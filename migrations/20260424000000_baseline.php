<?php
/**
 * Baseline migration — captures the schema as of v1.4.0 (Customer Accounts).
 *
 * Idempotent: every CREATE TABLE uses IF NOT EXISTS and ALTER TABLE for
 * post-create columns is wrapped in try/catch. Fresh DBs run it; existing
 * production DBs are seeded via `php cli/migrate.php baseline` (no run).
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $now     = $isSqlite ? "datetime('now')" : 'NOW()';
        $text = MigrationSql::short($isSqlite);
        $long = MigrationSql::text($isSqlite);
        $defaultEmpty = MigrationSql::defaultClause($isSqlite, '');
        $defaultObject = MigrationSql::defaultClause($isSqlite, '{}');
        $defaultArray = MigrationSql::defaultClause($isSqlite, '[]');

        // Categories
        $db->exec("CREATE TABLE IF NOT EXISTS categories (
            id            INTEGER PRIMARY KEY{$autoinc},
            slug          {$text} NOT NULL,
            name          {$text} NOT NULL,
            parent_id     INTEGER,
            path          {$text} NOT NULL {$defaultEmpty},
            depth         INTEGER NOT NULL DEFAULT 0,
            sort_order    INTEGER DEFAULT 0,
            product_count INTEGER DEFAULT 0,
            translations  {$long} {$defaultObject},
            meta          {$long} {$defaultObject},
            FOREIGN KEY (parent_id) REFERENCES categories(id)
        )");
        if ($isSqlite) {
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_cat_slug_parent ON categories(slug, COALESCE(parent_id, 0))");
        } else {
            try {
                $db->exec("ALTER TABLE categories ADD COLUMN parent_id_uniq INT GENERATED ALWAYS AS (COALESCE(parent_id, 0)) STORED");
                $db->exec("CREATE UNIQUE INDEX idx_cat_slug_parent ON categories(slug(191), parent_id_uniq)");
            } catch (\PDOException $e) { /* already exists */ }
        }
        MigrationSql::index($db, $isSqlite, 'categories', 'idx_cat_parent', 'parent_id');
        MigrationSql::index($db, $isSqlite, 'categories', 'idx_cat_path', 'path');

        // Products
        $db->exec("CREATE TABLE IF NOT EXISTS products (
            id            INTEGER PRIMARY KEY{$autoinc},
            sku           {$text} UNIQUE NOT NULL,
            slug          {$text} UNIQUE NOT NULL,
            product_type  {$text} NOT NULL DEFAULT 'simple',
            name          {$text} NOT NULL,
            category_id   INTEGER NOT NULL,
            price         INTEGER NOT NULL,
            compare_price INTEGER,
            currency      {$text} NOT NULL DEFAULT 'eur',
            stock         INTEGER NOT NULL DEFAULT 0,
            status        {$text} NOT NULL DEFAULT 'active',
            images        {$long} {$defaultArray},
            summary       {$long} {$defaultEmpty},
            description   {$long} {$defaultEmpty},
            attrs         {$long} {$defaultObject},
            tags          {$long} {$defaultArray},
            filter_tags   {$long} {$defaultEmpty},
            weight_g      INTEGER DEFAULT 0,
            translations  {$long} {$defaultObject},
            created_at    {$text} DEFAULT ({$now}),
            updated_at    {$text} DEFAULT ({$now}),
            FOREIGN KEY (category_id) REFERENCES categories(id)
        )");
        MigrationSql::index($db, $isSqlite, 'products', 'idx_prod_category', 'category_id, status, price');
        MigrationSql::index($db, $isSqlite, 'products', 'idx_prod_status', 'status');
        MigrationSql::index($db, $isSqlite, 'products', 'idx_prod_price', 'price');
        MigrationSql::index($db, $isSqlite, 'products', 'idx_prod_updated', 'updated_at');
        MigrationSql::index($db, $isSqlite, 'products', 'idx_prod_created', 'status, created_at DESC');

        // FTS5 (SQLite only)
        if ($isSqlite) {
            $ftsExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='products_fts'")->fetch();
            if (!$ftsExists) {
                $db->exec("CREATE VIRTUAL TABLE products_fts USING fts5(
                    name, sku, summary, tags, filter_tags,
                    content=products, content_rowid=id,
                    tokenize='unicode61 remove_diacritics 2'
                )");
                $db->exec("CREATE TRIGGER IF NOT EXISTS products_ai AFTER INSERT ON products BEGIN
                    INSERT INTO products_fts(rowid, name, sku, summary, tags, filter_tags)
                    VALUES (new.id, new.name, new.sku, new.summary, new.tags, new.filter_tags);
                END");
                $db->exec("CREATE TRIGGER IF NOT EXISTS products_ad AFTER DELETE ON products BEGIN
                    INSERT INTO products_fts(products_fts, rowid, name, sku, summary, tags, filter_tags)
                    VALUES ('delete', old.id, old.name, old.sku, old.summary, old.tags, old.filter_tags);
                END");
                $db->exec("CREATE TRIGGER IF NOT EXISTS products_au AFTER UPDATE ON products BEGIN
                    INSERT INTO products_fts(products_fts, rowid, name, sku, summary, tags, filter_tags)
                    VALUES ('delete', old.id, old.name, old.sku, old.summary, old.tags, old.filter_tags);
                    INSERT INTO products_fts(rowid, name, sku, summary, tags, filter_tags)
                    VALUES (new.id, new.name, new.sku, new.summary, new.tags, new.filter_tags);
                END");
            }
        } else {
            try {
                $db->exec("ALTER TABLE products ADD FULLTEXT idx_ft_products (name, sku, summary, tags)");
                $db->exec("ALTER TABLE products ADD FULLTEXT idx_ft_filters (filter_tags)");
            } catch (\PDOException $e) { /* already exists */ }
        }

        // Product Attributes
        $db->exec("CREATE TABLE IF NOT EXISTS product_attrs (
            product_id  INTEGER NOT NULL,
            attr_name   {$text} NOT NULL,
            attr_value  {$text} NOT NULL,
            numeric_val REAL,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )");
        MigrationSql::index($db, $isSqlite, 'product_attrs', 'idx_pa_lookup', 'attr_name, attr_value, product_id');
        MigrationSql::index($db, $isSqlite, 'product_attrs', 'idx_pa_product', 'product_id');

        // Variants
        $db->exec("CREATE TABLE IF NOT EXISTS variants (
            id             INTEGER PRIMARY KEY{$autoinc},
            product_id     INTEGER NOT NULL,
            sku            {$text} UNIQUE NOT NULL,
            name           {$text} NOT NULL {$defaultEmpty},
            attrs          {$long} NOT NULL {$defaultObject},
            price_delta    INTEGER NOT NULL DEFAULT 0,
            price_absolute INTEGER,
            stock          INTEGER NOT NULL DEFAULT 0,
            sort_order     INTEGER DEFAULT 0,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )");
        MigrationSql::index($db, $isSqlite, 'variants', 'idx_var_product', 'product_id');

        // Slug Registry
        $db->exec("CREATE TABLE IF NOT EXISTS slug_registry (
            slug      {$text} NOT NULL,
            locale    {$text} NOT NULL,
            entity    {$text} NOT NULL,
            entity_id INTEGER NOT NULL,
            PRIMARY KEY (slug, locale, entity)
        )");

        // Orders
        $db->exec("CREATE TABLE IF NOT EXISTS orders (
            id              INTEGER PRIMARY KEY{$autoinc},
            order_number    {$text} UNIQUE NOT NULL,
            status          {$text} NOT NULL DEFAULT 'pending',
            payment_status  {$text} NOT NULL DEFAULT 'pending',
            customer_id     INTEGER DEFAULT NULL,
            customer_name   {$text} NOT NULL,
            customer_email  {$text} NOT NULL,
            customer_phone  {$text} {$defaultEmpty},
            shipping_addr   {$long} {$defaultObject},
            payment_method  {$text} NOT NULL,
            payment_id      {$text} {$defaultEmpty},
            subtotal        INTEGER NOT NULL,
            discount_amount INTEGER NOT NULL DEFAULT 0,
            shipping_cost   INTEGER NOT NULL DEFAULT 0,
            tax_amount      INTEGER NOT NULL DEFAULT 0,
            total           INTEGER NOT NULL,
            currency        {$text} NOT NULL DEFAULT 'eur',
            locale          {$text} NOT NULL DEFAULT 'en',
            notes           {$long} {$defaultEmpty},
            ip_address      {$text} {$defaultEmpty},
            tracking_number {$text} DEFAULT NULL,
            carrier         {$text} DEFAULT NULL,
            erp_id          {$text} DEFAULT NULL,
            erp_status      {$text} DEFAULT NULL,
            erp_synced_at   {$text} DEFAULT NULL,
            erp_error       {$long} DEFAULT NULL,
            created_at      {$text} DEFAULT ({$now}),
            updated_at      {$text} DEFAULT ({$now})
        )");
        // Migration-style ALTERs for upgrade-from-older-schema paths
        foreach ([
            "ALTER TABLE orders ADD COLUMN payment_status {$text} NOT NULL DEFAULT 'pending'",
            "ALTER TABLE orders ADD COLUMN locale {$text} NOT NULL DEFAULT 'en'",
            "ALTER TABLE orders ADD COLUMN tracking_number {$text} DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN carrier {$text} DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN customer_id INTEGER DEFAULT NULL",
        ] as $alter) {
            try { $db->exec($alter); } catch (\PDOException $e) { /* already exists */ }
        }
        MigrationSql::index($db, $isSqlite, 'orders', 'idx_ord_status', 'status, created_at');
        MigrationSql::index($db, $isSqlite, 'orders', 'idx_ord_email', 'customer_email');
        MigrationSql::index($db, $isSqlite, 'orders', 'idx_ord_customer', 'customer_id');

        // Email Log
        $db->exec("CREATE TABLE IF NOT EXISTS email_log (
            id         INTEGER PRIMARY KEY{$autoinc},
            order_id   INTEGER DEFAULT NULL,
            recipient  {$text} NOT NULL,
            template   {$text} NOT NULL,
            locale     {$text} NOT NULL DEFAULT 'en',
            subject    {$text} NOT NULL {$defaultEmpty},
            adapter    {$text} NOT NULL {$defaultEmpty},
            status     {$text} NOT NULL DEFAULT 'pending',
            error      {$long} {$defaultEmpty},
            payload    {$long} {$defaultObject},
            sent_at    {$text} DEFAULT NULL,
            created_at {$text} DEFAULT ({$now})
        )");
        MigrationSql::index($db, $isSqlite, 'email_log', 'idx_eml_order', 'order_id');
        MigrationSql::index($db, $isSqlite, 'email_log', 'idx_eml_recipient', 'recipient, created_at');
        MigrationSql::index($db, $isSqlite, 'email_log', 'idx_eml_status', 'status, created_at');

        // Payment Transactions
        $db->exec("CREATE TABLE IF NOT EXISTS payment_transactions (
            id              INTEGER PRIMARY KEY{$autoinc},
            order_id        INTEGER NOT NULL,
            provider        {$text} NOT NULL,
            provider_ref    {$text} NOT NULL {$defaultEmpty},
            status          {$text} NOT NULL,
            amount          INTEGER NOT NULL,
            currency        {$text} NOT NULL DEFAULT 'eur',
            idempotency_key {$text} NOT NULL UNIQUE,
            raw_response    {$long} NOT NULL {$defaultObject},
            created_at      {$text} DEFAULT ({$now}),
            updated_at      {$text} DEFAULT ({$now}),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )");
        MigrationSql::index($db, $isSqlite, 'payment_transactions', 'idx_ptx_order', 'order_id');
        MigrationSql::index($db, $isSqlite, 'payment_transactions', 'idx_ptx_ref', 'provider, provider_ref');
        MigrationSql::index($db, $isSqlite, 'payment_transactions', 'idx_ptx_status', 'provider, status');

        // Order Items
        $db->exec("CREATE TABLE IF NOT EXISTS order_items (
            id          INTEGER PRIMARY KEY{$autoinc},
            order_id    INTEGER NOT NULL,
            product_id  INTEGER NOT NULL,
            variant_id  INTEGER,
            sku         {$text} NOT NULL,
            name        {$text} NOT NULL,
            quantity    INTEGER NOT NULL,
            unit_price  INTEGER NOT NULL,
            total       INTEGER NOT NULL,
            attrs       {$long} {$defaultObject},
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id)
        )");
        MigrationSql::index($db, $isSqlite, 'order_items', 'idx_oi_order', 'order_id');

        // Audit Log
        $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id         INTEGER PRIMARY KEY{$autoinc},
            entity     {$text} NOT NULL,
            entity_id  INTEGER NOT NULL,
            action     {$text} NOT NULL,
            old_value  {$long},
            new_value  {$long},
            note       {$long},
            actor      {$text} NOT NULL,
            ip_address {$text},
            created_at {$text} DEFAULT ({$now})
        )");
        MigrationSql::index($db, $isSqlite, 'audit_log', 'idx_audit_entity', 'entity, entity_id, created_at');
        MigrationSql::index($db, $isSqlite, 'audit_log', 'idx_audit_actor', 'actor, created_at');

        // Processed Events (idempotency)
        $db->exec("CREATE TABLE IF NOT EXISTS processed_events (
            event_id   {$text} PRIMARY KEY,
            created_at {$text} DEFAULT ({$now})
        )");

        // Coupon Usage
        $db->exec("CREATE TABLE IF NOT EXISTS coupon_uses (
            id             INTEGER PRIMARY KEY{$autoinc},
            coupon_code    {$text} NOT NULL,
            order_id       INTEGER NOT NULL,
            customer_email {$text} NOT NULL,
            discount       INTEGER NOT NULL DEFAULT 0,
            created_at     {$text} DEFAULT ({$now}),
            FOREIGN KEY (order_id) REFERENCES orders(id)
        )");
        MigrationSql::index($db, $isSqlite, 'coupon_uses', 'idx_coupon_code', 'coupon_code');
        MigrationSql::index($db, $isSqlite, 'coupon_uses', 'idx_coupon_email', 'coupon_code, customer_email');

        // Abandoned Carts
        $db->exec("CREATE TABLE IF NOT EXISTS abandoned_carts (
            id             INTEGER PRIMARY KEY{$autoinc},
            session_id     {$text} NOT NULL UNIQUE,
            customer_email {$text},
            cart_data      {$long} NOT NULL {$defaultObject},
            created_at     {$text} DEFAULT ({$now}),
            reminded_at    {$text}
        )");
        MigrationSql::index($db, $isSqlite, 'abandoned_carts', 'idx_ac_email', 'customer_email, reminded_at');

        // Product Specifications
        $db->exec("CREATE TABLE IF NOT EXISTS product_specifications (
            id             INTEGER PRIMARY KEY{$autoinc},
            product_id     INTEGER NOT NULL,
            lang           {$text} NOT NULL DEFAULT 'en',
            criteria_name  {$text} NOT NULL,
            criteria_value {$text} NOT NULL,
            numeric_val    REAL,
            sort_order     INTEGER DEFAULT 0,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )");
        MigrationSql::index($db, $isSqlite, 'product_specifications', 'idx_spec_product', 'product_id, lang');
        MigrationSql::index($db, $isSqlite, 'product_specifications', 'idx_spec_criteria', 'criteria_name, criteria_value, product_id');
        MigrationSql::index($db, $isSqlite, 'product_specifications', 'idx_spec_numeric', 'criteria_name, numeric_val');

        // Attribute Definitions / Options / Category Attributes
        $db->exec("CREATE TABLE IF NOT EXISTS attribute_definitions (
            id         INTEGER PRIMARY KEY{$autoinc},
            name       {$text} NOT NULL,
            slug       {$text} NOT NULL UNIQUE,
            type       {$text} NOT NULL DEFAULT 'enum',
            unit       {$text} {$defaultEmpty},
            sort_order INTEGER DEFAULT 0
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS attribute_options (
            id           INTEGER PRIMARY KEY{$autoinc},
            attribute_id INTEGER NOT NULL,
            value        {$text} NOT NULL,
            sort_order   INTEGER DEFAULT 0,
            FOREIGN KEY (attribute_id) REFERENCES attribute_definitions(id) ON DELETE CASCADE
        )");
        MigrationSql::index($db, $isSqlite, 'attribute_options', 'idx_attropt_attr', 'attribute_id, sort_order');
        $db->exec("CREATE TABLE IF NOT EXISTS category_attributes (
            category_id  INTEGER NOT NULL,
            attribute_id INTEGER NOT NULL,
            PRIMARY KEY (category_id, attribute_id),
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
            FOREIGN KEY (attribute_id) REFERENCES attribute_definitions(id) ON DELETE CASCADE
        )");

        // Product OE Numbers
        $db->exec("CREATE TABLE IF NOT EXISTS product_oe_numbers (
            id              INTEGER PRIMARY KEY{$autoinc},
            product_id      INTEGER NOT NULL,
            oem_brand       {$text} NOT NULL,
            oem_number      {$text} NOT NULL,
            oem_number_norm {$text} NOT NULL {$defaultEmpty},
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )");
        MigrationSql::index($db, $isSqlite, 'product_oe_numbers', 'idx_oe_product', 'product_id');
        MigrationSql::index($db, $isSqlite, 'product_oe_numbers', 'idx_oe_number', 'oem_number, oem_brand');
        MigrationSql::index($db, $isSqlite, 'product_oe_numbers', 'idx_oe_norm', 'oem_number_norm');

        if ($isSqlite) {
            $oeFtsExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='oe_fts'")->fetch();
            if (!$oeFtsExists) {
                $db->exec("CREATE VIRTUAL TABLE oe_fts USING fts5(oem_number_norm, content=product_oe_numbers, content_rowid=id, tokenize='unicode61')");
                $db->exec("CREATE TRIGGER IF NOT EXISTS oe_ai AFTER INSERT ON product_oe_numbers BEGIN
                    INSERT INTO oe_fts(rowid, oem_number_norm) VALUES (new.id, new.oem_number_norm);
                END");
                $db->exec("CREATE TRIGGER IF NOT EXISTS oe_ad AFTER DELETE ON product_oe_numbers BEGIN
                    INSERT INTO oe_fts(oe_fts, rowid, oem_number_norm) VALUES ('delete', old.id, old.oem_number_norm);
                END");
            }
        }

        // Product Vehicles
        $db->exec("CREATE TABLE IF NOT EXISTS product_vehicles (
            id              INTEGER PRIMARY KEY{$autoinc},
            product_id      INTEGER NOT NULL,
            ktype           INTEGER,
            manufacturer    {$text} NOT NULL,
            model           {$text} NOT NULL,
            type_name       {$text} {$defaultEmpty},
            year_from       INTEGER,
            year_to         INTEGER,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )");
        MigrationSql::index($db, $isSqlite, 'product_vehicles', 'idx_pv_product', 'product_id');
        MigrationSql::index($db, $isSqlite, 'product_vehicles', 'idx_pv_ktype', 'ktype');
        MigrationSql::index($db, $isSqlite, 'product_vehicles', 'idx_pv_mfr', 'manufacturer, model');

        // CMS Pages
        $db->exec("CREATE TABLE IF NOT EXISTS pages (
            id           INTEGER PRIMARY KEY{$autoinc},
            slug         {$text} NOT NULL UNIQUE,
            title        {$text} NOT NULL,
            body         {$long} NOT NULL {$defaultEmpty},
            status       {$text} NOT NULL DEFAULT 'active',
            translations {$long} {$defaultObject},
            created_at   {$text} DEFAULT ({$now}),
            updated_at   {$text} DEFAULT ({$now})
        )");
        MigrationSql::index($db, $isSqlite, 'pages', 'idx_pages_slug', 'slug');

        // Warehouses + warehouse_stock + feeds
        $db->exec("CREATE TABLE IF NOT EXISTS warehouses (
            id          INTEGER PRIMARY KEY{$autoinc},
            code        {$text} NOT NULL UNIQUE,
            name        {$text} NOT NULL,
            priority    INTEGER NOT NULL DEFAULT 0,
            is_dropship INTEGER NOT NULL DEFAULT 0,
            is_default  INTEGER NOT NULL DEFAULT 0,
            contact     {$long} {$defaultEmpty},
            notes       {$long} {$defaultEmpty},
            created_at  {$text} DEFAULT ({$now})
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS warehouse_stock (
            id           INTEGER PRIMARY KEY{$autoinc},
            warehouse_id INTEGER NOT NULL,
            sku          {$text} NOT NULL,
            qty          INTEGER NOT NULL DEFAULT 0,
            reserved     INTEGER NOT NULL DEFAULT 0,
            updated_at   {$text} DEFAULT ({$now}),
            FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
            UNIQUE(warehouse_id, sku)
        )");
        MigrationSql::index($db, $isSqlite, 'warehouse_stock', 'idx_wstock_sku', 'sku');
        MigrationSql::index($db, $isSqlite, 'warehouse_stock', 'idx_wstock_wh', 'warehouse_id');

        $db->exec("CREATE TABLE IF NOT EXISTS feeds (
            id           INTEGER PRIMARY KEY{$autoinc},
            code         {$text} NOT NULL UNIQUE,
            name         {$text} NOT NULL,
            warehouse_id INTEGER NOT NULL,
            url          {$long} NOT NULL,
            format       {$text} NOT NULL DEFAULT 'xml',
            field_map    {$long} NOT NULL {$defaultObject},
            import_mode  {$text} NOT NULL DEFAULT 'upsert',
            schedule     {$text} NOT NULL DEFAULT '0 */6 * * *',
            enabled      INTEGER NOT NULL DEFAULT 1,
            last_sync    {$text} DEFAULT NULL,
            last_status  {$text} DEFAULT NULL,
            last_count   INTEGER DEFAULT 0,
            created_at   {$text} DEFAULT ({$now}),
            FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
        )");

        // Shipping rates + labels
        $db->exec("CREATE TABLE IF NOT EXISTS shipping_rates (
            id            INTEGER PRIMARY KEY{$autoinc},
            carrier       {$text} NOT NULL,
            country       {$text} NOT NULL,
            weight_from_g INTEGER NOT NULL DEFAULT 0,
            weight_to_g   INTEGER NOT NULL DEFAULT 999999,
            price         INTEGER NOT NULL,
            cod_surcharge INTEGER NOT NULL DEFAULT 0,
            created_at    {$text} DEFAULT ({$now})
        )");
        MigrationSql::index($db, $isSqlite, 'shipping_rates', 'idx_srate_lookup', 'carrier, country, weight_from_g, weight_to_g');

        $db->exec("CREATE TABLE IF NOT EXISTS shipping_labels (
            id          INTEGER PRIMARY KEY{$autoinc},
            order_id    INTEGER NOT NULL,
            carrier     {$text} NOT NULL,
            awb         {$text} NOT NULL UNIQUE,
            status      {$text} NOT NULL DEFAULT 'created',
            label_data  {$long} NOT NULL {$defaultEmpty},
            created_at  {$text} DEFAULT ({$now}),
            updated_at  {$text} DEFAULT ({$now}),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )");
        MigrationSql::index($db, $isSqlite, 'shipping_labels', 'idx_slabel_order', 'order_id');
        MigrationSql::index($db, $isSqlite, 'shipping_labels', 'idx_slabel_awb', 'awb');

        // Customers + addresses + reset tokens
        $db->exec("CREATE TABLE IF NOT EXISTS customers (
            id                INTEGER PRIMARY KEY{$autoinc},
            email             {$text} NOT NULL UNIQUE,
            password_hash     {$text} NOT NULL,
            name              {$text} NOT NULL {$defaultEmpty},
            phone             {$text} {$defaultEmpty},
            locale            {$text} NOT NULL DEFAULT 'en',
            status            {$text} NOT NULL DEFAULT 'active',
            email_verified_at {$text} DEFAULT NULL,
            last_login_at     {$text} DEFAULT NULL,
            created_at        {$text} DEFAULT ({$now}),
            updated_at        {$text} DEFAULT ({$now})
        )");
        MigrationSql::index($db, $isSqlite, 'customers', 'idx_cust_email', 'email');

        $db->exec("CREATE TABLE IF NOT EXISTS customer_addresses (
            id          INTEGER PRIMARY KEY{$autoinc},
            customer_id INTEGER NOT NULL,
            type        {$text} NOT NULL DEFAULT 'shipping',
            is_default  INTEGER NOT NULL DEFAULT 0,
            name        {$text} NOT NULL {$defaultEmpty},
            company     {$text} {$defaultEmpty},
            vat_id      {$text} {$defaultEmpty},
            street      {$text} NOT NULL {$defaultEmpty},
            city        {$text} NOT NULL {$defaultEmpty},
            zip         {$text} NOT NULL {$defaultEmpty},
            country     {$text} NOT NULL {$defaultEmpty},
            phone       {$text} {$defaultEmpty},
            created_at  {$text} DEFAULT ({$now}),
            updated_at  {$text} DEFAULT ({$now}),
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )");
        MigrationSql::index($db, $isSqlite, 'customer_addresses', 'idx_addr_cust', 'customer_id, type');

        $db->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id          INTEGER PRIMARY KEY{$autoinc},
            customer_id INTEGER NOT NULL,
            token       {$text} NOT NULL UNIQUE,
            expires_at  {$text} NOT NULL,
            used_at     {$text} DEFAULT NULL,
            created_at  {$text} DEFAULT ({$now}),
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )");
        MigrationSql::index($db, $isSqlite, 'password_reset_tokens', 'idx_prt_token', 'token');

        // Default warehouse seed
        $wCount = (int)$db->query("SELECT COUNT(*) FROM warehouses")->fetchColumn();
        if ($wCount === 0) {
            $db->exec("INSERT INTO warehouses (code, name, priority, is_default) VALUES ('main', 'Hlavný sklad', 10, 1)");
        }
    }
};
