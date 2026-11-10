<?php
/**
 * Migration: saved_for_later_items
 *
 * "Buy later" lines of signed-in customers (SavedForLater): a cart line (product, variant, quantity) set aside,
 * per customer and store. item_key is the cart key (p_<product> / v_<variant>), so one UNIQUE keeps a line once
 * without a nullable column in it. saved_price/saved_currency only tell the shopper the price has changed since.
 * A guest's list stays in the session and is folded in here on login.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $now     = $isSqlite ? "datetime('now')" : "NOW()";
        $short   = MigrationSql::short($isSqlite);
        $date    = MigrationSql::datetime($isSqlite);

        $db->exec("CREATE TABLE IF NOT EXISTS saved_for_later_items (
            id             INTEGER PRIMARY KEY{$autoinc},
            customer_id    INTEGER NOT NULL,
            store_id       {$short} NOT NULL,
            item_key       {$short} NOT NULL,
            product_id     INTEGER NOT NULL,
            variant_id     INTEGER DEFAULT NULL,
            qty            INTEGER NOT NULL DEFAULT 1,
            saved_price    INTEGER DEFAULT NULL,
            saved_currency {$short} DEFAULT NULL,
            created_at     {$date} NOT NULL DEFAULT ({$now}),
            UNIQUE(customer_id, store_id, item_key)
        )");
        MigrationSql::index($db, $isSqlite, 'saved_for_later_items', 'idx_saved_later_product', 'product_id');
    }
};
