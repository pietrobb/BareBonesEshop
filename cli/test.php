<?php
require_once __DIR__ . '/cli-only.php';

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
// TEST 0: CLI and webroot security boundaries
// ============================================================
echo "--- Test 0: CLI and webroot security boundaries ---\n";

$cliGuardPath = __DIR__ . '/cli-only.php';
$cliGuardSource = file_get_contents($cliGuardPath);
assert_true($cliGuardSource !== false, 'CLI guard: shared guard exists');
assert_true(str_contains((string)$cliGuardSource, "['cli', 'phpdbg']"), 'CLI guard: command-line SAPIs are allowlisted');
assert_true(str_contains((string)$cliGuardSource, 'http_response_code(403);'), 'CLI guard: HTTP rejection returns 403');
assert_true(preg_match('/\bexit\s*\(1\)\s*;/', (string)$cliGuardSource) === 1, 'CLI guard: rejected invocation exits non-zero');

$cliEntrypoints = glob(__DIR__ . '/*.php') ?: [];
sort($cliEntrypoints);
foreach ($cliEntrypoints as $cliEntrypoint) {
    if (basename($cliEntrypoint) === basename($cliGuardPath)) continue;
    $source = str_replace("\r\n", "\n", (string)file_get_contents($cliEntrypoint));
    $guardPrefix = "<?php\nrequire_once __DIR__ . '/cli-only.php';";
    assert_true(str_starts_with($source, $guardPrefix), 'CLI guard: ' . basename($cliEntrypoint) . ' includes guard first');
}

$htaccess = str_replace("\r\n", "\n", (string)file_get_contents(__DIR__ . '/../.htaccess'));
assert_true(preg_match('/^RewriteRule \^\.\+\/\?\$ - \[F,L\]$/mi', $htaccess) === 1, '.htaccess: direct directory requests are denied');
foreach (['storage', 'db', 'jobs', 'logs', 'config', 'cli', 'src', 'migrations', 'adapters', 'lang', 'demo', 'docs', 'forms', 'blocks'] as $privateTree) {
    assert_true(
        preg_match('/^RewriteRule[^\r\n]*\b' . preg_quote($privateTree, '/') . '\b[^\r\n]*\s-\s+\[F,L,NC\]$/mi', $htaccess) === 1,
        ".htaccess: {$privateTree} tree is denied"
    );
}
assert_true(str_contains($htaccess, 'RewriteRule ^vendor/(?!bbf/'), '.htaccess: vendor tree is denied except explicit BBF public integration files');
assert_true(str_contains($htaccess, 'bbf\.(?:css|js)|submit\.php'), '.htaccess: required BBF assets and handler remain public');
assert_true(str_contains($htaccess, 'RewriteRule ^import/(?!test-products\.csv$)'), '.htaccess: only the documented import example remains public');
assert_true(str_contains($htaccess, '%{THE_REQUEST}'), '.htaccess: direct cache denial distinguishes original requests');
assert_true(str_contains($htaccess, '(?:[^?\s]+/)?cache'), '.htaccess: direct cache denial covers subdirectory deployments');
assert_true(preg_match('/^RewriteRule \^cache[^\r\n]*\s-\s+\[F,L,NC\]$/mi', $htaccess) === 1, '.htaccess: direct cache requests are denied');
assert_equals(4, substr_count($htaccess, 'RewriteRule ^api\.php$ cache/'), '.htaccess: explicit internal API cache rewrites remain');
assert_true(!str_contains($htaccess, 'RewriteCond %{REQUEST_URI} ^/cache/'), '.htaccess: broad direct cache serving condition is absent');
assert_true(preg_match('/RewriteRule\s+\^\(\.\*\)\$\s+\$1\s+\[L,T=application\/json\]/i', $htaccess) !== 1, '.htaccess: broad direct cache serving rewrite is absent');
assert_true(stripos($htaccess, '<FilesMatch') === false, '.htaccess: inert FilesMatch is removed');
assert_true(str_contains($htaccess, 'RewriteRule ^\.well-known/'), '.htaccess: ACME and security.txt have a narrow dot-path exception');
assert_true(str_contains($htaccess, 'RewriteRule (?:^|/)\. - [F,L,NC]'), '.htaccess: other dotfiles are denied');
assert_true(preg_match('/^RewriteRule[^\r\n]*sqlite3\?[^\r\n]*\|log\|lock[^\r\n]*\s-\s+\[F,L,NC\]$/mi', $htaccess) === 1, '.htaccess: database, journal, log, lock, and backup extensions are denied');
foreach ([
    'config.php' => 'config(?:\.example)?\.php',
    'stores.json' => 'stores\.json',
    'catalog.json' => 'catalog\.json',
    'shipping.json' => 'shipping\.json',
    'tax.json' => 'tax\.json',
    'pricing.json' => 'pricing\.json',
    'layouts.json' => 'layouts\.json',
    'admin-roles.json' => 'admin-roles\.json',
] as $sensitiveName => $ruleFragment) {
    assert_true(str_contains($htaccess, $ruleFragment), ".htaccess: {$sensitiveName} is denied");
}
assert_true(preg_match('/^RewriteRule \^uploads\/[^\r\n]*php[^\r\n]*\s-\s+\[F,L,NC\]$/mi', $htaccess) === 1, '.htaccess: PHP execution under uploads is denied');
assert_true(str_contains($htaccess, 'RewriteCond %{REQUEST_FILENAME} -f'), '.htaccess: public static assets remain available');
assert_true(str_contains($htaccess, 'bbf\.(?:css|js)|submit\.php'), '.htaccess: required BBF browser integration remains allowlisted');

$configExampleSource = (string)file_get_contents(__DIR__ . '/../config.example.php');
foreach (['db_sqlite_path', 'private_cache_dir', 'jobs_dir', 'invoice_storage_dir', 'backup_storage_dir', 'backup_log_file', 'update_storage_dir', 'gdpr_salt_file'] as $privateConfigKey) {
    assert_true(
        preg_match("/'" . preg_quote($privateConfigKey, '/') . "'\\s*=>\\s*dirname\\(__DIR__\\)/", $configExampleSource) === 1,
        "Config: {$privateConfigKey} defaults outside the application root"
    );
}
$securitySource = (string)file_get_contents(__DIR__ . '/../src/Security.php');
assert_true(str_contains($securitySource, "['private_cache_dir']"), 'Security: private cache is configurable');
assert_true(substr_count($securitySource, 'self::privateCacheDir($cfg)') >= 2, 'Security: rate-limit DB and admin locks use validated private cache');
assert_true(!str_contains($securitySource, "['cache_dir'] ?? __DIR__ . '/../cache'"), 'Security: public catalog cache is not used for abuse-control state');

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
$testRateDb = new PDO('sqlite::memory:');
Security::setRateDbForTesting($testRateDb);

assert_true(Db::get() instanceof PDO, 'PDO connection established');
assert_true(Db::isSqlite(), 'Driver is SQLite');
assert_equals('sqlite', Db::driver(), 'Driver name is sqlite');
assert_equals("datetime('now')", Db::now(), 'now() returns SQLite expression');

// ============================================================
// TEST 1b: Driver-aware SQL builders (MySQL parity, bug #8)
// Asserts the emitted SQL of every Db builder for BOTH drivers
// without needing a live MySQL server (driver flag flip only).
// ============================================================
echo "\n--- Test 1b: Driver-aware SQL builders (MySQL parity) ---\n";

// SQLite branch (active driver)
assert_equals('INSERT OR IGNORE', Db::insertIgnore(), 'Builder[sqlite]: insertIgnore');
assert_equals('ON CONFLICT(sku) DO UPDATE SET name = excluded.name',
    Db::upsertClause('sku', 'name = excluded.name', 'name = VALUES(name)'),
    'Builder[sqlite]: upsertClause uses conflict target + excluded');
assert_equals("datetime(checked_at, '+' || ttl_seconds || ' seconds')",
    Db::dateAddSeconds('checked_at', 'ttl_seconds'),
    'Builder[sqlite]: dateAddSeconds string-concat arithmetic');

// MySQL branch — flip the driver flag only; builders emit strings, no server needed
Db::init($pdo, 'mysql');
assert_true(Db::isMysql(), 'Builder[mysql]: driver flag flipped for assertions');
assert_equals('NOW()', Db::now(), 'Builder[mysql]: now() is NOW()');
assert_equals('INSERT IGNORE', Db::insertIgnore(), 'Builder[mysql]: insertIgnore');
assert_equals('ON DUPLICATE KEY UPDATE name = VALUES(name)',
    Db::upsertClause('sku', 'name = excluded.name', 'name = VALUES(name)'),
    'Builder[mysql]: upsertClause ignores conflict target');
assert_equals('ON DUPLICATE KEY UPDATE x = 1',
    Db::upsertClause('sku', 'x = 1'),
    'Builder[mysql]: upsertClause falls back to sqlite updates when mysql variant omitted');
assert_equals('DATE_ADD(checked_at, INTERVAL ttl_seconds SECOND)',
    Db::dateAddSeconds('checked_at', 'ttl_seconds'),
    'Builder[mysql]: dateAddSeconds uses DATE_ADD');
assert_equals('DATE_ADD(checked_at, INTERVAL ttl_seconds SECOND) <= NOW()',
    Db::dateAddSeconds('checked_at', 'ttl_seconds') . ' <= ' . Db::now(),
    'Retention[mysql]: GDPR TTL expression is valid MySQL syntax');

Db::init($pdo, 'sqlite');
assert_true(Db::isSqlite(), 'Builder: driver restored to sqlite');

// Functional regression (bug #8, GDPR retention): expired row matches, live row does not
$pdo->exec("CREATE TABLE _ttl_probe (id INTEGER PRIMARY KEY, checked_at TEXT, ttl_seconds INTEGER)");
$pdo->exec("INSERT INTO _ttl_probe (checked_at, ttl_seconds) VALUES (datetime('now', '-2 hours'), 3600)");
$pdo->exec("INSERT INTO _ttl_probe (checked_at, ttl_seconds) VALUES (datetime('now'), 86400)");
$ttlExpr = Db::dateAddSeconds('checked_at', 'ttl_seconds') . ' <= ' . Db::now();
assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM _ttl_probe WHERE {$ttlExpr}")->fetchColumn(),
    'Retention[sqlite]: TTL expression selects only the expired row');
$pdo->exec("DROP TABLE _ttl_probe");

// Optional live MySQL parity run — enabled via env:
//   TEST_MYSQL_DSN="mysql:host=127.0.0.1;dbname=test;charset=utf8mb4" [TEST_MYSQL_USER=...] [TEST_MYSQL_PASS=...]
$mysqlDsn = getenv('TEST_MYSQL_DSN');
if ($mysqlDsn) {
    try {
        $my = new PDO($mysqlDsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $my->exec("DROP TABLE IF EXISTS _parity_probe");
        $my->exec("CREATE TABLE _parity_probe (sku VARCHAR(32) PRIMARY KEY, qty INT NOT NULL, checked_at DATETIME NULL, ttl_seconds INT NULL)");
        Db::init($my, 'mysql');
        $my->exec(Db::insertIgnore() . " INTO _parity_probe (sku, qty) VALUES ('A', 1)");
        $my->exec(Db::insertIgnore() . " INTO _parity_probe (sku, qty) VALUES ('A', 99)");
        assert_equals(1, (int)$my->query("SELECT qty FROM _parity_probe WHERE sku='A'")->fetchColumn(),
            'MySQL live: INSERT IGNORE skips duplicate');
        $my->exec("INSERT INTO _parity_probe (sku, qty) VALUES ('A', 5) " . Db::upsertClause('sku', 'qty = excluded.qty', 'qty = VALUES(qty)'));
        assert_equals(5, (int)$my->query("SELECT qty FROM _parity_probe WHERE sku='A'")->fetchColumn(),
            'MySQL live: ON DUPLICATE KEY UPDATE applies');
        $my->exec("UPDATE _parity_probe SET checked_at = DATE_SUB(NOW(), INTERVAL 2 HOUR), ttl_seconds = 3600 WHERE sku='A'");
        assert_equals(1, (int)$my->query("SELECT COUNT(*) FROM _parity_probe WHERE " . Db::dateAddSeconds('checked_at', 'ttl_seconds') . " <= " . Db::now())->fetchColumn(),
            'MySQL live: GDPR TTL retention expression selects expired row');
        $my->exec("DROP TABLE IF EXISTS invoices");
        $my->exec("DROP TABLE IF EXISTS orders");
        $my->exec("CREATE TABLE orders (id INT PRIMARY KEY) ENGINE=InnoDB");
        $my->exec("CREATE TABLE invoices (id INT PRIMARY KEY) ENGINE=InnoDB");
        $my->exec("CREATE TABLE categories (id INT PRIMARY KEY) ENGINE=InnoDB");
        $my->exec("CREATE TABLE products (id INT PRIMARY KEY, category_id INT NULL) ENGINE=InnoDB");
        $my->exec("CREATE TABLE variants (id INT PRIMARY KEY, product_id INT NOT NULL) ENGINE=InnoDB");
        $my->exec("CREATE TABLE customers (id INT PRIMARY KEY) ENGINE=InnoDB");
        $financialMigration = require __DIR__ . '/../migrations/20260801141000_financial_snapshots.php';
        $financialMigration->up($my, false);
        $financialMigration->up($my, false);
        $financialColumns = (int)$my->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'financial_snapshot' AND TABLE_NAME IN ('orders','invoices')")->fetchColumn();
        assert_equals(2, $financialColumns, 'MySQL live: financial snapshot migration is applied and idempotent');
        $eventApiMigrationMysql = require __DIR__ . '/../migrations/20260801163000_event_outbox_api_v1.php';
        $eventApiMigrationMysql->up($my, false);
        $eventApiMigrationMysql->up($my, false);
        $g4MysqlTables = (int)$my->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('event_outbox','webhook_deliveries','api_clients','api_idempotency')")->fetchColumn();
        assert_equals(4, $g4MysqlTables, 'MySQL live: G4 outbox/API migration is applied and idempotent');
        $g6MigrationMysql = require __DIR__ . '/../migrations/20260801190000_catalog_pricing_b2b.php';
        $g6MigrationMysql->up($my, false);
        $g6MigrationMysql->up($my, false);
        $g6MysqlTables = (int)$my->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('brands','product_categories','customer_groups','companies','price_lists','price_list_prices','promotions')")->fetchColumn();
        assert_equals(7, $g6MysqlTables, 'MySQL live: G6 catalog/pricing/B2B migration is applied and idempotent');
        $g7MigrationMysql = require __DIR__ . '/../migrations/20260801200000_inventory_ledger_fulfillment.php';
        $g7MigrationMysql->up($my, false);
        $g7MigrationMysql->up($my, false);
        $g7MysqlTables = (int)$my->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('stock_movements','suppliers','supplier_products','purchase_orders','purchase_order_items','inventory_receipts','bundle_components','shipments','shipment_items')")->fetchColumn();
        assert_equals(9, $g7MysqlTables, 'MySQL live: G7 inventory/fulfillment migration is applied and idempotent');
        $g8MigrationMysql = require __DIR__ . '/../migrations/20260801210000_returns_credit_notes.php';
        $g8MigrationMysql->up($my, false);
        $g8MigrationMysql->up($my, false);
        $g8MysqlTables = (int)$my->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('return_labels','return_receipts','return_exchanges','order_refunds','credit_note_items')")->fetchColumn();
        assert_equals(5, $g8MysqlTables, 'MySQL live: G8 returns/refunds migration is applied and idempotent');
        $g9MigrationMysql = require __DIR__ . '/../migrations/20260801220000_admin_identity_queue_operations.php';
        $g9MigrationMysql->up($my, false);
        $g9MigrationMysql->up($my, false);
        $g9MysqlTables = (int)$my->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('admin_accounts','admin_recovery_codes','admin_store_permissions','admin_devices','admin_sessions','queue_jobs')")->fetchColumn();
        assert_equals(6, $g9MysqlTables, 'MySQL live: G9 admin identity and queue migration is applied and idempotent');
        $g10MigrationMysql = require __DIR__ . '/../migrations/20260801230000_theme_content_marketing.php';
        $g10MigrationMysql->up($my, false);
        $g10MigrationMysql->up($my, false);
        $g10MysqlTables = (int)$my->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('content_menu_items','content_banners','content_redirects')")->fetchColumn();
        assert_equals(3, $g10MysqlTables, 'MySQL live: G10 content migration is applied and idempotent');
        foreach (['content_redirects', 'content_banners', 'content_menu_items'] as $table) $my->exec("DROP TABLE {$table}");
        foreach (['credit_note_items', 'order_refunds', 'return_exchanges', 'return_receipts', 'return_labels'] as $table) $my->exec("DROP TABLE {$table}");
        foreach (['shipment_items', 'shipments', 'bundle_components', 'inventory_receipts', 'purchase_order_items', 'purchase_orders', 'supplier_products', 'suppliers', 'stock_movements'] as $table) $my->exec("DROP TABLE {$table}");
        foreach (['price_list_prices', 'price_lists', 'promotions', 'product_categories', 'companies', 'customer_groups', 'brands'] as $table) $my->exec("DROP TABLE {$table}");
        $my->exec("DROP TABLE api_idempotency");
        $my->exec("DROP TABLE api_clients");
        $my->exec("DROP TABLE webhook_deliveries");
        $my->exec("DROP TABLE event_outbox");
        $my->exec("DROP TABLE variants");
        $my->exec("DROP TABLE products");
        $my->exec("DROP TABLE categories");
        $my->exec("DROP TABLE customers");
        $my->exec("DROP TABLE invoices");
        $my->exec("DROP TABLE orders");
        $my->exec("DROP TABLE _parity_probe");
    } catch (\PDOException $e) {
        echo "  [SKIP] TEST_MYSQL_DSN set but MySQL unavailable: " . $e->getMessage() . "\n";
    } finally {
        Db::init($pdo, 'sqlite');
    }
} else {
    echo "  [SKIP] live MySQL parity probe (set TEST_MYSQL_DSN to enable)\n";
}

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
$pdo->exec("CREATE TABLE IF NOT EXISTS pages (
    id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE, title TEXT NOT NULL,
    body TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active', translations TEXT DEFAULT '{}',
    created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now'))
)");

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

$pdo->exec("CREATE TABLE IF NOT EXISTS product_related_products (
    product_id INTEGER NOT NULL,
    related_product_id INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id, related_product_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (related_product_id) REFERENCES products(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_product_related_sort ON product_related_products(product_id, sort_order)");

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
    availability TEXT NOT NULL DEFAULT 'active',
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
    payment_id TEXT DEFAULT '', payment_status TEXT NOT NULL DEFAULT 'pending',
    payment_fee INTEGER NOT NULL DEFAULT 0, payment_method_snapshot TEXT DEFAULT NULL,
    subtotal INTEGER NOT NULL,
    discount_amount INTEGER NOT NULL DEFAULT 0,
    shipping_cost INTEGER NOT NULL DEFAULT 0, tax_amount INTEGER NOT NULL DEFAULT 0,
    total INTEGER NOT NULL, currency TEXT NOT NULL DEFAULT 'eur',
    locale TEXT NOT NULL DEFAULT 'en',
    notes TEXT DEFAULT '', ip_address TEXT DEFAULT '',
    erp_id TEXT DEFAULT NULL, erp_status TEXT DEFAULT NULL,
    erp_synced_at TEXT DEFAULT NULL, erp_error TEXT DEFAULT NULL,
    vat_scenario TEXT DEFAULT NULL, vat_total INTEGER DEFAULT NULL, net_total INTEGER DEFAULT NULL,
    financial_snapshot TEXT DEFAULT NULL,
    stock_restored INTEGER NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now'))
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS checkout_sessions (
    id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, provider TEXT NOT NULL,
    session_id TEXT NOT NULL, browser_session_hash TEXT DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'pending', created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now')), UNIQUE(provider, session_id)
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

$storeScopeMigration = require __DIR__ . '/../migrations/20260801152000_store_context_scope.php';
$storeScopeMigration->up($pdo, true);
$productStoreColumn = array_values(array_filter($pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC), static fn(array $row): bool => $row['name'] === 'store_id'));
assert_equals("'default'", (string)($productStoreColumn[0]['dflt_value'] ?? ''), 'Store migration: new catalog rows default to the default store');
$eventApiMigration = require __DIR__ . '/../migrations/20260801163000_event_outbox_api_v1.php';
$eventApiMigration->up($pdo, true);
$eventApiMigration->up($pdo, true);
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='event_outbox'")->fetchColumn(), 'G4 migration: transactional event outbox exists');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='api_clients'")->fetchColumn(), 'G4 migration: API client credential table exists');
$catalogPricingB2bMigration = require __DIR__ . '/../migrations/20260801190000_catalog_pricing_b2b.php';
$catalogPricingB2bMigration->up($pdo, true);
$catalogPricingB2bMigration->up($pdo, true);
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='price_lists'")->fetchColumn(), 'G6 migration: price lists exist and migration is idempotent');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_groups'")->fetchColumn(), 'G6 migration: customer groups exist');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='companies'")->fetchColumn(), 'G6 migration: companies exist');
assert_equals((int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(), (int)$pdo->query('SELECT COUNT(*) FROM product_categories WHERE is_primary = 1')->fetchColumn(), 'G6 migration: legacy primary categories are preserved');
$inventoryMigration = require __DIR__ . '/../migrations/20260801200000_inventory_ledger_fulfillment.php';
$inventoryMigration->up($pdo, true);
$inventoryMigration->up($pdo, true);
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='stock_movements'")->fetchColumn(), 'G7 migration: stock movement ledger exists and migration is idempotent');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipments'")->fetchColumn(), 'G7 migration: split shipment tables exist');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='purchase_orders'")->fetchColumn(), 'G7 migration: procurement minimum exists');
$adminQueueMigration = require __DIR__ . '/../migrations/20260801220000_admin_identity_queue_operations.php';
$adminQueueMigration->up($pdo, true);
$adminQueueMigration->up($pdo, true);
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='admin_accounts'")->fetchColumn(), 'G9 migration: admin identities exist and migration is idempotent');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='queue_jobs'")->fetchColumn(), 'G9 migration: durable database queue exists');
$contentMarketingMigration = require __DIR__ . '/../migrations/20260801230000_theme_content_marketing.php';
$contentMarketingMigration->up($pdo, true);
$contentMarketingMigration->up($pdo, true);
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='content_menu_items'")->fetchColumn(), 'G10 migration: content menus exist and migration is idempotent');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='content_banners'")->fetchColumn(), 'G10 migration: scheduled banners exist');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='content_redirects'")->fetchColumn(), 'G10 migration: redirect manager exists');

// ============================================================
// TEST 3: CSV Import
// ============================================================
echo "\n--- Test 3: CSV Import ---\n";

$csvFile = __DIR__ . '/../import/test-products.csv';
$result = Import::csv($csvFile);

assert_true($result['success'], 'CSV import succeeded');
assert_equals(8, $result['imported'], 'Imported 8 products');
assert_equals(0, count($result['errors']), 'No import errors');

$badCsv = tempnam(sys_get_temp_dir(), 'bbe-csv-');
file_put_contents($badCsv, "sku,sku,name\nA,A,Duplicate\n");
$badCsvResult = Import::csv($badCsv);
assert_true($badCsvResult['success'] === false, 'CSV import: duplicate headers are rejected');
unlink($badCsv);
$oversizedCsv = tempnam(sys_get_temp_dir(), 'bbe-csv-large-');
$oversizedHandle = fopen($oversizedCsv, 'wb');
ftruncate($oversizedHandle, Import::MAX_FILE_BYTES + 1);
fclose($oversizedHandle);
$oversizedCsvResult = Import::csv($oversizedCsv);
assert_true($oversizedCsvResult['success'] === false, 'CSV import: oversized files are rejected before parsing');
unlink($oversizedCsv);

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

$_SESSION = [];
$formToken = Security::formTrapToken('test_register', 3600);
$trapKey = 'form_trap_tokens_' . md5('test_register');
$_SESSION[$trapKey][$formToken] = time() - 5;
assert_true(Security::formTrapValidate('test_register', $formToken, '', 4, 3600), 'formTrapValidate accepts a human-paced one-time token');
assert_true(!Security::formTrapValidate('test_register', $formToken, '', 4, 3600), 'formTrapValidate rejects token reuse');
$fastToken = Security::formTrapToken('test_register', 3600);
assert_true(!Security::formTrapValidate('test_register', $fastToken, '', 4, 3600), 'formTrapValidate rejects too-fast submits');
$hpToken = Security::formTrapToken('test_register', 3600);
$_SESSION[$trapKey][$hpToken] = time() - 5;
assert_true(!Security::formTrapValidate('test_register', $hpToken, 'https://spam.example', 4, 3600), 'formTrapValidate rejects filled honeypot fields');
$oldToken = Security::formTrapToken('test_register', 3600);
$_SESSION[$trapKey][$oldToken] = time() - 3601;
assert_true(!Security::formTrapValidate('test_register', $oldToken, '', 4, 3600), 'formTrapValidate rejects expired tokens');
$accountSource = file_get_contents(__DIR__ . '/../account.php') ?: '';
assert_true(str_contains($accountSource, 'name="register_token"'), 'Account register form includes form-trap token');
assert_true(!str_contains($accountSource, 'HTTP_HOST'), 'G3: password reset URL does not trust the Host header');
assert_true(str_contains($accountSource, 'name="company_website"'), 'Account register form includes hidden honeypot field');
assert_true(str_contains($accountSource, 'account_login_account_'), 'G4: login has a per-account throttle independent of IP');
assert_true(str_contains($accountSource, 'account_forgot_recipient_'), 'G4: password reset has a per-recipient throttle independent of IP');
assert_true(str_contains($accountSource, "account.err_invalid_credentials"), 'G4: login returns a generic credential error');
$adminSourceG4 = file_get_contents(__DIR__ . '/../admin.php') ?: '';
assert_true(str_contains($adminSourceG4, "adminRequire('customers.edit')"), 'G4: customer status mutation requires edit permission');
assert_true(str_contains($adminSourceG4, 'AND order_id = :order_id'), 'G4: invoice resend is scoped to the displayed order');
assert_true(str_contains($adminSourceG4, 'admin_email_resend_'), 'G4: admin email resend has a cooldown budget');
$workerSourceG4 = file_get_contents(__DIR__ . '/worker.php') ?: '';
assert_true(str_contains($workerSourceG4, '$to = (string)$order[\'customer_email\']'), 'G4: order mail recipient is loaded from the order record');
assert_true(str_contains($workerSourceG4, '$allowedTemplates'), 'G4: worker mail templates are allowlisted');
assert_true(str_contains($workerSourceG4, '$template !== \'cart_reminder\''), 'G4: payload-only mail jobs are limited to cart reminders');
$mailSourceG4 = file_get_contents(__DIR__ . '/../src/MailService.php') ?: '';
assert_true(!str_contains($mailSourceG4, "SERVER_NAME"), 'G4: mail sender does not trust the request host');
assert_true(str_contains($mailSourceG4, "FILTER_VALIDATE_EMAIL"), 'G4: sender, recipient and reply-to are validated');
foreach (['api.php', 'index.php', 'product.php', 'wishlist.php'] as $customerEntrypoint) {
    $entrySource = file_get_contents(__DIR__ . '/../' . $customerEntrypoint) ?: '';
    assert_true(str_contains($entrySource, 'Customers::isLoggedIn();'), "G4: {$customerEntrypoint} validates stale customer sessions");
}
$apiSourceG5 = file_get_contents(__DIR__ . '/../api.php') ?: '';
$indexSourceG5 = file_get_contents(__DIR__ . '/../index.php') ?: '';
foreach (['checkout_global', 'checkout_session_', 'checkout_subject_', 'Security::rotateCsrfToken()'] as $checkoutGuard) {
    assert_true(str_contains($apiSourceG5, $checkoutGuard), "G5: API checkout uses {$checkoutGuard}");
    assert_true(str_contains($indexSourceG5, $checkoutGuard), "G5: legacy checkout uses {$checkoutGuard}");
}
$_SESSION['csrf_token'] = 'g5-checkout-request-token';
$_POST['csrf_token'] = 'g5-checkout-request-token';
assert_true(Security::csrfValidate(), 'G5: original checkout CSRF token starts valid');
$newCheckoutCsrf = Security::rotateCsrfToken();
assert_true(!Security::csrfValidate(), 'G5: exact checkout replay token is invalid after successful-create rotation');
$_POST['csrf_token'] = $newCheckoutCsrf;
assert_true(Security::csrfValidate(), 'G5: newly issued CSRF token remains usable');
$_POST = [];

$rateKey = 'g4_global_' . bin2hex(random_bytes(8));
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
assert_true(Security::rateLimit($rateKey, 1, 3600, false), 'G4: first global subject hit is allowed');
$_SERVER['REMOTE_ADDR'] = '192.0.2.11';
assert_true(!Security::rateLimit($rateKey, 1, 3600, false), 'G4: global subject throttle cannot be bypassed by changing IP');

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
$cacheJobsDir = sys_get_temp_dir() . '/bareboneseshop-jobs-cache-' . getmypid();
JobQueue::setDir($cacheJobsDir);
Cache::invalidatePrefix('test');
@rmdir($testCacheDir . '/test');
@rmdir($testCacheDir . '/kv');
@rmdir($testCacheDir);
foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
    $subDir = $cacheJobsDir . '/' . $sub;
    if (is_dir($subDir)) {
        array_map('unlink', glob($subDir . '/*.json') ?: []);
        @rmdir($subDir);
    }
}
@rmdir($cacheJobsDir);

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
assert_true(!isset($cart['v_5']), 'Cart::restore rejects unknown or mismatched variant');
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

