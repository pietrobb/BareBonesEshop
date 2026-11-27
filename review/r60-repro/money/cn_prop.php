<?php
// R59 credit-note property test. php cn_prop.php driver target seed steps [verbose]
// Random SK orders (23/19/5 %, books, a set of 23 % lamp + 5 % bulbs, an e-book, shipping, card fee, coupons) paid by
// card and invoiced; random partial/full shop refunds, dashboard refunds, pending/declined refunds, partial shipments,
// cancellations of unshipped rests, returns of some units (admin "Refunded" with deduct), then a final sweep that returns
// and refunds everything. Invariants: cn_check.php after every step.
require __DIR__ . '/lib50.php';
require __DIR__ . '/check50.php';
require __DIR__ . '/cn_check.php';
[, $driver, $target] = $argv;
$seed = (int)($argv[3] ?? 1); $steps = (int)($argv[4] ?? 200); $verbose = !empty($argv[5]);
mt_srand($seed);
$db = r50_boot($driver, $target, true);
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$wMain = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
$db->prepare("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('aux', 'Aux', 5, 0, 'default')")->execute(); $wAux = (int)$db->lastInsertId();
$N = 1000000;
$P = [
    'A23' => r50_product($db, 'default', 'A23', 2990, 'eur', [$wMain => $N], 'simple', 'deny', 'standard'),
    'B19' => r50_product($db, 'default', 'B19', 1250, 'eur', [$wMain => $N], 'simple', 'deny', 'reduced'),
    'C5' => r50_product($db, 'default', 'C5', 899, 'eur', [$wAux => $N], 'simple', 'deny', 'reduced2'),
    'BK' => r50_product($db, 'default', 'BK', 1590, 'eur', [$wAux => $N], 'simple', 'deny', 'books'),
    'D23' => r50_product($db, 'default', 'D23', 333, 'eur', [$wAux => $N], 'simple', 'deny', 'standard'),
    'EB' => r50_product($db, 'default', 'EB', 990, 'eur', [], 'digital', 'deny', 'books'),
];
$lamp = r50_product($db, 'default', 'LAMP', 2900, 'eur', [$wMain => $N]);
$bulb = r50_product($db, 'default', 'BULB', 10, 'eur', [$wAux => $N], 'simple', 'deny', 'reduced2');
$P['SET'] = r50_product($db, 'default', 'SET', 3000, 'eur', []);
$db->exec("UPDATE products SET stock = $N, product_type = 'bundle' WHERE id = {$P['SET']}");
$db->prepare("INSERT INTO bundle_components (bundle_product_id, component_product_id, component_variant_id, quantity, sort_order) VALUES (?, ?, 0, 1, 0), (?, ?, 0, 10, 1)")->execute([$P['SET'], $lamp, $P['SET'], $bulb]);
$db->exec("UPDATE payment_methods SET fixed_fee = 99 WHERE code = 'card' AND store_id = 'default'");
$log = []; $orders = []; $viol = []; $seen = []; $stats = []; $notes = [];
$GLOBALS['captured'] = [];
$drain = function () use (&$log) { r50_drain($log, 500, true); };
$pay = function (int $oid) {
    $o = Orders::byId($oid);
    PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$oid", 'data' => ['order_id' => $oid, 'amount' => (int)$o['total'], 'currency' => 'eur', 'payment_intent' => 'pi_' . $oid]], 'stripe');
    $GLOBALS['captured'][$oid] = (int)$o['total'];
};
$pick = function (callable $f) use (&$orders) { $ids = array_keys($orders); shuffle($ids); foreach ($ids as $id) { $o = Orders::byId($id); if ($o && $f($o)) return $o; } return null; };
$paidish = fn(array $o) => in_array($o['payment_status'], ['paid', 'partially_refunded'], true);
$pendingRefunds = []; // R50 pending refund ids
$adminRefunded = function (int $id): string {
    try {
        if (Rma::settleAlreadyRefunded($id)) return 'settled';
        $rma = Rma::byId($id);
        if (Rma::refundInFlight((int)$rma['order_id'])) return 'REFUSED: refund in flight';
        $outside = Rma::refundedOutsideReturns((int)$rma['order_id']);
        $source = InvoiceService::byOrderId((int)$rma['order_id']);
        $q = InvoiceService::creditableQuantities($id);
        if (!$q) return 'nothing to credit';
        if ($outside > 0 && InvoiceService::rmaCreditOwed((int)$source['id'], $id, $q, $outside)[1] === 0) return Rma::closeWithoutPayout($id) ? "closed w/o payout (outside $outside)" : 'close refused';
        $credit = InvoiceService::credit((int)$source['id'], $id, $q, 'admin-credit-' . $id . '-' . InvoiceService::creditAttempts($id), $outside > 0);
        return 'credit ' . (-(int)$credit['total_gross']) . " (outside $outside)";
    } catch (Throwable $e) { return 'REFUSED: ' . get_class($e) . ': ' . $e->getMessage(); }
};
$returnUnits = function (array $o, bool $all) use ($db) {
    $oid = (int)$o['id'];
    $units = [];
    foreach (Rma::returnableItems($oid) as $it) {
        if ((int)$it['digital'] === 1) continue;
        $shipped = Fulfillment::shippedUnits($it);
        $claimed = (int)$it['quantity'] - (int)$it['returnable_quantity'];
        $can = $shipped - $claimed; if ($can <= 0) continue;
        if ($all || mt_rand(0, 1)) $units[(int)$it['id']] = $all ? $can : mt_rand(1, $can);
    }
    if (!$units) return [null, 'nothing returnable'];
    [$ok, $rma, $err] = Rma::create($oid, null, 'return', $units);
    if (!$ok) return [null, "rma refused $err " . json_encode($units)];
    Rma::setStatus((int)$rma, 'approved');
    $dec = []; foreach (Rma::items((int)$rma) as $ri) $dec[(int)$ri['id']] = ['quantity' => (int)$ri['quantity'], 'disposition' => 'restock'];
    Rma::receive((int)$rma, $dec, 'rc' . $rma);
    return [(int)$rma, 'returned ' . json_encode($units)];
};
$check = function (string $label, bool $final = false) use ($db, &$orders, &$viol, &$seen, &$pendingRefunds) {
    $skip = []; foreach ($pendingRefunds as [$oid]) $skip[$oid] = true;
    $list = cn_check($db, array_keys($orders), $final, $skip);
    foreach (r50_check($db, $final) as $x) if (!str_starts_with($x, 'RESERVED') && !str_starts_with($x, 'LEDGER') && !str_starts_with($x, 'PSTOCK')) $list[] = 'R50 ' . $x;
    $new = 0;
    foreach ($list as $x) { $k = preg_replace('/credited=[-\d]+.*$/', '', $x); if (isset($seen[$k])) continue; $seen[$k] = 1; $viol[] = "[$label] $x"; $new++; }
    return $new;
};
$bag = []; foreach (['create' => 14, 'ship_one' => 10, 'ship_all' => 6, 'cancel' => 6, 'refund_part' => 8, 'refund_full' => 2, 'dashboard' => 7, 'return' => 10, 'refund_return' => 9, 'pending' => 3, 'settle_pending' => 4, 'declined' => 3] as $op => $w) for ($i = 0; $i < $w; $i++) $bag[] = $op;
for ($step = 1; $step <= $steps; $step++) {
    $op = $step <= 8 ? 'create' : $bag[mt_rand(0, count($bag) - 1)];
    $note = '';
    try {
        switch ($op) {
            case 'create':
                $keys = array_keys($P); $lines = [];
                for ($i = 0, $n = mt_rand(1, 4); $i < $n; $i++) $lines[$keys[mt_rand(0, count($keys) - 1)]] = mt_rand(1, 3);
                $in = ['country' => 'SK', 'payment_method' => 'card', 'shipping_method' => ['gls', 'packeta', 'pickup'][mt_rand(0, 2)]];
                if (mt_rand(0, 9) < 3) $in['coupon_code'] = ['PCT15', 'ONCE', 'SHIPFREE'][mt_rand(0, 2)];
                $r = r36_checkout(array_map(fn($k, $q) => [$P[$k], $q], array_keys($lines), $lines), $in);
                if (empty($r['order_id'])) { $note = 'checkout refused ' . ($r['error_code'] ?? json_encode($r)); break; }
                $oid = (int)$r['order_id']; $orders[$oid] = true;
                $pay($oid); $drain();
                if (!InvoiceService::byOrderId($oid)) InvoiceService::issue($oid);
                $o = Orders::byId($oid);
                $note = "#$oid " . json_encode($lines) . ' ' . ($in['coupon_code'] ?? '') . " {$in['shipping_method']} total={$o['total']} st={$o['status']}";
                break;
            case 'ship_one': case 'ship_all':
                $o = $pick(fn($o) => in_array($o['status'], ['paid', 'processing', 'shipped'], true)); if (!$o) break;
                $oid = (int)$o['id'];
                $pend = $db->query("SELECT id FROM shipments WHERE order_id = $oid AND status NOT IN ('shipped','cancelled')")->fetchAll(PDO::FETCH_COLUMN);
                if (!$pend) $pend = Fulfillment::planOrder($oid);
                if (!$pend) { $note = "#$oid nothing to ship"; break; }
                shuffle($pend);
                foreach ($op === 'ship_one' ? [$pend[0]] : $pend as $sid) Fulfillment::ship((int)$sid);
                $left = (int)$db->query("SELECT COUNT(*) FROM shipments WHERE order_id = $oid AND status NOT IN ('shipped','cancelled')")->fetchColumn();
                $o = Orders::byId($oid);
                if ($left === 0 && in_array($o['status'], ['paid', 'processing'], true)) { try { OrderWorkflow::transition($oid, 'shipped', 'prop'); } catch (Throwable $e) { $note = 'ship transition: ' . $e->getMessage() . ' '; } }
                $o = Orders::byId($oid);
                if ($o['status'] === 'shipped' && mt_rand(0, 2)) { try { OrderWorkflow::transition($oid, 'delivered', 'prop'); } catch (Throwable $e) { $note .= 'deliver: ' . $e->getMessage(); } }
                $drain(); $note .= "#$oid shipped " . count($op === 'ship_one' ? [1] : $pend) . ' -> ' . Orders::byId($oid)['status']; break;
            case 'cancel':
                $o = $pick(fn($o) => in_array($o['status'], ['paid', 'processing', 'shipped'], true) && $paidish($o)); if (!$o) break;
                OrderWorkflow::transition((int)$o['id'], 'cancelled', 'prop'); $drain();
                $note = "cancel #{$o['id']} (was {$o['status']}) refunded=" . PaymentService::refundedAmount((int)$o['id']); break;
            case 'refund_part': case 'refund_full': case 'pending': case 'declined':
                $o = $pick(fn($o) => $paidish($o) && PaymentService::claimableRefund((int)$o['id']) > 0); if (!$o) break;
                $oid = (int)$o['id']; $bal = PaymentService::claimableRefund($oid);
                $a = $op === 'refund_full' ? $bal : max(1, (int)($bal * mt_rand(5, 70) / 100));
                R50Gateway::$refundMode = $op === 'pending' ? 'pending' : ($op === 'declined' ? 'declined' : 'succeeded');
                try { PaymentService::requestRefundAndQueue($oid, $a, 'rq-' . bin2hex(random_bytes(4))); $drain(); } finally { R50Gateway::$refundMode = 'succeeded'; }
                if ($op === 'pending') foreach (R50Gateway::$pending as $rid => [$pi, $amt, $po]) if ($po === $oid && !isset($pendingRefunds[$rid])) $pendingRefunds[$rid] = [$oid, $amt];
                $note = "$op #$oid $a of $bal"; break;
            case 'settle_pending':
                if (!$pendingRefunds) break;
                $rid = array_rand($pendingRefunds); [$oid, $amt] = $pendingRefunds[$rid]; unset($pendingRefunds[$rid]); unset(R50Gateway::$pending[$rid]);
                $ok = mt_rand(0, 3) > 0;
                if ($ok) R50Gateway::$refunded['o' . $oid] = (R50Gateway::$refunded['o' . $oid] ?? 0) + $amt;
                applyStripeRefundUpdated(['id' => $rid, 'amount' => $amt, 'status' => $ok ? 'succeeded' : 'failed', 'payment_intent' => 'pi_' . $oid], 'evt_' . $rid, $oid);
                $drain(); $note = "pending $rid #$oid $amt -> " . ($ok ? 'succeeded' : 'failed'); break;
            case 'dashboard':
                $o = $pick(fn($o) => $paidish($o) && PaymentService::refundableBalance((int)$o['id']) > 0); if (!$o) break;
                $oid = (int)$o['id']; $bal = PaymentService::refundableBalance($oid);
                // the provider caps at what it still holds (shop requests in flight included)
                $left = ($GLOBALS['captured'][$oid] ?? 0) - (R50Gateway::$refunded['o' . $oid] ?? 0) - array_sum(array_map(fn($p) => $p[2] === $oid ? $p[1] : 0, R50Gateway::$pending));
                $a = min($left, max(1, (int)($bal * mt_rand(10, 100) / 100))); if ($a <= 0) break;
                $rid = 're_d' . bin2hex(random_bytes(4)); R50Gateway::$refunded['o' . $oid] = (R50Gateway::$refunded['o' . $oid] ?? 0) + $a;
                PaymentService::bookProviderRefund($oid, 'stripe', $rid, $a, 'refund.succeeded', 'stripe_refund_' . $rid, []);
                $drain(); $note = "dashboard #$oid $a"; break;
            case 'return':
                $o = $pick(fn($o) => in_array($o['status'], ['delivered', 'cancelled'], true)); if (!$o) break;
                [$rma, $note] = $returnUnits($o, false); $note = "#{$o['id']} $note"; break;
            case 'refund_return':
                $row = $db->query("SELECT q.id, q.order_id FROM rma_requests q WHERE q.status = 'received' ORDER BY " . (Db::isSqlite() ? 'RANDOM()' : 'RAND()') . " LIMIT 1")->fetch(PDO::FETCH_ASSOC); if (!$row) break;
                $note = "rma {$row['id']} #{$row['order_id']}: " . $adminRefunded((int)$row['id']); $drain(); break;
        }
    } catch (Throwable $e) { $note .= ' EXC ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200) . ' @' . basename($e->getFile()) . ':' . $e->getLine(); }
    $stats[$op] = ($stats[$op] ?? 0) + 1;
    if (str_contains($note, 'EXC') || str_contains($note, 'REFUSED')) $notes[] = "$step $op $note";
    $new = $check("step $step $op: $note");
    if ($verbose) echo "$step $op $note" . ($new ? " VIOL+$new" : '') . "\n";
}
// final sweep: settle pending refunds, ship nothing more; cancel open orders, return all shipped units, refund returns, refund rest
foreach ($pendingRefunds as $rid => [$oid, $amt]) { R50Gateway::$refunded['o' . $oid] = (R50Gateway::$refunded['o' . $oid] ?? 0) + $amt; unset(R50Gateway::$pending[$rid]); applyStripeRefundUpdated(['id' => $rid, 'amount' => $amt, 'status' => 'succeeded', 'payment_intent' => 'pi_' . $oid], 'evt_' . $rid, $oid); }
$pendingRefunds = []; $drain();
foreach (array_keys($orders) as $oid) {
    $o = Orders::byId($oid); $sweep = [];
    try {
        foreach (Rma::forOrder($oid) as $q) if ($q['status'] === 'received') $sweep[] = 'rma' . $q['id'] . ':' . $adminRefunded((int)$q['id']);
        $drain(); $o = Orders::byId($oid);
        if ($o['status'] === 'shipped') { OrderWorkflow::transition($oid, 'delivered', 'sweep'); $sweep[] = 'delivered'; }
        elseif (in_array($o['status'], ['paid', 'processing'], true)) { OrderWorkflow::transition($oid, 'cancelled', 'sweep'); $drain(); $sweep[] = 'cancelled'; }
        $o = Orders::byId($oid);
        if (in_array($o['status'], ['delivered', 'cancelled'], true)) { [$rma, $n] = $returnUnits($o, true); if ($rma) { $sweep[] = $n . ' ' . $adminRefunded($rma); $drain(); } }
        $left = PaymentService::claimableRefund($oid);
        if ($left > 0) { PaymentService::requestRefundAndQueue($oid, $left, 'sweep-' . $oid); $drain(); $sweep[] = "refund rest $left"; }
    } catch (Throwable $e) { $sweep[] = 'EXC ' . get_class($e) . ': ' . $e->getMessage(); }
    if ($verbose) echo "sweep #$oid " . implode('; ', $sweep) . "\n";
    if (array_filter($sweep, fn($s) => str_contains($s, 'EXC') || str_contains($s, 'REFUSED'))) $notes[] = "sweep #$oid " . implode('; ', $sweep);
}
$check('final', true);
// all money back = invoice total?
$short = [];
foreach (array_keys($orders) as $oid) { $inv = InvoiceService::byOrderId($oid); if (!$inv) continue; $ref = PaymentService::refundedAmount($oid); $o = Orders::byId($oid); if ($ref !== (int)$o['total'] && !in_array($o['payment_status'], ['pending', 'failed'], true)) $short[] = "#$oid refunded $ref of {$o['total']} ps={$o['payment_status']} st={$o['status']}"; }
$jf = array_filter($log, fn($l) => str_starts_with($l, 'JOBFAIL'));
echo json_encode(['driver' => $driver, 'seed' => $seed, 'steps' => $steps, 'orders' => count($orders), 'credit_notes' => (int)$db->query("SELECT COUNT(*) FROM invoices WHERE type='credit_note'")->fetchColumn(), 'balance_notes' => (int)$db->query("SELECT COUNT(*) FROM invoices WHERE credit_snapshot LIKE '%outside_balance%'")->fetchColumn(), 'ops' => $stats, 'violations' => count($viol), 'jobfails' => count($jf), 'not_fully_refunded_after_sweep' => count($short)]), "\n";
foreach (array_slice($viol, 0, 25) as $x) echo "VIOL ", substr($x, 0, 700), "\n";
foreach (array_slice($jf, 0, 8) as $l) echo "  ", substr($l, 0, 400), "\n";
foreach (array_slice($short, 0, 8) as $l) echo "  SHORT ", $l, "\n";
foreach (array_slice($notes, 0, 15) as $l) echo "  NOTE ", substr($l, 0, 300), "\n";
