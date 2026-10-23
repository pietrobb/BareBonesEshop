<?php

// Audit round 4: a store that shares another store's catalog (shipped cz: url_prefix /cs, czk, catalog_scope default)
// was broken end to end. Stock was bound to the store id while products are bound to the catalog (checkout always
// failed with stock_changed), cart amounts in the product currency were formatted in the store currency, gateway
// return/notify URLs pointed at the default store, the worker's cache rebuild mixed catalogs, the ERP sync only ran
// for the default store (orders without items, a failing order blocking the batch, cancelled orders pushed, a
// watermark lost on cache flush) and cli/gdpr-export.php could not export a customer of another store.
echo "\n--- Audit round 4: non-default store with a shared catalog end to end ---\n";
require_once dirname(TEST_CLI_DIR) . '/adapters/PaymentAdapter.php';

$r4Root = test_fixture_path('r4-multistore');
mkdir($r4Root . '/sessions', 0700, true);
mkdir($r4Root . '/cache', 0700, true);
$r4Fixture = $r4Root . '/request.php';
file_put_contents($r4Fixture, <<<'PHP'
<?php
[, $phase, $action, $root, $target, $dir, $extra] = $argv;
require $root . '/src/autoload.php';
require_once $root . '/adapters/PaymentAdapter.php';
$db = new PDO('sqlite:' . $target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($db, 'sqlite');
Cache::setDir($dir . '/cache');
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate.lock'))->apply();
    $category = $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)');
    $category->execute(['default', 'r4-cat', 'R4 category', 'r4-cat']);
    $categoryId = (int)$db->lastInsertId();
    $category->execute(['r4-other', 'r4-foreign-cat', 'R4 foreign category', 'r4-foreign-cat']);
    $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, currency, stock, status) VALUES ('default', ?, 'R4-SKU', 'r4-product', 'R4 Product', 24585, 'eur', 0, 'active')")->execute([$categoryId]);
    $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, currency, stock, status) VALUES ('r4-other', ?, 'R4-FOREIGN', 'r4-foreign', 'R4 Foreign', 100, 'eur', 0, 'active')")->execute([$categoryId]);
    // R9: the cz store sells the shared EUR catalog only at its CZK price list (Pricing::soldInStore).
    $db->exec("INSERT INTO price_lists (store_id, code, name, currency, customer_group_id, priority, active) VALUES ('cz', 'r4-czk', 'R4 CZK', 'CZK', NULL, 0, 1)");
    $db->prepare("INSERT INTO price_list_prices (price_list_id, product_id, variant_id, min_qty, price) SELECT ?, id, NULL, 1, 614600 FROM products WHERE sku = 'R4-SKU'")->execute([(int)$db->lastInsertId()]);
    // Stock is booked in the default store (the catalog owner): the cz store must sell from the same warehouse.
    StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), '/', ''));
    Warehouse::setStock((int)Warehouse::defaultWarehouse()['id'], 'R4-SKU', 5);
    Warehouse::syncProductStock(['R4-SKU']);
    exit(0);
}
JobQueue::useBackend(new DatabaseQueue($db, 30));
if ($action === 'gdpr') {
    // The real CLI script (it exits with its own code), against the fixture DB.
    (new ReflectionProperty(GdprService::class, 'cachedSalt'))->setValue(null, 'r4-test-salt');
    $argv = ['gdpr-export.php', ...json_decode($extra, true)];
    require $root . '/cli/gdpr-export.php';
}
$config =is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
$config = ['app_url' => 'https://shop.example'] + $config;
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_id('r4multistore');
session_start();
$_SERVER['REMOTE_ADDR'] = '127.0.4.4';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$input = json_decode($extra, true) ?: [];
$result = static function (array $payload): never { exit("\nR4RESULT" . json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)); };
$state = static fn(): array => [
    'stock' => $db->query("SELECT store_id, qty, reserved FROM warehouse_stock WHERE sku = 'R4-SKU'")->fetchAll(),
    'orders' => $db->query('SELECT id, order_number, store_id, currency, total, payment_status FROM orders ORDER BY id')->fetchAll(),
];
if ($action === 'rebuild') {
    // The real worker handler, in the cz store a queued job carries in _store_id.
    $argv = ['worker.php', '--once'];
    require_once $root . '/cli/worker.php';
    Bootstrap::initStoreContext('/', '', 'cz');
    handleCacheRebuild(['scope' => 'full'], []);
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/cache', FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[] = substr($file->getPathname(), strlen($dir . '/cache/'));
    }
    sort($files);
    $result(['files' => $files, 'prices' => json_decode((string)Cache::read('prices/guest.json'), true)]);
}
if ($action === 'api_card') {
    // Records what the gateway is asked to redirect/notify to.
    $gateway = new class(['mock_result' => 'success']) extends MockPayment {
        public static array $order = [];
        public function createSession(array $order): array { self::$order = $order; return parent::createSession($order); }
    };
    Adapters::set('payment', $gateway);
    Bootstrap::initLocale('/cs/');
    Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R4-SKU'")->fetchColumn(), null, 1);
    $run = CheckoutService::execute(['name' => 'R4 Card', 'email' => 'r4-card@example.test', 'phone' => '+420777000444', 'street' => 'Vinohradska 4',
        'city' => 'Praha', 'zip' => '12000', 'country' => 'CZ', 'csrf_token' => Security::csrfToken(), 'payment_method' => 'card'] + $input, $config);
    $result(['run' => $run, 'gateway' => array_intersect_key($gateway::$order, array_flip(['success_url', 'cancel_url', 'notify_url', 'currency'])), 'state' => $state()]);
}
$_SERVER['REQUEST_METHOD'] = $action === 'checkout_post' ? 'POST' : 'GET';
$_SERVER['REQUEST_URI'] = '/cs/?page=' . ($action === 'done' ? 'order-done' : ($action === 'cart' ? 'cart' : 'checkout'));
$_GET = ['page' => $action === 'done' ? 'order-done' : ($action === 'cart' ? 'cart' : 'checkout')];
$_POST = [];
if ($action === 'cart') {
    $_SESSION = [];
    Bootstrap::initLocale('/cs/');
    Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R4-SKU'")->fetchColumn(), null, 1);
} elseif ($action === 'checkout_post') {
    $_POST = ['name' => 'R4 Buyer', 'email' => 'r4-buyer@example.test', 'phone' => '+420777000444', 'street' => 'Vinohradska 4', 'city' => 'Praha',
        'zip' => '12000', 'country' => 'CZ', 'checkout' => '1', 'csrf_token' => Security::csrfToken()] + $input;
} elseif ($action === 'done') {
    $order = Orders::byId((int)$input['order_id']);
    $_GET += ['order' => (string)$order['order_number'], 'confirmation_token' => CheckoutSession::createConfirmation((int)$order['id'], CheckoutSession::browserSessionId())];
}
ob_start();
register_shutdown_function(static function () use ($state): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR4RESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'state' => $state()], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$r4Db = $r4Root . '/shop.sqlite';
$r4Run = static function (string $phase, string $action, array $input = []) use ($r4Fixture, $r4Db, $r4Root): array {
    $process = proc_open([PHP_BINARY, $r4Fixture, $phase, $action, dirname(TEST_CLI_DIR), $r4Db, $r4Root, json_encode($input)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R4RESULT');
    $decoded = $at === false ? null : json_decode(strtok(substr($raw, $at + 8), "\n"), true);
    return [$exit, is_array($decoded) ? $decoded : [], trim($at === false ? substr($raw, 0, 1200) : substr($raw, 0, min($at, 1200)))];
};
try {
    [$r4Exit, , $r4Noise] = $r4Run('setup', 'none');
    assert_equals([0, ''], [$r4Exit, $r4Noise], 'R4 multistore: fixture migrated with a default-catalog product stocked in the default store');

    // 3. Cart amounts are in the cart currency, and the runtime config carries it with its decimals. Since R9 the
    // cz store sells the EUR product at its CZK price list (6 146 Kč), never as the catalog's 245,85 €.
    [, $r4Cart, $r4Noise] = $r4Run('run', 'cart');
    $r4Html = (string)($r4Cart['output'] ?? '');
    assert_true(preg_match('~6\D{0,8}146 Kč~u', $r4Html) === 1 && !str_contains($r4Html, '245,85'), 'R4 currency: the /cs/ cart shows the line at the CZK price list price, not the EUR catalog price (' . $r4Noise . ')');
    assert_true(str_contains($r4Html, '"currency":"CZK","currencyDecimals":0'),
        'R4 currency: the storefront runtime config formats checkout amounts in CZK with 0 decimals');

    // 1. Stock: the checkout page offers shipping and the order reserves the shared warehouse's stock.
    [, $r4Page] = $r4Run('run', 'checkout');
    preg_match('~name="shipping_method" value="([^"]+)" checked~', (string)($r4Page['output'] ?? ''), $r4Ship);
    assert_true(!empty($r4Ship[1]) && str_contains((string)$r4Page['output'], 'name="payment" value="bank_transfer"'), 'R4 checkout: the /cs/ checkout offers shipping and bank transfer');
    [, $r4Placed, $r4Noise] = $r4Run('run', 'checkout_post', ['shipping_method' => $r4Ship[1] ?? '', 'payment' => 'bank_transfer']);
    $r4Order = $r4Placed['state']['orders'][0] ?? [];
    assert_true(count($r4Placed['state']['orders'] ?? []) === 1 && ($r4Order['store_id'] ?? '') === 'cz' && ($r4Order['currency'] ?? '') === 'czk',
        'R4 stock: a guest bank-transfer order is placed in the cz store, not refused with stock_changed (' . substr(strip_tags((string)($r4Placed['output'] ?? '')), 0, 200) . $r4Noise . ')');
    assert_equals([['store_id' => 'default', 'qty' => 5, 'reserved' => 1]], $r4Placed['state']['stock'] ?? null, 'R4 stock: the cz order reserves from the catalog owner\'s warehouse');
    [, $r4Done] = $r4Run('run', 'done', ['order_id' => (int)($r4Order['id'] ?? 0)]);
    assert_true(str_contains((string)($r4Done['output'] ?? ''), (string)($r4Order['order_number'] ?? '-')), 'R4 checkout: the /cs/ order-done page verifies and shows the cz order');

    // 2. Gateway URLs carry the order's store prefix: return pages find the store-scoped order, webhooks its scope.
    [, $r4Card, $r4Noise] = $r4Run('run', 'api_card', ['shipping_method' => $r4Ship[1] ?? '']);
    $r4Gateway = (array)($r4Card['gateway'] ?? []);
    assert_true(!empty($r4Card['run']['success']) && str_starts_with((string)($r4Gateway['success_url'] ?? ''), 'https://shop.example/cs/?page=order-done&order=')
        && str_starts_with((string)($r4Gateway['cancel_url'] ?? ''), 'https://shop.example/cs/?page=checkout-cancel&order=')
        && ($r4Gateway['notify_url'] ?? '') === 'https://shop.example/cs/api/v1/webhooks/payment/stripe',
        'R4 payment: success, cancel and notify URLs of a cz order use the /cs store base (' . json_encode($r4Gateway) . $r4Noise . ')');
    assert_equals(['store_id' => 'default', 'qty' => 5, 'reserved' => 2], $r4Card['state']['stock'][0] ?? null, 'R4 stock: a second cz order reserves the next unit');

    // 4. The worker's full cache rebuild in the cz store writes only its languages and its catalog.
    [, $r4Rebuild, $r4Noise] = $r4Run('run', 'rebuild');
    $r4Files = (array)($r4Rebuild['files'] ?? []);
    assert_true(in_array('stores/cz/storefront/cs/cat/r4-cat/page-1.json', $r4Files, true) && !preg_grep('~r4-foreign-cat~', $r4Files)
        && !preg_grep('~^stores/(?!cz/)~', $r4Files) && !preg_grep('~/de/~', $r4Files),
        'R4 cache rebuild: category pages of the cz catalog only, in the cz namespace and languages (' . implode(', ', array_slice($r4Files, 0, 8)) . $r4Noise . ')');
    assert_equals(['R4-SKU'], array_keys((array)($r4Rebuild['prices'] ?? [])), 'R4 cache rebuild: the price cache holds the cz catalog\'s products only');

    // 6. cli/gdpr-export.php exports a customer of a non-default store, in that store; --store picks among duplicates.
    $r4Fx = new PDO('sqlite:' . $r4Db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r4Customer = $r4Fx->prepare("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES (?, ?, '!r4!', ?, 'active')");
    $r4Customer->execute(['cz', 'r4-cz@example.test', 'R4 Cz Only']);
    $r4Fx = null;
    $r4Gdpr = static function (array $args) use ($r4Fixture, $r4Db, $r4Root): array {
        $process = proc_open([PHP_BINARY, $r4Fixture, 'run', 'gdpr', dirname(TEST_CLI_DIR), $r4Db, $r4Root, json_encode($args)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        [$out, $err] = [stream_get_contents($pipes[1]), stream_get_contents($pipes[2])];
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), json_decode((string)$out, true), trim((string)$err)];
    };
    [$r4Code, $r4Export, $r4Err] = $r4Gdpr(['--email=r4-cz@example.test']);
    assert_true($r4Code === 0 && ($r4Export['customer']['name'] ?? '') === 'R4 Cz Only', "R4 GDPR: a cz customer found by e-mail is exported, not 'Customer not found' ({$r4Err})");
    [$r4Code, , $r4Err] = $r4Gdpr(['--email=r4-cz@example.test', '--store=default']);
    assert_true($r4Code === 1 && str_contains($r4Err, 'No customer found'), 'R4 GDPR: --store narrows the lookup to that store');
    [$r4Code, $r4Export, $r4Err] = $r4Gdpr(['--customer-id=' . (int)($r4Export['customer']['id'] ?? 0), '--store=cz']);
    assert_true($r4Code === 0 && ($r4Export['customer']['name'] ?? '') === 'R4 Cz Only', "R4 GDPR: --customer-id of a cz customer is exported in its store ({$r4Err})");
    $r4Fx = new PDO('sqlite:' . $r4Db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assert_equals(['cz', 'cz'], $r4Fx->query("SELECT store_id FROM gdpr_events WHERE action = 'export' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN), 'R4 GDPR: the exports are audited in the customer\'s store');
    $r4Fx = null;
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r4Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $r4File) {
        $r4File->isDir() ? @rmdir($r4File->getPathname()) : @unlink($r4File->getPathname());
    }
    @rmdir($r4Root);
}

// In-process: stock model and data migration, webhook order lookup, multi-store ERP sync (fresh migrated DB).
[$r4SavedDb, $r4SavedDriver, $r4SavedContext] = [Db::get(), Db::driver(), StoreContext::current()];
$r4StoresProperty = new ReflectionProperty(Bootstrap::class, 'stores');
$r4SavedStores = $r4StoresProperty->getValue();
$r4AdaptersProperty = new ReflectionProperty(Adapters::class, 'instances');
$r4SavedAdapters = $r4AdaptersProperty->getValue();
$r4Lock = test_fixture_path('r4-migrate') . '.lock';
$r4Mem = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    $r4Stores = Bootstrap::stores();
    $r4Stores['stores']['r4x'] = array_replace($r4Stores['stores']['cz'], ['id' => 'r4x', 'url_prefix' => '/r4x', 'catalog_scope' => 'default', 'provider_scope' => 'r4x']);
    $r4StoresProperty->setValue(null, $r4Stores);
    (new Migrator($r4Mem, TEST_CLI_DIR . '/../migrations', $r4Lock))->apply(static fn($m) => null);
    Db::init($r4Mem, 'sqlite');
    $r4In = static fn(string $store, callable $fn): mixed => PaymentWebhookRouting::within($store, $fn);
    StoreContext::activate(StoreContext::resolve($r4Stores, '/', '', 'default'));
    $r4Mem->exec("INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES ('default', 'r4m', 'R4M', NULL, 'r4m', 0)");
    $r4Mem->exec("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', 1, 'R4M-SKU', 'r4m-sku', 'R4M', 1000, 0, 'active')");
    $r4Main = (int)Warehouse::defaultWarehouse()['id'];
    Warehouse::setStock($r4Main, 'R4M-SKU', 4);

    // 1. Every store sharing the catalog sees, reserves and releases the same stock; admin pages list the warehouses.
    [$r4CzAvailable, $r4CzWarehouses, $r4Line] = $r4In('cz', static fn(): array => [
        Warehouse::totalAvailable('R4M-SKU'), array_map('intval', array_column(Warehouse::all(), 'id')),
        Inventory::reserveLine(['product_id' => 1, 'variant_id' => null, 'sku' => 'R4M-SKU'], 3),
    ]);
    assert_equals([4, [$r4Main], 3, $r4Main], [$r4CzAvailable, $r4CzWarehouses, $r4Line['reserved_qty'], (int)($r4Line['allocations'][0]['warehouse_id'] ?? 0)],
        'R4 stock: the cz store sees the catalog\'s warehouse and stock and reserves from it');
    assert_equals([1, 1], [Warehouse::totalAvailable('R4M-SKU'), (int)$r4Mem->query("SELECT stock FROM products WHERE sku = 'R4M-SKU'")->fetchColumn()], 'R4 stock: the default store sees the cz reservation (products.stock mirrors it)');
    $r4In('cz', static fn() => Warehouse::release('R4M-SKU', $r4Line['allocations']));
    assert_equals(4, Warehouse::totalAvailable('R4M-SKU'), 'R4 stock: releasing the cz reservation frees the shared stock');

    // Migration: a warehouse a shared-catalog store created under its own id moves to the catalog.
    $r4Mem->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('cz', 'r4-cz-wh', 'R4 CZ', 5, 1)");
    $r4CzWh = (int)$r4Mem->lastInsertId();
    $r4Mem->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('cz', {$r4CzWh}, 'R4M-SKU', 6, 1)");
    (require TEST_CLI_DIR . '/../migrations/20261017093000_catalog_stock_erp_retry.php')->up($r4Mem, true);
    assert_equals([['store_id' => 'default', 'is_default' => 0]], array_map(static fn(array $w): array => ['store_id' => $w['store_id'], 'is_default' => (int)$w['is_default']], $r4Mem->query("SELECT store_id, is_default FROM warehouses WHERE id = {$r4CzWh}")->fetchAll()),
        'R4 migration: the cz warehouse moves to the catalog, keeping the catalog\'s default warehouse');
    assert_equals([9, 9], [Warehouse::totalAvailable('R4M-SKU'), (int)$r4Mem->query("SELECT stock FROM products WHERE sku = 'R4M-SKU'")->fetchColumn()], 'R4 migration: its stock counts for the catalog and products.stock is refreshed');
    // Round 5: MySQL runs it without a transaction; a rerun after a failure between the warehouse and the stock move
    // (warehouses moved, stock rows still under cz) still moves the stock rows.
    $r4Mem->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('cz', {$r4CzWh}, 'R5M-LEFT', 2, 0)");
    (require TEST_CLI_DIR . '/../migrations/20261017093000_catalog_stock_erp_retry.php')->up($r4Mem, true);
    assert_equals(['default'], $r4Mem->query("SELECT store_id FROM warehouse_stock WHERE sku = 'R5M-LEFT'")->fetchAll(PDO::FETCH_COLUMN),
        'R5 migration: a rerun moves stock rows left behind after the warehouses moved');

    // 2. Stripe events (one global secret) find an order of a store in another provider scope; other providers do not.
    $r4Mem->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id) VALUES ('R4-X', 'pending', 'pending', 'R4', 'r4@example.test', 'card', 100, 100, 'czk', 'r4x')");
    $r4Locate = static function (string $provider): string {
        try { return PaymentWebhookRouting::locate(['order_number' => 'R4-X'], $provider)['store_id']; } catch (PaymentWebhookUnresolved $e) { return 'unresolved'; }
    };
    assert_equals(['r4x', 'unresolved'], [$r4Locate('stripe'), $r4Locate('payu')], 'R4 webhook: a Stripe event locates the order of another provider scope; a PayU event stays in its scope');
    $r4Mem->exec('DELETE FROM orders');

    // 5. ERP sync of every store: orders with items, backoff, no cancelled orders, DB watermark, one catalog pull.
    if (!class_exists('R4ErpAdapter', false)) {
        final class R4ErpAdapter implements IntegrationAdapter {
            public array $pushed = [];
            public int $productPulls = 0;
            public ?string $since = null;
            public array $failing = [];
            public function pushOrder(array $order): string {
                if (in_array($order['order_number'], $this->failing, true)) throw new RuntimeException('ERP down for ' . $order['order_number']);
                $this->pushed[$order['order_number']] = [$order['store_id'], array_column($order['items'] ?? [], 'sku')];
                return 'r4-erp-' . $order['id'];
            }
            public function pullOrderStatus(string $externalId): string { return 'confirmed'; }
            public function pushCustomer(array $customer): string { return ''; }
            public function healthCheck(): bool { return true; }
            public function pullProducts(?\DateTimeInterface $since = null): iterable { $this->productPulls++; $this->since = $since?->format('c'); return []; }
            public function pushProduct(array $product): void {}
            public function pullStock(): iterable { return []; }
        }
    }
    $r4Erp = new R4ErpAdapter();
    $r4AdaptersProperty->setValue(null, ['integration' => $r4Erp] + (array)$r4SavedAdapters);
    $r4Order = $r4Mem->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id, created_at)
        VALUES (?, ?, 'paid', 'R4', 'r4@example.test', 'card', 1000, 1000, 'eur', ?, ?)");
    $r4Item = $r4Mem->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, 1, 'R4M-SKU', 'R4M', 1, 1000, 1000)");
    foreach ([['R4-FAIL', 'paid', 'cz', '2026-01-01 00:00:00'], ['R4-CZ', 'paid', 'cz', '2026-01-02 00:00:00'], ['R4-CANCEL', 'cancelled', 'cz', '2026-01-03 00:00:00'], ['R4-DEF', 'processing', 'default', '2026-01-04 00:00:00']] as $r4Row) {
        $r4Order->execute($r4Row);
        $r4Item->execute([(int)$r4Mem->lastInsertId()]);
    }
    $r4Erp->failing = ['R4-FAIL'];
    $r4Before = gmdate('c');
    $r4Sync = Sync::runAllStores();
    assert_equals(['R4-DEF' => ['default', ['R4M-SKU']], 'R4-CZ' => ['cz', ['R4M-SKU']]], $r4Erp->pushed, 'R4 ERP: orders of every store are pushed with their items; a cancelled order never reaches the ERP');
    assert_equals([1, 1, 2], [$r4Erp->productPulls, $r4Sync['orders_failed'] ?? null, $r4Sync['orders_pushed'] ?? null], 'R4 ERP: products are pulled once per run, not per store; the failure is counted');
    $r4Failed = $r4Mem->query("SELECT erp_attempts, erp_next_retry_at, erp_error FROM orders WHERE order_number = 'R4-FAIL'")->fetch();
    assert_true((int)$r4Failed['erp_attempts'] === 1 && (string)$r4Failed['erp_next_retry_at'] > gmdate('Y-m-d H:i:s') && str_contains((string)$r4Failed['erp_error'], 'ERP down'),
        'R4 ERP: a failed push records the attempt and a later retry time');
    $r4Watermark = $r4In('default', static fn(): ?string => Sync::watermark());
    assert_true($r4Watermark !== null && $r4Watermark <= gmdate('c') && $r4Watermark >= $r4Before, 'R4 ERP: the watermark is the run\'s start time, stored in the database');
    @mkdir(dirname(Cache::path('.flush_marker')), 0750, true);
    Cache::flush();
    $r4Erp->pushed = [];
    $r4Order->execute(['R4-CZ2', 'paid', 'cz', '2026-01-05 00:00:00']);
    $r4Item->execute([(int)$r4Mem->lastInsertId()]);
    Sync::runAllStores();
    assert_equals([['R4-CZ2'], $r4Watermark], [array_keys($r4Erp->pushed), $r4Erp->since], 'R4 ERP: a backed-off order does not block the batch, and the watermark survives a cache flush');
    $r4Mem->exec("UPDATE orders SET erp_next_retry_at = '2000-01-01 00:00:00' WHERE order_number = 'R4-FAIL'");
    $r4Erp->failing = [];
    Sync::runAllStores();
    assert_equals(['R4-CZ2', 'R4-FAIL'], array_keys($r4Erp->pushed), 'R4 ERP: the failed order is pushed once its retry time has come');
    $r4SyncCli = (string)file_get_contents(TEST_CLI_DIR . '/sync.php');
    assert_true(str_contains($r4SyncCli, 'JobQueue::acquireWorkerLock(') && str_contains($r4SyncCli, 'Sync::runAllStores()'), 'R4 ERP: cli/sync.php holds a lock against overlapping cron runs and syncs every store');
} catch (Throwable $e) {
    assert_true(false, 'R4 in-process regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $r4AdaptersProperty->setValue(null, $r4SavedAdapters);
    $r4StoresProperty->setValue(null, $r4SavedStores);
    Db::init($r4SavedDb, $r4SavedDriver);
    StoreContext::activate($r4SavedContext);
    @unlink($r4Lock);
}
