<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Reproducible scale, concurrency, and failure benchmark.
 *
 * Run only from a disposable checkout without config.php:
 *   php cli/benchmark.php --prepare --dataset=100000 --iterations=100 --concurrency=4
 * Optional 1M profile:
 *   php cli/benchmark.php --prepare --dataset=1000000 --iterations=100 --concurrency=8
 */

$options = getopt('', [
    'prepare', 'reuse', 'runtime:', 'dataset:', 'iterations:', 'concurrency:', 'report:',
    'worker:', 'worker-index:',
]);
$root = dirname(__DIR__);
$configPath = $root . '/config.php';
$dataset = max(100, (int)($options['dataset'] ?? 100000));
$iterations = max(20, min(1000, (int)($options['iterations'] ?? 100)));
$concurrency = max(1, min(32, (int)($options['concurrency'] ?? 4)));
$runtime = (string)($options['runtime'] ?? (sys_get_temp_dir() . '/barebones-benchmark-' . substr(hash('sha256', $root), 0, 12)));

function benchmarkAbsolutePath(string $path): bool
{
    return str_starts_with($path, '/') || str_starts_with($path, '\\\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
}

function benchmarkContainedPath(string $path, string $root): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || str_starts_with($path, $root . '/');
}

function benchmarkEnsureDir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException("Cannot create benchmark directory: {$path}");
    }
}

function benchmarkPrepare(string $root, string $configPath, string $runtime): void
{
    if (is_file($configPath)) {
        throw new RuntimeException('Refusing --prepare because config.php already exists; use a fresh disposable checkout');
    }
    if (!benchmarkAbsolutePath($runtime) || !str_contains(strtolower(str_replace('\\', '/', $runtime)), 'barebones-benchmark')) {
        throw new RuntimeException('Benchmark runtime must be an absolute disposable path containing barebones-benchmark');
    }
    foreach (['db', 'cache', 'jobs', 'invoices', 'backups', 'logs', 'updates', 'secrets', 'reports'] as $directory) {
        benchmarkEnsureDir($runtime . '/' . $directory);
    }
    $marker = [
        'purpose' => 'barebones-benchmark',
        'root' => realpath($root) ?: $root,
        'created_at' => gmdate('c'),
    ];
    file_put_contents($runtime . '/.barebones-benchmark.json', json_encode($marker, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);

    $config = require $root . '/config.example.php';
    $config['db_driver'] = 'sqlite';
    $config['private_runtime_dir'] = $runtime;
    $config['db_sqlite_path'] = $runtime . '/db/shop.sqlite';
    $config['benchmark_query_count'] = true;
    $config['benchmark_fixture_version'] = 'g4-category-v2';
    $config['private_cache_dir'] = $runtime . '/cache';
    $config['jobs_dir'] = $runtime . '/jobs';
    $config['invoice_storage_dir'] = $runtime . '/invoices';
    $config['backup_storage_dir'] = $runtime . '/backups';
    $config['backup_log_file'] = $runtime . '/logs/backup.log';
    $config['structured_log_file'] = $runtime . '/logs/application.jsonl';
    $config['update_storage_dir'] = $runtime . '/updates';
    $config['gdpr_salt_file'] = $runtime . '/secrets/gdpr_salt';
    $config['app_url'] = 'http://127.0.0.1';
    $config['admin_identity_key'] = base64_encode(str_repeat('B', 32));
    $config['adapters']['payment'] = 'MockPayment';
    $config['provider_defaults']['payment'] = 'mock';
    $config['provider_defaults']['mail'] = 'log';
    $config['allow_mock_webhook'] = true;
    $config['mock_webhook_secret'] = 'barebones-benchmark-webhook-secret';
    $config['mock_result'] = 'success';
    $config['queue'] = ['backend' => 'database', 'lease_seconds' => 30];

    $php = "<?php\n// Generated only for a disposable qualification benchmark checkout.\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($configPath, $php, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write disposable benchmark config.php');
    }
}

if (isset($options['prepare'])) {
    benchmarkPrepare($root, $configPath, $runtime);
}
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing config.php. Run this command with --prepare from a fresh disposable checkout.\n");
    exit(1);
}

$config = require $configPath;
$configRuntime = (string)($config['private_runtime_dir'] ?? '');
$dbPath = (string)($config['db_sqlite_path'] ?? '');
$markerPath = $configRuntime . '/.barebones-benchmark.json';
if (($config['db_driver'] ?? '') !== 'sqlite'
    || ($config['benchmark_fixture_version'] ?? '') !== 'g4-category-v2'
    || !benchmarkAbsolutePath($configRuntime)
    || !str_contains(strtolower(str_replace('\\', '/', $configRuntime)), 'barebones-benchmark')
    || !benchmarkContainedPath($dbPath, $configRuntime)
    || !is_file($markerPath)) {
    fwrite(STDERR, "Refusing to benchmark a non-disposable runtime. Use --prepare in a fresh checkout.\n");
    exit(1);
}
$marker = json_decode((string)file_get_contents($markerPath), true);
if (($marker['purpose'] ?? '') !== 'barebones-benchmark') {
    fwrite(STDERR, "Invalid benchmark runtime marker.\n");
    exit(1);
}
$runtime = $configRuntime;

