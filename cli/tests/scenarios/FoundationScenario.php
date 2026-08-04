<?php

// TEST 0: CLI and webroot security boundaries
// ============================================================
echo "--- Test 0: CLI and webroot security boundaries ---\n";

$cliGuardPath = TEST_CLI_DIR . '/cli-only.php';
$cliGuardSource = file_get_contents($cliGuardPath);
assert_true($cliGuardSource !== false, 'CLI guard: shared guard exists');
assert_true(str_contains((string)$cliGuardSource, "['cli', 'phpdbg']"), 'CLI guard: command-line SAPIs are allowlisted');
assert_true(str_contains((string)$cliGuardSource, 'http_response_code(403);'), 'CLI guard: HTTP rejection returns 403');
assert_true(preg_match('/\bexit\s*\(1\)\s*;/', (string)$cliGuardSource) === 1, 'CLI guard: rejected invocation exits non-zero');

$cliEntrypoints = glob(TEST_CLI_DIR . '/*.php') ?: [];
sort($cliEntrypoints);
foreach ($cliEntrypoints as $cliEntrypoint) {
    if (basename($cliEntrypoint) === basename($cliGuardPath)) continue;
    $source = str_replace("\r\n", "\n", (string)file_get_contents($cliEntrypoint));
    $guardPrefix = "<?php\nrequire_once __DIR__ . '/cli-only.php';";
    assert_true(str_starts_with($source, $guardPrefix), 'CLI guard: ' . basename($cliEntrypoint) . ' includes guard first');
}

$htaccess = str_replace("\r\n", "\n", (string)file_get_contents(TEST_CLI_DIR . '/../.htaccess'));
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

$configExampleSource = (string)file_get_contents(TEST_CLI_DIR . '/../config.example.php');
foreach (['db_sqlite_path', 'private_cache_dir', 'jobs_dir', 'invoice_storage_dir', 'backup_storage_dir', 'backup_log_file', 'metrics_log_file', 'update_storage_dir', 'gdpr_salt_file'] as $privateConfigKey) {
    assert_true(
        preg_match("/'" . preg_quote($privateConfigKey, '/') . "'\\s*=>\\s*dirname\\(__DIR__\\)/", $configExampleSource) === 1,
        "Config: {$privateConfigKey} defaults outside the application root"
    );
}
$securitySource = (string)file_get_contents(TEST_CLI_DIR . '/../src/Security.php');
assert_true(str_contains($securitySource, "['private_cache_dir']"), 'Security: private cache is configurable');
assert_true(substr_count($securitySource, 'self::privateCacheDir($cfg)') >= 2, 'Security: rate-limit DB and admin locks use validated private cache');
assert_true(!str_contains($securitySource, "['cache_dir'] ?? __DIR__ . '/../cache'"), 'Security: public catalog cache is not used for abuse-control state');

// ============================================================
// TEST 1: Database connection
// ============================================================
echo "--- Test 1: Database Connection ---\n";

