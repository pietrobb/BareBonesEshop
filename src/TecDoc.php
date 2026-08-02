<?php
/**
 * BareBones Shop — TecDoc Auto Parts
 * Vehicle cascade lookups, OE search, parametric specification filtering.
 *
 * Reads from the sidecar TecDoc DB for vehicle cascade (manufacturer→model→type),
 * and from shop.sqlite for product specs, OE numbers, and parametric search.
 */
class TecDoc {
    private static ?PDO $sidecar = null;

    /**
     * Get sidecar TecDoc database connection (read-only)
     */
    public static function sidecarDb(): ?PDO {
        if (self::$sidecar) return self::$sidecar;

        $cfg = require __DIR__ . '/../config.php';
        $path = $cfg['tecdoc_db_path'] ?? '';
        if (!$path || !file_exists($path)) return null;

        self::$sidecar = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$sidecar->exec('PRAGMA journal_mode = WAL');
        self::$sidecar->exec('PRAGMA cache_size = -32000');
        self::$sidecar->exec('PRAGMA mmap_size = 134217728');
        return self::$sidecar;
    }

    /** For testing: inject sidecar PDO */
    public static function initSidecar(PDO $pdo): void {
        self::$sidecar = $pdo;
    }

    /** Reset sidecar connection */
    public static function resetSidecar(): void {
        self::$sidecar = null;
    }

    // ============================================================
    // Vehicle Cascade (from sidecar DB)
    // ============================================================

