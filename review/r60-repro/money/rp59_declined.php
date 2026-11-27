<?php
// Declined refunds through the real ThePay/Comgate/GoPay adapters with fake transports (no network):
// item refund (return credit note) refused; order refund refused after the dashboard refunded part/all; Comgate 4xx codes.
require __DIR__ . '/lib50.php';
require __DIR__ . '/check50.php';
require __DIR__ . '/cn_check.php';
$driver = $argv[1] ?? 'sqlite';
$db = r50_boot($driver, $driver === 'sqlite' ? __DIR__ . '/tmp/rp59_declined.db' : 'bbe_r60_money_decl', true);
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default');
require_once R36_ROOT . '/adapters/PaymentAdapter.php';
foreach (['ThePayPayment', 'ComgatePayment', 'GoPayPayment'] as $c) require_once R36_ROOT . "/adapters/contrib/{$c}.php";
$inst = new ReflectionProperty(Adapters::class, 'instances');
$GW = []; // payment id => [number, total, refunded, calls, answer: ok|refused|err1100|err1400]
$refundCall = function (string $id, int $amount) use (&$GW): array {
    $GW[$id]['calls']++;
    $a = $GW[$id]['answer'];
    if ($a === 'refused') return ['status' => 400, 'body' => json_encode(['message' => 'refund refused', 'code' => 1400])];
    if ($a === 'err1100') return ['status' => 400, 'body' => json_encode(['message' => 'unknown error', 'code' => 1100])];
    if ($GW[$id]['refunded'] + $amount > $GW[$id]['total']) return ['status' => 400, 'body' => json_encode(['message' => 'exceeds', 'code' => 1401])];
    $GW[$id]['refunded'] += $amount;
    return ['status' => 200, 'body' => json_encode(['code' => 0, 'id' => (int)$id, 'result' => 'FINISHED'])];
};
$thepay = new ThePayPayment(['thepay_merchant_id' => 'm', 'thepay_project_id' => '42', 'thepay_password' => 'p'],
    function (string $method, string $url, array $headers, ?string $body) use (&$GW, $refundCall): array {
        if (!preg_match('#/payments/([^/?]+)(/refund)?\?#', $url, $m) || !isset($GW[$m[1]])) return ['status' => 404, 'body' => '{}'];
        if (($m[2] ?? '') === '/refund') return $refundCall($m[1], (int)json_decode((string)$body, true)['amount']);
        $p = $GW[$m[1]];
        return ['status' => 200, 'body' => json_encode(['uid' => $m[1], 'project_id' => 42, 'order_id' => $p['number'], 'amount' => $p['total'], 'paid_amount' => $p['total'],
            'currency' => 'EUR', 'state' => $p['refunded'] >= $p['total'] ? 'refunded' : ($p['refunded'] > 0 ? 'partially_refunded' : 'paid')])];
    });
$comgate = new ComgatePayment(['comgate_merchant' => 'm', 'comgate_secret' => 's'],
    function (string $method, string $url, array $headers, ?string $body) use (&$GW, $refundCall): array {
        if (str_ends_with($url, '/v2.0/refund.json')) { $f = json_decode((string)$body, true); return $refundCall((string)$f['transId'], (int)$f['amount']); }
        if (!preg_match('#/transId/([^/]+)\.json$#', $url, $m) || !isset($GW[$m[1]])) return ['status' => 200, 'body' => json_encode(['code' => 1400])];
        return ['status' => 200, 'body' => json_encode(['code' => 0, 'transId' => $m[1], 'status' => 'PAID', 'price' => (string)$GW[$m[1]]['total'], 'curr' => 'EUR', 'refId' => $GW[$m[1]]['number'], 'test' => 'false'])];
    });
