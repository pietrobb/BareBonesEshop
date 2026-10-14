<?php
/**
 * A12.1: product SEO title and meta description edited in the admin product editor.
 * Empty values keep the storefront fallback (product name / summary); per-locale values live in products.translations.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['seo_title' => $isSqlite ? 'TEXT DEFAULT NULL' : 'VARCHAR(255) DEFAULT NULL', 'meta_description' => 'TEXT DEFAULT NULL'] as $column => $definition) {
            if (!in_array($column, $columns, true)) $db->exec("ALTER TABLE products ADD COLUMN {$column} {$definition}");
        }
    }
};
