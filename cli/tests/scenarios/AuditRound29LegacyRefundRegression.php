<?php

// Review round 29 (2026-11-11), low "dvojité zaúčtovanie refundácie z 2.0.x" (goal #410): up to 2.1.2 a Stripe
// charge.refunded booked the change of the charge's refunded total under the payment id, without refund ids. After the
// update every charge.refunded lists all of the charge's refunds by id, so the next dashboard refund booked the old
// refund's money a second time (10.00 + 5.00 refunded, 25.00 on the ledger). R29: a refund without a shop key that Stripe
// created before the last such booking is identified against that money, oldest first; only newer refunds are booked.
echo "\n--- R29: a refund booked up to 2.1.2 without its id is not booked again when a later event lists it ---\n";

if (!function_exists('stripeRefundKey')) require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
JobQueue::setDir(test_fixture_path('r29l-jobs'));
$r29lOrders = [];
$r29lOrder = static function (string $number, string $paymentStatus) use ($pdo, &$r29lOrders): int {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, store_id, created_at)
        VALUES (?, 'R29L', 'r29l@example.test', '', '{\"country\":\"SK\"}', 4200, 0, 0, 0, 4200, 'eur', 'delivered', ?, 'stripe', ?, ?, 'pickup', 'default', " . Db::now() . ")")
        ->execute([$number, $paymentStatus, 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card'])]);
    $id = $r29lOrders[] = (int)$pdo->lastInsertId();
    // SQLite reuses the id of an order an earlier scenario deleted; rows it left under that id are not this order's.
    foreach (['payment_transactions', 'order_refunds'] as $r29lStale) $pdo->prepare("DELETE FROM {$r29lStale} WHERE order_id = ?")->execute([$id]);
    return $id;
};
// What 2.0.x (ApiHandlers onChargeRefunded) booked for a charge.refunded: the change of amount_refunded under the payment id.
$r29lLegacy = static function (int $orderId, int $amount, int $cumulative, int $at) use ($pdo): void {
    $stamp = gmdate('Y-m-d H:i:s', $at);
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response, created_at, updated_at)
        VALUES (?, 'default', 'stripe', ?, ?, ?, 'eur', ?, ?, ?, ?)")
        ->execute([$orderId, (string)Orders::byId($orderId)['payment_id'], $cumulative >= 4200 ? 'refunded' : 'partially_refunded', $amount,
            "stripe_refund_ch_r29l_{$orderId}_{$cumulative}", json_encode(['cumulative_amount' => $cumulative]), $stamp, $stamp]);
};
$r29lRefund = static fn(string $id, int $amount, int $created): array => ['id' => $id, 'amount' => $amount, 'status' => 'succeeded', 'created' => $created,
    'charge' => 'ch_r29l', 'metadata' => []];
// charge.refunded as Stripe sends it now: its refunds embedded, newest first.
$r29lSummary = static function (int $orderId, array $refunds): void {
    $order = Orders::byId($orderId);
    usort($refunds, static fn(array $a, array $b): int => $b['created'] <=> $a['created']);
    applyStripeChargeRefunded(['id' => 'ch_r29l_' . $orderId, 'payment_intent' => (string)$order['payment_id'],
        'amount_refunded' => array_sum(array_column($refunds, 'amount')), 'refunds' => ['data' => $refunds, 'has_more' => false]], (string)$order['payment_id'], $orderId);
};
$r29lUpdated = static fn(int $orderId, array $refund, string $event): mixed =>
    applyStripeRefundUpdated($refund + ['payment_intent' => (string)Orders::byId($orderId)['payment_id']], $event, $orderId);
