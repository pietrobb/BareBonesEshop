<?php

// Review round 12 (R38, medium 8): GoPay/ThePay/Comgate have no idempotent refunds, so a refund attempt whose answer was
// lost (a timeout after the provider executed it) is never sent again. The job's retries only said "reconcile manually"
// until it died: the payment stayed refund_pending, the ledger held 0 and the admin had no action for it. Now the
// provider's payment state settles it when it can (refunded in full), else the job ends at once with an incident and the
// order offers "confirm executed" / "not executed". Real GoPayPayment (only its HTTP transport stubbed), the worker's
// job loop and the admin order page and handler, on SQLite and MySQL.
echo "\n--- R38 a refund whose provider answer was lost is recovered or decided by the admin ---\n";
$r38uRoot = test_fixture_path('refund-unknown-r38');
mkdir($r38uRoot, 0700, true);
$r38uStores = ['default_store' => 'default', 'stores' => [
    'default' => ['name' => 'R38U', 'default_locale' => 'en', 'languages' => ['en'], 'currency' => 'eur', 'url_prefix' => '', 'domains' => [], 'permissions' => ['*'], 'channels' => ['storefront', 'api', 'admin'], 'catalog_scope' => 'default', 'provider_scope' => 'default'],
]];
file_put_contents($r38uRoot . '/step.php', <<<'PHP'
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
    : new PDO('sqlite:' . $fx['target']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($fx['driver'] === 'mysql') {
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$fx['target']}`"); exit(0); }
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$fx['target']}` CHARACTER SET utf8mb4");
    $db->exec("USE `{$fx['target']}`");
}
Db::init($db, $fx['driver']);
JobQueue::setDir($dir . '/jobs');
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $fx['stores']);
StoreContext::activate(StoreContext::resolve($fx['stores'], '/', '', 'default'));
I18n::setLocale('en');
$orderId = static function(string $paymentId) use ($db): int {
    $stmt = $db->prepare('SELECT id FROM orders WHERE payment_id = ?');
    $stmt->execute([$paymentId]);
    return (int)$stmt->fetchColumn();
};

// GoPay: executes a refund (or not, "lost" before it reached GoPay), then the answer times out; the payment inquiry
// reports PAID / PARTIALLY_REFUNDED / REFUNDED from what it executed.
$gopayFile = $dir . '/gopay.json';
$transport = static function(string $method, string $url, array $headers, ?string $body) use ($gopayFile): array {
    if (str_contains($url, '/oauth2/token')) return ['status' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 1800])];
    $gw = json_decode(file_get_contents($gopayFile), true);
    if (!preg_match('#/payments/payment/(\d+)(/refund)?$#', (string)parse_url($url, PHP_URL_PATH), $m)) return ['status' => 404, 'body' => '{}'];
    $id = $m[1];
    if (($m[2] ?? '') === '/refund') {
        parse_str((string)$body, $form);
        $gw[$id]['calls']++;
        if ($gw[$id]['execute']) $gw[$id]['refunded'] += (int)$form['amount'];
        file_put_contents($gopayFile, json_encode($gw));
        throw new RuntimeException('GoPay transport error: Operation timed out after 30001 milliseconds');
    }
    $refunded = (int)$gw[$id]['refunded'];
    $state = $refunded === 0 ? 'PAID' : ($refunded >= 1000 ? 'REFUNDED' : 'PARTIALLY_REFUNDED');
    return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'state' => $state, 'order_number' => 'R38U-' . $id, 'amount' => 1000,
        'currency' => 'EUR', 'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])];
};
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new GoPayPayment(['gopay_goid' => '8123456789', 'gopay_client_id' => 'c', 'gopay_client_secret' => 's'], $transport)]);

