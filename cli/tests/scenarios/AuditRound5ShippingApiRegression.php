<?php

// Round 5 review (2026-10-18), shipping, API and extensions: carrier webhooks find the shipments the admin
// really ships, one broken carrier or storefront extension never takes checkout or the product page down,
// outgoing webhooks retry for days and a dead event can be queued again, events carry their order, and
// PATCH /products supports ETag / If-Match.
echo "\n--- Audit round 5: shipping webhooks, broken carriers/extensions, webhook retry, event order, ETag ---\n";

$r5sRoot = test_fixture_path('r5-shipping-api');
mkdir($r5sRoot, 0700, true);
JobQueue::setDir($r5sRoot . '/jobs');
Bootstrap::initStoreContext('/', '', 'default');
$r5sStore = StoreContext::current()->id();
$r5sThrows = static function (callable $fn): ?Throwable {
    try { $fn(); } catch (Throwable $e) { return $e; }
    return null;
};

// ── 1. Carrier webhooks resolve the shipments shipped in the admin (no more unknown_awb) ─────────
$r5sSecret = 'r5-carrier-secret-0123456789';
putenv('SHIP_PACKETA_WEBHOOK_SECRET=' . $r5sSecret);
putenv('SHIP_GLS_WEBHOOK_SECRET=' . $r5sSecret);
$r5sHook = static function (string $carrier, string $awb, string $status, string $nonce = '') use ($r5sSecret, $r5sThrows): array {
    $raw = json_encode(['awb' => $awb, 'status' => $status, 'nonce' => $nonce]);
    $result = null;
    $error = $r5sThrows(static function () use ($carrier, $raw, $r5sSecret, &$result): void {
        $result = ShippingService::handleTrackingWebhook($carrier, json_decode($raw, true), hash_hmac('sha256', $raw, $r5sSecret), $raw);
    });
    return $error ? ['error' => get_class($error) . ': ' . $error->getMessage()] : $result;
};
$r5sOrder = static function (string $number) use ($pdo, $r5sStore): int {
    $pdo->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, locale)
        VALUES (?, ?, 'shipped', 'paid', 'R5 Buyer', 'r5-ship@example.test', 'bank_transfer', 1000, 1000, 'eur', 'en')")->execute([$number, $r5sStore]);
    return (int)$pdo->query("SELECT id FROM orders WHERE order_number = " . $pdo->quote($number))->fetchColumn();
};
$r5sShipment = static function (int $orderId, ?string $carrier, ?string $tracking, string $key, string $status = 'shipped') use ($pdo, $r5sStore): void {
    $pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, tracking_number, idempotency_key, shipped_at) VALUES (?, ?, ?, ?, ?, ?, '2026-10-18 10:00:00')")
        ->execute([$r5sStore, $orderId, $status, $carrier, $tracking, $key]);
};
$r5sOrderStatus = static fn(int $id): string => (string)$pdo->query("SELECT status FROM orders WHERE id = {$id}")->fetchColumn();
$r5sShipmentRow = static function (string $key) use ($pdo): array {
    $row = $pdo->query("SELECT * FROM shipments WHERE idempotency_key = " . $pdo->quote($key))->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
};

$r5sOne = $r5sOrder('R5S-ONE');
// The admin types the carrier name and the tracking number as printed on the label.
$r5sShipment($r5sOne, 'Packeta', 'Z 123 4567 890', 'r5s-one');
$r5sResult = $r5sHook('packeta', 'Z1234567890', 'in_transit', 'a');
assert_true(($r5sResult['handled'] ?? false) === true, 'R5 shipping webhook: an admin shipment (carrier "Packeta", tracking with spaces) is found, not unknown_awb (' . json_encode($r5sResult) . ')');
assert_equals('in_transit', $r5sShipmentRow('r5s-one')['tracking_status'] ?? null, 'R5 shipping webhook: the carrier status is stored on the shipment');
assert_equals('shipped', $r5sOrderStatus($r5sOne), 'R5 shipping webhook: in_transit does not change the order');
$r5sResult = $r5sHook('packeta', 'Z1234567890', 'delivered', 'b');
assert_true(($r5sResult['handled'] ?? false) === true && ($r5sResult['transitioned'] ?? false) === true, 'R5 shipping webhook: delivered is applied (' . json_encode($r5sResult) . ')');
assert_equals('delivered', $r5sOrderStatus($r5sOne), 'R5 shipping webhook: the order of a delivered single shipment becomes delivered');
assert_true(($r5sShipmentRow('r5s-one')['delivered_at'] ?? null) !== null, 'R5 shipping webhook: the shipment records when it was delivered');
assert_equals('already_processed', $r5sHook('packeta', 'Z1234567890', 'delivered', 'b')['reason'] ?? null, 'R5 shipping webhook: a replayed event is processed once');
assert_equals('stale_status', $r5sHook('packeta', 'Z1234567890', 'in_transit', 'c')['reason'] ?? null, 'R5 shipping webhook: the shipment status never moves backwards');

