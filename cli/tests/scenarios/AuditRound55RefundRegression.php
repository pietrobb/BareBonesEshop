<?php

// Review round 20 (R55), refunds:
// medium 2. GoPay/ThePay reported the payment REFUNDED while an admin refund request AND a credit note's refund were in
//    flight: the request was settled, but the credit note's money was booked as a refund outside the shop and its item
//    refund stayed pending for good, with nothing for the admin to confirm or release.
// medium 4. A refund released (declined by the provider, failed at the provider, dropped by "paid") after the rest of the
//    money went back another way left the payment refunded and the order processing: it could still be shipped.
// low. The credit note a REFUNDED notification settled is referenced by its booking's key: Rma::refundedOutsideReturns()
//    counted its money as returned outside the returns.
// Real GoPayPayment (only its HTTP transport stubbed), the worker's refund job, on SQLite and MySQL.
echo "\n--- R55 refunds: REFUNDED with a request and a credit note in flight, released refunds close the order ---\n";
$r55nRoot = test_fixture_path('refund-r55');
mkdir($r55nRoot, 0700, true);
file_put_contents($r55nRoot . '/step.php', <<<'PHP'
<?php
[, $phase] = $argv;
$args = array_slice($argv, 2);
$dir = __DIR__;
$fx = json_decode(file_get_contents($dir . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
require $fx['root'] . '/src/autoload.php';
require_once $fx['root'] . '/adapters/PaymentAdapter.php';
require_once $fx['root'] . '/adapters/contrib/GoPayPayment.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$db = $fx['driver'] === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new ShopPDO('sqlite:' . $fx['target']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($fx['driver'] === 'mysql') {
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$fx['target']}`"); exit(0); }
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$fx['target']}` CHARACTER SET utf8mb4");
    $db->exec("USE `{$fx['target']}`");
}
Db::init($db, $fx['driver']);
JobQueue::setDir($dir . '/jobs');
Bootstrap::initStoreContext('/', '', 'default');
I18n::setLocale('en');
$orderId = static function(string $paymentId) use ($db): int {
    $stmt = $db->prepare('SELECT id FROM orders WHERE payment_id = ?');
    $stmt->execute([$paymentId]);
    return (int)$stmt->fetchColumn();
};

$gopayFile = $dir . '/gopay.json';
$transport = static function(string $method, string $url, array $headers, ?string $body) use ($gopayFile): array {
    if (str_contains($url, '/oauth2/token')) return ['status' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 1800])];
    $gw = json_decode(file_get_contents($gopayFile), true);
    if (!preg_match('#/payments/payment/(\d+)(/refund)?$#', (string)parse_url($url, PHP_URL_PATH), $m)) return ['status' => 404, 'body' => '{}'];
    $id = $m[1];
    if (($m[2] ?? '') === '/refund') {
        parse_str((string)$body, $form);
        $gw[$id]['calls']++;
        $mode = array_shift($gw[$id]['modes']) ?? 'timeout';
        if ($mode !== 'timeout' && $mode !== 'refused') $gw[$id]['refunded'] += (int)$form['amount'];
        file_put_contents($gopayFile, json_encode($gw));
        // GoPay refuses the refund of a payment refunded already.
        if ($mode === 'refused') return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'result' => 'FAILED'])];
        if ($mode === 'accepted') return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'result' => 'ACCEPTED'])];
        if ($mode === 'finished') return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'result' => 'FINISHED'])];
        throw new RuntimeException('GoPay transport error: Operation timed out after 30001 milliseconds');
    }
    $refunded = (int)$gw[$id]['refunded'];
    $state = $refunded === 0 ? 'PAID' : ($refunded >= 2000 ? 'REFUNDED' : 'PARTIALLY_REFUNDED');
    return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'state' => $state, 'order_number' => 'R55N-' . $id, 'amount' => 2000,
        'currency' => 'EUR', 'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])];
};
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new GoPayPayment(['gopay_goid' => '8123456789', 'gopay_client_id' => 'c', 'gopay_client_secret' => 's'], $transport)]);
$gateway = static function(string $paymentId, callable $change) use ($gopayFile): void {
    $gw = json_decode(file_get_contents($gopayFile), true);
    $gw[$paymentId] = $change($gw[$paymentId]);
    file_put_contents($gopayFile, json_encode($gw));
};

