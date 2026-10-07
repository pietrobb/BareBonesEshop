<?php
/** Abandoned-cart recovery: value/locale snapshot, conversion link, recovery token and e-mail opt-out list. */
return new class {
    private const COLUMNS = [
        'store_id'       => ['TEXT', "VARCHAR(64)"],
        'locale'         => ['TEXT', "VARCHAR(16)"],
        'currency'       => ['TEXT', "VARCHAR(8)"],
        'cart_value'     => ['INTEGER NOT NULL DEFAULT 0', 'INT NOT NULL DEFAULT 0'],
        'item_count'     => ['INTEGER NOT NULL DEFAULT 0', 'INT NOT NULL DEFAULT 0'],
        'updated_at'     => ['TEXT', 'DATETIME NULL'],
        'recovery_token' => ['TEXT', 'VARCHAR(64) NULL'],
        'order_id'       => ['INTEGER', 'INT NULL'],
        'converted_at'   => ['TEXT', 'DATETIME NULL'],
        'reminder_status' => ['TEXT', 'VARCHAR(16) NULL'],
    ];

    public function up(PDO $db, bool $isSqlite): void {
        if ($this->tableExists($db, $isSqlite, 'abandoned_carts')) {
            foreach (self::COLUMNS as $column => [$sqliteDef, $mysqlDef]) {
                if (!$this->columnExists($db, $isSqlite, 'abandoned_carts', $column)) {
                    $db->exec("ALTER TABLE abandoned_carts ADD COLUMN {$column} " . ($isSqlite ? $sqliteDef : $mysqlDef));
                }
            }
            $this->index($db, $isSqlite, 'abandoned_carts', 'idx_ac_token', 'recovery_token', true);
            $this->index($db, $isSqlite, 'abandoned_carts', 'idx_ac_due', 'reminded_at, order_id, created_at', false);
        }

        $db->exec($isSqlite
            ? "CREATE TABLE IF NOT EXISTS email_suppressions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                scope TEXT NOT NULL DEFAULT 'marketing',
                source TEXT,
                created_at TEXT DEFAULT (datetime('now'))
            )"
            : "CREATE TABLE IF NOT EXISTS email_suppressions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(191) NOT NULL UNIQUE,
                scope VARCHAR(32) NOT NULL DEFAULT 'marketing',
                source VARCHAR(64) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function index(PDO $db, bool $isSqlite, string $table, string $name, string $columns, bool $unique): void {
        $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
        if ($isSqlite) {
            $db->exec("CREATE {$kind} IF NOT EXISTS {$name} ON {$table} ({$columns})");
            return;
        }
        $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute([$table, $name]);
        if (!$stmt->fetchColumn()) $db->exec("CREATE {$kind} {$name} ON {$table} ({$columns})");
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
