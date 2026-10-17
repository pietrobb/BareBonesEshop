<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Job Worker
 * Processes pending jobs from the file-based queue.
 * Usage: php cli/worker.php [--once] [--verbose]
 *
 * Run via cron: * * * * * php /path/to/cli/worker.php
 * Exclusive lock prevents multiple instances.
 */
require_once __DIR__ . '/../src/autoload.php';

set_exception_handler(static function (Throwable $e): void {
    IncidentMonitor::heartbeat('worker', 'failed');
    IncidentMonitor::report('worker.unhandled_failed', 'worker', 'critical', ['exception' => $e]);
    fwrite(STDERR, "worker.unhandled_failed\n");
    exit(1);
});

$once = in_array('--once', $argv ?? []);
$verbose = in_array('--verbose', $argv ?? []);

// The file backend needs a process lock; durable backends use per-job atomic leases
// and intentionally permit multiple workers.
$cfg = require __DIR__ . '/../config.php';
$lock = null;
$lockFile = null;
if (!JobQueue::supportsConcurrentWorkers()) {
    $jobsDir = $cfg['jobs_dir'] ?? dirname(__DIR__, 2) . '/bareboneseshop-private/jobs';
    if (!is_dir($jobsDir)) mkdir($jobsDir, 0750, true);
    $lockFile = $jobsDir . '/worker.lock';
    $lock = JobQueue::acquireWorkerLock($lockFile);
    if ($lock === null) {
        if ($verbose) echo "Worker is already running.\n";
        exit(0);
    }
}

if ($verbose) echo "Worker started (PID: " . getmypid() . ", queue: " . JobQueue::backendName() . ")\n";

// Job handlers registry
$handlers = [
    'cache_rebuild'     => 'handleCacheRebuild',
    'email_send'        => 'handleEmailSend',
    'stock_alert'       => 'handleStockAlert',
    'search_reindex'    => 'handleSearchReindex',
    'payment_refund'    => 'handlePaymentRefund',
    'payment_cancel'    => 'handlePaymentCancel',
    'erp_order_push'    => 'handleErpOrderPush',
    'erp_order_update'  => 'handleErpOrderUpdate',
    'cart_abandonment'  => 'handleCartAbandonment',
    'cleanup'           => 'handleCleanup',
    'image_resize'      => 'handleImageResize',
    'import_process'    => 'handleImportProcess',
    'invoice_issue'     => 'handleInvoiceIssue',
    'digital_fulfill'   => 'handleDigitalFulfill',
    'digital_revoke'    => 'handleDigitalRevoke',
    'merchant_notify'   => 'handleMerchantNotify',
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
            $provider = strtolower((string)($snapshot['provider'] ?? orderProviderCode($order, 'payment')));
            if ($provider === '') $provider = Adapters::defaultProviderCode('payment');
            // Stores may hold different provider credentials, so gateways are cached per provider scope.
            $gatewayKey = StoreContext::current()->providerId() . ':' . $provider;
            $gateways[$gatewayKey] ??= Adapters::payment($provider);
            return $gateways[$gatewayKey]->getStatus((string)$order['payment_id']);
        }
    );
    if ($verbose && $expiredOrders > 0) echo "Expired pending orders: {$expiredOrders}\n";
} catch (\Throwable $e) {
    Logger::exception('worker.pending_expiry_failed', $e);
}

// Deliver committed outbox events before file jobs. Failed endpoints are retried
// from the database without repeating the original commerce mutation.
try {
    processOutbox($cfg, $verbose);
} catch (\Throwable $e) {
    Logger::exception('worker.outbox_sweep_failed', $e);
}

// The single abandoned-cart reminder is due-date driven, so it is swept on every
// cron run instead of depending on someone enqueueing a cart_abandonment job.
// Off unless enabled; even then only carts whose shopper gave reminder consent are reminded.
try {
    $reminderCfg = (array)($cfg['cart_reminder'] ?? []);
    if (($reminderCfg['enabled'] ?? false) === true) {
        $reminders = AbandonedCarts::sweep($reminderCfg + ['base_url' => cartReminderBaseUrl($cfg)]);
        if ($verbose && $reminders > 0) echo "Cart reminders queued: {$reminders}\n";
    }
} catch (\Throwable $e) {
    Logger::exception('worker.cart_reminder_sweep_failed', $e);
}

