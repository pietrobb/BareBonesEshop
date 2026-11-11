<?php

// Review round 29 (2026-11-11, main @ d9dc6722), goal #410:
// - finding 4: an item refund job that ended while an admin refund was in flight took the $itemRefund branch of
//   PaymentService::settleRefundJob() (PaymentService.php:950) and moved the payment out of refund_pending: the request's
//   money counted as free, a second admin refund paid it again (the customer got 22.00 instead of 40.99), no warning.
// - finding 5: Stripe declined the admin refund for good (a 4xx); the job retried the same request until it died, and once a
//   dashboard refund was booked the retry was hidden and no new refund could be requested: refund_pending forever.
echo "\n--- R29: an item refund during an admin refund keeps it in flight; a declined admin refund is released ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
JobQueue::setDir(test_fixture_path('r29f-jobs'));
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R29fFakeStripe', false)) {
    class R29fFakeStripe extends StripePayment {
        /** null: the refund succeeds; an int: Stripe answers with that HTTP code. */
        public static ?int $answer = null;
        public static int $calls = 0;
        /** true: the refund names no id, as GoPay/ThePay refunds do. */
        public static bool $noId = false;
        public function __construct() { parent::__construct([]); }
        protected function apiCall(string $method, string $endpoint, array $params = [], array $extraHeaders = []): array {
            self::$calls++;
            if (self::$answer !== null) throw new \RuntimeException('Stripe API: Charge has already been refunded', self::$answer);
            return ['id' => self::$noId ? '' : 're_r29f_' . substr(sha1((string)($params['metadata'][self::REFUND_KEY_METADATA] ?? uniqid())), 0, 10), 'status' => 'succeeded'];
        }
    }
}
$r29fAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r29fSavedAdapters = $r29fAdapters->getValue();
$r29fAdapters->setValue(null, ['payment' => new R29fFakeStripe()] + $r29fSavedAdapters);
$r29fProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r29fOrders = [];
$r29fOrder = static function (string $number) use ($pdo, $r29fProduct, &$r29fOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['product_id' => 1, 'variant_id' => null, 'sku' => "{$number}-PB", 'price' => 4200, 'qty' => 1, 'total' => 4200, 'available' => true,
        'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'books', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $items = Cart::applyVat(['items' => [$line], 'subtotal' => 4200, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R29F', 'r29f@example.test', '', '{\"country\":\"SK\"}', 4200, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$number, (int)$snapshot['totals']['gross'], 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card']),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $id = $r29fOrders[] = (int)$pdo->lastInsertId();
    // SQLite reuses the id of an order an earlier scenario deleted; rows it left under that id are not this order's.
    foreach (['payment_transactions', 'order_refunds'] as $r29fStale) $pdo->prepare("DELETE FROM {$r29fStale} WHERE order_id = ?")->execute([$id]);
    $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 4200, 4200)')
        ->execute([$id, $r29fProduct, "{$number}-PB", "R29F {$number}"]);
    InvoiceService::issue($id);
    return $id;
};
$r29fState = static fn(int $orderId): array => [PaymentService::refundedAmount($orderId), (string)Orders::byId($orderId)['payment_status'],
    PaymentService::requestedRefundInFlight(Orders::byId($orderId))];
$r29fJob = static fn(int $orderId, int $amount, string $key, int $itemRefund = 0) => WorkerJobs::handlePaymentRefund(['order_id' => $orderId,
    'payment_id' => (string)Orders::byId($orderId)['payment_id'], 'amount' => $amount, 'idempotency_key' => $key] + ($itemRefund > 0 ? ['refund_id' => $itemRefund] : []), []);
