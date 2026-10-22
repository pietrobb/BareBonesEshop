<?php

// Round 8 review (2026-10-21), unfixed from round 7: a payment confirmed while an admin cancels the same order failed
// with HTTP 500. PaymentService::transitionPayment() (Stripe checkout webhook, offline transfer confirmation) and
// settleCapture() (every capture, after the payment is booked) read the order without its row lock and then failed
// the compare-and-set or the paid transition once the cancellation committed. Here the cancellation is held open in
// one process while a second one confirms the payment; it must wait and then settle on the cancelled order (payment
// kept, refund flagged), not fail. Also unfixed from round 7: a capture booked from a provider notification left the
// order without the provider's payment id, so its refund had to be done by hand.
echo "\n--- Audit round 8 (rest): payment confirmed while the order is being cancelled; payment id from notifications ---\n";
$r8pRoot = test_fixture_path('r8-pay-cancel');
mkdir($r8pRoot);
file_put_contents($r8pRoot . '/race.php', <<<'PHP'
<?php
[, $phase, $root, $driver, $target] = $argv;
require $root . '/src/autoload.php';
$connect = static function() use ($driver, $target): PDO {
    if ($driver === 'sqlite') {
        $db = new PDO('sqlite:' . $target, null, null, [PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA foreign_keys = ON');
        return $db;
    }
    $db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("USE `{$target}`");
    $db->exec('SET SESSION innodb_lock_wait_timeout = 10');
    return $db;
};
if ($phase === 'teardown') {
    (new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: ''))->exec("DROP DATABASE IF EXISTS `{$target}`");
    exit(0);
}
if ($phase === 'offline' || $phase === 'settle') {
    // The second process: an admin records the received bank transfer, or a webhook's booked capture is settled.
    Db::init($connect(), $driver);
    try {
        $phase === 'offline' ? OfflinePaymentQueue::confirm((int)$argv[5], 'r8p-admin') : PaymentService::settleCapture((int)$argv[5], 'R8P webhook');
        echo json_encode('ok');
    } catch (Throwable $e) { echo json_encode('refused: ' . $e->getMessage()); }
    exit(0);
}
if ($driver === 'mysql') {
    (new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: ''))->exec("CREATE DATABASE `{$target}`");
}
$db = $connect();
Db::init($db, $driver);
(new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate-' . $driver . '.lock'))->apply();
$db->exec("INSERT INTO categories (store_id, name, slug) VALUES ('default', 'R8P', 'r8p')");
$category = (int)$db->lastInsertId();
$db->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r8p-wh', 'R8P', 1, 1)");
$wh = (int)$db->lastInsertId();
$db->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97501, 'default', {$category}, 'R8P', 'r8p', 'R8P-SKU', 'simple', 1000, 'eur', 'active', 6, 'deny')");
$db->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$wh}, 'R8P-SKU', 10, 4)");
$order = static function(string $number, string $paymentStatus, string $method, string $paymentId) use ($db, $wh): int {
    $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, payment_method_snapshot, subtotal, total, currency, store_id)
        VALUES (?, 'pending', ?, 'R8P', 'r8p@example.test', ?, ?, ?, 2000, 2000, 'eur', 'default')")
        ->execute([$number, $paymentStatus, $method, $paymentId, json_encode($method === 'bank_transfer'
            ? ['provider' => 'manual', 'service_type' => 'bank_transfer'] : ['provider' => 'mock', 'service_type' => 'online_card', 'requires_online' => true])]);
    $id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, attrs, reserved_qty) VALUES (?, 97501, 'R8P-SKU', 'R8P', 2, 1000, 2000, ?, 2)")
        ->execute([$id, json_encode(['_inventory_allocations' => [['warehouse_id' => $wh, 'warehouse_code' => 'r8p-wh', 'qty' => 2, 'status' => 'reserved']]])]);
    return $id;
};
// This process cancels the order in an open transaction and starts the second process meanwhile; it commits after
// 2 s (the second one is then waiting for the order), and returns that process's output and the final order state.
$whileCancelling = static function(int $orderId, string $phase) use ($db, $root, $driver, $target): array {
    Db::init($db, $driver);
    $db->beginTransaction();
    OrderWorkflow::transitionWithin($orderId, 'cancelled', 'R8P admin cancel');
    $process = proc_open([PHP_BINARY, __FILE__, $phase, $root, $driver, $target, (string)$orderId], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    usleep(2000000);
    $db->commit();
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $row = $db->query("SELECT status, payment_status FROM orders WHERE id = {$orderId}")->fetch(PDO::FETCH_NUM);
    $ledger = $db->query("SELECT status FROM payment_transactions WHERE order_id = {$orderId} AND status IN ('refund_manual_required', 'refund_pending') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    return [json_decode($output, true) ?? $output, ...$row, $ledger];
};
// A capture booked from a provider notification, on an order without the provider's payment id (or, at Stripe, with
// only its checkout session id): returns the order status, its payment_id and whether a refund must go by hand.
$captured = static function(string $number, string $provider, string $paymentId, array $data) use ($order, $db, $driver): array {
    $id = $order($number, 'pending', 'card', $paymentId);
    $db->prepare('UPDATE orders SET payment_method_snapshot = ? WHERE id = ?')
        ->execute([json_encode(['provider' => $provider, 'service_type' => 'online_card', 'requires_online' => true]), $id]);
    Db::init($db, $driver);
    try {
        PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => 'r8p-' . $number,
            'data' => ['order_id' => $id, 'amount' => 2000, 'currency' => 'eur'] + $data], $provider);
    } catch (Throwable $e) { return ['refused: ' . $e->getMessage()]; }
    $row = Orders::byId($id);
    return [(string)$row['status'], (string)$row['payment_id'], PaymentService::refundsByHand($row)];
};
echo json_encode([
    'offline' => $whileCancelling($order('R8P-1', 'pending', 'bank_transfer', ''), 'offline'),
    'settle' => $whileCancelling($order('R8P-2', 'paid', 'card', 'pi_r8p_2'), 'settle'),
    'reference' => $captured('R8P-3', 'mock', '', ['payment_id' => 'mock_r8p_3']),
    'stripe_session' => $captured('R8P-4', 'stripe', 'cs_r8p_4', ['id' => 'pi_r8p_4', 'payment_intent' => 'pi_r8p_4']),
    'kept' => $captured('R8P-5', 'mock', 'mock_r8p_5', ['payment_id' => 'mock_other']),
]);
PHP);
$r8pPhp = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
$r8pCheck = static function(string $driver, string $target) use ($r8pPhp, $r8pRoot): void {
    [$code, $out] = $r8pPhp([$r8pRoot . '/race.php', 'run', dirname(TEST_CLI_DIR), $driver, $target]);
    $r8p = json_decode($out, true);
    assert_equals(0, $code, "R8 pay vs cancel {$driver}: the race script ran (" . substr($out, 0, 400) . ')');
    assert_equals(['ok', 'cancelled', 'paid', ['refund_manual_required']], $r8p['offline'] ?? $out,
        "R8 pay vs cancel {$driver}: a bank transfer confirmed while the order is being cancelled waits, is booked on the cancelled order and flagged for a manual refund (no 500)");
    assert_equals(['ok', 'cancelled', 'refund_pending', ['refund_pending']], $r8p['settle'] ?? $out,
        "R8 pay vs cancel {$driver}: settling a booked capture while the order is being cancelled waits and leaves the order cancelled with its one refund (no 500)");
    assert_equals(['paid', 'mock_r8p_3', false], $r8p['reference'] ?? $out,
        "R8 payment_id {$driver}: a capture notification records the provider's payment id on an order without one, so its refund goes through the provider, not by hand");
    assert_equals(['paid', 'pi_r8p_4', false], $r8p['stripe_session'] ?? $out,
        "R8 payment_id {$driver}: a Stripe capture replaces the checkout session id with the payment intent the refund API needs");
    assert_equals(['paid', 'mock_r8p_5', false], $r8p['kept'] ?? $out,
        "R8 payment_id {$driver}: a payment id the order already holds is not overwritten by a notification");
};
$r8pCheck('sqlite', $r8pRoot . '/race.sqlite');
if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R8 pay vs cancel on MySQL/MariaDB (set TEST_MYSQL_DSN)\n";
} else {
    $r8pDb = 'bbe_r8p_race_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
    try {
        $r8pCheck('mysql', $r8pDb);
    } finally {
        $r8pPhp([$r8pRoot . '/race.php', 'teardown', dirname(TEST_CLI_DIR), 'mysql', $r8pDb]);
    }
}
foreach (glob($r8pRoot . '/*') ?: [] as $r8pFile) unlink($r8pFile);
rmdir($r8pRoot);