// Retention housekeeping runs as a queued job once a day; nothing else enqueues it.
try {
    if (RetentionCleanup::enqueueIfDue(workerJobsDir($cfg) . '/retention-cleanup.last') && $verbose) echo "Cleanup job queued\n";
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
}
StoreContext::activate($digestStore);

$monitorStore = StoreContext::current();
foreach (Bootstrap::stores()['stores'] ?? [] as $monitorStoreCode => $_) {
    Bootstrap::initStoreContext('/', '', (string)$monitorStoreCode);
    IncidentMonitor::heartbeat('worker', 'running');
    IncidentMonitor::sweep();
}
StoreContext::activate($monitorStore);

// Process loop
$maxRuntime = 55; // seconds (cron runs every minute)
$startTime = time();
$processed = 0;

do {
    $jobs = JobQueue::pull(5);

    if (empty($jobs)) {
        if ($once) break;
        sleep(2);
        continue;
    }

    $claimedAny = false;
    foreach ($jobs as $job) {
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
            if ($handler && is_callable($handler)) {
                $handler($payload, $job);
            } else {
                throw new \RuntimeException("Unknown job type: {$type}");
            }

            JobQueue::complete($procFile);
            $processed++;
            Logger::info('worker.job_completed', ['job_id' => $job['id'] ?? '', 'job_type' => $type]);
            if ($verbose) echo "    [OK]\n";

        } catch (JobLeaseLost $e) {
            // The job now belongs to another worker: neither complete nor fail this stale claim.
            Logger::warning('worker.job_lease_lost', ['job_id' => $job['id'] ?? '', 'job_type' => $type]);
            if ($verbose) echo "    [ABORTED] lease lost\n";
        } catch (\Throwable $e) {
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
        }
    }

    if ($once) break;
    // Every pulled job was in backoff or claimed elsewhere: wait instead of spinning.
    if (!$claimedAny) sleep(2);

} while (time() - $startTime < $maxRuntime);

