<?php
/**
 * Migration: add_stock_restored_column
 * Generated: 2026-06-11T21:30:00+00:00
 *
 * Bug #11 (audit 2026-06-11): the double-restore guard in
 * Orders::restoreStock relied on a `notes LIKE '%stock_restored%'`
 * substring heuristic — fragile (any customer/admin note containing the
 * literal text would block a legitimate restore) and not race-safe.
 *
 * Adds a dedicated orders.stock_restored flag (0/1) and backfills it
 * from the legacy notes marker.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $this->addColumnIfMissing($db, 'orders', 'stock_restored', "INTEGER NOT NULL DEFAULT 0");

        // Backfill orders previously marked via the notes substring
        $db->exec("UPDATE orders SET stock_restored = 1 WHERE notes LIKE '%stock_restored%'");
    }

    /** Idempotent ALTER TABLE — PRAGMA path for SQLite, INFORMATION_SCHEMA for MySQL. */
    private function addColumnIfMissing(PDO $db, string $table, string $col, string $def): void {
        $exists = false;
        try {
            $rows = $db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (($r['name'] ?? '') === $col) { $exists = true; break; }
            }
        } catch (\Throwable $e) {
            // MySQL fallback
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME=? AND COLUMN_NAME=?");
            $stmt->execute([$table, $col]);
            $exists = ((int)$stmt->fetchColumn()) > 0;
        }
        if (!$exists) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
    }
};
