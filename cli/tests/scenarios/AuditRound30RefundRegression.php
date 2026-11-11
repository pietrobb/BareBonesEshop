<?php

// Review round 30 (2026-11-11, main @ e41e8e0b):
// - finding 2: after Stripe declined an admin refund (released, R29) a later credit note made the payment refund_pending
//   again, and the released request counted as in flight: the payment stayed refund_pending for good, the refund form was
//   hidden and "retry" re-queued the declined refund (once Stripe forgot its idempotency key, 24 h later, it could pay it).
//   A regression of R29's fix: latestRefundRequestRow() did not know a released request.
// - low: a refund declined on an order a dashboard refund had refunded in full went to partially_refunded, not refunded.
echo "\n--- R30: a declined admin refund stays released; a declined refund on a fully refunded order is refunded ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
JobQueue::setDir(test_fixture_path('r30r-jobs'));
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R30rFakeStripe', false)) {
    class R30rFakeStripe extends StripePayment {
        /** null: the refund succeeds; an int: Stripe answers with that HTTP code. */
        public static ?int $answer = null;
        public function __construct() { parent::__construct([]); }
        protected function apiCall(string $method, string $endpoint, array $params = [], array $extraHeaders = []): array {
            if (self::$answer !== null) throw new \RuntimeException('Stripe API: declined', self::$answer);
            return ['id' => 're_r30r_' . substr(sha1((string)($params['metadata'][self::REFUND_KEY_METADATA] ?? uniqid())), 0, 10), 'status' => 'succeeded'];
        }
    }
}
$r30rAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r30rSavedAdapters = $r30rAdapters->getValue();
$r30rAdapters->setValue(null, ['payment' => new R30rFakeStripe()] + $r30rSavedAdapters);
$r30rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r30rOrders = [];
$r30rOrder = static function (string $number) use ($pdo, $r30rProduct, &$r30rOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['product_id' => 1, 'variant_id' => null, 'sku' => "{$number}-PB", 'price' => 4200, 'qty' => 1, 'total' => 4200, 'available' => true,
        'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'books', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $items = Cart::applyVat(['items' => [$line], 'subtotal' => 4200, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R30R', 'r30r@example.test', '', '{\"country\":\"SK\"}', 4200, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$number, (int)$snapshot['totals']['gross'], 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card']),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $id = $r30rOrders[] = (int)$pdo->lastInsertId();
    // SQLite reuses the id of an order an earlier scenario deleted; rows it left under that id are not this order's.
    foreach (['payment_transactions', 'order_refunds'] as $r30rStale) $pdo->prepare("DELETE FROM {$r30rStale} WHERE order_id = ?")->execute([$id]);
    $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 4200, 4200)')
        ->execute([$id, $r30rProduct, "{$number}-PB", "R30R {$number}"]);
    InvoiceService::issue($id);
    return $id;
};
$r30rState = static fn(int $orderId): array => [PaymentService::refundedAmount($orderId), (string)Orders::byId($orderId)['payment_status'],
    PaymentService::requestedRefundInFlight(Orders::byId($orderId))];
$r30rJob = static fn(int $orderId, int $amount, string $key, int $itemRefund = 0) => WorkerJobs::handlePaymentRefund(['order_id' => $orderId,
    'payment_id' => (string)Orders::byId($orderId)['payment_id'], 'amount' => $amount, 'idempotency_key' => $key] + ($itemRefund > 0 ? ['refund_id' => $itemRefund] : []), []);
// What InvoiceService::credit() does for a paid order: a pending item refund, and the payment refund_pending.
$r30rCredit = static function (int $orderId, int $amount, string $key) use ($pdo): int {
    $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, 0, 0, ?, 'eur', 'pending', ?)")
        ->execute([$orderId, $amount, $key]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE orders SET payment_status = 'refund_pending' WHERE id = ? AND payment_status IN ('paid', 'partially_refunded', 'disputed')")->execute([$orderId]);
    return $id;
};

try {
    // 1. Finding 2: Stripe declines the 10.00 admin refund (released), then a 3.00 credit note is issued and refunded.
    $o1 = $r30rOrder('R30R-1');
    PaymentService::requestRefundAndQueue($o1, 1000, 'r30r-1', ['reason' => 'R30R']);
    R30rFakeStripe::$answer = 400;
    $r30rJob($o1, 1000, 'r30r-1_execute');
    R30rFakeStripe::$answer = null;
    $released = $r30rState($o1);
    $item1 = $r30rCredit($o1, 300, 'r30r-1-item');
    assert_equals([[0, 'paid', 0], 0, null], [$released, $r30rState($o1)[2], PaymentService::unexecutedRefundRequest(Orders::byId($o1))],
        'R30 refunds: the declined request is released and stays so while a credit note is in flight — nothing in flight, no "retry" of the declined refund (was: 10.00 in flight, retry offered)');
    $r30rJob($o1, 300, 'r30r-1-item', $item1);
    assert_equals([300, 'partially_refunded', 0], $r30rState($o1),
        'R30 refunds: the credit note\'s refund then moves the payment to partially_refunded (was: refund_pending for good)');
    assert_true(PaymentService::requestRefund($o1, 1000, 'r30r-1-again'), 'R30 refunds: the admin can request the refund again (the form is back)');

    // 2. Low: a dashboard refund of the whole order while the admin's 10.00 is in flight, then Stripe declines the 10.00.
    $o2 = $r30rOrder('R30R-2');
    $total2 = (int)Orders::byId($o2)['total'];
    PaymentService::requestRefundAndQueue($o2, 1000, 'r30r-2', ['reason' => 'R30R']);
    PaymentService::bookProviderRefund($o2, 'stripe', 're_r30r_dash2', $total2, 'refund.succeeded', 'stripe_refund_re_r30r_dash2',
        ['refund_id' => 're_r30r_dash2', 'amount' => $total2, 'currency' => 'eur']);
    R30rFakeStripe::$answer = 400;
    $r30rJob($o2, 1000, 'r30r-2_execute');
    R30rFakeStripe::$answer = null;
    assert_equals([$total2, 'refunded', 0], $r30rState($o2),
        'R30 refunds: a refund declined on an order refunded in full is released to refunded (was: partially_refunded)');
} catch (Throwable $e) {
    assert_true(false, 'R30 refunds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    R30rFakeStripe::$answer = null;
    $r30rAdapters->setValue(null, $r30rSavedAdapters);
    foreach ($r30rOrders as $r30rCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r30rTable => $r30rWhere) {
            try { $pdo->prepare("DELETE FROM {$r30rTable} WHERE {$r30rWhere}")->execute([$r30rCleanup]); } catch (Throwable $e) {}
        }
    }
}
