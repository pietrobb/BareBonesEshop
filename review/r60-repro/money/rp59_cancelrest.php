<?php
// Partial refund outside returns, then the unshipped rest (other VAT rate) cancelled: which rates does the cancellation's credit note credit?
require __DIR__ . '/lib50.php';
require __DIR__ . '/cn_check.php';
$driver = $argv[1] ?? 'sqlite';
$db = r50_boot($driver, $driver === 'sqlite' ? __DIR__ . '/tmp/rp59_cancelrest.db' : 'bbe_r60_money_cancelrest', true);
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$wMain = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
$db->prepare("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('aux', 'Aux', 5, 0, 'default')")->execute(); $wAux = (int)$db->lastInsertId();
$A = r50_product($db, 'default', 'A23', 2990, 'eur', [$wMain => 100]);
$C = r50_product($db, 'default', 'C5', 899, 'eur', [$wAux => 100], 'simple', 'deny', 'reduced2');
$log = [];
foreach ([['shop refund', 2476], ['dashboard refund', 2476], ['shop refund (small)', 500], ['no refund', 0]] as [$label, $amount]) {
    $r = r36_checkout([[$C, 2], [$A, 3]], ['country' => 'SK', 'payment_method' => 'card', 'shipping_method' => 'pickup']);
    $o = (int)$r['order_id']; $t = (int)Orders::byId($o)['total'];
    PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$o", 'data' => ['order_id' => $o, 'amount' => $t, 'currency' => 'eur', 'payment_intent' => 'pi_' . $o]], 'stripe'); r50_drain($log, 100, true);
    InvoiceService::issue($o);
    foreach (Fulfillment::planOrder($o) as $sid) if ((int)$db->query("SELECT warehouse_id FROM shipments WHERE id=$sid")->fetchColumn() === $wMain) Fulfillment::ship($sid);
    if ($amount > 0 && $label === 'dashboard refund') PaymentService::bookProviderRefund($o, 'stripe', "re_g$o", $amount, 'refund.succeeded', "stripe_refund_re_g$o", []);
    elseif ($amount > 0) PaymentService::requestRefundAndQueue($o, $amount, "gw$o");
    r50_drain($log, 100, true);
    OrderWorkflow::transition($o, 'cancelled', 'C5 not available'); r50_drain($log, 100, true);
    $cred = [];
    foreach ($db->query("SELECT vat_breakdown FROM invoices WHERE order_id=$o AND type='credit_note'")->fetchAll(PDO::FETCH_COLUMN) as $bd) foreach (json_decode($bd, true) as $k => $b) $cred[$k] = ($cred[$k] ?? 0) - (int)$b['net'] - (int)$b['vat'];
    $last = $db->query("SELECT vat_breakdown FROM invoices WHERE order_id=$o AND type='credit_note' ORDER BY id DESC LIMIT 1")->fetchColumn();
    printf("%-20s order %d (C5 2x899 @5%% unshipped, A23 3x2990 @23%% shipped): goodwill %d, cancellation refund %d; cancellation note %s; credited per rate %s\n",
        $label, $t, $amount, PaymentService::refundedAmount($o) - $amount, $last, json_encode($cred));
    echo "    cn_check: ", json_encode(cn_check($db, [$o])), "\n";
}
