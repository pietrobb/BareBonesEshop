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


// ── HTTPS behind a TLS-terminating proxy: HSTS, secure cookies and the form-action origin see https ──
$r7rCfgProp = new ReflectionProperty('Security', 'cfg');
$r7rSavedCfg = $r7rCfgProp->getValue();
$r7rSavedServer = $_SERVER;
try {
    $r7rCfgProp->setValue(null, ['trusted_proxies' => ['10.0.0.1']]);
    $r7rHttps = static function (array $server): bool {
        $_SERVER = $server + ['HTTP_HOST' => 'shop.example'];
        return Security::requestIsHttps();
    };
    assert_equals(
        [true, false, true, false, false, true],
        [
            $r7rHttps(['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.9']),
            $r7rHttps(['HTTPS' => 'off', 'REMOTE_ADDR' => '203.0.113.9']),
            $r7rHttps(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https']),
            $r7rHttps(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']),
            $r7rHttps(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https, http']),
            $r7rHttps(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'HTTPS']),
        ],
        'R7 HSTS: https is direct HTTPS or X-Forwarded-Proto from a trusted proxy (its own, last value); a client cannot claim it (was only $_SERVER[HTTPS])'
    );
    $_SERVER = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'shop.example'];
    assert_true(Security::formActionAllows('https://shop.example/?page=order-done'),
        'R7 HSTS: behind the proxy a redirect to the shop\'s own https address passes form-action');
} finally {
    $_SERVER = $r7rSavedServer;
    $r7rCfgProp->setValue(null, $r7rSavedCfg);
}
$r7rSecuritySource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Security.php');
assert_true((bool)preg_match('/if \(self::requestIsHttps\(\) \|\| [^\n]*app_url[^\n]*\{\s*header\("Strict-Transport-Security/', $r7rSecuritySource),
    'R7 HSTS: the header is sent on proxied https or an https app_url');


// ── Rate-limit storage failure (read-only disk): requests are allowed and one critical incident is raised (was a 500) ──
$r7rRateFile = sys_get_temp_dir() . '/r7r-rate-' . bin2hex(random_bytes(4)) . '.sqlite';
(new PDO('sqlite:' . $r7rRateFile))->exec('CREATE TABLE hits (key TEXT NOT NULL, ts INTEGER NOT NULL); CREATE INDEX idx_hits ON hits(key, ts)');
$r7rRateIncidents = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'security.rate_limit_storage_failed'")->fetchColumn();
$r7rRateBefore = $r7rRateIncidents();
try {
    Security::setRateDbForTesting(new PDO('sqlite:' . $r7rRateFile, null, null, [constant(class_exists('Pdo\Sqlite') ? 'Pdo\Sqlite::ATTR_OPEN_FLAGS' : 'PDO::SQLITE_ATTR_OPEN_FLAGS') => constant(class_exists('Pdo\Sqlite') ? 'Pdo\Sqlite::OPEN_READONLY' : 'PDO::SQLITE_OPEN_READONLY')]));
    $r7rRateErrorLog = ini_set('error_log', sys_get_temp_dir() . '/r7r-rate-error.log');
    try {
        $r7rRateResults = [Security::rateLimit('r7r_rate', 5), Security::rateLimitAllows('r7r_rate', 5, 60), Security::rateLimit('r7r_rate', 5)];
        Security::rateLimitRecord('r7r_rate');
    } finally {
        ini_set('error_log', (string)$r7rRateErrorLog);
    }
    assert_equals([true, true, true], $r7rRateResults, 'R7 rate limit: a read-only limiter store lets requests through (was an uncaught PDOException, HTTP 500)');
    assert_equals(1, $r7rRateIncidents() - $r7rRateBefore, 'R7 rate limit: the storage failure is one critical incident per request, not one per check');
} finally {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    @unlink($r7rRateFile);
}

// R7 medium: the Google feed read products.availability as if it held backorder/preorder (those live in
// inventory_policy) and ignored discontinued/disabled, so it advertised unsellable products and hid sellable ones.
echo "\n--- R7: Google feed availability follows the storefront ---\n";
$r7rCatalog = StoreContext::current()->catalogId();
$r7rFeedRows = [
    // id, sku, stock, availability, inventory_policy, product_type, available_from, expected
    [97720, 'R7R-FEED-DISC', 5, 'discontinued', 'deny', 'simple', null, 'out of stock'],
    [97721, 'R7R-FEED-BACK', 0, 'active', 'backorder', 'simple', null, 'backorder'],
    [97722, 'R7R-FEED-PRE', 0, 'active', 'preorder', 'simple', '2099-03-01 00:00:00', 'preorder'],
    [97723, 'R7R-FEED-DIGI', 0, 'active', 'deny', 'digital', null, 'in stock'],
    [97724, 'R7R-FEED-STOCK', 3, 'active', 'deny', 'simple', null, 'in stock'],
    [97725, 'R7R-FEED-SOON', 4, 'active', 'deny', 'simple', '2099-03-01 00:00:00', 'out of stock'],
];
$pdo->exec('DELETE FROM products WHERE id BETWEEN 97720 AND 97725');
foreach ($r7rFeedRows as [$id, $sku, $stock, $state, $policy, $type, $from]) {
    $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, name, price, stock, status, availability, inventory_policy, product_type, available_from)
        SELECT ?, ?, id, ?, ?, ?, 1000, ?, 'active', ?, ?, ?, ? FROM categories WHERE store_id = ? ORDER BY id LIMIT 1")
        ->execute([$id, $r7rCatalog, $sku, strtolower($sku), $sku, $stock, $state, $policy, $type, $from, $r7rCatalog]);
}
$r7rFeed = MarketingFeed::google('https://shop.example');
$r7rFeedItem = static function (string $sku) use ($r7rFeed): string {
    foreach (explode('<item>', $r7rFeed) as $item) if (str_contains($item, '<g:id>' . $sku . '</g:id>')) return $item;
    return '';
};
foreach ($r7rFeedRows as [$id, $sku, , $state, $policy, $type, , $expected]) {
    preg_match('#<g:availability>([^<]*)</g:availability>#', $r7rFeedItem($sku), $r7rMatch);
    assert_equals($expected, $r7rMatch[1] ?? null, "R7 Google feed: {$sku} ({$state}, {$policy}, {$type}) is '{$expected}'");
}
assert_true(str_contains($r7rFeedItem('R7R-FEED-PRE'), '<g:availability_date>2099-03-01T00:00:00+00:00</g:availability_date>'),
    'R7 Google feed: a preorder carries its release date');