// Fresh DB for testing
$testDbPath = test_fixture_path('suite');
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
        $financialMigration = require TEST_CLI_DIR . '/../migrations/20260801141000_financial_snapshots.php';
        $financialMigration->up($my, false);
        $financialMigration->up($my, false);
        $financialColumns = (int)$my->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'financial_snapshot' AND TABLE_NAME IN ('orders','invoices')")->fetchColumn();
        assert_equals(2, $financialColumns, 'MySQL live: financial snapshot migration is applied and idempotent');
        $eventApiMigrationMysql = require TEST_CLI_DIR . '/../migrations/20260801163000_event_outbox_api_v1.php';
        $eventApiMigrationMysql->up($my, false);
        $eventApiMigrationMysql->up($my, false);
        $g4MysqlTables = (int)$my->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('event_outbox','webhook_deliveries','api_clients','api_idempotency')")->fetchColumn();
        assert_equals(4, $g4MysqlTables, 'MySQL live: G4 outbox/API migration is applied and idempotent');
        $g6MigrationMysql = require TEST_CLI_DIR . '/../migrations/20260801190000_catalog_pricing_b2b.php';
        $g6MigrationMysql->up($my, false);
        $g6MigrationMysql->up($my, false);
        $g6MysqlTables = (int)$my->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('brands','product_categories','customer_groups','companies','price_lists','price_list_prices','promotions')")->fetchColumn();
        assert_equals(7, $g6MysqlTables, 'MySQL live: G6 catalog/pricing/B2B migration is applied and idempotent');
        $g7MigrationMysql = require TEST_CLI_DIR . '/../migrations/20260801200000_inventory_ledger_fulfillment.php';
        $g7MigrationMysql->up($my, false);
        $g7MigrationMysql->up($my, false);
        $g7MysqlTables = (int)$my->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('stock_movements','suppliers','supplier_products','purchase_orders','purchase_order_items','inventory_receipts','bundle_components','shipments','shipment_items')")->fetchColumn();
        assert_equals(9, $g7MysqlTables, 'MySQL live: G7 inventory/fulfillment migration is applied and idempotent');
        $g8MigrationMysql = require TEST_CLI_DIR . '/../migrations/20260801210000_returns_credit_notes.php';
        $g8MigrationMysql->up($my, false);
        $g8MigrationMysql->up($my, false);
        $g8MysqlTables = (int)$my->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('return_labels','return_receipts','return_exchanges','order_refunds','credit_note_items')")->fetchColumn();
        assert_equals(5, $g8MysqlTables, 'MySQL live: G8 returns/refunds migration is applied and idempotent');
        $g9MigrationMysql = require TEST_CLI_DIR . '/../migrations/20260801220000_admin_identity_queue_operations.php';
        $g9MigrationMysql->up($my, false);
        $g9MigrationMysql->up($my, false);
        $g9MysqlTables = (int)$my->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('admin_accounts','admin_recovery_codes','admin_store_permissions','admin_devices','admin_sessions','queue_jobs')")->fetchColumn();
        assert_equals(6, $g9MysqlTables, 'MySQL live: G9 admin identity and queue migration is applied and idempotent');
        $g10MigrationMysql = require TEST_CLI_DIR . '/../migrations/20260801230000_theme_content_marketing.php';
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
    page_type TEXT NOT NULL DEFAULT 'content', template TEXT NOT NULL DEFAULT 'cms', sections TEXT NOT NULL DEFAULT '[]',
    seo_title TEXT NOT NULL DEFAULT '', meta_description TEXT NOT NULL DEFAULT '',
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
    relation_type TEXT NOT NULL DEFAULT 'related',
    sort_order INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id, related_product_id, relation_type),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (related_product_id) REFERENCES products(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_product_related_sort ON product_related_products(product_id, relation_type, sort_order)");

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

$pdo->exec("CREATE TABLE IF NOT EXISTS abandoned_carts (
    id INTEGER PRIMARY KEY, session_id TEXT NOT NULL UNIQUE,
    customer_email TEXT, cart_data TEXT NOT NULL DEFAULT '{}',
    created_at TEXT DEFAULT (datetime('now')), reminded_at TEXT
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_ac_email ON abandoned_carts(customer_email, reminded_at)");

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

$storeScopeMigration = require TEST_CLI_DIR . '/../migrations/20260801152000_store_context_scope.php';
$storeScopeMigration->up($pdo, true);
$productStoreColumn = array_values(array_filter($pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC), static fn(array $row): bool => $row['name'] === 'store_id'));
assert_equals("'default'", (string)($productStoreColumn[0]['dflt_value'] ?? ''), 'Store migration: new catalog rows default to the default store');
$eventApiMigration = require TEST_CLI_DIR . '/../migrations/20260801163000_event_outbox_api_v1.php';
$eventApiMigration->up($pdo, true);
$eventApiMigration->up($pdo, true);
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='event_outbox'")->fetchColumn(), 'G4 migration: transactional event outbox exists');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='api_clients'")->fetchColumn(), 'G4 migration: API client credential table exists');
$catalogPricingB2bMigration = require TEST_CLI_DIR . '/../migrations/20260801190000_catalog_pricing_b2b.php';
$catalogPricingB2bMigration->up($pdo, true);
$catalogPricingB2bMigration->up($pdo, true);
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='price_lists'")->fetchColumn(), 'G6 migration: price lists exist and migration is idempotent');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_groups'")->fetchColumn(), 'G6 migration: customer groups exist');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='companies'")->fetchColumn(), 'G6 migration: companies exist');
assert_equals((int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(), (int)$pdo->query('SELECT COUNT(*) FROM product_categories WHERE is_primary = 1')->fetchColumn(), 'G6 migration: legacy primary categories are preserved');
$inventoryMigration = require TEST_CLI_DIR . '/../migrations/20260801200000_inventory_ledger_fulfillment.php';
$inventoryMigration->up($pdo, true);
$inventoryMigration->up($pdo, true);
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='stock_movements'")->fetchColumn(), 'G7 migration: stock movement ledger exists and migration is idempotent');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipments'")->fetchColumn(), 'G7 migration: split shipment tables exist');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='purchase_orders'")->fetchColumn(), 'G7 migration: procurement minimum exists');
$adminQueueMigration = require TEST_CLI_DIR . '/../migrations/20260801220000_admin_identity_queue_operations.php';
$adminQueueMigration->up($pdo, true);
$adminQueueMigration->up($pdo, true);
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='admin_accounts'")->fetchColumn(), 'G9 migration: admin identities exist and migration is idempotent');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='queue_jobs'")->fetchColumn(), 'G9 migration: durable database queue exists');
$contentMarketingMigration = require TEST_CLI_DIR . '/../migrations/20260801230000_theme_content_marketing.php';
$contentMarketingMigration->up($pdo, true);
$contentMarketingMigration->up($pdo, true);
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='content_menu_items'")->fetchColumn(), 'G10 migration: content menus exist and migration is idempotent');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='content_banners'")->fetchColumn(), 'G10 migration: scheduled banners exist');
assert_true((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='content_redirects'")->fetchColumn(), 'G10 migration: redirect manager exists');

// ============================================================
// TEST 3: CSV Import
// ============================================================
echo "\n--- Test 3: CSV Import ---\n";

$csvFile = TEST_CLI_DIR . '/../import/test-products.csv';
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
$accountSource = file_get_contents(TEST_CLI_DIR . '/../account.php') ?: '';
assert_true(str_contains($accountSource, 'name="register_token"'), 'Account register form includes form-trap token');
assert_true(!str_contains($accountSource, 'HTTP_HOST'), 'G3: password reset URL does not trust the Host header');
assert_true(str_contains($accountSource, 'name="company_website"'), 'Account register form includes hidden honeypot field');
assert_true(str_contains($accountSource, 'account_login_account_'), 'G4: login has a per-account throttle independent of IP');
assert_true(str_contains($accountSource, 'account_forgot_recipient_'), 'G4: password reset has a per-recipient throttle independent of IP');
assert_true(str_contains($accountSource, "account.err_invalid_credentials"), 'G4: login returns a generic credential error');
$adminSourceG4 = entrypointSource('admin.php');
assert_true(str_contains($adminSourceG4, "adminRequire('customers.edit')"), 'G4: customer status mutation requires edit permission');
assert_true(str_contains($adminSourceG4, 'AND order_id = :order_id'), 'G4: invoice resend is scoped to the displayed order');
assert_true(str_contains($adminSourceG4, 'admin_email_resend_'), 'G4: admin email resend has a cooldown budget');
$workerSourceG4 = file_get_contents(TEST_CLI_DIR . '/worker.php') ?: '';
assert_true(str_contains($workerSourceG4, '$to = (string)$order[\'customer_email\']'), 'G4: order mail recipient is loaded from the order record');
assert_true(str_contains($workerSourceG4, '$allowedTemplates'), 'G4: worker mail templates are allowlisted');
assert_true(str_contains($workerSourceG4, '$template !== \'cart_reminder\''), 'G4: payload-only mail jobs are limited to cart reminders');
$mailSourceG4 = file_get_contents(TEST_CLI_DIR . '/../src/MailService.php') ?: '';
assert_true(!str_contains($mailSourceG4, "SERVER_NAME"), 'G4: mail sender does not trust the request host');
assert_true(str_contains($mailSourceG4, "FILTER_VALIDATE_EMAIL"), 'G4: sender, recipient and reply-to are validated');
foreach (['api.php', 'index.php', 'wishlist.php'] as $customerEntrypoint) {
    $entrySource = entrypointSource($customerEntrypoint);
    assert_true(str_contains($entrySource, 'Customers::isLoggedIn();'), "G4: {$customerEntrypoint} validates stale customer sessions");
}
$productEntrypoint = entrypointSource('product.php');
assert_true(str_contains($productEntrypoint, "src/Entrypoints/Storefront.php"), 'G4: product.php delegates session validation to the canonical storefront');
$apiSourceG5 = entrypointSource('api.php');
$indexSourceG5 = entrypointSource('index.php');
foreach (['checkout_global', 'checkout_session_', 'checkout_subject_', 'Security::rotateCsrfToken()'] as $checkoutGuard) {
    assert_true(str_contains($apiSourceG5, $checkoutGuard), "G5: canonical checkout uses {$checkoutGuard}");
}
foreach (['checkout_global', 'checkout_session_'] as $transportGuard) {
    assert_true(str_contains($indexSourceG5, $transportGuard), "G5: HTML transport uses {$transportGuard}");
}
assert_true(str_contains($indexSourceG5, 'CheckoutService::execute('), 'G1 convergence: HTML checkout delegates to application service');
assert_true(!str_contains($indexSourceG5, 'apiCheckout('), 'G1 convergence: storefront does not depend on the JSON transport');
assert_true(!str_contains($indexSourceG5, 'Orders::create('), 'G1 convergence: storefront has no independent order creation path');
$entrypointRoot = TEST_CLI_DIR . '/../src/Entrypoints';
foreach ([
    'Storefront/Layout.php', 'Storefront/CatalogPages.php', 'Storefront/CheckoutPages.php',
    'Admin/Shared.php', 'Admin/Catalog.php', 'Admin/Orders.php', 'Admin/Customers.php', 'Admin/Operations.php', 'Admin/Request.php',
] as $boundaryModule) {
    assert_true(is_file($entrypointRoot . '/' . $boundaryModule), "G5 boundaries: {$boundaryModule} exists");
}
$storefrontOrchestrator = (string)file_get_contents($entrypointRoot . '/Storefront.php');
$adminOrchestrator = (string)file_get_contents($entrypointRoot . '/Admin.php');
assert_true(preg_match('/^function\s+/m', $storefrontOrchestrator) !== 1, 'G5 boundaries: storefront orchestrator defines no page or checkout functions');
assert_true(preg_match('/^function\s+/m', $adminOrchestrator) !== 1, 'G5 boundaries: admin orchestrator defines no context handlers');
assert_true(str_contains((string)file_get_contents($entrypointRoot . '/Storefront/CheckoutPages.php'), 'CheckoutService::execute('), 'G5 boundaries: HTML checkout transport owns canonical checkout delegation');
assert_true(str_contains((string)file_get_contents($entrypointRoot . '/Admin/Request.php'), 'handlePost('), 'G5 boundaries: admin POST dispatch has an explicit request boundary');
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
$html = Template::process('{{#each items}}{{name}}{{/each}}', [
    'items' => [['name' => '{{{secret}}}']],
    'secret' => '<b>must not render</b>',
]);
assert_equals('&#123;&#123;&#123;secret&#125;&#125;&#125;', $html, '#each escaped values cannot become template directives');

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

$testCacheDir = TEST_CLI_DIR . '/../cache/test_' . getmypid();
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
Cache::setDir(TEST_CLI_DIR . '/../cache');

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
$_SESSION['cart'] = [1 => 2];
$legacyCart = Cart::get();
assert_equals(['product_id' => 1, 'variant_id' => null, 'qty' => 2], $legacyCart['p_1'], 'Cart normalizes the legacy storefront session shape for API checkout');
$_SESSION['cart'] = $cart;

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
assert_equals('create', $logs[0]['action'], 'Latest audit action is returned first');
assert_equals('update', $logs[1]['action'] ?? $logs[0]['action'], 'Earlier audit action follows');

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
try { JobQueue::setDir(TEST_CLI_DIR . '/../jobs/unsafe'); } catch (RuntimeException $e) { $jobPathRejected = true; }
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
assert_true(with_expected_structured_log('admin.login_failed', static fn() => AdminIdentity::authenticate('g9-operator', 'Test-password-1234', Totp::code($enrollment['secret']), '127.0.0.1', 'G9 Test Device')) === null, 'G9 identity: rotated password rejects the previous credential');
$login = AdminIdentity::authenticate('g9-operator', 'Rotated-password-5678', Totp::code($enrollment['secret']), '127.0.0.1', 'G9 Test Device');
assert_true($login !== null, 'G9 identity: rotated password authenticates with MFA');
assert_true(AdminIdentity::storeAllows($login['store_permissions'], 'default', 'orders.ship'), 'G9 identity: wildcard permission allows the granted store action');
assert_true(!AdminIdentity::storeAllows($login['store_permissions'], 'other', 'orders.ship'), 'G9 identity: another store fails closed');
AdminIdentity::revokeSession($login['session_id']);
assert_true(AdminIdentity::session($login['token']) === null, 'G9 identity: revoked session cannot be reused');
$recoveryLogin = AdminIdentity::authenticate('g9-operator', 'Rotated-password-5678', $enrollment['recovery_codes'][0], '127.0.0.1', 'G9 Recovery Device');
assert_true($recoveryLogin !== null, 'G9 identity: recovery code authenticates once');
AdminIdentity::revokeSession($recoveryLogin['session_id']);
assert_true(with_expected_structured_log('admin.login_failed', static fn() => AdminIdentity::authenticate('g9-operator', 'Test-password-1234', $enrollment['recovery_codes'][0], '127.0.0.1', 'G9 Recovery Device')) === null, 'G9 identity: used recovery code cannot replay');
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
