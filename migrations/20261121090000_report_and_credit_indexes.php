<?php

// R51 (review round 51, MySQL lows):
//  - customers (store_id, company_id): the B2B credit check (Customers::companyOpenTotal(), a locking read in the order
//    transaction) found the company's members through an index starting with customer_group_id, so MySQL scanned and
//    locked every customer of the store and orders on invoice of other companies waited on it.
//  - orders (store_id, payment_status, created_at, is_synthetic, currency, total) and order_items (order_id, product_id,
//    variant_id, quantity, total): the sales reports read only these indexes, not every order and order line row of the
//    period (a 365-day report took 13 s at 500 000 orders). They replace the narrower indexes they start with.
//    orders.status does not fit into the MySQL key next to the other text columns (3072 bytes): the reports leave out
//    the few cancelled/refunded orders of the period by id instead (SalesReport::revenueWhere()).
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        MigrationSql::index($db, $isSqlite, 'customers', 'idx_customers_store_company', 'store_id, company_id');
        MigrationSql::index($db, $isSqlite, 'orders', 'idx_orders_store_payment_cover', 'store_id, payment_status, created_at, is_synthetic, currency, total');
        MigrationSql::index($db, $isSqlite, 'order_items', 'idx_oi_order_cover', 'order_id, product_id, variant_id, quantity, total');
        $this->drop($db, $isSqlite, 'orders', 'idx_orders_store_payment_created');
        $this->drop($db, $isSqlite, 'order_items', 'idx_oi_order');
    }

    private function drop(PDO $db, bool $isSqlite, string $table, string $index): void {
        if ($isSqlite) {
            $db->exec("DROP INDEX IF EXISTS {$index}");
            return;
        }
        $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        if ($stmt->fetchColumn()) $db->exec("DROP INDEX {$index} ON {$table}");
    }
};
