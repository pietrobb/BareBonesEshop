<?php
/**
 * R15: double opt-in for "notify me when back in stock". A request is mailed about the product only once the address
 * confirmed it through the e-mailed link (confirmed_at); token_hash is the SHA-256 of that link's token while it waits.
 * Requests stored before this migration were never confirmed: they stay unconfirmed and lapse like any other.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(back_in_stock_requests)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'back_in_stock_requests'")->fetchAll(PDO::FETCH_COLUMN);
        $date = MigrationSql::datetime($isSqlite);
        $short = MigrationSql::short($isSqlite);
        if (!in_array('confirmed_at', $columns, true)) $db->exec("ALTER TABLE back_in_stock_requests ADD COLUMN confirmed_at {$date} NULL");
        if (!in_array('token_hash', $columns, true)) $db->exec("ALTER TABLE back_in_stock_requests ADD COLUMN token_hash {$short} NULL");
        MigrationSql::index($db, $isSqlite, 'back_in_stock_requests', 'idx_bis_token', 'token_hash');
    }
};
