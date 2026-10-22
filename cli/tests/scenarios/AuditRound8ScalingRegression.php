<?php

// Review round 8 (scaling, 200k products / 500k orders): admin list queries are served by an index matching their
// WHERE + ORDER BY, never by a store-wide scan or a temporary sort.
echo "\n--- Review round 8: scaling ---\n";
[$r8sSavedDb, $r8sSavedDriver] = [Db::get(), Db::driver()];
$r8sTmp = sys_get_temp_dir() . '/bbe-r8scale-' . bin2hex(random_bytes(4));
mkdir($r8sTmp, 0700, true);
$r8sDb = new class('sqlite::memory:') extends PDO {
    /** @var list<string> */
    public array $prepared = [];
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->prepared[] = $query;
        return parent::prepare($query, $options);
    }
};
$r8sDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$r8sDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
try {
    (new Migrator($r8sDb, TEST_CLI_DIR . '/../migrations', $r8sTmp . '/migrate.lock'))->apply(static fn($m) => null);
    Db::init($r8sDb, 'sqlite');
    $r8sStore = $r8sDb->quote((string)StoreContext::current()->id());
    // A two-store shop with a realistic status mix, analysed so the planner sees the real distribution.
    $r8sDb->exec("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, payment_method, subtotal, total, store_id, created_at)
        WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 20000)
        SELECT 'R8S-' || i, CASE i % 17 WHEN 0 THEN 'pending' WHEN 1 THEN 'cancelled' ELSE 'delivered' END,
            CASE i % 23 WHEN 0 THEN 'pending' WHEN 1 THEN 'authorized' ELSE 'paid' END, (i % 5000) + 1, 'C', 'c' || i || '@example.invalid',
            'cod', 1000, 1000, CASE WHEN i % 10 = 0 THEN 'r8s-other' ELSE {$r8sStore} END, datetime('2024-01-01', '+' || i || ' minutes') FROM n");
    $r8sDb->exec("INSERT INTO customers (email, password_hash, name, status, store_id, created_at)
        WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 5000)
        SELECT 'c' || i || '@example.invalid', 'x', 'C', 'active', CASE WHEN i % 10 = 0 THEN 'r8s-other' ELSE {$r8sStore} END, datetime('2024-01-01', '+' || i || ' minutes') FROM n");
    $r8sDb->exec('ANALYZE');

    $r8sPlan = static function (string $sql, array $params) use ($r8sDb): string {
        $st = $r8sDb->prepare('EXPLAIN QUERY PLAN ' . $sql);
        $st->execute($params);
        return implode(' | ', array_column($st->fetchAll(), 'detail'));
    };
    $r8sCheck = static function (string $label, string $plan, string $index): void {
        assert_true(str_contains($plan, 'USING INDEX ' . $index) || str_contains($plan, 'USING COVERING INDEX ' . $index), "{$label}: uses {$index} (plan: {$plan})");
        assert_true(!str_contains($plan, 'TEMP B-TREE') && !preg_match('/\bSCAN orders\b|\bSCAN customers\b/', $plan), "{$label}: no scan and no temporary sort (plan: {$plan})");
    };

    // Admin order list (src/Entrypoints/Admin/Orders.php pageOrders): filtered by status or payment status, newest first.
    $r8sOrders = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php');
    assert_true(str_contains($r8sOrders, 'SELECT * FROM orders WHERE {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset'), 'R8 scaling: the admin order list query has the shape measured below');
    $r8sCheck('R8 scaling: order list by status', $r8sPlan("SELECT * FROM orders WHERE store_id = ? AND status = ? ORDER BY created_at DESC LIMIT 25 OFFSET 0", [StoreContext::current()->id(), 'pending']), 'idx_orders_store_status_created');
    $r8sCheck('R8 scaling: order count by status', $r8sPlan("SELECT COUNT(*) FROM orders WHERE store_id = ? AND status = ?", [StoreContext::current()->id(), 'pending']), 'idx_orders_store_status_created');
    $r8sCheck('R8 scaling: order list by payment status', $r8sPlan("SELECT * FROM orders WHERE store_id = ? AND payment_status = ? ORDER BY created_at DESC LIMIT 25 OFFSET 0", [StoreContext::current()->id(), 'authorized']), 'idx_orders_store_payment_created');
    // Sales dashboard open-payment counter, taken verbatim from the entrypoint.
    assert_true(str_contains($r8sOrders, "SELECT COUNT(*) FROM orders WHERE store_id = :store AND payment_status IN ('pending', 'authorized')"), 'R8 scaling: the dashboard open-payment counter has the shape measured below');
    $r8sCheck('R8 scaling: dashboard open-payment count', $r8sPlan("SELECT COUNT(*) FROM orders WHERE store_id = :store AND payment_status IN ('pending', 'authorized')", [':store' => StoreContext::current()->id()]), 'idx_orders_store_payment_created');

    // Admin customer list: the statements Customers::adminList/adminCount really prepare.
    $r8sDb->prepared = [];
    $r8sList = Customers::adminList('', 25, 0);
    Customers::adminCount('');
    assert_equals(25, count($r8sList), 'R8 scaling: the admin customer list returns a page');
    assert_true((int)$r8sList[0]['id'] > (int)$r8sList[24]['id'], 'R8 scaling: the admin customer list is newest first');
    $r8sListSql = array_values(array_filter($r8sDb->prepared, static fn(string $s): bool => str_contains($s, 'FROM customers') && str_contains($s, 'ORDER BY id DESC')));
    assert_equals(1, count($r8sListSql), 'R8 scaling: Customers::adminList prepares one list statement');
    $r8sCheck('R8 scaling: customer list', $r8sPlan((string)($r8sListSql[0] ?? ''), [StoreContext::current()->id(), 25, 0]), 'idx_customers_store_id');
} finally {
    Db::init($r8sSavedDb, $r8sSavedDriver);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r8sTmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $r8sF) {
        $r8sF->isDir() ? @rmdir($r8sF->getPathname()) : @unlink($r8sF->getPathname());
    }
    @rmdir($r8sTmp);
}

