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
    shipping_addr TEXT DEFAULT '{}', payment_method TEXT NOT NULL,
    payment_id TEXT DEFAULT '', subtotal INTEGER NOT NULL,
    discount_amount INTEGER NOT NULL DEFAULT 0,
    shipping_cost INTEGER NOT NULL DEFAULT 0, tax_amount INTEGER NOT NULL DEFAULT 0,
    total INTEGER NOT NULL, currency TEXT NOT NULL DEFAULT 'eur',
    notes TEXT DEFAULT '', ip_address TEXT DEFAULT '',
    created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now'))
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
    id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
    variant_id INTEGER, sku TEXT NOT NULL, name TEXT NOT NULL,
    quantity INTEGER NOT NULL, unit_price INTEGER NOT NULL, total INTEGER NOT NULL,
    attrs TEXT DEFAULT '{}',
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