$r5sTwo = $r5sOrder('R5S-TWO');
$r5sShipment($r5sTwo, 'GLS', 'R5S-GLS-1', 'r5s-two-a');
$r5sShipment($r5sTwo, 'gls', 'r5s-gls-2', 'r5s-two-b');
$r5sResult = $r5sHook('gls', 'R5S-GLS-1', 'delivered', 'a');
assert_true(($r5sResult['handled'] ?? false) === true && empty($r5sResult['transitioned']), 'R5 shipping webhook: one of two parcels delivered is recorded (' . json_encode($r5sResult) . ')');
assert_equals('shipped', $r5sOrderStatus($r5sTwo), 'R5 shipping webhook: the order stays shipped while another parcel is on the way');
$r5sResult = $r5sHook('gls', 'R5S-GLS-2', 'delivered', 'b');
assert_true(($r5sResult['transitioned'] ?? false) === true && $r5sOrderStatus($r5sTwo) === 'delivered', 'R5 shipping webhook: the last delivered parcel delivers the order (tracking number case-insensitive)');

$r5sThree = $r5sOrder('R5S-THREE');
$r5sShipment($r5sThree, 'Packeta', 'R5S-PK-3', 'r5s-three');
$r5sShipment($r5sThree, null, null, 'r5s-three-open', 'planned');
$r5sResult = $r5sHook('packeta', 'R5S-PK-3', 'delivered', 'a');
assert_true(($r5sResult['handled'] ?? false) === true && empty($r5sResult['transitioned']) && $r5sOrderStatus($r5sThree) === 'shipped',
    'R5 shipping webhook: an order with goods still waiting for a shipment is not marked delivered (' . json_encode($r5sResult) . ')');
assert_equals('unknown_awb', $r5sHook('gls', 'Z1234567890', 'delivered', 'x')['reason'] ?? null, 'R5 shipping webhook: the tracking number of another carrier is not matched');
assert_equals('unknown_awb', $r5sHook('packeta', 'R5S-NOPE', 'in_transit', 'x')['reason'] ?? null, 'R5 shipping webhook: an unknown tracking number stays unknown_awb');

// The admin form leaves the carrier empty or gets the carrier's local brand name; the order's shipping
// method knows the adapter (capability review run 27).
$pdo->prepare("INSERT INTO shipping_methods (store_id, code, carrier, name) VALUES (?, 'r5s-zasielkovna', 'packeta', 'R5 Zásielkovňa')")
    ->execute([StoreContext::current()->providerId()]);
