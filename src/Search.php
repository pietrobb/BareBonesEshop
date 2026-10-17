<?php
/**
 * BareBones Shop — Full-Text Search
 * Abstraction over SQLite FTS5 and MySQL FULLTEXT.
 */
class Search {
    /** Largest whole-currency price filter: keeps the *100 cents conversion far from integer overflow. */
    public const MAX_PRICE_FILTER = 100000000;
    /**
     * Search products
     */
    public const SORTS = ['relevance', 'price_asc', 'price_desc', 'name_asc', 'name_desc', 'newest'];

    /** Search-page sort and filters from a query string: whole-currency price inputs become cents, unknown sorts fall back to relevance. */
    public static function filtersFromQuery(array $get): array {
        $sort = in_array($get['sort'] ?? '', self::SORTS, true) ? (string)$get['sort'] : 'relevance';
        $number = static fn(string $key): ?int => isset($get[$key]) && is_scalar($get[$key]) && trim((string)$get[$key]) !== '' ? max(0, min(self::MAX_PRICE_FILTER, Security::int($get[$key]))) : null;
        $pmin = $number('pmin');
        $pmax = $number('pmax');
        $cat = (int)($number('cat') ?? 0);
        return [
            'sort' => $sort, 'sorts' => self::SORTS, 'pmin' => $pmin, 'pmax' => $pmax, 'cat' => $cat,
            'opts' => ['sort' => $sort, 'price_min' => $pmin === null ? null : $pmin * 100, 'price_max' => $pmax === null ? null : $pmax * 100, 'category_ids' => $cat > 0 ? [$cat] : []],
        ];
    }

    /**
     * Search products.
     * $opts: sort (one of SORTS, default relevance), price_min/price_max (cents), category_ids (int[]; empty = all).
     * The result carries a 'categories' facet (id, name, slug, count) of the matches before the category filter.
     */
    public static function query(string $q, int $page = 1, int $perPage = 24, array $opts = []): array {
        $q = trim($q);
        if ($q === '') return ['products' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 0, 'categories' => []];
        $page = max(1, min(self::MAX_PAGE, $page));
        try {
            return self::runQuery($q, $page, $perPage, $opts, false);
        } catch (PDOException $e) {
            // A query the full-text engine still refuses must not become a 500: degrade to a LIKE scan.
            error_log('[Search] full-text query failed, using LIKE fallback: ' . $e->getMessage());
            return self::runQuery($q, $page, $perPage, $opts, true);
        }
    }

    /** Highest result page a request may ask for; larger values only produce huge OFFSETs (or int overflow). */
    public const MAX_PAGE = 10000;

