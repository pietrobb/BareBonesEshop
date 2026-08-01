<?php
/** Auditable inventory ledger, procurement, bundles and split fulfillment. */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $short = $isSqlite ? 'TEXT' : 'VARCHAR(191)';
        $datetime = $isSqlite ? 'TEXT' : 'DATETIME';
        $created = $isSqlite ? "TEXT NOT NULL DEFAULT (datetime('now'))" : 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';

        foreach ([
            ['inventory_policy', "{$short} NOT NULL DEFAULT 'deny'"],
            ['preorder_at', "{$datetime} DEFAULT NULL"],
            ['personalization_schema', "TEXT NOT NULL DEFAULT '{}'"],
        ] as [$column, $definition]) {
            $this->addColumnIfMissing($db, $isSqlite, 'products', $column, $definition);
        }
        foreach ([
            ['inventory_policy', "{$short} NOT NULL DEFAULT 'inherit'"],
            ['preorder_at', "{$datetime} DEFAULT NULL"],
        ] as [$column, $definition]) {
            $this->addColumnIfMissing($db, $isSqlite, 'variants', $column, $definition);
        }
        foreach ([
            ['reserved_qty', 'INTEGER NOT NULL DEFAULT 0'],
            ['backordered_qty', 'INTEGER NOT NULL DEFAULT 0'],
            ['fulfillment_status', "{$short} NOT NULL DEFAULT 'unfulfilled'"],
            ['preorder_at', "{$datetime} DEFAULT NULL"],
        ] as [$column, $definition]) {
            $this->addColumnIfMissing($db, $isSqlite, 'order_items', $column, $definition);
        }
        $this->addColumnIfMissing($db, $isSqlite, 'warehouses', 'store_id', "{$short} NOT NULL DEFAULT 'default'");
        $this->addColumnIfMissing($db, $isSqlite, 'warehouse_stock', 'store_id', "{$short} NOT NULL DEFAULT 'default'");

        $db->exec("CREATE TABLE IF NOT EXISTS stock_movements (
            id INTEGER PRIMARY KEY{$autoinc},
            store_id {$short} NOT NULL DEFAULT 'default',
            warehouse_id INTEGER DEFAULT NULL,
            sku {$short} NOT NULL,
            movement_type {$short} NOT NULL,
            quantity_delta INTEGER NOT NULL DEFAULT 0,
            reserved_delta INTEGER NOT NULL DEFAULT 0,
            on_hand_after INTEGER NOT NULL,
            reserved_after INTEGER NOT NULL,
            reference_type {$short} DEFAULT NULL,
            reference_id {$short} DEFAULT NULL,
            idempotency_key {$short} DEFAULT NULL,
            metadata TEXT NOT NULL DEFAULT '{}',
            created_at {$created},
            UNIQUE(store_id, idempotency_key)
        )");
        $this->index($db, $isSqlite, 'stock_movements', 'idx_stock_movements_sku', 'store_id, sku, created_at, id');
        $this->index($db, $isSqlite, 'stock_movements', 'idx_stock_movements_reference', 'store_id, reference_type, reference_id');

        $db->exec("CREATE TABLE IF NOT EXISTS suppliers (
            id INTEGER PRIMARY KEY{$autoinc}, store_id {$short} NOT NULL DEFAULT 'default',
            code {$short} NOT NULL, name {$short} NOT NULL, contact TEXT NOT NULL DEFAULT '{}',
            active INTEGER NOT NULL DEFAULT 1, created_at {$created}, UNIQUE(store_id, code)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS supplier_products (
            supplier_id INTEGER NOT NULL, sku {$short} NOT NULL, supplier_sku {$short} DEFAULT NULL,
            cost_price INTEGER NOT NULL DEFAULT 0, min_order_qty INTEGER NOT NULL DEFAULT 1,
            pack_size INTEGER NOT NULL DEFAULT 1, lead_time_days INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(supplier_id, sku)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
            id INTEGER PRIMARY KEY{$autoinc}, store_id {$short} NOT NULL DEFAULT 'default',
            supplier_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, number {$short} NOT NULL,
            status {$short} NOT NULL DEFAULT 'draft', currency {$short} NOT NULL DEFAULT 'eur',
            expected_at {$datetime} DEFAULT NULL, notes TEXT NOT NULL DEFAULT '',
            created_at {$created}, updated_at {$created}, UNIQUE(store_id, number)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS purchase_order_items (
            id INTEGER PRIMARY KEY{$autoinc}, purchase_order_id INTEGER NOT NULL, sku {$short} NOT NULL,
            quantity INTEGER NOT NULL, received_qty INTEGER NOT NULL DEFAULT 0,
            unit_cost INTEGER NOT NULL DEFAULT 0, UNIQUE(purchase_order_id, sku)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_receipts (
            id INTEGER PRIMARY KEY{$autoinc}, store_id {$short} NOT NULL DEFAULT 'default',
            purchase_order_id INTEGER NOT NULL, purchase_order_item_id INTEGER NOT NULL,
            warehouse_id INTEGER NOT NULL, sku {$short} NOT NULL, quantity INTEGER NOT NULL,
            idempotency_key {$short} NOT NULL, created_at {$created}, UNIQUE(store_id, idempotency_key)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS bundle_components (
            bundle_product_id INTEGER NOT NULL, component_product_id INTEGER NOT NULL,
            component_variant_id INTEGER NOT NULL DEFAULT 0, quantity INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(bundle_product_id, component_product_id, component_variant_id)
        )");
        $this->index($db, $isSqlite, 'bundle_components', 'idx_bundle_components_component', 'component_product_id, component_variant_id');

        $db->exec("CREATE TABLE IF NOT EXISTS shipments (
            id INTEGER PRIMARY KEY{$autoinc}, store_id {$short} NOT NULL DEFAULT 'default',
            order_id INTEGER NOT NULL, warehouse_id INTEGER DEFAULT NULL,
            status {$short} NOT NULL DEFAULT 'planned', carrier {$short} DEFAULT NULL,
            tracking_number {$short} DEFAULT NULL, idempotency_key {$short} NOT NULL,
            shipped_at {$datetime} DEFAULT NULL, created_at {$created}, updated_at {$created},
            UNIQUE(store_id, idempotency_key)
        )");
        $this->index($db, $isSqlite, 'shipments', 'idx_shipments_order', 'store_id, order_id, status');
        $db->exec("CREATE TABLE IF NOT EXISTS shipment_items (
            id INTEGER PRIMARY KEY{$autoinc}, shipment_id INTEGER NOT NULL,
            order_item_id INTEGER NOT NULL, sku {$short} NOT NULL, quantity INTEGER NOT NULL,
            status {$short} NOT NULL DEFAULT 'planned', created_at {$created},
            UNIQUE(shipment_id, order_item_id, sku)
        )");
        $this->index($db, $isSqlite, 'shipment_items', 'idx_shipment_items_order_item', 'order_item_id, status');

        if ($this->tableExists($db, $isSqlite, 'warehouse_stock')) {
            $insert = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
            $key = $isSqlite
                ? "'opening:' || warehouse_id || ':' || sku"
                : "CONCAT('opening:', warehouse_id, ':', sku)";
            $db->exec("{$insert} INTO stock_movements
                (store_id, warehouse_id, sku, movement_type, quantity_delta, reserved_delta,
                 on_hand_after, reserved_after, reference_type, idempotency_key, metadata)
                SELECT COALESCE(store_id, 'default'), warehouse_id, sku, 'opening_balance', qty, reserved,
                       qty, reserved, 'migration', {$key}, '{}'
                FROM warehouse_stock");
        }
    }

    private function tableExists(PDO $db, bool $isSqlite, string $table): bool {
        if ($isSqlite) {
            $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        } else {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        }
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function addColumnIfMissing(PDO $db, bool $isSqlite, string $table, string $column, string $definition): void {
        if (!$this->tableExists($db, $isSqlite, $table)) return;
        if ($isSqlite) {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) return;
            }
        } else {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $stmt->execute([$table, $column]);
            if ($stmt->fetchColumn()) return;
        }
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private function index(PDO $db, bool $isSqlite, string $table, string $name, string $columns): void {
        if ($isSqlite) {
            $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?");
            $stmt->execute([$name]);
        } else {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
            $stmt->execute([$table, $name]);
        }
        if (!$stmt->fetchColumn()) $db->exec("CREATE INDEX {$name} ON {$table}({$columns})");
    }
};
