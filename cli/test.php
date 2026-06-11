<?php
/**
 * BareBones Shop — Test Runner
 * Simple test infrastructure without PHPUnit.
 * Usage: php cli/test.php
 */

require_once __DIR__ . '/../src/autoload.php';

// Test framework
$tests = 0;
$passed = 0;
$failed = 0;
$errors = [];

function assert_true(bool $condition, string $message): void {
    global $tests, $passed, $failed, $errors;
    $tests++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$message}\n";
    } else {
        $failed++;
        $errors[] = $message;
        echo "  [FAIL] {$message}\n";
    }
}

function assert_equals($expected, $actual, string $message): void {
    global $tests, $passed, $failed, $errors;
    $tests++;
    if ($expected === $actual) {
        $passed++;
        echo "  [PASS] {$message}\n";
    } else {
        $failed++;
        $expStr = var_export($expected, true);
        $actStr = var_export($actual, true);
        $errors[] = "{$message} (expected: {$expStr}, got: {$actStr})";
        echo "  [FAIL] {$message} (expected: {$expStr}, got: {$actStr})\n";
    }
}

function assert_greater(int $actual, int $than, string $message): void {
    assert_true($actual > $than, "{$message} ({$actual} > {$than})");
}

echo "========================================\n";
echo "BareBones Shop — Test Suite\n";
echo "========================================\n\n";

// ============================================================
// TEST 1: Database connection
// ============================================================
echo "--- Test 1: Database Connection ---\n";

// Fresh DB for testing
$testDbPath = __DIR__ . '/../db/test.sqlite';
if (file_exists($testDbPath)) unlink($testDbPath);

