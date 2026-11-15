<?php

echo "\n--- R37: both item refunds already at Stripe must not refund received units twice ---\n";
$r37uDir = test_fixture_path('refund-units-r37'); mkdir($r37uDir);
$r37uWorker = $r37uDir . '/units.php';
file_put_contents($r37uWorker, <<<'PHP'
<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
class R37uStripe extends StripePayment {
    public function __construct() { parent::__construct([]); }
    public function refundByKey(string $paymentId, string $idempotencyKey): ?array { return ['status' => 'none']; }
}
$db = $driver === 'mysql' ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]) : new ShopPDO('sqlite:' . $dir . '/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r37u_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
try {
    (new Migrator($db, $root . '/migrations', $dir . '/migration-' . $driver . '.lock'))->apply();
    Bootstrap::initStoreContext('/', '', 'default');
    (new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new R37uStripe()]);
    JobQueue::setDir($dir . '/jobs-' . $driver);
    $drop = static function() use ($dir, $driver): void { foreach (glob($dir . '/jobs-' . $driver . '/pending/*.json') ?: [] as $file) unlink($file); };
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r37u', 'R37U', 'r37u', 0)");
    $category = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R37U', 'r37u', 'R37U', 1000, 5, 'active')")->execute([$category]);
    $product = (int)$db->lastInsertId();
    $checks = [];
    foreach ([false, true] as $secondFirst) {
        $tag = $secondFirst ? 'second-first' : 'late-first';
        $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK'];
        $snapshot = VatService::buildSnapshot([['product_id' => $product, 'sku' => 'R37U', 'qty' => 2, 'price' => 1000, 'product_type' => 'simple']], $ctx, ['currency' => 'eur']);
        $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
            VALUES (?, 'R37U', '', '{\"country\":\"SK\"}', 2000, 2000, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default')")
            ->execute(['R37U-' . $tag, 'pi_r37u_' . $tag, json_encode(['provider' => 'stripe', 'service_type' => 'online_card']), json_encode($snapshot)]);
        $order = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R37U', 'R37U', 2, 1000, 2000)")->execute([$order, $product]);
        $item = (int)$db->lastInsertId();
        $invoice = InvoiceService::issue($order);
        [, $rma] = Rma::create($order, null, 'R37 return', [$item => 1]);
        Rma::setStatus((int)$rma, 'approved');
        $returned = (int)Rma::items((int)$rma)[0]['id'];
        Rma::receive((int)$rma, [$returned => ['quantity' => 1, 'disposition' => 'repair']], 'r37u-receipt-' . $tag);
        $firstKey = 'r37u-first-' . $tag;
        $first = InvoiceService::credit((int)$invoice['id'], (int)$rma, [$returned => 1], $firstKey);
        $drop();
        PaymentService::releaseStalledItemRefund($order, (int)$first['refund_id'], 'r37-admin', 'Nothing yet at Stripe');
        $secondKey = 'r37u-second-' . $tag;
        $second = InvoiceService::credit((int)$invoice['id'], (int)$rma, [$returned => 1], $secondKey);
        Rma::noteProviderRefund((int)$second['refund_id'], 're_r37u_second_' . $tag);
        $drop();
        $book = static function(bool $first) use ($order, $firstKey, $secondKey, $tag): void {
            $ref = 're_r37u_' . ($first ? 'late_' : 'second_') . $tag;
            PaymentService::bookProviderRefund($order, 'stripe', $ref, 1000, 'charge.refunded', 'stripe_refund_' . $ref, ['refund_key' => $first ? $firstKey : $secondKey]);
        };
        $book(!$secondFirst); $book($secondFirst);
        foreach ($db->query("SELECT idempotency_key FROM payment_transactions WHERE order_id = {$order} AND status IN ('partially_refunded','refunded','refund_succeeded') AND amount > 0")->fetchAll(PDO::FETCH_COLUMN) as $key) InvoiceService::creditBookedRefund($order, (string)$key);
        $row = Rma::items((int)$rma)[0];
        $checks[] = ["{$tag}: received one unit is refunded once", (int)$row['received_quantity'] === 1 && (int)$row['refunded_quantity'] === 1];
        $checks[] = ["{$tag}: both real external payments remain in the ledger", PaymentService::refundedAmount($order) === 2000];
        $credits = (int)$db->query("SELECT COALESCE(SUM(-total_gross),0) FROM invoices WHERE order_id = {$order} AND type = 'credit_note' AND status <> 'cancelled'")->fetchColumn();
        $checks[] = ["{$tag}: credit documents keep both real payments", $credits === 2000];
        $checks[] = ["{$tag}: the operator is warned about the duplicate provider payment", IncidentMonitor::reportedForOrder('payment.duplicate_refund_sent', $order)];
        $book(true); $book(false);
        $checks[] = ["{$tag}: repeated webhooks change neither units nor money", (int)Rma::items((int)$rma)[0]['refunded_quantity'] === 1 && PaymentService::refundedAmount($order) === 2000];
        $drop();
    }
    echo json_encode(['checks' => $checks]);
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
foreach (['sqlite', 'mysql'] as $r37uDriver) {
    if ($r37uDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { echo "  [SKIP] R37 refund units native MySQL\n"; continue; }
    $proc = proc_open([PHP_BINARY, $r37uWorker, dirname(TEST_CLI_DIR), $r37uDir, $r37uDriver], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]); $raw = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc); $result = json_decode($raw, true);
    assert_true($exit === 0 && is_array($result), "R37 refund units {$r37uDriver}: isolated fixture ({$err})");
    foreach ($result['checks'] ?? [] as [$label, $ok]) assert_true($ok, "R37 refund units {$r37uDriver}: {$label}");
}
