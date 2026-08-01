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

$once = in_array('--once', $argv ?? []);
$verbose = in_array('--verbose', $argv ?? []);

// Exclusive lock
$cfg = require __DIR__ . '/../config.php';
$jobsDir = $cfg['jobs_dir'] ?? dirname(__DIR__, 2) . '/bareboneseshop-private/jobs';
if (!is_dir($jobsDir)) mkdir($jobsDir, 0750, true);

$lockFile = $jobsDir . '/worker.lock';
$lock = fopen($lockFile, 'w+');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    if ($verbose) echo "Worker is already running.\n";
    exit(0);
}

// Write PID
fwrite($lock, (string)getmypid());
fflush($lock);

if ($verbose) echo "Worker started (PID: " . getmypid() . ")\n";

// Job handlers registry
$handlers = [
    'cache_rebuild'     => 'handleCacheRebuild',
    'email_send'        => 'handleEmailSend',
    'stock_alert'       => 'handleStockAlert',
    'search_reindex'    => 'handleSearchReindex',
    'payment_refund'    => 'handlePaymentRefund',
    'erp_order_push'    => 'handleErpOrderPush',
    'erp_order_update'  => 'handleErpOrderUpdate',
    'sitemap_build'     => 'handleSitemapBuild',
    'cart_abandonment'  => 'handleCartAbandonment',
    'cleanup'           => 'handleCleanup',
    'image_resize'      => 'handleImageResize',
    'import_process'    => 'handleImportProcess',
    'invoice_issue'     => 'handleInvoiceIssue',
    'digital_fulfill'   => 'handleDigitalFulfill',
    'digital_revoke'    => 'handleDigitalRevoke',
    'merchant_notify'   => 'handleMerchantNotify',
];

// Release abandoned online reservations on every cron worker run. Provider
// errors and non-terminal payment states are skipped by Orders::expirePending.
try {
    $gateways = [];
    $expiredOrders = Orders::expirePending(
        (int)($cfg['pending_order_expiry_seconds'] ?? 172800),
        (int)($cfg['pending_order_expiry_batch'] ?? 25),
        static function (array $order) use (&$gateways): string {
            $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true) ?: [];
            $provider = strtolower((string)($snapshot['provider'] ?? orderProviderCode($order, 'payment')));
            if ($provider === '') $provider = Adapters::defaultProviderCode('payment');
            $gateways[$provider] ??= Adapters::payment($provider);
            return $gateways[$provider]->getStatus((string)$order['payment_id']);
        }
    );
    if ($verbose && $expiredOrders > 0) echo "Expired pending orders: {$expiredOrders}\n";
} catch (\Throwable $e) {
    error_log('Pending order expiry failed: ' . $e->getMessage());
}

// Deliver committed outbox events before file jobs. Failed endpoints are retried
// from the database without repeating the original commerce mutation.
try {
    processOutbox($cfg, $verbose);
} catch (\Throwable $e) {
    error_log('Outbox delivery sweep failed: ' . $e->getMessage());
}

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

    foreach ($jobs as $job) {
        // Skip jobs waiting for exponential backoff
        if (!JobQueue::isReady($job)) continue;

        $type = $job['type'] ?? 'unknown';
        $handler = $handlers[$type] ?? null;

        $procFile = JobQueue::markProcessing($job);
        if ($procFile === '') {
            // Lost the claim race to another worker — skip silently (F7).
            if ($verbose) echo "  Skipped (already claimed): {$type} (ID: {$job['id']})\n";
            continue;
        }

        if ($verbose) echo "  Processing: {$type} (ID: {$job['id']})\n";

        try {
            $payload = $job['payload'] ?? [];
            Bootstrap::initStoreContext(
                '/',
                '',
                (string)($payload['_store_id'] ?? 'default'),
                (string)($payload['_channel_id'] ?? 'storefront')
            );
            if ($handler && function_exists($handler)) {
                $handler($payload, $job);
            } else {
                throw new \RuntimeException("Unknown job type: {$type}");
            }

            JobQueue::complete($procFile);
            $processed++;
            if ($verbose) echo "    [OK]\n";

        } catch (\Throwable $e) {
            JobQueue::fail($procFile, $job, $e->getMessage());
            if ($verbose) echo "    [FAIL] {$e->getMessage()}\n";
            error_log("Worker job failed [{$type}]: {$e->getMessage()}");
        }
    }

    if ($once) break;

} while (time() - $startTime < $maxRuntime);

// Release lock
flock($lock, LOCK_UN);
fclose($lock);
@unlink($lockFile);

