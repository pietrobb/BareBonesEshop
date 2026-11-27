<?php
// TOCTOU: the capture amount is compared with the order total read before the order lock.
// php race_paid.php setup db | edit db oid | hook db oid amount | show db oid
require __DIR__ . '/lib50.php';
[, $role, $target] = $argv;
$db = r50_boot('mysql', $target, $role === 'setup');
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
if ($role === 'setup') {
    $wh = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
    $p = r50_product($db, 'default', 'RP', 2000, 'eur', [$wh => 100]); $q = r50_product($db, 'default', 'RQ', 1500, 'eur', [$wh => 100]);
    $r = r36_checkout([[$p, 1]], ['country' => 'SK', 'payment_method' => 'card', 'shipping_method' => 'pickup']);
    echo $r['order_id'], ' ', Orders::byId((int)$r['order_id'])['total'], "\n"; exit;
}
$oid = (int)$argv[3];
if ($role === 'edit') {
    // the effect of an admin edit (ManualOrder::editItems: under the order lock, total raised, committed) held 1.5 s
    $db->beginTransaction(); Orders::lockWithin($oid);
    $db->exec("UPDATE orders SET subtotal = subtotal + 1500, total = total + 1500 WHERE id = $oid");
    usleep(1500000);
    $db->commit();
    echo "edit committed: total now ", Orders::byId($oid)['total'], "\n"; exit;
}
if ($role === 'stress') {
    // real ManualOrder::editItems against a real webhook, many fresh orders
    [$p] = [0];
    $hits = 0; $n = (int)$argv[4];
    for ($k = 0; $k < $n; $k++) {
        $r = r36_checkout([[ (int)$db->query("SELECT id FROM products WHERE sku='RP'")->fetchColumn(), 1]], ['country' => 'SK', 'payment_method' => 'card', 'shipping_method' => 'pickup']);
        $o = (int)$r['order_id']; $t = (int)Orders::byId($o)['total'];
        $pid = pcntl_fork();
        if ($pid === 0) { $GLOBALS['db2'] = r50_boot('mysql', $GLOBALS['target'], false); r36_store('default'); usleep(mt_rand(0, 4000));
            try { PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$o", 'data' => ['order_id' => $o, 'amount' => $t, 'currency' => 'eur', 'payment_intent' => 'pi_' . $o]], 'stripe'); } catch (Throwable $e) {}
            exit(0); }
        $db = r50_boot('mysql', $target, false); r36_store('default'); usleep(mt_rand(0, 4000));
        try { ManualOrder::editItems($o, ManualOrder::parseLines("RP 1\nRQ 1")['lines']); } catch (Throwable $e) {}
        pcntl_waitpid($pid, $st);
        $x = Orders::byId($o); $paid = (int)$db->query("SELECT COALESCE(SUM(amount),0) FROM payment_transactions WHERE order_id=$o AND status='paid'")->fetchColumn();
        if ($x['payment_status'] === 'paid' && $paid !== (int)$x['total']) { $hits++; echo "  #$o paid $paid for total {$x['total']}\n"; }
    }
    echo "stress: $hits of $n orders paid at another amount than their total\n"; exit;
}
if ($role === 'hook') {
    usleep(300000);
    try { $res = PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$oid", 'data' => ['order_id' => $oid, 'amount' => (int)$argv[4], 'currency' => 'eur', 'payment_intent' => 'pi_' . $oid]], 'stripe'); echo "hook: ", json_encode($res), "\n"; }
    catch (Throwable $e) { echo "hook EXC ", $e->getMessage(), "\n"; }
    exit;
}
$o = Orders::byId($oid);
echo "order #$oid status={$o['status']} payment={$o['payment_status']} total={$o['total']} paid ledger=", $db->query("SELECT COALESCE(SUM(amount),0) FROM payment_transactions WHERE order_id=$oid AND status='paid'")->fetchColumn(), "\n";
