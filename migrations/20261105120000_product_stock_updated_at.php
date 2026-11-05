<?php
/**
 * R17: a stock change (sale, shipment, adjustment) moved products.updated_at since v2.1.2, so that API v1
 * updated_after returns it — and with it the public feed and sitemap cache fingerprint (MAX(updated_at)) changed
 * after every sale and every feed and sitemap was rebuilt. Stock changes now move
 * stock_updated_at; updated_after matches either column.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('stock_updated_at', $columns, true)) {
            $db->exec('ALTER TABLE products ADD COLUMN stock_updated_at ' . MigrationSql::datetime($isSqlite) . ' NULL');
        }
        MigrationSql::index($db, $isSqlite, 'products', 'idx_prod_stock_updated', 'stock_updated_at');
    }
};