foreach (['r5s-empty' => [null, 'Z 222 3333 444'], 'r5s-brand' => ['Zásielkovňa', 'Z 222 3333 555']] as $r5sKey => [$r5sCarrier, $r5sTracking]) {
    $r5sId = $r5sOrder(strtoupper($r5sKey));
    $pdo->prepare("UPDATE orders SET shipping_method = 'r5s-zasielkovna' WHERE id = ?")->execute([$r5sId]);
    $r5sShipment($r5sId, $r5sCarrier, $r5sTracking, $r5sKey);
    $r5sResult = $r5sHook('packeta', str_replace(' ', '', $r5sTracking), 'in_transit', $r5sKey);
    assert_true(($r5sResult['handled'] ?? false) === true && ($r5sShipmentRow($r5sKey)['tracking_status'] ?? null) === 'in_transit',
        'R5 shipping webhook: a shipment with carrier ' . json_encode($r5sCarrier, JSON_UNESCAPED_UNICODE) . ' on an order shipped by the packeta method is found (' . json_encode($r5sResult) . ')');
}
$r5sResult = $r5sHook('gls', 'Z2223333444', 'in_transit', 'r5s-gls');
assert_equals('unknown_awb', $r5sResult['reason'] ?? null, "R5 shipping webhook: another carrier's webhook does not take a shipment of the order's packeta method");
// Round 6: the order's method is packeta but the admin shipped it with GLS. Packeta's webhook for that tracking
// number must not deliver GLS's parcel; GLS's own webhook and a "Packeta SK" spelling still match.
foreach (['r6s-gls' => ['GLS', 'R6S 100 200 300', 'packeta', false], 'r6s-glssk' => ['GLS Slovakia', 'R6S 100 200 301', 'packeta', false],
    'r6s-glsown' => ['GLS Slovakia', 'R6S 100 200 302', 'gls', true], 'r6s-pksk' => ['Packeta SK', 'R6S 100 200 303', 'packeta', true],
    // Round 7: a carrier that is not a registered adapter (DPD) is still another carrier; a name from the
    // shipping method's own name ("R5 Zásielkovňa") or a lowercase brand spelling is still Packeta.
    'r7s-dpd' => ['DPD', 'R7S 100 200 304', 'packeta', false], 'r7s-dpdsk' => ['DPD Slovakia', 'R7S 100 200 305', 'packeta', false],
    'r7s-zasilkovna' => ['zásilkovna', 'R7S 100 200 306', 'packeta', true], 'r7s-method' => ['R5 Zásielkovňa', 'R7S 100 200 307', 'packeta', true]] as $r6sKey => [$r6sCarrier, $r6sTracking, $r6sFrom, $r6sFound]) {
    $r6sId = $r5sOrder(strtoupper($r6sKey));
    $pdo->prepare("UPDATE orders SET shipping_method = 'r5s-zasielkovna' WHERE id = ?")->execute([$r6sId]);
    $r5sShipment($r6sId, $r6sCarrier, $r6sTracking, $r6sKey);
    $r6sResult = $r5sHook($r6sFrom, str_replace(' ', '', $r6sTracking), 'delivered', $r6sKey);
    assert_equals($r6sFound ? 'delivered' : null, $r5sShipmentRow($r6sKey)['tracking_status'] ?? null,
        "R6 shipping webhook: {$r6sFrom}'s webhook " . ($r6sFound ? 'updates' : 'does not touch') . " a {$r6sCarrier} shipment on a packeta-method order (" . json_encode($r6sResult) . ')');
}
putenv('SHIP_PACKETA_WEBHOOK_SECRET');
putenv('SHIP_GLS_WEBHOOK_SECRET');

// ── 2. A carrier whose constructor fails is skipped; a broken storefront extension is skipped ─────
$r5sExt = $r5sRoot . '/extensions';
mkdir($r5sExt . '/r5-broken-carrier/src', 0700, true);
file_put_contents($r5sExt . '/r5-broken-carrier/extension.php', "<?php\nreturn ['code' => 'r5-broken-carrier', 'name' => 'R5 broken carrier', 'version' => '1.0.0',\n"
    . "    'register' => static function (ExtensionApi \$api): void { \$api->provider('shipping', 'r5-broken', R5BrokenCarrier::class, ['rates']); },\n];\n");
file_put_contents($r5sExt . '/r5-broken-carrier/src/R5BrokenCarrier.php', "<?php\nfinal class R5BrokenCarrier implements ShippingAdapter {\n"
    . "    public function __construct() { throw new RuntimeException('R5 carrier API key missing'); }\n"
    . "    public function getRates(array \$params): array { return []; }\n    public function generateLabel(array \$order): array { return []; }\n"
    . "    public function trackShipment(string \$awb): array { return []; }\n    public function supportsCOD(): bool { return false; }\n"
    . "    public function carrierId(): string { return 'r5-broken'; }\n}\n");
try {
    Extensions::reset();
    Extensions::boot(['extensions' => ['r5-broken-carrier']], $r5sExt);
    ShippingService::setAdapters([]);
    $r5sRates = null;
    $r5sError = $r5sThrows(static function () use (&$r5sRates): void { $r5sRates = ShippingService::calculateRates('IS', 1000, 2000); });
    assert_true($r5sError === null, 'R5 carriers: a carrier whose constructor throws does not take the rates (checkout) down (' . ($r5sError ? $r5sError->getMessage() : 'ok') . ')');
    assert_true(in_array('pickup', array_column($r5sRates ?? [], 'carrier'), true), 'R5 carriers: the working carriers are still offered');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'shipping.adapter_unavailable'")->fetchColumn() >= 1,
        'R5 carriers: the broken carrier raises an incident for the merchant');
} finally {
    Extensions::reset();
    ShippingService::setAdapters([]);
}

