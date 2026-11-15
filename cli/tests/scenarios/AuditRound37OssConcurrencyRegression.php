<?php

// Independent connections freeze their quotes before a barrier; no mocked threshold or order writer.
echo "\n--- R37 OSS: fourteen concurrent orders and a contended seller lock ---\n";
$r37cDir = test_fixture_path('oss-concurrency-r37');
mkdir($r37cDir);
mkdir($r37cDir . '/sessions');
$r37cWorker = $r37cDir . '/worker.php';
file_put_contents($r37cWorker, <<<'PHP'
<?php
[, $root, $dir, $driver, $target, $phase, $worker] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new ShopPDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit; }
    $db->exec("USE `{$target}`");
} else { if ($phase === 'setup') $db->exec('PRAGMA journal_mode = WAL'); $db->exec('PRAGMA busy_timeout = 5000'); $db->exec('PRAGMA synchronous = NORMAL'); $db->exec('PRAGMA foreign_keys = ON'); }
Db::init($db, $driver);
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r37c', 'R37C', 'r37c', 0)");
    $cat = (int)$db->lastInsertId();
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    for ($n = 0; $n < 14; $n++) {
        $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['default', $cat, 'R37C-' . $n, 'r37c-' . $n, 'R37C Mug', 12300, 100, 'active', 500]);
        $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 100, 0)')->execute([$warehouse, 'R37C-' . $n]);
    }
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r37c_pay', 'manual', 'R37C payment', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    $db->exec("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r37c_pay', 'AT')");
    $year = (int)ShopTime::date('Y');
    $count = max(1, min(200000, (int)(getenv('R37_OSS_BENCH_ORDERS') ?: 100)));
    $order = $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, net_total, currency, status, payment_status, payment_method, store_id, vat_scenario, oss_net, created_at)
        VALUES (?, 'R37C history', 'history@example.test', '{\"country\":\"AT\"}', 1, 1, 1, 'eur', 'delivered', 'paid', 'bank_transfer', 'default', 'oss', 0, ?)");
    $invoice = $db->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type, oss_net)
        VALUES (?, 'default', ?, ?, '', 1, 0, 1, 'eur', 'issued', ?, 'oss', 'invoice', 1)");
    $db->beginTransaction();
    for ($n = 0; $n < $count; $n++) {
        $order->execute(['R37C-H' . $n, $year . '-01-02']);
        $invoice->execute([(int)$db->lastInsertId(), 'R37C-H' . $n, $year . '-01-03', 'r37c-h' . $n]);
    }
    $db->commit();
    echo json_encode(['history' => $count]); exit;
}
$config = require AppConfig::path($root);
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.37.0.' . ((int)$worker + 1);
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
$stores = Bootstrap::stores();
$stores['stores']['default']['oss_enabled'] = false;
$stores['stores']['default']['legal']['country'] = 'SK';
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Bootstrap::initStoreContext('/', '', 'default');
if ($phase === 'prepare') {
    $db->exec('DELETE FROM oss_threshold_totals');
    $db->prepare("INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES ('default', ?, 'eur', ?)")
        ->execute([(int)ShopTime::date('Y'), $worker === 'near' ? 990000 : 1100000]);
    exit;
}
$id = $db->prepare('SELECT id FROM products WHERE sku = ?');
$id->execute(['R37C-' . $worker]);
$productId = (int)$id->fetchColumn(); $id->closeCursor(); Cart::add($productId, null, 1);
$vat = CheckoutService::vatContext('AT', '', 'gls', false);
$items = array_values(CheckoutService::availableItems(Cart::enriched($vat['context'])['items']));
$snapshot = Adapters::tax($vat['tax_provider'])::buildSnapshot($items, $vat['context'], ['currency' => 'eur', 'shipping' => 0, 'payment_fee' => 0, 'discount' => 0]);
file_put_contents($dir . '/' . $driver . '-' . $phase . '-' . $worker . '.ready', '1');
$deadline = microtime(true) + 45;
while (!is_file($dir . '/' . $driver . '-' . $phase . '.go')) {
    if (microtime(true) > $deadline) throw new RuntimeException('Barrier timed out');
    usleep(10000);
}
$start = microtime(true);
if ($phase === 'near') {
    try {
        $id = Orders::create($items, ['name' => 'R37C Buyer', 'email' => 'r37c' . $worker . '@example.test', 'phone' => '', 'address' => ['country' => 'AT'], 'currency' => 'eur', 'financial_snapshot' => $snapshot], 'bank_transfer');
        echo json_encode(['result' => 'placed', 'scenario' => Orders::byId($id)['vat_scenario'], 'id' => $id, 'ms' => (microtime(true) - $start) * 1000]);
    } catch (VatChangedException $e) {
        echo json_encode(['result' => 'vat_changed', 'ms' => (microtime(true) - $start) * 1000]);
    }
    exit;
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['page' => 'checkout'];
$consents = array_fill_keys(array_keys(CheckoutService::consentDefinitions('AT', $items)), '1');
$_POST = ['name' => 'R37C Buyer', 'email' => 'r37c-http' . $worker . '@example.test', 'phone' => '+43660000037', 'street' => 'Ring 1', 'city' => 'Wien', 'zip' => '1010', 'country' => 'AT',
    'shipping_method' => 'gls', 'payment' => 'r37c_pay', 'payment_method' => 'r37c_pay', 'checkout' => '1', 'consents' => $consents, 'csrf_token' => Security::csrfToken()];
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($db, $worker, $start): void {
    $out = ''; while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    $query = $db->prepare('SELECT id, vat_scenario FROM orders WHERE customer_email = ?');
    $query->execute(['r37c-http' . $worker . '@example.test']);
    echo json_encode(['code' => http_response_code(), 'orders' => $query->fetchAll(), 'ms' => (microtime(true) - $start) * 1000, 'error' => substr(strip_tags($out), -600)]);
});
require $root . '/index.php';
PHP);
$r37cStart = static function(string $driver, string $target, string $phase, string $worker) use ($r37cWorker, $r37cDir): array {
    $out = $r37cDir . '/' . $driver . '-' . $phase . '-' . $worker . '.out';
    $err = $out . '.err';
    $process = proc_open([PHP_BINARY, $r37cWorker, dirname(TEST_CLI_DIR), $r37cDir, $driver, $target, $phase, $worker], [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes);
    fclose($pipes[0]);
    return [$process, $out, $err];
};
$r37cJoin = static function(array $process): array {
    [$proc, $out, $err] = $process;
    $code = proc_close($proc);
    return [$code, json_decode((string)file_get_contents($out), true), (string)file_get_contents($err)];
};
foreach (['sqlite', 'mysql'] as $r37cDriver) {
    if ($r37cDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { echo "  [SKIP] R37 concurrency native MySQL\n"; continue; }
    $r37cTarget = $r37cDriver === 'mysql' ? 'bbe_r37c_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $r37cDir . '/shop.sqlite';
    try {
        [$exit, $result, $err] = $r37cJoin($r37cStart($r37cDriver, $r37cTarget, 'setup', '0'));
        assert_true($exit === 0, "R37 concurrency {$r37cDriver}: isolated schema ({$err})");
        if ($exit !== 0) continue;
        foreach (['near', 'past'] as $r37cPhase) {
            [$exit, , $err] = $r37cJoin($r37cStart($r37cDriver, $r37cTarget, 'prepare', $r37cPhase));
            assert_true($exit === 0, "R37 concurrency {$r37cDriver}/{$r37cPhase}: threshold fixture ({$err})");
            $processes = [];
            for ($n = 0; $n < 14; $n++) $processes[] = $r37cStart($r37cDriver, $r37cTarget, $r37cPhase, (string)$n);
            $deadline = microtime(true) + 40;
            do { $ready = count(glob($r37cDir . '/' . $r37cDriver . '-' . $r37cPhase . '-*.ready') ?: []); if ($ready === 14) break; usleep(10000); } while (microtime(true) < $deadline);
            assert_equals(14, $ready, "R37 concurrency {$r37cDriver}/{$r37cPhase}: all quotes frozen before any submit");
            $lockDb = null; $lock = null;
            if ($r37cDriver === 'mysql' && $r37cPhase === 'past') {
                $lockDb = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '');
                $store = Bootstrap::stores()['stores']['default'];
                $seller = (new ReflectionMethod(OssThreshold::class, 'sellerKey'))->invoke(null, 'default', $store);
                $lock = 'bbe_oss_' . substr(hash('sha256', $seller), 0, 40);
                $stmt = $lockDb->prepare('SELECT GET_LOCK(?, 1)'); $stmt->execute([$lock]);
                assert_equals(1, (int)$stmt->fetchColumn(), 'R37 concurrency MySQL: deliberately hold the threshold lock during destination-priced HTTP checkout');
            }
            file_put_contents($r37cDir . '/' . $r37cDriver . '-' . $r37cPhase . '.go', '1');
            $results = [];
            foreach ($processes as $n => $process) {
                [$exit, $result, $err] = $r37cJoin($process);
                assert_true($exit === 0 && is_array($result), "R37 concurrency {$r37cDriver}/{$r37cPhase}/{$n}: process completed ({$err})");
                $results[] = $result ?? [];
            }
            if ($lockDb !== null) { $stmt = $lockDb->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$lock]); }
            if ($r37cPhase === 'near') {
                assert_equals(1, count(array_filter($results, static fn(array $r): bool => ($r['result'] ?? '') === 'placed' && ($r['scenario'] ?? '') === 'domestic_subthreshold')), "R37 concurrency {$r37cDriver}: only one home-priced order reaches exactly the threshold");
                assert_equals(13, count(array_filter($results, static fn(array $r): bool => ($r['result'] ?? '') === 'vat_changed')), "R37 concurrency {$r37cDriver}: all thirteen stale quotes are refused before charging");
            } else {
                foreach ($results as $n => $result) assert_true(($result['code'] ?? 503) < 400 && count($result['orders'] ?? []) === 1 && ($result['orders'][0]['vat_scenario'] ?? '') === 'oss', "R37 concurrency {$r37cDriver}/HTTP/{$n}: order placed under OSS, no 503 (" . json_encode($result) . ')');
            }
            echo 'R37 concurrency timings ' . $r37cDriver . '/' . $r37cPhase . ': ' . json_encode(array_column($results, 'ms')) . "\n";
        }
    } finally {
        if ($r37cDriver === 'mysql') $r37cJoin($r37cStart($r37cDriver, $r37cTarget, 'teardown', '0'));
    }
}
