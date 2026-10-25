<?php
/**
 * R10 UX10: "Duplicate" in the product editor. The copy is a draft with its own SKU and slug, no stock, no GTIN
 * (a barcode names one product) and no translated slugs (slugs are unique). It keeps texts, prices, images,
 * categories, attributes, specifications, related products, bundle components, price-list prices and the
 * variants (under new SKUs, without stock).
 */
final class ProductDuplicator {
    /** Child rows copied as they are: table => column holding the product id. */
    private const CHILDREN = [
        'product_categories' => 'product_id', 'product_attrs' => 'product_id', 'product_specifications' => 'product_id',
        'product_related_products' => 'product_id', 'bundle_components' => 'bundle_product_id',
    ];

    /** The new product's id; the source must belong to the active catalog. */
    public static function duplicate(int $productId): int {
        $source = Products::byId($productId);
        if ($source === null) throw new RuntimeException('Product not found in the active catalog');
        $db = Db::get();
        $now = gmdate('Y-m-d H:i:s');
        $translations = json_decode((string)($source['translations'] ?? ''), true);
        if (is_array($translations)) {
            foreach ($translations as $locale => $fields) if (is_array($fields)) unset($translations[$locale]['slug']);
        }
        $copy = array_merge($source, [
            'sku' => self::freeSku((string)$source['sku']),
            'slug' => self::freeSlug((string)$source['slug']),
            'name' => I18n::format('admin.product.copy_name', ['name' => (string)$source['name']]),
            'status' => 'draft',
            'stock' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (array_key_exists('gtin', $copy)) $copy['gtin'] = null;
        if (array_key_exists('translations', $copy)) $copy['translations'] = is_array($translations) ? json_encode($translations, JSON_UNESCAPED_UNICODE) : $copy['translations'];
        unset($copy['id']);

        $db->beginTransaction();
        try {
            self::insert($db, 'products', $copy);
            $newId = (int)$db->lastInsertId();
            foreach (self::CHILDREN as $table => $column) {
                if (!Db::tableExists($table)) continue;
                $rows = $db->prepare("SELECT * FROM {$table} WHERE {$column} = ?");
                $rows->execute([$productId]);
                foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    unset($row['id']);
                    self::insert($db, $table, [$column => $newId] + $row);
                }
            }
            $variantIds = self::copyVariants($db, $productId, $newId);
            if (Db::tableExists('price_list_prices')) {
                $prices = $db->prepare('SELECT * FROM price_list_prices WHERE product_id = ?');
                $prices->execute([$productId]);
                foreach ($prices->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    unset($row['id']);
                    $variant = $row['variant_id'] === null ? null : ($variantIds[(int)$row['variant_id']] ?? false);
                    if ($variant === false) continue;
                    self::insert($db, 'price_list_prices', ['product_id' => $newId, 'variant_id' => $variant, 'created_at' => $now] + $row);
                }
            }
            PriceHistory::record([$newId], $db);
            CategoryCounts::refresh(CategoryCounts::ofProducts([$newId], $db), $db);
            AuditLog::log('product', $newId, null, ['duplicated_from' => $productId, 'sku' => $copy['sku']], 'Admin duplicate');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return $newId;
    }

    /** @return array<int,int> old variant id => new variant id */
    private static function copyVariants(PDO $db, int $productId, int $newId): array {
        $map = [];
        $variants = $db->prepare('SELECT * FROM variants WHERE product_id = ? ORDER BY id');
        $variants->execute([$productId]);
        foreach ($variants->fetchAll(PDO::FETCH_ASSOC) as $variant) {
            $oldId = (int)$variant['id'];
            unset($variant['id']);
            self::insert($db, 'variants', ['product_id' => $newId, 'sku' => self::freeSku((string)$variant['sku']), 'stock' => 0] + $variant);
            $map[$oldId] = (int)$db->lastInsertId();
        }
        return $map;
    }

    /** First of SKU-COPY, SKU-COPY-2, … that no product or variant of any store uses (the SKU rule of the editor). */
    private static function freeSku(string $sku): string {
        $base = mb_substr($sku, 0, 80) . '-COPY';
        for ($n = 1; ; $n++) {
            $candidate = $n === 1 ? $base : $base . '-' . $n;
            if (!SkuGuard::taken($candidate)) return $candidate;
        }
    }

    /** First of slug-copy, slug-copy-2, … that is neither a product slug nor a translated slug. */
    private static function freeSlug(string $slug): string {
        $db = Db::get();
        $product = $db->prepare('SELECT 1 FROM products WHERE slug = ?');
        $registered = $db->prepare("SELECT 1 FROM slug_registry WHERE slug = ? AND entity = 'product'");
        $base = mb_substr($slug, 0, 180) . '-copy';
        for ($n = 1; ; $n++) {
            $candidate = $n === 1 ? $base : $base . '-' . $n;
            $product->execute([$candidate]);
            $registered->execute([$candidate]);
            if ($product->fetchColumn() === false && $registered->fetchColumn() === false) return $candidate;
        }
    }

    /** @param array<string,mixed> $row column names come from the table itself (SELECT *), never from input */
    private static function insert(PDO $db, string $table, array $row): void {
        $columns = array_keys($row);
        $db->prepare("INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($row));
    }
}
