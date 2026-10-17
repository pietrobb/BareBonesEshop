<?php
/**
 * One SKU-uniqueness rule for the product editor, the variant editor and the CSV/feed import: a SKU is the stock
 * ledger key of every store, so it may belong to exactly one product or one variant across all catalogs. A SKU that
 * orders, bundles, reservations or the warehouse ledger refer to is pinned and cannot be renamed.
 */
final class SkuGuard {
    /** True when a product (other than $exceptProductId) or a variant (other than $exceptVariantId) of any store uses the SKU. */
    public static function taken(string $sku, int $exceptProductId = 0, int $exceptVariantId = 0): bool {
        $db = Db::get();
        $product = $db->prepare('SELECT 1 FROM products WHERE sku = :sku AND id != :id');
        $product->execute([':sku' => $sku, ':id' => $exceptProductId ?: -1]);
        if ($product->fetchColumn()) return true;
        $variant = $db->prepare('SELECT 1 FROM variants WHERE sku = :sku AND id != :id');
        $variant->execute([':sku' => $sku, ':id' => $exceptVariantId ?: -1]);
        return (bool)$variant->fetchColumn();
    }

    /** Why the SKU of product $productId may not change (orders, bundles, reservations, warehouse ledger), or null. */
    public static function productSkuPinned(int $productId, string $sku): ?string {
        $checks = [
            'admin.msg.variant.in_orders' => ['SELECT 1 FROM order_items WHERE sku = :sku OR (product_id = :id AND variant_id IS NULL)', [':sku' => $sku, ':id' => $productId]],
            'admin.msg.variant.in_bundle' => ['SELECT 1 FROM bundle_components WHERE component_product_id = :id AND component_variant_id = 0', [':id' => $productId]],
            'admin.msg.variant.reserved' => ['SELECT 1 FROM warehouse_stock WHERE sku = :sku AND reserved > 0', [':sku' => $sku]],
            'admin.msg.variant.ledger_records' => ['SELECT 1 FROM warehouse_stock WHERE sku = :sku', [':sku' => $sku]],
        ];
        foreach ($checks as $reason => [$sql, $params]) {
            $stmt = Db::get()->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            if ($stmt->fetchColumn()) return I18n::t($reason);
        }
        return null;
    }
}
