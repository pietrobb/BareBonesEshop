<?php
/**
 * FtsSearch — SearchAdapter wrapping built-in FTS5/FULLTEXT Search class.
 * Enables swap to Meilisearch etc. via Adapters::search().
 */
class FtsSearch {
    public function __construct(array $cfg) {}

    public function search(string $query, array $opts = []): array {
        $page    = $opts['page'] ?? 1;
        $perPage = $opts['per_page'] ?? 24;
        return Search::query($query, $page, $perPage, array_intersect_key($opts, array_flip(['sort', 'price_min', 'price_max', 'category_ids'])));
    }

    public function suggest(string $prefix, int $limit = 5): array {
        return Search::suggest($prefix, $limit);
    }

    public function index(array $product): void {
        // FTS5 triggers auto-update on product INSERT/UPDATE
    }

    public function remove(int $productId): void {
        // FTS5 triggers auto-remove on product DELETE
    }

    public function reindexAll(): void {
        if (Db::isSqlite()) {
            Db::get()->exec("INSERT INTO products_fts(products_fts) VALUES('rebuild')");
        } else {
            Db::get()->exec("OPTIMIZE TABLE products");
        }
    }
}
