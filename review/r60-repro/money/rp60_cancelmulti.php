<?php
// R60: cancellation of the unshipped rest after several partial refunds: mixed rates (23/19/5), shipping, coupon, card fee,
// refunds larger than the shipped goods, dashboard + shop refunds. php rp60_cancelmulti.php driver
require __DIR__ . '/lib50.php';
require __DIR__ . '/check50.php';
require __DIR__ . '/cn_check.php';
$driver = $argv[1] ?? 'sqlite';
$db = r50_boot($driver, $driver === 'sqlite' ? __DIR__ . '/tmp/rp60_cm.db' : 'bbe_r60_money_cm', true);
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$wMain = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
$db->prepare("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('aux', 'Aux', 5, 0, 'default')")->execute(); $wAux = (int)$db->lastInsertId();
$A = r50_product($db, 'default', 'A23', 2990, 'eur', [$wMain => 1000]);
$B = r50_product($db, 'default', 'B19', 1250, 'eur', [$wAux => 1000], 'simple', 'deny', 'reduced');
$C = r50_product($db, 'default', 'C5', 899, 'eur', [$wAux => 1000], 'simple', 'deny', 'reduced2');
$D = r50_product($db, 'default', 'D23', 333, 'eur', [$wAux => 1000], 'simple', 'deny', 'standard');
$db->exec("UPDATE payment_methods SET fixed_fee = 99 WHERE code = 'card' AND store_id = 'default'");
$log = []; $all = [];
$cases = [
  ['3 rates, 2 small refunds', [[$A, 1], [$B, 2], [$C, 3]], 'gls', null, [['shop', 500], ['dash', 300]]],
  ['refunds > shipped goods', [[$A, 1], [$B, 2], [$C, 3]], 'gls', null, [['dash', 3500], ['shop', 1200]]],
  ['coupon, refunds', [[$A, 2], [$B, 1], [$C, 2], [$D, 2]], 'packeta', 'PCT15', [['shop', 777], ['dash', 1234], ['shop', 1]]],
  ['unshipped 23+5, shipped 23', [[$A, 1], [$D, 3], [$C, 1]], 'gls', 'PCT15', [['dash', 2000]]],
  ['almost everything refunded', [[$A, 1], [$B, 1], [$C, 1]], 'gls', null, [['dash', 5000]]],
];
foreach ($cases as [$label, $lines, $ship, $coupon, $refunds]) {
    $in = ['country' => 'SK', 'payment_method' => 'card', 'shipping_method' => $ship] + ($coupon ? ['coupon_code' => $coupon] : []);
    $r = r36_checkout($lines, $in); $o = (int)($r['order_id'] ?? 0); if (!$o) { echo "$label: checkout refused ", json_encode($r), "\n"; continue; }
    $t = (int)Orders::byId($o)['total'];
    PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$o", 'data' => ['order_id' => $o, 'amount' => $t, 'currency' => 'eur', 'payment_intent' => 'pi_' . $o]], 'stripe'); r50_drain($log, 100, true);
    $inv = InvoiceService::issue($o);
    foreach (Fulfillment::planOrder($o) as $sid) if ((int)$db->query("SELECT warehouse_id FROM shipments WHERE id=$sid")->fetchColumn() === $wMain) Fulfillment::ship($sid);
    $k = 0; foreach ($refunds as [$kind, $amt]) { $k++; $amt = min($amt, PaymentService::claimableRefund($o) - 1); if ($amt <= 0) continue;
        if ($kind === 'dash') PaymentService::bookProviderRefund($o, 'stripe', "re_$o$k", $amt, 'refund.succeeded', "stripe_refund_re_$o$k", []); else PaymentService::requestRefundAndQueue($o, $amt, "rq$o$k");
        r50_drain($log, 100, true); }
    $before = PaymentService::refundedAmount($o);
    OrderWorkflow::transition($o, 'cancelled', 'rest not available'); r50_drain($log, 100, true);
    $cred = [];
    foreach ($db->query("SELECT vat_breakdown FROM invoices WHERE order_id=$o AND type='credit_note' AND status<>'cancelled'")->fetchAll(PDO::FETCH_COLUMN) as $bd) foreach (json_decode($bd, true) as $kk => $b) { $cred[$kk]['net'] = ($cred[$kk]['net'] ?? 0) - $b['net']; $cred[$kk]['vat'] = ($cred[$kk]['vat'] ?? 0) - $b['vat']; }
    printf("%-28s total %d invoice %s\n   refunded before cancel %d, cancel refund %d; credited per rate %s\n   cn_check %s\n", $label, $t, $inv['vat_breakdown'], $before, PaymentService::refundedAmount($o) - $before, json_encode($cred), json_encode(cn_check($db, [$o])));
    $rest = PaymentService::claimableRefund($o); if ($rest > 0) { PaymentService::requestRefundAndQueue($o, $rest, "rest$o"); r50_drain($log, 100, true); }
    echo "   after refunding the rest ($rest): ", json_encode(cn_check($db, [$o], true)), "\n";
}