$out = [];
switch ($phase) {
    case 'setup':
        (new Migrator($db, $fx['root'] . '/migrations', $dir . '/migrate.lock'))->apply();
        $gw = [];
        foreach (json_decode($args[0], true) as $paymentId => $execute) {
            $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
                VALUES (?, 'R38U', 'r38u@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'delivered', 'paid', 'gopay', ?, ?, 'default', " . Db::now() . ")")
                ->execute(['R38U-' . $paymentId, $paymentId, json_encode(['provider' => 'gopay', 'service_type' => 'online_card', 'requires_online' => true])]);
            $gw[$paymentId] = ['execute' => $execute, 'refunded' => 0, 'calls' => 0];
        }
        file_put_contents($gopayFile, json_encode($gw));
        break;
    case 'request':
        PaymentService::requestRefundAndQueue($orderId($args[0]), (int)$args[1], 'r38u_request_' . $args[0] . '_' . $args[1], ['reason' => 'R38U']);
        break;
    case 'manual':
        // The admin's manual payment status change (the order detail's form and its handler).
        $_SESSION = ['admin_role' => 'owner', 'admin_user' => 'r38u-admin', 'csrf_token' => 'r38u-token'];
        $_GET = ['id' => (string)$orderId($args[0])];
        $out['offered'] = adminManualPaymentTransitions(Orders::byId($orderId($args[0])));
        $_POST = ['csrf_token' => 'r38u-token', 'new_payment_status' => $args[1], 'amount' => '', 'note' => 'R38U by hand'];
        register_shutdown_function(static function() use (&$out): void {
            $out['flash'] = $_SESSION['flash']['type'] ?? null;
            $out['message'] = $_SESSION['flash']['msg'] ?? null;
            print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        });
        handleOrderPaymentTransition(); // redirects and exits
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
                $out[(string)$job['payload']['payment_id']] = 'done';
            } catch (Throwable $e) {
                JobQueue::fail($claim, $job, $e->getMessage());
                $out[(string)$job['payload']['payment_id']] = 'failed';
            } finally {
                JobQueue::endWork();
            }
        }
        ksort($out); // jobs queued in the same second are pulled in any order
        break;
    case 'state':
        $id = $orderId($args[0]);
        $order = Orders::byId($id);
        $gw = json_decode(file_get_contents($gopayFile), true);
        $open = 0;
        foreach (['pending', 'processing'] as $state) {
            foreach (glob($dir . "/jobs/{$state}/*.json") ?: [] as $file) {
                if ((int)(json_decode(file_get_contents($file), true)['payload']['order_id'] ?? 0) === $id) $open++;
            }
        }
        $incidents = $db->prepare("SELECT COUNT(*) FROM operational_incidents WHERE code = 'payment.refund_outcome_unknown' AND context_json LIKE ?");
        $incidents->execute(['%"order_id":' . $id . '%']);
        $out = ['payment' => (string)$order['payment_status'], 'refunded' => PaymentService::refundedAmount($id), 'balance' => PaymentService::refundableBalance($id),
            'gopay_calls' => (int)$gw[$args[0]]['calls'], 'open_jobs' => $open, 'incident' => (int)$incidents->fetchColumn()];
        break;
    case 'admin':
        $_SESSION = ['admin_role' => 'owner', 'admin_user' => 'r38u-admin', 'csrf_token' => 'r38u-token'];
        $_GET = ['id' => (string)$orderId($args[0])];
        ob_start();
        pageOrder();
        $page = (string)ob_get_clean();
        $out['form'] = str_contains($page, 'action=refund_confirm');
        $out['not_executed_button'] = str_contains($page, 'data-refund-not-executed');
        if (($args[1] ?? '') !== '') {
            $_POST = ['csrf_token' => 'r38u-token', 'note' => 'Checked in the GoPay portal'] + ($args[1] === 'not_executed' ? ['outcome' => 'not_executed'] : []);
            register_shutdown_function(static function() use (&$out): void {
                $out['flash'] = $_SESSION['flash'] ?? null;
                print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            });
            handleOrderRefundConfirm(); // redirects and exits
        }
        break;
}
print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r38uDrivers = ['sqlite' => $r38uRoot . '/refunds.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r38uDrivers['mysql'] = 'bbe_r38u_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R38 refund unknown native MySQL (set TEST_MYSQL_DSN)\n";
$r38uStep = static function(string ...$args) use ($r38uRoot): mixed {
    $process = proc_open(array_merge([PHP_BINARY, $r38uRoot . '/step.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    return $exit === 0 && $stderr === '' && is_array($decoded) ? $decoded : ['error' => "exit {$exit}: " . substr($stdout . $stderr, -1500)];
};
// Payment id => whether GoPay executed the refund before its answer was lost.
$r38uFull = '3800000001';   // whole balance, executed: GoPay reports REFUNDED
$r38uLost = '3800000002';   // 3.00, never reached GoPay: PAID
$r38uPartial = '3800000003'; // 4.00, executed: PARTIALLY_REFUNDED (no amount)
try {
    foreach ($r38uDrivers as $r38uDriver => $r38uTarget) {
        $tag = "R38 refund unknown {$r38uDriver}";
        @array_map('unlink', glob($r38uRoot . '/jobs/*/*.json') ?: []);
        file_put_contents($r38uRoot . '/fixture.json', json_encode(['driver' => $r38uDriver, 'target' => $r38uTarget, 'root' => dirname(TEST_CLI_DIR), 'stores' => $r38uStores], JSON_THROW_ON_ERROR));
        assert_equals([], $r38uStep('setup', json_encode([$r38uFull => true, $r38uLost => false, $r38uPartial => true])), "{$tag}: isolated fixture migrated");
        foreach ([[$r38uFull, 1000], [$r38uLost, 300], [$r38uPartial, 400]] as [$payment, $amount]) $r38uStep('request', $payment, (string)$amount);

        $first = $r38uStep('work');
        assert_equals([$r38uFull => 'failed', $r38uLost => 'failed', $r38uPartial => 'failed'], $first, "{$tag}: the timed-out refund calls fail the jobs (retried later)");
        $second = $r38uStep('work');
        $full = $r38uStep('state', $r38uFull);
        assert_equals(['refunded', 1000, 0, 1], [$full['payment'] ?? '', $full['refunded'] ?? -1, $full['balance'] ?? -1, $full['gopay_calls'] ?? -1],
            "{$tag}: the retry asks GoPay, finds the payment REFUNDED and books the whole refund once, without a second refund call (was: refund_pending, ledger 0)");
        foreach ([$r38uLost => 'lost', $r38uPartial => 'partial'] as $payment => $label) {
            $state = $r38uStep('state', $payment);
            assert_equals(['refund_pending', 0, 1, 0, 1], [$state['payment'] ?? '', $state['refunded'] ?? -1, $state['gopay_calls'] ?? -1, $state['open_jobs'] ?? -1, $state['incident'] ?? -1],
                "{$tag}: {$label}: GoPay cannot tell, so the job ends at once with an incident, never calling GoPay again (was: retried until dead, no incident)");
            $page = $r38uStep('admin', $payment);
            assert_equals([true, true], [$page['form'] ?? null, $page['not_executed_button'] ?? null], "{$tag}: {$label}: the order offers confirm-executed and not-executed (was: no action)");
        }
        assert_equals([$r38uFull => 'done', $r38uLost => 'done', $r38uPartial => 'done'], $second, "{$tag}: the retried jobs complete: recovered, or left to the admin (was: failed again)");

        $released = $r38uStep('admin', $r38uLost, 'not_executed');
        $lost = $r38uStep('state', $r38uLost);
        assert_equals(['ok', 'paid', 0, 1000], [$released['flash']['type'] ?? ($released['error'] ?? ''), $lost['payment'] ?? '', $lost['refunded'] ?? -1, $lost['balance'] ?? -1],
            "{$tag}: not executed: the request is released, the payment is paid with the whole balance refundable again");
        $confirmed = $r38uStep('admin', $r38uPartial, 'confirm');
        $partial = $r38uStep('state', $r38uPartial);
        assert_equals(['ok', 'partially_refunded', 400, 600], [$confirmed['flash']['type'] ?? ($confirmed['error'] ?? ''), $partial['payment'] ?? '', $partial['refunded'] ?? -1, $partial['balance'] ?? -1],
            "{$tag}: confirmed executed: the 4.00 refund is booked, 6.00 left refundable");

        // R38 (low): a second refund (2.00) of the partially refunded order, released by hand with "paid".
        $r38uStep('request', $r38uPartial, '200');
        $manualFailed = $r38uStep('manual', $r38uPartial, 'failed');
        assert_equals([['paid'], 'err', 'refund_pending'], [$manualFailed['offered'] ?? null, $manualFailed['flash'] ?? ($manualFailed['error'] ?? ''), $r38uStep('state', $r38uPartial)['payment'] ?? ''],
            "{$tag}: a captured payment with a refund in flight cannot be set failed by hand (was offered and accepted)");
        $manualPaid = $r38uStep('manual', $r38uPartial, 'paid');
        // R38 (usability): the confirmation names the status as the admin sees it, not the code 'paid'.
        $manualMessage = (string)($manualPaid['message'] ?? ($manualPaid['error'] ?? ''));
        assert_true(($manualPaid['flash'] ?? '') === 'ok' && $manualMessage !== '' && !str_contains($manualMessage, "'paid'"),
            "{$tag}: the manual status change confirms it with the status label (got: {$manualMessage})");
        $released = $r38uStep('state', $r38uPartial);
        assert_equals(['partially_refunded', 400, 600], [$released['payment'] ?? '', $released['refunded'] ?? -1, $released['balance'] ?? -1],
            "{$tag}: \"paid\" by hand releases the 2.00 in flight and keeps the 4.00 already refunded (was: paid)");
        assert_equals([[$r38uPartial => 'done'], 1], [$r38uStep('work'), $r38uStep('state', $r38uPartial)['gopay_calls'] ?? -1],
            "{$tag}: the released request's job pays nothing out");
        $again = $r38uStep('admin', $r38uPartial);
        assert_equals(false, $again['form'] ?? null, "{$tag}: no refund is left awaiting confirmation after the release");

        $after = $r38uStep('admin', $r38uFull);
        assert_equals([false, false], [$after['form'] ?? null, $after['not_executed_button'] ?? null], "{$tag}: a recovered refund offers no confirmation");
        assert_equals([], array_filter([$r38uStep('work')]), "{$tag}: no job of these refunds is left");
    }
} finally {
    if (isset($r38uDrivers['mysql'])) {
        file_put_contents($r38uRoot . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $r38uDrivers['mysql'], 'root' => dirname(TEST_CLI_DIR), 'stores' => $r38uStores], JSON_THROW_ON_ERROR));
        $r38uStep('teardown');
    }
}
