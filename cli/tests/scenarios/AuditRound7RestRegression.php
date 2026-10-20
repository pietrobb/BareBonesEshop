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

// ── Several parcels: a delivered label delivers the order only once every other parcel is delivered too ──
$pdo->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, locale)
    VALUES ('R7R-MULTI', ?, 'shipped', 'paid', 'R7 Buyer', 'r7r-multi@example.test', 'bank_transfer', 1000, 1000, 'eur', 'en')")->execute([$r7rStore]);
$r7rMulti = (int)$pdo->query("SELECT id FROM orders WHERE order_number = 'R7R-MULTI'")->fetchColumn();
$pdo->prepare("INSERT INTO shipping_labels (order_id, store_id, carrier, awb, status, label_data) VALUES (?, ?, 'http-carrier', 'R7R-MLABEL', 'created', '')")
    ->execute([$r7rMulti, $r7rStore]);
// Parcel 1 is the label's own parcel (same tracking number), parcel 2 a second box.
foreach (['r7r-m1' => 'R7R-MLABEL', 'r7r-m2' => 'R7R-MPARCEL2'] as $r7rKey => $r7rTracking) {
    $pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, tracking_number, idempotency_key, shipped_at) VALUES (?, ?, 'shipped', 'HTTP Carrier', ?, ?, '2026-10-20 10:00:00')")
        ->execute([$r7rStore, $r7rMulti, $r7rTracking, $r7rKey]);
}
$r7rMultiStatus = static fn(): string => (string)$pdo->query("SELECT status FROM orders WHERE id = {$r7rMulti}")->fetchColumn();
$r7rFirst = $r7rHook('R7R-MLABEL', 'delivered', 'm1');
assert_equals(['shipped', false, 'delivered'], [$r7rMultiStatus(), $r7rFirst['transitioned'] ?? null,
    (string)$pdo->query("SELECT tracking_status FROM shipments WHERE idempotency_key = 'r7r-m1'")->fetchColumn()],
    'R7 multi-parcel: a delivered label keeps the order shipped while its second parcel is on the way (was delivered); the label\'s own shipment follows it');
$r7rHook('R7R-MPARCEL2', 'delivered', 'm2');
assert_equals('delivered', $r7rMultiStatus(), 'R7 multi-parcel: the last delivered parcel delivers the order');
$pdo->exec("DELETE FROM shipments WHERE order_id = {$r7rMulti}");
$pdo->exec("DELETE FROM shipping_labels WHERE order_id = {$r7rMulti}");
putenv('SHIP_HTTP_CARRIER_WEBHOOK_SECRET');

// ── HTTP carrier labels: cash on delivery is sent, a new label after cancelling is a new request ──
$r7rLabelCalls = [];
JsonHttpClient::setFixtureTransport(static function (string $method, string $url, string $body, array $headers) use (&$r7rLabelCalls): array {
    $path = (string)parse_url($url, PHP_URL_PATH);
    if ($path === '/v1/labels' && $method === 'POST') {
        $r7rLabelCalls[] = json_decode($body, true);
        return ['status' => 201, 'body' => ['awb' => 'R7RAWB' . count($r7rLabelCalls), 'label_data' => '']];
    }
    if (str_ends_with($path, '/cancel')) return ['status' => 200, 'body' => ['status' => 'cancelled']];
    return ['status' => 404, 'body' => ['error' => ['code' => 'fixture_not_found']]];
});
$r7rCarrier = static fn(bool $cod): HttpShipping => new HttpShipping(['integrations' => ['http_shipping' => [
    'base_url' => 'https://carrier.test', 'api_key' => str_repeat('c', 24), 'carrier_code' => 'http-carrier', 'supports_cod' => $cod]]]);
