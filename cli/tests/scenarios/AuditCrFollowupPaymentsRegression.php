<?php

// Code review follow-up (payments/orders): money captured on a cancelled order or by a second
// payment is never kept silently, offline refunds are flagged, Stripe captures must carry amount
// and currency, and one bad ERP product no longer aborts the whole sync.
echo "\n--- CR follow-up: payments and orders ---\n";
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php';
Bootstrap::initStoreContext('/', '', 'default');
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);

$crfpJobsDir = sys_get_temp_dir() . '/bbe-crfp-jobs-' . getmypid() . '-' . bin2hex(random_bytes(3));
JobQueue::setDir($crfpJobsDir);
$crfpAdapters = new ReflectionProperty(Adapters::class, 'instances');
$crfpSavedAdapters = $crfpAdapters->getValue();
$crfpSecret = 'crfp-mock-webhook-secret-0123456789';
$crfpAdapters->setValue(null, ['payment' => new MockPayment(['mock_webhook_secret' => $crfpSecret])] + $crfpSavedAdapters);
$crfpOrder = static function (string $status, string $paymentStatus, string $method, string $paymentId = '', int $total = 1000) use ($pdo): int {
    $snapshot = match ($method) {
        'bank_transfer' => ['provider' => 'manual', 'service_type' => 'bank_transfer', 'requires_online' => false],
        'cod' => ['provider' => 'manual', 'service_type' => 'cod', 'requires_online' => false],
        'card' => ['provider' => 'stripe', 'service_type' => 'online_card', 'requires_online' => true],
        default => ['provider' => 'mock', 'service_type' => 'online_card', 'requires_online' => true],
    };
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'CRFP', 'crfp@example.test', '', '{}', ?, 0, 0, 0, ?, 'eur', ?, ?, ?, ?, ?, 'default', " . Db::now() . ")")
        ->execute(['CRFP-' . bin2hex(random_bytes(5)), $total, $total, $status, $paymentStatus, $method, $paymentId, json_encode($snapshot)]);
    return (int)$pdo->lastInsertId();
};
$crfpState = static function (int $orderId): array {
    $order = Orders::byId($orderId);
    return [(string)$order['status'], (string)$order['payment_status']];
};
$crfpIncidents = static function (string $code, int $orderId) use ($pdo): int {
    $stmt = $pdo->prepare("SELECT context_json FROM operational_incidents WHERE code = ?");
    $stmt->execute([$code]);
    return count(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static fn($json): bool => (int)(json_decode((string)$json, true)['order_id'] ?? 0) === $orderId));
};
$crfpLedger = static function (int $orderId, string $status) use ($pdo): array {
    $stmt = $pdo->prepare("SELECT amount FROM payment_transactions WHERE order_id = ? AND status = ?");
    $stmt->execute([$orderId, $status]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};
$crfpMockPaid = static function (int $orderId, string $eventId, string $sessionId) use ($crfpSecret): array {
    $payload = ['event_id' => $eventId] + MockPayment::paidPayload($orderId, 1000, $sessionId);
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    return PaymentService::handleWebhook($raw, hash_hmac('sha256', $raw, $crfpSecret), $payload, Adapters::payment(), 'mock');
};
$crfpStripe = static fn(int $orderId, string $suffix, array $override = []): ?string => onPaymentCompleted(array_replace([
    'id' => 'cs_crfp_' . $suffix, 'payment_intent' => 'pi_crfp_' . $suffix, 'payment_status' => 'paid',
    'amount_total' => 1000, 'currency' => 'eur', 'metadata' => ['order_id' => $orderId],
], $override));

try {
    // 1. A bank transfer confirmed after the order expired: the money is recorded, the order stays
    // cancelled, and a manual refund is flagged with critical incidents instead of a silent "paid".
    $crfp1 = $crfpOrder('pending', 'pending', 'bank_transfer');
    OrderWorkflow::transition($crfp1, 'cancelled', 'CRFP expiry');
    assert_true(OfflinePaymentQueue::confirm($crfp1, 'crfp-admin', 'statement 7'), 'CRFP 1: a transfer for a cancelled order is still recorded');
    assert_equals(['cancelled', 'paid'], $crfpState($crfp1), 'CRFP 1: the cancelled order stays cancelled (its stock was released)');
    assert_equals([1000], $crfpLedger($crfp1, 'refund_manual_required'), 'CRFP 1: the ledger flags the whole amount for a manual refund');
    assert_equals(1, $crfpIncidents('payment.captured_after_cancel', $crfp1), 'CRFP 1: a critical captured-after-cancel incident is raised');
    assert_equals(1, $crfpIncidents('payment.order_refund_manual', $crfp1), 'CRFP 1: a critical manual-refund incident is raised');
    assert_true(!OfflinePaymentQueue::confirm($crfp1, 'crfp-admin'), 'CRFP 1: a second confirm records nothing');
    assert_equals([1, 1], [$crfpIncidents('payment.captured_after_cancel', $crfp1), $crfpIncidents('payment.order_refund_manual', $crfp1)], 'CRFP 1: a second confirm does not repeat the incidents');
    $crfp1Open = $crfpOrder('pending', 'pending', 'bank_transfer');
    assert_true(OfflinePaymentQueue::confirm($crfp1Open, 'crfp-admin'), 'CRFP 1: confirming an open transfer order still works');
    assert_equals(['paid', 'paid'], $crfpState($crfp1Open), 'CRFP 1: an open transfer order moves to paid');

    // 2. Manual payment transitions: a pending order's payment is not cancelled behind the order's back
    // (it would leave the expiry sweep with its stock reserved); a cancelled order may close its payment.
    $crfp2 = adminManualPaymentTransitions(['status' => 'pending', 'payment_status' => 'failed']);
    assert_true(in_array('paid', $crfp2, true) && !in_array('cancelled', $crfp2, true), 'CRFP 2: a failed payment of a pending order cannot be cancelled by hand');
    assert_true(!in_array('cancelled', adminManualPaymentTransitions(['status' => 'pending', 'payment_status' => 'pending']), true), 'CRFP 2: a pending payment of a pending order cannot be cancelled by hand');
    assert_true(in_array('cancelled', adminManualPaymentTransitions(['status' => 'cancelled', 'payment_status' => 'failed']), true), 'CRFP 2: a cancelled order may close its failed payment');
    assert_true(array_intersect(['refund_pending', 'partially_refunded', 'refunded'], adminManualPaymentTransitions(['status' => 'paid', 'payment_status' => 'paid'])) === [], 'CRFP 2: refunds stay on the refund ledger');
    $crfp2Expiry = $crfpOrder('pending', 'failed', 'mock', 'mock_crfp_2');
    $pdo->prepare("UPDATE orders SET created_at = '2000-01-01 00:00:00' WHERE id = ?")->execute([$crfp2Expiry]);
    Orders::expirePending(3600, 100, static fn(array $o): string => $o['payment_id'] === 'mock_crfp_2' ? 'cancelled' : 'created');
    assert_equals('cancelled', $crfpState($crfp2Expiry)[0], 'CRFP 2: a pending order with a failed payment is still swept by expiry');
    $crfp2Gateway = $crfpOrder('cancelled', 'cancelled', 'mock', 'mock_crfp_2c');
    PaymentService::transitionPayment($crfp2Gateway, 'paid', 'admin.paid', 0, 'crfp_2c_paid');
    PaymentService::settleCapture($crfp2Gateway, 'CRFP admin paid');
    assert_equals(['cancelled', 'refund_pending'], $crfpState($crfp2Gateway), 'CRFP 2: money marked paid on a cancelled gateway order goes to the refund ledger');
    assert_equals(1, $crfpIncidents('payment.captured_after_cancel', $crfp2Gateway), 'CRFP 2: and raises the critical incident');

    // 3. Cancelling a paid bank-transfer / COD order: no provider refund exists, so the ledger and a
    // critical incident record the manual refund instead of keeping the money silently.
    foreach (['bank_transfer', 'cod'] as $crfp3Method) {
        $crfp3 = $crfpOrder('paid', 'paid', $crfp3Method, '', 2500);
        OrderWorkflow::transition($crfp3, 'cancelled', 'CRFP cancel paid offline');
        assert_equals(['cancelled', 'paid'], $crfpState($crfp3), "CRFP 3 {$crfp3Method}: the cancelled order keeps the recorded payment until refunded by hand");
        assert_equals([2500], $crfpLedger($crfp3, 'refund_manual_required'), "CRFP 3 {$crfp3Method}: the refundable balance is flagged for a manual refund");
        assert_equals(1, $crfpIncidents('payment.order_refund_manual', $crfp3), "CRFP 3 {$crfp3Method}: a critical manual-refund incident is raised");
        assert_equals([], PaymentService::orderRefundJobsWithin($crfp3, 'again'), "CRFP 3 {$crfp3Method}: no provider refund job is invented");
        assert_equals(1, $crfpIncidents('payment.order_refund_manual', $crfp3), "CRFP 3 {$crfp3Method}: the flag is raised once per order");
    }

    // 4. A second capture on a paid order: a redelivery of the same payment stays quiet, a payment
    // under another provider reference is money taken twice and raises an incident.
    $crfp4 = $crfpOrder('pending', 'pending', 'mock', 'mock_crfp_4');
    $crfpMockPaid($crfp4, 'crfp4_evt_1', 'mock_crfp_4');
    assert_equals(['paid', 'paid'], $crfpState($crfp4), 'CRFP 4: the first capture pays the order');
    $crfpMockPaid($crfp4, 'crfp4_evt_2', 'mock_crfp_4');
    assert_equals(0, $crfpIncidents('payment.duplicate_capture', $crfp4), 'CRFP 4: a redelivered capture of the same payment raises nothing');
    $crfpMockPaid($crfp4, 'crfp4_evt_3', 'mock_crfp_4_second');
    assert_equals(1, $crfpIncidents('payment.duplicate_capture', $crfp4), 'CRFP 4: a capture under another reference raises a critical incident');
    assert_equals(['paid', 'paid'], $crfpState($crfp4), 'CRFP 4: the duplicate capture changes no state');
    $crfp4s = $crfpOrder('pending', 'pending', 'card', 'cs_crfp_4s');
    $crfpStripe($crfp4s, '4s');
    $crfpStripe($crfp4s, '4s');
    assert_equals([['paid', 'paid'], 0], [$crfpState($crfp4s), $crfpIncidents('payment.duplicate_capture', $crfp4s)], 'CRFP 4 Stripe: a redelivered session is not a duplicate');
    $crfpStripe($crfp4s, '4s_second');
    assert_equals(1, $crfpIncidents('payment.duplicate_capture', $crfp4s), 'CRFP 4 Stripe: a second paid session raises a critical incident');
    assert_equals('pi_crfp_4s', (string)Orders::byId($crfp4s)['payment_id'], 'CRFP 4 Stripe: the recorded payment reference (used for refunds) is kept');

    // 5. A Stripe session without amount or currency is unverified, never a payment.
    foreach (['amount_total' => 'amount', 'currency' => 'currency'] as $crfp5Field => $crfp5Label) {
        $crfp5 = $crfpOrder('pending', 'pending', 'card', 'cs_crfp_5' . $crfp5Label);
        $crfp5Session = ['id' => 'cs_crfp_5' . $crfp5Label, 'payment_intent' => 'pi_crfp_5' . $crfp5Label, 'payment_status' => 'paid', 'amount_total' => 1000, 'currency' => 'eur', 'metadata' => ['order_id' => $crfp5]];
        unset($crfp5Session[$crfp5Field]);
        $crfp5Error = '';
        try {
            onPaymentCompleted($crfp5Session);
        } catch (RuntimeException $e) {
            $crfp5Error = $e->getMessage();
        }
        assert_true(str_contains($crfp5Error, 'missing'), "CRFP 5: a Stripe session without {$crfp5Label} is rejected");
        assert_equals(['pending', 'pending'], $crfpState($crfp5), "CRFP 5: a Stripe session without {$crfp5Label} pays nothing");
    }
} catch (Throwable $e) {
    assert_true(false, 'CR follow-up payments regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $crfpAdapters->setValue(null, $crfpSavedAdapters);
}

// 6. ERP sync: a product the shop refuses (SKU of another catalog) is skipped and counted; stock,
// order push and status pull still run. Everything runs in a rolled-back transaction.
if (!class_exists('CrfpSyncAdapter', false)) {
    final class CrfpSyncAdapter implements IntegrationAdapter {
        public array $pushed = [];
        public int $categoryId = 0;
        public function pushOrder(array $order): string { $this->pushed[] = (int)$order['id']; return 'crfp-erp-' . $order['id']; }
        public function pullOrderStatus(string $externalId): string { return 'crfp-status'; }
        public function pushCustomer(array $customer): string { return ''; }
        public function healthCheck(): bool { return true; }
        public function pullProducts(?\DateTimeInterface $since = null): iterable {
            yield ['sku' => 'CRFP-FOREIGN', 'slug' => 'crfp-foreign', 'name' => 'Foreign', 'category_id' => $this->categoryId, 'price' => 1, 'compare_price' => null, 'stock' => 0];
            yield ['sku' => 'CRFP-OWN', 'slug' => 'crfp-own', 'name' => 'Own', 'category_id' => $this->categoryId, 'price' => 100, 'compare_price' => null, 'stock' => 3];
        }
        public function pushProduct(array $product): void {}
        public function pullStock(): iterable { return []; }
    }
}
$crfpLastSync = Cache::get('last_erp_sync');
$crfpSyncAdapter = new CrfpSyncAdapter();
$crfpSyncAdapter->categoryId = (int)$pdo->query("SELECT id FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$crfpAdapters->setValue(null, ['integration' => $crfpSyncAdapter] + $crfpSavedAdapters);
$pdo->beginTransaction();
try {
    $crfp6Order = $crfpOrder('paid', 'paid', 'mock', 'mock_crfp_6');
    $pdo->prepare("INSERT INTO products (sku, slug, name, category_id, price, stock, store_id) VALUES ('CRFP-FOREIGN', 'crfp-foreign-other', 'Other catalog', ?, 500, 1, 'crfp-other-catalog')")->execute([$crfpSyncAdapter->categoryId]);
    $crfp6 = Sync::run();
    assert_equals([1, 1], [$crfp6['products_updated'] ?? null, $crfp6['products_skipped'] ?? null], 'CRFP 6: the foreign SKU is skipped and counted, the own product is synced');
    assert_true(in_array($crfp6Order, $crfpSyncAdapter->pushed, true) && ($crfp6['orders_pushed'] ?? 0) >= 1, 'CRFP 6: orders are still pushed after a refused product');
    assert_true(Cache::get('last_erp_sync') !== $crfpLastSync, 'CRFP 6: the sync run completes and records its watermark');
    $crfp6Foreign = $pdo->query("SELECT store_id, name FROM products WHERE sku = 'CRFP-FOREIGN'")->fetch(PDO::FETCH_ASSOC);
    assert_equals(['crfp-other-catalog', 'Other catalog'], [$crfp6Foreign['store_id'], $crfp6Foreign['name']], 'CRFP 6: the other catalog product is untouched');
} catch (Throwable $e) {
    assert_true(false, 'CR follow-up ERP sync regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $crfpAdapters->setValue(null, $crfpSavedAdapters);
    if ($crfpLastSync === null) Cache::invalidate('kv/' . md5('last_erp_sync'));
    else Cache::set('last_erp_sync', $crfpLastSync);
    foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
        foreach (glob($crfpJobsDir . '/' . $sub . '/*.json') ?: [] as $file) @unlink($file);
        @rmdir($crfpJobsDir . '/' . $sub);
    }
    @rmdir($crfpJobsDir);
}