// Release the file-backend process lock, when one was acquired. The file itself stays: unlinking
// it lets a worker that opened the old file and a worker that creates a new one both "hold" it.
if (is_resource($lock)) {
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($verbose) echo "Worker done. Processed: {$processed} jobs.\n";

// ============================================================
// Job Handlers
// ============================================================

function orderProviderCode(array $order, string $type): string {
    $snapshot = json_decode((string)($order['financial_snapshot'] ?? ''), true) ?: [];
    return strtolower((string)($snapshot['providers'][$type] ?? ''));
}

/**
 * Outbox sweep (see OutboxDispatcher): per-endpoint failures are recorded and retried with
 * Outbox::markRetry without blocking other endpoints, and the sweep is time-boxed.
 */
function processOutbox(array $cfg, bool $verbose = false): void {
    OutboxDispatcher::run($cfg, $verbose);
}

function handleCacheRebuild(array $payload, array $job): void {
    $scope = $payload['scope'] ?? 'partial';
    $cfg = require __DIR__ . '/../config.php';
    $stores = json_decode(file_get_contents(__DIR__ . '/../stores.json'), true);
    $locales = [];
    foreach ($stores['stores'] ?? [] as $store) {
        foreach ($store['languages'] ?? [] as $lang) {
            $locales[$lang] = true;
        }
    }
    $locales = array_keys($locales) ?: [$stores['default_locale'] ?? 'en'];

    if ($scope === 'full' || !empty($payload['prefix'])) {
        // Full rebuild — categories + all category listings
        foreach ($locales as $locale) {
            I18n::setLocale($locale);

            // Categories tree
            $tree = Categories::tree();
            $translatedTree = array_map(function($cat) {
                $cat = I18n::category($cat);
                $cat = Categories::enrich($cat);
                if (!empty($cat['children'])) {
                    $cat['children'] = array_map(function($child) {
                        return Categories::enrich(I18n::category($child));
                    }, $cat['children']);
                }
                return $cat;
            }, $tree);
            Cache::writeJson(Cache::localePath($locale, 'categories.json'), ['categories' => $translatedTree]);

            // Category listings (first page of each)
            $allCats = Db::get()->query("SELECT * FROM categories")->fetchAll();
            $perPage = $cfg['per_page'] ?? 24;

            foreach ($allCats as $cat) {
                JobQueue::heartbeat();
                $data = Products::byCategory($cat['id'], 1, $perPage, 'price_asc');
                $data['products'] = Products::enrichMany(array_map([I18n::class, 'product'], $data['products']));
                Cache::writeJson(Cache::localePath($locale, "cat/{$cat['slug']}/page-1.json"), $data);

                // Filters
                $raw = Products::filterValues($cat['id']);
                $filters = [];
                foreach ($raw as $attrName => $options) {
                    $filters[] = [
                        'attr_name' => $attrName,
                        'label'     => I18n::attrLabel($attrName),
                        'options'   => array_map(fn($opt) => [
                            'value' => $opt['value'],
                            'label' => I18n::attr($attrName, $opt['value']),
                            'count' => $opt['count'],
                        ], $options),
                    ];
                }
                Cache::writeJson(Cache::localePath($locale, "cat/{$cat['slug']}/filters.json"), ['filters' => $filters]);
            }
        }

        // Pre-computed price cache per customer group
        $allProducts = Db::get()->query("SELECT id, sku, price, category_id FROM products WHERE status = 'active'")->fetchAll();
        $pricingGroups = array_keys(Pricing::config()['customer_groups'] ?? ['guest' => []]);
        if (!in_array('guest', $pricingGroups, true)) array_unshift($pricingGroups, 'guest');
        foreach ($pricingGroups as $group) {
            JobQueue::heartbeat();
            $prices = [];
            foreach ($allProducts as $p) {
                $priced = Pricing::apply($p, $group);
                $prices[$p['sku']] = $priced['price'];
            }
            Cache::writeJson("prices/{$group}.json", $prices);
        }
    } elseif (!empty($payload['path'])) {
        // Single path rebuild — proactively regenerate the cache file
        $path = $payload['path'];
        $perPage = $cfg['per_page'] ?? 24;

        // Determine locale and content type from path pattern, then regenerate
        if (preg_match('#^([a-z]{2})/cat/([a-z0-9-]+)/page-(\d+)\.json$#', $path, $m)) {
            $locale = $m[1];
            $catSlug = $m[2];
            $pageNum = (int)$m[3];
            I18n::setLocale($locale);
            $cat = Categories::bySlug($catSlug);
            if ($cat) {
                $data = Products::byCategory($cat['id'], $pageNum, $perPage, 'price_asc');
                $data['products'] = Products::enrichMany(array_map([I18n::class, 'product'], $data['products']));
                Cache::writeJson(Cache::localePath($locale, "cat/{$catSlug}/page-{$pageNum}.json"), $data);
            }
        } elseif (preg_match('#^([a-z]{2})/product/([a-z0-9-]+)\.json$#', $path, $m)) {
            $locale = $m[1];
            $prodSlug = $m[2];
            I18n::setLocale($locale);
            $product = Products::bySlug($prodSlug);
            if ($product) {
                $product = Products::enrich(I18n::product($product));
                $product['variants'] = Products::variants($product['id']);
                $product['attributes'] = Products::attrs($product['id']);
                Cache::writeJson(Cache::localePath($locale, "product/{$prodSlug}.json"), ['product' => $product]);
            }
        } elseif (preg_match('#^([a-z]{2})/categories\.json$#', $path, $m)) {
            $locale = $m[1];
            I18n::setLocale($locale);
            $tree = Categories::tree();
            $translatedTree = array_map(function($cat) {
                $cat = I18n::category($cat);
                $cat = Categories::enrich($cat);
                if (!empty($cat['children'])) {
                    $cat['children'] = array_map(fn($c) => Categories::enrich(I18n::category($c)), $cat['children']);
                }
                return $cat;
            }, $tree);
            Cache::writeJson(Cache::localePath($locale, 'categories.json'), ['categories' => $translatedTree]);
        } else {
            // Unknown pattern — mark stale for on-demand regeneration
            $file = $cfg['cache_dir'] . '/' . $path;
            if (file_exists($file)) {
                touch($file, 1);
            }
        }
    }
}

function handleEmailSend(array $payload, array $job): void {
    $template = (string)($payload['template'] ?? '');
    $to       = (string)($payload['to'] ?? '');
    $orderId  = $payload['order_id'] ?? null;
    $locale   = (string)($payload['locale'] ?? '');
    $order = null;

    $allowedTemplates = [
        'order_confirmation', 'order_paid', 'order_cancelled', 'order_shipped',
        'refund_issued', 'invoice_issued', 'cart_reminder',
    ];
    if (!$to || !in_array($template, $allowedTemplates, true)) return;
    if ($orderId === null && $template !== 'cart_reminder') return;
    if ($template === 'cart_reminder' && AbandonedCarts::isSuppressed($to)) return;

    $vars = (array)($payload['vars'] ?? []);
    if ($orderId) {
        $order = Orders::byId((int)$orderId);
        if ($order) {
            // Order-linked jobs cannot redirect mail to a payload-controlled recipient.
            $to = (string)$order['customer_email'];
            // Prefer locale from order row, then payload, then default.
            if ($locale === '') $locale = (string)($order['locale'] ?? '');
            $vars = array_merge($vars, [
                'order_number'   => $order['order_number'],
                'order_status'   => $order['status'],
                'order_total'    => I18n::price((int)$order['total'], $order['currency'] ?? null),
                'customer_name'  => $order['customer_name'],
                'customer_email' => $order['customer_email'],
                'payment_method' => $order['payment_method'] ?? '',
                'carrier'        => $order['carrier'] ?? '',
                'tracking_number'=> $order['tracking_number'] ?? '',
            ]);
            if ($template === 'order_confirmation') $vars = array_merge($vars, BankTransferInstructions::mailVars($order));
            $items = Orders::items((int)$orderId);
            $vars['items'] = array_map(fn($it) => [
                'name'       => $it['name'],
                'sku'        => $it['sku'],
                'quantity'   => $it['quantity'],
                'unit_price' => I18n::price((int)$it['unit_price']),
                'total'      => I18n::price((int)$it['total']),
            ], $items);
        } else {
            return;
        }
    }

    // Retry with the provider frozen on the order; legacy jobs use the current default.
    $providerCode = (string)($payload['_provider_code'] ?? ($order ? orderProviderCode($order, 'mail') : ''));
    if ($providerCode === '') $providerCode = Adapters::defaultProviderCode('mail');
    $result = MailService::send($template, $to, $locale, $vars, $orderId !== null ? (int)$orderId : null, [], $providerCode);
    if (($result['status'] ?? '') === 'failed') throw new RuntimeException('mail.delivery_failed');
    // Rate-limited order mail is queued again after the limit window instead of being dropped.
    if (($result['status'] ?? '') === 'rate_limited') MailService::deferRateLimited($payload, $result);
}

function handleDigitalFulfill(array $payload, array $job): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId <= 0 || !DigitalDelivery::orderHasAssets($orderId)) return;

    // One fulfilment per order at a time: a concurrent job would rotate (revoke) the links this one mails.
    $claim = DigitalDelivery::claimFulfilment($orderId);
    // Another worker is fulfilling this order right now: retry with backoff, by then its links are
    // delivered and grant() has nothing left (a crashed holder's claim lapses after its lease).
    if ($claim === null) throw new RuntimeException("Digital fulfilment of order #{$orderId} is already in progress");
    try {
        fulfilDigitalOrder($orderId, $claim);
    } finally {
        DigitalDelivery::releaseFulfilment($claim);
    }
}

