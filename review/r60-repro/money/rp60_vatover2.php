<?php
// R60: rp59_vatover with the two returns settled in the other order (as cn_prop seed 4 did).
// A return credit note capped by money refunded outside the returns credits 1 cent more VAT at 23 % than the invoice has
// left there (capToUncredited bounds the net at the rate's uncredited net, not the VAT at its uncredited VAT).
require __DIR__ . '/lib50.php';
require __DIR__ . '/check50.php';
require __DIR__ . '/cn_check.php';
$driver = $argv[1] ?? 'sqlite';
$db = r50_boot($driver, $driver === 'sqlite' ? __DIR__ . '/tmp/rp60_vatover2.db' : 'bbe_r60_money_vatover', true);
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$wMain = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
$db->prepare("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('aux', 'Aux', 5, 0, 'default')")->execute(); $wAux = (int)$db->lastInsertId();
$A = r50_product($db, 'default', 'A23', 2990, 'eur', [$wMain => 100]);
$BK = r50_product($db, 'default', 'BK', 1590, 'eur', [$wAux => 100], 'simple', 'deny', 'books');
$lamp = r50_product($db, 'default', 'LAMP', 2900, 'eur', [$wMain => 100]);
$bulb = r50_product($db, 'default', 'BULB', 10, 'eur', [$wAux => 1000], 'simple', 'deny', 'reduced2');
$SET = r50_product($db, 'default', 'SET', 3000, 'eur', []); $db->exec("UPDATE products SET stock = 100, product_type = 'bundle' WHERE id = $SET");
$db->prepare("INSERT INTO bundle_components (bundle_product_id, component_product_id, component_variant_id, quantity, sort_order) VALUES (?, ?, 0, 1, 0), (?, ?, 0, 10, 1)")->execute([$SET, $lamp, $SET, $bulb]);
$db->exec("UPDATE payment_methods SET fixed_fee = 99 WHERE code = 'card' AND store_id = 'default'");
$log = [];
$r = r36_checkout([[$SET, 2], [$BK, 1], [$A, 3]], ['country' => 'SK', 'payment_method' => 'card', 'shipping_method' => 'packeta', 'coupon_code' => 'PCT15']);
$o = (int)$r['order_id']; $t = (int)Orders::byId($o)['total'];
PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$o", 'data' => ['order_id' => $o, 'amount' => $t, 'currency' => 'eur', 'payment_intent' => 'pi_' . $o]], 'stripe'); r50_drain($log, 100, true);
$inv = InvoiceService::issue($o);
OrderWorkflow::transition($o, 'shipped', 'x'); OrderWorkflow::transition($o, 'delivered', 'x'); r50_drain($log, 100, true);
echo "order total $t, invoice breakdown {$inv['vat_breakdown']}\n";
PaymentService::requestRefundAndQueue($o, 8023, 'rq1'); r50_drain($log, 100, true);
$items = Orders::items($o);
$ret = function (array $units) use ($o) { [, $rma] = Rma::create($o, null, 'return', $units); Rma::setStatus((int)$rma, 'approved'); $dec = []; foreach (Rma::items((int)$rma) as $ri) $dec[(int)$ri['id']] = ['quantity' => (int)$ri['quantity'], 'disposition' => 'restock']; Rma::receive((int)$rma, $dec, "rc$rma"); return (int)$rma; };
$r2 = $ret([(int)$items[0]['id'] => 2, (int)$items[2]['id'] => 1]);
$r3 = $ret([(int)$items[1]['id'] => 1, (int)$items[2]['id'] => 2]);
$rid = 're_dash1'; PaymentService::bookProviderRefund($o, 'stripe', $rid, 2360, 'refund.succeeded', 'stripe_refund_' . $rid, []); r50_drain($log, 100, true);
$admin = function (int $id) { $rma = Rma::byId($id); $outside = Rma::refundedOutsideReturns((int)$rma['order_id']); $src = InvoiceService::byOrderId((int)$rma['order_id']); $q = InvoiceService::creditableQuantities($id);
    if ($outside > 0 && InvoiceService::rmaCreditOwed((int)$src['id'], $id, $q, $outside)[1] === 0) return Rma::closeWithoutPayout($id) ? "closed w/o payout (outside $outside)" : 'close refused';
    $c = InvoiceService::credit((int)$src['id'], $id, $q, "admin-credit-$id-0", $outside > 0); return 'credit ' . (-(int)$c['total_gross']) . " (outside $outside)"; };
echo "rma $r2: ", $admin($r2), "\n"; r50_drain($log, 100, true);
echo "  cn_check: ", json_encode(cn_check($db, [$o])), "\n";
echo "rma $r3: ", $admin($r3), "\n"; r50_drain($log, 100, true);
foreach ($db->query("SELECT number, total_gross, total_net, total_vat, vat_breakdown FROM invoices WHERE order_id=$o ORDER BY id")->fetchAll() as $x) echo "    ", json_encode($x), "\n";
echo "  cn_check: ", json_encode(cn_check($db, [$o])), "\n";
$left = PaymentService::claimableRefund($o); PaymentService::requestRefundAndQueue($o, $left, 'rest'); r50_drain($log, 100, true);
echo "rest refunded $left; refunded ", PaymentService::refundedAmount($o), " of $t\n  cn_check: ", json_encode(cn_check($db, [$o], true)), "\n";
