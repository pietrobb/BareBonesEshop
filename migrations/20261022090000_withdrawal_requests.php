<?php
/**
 * Round 8: electronic withdrawal function (Directive 2011/83/EU art. 11a, added by 2023/2673, from 19 June 2026).
 * A consumer withdraws from a contract on the storefront without logging in and without a reason; the shop
 * records the statement with the time it was received and acknowledges it by e-mail. One row per statement.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $short = MigrationSql::short($isSqlite);
        $text = MigrationSql::text($isSqlite);
        $date = MigrationSql::datetime($isSqlite);
        $id = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $db->exec("CREATE TABLE IF NOT EXISTS withdrawal_requests (
            id {$id},
            store_id {$short} NOT NULL,
            order_id INTEGER NOT NULL,
            order_number {$short} NOT NULL,
            customer_name {$short} NOT NULL,
            customer_email {$short} NOT NULL,
            items {$text},
            reason {$text},
            received_at {$date} NOT NULL,
            confirmation_status {$short} NOT NULL DEFAULT 'pending'
        )" . ($isSqlite ? '' : ' ENGINE=InnoDB'));
        MigrationSql::index($db, $isSqlite, 'withdrawal_requests', 'idx_withdrawal_order', 'store_id, order_id');
    }
};