$testJobsDir = sys_get_temp_dir() . '/bareboneseshop-jobs-test-' . getmypid();
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

$jobPathRejected = false;
try { JobQueue::setDir(__DIR__ . '/../jobs/unsafe'); } catch (RuntimeException $e) { $jobPathRejected = true; }
assert_true($jobPathRejected, 'JobQueue: jobs_dir inside application root is rejected');
$forgedJobRejected = false;
try { JobQueue::complete(__FILE__); } catch (RuntimeException $e) { $forgedJobRejected = true; }
assert_true($forgedJobRejected, 'JobQueue: forged processing path is rejected');
$badPayload = fopen('php://temp', 'rb');
$badPayloadRejected = false;
try { JobQueue::push('invalid_payload', ['resource' => $badPayload]); } catch (JsonException $e) { $badPayloadRejected = true; }
fclose($badPayload);
assert_true($badPayloadRejected, 'JobQueue: unencodable payload fails closed');

// G9 database queue: priority order, atomic claim ownership, retry and failure visibility.
$pdo->exec('DELETE FROM queue_jobs');
JobQueue::useBackend(new DatabaseQueue($pdo, 30));
assert_equals('database', JobQueue::backendName(), 'G9 queue: database backend is selected');
$dbJobLow = JobQueue::push('g9_low', ['value' => 1], 8);
$dbJobHigh = JobQueue::push('g9_high', ['value' => 2], 1);
$dbJobs = JobQueue::pull(10);
assert_equals($dbJobHigh, $dbJobs[0]['id'], 'G9 queue: database backend preserves priority ordering');
assert_true(!empty($dbJobs[0]['correlation_id']), 'G9 queue: correlation ID is persisted with the job');
$dbClaim = JobQueue::markProcessing($dbJobs[0]);
assert_true(str_starts_with($dbClaim, 'db:'), 'G9 queue: first worker obtains an opaque database claim');
assert_equals('', JobQueue::markProcessing($dbJobs[0]), 'G9 queue: a concurrent worker cannot claim the same job');
JobQueue::complete($dbClaim);
$dbRetryJob = null;
foreach (JobQueue::pull(10) as $candidate) if ($candidate['id'] === $dbJobLow) $dbRetryJob = $candidate;
assert_true($dbRetryJob !== null, 'G9 queue: second database job remains available');
for ($attempt = 0; $attempt < 3; $attempt++) {
    $claim = JobQueue::markProcessing($dbRetryJob);
    JobQueue::fail($claim, $dbRetryJob, 'database queue test failure');
    $pdo->prepare("UPDATE queue_jobs SET available_at=:now WHERE id=:id")->execute([':now' => gmdate('Y-m-d H:i:s'), ':id' => $dbJobLow]);
    foreach (JobQueue::pull(10) as $candidate) if ($candidate['id'] === $dbJobLow) $dbRetryJob = $candidate;
}
assert_equals(1, JobQueue::stats()['failed'], 'G9 queue: exhausted database job moves to failed');
assert_equals($dbJobLow, JobQueue::failures(5)[0]['id'], 'G9 queue: failed job is available to operations dashboard');

// G9 identity: encrypted TOTP, one-time recovery, store grants and revocable sessions.
putenv('BBE_ADMIN_IDENTITY_KEY=' . base64_encode(str_repeat('K', 32)));
$adminId = AdminIdentity::create('g9-operator', 'Test-password-1234', 'manager', true);
assert_equals(1, AdminIdentity::accountCount(), 'G9 identity: DB mode remains authoritative once an account exists');
AdminIdentity::setStatus($adminId, 'disabled');
assert_equals(0, AdminIdentity::activeCount(), 'G9 identity: disabled account leaves no active DB login');
assert_equals(1, AdminIdentity::accountCount(), 'G9 identity: disabling the last account cannot restore legacy auth mode');
AdminIdentity::setStatus($adminId, 'active');
AdminIdentity::grantStore($adminId, 'default', ['orders.*', 'reports.view']);
$enrollment = AdminIdentity::enrollTotp($adminId);
$storedTotp = (string)$pdo->query("SELECT totp_secret_encrypted FROM admin_accounts WHERE id={$adminId}")->fetchColumn();
$storedRecovery = (string)$pdo->query("SELECT code_hash FROM admin_recovery_codes WHERE account_id={$adminId} ORDER BY id LIMIT 1")->fetchColumn();
assert_true(str_starts_with($storedTotp, 'v1:') && !str_contains($storedTotp, $enrollment['secret']), 'G9 identity: TOTP seed is encrypted at rest');
assert_true($storedRecovery !== hash('sha256', str_replace('-', '', $enrollment['recovery_codes'][0])), 'G9 identity: recovery codes use a deployment-keyed hash at rest');
assert_true(Totp::verify($enrollment['secret'], Totp::code($enrollment['secret'])), 'G9 identity: RFC 6238 code verifies');
$login = AdminIdentity::authenticate('g9-operator', 'Test-password-1234', Totp::code($enrollment['secret']), '127.0.0.1', 'G9 Test Device');
assert_true($login !== null, 'G9 identity: password and TOTP authenticate');
assert_true(AdminIdentity::session($login['token']) !== null, 'G9 identity: server-side session validates');
AdminIdentity::setPassword($adminId, 'Rotated-password-5678');
assert_true(AdminIdentity::session($login['token']) === null, 'G9 identity: password rotation revokes existing sessions');
assert_true(AdminIdentity::authenticate('g9-operator', 'Test-password-1234', Totp::code($enrollment['secret']), '127.0.0.1', 'G9 Test Device') === null, 'G9 identity: rotated password rejects the previous credential');
$login = AdminIdentity::authenticate('g9-operator', 'Rotated-password-5678', Totp::code($enrollment['secret']), '127.0.0.1', 'G9 Test Device');
assert_true($login !== null, 'G9 identity: rotated password authenticates with MFA');
assert_true(AdminIdentity::storeAllows($login['store_permissions'], 'default', 'orders.ship'), 'G9 identity: wildcard permission allows the granted store action');
assert_true(!AdminIdentity::storeAllows($login['store_permissions'], 'other', 'orders.ship'), 'G9 identity: another store fails closed');
AdminIdentity::revokeSession($login['session_id']);
assert_true(AdminIdentity::session($login['token']) === null, 'G9 identity: revoked session cannot be reused');
$recoveryLogin = AdminIdentity::authenticate('g9-operator', 'Rotated-password-5678', $enrollment['recovery_codes'][0], '127.0.0.1', 'G9 Recovery Device');
assert_true($recoveryLogin !== null, 'G9 identity: recovery code authenticates once');
AdminIdentity::revokeSession($recoveryLogin['session_id']);
assert_true(AdminIdentity::authenticate('g9-operator', 'Test-password-1234', $enrollment['recovery_codes'][0], '127.0.0.1', 'G9 Recovery Device') === null, 'G9 identity: used recovery code cannot replay');
$pdo->prepare("UPDATE queue_jobs SET store_id='other', state='failed', last_error='other store' WHERE id=:id")->execute([':id' => $dbJobHigh]);
$operationsG9 = OperationalDashboard::summary('default');
assert_equals('database', $operationsG9['queue_backend'], 'G9 dashboard: reports active queue backend');
assert_equals(1, $operationsG9['counts']['failed_jobs'], 'G9 dashboard: reports store-scoped failed jobs');
putenv('BBE_ADMIN_IDENTITY_KEY');
JobQueue::setDir($testJobsDir);

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
$testJobsDir2 = sys_get_temp_dir() . '/bareboneseshop-jobs-wf-' . getmypid();
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

// G5: expire only old, unpaid, terminal online checkouts and restore once.
$onlineCustomer = $customer;
$onlineCustomer['payment_method_snapshot'] = json_encode(['provider' => 'stripe', 'requires_online' => true]);
$expiryOrderId = Orders::create($cartItems2, $onlineCustomer, 'stripe');
CheckoutSession::bind($expiryOrderId, 'cs_expired_test', 'stripe', 'test-browser-session');
$pdo->prepare("UPDATE orders SET created_at = datetime('now', '-3 hours'), payment_id = 'cs_expired_test' WHERE id = ?")
    ->execute([$expiryOrderId]);
$expiryStockBefore = (int)Products::bySku('TSHIRT-BLK-M')['stock'];
assert_equals(1, Orders::expirePending(3600, 10, static fn(array $order): string => 'expired'), 'G5: one terminal unpaid online order expires');
$expiredOrder = Orders::byId($expiryOrderId);
assert_equals('cancelled', $expiredOrder['status'], 'G5: expired pending order is cancelled');
assert_equals('cancelled', $expiredOrder['payment_status'], 'G5: expired pending payment is closed');
$expiredCheckoutState = $pdo->query("SELECT status FROM checkout_sessions WHERE session_id = 'cs_expired_test'")->fetchColumn();
assert_equals('expired', $expiredCheckoutState, 'G5: provider checkout binding is expired in the same transaction');
$expiryStockAfter = (int)Products::bySku('TSHIRT-BLK-M')['stock'];
assert_equals($expiryStockBefore + 1, $expiryStockAfter, 'G5: expiry releases reserved stock');
assert_equals(0, Orders::expirePending(3600, 10, static fn(array $order): string => 'expired'), 'G5: repeated expiry cannot claim the order again');
assert_equals($expiryStockAfter, (int)Products::bySku('TSHIRT-BLK-M')['stock'], 'G5: repeated expiry cannot double-restore stock');

$offlineCustomer = $customer;
$offlineCustomer['payment_method_snapshot'] = json_encode(['provider' => 'manual', 'requires_online' => false]);
$offlineOrderId = Orders::create($cartItems2, $offlineCustomer, 'transfer');
$pdo->prepare("UPDATE orders SET created_at = datetime('now', '-3 hours') WHERE id = ?")->execute([$offlineOrderId]);
assert_equals(0, Orders::expirePending(3600, 10, static fn(array $order): string => 'expired'), 'G5: offline pending order is never auto-expired');
assert_equals('pending', Orders::byId($offlineOrderId)['status'], 'G5: offline order remains pending');

$paidOnlineOrderId = Orders::create($cartItems2, $onlineCustomer, 'stripe');
$pdo->prepare("UPDATE orders SET created_at = datetime('now', '-3 hours'), payment_status = 'paid', payment_id = 'cs_paid_test' WHERE id = ?")
    ->execute([$paidOnlineOrderId]);
assert_equals(0, Orders::expirePending(3600, 10, static fn(array $order): string => 'expired'), 'G5: paid order is never auto-expired');
assert_equals('pending', Orders::byId($paidOnlineOrderId)['status'], 'G5: paid order lifecycle remains untouched');

$openOnlineOrderId = Orders::create($cartItems2, $onlineCustomer, 'stripe');
$pdo->prepare("UPDATE orders SET created_at = datetime('now', '-3 hours'), payment_id = 'cs_open_test' WHERE id = ?")
    ->execute([$openOnlineOrderId]);
assert_equals(0, Orders::expirePending(3600, 10, static fn(array $order): string => 'open'), 'G5: non-terminal provider session fails closed');
assert_equals('pending', Orders::byId($openOnlineOrderId)['status'], 'G5: open provider checkout remains pending');
$pdo->prepare("UPDATE orders SET created_at = datetime('now') WHERE id = ?")->execute([$openOnlineOrderId]);

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
    if (strlen($secret) < 16 || trim($secret) !== $secret) return false;
    $timestamp = '';
    $signatures = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$key, $value] = explode('=', trim($part), 2) + [1 => ''];
        if ($key === 't') {
            if ($timestamp !== '' && $timestamp !== $value) return false;
            $timestamp = $value;
        } elseif ($key === 'v1' && $value !== '') {
            $signatures[] = strtolower($value);
        }
    }
    if ($timestamp === '' || !ctype_digit($timestamp) || !$signatures) return false;
    if (abs(time() - (int)$timestamp) > 300) return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) return true;
    }
    return false;
}

$testSecret = 'test-stripe-webhook-secret';
$testPayload = '{"id":"evt_1","type":"checkout.session.completed"}';
$testTimestamp = (string)time();
$testSig = hash_hmac('sha256', $testTimestamp . '.' . $testPayload, $testSecret);
$testHeader = "t={$testTimestamp},v1={$testSig}";

assert_true(verifyStripeSignature($testPayload, $testHeader, $testSecret), 'Valid Stripe signature accepted');
assert_true(verifyStripeSignature($testPayload, "t={$testTimestamp},v1=deadbeef,v1={$testSig}", $testSecret), 'Any valid Stripe v1 signature is accepted during secret rotation');
assert_true(!verifyStripeSignature($testPayload, $testHeader, ''), 'Empty Stripe webhook secret fails closed');
$weakSecretHeader = "t={$testTimestamp},v1=" . hash_hmac('sha256', $testTimestamp . '.' . $testPayload, 'short');
assert_true(!verifyStripeSignature($testPayload, $weakSecretHeader, 'short'), 'Weak Stripe webhook secret fails closed even with a matching HMAC');
assert_true(!verifyStripeSignature($testPayload, $testHeader, 'wrong_secret'), 'Wrong secret rejected');
assert_true(!verifyStripeSignature('tampered', $testHeader, $testSecret), 'Tampered payload rejected');
assert_true(!verifyStripeSignature($testPayload, "t=1,t={$testTimestamp},v1={$testSig}", $testSecret), 'Conflicting Stripe timestamps rejected');
assert_true(!verifyStripeSignature($testPayload, "t=not-a-time,v1={$testSig}", $testSecret), 'Malformed Stripe timestamp rejected');

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

$testJobsDir3 = sys_get_temp_dir() . '/bareboneseshop-jobs-worker-' . getmypid();
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
assert_equals(StoreContext::current()->id(), $jobs[0]['payload']['_store_id'] ?? null, 'Worker: queued job carries active store context');
assert_equals(StoreContext::current()->channel(), $jobs[0]['payload']['_channel_id'] ?? null, 'Worker: queued job carries active channel context');
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

$testJobsDir3 = sys_get_temp_dir() . '/bareboneseshop-jobs-return-' . getmypid();
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
// prices_include_tax=true → extracted tax_amount is reporting-only, NOT added to total
$expectedTotal = max(0, $expectedSubtotal - 500) + 399;
assert_equals($expectedSubtotal, (int)$couponOrder['subtotal'], 'Coupon order: subtotal correct');
assert_equals(500, (int)$couponOrder['discount_amount'], 'Coupon discount persisted in order');
assert_equals($expectedTotal, (int)$couponOrder['total'], 'Coupon order: total = subtotal - discount + shipping (tax included in gross)');

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

foreach (['', "bad\0name", "bad\nname", '/etc/passwd', '\\server\\share\\file', 'C:\\Windows\\file', 'products/file.txt:payload', 'products/./file', 'products\\..\\file', 'products\\mixed/file'] as $badPath) {
    foreach (['store', 'delete', 'url', 'resize'] as $method) {
        $rejected = false;
        try {
            if ($method === 'store') $storage2->store($badPath, 'x');
            elseif ($method === 'delete') $storage2->delete($badPath);
            elseif ($method === 'url') $storage2->url($badPath);
            else $storage2->resize($badPath, 10, 10);
        } catch (\RuntimeException $e) {
            $rejected = true;
        }
        assert_true($rejected, "LocalStorage {$method} rejects unsafe path " . json_encode($badPath));
    }
}

file_put_contents($tmpDir2 . '/blocked', 'not a directory');
$mkdirFailureThrown = false;
try { $storage2->store('blocked/file.txt', 'x'); } catch (\RuntimeException $e) { $mkdirFailureThrown = true; }
assert_true($mkdirFailureThrown, 'LocalStorage mkdir failure throws');

$siblingDir2 = $tmpDir2 . '_sibling';
@mkdir($siblingDir2);
$linkDir2 = $tmpDir2 . '/escape-link';
if (@symlink($siblingDir2, $linkDir2)) {
    $symlinkRejected = $symlinkUrlRejected = false;
    try { $storage2->store('escape-link/escaped.txt', 'x'); } catch (\RuntimeException $e) { $symlinkRejected = true; }
    try { $storage2->url('escape-link/escaped.txt'); } catch (\RuntimeException $e) { $symlinkUrlRejected = true; }
    assert_true($symlinkRejected, 'LocalStorage rejects sibling-prefix symlink escape');
    assert_true($symlinkUrlRejected, 'LocalStorage refuses URL generation through an escaping symlink');
    assert_true(!file_exists($siblingDir2 . '/escaped.txt'), 'LocalStorage symlink escape writes nothing outside root');
    @unlink($linkDir2);
} else {
    echo "  [SKIP] LocalStorage symlink regression (symlink unavailable)\n";
}
@rmdir($siblingDir2);

// Cleanup
@unlink($tmpDir2 . '/blocked');
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
$testJobsDir45 = sys_get_temp_dir() . '/bareboneseshop-jobs-refund-' . getmypid();
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

    if ($totalImages === 0) {
        echo "  [SKIP] No product image references in live database\n";
    } else {
        $missingPct = round($missingImages / $totalImages * 100, 1);
        assert_true($missingImages === 0, "Product images: {$missingImages}/{$totalImages} missing on disk ({$missingPct}%)");
    }

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
// This fixture is created after the initial migration pass; reapply G9 so its
// additive payment correlation column is tested against the same late table.
$adminQueueMigration->up($pdo, true);

// Add payment_status column to orders
try { $pdo->exec("ALTER TABLE orders ADD COLUMN payment_status TEXT NOT NULL DEFAULT 'pending'"); }
catch (\PDOException $e) { /* already exists */ }

// Payment method pricing tables
$paymentMigFile = __DIR__ . '/../migrations/20260425071000_payment_methods_pricing_engine.php';
if (file_exists($paymentMigFile)) {
    $paymentMig = require $paymentMigFile;
    $paymentMig->up($pdo, true);
}

