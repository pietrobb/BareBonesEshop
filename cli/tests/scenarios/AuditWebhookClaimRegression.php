<?php

// Code review M7: a webhook counts as processed only after its handler finished. A process that dies mid-event
// (fatal error, timeout) leaves a 'processing' claim; a redelivery during the lease is asked to retry, and one after
// the lease takes the claim over and processes the event instead of skipping it forever.
echo "\n--- Code review M7: webhook claim is consumed only after processing ---\n";
Bootstrap::initStoreContext('/', '', 'default');
$m7Adapters = new ReflectionProperty(Adapters::class, 'instances');
$m7SavedAdapters = $m7Adapters->getValue();
$m7Secret = 'm7-mock-webhook-secret-0123456789';
$m7Adapters->setValue(null, ['payment' => new MockPayment(['mock_webhook_secret' => $m7Secret])] + $m7SavedAdapters);
$m7Order = static function () use ($pdo): int {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'M7', 'm7@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'pending', 'pending', 'mock', '', ?, 'default', " . Db::now() . ")")
        ->execute(['M7-' . bin2hex(random_bytes(5)), json_encode(['provider' => 'mock', 'service_type' => 'online_card', 'requires_online' => true])]);
    return (int)$pdo->lastInsertId();
};
$m7Deliver = static function (int $orderId, string $eventId) use ($m7Secret): array {
    $payload = ['event_id' => $eventId] + MockPayment::paidPayload($orderId, 1000, 'mock_m7_' . $orderId);
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    return PaymentService::handleWebhook($raw, hash_hmac('sha256', $raw, $m7Secret), $payload, Adapters::payment(), 'mock');
};
$m7PaymentStatus = static fn(int $orderId): string => (string)(Orders::byId($orderId)['payment_status'] ?? '');
// The first delivery took the claim and then its process died before it changed the order.
$m7Crash = static function (string $eventId): void {
    Idempotency::webhookProcessed('payment_mock_' . $eventId);
};
$m7AgeClaim = static function (string $eventId) use ($pdo): void {
    try {
        $pdo->prepare('UPDATE processed_events SET claimed_at = ? WHERE event_id = ?')->execute([time() - Idempotency::LEASE_SECONDS - 60, 'payment_mock_' . $eventId]);
    } catch (Throwable $e) {
        // Schema without claim timestamps: the claim never ages, which is exactly the defect under test.
    }
};

try {
    $m7Stale = $m7Order();
    $m7Crash('m7_evt_stale');
    $m7AgeClaim('m7_evt_stale');
    $m7Retry = $m7Deliver($m7Stale, 'm7_evt_stale');
    assert_true(($m7Retry['handled'] ?? false) && empty($m7Retry['duplicate']), 'CR-M7: a redelivery after the lease processes an event whose first process died');
    assert_equals('paid', $m7PaymentStatus($m7Stale), 'CR-M7: the order is paid once the redelivered event is processed');
    $m7Again = $m7Deliver($m7Stale, 'm7_evt_stale');
    assert_true(!empty($m7Again['duplicate']), 'CR-M7: after successful processing a further delivery is a duplicate');

    $m7Busy = $m7Order();
    $m7Crash('m7_evt_busy');
    $m7InFlight = $m7Deliver($m7Busy, 'm7_evt_busy');
    assert_true(empty($m7InFlight['handled']) && empty($m7InFlight['duplicate']) && !empty($m7InFlight['retry']),
        'CR-M7: a redelivery while the first is still in flight is asked to retry, not acknowledged as processed');
    assert_equals('pending', $m7PaymentStatus($m7Busy), 'CR-M7: the in-flight duplicate changes nothing');
    assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM processed_events WHERE event_id = 'payment_mock_m7_evt_busy' AND state = 'processing'")->fetchColumn(),
        'CR-M7: the in-flight claim stays processing, not done');

    $m7Fresh = $m7Order();
    assert_true($m7Deliver($m7Fresh, 'm7_evt_fresh')['handled'] ?? false, 'CR-M7: a normal delivery is processed');
    assert_equals('done', (string)$pdo->query("SELECT state FROM processed_events WHERE event_id = 'payment_mock_m7_evt_fresh'")->fetchColumn(),
        'CR-M7: a processed event is marked done only after its handler finished');
} catch (Throwable $e) {
    assert_true(false, 'CR-M7: webhook claim scenario ran without error (' . $e->getMessage() . ')');
} finally {
    $m7Adapters->setValue(null, $m7SavedAdapters);
}