$r29lState = static fn(int $orderId): array => [PaymentService::refundedAmount($orderId), (string)Orders::byId($orderId)['payment_status']];
$r29lConfirmed = static fn(int $orderId): array => array_map('strval', $pdo->query("SELECT provider_ref FROM payment_transactions WHERE order_id = {$orderId}
    AND status = 'refund_confirmed' ORDER BY provider_ref")->fetchAll(PDO::FETCH_COLUMN));

try {
    $then = time() - 2 * 86400;
    $now = time();

    // 1. 10.00 refunded in the dashboard and booked by 2.0.x; after the update a 5.00 dashboard refund. Its charge.refunded
    //    lists both (newest first) and is redelivered once.
    $o = $r29lOrder('R29L-A', 'partially_refunded');
    $r29lLegacy($o, 1000, 1000, $then);
    $old = $r29lRefund('re_r29l_a_old', 1000, $then - 30);
    $new = $r29lRefund('re_r29l_a_new', 500, $now);
    $r29lSummary($o, [$old, $new]);
    $first = $r29lState($o);
    $r29lSummary($o, [$old, $new]);
    assert_equals([[1500, 'partially_refunded'], [1500, 'partially_refunded'], ['re_r29l_a_old']], [$first, $r29lState($o), $r29lConfirmed($o)],
        'R29 legacy refund: the 10.00 2.0.x booked is only identified by its id, the new 5.00 is booked — 15.00 on the ledger, also redelivered (was: 25.00)');

    // 2. The new refund's own refund.updated comes first: it is booked (created after the old booking), the summary then
    //    identifies the old one and books nothing more.
    $o = $r29lOrder('R29L-B', 'partially_refunded');
    $r29lLegacy($o, 1000, 1000, $then);
    $old = $r29lRefund('re_r29l_b_old', 1000, $then - 30);
    $new = $r29lRefund('re_r29l_b_new', 500, $now);
    $r29lUpdated($o, $new, 'evt_r29l_b');
    $first = $r29lState($o);
    $r29lSummary($o, [$old, $new]);
    assert_equals([[1500, 'partially_refunded'], [1500, 'partially_refunded']], [$first, $r29lState($o)],
        'R29 legacy refund: a new refund\'s refund.updated before the summary is booked, not taken for the old one (15.00)');

    // 3. Two refunds 2.0.x booked one event apart (10.00, then 5.00 more), then a new 2.00 and the rest refunded together:
    //    oldest first, the two are identified, the 2.00 and 25.00 booked and the payment refunded.
    $o = $r29lOrder('R29L-C', 'partially_refunded');
    $r29lLegacy($o, 1000, 1000, $then);
    $r29lLegacy($o, 500, 1500, $then + 600);
    $refunds = [$r29lRefund('re_r29l_c_1', 1000, $then - 30), $r29lRefund('re_r29l_c_2', 500, $then + 570),
        $r29lRefund('re_r29l_c_3', 200, $now - 60), $r29lRefund('re_r29l_c_4', 2500, $now)];
    $r29lSummary($o, $refunds);
    assert_equals([[4200, 'refunded'], ['re_r29l_c_1', 're_r29l_c_2']], [$r29lState($o), $r29lConfirmed($o)],
        'R29 legacy refund: two old refunds are identified oldest first, the new ones booked: 42.00 refunded (was: 57.00)');

    // 4. No booking without ids: an old dashboard refund the shop never booked is booked as it is.
    $o = $r29lOrder('R29L-D', 'paid');
    $r29lSummary($o, [$r29lRefund('re_r29l_d_old', 1000, $then - 30)]);
    assert_equals([[1000, 'partially_refunded'], []], [$r29lState($o), $r29lConfirmed($o)],
        'R29 legacy refund: with no booking without refund ids, an old refund is booked by its id');

    // 5. Stripe's events the shop handles are the ones the operations runbook tells the merchant to send.
    $r29lApi = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php');
    $r29lDocs = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/docs/OPERATIONS_RUNBOOK.md');
    preg_match('/\$resolvedStore = match\(\$event\[\'type\'\]\) \{(.*?)default/s', $r29lApi, $r29lMatch);
    preg_match_all("/'([a-z_]+(?:\.[a-z_]+)+)'/", $r29lMatch[1] ?? '', $r29lEvents);
    $r29lMissing = array_values(array_filter($r29lEvents[1], static fn(string $event): bool => !str_contains($r29lDocs, "`{$event}`")));
    assert_true(count($r29lEvents[1]) >= 7 && $r29lMissing === [] && str_contains($r29lDocs, '/api/v1/webhooks/payment/stripe'),
        'R29 docs: the operations runbook lists every Stripe event the webhook handles and its URL (missing: ' . implode(', ', $r29lMissing) . ')');
} catch (Throwable $e) {
    assert_true(false, 'R29 legacy refund (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    foreach ($r29lOrders as $r29lCleanup) {
        foreach (['order_refunds', 'payment_transactions', 'order_items'] as $r29lTable) {
            try { $pdo->prepare("DELETE FROM {$r29lTable} WHERE order_id = ?")->execute([$r29lCleanup]); } catch (Throwable $e) {}
        }
        try { $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r29lCleanup]); } catch (Throwable $e) {}
    }
}
