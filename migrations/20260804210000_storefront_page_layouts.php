<?php
/** Per-page theme layout selection for configurable storefront pages. */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        if (!$this->tableExists($db, $isSqlite, 'pages') || $this->columnExists($db, $isSqlite, 'pages', 'layout')) return;
        $definition = $isSqlite ? "TEXT NOT NULL DEFAULT 'storefront'" : "VARCHAR(80) NOT NULL DEFAULT 'storefront'";
        $db->exec("ALTER TABLE pages ADD COLUMN layout {$definition}");
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
