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

            $data = array_combine($header, $row);

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
     * Import a single product from associative array
     */
    public static function importProduct(array $data, ?PDO $db = null): void {
        $db = $db ?? Db::get();

        // Required fields
        $sku  = trim($data['sku'] ?? '');
        $name = trim($data['name'] ?? '');
        if (!$sku || !$name) {
            throw new \InvalidArgumentException("Missing required field: sku or name");
        }

        // Category: auto-create from path (e.g., "oblecenie/tricka")
        $categoryPath = trim($data['category'] ?? 'uncategorized');
        $categoryId = self::ensureCategory($categoryPath, $db);

        // Slug: generate from name if not provided
        $slug = trim($data['slug'] ?? '') ?: self::slugify($name);

        // Price in cents
        $price = self::parsePriceToCents($data['price'] ?? 0);
        $comparePrice = !empty($data['compare_price']) ? self::parsePriceToCents($data['compare_price']) : null;

        // Gather translations from columns like name_en, name_de, summary_en, slug_en
        $translations = self::gatherTranslations($data);

        // Gather attrs from attr:* columns
        $attrs = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'attr:') && $value !== '') {
                $attrName = substr($key, 5);
                $attrs[$attrName] = $value;
            }
        }

        // Tags
        $tags = [];
        if (!empty($data['tags'])) {
            $tags = array_map('trim', explode('|', $data['tags']));
        }

        // Build filter_tags for FTS
        $filterTags = self::buildFilterTagsFromAttrs($attrs);

        // Product type
        $productType = trim($data['product_type'] ?? 'simple');

        // Images
        $images = [];
        if (!empty($data['image'])) {
            $images = array_map('trim', explode('|', $data['image']));
        }

        // UPSERT
        self::upsertProduct([
            'sku'           => $sku,
            'slug'          => $slug,
            'product_type'  => $productType,
            'name'          => $name,
            'category_id'   => $categoryId,
            'price'         => $price,
            'compare_price' => $comparePrice,
            'stock'         => (int)($data['stock'] ?? 0),
            'status'        => trim($data['status'] ?? 'active'),
            'summary'       => trim($data['summary'] ?? ''),
            'description'   => trim($data['description'] ?? ''),
            'images'        => $images,
            'attrs'         => $attrs,
            'tags'          => $tags,
            'filter_tags'   => $filterTags,
            'weight_g'      => (int)($data['weight_g'] ?? 0),
            'translations'  => $translations,
        ], $db);

        // Get product ID for attrs table
        $stmt = $db->prepare("SELECT id FROM products WHERE sku = :sku");
        $stmt->execute([':sku' => $sku]);
        $productId = (int)$stmt->fetchColumn();

        // Optional per-product stock alert thresholds; an empty cell resets to the config default.
        foreach (['stock_low_threshold', 'stock_critical_threshold'] as $column) {
            if (!array_key_exists($column, $data)) continue;
            $value = trim((string)$data[$column]);
            $db->prepare("UPDATE products SET {$column} = :value WHERE id = :id")
                ->execute([':value' => $value === '' ? null : max(0, (int)$value), ':id' => $productId]);
        }
        // The stock column is "available"; with warehouses it is booked in the ledger (source of truth).
        if (array_key_exists('stock', $data) && trim((string)$data['stock']) !== '') {
            Products::setAvailableStock($sku, $productType, (int)$data['stock'], 'import', 'csv_import');
        }

        // Populate product_attrs table
        self::syncProductAttrs($productId, $attrs, $db);

        // Register base slug under 'sk' — CSV import convention treats base columns as SK.
        // Per-locale slugs from translations are registered below.
        self::registerSlug($slug, 'sk', 'product', $productId, $db);
        foreach ($translations as $locale => $tr) {
            if (!empty($tr['slug'])) {
                self::registerSlug($tr['slug'], $locale, 'product', $productId, $db);
            }
        }
    }

    /**
     * UPSERT product — works on both SQLite and MySQL
     */
    public static function upsertProduct(array $data, ?PDO $db = null): void {
        $db = $db ?? Db::get();
        $data['description'] = Security::sanitizeRichHtml((string)($data['description'] ?? ''));
        foreach (($data['translations'] ?? []) as $locale => $translation) {
            if (isset($translation['description'])) {
                $data['translations'][$locale]['description'] = Security::sanitizeRichHtml((string)$translation['description']);
            }
        }

        if (Db::isSqlite()) {
            $stmt = $db->prepare("
                INSERT INTO products (sku, slug, product_type, name, category_id, price, compare_price, stock, status,
                    summary, description, images, attrs, tags, filter_tags, weight_g, translations)
                VALUES (:sku, :slug, :ptype, :name, :cat, :price, :cprice, :stock, :status,
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
                INSERT INTO products (sku, slug, product_type, name, category_id, price, compare_price, stock, status,
                    summary, description, images, attrs, tags, filter_tags, weight_g, translations)
                VALUES (:sku, :slug, :ptype, :name, :cat, :price, :cprice, :stock, :status,
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
        $parts = array_filter(array_map('trim', explode('/', $path)));
        if (empty($parts)) $parts = ['uncategorized'];

        $parentId = null;
        $fullPath = '';
        $depth = 0;
        $categoryId = 0;

        foreach ($parts as $part) {
            $slug = self::slugify($part);
            $fullPath = $fullPath ? $fullPath . '/' . $slug : $slug;

            if ($parentId === null) {
                $stmt = $db->prepare("SELECT id FROM categories WHERE slug = :slug AND parent_id IS NULL");
                $stmt->execute([':slug' => $slug]);
            } else {
                $stmt = $db->prepare("SELECT id FROM categories WHERE slug = :slug AND parent_id = :parent");
                $stmt->execute([':slug' => $slug, ':parent' => $parentId]);
            }
            $existing = $stmt->fetch();

            if ($existing) {
                $categoryId = (int)$existing['id'];
            } else {
                $stmt = $db->prepare("
                    INSERT INTO categories (slug, name, parent_id, path, depth, sort_order)
                    VALUES (:slug, :name, :parent, :path, :depth, 0)
                ");
                $stmt->execute([
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
     * Recount products per category (including subtree totals via materialized path)
     */
    public static function recountCategories(?PDO $db = null): void {
        $db = $db ?? Db::get();

        // Count products in entire subtree using materialized path
        $cats = $db->query("SELECT id, path FROM categories ORDER BY depth DESC")->fetchAll();
        $stmt = $db->prepare("UPDATE categories SET product_count = (
            SELECT COUNT(*) FROM products p
            JOIN categories c2 ON p.category_id = c2.id
            WHERE (c2.path = :path OR c2.path LIKE :pathPrefix)
              AND p.status = 'active'
        ) WHERE id = :id");

        foreach ($cats as $cat) {
            $stmt->execute([
                ':path'       => $cat['path'],
                ':pathPrefix' => $cat['path'] . '/%',
                ':id'         => $cat['id'],
            ]);
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

    /** Convert price to cents: "19.90" → 1990. Always treats CSV values as euros. */
    public static function parsePriceToCents($price): int {
        if (is_int($price)) return $price; // Already in cents (from code)
        $price = (float)str_replace(',', '.', (string)$price);
        return (int)round($price * 100);
    }

    /** Generate URL-safe slug with proper Central European transliteration */
    public static function slugify(string $text): string {
        $text = Str::removeDiacritics($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }

    /** Normalize string for FTS token */
    private static function normalizeToken(string $s): string {
        return Str::normalizeToken($s);
    }
}
