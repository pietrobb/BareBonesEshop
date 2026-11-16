<?php

// Review round 14 (R40, medium 1): "Confirm executed" was not bound to the refund the admin saw. The form posted no refund
// key or amount and the handler re-found the refund awaiting confirmation itself: an older item refund B lost before
// GoPay (outcome unknown), a newer A GoPay accepted; the page offered A, a double click (or a stale page) confirmed B too,
// which never executed (ledger 2000, GoPay paid out 1000, both credit notes "refunded").
// Real GoPayPayment (only its HTTP transport stubbed), the worker's job loop, the admin order page and its handler, on
// SQLite and MySQL.
echo "\n--- R40 refund confirmation bound to the refund the admin saw (double click, stale page) ---\n";
$r40cRoot = test_fixture_path('refund-confirm-r40');
mkdir($r40cRoot, 0700, true);
file_put_contents($r40cRoot . '/step.php', <<<'PHP'
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

// GoPay: each refund call takes the payment's next mode: 'accepted' (executed, answered ACCEPTED), 'timeout' (lost before GoPay).
$gopayFile = $dir . '/gopay.json';
$transport = static function(string $method, string $url, array $headers, ?string $body) use ($gopayFile): array {
    if (str_contains($url, '/oauth2/token')) return ['status' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 1800])];
    $gw = json_decode(file_get_contents($gopayFile), true);
    if (!preg_match('#/payments/payment/(\d+)(/refund)?$#', (string)parse_url($url, PHP_URL_PATH), $m)) return ['status' => 404, 'body' => '{}'];
    $id = $m[1];
    if (($m[2] ?? '') === '/refund') {
        parse_str((string)$body, $form);
        $mode = array_shift($gw[$id]['modes']) ?? 'timeout';
        if ($mode !== 'timeout') $gw[$id]['refunded'] += (int)$form['amount'];
        file_put_contents($gopayFile, json_encode($gw));
        if ($mode === 'accepted') return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'result' => 'ACCEPTED'])];
        throw new RuntimeException('GoPay transport error: Operation timed out after 30001 milliseconds');
    }
    $refunded = (int)$gw[$id]['refunded'];
    $state = $refunded === 0 ? 'PAID' : ($refunded >= 2000 ? 'REFUNDED' : 'PARTIALLY_REFUNDED');
    return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'state' => $state, 'order_number' => 'R40C-' . $id, 'amount' => 2000,
        'currency' => 'EUR', 'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])];
};
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new GoPayPayment(['gopay_goid' => '8123456789', 'gopay_client_id' => 'c', 'gopay_client_secret' => 's'], $transport)]);
$admin = static function(int $id): void {
    $_SESSION = ['admin_role' => 'owner', 'admin_user' => 'r40c-admin', 'csrf_token' => 'r40c-token'];
    $_GET = ['id' => (string)$id];
};

