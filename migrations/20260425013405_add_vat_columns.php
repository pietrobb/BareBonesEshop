<?php
/**
 * Migration: add_vat_columns
 * Generated: 2026-04-25T01:34:05+00:00
 *
 * Adds VAT/tax compliance columns:
 *   - products.vat_class                                        (standard|reduced|reduced2|zero)
 *   - order_items.vat_class, vat_rate, vat_amount, net_amount
 *   - orders.vat_scenario, vat_total, net_total
 *   - customers.vat_id, vat_validated_at, vat_country
 *   - invoices.type, refers_to_invoice_id, vat_scenario, reverse_charge_note_key
 *   - invoice_counters: extend with type ('invoice'|'credit_note') for separate sequences
 *   - vat_id_cache (NEW table) — VIES validation cache
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        // ── products: vat_class ────────────────────────────────────────────
        $this->addColumnIfMissing($db, 'products', 'vat_class', "TEXT NOT NULL DEFAULT 'standard'");

        // ── order_items: per-line VAT snapshot ─────────────────────────────
        $this->addColumnIfMissing($db, 'order_items', 'vat_class',  "TEXT NOT NULL DEFAULT 'standard'");
        $this->addColumnIfMissing($db, 'order_items', 'vat_rate',   "REAL NOT NULL DEFAULT 0");
        $this->addColumnIfMissing($db, 'order_items', 'vat_amount', "INTEGER NOT NULL DEFAULT 0");
        $this->addColumnIfMissing($db, 'order_items', 'net_amount', "INTEGER NOT NULL DEFAULT 0");

        // ── orders: aggregated VAT for fast reads ──────────────────────────
        $this->addColumnIfMissing($db, 'orders', 'vat_scenario', "TEXT");
        $this->addColumnIfMissing($db, 'orders', 'vat_total',    "INTEGER NOT NULL DEFAULT 0");
        $this->addColumnIfMissing($db, 'orders', 'net_total',    "INTEGER NOT NULL DEFAULT 0");

        // ── customers: VAT ID for B2B reverse-charge ───────────────────────
        $this->addColumnIfMissing($db, 'customers', 'vat_id',           "TEXT");
        $this->addColumnIfMissing($db, 'customers', 'vat_country',      "TEXT");
        $this->addColumnIfMissing($db, 'customers', 'vat_validated_at', "TEXT");

        // ── invoices: type + credit_note linkage + VAT context ─────────────
        $this->addColumnIfMissing($db, 'invoices', 'type',                    "TEXT NOT NULL DEFAULT 'invoice'");
        $this->addColumnIfMissing($db, 'invoices', 'refers_to_invoice_id',    "INTEGER");
        $this->addColumnIfMissing($db, 'invoices', 'vat_scenario',            "TEXT");
        $this->addColumnIfMissing($db, 'invoices', 'reverse_charge_note_key', "TEXT");

        // ── invoice_counters: split sequence per type ──────────────────────
        // Old PK was (year, prefix). New PK is (year, prefix, type).
        // Forward-only: we add a `type` column with default 'invoice', then
        // create a new unique index. Old rows become 'invoice'.
        $this->addColumnIfMissing($db, 'invoice_counters', 'type', "TEXT NOT NULL DEFAULT 'invoice'");
        try {
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_invoice_counters_year_prefix_type ON invoice_counters(year, prefix, type)");
        } catch (\Throwable $e) { /* ignore if exists */ }

        // ── vat_id_cache: VIES results, shared across customers ────────────
        $now = $isSqlite ? "datetime('now')" : "NOW()";
        $db->exec("
            CREATE TABLE IF NOT EXISTS vat_id_cache (
                vat_id        TEXT PRIMARY KEY,
                country       TEXT NOT NULL,
                status        TEXT NOT NULL,
                trader_name   TEXT,
                trader_addr   TEXT,
                checked_at    TEXT NOT NULL DEFAULT ({$now}),
                ttl_seconds   INTEGER NOT NULL DEFAULT 86400,
                raw_response  TEXT
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_vat_id_cache_status ON vat_id_cache(status)");

        // ── invoices.refers_to_invoice_id index for credit_note lookups ────
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_refers_to ON invoices(refers_to_invoice_id)");
        } catch (\Throwable $e) { /* ignore */ }
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_type ON invoices(type)");
        } catch (\Throwable $e) { /* ignore */ }
    }

    /**
     * Idempotent ALTER TABLE — checks PRAGMA table_info first.
     * Safe under both SQLite and MySQL (the latter ignores PRAGMA but the
     * INFORMATION_SCHEMA fallback below covers it; in SQLite-only dev/test
     * runs the PRAGMA path is taken).
     */
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
