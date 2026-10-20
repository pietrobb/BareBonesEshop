<?php
/**
 * Bulk actions of the admin product list, bound to the active catalog. Every changed product is written to the audit
 * log, and a status or price change records product.updated in the same transaction. A product that orders refer to is never deleted (order_items keeps a foreign key to it): it is skipped and
 * reported, so the admin can archive it instead. A price change never takes a product below zero or below the
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
        $db->beginTransaction();
        try {
            foreach ($products as $product) {
                $id = (int)$product['id'];
                if ($action === 'delete') {
                    if (self::inOrders($id)) {
                        $result['skipped'][] = (string)($product['sku'] ?? $id);
                        continue;
                    }
                    $db->prepare('DELETE FROM products WHERE id = :id AND store_id = :catalog')->execute([':id' => $id, ':catalog' => StoreContext::current()->catalogId()]);
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
                        AuditLog::log('product', $id, ['price' => (int)$product['price']], ['price' => $price['price']], 'Admin bulk price ' . sprintf('%+d%%', self::PRICE_ACTIONS[$action]));
                        Products::recordUpdated($id, $product);
                    }
                }
                $result['count']++;
            }
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

    private static function inOrders(int $productId): bool {
        $stmt = Db::get()->prepare('SELECT 1 FROM order_items WHERE product_id = :id LIMIT 1');
        $stmt->execute([':id' => $productId]);
        return (bool)$stmt->fetchColumn();
    }
}