$r7rLabelOrder = static function (string $number, string $payment, string $paymentStatus) use ($pdo, $r7rStore): int {
    $pdo->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, locale)
        VALUES (?, ?, 'processing', ?, 'R7 Buyer', 'r7r-label@example.test', ?, 2590, 2590, 'eur', 'en')")->execute([$number, $r7rStore, $paymentStatus, $payment]);
    return (int)$pdo->lastInsertId();
};
assert_true(PaymentService::isCashOnDelivery('cod'), 'R7 COD label: fixture payment method cod is cash on delivery');
$r7rCodOrder = $r7rLabelOrder('R7R-COD', 'cod', 'pending');
$r7rPrepaidOrder = $r7rLabelOrder('R7R-PREPAID', 'bank_transfer', 'paid');
ShippingService::setAdapters(['http-carrier' => $r7rCarrier(true)]);
try {
    ShippingService::generateLabel($r7rCodOrder, 'http-carrier');
    ShippingService::generateLabel($r7rPrepaidOrder, 'http-carrier');
    assert_equals([['amount' => 2590, 'currency' => 'eur'], null], array_map(static fn(array $call) => array_key_exists('cod', $call['order'] ?? []) ? $call['order']['cod'] : 'missing', array_slice($r7rLabelCalls, 0, 2)),
        'R7 COD label: the carrier is told to collect 25.90 € on the COD parcel and nothing on the prepaid one (was not sent at all)');
    ShippingService::cancelLabel($r7rCodOrder, 'http-carrier');
    $r7rRelabel = ShippingService::generateLabel($r7rCodOrder, 'http-carrier');
    assert_equals(["order-{$r7rCodOrder}", "order-{$r7rCodOrder}-2", 'R7RAWB3'],
        [$r7rLabelCalls[0]['idempotency_key'] ?? null, $r7rLabelCalls[2]['idempotency_key'] ?? null, (string)($r7rRelabel['awb'] ?? '')],
        'R7 COD label: a label after cancelling uses a new idempotency key, so the carrier does not replay the cancelled AWB');
    ShippingService::setAdapters(['http-carrier' => $r7rCarrier(false)]);
    $r7rNoCodOrder = $r7rLabelOrder('R7R-NOCOD', 'cod', 'pending');
    $r7rRefused = '';
    try {
        ShippingService::generateLabel($r7rNoCodOrder, 'http-carrier');
    } catch (RuntimeException $e) {
        $r7rRefused = $e->getMessage();
    }
    assert_true(str_contains($r7rRefused, 'does not support cash on delivery') && count($r7rLabelCalls) === 3,
        "R7 COD label: a carrier without COD gets no label for a COD order (would ship it as prepaid) ({$r7rRefused})");
} finally {
    ShippingService::setAdapters([]);
    JsonHttpClient::setFixtureTransport(null);
}
$pdo->exec("DELETE FROM shipping_labels WHERE order_id IN (SELECT id FROM orders WHERE order_number IN ('R7R-COD', 'R7R-PREPAID', 'R7R-NOCOD'))");

// ── Variant surcharge below a price list: base 10.00 €, variant −8.00 €, a product-level price-list row 5.00 € ──
$pdo->exec('DELETE FROM products WHERE id = 97701');
$pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, name, price, stock, status)
    SELECT 97701, ?, id, 'R7R-VAR', 'r7r-var', 'R7R Variant', 1000, 10, 'active' FROM categories WHERE store_id = ? ORDER BY id LIMIT 1")
    ->execute([StoreContext::current()->catalogId(), StoreContext::current()->catalogId()]);
$pdo->prepare("INSERT INTO variants (product_id, sku, name, stock, price_absolute, price_delta) VALUES (97701, 'R7R-VAR-S', 'S', 5, NULL, -800)")->execute();
$r7rVariant = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO price_lists (store_id, code, name, currency, customer_group_id, priority, active) VALUES (?, 'r7r-list', 'R7R list', 'eur', NULL, 0, 1)")->execute([$r7rStore]);
$r7rList = (int)$pdo->lastInsertId();
$r7rSave = PricingAdmin::savePrices($r7rList, ['prices' => "R7R-VAR | 1 | 500"]);
$r7rRows = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM price_list_prices WHERE price_list_id = {$r7rList}")->fetchColumn();
assert_true(empty($r7rSave['ok']) && $r7rRows() === 0 && str_contains((string)($r7rSave['message'] ?? ''), '800'),
    'R7 variant price: a product price-list row below the variant floor (8.00 €) is refused (was saved, variant −3.00 €) (' . json_encode($r7rSave) . ')');
assert_true(!empty(PricingAdmin::savePrices($r7rList, ['prices' => "R7R-VAR | 1 | 800"])['ok']) && $r7rRows() === 1,
    'R7 variant price: a product price-list row at the floor is saved');
// Rows written before the check (or a variant saved afterwards) never price the variant below zero.
$pdo->exec("UPDATE price_list_prices SET price = 500 WHERE price_list_id = {$r7rList}");
$r7rPriced = Pricing::applyPriceList(['id' => 97701, 'price' => 1000, 'currency' => 'eur'], $r7rVariant);
assert_equals(0, (int)$r7rPriced['price'], 'R7 variant price: an old too-low price-list row prices the variant at 0, never negative (was −300)');
$r7rEdit = ProductVariants::validate(97701, [(string)$r7rVariant => ['sku' => 'R7R-VAR-S', 'name' => 'S', 'price_absolute' => '', 'price_delta' => '-600', 'stock' => '5', 'sort_order' => '0']]);
assert_true(is_string($r7rEdit), 'R7 variant price: the variant editor refuses a surcharge that the product price list would push below zero (5.00 − 6.00)');
$pdo->exec("DELETE FROM price_list_prices WHERE price_list_id = {$r7rList}");
$pdo->exec("DELETE FROM price_lists WHERE id = {$r7rList}");
$pdo->exec('DELETE FROM variants WHERE product_id = 97701');
$pdo->exec('DELETE FROM products WHERE id = 97701');


