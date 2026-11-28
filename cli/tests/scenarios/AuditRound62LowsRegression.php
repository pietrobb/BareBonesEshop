<?php

// Review round 62 (kolo 27), finding 14 (goal #662): the staging sweep reads the directory itself (lstat) and knows the
// user without ext-posix; an order cancellation picked as a deadlock victim runs again.
echo "\n--- R62 lows ---\n";

$r62lBackup = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/BackupService.php');
$r62lSweep = (string)strstr($r62lBackup, 'private static function sweepStaging');
assert_true(str_contains($r62lSweep, '$stat = @lstat($dir);') && !str_contains($r62lSweep, '@stat($dir)') && str_contains($r62lSweep, '0040000'),
    'R62 staging sweep: the directory is checked with lstat and must be a directory, not followed through a link swapped in after is_link() (was: stat)');

if (PHP_OS_FAMILY !== 'Windows') {
    // Without ext-posix the sweep takes the owner of a file this process makes; was: no owner or mode check at all.
    $r62lCode = 'require ' . var_export(dirname(TEST_CLI_DIR) . '/src/BackupService.php', true) . '; $m = new ReflectionMethod(BackupService::class, "processUid");'
        . ' echo json_encode([function_exists("posix_geteuid"), $m->invoke(null)]);';
    $r62lOut = [];
    exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=posix_geteuid -r ' . escapeshellarg($r62lCode) . ' 2>&1', $r62lOut, $r62lRc);
    $r62lExpect = function_exists('posix_geteuid') ? posix_geteuid() : (int)fileowner(__FILE__);
    assert_equals([0, json_encode([false, $r62lExpect])], [$r62lRc, implode("\n", $r62lOut)],
        'R62 staging sweep: without ext-posix the user is the owner of a file the process makes, so other users\' directories are still left alone');
}

$r62lWorkflow = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/OrderWorkflow.php');
assert_true(str_contains((string)strstr($r62lWorkflow, 'public static function transition('), 'Db::deadlocked($e)'),
    'R62 cancellation deadlock: OrderWorkflow::transition() runs its transaction in the deadlock retry loop');

if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R62 cancellation deadlock retry native MySQL (set TEST_MYSQL_DSN)\n";
    return;
}
$r62lRoot = test_fixture_path('cancel-deadlock-r62');
mkdir($r62lRoot, 0700, true);
file_put_contents($r62lRoot . '/child.php', <<<'PHP'
<?php
[, $target, $root] = $argv;
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
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
    $db->exec("INSERT INTO categories (slug, name, store_id) VALUES ('r62l', 'R62L', 'default')");
    $category = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO products (sku, slug, name, category_id, product_type, price, currency, stock, status, inventory_policy, store_id) VALUES ('R62L-A', 'r62l-a', 'R62L-A', {$category}, 'simple', 1000, 'eur', 100, 'active', 'deny', 'default')")->execute();
    Warehouse::setStock($warehouse, 'R62L-A', 100, 'count', 'test', 'r62l-A');
    $db->exec("INSERT INTO shipping_methods (code, carrier, name, service_type, supports_cod, supports_pickup, free_above, sort_order) VALUES ('r62lship', 'gls', 'R62L parcel', 'parcel', 0, 0, NULL, 1)");
    $db->exec("INSERT INTO shipping_rates (carrier, country, zone_code, method_code, weight_from_g, weight_to_g, price, cod_surcharge, sort_order) VALUES ('gls', '', 'domestic', 'r62lship', 0, 999999, 400, 0, 1)");
    $db->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
        VALUES ('r62lpay', 'manual', 'R62L transfer', '', 'offline', 0, 0, 0, 0, NULL, 0, 1, 1)");
    $db->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('r62lpay', 'SK')");
    // The cancellation's status update fails as a deadlock victim (MySQL error 1213) as many times as @r62_victims says.
    $db->exec("CREATE TRIGGER r62_victim BEFORE UPDATE ON orders FOR EACH ROW BEGIN
        IF NEW.status = 'cancelled' AND OLD.status <> 'cancelled' AND @r62_victims > 0 THEN SET @r62_victims = @r62_victims - 1; SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Deadlock found when trying to get lock'; END IF;
    END");
    foreach ([1, 2, 3] as $victims) {
        $placed = ManualOrder::create(ManualOrder::parseLines('R62L-A 2')['lines'], ['name' => 'R62L Buyer', 'email' => "r62l{$victims}@example.test",
            'phone' => '+421900000062', 'country' => 'SK', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'r62lship',
            'payment_method' => 'r62lpay', 'locale' => 'sk'], false);
        $orderId = (int)($placed['order_id'] ?? 0);
        if ($orderId <= 0) throw new RuntimeException('order not placed: ' . json_encode($placed));
        $db->exec("SET @r62_victims = {$victims}");
        try {
            OrderWorkflow::transition($orderId, 'cancelled', 'R62 cancel');
            $out[] = 'ok';
        } catch (PDOException $e) {
            $out[] = Db::deadlocked($e) ? 'deadlock' : $e->getMessage();
        }
        $out[] = [(string)$db->query("SELECT status FROM orders WHERE id = {$orderId}")->fetchColumn(),
            (int)$db->query("SELECT SUM(reserved) FROM warehouse_stock WHERE sku = 'R62L-A'")->fetchColumn()];
    }
} finally {
    $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
echo json_encode($out, JSON_THROW_ON_ERROR);
PHP);
$r62lTarget = 'bbe_r62l_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
$r62lProcess = proc_open([PHP_BINARY, $r62lRoot . '/child.php', $r62lTarget, dirname(TEST_CLI_DIR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r62lPipes);
fclose($r62lPipes[0]);
$r62lStdout = (string)stream_get_contents($r62lPipes[1]);
$r62lStderr = (string)stream_get_contents($r62lPipes[2]);
fclose($r62lPipes[1]);
fclose($r62lPipes[2]);
$r62lExit = proc_close($r62lProcess);
$r62lOut = json_decode($r62lStdout, true);
if ($r62lExit !== 0 || !is_array($r62lOut)) {
    assert_true(false, "R62 cancellation deadlock: child shop ran (exit {$r62lExit}: " . substr($r62lStdout . $r62lStderr, -1500) . ')');
} else {
    assert_equals(['ok', ['cancelled', 0], 'ok', ['cancelled', 0], 'deadlock', ['pending', 2]], $r62lOut,
        'R62 cancellation deadlock: a cancellation picked once or twice as a deadlock victim runs again and releases the stock once; a third time it fails and changes nothing (was: failed the first time)');
}
foreach (glob($r62lRoot . '/jobs/*/*') ?: [] as $r62lFile) @unlink($r62lFile);
foreach (glob($r62lRoot . '/jobs/*', GLOB_ONLYDIR) ?: [] as $r62lDir) @rmdir($r62lDir);
@rmdir($r62lRoot . '/jobs');
foreach (glob($r62lRoot . '/*') ?: [] as $r62lFile) @unlink($r62lFile);
@rmdir($r62lRoot);
