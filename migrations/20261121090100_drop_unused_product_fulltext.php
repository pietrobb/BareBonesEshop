<?php

// R51 (review round 51, low): since 20261120120300_product_search_names the MySQL search matches
// idx_ft_products_names (name, sku, summary, tags, search_names); the older idx_ft_products (name, sku, summary, tags)
// is read by no query and only slowed every product write. SQLite keeps a single FTS5 table.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        if ($isSqlite) return;
        $indexes = $db->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('idx_ft_products_names', $indexes, true) && in_array('idx_ft_products', $indexes, true)) {
            $db->exec('ALTER TABLE products DROP INDEX idx_ft_products');
        }
    }
};
