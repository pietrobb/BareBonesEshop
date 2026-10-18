<?php

// Final review of audit round 4: GoPay/ThePay refunds booked once when the REFUNDED notification races the refund
// worker; HTML checkout coupon attempts share the coupon API's throttle and refusals give no guessing oracle;
// another store's default language has no ?lang= canonical; one download predicate; category and pricing edits and
// stock-outs reach every store sharing the catalog while ordinary reservations keep the catalog caches; managed menu
// items keep one store prefix; ERP sync fails loudly without its lock; stuck refund requests can be re-queued.
echo "\n--- Audit round 4: final review ---\n";
require_once TEST_CLI_DIR . '/../cli/worker.php';
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php';
require_once dirname(TEST_CLI_DIR) . '/adapters/PaymentAdapter.php';
Bootstrap::initStoreContext('/', '', 'default');

if (!class_exists('Fr4RacingGateway', false)) {
    /** A provider without refund ids (GoPay, ThePay): $during runs while the refund call is "in flight". */
    class Fr4RacingGateway implements PaymentAdapter {
        /** @var (callable(): void)|null */
        public $during = null;
        public int $calls = 0;
        public function createSession(array $order): array { return []; }
        public function handleWebhook(array $payload): array { return []; }
        public function getStatus(string $paymentId): string { return 'paid'; }
        public function refund(string $paymentId, int $amount): array {
            $this->calls++;
            if ($this->during) ($this->during)();
            return ['refund_id' => '', 'status' => 'succeeded'];
        }
    }
    /** Stripe without network: one refund id per idempotency key. */
    class Fr4FakeStripe extends StripePayment {
        public int $calls = 0;
        public function __construct() { parent::__construct([]); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            $this->calls++;
            return ['refund_id' => 're_fr4_' . substr(sha1($idempotencyKey), 0, 12), 'status' => 'succeeded'];
        }
    }
}

$fr4Tmp = sys_get_temp_dir() . '/bbe-fr4-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($fr4Tmp . '/cache', 0700, true);
JobQueue::setDir($fr4Tmp . '/jobs');
$fr4Adapters = new ReflectionProperty(Adapters::class, 'instances');
$fr4SavedAdapters = $fr4Adapters->getValue();
$fr4CacheDir = new ReflectionProperty(Cache::class, 'cacheDir');
$fr4SavedCacheDir = $fr4CacheDir->getValue();
$fr4RateDb = new ReflectionProperty(Security::class, 'rateDb');
$fr4SavedRateDb = $fr4RateDb->getValue();
$fr4SavedLocale = I18n::locale();
$fr4SavedIp = $_SERVER['REMOTE_ADDR'] ?? null;
$fr4Order = static function (string $status, string $paymentStatus, string $provider, string $paymentId, int $total = 1000) use ($pdo): int {
    $snapshot = ['provider' => $provider, 'service_type' => $provider === 'manual' ? 'bank_transfer' : 'online_card'];
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'FR4', 'fr4@example.test', '', '{}', ?, 0, 0, 0, ?, 'eur', ?, ?, ?, ?, ?, 'default', " . Db::now() . ")")
        ->execute(['FR4-' . bin2hex(random_bytes(5)), $total, $total, $status, $paymentStatus, $provider, $paymentId, json_encode($snapshot)]);
    return (int)$pdo->lastInsertId();
};
$fr4Ledger = static function (int $orderId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT status, amount FROM payment_transactions WHERE order_id = ? ORDER BY id');
    $stmt->execute([$orderId]);
    return array_map(static fn(array $row): string => $row['status'] . ':' . $row['amount'], $stmt->fetchAll(PDO::FETCH_ASSOC));
};
$fr4State = static function (int $orderId): array {
    $order = Orders::byId($orderId);
    return [(string)$order['payment_status'], PaymentService::refundedAmount($orderId)];
};
$fr4Jobs = static function (int $orderId) use ($fr4Tmp): array {
    $jobs = [];
    foreach (glob($fr4Tmp . '/jobs/pending/*.json') ?: [] as $file) {
        $job = json_decode((string)file_get_contents($file), true);
        if (($job['type'] ?? '') === 'payment_refund' && (int)($job['payload']['order_id'] ?? 0) === $orderId) $jobs[] = $job;
    }
    return $jobs;
};
$fr4Apply = new ReflectionMethod(PaymentService::class, 'applyWebhookEvent');
$fr4Reported = static fn(int $orderId, string $paymentId): callable => static fn() => $fr4Apply->invoke(null, $orderId, 'gopay', 'refund.reported',
    'payment_gopay_fr4_' . $orderId, ['state' => 'REFUNDED', 'currency' => 'EUR', 'amount' => 1000, 'provider_ref' => $paymentId]);
