<?php
/**
 * BareBones Shop — Schema Builder
 * Reads catalog.json + stores.json → generates SQLite/MySQL schema, creates DB tables.
 *
 * Usage: php cli/schema-build.php [--fresh]
 *   --fresh  Drop all tables and recreate (dev only!)
 */
require_once __DIR__ . '/../src/autoload.php';

$fresh = in_array('--fresh', $argv ?? []);

echo "BareBones Shop — Schema Builder\n";
echo "================================\n\n";

// Load JSON definitions
$catalog = json_decode(file_get_contents(__DIR__ . '/../catalog.json'), true);
if (!$catalog) { echo "ERROR: Cannot read catalog.json\n"; exit(1); }

$stores = json_decode(file_get_contents(__DIR__ . '/../stores.json'), true);
if (!$stores) { echo "ERROR: Cannot read stores.json\n"; exit(1); }

echo "Loaded: " . count($catalog['product_types']) . " product types, "
    . count($stores['stores']) . " stores\n\n";

$db = Db::get();
$isSqlite = Db::isSqlite();

echo "Database: " . ($isSqlite ? 'SQLite' : 'MySQL') . "\n";

if ($fresh) {
    echo "FRESH mode: dropping all tables...\n";
    $tables = [
        'shipping_labels', 'shipping_rates',
        'abandoned_carts', 'coupon_uses', 'processed_events', 'payment_transactions', 'audit_log', 'order_items', 'orders',
        'product_vehicles', 'product_oe_numbers', 'product_specifications',
        'slug_registry', 'variants', 'product_attrs', 'products',
        'categories',
    ];
    if ($isSqlite) {
        $tables[] = 'products_fts';
    }
    foreach ($tables as $t) {
        $db->exec("DROP TABLE IF EXISTS {$t}");
    }
    echo "Done.\n\n";
}

// ============================================================
// Create tables
// ============================================================

echo "Creating tables...\n";

// Categories
$db->exec("CREATE TABLE IF NOT EXISTS categories (
    id            INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
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
    // MySQL: use a generated column or composite unique; parent_id NULL → 0 for uniqueness
    try {
        $db->exec("ALTER TABLE categories ADD COLUMN parent_id_uniq INT GENERATED ALWAYS AS (COALESCE(parent_id, 0)) STORED");
        $db->exec("CREATE UNIQUE INDEX idx_cat_slug_parent ON categories(slug(191), parent_id_uniq)");
    } catch (\PDOException $e) {
        // Already exists — safe to ignore on re-run
    }
}
$db->exec("CREATE INDEX IF NOT EXISTS idx_cat_parent ON categories(parent_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_cat_path ON categories(path)");
echo "  [OK] categories\n";

// Products
$db->exec("CREATE TABLE IF NOT EXISTS products (
    id            INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
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
    created_at    TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    updated_at    TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    FOREIGN KEY (category_id) REFERENCES categories(id)
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_prod_category ON products(category_id, status, price)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_prod_status ON products(status)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_prod_price ON products(price)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_prod_updated ON products(updated_at)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_prod_created ON products(status, created_at DESC)");
echo "  [OK] products\n";

// FTS — different per driver
if ($isSqlite) {
    // Check if FTS table already exists
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
        echo "  [OK] products_fts (FTS5 + triggers)\n";
    } else {
        echo "  [OK] products_fts (already exists)\n";
    }
} else {
    // MySQL: FULLTEXT indexes on products table
    try {
        $db->exec("ALTER TABLE products ADD FULLTEXT idx_ft_products (name, sku, summary, tags)");
        $db->exec("ALTER TABLE products ADD FULLTEXT idx_ft_filters (filter_tags)");
        echo "  [OK] FULLTEXT indexes\n";
    } catch (PDOException $e) {
        // Already exists
        echo "  [OK] FULLTEXT indexes (already exist)\n";
    }
}

