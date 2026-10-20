<?php
/**
 * Round 7: product price history for the EU Omnibus rule (98/6/EC art. 6a, as amended by 2019/2161).
 *
 * A shop that announces a price reduction must show as the prior price the lowest price it charged in the
 * 30 days before the reduction. The struck-through price came from compare_price, which anyone can set to any
 * number, so the storefront advertised "was 199 €" for a product that always cost 99 €. PriceHistory records
 * every price a product gets; the storefront caps the struck-through price with it.
 *
 * Existing products get one row with their current price from their creation date. A discount set before this
 * migration has no recorded prior price, so it shows no struck-through price until the next real price change.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $db->exec($isSqlite
            ? 'CREATE TABLE IF NOT EXISTS product_price_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL,
                price INTEGER NOT NULL,
                lowest_prior INTEGER DEFAULT NULL,
                changed_at TEXT NOT NULL
            )'
            : 'CREATE TABLE IF NOT EXISTS product_price_history (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                price INT NOT NULL,
                lowest_prior INT DEFAULT NULL,
                changed_at DATETIME NOT NULL
            ) ENGINE=InnoDB');
        MigrationSql::index($db, $isSqlite, 'product_price_history', 'idx_price_history_product', 'product_id, changed_at');
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff(['id', 'price', 'created_at', 'updated_at'], $columns)) return;
        $db->exec("INSERT INTO product_price_history (product_id, price, changed_at)
            SELECT p.id, p.price, COALESCE(p.created_at, p.updated_at, '" . gmdate('Y-m-d H:i:s') . "') FROM products p
            WHERE p.price IS NOT NULL AND NOT EXISTS (SELECT 1 FROM product_price_history h WHERE h.product_id = p.id)");
    }
};
