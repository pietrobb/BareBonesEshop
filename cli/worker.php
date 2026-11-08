<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Job Worker
 * Processes pending jobs from the configured queue backend (queue.backend): `file` (JSON files in
 * jobs_dir, the default), `database` or `redis`. Before the job loop, every run also expires abandoned
 * unpaid orders, delivers the outbound-webhook outbox, sends due abandoned-cart reminders (when enabled), schedules the
 * daily retention cleanup, and runs the per-store low-stock digest, backorder allocation and incident sweep.
 * Job handlers: src/WorkerJobs.php (a class, so this script declares no global function).
 * Usage: php cli/worker.php [--once] [--verbose]
 *
 * Run via cron: * * * * * php /path/to/cli/worker.php
 * The file backend takes an exclusive lock (one worker at a time); the database and redis backends lease
 * each job atomically, so several workers may run in parallel.
 */
require_once __DIR__ . '/../src/autoload.php';

set_exception_handler(static function (Throwable $e): void {
    IncidentMonitor::heartbeat('worker', 'failed');
    IncidentMonitor::report('worker.unhandled_failed', 'worker', 'critical', ['exception' => $e]);
    if (defined('STDERR')) fwrite(STDERR, "worker.unhandled_failed\n");
    exit(1);
});

$once = in_array('--once', $argv ?? []);
$verbose = in_array('--verbose', $argv ?? []);

// cli/update.php is replacing files or migrating the database (T20): the next cron run picks the jobs up.
if (MaintenanceMode::active(dirname(__DIR__))) {
    if ($verbose) echo "Update in progress (maintenance mode); worker skipped.\n";
    exit(0);
}

// The file backend needs a process lock; durable backends use per-job atomic leases
// and intentionally permit multiple workers.
$cfg = require AppConfig::path(dirname(__DIR__));
$lock = null;
$lockFile = null;
if (!JobQueue::supportsConcurrentWorkers()) {
    $lockFile = JobQueue::workerLockFile();
    $lock = JobQueue::acquireWorkerLock($lockFile);
    if ($lock === null) {
        if ($verbose) echo "Worker is already running.\n";
        exit(0);
    }
}

if ($verbose) echo "Worker started (PID: " . getmypid() . ", queue: " . JobQueue::backendName() . ")\n";

// Job handlers registry
$handlers = [
    'cache_rebuild'     => [WorkerJobs::class, 'handleCacheRebuild'],
    'email_send'        => [WorkerJobs::class, 'handleEmailSend'],
    'stock_alert'       => [WorkerJobs::class, 'handleStockAlert'],
    'back_in_stock'     => static fn(array $payload): int => BackInStock::process(array_map('strval', (array)($payload['skus'] ?? []))),
    'search_reindex'    => [WorkerJobs::class, 'handleSearchReindex'],
    'payment_refund'    => [WorkerJobs::class, 'handlePaymentRefund'],
    'payment_cancel'    => [WorkerJobs::class, 'handlePaymentCancel'],
    'refund_credit_note' => static fn(array $payload): ?array => InvoiceService::creditBookedRefund((int)($payload['order_id'] ?? 0), (string)($payload['refund_key'] ?? ''), (int)($payload['deferrals'] ?? 0)),
    'closure_credit_note' => static fn(array $payload): ?array => InvoiceService::creditClosure((int)($payload['order_id'] ?? 0)),
    'erp_order_push'    => [WorkerJobs::class, 'handleErpOrderPush'],
    'erp_order_update'  => [WorkerJobs::class, 'handleErpOrderUpdate'],
    'cart_abandonment'  => [WorkerJobs::class, 'handleCartAbandonment'],
    'cleanup'           => [WorkerJobs::class, 'handleCleanup'],
    'image_resize'      => [WorkerJobs::class, 'handleImageResize'],
    'import_process'    => [WorkerJobs::class, 'handleImportProcess'],
    'invoice_issue'     => [WorkerJobs::class, 'handleInvoiceIssue'],
    'digital_fulfill'   => [WorkerJobs::class, 'handleDigitalFulfill'],
    'digital_revoke'    => [WorkerJobs::class, 'handleDigitalRevoke'],
    'merchant_notify'   => [WorkerJobs::class, 'handleMerchantNotify'],
    'incident_notify'   => static fn(array $payload): int => IncidentMonitor::notify((string)($payload['fingerprint'] ?? '')),
];

