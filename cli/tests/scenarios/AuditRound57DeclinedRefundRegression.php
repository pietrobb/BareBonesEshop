<?php

// Review round 22 (R57), medium S3: a refund ThePay/Comgate refused was checked against the payment's state with a GoPay
// notification (['id' => …]); ThePay always refused it as unverified (Comgate reports no refunds at all). A refund refused
// because the dashboard had refunded everything was then failed and retried until the job died. A partial refund refused
// while the payment stayed paid (any provider without idempotent refunds) failed the job, its retry found the attempt
// answered ("reconcile manually") and the payment stayed refund_pending, with neither confirm nor release on the order.
echo "\n--- R57 declined refunds: ThePay/Comgate/GoPay refused refunds settled by the payment state or released ---\n";

$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
require_once dirname(TEST_CLI_DIR) . '/adapters/PaymentAdapter.php';
foreach (['ThePayPayment', 'ComgatePayment', 'GoPayPayment'] as $r57dClass) require_once dirname(TEST_CLI_DIR) . "/adapters/contrib/{$r57dClass}.php";

$r57dInstances = new ReflectionProperty(Adapters::class, 'instances');
$r57dSaved = $r57dInstances->getValue();
// The provider side: per payment id its state ('paid' / 'refunded') and the answer to a refund call ('refused' / 'ok').
$r57dGw = [];
$r57dRefund = static function (string $id, int $amount) use (&$r57dGw): array {
    $r57dGw[$id]['calls']++;
    if ($r57dGw[$id]['answer'] === 'refused') return ['status' => 400, 'body' => json_encode(['message' => 'refund refused'])];
    $r57dGw[$id]['refunded'] += $amount;
    return ['status' => 200, 'body' => json_encode(['code' => 0, 'id' => (int)$id, 'result' => 'FINISHED'])];
};
$r57dThePay = new ThePayPayment(['thepay_merchant_id' => 'm', 'thepay_project_id' => '42', 'thepay_password' => 'p'],
    static function (string $method, string $url, array $headers, ?string $body) use (&$r57dGw, $r57dRefund): array {
        if (!preg_match('#/payments/([^/?]+)(/refund)?\?#', $url, $m) || !isset($r57dGw[$m[1]])) return ['status' => 404, 'body' => '{}'];
        if (($m[2] ?? '') === '/refund') return $r57dRefund($m[1], (int)json_decode((string)$body, true)['amount']);
        $p = $r57dGw[$m[1]];
        return ['status' => 200, 'body' => json_encode(['uid' => $m[1], 'project_id' => 42, 'order_id' => $p['number'], 'amount' => 3000, 'paid_amount' => 3000,
            'currency' => 'EUR', 'state' => $p['refunded'] >= 3000 ? 'refunded' : ($p['refunded'] > 0 ? 'partially_refunded' : 'paid')])];
    });
$r57dComgate = new ComgatePayment(['comgate_merchant' => 'm', 'comgate_secret' => 's'],
    static function (string $method, string $url, array $headers, ?string $body) use (&$r57dGw, $r57dRefund): array {
        if (str_ends_with($url, '/v2.0/refund.json')) {
            $form = json_decode((string)$body, true);
            return $r57dRefund((string)$form['transId'], (int)$form['amount']);
        }
        if (!preg_match('#/transId/([^/]+)\.json$#', $url, $m) || !isset($r57dGw[$m[1]])) return ['status' => 200, 'body' => json_encode(['code' => 1400])];
        // Comgate has no refunded state: a payment refunded in its portal still reads PAID.
        return ['status' => 200, 'body' => json_encode(['code' => 0, 'transId' => $m[1], 'status' => 'PAID', 'price' => '3000', 'curr' => 'EUR',
            'refId' => $r57dGw[$m[1]]['number'], 'test' => 'false'])];
    });
