<?php
/**
 * Add explicit store/channel ownership to commerce data.
 * Existing single-store rows are preserved under the literal `default` store.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        foreach ([
            'categories', 'products', 'slug_registry', 'orders', 'payment_transactions',
            'coupon_uses', 'invoices', 'payment_methods', 'payment_method_countries',
            'payment_method_shipping', 'shipping_methods', 'shipping_zones',
            'shipping_zone_countries', 'shipping_rates', 'shipping_labels', 'pages',
        ] as $table) {
            if ($this->tableExists($db, $isSqlite, $table)) {
                $this->addColumnIfMissing($db, $isSqlite, $table, 'store_id', "TEXT NOT NULL DEFAULT 'default'");
            }
        }
        if ($this->tableExists($db, $isSqlite, 'orders')) {
            $this->addColumnIfMissing($db, $isSqlite, 'orders', 'channel_id', "TEXT NOT NULL DEFAULT 'storefront'");
        }

        foreach ([
            ['categories', 'idx_categories_store_path', ['store_id', 'path']],
            ['products', 'idx_products_store_catalog', ['store_id', 'status', 'category_id', 'price']],
            ['slug_registry', 'idx_slug_registry_store', ['store_id', 'entity', 'slug']],
            ['orders', 'idx_orders_store_created', ['store_id', 'created_at']],
            ['payment_transactions', 'idx_payment_transactions_store', ['store_id', 'order_id']],
            ['coupon_uses', 'idx_coupon_uses_store', ['store_id', 'coupon_code']],
            ['invoices', 'idx_invoices_store', ['store_id', 'order_id']],
            ['payment_methods', 'idx_payment_methods_store', ['store_id', 'active', 'sort_order']],
            ['payment_method_countries', 'idx_payment_method_countries_store', ['store_id', 'country', 'method_code']],
            ['payment_method_shipping', 'idx_payment_method_shipping_store', ['store_id', 'shipping_method_code', 'method_code']],
            ['shipping_methods', 'idx_shipping_methods_store', ['store_id', 'active', 'sort_order']],
            ['shipping_zones', 'idx_shipping_zones_store', ['store_id', 'active', 'sort_order']],
            ['shipping_zone_countries', 'idx_shipping_zone_countries_store', ['store_id', 'country', 'zone_code']],
            ['shipping_rates', 'idx_shipping_rates_store', ['store_id', 'method_code', 'zone_code', 'active']],
            ['shipping_labels', 'idx_shipping_labels_store', ['store_id', 'order_id']],
            ['pages', 'idx_pages_store', ['store_id', 'status', 'slug']],
        ] as [$table, $index, $columns]) {
            if ($this->tableExists($db, $isSqlite, $table) && !$this->indexExists($db, $isSqlite, $table, $index)) {
                $db->exec("CREATE INDEX {$index} ON {$table}(" . implode(', ', $columns) . ')');
            }
        }
    }

    private function tableExists(PDO $db, bool $isSqlite, string $table): bool {
        if ($isSqlite) {
            $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
            $stmt->execute([$table]);
        } else {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
        }
        return (bool)$stmt->fetchColumn();
    }

    private function addColumnIfMissing(PDO $db, bool $isSqlite, string $table, string $column, string $definition): void {
        if ($isSqlite) {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) return;
            }
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            if ((int)$stmt->fetchColumn() > 0) return;
        }
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private function indexExists(PDO $db, bool $isSqlite, string $table, string $index): bool {
        if ($isSqlite) {
            $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?");
            $stmt->execute([$index]);
        } else {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $stmt->execute([$table, $index]);
        }
        return (bool)$stmt->fetchColumn();
    }
};
