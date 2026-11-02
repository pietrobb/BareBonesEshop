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

    /** Whether the variant is digital content of an otherwise physical product (an e-book next to its paperback). */
    public static function isDigitalVariant(?int $variantId): bool {
        if (!$variantId) return false;
        $stmt = Db::get()->prepare('SELECT v.digital FROM variants v JOIN products p ON p.id = v.product_id AND p.store_id = :store WHERE v.id = :id');
        $stmt->execute([':id' => $variantId, ':store' => StoreContext::current()->catalogId()]);
        return (int)$stmt->fetchColumn() === 1;
    }

    /** Whether an order or cart line (product_id, variant_id) is digital: a digital product or a digital variant. */
    public static function isDigitalItem(array $item): bool {
        return self::isDigitalProduct((int)($item['product_id'] ?? 0))
            || self::isDigitalVariant(isset($item['variant_id']) ? (int)$item['variant_id'] : null);
    }

    private const PRODUCT_COLUMNS = 'id, product_type, status, stock, availability, available_from, inventory_policy, preorder_at';

    public static function isPurchasable(int $productId, ?int $variantId = null): bool {
        $stmt = Db::get()->prepare('SELECT ' . self::PRODUCT_COLUMNS . ' FROM products WHERE id = :id AND store_id = :store');
        $stmt->execute([':id' => $productId, ':store' => StoreContext::current()->catalogId()]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($variantId === null) return $product ? self::productPurchasable($product) : false;
        if (!$product || !self::productOpen($product)) return false;
        $stmt = Db::get()->prepare('SELECT product_id, stock, availability, available_from, inventory_policy, preorder_at, digital FROM variants WHERE id = :id');
        $stmt->execute([':id' => $variantId]);
        $variant = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$variant || (int)$variant['product_id'] !== $productId) return false;
        return self::variantPurchasable($product, $variant);
    }

    /** A variant of an open product: released and active, and a download, in stock, or sold ahead of stock by its policy. */
    private static function variantPurchasable(array $product, array $variant): bool {
        $policy = (string)($product['inventory_policy'] ?? Inventory::DENY);
        $digital = self::isDigitalType((string)$product['product_type']) || (int)($variant['digital'] ?? 0) === 1;
        $variantPolicy = ($variant['inventory_policy'] ?? 'inherit') === 'inherit'
            ? $policy
            : (string)$variant['inventory_policy'];
        if (($variant['availability'] ?? self::ACTIVE) !== self::ACTIVE
            || (!empty($variant['available_from']) && strtotime((string)$variant['available_from']) > time()
                && $variantPolicy !== Inventory::PREORDER)) return false;
        return $digital || (int)$variant['stock'] > 0
            || in_array($variantPolicy, [Inventory::BACKORDER, Inventory::PREORDER], true);
    }

    /**
     * For the products of a listing that have variants (their stock is held by the variants, not the product row):
     * whether any variant can be bought, whether one is in stock (a download always is) and the units in stock.
     * Products without variants are not in the result.
     * @param list<int> $productIds
     * @return array<int, array{purchasable: bool, in_stock: bool, quantity: int}>
     */
    public static function variantStatesMany(array $productIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
        $result = [];
        $columns = implode(', ', array_map(static fn(string $c): string => 'p.' . trim($c), explode(',', self::PRODUCT_COLUMNS)));
        foreach (array_chunk($ids, 500) as $chunk) {
            $params = [':store' => StoreContext::current()->catalogId()];
            foreach ($chunk as $index => $id) $params[':id' . $index] = $id;
            $stmt = Db::get()->prepare("SELECT {$columns}, v.stock AS v_stock, v.availability AS v_availability, v.available_from AS v_available_from,
                    v.inventory_policy AS v_inventory_policy, v.digital AS v_digital
                FROM variants v JOIN products p ON p.id = v.product_id
                WHERE p.store_id = :store AND p.id IN (" . implode(', ', array_slice(array_keys($params), 1)) . ')');
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int)$row['id'];
                $variant = ['stock' => $row['v_stock'], 'availability' => $row['v_availability'], 'available_from' => $row['v_available_from'],
                    'inventory_policy' => $row['v_inventory_policy'], 'digital' => $row['v_digital']];
                $state = $result[$id] ?? ['purchasable' => false, 'in_stock' => false, 'quantity' => 0];
                if (self::productOpen($row) && self::variantPurchasable($row, $variant)) {
                    $state['purchasable'] = true;
                    $digital = self::isDigitalType((string)$row['product_type']) || (int)$row['v_digital'] === 1;
                    if ($digital || (int)$row['v_stock'] > 0) $state['in_stock'] = true;
                }
                if ((int)$row['v_digital'] !== 1) $state['quantity'] += max(0, (int)$row['v_stock']);
                $result[$id] = $state;
            }
        }
        return $result;
    }

    /**
     * Product-level purchasability for a whole listing in one query (same rules as isPurchasable
     * without a variant). Unknown or foreign-catalog ids map to false.
     * @param list<int> $productIds
     * @return array<int, bool>
     */
    public static function purchasableMany(array $productIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
        $result = array_fill_keys($ids, false);
        foreach (array_chunk($ids, 500) as $chunk) {
            $params = [':store' => StoreContext::current()->catalogId()];
            foreach ($chunk as $index => $id) $params[':id' . $index] = $id;
            $stmt = Db::get()->prepare('SELECT ' . self::PRODUCT_COLUMNS . ' FROM products WHERE store_id = :store AND id IN ('
                . implode(', ', array_slice(array_keys($params), 1)) . ')');
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) $result[(int)$product['id']] = self::productPurchasable($product);
        }
        return $result;
    }

    /** Active, available and released (or pre-orderable) product row. */
    private static function productOpen(array $product): bool {
        if ($product['status'] !== 'active' || ($product['availability'] ?? self::ACTIVE) !== self::ACTIVE) return false;
        $policy = (string)($product['inventory_policy'] ?? Inventory::DENY);
        return empty($product['available_from']) || strtotime((string)$product['available_from']) <= time()
            || $policy === Inventory::PREORDER;
    }

    /** @param array $product row with the PRODUCT_COLUMNS */
    public static function productPurchasable(array $product): bool {
        if (!self::productOpen($product)) return false;
        $policy = (string)($product['inventory_policy'] ?? Inventory::DENY);
        return self::isDigitalType((string)$product['product_type']) || (int)$product['stock'] > 0
            || in_array($policy, [Inventory::BACKORDER, Inventory::PREORDER], true);
    }

    public static function setStatus(int $variantId, string $status): void {
        if (!in_array($status, self::statuses(), true)) throw new InvalidArgumentException('Invalid variant availability');
        Db::get()->prepare('UPDATE variants SET availability = :status WHERE id = :id')
            ->execute([':status' => $status, ':id' => $variantId]);
    }
}
