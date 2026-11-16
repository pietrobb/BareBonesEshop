<?php

// Review round 13 (R39, medium 2-4): refunds at GoPay (no idempotent refunds, no refund ids) decided by the admin.
// 2. "Not executed" on a whole-order refund whose answer was lost returned the payment to paid, and an item refund GoPay
//    had accepted next to it was offered no action any more (paid, GoPay 10.00 out, ledger 0).
// 3. An item refund (credit note) whose answer was lost offered only "release", which reversed the credit note GoPay had
//    paid out: it now offers "confirm executed" / "not executed" like a whole-order refund.
// 4. "Paid" set by hand on a refund GoPay accepted dropped it (ledger 0, its confirmation gone), with orders.payments only.
// Real GoPayPayment (only its HTTP transport stubbed), the worker's job loop, the admin order page and its handlers, on
// SQLite and MySQL.
echo "\n--- R39 GoPay refunds the admin decides: item refunds next to a request, lost item answers, manual paid ---\n";
$r39rRoot = test_fixture_path('refund-manual-r39');
mkdir($r39rRoot, 0700, true);
file_put_contents($r39rRoot . '/step.php', <<<'PHP'
<?php
[, $phase] = $argv;
$args = array_slice($argv, 2);
$dir = __DIR__;
$fx = json_decode(file_get_contents($dir . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
require $fx['root'] . '/src/autoload.php';
require_once $fx['root'] . '/adapters/PaymentAdapter.php';
require_once $fx['root'] . '/adapters/contrib/GoPayPayment.php';
foreach (['Shared', 'Orders'] as $module) require_once $fx['root'] . "/src/Entrypoints/Admin/{$module}.php";
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
$itemRefund = static fn(int $id): array => $db->query("SELECT id, status, amount FROM order_refunds WHERE order_id = {$id} ORDER BY id DESC LIMIT 1")->fetch() ?: [];

// GoPay: each refund call takes the payment's next mode: 'accepted' (executed, answered ACCEPTED), 'timeout_exec'
// (executed, answer lost), 'timeout' (lost before GoPay); the payment inquiry reports what it executed.
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
        throw new RuntimeException('GoPay transport error: Operation timed out after 30001 milliseconds');
    }
    $refunded = (int)$gw[$id]['refunded'];
    $state = $refunded === 0 ? 'PAID' : ($refunded >= 2000 ? 'REFUNDED' : 'PARTIALLY_REFUNDED');
    return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'state' => $state, 'order_number' => 'R39R-' . $id, 'amount' => 2000,
        'currency' => 'EUR', 'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])];
};
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new GoPayPayment(['gopay_goid' => '8123456789', 'gopay_client_id' => 'c', 'gopay_client_secret' => 's'], $transport)]);
$setModes = static function(string $paymentId, array $modes) use ($gopayFile): void {
    $gw = json_decode(file_get_contents($gopayFile), true);
    $gw[$paymentId]['modes'] = $modes;
    file_put_contents($gopayFile, json_encode($gw));
};
$admin = static function(int $id): void {
    $_SESSION = ['admin_role' => 'owner', 'admin_user' => 'r39r-admin', 'csrf_token' => 'r39r-token'];
    $_GET = ['id' => (string)$id];
};