/** $claim (the fulfilment claim, renewed in place) is renewed right before the mail, so its lease covers the delivery. */
function fulfilDigitalOrder(int $orderId, ?string &$claim = null): void {
    $links = DigitalDelivery::grant($orderId);
    if (!$links) return;
    $order = Orders::byId($orderId);
    if (!$order) throw new RuntimeException("Order #{$orderId} not found");

    $formatted = array_map(static function (array $link): array {
        $validity = $link['expires_at'] === null
            ? 'no expiry'
            : date('Y-m-d H:i T', (int)$link['expires_at']);
        if ($link['download_limit'] !== null) $validity .= ', max ' . (int)$link['download_limit'] . ' downloads';
        return ['label' => $link['label'], 'url' => $link['url'], 'validity' => $validity];
    }, $links);

    // Fresh leases for the slow mail: the job's, and the per-order claim (lost = another worker took the order
    // over and rotates these links; it delivers its own, so this run must not mail links about to be revoked).
    JobQueue::heartbeat();
    if ($claim !== null) {
        $renewed = DigitalDelivery::renewFulfilment($claim);
        if ($renewed === null) throw new RuntimeException("Digital fulfilment claim of order #{$orderId} was lost to another worker");
        $claim = $renewed;
    }
    $result = MailService::send('digital_delivery', (string)$order['customer_email'], (string)($order['locale'] ?? ''), [
        'order_number' => $order['order_number'],
        'customer_name' => $order['customer_name'],
        'download_links' => $formatted,
    ], $orderId, [], orderProviderCode($order, 'mail') ?: Adapters::defaultProviderCode('mail'));
    $ids = array_column($links, 'id');
    if (($result['status'] ?? '') !== 'sent') {
        DigitalDelivery::revokeIssued($ids, 'delivery_email_failed');
        throw new RuntimeException('Digital delivery email failed: ' . ($result['error'] ?? 'unknown error'));
    }
    DigitalDelivery::markDelivered($ids);
}

