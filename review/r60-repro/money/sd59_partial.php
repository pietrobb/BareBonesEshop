<?php
// php sd59_partial.php driver target : each of the four sales_daily tables dropped in turn -> reports, touchOrders, worker, refreshAll
require __DIR__ . '/lib50.php';
[, $driver, $target] = $argv;
$db = r50_boot($driver, $target, true); r36_store('default');
$wh = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' ORDER BY is_default DESC, id LIMIT 1")->fetchColumn();
$p = r50_product($db, 'default', 'PT', 1990, 'eur', [$wh => 1000]);
r50_set_pricing(); $r = r36_checkout([[$p, 1]], ['country' => 'SK', 'zip' => '81101', 'payment_method' => 'card', 'shipping_method' => (string)$db->query("SELECT code FROM shipping_methods WHERE active=1 LIMIT 1")->fetchColumn()]); $oid = (int)($r['order_id'] ?? 0); if (!$oid) { var_dump($r); exit(1); }
$db->prepare("UPDATE orders SET payment_status='paid', status='paid', created_at=? WHERE id=?")->execute([gmdate('Y-m-d H:i:s', time() - 5 * 86400), $oid]);
SalesDaily::refreshAll();
$mig = R36_ROOT . '/migrations/20261121120000_sales_daily.php';
foreach (['sales_daily', 'sales_daily_products', 'sales_daily_changes', 'sales_daily_state', 'sales_monthly_products'] as $t) {
    $db->exec("DROP TABLE $t"); Db::forgetSchema($db); SalesReport::$refreshFailed = [];
    $res = [];
    foreach (['totals7' => fn() => SalesReport::totals('default', SalesReport::sinceUtc(7))['eur']['revenue'] ?? 0,
              'trend30' => fn() => array_sum(array_column(SalesReport::trend('default', 30)['eur'] ?? [], 'revenue')),
              'top365' => fn() => count(SalesReport::topProducts('default', SalesReport::sinceUtc(365))['eur'] ?? []),
              'touch' => function () use ($oid) { SalesDaily::touchOrders([$oid]); return 'ok'; },
              'refund' => function () use ($oid) { $rid = 're_p' . bin2hex(random_bytes(3)); PaymentService::bookProviderRefund($oid, 'stripe', $rid, 1, 'refund.succeeded', 'stripe_refund_' . $rid, []); return 'ok'; },
              'worker' => function () { SalesDaily::handleJob(); return 'ok'; },
              'refreshAll' => fn() => SalesDaily::refreshAll() ? 'true' : 'false'] as $k => $f) {
        try { $res[$k] = $f(); } catch (Throwable $e) { $res[$k] = 'EXC ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 100); }
    }
    echo "without $t: ", json_encode($res), "\n";
    // restore all tables: rerun the migrations that create them
    foreach (glob(R36_ROOT . '/migrations/*sales*') as $m) { try { (require $m)->up($db, Db::isSqlite()); } catch (Throwable $e) { echo "  re-migrate ", basename($m), ": ", substr($e->getMessage(), 0, 80), "\n"; } }
    Db::forgetSchema($db);
    if (!SalesDaily::available()) echo "  (tables not restored)\n";
}
