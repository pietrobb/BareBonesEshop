<?php
// MySQL deadlock stress. php dl59.php setup db | php dl59.php child db mode start dur idx | php dl59.php check db
// modes: rev (variant carts of two products in reverse order), diff (checkouts of different simple products),
//        stock (admin stock set of the checkout SKUs), edit (admin edit of pending orders), cancel (cancel pending orders)
require __DIR__ . '/lib50.php';
require __DIR__ . '/check50.php';
[, $role, $target] = $argv;
$db = r50_boot('mysql', $target, $role === 'setup');
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$wh = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
if ($role === 'setup') {
    $db->prepare("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('aux', 'Aux', 5, 0, 'default')")->execute(); $aux = (int)$db->lastInsertId();
    foreach (['VA', 'VB'] as $p) {
        $pid = r50_product($db, 'default', $p, 1500, 'eur', []);
        foreach (['RED', 'BLUE'] as $i => $c) { $db->prepare("INSERT INTO variants (product_id, sku, name, stock, sort_order) VALUES (?, ?, ?, 0, ?)")->execute([$pid, "$p-$c", $c, $i]); Warehouse::setStock($wh, "$p-$c", 1000000, 'count', 'dl', "o-$p-$c"); Warehouse::syncProductStock(["$p-$c"]); }
    }
    for ($k = 1; $k <= 8; $k++) r50_product($db, 'default', "S$k", 990 + $k, 'eur', [$wh => 1000000, $aux => 1000]);
    echo "setup ok\n"; exit;
}
if ($role === 'check') {
    $log = []; r50_drain($log, 2000, true);
    $v = array_filter(r50_check($db, false), fn($x) => !str_starts_with($x, 'ENTITLEMENT'));
    $bad = Db::get()->query("SELECT o.id, o.total, (SELECT SUM(amount) FROM payment_transactions t WHERE t.order_id=o.id AND t.status='paid') paid FROM orders o WHERE o.payment_status='paid'")->fetchAll();
    $bad = array_filter($bad, fn($r) => (int)$r['paid'] !== (int)$r['total']);
    echo "paid at another total: ", count($bad), " ", json_encode(array_slice(array_values($bad), 0, 5)), "\n";
    echo "orders=", $db->query('SELECT COUNT(*) FROM orders')->fetchColumn(), " violations=", count($v), " ", json_encode(array_slice(array_values($v), 0, 8)), "\n"; exit;
}
[, , , $mode, $start, $dur, $idx] = $argv;
$var = []; foreach ($db->query("SELECT v.id, v.sku, v.product_id FROM variants v")->fetchAll() as $r) $var[$r['sku']] = [(int)$r['product_id'], (int)$r['id']];
$simple = []; foreach ($db->query("SELECT id, sku FROM products WHERE sku LIKE 'S%'")->fetchAll() as $r) $simple[$r['sku']] = (int)$r['id'];
while (microtime(true) < (float)$start) usleep(300);
$end = (float)$start + (float)$dur; $res = []; $c = function ($k) use (&$res) { $res[$k] = ($res[$k] ?? 0) + 1; };
$checkout = function (array $lines) use ($c) {
    $r = r36_checkout($lines, ['country' => 'SK', 'payment_method' => 'bank_transfer', 'shipping_method' => 'gls']);
    if (!empty($r['order_id'])) { $c('order'); return (int)$r['order_id']; }
    $c('refused ' . ($r['error_code'] ?? json_encode($r))); return 0;
};
while (microtime(true) < $end) {
    try {
        switch ($mode) {
            case 'rev':
                $lines = $idx % 2 ? [$var['VA-RED'], $var['VB-RED']] : [$var['VB-BLUE'], $var['VA-BLUE']];
                $checkout(array_map(fn($x) => [$x[0], 1, $x[1]], $lines)); break;
            case 'diff':
                $keys = array_keys($simple); $k = $keys[($idx * 3 + mt_rand(0, 1)) % count($keys)];
                $checkout([[$simple[$k], 1, null]]); break;
            case 'mix':  // carts of several simple products in random order
                $keys = array_keys($simple); shuffle($keys); $checkout(array_map(fn($k) => [$simple[$k], 1, null], array_slice($keys, 0, mt_rand(2, 4)))); break;
            case 'stock':
                $keys = array_merge(array_keys($simple), array_keys($var)); $sku = $keys[mt_rand(0, count($keys) - 1)];
                $avail = Warehouse::totalAvailable($sku); Warehouse::setAvailable($sku, max(0, $avail + mt_rand(-3, 5)), 'manual_adjustment', 'admin_stock', $avail); $c('stock'); break;
            case 'edit':
                $oid = (int)Db::get()->query("SELECT id FROM orders WHERE status='pending' AND payment_status='pending' ORDER BY RAND() LIMIT 1")->fetchColumn(); if (!$oid) { usleep(20000); break; }
                $keys = array_keys($simple); shuffle($keys); $lines = [];
                foreach (array_slice($keys, 0, mt_rand(1, 3)) as $k) $lines[] = ['product_id' => $simple[$k], 'variant_id' => null, 'qty' => mt_rand(1, 2), 'sku' => $k];
                $r = ManualOrder::editItems($oid, $lines); $c('edit ' . ($r['ok'] ? 'ok' : $r['error'])); break;
            case 'editlc':  // admin edit with the SKUs typed in lower case (ManualOrder::parseLines keeps the typed SKU)
                $oid = (int)Db::get()->query("SELECT id FROM orders WHERE status='pending' AND payment_status='pending' ORDER BY RAND() LIMIT 1")->fetchColumn(); if (!$oid) { usleep(20000); break; }
                $keys = array_keys($simple); shuffle($keys);
                $parsed = ManualOrder::parseLines(implode("\n", array_map(fn($k) => strtolower($k) . ' ' . mt_rand(1, 2), array_slice($keys, 0, mt_rand(1, 3)))));
                if ($parsed['errors']) { $c('parse error ' . json_encode($parsed['errors'])); break; }
                $r = ManualOrder::editItems($oid, $parsed['lines']); $c('edit ' . ($r['ok'] ? 'ok' : $r['error'])); break;
            case 'hook':  // provider capture of a pending order at the total read before (raced with edits)
                $row = Db::get()->query("SELECT id, total FROM orders WHERE status='pending' AND payment_status='pending' ORDER BY RAND() LIMIT 1")->fetch(); if (!$row) { usleep(20000); break; }
                usleep(mt_rand(0, 3000));
                try { PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => 'h' . $row['id'] . '-' . mt_rand(), 'data' => ['order_id' => (int)$row['id'], 'amount' => (int)$row['total'], 'currency' => 'eur', 'payment_intent' => 'pi_' . $row['id']]], 'stripe'); $c('hook ok'); }
                catch (RuntimeException $e) { if (str_contains($e->getMessage(), 'does not match')) $c('hook mismatch'); else throw $e; }
                break;
            case 'cancel':
                $oid = (int)Db::get()->query("SELECT id FROM orders WHERE status='pending' ORDER BY RAND() LIMIT 1")->fetchColumn(); if (!$oid) { usleep(20000); break; }
                OrderWorkflow::transition($oid, 'cancelled', 'dl'); $c('cancel'); break;
        }
    } catch (Throwable $e) {
        if (Db::get()->inTransaction()) Db::get()->rollBack();
        $c('EXC ' . (Db::deadlocked($e) ? 'DEADLOCK ' : '') . get_class($e) . ' ' . substr(preg_replace('/\d+/', '#', $e->getMessage()), 0, 80) . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
ksort($res); echo json_encode(["$mode$idx" => $res]), "\n";
