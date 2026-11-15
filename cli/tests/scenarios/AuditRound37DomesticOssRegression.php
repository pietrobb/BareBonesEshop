<?php

// A foreign billing address is not a digital sale unless the basket actually contains a download.
echo "\n--- R37 domestic supply: foreign billing must not block checkout above the OSS threshold ---\n";
$r37dRoot = test_fixture_path('domestic-oss-r37');
mkdir($r37dRoot);
mkdir($r37dRoot . '/sessions');
$r37dFixture = $r37dRoot . '/request.php';
file_put_contents($r37dFixture, <<<'PHP'
<?php
[, $driver, $phase, $root, $target, $dir, $extra] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
$args = json_decode($extra, true) ?: [];
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r37d', 'R37D', 'r37d', 0)");
    $cat = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R37D-MUG', 'r37d-mug', 'R37D Mug', 12300, 100, 'active', 500]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 100, 0)')->execute([$warehouse, 'R37D-MUG']);
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r37d_pay', 'manual', 'R37D payment', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    foreach (['SK', 'AT'] as $country) $db->prepare("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r37d_pay', ?)")->execute([$country]);
    $db->exec("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order)
        VALUES ('default', 'pickup', 'pickup', 'AT', 'near_eu', 0, 999999, 0, 0, '0', 9)");
    exit(0);
}
$config = require AppConfig::path($root);
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite::memory:')); // Each case tests VAT, not the shared three-orders-per-email quota.
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.37.1';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
$stores = Bootstrap::stores();
$stores['stores']['default']['oss_enabled'] = false;
$stores['stores']['default']['legal']['country'] = 'SK';
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Bootstrap::initStoreContext('/', '', 'default');
$db->exec('DELETE FROM oss_threshold_totals');
$db->prepare("INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES ('default', ?, 'eur', ?)")
    ->execute([(int)ShopTime::date('Y') - (int)($args['previous'] ?? 0), OssThreshold::EUR_CENTS + 100]);
unset($args['previous']);
VatService::reset();
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R37D-MUG'")->fetchColumn(), null, 1);
$typed = $args + ['name' => 'R37D Kundin', 'email' => 'r37d@example.test', 'phone' => '+43660000037', 'street' => 'Ulica 1',
    'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'payment' => 'r37d_pay', 'payment_method' => 'r37d_pay',
    'billing_different' => '1', 'billing_name' => 'R37D Kundin', 'billing_country' => 'AT', 'billing_street' => 'Ring 1',
    'billing_city' => 'Wien', 'billing_zip' => '1010', 'csrf_token' => Security::csrfToken()];
