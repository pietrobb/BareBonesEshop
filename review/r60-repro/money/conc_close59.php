<?php
// Three returns of one order marked "Refunded" at once (MySQL), outside money covering two of them.
// php conc_close59.php setup db rounds | child db round idx start | check db rounds
require __DIR__ . '/lib50.php';
require __DIR__ . '/cn_check.php';
[, $role, $target] = $argv;
$db = r50_boot('mysql', $target, $role === 'setup');
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$log = [];
if ($role === 'setup') {
    $wh = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
    $p = [r50_product($db, 'default', 'CA', 2000, 'eur', [$wh => 1000]), r50_product($db, 'default', 'CB', 2000, 'eur', [$wh => 1000], 'simple', 'deny', 'reduced2'), r50_product($db, 'default', 'CC', 1666, 'eur', [$wh => 1000], 'simple', 'deny', 'reduced')];
    $map = [];
    for ($k = 0; $k < (int)$argv[3]; $k++) {
        $r = r36_checkout([[$p[0], 1], [$p[1], 1], [$p[2], 1]], ['country' => 'SK', 'payment_method' => 'card', 'shipping_method' => 'pickup']);
        $o = (int)$r['order_id']; $t = (int)Orders::byId($o)['total'];
        PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$o", 'data' => ['order_id' => $o, 'amount' => $t, 'currency' => 'eur', 'payment_intent' => 'pi_' . $o]], 'stripe'); r50_drain($log, 100, true);
        InvoiceService::issue($o); OrderWorkflow::transition($o, 'shipped', 'x'); OrderWorkflow::transition($o, 'delivered', 'x');
        PaymentService::bookProviderRefund($o, 'stripe', "re_c$o", 4000, 'refund.succeeded', "stripe_refund_re_c$o", []); r50_drain($log, 100, true);
        $rmas = [];
        foreach (Orders::items($o) as $it) { [, $rma] = Rma::create($o, null, 'return', [(int)$it['id'] => 1]); Rma::setStatus((int)$rma, 'approved');
            $dec = []; foreach (Rma::items((int)$rma) as $ri) $dec[(int)$ri['id']] = ['quantity' => 1, 'disposition' => 'restock']; Rma::receive((int)$rma, $dec, "rc$rma"); $rmas[] = (int)$rma; }
        $map[$k] = [$o, $rmas];
    }
    file_put_contents(__DIR__ . "/tmp/cc59_$target.json", json_encode($map)); echo "setup ", count($map), " orders\n"; exit;
}
$map = json_decode(file_get_contents(__DIR__ . "/tmp/cc59_$target.json"), true);
if ($role === 'child') {
    [$o, $rmas] = $map[(int)$argv[3]]; $id = $rmas[(int)$argv[4]];
    while (microtime(true) < (float)$argv[5]) usleep(200);
    try {
        // the admin's "Refunded" with "deduct" confirmed (handleRmaStatus)
        if (Rma::settleAlreadyRefunded($id)) { echo "settled\n"; exit; }
        if (Rma::refundInFlight($o)) { echo "in flight\n"; exit; }
        $outside = Rma::refundedOutsideReturns($o); $src = InvoiceService::byOrderId($o); $q = InvoiceService::creditableQuantities($id);
        if ($outside > 0 && InvoiceService::rmaCreditOwed((int)$src['id'], $id, $q, $outside)[1] === 0) { echo Rma::closeWithoutPayout($id) ? "closed\n" : "close refused\n"; exit; }
        $c = InvoiceService::credit((int)$src['id'], $id, $q, "admin-credit-$id-" . InvoiceService::creditAttempts($id), $outside > 0);
        echo "credit ", -(int)$c['total_gross'], "\n";
    } catch (Throwable $e) { echo "EXC ", (Db::deadlocked($e) ? 'DEADLOCK ' : ''), get_class($e), ' ', substr($e->getMessage(), 0, 100), "\n"; }
    exit;
}
if ($role === 'check') {
    foreach ($map as [$o, $rmas]) foreach ($rmas as $id) if (Rma::byId($id)['status'] === 'received') {
        $outside = Rma::refundedOutsideReturns($o); $src = InvoiceService::byOrderId($o); $q = InvoiceService::creditableQuantities($id);
        if ($outside > 0 && InvoiceService::rmaCreditOwed((int)$src['id'], $id, $q, $outside)[1] === 0) Rma::closeWithoutPayout($id);
        else InvoiceService::credit((int)$src['id'], $id, $q, "admin-credit-$id-retry", $outside > 0);
    }
    for ($k = 0; $k < 6; $k++) r50_drain($log, 500, true);
    $bad = 0; $sum = [];
    foreach ($map as $k => [$o, $rmas]) {
        $t = (int)Orders::byId($o)['total']; $ref = PaymentService::refundedAmount($o);
        $st = array_map(fn($r) => Rma::byId($r)['status'], $rmas);
        $v = cn_check($db, [$o], false);
        $key = "refunded $ref of $t, rmas " . implode('/', $st) . ($v ? ' ' . json_encode($v) : '');
        $sum[$key] = ($sum[$key] ?? 0) + 1;
        if ($ref !== $t || $v) $bad++;
    }
    foreach ($sum as $k => $n) echo "  $n x $k\n";
    echo "orders not fully paid back after all goods returned (or invariant broken): $bad of ", count($map), "\n";
}
