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

        $db->exec("
            CREATE TABLE IF NOT EXISTS invoices (
                id INTEGER PRIMARY KEY,
                order_id INTEGER NOT NULL,
                number TEXT NOT NULL UNIQUE,
                issued_at TEXT NOT NULL,
                delivery_date TEXT,
                due_date TEXT,
                pdf_path TEXT NOT NULL,
                total_net INTEGER NOT NULL DEFAULT 0,
                total_vat INTEGER NOT NULL DEFAULT 0,
                total_gross INTEGER NOT NULL DEFAULT 0,
                currency TEXT NOT NULL DEFAULT 'eur',
                vat_breakdown TEXT NOT NULL DEFAULT '{}',
                customer_snapshot TEXT NOT NULL DEFAULT '{}',
                seller_snapshot TEXT NOT NULL DEFAULT '{}',
                payment_method TEXT,
                locale TEXT NOT NULL DEFAULT 'en',
                status TEXT NOT NULL DEFAULT 'issued',
                download_token TEXT NOT NULL UNIQUE,
                cancelled_at TEXT,
                created_at TEXT DEFAULT ({$now}),
                FOREIGN KEY (order_id) REFERENCES orders(id)
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_order ON invoices(order_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_issued_at ON invoices(issued_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status)");

        $db->exec("
            CREATE TABLE IF NOT EXISTS invoice_counters (
                year INTEGER NOT NULL,
                prefix TEXT NOT NULL DEFAULT '',
                last_number INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (year, prefix)
            )
        ");
    }
};