// SQLite admin login under concurrent writes: a SELECT left open after one fetch() keeps its WAL snapshot, so the
// following UPDATE fails with SQLITE_BUSY (snapshot) as soon as any other request committed in between — the
// busy timeout cannot help. Here a second connection commits right before every admin write; login, the session
// check and a returning device must still succeed.
[$r8sSavedDb, $r8sSavedDriver] = [Db::get(), Db::driver()];
$r8sTmp = sys_get_temp_dir() . '/bbe-r8login-' . bin2hex(random_bytes(4));
mkdir($r8sTmp, 0700, true);
$r8sFile = $r8sTmp . '/shop.sqlite';
$r8sOther = new PDO('sqlite:' . $r8sFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r8sOther->exec('PRAGMA journal_mode = WAL');
$r8sOther->exec('CREATE TABLE r8s_noise (x INTEGER)');
$r8sLogin = new class('sqlite:' . $r8sFile) extends PDO {
    public ?PDO $other = null;
    public int $concurrentWrites = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if ($this->other !== null && preg_match('/^\s*(UPDATE|INSERT INTO)\s+admin_(accounts|sessions|devices)\b/i', $query)) {
            $this->other->exec('INSERT INTO r8s_noise VALUES (1)');
            $this->concurrentWrites++;
        }
        return parent::prepare($query, $options);
    }
};
$r8sLogin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$r8sLogin->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$r8sLogin->exec('PRAGMA busy_timeout = 2000');
try {
    (new Migrator($r8sLogin, TEST_CLI_DIR . '/../migrations', $r8sTmp . '/migrate.lock'))->apply(static fn($m) => null);
    Db::init($r8sLogin, 'sqlite');
    AdminIdentity::create('r8scale', 'R8-Scale-Admin-Passphrase-2026!', 'owner', false);
    $r8sLogin->other = $r8sOther;
    $r8sTry = static function (callable $fn): array {
        try { return [$fn(), null]; } catch (PDOException $e) { return [null, $e->getMessage()]; }
    };
    [$r8sFirst, $r8sErr1] = $r8sTry(static fn() => AdminIdentity::authenticate('r8scale', 'R8-Scale-Admin-Passphrase-2026!', '', '203.0.113.8', 'R8 browser'));
    assert_true(is_array($r8sFirst) && $r8sErr1 === null, 'R8 SQLite: admin login succeeds while other requests write (error: ' . ($r8sErr1 ?? 'none') . ')');
    [$r8sSession, $r8sErr2] = $r8sTry(static fn() => is_array($r8sFirst) ? AdminIdentity::session((string)$r8sFirst['token']) : null);
    assert_true(is_array($r8sSession) && $r8sErr2 === null, 'R8 SQLite: the admin session check succeeds while other requests write (error: ' . ($r8sErr2 ?? 'none') . ')');
    [$r8sAgain, $r8sErr3] = $r8sTry(static fn() => AdminIdentity::authenticate('r8scale', 'R8-Scale-Admin-Passphrase-2026!', '', '203.0.113.8', 'R8 browser'));
    assert_true(is_array($r8sAgain) && $r8sErr3 === null && $r8sFirst !== null && $r8sAgain['device_id'] === $r8sFirst['device_id'], 'R8 SQLite: a returning device logs in again while other requests write (error: ' . ($r8sErr3 ?? 'none') . ')');
    assert_true($r8sLogin->concurrentWrites >= 5, 'R8 SQLite: the concurrent writer really committed before each admin write');
} finally {
    Db::init($r8sSavedDb, $r8sSavedDriver);
    $r8sLogin = $r8sOther = null;
    gc_collect_cycles();
    foreach (glob($r8sTmp . '/*') ?: [] as $r8sF) @unlink($r8sF);
    @rmdir($r8sTmp);
}

