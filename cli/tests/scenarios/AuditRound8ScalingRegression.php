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
if ($page === 'sitemap') {
    Sitemap::setMaxUrlsForTesting(10000);
    $result = [];
    foreach ([0, 1] as $part) {
        $out = tmpfile();
        Sitemap::write($out, 'https://shop.example', $part);
        rewind($out);
        $result[$part] = ['urls' => 0, 'index' => false, 'last' => ''];
        while (($line = fgets($out)) !== false) {
            if (str_starts_with($line, '  <url>')) $result[$part]['urls']++;
            if (str_starts_with($line, '<sitemapindex')) $result[$part]['index'] = true;
            $result[$part]['last'] = trim($line);
        }
        fclose($out);
    }
    echo json_encode($result);
    exit(0);
}
if ($page === 'rebuild') {
    // 30 extra categories reached only through product_categories, so the listing must keep that branch.
    $store = $db->quote(StoreContext::current()->catalogId());
    for ($c = 1; $c <= 30; $c++) {
        $db->exec("INSERT INTO categories (name, slug, store_id) VALUES ('R8 extra {$c}', 'r8-extra-{$c}', {$store})");
        $id = (int)$db->lastInsertId();
        $db->exec("INSERT INTO product_categories (product_id, category_id) SELECT id, {$id} FROM products WHERE sku IN ('R8M-{$c}', 'R8M-" . ($c + 100) . "')");
    }
    $rec = new class('sqlite:' . $dir . '/shop.sqlite') extends PDO {
        /** @var list<string> */
        public array $listings = [];
        public function prepare(string $query, array $options = []): PDOStatement|false {
            if (preg_match('/^\s*SELECT (?:COUNT\(\*\)|p\.\*) FROM products p WHERE/i', $query)) $this->listings[] = $query;
            return parent::prepare($query, $options);
        }
    };
    $rec->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rec->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    Db::init($rec, 'sqlite');
    Cache::setDir($dir . '/cache');
    CacheRebuild::run(['scope' => 'full'], ['per_page' => 24]);
    $extra = (int)$db->query("SELECT id FROM categories WHERE slug = 'r8-extra-7'")->fetchColumn();
    $count = (string)(array_values(array_filter($rec->listings, static fn($q) => str_contains($q, 'COUNT(*)')))[0] ?? '');
    $literal = str_replace([':cat_link', ':cat', ':store'], [(string)$extra, (string)$extra, $store], $count);
    $plan = $count === '' ? [] : array_column($db->query('EXPLAIN QUERY PLAN ' . $literal)->fetchAll(), 'detail');
    $listing = Cache::readJson(Cache::localePath('en', 'cat/r8-extra-7/page-1.json'));
    $prices = json_decode((string)Cache::read('prices/guest.json'), true);
    $sample = $db->query("SELECT id, sku, price, category_id FROM products WHERE sku = 'R8M-39999'")->fetch();
    echo json_encode([
        'categories' => (int)$db->query("SELECT COUNT(*) FROM categories WHERE store_id = {$store}")->fetchColumn(),
        'locales' => count((array)(StoreContext::current()->config()['languages'] ?? [])),
        'listing_queries' => count($rec->listings), 'plan' => $plan,
        'extra_skus' => array_column((array)($listing['products'] ?? []), 'sku'), 'extra_total' => $listing['total'] ?? null,
        'extra_category' => $listing['category']['slug'] ?? null,
        'prices' => is_array($prices) ? count($prices) : -1,
        'sample_ok' => is_array($prices) && ($prices['R8M-39999'] ?? null) === Pricing::apply($sample, 'guest')['price'],
        'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    ]);
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
    $r8sSitemap = $r8sRender('sitemap');
    assert_true(($r8sSitemap['result'][0]['index'] ?? false) === true && ($r8sSitemap['result'][0]['last'] ?? '') === '</sitemapindex>',
        'R8 sitemap: 40k products render a sitemap index within 24 MB (' . substr($r8sSitemap['raw'], 0, 300) . ')');
    assert_true(($r8sSitemap['result'][1]['urls'] ?? 0) === 10000 && ($r8sSitemap['result'][1]['last'] ?? '') === '</urlset>',
        'R8 sitemap: a part streams exactly its page of URLs within 24 MB, products read in chunks (' . substr($r8sSitemap['raw'], 0, 300) . ')');

    // Full cache rebuild at catalog scale: one listing query pair per category for all languages (it ran again per
    // language), served from the category indexes instead of a scan of the whole catalog, price maps streamed.
    $r8sRebuild = $r8sRender('rebuild');
    $r8sRb = (array)($r8sRebuild['result'] ?? []);
    assert_true(isset($r8sRb['categories']) && $r8sRebuild['exit'] === 0,
        'R8 cache rebuild: a full rebuild of 31 categories x 40k products completes within 24 MB (' . substr($r8sRebuild['raw'], 0, 300) . ')');
    assert_true(($r8sRb['locales'] ?? 0) >= 2 && ($r8sRb['listing_queries'] ?? PHP_INT_MAX) === 2 * ($r8sRb['categories'] ?? 0),
        'R8 cache rebuild: listings are queried once per category, not once per category and language (' . ($r8sRb['listing_queries'] ?? '?') . ' queries, ' . ($r8sRb['categories'] ?? '?') . ' categories, ' . ($r8sRb['locales'] ?? '?') . ' languages)');
    $r8sPlan = implode(' | ', (array)($r8sRb['plan'] ?? []));
    assert_true(str_contains($r8sPlan, 'idx_prod_category') && str_contains($r8sPlan, 'idx_product_categories_category') && !str_contains($r8sPlan, 'store_id=? AND status=?'),
        'R8 cache rebuild: a category listing reads its products through both category indexes, not the whole catalog (' . $r8sPlan . ')');
    $r8sExtra = (array)($r8sRb['extra_skus'] ?? []);
    sort($r8sExtra);
    assert_equals([['R8M-107', 'R8M-7'], 2], [$r8sExtra, $r8sRb['extra_total'] ?? null], 'R8 cache rebuild: a category listing still includes products assigned through product_categories');
    assert_equals('r8-extra-7', $r8sRb['extra_category'] ?? null, 'R8 cache rebuild: a rebuilt listing carries its category, like the one the category API caches');
    $r8sWarm = (string)file_get_contents(TEST_CLI_DIR . '/cache-warm.php');
    assert_true(str_contains($r8sWarm, 'CacheRebuild::run([\'scope\' => \'full\'], $cfg)') && str_contains($r8sWarm, 'Bootstrap::initStoreContext(')
        && preg_match('/if \(!\$withProducts\) continue;/', $r8sWarm) === 1,
        'R8 cache warm: cli/cache-warm.php runs the store-scoped rebuild per store; one file per product and language only with --products');
    assert_true(($r8sRb['prices'] ?? -1) === 40000 && ($r8sRb['sample_ok'] ?? false) === true,
        'R8 cache rebuild: the streamed guest price map holds every active product with its group price (' . ($r8sRb['prices'] ?? '?') . ')');

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
    if (is_dir($r8sMem . '/cache')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r8sMem . '/cache', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $r8sF) {
            $r8sF->isDir() ? @rmdir($r8sF->getPathname()) : @unlink($r8sF->getPathname());
        }
        @rmdir($r8sMem . '/cache');
    }
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

// A queue state directory that does not exist yet (fresh install) lists nothing; the admin's error handler turns any
// warning into an exception, so opendir() on it broke the dashboard.
$r8sQueueProps = [new ReflectionProperty(JobQueue::class, 'jobsDir'), new ReflectionProperty(JobQueue::class, 'forceFile')];
$r8sSavedQueue = array_map(static fn(ReflectionProperty $p) => $p->getValue(), $r8sQueueProps);
set_error_handler(static function (int $no, string $message): bool { throw new ErrorException($message, 0, $no); });
try {
    JobQueue::setDir(sys_get_temp_dir() . '/bbe-r8-nojobs-' . bin2hex(random_bytes(4)));
    $r8sNoDir = [JobQueue::stats(), JobQueue::failures()];
} catch (ErrorException $e) {
    $r8sNoDir = $e->getMessage();
} finally {
    restore_error_handler();
    foreach ($r8sQueueProps as $r8sI => $r8sProp) $r8sProp->setValue(null, $r8sSavedQueue[$r8sI]);
}
assert_equals([['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0], []], $r8sNoDir, 'R8 queue stats: missing queue directories count as empty');

// GDPR export on the file queue: completed/ holds a week of every customer's jobs. They were all decoded into one array
// before the customer's were picked out (about 3 KB each), so a busy shop's export died at 128 MB behind HTTP 200.
$r8sGdprDir = sys_get_temp_dir() . '/bbe-r8-gdprjobs-' . bin2hex(random_bytes(4));
$r8sGdprEmail = 'r8-gdpr-' . bin2hex(random_bytes(4)) . '@example.com';
$r8sGdprStore = StoreContext::current()->id();
@mkdir($r8sGdprDir . '/completed', 0777, true);
@mkdir($r8sGdprDir . '/pending', 0777, true);
$r8sGdprBody = str_repeat('Order confirmation line ', 100);
for ($r8sI = 0; $r8sI < 6000; $r8sI++) {
    file_put_contents(sprintf('%s/completed/%010d_r8g%05d.json', $r8sGdprDir, 5, $r8sI), json_encode(['id' => "g{$r8sI}", 'type' => 'email_send',
        'payload' => ['to' => "other{$r8sI}@example.com", 'subject' => "Order {$r8sI}", 'body' => $r8sGdprBody, '_store_id' => $r8sGdprStore]]));
}
file_put_contents(sprintf('%s/pending/%010d_r8gmine.json', $r8sGdprDir, 5), json_encode(['id' => 'g-mine', 'type' => 'email_send', 'created_at' => '2026-10-22T00:00:00+00:00',
    'payload' => ['to' => $r8sGdprEmail, 'subject' => 'Your order', '_store_id' => $r8sGdprStore]]));
$r8sSavedQueue = array_map(static fn(ReflectionProperty $p) => $p->getValue(), $r8sQueueProps);
// A guest the store knows (here by an abandoned cart): the merchant's access request for them reads the queue.
Db::get()->prepare("INSERT INTO abandoned_carts (session_id, customer_email, store_id, created_at) VALUES ('r8-gdpr', ?, ?, '2026-10-22 00:00:00')")->execute([$r8sGdprEmail, $r8sGdprStore]);
try {
    JobQueue::setDir($r8sGdprDir);
    $r8sGdprPeak = null;
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
        $r8sGdprPeak = memory_get_peak_usage();
    }
    $r8sGdprExport = GdprService::exportGuest($r8sGdprEmail);
    $r8sGdprPeak = $r8sGdprPeak === null ? null : memory_get_peak_usage() - $r8sGdprPeak;
} finally {
    Db::get()->prepare("DELETE FROM abandoned_carts WHERE customer_email = ?")->execute([$r8sGdprEmail]);
    foreach ($r8sQueueProps as $r8sI => $r8sProp) $r8sProp->setValue(null, $r8sSavedQueue[$r8sI]);
    foreach (['completed', 'pending', ''] as $r8sSub) {
        foreach (glob($r8sGdprDir . '/' . $r8sSub . '/*.json') ?: [] as $r8sF) @unlink($r8sF);
        @rmdir(rtrim($r8sGdprDir . '/' . $r8sSub, '/'));
    }
}
assert_equals([['g-mine', 'pending']], array_map(static fn(array $j): array => [$j['id'], $j['state']], array_values(array_filter($r8sGdprExport['queued_jobs'], static fn(array $j): bool => str_starts_with((string)$j['id'], 'g')))),
    'R8 GDPR export: of 6000 unrelated queue files only the subject\'s own job is exported');
if ($r8sGdprPeak === null) {
    print "  [SKIP] R8 GDPR export memory: PHP " . PHP_VERSION . " has no memory_reset_peak_usage()\n";
} else {
    assert_true($r8sGdprPeak < 4 * 1048576, 'R8 GDPR export: 6000 unrelated queue files are streamed, not held in memory (peak grew ' . round($r8sGdprPeak / 1048576, 1) . ' MB)');
}

// A fatal error mid-page (here: memory limit) never ships as HTTP 200 with half a page.
// Runs a PHP script with a 16 MB limit and no error display; returns [stdout, stderr].
$r8sRunOom = static function (string $code): array {
    $file = sys_get_temp_dir() . '/bbe-r8fatal-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($file, $code);
    try {
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=16M', '-d', 'display_errors=0', '-d', 'log_errors=0', $file, dirname(TEST_CLI_DIR)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $out = [(string)stream_get_contents($pipes[1]), (string)stream_get_contents($pipes[2])];
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $out;
    } finally {
        @unlink($file);
    }
};
// Some PHP builds (Ubuntu PHP 8.1.34 of 2026-10-21) run no shutdown function at all after an exhausted memory limit:
// they print the fatal to stderr and exit. No PHP-level guard can answer there; the probe tells such a runtime apart.
[, $r8sProbe] = $r8sRunOom('<?php register_shutdown_function(static fn() => fwrite(STDERR, "R8-SHUTDOWN-RAN")); $r = []; while (true) $r[] = str_repeat("x", 1 << 20);');
if (!str_contains($r8sProbe, 'R8-SHUTDOWN-RAN')) {
    print "  [SKIP] R8 admin fatal: PHP " . PHP_VERSION . " runs no shutdown function after an exhausted memory limit\n";
} else {
    [$r8sOut, $r8sErr] = $r8sRunOom(<<<'PHP'
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
    assert_true(!str_contains($r8sOut, 'R8-HALF-PAGE') && str_contains($r8sOut, '<h1>500</h1>'), 'R8 admin fatal: the half-rendered page is discarded for an error page (got: ' . substr($r8sOut, 0, 160) . ')');
    assert_true(str_contains($r8sErr, 'status=500'), 'R8 admin fatal: the response status is 500, not 200 (got: ' . substr($r8sErr, 0, 160) . ')');
}
$r8sAdmin = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin.php');
$r8sGuardAt = strpos($r8sAdmin, "register_shutdown_function('adminFatalPageGuard');");
$r8sBufferAt = strpos($r8sAdmin, 'ob_start();');
$r8sRenderAt = strpos($r8sAdmin, '<!DOCTYPE html>');
assert_true($r8sGuardAt !== false && $r8sBufferAt !== false && $r8sBufferAt < $r8sRenderAt && $r8sGuardAt < $r8sRenderAt,
    'R8 admin fatal: admin.php buffers the page and registers the guard before any HTML is emitted');

// Retention jobs on SQLite: one DELETE/UPDATE over years of rows held the write lock for half a minute and every
// checkout gave up after busy_timeout. Each purge now runs as short statements of at most 1000 keys, each its own
// commit: a second connection with busy_timeout 0 must be able to write before every one of them.
[$r8sSavedDb, $r8sSavedDriver] = [Db::get(), Db::driver()];
$r8sRet = sys_get_temp_dir() . '/bbe-r8ret-' . bin2hex(random_bytes(4));
mkdir($r8sRet . '/jobs', 0700, true);
$r8sRetFile = $r8sRet . '/shop.sqlite';
$r8sRetOther = new PDO('sqlite:' . $r8sRetFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r8sRetOther->exec('PRAGMA journal_mode = WAL');
$r8sRetOther->exec('PRAGMA busy_timeout = 0');
$r8sRetOther->exec('CREATE TABLE r8s_checkout (x INTEGER)');
$r8sRetDb = new class('sqlite:' . $r8sRetFile) extends PDO {
    public ?PDO $other = null;
    /** @var list<array{table: string, keys: int, concurrent: bool}> */
    public array $writes = [];
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if ($this->other !== null && preg_match('/^\s*(?:DELETE FROM|UPDATE)\s+(email_log|queue_jobs)\b/i', $query, $m)) {
            try { $this->other->exec('INSERT INTO r8s_checkout VALUES (1)'); $ok = true; } catch (PDOException) { $ok = false; }
            $this->writes[] = ['table' => $m[1], 'keys' => substr_count($query, ':batch_key'), 'concurrent' => $ok];
        }
        return parent::prepare($query, $options);
    }
};
$r8sRetDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$r8sRetDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
try {
    (new Migrator($r8sRetDb, TEST_CLI_DIR . '/../migrations', $r8sRet . '/migrate.lock'))->apply(static fn($m) => null);
    Db::init($r8sRetDb, 'sqlite');
    $r8sOld = gmdate('Y-m-d H:i:s', time() - 400 * 86400);
    $r8sNew = gmdate('Y-m-d H:i:s');
    $r8sRetDb->exec("WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 2600)
        INSERT INTO email_log (recipient, template, subject, payload, created_at)
        SELECT 'buyer' || i || '@example.com', 'order_confirmation', 'Order ' || i, '{}', CASE WHEN i <= 2500 THEN '{$r8sOld}' ELSE '{$r8sNew}' END FROM n");
    $r8sRetDb->exec("WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 2100)
        INSERT INTO queue_jobs (id, type, payload, state, available_at, correlation_id, completed_at)
        SELECT 'job' || i, 'mail', '{}', 'completed', '{$r8sOld}', 'c' || i, CASE WHEN i <= 2050 THEN '{$r8sOld}' ELSE '{$r8sNew}' END FROM n");
    $r8sRetDb->other = $r8sRetOther;
    $r8sByTable = static fn(string $t): array => array_values(array_filter($r8sRetDb->writes, static fn($w) => $w['table'] === $t));

    $r8sScrub = GdprService::applyRetention('email_log', ['table' => 'email_log', 'ttl_days' => 90, 'field' => 'created_at',
        'action' => 'scrub_pii', 'scrub_fields' => ['recipient', 'subject', 'payload']], 'apply');
    $r8sScrubWrites = $r8sByTable('email_log');
    $r8sLeft = (int)$r8sRetDb->query("SELECT COUNT(*) FROM email_log WHERE recipient LIKE 'buyer%'")->fetchColumn();
    assert_true((int)($r8sScrub['affected'] ?? $r8sScrub['rows_affected'] ?? -1) === 2500 && $r8sLeft === 100,
        'R8 retention: scrub_pii erases exactly the 2500 old rows and keeps the 100 recent ones (left: ' . $r8sLeft . ')');
    assert_true(count($r8sScrubWrites) >= 3 && max(array_column($r8sScrubWrites, 'keys') ?: [PHP_INT_MAX]) <= 1000 && min(array_column($r8sScrubWrites, 'keys') ?: [0]) >= 1,
        'R8 retention: scrub_pii runs as statements of at most 1000 keys (' . json_encode(array_column($r8sScrubWrites, 'keys')) . ')');
    assert_true($r8sScrubWrites !== [] && !in_array(false, array_column($r8sScrubWrites, 'concurrent'), true),
        'R8 retention: a checkout with busy_timeout 0 can write before every scrub statement');
    $r8sAgain = GdprService::applyRetention('email_log', ['table' => 'email_log', 'ttl_days' => 90, 'field' => 'created_at',
        'action' => 'scrub_pii', 'scrub_fields' => ['recipient', 'subject', 'payload']], 'apply');
    assert_true((int)($r8sAgain['affected'] ?? $r8sAgain['rows_affected'] ?? -1) === 0, 'R8 retention: a re-run finds nothing left to scrub and terminates');

    $r8sRetDb->writes = [];
    $r8sDel = GdprService::applyRetention('email_log_delete', ['table' => 'email_log', 'ttl_days' => 90, 'field' => 'created_at', 'action' => 'delete'], 'apply');
    $r8sDelWrites = $r8sByTable('email_log');
    assert_true((int)($r8sDel['affected'] ?? $r8sDel['rows_affected'] ?? -1) === 2500 && (int)$r8sRetDb->query('SELECT COUNT(*) FROM email_log')->fetchColumn() === 100,
        'R8 retention: delete removes exactly the 2500 old rows');
    assert_true(count($r8sDelWrites) >= 3 && max(array_column($r8sDelWrites, 'keys') ?: [PHP_INT_MAX]) <= 1000 && !in_array(false, array_column($r8sDelWrites, 'concurrent'), true),
        'R8 retention: delete runs as short statements a concurrent checkout can write between (' . json_encode($r8sDelWrites) . ')');

    $r8sRetDb->exec("WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 2300)
        INSERT INTO email_log (recipient, template, created_at) SELECT 'Hash' || i || '@Example.com', 'x', '{$r8sOld}' FROM n");
    $r8sHash = GdprService::applyRetention('email_log_hash', ['table' => 'email_log', 'ttl_days' => 90, 'field' => 'created_at',
        'action' => 'hash_email', 'scrub_fields' => ['recipient']], 'apply');
    $r8sPlain = (int)$r8sRetDb->query("SELECT COUNT(*) FROM email_log WHERE recipient LIKE '%@%' AND created_at = '{$r8sOld}'")->fetchColumn();
    assert_true((int)($r8sHash['affected'] ?? $r8sHash['rows_affected'] ?? -1) === 2300 && $r8sPlain === 0,
        'R8 retention: hash_email hashes all 2300 old addresses across its 1000-row chunks (plain left: ' . $r8sPlain . ')');

    $r8sRetDb->writes = [];
    $r8sClean = RetentionCleanup::run(['jobs_dir' => $r8sRet . '/jobs']);
    $r8sJobWrites = $r8sByTable('queue_jobs');
    assert_true(($r8sClean['queue_jobs'] ?? -1) === 2050 && (int)$r8sRetDb->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn() === 50,
        'R8 retention: the daily cleanup deletes exactly the 2050 old completed jobs');
    assert_true(count($r8sJobWrites) >= 3 && max(array_column($r8sJobWrites, 'keys') ?: [PHP_INT_MAX]) <= 1000 && !in_array(false, array_column($r8sJobWrites, 'concurrent'), true),
        'R8 retention: the daily cleanup deletes in short statements a concurrent checkout can write between (' . json_encode(array_column($r8sJobWrites, 'keys')) . ')');
} finally {
    Db::init($r8sSavedDb, $r8sSavedDriver);
    $r8sRetDb = $r8sRetOther = null;
    gc_collect_cycles();
    foreach (glob($r8sRet . '/jobs/*/*') ?: [] as $r8sF) @unlink($r8sF);
    foreach (glob($r8sRet . '/jobs/*') ?: [] as $r8sF) @rmdir($r8sF);
    @rmdir($r8sRet . '/jobs');
    foreach (glob($r8sRet . '/*') ?: [] as $r8sF) @unlink($r8sF);
    @rmdir($r8sRet);
}

// MySQL: the CSV exports really stream. Native prepares ignore the buffered-query option given to prepare(), so the
// whole result used to land in PHP memory; Db::executeUnbuffered switches the connection for that execute only.
if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R8 MySQL unbuffered export (set TEST_MYSQL_DSN)\n";
} else {
    [$r8sSavedDb, $r8sSavedDriver] = [Db::get(), Db::driver()];
    $r8sMy = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
    $r8sMy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r8sMyDb = 'bbe_r8s_unbuf_' . bin2hex(random_bytes(4));
    $r8sBufferedAttr = defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    try {
        $r8sMy->exec("CREATE DATABASE `{$r8sMyDb}`");
        $r8sMy->exec("USE `{$r8sMyDb}`");
        $r8sMy->exec('CREATE TABLE r8s_rows (id INT PRIMARY KEY AUTO_INCREMENT, v VARCHAR(500))');
        $r8sInsert = $r8sMy->prepare('INSERT INTO r8s_rows (v) VALUES ' . implode(', ', array_fill(0, 500, '(?)')));
        for ($r8sI = 0; $r8sI < 60; $r8sI++) $r8sInsert->execute(array_fill(0, 500, str_repeat('x', 400)));
        Db::init($r8sMy, 'mysql');
        $r8sBefore = memory_get_usage();
        $r8sStmt = Db::executeUnbuffered('SELECT * FROM r8s_rows ORDER BY id');
        $r8sHeld = memory_get_usage() - $r8sBefore;
        $r8sRows = 0;
        while ($r8sStmt->fetch(PDO::FETCH_NUM) !== false) $r8sRows++;
        $r8sStmt = null;
        assert_true($r8sRows === 30000 && $r8sHeld < 2 * 1048576,
            'R8 MySQL export: 30k rows (~12 MB) stream instead of being buffered in PHP (held after execute: ' . round($r8sHeld / 1048576, 1) . ' MB)');
        assert_true($r8sMy->getAttribute($r8sBufferedAttr) == true, 'R8 MySQL export: the connection is buffered again for every other query');
        $r8sA = $r8sMy->query('SELECT COUNT(*) FROM r8s_rows');
        $r8sB = $r8sMy->query('SELECT 1');
        assert_equals([30000, 1], [(int)$r8sA->fetchColumn(), (int)$r8sB->fetchColumn()], 'R8 MySQL export: two open buffered queries work after the stream');
    } finally {
        Db::init($r8sSavedDb, $r8sSavedDriver);
        $r8sMy->exec("DROP DATABASE IF EXISTS `{$r8sMyDb}`");
        $r8sMy = null;
    }
}
