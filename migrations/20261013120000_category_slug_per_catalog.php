<?php
/**
 * A12.4: category slugs are unique among siblings of one catalog, not across all stores — a second catalog may have
 * its own root "shoes". The old index (slug, parent) predates categories.store_id; the new one is strictly weaker,
 * so existing rows always satisfy it.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        if ($isSqlite) {
            $db->exec('DROP INDEX IF EXISTS idx_cat_slug_parent');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_cat_store_slug_parent ON categories(store_id, slug, COALESCE(parent_id, 0))');
            return;
        }
        $columns = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('parent_id_uniq', $columns, true)) {
            $db->exec('ALTER TABLE categories ADD COLUMN parent_id_uniq INT GENERATED ALWAYS AS (COALESCE(parent_id, 0)) STORED');
        }
        $indexes = $db->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('idx_cat_store_slug_parent', $indexes, true)) {
            $db->exec('CREATE UNIQUE INDEX idx_cat_store_slug_parent ON categories(store_id, slug(191), parent_id_uniq)');
        }
        if (in_array('idx_cat_slug_parent', $indexes, true)) $db->exec('DROP INDEX idx_cat_slug_parent ON categories');
    }
};