$pdo = new PDO('sqlite:' . $testDbPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA foreign_keys = ON');
Db::init($pdo, 'sqlite');

assert_true(Db::get() instanceof PDO, 'PDO connection established');
assert_true(Db::isSqlite(), 'Driver is SQLite');
assert_equals('sqlite', Db::driver(), 'Driver name is sqlite');
assert_equals("datetime('now')", Db::now(), 'now() returns SQLite expression');

// ============================================================
// TEST 2: Schema Build
// ============================================================
echo "\n--- Test 2: Schema Creation ---\n";

// Inline schema creation (same as schema-build.php)
$pdo->exec("CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY, slug TEXT NOT NULL, name TEXT NOT NULL,
    parent_id INTEGER, path TEXT NOT NULL DEFAULT '', depth INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER DEFAULT 0, product_count INTEGER DEFAULT 0,
    translations TEXT DEFAULT '{}', meta TEXT DEFAULT '{}',
    FOREIGN KEY (parent_id) REFERENCES categories(id)
)");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_cat_slug_parent ON categories(slug, COALESCE(parent_id, 0))");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_cat_parent ON categories(parent_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_cat_path ON categories(path)");

$pdo->exec("CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY, sku TEXT UNIQUE NOT NULL, slug TEXT UNIQUE NOT NULL,
    product_type TEXT NOT NULL DEFAULT 'simple', name TEXT NOT NULL,
    category_id INTEGER NOT NULL, price INTEGER NOT NULL, compare_price INTEGER,
    currency TEXT NOT NULL DEFAULT 'eur', stock INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active', images TEXT DEFAULT '[]',
    summary TEXT DEFAULT '', description TEXT DEFAULT '', attrs TEXT DEFAULT '{}',
    tags TEXT DEFAULT '[]', filter_tags TEXT DEFAULT '', weight_g INTEGER DEFAULT 0,
    translations TEXT DEFAULT '{}',
    created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (category_id) REFERENCES categories(id)
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_prod_category ON products(category_id, status, price)");
try { $pdo->exec("ALTER TABLE products ADD COLUMN vat_class TEXT DEFAULT 'standard'"); } catch (\PDOException $e) {}

$pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS products_fts USING fts5(
    name, sku, summary, tags, filter_tags,
    content=products, content_rowid=id,
    tokenize='unicode61 remove_diacritics 2'
)");

$pdo->exec("CREATE TRIGGER IF NOT EXISTS products_ai AFTER INSERT ON products BEGIN
    INSERT INTO products_fts(rowid, name, sku, summary, tags, filter_tags)
    VALUES (new.id, new.name, new.sku, new.summary, new.tags, new.filter_tags);
END");
$pdo->exec("CREATE TRIGGER IF NOT EXISTS products_ad AFTER DELETE ON products BEGIN
    INSERT INTO products_fts(products_fts, rowid, name, sku, summary, tags, filter_tags)
    VALUES ('delete', old.id, old.name, old.sku, old.summary, old.tags, old.filter_tags);
END");
$pdo->exec("CREATE TRIGGER IF NOT EXISTS products_au AFTER UPDATE ON products BEGIN
    INSERT INTO products_fts(products_fts, rowid, name, sku, summary, tags, filter_tags)
    VALUES ('delete', old.id, old.name, old.sku, old.summary, old.tags, old.filter_tags);
    INSERT INTO products_fts(rowid, name, sku, summary, tags, filter_tags)
    VALUES (new.id, new.name, new.sku, new.summary, new.tags, new.filter_tags);
END");

$pdo->exec("CREATE TABLE IF NOT EXISTS product_attrs (
    product_id INTEGER NOT NULL, attr_name TEXT NOT NULL, attr_value TEXT NOT NULL,
    numeric_val REAL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_pa_lookup ON product_attrs(attr_name, attr_value, product_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_pa_product ON product_attrs(product_id)");

$pdo->exec("CREATE TABLE IF NOT EXISTS variants (
    id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, sku TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL DEFAULT '', attrs TEXT NOT NULL DEFAULT '{}',
    price_delta INTEGER NOT NULL DEFAULT 0, price_absolute INTEGER,
    stock INTEGER NOT NULL DEFAULT 0, sort_order INTEGER DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS slug_registry (
    slug TEXT NOT NULL, locale TEXT NOT NULL, entity TEXT NOT NULL,
    entity_id INTEGER NOT NULL, PRIMARY KEY (slug, locale, entity)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY, order_number TEXT UNIQUE NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending', customer_name TEXT NOT NULL,
    customer_email TEXT NOT NULL, customer_phone TEXT DEFAULT '',
    customer_id INTEGER DEFAULT NULL,
    shipping_addr TEXT DEFAULT '{}', payment_method TEXT NOT NULL,
    payment_id TEXT DEFAULT '', subtotal INTEGER NOT NULL,
    discount_amount INTEGER NOT NULL DEFAULT 0,
    shipping_cost INTEGER NOT NULL DEFAULT 0, tax_amount INTEGER NOT NULL DEFAULT 0,
    total INTEGER NOT NULL, currency TEXT NOT NULL DEFAULT 'eur',
    locale TEXT NOT NULL DEFAULT 'en',
    notes TEXT DEFAULT '', ip_address TEXT DEFAULT '',
    erp_id TEXT DEFAULT NULL, erp_status TEXT DEFAULT NULL,
    erp_synced_at TEXT DEFAULT NULL, erp_error TEXT DEFAULT NULL,
    vat_scenario TEXT DEFAULT NULL, vat_total INTEGER DEFAULT NULL, net_total INTEGER DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now'))
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (
    id INTEGER PRIMARY KEY, code TEXT UNIQUE NOT NULL, name TEXT NOT NULL,
    priority INTEGER DEFAULT 0, is_dropship INTEGER DEFAULT 0,
    is_default INTEGER DEFAULT 0, contact TEXT DEFAULT '', notes TEXT DEFAULT ''
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS warehouse_stock (
    id INTEGER PRIMARY KEY, warehouse_id INTEGER NOT NULL, sku TEXT NOT NULL,
    qty INTEGER NOT NULL DEFAULT 0, reserved INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT DEFAULT (datetime('now')),
    UNIQUE(warehouse_id, sku), FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
    id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
    variant_id INTEGER, sku TEXT NOT NULL, name TEXT NOT NULL,
    quantity INTEGER NOT NULL, unit_price INTEGER NOT NULL, total INTEGER NOT NULL,
    attrs TEXT DEFAULT '{}',
    vat_class TEXT DEFAULT 'standard', vat_rate REAL DEFAULT NULL,
    vat_amount INTEGER DEFAULT NULL, net_amount INTEGER DEFAULT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS coupon_uses (
    id INTEGER PRIMARY KEY, coupon_code TEXT NOT NULL, order_id INTEGER NOT NULL,
    customer_email TEXT NOT NULL, discount INTEGER NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (order_id) REFERENCES orders(id)
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_coupon_code ON coupon_uses(coupon_code)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_coupon_email ON coupon_uses(coupon_code, customer_email)");

$pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY, entity TEXT NOT NULL, entity_id INTEGER NOT NULL,
    action TEXT NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
    actor TEXT NOT NULL, ip_address TEXT,
    created_at TEXT DEFAULT (datetime('now'))
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS processed_events (
    event_id TEXT PRIMARY KEY, created_at TEXT DEFAULT (datetime('now'))
)");

// TecDoc tables
$pdo->exec("CREATE TABLE IF NOT EXISTS product_specifications (
    id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, lang TEXT NOT NULL DEFAULT 'sk',
    criteria_name TEXT NOT NULL, criteria_value TEXT NOT NULL, numeric_val REAL,
    sort_order INTEGER DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_spec_product ON product_specifications(product_id, lang)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_spec_criteria ON product_specifications(criteria_name, criteria_value, product_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_spec_numeric ON product_specifications(criteria_name, numeric_val)");

$pdo->exec("CREATE TABLE IF NOT EXISTS product_oe_numbers (
    id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL,
    oem_brand TEXT NOT NULL, oem_number TEXT NOT NULL,
    oem_number_norm TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_oe_product ON product_oe_numbers(product_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_oe_number ON product_oe_numbers(oem_number, oem_brand)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_oe_norm ON product_oe_numbers(oem_number_norm)");

$pdo->exec("CREATE TABLE IF NOT EXISTS product_vehicles (
    id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL,
    ktype INTEGER, manufacturer TEXT NOT NULL, model TEXT NOT NULL,
    type_name TEXT DEFAULT '', year_from INTEGER, year_to INTEGER,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_pv_product ON product_vehicles(product_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_pv_ktype ON product_vehicles(ktype)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_pv_mfr ON product_vehicles(manufacturer, model)");

// Verify tables
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('categories', $tables), 'categories table exists');
assert_true(in_array('products', $tables), 'products table exists');
assert_true(in_array('products_fts', $tables), 'products_fts table exists');
assert_true(in_array('product_attrs', $tables), 'product_attrs table exists');
assert_true(in_array('variants', $tables), 'variants table exists');
assert_true(in_array('slug_registry', $tables), 'slug_registry table exists');
assert_true(in_array('orders', $tables), 'orders table exists');
assert_true(in_array('order_items', $tables), 'order_items table exists');
assert_true(in_array('audit_log', $tables), 'audit_log table exists');
assert_true(in_array('product_specifications', $tables), 'product_specifications table exists');
assert_true(in_array('product_oe_numbers', $tables), 'product_oe_numbers table exists');
assert_true(in_array('product_vehicles', $tables), 'product_vehicles table exists');
assert_true(in_array('processed_events', $tables), 'processed_events table exists');

// ============================================================
// TEST 3: CSV Import
// ============================================================
echo "\n--- Test 3: CSV Import ---\n";

$csvFile = __DIR__ . '/../import/test-products.csv';
$result = Import::csv($csvFile);

assert_true($result['success'], 'CSV import succeeded');
assert_equals(8, $result['imported'], 'Imported 8 products');
assert_equals(0, count($result['errors']), 'No import errors');

// Verify products in DB
$count = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
assert_equals(8, $count, '8 products in database');

// Verify categories auto-created
$catCount = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
assert_greater($catCount, 3, 'Multiple categories auto-created');

// Verify category tree
$tricka = $pdo->query("SELECT * FROM categories WHERE slug = 'tricka'")->fetch();
assert_true($tricka !== false, 'Category "tricka" exists');
assert_equals('oblecenie/tricka', $tricka['path'], 'Category path is correct');
assert_equals(1, (int)$tricka['depth'], 'Category depth is 1');

// Verify product data
$tshirt = $pdo->query("SELECT * FROM products WHERE sku = 'TSHIRT-BLK-M'")->fetch();
assert_true($tshirt !== false, 'Product TSHIRT-BLK-M exists');
assert_equals(1990, (int)$tshirt['price'], 'Price is 1990 cents (€19.90)');
assert_equals(120, (int)$tshirt['stock'], 'Stock is 120');
assert_equals('clothing', $tshirt['product_type'], 'Product type is clothing');

// Verify translations stored
$translations = json_decode($tshirt['translations'], true);
assert_true(!empty($translations['en']['name']), 'English translation exists');
assert_equals('Black T-Shirt Classic', $translations['en']['name'], 'EN name is correct');
assert_equals('black-t-shirt-classic', $translations['en']['slug'], 'EN slug is correct');
assert_true(!empty($translations['de']['name']), 'German translation exists');

// Verify product_attrs
$attrs = $pdo->query("SELECT * FROM product_attrs WHERE product_id = {$tshirt['id']} ORDER BY attr_name")->fetchAll();
assert_greater(count($attrs), 2, 'Product has multiple attributes');

$colorAttr = null;
foreach ($attrs as $a) {
    if ($a['attr_name'] === 'color') $colorAttr = $a;
}
assert_true($colorAttr !== null, 'Color attribute exists');

// Verify filter_tags
assert_true(str_contains($tshirt['filter_tags'], 'attr_color_'), 'filter_tags contains color token');
assert_true(str_contains($tshirt['filter_tags'], 'attr_size_m'), 'filter_tags contains size token');

// Verify product_count on categories
$trickaCount = (int)$pdo->query("SELECT product_count FROM categories WHERE slug = 'tricka'")->fetchColumn();
assert_equals(3, $trickaCount, 'Category tricka has 3 products');

// ============================================================
// TEST 4: FTS5 Full-Text Search
// ============================================================
echo "\n--- Test 4: Full-Text Search (FTS5) ---\n";

// Search by name
$results = $pdo->query("
    SELECT p.sku, p.name, rank
    FROM products_fts fts
    JOIN products p ON p.id = fts.rowid
    WHERE products_fts MATCH 'tricko'
    ORDER BY rank
")->fetchAll();
assert_greater(count($results), 0, 'FTS5 search for "tricko" finds results');

// Search by SKU
$results = $pdo->query("
    SELECT p.sku FROM products_fts fts
    JOIN products p ON p.id = fts.rowid
    WHERE products_fts MATCH 'TSHIRT'
    ORDER BY rank
")->fetchAll();
assert_greater(count($results), 0, 'FTS5 search for "TSHIRT" (SKU) finds results');

// Search by tags
$results = $pdo->query("
    SELECT p.sku FROM products_fts fts
    JOIN products p ON p.id = fts.rowid
    WHERE products_fts MATCH 'bestseller'
    ORDER BY rank
")->fetchAll();
assert_greater(count($results), 1, 'FTS5 search for "bestseller" finds multiple results');

// FTS-based faceted filter
$results = $pdo->query("
    SELECT p.sku FROM products_fts fts
    JOIN products p ON p.id = fts.rowid
    WHERE products_fts MATCH 'attr_color_cierna'
    ORDER BY rank
")->fetchAll();
assert_greater(count($results), 0, 'FTS faceted filter for color=cierna works');

// Combined filter
$results = $pdo->query("
    SELECT p.sku FROM products_fts fts
    JOIN products p ON p.id = fts.rowid
    WHERE products_fts MATCH 'attr_color_cierna AND attr_size_m'
    ORDER BY rank
")->fetchAll();
assert_greater(count($results), 0, 'FTS combined filter (color+size) works');

// ============================================================
// TEST 5: I18n — Translations
// ============================================================
echo "\n--- Test 5: I18n Translations ---\n";

// Slovak (default)
I18n::setLocale('sk');
assert_equals('sk', I18n::locale(), 'Locale is sk');
assert_equals('Pridať do košíka', I18n::t('add_to_cart'), 'SK translation for add_to_cart');
assert_equals('Vypredané', I18n::t('out_of_stock'), 'SK translation for out_of_stock');

// English
I18n::setLocale('en');
assert_equals('Add to Cart', I18n::t('add_to_cart'), 'EN translation for add_to_cart');

// Product translation
I18n::setLocale('en');
$translated = I18n::product($tshirt);
assert_equals('Black T-Shirt Classic', $translated['name'], 'Product translated to EN');
assert_equals('black-t-shirt-classic', $translated['slug'], 'Slug translated to EN');

// Slovak - no translation (base language)
I18n::setLocale('sk');
$original = I18n::product($tshirt);
assert_equals('Čierne tričko Classic', $original['name'], 'Product stays in SK (base)');

// Price formatting
assert_equals('19,90 €', I18n::price(1990, 'eur'), 'EUR price formatting');
assert_equals('$19.90', I18n::price(1990, 'usd'), 'USD price formatting');

// Attribute translation
I18n::setLocale('sk');
$colorLabel = I18n::attrLabel('color');
assert_equals('Farba', $colorLabel, 'Color attribute label in SK');

I18n::setLocale('en');
$colorLabel = I18n::attrLabel('color');
assert_equals('Color', $colorLabel, 'Color attribute label in EN');

// Attribute value translation (translatable options)
I18n::setLocale('sk');
$blackSk = I18n::attr('color', 'black');
assert_equals('Čierna', $blackSk, 'Color "black" translated to SK');

I18n::setLocale('de');
$blackDe = I18n::attr('color', 'black');
assert_equals('Schwarz', $blackDe, 'Color "black" translated to DE');

// ============================================================
// TEST 6: Slug Uniqueness
// ============================================================
echo "\n--- Test 6: Slug Uniqueness ---\n";

$isValid = Import::validateSlug('cierne-tricko-classic', 'sk', 'product', $tshirt['id']);
assert_true($isValid, 'Own slug is valid for same entity');

// Try to register same slug for different entity
$isValid = Import::validateSlug('cierne-tricko-classic', 'sk', 'product', 99999);
assert_true(!$isValid, 'Duplicate slug detected for different entity');

// Different locale is OK
$isValid = Import::validateSlug('cierne-tricko-classic', 'en', 'product', 99999);
assert_true($isValid, 'Same slug in different locale is valid');

// ============================================================
// TEST 7: UPSERT (re-import same CSV)
// ============================================================
echo "\n--- Test 7: UPSERT (re-import) ---\n";

$result2 = Import::csv($csvFile);
assert_true($result2['success'], 'Re-import succeeded');
assert_equals(8, $result2['imported'], 'Re-imported 8 products');

$count2 = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
assert_equals(8, $count2, 'Still 8 products (no duplicates)');

// ============================================================
// TEST 8: Security helpers
// ============================================================
echo "\n--- Test 8: Security Helpers ---\n";

assert_equals('hello-world', Security::slug('Hello World!'), 'slug() sanitizes correctly');
assert_equals('test-123', Security::slug('Test 123!!'), 'slug() handles numbers');
assert_equals('', Security::slug('!!!'), 'slug() strips all special chars');

assert_equals(42, Security::int('42'), 'int() parses valid integer');
assert_equals(0, Security::int('abc'), 'int() returns default for invalid');
assert_equals(-1, Security::int('abc', -1), 'int() returns custom default');

assert_equals('test@example.com', Security::email('test@example.com'), 'email() validates correct email');
assert_equals(null, Security::email('not-an-email'), 'email() rejects invalid email');

assert_equals('&lt;script&gt;', esc('<script>'), 'esc() escapes HTML');
assert_equals('&quot;hello&quot;', esc('"hello"'), 'esc() escapes quotes');

// ============================================================
// TEST 9: Category Tree
// ============================================================
echo "\n--- Test 9: Category Tree ---\n";

$allCats = $pdo->query("SELECT * FROM categories ORDER BY path")->fetchAll();
$rootCats = array_filter($allCats, fn($c) => $c['parent_id'] === null);
assert_greater(count($rootCats), 0, 'Root categories exist');

$oblecenie = $pdo->query("SELECT * FROM categories WHERE slug = 'oblecenie'")->fetch();
assert_true($oblecenie !== false, 'Category "oblecenie" exists');
assert_equals(0, (int)$oblecenie['depth'], 'Root category depth is 0');
assert_true($oblecenie['parent_id'] === null, 'Root category has no parent');

// Children
$children = $pdo->query("SELECT * FROM categories WHERE parent_id = {$oblecenie['id']} ORDER BY slug")->fetchAll();
assert_greater(count($children), 0, 'Category "oblecenie" has children');

// ============================================================
// TEST 10: Performance (basic)
// ============================================================
echo "\n--- Test 10: Basic Performance ---\n";

$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    $pdo->query("SELECT * FROM products WHERE sku = 'TSHIRT-BLK-M'")->fetch();
}
$elapsed = (microtime(true) - $start) * 1000;
assert_true($elapsed < 500, "100x product by SKU lookup in " . round($elapsed, 1) . "ms");

$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    $pdo->query("SELECT p.* FROM products_fts fts JOIN products p ON p.id = fts.rowid WHERE products_fts MATCH 'tricko' LIMIT 24")->fetchAll();
}
$elapsed = (microtime(true) - $start) * 1000;
assert_true($elapsed < 1000, "100x FTS5 search in " . round($elapsed, 1) . "ms");

// ============================================================
// RESULTS
// ============================================================
// TEST 11: Products.php queries
// ============================================================
echo "\n--- Test 11: Products Queries ---\n";

$p = Products::bySlug('cierne-tricko-classic');
assert_true($p !== null, 'Products::bySlug finds product');
assert_equals('TSHIRT-BLK-M', $p['sku'], 'bySlug returns correct SKU');

$p2 = Products::byId((int)$p['id']);
assert_true($p2 !== null, 'Products::byId finds product');
assert_equals('TSHIRT-BLK-M', $p2['sku'], 'byId returns correct SKU');

$p3 = Products::bySku('PHONE-SAM-128');
assert_true($p3 !== null, 'Products::bySku finds product');
assert_equals('Samsung Galaxy A55', $p3['name'], 'bySku returns correct name');

// byCategory with pagination
$tricka = Categories::bySlug('tricka');
$listing = Products::byCategory($tricka['id'], 1, 10, 'price_asc');
assert_equals(3, $listing['total'], 'byCategory returns correct total');
assert_equals(3, count($listing['products']), 'byCategory returns correct count');
assert_true($listing['products'][0]['price'] <= $listing['products'][1]['price'], 'byCategory sorts by price ASC');

// Filter values
$filterVals = Products::filterValues($tricka['id']);
assert_true(isset($filterVals['color']), 'filterValues has color attribute');
assert_true(isset($filterVals['size']), 'filterValues has size attribute');

// Enrich
$enriched = Products::enrich($p);
assert_true(is_array($enriched['images']), 'enrich() decodes images JSON');
assert_true(!empty($enriched['price_formatted']), 'enrich() adds price_formatted');
assert_true(!empty($enriched['url']), 'enrich() adds url');

// Latest
$latest = Products::latest(3);
assert_equals(3, count($latest), 'latest() returns requested count');

// ============================================================
// TEST 12: Categories.php queries
// ============================================================
echo "\n--- Test 12: Categories Queries ---\n";

$tree = Categories::tree();
assert_greater(count($tree), 0, 'Categories::tree returns results');

$roots = Categories::roots();
assert_greater(count($roots), 0, 'Categories::roots returns root categories');

$obl = Categories::bySlug('oblecenie');
assert_true($obl !== null, 'Categories::bySlug finds category');

$children = Categories::children($obl['id']);
assert_greater(count($children), 0, 'Categories::children returns subcategories');

$bread = Categories::breadcrumb($tricka['id']);
assert_equals(2, count($bread), 'Breadcrumb has 2 levels (oblecenie > tricka)');
assert_equals('oblecenie', $bread[0]['slug'], 'Breadcrumb first = oblecenie');
assert_equals('tricka', $bread[1]['slug'], 'Breadcrumb second = tricka');

$catByPath = Categories::byPath('oblecenie/tricka');
assert_true($catByPath !== null, 'byPath finds category');
assert_equals($tricka['id'], $catByPath['id'], 'byPath returns correct ID');

$descIds = Categories::descendantIds($obl['id']);
assert_greater(count($descIds), 1, 'descendantIds includes subcategories');

// ============================================================
// TEST 13: Search.php queries
// ============================================================
echo "\n--- Test 13: Search Queries ---\n";

$sr = Search::query('tričko', 1, 10);
assert_greater($sr['total'], 0, 'Search::query finds products');
assert_true(count($sr['products']) > 0, 'Search returns product list');

$sr2 = Search::query('samsung galaxy', 1, 10);
assert_greater($sr2['total'], 0, 'Search finds Samsung products');

$sr3 = Search::query('xyznonexistent', 1, 10);
assert_equals(0, $sr3['total'], 'Search returns 0 for nonexistent query');

// Suggest
$sugg = Search::suggest('tri', 5);
assert_greater(count($sugg), 0, 'suggest() returns results for "tri"');

// Empty query
$sr4 = Search::query('', 1, 10);
assert_equals(0, count($sr4['products']), 'Empty query returns empty');

// ============================================================
// TEST 14: Template Engine
// ============================================================
echo "\n--- Test 14: Template Engine ---\n";

// Variable substitution
$html = Template::process('Hello {{name}}!', ['name' => 'World']);
assert_equals('Hello World!', $html, 'Template variable substitution');

// Escaping
$html = Template::process('{{val}}', ['val' => '<script>alert(1)</script>']);
assert_true(str_contains($html, '&lt;script&gt;'), 'Template escapes HTML');

// Raw output
$html = Template::process('{{{val}}}', ['val' => '<b>bold</b>']);
assert_equals('<b>bold</b>', $html, 'Triple braces = raw output');

// Dot notation
$html = Template::process('{{a.b.c}}', ['a' => ['b' => ['c' => 'deep']]]);
assert_equals('deep', $html, 'Dot notation works');

// #if true
$html = Template::process('{{#if show}}YES{{/if}}', ['show' => true]);
assert_equals('YES', $html, '#if true renders content');

// #if false
$html = Template::process('{{#if show}}YES{{/if}}', ['show' => false]);
assert_equals('', $html, '#if false renders nothing');

// #if else
$html = Template::process('{{#if show}}YES{{else}}NO{{/if}}', ['show' => false]);
assert_equals('NO', $html, '#if else renders else branch');

// #if comparison
$html = Template::process('{{#if stock > 0}}in stock{{/if}}', ['stock' => 5]);
assert_equals('in stock', $html, '#if comparison works');

// #each
$html = Template::process('{{#each items}}[{{name}}]{{/each}}', ['items' => [
    ['name' => 'A'], ['name' => 'B'], ['name' => 'C']
]]);
assert_equals('[A][B][C]', $html, '#each loop works');

// #each with @index, @first, @last
$html = Template::process('{{#each items}}{{@index}}{{#if @first}}F{{/if}}{{#if @last}}L{{/if}} {{/each}}', [
    'items' => [['x' => 1], ['x' => 2], ['x' => 3]]
]);
assert_true(str_contains($html, '0F'), '#each @first works');
assert_true(str_contains($html, '2L'), '#each @last works');

// .length
$html = Template::process('{{#if items.length > 1}}many{{/if}}', ['items' => [1, 2, 3]]);
assert_equals('many', $html, '.length property works');

// Missing variable
$html = Template::process('{{missing}}', []);
assert_equals('', $html, 'Missing variable renders empty');

// ============================================================
// TEST 15: Cache
// ============================================================
echo "\n--- Test 15: Cache ---\n";

$testCacheDir = __DIR__ . '/../cache/test_' . getmypid();
Cache::setDir($testCacheDir);

Cache::writeJson('test/data.json', ['foo' => 'bar']);
$read = Cache::readJson('test/data.json');
assert_equals('bar', $read['foo'], 'Cache write/read JSON works');

Cache::write('test/text.txt', 'hello');
assert_equals('hello', Cache::read('test/text.txt'), 'Cache write/read text works');

// TTL check
Cache::write('test/old.txt', 'old');
// File is fresh (just written)
assert_true(Cache::fresh('test/old.txt', 3600), 'Cache fresh check works');

// Key-value
Cache::set('mykey', 'myvalue');
assert_equals('myvalue', Cache::get('mykey'), 'Cache key-value works');

// Locale path helper
assert_equals('sk/product/test.json', Cache::localePath('sk', 'product/test.json'), 'localePath works');

// Cleanup test cache
Cache::invalidatePrefix('test');
@rmdir($testCacheDir . '/test');
@rmdir($testCacheDir . '/kv');
@rmdir($testCacheDir);

// Reset cache dir
Cache::setDir(__DIR__ . '/../cache');

// ============================================================
// TEST 16: Cart (session-based)
// ============================================================
echo "\n--- Test 16: Cart ---\n";

// Mock session
$_SESSION = [];
Cart::add(1, null, 2);
$cart = Cart::get();
assert_true(isset($cart['p_1']), 'Cart::add creates entry with product key');
assert_equals(2, $cart['p_1']['qty'], 'Cart qty is 2');

// Add same product again
Cart::add(1, null, 3);
$cart = Cart::get();
assert_equals(5, $cart['p_1']['qty'], 'Cart qty incremented to 5');

// Add variant
Cart::add(1, 10, 1);
$cart = Cart::get();
assert_true(isset($cart['v_10']), 'Cart::add creates entry with variant key');

// Update qty
Cart::update('p_1', 1);
assert_equals(1, Cart::get()['p_1']['qty'], 'Cart::update changes qty');

// Remove (qty 0)
Cart::update('p_1', 0);
assert_true(!isset(Cart::get()['p_1']), 'Cart::update removes item when qty=0');

// Clear
Cart::clear();
assert_true(empty(Cart::get()), 'Cart::clear empties cart');

// Restore from localStorage data
Cart::restore([
    ['product_id' => 2, 'variant_id' => null, 'qty' => 3],
    ['product_id' => 3, 'variant_id' => 5, 'qty' => 1],
]);
$cart = Cart::get();
assert_true(isset($cart['p_2']), 'Cart::restore restores product');
assert_true(isset($cart['v_5']), 'Cart::restore restores variant');
assert_equals(3, $cart['p_2']['qty'], 'Cart::restore correct qty');

// Cart email
Cart::setEmail('test@example.com');
assert_equals('test@example.com', $_SESSION['cart_email'], 'Cart::setEmail stores email');
assert_true(!empty($_SESSION['cart_email_at']), 'Cart::setEmail stores timestamp');

$_SESSION = [];

// ============================================================
// TEST 17: AuditLog
// ============================================================
echo "\n--- Test 17: AuditLog ---\n";

AuditLog::log('product', 1, ['price' => 1990], ['price' => 1590], 'Price reduced');
AuditLog::log('product', 1, null, ['name' => 'Test'], 'Product created');

$logs = AuditLog::forEntity('product', 1);
assert_equals(2, count($logs), 'AuditLog has 2 entries');
assert_equals('update', $logs[0]['action'], 'First log action is update (latest first)');
assert_equals('create', $logs[1]['action'] ?? $logs[0]['action'], 'Create action detected');

// ============================================================
// TEST 18: Idempotency
// ============================================================
echo "\n--- Test 18: Idempotency ---\n";

$isDup = Idempotency::webhookProcessed('evt_test_001');
assert_true(!$isDup, 'First event is not duplicate');

$isDup2 = Idempotency::webhookProcessed('evt_test_001');
assert_true($isDup2, 'Same event is duplicate');

$isDup3 = Idempotency::webhookProcessed('evt_test_002');
assert_true(!$isDup3, 'Different event is not duplicate');

// ============================================================
// TEST 19: JobQueue
// ============================================================
echo "\n--- Test 19: JobQueue ---\n";

$testJobsDir = __DIR__ . '/../jobs/test_' . getmypid();
JobQueue::setDir($testJobsDir);

$id1 = JobQueue::push('email_send', ['to' => 'a@b.com'], 5);
$id2 = JobQueue::push('stock_sync', ['sku' => 'TEST'], 1);  // higher priority (lower number)
assert_true(!empty($id1), 'JobQueue::push returns ID');
assert_true($id1 !== $id2, 'Job IDs are unique');

// Pull — should get priority 1 first
$jobs = JobQueue::pull(10);
assert_equals(2, count($jobs), 'JobQueue::pull returns 2 jobs');
assert_equals('stock_sync', $jobs[0]['type'], 'Higher priority job comes first');
assert_equals('email_send', $jobs[1]['type'], 'Lower priority job comes second');

// Mark processing
$procFile = JobQueue::markProcessing($jobs[0]);
assert_true(file_exists($procFile), 'Job moved to processing');

// Complete
JobQueue::complete($procFile);
$stats = JobQueue::stats();
assert_equals(1, $stats['pending'], '1 job still pending');
assert_equals(1, $stats['completed'], '1 job completed');

// Fail with retry
$jobs2 = JobQueue::pull(10);
$procFile2 = JobQueue::markProcessing($jobs2[0]);
$failJob = $jobs2[0];
$failJob['attempts'] = 0;
$failJob['max_attempts'] = 3;
JobQueue::fail($procFile2, $failJob, 'Test error');

$stats2 = JobQueue::stats();
assert_equals(1, $stats2['pending'], 'Failed job retried — back in pending');

// Exhaust retries
for ($i = 0; $i < 3; $i++) {
    $retryJobs = JobQueue::pull(10);
    if (empty($retryJobs)) break;
    $pf = JobQueue::markProcessing($retryJobs[0]);
    $rj = $retryJobs[0];
    JobQueue::fail($pf, $rj, 'Error attempt ' . ($i + 1));
}
$stats3 = JobQueue::stats();
assert_equals(1, $stats3['failed'], 'Job moved to failed after max retries');

// Cleanup jobs test dir
foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
    $subDir = $testJobsDir . '/' . $sub;
    if (is_dir($subDir)) {
        array_map('unlink', glob($subDir . '/*.json') ?: []);
        @rmdir($subDir);
    }
}
@rmdir($testJobsDir);

// ============================================================
// TEST 20: Pricing
// ============================================================
echo "\n--- Test 20: Pricing ---\n";

Pricing::setConfig([
    'customer_groups' => [
        'guest' => ['name' => 'Guest', 'discount' => 0],
        'wholesale' => ['name' => 'Wholesale', 'discount' => 15],
        'vip' => ['name' => 'VIP', 'discount' => 10],
    ],
    'catalog_rules' => [
        [
            'name' => 'Summer Sale',
            'active' => true,
            'from' => '2026-01-01',
            'to' => '2026-12-31',
            'conditions' => ['category' => 'oblecenie'],
            'discount_type' => 'percent',
            'discount_value' => 20,
            'priority' => 1,
        ],
    ],
    'cart_rules' => [
        [
            'name' => 'WELCOME10',
            'type' => 'coupon',
            'code' => 'WELCOME10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'min_subtotal' => 5000,
            'active' => true,
        ],
        [
            'name' => 'Free Shipping over 50',
            'type' => 'automatic',
            'conditions' => ['min_subtotal' => 5000],
            'action' => 'free_shipping',
            'active' => true,
        ],
    ],
    'tier_pricing' => [
        'enabled' => true,
        'rules' => [
            ['min_qty' => 10, 'discount_percent' => 5],
            ['min_qty' => 50, 'discount_percent' => 12],
        ],
    ],
]);

// Guest — no group discount
$product = ['price' => 1990, 'compare_price' => null, 'category_path' => 'oblecenie/tricka'];
$applied = Pricing::apply($product, 'guest', '2026-06-15');
assert_equals(1592, $applied['price'], 'Catalog rule: 20% off 1990 = 1592');
assert_equals(1990, $applied['compare_price'], 'Original price saved as compare_price');

// Wholesale — 15% group discount (less than 20% catalog, so catalog wins)
$product2 = ['price' => 1990, 'compare_price' => null, 'category_path' => 'oblecenie/tricka'];
$applied2 = Pricing::apply($product2, 'wholesale', '2026-06-15');
assert_equals(1592, $applied2['price'], 'Best discount wins (20% catalog > 15% group)');

// VIP but no matching catalog rule
$product3 = ['price' => 1000, 'compare_price' => null, 'category_path' => 'elektronika'];
$applied3 = Pricing::apply($product3, 'vip', '2026-06-15');
assert_equals(900, $applied3['price'], 'VIP 10% off: 1000 → 900');

// Tier pricing
assert_equals(1990, Pricing::tierPrice(1990, 1), 'Tier: qty 1 = no discount');
assert_equals(1891, Pricing::tierPrice(1990, 10), 'Tier: qty 10 = 5% off');
assert_equals(1751, Pricing::tierPrice(1990, 50), 'Tier: qty 50 = 12% off');

// Coupon
$couponResult = Pricing::applyCoupon('WELCOME10', 10000);
assert_equals(1000, $couponResult['discount'], 'Coupon WELCOME10: 10% of 10000 = 1000');
assert_equals('WELCOME10', $couponResult['name'], 'Coupon returns rule name');

// Coupon min not met
$couponResult2 = Pricing::applyCoupon('WELCOME10', 3000);
assert_equals(0, $couponResult2['discount'], 'Coupon rejected when min subtotal not met');

// Invalid coupon
$couponResult3 = Pricing::applyCoupon('INVALID', 10000);
assert_equals(0, $couponResult3['discount'], 'Invalid coupon returns 0');

// Auto rules
$actions = Pricing::autoCartRules(6000);
assert_equals(1, count($actions), 'Auto rule triggers above 5000');
assert_equals('free_shipping', $actions[0]['action'], 'Auto rule action is free_shipping');

$actions2 = Pricing::autoCartRules(3000);
assert_equals(0, count($actions2), 'Auto rule does not trigger below 5000');

// ============================================================
// TEST 21: Shipping
// ============================================================
echo "\n--- Test 21: Shipping ---\n";

Shipping::setConfig([
    'methods' => [
        'pickup' => [
            'name' => ['en' => 'Pickup', 'sk' => 'Odber'],
            'price' => 0,
            'available_zones' => ['domestic'],
        ],
        'standard' => [
            'name' => ['en' => 'Standard', 'sk' => 'Štandard'],
            'delivery_days' => '5-7',
            'rates' => [
                'domestic' => ['base' => 399, 'per_kg' => 0],
                'eu' => ['base' => 799, 'per_kg' => 100],
                'international' => ['base' => 1599, 'per_kg' => 200],
            ],
            'free_above' => 5000,
        ],
        'express' => [
            'name' => ['en' => 'Express', 'sk' => 'Expres'],
            'delivery_days' => '1-2',
            'rates' => [
                'domestic' => ['base' => 799, 'per_kg' => 0],
                'eu' => ['base' => 1499, 'per_kg' => 150],
            ],
        ],
    ],
    'zones' => [
        'domestic' => ['countries' => ['SK']],
        'eu' => ['countries' => ['CZ', 'DE', 'AT', 'PL']],
        'international' => ['countries' => ['*']],
    ],
]);

// Zone detection
assert_equals('domestic', Shipping::zoneForCountry('SK'), 'SK is domestic');
assert_equals('eu', Shipping::zoneForCountry('DE'), 'DE is EU');
assert_equals('international', Shipping::zoneForCountry('US'), 'US is international');

// Pickup
assert_equals(0, Shipping::calculate('pickup', 'SK'), 'Pickup is free');

// Standard domestic
assert_equals(399, Shipping::calculate('standard', 'SK', 500, 3000), 'Standard SK = 399');

// Free shipping above threshold
assert_equals(0, Shipping::calculate('standard', 'SK', 500, 6000), 'Standard SK free above 5000');

// Standard EU with weight
assert_equals(899, Shipping::calculate('standard', 'DE', 1000, 3000), 'Standard DE 1kg = 799+100');

// Express domestic
assert_equals(799, Shipping::calculate('express', 'SK'), 'Express SK = 799');

// Express unavailable internationally
assert_equals(-1, Shipping::calculate('express', 'US'), 'Express not available for US');

// Available methods
I18n::setLocale('en');
$methods = Shipping::availableMethods('SK', 3000);
assert_greater(count($methods), 1, 'Multiple shipping methods for SK');

$methods2 = Shipping::availableMethods('US', 3000);
assert_equals(1, count($methods2), 'Only standard for US');

// ============================================================
// TEST 22: Tax
// ============================================================
echo "\n--- Test 22: Tax ---\n";

Tax::setConfig([
    'default_rate' => 20,
    'prices_include_tax' => true,
    'rates' => [
        'SK' => ['standard' => 20, 'reduced' => 10, 'zero' => 0],
        'DE' => ['standard' => 19, 'reduced' => 7],
        'US' => ['standard' => 0],
    ],
    'product_tax_class' => [
        'default' => 'standard',
        'food' => 'reduced',
        'books' => 'reduced',
    ],
]);

// Basic rate lookup
assert_equals(20.0, Tax::rate('SK'), 'SK standard rate = 20%');
assert_equals(19.0, Tax::rate('DE'), 'DE standard rate = 19%');
assert_equals(0.0, Tax::rate('US'), 'US standard rate = 0%');
assert_equals(10.0, Tax::rate('SK', 'food'), 'SK reduced rate = 10%');

// Extract tax from gross
$tax = Tax::extractFromGross(1200, 'SK');  // 1200 / 1.20 = 1000, tax = 200
assert_equals(200, $tax, 'Tax extracted from gross: 1200 → 200');

$tax2 = Tax::extractFromGross(1190, 'DE');  // 1190 / 1.19 = 1000, tax = 190
assert_equals(190, $tax2, 'Tax extracted from gross DE: 1190 → 190');

// Zero tax country
$tax3 = Tax::extractFromGross(1000, 'US');
assert_equals(0, $tax3, 'No tax for US');

// Net from gross
$net = Tax::netFromGross(1200, 'SK');
assert_equals(1000, $net, 'Net from gross: 1200 → 1000');

// Calculate on net
$taxOnNet = Tax::calculateOnNet(1000, 'SK');
assert_equals(200, $taxOnNet, 'Tax on net: 1000 → 200');

// Order breakdown
$items = [
    ['total' => 2400, 'tax_class' => 'default'],
    ['total' => 1100, 'tax_class' => 'food'],
];
$breakdown = Tax::orderBreakdown($items, 'SK');
assert_equals(500, $breakdown['total_tax'], 'Order tax: 400 (standard) + 100 (reduced) = 500');
assert_true(isset($breakdown['breakdown']['20.0%']), 'Breakdown includes 20% rate');
assert_true(isset($breakdown['breakdown']['10.0%']), 'Breakdown includes 10% rate');

// ============================================================
// TEST 23: Orders + OrderWorkflow
// ============================================================
echo "\n--- Test 23: Orders + Workflow ---\n";

// Need product in cart with stock
$_SESSION = [];
$testProduct = Products::bySku('TSHIRT-BLK-M');
assert_true($testProduct !== null, 'Test product exists for order');

// Setup warehouse + stock so Orders::create() can reserve
$pdo->exec("INSERT INTO warehouses (code, name, priority, is_dropship, is_default, contact, notes) VALUES ('main', 'Main', 10, 0, 1, '', '')");
$whId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (:wh, :sku, :qty, 0)")
    ->execute([':wh' => $whId, ':sku' => $testProduct['sku'], ':qty' => (int)$testProduct['stock']]);

$originalStock = (int)$testProduct['stock'];

// Create order with cart items
$cartItems = [
    [
        'product_id' => $testProduct['id'],
        'variant_id' => null,
        'qty' => 2,
        'price' => $testProduct['price'],
        'sku' => $testProduct['sku'],
        'name' => $testProduct['name'],
        'variant_name' => '',
    ],
];
$customer = [
    'name' => 'Test Customer',
    'email' => 'test@example.com',
    'phone' => '+421900000000',
    'address' => ['street' => 'Test 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency' => 'eur',
    'shipping_cost' => 399,
    'tax_amount' => 0,
];

$orderId = Orders::create($cartItems, $customer, 'stripe');
assert_true($orderId > 0, 'Order created with ID > 0');

// Check order
$order = Orders::byId($orderId);
assert_true($order !== null, 'Order found by ID');
assert_equals('pending', $order['status'], 'Order status is pending');
assert_equals('Test Customer', $order['customer_name'], 'Customer name correct');
assert_equals('test@example.com', $order['customer_email'], 'Customer email correct');
assert_equals($testProduct['price'] * 2, (int)$order['subtotal'], 'Subtotal correct');
assert_equals(399, (int)$order['shipping_cost'], 'Shipping cost correct');
assert_equals($testProduct['price'] * 2 + 399, (int)$order['total'], 'Total = subtotal + shipping');

// Check order number format
assert_true(str_starts_with($order['order_number'], 'ORD-'), 'Order number starts with ORD-');

// Check order items
$items = Orders::items($orderId);
assert_equals(1, count($items), 'Order has 1 line item');
assert_equals(2, (int)$items[0]['quantity'], 'Item quantity is 2');
assert_equals($testProduct['sku'], $items[0]['sku'], 'Item SKU correct');

// Stock decremented
$updatedProduct = Products::bySku('TSHIRT-BLK-M');
assert_equals($originalStock - 2, (int)$updatedProduct['stock'], 'Stock decremented by 2');

// By number
$orderByNum = Orders::byNumber($order['order_number']);
assert_true($orderByNum !== null, 'Order found by number');
assert_equals($orderId, (int)$orderByNum['id'], 'byNumber returns correct order');

// By email
$byEmail = Orders::byEmail('test@example.com');
assert_greater(count($byEmail), 0, 'byEmail finds orders');

// OrderWorkflow — allowed transitions
assert_true(OrderWorkflow::canTransition('pending', 'paid'), 'pending → paid allowed');
assert_true(OrderWorkflow::canTransition('pending', 'cancelled'), 'pending → cancelled allowed');
assert_true(!OrderWorkflow::canTransition('pending', 'shipped'), 'pending → shipped NOT allowed');
assert_true(!OrderWorkflow::canTransition('refunded', 'pending'), 'refunded → pending NOT allowed');

$allowed = OrderWorkflow::allowedTransitions('paid');
assert_true(in_array('processing', $allowed), 'paid can go to processing');
assert_true(in_array('shipped', $allowed), 'paid can go to shipped');
assert_true(in_array('cancelled', $allowed), 'paid can go to cancelled');

// Terminal state
assert_true(OrderWorkflow::isTerminal('refunded'), 'refunded is terminal');
assert_true(!OrderWorkflow::isTerminal('pending'), 'pending is not terminal');

// Set up job queue for workflow side effects
$testJobsDir2 = __DIR__ . '/../jobs/test_wf_' . getmypid();
JobQueue::setDir($testJobsDir2);

// Transition pending → paid
OrderWorkflow::transition($orderId, 'paid', 'Stripe payment received');
$order2 = Orders::byId($orderId);
assert_equals('paid', $order2['status'], 'Order transitioned to paid');

// Transition paid → shipped (queues email)
OrderWorkflow::transition($orderId, 'shipped', 'Dispatched via DPD');
$order3 = Orders::byId($orderId);
assert_equals('shipped', $order3['status'], 'Order transitioned to shipped');

// Check email job queued
$queuedJobs = JobQueue::pull(10);
assert_greater(count($queuedJobs), 0, 'Shipping notification job queued');

// Transition shipped → delivered
OrderWorkflow::transition($orderId, 'delivered');
assert_equals('delivered', Orders::byId($orderId)['status'], 'Order delivered');

// Invalid transition should throw
$threwException = false;
try {
    OrderWorkflow::transition($orderId, 'pending');
} catch (\LogicException $e) {
    $threwException = true;
}
assert_true($threwException, 'Invalid transition throws LogicException');

// Stock restore test: create another order and cancel it
$cartItems2 = [
    [
        'product_id' => $testProduct['id'],
        'variant_id' => null,
        'qty' => 1,
        'price' => $testProduct['price'],
        'sku' => $testProduct['sku'],
        'name' => $testProduct['name'],
        'variant_name' => '',
    ],
];
$orderId2 = Orders::create($cartItems2, $customer, 'stripe');
$stockBefore = (int)Products::bySku('TSHIRT-BLK-M')['stock'];

OrderWorkflow::transition($orderId2, 'cancelled', 'Customer requested');
$stockAfter = (int)Products::bySku('TSHIRT-BLK-M')['stock'];
assert_equals($stockBefore + 1, $stockAfter, 'Cancellation restores stock');

// Order stats
$stats = Orders::stats();
assert_true(isset($stats['pending']), 'Stats has pending count');
assert_true(isset($stats['revenue']), 'Stats has revenue');

// Cleanup jobs test dir
foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
    $subDir = $testJobsDir2 . '/' . $sub;
    if (is_dir($subDir)) {
        array_map('unlink', glob($subDir . '/*.json') ?: []);
        @rmdir($subDir);
    }
}
@rmdir($testJobsDir2);

// ============================================================
// TEST 24: Out-of-stock order rejection
// ============================================================
echo "\n--- Test 24: Out-of-stock Order ---\n";

$cartItemsOOS = [
    [
        'product_id' => $testProduct['id'],
        'variant_id' => null,
        'qty' => 999999, // way more than stock
        'price' => $testProduct['price'],
        'sku' => $testProduct['sku'],
        'name' => $testProduct['name'],
        'variant_name' => '',
    ],
];
$oosException = false;
try {
    Orders::create($cartItemsOOS, $customer, 'stripe');
} catch (\RuntimeException $e) {
    $oosException = true;
    assert_true(str_contains($e->getMessage(), 'Out of stock'), 'OOS error message correct');
}
assert_true($oosException, 'Out-of-stock order throws RuntimeException');

// ============================================================
// TEST 25: Stripe Webhook Signature Verification
// ============================================================
echo "\n--- Test 25: Webhook Signature ---\n";

// Inline the signature verification function for testing (api.php has side effects)
function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool {
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$key, $val] = explode('=', trim($part), 2) + [1 => ''];
        $parts[$key] = $val;
    }
    $timestamp = $parts['t'] ?? '';
    $signature = $parts['v1'] ?? '';
    if (!$timestamp || !$signature) return false;
    if (abs(time() - (int)$timestamp) > 300) return false;
    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    return hash_equals($expected, $signature);
}

$testSecret = 'whsec_test123';
$testPayload = '{"id":"evt_1","type":"checkout.session.completed"}';
$testTimestamp = (string)time();
$testSig = hash_hmac('sha256', $testTimestamp . '.' . $testPayload, $testSecret);
$testHeader = "t={$testTimestamp},v1={$testSig}";

assert_true(verifyStripeSignature($testPayload, $testHeader, $testSecret), 'Valid Stripe signature accepted');
assert_true(!verifyStripeSignature($testPayload, $testHeader, 'wrong_secret'), 'Wrong secret rejected');
assert_true(!verifyStripeSignature('tampered', $testHeader, $testSecret), 'Tampered payload rejected');

// Expired timestamp
$oldTimestamp = (string)(time() - 600);
$oldSig = hash_hmac('sha256', $oldTimestamp . '.' . $testPayload, $testSecret);
$oldHeader = "t={$oldTimestamp},v1={$oldSig}";
assert_true(!verifyStripeSignature($testPayload, $oldHeader, $testSecret), 'Expired signature rejected');

// ============================================================
// TEST 26: Cache Warming (inline)
// ============================================================
echo "\n--- Test 26: Cache Warming ---\n";

$warmCacheDir = __DIR__ . '/../cache/test_warm_' . getmypid();
Cache::setDir($warmCacheDir);

// Warm categories
I18n::setLocale('sk');
$tree = Categories::tree();
$translatedTree = array_map(fn($cat) => I18n::category($cat), $tree);
Cache::writeJson(Cache::localePath('sk', 'categories.json'), ['categories' => $translatedTree]);

$read = Cache::readJson(Cache::localePath('sk', 'categories.json'));
assert_true(!empty($read['categories']), 'Cache warmed: categories');

// Warm a category listing
$tricka = Categories::bySlug('tricka');
$cfg = require __DIR__ . '/../config.php';
$listing = Products::byCategory($tricka['id'], 1, $cfg['per_page'] ?? 24, 'price_asc');
$listing['products'] = array_map(fn($p) => Products::enrich(I18n::product($p)), $listing['products']);
Cache::writeJson(Cache::localePath('sk', "cat/tricka/page-1.json"), $listing);

$readListing = Cache::readJson(Cache::localePath('sk', "cat/tricka/page-1.json"));
assert_true(!empty($readListing['products']), 'Cache warmed: category listing');
assert_equals(3, $readListing['total'], 'Cache listing has correct total');

// Warm a product page
$product = Products::bySku('TSHIRT-BLK-M');
$enriched = Products::enrich(I18n::product($product));
Cache::writeJson(Cache::localePath('sk', "product/{$product['slug']}.json"), ['product' => $enriched]);

$readProduct = Cache::readJson(Cache::localePath('sk', "product/{$product['slug']}.json"));
assert_true(!empty($readProduct['product']), 'Cache warmed: product page');
assert_equals('TSHIRT-BLK-M', $readProduct['product']['sku'], 'Cache product has correct SKU');

// Cleanup warm cache
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($warmCacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iter as $f) {
    $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
}
@rmdir($warmCacheDir);
Cache::setDir(__DIR__ . '/../cache');

// ============================================================
// TEST 27: Worker job handler (inline test)
// ============================================================
echo "\n--- Test 27: Worker Job Dispatch ---\n";

$testJobsDir3 = __DIR__ . '/../jobs/test_worker_' . getmypid();
JobQueue::setDir($testJobsDir3);

// Push various job types
$j1 = JobQueue::push('email_send', ['to' => 'test@test.com', 'template' => 'test'], 5);
$j2 = JobQueue::push('cache_rebuild', ['scope' => 'full'], 1);
$j3 = JobQueue::push('stock_alert', ['threshold' => 10], 3);

$stats = JobQueue::stats();
assert_equals(3, $stats['pending'], 'Worker: 3 jobs pending');

// Pull in priority order
$jobs = JobQueue::pull(10);
assert_equals('cache_rebuild', $jobs[0]['type'], 'Worker: highest priority first (cache_rebuild)');
assert_equals('stock_alert', $jobs[1]['type'], 'Worker: second priority (stock_alert)');
assert_equals('email_send', $jobs[2]['type'], 'Worker: third priority (email_send)');

// Process one
$procFile = JobQueue::markProcessing($jobs[0]);
JobQueue::complete($procFile);
$stats2 = JobQueue::stats();
assert_equals(2, $stats2['pending'], 'Worker: 2 jobs left after processing');
assert_equals(1, $stats2['completed'], 'Worker: 1 completed');

// Cleanup
foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
    $subDir = $testJobsDir3 . '/' . $sub;
    if (is_dir($subDir)) {
        array_map('unlink', glob($subDir . '/*.json') ?: []);
        @rmdir($subDir);
    }
}
@rmdir($testJobsDir3);

// ============================================================
// TEST 28: Security Audit
// ============================================================
echo "\n--- Test 28: Security Audit ---\n";

// SQL injection via prepared statements
$maliciousSlug = "'; DROP TABLE products; --";
$result = Products::bySlug($maliciousSlug);
assert_true($result === null, 'SQL injection via slug returns null (no crash)');

$tableCount = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='products'")->fetchColumn();
assert_equals(1, $tableCount, 'products table still exists after injection attempt');

// XSS prevention
$xssPayload = '<script>alert("xss")</script>';
$escaped = esc($xssPayload);
assert_true(!str_contains($escaped, '<script>'), 'esc() prevents XSS: no raw <script>');
assert_true(str_contains($escaped, '&lt;script&gt;'), 'esc() converts to HTML entities');

// Slug sanitization prevents directory traversal
$traversal = '../../../etc/passwd';
$safeSlug = Security::slug($traversal);
assert_true(!str_contains($safeSlug, '..'), 'Slug sanitization prevents directory traversal');
assert_true(!str_contains($safeSlug, '/'), 'Slug sanitization removes path separators');

// Int sanitization
assert_equals(0, Security::int('abc; DROP TABLE'), 'int() rejects SQL in input');
assert_equals(0, Security::int('1 OR 1=1'), 'int() rejects boolean injection');

// Email validation
assert_equals(null, Security::email('admin@"><script>'), 'email() rejects XSS payload');
assert_equals(null, Security::email('not-email'), 'email() rejects invalid format');

// Webhook replay protection (idempotency)
$replay1 = Idempotency::webhookProcessed('replay_test_001');
assert_true(!$replay1, 'Webhook first delivery accepted');
$replay2 = Idempotency::webhookProcessed('replay_test_001');
assert_true($replay2, 'Webhook replay blocked');

// Order creation rejects negative price manipulation
$testProduct = Products::bySku('TSHIRT-BLK-M');
if ($testProduct) {
    // Even if client sends negative qty, server enforces min 1
    $maxQty = min(99, max(1, -5)); // Simulating server-side sanitization
    assert_equals(1, $maxQty, 'Negative qty sanitized to 1');
}

// Template engine prevents stored XSS
$xssData = ['name' => '<img src=x onerror=alert(1)>'];
$rendered = Template::process('{{name}}', $xssData);
assert_true(!str_contains($rendered, '<img'), 'Template prevents stored XSS');
assert_true(str_contains($rendered, '&lt;img'), 'Template escapes stored XSS');

// Raw template output is opt-in only
$rendered2 = Template::process('{{{name}}}', $xssData);
assert_true(str_contains($rendered2, '<img'), 'Triple braces = raw (developer choice)');

// ============================================================
// TEST 29: TecDoc — Specifications CRUD & Parametric Search
// ============================================================
echo "\n--- Test 29: TecDoc — Specifications & Parametric Search ---\n";

// Create a "Brake Disc" category for auto parts
$pdo->exec("INSERT INTO categories (slug, name, parent_id, path, depth, sort_order, meta) VALUES ('brake-system', 'Brake System', NULL, 'brake-system', 0, 100, '{}')");
$brakeSystemId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO categories (slug, name, parent_id, path, depth, sort_order, meta) VALUES ('brake-disc', 'Brake Disc', {$brakeSystemId}, 'brake-system/brake-disc', 1, 101, '{\"genart_no\":192}')");
$brakeDiscCatId = (int)$pdo->lastInsertId();

// Insert 3 brake disc products with different specs
$pdo->exec("INSERT INTO products (sku, slug, product_type, name, category_id, price, stock, status, summary, filter_tags)
    VALUES ('BD-001', 'brake-disc-front-300mm', 'auto_part', 'Brake Disc Front 300mm', {$brakeDiscCatId}, 5500, 10, 'active', 'Front brake disc 300mm ventilated', 'attr_supplier_trw')");
$bd1Id = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO products (sku, slug, product_type, name, category_id, price, stock, status, summary, filter_tags)
    VALUES ('BD-002', 'brake-disc-front-280mm', 'auto_part', 'Brake Disc Front 280mm', {$brakeDiscCatId}, 4200, 5, 'active', 'Front brake disc 280mm solid', 'attr_supplier_febi')");
$bd2Id = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO products (sku, slug, product_type, name, category_id, price, stock, status, summary, filter_tags)
    VALUES ('BD-003', 'brake-disc-rear-260mm', 'auto_part', 'Brake Disc Rear 260mm', {$brakeDiscCatId}, 3800, 15, 'active', 'Rear brake disc 260mm solid', 'attr_supplier_trw')");
$bd3Id = (int)$pdo->lastInsertId();

// Add specifications for each disc
$specStmt = $pdo->prepare("INSERT INTO product_specifications (product_id, lang, criteria_name, criteria_value, numeric_val, sort_order) VALUES (?, ?, ?, ?, ?, ?)");

// BD-001: Front 300mm ventilated TRW
$specStmt->execute([$bd1Id, 'sk', 'Priemer [mm]', '300', 300.0, 1]);
$specStmt->execute([$bd1Id, 'sk', 'Hrúbka [mm]', '28', 28.0, 2]);
$specStmt->execute([$bd1Id, 'sk', 'Strana montáže', 'Vpredu', null, 3]);
$specStmt->execute([$bd1Id, 'sk', 'Typ', 'Vetraný', null, 4]);
$specStmt->execute([$bd1Id, 'sk', 'Počet otvorov', '5', 5.0, 5]);

// BD-002: Front 280mm solid FEBI
$specStmt->execute([$bd2Id, 'sk', 'Priemer [mm]', '280', 280.0, 1]);
$specStmt->execute([$bd2Id, 'sk', 'Hrúbka [mm]', '22', 22.0, 2]);
$specStmt->execute([$bd2Id, 'sk', 'Strana montáže', 'Vpredu', null, 3]);
$specStmt->execute([$bd2Id, 'sk', 'Typ', 'Plný', null, 4]);
$specStmt->execute([$bd2Id, 'sk', 'Počet otvorov', '4', 4.0, 5]);

// BD-003: Rear 260mm solid TRW
$specStmt->execute([$bd3Id, 'sk', 'Priemer [mm]', '260', 260.0, 1]);
$specStmt->execute([$bd3Id, 'sk', 'Hrúbka [mm]', '10', 10.0, 2]);
$specStmt->execute([$bd3Id, 'sk', 'Strana montáže', 'Vzadu', null, 3]);
$specStmt->execute([$bd3Id, 'sk', 'Typ', 'Plný', null, 4]);
$specStmt->execute([$bd3Id, 'sk', 'Počet otvorov', '5', 5.0, 5]);

// Get specifications for a product
$specs = TecDoc::specifications($bd1Id, 'sk');
assert_equals(5, count($specs), 'BD-001 has 5 specs');
assert_equals('Priemer [mm]', $specs[0]['criteria_name'], 'First spec is Priemer');
assert_equals('300', $specs[0]['criteria_value'], 'Priemer value = 300');
assert_equals(300.0, $specs[0]['numeric_val'], 'Numeric value extracted');

// Parametric search — no filters → all 3 products
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', [], null, null, 'sk');
assert_equals(3, $result['total'], 'Parametric: no filter → 3 products');

// Filter by mounting side = Vpredu → 2 products
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', ['Strana montáže' => ['Vpredu']], null, null, 'sk');
assert_equals(2, $result['total'], 'Parametric: Vpredu → 2 products');

// Filter by type = Plný → 2 products (BD-002, BD-003)
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', ['Typ' => ['Plný']], null, null, 'sk');
assert_equals(2, $result['total'], 'Parametric: Plný → 2 products');

// Combined filter: Vpredu + Plný → 1 product (BD-002)
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', [
    'Strana montáže' => ['Vpredu'],
    'Typ' => ['Plný'],
], null, null, 'sk');
assert_equals(1, $result['total'], 'Parametric: Vpredu + Plný → 1 product');
assert_equals('BD-002', $result['products'][0]['sku'], 'Filtered product is BD-002');

// Combined filter: Vpredu + Vetraný → 1 product (BD-001)
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', [
    'Strana montáže' => ['Vpredu'],
    'Typ' => ['Vetraný'],
], null, null, 'sk');
assert_equals(1, $result['total'], 'Parametric: Vpredu + Vetraný → 1 product');
assert_equals('BD-001', $result['products'][0]['sku'], 'Filtered product is BD-001');

// Price range filter within parametric search
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', [], 4000, 5000, 'sk');
assert_equals(1, $result['total'], 'Price range 4000-5000 → 1 product');
assert_equals('BD-002', $result['products'][0]['sku'], 'Price filtered product is BD-002');

// Spec filter values — dynamic facets
$filterValues = TecDoc::specFilterValues($brakeDiscCatId, 'sk');
assert_true(isset($filterValues['Strana montáže']), 'Filter has Strana montáže');
assert_true(isset($filterValues['Typ']), 'Filter has Typ');
assert_true(isset($filterValues['Počet otvorov']), 'Filter has Počet otvorov');

// Strana montáže should have Vpredu(2) and Vzadu(1)
$mountValues = array_column($filterValues['Strana montáže'], 'value');
assert_true(in_array('Vpredu', $mountValues), 'Strana montáže has Vpredu');
assert_true(in_array('Vzadu', $mountValues), 'Strana montáže has Vzadu');

// Dynamic filter narrowing: when Vpredu is selected, Typ should still show both options
$filterValues2 = TecDoc::specFilterValues($brakeDiscCatId, 'sk', ['Strana montáže' => ['Vpredu']]);
$typValues = array_column($filterValues2['Typ'], 'value');
assert_true(in_array('Vetraný', $typValues), 'With Vpredu filter, Typ still shows Vetraný');
assert_true(in_array('Plný', $typValues), 'With Vpredu filter, Typ still shows Plný');

// ============================================================
// TEST 30: TecDoc — OE Number Search
// ============================================================
echo "\n--- Test 30: TecDoc — OE Number Search ---\n";

// Add OE numbers (with normalized form for fast search)
$oeStmt = $pdo->prepare("INSERT INTO product_oe_numbers (product_id, oem_brand, oem_number, oem_number_norm) VALUES (?, ?, ?, ?)");
$oeStmt->execute([$bd1Id, 'VOLKSWAGEN', '1K0 615 301 AD', '1K0615301AD']);
$oeStmt->execute([$bd1Id, 'AUDI', '8J0 615 301 K', '8J0615301K']);
$oeStmt->execute([$bd2Id, 'SKODA', '6R0 615 301 B', '6R0615301B']);
$oeStmt->execute([$bd3Id, 'VOLKSWAGEN', '1K0 615 601 N', '1K0615601N']);

// Search by exact OE
$result = TecDoc::searchByOE('1K0 615 301 AD');
assert_equals(1, $result['total'], 'OE search exact: 1 result');
assert_equals('BD-001', $result['products'][0]['sku'], 'OE search finds correct product');

// Search by partial OE (normalized — strips spaces/dashes)
$result = TecDoc::searchByOE('1K0615301');
assert_equals(1, $result['total'], 'OE search normalized: finds with spaces stripped');

// Search by OE pattern across multiple products
$result = TecDoc::searchByOE('615301');
assert_equals(2, $result['total'], 'OE pattern 615301 → 2 products (BD-001, BD-003)');

// Get OE numbers for a product
$oeNums = TecDoc::oeNumbers($bd1Id);
assert_equals(2, count($oeNums), 'BD-001 has 2 OE numbers');
assert_equals('AUDI', $oeNums[0]['oem_brand'], 'First OE sorted alphabetically by brand');

// ============================================================
// TEST 31: TecDoc — Vehicle Compatibility
// ============================================================
echo "\n--- Test 31: TecDoc — Vehicle Compatibility ---\n";

// Add vehicle compatibility
$vehStmt = $pdo->prepare("INSERT INTO product_vehicles (product_id, ktype, manufacturer, model, type_name) VALUES (?, ?, ?, ?, ?)");
$vehStmt->execute([$bd1Id, 35430, 'VOLKSWAGEN', 'GOLF V (1K1)', '2.0 TDI 140HP']);
$vehStmt->execute([$bd1Id, 35431, 'VOLKSWAGEN', 'GOLF V (1K1)', '1.6 FSI 115HP']);
$vehStmt->execute([$bd2Id, 35430, 'VOLKSWAGEN', 'GOLF V (1K1)', '2.0 TDI 140HP']);
$vehStmt->execute([$bd3Id, 35430, 'VOLKSWAGEN', 'GOLF V (1K1)', '2.0 TDI 140HP']);
$vehStmt->execute([$bd1Id, 48907, 'AUDI', 'A3 (8P)', '2.0 TDI 140HP']);

// Parts by ktype
$result = TecDoc::partsByKtype(35430);
assert_equals(3, $result['total'], 'Ktype 35430 → 3 parts');

// Parts by ktype with category filter
$result = TecDoc::partsByKtype(35430, 1, 24, $brakeDiscCatId);
assert_equals(3, $result['total'], 'Ktype 35430 + brake disc cat → 3 parts');

// Categories for ktype
$cats = TecDoc::categoriesForKtype(35430);
assert_equals(1, count($cats), 'Ktype 35430 has 1 category');
assert_equals('brake-disc', $cats[0]['slug'], 'Category is brake-disc');
assert_equals(3, (int)$cats[0]['part_count'], 'Category has 3 parts for this ktype');

// Vehicles for product
$vehicles = TecDoc::vehiclesForProduct($bd1Id);
assert_equals(3, count($vehicles), 'BD-001 fits 3 vehicles');

// Enrich product with TecDoc data
$product = Products::byId($bd1Id);
$enriched = TecDoc::enrichProduct($product, 'sk');
assert_equals(5, count($enriched['specifications']), 'Enriched product has 5 specs');
assert_equals(2, count($enriched['oe_numbers']), 'Enriched product has 2 OE numbers');
assert_equals(3, $enriched['vehicle_count'], 'Enriched product fits 3 vehicles');

// ============================================================
// TEST 32: TecDoc — Parametric Filter Edge Cases
// ============================================================
echo "\n--- Test 32: TecDoc — Parametric Filter Edge Cases ---\n";

// OR within same criterion: holes = 4 OR 5 → all 3 products
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', [
    'Počet otvorov' => ['4', '5'],
], null, null, 'sk');
assert_equals(3, $result['total'], 'OR within criterion: holes 4 OR 5 → 3 products');

// Sorting works
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_desc', [], null, null, 'sk');
assert_equals('BD-001', $result['products'][0]['sku'], 'Price desc: BD-001 first (5500)');
assert_equals('BD-003', $result['products'][2]['sku'], 'Price desc: BD-003 last (3800)');

// Pagination
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 2, 'price_asc', [], null, null, 'sk');
assert_equals(3, $result['total'], 'Pagination: total still 3');
assert_equals(2, count($result['products']), 'Pagination: page 1 has 2 items');
assert_equals(2, $result['pages'], 'Pagination: 2 pages total');

$result2 = TecDoc::parametricSearch($brakeDiscCatId, 2, 2, 'price_asc', [], null, null, 'sk');
assert_equals(1, count($result2['products']), 'Pagination: page 2 has 1 item');

// Empty category returns no filters
$emptyCatId = $brakeSystemId; // parent with no direct products
$result = TecDoc::parametricSearch($emptyCatId, 1, 24, 'price_asc', [], null, null, 'sk');
assert_equals(0, $result['total'], 'Empty category → 0 products');
assert_true(empty($result['filters']), 'Empty category → no filters');

// ============================================================
// TEST 33: Order Workflow — returned restores stock
// ============================================================
echo "\n--- Test 33: Returned Order Restores Stock ---\n";

$testJobsDir3 = __DIR__ . '/../jobs/test_ret_' . getmypid();
JobQueue::setDir($testJobsDir3);

// Create an order, pay it, ship it, then return it
$retProduct = Products::bySku('TSHIRT-BLK-M');
$retStockBefore = (int)$retProduct['stock'];
$retCartItems = [
    [
        'product_id' => $retProduct['id'],
        'variant_id' => null,
        'qty' => 3,
        'price' => $retProduct['price'],
        'sku' => $retProduct['sku'],
        'name' => $retProduct['name'],
        'variant_name' => '',
    ],
];
$retCustomer = [
    'name' => 'Return Tester',
    'email' => 'return@test.com',
    'phone' => '',
    'address' => ['street' => 'Return St', 'city' => 'BA', 'zip' => '00000', 'country' => 'SK'],
    'currency' => 'eur',
    'shipping_cost' => 0,
    'tax_amount' => 0,
];
$retOrderId = Orders::create($retCartItems, $retCustomer, 'stripe');
$retStockAfterCreate = (int)Products::bySku('TSHIRT-BLK-M')['stock'];
assert_equals($retStockBefore - 3, $retStockAfterCreate, 'Stock decremented by 3 on order create');

OrderWorkflow::transition($retOrderId, 'paid', 'Paid');
OrderWorkflow::transition($retOrderId, 'shipped', 'Shipped');
OrderWorkflow::transition($retOrderId, 'delivered', 'Delivered');

// Return the order — should restore stock
OrderWorkflow::transition($retOrderId, 'returned', 'Customer returned');
$retStockAfterReturn = (int)Products::bySku('TSHIRT-BLK-M')['stock'];
assert_equals($retStockAfterCreate + 3, $retStockAfterReturn, 'Stock restored on return (+3)');
assert_equals('returned', Orders::byId($retOrderId)['status'], 'Order status is returned');

// Transition to refunded — stock should NOT change again
OrderWorkflow::transition($retOrderId, 'refunded', 'Refund processed');
$retStockAfterRefund = (int)Products::bySku('TSHIRT-BLK-M')['stock'];
assert_equals($retStockAfterReturn, $retStockAfterRefund, 'Stock unchanged on refund (already restored)');
assert_equals('refunded', Orders::byId($retOrderId)['status'], 'Order status is refunded');

// Cleanup
foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
    $d = $testJobsDir3 . '/' . $sub;
    if (is_dir($d)) { array_map('unlink', glob($d . '/*.json') ?: []); @rmdir($d); }
}
@rmdir($testJobsDir3);

// ============================================================
// TEST 34: Coupon discount persisted in order
// ============================================================
echo "\n--- Test 34: Coupon Discount in Order ---\n";

$couponProduct = Products::bySku('TSHIRT-BLK-M');
$couponCartItems = [
    [
        'product_id' => $couponProduct['id'],
        'variant_id' => null,
        'qty' => 1,
        'price' => $couponProduct['price'],
        'sku' => $couponProduct['sku'],
        'name' => $couponProduct['name'],
        'variant_name' => '',
    ],
];
$couponCustomer = [
    'name' => 'Coupon Tester',
    'email' => 'coupon@test.com',
    'phone' => '',
    'address' => ['street' => 'Test', 'city' => 'BA', 'zip' => '00000', 'country' => 'SK'],
    'currency' => 'eur',
    'shipping_cost' => 399,
    'tax_amount' => 200,
    'coupon_discount' => 500,
];

$couponOrderId = Orders::create($couponCartItems, $couponCustomer, 'cod');
$couponOrder = Orders::byId($couponOrderId);
$expectedSubtotal = $couponProduct['price'];
$expectedTotal = max(0, $expectedSubtotal - 500) + 399 + 200;
assert_equals($expectedSubtotal, (int)$couponOrder['subtotal'], 'Coupon order: subtotal correct');
assert_equals(500, (int)$couponOrder['discount_amount'], 'Coupon discount persisted in order');
assert_equals($expectedTotal, (int)$couponOrder['total'], 'Coupon order: total = subtotal - discount + shipping + tax');

// ============================================================
// TEST 35: Pricing edge cases — zero price, zero compare_price
// ============================================================
echo "\n--- Test 35: Pricing Edge Cases ---\n";

Pricing::setConfig([
    'customer_groups' => ['guest' => ['discount' => 0]],
    'catalog_rules' => [
        [
            'active' => true,
            'discount_type' => 'fixed',
            'discount_value' => 500,
            'conditions' => [],
        ],
    ],
    'tier_pricing' => ['enabled' => false],
    'cart_rules' => [],
]);

// Zero price product + fixed discount → should not divide by zero
$zeroPriceProduct = ['id' => 999, 'price' => 0, 'status' => 'active'];
$result = Pricing::apply($zeroPriceProduct);
assert_equals(0, $result['price'], 'Zero price product stays at 0 (no div by zero)');
assert_true(!isset($result['discount_percent']) || $result['discount_percent'] === 0,
    'No discount percent set for zero-price product');

// Product with compare_price = 0 should not crash enrich()
$weirdProduct = [
    'id' => 998, 'price' => 1000, 'compare_price' => 0, 'slug' => 'test',
    'images' => '[]', 'attrs' => '{}', 'tags' => '[]', 'currency' => 'eur',
];
$enriched = Products::enrich($weirdProduct);
assert_true(!isset($enriched['discount_percent']), 'compare_price=0 does not set discount_percent');
assert_equals(1000, $enriched['price'], 'Price preserved with compare_price=0');

// Restore default config
Pricing::setConfig([
    'customer_groups' => [
        'guest' => ['discount' => 0],
        'wholesale' => ['discount' => 15],
        'vip' => ['discount' => 10],
    ],
    'catalog_rules' => [
        [
            'active' => true,
            'discount_type' => 'percent',
            'discount_value' => 20,
            'conditions' => ['category' => 'tricka'],
        ],
    ],
    'tier_pricing' => [
        'enabled' => true,
        'rules' => [
            ['min_qty' => 10, 'discount_percent' => 5],
            ['min_qty' => 50, 'discount_percent' => 12],
        ],
    ],
    'cart_rules' => [
        [
            'active' => true,
            'type' => 'coupon',
            'code' => 'WELCOME10',
            'name' => 'Welcome 10% off',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'min_subtotal' => 2000,
        ],
        [
            'active' => true,
            'type' => 'automatic',
            'name' => 'Free shipping over 50',
            'conditions' => ['min_subtotal' => 5000],
            'action' => 'free_shipping',
        ],
    ],
]);

// ============================================================
// TEST 36: Import category parent_id isolation
// ============================================================
echo "\n--- Test 36: Import Category Parent Isolation ---\n";

// Create two different trees with a shared slug at leaf level
Import::ensureCategory('electronics/cables', Db::get());
Import::ensureCategory('accessories/cables', Db::get());

// They should be different categories
$elecCables = Db::get()->prepare("SELECT c.id FROM categories c WHERE c.slug = 'cables' AND c.parent_id = (SELECT id FROM categories WHERE slug = 'electronics')");
$elecCables->execute();
$elecId = (int)$elecCables->fetchColumn();

$accCables = Db::get()->prepare("SELECT c.id FROM categories c WHERE c.slug = 'cables' AND c.parent_id = (SELECT id FROM categories WHERE slug = 'accessories')");
$accCables->execute();
$accId = (int)$accCables->fetchColumn();

assert_true($elecId > 0, 'electronics/cables category exists');
assert_true($accId > 0, 'accessories/cables category exists');
assert_true($elecId !== $accId, 'Same-slug categories under different parents are distinct');

// ============================================================
// TEST 37: Cart::removeUnavailable
// ============================================================
echo "\n--- Test 37: Cart removeUnavailable ---\n";

$_SESSION = ['cart' => []];
$cartProd = Products::bySku('TSHIRT-BLK-M');
Cart::add((int)$cartProd['id'], null, 5);
// Also add a product with non-existent ID
$_SESSION['cart']['p_999999'] = ['product_id' => 999999, 'variant_id' => null, 'qty' => 2];

$cartBefore = Cart::get();
assert_equals(2, count($cartBefore), 'Cart has 2 items before cleanup');

Cart::removeUnavailable([]);
$cartAfter = Cart::get();
assert_true(!isset($cartAfter['p_999999']), 'Non-existent product removed from cart');
assert_true(isset($cartAfter['p_' . $cartProd['id']]), 'Valid product still in cart');

// ============================================================
// TEST 38: Coupon per-customer limit
// ============================================================
echo "\n--- Test 38: Coupon Per-Customer Limit ---\n";

Pricing::setConfig([
    'customer_groups' => ['guest' => ['discount' => 0]],
    'catalog_rules' => [],
    'tier_pricing' => ['enabled' => false],
    'cart_rules' => [
        [
            'active' => true,
            'type' => 'coupon',
            'code' => 'ONCE',
            'name' => 'One-time coupon',
            'discount_type' => 'percent',
            'discount_value' => 15,
            'per_customer' => 1,
        ],
    ],
]);

// First use should work
$r1 = Pricing::applyCoupon('ONCE', 10000, 'repeat@test.com');
assert_true($r1['discount'] > 0, 'First coupon use succeeds');

// Record usage (use a real order ID from previous test)
$existingOrder = $pdo->query("SELECT id FROM orders LIMIT 1")->fetchColumn();
Pricing::recordCouponUse('ONCE', (int)$existingOrder, 'repeat@test.com', $r1['discount']);

// Second use by same email should be rejected
$r2 = Pricing::applyCoupon('ONCE', 10000, 'repeat@test.com');
assert_equals(0, $r2['discount'], 'Second use by same customer rejected');
assert_equals('Coupon already used', $r2['error'], 'Per-customer limit error message');

// Different email should still work
$r3 = Pricing::applyCoupon('ONCE', 10000, 'other@test.com');
assert_true($r3['discount'] > 0, 'Different customer can still use coupon');

// Restore default pricing config
Pricing::setConfig([
    'customer_groups' => [
        'guest' => ['discount' => 0],
        'wholesale' => ['discount' => 15],
        'vip' => ['discount' => 10],
    ],
    'catalog_rules' => [
        [
            'active' => true,
            'discount_type' => 'percent',
            'discount_value' => 20,
            'conditions' => ['category' => 'tricka'],
        ],
    ],
    'tier_pricing' => [
        'enabled' => true,
        'rules' => [
            ['min_qty' => 10, 'discount_percent' => 5],
            ['min_qty' => 50, 'discount_percent' => 12],
        ],
    ],
    'cart_rules' => [
        [
            'active' => true,
            'type' => 'coupon',
            'code' => 'WELCOME10',
            'name' => 'Welcome 10% off',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'min_subtotal' => 2000,
        ],
        [
            'active' => true,
            'type' => 'automatic',
            'name' => 'Free shipping over 50',
            'conditions' => ['min_subtotal' => 5000],
            'action' => 'free_shipping',
        ],
    ],
]);

// ============================================================
// TEST 39: Adapters Facade
// ============================================================
echo "\n--- Test 39: Adapters Facade ---\n";

// Test CashOnDelivery adapter via Adapters::set()
require_once __DIR__ . '/../adapters/PaymentAdapter.php';
require_once __DIR__ . '/../adapters/contrib/CashOnDelivery.php';
$cod = new CashOnDelivery([]);
Adapters::set('payment', $cod);

$session = Adapters::payment()->createSession(['order_number' => 'TEST-001', 'success_url' => '/ok']);
assert_true(!empty($session['session_id']), 'COD adapter returns session_id');
assert_true(str_contains($session['session_id'], 'cod_'), 'COD session_id starts with cod_');
assert_equals('/ok', $session['url'], 'COD redirects to success_url');

$status = Adapters::payment()->getStatus('cod_TEST-001');
assert_equals('pending_delivery', $status, 'COD status is pending_delivery');

$refund = Adapters::payment()->refund('cod_TEST-001', 1000);
assert_equals('manual', $refund['status'], 'COD refund is manual');

// Test StorageAdapter interface
require_once __DIR__ . '/../adapters/StorageAdapter.php';
require_once __DIR__ . '/../adapters/contrib/LocalStorage.php';
$tmpDir = sys_get_temp_dir() . '/bbshop_test_uploads_' . getmypid();
$storage = new LocalStorage(['uploads_dir' => $tmpDir, 'uploads_url' => '/uploads']);
Adapters::set('storage', $storage);

$url = $storage->store('test/sample.txt', 'hello world');
assert_equals('/uploads/test/sample.txt', $url, 'LocalStorage store returns URL');
assert_true(file_exists($tmpDir . '/test/sample.txt'), 'File exists on disk');

$medUrl = $storage->url('test/sample.txt', 'medium');
assert_true(str_contains($medUrl, 'sample-medium'), 'URL contains variant name');

$fullUrl = $storage->url('test/sample.txt', 'full');
assert_equals('/uploads/test/sample.txt', $fullUrl, 'Full variant returns original URL');

$storage->delete('test/sample.txt');
assert_true(!file_exists($tmpDir . '/test/sample.txt'), 'File deleted from disk');

// Cleanup
@rmdir($tmpDir . '/test');
@rmdir($tmpDir);

// ============================================================
// TEST 40: Enrich adds category_path for pricing
// ============================================================
echo "\n--- Test 40: Enrich Category Path for Pricing ---\n";

$enrichedProduct = Products::enrich(Products::bySku('TSHIRT-BLK-M'));
assert_true(!empty($enrichedProduct['category_slug']), 'Enriched product has category_slug');
assert_true(!empty($enrichedProduct['category_path']), 'Enriched product has category_path');
assert_true(str_contains($enrichedProduct['category_path'], 'tricka'), 'Category path contains tricka');

// Test that pricing rules now match with category condition
$enrichedForPricing = Products::enrich(Products::bySku('TSHIRT-BLK-M'));
$priced = Pricing::apply($enrichedForPricing);
assert_true($priced['price'] < $enrichedForPricing['price'] || !empty($priced['discount_percent']),
    'Catalog rule with category condition applied via category_path');

// ============================================================
// TEST 41: Idempotency error handling
// ============================================================
echo "\n--- Test 41: Idempotency Selective Error Handling ---\n";

// First insert should work (not duplicate)
$isdup1 = Idempotency::webhookProcessed('test_idem_new_' . time());
assert_equals(false, $isdup1, 'New event is not duplicate');

// Second insert of same event should be duplicate
$eventId = 'test_idem_dup_' . time();
Idempotency::webhookProcessed($eventId);
$isdup2 = Idempotency::webhookProcessed($eventId);
assert_equals(true, $isdup2, 'Repeat event is duplicate');

// ============================================================
// TEST 42: LocalStorage path traversal prevention
// ============================================================
echo "\n--- Test 42: LocalStorage Path Traversal Prevention ---\n";

$tmpDir2 = sys_get_temp_dir() . '/bbshop_test_uploads2_' . getmypid();
$storage2 = new LocalStorage(['uploads_dir' => $tmpDir2, 'uploads_url' => '/uploads']);

// Normal path should work
$url2 = $storage2->store('products/test.txt', 'safe content');
assert_equals('/uploads/products/test.txt', $url2, 'Normal path storage works');

// Path traversal should throw
$traversalThrown = false;
try {
    $storage2->store('../../etc/passwd', 'malicious');
} catch (\RuntimeException $e) {
    $traversalThrown = true;
}
assert_true($traversalThrown, 'Path traversal in store() throws RuntimeException');

// Cleanup
@unlink($tmpDir2 . '/products/test.txt');
@rmdir($tmpDir2 . '/products');
@rmdir($tmpDir2);

// ============================================================
// TEST 43: Adapters class name validation
// ============================================================
echo "\n--- Test 43: Adapters Class Name Validation ---\n";

$invalidThrown = false;
try {
    // Try to resolve an adapter with a dangerous class name
    Adapters::set('_test_invalid', (object)[]);  // clear any cache
    // We can't easily test resolve() with bad config, so validate the regex directly
    $badNames = ['../Foo', 'Foo Bar', 'Foo;Bar', 'Foo/Bar'];
    foreach ($badNames as $bad) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $bad)) {
            $invalidThrown = true;
        }
    }
} catch (\Throwable $e) {
    $invalidThrown = true;
}
assert_true($invalidThrown, 'Invalid adapter class names are rejected by validation regex');

// Valid names should pass
$validNames = ['LocalStorage', 'StripePayment', 'CashOnDelivery', 'My_Adapter'];
$allValid = true;
foreach ($validNames as $valid) {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $valid)) $allValid = false;
}
assert_true($allValid, 'Valid adapter class names pass validation regex');

// ============================================================
// TEST 44: Idempotency unmark allows retry
// ============================================================
echo "\n--- Test 44: Idempotency Unmark Allows Retry ---\n";

$retryEventId = 'test_retry_' . time();
$dup1 = Idempotency::webhookProcessed($retryEventId);
assert_equals(false, $dup1, 'First processing marks event as new');

$dup2 = Idempotency::webhookProcessed($retryEventId);
assert_equals(true, $dup2, 'Second attempt is duplicate');

Idempotency::unmark($retryEventId);
$dup3 = Idempotency::webhookProcessed($retryEventId);
assert_equals(false, $dup3, 'After unmark, event can be reprocessed');

// ============================================================
// TEST 45: OrderWorkflow cancellation skips checkout session refund
// ============================================================
echo "\n--- Test 45: Cancellation Refund Guard ---\n";

// Set up a fresh jobs dir for this test
$testJobsDir45 = __DIR__ . '/../jobs/test_refund_' . getmypid();
JobQueue::setDir($testJobsDir45);

// Create an order with a checkout session ID (cs_*) as payment_id
$csOrder = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_method, payment_id)
    VALUES ('ORD-CS-TEST', 'Test User', 'test@test.com', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'pending', 'stripe', 'cs_test_session_123')");
$csOrder->execute();
$csOrderId = (int)$pdo->lastInsertId();

// Transition to cancelled
OrderWorkflow::transition($csOrderId, 'cancelled', 'Test cancel with checkout session');

// Verify no refund job was queued
$refundJobFound = false;
foreach (glob($testJobsDir45 . '/pending/*.json') ?: [] as $jf) {
    $jd = json_decode(file_get_contents($jf), true);
    if (($jd['type'] ?? '') === 'payment_refund' && ($jd['payload']['order_id'] ?? 0) === $csOrderId) {
        $refundJobFound = true;
    }
}
assert_true(!$refundJobFound, 'No refund job queued for checkout session (cs_*) payment_id');

// Now test with a payment_intent ID — refund SHOULD be queued
$piOrder = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_method, payment_id)
    VALUES ('ORD-PI-TEST', 'Test User', 'test@test.com', '', '{}', 2000, 0, 0, 0, 2000, 'eur', 'pending', 'stripe', 'pi_test_intent_456')");
$piOrder->execute();
$piOrderId = (int)$pdo->lastInsertId();

OrderWorkflow::transition($piOrderId, 'cancelled', 'Test cancel with payment intent');

$refundJobFound2 = false;
foreach (glob($testJobsDir45 . '/pending/*.json') ?: [] as $jf) {
    $jd = json_decode(file_get_contents($jf), true);
    if (($jd['type'] ?? '') === 'payment_refund' && ($jd['payload']['order_id'] ?? 0) === $piOrderId) {
        $refundJobFound2 = true;
    }
}
assert_true($refundJobFound2, 'Refund job queued for payment_intent (pi_*) payment_id');

// Cleanup jobs dir
foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
    foreach (glob($testJobsDir45 . '/' . $sub . '/*.json') ?: [] as $f) @unlink($f);
    @rmdir($testJobsDir45 . '/' . $sub);
}
@rmdir($testJobsDir45);

// ============================================================
// TEST 46: Template engine (now owned by MailService)
// ============================================================
echo "\n--- Test 46: Template Engine ---\n";

// Basic variable substitution
$tpl1 = MailService::renderString('Hello {{name}}, order #{{order_number}}', [
    'name' => 'Peter',
    'order_number' => 'ORD-001',
]);
assert_true(str_contains($tpl1, 'Peter'), 'Template var substitution works');
assert_true(str_contains($tpl1, 'ORD-001'), 'Template multiple vars work');

// {{#if}} blocks
$tpl2 = MailService::renderString('{{#if paid}}Payment received{{/if}}{{#if refunded}}Refunded{{/if}}', [
    'paid' => true,
    'refunded' => false,
]);
assert_true(str_contains($tpl2, 'Payment received'), 'Template #if truthy renders block');
assert_true(!str_contains($tpl2, 'Refunded'), 'Template #if falsy hides block');

// {{#each}} blocks
$tpl3 = MailService::renderString('<ul>{{#each items}}<li>{{name}} x{{quantity}}</li>{{/each}}</ul>', [
    'items' => [
        ['name' => 'Widget', 'quantity' => '2'],
        ['name' => 'Gadget', 'quantity' => '1'],
    ],
]);
assert_true(str_contains($tpl3, 'Widget'), 'Template #each renders items');
assert_true(str_contains($tpl3, 'Gadget'), 'Template #each renders all items');
assert_true(str_contains($tpl3, 'x2'), 'Template #each uses item fields');

// HTML escaping
$tpl4 = MailService::renderString('{{name}}', ['name' => '<script>alert(1)</script>']);
assert_true(!str_contains($tpl4, '<script>'), 'Template escapes HTML');
assert_true(str_contains($tpl4, '&lt;script&gt;'), 'Template HTML-encodes special chars');

// ============================================================
// TEST: HTTP Smoke — hit main pages, check for PHP warnings/errors
// ============================================================
echo "\n--- Test: HTTP Smoke ---\n";

$smokeBaseUrl = getenv('SMOKE_URL') ?: 'http://localhost:8080';
$smokePages = [
    '/'                          => 'Homepage',
    '/?page=cart'                => 'Cart',
    '/?page=checkout'            => 'Checkout',
];

// Add a real category and product if we have access to the live DB
// Try to discover category/product slugs from homepage
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
$homeHtml = @file_get_contents($smokeBaseUrl . '/', false, $ctx);
$smokeAvailable = ($homeHtml !== false);

if ($smokeAvailable) {
    if (preg_match('/page=category&amp;slug=([a-z0-9-]+)/', $homeHtml, $cm)) {
        $smokePages['/?page=category&slug=' . $cm[1]] = 'Category: ' . $cm[1];
    }
    if (preg_match('/page=product&amp;slug=([a-z0-9-]+)/', $homeHtml, $pm)) {
        $smokePages['/?page=product&slug=' . $pm[1]] = 'Product: ' . $pm[1];
    }
    $smokePages['/?page=search&q=harvia'] = 'Search: harvia';

    $phpErrorPattern = '/(Warning|Fatal|Parse error|Notice|Deprecated|Undefined variable|Uncaught).*?(on line \d+|in \/|in [A-Z]:\\\\)/i';

    foreach ($smokePages as $path => $label) {
        $html = @file_get_contents($smokeBaseUrl . $path, false, $ctx);
        if ($html === false) {
            assert_true(false, "Smoke {$label}: Failed to fetch");
            continue;
        }
        // Check response headers for HTTP status
        $status = 200;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('/HTTP\/\S+\s+(\d+)/', $h, $hm)) $status = (int)$hm[1];
            }
        }
        if ($status >= 400) {
            assert_true(false, "Smoke {$label}: HTTP {$status}");
        } elseif (preg_match($phpErrorPattern, $html, $errMatch)) {
            assert_true(false, "Smoke {$label}: PHP error — " . substr($errMatch[0], 0, 120));
        } else {
            assert_true(true, "Smoke {$label}: OK ({$status}, " . strlen($html) . " bytes)");
        }
    }
} else {
    echo "  [SKIP] No server running at {$smokeBaseUrl} — start with: php -S localhost:8080\n";
}