$pdo->exec('DELETE FROM products WHERE id BETWEEN 97720 AND 97725');

// R7 medium (Omnibus, 98/6/EC art. 6a): the struck-through "original" price came from compare_price, which can be any
// number; the prior price of a reduction must be the lowest price of the 30 days before it.
echo "\n--- R7: Omnibus prior price ---\n";
$r7rAgo = static fn(int $days): string => gmdate('Y-m-d H:i:s', time() - $days * 86400);
$r7rOmni = static function (int $id, int $price, ?int $compare, array $history) use ($pdo, $r7rCatalog): void {
    $pdo->exec("DELETE FROM product_price_history WHERE product_id = {$id}");
    $pdo->exec("DELETE FROM products WHERE id = {$id}");
    $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, name, price, compare_price, stock, status)
        SELECT ?, ?, id, ?, ?, ?, ?, ?, 5, 'active' FROM categories WHERE store_id = ? ORDER BY id LIMIT 1")
        ->execute([$id, $r7rCatalog, "R7R-OMNI-{$id}", "r7r-omni-{$id}", "R7R Omni {$id}", $history === [] ? $price : $history[0][0], $compare, $r7rCatalog]);
    foreach ($history as [$historicPrice, $at]) {
        $pdo->prepare('INSERT INTO product_price_history (product_id, price, lowest_prior, changed_at) VALUES (?, ?, NULL, ?)')->execute([$id, $historicPrice, $at]);
    }
    $pdo->prepare('UPDATE products SET price = ? WHERE id = ?')->execute([$price, $id]);
    PriceHistory::record([$id]); // the write path's call (admin save, API, import, feed, bulk)
};
$r7rShown = static function (int $id) use ($pdo): ?int {
    $row = $pdo->query("SELECT * FROM products WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
    return StorefrontViewModel::productCard($row)['price']['compare_amount_minor'];
};
// A new product "reduced" from an invented 199.00: it never had another price.
$r7rOmni(97730, 9900, 19900, []);
assert_equals(null, $r7rShown(97730), 'R7 Omnibus: a product that never had another price shows no struck-through price (was 199.00)');
// 120.00 for months, reduced to 99.00 today: the prior price is 120.00, also when compare_price claims 150.00.
$r7rOmni(97731, 9900, 15000, [[12000, $r7rAgo(90)]]);
assert_equals(12000, $r7rShown(97731), 'R7 Omnibus: a real reduction shows the price of the 30 days before it, capped from the claimed 150.00');
// Raised to 120.00 ten days ago from 99.00 and "reduced" back: the lowest price of the 30 days before is 99.00.
$r7rOmni(97732, 9900, 12000, [[9900, $r7rAgo(90)], [12000, $r7rAgo(10)]]);
assert_equals(null, $r7rShown(97732), 'R7 Omnibus: raising the price and "reducing" it back shows no struck-through price (was 120.00)');
// The admin bulk price action records the history too: 100.00 for months, −20 % today.
$r7rOmni(97733, 10000, 10000, [[10000, $r7rAgo(90)]]);
ProductBulk::apply('price_down_20', [97733]);
assert_equals(10000, $r7rShown(97733), 'R7 Omnibus: a bulk −20 % reduction shows the previous 100.00');
assert_equals([10000, 8000], array_map('intval', $pdo->query('SELECT price FROM product_price_history WHERE product_id = 97733 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)),
    'R7 Omnibus: the bulk price action recorded the new price');
// A promotion or price list below the base price: the prior price is the base, or a lower price of the last 30 days.
$r7rLatest = static fn(int $price, ?int $lowest, int $days): array => ['price' => $price, 'lowest_prior' => $lowest, 'changed_at' => $r7rAgo($days)];
assert_equals(10000, PriceHistory::capCompare(['price' => 8000, 'compare_price' => 10000], 10000, $r7rLatest(10000, 9000, 90))['compare_price'],
    'R7 Omnibus: a promotion on a long-stable price shows the base price');
assert_equals(9000, PriceHistory::capCompare(['price' => 8000, 'compare_price' => 10000], 10000, $r7rLatest(10000, 9000, 5))['compare_price'],
    'R7 Omnibus: a promotion right after a price rise shows the lower price of the last 30 days');
assert_equals(10000, PriceHistory::capCompare(['price' => 8000, 'compare_price' => 10000], 10000, null)['compare_price'],
    'R7 Omnibus: a product without recorded history keeps its compare price');
$pdo->exec('DELETE FROM product_price_history WHERE product_id BETWEEN 97730 AND 97733');
$pdo->exec('DELETE FROM products WHERE id BETWEEN 97730 AND 97733');

// R7 medium: categories.product_count (the storefront hides a category at 0 and orders its menus by it) was counted
// only by the CSV import and the category admin; a product created, moved, archived or deleted in the admin left the
// counts as they were, and a product linked to a second category never counted there.
echo "\n--- R7: category product counts follow product writes ---\n";
$pdo->exec('DELETE FROM categories WHERE id BETWEEN 97790 AND 97792');
$r7rCat = $pdo->prepare('INSERT INTO categories (id, store_id, slug, name, parent_id, path, depth, product_count) VALUES (?, ?, ?, ?, ?, ?, ?, 0)');
$r7rCat->execute([97790, $r7rCatalog, 'r7r-cnt', 'R7R count', null, 'r7r-cnt', 0]);
$r7rCat->execute([97791, $r7rCatalog, 'r7r-cnt-kid', 'R7R count kid', 97790, 'r7r-cnt/r7r-cnt-kid', 1]);
$r7rCat->execute([97792, $r7rCatalog, 'r7r-cnt-other', 'R7R count other', null, 'r7r-cnt-other', 0]);
$r7rCounts = static fn(): array => array_map('intval', $pdo->query('SELECT product_count FROM categories WHERE id BETWEEN 97790 AND 97792 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$r7rRow = static fn(int $id): array => $pdo->query("SELECT * FROM products WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
// Admin create: the product row, then ProductEditor::save() in the same transaction (as Admin\Catalog does).
$pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, name, price, stock, status) VALUES (97740, ?, 97791, 'R7R-CNT', 'r7r-cnt-p', 'R7R Count', 1000, 3, 'active')")->execute([$r7rCatalog]);
ProductEditor::save(97740, ['categories' => [97791], 'slugs' => []]);
assert_equals([1, 1, 0], $r7rCounts(), 'R7 category counts: an admin-created product counts in its category and the parent (was 0, hidden from the menu)');
// Admin edit moving it to another category, also linked to the old one.
$r7rOld = $r7rRow(97740);
$pdo->exec('UPDATE products SET category_id = 97792 WHERE id = 97740');
ProductEditor::save(97740, ['categories' => [97792], 'slugs' => []], $r7rOld);
assert_equals([0, 0, 1], $r7rCounts(), 'R7 category counts: a product moved in the admin leaves the old categories and joins the new one');
$r7rOld = $r7rRow(97740);
ProductEditor::save(97740, ['categories' => [97792, 97791], 'slugs' => []], $r7rOld);
assert_equals([1, 1, 1], $r7rCounts(), 'R7 category counts: a product linked to a second category counts there as the category page lists it');
ProductBulk::apply('archive', [97740]);
assert_equals([0, 0, 0], $r7rCounts(), 'R7 category counts: a bulk-archived product leaves every count');
ProductBulk::apply('activate', [97740]);
assert_equals([1, 1, 1], $r7rCounts(), 'R7 category counts: a bulk-activated product is counted again');
ProductBulk::apply('delete', [97740]);
assert_equals([0, 0, 0], $r7rCounts(), 'R7 category counts: a bulk-deleted product leaves every count');
$pdo->exec('DELETE FROM product_categories WHERE product_id = 97740');
$pdo->exec('DELETE FROM product_price_history WHERE product_id = 97740');
$pdo->exec('DELETE FROM products WHERE id = 97740');
$pdo->exec('DELETE FROM categories WHERE id BETWEEN 97790 AND 97792');

// R7 medium: deleting a product left its warehouse stock rows (keyed by SKU) behind; a product created later with the
// same SKU, or a variant SKU of it, inherited the units and sold stock nobody had.
echo "\n--- R7: a deleted product's stock does not come back ---\n";
$r7rWh = (int)(Warehouse::defaultWarehouse()['id'] ?? Warehouse::all()[0]['id']);
$r7rStockOf = static function (string $sku) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT qty, reserved FROM warehouse_stock WHERE sku = ?');
    $stmt->execute([$sku]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};
$r7rNewProduct = static function (int $id) use ($pdo, $r7rCatalog): void {
    $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, name, price, stock, status)
        SELECT ?, ?, id, 'R7R-PHANTOM', ?, 'R7R Phantom', 1000, 0, 'active' FROM categories WHERE store_id = ? ORDER BY id LIMIT 1")
        ->execute([$id, $r7rCatalog, "r7r-phantom-{$id}", $r7rCatalog]);
};
$r7rNewProduct(97750);
$pdo->exec("INSERT INTO variants (id, product_id, sku, name) VALUES (97750, 97750, 'R7R-PHANTOM-XL', 'XL')");
Warehouse::setStock($r7rWh, 'R7R-PHANTOM', 7, 'receipt');
Warehouse::setStock($r7rWh, 'R7R-PHANTOM-XL', 4, 'receipt');
$r7rDeleted = ProductBulk::apply('delete', [97750]);
assert_equals([1, []], [$r7rDeleted['count'], $r7rDeleted['skipped']], 'R7 phantom stock: the product without orders is deleted');
assert_equals([[], []], [$r7rStockOf('R7R-PHANTOM'), $r7rStockOf('R7R-PHANTOM-XL')], 'R7 phantom stock: the product and variant stock rows are gone (were 7 and 4 units)');
assert_equals([['R7R-PHANTOM', -7], ['R7R-PHANTOM-XL', -4]], array_map(static fn(array $r): array => [$r['sku'], (int)$r['quantity_delta']],
    $pdo->query("SELECT sku, quantity_delta FROM stock_movements WHERE movement_type = 'product_deleted' AND sku LIKE 'R7R-PHANTOM%' ORDER BY sku")->fetchAll(PDO::FETCH_ASSOC)),
    'R7 phantom stock: the ledger books the units out');
$r7rNewProduct(97751);
Warehouse::syncProductStock(['R7R-PHANTOM']);
assert_equals(0, (int)$pdo->query('SELECT stock FROM products WHERE id = 97751')->fetchColumn(), 'R7 phantom stock: a new product with the SKU starts at 0 (was 7)');
// Units reserved for an open order keep the product.
Warehouse::setStock($r7rWh, 'R7R-PHANTOM', 3, 'receipt');
$pdo->exec("UPDATE warehouse_stock SET reserved = 1 WHERE sku = 'R7R-PHANTOM'");
assert_equals(['R7R-PHANTOM'], ProductBulk::apply('delete', [97751])['skipped'], 'R7 phantom stock: a product with reserved units is not deleted');
$pdo->exec("DELETE FROM warehouse_stock WHERE sku LIKE 'R7R-PHANTOM%'");
$pdo->exec("DELETE FROM stock_movements WHERE sku LIKE 'R7R-PHANTOM%'");
$pdo->exec('DELETE FROM products WHERE id IN (97750, 97751)');

// ── cli/extract-specs.php deleted every 'sk' specification first, including the admin-entered and imported ones ──
$r7rSpecs = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
(require TEST_CLI_DIR . '/../migrations/20260424000000_baseline.php')->up($r7rSpecs, true);
(require TEST_CLI_DIR . '/../migrations/20261020130000_product_specification_source.php')->up($r7rSpecs, true);
$r7rSpecs->exec("INSERT INTO categories (id, slug, name, path, depth) VALUES (1, 'saunove-pece', 'Pece', 'saunove-pece', 0)");
$r7rSpecs->exec("INSERT INTO products (id, sku, slug, name, category_id, price, status) VALUES (1, 'R7R-S1', 'r7r-s1', 'Harvia Cilindro 9 kW čierna', 1, 1000, 'active')");
$r7rSpecs->exec("INSERT INTO product_specifications (product_id, criteria_name, criteria_value, lang) VALUES (1, 'Farba', 'Biela', 'sk'), (1, 'Záruka', '5 rokov', 'sk')");
$r7rRows = static fn(): array => $r7rSpecs->query("SELECT criteria_name || '=' || criteria_value FROM product_specifications ORDER BY criteria_name")->fetchAll(PDO::FETCH_COLUMN);
$r7rFirst = SpecExtraction::run($r7rSpecs);
$r7rSecond = SpecExtraction::run($r7rSpecs);
assert_equals(['Farba=Biela', 'Výkon (kW)=9 kW', 'Značka=Harvia', 'Záruka=5 rokov'], $r7rRows(),
    'R7 extract-specs: specs entered in the admin survive and win over the extracted color (were deleted)');
assert_equals([2, 1, 2, 1], [$r7rFirst['total'], $r7rFirst['kept'], $r7rSecond['total'], $r7rSecond['kept']], 'R7 extract-specs: a re-run replaces only its own rows');
$r7rSpecs = null;

// R7 low: an invoice_issue job queued when the order was paid, run after the order was cancelled, threw "not paid" on
// every retry (a critical incident each time) until the job died. It has nothing to invoice and now ends quietly.
echo "\n--- R7: invoice_issue after the paid order was cancelled ---\n";
$r7rSavedArgv = $argv ?? [];
$argv = ['worker.php', '--once'];
require_once TEST_CLI_DIR . '/../cli/worker.php';
$argv = $r7rSavedArgv;
$pdo->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, locale)
    VALUES ('R7R-INV-CANCEL', ?, 'cancelled', 'refunded', 'R7 Buyer', 'r7r-inv@example.test', 'card', 2590, 2590, 'eur', 'en')")->execute([$r7rStore]);
$r7rInvOrder = (int)$pdo->lastInsertId();
$r7rInvError = null;
try {
    handleInvoiceIssue(['order_id' => $r7rInvOrder], []);
} catch (Throwable $e) {
    $r7rInvError = $e->getMessage();
}
assert_equals([null, null], [$r7rInvError, InvoiceService::byOrderId($r7rInvOrder)],
    'R7 invoice job: a cancelled order completes the job without an invoice (was "is not paid" on every retry)');
$pdo->exec("UPDATE orders SET status = 'pending', payment_status = 'pending' WHERE id = {$r7rInvOrder}");
$r7rInvError = null;
try {
    handleInvoiceIssue(['order_id' => $r7rInvOrder], []);
} catch (Throwable $e) {
    $r7rInvError = $e->getMessage();
}
assert_true($r7rInvError === null && InvoiceService::byOrderId($r7rInvOrder) === null, 'R7 invoice job: an unpaid order is skipped the same way');
$pdo->exec("DELETE FROM orders WHERE id = {$r7rInvOrder}");
