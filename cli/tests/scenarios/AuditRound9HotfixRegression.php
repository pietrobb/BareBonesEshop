<?php

// Round 9 review (2026-10-23), blockers of the new-merchant acceptance test:
//  - cash on delivery could only ship after the order was falsely marked paid, which also dropped the amount the
//    carrier collects from the label (pending led only to paid or cancelled, shipping needed a paid order);
//  - the checkout form's JS total left out the COD surcharge: the shopper saw 24,09 € and was charged 25,09 €;
//  - an accepted coupon vanished on a checkout reload (written to the session, never read back) and the order was
//    placed at full price while the page still showed the discount.
echo "\n--- Audit round 9 (hotfix): COD fulfillment, COD total in the checkout preview, coupon after reload ---\n";

// nginx: `location ^~ /uploads/` skips the server-level regex denies, so /uploads/.env, .sql and .sqlite were public
// (probed on nginx 1.27). The uploads block repeats the dotfile and data-file denies of the server block.
$r9hNginx = str_replace("\r\n", "\n", (string)file_get_contents(dirname(TEST_CLI_DIR) . '/deploy/nginx.conf'));
$r9hUploads = preg_match('#\n    location \^~ /uploads/ \{\n(.*?)\n    \}#s', $r9hNginx, $r9hMatch) ? $r9hMatch[1] : '';
foreach (['location ~ (?:^|/)\. { return 403; }', 'location ~* \.(?:(?:sqlite3?|db)(?:-(?:wal|shm|journal))?|wal|shm|journal|sql|dump|bak|backup|log|lock|tmp|ini|ya?ml|pem|key|md|neon(?:\.dist)?)$ { return 403; }'] as $r9hDeny) {
    assert_true(str_contains($r9hNginx, "\n    " . $r9hDeny) && str_contains($r9hUploads, "\n        " . $r9hDeny),
        'R9 nginx uploads: the /uploads/ prefix location repeats the server-level deny ' . substr($r9hDeny, 0, 40));
}
require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shipments.php';
Bootstrap::initStoreContext('/', '', 'default');
$r9hPricing = (new ReflectionProperty(Pricing::class, 'config'))->getValue() ?? [];
$r9hServer = array_intersect_key($_SERVER, array_flip(['REQUEST_METHOD', 'HTTP_HOST', 'REMOTE_ADDR']));
$r9hActivePromotions = $pdo->query('SELECT id FROM promotions WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec('UPDATE promotions SET active = 0');
$r9hCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
try {
    Pricing::setConfig(['cart_rules' => [
        ['type' => 'coupon', 'active' => true, 'code' => 'R9HTEN', 'name' => 'R9H ten percent', 'discount_type' => 'percent', 'discount_value' => 10, 'stacking' => 'stack'],
    ]]);
    $pdo->exec("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('r9h-main', 'R9H Main', 310, 0, 'default')");
    $r9hWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE code = 'r9h-main'")->fetchColumn();
    // Fixed ids: an auto-assigned id follows the highest fixed id of earlier scenarios and collides with later ones.
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy) VALUES
        (98901, 'default', {$r9hCategory}, 'R9H-COD', 'r9h-cod', 'simple', 'R9H COD goods', 1000, 'eur', 0, 'active', 'deny'),
        (98902, 'default', {$r9hCategory}, 'R9H-PARCEL', 'r9h-parcel', 'simple', 'R9H parcel', 2000, 'eur', 0, 'active', 'backorder')");
    Warehouse::setStock($r9hWarehouse, 'R9H-COD', 10, 'count', 'test', 'r9h-opening');

    // 1. Cash on delivery ships from 'pending' and stays unpaid until the carrier collects; a bank transfer does not.
    $r9hCustomer = ['name' => 'R9H Customer', 'email' => 'r9h@example.test', 'phone' => '', 'address' => [],
        'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'];
    $r9hOrder = static fn(string $type): int => Orders::create([['product_id' => 98901, 'variant_id' => null, 'sku' => 'R9H-COD',
        'name' => 'R9H COD goods', 'qty' => 1, 'price' => 1000, 'attrs' => []]],
        $r9hCustomer + ['payment_method_snapshot' => json_encode(['code' => $type, 'service_type' => $type])], $type);
    $r9hCod = $r9hOrder('cod');
    $r9hCodRow = Orders::byId($r9hCod);
    assert_equals(['pending', 'pending'], [$r9hCodRow['status'], $r9hCodRow['payment_status']], 'R9 COD setup: a new cash-on-delivery order is pending and unpaid');
    assert_true(in_array('shipped', OrderWorkflow::allowedFor($r9hCodRow), true) && OrderWorkflow::adminRejection($r9hCodRow, 'shipped', false) === null,
        'R9 COD: the admin may ship an unpaid cash-on-delivery order without marking it paid');
    assert_true(Fulfillment::orderShippable($r9hCodRow), 'R9 COD: the order detail offers shipments for an unpaid cash-on-delivery order');
    OrderWorkflow::transition($r9hCod, 'shipped', 'R9 COD handover');
    $r9hCodRow = Orders::byId($r9hCod);
    assert_equals(['shipped', 'pending'], [$r9hCodRow['status'], $r9hCodRow['payment_status']],
        'R9 COD: the order ships and stays unpaid, so the label keeps the amount the carrier collects');
    $r9hBank = Orders::byId($r9hOrder('bank_transfer'));
    assert_true(OrderWorkflow::adminRejection($r9hBank, 'shipped', false) === 'invalid_transition' && !Fulfillment::orderShippable($r9hBank),
        'R9 COD: an unpaid bank-transfer order still does not ship before its payment');

    // 2. The payment methods the checkout JS loads carry the shipping the order charges with each: cash on delivery
    //    adds the carrier's surcharge (and its fee is priced on that), exactly as the server-rendered form does.
    $pdo->exec("INSERT INTO shipping_methods (code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('r9hship', 'gls', 'R9H parcel', 'parcel', 1, 0, 1)");
    $pdo->exec("INSERT INTO shipping_rates (carrier, country, zone_code, method_code, weight_from_g, weight_to_g, price, cod_surcharge, sort_order)
        VALUES ('gls', '', 'domestic', 'r9hship', 0, 999999, 400, 150, 1)");
    $pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
        VALUES ('r9hcod', 'manual', 'R9H COD', '', 'cod', 0, 0, 100, 100, NULL, 0, 1, 1), ('r9hbank', 'manual', 'R9H bank', '', 'bank_transfer', 0, 0, 0, 0, NULL, 0, 1, 2)");
    $pdo->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('r9hcod', 'SK'), ('r9hbank', 'SK')");
    Cart::clear();
    Cart::add(98902, null, 1);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['country' => 'SK', 'subtotal' => '2000'];
    $r9hRates = array_column(apiShippingRates()['methods'] ?? [], 'price', 'method_code');
    assert_equals(400, $r9hRates['r9hship'] ?? null, 'R9 COD total setup: the parcel rate the checkout lists costs 4 €');
    $_GET = ['country' => 'SK', 'currency' => 'eur', 'amount' => '2400', 'shipping' => '400', 'shipping_method' => 'r9hship'];
    $r9hMethods = array_column(apiPaymentMethods()['methods'] ?? [], null, 'code');
    $r9hVat = CheckoutService::vatContext('SK', '', 'r9hship', false);
    $r9hHtml = CheckoutService::paymentPreview(CheckoutService::availableItems(Cart::enriched($r9hVat['context'])['items']), 2000, 'SK', 'eur', 'r9hship', 400, false, 'r9hcod', null, $r9hVat);
    // R11: one COD fee — the 1,50 € carrier surcharge is part of the COD fee, the shipping stays the 4 € carriage.
    assert_equals([400, (int)$r9hHtml['shipping'], (int)$r9hHtml['selected']['fee'], 150],
        [(int)($r9hMethods['r9hcod']['shipping'] ?? -1), (int)($r9hMethods['r9hcod']['shipping'] ?? -1), (int)($r9hMethods['r9hcod']['fee'] ?? -1), (int)($r9hMethods['r9hcod']['cod_surcharge'] ?? -1)],
        'R9 COD total: the COD method the JS lists carries the 4 € carriage and the one COD fee (surcharge included) the order charges');
    assert_equals([400, 0], [(int)($r9hMethods['r9hbank']['shipping'] ?? -1), (int)($r9hMethods['r9hbank']['fee'] ?? -1)],
        'R9 COD total: other methods keep the selected rate as their shipping');

    // 3. A checkout reload (GET) prices with the coupon the form accepted and keeps it in the form it posts.
    $r9hRoot = test_fixture_path('r9-coupon-reload');
    mkdir($r9hRoot);
    file_put_contents($r9hRoot . '/reload.php', <<<'PHP'
<?php
[, $root, $dbPath, $rules] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
Pricing::setConfig(json_decode($rules, true));
ConsentService::setDefinitionsForTesting([]);
I18n::setLocale('sk');
require $root . '/src/Entrypoints/Storefront/CheckoutPages.php';
$t = static fn(string $key): string => $key;
Security::sessionStart();
$_SESSION = ['cart' => ['p_98902' => ['product_id' => 98902, 'variant_id' => null, 'qty' => 1]], 'csrf_token' => 'r9h-reload', 'checkout_coupon' => 'R9HTEN'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.9.91';
$_SERVER['HTTP_HOST'] = 'localhost';
$_POST = [];
$html = renderCheckout();
echo 'R9HRESULT' . json_encode([
    'discount' => preg_match('~id="summary-discount">([^<]*)<~', $html, $m) ? $m[1] : null,
    'coupon' => preg_match('~name="coupon_code"[^>]*value="([^"]*)"~', $html, $m) ? $m[1] : null,
]) . "\nR9HPAGE" . substr(preg_replace('~\s+~', ' ', strip_tags($html)), 0, 600);
PHP);
    // On Linux an earlier child process can have closed this database last and removed its -wal file, leaving this
    // connection writing into the unlinked one: copy the rows into the database file so the child sees them.
    $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
    $r9hProcess = proc_open([PHP_BINARY, $r9hRoot . '/reload.php', dirname(TEST_CLI_DIR), $testDbPath, json_encode((new ReflectionProperty(Pricing::class, 'config'))->getValue())],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r9hPipes);
    fclose($r9hPipes[0]);
    $r9hOut = stream_get_contents($r9hPipes[1]) . stream_get_contents($r9hPipes[2]);
    fclose($r9hPipes[1]);
    fclose($r9hPipes[2]);
    proc_close($r9hProcess);
    @unlink($r9hRoot . '/reload.php');
    @rmdir($r9hRoot);
    $r9hAt = strrpos($r9hOut, 'R9HRESULT');
    $r9hPage = $r9hAt === false ? '' : (string)strstr(substr($r9hOut, $r9hAt + 9), "\nR9HPAGE");
    $r9hReload = $r9hAt === false ? null : json_decode(strstr(substr($r9hOut, $r9hAt + 9), "\nR9HPAGE", true) ?: '', true);
    $r9hExpected = ['discount' => I18n::price(-200, 'eur'), 'coupon' => 'R9HTEN'];
    assert_equals($r9hExpected, $r9hReload, 'R9 coupon reload: the reloaded checkout shows the accepted coupon\'s discount and posts the code with the order'
        . ($r9hReload === $r9hExpected ? '' : ' (' . substr(trim(strip_tags($r9hAt === false ? $r9hOut : $r9hPage)), 0, 600) . ')'));
} finally {
    Pricing::setConfig($r9hPricing);
    $_GET = [];
    $_SERVER = array_replace($_SERVER, $r9hServer);
    Cart::clear();
    foreach ($r9hActivePromotions as $r9hPromotion) $pdo->prepare('UPDATE promotions SET active = 1 WHERE id = ?')->execute([(int)$r9hPromotion]);
    $pdo->exec("DELETE FROM payment_method_countries WHERE method_code IN ('r9hcod', 'r9hbank')");
    $pdo->exec("DELETE FROM payment_methods WHERE code IN ('r9hcod', 'r9hbank')");
    $pdo->exec("DELETE FROM shipping_rates WHERE method_code = 'r9hship'");
    $pdo->exec("DELETE FROM shipping_methods WHERE code = 'r9hship'");
}

// 4. orders_contact retention (180 days) scrubs closed orders only, leaves empty fields empty (the marker would print on
//    the packing slip) and keeps the internal lines of the note (refund id, ERP status marker).
$r9hOrderAt = static function (string $status, string $phone, string $notes) use ($pdo): int {
    $pdo->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, customer_phone, shipping_addr, payment_method,
            subtotal, total, currency, store_id, locale, ip_address, notes, created_at)
        VALUES (?, ?, 'paid', 'R9H Person', 'r9h-retention@example.test', ?, '{}', 'card', 1000, 1000, 'eur', 'default', 'sk', '198.51.100.9', ?, ?)")
        ->execute(['R9H-R-' . bin2hex(random_bytes(3)), $status, $phone, $notes, gmdate('Y-m-d H:i:s', time() - 200 * 86400)]);
    return (int)$pdo->lastInsertId();
};
$r9hClosed = $r9hOrderAt('delivered', '+421900000009', "Ring twice, R9H\nRefund: re_r9h_1\nerp_status:closed");
$r9hOpen = $r9hOrderAt('processing', '+421900000009', 'Leave at the door, R9H');
$r9hEmpty = $r9hOrderAt('cancelled', '', '');
$r9hPolicy = (array)(json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/config/data-retention.json'), true)['policies']['orders_contact'] ?? []);
GdprService::applyRetention('orders_contact', $r9hPolicy, 'apply');
$r9hContact = static fn(int $id): array => $pdo->query("SELECT ip_address, customer_phone, notes FROM orders WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
$r9hMarker = '[erased per GDPR retention at ' . gmdate('Y-m-d') . ']';
assert_equals(['ip_address' => $r9hMarker, 'customer_phone' => $r9hMarker, 'notes' => "Refund: re_r9h_1\nerp_status:closed\n{$r9hMarker}"], $r9hContact($r9hClosed),
    'R9 retention: a closed order loses IP, phone and the customer\'s note; the refund id and ERP marker stay');
assert_equals(['ip_address' => '198.51.100.9', 'customer_phone' => '+421900000009', 'notes' => 'Leave at the door, R9H'], $r9hContact($r9hOpen),
    'R9 retention: an order still in fulfilment keeps its phone and note for the delivery');
assert_equals(['ip_address' => $r9hMarker, 'customer_phone' => '', 'notes' => ''], $r9hContact($r9hEmpty),
    'R9 retention: empty phone and note stay empty (no "[erased…]" on documents)');
GdprService::applyRetention('orders_contact', $r9hPolicy, 'apply');
assert_equals("Refund: re_r9h_1\nerp_status:closed\n{$r9hMarker}", $r9hContact($r9hClosed)['notes'], 'R9 retention: a second run leaves a scrubbed note as it is');
$pdo->exec("DELETE FROM orders WHERE customer_email = 'r9h-retention@example.test'");

// 5./6. Migrations that must finish after an interruption on MySQL (DDL and every statement commit on their own there):
//  - invoice token hashing: a run stopped after the ALTER returned early on the next run, so the rest of the e-mailed
//    links (still plain tokens) stopped working;
//  - CZ/HU/PL price lists: a failed run left a half-seeded scope (skipped by the next run), and rows left from an earlier
//    own price list (methods deleted, zones and links kept) failed the seed on their unique keys and blocked `migrate`.
if (!class_exists('R9mPdo', false)) {
    final class R9mPdo extends PDO {
        public ?string $failOn = null;
        public function prepare(string $query, array $options = []): PDOStatement|false {
            if ($this->failOn !== null && str_contains($query, $this->failOn)) throw new PDOException('R9 injected failure: ' . $this->failOn);
            return parent::prepare($query, $options);
        }
    }
}
$r9mDrivers = ['sqlite' => test_fixture_path('r9-migrations.sqlite')];
if (getenv('TEST_MYSQL_DSN')) $r9mDrivers['mysql'] = 'bbe_r9m_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R9 migrations on native MySQL/MariaDB (set TEST_MYSQL_DSN)\n";
$r9mPrev = [Db::get(), Db::driver()];
foreach ($r9mDrivers as $r9mDriver => $r9mTarget) {
    $r9mSqlite = $r9mDriver === 'sqlite';
    if ($r9mSqlite) {
        $r9mDb = new R9mPdo('sqlite:' . $r9mTarget);
    } else {
        $r9mDb = new R9mPdo(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
        $r9mDb->exec("CREATE DATABASE `{$r9mTarget}`");
        $r9mDb->exec("USE `{$r9mTarget}`");
    }
    $r9mDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r9mDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    try {
        // 5. Interrupted after the ALTER and the first rows: converted rows (with an expiry) stay, the rest is finished.
        $r9mDb->exec($r9mSqlite ? 'CREATE TABLE invoices (id INTEGER PRIMARY KEY, download_token TEXT, download_token_expires_at TEXT)'
            : 'CREATE TABLE invoices (id INT PRIMARY KEY, download_token VARCHAR(64) NULL, download_token_expires_at DATETIME NULL)');
        $r9mTokens = [];
        foreach ([1, 2, 3, 4, 5, 6, 7000, 7001, 7002] as $r9mId) {
            $r9mTokens[$r9mId] = bin2hex(random_bytes(32));
            $r9mDone = $r9mId <= 3;
            $r9mDb->prepare('INSERT INTO invoices (id, download_token, download_token_expires_at) VALUES (?, ?, ?)')
                ->execute([$r9mId, $r9mDone ? hash('sha256', $r9mTokens[$r9mId]) : $r9mTokens[$r9mId], $r9mDone ? '2030-01-01 00:00:00' : null]);
        }
        $r9mDb->exec('INSERT INTO invoices (id, download_token, download_token_expires_at) VALUES (8, NULL, NULL)');
        (require dirname(TEST_CLI_DIR) . '/migrations/20261022110000_invoice_download_token_hash.php')->up($r9mDb, $r9mSqlite);
        $r9mRows = array_column($r9mDb->query('SELECT id, download_token, download_token_expires_at FROM invoices')->fetchAll(), null, 'id');
        $r9mWrong = [];
        foreach ($r9mTokens as $r9mId => $r9mToken) {
            $r9mRow = $r9mRows[$r9mId] ?? [];
            if (($r9mRow['download_token'] ?? null) !== hash('sha256', $r9mToken) || ($r9mRow['download_token_expires_at'] ?? null) === null
                || ($r9mId <= 3 && !str_starts_with((string)$r9mRow['download_token_expires_at'], '2030-01-01'))) $r9mWrong[] = $r9mId;
        }
        assert_equals([[], null, null], [$r9mWrong, array_key_exists(8, $r9mRows) ? $r9mRows[8]['download_token'] : 'missing', $r9mRows[8]['download_token_expires_at'] ?? null],
            "R9 {$r9mDriver} token migration: a re-run after an interruption hashes the remaining plain tokens once (old links work), converted rows keep theirs");
        $r9mDb->exec('DROP TABLE invoices');

        // 6. CZ price list: a run failing half-way leaves nothing behind and the next run seeds it in full.
        Db::init($r9mDb, $r9mDriver);
        (new Migrator($r9mDb, dirname(TEST_CLI_DIR) . '/migrations', test_fixture_path("r9-migrate-{$r9mDriver}.lock")))->apply();
        $r9mTables = ['shipping_methods', 'payment_methods', 'shipping_rates', 'shipping_zones', 'shipping_zone_countries', 'payment_method_countries', 'payment_method_shipping'];
        $r9mCounts = static function () use ($r9mDb, $r9mTables): array {
            $counts = [];
            foreach ($r9mTables as $table) $counts[$table] = (int)$r9mDb->query("SELECT COUNT(*) FROM {$table} WHERE store_id = 'cz'")->fetchColumn();
            return $counts;
        };
        $r9mSeeded = $r9mCounts();
        assert_true($r9mSeeded['shipping_methods'] === 3 && $r9mSeeded['payment_methods'] === 3 && $r9mSeeded['shipping_rates'] === 16,
            "R9 {$r9mDriver} price lists setup: migrate seeds the CZ scope (" . json_encode($r9mSeeded) . ')');
        $r9mPriceLists = static fn() => (require dirname(TEST_CLI_DIR) . '/migrations/20261022230000_currency_price_lists.php')->up($r9mDb, $r9mSqlite);
        foreach ($r9mTables as $r9mTable) $r9mDb->exec("DELETE FROM {$r9mTable} WHERE store_id = 'cz'");
        $r9mDb->failOn = 'INTO payment_methods';
        $r9mError = '';
        try { $r9mPriceLists(); } catch (PDOException $e) { $r9mError = $e->getMessage(); }
        $r9mDb->failOn = null;
        assert_true(str_contains($r9mError, 'R9 injected failure') && array_sum($r9mCounts()) === 0,
            "R9 {$r9mDriver} price lists: a run that fails half-way leaves no half-seeded CZ scope (" . json_encode($r9mCounts()) . ')');
        $r9mPriceLists();
        assert_equals($r9mSeeded, $r9mCounts(), "R9 {$r9mDriver} price lists: the next run seeds the whole CZ scope");

        // Rows left from an earlier own price list whose methods were deleted: kept, not duplicated, no failure.
        $r9mDb->exec("DELETE FROM shipping_methods WHERE store_id = 'cz'");
        $r9mDb->exec("DELETE FROM payment_methods WHERE store_id = 'cz'");
        $r9mError = '';
        try { $r9mPriceLists(); } catch (Throwable $e) { $r9mError = $e->getMessage(); }
        assert_equals(['', $r9mSeeded], [$r9mError, $r9mCounts()], "R9 {$r9mDriver} price lists: leftover zones, rates and links do not fail the migration and are not duplicated");
    } finally {
        Db::init(...$r9mPrev);
        if ($r9mSqlite) {
            $r9mDb = null;
            @unlink($r9mTarget);
        } else {
            $r9mDb->exec("DROP DATABASE IF EXISTS `{$r9mTarget}`");
            $r9mDb = null;
        }
        @unlink(test_fixture_path("r9-migrate-{$r9mDriver}.lock"));
    }
}
