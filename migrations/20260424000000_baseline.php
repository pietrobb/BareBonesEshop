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

        // Categories
        $db->exec("CREATE TABLE IF NOT EXISTS categories (
            id            INTEGER PRIMARY KEY{$autoinc},
            slug          TEXT NOT NULL,
            name          TEXT NOT NULL,
            parent_id     INTEGER,
            path          TEXT NOT NULL DEFAULT '',
            depth         INTEGER NOT NULL DEFAULT 0,
            sort_order    INTEGER DEFAULT 0,
            product_count INTEGER DEFAULT 0,
            translations  TEXT DEFAULT '{}',
            meta          TEXT DEFAULT '{}',
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
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cat_parent ON categories(parent_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cat_path ON categories(path)");

        // Products
        $db->exec("CREATE TABLE IF NOT EXISTS products (
            id            INTEGER PRIMARY KEY{$autoinc},
            sku           TEXT UNIQUE NOT NULL,
            slug          TEXT UNIQUE NOT NULL,
            product_type  TEXT NOT NULL DEFAULT 'simple',
            name          TEXT NOT NULL,
            category_id   INTEGER NOT NULL,
            price         INTEGER NOT NULL,
            compare_price INTEGER,
            currency      TEXT NOT NULL DEFAULT 'eur',
            stock         INTEGER NOT NULL DEFAULT 0,
            status        TEXT NOT NULL DEFAULT 'active',
            images        TEXT DEFAULT '[]',
            summary       TEXT DEFAULT '',
            description   TEXT DEFAULT '',
            attrs         TEXT DEFAULT '{}',
            tags          TEXT DEFAULT '[]',
            filter_tags   TEXT DEFAULT '',
            weight_g      INTEGER DEFAULT 0,
            translations  TEXT DEFAULT '{}',
            created_at    TEXT DEFAULT ({$now}),
            updated_at    TEXT DEFAULT ({$now}),
            FOREIGN KEY (category_id) REFERENCES categories(id)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_prod_category ON products(category_id, status, price)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_prod_status ON products(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_prod_price ON products(price)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_prod_updated ON products(updated_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_prod_created ON products(status, created_at DESC)");

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
            attr_name   TEXT NOT NULL,
            attr_value  TEXT NOT NULL,
            numeric_val REAL,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pa_lookup ON product_attrs(attr_name, attr_value, product_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pa_product ON product_attrs(product_id)");

        // Variants
        $db->exec("CREATE TABLE IF NOT EXISTS variants (
            id             INTEGER PRIMARY KEY{$autoinc},
            product_id     INTEGER NOT NULL,
            sku            TEXT UNIQUE NOT NULL,
            name           TEXT NOT NULL DEFAULT '',
            attrs          TEXT NOT NULL DEFAULT '{}',
            price_delta    INTEGER NOT NULL DEFAULT 0,
            price_absolute INTEGER,
            stock          INTEGER NOT NULL DEFAULT 0,
            sort_order     INTEGER DEFAULT 0,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_var_product ON variants(product_id)");

        // Slug Registry
        $db->exec("CREATE TABLE IF NOT EXISTS slug_registry (
            slug      TEXT NOT NULL,
            locale    TEXT NOT NULL,
            entity    TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            PRIMARY KEY (slug, locale, entity)
        )");

        // Orders
        $db->exec("CREATE TABLE IF NOT EXISTS orders (
            id              INTEGER PRIMARY KEY{$autoinc},
            order_number    TEXT UNIQUE NOT NULL,
            status          TEXT NOT NULL DEFAULT 'pending',
            payment_status  TEXT NOT NULL DEFAULT 'pending',
            customer_id     INTEGER DEFAULT NULL,
            customer_name   TEXT NOT NULL,
            customer_email  TEXT NOT NULL,
            customer_phone  TEXT DEFAULT '',
            shipping_addr   TEXT DEFAULT '{}',
            payment_method  TEXT NOT NULL,
            payment_id      TEXT DEFAULT '',
            subtotal        INTEGER NOT NULL,
            discount_amount INTEGER NOT NULL DEFAULT 0,
            shipping_cost   INTEGER NOT NULL DEFAULT 0,
            tax_amount      INTEGER NOT NULL DEFAULT 0,
            total           INTEGER NOT NULL,
            currency        TEXT NOT NULL DEFAULT 'eur',
            locale          TEXT NOT NULL DEFAULT 'en',
            notes           TEXT DEFAULT '',
            ip_address      TEXT DEFAULT '',
            tracking_number TEXT DEFAULT NULL,
            carrier         TEXT DEFAULT NULL,
            erp_id          TEXT DEFAULT NULL,
            erp_status      TEXT DEFAULT NULL,
            erp_synced_at   TEXT DEFAULT NULL,
            erp_error       TEXT DEFAULT NULL,
            created_at      TEXT DEFAULT ({$now}),
            updated_at      TEXT DEFAULT ({$now})
        )");
        // Migration-style ALTERs for upgrade-from-older-schema paths
        foreach ([
            "ALTER TABLE orders ADD COLUMN payment_status TEXT NOT NULL DEFAULT 'pending'",
            "ALTER TABLE orders ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'",
            "ALTER TABLE orders ADD COLUMN tracking_number TEXT DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN carrier TEXT DEFAULT NULL",
            "ALTER TABLE orders ADD COLUMN customer_id INTEGER DEFAULT NULL",
        ] as $alter) {
            try { $db->exec($alter); } catch (\PDOException $e) { /* already exists */ }
        }
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ord_status ON orders(status, created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ord_email ON orders(customer_email)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ord_customer ON orders(customer_id)");

        // Email Log
        $db->exec("CREATE TABLE IF NOT EXISTS email_log (
            id         INTEGER PRIMARY KEY{$autoinc},
            order_id   INTEGER DEFAULT NULL,
            recipient  TEXT NOT NULL,
            template   TEXT NOT NULL,
            locale     TEXT NOT NULL DEFAULT 'en',
            subject    TEXT NOT NULL DEFAULT '',
            adapter    TEXT NOT NULL DEFAULT '',
            status     TEXT NOT NULL DEFAULT 'pending',
            error      TEXT DEFAULT '',
            payload    TEXT DEFAULT '{}',
            sent_at    TEXT DEFAULT NULL,
            created_at TEXT DEFAULT ({$now})
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_eml_order ON email_log(order_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_eml_recipient ON email_log(recipient, created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_eml_status ON email_log(status, created_at)");

        // Payment Transactions
        $db->exec("CREATE TABLE IF NOT EXISTS payment_transactions (
            id              INTEGER PRIMARY KEY{$autoinc},
            order_id        INTEGER NOT NULL,
            provider        TEXT NOT NULL,
            provider_ref    TEXT NOT NULL DEFAULT '',
            status          TEXT NOT NULL,
            amount          INTEGER NOT NULL,
            currency        TEXT NOT NULL DEFAULT 'eur',
            idempotency_key TEXT NOT NULL UNIQUE,
            raw_response    TEXT NOT NULL DEFAULT '{}',
            created_at      TEXT DEFAULT ({$now}),
            updated_at      TEXT DEFAULT ({$now}),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ptx_order ON payment_transactions(order_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ptx_ref ON payment_transactions(provider, provider_ref)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ptx_status ON payment_transactions(provider, status)");

        // Order Items
        $db->exec("CREATE TABLE IF NOT EXISTS order_items (
            id          INTEGER PRIMARY KEY{$autoinc},
            order_id    INTEGER NOT NULL,
            product_id  INTEGER NOT NULL,
            variant_id  INTEGER,
            sku         TEXT NOT NULL,
            name        TEXT NOT NULL,
            quantity    INTEGER NOT NULL,
            unit_price  INTEGER NOT NULL,
            total       INTEGER NOT NULL,
            attrs       TEXT DEFAULT '{}',
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_oi_order ON order_items(order_id)");

        // Audit Log
        $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id         INTEGER PRIMARY KEY{$autoinc},
            entity     TEXT NOT NULL,
            entity_id  INTEGER NOT NULL,
            action     TEXT NOT NULL,
            old_value  TEXT,
            new_value  TEXT,
            note       TEXT,
            actor      TEXT NOT NULL,
            ip_address TEXT,
            created_at TEXT DEFAULT ({$now})
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log(entity, entity_id, created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_actor ON audit_log(actor, created_at)");

        // Processed Events (idempotency)
        $db->exec("CREATE TABLE IF NOT EXISTS processed_events (
            event_id   TEXT PRIMARY KEY,
            created_at TEXT DEFAULT ({$now})
        )");

        // Coupon Usage
        $db->exec("CREATE TABLE IF NOT EXISTS coupon_uses (
            id             INTEGER PRIMARY KEY{$autoinc},
            coupon_code    TEXT NOT NULL,
            order_id       INTEGER NOT NULL,
            customer_email TEXT NOT NULL,
            discount       INTEGER NOT NULL DEFAULT 0,
            created_at     TEXT DEFAULT ({$now}),
            FOREIGN KEY (order_id) REFERENCES orders(id)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_coupon_code ON coupon_uses(coupon_code)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_coupon_email ON coupon_uses(coupon_code, customer_email)");

        // Abandoned Carts
        $db->exec("CREATE TABLE IF NOT EXISTS abandoned_carts (
            id             INTEGER PRIMARY KEY{$autoinc},
            session_id     TEXT NOT NULL UNIQUE,
            customer_email TEXT,
            cart_data      TEXT NOT NULL DEFAULT '{}',
            created_at     TEXT DEFAULT ({$now}),
            reminded_at    TEXT
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ac_email ON abandoned_carts(customer_email, reminded_at)");

        // Product Specifications
        $db->exec("CREATE TABLE IF NOT EXISTS product_specifications (
            id             INTEGER PRIMARY KEY{$autoinc},
            product_id     INTEGER NOT NULL,
            lang           TEXT NOT NULL DEFAULT 'en',
            criteria_name  TEXT NOT NULL,
            criteria_value TEXT NOT NULL,
            numeric_val    REAL,
            sort_order     INTEGER DEFAULT 0,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_spec_product ON product_specifications(product_id, lang)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_spec_criteria ON product_specifications(criteria_name, criteria_value, product_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_spec_numeric ON product_specifications(criteria_name, numeric_val)");

        // Attribute Definitions / Options / Category Attributes
        $db->exec("CREATE TABLE IF NOT EXISTS attribute_definitions (
            id         INTEGER PRIMARY KEY{$autoinc},
            name       TEXT NOT NULL,
            slug       TEXT NOT NULL UNIQUE,
            type       TEXT NOT NULL DEFAULT 'enum',
            unit       TEXT DEFAULT '',
            sort_order INTEGER DEFAULT 0
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS attribute_options (
            id           INTEGER PRIMARY KEY{$autoinc},
            attribute_id INTEGER NOT NULL,
            value        TEXT NOT NULL,
            sort_order   INTEGER DEFAULT 0,
            FOREIGN KEY (attribute_id) REFERENCES attribute_definitions(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_attropt_attr ON attribute_options(attribute_id, sort_order)");
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
            oem_brand       TEXT NOT NULL,
            oem_number      TEXT NOT NULL,
            oem_number_norm TEXT NOT NULL DEFAULT '',
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_oe_product ON product_oe_numbers(product_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_oe_number ON product_oe_numbers(oem_number, oem_brand)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_oe_norm ON product_oe_numbers(oem_number_norm)");

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
            manufacturer    TEXT NOT NULL,
            model           TEXT NOT NULL,
            type_name       TEXT DEFAULT '',
            year_from       INTEGER,
            year_to         INTEGER,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pv_product ON product_vehicles(product_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pv_ktype ON product_vehicles(ktype)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pv_mfr ON product_vehicles(manufacturer, model)");

        // CMS Pages
        $db->exec("CREATE TABLE IF NOT EXISTS pages (
            id           INTEGER PRIMARY KEY{$autoinc},
            slug         TEXT NOT NULL UNIQUE,
            title        TEXT NOT NULL,
            body         TEXT NOT NULL DEFAULT '',
            status       TEXT NOT NULL DEFAULT 'active',
            translations TEXT DEFAULT '{}',
            created_at   TEXT DEFAULT ({$now}),
            updated_at   TEXT DEFAULT ({$now})
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pages_slug ON pages(slug)");

        // Warehouses + warehouse_stock + feeds
        $db->exec("CREATE TABLE IF NOT EXISTS warehouses (
            id          INTEGER PRIMARY KEY{$autoinc},
            code        TEXT NOT NULL UNIQUE,
            name        TEXT NOT NULL,
            priority    INTEGER NOT NULL DEFAULT 0,
            is_dropship INTEGER NOT NULL DEFAULT 0,
            is_default  INTEGER NOT NULL DEFAULT 0,
            contact     TEXT DEFAULT '',
            notes       TEXT DEFAULT '',
            created_at  TEXT DEFAULT ({$now})
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS warehouse_stock (
            id           INTEGER PRIMARY KEY{$autoinc},
            warehouse_id INTEGER NOT NULL,
            sku          TEXT NOT NULL,
            qty          INTEGER NOT NULL DEFAULT 0,
            reserved     INTEGER NOT NULL DEFAULT 0,
            updated_at   TEXT DEFAULT ({$now}),
            FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
            UNIQUE(warehouse_id, sku)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_wstock_sku ON warehouse_stock(sku)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_wstock_wh ON warehouse_stock(warehouse_id)");

        $db->exec("CREATE TABLE IF NOT EXISTS feeds (
            id           INTEGER PRIMARY KEY{$autoinc},
            code         TEXT NOT NULL UNIQUE,
            name         TEXT NOT NULL,
            warehouse_id INTEGER NOT NULL,
            url          TEXT NOT NULL,
            format       TEXT NOT NULL DEFAULT 'xml',
            field_map    TEXT NOT NULL DEFAULT '{}',
            import_mode  TEXT NOT NULL DEFAULT 'upsert',
            schedule     TEXT NOT NULL DEFAULT '0 */6 * * *',
            enabled      INTEGER NOT NULL DEFAULT 1,
            last_sync    TEXT DEFAULT NULL,
            last_status  TEXT DEFAULT NULL,
            last_count   INTEGER DEFAULT 0,
            created_at   TEXT DEFAULT ({$now}),
            FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
        )");

        // Shipping rates + labels
        $db->exec("CREATE TABLE IF NOT EXISTS shipping_rates (
            id            INTEGER PRIMARY KEY{$autoinc},
            carrier       TEXT NOT NULL,
            country       TEXT NOT NULL,
            weight_from_g INTEGER NOT NULL DEFAULT 0,
            weight_to_g   INTEGER NOT NULL DEFAULT 999999,
            price         INTEGER NOT NULL,
            cod_surcharge INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT DEFAULT ({$now})
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_srate_lookup ON shipping_rates(carrier, country, weight_from_g, weight_to_g)");

        $db->exec("CREATE TABLE IF NOT EXISTS shipping_labels (
            id          INTEGER PRIMARY KEY{$autoinc},
            order_id    INTEGER NOT NULL,
            carrier     TEXT NOT NULL,
            awb         TEXT NOT NULL UNIQUE,
            status      TEXT NOT NULL DEFAULT 'created',
            label_data  TEXT NOT NULL DEFAULT '',
            created_at  TEXT DEFAULT ({$now}),
            updated_at  TEXT DEFAULT ({$now}),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_slabel_order ON shipping_labels(order_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_slabel_awb   ON shipping_labels(awb)");

        // Customers + addresses + reset tokens
        $db->exec("CREATE TABLE IF NOT EXISTS customers (
            id                INTEGER PRIMARY KEY{$autoinc},
            email             TEXT NOT NULL UNIQUE,
            password_hash     TEXT NOT NULL,
            name              TEXT NOT NULL DEFAULT '',
            phone             TEXT DEFAULT '',
            locale            TEXT NOT NULL DEFAULT 'en',
            status            TEXT NOT NULL DEFAULT 'active',
            email_verified_at TEXT DEFAULT NULL,
            last_login_at     TEXT DEFAULT NULL,
            created_at        TEXT DEFAULT ({$now}),
            updated_at        TEXT DEFAULT ({$now})
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cust_email ON customers(email)");

        $db->exec("CREATE TABLE IF NOT EXISTS customer_addresses (
            id          INTEGER PRIMARY KEY{$autoinc},
            customer_id INTEGER NOT NULL,
            type        TEXT NOT NULL DEFAULT 'shipping',
            is_default  INTEGER NOT NULL DEFAULT 0,
            name        TEXT NOT NULL DEFAULT '',
            company     TEXT DEFAULT '',
            vat_id      TEXT DEFAULT '',
            street      TEXT NOT NULL DEFAULT '',
            city        TEXT NOT NULL DEFAULT '',
            zip         TEXT NOT NULL DEFAULT '',
            country     TEXT NOT NULL DEFAULT '',
            phone       TEXT DEFAULT '',
            created_at  TEXT DEFAULT ({$now}),
            updated_at  TEXT DEFAULT ({$now}),
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_addr_cust ON customer_addresses(customer_id, type)");

        $db->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id          INTEGER PRIMARY KEY{$autoinc},
            customer_id INTEGER NOT NULL,
            token       TEXT NOT NULL UNIQUE,
            expires_at  TEXT NOT NULL,
            used_at     TEXT DEFAULT NULL,
            created_at  TEXT DEFAULT ({$now}),
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_prt_token ON password_reset_tokens(token)");

        // Default warehouse seed
        $wCount = (int)$db->query("SELECT COUNT(*) FROM warehouses")->fetchColumn();
        if ($wCount === 0) {
            $db->exec("INSERT INTO warehouses (code, name, priority, is_default) VALUES ('main', 'Hlavný sklad', 10, 1)");
        }
    }
};
