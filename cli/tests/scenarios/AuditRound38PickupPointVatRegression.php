<?php
$pdo ??= test_commerce_fixture();

// Review round 12 (R38, medium 2): ShippingService::isPickupMethod() took every method of service_type "pickup" for
// collection in the seller's store, so a carrier's pick-up point in Austria (Packeta, Z-BOX) was supplied in Slovakia:
// Slovak VAT for a consumer, no reverse charge for a business with a valid ATU, and the sale left out of the OSS
// threshold. Goods handed over at a carrier's point are supplied where the point is; only in-store pickup is at home.
echo "\n--- R38 a carrier pick-up point abroad is not in-store pickup for VAT ---\n";

assert_true(!ShippingService::isPickupMethod('packeta') && ShippingService::isPickupMethod('pickup'),
    'R38 pickup point: the seeded Packeta method is no in-store pickup, the seeded personal pickup is');

$r38pRoot = test_fixture_path('pickup-point-r38');
mkdir($r38pRoot);
mkdir($r38pRoot . '/sessions');
$r38pFixture = $r38pRoot . '/request.php';
file_put_contents($r38pFixture, <<<'PHP'
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
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r38p', 'R38P', 'r38p', 0)");
    $cat = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R38P-MUG', 'r38p-mug', 'R38P Mug', 12300, 100, 'active', 500]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 100, 0)')->execute([$warehouse, 'R38P-MUG']);
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r38p_pay', 'manual', 'R38P payment', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    $db->exec("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r38p_pay', 'AT')");
    // A carrier's pick-up point network (as Packeta's Z-BOX): delivered to a point, not to the address.
    $db->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('default', 'r38p-point', 'r38p', 'R38P point', 'pickup', 0, 1, 1)");
    foreach ([['r38p-point', 'r38p'], ['pickup', 'pickup']] as [$code, $carrier]) {
        $db->prepare("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order)
            VALUES ('default', ?, ?, 'AT', 'near_eu', 0, 999999, 0, 0, '2', 9)")->execute([$code, $carrier]);
    }
    $db->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES ('ATU38383838', 'AT', 'valid', 'R38P GmbH', ?, 86400)")
        ->execute([gmdate('Y-m-d H:i:s')]);
    exit(0);
}
$config = require AppConfig::path($root);
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.38.2';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
$stores = Bootstrap::stores();
$stores['stores']['default']['oss_enabled'] = !empty($args['oss']);
$stores['stores']['default']['legal']['country'] = 'SK';
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Bootstrap::initStoreContext('/', '', 'default');
unset($args['oss']);
VatService::reset();
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R38P-MUG'")->fetchColumn(), null, 1);
$typed = $args + ['name' => 'R38P Kundin', 'email' => 'r38p@example.test', 'phone' => '+43660000038', 'street' => 'Ring 1',
    'city' => 'Wien', 'zip' => '1010', 'country' => 'AT', 'payment' => 'r38p_pay', 'csrf_token' => Security::csrfToken()];
$orders = static fn(): array => $db->query('SELECT id, total, vat_scenario, oss_net, shipping_method FROM orders ORDER BY id')->fetchAll();
$before = count($orders());
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['page' => 'checkout'];
$_POST = $typed;
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR38PRESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/index.php';
PHP);
$r38pRun = static function(array $args) use ($r38pFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r38pFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R38PRESULT');
    return [$exit, $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true), substr($raw, 0, min($at === false ? 600 : $at, 600))];
};
$r38pApp = dirname(TEST_CLI_DIR);
$r38pTargets = [];
try {
    foreach (['sqlite', 'mysql'] as $r38pDriver) {
        if ($r38pDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R38 pickup point native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
        $r38pTarget = $r38pDriver === 'mysql' ? 'bbe_r38p_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $r38pRoot . '/shop.sqlite';
        $r38pTargets[] = [$r38pDriver, $r38pTarget];
        [$exit, , $noise] = $r38pRun([$r38pDriver, 'setup', $r38pApp, $r38pTarget, $r38pRoot, '{}']);
        assert_equals([0, ''], [$exit, trim($noise)], "R38 pickup point {$r38pDriver}: isolated migrated fixture");
        $cases = [
            'consumer, OSS on, carrier point' => [['oss' => true, 'shipping_method' => 'r38p-point'], 'oss', true],
            'business ATU, carrier point' => [['oss' => true, 'shipping_method' => 'r38p-point', 'company' => 'R38P GmbH', 'vat_id' => 'ATU38383838'], 'reverse_charge', false],
            'consumer, OSS off under threshold, carrier point' => [['oss' => false, 'shipping_method' => 'r38p-point'], 'domestic_subthreshold', true],
            'consumer, in-store pickup (control)' => [['oss' => true, 'shipping_method' => 'pickup'], 'domestic', false],
        ];
        foreach ($cases as $case => [$input, $scenario, $counts]) {
            $label = "R38 pickup point {$r38pDriver}/{$case}";
            [$exit, $page, $noise] = $r38pRun([$r38pDriver, 'refresh', $r38pApp, $r38pTarget, $r38pRoot, json_encode($input + ['checkout_refresh' => '1'])]);
            $html = (string)($page['output'] ?? '');
            $quoted = preg_match('~name="quoted_total" value="(\d+)"~', $html, $m) ? (int)$m[1] : null;
            $method = preg_match('~name="shipping_method" value="([^"]+)"[^>]*checked~', $html, $m) ? $m[1] : '';
            $consents = preg_match_all('~name="consents\[([a-z_]+)\]" value="1"~', $html, $m) ? array_fill_keys($m[1], '1') : [];
            assert_true($exit === 0 && $quoted !== null && $method === $input['shipping_method'], "{$label}: checkout offers the method and a total ({$method}, {$noise})");
            [$exit, $ordered, $noise] = $r38pRun([$r38pDriver, 'order', $r38pApp, $r38pTarget, $r38pRoot, json_encode(['quoted_total' => (string)$quoted, 'consents' => $consents, 'checkout' => '1'] + $input)]);
            $order = $ordered['new_orders'][0] ?? [];
            $error = preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', (string)($ordered['output'] ?? ''), $m) ? html_entity_decode($m[1]) : '';
            assert_true(count($ordered['new_orders'] ?? []) === 1 && ($order['vat_scenario'] ?? '') === $scenario && (int)($order['total'] ?? -1) === $quoted,
                "{$label}: ordered at the total shown under {$scenario} (got " . json_encode($order) . " {$error})");
            assert_true($counts ? (int)($order['oss_net'] ?? 0) > 0 : (int)($order['oss_net'] ?? -1) === 0,
                "{$label}: " . ($counts ? 'counts toward' : 'stays out of') . ' the OSS threshold (oss_net ' . json_encode($order['oss_net'] ?? null) . ')');
        }
    }
} finally {
    foreach ($r38pTargets as [$r38pDriver, $r38pTarget]) if ($r38pDriver === 'mysql') $r38pRun(['mysql', 'teardown', $r38pApp, $r38pTarget, $r38pRoot, '{}']);
}
