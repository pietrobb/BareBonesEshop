<?php
/**
 * Admin catalog editor reads/writes bound to the active store's catalog_scope:
 * categories, digital assets (owned through their product SKU) and category links of the shared attribute vocabulary.
 */
final class CatalogScope {
    /** Rows of a query whose only parameter is :catalog (the active catalog scope). */
    public static function rows(string $sql, array $params = []): array {
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($params + [':catalog' => StoreContext::current()->catalogId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function categories(): array {
        return self::rows('SELECT id, name, path FROM categories WHERE store_id = :catalog ORDER BY path, name');
    }

    public static function activeProductIds(): array {
        return array_map('intval', array_column(self::rows("SELECT id FROM products WHERE store_id = :catalog AND status = 'active'"), 'id'));
    }

    public static function ownsSku(string $sku): bool {
        return (bool)self::rows('SELECT 1 AS owned FROM products WHERE sku = :sku AND store_id = :catalog', [':sku' => $sku]);
    }

    public static function digitalAssets(): array {
        return self::rows('SELECT da.*, (SELECT COUNT(*) FROM digital_entitlements de WHERE de.asset_id = da.id) AS entitlement_count
            FROM digital_assets da WHERE EXISTS (SELECT 1 FROM products p WHERE p.sku = da.sku AND p.store_id = :catalog)
            ORDER BY da.sku, da.asset_key');
    }

    public static function setDigitalAssetActive(int $id, int $active): void {
        Db::get()->prepare('UPDATE digital_assets SET active = :active, updated_at = ' . Db::now() . ' WHERE id = :id
            AND EXISTS (SELECT 1 FROM products p WHERE p.sku = digital_assets.sku AND p.store_id = :catalog)')
            ->execute([':active' => $active, ':id' => $id, ':catalog' => StoreContext::current()->catalogId()]);
    }

    /** Attribute definitions with the names of the active catalog's categories they are linked to. */
    public static function attributesWithCategories(): array {
        return self::rows("SELECT ad.*, GROUP_CONCAT(c.name, ', ') as category_names
            FROM attribute_definitions ad
            LEFT JOIN category_attributes ca ON ca.attribute_id = ad.id
            LEFT JOIN categories c ON c.id = ca.category_id AND c.store_id = :catalog
            GROUP BY ad.id ORDER BY ad.sort_order, ad.name");
    }

    public static function attributeCategoryIds(int $attributeId): array {
        return array_column(self::rows('SELECT ca.category_id FROM category_attributes ca JOIN categories c ON c.id = ca.category_id
            WHERE ca.attribute_id = :aid AND c.store_id = :catalog', [':aid' => $attributeId]), 'category_id');
    }

    /** Replace the attribute's links to active-catalog categories; other catalogs keep their links. */
    public static function replaceAttributeCategories(int $attributeId, mixed $submitted): void {
        $db = Db::get();
        $owned = array_map('intval', array_column(self::categories(), 'id'));
        $selected = array_intersect(array_unique(array_map('intval', is_array($submitted) ? $submitted : [])), $owned);
        $db->prepare('DELETE FROM category_attributes WHERE attribute_id = :aid AND category_id IN (SELECT id FROM categories WHERE store_id = :catalog)')
            ->execute([':aid' => $attributeId, ':catalog' => StoreContext::current()->catalogId()]);
        $insert = $db->prepare('INSERT INTO category_attributes (category_id, attribute_id) VALUES (:cid, :aid)');
        foreach ($selected as $categoryId) $insert->execute([':cid' => $categoryId, ':aid' => $attributeId]);
    }

    public static function attributeUsedByOtherCatalog(int $attributeId): bool {
        return (bool)self::rows('SELECT 1 AS used FROM category_attributes ca JOIN categories c ON c.id = ca.category_id
            WHERE ca.attribute_id = :aid AND c.store_id != :catalog', [':aid' => $attributeId]);
    }
}
