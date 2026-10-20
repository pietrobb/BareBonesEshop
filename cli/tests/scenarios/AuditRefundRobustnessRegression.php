<?php

// Payment review (refunds, webhook robustness): a refund is booked once per provider refund id whether the
// worker or the provider's webhook sees it first; an admin refund request is executed (or completed by hand);
// a paid order is not "refunded" while its money is kept; asynchronous item refunds are completed by their
// webhook; pulled-notification failures raise incidents; partial refunds keep paid downloads.
echo "\n--- Payment review: refund robustness ---\n";
require_once TEST_CLI_DIR . '/../cli/worker.php';
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php';
if (!function_exists('applyStripeChargeRefunded')) require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
Bootstrap::initStoreContext('/', '', 'default');
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);

if (!class_exists('ArrFakeStripe', false)) {
    /** Stripe without network: idempotent refunds answer one refund id per key, the refund list is set by the test. */
    class ArrFakeStripe extends StripePayment {
        public string $refundStatus = 'succeeded';
        /** @var list<array<string, mixed>>|null */
        public ?array $listed = null;
        public int $calls = 0;
        public function __construct() { parent::__construct([]); }
        public static function refundIdFor(string $key): string { return 're_arr_' . substr(sha1($key), 0, 12); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            $this->calls++;
            return ['refund_id' => self::refundIdFor($idempotencyKey), 'status' => $this->refundStatus];
        }
        public function chargeRefunds(string $chargeId): ?array { return $this->listed; }
    }
    class ArrFakePayU extends PayUPayment {
        public int $calls = 0;
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            $this->calls++;
            return ['refund_id' => 'RF-ARR-' . $amount, 'status' => 'pending'];
        }
    }
}

