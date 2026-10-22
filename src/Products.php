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
        $store = StoreContext::current()->catalogId();
        $registered = "SELECT p.* FROM slug_registry sr
            JOIN products p ON p.id = sr.entity_id
            WHERE sr.slug = :slug AND sr.entity = 'product'
              AND sr.store_id = :store AND p.store_id = :product_store AND p.status = 'active'";

        // The current language's slug first: it is the canonical URL the storefront links and redirects to, and it
        // may be another product's base slug (similar products across languages); base first would loop or hijack.
        $stmt = $db->prepare($registered . ' AND sr.locale = :locale LIMIT 1');
        $stmt->execute([':slug' => $slug, ':store' => $store, ':product_store' => $store, ':locale' => I18n::locale()]);
        $product = $stmt->fetch();
        if ($product && (string)(I18n::product($product)['slug'] ?? '') === $slug) return $product;

        // Direct slug match
        $stmt = $db->prepare("SELECT * FROM products WHERE slug = :slug AND store_id = :store AND status = 'active'");
        $stmt->execute([':slug' => $slug, ':store' => $store]);
        $product = $stmt->fetch();

        if ($product) return $product;

        // Another language's (or an old) slug
        $stmt = $db->prepare($registered . ' LIMIT 1');
        $stmt->execute([':slug' => $slug, ':store' => $store, ':product_store' => $store]);
        return $stmt->fetch() ?: null;
    }

    /** Resolve an inactive product for canonical 404/410 handling. */
    public static function bySlugAnyStatus(string $slug): ?array {
        $db = Db::get();
        $store = StoreContext::current()->catalogId();
        $stmt = $db->prepare('SELECT * FROM products WHERE slug = :slug AND store_id = :store LIMIT 1');
        $stmt->execute([':slug' => $slug, ':store' => $store]);
        $product = $stmt->fetch();
        if ($product) return $product;

        $stmt = $db->prepare("SELECT p.* FROM slug_registry sr
            JOIN products p ON p.id = sr.entity_id
            WHERE sr.slug = :slug AND sr.entity = 'product'
              AND sr.store_id = :store AND p.store_id = :product_store
            LIMIT 1");
        $stmt->execute([':slug' => $slug, ':store' => $store, ':product_store' => $store]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get product by ID
     */
    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM products WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get product by SKU
     */
    public static function bySku(string $sku): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM products WHERE sku = :sku AND store_id = :store");
        $stmt->execute([':sku' => $sku, ':store' => StoreContext::current()->catalogId()]);
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
        $page = max(1, min(Search::MAX_PAGE, $page));
        $offset = ($page - 1) * $perPage;

        // Base conditions
        // Primary category or an extra assignment, as an id set from both category indexes: "category_id = ? OR
        // EXISTS (...)" could use neither and scanned the whole catalog for every category (45 ms at 200k products).
        $where = "p.id IN (
            SELECT id FROM products WHERE category_id = :cat
            UNION SELECT product_id FROM product_categories WHERE category_id = :cat_link
        ) AND p.store_id = :store AND p.status = 'active'";
        $params = [':cat' => $categoryId, ':cat_link' => $categoryId, ':store' => StoreContext::current()->catalogId()];

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
        $ftsQuery = '';
        if (!empty($filters)) {
            $ftsTokens = [];
            foreach (array_slice($filters, 0, self::MAX_FILTER_CRITERIA, true) as $attrName => $values) {
                if (!is_array($values)) $values = [$values];
                $orTokens = [];
                // Only scalar values become tokens: a nested array from a JSON filter used to be a TypeError (500).
                foreach (array_slice(array_values(array_filter($values, 'is_scalar')), 0, self::MAX_FILTER_VALUES) as $v) {
                    $n = self::normalizeToken((string)$attrName);
                    $val = self::normalizeToken((string)$v);
                    $orTokens[] = "attr_{$n}_{$val}";
                }
                if ($orTokens === []) continue;
                // Within same attribute: OR (pick any size)
                if (count($orTokens) === 1) {
                    $ftsTokens[] = $orTokens[0];
                } else {
                    $ftsTokens[] = '(' . implode(' OR ', $orTokens) . ')';
                }
            }
            // Between different attributes: AND (must match all)
            $ftsQuery = implode(' AND ', $ftsTokens);
        }
        if ($ftsQuery !== '') {
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
        $stmt = $db->prepare("SELECT path FROM categories WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $categoryId, ':store' => StoreContext::current()->catalogId()]);
        $catPath = $stmt->fetchColumn();

        if (!$catPath) return ['products' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 0];

        // Get all category IDs in subtree
        $stmt = $db->prepare("SELECT id FROM categories WHERE store_id = :store AND (path = :path OR path LIKE :pathPrefix ESCAPE '!')");
        $stmt->execute([':store' => StoreContext::current()->catalogId(), ':path' => $catPath, ':pathPrefix' => Db::likeLiteral((string)$catPath) . '/%']);
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

        $categoryWhere = "p.id IN (
            SELECT id FROM products WHERE category_id IN ({$placeholders})
            UNION SELECT product_id FROM product_categories WHERE category_id IN ({$placeholders})
        )";
        $categoryParams = [...$catIds, ...$catIds];

        // Count
        $stmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE {$categoryWhere} AND p.store_id = ? AND p.status = 'active'");
        $stmt->execute([...$categoryParams, StoreContext::current()->catalogId()]);
        $total = (int)$stmt->fetchColumn();

        // Fetch
        $stmt = $db->prepare("SELECT p.* FROM products p WHERE {$categoryWhere} AND p.store_id = ? AND p.status = 'active' ORDER BY {$orderBy} LIMIT ? OFFSET ?");
        $stmt->execute([...$categoryParams, StoreContext::current()->catalogId(), $perPage, $offset]);
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
        $stmt = Db::get()->prepare("SELECT v.* FROM variants v JOIN products p ON p.id = v.product_id WHERE v.product_id = :pid AND p.store_id = :store ORDER BY v.sort_order, v.id");
        $stmt->execute([':pid' => $productId, ':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetchAll();
    }

    /** All category assignments, with the legacy primary category included. */
    public static function categoryIds(int $productId): array {
        try {
            $stmt = Db::get()->prepare("SELECT DISTINCT pc.category_id
                FROM product_categories pc
                JOIN products p ON p.id = pc.product_id
                JOIN categories c ON c.id = pc.category_id AND c.store_id = p.store_id
                WHERE pc.product_id = :product AND p.store_id = :store
                ORDER BY pc.is_primary DESC, pc.sort_order, pc.category_id");
            $stmt->execute([':product' => $productId, ':store' => StoreContext::current()->catalogId()]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== []) return $ids;
            $product = self::byId($productId);
            return $product && !empty($product['category_id']) ? [(int)$product['category_id']] : [];
        } catch (PDOException $e) {
            $product = self::byId($productId);
            return $product && !empty($product['category_id']) ? [(int)$product['category_id']] : [];
        }
    }

    /**
     * Get attributes for a product
     */
    public static function attrs(int $productId): array {
        $stmt = Db::get()->prepare("SELECT pa.attr_name, pa.attr_value, pa.numeric_val FROM product_attrs pa JOIN products p ON p.id = pa.product_id WHERE pa.product_id = :pid AND p.store_id = :store ORDER BY pa.attr_name");
        $stmt->execute([':pid' => $productId, ':store' => StoreContext::current()->catalogId()]);
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
            WHERE p.category_id = :cat AND p.store_id = :store AND p.status = 'active'
            GROUP BY pa.attr_name, pa.attr_value
            ORDER BY pa.attr_name, cnt DESC
        ");
        $stmt->execute([':cat' => $categoryId, ':store' => StoreContext::current()->catalogId()]);
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
            WHERE category_id = :cat AND id != :id AND store_id = :store AND status = 'active'
            ORDER BY {$rand}
            LIMIT :limit
        ");
        $stmt->execute([':cat' => $product['category_id'], ':id' => $productId, ':store' => StoreContext::current()->catalogId(), ':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /** Products selected by an administrator under the conventional related relation. */
    public static function curatedRelated(int $productId, int $limit = 4): array {
        return self::relatedByType($productId, 'related', $limit);
    }

    /** Active target products for one safe, arbitrary relation type. */
    public static function relatedByType(int $productId, string $relationType, int $limit = 100): array {
        self::assertRelationType($relationType);
        $stmt = Db::get()->prepare("
            SELECT p.*
            FROM product_related_products pr
            JOIN products p ON p.id = pr.related_product_id
            WHERE pr.product_id = :id AND pr.relation_type = :type
              AND p.store_id = :store AND p.status = 'active'
              AND EXISTS (SELECT 1 FROM products source WHERE source.id = pr.product_id AND source.store_id = :source_store)
            ORDER BY pr.sort_order, pr.related_product_id
            LIMIT :limit
        ");
        $store = StoreContext::current()->catalogId();
        $stmt->execute([':id' => $productId, ':type' => $relationType, ':store' => $store, ':source_store' => $store, ':limit' => max(1, min(500, $limit))]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** All typed relations, including metadata used to group canonical card models. */
    public static function typedRelations(int $productId, int $limit = 500): array {
        $stmt = Db::get()->prepare("
            SELECT p.*, pr.relation_type, pr.sort_order AS relation_sort_order
            FROM product_related_products pr
            JOIN products p ON p.id = pr.related_product_id
            WHERE pr.product_id = :id AND p.store_id = :store AND p.status = 'active'
              AND EXISTS (SELECT 1 FROM products source WHERE source.id = pr.product_id AND source.store_id = :source_store)
            ORDER BY pr.relation_type, pr.sort_order, pr.related_product_id
            LIMIT :limit
        ");
        $store = StoreContext::current()->catalogId();
        $stmt->execute([':id' => $productId, ':store' => $store, ':source_store' => $store, ':limit' => max(1, min(1000, $limit))]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Latest products
     */
    public static function latest(int $limit = 8): array {
        $stmt = Db::get()->prepare("
            SELECT * FROM products WHERE store_id = :store AND status = 'active'
            ORDER BY created_at DESC LIMIT :limit
        ");
        $stmt->execute([':store' => StoreContext::current()->catalogId(), ':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Products on sale (has compare_price)
     */
    public static function onSale(int $limit = 8): array {
        $stmt = Db::get()->prepare("
            SELECT * FROM products
            WHERE store_id = :store AND status = 'active' AND compare_price IS NOT NULL AND compare_price > price
            ORDER BY (compare_price - price) DESC
            LIMIT :limit
        ");
        $stmt->execute([':store' => StoreContext::current()->catalogId(), ':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get specifications for a product in a given language
     */
    public static function specifications(int $productId, string $lang = 'sk'): array {
        $stmt = Db::get()->prepare("
            SELECT ps.criteria_name, ps.criteria_value, ps.numeric_val
            FROM product_specifications ps
            JOIN products p ON p.id = ps.product_id AND p.store_id = :store
            WHERE ps.product_id = :pid AND ps.lang = :lang
            ORDER BY ps.sort_order, ps.criteria_name
        ");
        $stmt->execute([':pid' => $productId, ':lang' => $lang, ':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetchAll();
    }

    /**
     * Get available specification criteria + values for a category (for filter UI)
     * Returns: ['Priemer [mm]' => [{value: '300', count: 15, numeric: 300.0}, ...], ...]
     * Criteria are discovered from the data, so every category gets its own filters.
     */
    public static function specFilterValues(int|array $categoryId, string $lang = 'sk', array $activeFilters = [], ?int $priceMin = null, ?int $priceMax = null): array {
        $db = Db::get();
        // The facets of an unfiltered category page (what every visit opens) are cached until the catalog changes:
        // aggregating the specifications of a 50k-product category took 0.5-2 s on every request.
        $cacheKey = null;
        if ($activeFilters === [] && $priceMin === null && $priceMax === null) {
            $ids = array_map('intval', (array)$categoryId);
            sort($ids);
            $cacheKey = Cache::localePath($lang, 'facets/' . hash('sha256', implode(',', $ids)) . '.json');
            $cached = Cache::readCatalogJson($cacheKey, 3600);
            if ($cached !== null) return $cached;
        }

        if (is_array($categoryId)) {
            $catIds = array_map('intval', $categoryId);
            $catIn = implode(',', $catIds);
            $baseWhere = "p.category_id IN ({$catIn}) AND p.status = 'active'";
            $baseParams = [];
        } else {
            $baseWhere = "p.category_id = :cat AND p.status = 'active'";
            $baseParams = [':cat' => $categoryId];
        }
        $baseWhere .= ' AND p.store_id = :store';
        $baseParams[':store'] = StoreContext::current()->catalogId();

        if ($priceMin !== null) {
            $baseWhere .= " AND p.price >= :pmin";
            $baseParams[':pmin'] = $priceMin;
        }
        if ($priceMax !== null) {
            $baseWhere .= " AND p.price <= :pmax";
            $baseParams[':pmax'] = $priceMax;
        }

        $loadValues = static function (?string $criterion, ?string $excludedFilter) use ($db, $baseWhere, $baseParams, $activeFilters, $lang): array {
            $where = $baseWhere;
            $params = $baseParams;
            $filterIndex = 0;
            foreach ($activeFilters as $filterName => $filterValues) {
                if ($filterName === $excludedFilter || empty($filterValues)) continue;
                $placeholders = [];
                foreach (array_values((array)$filterValues) as $valueIndex => $filterValue) {
                    $key = ":fv_{$filterIndex}_{$valueIndex}";
                    $placeholders[] = $key;
                    $params[$key] = $filterValue;
                }
                $nameKey = ":fn_{$filterIndex}";
                $langKey = ":fl_{$filterIndex}";
                $params[$nameKey] = $filterName;
                $params[$langKey] = $lang;
                $where .= " AND p.id IN (
                    SELECT ps2.product_id FROM product_specifications ps2
                    WHERE ps2.criteria_name = {$nameKey}
                      AND ps2.criteria_value IN (" . implode(',', $placeholders) . ")
                      AND ps2.lang = {$langKey}
                )";
                $filterIndex++;
            }

            $params[':facet_lang'] = $lang;
            $criterionWhere = '';
            if ($criterion !== null) {
                $criterionWhere = ' AND ps.criteria_name = :facet_criterion';
                $params[':facet_criterion'] = $criterion;
            }
            $stmt = $db->prepare("
                SELECT ps.criteria_name, ps.criteria_value, MIN(ps.numeric_val) AS numeric_val,
                       COUNT(DISTINCT ps.product_id) AS cnt
                FROM product_specifications ps
                JOIN products p ON p.id = ps.product_id
                WHERE {$where} AND ps.lang = :facet_lang{$criterionWhere}
                GROUP BY ps.criteria_name, ps.criteria_value
                HAVING COUNT(DISTINCT ps.product_id) > 0
                ORDER BY ps.criteria_name, MIN(ps.numeric_val) IS NULL, MIN(ps.numeric_val), ps.criteria_value
            ");
            $stmt->execute($params);
            return $stmt->fetchAll();
        };

        $rowsByCriterion = [];
        foreach ($loadValues(null, null) as $row) {
            $rowsByCriterion[$row['criteria_name']][] = $row;
        }
        foreach ($activeFilters as $criterion => $values) {
            if (empty($values)) continue;
            $replacement = $loadValues($criterion, $criterion);
            if ($replacement !== []) $rowsByCriterion[$criterion] = $replacement;
        }
        ksort($rowsByCriterion, SORT_STRING);

        $filters = [];
        foreach ($rowsByCriterion as $criterion => $values) {
            if (count($values) <= 1 || count($values) > 50) continue;
            $filters[$criterion] = array_map(static fn(array $value): array => [
                'value' => $value['criteria_value'],
                'count' => (int)$value['cnt'],
                'numeric' => $value['numeric_val'],
            ], $values);
        }
        if ($cacheKey !== null) Cache::writeJson($cacheKey, $filters);
        return $filters;
    }

    /** Bounds for query-string facet filters: every criterion is one correlated subquery per request. */
    public const MAX_FILTER_CRITERIA = 10;
    public const MAX_FILTER_VALUES = 20;

    /**
     * Keep only spec/range filters on criteria the categories really have in $lang, at most
     * MAX_FILTER_CRITERIA criteria with MAX_FILTER_VALUES distinct scalar values each, and finite range edges.
     * Returns [$specFilters, $rangeFilters].
     */
    public static function boundSpecFilters(int|array $categoryId, string $lang, array $specFilters, array $rangeFilters): array {
        $catIds = array_values(array_unique(array_map('intval', (array)$categoryId)));
        if ($catIds === [] || ($specFilters === [] && $rangeFilters === [])) return [[], []];
        $stmt = Db::get()->prepare('SELECT DISTINCT ps.criteria_name FROM product_specifications ps
            JOIN products p ON p.id = ps.product_id
            WHERE p.category_id IN (' . implode(',', $catIds) . ") AND p.store_id = :store AND p.status = 'active' AND ps.lang = :lang");
        $stmt->execute([':store' => StoreContext::current()->catalogId(), ':lang' => $lang]);
        $known = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        $spec = [];
        foreach ($specFilters as $name => $values) {
            if (count($spec) >= self::MAX_FILTER_CRITERIA) break;
            if (!isset($known[(string)$name])) continue;
            $values = array_values(array_unique(array_map('strval', array_filter((array)$values, 'is_scalar'))));
            if ($values !== []) $spec[(string)$name] = array_slice($values, 0, self::MAX_FILTER_VALUES);
        }
        $ranges = [];
        foreach ($rangeFilters as $name => $range) {
            if (count($ranges) >= self::MAX_FILTER_CRITERIA) break;
            if (!isset($known[(string)$name]) || !is_array($range)) continue;
            $edges = [];
            foreach (['min', 'max'] as $edge) {
                if (isset($range[$edge]) && is_numeric($range[$edge]) && is_finite((float)$range[$edge])) $edges[$edge] = (float)$range[$edge];
            }
            if ($edges !== []) $ranges[(string)$name] = $edges;
        }
        return [$spec, $ranges];
    }

    /**
     * Search products within categories using parametric spec filters.
     * $specFilters = ['Material' => ['cotton', 'wool']] (AND across criteria, OR within one);
     * $rangeFilters = ['Power (kW)' => ['min' => 3, 'max' => 10]]
     */
    public static function parametricSearch(
        int|array $categoryId,
        int $page = 1,
        int $perPage = 24,
        string $sort = 'price_asc',
        array $specFilters = [],
        ?int $priceMin = null,
        ?int $priceMax = null,
        string $lang = 'sk',
        array $rangeFilters = []
    ): array {
        $db = Db::get();
        $page = max(1, min(Search::MAX_PAGE, $page));
        $offset = ($page - 1) * $perPage;
        [$specFilters, $rangeFilters] = self::boundSpecFilters($categoryId, $lang, $specFilters, $rangeFilters);

        if (is_array($categoryId)) {
            $catIds = array_map('intval', $categoryId);
            $catIn = implode(',', $catIds);
            $where = "p.category_id IN ({$catIn}) AND p.status = 'active'";
            $params = [];
        } else {
            $where = "p.category_id = :cat AND p.status = 'active'";
            $params = [':cat' => $categoryId];
        }
        $where .= ' AND p.store_id = :store';
        $params[':store'] = StoreContext::current()->catalogId();

        if ($priceMin !== null) {
            $where .= " AND p.price >= :pmin";
            $params[':pmin'] = $priceMin;
        }
        if ($priceMax !== null) {
            $where .= " AND p.price <= :pmax";
            $params[':pmax'] = $priceMax;
        }

        // Apply spec filters — each criterion is AND, values within a criterion are OR
        $filterIdx = 0;
        foreach ($specFilters as $criterionName => $values) {
            if (empty($values)) continue;
            $placeholders = [];
            // Value keys come from the query string: never paste them into placeholder names.
            foreach (array_values((array)$values) as $i => $v) {
                $key = ":sv_{$filterIdx}_{$i}";
                $placeholders[] = $key;
                $params[$key] = $v;
            }
            $phStr = implode(',', $placeholders);
            $cnKey = ":cn_{$filterIdx}";
            $langKey = ":lang_{$filterIdx}";
            $params[$cnKey] = $criterionName;
            $params[$langKey] = $lang;

            $where .= " AND p.id IN (
                SELECT ps.product_id FROM product_specifications ps
                WHERE ps.criteria_name = {$cnKey}
                  AND ps.criteria_value IN ({$phStr})
                  AND ps.lang = {$langKey}
            )";
            $filterIdx++;
        }

        // Apply numeric range filters (e.g., kW 3-9)
        $rangeIdx = 0;
        foreach ($rangeFilters as $criterionName => $range) {
            $cnKey = ":rcn_{$rangeIdx}";
            $langKey = ":rlang_{$rangeIdx}";
            $params[$cnKey] = $criterionName;
            $params[$langKey] = $lang;
            $rangeCond = "ps.criteria_name = {$cnKey} AND ps.lang = {$langKey}";

            if (isset($range['min'])) {
                $minKey = ":rmin_{$rangeIdx}";
                $params[$minKey] = $range['min'];
                $rangeCond .= " AND ps.numeric_val >= {$minKey}";
            }
            if (isset($range['max'])) {
                $maxKey = ":rmax_{$rangeIdx}";
                $params[$maxKey] = $range['max'];
                $rangeCond .= " AND ps.numeric_val <= {$maxKey}";
            }

            $where .= " AND p.id IN (
                SELECT ps.product_id FROM product_specifications ps
                WHERE {$rangeCond}
            )";
            $rangeIdx++;
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

        // Count
        $stmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE {$where}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Fetch
        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;
        $stmt = $db->prepare("SELECT p.* FROM products p WHERE {$where} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset");
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        return [
            'products'   => $products,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'pages'      => (int)ceil($total / $perPage),
            'filters'    => self::specFilterValues($categoryId, $lang, $specFilters, $priceMin, $priceMax),
            // The filters actually applied after boundSpecFilters(): presenters echo these, not the raw query.
            'applied_spec' => $specFilters,
            'applied_ranges' => $rangeFilters,
        ];
    }

    /** Deterministic margin in integer cents and basis points. */
    public static function margin(int $salePrice, int $costPrice): array {
        $salePrice = max(0, $salePrice);
        $costPrice = max(0, $costPrice);
        $amount = $salePrice - $costPrice;
        return [
            'amount' => $amount,
            'bps' => $salePrice === 0 ? 0 : intdiv($amount * 10000, $salePrice),
        ];
    }

    /**
     * Admin/CSV stock edit expressed as "available": booked in the warehouse ledger for physical
     * products (digital ones are not stock-tracked). On failure products.stock is re-synced.
     */
    public static function setAvailableStock(string $sku, string $productType, int $available, string $movementType, string $referenceType): bool {
        if (in_array(strtolower($productType), VariantAvailability::DIGITAL_TYPES, true)) return false;
        try {
            return Warehouse::setAvailable($sku, max(0, $available), $movementType, $referenceType);
        } catch (RuntimeException | InvalidArgumentException $e) {
            Warehouse::syncProductStock([$sku]);
            throw $e;
        }
    }

    /**
     * product.updated for a product whose API representation changed since $before (admin edit, CSV/feed import),
     * recorded in the caller's transaction like the API's own event.
     */
    public static function recordUpdated(int $productId, array $before): void {
        $after = self::byId($productId);
        if (!$after) return;
        $dto = ApiV1::productDto($after);
        $comparable = static fn(array $row): array => array_diff_key(ApiV1::productDto($row), ['updated_at' => true]);
        if ($comparable($before) === $comparable($after)) return;
        Outbox::record(DomainEvents::PRODUCT_UPDATED, 'product', $productId, $dto);
    }

    /**
     * Increment stock (for order cancellation).
     * Routes through default warehouse if available.
     */
    public static function incrementStock(int $id, int $qty): void {
        $db = Db::get();
        // Get SKU for warehouse update
        $stmt = $db->prepare("SELECT sku FROM products WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->catalogId()]);
        $sku = $stmt->fetchColumn();

        if ($sku) {
            $wh = Warehouse::defaultWarehouse();
            if ($wh) {
                $rows = Warehouse::stockBySku((string)$sku);
                $currentOnHand = 0;
                foreach ($rows as $row) {
                    if ((int)$row['id'] === (int)$wh['id']) {
                        $currentOnHand = (int)$row['qty'];
                        break;
                    }
                }
                Warehouse::setStock((int)$wh['id'], (string)$sku, $currentOnHand + $qty, 'legacy_restore');
            }
            Warehouse::syncProductStock([$sku]);
        } else {
            // Fallback: direct update
            $db->prepare("UPDATE products SET stock = stock + :qty WHERE id = :id AND store_id = :store")
               ->execute([':qty' => $qty, ':id' => $id, ':store' => StoreContext::current()->catalogId()]);
            Cache::catalogChanged();
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
        $stmt = $db->prepare("UPDATE products SET stock = :qty, updated_at = " . Db::now() . " WHERE sku = :sku AND store_id = :store");
        $updated = 0;
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();
        foreach ($stockData as $item) {
            $stmt->execute([':qty' => (int)$item['qty'], ':sku' => $item['sku'], ':store' => StoreContext::current()->catalogId()]);
            if ($stmt->rowCount() > 0) $updated++;
        }
        if ($ownTransaction) $db->commit();
        if ($updated > 0) Cache::catalogChanged();
        return $updated;
    }

    /** Enrich one product through the same request-scoped path as listings. */
    public static function enrich(array $product, ?array $categoryMap = null): array {
        return self::enrichMany([$product], $categoryMap)[0];
    }

    /** Enrich a collection with a constant query budget while preserving keys and order. */
    public static function enrichMany(array $products, ?array $categoryMap = null): array {
        if ($products === []) return [];
        $context = ProductEnrichmentContext::forProducts($products, $categoryMap, self::sessionCustomerId());
        $result = [];
        foreach ($products as $key => $product) {
            $result[$key] = self::enrichWithContext($product, $context);
        }
        return $result;
    }

    /** Batch all category assignments, with a legacy primary-category fallback. */
    public static function categoryIdsMany(array $products): array {
        $result = [];
        $productRows = [];
        foreach ($products as $product) {
            $productId = (int)($product['id'] ?? 0);
            if ($productId <= 0) continue;
            $result[$productId] = [];
            $productRows[$productId] = $product;
        }
        if ($productRows === []) return $result;

        $params = [':store' => StoreContext::current()->catalogId()];
        $placeholders = [];
        foreach (array_keys($productRows) as $index => $productId) {
            $placeholder = ':product_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $productId;
        }

        try {
            $stmt = Db::get()->prepare("SELECT pc.product_id, pc.category_id, pc.is_primary, pc.sort_order
                FROM product_categories pc
                JOIN products p ON p.id = pc.product_id
                JOIN categories c ON c.id = pc.category_id AND c.store_id = p.store_id
                WHERE pc.product_id IN (" . implode(', ', $placeholders) . ") AND p.store_id = :store
                ORDER BY pc.product_id, pc.is_primary DESC, pc.sort_order, pc.category_id");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $productId = (int)$row['product_id'];
                $categoryId = (int)$row['category_id'];
                if (!in_array($categoryId, $result[$productId], true)) $result[$productId][] = $categoryId;
            }
            foreach ($productRows as $productId => $product) {
                if ($result[$productId] === [] && !empty($product['category_id'])) $result[$productId] = [(int)$product['category_id']];
            }
        } catch (PDOException $e) {
            foreach ($productRows as $productId => $product) {
                if (!empty($product['category_id'])) $result[$productId] = [(int)$product['category_id']];
            }
        }
        return $result;
    }

    private static function enrichWithContext(array $product, ProductEnrichmentContext $context): array {
        $product['description'] = Security::sanitizeRichHtml((string)($product['description'] ?? ''));
        $product['images'] = json_decode($product['images'] ?? '[]', true);
        $product['attrs'] = json_decode($product['attrs'] ?? '{}', true);
        $product['tags'] = json_decode($product['tags'] ?? '[]', true);
        $product['category_ids'] = $context->categoryIds((int)($product['id'] ?? 0));
        $product = $context->applyCommerce($product);
        unset($product['cost_price']);

        $currency = $product['currency'] ?? null;
        $product['price_formatted'] = I18n::price($product['price'], $currency);
        if (!empty($product['compare_price']) && $product['compare_price'] > 0) {
            $product['compare_price_formatted'] = I18n::price($product['compare_price'], $currency);
            $product['discount_percent'] = round((1 - $product['price'] / $product['compare_price']) * 100);
        }

        $product['url'] = UrlAliases::outbound('/product/' . $product['slug']);
        $product['image'] = $product['images'][0] ?? null;
        if (!empty($product['category_id']) && empty($product['category_path'])) {
            $catRow = $context->category((int)$product['category_id']);
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
        $stmt = Db::get()->prepare("SELECT id, slug, path FROM categories WHERE store_id = :store");
        $stmt->execute([':store' => StoreContext::current()->catalogId()]);
        $rows = $stmt->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['id']] = ['slug' => $r['slug'], 'path' => $r['path']];
        }
        return $map;
    }

    private static function sessionCustomerId(): ?int {
        $id = (int)($_SESSION['customer_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private static function normalizeToken(string $s): string {
        return Str::normalizeToken($s);
    }

    private static function assertRelationType(string $type): void {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,79}$/', $type)) {
            throw new InvalidArgumentException('Invalid product relation type');
        }
    }
}