require_once $root . '/src/autoload.php';
require_once $root . '/src/Queue.php';
I18n::setLocale('sk');

function benchmarkPercentile(array $values, float $percentile): float
{
    if ($values === []) return 0.0;
    sort($values, SORT_NUMERIC);
    $index = (int)ceil($percentile * count($values)) - 1;
    return (float)$values[max(0, min(count($values) - 1, $index))];
}

function benchmarkMeasure(string $name, callable $operation, int $iterations): array
{
    for ($i = 0; $i < 5; $i++) $operation();
    $samples = [];
    $queryCounts = [];
    for ($i = 0; $i < $iterations; $i++) {
        Db::resetQueryCount();
        $start = hrtime(true);
        $operation();
        $samples[] = (hrtime(true) - $start) / 1_000_000;
        if (Db::queryCount() !== null) $queryCounts[] = Db::queryCount();
    }
    return [
        'name' => $name,
        'iterations' => $iterations,
        'unit' => 'ms',
        'p50' => benchmarkPercentile($samples, 0.50),
        'p95' => benchmarkPercentile($samples, 0.95),
        'p99' => benchmarkPercentile($samples, 0.99),
        'min' => min($samples),
        'max' => max($samples),
        'mean' => array_sum($samples) / count($samples),
        'query_count' => $queryCounts === [] ? null : [
            'p50' => (int)benchmarkPercentile($queryCounts, 0.50),
            'p95' => (int)benchmarkPercentile($queryCounts, 0.95),
            'p99' => (int)benchmarkPercentile($queryCounts, 0.99),
            'min' => min($queryCounts),
            'max' => max($queryCounts),
        ],
    ];
}

function benchmarkStartHttpServer(string $root, string $runtime): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The reproducible HTTP TTFB profile requires ext-curl');
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) throw new RuntimeException("Cannot reserve local benchmark port: {$errorMessage}");
    $address = (string)stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr(strrchr($address, ':'), 1);
    if ($port <= 0) throw new RuntimeException('Cannot determine local benchmark port');

    $stdout = $runtime . '/logs/http-server.out.log';
    $stderr = $runtime . '/logs/http-server.err.log';
    $queryLog = $runtime . '/logs/http-query-count.jsonl';
    $prepend = $runtime . '/http-query-count.php';
    file_put_contents($prepend, <<<'PHP'
<?php
register_shutdown_function(static function (): void {
    $count = class_exists('Db', false) ? Db::queryCount() : null;
    $log = getenv('BBE_BENCHMARK_QUERY_LOG');
    if ($count !== null && is_string($log) && $log !== '') {
        file_put_contents($log, json_encode([
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'query_count' => $count,
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }
});
PHP, LOCK_EX);
    @unlink($queryLog);
    putenv('BBE_BENCHMARK_QUERY_LOG=' . $queryLog);
    $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-S', "127.0.0.1:{$port}", '-t', $root], [
        0 => ['pipe', 'r'], 1 => ['file', $stdout, 'ab'], 2 => ['file', $stderr, 'ab'],
    ], $pipes, $root);
    putenv('BBE_BENCHMARK_QUERY_LOG');
    if (!is_resource($process)) throw new RuntimeException('Cannot start local PHP benchmark server');
    fclose($pipes[0]);
    $baseUrl = "http://127.0.0.1:{$port}";
    $deadline = microtime(true) + 10.0;
    do {
        $curl = curl_init($baseUrl . '/index.php');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 500, CURLOPT_TIMEOUT_MS => 2000]);
        curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);
        if ($status === 200) return [$process, $baseUrl, $queryLog];
        usleep(100000);
    } while (microtime(true) < $deadline);

    proc_terminate($process);
    proc_close($process);
    $error = is_file($stderr) ? trim((string)file_get_contents($stderr)) : '';
    throw new RuntimeException('Local benchmark server did not become ready: ' . substr($error, -2000));
}