$arrJobsDir = sys_get_temp_dir() . '/bbe-arr-jobs-' . getmypid() . '-' . bin2hex(random_bytes(3));
JobQueue::setDir($arrJobsDir);
$arrAdapters = new ReflectionProperty(Adapters::class, 'instances');
$arrSavedAdapters = $arrAdapters->getValue();
$arrUse = static function (PaymentAdapter $gateway) use ($arrAdapters, $arrSavedAdapters): PaymentAdapter {
    $arrAdapters->setValue(null, ['payment' => $gateway] + $arrSavedAdapters);
    return $gateway;
};
$arrOrder = static function (string $status, string $paymentStatus, string $provider, string $paymentId, int $total = 1000) use ($pdo): int {
    $snapshot = ['provider' => $provider, 'service_type' => $provider === 'manual' ? 'bank_transfer' : 'online_card', 'requires_online' => $provider !== 'manual'];
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'ARR', 'arr@example.test', '', '{}', ?, 0, 0, 0, ?, 'eur', ?, ?, ?, ?, ?, 'default', " . Db::now() . ")")
        ->execute(['ARR-' . bin2hex(random_bytes(5)), $total, $total, $status, $paymentStatus, $provider, $paymentId, json_encode($snapshot)]);
    return (int)$pdo->lastInsertId();
};
$arrState = static function (int $orderId): array {
    $order = Orders::byId($orderId);
    return [(string)$order['status'], (string)$order['payment_status'], PaymentService::refundedAmount($orderId)];
};
$arrJobs = static function (int $orderId) use ($arrJobsDir): array {
    $jobs = [];
    foreach (glob($arrJobsDir . '/pending/*.json') ?: [] as $file) {
        $job = json_decode((string)file_get_contents($file), true);
        if (($job['type'] ?? '') === 'payment_refund' && (int)($job['payload']['order_id'] ?? 0) === $orderId) $jobs[] = $job;
    }
    return $jobs;
};
$arrLedger = static function (int $orderId, string $status) use ($pdo): array {
    $stmt = $pdo->prepare("SELECT amount FROM payment_transactions WHERE order_id = ? AND status = ? ORDER BY id");
    $stmt->execute([$orderId, $status]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};
$arrItemRefund = static function (int $orderId, int $amount, string $key) use ($pdo): int {
    $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, 0, 0, ?, 'eur', 'pending', ?)")
        ->execute([$orderId, $amount, $key]);
    return (int)$pdo->lastInsertId();
};
$arrItemRefundRow = static function (int $refundId) use ($pdo): array {
    $row = $pdo->query('SELECT status, payment_reference FROM order_refunds WHERE id = ' . $refundId)->fetch(PDO::FETCH_ASSOC);
    return [(string)$row['status'], (string)$row['payment_reference']];
};
$arrRefundableForCredit = new ReflectionMethod(InvoiceService::class, 'refundableForCredit');
$arrPayUKey = 'arr-payu-second-key-0123456789';
$arrPayUWebhook = static function (PaymentAdapter $gateway, int $orderId, string $refundId, int $amount, string $status) use ($arrPayUKey): array {
    $order = Orders::byId($orderId);
    $raw = json_encode(['orderId' => (string)$order['payment_id'], 'extOrderId' => (string)$order['order_number'],
        'refund' => ['refundId' => $refundId, 'amount' => (string)$amount, 'currencyCode' => 'EUR', 'status' => $status]], JSON_THROW_ON_ERROR);
    return PaymentService::handleWebhook($raw, 'sender=checkout;signature=' . md5($raw . $arrPayUKey) . ';algorithm=MD5;content=DOCUMENT', json_decode($raw, true), $gateway, 'payu');
};
$arrGoPayState = 'PAID';
$arrGoPayRefund = 'ACCEPTED';
$arrGoPayFail = false;
$arrGoPay = new GoPayPayment(['gopay_goid' => '8123456789', 'gopay_client_id' => 'c', 'gopay_client_secret' => 's'],
    static function (string $method, string $url, array $headers, ?string $body) use (&$arrGoPayState, &$arrGoPayRefund, &$arrGoPayFail): array {
        if (str_contains($url, 'oauth2')) return ['status' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 1800])];
        if ($arrGoPayFail) return ['status' => 503, 'body' => '{}'];
        if (str_ends_with($url, '/refund')) return ['status' => 200, 'body' => json_encode(['id' => 3000000101, 'result' => $arrGoPayRefund])];
        $id = (int)substr($url, strrpos($url, '/') + 1);
        return ['status' => 200, 'body' => json_encode(['id' => $id, 'state' => $arrGoPayState, 'order_number' => 'ARR-GOPAY-' . $id, 'amount' => 1000,
            'currency' => 'EUR', 'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])];
    });

try {
    // 1. Stripe: the charge.refunded webhook and the refund worker book one refund once, in either order.
    $stripe = $arrUse(new ArrFakeStripe());
    $r1 = $arrOrder('paid', 'paid', 'stripe', 'pi_arr_1');
    OrderWorkflow::transition($r1, 'cancelled', 'ARR 1 cancel');
    $r1Job = $arrJobs($r1)[0] ?? null;
    assert_true($r1Job !== null, 'ARR 1: cancelling a paid order queues its refund');
    $r1Refund = ArrFakeStripe::refundIdFor('order_refund_' . $r1);
    applyStripeChargeRefunded(['id' => 'ch_arr_1', 'payment_intent' => 'pi_arr_1', 'amount_refunded' => 1000,
        'refunds' => ['data' => [['id' => $r1Refund, 'amount' => 1000, 'status' => 'succeeded']], 'has_more' => false]], 'pi_arr_1', $r1);
    assert_equals(['refunded', 'refunded', 1000], $arrState($r1), 'ARR 1: the webhook seen first books the refund and closes the order');
    handlePaymentRefund($r1Job['payload'], $r1Job);
    assert_equals(['refunded', 'refunded', 1000], $arrState($r1), 'ARR 1: the worker then finds the refund id on the ledger and books nothing more (was 2000)');
    assert_equals([1000], $arrLedger($r1, 'refund_confirmed'), 'ARR 1: the worker records a non-money confirmation of the booked refund');
    handlePaymentRefund($r1Job['payload'], $r1Job);
    assert_equals([1, 1000], [$stripe->calls, PaymentService::refundedAmount($r1)], 'ARR 1: a redelivered job neither calls Stripe again nor books money');

    $r1b = $arrOrder('paid', 'paid', 'stripe', 'pi_arr_1b');
    OrderWorkflow::transition($r1b, 'cancelled', 'ARR 1b cancel');
    $r1bJob = $arrJobs($r1b)[0];
    handlePaymentRefund($r1bJob['payload'], $r1bJob);
    applyStripeChargeRefunded(['id' => 'ch_arr_1b', 'payment_intent' => 'pi_arr_1b', 'amount_refunded' => 1000,
        'refunds' => ['data' => [['id' => ArrFakeStripe::refundIdFor('order_refund_' . $r1b), 'amount' => 1000, 'status' => 'succeeded']]]], 'pi_arr_1b', $r1b);
    assert_equals(['refunded', 'refunded', 1000], $arrState($r1b), 'ARR 1: worker first, webhook second books the refund once');

    // Partial refund: an admin request of 400 on a 1000 order, webhook first.
    $r1c = $arrOrder('delivered', 'paid', 'stripe', 'pi_arr_1c');
    PaymentService::requestRefundAndQueue($r1c, 400, 'arr_1c_request', ['reason' => 'ARR']);
    $r1cJob = $arrJobs($r1c)[0] ?? null;
    assert_equals(['pi_arr_1c', 400, 'arr_1c_request_execute'], [$r1cJob['payload']['payment_id'] ?? null, $r1cJob['payload']['amount'] ?? null, $r1cJob['payload']['idempotency_key'] ?? null],
        'ARR 2: an admin refund request queues the payment_refund job that executes it');
    applyStripeChargeRefunded(['id' => 'ch_arr_1c', 'payment_intent' => 'pi_arr_1c', 'amount_refunded' => 400,
        'refunds' => ['data' => [['id' => ArrFakeStripe::refundIdFor('arr_1c_request_execute'), 'amount' => 400, 'status' => 'succeeded']]]], 'pi_arr_1c', $r1c);
    handlePaymentRefund($r1cJob['payload'], $r1cJob);
    assert_equals([['delivered', 'partially_refunded', 400], 600], [$arrState($r1c), PaymentService::refundableBalance($r1c)], 'ARR 1: a partial refund is booked once (was 800, balance 200)');

    // Events without embedded refunds: the refund list comes from the Stripe API, keyed by refund id.
    $r1d = $arrOrder('delivered', 'paid', 'stripe', 'pi_arr_1d');
    $stripe->listed = [['id' => 're_arr_1d_a', 'amount' => 300, 'status' => 'succeeded']];
    applyStripeChargeRefunded(['id' => 'ch_arr_1d', 'payment_intent' => 'pi_arr_1d', 'amount_refunded' => 300], 'pi_arr_1d', $r1d);
    $stripe->listed = [['id' => 're_arr_1d_a', 'amount' => 300, 'status' => 'succeeded'], ['id' => 're_arr_1d_b', 'amount' => 200, 'status' => 'succeeded'], ['id' => 're_arr_1d_c', 'amount' => 500, 'status' => 'failed']];
    applyStripeChargeRefunded(['id' => 'ch_arr_1d', 'payment_intent' => 'pi_arr_1d', 'amount_refunded' => 500], 'pi_arr_1d', $r1d);
    applyStripeChargeRefunded(['id' => 'ch_arr_1d', 'payment_intent' => 'pi_arr_1d', 'amount_refunded' => 300], 'pi_arr_1d', $r1d);
    assert_equals(['delivered', 'partially_refunded', 500], $arrState($r1d), 'ARR 1: listed refunds are booked once per id, failed ones never, a late event changes nothing');
    // No refund ids at all: the cumulative difference is computed under the order lock, out-of-order events cannot over-book.
    $stripe->listed = null;
    $r1e = $arrOrder('delivered', 'paid', 'stripe', 'pi_arr_1e');
    applyStripeChargeRefunded(['id' => 'ch_arr_1e', 'payment_intent' => 'pi_arr_1e', 'amount_refunded' => 700], 'pi_arr_1e', $r1e);
    applyStripeChargeRefunded(['id' => 'ch_arr_1e', 'payment_intent' => 'pi_arr_1e', 'amount_refunded' => 400], 'pi_arr_1e', $r1e);
    assert_equals(['delivered', 'partially_refunded', 700], $arrState($r1e), 'ARR 1: without refund ids only the cumulative difference is booked');
    assert_true(str_contains((string)file_get_contents(TEST_CLI_DIR . '/../src/PaymentService.php'), 'Orders::lockWithin($orderId)')
        && str_contains((string)file_get_contents(TEST_CLI_DIR . '/../src/Orders.php'), 'FROM orders WHERE id = :id AND store_id = :store FOR UPDATE'), 'ARR 1: MySQL/MariaDB lock the order row while booking a refund');

    // 2. GoPay: the refund request is executed; GoPay accepts it asynchronously and its REFUNDED notification completes it.
    $arrUse($arrGoPay);
    $r2 = $arrOrder('delivered', 'paid', 'gopay', '3000000201');
    $pdo->prepare("UPDATE orders SET order_number = 'ARR-GOPAY-3000000201' WHERE id = ?")->execute([$r2]);
    PaymentService::requestRefundAndQueue($r2, 1000, 'arr_2_request', ['reason' => 'ARR']);
    $r2Job = $arrJobs($r2)[0];
    handlePaymentRefund($r2Job['payload'], $r2Job);
    assert_equals(['delivered', 'refund_pending', 0], $arrState($r2), 'ARR 2: an accepted (pending) GoPay refund waits for its notification');
    $arrGoPayState = 'REFUNDED';
    PaymentService::handlePulledNotification(['id' => '3000000201'], $arrGoPay, 'gopay');
    assert_equals(['delivered', 'refunded', 1000], $arrState($r2), 'ARR 2: the GoPay REFUNDED notification completes the refund in flight (was stuck in refund_pending)');
    // A REFUNDED report without a matching refund in flight moves no money.
    $r2b = $arrOrder('delivered', 'paid', 'gopay', '3000000202');
    $pdo->prepare("UPDATE orders SET order_number = 'ARR-GOPAY-3000000202' WHERE id = ?")->execute([$r2b]);
    PaymentService::handlePulledNotification(['id' => '3000000202'], $arrGoPay, 'gopay');
    assert_equals(['delivered', 'paid', 0], $arrState($r2b), 'ARR 2: a refund report the shop did not request stays a log entry');
    $r2c = $arrOrder('delivered', 'paid', 'gopay', '3000000203');
    $pdo->prepare("UPDATE orders SET order_number = 'ARR-GOPAY-3000000203' WHERE id = ?")->execute([$r2c]);
    PaymentService::requestRefund($r2c, 300, 'arr_2c_request');
    PaymentService::handlePulledNotification(['id' => '3000000203'], $arrGoPay, 'gopay');
    assert_equals(['delivered', 'refund_pending', 0], $arrState($r2c), 'ARR 2: REFUNDED does not complete a refund smaller than the remaining balance');

    // Manual refund completed: offline money (no provider refund API), audited, partial then full.
    $r2m = $arrOrder('cancelled', 'paid', 'manual', '');
    assert_true(PaymentService::refundsByHand(Orders::byId($r2m)) && !PaymentService::refundsByHand(Orders::byId($r1c)), 'ARR 2: offline payments are refunded by hand, provider payments are not');
    PaymentService::completeManualRefund($r2m, 400, 'arr-admin', 'Bank transfer back');
    assert_equals(['cancelled', 'partially_refunded', 400], $arrState($r2m), 'ARR 2: a manual partial refund is recorded on the ledger');
    $r2mRefused = false;
    try { PaymentService::completeManualRefund($r2m, 601, 'arr-admin', 'too much'); } catch (LogicException $e) { $r2mRefused = true; }
    assert_true($r2mRefused, 'ARR 2: a manual refund above the remaining balance is refused');
    PaymentService::completeManualRefund($r2m, 600, 'arr-admin', 'Rest');
    assert_equals(['refunded', 'refunded', 1000], $arrState($r2m), 'ARR 2: the manual full refund closes the cancelled order');
    $r2mAudit = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'order_refund' AND entity_id = '{$r2m}' AND note LIKE '%arr-admin%'")->fetchColumn();
    assert_equals(2, $r2mAudit, 'ARR 2: each manual refund is audited with the acting admin');
    $r2mOnline = false;
    try { PaymentService::completeManualRefund($r1d, 100, 'arr-admin', 'not allowed'); } catch (LogicException $e) { $r2mOnline = true; }
    assert_true($r2mOnline, 'ARR 2: a provider payment cannot be marked refunded by hand');
    $arrAdminSource = entrypointSource('admin.php');
    assert_true(str_contains($arrAdminSource, "'order:refund_manual' => handleOrderManualRefund()")
        && str_contains($arrAdminSource, "function handleOrderManualRefund(): void {\n    Security::adminRequire('orders.refund');"),
        'ARR 2: the manual refund admin action is routed and needs the refund permission');

    // 3. A paid order is not "refunded" (stock, downloads, email) while its money is still kept.
    $r3 = $arrOrder('paid', 'paid', 'stripe', 'pi_arr_3');
    assert_equals('refund_outstanding', OrderWorkflow::adminRejection(Orders::byId($r3), 'refunded', true), 'ARR 3: admin refunded on a paid order with a balance is refused');
    $r3Refused = false;
    try { OrderWorkflow::transition($r3, 'refunded', 'ARR 3'); } catch (LogicException $e) { $r3Refused = true; }
    assert_equals([true, ['paid', 'paid', 0], []], [$r3Refused, $arrState($r3), $arrJobs($r3)], 'ARR 3: the transition changes nothing and queues nothing');
    assert_equals(['refund_outstanding'], array_values(OrderWorkflow::bulkTransition([$r3], 'refunded', 'ARR 3', true)['rejected']), 'ARR 3: bulk refunded reports the outstanding refund');
    $stripe = $arrUse(new ArrFakeStripe());
    applyStripeChargeRefunded(['id' => 'ch_arr_3', 'payment_intent' => 'pi_arr_3', 'amount_refunded' => 1000,
        'refunds' => ['data' => [['id' => 're_arr_3', 'amount' => 1000, 'status' => 'succeeded']]]], 'pi_arr_3', $r3);
    assert_equals(['refunded', 'refunded', 1000], $arrState($r3), 'ARR 3: once the money went back the order closes as refunded');
    // Round 5: returned and cancelled orders with money still kept are not "refunded" either.
    $r5Returned = $arrOrder('returned', 'paid', 'stripe', 'pi_arr_5r');
    $r5Cancelled = $arrOrder('cancelled', 'refund_pending', 'stripe', 'pi_arr_5c');
    $r5Settled = $arrOrder('cancelled', 'refunded', 'stripe', 'pi_arr_5s');
    assert_equals(['refund_outstanding', 'refund_outstanding', null], [OrderWorkflow::adminRejection(Orders::byId($r5Returned), 'refunded', true),
        OrderWorkflow::adminRejection(Orders::byId($r5Cancelled), 'refunded', true), OrderWorkflow::adminRejection(Orders::byId($r5Settled), 'refunded', true)],
        'ARR 5: returned/cancelled -> refunded is refused while a refundable balance remains, allowed once the money went back');
    // Cancelling captured money refunds it: without the refund right (fulfillment role, API key without refunds:write) it is refused.
    $r5Paid = $arrOrder('paid', 'paid', 'stripe', 'pi_arr_5p');
    $r5Unpaid = $arrOrder('pending', 'pending', 'stripe', 'pi_arr_5u');
    assert_equals(['forbidden', null, null, ['forbidden']], [OrderWorkflow::adminRejection(Orders::byId($r5Paid), 'cancelled', false),
        OrderWorkflow::adminRejection(Orders::byId($r5Paid), 'cancelled', true), OrderWorkflow::adminRejection(Orders::byId($r5Unpaid), 'cancelled', false),
        array_values(OrderWorkflow::bulkTransition([$r5Paid], 'cancelled', 'ARR 5', false)['rejected'])],
        'ARR 5: cancelling a paid order needs the refund right; an unpaid order can be cancelled without it');
    $r5Api = new ReflectionMethod(ApiV1::class, 'changeOrderStatus');
    $r5ApiRefused = '';
    try { $r5Api->invoke(null, $r5Paid, ['status' => 'cancelled'], 'arr-5-key', ['key_id' => 'arr5', 'scopes' => ['orders:write'], 'store_id' => 'default'], '/api/v1/orders/' . $r5Paid . '/status'); }
    catch (DomainException $e) { $r5ApiRefused = $e->getMessage(); }
    assert_equals(['Missing API scope: refunds:write', ['paid', 'paid', 0], []], [$r5ApiRefused, $arrState($r5Paid), $arrJobs($r5Paid)],
        'ARR 5: an API key with orders:write but without refunds:write cannot cancel (refund) a paid order');
    $r5Admin = entrypointSource('admin.php');
    assert_true(str_contains($r5Admin, "if (\$newStatus === 'refunded' || (\$order && OrderWorkflow::returnsMoney(\$order, \$newStatus))) Security::adminRequire('orders.refund');"),
        'ARR 5: the admin status change requires orders.refund for a cancellation that refunds');

    // 4. Item (credit note) refunds: PayU answers PENDING, the FINALIZED notification completes the credit note.
    $arrStatus = new ReflectionMethod(PayUPayment::class, 'refundStatus');
    assert_equals(['succeeded', 'pending', 'failed'], [$arrStatus->invoke(null, 'FINALIZED'), $arrStatus->invoke(null, 'PENDING'), $arrStatus->invoke(null, 'CANCELED')], 'ARR 4: PayU refund FINALIZED maps to succeeded');
    $payu = $arrUse(new ArrFakePayU(['payu_second_key' => $arrPayUKey]));
    $r4 = $arrOrder('delivered', 'refund_pending', 'payu', 'PAYU-ARR-4');
    $r4Item = $arrItemRefund($r4, 400, 'arr-credit-4');
    handlePaymentRefund(['order_id' => $r4, 'payment_id' => 'PAYU-ARR-4', 'amount' => 400, 'refund_id' => $r4Item, 'idempotency_key' => 'arr-credit-4'], []);
    assert_equals([1, ['pending', 'RF-ARR-400']], [$payu->calls, $arrItemRefundRow($r4Item)], 'ARR 4: a pending item refund is accepted (no failing job) and remembers the provider refund id');
    $arrPayUWebhook($payu, $r4, 'RF-ARR-400', 400, 'FINALIZED');
    assert_equals([['delivered', 'partially_refunded', 400], ['succeeded', 'RF-ARR-400']], [$arrState($r4), $arrItemRefundRow($r4Item)], 'ARR 4: the refund webhook books the money and completes the credit note');
    assert_equals(600, $arrRefundableForCredit->invoke(null, Orders::byId($r4)), 'ARR 4: refundable for further credit notes is 600 (was 200)');
    // Matched by amount when the provider refund id was never noted (e.g. the worker stopped after the call).
    $r4b = $arrOrder('delivered', 'refund_pending', 'payu', 'PAYU-ARR-4B');
    $r4bItem = $arrItemRefund($r4b, 300, 'arr-credit-4b');
    $arrPayUWebhook($payu, $r4b, 'RF-ARR-4B', 300, 'FINALIZED');
    assert_equals('succeeded', $arrItemRefundRow($r4bItem)[0], 'ARR 4: a refund webhook completes the pending credit note of the same amount');
    // A credit note whose refund the ledger already holds is not subtracted twice.
    $r4c = $arrOrder('delivered', 'refund_pending', 'payu', 'PAYU-ARR-4C');
    $arrItemRefund($r4c, 400, 'arr-credit-4c');
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response) VALUES (?, 'default', 'payu', 'RF-ARR-4C', 'refund_succeeded', 400, 'eur', 'arr-credit-4c', '{}')")->execute([$r4c]);
    assert_equals(600, $arrRefundableForCredit->invoke(null, Orders::byId($r4c)), 'ARR 4: a pending credit note already refunded on the ledger is subtracted once');
    // Stripe answers 'pending' for an item refund: accepted, completed by charge.refunded via its refund id.
    $stripe = $arrUse(new ArrFakeStripe());
    $stripe->refundStatus = 'pending';
    $r4d = $arrOrder('delivered', 'refund_pending', 'stripe', 'pi_arr_4d');
    $r4dItem = $arrItemRefund($r4d, 250, 'arr-credit-4d');
    handlePaymentRefund(['order_id' => $r4d, 'payment_id' => 'pi_arr_4d', 'amount' => 250, 'refund_id' => $r4dItem, 'idempotency_key' => 'arr-credit-4d'], []);
    applyStripeChargeRefunded(['id' => 'ch_arr_4d', 'payment_intent' => 'pi_arr_4d', 'amount_refunded' => 250,
        'refunds' => ['data' => [['id' => ArrFakeStripe::refundIdFor('arr-credit-4d'), 'amount' => 250, 'status' => 'succeeded']]]], 'pi_arr_4d', $r4d);
    assert_equals([['delivered', 'partially_refunded', 250], 'succeeded'], [$arrState($r4d), $arrItemRefundRow($r4dItem)[0]], 'ARR 4: a Stripe pending item refund is completed by its charge.refunded event');

    // 5. A GoPay/Comgate/ThePay notification that fails after verification raises the critical incident.
    $arrUse($arrGoPay);
    $arrIncidents = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'payment.webhook_failed'")->fetchColumn();
    $r5Before = $arrIncidents();
    $arrGoPayFail = true;
    $arrSavedGet = $_GET;
    $_GET = ['id' => '3000000501'];
    $r5 = with_expected_error_log('webhook_payment [gopay] error', static fn() => apiWebhookPulledNotification('gopay'));
    $_GET = $arrSavedGet;
    $arrGoPayFail = false;
    assert_equals([['error' => 'Processing failed'], $r5Before + 1], [$r5, $arrIncidents()], 'ARR 5: a failing pulled notification reports payment.webhook_failed');

    // 6. A partial refund keeps paid downloads, except of the credited line.
    $r6 = $arrOrder('delivered', 'partially_refunded', 'stripe', 'pi_arr_6');
    $r6Product = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'ARR-DIGI', 'Guide', 1, 500, 500)")->execute([$r6, $r6Product]);
    $r6Item = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'ARR-DIGI', 'Guide 2', 1, 500, 500)")->execute([$r6, $r6Product]);
    $r6Item2 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path) VALUES ('ARR-DIGI', 'arr-" . bin2hex(random_bytes(3)) . "', 'Guide', 'arr/guide.pdf')");
    $r6Asset = (int)$pdo->lastInsertId();
    $r6Tokens = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
    foreach ([$r6Item, $r6Item2] as $i => $item) {
        $pdo->prepare("INSERT INTO digital_entitlements (order_id, order_item_id, asset_id, token_hash, status) VALUES (?, ?, ?, ?, 'active')")->execute([$r6, $item, $r6Asset, hash('sha256', $r6Tokens[$i])]);
    }
    $r6Refund = $arrItemRefund($r6, 500, 'arr-credit-6');
    $pdo->prepare("INSERT INTO credit_note_items (credit_note_id, refund_id, order_item_id, rma_item_id, sku, name, quantity, unit_gross, gross, net, vat, rate_basis_points) VALUES (0, ?, ?, 0, 'ARR-DIGI', 'Guide 2', 1, 500, 500, 400, 100, 2500)")
        ->execute([$r6Refund, $r6Item2]);
    assert_equals([true, false], [DigitalDelivery::resolve($r6Tokens[0]) !== null, DigitalDelivery::resolve($r6Tokens[1]) !== null], 'ARR 6: after a partial refund the paid line still downloads, the credited line does not');
    $pdo->prepare("UPDATE orders SET payment_status = 'refund_pending' WHERE id = ?")->execute([$r6]);
    assert_true(DigitalDelivery::resolve($r6Tokens[0]) !== null, 'ARR 6: a refund in flight keeps paid downloads');
    $pdo->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = ?")->execute([$r6]);
    assert_true(DigitalDelivery::resolve($r6Tokens[0]) === null, 'ARR 6: a fully refunded payment ends downloads');
} catch (Throwable $e) {
    assert_true(false, 'Refund robustness regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $arrAdapters->setValue(null, $arrSavedAdapters);
    foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
        foreach (glob($arrJobsDir . '/' . $sub . '/*.json') ?: [] as $file) @unlink($file);
        @rmdir($arrJobsDir . '/' . $sub);
    }
    @rmdir($arrJobsDir);
}
