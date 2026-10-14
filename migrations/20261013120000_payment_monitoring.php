<?php
/** Payment funnel monitoring (P0 2026-10-13): synthetic smoke orders and browser CSP violation reports. */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        if ($this->tableExists($db, $isSqlite, 'orders') && !$this->columnExists($db, $isSqlite, 'orders', 'is_synthetic')) {
            $db->exec('ALTER TABLE orders ADD COLUMN is_synthetic INTEGER NOT NULL DEFAULT 0');
        }

        $db->exec($isSqlite
            ? "CREATE TABLE IF NOT EXISTS csp_violations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_id TEXT NOT NULL DEFAULT 'default',
                directive TEXT NOT NULL,
                blocked TEXT NOT NULL DEFAULT '',
                document_path TEXT NOT NULL DEFAULT '',
                disposition TEXT NOT NULL DEFAULT 'enforce',
                created_at TEXT NOT NULL
            )"
            : "CREATE TABLE IF NOT EXISTS csp_violations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                store_id VARCHAR(64) NOT NULL DEFAULT 'default',
                directive VARCHAR(64) NOT NULL,
                blocked VARCHAR(255) NOT NULL DEFAULT '',
                document_path VARCHAR(255) NOT NULL DEFAULT '',
                disposition VARCHAR(16) NOT NULL DEFAULT 'enforce',
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!$this->indexExists($db, $isSqlite, 'csp_violations', 'idx_csp_violations_store_time')) {
            $db->exec('CREATE INDEX idx_csp_violations_store_time ON csp_violations (store_id, created_at)');
        }
    }

    private function tableExists(PDO $db, bool $isSqlite, string $table): bool {
        $stmt = $isSqlite
            ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?")
            : $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function columnExists(PDO $db, bool $isSqlite, string $table, string $column): bool {
        if ($isSqlite) {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) return true;
            }
            return false;
        }
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }

    private function indexExists(PDO $db, bool $isSqlite, string $table, string $index): bool {
        $stmt = $isSqlite
            ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?")
            : $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute($isSqlite ? [$index] : [$table, $index]);
        return (bool)$stmt->fetchColumn();
    }
};
