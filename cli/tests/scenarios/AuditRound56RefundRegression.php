<?php

// Review round 21 (R56), refunds:
// medium 2. GoPay/ThePay reported the payment REFUNDED between the refund job's booking (refund_succeeded) and its
//    settleRefundJob(): no request in flight, the payment still refund_pending. The event was "ignored" and its id used up,
//    so later notifications and polls were dropped as duplicates: 3000 refunded at GoPay, 500 in the ledger, the order to ship.
// low. A refund refused by ThePay/Comgate (HTTP 4xx: refunded in their dashboard meanwhile) was an error retried until the
//    job died; GoPay's FAILED result was checked against the payment's state (R55). An admin refund GoPay refused that way
//    still failed its job; a released refund raised a critical incident although the customer had all the money.
// low. Refund credit notes issued before R55 have no "split": the unshipped goods' rate was credited again (N1).
echo "\n--- R56 refunds: REFUNDED between booking and settling, refused refunds settled by the payment state, legacy credits ---\n";

// --- Legacy refund credit notes (before R55: no split) ------------------------------------------------------------------------
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
try {
    $r56rCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r56rWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class) VALUES
        (95681, 'default', {$r56rCategory}, 'R56R-STD', 'r56r-std', 'simple', 'R56R standard', 1000, 'eur', 5, 'active', 'deny', 'standard'),
        (95682, 'default', {$r56rCategory}, 'R56R-BOOK1', 'r56r-book1', 'simple', 'R56R book shipped', 1000, 'eur', 5, 'active', 'deny', 'books'),
        (95683, 'default', {$r56rCategory}, 'R56R-BOOK2', 'r56r-book2', 'simple', 'R56R book unshipped', 1000, 'eur', 5, 'active', 'deny', 'books')");
    foreach (['R56R-STD', 'R56R-BOOK1', 'R56R-BOOK2'] as $r56rSku) Warehouse::setStock($r56rWarehouse, $r56rSku, 5, 'count', 'test', 'r56r-opening-' . $r56rSku);
    $r56rLine = static fn(int $productId, string $sku): array => ['product_id' => $productId, 'variant_id' => null, 'sku' => $sku, 'name' => $sku, 'qty' => 1, 'price' => 1000, 'attrs' => []];
    $r56rOrder = Orders::create([$r56rLine(95681, 'R56R-STD'), $r56rLine(95682, 'R56R-BOOK1'), $r56rLine(95683, 'R56R-BOOK2')],
        ['name' => 'R56R Buyer', 'email' => 'r56r@example.test', 'phone' => '', 'address' => ['country' => 'SK'], 'shipping_cost' => 0, 'tax_amount' => 0,
            'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
    $r56rSnapshot = VatService::buildSnapshot([
        ['product_id' => 95681, 'sku' => 'R56R-STD', 'qty' => 1, 'price' => 1000, 'product_type' => 'simple', 'vat_class' => 'standard'],
        ['product_id' => 95682, 'sku' => 'R56R-BOOK1', 'qty' => 1, 'price' => 1000, 'product_type' => 'simple', 'vat_class' => 'books'],
        ['product_id' => 95683, 'sku' => 'R56R-BOOK2', 'qty' => 1, 'price' => 1000, 'product_type' => 'simple', 'vat_class' => 'books'],
    ], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
    $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
        ->execute([json_encode($r56rSnapshot), $r56rOrder]);
    $r56rInvoice = InvoiceService::issue($r56rOrder);
    $r56rBookKey = $r56rStdKey = '';
    foreach (json_decode((string)$r56rInvoice['vat_breakdown'], true) as $r56rKey => $r56rRate) {
        if ((int)($r56rRate['net'] ?? 0) + (int)($r56rRate['vat'] ?? 0) === 2000) $r56rBookKey = (string)$r56rKey;
        else $r56rStdKey = (string)$r56rKey;
    }
    $r56rParcels = Fulfillment::planOrder($r56rOrder);
    $r56rIn = implode(',', array_map('intval', $r56rParcels) ?: [0]);
    $r56rParcel = (int)$pdo->query("SELECT shipment_id FROM shipment_items WHERE sku = 'R56R-STD' AND shipment_id IN ({$r56rIn})")->fetchColumn();
    $pdo->exec("DELETE FROM shipment_items WHERE sku = 'R56R-BOOK2' AND shipment_id = {$r56rParcel}");
    $pdo->exec("UPDATE shipment_items SET shipment_id = {$r56rParcel} WHERE sku = 'R56R-BOOK1' AND shipment_id IN ({$r56rIn})");
    Fulfillment::ship($r56rParcel);
    OrderWorkflow::transition($r56rOrder, 'cancelled', 'R56 cancelled rest');
    $r56rRates = static function (?array $credit): array {
        $rates = array_map(static fn(array $rate): int => -(int)($rate['net'] ?? 0) - (int)($rate['vat'] ?? 0), (array)json_decode((string)($credit['vat_breakdown'] ?? '[]'), true));
        ksort($rates);
        return $rates;
    };
    $r56rCancel = InvoiceService::creditRefund($r56rOrder, 1000, 'order_refund_' . $r56rOrder);
    assert_equals([$r56rBookKey => 1000], $r56rRates($r56rCancel), 'R56 legacy credit: the cancelled rest\'s refund credits the unshipped book\'s rate');
    assert_equals('unshipped', json_decode((string)$r56rCancel['credit_snapshot'], true)['split'] ?? null, 'R56: the cancellation refund\'s credit note names its split');
    // The credit note as R54 wrote it: no split.
    $r56rLegacy = json_decode((string)$r56rCancel['credit_snapshot'], true);
    unset($r56rLegacy['split']);
    $pdo->prepare('UPDATE invoices SET credit_snapshot = ? WHERE id = ?')->execute([json_encode($r56rLegacy), (int)$r56rCancel['id']]);
    $r56rDashboard = InvoiceService::creditRefund($r56rOrder, 1000, 'r56r-dashboard-refund');
    $r56rExpected = [$r56rBookKey => 500, $r56rStdKey => 500];
    ksort($r56rExpected);
    assert_equals($r56rExpected, $r56rRates($r56rDashboard),
        'R56 legacy credit: a later refund after a credit note issued before R55 is split over what the invoice has left (was: the unshipped book\'s rate again)');
    assert_equals('invoice', json_decode((string)$r56rDashboard['credit_snapshot'], true)['split'] ?? null, 'R56: a refund split over the invoice says so');
} catch (Throwable $e) {
    assert_true(false, 'R56 legacy credit (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// --- ThePay / Comgate: a refused refund is a failed refund, not an error to retry ---------------------------------------------
try {
    require_once dirname(TEST_CLI_DIR) . '/adapters/PaymentAdapter.php';
    require_once dirname(TEST_CLI_DIR) . '/adapters/contrib/ThePayPayment.php';
    require_once dirname(TEST_CLI_DIR) . '/adapters/contrib/ComgatePayment.php';
    $r56rCode = 400;
    $r56rTransport = static function (string $method, string $url, array $headers, ?string $body) use (&$r56rCode): array {
        return ['status' => $r56rCode, 'body' => json_encode(['message' => 'refused'])];
    };
    $r56rThePay = new ThePayPayment(['thepay_merchant_id' => 'm', 'thepay_project_id' => '42', 'thepay_password' => 'p'], $r56rTransport);
    $r56rComgate = new ComgatePayment(['comgate_merchant' => 'm', 'comgate_secret' => 's'], $r56rTransport);
    assert_equals(['failed', 'failed'], [$r56rThePay->refund('uid-1', 500)['status'] ?? null, $r56rComgate->refund('TRANS-1', 500)['status'] ?? null],
        'R56: a refund ThePay and Comgate refuse (HTTP 400) is a failed refund the shop checks against the payment state (was: an error retried until the job died)');
    foreach ([401, 429, 503] as $r56rCode) {
        $r56rThrown = 0;
        foreach ([static fn() => $r56rThePay->refund('uid-1', 500), static fn() => $r56rComgate->refund('TRANS-1', 500)] as $r56rCall) {
            try { $r56rCall(); } catch (RuntimeException $e) { $r56rThrown++; }
        }
        assert_equals(2, $r56rThrown, "R56: HTTP {$r56rCode} (credentials, throttling, outage) stays an error to retry");
    }
} catch (Throwable $e) {
    assert_true(false, 'R56 refused refunds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// --- GoPay: the real adapter (transport stubbed), the worker's refund job, SQLite and MySQL -----------------------------------
$r56nRoot = test_fixture_path('refund-r56');
mkdir($r56nRoot, 0700, true);
file_put_contents($r56nRoot . '/step.php', <<<'PHP'
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
        if ($mode === 'finished') $gw[$id]['refunded'] += (int)$form['amount'];
        file_put_contents($gopayFile, json_encode($gw));
        if ($mode === 'refused') return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'result' => 'FAILED'])];
        if ($mode === 'finished') return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'result' => 'FINISHED'])];
        throw new RuntimeException('GoPay transport error: Operation timed out after 30001 milliseconds');
    }
    $refunded = (int)$gw[$id]['refunded'];
    $state = $refunded === 0 ? 'PAID' : ($refunded >= 3000 ? 'REFUNDED' : 'PARTIALLY_REFUNDED');
    return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'state' => $state, 'order_number' => 'R56N-' . $id, 'amount' => 3000,
        'currency' => 'EUR', 'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])];
};
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new GoPayPayment(['gopay_goid' => '8123456789', 'gopay_client_id' => 'c', 'gopay_client_secret' => 's'], $transport)]);
$gateway = static function(string $paymentId, callable $change) use ($gopayFile): void {
    $gw = json_decode(file_get_contents($gopayFile), true);
    $gw[$paymentId] = $change($gw[$paymentId]);
    file_put_contents($gopayFile, json_encode($gw));
};
$jobsOf = static function(string $paymentId): array {
    $jobs = [];
    foreach (glob(__DIR__ . '/jobs/pending/*.json') ?: [] as $file) {
        $job = json_decode(file_get_contents($file), true);
        if (($job['type'] ?? '') === 'payment_refund' && (string)($job['payload']['payment_id'] ?? '') === $paymentId) $jobs[] = $job;
    }
    return $jobs;
};

