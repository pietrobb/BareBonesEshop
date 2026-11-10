<?php

// Review round 28 (2026-11-10, main @ a90ea0ea), medium 2 (goal #404): a refund webhook while an admin refund was in flight
// (PaymentService.php:918). The dashboard refund moved the payment from refund_pending to partially_refunded, so the admin
// request counted as settled: its money was free again (a second admin refund or a credit note could promise it) and the
// job's result, finding no refund_pending payment, left a fully refunded payment partially_refunded with the order open.
// R28: the event's money is booked, the payment stays refund_pending until the request settles, and settles it right.
echo "\n--- R28: a refund event during an admin refund — the payment stays refund_pending, the request's money stays promised ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
JobQueue::setDir(test_fixture_path('r28f-jobs'));
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R28fFakeStripe', false)) {
    class R28fFakeStripe extends StripePayment {
        public static ?Closure $during = null;
        public function __construct() { parent::__construct([]); }
        public static function refundIdOf(string $key): string { return 're_r28f_' . substr(sha1($key), 0, 10); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            $during = self::$during;
            self::$during = null;
            if ($during !== null) $during();
            return ['refund_id' => self::refundIdOf($idempotencyKey), 'status' => 'succeeded'];
        }
    }
}
$r28fAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r28fSavedAdapters = $r28fAdapters->getValue();
$r28fAdapters->setValue(null, ['payment' => new R28fFakeStripe()] + $r28fSavedAdapters);
$r28fProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r28fOrders = [];
$r28fOrder = static function (string $number) use ($pdo, $r28fProduct, &$r28fOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['product_id' => 1, 'variant_id' => null, 'sku' => "{$number}-PB", 'price' => 4200, 'qty' => 1, 'total' => 4200, 'available' => true,
        'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'books', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $items = Cart::applyVat(['items' => [$line], 'subtotal' => 4200, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R28F', 'r28f@example.test', '', '{\"country\":\"SK\"}', 4200, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$number, (int)$snapshot['totals']['gross'], 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card']),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $id = $r28fOrders[] = (int)$pdo->lastInsertId();
    // SQLite reuses the id of an order an earlier scenario deleted; rows it left under that id are not this order's.
    foreach (['payment_transactions', 'order_refunds'] as $r28fStale) $pdo->prepare("DELETE FROM {$r28fStale} WHERE order_id = ?")->execute([$id]);
    $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 4200, 4200)')
        ->execute([$id, $r28fProduct, "{$number}-PB", "R28F {$number}"]);
    InvoiceService::issue($id);
    return $id;
};
$r28fState = static fn(int $orderId): array => [PaymentService::refundedAmount($orderId), (string)Orders::byId($orderId)['payment_status']];
$r28fIncidents = static function (int $orderId) use ($pdo): int {
    $stmt = $pdo->prepare("SELECT context_json FROM operational_incidents WHERE code = 'payment.refund_overcommitted'");
    $stmt->execute();
    return count(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static fn($json): bool => (int)(json_decode((string)$json, true)['order_id'] ?? 0) === $orderId));
};
// The admin refund (Admin/Orders.php): requested on the ledger, then its payment_refund job runs; $during fires inside the
// provider call, where a webhook of another refund lands. Returns what the order looked like right after that webhook.
$r28fAdminRefund = static function (int $orderId, int $amount, string $key, ?Closure $webhook) use ($r28fState): array {
    PaymentService::requestRefundAndQueue($orderId, $amount, $key, ['reason' => 'R28F']);
    $seen = [];
    R28fFakeStripe::$during = $webhook === null ? null : static function () use ($orderId, $webhook, $r28fState, &$seen): void {
        $webhook();
        $order = Orders::byId($orderId);
        // What a credit note may still promise (InvoiceService::refundableForCredit()): the claimable balance less the refund in flight.
        $inFlight = PaymentService::requestedRefundInFlight($order);
        $seen = [$r28fState($orderId), $inFlight, PaymentService::claimableRefund($orderId) - $inFlight];
    };
    WorkerJobs::handlePaymentRefund(['order_id' => $orderId, 'payment_id' => (string)Orders::byId($orderId)['payment_id'], 'amount' => $amount,
        'idempotency_key' => $key . '_execute'], []);
    return $seen;
};
// A refund made in the Stripe dashboard: its refund.updated carries no refund key of the shop (ApiHandlers onRefundUpdated()).
$r28fDashboard = static fn(int $orderId, string $id, int $amount): Closure => static function () use ($orderId, $id, $amount): void {
    PaymentService::bookProviderRefund($orderId, 'stripe', $id, $amount, 'refund.succeeded', 'stripe_refund_' . $id, ['refund_id' => $id, 'amount' => $amount, 'currency' => 'eur']);
};

