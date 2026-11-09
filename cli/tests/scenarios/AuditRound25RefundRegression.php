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
        /** @var list<string> Refund keys Stripe declines. */
        public static array $failKeys = [];
        public function __construct() { parent::__construct([]); }
        public static function refundIdOf(string $key): string { return 're_r25r_' . substr(sha1($key), 0, 10); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            $during = self::$during;
            self::$during = null;
            if ($during !== null) $during();
            return ['refund_id' => self::refundIdOf($idempotencyKey), 'status' => in_array($idempotencyKey, self::$failKeys, true) ? 'failed' : 'succeeded'];
        }
    }
}
$r25rAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r25rSavedAdapters = $r25rAdapters->getValue();
$r25rAdapters->setValue(null, ['payment' => new R25rFakeStripe()] + $r25rSavedAdapters);
$r25rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r25rOrders = [];
$r25rOrder = static function (string $number, int $lines = 2) use ($pdo, $r25rProduct, &$r25rOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['product_id' => 1, 'variant_id' => null, 'sku' => "{$number}-PB", 'price' => 2100, 'qty' => 1, 'total' => 2100, 'available' => true,
        'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'books', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $skus = ["{$number}-PB"];
    for ($i = 2; $i <= $lines; $i++) $skus[] = "{$number}-PB{$i}";
    $items = Cart::applyVat(['items' => array_map(static fn(string $sku): array => ['sku' => $sku] + $line, $skus), 'subtotal' => 2100 * $lines, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R25R', 'r25r@example.test', '', '{\"country\":\"SK\"}', ?, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$number, 2100 * $lines, (int)$snapshot['totals']['gross'], 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card']),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $id = $r25rOrders[] = (int)$pdo->lastInsertId();
    foreach ($skus as $sku) {
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

    // 7. Review run 379: an item refund sent to Stripe but not carried out yet, while a larger dashboard refund (25.00) is
    // reported by charge.refunded without its refund list and then by refund.updated under its own id.
    $o7 = $r25rOrder('R25R-7', 3);
    $e = $r25rItemRefund($o7, 0, 'r25r-7a');
    $dash7 = $e['amount'] + 400;
    $o7Steps = [];
    R25rFakeStripe::$during = static function () use ($o7, $e, $dash7, $r25rItem, $r25rCreditJobs, &$o7Steps): void {
        PaymentService::bookCumulativeRefund($o7, 'stripe', $dash7, 'charge.refunded', 'stripe_refund_ch_r25r7_' . $dash7);
        $r25rCreditJobs($o7);
        $o7Steps[] = [PaymentService::refundedAmount($o7), $r25rItem($e['id'])[0]];
        $o7Steps[] = PaymentService::bookProviderRefund($o7, 'stripe', 're_r25r7_dashboard', $dash7, 'refund.updated', 'stripe_refund_re_r25r7_dashboard');
        $r25rCreditJobs($o7);
        $o7Steps[] = PaymentService::refundedAmount($o7);
    };
    $r25rJob($o7, $e);
    $r25rCreditJobs($o7);
    $o7Late = PaymentService::bookCumulativeRefund($o7, 'stripe', $dash7 + $e['amount'], 'charge.refunded', 'stripe_refund_ch_r25r7_' . ($dash7 + $e['amount']));
    assert_equals([[$dash7, 'pending'], false, $dash7, $dash7 + $e['amount'], false, ['succeeded', R25rFakeStripe::refundIdOf('r25r-7a')], -($dash7 + $e['amount'])],
        [...$o7Steps, PaymentService::refundedAmount($o7), $o7Late, $r25rItem($e['id']), array_sum($r25rCredits($o7))],
        'R25 refunds: a dashboard refund larger than an item refund still at Stripe is booked once — its own id reclaims the money the summary event matched to the item, which is completed only by its own result (was: 50.00 on the ledger for 46.00 refunded)');

    // 7b. Review run 380: the same, but the item's job result (re_X succeeded) confirms the provisional booking before the
    // dashboard refund's own event arrives.
    $o7b = $r25rOrder('R25R-7B', 3);
    $h = $r25rItemRefund($o7b, 0, 'r25r-7b');
    $hRefund = R25rFakeStripe::refundIdOf('r25r-7b');
    $dash7b = $h['amount'] + 400;
    R25rFakeStripe::$during = static function () use ($o7b, $dash7b, $r25rCreditJobs): void {
        PaymentService::bookCumulativeRefund($o7b, 'stripe', $dash7b, 'charge.refunded', 'stripe_refund_ch_r25r7b_' . $dash7b);
        $r25rCreditJobs($o7b);
    };
    $r25rJob($o7b, $h);
    $r25rCreditJobs($o7b);
    $o7bSteps = [PaymentService::refundedAmount($o7b)];
    PaymentService::bookProviderRefund($o7b, 'stripe', 're_r25r7b_dashboard', $dash7b, 'refund.updated', 'stripe_refund_re_r25r7b_dashboard');
    $r25rCreditJobs($o7b);
    $o7bSteps[] = PaymentService::refundedAmount($o7b);
    $o7bSteps[] = PaymentService::bookCumulativeRefund($o7b, 'stripe', $dash7b + $h['amount'], 'charge.refunded', 'stripe_refund_ch_r25r7b_' . ($dash7b + $h['amount']));
    $o7bSteps[] = PaymentService::bookProviderRefund($o7b, 'stripe', $hRefund, $h['amount'], 'refund.updated', 'stripe_refund_' . $hRefund . '_late',
        stripeRefundKey(['metadata' => [StripePayment::REFUND_KEY_METADATA => 'r25r-7b']]));
    $o7bSteps[] = PaymentService::bookProviderRefund($o7b, 'stripe', 're_r25r7b_dashboard', $dash7b, 'refund.updated', 'stripe_refund_re_r25r7b_dashboard_again');
    $r25rCreditJobs($o7b);
    assert_equals([$dash7b, $dash7b + $h['amount'], false, false, false, $dash7b + $h['amount'], ['succeeded', $hRefund], -($dash7b + $h['amount'])],
        [...$o7bSteps, PaymentService::refundedAmount($o7b), $r25rItem($h['id']), array_sum($r25rCredits($o7b))],
        'R25 refunds: a dashboard refund\'s own id after the item\'s job result confirmed the summary booking reclaims it and books the item\'s money under its own refund once (was: 50.00 on the ledger for 46.00 refunded)');

    // 7c. The same, but an event naming the item's refund with its key confirms the provisional booking first.
    $o7c = $r25rOrder('R25R-7C', 3);
    $k = $r25rItemRefund($o7c, 0, 'r25r-7c');
    $kRefund = R25rFakeStripe::refundIdOf('r25r-7c');
    $dash7c = $k['amount'] + 400;
    $o7cSteps = [];
    R25rFakeStripe::$during = static function () use ($o7c, $k, $kRefund, $dash7c, $r25rCreditJobs, &$o7cSteps): void {
        PaymentService::bookCumulativeRefund($o7c, 'stripe', $dash7c, 'charge.refunded', 'stripe_refund_ch_r25r7c_' . $dash7c);
        $r25rCreditJobs($o7c);
        $o7cSteps[] = PaymentService::bookProviderRefund($o7c, 'stripe', $kRefund, $k['amount'], 'refund.updated', 'stripe_refund_' . $kRefund,
            stripeRefundKey(['metadata' => [StripePayment::REFUND_KEY_METADATA => 'r25r-7c']]));
        $r25rCreditJobs($o7c);
        PaymentService::bookProviderRefund($o7c, 'stripe', 're_r25r7c_dashboard', $dash7c, 'refund.updated', 'stripe_refund_re_r25r7c_dashboard');
        $r25rCreditJobs($o7c);
        $o7cSteps[] = PaymentService::refundedAmount($o7c);
    };
    $r25rJob($o7c, $k);
    $r25rCreditJobs($o7c);
    assert_equals([false, $dash7c + $k['amount'], $dash7c + $k['amount'], ['succeeded', $kRefund], -($dash7c + $k['amount'])],
        [...$o7cSteps, PaymentService::refundedAmount($o7c), $r25rItem($k['id']), array_sum($r25rCredits($o7c))],
        'R25 refunds: a dashboard refund\'s own id after an event naming the item\'s refund confirmed the summary booking is booked once, the item\'s money once under its own refund');

    // 8. The same, but Stripe declines the item refund: the summary's money was the dashboard refund alone.
    $o8 = $r25rOrder('R25R-8', 3);
    $g = $r25rItemRefund($o8, 0, 'r25r-8a');
    $dash8 = $g['amount'] + 400;
    R25rFakeStripe::$failKeys = ['r25r-8a'];
    R25rFakeStripe::$during = static function () use ($o8, $dash8, $r25rCreditJobs): void {
        PaymentService::bookCumulativeRefund($o8, 'stripe', $dash8, 'charge.refunded', 'stripe_refund_ch_r25r8_' . $dash8);
        $r25rCreditJobs($o8);
    };
    $r25rJob($o8, $g);
    $r25rCreditJobs($o8);
    $o8Dashboard = PaymentService::bookProviderRefund($o8, 'stripe', 're_r25r8_dashboard', $dash8, 'refund.updated', 'stripe_refund_re_r25r8_dashboard');
    $r25rCreditJobs($o8);
    assert_equals([$dash8, false, 'failed'],
        [PaymentService::refundedAmount($o8), $o8Dashboard, $r25rItem($g['id'])[0]],
        'R25 refunds: an item refund Stripe declined is not completed by a summary event\'s money, and the dashboard refund\'s own id only identifies it');

    // 6. The refund key travels with the Stripe refund and is read back from the webhook.
    $r25rStripeSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/adapters/contrib/StripePayment.php');
    assert_true(str_contains($r25rStripeSrc, "\$params['metadata'] = [self::REFUND_KEY_METADATA => \$idempotencyKey]")
        && stripeRefundKey(['metadata' => [StripePayment::REFUND_KEY_METADATA => 'k1']]) === ['refund_key' => 'k1'] && stripeRefundKey([]) === [],
        'R25 refunds: Stripe refunds carry the shop\'s refund key in their metadata and the webhook reads it back');
} catch (Throwable $e) {
    $r25rFail('cumulative Stripe refund', $e);
} finally {
    R25rFakeStripe::$during = null;
    R25rFakeStripe::$failKeys = [];
    $r25rAdapters->setValue(null, $r25rSavedAdapters);
    foreach ($r25rOrders as $r25rCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'return_receipts' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r25rTable => $r25rWhere) {
            try { $pdo->prepare("DELETE FROM {$r25rTable} WHERE {$r25rWhere}")->execute([$r25rCleanup]); } catch (Throwable $e) {}
        }
    }
}
