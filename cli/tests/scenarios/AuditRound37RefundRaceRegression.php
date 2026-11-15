<?php

// Goal468: two DB processes, real file-queue claims and refund services; only the gateway is fake.
// The barrier is INSIDE the fake adapter, before its simulated external dispatch, not a recalled request.
// Once dispatch is claimed cancellation must not report success; cancellation before the claim must block dispatch.
// Ordering and bookkeeping are checked separately. No production hooks, network payments or shared-schema cleanup.
echo "\n--- R37: cancellation versus item-refund dispatch (SQLite/native MySQL) ---\n";
$r37raceDir = test_fixture_path('refund-race-r37');
mkdir($r37raceDir);
$r37raceScript = $r37raceDir . '/race.php';
file_put_contents($r37raceScript, <<<'PHP'
<?php
[, $root, $base, $driver, $role] = $argv;
require $root . '/src/autoload.php';
function waitFile(string $path): void {
    $until = microtime(true) + 30;
    do {
        clearstatcache(true, $path);
        if (is_file($path)) return;
        usleep(10000);
    } while (microtime(true) < $until);
    throw new RuntimeException('Barrier timeout: ' . $path);
}
function saveJson(string $path, array $data): void {
    file_put_contents($path . '.tmp', json_encode($data, JSON_THROW_ON_ERROR));
    rename($path . '.tmp', $path);
}
class R37RaceGateway extends StripePayment {
    public static string $dir = '';
    public static int $refund = 0;
    public static $lookup = null;
    public function __construct() { parent::__construct([]); }
    public function refund(string $paymentId, int $amount): array {
        throw new LogicException('Unexpected non-idempotent gateway dispatch');
    }
    public function refundByKey(string $paymentId, string $idempotencyKey): ?array {
        if (self::$lookup !== null) (self::$lookup)();
        return ['status' => 'none'];
    }
    public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
        // Entered through real WorkerJobs -> PaymentService after its status read and heartbeat.
        saveJson(self::$dir . '/ready.json', [
            'pid' => getmypid(), 'status' => Rma::refundById(self::$refund)['status'],
            'transaction' => Db::get()->inTransaction(), 'stack' => array_map(
                static fn(array $f): string => ($f['class'] ?? '') . ($f['type'] ?? '') . $f['function'],
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))]);
        waitFile(self::$dir . '/dispatch.go');
        // This file is the fake provider's first receipt of a request. Nothing was sent before it.
        saveJson(self::$dir . '/provider.json', [
            'amount' => $amount, 'key' => $idempotencyKey,
            'status_at_dispatch' => Rma::refundById(self::$refund)['status'],
            'cancellation_committed' => is_file(self::$dir . '/cancelled.json')]);
        return ['status' => 'succeeded', 'refund_id' => 're_r37race_' . basename(self::$dir)];
    }
}
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new ShopPDO('sqlite:' . $base . '/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$schema = $role === 'worker' ? $argv[5] : 'bbe_r37race_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') {
    if (!preg_match('/^bbe_r37race_[0-9]+_[a-f0-9]{8}_test$/D', $schema)) throw new RuntimeException('Unsafe schema');
    if ($role !== 'worker') $db->exec("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4");
    $db->exec("USE `{$schema}`");
}
Db::init($db, $driver);
Bootstrap::initStoreContext('/', '', 'default');
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new R37RaceGateway()]);
if ($role === 'worker') {
    $dir = $argv[6];
    JobQueue::setDir($dir . '/jobs');
    R37RaceGateway::$dir = $dir;
    waitFile($dir . '/start.json');
    $start = json_decode(file_get_contents($dir . '/start.json'), true, 512, JSON_THROW_ON_ERROR);
    R37RaceGateway::$refund = (int)$start['refund'];
    $lock = JobQueue::acquireWorkerLock(JobQueue::workerLockFile());
    if ($lock === null) throw new RuntimeException('Worker lock unavailable');
    $selected = null;
    foreach (JobQueue::pull(100) as $job) {
        if ($job['type'] === 'payment_refund' && (int)($job['payload']['refund_id'] ?? 0) === R37RaceGateway::$refund) { $selected = $job; break; }
    }
    if ($selected === null) throw new RuntimeException('No real refund job');
    $claim = JobQueue::markProcessing($selected);
    if ($claim === '') throw new RuntimeException('Claim failed');
    JobQueue::beginWork($claim);
    $error = null;
    try {
        WorkerJobs::handlePaymentRefund($selected['payload'], $selected);
        JobQueue::complete($claim);
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
        JobQueue::fail($claim, $selected, $error);
    } finally { JobQueue::endWork(); flock($lock, LOCK_UN); fclose($lock); }
    saveJson($dir . '/worker.json', ['error' => $error, 'queue' => JobQueue::stats()]);
    exit(0);
}
$child = null;
try {
    (new Migrator($db, $root . '/migrations', $base . '/migrate.lock'))->apply();
    $checks = []; $traces = [];
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r37race', 'Race', 'r37race', 0)");
    $category = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R37RACE', 'r37race', 'Race', 1000, 5, 'active')")->execute([$category]);
    $product = (int)$db->lastInsertId();
    foreach (['duplicate-stop', 'stalled-release', 'cancel-before-dispatch'] as $mode) {
        $dir = $base . '/' . $mode; mkdir($dir);
        JobQueue::setDir($dir . '/jobs');
        $snapshot = VatService::buildSnapshot([['product_id' => $product, 'sku' => 'R37RACE', 'qty' => 2, 'price' => 1000, 'product_type' => 'simple']], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
        $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
            VALUES (?, 'Race', '', '{\"country\":\"SK\"}', 2000, 2000, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default')")
            ->execute(['R37RACE-' . $mode, 'pi_r37race_' . $mode, json_encode(['provider' => 'stripe', 'service_type' => 'online_card']), json_encode($snapshot)]);
        $order = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R37RACE', 'Race', 2, 1000, 2000)")->execute([$order, $product]);
        $item = (int)$db->lastInsertId();
        $invoice = InvoiceService::issue($order);
        [, $rma] = Rma::create($order, null, 'Race return', [$item => 1]);
        Rma::setStatus((int)$rma, 'approved');
        $returned = (int)Rma::items((int)$rma)[0]['id'];
        Rma::receive((int)$rma, [$returned => ['quantity' => 1, 'disposition' => 'repair']], 'receipt-' . $mode);
        $firstKey = 'race-first-' . $mode;
        $first = InvoiceService::credit((int)$invoice['id'], (int)$rma, [$returned => 1], $firstKey);
        $refund = (int)$first['refund_id'];
        $refundJob = null;
        foreach (JobQueue::pull(100) as $job) {
            if ($job['type'] === 'payment_refund' && (int)($job['payload']['refund_id'] ?? 0) === $refund) { $refundJob = $job; unlink($job['_file']); }
        }
        if ($refundJob === null) throw new RuntimeException('Invoice credit did not queue refund');
        if ($mode === 'cancel-before-dispatch') {
            $released = PaymentService::releaseStalledItemRefund($order, $refund, 'race-admin', 'Cancelled before worker dispatch');
            R37RaceGateway::$dir = $dir; R37RaceGateway::$refund = $refund;
            $result = PaymentService::refund($order, 'pi_r37race_' . $mode, 1000, new R37RaceGateway(), $firstKey, 'eur');
            $checks[] = [$mode . ': canonical dispatch refuses a stale released item refund', $released === 'released' && $result['status'] === 'skipped' && !is_file($dir . '/ready.json')];
            WorkerJobs::handlePaymentRefund($refundJob['payload'], []);
            $checks[] = [$mode . ': stale worker pays nothing and keeps the credit reversal', Rma::refundById($refund)['status'] === 'failed' && PaymentService::refundedAmount($order) === 0 && !is_file($dir . '/provider.json')];
            continue;
        }
        if ($mode === 'duplicate-stop') {
            $released = PaymentService::releaseStalledItemRefund($order, $refund, 'race-admin', 'Fake provider has no refund');
            if ($released !== 'released') throw new RuntimeException('Initial release failed: ' . $released);
            $second = InvoiceService::credit((int)$invoice['id'], (int)$rma, [$returned => 1], 'race-second-' . $mode);
            $refund = (int)$second['refund_id'];
        }
        $child = proc_open([PHP_BINARY, __FILE__, $root, $base, $driver, 'worker', $schema, $dir],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . '/stdout.log', 'w'], 2 => ['file', $dir . '/stderr.log', 'w']], $pipes);
        if (!is_resource($child)) throw new RuntimeException('Cannot launch worker');
        $startWorker = static function() use ($dir, $refund): void {
            saveJson($dir . '/start.json', ['refund' => $refund]);
            waitFile($dir . '/ready.json');
        };
        if ($mode === 'duplicate-stop') {
            $startWorker();
            // Control: a worker already visible in processing is NOT a stalled refund.
            $guard = PaymentService::releaseStalledItemRefund($order, $refund, 'race-admin', 'Must not release active job');
            $checks[] = [$mode . ': active processing job blocks stalled release', $guard === '' && Rma::refundById($refund)['status'] === 'pending'];
            // Real webhook bookkeeping calls coverReleasedRefund -> stopDuplicateRefunds -> failRefund.
            PaymentService::bookProviderRefund($order, 'stripe', 're_r37race_late', 1000, 'charge.refunded', 'stripe_refund_re_r37race_late', ['refund_key' => $firstKey]);
            $cancelResult = Rma::refundById($refund)['status'];
        } else {
            // Release has already seen an empty queue when it enters the provider lookup.
            // A delayed/manual retry becomes runnable during that lookup (no stale/expired lease needed).
            R37RaceGateway::$lookup = static function() use ($refundJob, $startWorker): void {
                JobQueue::push('payment_refund', $refundJob['payload'], (int)$refundJob['priority']);
                $startWorker();
            };
            $cancelResult = PaymentService::releaseStalledItemRefund($order, $refund, 'race-admin', 'No provider request at lookup');
            R37RaceGateway::$lookup = null;
        }
        $ready = json_decode(file_get_contents($dir . '/ready.json'), true, 512, JSON_THROW_ON_ERROR);
        $processing = array_values(array_filter(JobQueue::openJobs('payment_refund') ?? [], static fn(array $p): bool => (int)($p['refund_id'] ?? 0) === $refund));
        $cancelled = ['result' => $cancelResult, 'status' => Rma::refundById($refund)['status'],
            'provider_requests' => is_file($dir . '/provider.json') ? 1 : 0, 'processing_jobs' => count($processing), 'transaction' => $db->inTransaction()];
        saveJson($dir . '/cancelled.json', $cancelled);
        $checks[] = [$mode . ': two-process dispatch claim prevents false cancellation while a real job processes',
            $ready['pid'] !== getmypid() && $ready['status'] === 'pending' && !$ready['transaction'] &&
            $cancelled['status'] === 'pending' && !$cancelled['transaction'] && $cancelled['provider_requests'] === 0 && count($processing) === 1];
        file_put_contents($dir . '/dispatch.go', 'go');
        waitFile($dir . '/worker.json');
        $exit = proc_close($child); $child = null;
        $worker = json_decode(file_get_contents($dir . '/worker.json'), true, 512, JSON_THROW_ON_ERROR);
        $provider = is_file($dir . '/provider.json') ? json_decode(file_get_contents($dir . '/provider.json'), true, 512, JSON_THROW_ON_ERROR) : null;
        $checks[] = [$mode . ': worker subprocess exited normally', $exit === 0];
        $checks[] = [$mode . ': SAFETY dispatch never pays a refund reported cancelled', $provider === null || ($cancelled['status'] !== 'failed' && $provider['status_at_dispatch'] !== 'failed')];
        $checks[] = [$mode . ': in-flight result settles without a failed-refund bookkeeping exception', $worker['error'] === null && Rma::refundById($refund)['status'] === 'succeeded'];
        if ($mode === 'duplicate-stop') $checks[] = [$mode . ': dispatch already started raises duplicate-payment warning', IncidentMonitor::reportedForOrder('payment.duplicate_refund_sent', $order)];
        $traces[] = ['driver' => $driver, 'race' => $mode, 'ready' => $ready, 'cancel' => $cancelled, 'provider' => $provider,
            'worker' => $worker, 'final_refund' => Rma::refundById($refund), 'booked_money' => PaymentService::refundedAmount($order),
            'returned_units' => Rma::items((int)$rma)[0]['refunded_quantity'],
            'audit' => array_map(static fn(array $a): string => (string)($a['note'] ?? ''), AuditLog::forEntity('order_refund', $order, 20))];
    }
    echo json_encode(['checks' => $checks, 'traces' => $traces], JSON_THROW_ON_ERROR);
} finally {
    if (is_resource($child)) { proc_terminate($child); proc_close($child); }
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE `{$schema}`");
}
PHP);
foreach (['sqlite', 'mysql'] as $r37raceDriver) {
    if ($r37raceDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) {
        echo "  [SKIP] R37 refund race native MySQL (TEST_MYSQL_DSN unset)\n";
        continue;
    }
    $r37raceBase = $r37raceDir . '/' . $r37raceDriver; mkdir($r37raceBase);
    $r37raceProc = proc_open([PHP_BINARY, $r37raceScript, dirname(TEST_CLI_DIR), $r37raceBase, $r37raceDriver, 'coordinator'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r37racePipes);
    fclose($r37racePipes[0]);
    $r37raceRaw = stream_get_contents($r37racePipes[1]); $r37raceErr = stream_get_contents($r37racePipes[2]);
    fclose($r37racePipes[1]); fclose($r37racePipes[2]);
    $r37raceExit = proc_close($r37raceProc);
    $r37raceResult = json_decode($r37raceRaw, true);
    assert_true($r37raceExit === 0 && is_array($r37raceResult), "R37 refund race {$r37raceDriver}: isolated fixture ({$r37raceErr})");
    foreach ($r37raceResult['traces'] ?? [] as $r37raceTrace) echo '  TRACE ' . json_encode($r37raceTrace, JSON_UNESCAPED_SLASHES) . "\n";
    foreach ($r37raceResult['checks'] ?? [] as [$r37raceLabel, $r37raceOk]) assert_true($r37raceOk, "R37 refund race {$r37raceDriver}: {$r37raceLabel}");
}
