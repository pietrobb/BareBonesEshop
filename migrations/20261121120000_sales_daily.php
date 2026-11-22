<?php

// R52 (idea 61): daily sales totals (SalesDaily). A 365-day sales report summed every order and order line of the year
// (3.8 s at 500 000 orders on MySQL); the reports now read one row per store, local day and currency and one per
// product/variant for the days that have ended, and sum only the open days from the orders. The days of the existing
// orders are built here; a signed update run by an older release's classes leaves that to the first report.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $short = $isSqlite ? 'TEXT' : 'VARCHAR(64)';
        $day = $isSqlite ? 'TEXT' : 'CHAR(10)';
        $currency = $isSqlite ? 'TEXT' : 'VARCHAR(8)';
        $int = $isSqlite ? 'INTEGER' : 'BIGINT';
        $real = $isSqlite ? 'REAL' : 'DOUBLE';
        $engine = $isSqlite ? '' : ' ENGINE=InnoDB';
        $db->exec("CREATE TABLE IF NOT EXISTS sales_daily (
                store_id {$short} NOT NULL, day {$day} NOT NULL, currency {$currency} NOT NULL,
                orders {$int} NOT NULL DEFAULT 0, revenue {$int} NOT NULL DEFAULT 0,
                PRIMARY KEY (store_id, day, currency)
            ){$engine}");
        $db->exec("CREATE TABLE IF NOT EXISTS sales_daily_products (
                store_id {$short} NOT NULL, day {$day} NOT NULL, currency {$currency} NOT NULL,
                product_id {$int} NOT NULL, variant_key {$int} NOT NULL, variant_id {$int} NULL, line_id {$int} NOT NULL,
                quantity {$int} NOT NULL DEFAULT 0, revenue {$real} NOT NULL DEFAULT 0,
                PRIMARY KEY (store_id, day, currency, product_id, variant_key)
            ){$engine}");
        // Orders changed after their day was built (SalesDaily::touchOrders()); insert only.
        $db->exec($isSqlite
            ? 'CREATE TABLE IF NOT EXISTS sales_daily_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id TEXT NOT NULL, created_at TEXT NOT NULL)'
            : 'CREATE TABLE IF NOT EXISTS sales_daily_changes (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, store_id VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB');
        MigrationSql::index($db, $isSqlite, 'sales_daily_changes', 'idx_sales_daily_changes_store', 'store_id, id');
        $name = $isSqlite ? 'TEXT' : 'VARCHAR(191)';
        $db->exec("CREATE TABLE IF NOT EXISTS sales_daily_state (state_key {$name} NOT NULL PRIMARY KEY, value {$short} NOT NULL){$engine}");
        // The row SalesDaily's writers lock on MySQL.
        $db->exec(($isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . " INTO sales_daily_state (state_key, value) VALUES ('lock', '')");
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
        if (!method_exists('SalesDaily', 'refreshAll') || !method_exists('SalesReport', 'revenueRows') || Db::get() !== $db) return;
        foreach (['tableExists', 'forceIndex', 'rollBackOpen', 'transactionOpen'] as $method) if (!method_exists('Db', $method)) return;
        // R54 (low): for a few seconds; the worker job builds the rest and the reports sum the days not built from the
        // orders. Was: every day at once (10 s on MariaDB at 200 000 orders, inside the update's maintenance window).
        if (!defined('SalesDaily::JOB') || !class_exists('JobQueue')) {
            SalesDaily::refreshAll();
            return;
        }
        SalesDaily::refreshAll(false, 3.0, $finished);
        if (!$finished) {
            try {
                JobQueue::push(SalesDaily::JOB, []);
            } catch (Throwable $e) {
                // The first report builds further and queues the job itself.
            }
        }
    }
};
