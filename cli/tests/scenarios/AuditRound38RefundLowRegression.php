<?php

// Review round 12 (R38, low, unfinished refunds):
// 1. The credit note of a provider refund waited 30 minutes for an in-flight item refund of the same amount and then
//    failed for good, while that item refund's job retries after errors for about 9 hours (WorkerJobs::refundRetryWindow()).
//    It now waits until that job stopped retrying, then fails with a critical incident naming the order.
// 2. A stalled item refund whose provider refund lookup keeps failing (a Stripe outage, a key without refund read) could
//    never be released. The admin who checked the provider's dashboard may now release it without the check (audited).
// Real StripePayment (only its API call stubbed), the real file queue, the admin order page and its release handler, on
// SQLite and MySQL.
echo "\n--- R38 unfinished refunds: the credit note outwaits the refund job; release without the provider's check ---\n";
$r38qRoot = test_fixture_path('refund-low-r38');
mkdir($r38qRoot, 0700, true);
file_put_contents($r38qRoot . '/step.php', <<<'PHP'
<?php
[, $phase] = $argv;
$args = array_slice($argv, 2);
$dir = __DIR__;
$fx = json_decode(file_get_contents($dir . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
require $fx['root'] . '/src/autoload.php';
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

// Stripe whose refund list cannot be read (an outage): every lookup fails.
class R38qStripe extends StripePayment {
    public function __construct() { parent::__construct(['stripe_secret_key' => 'rk_test_r38q']); }
    protected function apiCall(string $method, string $endpoint, array $params = [], array $extraHeaders = []): array {
        if ($method === 'GET' && $endpoint === '/v1/refunds') throw new RuntimeException('Stripe API: An error occurred with our connection to Stripe (HTTP 503)', 503);
        throw new RuntimeException("R38q: unexpected Stripe call {$method} {$endpoint}");
    }
}
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new R38qStripe()]);
$order = static function(string $number) use ($db): int {
    $stmt = $db->prepare('SELECT id FROM orders WHERE order_number = ?');
    $stmt->execute([$number]);
    return (int)$stmt->fetchColumn();
};
$itemRefund = static fn(int $orderId): array => $db->query("SELECT id, status, amount FROM order_refunds WHERE order_id = {$orderId} ORDER BY id LIMIT 1")->fetch() ?: [];

$out = [];
switch ($phase) {
    case 'setup':
        (new Migrator($db, $fx['root'] . '/migrations', $dir . '/migrate.lock'))->apply();
        $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r38q', 'R38Q', 'r38q', 0)");
        $category = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R38Q', 'r38q', 'R38Q', 1000, 5, 'active')")->execute([$category]);
        $product = (int)$db->lastInsertId();
        foreach (['R38Q-STALLED', 'R38Q-CREDIT'] as $number) {
            $snapshot = VatService::buildSnapshot([['product_id' => $product, 'sku' => 'R38Q', 'qty' => 2, 'price' => 1000, 'product_type' => 'simple']], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
            $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
                VALUES (?, 'R38Q', 'r38q@example.test', '{\"country\":\"SK\"}', 2000, 2000, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default')")
                ->execute([$number, 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card']), json_encode($snapshot)]);
            $orderId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R38Q', 'R38Q', 2, 1000, 2000)")->execute([$orderId, $product]);
            $item = (int)$db->lastInsertId();
            $invoice = InvoiceService::issue($orderId);
            [, $rma] = Rma::create($orderId, null, 'R38Q return', [$item => 1]);
            Rma::setStatus((int)$rma, 'approved');
            $returned = (int)Rma::items((int)$rma)[0]['id'];
            Rma::receive((int)$rma, [$returned => ['quantity' => 1, 'disposition' => 'repair']], 'receipt-' . $number);
            InvoiceService::credit((int)$invoice['id'], (int)$rma, [$returned => 1], strtolower($number) . '-item');
        }
        // The stalled item refund's job died (its retries ran out); the other one's job still retries after errors.
        foreach (JobQueue::pull(100) as $job) {
            if ($job['type'] === 'payment_refund' && (int)($job['payload']['order_id'] ?? 0) === $order('R38Q-STALLED')) unlink($job['_file']);
        }
        // A refund made in the Stripe dashboard of the same amount, booked by its webhook, while the item refund still
        // has no provider refund id.
        $credit = $order('R38Q-CREDIT');
        $db->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
            VALUES (?, 'default', 'stripe', 're_r38q_dash', 'refunded', ?, 'eur', 'stripe_refund_re_r38q_dash', '{}')")->execute([$credit, (int)$itemRefund($credit)['amount']]);
        // 2.1.3 and older (red run): 12 requeues 5, 10, 20, 40 minutes, then hourly.
        $out = ['window' => method_exists(WorkerJobs::class, 'refundRetryWindow') ? WorkerJobs::refundRetryWindow() : 33300];
        break;
    case 'credit':
        // The refund_credit_note job as cli/worker.php runs it, at the given number of earlier deferrals.
        $credit = $order('R38Q-CREDIT');
        foreach (glob($dir . '/jobs/pending/*.json') ?: [] as $file) {
            if ((json_decode(file_get_contents($file), true)['type'] ?? '') === 'refund_credit_note') unlink($file);
        }
        try {
            $result = InvoiceService::creditBookedRefund($credit, 'stripe_refund_re_r38q_dash', (int)$args[0]);
            $out['result'] = $result === null ? 'deferred' : 'credited';
        } catch (Throwable $e) {
            $out['result'] = 'failed';
        }
        // The job it queued for the next check: its deferral count and in how many seconds it runs.
        $out['next'] = null;
        $out['delay'] = 0;
        foreach (glob($dir . '/jobs/pending/*.json') ?: [] as $file) {
            $job = json_decode(file_get_contents($file), true);
            if (($job['type'] ?? '') !== 'refund_credit_note') continue;
            $out['next'] = (int)($job['payload']['deferrals'] ?? -1);
            $out['delay'] = max(0, strtotime(($job['retry_after'] ?? gmdate('Y-m-d\TH:i:s')) . ' UTC') - time());
        }
        $incident = $db->prepare("SELECT COUNT(*) FROM operational_incidents WHERE code = 'payment.credit_note_stalled' AND context_json LIKE ?");
        $incident->execute(['%"order_id":' . $credit . '%']);
        $out['incident'] = (int)$incident->fetchColumn();
        $out['credit_notes'] = (int)$db->query("SELECT COUNT(*) FROM invoices WHERE order_id = {$credit} AND type = 'credit_note'")->fetchColumn();
        break;
    case 'admin':
        $id = $order('R38Q-STALLED');
        $_SESSION = ['admin_role' => 'owner', 'admin_user' => 'r38q-admin', 'csrf_token' => 'r38q-token'];
        $_GET = ['id' => (string)$id];
        ob_start();
        pageOrder();
        $page = (string)ob_get_clean();
        $out['release_form'] = str_contains($page, 'action=refund_release');
        $out['without_check'] = str_contains($page, 'data-release-without-check');
        if (($args[0] ?? '') !== '') {
            $_POST = ['csrf_token' => 'r38q-token', 'refund_id' => (string)$itemRefund($id)['id'], 'note' => 'Nothing left in the Stripe dashboard']
                + ($args[0] === 'without_check' ? ['without_provider_check' => '1'] : []);
            register_shutdown_function(static function() use (&$out, $itemRefund, $id, $db): void {
                $out['flash'] = $_SESSION['flash']['type'] ?? null;
                $out['message'] = $_SESSION['flash']['msg'] ?? null;
                $out['item_refund'] = $itemRefund($id)['status'] ?? null;
                $out['offered_after'] = PaymentService::stalledItemRefund(Orders::byId($id)) !== null;
                $out['audit'] = (string)$db->query("SELECT note FROM audit_log WHERE entity = 'order_refund' AND entity_id = {$id} ORDER BY id DESC LIMIT 1")->fetchColumn();
                print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            });
            handleOrderRefundRelease(); // redirects and exits
        }
        break;
}
print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r38qDrivers = ['sqlite' => $r38qRoot . '/refunds.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r38qDrivers['mysql'] = 'bbe_r38q_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R38 refund low native MySQL (set TEST_MYSQL_DSN)\n";
$r38qStep = static function(string ...$args) use ($r38qRoot): array {
    $process = proc_open(array_merge([PHP_BINARY, $r38qRoot . '/step.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    // stderr may carry the handler's own error log line (Logger::phpLog() of a refused release).
    return $exit === 0 && is_array($decoded) ? $decoded : ['error' => "exit {$exit}: " . substr($stdout . $stderr, -1500)];
};
try {
    foreach ($r38qDrivers as $r38qDriver => $r38qTarget) {
        $tag = "R38 refund low {$r38qDriver}";
        @array_map('unlink', glob($r38qRoot . '/jobs/*/*.json') ?: []);
        if ($r38qDriver === 'sqlite') @unlink($r38qTarget);
        file_put_contents($r38qRoot . '/fixture.json', json_encode(['driver' => $r38qDriver, 'target' => $r38qTarget, 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        $setup = $r38qStep('setup');
        assert_true(($setup['window'] ?? 0) >= 9 * 3600, "{$tag}: isolated fixture migrated; the refund job retries after errors for about 9 hours (" . json_encode($setup) . ')');

        // 1. The credit note of the dashboard refund waits for the item refund whose job is still retrying: run the job
        //    chain as the worker would, each next job with the deferral count and after the delay the last one queued.
        $r38qWaited = 0;
        $r38qDeferral = 0;
        $r38qLast = [];
        for ($r38qRuns = 0; $r38qRuns < 60; $r38qRuns++) {
            $r38qLast = $r38qStep('credit', (string)$r38qDeferral);
            if (($r38qLast['result'] ?? '') !== 'deferred' || ($r38qLast['next'] ?? null) === null) break;
            if (($r38qLast['incident'] ?? 0) !== 0) break;
            $r38qWaited += (int)$r38qLast['delay'];
            $r38qDeferral = (int)$r38qLast['next'];
        }
        $r38qWindow = (int)($setup['window'] ?? 0);
        assert_true($r38qWaited >= $r38qWindow,
            "{$tag}: the credit note waits as long as the item refund's job may still retry (" . round($r38qWaited / 3600, 1) . ' h of ' . round($r38qWindow / 3600, 1) . ' h; was: half an hour)');
        assert_equals(['failed', 1, 1], [$r38qLast['result'] ?? ($r38qLast['error'] ?? ''), $r38qLast['incident'] ?? -1, $r38qLast['credit_notes'] ?? -1],
            "{$tag}: then the credit note job fails with a critical incident naming the order, issuing no second credit note (was: no incident)");
        assert_true($r38qWaited <= $r38qWindow + 2 * 3600, "{$tag}: and not much later than the refund job's last retry (" . round($r38qWaited / 3600, 1) . ' h)');

        // 2. Stripe cannot be asked about the stalled item refund.
        $page = $r38qStep('admin');
        assert_equals([true, true], [$page['release_form'] ?? null, $page['without_check'] ?? null], "{$tag}: the stalled item refund offers a release and the release without the provider's check");
        $refused = $r38qStep('admin', 'checked');
        assert_equals(['err', 'pending', true], [$refused['flash'] ?? ($refused['error'] ?? ''), $refused['item_refund'] ?? '', $refused['offered_after'] ?? null],
            "{$tag}: a plain release is still refused while Stripe cannot be asked (nothing released)");
        assert_true(str_contains((string)($refused['message'] ?? ''), 'without its check'), "{$tag}: the refusal names the release without the check (" . json_encode($refused['message'] ?? null) . ')');
        $released = $r38qStep('admin', 'without_check');
        assert_equals(['ok', 'failed', false], [$released['flash'] ?? ($released['error'] ?? ''), $released['item_refund'] ?? '', $released['offered_after'] ?? null],
            "{$tag}: released without the provider's check: the item refund is released (its credit note reversed) and no longer offered (was: stuck for good)");
        assert_true(str_contains((string)($released['audit'] ?? ''), 'r38q-admin') && str_contains((string)($released['audit'] ?? ''), 'not checked with'),
            "{$tag}: the audit log says who released it without the provider's check (" . json_encode($released['audit'] ?? null) . ')');
    }
} finally {
    if (isset($r38qDrivers['mysql'])) {
        file_put_contents($r38qRoot . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $r38qDrivers['mysql'], 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        $r38qStep('teardown');
    }
}
