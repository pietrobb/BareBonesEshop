<?php
/**
 * BareBones Shop — Full-Text Search
 * Abstraction over SQLite FTS5 and MySQL FULLTEXT.
 */
class Search {
    /**
     * Search products
     */
    public static function query(string $q, int $page = 1, int $perPage = 24): array {
        $db = Db::get();
        $q = trim($q);
        if ($q === '') return ['products' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 0];

        $offset = ($page - 1) * $perPage;

        if (Db::isSqlite()) {
            $ftsQuery = self::sanitizeFtsQuery($q);

            // Count
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM products_fts fts
                CROSS JOIN products p ON p.id = fts.rowid
                WHERE products_fts MATCH :q AND p.store_id = :store AND p.status = 'active'
            ");
            $stmt->execute([':q' => $ftsQuery, ':store' => StoreContext::current()->catalogId()]);
            $total = (int)$stmt->fetchColumn();

            // Fetch
            $stmt = $db->prepare("
                SELECT p.*, rank
                FROM products_fts fts
                JOIN products p ON p.id = fts.rowid
                WHERE products_fts MATCH :q AND p.store_id = :store AND p.status = 'active'
                ORDER BY rank
                LIMIT :limit OFFSET :offset
            ");
            $stmt->execute([':q' => $ftsQuery, ':store' => StoreContext::current()->catalogId(), ':limit' => $perPage, ':offset' => $offset]);
        } else {
            $safeQ = self::sanitizeMysqlQuery($q);

            // Count
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM products p
                WHERE MATCH(p.name, p.sku, p.summary, p.tags) AGAINST(:q IN NATURAL LANGUAGE MODE)
                  AND p.store_id = :store AND p.status = 'active'
            ");
            $stmt->execute([':q' => $safeQ, ':store' => StoreContext::current()->catalogId()]);
            $total = (int)$stmt->fetchColumn();

            // Fetch
            $stmt = $db->prepare("
                SELECT p.*, MATCH(p.name, p.sku, p.summary, p.tags) AGAINST(:q) AS relevance
                FROM products p
                WHERE MATCH(p.name, p.sku, p.summary, p.tags) AGAINST(:q2 IN NATURAL LANGUAGE MODE)
                  AND p.store_id = :store AND p.status = 'active'
                ORDER BY relevance DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->execute([':q' => $safeQ, ':q2' => $safeQ, ':store' => StoreContext::current()->catalogId(), ':limit' => $perPage, ':offset' => $offset]);
        }

        $products = $stmt->fetchAll();

        return [
            'products' => $products,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / $perPage),
            'query'    => $q,
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
                JOIN products p ON p.id = fts.rowid
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
            JOIN products p ON p.id = fts.rowid
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
        $q = trim(preg_replace('/\s+/', ' ', $q));
        if ($q === '') return '""';

        // Prefix match for last word (autocomplete effect)
        $words = explode(' ', $q);
        $last = array_pop($words);
        $words[] = '"' . $last . '" *';
        return implode(' ', $words);
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
