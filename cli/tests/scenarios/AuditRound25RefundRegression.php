<?php

// Review round 25 (2026-11-09, "kolo 9"), findings 2 and 3 (goal #386): a Stripe charge.refunded without its refund list
// (PaymentService::bookCumulativeRefund()) matched the shop's refunds in flight only by an exactly equal amount, and a
// refund.updated (or a later charge.refunded) naming the shop's refund between that event and the refund job's result
// booked the money again.
echo "\n--- R25: cumulative Stripe refunds against the sum of refunds in flight, refund ids identified, not booked twice ---\n";

if (!function_exists('stripeRefundKey')) require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
Bootstrap::initStoreContext('/', '', 'default');
$r25rFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R25 refunds {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
VatService::reset();
JobQueue::setDir(test_fixture_path('r25r-jobs'));
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R25rFakeStripe', false)) {
    class R25rFakeStripe extends StripePayment {
        /** What Stripe reports (webhooks) while this refund call is in flight, before its result is booked. */
        public static ?Closure $during = null;
        public function __construct() { parent::__construct([]); }
        public static function refundIdOf(string $key): string { return 're_r25r_' . substr(sha1($key), 0, 10); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            $during = self::$during;
            self::$during = null;
            if ($during !== null) $during();
            return ['refund_id' => self::refundIdOf($idempotencyKey), 'status' => 'succeeded'];
        }
    }
}
$r25rAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r25rSavedAdapters = $r25rAdapters->getValue();
$r25rAdapters->setValue(null, ['payment' => new R25rFakeStripe()] + $r25rSavedAdapters);
$r25rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r25rOrders = [];
$r25rOrder = static function (string $number) use ($pdo, $r25rProduct, &$r25rOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['product_id' => 1, 'variant_id' => null, 'sku' => "{$number}-PB", 'price' => 2100, 'qty' => 1, 'total' => 2100, 'available' => true,
        'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'books', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $items = Cart::applyVat(['items' => [$line, ['sku' => "{$number}-PB2"] + $line], 'subtotal' => 4200, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R25R', 'r25r@example.test', '', '{\"country\":\"SK\"}', 4200, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$number, (int)$snapshot['totals']['gross'], 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card']),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $id = $r25rOrders[] = (int)$pdo->lastInsertId();
    foreach (["{$number}-PB", "{$number}-PB2"] as $sku) {
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 2100, 2100)')
            ->execute([$id, $r25rProduct, $sku, "R25R {$sku}"]);
    }
    InvoiceService::issue($id);
    return $id;
};
// An item returned and credited (credit note + pending item refund under $key); its money, positive.
$r25rItemRefund = static function (int $orderId, int $line, string $key) use ($pdo): array {
    [, $rma] = Rma::create($orderId, null, "R25R return {$key}", [(int)Orders::items($orderId)[$line]['id'] => 1]);
    Rma::setStatus((int)$rma, 'approved');
    $rmaItem = (int)Rma::items((int)$rma)[0]['id'];
    Rma::receive((int)$rma, [$rmaItem => ['quantity' => 1, 'disposition' => 'repair']], "r25r-receipt-{$key}");
    $invoiceId = (int)$pdo->query("SELECT id FROM invoices WHERE order_id = {$orderId} AND type = 'invoice'")->fetchColumn();
    $credit = InvoiceService::credit($invoiceId, (int)$rma, [$rmaItem => 1], $key);
    return ['id' => (int)$credit['refund_id'], 'amount' => -(int)$credit['total_gross'], 'key' => $key];
};
$r25rJob = static function (int $orderId, array $item): void {
    WorkerJobs::handlePaymentRefund(['order_id' => $orderId, 'payment_id' => (string)Orders::byId($orderId)['payment_id'], 'amount' => $item['amount'],
        'refund_id' => $item['id'], 'idempotency_key' => $item['key']], []);
};
$r25rCredits = static fn(int $orderId): array => array_map('intval',
    $pdo->query("SELECT total_gross FROM invoices WHERE order_id = {$orderId} AND type = 'credit_note' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
// What the refund_credit_note job does for every refund booked.
$r25rCreditJobs = static function (int $orderId) use ($pdo): void {
    foreach ($pdo->query("SELECT idempotency_key FROM payment_transactions WHERE order_id = {$orderId}
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded') AND amount > 0")->fetchAll(PDO::FETCH_COLUMN) as $key) {
        InvoiceService::creditBookedRefund($orderId, (string)$key);
    }
};
$r25rItem = static fn(int $id): array => $pdo->query("SELECT status, payment_reference FROM order_refunds WHERE id = {$id}")->fetch(PDO::FETCH_NUM);

try {
    // 1. Two item refunds in flight, reported together by one charge.refunded without its refund list (21.00 + 21.00 = 42.00).
    $o1 = $r25rOrder('R25R-1');
    $a = $r25rItemRefund($o1, 0, 'r25r-1a');
    $b = $r25rItemRefund($o1, 1, 'r25r-1b');
    R25rFakeStripe::$during = static function () use ($o1, $b, $a, $r25rJob, $r25rCreditJobs): void {
        R25rFakeStripe::$during = static function () use ($o1, $a, $b, $r25rCreditJobs): void {
            PaymentService::bookCumulativeRefund($o1, 'stripe', $a['amount'] + $b['amount'], 'charge.refunded', 'stripe_refund_ch_r25r1_' . ($a['amount'] + $b['amount']));
            $r25rCreditJobs($o1);
        };
        $r25rJob($o1, $b);
    };
    $r25rJob($o1, $a);
    $r25rCreditJobs($o1);
    assert_equals([$a['amount'] + $b['amount'], 2, 'refunded'],
        [PaymentService::refundedAmount($o1), count($r25rCredits($o1)), (string)Orders::byId($o1)['payment_status']],
        'R25 refunds: two item refunds reported as one cumulative refund are booked once each (was: 84.00 on the ledger of a 42.00 order) and keep their two credit notes');
    assert_equals([['succeeded', R25rFakeStripe::refundIdOf('r25r-1a')], ['succeeded', R25rFakeStripe::refundIdOf('r25r-1b')]],
        [$r25rItem($a['id']), $r25rItem($b['id'])],
        'R25 refunds: each item refund refers to its Stripe refund id once the job\'s result names it (was: the webhook\'s ledger key)');

    // 2. An item refund in flight and a dashboard refund of 5.00 in one event: the payment is partially refunded, the rest refundable.
    $o2 = $r25rOrder('R25R-2');
    $c = $r25rItemRefund($o2, 0, 'r25r-2a');
    R25rFakeStripe::$during = static function () use ($o2, $c, $r25rCreditJobs): void {
        PaymentService::bookCumulativeRefund($o2, 'stripe', $c['amount'] + 500, 'charge.refunded', 'stripe_refund_ch_r25r2_' . ($c['amount'] + 500));
        $r25rCreditJobs($o2);
    };
    $r25rJob($o2, $c);
    $r25rCreditJobs($o2);
    $o2Total = (int)Orders::byId($o2)['total'];
    assert_equals([$c['amount'] + 500, 'partially_refunded', $o2Total - $c['amount'] - 500, [-$c['amount'], -500]],
        [PaymentService::refundedAmount($o2), (string)Orders::byId($o2)['payment_status'], PaymentService::refundableBalance($o2), $r25rCredits($o2)],
        'R25 refunds: an item refund and a dashboard refund in one cumulative event leave the payment partially refunded with the real rest refundable (was: refunded)');

    // 3. Finding 3: refund.updated of the shop's own refund between the cumulative booking and the job's result.
    $o3 = $r25rOrder('R25R-3');
    $d = $r25rItemRefund($o3, 0, 'r25r-3a');
    $dRefund = R25rFakeStripe::refundIdOf('r25r-3a');
    $o3Booked = null;
    R25rFakeStripe::$during = static function () use ($o3, $d, $dRefund, $r25rCreditJobs, &$o3Booked): void {
        PaymentService::bookCumulativeRefund($o3, 'stripe', $d['amount'], 'charge.refunded', 'stripe_refund_ch_r25r3_' . $d['amount']);
        $r25rCreditJobs($o3);
        $o3Booked = PaymentService::bookProviderRefund($o3, 'stripe', $dRefund, $d['amount'], 'refund.succeeded', 'stripe_refund_' . $dRefund,
            stripeRefundKey(['metadata' => [StripePayment::REFUND_KEY_METADATA => 'r25r-3a']]));
        $r25rCreditJobs($o3);
    };
    $r25rJob($o3, $d);
    $r25rCreditJobs($o3);
    assert_equals([false, $d['amount'], [-$d['amount']], ['succeeded', $dRefund]],
        [$o3Booked, PaymentService::refundedAmount($o3), $r25rCredits($o3), $r25rItem($d['id'])],
        'R25 refunds: a refund.updated naming the shop\'s refund before the job\'s result only identifies it (was: 42.00 on the ledger for 21.00 returned, a second credit note)');
    assert_true(!PaymentService::bookProviderRefund($o3, 'stripe', $dRefund, $d['amount'], 'charge.refunded', 'stripe_refund_' . $dRefund . '_late')
        && PaymentService::refundedAmount($o3) === $d['amount'],
        'R25 refunds: a later charge.refunded listing the same refund books nothing either');

    // 4. A dashboard refund of the same sum as an item refund still queued (its job has not called Stripe) is not that refund.
    $o4 = $r25rOrder('R25R-4');
    $q = $r25rItemRefund($o4, 0, 'r25r-4a');
    PaymentService::bookCumulativeRefund($o4, 'stripe', $q['amount'], 'charge.refunded', 'stripe_refund_ch_r25r4_' . $q['amount']);
    $r25rCreditJobs($o4);
    $o4Queued = $r25rItem($q['id'])[0];
    $r25rJob($o4, $q);
    $r25rCreditJobs($o4);
    $o4Dashboard = PaymentService::bookProviderRefund($o4, 'stripe', 're_r25r_dashboard', $q['amount'], 'refund.succeeded', 'stripe_refund_re_r25r_dashboard');
    assert_equals(['pending', 2 * $q['amount'], false, 2],
        [$o4Queued, PaymentService::refundedAmount($o4), $o4Dashboard, count($r25rCredits($o4))],
        'R25 refunds: a dashboard refund of the same sum leaves a queued item refund pending; both refunds are booked once, the dashboard refund\'s own id later only identifies it');

    // 5. The cumulative amount never books more than the order holds.
    $o5 = $r25rOrder('R25R-5');
    PaymentService::bookCumulativeRefund($o5, 'stripe', 999999, 'charge.refunded', 'stripe_refund_ch_r25r5_999999');
    assert_equals((int)Orders::byId($o5)['total'], PaymentService::refundedAmount($o5), 'R25 refunds: a cumulative refund is capped at the order total');

    // 6. The refund key travels with the Stripe refund and is read back from the webhook.
    $r25rStripeSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/adapters/contrib/StripePayment.php');
    assert_true(str_contains($r25rStripeSrc, "\$params['metadata'] = [self::REFUND_KEY_METADATA => \$idempotencyKey]")
        && stripeRefundKey(['metadata' => [StripePayment::REFUND_KEY_METADATA => 'k1']]) === ['refund_key' => 'k1'] && stripeRefundKey([]) === [],
        'R25 refunds: Stripe refunds carry the shop\'s refund key in their metadata and the webhook reads it back');
} catch (Throwable $e) {
    $r25rFail('cumulative Stripe refund', $e);
} finally {
    R25rFakeStripe::$during = null;
    $r25rAdapters->setValue(null, $r25rSavedAdapters);
    foreach ($r25rOrders as $r25rCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'return_receipts' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r25rTable => $r25rWhere) {
            try { $pdo->prepare("DELETE FROM {$r25rTable} WHERE {$r25rWhere}")->execute([$r25rCleanup]); } catch (Throwable $e) {}
        }
    }
}
