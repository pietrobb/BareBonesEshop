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
            if ($qty <= 0) { unset($_SESSION['cart'][$key]); continue; }

            $total = $price * $qty;
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
     * Cart abandonment: save email at first checkout step
     */
    public static function setEmail(string $email): void {
        $_SESSION['cart_email'] = $email;
        $_SESSION['cart_email_at'] = time();
    }
}
