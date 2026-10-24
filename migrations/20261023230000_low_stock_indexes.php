<?php
/**
 * Round 9 (performance): the low-stock dashboard card sums warehouse_stock per SKU of the store and needs the store's
 * highest per-product `low` override. A covering (store_id, sku, qty, reserved) index lets the sum read the index only,
 * and (store_id, stock_low_threshold) answers the MAX without scanning the catalog.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        MigrationSql::index($db, $isSqlite, 'warehouse_stock', 'idx_wstock_store_sku', 'store_id, sku, qty, reserved');
        MigrationSql::index($db, $isSqlite, 'products', 'idx_products_store_low_threshold', 'store_id, stock_low_threshold');
    }
};
