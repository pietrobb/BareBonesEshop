<?php

// Review round 53 (medium 2 and lows): the first build of the daily sales totals ran inside the admin request and saved
// how far it got only at the end (15–26 s at 150 000 orders, 42 s at 500 000 on MySQL; cut off by a timeout, every visit
// started over). It also ran after a timezone change and a bulk change of old orders. 400 000 logged changes were read
// row by row (out of memory), and one of the four tables missing failed the reports with a 500.
echo "\n--- Audit round 53: daily sales are built in bounded batches that resume, changes read per hour, partial tables ---\n";

$r53sDb = Db::get();
$r53sStore = StoreContext::current()->id();
$r53sOrders = [];
$r53sCreated = !Db::tableExists('sales_daily_state', $r53sDb);
$r53sBackend = new class implements QueueBackend {
    /** @var list<string> */
    public array $pushed = [];
    public function name(): string { return 'r53s-probe'; }
    public function push(string $type, array $payload, int $priority = 5): string { $this->pushed[] = $type; return 'r53s'; }
    public function pull(int $limit = 10): array { return []; }
    public function markProcessing(array $job): string { return ''; }
    public function complete(string $claim): void {}
    public function fail(string $claim, array $job, string $error): void {}
    public function extendLease(string $claim): bool { return true; }
    public function stats(): array { return []; }
    public function failures(int $limit = 20): array { return []; }
};
$r53sState = static function (string $key) use ($r53sDb): string {
    $stmt = $r53sDb->prepare('SELECT value FROM sales_daily_state WHERE state_key = ?');
    $stmt->execute([$key]);
    $value = (string)$stmt->fetchColumn();
    $stmt->closeCursor();
    return $value;
};
$r53sChangesLeft = static function () use ($r53sDb, $r53sStore): int {
    $stmt = $r53sDb->prepare('SELECT COUNT(*) FROM sales_daily_changes WHERE store_id = ?');
    $stmt->execute([$r53sStore]);
    $count = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    return $count;
};
// Totals of 30 and 365 days and the 365-day best sellers, as the orders sum them.
$r53sCheck = static function (string $label) use ($r53sStore): void {
    $far = '9999-12-31 00:00:00';
    $diff = [];
    foreach ([30, 365] as $period) {
        $since = SalesReport::sinceUtc($period);
        $raw = SalesReport::revenueRows($r53sStore, $since, $far)['xts'] ?? ['orders' => 0, 'revenue' => 0];
        $report = SalesReport::totals($r53sStore, $since)['xts'] ?? ['orders' => 0, 'revenue' => 0];
        if ([$raw['orders'], $raw['revenue']] !== [$report['orders'], $report['revenue']]) $diff[] = "totals {$period}: " . json_encode([$raw, $report]);
    }
    $since = SalesReport::sinceUtc(365);
    $top = [];
    foreach (SalesReport::topProducts($r53sStore, $since, 50)['xts'] ?? [] as $row) $top[$row['product_id']] = [$row['quantity'], $row['revenue']];
    $rawTop = [];
    foreach (SalesReport::productGroups($r53sStore, $since) as $group) {
        if ($group['currency'] === 'xts') $rawTop[$group['product_id']] = [$group['quantity'], (int)round($group['revenue'])];
    }
    ksort($top);
    ksort($rawTop);
    if ($top !== $rawTop) $diff[] = 'top: ' . json_encode([$rawTop, $top]);
    assert_true($diff === [], "R53 daily sales: {$label} - the reports read as the orders sum them" . ($diff === [] ? '' : ' (' . implode('; ', $diff) . ')'));
};
try {
    if ($r53sCreated) (require TEST_CLI_DIR . '/../migrations/20261121120000_sales_daily.php')->up($r53sDb, Db::isSqlite());
    $r53sMonthlyCreated = !Db::tableExists('sales_monthly_products', $r53sDb);
    if ($r53sMonthlyCreated && is_file(TEST_CLI_DIR . '/../migrations/20261122090000_sales_monthly_products.php')) {
        (require TEST_CLI_DIR . '/../migrations/20261122090000_sales_monthly_products.php')->up($r53sDb, Db::isSqlite());
    }
    $r53sCategory = (int)$r53sDb->query('SELECT MIN(id) FROM categories')->fetchColumn();
    $r53sProduct = $r53sDb->prepare("INSERT INTO products (id, category_id, name, slug, sku, price, store_id) VALUES (?, ?, ?, ?, ?, 1000, ?)");
    foreach ([953101 => 'R53S-A', 953102 => 'R53S-B'] as $r53sId => $r53sSku) $r53sProduct->execute([$r53sId, $r53sCategory, $r53sSku, strtolower($r53sSku), $r53sSku, $r53sStore]);
    $r53sOrder = $r53sDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method,
        subtotal, total, currency, store_id, locale, created_at) VALUES (?, 'delivered', 'paid', 'R53S', 'r53s@example.test', '{}', 'card', ?, ?, 'XTS', ?, 'sk', ?)");
    $r53sLine = $r53sDb->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $r53sAt = static fn(int $days): string => (new DateTimeImmutable('today'))->modify("-{$days} days")->modify('+600 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $r53sByDay = [];
    for ($r53sDay = 5; $r53sDay <= 200; $r53sDay += 5) {
        $r53sTotal = 1000 + $r53sDay;
        $r53sOrder->execute(['R53S-' . bin2hex(random_bytes(5)), $r53sTotal, $r53sTotal, $r53sStore, $r53sAt($r53sDay)]);
        $r53sOrders[] = $r53sByDay[$r53sDay] = (int)$r53sDb->lastInsertId();
        $r53sLine->execute([$r53sByDay[$r53sDay], 953101 + $r53sDay % 2, 'R53S', 'R53S', 1, $r53sTotal, $r53sTotal]);
    }
    JobQueue::useBackend($r53sBackend);

    // 1. A build cut short keeps what it built; the next request continues from there and the worker job finishes it.
    $r53sBounded = property_exists('SalesDaily', 'requestSeconds');
    assert_true($r53sBounded, 'R53 daily sales: a report builds the daily totals within a time limit');
    if ($r53sBounded) {
    SalesDaily::refreshAll(true, 0.0, $r53sFinished);
    assert_true($r53sFinished === false, 'R53 daily sales: a rebuild out of time says it did not finish (' . var_export($r53sFinished, true) . ')');
    $r53sDb->prepare('DELETE FROM sales_daily_state WHERE state_key = ?')->execute(['build_queued_at']);
    SalesDaily::refresh($r53sStore, 0.0);
    $r53sFirst = $r53sState('built_until:' . $r53sStore);
    assert_true($r53sFirst !== '' && $r53sFirst < SalesDaily::openDay(), "R53 daily sales: a build out of time stops and records how far it got ('{$r53sFirst}')");
    SalesDaily::refresh($r53sStore, 0.0);
    $r53sSecond = $r53sState('built_until:' . $r53sStore);
    assert_true($r53sSecond > $r53sFirst, "R53 daily sales: the next request continues the build, not from the start ({$r53sFirst} -> {$r53sSecond})");
    assert_equals([SalesDaily::JOB], $r53sBackend->pushed, 'R53 daily sales: the request queues the worker job that builds the rest, once');
    SalesDaily::$requestSeconds = 0.0;
    $r53sCheck('while the days are built a batch per request');
    assert_true($r53sState('built_until:' . $r53sStore) < SalesDaily::openDay(), 'R53 daily sales: a report request built only its batch');
    SalesDaily::handleJob();
    assert_equals([SalesDaily::openDay(), [SalesDaily::JOB]], [$r53sState('built_until:' . $r53sStore), $r53sBackend->pushed],
        'R53 daily sales: the worker job builds the rest and does not queue itself again once done');
    $r53sCheck('after the worker job');
    }

    // 1b. The whole months of a 365-day report are read from the monthly sums; the best sellers are ranked in SQL, and a
    // product sold today (summed from the orders) still climbs above the built leader.
    $r53sMonthly = Db::tableExists('sales_monthly_products', $r53sDb);
    assert_true($r53sMonthly, 'R53 daily sales: the monthly product sums exist');
    if ($r53sMonthly) {
        $r53sMonths = $r53sDb->prepare("SELECT COUNT(DISTINCT month) FROM sales_monthly_products WHERE store_id = ? AND currency = 'xts'");
        $r53sMonths->execute([$r53sStore]);
        assert_true((int)$r53sMonths->fetchColumn() >= 6, 'R53 daily sales: the built months are summed per product');
        $r53sLeader = SalesReport::topProducts($r53sStore, SalesReport::sinceUtc(365), 1)['xts'][0]['product_id'] ?? null;
        $r53sOther = $r53sLeader === 953101 ? 953102 : 953101;
        $r53sOrder->execute(['R53S-' . bin2hex(random_bytes(5)), 900000, 900000, $r53sStore, gmdate('Y-m-d H:i:s')]);
        $r53sOrders[] = $r53sToday = (int)$r53sDb->lastInsertId();
        $r53sLine->execute([$r53sToday, $r53sOther, 'R53S', 'R53S', 1, 900000, 900000]);
        // The ranked read returns the built leader and, because today's order can lift it above the leader, the other
        // product's built figures too.
        $r53sBuilt = SalesDaily::productGroups($r53sStore, SalesDaily::dayOf(SalesReport::sinceUtc(365)), SalesDaily::builtUntil($r53sStore), 1);
        assert_equals([$r53sLeader], array_column(array_filter($r53sBuilt, static fn(array $g): bool => $g['currency'] === 'xts'), 'product_id'),
            'R53 daily sales: the ranked read returns only the leader of the built days');
        $r53sTop = SalesReport::topProducts($r53sStore, SalesReport::sinceUtc(365), 1)['xts'] ?? [];
        $r53sRaw = SalesReport::productGroups($r53sStore, SalesReport::sinceUtc(365));
        $r53sOtherRevenue = (int)round($r53sRaw['xts|' . $r53sOther . '|0']['revenue'] ?? 0);
        assert_equals([$r53sOther, $r53sOtherRevenue], [$r53sTop[0]['product_id'] ?? null, $r53sTop[0]['revenue'] ?? null],
            "R53 daily sales: a product sold today overtakes the built leader {$r53sLeader} in the top 1, with all of its revenue");
        $r53sCheck('with a large order today');
    }
    if ($r53sBounded) {

    // 2. Old orders changed on three days: one batch per request, the days not built yet read from the orders.
    foreach ([30, 90, 150] as $r53sDay) {
        $r53sDb->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$r53sByDay[$r53sDay]]);
        SalesDaily::touchOrders([$r53sByDay[$r53sDay]]);
    }
    $r53sCheck('after three old orders were cancelled, built a day per request');
    assert_true($r53sChangesLeft() === 0, 'R53 daily sales: the changed days are built and their changes deleted after a few requests (' . $r53sChangesLeft() . ' left)');
    SalesDaily::$requestSeconds = 2.0;
    }

    // 3a. 400 000 logged changes (a bulk edit) are built within 24 MB more memory, in a child shop of its own (PHP 8.1
    // cannot reset the suite's peak memory).
    $r53sRoot = test_fixture_path('r53-sales-daily');
    mkdir($r53sRoot);
    file_put_contents($r53sRoot . '/child.php', <<<'PHP'
<?php
[, $root] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . __DIR__ . '/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
(new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
Bootstrap::initStoreContext('/', '', 'default');
$order = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, created_at)
    VALUES (?, 'delivered', 'paid', 'R53S', 'r53s@example.test', '{}', 'card', 1000, 1000, 'eur', 'default', 'sk', ?)");
for ($i = 1; $i <= 40; $i++) $order->execute(["R53S-{$i}", gmdate('Y-m-d H:i:s', time() - $i * 5 * 86400)]);
SalesDaily::refreshAll();
$values = implode(',', array_fill(0, 500, "('default', ?)"));
$insert = $db->prepare("INSERT INTO sales_daily_changes (store_id, created_at) VALUES {$values}");
$db->beginTransaction();
for ($i = 0; $i < 800; $i++) $insert->execute(array_map(static fn(int $j): string => gmdate('Y-m-d H:i:s', time() - (10 + $j % 40) * 86400), range(0, 499)));
$db->commit();
gc_collect_cycles();
ini_set('memory_limit', (string)(memory_get_usage() + 24 * 1048576));
SalesDaily::refresh('default');
$left = (int)$db->query('SELECT COUNT(*) FROM sales_daily_changes')->fetchColumn();
echo "r53s-ok {$left}\n";
PHP);
    $r53sOut = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($r53sRoot . '/child.php') . ' ' . escapeshellarg(dirname(TEST_CLI_DIR)) . ' 2>&1', $r53sOut);
    assert_true(in_array('r53s-ok 0', $r53sOut, true), 'R53 daily sales: 400 000 logged changes are built within 24 MB and deleted (' . substr(implode(' | ', $r53sOut), 0, 300) . ')');

    // 3b. 100 000 logged changes in the suite's shop: built, deleted, the reports still right.
    $r53sValues = [];
    for ($i = 0; $i < 500; $i++) $r53sValues[] = '(?, ?)';
    $r53sInsert = $r53sDb->prepare('INSERT INTO sales_daily_changes (store_id, created_at) VALUES ' . implode(',', $r53sValues));
    $r53sParams = [];
    for ($i = 0; $i < 500; $i++) array_push($r53sParams, $r53sStore, $r53sAt(10 + 5 * ($i % 3)));
    $r53sDb->beginTransaction();
    for ($i = 0; $i < 200; $i++) $r53sInsert->execute($r53sParams);
    $r53sDb->commit();
    SalesDaily::refresh($r53sStore, 2.0);
    assert_equals(0, $r53sChangesLeft(), 'R53 daily sales: the 100 000 changes are deleted once their days are built');
    $r53sCheck('after 100 000 logged changes');

    // 4. One of the tables missing: the reports sum the orders instead of failing.
    // SQLite drops no table while a statement of the connection is mid-read (earlier scenarios leave some open).
    foreach ($GLOBALS as $r53sValue) {
        if ($r53sValue instanceof PDOStatement) $r53sValue->closeCursor();
    }
    $r53sDb->exec('DROP TABLE sales_daily_products');
    Db::forgetSchema($r53sDb);
    try {
        $r53sCheck('with sales_daily_products missing');
    } finally {
        (require TEST_CLI_DIR . '/../migrations/20261121120000_sales_daily.php')->up($r53sDb, Db::isSqlite());
        Db::forgetSchema($r53sDb);
    }
    assert_true(str_contains((string)file_get_contents(TEST_CLI_DIR . '/worker.php'), "SalesDaily::JOB     => [SalesDaily::class, 'handleJob']"),
        'R53 daily sales: cli/worker.php runs the build job');
} catch (Throwable $e) {
    assert_true(false, 'R53 daily sales (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    JobQueue::useBackend(null);
    if (property_exists('SalesDaily', 'requestSeconds')) SalesDaily::$requestSeconds = 2.0;
    if ($r53sDb->inTransaction()) $r53sDb->rollBack();
    if ($r53sOrders !== []) {
        $r53sIds = implode(',', $r53sOrders);
        $r53sDb->exec("DELETE FROM order_items WHERE order_id IN ({$r53sIds})");
        $r53sDb->exec("DELETE FROM orders WHERE id IN ({$r53sIds})");
    }
    $r53sDb->exec('DELETE FROM products WHERE id IN (953101, 953102)');
    if ($r53sCreated) {
        foreach ($GLOBALS as $r53sValue) {
            if ($r53sValue instanceof PDOStatement) $r53sValue->closeCursor();
        }
        foreach (['sales_daily', 'sales_daily_products', 'sales_daily_changes', 'sales_daily_state', 'sales_monthly_products'] as $r53sTable) $r53sDb->exec("DROP TABLE IF EXISTS {$r53sTable}");
        Db::forgetSchema($r53sDb);
    } elseif (Db::tableExists('sales_daily_state', $r53sDb)) {
        if (!empty($r53sMonthlyCreated)) {
            $r53sDb->exec('DROP TABLE IF EXISTS sales_monthly_products');
            Db::forgetSchema($r53sDb);
        }
        SalesDaily::refreshAll(true);
    }
}
