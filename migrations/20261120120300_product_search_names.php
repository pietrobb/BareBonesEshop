<?php

// R50 (review round 20, medium): the search read only products.name/summary — a Czech store whose products carry
// their Czech names in products.translations never found them. products.search_names holds those translated names
// and summaries as plain text (Search::refreshNames()) and is part of the full-text index on both databases.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns === []) return;
        if ($isSqlite) {
            // FTS5 cannot add a column: the index is rebuilt with search_names after the column is filled.
            foreach (['products_ai', 'products_ad', 'products_au'] as $trigger) $db->exec("DROP TRIGGER IF EXISTS {$trigger}");
            $db->exec('DROP TABLE IF EXISTS products_fts');
        }
        if (!in_array('search_names', $columns, true)) $db->exec('ALTER TABLE products ADD COLUMN search_names ' . MigrationSql::text($isSqlite));
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
        if (method_exists('Search', 'refreshNames')) Search::refreshNames([], $db);
        if ($isSqlite) {
            $cols = 'name, sku, summary, tags, filter_tags, search_names';
            $new = 'new.name, new.sku, new.summary, new.tags, new.filter_tags, new.search_names';
            $old = 'old.name, old.sku, old.summary, old.tags, old.filter_tags, old.search_names';
            $db->exec("CREATE VIRTUAL TABLE products_fts USING fts5({$cols}, content=products, content_rowid=id, tokenize='unicode61 remove_diacritics 2')");
            $db->exec("CREATE TRIGGER products_ai AFTER INSERT ON products BEGIN INSERT INTO products_fts(rowid, {$cols}) VALUES (new.id, {$new}); END");
            $db->exec("CREATE TRIGGER products_ad AFTER DELETE ON products BEGIN INSERT INTO products_fts(products_fts, rowid, {$cols}) VALUES ('delete', old.id, {$old}); END");
            $db->exec("CREATE TRIGGER products_au AFTER UPDATE ON products BEGIN
                INSERT INTO products_fts(products_fts, rowid, {$cols}) VALUES ('delete', old.id, {$old});
                INSERT INTO products_fts(rowid, {$cols}) VALUES (new.id, {$new});
            END");
            $db->exec("INSERT INTO products_fts(products_fts) VALUES('rebuild')");
            return;
        }
        $exists = $db->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_ft_products_names'")->fetchColumn();
        if (!$exists) $db->exec('ALTER TABLE products ADD FULLTEXT idx_ft_products_names (name, sku, summary, tags, search_names)');
    }
};
