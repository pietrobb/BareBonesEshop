<?php
/**
 * BareBones Shop — Search Adapter Interface
 * Default: FtsSearch (SQLite FTS5 / MySQL FULLTEXT)
 * Optional: MeilisearchAdapter, TypesenseAdapter, AlgoliaAdapter
 */
interface SearchAdapter {
    /** Add or update a product in the search index */
    public function index(array $product): void;

    /** Remove a product from the search index */
    public function remove(int $productId): void;

    /** Full-text search with pagination and filters */
    public function search(string $query, array $opts = []): array;

    /** Autocomplete suggestions (prefix search) */
    public function suggest(string $prefix, int $limit = 5): array;

    /** Full index rebuild */
    public function reindexAll(): void;
}