function handleDigitalRevoke(array $payload, array $job): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId > 0) DigitalDelivery::revokeOrder($orderId, (string)($payload['reason'] ?? 'order_reversal'));
}

function handleMerchantNotify(array $payload, array $job): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId > 0) MerchantNotifier::send($orderId, (string)($payload['event'] ?? 'paid'));
}

function handleStockAlert(array $payload, array $job): void {
    // Jobs without a SKU list (legacy {threshold} payloads) re-evaluate every tracked SKU.
    $skus = isset($payload['skus']) && is_array($payload['skus']) ? $payload['skus'] : null;
    StockAlerts::process($skus);
}

function handleSearchReindex(array $payload, array $job): void {
    $productId = $payload['product_id'] ?? null;

    if (Db::isSqlite()) {
        if ($productId) {
            // Single product — FTS triggers handle this automatically on UPDATE
            $now = Db::now();
            Db::get()->prepare("UPDATE products SET updated_at = {$now} WHERE id = :id")
                ->execute([':id' => $productId]);
        } else {
            // Full reindex — rebuild FTS5
            JobQueue::heartbeat(true); // a fresh lease for the rebuild (no-op outside a worker job)
            Db::get()->exec("INSERT INTO products_fts(products_fts) VALUES('rebuild')");
        }
    } else {
        // MySQL — FULLTEXT indexes are maintained automatically, but we can
        // OPTIMIZE TABLE to rebuild if full reindex is requested
        if (!$productId) {
            JobQueue::heartbeat(true);
            Db::get()->exec("OPTIMIZE TABLE products");
        }
        // Single product: no action needed — MySQL FULLTEXT auto-updates on change
    }
}

function orderPaymentGateway(array $payload, ?array $order): PaymentAdapter {
    $paymentSnapshot = $order ? (json_decode((string)($order['payment_method_snapshot'] ?? ''), true) ?: []) : [];
    $providerCode = (string)($payload['_provider_code'] ?? ($paymentSnapshot['provider'] ?? ($order ? orderProviderCode($order, 'payment') : '')));
    if ($providerCode === '') $providerCode = Adapters::defaultProviderCode('payment');
    return Adapters::payment($providerCode);
}

/**
 * Refund job. Item refunds carry their order_refunds id; whole-order refunds (cancellation,
 * payment captured on a cancelled order) carry a ledger idempotency key from
 * PaymentService::orderRefundJobsWithin(). A provider failure fails the job, so it is retried
 * and, when retries run out, raised as an incident — never recorded as done.
 */