    private static function runQuery(string $q, int $page, int $perPage, array $opts, bool $like): array {
        $db = Db::get();
        $offset = ($page - 1) * $perPage;
        $sort = in_array($opts['sort'] ?? '', self::SORTS, true) ? (string)$opts['sort'] : 'relevance';
        $params = [':store' => StoreContext::current()->catalogId()];
        if ($like) {
            $from = 'FROM products p';
            $where = "p.store_id = :store AND p.status = 'active'";
            foreach (array_slice(preg_split('/\s+/u', $q) ?: [], 0, 8) as $i => $word) {
                $pattern = '%' . Db::likeLiteral($word) . '%';
                $alternatives = [];
                foreach (['name', 'sku', 'summary', 'tags'] as $j => $column) {
                    $alternatives[] = "p.{$column} LIKE :like{$i}_{$j} ESCAPE '!'";
                    $params[":like{$i}_{$j}"] = $pattern;
                }
                $where .= ' AND (' . implode(' OR ', $alternatives) . ')';
            }
            $select = 'p.*';
            $relevance = 'p.name ASC, p.id ASC';
        } elseif (Db::isSqlite()) {
            // CROSS JOIN pins the FTS table as the outer loop: with a plain JOIN the planner may scan
            // every product and run one FTS lookup per row (tens of seconds at 50k products for "?q=a").
            $from = "FROM products_fts fts CROSS JOIN products p ON p.id = fts.rowid";
            $where = "products_fts MATCH :q AND p.store_id = :store AND p.status = 'active'";
            $params[':q'] = self::sanitizeFtsQuery($q);
            $select = 'p.*, rank';
            $relevance = 'rank';
        } else {
            $from = 'FROM products p';
            $where = "MATCH(p.name, p.sku, p.summary, p.tags) AGAINST(:q IN NATURAL LANGUAGE MODE) AND p.store_id = :store AND p.status = 'active'";
            $params[':q'] = self::sanitizeMysqlQuery($q);
            $select = 'p.*, MATCH(p.name, p.sku, p.summary, p.tags) AGAINST(:q_rel) AS relevance';
            $relevance = 'relevance DESC';
        }
        if (isset($opts['price_min']) && $opts['price_min'] !== null) { $where .= ' AND p.price >= :pmin'; $params[':pmin'] = (int)$opts['price_min']; }
        if (isset($opts['price_max']) && $opts['price_max'] !== null) { $where .= ' AND p.price <= :pmax'; $params[':pmax'] = (int)$opts['price_max']; }

        $facet = $db->prepare("SELECT c.id, c.name, c.slug, COUNT(*) AS cnt {$from} JOIN categories c ON c.id = p.category_id WHERE {$where} GROUP BY c.id, c.name, c.slug ORDER BY cnt DESC, c.name");
        $facet->execute($params);
        $categories = array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'slug' => (string)$row['slug'], 'count' => (int)$row['cnt']], $facet->fetchAll());

        $categoryIds = array_values(array_unique(array_filter(array_map('intval', (array)($opts['category_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
        if ($categoryIds) {
            $marks = [];
            foreach ($categoryIds as $i => $id) { $marks[] = ':cat' . $i; $params[':cat' . $i] = $id; }
            $where .= ' AND p.category_id IN (' . implode(', ', $marks) . ')';
        }

        $stmt = $db->prepare("SELECT COUNT(*) {$from} WHERE {$where}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $orderBy = match ($sort) {
            'price_asc' => 'p.price ASC, p.id ASC',
            'price_desc' => 'p.price DESC, p.id ASC',
            'name_asc' => 'p.name ASC, p.id ASC',
            'name_desc' => 'p.name DESC, p.id ASC',
            'newest' => 'p.created_at DESC, p.id DESC',
            default => $relevance,
        };
        $stmt = $db->prepare("SELECT {$select} {$from} WHERE {$where} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset");
        $fetch = $params + [':limit' => $perPage, ':offset' => $offset];
        if (!$like && !Db::isSqlite()) $fetch[':q_rel'] = $params[':q'];
        foreach ($fetch as $name => $value) $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->execute();
        $products = $stmt->fetchAll();

        return [
            'products'   => $products,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'pages'      => (int)ceil($total / $perPage),
            'query'      => $q,
            'categories' => $categories,
        ];
    }

    /**
     * Autocomplete suggestions (prefix search)
     */
    public static function suggest(string $prefix, int $limit = 5): array {
        $db = Db::get();
        $prefix = trim($prefix);
        if (strlen($prefix) < 2) return [];

        if (Db::isSqlite()) {
            $ftsQuery = '"' . self::escFts($prefix) . '" *';
            $stmt = $db->prepare("
                SELECT DISTINCT p.name, p.slug
                FROM products_fts fts
                CROSS JOIN products p ON p.id = fts.rowid
                WHERE products_fts MATCH :q AND p.store_id = :store AND p.status = 'active'
                ORDER BY rank
                LIMIT :limit
            ");
            $stmt->execute([':q' => $ftsQuery, ':store' => StoreContext::current()->catalogId(), ':limit' => $limit]);
        } else {
            $safePrefix = self::sanitizeMysqlQuery($prefix);
            $stmt = $db->prepare("
                SELECT DISTINCT p.name, p.slug
                FROM products p
                WHERE MATCH(p.name, p.sku, p.summary, p.tags) AGAINST(:q IN BOOLEAN MODE)
                  AND p.store_id = :store AND p.status = 'active'
                LIMIT :limit
            ");
            $stmt->execute([':q' => $safePrefix . '*', ':store' => StoreContext::current()->catalogId(), ':limit' => $limit]);
        }

        return $stmt->fetchAll();
    }

    /**
     * Search with highlight snippets (SQLite only)
     */
    public static function withHighlights(string $q, int $page = 1, int $perPage = 24): array {
        if (!Db::isSqlite()) {
            return self::query($q, $page, $perPage);
        }

        $db = Db::get();
        $ftsQuery = self::sanitizeFtsQuery($q);
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT p.*,
                highlight(products_fts, 0, '<mark>', '</mark>') AS name_hl,
                rank
            FROM products_fts fts
            CROSS JOIN products p ON p.id = fts.rowid
            WHERE products_fts MATCH :q AND p.store_id = :store AND p.status = 'active'
            ORDER BY rank
            LIMIT :limit OFFSET :offset
        ");
        $stmt->execute([':q' => $ftsQuery, ':store' => StoreContext::current()->catalogId(), ':limit' => $perPage, ':offset' => $offset]);

        $results = $stmt->fetchAll();
        // Sanitize name_hl: escape any HTML except <mark> tags from FTS5 highlight()
        foreach ($results as &$r) {
            if (isset($r['name_hl'])) {
                $r['name_hl'] = strip_tags($r['name_hl'], '<mark>');
            }
        }
        return $results;
    }

    /**
     * Sanitize FTS5 query — protect against FTS injection
     */
    private static function sanitizeFtsQuery(string $q): string {
        $q = preg_replace('/["\*\(\)\{\}\[\]:^~]/', ' ', $q);
        $q = preg_replace('/\b(AND|OR|NOT|NEAR)\b/i', '', $q);
        // Every token is a quoted FTS5 string: a raw "t-shirt", "3.5", "o'neil" or "a,b" is FTS5 syntax
        // (column filter, bareword error) and used to fail the whole search with an SQL error.
        // Tokens without a letter or digit ("%", "/") would be empty phrases that match nothing.
        $words = array_values(array_filter(preg_split('/\s+/u', trim($q)) ?: [], static fn(string $w): bool => preg_match('/[\p{L}\p{N}]/u', $w) === 1));
        if ($words === []) return '""';
        $quoted = array_map(static fn(string $w): string => '"' . str_replace('"', '""', $w) . '"', $words);
        // Prefix match for last word (autocomplete effect)
        $quoted[count($quoted) - 1] .= ' *';
        return implode(' ', $quoted);
    }

    /** Escape a single term for FTS */
    private static function escFts(string $s): string {
        return preg_replace('/["\*\(\)\{\}\[\]:^~]/', '', $s);
    }

    /**
     * Sanitize MySQL FULLTEXT query — strip boolean operators
     */
    private static function sanitizeMysqlQuery(string $q): string {
        // Remove MySQL boolean mode operators: + - > < ( ) ~ * " @
        $q = preg_replace('/[+\-><()\~\*"@]/', ' ', $q);
        return trim(preg_replace('/\s+/', ' ', $q));
    }
}