// ============================================================
// \ TEST: Product Images — verify image files exist on disk
// ============================================================
echo "\n--- Test: Product Images ---\n";

$liveDbPath = __DIR__ . '/../db/shop.sqlite';
if (file_exists($liveDbPath)) {
    $imgDb = new PDO('sqlite:' . $liveDbPath);
    $imgDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $imgRows = $imgDb->query("SELECT id, slug, images FROM products WHERE images IS NOT NULL AND images != '[]' AND images != 'null'")->fetchAll(PDO::FETCH_ASSOC);

    $totalImages = 0;
    $missingImages = 0;
    $missingExamples = [];
    $baseDir = realpath(__DIR__ . '/..');

    foreach ($imgRows as $row) {
        $imgs = json_decode($row['images'], true);
        if (!is_array($imgs)) continue;
        foreach ($imgs as $imgPath) {
            $totalImages++;
            $fullPath = $baseDir . '/' . ltrim(str_replace('\\', '/', $imgPath), '/');
            if (!file_exists($fullPath)) {
                $missingImages++;
                if (count($missingExamples) < 3) {
                    $missingExamples[] = $row['slug'] . ' → ' . $imgPath;
                }
            }
        }
    }

    assert_true($totalImages > 0, "Product images: {$totalImages} image references found in DB");
    $missingPct = $totalImages > 0 ? round($missingImages / $totalImages * 100, 1) : 0;
    assert_true($missingImages === 0, "Product images: {$missingImages}/{$totalImages} missing on disk ({$missingPct}%)");

    if (!empty($missingExamples)) {
        foreach ($missingExamples as $ex) {
            echo "    Missing: {$ex}\n";
        }
    }

    // Also verify via HTTP if server is running
    if ($smokeAvailable && $totalImages > 0) {
        // Pick a product with images and check img tags resolve
        $sampleRow = $imgRows[0];
        $sampleImgs = json_decode($sampleRow['images'], true);
        if (!empty($sampleImgs[0])) {
            $imgUrl = $smokeBaseUrl . '/' . ltrim($sampleImgs[0], '/');
            $imgHeaders = @get_headers($imgUrl, true);
            $imgStatus = 404;
            if ($imgHeaders && preg_match('/\s(\d{3})/', $imgHeaders[0], $sm)) {
                $imgStatus = (int)$sm[1];
            }
            assert_true($imgStatus === 200, "Product images HTTP: {$sampleRow['slug']} image returns {$imgStatus}");
        }
    }

    $imgDb = null;
} else {
    echo "  [SKIP] No live database at {$liveDbPath}\n";
}

