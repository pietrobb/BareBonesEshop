<?php

echo "\n--- R37: externally settled RMA and bounded credit-note waiting ---\n";
$r37sDir = test_fixture_path('refund-settlement-r37'); mkdir($r37sDir);
$r37sWorker = $r37sDir . '/settlement.php';
file_put_contents($r37sWorker, <<<'PHP'
<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql' ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]) : new ShopPDO('sqlite:' . $dir . '/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r37s_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
try {
    (new Migrator($db, $root . '/migrations', $dir . '/migration-' . $driver . '.lock'))->apply();
    Bootstrap::initStoreContext('/', '', 'default');
    JobQueue::setDir($dir . '/jobs-' . $driver);
    $drop = static function() use ($dir, $driver): void { foreach (glob($dir . '/jobs-' . $driver . '/pending/*.json') ?: [] as $file) unlink($file); };
    $jobs = static function() use ($dir, $driver): array { return array_values(array_filter(array_map(static fn($file) => json_decode(file_get_contents($file), true), glob($dir . '/jobs-' . $driver . '/pending/*.json') ?: []), static fn($job) => in_array($job['type'] ?? '', ['refund_credit_note', 'payment_refund'], true))); };
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r37s', 'R37S', 'r37s', 0)");
    $category = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R37S', 'r37s', 'R37S', 1000, 5, 'active')")->execute([$category]);
    $product = (int)$db->lastInsertId();
    $checks = [];
    foreach (['external', 'waiting', 'partial'] as $tag) {
        $snapshot = VatService::buildSnapshot([['product_id' => $product, 'sku' => 'R37S', 'qty' => 2, 'price' => 1000, 'product_type' => 'simple']], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
        $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
            VALUES (?, 'R37S', '', '{\"country\":\"SK\"}', 2000, 2000, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default')")
            ->execute(['R37S-' . $tag, 'pi_r37s_' . $tag, json_encode(['provider' => 'stripe', 'service_type' => 'online_card']), json_encode($snapshot)]);
        $order = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R37S', 'R37S', 2, 1000, 2000)")->execute([$order, $product]);
        $item = (int)$db->lastInsertId(); $invoice = InvoiceService::issue($order);
        [, $rma] = Rma::create($order, null, 'R37 return', [$item => 1]); Rma::setStatus((int)$rma, 'approved');
        $returned = (int)Rma::items((int)$rma)[0]['id'];
        Rma::receive((int)$rma, [$returned => ['quantity' => 1, 'disposition' => 'repair']], 'r37s-' . $tag);
        $settle = static fn() => method_exists(Rma::class, 'settleAlreadyRefunded') && Rma::settleAlreadyRefunded((int)$rma);
        $checks[] = ["{$tag}: unpaid return cannot be marked settled", !$settle()];
        if ($tag === 'external') $exchange = Rma::createExchange((int)$rma, $returned, 1, $product, null, 'r37s-exchange');
        if ($tag === 'waiting') $pending = InvoiceService::credit((int)$invoice['id'], (int)$rma, [$returned => 1], 'r37s-pending');
        if ($tag === 'partial') {
            PaymentService::bookProviderRefund($order, 'stripe', 're_r37s_partial_first', 500, 'charge.refunded', 'r37s-partial-first', []);
            $partialKey = (string)$db->query("SELECT idempotency_key FROM payment_transactions WHERE order_id={$order} AND amount=500 AND status IN ('refunded','partially_refunded','refund_succeeded')")->fetchColumn();
            InvoiceService::creditBookedRefund($order, $partialKey);
            $checks[] = ['partial: money and matching credit do not establish full return coverage', !$settle() && Rma::byId((int)$rma)['status'] === 'received'];
        }
        $amount = $tag === 'partial' ? 1500 : 2000;
        PaymentService::bookProviderRefund($order, 'stripe', 're_r37s_' . $tag, $amount, 'charge.refunded', 'r37s-external-' . $tag, []);
        $key = (string)$db->query("SELECT idempotency_key FROM payment_transactions WHERE order_id={$order} AND amount={$amount} AND status IN ('refunded','partially_refunded','refund_succeeded')")->fetchColumn();
        $checks[] = ["{$tag}: money alone without matching documents does not settle RMA", !$settle()];
        $drop();
        InvoiceService::creditBookedRefund($order, $key);
        if ($tag === 'waiting') {
            $checks[] = ['blocked credit schedules a delayed retry', count($jobs()) === 1];
            $drop(); $stopped = false;
            try { InvoiceService::creditBookedRefund($order, $key, 0, 96); } catch (RuntimeException $e) { $stopped = str_contains($e->getMessage(), 'reconcile'); }
            $checks[] = ['persistently blocked credit stops automatic requeue', $stopped && count($jobs()) === 0];
            $checks[] = ['persistently blocked credit alerts operator', IncidentMonitor::reportedForOrder('payment.credit_note_stalled', $order)];
            $checks[] = ['pending item refund prevents settlement', !$settle()];
            Rma::failRefund((int)$pending['refund_id'], 'Provider definitively declined; reconcile external refund');
            InvoiceService::creditBookedRefund($order, $key, 0, 96);
        }
        $drop();
        if ($tag === 'external') {
            $checks[] = ['external: an active exchange blocks settlement', !$settle()];
            $db->prepare("UPDATE return_exchanges SET status='cancelled' WHERE id=?")->execute([$exchange]);
            $db->exec($driver === 'sqlite'
                ? "CREATE TRIGGER r37_settlement_abort BEFORE UPDATE OF status ON rma_requests BEGIN SELECT RAISE(ABORT, 'r37 settlement injected failure'); END"
                : "CREATE TRIGGER r37_settlement_abort BEFORE UPDATE ON rma_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='r37 settlement injected failure'");
            $rolledBack = false;
            try { $settle(); } catch (PDOException $e) { $rolledBack = str_contains($e->getMessage(), 'r37 settlement injected failure'); }
            $db->exec('DROP TRIGGER r37_settlement_abort');
            $checks[] = ['external: failed status write rolls unit settlement back and releases transaction', $rolledBack && !$db->inTransaction() && (int)Rma::items((int)$rma)[0]['refunded_quantity'] === 0];
            $db->beginTransaction(); $settle(); $db->rollBack();
            $checks[] = ['external: settlement preserves caller rollback ownership', Rma::byId((int)$rma)['status'] === 'received' && (int)Rma::items((int)$rma)[0]['refunded_quantity'] === 0];
        }
        $before = [(int)$db->query("SELECT COUNT(*) FROM invoices WHERE order_id={$order}")->fetchColumn(), PaymentService::refundedAmount($order), (int)$db->query("SELECT COUNT(*) FROM order_refunds WHERE order_id={$order}")->fetchColumn()];
        $checks[] = ["{$tag}: fully refunded and credited order settles received RMA", $settle() && Rma::byId((int)$rma)['status'] === 'refunded'];
        $checks[] = ["{$tag}: received quantity is settled exactly once", (int)Rma::items((int)$rma)[0]['refunded_quantity'] === 1 && !$settle()];
        $after = [(int)$db->query("SELECT COUNT(*) FROM invoices WHERE order_id={$order}")->fetchColumn(), PaymentService::refundedAmount($order), (int)$db->query("SELECT COUNT(*) FROM order_refunds WHERE order_id={$order}")->fetchColumn()];
        $checks[] = ["{$tag}: settlement creates no money, document or refund job", $before === $after && $jobs() === []];
        $checks[] = ["{$tag}: settled units cannot be credited again", InvoiceService::creditableQuantities((int)$rma) === []];
        $exchangeBlocked = false;
        try { Rma::createExchange((int)$rma, $returned, 1, $product, null, 'r37s-after-' . $tag); } catch (LogicException $e) { $exchangeBlocked = true; }
        $checks[] = ["{$tag}: settled units cannot be exchanged again", $exchangeBlocked];
        $drop();
    }
    echo json_encode(['checks' => $checks]);
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
foreach (['sqlite', 'mysql'] as $r37sDriver) {
    if ($r37sDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { echo "  [SKIP] R37 settlement native MySQL\n"; continue; }
    $proc = proc_open([PHP_BINARY, $r37sWorker, dirname(TEST_CLI_DIR), $r37sDir, $r37sDriver], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]); $raw = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc); $result = json_decode($raw, true);
    assert_true($exit === 0 && is_array($result), "R37 settlement {$r37sDriver}: isolated fixture ({$err})");
    foreach ($result['checks'] ?? [] as [$label, $ok]) assert_true($ok, "R37 settlement {$r37sDriver}: {$label}");
}
