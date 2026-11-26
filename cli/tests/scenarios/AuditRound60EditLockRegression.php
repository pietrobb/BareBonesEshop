<?php

// Review round 25 (R60), low: an admin edit of a pending order released its old lines one by one and locked the new
// lines' SKUs only after, so an edit holding X and waiting for A deadlocked with a checkout holding A and waiting for X
// (19 of 236 checkouts failed after three tries). The edit now locks the SKUs of both, in SKU order, before releasing.
echo "\n--- R60 edit locks: an admin edit of an order's lines does not deadlock with a checkout (MySQL) ---\n";
if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R60 edit locks native MySQL (set TEST_MYSQL_DSN)\n";
    return;
}
$r60eRoot = test_fixture_path('edit-lock-r60');
mkdir($r60eRoot, 0700, true);
file_put_contents($r60eRoot . '/child.php', <<<'PHP'
<?php
[, $mode, $target, $root] = $argv;
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$connect = static fn(): PDO => new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db = $connect();
if ($mode === 'checkout') {
    // A checkout that has locked A (its first SKU) and wants X next, as lockCartSkus() takes them.
    $db->exec("USE `{$target}`");
    $db->exec('SET SESSION innodb_lock_wait_timeout = 10');
    $db->beginTransaction();
    $db->query("SELECT id FROM warehouse_stock WHERE sku = 'R60E-A' FOR UPDATE")->fetchAll();
    touch($argv[4]);
    usleep(1500000);
    try {
        $db->query("SELECT id FROM warehouse_stock WHERE sku = 'R60E-X' FOR UPDATE")->fetchAll();
        $result = 'ok';
    } catch (PDOException $e) {
        $result = (string)($e->errorInfo[1] ?? $e->getCode());
    }
    if ($db->inTransaction()) $db->commit();
    echo json_encode($result);
    exit(0);
}
$db->exec("DROP DATABASE IF EXISTS `{$target}`");
$db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
$db->exec("USE `{$target}`");
$out = [];
try {
    Db::init($db, 'mysql');
    (new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
    JobQueue::setDir(__DIR__ . '/jobs');
    Bootstrap::initStoreContext('/', '', 'default');
    I18n::setLocale('en');
    ConsentService::setDefinitionsForTesting([]);
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $warehouse = (int)(Warehouse::defaultWarehouse()['id'] ?? 0);
    $db->exec("INSERT INTO categories (slug, name, store_id) VALUES ('r60e', 'R60E', 'default')");
    $category = (int)$db->lastInsertId();
    foreach (['R60E-A', 'R60E-X'] as $sku) {
        $db->prepare("INSERT INTO products (sku, slug, name, category_id, product_type, price, currency, stock, status, inventory_policy, store_id) VALUES (?, ?, ?, {$category}, 'simple', 1000, 'eur', 100, 'active', 'deny', 'default')")
            ->execute([$sku, strtolower($sku), $sku]);
        Warehouse::setStock($warehouse, $sku, 100, 'count', 'test', 'r60e-' . $sku);
    }
    $db->exec("INSERT INTO shipping_methods (code, carrier, name, service_type, supports_cod, supports_pickup, free_above, sort_order) VALUES ('r60eship', 'gls', 'R60E parcel', 'parcel', 0, 0, NULL, 1)");
    $db->exec("INSERT INTO shipping_rates (carrier, country, zone_code, method_code, weight_from_g, weight_to_g, price, cod_surcharge, sort_order) VALUES ('gls', '', 'domestic', 'r60eship', 0, 999999, 400, 0, 1)");
    $db->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
        VALUES ('r60epay', 'manual', 'R60E transfer', '', 'offline', 0, 0, 0, 0, NULL, 0, 1, 1)");
    $db->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('r60epay', 'SK')");
    $placed = ManualOrder::create(ManualOrder::parseLines('R60E-X 1')['lines'], ['name' => 'R60E Buyer', 'email' => 'r60e@example.test',
        'phone' => '+421900000060', 'country' => 'SK', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'r60eship',
        'payment_method' => 'r60epay', 'locale' => 'sk'], false);
    $orderId = (int)($placed['order_id'] ?? 0);
    if ($orderId <= 0) throw new RuntimeException('order not placed: ' . json_encode($placed));
    $marker = __DIR__ . '/checkout-ready';
    @unlink($marker);
    $process = proc_open([PHP_BINARY, __FILE__, 'checkout', $target, $root, $marker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 200 && !is_file($marker); $i++) usleep(25000);
    // The admin edits the order from X to A while the checkout holds A.
    try {
        $edit = ManualOrder::editItems($orderId, ManualOrder::parseLines('R60E-A 1')['lines']);
        $out['edit'] = !empty($edit['ok']) ? 'ok' : (string)($edit['error'] ?? 'refused');
    } catch (PDOException $e) {
        $out['edit'] = (string)($e->errorInfo[1] ?? $e->getCode());
    }
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $out['checkout'] = json_decode($stdout, true) ?? substr($stdout . $stderr, -400);
    $out['reserved'] = $db->query("SELECT sku, reserved FROM warehouse_stock WHERE sku LIKE 'R60E-%' ORDER BY sku")->fetchAll(PDO::FETCH_KEY_PAIR);
} finally {
    $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
echo json_encode($out, JSON_THROW_ON_ERROR);
PHP);
$r60eTarget = 'bbe_r60e_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
$r60eProcess = proc_open([PHP_BINARY, $r60eRoot . '/child.php', 'main', $r60eTarget, dirname(TEST_CLI_DIR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r60ePipes);
fclose($r60ePipes[0]);
$r60eStdout = (string)stream_get_contents($r60ePipes[1]);
$r60eStderr = (string)stream_get_contents($r60ePipes[2]);
fclose($r60ePipes[1]);
fclose($r60ePipes[2]);
$r60eExit = proc_close($r60eProcess);
$r60eOut = json_decode($r60eStdout, true);
if ($r60eExit !== 0 || !is_array($r60eOut)) {
    assert_true(false, "R60 edit locks: child shop ran (exit {$r60eExit}: " . substr($r60eStdout . $r60eStderr, -1500) . ')');
} else {
    assert_equals(['edit' => 'ok', 'checkout' => 'ok', 'reserved' => ['R60E-A' => 1, 'R60E-X' => 0]], $r60eOut,
        'R60 edit locks: an edit from X to A waits for the checkout holding A, which then takes X; both go through (was: 1213 deadlock)');
}
foreach (glob($r60eRoot . '/jobs/*/*') ?: [] as $r60eFile) @unlink($r60eFile);
foreach (glob($r60eRoot . '/jobs/*', GLOB_ONLYDIR) ?: [] as $r60eDir) @rmdir($r60eDir);
@rmdir($r60eRoot . '/jobs');
foreach (glob($r60eRoot . '/*') ?: [] as $r60eFile) @unlink($r60eFile);
@rmdir($r60eRoot);