$out = [];
switch ($phase) {
    case 'setup':
        (new Migrator($db, $fx['root'] . '/migrations', $dir . '/migrate.lock'))->apply();
        $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r56n', 'R56N', 'r56n', 0)");
        $category = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R56N', 'r56n', 'R56N', 1000, 50, 'active')")->execute([$category]);
        $product = (int)$db->lastInsertId();
        $gw = [];
        foreach (json_decode($args[0], true) as $paymentId => $status) {
            $snapshot = VatService::buildSnapshot([['product_id' => $product, 'sku' => 'R56N', 'qty' => 3, 'price' => 1000, 'product_type' => 'simple']], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
            $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
                VALUES (?, 'R56N', 'r56n@example.test', '{\"country\":\"SK\"}', 3000, 3000, 'eur', ?, 'paid', 'gopay', ?, ?, 'pickup', 'domestic', ?, 'default')")
                ->execute(['R56N-' . $paymentId, $status, (string)$paymentId, json_encode(['provider' => 'gopay', 'service_type' => 'online_card', 'requires_online' => true]), json_encode($snapshot)]);
            $id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R56N', 'R56N', 3, 1000, 3000)")->execute([$id, $product]);
            InvoiceService::issue($id);
            $gw[(string)$paymentId] = ['modes' => [], 'refunded' => 0, 'calls' => 0];
        }
        file_put_contents($gopayFile, json_encode($gw));
        break;
    case 'request':
        $gateway($args[0], static fn(array $p): array => ['modes' => array_slice($args, 2)] + $p);
        PaymentService::requestRefundAndQueue($orderId($args[0]), (int)$args[1], 'r56n_request_' . $args[0], ['reason' => 'R56N']);
        $out = ['payment' => (string)Orders::byId($orderId($args[0]))['payment_status']];
        break;
    case 'refundcall':
        // The refund job up to its provider call and booking (PaymentService::refund()), stopped before settleRefundJob().
        $job = $jobsOf($args[0])[0];
        $result = PaymentService::refund((int)$job['payload']['order_id'], $args[0], (int)$job['payload']['amount'], Adapters::payment(), (string)$job['payload']['idempotency_key'], 'eur');
        $out = ['result' => (string)($result['status'] ?? ''), 'payment' => (string)Orders::byId($orderId($args[0]))['payment_status'], 'refunded' => PaymentService::refundedAmount($orderId($args[0]))];
        break;
    case 'dashboard':
        $gateway($args[0], static fn(array $p): array => ['refunded' => 3000] + $p);
        break;
    case 'book':
        PaymentService::bookProviderRefund($orderId($args[0]), 'gopay', 'r56n-dash-' . $args[0], 3000, 'refund.succeeded', 'r56n-dash-' . $args[0]);
        break;
    case 'decline':
        PaymentService::declinedRefundRequest($orderId($args[0]), 'r56n_request_' . $args[0] . '_execute', 'R56N declined');
        break;
    case 'notify':
        $result = PaymentService::handlePulledNotification(['id' => $args[0]], Adapters::payment(), 'gopay');
        $out = ['handled' => (bool)($result['handled'] ?? false)];
        break;
    case 'work':
        foreach (glob($dir . '/jobs/pending/*.json') ?: [] as $file) {
            $job = json_decode(file_get_contents($file), true);
            unset($job['retry_after']);
            file_put_contents($file, json_encode($job));
        }
        foreach (JobQueue::pull(50) as $job) {
            if (($job['type'] ?? '') !== 'payment_refund' || (string)($job['payload']['payment_id'] ?? '') !== $args[0]) continue;
            $claim = JobQueue::markProcessing($job);
            if ($claim === '') continue;
            JobQueue::beginWork($claim);
            try {
                WorkerJobs::handlePaymentRefund($job['payload'], $job);
                JobQueue::complete($claim);
                $out[] = 'done';
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
        $incidents = $db->prepare("SELECT severity FROM operational_incidents WHERE code = 'payment.refund_failed' AND context_json LIKE ? ORDER BY occurred_at");
        $incidents->execute(['%"order_id":' . $id . '%']);
        $out = ['order' => (string)$order['status'], 'payment' => (string)$order['payment_status'], 'refunded' => PaymentService::refundedAmount($id),
            'gopay' => (int)$gw['refunded'], 'calls' => (int)$gw['calls'], 'incidents' => $incidents->fetchAll(PDO::FETCH_COLUMN)];
        break;
}
print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r56nDrivers = ['sqlite' => $r56nRoot . '/refunds.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r56nDrivers['mysql'] = 'bbe_r56n_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R56 refunds native MySQL (set TEST_MYSQL_DSN)\n";
$r56nStep = static function(string ...$args) use ($r56nRoot): array {
    $process = proc_open(array_merge([PHP_BINARY, $r56nRoot . '/step.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    return $exit === 0 && is_array($decoded) ? $decoded : ['error' => "exit {$exit}: " . substr($stdout . $stderr, -1500)];
};
$r56nWindow = '5600000001';  // medium 2: REFUNDED between the job's booking and its settleRefundJob()
$r56nRefused = '5600000002'; // low: the admin refund GoPay refuses after the dashboard refunded everything
$r56nDecline = '5600000003'; // low: a declined request after the dashboard refunded everything: a warning, not critical
$r56nState = static fn(array $s): array => [$s['order'] ?? ($s['error'] ?? ''), $s['payment'] ?? '', $s['refunded'] ?? -1];
try {
    foreach ($r56nDrivers as $r56nDriver => $r56nTarget) {
        $tag = "R56 refunds {$r56nDriver}";
        @array_map('unlink', glob($r56nRoot . '/jobs/*/*.json') ?: []);
        if ($r56nDriver === 'sqlite') @unlink($r56nTarget);
        file_put_contents($r56nRoot . '/fixture.json', json_encode(['driver' => $r56nDriver, 'target' => $r56nTarget, 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        assert_equals([], $r56nStep('setup', json_encode([$r56nWindow => 'processing', $r56nRefused => 'processing', $r56nDecline => 'processing'])), "{$tag}: isolated fixture migrated");

        assert_equals(['payment' => 'refund_pending'], $r56nStep('request', $r56nWindow, '500', 'finished'), "{$tag}: an admin refund of 5.00 requested");
        assert_equals(['result' => 'succeeded', 'payment' => 'refund_pending', 'refunded' => 500], $r56nStep('refundcall', $r56nWindow),
            "{$tag}: GoPay executed it and the job booked it; the payment is still refund_pending (the window before settleRefundJob)");
        $r56nStep('dashboard', $r56nWindow);
        assert_equals(['handled' => true], $r56nStep('notify', $r56nWindow), "{$tag}: the REFUNDED notification in the window is handled");
        assert_equals(['refunded', 'refunded', 3000], $r56nState($r56nStep('state', $r56nWindow)),
            "{$tag}: REFUNDED in the window books the rest refunded in the dashboard and closes the order (was: ignored, 500 in the ledger, processing)");
        assert_equals(['done'], $r56nStep('work', $r56nWindow), "{$tag}: the job then finishes its bookkeeping");
        $r56nWindowState = $r56nStep('state', $r56nWindow);
        assert_equals([['refunded', 'refunded', 3000], 1], [$r56nState($r56nWindowState), $r56nWindowState['calls'] ?? -1], "{$tag}: one GoPay call, nothing booked twice");

        $r56nStep('request', $r56nRefused, '500', 'refused');
        $r56nStep('dashboard', $r56nRefused);
        assert_equals(['done'], $r56nStep('work', $r56nRefused),
            "{$tag}: the admin refund GoPay refuses because the dashboard refunded everything is settled by the REFUNDED status (was: the job failed until it died)");
        $r56nRefusedState = $r56nStep('state', $r56nRefused);
        assert_equals([['refunded', 'refunded', 3000], []], [$r56nState($r56nRefusedState), $r56nRefusedState['incidents'] ?? null], "{$tag}: the order is closed, no refund_failed incident");

        $r56nStep('request', $r56nDecline, '500');
        $r56nStep('book', $r56nDecline);
        $r56nStep('decline', $r56nDecline);
        $r56nDeclineState = $r56nStep('state', $r56nDecline);
        assert_equals([['refunded', 'refunded', 3000], ['warning']], [$r56nState($r56nDeclineState), $r56nDeclineState['incidents'] ?? null],
            "{$tag}: a declined request after the dashboard refunded everything is a warning (the customer has the money), not a critical incident");
    }
} finally {
    if (isset($r56nDrivers['mysql'])) {
        file_put_contents($r56nRoot . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $r56nDrivers['mysql'], 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        $r56nStep('teardown');
    }
    foreach (glob($r56nRoot . '/jobs/*/*.json') ?: [] as $r56nFile) @unlink($r56nFile);
}
