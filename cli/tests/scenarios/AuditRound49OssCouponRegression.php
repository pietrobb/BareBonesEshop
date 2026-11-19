<?php

// Review round 19 (R49, low): the sale that passes the OSS threshold re-prices a line that keeps its net abroad
// (123 € at SK 23 % → 127 € at HU 27 %); a 10 % coupon was still taken from 123 € (12,30 € off instead of 12,70 €),
// on the page and on the order.
echo "\n--- R49 OSS threshold: a percentage coupon follows the re-priced goods (page and order) ---\n";
$r49cRoot = test_fixture_path('oss-coupon-r49');
mkdir($r49cRoot);
mkdir($r49cRoot . '/sessions');
$r49cFixture = $r49cRoot . '/request.php';
file_put_contents($r49cFixture, <<<'PHP'
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
    $db->exec("UPDATE promotions SET active = 0");
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r49c', 'R49C', 'r49c', 0)");
    $cat = (int)$db->lastInsertId();
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R49C-MUG', 'r49c-mug', 'R49C-MUG', 12300, 100, 'active', 500]);
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 100, 0)')->execute([$warehouse, 'R49C-MUG']);
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r49c-pay', 'manual', 'R49C payment', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    $db->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('default', 'r49c-post', 'r49c', 'R49C post', 'parcel', 0, 0, 1)");
    $db->exec("INSERT INTO shipping_zones (store_id, code, name, active, sort_order) VALUES ('default', 'r49c-zone', 'R49C zone', 1, 1)");
    foreach (['SK', 'HU'] as $country) {
        $db->prepare("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r49c-pay', ?)")->execute([$country]);
        $db->prepare("INSERT INTO shipping_zone_countries (store_id, zone_code, country) VALUES ('default', 'r49c-zone', ?)")->execute([$country]);
        $db->prepare("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order)
            VALUES ('default', 'r49c-post', 'r49c', ?, 'r49c-zone', 0, 999999, 2460, 0, '2', 1)")->execute([$country]);
    }
    exit(0);
}
$config = require AppConfig::path($root);
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
Pricing::setConfig(['cart_rules' => [['type' => 'coupon', 'active' => true, 'code' => 'R49TEN', 'name' => 'R49 ten percent', 'discount_type' => 'percent', 'discount_value' => 10, 'stacking' => 'stack']]]);
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.49.3';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
$stores = Bootstrap::stores();
$stores['stores']['default']['oss_enabled'] = false;
$stores['stores']['default']['legal']['country'] = 'SK';
$stores['stores']['default']['cross_border_pricing'] = 'net';
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$db->exec('DELETE FROM oss_threshold_totals');
$db->prepare("INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES ('default', ?, 'eur', 989999)")->execute([(int)ShopTime::date('Y')]);
$orders = static fn(): array => $db->query('SELECT id, total, net_total, discount_amount, vat_scenario FROM orders ORDER BY id')->fetchAll();
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R49C-MUG'")->fetchColumn(), null, 1);
$before = count($orders());
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['page' => 'checkout'];
$_POST = ['csrf_token' => Security::csrfToken()] + array_diff_key($args, ['csrf_token' => true]);
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR49CRESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/index.php';
PHP);
$r49cRun = static function(array $args) use ($r49cFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r49cFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R49CRESULT');
    return [$exit, $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true), substr($raw, 0, min($at === false ? 600 : $at, 600))];
};
$r49cApp = dirname(TEST_CLI_DIR);
$r49cTargets = [];
try {
    foreach (['sqlite', 'mysql'] as $r49cDriver) {
        if ($r49cDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R49 OSS coupon native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
        $target = $r49cDriver === 'mysql' ? 'bbe_r49c_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '_test' : $r49cRoot . "/shop-{$r49cDriver}.sqlite";
        $r49cTargets[] = [$r49cDriver, $target];
        [$exit, , $noise] = $r49cRun([$r49cDriver, 'setup', $r49cApp, $target, $r49cRoot, '{}']);
        assert_equals([0, ''], [$exit, trim($noise)], "R49 OSS coupon {$r49cDriver}: isolated migrated fixture");
        $buyer = ['country' => 'HU', 'coupon_code' => 'R49TEN', 'shipping_method' => 'r49c-post', 'payment' => 'r49c-pay'];
        [$exit, $page, $noise] = $r49cRun([$r49cDriver, 'page', $r49cApp, $target, $r49cRoot, json_encode($buyer + ['checkout_refresh' => '1'])]);
        $html = (string)($page['output'] ?? '');
        $quoted = preg_match('~name="quoted_total" value="(\d+)"~', $html, $m) ? (int)$m[1] : null;
        // 10 000 € less 100,01 € so far: the goods (100 € net, 90 € after the coupon) stay under the threshold; the shipping
        // (20 € net) passes it: HU 27 % with
        // the net kept, 127,00 €, and 10 % off it is 12,70 € (was 12,30 €, from the price before).
        $goods = preg_match('~id="checkout-subtotal" value="(\d+)"~', $html, $m) ? (int)$m[1] : null;
        assert_true($quoted !== null && $goods === 12700, "R49 OSS coupon {$r49cDriver}: the page re-prices the goods to 127,00 € (goods {$goods}, total {$quoted}; {$noise})");
        assert_true(str_contains($html, '12,70') && !str_contains($html, '12,30'), "R49 OSS coupon {$r49cDriver}: the coupon line names 12,70 €");
        [$exit, $placed, $noise] = $r49cRun([$r49cDriver, 'order', $r49cApp, $target, $r49cRoot, json_encode($buyer + ['checkout' => '1', 'quoted_total' => (string)$quoted,
            'email' => 'r49c@example.test', 'name' => 'R49 Buyer', 'phone' => '+36300000049', 'street' => 'Fő utca 1', 'city' => 'Budapest', 'zip' => '1011', 'consents' => ['terms' => '1']])]);
        $order = $placed['new_orders'][0] ?? [];
        assert_equals([1, 'oss', $quoted, 1270], [count($placed['new_orders'] ?? []), $order['vat_scenario'] ?? null, (int)($order['total'] ?? -1), (int)($order['discount_amount'] ?? -1)],
            "R49 OSS coupon {$r49cDriver}: the order charges the shown total, 12,70 € off (" . (preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', (string)($placed['output'] ?? ''), $m) ? html_entity_decode($m[1]) : $noise) . ')');
    }
} finally {
    foreach ($r49cTargets as [$r49cDriver, $target]) if ($r49cDriver === 'mysql') $r49cRun(['mysql', 'teardown', $r49cApp, $target, $r49cRoot, '{}']);
}