StorefrontViewModelRegistry::register('r5-probe', 'r5-good.data', static fn(array $context): array => ['ok' => true]);
StorefrontViewModelRegistry::register('r5-probe', 'r5-throws.data', static function (array $context): array { throw new RuntimeException('R5 view-model failure'); });
StorefrontViewModelRegistry::register('r5-probe', 'r5-wrong.data', static fn(array $context): string => 'not an array');
$r5sModel = null;
$r5sError = $r5sThrows(static function () use (&$r5sModel): void { $r5sModel = StorefrontViewModelRegistry::extensions('r5-probe', []); });
assert_true($r5sError === null, 'R5 storefront: a failing extension view-model does not turn the page into a 500 (' . ($r5sError ? $r5sError->getMessage() : 'ok') . ')');
assert_equals(['r5-good' => ['data' => ['ok' => true]]], $r5sModel, 'R5 storefront: only the failing extensions are left out, the others still render');

// ── 3. Outgoing webhooks retry for days and a dead event can be queued again ────────────────────
$r5sDelays = method_exists('Outbox', 'retryDelay') ? array_map([Outbox::class, 'retryDelay'], range(1, Outbox::MAX_ATTEMPTS - 1)) : [];
assert_true(array_sum($r5sDelays) >= 3 * 86400, 'R5 outbox: retries span at least three days before an event is dead (' . array_sum($r5sDelays) . ' s)');
assert_true($r5sDelays !== [] && $r5sDelays[0] <= 60 && max($r5sDelays) <= 6 * 3600, 'R5 outbox: the first retry is quick and no gap exceeds six hours');
$pdo->beginTransaction();
$r5sDead = Outbox::record('order.created', 'order', 995001, ['n' => 1]);
$r5sDone = Outbox::record('order.created', 'order', 995002, ['n' => 2]);
$pdo->commit();
$pdo->exec("UPDATE event_outbox SET status = 'dead', attempts = " . Outbox::MAX_ATTEMPTS . " WHERE id = " . $pdo->quote($r5sDead));
$pdo->exec("UPDATE event_outbox SET status = 'delivered' WHERE id = " . $pdo->quote($r5sDone));
$r5sRequeue = method_exists('Outbox', 'requeue') ? [Outbox::requeue($r5sDead), Outbox::requeue($r5sDone)] : null;
$r5sDeadRow = $pdo->query("SELECT status, attempts FROM event_outbox WHERE id = " . $pdo->quote($r5sDead))->fetch(PDO::FETCH_ASSOC);
assert_equals([true, false], $r5sRequeue, 'R5 outbox: a dead event can be queued again, a delivered one cannot');
assert_equals(['status' => 'retry', 'attempts' => 0], ['status' => $r5sDeadRow['status'] ?? null, 'attempts' => (int)($r5sDeadRow['attempts'] ?? -1)], 'R5 outbox: the requeued event gets a fresh set of attempts');
assert_true(is_file(dirname(TEST_CLI_DIR) . '/cli/outbox-requeue.php') && str_contains((string)@file_get_contents(dirname(TEST_CLI_DIR) . '/cli/outbox-requeue.php'), 'Outbox::requeue('),
    'R5 outbox: cli/outbox-requeue.php queues dead events again');
$pdo->exec("UPDATE event_outbox SET status = 'dead' WHERE id IN (" . $pdo->quote($r5sDead) . ', ' . $pdo->quote($r5sDone) . ')');

// ── 4. Events carry their order ─────────────────────────────────────────────────────────────────
$pdo->beginTransaction();
$r5sFirst = Outbox::record('order.created', 'order', 995010, ['n' => 1]);
$r5sSecond = Outbox::record('order.paid', 'order', 995010, ['n' => 2]);
$pdo->commit();
$r5sEnvelope = static function (string $id) use ($pdo): array {
    return Outbox::envelope($pdo->query("SELECT * FROM event_outbox WHERE id = " . $pdo->quote($id))->fetch(PDO::FETCH_ASSOC));
};
$r5sSeq = [$r5sEnvelope($r5sFirst)['sequence'] ?? null, $r5sEnvelope($r5sSecond)['sequence'] ?? null];
assert_true(is_int($r5sSeq[0]) && is_int($r5sSeq[1]) && $r5sSeq[1] > $r5sSeq[0], 'R5 events: the envelope carries a sequence, later events of an order have a higher one (' . json_encode($r5sSeq) . ')');
$pdo->exec("DELETE FROM event_outbox WHERE id IN (" . $pdo->quote($r5sFirst) . ', ' . $pdo->quote($r5sSecond) . ')');
$pdo->beginTransaction();
$r5sThird = Outbox::record('order.shipped', 'order', 995010, ['n' => 3]);
$pdo->commit();
assert_true(($r5sEnvelope($r5sThird)['sequence'] ?? 0) > (int)$r5sSeq[1], 'R5 events: the sequence keeps growing after retention deleted older events');
$pdo->exec("UPDATE event_outbox SET status = 'dead' WHERE id = " . $pdo->quote($r5sThird));
assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/docs/openapi.yaml'), 'sequence:'), 'R5 events: the OpenAPI event envelope documents the sequence');

