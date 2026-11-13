<?php

// Review round 35 (low, refunds):
// 1. A second "Release" of a stalled item refund whose first release found it executed at Stripe ('refunded') or still
//    processed there ('pending') showed an error: it now reports the same outcome again and changes nothing.
// 2. A Stripe key that may not read refunds (restricted key without the Refunds permission; Write implies Read, see
//    docs.stripe.com/keys/restricted-api-keys) refused every release with "try again later": Stripe answers 403, the
//    release is still refused (nothing is released) and the admin is told to fix the key's permission.
echo "\n--- R35: idempotent second release; Stripe key without refund read permission ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r35rJobsDir = test_fixture_path('r35r-jobs');
JobQueue::setDir($r35rJobsDir);
$r35rDropQueue = static function () use ($r35rJobsDir): void {
    foreach (['pending', 'processing'] as $sub) foreach (glob($r35rJobsDir . '/' . $sub . '/*.json') ?: [] as $file) @unlink($file);
};
if (!class_exists('R35rFakeStripe', false)) {
    class R35rFakeStripe extends StripePayment {
        /** @var array<string,mixed>|null what GET /v1/refunds answers; null: Stripe answers 403 (missing permission) */
        public static ?array $list = null;
        public function __construct() { parent::__construct(['stripe_secret_key' => 'rk_test_r35r']); }
        protected function apiCall(string $method, string $endpoint, array $params = [], array $extraHeaders = []): array {
            if ($method === 'GET' && $endpoint === '/v1/refunds') {
                if (self::$list === null) throw new RuntimeException("Stripe API: The provided key 'rk_test_***r35r' does not have the required permissions for this endpoint. Having the 'rak_refund_read' permission would allow this request to continue.", 403);
                return self::$list;
            }
            throw new RuntimeException("R35r: unexpected Stripe call {$method} {$endpoint}");
        }
    }
}
$r35rAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r35rSavedAdapters = $r35rAdapters->getValue();
$r35rAdapters->setValue(null, ['payment' => new R35rFakeStripe()] + $r35rSavedAdapters);
$r35rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r35rOrders = [];
$r35rOrder = static function (string $number, string $paymentId) use ($pdo, $r35rProduct, &$r35rOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['product_id' => 1, 'variant_id' => null, 'sku' => "{$number}-PB", 'price' => 2100, 'qty' => 1, 'total' => 2100, 'available' => true,
        'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'books', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $items = Cart::applyVat(['items' => [$line], 'subtotal' => 2100, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R35R', 'r35r@example.test', '', '{\"country\":\"SK\"}', 2100, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$number, (int)$snapshot['totals']['gross'], $paymentId, json_encode(['provider' => 'stripe', 'service_type' => 'online_card']),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $id = $r35rOrders[] = (int)$pdo->lastInsertId();
    foreach (['payment_transactions', 'order_refunds'] as $r35rStale) $pdo->prepare("DELETE FROM {$r35rStale} WHERE order_id = ?")->execute([$id]);
    $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 2100, 2100)')
        ->execute([$id, $r35rProduct, "{$number}-PB", "R35R {$number}"]);
    InvoiceService::issue($id);
    return $id;
};
// An item refund whose payment_refund job died: pending, offered for release.
$r35rItemRefund = static function (int $orderId, string $key) use ($pdo, $r35rDropQueue): array {
    [, $rma] = Rma::create($orderId, null, "R35R return {$key}", [(int)Orders::items($orderId)[0]['id'] => 1]);
    Rma::setStatus((int)$rma, 'approved');
    $rmaItem = (int)Rma::items((int)$rma)[0]['id'];
    Rma::receive((int)$rma, [$rmaItem => ['quantity' => 1, 'disposition' => 'repair']], "r35r-receipt-{$key}");
    $invoiceId = (int)$pdo->query("SELECT id FROM invoices WHERE order_id = {$orderId} AND type = 'invoice'")->fetchColumn();
    $credit = InvoiceService::credit($invoiceId, (int)$rma, [$rmaItem => 1], $key);
    $r35rDropQueue();
    return ['id' => (int)$credit['refund_id'], 'amount' => -(int)$credit['total_gross'], 'key' => $key];
};
$r35rItem = static fn(int $id): string => (string)$pdo->query("SELECT status FROM order_refunds WHERE id = {$id}")->fetchColumn();
$r35rCredits = static fn(int $orderId): array => array_map('intval',
    $pdo->query("SELECT total_gross FROM invoices WHERE order_id = {$orderId} AND type = 'credit_note' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$r35rRefund = static fn(array $item, string $id, string $status): array => ['data' => [['id' => $id, 'status' => $status, 'amount' => $item['amount'],
    'metadata' => [StripePayment::REFUND_KEY_METADATA => $item['key']]]], 'has_more' => false];

