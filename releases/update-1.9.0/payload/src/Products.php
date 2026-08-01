<?php
/**
 * BareBones Shop — Product Queries
 * All product-related database operations.
 */
class Products {
    /**
     * Get product by slug (with locale-aware slug lookup)
     */
    public static function bySlug(string $slug): ?array {
        $db = Db::get();

        // Direct slug match first
        $stmt = $db->prepare("SELECT * FROM products WHERE slug = :slug AND status = 'active'");
        $stmt->execute([':slug' => $slug]);
        $product = $stmt->fetch();

        if ($product) return $product;

        // Check slug_registry for locale-specific slugs
        $stmt = $db->prepare("
            SELECT p.* FROM slug_registry sr
            JOIN products p ON p.id = sr.entity_id
            WHERE sr.slug = :slug AND sr.entity = 'product' AND p.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get product by ID
     */
    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM products WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get product by SKU
     */
    public static function bySku(string $sku): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM products WHERE sku = :sku");
        $stmt->execute([':sku' => $sku]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get products for a category (paginated, sorted, filtered)
     */
    public static function byCategory(
        int $categoryId,
        int $page = 1,
        int $perPage = 24,
        string $sort = 'price_asc',
        array $filters = [],
        ?int $priceMin = null,
        ?int $priceMax = null
    ): array {
        $db = Db::get();
        $offset = ($page - 1) * $perPage;

        // Base conditions
        $where = "p.category_id = :cat AND p.status = 'active'";
        $params = [':cat' => $categoryId];

        // Price range
        if ($priceMin !== null) {
            $where .= " AND p.price >= :pmin";
            $params[':pmin'] = $priceMin;
        }
        if ($priceMax !== null) {
            $where .= " AND p.price <= :pmax";
            $params[':pmax'] = $priceMax;
        }

        // Attribute filters via FTS
        if (!empty($filters)) {
            $ftsTokens = [];
            foreach ($filters as $attrName => $values) {
                if (!is_array($values)) $values = [$values];
                $orTokens = [];
                foreach ($values as $v) {
                    $n = self::normalizeToken($attrName);
                    $val = self::normalizeToken($v);
                    $orTokens[] = "attr_{$n}_{$val}";
                }
                // Within same attribute: OR (pick any size)
                if (count($orTokens) === 1) {
                    $ftsTokens[] = $orTokens[0];
                } else {
                    $ftsTokens[] = '(' . implode(' OR ', $orTokens) . ')';
                }
            }
            // Between different attributes: AND (must match all)
            $ftsQuery = implode(' AND ', $ftsTokens);

            if (Db::isSqlite()) {
                $where .= " AND p.id IN (
                    SELECT rowid FROM products_fts WHERE products_fts MATCH :fts
                )";
            } else {
                $where .= " AND MATCH(p.filter_tags) AGAINST(:fts IN BOOLEAN MODE)";
            }
            $params[':fts'] = $ftsQuery;
        }

        // Sort
        $orderBy = match($sort) {
            'price_asc'  => 'p.price ASC',
            'price_desc' => 'p.price DESC',
            'name_asc'   => 'p.name ASC',
            'name_desc'  => 'p.name DESC',
            'newest'     => 'p.created_at DESC',
            default      => 'p.price ASC',
        };

        // Count total
        $countSql = "SELECT COUNT(*) FROM products p WHERE {$where}";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Fetch page
        $sql = "SELECT p.* FROM products p WHERE {$where} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset";
        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        return [
            'products' => $products,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / $perPage),
        ];
    }

    /**
     * Get products also in subcategories (using materialized path)
     */
    public static function byCategoryTree(int $categoryId, int $page = 1, int $perPage = 24, string $sort = 'price_asc'): array {
        $db = Db::get();

        // Get category path for LIKE query
        $stmt = $db->prepare("SELECT path FROM categories WHERE id = :id");
        $stmt->execute([':id' => $categoryId]);
        $catPath = $stmt->fetchColumn();

        if (!$catPath) return ['products' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 0];

        // Get all category IDs in subtree
        $stmt = $db->prepare("SELECT id FROM categories WHERE path = :path OR path LIKE :pathPrefix");
        $stmt->execute([':path' => $catPath, ':pathPrefix' => $catPath . '/%']);
        $catIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($catIds)) $catIds = [$categoryId];

        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        $offset = ($page - 1) * $perPage;

        $orderBy = match($sort) {
            'price_asc'  => 'p.price ASC',
            'price_desc' => 'p.price DESC',
            'name_asc'   => 'p.name ASC',
            'name_desc'  => 'p.name DESC',
            'newest'     => 'p.created_at DESC',
            default      => 'p.price ASC',
        };

        // Count
        $stmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE p.category_id IN ({$placeholders}) AND p.status = 'active'");
        $stmt->execute($catIds);
        $total = (int)$stmt->fetchColumn();

        // Fetch
        $stmt = $db->prepare("SELECT p.* FROM products p WHERE p.category_id IN ({$placeholders}) AND p.status = 'active' ORDER BY {$orderBy} LIMIT ? OFFSET ?");
        $stmt->execute([...$catIds, $perPage, $offset]);
        $products = $stmt->fetchAll();

        return [
            'products' => $products,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / $perPage),
        ];
    }

    /**
     * Get variants for a product
     */
    public static function variants(int $productId): array {
        $stmt = Db::get()->prepare("SELECT * FROM variants WHERE product_id = :pid ORDER BY sort_order, id");
        $stmt->execute([':pid' => $productId]);
        return $stmt->fetchAll();
    }

    /**
     * Get attributes for a product
     */
    public static function attrs(int $productId): array {
        $stmt = Db::get()->prepare("SELECT attr_name, attr_value, numeric_val FROM product_attrs WHERE product_id = :pid ORDER BY attr_name");
        $stmt->execute([':pid' => $productId]);
        return $stmt->fetchAll();
    }

    /**
     * Get available filter values for a category
     */
    public static function filterValues(int $categoryId): array {
        $stmt = Db::get()->prepare("
            SELECT pa.attr_name, pa.attr_value, COUNT(*) as cnt
            FROM product_attrs pa
            JOIN products p ON p.id = pa.product_id
            WHERE p.category_id = :cat AND p.status = 'active'
            GROUP BY pa.attr_name, pa.attr_value
            ORDER BY pa.attr_name, cnt DESC
        ");
        $stmt->execute([':cat' => $categoryId]);
        $rows = $stmt->fetchAll();

        // Group by attribute name
        $filters = [];
        foreach ($rows as $row) {
            $filters[$row['attr_name']][] = [
                'value' => $row['attr_value'],
                'count' => (int)$row['cnt'],
            ];
        }
        return $filters;
    }

    /**
     * Related products (same category, excluding current)
     */
    public static function related(int $productId, int $limit = 4): array {
        $product = self::byId($productId);
        if (!$product) return [];

        $rand = Db::isSqlite() ? 'RANDOM()' : 'RAND()';
        $stmt = Db::get()->prepare("
            SELECT * FROM products
            WHERE category_id = :cat AND id != :id AND status = 'active'
            ORDER BY {$rand}
            LIMIT :limit
        ");
        $stmt->execute([':cat' => $product['category_id'], ':id' => $productId, ':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Products selected by an administrator for this product.
     */
    public static function curatedRelated(int $productId, int $limit = 4): array {
        $stmt = Db::get()->prepare("
            SELECT p.*
            FROM product_related_products pr
            JOIN products p ON p.id = pr.related_product_id
            WHERE pr.product_id = :id AND p.status = 'active'
            ORDER BY pr.sort_order, pr.related_product_id
            LIMIT :limit
        ");
        $stmt->execute([':id' => $productId, ':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Active physical products below the stock alert threshold.
     */
    public static function lowStockSummary(int $threshold = 10, int $limit = 20): array {
        $db = Db::get();
        $typeParams = [];
        $typePlaceholders = [];
        foreach (VariantAvailability::DIGITAL_TYPES as $index => $type) {
            $placeholder = ':digital_type_' . $index;
            $typePlaceholders[] = $placeholder;
            $typeParams[$placeholder] = $type;
        }

        $where = "stock < :threshold AND status = 'active' AND LOWER(COALESCE(product_type, '')) NOT IN (" . implode(', ', $typePlaceholders) . ')';
        $params = [':threshold' => $threshold] + $typeParams;

        $countStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE {$where}");
        $countStmt->execute($params);

        $listStmt = $db->prepare("SELECT sku, name, stock FROM products WHERE {$where} ORDER BY stock ASC LIMIT :limit");
        foreach ($params as $placeholder => $value) {
            $listStmt->bindValue($placeholder, $value, $placeholder === ':threshold' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $listStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $listStmt->execute();

        return [
            'count' => (int)$countStmt->fetchColumn(),
            'products' => $listStmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Latest products
     */
    public static function latest(int $limit = 8): array {
        $stmt = Db::get()->prepare("
            SELECT * FROM products WHERE status = 'active'
            ORDER BY created_at DESC LIMIT :limit
        ");
        $stmt->execute([':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Products on sale (has compare_price)
     */
    public static function onSale(int $limit = 8): array {
        $stmt = Db::get()->prepare("
            SELECT * FROM products
            WHERE status = 'active' AND compare_price IS NOT NULL AND compare_price > price
            ORDER BY (compare_price - price) DESC
            LIMIT :limit
        ");
        $stmt->execute([':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Parametric search — filter products by category + spec attributes.
     * Generic alternative to TecDoc::parametricSearch().
     *
     * @param array $categoryIds  Category IDs to search in
     * @param int $page           Page number
     * @param int $perPage        Products per page
     * @param string $sort        Sort: price_asc, price_desc, name_asc, name_desc, newest
     * @param array $specFilters  ['Napätie' => ['12V','24V'], 'Výrobca' => ['Victron']]
     * @param ?int $priceMin      Min price in cents
     * @param ?int $priceMax      Max price in cents
     * @param array $rangeFilters ['Výkon (kW)' => ['min'=>3, 'max'=>10]]
     * @return array ['products'=>[], 'total'=>int, 'pages'=>int, 'filters'=>[]]
     */
    public static function parametricSearch(
        array $categoryIds, int $page = 1, int $perPage = 24, string $sort = 'price_asc',
        array $specFilters = [], ?int $priceMin = null, ?int $priceMax = null, array $rangeFilters = []
    ): array {
        $db = Db::get();
        if (empty($categoryIds)) return ['products' => [], 'total' => 0, 'pages' => 0, 'filters' => []];

        $ph = implode(',', array_fill(0, count($categoryIds), '?'));

        // Base query: products in these categories
        $where = "p.category_id IN ({$ph}) AND p.status = 'active'";
        $whereParams = $categoryIds;

        // Price filters
        if ($priceMin !== null) { $where .= " AND p.price >= ?"; $whereParams[] = $priceMin; }
        if ($priceMax !== null) { $where .= " AND p.price <= ?"; $whereParams[] = $priceMax; }

        // Spec enum filters (inner join for each)
        $joins = '';
        $joinParams = [];
        $ji = 0;
        foreach ($specFilters as $name => $values) {
            if (empty($values)) continue;
            $ji++;
            $vph = implode(',', array_fill(0, count($values), '?'));
            $joins .= " INNER JOIN product_specifications sf{$ji} ON sf{$ji}.product_id = p.id AND sf{$ji}.criteria_name = ? AND sf{$ji}.criteria_value IN ({$vph})";
            $joinParams[] = $name;
            $joinParams = array_merge($joinParams, $values);
        }

        // Numeric range filters
        foreach ($rangeFilters as $name => $range) {
            $ji++;
            $rangeCond = "sr{$ji}.product_id = p.id AND sr{$ji}.criteria_name = ?";
            $joinParams[] = $name;
            if (isset($range['min'])) { $rangeCond .= " AND sr{$ji}.numeric_val >= ?"; $joinParams[] = $range['min']; }
            if (isset($range['max'])) { $rangeCond .= " AND sr{$ji}.numeric_val <= ?"; $joinParams[] = $range['max']; }
            $joins .= " INNER JOIN product_specifications sr{$ji} ON {$rangeCond}";
        }

        // Merge params: JOIN params first (appear before WHERE in SQL), then WHERE params
        $params = array_merge($joinParams, $whereParams);

        // Count total
        $countSql = "SELECT COUNT(DISTINCT p.id) FROM products p {$joins} WHERE {$where}";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));

        // Sort
        $orderBy = match ($sort) {
            'price_desc' => 'p.price DESC',
            'name_asc' => 'p.name ASC',
            'name_desc' => 'p.name DESC',
            'newest' => 'p.created_at DESC',
            default => 'p.price ASC',
        };

        // Fetch page
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT DISTINCT p.* FROM products p {$joins} WHERE {$where} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build available filters from all matching products (without current spec filters applied)
        $filterSql = "SELECT DISTINCT ps.criteria_name, ps.criteria_value, ps.numeric_val
            FROM product_specifications ps
            INNER JOIN products p ON p.id = ps.product_id
            WHERE p.category_id IN ({$ph}) AND p.status = 'active'
            ORDER BY ps.criteria_name, ps.criteria_value";
        $fStmt = $db->prepare($filterSql);
        $fStmt->execute($categoryIds);

        $filters = [];
        foreach ($fStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = $row['criteria_name'];
            if (!isset($filters[$name])) {
                $filters[$name] = ['name' => $name, 'type' => 'enum', 'values' => [], 'min' => null, 'max' => null];
            }
            if ($row['numeric_val'] !== null) {
                $filters[$name]['type'] = 'range';
                $nv = (float)$row['numeric_val'];
                if ($filters[$name]['min'] === null || $nv < $filters[$name]['min']) $filters[$name]['min'] = $nv;
                if ($filters[$name]['max'] === null || $nv > $filters[$name]['max']) $filters[$name]['max'] = $nv;
            }
            $val = $row['criteria_value'];
            if (!in_array($val, $filters[$name]['values'])) {
                $filters[$name]['values'][] = $val;
            }
        }

        return ['products' => $products, 'total' => $total, 'pages' => $pages, 'filters' => $filters];
    }

    /**
     * Increment stock (for order cancellation).
     * Routes through default warehouse if available.
     */
    public static function incrementStock(int $id, int $qty): void {
        $db = Db::get();
        // Get SKU for warehouse update
        $stmt = $db->prepare("SELECT sku FROM products WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $sku = $stmt->fetchColumn();

        if ($sku) {
            $wh = Warehouse::defaultWarehouse();
            if ($wh) {
                $cur = Warehouse::totalAvailable($sku);
                Warehouse::setStock((int)$wh['id'], $sku, $cur + $qty);
            }
            Warehouse::syncProductStock([$sku]);
        } else {
            // Fallback: direct update
            $db->prepare("UPDATE products SET stock = stock + :qty WHERE id = :id")
               ->execute([':qty' => $qty, ':id' => $id]);
        }
    }

    /**
     * Bulk update stock from ERP data [{sku, qty}, ...].
     * Routes through default warehouse, recalculates product totals.
     */
    public static function bulkUpdateStock(array $stockData): int {
        $wh = Warehouse::defaultWarehouse();
        if ($wh) {
            $stockMap = [];
            foreach ($stockData as $item) {
                $stockMap[$item['sku']] = (int)$item['qty'];
            }
            return Warehouse::bulkSetStock((int)$wh['id'], $stockMap);
        }

        // Fallback: direct update (no warehouses configured)
        $db = Db::get();
        $stmt = $db->prepare("UPDATE products SET stock = :qty, updated_at = " . Db::now() . " WHERE sku = :sku");
        $updated = 0;
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();
        foreach ($stockData as $item) {
            $stmt->execute([':qty' => (int)$item['qty'], ':sku' => $item['sku']]);
            if ($stmt->rowCount() > 0) $updated++;
        }
        if ($ownTransaction) $db->commit();
        return $updated;
    }

    /**
     * Enrich product with computed fields for display.
     * Pass $categoryMap ([id => ['slug','path']]) to avoid N+1 category queries.
     */
    public static function enrich(array $product, ?array $categoryMap = null): array {
        $product['description'] = Security::sanitizeRichHtml((string)($product['description'] ?? ''));
        $product['images'] = json_decode($product['images'] ?? '[]', true);
        $product['attrs'] = json_decode($product['attrs'] ?? '{}', true);
        $product['tags'] = json_decode($product['tags'] ?? '[]', true);

        // Apply DB promotions (before formatting)
        if (class_exists('Promotions')) {
            $product = Promotions::applyToProduct($product);
        }

        $currency = $product['currency'] ?? null;
        $product['price_formatted'] = I18n::price($product['price'], $currency);

        if (!empty($product['compare_price']) && $product['compare_price'] > 0) {
            $product['compare_price_formatted'] = I18n::price($product['compare_price'], $currency);
            $product['discount_percent'] = round(
                (1 - $product['price'] / $product['compare_price']) * 100
            );
        }

        $product['url'] = '/product/' . $product['slug'];
        $product['image'] = $product['images'][0] ?? null;

        // Add category info for pricing rule matching
        if (!empty($product['category_id']) && empty($product['category_path'])) {
            if ($categoryMap !== null && isset($categoryMap[$product['category_id']])) {
                $catRow = $categoryMap[$product['category_id']];
            } else {
                $cat = Db::get()->prepare("SELECT slug, path FROM categories WHERE id = :id");
                $cat->execute([':id' => $product['category_id']]);
                $catRow = $cat->fetch();
            }
            if ($catRow) {
                $product['category_slug'] = $catRow['slug'];
                $product['category_path'] = $catRow['path'];
            }
        }

        return $product;
    }

    /**
     * Pre-fetch category map for batch enrichment. Avoids N+1 in enrich().
     */
    public static function categoryMap(): array {
        $rows = Db::get()->query("SELECT id, slug, path FROM categories")->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['id']] = ['slug' => $r['slug'], 'path' => $r['path']];
        }
        return $map;
    }

    private static function normalizeToken(string $s): string {
        return Str::normalizeToken($s);
    }
}
