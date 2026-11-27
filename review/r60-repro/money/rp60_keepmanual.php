<?php
// R60: bank-transfer order (no payment id): outside manual refund, then returns refunded in full with that money kept
// ("keep"), paid back by hand (manual-admin) — never more than paid in total; the credit note mail names its credit note.
require __DIR__ . '/lib50.php';
require __DIR__ . '/check50.php';
require __DIR__ . '/cn_check.php';
$driver = $argv[1] ?? 'sqlite';
$db = r50_boot($driver, $driver === 'sqlite' ? __DIR__ . '/tmp/rp60_km.db' : 'bbe_r60_money_km', true);
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$wh = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
$A = r50_product($db, 'default', 'A23', 2990, 'eur', [$wh => 100]);
$C = r50_product($db, 'default', 'C5', 899, 'eur', [$wh => 100], 'simple', 'deny', 'reduced2');
$log = [];
$r = r36_checkout([[$A, 1], [$C, 2]], ['country' => 'SK', 'payment_method' => 'bank_transfer', 'shipping_method' => 'gls']);
$o = (int)$r['order_id']; $t = (int)Orders::byId($o)['total'];
OfflinePaymentQueue::confirm($o, 'admin', 'paid'); r50_drain($log, 100, true);
if (!InvoiceService::byOrderId($o)) InvoiceService::issue($o);
OrderWorkflow::transition($o, 'shipped', 'x'); OrderWorkflow::transition($o, 'delivered', 'x'); r50_drain($log, 100, true);
PaymentService::completeManualRefund($o, 1500, 'admin', 'late delivery discount'); r50_drain($log, 100, true);
$items = Orders::items($o); $by = []; foreach ($items as $it) $by[$it['sku']] = (int)$it['id'];
$ret = function (array $units) use ($o) { [$ok, $rma, $err] = Rma::create($o, null, 'return', $units); Rma::setStatus((int)$rma, 'approved'); $dec = []; foreach (Rma::items((int)$rma) as $ri) $dec[(int)$ri['id']] = ['quantity' => (int)$ri['quantity'], 'disposition' => 'restock']; Rma::receive((int)$rma, $dec, "rc$rma"); return (int)$rma; };
$keep = function (int $id) use ($o, &$log) {
    $src = InvoiceService::byOrderId($o); $q = InvoiceService::creditableQuantities($id); $outside = Rma::refundedOutsideReturns($o);
    try { $c = InvoiceService::credit((int)$src['id'], $id, $q, "admin-credit-$id-" . InvoiceService::creditAttempts($id), $outside > 0 && getenv("DEDUCT") !== false, $outside > 0 && getenv("DEDUCT") === false); }
    catch (Throwable $e) { return 'REFUSED ' . $e->getMessage(); }
    $done = Rma::completeRefund((int)$c['refund_id'], 'manual-admin');
    if ($done) PaymentService::mailManualItemRefund($o, (int)(Rma::refundById((int)$c['refund_id'])['amount'] ?? 0), (int)$c['id']);
    r50_drain($log, 100, true);
    return 'credit ' . -(int)$c['total_gross'] . " (outside $outside kept) completed=" . var_export($done, true);
};
$state = function () use ($o, $db, $t) { $ord = Orders::byId($o); return sprintf('ledger refunded %d + by hand %d of %d paid; payment %s; claimable %d', PaymentService::refundedAmount($o),
    (int)$db->query("SELECT COALESCE(SUM(amount),0) FROM order_refunds WHERE order_id=$o AND status='succeeded'")->fetchColumn(), $t, $ord['payment_status'], PaymentService::claimableRefund($o)); };
echo "order $t; after outside 1500: ", $state(), "\n";
echo "return C5x2 keep: ", $keep($ret([$by['C5'] => 2])), "\n   ", $state(), "\n";
echo "return A23 keep: ", $keep($ret([$by['A23'] => 1])), "\n   ", $state(), "\n";
try { PaymentService::completeManualRefund($o, 1, 'admin', 'more'); echo "extra manual refund 1: ACCEPTED\n"; } catch (Throwable $e) { echo "extra manual refund 1: refused\n"; }
echo "   ", $state(), "\n  cn_check: ", json_encode(cn_check($db, [$o], true)), "\n";
$mails = array_filter($log, fn($l) => str_contains($l, 'credit_note_id'));
echo "  mail jobs naming a credit note: ", count($mails), "\n";
foreach (glob(getenv('R50_JOBDIR') ?: (__DIR__ . '/tmp/jobs-' . getmypid())) ?: [] as $d) {}
