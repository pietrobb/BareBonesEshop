<?php

// Review round 25 (R60), medium 5 (older): a capture was compared with the order total read before the order was locked.
// An admin edit of the pending order (20 € → 35 €) committing in between marked it paid at 35 € for the 20 € the customer
// paid (14 of 80 tries). The total is now compared with the order read under its lock.
echo "\n--- R60 payment lock: a capture is checked against the total read under the order lock (MySQL) ---\n";
if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R60 payment lock native MySQL (set TEST_MYSQL_DSN)\n";
    return;
}
$r60pRoot = test_fixture_path('payment-lock-r60');
mkdir($r60pRoot, 0700, true);
file_put_contents($r60pRoot . '/step.php', <<<'PHP'
<?php
[, $phase, $target, $root] = $argv;
$args = array_slice($argv, 4);
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
$db->exec("USE `{$target}`");
Db::init($db, 'mysql');
JobQueue::setDir(__DIR__ . '/jobs');
Bootstrap::initStoreContext('/', '', 'default');
I18n::setLocale('en');
$out = [];
switch ($phase) {
    case 'setup':
        (new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
        $db->exec("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, shipping_method, vat_scenario, store_id)
            VALUES ('R60P-1', 'R60P', 'r60p@example.test', '{\"country\":\"SK\"}', 2000, 2000, 'eur', 'pending', 'pending', 'card', 'pi_r60p', 'pickup', 'domestic', 'default')");
        $out = ['order' => (int)$db->lastInsertId()];
        break;
    case 'pay':
        // The customer paid the 20 € session the order was opened with.
        try {
            PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => 'evt_r60p_' . $args[0],
                'data' => ['order_id' => (int)$args[0], 'amount' => 2000, 'currency' => 'eur', 'id' => 'pi_r60p', 'payment_intent' => 'pi_r60p']], 'stripe');
            $out = ['refused' => false];
        } catch (RuntimeException $e) {
            $out = ['refused' => str_contains($e->getMessage(), 'does not match')];
        }
        break;
    case 'state':
        $order = Orders::byId((int)$args[0]);
        $out = ['payment' => (string)($order['payment_status'] ?? ''), 'total' => (int)($order['total'] ?? 0),
            'incident' => IncidentMonitor::reportedForOrder('payment.amount_mismatch', (int)$args[0])];
        break;
}
echo json_encode($out, JSON_THROW_ON_ERROR);
PHP);
$r60pTarget = 'bbe_r60p_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
$r60pRun = static function (array $args) use ($r60pRoot, $r60pTarget) {
    $process = proc_open(array_merge([PHP_BINARY, $r60pRoot . '/step.php', $args[0], $r60pTarget, dirname(TEST_CLI_DIR)], array_slice($args, 1)),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    return [$process, $pipes];
};
$r60pWait = static function (array $running): array {
    [$process, $pipes] = $running;
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode($stdout, true);
    return $exit === 0 && is_array($decoded) ? $decoded : ['error' => "exit {$exit}: " . substr($stdout . $stderr, 0, 400)];
};
try {
    $r60pOrder = (int)($r60pWait($r60pRun(['setup']))['order'] ?? 0);
    assert_true($r60pOrder > 0, 'R60 payment lock: a pending 20 € card order');
    // The admin edits the order to 35 € (Orders::replaceItems() holds the order lock) while the capture arrives.
    $r60pAdmin = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r60pAdmin->exec("USE `{$r60pTarget}`");
    $r60pAdmin->beginTransaction();
    $r60pAdmin->query("SELECT id FROM orders WHERE id = {$r60pOrder} FOR UPDATE")->fetchAll();
    $r60pPay = $r60pRun(['pay', (string)$r60pOrder]);
    usleep(1500000);
    $r60pAdmin->exec("UPDATE orders SET subtotal = 3500, total = 3500 WHERE id = {$r60pOrder}");
    $r60pAdmin->commit();
    assert_equals(['refused' => true], $r60pWait($r60pPay), 'R60 payment lock: the 20 € capture is refused for the order edited to 35 € meanwhile');
    assert_equals(['payment' => 'pending', 'total' => 3500, 'incident' => true], $r60pWait($r60pRun(['state', (string)$r60pOrder])),
        'R60 payment lock: the order stays pending at 35 € and the mismatch is an incident (was: paid at 35 € for 20 €)');
} catch (Throwable $e) {
    assert_true(false, 'R60 payment lock (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r60pWait($r60pRun(['teardown']));
    foreach (glob($r60pRoot . '/jobs/*/*') ?: [] as $r60pFile) @unlink($r60pFile);
    foreach (glob($r60pRoot . '/jobs/*', GLOB_ONLYDIR) ?: [] as $r60pDir) @rmdir($r60pDir);
    @rmdir($r60pRoot . '/jobs');
    foreach (glob($r60pRoot . '/*') ?: [] as $r60pFile) @unlink($r60pFile);
    @rmdir($r60pRoot);
}
