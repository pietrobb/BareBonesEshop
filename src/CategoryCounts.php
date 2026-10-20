<?php
/**
 * Keeps categories.product_count (the storefront hides a category with 0 and orders its menus by it) current after a
 * product write: the given categories and every ancestor of them are recounted by Import::recountCategories().
 */
final class CategoryCounts {
    /** Primary and linked category ids of the given products (read before a write that moves or deletes them). */
    public static function ofProducts(array $productIds, ?PDO $db = null): array {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (!$productIds) return [];
        $db ??= Db::get();
        $in = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $db->prepare("SELECT category_id FROM products WHERE id IN ({$in}) AND category_id IS NOT NULL
            UNION SELECT category_id FROM product_categories WHERE product_id IN ({$in})");
        $stmt->execute([...$productIds, ...$productIds]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Recount the given categories and their ancestors. */
    public static function refresh(array $categoryIds, ?PDO $db = null): void {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        if (!$categoryIds) return;
        $db ??= Db::get();
        $in = implode(',', array_fill(0, count($categoryIds), '?'));
        $stmt = $db->prepare("SELECT path, store_id FROM categories WHERE id IN ({$in})");
        $stmt->execute($categoryIds);
        $paths = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $prefix = '';
            foreach (explode('/', (string)$row['path']) as $segment) {
                $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
                $paths[(string)$row['store_id']][$prefix] = true;
            }
        }
        $ids = $categoryIds;
        foreach ($paths as $store => $storePaths) {
            $list = array_keys($storePaths);
            $marks = implode(',', array_fill(0, count($list), '?'));
            $ancestors = $db->prepare("SELECT id FROM categories WHERE store_id = ? AND path IN ({$marks})");
            $ancestors->execute([$store, ...$list]);
            $ids = [...$ids, ...array_map('intval', $ancestors->fetchAll(PDO::FETCH_COLUMN))];
        }
        Import::recountCategories($db, array_values(array_unique($ids)));
    }
}
