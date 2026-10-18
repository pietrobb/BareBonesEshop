<?php
/**
 * Multi-store stock and ERP sync:
 * - Stock belongs to the catalog that owns the SKU (Warehouse binds warehouses/warehouse_stock to
 *   StoreContext::catalogId()). Warehouses a store with a shared catalog_scope created under its own id
 *   are moved to that catalog (keeping the catalog's default warehouse) and the catalog's stock columns
 *   are refreshed for the SKUs they hold.
 * - orders.erp_attempts / erp_next_retry_at back off a failing ERP push instead of retrying it at the
 *   head of every batch.
 * - erp_sync_state keeps the ERP product watermark per store in the database (it was a cache entry,
 *   lost on every cache flush).
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $short = MigrationSql::short($isSqlite);
        $datetime = MigrationSql::datetime($isSqlite);
        $suffix = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

        $orderColumns = $this->columns($db, $isSqlite, 'orders');
        if ($orderColumns !== []) {
            if (!in_array('erp_attempts', $orderColumns, true)) $db->exec('ALTER TABLE orders ADD COLUMN erp_attempts INTEGER NOT NULL DEFAULT 0');
            if (!in_array('erp_next_retry_at', $orderColumns, true)) $db->exec("ALTER TABLE orders ADD COLUMN erp_next_retry_at {$datetime} DEFAULT NULL");
        }
        $db->exec("CREATE TABLE IF NOT EXISTS erp_sync_state (
            store_id {$short} NOT NULL PRIMARY KEY,
            watermark {$short} NOT NULL,
            updated_at {$datetime} DEFAULT NULL
        ){$suffix}");

        if (!in_array('store_id', $this->columns($db, $isSqlite, 'warehouses'), true)
            || !in_array('store_id', $this->columns($db, $isSqlite, 'warehouse_stock'), true)) return;
        $stores = class_exists('Bootstrap') ? (array)(Bootstrap::stores()['stores'] ?? []) : [];
        foreach ($stores as $storeId => $config) {
            $storeId = (string)$storeId;
            $catalog = (string)(is_array($config) ? ($config['catalog_scope'] ?? $storeId) : $storeId);
            if ($catalog === $storeId) continue;
            // Not transactional on MySQL: a rerun after a failure between the two moves still finds the stock rows.
            $moved = $db->prepare('SELECT (SELECT COUNT(*) FROM warehouses WHERE store_id = ?) + (SELECT COUNT(*) FROM warehouse_stock WHERE store_id = ?)');
            $moved->execute([$storeId, $storeId]);
            if ((int)$moved->fetchColumn() === 0) continue;
            $default = $db->prepare('SELECT COUNT(*) FROM warehouses WHERE store_id = ? AND is_default = 1');
            $default->execute([$catalog]);
            if ((int)$default->fetchColumn() > 0) $db->prepare('UPDATE warehouses SET is_default = 0 WHERE store_id = ?')->execute([$storeId]);
            $db->prepare('UPDATE warehouses SET store_id = ? WHERE store_id = ?')->execute([$catalog, $storeId]);
            $db->prepare('UPDATE warehouse_stock SET store_id = ? WHERE store_id = ?')->execute([$catalog, $storeId]);
            $available = '(SELECT COALESCE(SUM(ws.qty - ws.reserved), 0) FROM warehouse_stock ws WHERE ws.sku = %s.sku AND ws.store_id = ?)';
            $db->prepare('UPDATE products SET stock = ' . sprintf($available, 'products') . '
                WHERE store_id = ? AND sku IN (SELECT sku FROM warehouse_stock WHERE store_id = ?)')->execute([$catalog, $catalog, $catalog]);
            $db->prepare('UPDATE variants SET stock = ' . sprintf($available, 'variants') . '
                WHERE product_id IN (SELECT id FROM products WHERE store_id = ?) AND sku IN (SELECT sku FROM warehouse_stock WHERE store_id = ?)')->execute([$catalog, $catalog, $catalog]);
        }
    }

    /** @return list<string> */
    private function columns(PDO $db, bool $isSqlite, string $table): array {
        if ($isSqlite) return array_column($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
};
