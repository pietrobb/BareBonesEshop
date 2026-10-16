<?php
/**
 * BareBones Shop — Product Import
 * CSV/JSON import with UPSERT, auto-category creation, filter_tags generation.
 */
class Import {
    public const MAX_FILE_BYTES = 50 * 1024 * 1024;
    public const MAX_ROW_BYTES = 1024 * 1024;
    public const MAX_COLUMNS = 256;
    public const MAX_ROWS = 100000;

    /**
     * Import products from CSV file
     * CSV format: sku,name,category,price,stock,summary,image,attr:color,attr:size,...
     * Translations: name_en, name_de, summary_en, slug_en, etc.
     */
    public static function csv(string $filePath, ?string $productTypeOverride = null): array {
        if (!is_file($filePath) || is_link($filePath)) {
            return ['success' => false, 'imported' => 0, 'errors' => ["File not found or unsafe: {$filePath}"]];
        }
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize <= 0 || $fileSize > self::MAX_FILE_BYTES) {
            return ['success' => false, 'imported' => 0, 'errors' => ['CSV size must be between 1 byte and 50 MB']];
        }

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return ['success' => false, 'imported' => 0, 'errors' => ["Cannot open file: {$filePath}"]];
        }
        $openedStat = fstat($handle);
        if ($openedStat === false || ($openedStat['mode'] & 0170000) !== 0100000
            || $openedStat['size'] <= 0 || $openedStat['size'] > self::MAX_FILE_BYTES) {
            fclose($handle);
            return ['success' => false, 'imported' => 0, 'errors' => ['CSV file changed or is not a safe regular file']];
        }

        // Strip UTF-8 BOM if present (Excel exports)
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        // Read header
        $header = fgetcsv($handle, self::MAX_ROW_BYTES, ',', '"', '');
        if (!$header) {
            fclose($handle);
            return ['success' => false, 'imported' => 0, 'errors' => ['Empty CSV or invalid format']];
        }
        $header = array_map('trim', $header);
        $headerNamesValid = !array_filter($header, static fn($name) => strlen($name) > 128);
        if (count($header) > self::MAX_COLUMNS || in_array('', $header, true)
            || count(array_unique($header)) !== count($header) || !$headerNamesValid
            || !in_array('sku', $header, true) || !in_array('name', $header, true)) {
            fclose($handle);
            return ['success' => false, 'imported' => 0, 'errors' => ['CSV header is invalid, duplicated, missing sku/name, or exceeds limits']];
        }

        $db = Db::get();
        $imported = 0;
        $errors = [];
        $batchSize = 500;
        $batch = 0;
        $limitExceeded = false;

        $db->beginTransaction();
        $lineNum = 1; // header = line 1

        while (($row = fgetcsv($handle, self::MAX_ROW_BYTES, ',', '"', '')) !== false) {
            $lineNum++;
            if ($lineNum > self::MAX_ROWS + 1) {
                $errors[] = 'CSV exceeds the 100000 row limit; earlier committed batches remain imported';
                $limitExceeded = true;
                break;
            }

            if (count($row) !== count($header)) {
                $errors[] = "Line {$lineNum}: column count mismatch";
                continue;
            }

            $data = array_combine($header, array_map([self::class, 'csvCellDecode'], $row));

            try {
                $db->exec('SAVEPOINT row_import');
                if ($productTypeOverride) $data['product_type'] = $productTypeOverride;
                self::importProduct($data, $db);
                $db->exec('RELEASE SAVEPOINT row_import');
                $imported++;
                $batch++;

                // Commit in batches for performance
                if ($batch >= $batchSize) {
                    $db->commit();
                    $db->beginTransaction();
                    $batch = 0;
                }
            } catch (\Throwable $e) {
                $db->exec('ROLLBACK TO SAVEPOINT row_import');
                $errors[] = "Line {$lineNum} (SKU: " . ($data['sku'] ?? '?') . "): " . $e->getMessage();
            }
        }

        if ($db->inTransaction()) $db->commit();
        fclose($handle);

        // Recount category products
        self::recountCategories($db);

        return [
            'success'  => !$limitExceeded,
            'imported' => $imported,
            'errors'   => $errors,
        ];
    }

    /**
     * Import a single product from an associative array (CSV row or mapped feed item).
     * A new SKU is created with defaults for absent columns. An existing SKU changes only the
     * columns present in $data, so an edited "Export CSV" file (a subset of the columns) never
     * wipes the description, images, tags, category or attributes it does not carry.
     */
    public static function importProduct(array $data, ?PDO $db = null): void {
        $db = $db ?? Db::get();

        // Required fields
        $sku  = trim($data['sku'] ?? '');
        $name = trim($data['name'] ?? '');
        if (!$sku || !$name) {
            throw new \InvalidArgumentException("Missing required field: sku or name");
        }

        $catalog = self::assertSkuInCatalog($sku, $db);
        $stmt = $db->prepare("SELECT * FROM products WHERE sku = :sku AND store_id = :catalog");
        $stmt->execute([':sku' => $sku, ':catalog' => $catalog]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($existing) {
            $productId = (int)$existing['id'];
            $productType = self::updateProduct($existing, $data, $db);
        } else {
            $productType = trim($data['product_type'] ?? 'simple');
            $productId = self::createProduct($data, $productType, $db);
        }

        // Optional per-product stock alert thresholds; an empty cell resets to the config default.
        foreach (['stock_low_threshold', 'stock_critical_threshold'] as $column) {
            if (!array_key_exists($column, $data)) continue;
            $value = trim((string)$data[$column]);
            $value = $value === '' ? null : max(0, (int)$value);
            if ($existing && ($existing[$column] === null ? null : (int)$existing[$column]) === $value) continue;
            $db->prepare("UPDATE products SET {$column} = :value WHERE id = :id")
                ->execute([':value' => $value, ':id' => $productId]);
        }
        // The stock column is "available"; with warehouses a change is booked in the ledger (source of truth).
        if (array_key_exists('stock', $data) && trim((string)$data['stock']) !== ''
            && (!$existing || (int)$existing['stock'] !== (int)$data['stock'])) {
            Products::setAvailableStock($sku, $productType, (int)$data['stock'], 'import', 'csv_import');
        }

        // Base columns are SK by CSV import convention; per-locale slugs come from the translations.
        if (!$existing || array_key_exists('slug', $data)) {
            $stmt = $db->prepare("SELECT slug FROM products WHERE id = :id");
            $stmt->execute([':id' => $productId]);
            self::registerSlug((string)$stmt->fetchColumn(), 'sk', 'product', $productId, $db);
        }
        foreach (self::gatherTranslations($data) as $locale => $tr) {
            if (!empty($tr['slug'])) {
                self::registerSlug($tr['slug'], $locale, 'product', $productId, $db);
            }
        }
    }

    /**
     * SKUs are unique across all catalogs, so a SKU owned by another catalog must never be
     * created or upserted here: that would overwrite the other store's product. Returns the current catalog.
     */
    private static function assertSkuInCatalog(string $sku, PDO $db): string {
        $catalog = StoreContext::current()->catalogId();
        $stmt = $db->prepare("SELECT store_id FROM products WHERE sku = :sku");
        $stmt->execute([':sku' => $sku]);
        $owner = $stmt->fetchColumn();
        if ($owner !== false && (string)$owner !== $catalog) {
            throw new \RuntimeException("SKU '{$sku}' belongs to another catalog");
        }
        return $catalog;
    }

    private static function createProduct(array $data, string $productType, PDO $db): int {
        $sku = trim($data['sku']);
        $name = trim($data['name']);
        $attrs = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'attr:') && $value !== '') $attrs[substr($key, 5)] = $value;
        }
        self::upsertProduct([
            'sku'           => $sku,
            'slug'          => trim($data['slug'] ?? '') ?: self::slugify($name),
            'product_type'  => $productType,
            'name'          => $name,
            'category_id'   => self::ensureCategory(trim($data['category'] ?? 'uncategorized'), $db),
            'price'         => self::parsePriceToCents($data['price'] ?? 0),
            'compare_price' => !empty($data['compare_price']) ? self::parsePriceToCents($data['compare_price']) : null,
            'stock'         => (int)($data['stock'] ?? 0),
            'status'        => trim($data['status'] ?? 'active'),
            'summary'       => trim($data['summary'] ?? ''),
            'description'   => trim($data['description'] ?? ''),
            'images'        => self::pipeList($data['image'] ?? ''),
            'attrs'         => $attrs,
            'tags'          => self::pipeList($data['tags'] ?? ''),
            'filter_tags'   => self::buildFilterTagsFromAttrs($attrs),
            'weight_g'      => (int)($data['weight_g'] ?? 0),
            'translations'  => self::gatherTranslations($data),
        ], $db);

        $stmt = $db->prepare("SELECT id FROM products WHERE sku = :sku AND store_id = :catalog");
        $stmt->execute([':sku' => $sku, ':catalog' => StoreContext::current()->catalogId()]);
        $productId = (int)$stmt->fetchColumn();
        self::syncProductAttrs($productId, $attrs, $db);
        return $productId;
    }

    /** Update only the columns present in $data that differ from the stored row; returns the product type. */
    private static function updateProduct(array $existing, array $data, PDO $db): string {
        $has = static fn(string $key): bool => array_key_exists($key, $data);
        $json = static fn($stored): array => is_array($d = json_decode((string)$stored, true)) ? $d : [];
        $name = trim($data['name']);
        $set = ['name' => $name];
        if ($has('slug')) $set['slug'] = trim((string)$data['slug']) ?: self::slugify($name);
        if ($has('product_type')) $set['product_type'] = trim((string)$data['product_type']) ?: 'simple';
        if ($has('category')) $set['category_id'] = self::ensureCategory(trim((string)$data['category']) ?: 'uncategorized', $db);
        if ($has('price')) $set['price'] = self::parsePriceToCents($data['price']);
        if ($has('compare_price')) $set['compare_price'] = !empty($data['compare_price']) ? self::parsePriceToCents($data['compare_price']) : null;
        if ($has('stock') && trim((string)$data['stock']) !== '') $set['stock'] = (int)$data['stock'];
        if ($has('status')) $set['status'] = trim((string)$data['status']) ?: 'active';
        if ($has('summary')) $set['summary'] = trim((string)$data['summary']);
        if ($has('description')) $set['description'] = Security::sanitizeRichHtml(trim((string)$data['description']));
        if ($has('weight_g') && trim((string)$data['weight_g']) !== '') $set['weight_g'] = (int)$data['weight_g'];

        $lists = [];
        if ($has('image')) $lists['images'] = self::pipeList($data['image']);
        if ($has('tags')) $lists['tags'] = self::pipeList($data['tags']);

        // attr:* and name_xx/summary_xx/... columns: a value sets the entry, an empty cell removes it.
        $attrs = $json($existing['attrs'] ?? null);
        $attrsGiven = false;
        $translations = $json($existing['translations'] ?? null);
        $translationsGiven = false;
        foreach ($data as $key => $value) {
            $value = trim((string)$value);
            if (str_starts_with($key, 'attr:')) {
                $attrsGiven = true;
                if ($value === '') unset($attrs[substr($key, 5)]);
                else $attrs[substr($key, 5)] = $value;
            } elseif (preg_match('/^(name|summary|description|slug)_([a-z]{2})$/', $key, $m)) {
                $translationsGiven = true;
                if ($value === '') unset($translations[$m[2]][$m[1]]);
                else $translations[$m[2]][$m[1]] = $m[1] === 'description' ? Security::sanitizeRichHtml($value) : $value;
                if (empty($translations[$m[2]])) unset($translations[$m[2]]);
            }
        }
        if ($attrsGiven) $lists['attrs'] = $attrs;
        if ($translationsGiven) $lists['translations'] = $translations;

        $changes = [];
        foreach ($set as $column => $value) {
            $before = $existing[$column] ?? null;
            if ($before === null ? $value !== null : (string)$before !== (string)$value) $changes[$column] = $value;
        }
        foreach ($lists as $column => $value) {
            if ($json($existing[$column] ?? null) != $value) $changes[$column] = json_encode($value);
        }
        if (isset($changes['attrs'])) $changes['filter_tags'] = self::buildFilterTagsFromAttrs($attrs);

        if ($changes) {
            $assignments = implode(', ', array_map(static fn(string $c): string => "{$c} = :{$c}", array_keys($changes)));
            $params = [':id' => (int)$existing['id']];
            foreach ($changes as $column => $value) $params[":{$column}"] = $value;
            $db->prepare("UPDATE products SET {$assignments}, updated_at = " . Db::now() . " WHERE id = :id")->execute($params);
        }
        if (isset($changes['attrs'])) self::syncProductAttrs((int)$existing['id'], $attrs, $db);

        return (string)($set['product_type'] ?? $existing['product_type'] ?? 'simple');
    }

    /** "a.jpg|b.jpg" → ['a.jpg', 'b.jpg'] */
    private static function pipeList($value): array {
        return array_values(array_filter(array_map('trim', explode('|', (string)$value)), 'strlen'));
    }

    /**
     * UPSERT product — works on both SQLite and MySQL
     */
    public static function upsertProduct(array $data, ?PDO $db = null): void {
        $db = $db ?? Db::get();
        $catalog = self::assertSkuInCatalog((string)$data['sku'], $db);
        $data['description'] = Security::sanitizeRichHtml((string)($data['description'] ?? ''));
        foreach (($data['translations'] ?? []) as $locale => $translation) {
            if (isset($translation['description'])) {
                $data['translations'][$locale]['description'] = Security::sanitizeRichHtml((string)$translation['description']);
            }
        }

        if (Db::isSqlite()) {
            $stmt = $db->prepare("
                INSERT INTO products (store_id, sku, slug, product_type, name, category_id, price, compare_price, stock, status,
                    summary, description, images, attrs, tags, filter_tags, weight_g, translations)
                VALUES (:catalog, :sku, :slug, :ptype, :name, :cat, :price, :cprice, :stock, :status,
                    :summary, :desc, :images, :attrs, :tags, :ftags, :weight, :translations)
                ON CONFLICT(sku) DO UPDATE SET
                    slug = excluded.slug,
                    product_type = excluded.product_type,
                    name = excluded.name,
                    category_id = excluded.category_id,
                    price = excluded.price,
                    compare_price = excluded.compare_price,
                    stock = excluded.stock,
                    status = excluded.status,
                    summary = excluded.summary,
                    description = excluded.description,
                    images = excluded.images,
                    attrs = excluded.attrs,
                    tags = excluded.tags,
                    filter_tags = excluded.filter_tags,
                    weight_g = excluded.weight_g,
                    translations = excluded.translations,
                    updated_at = datetime('now')
            ");
        } else {
            $stmt = $db->prepare("
                INSERT INTO products (store_id, sku, slug, product_type, name, category_id, price, compare_price, stock, status,
                    summary, description, images, attrs, tags, filter_tags, weight_g, translations)
                VALUES (:catalog, :sku, :slug, :ptype, :name, :cat, :price, :cprice, :stock, :status,
                    :summary, :desc, :images, :attrs, :tags, :ftags, :weight, :translations)
                ON DUPLICATE KEY UPDATE
                    slug = VALUES(slug),
                    product_type = VALUES(product_type),
                    name = VALUES(name),
                    category_id = VALUES(category_id),
                    price = VALUES(price),
                    compare_price = VALUES(compare_price),
                    stock = VALUES(stock),
                    status = VALUES(status),
                    summary = VALUES(summary),
                    description = VALUES(description),
                    images = VALUES(images),
                    attrs = VALUES(attrs),
                    tags = VALUES(tags),
                    filter_tags = VALUES(filter_tags),
                    weight_g = VALUES(weight_g),
                    translations = VALUES(translations),
                    updated_at = NOW()
            ");
        }

        $stmt->execute([
            ':catalog'      => $catalog,
            ':sku'          => $data['sku'],
            ':slug'         => $data['slug'],
            ':ptype'        => $data['product_type'] ?? 'simple',
            ':name'         => $data['name'],
            ':cat'          => $data['category_id'],
            ':price'        => $data['price'],
            ':cprice'       => $data['compare_price'],
            ':stock'        => $data['stock'],
            ':status'       => $data['status'] ?? 'active',
            ':summary'      => $data['summary'] ?? '',
            ':desc'         => $data['description'] ?? '',
            ':images'       => json_encode($data['images'] ?? []),
            ':attrs'        => json_encode($data['attrs'] ?? []),
            ':tags'         => json_encode($data['tags'] ?? []),
            ':ftags'        => $data['filter_tags'] ?? '',
            ':weight'       => $data['weight_g'] ?? 0,
            ':translations' => json_encode($data['translations'] ?? []),
        ]);
    }

    /**
     * Ensure category exists, auto-create from path like "oblecenie/tricka"
     */
    public static function ensureCategory(string $path, ?PDO $db = null): int {
        $db = $db ?? Db::get();
        $catalog = StoreContext::current()->catalogId();
        $parts = array_filter(array_map('trim', explode('/', $path)));
        if (empty($parts)) $parts = ['uncategorized'];

        $parentId = null;
        $fullPath = '';
        $depth = 0;
        $categoryId = 0;

        foreach ($parts as $part) {
            $slug = self::slugify($part);
            $fullPath = $fullPath ? $fullPath . '/' . $slug : $slug;

            // Imported products land in the current catalog, so their categories must too:
            // other catalogs may own a category with the same slug and parent level.
            if ($parentId === null) {
                $stmt = $db->prepare("SELECT id FROM categories WHERE slug = :slug AND parent_id IS NULL AND store_id = :catalog");
                $stmt->execute([':slug' => $slug, ':catalog' => $catalog]);
            } else {
                $stmt = $db->prepare("SELECT id FROM categories WHERE slug = :slug AND parent_id = :parent AND store_id = :catalog");
                $stmt->execute([':slug' => $slug, ':parent' => $parentId, ':catalog' => $catalog]);
            }
            $existing = $stmt->fetch();

            if ($existing) {
                $categoryId = (int)$existing['id'];
            } else {
                $stmt = $db->prepare("
                    INSERT INTO categories (store_id, slug, name, parent_id, path, depth, sort_order)
                    VALUES (:catalog, :slug, :name, :parent, :path, :depth, 0)
                ");
                $stmt->execute([
                    ':catalog' => $catalog,
                    ':slug'   => $slug,
                    ':name'   => $part,
                    ':parent' => $parentId,
                    ':path'   => $fullPath,
                    ':depth'  => $depth,
                ]);
                $categoryId = (int)$db->lastInsertId();
            }

            $parentId = $categoryId;
            $depth++;
        }

        return $categoryId;
    }

    /**
     * Sync product_attrs table for a product
     */
    public static function syncProductAttrs(int $productId, array $attrs, ?PDO $db = null): void {
        $db = $db ?? Db::get();

        // Delete existing attrs
        $stmt = $db->prepare("DELETE FROM product_attrs WHERE product_id = :pid");
        $stmt->execute([':pid' => $productId]);

        // Insert new attrs
        $stmt = $db->prepare("INSERT INTO product_attrs (product_id, attr_name, attr_value, numeric_val)
            VALUES (:pid, :name, :value, :numval)");

        foreach ($attrs as $name => $value) {
            $numericVal = is_numeric($value) ? (float)$value : null;
            $stmt->execute([
                ':pid'    => $productId,
                ':name'   => $name,
                ':value'  => (string)$value,
                ':numval' => $numericVal,
            ]);
        }
    }

    /**
     * Build filter_tags string for FTS-based faceted filtering
     */
    public static function buildFilterTagsFromAttrs(array $attrs): string {
        $tags = '';
        foreach ($attrs as $name => $value) {
            $n = self::normalizeToken($name);
            $v = self::normalizeToken((string)$value);
            if ($n && $v) {
                $tags .= "attr_{$n}_{$v} ";
            }
        }
        return trim($tags);
    }

    /**
     * Register slug for uniqueness checking
     */
    public static function registerSlug(string $slug, string $locale, string $entity, int $entityId, ?PDO $db = null): void {
        $db = $db ?? Db::get();

        // Check for conflicts with a different entity
        $check = $db->prepare("SELECT entity_id FROM slug_registry WHERE slug = :slug AND locale = :locale AND entity = :entity AND entity_id != :eid");
        $check->execute([':slug' => $slug, ':locale' => $locale, ':entity' => $entity, ':eid' => $entityId]);
        if ($check->fetch()) {
            throw new \RuntimeException("Slug '{$slug}' ({$locale}) is already used by another {$entity}");
        }

        // Safe to upsert for the same entity_id (re-import of same product)
        if (Db::isSqlite()) {
            $stmt = $db->prepare("
                INSERT OR REPLACE INTO slug_registry (slug, locale, entity, entity_id)
                VALUES (:slug, :locale, :entity, :eid)
            ");
        } else {
            $stmt = $db->prepare("
                REPLACE INTO slug_registry (slug, locale, entity, entity_id)
                VALUES (:slug, :locale, :entity, :eid)
            ");
        }
        $stmt->execute([
            ':slug'   => $slug,
            ':locale' => $locale,
            ':entity' => $entity,
            ':eid'    => $entityId,
        ]);
    }

    /**
     * Validate slug uniqueness
     */
    public static function validateSlug(string $slug, string $locale, string $entity, int $entityId): bool {
        $stmt = Db::get()->prepare("
            SELECT entity_id FROM slug_registry
            WHERE slug = :slug AND locale = :locale AND entity = :entity AND entity_id != :eid
        ");
        $stmt->execute([':slug' => $slug, ':locale' => $locale, ':entity' => $entity, ':eid' => $entityId]);
        return $stmt->fetch() === false;
    }

    /**
     * Recount products per category (including subtree totals via materialized path), all or only $ids.
     * Paths are unique only within a catalog, so the subtree is matched in the category's own store.
     */
    public static function recountCategories(?PDO $db = null, ?array $ids = null): void {
        $db = $db ?? Db::get();

        // Count products in entire subtree using materialized path
        if ($ids === null) {
            $cats = $db->query("SELECT id, path, store_id FROM categories ORDER BY depth DESC")->fetchAll();
        } else {
            $one = $db->prepare("SELECT id, path, store_id FROM categories WHERE id = :id");
            $cats = [];
            foreach ($ids as $id) {
                $one->execute([':id' => (int)$id]);
                if ($row = $one->fetch()) $cats[] = $row;
            }
        }
        // Count and update separately: MySQL 8 rejects an UPDATE whose subquery reads the updated table (error 1093).
        $count = $db->prepare("SELECT COUNT(*) FROM products p
            JOIN categories c2 ON p.category_id = c2.id
            WHERE c2.store_id = :store AND (c2.path = :path OR c2.path LIKE :pathPrefix ESCAPE '!')
              AND p.status = 'active'");
        $update = $db->prepare("UPDATE categories SET product_count = :n WHERE id = :id");

        foreach ($cats as $cat) {
            $count->execute([
                ':store'      => $cat['store_id'],
                ':path'       => $cat['path'],
                ':pathPrefix' => Db::likeLiteral((string)$cat['path']) . '/%',
            ]);
            $update->execute([':n' => (int)$count->fetchColumn(), ':id' => $cat['id']]);
        }
    }

    // ============================================================
    // Helpers
    // ============================================================

    /** Gather translations from column names like name_en, name_de, summary_en, slug_en */
    private static function gatherTranslations(array $data): array {
        $translations = [];
        $translatableFields = ['name', 'summary', 'description', 'slug'];

        foreach ($data as $key => $value) {
            if ($value === '') continue;
            foreach ($translatableFields as $field) {
                if (preg_match('/^' . preg_quote($field) . '_([a-z]{2})$/', $key, $m)) {
                    $locale = $m[1];
                    $translations[$locale][$field] = trim($value);
                }
            }
        }

        return $translations;
    }

    /** Undo Security::csvCell(): the export prefixes formula-like cells with an apostrophe. */
    public static function csvCellDecode(string $value): string {
        if (strlen($value) > 1 && $value[0] === "'" && Security::csvCell(substr($value, 1)) === $value) return substr($value, 1);
        return $value;
    }

    /** Price in cents → the CSV format read by parsePriceToCents(): 2398 → "23.98". */
    public static function formatCents(int $cents): string {
        return number_format($cents / 100, 2, '.', '');
    }

    /** Convert price to cents: "19.90" → 1990. Always treats CSV values as euros. */
    public static function parsePriceToCents($price): int {
        if (is_int($price)) return $price; // Already in cents (from code)
        $price = (float)str_replace(',', '.', (string)$price);
        return (int)round($price * 100);
    }

    /** Generate URL-safe slug with proper Central European transliteration */
    public static function slugify(string $text): string {
        return Str::slug($text);
    }

    /** Normalize string for FTS token */
    private static function normalizeToken(string $s): string {
        return Str::normalizeToken($s);
    }
}