// Release abandoned unpaid reservations in every store on every cron worker run. Provider
// errors and non-terminal payment states are skipped by Orders::expirePending.
try {
    $gateways = [];
    $expiredOrders = PendingOrderExpiry::sweep(
        $cfg,
        static function (array $order) use (&$gateways): string {
            $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true) ?: [];
            $provider = strtolower((string)($snapshot['provider'] ?? WorkerJobs::orderProviderCode($order, 'payment')));
            if ($provider === '') $provider = Adapters::defaultProviderCode('payment');
            // Stores may hold different provider credentials, so gateways are cached per provider scope.
            $gatewayKey = StoreContext::current()->providerId() . ':' . $provider;
            $gateways[$gatewayKey] ??= Adapters::payment($provider);
            return PendingOrderExpiry::providerStatus($order, $gateways[$gatewayKey], $provider);
        }
    );
    if ($verbose && $expiredOrders > 0) echo "Expired pending orders: {$expiredOrders}\n";
} catch (\Throwable $e) {
    Logger::exception('worker.pending_expiry_failed', $e);
}

// Deliver committed outbox events before file jobs. Failed endpoints are retried
// from the database without repeating the original commerce mutation.
try {
    WorkerJobs::processOutbox($cfg, $verbose);
} catch (\Throwable $e) {
    Logger::exception('worker.outbox_sweep_failed', $e);
}

// The single abandoned-cart reminder is due-date driven, so it is swept on every
// cron run instead of depending on someone enqueueing a cart_abandonment job.
// Off unless enabled; even then only carts whose shopper gave reminder consent are reminded.
try {
    $reminderCfg = (array)($cfg['cart_reminder'] ?? []);
    if (($reminderCfg['enabled'] ?? false) === true) {
        $reminders = AbandonedCarts::sweep($reminderCfg + ['base_url' => WorkerJobs::cartReminderBaseUrl($cfg)]);
        if ($verbose && $reminders > 0) echo "Cart reminders queued: {$reminders}\n";
    }
} catch (\Throwable $e) {
    Logger::exception('worker.cart_reminder_sweep_failed', $e);
}

// Retention housekeeping runs as a queued job once a day; nothing else enqueues it.
try {
    if (RetentionCleanup::enqueueIfDue(WorkerJobs::workerJobsDir($cfg) . '/retention-cleanup.last') && $verbose) echo "Cleanup job queued\n";
} catch (\Throwable $e) {
    Logger::exception('worker.cleanup_schedule_failed', $e);
}

// Optional daily low-stock digest (stock_alerts.digest_hour); claimed once per store and day,
// so every store gets its own digest, like the incident sweep below.
$digestStore = StoreContext::current();
foreach (Bootstrap::stores()['stores'] ?? [] as $digestStoreCode => $_) {
    try {
        Bootstrap::initStoreContext('/', '', (string)$digestStoreCode);
        StockAlerts::digest();
    } catch (\Throwable $e) {
        Logger::exception('worker.stock_digest_failed', $e, ['store' => (string)$digestStoreCode]);
    }
    // Backorder lines skipped while another transaction held their order get the stock that is still free.
    try {
        $backorderUnits = BackorderAllocation::sweep();
        if ($verbose && $backorderUnits > 0) echo "Backorder units allocated ({$digestStoreCode}): {$backorderUnits}\n";
    } catch (\Throwable $e) {
        Logger::exception('worker.backorder_sweep_failed', $e, ['store' => (string)$digestStoreCode]);
    }
}
StoreContext::activate($digestStore);

$monitorStore = StoreContext::current();
foreach (Bootstrap::stores()['stores'] ?? [] as $monitorStoreCode => $_) {
    Bootstrap::initStoreContext('/', '', (string)$monitorStoreCode);
    IncidentMonitor::heartbeat('worker', 'running');
    IncidentMonitor::sweep();
}
StoreContext::activate($monitorStore);

/** A failed job's open transaction is rolled back before its failure is recorded and the next job starts (R17). */
$rollBackOpenJob = static function (): void {
    // Never connects (a job that failed to connect has nothing to roll back) and never masks the job's own failure.
    if (!Db::connected()) return;
    try {
        if (Db::get()->inTransaction()) Db::get()->rollBack();
    } catch (\Throwable $rollbackError) {
        Logger::warning('worker.job_rollback_failed', ['error' => $rollbackError->getMessage()]);
    }
};

// Process loop
$maxRuntime = 55; // seconds (cron runs every minute)
$startTime = time();
$processed = 0;

