<?php
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
$jobsDir = $cfg['jobs_dir'] ?? __DIR__ . '/../jobs';
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
];

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

        if ($verbose) echo "  Processing: {$type} (ID: {$job['id']})\n";

        try {
            if ($handler && function_exists($handler)) {
                $handler($job['payload'] ?? [], $job);
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
    $locales = array_keys($locales) ?: ['sk'];

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
                $data = Products::byCategory($cat['id'], 1, $perPage, 'price_asc');
                $data['products'] = array_map(function($p) {
                    return Products::enrich(I18n::product($p));
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
    } elseif (!empty($payload['path'])) {
        // Single path rebuild
        Cache::invalidate($payload['path']);
    }
}

function handleEmailSend(array $payload, array $job): void {
    $template = $payload['template'] ?? '';
    $to = $payload['to'] ?? '';
    $orderId = $payload['order_id'] ?? null;

    // In production this would use an email adapter.
    // For now, log the email to a file.
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) mkdir($logDir, 0750, true);

    $logEntry = date('c') . " | TO: {$to} | TEMPLATE: {$template}";
    if ($orderId) {
        $order = Orders::byId((int)$orderId);
        if ($order) $logEntry .= " | ORDER: {$order['order_number']}";
    }
    $logEntry .= "\n";

    file_put_contents($logDir . '/emails.log', $logEntry, FILE_APPEND | LOCK_EX);
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
    if (!Db::isSqlite()) return; // FTS5 only

    $productId = $payload['product_id'] ?? null;
    if ($productId) {
        // Single product — FTS triggers handle this automatically on UPDATE
        // Just touch the product to trigger the FTS update trigger
        $now = Db::now();
        Db::get()->prepare("UPDATE products SET updated_at = {$now} WHERE id = :id")
            ->execute([':id' => $productId]);
    } else {
        // Full reindex — rebuild FTS
        Db::get()->exec("INSERT INTO products_fts(products_fts) VALUES('rebuild')");
    }
}

function handlePaymentRefund(array $payload, array $job): void {
    // In production: call Stripe API to refund
    $orderId = $payload['order_id'] ?? 0;
    $amount = $payload['amount'] ?? 0;
    $paymentId = $payload['payment_id'] ?? '';

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) mkdir($logDir, 0750, true);
    file_put_contents($logDir . '/refunds.log',
        date('c') . " | ORDER: {$orderId} | AMOUNT: {$amount} | PAYMENT: {$paymentId}\n",
        FILE_APPEND | LOCK_EX
    );
}

function handleErpOrderPush(array $payload, array $job): void {
    // Placeholder for ERP integration
    $orderId = $payload['order_id'] ?? 0;
    error_log("ERP push: order #{$orderId}");
}

function handleErpOrderUpdate(array $payload, array $job): void {
    $orderId = $payload['order_id'] ?? 0;
    $status = $payload['status'] ?? '';
    error_log("ERP update: order #{$orderId} status={$status}");
}

function handleSitemapBuild(array $payload, array $job): void {
    $db = Db::get();
    $baseUrl = $payload['base_url'] ?? 'https://example.com';

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    // Homepage
    $xml .= "<url><loc>{$baseUrl}/</loc><priority>1.0</priority></url>\n";

    // Categories
    $cats = $db->query("SELECT slug, path FROM categories ORDER BY path")->fetchAll();
    foreach ($cats as $cat) {
        $xml .= "<url><loc>{$baseUrl}/category/" . esc($cat['slug']) . "</loc><priority>0.8</priority></url>\n";
    }

    // Products
    $stmt = $db->query("SELECT slug, updated_at FROM products WHERE status = 'active' ORDER BY updated_at DESC");
    while ($p = $stmt->fetch()) {
        $lastmod = substr($p['updated_at'], 0, 10);
        $xml .= "<url><loc>{$baseUrl}/product/" . esc($p['slug']) . "</loc><lastmod>{$lastmod}</lastmod><priority>0.6</priority></url>\n";
    }

    $xml .= "</urlset>\n";

    file_put_contents(__DIR__ . '/../sitemap.xml', $xml, LOCK_EX);
}

function handleCartAbandonment(array $payload, array $job): void {
    // Placeholder: would scan sessions/carts older than 24h with email set
    error_log("Cart abandonment check triggered");
}

function handleCleanup(array $payload, array $job): void {
    // Clean old processed events
    Idempotency::cleanup(7);

    // Clean old completed jobs (older than 7 days)
    $completedDir = (require __DIR__ . '/../config.php')['jobs_dir'] ?? __DIR__ . '/../jobs';
    $completedDir .= '/completed';
    if (is_dir($completedDir)) {
        $cutoff = time() - (7 * 86400);
        foreach (glob($completedDir . '/*.json') ?: [] as $file) {
            if (filemtime($file) < $cutoff) @unlink($file);
        }
    }
}