// Product Attributes — faceted filtering
$db->exec("CREATE TABLE IF NOT EXISTS product_attrs (
    product_id  INTEGER NOT NULL,
    attr_name   TEXT NOT NULL,
    attr_value  TEXT NOT NULL,
    numeric_val REAL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_pa_lookup ON product_attrs(attr_name, attr_value, product_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_pa_product ON product_attrs(product_id)");
echo "  [OK] product_attrs\n";

// Variants
$db->exec("CREATE TABLE IF NOT EXISTS variants (
    id             INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
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
echo "  [OK] variants\n";

// Slug Registry — uniqueness per locale
$db->exec("CREATE TABLE IF NOT EXISTS slug_registry (
    slug      TEXT NOT NULL,
    locale    TEXT NOT NULL,
    entity    TEXT NOT NULL,
    entity_id INTEGER NOT NULL,
    PRIMARY KEY (slug, locale, entity)
)");
echo "  [OK] slug_registry\n";

// Orders
$db->exec("CREATE TABLE IF NOT EXISTS orders (
    id             INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    order_number   TEXT UNIQUE NOT NULL,
    status         TEXT NOT NULL DEFAULT 'pending',
    payment_status TEXT NOT NULL DEFAULT 'pending',
    customer_name  TEXT NOT NULL,
    customer_email TEXT NOT NULL,
    customer_phone TEXT DEFAULT '',
    shipping_addr  TEXT DEFAULT '{}',
    payment_method TEXT NOT NULL,
    payment_id     TEXT DEFAULT '',
    subtotal       INTEGER NOT NULL,
    discount_amount INTEGER NOT NULL DEFAULT 0,
    shipping_cost  INTEGER NOT NULL DEFAULT 0,
    tax_amount     INTEGER NOT NULL DEFAULT 0,
    total          INTEGER NOT NULL,
    currency       TEXT NOT NULL DEFAULT 'eur',
    notes          TEXT DEFAULT '',
    ip_address     TEXT DEFAULT '',
    erp_id         TEXT DEFAULT NULL,
    erp_status     TEXT DEFAULT NULL,
    erp_synced_at  TEXT DEFAULT NULL,
    erp_error      TEXT DEFAULT NULL,
    created_at     TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    updated_at     TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . ")
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_ord_status ON orders(status, created_at)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_ord_email ON orders(customer_email)");
// Migration: add payment_status to existing databases
try {
    $db->exec("ALTER TABLE orders ADD COLUMN payment_status TEXT NOT NULL DEFAULT 'pending'");
} catch (\PDOException $e) { /* already exists */ }
echo "  [OK] orders\n";

// Payment Transactions — immutable log of every payment event
$db->exec("CREATE TABLE IF NOT EXISTS payment_transactions (
    id              INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    order_id        INTEGER NOT NULL,
    provider        TEXT NOT NULL,
    provider_ref    TEXT NOT NULL DEFAULT '',
    status          TEXT NOT NULL,
    amount          INTEGER NOT NULL,
    currency        TEXT NOT NULL DEFAULT 'eur',
    idempotency_key TEXT NOT NULL UNIQUE,
    raw_response    TEXT NOT NULL DEFAULT '{}',
    created_at      TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    updated_at      TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_ptx_order ON payment_transactions(order_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_ptx_ref ON payment_transactions(provider, provider_ref)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_ptx_status ON payment_transactions(provider, status)");
echo "  [OK] payment_transactions\n";

// Order Items
$db->exec("CREATE TABLE IF NOT EXISTS order_items (
    id          INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
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
echo "  [OK] order_items\n";

// Audit Log
$db->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id         INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    entity     TEXT NOT NULL,
    entity_id  INTEGER NOT NULL,
    action     TEXT NOT NULL,
    old_value  TEXT,
    new_value  TEXT,
    note       TEXT,
    actor      TEXT NOT NULL,
    ip_address TEXT,
    created_at TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . ")
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log(entity, entity_id, created_at)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_audit_actor ON audit_log(actor, created_at)");
echo "  [OK] audit_log\n";

// Processed Events — idempotency
$db->exec("CREATE TABLE IF NOT EXISTS processed_events (
    event_id   TEXT PRIMARY KEY,
    created_at TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . ")
)");
echo "  [OK] processed_events\n";

// Coupon Usage Tracking
$db->exec("CREATE TABLE IF NOT EXISTS coupon_uses (
    id             INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    coupon_code    TEXT NOT NULL,
    order_id       INTEGER NOT NULL,
    customer_email TEXT NOT NULL,
    discount       INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    FOREIGN KEY (order_id) REFERENCES orders(id)
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_coupon_code ON coupon_uses(coupon_code)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_coupon_email ON coupon_uses(coupon_code, customer_email)");
echo "  [OK] coupon_uses\n";

// Abandoned Carts — for recovery emails
$db->exec("CREATE TABLE IF NOT EXISTS abandoned_carts (
    id             INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    session_id     TEXT NOT NULL UNIQUE,
    customer_email TEXT,
    cart_data      TEXT NOT NULL DEFAULT '{}',
    created_at     TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    reminded_at    TEXT
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_ac_email ON abandoned_carts(customer_email, reminded_at)");
echo "  [OK] abandoned_carts\n";

// Product Specifications — parametric search (TecDoc-style)
$db->exec("CREATE TABLE IF NOT EXISTS product_specifications (
    id          INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    product_id  INTEGER NOT NULL,
    lang        TEXT NOT NULL DEFAULT 'sk',
    criteria_name  TEXT NOT NULL,
    criteria_value TEXT NOT NULL,
    numeric_val    REAL,
    sort_order     INTEGER DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_spec_product ON product_specifications(product_id, lang)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_spec_criteria ON product_specifications(criteria_name, criteria_value, product_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_spec_numeric ON product_specifications(criteria_name, numeric_val)");
echo "  [OK] product_specifications\n";

// Attribute Definitions — configurable attribute types per category
$db->exec("CREATE TABLE IF NOT EXISTS attribute_definitions (
    id         INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    name       TEXT NOT NULL,
    slug       TEXT NOT NULL UNIQUE,
    type       TEXT NOT NULL DEFAULT 'enum',
    unit       TEXT DEFAULT '',
    sort_order INTEGER DEFAULT 0
)");
echo "  [OK] attribute_definitions\n";

// Attribute Options — values for enum attributes
$db->exec("CREATE TABLE IF NOT EXISTS attribute_options (
    id           INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    attribute_id INTEGER NOT NULL,
    value        TEXT NOT NULL,
    sort_order   INTEGER DEFAULT 0,
    FOREIGN KEY (attribute_id) REFERENCES attribute_definitions(id) ON DELETE CASCADE
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_attropt_attr ON attribute_options(attribute_id, sort_order)");
echo "  [OK] attribute_options\n";

// Category Attributes — which attributes apply to which categories
$db->exec("CREATE TABLE IF NOT EXISTS category_attributes (
    category_id  INTEGER NOT NULL,
    attribute_id INTEGER NOT NULL,
    PRIMARY KEY (category_id, attribute_id),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (attribute_id) REFERENCES attribute_definitions(id) ON DELETE CASCADE
)");
echo "  [OK] category_attributes\n";

// Product OE Numbers — cross-references
$db->exec("CREATE TABLE IF NOT EXISTS product_oe_numbers (
    id              INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    product_id      INTEGER NOT NULL,
    oem_brand       TEXT NOT NULL,
    oem_number      TEXT NOT NULL,
    oem_number_norm TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_oe_product ON product_oe_numbers(product_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_oe_number ON product_oe_numbers(oem_number, oem_brand)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_oe_norm ON product_oe_numbers(oem_number_norm)");
echo "  [OK] product_oe_numbers\n";

// OE FTS for fast substring search
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
        echo "  [OK] oe_fts (FTS5 for OE search)\n";
    } else {
        echo "  [OK] oe_fts (already exists)\n";
    }
}

// Product Vehicles — compatibility
$db->exec("CREATE TABLE IF NOT EXISTS product_vehicles (
    id              INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
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
echo "  [OK] product_vehicles\n";

// CMS Pages — static content pages
$db->exec("CREATE TABLE IF NOT EXISTS pages (
    id         INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    slug       TEXT NOT NULL UNIQUE,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    status     TEXT NOT NULL DEFAULT 'active',
    translations TEXT DEFAULT '{}',
    created_at TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    updated_at TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . ")
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_pages_slug ON pages(slug)");
echo "  [OK] pages\n";

// Warehouses
$db->exec("CREATE TABLE IF NOT EXISTS warehouses (
    id          INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    code        TEXT NOT NULL UNIQUE,
    name        TEXT NOT NULL,
    priority    INTEGER NOT NULL DEFAULT 0,
    is_dropship INTEGER NOT NULL DEFAULT 0,
    is_default  INTEGER NOT NULL DEFAULT 0,
    contact     TEXT DEFAULT '',
    notes       TEXT DEFAULT '',
    created_at  TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . ")
)");
echo "  [OK] warehouses\n";

// Warehouse stock — per-SKU stock in each warehouse
$db->exec("CREATE TABLE IF NOT EXISTS warehouse_stock (
    id           INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    warehouse_id INTEGER NOT NULL,
    sku          TEXT NOT NULL,
    qty          INTEGER NOT NULL DEFAULT 0,
    reserved     INTEGER NOT NULL DEFAULT 0,
    updated_at   TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
    UNIQUE(warehouse_id, sku)
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_wstock_sku ON warehouse_stock(sku)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_wstock_wh ON warehouse_stock(warehouse_id)");
echo "  [OK] warehouse_stock\n";

// Feeds — external data sources (XML/CSV) linked to a warehouse
$db->exec("CREATE TABLE IF NOT EXISTS feeds (
    id           INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
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
    created_at   TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
)");
echo "  [OK] feeds\n";

// Shipping Rates — tiered pricing per carrier/country/weight
$db->exec("CREATE TABLE IF NOT EXISTS shipping_rates (
    id             INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    carrier        TEXT NOT NULL,
    country        TEXT NOT NULL,
    weight_from_g  INTEGER NOT NULL DEFAULT 0,
    weight_to_g    INTEGER NOT NULL DEFAULT 999999,
    price          INTEGER NOT NULL,
    cod_surcharge  INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . ")
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_srate_lookup ON shipping_rates(carrier, country, weight_from_g, weight_to_g)");
echo "  [OK] shipping_rates\n";

// Shipping Labels — AWB/tracking records
$db->exec("CREATE TABLE IF NOT EXISTS shipping_labels (
    id          INTEGER PRIMARY KEY" . ($isSqlite ? "" : " AUTO_INCREMENT") . ",
    order_id    INTEGER NOT NULL,
    carrier     TEXT NOT NULL,
    awb         TEXT NOT NULL UNIQUE,
    status      TEXT NOT NULL DEFAULT 'created',
    label_data  TEXT NOT NULL DEFAULT '',
    created_at  TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    updated_at  TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_slabel_order ON shipping_labels(order_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_slabel_awb   ON shipping_labels(awb)");
echo "  [OK] shipping_labels\n";

// Migration: add tracking_number and carrier to existing orders
try { $db->exec("ALTER TABLE orders ADD COLUMN tracking_number TEXT DEFAULT NULL"); } catch (\PDOException $e) {}
try { $db->exec("ALTER TABLE orders ADD COLUMN carrier TEXT DEFAULT NULL"); } catch (\PDOException $e) {}
echo "  [OK] orders: tracking_number + carrier columns\n";

// Insert default warehouse if none exists
$wCount = (int)$db->query("SELECT COUNT(*) FROM warehouses")->fetchColumn();
if ($wCount === 0) {
    $db->exec("INSERT INTO warehouses (code, name, priority, is_default) VALUES ('main', 'Hlavný sklad', 10, 1)");
    echo "  [OK] default warehouse 'main' created\n";
}

echo "\nSchema build complete!\n";

// ============================================================
// Generate admin forms from catalog.json product types (GAP-H3)
// ============================================================
echo "\nGenerating admin forms...\n";
$formsDir = __DIR__ . '/../forms';
if (!is_dir($formsDir)) mkdir($formsDir, 0750, true);

foreach ($catalog['product_types'] as $typeName => $typeDef) {
    $fields = [
        ['name' => 'sku', 'type' => 'text', 'label' => 'SKU', 'required' => true],
        ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'translatable' => true],
        ['name' => 'slug', 'type' => 'text', 'label' => 'URL Slug'],
        ['name' => 'category', 'type' => 'select', 'label' => 'Category', 'required' => true, 'options_from' => 'categories'],
        ['name' => 'price', 'type' => 'number', 'label' => 'Price (cents)', 'required' => true],
        ['name' => 'compare_price', 'type' => 'number', 'label' => 'Compare Price'],
        ['name' => 'stock', 'type' => 'number', 'label' => 'Stock', 'required' => true],
        ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'options' => ['active', 'draft', 'archived']],
        ['name' => 'summary', 'type' => 'textarea', 'label' => 'Summary', 'translatable' => true],
        ['name' => 'description', 'type' => 'textarea', 'label' => 'Description', 'translatable' => true],
        ['name' => 'images', 'type' => 'file', 'label' => 'Images', 'multiple' => true],
        ['name' => 'weight_g', 'type' => 'number', 'label' => 'Weight (g)'],
        ['name' => 'tags', 'type' => 'text', 'label' => 'Tags (comma-separated)'],
    ];

    // Type-specific attributes
    foreach ($typeDef['attributes'] ?? [] as $attrName => $attrDef) {
        $field = [
            'name'  => "attr:{$attrName}",
            'type'  => $attrDef['type'] ?? 'text',
            'label' => $attrDef['label'] ?? $attrName,
        ];
        if (!empty($attrDef['options']) && is_array($attrDef['options'])) {
            $field['options'] = $attrDef['options'];
        }
        if (!empty($attrDef['required'])) $field['required'] = true;
        if (!empty($attrDef['filterable'])) $field['filterable'] = true;
        if (!empty($attrDef['translatable'])) $field['translatable'] = true;
        $fields[] = $field;
    }

    $form = [
        'id'           => "admin-{$typeName}",
        'product_type' => $typeName,
        'has_variants'  => $typeDef['has_variants'] ?? false,
        'fields'       => $fields,
    ];
    if (!empty($typeDef['variant_dimensions'])) {
        $form['variant_dimensions'] = $typeDef['variant_dimensions'];
    }
    file_put_contents(
        "{$formsDir}/admin-{$typeName}.json",
        json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    echo "  [OK] forms/admin-{$typeName}.json\n";
}

// ============================================================
// Generate import CSV templates per product type
// ============================================================
echo "\nGenerating import templates...\n";
$importDir = __DIR__ . '/../import';
if (!is_dir($importDir)) mkdir($importDir, 0750, true);

// Collect all store languages
$allLangs = [];
foreach ($stores['stores'] ?? [] as $store) {
    foreach ($store['languages'] ?? [] as $l) $allLangs[$l] = true;
}
$defaultLocale = $stores['default_locale'] ?? 'sk';

foreach ($catalog['product_types'] as $typeName => $typeDef) {
    $headers = ['sku', 'name', 'slug', 'category', 'price', 'compare_price', 'stock', 'status', 'summary', 'description', 'image', 'weight_g', 'tags'];

    foreach ($typeDef['attributes'] ?? [] as $attrName => $attrDef) {
        $headers[] = "attr:{$attrName}";
    }

    // Translation columns
    foreach (array_keys($allLangs) as $l) {
        if ($l !== $defaultLocale) {
            $headers[] = "name_{$l}";
            $headers[] = "summary_{$l}";
            $headers[] = "slug_{$l}";
        }
    }

    $fp = fopen("{$importDir}/template-{$typeName}.csv", 'w');
    fputcsv($fp, $headers);
    fclose($fp);
    echo "  [OK] import/template-{$typeName}.csv\n";
}

// ============================================================
// Summary
// ============================================================
echo "\nProduct types defined:\n";
foreach ($catalog['product_types'] as $typeName => $type) {
    $attrCount = count($type['attributes'] ?? []);
    $hasVariants = ($type['has_variants'] ?? false) ? 'yes' : 'no';
    echo "  - {$typeName}: {$attrCount} attributes, variants: {$hasVariants}\n";
}

echo "\nStores defined:\n";
foreach ($stores['stores'] as $code => $store) {
    $langs = implode(', ', $store['languages']);
    echo "  - {$code}: {$store['name']} ({$store['currency']}, langs: {$langs})\n";
}

echo "\nDone.\n";