$orders = static fn(): array => $db->query('SELECT id, total, vat_scenario, oss_net FROM orders ORDER BY id')->fetchAll();
$before = count($orders());
if ($phase === 'manual') {
    $result = ManualOrder::create(ManualOrder::parseLines('R37D-MUG 1')['lines'], $typed, false);
    echo 'R37DRESULT' . json_encode(['result' => $result, 'new_orders' => array_slice($orders(), $before)]);
    exit(0);
}
if ($phase === 'guard') {
    $customer = ['name' => 'R37D', 'address' => ['country' => 'SK'], 'billing_address' => ['country' => 'AT']];
    $cases = ['physical' => [['product_type' => 'simple']], 'ebook' => [['product_type' => 'digital']],
        'mixed' => [['product_type' => 'simple'], ['product_type' => 'digital']], 'digital_flag' => [['digital' => true]],
        'abroad_goods' => [['product_type' => 'simple']]];
    $result = [];
    foreach ($cases as $name => $items) {
        $snapshot = ['scenario' => $name === 'abroad_goods' ? 'domestic_subthreshold' : 'domestic', 'currency' => 'eur', 'totals' => ['net' => 10000],
            'components' => array_map(static fn($item): array => ['type' => 'product', 'net' => 5000, 'supply' => 'domestic'], $items)];
        try {
            OssThreshold::releaseOrderLock($db, OssThreshold::guardOrder($db, $snapshot, $customer, $items));
            $result[$name] = 'allowed';
        } catch (VatChangedException $e) { $result[$name] = 'vat_changed'; }
    }
    echo 'R37DRESULT' . json_encode($result);
    exit(0);
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['page' => 'checkout'];
$_POST = $typed;
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR37DRESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/index.php';
PHP);
$r37dRun = static function(array $args) use ($r37dFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r37dFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R37DRESULT');
    return [$exit, $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true), substr($raw, 0, min($at === false ? 600 : $at, 600))];
};
$r37dApp = dirname(TEST_CLI_DIR);
$r37dTargets = [];
try {
    foreach (['sqlite', 'mysql'] as $r37dDriver) {
        if ($r37dDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R37 domestic OSS native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
        $r37dTarget = $r37dDriver === 'mysql' ? 'bbe_r37d_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $r37dRoot . '/shop.sqlite';
        $r37dTargets[] = [$r37dDriver, $r37dTarget];
        [$exit, , $noise] = $r37dRun([$r37dDriver, 'setup', $r37dApp, $r37dTarget, $r37dRoot, '{}']);
        assert_equals([0, ''], [$exit, trim($noise)], "R37 domestic OSS {$r37dDriver}: isolated migrated fixture");
        foreach ([0, 1] as $previous) {
            $run = static function(string $phase, array $post) use ($r37dRun, $r37dDriver, $r37dApp, $r37dTarget, $r37dRoot, $previous): array {
                [$exit, $result, $noise] = $r37dRun([$r37dDriver, $phase, $r37dApp, $r37dTarget, $r37dRoot, json_encode($post + ['previous' => $previous])]);
                assert_true($exit === 0 && is_array($result), "R37 domestic OSS {$r37dDriver}/previous={$previous}/{$phase}: handler completes ({$noise})");
                return $result ?? [];
            };
            $guard = $run('guard', []);
            assert_equals(['physical' => 'allowed', 'ebook' => 'vat_changed', 'mixed' => 'vat_changed', 'digital_flag' => 'vat_changed', 'abroad_goods' => 'vat_changed'], $guard,
                "R37 domestic OSS {$r37dDriver}/previous={$previous}: guard exempts only baskets without an OSS supply");
            foreach (['delivery' => ['country' => 'SK', 'shipping_method' => 'gls'], 'pickup' => ['country' => 'AT', 'shipping_method' => 'pickup']] as $case => $input) {
                $page = $run('refresh', $input + ['checkout_refresh' => '1']);
                $html = (string)($page['output'] ?? '');
                $quoted = preg_match('~name="quoted_total" value="(\d+)"~', $html, $m) ? (int)$m[1] : null;
                $method = preg_match('~name="shipping_method" value="([^"]+)"[^>]*checked~', $html, $m) ? $m[1] : '';
                $consents = preg_match_all('~name="consents\[([a-z_]+)\]" value="1"~', $html, $m) ? array_fill_keys($m[1], '1') : [];
                assert_true($quoted !== null && $method === $input['shipping_method'], "R37 domestic OSS {$r37dDriver}/{$case}/previous={$previous}: checkout offers a total and the requested method ({$method})");
                $post = ['shipping_method' => $method, 'quoted_total' => (string)$quoted, 'consents' => $consents, 'checkout' => '1'] + $input;
                foreach ([1, 2] as $attempt) {
                    $ordered = $run('order', $post);
                    $order = $ordered['new_orders'][0] ?? [];
                    assert_true(count($ordered['new_orders'] ?? []) === 1 && ($order['vat_scenario'] ?? '') === 'domestic' && (int)($order['total'] ?? -1) === $quoted && (int)($order['oss_net'] ?? -1) === 0,
                        "R37 domestic OSS {$r37dDriver}/{$case}/previous={$previous}/attempt={$attempt}: placed at shown domestic VAT, no OSS turnover (" . (preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', (string)($ordered['output'] ?? ''), $m) ? html_entity_decode($m[1]) : json_encode($order)) . ')');
                }
                $manual = $run('manual', ['shipping_method' => $method] + $input);
                $order = $manual['new_orders'][0] ?? [];
                assert_true(($manual['result']['ok'] ?? false) && ($order['vat_scenario'] ?? '') === 'domestic' && (int)($order['oss_net'] ?? -1) === 0,
                    "R37 domestic OSS {$r37dDriver}/{$case}/previous={$previous}: manual order allowed (" . json_encode($manual['result'] ?? []) . ')');
            }
        }
    }
} finally {
    foreach ($r37dTargets as [$r37dDriver, $r37dTarget]) if ($r37dDriver === 'mysql') $r37dRun(['mysql', 'teardown', $r37dApp, $r37dTarget, $r37dRoot, '{}']);
}