$gopay = new GoPayPayment(['gopay_goid' => '8123456789', 'gopay_client_id' => 'c', 'gopay_client_secret' => 's'],
    function (string $method, string $url, array $headers, ?string $body) use (&$GW, $refundCall): array {
        if (str_contains($url, '/oauth2/token')) return ['status' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 1800])];
        if (!preg_match('#/payments/payment/(\d+)(/refund)?$#', (string)parse_url($url, PHP_URL_PATH), $m) || !isset($GW[$m[1]])) return ['status' => 404, 'body' => '{}'];
        if (($m[2] ?? '') === '/refund') {
            parse_str((string)$body, $form);
            if ($GW[$m[1]]['answer'] !== 'ok' || $GW[$m[1]]['refunded'] + (int)$form['amount'] > $GW[$m[1]]['total']) { $GW[$m[1]]['calls']++; return ['status' => 200, 'body' => json_encode(['id' => (int)$m[1], 'result' => 'FAILED'])]; }
            return $refundCall($m[1], (int)$form['amount']);
        }
        $p = $GW[$m[1]];
        return ['status' => 200, 'body' => json_encode(['id' => (int)$m[1], 'order_number' => $p['number'], 'amount' => $p['total'], 'currency' => 'EUR',
            'state' => $p['refunded'] >= $p['total'] ? 'REFUNDED' : ($p['refunded'] > 0 ? 'PARTIALLY_REFUNDED' : 'PAID'), 'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])];
    });
$gws = ['thepay' => $thepay, 'comgate' => $comgate, 'gopay' => $gopay];
$cat = (int)$db->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
if (!$cat) { $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'd', 'D', 'd', 0)"); $cat = (int)$db->lastInsertId(); }
$n = 0;
$order = function (string $provider) use ($db, $cat, &$n, &$GW): array {
    $n++;
    $pid = ['thepay' => "tp-uid-$n", 'comgate' => "CG-$n-XYZ", 'gopay' => (string)(5700000000 + $n)][$provider];
    $items = [];
    foreach ([[2000, 1, 'standard'], [1000, 1, 'reduced2']] as $i => [$price, $qty, $class]) {
        $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, vat_class) VALUES ('default', ?, ?, ?, ?, ?, 50, 'active', ?)")->execute([$cat, "D{$n}-{$i}", "d{$n}-{$i}", "D{$n}-{$i}", $price, $class]);
        $items[] = ['product_id' => (int)$db->lastInsertId(), 'sku' => "D{$n}-{$i}", 'qty' => $qty, 'price' => $price, 'total' => $price * $qty, 'product_type' => 'simple', 'vat_class' => $class, 'currency' => 'eur', 'available' => true];
    }
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK'];
    $items = Cart::applyVat(['items' => $items, 'subtotal' => 3000, 'currency' => 'eur'], $ctx)['items'];
    $snap = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $number = "D59-$n";
    $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
        VALUES (?, 'D', 'd@example.test', '{\"country\":\"SK\"}', 3000, 3000, 'eur', 'delivered', 'paid', ?, ?, ?, 'pickup', 'domestic', ?, 'default')")
        ->execute([$number, $provider, $pid, json_encode(['provider' => $provider, 'service_type' => 'online_card', 'requires_online' => true]), json_encode($snap)]);
    $id = (int)$db->lastInsertId(); $iids = [];
    foreach ($items as $it) { $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$id, $it['product_id'], $it['sku'], $it['sku'], $it['qty'], $it['price'], $it['total']]); $iids[] = (int)$db->lastInsertId(); }
    $db->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, provider_ref, store_id, created_at, updated_at) VALUES (?, ?, 'paid', 3000, 'eur', ?, ?, 'default', " . Db::now() . ", " . Db::now() . ")")->execute([$id, $provider, "paid-$id", $pid]);
    InvoiceService::issue($id);
    $GW[$pid] = ['number' => $number, 'total' => 3000, 'refunded' => 0, 'calls' => 0, 'answer' => 'ok'];
    return [$id, $pid, $iids];
};
$jobs = function (PaymentAdapter $gw) use ($inst) {
    $out = [];
    $inst->setValue(null, ['payment' => $gw]);
    try {
        for ($r = 0; $r < 5; $r++) {
            $did = false;
            foreach (JobQueue::pull(50) as $job) {
                $proc = JobQueue::markProcessing($job); if ($proc === '') continue; $did = true;
                JobQueue::beginWork($proc);
                try {
                    $p = $job['payload'] ?? [];
                    match ($job['type']) {
                        'payment_refund' => WorkerJobs::handlePaymentRefund($p, $job),
                        'refund_credit_note' => InvoiceService::creditBookedRefund((int)$p['order_id'], (string)$p['refund_key'], (int)($p['deferrals'] ?? 0), (int)($p['waits'] ?? 0)),
                        default => null,
                    };
                    JobQueue::complete($proc); $out[] = $job['type'] . ':ok';
                } catch (Throwable $e) { JobQueue::fail($proc, $job, 'x'); $out[] = $job['type'] . ':FAIL ' . substr($e->getMessage(), 0, 90); }
                finally { JobQueue::endWork(); }
            }
            if (!$did) break;
        }
    } finally { $inst->setValue(null, []); }
    return $out;
};
$state = function (int $o, string $pid, array $jobsOut) use ($db, &$GW) {
    $x = Orders::byId($o);
    $cns = $db->query("SELECT number, total_gross, credit_snapshot FROM invoices WHERE order_id=$o AND type='credit_note' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $rf = $db->query("SELECT status, amount FROM order_refunds WHERE order_id=$o")->fetchAll(PDO::FETCH_NUM);
    return sprintf("pay=%s refunded(shop)=%d provider=%d calls=%d CNs=%s item_refunds=%s jobs=%s cn_check=%s", $x['payment_status'], PaymentService::refundedAmount($o), $GW[$pid]['refunded'], $GW[$pid]['calls'],
        json_encode(array_map(fn($c) => [$c['number'], (int)$c['total_gross'], json_decode($c['credit_snapshot'], true)['kind'] ?? 'item'], $cns)), json_encode($rf), json_encode($jobsOut), json_encode(cn_check($db, [$o], false)));
};
$return = function (int $o, int $iid) { [, $rma] = Rma::create($o, null, 'return', [$iid => 1]); Rma::setStatus((int)$rma, 'approved');
    $dec = []; foreach (Rma::items((int)$rma) as $ri) $dec[(int)$ri['id']] = ['quantity' => 1, 'disposition' => 'restock']; Rma::receive((int)$rma, $dec, "rc$rma"); return (int)$rma; };
foreach ($gws as $prov => $gw) {
    // 1. return credit note, provider refuses the item refund
    [$o, $pid, $iids] = $order($prov); $GW[$pid]['answer'] = 'refused';
    $rma = $return($o, $iids[1]); $inv = InvoiceService::byOrderId($o);
    InvoiceService::credit((int)$inv['id'], $rma, InvoiceService::creditableQuantities($rma), "ic$rma");
    $j = $jobs($gw); echo "($prov 1) item refund 10.00 refused: ", $state($o, $pid, $j), " rma=", Rma::byId($rma)['status'], "\n";
    // 2. dashboard refunded everything, then shop partial refund refused
    [$o, $pid] = $order($prov); $GW[$pid]['refunded'] = 3000;
    PaymentService::requestRefundAndQueue($o, 500, "rq$o", ["reason" => "x"]); $GW[$pid]['answer'] = 'refused';
    $j = $jobs($gw); echo "($prov 2) dashboard refunded all, shop 5.00 refused: ", $state($o, $pid, $j), "\n";
    // 3. dashboard refunded 28.00, shop 5.00 refused (exceeds)
    [$o, $pid] = $order($prov); $GW[$pid]['refunded'] = 2800;
    PaymentService::requestRefundAndQueue($o, 500, "rq$o", ["reason" => "x"]);
    $j = $jobs($gw); echo "($prov 3) dashboard refunded 28.00, shop 5.00 refused: ", $state($o, $pid, $j), "\n";
    // 4. return credit after a dashboard full refund: item refund refused, payment refunded
    [$o, $pid, $iids] = $order($prov); $rma = $return($o, $iids[0]); $inv = InvoiceService::byOrderId($o);
    InvoiceService::credit((int)$inv['id'], $rma, InvoiceService::creditableQuantities($rma), "ic$rma"); $GW[$pid]['refunded'] = 3000;
    $j = $jobs($gw); echo "($prov 4) item credit 20.00 then dashboard refunded all, item refund refused: ", $state($o, $pid, $j), " rma=", Rma::byId($rma)['status'], "\n";
    if ($prov === 'comgate') {
        [$o, $pid] = $order($prov); $GW[$pid]['answer'] = 'err1100';
        PaymentService::requestRefundAndQueue($o, 500, "rq$o", ["reason" => "x"]);
        $j = $jobs($gw); echo "(comgate 5) 4xx with code 1100: ", $state($o, $pid, $j), "\n";
    }
}
echo "r50_check: ", json_encode(array_values(array_filter(r50_check($db, false), fn($x) => !str_starts_with($x, 'PAID_NO_LEDGER')))), "\n";
