<?php

// Review round 19 (R54), refunds:
// medium 3. GoPay/ThePay reported the payment REFUNDED (refunded in the dashboard) while a credit note's refund was still
//    in flight. Its job not yet accepted: the whole balance was booked outside the shop and the credit note's job then
//    tried GoPay again (money without its credit note). Accepted and smaller than the balance: the notification was
//    ignored for good, the payment stayed unsettled although GoPay had refunded everything.
// medium 4. A refund job that returned all the money of a processing order left it to be shipped (processing → refunded
//    is no transition and the order was not cancelled first).
// medium 7. The refund of a paid order whose unshipped rest was cancelled split its credit note over the whole invoice,
//    eating into the shipped goods' rates, so their later return was cut short.
// Real GoPayPayment (only its HTTP transport stubbed), the worker's refund job, on SQLite and MySQL.
echo "\n--- R54 refunds: GoPay REFUNDED during a credit note refund, worker full refund closes the order, cancelled rest VAT ---\n";
$r54fRoot = test_fixture_path('refund-r54');
mkdir($r54fRoot, 0700, true);
file_put_contents($r54fRoot . '/step.php', <<<'PHP'
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

// GoPay: each refund call takes the payment's next mode: 'accepted' (ACCEPTED, finished later), 'finished' (FINISHED),
// none left: a timeout before GoPay. The payment inquiry reports what GoPay refunded.
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
        if ($mode !== 'timeout') $gw[$id]['refunded'] += (int)$form['amount'];
        file_put_contents($gopayFile, json_encode($gw));
        if ($mode === 'accepted') return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'result' => 'ACCEPTED'])];
        if ($mode === 'finished') return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'result' => 'FINISHED'])];
        throw new RuntimeException('GoPay transport error: Operation timed out after 30001 milliseconds');
    }
    $refunded = (int)$gw[$id]['refunded'];
    $state = $refunded === 0 ? 'PAID' : ($refunded >= 2000 ? 'REFUNDED' : 'PARTIALLY_REFUNDED');
    return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'state' => $state, 'order_number' => 'R54F-' . $id, 'amount' => 2000,
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
        $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r54f', 'R54F', 'r54f', 0)");
        $category = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R54F', 'r54f', 'R54F', 1000, 50, 'active')")->execute([$category]);
        $product = (int)$db->lastInsertId();
        $gw = [];
        foreach (json_decode($args[0], true) as $paymentId => $status) {
            $snapshot = VatService::buildSnapshot([['product_id' => $product, 'sku' => 'R54F', 'qty' => 2, 'price' => 1000, 'product_type' => 'simple']], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
            $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
                VALUES (?, 'R54F', 'r54f@example.test', '{\"country\":\"SK\"}', 2000, 2000, 'eur', ?, 'paid', 'gopay', ?, ?, 'pickup', 'domestic', ?, 'default')")
                ->execute(['R54F-' . $paymentId, $status, (string)$paymentId, json_encode(['provider' => 'gopay', 'service_type' => 'online_card', 'requires_online' => true]), json_encode($snapshot)]);
            $id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R54F', 'R54F', 2, 1000, 2000)")->execute([$id, $product]);
            InvoiceService::issue($id);
            $gw[(string)$paymentId] = ['modes' => [], 'refunded' => 0, 'calls' => 0];
        }
        file_put_contents($gopayFile, json_encode($gw));
        break;
    case 'request':
        $gateway($args[0], static fn(array $p): array => ['modes' => array_slice($args, 2)] + $p);
        PaymentService::requestRefundAndQueue($orderId($args[0]), (int)$args[1], 'r54f_request_' . $args[0], ['reason' => 'R54F']);
        break;
    case 'credit':
        // One returned item credited (10.00): the credit note queues its item refund.
        $gateway($args[0], static fn(array $p): array => ['modes' => array_slice($args, 1)] + $p);
        $id = $orderId($args[0]);
        $item = (int)$db->query("SELECT id FROM order_items WHERE order_id = {$id}")->fetchColumn();
        $invoice = (int)$db->query("SELECT id FROM invoices WHERE order_id = {$id} AND type = 'invoice'")->fetchColumn();
        [, $rma] = Rma::create($id, null, 'R54F return', [$item => 1]);
        Rma::setStatus((int)$rma, 'approved');
        $returned = (int)Rma::items((int)$rma)[0]['id'];
        Rma::receive((int)$rma, [$returned => ['quantity' => 1, 'disposition' => 'repair']], 'receipt-r54f-' . $args[0]);
        InvoiceService::credit($invoice, (int)$rma, [$returned => 1], 'r54f-item-' . $args[0]);
        break;
    case 'dashboard':
        // The merchant refunds the rest of the payment in the GoPay dashboard.
        $gateway($args[0], static fn(array $p): array => ['refunded' => 2000] + $p);
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
        $out = ['order' => (string)$order['status'], 'payment' => (string)$order['payment_status'], 'refunded' => PaymentService::refundedAmount($id),
            'gopay' => (int)$gw['refunded'], 'calls' => (int)$gw['calls'],
            'item' => $db->query("SELECT status FROM order_refunds WHERE order_id = {$id} ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null];
        break;
}
print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r54fDrivers = ['sqlite' => $r54fRoot . '/refunds.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r54fDrivers['mysql'] = 'bbe_r54f_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R54 refunds native MySQL (set TEST_MYSQL_DSN)\n";
$r54fStep = static function(string ...$args) use ($r54fRoot): array {
    $process = proc_open(array_merge([PHP_BINARY, $r54fRoot . '/step.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    return $exit === 0 && is_array($decoded) ? $decoded : ['error' => "exit {$exit}: " . substr($stdout . $stderr, -1500)];
};
$r54fQueued = '5400000001';   // medium 3: credit note's job still queued, the rest refunded in the dashboard
$r54fAccepted = '5400000002'; // medium 3: credit note's refund accepted (smaller than the balance), the rest in the dashboard
$r54fProcessing = '5400000003'; // medium 4: the whole payment of a processing order refunded by the worker
$r54fState = static fn(array $s): array => [$s['order'] ?? ($s['error'] ?? ''), $s['payment'] ?? '', $s['refunded'] ?? -1, $s['gopay'] ?? -1, $s['calls'] ?? -1, $s['item'] ?? null];
try {
    foreach ($r54fDrivers as $r54fDriver => $r54fTarget) {
        $tag = "R54 refunds {$r54fDriver}";
        @array_map('unlink', glob($r54fRoot . '/jobs/*/*.json') ?: []);
        if ($r54fDriver === 'sqlite') @unlink($r54fTarget);
        file_put_contents($r54fRoot . '/fixture.json', json_encode(['driver' => $r54fDriver, 'target' => $r54fTarget, 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        assert_equals([], $r54fStep('setup', json_encode([$r54fQueued => 'delivered', $r54fAccepted => 'delivered', $r54fProcessing => 'processing'])), "{$tag}: isolated fixture migrated");

        $r54fStep('credit', $r54fQueued);
        $r54fStep('dashboard', $r54fQueued);
        $r54fStep('notify', $r54fQueued);
        assert_equals(['delivered', 'refunded', 2000, 2000, 0, 'succeeded'], $r54fState($r54fStep('state', $r54fQueued)),
            "{$tag}: REFUNDED while a credit note's job waits books the credit note with it and only the rest outside the shop (was: the whole balance outside, credit note pending)");
        $r54fStep('work');
        assert_equals(['delivered', 'refunded', 2000, 2000, 0, 'succeeded'], $r54fState($r54fStep('state', $r54fQueued)),
            "{$tag}: the credit note's job then finishes without calling GoPay again");

        $r54fStep('credit', $r54fAccepted, 'accepted');
        $r54fStep('work');
        $r54fStep('dashboard', $r54fAccepted);
        $r54fStep('notify', $r54fAccepted);
        assert_equals(['delivered', 'refunded', 2000, 2000, 1, 'succeeded'], $r54fState($r54fStep('state', $r54fAccepted)),
            "{$tag}: REFUNDED after GoPay accepted a smaller credit note refund settles it and books the rest (was: ignored for good, ledger 0)");

        $r54fStep('request', $r54fProcessing, '2000', 'finished');
        $r54fStep('work');
        assert_equals(['refunded', 'refunded', 2000, 2000, 1, null], $r54fState($r54fStep('state', $r54fProcessing)),
            "{$tag}: a refund job that returned all the money of a processing order closes it (was: processing, to be shipped)");
    }
} finally {
    if (isset($r54fDrivers['mysql'])) {
        file_put_contents($r54fRoot . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $r54fDrivers['mysql'], 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        $r54fStep('teardown');
    }
}

// --- Medium 7: the refund of a cancelled unshipped rest credits the goods that never shipped, at their own rate ------
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
try {
    $r54fCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r54fWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class) VALUES
        (98541, 'default', {$r54fCategory}, 'R54F-STD', 'r54f-std', 'simple', 'R54F standard', 4000, 'eur', 5, 'active', 'deny', 'standard'),
        (98542, 'default', {$r54fCategory}, 'R54F-BOOK', 'r54f-book', 'simple', 'R54F book', 2000, 'eur', 5, 'active', 'deny', 'books')");
    Warehouse::setStock($r54fWarehouse, 'R54F-STD', 5, 'count', 'test', 'r54f-opening-std');
    Warehouse::setStock($r54fWarehouse, 'R54F-BOOK', 5, 'count', 'test', 'r54f-opening-book');
    $r54fLine = static fn(int $productId, string $sku, int $price): array => ['product_id' => $productId, 'variant_id' => null, 'sku' => $sku,
        'name' => $sku, 'qty' => 1, 'price' => $price, 'attrs' => []];
    $r54fOrder = Orders::create([$r54fLine(98541, 'R54F-STD', 4000), $r54fLine(98542, 'R54F-BOOK', 2000)],
        ['name' => 'R54F Buyer', 'email' => 'r54f@example.test', 'phone' => '', 'address' => ['country' => 'SK'], 'shipping_cost' => 0, 'tax_amount' => 0,
            'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
    $r54fSnapshot = VatService::buildSnapshot([
        ['product_id' => 98541, 'sku' => 'R54F-STD', 'qty' => 1, 'price' => 4000, 'product_type' => 'simple', 'vat_class' => 'standard'],
        ['product_id' => 98542, 'sku' => 'R54F-BOOK', 'qty' => 1, 'price' => 2000, 'product_type' => 'simple', 'vat_class' => 'books'],
    ], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
    $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
        ->execute([json_encode($r54fSnapshot), $r54fOrder]);
    $r54fInvoice = InvoiceService::issue($r54fOrder);
    $r54fRates = array_keys((array)json_decode((string)$r54fInvoice['vat_breakdown'], true));
    assert_true(count($r54fRates) === 2, 'R54 cancelled rest: the invoice has two VAT rates (' . implode(', ', $r54fRates) . ')');
    // The standard item leaves; the book never ships and the rest is cancelled.
    $r54fParcels = Fulfillment::planOrder($r54fOrder);
    $r54fParcel = (int)$pdo->query("SELECT shipment_id FROM shipment_items WHERE sku = 'R54F-STD' AND shipment_id IN (" . implode(',', array_map('intval', $r54fParcels) ?: [0]) . ')')->fetchColumn();
    $pdo->exec("DELETE FROM shipment_items WHERE sku = 'R54F-BOOK' AND shipment_id = {$r54fParcel}");
    Fulfillment::ship($r54fParcel);
    OrderWorkflow::transition($r54fOrder, 'cancelled', 'R54 cancelled rest');
    $r54fUnshipped = (int)Fulfillment::unshippedGross($r54fOrder);
    assert_equals(2000, $r54fUnshipped, 'R54 cancelled rest: the book never shipped (20.00)');
    $r54fCredit = InvoiceService::creditRefund($r54fOrder, $r54fUnshipped, 'r54f-cancelled-rest');
    $r54fBookKey = '';
    foreach (json_decode((string)$r54fInvoice['vat_breakdown'], true) as $r54fKey => $r54fRate) {
        if ((int)($r54fRate['net'] ?? 0) + (int)($r54fRate['vat'] ?? 0) === 2000) $r54fBookKey = (string)$r54fKey;
    }
    assert_equals([$r54fBookKey], array_map('strval', array_keys((array)json_decode((string)($r54fCredit['vat_breakdown'] ?? '[]'), true))),
        'R54 cancelled rest: its refund credits the book\'s rate only (was: split over both rates, cutting the shipped item\'s later return short)');
    $r54fReturn = InvoiceService::creditRefund($r54fOrder, 4000, 'r54f-shipped-return');
    assert_equals(-4000, (int)($r54fReturn['total_gross'] ?? 0), 'R54 cancelled rest: the shipped item\'s return then credits its whole 40.00');
} catch (Throwable $e) {
    assert_true(false, 'R54 cancelled rest (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
foreach (glob($r54fRoot . '/jobs/*/*.json') ?: [] as $r54fFile) @unlink($r54fFile);