function handlePaymentRefund(array $payload, array $job): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    $amount = (int)($payload['amount'] ?? 0);
    $paymentId = (string)($payload['payment_id'] ?? '');
    $itemRefundId = (int)($payload['refund_id'] ?? 0);
    $idempotencyKey = (string)($payload['idempotency_key'] ?? '');
    $order = $orderId > 0 ? Orders::byId($orderId) : null;

    if ($itemRefundId === 0 && $idempotencyKey === '' && $order) {
        // Job queued before the refund ledger: claim the order's refundable balance now.
        $db = Db::get();
        $db->beginTransaction();
        try {
            PaymentService::orderRefundJobsWithin($orderId, 'order.cancelled');
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        // Read back from the ledger, so a retry of this job refunds the same claim.
        $request = PaymentService::orderRefundRequest($orderId);
        if ($request === null) return; // unpaid or nothing left to refund
        [$amount, $idempotencyKey] = [$request['amount'], $request['idempotency_key']];
    }
    // A redelivered whole-order job after the provider confirmed the refund only finishes the bookkeeping.
    $alreadyRefunded = $itemRefundId === 0 && $idempotencyKey !== '' && PaymentService::refundSucceeded($idempotencyKey);

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) mkdir($logDir, 0750, true);

    $result = $alreadyRefunded ? ['status' => 'succeeded', 'replayed' => true] : ['status' => 'skipped'];
    if (!$alreadyRefunded && $paymentId !== '' && $orderId > 0 && $amount > 0) {
        // Renew the lease before moving money (outside any transaction); aborts if another worker owns the job.
        JobQueue::heartbeat(true);
        try {
            $result = PaymentService::refund($orderId, $paymentId, $amount, orderPaymentGateway($payload, $order), $idempotencyKey, (string)($order['currency'] ?? 'eur'));
        } catch (\Throwable $e) {
            $result = ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    file_put_contents($logDir . '/refunds.log',
        date('c') . " | ORDER: {$orderId} | AMOUNT: {$amount} | PAYMENT: {$paymentId} | RESULT: " . json_encode($result) . "\n",
        FILE_APPEND | LOCK_EX
    );

    $status = (string)($result['status'] ?? '');
    if ($status === 'skipped') return;
    $refundId = (string)($result['refund_id'] ?? $result['id'] ?? '');
    // An asynchronous provider refund is finalised by its refund webhook, which finds an item refund by this id.
    if ($status === 'pending') {
        if ($itemRefundId > 0 && $refundId !== '') Rma::noteProviderRefund($itemRefundId, $refundId);
        return;
    }
    if ($status !== 'succeeded') {
        throw new RuntimeException("Refund provider call did not succeed for order #{$orderId}");
    }

    if ($refundId !== '') Orders::setRefundId($orderId, $refundId);
    if ($itemRefundId > 0) Rma::completeRefund($itemRefundId, $refundId);
    $order = Orders::byId($orderId);
    if (!$order) return;
    $paymentStatus = PaymentService::refundedAmount($orderId) >= (int)$order['total'] ? 'refunded' : 'partially_refunded';
    if ($itemRefundId > 0 || ($order['payment_status'] ?? '') === 'refund_pending') {
        PaymentService::updatePaymentStatus($orderId, $paymentStatus, 'refund.succeeded', [
            'refund_id' => $itemRefundId > 0 ? $itemRefundId : null,
            'amount' => $amount,
            'provider_ref' => $refundId,
        ]);
    }
    if ($itemRefundId === 0 && $paymentStatus === 'refunded' && OrderWorkflow::canTransition((string)$order['status'], 'refunded')) {
        OrderWorkflow::transition($orderId, 'refunded', "Payment refund completed: {$paymentId}");
    }
}

/** Void the provider session of a cancelled, unpaid order so it can no longer be paid. */
function handlePaymentCancel(array $payload, array $job): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    $order = $orderId > 0 ? Orders::byId($orderId) : null;
    $paymentId = (string)($payload['payment_id'] ?? '');
    // Paid meanwhile: PaymentService::settleCapture() refunds it instead.
    if (!$order || $paymentId === '' || ($order['payment_status'] ?? '') !== 'cancelled') return;
    $gateway = orderPaymentGateway($payload, $order);
    if ($gateway instanceof CancellablePaymentAdapter) $gateway->cancelPayment($paymentId);
}

function handleErpOrderPush(array $payload, array $job): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    $order = $orderId > 0 ? Orders::byId($orderId) : null;
    if (!$order) return;
    $providerCode = (string)($payload['_provider_code'] ?? orderProviderCode($order, 'erp'));
    $adapter = $providerCode !== '' ? Adapters::integration($providerCode) : Adapters::integration();
    if (!$adapter) return;
    $order['items'] = Orders::items($orderId);
    JobQueue::heartbeat(true); // a fresh lease for the ERP call
    Orders::markSynced($orderId, $adapter->pushOrder($order));
}

function handleErpOrderUpdate(array $payload, array $job): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    $order = $orderId > 0 ? Orders::byId($orderId) : null;
    if (!$order || empty($order['erp_id'])) return;
    $providerCode = (string)($payload['_provider_code'] ?? orderProviderCode($order, 'erp'));
    $adapter = $providerCode !== '' ? Adapters::integration($providerCode) : Adapters::integration();
    if (!$adapter) return;
    if (!$adapter instanceof OrderStatusIntegrationAdapter) {
        throw new RuntimeException("ERP provider does not support order status updates: {$providerCode}");
    }
    $status = strtolower((string)($payload['status'] ?? $order['status'] ?? ''));
    JobQueue::heartbeat(true); // a fresh lease for the ERP call
    $adapter->updateOrderStatus((string)$order['erp_id'], $status, [
        'order_id' => $orderId,
        'order_number' => (string)$order['order_number'],
        'store_id' => StoreContext::current()->id(),
    ]);
}

