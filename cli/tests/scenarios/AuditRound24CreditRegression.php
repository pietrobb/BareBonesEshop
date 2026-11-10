<?php

// Review round 24 (2026-11-09, "kolo 8"), finding 3 (goal #380): a Stripe charge.refunded reporting the shop's own refund in
// flight (an item refund with its credit note, an admin refund request) before the refund job's result got a credit note of
// its own, and the job's result booked the same money a second time.
// R27: a charge.refunded is booked only by its refunds' ids (one without its refund list is deferred), so the event books
// the shop's refund by the refund key Stripe carries in its metadata.
echo "\n--- R24: a Stripe refund event settles the shop's refund in flight, one credit note ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r24cFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R24 credit {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
VatService::reset();
JobQueue::setDir(test_fixture_path('r24c-jobs'));
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R24cFakeStripe', false)) {
    class R24cFakeStripe extends StripePayment {
        /** R25: what Stripe reports (webhooks) while this refund call is in flight, before its result is booked. */
        public static ?Closure $during = null;
        public function __construct() { parent::__construct([]); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            $during = self::$during;
            self::$during = null;
            if ($during !== null) $during();
            return ['refund_id' => 're_r24c_' . substr(sha1($idempotencyKey), 0, 10), 'status' => 'succeeded'];
        }
    }
}
$r24cAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r24cSavedAdapters = $r24cAdapters->getValue();
$r24cAdapters->setValue(null, ['payment' => new R24cFakeStripe()] + $r24cSavedAdapters);
$r24cProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r24cOrders = [];
$r24cOrder = static function (string $number) use ($pdo, $r24cProduct, &$r24cOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['product_id' => 1, 'variant_id' => null, 'sku' => "{$number}-PB", 'price' => 2100, 'qty' => 1, 'total' => 2100, 'available' => true,
        'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'books', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $items = Cart::applyVat(['items' => [$line, ['sku' => "{$number}-PB2"] + $line], 'subtotal' => 4200, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R24C', 'r24c@example.test', '', '{\"country\":\"SK\"}', 4200, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$number, (int)$snapshot['totals']['gross'], 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card']),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $id = $r24cOrders[] = (int)$pdo->lastInsertId();
    foreach (["{$number}-PB", "{$number}-PB2"] as $sku) {
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 2100, 2100)')
            ->execute([$id, $r24cProduct, $sku, "R24C {$sku}"]);
    }
    InvoiceService::issue($id);
    return $id;
};
$r24cCredits = static fn(int $orderId): array => array_map('intval',
    $pdo->query("SELECT total_gross FROM invoices WHERE order_id = {$orderId} AND type = 'credit_note' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
// What the refund_credit_note job does for every refund booked since $after.
$r24cCreditJobs = static function (int $orderId, int $after) use ($pdo): void {
    foreach ($pdo->query("SELECT idempotency_key FROM payment_transactions WHERE order_id = {$orderId} AND id > {$after}
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded') AND amount > 0")->fetchAll(PDO::FETCH_COLUMN) as $key) {
        InvoiceService::creditBookedRefund($orderId, (string)$key);
    }
};
$r24cLast = static fn(): int => (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM payment_transactions')->fetchColumn();

try {
    // 1. An item refund (returned paperback, credit note issued), then charge.refunded before the refund job's result.
    $r24cItemOrder = $r24cOrder('R24C-1');
    [, $rma] = Rma::create($r24cItemOrder, null, 'R24C paperback back', [(int)Orders::items($r24cItemOrder)[0]['id'] => 1]);
    Rma::setStatus((int)$rma, 'approved');
    $rmaItem = (int)Rma::items((int)$rma)[0]['id'];
    Rma::receive((int)$rma, [$rmaItem => ['quantity' => 1, 'disposition' => 'repair']], 'r24c-receipt');
    $invoiceId = (int)$pdo->query("SELECT id FROM invoices WHERE order_id = {$r24cItemOrder} AND type = 'invoice'")->fetchColumn();
    $credit = InvoiceService::credit($invoiceId, (int)$rma, [$rmaItem => 1], 'r24c-item');
    $amount = -(int)$credit['total_gross'];
    $before = $r24cLast();
    $r24cDuring = [];
    R24cFakeStripe::$during = static function () use (&$r24cDuring, $r24cItemOrder, $amount, $credit, $before, $r24cCreditJobs, $r24cCredits, $pdo): void {
        $id = 're_r24c_' . substr(sha1('r24c-item'), 0, 10);
        PaymentService::bookProviderRefund($r24cItemOrder, 'stripe', $id, $amount, 'charge.refunded', 'stripe_refund_' . $id, ['charge_id' => 'ch_r24c1', 'refund_key' => 'r24c-item']);
        $r24cCreditJobs($r24cItemOrder, $before);
        $r24cDuring = [$r24cCredits($r24cItemOrder), (string)$pdo->query('SELECT status FROM order_refunds WHERE id = ' . (int)$credit['refund_id'])->fetchColumn(),
            (string)Orders::byId($r24cItemOrder)['payment_status']];
    };
    WorkerJobs::handlePaymentRefund(['order_id' => $r24cItemOrder, 'payment_id' => 'pi_r24c-1', 'amount' => $amount,
        'refund_id' => (int)$credit['refund_id'], 'idempotency_key' => 'r24c-item'], []);
    // R27: booked by its own refund id, so the item refund is completed with it.
    assert_equals([[(int)$credit['total_gross']], 'succeeded', 'partially_refunded'], $r24cDuring,
        'R24 credit: a charge.refunded of the item refund in flight books it, and the item credit note stays the only one (was: a second credit note)');
    $r24cCreditJobs($r24cItemOrder, $before);
    $refundId = 're_r24c_' . substr(sha1('r24c-item'), 0, 10);
    assert_equals([$amount, 'refund_confirmed', 1, 'succeeded'], [PaymentService::refundedAmount($r24cItemOrder),
        (string)$pdo->query("SELECT status FROM payment_transactions WHERE order_id = {$r24cItemOrder} AND provider_ref = '{$refundId}'")->fetchColumn(),
        count($r24cCredits($r24cItemOrder)), (string)$pdo->query('SELECT status FROM order_refunds WHERE id = ' . (int)$credit['refund_id'])->fetchColumn()],
        'R24 credit: the refund job\'s result then only confirms the refund (was: booked a second time) and completes the item refund');
    assert_true(!PaymentService::bookProviderRefund($r24cItemOrder, 'stripe', $refundId, $amount, 'refund.succeeded', 'stripe_refund_' . $refundId),
        'R24 credit: a later refund.updated of the same Stripe refund books nothing');
    assert_equals($amount, PaymentService::refundedAmount($r24cItemOrder), 'R24 credit: the ledger holds the item refund once');

    // 2. An admin refund request, then charge.refunded before the refund job's result.
    $r24cAdminOrder = $r24cOrder('R24C-2');
    PaymentService::requestRefund($r24cAdminOrder, 500, 'admin_refund_request_r24c');
    $before = $r24cLast();
    R24cFakeStripe::$during = static function () use ($r24cAdminOrder, $before, $r24cCreditJobs): void {
        $id = 're_r24c_' . substr(sha1('admin_refund_request_r24c_execute'), 0, 10);
        PaymentService::bookProviderRefund($r24cAdminOrder, 'stripe', $id, 500, 'charge.refunded', 'stripe_refund_' . $id, ['charge_id' => 'ch_r24c2', 'refund_key' => 'admin_refund_request_r24c_execute']);
        $r24cCreditJobs($r24cAdminOrder, $before);
    };
    PaymentService::refund($r24cAdminOrder, 'pi_r24c-2', 500, new R24cFakeStripe(), 'admin_refund_request_r24c_execute');
    $r24cCreditJobs($r24cAdminOrder, $before);
    assert_equals([500, [-500], 'partially_refunded'], [PaymentService::refundedAmount($r24cAdminOrder), $r24cCredits($r24cAdminOrder),
        (string)Orders::byId($r24cAdminOrder)['payment_status']],
        'R24 credit: an admin refund reported by charge.refunded is booked and credited once (the job\'s result booked it again)');

    // 3. A refund made in the Stripe dashboard (nothing of the shop in flight) still gets its credit note.
    $before = $r24cLast();
    PaymentService::bookProviderRefund($r24cAdminOrder, 'stripe', 're_r24c_dashboard', 300, 'charge.refunded', 'stripe_refund_re_r24c_dashboard', ['charge_id' => 'ch_r24c2']);
    $r24cCreditJobs($r24cAdminOrder, $before);
    assert_equals([800, [-500, -300]], [PaymentService::refundedAmount($r24cAdminOrder), $r24cCredits($r24cAdminOrder)],
        'R24 credit: a dashboard refund with no refund of the shop in flight is booked and credited as before');
} catch (Throwable $e) {
    $r24cFail('Stripe refund event', $e);
} finally {
    $r24cAdapters->setValue(null, $r24cSavedAdapters);
    foreach ($r24cOrders as $r24cCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r24cTable => $r24cWhere) {
            try { $pdo->prepare("DELETE FROM {$r24cTable} WHERE {$r24cWhere}")->execute([$r24cCleanup]); } catch (Throwable $e) {}
        }
    }
}
