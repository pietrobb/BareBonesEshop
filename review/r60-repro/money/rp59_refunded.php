<?php
// GoPay/ThePay REFUNDED while a shop refund is in flight (order refund pending at the provider / item refund of a return in flight).
require __DIR__ . '/lib50.php';
require __DIR__ . '/check50.php';
require __DIR__ . '/cn_check.php';
$driver = $argv[1] ?? 'sqlite';
$db = r50_boot($driver, $driver === 'sqlite' ? __DIR__ . '/tmp/rp59_refunded.db' : 'bbe_r60_money_rpref', true);
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$wh = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
$p = r50_product($db, 'default', 'MUG', 1990, 'eur', [$wh => 500]);
$q = r50_product($db, 'default', 'BOOK', 1590, 'eur', [$wh => 500], 'simple', 'deny', 'books');
$log = []; $all = [];
$card = function () use ($p, $q, &$all, $db) { global $log; $r = r36_checkout([[$p, 2], [$q, 1]], ['country' => 'SK', 'shipping_method' => 'gls', 'payment_method' => 'card']); $o = (int)$r['order_id']; $t = (int)Orders::byId($o)['total'];
    PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$o", 'data' => ['order_id' => $o, 'amount' => $t, 'currency' => 'eur', 'payment_intent' => 'pi_' . $o]], 'stripe'); r50_drain($log); InvoiceService::issue($o); $all[] = $o; return [$o, $t]; };
$refunded = function (int $o, int $t, string $prov, string $k) { PaymentService::applyProviderStatusEvent(['event_type' => 'refund.reported', 'event_id' => "$prov$k$o", 'data' => ['order_id' => $o, 'amount' => $t, 'currency' => 'eur', 'state' => $prov === 'gopay' ? 'REFUNDED' : 'refunded', 'payment_id' => 'pi_' . $o]], $prov); };
$show = function (string $l, int $o) use ($db) { global $log; r50_drain($log, 200, true); $x = Orders::byId($o);
    $cn = (int)$db->query("SELECT COALESCE(SUM(-total_gross),0) FROM invoices WHERE order_id=$o AND type='credit_note'")->fetchColumn();
    $pend = $db->query("SELECT status, COUNT(*) FROM order_refunds WHERE order_id=$o GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    printf("%-64s st=%-9s pay=%-14s refunded=%5d/%5d credit_notes=%5d item_refunds=%s cn_check=%s\n", $l, $x['status'], $x['payment_status'], PaymentService::refundedAmount($o), $x['total'], $cn, json_encode($pend), json_encode(cn_check($db, [$o], true))); };
foreach (['gopay', 'thepay'] as $prov) {
    [$o, $t] = $card(); R50Gateway::$refundMode = 'pending'; PaymentService::requestRefundAndQueue($o, 500, "rqp$o"); r50_drain($log); R50Gateway::$refundMode = 'succeeded'; $refunded($o, $t, $prov, 'a'); $show("($prov 1) shop partial 5.00 pending, then REFUNDED full", $o);
    [$o, $t] = $card(); R50Gateway::$refundMode = 'pending'; PaymentService::requestRefundAndQueue($o, $t, "rqp$o"); r50_drain($log); R50Gateway::$refundMode = 'succeeded'; $refunded($o, $t, $prov, 'b'); $show("($prov 2) shop full pending, then REFUNDED full", $o);
    [$o, $t] = $card(); R50Gateway::$refundMode = 'error'; PaymentService::requestRefundAndQueue($o, 700, "rqe$o"); r50_drain($log); R50Gateway::$refundMode = 'succeeded'; $refunded($o, $t, $prov, 'c'); $show("($prov 3) shop partial 7.00 job erroring (retry queued), then REFUNDED", $o);
    // item refund (return) in flight
    [$o, $t] = $card(); OrderWorkflow::transition($o, 'shipped', 'x'); OrderWorkflow::transition($o, 'delivered', 'x');
    $it = Orders::items($o)[0]; [, $rma] = Rma::create($o, null, 'return', [(int)$it['id'] => 1]); Rma::setStatus((int)$rma, 'approved');
    $dec = []; foreach (Rma::items((int)$rma) as $ri) $dec[(int)$ri['id']] = ['quantity' => 1, 'disposition' => 'restock']; Rma::receive((int)$rma, $dec, "rc$rma");
    R50Gateway::$refundMode = 'pending'; $inv = InvoiceService::byOrderId($o); InvoiceService::credit((int)$inv['id'], (int)$rma, InvoiceService::creditableQuantities((int)$rma), "ic$rma"); r50_drain($log); R50Gateway::$refundMode = 'succeeded';
    $refunded($o, $t, $prov, 'd'); $show("($prov 4) return credit 19.90 pending at provider, then REFUNDED full", $o);
    echo "     rma status: ", Rma::byId((int)$rma)['status'], "\n";
}
echo "r50_check: ", json_encode(array_values(array_filter(r50_check($db, true), fn($x) => !str_starts_with($x, 'ENTITLEMENT')))), "\n";
foreach ($log as $l) if (str_starts_with($l, 'JOBFAIL')) echo "  ", substr($l, 0, 300), "\n";
