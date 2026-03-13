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
    'image_resize'      => 'handleImageResize',
    'import_process'    => 'handleImportProcess',
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
    } elseif (!empty($payload['path'])) {
        // Single path rebuild — directly touch stale, do NOT call Cache::invalidate()
        // which would re-queue another cache_rebuild job creating an infinite loop
        $file = $cfg['cache_dir'] . '/' . $payload['path'];
        if (file_exists($file)) {
            touch($file, 1); // set mtime to epoch → stale on next read
        }
    }
}

function handleEmailSend(array $payload, array $job): void {
    $template = $payload['template'] ?? '';
    $to = $payload['to'] ?? '';
    $orderId = $payload['order_id'] ?? null;

    if (!$to || !$template) return;

    $vars = $payload['vars'] ?? [];
    if ($orderId) {
        $order = Orders::byId((int)$orderId);
        if ($order) {
            $vars = array_merge($vars, [
                'order_number' => $order['order_number'],
                'order_status' => $order['status'],
                'order_total' => I18n::price((int)$order['total']),
                'customer_name' => $order['customer_name'],
                'customer_email' => $order['customer_email'],
                'payment_method' => $order['payment_method'] ?? '',
            ]);
            // Include order items for {{#each items}} template blocks
            $items = Orders::items((int)$orderId);
            $vars['items'] = array_map(fn($it) => [
                'name'       => $it['name'],
                'sku'        => $it['sku'],
                'quantity'   => $it['quantity'],
                'unit_price' => I18n::price((int)$it['unit_price']),
                'total'      => I18n::price((int)$it['total']),
            ], $items);
        }
    }

    // Render template if it exists
    $templateFile = __DIR__ . '/../templates/' . $template . '.html';
    $body = '';
    $subject = $template;
    if (file_exists($templateFile)) {
        $body = file_get_contents($templateFile);
        $body = renderTemplate($body, $vars);
        // Extract subject from <title> if present
        if (preg_match('/<title>(.*?)<\/title>/i', $body, $m)) {
            $subject = $m[1];
        }
    } else {
        $body = "Template: {$template}\n" . json_encode($vars, JSON_PRETTY_PRINT);
    }

    // Attempt to send via mail() if available
    $sent = false;
    if (function_exists('mail')) {
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: noreply@" . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n";
        $sent = @mail($to, $subject, $body, $headers);
    }

    // Always log
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) mkdir($logDir, 0750, true);
    $status = $sent ? 'SENT' : 'LOGGED';
    $logEntry = date('c') . " | {$status} | TO: {$to} | SUBJECT: {$subject}";
    if ($orderId) $logEntry .= " | ORDER_ID: {$orderId}";
    $logEntry .= "\n";
    file_put_contents($logDir . '/emails.log', $logEntry, FILE_APPEND | LOCK_EX);
}

/** Simple template engine: {{var}}, {{#if var}}...{{/if}}, {{#each items}}...{{/each}} */
function renderTemplate(string $tpl, array $vars): string {
    // Process {{#each key}}...{{/each}} blocks
    $tpl = preg_replace_callback('/\{\{#each\s+(\w+)\}\}(.*?)\{\{\/each\}\}/s', function($m) use ($vars) {
        $key = $m[1];
        $inner = $m[2];
        $items = $vars[$key] ?? [];
        if (!is_array($items)) return '';
        $out = '';
        foreach ($items as $item) {
            $row = $inner;
            if (is_array($item)) {
                foreach ($item as $k => $v) {
                    $row = str_replace('{{' . $k . '}}', htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $row);
                }
            } else {
                $row = str_replace('{{this}}', htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8'), $row);
            }
            $out .= $row;
        }
        return $out;
    }, $tpl);

    // Process {{#if key}}...{{/if}} blocks
    $tpl = preg_replace_callback('/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s', function($m) use ($vars) {
        return !empty($vars[$m[1]]) ? $m[2] : '';
    }, $tpl);

    // Simple variable substitution
    foreach ($vars as $k => $v) {
        if (is_scalar($v)) {
            $tpl = str_replace('{{' . $k . '}}', htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $tpl);
        }
    }
    // Clean up any remaining unresolved placeholders
    $tpl = preg_replace('/\{\{[^}]+\}\}/', '', $tpl);

    return $tpl;
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
            $payment = Adapters::payment();
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
    $baseUrl = filter_var($payload['base_url'] ?? 'https://example.com', FILTER_VALIDATE_URL)
        ?: 'https://example.com';
    $safeBase = htmlspecialchars(rtrim($baseUrl, '/'), ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    // Homepage
    $xml .= "<url><loc>{$safeBase}/</loc><priority>1.0</priority></url>\n";

    // Categories
    $cats = $db->query("SELECT slug, path FROM categories ORDER BY path")->fetchAll();
    foreach ($cats as $cat) {
        $slug = htmlspecialchars($cat['slug'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml .= "<url><loc>{$safeBase}/category/{$slug}</loc><priority>0.8</priority></url>\n";
    }

    // Products
    $stmt = $db->query("SELECT slug, updated_at FROM products WHERE status = 'active' ORDER BY updated_at DESC");
    while ($p = $stmt->fetch()) {
        $slug = htmlspecialchars($p['slug'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $lastmod = substr($p['updated_at'], 0, 10);
        $xml .= "<url><loc>{$safeBase}/product/{$slug}</loc><lastmod>{$lastmod}</lastmod><priority>0.6</priority></url>\n";
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

function handleImageResize(array $payload, array $job): void {
    $path = $payload['path'] ?? '';
    if (!$path) return;

    $storage = Adapters::storage();
    $storage->generateVariants($path);
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

    // Clean up temp file
    @unlink($file);
}