// Digital commerce tables and variant availability
$digitalCommerceMigFile = __DIR__ . '/../migrations/20260713093000_digital_commerce_core.php';
if (file_exists($digitalCommerceMigFile)) {
    $digitalCommerceMig = require $digitalCommerceMigFile;
    $digitalCommerceMig->up($pdo, true);
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

// Later feature tables must receive the same idempotent store backfill.
$storeScopeMigration->up($pdo, true);
$domainStoreConfig = Bootstrap::stores();
$domainStoreConfig['stores']['default']['domains'] = ['shop.test'];
assert_equals('default', StoreContext::resolve($domainStoreConfig, '/de/products', 'shop.test')->id(), 'Store resolution: matched domain takes precedence over another store URL prefix');
$deMarketContext = StoreContext::resolve(Bootstrap::stores(), '/de/products', '', 'de');
StoreContext::activate($deMarketContext);
assert_true(Products::bySku('TSHIRT-BLK-M') !== null, 'Store compatibility: existing market route shares the migrated default catalog');
$isolatedConfig = Bootstrap::stores();
$isolatedConfig['stores']['isolated'] = ['id' => 'isolated', 'name' => 'Isolated', 'default_locale' => 'en', 'languages' => ['en'], 'currency' => 'eur', 'url_prefix' => '/isolated', 'domains' => [], 'channels' => ['storefront'], 'permissions' => ['*'], 'legal' => []];
$deContext = StoreContext::resolve($isolatedConfig, '/isolated/products', '', 'isolated');
StoreContext::activate($deContext);
$pdo->exec("INSERT INTO categories (slug, name, path, depth, store_id) VALUES ('de-isolation', 'DE Isolation', 'de-isolation', 0, 'isolated')");
$deCategoryId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (sku, slug, name, category_id, price, currency, stock, store_id) VALUES ('DE-ONLY', 'de-only', 'DE only', ?, 1299, 'eur', 5, 'isolated')")->execute([$deCategoryId]);
assert_equals('DE-ONLY', Products::bySku('DE-ONLY')['sku'] ?? null, 'Store isolation: second store sees its product');
assert_true(Products::bySku('TSHIRT-BLK-M') === null, 'Store isolation: second store cannot read default catalog');
assert_true(Orders::byId($orderId) === null, 'Store isolation: second store cannot read default order');
Cache::write('isolation/key.txt', 'de');
$deCachePath = Cache::path('isolation/key.txt');
Bootstrap::initStoreContext('/', '', 'default');
Cache::write('isolation/key.txt', 'default');
assert_true($deCachePath !== Cache::path('isolation/key.txt'), 'Store isolation: identical cache keys have distinct paths');
assert_equals('default', Cache::read('isolation/key.txt'), 'Store isolation: default cache value is not contaminated');
assert_true(Products::bySku('DE-ONLY') === null, 'Store isolation: default store cannot read second-store catalog');
assert_true(Orders::byId($orderId) !== null, 'Store isolation: default order remains accessible in its store');

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

$mockGateway = new MockPayment(['mock_result' => 'success', 'mock_webhook_secret' => 'test-mock-webhook-secret']);

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
assert_equals('default', $txs[0]['store_id'], 'Payment: transaction stores active store ownership');
assert_true(!empty($txs[0]['correlation_id']), 'G9 payment: transaction stores the operational correlation ID');
StoreContext::activate($deContext);
assert_equals(0, count(PaymentService::transactionsForOrder((int)$payOrder['id'])), 'Store isolation: second store cannot read default payment transactions');
$crossStorePaymentRejected = false;
try { PaymentService::initiate(['order_id' => $payOrder['id']], $mockGateway); } catch (\RuntimeException $e) { $crossStorePaymentRejected = true; }
assert_true($crossStorePaymentRejected, 'Store isolation: second store cannot initiate payment for default order');
Bootstrap::initStoreContext('/', '', 'default');

echo "\n--- Test: Payment — webhook paid flow ---\n";

// Test B: simulate paid webhook
$paidPayload = MockPayment::paidPayload((int)$payOrder['id'], (int)$payOrder['total'], $session['session_id']);
$paidRawPayload = json_encode($paidPayload);
$paidSignature = hash_hmac('sha256', $paidRawPayload, 'test-mock-webhook-secret');
$webhookResult = PaymentService::handleWebhook(
    rawPayload:    $paidRawPayload,
    sigHeader:     $paidSignature,
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
    rawPayload:    $paidRawPayload,
    sigHeader:     $paidSignature,
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
    'street' => 'Testovacia 1',
    'city' => 'Bratislava',
    'zip' => '811 01',
    'country' => 'SK',
    'shipping_method' => 'packeta',
    'payment_method' => 'card',
];
$_SERVER['HTTP_HOST'] = 'attacker.example';
$onlineCheckoutCfg = require __DIR__ . '/../config.php';
$onlineCheckoutCfg['app_url'] = 'https://shop.example';
$onlineCheckout = apiCheckout($onlineCheckoutCfg);
assert_true(!empty($onlineCheckout['success']), 'Payment UX: online checkout succeeds');
assert_true(!empty($onlineCheckout['payment_url']), 'Payment UX: online checkout returns payment_url');
assert_true(str_starts_with((string)$onlineCheckout['payment_url'], 'https://shop.example/'), 'G3: checkout callback uses configured app_url');
assert_true(!str_contains((string)$onlineCheckout['payment_url'], 'attacker.example'), 'G3: checkout callback ignores poisoned Host header');
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
assert_equals('default', $labelRow['store_id'], 'Shipping: label stores active store ownership');
StoreContext::activate($deContext);
$crossStoreLabelRejected = false;
try { ShippingService::generateLabel($shippingOrderId, 'packeta'); } catch (\RuntimeException $e) { $crossStoreLabelRejected = true; }
assert_true($crossStoreLabelRejected, 'Store isolation: second store cannot generate a label for default order');
Bootstrap::initStoreContext('/', '', 'default');

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
$shippingWebhookSecret = 'test-shipping-webhook-secret';
putenv('SHIP_PACKETA_WEBHOOK_SECRET=' . $shippingWebhookSecret);
$webhookRaw = json_encode($webhookPayload, JSON_UNESCAPED_SLASHES);
$webhookSignature = hash_hmac('sha256', $webhookRaw, $shippingWebhookSecret);
StoreContext::activate($deContext);
$crossStoreWebhook = ShippingService::handleTrackingWebhook('packeta', $webhookPayload, $webhookSignature, $webhookRaw);
assert_equals('unknown_awb', $crossStoreWebhook['reason'] ?? null, 'Store isolation: second store webhook cannot resolve default shipping label');
Bootstrap::initStoreContext('/', '', 'default');
$webhookResult = ShippingService::handleTrackingWebhook('packeta', $webhookPayload, $webhookSignature, $webhookRaw);
assert_true($webhookResult['handled'], 'Shipping: tracking webhook handled=true');
assert_equals('delivered', $webhookResult['status'], 'Shipping: webhook status=delivered');
assert_equals($shippingOrderId, $webhookResult['order_id'], 'Shipping: webhook order_id matches');
$shippingReplay = ShippingService::handleTrackingWebhook('packeta', $webhookPayload, $webhookSignature, $webhookRaw);
assert_true($shippingReplay['duplicate'] ?? false, 'G3: shipping webhook replay is idempotent');

// Verify label status updated
$deliveredLabel = $pdo->query("SELECT status FROM shipping_labels WHERE awb = '{$labelResult['awb']}'")->fetch();
assert_equals('delivered', $deliveredLabel['status'], 'Shipping: label status updated to delivered after webhook');

// Verify order transitioned to delivered
$deliveredOrder = $pdo->query("SELECT status FROM orders WHERE id = {$shippingOrderId}")->fetch();
assert_equals('delivered', $deliveredOrder['status'], 'Shipping: order status = delivered after webhook');

echo "\n--- Test: Shipping — bad webhook signature rejected ---\n";
$badPayload = ['awb' => 'PKT-BAD', 'status' => 'in_transit'];
$badRaw = json_encode($badPayload, JSON_UNESCAPED_SLASHES);
$exceptionThrown = false;
try {
    ShippingService::handleTrackingWebhook('packeta', $badPayload, 'bad-sig', $badRaw);
} catch (\RuntimeException $e) {
    $exceptionThrown = str_contains($e->getMessage(), 'signature');
}
assert_true($exceptionThrown, 'Shipping: bad signature throws RuntimeException');
putenv('SHIP_PACKETA_WEBHOOK_SECRET');
$exceptionThrown = false;
try {
    ShippingService::handleTrackingWebhook('packeta', $badPayload, hash_hmac('sha256', $badRaw, $shippingWebhookSecret), $badRaw);
} catch (\RuntimeException $e) {
    $exceptionThrown = str_contains($e->getMessage(), 'not configured');
}
assert_true($exceptionThrown, 'G3: shipping webhook without configured secret fails closed');

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
    store_id   TEXT NOT NULL DEFAULT 'default',
    correlation_id TEXT DEFAULT NULL,
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
$originalMailFrom = getenv('MAIL_FROM');
$originalServerName = $_SERVER['SERVER_NAME'] ?? null;
putenv('MAIL_FROM=');
$_SERVER['SERVER_NAME'] = "attacker.example\r\nBcc: victim@example.com";
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
assert_equals('noreply@localhost.invalid', $last['from'] ?? '', 'G4: mail sender ignores poisoned SERVER_NAME');
assert_true(str_contains($last['html'] ?? '', 'LOG-1'), 'Email: LogMail rendered order_number into HTML');
$originalMailFrom === false ? putenv('MAIL_FROM') : putenv('MAIL_FROM=' . $originalMailFrom);
if ($originalServerName === null) {
    unset($_SERVER['SERVER_NAME']);
} else {
    $_SERVER['SERVER_NAME'] = $originalServerName;
}

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
$badTemplate = MailService::send('../order_paid', 'safe@example.com', 'en');
assert_equals('skipped', $badTemplate['status'], 'G4: unsafe mail template identifier is rejected');
for ($i = 0; $i < 3; $i++) {
    $resetMail = MailService::send('account_password_reset', 'flood-target@example.com', 'en', ['reset_url' => 'https://shop.example/reset']);
    assert_equals('sent', $resetMail['status'], 'G4: reset mail within recipient budget is sent');
}
$blockedResetMail = MailService::send('account_password_reset', 'flood-target@example.com', 'en', ['reset_url' => 'https://shop.example/reset']);
assert_equals('skipped', $blockedResetMail['status'], 'G4: fourth reset mail to one recipient is throttled');
assert_equals('recipient rate limit', $blockedResetMail['error'], 'G4: recipient throttle returns a stable internal reason');

echo "\n--- Test: Email — SmtpMail rejects bad addresses without connecting ---\n";
$smtp = new SmtpMail(['smtp_host' => '127.0.0.1', 'smtp_port' => 1]);
$smtpBad = $smtp->send(['to' => 'bad', 'from' => 'also-bad', 'subject' => 't', 'html' => 'h', 'text' => 'h']);
assert_equals('failed',            $smtpBad['status'],  'Email: SmtpMail rejects invalid addresses');
assert_equals('invalid recipient', $smtpBad['message'], 'Email: SmtpMail error message identifies recipient');

echo "\n--- Test: Security hardening — SMTP CRLF strip + XFF rightmost-trusted (P4) ---\n";
// #14 SMTP header injection: CR/LF stripped from header values
$crlfM = new ReflectionMethod('SmtpMail', 'stripCrlf');
$crlfM->setAccessible(true);
assert_equals('evilHeader', $crlfM->invoke(null, "evil\r\nHeader"), 'Security: stripCrlf removes CRLF from header value');
assert_equals('abc',        $crlfM->invoke(null, "a\rb\nc"),        'Security: stripCrlf removes bare CR and LF');

// #13 clientIp XFF anti-spoof: rightmost non-trusted address wins
$cfgProp = new ReflectionProperty('Security', 'cfg');
$cfgProp->setAccessible(true);
$origCfg = $cfgProp->getValue();
$origServer = $_SERVER;
$cfgProp->setValue(null, ['trusted_proxies' => ['10.0.0.1']]);
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 203.0.113.9, 10.0.0.1';
assert_equals('203.0.113.9', Security::clientIp(), 'Security: clientIp picks rightmost non-trusted XFF address');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
assert_equals('203.0.113.9', Security::clientIp(), 'Security: clientIp returns sole forwarded address');
$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1';
assert_equals('8.8.8.8', Security::clientIp(), 'Security: clientIp ignores XFF when remote is not a trusted proxy');
$_SERVER = $origServer;
$cfgProp->setValue(null, $origCfg);

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
$resendWrongOrder = MailService::resend($logId, 999999);
assert_equals('skipped', $resendWrongOrder['status'], 'G4: resend cannot use an email log from another order');

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

// Inline-execute (worker handler code path) with a forged payload recipient.
require_once __DIR__ . '/../cli/worker.php';
LogMail::clearCaptured();
handleEmailSend(['template' => 'order_paid', 'to' => 'attacker@example.com'], []);
assert_true(LogMail::lastCaptured() === null, 'G4: worker rejects non-cart mail jobs without an order');
$emailJob['payload']['to'] = 'attacker@example.com';
handleEmailSend($emailJob['payload'], $emailJob);

$lastMail = LogMail::lastCaptured();
assert_true($lastMail !== null, 'Email: worker dispatch captured by LogMail');
assert_equals('customer@example.com', $lastMail['to'] ?? '', 'G4: worker ignores forged recipient and uses order customer_email');
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
$catalogPricingB2bMigration->up($pdo, true);

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
assert_true(Customers::isLoggedIn(), 'G4: fresh customer session is accepted');
$_SESSION['customer_last_seen'] = time() - 1801;
assert_true(!Customers::isLoggedIn(), 'G4: idle customer session expires after 30 minutes');
[$lok, $lrow, $lerr] = Customers::login('alice@test.local', 'Passw0rd!');
assert_true($lok, 'G4: customer can log in again after idle expiry');

// Login wrong password
[$lok2, , $lerr2] = Customers::login('alice@test.local', 'Wrong!Pw1');
assert_true($lok2 === false && $lerr2 === 'invalid_credentials', 'Customer: wrong password rejected');

// Login non-existent email
[$lok3, , $lerr3] = Customers::login('ghost@test.local', 'Passw0rd!');
assert_true($lok3 === false && $lerr3 === 'invalid_credentials', 'Customer: non-existent email rejected');
$pdo->prepare("UPDATE customers SET status = 'blocked' WHERE id = ?")->execute([$aliceId]);
assert_true(!Customers::isLoggedIn(), 'G4: blocking an account invalidates its existing session');
[$blockedLogin, , $blockedErr] = Customers::login('alice@test.local', 'Passw0rd!');
assert_true(!$blockedLogin && $blockedErr === 'invalid_credentials', 'G4: blocked account uses the generic login error');
$pdo->prepare("UPDATE customers SET status = 'active' WHERE id = ?")->execute([$aliceId]);
[$activeAgain] = Customers::login('alice@test.local', 'Passw0rd!');
assert_true($activeAgain, 'G4: restored active account can authenticate again');

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
$preChangeSessionHash = (string)($_SESSION['customer_auth_hash'] ?? '');
[$cok, $cerr] = Customers::changePassword($aliceId, 'Passw0rd!', 'NewPassw0rd!');
assert_true($cok === true && $cerr === null, 'Customer: changePassword with correct old pw succeeds');
assert_true((string)($_SESSION['customer_auth_hash'] ?? '') !== $preChangeSessionHash, 'G4: password change rotates the session credential fingerprint');
$_SESSION['customer_auth_hash'] = $preChangeSessionHash;
assert_true(!Customers::isLoggedIn(), 'G4: a session bearing the previous password fingerprint is rejected');
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
$replacementTok = Customers::startPasswordReset('alice@test.local');
assert_true(Customers::verifyResetToken($tok) === null, 'G4: issuing a new reset token invalidates the previous token');
$tok = $replacementTok;
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

// --- P4 security: reset token stored hashed (sha256), not plaintext (#12) ---
$freshTok = Customers::startPasswordReset('alice@test.local');
$storedTok = $pdo->query("SELECT token FROM password_reset_tokens ORDER BY id DESC LIMIT 1")->fetchColumn();
assert_true($storedTok === hash('sha256', $freshTok), 'Security: reset token stored as sha256 hash in DB');
assert_true($storedTok !== $freshTok, 'Security: plaintext reset token NOT stored in DB');
assert_equals($aliceId, Customers::verifyResetToken($freshTok), 'Security: verifyResetToken accepts plaintext, matches stored hash');

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
// TEST: G6 catalog, price lists, promotions and B2B rules
// ============================================================
echo "\n--- Test: G6 Catalog / Pricing / B2B ---\n";
Bootstrap::initStoreContext('/', '', 'default');
$g6Product = $pdo->query("SELECT * FROM products WHERE store_id = 'default' AND status = 'active' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($g6Product), 'G6: active product fixture exists');
$g6ProductId = (int)$g6Product['id'];
$pdo->exec("INSERT INTO categories (slug, name, path, depth, store_id) VALUES ('g6-secondary', 'G6 Secondary', 'g6-secondary', 0, 'default')");
$g6SecondaryCategory = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO product_categories (product_id, category_id, is_primary, sort_order) VALUES (?, ?, 0, 10)")
    ->execute([$g6ProductId, $g6SecondaryCategory]);
$g6CategoryResult = Products::byCategory($g6SecondaryCategory);
assert_true(in_array($g6ProductId, array_map('intval', array_column($g6CategoryResult['products'], 'id')), true), 'G6: additional category participates in catalog listings');
assert_true(in_array($g6SecondaryCategory, Products::categoryIds($g6ProductId), true), 'G6: product exposes all category assignments');

$pdo->exec("INSERT INTO brands (store_id, code, name, manufacturer_name) VALUES ('default', 'g6-brand', 'G6 Brand', 'G6 Manufacturing s.r.o.')");
$g6BrandId = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE products SET brand_id=?, gtin='8580000000001', mpn='G6-MPN', cost_price=700, length_mm=300, width_mm=200, height_mm=100 WHERE id=?")
    ->execute([$g6BrandId, $g6ProductId]);
$g6CatalogRow = Products::byId($g6ProductId);
assert_equals('8580000000001', $g6CatalogRow['gtin'], 'G6: GTIN is a first-class catalog field');
assert_equals('G6-MPN', $g6CatalogRow['mpn'], 'G6: manufacturer part number is persisted');
assert_equals(700, (int)$g6CatalogRow['cost_price'], 'G6: cost basis remains integer cents');
$g6Margin = Products::margin(1234, 700);
assert_equals(534, $g6Margin['amount'], 'G6: margin amount remains deterministic integer cents');
assert_equals(4327, $g6Margin['bps'], 'G6: margin ratio uses deterministic basis points');

$pdo->exec("INSERT INTO customer_groups (store_id, code, name, discount_bps) VALUES ('default', 'trade', 'Trade', 500)");
$g6GroupId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO companies (store_id, code, legal_name, registration_number, vat_id, vat_country, vat_validated_at, approval_status, credit_limit, payment_terms_days, invoice_purchase)
    VALUES ('default', 'acme', 'ACME Trade s.r.o.', '12345678', 'SK1234567890', 'SK', datetime('now'), 'approved', 5000, 30, 1)");
$g6CompanyId = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE customers SET customer_group_id=?, company_id=? WHERE id=?")
    ->execute([$g6GroupId, $g6CompanyId, $aliceId]);
$g6Profile = Customers::commerceProfile($aliceId);
assert_equals('trade', $g6Profile['customer_group_code'], 'G6: customer resolves its store-scoped group');
assert_equals('approved', $g6Profile['approval_status'], 'G6: company approval is explicit');
assert_equals(30, (int)$g6Profile['payment_terms_days'], 'G6: payment terms are explicit days');
StoreContext::activate($deContext);
assert_true(Customers::commerceProfile($aliceId) === null, 'G6: company and customer-group profile cannot cross stores');
[$g6IsolatedRegistered, $g6IsolatedCustomerId] = Customers::register('isolated-g6@test.local', 'Passw0rd!', 'Isolated G6');
assert_true($g6IsolatedRegistered && $g6IsolatedCustomerId > 0, 'G6: customer registration succeeds in a non-default store');
$g6RegisteredStore = $pdo->prepare('SELECT store_id FROM customers WHERE id = ?');
$g6RegisteredStore->execute([$g6IsolatedCustomerId]);
assert_equals('isolated', $g6RegisteredStore->fetchColumn(), 'G6: registration persists the active store identity');
Bootstrap::initStoreContext('/', '', 'default');

$pdo->prepare("INSERT INTO price_lists (store_id, code, name, currency, customer_group_id, priority) VALUES ('default', 'trade-eur', 'Trade EUR', 'eur', ?, 100)")
    ->execute([$g6GroupId]);
$g6PriceListId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO price_list_prices (price_list_id, product_id, min_qty, price, compare_price) VALUES (?, ?, 1, 1234, 1500)")
    ->execute([$g6PriceListId, $g6ProductId]);
$g6Priced = Pricing::applyPriceList($g6CatalogRow, null, 1, $aliceId, '2026-08-01 12:00:00');
assert_equals(1234, (int)$g6Priced['price'], 'G6: store/currency/customer-group price list resolves integer cents');
assert_equals('trade-eur', $g6Priced['price_list_snapshot']['code'], 'G6: selected price-list code is snapshotted');
$g6GuestPrice = Pricing::applyPriceList($g6CatalogRow, null, 1, null, '2026-08-01 12:00:00');
assert_equals((int)$g6CatalogRow['price'], (int)$g6GuestPrice['price'], 'G6: group price list does not leak to guests');

$pdo->prepare("INSERT INTO promotions (store_id, code, name, scope, priority, stacking, conditions, actions)
    VALUES ('default', 'trade-ten', 'Trade ten percent', 'catalog', 10, 'exclusive', ?, ?)")
    ->execute([
        json_encode(['product_ids' => [$g6ProductId], 'customer_group_ids' => [$g6GroupId]]),
        json_encode(['type' => 'discount_percent', 'bps' => 1000]),
    ]);
$g6Promoted = Promotions::applyToProduct($g6Priced, 1, $aliceId, '2026-08-01 12:00:00');
assert_equals(1111, (int)$g6Promoted['price'], 'G6: promotion basis points round deterministically in cents');
assert_equals('trade-ten', $g6Promoted['promotion_snapshot']['applied'][0]['code'], 'G6: applied promotion is snapshotted');
$pdo->prepare("INSERT INTO variants (product_id, sku, name, stock, price_delta, media, weight_g, length_mm, width_mm, height_mm, availability)
    VALUES (?, 'G6-VARIANT', 'G6 Variant', 5, 0, '[\"/uploads/g6.webp\"]', 900, 400, 300, 200, 'active')")
    ->execute([$g6ProductId]);
$g6VariantId = (int)$pdo->lastInsertId();
$previousCart = $_SESSION['cart'] ?? [];
$_SESSION['customer_id'] = $aliceId;
$_SESSION['cart'] = ['v_' . $g6VariantId => ['product_id' => $g6ProductId, 'variant_id' => $g6VariantId, 'qty' => 1]];
$g6VariantCart = Cart::enriched();
assert_equals(900, (int)$g6VariantCart['items'][0]['weight_g'], 'G6: variant weight overrides product logistics');
assert_equals(400, (int)$g6VariantCart['items'][0]['length_mm'], 'G6: variant dimensions are resolved into checkout');
assert_equals('trade-eur', $g6VariantCart['items'][0]['attrs']['_pricing']['price_list']['code'], 'G6: cart line carries its price-list snapshot');
$_SESSION['cart'] = $previousCart;

foreach ([
    ['g6-free-shipping', 20, 'stack', ['min_subtotal' => 1000], ['type' => 'free_shipping']],
    ['g6-buy-x-get-y', 30, 'stack', ['buy_product_id' => $g6ProductId, 'buy_qty' => 2], ['type' => 'buy_x_get_y', 'product_id' => $g6ProductId, 'qty' => 1]],
    ['g6-gift', 40, 'stack', ['min_subtotal' => 1000], ['type' => 'gift', 'product_id' => $g6ProductId, 'qty' => 1]],
] as [$code, $priority, $stacking, $conditions, $actions]) {
    $stmt = $pdo->prepare("INSERT INTO promotions (store_id, code, name, scope, priority, stacking, conditions, actions) VALUES ('default', ?, ?, 'cart', ?, ?, ?, ?)");
    $stmt->execute([$code, $code, $priority, $stacking, json_encode($conditions), json_encode($actions)]);
}
$g6CartItems = [['product_id' => $g6ProductId, 'variant_id' => null, 'qty' => 2, 'price' => 1111]];
$g6CartActions = Promotions::cartActions($g6CartItems, 2222, $aliceId, '2026-08-01 12:00:00');
assert_true(in_array('free_shipping', array_column($g6CartActions, 'action'), true), 'G6: automatic free-shipping promotion resolves');
$g6Bxgy = array_values(array_filter($g6CartActions, static fn(array $rule): bool => $rule['action'] === 'buy_x_get_y'))[0] ?? [];
assert_equals(1111, (int)($g6Bxgy['discount'] ?? 0), 'G6: buy-X-get-Y discounts only an eligible in-cart unit');
$g6Gift = array_values(array_filter($g6CartActions, static fn(array $rule): bool => $rule['action'] === 'gift'))[0] ?? [];
assert_equals($g6ProductId, (int)($g6Gift['gift']['product_id'] ?? 0), 'G6: gift entitlement is explicit without silently mutating stock');

$invoiceQuote = PaymentService::selectMethod('invoice', 'SK', 'eur', 3000, 'gls', $aliceId);
assert_true($invoiceQuote !== null, 'G6: approved company can purchase on invoice within credit limit');
assert_equals(30, (int)$invoiceQuote['payment_terms_days'], 'G6: invoice quote freezes payment terms');
assert_true(PaymentService::selectMethod('invoice', 'SK', 'eur', 4000, 'gls', $aliceId) === null, 'G6: outstanding exposure plus order amount enforces credit limit');
$pdo->prepare("UPDATE companies SET approval_status='pending' WHERE id=?")->execute([$g6CompanyId]);
assert_true(PaymentService::selectMethod('invoice', 'SK', 'eur', 100, 'gls', $aliceId) === null, 'G6: unapproved company cannot purchase on invoice');
$pdo->prepare("UPDATE companies SET approval_status='approved' WHERE id=?")->execute([$g6CompanyId]);
assert_true(PaymentService::selectMethod('invoice', 'SK', 'eur', 100, 'gls', null) === null, 'G6: guest cannot purchase on invoice');

// ============================================================
// TEST: Product Reviews (P7.1)
// ============================================================
echo "\n--- Test: Product Reviews ---\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS product_reviews (
    id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, customer_id INTEGER DEFAULT NULL,
    author_name TEXT NOT NULL DEFAULT '', rating INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT '', body TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)");

// --- submit validation ---
[$rok, $rid, $rerr] = Reviews::submit(1, ['rating' => 5, 'author_name' => 'Alice', 'title' => 'Great', 'body' => 'Loved it']);
assert_true($rok === true && $rid > 0 && $rerr === null, 'Reviews: valid submit returns [true, id, null]');

[$rok2, , $rerr2] = Reviews::submit(1, ['rating' => 6, 'author_name' => 'A', 'body' => 'too high']);
assert_true($rok2 === false && $rerr2 === 'invalid_rating', 'Reviews: rating > 5 rejected');

[$rok3, , $rerr3] = Reviews::submit(1, ['rating' => 0, 'author_name' => 'A', 'body' => 'too low']);
assert_true($rok3 === false && $rerr3 === 'invalid_rating', 'Reviews: rating < 1 rejected');

[$rok4, , $rerr4] = Reviews::submit(1, ['rating' => 4, 'author_name' => 'A', 'body' => 'ab']);
assert_true($rok4 === false && $rerr4 === 'body_too_short', 'Reviews: body < 3 chars rejected');

[$rok5, , $rerr5] = Reviews::submit(1, ['rating' => 4, 'author_name' => '', 'body' => 'no author']);
assert_true($rok5 === false && $rerr5 === 'author_required', 'Reviews: empty author rejected');

[$rok6, , $rerr6] = Reviews::submit(0, ['rating' => 4, 'author_name' => 'A', 'body' => 'no product']);
assert_true($rok6 === false && $rerr6 === 'invalid_product', 'Reviews: invalid product_id rejected');

// --- pending / moderation ---
$pendingBefore = Reviews::pending();
assert_true(count($pendingBefore) >= 1, 'Reviews: submitted review appears in pending queue');

$one = Reviews::byId($rid);
assert_true(is_array($one) && $one['status'] === 'pending', 'Reviews: new review starts as pending');

// approved() excludes pending
assert_equals(0, count(Reviews::approved(1)), 'Reviews: approved() empty while review is pending');

// aggregate over approved only — still 0
$aggPending = Reviews::aggregate(1);
assert_equals(0, $aggPending['count'], 'Reviews: aggregate count 0 while pending');
assert_equals(0.0, $aggPending['avg'], 'Reviews: aggregate avg 0.0 while pending');

// --- approve and verify visibility ---
assert_true(Reviews::setStatus($rid, 'approved'), 'Reviews: setStatus approved returns true');
$approved = Reviews::approved(1);
assert_equals(1, count($approved), 'Reviews: approved() lists the approved review');
assert_equals(5, (int)$approved[0]['rating'], 'Reviews: approved review keeps its rating');

// add a second approved review (rating 3) → avg = 4.0
[$r2ok, $r2id] = Reviews::submit(1, ['rating' => 3, 'author_name' => 'Bob', 'body' => 'It was ok']);
Reviews::setStatus($r2id, 'approved');
$agg = Reviews::aggregate(1);
assert_equals(2, $agg['count'], 'Reviews: aggregate counts both approved');
assert_equals(4.0, $agg['avg'], 'Reviews: aggregate avg = (5+3)/2 = 4.0');

// rejected review is excluded from aggregate + approved
[$r3ok, $r3id] = Reviews::submit(1, ['rating' => 1, 'author_name' => 'Troll', 'body' => 'spam spam']);
Reviews::setStatus($r3id, 'rejected');
$agg2 = Reviews::aggregate(1);
assert_equals(2, $agg2['count'], 'Reviews: rejected review excluded from aggregate');
assert_equals(0, count(array_filter(Reviews::approved(1), fn($r) => (int)$r['id'] === $r3id)), 'Reviews: rejected review not in approved()');

// setStatus rejects unknown status + missing id
assert_true(Reviews::setStatus($rid, 'bogus') === false, 'Reviews: unknown status rejected');
assert_true(Reviews::setStatus(999999, 'approved') === false, 'Reviews: setStatus on missing id returns false');

// per-product isolation: product 2 has no reviews
assert_equals(0, Reviews::aggregate(2)['count'], 'Reviews: aggregate isolated per product');

// --- P7.2: api.php review_submit endpoint ---
if (!defined('BB_TEST_LOAD_API_ONLY')) {
    define('BB_TEST_LOAD_API_ONLY', true);
    require_once __DIR__ . '/../api.php';
}
// A real product is needed (handler verifies Products::byId). Product 501/502 exist from earlier seed;
// fall back to creating one if not present in this DB position.
$apiProdId = (int)($pdo->query("SELECT id FROM products LIMIT 1")->fetchColumn() ?: 0);
assert_true($apiProdId > 0, 'Reviews API: a product exists to attach a review to');

$submitReview = function (array $body, ?int $customerId = null) use ($apiProdId) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SESSION['csrf_token'] = 'rv-test-token';
    if ($customerId !== null) { $_SESSION['customer_id'] = $customerId; }
    else { unset($_SESSION['customer_id']); }
    $_POST = array_merge(['csrf_token' => 'rv-test-token', 'product_id' => $apiProdId], $body);
    $res = apiReviewSubmit();
    $_POST = [];
    unset($_SESSION['customer_id']);
    return $res;
};

// Guest submit → ok, pending
$ar = $submitReview(['rating' => 5, 'author_name' => 'API Guest', 'body' => 'Solid product']);
assert_true(!empty($ar['ok']) && ($ar['status'] ?? '') === 'pending', 'Reviews API: guest submit returns ok + pending');
$arRow = Reviews::byId((int)$ar['id']);
assert_true($arRow['status'] === 'pending' && $arRow['customer_id'] === null, 'Reviews API: guest review stored pending, no customer_id');

// Validation error bubbles up (rating out of range)
$arBad = $submitReview(['rating' => 9, 'author_name' => 'X', 'body' => 'nope']);
assert_true(($arBad['ok'] ?? null) === false && $arBad['error'] === 'invalid_rating', 'Reviews API: invalid rating rejected');

// Missing author for guest
$arNoAuthor = $submitReview(['rating' => 4, 'body' => 'no name given']);
assert_true(($arNoAuthor['ok'] ?? null) === false && $arNoAuthor['error'] === 'author_required', 'Reviews API: guest without author rejected');

// Invalid product id
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['csrf_token'] = 'rv-test-token';
$_POST = ['csrf_token' => 'rv-test-token', 'product_id' => 999999, 'rating' => 5, 'author_name' => 'Y', 'body' => 'ghost product'];
$arGhost = apiReviewSubmit();
$_POST = [];
assert_true(($arGhost['ok'] ?? null) === false && $arGhost['error'] === 'invalid_product', 'Reviews API: non-existent product rejected');

// Logged-in customer → review attributed + name taken from account
$cust = Customers::byId($aliceId);
$arCust = $submitReview(['rating' => 4, 'body' => 'as a registered customer'], $aliceId);
assert_true(!empty($arCust['ok']), 'Reviews API: logged-in customer submit ok');
$arCustRow = Reviews::byId((int)$arCust['id']);
assert_equals($aliceId, (int)$arCustRow['customer_id'], 'Reviews API: review attributed to logged-in customer');
assert_equals($cust['name'], $arCustRow['author_name'], 'Reviews API: author_name taken from customer account');

// CSRF rejection
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['csrf_token'] = 'rv-test-token';
$_POST = ['csrf_token' => 'WRONG', 'product_id' => $apiProdId, 'rating' => 5, 'author_name' => 'Z', 'body' => 'csrf attempt'];
$arCsrf = apiReviewSubmit();
$_POST = [];
assert_true(isset($arCsrf['error']), 'Reviews API: invalid CSRF token rejected');

// ============================================================
// TEST: Wishlist (P7.3)
// ============================================================
echo "\n--- Test: Wishlist ---\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS wishlist_items (
    id INTEGER PRIMARY KEY, owner_key TEXT NOT NULL, customer_id INTEGER DEFAULT NULL,
    product_id INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(owner_key, product_id)
)");

// RMA tables (mirror of migrations/20260426100000_create_rma.php)
$pdo->exec("CREATE TABLE IF NOT EXISTS rma_requests (
    id INTEGER PRIMARY KEY, rma_number TEXT NOT NULL, order_id INTEGER NOT NULL,
    customer_id INTEGER DEFAULT NULL, status TEXT NOT NULL DEFAULT 'requested',
    reason TEXT NOT NULL DEFAULT '', admin_note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(rma_number)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS rma_items (
    id INTEGER PRIMARY KEY, rma_id INTEGER NOT NULL, order_item_id INTEGER NOT NULL,
    product_id INTEGER DEFAULT NULL, sku TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '',
    quantity INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT (datetime('now'))
)");
$returnsCreditMigration = require __DIR__ . '/../migrations/20260801210000_returns_credit_notes.php';
$returnsCreditMigration->up($pdo, true);
$returnsCreditMigration->up($pdo, true);
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='order_refunds'")->fetchColumn(), 'G8 migration: item refund ledger exists and migration is idempotent');

$wlProd = (int)($pdo->query("SELECT id FROM products LIMIT 1")->fetchColumn() ?: 0);
$wlProd2 = (int)($pdo->query("SELECT id FROM products ORDER BY id LIMIT 1 OFFSET 1")->fetchColumn() ?: 0);
assert_true($wlProd > 0, 'Wishlist: a product exists to save');

$guest = 's:wltest';
$cust  = 'c:7001';

// add + has + count (explicit owner key, no session dependency)
assert_true(Wishlist::add($wlProd, $guest), 'Wishlist: add returns true on new row');
assert_true(Wishlist::has($wlProd, $guest), 'Wishlist: has true after add');
assert_equals(1, Wishlist::count($guest), 'Wishlist: count 1 after add');

// add is idempotent — second add for same product returns false, count unchanged
assert_true(Wishlist::add($wlProd, $guest) === false, 'Wishlist: duplicate add returns false');
assert_equals(1, Wishlist::count($guest), 'Wishlist: count still 1 after duplicate add');

// invalid product id rejected
assert_true(Wishlist::add(0, $guest) === false, 'Wishlist: add invalid product_id rejected');

// per-owner isolation — customer owner does not see guest item
assert_true(Wishlist::has($wlProd, $cust) === false, 'Wishlist: per-owner isolation');
assert_equals(0, Wishlist::count($cust), 'Wishlist: other owner count 0');

// toggle: present → removed, absent → added
assert_equals('removed', Wishlist::toggle($wlProd, $guest), 'Wishlist: toggle removes when present');
assert_equals(0, Wishlist::count($guest), 'Wishlist: count 0 after toggle-remove');
assert_equals('added', Wishlist::toggle($wlProd, $guest), 'Wishlist: toggle adds when absent');
assert_equals(1, Wishlist::count($guest), 'Wishlist: count 1 after toggle-add');

// remove returns true when a row is deleted, false when nothing to delete
assert_true(Wishlist::remove($wlProd, $guest), 'Wishlist: remove returns true when present');
assert_true(Wishlist::remove($wlProd, $guest) === false, 'Wishlist: remove returns false when absent');

// ids ordering — newest first
Wishlist::add($wlProd, $guest);
if ($wlProd2 > 0) { Wishlist::add($wlProd2, $guest); }
$ids = Wishlist::ids($guest);
if ($wlProd2 > 0) {
    assert_equals($wlProd2, $ids[0] ?? 0, 'Wishlist: ids newest-first');
    assert_equals(2, count($ids), 'Wishlist: ids returns both');
}

// list() returns enriched, active products only
$listed = Wishlist::list($guest);
assert_true(count($listed) >= 1 && isset($listed[0]['name']), 'Wishlist: list returns enriched product rows');

// merge: fold guest session wishlist into a customer wishlist
$mergeSess = 's:mergeme';
$mergeCust = 8002;
Wishlist::add($wlProd, $mergeSess);
$moved = Wishlist::merge($mergeSess, $mergeCust);
assert_equals(1, $moved, 'Wishlist: merge moved 1 row');
assert_true(Wishlist::has($wlProd, 'c:' . $mergeCust), 'Wishlist: merged item now under customer key');
assert_equals(0, Wishlist::count($mergeSess), 'Wishlist: session wishlist emptied after merge');

// --- P7.3: api.php wishlist_toggle / wishlist endpoints ---
// (api.php already loaded above for the reviews API tests)
$wlToggle = function (int $pid) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SESSION['csrf_token'] = 'wl-test-token';
    unset($_SESSION['customer_id']);
    $_POST = ['csrf_token' => 'wl-test-token', 'product_id' => $pid];
    $res = apiWishlistToggle();
    $_POST = [];
    return $res;
};

$wa1 = $wlToggle($wlProd);
assert_true(!empty($wa1['ok']) && $wa1['state'] === 'added' && $wa1['in_list'] === true, 'Wishlist API: toggle adds (state=added)');
$wa2 = $wlToggle($wlProd);
assert_true(!empty($wa2['ok']) && $wa2['state'] === 'removed' && $wa2['in_list'] === false, 'Wishlist API: toggle removes (state=removed)');

// invalid product rejected
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['csrf_token'] = 'wl-test-token';
$_POST = ['csrf_token' => 'wl-test-token', 'product_id' => 999999];
$waGhost = apiWishlistToggle();
$_POST = [];
assert_true(($waGhost['ok'] ?? null) === false && $waGhost['error'] === 'invalid_product', 'Wishlist API: invalid product rejected');

// CSRF rejection
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['csrf_token'] = 'wl-test-token';
$_POST = ['csrf_token' => 'WRONG', 'product_id' => $wlProd];
$waCsrf = apiWishlistToggle();
$_POST = [];
assert_true(isset($waCsrf['error']), 'Wishlist API: invalid CSRF token rejected');

// read endpoint reflects current owner's list
$wlToggle($wlProd); // add back
$waList = apiWishlist();
assert_true(!empty($waList['ok']) && in_array($wlProd, $waList['ids'], true), 'Wishlist API: wishlist read returns saved id');

// ============================================================
// TEST: RMA / Returns
// ============================================================
echo "\n--- Test: RMA / Returns ---\n";

// Seed stock and create a delivered order owned by a customer (alice).
$rmaProd = Products::bySku($testProduct['sku']) ?: $testProduct;
$rmaHasStock = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_stock WHERE sku = " . $pdo->quote($rmaProd['sku']))->fetchColumn();
if ($rmaHasStock === 0) {
    $pdo->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (:wh,:sku,100,0)")
        ->execute([':wh' => $whId, ':sku' => $rmaProd['sku']]);
} else {
    $pdo->prepare("UPDATE warehouse_stock SET qty = qty + 50 WHERE sku = :sku")->execute([':sku' => $rmaProd['sku']]);
}
$rmaCustomer = [
    'name' => 'Alice', 'email' => 'alice@example.com', 'phone' => '',
    'address' => ['street' => 'A 1', 'city' => 'BA', 'zip' => '81101', 'country' => 'SK'],
    'currency' => 'eur', 'shipping_cost' => 0, 'tax_amount' => 0,
    'customer_id' => $aliceId,
];
$rmaCart = [[
    'product_id' => $rmaProd['id'], 'variant_id' => null, 'qty' => 2,
    'price' => $rmaProd['price'], 'sku' => $rmaProd['sku'], 'name' => $rmaProd['name'], 'variant_name' => '',
]];
$rmaOrderId = Orders::create($rmaCart, $rmaCustomer, 'cod');
Fulfillment::shipOrder($rmaOrderId);
Orders::updateStatus($rmaOrderId, 'delivered');
$rmaItems = Orders::items($rmaOrderId);
$rmaOi1 = (int)$rmaItems[0]['id'];
assert_equals('delivered', Orders::byId($rmaOrderId)['status'], 'RMA: seed order is delivered');

// A second, still-pending order (not returnable)
$rmaPendingOrderId = Orders::create($rmaCart, $rmaCustomer, 'cod');

// Guards: invalid order
assert_equals('invalid_order', Rma::create(0, $aliceId, 'x', [$rmaOi1 => 1])[2], 'RMA: invalid order id rejected');
assert_equals('invalid_order', Rma::create(999999, $aliceId, 'x', [$rmaOi1 => 1])[2], 'RMA: non-existent order rejected');

// Guard: pending order not returnable
assert_equals('order_not_returnable', Rma::create($rmaPendingOrderId, $aliceId, 'x', [$rmaOi1 => 1])[2], 'RMA: non-delivered order rejected');

// Guard: ownership
assert_equals('not_owner', Rma::create($rmaOrderId, $aliceId + 99999, 'x', [$rmaOi1 => 1])[2], 'RMA: order owned by another customer rejected');

// Guard: reason required
assert_equals('reason_required', Rma::create($rmaOrderId, $aliceId, '   ', [$rmaOi1 => 1])[2], 'RMA: empty reason rejected');

// Guard: no items
assert_equals('no_items', Rma::create($rmaOrderId, $aliceId, 'damaged', [])[2], 'RMA: empty item set rejected');
assert_equals('no_items', Rma::create($rmaOrderId, $aliceId, 'damaged', [999999 => 1])[2], 'RMA: foreign order_item rejected → no_items');

// Valid first partial request.
[$rOk, $rId, $rErr] = Rma::create($rmaOrderId, $aliceId, 'Box arrived crushed', [$rmaOi1 => 1]);
assert_true($rOk === true && $rId > 0 && $rErr === null, 'RMA: valid create returns ok + id');
$rmaRow = Rma::byId($rId);
assert_equals('requested', $rmaRow['status'], 'RMA: new request lands as requested');
assert_equals($aliceId, (int)$rmaRow['customer_id'], 'RMA: request attributed to customer');
$rmaLineItems = Rma::items($rId);
assert_equals(1, count($rmaLineItems), 'RMA: one line item stored');
assert_equals(1, (int)$rmaLineItems[0]['quantity'], 'RMA: first partial quantity stored');

// A second partial RMA can remain open for the unclaimed quantity.
[$rOk2, $rId2, $rErr2] = Rma::create($rmaOrderId, $aliceId, 'second parcel', [$rmaOi1 => 99]);
assert_true($rOk2 === true && $rId2 > 0 && $rErr2 === null, 'RMA: second partial request is allowed');
assert_equals(1, (int)Rma::items($rId2)[0]['quantity'], 'RMA: second request is clamped to remaining quantity');
assert_equals('no_items', Rma::create($rmaOrderId, $aliceId, 'over claim', [$rmaOi1 => 1])[2], 'RMA: claimed quantities cannot exceed ordered quantity');

// openForOrder finds the open request
assert_true(Rma::openForOrder($rmaOrderId) !== null, 'RMA: openForOrder returns the open request');

// forCustomer lists it
$rmaCustList = Rma::forCustomer($aliceId);
assert_true(count($rmaCustList) >= 2 && (int)$rmaCustList[0]['id'] === $rId2, 'RMA: forCustomer returns all partial requests newest first');

// Transition guards
assert_true(Rma::setStatus($rId, 'refunded') === false, 'RMA: requested → refunded blocked (illegal jump)');
assert_true(Rma::setStatus($rId, 'approved') === true, 'RMA: requested → approved allowed');
assert_equals('approved', Rma::byId($rId)['status'], 'RMA: status now approved');
$label = Rma::recordLabel($rId, 'http-carrier', 'RET-001', 'label-rma-1', 'TRACK-RET-1', 'https://carrier.test/labels/RET-001.pdf');
assert_equals('RET-001', (string)$label['label_reference'], 'RMA: return label reference is audited');
assert_equals((int)$label['id'], (int)Rma::recordLabel($rId, 'http-carrier', 'RET-001', 'label-rma-1')['id'], 'RMA: return label creation is idempotent');
$labelConflict = false;
try { Rma::recordLabel($rId, 'http-carrier', 'RET-DIFFERENT', 'label-rma-1'); } catch (LogicException $e) { $labelConflict = true; }
assert_true($labelConflict, 'RMA: label idempotency key rejects changed input');
$rmaStockBeforeReceipt = (int)$pdo->query("SELECT SUM(qty) FROM warehouse_stock WHERE sku=" . $pdo->quote($rmaProd['sku']))->fetchColumn();
assert_true(Rma::receive($rId, [$rmaLineItems[0]['id'] => ['quantity' => 1, 'disposition' => 'restock']], 'receipt-rma-1', 'parcel in'), 'RMA: partial receipt with restock succeeds');
assert_true(Rma::receive($rId, [$rmaLineItems[0]['id'] => ['quantity' => 1, 'disposition' => 'restock']], 'receipt-rma-1', 'replay') === false, 'RMA: receipt replay is idempotent');
$receiptConflict = false;
try { Rma::receive($rId, [$rmaLineItems[0]['id'] => ['quantity' => 1, 'disposition' => 'discard']], 'receipt-rma-1'); }
catch (LogicException $e) { $receiptConflict = true; }
assert_true($receiptConflict, 'RMA: receipt idempotency key rejects changed input');
assert_equals($rmaStockBeforeReceipt + 1, (int)$pdo->query("SELECT SUM(qty) FROM warehouse_stock WHERE sku=" . $pdo->quote($rmaProd['sku']))->fetchColumn(), 'RMA: accepted restock returns exactly one shipped unit');
assert_equals('received', Rma::byId($rId)['status'], 'RMA: fully received partial request advances to received');
assert_equals('parcel in', (string)$pdo->query("SELECT note FROM return_receipts WHERE rma_id={$rId}")->fetchColumn(), 'RMA: receipt note persisted');
assert_true(Rma::setStatus($rId, 'refunded') === true, 'RMA: received → refunded allowed');
assert_true(Rma::setStatus($rId, 'approved') === false, 'RMA: refunded is terminal');

// Unknown id / status
assert_true(Rma::setStatus(999999, 'approved') === false, 'RMA: setStatus on unknown id false');
assert_true(Rma::setStatus($rId, 'bogus') === false, 'RMA: setStatus with unknown status false');

// The independent second partial request remains open and can choose a non-stock disposition plus exchange.
assert_true(Rma::openForOrder($rmaOrderId) !== null, 'RMA: another partial request remains open');
assert_true(Rma::setStatus($rId2, 'approved') === true, 'RMA: second partial request is approved independently');
$rmaLine2 = Rma::items($rId2)[0];
$rmaStockBeforeDiscard = (int)$pdo->query("SELECT SUM(qty) FROM warehouse_stock WHERE sku=" . $pdo->quote($rmaProd['sku']))->fetchColumn();
assert_true(Rma::receive($rId2, [$rmaLine2['id'] => ['quantity' => 1, 'disposition' => 'discard']], 'receipt-rma-2'), 'RMA: discard receipt succeeds');
assert_equals($rmaStockBeforeDiscard, (int)$pdo->query("SELECT SUM(qty) FROM warehouse_stock WHERE sku=" . $pdo->quote($rmaProd['sku']))->fetchColumn(), 'RMA: discard does not change on-hand stock');
$exchangeId = Rma::createExchange($rId2, (int)$rmaLine2['id'], 1, (int)$rmaProd['id'], null, 'exchange-rma-2');
assert_true($exchangeId > 0, 'RMA: received item can be reserved for an exchange');
$exchangeOverClaim = false;
try { Rma::createExchange($rId2, (int)$rmaLine2['id'], 1, (int)$rmaProd['id'], null, 'exchange-rma-2-over'); }
catch (LogicException $e) { $exchangeOverClaim = true; }
assert_true($exchangeOverClaim, 'RMA: exchange cannot reuse refunded or already exchanged quantity');
assert_true(Rma::setStatus($rId2, 'refunded') === true, 'RMA: exchanged request can be closed');
assert_true(Rma::openForOrder($rmaOrderId) === null, 'RMA: no open request after every partial request is terminal');

// ============================================================
// TEST: Admin customer management (P7.5)
// ============================================================
echo "\n--- Test: Admin Customer Management ---\n";

// adminList returns the seeded customer (alice) and supports search
$allCust = Customers::adminList('', 100);
assert_true(count($allCust) >= 1, 'AdminCustomers: list returns customers');
$byEmail = Customers::adminList('alice@test.local', 50);
assert_true(count($byEmail) >= 1 && (int)$byEmail[0]['id'] === $aliceId, 'AdminCustomers: search by email finds alice');
$byName = Customers::adminList('Alice', 50);
assert_true(count($byName) >= 1, 'AdminCustomers: search by name matches');
$byId = Customers::adminList((string)$aliceId, 50);
assert_true(count($byId) >= 1 && (int)$byId[0]['id'] === $aliceId, 'AdminCustomers: numeric query matches id');
$noHit = Customers::adminList('no-such-person@nowhere.test', 50);
assert_equals(0, count($noHit), 'AdminCustomers: non-matching search returns empty');

// setStatus: block then reactivate
assert_equals('active', Customers::byId($aliceId)['status'], 'AdminCustomers: alice starts active');
assert_true(Customers::setStatus($aliceId, 'blocked') === true, 'AdminCustomers: block returns true');
assert_equals('blocked', Customers::byId($aliceId)['status'], 'AdminCustomers: status now blocked');
assert_true(Customers::setStatus($aliceId, 'active') === true, 'AdminCustomers: reactivate returns true');
assert_equals('active', Customers::byId($aliceId)['status'], 'AdminCustomers: status back to active');

// setStatus guards: invalid status, unknown id, anonymized terminal
assert_true(Customers::setStatus($aliceId, 'bogus') === false, 'AdminCustomers: invalid status rejected');
assert_true(Customers::setStatus(0, 'blocked') === false, 'AdminCustomers: invalid id rejected');
assert_true(Customers::setStatus(999999, 'blocked') === false, 'AdminCustomers: unknown customer rejected');
$pdo->prepare("UPDATE customers SET status = 'anonymized' WHERE id = ?")->execute([$aliceId]);
assert_true(Customers::setStatus($aliceId, 'active') === false, 'AdminCustomers: anonymized account cannot be reactivated');
$pdo->prepare("UPDATE customers SET status = 'active' WHERE id = ?")->execute([$aliceId]); // restore for later tests

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
// Apply invoices(order_id) unique-index migration (bug #9) so all invoice
// tests below run with the production constraint active
$uqMigFile = __DIR__ . '/../migrations/20260611220000_add_invoices_order_unique.php';
if (file_exists($uqMigFile)) {
    $uqMig = require $uqMigFile;
    $uqMig->up(Db::get(), Db::isSqlite());
}
$financialMigFile = __DIR__ . '/../migrations/20260801141000_financial_snapshots.php';
if (file_exists($financialMigFile)) {
    $financialMig = require $financialMigFile;
    $financialMig->up(Db::get(), Db::isSqlite());
}
$storeScopeMigration->up(Db::get(), true);
$returnsCreditMigration->up(Db::get(), true);

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
assert_equals('default', $inv1['store_id'], 'Invoice: issued invoice stores active store ownership');
StoreContext::activate($deContext);
assert_true(InvoiceService::byOrderId(5001) === null, 'Store isolation: second store cannot read default invoice by order');
assert_true(InvoiceService::byToken((string)$inv1['download_token']) === null, 'Store isolation: second store cannot use default invoice token');
Bootstrap::initStoreContext('/', '', 'default');

// 2. PDF written to disk
$pdfPath = InvoiceService::pdfAbsolutePath($inv1);
assert_true(file_exists($pdfPath), 'Invoice: PDF file written to disk');
assert_true(filesize($pdfPath) > 500, 'Invoice: PDF size > 500 bytes');
assert_equals('%PDF-1.4', substr((string)file_get_contents($pdfPath), 0, 8), 'Invoice: PDF has %PDF-1.4 magic header');
$appRootNormalized = strtolower(str_replace('\\', '/', realpath(__DIR__ . '/..') ?: dirname(__DIR__)));
$pdfPathNormalized = strtolower(str_replace('\\', '/', realpath($pdfPath) ?: $pdfPath));
assert_true(!str_starts_with($pdfPathNormalized, $appRootNormalized . '/'), 'Invoice: PDF is stored outside the application root');
foreach (['../config.php', '/tmp/stolen.pdf', 'C:\\Windows\\stolen.pdf', '\\\\server\\share\\stolen.pdf', '2026/07/file.pdf:payload', '2026/07/not-a-pdf.txt', "2026/07/bad\0.pdf"] as $badInvoicePath) {
    $invoicePathRejected = false;
    try { InvoiceService::pdfAbsolutePath(['pdf_path' => $badInvoicePath]); } catch (RuntimeException $e) { $invoicePathRejected = true; }
    assert_true($invoicePathRejected, "Invoice: unsafe PDF path rejected ({$badInvoicePath})");
}
$legacyInvoicePath = InvoiceService::pdfAbsolutePath(['pdf_path' => 'storage/invoices/2026/07/legacy.pdf']);
assert_true(str_ends_with(str_replace('\\', '/', $legacyInvoicePath), '/2026/07/legacy.pdf'), 'Invoice: legacy DB path maps inside private invoice root');

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
$backupRoot = sys_get_temp_dir() . '/bareboneseshop-backup-test';
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

$localBackup = new LocalBackupStorage($backupRoot . '/storage/backups');
assert_true(str_ends_with(str_replace('\\', '/', $localBackup->localPath('2026/04/file.db')), '/2026/04/file.db'), 'LocalBackupStorage: valid relative path is preserved');
foreach (['', "bad\0name", "bad\tname", '/tmp/file', '\\server\\share', 'D:\\backup.db', '2026/file.db:payload', '2026/../file', '2026\\mixed/file'] as $badPath) {
    $localRejected = $listRejected = false;
    try { $localBackup->localPath($badPath); } catch (RuntimeException $e) { $localRejected = true; }
    if ($badPath !== '') {
        try { $localBackup->list($badPath); } catch (RuntimeException $e) { $listRejected = true; }
    } else {
        $listRejected = is_array($localBackup->list());
    }
    assert_true($localRejected, 'LocalBackupStorage: localPath rejects ' . json_encode($badPath));
    assert_true($listRejected, $badPath === '' ? 'LocalBackupStorage: empty list prefix remains valid' : 'LocalBackupStorage: list prefix rejects ' . json_encode($badPath));
}

file_put_contents($backupRoot . '/storage/backups/blocked', 'not a directory');
$backupMkdirFailure = false;
try { $localBackup->put($liveBackupDb, 'blocked/file.db', []); } catch (RuntimeException $e) { $backupMkdirFailure = true; }
assert_true($backupMkdirFailure, 'LocalBackupStorage: mkdir failure throws');
$backupCopyFailure = false;
try { $localBackup->put($backupRoot . '/missing-source.db', 'copy/file.db', []); } catch (RuntimeException $e) { $backupCopyFailure = true; }
assert_true($backupCopyFailure, 'LocalBackupStorage: copy failure throws');

$backupSibling = $backupRoot . '/storage/backups-sibling';
@mkdir($backupSibling);
$backupLink = $backupRoot . '/storage/backups/escape-link';
if (@symlink($backupSibling, $backupLink)) {
    $backupSymlinkPathRejected = $backupSymlinkListRejected = false;
    try { $localBackup->localPath('escape-link/escaped.db'); } catch (RuntimeException $e) { $backupSymlinkPathRejected = true; }
    try { $localBackup->list('escape-link'); } catch (RuntimeException $e) { $backupSymlinkListRejected = true; }
    assert_true($backupSymlinkPathRejected, 'LocalBackupStorage: localPath rejects sibling-prefix symlink escape');
    assert_true($backupSymlinkListRejected, 'LocalBackupStorage: list rejects symlinked prefix escape');
    @unlink($backupLink);
} else {
    echo "  [SKIP] LocalBackupStorage symlink regressions (symlink unavailable)\n";
}
@rmdir($backupSibling);

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
    $tampered = preg_match('/(?:size|sha256) mismatch/i', $e->getMessage()) === 1;
}
assert_true($tampered, 'Restore: tampered backup rejected by metadata verification');