try {
    $r35rDropQueue();
    // 1a. Stripe executed it: the first release books it, the second says so again and books nothing more.
    $o1 = $r35rOrder('R35R-1', 'pi_r35r_1');
    $i1 = $r35rItemRefund($o1, 'r35r-1-item');
    R35rFakeStripe::$list = $r35rRefund($i1, 're_r35r_1', 'succeeded');
    $first1 = PaymentService::releaseStalledItemRefund($o1, $i1['id'], 'r35r-admin', 'R35R first click');
    $second1 = PaymentService::releaseStalledItemRefund($o1, $i1['id'], 'r35r-admin', 'R35R second click');
    assert_equals(['refunded', 'refunded', 'succeeded', $i1['amount'], [-$i1['amount']]],
        [$first1, $second1, $r35rItem($i1['id']), PaymentService::refundedAmount($o1), $r35rCredits($o1)],
        'R35 refunds: a second release of a refund Stripe executed reports it again and books nothing twice (was: an error)');

    // 1b. Stripe still processes it: the second release says it is still processed.
    $o2 = $r35rOrder('R35R-2', 'pi_r35r_2');
    $i2 = $r35rItemRefund($o2, 'r35r-2-item');
    R35rFakeStripe::$list = $r35rRefund($i2, 're_r35r_2', 'pending');
    $first2 = PaymentService::releaseStalledItemRefund($o2, $i2['id'], 'r35r-admin', 'R35R first click');
    $second2 = PaymentService::releaseStalledItemRefund($o2, $i2['id'], 'r35r-admin', 'R35R second click');
    assert_equals(['pending', 'pending', 'pending', [-$i2['amount']]], [$first2, $second2, $r35rItem($i2['id']), $r35rCredits($o2)],
        'R35 refunds: a second release of a refund Stripe still processes reports it again (was: an error)');

    // 2. A key without refund read permission: Stripe answers 403, nothing is released, the admin gets the key to fix.
    $o3 = $r35rOrder('R35R-3', 'pi_r35r_3');
    $i3 = $r35rItemRefund($o3, 'r35r-3-item');
    R35rFakeStripe::$list = null;
    $code3 = 0;
    try { PaymentService::releaseStalledItemRefund($o3, $i3['id'], 'r35r-admin', 'R35R no permission'); } catch (RuntimeException $e) { $code3 = $e->getCode(); }
    $r35rHandler = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php');
    assert_equals([403, 'pending', [-$i3['amount']], true, true],
        [$code3, $r35rItem($i3['id']), $r35rCredits($o3),
            str_contains($r35rHandler, "in_array(\$e->getCode(), [401, 403], true) ? 'admin.order.msg.refund_release_forbidden' : 'admin.order.msg.refund_release_unverified'"),
            str_contains(I18n::t('admin.order.msg.refund_release_forbidden'), 'Refunds')],
        'R35 refunds: a Stripe key that may not read refunds refuses the release (403, nothing released) and the admin is told which permission to give');
} catch (Throwable $e) {
    assert_true(false, 'R35 refunds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    R35rFakeStripe::$list = null;
    $r35rAdapters->setValue(null, $r35rSavedAdapters);
    $r35rDropQueue();
    foreach ($r35rOrders as $r35rCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'return_receipts' => 'rma_item_id IN (SELECT id FROM rma_items WHERE rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?))',
            'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r35rTable => $r35rWhere) {
            try { $pdo->prepare("DELETE FROM {$r35rTable} WHERE {$r35rWhere}")->execute([$r35rCleanup]); } catch (Throwable $e) {}
        }
    }
}
