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
        $short   = MigrationSql::short($isSqlite);
        $text    = MigrationSql::text($isSqlite);
        $date    = MigrationSql::datetime($isSqlite);
        $defaultEmpty = MigrationSql::defaultClause($isSqlite, '');

        $db->exec("CREATE TABLE IF NOT EXISTS rma_requests (
            id          INTEGER PRIMARY KEY{$autoinc},
            rma_number  {$short} NOT NULL,
            order_id    INTEGER NOT NULL,
            customer_id INTEGER DEFAULT NULL,
            status      {$short} NOT NULL DEFAULT 'requested',
            reason      {$text} NOT NULL {$defaultEmpty},
            admin_note  {$text} NOT NULL {$defaultEmpty},
            created_at  {$date} NOT NULL DEFAULT ({$now}),
            updated_at  {$date} NOT NULL DEFAULT ({$now}),
            UNIQUE(rma_number)
        )");
        MigrationSql::index($db, $isSqlite, 'rma_requests', 'idx_rma_order', 'order_id');
        MigrationSql::index($db, $isSqlite, 'rma_requests', 'idx_rma_customer', 'customer_id');
        MigrationSql::index($db, $isSqlite, 'rma_requests', 'idx_rma_status', 'status');

        $db->exec("CREATE TABLE IF NOT EXISTS rma_items (
            id            INTEGER PRIMARY KEY{$autoinc},
            rma_id        INTEGER NOT NULL,
            order_item_id INTEGER NOT NULL,
            product_id    INTEGER DEFAULT NULL,
            sku           {$short} NOT NULL {$defaultEmpty},
            name          {$short} NOT NULL {$defaultEmpty},
            quantity      INTEGER NOT NULL DEFAULT 1,
            created_at    {$date} NOT NULL DEFAULT ({$now})
        )");
        MigrationSql::index($db, $isSqlite, 'rma_items', 'idx_rma_items_rma', 'rma_id');
    }
};
