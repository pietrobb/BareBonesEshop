<?php
// R59 sales_daily at scale. php sd59_big.php driver target mode [args]
//  gen N            : N orders over 2 years (bulk SQL), tables built by refreshAll()
//  reset            : forget built days (as a migration that left the build / timezone change)
//  changes N        : N change rows spread over the orders' days (as N late changes nobody opened a report for)
//  req dash|reports : one admin page's report calls (dashboard: refreshOnce + totals(1) + trend(14); reports: refreshOnce + trend(365) + top(365))
//  worker           : one worker job (handleJob)
//  check            : report figures vs. the live SQL path (revenueRows), per day for 365 d
require __DIR__ . '/lib50.php';
[, $driver, $target, $mode] = $argv;
$db = r50_boot($driver, $target, $mode === 'gen');
r36_store('default');
$t0 = microtime(true);
$exitInfo = function () use ($db, $t0) {
    $st = $db->query("SELECT state_key, value FROM sales_daily_state WHERE state_key LIKE 'built_until:%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    return sprintf('elapsed=%.2fs peak=%dMB changes_left=%d built_until=%s cpu=%.2fs', microtime(true) - $t0, memory_get_peak_usage(true) / 1048576,
        $db->query('SELECT COUNT(*) FROM sales_daily_changes')->fetchColumn(), json_encode($st), (function () { $r = getrusage(); return $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6; })());
};
if ($mode === 'gen') {
    $n = (int)$argv[4];
    $cat = (int)$db->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
    if (!$cat) { $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'b', 'B', 'b', 0)"); $cat = (int)$db->lastInsertId(); }
    for ($p = 1; $p <= 300; $p++) $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, ?, ?, ?, 1000, 0, 'active')")->execute([$cat, "BP$p", "bp$p", "BP $p"]);
    $pmin = (int)$db->query("SELECT MIN(id) FROM products WHERE sku LIKE 'BP%'")->fetchColumn();
    mt_srand(7); $now = time();
    $db->beginTransaction();
    $batch = 1000; $oid0 = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM orders')->fetchColumn();
    $statuses = [['delivered', 'paid'], ['paid', 'paid'], ['pending', 'pending'], ['cancelled', 'cancelled'], ['delivered', 'partially_refunded'], ['delivered', 'paid']];
    for ($i = 0; $i < $n; $i += $batch) {
        $ov = []; $iv = []; $rv = [];
        for ($k = 0; $k < $batch && $i + $k < $n; $k++) {
            $id = $oid0 + $i + $k + 1; [$st, $ps] = $statuses[mt_rand(0, 5)];
            $created = gmdate('Y-m-d H:i:s', $now - mt_rand(3600, 730 * 86400)); $total = mt_rand(500, 50000);
            array_push($ov, $id, "BIG-$id", $st, $ps, $total, $total, $created);
            $lines = mt_rand(1, 2); $left = $total;
            for ($l = 0; $l < $lines; $l++) { $lt = $l === $lines - 1 ? $left : intdiv($total, 2); $left -= $lt; array_push($iv, $id, $pmin + mt_rand(0, 299), 'BP', 'BP', mt_rand(1, 3), $lt, $lt); }
            if ($ps === 'partially_refunded') array_push($rv, $id, mt_rand(1, $total), "big-$id");
        }
        $c = intdiv(count($ov), 7);
        $db->prepare("INSERT INTO orders (id, order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, is_synthetic, created_at) VALUES "
            . implode(',', array_fill(0, $c, "(?, ?, ?, ?, 'B', 'b@example.test', '{}', 'card', ?, ?, 'EUR', 'default', 'sk', 0, ?)")))->execute($ov);
        $c = intdiv(count($iv), 7);
        $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES " . implode(',', array_fill(0, $c, '(?, ?, ?, ?, ?, ?, ?)')))->execute($iv);
        if ($rv) { $c = intdiv(count($rv), 3); $db->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id, created_at, updated_at) VALUES " . implode(',', array_fill(0, $c, "(?, 'stripe', 'refund_succeeded', ?, 'eur', ?, 'default', '2026-01-01 00:00:00', '2026-01-01 00:00:00')")))->execute($rv); }
        if (($i / $batch) % 20 === 19) { $db->commit(); $db->beginTransaction(); }
    }
    $db->commit();
    if ($driver === 'mysql') foreach (['orders', 'order_items', 'payment_transactions'] as $tb) $db->query("ANALYZE TABLE $tb")->fetchAll();
    printf("inserted %d orders in %.1f s\n", $n, microtime(true) - $t0);
    $t = microtime(true); SalesDaily::refreshAll(); printf("refreshAll %.1f s\n", microtime(true) - $t);
    echo $exitInfo(), "\n"; exit;
}
if ($mode === 'reset') {
    $db->exec("DELETE FROM sales_daily_state WHERE state_key LIKE 'built_until:%' OR state_key LIKE 'timezone:%' OR state_key = 'build_queued_at'"); $db->exec('DELETE FROM sales_daily'); $db->exec('DELETE FROM sales_daily_products');
    if (Db::tableExists('sales_monthly_products')) $db->exec('DELETE FROM sales_monthly_products');
    $db->exec('DELETE FROM sales_daily_changes');
    echo "reset\n"; exit;
}
if ($mode === 'changes') {
    $n = (int)$argv[4];
    $rows = $db->query("SELECT created_at FROM orders WHERE store_id='default' ORDER BY id LIMIT 150000")->fetchAll(PDO::FETCH_COLUMN);
    mt_srand(9);
    $db->beginTransaction();
    for ($i = 0; $i < $n; $i += 1000) {
        $v = []; for ($k = 0; $k < 1000 && $i + $k < $n; $k++) array_push($v, 'default', $rows[mt_rand(0, count($rows) - 1)]);
        $db->prepare('INSERT INTO sales_daily_changes (store_id, created_at) VALUES ' . implode(',', array_fill(0, intdiv(count($v), 2), '(?, ?)')))->execute($v);
    }
    // real changes among them: 3000 orders cancelled / refunded, logged through touchOrders()
    $ids = $db->query("SELECT id FROM orders WHERE store_id='default' AND payment_status='paid' AND status <> 'cancelled' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    shuffle($ids); $ids = array_slice($ids, 0, 3000);
    foreach (array_chunk($ids, 500) as $ch) { $db->exec("UPDATE orders SET status='cancelled' WHERE id IN (" . implode(',', $ch) . ")"); SalesDaily::touchOrders($ch); }
    $db->commit(); echo "inserted $n change rows + 3000 real cancels\n"; exit;
}
if ($mode === 'req') {
    register_shutdown_function(function () use ($exitInfo) { $e = error_get_last(); echo "  EXIT ", $e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true) ? 'FATAL(' . substr($e['message'], 0, 120) . ') ' : 'ok ', $exitInfo(), "\n"; });
    $kind = $argv[4];
    $o = SalesReport::refreshOnce();
    if ($kind === 'dash') { $a = SalesReport::totals('default', SalesReport::sinceUtc(1)); $tr = SalesReport::trend('default', 14); echo "dash today=", json_encode($a['eur'] ?? []), ' trend14=', json_encode(SalesReport::totalsOf($tr)['eur'] ?? []), "\n"; }
    else { $tr = SalesReport::trend('default', 365); $top = SalesReport::topProducts('default', SalesReport::sinceUtc(365), 10, true); echo "reports 365=", json_encode(SalesReport::totalsOf($tr)['eur'] ?? []), " top1=", json_encode($top['eur'][0] ?? null), "\n"; }
    unset($o); exit;
}
if ($mode === 'worker') {
    register_shutdown_function(function () use ($exitInfo) { $e = error_get_last(); echo "  WORKER EXIT ", $e && in_array($e['type'], [E_ERROR], true) ? 'FATAL(' . substr($e['message'], 0, 120) . ') ' : 'ok ', $exitInfo(), "\n"; });
    SalesDaily::handleJob(); exit;
}
if ($mode === 'check') {
    $far = "9999-12-31 00:00:00"; $bad = 0; echo "changes before check: ", $db->query("SELECT COUNT(*) FROM sales_daily_changes")->fetchColumn(), "\n";
    foreach ([7, 30, 90, 365] as $p) {
        $since = SalesReport::sinceUtc($p);
        $live = SalesReport::revenueRows('default', $since, $far)['eur'] ?? [];
        $got = SalesReport::totals('default', $since)['eur'] ?? [];
        if (($live['orders'] ?? 0) !== ($got['orders'] ?? 0) || ($live['revenue'] ?? 0) !== ($got['revenue'] ?? 0)) { $bad++; echo "MISMATCH {$p}d report=", json_encode($got), " live=", json_encode($live), "\n"; }
    }
    // full built-table vs live per day
    $built = SalesDaily::builtUntil('default');
    $days = SalesDaily::days('default', '2000-01-01', $built)['eur'] ?? [];
    $liveDays = [];
    foreach ($db->query("SELECT created_at, total, id, status, payment_status FROM orders WHERE store_id='default' AND is_synthetic=0")->fetchAll() as $o) {}
    $nd = 0;
    foreach ($days as $d => $f) { $l = SalesReport::revenueRows('default', SalesDaily::dayStartUtc($d), SalesDaily::dayStartUtc(SalesDaily::nextDay($d)))['eur'] ?? ['orders' => 0, 'revenue' => 0]; if ($l['orders'] !== $f['orders'] || $l['revenue'] !== $f['revenue']) { $nd++; if ($nd < 5) echo "DAY $d built=", json_encode($f), " live=", json_encode($l), "\n"; } }
    echo "check: period mismatches=$bad, built days=", count($days), " built-day mismatches=$nd builtUntil=$built ", $exitInfo(), "\n"; exit;
}
