<?php

// Review round 12 (R38, usability 2): the "refund issued" e-mail carried no attachment, so the customer never got the
// credit note correcting the invoice. Now it attaches the credit note of the refund it announces; when its job runs
// before the credit note job queued with it, it waits for that one (5 minutes, at most three times). A refund booked from
// a Stripe webhook (PaymentService::bookProviderRefund()), then the worker's jobs as cli/worker.php runs them, through
// the real mail service writing LogMail's log, on SQLite and MySQL.
echo "\n--- R38 the refund e-mail carries the credit note ---\n";
$r38mRoot = test_fixture_path('refund-mail-r38');
mkdir($r38mRoot, 0700, true);
file_put_contents($r38mRoot . '/step.php', <<<'PHP'
<?php
[, $phase] = $argv;
$args = array_slice($argv, 2);
$dir = __DIR__;
$fx = json_decode(file_get_contents($dir . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
require $fx['root'] . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false; // suppressed with @ (LogMail's mkdir)
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
MailService::useAdapter(new LogMail(['log_file' => $dir . '/mail.log']));
$order = static function(string $number) use ($db): int {
    $stmt = $db->prepare('SELECT id FROM orders WHERE order_number = ?');
    $stmt->execute([$number]);
    return (int)$stmt->fetchColumn();
};

$out = [];
switch ($phase) {
    case 'setup':
        (new Migrator($db, $fx['root'] . '/migrations', $dir . '/migrate.lock'))->apply();
        $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r38m', 'R38M', 'r38m', 0)");
        $category = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R38M', 'r38m', 'R38M', 1000, 5, 'active')")->execute([$category]);
        $product = (int)$db->lastInsertId();
        foreach (['R38M-FIRST', 'R38M-LATE'] as $number) {
            $snapshot = VatService::buildSnapshot([['product_id' => $product, 'sku' => 'R38M', 'qty' => 2, 'price' => 1000, 'product_type' => 'simple']], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
            $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, locale, store_id)
                VALUES (?, 'R38M', ?, '{\"country\":\"SK\"}', 2000, 2000, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'en', 'default')")
                ->execute([$number, strtolower($number) . '@example.test', 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card']), json_encode($snapshot)]);
            $orderId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R38M', 'R38M', 2, 1000, 2000)")->execute([$orderId, $product]);
            InvoiceService::issue($orderId);
        }
        @array_map('unlink', glob($dir . '/jobs/*/*.json') ?: []);
        break;
    case 'refund':
        // A 5.00 refund made in the Stripe Dashboard, booked from its refund.created webhook.
        $id = $order($args[0]);
        PaymentService::bookProviderRefund($id, 'stripe', 're_' . strtolower($args[0]), 500, 'refund.created', 'evt_' . strtolower($args[0]));
        break;
    case 'work':
        // One worker pass over the jobs of $args[0] (comma-separated types), the delay of a re-queued job having passed.
        $types = explode(',', $args[0]);
        foreach (glob($dir . '/jobs/pending/*.json') ?: [] as $file) {
            $job = json_decode(file_get_contents($file), true);
            if (!in_array($job['type'] ?? '', $types, true)) continue;
            $out['delayed'][] = isset($job['retry_after']) ? max(0, strtotime($job['retry_after'] . ' UTC') - time()) : 0;
            unset($job['retry_after']);
            file_put_contents($file, json_encode($job));
        }
        $handlers = [
            'email_send' => [WorkerJobs::class, 'handleEmailSend'],
            'refund_credit_note' => static fn(array $payload): ?array => InvoiceService::creditBookedRefund((int)($payload['order_id'] ?? 0), (string)($payload['refund_key'] ?? ''), (int)($payload['deferrals'] ?? 0), (int)($payload['waits'] ?? 0)),
        ];
        foreach (JobQueue::pull(50) as $job) {
            if (!in_array($job['type'] ?? '', $types, true)) continue;
            $claim = JobQueue::markProcessing($job);
            if ($claim === '') continue;
            JobQueue::beginWork($claim);
            try {
                $handlers[$job['type']]($job['payload'], $job);
                JobQueue::complete($claim);
                $out['ran'][] = $job['type'];
            } catch (Throwable $e) {
                JobQueue::fail($claim, $job, $e->getMessage());
                $out['ran'][] = $job['type'] . ':failed:' . $e->getMessage();
            } finally {
                JobQueue::endWork();
            }
        }
        break;
    case 'state':
        $id = $order($args[0]);
        $credit = $db->query("SELECT number FROM invoices WHERE order_id = {$id} AND type = 'credit_note' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $log = (string)@file_get_contents($dir . '/mail.log');
        $to = strtolower($args[0]) . '@example.test';
        // The log entries of this order's customer: each starts with "[<time>] log | TO=<recipient>".
        $entries = array_values(array_filter(preg_split('/^(?=\[[^\]\n]+\] log \| TO=)/m', $log) ?: [], static fn(string $e): bool => str_contains($e, '| TO=' . $to . ' |')));
        $open = [];
        foreach (glob($dir . '/jobs/pending/*.json') ?: [] as $file) {
            $job = json_decode(file_get_contents($file), true);
            if ((int)($job['payload']['order_id'] ?? 0) === $id) $open[] = $job['type'];
        }
        sort($open);
        $out = ['credit_note' => $credit === false ? null : (string)$credit, 'mails' => count($entries), 'last_mail' => end($entries) ?: '', 'open' => $open];
        break;
}
print json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r38mDrivers = ['sqlite' => $r38mRoot . '/mail.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r38mDrivers['mysql'] = 'bbe_r38m_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R38 refund mail native MySQL (set TEST_MYSQL_DSN)\n";
$r38mStep = static function(string ...$args) use ($r38mRoot): array {
    $process = proc_open(array_merge([PHP_BINARY, $r38mRoot . '/step.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    return $exit === 0 && is_array($decoded) ? $decoded : ['error' => "exit {$exit}: " . substr($stdout . $stderr, -1500)];
};
try {
    foreach ($r38mDrivers as $r38mDriver => $r38mTarget) {
        $tag = "R38 refund mail {$r38mDriver}";
        @array_map('unlink', glob($r38mRoot . '/jobs/*/*.json') ?: []);
        @unlink($r38mRoot . '/mail.log');
        if ($r38mDriver === 'sqlite') @unlink($r38mTarget);
        file_put_contents($r38mRoot . '/fixture.json', json_encode(['driver' => $r38mDriver, 'target' => $r38mTarget, 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        assert_equals([], $r38mStep('setup'), "{$tag}: isolated fixture migrated, two paid orders with their invoices");

        // 1. The worker takes the credit note job first, then the e-mail queued with it.
        $r38mStep('refund', 'R38M-FIRST');
        $r38mStep('work', 'refund_credit_note');
        $r38mStep('work', 'email_send');
        $first = $r38mStep('state', 'R38M-FIRST');
        assert_true(($first['credit_note'] ?? null) !== null && ($first['mails'] ?? 0) === 1
            && str_contains((string)$first['last_mail'], 'Attachment: ' . $first['credit_note'] . '.pdf (application/pdf'),
            "{$tag}: the refund e-mail carries the refund's credit note as a PDF (was: no attachment) (" . json_encode($first) . ')');

        // 2. The e-mail job runs before the credit note job: it waits for it instead of going without the credit note.
        $r38mStep('refund', 'R38M-LATE');
        $early = $r38mStep('work', 'email_send');
        $waiting = $r38mStep('state', 'R38M-LATE');
        assert_equals([0, ['email_send', 'refund_credit_note']], [$waiting['mails'] ?? -1, $waiting['open'] ?? null],
            "{$tag}: run before the credit note exists, the e-mail is queued again behind its credit note job (" . json_encode([$early, $waiting]) . ')');
        $r38mStep('work', 'refund_credit_note');
        $resent = $r38mStep('work', 'email_send');
        $late = $r38mStep('state', 'R38M-LATE');
        assert_true(($resent['delayed'][0] ?? 0) > 200 && ($late['mails'] ?? 0) === 1 && ($late['credit_note'] ?? null) !== null
            && str_contains((string)$late['last_mail'], 'Attachment: ' . $late['credit_note'] . '.pdf (application/pdf') && ($late['open'] ?? null) === [],
            "{$tag}: about 5 minutes later it goes out once, with the credit note (" . json_encode([$resent, $late]) . ')');
    }
} finally {
    if (isset($r38mDrivers['mysql'])) {
        file_put_contents($r38mRoot . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $r38mDrivers['mysql'], 'root' => dirname(TEST_CLI_DIR)], JSON_THROW_ON_ERROR));
        $r38mStep('teardown');
    }
}