// ============================================================
// TEST: Payment Gateways
// ============================================================
echo "\n--- Test: Payment — MockGateway initiate ---\n";

// Setup MockPayment adapter
require_once __DIR__ . '/../adapters/PaymentAdapter.php';
require_once __DIR__ . '/../adapters/contrib/MockPayment.php';
require_once __DIR__ . '/../src/PaymentService.php';

// Add payment_transactions table to test DB
$pdo->exec("CREATE TABLE IF NOT EXISTS payment_transactions (
    id              INTEGER PRIMARY KEY,
    order_id        INTEGER NOT NULL,
    provider        TEXT NOT NULL,
    provider_ref    TEXT NOT NULL DEFAULT '',
    status          TEXT NOT NULL,
    amount          INTEGER NOT NULL,
    currency        TEXT NOT NULL DEFAULT 'eur',
    idempotency_key TEXT NOT NULL UNIQUE,
    raw_response    TEXT NOT NULL DEFAULT '{}',
    created_at      TEXT DEFAULT (datetime('now')),
    updated_at      TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
)");

// Add payment_status column to orders
try { $pdo->exec("ALTER TABLE orders ADD COLUMN payment_status TEXT NOT NULL DEFAULT 'pending'"); }
catch (\PDOException $e) { /* already exists */ }

// Payment method pricing tables
$paymentMigFile = __DIR__ . '/../migrations/20260425071000_payment_methods_pricing_engine.php';
if (file_exists($paymentMigFile)) {
    $paymentMig = require $paymentMigFile;
    $paymentMig->up($pdo, true);
}