// Admin pages at catalog scale: dashboard, product list and its low-stock filter render completely within a tight
// memory limit (stock levels counted, ordered and limited in SQL; queue directories streamed, not globbed).
$r8sMem = sys_get_temp_dir() . '/bbe-r8mem-' . bin2hex(random_bytes(4));
mkdir($r8sMem . '/jobs/failed', 0700, true);
foreach (['pending', 'processing', 'completed'] as $r8sState) mkdir($r8sMem . '/jobs/' . $r8sState, 0700);
file_put_contents($r8sMem . '/render.php', <<<'PHP'
<?php
[, $root, $dir, $page] = $argv;
require $root . '/src/autoload.php';
class_exists('Security');
$db = new PDO('sqlite:' . $dir . '/shop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($db, 'sqlite');
JobQueue::setDir($dir . '/jobs');
if ($page === 'seed') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate.lock'))->apply(static fn($m) => null);
    $store = $db->quote(StoreContext::current()->catalogId());
    $db->exec("INSERT INTO categories (name, slug, store_id) VALUES ('R8M', 'r8m', {$store})");
    $category = (int)$db->lastInsertId();
    $warehouse = (int)$db->query('SELECT id FROM warehouses ORDER BY id LIMIT 1')->fetchColumn();
    $db->exec("INSERT INTO products (category_id, sku, name, slug, price, currency, stock, status, store_id, updated_at)
        WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 40000)
        SELECT {$category}, 'R8M-' || i, 'Low stock product with a realistic, fairly long catalogue name ' || i, 'r8m-' || i, 1990, 'EUR',
            i % 5, 'active', {$store}, datetime('2024-01-01', '+' || i || ' minutes') FROM n");
    $db->exec("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved, store_id) SELECT {$warehouse}, sku, stock, 0, store_id FROM products");
    $db->exec('ANALYZE');
    exit(0);
}
$_SESSION = [];
$_GET = $page === 'products_low' ? ['p' => 'products', 'stock' => 'low'] : ['p' => $page];
require_once $root . '/src/Entrypoints/Admin/Shared.php';
foreach ((require $root . '/src/Entrypoints/Admin/Modules.php')[$_GET['p']] as $module) require_once $root . '/src/Entrypoints/Admin/' . $module . '.php';
register_shutdown_function(static function (): void {
    $html = '';
    while (ob_get_level() > 0) $html = ob_get_clean() . $html;
    $error = error_get_last();
    echo json_encode(['complete' => str_contains($html, '</table>') && !($error && $error['type'] === E_ERROR),
        'rows' => substr_count($html, '<tr'), 'error' => $error && $error['type'] === E_ERROR ? $error['message'] : null]);
});
ob_start();
$_GET['p'] === 'dashboard' ? pageDashboard() : pageProducts();
PHP);
$r8sRender = static function (string $page) use ($r8sMem): array {
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=' . ($page === 'seed' ? '256M' : '24M'), '-d', 'display_errors=0', '-d', 'log_errors=0',
        $r8sMem . '/render.php', dirname(TEST_CLI_DIR), $r8sMem, $page], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['exit' => $exit, 'result' => json_decode((string)$out, true), 'raw' => trim($out . ' ' . $err)];
};
try {
    // A queue that was never drained: thousands of pending and completed job files.
    for ($r8sI = 0; $r8sI < 3000; $r8sI++) {
        file_put_contents(sprintf('%s/jobs/pending/%010d_r8.json', $r8sMem, $r8sI), '{}');
        file_put_contents(sprintf('%s/jobs/completed/%010d_r8.json', $r8sMem, $r8sI), '{}');
    }
    $r8sSeed = $r8sRender('seed');
    assert_true($r8sSeed['exit'] === 0, 'R8 admin memory: 40k low-stock products seeded (' . $r8sSeed['raw'] . ')');
    foreach (['dashboard' => 'dashboard', 'products' => 'product list', 'products_low' => 'low-stock product filter'] as $r8sPage => $r8sLabel) {
        $r8sRun = $r8sRender($r8sPage);
        assert_true(($r8sRun['result']['complete'] ?? false) === true,
            "R8 admin memory: the {$r8sLabel} renders completely at 40k low-stock SKUs within 24 MB (" . ($r8sRun['result']['error'] ?? $r8sRun['raw']) . ')');
    }
    assert_true(($r8sRun['result']['rows'] ?? 0) <= 30, 'R8 admin memory: the low-stock filter still pages (25 rows), not the whole low list');

    // The bounded APIs keep the old semantics: same count, worst first, limited.
    $r8sJobs = static function () use ($r8sMem): array {
        JobQueue::setDir($r8sMem . '/jobs');
        return [JobQueue::stats(), JobQueue::failures(3)];
    };
    for ($r8sI = 1; $r8sI <= 5; $r8sI++) {
        file_put_contents(sprintf('%s/jobs/failed/%010d_r8.json', $r8sMem, $r8sI), json_encode(['id' => "f{$r8sI}", 'type' => 'r8', 'payload' => ['_store_id' => 'default']]));
    }
    file_put_contents($r8sMem . '/jobs/failed/.hidden.json', '{}');
    [$r8sStats, $r8sFailures] = $r8sJobs();
    assert_equals(['pending' => 3000, 'processing' => 0, 'completed' => 3000, 'failed' => 5], $r8sStats, 'R8 queue: file-queue counts are streamed and still exact (dot files ignored)');
    assert_equals(['f5', 'f4', 'f3'], array_column($r8sFailures, 'id'), 'R8 queue: failures() returns the newest N, newest first');
} finally {
    foreach (['jobs/pending', 'jobs/completed', 'jobs/failed', 'jobs/processing', 'jobs', ''] as $r8sSub) {
        foreach (glob($r8sMem . '/' . $r8sSub . '/{,.}*', GLOB_BRACE) ?: [] as $r8sF) if (is_file($r8sF)) @unlink($r8sF);
    }
    foreach (['jobs/pending', 'jobs/completed', 'jobs/failed', 'jobs/processing', 'jobs', ''] as $r8sSub) @rmdir(rtrim($r8sMem . '/' . $r8sSub, '/'));
}

