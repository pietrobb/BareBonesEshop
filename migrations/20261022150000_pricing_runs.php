<?php
/**
 * Round 8 follow-up: past runs of catalog promotions and price lists for the EU Omnibus prior price.
 *
 * The prior price counts what catalog promotions and price lists charged in the 30 days before a reduction, and it
 * was rebuilt from their current rows. Re-dating a rule to run it again, switching it off, deleting it, or repricing
 * or deleting a price list rewrote that record, and the storefront struck through the base price although a lower
 * price had been charged days before. PricingAdmin now copies such a run here before it changes it.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $id = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $short = $isSqlite ? 'TEXT' : 'VARCHAR(191)';
        $datetime = $isSqlite ? 'TEXT' : 'DATETIME';
        $engine = $isSqlite ? '' : ' ENGINE=InnoDB';
        $db->exec("CREATE TABLE IF NOT EXISTS promotion_runs (
            id {$id},
            store_id {$short} NOT NULL,
            promotion_id INTEGER NOT NULL,
            code {$short} NOT NULL,
            scope {$short} NOT NULL,
            priority INTEGER NOT NULL,
            stacking {$short} NOT NULL,
            conditions TEXT NOT NULL,
            actions TEXT NOT NULL,
            valid_from {$datetime} DEFAULT NULL,
            valid_to {$datetime} NOT NULL
        ){$engine}");
        MigrationSql::index($db, $isSqlite, 'promotion_runs', 'idx_promotion_runs_store', 'store_id, scope, valid_to');
        $db->exec("CREATE TABLE IF NOT EXISTS price_list_runs (
            id {$id},
            store_id {$short} NOT NULL,
            price_list_id INTEGER NOT NULL,
            currency {$short} NOT NULL,
            customer_group_id INTEGER DEFAULT NULL,
            product_id INTEGER NOT NULL,
            price INTEGER NOT NULL,
            valid_from {$datetime} DEFAULT NULL,
            valid_to {$datetime} NOT NULL
        ){$engine}");
        MigrationSql::index($db, $isSqlite, 'price_list_runs', 'idx_price_list_runs_product', 'product_id, store_id, valid_to');
    }
};
