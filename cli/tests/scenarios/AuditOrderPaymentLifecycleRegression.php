<?php

// Code review 2026-10-15 H1–H4: cancelled/expired orders cannot keep a payment, cancellation
// refunds go through the refund ledger and fail loudly, order transitions are compare-and-set,
// a declined payment neither blocks stock forever nor rejects a later successful payment.
echo "\n--- Code review H1–H4: order/payment lifecycle ---\n";
require_once TEST_CLI_DIR . '/../cli/worker.php';
Bootstrap::initStoreContext('/', '', 'default');
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);

$oplJobsDir = sys_get_temp_dir() . '/bbe-opl-jobs-' . getmypid() . '-' . bin2hex(random_bytes(3));
JobQueue::setDir($oplJobsDir);
$oplAdapters = new ReflectionProperty(Adapters::class, 'instances');
$oplSavedAdapters = $oplAdapters->getValue();
$oplSecret = 'opl-mock-webhook-secret-0123456789';
$oplGateway = static function (string $refund = 'succeeded') use ($oplAdapters, $oplSavedAdapters, $oplSecret): MockPayment {
    $gateway = new MockPayment(['mock_webhook_secret' => $oplSecret, 'mock_refund_result' => $refund]);
    $oplAdapters->setValue(null, ['payment' => $gateway] + $oplSavedAdapters);
    return $gateway;
};
$oplOrder = static function (string $status, string $paymentStatus, string $paymentId, int $total = 1000, string $createdAt = '') use ($pdo): int {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'OPL', 'opl@example.test', '', '{}', ?, 0, 0, 0, ?, 'eur', ?, ?, 'mock', ?, ?, 'default', " . ($createdAt === '' ? Db::now() : '?') . ")")
        ->execute(array_merge(['OPL-' . bin2hex(random_bytes(5)), $total, $total, $status, $paymentStatus, $paymentId,
            json_encode(['provider' => 'mock', 'service_type' => 'online_card', 'requires_online' => true])], $createdAt === '' ? [] : [$createdAt]));
    return (int)$pdo->lastInsertId();
};
$oplState = static function (int $orderId): array {
    $order = Orders::byId($orderId);
    return [(string)$order['status'], (string)$order['payment_status']];
};
$oplJobs = static function (int $orderId, string $type) use ($oplJobsDir): array {
    $jobs = [];
    foreach (glob($oplJobsDir . '/pending/*.json') ?: [] as $file) {
        $job = json_decode((string)file_get_contents($file), true);
        if (($job['type'] ?? '') === $type && (int)($job['payload']['order_id'] ?? 0) === $orderId) $jobs[] = $job;
    }
    return $jobs;
};
$oplWebhookPaid = static function (int $orderId, string $eventId, int $amount = 1000) use ($oplSecret): array {
    $payload = ['event_id' => $eventId] + MockPayment::paidPayload($orderId, $amount, 'mock_opl_' . $orderId);
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    return PaymentService::handleWebhook($raw, hash_hmac('sha256', $raw, $oplSecret), $payload, Adapters::payment(), 'mock');
};
$oplIncidents = static function (string $code, int $orderId) use ($pdo): int {
    $stmt = $pdo->prepare("SELECT context_json FROM operational_incidents WHERE code = ?");
    $stmt->execute([$code]);
    return count(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static fn($json): bool => (int)(json_decode((string)$json, true)['order_id'] ?? 0) === $orderId));
};

