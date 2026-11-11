<?php

// Review round 30, low: R29 identified a refund without a shop key as one 2.0.x booked (without its id) when Stripe
// created it up to five minutes after the last such booking (a shop clock behind Stripe's). A new dashboard refund in
// those five minutes whose refund.updated came first was taken for the old one (not booked), and the old one was booked a
// second time when the charge.refunded listed it. Now a charge.refunded tells them apart by each refund's place in the
// charge's refunded total, and a refund alone near the last booking waits for that list.
echo "\n--- R30: a refund created near a 2.0.x booking is booked once, whatever comes first ---\n";

if (!function_exists('stripeRefundKey')) require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
JobQueue::setDir(test_fixture_path('r30l-jobs'));
$r30lOrders = [];
$r30lOrder = static function (string $number) use ($pdo, &$r30lOrders): int {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, store_id, created_at)
        VALUES (?, 'R30L', 'r30l@example.test', '', '{\"country\":\"SK\"}', 4200, 0, 0, 0, 4200, 'eur', 'delivered', 'partially_refunded', 'stripe', ?, ?, 'pickup', 'default', " . Db::now() . ")")
        ->execute([$number, 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card'])]);
    $id = $r30lOrders[] = (int)$pdo->lastInsertId();
    foreach (['payment_transactions', 'order_refunds'] as $r30lStale) $pdo->prepare("DELETE FROM {$r30lStale} WHERE order_id = ?")->execute([$id]);
    return $id;
};
// What 2.0.x booked for a charge.refunded: the change of amount_refunded under the payment id.
$r30lLegacy = static function (int $orderId, int $amount, int $cumulative, int $at) use ($pdo): void {
    $stamp = gmdate('Y-m-d H:i:s', $at);
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response, created_at, updated_at)
        VALUES (?, 'default', 'stripe', ?, 'partially_refunded', ?, 'eur', ?, ?, ?, ?)")
        ->execute([$orderId, (string)Orders::byId($orderId)['payment_id'], $amount, "stripe_refund_ch_r30l_{$orderId}_{$cumulative}",
            json_encode(['cumulative_amount' => $cumulative]), $stamp, $stamp]);
};
$r30lRefund = static fn(string $id, int $amount, int $created): array => ['id' => $id, 'amount' => $amount, 'status' => 'succeeded', 'created' => $created,
    'charge' => 'ch_r30l', 'metadata' => []];
$r30lSummary = static function (int $orderId, array $refunds): void {
    $order = Orders::byId($orderId);
    usort($refunds, static fn(array $a, array $b): int => $b['created'] <=> $a['created']);
    applyStripeChargeRefunded(['id' => 'ch_r30l_' . $orderId, 'payment_intent' => (string)$order['payment_id'],
        'amount_refunded' => array_sum(array_column($refunds, 'amount')), 'refunds' => ['data' => $refunds, 'has_more' => false]], (string)$order['payment_id'], $orderId);
};
$r30lUpdated = static function (int $orderId, array $refund, string $event): string {
    try {
        applyStripeRefundUpdated($refund + ['payment_intent' => (string)Orders::byId($orderId)['payment_id']], $event, $orderId);
        return 'processed';
    } catch (PaymentWebhookDeferred) {
        return 'deferred';
    }
};

try {
    $booked = time() - 120; // the 2.0.x booking, two minutes ago by the shop's clock
    // 1. A 5.00 dashboard refund a minute after that booking; its refund.updated comes before the charge.refunded.
    $o = $r30lOrder('R30L-A');
    $r30lLegacy($o, 1000, 1000, $booked);
    $old = $r30lRefund('re_r30l_a_old', 1000, $booked - 5);
    $new = $r30lRefund('re_r30l_a_new', 500, $booked + 60);
    $first = $r30lUpdated($o, $new, 'evt_r30l_a');
    $r30lSummary($o, [$old, $new]);
    $again = $r30lUpdated($o, $new, 'evt_r30l_a');
    assert_equals(['deferred', 'processed', 1500], [$first, $again, PaymentService::refundedAmount($o)],
        'R30 legacy refund: a new refund near the old booking waits for the charge.refunded, which books it and identifies the old one: 15.00 (was: 20.00, the old one twice)');

    // 2. The shop's clock behind Stripe's: the refund 2.0.x booked was "created" after the booking; a new refund too.
    $o = $r30lOrder('R30L-B');
    $r30lLegacy($o, 1000, 1000, $booked);
    $r30lSummary($o, [$r30lRefund('re_r30l_b_old', 1000, $booked + 100), $r30lRefund('re_r30l_b_new', 1000, $booked + 200)]);
    assert_equals(2000, PaymentService::refundedAmount($o),
        'R30 legacy refund: the charge\'s refunded total, not the clocks, tells the old refund (identified) from the new one of the same amount (booked)');

    // 3. A refund alone long after the last old booking is booked at once.
    $o = $r30lOrder('R30L-C');
    $r30lLegacy($o, 1000, 1000, $booked - 86400);
    assert_equals(['processed', 1500], [$r30lUpdated($o, $r30lRefund('re_r30l_c_new', 500, $booked), 'evt_r30l_c'), PaymentService::refundedAmount($o)],
        'R30 legacy refund: a refund made a day after the old booking is booked without waiting');
} catch (Throwable $e) {
    assert_true(false, 'R30 legacy refund (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    foreach ($r30lOrders as $r30lCleanup) {
        foreach (['order_refunds', 'payment_transactions', 'order_items'] as $r30lTable) {
            try { $pdo->prepare("DELETE FROM {$r30lTable} WHERE order_id = ?")->execute([$r30lCleanup]); } catch (Throwable $e) {}
        }
        try { $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r30lCleanup]); } catch (Throwable $e) {}
    }
}
