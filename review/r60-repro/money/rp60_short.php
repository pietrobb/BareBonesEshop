<?php
// R60: cn_prop60 seed 28. Dashboard refund, cancellation of the unshipped rest (B19 @19 %), returns of the shipped goods closed
// without payout: is the 5 % goods' rate credited in full? php rp60_short.php driver
require __DIR__ . '/lib50.php';
require __DIR__ . '/check50.php';
require __DIR__ . '/cn_check.php';
$driver = $argv[1] ?? 'sqlite';
$db = r50_boot($driver, $driver === 'sqlite' ? __DIR__ . '/tmp/rp60_short.db' : 'bbe_r60_money_short', true);
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$wMain = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
$db->prepare("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('aux', 'Aux', 5, 0, 'default')")->execute(); $wAux = (int)$db->lastInsertId();
$B = r50_product($db, 'default', 'B19', 1250, 'eur', [$wMain => 100], 'simple', 'deny', 'reduced');
$C = r50_product($db, 'default', 'C5', 899, 'eur', [$wAux => 100], 'simple', 'deny', 'reduced2');
$D = r50_product($db, 'default', 'D23', 333, 'eur', [$wAux => 100], 'simple', 'deny', 'standard');
$db->exec("UPDATE payment_methods SET fixed_fee = 99 WHERE code = 'card' AND store_id = 'default'");
$dash = (int)($argv[2] ?? 3077); $dash2 = (int)($argv[3] ?? 40);
$log = [];
$r = r36_checkout([[$D, 3], [$B, 1], [$C, 2]], ['country' => 'SK', 'payment_method' => 'card', 'shipping_method' => 'packeta']);
$o = (int)$r['order_id']; $t = (int)Orders::byId($o)['total'];
PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$o", 'data' => ['order_id' => $o, 'amount' => $t, 'currency' => 'eur', 'payment_intent' => 'pi_' . $o]], 'stripe'); r50_drain($log, 100, true);
$inv = InvoiceService::issue($o);
echo "order $t invoice {$inv['vat_breakdown']}\n";
foreach (Fulfillment::planOrder($o) as $sid) if ((int)$db->query("SELECT warehouse_id FROM shipments WHERE id=$sid")->fetchColumn() === $wAux) Fulfillment::ship($sid);
PaymentService::bookProviderRefund($o, 'stripe', 're_d1', $dash, 'refund.succeeded', 'stripe_refund_re_d1', []); r50_drain($log, 100, true);
OrderWorkflow::transition($o, 'cancelled', 'B19 not available'); r50_drain($log, 100, true);
echo "after cancel refunded ", PaymentService::refundedAmount($o), "\n";
$items = Orders::items($o); $by = []; foreach ($items as $it) $by[$it['sku']] = (int)$it['id'];
$ret = function (array $units) use ($o) { [$ok, $rma, $err] = Rma::create($o, null, 'return', $units); if (!$ok) { echo "rma refused $err\n"; return 0; } Rma::setStatus((int)$rma, 'approved'); $dec = []; foreach (Rma::items((int)$rma) as $ri) $dec[(int)$ri['id']] = ['quantity' => (int)$ri['quantity'], 'disposition' => 'restock']; Rma::receive((int)$rma, $dec, "rc$rma"); return (int)$rma; };
$admin = function (int $id) { $rma = Rma::byId($id); $outside = Rma::refundedOutsideReturns((int)$rma['order_id']); $src = InvoiceService::byOrderId((int)$rma['order_id']); $q = InvoiceService::creditableQuantities($id);
    if ($outside > 0 && InvoiceService::rmaCreditOwed((int)$src['id'], $id, $q, $outside)[1] === 0) return Rma::closeWithoutPayout($id) ? "closed w/o payout (outside $outside)" : 'close refused';
    $c = InvoiceService::credit((int)$src['id'], $id, $q, "admin-credit-$id-0", $outside > 0); return 'credit ' . (-(int)$c['total_gross']) . " (outside $outside)"; };
$r1 = $ret([$by['D23'] => 3]); echo "rma D23x3: ", $admin($r1), "\n"; r50_drain($log, 100, true);
if ($dash2 > 0) { PaymentService::bookProviderRefund($o, 'stripe', 're_d2', $dash2, 'refund.succeeded', 'stripe_refund_re_d2', []); r50_drain($log, 100, true); }
$r2 = $ret([$by['C5'] => 2]); echo "rma C5x2: ", $admin($r2), "\n"; r50_drain($log, 100, true);
foreach ($db->query("SELECT number, total_gross, vat_breakdown FROM invoices WHERE order_id=$o ORDER BY id")->fetchAll() as $x) echo "   ", json_encode($x), "\n";
echo "  cn_check: ", json_encode(cn_check($db, [$o])), "\n";
