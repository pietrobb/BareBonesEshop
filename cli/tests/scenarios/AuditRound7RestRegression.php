<?php

// Round 7 review (2026-10-20), remaining medium/low findings and the round-6 findings still open.
echo "\n--- Audit round 7: remaining findings ---\n";

$r7rStore = StoreContext::current()->id();

// ── Carrier webhooks: after a failed delivery attempt the parcel goes back to the depot (failed → in_transit) ──
$r7rSecret = 'r7r-carrier-secret-0123456789';
putenv('SHIP_HTTP_CARRIER_WEBHOOK_SECRET=' . $r7rSecret);
$r7rHook = static function (string $awb, string $status, string $nonce) use ($r7rSecret): array {
    $raw = json_encode(['awb' => $awb, 'status' => $status, 'nonce' => $nonce]);
    return ShippingService::handleTrackingWebhook('http-carrier', json_decode($raw, true), hash_hmac('sha256', $raw, $r7rSecret), $raw);
};
$pdo->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, locale)
    VALUES ('R7R-TRACK', ?, 'shipped', 'paid', 'R7 Buyer', 'r7r-track@example.test', 'bank_transfer', 1000, 1000, 'eur', 'en')")->execute([$r7rStore]);
$r7rTrackOrder = (int)$pdo->query("SELECT id FROM orders WHERE order_number = 'R7R-TRACK'")->fetchColumn();
$pdo->prepare("INSERT INTO shipping_labels (order_id, store_id, carrier, awb, status, label_data) VALUES (?, ?, 'http-carrier', 'R7R-LABEL', 'created', '')")
    ->execute([$r7rTrackOrder, $r7rStore]);
$pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, tracking_number, idempotency_key, shipped_at) VALUES (?, ?, 'shipped', 'HTTP Carrier', 'R7R-PARCEL', 'r7r-parcel', '2026-10-20 10:00:00')")
    ->execute([$r7rStore, $r7rTrackOrder]);
$r7rLabel = static fn(): string => (string)$pdo->query("SELECT status FROM shipping_labels WHERE awb = 'R7R-LABEL'")->fetchColumn();
$r7rParcel = static fn(): string => (string)$pdo->query("SELECT tracking_status FROM shipments WHERE idempotency_key = 'r7r-parcel'")->fetchColumn();
foreach (['label' => ['R7R-LABEL', $r7rLabel], 'admin shipment' => ['R7R-PARCEL', $r7rParcel]] as $r7rKind => [$r7rAwb, $r7rRead]) {
    $r7rHook($r7rAwb, 'out_for_delivery', 'a');
    $r7rHook($r7rAwb, 'failed', 'b');
    $r7rBack = $r7rHook($r7rAwb, 'in_transit', 'c');
    assert_equals([true, 'in_transit'], [($r7rBack['handled'] ?? false) === true, $r7rRead()],
        "R7 tracking ({$r7rKind}): in_transit after a failed delivery attempt is applied (was stale_status, stuck on failed)");
    $r7rHook($r7rAwb, 'delivered', 'd');
    assert_equals(['stale_status', 'delivered'], [$r7rHook($r7rAwb, 'in_transit', 'e')['reason'] ?? null, $r7rRead()],
        "R7 tracking ({$r7rKind}): a late in_transit still cannot undo delivered");
}
$pdo->exec("DELETE FROM shipping_labels WHERE awb = 'R7R-LABEL'");
putenv('SHIP_HTTP_CARRIER_WEBHOOK_SECRET');