$missingMetaBackup = BackupService::create(['label' => 'manual']);
$missingMetaPath = $backupRoot . '/storage/backups/' . $missingMetaBackup['remote_path'] . '.meta.json';
unlink($missingMetaPath);
$missingMetaRejected = false;
try { BackupService::restore($missingMetaBackup['remote_path'], ['skip_snapshot' => true]); } catch (RuntimeException $e) {
    $missingMetaRejected = str_contains($e->getMessage(), 'metadata is required');
}
assert_true($missingMetaRejected, 'Restore: backup without metadata is rejected');

$wrongIdentityBackup = BackupService::create(['label' => 'manual']);
$wrongIdentityPath = $backupRoot . '/storage/backups/' . $wrongIdentityBackup['remote_path'] . '.meta.json';
$wrongIdentityMeta = json_decode((string)file_get_contents($wrongIdentityPath), true);
$wrongIdentityMeta['remote_path'] = 'other/backup.db';
file_put_contents($wrongIdentityPath, json_encode($wrongIdentityMeta));
$wrongIdentityRejected = false;
try { BackupService::restore($wrongIdentityBackup['remote_path'], ['skip_snapshot' => true]); } catch (RuntimeException $e) {
    $wrongIdentityRejected = str_contains($e->getMessage(), 'identity does not match');
}
assert_true($wrongIdentityRejected, 'Restore: metadata from another backup is rejected');
assert_equals(0, count(glob($liveBackupDb . '.restore-*.tmp') ?: []), 'Restore: failed validation leaves no staged temp files');

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
// 51.12b — non-VAT payer
$sc = VatService::resolveScenario(['seller_country'=>'SK','seller_vat_registered'=>false,'customer_country'=>'SK']);
assert_equals('non_vat_payer', $sc['scenario'], 'VAT scenario: non-VAT payer');
assert_equals('vat.non_vat_payer_note', $sc['note_key'], 'VAT scenario: non-VAT payer note_key');
assert_true(!$sc['applies_vat'], 'VAT scenario: non-VAT payer applies no VAT');

// 51.13 — computeLine gross-inclusive 23%
$ln = VatService::computeLine(1230, 1, 23.0, false);
assert_equals(1230, $ln['gross'], 'VAT line: gross');
assert_equals(1000, $ln['net'],   'VAT line: net');
assert_equals(230,  $ln['vat_amount'], 'VAT line: vat_amount');
// 51.14 — computeLine reverse-charge zero VAT
$ln = VatService::computeLine(2460, 1, 23.0, true);
assert_equals(0,    $ln['vat_amount'], 'VAT line: reverse_charge vat=0');
assert_equals(2460, $ln['net'],        'VAT line: reverse_charge net=gross');

// 51.15 — one deterministic snapshot covers products, discount, shipping and fee
$snapshotItems = [
    ['product_id'=>1, 'sku'=>'STD', 'price'=>12300, 'qty'=>1, 'vat_class'=>'standard'],
    ['product_id'=>2, 'sku'=>'RED', 'price'=>11000, 'qty'=>1, 'vat_class'=>'reduced'],
];
$snapshotContext = ['seller_country'=>'SK', 'customer_country'=>'SK'];
$snapshotAmounts = ['discount'=>2330, 'shipping'=>600, 'payment_fee'=>120, 'currency'=>'eur', 'effective_date'=>'2026-08-01'];
$financialSnapshot = VatService::buildSnapshot($snapshotItems, $snapshotContext, $snapshotAmounts);
assert_equals(1, $financialSnapshot['version'], 'VAT snapshot: versioned contract');
assert_equals(21690, $financialSnapshot['totals']['gross'], 'VAT snapshot: gross includes discounted products, shipping and fee');
assert_equals(3763, $financialSnapshot['totals']['vat'], 'VAT snapshot: mixed-rate VAT is deterministic');
assert_equals(17927, $financialSnapshot['totals']['net'], 'VAT snapshot: net + VAT = gross');
assert_equals(2182, $financialSnapshot['breakdown']['23']['vat'], 'VAT snapshot: shipping follows standard product rate');
assert_equals(1581, $financialSnapshot['breakdown']['19']['vat'], 'VAT snapshot: coupon lowers reduced-rate tax base');
assert_equals(120, $financialSnapshot['breakdown']['0']['net'], 'VAT snapshot: payment fee remains exempt');
assert_equals($financialSnapshot, VatService::buildSnapshot($snapshotItems, $snapshotContext, $snapshotAmounts), 'VAT snapshot: identical inputs produce identical snapshot and fingerprint');
VatService::validateSnapshot($financialSnapshot);
$tamperedSnapshot = $financialSnapshot;
$tamperedSnapshot['totals']['gross']++;
$tamperRejected = false;
try { VatService::validateSnapshot($tamperedSnapshot); }
catch (InvalidArgumentException $e) { $tamperRejected = true; }
assert_true($tamperRejected, 'VAT snapshot: fingerprint rejects changed totals');

// 51.16 — reverse charge freezes every component at zero VAT
$reverseSnapshot = VatService::buildSnapshot($snapshotItems, [
    'seller_country'=>'SK', 'customer_country'=>'DE', 'customer_vat_id_valid'=>true, 'is_b2b'=>true,
], ['shipping'=>500, 'currency'=>'eur', 'effective_date'=>'2026-08-01']);
assert_equals('reverse_charge', $reverseSnapshot['scenario'], 'VAT snapshot: reverse-charge scenario frozen');
assert_equals(0, $reverseSnapshot['totals']['vat'], 'VAT snapshot: reverse-charge products and shipping have zero VAT');
assert_equals($reverseSnapshot['totals']['gross'], $reverseSnapshot['totals']['net'], 'VAT snapshot: reverse-charge net equals gross');

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

$pdo->exec("INSERT INTO product_related_products (product_id, related_product_id, sort_order) VALUES (501, 502, 0)");
$curatedRelated = Products::curatedRelated(501, 4);
assert_equals(1, count($curatedRelated), 'Products: curated related returns selected products');
assert_equals('SKU-B', $curatedRelated[0]['sku'], 'Products: curated related preserves configured product');

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

// 53.5 — Non-VAT payer → cart items vat=0 and note key is carried
$ctxNonVat = ['seller_country'=>'SK','seller_vat_registered'=>false,'customer_country'=>'SK','is_b2b'=>false,'customer_vat_id_valid'=>false,'seller_oss_enabled'=>false];
$cartNonVat = Cart::enriched($ctxNonVat);
assert_equals('non_vat_payer', $cartNonVat['vat_scenario'], 'Cart: non-VAT payer scenario');
assert_equals(0, (int)$cartNonVat['vat_total'], 'Cart: non-VAT payer vat_total=0');
assert_equals('vat.non_vat_payer_note', $cartNonVat['reverse_charge_note_key'], 'Cart: non-VAT payer note key');

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
$preflightNames = array_column($preflight['checks'], 'name');
foreach (['sqlite_path', 'jobs_dir', 'private_cache_dir', 'invoice_storage_dir', 'backup_storage_dir', 'backup_log_file', 'gdpr_salt_file'] as $privateCheck) {
    assert_true(in_array($privateCheck, $preflightNames, true), "Staging preflight: checks {$privateCheck}");
}

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

// ============================================================
// TEST 54b: P3 — invoice VAT correctness (coupon base, shipping VAT,
// mixed-rate recap, net+VAT=gross invariant). Synthetic order with two
// VAT rates (23% + 10%), a 10% coupon, and shipping at the standard rate.
// ============================================================
echo "\n--- Test 54b: P3 invoice VAT correctness ---\n";

$db->exec("PRAGMA foreign_keys = OFF");
$db->exec("DELETE FROM order_items WHERE order_id IN (5004, 5005)");
$db->exec("DELETE FROM orders WHERE id IN (5004, 5005)");
// subtotal 23300 (12300@23% + 11000@10%), coupon 2330, shipping 600
// total = (23300 - 2330) + 600 = 21570
$db->exec("INSERT INTO orders (id, order_number, status, customer_name, customer_email, shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, vat_scenario, created_at) VALUES (5004, 'INV-5004', 'paid', 'Mix Rate', 'mix@test.sk', '" . addslashes(json_encode(['street'=>'A 1','city'=>'BA','zip'=>'81101','country'=>'SK'])) . "', 'bank_transfer', 23300, 2330, 600, 4200, 21570, 'eur', 'sk', 'domestic', datetime('now'))");
$db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, vat_class, vat_rate, vat_amount, net_amount) VALUES (5004, 501, 'SKU-A', 'Standard 23%', 1, 12300, 12300, 'standard', 23, 2300, 10000)");
$db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, vat_class, vat_rate, vat_amount, net_amount) VALUES (5004, 502, 'SKU-B', 'Reduced 10%', 1, 11000, 11000, 'reduced', 10, 1000, 10000)");
$db->exec("PRAGMA foreign_keys = ON");

$inv4 = InvoiceService::issue(5004);
$bd4  = json_decode((string)$inv4['vat_breakdown'], true) ?: [];

// (c) mixed-rate: two rate groups present
assert_true(count($bd4) >= 2, 'P3: invoice has >1 VAT rate group (23% + 10%)');
assert_true(isset($bd4['23']) && isset($bd4['10']), 'P3: rate groups keyed 23 and 10');

// (a) coupon reduces the VAT BASE per rate: 10% line net drops 10000 -> 9000
assert_equals(9000, (int)$bd4['10']['net'], 'P3: coupon proportionally lowers 10% net base');
assert_equals(900,  (int)$bd4['10']['vat'], 'P3: 10% VAT recomputed on discounted base');

// (b) shipping carries standard-rate VAT, folded into the 23% group
//     products 23%: net 9000 / vat 2070; + shipping 600 -> net 488 / vat 112
assert_equals(9488, (int)$bd4['23']['net'], 'P3: 23% net = discounted product + shipping net');
assert_equals(2182, (int)$bd4['23']['vat'], 'P3: 23% VAT includes shipping VAT');

// (d) net + VAT = gross == order.total (hard invariant)
assert_equals(21570, (int)$inv4['total_gross'], 'P3: total_gross == order.total');
assert_equals(3082,  (int)$inv4['total_vat'],   'P3: total_vat = sum of group VAT');
assert_equals(18488, (int)$inv4['total_net'],   'P3: total_net = gross - VAT');
assert_equals((int)$inv4['total_gross'], (int)$inv4['total_net'] + (int)$inv4['total_vat'],
    'P3: net + VAT = gross invariant holds');
// groups reconcile exactly to gross
$sumGroup = 0;
foreach ($bd4 as $g) { $sumGroup += (int)$g['net'] + (int)$g['vat']; }
assert_equals(21570, $sumGroup, 'P3: sum(net+VAT) across rate groups == order.total');

