<?php

// Review round 17 (2026-11-05), merchant acceptance (medium): a payment recorded as "Paid" by hand after a failed card
// left the order Pending; an e-book whose line was refunded through a return still showed "Active" in the admin
// (its download was already refused); a credit note's variable symbol was the number of another invoice.
echo "\n--- Audit round 17: manual payment, refunded e-book in the admin, credit note variable symbol ---\n";

Bootstrap::initStoreContext('/', '', 'default');
JobQueue::setDir(test_fixture_path('r17a-jobs'));
if (!function_exists('adminCodeLabel')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
$r17aProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r17aOrder = static function (string $tag, string $status, string $paymentStatus, string $method) use ($pdo, $r17aProduct): array {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_method_snapshot, shipping_method, store_id, created_at)
        VALUES (?, 'R17A', 'r17a@example.test', '', '{\"country\":\"SK\"}', 1500, 0, 0, 0, 1500, 'eur', ?, ?, ?, ?, 'pickup', 'default', " . Db::now() . ")")
        ->execute(['R17A-' . $tag . '-' . getmypid(), $status, $paymentStatus, $method, json_encode(['code' => $method, 'provider' => $method === 'card' ? 'stripe' : 'manual', 'service_type' => $method])]);
    $orderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R17A-EBOOK', 'R17A e-book', 1, 1500, 1500)")
        ->execute([$orderId, $r17aProduct]);
    return ['id' => $orderId, 'item' => (int)$pdo->lastInsertId()];
};
$r17aState = static fn(int $orderId): array => array_values($pdo->query("SELECT status, payment_status FROM orders WHERE id = {$orderId}")->fetch(PDO::FETCH_NUM) ?: []);

// 1. "Paid" recorded by hand (a bank transfer after the card failed) pays the order like a gateway capture.
try {
    $r17aFailed = $r17aOrder('failed-card', 'pending', 'failed', 'card');
    $r17aCancelledFlag = PaymentService::recordAdminPayment(Orders::byId($r17aFailed['id']), 'paid', 0, 'R17A bank transfer', 'r17a-admin');
    assert_equals([false, ['paid', 'paid']], [$r17aCancelledFlag, $r17aState($r17aFailed['id'])],
        'R17 acceptance: a manual Paid after a failed card moves the order from Pending to Paid (was: payment Paid, order still Pending)');
    $r17aPending = $r17aOrder('pending-transfer', 'pending', 'pending', 'bank_transfer');
    PaymentService::recordAdminPayment(Orders::byId($r17aPending['id']), 'paid', 0, '', 'r17a-admin');
    assert_equals(['paid', 'paid'], $r17aState($r17aPending['id']), 'R17 acceptance: a bank transfer recorded as Paid by hand pays the pending order');
    // Money after the goods (cash on delivery): the delivered order keeps its status, only the payment is recorded.
    $r17aCod = $r17aOrder('cod-delivered', 'delivered', 'pending', 'cod');
    PaymentService::recordAdminPayment(Orders::byId($r17aCod['id']), 'paid', 0, 'R17A collected', 'r17a-admin');
    assert_equals(['delivered', 'paid'], $r17aState($r17aCod['id']), 'R17 acceptance: cash collected on a delivered order records the payment and leaves the order Delivered');
    // A cancelled order: the money is flagged for a manual refund, the order stays cancelled.
    $r17aCancelled = $r17aOrder('cancelled', 'cancelled', 'cancelled', 'bank_transfer');
    assert_equals([true, 'cancelled'], [PaymentService::recordAdminPayment(Orders::byId($r17aCancelled['id']), 'paid', 0, 'R17A late', 'r17a-admin'), $r17aState($r17aCancelled['id'])[0] ?? null],
        'R17 acceptance: money recorded on a cancelled order still goes to a manual refund and the order stays Cancelled');
} catch (Throwable $e) {
    assert_true(false, 'R17 acceptance manual payment (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// 2. An e-book whose line is on a return credit note: the admin shows its download as blocked, not Active.
try {
    $r17aBook = $r17aOrder('ebook', 'delivered', 'paid', 'card');
    $pdo->prepare("INSERT INTO digital_assets (sku, asset_key, storage_path) VALUES (?, 'default', 'r17a/book.pdf')")->execute(['R17A-EBOOK-' . getmypid()]);
    $r17aAsset = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO digital_entitlements (order_id, order_item_id, asset_id, token_hash, status) VALUES (?, ?, ?, ?, 'active')")
        ->execute([$r17aBook['id'], $r17aBook['item'], $r17aAsset, hash('sha256', 'r17a-' . getmypid())]);
    $r17aBadge = static function () use ($r17aBook): array {
        $row = DigitalDelivery::forOrder($r17aBook['id'])[0] ?? [];
        return [$row['status'] ?? null, $row['download_blocked'] ?? null];
    };
    assert_equals(['active', false], $r17aBadge(), 'R17 acceptance: a paid e-book entitlement is active and its download not blocked');
    $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, 1, 1, 1500, 'eur', 'succeeded', ?)")
        ->execute([$r17aBook['id'], 'r17a-refund-' . getmypid()]);
    $r17aRefund = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO credit_note_items (credit_note_id, refund_id, order_item_id, rma_item_id, sku, name, quantity, unit_gross, gross, net, vat, rate_basis_points)
        VALUES (1, ?, ?, ?, 'R17A-EBOOK', 'R17A e-book', 1, 1500, 1500, 1500, 0, 0)")->execute([$r17aRefund, $r17aBook['item'], 900000 + getmypid() % 99999]);
    assert_equals(['active', true], $r17aBadge(), 'R17 acceptance: after the return refund the entitlement row is still active, but its download is reported blocked');
    assert_equals(I18n::t('admin.entitlement.status.blocked'), adminCodeLabel('admin.entitlement.status.', 'blocked'),
        'R17 acceptance: the blocked badge has a label (not the raw code)');
    $r17aSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php');
    assert_true(str_contains($r17aSource, "\$entitlementStatus = \$entitlement['download_blocked'] ? 'blocked' : (string)\$entitlement['status'];"),
        'R17 acceptance: the order detail labels the entitlement by download_blocked');
    foreach (glob(dirname(TEST_CLI_DIR) . '/lang/*.php') ?: [] as $r17aLang) {
        assert_true(isset((require $r17aLang)['admin.entitlement.status.blocked']), 'R17 acceptance: ' . basename($r17aLang) . ' labels a blocked download');
    }
} catch (Throwable $e) {
    assert_true(false, 'R17 acceptance e-book badge (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// 3. The credit note's variable symbol is never the number of an invoice (same digits, other prefix).
$r17aInvoice = ['type' => 'invoice', 'order_id' => 7, 'number' => '20260007'];
$r17aCredit = ['type' => 'credit_note', 'order_id' => 7, 'number' => 'CN-20260007'];
$r17aCreditVs = InvoiceService::variableSymbol($r17aCredit);
assert_true($r17aCreditVs !== '20260007' && $r17aCreditVs !== InvoiceService::variableSymbol($r17aInvoice) && preg_match('/^9\d{1,9}$/', $r17aCreditVs) === 1,
    "R17 acceptance: credit note CN-20260007 has a variable symbol of its own, not invoice 20260007's number ({$r17aCreditVs})");
assert_equals('9026000099', InvoiceService::variableSymbol(['type' => 'credit_note', 'order_id' => 7, 'number' => 'CN-2026000099']),
    'R17 acceptance: the credit note symbol stays within ten digits');
