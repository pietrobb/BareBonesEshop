<?php

// Review round 27 (2026-11-10, "kolo 11"), findings 2 and 3 (goal #395): the provisional booking of a Stripe
// charge.refunded without its refund list (R25/R26) left the ledger off Stripe — a dashboard refund reclaiming part of it
// and the summary re-booked (D500: 3100 on the ledger for 2600 refunded), a refund booked by its own event plus a failed
// one matched by the summary (S2c: 4200 for 2100, "refunded"), a failed refund never taken back (S3c, S3d, S3g), and the
// event key stripe_refund_<charge>_<amount> colliding after a reversal (S3f). R27: such an event books nothing and is
// redelivered by Stripe; money is booked only by refund id. The ledger equals what Stripe returned in every order.
echo "\n--- R27: charge.refunded without its refund list is deferred; the ledger is what Stripe refunded, by refund id ---\n";

if (!function_exists('stripeRefundKey')) require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
JobQueue::setDir(test_fixture_path('r27r-jobs'));
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R27rFakeStripe', false)) {
    class R27rFakeStripe extends StripePayment {
        public static ?Closure $during = null;
        /** @var array<string,string> refund key => the status Stripe answers (default succeeded) */
        public static array $outcome = [];
        /** @var list<array<string,mixed>>|null what GET /v1/refunds?charge= answers; null: not available */
        public static ?array $list = null;
        public static bool $listFails = false;
        public function __construct() { parent::__construct([]); }
        public static function refundIdOf(string $key): string { return 're_r27r_' . substr(sha1($key), 0, 10); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            $during = self::$during;
            self::$during = null;
            if ($during !== null) $during();
            return ['refund_id' => self::refundIdOf($idempotencyKey), 'status' => self::$outcome[$idempotencyKey] ?? 'succeeded'];
        }
        public function chargeRefunds(string $chargeId): ?array {
            if (self::$listFails) throw new RuntimeException('Stripe API unreachable');
            return self::$list;
        }
    }
}
$r27rAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r27rSavedAdapters = $r27rAdapters->getValue();
$r27rAdapters->setValue(null, ['payment' => new R27rFakeStripe()] + $r27rSavedAdapters);
$r27rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r27rOrders = [];
$r27rOrder = static function (string $number) use ($pdo, $r27rProduct, &$r27rOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['product_id' => 1, 'variant_id' => null, 'sku' => "{$number}-PB", 'price' => 2100, 'qty' => 1, 'total' => 2100, 'available' => true,
        'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'books', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $skus = ["{$number}-PB", "{$number}-PB2"];
    $items = Cart::applyVat(['items' => array_map(static fn(string $sku): array => ['sku' => $sku] + $line, $skus), 'subtotal' => 4200, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R27R', 'r27r@example.test', '', '{\"country\":\"SK\"}', 4200, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$number, (int)$snapshot['totals']['gross'], 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card']),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $id = $r27rOrders[] = (int)$pdo->lastInsertId();
    // SQLite reuses the id of an order an earlier scenario deleted; rows it left under that id are not this order's.
    foreach (['payment_transactions', 'order_refunds'] as $r27rStale) $pdo->prepare("DELETE FROM {$r27rStale} WHERE order_id = ?")->execute([$id]);
    foreach ($skus as $sku) {
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 2100, 2100)')
            ->execute([$id, $r27rProduct, $sku, "R27R {$sku}"]);
    }
    InvoiceService::issue($id);
    return $id;
};
$r27rItemRefund = static function (int $orderId, int $line, string $key) use ($pdo): array {
    [, $rma] = Rma::create($orderId, null, "R27R return {$key}", [(int)Orders::items($orderId)[$line]['id'] => 1]);
    Rma::setStatus((int)$rma, 'approved');
    $rmaItem = (int)Rma::items((int)$rma)[0]['id'];
    Rma::receive((int)$rma, [$rmaItem => ['quantity' => 1, 'disposition' => 'repair']], "r27r-receipt-{$key}");
    $invoiceId = (int)$pdo->query("SELECT id FROM invoices WHERE order_id = {$orderId} AND type = 'invoice'")->fetchColumn();
    $credit = InvoiceService::credit($invoiceId, (int)$rma, [$rmaItem => 1], $key);
    return ['id' => (int)$credit['refund_id'], 'amount' => -(int)$credit['total_gross'], 'key' => $key, 'refund' => R27rFakeStripe::refundIdOf($key)];
};
$r27rJob = static function (int $orderId, array $item): void {
    WorkerJobs::handlePaymentRefund(['order_id' => $orderId, 'payment_id' => (string)Orders::byId($orderId)['payment_id'], 'amount' => $item['amount'],
        'refund_id' => $item['id'], 'idempotency_key' => $item['key']], []);
};
$r27rCredits = static fn(int $orderId): array => array_map('intval',
    $pdo->query("SELECT total_gross FROM invoices WHERE order_id = {$orderId} AND type = 'credit_note' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$r27rCreditJobs = static function (int $orderId) use ($pdo): void {
    foreach ($pdo->query("SELECT idempotency_key FROM payment_transactions WHERE order_id = {$orderId}
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded') AND amount > 0")->fetchAll(PDO::FETCH_COLUMN) as $key) {
        InvoiceService::creditBookedRefund($orderId, (string)$key);
    }
};
$r27rItem = static fn(int $id): string => (string)$pdo->query("SELECT status FROM order_refunds WHERE id = {$id}")->fetchColumn();
$r27rState = static fn(int $orderId): array => [PaymentService::refundedAmount($orderId), (string)Orders::byId($orderId)['payment_status']];
// A Stripe refund as the API and the webhooks show it; the shop's own carries its refund key in the metadata.
$r27rRefund = static fn(string $id, int $amount, string $status, string $key = ''): array => ['id' => $id, 'amount' => $amount, 'status' => $status, 'charge' => 'ch_r27r',
    'metadata' => $key !== '' ? [StripePayment::REFUND_KEY_METADATA => $key] : []];
// charge.refunded: what it reports refunded and, when Stripe can list them, its refunds. Returns whether it was deferred.
$r27rSummary = static function (int $orderId, int $refunded, ?array $list) use ($pdo): bool {
    R27rFakeStripe::$list = $list;
    $order = Orders::byId($orderId);
    try {
        applyStripeChargeRefunded(['id' => 'ch_r27r_' . $orderId, 'payment_intent' => (string)$order['payment_id'], 'amount_refunded' => $refunded], (string)$order['payment_id'], $orderId);
        return false;
    } catch (PaymentWebhookDeferred $e) {
        return true;
    } finally {
        R27rFakeStripe::$list = null;
    }
};
$r27rUpdated = static fn(int $orderId, array $refund, string $event): mixed =>
    applyStripeRefundUpdated($refund + ['payment_intent' => (string)Orders::byId($orderId)['payment_id']], $event, $orderId);

try {
    // 1. D500 (finding 2): the item's 21.00 at Stripe, a 5.00 dashboard refund; the summary (26.00) comes while the item's
    //    call is in flight (a) before the dashboard refund's own event, (b) after it. Redelivered with the list either before
    //    or after the item's result.
    foreach (['a', 'b'] as $v) {
        $o = $r27rOrder('R27R-D' . strtoupper($v));
        $a = $r27rItemRefund($o, 0, 'r27r-d' . $v);
        $dash = $r27rRefund('re_r27r_d' . $v . '_dashboard', 500, 'succeeded');
        $deferred = [];
        R27rFakeStripe::$during = static function () use ($o, $a, $dash, $v, &$deferred, $r27rSummary, $r27rUpdated, $r27rRefund, $r27rState): void {
            if ($v === 'b') $r27rUpdated($o, $dash, 'evt_r27r_d' . $v);
            $deferred[] = $r27rSummary($o, $a['amount'] + 500, null);
            $deferred[] = $r27rState($o);
            if ($v === 'a') {
                $r27rUpdated($o, $dash, 'evt_r27r_d' . $v);
                // Redelivered with the list before the item's result: the item is booked by its key, its result confirms it.
                $deferred[] = $r27rSummary($o, $a['amount'] + 500, [$r27rRefund($a['refund'], $a['amount'], 'succeeded', $a['key']), $dash]);
            }
        };
        $r27rJob($o, $a);
        if ($v === 'b') $deferred[] = $r27rSummary($o, $a['amount'] + 500, [$r27rRefund($a['refund'], $a['amount'], 'succeeded', $a['key']), $dash]);
        $r27rCreditJobs($o);
        assert_equals([true, $v === 'a' ? [0, 'refund_pending'] : [500, 'partially_refunded'], false], $deferred,
            "R27 D500 ({$v}): charge.refunded without its refund list books nothing (deferred); with it, it books nothing twice");
        assert_equals([[$a['amount'] + 500, 'partially_refunded'], [-$a['amount'], -500], 'succeeded'], [$r27rState($o), $r27rCredits($o), $r27rItem($a['id'])],
            "R27 D500 ({$v}): the ledger is 26.00 as Stripe refunded, credit notes [-21.00, -5.00], the item refund completed (was: 31.00, [-21.00, -5.00, -5.00])");
    }

    // 2. S2c: items A and B at Stripe; B booked by its own event, A's call fails, the summary came between.
    $o = $r27rOrder('R27R-S2C');
    $a = $r27rItemRefund($o, 0, 'r27r-s2c-a');
    $b = $r27rItemRefund($o, 1, 'r27r-s2c-b');
    $r27rUpdated($o, $r27rRefund($b['refund'], $b['amount'], 'succeeded', $b['key']), 'evt_r27r_s2c_b');
    R27rFakeStripe::$outcome[$a['key']] = 'failed';
    $s2cDeferred = null;
    R27rFakeStripe::$during = static function () use ($o, $a, $b, &$s2cDeferred, $r27rSummary): void {
        $s2cDeferred = $r27rSummary($o, $a['amount'] + $b['amount'], null);
    };
    $r27rJob($o, $a);
    $r27rJob($o, $b);
    $s2cLate = $r27rSummary($o, $b['amount'], [$r27rRefund($a['refund'], $a['amount'], 'failed', $a['key']), $r27rRefund($b['refund'], $b['amount'], 'succeeded', $b['key'])]);
    $r27rCreditJobs($o);
    // A's credit note is reversed with its failed refund (+21.00), B's stays.
    assert_equals([true, false, [$b['amount'], 'partially_refunded'], 'failed', 'succeeded', [-$a['amount'], -$b['amount'], $a['amount']]],
        [$s2cDeferred, $s2cLate, $r27rState($o), $r27rItem($a['id']), $r27rItem($b['id']), $r27rCredits($o)],
        'R27 S2c: B booked once by its id, A failed and released, the ledger 21.00 "partially_refunded" (was: 42.00 "refunded")');

    // 3. S3c / S3d / S3g: A answered pending (SEPA); the summary counts it; Stripe fails it. (c) refund.failed after the
    //    job's result, (d) the same with a 5.00 dashboard refund, (g) refund.failed before the job's result.
    foreach (['c', 'd', 'g'] as $v) {
        $o = $r27rOrder('R27R-S3' . strtoupper($v));
        $a = $r27rItemRefund($o, 0, 'r27r-s3' . $v);
        R27rFakeStripe::$outcome[$a['key']] = 'pending';
        $r27rFailedRefund = $r27rRefund($a['refund'], $a['amount'], 'failed', $a['key']);
        R27rFakeStripe::$during = static function () use ($o, $a, $v, $r27rFailedRefund, $r27rSummary, $r27rUpdated, $r27rRefund): void {
            $r27rSummary($o, $a['amount'] + ($v === 'd' ? 500 : 0), null);
            if ($v === 'd') $r27rUpdated($o, $r27rRefund('re_r27r_s3d_dashboard', 500, 'succeeded'), 'evt_r27r_s3d_dash');
            if ($v === 'g') $r27rUpdated($o, $r27rFailedRefund, 'evt_r27r_s3g_failed');
        };
        $r27rJob($o, $a);
        if ($v !== 'g') $r27rUpdated($o, $r27rFailedRefund, 'evt_r27r_s3' . $v . '_failed');
        $list = [$r27rFailedRefund] + ($v === 'd' ? [1 => $r27rRefund('re_r27r_s3d_dashboard', 500, 'succeeded')] : []);
        $late = $r27rSummary($o, $v === 'd' ? 500 : 0, array_values($list));
        $r27rCreditJobs($o);
        $dash = $v === 'd' ? 500 : 0;
        // The item's credit note is reversed with its failed refund (+21.00).
        assert_equals([false, [$dash, $dash > 0 ? 'partially_refunded' : 'paid'], 'failed', $dash > 0 ? [-$a['amount'], $a['amount'], -500] : [-$a['amount'], $a['amount']]],
            [$late, $r27rState($o), $r27rItem($a['id']), $r27rCredits($o)],
            "R27 S3{$v}: a refund Stripe failed is never on the ledger and its item refund is released" . ($v === 'g' ? ' also when refund.failed came before the job\'s pending result' : '')
            . ' (was: its provisional booking stayed)');
    }

    // 4. S3f: the summary keyed by charge and amount collided after a reversal; now nothing is booked without a refund id,
    //    and two refunds of the same sum are two rows.
    $o = $r27rOrder('R27R-S3F');
    assert_true($r27rSummary($o, 2100, null) && $r27rSummary($o, 2100, null), 'R27 S3f: a charge.refunded without its refund list is deferred on every delivery');
    $r27rSummary($o, 2100, [$r27rRefund('re_r27r_s3f_1', 2100, 'succeeded')]);
    $r27rSummary($o, 4200, [$r27rRefund('re_r27r_s3f_1', 2100, 'succeeded'), $r27rRefund('re_r27r_s3f_2', 2100, 'succeeded')]);
    assert_equals([[4200, 'refunded'], 0], [$r27rState($o),
        (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE order_id = {$o} AND idempotency_key LIKE 'stripe_refund_ch_%'")->fetchColumn()],
        'R27 S3f: two refunds of the same sum are both booked by their ids; no row is keyed by charge and amount (was: the second 21.00 lost)');

    // 5. Stripe's refund list unreachable: deferred too (was: a 500 and a critical incident).
    $o = $r27rOrder('R27R-API');
    R27rFakeStripe::$listFails = true;
    try {
        $apiDeferred = $r27rSummary($o, 2100, [$r27rRefund('re_r27r_api', 2100, 'succeeded')]);
    } finally {
        R27rFakeStripe::$listFails = false;
    }
    assert_equals([true, [0, 'paid']], [$apiDeferred, $r27rState($o)], 'R27 refunds: a refund list Stripe cannot answer defers the event, nothing booked');

    $r27rStripeSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/adapters/contrib/StripePayment.php');
    assert_true(str_contains($r27rStripeSrc, "\$params['metadata'] = [self::REFUND_KEY_METADATA => \$idempotencyKey]")
        && stripeRefundKey(['metadata' => [StripePayment::REFUND_KEY_METADATA => 'k1']]) === ['refund_key' => 'k1'] && stripeRefundKey([]) === [],
        'R27 refunds: Stripe refunds carry the shop\'s refund key in their metadata and the webhook reads it back');
    $r27rApi = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php');
    assert_true(!str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/PaymentService.php'), 'function bookCumulativeRefund')
        && preg_match('/catch \(PaymentWebhookDeferred \$e\) \{\s*Idempotency::unmark\(\$eventKey\);.*?http_response_code\(503\);/s', $r27rApi) === 1,
        'R27 refunds: no cumulative booking is left; a deferred event is unmarked and answered 503 so Stripe redelivers it');
} catch (Throwable $e) {
    assert_true(false, 'R27 refunds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    R27rFakeStripe::$during = null;
    R27rFakeStripe::$outcome = [];
    $r27rAdapters->setValue(null, $r27rSavedAdapters);
    foreach ($r27rOrders as $r27rCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r27rTable => $r27rWhere) {
            try { $pdo->prepare("DELETE FROM {$r27rTable} WHERE {$r27rWhere}")->execute([$r27rCleanup]); } catch (Throwable $e) {}
        }
    }
}
