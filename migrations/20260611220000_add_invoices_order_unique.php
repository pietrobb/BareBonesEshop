<?php
/**
 * Migration: add_invoices_order_unique
 * Generated: 2026-06-11T22:00:00+00:00
 *
 * Bug #9 (audit v1.10): InvoiceService::issue had a check-then-insert race —
 * two concurrent issue() calls for the same order could both pass the
 * byOrderId() null-check and INSERT, producing two invoices (two numbers,
 * two PDFs) for one order. The DB must enforce one *invoice* per order.
 *
 * Scoped to type='invoice' so future credit notes (invoices.type =
 * 'credit_note', see 20260425013405_add_vat_columns) for the same order
 * are not blocked:
 *   - SQLite : partial unique index  ... WHERE type='invoice'
 *   - MySQL  : functional unique index over CASE WHEN type='invoice'
 *              THEN order_id END (NULLs are not unique-constrained)
 *
 * Pre-existing duplicate rows (the bug's damage) are preserved for audit:
 * all but the lowest-id invoice per order are re-typed to 'duplicate' and
 * status 'cancelled' so the index can be created.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        // 1. Defuse existing duplicates: keep lowest id per order, void the rest.
        if ($isSqlite) {
            $db->exec("
                UPDATE invoices SET type = 'duplicate', status = 'cancelled'
                WHERE type = 'invoice' AND id NOT IN (
                    SELECT MIN(id) FROM invoices WHERE type = 'invoice' GROUP BY order_id
                )
            ");
        } else {
            $db->exec("
                UPDATE invoices AS duplicate_invoice
                JOIN (
                    SELECT order_id, MIN(id) AS keep_id
                    FROM invoices WHERE type = 'invoice' GROUP BY order_id
                ) AS kept_invoice ON kept_invoice.order_id = duplicate_invoice.order_id
                SET duplicate_invoice.type = 'duplicate', duplicate_invoice.status = 'cancelled'
                WHERE duplicate_invoice.type = 'invoice' AND duplicate_invoice.id <> kept_invoice.keep_id
            ");
        }

        // 2. Enforce uniqueness at the DB level.
        if ($isSqlite) {
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_invoices_order_invoice
                       ON invoices(order_id) WHERE type = 'invoice'");
        } else {
            $columnExists = $db->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
                  AND COLUMN_NAME = 'invoice_order_unique'
            ")->fetchColumn();
            if (!$columnExists) {
                $db->exec("ALTER TABLE invoices ADD COLUMN invoice_order_unique INTEGER
                           GENERATED ALWAYS AS (CASE WHEN type = 'invoice' THEN order_id ELSE NULL END) STORED");
            }
            $indexExists = $db->query("
                SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
                  AND INDEX_NAME = 'uq_invoices_order_invoice'
            ")->fetchColumn();
            if (!$indexExists) {
                $db->exec("CREATE UNIQUE INDEX uq_invoices_order_invoice ON invoices(invoice_order_unique)");
            }
        }
    }
};
