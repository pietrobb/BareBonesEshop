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
        $short = MigrationSql::short($isSqlite);
        $text = MigrationSql::text($isSqlite);
        $date = MigrationSql::datetime($isSqlite);

        // ── products: vat_class ────────────────────────────────────────────
        $this->addColumnIfMissing($db, $isSqlite, 'products', 'vat_class', "{$short} NOT NULL DEFAULT 'standard'");

        // ── order_items: per-line VAT snapshot ─────────────────────────────
        $this->addColumnIfMissing($db, $isSqlite, 'order_items', 'vat_class',  "{$short} NOT NULL DEFAULT 'standard'");
        $this->addColumnIfMissing($db, $isSqlite, 'order_items', 'vat_rate',   "REAL NOT NULL DEFAULT 0");
        $this->addColumnIfMissing($db, $isSqlite, 'order_items', 'vat_amount', "INTEGER NOT NULL DEFAULT 0");
        $this->addColumnIfMissing($db, $isSqlite, 'order_items', 'net_amount', "INTEGER NOT NULL DEFAULT 0");

        // ── orders: aggregated VAT for fast reads ──────────────────────────
        $this->addColumnIfMissing($db, $isSqlite, 'orders', 'vat_scenario', $short);
        $this->addColumnIfMissing($db, $isSqlite, 'orders', 'vat_total',    "INTEGER NOT NULL DEFAULT 0");
        $this->addColumnIfMissing($db, $isSqlite, 'orders', 'net_total',    "INTEGER NOT NULL DEFAULT 0");

        // ── customers: VAT ID for B2B reverse-charge ───────────────────────
        $this->addColumnIfMissing($db, $isSqlite, 'customers', 'vat_id',           $short);
        $this->addColumnIfMissing($db, $isSqlite, 'customers', 'vat_country',      $short);
        $this->addColumnIfMissing($db, $isSqlite, 'customers', 'vat_validated_at', $date);

        // ── invoices: type + credit_note linkage + VAT context ─────────────
        $this->addColumnIfMissing($db, $isSqlite, 'invoices', 'type',                    "{$short} NOT NULL DEFAULT 'invoice'");
        $this->addColumnIfMissing($db, $isSqlite, 'invoices', 'refers_to_invoice_id',    "INTEGER");
        $this->addColumnIfMissing($db, $isSqlite, 'invoices', 'vat_scenario',            $short);
        $this->addColumnIfMissing($db, $isSqlite, 'invoices', 'reverse_charge_note_key', $short);

        // ── invoice_counters: split sequence per type ──────────────────────
        // Old PK was (year, prefix). New PK is (year, prefix, type).
        // Forward-only: we add a `type` column with default 'invoice', then
        // create a new unique index. Old rows become 'invoice'.
        $this->addColumnIfMissing($db, $isSqlite, 'invoice_counters', 'type', "{$short} NOT NULL DEFAULT 'invoice'");
        MigrationSql::index($db, $isSqlite, 'invoice_counters', 'idx_invoice_counters_year_prefix_type', 'year, prefix, type', true);

        // ── vat_id_cache: VIES results, shared across customers ────────────
        $now = $isSqlite ? "datetime('now')" : "NOW()";
        $db->exec("
            CREATE TABLE IF NOT EXISTS vat_id_cache (
                vat_id        {$short} PRIMARY KEY,
                country       {$short} NOT NULL,
                status        {$short} NOT NULL,
                trader_name   {$text},
                trader_addr   {$text},
                checked_at    {$date} NOT NULL DEFAULT ({$now}),
                ttl_seconds   INTEGER NOT NULL DEFAULT 86400,
                raw_response  {$text}
            )
        ");
        MigrationSql::index($db, $isSqlite, 'vat_id_cache', 'idx_vat_id_cache_status', 'status');

        // ── invoices.refers_to_invoice_id index for credit_note lookups ────
        MigrationSql::index($db, $isSqlite, 'invoices', 'idx_invoices_refers_to', 'refers_to_invoice_id');
        MigrationSql::index($db, $isSqlite, 'invoices', 'idx_invoices_type', 'type');
    }

    /**
     * Idempotent ALTER TABLE — checks PRAGMA table_info first.
     * Safe under both SQLite and MySQL (the latter ignores PRAGMA but the
     * INFORMATION_SCHEMA fallback below covers it; in SQLite-only dev/test
     * runs the PRAGMA path is taken).
     */
    private function addColumnIfMissing(PDO $db, bool $isSqlite, string $table, string $col, string $def): void {
        if ($isSqlite) {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $col) return;
            }
        } else {
            $stmt = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $stmt->execute([$table, $col]);
            if ($stmt->fetchColumn()) return;
        }
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
    }
};
