<?php
/** Low / critical stock alerts: per-product threshold overrides, per SKU+store alert state, daily digest claims. */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        if ($this->tableExists($db, $isSqlite, 'products')) {
            foreach (['stock_low_threshold', 'stock_critical_threshold'] as $column) {
                if (!$this->columnExists($db, $isSqlite, 'products', $column)) {
                    $db->exec("ALTER TABLE products ADD COLUMN {$column} INTEGER DEFAULT NULL");
                }
            }
        }

        $db->exec($isSqlite
            ? "CREATE TABLE IF NOT EXISTS stock_alert_state (
                store_id TEXT NOT NULL DEFAULT 'default',
                sku TEXT NOT NULL,
                level TEXT NOT NULL DEFAULT 'ok',
                available INTEGER NOT NULL DEFAULT 0,
                alerted_at TEXT,
                updated_at TEXT DEFAULT (datetime('now')),
                PRIMARY KEY (store_id, sku)
            )"
            : "CREATE TABLE IF NOT EXISTS stock_alert_state (
                store_id VARCHAR(64) NOT NULL DEFAULT 'default',
                sku VARCHAR(191) NOT NULL,
                level VARCHAR(16) NOT NULL DEFAULT 'ok',
                available INT NOT NULL DEFAULT 0,
                alerted_at DATETIME NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (store_id, sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec($isSqlite
            ? "CREATE TABLE IF NOT EXISTS stock_alert_digests (
                store_id TEXT NOT NULL,
                digest_date TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now')),
                PRIMARY KEY (store_id, digest_date)
            )"
            : "CREATE TABLE IF NOT EXISTS stock_alert_digests (
                store_id VARCHAR(64) NOT NULL,
                digest_date DATE NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (store_id, digest_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
};