// StockAlerts in SQL keeps the dashboard semantics on the suite catalog: count = all rows, products limited and ordered
// worst first, and the per-page levels match the full list.
$r8sAll = StockAlerts::belowLow();
$r8sSummary = StockAlerts::summary(2);
assert_equals(count($r8sAll), $r8sSummary['count'], 'R8 stock alerts: summary count equals the number of low SKUs');
assert_equals(array_slice($r8sAll, 0, 2), $r8sSummary['products'], 'R8 stock alerts: summary lists the first rows of the ordered list');
$r8sRank = ['low' => 1, 'critical' => 2, 'out' => 3];
$r8sOrdered = true;
for ($r8sI = 1; $r8sI < count($r8sAll); $r8sI++) {
    [$a, $b] = [$r8sAll[$r8sI - 1], $r8sAll[$r8sI]];
    if ([$r8sRank[$b['level']], $a['available'], $a['sku']] > [$r8sRank[$a['level']], $b['available'], $b['sku']]) $r8sOrdered = false;
}
assert_true($r8sOrdered, 'R8 stock alerts: rows are ordered worst level first, then lowest availability, then SKU');
$r8sExpected = [];
foreach ($r8sAll as $r8sRow) if (($r8sRank[$r8sRow['level']]) > ($r8sRank[$r8sExpected[$r8sRow['product_id']] ?? ''] ?? 0)) $r8sExpected[$r8sRow['product_id']] = $r8sRow['level'];
ksort($r8sExpected);
$r8sLevels = StockAlerts::levelsForProducts(array_keys($r8sExpected));
ksort($r8sLevels);
assert_equals($r8sExpected, $r8sLevels, 'R8 stock alerts: levels for one list page equal the worst level of each product');

