<?php
/**
 * R31: an order can be invoiced to another address than it is delivered to. orders.billing_addr holds the billing
 * address as JSON (name, street, city, zip, country and the buyer company: company, ico, dic, ic_dph); NULL means the
 * order is billed to its delivery address (shipping_addr), which is how every order placed before was stored.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'")->fetchAll(PDO::FETCH_COLUMN);
        $long = MigrationSql::text($isSqlite);
        if (!in_array('billing_addr', $columns, true)) $db->exec("ALTER TABLE orders ADD COLUMN billing_addr {$long} DEFAULT NULL");
    }
};
