<?php
/**
 * BareBones Shop — Test Runner
 * Simple test infrastructure without PHPUnit.
 * Usage: php cli/test.php
 */

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Import.php';

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
    id INTEGER PRIMARY KEY, slug TEXT UNIQUE NOT NULL, name TEXT NOT NULL,
    parent_id INTEGER, path TEXT NOT NULL DEFAULT '', depth INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER DEFAULT 0, product_count INTEGER DEFAULT 0,
    translations TEXT DEFAULT '{}', meta TEXT DEFAULT '{}',
    FOREIGN KEY (parent_id) REFERENCES categories(id)
)");
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

$pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY, entity TEXT NOT NULL, entity_id INTEGER NOT NULL,
    action TEXT NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
    actor TEXT NOT NULL, ip_address TEXT,
    created_at TEXT DEFAULT (datetime('now'))
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS processed_events (
    event_id TEXT PRIMARY KEY, created_at TEXT DEFAULT (datetime('now'))
)");

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