$out = [];
switch ($phase) {
    case 'setup':
        (new Migrator($db, $fx['root'] . '/migrations', $dir . '/migrate.lock'))->apply();
        $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r39r', 'R39R', 'r39r', 0)");
        $category = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R39R', 'r39r', 'R39R', 1000, 50, 'active')")->execute([$category]);
        $product = (int)$db->lastInsertId();
        $gw = [];
        foreach (json_decode($args[0], true) as $paymentId) {
            $snapshot = VatService::buildSnapshot([['product_id' => $product, 'sku' => 'R39R', 'qty' => 2, 'price' => 1000, 'product_type' => 'simple']], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
            $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
                VALUES (?, 'R39R', 'r39r@example.test', '{\"country\":\"SK\"}', 2000, 2000, 'eur', 'delivered', 'paid', 'gopay', ?, ?, 'pickup', 'domestic', ?, 'default')")
                ->execute(['R39R-' . $paymentId, $paymentId, json_encode(['provider' => 'gopay', 'service_type' => 'online_card', 'requires_online' => true]), json_encode($snapshot)]);
            $id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R39R', 'R39R', 2, 1000, 2000)")->execute([$id, $product]);
            InvoiceService::issue($id);
            $gw[$paymentId] = ['modes' => [], 'refunded' => 0, 'calls' => 0];
        }
        file_put_contents($gopayFile, json_encode($gw));
        break;
    case 'request':
        $setModes($args[0], array_slice($args, 2));
        PaymentService::requestRefundAndQueue($orderId($args[0]), (int)$args[1], 'r39r_request_' . $args[0] . '_' . $args[1], ['reason' => 'R39R']);
        break;
    case 'credit':
        // Returned items credited (10.00 each): the credit note queues its item refund.
        $setModes($args[0], array_slice($args, 2));
        $id = $orderId($args[0]);
        $qty = (int)$args[1];
        $item = (int)$db->query("SELECT id FROM order_items WHERE order_id = {$id}")->fetchColumn();
        $invoice = (int)$db->query("SELECT id FROM invoices WHERE order_id = {$id} AND type = 'invoice'")->fetchColumn();
        [, $rma] = Rma::create($id, null, 'R39R return', [$item => $qty]);
        Rma::setStatus((int)$rma, 'approved');
        $returned = (int)Rma::items((int)$rma)[0]['id'];
        Rma::receive((int)$rma, [$returned => ['quantity' => $qty, 'disposition' => 'repair']], 'receipt-r39r-' . $args[0]);
        InvoiceService::credit($invoice, (int)$rma, [$returned => $qty], 'r39r-item-' . $args[0]);
        break;
    case 'notify':
        // GoPay's notification of the payment (pulled: the state comes from its API).
        $out = PaymentService::handlePulledNotification(['id' => $args[0]], Adapters::payment(), 'gopay');
        $out = ['handled' => (bool)($out['handled'] ?? false)];
        break;
    case 'work':
        // One worker pass as cli/worker.php runs it; the retry delay of the last pass has passed.
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
            $key = (string)$job['payload']['payment_id'] . (empty($job['payload']['refund_id']) ? '' : '/item');
            try {
                WorkerJobs::handlePaymentRefund($job['payload'], $job);
                JobQueue::complete($claim);
                $out[$key] = 'done';
            } catch (Throwable $e) {
                JobQueue::fail($claim, $job, $e->getMessage());
                $out[$key] = 'failed';
            } finally {
                JobQueue::endWork();
            }
        }
        ksort($out);
        break;
    case 'state':
        $id = $orderId($args[0]);
        $gw = json_decode(file_get_contents($gopayFile), true);
        $incidents = $db->prepare("SELECT COUNT(*) FROM operational_incidents WHERE code = 'payment.refund_outcome_unknown' AND context_json LIKE ?");
        $incidents->execute(['%"order_id":' . $id . '%']);
        $out = ['payment' => (string)Orders::byId($id)['payment_status'], 'refunded' => PaymentService::refundedAmount($id),
            'balance' => PaymentService::refundableBalance($id), 'gopay' => (int)$gw[$args[0]]['refunded'], 'item' => $itemRefund($id)['status'] ?? null,
            'incident' => (int)$incidents->fetchColumn()];
        break;
    case 'admin':
        $id = $orderId($args[0]);
        $admin($id);
        ob_start();
        pageOrder();
        $page = (string)ob_get_clean();
        $out['confirm'] = str_contains($page, 'action=refund_confirm');
        $out['not_executed'] = str_contains($page, 'data-refund-not-executed');
        $out['release'] = str_contains($page, 'action=refund_release');
        $out['offered'] = adminManualPaymentTransitions(Orders::byId($id));
        $out['amount'] = PaymentService::unconfirmedProviderRefund(Orders::byId($id))['amount'] ?? null;
        if (($args[1] ?? '') !== '') {
            // R40: the form posts the refund it shows (request key, amount), as a browser would.
            preg_match_all('/name="(request_key|refund_amount)" value="([^"]*)"/', $page, $shown, PREG_SET_ORDER);
            $_POST = ['csrf_token' => 'r39r-token', 'note' => 'Checked in the GoPay portal'] + array_column(array_map(static fn(array $m): array => [$m[1], html_entity_decode($m[2])], $shown), 1, 0) + ($args[1] === 'not_executed' ? ['outcome' => 'not_executed'] : []);
            register_shutdown_function(static function() use (&$out): void {
                $out['flash'] = $_SESSION['flash']['type'] ?? null;
                print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            });
            handleOrderRefundConfirm(); // redirects and exits
        }
        break;
    case 'manual':
        $id = $orderId($args[0]);
        $admin($id);
        $_POST = ['csrf_token' => 'r39r-token', 'new_payment_status' => $args[1], 'amount' => '', 'note' => 'R39R by hand'];
        register_shutdown_function(static function() use (&$out): void {
            $out['flash'] = $_SESSION['flash']['type'] ?? null;
            print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        });
        handleOrderPaymentTransition(); // redirects and exits
}
print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r39rDrivers = ['sqlite' => $r39rRoot . '/refunds.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r39rDrivers['mysql'] = 'bbe_r39r_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R39 refund manual native MySQL (set TEST_MYSQL_DSN)\n";
$r39rStep = static function(string ...$args) use ($r39rRoot): array {
    $process = proc_open(array_merge([PHP_BINARY, $r39rRoot . '/step.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    return $exit === 0 && is_array($decoded) ? $decoded : ['error' => "exit {$exit}: " . substr($stdout . $stderr, -1500)];
};
$r39rOrphan = '3900000001';   // medium 2: request lost before GoPay, then an item refund GoPay accepted
$r39rItemPaid = '3900000002'; // medium 3: item refund executed, answer lost
$r39rItemLost = '3900000003'; // medium 3: item refund lost before GoPay
$r39rManual = '3900000004';   // medium 4: request GoPay accepted, then "paid" by hand
$r39rSettled = '3900000005';  // low: item refund of the whole payment accepted, then GoPay reports REFUNDED
$r39rNotified = '3900000006'; // low: whole refund executed, answer lost, GoPay's notification before the retry
$r39rState = static fn(array $s): array => [$s['payment'] ?? ($s['error'] ?? ''), $s['refunded'] ?? -1, $s['balance'] ?? -1, $s['gopay'] ?? -1, $s['item'] ?? null];
try {
    foreach ($r39rDrivers as $r39rDriver => $r39rTarget) {
        $tag = "R39 refund manual {$r39rDriver}";
        @array_map('unlink', glob($r39rRoot . '/jobs/*/*.json') ?: []);
        if ($r39rDriver === 'sqlite') @unlink($r39rTarget);
        file_put_contents($r39rRoot . '/fixture.json', json_encode(['driver' => $r39rDriver, 'target' => $r39rTarget, 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        assert_equals([], $r39rStep('setup', json_encode([$r39rOrphan, $r39rItemPaid, $r39rItemLost, $r39rManual, $r39rSettled, $r39rNotified])), "{$tag}: isolated fixture migrated");

        // Medium 2.
        $r39rStep('request', $r39rOrphan, '500', 'timeout');
        $r39rStep('work');
        $r39rStep('work');
        $r39rStep('credit', $r39rOrphan, '1', 'accepted');
        $r39rStep('work');
        $released = $r39rStep('admin', $r39rOrphan, 'not_executed');
        assert_equals('ok', $released['flash'] ?? ($released['error'] ?? ''), "{$tag}: the lost whole-order request is released as not executed");
        $after = $r39rStep('admin', $r39rOrphan);
        assert_equals([true, 1000], [$after['confirm'] ?? ($after['error'] ?? null), $after['amount'] ?? null],
            "{$tag}: the item refund GoPay accepted is still offered for confirmation after the release (was: no action, ledger 0)");
        $r39rStep('admin', $r39rOrphan, 'confirm');
        assert_equals(['partially_refunded', 1000, 1000, 1000, 'succeeded'], $r39rState($r39rStep('state', $r39rOrphan)),
            "{$tag}: confirmed: the ledger holds the 10.00 GoPay paid out, the item refund succeeded");

        // Medium 3.
        foreach ([$r39rItemPaid => 'timeout_exec', $r39rItemLost => 'timeout'] as $payment => $mode) {
            $r39rStep('credit', $payment, '1', $mode);
            $r39rStep('work');
            $r39rStep('work');
            $page = $r39rStep('admin', $payment);
            assert_equals([true, true, false], [$page['confirm'] ?? ($page['error'] ?? null), $page['not_executed'] ?? null, $page['release'] ?? null],
                "{$tag}: {$mode}: an item refund whose answer was lost offers confirm-executed and not-executed, not only release (was: release only)");
        }
        $r39rStep('admin', $r39rItemPaid, 'confirm');
        assert_equals(['partially_refunded', 1000, 1000, 1000, 'succeeded'], $r39rState($r39rStep('state', $r39rItemPaid)),
            "{$tag}: confirmed executed: the item refund GoPay paid out is booked (was: released, its credit note reversed)");
        $r39rStep('admin', $r39rItemLost, 'not_executed');
        assert_equals(['paid', 0, 2000, 0, 'failed'], $r39rState($r39rStep('state', $r39rItemLost)),
            "{$tag}: not executed: the item refund is released (credit note reversed), the whole payment refundable again");

        // Medium 4.
        $r39rStep('request', $r39rManual, '500', 'accepted');
        $r39rStep('work');
        $page = $r39rStep('admin', $r39rManual);
        assert_true(!in_array('paid', $page['offered'] ?? ['paid'], true), "{$tag}: \"paid\" is not offered on a refund GoPay accepted (" . json_encode($page['offered'] ?? $page) . ')');
        $manual = $r39rStep('manual', $r39rManual, 'paid');
        assert_equals(['err', 'refund_pending', 0, 500], [$manual['flash'] ?? ($manual['error'] ?? ''), ...array_slice($r39rState($r39rStep('state', $r39rManual)), 0, 2), $r39rStep('admin', $r39rManual)['amount'] ?? null],
            "{$tag}: \"paid\" by hand is refused: the accepted refund stays for confirmation (was: paid, ledger 0, confirmation gone)");

        // Low: an item refund of the whole payment GoPay accepted is settled by its REFUNDED notification.
        $r39rStep('credit', $r39rSettled, '2', 'accepted');
        $r39rStep('work');
        $r39rStep('notify', $r39rSettled);
        assert_equals(['refunded', 2000, 0, 2000, 'succeeded'], $r39rState($r39rStep('state', $r39rSettled)),
            "{$tag}: GoPay REFUNDED settles the item refund it accepted (was: pending for good, ledger 0)");

        // Low: a whole-order refund whose answer was lost, settled by GoPay's notification before the job's retry.
        $r39rStep('request', $r39rNotified, '2000', 'timeout_exec');
        $r39rStep('work');
        $r39rStep('notify', $r39rNotified);
        $r39rStep('work');
        $notified = $r39rStep('state', $r39rNotified);
        assert_equals(['refunded', 2000, 0, 0], [...array_slice($r39rState($notified), 0, 3), $notified['incident'] ?? -1],
            "{$tag}: the retry finds the refund settled: booked once, no \"refund outcome unknown\" incident");
    }
} finally {
    if (isset($r39rDrivers['mysql'])) {
        file_put_contents($r39rRoot . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $r39rDrivers['mysql'], 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        $r39rStep('teardown');
    }
}
