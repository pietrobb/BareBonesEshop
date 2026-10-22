<?php

echo "\n--- Operational incident fault injection ---\n";
$imRoot = test_fixture_path('incident-monitor');
mkdir($imRoot, 0700, true);
$imPreviousDb = Db::get();
$imPreviousStore = StoreContext::current();
$imPreviousSession = $_SESSION;
$imLoggerConfig = new ReflectionProperty(Logger::class, 'config');
$imMonitorConfig = new ReflectionProperty(IncidentMonitor::class, 'config');
$imMailAdapter = new ReflectionProperty(MailService::class, 'adapterOverride');
$imJobsDir = new ReflectionProperty(JobQueue::class, 'jobsDir');
$imForceFile = new ReflectionProperty(JobQueue::class, 'forceFile');
$imOldLogger = $imLoggerConfig->getValue();
$imOldMonitor = $imMonitorConfig->getValue();
$imOldMail = $imMailAdapter->getValue();
$imOldJobs = $imJobsDir->getValue();
$imOldForceFile = $imForceFile->getValue();
$imOldCorrelation = Logger::correlationId();
$imOldFrom = getenv('MAIL_FROM');
$imSnapshot = new PDO('sqlite:' . $testDbPath);
$imSnapshot->exec('VACUUM INTO ' . $imSnapshot->quote($imRoot . '/shop.sqlite'));
$imSnapshot = null;
$imDb = new PDO('sqlite:' . $imRoot . '/shop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$imMigration = require dirname(TEST_CLI_DIR) . '/migrations/20261012090000_operational_incidents.php';
$imMigration->up($imDb, true);
$imMigration->up($imDb, true);
Db::init($imDb, 'sqlite');
Bootstrap::initStoreContext('/', '', 'default');
$imConfig = ['structured_log_file' => $imRoot . '/application.jsonl', 'admin_email' => 'owner@example.test', 'incident_alerts' => ['enabled' => true, 'window_seconds' => 900, 'checkout_failures' => 3, 'cooldown_seconds' => 900], 'jobs_dir' => $imRoot . '/jobs', 'cart_reminder' => ['enabled' => false], 'stock_alerts' => ['enabled' => false]];
$imLoggerConfig->setValue(null, $imConfig);
$imMonitorConfig->setValue(null, $imConfig);
JobQueue::setDir($imRoot . '/jobs');
putenv('MAIL_FROM=noreply@example.test');
$imMail = new class implements MailAdapter {
    public bool $fail = false;
    public array $messages = [];
    public function adapterId(): string { return 'smtp-fixture'; }
    public function send(array $msg): array {
        $this->messages[] = $msg;
        if ($this->fail) throw new RuntimeException('PrivateName private@example.test token=secret-value 127.0.0.99');
        return ['status' => 'sent', 'message' => '', 'raw' => 'fixture'];
    }
};
MailService::useAdapter($imMail);
try {
    Logger::setCorrelationId('incident-regression-correlation');
    $imSensitive = ['post' => ['name' => 'PrivateName', 'email' => 'private@example.test'], 'session_id' => 'private-session', 'ip' => '127.0.0.99', 'token' => 'secret-value', 'address' => 'PrivateStreet', 'card' => '4111111111111111', 'order_id' => 7, 'exception' => new RuntimeException('PrivateName private@example.test token=secret-value')];
    for ($imI = 0; $imI < 2; $imI++) IncidentMonitor::report('checkout.failed', 'checkout', 'error', $imSensitive);
    assert_equals(0, count(JobQueue::pull(100)), 'Incident: checkout below configured threshold sends no alert');
    IncidentMonitor::report('checkout.failed', 'checkout', 'error', $imSensitive);
    assert_equals(1, count(JobQueue::pull(100)), 'Incident: third technical checkout failure queues one digest');
    for ($imI = 0; $imI < 5; $imI++) IncidentMonitor::report('checkout.failed', 'checkout', 'error', $imSensitive);
    assert_equals(1, count(JobQueue::pull(100)), 'Incident: repeated failures deduplicate during cooldown');
    $imFingerprint = hash('sha256', 'default|checkout.failed|checkout');
    assert_equals(1, IncidentMonitor::notify($imFingerprint), 'Incident: queued digest invokes existing MailService adapter');
    assert_equals(0, IncidentMonitor::notify($imFingerprint), 'Incident: notification replay does not send twice');
    assert_equals(1, count($imMail->messages), 'Incident: exactly one digest message');
    assert_true(str_contains($imMail->messages[0]['html'], 'checkout.failed') && str_contains($imMail->messages[0]['html'], 'incident-regression-correlation'), 'Incident: notification includes stable code and correlation');
    $imState = $imDb->query('SELECT * FROM operational_alert_state WHERE fingerprint = ' . $imDb->quote($imFingerprint))->fetch();
    assert_equals('accepted', $imState['notification_status'], 'Incident: adapter acceptance is recorded, not claimed inbox delivery');
    assert_true((int)$imState['last_notification_at'] > 0, 'Incident: last observed adapter acceptance is recorded');
    IncidentMonitor::report('checkout.failed', 'checkout', 'error');
    assert_equals(1, count($imMail->messages), 'Incident: new repeat inside cooldown sends no mail');
    $imDb->prepare('UPDATE operational_alert_state SET last_queued_at = 0 WHERE fingerprint = ?')->execute([$imFingerprint]);
    assert_equals(1, IncidentMonitor::sweep(), 'Incident: pending repeat digest becomes eligible after cooldown');
    assert_equals(1, IncidentMonitor::notify($imFingerprint), 'Incident: follow-up digest succeeds');
    IncidentMonitor::report('payment.session_failed', 'payment', 'critical', $imSensitive);
    $imCritical = hash('sha256', 'default|payment.session_failed|payment');
    assert_equals('queued', $imDb->query('SELECT notification_status FROM operational_alert_state WHERE fingerprint = ' . $imDb->quote($imCritical))->fetchColumn(), 'Incident: first critical failure queues immediately');
    $imMail->fail = true;
    $imIncidentsBeforeMail = (int)$imDb->query('SELECT COUNT(*) FROM operational_incidents')->fetchColumn();
    $imFailed = false;
    try { IncidentMonitor::notify($imCritical); } catch (RuntimeException $e) { $imFailed = $e->getMessage() === 'Incident notification failed'; }
    assert_true($imFailed, 'Incident: throwing mail adapter returns stable retry error');
    assert_equals('failed', $imDb->query('SELECT notification_status FROM operational_alert_state WHERE fingerprint = ' . $imDb->quote($imCritical))->fetchColumn(), 'Incident: mail failure is locally retained');
    assert_equals($imIncidentsBeforeMail, (int)$imDb->query('SELECT COUNT(*) FROM operational_incidents')->fetchColumn(), 'Incident: notifier failure creates no recursive incident');
    $imMail->fail = false;
    assert_equals(1, IncidentMonitor::notify($imCritical), 'Incident: failed notification can retry');
    $imMail->fail = true;
    $imOrdinaryMail = MailService::send('order_confirmation', 'buyer@example.test', 'en', []);
    assert_equals('failed', $imOrdinaryMail['status'], 'Incident: ordinary adapter exception is converted into failed mail result');
    assert_equals('queued', $imDb->query("SELECT notification_status FROM operational_alert_state WHERE code = 'mail.delivery_failed'")->fetchColumn(), 'Incident: ordinary mail failure queues critical alert');
    $imMail->fail = false;
    $imBeforeValidation = (int)$imDb->query('SELECT COUNT(*) FROM operational_incidents')->fetchColumn();
    for ($imI = 0; $imI < 20; $imI++) CheckoutService::execute(['name' => ['bot'], 'email' => '']);
    assert_equals($imBeforeValidation, (int)$imDb->query('SELECT COUNT(*) FROM operational_incidents')->fetchColumn(), 'Incident: invalid fields/bot arrays are validation, no incident spam');
    $imDb->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'im-fixture', 'Incident fixture', 'im-fixture', 0)");
    $imCategory = (int)$imDb->lastInsertId();
    $imDb->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, product_type, price, currency, stock, status) VALUES ('default', ?, 'IM-DIGITAL', 'im-digital', 'Incident digital', 'downloadable', 599, 'eur', 0, 'active')")->execute([$imCategory]);
    $imProduct = (int)$imDb->lastInsertId();
    $imDb->exec("INSERT INTO payment_methods (code, provider, name, service_type, requires_online, active) VALUES ('incidentpay', 'manual', 'Incident offline', 'offline', 0, 1)");
    $imDb->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('incidentpay', 'SK')");
    ConsentService::setDefinitionsForTesting([]);
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $_SESSION = ['cart' => [], 'csrf_token' => 'incident-test'];
    Cart::add($imProduct, null, 1);
    $imInput = ['name' => 'PrivateName', 'email' => 'fault-buyer@example.test', 'country' => 'SK', 'payment_method' => 'incidentpay', 'consents' => ['digital_delivery' => true]];
    $imDb->exec("CREATE TRIGGER im_checkout_fault BEFORE INSERT ON orders BEGIN SELECT RAISE(ABORT, 'PrivateName private@example.test token=secret-value'); END");
    $imOrderCount = (int)$imDb->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $imResult = CheckoutService::execute($imInput);
    assert_equals('checkout.failed', $imResult['error_code'], 'Incident: actual checkout database fault returns stable failure');
    assert_equals(503, http_response_code(), 'Incident: technical checkout fault returns 503');
    assert_equals($imOrderCount, (int)$imDb->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'Incident: failed create rolls back order');
    assert_true(!str_contains(json_encode($imResult), 'private@example.test'), 'Incident: public checkout error does not expose exception data');
    $imDb->exec('DROP TRIGGER im_checkout_fault');
    $imLoggerConfig->setValue(null, ['structured_log_file' => $imRoot]);
    assert_true(!IncidentMonitor::probeLog(), 'Incident: log-write probe detects actual unwritable target');
    assert_equals('failed', $imDb->query("SELECT status FROM operational_monitor_state WHERE probe = 'log'")->fetchColumn(), 'Incident: failed log-write evidence visible in ops');
    // A broken alert queue must not change a successful commerce result.
    JobQueue::setDir($imRoot . '/broken-jobs');
    mkdir($imRoot . '/broken-jobs', 0700, true);
    file_put_contents($imRoot . '/broken-jobs/pending', 'blocked-directory');
    $imResult = CheckoutService::execute($imInput);
    assert_true(!empty($imResult['success']), 'Incident: logger and notifier queue failures do not block checkout');
    assert_equals($imOrderCount + 1, (int)$imDb->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'Incident: successful order commits once despite monitoring failure');
    $imLoggerConfig->setValue(null, $imConfig);
    JobQueue::setDir($imRoot . '/jobs');
    assert_true(IncidentMonitor::probeLog(), 'Incident: log-write probe verifies a real append');
    $imAdaptersProperty = new ReflectionProperty(Adapters::class, 'instances');
    $imOldAdapters = $imAdaptersProperty->getValue();
    $imAdaptersProperty->setValue(null, ['payment' => new MockPayment(['mock_result' => 'error'])]);
    try {
        $imDb->exec("UPDATE payment_methods SET requires_online = 1, provider = 'mock' WHERE code = 'incidentpay'");
        Cart::add($imProduct, null, 1);
        $imPaymentResult = CheckoutService::execute(array_replace($imInput, ['email' => 'payment-fault@example.test']), ['app_url' => 'https://shop.example']);
        assert_true(!empty($imPaymentResult['success']) && isset($imPaymentResult['payment_error']), 'Incident: actual gateway fault preserves created order with safe payment error');
        $imGatewayFault = $imDb->query("SELECT context_json FROM operational_incidents WHERE code = 'payment.session_failed' ORDER BY occurred_at DESC")->fetchAll();
        assert_true(in_array((int)$imPaymentResult['order_id'], array_map(static fn(array $row): int => (int)(json_decode($row['context_json'], true)['order_id'] ?? 0), $imGatewayFault), true), 'Incident: actual gateway exception recorded with order ID');
    } finally {
        $imAdaptersProperty->setValue(null, $imOldAdapters);
        $imDb->exec("UPDATE payment_methods SET requires_online = 0, provider = 'manual' WHERE code = 'incidentpay'");
    }
    mkdir($imRoot . '/worker/cli', 0700, true);
    mkdir($imRoot . '/worker/src', 0700, true);
    $imWorkerCfg = array_replace($imConfig, ['jobs_dir' => $imRoot . '/worker/jobs']);
    file_put_contents($imRoot . '/worker/config.php', '<?php return ' . var_export($imWorkerCfg, true) . ';');
    file_put_contents($imRoot . '/worker/src/autoload.php', '<?php
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';
$cfg = require dirname(__DIR__) . "/config.php";
$db = new PDO("sqlite:" . ' . var_export($imRoot . '/shop.sqlite', true) . ', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($db, "sqlite");
Bootstrap::initStoreContext("/", "", "default");
JobQueue::setDir($cfg["jobs_dir"]);
StockAlerts::setConfigForTesting($cfg);
(new ReflectionProperty(Logger::class, "config"))->setValue(null, $cfg);
(new ReflectionProperty(IncidentMonitor::class, "config"))->setValue(null, $cfg);
');
    copy(TEST_CLI_DIR . '/cli-only.php', $imRoot . '/worker/cli/cli-only.php');
    copy(TEST_CLI_DIR . '/worker.php', $imRoot . '/worker/cli/worker.php');
    JobQueue::setDir($imRoot . '/worker/jobs');
    JobQueue::push('fault_fixture_unknown', [], 1);
    $imDb->prepare("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, active) VALUES ('IM-DIGITAL', 'fault', 'Fixture', 'missing.pdf', 'missing.pdf', 'application/pdf', 1)")->execute();
    JobQueue::push('digital_fulfill', ['order_id' => (int)$imResult['order_id']], 1);
    $imWorker = proc_open([PHP_BINARY, $imRoot . '/worker/cli/worker.php', '--once'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $imWorkerPipes);
    fclose($imWorkerPipes[0]);
    $imWorkerOutput = stream_get_contents($imWorkerPipes[1]);
    $imWorkerErrors = stream_get_contents($imWorkerPipes[2]);
    fclose($imWorkerPipes[1]);
    fclose($imWorkerPipes[2]);
    assert_equals(0, proc_close($imWorker), 'Incident: real isolated worker handles injected jobs without crashing');
    assert_true((int)$imDb->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'worker.job_failed'")->fetchColumn() > 0, 'Incident: real worker fault recorded');
    assert_true((int)$imDb->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'digital.fulfillment_failed'")->fetchColumn() > 0, 'Incident: real digital fulfillment fault recorded');
    // The retry is in backoff, which pull() no longer hands out (CR-M10): read the pending queue.
    $imRetryJobs = array_filter(
        array_map(static fn(string $file): ?array => json_decode((string)file_get_contents($file), true), glob($imRoot . '/worker/jobs/pending/*.json') ?: []),
        static fn(?array $job): bool => ($job['type'] ?? '') === 'fault_fixture_unknown' && !JobQueue::isReady($job)
    );
    assert_equals(1, count($imRetryJobs), 'Incident: real worker fault remains queued for retry');
    assert_equals('worker.job_failed', array_values($imRetryJobs)[0]['last_error'], 'Incident: worker retry error is stable and contains no raw exception');
    assert_equals('incident-regression-correlation', array_values($imRetryJobs)[0]['correlation_id'], 'Incident: worker preserves correlation across retry');
    JobQueue::setDir($imRoot . '/jobs');
    $imLog = (string)file_get_contents($imRoot . '/application.jsonl');
    $imRecords = json_decode('[' . implode(',', array_filter(explode("\n", trim($imLog)))) . ']', true, 512, JSON_THROW_ON_ERROR);
    assert_true(count($imRecords) > 0, 'Incident: parseable JSONL records exist');
    foreach (['PrivateName', 'private@example.test', '127.0.0.99', 'secret-value', 'private-session', 'PrivateStreet', '4111111111111111'] as $imSecret) {
        assert_true(!str_contains($imLog, $imSecret), 'Incident: sensitive value absent from operational log');
    }
    $imOps = IncidentMonitor::snapshot();
    assert_true($imOps['available'] && count($imOps['incidents']) > 0 && count($imOps['probes']) > 0, 'Incident: ops shows actual incident and probe evidence');
    assert_equals([], IncidentMonitor::snapshot('foreign-fixture')['incidents'], 'Incident: ops is store-scoped');
    $imMigrationTables = ['operational_incidents', 'operational_alert_state', 'operational_monitor_state'];
    foreach ($imMigrationTables as $imTable) $imDb->exec('DROP TABLE ' . $imTable);
    IncidentMonitor::report('checkout.failed', 'checkout', 'error', $imSensitive);
    assert_true(true, 'Incident: unavailable persistence does not throw into checkout');
} finally {
    ConsentService::setDefinitionsForTesting(null);
    Db::init($imPreviousDb, 'sqlite');
    StoreContext::activate($imPreviousStore);
    $_SESSION = $imPreviousSession;
    $imLoggerConfig->setValue(null, $imOldLogger);
    $imMonitorConfig->setValue(null, $imOldMonitor);
    $imMailAdapter->setValue(null, $imOldMail);
    $imJobsDir->setValue(null, $imOldJobs);
    $imForceFile->setValue(null, $imOldForceFile);
    Logger::setCorrelationId($imOldCorrelation);
    putenv($imOldFrom === false ? 'MAIL_FROM' : 'MAIL_FROM=' . $imOldFrom);
    $imDb = null;
    $imPreviousDb = null;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($imRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $imFile) {
        $imFile->isDir() ? rmdir($imFile->getPathname()) : unlink($imFile->getPathname());
    }
    rmdir($imRoot);
}