// ── 5. PATCH /products: ETag and If-Match ───────────────────────────────────────────────────────
$pdo->prepare("INSERT INTO categories (store_id, slug, name, path) VALUES (?, 'r5s-etag', 'R5 etag', 'r5s-etag')")->execute([StoreContext::current()->catalogId()]);
$r5sCategory = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (sku, name, slug, category_id, price, stock, status, store_id) VALUES ('R5S-ETAG', 'R5 etag', 'r5s-etag', ?, 1000, 0, 'active', ?)")
    ->execute([$r5sCategory, StoreContext::current()->catalogId()]);
$r5sProduct = (int)$pdo->lastInsertId();
$r5sClient = ApiAuth::authenticate('Bearer ' . ApiAuth::issueClient('R5 etag', $r5sStore, ['products:read', 'products:write'])['token']);
$r5sApi = static function (string $method, array $body, array $headers) use ($r5sProduct, $r5sClient): array {
    try {
        return ApiV1::dispatch($method, "/api/v1/products/{$r5sProduct}", [], $body, $headers, $r5sClient);
    } catch (Throwable $e) {
        return ApiV1::errorResponse($e);
    }
};
$r5sGet = $r5sApi('GET', [], []);
$r5sEtag = (string)($r5sGet['headers']['ETag'] ?? '');
assert_true(preg_match('/^"[0-9a-f]{16,64}"$/', $r5sEtag) === 1, 'R5 ETag: GET /products/{id} returns a strong ETag (' . json_encode($r5sGet['headers'] ?? null) . ')');
$r5sStale = $r5sApi('PATCH', ['name' => 'R5 etag stale'], ['Idempotency-Key' => 'r5s-etag-stale-0001', 'If-Match' => '"0000000000000000"']);
assert_equals([412, 'precondition_failed'], [$r5sStale['status'], $r5sStale['body']['error']['code'] ?? null], 'R5 ETag: PATCH with an outdated If-Match is refused with 412');
assert_equals('R5 etag', (string)$pdo->query("SELECT name FROM products WHERE id = {$r5sProduct}")->fetchColumn(), 'R5 ETag: the refused PATCH changes nothing');
$r5sOk = $r5sApi('PATCH', ['name' => 'R5 etag renamed'], ['Idempotency-Key' => 'r5s-etag-ok-0001', 'If-Match' => $r5sEtag]);
$r5sNewEtag = (string)($r5sOk['headers']['ETag'] ?? '');
assert_true($r5sOk['status'] === 200 && $r5sNewEtag !== '' && $r5sNewEtag !== $r5sEtag, 'R5 ETag: PATCH with the current If-Match applies and returns the new ETag');
assert_equals(412, $r5sApi('PATCH', ['name' => 'R5 etag lost update'], ['Idempotency-Key' => 'r5s-etag-lost-0001', 'If-Match' => $r5sEtag])['status'],
    'R5 ETag: a second writer with the old ETag cannot overwrite the change (lost update)');
assert_equals(200, $r5sApi('PATCH', ['name' => 'R5 etag no header'], ['Idempotency-Key' => 'r5s-etag-none-0001'])['status'], 'R5 ETag: If-Match stays optional for existing clients');
$r5sExternal = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/ApiExternal.php');
assert_true(str_contains($r5sExternal, "\$result['headers']") && str_contains($r5sExternal, 'HTTP_IF_MATCH'), 'R5 ETag: the HTTP entry point sends the ETag header and passes If-Match on');
$pdo->exec("DELETE FROM products WHERE id = {$r5sProduct}");
$pdo->exec("DELETE FROM categories WHERE id = {$r5sCategory}");