// ── An extension listener that opens its transaction with exec('BEGIN') (invisible to inTransaction() before PHP 8.4) ──
$r7rDb = Db::get();
$r7rListeners = new ReflectionProperty(Extensions::class, 'listeners');
$r7rBooted = new ReflectionProperty(Extensions::class, 'booted');
$r7rSavedListeners = $r7rListeners->getValue();
$r7rSavedBooted = $r7rBooted->getValue();
$r7rDb->beginTransaction();
$r7rEventId = Outbox::record(DomainEvents::ORDER_CREATED, 'order', 97801, ['order_id' => 97801, 'order_number' => 'R7R-EXT']);
$r7rDb->commit();
$r7rEvent = $r7rDb->query("SELECT * FROM event_outbox WHERE id = '{$r7rEventId}'")->fetch(PDO::FETCH_ASSOC);
try {
    $r7rBooted->setValue(null, true);
    $r7rListeners->setValue(null, [
        'ext:r7r:raw' => ['events' => DomainEvents::ORDER_CREATED, 'listener' => static function () use ($r7rDb): void {
            $r7rDb->exec('BEGIN');
            $r7rDb->exec("INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES ('default', 'r7r-leak', 'R7R leak', NULL, 'r7r-leak', 0)");
        }],
        'ext:r7r:after' => ['events' => DomainEvents::ORDER_CREATED, 'listener' => static function (): void {}],
    ]);
    $r7rResult = Extensions::deliver($r7rEvent, Outbox::envelope($r7rEvent));
    $r7rOpen = Db::transactionOpen($r7rDb);
    if ($r7rOpen) Db::rollBackOpen($r7rDb);
    assert_true(!$r7rOpen, "R7 extensions: a transaction a listener opened with exec('BEGIN') is not left open");
    assert_equals(0, (int)$r7rDb->query("SELECT COUNT(*) FROM categories WHERE slug = 'r7r-leak'")->fetchColumn(), "R7 extensions: the listener's uncommitted write is rolled back");
    assert_true(count($r7rResult['failures']) === 1 && str_contains($r7rResult['failures'][0], 'ext:r7r:raw') && str_contains($r7rResult['failures'][0], 'transaction'),
        'R7 extensions: the raw-BEGIN listener is a failed delivery naming the open transaction (' . json_encode($r7rResult['failures']) . ')');
} finally {
    if (Db::transactionOpen($r7rDb)) Db::rollBackOpen($r7rDb);
    $r7rDb->exec("DELETE FROM categories WHERE slug = 'r7r-leak'");
    $r7rListeners->setValue(null, $r7rSavedListeners);
    $r7rBooted->setValue(null, $r7rSavedBooted);
}


// ── Admin bulk actions on the product list record product.updated like a single edit (API subscribers saw nothing) ──
$pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, name, price, stock, status)
    SELECT ?, 'default', id, ?, ?, ?, 1000, 0, 'active' FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")
    ->execute([970701, 'R7R-BULK-A', 'r7r-bulk-a', 'R7R Bulk A']);
$pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, name, price, stock, status)
    SELECT ?, 'default', id, ?, ?, ?, 0, 0, 'archived' FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")
    ->execute([970702, 'R7R-BULK-B', 'r7r-bulk-b', 'R7R Bulk B']);
$r7rBulkEvents = static function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare("SELECT payload FROM event_outbox WHERE event_type = 'product.updated' AND aggregate_id = ? ORDER BY seq");
    $stmt->execute([(string)$id]);
    return array_map(static fn(string $json): array => json_decode($json, true), $stmt->fetchAll(PDO::FETCH_COLUMN));
};
ProductBulk::apply('price_up_10', [970701, 970702]);
$r7rBulkA = $r7rBulkEvents(970701);
assert_equals([1, 1100], [count($r7rBulkA), (int)($r7rBulkA[0]['price']['amount'] ?? 0)],
    'R7 bulk: a bulk price change records product.updated with the new price (was not recorded)');
assert_equals(0, count($r7rBulkEvents(970702)), 'R7 bulk: a product whose price the bulk action left unchanged (0 + 10 %) records nothing');
ProductBulk::apply('archive', [970701, 970702]);
$r7rBulkA = $r7rBulkEvents(970701);
assert_equals([2, 'archived'], [count($r7rBulkA), $r7rBulkA[1]['status'] ?? null],
    'R7 bulk: a bulk archive records product.updated with the new status (was not recorded)');
assert_equals(0, count($r7rBulkEvents(970702)), 'R7 bulk: an already archived product records nothing');
$pdo->exec('DELETE FROM products WHERE id IN (970701, 970702)');