try {
    // 1. Admin refunds 10.00 of 42.00; a 5.00 dashboard refund lands while the job calls Stripe.
    $o1 = $r28fOrder('R28F-1');
    $total1 = (int)Orders::byId($o1)['total'];
    $seen1 = $r28fAdminRefund($o1, 1000, 'r28f-1', $r28fDashboard($o1, 're_r28f_dash1', 500));
    assert_equals([[500, 'refund_pending'], 1000, $total1 - 1500], $seen1,
        'R28 refunds: a dashboard refund during an admin refund is booked, the payment stays refund_pending and the 10.00 in flight stays promised (was: partially_refunded, in flight 0, claimable 37.00)');
    assert_equals([1500, 'partially_refunded'], $r28fState($o1), 'R28 refunds: the admin refund\'s result then moves the payment: 15.00 refunded, partially_refunded');
    $second = null;
    try {
        PaymentService::requestRefund($o1, $total1 - 1500 + 1, 'r28f-1-more');
    } catch (LogicException $e) {
        $second = 'refused';
    }
    assert_equals('refused', $second, 'R28 refunds: no further refund can claim more than the 27.00 left');

    // 2. While the admin refunds 10.00, the dashboard refunds the other 32.00: the order is fully refunded and closes.
    $o2 = $r28fOrder('R28F-2');
    $total2 = (int)Orders::byId($o2)['total'];
    $seen2 = $r28fAdminRefund($o2, 1000, 'r28f-2', $r28fDashboard($o2, 're_r28f_dash2', $total2 - 1000));
    assert_equals([$total2 - 1000, 'refund_pending'], $seen2[0], 'R28 refunds: the rest refunded in the dashboard leaves the admin refund in flight');
    assert_equals([[$total2, 'refunded'], 0], [$r28fState($o2), $r28fIncidents($o2)],
        'R28 refunds: the admin refund\'s result completes it: refunded (was: partially_refunded with all money back)');

    // 3. A dashboard refund that leaves less than the refund in flight raises a critical incident (Stripe refuses or pays twice).
    $o3 = $r28fOrder('R28F-3');
    $total3 = (int)Orders::byId($o3)['total'];
    PaymentService::requestRefundAndQueue($o3, 1000, 'r28f-3', ['reason' => 'R28F']);
    $r28fDashboard($o3, 're_r28f_dash3', $total3 - 500)();
    assert_equals([[$total3 - 500, 'refund_pending'], 1, 1000], [$r28fState($o3), $r28fIncidents($o3), PaymentService::requestedRefundInFlight(Orders::byId($o3))],
        'R28 refunds: a dashboard refund overcommitting the refund in flight is booked and raises payment.refund_overcommitted');

    // 4. The admin refund's own webhook (its refund key in the metadata) before the job's result still settles it.
    $o4 = $r28fOrder('R28F-4');
    $own4 = R28fFakeStripe::refundIdOf('r28f-4_execute');
    $r28fAdminRefund($o4, 1000, 'r28f-4', static function () use ($o4, $own4): void {
        PaymentService::bookProviderRefund($o4, 'stripe', $own4, 1000, 'refund.succeeded', 'stripe_refund_' . $own4,
            ['refund_id' => $own4, 'amount' => 1000, 'currency' => 'eur', 'refund_key' => 'r28f-4_execute']);
    });
    assert_equals([[1000, 'partially_refunded'], 0], [$r28fState($o4), PaymentService::requestedRefundInFlight(Orders::byId($o4))],
        'R28 refunds: the admin refund\'s own event settles it (booked once, partially_refunded, nothing in flight)');
} catch (Throwable $e) {
    assert_true(false, 'R28 refunds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    R28fFakeStripe::$during = null;
    $r28fAdapters->setValue(null, $r28fSavedAdapters);
    foreach ($r28fOrders as $r28fCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r28fTable => $r28fWhere) {
            try { $pdo->prepare("DELETE FROM {$r28fTable} WHERE {$r28fWhere}")->execute([$r28fCleanup]); } catch (Throwable $e) {}
        }
    }
}
