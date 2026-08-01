<?php
/**
 * General catalog, price-list, promotion and B2B account model.
 * Legacy product/category/customer rows retain their existing meaning.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $short = $isSqlite ? 'TEXT' : 'VARCHAR(191)';
        $datetime = $isSqlite ? 'TEXT' : 'DATETIME';
        $created = $isSqlite ? "TEXT NOT NULL DEFAULT (datetime('now'))" : 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $defaultObject = MigrationSql::defaultClause($isSqlite, '{}');
        $defaultArray = MigrationSql::defaultClause($isSqlite, '[]');

        $db->exec("CREATE TABLE IF NOT EXISTS brands (
            id INTEGER PRIMARY KEY{$autoinc},
            store_id {$short} NOT NULL DEFAULT 'default',
            code {$short} NOT NULL,
            name {$short} NOT NULL,
            manufacturer_name {$short} DEFAULT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at {$created},
            UNIQUE(store_id, code)
        )");
        $this->index($db, $isSqlite, 'brands', 'idx_brands_store_active', 'store_id, active, name');

        foreach ([
            ['brand_id', 'INTEGER DEFAULT NULL'],
            ['gtin', "{$short} DEFAULT NULL"],
            ['mpn', "{$short} DEFAULT NULL"],
            ['cost_price', 'INTEGER DEFAULT NULL'],
            ['length_mm', 'INTEGER NOT NULL DEFAULT 0'],
            ['width_mm', 'INTEGER NOT NULL DEFAULT 0'],
            ['height_mm', 'INTEGER NOT NULL DEFAULT 0'],
            ['availability', "{$short} NOT NULL DEFAULT 'active'"],
            ['available_from', "{$datetime} DEFAULT NULL"],
        ] as [$column, $definition]) {
            $this->addColumnIfMissing($db, $isSqlite, 'products', $column, $definition);
        }
        foreach ([
            ['gtin', "{$short} DEFAULT NULL"],
            ['mpn', "{$short} DEFAULT NULL"],
            ['media', "TEXT NOT NULL {$defaultArray}"],
            ['weight_g', 'INTEGER NOT NULL DEFAULT 0'],
            ['length_mm', 'INTEGER NOT NULL DEFAULT 0'],
            ['width_mm', 'INTEGER NOT NULL DEFAULT 0'],
            ['height_mm', 'INTEGER NOT NULL DEFAULT 0'],
            ['availability', "{$short} NOT NULL DEFAULT 'active'"],
            ['available_from', "{$datetime} DEFAULT NULL"],
        ] as [$column, $definition]) {
            $this->addColumnIfMissing($db, $isSqlite, 'variants', $column, $definition);
        }

        $db->exec("CREATE TABLE IF NOT EXISTS product_categories (
            product_id INTEGER NOT NULL,
            category_id INTEGER NOT NULL,
            is_primary INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(product_id, category_id),
            FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE CASCADE
        )");
        $this->index($db, $isSqlite, 'product_categories', 'idx_product_categories_category', 'category_id, sort_order, product_id');
        $insertIgnore = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $db->exec("{$insertIgnore} INTO product_categories (product_id, category_id, is_primary)
            SELECT id, category_id, 1 FROM products WHERE category_id IS NOT NULL");

        $db->exec("CREATE TABLE IF NOT EXISTS customer_groups (
            id INTEGER PRIMARY KEY{$autoinc},
            store_id {$short} NOT NULL DEFAULT 'default',
            code {$short} NOT NULL,
            name {$short} NOT NULL,
            discount_bps INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            created_at {$created},
            UNIQUE(store_id, code)
        )");
        $this->index($db, $isSqlite, 'customer_groups', 'idx_customer_groups_store', 'store_id, active');

        $db->exec("CREATE TABLE IF NOT EXISTS companies (
            id INTEGER PRIMARY KEY{$autoinc},
            store_id {$short} NOT NULL DEFAULT 'default',
            code {$short} NOT NULL,
            legal_name {$short} NOT NULL,
            registration_number {$short} DEFAULT NULL,
            vat_id {$short} DEFAULT NULL,
            vat_country {$short} DEFAULT NULL,
            vat_validated_at {$datetime} DEFAULT NULL,
            approval_status {$short} NOT NULL DEFAULT 'pending',
            credit_limit INTEGER NOT NULL DEFAULT 0,
            payment_terms_days INTEGER NOT NULL DEFAULT 0,
            invoice_purchase INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            created_at {$created},
            updated_at {$created},
            UNIQUE(store_id, code)
        )");
        $this->index($db, $isSqlite, 'companies', 'idx_companies_store_approval', 'store_id, active, approval_status');

        foreach ([
            ['store_id', "{$short} NOT NULL DEFAULT 'default'"],
            ['customer_group_id', 'INTEGER DEFAULT NULL'],
            ['company_id', 'INTEGER DEFAULT NULL'],
            ['vat_id', "{$short} DEFAULT NULL"],
            ['vat_country', "{$short} DEFAULT NULL"],
            ['vat_validated_at', "{$datetime} DEFAULT NULL"],
        ] as [$column, $definition]) {
            $this->addColumnIfMissing($db, $isSqlite, 'customers', $column, $definition);
        }
        if ($this->tableExists($db, $isSqlite, 'customers')) {
            $this->index($db, $isSqlite, 'customers', 'idx_customers_store_group', 'store_id, customer_group_id, company_id');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS price_lists (
            id INTEGER PRIMARY KEY{$autoinc},
            store_id {$short} NOT NULL DEFAULT 'default',
            code {$short} NOT NULL,
            name {$short} NOT NULL,
            currency {$short} NOT NULL,
            customer_group_id INTEGER DEFAULT NULL,
            priority INTEGER NOT NULL DEFAULT 0,
            valid_from {$datetime} DEFAULT NULL,
            valid_to {$datetime} DEFAULT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at {$created},
            updated_at {$created},
            UNIQUE(store_id, code)
        )");
        $this->index($db, $isSqlite, 'price_lists', 'idx_price_lists_resolve', 'store_id, currency, active, priority');

        $db->exec("CREATE TABLE IF NOT EXISTS price_list_prices (
            id INTEGER PRIMARY KEY{$autoinc},
            price_list_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            variant_id INTEGER DEFAULT NULL,
            min_qty INTEGER NOT NULL DEFAULT 1,
            price INTEGER NOT NULL,
            compare_price INTEGER DEFAULT NULL,
            created_at {$created},
            FOREIGN KEY(price_list_id) REFERENCES price_lists(id) ON DELETE CASCADE,
            FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY(variant_id) REFERENCES variants(id) ON DELETE CASCADE
        )");
        $this->index($db, $isSqlite, 'price_list_prices', 'idx_price_list_prices_resolve', 'product_id, variant_id, min_qty, price_list_id');

        $db->exec("CREATE TABLE IF NOT EXISTS promotions (
            id INTEGER PRIMARY KEY{$autoinc},
            store_id {$short} NOT NULL DEFAULT 'default',
            code {$short} NOT NULL,
            name {$short} NOT NULL,
            scope {$short} NOT NULL DEFAULT 'catalog',
            coupon_code {$short} DEFAULT NULL,
            priority INTEGER NOT NULL DEFAULT 100,
            stacking {$short} NOT NULL DEFAULT 'exclusive',
            conditions TEXT NOT NULL {$defaultObject},
            actions TEXT NOT NULL {$defaultObject},
            valid_from {$datetime} DEFAULT NULL,
            valid_to {$datetime} DEFAULT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at {$created},
            updated_at {$created},
            UNIQUE(store_id, code)
        )");
        $this->index($db, $isSqlite, 'promotions', 'idx_promotions_resolve', 'store_id, scope, active, priority');
        $this->index($db, $isSqlite, 'promotions', 'idx_promotions_coupon', 'store_id, coupon_code, active');

        if ($this->tableExists($db, $isSqlite, 'payment_methods')) {
            $stmt = $db->prepare("{$insertIgnore} INTO payment_methods
                (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
                VALUES ('invoice', 'manual', 'Invoice purchase', 'Approved B2B account with credit limit and payment terms.', 'invoice', 0, NULL, 0, 0, NULL, 0, 1, 25)");
            $stmt->execute();
            if ($this->tableExists($db, $isSqlite, 'payment_method_countries')) {
                $db->exec("{$insertIgnore} INTO payment_method_countries (method_code, country)
                    SELECT 'invoice', country FROM payment_method_countries WHERE method_code = 'bank_transfer'");
            }
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
