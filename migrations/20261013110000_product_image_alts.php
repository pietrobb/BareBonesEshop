<?php
/**
 * A12.2: alt texts of product images edited in the admin product editor, as a JSON map image URL => alt.
 * NULL = every image falls back to the product name; per-locale maps live in products.translations[locale].image_alts.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('image_alts', $columns, true)) $db->exec('ALTER TABLE products ADD COLUMN image_alts TEXT DEFAULT NULL');
    }
};
