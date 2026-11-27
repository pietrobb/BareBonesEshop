<?php
// A refund outside returns, then the cancellation of the unshipped rest refunds the remaining balance: the rate ends fully
// credited in gross but with net +1 / VAT -1 cent (rateCredit takes the goods' own net slice, not the rate's net rest).
require __DIR__ . '/lib50.php';
require __DIR__ . '/cn_check.php';
$driver = $argv[1] ?? 'sqlite';
$db = r50_boot($driver, $driver === 'sqlite' ? __DIR__ . '/tmp/rp59_netrest.db' : 'bbe_r60_money_netrest', true);
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$wMain = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
$db->prepare("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('aux', 'Aux', 5, 0, 'default')")->execute(); $wAux = (int)$db->lastInsertId();
$lamp = r50_product($db, 'default', 'LAMP', 2900, 'eur', [$wMain => 50]);
$bulb = r50_product($db, 'default', 'BULB', 10, 'eur', [$wAux => 500], 'simple', 'deny', 'reduced2');
$SET = r50_product($db, 'default', 'SET', 3000, 'eur', []); $db->exec("UPDATE products SET stock = 50, product_type = 'bundle' WHERE id = $SET");
$db->prepare("INSERT INTO bundle_components (bundle_product_id, component_product_id, component_variant_id, quantity, sort_order) VALUES (?, ?, 0, 1, 0), (?, ?, 0, 10, 1)")->execute([$SET, $lamp, $SET, $bulb]);
$A = r50_product($db, 'default', 'A', 3333, 'eur', [$wMain => 50]); $B = r50_product($db, 'default', 'B', 1111, 'eur', [$wAux => 50]);
$log = [];
foreach ([['3 sets, bulbs shipped', [[$SET, 3]], $wAux, 540], ['A (main) + 2 B (aux), A shipped', [[$A, 1], [$B, 2]], $wMain, 777]] as [$label, $lines, $ship, $goodwill]) {
    $r = r36_checkout($lines, ['country' => 'SK', 'shipping_method' => 'pickup', 'payment_method' => 'card']); $oid = (int)$r['order_id']; $o = Orders::byId($oid);
    PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$oid", 'data' => ['order_id' => $oid, 'amount' => (int)$o['total'], 'currency' => 'eur', 'payment_intent' => 'pi_' . $oid]], 'stripe'); r50_drain($log);
    $inv = InvoiceService::issue($oid);
    foreach (Fulfillment::planOrder($oid) as $sid) if ((int)$db->query("SELECT warehouse_id FROM shipments WHERE id=$sid")->fetchColumn() === $ship) Fulfillment::ship($sid);
    PaymentService::requestRefundAndQueue($oid, $goodwill, "gw$oid"); r50_drain($log, 100, true);
    OrderWorkflow::transition($oid, 'cancelled', 'rest'); r50_drain($log, 100, true);
    $notes = $db->query("SELECT total_gross, total_net, total_vat FROM invoices WHERE order_id=$oid AND type='credit_note' ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    printf("%-34s invoice %s; goodwill %d; cancellation refund %d; credit notes g/n/v %s\n    cn_check %s\n", $label, $inv['vat_breakdown'], $goodwill, PaymentService::refundedAmount($oid) - $goodwill, json_encode($notes), json_encode(cn_check($db, [$oid], true)));
}
