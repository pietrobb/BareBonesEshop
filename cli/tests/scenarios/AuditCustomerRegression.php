<?php

// Each request goes through the actual API function; public caches must not contain B2B prices.
echo "\n--- Audit A03: single-product personalized cache isolation ---\n";
require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';
$a03Root = test_fixture_path('customer-prices');
mkdir($a03Root, 0700, true);
$a03OriginalDb = Db::get();
$a03OriginalStore = StoreContext::current();
$a03OriginalGet = $_GET;
$a03OriginalSession = $_SESSION;
$a03CacheProperty = new ReflectionProperty(Cache::class, 'cacheDir');
$a03OriginalCacheDir = $a03CacheProperty->getValue();
$a03OriginalPricing = Pricing::config();
$a03SaltProperty = new ReflectionProperty(GdprService::class, 'cachedSalt');
$a03OriginalSalt = $a03SaltProperty->getValue();
$a03SaltProperty->setValue(null, 'a03-isolated-test-salt');
$a03Snapshot = new PDO('sqlite:' . $testDbPath);
$a03Snapshot->exec('VACUUM INTO ' . $a03Snapshot->quote($a03Root . '/pricing.sqlite'));
$a03Snapshot = null;
$a03Db = new PDO('sqlite:' . $a03Root . '/pricing.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($a03Db, 'sqlite');
Cache::setDir($a03Root . '/cache');
Pricing::setConfig(['customer_groups' => ['guest' => ['discount' => 0]], 'catalog_rules' => [], 'tier_pricing' => ['enabled' => false]]);
try {
    Bootstrap::initStoreContext('/', '', 'default');
    $a03Db->exec("INSERT INTO customer_groups (store_id, code, name, discount_bps) VALUES ('default', 'a03-wholesale', 'A03 Wholesale', 2000)");
    $a03Group = (int)$a03Db->lastInsertId();
    $a03Db->prepare("INSERT INTO customers (store_id, email, password_hash, name, customer_group_id, status) VALUES ('default', 'a03-b2b@example.test', '!test!', 'A03 B2B', ?, 'active')")->execute([$a03Group]);
    $a03Customer = (int)$a03Db->lastInsertId();
    $a03Db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'a03-category', 'A03 Category', 'a03-category', 0)");
    $a03Category = (int)$a03Db->lastInsertId();
    $a03Db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, currency, stock, status) VALUES ('default', ?, 'A03-PRICE', 'a03-price', 'A03 Price', 1000, 'eur', 10, 'active')")->execute([$a03Category]);
    $_GET = ['slug' => 'a03-price'];
    $a03CacheKey = Cache::localePath(I18n::locale(), 'product/a03-price.json');
    foreach ([['b2b', 'guest', 800, 1000], ['guest', 'b2b', 1000, 800]] as [$a03First, $a03Second, $a03FirstPrice, $a03SecondPrice]) {
        $a03CacheFile = Cache::path($a03CacheKey);
        if (is_file($a03CacheFile)) unlink($a03CacheFile);
        $_SESSION = $a03First === 'b2b' ? ['customer_id' => $a03Customer] : [];
        assert_equals($a03FirstPrice, apiProduct()['product']['price'], "Audit A03 {$a03First}→{$a03Second}: first actual API price");
        if ($a03First === 'b2b') assert_true(!is_file($a03CacheFile), 'Audit A03 B2B-first: personalized response never writes public cache');
        $_SESSION = $a03Second === 'b2b' ? ['customer_id' => $a03Customer] : [];
        assert_equals($a03SecondPrice, apiProduct()['product']['price'], "Audit A03 {$a03First}→{$a03Second}: second actual API price");
        assert_equals(1000, Cache::readJson($a03CacheKey)['product']['price'], 'Audit A03: public cache contains only guest price');
        $_SESSION = [];
        assert_equals(1000, apiProduct()['product']['price'], 'Audit A03: subsequent guest still receives guest price');
    }
    $a03Stores = Bootstrap::stores();
    $a03Stores['stores']['a03-isolated'] = array_replace($a03Stores['stores']['default'], ['id' => 'a03-isolated', 'url_prefix' => '/a03-isolated', 'domains' => [], 'catalog_scope' => 'a03-isolated', 'provider_scope' => 'a03-isolated']);
    StoreContext::activate(StoreContext::resolve($a03Stores, '/a03-isolated/', '', 'a03-isolated'));
    $_SESSION = ['customer_id' => $a03Customer];
    assert_equals(['error' => 'Product not found'], apiProduct(), 'Audit A03: other store cannot read cached default product or B2B price');
    $a03Db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('a03-isolated', 'a03-other-category', 'A03 Other Category', 'a03-other-category', 0)");
    $a03Category = (int)$a03Db->lastInsertId();
    $a03Db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, currency, stock, status) VALUES ('a03-isolated', ?, 'A03-OTHER', 'a03-other', 'A03 Other', 1500, 'eur', 10, 'active')")->execute([$a03Category]);
    $_GET = ['slug' => 'a03-other'];
    assert_equals(1500, apiProduct()['product']['price'], 'Audit A03: default-store customer gets no B2B discount in isolated store');
    Bootstrap::initStoreContext('/', '', 'default');
    assert_equals(['error' => 'Product not found'], apiProduct(), 'Audit A03: default store cannot read second-store cached product');

    $a03LegacyDir = $a03Root . '/legacy';
    mkdir($a03LegacyDir, 0700);
    $a03LegacyName = '20260611213000_add_stock_restored_column';
    $a03LegacyPath = $a03LegacyDir . '/' . $a03LegacyName . '.php';
    copy(dirname(TEST_CLI_DIR) . '/migrations/' . $a03LegacyName . '.php', $a03LegacyPath);
    $a03LegacyDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $a03LegacyMigrator = new Migrator($a03LegacyDb, $a03LegacyDir, $a03Root . '/legacy.lock');
    $a03LegacyMigrator->ensureMigrationsTable();
    $a03LegacyDb->prepare('INSERT INTO schema_migrations (name, checksum, duration_ms) VALUES (?, ?, 0)')->execute([$a03LegacyName, 'a4803eb5d0df8cfaade336b4d7a3d6e32c08ab4b98f5978efd97847e257ff723']);
    foreach (['a4803eb5d0df8cfaade336b4d7a3d6e32c08ab4b98f5978efd97847e257ff723', 'b6886c3132db22443fd82679686eae92bdd0d6254c9aacb888176bbd0fbaf76a'] as $a03LegacyChecksum) {
        $a03LegacyDb->prepare('UPDATE schema_migrations SET checksum = ? WHERE name = ?')->execute([$a03LegacyChecksum, $a03LegacyName]);
        assert_true($a03LegacyMigrator->status()['applied'][$a03LegacyName]['checksum_ok'], 'Audit A03: exact published stock migration checksum remains accepted');
        assert_equals([], $a03LegacyMigrator->apply(), 'Audit A03: published stock migration is not rerun after scope repair');
    }
    file_put_contents($a03LegacyPath, "\n// unapproved migration version\n", FILE_APPEND);
    $a03LegacyRejected = false;
    try { $a03LegacyMigrator->apply(); } catch (RuntimeException $e) { $a03LegacyRejected = str_contains($e->getMessage(), 'modified after being applied'); }
    assert_true($a03LegacyRejected, 'Audit A03: checksum compatibility still rejects unapproved migration changes');
    $a03LegacyMigrator = null;
    $a03LegacyDb = null;

    $a03GdprMigration = require dirname(TEST_CLI_DIR) . '/migrations/20260425033813_add_gdpr_tables.php';
    $a03GdprMigration->up($a03Db, true);
    $a03GdprChecks = static function(PDO $a03Db, int $a03Customer, bool $isSqlite): void {
    echo "\n--- Audit A03: GDPR export ownership and transactional erasure (" . ($isSqlite ? 'sqlite' : $a03Db->getAttribute(PDO::ATTR_SERVER_VERSION)) . ") ---\n";
    $a03Email = 'a03-victim@example.test';
    $a03Db->prepare("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES ('default', ?, '!test!', 'A03 Subject', 'active')")->execute([$a03Email]);
    $a03Subject = (int)$a03Db->lastInsertId();
    $a03Db->prepare("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES ('a03-isolated', ?, '!test!', 'A03 Other Subject', 'active')")->execute(['a03-other@example.test']);
    $a03OtherSubject = (int)$a03Db->lastInsertId();
    $a03OrderTemplate = $a03Db->query('SELECT * FROM orders LIMIT 1')->fetch();
    unset($a03OrderTemplate['id']);
    $a03OrderIds = [];
    $a03MailIds = [];
    foreach ([
        'owned' => ['default', $a03Subject],
        'guest' => ['default', null],
        'other-customer' => ['default', $a03Customer],
        'foreign-guest' => ['a03-isolated', null],
        'foreign-linked' => ['a03-isolated', $a03Subject],
    ] as $a03Case => [$a03Store, $a03Owner]) {
        $a03Row = array_replace($a03OrderTemplate, ['order_number' => 'A03-' . $a03Case, 'store_id' => $a03Store, 'customer_id' => $a03Owner, 'customer_email' => strtoupper($a03Email)]);
        $a03Db->prepare('INSERT INTO orders (' . implode(',', array_keys($a03Row)) . ') VALUES (' . implode(',', array_fill(0, count($a03Row), '?')) . ')')->execute(array_values($a03Row));
        $a03OrderIds[$a03Case] = (int)$a03Db->lastInsertId();
        $a03Db->prepare("INSERT INTO email_log (store_id, order_id, recipient, template, subject, payload) VALUES (?, ?, ?, 'order_created', ?, '{}')")->execute([$a03Store, $a03OrderIds[$a03Case], $a03Email, 'PII ' . $a03Case]);
        $a03MailIds[$a03Case] = (int)$a03Db->lastInsertId();
        $a03Db->prepare("INSERT INTO coupon_uses (store_id, order_id, coupon_code, customer_email, discount) VALUES (?, ?, 'A03', ?, 1)")->execute([$a03Store, $a03OrderIds[$a03Case], $a03Email]);
    }
    foreach (['default', 'a03-isolated'] as $a03Store) {
        $a03Db->prepare("INSERT INTO email_log (store_id, recipient, template, subject, payload) VALUES (?, ?, 'password_reset', 'PII unlinked', '{}')")->execute([$a03Store, $a03Email]);
        $a03MailIds['unlinked-' . $a03Store] = (int)$a03Db->lastInsertId();
        $a03Db->prepare("INSERT INTO audit_log (store_id, entity, entity_id, action, old_value, new_value, actor, ip_address) VALUES (?, 'customer', ?, 'update', ?, ?, 'a03-test', '127.0.0.1')")->execute([$a03Store, $a03Subject, $a03Email, $a03Email]);
    }
    $a03Db->prepare("INSERT INTO customer_addresses (customer_id, name, street, city, zip, country) VALUES (?, 'A03 Name', 'A03 Street', 'A03 City', '12345', 'SK')")->execute([$a03Subject]);
    $a03Db->prepare("INSERT INTO cookie_consent (customer_id, categories_json, method) VALUES (?, '{}', 'preferences')")->execute([$a03Subject]);
    $a03Export = GdprService::exportCustomer($a03Subject);
    assert_equals([$a03OrderIds['owned']], array_map(static fn(array $o): int => (int)$o['order']['id'], $a03Export['orders']), 'Audit A03 GDPR unverified: only customer-owned order, no email takeover');
    assert_equals([$a03MailIds['owned']], array_map('intval', array_column($a03Export['email_log'], 'id')), 'Audit A03 GDPR unverified: only owned-order email log');
    assert_equals(1, count($a03Export['addresses']), 'Audit A03 GDPR: own address exported');
    assert_equals(1, count($a03Export['consent_history']), 'Audit A03 GDPR: own consent exported');
    assert_true(!isset($a03Export['customer']['password_hash']), 'Audit A03 GDPR: password hash redacted');
    $a03Db->prepare("UPDATE customers SET email_verified_at = '2026-10-12 00:00:00' WHERE id = ?")->execute([$a03Subject]);
    $a03Export = GdprService::exportCustomer($a03Subject);
    assert_equals([$a03OrderIds['owned'], $a03OrderIds['guest']], array_map(static fn(array $o): int => (int)$o['order']['id'], $a03Export['orders']), 'Audit A03 GDPR verified: local guest order allowed, other-customer and store excluded');
    assert_equals([$a03MailIds['owned'], $a03MailIds['guest'], $a03MailIds['unlinked-default']], array_map('intval', array_column($a03Export['email_log'], 'id')), 'Audit A03 GDPR verified: email log matches local ownership policy');
    $a03ForeignRejected = false;
    try { GdprService::exportCustomer($a03OtherSubject); } catch (RuntimeException $e) { $a03ForeignRejected = true; }
    assert_true($a03ForeignRejected, 'Audit A03 GDPR: foreign customer export rejected');
    $a03ForeignRejected = false;
    try { GdprService::anonymizeCustomer($a03OtherSubject); } catch (RuntimeException $e) { $a03ForeignRejected = true; }
    assert_true($a03ForeignRejected, 'Audit A03 GDPR: foreign customer erasure rejected');
    $a03BeforeCustomer = $a03Db->query('SELECT * FROM customers WHERE id = ' . $a03Subject)->fetch();
    $a03Db->exec($isSqlite ? "CREATE TRIGGER a03_erase_fault BEFORE UPDATE ON email_log BEGIN SELECT RAISE(ABORT, 'a03 injected erasure failure'); END" : "CREATE TRIGGER a03_erase_fault BEFORE UPDATE ON email_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a03 injected erasure failure'");
    $a03Rollback = false;
    try { GdprService::anonymizeCustomer($a03Subject); } catch (PDOException $e) { $a03Rollback = str_contains($e->getMessage(), 'a03 injected erasure failure'); }
    $a03Db->exec('DROP TRIGGER a03_erase_fault');
    assert_true($a03Rollback, 'Audit A03 GDPR: injected mid-erasure error propagates');
    assert_equals($a03BeforeCustomer, $a03Db->query('SELECT * FROM customers WHERE id = ' . $a03Subject)->fetch(), 'Audit A03 GDPR: failed erasure rolls customer back');
    assert_equals(1, (int)$a03Db->query('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ' . $a03Subject)->fetchColumn(), 'Audit A03 GDPR: failed erasure rolls addresses back');
    $a03Result = GdprService::anonymizeCustomer($a03Subject);
    assert_equals('anonymized', $a03Result['status'], 'Audit A03 GDPR: successful erasure commits');
    assert_equals('!disabled!', $a03Db->query('SELECT password_hash FROM customers WHERE id = ' . $a03Subject)->fetchColumn(), 'Audit A03 GDPR: erased account password disabled');
    assert_equals(0, (int)$a03Db->query('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ' . $a03Subject)->fetchColumn(), 'Audit A03 GDPR: addresses removed');
    foreach (['owned', 'guest', 'unlinked-default'] as $a03Case) {
        assert_equals('deleted-' . $a03Subject . '@anonymized.local', $a03Db->query('SELECT recipient FROM email_log WHERE id = ' . $a03MailIds[$a03Case])->fetchColumn(), "Audit A03 GDPR: local owned email {$a03Case} anonymized");
    }
    foreach (['other-customer', 'foreign-guest', 'foreign-linked', 'unlinked-a03-isolated'] as $a03Case) {
        assert_equals($a03Email, $a03Db->query('SELECT recipient FROM email_log WHERE id = ' . $a03MailIds[$a03Case])->fetchColumn(), "Audit A03 GDPR: unrelated email {$a03Case} preserved");
    }
    assert_equals($a03Email, $a03Db->query("SELECT old_value FROM audit_log WHERE store_id = 'a03-isolated' AND entity = 'customer' AND entity_id = " . $a03Subject)->fetchColumn(), 'Audit A03 GDPR: foreign audit PII preserved');
    assert_equals(3, (int)$a03Db->query("SELECT COUNT(*) FROM coupon_uses WHERE coupon_code = 'A03' AND customer_email = " . $a03Db->quote($a03Email))->fetchColumn(), 'Audit A03 GDPR: unrelated coupon emails preserved');
    assert_equals(strtoupper($a03Email), $a03Db->query('SELECT customer_email FROM orders WHERE id = ' . $a03OrderIds['owned'])->fetchColumn(), 'Audit A03 GDPR: legally retained order PII preserved');
    assert_equals('already_anonymized', GdprService::anonymizeCustomer($a03Subject)['status'], 'Audit A03 GDPR: repeated erasure idempotent');
    };
    $a03GdprChecks($a03Db, $a03Customer, true);
    if (getenv('TEST_MYSQL_DSN')) {
        $a03Native = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $a03NativeDatabase = 'bbe_gdpr_' . bin2hex(random_bytes(6)) . '_test';
        $a03Native->exec("CREATE DATABASE {$a03NativeDatabase}");
        try {
            $a03Native->exec("CREATE DATABASE {$a03NativeDatabase}_peer_test");
            $a03Native->exec("CREATE TABLE {$a03NativeDatabase}_peer_test.orders (id INT PRIMARY KEY, stock_restored INT NOT NULL DEFAULT 0)");
            $a03Native->exec("USE {$a03NativeDatabase}");
            Db::init($a03Native, 'mysql');
            (new Migrator($a03Native, dirname(TEST_CLI_DIR) . '/migrations', $a03Root . '/native-migrations.lock'))->apply();
            assert_equals(1, (int)$a03Native->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='stock_restored'")->fetchColumn(), 'Audit A03: native stock migration ignores same-named columns in another schema');
            assert_equals([], (new Migrator($a03Native, dirname(TEST_CLI_DIR) . '/migrations', $a03Root . '/native-migrations.lock'))->apply(), 'Audit A03: complete native migration set remains idempotent');
            $a03Native->exec("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES ('default', 'a03-b2b@example.test', '!test!', 'A03 B2B', 'active')");
            $a03NativeCustomer = (int)$a03Native->lastInsertId();
            $a03Native->exec("INSERT INTO orders (order_number, customer_name, customer_email, payment_method, subtotal, total) VALUES ('A03-seed', 'Seed', 'seed@example.test', 'card', 1000, 1000)");
            $a03GdprChecks($a03Native, $a03NativeCustomer, false);
        } finally {
            Db::init($a03Db, 'sqlite');
            $a03Native->exec("DROP DATABASE IF EXISTS {$a03NativeDatabase}_peer_test");
            $a03Native->exec("DROP DATABASE {$a03NativeDatabase}");
            $a03Native = null;
        }
    } else {
        echo "  [SKIP] Audit A03 native MySQL/MariaDB GDPR ownership and erasure (set TEST_MYSQL_DSN with disposable DB/create-database permission)\n";
    }
} finally {
    Db::init($a03OriginalDb, 'sqlite');
    StoreContext::activate($a03OriginalStore);
    $a03CacheProperty->setValue(null, $a03OriginalCacheDir);
    Pricing::setConfig($a03OriginalPricing);
    $a03SaltProperty->setValue(null, $a03OriginalSalt);
    $_GET = $a03OriginalGet;
    $_SESSION = $a03OriginalSession;
    $a03Db = null;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a03Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $a03File) {
        $a03File->isDir() ? rmdir($a03File->getPathname()) : unlink($a03File->getPathname());
    }
    rmdir($a03Root);
}
