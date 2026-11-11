<?php
/**
 * R29: a saved "Buy later" line keeps the SKU of its variant (variant_sku). SQLite reuses the id of the last deleted
 * variant for the next one, so a line of a deleted variant was mapped onto the new variant of that id; now a line whose
 * variant id names another SKU is dropped as gone (SavedForLater::catalogCheck()). Lines saved before keep NULL.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(saved_for_later_items)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saved_for_later_items'")->fetchAll(PDO::FETCH_COLUMN);
        $short = MigrationSql::short($isSqlite);
        if (!in_array('variant_sku', $columns, true)) $db->exec("ALTER TABLE saved_for_later_items ADD COLUMN variant_sku {$short} DEFAULT NULL");
    }
};