$out = [];
switch ($phase) {
    case 'setup':
        (new Migrator($db, $fx['root'] . '/migrations', $dir . '/migrate.lock'))->apply();
        $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r55n', 'R55N', 'r55n', 0)");
        $category = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R55N', 'r55n', 'R55N', 1000, 50, 'active')")->execute([$category]);
        $product = (int)$db->lastInsertId();
        $gw = [];
        foreach (json_decode($args[0], true) as $paymentId => $status) {
            $snapshot = VatService::buildSnapshot([['product_id' => $product, 'sku' => 'R55N', 'qty' => 2, 'price' => 1000, 'product_type' => 'simple']], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
            $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
                VALUES (?, 'R55N', 'r55n@example.test', '{\"country\":\"SK\"}', 2000, 2000, 'eur', ?, 'paid', 'gopay', ?, ?, 'pickup', 'domestic', ?, 'default')")
                ->execute(['R55N-' . $paymentId, $status, (string)$paymentId, json_encode(['provider' => 'gopay', 'service_type' => 'online_card', 'requires_online' => true]), json_encode($snapshot)]);
            $id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R55N', 'R55N', 2, 1000, 2000)")->execute([$id, $product]);
            InvoiceService::issue($id);
            $gw[(string)$paymentId] = ['modes' => [], 'refunded' => 0, 'calls' => 0];
        }
        file_put_contents($gopayFile, json_encode($gw));
        break;
    case 'request':
        $gateway($args[0], static fn(array $p): array => ['modes' => array_slice($args, 2)] + $p);
        try {
            PaymentService::requestRefundAndQueue($orderId($args[0]), (int)$args[1], 'r55n_request_' . $args[0], ['reason' => 'R55N']);
            $out = ['payment' => (string)Orders::byId($orderId($args[0]))['payment_status']];
        } catch (Throwable $e) {
            $out = ['error' => get_class($e) . ': ' . $e->getMessage()];
        }
        break;
    case 'credit':
        $gateway($args[0], static fn(array $p): array => ['modes' => array_slice($args, 1)] + $p);
        $id = $orderId($args[0]);
        $item = (int)$db->query("SELECT id FROM order_items WHERE order_id = {$id}")->fetchColumn();
        $invoice = (int)$db->query("SELECT id FROM invoices WHERE order_id = {$id} AND type = 'invoice'")->fetchColumn();
        [, $rma] = Rma::create($id, null, 'R55N return', [$item => 1]);
        Rma::setStatus((int)$rma, 'approved');
        $returned = (int)Rma::items((int)$rma)[0]['id'];
        Rma::receive((int)$rma, [$returned => ['quantity' => 1, 'disposition' => 'repair']], 'receipt-r55n-' . $args[0]);
        try {
            InvoiceService::credit($invoice, (int)$rma, [$returned => 1], 'r55n-item-' . $args[0]);
        } catch (Throwable $e) {
            $out = ['error' => get_class($e) . ': ' . $e->getMessage()];
        }
        break;
    case 'dashboard':
        $gateway($args[0], static fn(array $p): array => ['refunded' => 2000] + $p);
        break;
    case 'book':
        // The whole payment refunded in the provider's dashboard, booked by its refund id while the shop's refund is in flight.
        PaymentService::bookProviderRefund($orderId($args[0]), 'gopay', 'r55n-dash-' . $args[0], 2000, 'refund.succeeded', 'r55n-dash-' . $args[0]);
        break;
    case 'decline':
        PaymentService::declinedRefundRequest($orderId($args[0]), 'r55n_request_' . $args[0] . '_execute', 'R55N declined');
        break;
    case 'fail':
        $id = $orderId($args[0]);
        // GoPay names no refund ids: the accepted request's row gets one, as a provider with refund ids (Stripe) records it.
        $ref = 're_r55n_' . $args[0];
        $named = $db->prepare("UPDATE payment_transactions SET provider_ref = ? WHERE order_id = ? AND status = 'refund_pending'");
        $named->execute([$ref, $id]);
        $out = ['named' => $named->rowCount() > 0, 'released' => PaymentService::providerRefundFailed($id, 'gopay', $ref, (int)$args[1], 'r55n-failed-' . $args[0])];
        break;
    case 'paid':
        PaymentService::recordAdminPayment(Orders::byId($orderId($args[0])), 'paid', 0, 'R55N paid after all', 'admin');
        break;
    case 'notify':
        $out = PaymentService::handlePulledNotification(['id' => $args[0]], Adapters::payment(), 'gopay');
        $out = ['handled' => (bool)($out['handled'] ?? false)];
        break;
    case 'work':
        foreach (glob($dir . '/jobs/pending/*.json') ?: [] as $file) {
            $job = json_decode(file_get_contents($file), true);
            unset($job['retry_after']);
            file_put_contents($file, json_encode($job));
        }
        foreach (JobQueue::pull(50) as $job) {
            if (($job['type'] ?? '') !== 'payment_refund') continue;
            $claim = JobQueue::markProcessing($job);
            if ($claim === '') continue;
            JobQueue::beginWork($claim);
            try {
                $calls = json_decode(file_get_contents($gopayFile), true)[(string)($job['payload']['payment_id'] ?? '')]['calls'] ?? 0;
                WorkerJobs::handlePaymentRefund($job['payload'], $job);
                JobQueue::complete($claim);
                $out[] = 'done ' . (!empty($job['payload']['refund_id']) ? 'item' : 'request')
                    . ((json_decode(file_get_contents($gopayFile), true)[(string)($job['payload']['payment_id'] ?? '')]['calls'] ?? 0) > $calls ? ' called' : '');
            } catch (Throwable $e) {
                JobQueue::fail($claim, $job, $e->getMessage());
                $out[] = 'failed: ' . $e->getMessage();
            } finally {
                JobQueue::endWork();
            }
        }
        break;
    case 'state':
        $id = $orderId($args[0]);
        $gw = json_decode(file_get_contents($gopayFile), true)[$args[0]];
        $order = Orders::byId($id);
        $out = ['order' => (string)$order['status'], 'payment' => (string)$order['payment_status'], 'refunded' => PaymentService::refundedAmount($id),
            'gopay' => (int)$gw['refunded'], 'calls' => (int)$gw['calls'],
            'item' => $db->query("SELECT status FROM order_refunds WHERE order_id = {$id} ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null,
            'outside' => Rma::refundedOutsideReturns($id)];
        break;
}
print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r55nDrivers = ['sqlite' => $r55nRoot . '/refunds.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r55nDrivers['mysql'] = 'bbe_r55n_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R55 refunds native MySQL (set TEST_MYSQL_DSN)\n";
$r55nStep = static function(string ...$args) use ($r55nRoot): array {
    $process = proc_open(array_merge([PHP_BINARY, $r55nRoot . '/step.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    return $exit === 0 && is_array($decoded) ? $decoded : ['error' => "exit {$exit}: " . substr($stdout . $stderr, -1500)];
};
$r55nBoth = '5500000001';    // medium 2: an admin refund request and a credit note in flight, the rest refunded in the dashboard
$r55nDecline = '5500000002'; // medium 4: the request declined after the dashboard refunded everything
$r55nFail = '5500000003';    // medium 4: the accepted request failed after the dashboard refunded everything
$r55nPaid = '5500000004';    // medium 4: "paid" drops the request after the dashboard refunded everything
$r55nLate = '5500000005';    // low: the credit note's job reaches GoPay after the dashboard refunded everything
$r55nState = static fn(array $s): array => [$s['order'] ?? ($s['error'] ?? ''), $s['payment'] ?? '', $s['refunded'] ?? -1, $s['item'] ?? null];
try {
    foreach ($r55nDrivers as $r55nDriver => $r55nTarget) {
        $tag = "R55 refunds {$r55nDriver}";
        @array_map('unlink', glob($r55nRoot . '/jobs/*/*.json') ?: []);
        if ($r55nDriver === 'sqlite') @unlink($r55nTarget);
        file_put_contents($r55nRoot . '/fixture.json', json_encode(['driver' => $r55nDriver, 'target' => $r55nTarget, 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        assert_equals([], $r55nStep('setup', json_encode([$r55nBoth => 'delivered', $r55nDecline => 'processing', $r55nFail => 'processing', $r55nPaid => 'processing', $r55nLate => 'delivered'])),
            "{$tag}: isolated fixture migrated");

        assert_equals(['payment' => 'refund_pending'], $r55nStep('request', $r55nBoth, '500'), "{$tag}: an admin refund requested");
        assert_equals([], $r55nStep('credit', $r55nBoth), "{$tag}: a credit note's refund queued beside it");
        $r55nStep('dashboard', $r55nBoth);
        $r55nStep('notify', $r55nBoth);
        $r55nBothState = $r55nStep('state', $r55nBoth);
        assert_equals(['delivered', 'refunded', 2000, 'succeeded'], $r55nState($r55nBothState),
            "{$tag}: REFUNDED with a request and a credit note in flight books the credit note with its money, the rest outside (was: credit note pending for good)");
        assert_equals(1000, $r55nBothState['outside'] ?? -1,
            "{$tag}: the request and the dashboard's rest count as returned outside the returns, the credit note's money does not (was: 2000)");
        $r55nWork = $r55nStep('work');
        assert_equals(0, $r55nStep('state', $r55nBoth)['calls'] ?? -1, "{$tag}: the request's and the credit note's jobs then finish without calling GoPay: " . json_encode($r55nWork));

        $r55nStep('request', $r55nDecline, '500');
        $r55nStep('book', $r55nDecline);
        $r55nStep('decline', $r55nDecline);
        assert_equals(['refunded', 'refunded', 2000, null], $r55nState($r55nStep('state', $r55nDecline)),
            "{$tag}: a declined request after the whole payment was refunded in the dashboard closes the order (was: processing, to be shipped)");

        $r55nStep('request', $r55nFail, '500', 'accepted');
        $r55nStep('work');
        $r55nStep('book', $r55nFail);
        assert_equals(['named' => true, 'released' => true], $r55nStep('fail', $r55nFail, '500'), "{$tag}: the accepted request's failure releases it");
        assert_equals(['refunded', 'refunded', 2000, null], $r55nState($r55nStep('state', $r55nFail)),
            "{$tag}: a failed provider refund after the whole payment was refunded in the dashboard closes the order (was: processing)");

        $r55nStep('request', $r55nPaid, '500');
        $r55nStep('book', $r55nPaid);
        $r55nStep('paid', $r55nPaid);
        assert_equals(['refunded', 'refunded', 2000, null], $r55nState($r55nStep('state', $r55nPaid)),
            "{$tag}: \"paid\" on a refund_pending payment the dashboard refunded in full closes the order (was: processing)");

        $r55nStep('credit', $r55nLate, 'refused');
        $r55nStep('dashboard', $r55nLate);
        $r55nStep('work');
        $r55nLateState = $r55nStep('state', $r55nLate);
        assert_equals(['delivered', 'refunded', 2000, 'succeeded'], $r55nState($r55nLateState),
            "{$tag}: a credit note refund GoPay refuses because the dashboard refunded everything is settled by the REFUNDED status (was: released, \"refund failed\")");
        assert_equals([1, 1000], [$r55nLateState['calls'] ?? -1, $r55nLateState['outside'] ?? -1],
            "{$tag}: one GoPay call, only the rest counted as refunded outside the returns");
    }
} finally {
    if (isset($r55nDrivers['mysql'])) {
        file_put_contents($r55nRoot . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $r55nDrivers['mysql'], 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        $r55nStep('teardown');
    }
    foreach (glob($r55nRoot . '/jobs/*/*.json') ?: [] as $r55nFile) @unlink($r55nFile);
}
