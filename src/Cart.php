<?php
/**
 * BareBones Shop — Cart
 * Session-based with localStorage restore endpoint.
 */
class Cart {
    public static function get(): array {
        return $_SESSION['cart'] ?? [];
    }

    public static function add(int $productId, ?int $variantId, int $qty): void {
        $key = $variantId ? "v_{$variantId}" : "p_{$productId}";
        if (!isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'qty'        => 0,
            ];
        }
        $_SESSION['cart'][$key]['qty'] += $qty;
    }

    public static function update(string $key, int $qty): void {
        if ($qty <= 0) {
            unset($_SESSION['cart'][$key]);
        } else {
            if (isset($_SESSION['cart'][$key])) {
                $_SESSION['cart'][$key]['qty'] = $qty;
            }
        }
    }

    /**
     * Enriched cart — real-time prices and stock from DB (batch queries)
     */
    public static function enriched(): array {
        $cart = self::get();
        if (empty($cart)) return ['items' => [], 'subtotal' => 0, 'count' => 0];

        $db = Db::get();

        // Collect IDs for batch queries
        $productIds = [];
        $variantIds = [];
        foreach ($cart as $item) {
            $productIds[] = $item['product_id'];
            if (!empty($item['variant_id'])) {
                $variantIds[] = $item['variant_id'];
            }
        }
        $productIds = array_unique($productIds);

        // Batch fetch products
        $products = [];
        if (!empty($productIds)) {
            $ph = implode(',', array_fill(0, count($productIds), '?'));
            $stmt = $db->prepare("SELECT id, name, slug, images, price, sku, stock, currency FROM products WHERE id IN ({$ph}) AND status = 'active'");
            $stmt->execute(array_values($productIds));
            while ($row = $stmt->fetch()) $products[(int)$row['id']] = $row;
        }

        // Batch fetch variants
        $variants = [];
        if (!empty($variantIds)) {
            $ph = implode(',', array_fill(0, count($variantIds), '?'));
            $stmt = $db->prepare("SELECT v.id, v.product_id, v.sku, v.name, v.stock, v.price_delta, v.price_absolute FROM variants v WHERE v.id IN ({$ph})");
            $stmt->execute(array_values($variantIds));
            while ($row = $stmt->fetch()) $variants[(int)$row['id']] = $row;
        }

        $items = [];
        $subtotal = 0;

        foreach ($cart as $key => $item) {
            $product = $products[$item['product_id']] ?? null;
            if (!$product) { unset($_SESSION['cart'][$key]); continue; }

            if (!empty($item['variant_id'])) {
                $variant = $variants[$item['variant_id']] ?? null;
                if (!$variant) { unset($_SESSION['cart'][$key]); continue; }
                $price = $variant['price_absolute'] !== null
                    ? (int)$variant['price_absolute']
                    : (int)$product['price'] + (int)$variant['price_delta'];
                $sku = $variant['sku'];
                $variantName = $variant['name'];
                $stock = (int)$variant['stock'];
            } else {
                $price = (int)$product['price'];
                $sku = $product['sku'];
                $variantName = '';
                $stock = (int)$product['stock'];
            }

            $qty = min($item['qty'], $stock);
            $available = $qty > 0;
            if (!$available) $qty = $item['qty']; // preserve requested qty for display

            $total = $available ? $price * $qty : 0;
            $subtotal += $total;
            $images = json_decode($product['images'] ?? '[]', true);
            $currency = $product['currency'] ?? 'eur';

            $items[] = [
                'key'          => $key,
                'product_id'   => $item['product_id'],
                'variant_id'   => $item['variant_id'],
                'sku'          => $sku,
                'name'         => $product['name'],
                'variant_name' => $variantName,
                'slug'         => $product['slug'],
                'image'        => $images[0] ?? null,
                'price'        => $price,
                'price_formatted' => I18n::price($price, $currency),
                'qty'          => $qty,
                'total'        => $total,
                'total_formatted' => I18n::price($total, $currency),
                'stock'        => $stock,
                'currency'     => $currency,
                'available'    => $available,
            ];
        }

        return [
            'items'    => $items,
            'subtotal' => $subtotal,
            'subtotal_formatted' => I18n::price($subtotal),
            'count'    => array_sum(array_column($items, 'qty')),
        ];
    }

    public static function clear(): void {
        unset($_SESSION['cart']);
    }

    /**
     * Restore cart from localStorage data (when session expired)
     */
    public static function restore(array $items): void {
        foreach ($items as $item) {
            $productId = Security::int($item['product_id'] ?? 0);
            $variantId = isset($item['variant_id']) && $item['variant_id']
                ? Security::int($item['variant_id']) : null;
            $qty = max(1, min(99, Security::int($item['qty'] ?? 1)));
            if ($productId > 0 && Products::byId($productId)) {
                self::add($productId, $variantId, $qty);
            }
        }
    }

    /**
     * Remove or reduce unavailable items from cart (after OOS checkout failure)
     */
    public static function removeUnavailable(array $outOfStockNames): void {
        $cart = self::get();
        foreach ($cart as $key => $item) {
            $product = Products::byId((int)($item['product_id'] ?? 0));
            if (!$product || $product['status'] !== 'active') {
                unset($_SESSION['cart'][$key]);
                continue;
            }
            $stock = (int)$product['stock'];
            if (!empty($item['variant_id'])) {
                $variants = Products::variants((int)$item['product_id']);
                foreach ($variants as $v) {
                    if ((int)$v['id'] === (int)$item['variant_id']) {
                        $stock = (int)$v['stock'];
                        break;
                    }
                }
            }
            if ($stock <= 0) {
                unset($_SESSION['cart'][$key]);
            } elseif ($item['qty'] > $stock) {
                $_SESSION['cart'][$key]['qty'] = $stock;
            }
        }
    }

    /**
     * Cart abandonment: save email and persist cart to DB for recovery
     */
    public static function setEmail(string $email): void {
        $_SESSION['cart_email'] = $email;
        $_SESSION['cart_email_at'] = time();

        // Persist to abandoned_carts table for background worker scanning
        $cart = self::get();
        if (!empty($cart) && $email) {
            try {
                $db = Db::get();
                $sid = session_id();
                // Atomic upsert — session_id has UNIQUE constraint
                if (Db::isSqlite()) {
                    $db->prepare("INSERT INTO abandoned_carts (session_id, customer_email, cart_data) VALUES (:sid, :email, :data)
                        ON CONFLICT(session_id) DO UPDATE SET customer_email = :email2, cart_data = :data2, reminded_at = NULL")
                       ->execute([':sid' => $sid, ':email' => $email, ':data' => json_encode($cart), ':email2' => $email, ':data2' => json_encode($cart)]);
                } else {
                    $db->prepare("INSERT INTO abandoned_carts (session_id, customer_email, cart_data) VALUES (:sid, :email, :data)
                        ON DUPLICATE KEY UPDATE customer_email = VALUES(customer_email), cart_data = VALUES(cart_data), reminded_at = NULL")
                       ->execute([':sid' => $sid, ':email' => $email, ':data' => json_encode($cart)]);
                }
            } catch (\Throwable $e) {
                // Silently fail — cart abandonment tracking is non-critical
                error_log("Cart::setEmail persist failed: " . $e->getMessage());
            }
        }
    }
}
