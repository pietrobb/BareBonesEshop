<?php
/**
 * Bulk actions of the admin product list, bound to the active catalog. Every changed product is written to the audit
 * log, and a status or price change records product.updated in the same transaction. A product that orders refer to is never deleted (order_items keeps a foreign key to it): it is skipped and
 * reported, so the admin can archive it instead (so is one with reserved units); a deleted product's warehouse stock
 * is booked out in the ledger and removed. A price change never takes a product below zero or below the
 * price at which a variant with a negative delta would turn negative.
 */
final class ProductBulk {
    /** Price actions → percent change. */
    public const PRICE_ACTIONS = ['price_up_5' => 5, 'price_up_10' => 10, 'price_up_20' => 20, 'price_down_5' => -5, 'price_down_10' => -10, 'price_down_20' => -20];
    public const STATUS_ACTIONS = ['activate' => 'active', 'archive' => 'archived'];

    /**
     * Apply $action to the products $ids of the active catalog (ids of other catalogs are ignored).
     * Returns ['ok' => bool, 'action' => string, 'count' => changed products, 'skipped' => SKUs left unchanged, 'clamped' => SKUs held at their price floor].
     */
    public static function apply(string $action, array $ids): array {
        $result = ['ok' => false, 'action' => $action, 'count' => 0, 'skipped' => [], 'clamped' => []];
        if (!isset(self::PRICE_ACTIONS[$action]) && !isset(self::STATUS_ACTIONS[$action]) && $action !== 'delete') return $result;
        $products = self::owned($ids);
        $db = Db::get();
        // A status change or delete moves the product in or out of its categories' counts.
        $categories = isset(self::PRICE_ACTIONS[$action]) ? [] : CategoryCounts::ofProducts(array_column($products, 'id'), $db);
        $db->beginTransaction();
        try {
            foreach ($products as $product) {
                $id = (int)$product['id'];
                if ($action === 'delete') {
                    $skus = self::skus($product);
                    if (self::inOrders($id) || self::reserved($skus)) {
                        $result['skipped'][] = (string)($product['sku'] ?? $id);
                        continue;
                    }
                    self::writeOffStock($id, $skus);
                    $db->prepare('DELETE FROM products WHERE id = :id AND store_id = :catalog')->execute([':id' => $id, ':catalog' => StoreContext::current()->catalogId()]);
                    // Its localized and previous slugs go too: left behind they blocked the slug for a new product
                    // and pointed at whichever product later reused the id.
                    $db->prepare("DELETE FROM slug_registry WHERE entity = 'product' AND entity_id = :id")->execute([':id' => $id]);
                    AuditLog::log('product', $id, $product, null, 'Admin bulk delete');
                } elseif (isset(self::STATUS_ACTIONS[$action])) {
                    $status = self::STATUS_ACTIONS[$action];
                    if ($product['status'] === $status) continue;
                    $db->prepare('UPDATE products SET status = :status, updated_at = ' . Db::now() . ' WHERE id = :id')->execute([':status' => $status, ':id' => $id]);
                    AuditLog::log('product', $id, ['status' => $product['status']], ['status' => $status], 'Admin bulk ' . $action);
                    Products::recordUpdated($id, $product);
                } else {
                    $price = self::changedPrice((int)$product['price'], self::PRICE_ACTIONS[$action], ProductVariants::minBasePrice($id));
                    if ($price['clamped']) $result['clamped'][] = (string)($product['sku'] ?? $id);
                    $db->prepare('UPDATE products SET price = :price, updated_at = ' . Db::now() . ' WHERE id = :id')->execute([':price' => $price['price'], ':id' => $id]);
                    if ($price['price'] !== (int)$product['price']) {
                        PriceHistory::record([$id], $db);
                        AuditLog::log('product', $id, ['price' => (int)$product['price']], ['price' => $price['price']], 'Admin bulk price ' . sprintf('%+d%%', self::PRICE_ACTIONS[$action]));
                        Products::recordUpdated($id, $product);
                    }
                }
                $result['count']++;
            }
            if ($result['count'] > 0) CategoryCounts::refresh($categories, $db);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        if ($result['count'] > 0) Cache::catalogChanged();
        $result['ok'] = true;
        return $result;
    }

    /** New price after a percent change, held at the floor (zero, or what keeps every variant priced ≥ 0). */
    public static function changedPrice(int $price, int $percent, int $floor = 0): array {
        $changed = (int)round($price * (100 + $percent) / 100);
        $floor = max(0, $floor);
        return ['price' => max($floor, $changed), 'clamped' => $changed < $floor];
    }

    /** @return list<array<string, mixed>> the products of the active catalog among $ids */
    private static function owned(array $ids): array {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if (!$ids) return [];
        $stmt = Db::get()->prepare('SELECT * FROM products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND store_id = ? ORDER BY id');
        $stmt->execute([...$ids, StoreContext::current()->catalogId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<string> the SKUs of the product and its variants (the warehouse stock is kept per SKU) */
    private static function skus(array $product): array {
        $stmt = Db::get()->prepare('SELECT sku FROM variants WHERE product_id = :id');
        $stmt->execute([':id' => (int)$product['id']]);
        return array_values(array_unique(array_filter([(string)$product['sku'], ...array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))], static fn(string $sku): bool => $sku !== '')));
    }

    /** @return list<array{warehouse_id: int|string, sku: string, qty: int|string, reserved: int|string}> */
    private static function stockRows(array $skus): array {
        if (!$skus) return [];
        $stmt = Db::get()->prepare('SELECT warehouse_id, sku, qty, reserved FROM warehouse_stock WHERE store_id = ? AND sku IN ('
            . implode(',', array_fill(0, count($skus), '?')) . ')' . (Db::isMysql() ? ' FOR UPDATE' : ''));
        $stmt->execute([StoreContext::current()->catalogId(), ...$skus]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function reserved(array $skus): bool {
        foreach (self::stockRows($skus) as $row) if ((int)$row['reserved'] > 0) return true;
        return false;
    }

    /**
     * A deleted product's stock rows stayed behind, keyed by SKU: a product created later with that SKU (or a variant
     * SKU) inherited them and was sold from stock nobody had. The units are booked out in the ledger, then the rows go.
     */
    private static function writeOffStock(int $productId, array $skus): void {
        foreach (self::stockRows($skus) as $row) {
            if ((int)$row['qty'] > 0) Warehouse::setStock((int)$row['warehouse_id'], (string)$row['sku'], 0, 'product_deleted', 'product', $productId);
            Db::get()->prepare('DELETE FROM warehouse_stock WHERE warehouse_id = :wh AND sku = :sku AND reserved = 0')
                ->execute([':wh' => (int)$row['warehouse_id'], ':sku' => (string)$row['sku']]);
        }
    }

    private static function inOrders(int $productId): bool {
        $stmt = Db::get()->prepare('SELECT 1 FROM order_items WHERE product_id = :id LIMIT 1');
        $stmt->execute([':id' => $productId]);
        return (bool)$stmt->fetchColumn();
    }
}
