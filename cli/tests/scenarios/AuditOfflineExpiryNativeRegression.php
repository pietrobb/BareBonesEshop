<?php

// A06.3 / finding 14 on a fully migrated SQLite and native MySQL/MariaDB database.
// Native SQL: per-type TTLs, COD/invoice never expire, ineligible rows cannot starve the batch, every store is swept.
$a063Root = test_fixture_path('offline-expiry');
mkdir($a063Root);
file_put_contents($a063Root . '/expiry.php', <<<'PHP'
<?php
[, $driver, $phase, $root, $target] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '')
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("CREATE DATABASE `{$target}`");
    $db->exec("USE `{$target}`");
    $db->exec("SET time_zone = '+00:00'");
}
Db::init($db, $driver);
(new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
JobQueue::setDir(__DIR__ . '/jobs-' . $driver);
$insert = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_method_snapshot, subtotal, total, currency, store_id, created_at)
    VALUES (:number, 'pending', :paid, 'A063', :email, :method, :snapshot, 1000, 1000, 'eur', :store, :created)");
$ago = static fn(int $hours): string => gmdate('Y-m-d H:i:s', time() - $hours * 3600);
foreach ([
    ['COD-1', 'cod', false, 'default', 9, 'pending'], ['COD-2', 'cod', false, 'default', 9, 'pending'],
    ['INVOICE', 'invoice', false, 'default', 9, 'pending'], ['BT-PAID', 'bank_transfer', false, 'default', 9, 'paid'],
    /* R11: past the due day and the 3 grace days */ ['BT-OLD', 'bank_transfer', false, 'default', 120, 'pending'], ['BT-YOUNG', 'bank_transfer', false, 'default', 1, 'pending'],
    ['ON-DEFAULT', 'online_card', true, 'default', 3, 'pending'], ['ON-CZ', 'online_card', true, 'cz', 3, 'pending'],
] as [$number, $type, $online, $store, $hours, $paid]) {
    $insert->execute([':number' => 'A063-' . $number, ':paid' => $paid, ':email' => strtolower($number) . '@example.test', ':method' => $type,
        ':snapshot' => json_encode(['code' => $type, 'service_type' => $type, 'requires_online' => $online]), ':store' => $store, ':created' => $ago($hours)]);
}
$cfg = ['pending_order_expiry_seconds' => 3600, 'pending_order_expiry_batch' => 1, 'offline_order_expiry_seconds' => ['bank_transfer' => 7200]];
for ($i = 0; $i < 4; $i++) {
    if (class_exists('PendingOrderExpiry')) PendingOrderExpiry::sweep($cfg, static fn(array $order): string => 'expired');
    else Orders::expirePending(3600, 1, static fn(array $order): string => 'expired');
}
$status = $db->query("SELECT order_number, status FROM orders ORDER BY order_number")->fetchAll(PDO::FETCH_KEY_PAIR);
$mails = array_map(static fn(array $job): string => ($job['payload']['template'] ?? '') . ':' . ($job['payload']['to'] ?? ''), JobQueue::pull(100));
echo json_encode(['status' => $status, 'mails' => $mails]);
PHP);
$a063Php = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
$a063Drivers = ['sqlite' => $a063Root . '/expiry.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a063Drivers['mysql'] = 'bbe_a063_expiry_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A06.3 native offline expiry (set TEST_MYSQL_DSN)\n";
$a063Expected = ['status' => [
    'A063-BT-OLD' => 'cancelled', 'A063-BT-PAID' => 'pending', 'A063-BT-YOUNG' => 'pending', 'A063-COD-1' => 'pending', 'A063-COD-2' => 'pending',
    'A063-INVOICE' => 'pending', 'A063-ON-CZ' => 'cancelled', 'A063-ON-DEFAULT' => 'cancelled',
], 'mails' => ['bank_transfer_reminder:bt-young@example.test', 'order_cancelled:bt-old@example.test']]; // R11: the transfer due today is reminded
try {
    foreach ($a063Drivers as $a063Driver => $a063Target) {
        [$a063Code, $a063Out] = $a063Php([$a063Root . '/expiry.php', $a063Driver, 'run', dirname(TEST_CLI_DIR), $a063Target]);
        $a063Got = json_decode($a063Out, true) ?? $a063Out;
        assert_equals([0, $a063Expected['status']], [$a063Code, $a063Got['status'] ?? $a063Got], "A06.3 {$a063Driver} expiry: configured transfer + online orders in every store expire; COD/invoice/paid/young stay pending; batch=1 is not starved");
        $a063Mails = $a063Got['mails'] ?? $a063Got;
        if (is_array($a063Mails)) sort($a063Mails);
        assert_equals($a063Expected['mails'], $a063Mails, "A06.3 {$a063Driver} expiry: only the expired offline buyer is told of the cancellation, the one due today is reminded");
    }
} finally {
    if (isset($a063Drivers['mysql'])) $a063Php([$a063Root . '/expiry.php', 'mysql', 'teardown', dirname(TEST_CLI_DIR), $a063Drivers['mysql']]);
    $a063Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a063Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a063Tree as $a063Path) $a063Path->isDir() ? rmdir($a063Path->getPathname()) : unlink($a063Path->getPathname());
    rmdir($a063Root);
}