try {
    // H1: cancelling an unpaid order closes the payment and voids the provider session.
    $oplGateway();
    MockPayment::$cancelled = [];
    MockPayment::$refunds = [];
    $h1 = $oplOrder('pending', 'pending', 'mock_opl_h1');
    OrderWorkflow::transition($h1, 'cancelled', 'H1 admin cancel');
    assert_equals(['cancelled', 'cancelled'], $oplState($h1), 'H1: cancelled unpaid order closes its payment (payment_status cancelled)');
    assert_equals(0, count($oplJobs($h1, 'payment_refund')), 'H1: no refund is queued for an unpaid order');
    $h1Cancel = $oplJobs($h1, 'payment_cancel');
    assert_equals(1, count($h1Cancel), 'H1: a payment_cancel job voids the provider session');
    handlePaymentCancel($h1Cancel[0]['payload'] ?? [], $h1Cancel[0] ?? []);
    assert_equals(['mock_opl_h1'], MockPayment::$cancelled, 'H1: worker voids the session at a cancellable provider');

    // H1: money captured anyway (race, provider without void) is refunded, never kept silently.
    $h1Result = $oplWebhookPaid($h1, 'opl_evt_h1');
    assert_true($h1Result['handled'] ?? false, 'H1: paid webhook on a cancelled order is handled (no 500)');
    assert_equals(['cancelled', 'refund_pending'], $oplState($h1), 'H1: order stays cancelled, the captured payment goes on the refund ledger');
    $h1Refund = $oplJobs($h1, 'payment_refund');
    assert_equals(1, count($h1Refund), 'H1: captured payment on a cancelled order queues exactly one refund');
    assert_equals([1000, 'order_refund_' . $h1], [(int)($h1Refund[0]['payload']['amount'] ?? 0), (string)($h1Refund[0]['payload']['idempotency_key'] ?? '')], 'H1: refund covers the captured amount with a stable idempotency key');
    assert_equals(1, $oplIncidents('payment.captured_after_cancel', $h1), 'H1: a critical incident tells the merchant');
    PaymentService::settleCapture($h1, 'H1 redelivery');
    assert_equals(1, count($oplJobs($h1, 'payment_refund')), 'H1: settling the same capture again queues no second refund');
    handlePaymentRefund($h1Refund[0]['payload'], $h1Refund[0]);
    assert_equals(['refunded', 'refunded'], $oplState($h1), 'H1: completed refund moves the cancelled order to refunded');
    assert_equals([['payment_id' => 'mock_opl_h1', 'amount' => 1000]], MockPayment::$refunds, 'H1: provider refunded the captured amount once');

    // H1: an expired order (payment_status cancelled) no longer answers a late payment with 500.
    $h1e = $oplOrder('cancelled', 'cancelled', 'mock_opl_h1e');
    $h1eResult = $oplWebhookPaid($h1e, 'opl_evt_h1e');
    assert_true($h1eResult['handled'] ?? false, 'H1: paid webhook on an expired order is accepted');
    assert_equals(['cancelled', 'refund_pending'], $oplState($h1e), 'H1: late payment on an expired order is put up for refund');
    assert_equals(1, count($oplJobs($h1e, 'payment_refund')), 'H1: late payment on an expired order queues its refund');

    // H2: cancelling a paid order refunds only the refundable balance through the ledger.
    $h2 = $oplOrder('paid', 'paid', 'mock_opl_h2');
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
        VALUES (?, 'default', 'mock', 'opl_prior', 'refund_succeeded', 300, 'eur', ?, '{}')")->execute([$h2, 'opl_prior_refund_' . $h2]);
    $pdo->prepare("UPDATE orders SET payment_status = 'partially_refunded' WHERE id = ?")->execute([$h2]);
    OrderWorkflow::transition($h2, 'cancelled', 'H2 cancel paid order');
    $h2Refund = $oplJobs($h2, 'payment_refund');
    assert_equals(['cancelled', 'refund_pending'], $oplState($h2), 'H2: cancellation refund is recorded on the ledger (refund_pending)');
    assert_equals(700, (int)($h2Refund[0]['payload']['amount'] ?? 0), 'H2: cancellation refunds the refundable balance, not order.total');
    $oplGateway('failed');
    $h2Failed = false;
    try { handlePaymentRefund($h2Refund[0]['payload'], $h2Refund[0]); } catch (RuntimeException $e) { $h2Failed = true; }
    assert_true($h2Failed, 'H2: a declined provider refund fails the job instead of passing silently');
    assert_equals(['cancelled', 'refund_pending'], $oplState($h2), 'H2: a failed refund leaves the order unrefunded and visible');
    $oplGateway('succeeded');
    MockPayment::$refunds = [];
    $h2Retry = false;
    try { handlePaymentRefund($h2Refund[0]['payload'], $h2Refund[0]); } catch (RuntimeException $e) { $h2Retry = str_contains($e->getMessage(), 'did not succeed'); }
    assert_true($h2Retry, 'H2: without provider idempotency a retry is refused (no double refund)');
    assert_equals([], MockPayment::$refunds, 'H2: the refused retry never reached the provider');

    $h2b = $oplOrder('paid', 'paid', 'mock_opl_h2b');
    OrderWorkflow::transition($h2b, 'cancelled', 'H2 cancel paid order');
    $h2bRefund = $oplJobs($h2b, 'payment_refund');
    handlePaymentRefund($h2bRefund[0]['payload'], $h2bRefund[0]);
    assert_equals(['refunded', 'refunded'], $oplState($h2b), 'H2: successful cancellation refund refunds payment and order');
    assert_equals(1000, PaymentService::refundedAmount($h2b), 'H2: refund is counted in the refund ledger');

    // H2: a job queued by the previous release (no key, full total) claims the ledger first.
    $h2c = $oplOrder('cancelled', 'paid', 'mock_opl_h2c');
    $h2cPayload = ['order_id' => $h2c, 'payment_id' => 'mock_opl_h2c', 'amount' => 1000];
    handlePaymentRefund($h2cPayload, ['payload' => $h2cPayload]);
    handlePaymentRefund($h2cPayload, ['payload' => $h2cPayload]);
    assert_equals(['refunded', 'refunded'], $oplState($h2c), 'H2: legacy whole-order refund job is executed through the ledger');
    assert_equals(1, count(array_filter(MockPayment::$refunds, static fn(array $r): bool => $r['payment_id'] === 'mock_opl_h2c')), 'H2: a repeated legacy job does not refund twice');
    $h2d = $oplOrder('cancelled', 'cancelled', 'pi_opl_h2d');
    $h2dPayload = ['order_id' => $h2d, 'payment_id' => 'pi_opl_h2d', 'amount' => 1000];
    handlePaymentRefund($h2dPayload, ['payload' => $h2dPayload]);
    assert_equals(0, count(array_filter(MockPayment::$refunds, static fn(array $r): bool => $r['payment_id'] === 'pi_opl_h2d')), 'H2: legacy job for an unpaid order refunds nothing');

    // H3: status writes are compare-and-set.
    $h3 = $oplOrder('pending', 'pending', '');
    $pdo->prepare("UPDATE orders SET status = 'paid' WHERE id = ?")->execute([$h3]);
    assert_true(!Orders::updateStatus($h3, 'cancelled', 'pending'), 'H3: a transition from a status the order already left is refused');
    assert_equals('paid', $oplState($h3)[0], 'H3: the refused transition changes nothing');
    assert_true(Orders::updateStatus($h3, 'processing', 'paid'), 'H3: a transition from the current status applies');

    // H4: a declined payment can still be paid and, if not, expires and releases its stock.
    $h4 = $oplOrder('pending', 'failed', 'mock_opl_h4');
    $oplWebhookPaid($h4, 'opl_evt_h4');
    assert_equals(['paid', 'paid'], $oplState($h4), 'H4: a successful retry after a declined payment pays the order');
    $h4b = $oplOrder('pending', 'failed', '', 1000, '2000-01-01 00:00:00');
    Orders::expirePending(3600, 100, static fn(): string => 'failed');
    assert_equals(['cancelled', 'cancelled'], $oplState($h4b), 'H4: an order with a declined payment expires');
    assert_equals(1, (int)Orders::byId($h4b)['stock_restored'], 'H4: expiry of a declined order releases its reservation');
    // Gateways report a failed payment under their own state names (GoPay, PayU, ThePay); an in-progress one stays.
    foreach (['timeouted' => 'cancelled', 'rejected' => 'cancelled', 'error' => 'cancelled', 'preauth_expired' => 'cancelled',
              'preauth_cancelled' => 'cancelled', 'created' => 'pending', 'waiting_for_payment' => 'pending'] as $h4Status => $h4Expect) {
        $h4c = $oplOrder('pending', 'failed', 'opl_h4c_' . $h4Status, 1000, '2000-01-01 00:00:00');
        Orders::expirePending(3600, 100, static fn(array $o): string => $o['payment_id'] === 'opl_h4c_' . $h4Status ? $h4Status : 'created');
        assert_equals($h4Expect, $oplState($h4c)[0], "H4: a failed order with a payment the gateway reports as '{$h4Status}' ends {$h4Expect}");
        assert_equals($h4Expect === 'cancelled' ? 1 : 0, (int)Orders::byId($h4c)['stock_restored'], "H4: reservation of the '{$h4Status}' order " . ($h4Expect === 'cancelled' ? 'is released' : 'is kept'));
    }
} catch (Throwable $e) {
    // worker.php's exception handler would end the suite without the cause.
    assert_true(false, 'Order/payment lifecycle regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $oplAdapters->setValue(null, $oplSavedAdapters);
    foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
        foreach (glob($oplJobsDir . '/' . $sub . '/*.json') ?: [] as $file) @unlink($file);
        @rmdir($oplJobsDir . '/' . $sub);
    }
    @rmdir($oplJobsDir);
}