if ($verbose) echo "Worker done. Processed: {$processed} jobs.\n";

// ============================================================
// Job Handlers
// ============================================================

function orderProviderCode(array $order, string $type): string {
    $snapshot = json_decode((string)($order['financial_snapshot'] ?? ''), true) ?: [];
    return strtolower((string)($snapshot['providers'][$type] ?? ''));
}

function processOutbox(array $cfg, bool $verbose = false): void {
    $endpoints = is_array($cfg['outbound_webhooks'] ?? null) ? $cfg['outbound_webhooks'] : [];
    foreach (Outbox::pending(25) as $pending) {
        $event = Outbox::claim((string)$pending['id']);
        if ($event === null) continue;
        try {
            Bootstrap::initStoreContext(
                '/',
                '',
                (string)$event['store_id'],
                (string)$event['channel_id']
            );
            $envelope = Outbox::envelope($event);
            $matched = 0;
            $failures = [];
            foreach ($endpoints as $code => $endpoint) {
                if (!is_array($endpoint)) continue;
                $stores = $endpoint['stores'] ?? [];
                if (is_array($stores) && $stores !== [] && !in_array((string)$event['store_id'], $stores, true)) continue;
                if (!Webhook::endpointAccepts($endpoint, (string)$event['event_type'])) continue;
                $matched++;
                if (Outbox::endpointDelivered((string)$event['id'], (string)$code)) continue;

                $result = Webhook::deliver($endpoint, $envelope);
                Outbox::recordDelivery(
                    (string)$event['id'],
                    (string)$code,
                    (int)$event['attempts'],
                    $result['delivered'],
                    $result['response_code'],
                    $result['error']
                );
                if (!$result['delivered']) {
                    $failures[] = (string)$code . ':' . ($result['response_code'] ?? 'network');
                }
            }
            if ($failures === []) {
                Outbox::markDelivered((string)$event['id']);
                if ($verbose) echo "Delivered outbox event {$event['id']} to {$matched} endpoint(s)\n";
            } else {
                Outbox::markRetry((string)$event['id'], (int)$event['attempts'], implode(', ', $failures));
                if ($verbose) echo "Deferred outbox event {$event['id']}: " . implode(', ', $failures) . "\n";
            }
        } catch (\Throwable $e) {
            Outbox::markRetry((string)$event['id'], (int)$event['attempts'], $e->getMessage());
            if ($verbose) echo "Deferred outbox event {$event['id']}: {$e->getMessage()}\n";
        }
    }
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

            $catMap = Products::categoryMap();
            foreach ($allCats as $cat) {
                $data = Products::byCategory($cat['id'], 1, $perPage, 'price_asc');
                $data['products'] = array_map(function($p) use ($catMap) {
                    return Products::enrich(I18n::product($p), $catMap);
                }, $data['products']);
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
                $catMap = Products::categoryMap();
                $data = Products::byCategory($cat['id'], $pageNum, $perPage, 'price_asc');
                $data['products'] = array_map(fn($p) => Products::enrich(I18n::product($p), $catMap), $data['products']);
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
    MailService::send($template, $to, $locale, $vars, $orderId !== null ? (int)$orderId : null, [], $providerCode);
}

function handleDigitalFulfill(array $payload, array $job): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId <= 0 || !DigitalDelivery::orderHasAssets($orderId)) return;

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
    $threshold = $payload['threshold'] ?? 10;
    $db = Db::get();

    $stmt = $db->prepare("SELECT sku, name, stock FROM products WHERE stock < :threshold AND status = 'active'");
    $stmt->execute([':threshold' => $threshold]);
    $lowStock = $stmt->fetchAll();

    if (empty($lowStock)) return;

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) mkdir($logDir, 0750, true);

    $msg = date('c') . " | LOW STOCK ALERT:\n";
    foreach ($lowStock as $p) {
        $msg .= "  {$p['sku']}: {$p['name']} — {$p['stock']} ks\n";
    }
    file_put_contents($logDir . '/stock-alerts.log', $msg, FILE_APPEND | LOCK_EX);
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
            Db::get()->exec("INSERT INTO products_fts(products_fts) VALUES('rebuild')");
        }
    } else {
        // MySQL — FULLTEXT indexes are maintained automatically, but we can
        // OPTIMIZE TABLE to rebuild if full reindex is requested
        if (!$productId) {
            Db::get()->exec("OPTIMIZE TABLE products");
        }
        // Single product: no action needed — MySQL FULLTEXT auto-updates on change
    }
}

