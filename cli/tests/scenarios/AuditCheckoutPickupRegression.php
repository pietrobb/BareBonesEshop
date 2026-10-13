<?php

// A13.4 / pick-up point selection through the real storefront handler (index.php?page=checkout): carriers that
// list pick-up points (here Packeta from its stored v5 branch feed) get a no-JS point list, nearest postal code
// first and only points in operation shown to customers; a point-only rate refuses the order without a valid point
// from that list (form kept), the chosen point is kept in the order's shipping snapshot and reaches the label;
// a parcel rate may still go to the address. SQLite always, MySQL 8 / MariaDB 11.4 via TEST_MYSQL_DSN.
echo "\n--- Audit A13.4: checkout pick-up point selection through the carrier adapter ---\n";

$a134Root = test_fixture_path('checkout-pickup');
mkdir($a134Root);
mkdir($a134Root . '/sessions');
$a134Fixture = $a134Root . '/request.php';
file_put_contents($a134Fixture, <<<'PHP'
<?php
[, $driver, $phase, $action, $root, $target, $dir, $extra] = $argv;
putenv('SHIP_PACKETA_POINTS_DIR=' . $dir . '/points');
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
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'a134cat', 'Cat', 'a134cat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'a134cat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'A134-SKU', 'a134-product', 'A134 Product', 2000, 50, 'active']);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'A134-SKU']);
    // A point-only Packeta rate next to the seeded Packeta parcel rate (address or point).
    $db->prepare("INSERT INTO shipping_methods (store_id, code, carrier, name, adapter_class, service_type, supports_cod, supports_pickup, sort_order) VALUES ('default', 'packeta-point', 'packeta', 'A134 Packeta point', 'PacketaShipping', 'pickup', 0, 1, 15)")->execute();
    $db->prepare("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order) VALUES ('default', 'packeta-point', 'packeta', 'SK', 'domestic', 0, 999999, 249, 0, '1-2', 15)")->execute();
    $branch = static fn(string $id, string $name, string $zip, string $country = 'sk', string $status = '1', string $front = '1'): array => [
        'id' => $id, 'name' => $name, 'place' => 'A134', 'street' => $name . ' 1', 'city' => $name, 'zip' => $zip, 'country' => $country,
        'currency' => 'EUR', 'status' => ['statusId' => $status, 'description' => 'x'], 'displayFrontend' => $front,
    ];
    $stored = Adapters::provider('shipping', 'packeta')->storePickupPoints('SK', [
        $branch('1001', 'A134 Bratislava', '811 01'), $branch('1002', 'A134 Kosice', '040 01'),
        $branch('1003', 'A134 Closed', '040 02', 'sk', '5'), $branch('1004', 'A134 Hidden', '040 03', 'sk', '1', '0'),
        $branch('1005', 'A134 Praha', '110 00', 'cz'),
    ]);
    echo $stored === 2 ? '' : "stored {$stored} points";
    exit(0);
}

$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.13.4';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'A134-SKU'")->fetchColumn(), null, 1);
$orders = static fn(): array => $db->query("SELECT id, order_number, shipping_method, shipping_rate_snapshot FROM orders ORDER BY id")->fetchAll();
if ($action === 'label') {
    $order = $orders()[(int)$extra] ?? [];
    $label = ShippingService::generateLabel((int)($order['id'] ?? 0), 'packeta');
    echo "\nA134RESULT" . json_encode(['code' => 200, 'output' => base64_decode((string)$label['label_data']), 'new_orders' => []]);
    exit(0);
}
$csrf = Security::csrfToken();
// One e-mail per action: the per-buyer checkout rate limit (3 per 30 min) must not decide these cases.
$typed = ['name' => 'A134 Buyer', 'email' => 'a134-' . str_replace('_', '-', $action) . '@example.test', 'phone' => '+421900000134', 'street' => 'A134 Street 4',
    'city' => 'Kosice', 'zip' => '04011', 'country' => 'SK', 'checkout' => '1', 'csrf_token' => $csrf] + (json_decode($extra, true) ?: []);
