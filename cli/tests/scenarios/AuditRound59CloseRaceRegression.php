<?php

// Review round 24 (R59), finding 1 (since R49): Rma::closeWithoutPayout() read the return with a plain read inside its
// transaction before it locked the order. On MySQL/MariaDB that read fixes the transaction's snapshot, so a return waiting
// for the order lock while another return of the order closed still saw the money refunded outside the returns as unused
// after the wait, and used it again: three returns "Refunded" at once credited the customer 33.89 € of 50.66 € of goods
// (106 of 200 rounds). The return's order is now looked up before the transaction opens.
echo "\n--- R59 close race: returns closed without payout at once use the outside money once (MySQL) ---\n";
if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R59 close race native MySQL (set TEST_MYSQL_DSN)\n";
    return;
}
$r59rRoot = test_fixture_path('close-race-r59');
mkdir($r59rRoot, 0700, true);
file_put_contents($r59rRoot . '/step.php', <<<'PHP'
<?php
[, $phase, $target, $root] = $argv;
$args = array_slice($argv, 4);
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$connect = static fn(): PDO => new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db = $connect();
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
        // A delivered, invoiced order of A, B and C (10 € each at 23 %), 15 € of it refunded in the gateway's dashboard,
        // and a received return of each line.
        (new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
        $warehouse = (int)(Warehouse::defaultWarehouse()['id'] ?? 0);
        $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r59r', 'R59R', 'r59r', 0)");
        $category = (int)$db->lastInsertId();
        $products = $lines = [];
        foreach (['A', 'B', 'C'] as $name) {
            $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, ?, ?, ?, 1000, 50, 'active')")
                ->execute([$category, "R59R-{$name}", "r59r-{$name}", $name]);
            $products[$name] = (int)$db->lastInsertId();
            Warehouse::setStock($warehouse, "R59R-{$name}", 50, 'count', 'test', "r59r-opening-{$name}");
            $lines[] = ['product_id' => $products[$name], 'sku' => "R59R-{$name}", 'qty' => 1, 'price' => 1000, 'product_type' => 'simple'];
        }
        $snapshot = VatService::buildSnapshot($lines, ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
        $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, shipping_method, vat_scenario, financial_snapshot, store_id)
            VALUES ('R59R-1', 'R59R', 'r59r@example.test', '{\"country\":\"SK\"}', 3000, 3000, 'eur', 'paid', 'paid', 'bank_transfer', '', 'pickup', 'domestic', ?, 'default')")
            ->execute([json_encode($snapshot)]);
        $orderId = (int)$db->lastInsertId();
        $line = $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 1000, 1000)");
        foreach ($products as $name => $product) $line->execute([$orderId, $product, "R59R-{$name}", $name]);
        InvoiceService::issue($orderId);
        $db->exec("UPDATE orders SET status = 'delivered' WHERE id = {$orderId}");
        $db->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 'partially_refunded', 1500, 'eur', 'r59r-dashboard', 'default')")
            ->execute([$orderId]);
        InvoiceService::creditRefund($orderId, 1500, 'r59r-dashboard');
        $rmas = [];
        foreach (Orders::items($orderId) as $item) {
            [, $rmaId] = Rma::create($orderId, null, 'R59R return', [(int)$item['id'] => 1]);
            Rma::setStatus((int)$rmaId, 'approved');
            $decisions = [];
            foreach (Rma::items((int)$rmaId) as $rmaItem) $decisions[(int)$rmaItem['id']] = ['quantity' => 1, 'disposition' => 'restock'];
            Rma::receive((int)$rmaId, $decisions, 'r59r-receipt-' . $rmaId);
            $rmas[] = (int)$rmaId;
        }
        $out = ['order' => $orderId, 'rmas' => $rmas, 'outside' => Rma::refundedOutsideReturns($orderId)];
        break;
    case 'close':
        $out = ['closed' => Rma::closeWithoutPayout((int)$args[0])];
        break;
    case 'state':
        $orderId = (int)$args[0];
        $applied = $db->query("SELECT COALESCE(SUM(outside_refund_applied), 0) FROM rma_requests WHERE order_id = {$orderId}")->fetchColumn();
        $closed = $db->query("SELECT COUNT(*) FROM rma_requests WHERE order_id = {$orderId} AND status = 'refunded'")->fetchColumn();
        $out = ['applied' => (int)$applied, 'closed' => (int)$closed, 'outside_left' => Rma::refundedOutsideReturns($orderId)];
        break;
}
echo json_encode($out, JSON_THROW_ON_ERROR);
PHP);
$r59rTarget = 'bbe_r59r_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
$r59rRun = static function (array $args) use ($r59rRoot, $r59rTarget) {
    $process = proc_open(array_merge([PHP_BINARY, $r59rRoot . '/step.php', $args[0], $r59rTarget, dirname(TEST_CLI_DIR)], array_slice($args, 1)),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    return [$process, $pipes];
};
$r59rWait = static function (array $running): array {
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
    $r59rSetup = $r59rWait($r59rRun(['setup']));
    assert_equals([3, 1500], [count($r59rSetup['rmas'] ?? []), $r59rSetup['outside'] ?? null], 'R59 close race: three received returns, 15 € refunded outside them (' . json_encode($r59rSetup) . ')');
    $r59rOrder = (int)($r59rSetup['order'] ?? 0);
    // The admin clicks "Refunded" on all three returns at once; a fourth request (here: this test) holds the order
    // meanwhile, so all three wait for its lock.
    $r59rHolder = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r59rHolder->exec("USE `{$r59rTarget}`");
    $r59rHolder->beginTransaction();
    $r59rHolder->query("SELECT id FROM orders WHERE id = {$r59rOrder} FOR UPDATE")->fetchAll();
    $r59rClosers = [];
    foreach ($r59rSetup['rmas'] ?? [] as $r59rRma) $r59rClosers[] = $r59rRun(['close', (string)$r59rRma]);
    usleep(1500000);
    $r59rHolder->commit();
    $r59rClosed = array_map(static fn(array $r): string => json_encode($r), array_map($r59rWait, $r59rClosers));
    sort($r59rClosed);
    assert_equals(['{"closed":false}', '{"closed":false}', '{"closed":true}'], $r59rClosed,
        'R59 close race: of three 10 € returns closed at once, only one is covered by the 15 € refunded outside (was: all three closed)');
    assert_equals(['applied' => 1000, 'closed' => 1, 'outside_left' => 500], $r59rWait($r59rRun(['state', (string)$r59rOrder])),
        'R59 close race: the outside money is used once (10 €), 5 € of it is left for the next return\'s payout (was: 30 € applied of 15 €)');
} catch (Throwable $e) {
    assert_true(false, 'R59 close race (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r59rWait($r59rRun(['teardown']));
    foreach (glob($r59rRoot . '/jobs/*/*') ?: [] as $r59rFile) @unlink($r59rFile);
    foreach (glob($r59rRoot . '/jobs/*', GLOB_ONLYDIR) ?: [] as $r59rDir) @rmdir($r59rDir);
    @rmdir($r59rRoot . '/jobs');
    foreach (glob($r59rRoot . '/*') ?: [] as $r59rFile) @unlink($r59rFile);
    @rmdir($r59rRoot);
}
