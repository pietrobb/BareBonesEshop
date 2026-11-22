<?php

// R53 (review round 53, low): the 365-day best sellers summed every product row of every built day (3.1 s at 500 000
// orders on MySQL). sales_monthly_products keeps the sum of a month's built days per product/variant (SalesDaily
// rebuilds a month with its days); the report reads whole months from it and only the days at both ends per day.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $short = $isSqlite ? 'TEXT' : 'VARCHAR(64)';
        $month = $isSqlite ? 'TEXT' : 'CHAR(7)';
        $currency = $isSqlite ? 'TEXT' : 'VARCHAR(8)';
        $int = $isSqlite ? 'INTEGER' : 'BIGINT';
        $real = $isSqlite ? 'REAL' : 'DOUBLE';
        $engine = $isSqlite ? '' : ' ENGINE=InnoDB';
        $db->exec("CREATE TABLE IF NOT EXISTS sales_monthly_products (
                store_id {$short} NOT NULL, month {$month} NOT NULL, currency {$currency} NOT NULL,
                product_id {$int} NOT NULL, variant_key {$int} NOT NULL, variant_id {$int} NULL, line_id {$int} NOT NULL,
                quantity {$int} NOT NULL DEFAULT 0, revenue {$real} NOT NULL DEFAULT 0,
                PRIMARY KEY (store_id, month, currency, product_id, variant_key)
            ){$engine}");
        $exists = static function (string $table) use ($db, $isSqlite): bool {
            $stmt = $isSqlite
                ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?")
                : $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);
            return $stmt->fetchColumn() !== false;
        };
        if ($exists('sales_daily_products')) {
            $db->exec('DELETE FROM sales_monthly_products');
            $db->exec('INSERT INTO sales_monthly_products (store_id, month, currency, product_id, variant_key, variant_id, line_id, quantity, revenue)
                SELECT store_id, SUBSTR(day, 1, 7), currency, product_id, variant_key, MAX(variant_id), MAX(line_id), SUM(quantity), SUM(revenue)
                FROM sales_daily_products GROUP BY store_id, SUBSTR(day, 1, 7), currency, product_id, variant_key');
        }
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
    }
};