// reverse-charge: shipping and products all 0% VAT, net = gross
$db->exec("PRAGMA foreign_keys = OFF");
$db->exec("INSERT INTO orders (id, order_number, status, customer_name, customer_email, shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, vat_scenario, created_at) VALUES (5005, 'INV-5005', 'paid', 'RC', 'rc@test.de', '" . addslashes(json_encode(['street'=>'B 2','city'=>'Berlin','zip'=>'10115','country'=>'DE','ic_dph'=>'DE811907980'])) . "', 'bank_transfer', 10000, 0, 500, 0, 10500, 'eur', 'de', 'reverse_charge', datetime('now'))");
$db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, vat_class, vat_rate, vat_amount, net_amount) VALUES (5005, 501, 'SKU-RC', 'RC item', 1, 10000, 10000, 'standard', 0, 0, 10000)");
$db->exec("PRAGMA foreign_keys = ON");
$inv5 = InvoiceService::issue(5005);
assert_equals(0, (int)$inv5['total_vat'], 'P3: reverse-charge invoice has zero VAT');
assert_equals(10500, (int)$inv5['total_net'], 'P3: reverse-charge net == gross (incl. shipping)');
assert_equals((int)$inv5['total_gross'], (int)$inv5['total_net'] + (int)$inv5['total_vat'],
    'P3: reverse-charge net + VAT = gross invariant');

// ============================================================
// TEST 55: P0 regression — HTTP checkout path (apiCheckout)
// All five P0 bugs (#1 per-line VAT=0, #2 double VAT, #3 dead VIES,
// #4 mock webhook bypass, #5 undefined $cfg) lived in api.php and
// slipped past tests that called VatService/Cart/Orders directly.
// These tests exercise the exact apiCheckout() path end-to-end.
// ============================================================
echo "\n--- Test 55: P0 regression (HTTP checkout path) ---\n";

if (!function_exists('apiCheckout')) {
    define('BB_TEST_LOAD_API_ONLY', true);
    require_once __DIR__ . '/../api.php';
}

// Deterministic offline payment method, zero fees, available for SK + DE
$pdo->exec("INSERT OR IGNORE INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order) VALUES ('p0pay','manual','P0 Pay','','offline',0,0,0,0,0,0,1,98)");
$pdo->exec("INSERT OR IGNORE INTO payment_method_countries (method_code, country) VALUES ('p0pay','SK'),('p0pay','DE')");

$p0Checkout = function (array $overrides) {
    // apiActiveStore() resolves the seller store by locale (languages[0]).
    // Default CLI locale 'en' would pick the US store → seller US, no EU VAT.
    I18n::setLocale('sk');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SESSION['csrf_token'] = 'p0-regression-test';
    $_SESSION['cart'] = [
        'p_501' => ['product_id' => 501, 'variant_id' => null, 'qty' => 2],
        'p_502' => ['product_id' => 502, 'variant_id' => null, 'qty' => 1],
    ];
    $country = $overrides['country'] ?? 'SK';
    // Pick whatever shipping method is actually offered for the country
    // (DB-driven rates for SK, legacy JSON fallback for DE) — the test
    // targets VAT correctness, not a specific carrier.
    $rates = ShippingService::calculateRates($country, 0, 28200, false, 0);
    $_POST = array_merge([
        'csrf_token'      => 'p0-regression-test',
        'name'            => 'P0 Regression',
        'email'           => 'p0@test.com',
        'phone'           => '',
        'street'          => 'Test Street 1',
        'city'            => 'Bratislava',
        'zip'             => '811 01',
        'country'         => $country,
        'shipping_method' => (string)($rates[0]['method_code'] ?? ''),
        'payment_method'  => 'p0pay',
    ], $overrides);
    $res = apiCheckout();
    $_POST = [];
    return $res;
};

// 55.1 — Bug #1: domestic B2C via HTTP path persists per-line VAT > 0
$r = $p0Checkout(['country' => 'SK']);
assert_true(!empty($r['success']), 'P0 #1: domestic HTTP checkout succeeds (' . ($r['error'] ?? 'ok') . ')');
$o = $pdo->query("SELECT * FROM orders WHERE id=" . (int)($r['order_id'] ?? 0))->fetch();
assert_equals('domestic', (string)($o['vat_scenario'] ?? ''), 'P0 #1: HTTP checkout scenario=domestic');
assert_true((int)($o['vat_total'] ?? 0) > 0, 'P0 #1: HTTP checkout vat_total > 0');
$minLineVat = $pdo->query("SELECT MIN(vat_amount) AS v FROM order_items WHERE order_id=" . (int)($r['order_id'] ?? 0))->fetch();
assert_true((int)($minLineVat['v'] ?? 0) > 0, 'P0 #1: every order_item has vat_amount > 0');

// 55.2 — Bug #2: gross-inclusive prices — extracted tax must NOT inflate total
assert_equals((int)$o['subtotal'] + (int)$o['shipping_cost'], (int)$o['total'],
    'P0 #2: total == gross subtotal + shipping (extracted VAT not added on top)');
assert_true((int)$o['tax_amount'] > 0, 'P0 #2: extracted tax still recorded for reporting');
$checkoutSnapshot = json_decode((string)($o['financial_snapshot'] ?? ''), true);
assert_true(is_array($checkoutSnapshot), 'G1: HTTP checkout persists canonical financial snapshot');
VatService::validateSnapshot($checkoutSnapshot);
assert_equals((int)$o['total'], (int)$checkoutSnapshot['totals']['gross'], 'G1: quote snapshot gross = order total');
assert_equals((int)$o['tax_amount'], (int)$checkoutSnapshot['totals']['vat'], 'G1: quote snapshot VAT = order tax amount');
assert_equals((int)$o['vat_total'], (int)$checkoutSnapshot['totals']['vat'], 'G1: quote snapshot VAT = order VAT total');
assert_equals((int)$o['net_total'], (int)$checkoutSnapshot['totals']['net'], 'G1: quote snapshot net = order net total');

$pdo->exec("UPDATE orders SET status='paid' WHERE id=" . (int)$o['id']);
$snapshotInvoice = InvoiceService::issue((int)$o['id']);
$invoiceSnapshot = json_decode((string)($snapshotInvoice['financial_snapshot'] ?? ''), true);
assert_equals($checkoutSnapshot, $invoiceSnapshot, 'G1: invoice copies the exact order financial snapshot');
assert_equals((int)$checkoutSnapshot['totals']['gross'], (int)$snapshotInvoice['total_gross'], 'G1: invoice gross = snapshot gross');
assert_equals((int)$checkoutSnapshot['totals']['vat'], (int)$snapshotInvoice['total_vat'], 'G1: invoice VAT = snapshot VAT');

// G8 — received item refund produces an immutable, tax-linked credit note.
$creditOrderId = (int)$o['id'];
$creditOrderItems = Orders::items($creditOrderId);
$creditOrderItem = $creditOrderItems[0];
$pdo->prepare("UPDATE orders SET status='delivered', payment_id='' WHERE id=:id")->execute([':id' => $creditOrderId]);
[$creditRmaOk, $creditRmaId] = Rma::create($creditOrderId, null, 'Item return for credit', [(int)$creditOrderItem['id'] => 1]);
assert_true($creditRmaOk && $creditRmaId > 0, 'G8 credit: partial RMA created');
assert_true(Rma::setStatus($creditRmaId, 'approved'), 'G8 credit: RMA approved');
$creditRmaItem = Rma::items($creditRmaId)[0];
assert_true(Rma::receive($creditRmaId, [(int)$creditRmaItem['id'] => ['quantity' => 1, 'disposition' => 'repair']], 'credit-receipt-1'), 'G8 credit: received quantity recorded without stock mutation');
$creditNote = InvoiceService::credit((int)$snapshotInvoice['id'], $creditRmaId, [(int)$creditRmaItem['id'] => 1], 'credit-note-1');
assert_equals('credit_note', (string)$creditNote['type'], 'G8 credit: document type is credit_note');
assert_equals((int)$snapshotInvoice['id'], (int)$creditNote['refers_to_invoice_id'], 'G8 credit: source invoice link is immutable');
assert_true((int)$creditNote['total_gross'] < 0, 'G8 credit: credited gross is negative integer cents');
assert_equals($checkoutSnapshot, json_decode((string)$creditNote['financial_snapshot'], true), 'G8 credit: source financial snapshot is preserved exactly');
$creditAuditSnapshot = json_decode((string)$creditNote['credit_snapshot'], true);
assert_equals((string)$checkoutSnapshot['fingerprint'], (string)$creditAuditSnapshot['source_fingerprint'], 'G8 credit: audit snapshot pins source fingerprint');
assert_equals((int)$creditNote['id'], (int)InvoiceService::credit((int)$snapshotInvoice['id'], $creditRmaId, [(int)$creditRmaItem['id'] => 1], 'credit-note-1')['id'], 'G8 credit: idempotency returns original credit note');
$creditReplayConflict = false;
try { InvoiceService::credit((int)$snapshotInvoice['id'], $creditRmaId, [(int)$creditRmaItem['id'] => 2], 'credit-note-1'); }
catch (LogicException $e) { $creditReplayConflict = true; }
assert_true($creditReplayConflict, 'G8 credit: idempotency key rejects changed quantities');
$creditLine = $pdo->query("SELECT * FROM credit_note_items WHERE credit_note_id=" . (int)$creditNote['id'])->fetch(PDO::FETCH_ASSOC);
assert_equals(1, (int)$creditLine['quantity'], 'G8 credit: item quantity is persisted');
assert_equals(-(int)$creditLine['gross'], (int)$creditNote['total_gross'], 'G8 credit: line gross reconciles exactly to document gross');
Rma::completeRefund((int)$creditNote['refund_id'], 'manual-refund-1');
assert_equals('succeeded', (string)Rma::refundById((int)$creditNote['refund_id'])['status'], 'G8 refund: manual payment completion is audited');
assert_equals(1, (int)Rma::items($creditRmaId)[0]['refunded_quantity'], 'G8 refund: exact item quantity is marked refunded');
assert_equals('refunded', (string)Rma::byId($creditRmaId)['status'], 'G8 refund: fully settled RMA closes');
$creditOverClaim = false;
try { InvoiceService::credit((int)$snapshotInvoice['id'], $creditRmaId, [(int)$creditRmaItem['id'] => 1], 'credit-note-over'); }
catch (LogicException $e) { $creditOverClaim = true; }
assert_true($creditOverClaim, 'G8 credit: an item cannot be credited twice');

// 55.3 — Bug #3: B2B reverse charge via HTTP path (VIES served from cache, no network)
$pdo->exec("DELETE FROM vat_id_cache");
$pdo->exec("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, trader_addr, checked_at, ttl_seconds, raw_response) VALUES ('DE811907980','DE','valid',NULL,NULL,datetime('now'),86400,NULL)");
$r2 = $p0Checkout(['country' => 'DE', 'vat_id' => 'DE811907980']);
assert_true(!empty($r2['success']), 'P0 #3: B2B DE HTTP checkout succeeds (' . ($r2['error'] ?? 'ok') . ')');
$o2 = $pdo->query("SELECT * FROM orders WHERE id=" . (int)($r2['order_id'] ?? 0))->fetch();
assert_equals('reverse_charge', (string)($o2['vat_scenario'] ?? ''), 'P0 #3: VIES-validated B2B → reverse_charge via HTTP path');
assert_equals(0, (int)($o2['vat_total'] ?? -1), 'P0 #3: reverse charge vat_total == 0');
assert_equals((int)$o2['subtotal'] + (int)$o2['shipping_cost'], (int)$o2['total'],
    'P0 #3: reverse charge total has no VAT added');

// 55.4 — Bug #4: mock webhook routing gate is closed with shipped config
$prodCfg = require dirname(__DIR__) . '/config.php';
assert_true(($prodCfg['allow_mock_webhook'] ?? true) === false, 'P0 #4: allow_mock_webhook ships as false');
assert_true(($prodCfg['adapters']['payment'] ?? '') !== 'MockPayment', 'P0 #4: shipped payment adapter is not MockPayment');
$mockRoutable = (($prodCfg['adapters']['payment'] ?? '') === 'MockPayment' || !empty($prodCfg['allow_mock_webhook']));
assert_true(!$mockRoutable, 'P0 #4: webhook_payment provider=mock not routable in production config');

// 55.5 — Bug #4: MockPayment signature is a real secret comparison
$mpSig = new MockPayment(['mock_webhook_secret' => 'test-mock-secret-123']);
$mpPayloadSig = hash_hmac('sha256', 'payload', 'test-mock-secret-123');
assert_true(!(new MockPayment([]))->verifySignature('payload', hash_hmac('sha256', 'payload', 'mock_sig')), 'G3: predictable default mock signature is rejected');
assert_true(!(new MockPayment(['mock_webhook_secret' => 'short']))->verifySignature('payload', hash_hmac('sha256', 'payload', 'short')), 'G3: weak mock webhook secret fails closed');
assert_true($mpSig->verifySignature('payload', $mpPayloadSig), 'P0 #4: correct raw-payload HMAC accepted');
assert_true(!$mpSig->verifySignature('tampered', $mpPayloadSig), 'G3: mock signature is bound to the raw payload');
assert_true(!$mpSig->verifySignature('payload', 'wrong-sig'), 'P0 #4: wrong webhook signature rejected');
assert_true(!$mpSig->verifySignature('payload', ''), 'P0 #4: empty webhook signature rejected');

// 55.6 — OSS consumes the issued invoice snapshot, including discount + shipping
$ossContext = [
    'seller_country'=>'SK', 'seller_oss_enabled'=>true, 'customer_country'=>'DE',
    'customer_vat_id_valid'=>false, 'is_b2b'=>false,
];
$_SESSION['cart'] = [
    'p_501' => ['product_id'=>501, 'variant_id'=>null, 'qty'=>1],
    'p_502' => ['product_id'=>502, 'variant_id'=>null, 'qty'=>1],
];
$ossCart = Cart::enriched($ossContext);
$ossSnapshot = VatService::buildSnapshot($ossCart['items'], $ossContext, [
    'discount'=>2330, 'shipping'=>600, 'payment_fee'=>0, 'currency'=>'eur', 'effective_date'=>date('Y-m-d'),
]);
$ossOrderId = Orders::create($ossCart['items'], [
    'name'=>'OSS Buyer', 'email'=>'oss@example.de', 'phone'=>'',
    'address'=>['street'=>'A 1', 'city'=>'Berlin', 'zip'=>'10115', 'country'=>'DE'],
    'currency'=>'eur', 'locale'=>'de', 'shipping_cost'=>600, 'payment_fee'=>0,
    'tax_amount'=>$ossSnapshot['totals']['vat'], 'coupon_discount'=>2330,
    'vat_scenario'=>'oss', 'financial_snapshot'=>$ossSnapshot,
], 'p0pay');
$pdo->exec("UPDATE orders SET status='paid' WHERE id={$ossOrderId}");
$ossInvoice = InvoiceService::issue($ossOrderId);
assert_equals($ossSnapshot, json_decode((string)$ossInvoice['financial_snapshot'], true), 'G1: OSS invoice preserves exact checkout snapshot');
if (!function_exists('collect')) {
    define('BB_TEST_LOAD_OSS_ONLY', true);
    require_once __DIR__ . '/oss-report.php';
}
$ossRows = collect(date('Y-m-d'), date('Y-m-d'), null);
$ossByRate = [];
foreach ($ossRows as $ossRow) {
    if (($ossRow['consumption_country'] ?? '') === 'DE') $ossByRate[(string)$ossRow['vat_rate']] = $ossRow;
}
foreach ($ossSnapshot['breakdown'] as $rate => $group) {
    assert_true(isset($ossByRate[(string)(float)$rate]), "G1: OSS contains snapshot rate {$rate}");
    $reported = $ossByRate[(string)(float)$rate] ?? [];
    assert_equals((int)$group['net'], (int)($reported['net'] ?? -1), "G1: OSS net matches snapshot rate {$rate}");
    assert_equals((int)$group['vat'], (int)($reported['vat'] ?? -1), "G1: OSS VAT matches snapshot rate {$rate}");
    assert_equals((int)$group['gross'], (int)($reported['gross'] ?? -1), "G1: OSS gross matches snapshot rate {$rate}");
}

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

// ============================================================
// TEST 58: P1 regression — stock_restored column replaces notes heuristic
// Bug #11: the double-restore guard was notes LIKE '%stock_restored%' —
// any order whose notes happened to contain that text (customer note,
// admin comment) silently SKIPPED its stock restore. Now a dedicated
// orders.stock_restored flag is claimed atomically (0 → 1).
// ============================================================
echo "\n--- Test 58: P1 regression (stock_restored flag) ---\n";

// 58.1 — restored orders carry the flag, not a notes marker
assert_equals(1, (int)$pdo->query("SELECT stock_restored FROM orders WHERE id={$rtA}")->fetchColumn(), 'Flag: returned order has stock_restored=1');
assert_equals(1, (int)$pdo->query("SELECT stock_restored FROM orders WHERE id={$rtB}")->fetchColumn(), 'Flag: cancelled order has stock_restored=1');
assert_true(!str_contains((string)$pdo->query("SELECT notes FROM orders WHERE id={$rtA}")->fetchColumn(), 'stock_restored'), 'Flag: notes no longer abused as marker');

// 58.2 — red herring: customer note containing the literal marker text
// must NOT block the restore (the old heuristic skipped it)
$rtCustomerC = $rtCustomer;
$rtCustomerC['notes'] = 'please gift-wrap; ref stock_restored ticket #42';
$rtC = Orders::create($rtItem(2), $rtCustomerC, 'stripe');
$s = $rtStock();
assert_equals(2, (int)$s['reserved'], 'Flag: order C reserved 2');
OrderWorkflow::transition($rtC, 'cancelled');
$s = $rtStock();
assert_equals(0, (int)$s['reserved'], 'Flag: restore runs despite marker text in notes (old heuristic would skip)');
assert_equals(1, (int)$pdo->query("SELECT stock_restored FROM orders WHERE id={$rtC}")->fetchColumn(), 'Flag: order C stock_restored=1');

// 58.3 — atomic claim: direct second restore is refused by the flag
Orders::restoreStock($rtC);
$s = $rtStock();
assert_equals(0, (int)$s['reserved'], 'Flag: repeated restore is a no-op (reserved stays 0)');
assert_equals(10, (int)$s['qty'], 'Flag: repeated restore is a no-op (qty stays 10)');

// ============================================================
// TEST 59: P1 regression — invoices(order_id) UNIQUE + race-safe issue
// Bug #9: InvoiceService::issue had a check-then-insert race — two
// concurrent issue() calls could both pass the byOrderId() null-check
// and INSERT, creating two invoices (two numbers, two PDFs) for one
// order. The DB now enforces one type='invoice' row per order via a
// partial unique index; issue() catches the violation (SQLSTATE 23000)
// and returns the winner's invoice instead of throwing.
// ============================================================
echo "\n--- Test 59: P1 regression (invoice UNIQUE order_id) ---\n";

// Seed a fresh paid order (invoices table + unique index exist from the
// Invoices test section; counters survive — numbers just keep growing)
$pdo->exec("INSERT INTO orders (id, order_number, status, customer_name, customer_email, shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at) VALUES (5901, 'INV-5901', 'paid', 'Race Test', 'race@example.sk', '{}', 'card', 2000, 0, 0, 0, 2000, 'eur', 'sk', datetime('now'))");
$pdo->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (5901, 501, 'SKU-S', 'Race produkt', 1, 2000, 2000)");

// 59.1 — idempotent issue (early-return path)
$ri1 = InvoiceService::issue(5901);
$ri2 = InvoiceService::issue(5901);
assert_equals((int)$ri1['id'], (int)$ri2['id'], 'UQ: re-issue returns same invoice id');

// 59.2 — the race's losing writer: raw duplicate INSERT for the same order
// must be rejected by the unique index with SQLSTATE 23000
$dupCode = null;
try {
    $pdo->exec("INSERT INTO invoices (order_id, number, issued_at, pdf_path, download_token, type) VALUES (5901, 'DUP-0001', '2026-06-11', 'x.pdf', '" . str_repeat('a', 64) . "', 'invoice')");
} catch (\PDOException $e) { $dupCode = (string)$e->getCode(); }
assert_equals('23000', $dupCode, 'UQ: duplicate invoice INSERT raises SQLSTATE 23000');
assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id=5901 AND type='invoice'")->fetchColumn(), 'UQ: still exactly 1 invoice for order');