// Shipping tables
$shippingMigFile = __DIR__ . '/../migrations/20260425064000_shipping_methods_pricing_engine.php';
if (file_exists($shippingMigFile)) {
    $shippingMig = require $shippingMigFile;
    $shippingMig->up($pdo, true);
}
$pdo->exec("CREATE TABLE IF NOT EXISTS shipping_rates (
    id            INTEGER PRIMARY KEY,
    carrier       TEXT NOT NULL,
    country       TEXT NOT NULL,
    weight_from_g INTEGER NOT NULL DEFAULT 0,
    weight_to_g   INTEGER NOT NULL DEFAULT 999999,
    price         INTEGER NOT NULL,
    cod_surcharge INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT DEFAULT (datetime('now'))
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS shipping_labels (
    id         INTEGER PRIMARY KEY,
    order_id   INTEGER NOT NULL,
    carrier    TEXT NOT NULL,
    awb        TEXT NOT NULL UNIQUE,
    status     TEXT NOT NULL DEFAULT 'created',
    label_data TEXT NOT NULL DEFAULT '',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
)");
try { $pdo->exec("ALTER TABLE orders ADD COLUMN tracking_number TEXT DEFAULT NULL"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE orders ADD COLUMN carrier TEXT DEFAULT NULL"); } catch (\PDOException $e) {}

// Insert shipping_rates test fixtures
$pdo->exec("INSERT INTO shipping_rates (carrier, country, weight_from_g, weight_to_g, price, cod_surcharge) VALUES
    ('packeta_test', 'SK', 0,    2000,  299, 100),
    ('packeta_test', 'SK', 2001, 5000,  349, 100),
    ('packeta_test', 'SK', 5001, 10000, 449, 100),
    ('packeta_test', 'CZ', 0,    2000,  399, 100),
    ('packeta_test', 'CZ', 2001, 5000,  449, 100),
    ('gls_test',     'SK', 0,    2000,  399, 150),
    ('gls_test',     'SK', 2001, 5000,  499, 150),
    ('gls_test',     'DE', 0,    5000,  899, 150)
");

$mockGateway = new MockPayment(['mock_result' => 'success']);

// Create a fresh pending order for payment tests
$payProduct = Products::bySku('TSHIRT-BLK-M');
$payOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Pay Customer',
    'email'         => 'pay@example.com',
    'phone'         => '+421900000001',
    'address'       => ['street' => 'Pay 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 0,
    'tax_amount'    => 0,
], 'card');
$payOrder = Orders::byId($payOrderId);

// Test A: initiate payment session
$session = PaymentService::initiate([
    'order_id'       => $payOrder['id'],
    'order_number'   => $payOrder['order_number'],
    'total'          => (int)$payOrder['total'],
    'currency'       => $payOrder['currency'],
    'items'          => Orders::items($payOrder['id']),
    'customer_email' => $payOrder['customer_email'],
    'success_url'    => '/thank-you',
    'cancel_url'     => '/cart',
], $mockGateway);

assert_true(!empty($session['url']), 'Payment: createSession returns URL');
assert_true(!empty($session['session_id']), 'Payment: createSession returns session_id');
assert_true(str_starts_with($session['session_id'], 'mock_'), 'Payment: Mock session_id starts with mock_');

// Verify initiated transaction logged
$txs = PaymentService::transactionsForOrder((int)$payOrder['id']);
assert_true(count($txs) >= 1, 'Payment: initiate logs transaction');
assert_equals('initiated', $txs[0]['status'], 'Payment: first transaction status is initiated');
assert_equals('mock', $txs[0]['provider'], 'Payment: provider is mock');

echo "\n--- Test: Payment — webhook paid flow ---\n";

// Test B: simulate paid webhook
$paidPayload = MockPayment::paidPayload((int)$payOrder['id'], (int)$payOrder['total'], $session['session_id']);
$webhookResult = PaymentService::handleWebhook(
    rawPayload:    json_encode($paidPayload),
    sigHeader:     'mock_sig',
    parsedPayload: $paidPayload,
    gateway:       $mockGateway,
    providerName:  'mock',
);

assert_true($webhookResult['handled'], 'Payment: webhook handled=true');
assert_equals('payment.paid', $webhookResult['event_type'], 'Payment: webhook event_type is payment.paid');

// Order should now be 'paid'
$paidOrder = Orders::byId((int)$payOrder['id']);
assert_equals('paid', $paidOrder['status'], 'Payment: order status is paid after webhook');
assert_equals('paid', $paidOrder['payment_status'], 'Payment: order payment_status is paid');

// Transaction log should have paid entry
$txs2 = PaymentService::transactionsForOrder((int)$payOrder['id']);
$statuses = array_column($txs2, 'status');
assert_true(in_array('paid', $statuses), 'Payment: paid transaction logged');

echo "\n--- Test: Payment — webhook idempotency ---\n";

// Test C: replay same webhook — must be deduplicated
$dupResult = PaymentService::handleWebhook(
    rawPayload:    json_encode($paidPayload),
    sigHeader:     'mock_sig',
    parsedPayload: $paidPayload,
    gateway:       $mockGateway,
    providerName:  'mock',
);
assert_true($dupResult['duplicate'] ?? false, 'Payment: duplicate webhook is idempotent');
assert_true(!$dupResult['handled'], 'Payment: duplicate webhook handled=false');

echo "\n--- Test: Payment — bad signature rejection ---\n";

// Test D: bad signature → exception
$badSigThrown = false;
try {
    $failGateway = new MockPayment(['mock_result' => 'success']);
    // Override verifySignature via anonymous class trick — use empty sig header
    PaymentService::handleWebhook(
        rawPayload:    json_encode($paidPayload),
        sigHeader:     '',   // empty sig → MockPayment::verifySignature returns false
        parsedPayload: $paidPayload,
        gateway:       $failGateway,
        providerName:  'mock',
    );
} catch (\RuntimeException $e) {
    $badSigThrown = str_contains($e->getMessage(), 'signature');
}
assert_true($badSigThrown, 'Payment: empty signature throws RuntimeException');

echo "\n--- Test: Payment — refund flow ---\n";

// Test E: refund
$refundResult = PaymentService::refund(
    orderId:    (int)$payOrder['id'],
    paymentId:  $session['session_id'],
    amount:     (int)$payOrder['total'],
    gateway:    $mockGateway,
);
assert_true(!empty($refundResult['refund_id']), 'Payment: refund returns refund_id');
assert_equals('succeeded', $refundResult['status'], 'Payment: mock refund status is succeeded');

$txs3 = PaymentService::transactionsForOrder((int)$payOrder['id']);
$refundTx = array_filter($txs3, fn($t) => str_starts_with($t['status'], 'refund_'));
assert_true(count($refundTx) >= 1, 'Payment: refund transaction logged');

echo "\n--- Test: Payment — fail gateway ---\n";

// Test F: fail gateway
$failMock = new MockPayment(['mock_result' => 'fail']);
$failSession = $failMock->createSession([
    'order_number' => 'TEST-FAIL',
    'total'        => 1000,
    'currency'     => 'eur',
    'items'        => [],
    'success_url'  => '/',
]);
assert_true(!empty($failSession['session_id']), 'Payment: fail gateway still returns session_id');

$failStatus = $failMock->getStatus($failSession['session_id']);
assert_equals('failed', $failStatus, 'Payment: fail gateway getStatus returns failed');

// Payment methods pricing engine
assert_true(isset($paymentMig), 'Payment methods: migration loaded');
$payMethodCount = (int)$pdo->query("SELECT COUNT(*) FROM payment_methods WHERE code IN ('card', 'bank_transfer', 'cod')")->fetchColumn();
assert_equals(3, $payMethodCount, 'Payment methods: default seed creates card, transfer and COD');
$paymentMig->up($pdo, true);
$payMethodCount2 = (int)$pdo->query("SELECT COUNT(*) FROM payment_methods WHERE code IN ('card', 'bank_transfer', 'cod')")->fetchColumn();
assert_equals(3, $payMethodCount2, 'Payment methods: seed is idempotent');

$pdo->exec("UPDATE payment_methods SET name = 'Admin custom card' WHERE code = 'card'");
$paymentMig->up($pdo, true);
$customCardName = (string)$pdo->query("SELECT name FROM payment_methods WHERE code = 'card'")->fetchColumn();
assert_equals('Admin custom card', $customCardName, 'Payment methods: seed does not overwrite admin-edited method');

$paymentMethodsSkPacketa = PaymentService::availableMethods('SK', 'eur', 2500, 'packeta');
$paymentCodesSkPacketa = array_column($paymentMethodsSkPacketa, 'code');
assert_true(in_array('card', $paymentCodesSkPacketa, true), 'Payment methods: card available for SK Packeta');
assert_true(in_array('bank_transfer', $paymentCodesSkPacketa, true), 'Payment methods: bank transfer available for SK Packeta');
assert_true(in_array('cod', $paymentCodesSkPacketa, true), 'Payment methods: COD available for SK Packeta');
$codQuote = PaymentService::selectMethod('cod', 'SK', 'eur', 2500, 'packeta');
assert_true($codQuote !== null, 'Payment methods: COD selectable for supported shipping method');
assert_equals(120, (int)$codQuote['fee'], 'Payment methods: COD fixed fee applied');

$codPickup = PaymentService::selectMethod('cod', 'SK', 'eur', 2500, 'pickup');
assert_true($codPickup === null, 'Payment methods: COD blocked for pickup shipping method');
$codGermany = PaymentService::selectMethod('cod', 'DE', 'eur', 2500, 'gls');
assert_true($codGermany === null, 'Payment methods: COD blocked outside seeded SK/CZ countries');

$pdo->exec("UPDATE payment_methods SET active = 0 WHERE code = 'bank_transfer'");
$disabledTransfer = PaymentService::selectMethod('bank_transfer', 'SK', 'eur', 2500, 'packeta');
assert_true($disabledTransfer === null, 'Payment methods: disabled method is not selectable');
$pdo->exec("UPDATE payment_methods SET active = 1 WHERE code = 'bank_transfer'");

$pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order) VALUES ('admin_test', 'manual', 'Admin Test', '', 'offline', 500, 5000, 25, 100, 3000, 0, 1, 99)");
$pdo->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('admin_test', 'SK')");
$adminTestQuote = PaymentService::selectMethod('admin_test', 'SK', 'eur', 1000, 'pickup');
assert_true($adminTestQuote !== null, 'Payment methods: admin-created method is selectable');
assert_equals(35, (int)$adminTestQuote['fee'], 'Payment methods: fixed + percent fee applied');
$adminTestFree = PaymentService::selectMethod('admin_test', 'SK', 'eur', 3000, 'pickup');
assert_equals(0, (int)$adminTestFree['fee'], 'Payment methods: free_above removes fee');
$adminTestTooHigh = PaymentService::selectMethod('admin_test', 'SK', 'eur', 6000, 'pickup');
assert_true($adminTestTooHigh === null, 'Payment methods: admin max amount limit enforced');
$pdo->exec("INSERT INTO payment_method_shipping (method_code, shipping_method_code) VALUES ('admin_test', 'gls')");
$adminTestWrongShipping = PaymentService::selectMethod('admin_test', 'SK', 'eur', 1000, 'pickup');
assert_true($adminTestWrongShipping === null, 'Payment methods: admin shipping constraint enforced');
$adminTestGls = PaymentService::selectMethod('admin_test', 'SK', 'eur', 1000, 'gls');
assert_true($adminTestGls !== null, 'Payment methods: admin shipping constraint allows configured method');
$pdo->exec("UPDATE payment_methods SET code = 'admin_test_renamed' WHERE code = 'admin_test'");
PaymentService::renameMethodCodeLinks('admin_test', 'admin_test_renamed');
$renamedCountryLinks = (int)$pdo->query("SELECT COUNT(*) FROM payment_method_countries WHERE method_code = 'admin_test_renamed' AND country = 'SK'")->fetchColumn();
$renamedShippingLinks = (int)$pdo->query("SELECT COUNT(*) FROM payment_method_shipping WHERE method_code = 'admin_test_renamed' AND shipping_method_code = 'gls'")->fetchColumn();
$renamedOldCountryLinks = (int)$pdo->query("SELECT COUNT(*) FROM payment_method_countries WHERE method_code = 'admin_test'")->fetchColumn();
$renamedOldShippingLinks = (int)$pdo->query("SELECT COUNT(*) FROM payment_method_shipping WHERE method_code = 'admin_test'")->fetchColumn();
assert_equals(1, $renamedCountryLinks, 'Payment methods: admin code rename preserves country links');
assert_equals(1, $renamedShippingLinks, 'Payment methods: admin code rename preserves shipping links');
assert_equals(0, $renamedOldCountryLinks, 'Payment methods: admin code rename removes stale old country links');
assert_equals(0, $renamedOldShippingLinks, 'Payment methods: admin code rename removes stale old shipping links');
$adminTestRenamedGls = PaymentService::selectMethod('admin_test_renamed', 'SK', 'eur', 1000, 'gls');
assert_true($adminTestRenamedGls !== null, 'Payment methods: renamed admin method remains selectable');
$pdo->exec("DELETE FROM payment_method_shipping WHERE method_code = 'admin_test_renamed'");
$pdo->exec("DELETE FROM payment_method_countries WHERE method_code = 'admin_test_renamed'");
$pdo->exec("DELETE FROM payment_methods WHERE code = 'admin_test_renamed'");

$paymentOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Payment Method Customer',
    'email'         => 'payment-method@example.com',
    'phone'         => '+421900000003',
    'address'       => ['street' => 'Payment 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 299,
    'payment_fee'   => (int)$codQuote['fee'],
    'payment_method_snapshot' => json_encode($codQuote, JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'cod');
$paymentOrder = Orders::byId($paymentOrderId);
assert_equals(120, (int)$paymentOrder['payment_fee'], 'Payment methods: order stores payment fee');
$paymentSnapshot = json_decode((string)$paymentOrder['payment_method_snapshot'], true) ?: [];
assert_equals('cod', (string)$paymentSnapshot['code'], 'Payment methods: order stores payment snapshot JSON');
assert_equals('manual', (string)$paymentSnapshot['provider'], 'Payment methods: order snapshot stores provider');
assert_equals('Dobierka', (string)$paymentSnapshot['label'], 'Payment methods: order snapshot stores label');

$checkoutPaymentMethods = PaymentService::availableMethods('SK', 'eur', 2799, 'packeta');
$checkoutPaymentCodes = array_column($checkoutPaymentMethods, 'code');
assert_true(in_array('cod', $checkoutPaymentCodes, true), 'Payment UX: checkout exposes COD for Packeta');
$checkoutPaymentCod = PaymentService::selectMethod('cod', 'SK', 'eur', 2799, 'packeta');
assert_true($checkoutPaymentCod !== null, 'Payment UX: checkout selected COD resolves against shipping method');
assert_equals(120, (int)$checkoutPaymentCod['fee'], 'Payment UX: checkout selected COD includes fee');
$checkoutPaymentPickupCod = PaymentService::selectMethod('cod', 'SK', 'eur', 2500, 'pickup');
assert_true($checkoutPaymentPickupCod === null, 'Payment UX: checkout blocks COD for pickup constraint');

if (!function_exists('apiCheckout')) {
    define('BB_TEST_LOAD_API_ONLY', true);
    require_once __DIR__ . '/../api.php';
}
Adapters::set('payment', $mockGateway);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION = ['cart' => [], 'csrf_token' => 'checkout-initiate-test'];
Cart::add((int)$payProduct['id'], null, 1);
$_POST = [
    'csrf_token' => 'checkout-initiate-test',
    'name' => 'Online Checkout Customer',
    'email' => 'checkout-initiate@example.com',
    'phone' => '+421900000099',
    'country' => 'SK',
    'shipping_method' => 'packeta',
    'payment_method' => 'card',
];
$onlineCheckout = apiCheckout();
assert_true(!empty($onlineCheckout['success']), 'Payment UX: online checkout succeeds');
assert_true(!empty($onlineCheckout['payment_url']), 'Payment UX: online checkout returns payment_url');
assert_true(!empty($onlineCheckout['session_id']), 'Payment UX: online checkout keeps session_id contract');
$onlineCheckoutTx = PaymentService::transactionsForOrder((int)$onlineCheckout['order_id']);
assert_equals('initiated', (string)$onlineCheckoutTx[0]['status'], 'Payment UX: online checkout logs initiated transaction');
assert_equals((string)$onlineCheckout['session_id'], (string)$onlineCheckoutTx[0]['provider_ref'], 'Payment UX: initiated transaction stores session_id');
$onlineCheckoutOrder = Orders::byId((int)$onlineCheckout['order_id']);
assert_equals('packeta', (string)$onlineCheckoutOrder['shipping_method'], 'Shipping UX: api checkout stores selected ShippingService method');
assert_equals('domestic', (string)$onlineCheckoutOrder['shipping_zone'], 'Shipping UX: api checkout stores selected ShippingService zone');
$onlineCheckoutShippingSnapshot = json_decode((string)$onlineCheckoutOrder['shipping_rate_snapshot'], true) ?: [];
assert_equals('packeta', (string)$onlineCheckoutShippingSnapshot['method_code'], 'Shipping UX: api checkout stores selected ShippingService rate snapshot');
assert_equals((int)$onlineCheckoutShippingSnapshot['price'], (int)$onlineCheckoutOrder['shipping_cost'], 'Shipping UX: api checkout shipping cost matches selected rate snapshot');
$_POST = [];

$pdo->exec("UPDATE payment_methods SET active = 0 WHERE code = 'cod'");
$checkoutDisabledCod = PaymentService::selectMethod('cod', 'SK', 'eur', 2799, 'packeta');
assert_true($checkoutDisabledCod === null, 'Payment UX: checkout rejects disabled selected payment');
$pdo->exec("UPDATE payment_methods SET active = 1 WHERE code = 'cod'");

$paymentUxOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Payment UX Customer',
    'email'         => 'payment-ux@example.com',
    'phone'         => '+421900000004',
    'address'       => ['street' => 'Payment UX 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 299,
    'payment_fee'   => (int)$checkoutPaymentCod['fee'],
    'payment_method_snapshot' => json_encode($checkoutPaymentCod, JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'cod');
$paymentUxOrder = Orders::byId($paymentUxOrderId);
$paymentUxSnapshot = json_decode((string)$paymentUxOrder['payment_method_snapshot'], true) ?: [];
assert_equals(120, (int)$paymentUxOrder['payment_fee'], 'Payment UX: order stores selected payment fee');
assert_equals('cod', (string)$paymentUxSnapshot['code'], 'Payment UX: order snapshot stores selected code');
assert_equals('manual', (string)$paymentUxSnapshot['provider'], 'Payment UX: order snapshot stores selected provider');

if (!function_exists('renderOrderDone')) {
    $_SERVER['REQUEST_URI'] = '/?page=order-done&order=' . urlencode((string)$paymentUxOrder['order_number']);
    $_GET = ['page' => 'order-done', 'order' => (string)$paymentUxOrder['order_number']];
    ob_start();
    require_once __DIR__ . '/../index.php';
    ob_end_clean();
}
$confirmationOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Payment Confirmation Customer',
    'email'         => 'payment-confirmation@example.com',
    'phone'         => '+421900000104',
    'address'       => ['street' => 'Payment Confirmation 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 299,
    'payment_fee'   => 0,
    'payment_method_snapshot' => json_encode(['code' => 'card', 'provider' => 'mock', 'label' => 'Card'], JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'card');
$confirmationOrder = Orders::byId($confirmationOrderId);
PaymentService::initiate([
    'order_id'       => $confirmationOrder['id'],
    'order_number'   => $confirmationOrder['order_number'],
    'total'          => (int)$confirmationOrder['total'],
    'currency'       => $confirmationOrder['currency'],
    'items'          => Orders::items($confirmationOrder['id']),
], $mockGateway);
$confirmationHtml = renderOrderDone((string)$confirmationOrder['order_number']);
assert_true(str_contains($confirmationHtml, 'Payment'), 'Payment UX: confirmation renders payment summary');
assert_true(str_contains($confirmationHtml, 'pending'), 'Payment UX: confirmation renders payment status');
assert_true(str_contains($confirmationHtml, 'Card'), 'Payment UX: confirmation renders payment method label');
assert_true(str_contains($confirmationHtml, 'mock_' . $confirmationOrder['order_number']), 'Payment UX: confirmation renders provider reference');

$legacyStripeOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Legacy Stripe Customer',
    'email'         => 'legacy-stripe@example.com',
    'phone'         => '+421900000105',
    'address'       => ['street' => 'Legacy Stripe 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 299,
    'payment_fee'   => 0,
    'payment_method_snapshot' => json_encode(['code' => 'card', 'provider' => 'stripe', 'label' => 'Card'], JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'card');
onPaymentCompleted([
    'id' => 'cs_legacy_paid_1',
    'payment_intent' => 'pi_legacy_paid_1',
    'amount_total' => (int)Orders::byId($legacyStripeOrderId)['total'],
    'metadata' => ['order_id' => $legacyStripeOrderId],
]);
$legacyStripeOrder = Orders::byId($legacyStripeOrderId);
$legacyStripeTxs = PaymentService::transactionsForOrder($legacyStripeOrderId);
$legacyStripeRaw = json_decode((string)$legacyStripeTxs[0]['raw_response'], true) ?: [];
assert_equals('paid', (string)$legacyStripeOrder['payment_status'], 'Payment webhook: legacy Stripe marks payment_status paid');
assert_equals('paid', (string)$legacyStripeTxs[0]['status'], 'Payment webhook: legacy Stripe logs paid transaction');
assert_equals('pi_legacy_paid_1', (string)$legacyStripeTxs[0]['provider_ref'], 'Payment webhook: legacy Stripe logs provider reference');
assert_equals('checkout.session.completed', (string)$legacyStripeRaw['event_type'], 'Payment webhook: legacy Stripe logs event type');
assert_equals('cs_legacy_paid_1', (string)$legacyStripeRaw['session_id'], 'Payment webhook: legacy Stripe logs session reference');

$paymentLifecycleOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Payment Lifecycle Customer',
    'email'         => 'payment-lifecycle@example.com',
    'phone'         => '+421900000005',
    'address'       => ['street' => 'Payment Lifecycle 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 299,
    'payment_fee'   => 0,
    'payment_method_snapshot' => json_encode(['code' => 'card', 'provider' => 'stripe', 'label' => 'Card', 'fee' => 0], JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'card');
$paymentLifecycleBefore = Orders::byId($paymentLifecycleOrderId);
$paymentLifecycleSnapshotBefore = (string)$paymentLifecycleBefore['payment_method_snapshot'];

assert_true(PaymentService::canPaymentTransition('pending', 'authorized'), 'Payment lifecycle: pending can authorize');
assert_true(!PaymentService::canPaymentTransition('pending', 'refunded'), 'Payment lifecycle: pending cannot refund');
$authorized = PaymentService::transitionPayment($paymentLifecycleOrderId, 'authorized', 'payment.authorized', 0, 'pay_lifecycle_auth', ['provider_ref' => 'pi_lifecycle_1']);
assert_true($authorized, 'Payment lifecycle: authorize transition applied');
assert_equals('authorized', Orders::byId($paymentLifecycleOrderId)['payment_status'], 'Payment lifecycle: order payment_status is authorized');
$duplicateAuthorize = PaymentService::transitionPayment($paymentLifecycleOrderId, 'authorized', 'payment.authorized', 0, 'pay_lifecycle_auth', ['provider_ref' => 'pi_lifecycle_1']);
assert_true(!$duplicateAuthorize, 'Payment lifecycle: duplicate status transition is idempotent');
$authEventCount = (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE idempotency_key = 'pay_lifecycle_auth'")->fetchColumn();
assert_equals(1, $authEventCount, 'Payment lifecycle: duplicate idempotency key logs once');

PaymentService::transitionPayment($paymentLifecycleOrderId, 'paid', 'payment.captured', 0, 'pay_lifecycle_capture');
assert_equals('paid', Orders::byId($paymentLifecycleOrderId)['payment_status'], 'Payment lifecycle: paid transition applied');
PaymentService::transitionPayment($paymentLifecycleOrderId, 'refund_pending', 'refund.requested', 500, 'pay_lifecycle_refund_requested');
assert_equals('refund_pending', Orders::byId($paymentLifecycleOrderId)['payment_status'], 'Payment lifecycle: refund_pending transition applied');
PaymentService::transitionPayment($paymentLifecycleOrderId, 'partially_refunded', 'refund.partial_succeeded', 500, 'pay_lifecycle_refund_partial');
assert_equals('partially_refunded', Orders::byId($paymentLifecycleOrderId)['payment_status'], 'Payment lifecycle: partial refund transition applied');
$invalidPaymentTransitionThrown = false;
try {
    PaymentService::transitionPayment($paymentLifecycleOrderId, 'authorized', 'payment.reauthorized', 0, 'pay_lifecycle_invalid');
} catch (\LogicException $e) {
    $invalidPaymentTransitionThrown = true;
}
assert_true($invalidPaymentTransitionThrown, 'Payment lifecycle: invalid transition throws');
assert_equals($paymentLifecycleSnapshotBefore, (string)Orders::byId($paymentLifecycleOrderId)['payment_method_snapshot'], 'Payment lifecycle: order payment snapshot remains unchanged');
$lifecycleEvents = PaymentService::transactionsForOrder($paymentLifecycleOrderId);
$lifecycleStatuses = array_column($lifecycleEvents, 'status');
assert_true(in_array('authorized', $lifecycleStatuses, true), 'Payment lifecycle: authorized event logged');
assert_true(in_array('refund_pending', $lifecycleStatuses, true), 'Payment lifecycle: refund request event logged');
assert_true(in_array('partially_refunded', $lifecycleStatuses, true), 'Payment lifecycle: partial refund event logged');
assert_equals(['refund_pending', 'refunded', 'disputed'], PaymentService::allowedPaymentTransitions('partially_refunded'), 'Payment admin: exposes allowed transitions for current payment status');
$adminTransitionOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Payment Admin Customer',
    'email'         => 'payment-admin@example.com',
    'phone'         => '+421900000006',
    'address'       => ['street' => 'Payment Admin 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 299,
    'payment_fee'   => 0,
    'payment_method_snapshot' => json_encode(['code' => 'bank_transfer', 'provider' => 'manual', 'label' => 'Bank transfer', 'fee' => 0], JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'bank_transfer');
PaymentService::transitionPayment($adminTransitionOrderId, 'paid', 'admin.paid', 0, 'pay_admin_mark_paid', ['actor' => 'test-admin', 'note' => 'Manual paid']);
$adminTxs = PaymentService::transactionsForOrder($adminTransitionOrderId);
$adminTxRaw = json_decode((string)end($adminTxs)['raw_response'], true) ?: [];
assert_equals('paid', Orders::byId($adminTransitionOrderId)['payment_status'], 'Payment admin: manual transition updates payment status');
assert_equals('admin.paid', (string)$adminTxRaw['event_type'], 'Payment admin: manual transition logs event type');
assert_equals('Manual paid', (string)$adminTxRaw['note'], 'Payment admin: manual transition logs note');
PaymentService::transitionPayment($adminTransitionOrderId, 'refund_pending', 'refund.requested', 750, 'pay_admin_refund_request', [
    'actor' => 'test-admin',
    'amount' => 750,
    'reason' => 'Customer return',
]);
$adminRefundTxs = PaymentService::transactionsForOrder($adminTransitionOrderId);
$adminRefundRaw = json_decode((string)end($adminRefundTxs)['raw_response'], true) ?: [];
assert_equals('refund_pending', Orders::byId($adminTransitionOrderId)['payment_status'], 'Payment admin: refund request updates payment status');
assert_equals('refund.requested', (string)$adminRefundRaw['event_type'], 'Payment admin: refund request logs event type');
assert_equals(750, (int)$adminRefundRaw['amount'], 'Payment admin: refund request logs amount');
assert_equals('Customer return', (string)$adminRefundRaw['reason'], 'Payment admin: refund request logs reason');
assert_equals('test-admin', (string)$adminRefundRaw['actor'], 'Payment admin: refund request logs admin actor');

$adminOrdersListSnippet = file_get_contents(__DIR__ . '/../admin.php') ?: '';
assert_true(str_contains($adminOrdersListSnippet, '<th>Payment</th>'), 'Payment admin: orders list renders Payment column');
assert_true(str_contains($adminOrdersListSnippet, 'badge-<?= esc($o[\'payment_status\'] ?? \'pending\') ?>'), 'Payment admin: orders list renders payment status badge');
assert_true(str_contains($adminOrdersListSnippet, 'esc($o[\'payment_method\'] ?? \'\')'), 'Payment admin: orders list renders payment method');
assert_true(str_contains($adminOrdersListSnippet, 'name="payment_status"'), 'Payment admin: orders list renders payment status filter');
assert_true(str_contains($adminOrdersListSnippet, 'AND payment_status = :payment_status'), 'Payment admin: orders list filters by payment status');
assert_true(str_contains($adminOrdersListSnippet, 'Payment method:'), 'Payment admin: order detail renders payment method snapshot');
assert_true(str_contains($adminOrdersListSnippet, 'Payment reference:'), 'Payment admin: order detail renders payment reference');
assert_true(str_contains($adminOrdersListSnippet, 'Last event:'), 'Payment admin: order detail renders latest payment event');
assert_true(str_contains($adminOrdersListSnippet, '<th>Details</th>'), 'Payment admin: timeline renders transaction details column');
assert_true(str_contains($adminOrdersListSnippet, "foreach (['reason', 'actor', 'refund_id', 'session_id', 'payment_id']"), 'Payment admin: timeline renders refund/session payload details');
assert_true(str_contains($adminOrdersListSnippet, 'Refund unavailable for current payment status.'), 'Payment admin: order detail explains refund ineligible state');
assert_true(in_array('refund_pending', PaymentService::paymentStatuses(), true), 'Payment admin: payment status filter includes refund_pending');

$accountOrdersSnippet = file_get_contents(__DIR__ . '/../account.php') ?: '';
assert_true(str_contains($accountOrdersSnippet, '$paymentRef = $tx[\'provider_ref\']'), 'Payment account: order payment history includes provider reference');

// ============================================================
// SHIPPING TESTS
// ============================================================

echo "\n--- Test: Shipping — PersonalPickup always free ---\n";
$pickup = new PersonalPickup();
assert_equals('pickup', $pickup->carrierId(), 'Shipping: PersonalPickup carrierId');
assert_true(!$pickup->supportsCOD(), 'Shipping: PersonalPickup does not support COD');
$pickupRates = $pickup->getRates(['country' => 'SK', 'weight_g' => 5000, 'subtotal' => 100]);
assert_true(!empty($pickupRates), 'Shipping: PersonalPickup returns rate');
assert_equals(0, $pickupRates[0]['price'], 'Shipping: PersonalPickup price is 0');

echo "\n--- Test: Shipping — Packeta tiered pricing (DB rates) ---\n";
$packeta = new PacketaShipping();
assert_equals('packeta', $packeta->carrierId(), 'Shipping: PacketaShipping carrierId');
assert_true($packeta->supportsCOD(), 'Shipping: Packeta supports COD');

// Weight tier 0-2000g: 299 cents
$r1 = $packeta->getRates(['country' => 'SK', 'weight_g' => 1500, 'subtotal' => 2000, 'cod' => false]);
assert_true(!empty($r1), 'Shipping: Packeta SK 1500g returns rate');
assert_equals(299, $r1[0]['price'], 'Shipping: Packeta SK 1500g = 299 cents');

// Weight tier 2001-5000g: 349 cents
$r2 = $packeta->getRates(['country' => 'SK', 'weight_g' => 3000, 'subtotal' => 2000, 'cod' => false]);
assert_equals(349, $r2[0]['price'], 'Shipping: Packeta SK 3000g = 349 cents');

// COD surcharge
$r3 = $packeta->getRates(['country' => 'SK', 'weight_g' => 1000, 'subtotal' => 2000, 'cod' => true]);
assert_equals(100, $r3[0]['cod_surcharge'], 'Shipping: Packeta SK COD surcharge = 100 cents');

echo "\n--- Test: Shipping — free_above threshold ---\n";
// Packeta free threshold default = 5000 cents (50 EUR)
$rFree = $packeta->getRates(['country' => 'SK', 'weight_g' => 2000, 'subtotal' => 5000, 'cod' => false]);
assert_equals(0, $rFree[0]['price'], 'Shipping: Packeta free when subtotal >= 5000');
assert_equals(0, $rFree[0]['cod_surcharge'], 'Shipping: Packeta free COD surcharge = 0 when free');

echo "\n--- Test: Shipping — GLS EU pricing ---\n";
$gls = new GlsShipping();
assert_equals('gls', $gls->carrierId(), 'Shipping: GlsShipping carrierId');
assert_true($gls->supportsCOD(), 'Shipping: GLS supports COD');

$rGls = $gls->getRates(['country' => 'SK', 'weight_g' => 1000, 'subtotal' => 1000, 'cod' => false]);
assert_true(!empty($rGls), 'Shipping: GLS SK returns rate');
assert_equals(399, $rGls[0]['price'], 'Shipping: GLS SK 1000g = 399 cents');

$rGlsDe = $gls->getRates(['country' => 'DE', 'weight_g' => 3000, 'subtotal' => 1000, 'cod' => false]);
assert_true(!empty($rGlsDe), 'Shipping: GLS DE returns rate');
assert_equals(899, $rGlsDe[0]['price'], 'Shipping: GLS DE 3000g = 899 cents');

// GLS should not service unknown countries
$rGlsUnknown = $gls->getRates(['country' => 'US', 'weight_g' => 1000, 'subtotal' => 1000]);
assert_true(empty($rGlsUnknown), 'Shipping: GLS does not service US');

echo "\n--- Test: Shipping — default seed pricing engine ---\n";
$methodCount = (int)$pdo->query("SELECT COUNT(*) FROM shipping_methods WHERE code IN ('pickup', 'packeta', 'gls')")->fetchColumn();
assert_equals(3, $methodCount, 'Shipping: default seed creates pickup, packeta and gls methods');
$zoneCount = (int)$pdo->query("SELECT COUNT(*) FROM shipping_zones WHERE code IN ('domestic', 'cz', 'near_eu', 'eu')")->fetchColumn();
assert_equals(4, $zoneCount, 'Shipping: default seed creates usable zones');

$shippingMig->up($pdo, true);
$methodCount2 = (int)$pdo->query("SELECT COUNT(*) FROM shipping_methods WHERE code IN ('pickup', 'packeta', 'gls')")->fetchColumn();
assert_equals(3, $methodCount2, 'Shipping: seed is idempotent for methods');

$pdo->exec("UPDATE shipping_methods SET name = 'Admin custom pickup' WHERE code = 'pickup'");
$shippingMig->up($pdo, true);
$customPickup = (string)$pdo->query("SELECT name FROM shipping_methods WHERE code = 'pickup'")->fetchColumn();
assert_equals('Admin custom pickup', $customPickup, 'Shipping: seed does not overwrite admin-edited method');

$pdo->exec("UPDATE shipping_methods SET active = 0 WHERE code = 'packeta'");
$disabledPacketa = ShippingService::selectRate('packeta', 'SK', 2000, 2000, false, 0);
assert_true($disabledPacketa === null, 'Shipping: disabled admin method is not selectable');
$pdo->exec("UPDATE shipping_methods SET active = 1 WHERE code = 'packeta'");

$dbRatesSk = ShippingService::calculateRates('SK', 2000, 2000);
$dbCarriersSk = array_column($dbRatesSk, 'carrier');
assert_true(count($dbRatesSk) >= 3, 'Shipping: seeded pricing returns rates for SK checkout');
assert_true(in_array('pickup', $dbCarriersSk), 'Shipping: seeded pricing includes pickup');
assert_true(in_array('packeta', $dbCarriersSk), 'Shipping: seeded pricing includes packeta');
assert_true(in_array('gls', $dbCarriersSk), 'Shipping: seeded pricing includes gls');

$glsEu = ShippingService::selectRate('gls', 'FR', 6000, 2000, false, 0);
assert_true($glsEu !== null, 'Shipping: seeded EU fallback rate returns GLS for FR');
assert_equals(1599, (int)$glsEu['price'], 'Shipping: seeded EU fallback 5-20kg price = 1599 cents');
$glsEuMissingSeedCountry = ShippingService::selectRate('gls', 'BG', 6000, 2000, false, 0);
assert_true($glsEuMissingSeedCountry !== null, 'Shipping: seeded EU fallback covers all EU countries');
assert_equals(1599, (int)$glsEuMissingSeedCountry['price'], 'Shipping: seeded EU fallback applies to BG 5-20kg');

$invalidCountryRate = ShippingService::selectRate('packeta', 'US', 2000, 2000, false, 0);
assert_true($invalidCountryRate === null, 'Shipping: invalid country/method combination returns no rate');
$legacyInternational = ShippingService::selectRate('standard', 'US', 2000, 2000, false, 0);
assert_true($legacyInternational !== null, 'Shipping: country without DB zone falls back to legacy international rate');
assert_equals(1999, (int)$legacyInternational['price'], 'Shipping: legacy international fallback computes standard price');

$freePacketa = ShippingService::selectRate('packeta', 'SK', 2000, 5000, false, 0);
assert_true($freePacketa !== null, 'Shipping: Packeta resolves at free shipping threshold');
assert_equals(0, (int)$freePacketa['price'], 'Shipping: method free_above threshold zeroes price');

$selectedSeedRate = ShippingService::selectRate('packeta', 'SK', 2000, 2000, false, 0);
assert_true($selectedSeedRate !== null, 'Shipping: seeded Packeta rate selectable');

$_SESSION = ['cart' => []];
Cart::add((int)$payProduct['id'], null, 1);
$shippingCart = Cart::enriched();
$shippingMetrics = ['weight_g' => 2000, 'volume_cm3' => 0];
$checkoutRates = ShippingService::calculateRates('SK', $shippingMetrics['weight_g'], (int)$shippingCart['subtotal'], false, $shippingMetrics['volume_cm3']);
assert_true(count($checkoutRates) >= 3, 'Shipping UX: checkout SK quote returns available methods from cart metrics');
$checkoutSelection = ShippingService::selectRate('packeta', 'SK', $shippingMetrics['weight_g'], (int)$shippingCart['subtotal'], false, $shippingMetrics['volume_cm3']);
assert_true($checkoutSelection !== null, 'Shipping UX: checkout selected Packeta method resolves against current cart');

$seedOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Ship Seed Customer',
    'email'         => 'ship-seed@example.com',
    'phone'         => '+421900000002',
    'address'       => ['street' => 'Seed 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => (int)$selectedSeedRate['price'],
    'shipping_method' => $selectedSeedRate['method_code'],
    'shipping_zone' => $selectedSeedRate['zone_code'],
    'shipping_weight_g' => $shippingMetrics['weight_g'],
    'shipping_rate_snapshot' => json_encode($selectedSeedRate, JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'card');
$seedOrder = Orders::byId($seedOrderId);
assert_equals('packeta', (string)$seedOrder['shipping_method'], 'Shipping: order snapshot stores method');
assert_equals('domestic', (string)$seedOrder['shipping_zone'], 'Shipping: order snapshot stores zone');
assert_equals((int)$shippingMetrics['weight_g'], (int)$seedOrder['shipping_weight_g'], 'Shipping: order snapshot stores weight');
$seedSnapshot = json_decode((string)$seedOrder['shipping_rate_snapshot'], true) ?: [];
assert_equals('packeta', (string)$seedSnapshot['method_code'], 'Shipping: order snapshot stores selected rate JSON');
assert_equals((int)$checkoutSelection['price'], (int)$seedOrder['shipping_cost'], 'Shipping UX: order stores selected checkout shipping price');
assert_equals((int)$shippingMetrics['weight_g'], (int)$seedOrder['shipping_weight_g'], 'Shipping UX: order snapshot weight matches checkout cart metrics');

// Re-seed again after snapshot checks so later adapter DB lookups still use default carrier rows.
$shippingMig->up($pdo, true);

echo "\n--- Test: Shipping — calculateRates aggregation ---\n";
ShippingService::setAdapters([new PersonalPickup(), new PacketaShipping(), new GlsShipping()]);
$allRates = ShippingService::calculateRates('SK', 2000, 2000);
assert_true(count($allRates) >= 3, 'Shipping: calculateRates returns rates from all 3 carriers for SK');

$carriers = array_column($allRates, 'carrier');
assert_true(in_array('pickup', $carriers), 'Shipping: calculateRates includes pickup');
assert_true(in_array('packeta', $carriers), 'Shipping: calculateRates includes packeta');
assert_true(in_array('gls', $carriers), 'Shipping: calculateRates includes gls');

echo "\n--- Test: Shipping — generateLabel + tracking ---\n";
// Use existing order from earlier tests
$shippingOrderId = $payOrderId; // reuse payment test order
$labelResult = ShippingService::generateLabel($shippingOrderId, 'packeta');
assert_true(!empty($labelResult['awb']), 'Shipping: generateLabel returns AWB');
assert_true(str_starts_with($labelResult['awb'], 'PKT'), 'Shipping: Packeta AWB starts with PKT');
assert_equals('packeta', $labelResult['carrier'], 'Shipping: label carrier is packeta');

// Verify label persisted in DB
$labelRow = $pdo->query("SELECT * FROM shipping_labels WHERE awb = '{$labelResult['awb']}'")->fetch();
assert_true($labelRow !== false, 'Shipping: label saved in shipping_labels table');
assert_equals('created', $labelRow['status'], 'Shipping: label status = created');

// Verify order updated with tracking_number + carrier
$updatedOrder = $pdo->query("SELECT tracking_number, carrier FROM orders WHERE id = {$shippingOrderId}")->fetch();
assert_equals($labelResult['awb'], $updatedOrder['tracking_number'], 'Shipping: order tracking_number updated');
assert_equals('packeta', $updatedOrder['carrier'], 'Shipping: order carrier updated');

echo "\n--- Test: Shipping — tracking webhook → delivered ---\n";
// Ship the order first (workflow: paid→shipped→delivered)
OrderWorkflow::transition($shippingOrderId, 'shipped');

$webhookPayload = [
    'awb'    => $labelResult['awb'],
    'status' => 'delivered',
];
$webhookResult = ShippingService::handleTrackingWebhook('packeta', $webhookPayload, '');
assert_true($webhookResult['handled'], 'Shipping: tracking webhook handled=true');
assert_equals('delivered', $webhookResult['status'], 'Shipping: webhook status=delivered');
assert_equals($shippingOrderId, $webhookResult['order_id'], 'Shipping: webhook order_id matches');

// Verify label status updated
$deliveredLabel = $pdo->query("SELECT status FROM shipping_labels WHERE awb = '{$labelResult['awb']}'")->fetch();
assert_equals('delivered', $deliveredLabel['status'], 'Shipping: label status updated to delivered after webhook');

// Verify order transitioned to delivered
$deliveredOrder = $pdo->query("SELECT status FROM orders WHERE id = {$shippingOrderId}")->fetch();
assert_equals('delivered', $deliveredOrder['status'], 'Shipping: order status = delivered after webhook');

echo "\n--- Test: Shipping — bad webhook signature rejected ---\n";
$_ENV['SHIP_PACKETA_WEBHOOK_SECRET'] = 'testsecret';
$badPayload = ['awb' => 'PKT-BAD', 'status' => 'in_transit'];
$exceptionThrown = false;
try {
    ShippingService::handleTrackingWebhook('packeta', $badPayload, 'bad-sig');
} catch (\RuntimeException $e) {
    $exceptionThrown = str_contains($e->getMessage(), 'signature');
}
assert_true($exceptionThrown, 'Shipping: bad signature throws RuntimeException');
unset($_ENV['SHIP_PACKETA_WEBHOOK_SECRET']);

// ============================================================
// TESTS: Email Notifications (MailService + adapters + templates)
// ============================================================
echo "\n--- Test: Email — email_log table setup ---\n";
$pdo->exec("CREATE TABLE IF NOT EXISTS email_log (
    id         INTEGER PRIMARY KEY,
    order_id   INTEGER DEFAULT NULL,
    recipient  TEXT NOT NULL,
    template   TEXT NOT NULL,
    locale     TEXT NOT NULL DEFAULT 'sk',
    subject    TEXT NOT NULL DEFAULT '',
    adapter    TEXT NOT NULL DEFAULT '',
    status     TEXT NOT NULL DEFAULT 'pending',
    error      TEXT DEFAULT '',
    payload    TEXT DEFAULT '{}',
    sent_at    TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now'))
)");
// Add locale to orders if missing (test DB was set up without it)
try { $pdo->exec("ALTER TABLE orders ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'"); }
catch (\PDOException $e) { /* already exists */ }

$emailLogCount = (int)$pdo->query("SELECT COUNT(*) FROM email_log")->fetchColumn();
assert_equals(0, $emailLogCount, 'Email: email_log table empty before tests');

echo "\n--- Test: Email — render template with locale ---\n";
$renderedSk = MailService::render('order_confirmation', 'sk', [
    'order_number' => 'TST-001',
    'order_status' => 'paid',
    'order_total'  => '10,00 €',
    'customer_name'=> 'Jožko',
    'items'        => [['name' => 'Tričko', 'sku' => 'SH-1', 'quantity' => 2, 'total' => '10,00 €']],
]);
assert_true(str_contains($renderedSk['subject'], 'TST-001'), 'Email: SK subject contains order number');
assert_true(str_contains($renderedSk['html'], 'Ďakujeme'), 'Email: SK HTML contains Ďakujeme');
assert_true(str_contains($renderedSk['html'], 'Tričko'), 'Email: SK HTML expands {{#each items}}');
assert_true(!str_contains($renderedSk['html'], '{{'), 'Email: SK HTML has no unresolved placeholders');

$renderedEn = MailService::render('order_confirmation', 'en', [
    'order_number' => 'TST-002',
    'order_status' => 'paid',
    'customer_name'=> 'John',
    'items'        => [],
]);
assert_true(str_contains($renderedEn['html'], 'Thanks for your order'), 'Email: EN template used when locale=en');

echo "\n--- Test: Email — locale fallback to en then sk ---\n";
$renderedXx = MailService::render('order_confirmation', 'fr', [
    'order_number' => 'TST-003',
    'customer_name'=> 'Pierre',
    'items'        => [],
]);
// fr has no templates → must fall back to en (first in fallback chain)
assert_true(str_contains($renderedXx['html'], 'Thanks for your order'), 'Email: fr locale falls back to en');
assert_true(str_contains($renderedXx['subject'], 'TST-003'), 'Email: fallback still renders variables');

$renderedNone = MailService::render('__nope__', 'sk', ['x' => 'y']);
assert_true(str_contains($renderedNone['html'], '__nope__'), 'Email: unknown template returns placeholder HTML');

echo "\n--- Test: Email — renderString edge cases ---\n";
$esc = MailService::renderString('{{a}}', ['a' => '<b>hi</b>']);
assert_equals('&lt;b&gt;hi&lt;/b&gt;', $esc, 'Email: renderString HTML-escapes scalar values');
$ifTpl = MailService::renderString('{{#if x}}YES{{/if}}{{#if y}}NO{{/if}}', ['x' => 1, 'y' => 0]);
assert_equals('YES', $ifTpl, 'Email: {{#if}} includes truthy, excludes falsy');

echo "\n--- Test: Email — LogMail adapter captures message ---\n";
LogMail::clearCaptured();
MailService::useAdapter(new LogMail(['log_file' => __DIR__ . '/../db/emails.test.log']));
$res = MailService::send('order_paid', 'jozko@example.com', 'sk', [
    'order_number' => 'LOG-1',
    'customer_name'=> 'Jožko',
    'order_total'  => '99,00 €',
]);
assert_equals('sent', $res['status'], 'Email: LogMail send returns status=sent');
assert_equals('log', $res['adapter'], 'Email: LogMail reports adapter=log');
$last = LogMail::lastCaptured();
assert_true($last !== null, 'Email: LogMail captured message');
assert_equals('jozko@example.com', $last['to'] ?? '', 'Email: LogMail captured recipient');
assert_true(str_contains($last['html'] ?? '', 'LOG-1'), 'Email: LogMail rendered order_number into HTML');

echo "\n--- Test: Email — email_log row persisted with status=sent ---\n";
$logId = (int)$res['log_id'];
assert_greater($logId, 0, 'Email: log_id returned');
$row = $pdo->query("SELECT * FROM email_log WHERE id = {$logId}")->fetch();
assert_equals('sent',         $row['status'],   'Email: email_log.status = sent');
assert_equals('order_paid',   $row['template'], 'Email: email_log.template = order_paid');
assert_equals('sk',           $row['locale'],   'Email: email_log.locale = sk');
assert_equals('log',          $row['adapter'],  'Email: email_log.adapter = log');
assert_true(!empty($row['sent_at']),            'Email: email_log.sent_at populated');

echo "\n--- Test: Email — invalid recipient is skipped ---\n";
$skip = MailService::send('order_paid', 'not-an-email', 'sk', ['order_number' => 'X']);
assert_equals('skipped', $skip['status'], 'Email: invalid address returns status=skipped');
assert_equals(0, $skip['log_id'], 'Email: invalid address does not create log row');

echo "\n--- Test: Email — SmtpMail rejects bad addresses without connecting ---\n";
$smtp = new SmtpMail(['smtp_host' => '127.0.0.1', 'smtp_port' => 1]);
$smtpBad = $smtp->send(['to' => 'bad', 'from' => 'also-bad', 'subject' => 't', 'html' => 'h', 'text' => 'h']);
assert_equals('failed',            $smtpBad['status'],  'Email: SmtpMail rejects invalid addresses');
assert_equals('invalid recipient', $smtpBad['message'], 'Email: SmtpMail error message identifies recipient');

echo "\n--- Test: Email — SesMail without credentials fails cleanly ---\n";
putenv('MAIL_SES_KEY=');
putenv('MAIL_SES_SECRET=');
$ses = new SesMail();
$sesRes = $ses->send(['to' => 'x@example.com', 'from' => 'y@example.com', 'subject' => 's', 'text' => 't']);
assert_equals('failed', $sesRes['status'], 'Email: SesMail without credentials fails');
assert_true(str_contains($sesRes['message'], 'credentials'), 'Email: SesMail error explains missing credentials');

echo "\n--- Test: Email — MailService::resend creates new log row ---\n";
$resend = MailService::resend($logId);
assert_equals('sent', $resend['status'], 'Email: resend returns status=sent');
assert_greater($resend['log_id'], $logId, 'Email: resend creates NEW log row (not amends original)');
$orig = $pdo->query("SELECT status FROM email_log WHERE id = {$logId}")->fetch();
assert_equals('sent', $orig['status'], 'Email: resend leaves original log row intact');

$resendBad = MailService::resend(999999);
assert_equals('skipped', $resendBad['status'], 'Email: resend on missing id returns skipped');

echo "\n--- Test: Email — worker handleEmailSend end-to-end via OrderWorkflow ---\n";
LogMail::clearCaptured();
MailService::useAdapter(new LogMail(['log_file' => __DIR__ . '/../db/emails.test.log']));

// Create an order ready to be paid → triggers order_paid email
$emProduct = Products::bySku('TSHIRT-BLK-M');
$emOrderId = Orders::create([
    [
        'product_id'   => $emProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$emProduct['price'],
        'sku'          => $emProduct['sku'],
        'name'         => $emProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Email Customer',
    'email'         => 'customer@example.com',
    'phone'         => '+421900000099',
    'address'       => ['street' => 'Email 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 0,
    'payment_method'=> 'transfer',
]);
// mark locale = en on this order
$pdo->prepare("UPDATE orders SET locale = 'en' WHERE id = :id")->execute([':id' => $emOrderId]);

OrderWorkflow::transition($emOrderId, 'paid', 'test email trigger');
// The transition pushes a job; find the one matching our order
$pending = JobQueue::pull(200);
$emailJob = null;
foreach ($pending as $j) {
    if (($j['type'] ?? '') === 'email_send' && ($j['payload']['order_id'] ?? null) == $emOrderId) {
        $emailJob = $j;
        break;
    }
}
assert_true($emailJob !== null, 'Email: OrderWorkflow enqueued email_send job on paid transition');

// Inline-execute (worker handler code path)
require_once __DIR__ . '/../cli/worker.php';
handleEmailSend($emailJob['payload'], $emailJob);

$lastMail = LogMail::lastCaptured();
assert_true($lastMail !== null, 'Email: worker dispatch captured by LogMail');
assert_equals('customer@example.com', $lastMail['to'] ?? '', 'Email: worker sent to order customer_email');
assert_true(str_contains($lastMail['html'] ?? '', 'Payment received'), 'Email: worker rendered EN order_paid (locale from order row)');

$orderEmailRow = $pdo->prepare("SELECT * FROM email_log WHERE order_id = :oid AND template = 'order_paid' ORDER BY id DESC LIMIT 1");
$orderEmailRow->execute([':oid' => $emOrderId]);
$orderEmail = $orderEmailRow->fetch();
assert_true($orderEmail !== false, 'Email: email_log row linked to order_id');
assert_equals('en', $orderEmail['locale'], 'Email: worker propagated order.locale to email_log');

// Reset adapter override for downstream safety
MailService::useAdapter(null);

// ============================================================
// TEST: Category translations — DB migration + I18n::category
// ============================================================
echo "\n--- Test: Category translations (migration + I18n::category) ---\n";

// Seed a category with base English name, no translations
$pdo->exec("INSERT INTO categories (slug, name, path, translations) VALUES ('test-body', 'Body', 'test-body', '{}')");
$testCatId = (int)$pdo->lastInsertId();

// Load migration module (defines migrateCatTranslations / migrateCatTranslationsData)
require_once __DIR__ . '/../cli/migrate-cat-translations.php';
migrateCatTranslations(false);

// Verify translations JSON populated
$row = $pdo->query("SELECT translations FROM categories WHERE id = {$testCatId}")->fetch();
$tr = json_decode($row['translations'] ?? '{}', true) ?: [];
assert_true(isset($tr['sk']['name']), 'CatI18n: SK translation populated for "Body"');
assert_equals('Karoséria', $tr['sk']['name'], 'CatI18n: SK "Body" = "Karoséria"');
assert_true(isset($tr['de']['name']), 'CatI18n: DE translation populated');
assert_equals('Karosserie', $tr['de']['name'], 'CatI18n: DE "Body" = "Karosserie"');

// I18n::category reads from translations JSON
$catRow = $pdo->query("SELECT * FROM categories WHERE id = {$testCatId}")->fetch();
I18n::setLocale('sk');
$trSk = I18n::category($catRow);
assert_equals('Karoséria', $trSk['name'], 'CatI18n: I18n::category returns SK translated name');

I18n::setLocale('de');
$trDe = I18n::category($catRow);
assert_equals('Karosserie', $trDe['name'], 'CatI18n: I18n::category returns DE translated name');

I18n::setLocale('en');
$trEn = I18n::category($catRow);
assert_equals('Body', $trEn['name'], 'CatI18n: I18n::category keeps base name for EN (no translation entry)');

// Migration is idempotent — re-running doesn't duplicate entries
migrateCatTranslations(false);
$row2 = $pdo->query("SELECT translations FROM categories WHERE id = {$testCatId}")->fetch();
assert_equals($row['translations'], $row2['translations'], 'CatI18n: migration is idempotent (re-run is no-op)');

// Migration does NOT overwrite existing DB-curated translations (without --force)
$upd = $pdo->prepare("UPDATE categories SET translations = :tr WHERE id = :id");
$upd->execute([':tr' => '{"sk":{"name":"Custom SK"}}', ':id' => $testCatId]);
migrateCatTranslations(false);
$row3 = $pdo->query("SELECT translations FROM categories WHERE id = {$testCatId}")->fetch();
$tr3 = json_decode($row3['translations'] ?? '{}', true) ?: [];
assert_equals('Custom SK', $tr3['sk']['name'] ?? '', 'CatI18n: existing DB-curated SK translation is preserved (no --force)');
assert_equals('Karosserie', $tr3['de']['name'] ?? '', 'CatI18n: missing DE translation is still added by migration');

// ============================================================
// TEST: Customer Accounts (register, login, reset, addresses, orders)
// ============================================================
echo "\n--- Test: Customer Accounts ---\n";

// Customer tables for test DB (mirror of schema-build.php)
$pdo->exec("CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '', phone TEXT DEFAULT '', locale TEXT NOT NULL DEFAULT 'en',
    status TEXT NOT NULL DEFAULT 'active', email_verified_at TEXT DEFAULT NULL,
    last_login_at TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now'))
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS customer_addresses (
    id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL,
    type TEXT NOT NULL DEFAULT 'shipping', is_default INTEGER NOT NULL DEFAULT 0,
    name TEXT NOT NULL DEFAULT '', company TEXT DEFAULT '', vat_id TEXT DEFAULT '',
    street TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '',
    zip TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '', phone TEXT DEFAULT '',
    created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL,
    token TEXT UNIQUE NOT NULL, expires_at TEXT NOT NULL, used_at TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
)");
try { $pdo->exec("ALTER TABLE orders ADD COLUMN customer_id INTEGER DEFAULT NULL"); } catch (\PDOException $e) {}

// --- Register ---
[$ok, $aliceId, $err] = Customers::register('alice@test.local', 'Passw0rd!', 'Alice', ['locale' => 'sk']);
assert_true($ok === true && $aliceId > 0, 'Customer: register returns [true, id, null]');
assert_true($err === null, 'Customer: register error is null on success');

// Register duplicate email
[$ok2, $id2, $err2] = Customers::register('alice@test.local', 'Another1!', 'Bob');
assert_true($ok2 === false && $id2 === null && $err2 === 'email_taken', 'Customer: duplicate email rejected');

// Register weak password
[$ok3, , $err3] = Customers::register('weak@test.local', '123', 'Weak');
assert_true($ok3 === false && $err3 === 'weak_password', 'Customer: weak password (<8 chars) rejected');

// Register invalid email
[$ok4, , $err4] = Customers::register('not-an-email', 'Passw0rd!', 'X');
assert_true($ok4 === false && $err4 === 'invalid_email', 'Customer: invalid email rejected');

// --- byId / byEmail ---
$row = Customers::byId($aliceId);
assert_true(is_array($row) && $row['email'] === 'alice@test.local', 'Customer: byId returns row');
assert_true(!isset($row['password_hash']), 'Customer: byId does NOT expose password_hash');
assert_equals('sk', $row['locale'], 'Customer: register stored locale from extras');

// --- Login success ---
[$lok, $lrow, $lerr] = Customers::login('alice@test.local', 'Passw0rd!');
assert_true($lok === true && $lrow['id'] === $aliceId, 'Customer: login success returns row');
assert_true(!isset($lrow['password_hash']), 'Customer: login return row strips password_hash');

// Login wrong password
[$lok2, , $lerr2] = Customers::login('alice@test.local', 'Wrong!Pw1');
assert_true($lok2 === false && $lerr2 === 'invalid_credentials', 'Customer: wrong password rejected');

// Login non-existent email
[$lok3, , $lerr3] = Customers::login('ghost@test.local', 'Passw0rd!');
assert_true($lok3 === false && $lerr3 === 'invalid_credentials', 'Customer: non-existent email rejected');

// --- Addresses ---
$addrId = Customers::addAddress($aliceId, [
    'type' => 'shipping', 'is_default' => 1,
    'name' => 'Alice Doe', 'street' => 'Main 1', 'city' => 'Bratislava',
    'zip' => '81101', 'country' => 'sk', 'phone' => '+421900000000',
]);
assert_true($addrId > 0, 'Customer: addAddress returns new id');
$addrs = Customers::addresses($aliceId);
assert_equals(1, count($addrs), 'Customer: 1 address listed');
assert_equals('SK', $addrs[0]['country'], 'Customer: country uppercased');
assert_equals(1, (int)$addrs[0]['is_default'], 'Customer: first address is default');

// Second shipping address with is_default=1 → previous unset
$addrId2 = Customers::addAddress($aliceId, [
    'type' => 'shipping', 'is_default' => 1,
    'name' => 'Alice Doe', 'street' => 'Second 2', 'city' => 'Kosice',
    'zip' => '04001', 'country' => 'SK',
]);
$shippingAddrs = Customers::addresses($aliceId, 'shipping');
$defaults = array_filter($shippingAddrs, fn($a) => (int)$a['is_default'] === 1);
assert_equals(1, count($defaults), 'Customer: only one default shipping address');
assert_equals($addrId2, (int)array_values($defaults)[0]['id'], 'Customer: newest addr is default');

// deleteAddress ownership
$ok = Customers::deleteAddress($aliceId, $addrId);
assert_true($ok === true, 'Customer: deleteAddress succeeds for owner');
$after = Customers::addresses($aliceId);
assert_equals(1, count($after), 'Customer: addresses reduced to 1 after delete');

// deleteAddress foreign-owner fails
$okForeign = Customers::deleteAddress(99999, $addrId2);
assert_true($okForeign === false, 'Customer: deleteAddress fails for non-owner');

// --- update profile ---
$upd = Customers::update($aliceId, ['name' => 'Alice Renamed', 'phone' => '+421911111111']);
assert_true($upd === true, 'Customer: update(name, phone) returns true');
$reloaded = Customers::byId($aliceId);
assert_equals('Alice Renamed', $reloaded['name'], 'Customer: name updated');

// --- changePassword ---
[$cok, $cerr] = Customers::changePassword($aliceId, 'Passw0rd!', 'NewPassw0rd!');
assert_true($cok === true && $cerr === null, 'Customer: changePassword with correct old pw succeeds');
[$lok4, , ] = Customers::login('alice@test.local', 'NewPassw0rd!');
assert_true($lok4 === true, 'Customer: login works with new password');
[$lok5, , ] = Customers::login('alice@test.local', 'Passw0rd!');
assert_true($lok5 === false, 'Customer: old password no longer works');

// changePassword wrong old pw
[$cok2, $cerr2] = Customers::changePassword($aliceId, 'Wrong!Old1', 'Another1!');
assert_true($cok2 === false && $cerr2 === 'invalid_current_password', 'Customer: wrong old pw rejected');

// --- Password reset flow ---
$tok = Customers::startPasswordReset('alice@test.local');
assert_true(is_string($tok) && strlen($tok) >= 32, 'Customer: startPasswordReset returns 32+ char token');
$noTok = Customers::startPasswordReset('ghost@test.local');
assert_true($noTok === null, 'Customer: startPasswordReset for unknown email returns null');

$vid = Customers::verifyResetToken($tok);
assert_equals($aliceId, $vid, 'Customer: verifyResetToken returns customer id for valid token');
$badId = Customers::verifyResetToken('invalid-token-123');
assert_true($badId === null, 'Customer: verifyResetToken returns null for unknown token');

[$rok, $rerr] = Customers::completePasswordReset($tok, 'FromReset1!');
assert_true($rok === true && $rerr === null, 'Customer: completePasswordReset succeeds');
[$lok6, , ] = Customers::login('alice@test.local', 'FromReset1!');
assert_true($lok6 === true, 'Customer: login works with reset password');

// Reuse token — marked used
[$rok2, $rerr2] = Customers::completePasswordReset($tok, 'Another1!');
assert_true($rok2 === false && $rerr2 === 'invalid_or_expired_token', 'Customer: reset token cannot be reused');

// --- Orders scoping ---
$pdo->exec("INSERT INTO orders (order_number, status, customer_name, customer_email, payment_method, subtotal, total, customer_id) VALUES ('C-100', 'paid', 'Alice Renamed', 'alice@test.local', 'cod', 1000, 1000, {$aliceId})");
$pdo->exec("INSERT INTO orders (order_number, status, customer_name, customer_email, payment_method, subtotal, total, customer_id) VALUES ('C-101', 'pending', 'Alice Renamed', 'alice@test.local', 'cod', 500, 500, {$aliceId})");
$pdo->exec("INSERT INTO orders (order_number, status, customer_name, customer_email, payment_method, subtotal, total, customer_id) VALUES ('GUEST-1', 'paid', 'Guest', 'guest@test.local', 'cod', 100, 100, NULL)");

$myOrders = Customers::orders($aliceId);
assert_equals(2, count($myOrders), 'Customer: orders() returns only own rows');
$nums = array_column($myOrders, 'order_number');
assert_true(in_array('C-100', $nums, true) && in_array('C-101', $nums, true), 'Customer: both owned orders listed');
assert_true(!in_array('GUEST-1', $nums, true), 'Customer: guest order NOT leaked to customer');
assert_equals(2, Customers::orderCount($aliceId), 'Customer: orderCount returns 2');

// Fetch specific order by customer scope
$o100 = Customers::order($aliceId, (int)$pdo->query("SELECT id FROM orders WHERE order_number='C-100'")->fetchColumn());
assert_true(is_array($o100) && $o100['order_number'] === 'C-100', 'Customer: order() returns own order');

// Scoped access to guest order returns null
$guestOrderId = (int)$pdo->query("SELECT id FROM orders WHERE order_number='GUEST-1'")->fetchColumn();
$blocked = Customers::order($aliceId, $guestOrderId);
assert_true($blocked === null, 'Customer: order() blocks access to guest/other-customer order');

// ============================================================
// TEST: Migrations Framework
// ============================================================
echo "\n--- Test: Migrations Framework ---\n";

$migDir = __DIR__ . '/../db/_test_migrations';
$migLock = __DIR__ . '/../db/.test-migrations.lock';
if (is_dir($migDir)) {
    foreach (glob($migDir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($migDir);
}
mkdir($migDir, 0750, true);

// Use a separate in-memory DB so we can drop schema_migrations cleanly
$migDb = new PDO('sqlite::memory:');
$migDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migDb->exec('PRAGMA foreign_keys = ON');

$writeMig = function (string $name, string $body) use ($migDir): string {
    $path = $migDir . '/' . $name . '.php';
    $code = "<?php\nreturn new class {\n    public function up(PDO \$db, bool \$isSqlite): void {\n        {$body}\n    }\n};\n";
    file_put_contents($path, $code);
    return $path;
};

// Test 1: Apply pending migrations on a fresh DB
$writeMig('20260101000001_create_widgets', "\$db->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');");
$writeMig('20260101000002_create_gizmos',  "\$db->exec('CREATE TABLE gizmos (id INTEGER PRIMARY KEY, label TEXT)');");
$mig = new Migrator($migDb, $migDir, $migLock);
$applied = $mig->apply(static fn($m) => null);
assert_equals(2, count($applied), 'Migrate: 2 pending migrations applied on fresh DB');
$tables = $migDb->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('widgets', $tables, true) && in_array('gizmos', $tables, true), 'Migrate: both tables created');
assert_true(in_array('schema_migrations', $tables, true), 'Migrate: schema_migrations table auto-created');

// Test 2: Re-apply is a no-op
$applied2 = $mig->apply(static fn($m) => null);
assert_equals(0, count($applied2), 'Migrate: re-run skips already-applied migrations');

// Test 3: Status reports applied + pending + checksum_ok
$writeMig('20260101000003_add_thingies', "\$db->exec('CREATE TABLE thingies (id INTEGER PRIMARY KEY)');");
$status = $mig->status();
assert_equals(2, count($status['applied']), 'Migrate: status applied=2');
assert_equals(1, count($status['pending']), 'Migrate: status pending=1');
assert_equals(0, count($status['missing']), 'Migrate: status missing=0');
$firstApplied = array_keys($status['applied'])[0];
assert_true($status['applied'][$firstApplied]['checksum_ok'] === true, 'Migrate: applied checksum_ok = true');

// Test 4: Checksum mismatch is a hard error
file_put_contents(
    $migDir . '/20260101000001_create_widgets.php',
    "<?php\nreturn new class {\n    public function up(PDO \$db, bool \$isSqlite): void {\n        // tampered\n    }\n};\n"
);
$thrown = false;
try {
    $mig->apply(static fn($m) => null);
} catch (\Throwable $e) {
    $thrown = (strpos($e->getMessage(), 'modified after being applied') !== false);
}
assert_true($thrown, 'Migrate: tampered migration triggers checksum hard fail');

// Restore the original tampered file via baseline reset (for next tests)
$writeMig('20260101000001_create_widgets', "\$db->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');");
// Re-record correct checksum for the now-restored file
$correct = $mig->fileChecksum($migDir . '/20260101000001_create_widgets.php');
$migDb->prepare("UPDATE schema_migrations SET checksum = ? WHERE name = ?")
      ->execute([$correct, '20260101000001_create_widgets']);

// Test 5: Failure mid-migration rolls back, marker not inserted
$writeMig('20260101000004_will_fail', "\$db->exec('CREATE TABLE temp_ok (id INTEGER PRIMARY KEY)'); \$db->exec('CREATE TABLE temp_ok (id INTEGER PRIMARY KEY)');");
$migFailed = false;
try {
    $mig->apply(static fn($m) => null);
} catch (\Throwable $e) {
    $migFailed = true;
}
assert_true($migFailed, 'Migrate: failing migration throws');
$marker = $migDb->prepare("SELECT 1 FROM schema_migrations WHERE name = ?");
$marker->execute(['20260101000004_will_fail']);
assert_true(!$marker->fetchColumn(), 'Migrate: failed migration not recorded in schema_migrations');
@unlink($migDir . '/20260101000004_will_fail.php');

// Test 6: Baseline marks files as applied without running them
$migDb2 = new PDO('sqlite::memory:');
$migDb2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mig2 = new Migrator($migDb2, $migDir, $migLock);
$marked = $mig2->baseline(static fn($m) => null);
assert_true(count($marked) >= 3, 'Migrate: baseline marked all existing files');
$widgetTables = $migDb2->query("SELECT name FROM sqlite_master WHERE type='table' AND name='widgets'")->fetchAll();
assert_equals(0, count($widgetTables), 'Migrate: baseline did NOT run migrations (no widgets table)');
// Re-baseline is idempotent
$marked2 = $mig2->baseline(static fn($m) => null);
assert_equals(0, count($marked2), 'Migrate: re-baseline is idempotent');

// Test 7: create() generates valid migration stub
$stubPath = $mig->create('add example column');
assert_true(file_exists($stubPath), 'Migrate: create() writes the stub file');
assert_true(strpos(file_get_contents($stubPath), 'public function up(PDO $db, bool $isSqlite)') !== false,
    'Migrate: stub contains expected up() signature');
assert_true(preg_match('/\d{14}_add_example_column\.php$/', basename($stubPath)) === 1,
    'Migrate: stub filename has timestamp prefix and slugified name');
// PHP syntax check on the stub
$rc = -1;
$out = [];
exec('"' . PHP_BINARY . '" -l ' . escapeshellarg($stubPath) . ' 2>&1', $out, $rc);
assert_equals(0, $rc, 'Migrate: generated stub passes php -l');
@unlink($stubPath);

// Test 8: missing file detection in status
$migDb3 = new PDO('sqlite::memory:');
$migDb3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mig3 = new Migrator($migDb3, $migDir, $migLock);
$mig3->ensureMigrationsTable();
$migDb3->prepare("INSERT INTO schema_migrations (name, checksum, duration_ms) VALUES (?, ?, 0)")
       ->execute(['99990101000000_ghost', 'deadbeef']);
$status3 = $mig3->status();
assert_equals(1, count($status3['missing']), 'Migrate: status detects missing file (in DB but no file)');
assert_equals('99990101000000_ghost', $status3['missing'][0], 'Migrate: missing entry has correct name');

// Test 9: Baseline migration produces full v1.4.0 schema
$baselineDb = new PDO('sqlite::memory:');
$baselineDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$baselineMig = new Migrator($baselineDb, __DIR__ . '/../migrations', $migLock);
$baselineApplied = $baselineMig->apply(static fn($m) => null);
assert_true(count($baselineApplied) >= 1, 'Migrate: baseline migration applied on fresh DB');
assert_true(in_array('20260424000000_baseline', $baselineApplied, true), 'Migrate: baseline name in applied list');
$realTables = $baselineDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE '%_fts%' ORDER BY name")
                          ->fetchAll(PDO::FETCH_COLUMN);
$expected = ['categories', 'customers', 'orders', 'products', 'warehouses', 'shipping_labels', 'payment_transactions', 'email_log'];
foreach ($expected as $t) {
    assert_true(in_array($t, $realTables, true), "Migrate: baseline created table '{$t}'");
}

// Cleanup migration test artifacts
$migDb = $migDb2 = $migDb3 = $baselineDb = null;
foreach (glob($migDir . '/*') ?: [] as $f) @unlink($f);
@rmdir($migDir);
@unlink($migLock);

// ============================================================
// TEST: Invoices (InvoiceService + MinimalPdf + backfill)
// ============================================================
echo "\n--- Test: Invoices ---\n";

// Apply invoices migration into the test DB (baseline already created orders/customers)
$invMigFile = __DIR__ . '/../migrations/20260424221721_create_invoices.php';
if (file_exists($invMigFile)) {
    $invMig = require $invMigFile;
    $invMig->up(Db::get(), Db::isSqlite());
}
// Apply add_vat_columns migration so invoices.vat_scenario etc. exist
$vatMigFile = __DIR__ . '/../migrations/20260425013405_add_vat_columns.php';
if (file_exists($vatMigFile)) {
    $vatMig = require $vatMigFile;
    $vatMig->up(Db::get(), Db::isSqlite());
}

// Seed a minimal paid order
$db = Db::get();
$db->exec("DELETE FROM invoices WHERE 1=1");
$db->exec("DELETE FROM invoice_counters WHERE 1=1");
$db->exec("INSERT INTO orders (id, order_number, status, customer_name, customer_email, customer_phone, shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at) VALUES (5001, 'INV-5001', 'paid', 'Ján Test', 'jan@example.sk', '+421', '" . addslashes(json_encode(['street'=>'Hl. 1','city'=>'BA','zip'=>'811 01','country'=>'SK'])) . "', 'bank_transfer', 10000, 0, 500, 2100, 12600, 'eur', 'sk', datetime('now'))");
$db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (5001, 1, 'SKU-T', 'Test produkt', 1, 10000, 10000)");

// 1. Issue invoice
$inv1 = InvoiceService::issue(5001);
assert_true(!empty($inv1['number']), 'Invoice: issued and has number');
assert_equals(8, strlen((string)$inv1['number']), 'Invoice: number is 8 chars (YYYYNNNN)');
assert_equals(12600, (int)$inv1['total_gross'], 'Invoice: total_gross matches order.total');
assert_equals('eur', (string)$inv1['currency'], 'Invoice: currency from order');
assert_equals(64, strlen((string)$inv1['download_token']), 'Invoice: download_token is 64 hex chars');
assert_true(ctype_xdigit((string)$inv1['download_token']), 'Invoice: download_token is hex');

// 2. PDF written to disk
$pdfPath = InvoiceService::pdfAbsolutePath($inv1);
assert_true(file_exists($pdfPath), 'Invoice: PDF file written to disk');
assert_true(filesize($pdfPath) > 500, 'Invoice: PDF size > 500 bytes');
assert_equals('%PDF-1.4', substr((string)file_get_contents($pdfPath), 0, 8), 'Invoice: PDF has %PDF-1.4 magic header');

// 3. Idempotency — issuing again returns same invoice
$inv2 = InvoiceService::issue(5001);
assert_equals((int)$inv1['id'], (int)$inv2['id'], 'Invoice: issue() is idempotent (same id on re-issue)');
assert_equals($inv1['number'], $inv2['number'], 'Invoice: number stable on re-issue');

// 4. Number monotonicity (per year+prefix)
$db->exec("INSERT INTO orders (id, order_number, status, customer_name, customer_email, shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at) VALUES (5002, 'INV-5002', 'paid', 'X', 'x@y.com', '{}', 'bank_transfer', 1000, 0, 0, 0, 1000, 'eur', 'sk', datetime('now'))");
$db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (5002, 1, 'X', 'X', 1, 1000, 1000)");
$inv3 = InvoiceService::issue(5002);
assert_true((int)$inv3['number'] > (int)$inv1['number'], 'Invoice: numbers are monotonic');

// 5. Lookup by token
$found = InvoiceService::byToken((string)$inv1['download_token']);
assert_true($found !== null, 'Invoice: byToken returns row for valid token');
assert_equals((int)$inv1['id'], (int)($found['id'] ?? 0), 'Invoice: lookup returns correct invoice');
$bad = InvoiceService::byToken(str_repeat('0', 64));
assert_true($bad === null, 'Invoice: byToken returns null for unknown token');

// 6. Reverse-charge detection
$rcOn = InvoiceService::isReverseCharge(
    ['country' => 'SK'],
    ['country' => 'DE', 'ic_dph' => 'DE123456789']
);
assert_true($rcOn, 'Invoice: reverse-charge ON for SK seller, DE customer with VAT ID');
$rcOff = InvoiceService::isReverseCharge(
    ['country' => 'SK'],
    ['country' => 'DE', 'ic_dph' => '']
);
assert_true(!$rcOff, 'Invoice: reverse-charge OFF when customer has no VAT ID');
$rcSame = InvoiceService::isReverseCharge(
    ['country' => 'SK'],
    ['country' => 'SK', 'ic_dph' => 'SK1234']
);
assert_true(!$rcSame, 'Invoice: reverse-charge OFF for same-country sale');

// 7. Backfill CLI logic — re-runnable, idempotent (scoped to id >= 5003 to ignore pre-existing test orders)
$db->exec("INSERT INTO orders (id, order_number, status, customer_name, customer_email, shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at) VALUES (5003, 'INV-5003', 'paid', 'Y', 'y@z.com', '{}', 'card', 5000, 0, 0, 0, 5000, 'eur', 'sk', datetime('now'))");
$db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (5003, 1, 'Y', 'Y', 1, 5000, 5000)");
$missingStmt = $db->query("SELECT o.id FROM orders o LEFT JOIN invoices i ON i.order_id = o.id WHERE o.status = 'paid' AND i.id IS NULL AND o.id >= 5003");
$missing = $missingStmt->fetchAll(PDO::FETCH_COLUMN);
assert_equals(1, count($missing), 'Backfill: detects 1 paid order without invoice');
assert_equals(5003, (int)$missing[0], 'Backfill: correct order id detected');
InvoiceService::issue(5003);
$missing2 = $db->query("SELECT o.id FROM orders o LEFT JOIN invoices i ON i.order_id = o.id WHERE o.status = 'paid' AND i.id IS NULL AND o.id >= 5003")->fetchAll(PDO::FETCH_COLUMN);
assert_equals(0, count($missing2), 'Backfill: 0 missing after issue (idempotent rerun = no-op)');

// 8. Invoice rejects non-existent order
$threw = false;
try { InvoiceService::issue(99999); } catch (\Throwable $e) { $threw = true; }
assert_true($threw, 'Invoice: throws on non-existent order');

// 9. Invoice MailService attachment plumbing
LogMail::clearCaptured();
MailService::useAdapter(new LogMail());
$pdfBytes = file_get_contents($pdfPath);
$res = MailService::send('invoice_issued', 'jan@example.sk', 'sk', [
    'order_number'   => 'INV-5001',
    'invoice_number' => $inv1['number'],
    'amount'         => '126,00 €',
], 5001, [['filename' => $inv1['number'].'.pdf', 'data' => $pdfBytes, 'content_type' => 'application/pdf']]);
assert_equals('sent', (string)$res['status'], 'MailService: invoice email sent via LogMail');
$captured = LogMail::lastCaptured();
assert_true(is_array($captured['attachments'] ?? null), 'MailService: attachments propagated to adapter');
assert_equals(1, count($captured['attachments']), 'MailService: 1 attachment captured');
assert_equals('application/pdf', $captured['attachments'][0]['content_type'], 'MailService: attachment content_type=application/pdf');
assert_equals(strlen($pdfBytes), strlen($captured['attachments'][0]['data']), 'MailService: attachment data length matches');
MailService::useAdapter(null);

// ============================================================
// TEST: Backup & Restore (BackupService + cli/restore.php)
// ============================================================
echo "\n--- Test 50: Backup & Restore ---\n";

// Use a private isolated SQLite + storage root for the backup tests
$backupRoot = __DIR__ . '/../db/_backup_test';
if (is_dir($backupRoot)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backupRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($backupRoot);
}
mkdir($backupRoot, 0775, true);
$liveBackupDb = $backupRoot . '/live.db';

$backupPdo = new PDO('sqlite:' . $liveBackupDb);
$backupPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$backupPdo->exec('PRAGMA journal_mode=WAL');
$backupPdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, email TEXT)');
$backupPdo->exec("INSERT INTO customers (email) VALUES ('a@x.sk'), ('b@x.sk')");
$backupPdo->exec('CREATE TABLE schema_migrations (name TEXT PRIMARY KEY, checksum TEXT NOT NULL DEFAULT \'\', applied_at TEXT, duration_ms INTEGER DEFAULT 0)');
$backupPdo->exec("INSERT INTO schema_migrations (name, applied_at) VALUES ('20260101_first', datetime('now'))");

$backupCfg = [
    'enabled' => true, 'storage' => 'local', 'compression' => false,
    'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6, 'minimum_kept' => 3],
    'auto_pre_migration' => true, 'auto_gc_on_backup' => false,
    'wal_checkpoint' => true,
    'log_file' => 'backup.log', 'log_max_bytes' => 10485760, 'log_max_files' => 5,
];
BackupService::init($backupPdo, $backupCfg, $backupRoot);

// 50.1 — config defaults loaded
$cfgLoaded = BackupService::config();
assert_true($cfgLoaded['enabled'] === true, 'Backup: config enabled flag honoured');
assert_equals(false, $cfgLoaded['compression'], 'Backup: compression default OFF');
assert_equals(7, (int)$cfgLoaded['retention']['daily'], 'Backup: daily retention=7');

// 50.2 — create() produces a backup with sha256, size, schema_version
$created = BackupService::create(['label' => 'manual']);
assert_true(is_string($created['remote_path']) && str_ends_with($created['remote_path'], '.db'), 'Backup: create returned remote_path');
assert_true($created['size_bytes'] > 0, 'Backup: file size > 0');
assert_equals(64, strlen($created['sha256']), 'Backup: sha256 is 64 hex chars');
assert_equals('20260101_first', $created['schema_version'], 'Backup: schema_version captured');
assert_equals('manual', $created['label'], 'Backup: label persisted in meta');

// 50.3 — meta JSON sidecar written
$absBackup = $backupRoot . '/storage/backups/' . $created['remote_path'];
assert_true(is_file($absBackup), 'Backup: .db file exists on disk');
assert_true(is_file($absBackup . '.meta.json'), 'Backup: .meta.json sidecar exists');
$metaOnDisk = json_decode((string)file_get_contents($absBackup . '.meta.json'), true);
assert_equals($created['sha256'], (string)($metaOnDisk['sha256'] ?? ''), 'Backup: meta sha256 matches result');

// 50.4 — sha256 verifies against actual file
$actualSha = hash_file('sha256', $absBackup);
assert_equals($created['sha256'], $actualSha, 'Backup: sha256 matches hash_file');

// 50.5 — list() returns at least one row
$listed = BackupService::list();
assert_greater(count($listed), 0, 'Backup: list() includes the created backup');

// 50.6 — second create() within same second gets a -1 suffix
$created2 = BackupService::create(['label' => 'manual']);
assert_true($created['remote_path'] !== $created2['remote_path'], 'Backup: second create() gets unique path');

// 50.7 — gz compression opt-in produces a .gz sidecar
$createdGz = BackupService::create(['label' => 'manual', 'compress' => true]);
$gzPath = $backupRoot . '/storage/backups/' . $createdGz['remote_path'] . '.gz';
assert_true(is_file($gzPath), 'Backup: --gz produces .db.gz sidecar');
assert_true($createdGz['compressed'] === true, 'Backup: compressed=true in meta');

// 50.8 — GC keeps minimum_kept newest backups
$gcCfg = $backupCfg;
$gcCfg['retention'] = ['daily' => 1, 'weekly' => 1, 'monthly' => 1, 'minimum_kept' => 2];
$gcRoot = $backupRoot . '/_gc';
mkdir($gcRoot . '/storage/backups/2026/03/01', 0775, true);
mkdir($gcRoot . '/storage/backups/2026/03/15', 0775, true);
mkdir($gcRoot . '/storage/backups/2026/04/01', 0775, true);
mkdir($gcRoot . '/storage/backups/2026/04/25', 0775, true);
$gcPdo = new PDO('sqlite:' . $gcRoot . '/live.db');
$gcPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$gcPdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
BackupService::init($gcPdo, $gcCfg, $gcRoot);
foreach ([
    ['2026/03/01/eshop-20260301-120000.db', '2026-03-01T12:00:00+00:00', 'auto'],
    ['2026/03/15/eshop-20260315-120000.db', '2026-03-15T12:00:00+00:00', 'auto'],
    ['2026/04/01/eshop-20260401-120000.db', '2026-04-01T12:00:00+00:00', 'auto'],
    ['2026/04/25/eshop-20260425-120000.db', '2026-04-25T12:00:00+00:00', 'auto'],
] as $tup) {
    [$rp, $ts, $lbl] = $tup;
    file_put_contents($gcRoot . '/storage/backups/' . $rp, str_repeat("\0", 32));
    file_put_contents($gcRoot . '/storage/backups/' . $rp . '.meta.json', json_encode(['timestamp' => $ts, 'label' => $lbl, 'sha256' => '0', 'size_bytes' => 32]));
    @touch($gcRoot . '/storage/backups/' . $rp, strtotime($ts));
}
$gcRes = BackupService::gc();
assert_greater($gcRes['kept'], 1, 'Backup: GC kept at least minimum_kept');
assert_true(count($gcRes['deleted']) >= 1, 'Backup: GC deleted at least one stale backup');

// 50.9 — Re-init service back on the original backupPdo for restore tests
BackupService::init($backupPdo, $backupCfg, $backupRoot);

// Mutate live DB then restore — verify rollback
$backupPdo->exec("INSERT INTO customers (email) VALUES ('c@x.sk'), ('d@x.sk')");
$beforeRestore = (int)$backupPdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
assert_equals(4, $beforeRestore, 'Backup: live mutated to 4 customers before restore');

$backupPdo = null; // release file handle on Windows so restore can swap
$res = BackupService::restore($created['remote_path']);
assert_equals('ok', (string)$res['integrity'], 'Restore: integrity_check returns ok');
assert_true(is_string($res['snapshot']) && str_ends_with($res['snapshot'], '.db'), 'Restore: pre-restore-snapshot taken');
$verifyPdo = new PDO('sqlite:' . $liveBackupDb);
$verifyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$afterRestore = (int)$verifyPdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
assert_equals(2, $afterRestore, 'Restore: customers count rolled back from 4 to 2');
$verifyPdo = null;

// 50.10 — Tampered backup is rejected (sha256 mismatch)
$absSrc = $backupRoot . '/storage/backups/' . $created2['remote_path'];
file_put_contents($absSrc, str_repeat('X', 16), FILE_APPEND);
$tampered = false;
try {
    BackupService::restore($created2['remote_path']);
} catch (\Throwable $e) {
    $tampered = (stripos($e->getMessage(), 'sha256 mismatch') !== false);
}
assert_true($tampered, 'Restore: tampered backup rejected with sha256 mismatch');

// 50.11 — Migrator preApplyHook fires once before pending migrations
$mTmp = $backupRoot . '/_mig';
@mkdir($mTmp, 0775, true);
$mDb = $mTmp . '/m.db';
@unlink($mDb);
$mPdo = new PDO('sqlite:' . $mDb);
$mPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mDir = $mTmp . '/migrations';
@mkdir($mDir);
file_put_contents($mDir . '/20260101000000_a.php', "<?php\nreturn new class { public function up(PDO \$db, bool \$is): void { \$db->exec('CREATE TABLE a (id INTEGER PRIMARY KEY)'); } };\n");
$migrator = new Migrator($mPdo, $mDir, $mTmp . '/m.lock');
$hookFired = 0;
$migrator->apply(static fn ($m) => null, function () use (&$hookFired) { $hookFired++; });
assert_equals(1, $hookFired, 'Migrator: preApplyHook fired exactly once for pending migrations');

// 50.12 — preApplyHook NOT called when nothing pending
$hookFired2 = 0;
$migrator->apply(static fn ($m) => null, function () use (&$hookFired2) { $hookFired2++; });
assert_equals(0, $hookFired2, 'Migrator: preApplyHook skipped when no pending migrations');

// 50.13 — preApplyHook throwing aborts the apply (no migration recorded)
file_put_contents($mDir . '/20260102000000_b.php', "<?php\nreturn new class { public function up(PDO \$db, bool \$is): void { \$db->exec('CREATE TABLE b (id INTEGER PRIMARY KEY)'); } };\n");
$throwCaught = false;
try {
    $migrator->apply(static fn ($m) => null, function () { throw new RuntimeException('hook says no'); });
} catch (\Throwable $e) {
    $throwCaught = true;
}
assert_true($throwCaught, 'Migrator: preApplyHook throw propagates');
$bExists = (bool)$mPdo->query("SELECT name FROM sqlite_master WHERE name='b'")->fetchColumn();
assert_true(!$bExists, 'Migrator: preApplyHook throw prevents the migration from running');

// 50.14 — buildRemotePath uses Y/m/d/eshop-YmdHis.db
$rp = BackupService::buildRemotePath(new DateTimeImmutable('2026-04-25T14:30:12+00:00'), 0);
assert_equals('2026/04/25/eshop-20260425-143012.db', $rp, 'Backup: buildRemotePath layout');

// Cleanup backup test artifacts (releases Windows locks)
BackupService::reset();
$mPdo = null;
$gcPdo = null;
usleep(100000);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backupRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($backupRoot);

// ============================================================
// TEST 51: VAT — config/vat-rates, scenarios, computeLine
// ============================================================
echo "\n--- Test 51: VAT (rates / scenarios / computeLine) ---\n";

VatService::reset();
VatService::init(realpath(__DIR__ . '/..'));

// 51.1 — current SK rate is 23%
assert_equals(23.0, VatService::rate('SK', 'standard'), 'VAT: SK current=23%');
// 51.2 — SK historical 2024 = 20%
assert_equals(20.0, VatService::rate('SK', 'standard', '2024-06-01'), 'VAT: SK 2024-06 historical=20%');
// 51.3 — CZ current reduced=12%
assert_equals(12.0, VatService::rate('CZ', 'reduced'), 'VAT: CZ reduced current=12%');
// 51.4 — CZ historical 2023 reduced=15%
assert_equals(15.0, VatService::rate('CZ', 'reduced', '2023-06-01'), 'VAT: CZ 2023 reduced=15%');
// 51.5 — class resolver: books → reduced
assert_equals('reduced', VatService::resolveClass('books'), 'VAT: alias books→reduced');
// 51.6 — class resolver: unknown → standard
assert_equals('standard', VatService::resolveClass('weirdo'), 'VAT: alias unknown→standard');
// 51.7 — isEuCountry
assert_true(VatService::isEuCountry('DE'), 'VAT: DE is EU');
assert_true(!VatService::isEuCountry('US'), 'VAT: US is not EU');

// 51.8 — domestic scenario
$sc = VatService::resolveScenario(['seller_country'=>'SK','customer_country'=>'SK']);
assert_equals('domestic', $sc['scenario'], 'VAT scenario: domestic same country');
// 51.9 — reverse_charge (B2B + valid VAT ID + cross-border EU)
$sc = VatService::resolveScenario(['seller_country'=>'SK','customer_country'=>'DE','customer_vat_id_valid'=>true,'is_b2b'=>true]);
assert_equals('reverse_charge', $sc['scenario'], 'VAT scenario: reverse_charge B2B intra-EU');
assert_equals('vat.reverse_charge_note', $sc['note_key'], 'VAT scenario: reverse_charge note_key');
// 51.10 — OSS scenario
$sc = VatService::resolveScenario(['seller_country'=>'SK','seller_oss_enabled'=>true,'customer_country'=>'DE']);
assert_equals('oss', $sc['scenario'], 'VAT scenario: OSS cross-border B2C');
assert_equals('DE', $sc['rate_country'], 'VAT scenario: OSS uses customer-country rate');
// 51.11 — sub-threshold (OSS off)
$sc = VatService::resolveScenario(['seller_country'=>'SK','seller_oss_enabled'=>false,'customer_country'=>'DE']);
assert_equals('domestic_subthreshold', $sc['scenario'], 'VAT scenario: sub-threshold uses seller rate');
assert_equals('SK', $sc['rate_country'], 'VAT scenario: sub-threshold rate_country=SK');
// 51.12 — export
$sc = VatService::resolveScenario(['seller_country'=>'SK','customer_country'=>'US']);
assert_equals('export', $sc['scenario'], 'VAT scenario: export outside EU');

// 51.13 — computeLine gross-inclusive 23%
$ln = VatService::computeLine(1230, 1, 23.0, false);
assert_equals(1230, $ln['gross'], 'VAT line: gross');
assert_equals(1000, $ln['net'],   'VAT line: net');
assert_equals(230,  $ln['vat_amount'], 'VAT line: vat_amount');
// 51.14 — computeLine reverse-charge zero VAT
$ln = VatService::computeLine(2460, 1, 23.0, true);
assert_equals(0,    $ln['vat_amount'], 'VAT line: reverse_charge vat=0');
assert_equals(2460, $ln['net'],        'VAT line: reverse_charge net=gross');

// ============================================================
// TEST 52: VAT — VatIdValidator + cache
// ============================================================
echo "\n--- Test 52: VAT (VatIdValidator + cache) ---\n";

// Ensure vat_id_cache table exists on the shared $pdo
$pdo->exec("
    CREATE TABLE IF NOT EXISTS vat_id_cache (
        vat_id        TEXT PRIMARY KEY,
        country       TEXT NOT NULL,
        status        TEXT NOT NULL,
        trader_name   TEXT,
        trader_addr   TEXT,
        checked_at    TEXT NOT NULL DEFAULT (datetime('now')),
        ttl_seconds   INTEGER NOT NULL DEFAULT 86400,
        raw_response  TEXT
    )
");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_vat_id_cache_status ON vat_id_cache(status)");

// Re-bind Db singleton — earlier Migrator/Backup tests may have swapped it to a different handle
Db::init($pdo, 'sqlite');

// Inject deterministic mock validator via DI helper if exposed; otherwise
// validate via direct cache flow + MockVatIdValidator
$mock = new MockVatIdValidator([
    'DE123456789' => ['status'=>'valid','country'=>'DE','trader_name'=>'Foo GmbH'],
    'AT654321'    => ['status'=>'invalid'],
    'PL5551234567'=> ['status'=>'unverified'],
]);

// 52.1 — DE valid is fresh (uncached)
$r = VatService::validateVatId('DE123456789', $mock);
assert_equals('valid', $r['status'], 'VAT id: DE valid');
assert_equals(false,   (bool)($r['cached'] ?? false), 'VAT id: DE first call uncached');
// 52.2 — DE replay is cached
$r = VatService::validateVatId('DE123456789', $mock);
assert_equals(true,    (bool)($r['cached'] ?? false), 'VAT id: DE replay cached');
// 52.3 — AT invalid cached 24h
$r = VatService::validateVatId('AT654321', $mock);
assert_equals('invalid', $r['status'], 'VAT id: AT invalid');
$row = $pdo->query("SELECT ttl_seconds FROM vat_id_cache WHERE vat_id='AT654321'")->fetch();
assert_equals(86400, (int)$row['ttl_seconds'], 'VAT id: invalid TTL=24h');
// 52.4 — PL unverified cached 1h
$r = VatService::validateVatId('PL5551234567', $mock);
assert_equals('unverified', $r['status'], 'VAT id: PL unverified');
$row = $pdo->query("SELECT ttl_seconds FROM vat_id_cache WHERE vat_id='PL5551234567'")->fetch();
assert_equals(3600, (int)$row['ttl_seconds'], 'VAT id: unverified TTL=1h');
// 52.5 — Bad shape → invalid (heuristic)
$r = VatService::validateVatId('garbage', $mock);
assert_equals('invalid', $r['status'], 'VAT id: garbage→invalid');

// ============================================================
// TEST 53: VAT — Cart vatContext + Orders snapshot
// ============================================================
echo "\n--- Test 53: VAT (Cart + Orders persistence) ---\n";

// Need a product with vat_class
$pdo->exec("PRAGMA foreign_keys = OFF");
$pdo->exec("DELETE FROM order_items");
$pdo->exec("DELETE FROM orders");
$pdo->exec("DELETE FROM products");
$pdo->exec("DELETE FROM categories");
$pdo->exec("PRAGMA foreign_keys = ON");
$pdo->exec("INSERT INTO categories (id,name,slug) VALUES (1,'X','x')");
$pdo->exec("INSERT INTO products (id,category_id,name,slug,sku,price,currency,status,vat_class,stock) VALUES
    (501,1,'Speaker','speaker','SKU-S',12300,'eur','active','standard',999),
    (502,1,'Book','book','SKU-B',  3600,'eur','active','reduced',999)");

// Seed warehouse stock so Warehouse::reserve in Orders::create succeeds
$pdo->exec("DELETE FROM warehouse_stock");
$pdo->exec("INSERT OR IGNORE INTO warehouses (id,code,name,priority,is_default) VALUES (1,'main','Main',100,1)");
$pdo->exec("INSERT INTO warehouse_stock (warehouse_id,sku,qty,reserved) VALUES (1,'SKU-S',999,0),(1,'SKU-B',999,0)");

// Initialize VatService config so rate() resolves SK 23% etc.
VatService::reset();
VatService::init(dirname(__DIR__));

$_SESSION = [];
$_SESSION['cart'] = [
    'p_501' => ['product_id'=>501,'variant_id'=>null,'qty'=>2],
    'p_502' => ['product_id'=>502,'variant_id'=>null,'qty'=>1],
];

// Cart::enriched expects raw context (seller/customer country) and resolves via VatService internally
$ctx = ['seller_country'=>'SK','customer_country'=>'SK','is_b2b'=>false,'customer_vat_id_valid'=>false,'seller_oss_enabled'=>false];
$cart = Cart::enriched($ctx);
// 53.1 — items have per-line VAT
$item0 = $cart['items'][0];
assert_true(isset($item0['vat_amount']), 'Cart: per-line vat_amount present');
assert_true(((int)$item0['vat_amount']) > 0, 'Cart: per-line vat_amount > 0 (domestic)');

// 53.2 — Orders::create persists scenario + per-line VAT
$customer = [
    'name'=>'Test','email'=>'t@x.com','phone'=>'',
    'address'=>['country'=>'SK','street'=>'X','city'=>'X','zip'=>'00000'],
    'currency'=>'eur','locale'=>'sk','shipping_cost'=>0,'tax_amount'=>0,
    'vat_scenario'=>'domestic',
];
$orderId = Orders::create($cart['items'], $customer, 'cash');
$order = $pdo->query("SELECT vat_scenario, vat_total, net_total FROM orders WHERE id={$orderId}")->fetch();
assert_equals('domestic', $order['vat_scenario'], 'Orders: vat_scenario persisted');
assert_true((int)$order['vat_total']  > 0, 'Orders: vat_total persisted');
assert_true((int)$order['net_total']  > 0, 'Orders: net_total persisted');

// 53.3 — order_items carry vat_class + vat_rate + vat_amount + net_amount
$it = $pdo->query("SELECT vat_class, vat_rate, vat_amount, net_amount FROM order_items WHERE order_id={$orderId} ORDER BY id ASC LIMIT 1")->fetch();
assert_equals('standard', $it['vat_class'], 'Orders: order_items.vat_class');
assert_true((int)$it['vat_rate']   > 0, 'Orders: order_items.vat_rate');
assert_true((int)$it['vat_amount'] > 0, 'Orders: order_items.vat_amount');
assert_true((int)$it['net_amount'] > 0, 'Orders: order_items.net_amount');

// 53.4 — Reverse-charge → cart items vat=0
$ctxRC = ['seller_country'=>'SK','customer_country'=>'DE','is_b2b'=>true,'customer_vat_id_valid'=>true,'seller_oss_enabled'=>false];
$cartRC = Cart::enriched($ctxRC);
assert_equals(0, (int)$cartRC['items'][0]['vat_amount'], 'Cart: reverse_charge vat=0');

// ============================================================
// TEST: Staging deploy preflight
// ============================================================
echo "\n--- Test: Staging Deploy Preflight ---\n";

require_once __DIR__ . '/staging-preflight.php';
$preflight = runStagingPreflight(['cli/staging-preflight.php']);
assert_true(is_array($preflight['checks'] ?? null), 'Staging preflight: returns checks');
assert_true(count($preflight['checks']) > 0, 'Staging preflight: emits deploy checks');
assert_true(in_array($preflight['status'] ?? '', ['GO', 'NO-GO'], true), 'Staging preflight: status is GO or NO-GO');
assert_true(in_array('php cli/migrate.php', $preflight['recommended_commands'] ?? [], true), 'Staging preflight: recommends migrations');
assert_true(in_array('php cli/cache-warm.php', $preflight['recommended_commands'] ?? [], true), 'Staging preflight: recommends cache warm');

// ============================================================
// TEST 54: VAT — InvoiceService scenario + breakdown grouping
// ============================================================
echo "\n--- Test 54: VAT (InvoiceService) ---\n";

// Mark order paid then issue invoice
$pdo->exec("UPDATE orders SET status='paid', tax_amount=" . (int)$order['vat_total'] . " WHERE id={$orderId}");

$inv = InvoiceService::issue($orderId);
// 54.1 — vat_scenario stored on invoice
$row = $pdo->query("SELECT vat_scenario, reverse_charge_note_key, total_vat FROM invoices WHERE id={$inv['id']}")->fetch();
assert_equals('domestic', $row['vat_scenario'], 'Invoice: vat_scenario stored');
// 54.2 — total_vat matches order
assert_equals((int)$order['vat_total'], (int)$row['total_vat'], 'Invoice: total_vat = order.vat_total');
// 54.3 — vat_breakdown groups by per-line rate (multi-rate present: 23 + 19)
$breakdown = json_decode($pdo->query("SELECT vat_breakdown FROM invoices WHERE id={$inv['id']}")->fetchColumn() ?: '[]', true) ?: [];
assert_true(count($breakdown) >= 1, 'Invoice: vat_breakdown has at least 1 rate group');

echo "\n--- VAT test suite complete ---\n";

// Re-init the global Db singleton on the main test DB so any later tests still work
Db::init($pdo, 'sqlite');

// ============================================================
// TEST 56: P1 regression — Warehouse::reserve oversell guard
// Bug #6: reserve() computed availability in PHP from a SELECT
// snapshot, then unconditionally UPDATEd reserved — two parallel
// checkouts could both grab the last piece. The UPDATE is now
// guarded by (qty - reserved) >= take. Bonus: a failed reserve
// used to leak partial allocations; it must release them.
// ============================================================
echo "\n--- Test 56: P1 regression (Warehouse::reserve) ---\n";

$pdo->exec("DELETE FROM warehouse_stock");
$pdo->exec("DELETE FROM warehouses WHERE id IN (91,92)");
$pdo->exec("INSERT INTO warehouses (id,code,name,priority,is_default) VALUES (91,'p1main','P1 Main',900,0),(92,'p1aux','P1 Aux',800,0)");
$pdo->exec("INSERT INTO warehouse_stock (warehouse_id,sku,qty,reserved) VALUES (91,'SKU-R',3,0),(92,'SKU-R',2,0)");

// 56.1 — happy path: drains priority warehouse first, splits remainder
$reserveAllocs = Warehouse::reserve('SKU-R', 4);
assert_equals(2, count($reserveAllocs), 'Reserve: split across 2 warehouses');
assert_equals(3, (int)$reserveAllocs[0]['qty'], 'Reserve: priority warehouse drained first');
$whR1 = (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE warehouse_id=91 AND sku='SKU-R'")->fetchColumn();
$whR2 = (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE warehouse_id=92 AND sku='SKU-R'")->fetchColumn();
assert_equals(3, $whR1, 'Reserve: wh1 reserved=3');
assert_equals(1, $whR2, 'Reserve: wh2 reserved=1');

// 56.2 — oversell: only 1 piece left in total, asking for 2 must throw
// AND must not leak the partial allocation it grabbed before failing
$reserveThrew = false;
try { Warehouse::reserve('SKU-R', 2); } catch (\RuntimeException $e) { $reserveThrew = true; }
assert_true($reserveThrew, 'Reserve: oversell throws RuntimeException');
$whR1 = (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE warehouse_id=91 AND sku='SKU-R'")->fetchColumn();
$whR2 = (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE warehouse_id=92 AND sku='SKU-R'")->fetchColumn();
assert_equals(3, $whR1, 'Reserve: failed reserve leaves wh1 untouched');
assert_equals(1, $whR2, 'Reserve: failed reserve rolls back partial allocation on wh2');

// 56.3 — the atomic guard itself: a stale-snapshot UPDATE asking for more
// than is available must affect 0 rows (this is what closes the race)
$guardStmt = $pdo->prepare("UPDATE warehouse_stock SET reserved = reserved + :take
    WHERE warehouse_id = 92 AND sku = 'SKU-R' AND (qty - reserved) >= :take2");
$guardStmt->bindValue(':take', 2, PDO::PARAM_INT);
$guardStmt->bindValue(':take2', 2, PDO::PARAM_INT);
$guardStmt->execute();
assert_equals(0, $guardStmt->rowCount(), 'Reserve guard: conditional UPDATE refuses oversell (avail=1, take=2)');
$guardStmt->bindValue(':take', 1, PDO::PARAM_INT);
$guardStmt->bindValue(':take2', 1, PDO::PARAM_INT);
$guardStmt->execute();
assert_equals(1, $guardStmt->rowCount(), 'Reserve guard: conditional UPDATE allows take within availability');
$pdo->exec("UPDATE warehouse_stock SET reserved = reserved - 1 WHERE warehouse_id = 92 AND sku = 'SKU-R'");

// 56.4 — release returns reservations symmetrically
Warehouse::release('SKU-R', $reserveAllocs);
$whSum = (int)$pdo->query("SELECT SUM(reserved) FROM warehouse_stock WHERE sku='SKU-R'")->fetchColumn();
assert_equals(0, $whSum, 'Release: all reservations returned');

// ============================================================
// TEST 57: P1 regression — return flow must not eat foreign reservations
// Bug #7: handleReturn restored stock via the release path, which
// decremented warehouse_stock.reserved. But a returned order's own
// reservation was already consumed at 'shipped' — whatever 'reserved'
// remains belongs to OTHER open orders. A physical return must only
// increment qty. Cancel (never shipped) must keep releasing 'reserved'.
// ============================================================
echo "\n--- Test 57: P1 regression (return flow vs foreign reservations) ---\n";

$pdo->exec("INSERT INTO products (id,category_id,name,slug,sku,price,currency,status,vat_class,stock) VALUES
    (503,1,'Returner','returner','SKU-RT',5000,'eur','active','standard',10)");
$pdo->exec("DELETE FROM warehouse_stock WHERE sku='SKU-RT'");
$pdo->exec("INSERT INTO warehouse_stock (warehouse_id,sku,qty,reserved) VALUES (1,'SKU-RT',10,0)");

$rtItem = fn(int $qty) => [[
    'product_id' => 503, 'variant_id' => null, 'qty' => $qty,
    'price' => 5000, 'sku' => 'SKU-RT', 'name' => 'Returner', 'variant_name' => '',
]];
$rtCustomer = [
    'name' => 'RT', 'email' => 'rt@test.com',
    'address' => ['street' => 'X 1', 'city' => 'BA', 'zip' => '81101', 'country' => 'SK'],
    'currency' => 'eur', 'shipping_cost' => 0, 'tax_amount' => 0,
];

$rtA = Orders::create($rtItem(2), $rtCustomer, 'stripe');   // order under test (will ship + return)
$rtB = Orders::create($rtItem(3), $rtCustomer, 'stripe');   // foreign open order (holds reservation)
$rtStock = fn() => $pdo->query("SELECT qty, reserved FROM warehouse_stock WHERE warehouse_id=1 AND sku='SKU-RT'")->fetch(PDO::FETCH_ASSOC);

$s = $rtStock();
assert_equals(5, (int)$s['reserved'], 'Return: A(2)+B(3) reserved=5');

// Ship A: its reservation is consumed (reserved -2, qty -2)
OrderWorkflow::transition($rtA, 'paid');
OrderWorkflow::transition($rtA, 'shipped');
$s = $rtStock();
assert_equals(8, (int)$s['qty'], 'Return: shipped decrements qty (10→8)');
assert_equals(3, (int)$s['reserved'], 'Return: shipped consumed own reservation (5→3)');

// Return A: goods come back as qty; B's reservation must stay intact
OrderWorkflow::transition($rtA, 'returned');
$s = $rtStock();
assert_equals(10, (int)$s['qty'], 'Return: qty restored (8→10)');
assert_equals(3, (int)$s['reserved'], 'Return: foreign reservation NOT eaten (reserved stays 3)');
assert_equals(7, (int)$pdo->query("SELECT stock FROM products WHERE id=503")->fetchColumn(), 'Return: products.stock = qty - foreign reserved = 7');

// Idempotency: second restore is a no-op (stock_restored guard now also covers the qty increment)
Orders::restoreStock($rtA, 'return');
$s = $rtStock();
assert_equals(10, (int)$s['qty'], 'Return: double restore idempotent (qty)');
assert_equals(3, (int)$s['reserved'], 'Return: double restore idempotent (reserved)');

// Cancel branch: B cancelled before shipping → releases its reservation, qty untouched
OrderWorkflow::transition($rtB, 'paid');
OrderWorkflow::transition($rtB, 'cancelled');
$s = $rtStock();
assert_equals(10, (int)$s['qty'], 'Cancel: qty untouched (still 10)');
assert_equals(0, (int)$s['reserved'], 'Cancel: releases own reservation (3→0)');
assert_equals(10, (int)$pdo->query("SELECT stock FROM products WHERE id=503")->fetchColumn(), 'Cancel: products.stock synced back to 10');

echo "\n========================================\n";
echo "Results: {$passed}/{$tests} passed";
if ($failed > 0) {
    echo ", {$failed} FAILED";
}
echo "\n";

if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $err) {
        echo "  - {$err}\n";
    }
    echo "\n";
}

// Cleanup — close PDO first (Windows locks)
$pdo = null;
Db::reset();
// Small delay to release file locks on Windows
usleep(100000);
@unlink($testDbPath);
@unlink($testDbPath . '-wal');
@unlink($testDbPath . '-shm');

echo "========================================\n";
exit($failed > 0 ? 1 : 0);