// A fatal error mid-page (here: memory limit) never ships as HTTP 200 with half a page.
$r8sFatal = sys_get_temp_dir() . '/bbe-r8fatal-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($r8sFatal, <<<'PHP'
<?php
require $argv[1] . '/src/autoload.php';
class_exists('Security');
require $argv[1] . '/src/Entrypoints/Admin/Shared.php';
ob_start();
register_shutdown_function('adminFatalPageGuard');
register_shutdown_function(static fn() => fwrite(STDERR, 'status=' . http_response_code()));
echo '<table><tr><td>R8-HALF-PAGE</td></tr>';
$rows = [];
while (true) $rows[] = str_repeat('x', 1 << 20);
PHP);
try {
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=16M', '-d', 'display_errors=0', '-d', 'log_errors=0', $r8sFatal, dirname(TEST_CLI_DIR)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $r8sOut = (string)stream_get_contents($pipes[1]);
    $r8sErr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
} finally {
    @unlink($r8sFatal);
}
assert_true(!str_contains($r8sOut, 'R8-HALF-PAGE') && str_contains($r8sOut, '<h1>500</h1>'), 'R8 admin fatal: the half-rendered page is discarded for an error page (got: ' . substr($r8sOut, 0, 160) . ')');
assert_true(str_contains($r8sErr, 'status=500'), 'R8 admin fatal: the response status is 500, not 200 (got: ' . substr($r8sErr, 0, 160) . ')');
$r8sAdmin = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin.php');
$r8sGuardAt = strpos($r8sAdmin, "register_shutdown_function('adminFatalPageGuard');");
$r8sBufferAt = strpos($r8sAdmin, 'ob_start();');
$r8sRenderAt = strpos($r8sAdmin, '<!DOCTYPE html>');
assert_true($r8sGuardAt !== false && $r8sBufferAt !== false && $r8sBufferAt < $r8sRenderAt && $r8sGuardAt < $r8sRenderAt,
    'R8 admin fatal: admin.php buffers the page and registers the guard before any HTML is emitted');
