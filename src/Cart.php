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
     * Enriched cart — real-time prices and stock from DB
     */
    public static function enriched(): array {
        $cart = self::get();
        if (empty($cart)) return ['items' => [], 'subtotal' => 0, 'count' => 0];

        $db = Db::get();
        $items = [];
        $subtotal = 0;

        foreach ($cart as $key => $item) {
            if (!empty($item['variant_id'])) {
                $stmt = $db->prepare("
                    SELECT p.name, p.slug, p.images,
                           COALESCE(v.price_absolute, p.price + v.price_delta) as price,
                           v.sku, v.name as variant_name, v.stock, p.currency
                    FROM variants v
                    JOIN products p ON p.id = v.product_id
                    WHERE v.id = :vid AND p.status = 'active'
                ");
                $stmt->execute([':vid' => $item['variant_id']]);
                $row = $stmt->fetch();
            } else {
                $stmt = $db->prepare("
                    SELECT name, slug, images, price, sku, '' as variant_name, stock, currency
                    FROM products
                    WHERE id = :pid AND status = 'active'
                ");
                $stmt->execute([':pid' => $item['product_id']]);
                $row = $stmt->fetch();
            }

            if (!$row) { unset($_SESSION['cart'][$key]); continue; }

            $qty = min($item['qty'], $row['stock']); // cap at stock
            if ($qty <= 0) { unset($_SESSION['cart'][$key]); continue; }

            $total = $row['price'] * $qty;
            $subtotal += $total;
            $images = json_decode($row['images'] ?? '[]', true);

            $items[] = [
                'key'          => $key,
                'product_id'   => $item['product_id'],
                'variant_id'   => $item['variant_id'],
                'sku'          => $row['sku'],
                'name'         => $row['name'],
                'variant_name' => $row['variant_name'],
                'slug'         => $row['slug'],
                'image'        => $images[0] ?? null,
                'price'        => (int)$row['price'],
                'price_formatted' => I18n::price((int)$row['price'], $row['currency'] ?? 'eur'),
                'qty'          => $qty,
                'total'        => $total,
                'total_formatted' => I18n::price($total, $row['currency'] ?? 'eur'),
                'stock'        => (int)$row['stock'],
                'currency'     => $row['currency'] ?? 'eur',
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
            $qty = max(1, Security::int($item['qty'] ?? 1));
            if ($productId > 0) {
                self::add($productId, $variantId, $qty);
            }
        }
    }

    /**
     * Remove unavailable items from cart
     */
    public static function removeUnavailable(array $unavailableNames): void {
        // Re-enrich will automatically remove items with 0 stock
        self::enriched();
    }

    /**
     * Cart abandonment: save email at first checkout step
     */
    public static function setEmail(string $email): void {
        $_SESSION['cart_email'] = $email;
        $_SESSION['cart_email_at'] = time();
    }
}