// R23: an update, rollback, migration or restore may start while this run is in its loop (up to $maxRuntime): checked
// before every pull and every job, the run stops and closes its database connection (an open SQLite connection made
// the updater refuse its automatic rollback). Jobs not yet claimed wait for the next run.
$maintenanceRoot = dirname(__DIR__);
$maintenanceStarted = false;
$stopForMaintenance = static function () use ($maintenanceRoot, &$maintenanceStarted, $verbose): bool {
    if (!MaintenanceMode::active($maintenanceRoot)) return false;
    if ($verbose && !$maintenanceStarted) echo "Maintenance started; worker stops.\n";
    $maintenanceStarted = true;
    return true;
};

do {
    if ($stopForMaintenance()) break;
    $jobs = JobQueue::pull(5);

    if (empty($jobs)) {
        if ($once) break;
        sleep(2);
        continue;
    }

    $claimedAny = false;
    foreach ($jobs as $job) {
        if ($stopForMaintenance()) break 2;
        // Skip jobs waiting for exponential backoff
        if (!JobQueue::isReady($job)) continue;

        $type = $job['type'] ?? 'unknown';
        $handler = $handlers[$type] ?? null;
        $jobCorrelation = (string)($job['correlation_id'] ?? '');
        Logger::setCorrelationId(preg_match('/^[A-Za-z0-9._-]{8,80}$/', $jobCorrelation)
            ? $jobCorrelation
            : substr(hash('sha256', (string)($job['id'] ?? $type)), 0, 24));

        $procFile = JobQueue::markProcessing($job);
        if ($procFile === '') {
            // Lost the claim race to another worker — skip silently (F7).
            if ($verbose) echo "  Skipped (already claimed): {$type} (ID: {$job['id']})\n";
            continue;
        }

        if ($verbose) echo "  Processing: {$type} (ID: {$job['id']})\n";
        $claimedAny = true;
        JobQueue::beginWork($procFile);

        try {
            $payload = $job['payload'] ?? [];
            Bootstrap::initStoreContext(
                '/',
                '',
                (string)($payload['_store_id'] ?? 'default'),
                (string)($payload['_channel_id'] ?? 'storefront')
            );
            if ($handler !== null) {
                $handler($payload, $job);
            } else {
                throw new \RuntimeException("Unknown job type: {$type}");
            }
            // R17: a handler that returns inside its own transaction would have the completion (and every later job
            // of this loop) written into it.
            if (Db::connected() && Db::get()->inTransaction()) throw new \RuntimeException("Job left a database transaction open: {$type}");

            JobQueue::complete($procFile);
            $processed++;
            Logger::info('worker.job_completed', ['job_id' => $job['id'] ?? '', 'job_type' => $type]);
            if ($verbose) echo "    [OK]\n";

        } catch (JobLeaseLost $e) {
            $rollBackOpenJob();
            // The job now belongs to another worker: neither complete nor fail this stale claim.
            Logger::warning('worker.job_lease_lost', ['job_id' => $job['id'] ?? '', 'job_type' => $type]);
            if ($verbose) echo "    [ABORTED] lease lost\n";
        } catch (\Throwable $e) {
            $rollBackOpenJob();
            try {
                JobQueue::fail($procFile, $job, 'worker.job_failed');
            } catch (Throwable $retryError) {
                if ($type !== 'incident_notify') IncidentMonitor::report('worker.retry_failed', 'worker', 'critical', ['exception' => $retryError]);
            }
            if ($verbose) echo "    [FAIL] worker.job_failed\n";
            if ($type === 'incident_notify') Logger::safeWrite('error', 'monitoring.notification_failed', ['phase' => 'notification']);
            else IncidentMonitor::report($type === 'digital_fulfill' ? 'digital.fulfillment_failed' : 'worker.job_failed', $type === 'digital_fulfill' ? 'digital' : 'worker', 'critical', ['exception' => $e, 'attempts' => (int)($job['attempts'] ?? 0)]);
        } finally {
            JobQueue::endWork();
            Cache::flushPendingCatalogChanges();
        }
    }

    // --once drains what is ready (within $maxRuntime), not only the first batch of five.
    if ($once && !$claimedAny) break;
    // Every pulled job was in backoff or claimed elsewhere: wait instead of spinning.
    if (!$claimedAny) sleep(2);

} while (time() - $startTime < $maxRuntime);

// Release the file-backend process lock, when one was acquired. The file itself stays: unlinking
// it lets a worker that opened the old file and a worker that creates a new one both "hold" it.
if (is_resource($lock)) {
    flock($lock, LOCK_UN);
    fclose($lock);
}
if ($maintenanceStarted) Db::reset();

if ($verbose) echo "Worker done. Processed: {$processed} jobs.\n";
