<?php
/**
 * Migration: create_rma
 * Generated: 2026-04-26T10:00:00+00:00
 *
 * RMA (Return Merchandise Authorisation) / returns flow. A customer can open a
 * return request against a *delivered* order; an admin then moves it through
 * the lifecycle requested → approved/rejected → received → refunded.
 *
 * rma_requests holds the header (one per return), rma_items the per-line detail
 * (which order_items + quantities are being returned). customer_id is nullable
 * to allow guest orders to be handled by an admin.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $now     = $isSqlite ? "datetime('now')" : "NOW()";

        $db->exec("CREATE TABLE IF NOT EXISTS rma_requests (
            id          INTEGER PRIMARY KEY{$autoinc},
            rma_number  TEXT NOT NULL,
            order_id    INTEGER NOT NULL,
            customer_id INTEGER DEFAULT NULL,
            status      TEXT NOT NULL DEFAULT 'requested',
            reason      TEXT NOT NULL DEFAULT '',
            admin_note  TEXT NOT NULL DEFAULT '',
            created_at  TEXT NOT NULL DEFAULT ({$now}),
            updated_at  TEXT NOT NULL DEFAULT ({$now}),
            UNIQUE(rma_number)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_rma_order ON rma_requests(order_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_rma_customer ON rma_requests(customer_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_rma_status ON rma_requests(status)");

        $db->exec("CREATE TABLE IF NOT EXISTS rma_items (
            id            INTEGER PRIMARY KEY{$autoinc},
            rma_id        INTEGER NOT NULL,
            order_item_id INTEGER NOT NULL,
            product_id    INTEGER DEFAULT NULL,
            sku           TEXT NOT NULL DEFAULT '',
            name          TEXT NOT NULL DEFAULT '',
            quantity      INTEGER NOT NULL DEFAULT 1,
            created_at    TEXT NOT NULL DEFAULT ({$now})
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_rma_items_rma ON rma_items(rma_id)");
    }
};
