<?php
/** Canonical server-side purchasability rules for product variants. */
class VariantAvailability {
    public const DIGITAL_TYPES = ['downloadable', 'digital'];
    public const ACTIVE = 'active';
    public const COMING_SOON = 'coming_soon';
    public const OUT_OF_STOCK = 'out_of_stock';
    public const DISABLED = 'disabled';
    public const DISCONTINUED = 'discontinued';

    public static function statuses(): array {
        return [self::ACTIVE, self::COMING_SOON, self::OUT_OF_STOCK, self::DISABLED, self::DISCONTINUED];
    }

    public static function isDigitalType(string $productType): bool {
        return in_array(strtolower($productType), self::DIGITAL_TYPES, true);
    }

    public static function isDigitalProduct(int $productId): bool {
        $stmt = Db::get()->prepare('SELECT product_type FROM products WHERE id = :id AND store_id = :store');
        $stmt->execute([':id' => $productId, ':store' => StoreContext::current()->catalogId()]);
        return self::isDigitalType((string)$stmt->fetchColumn());
    }

    public static function isPurchasable(int $productId, ?int $variantId = null): bool {
        $stmt = Db::get()->prepare("SELECT id, product_type, status, stock, availability, available_from, inventory_policy, preorder_at
            FROM products WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $productId, ':store' => StoreContext::current()->catalogId()]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product || $product['status'] !== 'active'
            || ($product['availability'] ?? self::ACTIVE) !== self::ACTIVE) return false;
        $policy = (string)($product['inventory_policy'] ?? Inventory::DENY);
        if (!empty($product['available_from']) && strtotime((string)$product['available_from']) > time()
            && $policy !== Inventory::PREORDER) return false;
        $digital = self::isDigitalType((string)$product['product_type']);

        if ($variantId !== null) {
            $stmt = Db::get()->prepare('SELECT product_id, stock, availability, available_from, inventory_policy, preorder_at FROM variants WHERE id = :id');
            $stmt->execute([':id' => $variantId]);
            $variant = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$variant || (int)$variant['product_id'] !== $productId) return false;
            $variantPolicy = ($variant['inventory_policy'] ?? 'inherit') === 'inherit'
                ? $policy
                : (string)$variant['inventory_policy'];
            if (($variant['availability'] ?? self::ACTIVE) !== self::ACTIVE
                || (!empty($variant['available_from']) && strtotime((string)$variant['available_from']) > time()
                    && $variantPolicy !== Inventory::PREORDER)) return false;
            return $digital || (int)$variant['stock'] > 0
                || in_array($variantPolicy, [Inventory::BACKORDER, Inventory::PREORDER], true);
        }

        return $digital || (int)$product['stock'] > 0
            || in_array($policy, [Inventory::BACKORDER, Inventory::PREORDER], true);
    }

    public static function setStatus(int $variantId, string $status): void {
        if (!in_array($status, self::statuses(), true)) throw new InvalidArgumentException('Invalid variant availability');
        Db::get()->prepare('UPDATE variants SET availability = :status WHERE id = :id')
            ->execute([':status' => $status, ':id' => $variantId]);
    }
}