$out = [];
switch ($phase) {
    case 'setup':
        (new Migrator($db, $fx['root'] . '/migrations', $dir . '/migrate.lock'))->apply();
        $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r40c', 'R40C', 'r40c', 0)");
        $category = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R40C', 'r40c', 'R40C', 1000, 50, 'active')")->execute([$category]);
        $product = (int)$db->lastInsertId();
        $paymentId = $args[0];
        $snapshot = VatService::buildSnapshot([['product_id' => $product, 'sku' => 'R40C', 'qty' => 2, 'price' => 1000, 'product_type' => 'simple']], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
        $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
            VALUES (?, 'R40C', 'r40c@example.test', '{\"country\":\"SK\"}', 2000, 2000, 'eur', 'delivered', 'paid', 'gopay', ?, ?, 'pickup', 'domestic', ?, 'default')")
            ->execute(['R40C-' . $paymentId, $paymentId, json_encode(['provider' => 'gopay', 'service_type' => 'online_card', 'requires_online' => true]), json_encode($snapshot)]);
        $id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R40C', 'R40C', 2, 1000, 2000)")->execute([$id, $product]);
        InvoiceService::issue($id);
        file_put_contents($gopayFile, json_encode([$paymentId => ['modes' => [], 'refunded' => 0]]));
        break;
    case 'credit':
        // One returned item credited (10.00): the credit note queues its item refund; GoPay answers with $args[1].
        $gw = json_decode(file_get_contents($gopayFile), true);
        $gw[$args[0]]['modes'] = [$args[1]];
        file_put_contents($gopayFile, json_encode($gw));
        $id = $orderId($args[0]);
        $item = (int)$db->query("SELECT id FROM order_items WHERE order_id = {$id}")->fetchColumn();
        $invoice = (int)$db->query("SELECT id FROM invoices WHERE order_id = {$id} AND type = 'invoice'")->fetchColumn();
        [, $rma] = Rma::create($id, null, 'R40C return ' . $args[2], [$item => 1]);
        Rma::setStatus((int)$rma, 'approved');
        $returned = (int)Rma::items((int)$rma)[0]['id'];
        Rma::receive((int)$rma, [$returned => ['quantity' => 1, 'disposition' => 'repair']], 'receipt-r40c-' . $args[2]);
        InvoiceService::credit($invoice, (int)$rma, [$returned => 1], 'r40c-item-' . $args[2]);
        $out = ['key' => (string)$db->query("SELECT idempotency_key FROM order_refunds WHERE order_id = {$id} ORDER BY id DESC LIMIT 1")->fetchColumn()];
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
            try {
                WorkerJobs::handlePaymentRefund($job['payload'], $job);
                JobQueue::complete($claim);
            } catch (Throwable $e) {
                JobQueue::fail($claim, $job, $e->getMessage());
            } finally {
                JobQueue::endWork();
            }
        }
        break;
    case 'page':
        // The admin order page: the refund its confirmation form shows (the hidden fields a browser posts back).
        $admin($orderId($args[0]));
        ob_start();
        pageOrder();
        $page = (string)ob_get_clean();
        preg_match_all('/name="(request_key|refund_amount)" value="([^"]*)"/', $page, $shown, PREG_SET_ORDER);
        $out = ['fields' => array_column(array_map(static fn(array $m): array => [$m[1], html_entity_decode($m[2])], $shown), 1, 0),
            'unknown' => str_contains($page, 'data-refund-not-executed')];
        break;
    case 'post':
        // The form posted with the fields of a page loaded earlier ($args[1]), "confirm executed" or "not executed".
        $admin($orderId($args[0]));
        $_POST = ['csrf_token' => 'r40c-token', 'note' => 'Checked in the GoPay portal'] + json_decode($args[1], true)
            + ($args[2] === 'not_executed' ? ['outcome' => 'not_executed'] : []);
        register_shutdown_function(static function() use (&$out): void {
            $out['flash'] = $_SESSION['flash']['type'] ?? null;
            print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        });
        handleOrderRefundConfirm(); // redirects and exits
        break;
    case 'state':
        $id = $orderId($args[0]);
        $gw = json_decode(file_get_contents($gopayFile), true);
        $out = ['payment' => (string)Orders::byId($id)['payment_status'], 'refunded' => PaymentService::refundedAmount($id),
            'gopay' => (int)$gw[$args[0]]['refunded'],
            'items' => $db->query("SELECT idempotency_key, status FROM order_refunds WHERE order_id = {$id} ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR)];
}
print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r40cDrivers = ['sqlite' => $r40cRoot . '/refunds.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r40cDrivers['mysql'] = 'bbe_r40c_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R40 refund confirm native MySQL (set TEST_MYSQL_DSN)\n";
$r40cStep = static function(string ...$args) use ($r40cRoot): array {
    $process = proc_open(array_merge([PHP_BINARY, $r40cRoot . '/step.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    return $exit === 0 && is_array($decoded) ? $decoded : ['error' => "exit {$exit}: " . substr($stdout . $stderr, -1500)];
};
$r40cPayment = '4000000001';
try {
    foreach ($r40cDrivers as $r40cDriver => $r40cTarget) {
        $tag = "R40 refund confirm {$r40cDriver}";
        @array_map('unlink', glob($r40cRoot . '/jobs/*/*.json') ?: []);
        if ($r40cDriver === 'sqlite') @unlink($r40cTarget);
        file_put_contents($r40cRoot . '/fixture.json', json_encode(['driver' => $r40cDriver, 'target' => $r40cTarget, 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        assert_equals([], $r40cStep('setup', $r40cPayment), "{$tag}: isolated fixture migrated");

        // B: lost before GoPay, its job gone (outcome unknown). A: accepted by GoPay, no refund id.
        $keyB = (string)($r40cStep('credit', $r40cPayment, 'timeout', 'b')['key'] ?? '');
        $r40cStep('work');
        $r40cStep('work');
        $keyA = (string)($r40cStep('credit', $r40cPayment, 'accepted', 'a')['key'] ?? '');
        $r40cStep('work');
        $shownA = $r40cStep('page', $r40cPayment);
        assert_equals([['request_key' => $keyA, 'refund_amount' => '1000'], false], [$shownA['fields'] ?? ($shownA['error'] ?? null), $shownA['unknown'] ?? null],
            "{$tag}: the page offers the refund GoPay accepted (A) and posts its key and amount");

        // Double click: the second submit of the same page must not confirm B.
        $first = $r40cStep('post', $r40cPayment, json_encode($shownA['fields'] ?? []), 'confirm');
        $second = $r40cStep('post', $r40cPayment, json_encode($shownA['fields'] ?? []), 'confirm');
        assert_equals(['ok', 'err'], [$first['flash'] ?? ($first['error'] ?? ''), $second['flash'] ?? ($second['error'] ?? '')],
            "{$tag}: the first click confirms A, the second (same page) is refused");
        $state = $r40cStep('state', $r40cPayment);
        assert_equals(['partially_refunded', 1000, 1000, [$keyB => 'pending', $keyA => 'succeeded']],
            [$state['payment'] ?? ($state['error'] ?? ''), $state['refunded'] ?? -1, $state['gopay'] ?? -1, $state['items'] ?? []],
            "{$tag}: the ledger holds what GoPay paid out (was: 2000 booked, B \"refunded\" though never executed)");

        // B is offered now as unknown; a stale page (A's fields) and a wrong amount are refused for either outcome.
        $shownB = $r40cStep('page', $r40cPayment);
        assert_equals([['request_key' => $keyB, 'refund_amount' => '1000'], true], [$shownB['fields'] ?? ($shownB['error'] ?? null), $shownB['unknown'] ?? null],
            "{$tag}: B, whose answer was lost, is offered next with its own key");
        $stale = $r40cStep('post', $r40cPayment, json_encode($shownA['fields'] ?? []), 'not_executed');
        $wrongAmount = $r40cStep('post', $r40cPayment, json_encode(['request_key' => $keyB, 'refund_amount' => '999']), 'confirm');
        $noFields = $r40cStep('post', $r40cPayment, '{}', 'confirm');
        assert_equals(['err', 'err', 'err'], [$stale['flash'] ?? ($stale['error'] ?? ''), $wrongAmount['flash'] ?? ($wrongAmount['error'] ?? ''), $noFields['flash'] ?? ($noFields['error'] ?? '')],
            "{$tag}: a stale page, a wrong amount and a post without the refund are refused");
        assert_equals([1000, 'pending'], [$r40cStep('state', $r40cPayment)['refunded'] ?? -1, $r40cStep('state', $r40cPayment)['items'][$keyB] ?? null],
            "{$tag}: the refused posts book and release nothing");

        // B checked at GoPay, not executed: released with its own fields.
        $released = $r40cStep('post', $r40cPayment, json_encode($shownB['fields'] ?? []), 'not_executed');
        $state = $r40cStep('state', $r40cPayment);
        assert_equals(['ok', 1000, 1000, 'failed'], [$released['flash'] ?? ($released['error'] ?? ''), $state['refunded'] ?? -1, $state['gopay'] ?? -1, $state['items'][$keyB] ?? null],
            "{$tag}: B released as not executed with the fields its page showed");
    }
} finally {
    if (isset($r40cDrivers['mysql'])) {
        file_put_contents($r40cRoot . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $r40cDrivers['mysql'], 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        $r40cStep('teardown');
    }
}
