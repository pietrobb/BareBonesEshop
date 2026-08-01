<?php
/**
 * Migration: create_invoices
 * Generated: 2026-04-24T22:17:21+00:00
 *
 * Adds invoice issuance tables:
 *   - invoices            : one row per issued invoice (immutable; corrections via new invoice)
 *   - invoice_counters    : per-(year,prefix) monotonic sequence with row-locked update
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $now = $isSqlite ? "datetime('now')" : "NOW()";
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $short = MigrationSql::short($isSqlite);
        $text = MigrationSql::text($isSqlite);

        $db->exec("
            CREATE TABLE IF NOT EXISTS invoices (
                id INTEGER PRIMARY KEY{$autoinc},
                order_id INTEGER NOT NULL,
                number {$short} NOT NULL UNIQUE,
                issued_at {$short} NOT NULL,
                delivery_date {$short},
                due_date {$short},
                pdf_path {$text} NOT NULL,
                total_net INTEGER NOT NULL DEFAULT 0,
                total_vat INTEGER NOT NULL DEFAULT 0,
                total_gross INTEGER NOT NULL DEFAULT 0,
                currency {$short} NOT NULL DEFAULT 'eur',
                vat_breakdown {$text} NOT NULL DEFAULT '{}',
                customer_snapshot {$text} NOT NULL DEFAULT '{}',
                seller_snapshot {$text} NOT NULL DEFAULT '{}',
                payment_method {$short},
                locale {$short} NOT NULL DEFAULT 'en',
                status {$short} NOT NULL DEFAULT 'issued',
                download_token {$short} NOT NULL UNIQUE,
                cancelled_at {$short},
                created_at {$short} DEFAULT ({$now}),
                FOREIGN KEY (order_id) REFERENCES orders(id)
            )
        ");
        MigrationSql::index($db, $isSqlite, 'invoices', 'idx_invoices_order', 'order_id');
        MigrationSql::index($db, $isSqlite, 'invoices', 'idx_invoices_issued_at', 'issued_at');
        MigrationSql::index($db, $isSqlite, 'invoices', 'idx_invoices_status', 'status');

        $db->exec("
            CREATE TABLE IF NOT EXISTS invoice_counters (
                year INTEGER NOT NULL,
                prefix {$short} NOT NULL DEFAULT '',
                last_number INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (year, prefix)
            )
        ");
    }
};
