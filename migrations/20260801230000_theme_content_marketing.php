<?php
/** Store-scoped content operations for themes, merchandising, and redirects. */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $short = $isSqlite ? 'TEXT' : 'VARCHAR(191)';
        $datetime = $isSqlite ? 'TEXT' : 'DATETIME';
        $created = $isSqlite ? "TEXT NOT NULL DEFAULT (datetime('now'))" : 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $defaultEmpty = MigrationSql::defaultClause($isSqlite, '');

        $db->exec("CREATE TABLE IF NOT EXISTS content_menu_items (
            id INTEGER PRIMARY KEY{$autoinc}, store_id {$short} NOT NULL DEFAULT 'default',
            menu_code {$short} NOT NULL DEFAULT 'primary', locale {$short} NOT NULL DEFAULT '*',
            label {$short} NOT NULL, url TEXT NOT NULL, parent_id INTEGER DEFAULT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1,
            created_at {$created}, updated_at {$created}
        )");
        $this->index($db, $isSqlite, 'content_menu_items', 'idx_content_menu_store', 'store_id, menu_code, locale, active, sort_order');

        $db->exec("CREATE TABLE IF NOT EXISTS content_banners (
            id INTEGER PRIMARY KEY{$autoinc}, store_id {$short} NOT NULL DEFAULT 'default',
            slot {$short} NOT NULL DEFAULT 'homepage', locale {$short} NOT NULL DEFAULT '*',
            title {$short} NOT NULL, body TEXT NOT NULL {$defaultEmpty}, image_url TEXT NOT NULL {$defaultEmpty},
            link_url TEXT NOT NULL {$defaultEmpty}, link_label {$short} NOT NULL DEFAULT '',
            starts_at {$datetime} DEFAULT NULL, ends_at {$datetime} DEFAULT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1,
            created_at {$created}, updated_at {$created}
        )");
        $this->index($db, $isSqlite, 'content_banners', 'idx_content_banner_store', 'store_id, slot, locale, active, starts_at, ends_at');

        $db->exec("CREATE TABLE IF NOT EXISTS content_redirects (
            id INTEGER PRIMARY KEY{$autoinc}, store_id {$short} NOT NULL DEFAULT 'default',
            source_path {$short} NOT NULL, target_url TEXT NOT NULL, http_status INTEGER NOT NULL DEFAULT 301,
            active INTEGER NOT NULL DEFAULT 1, created_at {$created}, updated_at {$created},
            UNIQUE(store_id, source_path)
        )");
        $this->index($db, $isSqlite, 'content_redirects', 'idx_content_redirect_store', 'store_id, active, source_path');

        foreach ([
            ['page_type', "{$short} NOT NULL DEFAULT 'content'"],
            ['template', "{$short} NOT NULL DEFAULT 'cms'"],
            ['seo_title', "TEXT NOT NULL {$defaultEmpty}"],
            ['meta_description', "TEXT NOT NULL {$defaultEmpty}"],
        ] as [$column, $definition]) {
            $this->addColumnIfMissing($db, $isSqlite, 'pages', $column, $definition);
        }
    }

    private function tableExists(PDO $db, bool $isSqlite, string $table): bool {
        $stmt = $isSqlite
            ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?")
            : $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function addColumnIfMissing(PDO $db, bool $isSqlite, string $table, string $column, string $definition): void {
        if (!$this->tableExists($db, $isSqlite, $table)) return;
        if ($isSqlite) {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) return;
            }
        } else {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $stmt->execute([$table, $column]);
            if ($stmt->fetchColumn()) return;
        }
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private function index(PDO $db, bool $isSqlite, string $table, string $name, string $columns): void {
        if (!$this->tableExists($db, $isSqlite, $table)) return;
        $stmt = $isSqlite
            ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?")
            : $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute($isSqlite ? [$name] : [$table, $name]);
        if (!$stmt->fetchColumn()) $db->exec("CREATE INDEX {$name} ON {$table}({$columns})");
    }
};
