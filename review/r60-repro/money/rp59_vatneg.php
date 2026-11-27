<?php
// A return credit note's reversing line (outsideLines) gives back more VAT at 5 % than the outside refunds credited there.
require __DIR__ . '/lib50.php';
require __DIR__ . '/cn_check.php';
$driver = $argv[1] ?? 'sqlite';
$db = r50_boot($driver, $driver === 'sqlite' ? __DIR__ . '/tmp/rp59_vatneg.db' : 'bbe_r60_money_vatneg', true);
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$wMain = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
$db->prepare("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('aux', 'Aux', 5, 0, 'default')")->execute(); $wAux = (int)$db->lastInsertId();
$B = r50_product($db, 'default', 'B19', 1250, 'eur', [$wMain => 100], 'simple', 'deny', 'reduced');
$C = r50_product($db, 'default', 'C5', 899, 'eur', [$wAux => 100], 'simple', 'deny', 'reduced2');
$E = r50_product($db, 'default', 'EB', 990, 'eur', [], 'digital', 'deny', 'books');
$db->exec("UPDATE payment_methods SET fixed_fee = 99 WHERE code = 'card' AND store_id = 'default'");
$log = [];
$r = r36_checkout([[$B, 2], [$C, 1], [$E, 1]], ['country' => 'SK', 'payment_method' => 'card', 'shipping_method' => 'pickup']); $o = (int)$r['order_id']; $t = (int)Orders::byId($o)['total'];
PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$o", 'data' => ['order_id' => $o, 'amount' => $t, 'currency' => 'eur', 'payment_intent' => 'pi_' . $o]], 'stripe'); r50_drain($log, 100, true);
$inv = InvoiceService::issue($o);
PaymentService::requestRefundAndQueue($o, 263, 'gw1'); r50_drain($log, 100, true);
PaymentService::bookProviderRefund($o, 'stripe', 're_d1', 2063, 'refund.succeeded', 'stripe_refund_re_d1', []); r50_drain($log, 100, true);
OrderWorkflow::transition($o, 'shipped', 'x'); OrderWorkflow::transition($o, 'delivered', 'x');
$it = Orders::items($o)[0]; [, $rma] = Rma::create($o, null, 'return', [(int)$it['id'] => 2]); Rma::setStatus((int)$rma, 'approved');
$dec = []; foreach (Rma::items((int)$rma) as $ri) $dec[(int)$ri['id']] = ['quantity' => 2, 'disposition' => 'restock']; Rma::receive((int)$rma, $dec, "rc$rma");
$outside = Rma::refundedOutsideReturns($o); $q = InvoiceService::creditableQuantities((int)$rma);
$c = InvoiceService::credit((int)$inv['id'], (int)$rma, $q, "ic$rma", $outside > 0); r50_drain($log, 100, true);
echo "invoice {$inv['vat_breakdown']} total $t; outside $outside; return credit ", -(int)$c['total_gross'], " bd {$c['vat_breakdown']}\n  cn_check ", json_encode(cn_check($db, [$o])), "\n";