$r57dGoPay = new GoPayPayment(['gopay_goid' => '8123456789', 'gopay_client_id' => 'c', 'gopay_client_secret' => 's'],
    static function (string $method, string $url, array $headers, ?string $body) use (&$r57dGw, $r57dRefund): array {
        if (str_contains($url, '/oauth2/token')) return ['status' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 1800])];
        if (!preg_match('#/payments/payment/(\d+)(/refund)?$#', (string)parse_url($url, PHP_URL_PATH), $m) || !isset($r57dGw[$m[1]])) return ['status' => 404, 'body' => '{}'];
        if (($m[2] ?? '') === '/refund') {
            parse_str((string)$body, $form);
            if ($r57dGw[$m[1]]['answer'] === 'refused') {
                $r57dGw[$m[1]]['calls']++;
                return ['status' => 200, 'body' => json_encode(['id' => (int)$m[1], 'result' => 'FAILED'])];
            }
            return $r57dRefund($m[1], (int)$form['amount']);
        }
        $p = $r57dGw[$m[1]];
        return ['status' => 200, 'body' => json_encode(['id' => (int)$m[1], 'order_number' => $p['number'], 'amount' => 3000, 'currency' => 'EUR',
            'state' => $p['refunded'] >= 3000 ? 'REFUNDED' : ($p['refunded'] > 0 ? 'PARTIALLY_REFUNDED' : 'PAID'), 'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])];
    });

try {
    $r57dCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R57D', 'r57d', 'R57D', 1000, 50, 'active')")->execute([$r57dCategory]);
    $r57dProduct = (int)$pdo->lastInsertId();
    $r57dOrder = static function (string $provider, string $paymentId) use ($pdo, $r57dProduct, &$r57dGw): int {
        $number = 'R57D-' . $paymentId;
        $snapshot = VatService::buildSnapshot([['product_id' => $r57dProduct, 'sku' => 'R57D', 'qty' => 3, 'price' => 1000, 'product_type' => 'simple']],
            ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
            VALUES (?, 'R57D', 'r57d@example.test', '{\"country\":\"SK\"}', 3000, 3000, 'eur', 'processing', 'paid', ?, ?, ?, 'pickup', 'domestic', ?, 'default')")
            ->execute([$number, $provider, $paymentId, json_encode(['provider' => $provider, 'service_type' => 'online_card', 'requires_online' => true]), json_encode($snapshot)]);
        $id = (int)$pdo->lastInsertId();
        // An id earlier scenarios used for an order they deleted: their incidents are not this order's.
        $pdo->prepare('DELETE FROM operational_incidents WHERE context_json = ? OR context_json LIKE ?')->execute(['{"order_id":' . $id . '}', '{"order_id":' . $id . ',%']);
        $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R57D', 'R57D', 3, 1000, 3000)")->execute([$id, $r57dProduct]);
        InvoiceService::issue($id);
        $r57dGw[$paymentId] = ['number' => $number, 'refunded' => 0, 'calls' => 0, 'answer' => 'refused'];
        return $id;
    };
    // The admin's refund request and its payment_refund job, run by the worker; returns 'done' or the job's failure.
    $r57dRun = static function (PaymentAdapter $gateway, int $orderId, string $paymentId, int $amount, bool $request = true) use ($r57dInstances): string {
        $key = 'r57d_request_' . $paymentId;
        if ($request) PaymentService::requestRefund($orderId, $amount, $key, ['reason' => 'R57D']);
        $r57dInstances->setValue(null, ['payment' => $gateway]);
        try {
            WorkerJobs::handlePaymentRefund(['order_id' => $orderId, 'payment_id' => $paymentId, 'amount' => $amount, 'idempotency_key' => $key . '_execute'], []);
            return 'done';
        } catch (Throwable $e) {
            return 'failed: ' . $e->getMessage();
        }
    };
    $r57dState = static function (int $orderId, string $paymentId) use ($pdo, &$r57dGw): array {
        $order = Orders::byId($orderId);
        $incidents = $pdo->prepare("SELECT severity FROM operational_incidents WHERE code = 'payment.refund_failed' AND (context_json LIKE ? OR context_json LIKE ?)");
        $incidents->execute(['%"order_id":' . $orderId . ',%', '%"order_id":' . $orderId . '}%']);
        return [(string)$order['payment_status'], PaymentService::refundedAmount($orderId), $r57dGw[$paymentId]['calls'], $incidents->fetchAll(PDO::FETCH_COLUMN)];
    };

    // ThePay: the payment refunded in the dashboard, the shop's refund of 5.00 refused.
    $r57dId = $r57dOrder('thepay', 'r57d-thepay-dash');
    $r57dGw['r57d-thepay-dash']['refunded'] = 3000;
    assert_equals('done', $r57dRun($r57dThePay, $r57dId, 'r57d-thepay-dash', 500),
        'R57 S3 ThePay: a refund refused after the dashboard refunded everything finishes its job (was: failed until the job died)');
    assert_equals(['refunded', 3000, 1, []], $r57dState($r57dId, 'r57d-thepay-dash'),
        'R57 S3 ThePay: settled by the payment\'s refunded state, read through ThePay\'s own status call: 30.00 refunded, no refund_failed incident (was: unverified, nothing settled)');

    // ThePay, Comgate and GoPay: a partial refund refused while the payment stays paid.
    foreach ([['thepay', $r57dThePay, 'r57d-thepay-part'], ['comgate', $r57dComgate, 'R57D-COMGATE-PART'], ['gopay', $r57dGoPay, '5700000001']] as [$r57dProvider, $r57dGateway, $r57dPayment]) {
        $r57dId = $r57dOrder($r57dProvider, $r57dPayment);
        assert_equals('done', $r57dRun($r57dGateway, $r57dId, $r57dPayment, 500),
            "R57 S3 {$r57dProvider}: a partial refund the provider refused finishes its job (was: failed, its retry \"reconcile manually\")");
        assert_equals(['paid', 0, 1, ['critical']], $r57dState($r57dId, $r57dPayment),
            "R57 S3 {$r57dProvider}: the refused request is released, the payment paid again, the merchant told (was: refund_pending for ever)");
        assert_equals('done', $r57dRun($r57dGateway, $r57dId, $r57dPayment, 500, false),
            "R57 S3 {$r57dProvider}: the released job run again calls the provider no more");
        assert_equals(1, $r57dGw[$r57dPayment]['calls'], "R57 S3 {$r57dProvider}: one provider call in all");
        $r57dGw[$r57dPayment]['answer'] = 'ok';
        PaymentService::requestRefund($r57dId, 500, 'r57d_again_' . $r57dPayment, ['reason' => 'R57D again']);
        assert_equals('refund_pending', (string)Orders::byId($r57dId)['payment_status'], "R57 S3 {$r57dProvider}: the admin can request the refund anew");
    }
} catch (Throwable $e) {
    assert_true(false, 'R57 declined refunds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r57dInstances->setValue(null, $r57dSaved);
}
