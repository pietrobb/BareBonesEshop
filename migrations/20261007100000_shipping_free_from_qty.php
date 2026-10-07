<?php
/** Per-method free shipping from N physical items (digital items never count). */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        if (!$this->tableExists($db, $isSqlite, 'shipping_methods') || $this->columnExists($db, $isSqlite, 'shipping_methods', 'free_from_qty')) return;
        $db->exec('ALTER TABLE shipping_methods ADD COLUMN free_from_qty INTEGER DEFAULT NULL');
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