// Catalog cache marker of the active catalog (Cache::catalogChanged()), in a private cache directory.
$fr4Marker = $fr4Tmp . '/cache/catalogs/' . rawurlencode(StoreContext::current()->catalogId()) . '.changed';
$fr4Changes = static function (callable $write) use ($fr4Marker): bool {
    @unlink($fr4Marker);
    $write();
    clearstatcache(true, $fr4Marker);
    return is_file($fr4Marker);
};

try {
    Cache::setDir($fr4Tmp . '/cache');
    // 1. GoPay/ThePay REFUNDED processed while the worker's refund call is in flight: booked once.
    $gateway = new Fr4RacingGateway();
    $fr4Adapters->setValue(null, ['payment' => $gateway] + $fr4SavedAdapters);
    $o1 = $fr4Order('cancelled', 'paid', 'gopay', '3000000901');
    PaymentService::requestRefund($o1, 1000, 'order_refund_request_' . $o1);
    $gateway->during = $fr4Reported($o1, '3000000901');
    PaymentService::refund($o1, '3000000901', 1000, $gateway, 'order_refund_' . $o1, 'eur');
    assert_equals([['refunded', 1000], ['refund_pending:1000', 'refund_attempted:1000', 'refunded:1000', 'refund_confirmed:1000']], [$fr4State($o1), $fr4Ledger($o1)],
        'FR4 1: a GoPay REFUNDED notification booked during the refund call leaves the worker a refund_confirmed (was 2000 of 1000)');
    // An admin request executed by its payment_refund job, ThePay-like (refund id ''), notification during the call.
    $o1b = $fr4Order('delivered', 'paid', 'gopay', '3000000902');
    PaymentService::requestRefundAndQueue($o1b, 1000, 'fr4_1b_request', ['reason' => 'FR4']);
    $gateway->during = $fr4Reported($o1b, '3000000902');
    $o1bJob = $fr4Jobs($o1b)[0] ?? null;
    assert_equals('fr4_1b_request_execute', $o1bJob['payload']['idempotency_key'] ?? null, 'FR4 1: the admin request job keeps its execute key');
    if ($o1bJob) handlePaymentRefund($o1bJob['payload'], $o1bJob);
    assert_equals(['refunded', 1000], $fr4State($o1b), 'FR4 1: the refund job after the notification books no second refund');
    // Keyed to the request: a booking that settled this request is recognised even where the amounts would still fit.
    $o1c = $fr4Order('delivered', 'refund_pending', 'gopay', '3000000903');
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
        VALUES (?, 'default', 'gopay', '3000000903', 'partially_refunded', 400, 'eur', ?, ?)")
        ->execute([$o1c, 'fr4_1c_evt', json_encode(['event_type' => 'refund.reported', 'settles_refund_request' => 'fr4_1c_request'])]);
    $gateway->during = null;
    PaymentService::refund($o1c, '3000000903', 400, $gateway, 'fr4_1c_request_execute', 'eur');
    assert_equals(400, PaymentService::refundedAmount($o1c), 'FR4 1: the worker confirms a refund a notification booked for its own request');
    PaymentService::refund($o1c, '3000000903', 300, $gateway, 'fr4_1c_other_execute', 'eur');
    assert_equals(700, PaymentService::refundedAmount($o1c), 'FR4 1: a different request without a refund id is still booked');
    assert_equals(['order_refund_request_7', 'adm_x', null], [PaymentService::refundRequestKey('order_refund_7'), PaymentService::refundRequestKey('adm_x_execute'), PaymentService::refundRequestKey('order_refund_x')],
        'FR4 1: execute keys map back to their ledger request keys');

    // 2. Coupons: refusals other than the minimum order look alike, and both entry points share one throttle.
    assert_equals(['coupon_invalid', 'coupon_invalid', 'coupon_invalid', 'coupon_min_subtotal'],
        array_map([CheckoutService::class, 'couponErrorKey'], ['Coupon expired', 'Coupon already used', 'Invalid coupon', 'Minimum order not met']),
        'FR4 2: used-up and already used coupons read as invalid (checkout preview and order placement)');
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $_SERVER['REMOTE_ADDR'] = '127.0.44.4';
    $fr4Allowed = [];
    for ($i = 0; $i < 11; $i++) $fr4Allowed[] = CheckoutService::couponAttemptAllowed();
    assert_equals(array_merge(array_fill(0, 10, true), [false]), $fr4Allowed, 'FR4 2: 10 coupon attempts per 10 minutes per client IP');
    $fr4Api = (string)file_get_contents(TEST_CLI_DIR . '/../src/Http/ApiHandlers.php');
    $fr4Checkout = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Storefront/CheckoutPages.php');
    assert_true(str_contains($fr4Api, 'if (!CheckoutService::couponAttemptAllowed()) {') && str_contains($fr4Checkout, '!CheckoutService::formCouponAllowed($couponCode)')
        && !str_contains($fr4Api, "rateLimit('validate_coupon'"), 'FR4 2: the coupon API and the HTML checkout form spend the same coupon budget');

    // 3. ?lang= of a language that is another store's default is not canonical here (hreflang points at that store).
    $fr4Secondary = [];
    foreach (['cs', 'en', 'sk'] as $locale) { I18n::setLocale($locale); $fr4Secondary[] = Bootstrap::secondaryLocale(); }
    Bootstrap::initStoreContext('/en/', '', 'us');
    I18n::setLocale('es');
    $fr4Secondary[] = Bootstrap::secondaryLocale();
    Bootstrap::initStoreContext('/', '', 'default');
    I18n::setLocale($fr4SavedLocale);
    assert_equals([null, null, null, 'es'], $fr4Secondary, 'FR4 3: only a language no store defaults to gets a ?lang= canonical');

    // 4. The account downloads page uses the download rules: a partial refund keeps uncredited lines.
    $o4 = $fr4Order('delivered', 'partially_refunded', 'stripe', 'pi_fr4_4');
    $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, 0, 0, 500, 'eur', 'pending', ?)")
        ->execute([$o4, 'fr4-credit-' . $o4]);
    $pdo->prepare("INSERT INTO credit_note_items (credit_note_id, refund_id, order_item_id, rma_item_id, sku, name, quantity, unit_gross, gross, net, vat, rate_basis_points) VALUES (0, ?, 987654002, 987654002, 'FR4', 'Credited', 1, 500, 500, 400, 100, 2500)")
        ->execute([(int)$pdo->lastInsertId()]);
    $fr4Row = static fn(string $payment, int $item): array => ['asset_active' => 1, 'payment_status' => $payment, 'order_status' => 'delivered',
        'expires_at' => null, 'download_limit' => null, 'download_count' => 0, 'order_item_id' => $item];
    assert_equals([true, true, false, false], [DigitalDelivery::downloadable($fr4Row('partially_refunded', 987654001)), DigitalDelivery::downloadable($fr4Row('refund_pending', 987654001)),
        DigitalDelivery::downloadable($fr4Row('partially_refunded', 987654002)), DigitalDelivery::downloadable($fr4Row('refunded', 987654001))],
        'FR4 4: after a partial refund the account offers uncredited downloads again (not the credited line, not after a full refund)');

    // 5. Category tree and pricing edits mark the shared catalog changed, so every store sharing it refreshes.
    assert_true($fr4Changes(static fn() => (new ReflectionMethod(CatalogTaxonomy::class, 'invalidateTree'))->invoke(null)), 'FR4 5: a category edit marks the shared catalog changed');
    assert_true($fr4Changes(static fn() => (new ReflectionMethod(PricingAdmin::class, 'invalidatePrices'))->invoke(null)), 'FR4 5: a pricing edit marks the shared catalog changed');

    // 6. A managed menu item entered with the store prefix keeps one prefix.
    Bootstrap::initStoreContext('/cs/', '', 'cz');
    $fr4Base = Template::baseUrl();
    assert_equals([$fr4Base . '/cs/page/x', $fr4Base . '/cs/page/x', $fr4Base . '/cs/', $fr4Base . '/cs/csx/page', 'https://example.test/a', '#top', '?page=cart'],
        array_map([Template::class, 'menuUrl'], ['/cs/page/x', '/page/x', '/cs', '/csx/page', 'https://example.test/a', '#top', '?page=cart']),
        'FR4 6: menu URLs get the store prefix once (/cs/page/x was /cs/cs/page/x)');
    Bootstrap::initStoreContext('/', '', 'default');

    // 7. ERP sync without a lock it can open fails loudly instead of exiting 0 forever.
    $fr4Sync = (string)file_get_contents(TEST_CLI_DIR . '/sync.php');
    assert_true(str_contains($fr4Sync, 'fwrite(STDERR, "ERP sync: cannot open the lock file') && str_contains($fr4Sync, 'exit(1);'),
        'FR4 7: sync.php reports an unopenable lock on STDERR with a non-zero exit, a run in progress still exits 0');

    // 8. Stock: an ordinary reservation keeps the catalog caches; availability flips and admin movements do not.
    $fr4Warehouse = Warehouse::defaultWarehouse();
    assert_true($fr4Warehouse !== null, 'FR4 8: the suite has a default warehouse');
    $pdo->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', (SELECT MIN(id) FROM categories), 'FR4-STK', ?, 'FR4 stock', 1000, 0, 'active')")->execute(['fr4-stk-' . bin2hex(random_bytes(3))]);
    $fr4WhId = (int)$fr4Warehouse['id'];
    assert_true($fr4Changes(static function () use ($fr4WhId): void { Warehouse::setStock($fr4WhId, 'FR4-STK', 5); Warehouse::syncProductStock(['FR4-STK']); }), 'FR4 8: a stock count marks the catalog changed');
    $fr4Alloc = [];
    assert_true(!$fr4Changes(static function () use (&$fr4Alloc): void { $fr4Alloc = Warehouse::reserve('FR4-STK', 2); Warehouse::syncProductStock(['FR4-STK']); }), 'FR4 8: a checkout reservation that leaves stock keeps the catalog caches');
    assert_true(!$fr4Changes(static function () use ($fr4Alloc): void { Warehouse::release('FR4-STK', $fr4Alloc); Warehouse::syncProductStock(['FR4-STK']); }), 'FR4 8: releasing it keeps them too');
    assert_true($fr4Changes(static function () use (&$fr4Alloc): void { $fr4Alloc = Warehouse::reserve('FR4-STK', 5); Warehouse::syncProductStock(['FR4-STK']); }), 'FR4 8: a reservation that sells out marks the catalog changed');
    assert_true($fr4Changes(static function () use ($fr4Alloc): void { Warehouse::release('FR4-STK', $fr4Alloc); Warehouse::syncProductStock(['FR4-STK']); }), 'FR4 8: a release back into stock marks it changed');
    assert_true($fr4Changes(static fn() => Warehouse::adjustStock($fr4WhId, 'FR4-STK', 1, 'receipt')), 'FR4 8: a receipt marks the catalog changed');

    // 9. A refund request no job ever executed (requested before jobs were queued) can be re-queued by an admin.
    $stripe = new Fr4FakeStripe();
    $fr4Adapters->setValue(null, ['payment' => $stripe] + $fr4SavedAdapters);
    $o9 = $fr4Order('delivered', 'paid', 'stripe', 'pi_fr4_9');
    PaymentService::requestRefund($o9, 600, 'admin_refund_request_' . $o9 . '_fr4');
    assert_equals(['amount' => 600, 'idempotency_key' => 'admin_refund_request_' . $o9 . '_fr4_execute'], PaymentService::unexecutedRefundRequest(Orders::byId($o9)),
        'FR4 9: a refund_pending request without an executed job is offered for retry, with requestRefundAndQueue()\'s execute key');
    assert_true(PaymentService::retryRefundRequest($o9, 'fr4-admin'), 'FR4 9: the retry queues the job');
    $o9Job = $fr4Jobs($o9)[0] ?? null;
    assert_equals([600, 'admin_refund_request_' . $o9 . '_fr4_execute'], [$o9Job['payload']['amount'] ?? null, $o9Job['payload']['idempotency_key'] ?? null], 'FR4 9: the queued job refunds the request amount under its execute key');
    $o9Audit = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'order_refund' AND entity_id = '{$o9}' AND note LIKE '%fr4-admin%'")->fetchColumn();
    assert_equals(1, $o9Audit, 'FR4 9: the retry is audited with the acting admin');
    if ($o9Job) handlePaymentRefund($o9Job['payload'], $o9Job);
    assert_equals([['partially_refunded', 600], 1, null], [$fr4State($o9), $stripe->calls, PaymentService::unexecutedRefundRequest(Orders::byId($o9))],
        'FR4 9: the job refunds once and the retry is no longer offered');
    $o9m = $fr4Order('delivered', 'paid', 'manual', '');
    PaymentService::requestRefund($o9m, 500, 'fr4_9m_request');
    assert_equals([null, false], [PaymentService::unexecutedRefundRequest(Orders::byId($o9m)), PaymentService::retryRefundRequest($o9m, 'fr4-admin')], 'FR4 9: money returned by hand is recorded, not retried');
    $fr4Admin = entrypointSource('admin.php');
    assert_true(str_contains($fr4Admin, "'order:refund_retry' => handleOrderRefundRetry()")
        && str_contains($fr4Admin, "function handleOrderRefundRetry(): void {\n    Security::adminRequire('orders.refund');"),
        'FR4 9: the retry admin action is routed through the CSRF-checked admin POST and needs the refund permission');
} catch (Throwable $e) {
    assert_true(false, 'Round 4 final review regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $fr4Adapters->setValue(null, $fr4SavedAdapters);
    $fr4CacheDir->setValue(null, $fr4SavedCacheDir);
    $fr4RateDb->setValue(null, $fr4SavedRateDb);
    if ($fr4SavedIp === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $fr4SavedIp;
    I18n::setLocale($fr4SavedLocale);
    Bootstrap::initStoreContext('/', '', 'default');
    $fr4Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fr4Tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($fr4Tree as $fr4Path) $fr4Path->isDir() ? @rmdir($fr4Path->getPathname()) : @unlink($fr4Path->getPathname());
    @rmdir($fr4Tmp);
}
