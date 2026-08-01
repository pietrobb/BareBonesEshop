<?php
/**
 * Persist the canonical checkout financial snapshot on orders and invoices.
 * Existing rows remain NULL and continue through the legacy read-only path.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $this->addColumnIfMissing($db, $isSqlite, 'orders', 'financial_snapshot', 'TEXT');
        $this->addColumnIfMissing($db, $isSqlite, 'invoices', 'financial_snapshot', 'TEXT');
    }

    private function addColumnIfMissing(PDO $db, bool $isSqlite, string $table, string $column, string $definition): void {
        $exists = false;
        if ($isSqlite) {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) { $exists = true; break; }
            }
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            $exists = (int)$stmt->fetchColumn() > 0;
        }
        if (!$exists) $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
};
