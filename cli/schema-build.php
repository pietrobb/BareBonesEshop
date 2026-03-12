<?php
/**
 * BareBones Shop — Schema Builder
 * Reads catalog.json + stores.json → generates SQLite/MySQL schema, creates DB tables.
 *
 * Usage: php cli/schema-build.php [--fresh]
 *   --fresh  Drop all tables and recreate (dev only!)
 */
require_once __DIR__ . '/../src/Db.php';

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
        'processed_events', 'audit_log', 'order_items', 'orders',
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
    slug          TEXT UNIQUE NOT NULL,
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
    customer_name  TEXT NOT NULL,
    customer_email TEXT NOT NULL,
    customer_phone TEXT DEFAULT '',
    shipping_addr  TEXT DEFAULT '{}',
    payment_method TEXT NOT NULL,
    payment_id     TEXT DEFAULT '',
    subtotal       INTEGER NOT NULL,
    shipping_cost  INTEGER NOT NULL DEFAULT 0,
    tax_amount     INTEGER NOT NULL DEFAULT 0,
    total          INTEGER NOT NULL,
    currency       TEXT NOT NULL DEFAULT 'eur',
    notes          TEXT DEFAULT '',
    ip_address     TEXT DEFAULT '',
    created_at     TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . "),
    updated_at     TEXT DEFAULT (" . ($isSqlite ? "datetime('now')" : "NOW()") . ")
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_ord_status ON orders(status, created_at)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_ord_email ON orders(customer_email)");
echo "  [OK] orders\n";

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

echo "\nSchema build complete!\n";

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
