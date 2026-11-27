<?php
// Sets of a 29.00 lamp (main warehouse) + 10 x 0.10 bulbs (aux): 2 sets with 15 % coupon; lamps ship, bulbs cancelled; then the sets come back.
require __DIR__ . '/lib50.php';
require __DIR__ . '/check50.php';
require __DIR__ . '/cn_check.php';
$driver = $argv[1] ?? 'sqlite';
$db = r50_boot($driver, $driver === 'sqlite' ? __DIR__ . '/tmp/rp59_multiset.db' : 'bbe_r60_money_mset', true);
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$wMain = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
$db->prepare("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('aux', 'Aux', 5, 0, 'default')")->execute(); $wAux = (int)$db->lastInsertId();
$lamp = r50_product($db, 'default', 'LAMP', 2900, 'eur', [$wMain => 50]);
$bulb = r50_product($db, 'default', 'BULB', 10, 'eur', [$wAux => 500]);
$log = [];
foreach ([['SET', 3000, 'standard'], ['SETD', 2500, 'standard']] as [$sku, $price, $vat]) {
    $bun = r50_product($db, 'default', $sku, $price, 'eur', [], 'simple', 'deny', $vat); $db->exec("UPDATE products SET stock = 50, product_type = 'bundle' WHERE id = $bun");
    $db->prepare("INSERT INTO bundle_components (bundle_product_id, component_product_id, component_variant_id, quantity, sort_order) VALUES (?, ?, 0, 1, 0), (?, ?, 0, 10, 1)")->execute([$bun, $lamp, $bun, $bulb]);
    foreach ([[$wMain, 'lamps shipped, bulbs cancelled'], [$wAux, 'bulbs shipped, lamps cancelled']] as [$wh, $label]) {
        $r = r36_checkout([[$bun, 2]], ['country' => 'SK', 'shipping_method' => 'pickup', 'payment_method' => 'card', 'coupon_code' => 'PCT15']); $oid = (int)$r['order_id']; $o = Orders::byId($oid);
        PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$oid", 'data' => ['order_id' => $oid, 'amount' => (int)$o['total'], 'currency' => 'eur', 'payment_intent' => 'pi_' . $oid]], 'stripe'); r50_drain($log);
        InvoiceService::issue($oid);
        foreach (Fulfillment::planOrder($oid) as $sid) if ((int)$db->query("SELECT warehouse_id FROM shipments WHERE id=$sid")->fetchColumn() === $wh) Fulfillment::ship($sid);
        OrderWorkflow::transition($oid, 'cancelled', 'rest'); r50_drain($log, 100, true);
        $onCancel = PaymentService::refundedAmount($oid);
        $it = Orders::items($oid)[0]; $credits = [];
        for ($k = 0; $k < 2; $k++) { [$ok, $rma, $err] = Rma::create($oid, null, 'return', [(int)$it['id'] => 1]); if (!$ok) { $credits[] = "refused $err"; continue; } Rma::setStatus((int)$rma, 'approved');
            $dec = []; foreach (Rma::items((int)$rma) as $ri) $dec[(int)$ri['id']] = ['quantity' => 1, 'disposition' => 'restock']; Rma::receive((int)$rma, $dec, "rc$rma");
            $c = InvoiceService::credit((int)InvoiceService::byOrderId($oid)['id'], (int)$rma, InvoiceService::creditableQuantities((int)$rma), "ic$rma"); r50_drain($log, 100, true); $credits[] = -(int)$c['total_gross']; }
        printf("%-5s (%d) x2 -15%%, %-32s total %5d: refund on cancel %4d (expect %4d), returns %s, all back %5d, cn_check %s\n", $sku, $price, $label, $o['total'], $onCancel,
            $wh === $wMain ? (int)round((int)$o['total'] * 200 / 6000) : (int)round((int)$o['total'] * 5800 / 6000), json_encode($credits), PaymentService::refundedAmount($oid), json_encode(cn_check($db, [$oid], true)));
    }
}
echo "violations: ", json_encode(array_values(array_filter(r50_check($db), fn($x) => !str_starts_with($x, 'ENTITLEMENT')))), "\n";