function handleCartAbandonment(array $payload, array $job): void {
    $cfg = require __DIR__ . '/../config.php';
    $options = (array)($cfg['cart_reminder'] ?? []);
    if (($options['enabled'] ?? false) !== true) return;
    if (isset($payload['hours'])) $options['delay_hours'] = (int)$payload['hours'];
    AbandonedCarts::sweep($options + ['base_url' => cartReminderBaseUrl($cfg)]);
}

function cartReminderBaseUrl(array $cfg): string {
    try {
        return Security::appUrl($cfg);
    } catch (\RuntimeException $e) {
        return '';
    }
}

function handleCleanup(array $payload, array $job): void {
    $cfg = require __DIR__ . '/../config.php';
    JobQueue::heartbeat(true); // a fresh lease for the retention sweep
    $deleted = RetentionCleanup::run(['jobs_dir' => workerJobsDir($cfg)]);
    Logger::info('worker.retention_cleanup', $deleted);
}

function workerJobsDir(array $cfg): string {
    return (string)($cfg['jobs_dir'] ?? dirname(__DIR__, 2) . '/bareboneseshop-private/jobs');
}

function handleImageResize(array $payload, array $job): void {
    $path = $payload['path'] ?? '';
    if (!$path) return;

    $storage = Adapters::storage();
    // Custom adapters written before the capability interface only define generateVariants().
    if (!$storage instanceof ImageVariantStorageAdapter && !method_exists($storage, 'generateVariants')) {
        Logger::info('worker.image_variants_unsupported', ['adapter' => get_class($storage)]);
        return;
    }
    JobQueue::heartbeat(true); // a fresh lease for the image processing
    $storage->generateVariants($path);
}

function handleInvoiceIssue(array $payload, array $job): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    if (!$orderId) return;

    // Issue invoice (idempotent — returns existing invoice if already issued)
    JobQueue::heartbeat(true); // a fresh lease for the PDF rendering
    $invoice = InvoiceService::issue($orderId);
    if (!$invoice) return;
    JobQueue::heartbeat(); // renew (or abort if taken over) before the slow mail delivery

    $order = Orders::byId($orderId);
    if (!$order) return;

    // Build PDF attachment from disk
    $pdfPath = InvoiceService::pdfAbsolutePath($invoice);
    $attachments = [];
    if (is_file($pdfPath)) {
        $attachments[] = [
            'filename'     => $invoice['number'] . '.pdf',
            'path'         => $pdfPath,
            'content_type' => 'application/pdf',
        ];
    }

    // Email the customer with the invoice
    $locale = (string)($invoice['locale'] ?? $order['locale'] ?? '');
    $vars = [
        'order_number'   => $order['order_number'],
        'invoice_number' => $invoice['number'],
        'invoice_total'  => I18n::price((int)$invoice['total_gross'], $invoice['currency'] ?? null),
        'due_date'       => $invoice['due_date'],
        'customer_name'  => $order['customer_name'],
    ];

    $result = MailService::send(
        'invoice_issued',
        (string)$order['customer_email'],
        $locale,
        $vars,
        $orderId,
        $attachments,
        orderProviderCode($order, 'mail') ?: Adapters::defaultProviderCode('mail')
    );
    // Fail the job so the queue retries delivery; issuing the invoice again is idempotent.
    if (($result['status'] ?? '') === 'failed') throw new RuntimeException('mail.delivery_failed');
}

function handleImportProcess(array $payload, array $job): void {
    $file = $payload['file'] ?? '';
    if (!$file || !file_exists($file)) {
        throw new \RuntimeException("Import file not found: {$file}");
    }

    $result = Import::csv($file);

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) mkdir($logDir, 0750, true);
    $errCount = count($result['errors'] ?? []);
    $logEntry = date('c') . " | IMPORT: {$result['imported']} ok, {$errCount} errors | FILE: {$file}\n";
    file_put_contents($logDir . '/imports.log', $logEntry, FILE_APPEND | LOCK_EX);

    // Clean up temp file only on success
    if (empty($result['errors'])) {
        @unlink($file);
    }
}
