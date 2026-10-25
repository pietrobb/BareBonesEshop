<?php
/** R10 UX8: shoppers' "notify me when back in stock" requests (see BackInStock). One per store, product and e-mail. */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $short = MigrationSql::short($isSqlite);
        $date = MigrationSql::datetime($isSqlite);
        $id = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $db->exec("CREATE TABLE IF NOT EXISTS back_in_stock_requests (
            id {$id},
            store_id {$short} NOT NULL,
            product_id INTEGER NOT NULL,
            email {$short} NOT NULL,
            locale {$short} NOT NULL DEFAULT '',
            created_at {$date} NOT NULL
        )" . ($isSqlite ? '' : ' ENGINE=InnoDB'));
        MigrationSql::index($db, $isSqlite, 'back_in_stock_requests', 'idx_bis_request', 'store_id, product_id, email', true);
        MigrationSql::index($db, $isSqlite, 'back_in_stock_requests', 'idx_bis_email', 'store_id, email');
    }
};
