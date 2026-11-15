<?php

// R37 / goal465: a selected pickup changes the goods VAT, not just the delivery fee.
// Isolated real storefront/API requests on SQLite and native MySQL; optional Chromium exercises the shipped JS.
echo "\n--- R37 selected shipping refresh: reverse-charge courier <-> domestic pickup ---\n";
$r37sRoot = test_fixture_path('shipping-refresh-r37');
mkdir($r37sRoot);
mkdir($r37sRoot . '/sessions');
$r37sApp = dirname(TEST_CLI_DIR);
$r37sFixture = $r37sRoot . '/request.php';
file_put_contents($r37sFixture, <<<'PHP'
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
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit; }
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r37s', 'R37S', 'r37s', 0)");
    $cat = (int)$db->lastInsertId();
    $db->exec("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) SELECT id, 'R37S', 50, 0 FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1");
    $db->exec("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g, inventory_policy) VALUES ('default', {$cat}, 'R37S', 'r37s', 'R37S lamp', 12300, 50, 'active', 500, 'deny')");
    $db->exec("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order) VALUES ('default', 'pickup', 'pickup', 'AT', 'near_eu', 0, 999999, 0, 0, '0', 9)");
    $db->exec("UPDATE shipping_rates SET price = 0, cod_surcharge = 0 WHERE country = 'AT' OR zone_code = 'near_eu'");
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order) VALUES ('default', 'r37s_pay', 'manual', 'R37S payment', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    $db->exec("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r37s_pay', 'AT')");
    $db->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES ('ATU37373737', 'AT', 'valid', 'R37S GmbH', ?, 86400)")->execute([gmdate('Y-m-d H:i:s')]);
    exit;
}
$config = require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate-' . $driver . '.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.37.5';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R37S'")->fetchColumn(), null, 1);
$args = json_decode($extra, true) ?: [];
$orders = static fn(): array => $db->query('SELECT total, vat_scenario, shipping_method FROM orders ORDER BY id')->fetchAll();
$before = count($orders());
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level()) $out = ob_get_clean() . $out;
    echo "\nR37SRESULT" . json_encode(['output' => $out, 'orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE);
});
if ($phase === 'api') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $args + ['country' => 'AT', 'currency' => 'eur', 'vat_id' => 'ATU37373737', 'amount' => '10000', 'subtotal' => '10000', 'shipping' => '0'];
    require_once $root . '/src/Http/ApiHandlers.php';
    echo json_encode(($args['action'] ?? '') === 'shipping_rates' ? apiShippingRates() : apiPaymentMethods());
} else {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['page' => 'checkout'];
    $_POST = $args + ['name' => 'R37S Buyer', 'email' => 'r37s-' . $phase . '@example.test', 'phone' => '+43660000037', 'street' => 'Ring 1', 'city' => 'Wien', 'zip' => '1010', 'country' => 'AT', 'company' => 'R37S GmbH', 'vat_id' => 'ATU37373737', 'payment' => 'r37s_pay', 'csrf_token' => Security::csrfToken()];
    require $root . '/index.php';
}
PHP);
$r37sRun = static function(string $driver, string $phase, string $target, array $args = []) use ($r37sFixture, $r37sApp, $r37sRoot): array {
    $p = proc_open([PHP_BINARY, $r37sFixture, $driver, $phase, $r37sApp, $target, $r37sRoot, json_encode($args)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($p);
    $at = strrpos($raw, 'R37SRESULT');
    $result = $at === false ? ['output' => $raw, 'orders' => []] : json_decode(substr($raw, $at + 10), true);
    assert_true($exit === 0 && is_array($result) && !str_contains($raw, 'Fatal error'), "R37 shipping {$driver}/{$phase}: real handler completes ({$exit})");
    return $result ?? ['output' => $raw, 'orders' => []];
};
$r37sTargets = ['sqlite' => $r37sRoot . '/checkout.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r37sTargets['mysql'] = 'bbe_r37s_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
try {
    foreach ($r37sTargets as $driver => $target) {
        $r37sRun($driver, 'setup', $target);
        $rates = json_decode($r37sRun($driver, 'api', $target, ['action' => 'shipping_rates'])['output'], true);
        $codes = array_column($rates['methods'] ?? [], 'method_code');
        $courier = (string)(array_values(array_diff($codes, ['pickup']))[0] ?? '');
        assert_true($courier !== '' && in_array('pickup', $codes, true), "R37 {$driver}: Austria offers both pickup and courier");
        $pages = $payments = [];
        foreach ([$courier => 'reverse_charge', 'pickup' => 'domestic'] as $method => $scenario) {
            $payments[$method] = json_decode($r37sRun($driver, 'api', $target, ['shipping_method' => $method])['output'], true);
            assert_equals($scenario, $payments[$method]['vat_scenario'] ?? '', "R37 {$driver}/{$method}: payment API gives the selected method's VAT scenario");
            $page = $r37sRun($driver, 'refresh', $target, ['shipping_method' => $method, 'checkout_refresh' => '1']);
            $pages[$method] = $page['output'];
            $total = $method === 'pickup' ? 12300 : 10000;
            assert_true($page['orders'] === [] && str_contains($page['output'], 'data-vat-scenario="' . $scenario . '"') && str_contains($page['output'], 'name="quoted_total" value="' . $total . '"'), "R37 {$driver}/{$method}: refresh renders {$total} / {$scenario} without placing an order");
        }
        preg_match_all('~name="consents\[([a-z_]+)\]" value="1"~', $pages['pickup'], $m);
        $order = ['checkout' => '1', 'shipping_method' => 'pickup', 'consents' => array_fill_keys($m[1], '1')];
        $stale = $r37sRun($driver, 'stale', $target, $order + ['quoted_total' => '10000']);
        assert_true($stale['orders'] === [] && str_contains($stale['output'], 'name="quoted_total" value="12300"'), "R37 {$driver}: no-JS stale courier quote refuses pickup and shows 123 EUR");
        $placed = $r37sRun($driver, 'order', $target, $order + ['quoted_total' => '12300']);
        assert_true(count($placed['orders']) === 1 && (int)$placed['orders'][0]['total'] === 12300 && $placed['orders'][0]['vat_scenario'] === 'domestic', "R37 {$driver}: refreshed pickup quote orders at exactly 123 EUR domestic (" . (preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', (string)$placed['output'], $m) ? html_entity_decode($m[1]) : json_encode($placed['orders'])) . ')');
        if (getenv('R37_NODE')) {
            $payload = json_encode(['source' => file_get_contents($r37sApp . '/themes/default/assets/storefront.js'), 'pages' => $pages, 'rates' => $rates, 'payments' => $payments, 'courier' => $courier, 'playwright' => getenv('R37_PLAYWRIGHT') ?: '@playwright/test']);
            $script = 'const fixture = ' . $payload . ";\n" . file_get_contents($r37sApp . '/tests/e2e/r37-shipping-refresh.cjs');
            $p = proc_open([getenv('R37_NODE'), '-'], [0 => ['pipe', 'r'], 1 => ['file', $r37sRoot . '/browser-' . $driver . '.log', 'w'], 2 => ['file', $r37sRoot . '/browser-' . $driver . '.log', 'a']], $pipes);
            fwrite($pipes[0], $script); fclose($pipes[0]);
            $exit = proc_close($p);
            $log = file_get_contents($r37sRoot . '/browser-' . $driver . '.log');
            echo $log;
            assert_equals(0, $exit, "R37 {$driver}: Chromium selected-method refresh regression");
        } else {
            echo "  [SKIP] R37 Chromium: set R37_NODE and optionally R37_PLAYWRIGHT\n";
        }
    }
} finally {
    if (isset($r37sTargets['mysql'])) $r37sRun('mysql', 'teardown', $r37sTargets['mysql']);
}