$r29fItemRefund = static function (int $orderId, int $amount, string $key) use ($pdo): int {
    $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, 0, 0, ?, 'eur', 'pending', ?)")
        ->execute([$orderId, $amount, $key]);
    return (int)$pdo->lastInsertId();
};
$r29fDashboard = static function (int $orderId, string $id, int $amount): void {
    PaymentService::bookProviderRefund($orderId, 'stripe', $id, $amount, 'refund.succeeded', 'stripe_refund_' . $id, ['refund_id' => $id, 'amount' => $amount, 'currency' => 'eur']);
};
$r29fThrows = static function (Closure $call): string {
    try {
        $call();
        return 'none';
    } catch (Throwable $e) {
        return get_class($e);
    }
};

try {
    // 1. Finding 4: the admin refunds 10.00; an item refund of 3.00 runs and ends while the admin refund is in flight.
    $o1 = $r29fOrder('R29F-1');
    $total1 = (int)Orders::byId($o1)['total'];
    PaymentService::requestRefundAndQueue($o1, 1000, 'r29f-1', ['reason' => 'R29F']);
    $r29fJob($o1, 300, 'r29f-1-item', $r29fItemRefund($o1, 300, 'r29f-1-item'));
    assert_equals([300, 'refund_pending', 1000], $r29fState($o1),
        'R29 refunds: an item refund job during an admin refund books its 3.00 and the 10.00 stays in flight (was: partially_refunded, in flight 0)');
    assert_equals('LogicException', $r29fThrows(static fn() => PaymentService::requestRefund($o1, 500, 'r29f-1-second')),
        'R29 refunds: no second admin refund while the first is in flight (was: requested and paid again)');
    $r29fJob($o1, 1000, 'r29f-1_execute');
    assert_equals([1300, 'partially_refunded', 0], $r29fState($o1), 'R29 refunds: the admin refund\'s job then settles it: 13.00 refunded, partially_refunded');
    assert_true(PaymentService::claimableRefund($o1) === $total1 - 1300, 'R29 refunds: what is left to refund is the total less both refunds');

    // 2. Finding 5: Stripe declines the admin refund for good after a dashboard refund of 5.00 was booked.
    $o2 = $r29fOrder('R29F-2');
    PaymentService::requestRefundAndQueue($o2, 1000, 'r29f-2', ['reason' => 'R29F']);
    $r29fDashboard($o2, 're_r29f_dash2', 500);
    R29fFakeStripe::$answer = 400;
    R29fFakeStripe::$calls = 0;
    $declined = $r29fThrows(static fn() => $r29fJob($o2, 1000, 'r29f-2_execute'));
    R29fFakeStripe::$answer = null;
    assert_equals(['none', 1, [500, 'partially_refunded', 0]], [$declined, R29fFakeStripe::$calls, $r29fState($o2)],
        'R29 refunds: a refund Stripe declines (400) is released at once: partially_refunded, nothing in flight, the job not retried (was: refund_pending forever)');
    $incident2 = $pdo->prepare("SELECT COUNT(*) FROM operational_incidents WHERE code = 'payment.refund_failed' AND context_json = ?");
    $incident2->execute([json_encode(['order_id' => $o2])]);
    assert_true((int)$incident2->fetchColumn() >= 1, 'R29 refunds: the declined refund raises payment.refund_failed for the merchant');
    assert_true(PaymentService::requestRefund($o2, 1000, 'r29f-2-again'), 'R29 refunds: the admin can then request the refund again');

    // 3. A transient failure (Stripe 500/429) still fails the job for a retry, and the retry stays offered after a dashboard refund.
    $o3 = $r29fOrder('R29F-3');
    PaymentService::requestRefundAndQueue($o3, 1000, 'r29f-3', ['reason' => 'R29F']);
    $transient = [];
    foreach ([500, 429, 401] as $r29fCode) {
        R29fFakeStripe::$answer = $r29fCode;
        $transient[] = $r29fThrows(static fn() => $r29fJob($o3, 1000, 'r29f-3_execute'));
    }
    R29fFakeStripe::$answer = null;
    $r29fDashboard($o3, 're_r29f_dash3', 500);
    assert_equals([['RuntimeException', 'RuntimeException', 'RuntimeException'], 'refund_pending', ['amount' => 1000, 'idempotency_key' => 'r29f-3_execute']],
        [$transient, $r29fState($o3)[1], PaymentService::unexecutedRefundRequest(Orders::byId($o3))],
        'R29 refunds: a 500/429/401 fails the job for a retry, and after a dashboard refund the admin still gets "retry" (was: hidden)');
    assert_true(PaymentService::retryRefundRequest($o3, 'r29f'), 'R29 refunds: the retry is queued');
    $r29fJob($o3, 1000, 'r29f-3_execute');
    assert_equals([1500, 'partially_refunded', 0], $r29fState($o3), 'R29 refunds: the retried refund settles the request');

    // 4. A declined item refund is failed (credit note released), not retried.
    $o4 = $r29fOrder('R29F-4');
    $item4 = $r29fItemRefund($o4, 300, 'r29f-4-item');
    R29fFakeStripe::$answer = 400;
    $declined4 = $r29fThrows(static fn() => $r29fJob($o4, 300, 'r29f-4-item', $item4));
    R29fFakeStripe::$answer = null;
    assert_equals(['none', 'failed', 0], [$declined4, (string)(Rma::refundById($item4)['status'] ?? ''), PaymentService::refundedAmount($o4)],
        'R29 refunds: a declined item refund is marked failed at once, nothing booked');

    // 5. Capability review run 408: an item refund of exactly the amount in flight is not the request's refund, with or
    // without a provider refund id (was: settlesRefundRequest() matched it by amount and released the request).
    foreach ([false => 'R29F-5', true => 'R29F-6'] as $r29fNoId => $r29fNumber) {
        $o5 = $r29fOrder($r29fNumber);
        PaymentService::requestRefundAndQueue($o5, 1000, "r29f-{$o5}", ['reason' => 'R29F']);
        R29fFakeStripe::$noId = (bool)$r29fNoId;
        $r29fJob($o5, 1000, "r29f-{$o5}-item", $r29fItemRefund($o5, 1000, "r29f-{$o5}-item"));
        R29fFakeStripe::$noId = false;
        $label = $r29fNoId ? 'without a refund id' : 'with a refund id';
        assert_equals([1000, 'refund_pending', 1000], $r29fState($o5),
            "R29 refunds: an item refund {$label} equal to the 10.00 in flight keeps the request in flight (was: partially_refunded, in flight 0)");
        $r29fForCredit = (new ReflectionMethod(InvoiceService::class, 'refundableForCredit'))->invoke(null, Orders::byId($o5));
        assert_equals(['LogicException', (int)Orders::byId($o5)['total'] - 2000],
            [$r29fThrows(static fn() => PaymentService::requestRefund($o5, 500, "r29f-{$o5}-second")), $r29fForCredit],
            "R29 refunds: after an equal item refund {$label} the 10.00 in flight is neither requested again nor promised to a credit note");
        $audit5 = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE entity = 'order_refund' AND entity_id = ? AND note LIKE '%is in flight'");
        $audit5->execute([$o5]);
        assert_true((int)$audit5->fetchColumn() === 1, "R29 refunds: the item refund {$label} is audited as booked while the request is in flight");
        $r29fJob($o5, 1000, "r29f-{$o5}_execute");
        assert_equals([2000, 'partially_refunded', 0], $r29fState($o5), "R29 refunds: the request's own job then settles it ({$label})");
    }
} catch (Throwable $e) {
    assert_true(false, 'R29 refunds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    R29fFakeStripe::$answer = null;
    $r29fAdapters->setValue(null, $r29fSavedAdapters);
    foreach ($r29fOrders as $r29fCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r29fTable => $r29fWhere) {
            try { $pdo->prepare("DELETE FROM {$r29fTable} WHERE {$r29fWhere}")->execute([$r29fCleanup]); } catch (Throwable $e) {}
        }
    }
}
