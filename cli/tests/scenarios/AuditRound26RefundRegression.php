<?php

// Review round 26 (2026-11-09, "kolo 10"), goal #390: provisional bookings of refunds in flight (R25,
// PaymentService::bookCumulativeRefund()) and what later takes them back.
// - finding 2: a dashboard refund (5.00) smaller than the provisional booking of an item refund (21.00) took all of it, and
//   the item's own refund id booked 21.00 again: 42.00 on the ledger of a 42.00 order with 26.00 refunded, the order
//   "refunded", credit notes [-21.00, -21.00] instead of [-21.00, -5.00] — in every order of the events;
// - finding 4: of two refunds in flight of the same sum the summary matched the older one; it failed, its booking became
//   money of no refund, and the other's result booked it again (42.00 for 21.00 refunded);
// - low: a refund booked provisionally that Stripe failed later was never taken back (only a critical incident), and the
//   refund_dispatched_* marker of a refund call stayed for good.
echo "\n--- R26: provisional refund bookings — reclaimed only in part, moved to the refund they were, taken back when it failed ---\n";

if (!function_exists('stripeRefundKey')) require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
JobQueue::setDir(test_fixture_path('r26r-jobs'));
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R26rFakeStripe', false)) {
    class R26rFakeStripe extends StripePayment {
        public static ?Closure $during = null;
        /** @var array<string,string> refund key => the status Stripe answers (default succeeded) */
        public static array $outcome = [];
        public function __construct() { parent::__construct([]); }
        public static function refundIdOf(string $key): string { return 're_r26r_' . substr(sha1($key), 0, 10); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            $during = self::$during;
            self::$during = null;
            if ($during !== null) $during();
            return ['refund_id' => self::refundIdOf($idempotencyKey), 'status' => self::$outcome[$idempotencyKey] ?? 'succeeded'];
        }
    }
}
$r26rAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r26rSavedAdapters = $r26rAdapters->getValue();
$r26rAdapters->setValue(null, ['payment' => new R26rFakeStripe()] + $r26rSavedAdapters);
$r26rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r26rOrders = [];
$r26rOrder = static function (string $number, int $lines = 2) use ($pdo, $r26rProduct, &$r26rOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['product_id' => 1, 'variant_id' => null, 'sku' => "{$number}-PB", 'price' => 2100, 'qty' => 1, 'total' => 2100, 'available' => true,
        'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'books', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $skus = ["{$number}-PB"];
    for ($i = 2; $i <= $lines; $i++) $skus[] = "{$number}-PB{$i}";
    $items = Cart::applyVat(['items' => array_map(static fn(string $sku): array => ['sku' => $sku] + $line, $skus), 'subtotal' => 2100 * $lines, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R26R', 'r26r@example.test', '', '{\"country\":\"SK\"}', ?, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$number, 2100 * $lines, (int)$snapshot['totals']['gross'], 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card']),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $id = $r26rOrders[] = (int)$pdo->lastInsertId();
    // SQLite reuses the id of an order an earlier scenario deleted; rows it left under that id are not this order's.
    foreach (['payment_transactions', 'order_refunds'] as $r26rStale) $pdo->prepare("DELETE FROM {$r26rStale} WHERE order_id = ?")->execute([$id]);
    foreach ($skus as $sku) {
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 2100, 2100)')
            ->execute([$id, $r26rProduct, $sku, "R26R {$sku}"]);
    }
    InvoiceService::issue($id);
    return $id;
};
$r26rItemRefund = static function (int $orderId, int $line, string $key) use ($pdo): array {
    [, $rma] = Rma::create($orderId, null, "R26R return {$key}", [(int)Orders::items($orderId)[$line]['id'] => 1]);
    Rma::setStatus((int)$rma, 'approved');
    $rmaItem = (int)Rma::items((int)$rma)[0]['id'];
    Rma::receive((int)$rma, [$rmaItem => ['quantity' => 1, 'disposition' => 'repair']], "r26r-receipt-{$key}");
    $invoiceId = (int)$pdo->query("SELECT id FROM invoices WHERE order_id = {$orderId} AND type = 'invoice'")->fetchColumn();
    $credit = InvoiceService::credit($invoiceId, (int)$rma, [$rmaItem => 1], $key);
    return ['id' => (int)$credit['refund_id'], 'amount' => -(int)$credit['total_gross'], 'key' => $key, 'refund' => R26rFakeStripe::refundIdOf($key)];
};
$r26rJob = static function (int $orderId, array $item): void {
    WorkerJobs::handlePaymentRefund(['order_id' => $orderId, 'payment_id' => (string)Orders::byId($orderId)['payment_id'], 'amount' => $item['amount'],
        'refund_id' => $item['id'], 'idempotency_key' => $item['key']], []);
};
$r26rCredits = static fn(int $orderId): array => array_map('intval',
    $pdo->query("SELECT total_gross FROM invoices WHERE order_id = {$orderId} AND type = 'credit_note' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
// What the refund_credit_note jobs do: every ledger row booked, and every refund_credit_note job queued.
$r26rCreditJobs = static function (int $orderId) use ($pdo): void {
    foreach ($pdo->query("SELECT idempotency_key FROM payment_transactions WHERE order_id = {$orderId}
            AND ((status IN ('partially_refunded', 'refunded', 'refund_succeeded') AND amount > 0) OR status = 'refund_confirmed')")->fetchAll(PDO::FETCH_COLUMN) as $key) {
        InvoiceService::creditBookedRefund($orderId, (string)$key);
    }
};
$r26rItem = static fn(int $id): array => $pdo->query("SELECT status, payment_reference FROM order_refunds WHERE id = {$id}")->fetch(PDO::FETCH_NUM);
$r26rState = static fn(int $orderId): array => [PaymentService::refundedAmount($orderId), (string)Orders::byId($orderId)['payment_status'], PaymentService::refundableBalance($orderId)];
$r26rDashboard = static fn(int $orderId, string $id, int $amount, string $suffix = ''): bool =>
    PaymentService::bookProviderRefund($orderId, 'stripe', $id, $amount, 'refund.updated', 'stripe_refund_' . $id . $suffix);
$r26rOwnEvent = static fn(int $orderId, array $item, string $suffix = ''): bool => PaymentService::bookProviderRefund($orderId, 'stripe', $item['refund'], $item['amount'],
    'refund.updated', 'stripe_refund_' . $item['refund'] . $suffix, stripeRefundKey(['metadata' => [StripePayment::REFUND_KEY_METADATA => $item['key']]]));

try {
    // 1. Finding 2 in its three orders: the item's 21.00 is reported (and booked provisionally) while its call is at Stripe,
    //    then a 5.00 dashboard refund under its own id; the item's result (a) after it, (b) before it, (c) an event with the
    //    item's own key before it.
    foreach (['a', 'b', 'c'] as $r26rVariant) {
        $o = $r26rOrder('R26R-2' . strtoupper($r26rVariant));
        $a = $r26rItemRefund($o, 0, 'r26r-2' . $r26rVariant);
        $dash = 're_r26r2' . $r26rVariant . '_dashboard';
        R26rFakeStripe::$during = static function () use ($o, $a, $dash, $r26rVariant, $r26rCreditJobs, $r26rDashboard, $r26rOwnEvent): void {
            PaymentService::bookCumulativeRefund($o, 'stripe', $a['amount'], 'charge.refunded', 'stripe_refund_ch_r26r2' . $r26rVariant . '_' . $a['amount']);
            $r26rCreditJobs($o);
            if ($r26rVariant === 'c') {
                $r26rOwnEvent($o, $a);
                $r26rCreditJobs($o);
            }
            if ($r26rVariant !== 'b') {
                $r26rDashboard($o, $dash, 500);
                $r26rCreditJobs($o);
            }
        };
        $r26rJob($o, $a);
        $r26rCreditJobs($o);
        if ($r26rVariant === 'b') {
            $r26rDashboard($o, $dash, 500);
            $r26rCreditJobs($o);
        }
        // Later reports of the same refunds book nothing.
        $late = [PaymentService::bookCumulativeRefund($o, 'stripe', $a['amount'] + 500, 'charge.refunded', 'stripe_refund_ch_r26r2' . $r26rVariant . '_' . ($a['amount'] + 500)),
            $r26rDashboard($o, $dash, 500, '_again'), $r26rOwnEvent($o, $a, '_late')];
        $r26rCreditJobs($o);
        $total = (int)Orders::byId($o)['total'];
        assert_equals([[$a['amount'] + 500, 'partially_refunded', $total - $a['amount'] - 500], [-$a['amount'], -500], ['succeeded', $a['refund']], [false, false, false]],
            [$r26rState($o), $r26rCredits($o), $r26rItem($a['id']), $late],
            "R26 refunds ({$r26rVariant}): a 5.00 dashboard refund takes only 5.00 of the item's provisional 21.00 — ledger 26.00, credit notes -21.00 and -5.00 (was: 42.00, 'refunded', -21.00 twice)");
    }

    // 2. Finding 4: two item refunds of 21.00 in flight, the summary of 21.00 matched to the older (A); A fails.
    //    (a) A's failure before B's result; (b) B's result first, booked on its own, then A's failure.
    foreach (['a', 'b'] as $r26rVariant) {
        $o = $r26rOrder('R26R-4' . strtoupper($r26rVariant));
        $a = $r26rItemRefund($o, 0, 'r26r-4' . $r26rVariant . 'a');
        $b = $r26rItemRefund($o, 1, 'r26r-4' . $r26rVariant . 'b');
        R26rFakeStripe::$outcome = [$a['key'] => 'failed'];
        $summary = static function () use ($o, $a, $r26rVariant, $r26rCreditJobs): void {
            PaymentService::bookCumulativeRefund($o, 'stripe', $a['amount'], 'charge.refunded', 'stripe_refund_ch_r26r4' . $r26rVariant . '_' . $a['amount']);
            $r26rCreditJobs($o);
        };
        if ($r26rVariant === 'a') {
            // B's call is at Stripe; A's call too, the summary arrives, A fails; then B succeeds.
            R26rFakeStripe::$during = static function () use ($o, $a, $summary, $r26rJob, $r26rCreditJobs): void {
                R26rFakeStripe::$during = $summary;
                $r26rJob($o, $a);
                $r26rCreditJobs($o);
            };
            $r26rJob($o, $b);
        } else {
            // A's call is at Stripe; B's call too, the summary arrives, B succeeds; then A fails.
            R26rFakeStripe::$during = static function () use ($o, $b, $summary, $r26rJob, $r26rCreditJobs): void {
                R26rFakeStripe::$during = $summary;
                $r26rJob($o, $b);
                $r26rCreditJobs($o);
            };
            $r26rJob($o, $a);
        }
        $r26rCreditJobs($o);
        $total = (int)Orders::byId($o)['total'];
        assert_equals([[$b['amount'], 'partially_refunded', $total - $b['amount']], -$b['amount'], 'failed', ['succeeded', $b['refund']]],
            [$r26rState($o), array_sum($r26rCredits($o)), $r26rItem($a['id'])[0], $r26rItem($b['id'])],
            "R26 refunds ({$r26rVariant}): the summary matched to a refund that failed is the other refund of that sum — booked once, 21.00 (was: 42.00 for 21.00 refunded)");
        R26rFakeStripe::$outcome = [];
    }

    // 3. Low: a refund Stripe answers pending, booked provisionally by the summary, then fails (refund.failed): its booking is
    //    taken back, the item refund and its credit note are released. (b) The same refund succeeding later is completed once.
    foreach (['a', 'b'] as $r26rVariant) {
        $o = $r26rOrder('R26R-5' . strtoupper($r26rVariant));
        $a = $r26rItemRefund($o, 0, 'r26r-5' . $r26rVariant);
        R26rFakeStripe::$outcome = [$a['key'] => 'pending'];
        R26rFakeStripe::$during = static function () use ($o, $a, $r26rVariant, $r26rCreditJobs): void {
            PaymentService::bookCumulativeRefund($o, 'stripe', $a['amount'], 'charge.refunded', 'stripe_refund_ch_r26r5' . $r26rVariant . '_' . $a['amount']);
            $r26rCreditJobs($o);
        };
        $r26rJob($o, $a);
        $r26rCreditJobs($o);
        $pendingItem = $r26rItem($a['id'])[0];
        if ($r26rVariant === 'a') {
            PaymentService::providerRefundFailed($o, 'stripe', $a['refund'], $a['amount'], 'stripe_refund_failed_' . $a['refund']);
            $r26rCreditJobs($o);
            $total = (int)Orders::byId($o)['total'];
            assert_equals(['pending', [0, 'paid', $total], 0, 'failed'],
                [$pendingItem, $r26rState($o), array_sum($r26rCredits($o)), $r26rItem($a['id'])[0]],
                'R26 refunds: a provisionally booked refund that fails later is taken back — nothing on the ledger, the order paid in full, the credit note released (was: 21.00 booked for good, only an incident)');
        } else {
            $r26rOwnEvent($o, $a);
            $r26rCreditJobs($o);
            assert_equals(['pending', $a['amount'], [-$a['amount']], ['succeeded', $a['refund']]],
                [$pendingItem, PaymentService::refundedAmount($o), $r26rCredits($o), $r26rItem($a['id'])],
                'R26 refunds: a pending refund booked provisionally is completed by its refund.succeeded, booked once');
        }
        R26rFakeStripe::$outcome = [];
    }

    // 4. Low: the refund_dispatched_* marker of a refund call goes once its result is recorded.
    $r26rMarkers = (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE status = 'refund_dispatched' AND order_id IN (" . implode(',', $r26rOrders) . ')')->fetchColumn();
    assert_equals(0, $r26rMarkers, 'R26 refunds: no refund_dispatched marker stays once the refund call\'s result is recorded');
} catch (Throwable $e) {
    assert_true(false, 'R26 refunds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    R26rFakeStripe::$during = null;
    R26rFakeStripe::$outcome = [];
    $r26rAdapters->setValue(null, $r26rSavedAdapters);
    foreach ($r26rOrders as $r26rCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'return_receipts' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r26rTable => $r26rWhere) {
            try { $pdo->prepare("DELETE FROM {$r26rTable} WHERE {$r26rWhere}")->execute([$r26rCleanup]); } catch (Throwable $e) {}
        }
    }
}