    /**
     * Get all vehicle manufacturers
     */
    public static function manufacturers(): array {
        $sdb = self::sidecarDb();
        if (!$sdb) return [];
        return $sdb->query("
            SELECT DISTINCT manufacturer FROM vehicles ORDER BY manufacturer
        ")->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get models for a manufacturer
     */
    public static function models(string $manufacturer): array {
        $sdb = self::sidecarDb();
        if (!$sdb) return [];
        $stmt = $sdb->prepare("
            SELECT DISTINCT model FROM vehicles WHERE manufacturer = :mfr ORDER BY model
        ");
        $stmt->execute([':mfr' => $manufacturer]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get engine types for a manufacturer + model
     */
    public static function types(string $manufacturer, string $model): array {
        $sdb = self::sidecarDb();
        if (!$sdb) return [];
        $stmt = $sdb->prepare("
            SELECT ktype, type_name, hp, fuel_type, engine_codes
            FROM vehicles
            WHERE manufacturer = :mfr AND model = :mdl
            ORDER BY type_name
        ");
        $stmt->execute([':mfr' => $manufacturer, ':mdl' => $model]);
        return $stmt->fetchAll();
    }

    /**
     * Get parts (product IDs) compatible with a specific ktype
     */
    public static function partsByKtype(int $ktype, int $page = 1, int $perPage = 24, ?int $categoryId = null): array {
        $db = Db::get();
        $offset = ($page - 1) * $perPage;

        $where = "pv.ktype = :ktype AND p.status = 'active'";
        $params = [':ktype' => $ktype];

        if ($categoryId !== null) {
            $where .= " AND p.category_id = :cat";
            $params[':cat'] = $categoryId;
        }

        $stmt = $db->prepare("SELECT COUNT(DISTINCT p.id) FROM product_vehicles pv JOIN products p ON p.id = pv.product_id WHERE {$where}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;
        $stmt = $db->prepare("
            SELECT DISTINCT p.* FROM product_vehicles pv
            JOIN products p ON p.id = pv.product_id
            WHERE {$where}
            ORDER BY p.name
            LIMIT :limit OFFSET :offset
        ");
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
     * Get categories that have parts for a specific ktype
     */
    public static function categoriesForKtype(int $ktype): array {
        $db = Db::get();
        $stmt = $db->prepare("
            SELECT c.*, COUNT(DISTINCT pv.product_id) as part_count
            FROM product_vehicles pv
            JOIN products p ON p.id = pv.product_id
            JOIN categories c ON c.id = p.category_id
            WHERE pv.ktype = :ktype AND p.status = 'active'
            GROUP BY c.id
            ORDER BY c.path, c.sort_order
        ");
        $stmt->execute([':ktype' => $ktype]);
        return $stmt->fetchAll();
    }

    // ============================================================
    // OE Number Search
    // ============================================================

    /**
     * Search products by OE number
     */
    public static function searchByOE(string $oeNumber, int $page = 1, int $perPage = 24): array {
        $db = Db::get();
        $oeNumber = trim($oeNumber);
        if ($oeNumber === '') return ['products' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 0];

        // Normalize: strip spaces, dashes, dots, slashes, quotes
        $normalized = strtoupper(preg_replace('/[\s\-\.\/\']/', '', $oeNumber));
        $offset = ($page - 1) * $perPage;

        // Try prefix match first (uses index), fall back to contains
        // Prefix is the common case: user types "1K0615301" to find "1K0615301AD"
        $stmt = $db->prepare("SELECT COUNT(DISTINCT p.id)
            FROM product_oe_numbers oe
            JOIN products p ON p.id = oe.product_id
            WHERE oe.oem_number_norm LIKE :oe
              AND p.status = 'active'
        ");
        $stmt->execute([':oe' => $normalized . '%']);
        $prefixTotal = (int)$stmt->fetchColumn();

        if ($prefixTotal > 0) {
            // Prefix match found — use it (fast, indexed)
            $total = $prefixTotal;
            $stmt = $db->prepare("
                SELECT DISTINCT p.*, oe.oem_brand, oe.oem_number
                FROM product_oe_numbers oe
                JOIN products p ON p.id = oe.product_id
                WHERE oe.oem_number_norm LIKE :oe AND p.status = 'active'
                ORDER BY p.name LIMIT :limit OFFSET :offset
            ");
            $stmt->execute([':oe' => $normalized . '%', ':limit' => $perPage, ':offset' => $offset]);
        } else {
            // Fall back to contains (slower, full scan)
            $stmt = $db->prepare("SELECT COUNT(DISTINCT p.id)
                FROM product_oe_numbers oe
                JOIN products p ON p.id = oe.product_id
                WHERE oe.oem_number_norm LIKE :oe AND p.status = 'active'
            ");
            $stmt->execute([':oe' => '%' . $normalized . '%']);
            $total = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT DISTINCT p.*, oe.oem_brand, oe.oem_number
                FROM product_oe_numbers oe
                JOIN products p ON p.id = oe.product_id
                WHERE oe.oem_number_norm LIKE :oe AND p.status = 'active'
                ORDER BY p.name LIMIT :limit OFFSET :offset
            ");
            $stmt->execute([':oe' => '%' . $normalized . '%', ':limit' => $perPage, ':offset' => $offset]);
        }
        $products = $stmt->fetchAll();

        return [
            'products' => $products,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / $perPage),
            'query'    => $oeNumber,
        ];
    }

    /**
     * Get OE numbers for a product
     */
    public static function oeNumbers(int $productId): array {
        $stmt = Db::get()->prepare("
            SELECT oem_brand, oem_number FROM product_oe_numbers
            WHERE product_id = :pid ORDER BY oem_brand, oem_number
        ");
        $stmt->execute([':pid' => $productId]);
        return $stmt->fetchAll();
    }

    // ============================================================
    // Parametric Specification Search
    // ============================================================

    /**
     * Get specifications for a product in a given language
     */
    public static function specifications(int $productId, string $lang = 'sk'): array {
        $stmt = Db::get()->prepare("
            SELECT criteria_name, criteria_value, numeric_val
            FROM product_specifications
            WHERE product_id = :pid AND lang = :lang
            ORDER BY sort_order, criteria_name
        ");
        $stmt->execute([':pid' => $productId, ':lang' => $lang]);
        return $stmt->fetchAll();
    }

    /**
     * Get available specification criteria + values for a category (for filter UI)
     * Returns: ['Priemer [mm]' => [{value: '300', count: 15, numeric: 300.0}, ...], ...]
     *
     * This is the KEY feature: each part type (genart/category) has different
     * relevant criteria. We discover them dynamically from the data.
     */
    public static function specFilterValues(int|array $categoryId, string $lang = 'sk', array $activeFilters = [], ?int $priceMin = null, ?int $priceMax = null): array {
        $db = Db::get();

        if (is_array($categoryId)) {
            $catIds = array_map('intval', $categoryId);
            $catIn = implode(',', $catIds);
            $baseWhere = "p.category_id IN ({$catIn}) AND p.status = 'active'";
            $baseParams = [];
        } else {
            $baseWhere = "p.category_id = :cat AND p.status = 'active'";
            $baseParams = [':cat' => $categoryId];
        }

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
                foreach ($filterValues as $valueIndex => $filterValue) {
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
                SELECT ps.criteria_name, ps.criteria_value, ps.numeric_val,
                       COUNT(DISTINCT ps.product_id) AS cnt
                FROM product_specifications ps
                JOIN products p ON p.id = ps.product_id
                WHERE {$where} AND ps.lang = :facet_lang{$criterionWhere}
                GROUP BY ps.criteria_name, ps.criteria_value
                HAVING cnt > 0
                ORDER BY ps.criteria_name, ps.numeric_val IS NULL, ps.numeric_val, ps.criteria_value
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

        $skipCriteria = ['spárované čísla produktov', 'pre OE-číslo', 'pre číslo tovaru'];
        $filters = [];
        foreach ($rowsByCriterion as $criterion => $values) {
            if (in_array($criterion, $skipCriteria, true) || count($values) <= 1 || count($values) > 50) continue;
            $filters[$criterion] = array_map(static fn(array $value): array => [
                'value' => $value['criteria_value'],
                'count' => (int)$value['cnt'],
                'numeric' => $value['numeric_val'],
            ], $values);
        }
        return $filters;
    }

    /**
     * Search products within a category using parametric spec filters.
     * $specFilters = ['Strana montáže' => ['L', 'R'], 'Materiál' => ['bavlna']]
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
        $offset = ($page - 1) * $perPage;

        if (is_array($categoryId)) {
            $catIds = array_map('intval', $categoryId);
            $catIn = implode(',', $catIds);
            $where = "p.category_id IN ({$catIn}) AND p.status = 'active'";
            $params = [];
        } else {
            $where = "p.category_id = :cat AND p.status = 'active'";
            $params = [':cat' => $categoryId];
        }

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
            foreach ($values as $i => $v) {
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
        ];
    }

    /**
     * Get compatible vehicles for a product (from shop DB)
     */
    public static function vehiclesForProduct(int $productId, int $limit = 100): array {
        $stmt = Db::get()->prepare("
            SELECT manufacturer, model, type_name, ktype
            FROM product_vehicles
            WHERE product_id = :pid
            ORDER BY manufacturer, model, type_name
            LIMIT :limit
        ");
        $stmt->execute([':pid' => $productId, ':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Enrich product with TecDoc data (specs, OE numbers, vehicle count)
     */
    public static function enrichProduct(array $product, string $lang = 'sk'): array {
        $pid = (int)$product['id'];
        $product['specifications'] = self::specifications($pid, $lang);
        $product['oe_numbers'] = self::oeNumbers($pid);

        $stmt = Db::get()->prepare("SELECT COUNT(*) FROM product_vehicles WHERE product_id = :pid");
        $stmt->execute([':pid' => $pid]);
        $product['vehicle_count'] = (int)$stmt->fetchColumn();

        return $product;
    }
}
