<?php
$pdo ??= test_commerce_fixture();

// Review round 12 (R38, low, VIES): reverse charge rested on the VAT ID alone. Any valid foreign VAT ID took the VAT
// off, whoever's it was: a consumer typing a company's ATU next to his own name, or a German VAT ID for a buyer billed
// in Austria. The holder VIES names must be the buyer: the VAT ID's state is the billing country (the delivery one when
// not billed apart), and the company named matches the registered name (legal form, case, a shorter form aside).
echo "\n--- R38 reverse charge only for the holder of the VAT ID (VIES name and country) ---\n";

$r38hRoot = test_fixture_path('vat-id-holder-r38');
mkdir($r38hRoot);
mkdir($r38hRoot . '/sessions');
$r38hFixture = $r38hRoot . '/request.php';
file_put_contents($r38hFixture, <<<'PHP'
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
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r38h', 'R38H', 'r38h', 0)");
    $cat = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R38H-MUG', 'r38h-mug', 'R38H Mug', 12300, 100, 'active', 500]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 100, 0)')->execute([$warehouse, 'R38H-MUG']);
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r38h_pay', 'manual', 'R38H payment', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    $db->exec("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r38h_pay', 'AT')");
    $db->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('default', 'r38h-ship', 'r38h', 'R38H courier', 'courier', 0, 0, 1)");
    $db->exec("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order)
        VALUES ('default', 'r38h-ship', 'r38h', 'AT', 'near_eu', 0, 999999, 0, 0, '2', 9)");
    foreach ([['ATU38383839', 'AT', 'R38H Handels GmbH'], ['DE383838390', 'DE', 'R38H Deutschland GmbH']] as [$id, $country, $holder]) {
        $db->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (?, ?, 'valid', ?, ?, 86400)")
            ->execute([$id, $country, $holder, gmdate('Y-m-d H:i:s')]);
    }
    exit(0);
}
$config = require AppConfig::path($root);
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.38.8';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
$stores = Bootstrap::stores();
$stores['stores']['default']['oss_enabled'] = true;
$stores['stores']['default']['legal']['country'] = 'SK';
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R38H-MUG'")->fetchColumn(), null, 1);
$typed = $args + ['name' => 'R38H Kunde', 'email' => 'r38h@example.test', 'phone' => '+43660000039', 'street' => 'Ring 1',
    'city' => 'Wien', 'zip' => '1010', 'country' => 'AT', 'shipping_method' => 'r38h-ship', 'payment' => 'r38h_pay', 'csrf_token' => Security::csrfToken()];
$orders = static fn(): array => $db->query('SELECT id, total, vat_scenario FROM orders ORDER BY id')->fetchAll();
$before = count($orders());
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['page' => 'checkout'];
$_POST = $typed;
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR38HRESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/index.php';
PHP);
$r38hRun = static function(array $args) use ($r38hFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r38hFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R38HRESULT');
    return [$exit, $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true), substr($raw, 0, min($at === false ? 600 : $at, 600))];
};
$r38hApp = dirname(TEST_CLI_DIR);
$r38hTargets = [];
$r38hBilledDe = ['billing_different' => '1', 'billing_name' => 'R38H Deutschland GmbH', 'billing_street' => 'Hauptstr. 1', 'billing_city' => 'Berlin',
    'billing_zip' => '10115', 'billing_country' => 'DE'];
try {
    foreach (['sqlite', 'mysql'] as $r38hDriver) {
        if ($r38hDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R38 VAT ID holder native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
        $r38hTarget = $r38hDriver === 'mysql' ? 'bbe_r38h_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $r38hRoot . '/shop.sqlite';
        $r38hTargets[] = [$r38hDriver, $r38hTarget];
        [$exit, , $noise] = $r38hRun([$r38hDriver, 'setup', $r38hApp, $r38hTarget, $r38hRoot, '{}']);
        assert_equals([0, ''], [$exit, trim($noise)], "R38 VAT ID holder {$r38hDriver}: isolated migrated fixture");
        $cases = [
            'the holder, as registered (control)' => [['company' => 'R38H Handels GmbH', 'vat_id' => 'ATU38383839'], 'reverse_charge'],
            'the holder, shorter name without the legal form' => [['company' => 'r38h handels', 'vat_id' => 'ATU38383839'], 'reverse_charge'],
            'another company with the holder\'s ATU' => [['company' => 'Fremde Bau GmbH', 'vat_id' => 'ATU38383839'], 'oss'],
            // R39 (low): art. 138 exempts a supply to a buyer identified in any other member state, not only the billing one.
            'a German VAT ID, billed in Austria' => [['company' => 'R38H Deutschland GmbH', 'vat_id' => 'DE383838390'], 'reverse_charge'],
            'a German VAT ID, billed in Germany, delivered to Austria' => [['company' => 'R38H Deutschland GmbH', 'vat_id' => 'DE383838390'] + $r38hBilledDe, 'reverse_charge'],
        ];
        foreach ($cases as $case => [$input, $scenario]) {
            $label = "R38 VAT ID holder {$r38hDriver}/{$case}";
            [$exit, $page, $noise] = $r38hRun([$r38hDriver, 'refresh', $r38hApp, $r38hTarget, $r38hRoot, json_encode($input + ['checkout_refresh' => '1'])]);
            $html = (string)($page['output'] ?? '');
            $quoted = preg_match('~name="quoted_total" value="(\d+)"~', $html, $m) ? (int)$m[1] : null;
            $consents = preg_match_all('~name="consents\[([a-z_]+)\]" value="1"~', $html, $m) ? array_fill_keys($m[1], '1') : [];
            assert_true($exit === 0 && $quoted !== null, "{$label}: checkout shows a total ({$noise})");
            [$exit, $ordered, $noise] = $r38hRun([$r38hDriver, 'order', $r38hApp, $r38hTarget, $r38hRoot, json_encode(['quoted_total' => (string)$quoted, 'consents' => $consents, 'checkout' => '1'] + $input)]);
            $order = $ordered['new_orders'][0] ?? [];
            $error = preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', (string)($ordered['output'] ?? ''), $m) ? html_entity_decode($m[1]) : '';
            assert_true(count($ordered['new_orders'] ?? []) === 1 && ($order['vat_scenario'] ?? '') === $scenario && (int)($order['total'] ?? -1) === $quoted,
                "{$label}: ordered under {$scenario} at the total shown (got " . json_encode($order) . " {$error})");
        }
    }
} finally {
    foreach ($r38hTargets as [$r38hDriver, $r38hTarget]) if ($r38hDriver === 'mysql') $r38hRun(['mysql', 'teardown', $r38hApp, $r38hTarget, $r38hRoot, '{}']);
}
