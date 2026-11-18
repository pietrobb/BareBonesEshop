<?php

// Round 46 (idea #31): "Change payment → Paid" on the admin order detail must settle the order like a confirmed
// transfer: the order becomes Paid, its e-book is delivered and the expiry sweep no longer cancels it. The order
// history notes of both writers (admin payment, Awaiting payment confirm) read in the admin's language.
echo "\n--- Audit round 46: admin Paid settles the order (fulfilment, e-book, expiry), notes in the admin's language ---\n";

Bootstrap::initStoreContext('/', '', 'default');
JobQueue::setDir(test_fixture_path('r46p-jobs'));
$pdo = Db::get();
$r46pProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r46pSku = 'R46P-EBOOK-' . getmypid();
$r46pOld = Db::isSqlite() ? "datetime('now', '-20 days')" : 'DATE_SUB(NOW(), INTERVAL 20 DAY)';
$r46pOrder = static function (string $tag) use ($pdo, $r46pProduct, $r46pSku, $r46pOld): int {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_method_snapshot, shipping_method, store_id, created_at)
        VALUES (?, 'R46P', 'r46p@example.test', '', '{\"country\":\"SK\"}', 1500, 0, 0, 0, 1500, 'eur', 'pending', 'pending', 'bank_transfer', ?, 'digital', 'default', {$r46pOld})")
        ->execute(['R46P-' . $tag . '-' . getmypid(), json_encode(['code' => 'bank_transfer', 'provider' => 'manual', 'service_type' => 'bank_transfer'])]);
    $orderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, 'R46P e-book', 1, 1500, 1500)")
        ->execute([$orderId, $r46pProduct, $r46pSku]);
    return $orderId;
};
$r46pState = static fn(int $orderId): array => array_values($pdo->query("SELECT status, payment_status FROM orders WHERE id = {$orderId}")->fetch(PDO::FETCH_NUM) ?: []);
$r46pNote = static function (int $orderId) use ($pdo): string {
    $note = $pdo->query("SELECT note FROM audit_log WHERE entity = 'order_status' AND entity_id = {$orderId} AND new_value = 'paid' ORDER BY id DESC LIMIT 1")->fetchColumn();
    return $note === false ? '' : AuditLogLabels::note('order_status', $orderId, (string)$note);
};

try {
    $pdo->prepare("INSERT INTO digital_assets (sku, asset_key, storage_path) VALUES (?, 'default', 'r46p/book.pdf')")->execute([$r46pSku]);
    $r46pPaid = $r46pOrder('admin-paid');
    $r46pUnpaid = $r46pOrder('unpaid');
    $r46pConfirmed = $r46pOrder('confirmed');

    // 1. The handler routes "Paid" through the settling service, not a bare payment_status update.
    $r46pHandler = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php');
    assert_true((bool)preg_match('/function handleOrderPaymentTransition\(\).*?PaymentService::recordAdminPayment\(\$order, \$newStatus/s', $r46pHandler),
        'R46 admin paid: the order detail payment form records the payment through PaymentService::recordAdminPayment');

    // 2. Paid by hand (as the handler calls it): the order is Paid, its e-book delivery is queued and grants a download.
    JobQueue::pull(1000);
    assert_true(!PaymentService::recordAdminPayment(Orders::byId($r46pPaid), 'paid', 0, 'VS 4601', 'r46p-admin'), 'R46 admin paid: an open order is not flagged for a refund');
    assert_equals(['paid', 'paid'], $r46pState($r46pPaid), 'R46 admin paid: "Change payment → Paid" moves the order to Paid (was: payment Paid, order Pending)');
    $r46pJobs = array_values(array_filter(JobQueue::pull(1000), static fn(array $job): bool => ($job['type'] ?? '') === 'digital_fulfill'
        && (int)($job['payload']['order_id'] ?? 0) === $r46pPaid));
    assert_equals(1, count($r46pJobs), 'R46 admin paid: the e-book delivery job is queued for the order');
    assert_equals(1, count(DigitalDelivery::grant($r46pPaid)), 'R46 admin paid: the queued delivery grants the e-book download');
    assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM digital_entitlements WHERE order_id = {$r46pPaid} AND status = 'active'")->fetchColumn(),
        'R46 admin paid: the e-book entitlement is active');

    // 3. The expiry sweep (14 days for transfers) cancels the unpaid twin but leaves the orders paid in the admin alone.
    assert_true(OfflinePaymentQueue::confirm($r46pConfirmed, 'r46p-admin', 'VS 4602'), 'R46 admin paid: Awaiting payment confirms the transfer');
    Orders::expirePending(172800, 100, null, ['bank_transfer' => 14 * 86400]);
    assert_equals(['cancelled', 'paid', 'paid'], [$r46pState($r46pUnpaid)[0] ?? null, $r46pState($r46pPaid)[0] ?? null, $r46pState($r46pConfirmed)[0] ?? null],
        'R46 admin paid: the expiry sweep cancels an unpaid 20-day-old transfer, not the ones paid in the admin');

    // 4. Both notes read in the admin's language (the confirm note was written in Slovak before R34).
    I18n::setLocale('en');
    $r46pEnglish = [$r46pNote($r46pPaid), $r46pNote($r46pConfirmed)];
    I18n::setLocale('sk');
    $r46pSlovak = [$r46pNote($r46pPaid), $r46pNote($r46pConfirmed)];
    assert_equals([['Payment recorded in the admin: VS 4601', 'Bank transfer received: VS 4602'],
        ['Platba zaznamenaná v administrácii: VS 4601', 'Platba prevodom prijatá: VS 4602']], [$r46pEnglish, $r46pSlovak],
        'R46 admin paid: the order history notes of an admin payment and a confirmed transfer read in the admin\'s language');
} catch (Throwable $e) {
    assert_true(false, 'R46 admin paid (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    I18n::setLocale('sk');
}