function handlePaymentRefund(array $payload, array $job): void {
    $orderId = $payload['order_id'] ?? 0;
    $amount = $payload['amount'] ?? 0;
    $paymentId = $payload['payment_id'] ?? '';

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) mkdir($logDir, 0750, true);

    $result = ['status' => 'skipped'];
    if ($paymentId) {
        try {
            $order = $orderId ? Orders::byId((int)$orderId) : null;
            $paymentSnapshot = $order ? (json_decode((string)($order['payment_method_snapshot'] ?? ''), true) ?: []) : [];
            $providerCode = (string)($payload['_provider_code'] ?? ($paymentSnapshot['provider'] ?? ($order ? orderProviderCode($order, 'payment') : '')));
            if ($providerCode === '') $providerCode = Adapters::defaultProviderCode('payment');
            $payment = Adapters::payment($providerCode);
            $result = $payment->refund($paymentId, $amount);
        } catch (\Throwable $e) {
            $result = ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // Update order after successful refund
    if ($orderId && ($result['status'] ?? '') === 'succeeded') {
        $refundId = $result['refund_id'] ?? $result['id'] ?? '';
        if ($refundId) {
            Orders::setRefundId($orderId, $refundId);
        }
        // Transition to refunded if workflow allows
        $order = Orders::byId($orderId);
        if ($order && OrderWorkflow::canTransition($order['status'], 'refunded')) {
            OrderWorkflow::transition($orderId, 'refunded', "Payment refund completed: {$paymentId}");
        }
    }

    file_put_contents($logDir . '/refunds.log',
        date('c') . " | ORDER: {$orderId} | AMOUNT: {$amount} | PAYMENT: {$paymentId} | RESULT: " . json_encode($result) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function handleErpOrderPush(array $payload, array $job): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    $order = $orderId > 0 ? Orders::byId($orderId) : null;
    if (!$order) return;
    $providerCode = (string)($payload['_provider_code'] ?? orderProviderCode($order, 'erp'));
    $adapter = $providerCode !== '' ? Adapters::integration($providerCode) : Adapters::integration();
    if (!$adapter) return;
    Orders::markSynced($orderId, $adapter->pushOrder($order));
}

function handleErpOrderUpdate(array $payload, array $job): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    $order = $orderId > 0 ? Orders::byId($orderId) : null;
    if (!$order || empty($order['erp_id'])) return;
    $providerCode = (string)($payload['_provider_code'] ?? orderProviderCode($order, 'erp'));
    $adapter = $providerCode !== '' ? Adapters::integration($providerCode) : Adapters::integration();
    if (!$adapter) return;
    // IntegrationAdapter has no outbound status mutation contract yet; G5 adds it.
    error_log("ERP update queued via {$providerCode}: order #{$orderId} status=" . (string)($payload['status'] ?? ''));
}

function handleSitemapBuild(array $payload, array $job): void {
    $db = Db::get();
    $baseUrl = filter_var($payload['base_url'] ?? 'https://example.com', FILTER_VALIDATE_URL)
        ?: 'https://example.com';
    $safeBase = htmlspecialchars(rtrim($baseUrl, '/'), ENT_XML1 | ENT_QUOTES, 'UTF-8');

    // Load store prefixes for multi-locale URLs
    $stores = json_decode(file_get_contents(__DIR__ . '/../stores.json'), true);
    $prefixes = [''];  // default locale has no prefix
    foreach ($stores['stores'] ?? [] as $store) {
        $p = $store['url_prefix'] ?? '';
        if ($p !== '' && !in_array($p, $prefixes, true)) $prefixes[] = $p;
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    // Homepages per locale
    foreach ($prefixes as $prefix) {
        $safePrefix = htmlspecialchars($prefix, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml .= "<url><loc>{$safeBase}{$safePrefix}/</loc><priority>1.0</priority></url>\n";
    }

    // Build prefix→locale map for translated slugs
    $sysDefault = $stores['default_locale'] ?? 'en';
    $prefixLocale = ['' => $sysDefault];
    foreach ($stores['stores'] ?? [] as $store) {
        $p = $store['url_prefix'] ?? '';
        if ($p !== '') $prefixLocale[$p] = $store['default_locale'] ?? $store['locale'] ?? $sysDefault;
    }

    // Categories per locale (with translated slugs)
    $cats = $db->query("SELECT * FROM categories ORDER BY path")->fetchAll();
    foreach ($cats as $cat) {
        foreach ($prefixes as $prefix) {
            $safePrefix = htmlspecialchars($prefix, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            I18n::setLocale($prefixLocale[$prefix] ?? $sysDefault);
            $trCat = I18n::category($cat);
            $slug = htmlspecialchars($trCat['slug'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml .= "<url><loc>{$safeBase}{$safePrefix}/category/{$slug}</loc><priority>0.8</priority></url>\n";
        }
    }

    // Products per locale (with translated slugs)
    $stmt = $db->query("SELECT * FROM products WHERE status = 'active' ORDER BY updated_at DESC");
    $allProducts = $stmt->fetchAll();
    foreach ($allProducts as $p) {
        $lastmod = substr($p['updated_at'], 0, 10);
        foreach ($prefixes as $prefix) {
            $safePrefix = htmlspecialchars($prefix, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            I18n::setLocale($prefixLocale[$prefix] ?? $sysDefault);
            $trP = I18n::product($p);
            $slug = htmlspecialchars($trP['slug'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml .= "<url><loc>{$safeBase}{$safePrefix}/product/{$slug}</loc><lastmod>{$lastmod}</lastmod><priority>0.6</priority></url>\n";
        }
    }

    $xml .= "</urlset>\n";

    file_put_contents(__DIR__ . '/../sitemap.xml', $xml, LOCK_EX);
}

function handleCartAbandonment(array $payload, array $job): void {
    $db = Db::get();
    $hoursThreshold = max(1, (int)($payload['hours'] ?? 24));
    $now = Db::isSqlite() ? "datetime('now')" : "NOW()";
    $interval = Db::isSqlite()
        ? "datetime('now', '-{$hoursThreshold} hours')"
        : "DATE_SUB(NOW(), INTERVAL {$hoursThreshold} HOUR)";

    $stmt = $db->prepare("
        SELECT * FROM abandoned_carts
        WHERE customer_email IS NOT NULL AND customer_email != ''
          AND reminded_at IS NULL
          AND created_at < {$interval}
        LIMIT 50
    ");
    $stmt->execute();
    $carts = $stmt->fetchAll();

    $sent = 0;
    foreach ($carts as $cart) {
        // Skip if customer already placed an order with this email recently
        $orderCheck = $db->prepare("SELECT id FROM orders WHERE customer_email = :email AND created_at > :since LIMIT 1");
        $orderCheck->execute([':email' => $cart['customer_email'], ':since' => $cart['created_at']]);
        if ($orderCheck->fetch()) {
            // Customer completed order — remove abandoned cart
            $db->prepare("DELETE FROM abandoned_carts WHERE id = :id")->execute([':id' => $cart['id']]);
            continue;
        }

        JobQueue::push('email_send', [
            'template' => 'cart_reminder',
            'to'       => $cart['customer_email'],
            'vars'     => ['cart_url' => '/'],
        ], 8);

        $db->prepare("UPDATE abandoned_carts SET reminded_at = {$now} WHERE id = :id")
           ->execute([':id' => $cart['id']]);
        $sent++;
    }

    if ($sent > 0) {
        error_log("Cart abandonment: sent {$sent} reminder emails");
    }
}

function handleCleanup(array $payload, array $job): void {
    // Clean old processed events and replayable machine-API responses.
    Idempotency::cleanup(7);
    ApiAuth::cleanupIdempotency(30);

    // Clean old completed jobs (older than 7 days)
    $completedDir = (require __DIR__ . '/../config.php')['jobs_dir'] ?? dirname(__DIR__, 2) . '/bareboneseshop-private/jobs';
    $completedDir .= '/completed';
    if (is_dir($completedDir)) {
        $cutoff = time() - (7 * 86400);
        foreach (glob($completedDir . '/*.json') ?: [] as $file) {
            if (filemtime($file) < $cutoff) @unlink($file);
        }
    }
}

function handleImageResize(array $payload, array $job): void {
    $path = $payload['path'] ?? '';
    if (!$path) return;

    $storage = Adapters::storage();
    $storage->generateVariants($path);
}

function handleInvoiceIssue(array $payload, array $job): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    if (!$orderId) return;

    // Issue invoice (idempotent — returns existing invoice if already issued)
    $invoice = InvoiceService::issue($orderId);
    if (!$invoice) return;

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

    MailService::send(
        'invoice_issued',
        (string)$order['customer_email'],
        $locale,
        $vars,
        $orderId,
        $attachments,
        orderProviderCode($order, 'mail') ?: Adapters::defaultProviderCode('mail')
    );
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
