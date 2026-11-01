<?php

// Code review M3 / cash on delivery through the real storefront checkout (index.php?page=checkout): the COD surcharge
// follows the payment method's service type, not its code (an admin may rename 'cod'), and the checkout summary shows
// the same total the placed order charges. SQLite always, MySQL 8 / MariaDB 11.4 via TEST_MYSQL_DSN.
echo "\n--- Code review M3: COD surcharge by service type, shown in the checkout summary ---\n";

$crm3Root = test_fixture_path('checkout-cod');
mkdir($crm3Root);
mkdir($crm3Root . '/sessions');
$crm3Fixture = $crm3Root . '/request.php';
file_put_contents($crm3Fixture, <<<'PHP'
<?php
[, $driver, $phase, $action, $root, $target, $dir] = $argv;
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
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'crm3cat', 'Cat', 'crm3cat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'crm3cat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'CRM3-SKU', 'crm3-product', 'CRM3 Product', 2000, 50, 'active']);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'CRM3-SKU']);
    // The merchant renamed the seeded cash-on-delivery method.
    foreach (['payment_methods' => 'code', 'payment_method_countries' => 'method_code', 'payment_method_shipping' => 'method_code'] as $table => $column) {
        $db->exec("UPDATE {$table} SET {$column} = 'dobierka' WHERE {$column} = 'cod'");
    }
    exit(0);
}

$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.13.3';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'CRM3-SKU'")->fetchColumn(), null, 1);
$buyer = ['name' => 'CRM3 Buyer', 'email' => 'crm3-buyer@example.test', 'phone' => '+421900000133', 'street' => 'CRM3 Street 3',
    'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'shipping_method' => 'gls', 'payment' => 'dobierka', 'csrf_token' => Security::csrfToken()];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['page' => 'checkout'];
$_POST = $buyer + ($action === 'preview' ? ['checkout_refresh' => '1'] : ['checkout' => '1']);
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($db): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    $orders = $db->query("SELECT payment_method, shipping_cost, payment_fee, total, shipping_rate_snapshot FROM orders WHERE customer_email = 'crm3-buyer@example.test'")->fetchAll();
    foreach ($orders as &$order) $order['total_formatted'] = I18n::price((int)$order['total'], 'eur');
    unset($order);
    echo "\nCRM3RESULT" . json_encode(['output' => $out, 'orders' => $orders], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$crm3Run = static function(array $args) use ($crm3Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $crm3Fixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'CRM3RESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : ['output' => '', 'orders' => []], $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$crm3Drivers = ['sqlite' => $crm3Root . '/checkout.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $crm3Drivers['mysql'] = 'bbe_crm3_cod_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Code review M3 native COD checkout (set TEST_MYSQL_DSN)\n";
$crm3App = dirname(TEST_CLI_DIR);
try {
    foreach ($crm3Drivers as $crm3Driver => $crm3Target) {
        [$crm3Exit, , $crm3Noise] = $crm3Run([$crm3Driver, 'setup', 'none', $crm3App, $crm3Target, $crm3Root]);
        assert_equals([0, ''], [$crm3Exit, trim($crm3Noise)], "CR-M3 {$crm3Driver}: migrated fixture with the COD method renamed to 'dobierka'");

        [, $crm3Preview, $crm3Noise] = $crm3Run([$crm3Driver, 'run', 'preview', $crm3App, $crm3Target, $crm3Root]);
        $crm3PreviewTotal = preg_match('~id="summary-total">([^<]*)<~', (string)$crm3Preview['output'], $m) ? html_entity_decode($m[1]) : '';
        assert_true($crm3Preview['orders'] === [] && $crm3PreviewTotal !== '', "CR-M3 {$crm3Driver}: the re-priced checkout form shows a total and places no order (" . trim($crm3Noise) . ')');

        [, $crm3Placed, $crm3Noise] = $crm3Run([$crm3Driver, 'run', 'place', $crm3App, $crm3Target, $crm3Root]);
        $crm3Order = $crm3Placed['orders'][0] ?? [];
        $crm3Rate = json_decode((string)($crm3Order['shipping_rate_snapshot'] ?? ''), true) ?: [];
        assert_true(count($crm3Placed['orders']) === 1 && ($crm3Order['payment_method'] ?? '') === 'dobierka',
            "CR-M3 {$crm3Driver}: the order is placed with the renamed COD method (" . trim($crm3Noise) . ')');
        // R11: the surcharge is billed once, inside the COD fee; the shipping line is the carriage.
        assert_true((int)($crm3Rate['cod_surcharge'] ?? 0) > 0 && (int)($crm3Order['shipping_cost'] ?? 0) === (int)($crm3Rate['price'] ?? -1) - (int)($crm3Rate['cod_surcharge'] ?? 0)
            && (int)($crm3Order['payment_fee'] ?? 0) >= (int)($crm3Rate['cod_surcharge'] ?? 0),
            "CR-M3 {$crm3Driver}: the carrier's COD surcharge is charged although the method is not called 'cod' (" . json_encode(array_intersect_key($crm3Rate, array_flip(['price', 'base_price', 'cod_surcharge']))) . ')');
        assert_equals((string)($crm3Order['total_formatted'] ?? '-'), $crm3PreviewTotal,
            "CR-M3 {$crm3Driver}: the checkout summary showed the total the order charges, COD surcharge included");
    }
} finally {
    if (isset($crm3Drivers['mysql'])) $crm3Run(['mysql', 'teardown', 'none', $crm3App, $crm3Drivers['mysql'], $crm3Root]);
    $crm3Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($crm3Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($crm3Tree as $crm3Path) $crm3Path->isDir() ? @rmdir($crm3Path->getPathname()) : @unlink($crm3Path->getPathname());
    @rmdir($crm3Root);
}