if ($action === 'core_foreign_point') {
    $result = CheckoutService::execute($typed + ['shipping_method' => 'packeta-point', 'pickup_point' => '1005'], $config);
    echo "\nA134RESULT" . json_encode(['code' => 200, 'output' => json_encode($result), 'new_orders' => []]);
    exit(0);
}
[$method, $post] = match ($action) {
    'get' => ['GET', []],
    'refresh_point' => ['POST', ['shipping_method' => 'packeta-point', 'address_action' => 'refresh'] + $typed],
    'place_point_missing' => ['POST', ['shipping_method' => 'packeta-point'] + $typed],
    'place_point_closed' => ['POST', ['shipping_method' => 'packeta-point', 'pickup_point' => '1003'] + $typed],
    'place_point' => ['POST', ['shipping_method' => 'packeta-point', 'pickup_point' => '1002'] + $typed],
    'place_parcel_address' => ['POST', ['shipping_method' => 'packeta'] + $typed],
};
$_SERVER['REQUEST_METHOD'] = $method;
$_GET = ['page' => 'checkout'];
$_POST = $post;
http_response_code(200);
$before = count($orders());
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nA134RESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$a134Run = static function(array $args) use ($a134Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a134Fixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    // A newline keeps a gateway log line on stderr from gluing onto the result line on stdout.
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'A134RESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$a134Drivers = ['sqlite' => $a134Root . '/checkout.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a134Drivers['mysql'] = 'bbe_a134_pickup_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A13.4 native checkout pick-up points (set TEST_MYSQL_DSN)\n";
$a134App = dirname(TEST_CLI_DIR);
try {
    foreach ($a134Drivers as $a134Driver => $a134Target) {
        [$a134Exit, , $a134Noise] = $a134Run([$a134Driver, 'setup', 'none', $a134App, $a134Target, $a134Root, '{}']);
        assert_equals([0, ''], [$a134Exit, trim($a134Noise)], "A13.4 {$a134Driver}: migrated fixture; of five feed branches only the two SK points in operation and shown to customers are stored");
        $a134Extra = '{}';
        $a134Do = static function(string $action) use (&$a134Extra, $a134Run, $a134Driver, $a134App, $a134Target, $a134Root): array {
            [$exit, $result, $noise] = $a134Run([$a134Driver, 'run', $action, $a134App, $a134Target, $a134Root, $a134Extra]);
            $label = "A13.4 {$a134Driver}/{$action}";
            assert_true($exit === 0 && $result !== null && !str_contains((string)$result['output'], 'Fatal error') && !str_contains((string)$result['output'], 'Uncaught'),
                "{$label}: real handler completes (exit {$exit} " . trim($noise) . ')');
            $html = (string)($result['output'] ?? '');
            $form = preg_match('~<form method="post" class="checkout-form".*?</form>~s', $html, $m) ? $m[0] : '';
            return [$label, $result ?? ['new_orders' => []], $html, $form];
        };
        $a134Points = static fn(string $form): array => preg_match('~<select id="checkout-pickup-point" name="pickup_point">(.*?)</select>~s', $form, $m)
            && preg_match_all('~<option value="(\d*)"~', $m[1], $o) ? $o[1] : [];

        [$l, , , $form] = $a134Do('get');
        assert_true(str_contains($form, '<button type="submit" name="address_action" value="refresh" formnovalidate>') && $a134Points($form) === []
            && str_contains($form, 'value="packeta-point"'), "{$l}: with in-store pickup preselected the form offers a no-JS button to show pick-up points");

        [$l, $r, , $form] = $a134Do('refresh_point');
        preg_match('~name="payment" value="([^"]+)"~', $form, $a134Pay);
        assert_true($r['new_orders'] === [] && $a134Points($form) === ['', '1002', '1001'] && str_contains($form, 'A134 Kosice — A134 Kosice 1, 040 01 A134 Kosice')
            && !str_contains($form, 'A134 Closed') && !str_contains($form, 'A134 Hidden') && !str_contains($form, 'A134 Praha')
            && str_contains($form, 'value="packeta-point" checked') && !empty($a134Pay[1]),
            "{$l}: refresh lists the carrier's points nearest to zip 04011 first (" . implode(',', $a134Points($form)) . '); closed, hidden and other-country points are not offered; nothing ordered');

        $a134Extra = json_encode(['payment' => $a134Pay[1] ?? '']);
        [$l, $r, , $form] = $a134Do('place_point_missing');
        $a134Error = static fn(string $form): string => preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', $form, $m) ? html_entity_decode($m[1]) : '';
        assert_true($r['new_orders'] === [] && $a134Error($form) === 'Vyberte výdajné miesto.' && $a134Points($form) === ['', '1002', '1001']
            && str_contains($form, 'value="A134 Street 4"'), "{$l}: a point-only rate without a point is refused (" . $a134Error($form) . '); the form comes back with the list and the typed values');

        [$l, $r, , $form] = $a134Do('place_point_closed');
        assert_true($r['new_orders'] === [] && str_starts_with($a134Error($form), 'Zvolené výdajné miesto už nie je dostupné'),
            "{$l}: a point that is not on the carrier's list for this address (closed) is refused (" . $a134Error($form) . ')');

        [$l, $r] = $a134Do('core_foreign_point');
        $a134Core = json_decode((string)$r['output'], true) ?: [];
        assert_true(empty($a134Core['success']) && str_starts_with((string)($a134Core['error'] ?? ''), 'Zvolené výdajné miesto už nie je dostupné'),
            "{$l}: the shared checkout core (HTML + JSON API) refuses a point of another country (" . ($a134Core['error'] ?? '') . ')');

        [$l, $r] = $a134Do('place_point');
        $a134Snapshot = json_decode((string)($r['new_orders'][0]['shipping_rate_snapshot'] ?? ''), true) ?: [];
        assert_true(count($r['new_orders']) === 1 && ($r['new_orders'][0]['shipping_method'] ?? '') === 'packeta-point'
            && ($a134Snapshot['pickup_point'] ?? null) === ['id' => '1002', 'name' => 'A134 Kosice', 'address' => 'A134 Kosice 1, 040 01 A134 Kosice'],
            "{$l}: the chosen point is kept in the order's shipping snapshot (" . json_encode($a134Snapshot['pickup_point'] ?? null) . ')');

        $a134Extra = json_encode(['payment' => $a134Pay[1] ?? '']);
        [$l, $r] = $a134Do('place_parcel_address');
        $a134Parcel = json_decode((string)($r['new_orders'][0]['shipping_rate_snapshot'] ?? ''), true) ?: [];
        assert_true(count($r['new_orders']) === 1 && ($r['new_orders'][0]['shipping_method'] ?? '') === 'packeta' && !isset($a134Parcel['pickup_point']),
            "{$l}: a parcel rate that also serves points may still be delivered to the address without a point");

        $a134Extra = '0';
        [$l, $r] = $a134Do('label');
        assert_true(str_contains((string)$r['output'], 'Pick-up point: 1002'), "{$l}: the label request carries the chosen pick-up point id");
    }
} finally {
    if (isset($a134Drivers['mysql'])) $a134Run(['mysql', 'teardown', 'none', $a134App, $a134Drivers['mysql'], $a134Root, '{}']);
    $a134Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a134Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a134Tree as $a134Path) $a134Path->isDir() ? @rmdir($a134Path->getPathname()) : @unlink($a134Path->getPathname());
    @rmdir($a134Root);
}