function benchmarkHttpQueryCount(string $queryLog, string $requestId): int
{
    $deadline = microtime(true) + 2.0;
    do {
        clearstatcache(true, $queryLog);
        if (is_file($queryLog)) {
            foreach (file($queryLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $record = json_decode($line, true);
                if (str_contains((string)($record['request_uri'] ?? ''), '_benchmark_request=' . $requestId)) {
                    return (int)$record['query_count'];
                }
            }
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException("Missing HTTP query count for request {$requestId}");
}

function benchmarkClearCacheFiles(string $cacheDir): void
{
    if (!is_dir($cacheDir)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if ($file->isFile()) @unlink($file->getPathname());
    }
}

function benchmarkHttpProfile(
    string $name,
    string $url,
    int $iterations,
    string $queryLog,
    ?string $cookie = null,
    ?callable $beforeEach = null,
    int $warmups = 5,
    ?callable $validateBody = null
): array {
    $request = static function () use ($url, $queryLog, $cookie, $beforeEach, $validateBody): array {
        if ($beforeEach !== null) $beforeEach();
        $requestId = bin2hex(random_bytes(8));
        $requestUrl = $url . (str_contains($url, '?') ? '&' : '?') . '_benchmark_request=' . $requestId;
        $headers = ['Accept: text/html'];
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
        $curl = curl_init($requestUrl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 30000,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $ttfb = (float)curl_getinfo($curl, CURLINFO_STARTTRANSFER_TIME) * 1000;
        $total = (float)curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000;
        $error = curl_error($curl);
        unset($curl);
        if ($body === false || $status !== 200) {
            throw new RuntimeException("HTTP benchmark request failed ({$status}): {$error}");
        }
        if ($validateBody !== null && !$validateBody($body)) {
            throw new RuntimeException("HTTP benchmark response contract failed for {$requestUrl}");
        }
        return [$total, $ttfb, benchmarkHttpQueryCount($queryLog, $requestId)];
    };
    for ($i = 0; $i < $warmups; $i++) $request();
    $samples = [];
    $ttfbSamples = [];
    $queryCounts = [];
    for ($i = 0; $i < $iterations; $i++) {
        [$total, $ttfb, $queryCount] = $request();
        $samples[] = $total;
        $ttfbSamples[] = $ttfb;
        $queryCounts[] = $queryCount;
    }
    return [
        'name' => $name, 'iterations' => $iterations, 'unit' => 'ms',
        'p50' => benchmarkPercentile($samples, 0.50),
        'p95' => benchmarkPercentile($samples, 0.95),
        'p99' => benchmarkPercentile($samples, 0.99),
        'min' => min($samples), 'max' => max($samples),
        'mean' => array_sum($samples) / count($samples),
        'ttfb' => [
            'p50' => benchmarkPercentile($ttfbSamples, 0.50),
            'p95' => benchmarkPercentile($ttfbSamples, 0.95),
            'p99' => benchmarkPercentile($ttfbSamples, 0.99),
        ],
        'query_count' => [
            'p50' => (int)benchmarkPercentile($queryCounts, 0.50),
            'p95' => (int)benchmarkPercentile($queryCounts, 0.95),
            'p99' => (int)benchmarkPercentile($queryCounts, 0.99),
            'min' => min($queryCounts),
            'max' => max($queryCounts),
        ],
    ];
}

function benchmarkSeed(PDO $db, int $count, int $concurrency, bool $reuse): float
{
    $existing = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($existing !== 0) {
        if ($reuse && $existing === $count) {
            $categories = (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn();
            $specifications = (int)$db->query('SELECT COUNT(*) FROM product_specifications')->fetchColumn();
            if ($categories === 2020 && $specifications === $count * 4) return 0.0;
            throw new RuntimeException("Reusable dataset does not match the category/specification fixture contract ({$categories} categories, {$specifications} specifications)");
        }
        throw new RuntimeException("Disposable dataset already contains {$existing} products; use a new runtime or explicit --reuse with the exact count");
    }

    $started = microtime(true);
    $category = $db->prepare("INSERT INTO categories (slug, name, path, depth, sort_order, product_count, translations, meta) VALUES (?, ?, ?, 0, ?, 0, '{}', '{}')");
    for ($i = 0; $i < 20; $i++) {
        $category->execute(["benchmark-category-{$i}", "Benchmark Category {$i}", "benchmark-category-{$i}", $i]);
    }
    $categoryIds = array_map('intval', $db->query("SELECT id FROM categories WHERE slug LIKE 'benchmark-category-%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

    $treeCategory = $db->prepare("INSERT INTO categories (slug, name, parent_id, path, depth, sort_order, product_count, translations, meta) VALUES (?, ?, ?, ?, ?, ?, 0, '{}', '{}')");
    $treeIds = [];
    $treePaths = [];
    $treeDepths = [];
    for ($i = 0; $i < 2000; $i++) {
        $parentIndex = $i === 0 ? null : intdiv($i - 1, 8);
        $parentId = $parentIndex === null ? null : $treeIds[$parentIndex];
        $depth = $parentIndex === null ? 0 : $treeDepths[$parentIndex] + 1;
        $slug = "benchmark-tree-{$i}";
        $path = $parentIndex === null ? $slug : $treePaths[$parentIndex] . '/' . $slug;
        $treeCategory->execute([$slug, "Benchmark Tree {$i}", $parentId, $path, $depth, $i]);
        $treeIds[$i] = (int)$db->lastInsertId();
        $treePaths[$i] = $path;
        $treeDepths[$i] = $depth;
    }

    $product = $db->prepare("INSERT INTO products
        (sku, slug, product_type, name, category_id, price, compare_price, currency, stock, status,
         images, summary, description, attrs, tags, filter_tags, weight_g, translations, inventory_policy, store_id)
        VALUES (?, ?, 'simple', ?, ?, ?, NULL, 'eur', 1000, 'active', '[]', ?, '', ?, ?, ?, ?, '{}', 'deny', 'default')");
    $attribute = $db->prepare('INSERT INTO product_attrs (product_id, attr_name, attr_value, numeric_val) VALUES (?, ?, ?, NULL)');
    $specification = $db->prepare("INSERT INTO product_specifications (product_id, lang, criteria_name, criteria_value, numeric_val, sort_order) VALUES (?, 'sk', ?, ?, ?, ?)");

    $db->beginTransaction();
    for ($i = 1; $i <= $count; $i++) {
        $categoryId = $categoryIds[($i - 1) % count($categoryIds)];
        $facetIndex = intdiv($i - 1, count($categoryIds));
        $color = ['black', 'white', 'red', 'blue'][$facetIndex % 4];
        $size = ['s', 'm', 'l', 'xl'][intdiv($facetIndex, 4) % 4];
        $sku = 'BENCH-' . str_pad((string)$i, 8, '0', STR_PAD_LEFT);
        $slug = 'benchmark-product-' . $i;
        $name = 'Benchmark Product ' . $i . ' premium';
        $attrs = json_encode(['color' => $color, 'size' => $size], JSON_THROW_ON_ERROR);
        $tags = json_encode(['benchmark', 'premium'], JSON_THROW_ON_ERROR);
        $product->execute([$sku, $slug, $name, $categoryId, 1000 + ($i % 90000), $name, $attrs, $tags, "attr_color_{$color} attr_size_{$size}", 100 + ($i % 5000)]);
        $productId = (int)$db->lastInsertId();
        $attribute->execute([$productId, 'color', $color]);
        $attribute->execute([$productId, 'size', $size]);
        $specification->execute([$productId, 'Brand', 'Brand ' . ($facetIndex % 10), null, 1]);
        $specification->execute([$productId, 'Material', ['steel', 'aluminium', 'ceramic', 'rubber', 'plastic'][intdiv($facetIndex, 10) % 5], null, 2]);
        $specification->execute([$productId, 'Mounting side', intdiv($facetIndex, 50) % 2 === 0 ? 'front' : 'rear', null, 3]);
        $diameter = 200 + ($facetIndex % 40);
        $specification->execute([$productId, 'Diameter (mm)', (string)$diameter, $diameter, 4]);
        if ($i % 1000 === 0) {
            $db->commit();
            $db->beginTransaction();
        }
    }
    if ($db->inTransaction()) $db->commit();
    $db->exec("UPDATE categories SET product_count = (SELECT COUNT(*) FROM products WHERE products.category_id = categories.id AND products.status = 'active')");

    $warehouseCount = max(20, $concurrency * 4);
    $db->prepare("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('benchmark', 'Benchmark Warehouse', 1, 1, 'default')")->execute();
    $warehouseId = (int)$db->lastInsertId();
    $stock = $db->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved, store_id) VALUES (?, ?, 1000, 0, 'default')");
    for ($i = 1; $i <= $warehouseCount; $i++) {
        $stock->execute([$warehouseId, 'BENCH-' . str_pad((string)$i, 8, '0', STR_PAD_LEFT)]);
    }
    return microtime(true) - $started;
}

function benchmarkCustomerSession(PDO $db): string
{
    $email = 'benchmark-customer@example.invalid';
    $existing = $db->prepare('SELECT id, password_hash FROM customers WHERE email = ? AND store_id = ?');
    $existing->execute([$email, 'default']);
    $customer = $existing->fetch(PDO::FETCH_ASSOC);
    if ($customer) {
        $customerId = (int)$customer['id'];
        $passwordHash = (string)$customer['password_hash'];
    } else {
        $passwordHash = password_hash('Benchmark-password-1!', PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO customers (email, password_hash, name, locale, status, store_id) VALUES (?, ?, 'Benchmark Customer', 'sk', 'active', 'default')");
        $stmt->execute([$email, $passwordHash]);
        $customerId = (int)$db->lastInsertId();
    }
    $sessionId = 'bbe-benchmark-' . bin2hex(random_bytes(8));
    session_id($sessionId);
    session_start();
    $_SESSION = [
        'customer_id' => $customerId,
        'customer_email' => $email,
        'customer_auth_hash' => hash('sha256', $passwordHash),
        'customer_last_seen' => time(),
        'cart' => [],
    ];
    session_write_close();
    return session_name() . '=' . $sessionId;
}

function benchmarkWorkerCheckout(int $index): never
{
    $db = Db::get();
    $stmt = $db->prepare("SELECT id, sku, name, price FROM products WHERE sku = :sku AND store_id = 'default'");
    $stmt->execute([':sku' => 'BENCH-' . str_pad((string)($index + 1), 8, '0', STR_PAD_LEFT)]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) throw new RuntimeException('Checkout worker product missing');
    $orderId = Orders::create([[
        'product_id' => (int)$product['id'], 'sku' => (string)$product['sku'],
        'name' => (string)$product['name'], 'price' => (int)$product['price'], 'qty' => 1,
        'attrs' => [], 'vat_class' => 'standard',
    ]], [
        'name' => 'Benchmark Customer', 'email' => "benchmark-{$index}@example.invalid",
        'address' => ['country' => 'SK'], 'currency' => 'eur', 'locale' => 'sk',
    ], 'cod');
    fwrite(STDOUT, json_encode(['order_id' => $orderId], JSON_THROW_ON_ERROR));
    exit(0);
}

if (($options['worker'] ?? '') === 'checkout') {
    benchmarkWorkerCheckout(max(0, (int)($options['worker-index'] ?? 0)));
}

$db = Db::get();
$migrator = new Migrator($db, $root . '/migrations', $runtime . '/.migrations.lock');
$applied = $migrator->apply();
$reusedDataset = isset($options['reuse']);
$seedSeconds = benchmarkSeed($db, $dataset, $concurrency, $reusedDataset);

function benchmarkConcurrentCheckouts(string $script, string $runtime, int $concurrency, int $workerOffset = 0): array
{
    $processes = [];
    $started = hrtime(true);
    for ($i = 0; $i < $concurrency; $i++) {
        $workerIndex = $i + $workerOffset;
        $token = getmypid() . '-' . $workerIndex;
        $stdoutPath = $runtime . "/logs/checkout-worker-{$token}.out.log";
        $stderrPath = $runtime . "/logs/checkout-worker-{$token}.err.log";
        $pipes = [];
        $process = proc_open([PHP_BINARY, $script, '--worker=checkout', "--worker-index={$workerIndex}"], [
            0 => ['pipe', 'r'], 1 => ['file', $stdoutPath, 'wb'], 2 => ['file', $stderrPath, 'wb'],
        ], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start checkout worker');
        fclose($pipes[0]);
        $processes[] = [$process, $workerIndex, $stdoutPath, $stderrPath];
        if ($i + 1 < $concurrency) usleep(25000);
    }
    $exitCodes = [];
    do {
        $running = false;
        foreach ($processes as [$process, $index]) {
            if (isset($exitCodes[$index])) continue;
            $status = proc_get_status($process);
            if ($status['running']) {
                $running = true;
            } else {
                $exitCodes[$index] = (int)$status['exitcode'];
            }
        }
        if ($running) usleep(10000);
    } while ($running);

    $failures = [];
    foreach ($processes as [$process, $index, $stdoutPath, $stderrPath]) {
        $closed = proc_close($process);
        $exit = $exitCodes[$index] >= 0 ? $exitCodes[$index] : $closed;
        $stdout = is_file($stdoutPath) ? (string)file_get_contents($stdoutPath) : '';
        $stderr = is_file($stderrPath) ? (string)file_get_contents($stderrPath) : '';
        @unlink($stdoutPath);
        @unlink($stderrPath);
        if ($exit !== 0) $failures[] = ['worker' => $index, 'exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }
    return [
        'workers' => $concurrency,
        'elapsed_ms' => (hrtime(true) - $started) / 1_000_000,
        'successful' => $concurrency - count($failures),
        'failures' => $failures,
    ];
}

function benchmarkFailureChecks(PDO $db, string $runtime): array
{
    $results = [];

    $timeoutAdapter = new class implements PaymentAdapter {
        public function createSession(array $order): array { throw new RuntimeException('injected payment timeout'); }
        public function handleWebhook(array $payload): array { return []; }
        public function refund(string $paymentId, int $amount): array { return []; }
        public function getStatus(string $paymentId): string { return 'unknown'; }
        public function verifySignature(string $payload, string $sigHeader): bool { return true; }
    };
    $order = $db->query("SELECT * FROM orders ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $beforeTransactions = (int)$db->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn();
    try {
        PaymentService::initiate([
            'order_id' => (int)$order['id'], 'order_number' => (string)$order['order_number'],
            'total' => (int)$order['total'], 'currency' => (string)$order['currency'],
        ], $timeoutAdapter, 'timeout-fixture');
        throw new RuntimeException('Payment timeout did not fail');
    } catch (RuntimeException $e) {
        $afterTransactions = (int)$db->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn();
        $results['payment_timeout'] = [
            'passed' => $e->getMessage() === 'injected payment timeout' && $afterTransactions === $beforeTransactions,
            'invariant' => 'no initiated transaction is recorded when session creation times out',
        ];
    }

    $gateway = new MockPayment(['mock_result' => 'success', 'mock_webhook_secret' => 'barebones-benchmark-webhook-secret']);
    $payload = MockPayment::paidPayload((int)$order['id'], (int)$order['total'], 'benchmark-session');
    $payload['event_id'] = 'benchmark-duplicate-event-' . $order['id'] . '-' . $beforeTransactions;
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $raw, 'barebones-benchmark-webhook-secret');
    $first = PaymentService::handleWebhook($raw, $signature, $payload, $gateway, 'mock');
    $second = PaymentService::handleWebhook($raw, $signature, $payload, $gateway, 'mock');
    $results['duplicate_webhook'] = [
        'passed' => ($first['handled'] ?? false) === true && ($second['duplicate'] ?? false) === true,
        'invariant' => 'the same provider event changes payment state at most once',
    ];

    $queue = new DatabaseQueue($db, 30);
    $jobId = $queue->push('benchmark.expired_lease', ['fixture' => true]);
    $job = null;
    foreach ($queue->pull(100) as $candidate) {
        if (($candidate['id'] ?? '') === $jobId) $job = $candidate;
    }
    $oldClaim = $job ? $queue->markProcessing($job) : '';
    $db->prepare("UPDATE queue_jobs SET claimed_at = :stale WHERE id = :id")->execute([
        ':stale' => gmdate('Y-m-d H:i:s', time() - 60), ':id' => $jobId,
    ]);
    $reclaimed = $queue->pull(10);
    $reclaimedJob = null;
    foreach ($reclaimed as $candidate) if (($candidate['id'] ?? '') === $jobId) $reclaimedJob = $candidate;
    $newClaim = $reclaimedJob ? $queue->markProcessing($reclaimedJob) : '';
    $oldRejected = false;
    try { $queue->complete($oldClaim); } catch (RuntimeException $e) { $oldRejected = true; }
    if ($newClaim !== '') $queue->complete($newClaim);
    $results['expired_queue_lease'] = [
        'passed' => $oldClaim !== '' && $newClaim !== '' && $oldClaim !== $newClaim && $oldRejected,
        'invariant' => 'an expired job is reclaimable and the stale owner cannot complete it',
        'diagnostic' => [
            'old_claimed' => $oldClaim !== '', 'reappeared' => $reclaimedJob !== null,
            'new_claimed' => $newClaim !== '', 'old_claim_rejected' => $oldRejected,
        ],
    ];

    MailService::useAdapter(new class implements MailAdapter {
        public function send(array $msg): array { return ['status' => 'failed', 'message' => 'injected mail outage', 'raw' => null]; }
        public function adapterId(): string { return 'failure-fixture'; }
    });
    $mailFixture = (int)$db->query('SELECT COUNT(*) FROM email_log')->fetchColumn();
    $mail = MailService::send('order_confirmation', "failure-benchmark-{$mailFixture}@example.invalid", 'en', ['order_number' => $order['order_number']], (int)$order['id']);
    MailService::useAdapter(null);
    $results['mail_outage'] = [
        'passed' => ($mail['status'] ?? '') === 'failed' && ($mail['error'] ?? '') === 'injected mail outage',
        'invariant' => 'mail outage is persisted as failed instead of reported as sent',
    ];

    ShippingService::setAdapters([new class implements ShippingAdapter {
        public function getRates(array $params): array { throw new RuntimeException('injected carrier outage'); }
        public function generateLabel(array $order): array { throw new RuntimeException('injected carrier outage'); }
        public function trackShipment(string $awb): array { throw new RuntimeException('injected carrier outage'); }
        public function supportsCOD(): bool { return false; }
        public function carrierId(): string { return 'failure-fixture'; }
    }]);
    $labelsBefore = (int)$db->query('SELECT COUNT(*) FROM shipping_labels')->fetchColumn();
    $carrierFailed = false;
    try { ShippingService::generateLabel((int)$order['id'], 'failure-fixture'); } catch (RuntimeException $e) { $carrierFailed = $e->getMessage() === 'injected carrier outage'; }
    $labelsAfter = (int)$db->query('SELECT COUNT(*) FROM shipping_labels')->fetchColumn();
    ShippingService::setAdapters([]);
    $results['carrier_outage'] = [
        'passed' => $carrierFailed && $labelsAfter === $labelsBefore,
        'invariant' => 'carrier outage creates no phantom shipping label',
    ];

    $failureRoot = $runtime . '/backup-failure-fixture';
    benchmarkEnsureDir($failureRoot);
    $yearBlocker = $failureRoot . '/' . gmdate('Y');
    if (!file_exists($yearBlocker)) file_put_contents($yearBlocker, 'not-a-directory', LOCK_EX);
    BackupService::reset();
    $backupFailed = false;
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        BackupService::init($db, [
            'enabled' => true, 'storage_dir' => $failureRoot,
            'log_file' => $runtime . '/logs/backup-failure.log',
            'wal_checkpoint' => false, 'auto_gc_on_backup' => false,
        ], dirname(__DIR__));
        BackupService::create(['label' => 'failure-injection']);
    } catch (Throwable $e) {
        $backupFailed = true;
    } finally {
        restore_error_handler();
        BackupService::reset();
    }
    $partialBackups = glob($failureRoot . '/*/*/*/*.db') ?: [];
    $results['backup_write_failure'] = [
        'passed' => $backupFailed && $partialBackups === [],
        'invariant' => 'unwritable backup destination fails closed without a partial backup',
    ];

    foreach ($results as $name => $result) {
        if (!$result['passed']) throw new RuntimeException("Failure injection failed: {$name} " . json_encode($result, JSON_UNESCAPED_SLASHES));
    }
    return $results;
}

$categoryId = (int)$db->query("SELECT id FROM categories WHERE slug LIKE 'benchmark-category-%' ORDER BY id LIMIT 1")->fetchColumn();
$productId = (int)$db->query("SELECT id FROM products WHERE sku = 'BENCH-00050000' OR sku = 'BENCH-00000001' ORDER BY id DESC LIMIT 1")->fetchColumn();
$product = Products::byId($productId);
$slug = (string)$product['slug'];
$sku = (string)$product['sku'];
$domain = [
    benchmarkMeasure('product_by_slug', fn() => Products::bySlug($slug), $iterations),
    benchmarkMeasure('category_listing_price_asc', fn() => Products::byCategory($categoryId, 1, 24, 'price_asc'), $iterations),
    benchmarkMeasure('category_spec_filter_values', fn() => Products::specFilterValues($categoryId, 'sk'), $iterations),
    benchmarkMeasure('category_spec_filter_active', fn() => Products::specFilterValues($categoryId, 'sk', ['Brand' => ['Brand 1']]), $iterations),
    benchmarkMeasure('category_tree_2020_nodes', fn() => Categories::tree(), $iterations),
    benchmarkMeasure('related_from_5000_products', fn() => Products::related($productId, 4), $iterations),
    benchmarkMeasure('search_selective_sku', fn() => Search::query($sku, 1, 24), $iterations),
    benchmarkMeasure('search_broad_term', fn() => Search::query('premium', 1, 24), $iterations),
    benchmarkMeasure('faceted_filter', fn() => Products::byCategory($categoryId, 1, 24, 'price_asc', ['color' => ['black'], 'size' => ['m']]), $iterations),
    benchmarkMeasure('price_range', fn() => Products::byCategory($categoryId, 1, 24, 'price_asc', [], 1000, 5000), $iterations),
];
$customerCookie = benchmarkCustomerSession($db);
[$httpServer, $baseUrl, $queryLog] = benchmarkStartHttpServer($root, $runtime);
$httpIterations = min($iterations, 20);
$clearCache = static fn() => benchmarkClearCacheFiles($runtime . '/cache');
$categoryUrl = $baseUrl . '/index.php?page=category&slug=benchmark-category-0&lang=sk';
$apiCategoryUrl = $baseUrl . '/api.php?action=parametric_search&category_id=' . $categoryId . '&lang=sk';
$validateCategorySsr = static fn(string $body): bool => str_contains($body, '5,000') && str_contains($body, 'Benchmark Product');
$validateCategoryApi = static function (string $body): bool {
    $payload = json_decode($body, true);
    return is_array($payload)
        && ($payload['total'] ?? null) === 5000
        && count($payload['products'] ?? []) === 24
        && count($payload['filters'] ?? []) === 4;
};
try {
    $httpTtfb = [
        benchmarkHttpProfile('category_ssr_guest_cold', $categoryUrl, $httpIterations, $queryLog, null, $clearCache, 0, $validateCategorySsr),
        benchmarkHttpProfile('category_ssr_guest_warm', $categoryUrl, $httpIterations, $queryLog, null, null, 5, $validateCategorySsr),
        benchmarkHttpProfile('category_ssr_customer_cold', $categoryUrl, $httpIterations, $queryLog, $customerCookie, $clearCache, 0, $validateCategorySsr),
        benchmarkHttpProfile('category_ssr_customer_warm', $categoryUrl, $httpIterations, $queryLog, $customerCookie, null, 5, $validateCategorySsr),
        benchmarkHttpProfile('category_api_guest_cold', $apiCategoryUrl, $httpIterations, $queryLog, null, $clearCache, 0, $validateCategoryApi),
        benchmarkHttpProfile('category_api_guest_warm', $apiCategoryUrl, $httpIterations, $queryLog, null, null, 5, $validateCategoryApi),
        benchmarkHttpProfile('category_api_customer_cold', $apiCategoryUrl, $httpIterations, $queryLog, $customerCookie, $clearCache, 0, $validateCategoryApi),
        benchmarkHttpProfile('category_api_customer_warm', $apiCategoryUrl, $httpIterations, $queryLog, $customerCookie, null, 5, $validateCategoryApi),
    ];
} finally {
    proc_terminate($httpServer);
    proc_close($httpServer);
}

$benchmarkBudgets = [
    'category_spec_filter_values' => ['p95_ms' => 150.0, 'max_queries' => 1],
    'category_spec_filter_active' => ['p95_ms' => 250.0, 'max_queries' => 2],
    'category_tree_2020_nodes' => ['p95_ms' => 25.0, 'max_queries' => 1],
    'related_from_5000_products' => ['p95_ms' => 25.0, 'max_queries' => 2],
    'category_ssr_guest_cold' => ['p95_ms' => 500.0, 'max_queries' => 36],
    'category_ssr_guest_warm' => ['p95_ms' => 500.0, 'max_queries' => 36],
    'category_ssr_customer_cold' => ['p95_ms' => 500.0, 'max_queries' => 39],
    'category_ssr_customer_warm' => ['p95_ms' => 500.0, 'max_queries' => 39],
    'category_api_guest_cold' => ['p95_ms' => 500.0, 'max_queries' => 15],
    'category_api_guest_warm' => ['p95_ms' => 500.0, 'max_queries' => 15],
    'category_api_customer_cold' => ['p95_ms' => 500.0, 'max_queries' => 18],
    'category_api_customer_warm' => ['p95_ms' => 500.0, 'max_queries' => 18],
];
$budgetEvidence = [];
foreach (array_merge($domain, $httpTtfb) as $profile) {
    $budget = $benchmarkBudgets[$profile['name']] ?? null;
    if ($budget === null) continue;
    $observedQueries = (int)($profile['query_count']['max'] ?? PHP_INT_MAX);
    $passed = $profile['p95'] <= $budget['p95_ms'] && $observedQueries <= $budget['max_queries'];
    $budgetEvidence[$profile['name']] = [
        'passed' => $passed,
        'p95_ms' => $profile['p95'],
        'p95_budget_ms' => $budget['p95_ms'],
        'max_queries' => $observedQueries,
        'query_budget' => $budget['max_queries'],
    ];
    if (!$passed) {
        throw new RuntimeException('Benchmark budget failed: ' . $profile['name'] . ' ' . json_encode($budgetEvidence[$profile['name']], JSON_UNESCAPED_SLASHES));
    }
}
$db = null;
$migrator = null;
Db::reset();
$serialCheckout = benchmarkConcurrentCheckouts(__FILE__, $runtime, 1, $concurrency + 4);
if ($serialCheckout['failures'] !== []) {
    throw new RuntimeException('Serial checkout control failed: ' . json_encode($serialCheckout['failures']));
}
$concurrencyResult = benchmarkConcurrentCheckouts(__FILE__, $runtime, $concurrency);
$concurrencyResult['passed'] = $concurrencyResult['failures'] === [];
$concurrencyResult['safe_concurrency_observed'] = $concurrencyResult['passed'] ? $concurrency : $concurrencyResult['successful'];
$concurrencyResult['serial_control'] = $serialCheckout;
$db = Db::get();
$failures = benchmarkFailureChecks($db, $runtime);

clearstatcache(true, $dbPath);
$report = [
    'schema' => 'barebones-benchmark/v2',
    'generated_at' => gmdate('c'),
    'git_commit' => trim((string)@shell_exec('git rev-parse HEAD')),
    'git_dirty' => trim((string)@shell_exec('git status --porcelain')) !== '',
    'dataset' => [
        'requested_products' => $dataset,
        'actual_products' => (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn(),
        'categories' => (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
        'seed_seconds' => $seedSeconds,
        'deterministic' => true,
        'reused_after_interrupted_read_profile' => $reusedDataset,
    ],
    'resource_envelope' => [
        'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
        'machine' => php_uname('m'),
        'logical_processors' => (int)(getenv('NUMBER_OF_PROCESSORS') ?: 0),
        'php' => PHP_VERSION,
        'pdo_driver' => Db::driver(),
        'memory_limit' => ini_get('memory_limit'),
        'peak_php_memory_bytes' => memory_get_peak_usage(true),
        'database_bytes' => is_file($dbPath) ? filesize($dbPath) : null,
    ],
    'methodology' => [
        'iterations' => $iterations,
        'warmups_per_operation' => 5,
        'percentile_method' => 'nearest-rank',
        'checkout_workers' => $concurrency,
        'checkout_worker_start_stagger_ms' => 25,
        'runtime' => $runtime,
        'http_primary_metric' => 'total response time',
        'http_cold_definition' => 'application cache files cleared before every request',
        'http_warm_definition' => 'five warmups followed by measured requests with caches retained',
    ],
    'domain_operations' => $domain,
    'http_category_profiles' => $httpTtfb,
    'performance_budgets' => $budgetEvidence,
    'checkout_concurrency' => $concurrencyResult,
    'failure_injection' => $failures,
    'limitations' => [
        'Numbers describe this exact disposable SQLite runtime and resource envelope, not universal production guarantees.',
        'HTTP total-response and TTFB profiles use the single-process PHP development server and are not a production web-server throughput claim.',
        'The backup failure uses a deterministic blocked destination path; it does not fill a real disk.',
        'The percentile search uses a selective SKU. An all-document broad term is a separate saturation diagnostic, not a normal latency sample.',
    ],
];

$reportPath = (string)($options['report'] ?? ($runtime . '/reports/benchmark-' . $dataset . '.json'));
if (!benchmarkAbsolutePath($reportPath) || !benchmarkContainedPath($reportPath, $runtime)) {
    throw new RuntimeException('Report path must be absolute and inside the disposable benchmark runtime');
}
benchmarkEnsureDir(dirname($reportPath));
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);

echo "BareBones Shop reproducible qualification benchmark\n";
echo "Dataset: {$report['dataset']['actual_products']} products; SQLite: " . number_format((int)$report['resource_envelope']['database_bytes']) . " bytes\n";
foreach (array_merge($domain, $httpTtfb) as $result) {
    $queries = $result['query_count']['max'] ?? null;
    printf("  %-34s p50=%8.3f ms  p95=%8.3f ms  p99=%8.3f ms  queries=%s\n",
        $result['name'], $result['p50'], $result['p95'], $result['p99'], $queries === null ? 'n/a' : (string)$queries);
}
printf("  concurrent_checkouts (%d)          elapsed=%8.3f ms; successful=%d\n", $concurrency, $concurrencyResult['elapsed_ms'], $concurrencyResult['successful']);
echo 'Failure injection: ' . count($failures) . '/' . count($failures) . " invariants passed\n";
echo "Report: {$reportPath}\n";
if (!$concurrencyResult['passed']) {
    fwrite(STDERR, "Concurrent checkout qualification failed; see report diagnostics.\n");
    exit(1);
}
