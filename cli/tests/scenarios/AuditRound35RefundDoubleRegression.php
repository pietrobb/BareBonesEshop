<?php

// Review round 35 (2026-11-13), medium 1 (goal #445): the admin released a stalled item refund (credit note reversed), then
// Stripe's late charge.refunded booked its money and a credit note (R34). The return still offered "Refunded": a click
// issued another credit note and sent another Stripe refund (19.90 returned, 23.89 paid back; a whole double refund on a
// multi-item invoice). R35: the money of a released item refund that the provider paid out after all covers its returned
// units: the return is refunded, nothing of it can be credited or refunded again.
echo "\n--- R35: late refund of a released item refund covers its return; no second credit note or refund ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r35dJobsDir = test_fixture_path('r35d-jobs');
JobQueue::setDir($r35dJobsDir);
$r35dDropQueue = static function () use ($r35dJobsDir): void {
    foreach (['pending', 'processing'] as $sub) foreach (glob($r35dJobsDir . '/' . $sub . '/*.json') ?: [] as $file) @unlink($file);
};
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R35dFakeStripe', false)) {
    class R35dFakeStripe extends StripePayment {
        public static int $refundCalls = 0;
        public function __construct() { parent::__construct([]); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            self::$refundCalls++;
            return ['refund_id' => 're_r35d_' . substr(sha1($idempotencyKey), 0, 10), 'status' => 'succeeded'];
        }
        public function refundByKey(string $paymentId, string $idempotencyKey): ?array { return ['status' => 'none']; }
    }
}
$r35dAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r35dSavedAdapters = $r35dAdapters->getValue();
$r35dAdapters->setValue(null, ['payment' => new R35dFakeStripe()] + $r35dSavedAdapters);
$r35dProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r35dOrders = [];
$r35dOrder = static function (string $number) use ($pdo, $r35dProduct, &$r35dOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['product_id' => 1, 'variant_id' => null, 'price' => 1990, 'qty' => 2, 'total' => 3980, 'available' => true, 'currency_mismatch' => false,
        'product_type' => 'simple', 'vat_class' => 'standard', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $skus = ["{$number}-A", "{$number}-B"];
    $items = Cart::applyVat(['items' => [['sku' => $skus[0]] + $line, ['sku' => $skus[1], 'vat_class' => 'books'] + $line], 'subtotal' => 7960, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R35D', 'r35d@example.test', '', '{\"country\":\"SK\"}', 7960, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$number, (int)$snapshot['totals']['gross'], 'pi_' . strtolower($number),
            json_encode(['provider' => 'stripe', 'service_type' => 'online_card']), json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $id = $r35dOrders[] = (int)$pdo->lastInsertId();
    foreach (['payment_transactions', 'order_refunds'] as $stale) $pdo->prepare("DELETE FROM {$stale} WHERE order_id = ?")->execute([$id]);
    foreach ($skus as $sku) {
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 2, 1990, 3980)')
            ->execute([$id, $r35dProduct, $sku, "R35D {$sku}"]);
    }
    InvoiceService::issue($id);
    return $id;
};
// A return of one unit of $count order lines (two units each) whose item refund job died: pending, offered for release.
$r35dItemRefund = static function (int $orderId, string $key, int $count) use ($pdo, $r35dDropQueue): array {
    $lines = [];
    foreach (array_slice(Orders::items($orderId), 0, $count) as $item) $lines[(int)$item['id']] = 1;
    [, $rma] = Rma::create($orderId, null, "R35D return {$key}", $lines);
    Rma::setStatus((int)$rma, 'approved');
    $decisions = [];
    foreach (Rma::items((int)$rma) as $rmaItem) $decisions[(int)$rmaItem['id']] = ['quantity' => 1, 'disposition' => 'repair'];
    Rma::receive((int)$rma, $decisions, "r35d-receipt-{$key}");
    $invoiceId = (int)$pdo->query("SELECT id FROM invoices WHERE order_id = {$orderId} AND type = 'invoice'")->fetchColumn();
    $credit = InvoiceService::credit($invoiceId, (int)$rma, array_map(static fn(): int => 1, $decisions), $key);
    $r35dDropQueue();
    return ['id' => (int)$credit['refund_id'], 'amount' => -(int)$credit['total_gross'], 'key' => $key, 'rma' => (int)$rma, 'invoice' => $invoiceId];
};
$r35dCreditJobs = static function (int $orderId) use ($pdo): void {
    foreach ($pdo->query("SELECT idempotency_key FROM payment_transactions WHERE order_id = {$orderId}
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded') AND amount > 0")->fetchAll(PDO::FETCH_COLUMN) as $key) {
        InvoiceService::creditBookedRefund($orderId, (string)$key);
    }
};
$r35dCredits = static fn(int $orderId): int => (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id = {$orderId} AND type = 'credit_note'")->fetchColumn();
// What the admin "Refunded" button of the return does (handleRmaStatus()): the creditable rest, a credit note, its refund.
$r35dClickRefunded = static function (array $refund) use ($r35dDropQueue, $r35dJobsDir): string {
    $quantities = InvoiceService::creditableQuantities($refund['rma']);
    if (!$quantities) return 'nothing_to_credit';
    try {
        InvoiceService::credit($refund['invoice'], $refund['rma'], $quantities, 'admin-credit-' . $refund['rma'] . '-' . InvoiceService::creditAttempts($refund['rma']));
    } catch (LogicException $e) {
        return 'refused';
    }
    foreach (glob($r35dJobsDir . '/pending/*.json') ?: [] as $file) {
        $job = json_decode((string)file_get_contents($file), true);
        if (is_array($job) && ($job['type'] ?? '') === 'payment_refund') WorkerJobs::handlePaymentRefund((array)($job['payload'] ?? []), []);
    }
    $r35dDropQueue();
    return 'credited';
};
$r35dLate = static function (int $orderId, array $refund, string $refundId) use ($r35dCreditJobs): void {
    PaymentService::releaseStalledItemRefund($orderId, $refund['id'], 'r35d-admin', 'R35D nothing at Stripe');
    PaymentService::bookProviderRefund($orderId, 'stripe', $refundId, $refund['amount'], 'charge.refunded', 'stripe_refund_' . $refundId, ['refund_key' => $refund['key']]);
    $r35dCreditJobs($orderId);
};

try {
    $r35dDropQueue();
    // 1. One returned line: released, then Stripe paid it out after all. The return is refunded; a click credits nothing.
    $o1 = $r35dOrder('R35D-1');
    $i1 = $r35dItemRefund($o1, 'r35d-1-item', 1);
    $r35dLate($o1, $i1, 're_r35d_late_1');
    $credits1 = $r35dCredits($o1);
    R35dFakeStripe::$refundCalls = 0;
    $click1 = $r35dClickRefunded($i1);
    assert_equals([[], 'refunded', 'nothing_to_credit', $credits1, 0, $i1['amount']],
        [InvoiceService::creditableQuantities($i1['rma']), (string)Rma::byId($i1['rma'])['status'], $click1, $r35dCredits($o1), R35dFakeStripe::$refundCalls, PaymentService::refundedAmount($o1)],
        'R35 refunds: a return whose released item refund Stripe paid out late is refunded and offers nothing to credit (was: "Refunded" sent a second refund)');

    // 2. A return of both lines of the invoice (was: the whole invoice refunded twice).
    $o2 = $r35dOrder('R35D-2');
    $i2 = $r35dItemRefund($o2, 'r35d-2-item', 2);
    $r35dLate($o2, $i2, 're_r35d_late_2');
    $credits2 = $r35dCredits($o2);
    R35dFakeStripe::$refundCalls = 0;
    $click2 = $r35dClickRefunded($i2);
    assert_equals([[], 'refunded', 'nothing_to_credit', $credits2, 0, $i2['amount']],
        [InvoiceService::creditableQuantities($i2['rma']), (string)Rma::byId($i2['rma'])['status'], $click2, $r35dCredits($o2), R35dFakeStripe::$refundCalls,
            PaymentService::refundedAmount($o2)],
        'R35 refunds: a return of several lines paid out late after its release is not refunded a second time');
    $r35dLineCredited = new ReflectionMethod(DigitalDelivery::class, 'lineCredited');
    assert_true($r35dLineCredited->invoke(null, (int)Orders::items($o2)[1]['id']),
        'R35 refunds: a returned line paid out late after its release counts as credited (its e-book download stays blocked)');

    // 3. Released and never paid out: the merchant can still refund the return anew (as before).
    $o3 = $r35dOrder('R35D-3');
    $i3 = $r35dItemRefund($o3, 'r35d-3-item', 1);
    PaymentService::releaseStalledItemRefund($o3, $i3['id'], 'r35d-admin', 'R35D nothing at Stripe');
    assert_equals(['received', 1], [(string)Rma::byId($i3['rma'])['status'], count(InvoiceService::creditableQuantities($i3['rma']))],
        'R35 refunds: a released item refund nobody paid out leaves its return refundable again');

    // 4. A refund the provider declined after its id was noted: still refundable (no money was booked under that id).
    $o4 = $r35dOrder('R35D-4');
    $i4 = $r35dItemRefund($o4, 'r35d-4-item', 1);
    Rma::noteProviderRefund($i4['id'], 're_r35d_declined_4');
    Rma::failRefund($i4['id'], 'R35D declined');
    assert_equals(['received', 1], [(string)Rma::byId($i4['rma'])['status'], count(InvoiceService::creditableQuantities($i4['rma']))],
        'R35 refunds: a declined item refund with a noted provider id leaves its return refundable again');
} catch (Throwable $e) {
    assert_true(false, 'R35 refund double (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r35dAdapters->setValue(null, $r35dSavedAdapters);
    $r35dDropQueue();
    foreach ($r35dOrders as $r35dCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'return_receipts' => 'rma_item_id IN (SELECT id FROM rma_items WHERE rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?))',
            'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r35dTable => $r35dWhere) {
            try { $pdo->prepare("DELETE FROM {$r35dTable} WHERE {$r35dWhere}")->execute([$r35dCleanup]); } catch (Throwable $e) {}
        }
    }
}
