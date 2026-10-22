<?php
/**
 * Round 8 (scaling): with 500k orders and 200k customers the admin lists filtered by order status or payment status,
 * the dashboard's open-payment count and the customer list (store_id, newest id first) scanned or sorted the whole
 * store. Composite indexes matching those WHERE + ORDER BY shapes.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        MigrationSql::index($db, $isSqlite, 'orders', 'idx_orders_store_status_created', 'store_id, status, created_at');
        MigrationSql::index($db, $isSqlite, 'orders', 'idx_orders_store_payment_created', 'store_id, payment_status, created_at');
        MigrationSql::index($db, $isSqlite, 'customers', 'idx_customers_store_id', 'store_id, id');
    }
};
