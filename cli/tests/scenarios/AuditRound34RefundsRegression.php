<?php

// Review round 34 (2026-11-12), medium 4 + low (goal #439): Stripe executed an item refund whose answer was lost, its job
// ran out of retries, the admin released the credit note, then charge.refunded came: 21.00 booked as returned, the credit
// note reversed to zero, nobody told. R34: the release asks Stripe first (an executed refund is booked to the credit note,
// a pending one waits for its webhook, an unreachable provider refuses); a refund executed after a release gets a credit
// note and an incident. Low: a job of a released refund queued again by hand pays nothing out; an item refund paid back by
// hand (no payment id) mails the customer.
echo "\n--- R34: released item refund vs. Stripe; late charge.refunded; re-queued job; manual refund mail ---\n";

if (!function_exists('stripeRefundKey')) require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r34rJobsDir = test_fixture_path('r34r-jobs');
JobQueue::setDir($r34rJobsDir);
$r34rDropQueue = static function () use ($r34rJobsDir): void {
    foreach (['pending', 'processing'] as $sub) foreach (glob($r34rJobsDir . '/' . $sub . '/*.json') ?: [] as $file) @unlink($file);
};
$r34rQueued = static function (string $type) use ($r34rJobsDir): array {
    $jobs = [];
    foreach (glob($r34rJobsDir . '/pending/*.json') ?: [] as $file) {
        $job = json_decode((string)file_get_contents($file), true);
        if (is_array($job) && ($job['type'] ?? '') === $type) $jobs[] = $job['payload'] ?? [];
    }
    return $jobs;
};
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R34rFakeStripe', false)) {
    class R34rFakeStripe extends StripePayment {
        /** @var array<string,mixed>|null|false what refundByKey() answers; false: Stripe unreachable */
        public static array|null|false $lookup = null;
        public static int $refundCalls = 0;
        public function __construct() { parent::__construct([]); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            self::$refundCalls++;
            return ['refund_id' => 're_r34r_' . substr(sha1($idempotencyKey), 0, 10), 'status' => 'succeeded'];
        }
        public function refundByKey(string $paymentId, string $idempotencyKey): ?array {
            if (self::$lookup === false) throw new RuntimeException('Stripe API unreachable');
            return self::$lookup;
        }
    }
}
$r34rAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r34rSavedAdapters = $r34rAdapters->getValue();
$r34rAdapters->setValue(null, ['payment' => new R34rFakeStripe()] + $r34rSavedAdapters);
$r34rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r34rOrders = [];
$r34rOrder = static function (string $number, string $paymentId) use ($pdo, $r34rProduct, &$r34rOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['product_id' => 1, 'variant_id' => null, 'sku' => "{$number}-PB", 'price' => 2100, 'qty' => 1, 'total' => 2100, 'available' => true,
        'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'books', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $skus = ["{$number}-PB", "{$number}-PB2"];
    $items = Cart::applyVat(['items' => array_map(static fn(string $sku): array => ['sku' => $sku] + $line, $skus), 'subtotal' => 4200, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R34R', 'r34r@example.test', '', '{\"country\":\"SK\"}', 4200, 0, 0, 0, ?, 'eur', 'delivered', 'paid', ?, ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$number, (int)$snapshot['totals']['gross'], $paymentId === '' ? 'bank_transfer' : 'stripe', $paymentId,
            json_encode($paymentId === '' ? ['provider' => 'bank_transfer', 'service_type' => 'bank_transfer'] : ['provider' => 'stripe', 'service_type' => 'online_card']),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $id = $r34rOrders[] = (int)$pdo->lastInsertId();
    foreach (['payment_transactions', 'order_refunds'] as $r34rStale) $pdo->prepare("DELETE FROM {$r34rStale} WHERE order_id = ?")->execute([$id]);
    foreach ($skus as $sku) {
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 2100, 2100)')
            ->execute([$id, $r34rProduct, $sku, "R34R {$sku}"]);
    }
    InvoiceService::issue($id);
    return $id;
};
// An item refund whose job died (its re-queues after network errors ran out): pending, offered for release.
$r34rItemRefund = static function (int $orderId, string $key) use ($pdo, $r34rDropQueue): array {
    [, $rma] = Rma::create($orderId, null, "R34R return {$key}", [(int)Orders::items($orderId)[0]['id'] => 1]);
    Rma::setStatus((int)$rma, 'approved');
    $rmaItem = (int)Rma::items((int)$rma)[0]['id'];
    Rma::receive((int)$rma, [$rmaItem => ['quantity' => 1, 'disposition' => 'repair']], "r34r-receipt-{$key}");
    $invoiceId = (int)$pdo->query("SELECT id FROM invoices WHERE order_id = {$orderId} AND type = 'invoice'")->fetchColumn();
    $credit = InvoiceService::credit($invoiceId, (int)$rma, [$rmaItem => 1], $key);
    $r34rDropQueue(); // its payment_refund job died
    return ['id' => (int)$credit['refund_id'], 'amount' => -(int)$credit['total_gross'], 'key' => $key, 'rma' => (int)$rma];
};
$r34rCredits = static fn(int $orderId): array => array_map('intval',
    $pdo->query("SELECT total_gross FROM invoices WHERE order_id = {$orderId} AND type = 'credit_note' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$r34rCreditJobs = static function (int $orderId) use ($pdo): void {
    foreach ($pdo->query("SELECT idempotency_key FROM payment_transactions WHERE order_id = {$orderId}
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded') AND amount > 0")->fetchAll(PDO::FETCH_COLUMN) as $key) {
        InvoiceService::creditBookedRefund($orderId, (string)$key);
    }
};
$r34rItem = static fn(int $id): string => (string)$pdo->query("SELECT status FROM order_refunds WHERE id = {$id}")->fetchColumn();
$r34rIncidents = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'payment.released_refund_executed'")->fetchColumn();

try {
    $r34rDropQueue();
    // 1. Medium 4: nothing at Stripe → released; then Stripe's late charge.refunded books the money, a credit note follows,
    //    the merchant is told (incident).
    $o1 = $r34rOrder('R34R-1', 'pi_r34r_1');
    $i1 = $r34rItemRefund($o1, 'r34r-1-item');
    $stalled = PaymentService::stalledItemRefund(Orders::byId($o1));
    R34rFakeStripe::$lookup = ['status' => 'none'];
    $released = PaymentService::releaseStalledItemRefund($o1, $i1['id'], 'r34r-admin', 'R34R nothing at Stripe');
    assert_equals([['amount' => $i1['amount'], 'refund_id' => $i1['id']], 'released', 'failed', [-$i1['amount'], $i1['amount']]],
        [$stalled, $released, $r34rItem($i1['id']), $r34rCredits($o1)],
        'R34 refunds: a stalled item refund Stripe has no refund of is released (credit note reversed)');
    $incidentsBefore = $r34rIncidents();
    PaymentService::bookProviderRefund($o1, 'stripe', 're_r34r_late_1', $i1['amount'], 'charge.refunded', 'stripe_refund_re_r34r_late_1', ['refund_key' => $i1['key']]);
    $r34rCreditJobs($o1);
    assert_equals([$i1['amount'], [-$i1['amount'], $i1['amount'], -$i1['amount']], 1],
        [PaymentService::refundedAmount($o1), $r34rCredits($o1), $r34rIncidents() - $incidentsBefore],
        'R34 refunds: Stripe\'s late charge.refunded of a released refund books the money and a credit note for it, with an incident (was: 21.00 returned, credit notes 0, silent)');

    // 2. Stripe executed it after all (the answer was lost): the release books it to the credit note instead.
    $o2 = $r34rOrder('R34R-2', 'pi_r34r_2');
    $i2 = $r34rItemRefund($o2, 'r34r-2-item');
    R34rFakeStripe::$lookup = ['refund_id' => 're_r34r_2', 'status' => 'succeeded', 'amount' => $i2['amount']];
    $outcome2 = PaymentService::releaseStalledItemRefund($o2, $i2['id'], 'r34r-admin', 'R34R check');
    $r34rCreditJobs($o2);
    assert_equals(['refunded', 'succeeded', $i2['amount'], [-$i2['amount']], 'partially_refunded'],
        [$outcome2, $r34rItem($i2['id']), PaymentService::refundedAmount($o2), $r34rCredits($o2), (string)Orders::byId($o2)['payment_status']],
        'R34 refunds: a release of a refund Stripe executed books it to its credit note, nothing is released or credited twice');

    // 3. Stripe still processes it: it waits for its webhook (no longer offered for release); 4. Stripe unreachable: refused.
    $o3 = $r34rOrder('R34R-3', 'pi_r34r_3');
    $i3 = $r34rItemRefund($o3, 'r34r-3-item');
    R34rFakeStripe::$lookup = ['refund_id' => 're_r34r_3', 'status' => 'pending', 'amount' => $i3['amount']];
    $outcome3 = PaymentService::releaseStalledItemRefund($o3, $i3['id'], 'r34r-admin', 'R34R check');
    $o4 = $r34rOrder('R34R-4', 'pi_r34r_4');
    $i4 = $r34rItemRefund($o4, 'r34r-4-item');
    R34rFakeStripe::$lookup = false;
    $outcome4 = 'no exception';
    try { PaymentService::releaseStalledItemRefund($o4, $i4['id'], 'r34r-admin', 'R34R check'); } catch (RuntimeException $e) { $outcome4 = $e->getMessage(); }
    assert_equals(['pending', 'pending', null, 'Stripe API unreachable', 'pending', [-$i4['amount']]],
        [$outcome3, $r34rItem($i3['id']), PaymentService::stalledItemRefund(Orders::byId($o3)), $outcome4, $r34rItem($i4['id']), $r34rCredits($o4)],
        'R34 refunds: a refund Stripe still processes waits for its webhook; when Stripe cannot be asked nothing is released');

    // Low: the job of a released refund queued again by hand pays nothing out.
    R34rFakeStripe::$refundCalls = 0;
    $before = PaymentService::refundedAmount($o1);
    WorkerJobs::handlePaymentRefund(['order_id' => $o1, 'payment_id' => 'pi_r34r_1', 'amount' => $i1['amount'], 'refund_id' => $i1['id'], 'idempotency_key' => $i1['key']], []);
    assert_equals([0, $before], [R34rFakeStripe::$refundCalls, PaymentService::refundedAmount($o1)],
        'R34 refunds: a job of a released item refund queued again by hand sends no refund (was: paid it out)');

    // Low: a second submit of a release that went through reports it, not an error (and releases nothing more).
    $o6 = $r34rOrder('R34R-6', 'pi_r34r_6');
    $i6 = $r34rItemRefund($o6, 'r34r-6-item');
    R34rFakeStripe::$lookup = ['status' => 'none'];
    $first6 = PaymentService::releaseStalledItemRefund($o6, $i6['id'], 'r34r-admin', 'R34R first click');
    $second6 = PaymentService::releaseStalledItemRefund($o6, $i6['id'], 'r34r-admin', 'R34R second click');
    $r34rHandler = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php');
    assert_equals(['released', 'already_released', 'failed', [-$i6['amount'], $i6['amount']], true],
        [$first6, $second6, $r34rItem($i6['id']), $r34rCredits($o6), str_contains($r34rHandler, "'already_released' => 'admin.order.msg.refund_released'")],
        'R34 refunds: a double click on "release" after it went through says released and reverses nothing twice (was: an error)');

    // Low: an item refund paid back by hand (order without a payment id) mails the customer, once.
    $r34rDropQueue();
    $o5 = $r34rOrder('R34R-5', '');
    $i5 = $r34rItemRefund($o5, 'r34r-5-item');
    $first = Rma::completeRefund($i5['id'], 'manual-admin');
    if ($first) PaymentService::mailManualItemRefund($o5, $i5['amount']);
    $second = Rma::completeRefund($i5['id'], 'manual-admin');
    $mails = array_values(array_filter($r34rQueued('email_send'), static fn(array $p): bool => ($p['template'] ?? '') === 'refund_issued' && (int)($p['order_id'] ?? 0) === $o5));
    assert_equals([true, false, 1, $i5['amount'], 'r34r@example.test'],
        [$first, $second, count($mails), (int)($mails[0]['refund_amount'] ?? 0), (string)($mails[0]['to'] ?? '')],
        'R34 refunds: an item refund paid back by hand mails the customer once (was: no mail)');
    assert_true(str_contains($r34rHandler, "Rma::completeRefund((int)\$credit['refund_id'], 'manual-admin')) {")
        && str_contains($r34rHandler, 'PaymentService::mailManualItemRefund('),
        'R34 refunds: the admin "refunded" of a return without a payment id sends that mail');
} catch (Throwable $e) {
    assert_true(false, 'R34 refunds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    R34rFakeStripe::$lookup = null;
    $r34rAdapters->setValue(null, $r34rSavedAdapters);
    $r34rDropQueue();
    foreach ($r34rOrders as $r34rCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'return_receipts' => 'rma_item_id IN (SELECT id FROM rma_items WHERE rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?))',
            'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r34rTable => $r34rWhere) {
            try { $pdo->prepare("DELETE FROM {$r34rTable} WHERE {$r34rWhere}")->execute([$r34rCleanup]); } catch (Throwable $e) {}
        }
    }
}