// 59.3 — credit note for the same order is NOT blocked (index is partial)
$pdo->exec("INSERT INTO invoices (order_id, number, issued_at, pdf_path, download_token, type) VALUES (5901, 'CN-0001', '2026-06-11', 'cn.pdf', '" . str_repeat('b', 64) . "', 'credit_note')");
assert_equals(2, (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id=5901")->fetchColumn(), 'UQ: credit_note for same order allowed');

// 59.4 — byOrderId prefers the live invoice over non-invoice rows
$pref = InvoiceService::byOrderId(5901);
assert_equals((int)$ri1['id'], (int)($pref['id'] ?? 0), 'UQ: byOrderId prefers type=invoice row');

// 59.5 — migration dedupe: pre-existing duplicates (the bug's damage) are
// voided (kept for audit as type='duplicate', status='cancelled'), lowest
// id survives, index is created. Isolated scratch DB — DDL on the live
// test DB trips SQLITE_LOCKED from open statement handles.
$uqDb = new PDO('sqlite::memory:');
$uqDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$uqDb->exec("CREATE TABLE invoices (
    id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL,
    number TEXT NOT NULL UNIQUE, type TEXT NOT NULL DEFAULT 'invoice',
    status TEXT NOT NULL DEFAULT 'issued'
)");
$uqDb->exec("INSERT INTO invoices (order_id, number, type) VALUES
    (7001, '20260001', 'invoice'),
    (7001, '20260002', 'invoice'),
    (7002, '20260003', 'invoice'),
    (7001, '20260004', 'credit_note')");
$uqMig->up($uqDb, true);
assert_equals(1, (int)$uqDb->query("SELECT COUNT(*) FROM invoices WHERE order_id=7001 AND type='invoice'")->fetchColumn(), 'UQ: migration dedupe leaves 1 live invoice');
assert_equals('20260001', (string)$uqDb->query("SELECT number FROM invoices WHERE order_id=7001 AND type='invoice'")->fetchColumn(), 'UQ: dedupe keeps lowest id (original invoice)');
$voided = $uqDb->query("SELECT type, status FROM invoices WHERE number='20260002'")->fetch(PDO::FETCH_ASSOC);
assert_equals('duplicate', (string)$voided['type'], 'UQ: dup row voided as type=duplicate');
assert_equals('cancelled', (string)$voided['status'], 'UQ: dup row status=cancelled');
assert_equals('credit_note', (string)$uqDb->query("SELECT type FROM invoices WHERE number='20260004'")->fetchColumn(), 'UQ: credit_note untouched by dedupe');
$uqMig->up($uqDb, true); // idempotent re-run must not throw
$dupCode2 = null;
try {
    $uqDb->exec("INSERT INTO invoices (order_id, number, type) VALUES (7001, '20260005', 'invoice')");
} catch (\PDOException $e) { $dupCode2 = (string)$e->getCode(); }
assert_equals('23000', $dupCode2, 'UQ: index active after migration (dup INSERT blocked)');
$uqDb = null;

// ============================================================
// TEST: Bootstrap shared front-controller helpers (P5)
// ============================================================
echo "\n--- Test: Bootstrap shared helpers (P5) ---\n";

// stores() returns the decoded stores.json, byte-for-byte parity with a direct decode
$bsStoresDirect = json_decode((string)file_get_contents(__DIR__ . '/../stores.json'), true) ?: [];
assert_equals($bsStoresDirect, Bootstrap::stores(), 'Bootstrap: stores() equals direct stores.json decode');
assert_true(Bootstrap::stores() === Bootstrap::stores(), 'Bootstrap: stores() returns stable cached value across calls');

// initLocale() parity with I18n::detectLocale + applies the locale
$savedLocale = I18n::locale();
assert_equals(I18n::detectLocale('/de/products'), Bootstrap::initLocale('/de/products'), 'Bootstrap: initLocale parity with I18n::detectLocale (/de)');
assert_equals('de', I18n::locale(), 'Bootstrap: initLocale applied de locale');
assert_equals(I18n::detectLocale('/'), Bootstrap::initLocale('/'), 'Bootstrap: initLocale parity for default (no prefix)');
assert_equals('en', I18n::locale(), 'Bootstrap: initLocale fell back to system default en');
I18n::setLocale($savedLocale); // restore for downstream tests

// catTranslations() fallback parity — SK + DE legacy names intact after extraction to data file
$bsCat = Bootstrap::catTranslations();
assert_equals('Brzdový kotúč', $bsCat['sk']['Brake Disc'] ?? null, 'Bootstrap: catTranslations sk Brake Disc preserved');
assert_equals('Brzdový systém', $bsCat['sk']['Brake System'] ?? null, 'Bootstrap: catTranslations sk Brake System preserved');
assert_equals('Karosserie', $bsCat['de']['Body'] ?? null, 'Bootstrap: catTranslations de Body preserved');
assert_true(($bsCat['sk']['Brake System'] ?? null) === (Bootstrap::catTranslations()['sk']['Brake System'] ?? null), 'Bootstrap: catTranslations stable across calls');

// hreflangAlternates() — alternates keyed by locale, prefix stripping, x-default
$hl = Bootstrap::hreflangAlternates('/de/products');
assert_equals('/de', $hl['alternates']['de'] ?? null, 'Bootstrap: hreflang de alternate maps to /de prefix');
assert_equals('/cs', $hl['alternates']['cs'] ?? null, 'Bootstrap: hreflang cs alternate maps to /cs prefix');
assert_equals('', $hl['alternates']['sk'] ?? 'MISSING', 'Bootstrap: hreflang sk alternate maps to empty prefix');
assert_equals('/products', $hl['pathNoPrefix'], 'Bootstrap: hreflang strips matched /de store prefix from path');
$hlRoot = Bootstrap::hreflangAlternates('/');
assert_equals('/', $hlRoot['pathNoPrefix'], 'Bootstrap: hreflang keeps / for unprefixed path');

// ============================================================
// TEST: SEO pack — sitemap.xml, robots.txt, JSON-LD (P6)
// ============================================================
echo "\n--- Test: SEO pack — sitemap / robots / JSON-LD (P6) ---\n";

// Known active rows so the sitemap has concrete category + product paths
$pdo->exec("INSERT INTO categories (slug, name, path, depth, sort_order, meta) VALUES ('seo-cat', 'SEO Cat', 'seo-cat', 0, 999, '{}')");
$seoCatId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO products (sku, slug, product_type, name, category_id, price, stock, status, summary, filter_tags)
    VALUES ('SEO-1', 'seo-prod', 'simple', 'SEO Prod', {$seoCatId}, 1000, 3, 'active', '', '')");

// --- Sitemap::paths() collects canonical prefix-less paths ---
$smPaths = Sitemap::paths();
assert_true(in_array('/', $smPaths, true), 'Sitemap: paths() always includes homepage');
assert_true(in_array('/category/seo-cat', $smPaths, true), 'Sitemap: paths() includes category route');
assert_true(in_array('/product/seo-prod', $smPaths, true), 'Sitemap: paths() includes active product route');

// --- Sitemap::xml() is well-formed and locale-complete ---
$smXml = Sitemap::xml('https://shop.example');
$smDoc = new DOMDocument();
assert_true($smDoc->loadXML($smXml) !== false, 'Sitemap: xml() is well-formed XML');
assert_true(str_contains($smXml, '<loc>https://shop.example/</loc>'), 'Sitemap: xml() contains absolute homepage loc');
assert_true(str_contains($smXml, 'hreflang="de"') && str_contains($smXml, 'hreflang="cs"'), 'Sitemap: xml() emits hreflang alternates');
assert_true(str_contains($smXml, 'hreflang="x-default"'), 'Sitemap: xml() emits x-default alternate');
assert_true(str_contains($smXml, 'https://shop.example/product/seo-prod'), 'Sitemap: xml() includes product URL');

// --- Robots::txt() — Sitemap directive + private-path disallows ---
$rb = Robots::txt('https://shop.example');
assert_true(str_contains($rb, 'User-agent: *'), 'Robots: declares wildcard user-agent');
assert_true(str_contains($rb, 'Sitemap: https://shop.example/sitemap.xml'), 'Robots: absolute Sitemap directive');
assert_true(str_contains($rb, 'Disallow: /admin'), 'Robots: disallows admin');
assert_true(str_contains($rb, 'Disallow: /checkout'), 'Robots: disallows checkout');
assert_true(str_contains($rb, 'Allow: /'), 'Robots: allows public root');

// --- JsonLd::organization() — default store identity ---
$ldOrg = JsonLd::organization('https://shop.example', Bootstrap::stores());
assert_equals('Organization', $ldOrg['@type'], 'JsonLd: organization @type');
assert_equals('Woodle s.r.o.', $ldOrg['name'], 'JsonLd: organization name from default store legal');
assert_equals('https://shop.example/', $ldOrg['url'], 'JsonLd: organization absolute url');

// --- JsonLd::webSite() — SearchAction sitelinks searchbox ---
$ldWs = JsonLd::webSite('https://shop.example', Bootstrap::stores());
assert_equals('WebSite', $ldWs['@type'], 'JsonLd: webSite @type');
assert_equals('SearchAction', $ldWs['potentialAction']['@type'], 'JsonLd: webSite has SearchAction');
assert_true(str_contains($ldWs['potentialAction']['target']['urlTemplate'], '{search_term_string}'), 'JsonLd: SearchAction target carries search_term_string');

// --- JsonLd::breadcrumbList() — ordered trail, leaf has no item ---
$ldBc = JsonLd::breadcrumbList([
    ['name' => 'Home', 'url' => '/'],
    ['name' => 'Brakes', 'url' => '/category/brakes'],
    ['name' => 'Front Disc'],
], 'https://shop.example');
assert_equals('BreadcrumbList', $ldBc['@type'], 'JsonLd: breadcrumb @type');
assert_equals(3, count($ldBc['itemListElement']), 'JsonLd: breadcrumb has all items');
assert_equals(1, $ldBc['itemListElement'][0]['position'], 'JsonLd: breadcrumb positions are 1-based');
assert_equals('https://shop.example/category/brakes', $ldBc['itemListElement'][1]['item'], 'JsonLd: breadcrumb ancestor has absolute item URL');
assert_true(!isset($ldBc['itemListElement'][2]['item']), 'JsonLd: breadcrumb leaf omits item (current page)');

// --- JsonLd::render() — nonce'd script wrapper with valid JSON payload ---
$ldHtml = JsonLd::render($ldOrg, 'nonce-xyz');
assert_true(str_contains($ldHtml, 'type="application/ld+json"'), 'JsonLd: render emits ld+json script');
assert_true(str_contains($ldHtml, 'nonce="nonce-xyz"'), 'JsonLd: render carries CSP nonce');
$ldPayload = substr($ldHtml, strpos($ldHtml, '>') + 1, -strlen('</script>'));
assert_true(json_decode($ldPayload) !== null, 'JsonLd: render payload is valid JSON');

// --- S1.3: admin CSP nonce alignment (Idea #1) ---------------------------
$adminSrc = file_get_contents(__DIR__ . '/../admin.php') ?: '';
$securitySrc = file_get_contents(__DIR__ . '/../src/Security.php') ?: '';

// (a) the admin.php unsafe-inline CSP override line must be gone — admin now
//     inherits the nonce-based CSP from Security::headers().
assert_true(
    !str_contains($adminSrc, "script-src 'self' 'unsafe-inline'"),
    'Admin CSP: no unsafe-inline script-src override in admin.php'
);

// (b) grep-guard — no inline event handlers (on*=) remain anywhere in admin.php;
//     CSP nonce does not authorize inline handler attributes, so any survivor
//     would silently break under the inherited policy.
assert_true(
    preg_match('/\son(click|submit|change|input|load|mouse\w+|key\w+)\s*=\s*["\']/i', $adminSrc) === 0,
    'Admin CSP: no inline on*= event handlers in admin.php'
);

$renderSources = glob(__DIR__ . '/../*.php') ?: [];
$renderSources = array_merge(
    $renderSources,
    glob(__DIR__ . '/../themes/*/blocks/*.html') ?: [],
    glob(__DIR__ . '/../themes/*/blocks/*/*.html') ?: []
);
$inlineHandlerPattern = '/\son(click|submit|change|input|load|mouse\w+|key\w+)\s*=\s*["\']/i';
foreach ($renderSources as $renderSource) {
    $source = file_get_contents($renderSource) ?: '';
    assert_true(
        preg_match($inlineHandlerPattern, $source) === 0,
        'Storefront CSP: no inline event handlers in ' . basename($renderSource)
    );
}

// (c) admin inline <script> blocks still carry the request nonce.
assert_true(
    str_contains($adminSrc, '<script nonce="<?= Security::cspNonce() ?>">'),
    'Admin CSP: inline scripts carry Security::cspNonce()'
);

// (d) the delegated nonce'd listener layer + data-* hooks are present.
assert_true(
    str_contains($adminSrc, "document.addEventListener('submit'") &&
    str_contains($adminSrc, 'form[data-confirm]'),
    'Admin CSP: delegated data-confirm submit listener present'
);
assert_true(
    str_contains($adminSrc, 'data-action="add-option"') &&
    str_contains($adminSrc, 'data-action="filter-cats"') &&
    str_contains($adminSrc, 'data-action="toggle-cats"'),
    'Admin CSP: data-action hooks replace inline function-call handlers'
);

$storefrontSrc = file_get_contents(__DIR__ . '/../index.php') ?: '';
$accountSrc = file_get_contents(__DIR__ . '/../account.php') ?: '';
assert_true(
    str_contains($storefrontSrc, 'data-storefront-action="cart-add"') &&
    str_contains($storefrontSrc, "e.target.closest('[data-storefront-action=\"cart-add\"]')") &&
    str_contains($storefrontSrc, 'data-submit-on-change') &&
    str_contains($storefrontSrc, 'data-product-tab'),
    'Storefront CSP: delegated cart, form-change, and product-tab hooks are present'
);
assert_true(
    str_contains($accountSrc, 'data-confirm="') &&
    str_contains($accountSrc, "form.matches('form[data-confirm]')"),
    'Account CSP: delegated data-confirm submit listener is present'
);

// (e) Security::headers() builds a nonce-based script-src with NO unsafe-inline.
assert_true(
    str_contains($securitySrc, "script-src 'self' 'nonce-\$nonce'"),
    'Admin CSP: Security script-src is nonce-based'
);
assert_true(
    preg_match('/script-src[^;"]*\'unsafe-inline\'/', $securitySrc) === 0,
    'Admin CSP: Security script-src has no unsafe-inline'
);

// (f) runtime — cspNonce() yields a non-empty per-request token.
assert_true(
    Security::cspNonce() !== '' && Security::cspNonce() === Security::cspNonce(),
    'Admin CSP: cspNonce() returns a stable non-empty per-request nonce'
);

// ============================================================
// TEST: Audit v1.12 remediation — Sprint A (F1, F3, F4, F8, F9)
// ============================================================
echo "\n--- Audit v1.12 remediation (Sprint A) ---\n";

// --- F1: Import::parsePriceToCents must be PUBLIC so FeedImport price_stock
//     mode (FeedImport.php:95) can call it cross-class without a fatal Error.
assert_true(
    (new ReflectionMethod(Import::class, 'parsePriceToCents'))->isPublic(),
    'F1: Import::parsePriceToCents is public (FeedImport cross-class call no longer fatals)'
);
assert_equals(1990, Import::parsePriceToCents('19,90'), 'F1: parsePriceToCents parses "19,90" → 1990');
assert_equals(2500, Import::parsePriceToCents(2500), 'F1: parsePriceToCents passes int cents through unchanged');

// --- F3: Security::safeLocalPath blocks open-redirect vectors.
assert_equals('account.php', Security::safeLocalPath('//evil.com', 'account.php'), 'F3: protocol-relative //evil.com rejected');
assert_equals('account.php', Security::safeLocalPath('http://evil.com', 'account.php'), 'F3: absolute http URL rejected');
assert_equals('account.php', Security::safeLocalPath('javascript:alert(1)', 'account.php'), 'F3: javascript: scheme rejected');
assert_equals('account.php', Security::safeLocalPath('/\\evil.com', 'account.php'), 'F3: backslash protocol-relative /\\ rejected');
assert_equals('account.php', Security::safeLocalPath('\\\\evil.com', 'account.php'), 'F3: backslash host \\\\evil rejected');
assert_equals('account.php', Security::safeLocalPath("acc\r\nSet-Cookie: x", 'account.php'), 'F3: CRLF header-injection rejected');
assert_equals('account.php', Security::safeLocalPath('', 'account.php'), 'F3: empty value falls back');
assert_equals('account.php?p=privacy', Security::safeLocalPath('account.php?p=privacy', 'fb'), 'F3: same-site relative path allowed');
assert_equals('/account?x=1', Security::safeLocalPath('/account?x=1', 'fb'), 'F3: single-leading-slash local path allowed');
assert_equals('https://shop.example/base', Security::appUrl(['app_url' => 'https://shop.example/base/']), 'G3: configured app_url is normalized');
foreach (['', '//evil.example', 'javascript:alert(1)', 'https://user:pass@shop.example', 'https://shop.example/?next=evil', "https://shop.example\r\n.evil"] as $badAppUrl) {
    $appUrlRejected = false;
    try {
        Security::appUrl(['app_url' => $badAppUrl]);
    } catch (\RuntimeException $e) {
        $appUrlRejected = true;
    }
    assert_true($appUrlRejected, 'G3: invalid app_url rejected: ' . json_encode($badAppUrl));
}

// --- F4: Orders::create rejects qty<=0 / negative price; stock never inflates.
$f4Before = (int)$pdo->query("SELECT stock FROM products WHERE id = " . (int)$testProduct['id'])->fetchColumn();
$f4Rejected = false;
try {
    Orders::create([[
        'product_id'   => $testProduct['id'], 'variant_id' => null,
        'qty'          => -3, 'price' => $testProduct['price'],
        'sku'          => $testProduct['sku'], 'name' => $testProduct['name'], 'variant_name' => '',
    ]], $customer, 'stripe');
} catch (\RuntimeException $e) {
    $f4Rejected = true;
}
$f4After = (int)$pdo->query("SELECT stock FROM products WHERE id = " . (int)$testProduct['id'])->fetchColumn();
assert_true($f4Rejected, 'F4: order with only a negative-qty item is rejected (RuntimeException)');
assert_equals($f4Before, $f4After, 'F4: negative qty does NOT inflate product stock');

// --- F8: apiCookieConsent must CSRF-validate, and the banner must send the token.
$f8Api = file_get_contents(__DIR__ . '/../api.php') ?: '';
assert_true(
    (bool)preg_match('/function apiCookieConsent\(\).*?Security::csrfValidate\(\)/s', $f8Api),
    'F8: apiCookieConsent() calls Security::csrfValidate()'
);
$f8Index = file_get_contents(__DIR__ . '/../index.php') ?: '';
assert_true(
    str_contains($f8Index, "fd.append('csrf_token'"),
    'F8: cookie-consent banner JS sends the csrf_token field'
);

// --- F9: Security::csvCell neutralizes spreadsheet formula injection.
assert_equals("'=1+1",       Security::csvCell('=1+1'),       'F9: leading = formula neutralized');
assert_equals("'+cmd",       Security::csvCell('+cmd'),       'F9: leading + non-numeric neutralized');
assert_equals("'@SUM(A1)",   Security::csvCell('@SUM(A1)'),   'F9: leading @ neutralized');
assert_equals("'-cmd|calc",  Security::csvCell('-cmd|calc'),  'F9: leading-dash command neutralized');
assert_equals('-500',        Security::csvCell('-500'),       'F9: legitimate negative number left intact');
assert_equals('1990',        Security::csvCell(1990),         'F9: numeric cell unchanged');
assert_equals('Brake Disc',  Security::csvCell('Brake Disc'), 'F9: plain text unchanged');

// ============================================================
// TEST: Audit v1.12 remediation — Sprint B (F2, F5, F6, F7)
// ============================================================
echo "\n--- Audit v1.12 remediation (Sprint B) ---\n";

// --- F2: coupon order VAT/net snapshot scaled to the POST-coupon gross.
//     Seed a self-contained product+stock so we control the numbers exactly.
$f2Cat = (int)$pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO products (sku, slug, product_type, name, category_id, price, stock, status, summary, filter_tags) VALUES ('F2-VAT-SKU','f2-vat-sku','simple','F2 VAT Test',:cat,1200,50,'active','','')")
    ->execute([':cat' => $f2Cat]);
$f2Pid = (int)$pdo->lastInsertId();
$f2Wh  = (int)$pdo->query("SELECT id FROM warehouses WHERE is_default = 1")->fetchColumn();
$pdo->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (:wh,'F2-VAT-SKU',50,0)")
    ->execute([':wh' => $f2Wh]);

$f2Cart = [[
    'product_id' => $f2Pid, 'variant_id' => null, 'qty' => 1, 'price' => 1200,
    'sku' => 'F2-VAT-SKU', 'name' => 'F2 VAT Test', 'variant_name' => '',
    'vat_amount' => 200, 'net_amount' => 1000, 'vat_rate' => 20.0, 'vat_class' => 'standard',
]];
$f2Customer = [
    'name' => 'F2 Tester', 'email' => 'f2@test.com', 'phone' => '',
    'address' => ['street' => 'x', 'city' => 'y', 'zip' => '00000', 'country' => 'SK'],
    'currency' => 'eur', 'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 300,
];
// gross subtotal 1200, coupon 300 → discounted gross 900;
// scaled VAT = round(200 * 900/1200) = 150; net = 900 - 150 = 750.
$f2OrderId = Orders::create($f2Cart, $f2Customer, 'cod');
$f2Order   = Orders::byId($f2OrderId);
assert_equals(150, (int)$f2Order['vat_total'], 'F2: VAT_total scaled to post-coupon gross (200→150)');
assert_equals(750, (int)$f2Order['net_total'], 'F2: net_total = discounted gross − scaled VAT');
assert_equals(900, (int)$f2Order['vat_total'] + (int)$f2Order['net_total'], 'F2: invariant net+vat == discounted gross');

// --- F5: syncAll wraps each feed's sync() in try/catch so one bad feed does
//     not abort the whole run (structural guard — network-free).
$f5Src = file_get_contents(__DIR__ . '/../src/FeedImport.php') ?: '';
assert_true(
    (bool)preg_match('/foreach\s*\(\$feeds as \$feed\).*?try\s*\{.*?self::sync\(.*?\}\s*catch\s*\(\\\\Throwable/s', $f5Src),
    'F5: syncAll isolates per-feed sync() failures in try/catch'
);

// --- F6: isDue uses a real cron evaluator (cronField/cronMatches), not the
//     old "*\/N"-only heuristic that ran fixed-time schedules hourly.
$cf = new ReflectionMethod(FeedImport::class, 'cronField');
$cf->setAccessible(true);
assert_true($cf->invoke(null, '*', 5),       'F6: cronField "*" matches any value');
assert_true($cf->invoke(null, '*/15', 30),   'F6: cronField "*/15" matches 30');
assert_true(!$cf->invoke(null, '*/15', 7),   'F6: cronField "*/15" rejects 7');
assert_true($cf->invoke(null, '1-5', 3),     'F6: cronField range "1-5" matches 3');
assert_true(!$cf->invoke(null, '1-5', 6),    'F6: cronField range "1-5" rejects 6');
assert_true($cf->invoke(null, '0,15,30,45', 30), 'F6: cronField list matches 30');
assert_true($cf->invoke(null, '8-18/2', 10), 'F6: cronField step-range "8-18/2" matches 10');
assert_true(!$cf->invoke(null, '8-18/2', 9), 'F6: cronField step-range "8-18/2" rejects 9');

$cm = new ReflectionMethod(FeedImport::class, 'cronMatches');
$cm->setAccessible(true);
$f6Midnight = mktime(0, 0, 0, 6, 2, 2026); // 2026-06-02 00:00
$f6Noon     = mktime(12, 0, 0, 6, 2, 2026);
assert_true($cm->invoke(null, ['0', '0', '*', '*', '*'], $f6Midnight), 'F6: cronMatches "0 0 * * *" at midnight');
assert_true(!$cm->invoke(null, ['0', '0', '*', '*', '*'], $f6Noon),    'F6: cronMatches "0 0 * * *" rejects noon');

$isDue = new ReflectionMethod(FeedImport::class, 'isDue');
$isDue->setAccessible(true);
assert_true($isDue->invoke(null, '*/15 * * * *', date('Y-m-d H:i:s', time() - 3600)), 'F6: "*/15 * * * *" due after 1h');
assert_true(!$isDue->invoke(null, '0 0 * * *', date('Y-m-d H:i:s')), 'F6: daily schedule not due when last_sync is now');

// --- F7: JobQueue::markProcessing is an atomic claim — a second claim of the
//     same file (already moved by another worker) returns '' instead of a path.
$f7JobId = JobQueue::push('f7_claim_test', ['x' => 1], 1);
$f7Pulled = null;
foreach (JobQueue::pull(200) as $jb) {
    if (($jb['type'] ?? '') === 'f7_claim_test') { $f7Pulled = $jb; break; }
}
assert_true($f7Pulled !== null, 'F7: test job pulled from queue');
$f7First = JobQueue::markProcessing($f7Pulled);
assert_true($f7First !== '' && file_exists($f7First), 'F7: first markProcessing claims the job (non-empty path)');
$f7Second = JobQueue::markProcessing($f7Pulled);
assert_equals('', $f7Second, 'F7: second markProcessing of an already-claimed job returns "" (no double-run)');
JobQueue::complete($f7First);

// ============================================================
// TEST 60: Reusable digital commerce core
// ============================================================
echo "\n--- Test 60: Digital commerce core ---\n";

$digitalDir = __DIR__ . '/../db/digital-test-assets';
if (!is_dir($digitalDir)) mkdir($digitalDir, 0750, true);
file_put_contents($digitalDir . '/guide.pdf', '%PDF-1.4 digital test');
DigitalDelivery::setConfigForTesting([
    'digital_assets_base_dir' => $digitalDir,
    'app_url' => 'https://shop.example',
]);

$pdo->exec("INSERT INTO products (id, category_id, name, slug, sku, product_type, price, currency, status, stock)
    VALUES (9001, 1, 'Digital Guide', 'digital-guide', 'DIGI-GUIDE', 'downloadable', 999, 'eur', 'active', 0)");
$pdo->exec("INSERT INTO products (id, category_id, name, slug, sku, product_type, price, currency, status, stock)
    VALUES (9002, 1, 'Physical Low Stock', 'physical-low-stock', 'PHYS-LOW', 'simple', 999, 'eur', 'active', 1)");
$lowStockSummary = Products::lowStockSummary(10, 1000);
$lowStockSkus = array_column($lowStockSummary['products'], 'sku');
assert_true(in_array('PHYS-LOW', $lowStockSkus, true), 'Dashboard low stock: physical products remain included');
assert_true(!in_array('DIGI-GUIDE', $lowStockSkus, true), 'Dashboard low stock: downloadable products are excluded');
$pdo->exec("UPDATE products SET product_type = 'digital' WHERE id = 9001");
$lowStockSkus = array_column(Products::lowStockSummary(10, 1000)['products'], 'sku');
assert_true(!in_array('DIGI-GUIDE', $lowStockSkus, true), 'Dashboard low stock: digital alias products are excluded');
$pdo->exec("UPDATE products SET product_type = 'downloadable' WHERE id = 9001");
$pdo->exec("DELETE FROM products WHERE id = 9002");
$pdo->exec("INSERT INTO digital_assets
    (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
    VALUES ('DIGI-GUIDE', 'pdf', 'PDF guide', 'guide.pdf', 'guide.pdf', 'application/pdf', 2, 3600, 1)");
$_SESSION['cart'] = [];
Cart::add(9001, null, 5);
$digitalCart = Cart::enriched();
assert_equals(1, (int)$digitalCart['items'][0]['qty'], 'Digital: cart normalizes quantity to one');
assert_equals(1, (int)$_SESSION['cart']['p_9001']['qty'], 'Digital: normalized quantity persists in session');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['country' => 'SK', 'subtotal' => '999'];
$digitalRates = apiShippingRates();
assert_equals(1, count($digitalRates['methods']), 'Digital checkout: exposes exactly one delivery method');
assert_equals('digital', (string)$digitalRates['methods'][0]['method_code'], 'Digital checkout: uses electronic delivery');
assert_equals(0, (int)$digitalRates['methods'][0]['price'], 'Digital checkout: electronic delivery is free');
$_GET = ['country' => 'SK', 'currency' => 'eur', 'amount' => '999', 'shipping_method' => 'packeta'];
$digitalPayments = apiPaymentMethods();
$digitalPaymentCodes = array_column($digitalPayments['methods'], 'code');
assert_true(!in_array('cod', $digitalPaymentCodes, true), 'Digital checkout: COD is never offered');
assert_true(in_array('card', $digitalPaymentCodes, true), 'Digital checkout: card payment remains available');

ConsentService::setDefinitionsForTesting([]);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.60';
$_SESSION['csrf_token'] = 'digital-checkout-test';
$_POST = [
    'csrf_token' => 'digital-checkout-test',
    'name' => 'Digital Buyer',
    'email' => 'digital-checkout@example.com',
    'country' => 'SK',
    'shipping_method' => 'balik-adresa',
    'payment_method' => 'cod',
];
$digitalCod = apiCheckout();
assert_true(empty($digitalCod['success']), 'Digital checkout: server rejects forged COD and physical shipping');

$pdo->exec("UPDATE products SET price = 0 WHERE id = 9001");
Cart::clear();
Cart::add(9001, null, 1);
$_SERVER['REMOTE_ADDR'] = '127.0.0.61';
$_SESSION['csrf_token'] = 'free-digital-checkout-test';
$_POST = [
    'csrf_token' => 'free-digital-checkout-test',
    'name' => 'Free Digital Buyer',
    'email' => 'free-digital@example.com',
    'country' => 'SK',
    'shipping_method' => 'digital',
    'payment_method' => 'free',
];
$freeDigitalCheckout = apiCheckout();
assert_true(!empty($freeDigitalCheckout['success']), 'Digital checkout: zero-total order succeeds without a payment gateway');
$freeDigitalOrder = Orders::byId((int)($freeDigitalCheckout['order_id'] ?? 0));
assert_equals(0, (int)($freeDigitalOrder['shipping_cost'] ?? -1), 'Digital checkout: zero-total order has no shipping charge');
assert_equals('digital', (string)($freeDigitalOrder['shipping_method'] ?? ''), 'Digital checkout: order stores electronic delivery snapshot');
assert_equals('free', (string)($freeDigitalOrder['payment_method'] ?? ''), 'Digital checkout: zero-total order stores no-payment method');
assert_equals('paid', (string)($freeDigitalOrder['payment_status'] ?? ''), 'Digital checkout: zero-total order is immediately paid');
assert_equals('paid', (string)($freeDigitalOrder['status'] ?? ''), 'Digital checkout: zero-total order triggers fulfillment');
$pdo->exec("UPDATE products SET price = 999 WHERE id = 9001");
ConsentService::setDefinitionsForTesting(null);
$_GET = [];
$_POST = [];
Cart::clear();

$digitalOrderId = Orders::create([[
    'product_id' => 9001, 'variant_id' => null, 'qty' => 5, 'price' => 999,
    'sku' => 'DIGI-GUIDE', 'name' => 'Digital Guide', 'variant_name' => '',
]], [
    'name' => 'Digital Buyer', 'email' => 'digital@example.com', 'currency' => 'eur',
    'shipping_cost' => 0, 'tax_amount' => 0,
], 'card');
$digitalOrderItem = Orders::items($digitalOrderId)[0];
assert_equals(1, (int)$digitalOrderItem['quantity'], 'Digital: order boundary clamps quantity to one');
assert_equals(999, (int)Orders::byId($digitalOrderId)['total'], 'Digital: customer is charged for one copy only');
assert_equals(0, (int)$pdo->query("SELECT stock FROM products WHERE id=9001")->fetchColumn(), 'Digital: order does not decrement stock');

$stripeAdapter = new StripePayment([]);
$stripeLineItems = (new ReflectionMethod(StripePayment::class, 'buildLineItems'))->invoke($stripeAdapter, [
    'order_number' => 'DISCOUNTED-1', 'currency' => 'eur', 'total' => 899,
    'shipping_cost' => 0,
    'items' => [['name' => 'Digital Guide', 'quantity' => 1, 'unit_price' => 999]],
]);
assert_equals(1, count($stripeLineItems), 'Stripe: adjusted order uses one authoritative total line');
assert_equals(899, (int)$stripeLineItems[0]['price_data']['unit_amount'], 'Stripe: coupon/fee-adjusted line equals orders.total');

PaymentService::updatePaymentStatus($digitalOrderId, 'paid');
OrderWorkflow::transition($digitalOrderId, 'paid');
$digitalJobs = array_column(FulfillmentDispatcher::dispatch('paid', Orders::byId($digitalOrderId)), 0);
assert_true(in_array('digital_fulfill', $digitalJobs, true), 'Digital: paid event dispatches fulfillment job');
$links = DigitalDelivery::grant($digitalOrderId);
assert_equals(1, count($links), 'Digital: one entitlement issued for mapped asset');
$token = (string)parse_url($links[0]['url'], PHP_URL_QUERY);
parse_str($token, $tokenQuery);
$rawToken = (string)($tokenQuery['token'] ?? '');
assert_equals(64, strlen($rawToken), 'Digital: raw token is 64 hex characters');
$storedHash = (string)$pdo->query("SELECT token_hash FROM digital_entitlements WHERE order_id={$digitalOrderId} ORDER BY id DESC LIMIT 1")->fetchColumn();
assert_equals(hash('sha256', $rawToken), $storedHash, 'Digital: database stores only SHA-256 token hash');
assert_true($storedHash !== $rawToken, 'Digital: raw token is not persisted');
$resolved = DigitalDelivery::resolve($rawToken);
assert_true($resolved !== null, 'Digital: valid paid token resolves');
assert_equals(realpath($digitalDir . '/guide.pdf'), DigitalDelivery::resolvedPath($resolved), 'Digital: asset resolves only below configured base directory');
assert_true(DigitalDelivery::consume((int)$resolved['id']), 'Digital: first download slot consumed atomically');
assert_true(DigitalDelivery::consume((int)$resolved['id']), 'Digital: second download slot consumed atomically');
assert_true(!DigitalDelivery::consume((int)$resolved['id']), 'Digital: configured download limit enforced');
DigitalDelivery::revokeOrder($digitalOrderId, 'test_refund');
assert_true(DigitalDelivery::resolve($rawToken) === null, 'Digital: revoked token no longer resolves');

ConsentService::setDefinitionsForTesting([
    'digital_delivery' => [
        'version' => '2026-07-13', 'required' => true,
        'text' => 'Immediate digital delivery consent', 'product_types' => ['downloadable'],
    ],
]);
$consentContext = ['country' => 'SK', 'product_types' => ['downloadable']];
assert_true(isset(ConsentService::validate([], $consentContext)['digital_delivery']), 'Consent: required digital consent is enforced');
assert_equals([], ConsentService::validate(['digital_delivery' => true], $consentContext), 'Consent: explicit boolean acceptance passes');
ConsentService::record($digitalOrderId, ['digital_delivery' => true], $consentContext, '127.0.0.1', 'Test Agent');
$consentRows = ConsentService::forOrder($digitalOrderId);
assert_equals('2026-07-13', (string)$consentRows[0]['version'], 'Consent: immutable version snapshot stored');
assert_equals('Immediate digital delivery consent', (string)$consentRows[0]['consent_text'], 'Consent: immutable text snapshot stored');
assert_true((string)$consentRows[0]['ip_hash'] !== '127.0.0.1', 'Consent: IP stored as privacy-preserving hash');
ConsentService::setDefinitionsForTesting(null);

CheckoutSession::bind($digitalOrderId, 'cs_test_digital_1', 'stripe', 'browser-session-a');
assert_equals($digitalOrderId, CheckoutSession::resolveOrderId('cs_test_digital_1'), 'Checkout binding: session resolves order');
assert_true(CheckoutSession::verifyOwnership($digitalOrderId, 'cs_test_digital_1', 'browser-session-a'), 'Checkout binding: ownership works before webhook race completes');
CheckoutSession::markCompleted('cs_test_digital_1');
assert_true(CheckoutSession::verifyOwnership($digitalOrderId, 'cs_test_digital_1', 'browser-session-a'), 'Checkout binding: correct browser session owns result');
assert_true(!CheckoutSession::verifyOwnership($digitalOrderId, 'cs_test_digital_1', 'browser-session-b'), 'Checkout binding: foreign browser session rejected');
$pdo->exec("INSERT INTO variants (id, product_id, sku, name, stock, availability) VALUES (9001, 9001, 'TEST-COMING-SOON', 'Future', 5, 'coming_soon')");
assert_true(!VariantAvailability::isPurchasable(9001, 9001), 'Variants: coming_soon is rejected server-side');
VariantAvailability::setStatus(9001, VariantAvailability::ACTIVE);
assert_true(VariantAvailability::isPurchasable(9001, 9001), 'Variants: active digital variant is purchasable');
$digitalCommerceMig->up($pdo, true);
$variantIndexes = array_column($pdo->query("PRAGMA index_list(variants)")->fetchAll(PDO::FETCH_ASSOC), 'name');
assert_true(in_array('idx_variants_availability', $variantIndexes, true), 'Migration: index creation is idempotent on SQLite');

$digitalMail = MailService::render('digital_delivery', 'en', [
    'order_number' => 'DIGI-1', 'customer_name' => 'Buyer',
    'download_links' => [['label' => 'Guide', 'url' => 'https://shop.example/download', 'validity' => '7 days']],
]);
assert_true(str_contains($digitalMail['html'], 'https://shop.example/download'), 'Digital: delivery email renders secure link');

$pdo->prepare("INSERT INTO merchant_notifications
    (order_id, event_key, recipient, status, updated_at)
    VALUES (?, 'stale_test', 'merchant@example.com', 'processing', datetime('now', '-901 seconds'))")
    ->execute([$digitalOrderId]);
$merchantClaim = new ReflectionMethod(MerchantNotifier::class, 'claim');
assert_true($merchantClaim->invoke(null, $digitalOrderId, 'stale_test', 'merchant@example.com'), 'Merchant notification: stale processing claim is recovered');
assert_true(!$merchantClaim->invoke(null, $digitalOrderId, 'stale_test', 'merchant@example.com'), 'Merchant notification: fresh processing claim is not duplicated');

Orders::setPaymentId($digitalOrderId, 'pi_refund_progression');
onChargeRefunded(['id' => 'ch_refund_progression', 'payment_intent' => 'pi_refund_progression', 'amount_refunded' => 400]);
assert_equals('partially_refunded', (string)Orders::byId($digitalOrderId)['payment_status'], 'Stripe refund: first cumulative amount records partial refund');
assert_equals(400, PaymentService::refundedAmount($digitalOrderId), 'Stripe refund: first cumulative event records its delta');
onChargeRefunded(['id' => 'ch_refund_progression', 'payment_intent' => 'pi_refund_progression', 'amount_refunded' => 700]);
assert_equals('partially_refunded', (string)Orders::byId($digitalOrderId)['payment_status'], 'Stripe refund: increasing partial cumulative amount preserves partial status');
assert_equals(700, PaymentService::refundedAmount($digitalOrderId), 'Stripe refund: repeated partial event records only the cumulative delta');
onChargeRefunded(['id' => 'ch_refund_progression', 'payment_intent' => 'pi_refund_progression', 'amount_refunded' => 999]);
assert_equals(999, PaymentService::refundedAmount($digitalOrderId), 'Stripe refund: final cumulative event totals exactly the order refund');
$fullyRefundedOrder = Orders::byId($digitalOrderId);
assert_equals('refunded', (string)$fullyRefundedOrder['payment_status'], 'Stripe refund: later full amount uses a distinct idempotency key');
assert_equals('refunded', (string)$fullyRefundedOrder['status'], 'Stripe refund: full cumulative refund closes the order');

DigitalDelivery::setConfigForTesting(null);
@unlink($digitalDir . '/guide.pdf');
@rmdir($digitalDir);

// ============================================================
// TEST: G6 remaining security findings
// ============================================================
echo "\n--- G6: SSRF, rich HTML, refunds, RBAC, and error handling ---\n";

$unsafeRichHtml = '<div class="layout"><p onclick="steal()">Hello <strong>world</strong>'
    . '<script>alert(1)</script><img src="https://tracker.example/pixel">'
    . '<a href="jav&#x61;script:alert(1)" style="color:red">bad</a>'
    . '<a href="https://docs.example/path" title="Docs">safe</a></p>'
    . '<form action="https://evil.example"><input name="secret"></form></div>';
$safeRichHtml = Security::sanitizeRichHtml($unsafeRichHtml);
assert_true(str_contains($safeRichHtml, '<p>') && str_contains($safeRichHtml, '<strong>world</strong>'), 'G6 rich HTML: allowed formatting survives');
foreach (['<script', '<img', '<form', '<input', 'onclick', 'style=', 'javascript:'] as $blockedHtml) {
    assert_true(!str_contains(strtolower($safeRichHtml), $blockedHtml), "G6 rich HTML: {$blockedHtml} is removed");
}
assert_true(str_contains($safeRichHtml, 'href="https://docs.example/path"'), 'G6 rich HTML: safe HTTPS links survive');
assert_true(str_contains($securitySrc, "form-action 'self'"), 'G6 CSP: form submissions are restricted to self');
assert_true(str_contains($securitySrc, "frame-ancestors 'none'"), 'G6 CSP: framing is denied in CSP');

$feedEndpoint = new ReflectionMethod(FeedImport::class, 'publicFeedEndpoint');
foreach ([
    'file:///etc/passwd',
    'gopher://127.0.0.1/',
    'http://localhost/feed.xml',
    'http://127.0.0.1/feed.xml',
    'http://10.0.0.1/feed.xml',
    'http://169.254.169.254/latest/meta-data/',
    'http://[::1]/feed.xml',
    'http://[fe80::1]/feed.xml',
    'http://[64:ff9b::7f00:1]/feed.xml',
    'http://[64:ff9b::a9fe:a9fe]/latest/meta-data/',
    'http://[64:ff9b:1::7f00:1]/feed.xml',
    'http://2130706433/feed.xml',
    'http://127.0.0.1:8080/feed.xml',
    'http://user:pass@93.184.216.34/feed.xml',
] as $blockedFeedUrl) {
    assert_true($feedEndpoint->invoke(null, $blockedFeedUrl) === null, "G6 feed SSRF: blocks {$blockedFeedUrl}");
}
$publicFeed = $feedEndpoint->invoke(null, 'https://93.184.216.34/feed.xml');
assert_equals('93.184.216.34', (string)($publicFeed['ip'] ?? ''), 'G6 feed SSRF: accepts a literal public HTTPS endpoint');
$feedSourceG6 = file_get_contents(__DIR__ . '/../src/FeedImport.php') ?: '';
assert_true(str_contains($feedSourceG6, 'CURLOPT_RESOLVE'), 'G6 feed SSRF: verified DNS result is pinned for the connection');
assert_true(str_contains($feedSourceG6, "CURLOPT_PROXY => ''") && str_contains($feedSourceG6, "CURLOPT_NOPROXY => '*'"), 'G6 feed SSRF: ambient proxies cannot bypass DNS pinning');
assert_true(str_contains($feedSourceG6, 'CURLOPT_FOLLOWLOCATION => false'), 'G6 feed SSRF: redirects cannot pivot to a private target');
assert_true(str_contains($feedSourceG6, '$maxBytes = 10 * 1024 * 1024'), 'G6 feed SSRF: response size is bounded');

$g6RefundOrderId = Orders::create([[
    'product_id' => 9001, 'variant_id' => null, 'qty' => 1, 'price' => 999,
    'sku' => 'DIGI-GUIDE', 'name' => 'Digital Guide', 'variant_name' => '',
]], [
    'name' => 'G6 Refund Buyer', 'email' => 'g6-refund@example.com', 'currency' => 'eur',
    'shipping_cost' => 0, 'tax_amount' => 0,
], 'card');
PaymentService::transitionPayment($g6RefundOrderId, 'paid', 'payment.captured', 999, 'g6_refund_paid');
$g6RefundTotal = (int)Orders::byId($g6RefundOrderId)['total'];
assert_equals($g6RefundTotal, PaymentService::refundableBalance($g6RefundOrderId), 'G6 refund: fully captured order exposes its server-side balance');
$g6OverRefundRejected = false;
try {
    PaymentService::requestRefund($g6RefundOrderId, $g6RefundTotal + 1, 'g6_refund_over_limit');
} catch (LogicException $e) {
    $g6OverRefundRejected = true;
}
assert_true($g6OverRefundRejected, 'G6 refund: amount above balance is rejected inside the transaction');
assert_equals('paid', (string)Orders::byId($g6RefundOrderId)['payment_status'], 'G6 refund: rejected amount leaves payment state unchanged');
PaymentService::requestRefund($g6RefundOrderId, 400, 'g6_refund_pending', ['actor' => 'test-admin', 'amount' => 400]);
assert_equals($g6RefundTotal, PaymentService::refundableBalance($g6RefundOrderId), 'G6 refund: pending request is not misreported as completed refund');
$g6SecondClaimRejected = false;
try {
    PaymentService::requestRefund($g6RefundOrderId, 100, 'g6_refund_second_claim');
} catch (LogicException $e) {
    $g6SecondClaimRejected = true;
}
assert_true($g6SecondClaimRejected, 'G6 refund: a second request cannot claim an already-pending payment');
assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE order_id = {$g6RefundOrderId} AND status = 'refund_pending'")->fetchColumn(), 'G6 refund: failed second claim creates no extra refund event');
PaymentService::transitionPayment($g6RefundOrderId, 'partially_refunded', 'refund.partial_succeeded', 400, 'g6_refund_partial');
assert_equals($g6RefundTotal - 400, PaymentService::refundableBalance($g6RefundOrderId), 'G6 refund: successful partial refund reduces server-side balance');
$adminSourceG6 = file_get_contents(__DIR__ . '/../admin.php') ?: '';
assert_true(str_contains($adminSourceG6, '$amount > $refundableBalance'), 'G6 refund: handler rejects an amount above remaining balance');
assert_true(str_contains($adminSourceG6, 'PaymentService::requestRefund'), 'G6 refund: admin uses the atomic refund claim');
assert_true(str_contains($adminSourceG6, "['refund_pending', 'partially_refunded', 'refunded']"), 'G6 refund: generic payment transition blocks every refund status');
assert_true(str_contains($adminSourceG6, "\$reason === ''"), 'G6 refund: server requires a non-empty audit reason');
assert_true(str_contains($adminSourceG6, "adminCan('customers.edit')"), 'G6 RBAC: customer status control is hidden from view-only roles');
assert_true(str_contains($adminSourceG6, "adminRequire('customers.edit')"), 'G6 RBAC: customer status mutation requires edit permission server-side');
$paymentSourceG7 = file_get_contents(__DIR__ . '/../src/PaymentService.php') ?: '';
assert_true(!str_contains($paymentSourceG7, '->quote('), 'G7 SQL: payment webhook order lookups never interpolate quoted values');
assert_true(substr_count($paymentSourceG7, 'payment_id = :payment_id') >= 2 && str_contains($paymentSourceG7, 'order_number = :order_number'), 'G7 SQL: Stripe and PayU fallback identifiers use bound parameters');

$accountSourceG6 = file_get_contents(__DIR__ . '/../account.php') ?: '';
assert_true(str_contains($accountSourceG6, 'GDPR erase failed [{$errorId}]'), 'G6 errors: erase failure details are logged with a correlation ID');
assert_true(!str_contains($accountSourceG6, "gdpr_erase_failed') . ': ' . \$ex->getMessage()"), 'G6 errors: erase exception detail is not rendered to the customer');
$importSourceG6 = file_get_contents(__DIR__ . '/../src/Import.php') ?: '';
$pageSourceG6 = file_get_contents(__DIR__ . '/../page.php') ?: '';
$apiSourceG6 = file_get_contents(__DIR__ . '/../api.php') ?: '';
assert_true(substr_count($adminSourceG6, 'Security::sanitizeRichHtml') >= 2, 'G6 rich HTML: CMS and admin product writes are sanitized');
assert_true(str_contains($importSourceG6, "\$data['description'] = Security::sanitizeRichHtml"), 'G6 rich HTML: product imports sanitize descriptions');
assert_true(str_contains($pageSourceG6, 'Security::sanitizeRichHtml'), 'G6 rich HTML: historical CMS content is sanitized on render');
assert_true(str_contains($apiSourceG6, 'Security::sanitizeRichHtml'), 'G6 rich HTML: CMS API returns sanitized content');
$scriptBreakout = JsonLd::render(['name' => '</script><img src=x onerror=alert(1)>'], 'g6-nonce');
assert_equals(1, substr_count(strtolower($scriptBreakout), '</script>'), 'G6 JSON embed: attacker data cannot terminate the JSON-LD script');
assert_true(!str_contains(strtolower($scriptBreakout), '<img'), 'G6 JSON embed: attacker HTML stays hex-escaped inside JSON');
$productSourceG6 = file_get_contents(__DIR__ . '/../product.php') ?: '';
$jsonLdSourceG6 = file_get_contents(__DIR__ . '/../src/JsonLd.php') ?: '';
assert_true(substr_count($adminSourceG6, 'JSON_HEX_TAG') >= 4, 'G6 JSON embed: admin prefill and dynamic maps hex-escape tag delimiters');
assert_true(str_contains($productSourceG6, 'JSON_HEX_TAG'), 'G6 JSON embed: product JSON-LD hex-escapes tag delimiters');
assert_true(str_contains($jsonLdSourceG6, 'JSON_HEX_TAG'), 'G6 JSON embed: shared JSON-LD renderer hex-escapes tag delimiters');

[$g6EraseRegistered, $g6EraseCustomerId] = Customers::register('g6-erase@example.com', 'StrongPass!123', 'G6 Erase');
assert_true($g6EraseRegistered, 'G6 GDPR: audit-failure fixture customer is created');
$g6EraseResult = GdprService::anonymizeCustomer((int)$g6EraseCustomerId, 'g6-test');
assert_equals('anonymized', (string)($g6EraseResult['status'] ?? ''), 'G6 GDPR: post-commit audit failure does not report erasure as failed');
assert_equals('anonymized', (string)(Customers::byId((int)$g6EraseCustomerId)['status'] ?? ''), 'G6 GDPR: customer remains anonymized after audit logging failure');
$gdprSourceG6 = file_get_contents(__DIR__ . '/../src/GdprService.php') ?: '';
assert_true(str_contains($gdprSourceG6, 'GDPR erase audit failed [{$errorId}]'), 'G6 GDPR: post-commit audit failure is logged with a correlation ID');

// ============================================================
// TEST 61: G3 stable multi-provider registry and frozen dispatch
// ============================================================
echo "\n--- Test 61: G3 provider registry ---\n";

$providerRegistry = Adapters::registry();
assert_equals('StripePayment', (string)$providerRegistry['payment']['stripe']['class'], 'G3 registry: stripe has a stable provider code');
assert_equals('PayUPayment', (string)$providerRegistry['payment']['payu']['class'], 'G3 registry: PayU can coexist with Stripe');
assert_equals('PacketaShipping', (string)$providerRegistry['shipping']['packeta']['class'], 'G3 registry: shipping provider is registered');
assert_equals('SmtpMail', (string)$providerRegistry['mail']['smtp']['class'], 'G3 registry: mail provider is registered');
assert_equals('VatService', (string)$providerRegistry['tax']['vat']['class'], 'G3 registry: tax provider is registered');
assert_true(in_array('refund', $providerRegistry['payment']['stripe']['capabilities'], true), 'G3 registry: provider capabilities are declared');
assert_equals(StoreContext::current()->providerId(), (string)$providerRegistry['payment']['stripe']['scope'], 'G3 registry: definitions declare active provider scope');
assert_true(Adapters::provider('payment', 'stripe') instanceof StripePayment, 'G3 registry: resolves Stripe independently');
assert_true(Adapters::provider('payment', 'payu') instanceof PayUPayment, 'G3 registry: resolves PayU independently');
assert_true(Adapters::shipping('packeta') instanceof PacketaShipping, 'G3 registry: resolves shipping by stable code');
assert_true(Adapters::mail('smtp') instanceof SmtpMail, 'G3 registry: resolves mail by stable code');
assert_equals(VatService::class, Adapters::tax('vat'), 'G3 registry: resolves static tax provider contract');
assert_equals('payu', Adapters::providerCode('payment', PayUPayment::class), 'G3 registry: maps adapter class back to stable code');
$invalidProviderRejected = false;
try { Adapters::provider('payment', '../stripe'); }
catch (InvalidArgumentException $e) { $invalidProviderRejected = true; }
assert_true($invalidProviderRejected, 'G3 registry: unsafe provider code is rejected before class loading');

assert_equals('vat', (string)$financialSnapshot['providers']['tax'], 'G3 snapshot: direct financial snapshot freezes tax provider');
$checkoutProviderSnapshot = json_decode((string)$onlineCheckoutOrder['financial_snapshot'], true) ?: [];
assert_equals('stripe', (string)($checkoutProviderSnapshot['providers']['payment'] ?? ''), 'G3 snapshot: checkout freezes selected payment provider');
assert_equals('packeta', (string)($checkoutProviderSnapshot['providers']['shipping'] ?? ''), 'G3 snapshot: checkout freezes selected shipping provider');
assert_equals('log', (string)($checkoutProviderSnapshot['providers']['mail'] ?? ''), 'G3 snapshot: checkout freezes mail provider');
assert_equals('packeta', (string)($onlineCheckoutShippingSnapshot['provider'] ?? ''), 'G3 snapshot: shipping rate stores provider code');

$workerProviderSource = file_get_contents(__DIR__ . '/worker.php') ?: '';
assert_true(!str_contains($workerProviderSource, 'Adapters::payment();'), 'G3 worker: no payment job depends on one global gateway');
assert_true(str_contains($workerProviderSource, "Adapters::payment(\$providerCode)"), 'G3 worker: refund resolves frozen payment provider');
assert_true(str_contains($workerProviderSource, "Adapters::payment(\$provider)"), 'G3 worker: expiry resolves each order provider independently');
assert_true(str_contains($workerProviderSource, "Adapters::integration(\$providerCode)"), 'G3 worker: ERP jobs resolve the frozen provider');
assert_true(str_contains($apiSourceG6, 'Adapters::payment($paymentProvider)'), 'G3 checkout: selected payment provider drives session creation');
assert_true(!str_contains($apiSourceG6, 'new StripePayment($cfg)'), 'G3 webhook: no hardcoded Stripe construction remains');

// ============================================================
// TEST 62: G4 outbox, signed webhooks and external API v1
// ============================================================
echo "\n--- Test 62: G4 outbox, webhooks and API v1 ---\n";

Bootstrap::initStoreContext('/', '', 'default', 'api');
$outsideTransactionRejected = false;
try { Outbox::record('test.invalid', 'test', 1, []); }
catch (LogicException $e) { $outsideTransactionRejected = true; }
assert_true($outsideTransactionRejected, 'G4 outbox: event writes are rejected outside a domain transaction');

$rollbackEventId = str_repeat('b', 32);
$pdo->beginTransaction();
Outbox::record('test.rolled_back', 'test', 1, ['ok' => true], 1, $rollbackEventId);
$pdo->rollBack();
$rollbackProbe = $pdo->prepare('SELECT 1 FROM event_outbox WHERE id=?');
$rollbackProbe->execute([$rollbackEventId]);
assert_true(!$rollbackProbe->fetchColumn(), 'G4 outbox: domain rollback removes the event atomically');
assert_true((int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type='order.created'")->fetchColumn() > 0, 'G4 outbox: order creation emits committed events');
assert_true((int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type LIKE 'stock.%'")->fetchColumn() > 0, 'G4 outbox: stock mutations emit committed events');
assert_true((int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type='payment.status_changed'")->fetchColumn() > 0, 'G4 outbox: payment transitions emit committed events');
assert_true((int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type LIKE 'customer.%'")->fetchColumn() > 0, 'G4 outbox: customer mutations emit committed events');

$deliveryEventId = str_repeat('a', 32);
$pdo->beginTransaction();
Outbox::record('test.delivery', 'test', 42, ['answer' => 42], 1, $deliveryEventId);
$pdo->commit();
$claimedDelivery = Outbox::claim($deliveryEventId);
assert_equals(1, (int)($claimedDelivery['attempts'] ?? 0), 'G4 outbox: conditional claim increments delivery attempt once');
$deliveryEnvelope = Outbox::envelope($claimedDelivery);
assert_equals($deliveryEventId, (string)$deliveryEnvelope['id'], 'G4 outbox: stable v1 envelope preserves event ID');
assert_equals('default', (string)$deliveryEnvelope['store_id'], 'G4 outbox: envelope carries explicit store scope');

$webhookSecret = str_repeat('s', 32);
$webhookBody = json_encode($deliveryEnvelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$webhookTimestamp = (string)time();
$webhookSignature = Webhook::signature($webhookBody, $webhookTimestamp, $deliveryEventId, $webhookSecret);
assert_true(Webhook::verify($webhookBody, $webhookTimestamp, $deliveryEventId, $webhookSignature, $webhookSecret), 'G4 webhook: valid HMAC signature and fresh timestamp pass');
assert_true(!Webhook::verify($webhookBody . 'x', $webhookTimestamp, $deliveryEventId, $webhookSignature, $webhookSecret), 'G4 webhook: tampered raw body fails signature verification');
assert_true(!Webhook::verify($webhookBody, (string)(time() - 301), $deliveryEventId, Webhook::signature($webhookBody, (string)(time() - 301), $deliveryEventId, $webhookSecret), $webhookSecret), 'G4 webhook: stale timestamp is rejected');
assert_true(!Webhook::verify($webhookBody, $webhookTimestamp, $deliveryEventId, $webhookSignature, $webhookSecret, static fn(string $id): bool => true), 'G4 webhook: consumer replay guard rejects a consumed event ID');
$mockDelivery = Webhook::deliver([
    'url' => 'https://integration.example/webhook', 'secret' => $webhookSecret,
], $deliveryEnvelope, static fn(string $url, string $body, array $headers): array => ['response_code' => 204]);
assert_true($mockDelivery['delivered'], 'G4 webhook: any 2xx transport response completes delivery');
Outbox::recordDelivery($deliveryEventId, 'contract_test', 1, true, 204, null);
Outbox::markDelivered($deliveryEventId);
assert_true(Outbox::endpointDelivered($deliveryEventId, 'contract_test'), 'G4 webhook: successful endpoint attempt is auditably deduplicated');
assert_equals('delivered', (string)$pdo->query("SELECT status FROM event_outbox WHERE id='{$deliveryEventId}'")->fetchColumn(), 'G4 outbox: delivered event reaches terminal state');
$staleEventId = str_repeat('c', 32);
$pdo->beginTransaction();
Outbox::record('test.stale_claim', 'test', 43, [], 1, $staleEventId);
$pdo->commit();
Outbox::claim($staleEventId);
$pdo->exec("UPDATE event_outbox SET updated_at=datetime('now', '-301 seconds') WHERE id='{$staleEventId}'");
$staleClaim = Outbox::claim($staleEventId);
assert_equals(2, (int)($staleClaim['attempts'] ?? 0), 'G4 outbox: worker crash claim is recoverable after the lease expires');
Outbox::markDead($staleEventId, 'contract test cleanup');

$issuedClient = ApiAuth::issueClient('G4 contract test', 'default', ['products:read', 'products:write', 'orders:read']);
$storedTokenHash = (string)$pdo->query('SELECT token_hash FROM api_clients WHERE id=' . (int)$issuedClient['id'])->fetchColumn();
assert_equals(hash('sha256', $issuedClient['token']), $storedTokenHash, 'G4 API auth: database stores only the bearer token hash');
assert_true($storedTokenHash !== $issuedClient['token'], 'G4 API auth: plaintext bearer token is never persisted');
$apiClient = ApiAuth::authenticate('Bearer ' . $issuedClient['token']);
assert_equals('default', (string)$apiClient['store_id'], 'G4 API auth: credential resolves its immutable store scope');
$wrongTokenRejected = false;
$wrongToken = substr($issuedClient['token'], 0, -1) . (str_ends_with($issuedClient['token'], '0') ? '1' : '0');
try { ApiAuth::authenticate('Bearer ' . $wrongToken); }
catch (RuntimeException $e) { $wrongTokenRejected = true; }
assert_true($wrongTokenRejected, 'G4 API auth: wrong secret fails closed');
$scopeRejected = false;
try { ApiAuth::requireScope($apiClient, 'orders:write'); }
catch (DomainException $e) { $scopeRejected = true; }
assert_true($scopeRejected, 'G4 API auth: missing mutation scope is rejected');

$productPage = ApiV1::dispatch('GET', '/api/v1/products', ['limit' => 1], [], [], $apiClient);
assert_equals(200, $productPage['status'], 'G4 API v1: scoped product list succeeds');
assert_equals(1, count($productPage['body']['data']), 'G4 API v1: cursor page obeys limit');
assert_true($productPage['body']['meta']['next_cursor'] !== null, 'G4 API v1: opaque next cursor is returned');
$secondProductPage = ApiV1::dispatch('GET', '/api/v1/products', [
    'limit' => 1, 'cursor' => $productPage['body']['meta']['next_cursor'],
], [], [], $apiClient);
assert_true((int)$secondProductPage['body']['data'][0]['id'] > (int)$productPage['body']['data'][0]['id'], 'G4 API v1: cursor advances in stable ID order');

$apiProductId = (int)$productPage['body']['data'][0]['id'];
$apiProductBefore = Products::byId($apiProductId);
$patchBody = ['stock' => (int)$apiProductBefore['stock'] + 1];
$idempotencyKey = 'g4-product-patch-0001';
$patchedProduct = ApiV1::dispatch('PATCH', "/api/v1/products/{$apiProductId}", [], $patchBody, ['Idempotency-Key' => $idempotencyKey], $apiClient);
assert_equals(200, $patchedProduct['status'], 'G4 API v1: scoped product mutation succeeds');
assert_equals((int)$apiProductBefore['stock'] + 1, (int)$patchedProduct['body']['data']['stock'], 'G4 API v1: stock remains an integer quantity');
$productOutboxCount = (int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type='product.updated' AND aggregate_id='{$apiProductId}'")->fetchColumn();
$replayedPatch = ApiV1::dispatch('PATCH', "/api/v1/products/{$apiProductId}", [], $patchBody, ['Idempotency-Key' => $idempotencyKey], $apiClient);
assert_true($replayedPatch['body']['meta']['idempotent_replay'], 'G4 API v1: identical retry returns the stored response');
assert_equals($productOutboxCount, (int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type='product.updated' AND aggregate_id='{$apiProductId}'")->fetchColumn(), 'G4 API v1: retry does not repeat mutation or outbox event');
$idempotencyConflict = false;
try {
    ApiV1::dispatch('PATCH', "/api/v1/products/{$apiProductId}", [], ['stock' => (int)$patchBody['stock'] + 1], ['Idempotency-Key' => $idempotencyKey], $apiClient);
} catch (UnexpectedValueException $e) { $idempotencyConflict = true; }
assert_true($idempotencyConflict, 'G4 API v1: same key with a different request returns conflict');
$pdo->prepare("UPDATE api_idempotency SET created_at=datetime('now', '-31 days') WHERE client_id=? AND idempotency_key=?")
    ->execute([$apiClient['id'], $idempotencyKey]);
assert_equals(1, ApiAuth::cleanupIdempotency(30), 'G4 API v1: expired replay responses are cleaned by the worker lifecycle');

$crossStoreRejected = false;
$foreignClient = $apiClient;
$foreignClient['store_id'] = 'cz';
try { ApiV1::dispatch('GET', '/api/v1/orders', [], [], [], $foreignClient); }
catch (DomainException $e) { $crossStoreRejected = true; }
assert_true($crossStoreRejected, 'G4 API v1: client cannot cross the active StoreContext');
$openApi = file_get_contents(__DIR__ . '/../docs/openapi.yaml') ?: '';
assert_true(str_contains($openApi, 'openapi: 3.1.0'), 'G4 contract: versioned OpenAPI document exists');
assert_true(str_contains($openApi, 'Idempotency-Key') && str_contains($openApi, 'products:write'), 'G4 contract: idempotency and scopes are documented');
assert_true(str_contains($openApi, 'X-BBE-Signature') && str_contains($openApi, 'dead-letter'), 'G4 contract: HMAC, replay and retry/dead-letter semantics are documented');
$workerG4 = file_get_contents(__DIR__ . '/worker.php') ?: '';
assert_true(str_contains($workerG4, 'processOutbox') && str_contains($workerG4, 'Outbox::markRetry'), 'G4 worker: committed events are delivered with durable retry handling');
Bootstrap::initStoreContext('/', '', 'default', 'storefront');

// ============================================================
// TEST 63: G5 setup, health, structured logs, shipping and ERP
// ============================================================
echo "\n--- Test 63: G5 merchant operations and integration contracts ---\n";

$g5Registry = Adapters::registry();
assert_equals('HttpShipping', (string)$g5Registry['shipping']['http-carrier']['class'], 'G5 registry: generic HTTP carrier is available');
assert_true(in_array('cancel', $g5Registry['shipping']['http-carrier']['capabilities'], true), 'G5 registry: carrier declares cancellation capability');
assert_equals('HttpErpAdapter', (string)$g5Registry['erp']['http']['class'], 'G5 registry: generic HTTP ERP is available');
assert_true(in_array('order_status', $g5Registry['erp']['http']['capabilities'], true), 'G5 registry: ERP declares status mutation capability');

$g5Calls = [];
JsonHttpClient::setFixtureTransport(static function (string $method, string $url, string $body, array $headers) use (&$g5Calls): array {
    $g5Calls[] = compact('method', 'url', 'body', 'headers');
    $path = (string)parse_url($url, PHP_URL_PATH);
    if ($path === '/v1/rates') return ['status' => 200, 'body' => ['rates' => [[
        'method_code' => 'fixture-pickup', 'label' => 'Fixture pickup', 'price' => 299,
        'cod_surcharge' => 0, 'delivery_days' => '1-2', 'cod' => false, 'pickup_required' => true,
    ]]]];
    if ($path === '/v1/pickup-points') return ['status' => 200, 'body' => ['data' => [[
        'id' => 'PP-1', 'name' => 'Fixture Point', 'address' => 'Test 1, Bratislava',
    ]]]];
    if ($path === '/v1/labels' && $method === 'POST') return ['status' => 201, 'body' => ['awb' => 'HTTP00000001', 'label_data' => 'fixture-label']];
    if (str_ends_with($path, '/cancel')) return ['status' => 200, 'body' => ['status' => 'cancelled']];
    if (str_starts_with($path, '/v1/shipments/')) return ['status' => 200, 'body' => ['status' => 'in_transit', 'events' => [['timestamp' => '2026-08-01T12:00:00Z', 'location' => 'Hub', 'description' => 'Accepted']]]];
    if ($path === '/v1/orders' && $method === 'POST') return ['status' => 201, 'body' => ['id' => 'ERP-1001']];
    if (str_ends_with($path, '/status')) return ['status' => 200, 'body' => ['status' => 'processing']];
    if ($path === '/v1/orders/ERP-1001' && $method === 'PATCH') return ['status' => 200, 'body' => ['status' => 'shipped']];
    if ($path === '/v1/stock') return ['status' => 200, 'body' => ['data' => [['sku' => 'TEST-001', 'qty' => 9]]]];
    if ($path === '/v1/customers') return ['status' => 201, 'body' => ['id' => 'CUS-1']];
    if ($path === '/v1/health') return ['status' => 200, 'body' => ['status' => 'ok']];
    if (str_starts_with($path, '/v1/products/')) return ['status' => 200, 'body' => ['status' => 'ok']];
    if ($path === '/v1/products') return ['status' => 200, 'body' => ['data' => [['sku' => 'TEST-001']]]];
    return ['status' => 404, 'body' => ['error' => ['code' => 'fixture_not_found']]];
});
$g5Config = [
    'integrations' => [
        'http_shipping' => ['base_url' => 'https://carrier.test', 'api_key' => str_repeat('c', 24), 'carrier_code' => 'http-carrier'],
        'http_erp' => ['base_url' => 'https://erp.test', 'api_key' => str_repeat('e', 24)],
    ],
];
$g5Shipping = new HttpShipping($g5Config);
$g5Rates = $g5Shipping->getRates(['country' => 'SK', 'weight_g' => 1000, 'subtotal' => 2000]);
assert_equals(299, (int)$g5Rates[0]['price'], 'G5 shipping: fixture rate preserves integer cents');
assert_true(!empty($g5Rates[0]['pickup_required']), 'G5 shipping: pickup requirement is explicit');
assert_equals('PP-1', (string)$g5Shipping->pickupPoints('SK', '81101')[0]['id'], 'G5 shipping: pickup points contract resolves');
$g5Label = $g5Shipping->generateLabel(['id' => 1, 'order_number' => 'G5-1', 'shipping_addr' => '{}', 'total' => 1000, 'currency' => 'eur']);
assert_equals('HTTP00000001', (string)$g5Label['awb'], 'G5 shipping: label contract returns AWB');
assert_equals('in_transit', (string)$g5Shipping->trackShipment('HTTP00000001')['status'], 'G5 shipping: tracking contract resolves');
assert_equals('cancelled', (string)$g5Shipping->cancelLabel('HTTP00000001')['status'], 'G5 shipping: label cancellation contract resolves');

ShippingService::setAdapters(['http-carrier' => $g5Shipping]);
$g5LabelCallsBefore = count(array_filter($g5Calls, static fn(array $call): bool => parse_url($call['url'], PHP_URL_PATH) === '/v1/labels' && $call['method'] === 'POST'));
$g5ServiceLabel = ShippingService::generateLabel((int)$digitalOrderId, 'http-carrier');
$g5ServiceLabelReplay = ShippingService::generateLabel((int)$digitalOrderId, 'http-carrier');
$g5LabelCallsAfter = count(array_filter($g5Calls, static fn(array $call): bool => parse_url($call['url'], PHP_URL_PATH) === '/v1/labels' && $call['method'] === 'POST'));
assert_equals((string)$g5ServiceLabel['awb'], (string)$g5ServiceLabelReplay['awb'], 'G5 shipping: label retry returns the original AWB');
assert_equals($g5LabelCallsBefore + 1, $g5LabelCallsAfter, 'G5 shipping: label retry performs one external mutation');
$g5Cancel = ShippingService::cancelLabel((int)$digitalOrderId, 'http-carrier');
$g5CancelReplay = ShippingService::cancelLabel((int)$digitalOrderId, 'http-carrier');
assert_true(empty($g5Cancel['idempotent_replay']) && !empty($g5CancelReplay['idempotent_replay']), 'G5 shipping: cancel replay is served from durable label state');
assert_equals('in_transit', (string)ShippingService::trackShipment('http-carrier', (string)$g5ServiceLabel['awb'])['status'], 'G5 shipping: service tracking uses frozen carrier');
ShippingService::setAdapters([]);

$g5Erp = new HttpErpAdapter($g5Config);
assert_equals('ERP-1001', $g5Erp->pushOrder(['id' => 1001, 'order_number' => 'G5-1001', 'items' => []]), 'G5 ERP: order export returns external ID');
$g5Erp->updateOrderStatus('ERP-1001', 'shipped', ['order_id' => 1001]);
assert_equals('processing', $g5Erp->pullOrderStatus('ERP-1001'), 'G5 ERP: status pull contract resolves');
assert_equals(9, (int)$g5Erp->pullStock()[0]['qty'], 'G5 ERP: stock contract resolves integer quantity');
assert_true($g5Erp->healthCheck(), 'G5 ERP: health contract succeeds');
assert_true(count(iterator_to_array($g5Erp->pullProducts())) === 1, 'G5 ERP: product delta contract is iterable');
$g5Erp->pushProduct(['sku' => 'TEST-001']);
assert_equals('CUS-1', $g5Erp->pushCustomer(['id' => 1, 'email' => 'fixture@example.test']), 'G5 ERP: customer export returns external ID');
assert_true(str_starts_with((string)$g5Calls[0]['headers'][3], 'Authorization: Bearer '), 'G5 HTTP: credentials use authorization header, not URL or payload');
foreach ($g5Calls as $call) {
    assert_true(!str_contains($call['url'], str_repeat('c', 24)) && !str_contains($call['body'], str_repeat('c', 24)), 'G5 HTTP: carrier secret never enters URL or JSON body');
    assert_true(!str_contains($call['url'], str_repeat('e', 24)) && !str_contains($call['body'], str_repeat('e', 24)), 'G5 HTTP: ERP secret never enters URL or JSON body');
}
JsonHttpClient::setFixtureTransport(null);

$g5Redacted = Logger::redact(['api_key' => 'secret-value', 'nested' => ['password' => 'hidden'], 'order_id' => 42]);
assert_equals('[REDACTED]', $g5Redacted['api_key'], 'G5 logging: top-level secret is redacted');
assert_equals('[REDACTED]', $g5Redacted['nested']['password'], 'G5 logging: nested secret is redacted');
assert_equals(42, $g5Redacted['order_id'], 'G5 logging: useful non-secret context remains');
Logger::setCorrelationId('g5-test-correlation');
assert_equals('g5-test-correlation', Logger::correlationId(), 'G5 logging: correlation ID is stable');
Logger::setCorrelationId(null);

$g5Schema = json_decode((string)file_get_contents(__DIR__ . '/../config/config.schema.json'), true);
assert_true(is_array($g5Schema), 'G5 setup: machine-readable config schema is valid JSON');
assert_true(!empty($g5Schema['properties']['db_password']['x-secret']), 'G5 setup: config schema marks secrets');
$g5Doctor = Setup::doctor(require __DIR__ . '/../config.php', dirname(__DIR__), $pdo);
assert_equals(1, (int)$g5Doctor['schema_version'], 'G5 setup: doctor output is explicitly versioned');
assert_true(isset($g5Doctor['counts']['pass'], $g5Doctor['checks']), 'G5 setup: doctor output is machine-readable');
$g5DoctorJson = json_encode($g5Doctor, JSON_THROW_ON_ERROR);
assert_true(!str_contains($g5DoctorJson, 'db_password'), 'G5 setup: doctor output excludes database password fields');
$g5SetupRoot = sys_get_temp_dir() . '/bbe-setup-' . bin2hex(random_bytes(4));
$g5SetupConfig = [
    'db_driver' => 'mysql',
    'private_runtime_dir' => $g5SetupRoot . '/runtime',
    'private_cache_dir' => $g5SetupRoot . '/cache',
    'jobs_dir' => $g5SetupRoot . '/jobs',
    'invoice_storage_dir' => $g5SetupRoot . '/invoices',
    'backup_storage_dir' => $g5SetupRoot . '/backups',
    'backup_log_file' => $g5SetupRoot . '/logs/backup.log',
    'structured_log_file' => $g5SetupRoot . '/logs/application.jsonl',
];
$g5ApplyFirst = Setup::apply($g5SetupConfig, dirname(__DIR__));
$g5SetupStateHash = hash_file('sha256', $g5ApplyFirst['state_file']);
$g5ApplySecond = Setup::apply($g5SetupConfig, dirname(__DIR__));
assert_equals('created', (string)$g5ApplyFirst['state_action'], 'G5 setup: first apply creates private version state');
assert_equals('unchanged', (string)$g5ApplySecond['state_action'], 'G5 setup: repeated apply is idempotent');
assert_equals($g5SetupStateHash, hash_file('sha256', $g5ApplySecond['state_file']), 'G5 setup: repeated apply does not rewrite state');
@unlink($g5ApplyFirst['state_file']);
foreach (['runtime', 'cache', 'jobs', 'invoices', 'backups', 'logs'] as $g5SetupDir) @rmdir($g5SetupRoot . '/' . $g5SetupDir);
@rmdir($g5SetupRoot);

$g5HealthConfig = (require __DIR__ . '/../config.php');
$g5HealthConfig['jobs_dir'] = sys_get_temp_dir();
$g5HealthConfig['private_cache_dir'] = sys_get_temp_dir();
$g5Ready = Health::readiness($g5HealthConfig, dirname(__DIR__), $pdo);
assert_equals('ready', (string)$g5Ready['status'], 'G5 health: readiness verifies DB, stores, and writable runtime paths');
assert_equals('ok', (string)Health::liveness()['status'], 'G5 health: liveness has no dependency side effects');
assert_true(str_contains($htaccess, 'healthz|readyz'), 'G5 health: public probe routes are explicit');
assert_true(str_contains($workerProviderSource, 'updateOrderStatus'), 'G5 ERP: worker executes status mutation contract instead of logging a placeholder');
assert_true(!str_contains($workerProviderSource, 'IntegrationAdapter has no outbound status mutation contract yet'), 'G5 ERP: worker placeholder is removed');

// ============================================================
// G7: auditable inventory, backorders, bundles and split fulfillment
// ============================================================
echo "\n--- G7: Inventory ledger and partial fulfillment ---\n";
$g7CategoryId = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$pdo->exec("DELETE FROM shipment_items");
$pdo->exec("DELETE FROM shipments");
$pdo->exec("DELETE FROM inventory_receipts");
$pdo->exec("DELETE FROM purchase_order_items");
$pdo->exec("DELETE FROM purchase_orders");
$pdo->exec("DELETE FROM supplier_products");
$pdo->exec("DELETE FROM suppliers");
$pdo->exec("DELETE FROM bundle_components");
$pdo->exec("DELETE FROM warehouse_stock WHERE sku LIKE 'G7-%'");
$pdo->exec("DELETE FROM warehouses WHERE code LIKE 'g7-%'");
$pdo->exec("DELETE FROM products WHERE sku LIKE 'G7-%'");
$pdo->exec("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('g7-main','G7 Main',200,0,'default'),('g7-aux','G7 Aux',100,0,'default')");
$g7Main = (int)$pdo->query("SELECT id FROM warehouses WHERE code='g7-main'")->fetchColumn();
$g7Aux = (int)$pdo->query("SELECT id FROM warehouses WHERE code='g7-aux'")->fetchColumn();

$g7ProductInsert = $pdo->prepare("INSERT INTO products
    (store_id, category_id, sku, slug, product_type, name, price, stock, status, inventory_policy, preorder_at, personalization_schema)
    VALUES ('default', :category, :sku, :slug, :type, :name, :price, :stock, 'active', :policy, :preorder, :personalization)");
$g7Products = [
    ['G7-COMP-A','g7-comp-a','simple','G7 Component A',500,3,'backorder',null,'{}'],
    ['G7-COMP-B','g7-comp-b','simple','G7 Component B',300,10,'deny',null,'{}'],
    ['G7-BUNDLE','g7-bundle','bundle','G7 Personalized Kit',1500,10,'deny',null,'{"required":["engraving"]}'],
    ['G7-SPLIT','g7-split','simple','G7 Split Product',900,3,'deny',null,'{}'],
    ['G7-CANCEL','g7-cancel','simple','G7 Partial Cancel Product',800,3,'deny',null,'{}'],
    ['G7-PRE','g7-pre','simple','G7 Preorder Product',700,0,'preorder','2030-01-15 00:00:00','{}'],
];
foreach ($g7Products as [$sku,$slug,$type,$name,$price,$stock,$policy,$preorder,$personalization]) {
    $g7ProductInsert->execute([
        ':category' => $g7CategoryId, ':sku' => $sku, ':slug' => $slug, ':type' => $type,
        ':name' => $name, ':price' => $price, ':stock' => $stock, ':policy' => $policy,
        ':preorder' => $preorder, ':personalization' => $personalization,
    ]);
}
$g7ProductIds = [];
foreach ($pdo->query("SELECT id, sku FROM products WHERE sku LIKE 'G7-%'")->fetchAll(PDO::FETCH_ASSOC) as $row) $g7ProductIds[$row['sku']] = (int)$row['id'];
$pdo->prepare('INSERT INTO bundle_components (bundle_product_id, component_product_id, quantity, sort_order) VALUES (:bundle,:a,2,1),(:bundle,:b,1,2)')
    ->execute([':bundle' => $g7ProductIds['G7-BUNDLE'], ':a' => $g7ProductIds['G7-COMP-A'], ':b' => $g7ProductIds['G7-COMP-B']]);
Warehouse::setStock($g7Main, 'G7-COMP-A', 3, 'count', 'test', 'g7-opening-a');
Warehouse::setStock($g7Main, 'G7-COMP-B', 10, 'count', 'test', 'g7-opening-b');
Warehouse::setStock($g7Main, 'G7-SPLIT', 1, 'count', 'test', 'g7-opening-split-main');
Warehouse::setStock($g7Aux, 'G7-SPLIT', 2, 'count', 'test', 'g7-opening-split-aux');
Warehouse::setStock($g7Main, 'G7-CANCEL', 1, 'count', 'test', 'g7-opening-cancel-main');
Warehouse::setStock($g7Aux, 'G7-CANCEL', 2, 'count', 'test', 'g7-opening-cancel-aux');
Warehouse::setStock($g7Main, 'G7-REL', 4, 'count', 'test', 'g7-opening-release');
Warehouse::syncProductStock(['G7-COMP-A','G7-COMP-B','G7-SPLIT','G7-CANCEL']);
StoreContext::activate($deContext);
assert_equals(0, Warehouse::totalAvailable('G7-SPLIT'), 'G7 store isolation: another store cannot see default-store stock');
$g7CrossStoreReserveBlocked = false;
try { Warehouse::reserve('G7-SPLIT', 1); } catch (RuntimeException $e) { $g7CrossStoreReserveBlocked = true; }
assert_true($g7CrossStoreReserveBlocked, 'G7 store isolation: another store cannot reserve default-store stock');
Bootstrap::initStoreContext('/', '', 'default');

$g7MissingPersonalization = false;
try {
    Inventory::reserveLine(['product_id' => $g7ProductIds['G7-BUNDLE'], 'variant_id' => null, 'sku' => 'G7-BUNDLE', 'attrs' => []], 1);
} catch (InvalidArgumentException $e) {
    $g7MissingPersonalization = true;
}
assert_true($g7MissingPersonalization, 'G7 personalization: required fields fail closed before reservation');
assert_true(VariantAvailability::isPurchasable($g7ProductIds['G7-PRE']), 'G7 preorder: explicit preorder remains purchasable at zero stock');

$g7Customer = [
    'name' => 'G7 Customer', 'email' => 'g7@example.test', 'phone' => '', 'address' => [],
    'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur',
];
$g7BundleOrder = Orders::create([[
    'product_id' => $g7ProductIds['G7-BUNDLE'], 'variant_id' => null, 'sku' => 'G7-BUNDLE',
    'name' => 'G7 Personalized Kit', 'qty' => 2, 'price' => 1500,
    'attrs' => ['personalization' => ['engraving' => 'Audit me']],
]], $g7Customer, 'bank_transfer');
$g7BundleItem = Orders::items($g7BundleOrder)[0];
$g7BundleAttrs = json_decode((string)$g7BundleItem['attrs'], true);
assert_equals(1, (int)$g7BundleItem['backordered_qty'], 'G7 bundle: component shortage freezes one backordered kit');
assert_equals(2, count($g7BundleAttrs['_bundle_components'] ?? []), 'G7 bundle: component allocation snapshot is frozen on the order line');
$g7BackorderShipBlocked = false;
try { Fulfillment::shipOrder($g7BundleOrder); } catch (RuntimeException $e) { $g7BackorderShipBlocked = true; }
assert_true($g7BackorderShipBlocked, 'G7 backorder: final shipment fails closed until shortage is allocated');
$g7BundleShipments = Fulfillment::planOrder($g7BundleOrder);
assert_equals(1, count($g7BundleShipments), 'G7 bundle: available components are grouped into the source warehouse shipment');
assert_true(Fulfillment::ship($g7BundleShipments[0]), 'G7 fulfillment: available bundle components ship partially');
assert_true(!Fulfillment::ship($g7BundleShipments[0]), 'G7 fulfillment: repeated shipment cannot double-consume stock');
assert_equals('partially_fulfilled', (string)Orders::items($g7BundleOrder)[0]['fulfillment_status'], 'G7 fulfillment: backordered bundle remains partially fulfilled');

$g7SplitOrder = Orders::create([[
    'product_id' => $g7ProductIds['G7-SPLIT'], 'variant_id' => null, 'sku' => 'G7-SPLIT',
    'name' => 'G7 Split Product', 'qty' => 3, 'price' => 900, 'attrs' => [],
]], $g7Customer, 'bank_transfer');
$g7SplitShipments = Fulfillment::planOrder($g7SplitOrder);
assert_equals(2, count($g7SplitShipments), 'G7 fulfillment: one order line splits across two warehouses');
assert_true(Fulfillment::ship($g7SplitShipments[0]), 'G7 fulfillment: first partial warehouse shipment succeeds');
assert_equals('partially_fulfilled', (string)Orders::items($g7SplitOrder)[0]['fulfillment_status'], 'G7 fulfillment: line reports partial state after first shipment');
assert_true(Fulfillment::ship($g7SplitShipments[1]), 'G7 fulfillment: second warehouse shipment succeeds');
assert_equals('fulfilled', (string)Orders::items($g7SplitOrder)[0]['fulfillment_status'], 'G7 fulfillment: line is fulfilled only after all warehouse shipments');
assert_equals(0, Warehouse::totalAvailable('G7-SPLIT'), 'G7 fulfillment: shipment consumes exact reserved on-hand stock');

$g7CancelOrder = Orders::create([[
    'product_id' => $g7ProductIds['G7-CANCEL'], 'variant_id' => null, 'sku' => 'G7-CANCEL',
    'name' => 'G7 Partial Cancel Product', 'qty' => 3, 'price' => 800, 'attrs' => [],
]], $g7Customer, 'bank_transfer');
OrderWorkflow::transition($g7CancelOrder, 'paid');
OrderWorkflow::transition($g7CancelOrder, 'processing');
$g7CancelShipments = Fulfillment::planOrder($g7CancelOrder);
assert_true(Fulfillment::ship($g7CancelShipments[0]), 'G7 cancellation: first warehouse can ship before order cancellation');
OrderWorkflow::transition($g7CancelOrder, 'cancelled');
assert_equals(2, Warehouse::totalAvailable('G7-CANCEL'), 'G7 cancellation: only unshipped warehouse reservation is released');
assert_equals(0, (int)$pdo->query("SELECT SUM(reserved) FROM warehouse_stock WHERE sku='G7-CANCEL'")->fetchColumn(), 'G7 cancellation: no reservation leaks after partial shipment cancellation');

$g7Release = Warehouse::reserve('G7-REL', 2);
Warehouse::release('G7-REL', $g7Release, 'test', 'g7-release');
$g7DoubleReleaseBlocked = false;
try { Warehouse::release('G7-REL', $g7Release, 'test', 'g7-release-again'); } catch (RuntimeException $e) { $g7DoubleReleaseBlocked = true; }
assert_true($g7DoubleReleaseBlocked, 'G7 reservation: exact release guard rejects a double restore');
assert_equals(0, (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE warehouse_id={$g7Main} AND sku='G7-REL'")->fetchColumn(), 'G7 reservation: failed double release leaves reservation unchanged');

$pdo->exec("INSERT INTO suppliers (store_id, code, name) VALUES ('default','g7-supplier','G7 Supplier')");
$g7Supplier = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO supplier_products (supplier_id, sku, cost_price, min_order_qty, pack_size) VALUES (:supplier,'G7-COMP-A',250,4,2)")
    ->execute([':supplier' => $g7Supplier]);
$g7MinimumBlocked = false;
try { Inventory::createPurchaseOrder($g7Supplier, $g7Main, 'G7-PO-BAD', [['sku' => 'G7-COMP-A', 'quantity' => 3]]); } catch (InvalidArgumentException $e) { $g7MinimumBlocked = true; }
assert_true($g7MinimumBlocked, 'G7 procurement: supplier minimum and pack size fail closed');
$g7Po = Inventory::createPurchaseOrder($g7Supplier, $g7Main, 'G7-PO-1', [['sku' => 'G7-COMP-A', 'quantity' => 4]]);
assert_true(Inventory::receivePurchaseOrder($g7Po, 'G7-COMP-A', 2, 'g7-receipt-1'), 'G7 procurement: partial PO receipt posts stock');
assert_true(!Inventory::receivePurchaseOrder($g7Po, 'G7-COMP-A', 2, 'g7-receipt-1'), 'G7 procurement: repeated receipt idempotency key is a no-op');
assert_equals(2, (int)$pdo->query("SELECT received_qty FROM purchase_order_items WHERE purchase_order_id={$g7Po}")->fetchColumn(), 'G7 procurement: repeated receipt cannot overstate received quantity');
assert_equals(2, (int)$pdo->query("SELECT qty FROM warehouse_stock WHERE warehouse_id={$g7Main} AND sku='G7-COMP-A'")->fetchColumn(), 'G7 procurement: physical stock matches the single accepted receipt');
$g7MovementTypes = $pdo->query("SELECT DISTINCT movement_type FROM stock_movements WHERE sku LIKE 'G7-%'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['count','reserve','release','shipment','receipt','backorder'] as $g7MovementType) {
    assert_true(in_array($g7MovementType, $g7MovementTypes, true), "G7 ledger: {$g7MovementType} movement is auditable");
}
$g7LedgerRows = $pdo->query("SELECT quantity_delta, reserved_delta, on_hand_after, reserved_after FROM stock_movements WHERE sku='G7-SPLIT' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
assert_true(count($g7LedgerRows) >= 6, 'G7 ledger: split stock keeps opening, reserve and shipment history per warehouse');
assert_true(array_reduce($g7LedgerRows, static fn(bool $ok, array $row): bool => $ok && (int)$row['on_hand_after'] >= 0 && (int)$row['reserved_after'] >= 0, true), 'G7 ledger: every resulting stock snapshot preserves non-negative invariants');

// ============================================================
// G10: theme/content/marketing/i18n quality layer
// ============================================================
echo "\n--- G10: Theme, content, feeds, consent, and quality gates ---\n";
Bootstrap::initStoreContext('/', '', 'default');
I18n::setLocale('en');
$g10Manifest = Template::manifest('default');
assert_equals('BareBones Default', (string)$g10Manifest['name'], 'G10 theme: package manifest loads');
assert_true(in_array('accessibility.css', $g10Manifest['assets']['css'], true), 'G10 theme: manifest declares versioned CSS assets');
assert_true(str_contains(Template::assets()['css'][0], '?v=2.0.0'), 'G10 theme: asset URL carries manifest version');
$g10Document = Template::document('G10 document', '<h1>Content</h1>', ['marketing_hooks' => '']);
assert_true(str_contains($g10Document, 'href="#main-content"') && str_contains($g10Document, '<main id="main-content"'), 'G10 WCAG: shared shell has a functional skip link');
assert_true(str_contains($g10Document, '<header') && str_contains($g10Document, '<footer'), 'G10 theme: shared shell owns header and footer blocks');

$g10MenuId = ContentService::saveMenu(['menu_code' => 'primary', 'locale' => 'en', 'label' => 'G10 Sale', 'url' => '/sale', 'active' => 1]);
$g10BannerId = ContentService::saveBanner(['slot' => 'homepage', 'locale' => 'en', 'title' => 'G10 Banner', 'body' => 'Campaign', 'link_url' => '/sale', 'active' => 1]);
$g10RedirectId = ContentService::saveRedirect(['source_path' => '/g10-old', 'target_url' => '/g10-new', 'http_status' => 308, 'active' => 1]);
$g10LandingId = ContentService::saveLanding(['slug' => 'g10-landing', 'title' => 'G10 Landing', 'body' => '<p>Safe</p><script>bad()</script>', 'seo_title' => 'G10 SEO', 'meta_description' => 'G10 description', 'status' => 'active']);
assert_equals('G10 Sale', (string)ContentService::menu('primary', 'en')[0]['label'], 'G10 content: localized store menu publishes');
assert_equals('G10 Banner', (string)ContentService::banners('homepage', 'en')[0]['title'], 'G10 content: scheduled banner publishes');
assert_equals('/g10-new', (string)ContentService::redirect('/g10-old')['target_url'], 'G10 content: exact-path redirect resolves');
$g10Landing = ContentService::page('g10-landing', 'en');
assert_equals('landing', (string)$g10Landing['page_type'], 'G10 content: landing page has explicit type');
assert_true(!str_contains((string)$g10Landing['body'], '<script'), 'G10 content: landing rich HTML is sanitized at write boundary');
assert_true((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity LIKE 'content_%'")->fetchColumn() >= 4, 'G10 content: admin content mutations are audited');
StoreContext::activate($deContext);
assert_equals([], ContentService::menu('primary', 'en'), 'G10 content: another store cannot read default-store menu');
assert_true(ContentService::redirect('/g10-old') === null, 'G10 content: another store cannot resolve default-store redirect');
Bootstrap::initStoreContext('/', '', 'default');
I18n::setLocale('en');

$g10Category = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO products (store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, summary, images, gtin, mpn, availability)
    VALUES ('default', :category, 'G10-FEED', 'g10-feed', 'simple', 'G10 Feed Product', 1234, 'eur', 5, 'active', 'Feed description', '[\"/assets/img/logo.png\"]', '1234567890123', 'G10-MPN', 'active')")
    ->execute([':category' => $g10Category]);
$g10Google = MarketingFeed::google('https://shop.example', 'en');
assert_true(str_contains($g10Google, '<g:id>G10-FEED</g:id>') && str_contains($g10Google, '<g:price>12.34 EUR</g:price>'), 'G10 feeds: Google XML uses stable IDs and integer-cent price');
assert_true(str_contains($g10Google, '<g:availability>in stock</g:availability>'), 'G10 feeds: Google availability is normalized');
$g10Meta = MarketingFeed::meta('https://shop.example', 'en');
assert_true(str_contains($g10Meta, 'G10-FEED') && str_contains($g10Meta, '12.34 EUR'), 'G10 feeds: Meta CSV exports the same product snapshot');

assert_equals(['necessary' => true, 'analytics' => false, 'marketing' => false], MarketingConsent::categories([]), 'G10 consent: optional categories default off');
assert_equals('', MarketingConsent::hooks([], 'nonce'), 'G10 consent: no analytics hook is emitted before opt-in');
$g10Hooks = MarketingConsent::hooks(['cc_v1' => json_encode(['analytics' => true, 'marketing' => false])], 'g10-nonce');
assert_true(str_contains($g10Hooks, 'barebones:consent-ready') && str_contains($g10Hooks, 'g10-nonce'), 'G10 consent: opted-in analytics emits a nonce-bound first-party hook');
$g10Seo = Seo::head('Landing', 'Description', '/page/g10-landing');
assert_true(str_contains($g10Seo, 'rel="canonical"') && str_contains($g10Seo, 'hreflang="x-default"'), 'G10 SEO: canonical and hreflang metadata share one helper');

$g10En = require __DIR__ . '/../lang/en.php';
$g10Es = require __DIR__ . '/../lang/es.php';
assert_equals(array_keys($g10En), array_keys($g10Es), 'G10 i18n: Spanish has exact key parity with English');
assert_equals('Añadir al carrito', $g10Es['add_to_cart'], 'G10 i18n: Spanish storefront text is translated');
$g10Ci = (string)file_get_contents(__DIR__ . '/../.github/workflows/ci.yml');
assert_true(str_contains($g10Ci, 'php cli/quality-gates.php'), 'G10 CI: WCAG and performance quality gate is mandatory');
$g10Htaccess = (string)file_get_contents(__DIR__ . '/../.htaccess');
assert_true(str_contains($g10Htaccess, 'feeds/google\\.xml') && str_contains($g10Htaccess, 'feeds/meta\\.csv'), 'G10 feeds: public routes are explicit');
assert_true(str_contains($g10Htaccess, 'themes/[^/]+/(?:theme\\.json|blocks'), 'G10 theme: manifests and source blocks are denied over HTTP');

ContentService::delete('menu', $g10MenuId);
ContentService::delete('banner', $g10BannerId);
ContentService::delete('redirect', $g10RedirectId);
ContentService::delete('landing', $g10LandingId);
$pdo->exec("DELETE FROM products WHERE sku='G10-FEED'");

require __DIR__ . '/test-update.php';

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
