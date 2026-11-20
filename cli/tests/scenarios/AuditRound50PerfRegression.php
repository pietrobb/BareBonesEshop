<?php

// Review round 20 (R50, data and performance): orders placed before orders.oss_net existed kept no net when a signed
// update ran migration 20261113190000 with the old release's Db, so every checkout counted them from their lines
// (6.7-8.8 s at 20 000 open orders, out of memory at 330 000); a GDPR erasure on MySQL compared LOWER(e-mail) and read
// (and locked) every order of the shop; at 500 000 orders MySQL sorted the whole store for the admin order list and a
// 90-day report summed every refund of the store's history.
echo "\n--- Audit round 50: oss_net backfill, GDPR e-mail lookups, admin list and report indexes ---\n";
$r50pRoot = test_fixture_path('perf-r50');
mkdir($r50pRoot);
$r50pChild = $r50pRoot . '/check.php';
file_put_contents($r50pChild, <<<'PHP'
<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $dir . '/perf.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r50p_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
$checks = [];
$bench = [];
$check = static function (bool $ok, string $label) use (&$checks): void { $checks[] = [$label, $ok]; };
$section = static function (string $name, callable $run) use ($check, $db): void {
    try {
        $run();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $check(false, "{$name} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
};
try {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $stores = Bootstrap::stores();
    $stores['stores']['default']['oss_enabled'] = false;
    $stores['stores']['default']['legal']['country'] = 'SK';
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
    Bootstrap::initStoreContext('/', '', 'default');
    $year = (int)ShopTime::date('Y');
    $orderStmt = $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
        total, net_total, currency, status, payment_status, payment_method, shipping_method, store_id, vat_scenario, created_at)
        VALUES (?, 'R50P', ?, '', '{\"country\":\"AT\"}', 120, 0, 20, 0, 120, 100, 'eur', ?, 'paid', 'bank_transfer', 'gls', 'default', ?, ?)");
    $place = static function (string $number, string $status = 'pending', string $scenario = 'oss', string $email = 'r50p@example.test') use ($orderStmt, $db, $year): int {
        $orderStmt->execute([$number, $email, $status, $scenario, $year . '-01-02 12:00:00']);
        return (int)$db->lastInsertId();
    };
    $net = static function (int $id) use ($db): ?int {
        $value = $db->query("SELECT oss_net FROM orders WHERE id = {$id}")->fetchColumn();
        return $value === null ? null : (int)$value;
    };
    $sum = static fn(): int => OssThreshold::worstYear($db, 'default', $stores['stores']['default'], $stores['stores'])['sum'];

    // --- oss_net of orders placed before the column existed ---------------------------------------------------------
    $section('oss_net backfill', static function () use ($db, $root, $driver, $place, $net, $sum, $check, &$bench): void {
        $open = $place('R50P-OPEN');
        $cancelled = $place('R50P-CANCELLED', 'cancelled');
        $invoiced = $place('R50P-INVOICED');
        $db->beginTransaction();
        $db->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type)
            VALUES (?, 'default', 'R50P-1', ?, '', 100, 20, 120, 'eur', 'issued', 'r50p-1', 'oss', 'invoice')")->execute([$invoiced, ShopTime::date('Y') . '-01-03']);
        OssThreshold::record($db, (int)$db->lastInsertId());
        $db->commit();
        $b2b = $place('R50P-B2B', 'pending', 'reverse_charge');
        // As a signed update from an older release left them: the column is there, no net stored.
        $db->exec('UPDATE orders SET oss_net = NULL');
        $migration = $root . '/migrations/20261120120000_orders_oss_net_backfill.php';
        $check(is_file($migration), 'a migration stores the net of the orders an older release left without one');
        if (!is_file($migration)) return;
        (require $migration)->up($db, $driver === 'sqlite');
        $check([100, 0, 0, 0] === [$net($open), $net($cancelled), $net($invoiced), $net($b2b)],
            'the backfill stores what Orders::create() would have: the open distance sale its net, a cancelled, invoiced or B2B order 0 (' . json_encode([$net($open), $net($cancelled), $net($invoiced), $net($b2b)]) . ')');
        $check($sum() === 200, 'the threshold still counts the open order and the invoice once each');

        // Not backfilled yet (the migration ran under an older OssThreshold): the checkout counts it once and stores it.
        $db->exec("UPDATE orders SET oss_net = NULL WHERE id = {$open}");
        $check($sum() === 200 && $net($open) === 100, 'a checkout counts an order still without a net from its lines and stores the net after its read-only snapshot');
        $db->beginTransaction();
        $db->exec("UPDATE orders SET oss_net = NULL WHERE id = {$open}");
        $inTransaction = $sum();
        $db->commit();
        $check($inTransaction === 200 && $net($open) === null, 'inside a caller\'s transaction the count is right and nothing is written');

        $count = max(1, min(330000, (int)(getenv('R50_PERF_ORDERS') ?: 1500)));
        $db->beginTransaction();
        for ($i = 0; $i < $count; $i++) $place('R50P-B' . $i);
        $db->exec('UPDATE orders SET oss_net = NULL');
        $db->commit();
        $start = microtime(true); $first = $sum(); $firstMs = (microtime(true) - $start) * 1000;
        $left = (int)$db->query("SELECT COUNT(*) FROM orders WHERE oss_net IS NULL AND status NOT IN ('cancelled', 'refunded', 'failed') AND vat_scenario = 'oss'
            AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.order_id = orders.id AND i.type = 'invoice' AND i.status = 'issued')")->fetchColumn();
        $start = microtime(true); $second = $sum(); $secondMs = (microtime(true) - $start) * 1000;
        $check($first === 100 * ($count + 1) + 100 && $second === $first && $left === 0,
            "{$count} open orders without a net are counted once from their lines, 500 at a time, and the next checkout reads the stored nets (left " . $left . ')');
        $bench += ['orders' => $count, 'firstMs' => round($firstMs, 1), 'secondMs' => round($secondMs, 1)];
        $db->prepare("UPDATE orders SET status = 'cancelled' WHERE order_number LIKE 'R50P-B%'")->execute();
    });

    // --- GDPR e-mail lookups ------------------------------------------------------------------------------------------
    $section('GDPR e-mail lookup', static function () use ($db, $driver, $place, $check): void {
        $order = $place('R50P-GDPR', 'cancelled', 'domestic', 'R50P-Mixed@Example.TEST');
        $prepare = new ReflectionMethod(GdprService::class, 'prepare');
        $stmt = $prepare->invoke(null, $db, 'SELECT o.id FROM orders o WHERE o.store_id = :store AND LOWER(o.customer_email) = LOWER(:owner_email)');
        $stmt->execute([':store' => 'default', ':owner_email' => 'r50p-mixed@example.test']);
        $check(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) === [$order], 'the erasure finds the order whatever the case of its e-mail');
        if ($driver === 'mysql') {
            $check(!str_contains($stmt->queryString, 'LOWER('), 'MySQL: with the *_ci collation the e-mail is compared as stored (LOWER() bypassed the index)');
            $plan = $db->prepare('EXPLAIN ' . $stmt->queryString);
            $plan->execute([':store' => 'default', ':owner_email' => 'r50p-mixed@example.test']);
            $keys = array_column($plan->fetchAll(PDO::FETCH_ASSOC), 'key');
            $check($keys === ['idx_ord_email'], 'MySQL: the erasure looks the customer\'s orders up through idx_ord_email, not by reading every order (' . json_encode($keys) . ')');
        } else {
            $check(str_contains($stmt->queryString, 'LOWER(o.customer_email) = LOWER(:owner_email)'), 'SQLite: LOWER() stays (its = is case-sensitive)');
        }
    });

    // --- Admin order list and sales reports -----------------------------------------------------------------------------
    $section('admin list and reports', static function () use ($db, $driver, $check): void {
        if ($driver === 'mysql') {
            $check(Db::forceIndex('orders', 'idx_orders_store_created') === ' FORCE INDEX (idx_orders_store_created)' && Db::forceIndex('orders', 'idx_r50p_missing') === '',
                'MySQL: the list hint names an index only when it exists');
            $check(str_contains(OrderExport::listIndex('pending', ''), 'idx_orders_store_status_created') && str_contains(OrderExport::listIndex('', 'paid'), 'idx_orders_store_payment_created')
                && str_contains(OrderExport::filters(['period' => 'all'])['index'], 'idx_orders_store_created'),
                'MySQL: the order list reads the index whose columns are its filters, in created_at order');
        } else {
            $check(Db::forceIndex('orders', 'idx_orders_store_created') === '' && OrderExport::filters([])['index'] === '', 'SQLite: no index hint');
        }
        $since = SalesReport::sinceUtc(90);
        [$source, $params] = SalesReport::revenueSource('default', $since);
        $check(str_contains($source, 'created_at >= :rsince') && ($params[':rsince'] ?? null) === $since, 'a 90-day report sums only the refunds of orders placed in those 90 days');
        $sql = "SELECT LOWER(o.currency) AS currency, COUNT(*) AS orders, COALESCE(SUM(o.total - COALESCE(r.refunded, 0)), 0) AS revenue
            FROM {$source} WHERE " . SalesReport::revenueCondition() . " AND o.created_at >= :since GROUP BY LOWER(o.currency)";
        $plan = $db->prepare(($driver === 'mysql' ? 'EXPLAIN ' : 'EXPLAIN QUERY PLAN ') . $sql);
        $plan->execute($params + [':store' => 'default', ':since' => $since]);
        $rows = $plan->fetchAll(PDO::FETCH_ASSOC);
        $text = json_encode($rows);
        $ranged = $driver === 'mysql'
            ? array_filter($rows, static fn(array $r): bool => ($r['table'] ?? '') === 'o' && ($r['key'] ?? '') === 'idx_orders_store_payment_created' && ($r['type'] ?? '') === 'range') !== []
            : preg_match('/SEARCH o USING (COVERING )?INDEX idx_orders_store_\w+ \(store_id=\?.*created_at>\?\)/', $text) === 1;
        $check($ranged, 'the report reads only the store\'s orders of the period through an index range, not every order of the store (' . substr($text, 0, 600) . ')');
        SalesReport::totals('default', $since);
        SalesReport::trend('default', 90);
        SalesReport::topProducts('default', $since);
        $check(true, 'totals, trend and top products run with the bounded refunds');
    });
    echo 'R50PRESULT' . json_encode(['checks' => $checks, 'bench' => $bench]);
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
foreach (['sqlite', 'mysql'] as $r50pDriver) {
    if ($r50pDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R50 perf native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
    $r50pProcess = proc_open([PHP_BINARY, $r50pChild, dirname(TEST_CLI_DIR), $r50pRoot, $r50pDriver], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r50pPipes);
    fclose($r50pPipes[0]);
    $r50pRaw = stream_get_contents($r50pPipes[1]) . "\n" . stream_get_contents($r50pPipes[2]);
    fclose($r50pPipes[1]); fclose($r50pPipes[2]);
    $r50pExit = proc_close($r50pProcess);
    $r50pAt = strrpos($r50pRaw, 'R50PRESULT');
    $r50pResult = $r50pAt === false ? null : json_decode(strtok(substr($r50pRaw, $r50pAt + 10), "\n"), true);
    assert_true($r50pExit === 0 && is_array($r50pResult), "R50 perf {$r50pDriver}: the isolated shop completes (" . ($r50pAt === false ? substr($r50pRaw, 0, 1500) : '') . ')');
    foreach ($r50pResult['checks'] ?? [] as [$r50pLabel, $r50pOk]) assert_true($r50pOk, "R50 perf {$r50pDriver}: {$r50pLabel}");
    print 'R50 perf benchmark ' . $r50pDriver . ': ' . json_encode($r50pResult['bench'] ?? []) . "\n";
}
