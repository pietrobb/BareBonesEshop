<?php

// TEST 60: Reusable digital commerce core
// ============================================================
echo "\n--- Test 60: Digital commerce core ---\n";

$digitalDir = TEST_CLI_DIR . '/../db/digital-test-assets';
if (!is_dir($digitalDir)) mkdir($digitalDir, 0750, true);
file_put_contents($digitalDir . '/guide.pdf', '%PDF-1.4 digital test');
DigitalDelivery::setConfigForTesting([
    'digital_assets_base_dir' => $digitalDir,
    'app_url' => 'https://shop.example',
]);

$pdo->exec("INSERT INTO products (id, category_id, name, slug, sku, product_type, price, currency, status, stock)
    VALUES (9001, 1, 'Digital Guide', 'digital-guide', 'DIGI-GUIDE', 'downloadable', 999, 'eur', 'active', 0)");
$pdo->exec("INSERT INTO products (id, category_id, name, slug, sku, product_type, price, currency, status, stock)
    VALUES (9002, 1, 'Physical Low Stock', 'physical-low-stock', 'PHYS-LOW', 'simple', 999, 'eur', 'active', 1)");
$lowStockWarehouse = (int)$pdo->query("SELECT id FROM warehouses ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', :wh, 'PHYS-LOW', 1, 0), ('default', :wh2, 'DIGI-GUIDE', 1, 0)")
    ->execute([':wh' => $lowStockWarehouse, ':wh2' => $lowStockWarehouse]);
$lowStockSummary = StockAlerts::summary(1000);
$lowStockSkus = array_column($lowStockSummary['products'], 'sku');
assert_true(in_array('PHYS-LOW', $lowStockSkus, true), 'Dashboard low stock: physical products remain included');
assert_true(!in_array('DIGI-GUIDE', $lowStockSkus, true), 'Dashboard low stock: downloadable products are excluded');
$pdo->exec("UPDATE products SET product_type = 'digital' WHERE id = 9001");
$lowStockSkus = array_column(StockAlerts::summary(1000)['products'], 'sku');
assert_true(!in_array('DIGI-GUIDE', $lowStockSkus, true), 'Dashboard low stock: digital alias products are excluded');
$pdo->exec("UPDATE products SET product_type = 'downloadable' WHERE id = 9001");
$pdo->exec("DELETE FROM warehouse_stock WHERE sku IN ('PHYS-LOW', 'DIGI-GUIDE')");
$pdo->exec("DELETE FROM products WHERE id = 9002");
$pdo->exec("INSERT INTO digital_assets
    (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
    VALUES ('DIGI-GUIDE', 'pdf', 'PDF guide', 'guide.pdf', 'guide.pdf', 'application/pdf', 2, 3600, 1)");
$_SESSION['cart'] = [];
Cart::add(9001, null, 5);
$digitalCart = Cart::enriched();
assert_equals(1, (int)$digitalCart['items'][0]['qty'], 'Digital: cart normalizes quantity to one');
assert_equals(1, (int)$_SESSION['cart']['p_9001']['qty'], 'Digital: normalized quantity persists in session');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['country' => 'SK', 'subtotal' => '999'];
$digitalRates = apiShippingRates();
assert_equals(1, count($digitalRates['methods']), 'Digital checkout: exposes exactly one delivery method');
assert_equals('digital', (string)$digitalRates['methods'][0]['method_code'], 'Digital checkout: uses electronic delivery');
assert_equals(0, (int)$digitalRates['methods'][0]['price'], 'Digital checkout: electronic delivery is free');
$_GET = ['country' => 'SK', 'currency' => 'eur', 'amount' => '999', 'shipping_method' => 'packeta'];
$digitalPayments = apiPaymentMethods();
$digitalPaymentCodes = array_column($digitalPayments['methods'], 'code');
assert_true(!in_array('cod', $digitalPaymentCodes, true), 'Digital checkout: COD is never offered');
assert_true(in_array('card', $digitalPaymentCodes, true), 'Digital checkout: card payment remains available');

ConsentService::setDefinitionsForTesting([]);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.60';
$_SESSION['csrf_token'] = 'digital-checkout-test';
$_POST = [
    'csrf_token' => 'digital-checkout-test',
    'name' => 'Digital Buyer',
    'email' => 'digital-checkout@example.com',
    'country' => 'SK',
    'shipping_method' => 'balik-adresa',
    'payment_method' => 'cod',
];
$digitalCod = apiCheckout();
assert_true(empty($digitalCod['success']), 'Digital checkout: server rejects forged COD and physical shipping');

$pdo->exec("UPDATE products SET price = 0 WHERE id = 9001");
Cart::clear();
Cart::add(9001, null, 1);
$_SERVER['REMOTE_ADDR'] = '127.0.0.61';
$_SESSION['csrf_token'] = 'free-digital-checkout-test';
$_POST = [
    'csrf_token' => 'free-digital-checkout-test',
    'name' => 'Free Digital Buyer',
    'email' => 'free-digital@example.com',
    'country' => 'SK',
    'shipping_method' => 'digital',
    'payment_method' => 'free',
];
$freeDigitalCheckout = apiCheckout();
assert_true(!empty($freeDigitalCheckout['success']), 'Digital checkout: zero-total order succeeds without a payment gateway');
$freeDigitalOrder = Orders::byId((int)($freeDigitalCheckout['order_id'] ?? 0));
assert_equals(0, (int)($freeDigitalOrder['shipping_cost'] ?? -1), 'Digital checkout: zero-total order has no shipping charge');
assert_equals('digital', (string)($freeDigitalOrder['shipping_method'] ?? ''), 'Digital checkout: order stores electronic delivery snapshot');
assert_equals('free', (string)($freeDigitalOrder['payment_method'] ?? ''), 'Digital checkout: zero-total order stores no-payment method');
assert_equals('paid', (string)($freeDigitalOrder['payment_status'] ?? ''), 'Digital checkout: zero-total order is immediately paid');
assert_equals('paid', (string)($freeDigitalOrder['status'] ?? ''), 'Digital checkout: zero-total order triggers fulfillment');
$createdNotifyJobs = array_filter(JobQueue::pull(500), static fn(array $job): bool => ($job['type'] ?? '') === 'merchant_notify'
    && (int)($job['payload']['order_id'] ?? 0) === (int)$freeDigitalOrder['id'] && ($job['payload']['event'] ?? '') === 'created');
assert_equals(1, count($createdNotifyJobs), 'Merchant notification: every new checkout order queues exactly one created notification');
$createdMail = MailService::render('merchant_order_created', 'sk', [
    'order_number' => 'BB-NEW-1', 'customer_name' => 'Ján', 'customer_email' => 'jan@example.com', 'order_total' => '12,00 €',
    'payment_method' => 'Bankový prevod', 'payment_status' => 'pending', 'awaiting_payment' => true,
    'admin_url' => 'https://shop.example/admin.php?p=order&id=7', 'items' => [['quantity' => 1, 'name' => 'Kniha', 'sku' => 'K1', 'total' => '12,00 €']],
]);
assert_true(str_contains($createdMail['subject'], 'BB-NEW-1') && str_contains($createdMail['subject'], 'čaká na platbu'), 'Merchant notification: unpaid order subject says it awaits payment');
assert_true(str_contains($createdMail['html'], 'Bankový prevod') && str_contains($createdMail['html'], 'admin.php?p=order&amp;id=7'), 'Merchant notification: created mail shows payment method and admin link');
assert_true(str_contains($createdMail['text'], 'Detail: https://shop.example/admin.php?p=order&id=7') && !str_contains($createdMail['text'], '&amp;'), 'Merchant notification: plain-text admin link is not HTML-escaped');
$subjectMail = MailService::render('merchant_order_created', 'sk', ['order_number' => "A&B <1>\r\nBcc: x@example.com"]);
assert_true(str_contains($subjectMail['subject'], 'A&B <1>') && !preg_match('/[\r\n]/', $subjectMail['subject']), 'Mail: subject is plain text on one line');
$paidCreatedMail = MailService::render('merchant_order_created', 'en', ['order_number' => 'BB-NEW-2', 'awaiting_payment' => false]);
assert_true(!str_contains($paidCreatedMail['subject'], 'awaiting'), 'Merchant notification: paid order subject has no awaiting-payment marker');
$pdo->exec("UPDATE products SET price = 999 WHERE id = 9001");
Cart::clear();
Cart::add(9001, null, 1);
$_SERVER['REMOTE_ADDR'] = '127.0.0.62';
$_SESSION['csrf_token'] = 'transfer-digital-checkout-test';
$_POST = [
    'csrf_token' => 'transfer-digital-checkout-test', 'name' => 'Transfer Buyer', 'email' => 'transfer@example.com',
    'country' => 'SK', 'shipping_method' => 'digital', 'payment_method' => 'bank_transfer',
];
$transferCheckout = apiCheckout();
$transferOrderId = (int)($transferCheckout['order_id'] ?? 0);
assert_true(!empty($transferCheckout['success']) && $transferOrderId > 0, 'Awaiting payment: digital bank-transfer checkout succeeds');
assert_equals('pending', (string)Orders::byId($transferOrderId)['payment_status'], 'Awaiting payment: transfer order starts unpaid');
$awaitingIds = array_map('intval', array_column(OfflinePaymentQueue::awaiting(), 'id'));
assert_true(in_array($transferOrderId, $awaitingIds, true), 'Awaiting payment: unpaid transfer is listed');
assert_true(!in_array((int)$freeDigitalOrder['id'], $awaitingIds, true), 'Awaiting payment: paid orders are not listed');
$awaitingRow = array_values(array_filter(OfflinePaymentQueue::awaiting(), static fn(array $o): bool => (int)$o['id'] === $transferOrderId))[0] ?? [];
assert_true(isset($awaitingRow['age_hours']) && (int)$awaitingRow['age_hours'] >= 0, 'Awaiting payment: row exposes order age');
JobQueue::pull(500);
assert_true(OfflinePaymentQueue::confirm($transferOrderId, 'tester', 'výpis 1'), 'Awaiting payment: confirm records the transfer');
$confirmedTransfer = Orders::byId($transferOrderId);
assert_equals('paid', (string)$confirmedTransfer['payment_status'], 'Awaiting payment: confirm marks payment paid');
assert_equals('paid', (string)$confirmedTransfer['status'], 'Awaiting payment: confirm moves order to paid');
$transferJobs = array_filter(JobQueue::pull(500), static fn(array $job): bool => ($job['type'] ?? '') === 'digital_fulfill'
    && (int)($job['payload']['order_id'] ?? 0) === $transferOrderId);
assert_equals(1, count($transferJobs), 'Awaiting payment: confirm releases digital delivery exactly once');
assert_true(!OfflinePaymentQueue::confirm($transferOrderId, 'tester'), 'Awaiting payment: second confirm is a no-op');
assert_true(!in_array($transferOrderId, array_map('intval', array_column(OfflinePaymentQueue::awaiting(), 'id')), true), 'Awaiting payment: confirmed order leaves the list');
$cardOrderRejected = false;
try { OfflinePaymentQueue::confirm($digitalOrderId ?? Orders::create([[
    'product_id' => 9001, 'variant_id' => null, 'qty' => 1, 'price' => 999, 'sku' => 'DIGI-GUIDE', 'name' => 'Digital Guide', 'variant_name' => '',
]], ['name' => 'Card Buyer', 'email' => 'card@example.com', 'currency' => 'eur', 'shipping_cost' => 0, 'tax_amount' => 0], 'card'), 'tester'); }
catch (LogicException $e) { $cardOrderRejected = true; }
assert_true($cardOrderRejected, 'Awaiting payment: card orders cannot be confirmed as transfers');
$recoverySource = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Recovery.php');
assert_true(str_contains($recoverySource, "adminRequire('orders.payments')"), 'Awaiting payment: confirm action requires orders.payments');

// Abandoned carts: capture, conversion, exactly one reminder, opt-out, restore.
$acAge = static function (string $sid, int $hours) use ($pdo): void {
    $pdo->prepare("UPDATE abandoned_carts SET created_at = datetime('now', ?), updated_at = datetime('now', ?) WHERE session_id = ?")
        ->execute(["-{$hours} hours", "-{$hours} hours", $sid]);
};
$acRow = static fn(string $sid): array => $pdo->query("SELECT * FROM abandoned_carts WHERE session_id = " . $pdo->quote($sid))->fetch(PDO::FETCH_ASSOC) ?: [];
$acReminders = static fn(): array => array_values(array_filter(JobQueue::pull(500), static fn(array $job): bool => ($job['type'] ?? '') === 'email_send'
    && ($job['payload']['template'] ?? '') === 'cart_reminder'));
$pdo->exec("DELETE FROM abandoned_carts");
Cart::clear();
Cart::add(9001, null, 1);
AbandonedCarts::capture('Shopper@Example.com', 'ac-sess-1');
AbandonedCarts::capture('shopper@example.com', 'ac-sess-1');
$acFirst = $acRow('ac-sess-1');
assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM abandoned_carts WHERE session_id = 'ac-sess-1'")->fetchColumn(), 'Abandoned carts: one row per session');
assert_equals('shopper@example.com', (string)($acFirst['customer_email'] ?? ''), 'Abandoned carts: e-mail is normalized');
assert_equals(999, (int)($acFirst['cart_value'] ?? 0), 'Abandoned carts: cart value is snapshotted');
assert_true((bool)preg_match('/^[a-f0-9]{32}$/', (string)($acFirst['recovery_token'] ?? '')), 'Abandoned carts: recovery token is issued');
assert_equals('Digital Guide', trim((string)(AbandonedCarts::list()[0]['items'][0]['name'] ?? '')), 'Abandoned carts: list shows item names');
$acAge('ac-sess-1', 30);
$acNoUrl = false;
try { AbandonedCarts::sweep([]); } catch (RuntimeException $e) { $acNoUrl = true; }
assert_true($acNoUrl, 'Cart reminder: refuses to send without an absolute app_url');
JobQueue::pull(500);
assert_equals(1, AbandonedCarts::sweep(['base_url' => 'https://shop.example/']), 'Cart reminder: due cart gets a reminder');
$acJobs = $acReminders();
assert_equals(1, count($acJobs), 'Cart reminder: exactly one mail job is queued');
assert_equals('shopper@example.com', (string)($acJobs[0]['payload']['to'] ?? ''), 'Cart reminder: goes to the cart e-mail');
$acVars = (array)($acJobs[0]['payload']['vars'] ?? []);
assert_equals('https://shop.example/?cart_recover=' . $acFirst['recovery_token'], (string)($acVars['cart_url'] ?? ''), 'Cart reminder: absolute recovery link');
assert_equals('https://shop.example/?cart_unsubscribe=' . $acFirst['recovery_token'], (string)($acVars['unsubscribe_url'] ?? ''), 'Cart reminder: absolute opt-out link');
assert_equals(0, AbandonedCarts::sweep(['base_url' => 'https://shop.example']), 'Cart reminder: second sweep sends nothing');
AbandonedCarts::capture('shopper@example.com', 'ac-sess-1');
$acAge('ac-sess-1', 30);
assert_equals(0, AbandonedCarts::sweep(['base_url' => 'https://shop.example']), 'Cart reminder: cart activity never re-arms a sent reminder');
assert_equals(AbandonedCarts::STATUS_SENT, (string)$acRow('ac-sess-1')['reminder_status'], 'Cart reminder: status records the sent reminder');

AbandonedCarts::capture('converted@example.com', 'ac-sess-2');
AbandonedCarts::markConverted($transferOrderId, 'ac-sess-2');
$acAge('order:' . $transferOrderId, 30);
$acAbandonedEmails = array_column(AbandonedCarts::list('abandoned'), 'customer_email');
$acConverted = array_values(array_filter(AbandonedCarts::list('converted'), static fn(array $c): bool => $c['customer_email'] === 'converted@example.com'));
assert_true(!in_array('converted@example.com', $acAbandonedEmails, true), 'Abandoned carts: converted cart is not listed as abandoned');
assert_equals($transferOrderId, (int)($acConverted[0]['order_id'] ?? 0), 'Abandoned carts: converted cart links its order');
AbandonedCarts::capture('converted@example.com', 'ac-sess-2');
assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM abandoned_carts WHERE session_id = 'ac-sess-2' AND order_id IS NULL")->fetchColumn(), 'Abandoned carts: a new cart after an order starts a fresh row');
$pdo->exec("DELETE FROM abandoned_carts WHERE session_id = 'ac-sess-2'");

AbandonedCarts::capture('transfer@example.com', 'ac-sess-3');
$acAge('ac-sess-3', 30);
AbandonedCarts::capture('optout@example.com', 'ac-sess-4');
$acAge('ac-sess-4', 30);
assert_true(AbandonedCarts::unsubscribe((string)$acRow('ac-sess-4')['recovery_token']), 'Cart reminder: opt-out link is accepted');
assert_true(!AbandonedCarts::unsubscribe('not-a-token'), 'Cart reminder: invalid opt-out token is rejected');
assert_true(AbandonedCarts::isSuppressed('OptOut@example.com'), 'Cart reminder: opt-out list is case-insensitive');
AbandonedCarts::capture('stale@example.com', 'ac-sess-5');
$acAge('ac-sess-5', 200);
assert_equals(0, AbandonedCarts::sweep(['base_url' => 'https://shop.example']), 'Cart reminder: ordered, opted-out and stale carts get no mail');
assert_equals(['shopper@example.com'], array_map(static fn(array $job): string => (string)$job['payload']['to'], $acReminders()), 'Cart reminder: no mail job for skipped carts');
assert_equals(AbandonedCarts::STATUS_ORDERED, (string)$acRow('ac-sess-3')['reminder_status'], 'Cart reminder: later order by the same e-mail closes the cart');
assert_equals($transferOrderId, (int)$acRow('ac-sess-3')['order_id'], 'Cart reminder: later order is linked to the cart');
assert_equals(AbandonedCarts::STATUS_SUPPRESSED, (string)$acRow('ac-sess-4')['reminder_status'], 'Cart reminder: opted-out cart is closed');
assert_true(($acRow('ac-sess-5')['reminder_status'] ?? null) === null, 'Cart reminder: carts older than max_age are never mailed');

Cart::clear();
assert_true(AbandonedCarts::restore((string)$acFirst['recovery_token']) && count(Cart::get()) === 1, 'Cart reminder: recovery link refills an empty cart');
assert_true(!AbandonedCarts::restore('0123456789abcdef0123456789abcdef'), 'Cart reminder: unknown recovery token is rejected');
Cart::clear();
$acMail = MailService::render('cart_reminder', 'sk', $acVars + ['cart_total' => '9,99 €']);
assert_true(str_contains($acMail['subject'], 'košíku') && str_contains($acMail['html'], $acVars['unsubscribe_url']) && str_contains($acMail['text'], $acVars['cart_url']), 'Cart reminder: sk mail carries recovery and opt-out links');
foreach (['cs', 'de', 'en', 'hu', 'pl'] as $acLocale) {
    assert_true(is_file(TEST_CLI_DIR . "/../templates/emails/{$acLocale}/cart_reminder.html"), "Cart reminder: {$acLocale} template exists");
}
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Recovery.php';
$_GET = ['filter' => 'all'];
ob_start();
pageAbandonedCarts();
$acAdminHtml = (string)ob_get_clean();
$_GET = [];
assert_true(str_contains($acAdminHtml, 'shopper@example.com') && str_contains($acAdminHtml, 'pripomienka odoslaná') && str_contains($acAdminHtml, 'admin.php?p=order&id=' . $transferOrderId), 'Abandoned carts: admin list shows e-mail, reminder state and order link');
assert_true(str_contains((string)file_get_contents(TEST_CLI_DIR . '/../src/Http/ApiHandlers.php'), 'AbandonedCarts::markConverted($orderId)'), 'Abandoned carts: checkout marks the session cart converted');
$acWorkerSource = (string)file_get_contents(TEST_CLI_DIR . '/worker.php');
assert_true(str_contains($acWorkerSource, 'AbandonedCarts::sweep($reminderCfg') && str_contains($acWorkerSource, "AbandonedCarts::isSuppressed(\$to)"), 'Cart reminder: worker sweeps every run and re-checks opt-out before sending');
$pdo->exec("DELETE FROM abandoned_carts");
$pdo->exec("DELETE FROM email_suppressions");
ConsentService::setDefinitionsForTesting(null);
$_GET = [];
$_POST = [];
Cart::clear();

$digitalOrderId = Orders::create([[
    'product_id' => 9001, 'variant_id' => null, 'qty' => 5, 'price' => 999,
    'sku' => 'DIGI-GUIDE', 'name' => 'Digital Guide', 'variant_name' => '',
]], [
    'name' => 'Digital Buyer', 'email' => 'digital@example.com', 'currency' => 'eur',
    'shipping_cost' => 0, 'tax_amount' => 0,
], 'card');
$digitalOrderItem = Orders::items($digitalOrderId)[0];
assert_equals(1, (int)$digitalOrderItem['quantity'], 'Digital: order boundary clamps quantity to one');
assert_equals(999, (int)Orders::byId($digitalOrderId)['total'], 'Digital: customer is charged for one copy only');
assert_equals(0, (int)$pdo->query("SELECT stock FROM products WHERE id=9001")->fetchColumn(), 'Digital: order does not decrement stock');

$stripeAdapter = new StripePayment([]);
$stripeLineItems = (new ReflectionMethod(StripePayment::class, 'buildLineItems'))->invoke($stripeAdapter, [
    'order_number' => 'DISCOUNTED-1', 'currency' => 'eur', 'total' => 899,
    'shipping_cost' => 0,
    'items' => [['name' => 'Digital Guide', 'quantity' => 1, 'unit_price' => 999]],
]);
assert_equals(1, count($stripeLineItems), 'Stripe: adjusted order uses one authoritative total line');
assert_equals(899, (int)$stripeLineItems[0]['price_data']['unit_amount'], 'Stripe: coupon/fee-adjusted line equals orders.total');

PaymentService::updatePaymentStatus($digitalOrderId, 'paid');
OrderWorkflow::transition($digitalOrderId, 'paid');
$digitalJobs = array_column(FulfillmentDispatcher::dispatch('paid', Orders::byId($digitalOrderId)), 0);
assert_true(in_array('digital_fulfill', $digitalJobs, true), 'Digital: paid event dispatches fulfillment job');
$links = DigitalDelivery::grant($digitalOrderId);
assert_equals(1, count($links), 'Digital: one entitlement issued for mapped asset');
$token = (string)parse_url($links[0]['url'], PHP_URL_QUERY);
parse_str($token, $tokenQuery);
$rawToken = (string)($tokenQuery['token'] ?? '');
assert_equals(64, strlen($rawToken), 'Digital: raw token is 64 hex characters');
$storedHash = (string)$pdo->query("SELECT token_hash FROM digital_entitlements WHERE order_id={$digitalOrderId} ORDER BY id DESC LIMIT 1")->fetchColumn();
assert_equals(hash('sha256', $rawToken), $storedHash, 'Digital: database stores only SHA-256 token hash');
assert_true($storedHash !== $rawToken, 'Digital: raw token is not persisted');
$resolved = DigitalDelivery::resolve($rawToken);
assert_true($resolved !== null, 'Digital: valid paid token resolves');
assert_equals(realpath($digitalDir . '/guide.pdf'), DigitalDelivery::resolvedPath($resolved), 'Digital: asset resolves only below configured base directory');
assert_true(DigitalDelivery::consume((int)$resolved['id']), 'Digital: first download slot consumed atomically');
assert_true(DigitalDelivery::consume((int)$resolved['id']), 'Digital: second download slot consumed atomically');
assert_true(!DigitalDelivery::consume((int)$resolved['id']), 'Digital: configured download limit enforced');
DigitalDelivery::revokeOrder($digitalOrderId, 'test_refund');
assert_true(DigitalDelivery::resolve($rawToken) === null, 'Digital: revoked token no longer resolves');

ConsentService::setDefinitionsForTesting([
    'digital_delivery' => [
        'version' => '2026-07-13', 'required' => true,
        'text' => 'Immediate digital delivery consent', 'product_types' => ['downloadable'],
    ],
]);
$consentContext = ['country' => 'SK', 'product_types' => ['downloadable']];
assert_true(isset(ConsentService::validate([], $consentContext)['digital_delivery']), 'Consent: required digital consent is enforced');
assert_equals([], ConsentService::validate(['digital_delivery' => true], $consentContext), 'Consent: explicit boolean acceptance passes');
ConsentService::record($digitalOrderId, ['digital_delivery' => true], $consentContext, '127.0.0.1', 'Test Agent');
$consentRows = ConsentService::forOrder($digitalOrderId);
assert_equals('2026-07-13', (string)$consentRows[0]['version'], 'Consent: immutable version snapshot stored');
assert_equals('Immediate digital delivery consent', (string)$consentRows[0]['consent_text'], 'Consent: immutable text snapshot stored');
assert_true((string)$consentRows[0]['ip_hash'] !== '127.0.0.1', 'Consent: IP stored as privacy-preserving hash');
ConsentService::setDefinitionsForTesting(null);

CheckoutSession::bind($digitalOrderId, 'cs_test_digital_1', 'stripe', 'browser-session-a');
assert_equals($digitalOrderId, CheckoutSession::resolveOrderId('cs_test_digital_1'), 'Checkout binding: session resolves order');
assert_true(CheckoutSession::verifyOwnership($digitalOrderId, 'cs_test_digital_1', 'browser-session-a'), 'Checkout binding: ownership works before webhook race completes');
CheckoutSession::markCompleted('cs_test_digital_1');
assert_true(CheckoutSession::verifyOwnership($digitalOrderId, 'cs_test_digital_1', 'browser-session-a'), 'Checkout binding: correct browser session owns result');
assert_true(!CheckoutSession::verifyOwnership($digitalOrderId, 'cs_test_digital_1', 'browser-session-b'), 'Checkout binding: foreign browser session rejected');
$pdo->exec("INSERT INTO variants (id, product_id, sku, name, stock, availability) VALUES (9001, 9001, 'TEST-COMING-SOON', 'Future', 5, 'coming_soon')");
assert_true(!VariantAvailability::isPurchasable(9001, 9001), 'Variants: coming_soon is rejected server-side');
VariantAvailability::setStatus(9001, VariantAvailability::ACTIVE);
assert_true(VariantAvailability::isPurchasable(9001, 9001), 'Variants: active digital variant is purchasable');
$digitalCommerceMig->up($pdo, true);
$variantIndexes = array_column($pdo->query("PRAGMA index_list(variants)")->fetchAll(PDO::FETCH_ASSOC), 'name');
assert_true(in_array('idx_variants_availability', $variantIndexes, true), 'Migration: index creation is idempotent on SQLite');

$digitalMail = MailService::render('digital_delivery', 'en', [
    'order_number' => 'DIGI-1', 'customer_name' => 'Buyer',
    'download_links' => [['label' => 'Guide', 'url' => 'https://shop.example/download', 'validity' => '7 days']],
]);
assert_true(str_contains($digitalMail['html'], 'https://shop.example/download'), 'Digital: delivery email renders secure link');

$pdo->prepare("INSERT INTO merchant_notifications
    (order_id, event_key, recipient, status, updated_at)
    VALUES (?, 'stale_test', 'merchant@example.com', 'processing', datetime('now', '-901 seconds'))")
    ->execute([$digitalOrderId]);
$merchantClaim = new ReflectionMethod(MerchantNotifier::class, 'claim');
assert_true($merchantClaim->invoke(null, $digitalOrderId, 'stale_test', 'merchant@example.com'), 'Merchant notification: stale processing claim is recovered');
assert_true(!$merchantClaim->invoke(null, $digitalOrderId, 'stale_test', 'merchant@example.com'), 'Merchant notification: fresh processing claim is not duplicated');

Orders::setPaymentId($digitalOrderId, 'pi_refund_progression');
onChargeRefunded(['id' => 'ch_refund_progression', 'payment_intent' => 'pi_refund_progression', 'amount_refunded' => 400]);
assert_equals('partially_refunded', (string)Orders::byId($digitalOrderId)['payment_status'], 'Stripe refund: first cumulative amount records partial refund');
assert_equals(400, PaymentService::refundedAmount($digitalOrderId), 'Stripe refund: first cumulative event records its delta');
onChargeRefunded(['id' => 'ch_refund_progression', 'payment_intent' => 'pi_refund_progression', 'amount_refunded' => 700]);
assert_equals('partially_refunded', (string)Orders::byId($digitalOrderId)['payment_status'], 'Stripe refund: increasing partial cumulative amount preserves partial status');
assert_equals(700, PaymentService::refundedAmount($digitalOrderId), 'Stripe refund: repeated partial event records only the cumulative delta');
onChargeRefunded(['id' => 'ch_refund_progression', 'payment_intent' => 'pi_refund_progression', 'amount_refunded' => 999]);
assert_equals(999, PaymentService::refundedAmount($digitalOrderId), 'Stripe refund: final cumulative event totals exactly the order refund');
$fullyRefundedOrder = Orders::byId($digitalOrderId);
assert_equals('refunded', (string)$fullyRefundedOrder['payment_status'], 'Stripe refund: later full amount uses a distinct idempotency key');
assert_equals('refunded', (string)$fullyRefundedOrder['status'], 'Stripe refund: full cumulative refund closes the order');

DigitalDelivery::setConfigForTesting(null);
@unlink($digitalDir . '/guide.pdf');
@rmdir($digitalDir);

// ============================================================
// TEST: G6 remaining security findings
// ============================================================
echo "\n--- G6: SSRF, rich HTML, refunds, RBAC, and error handling ---\n";

$unsafeRichHtml = '<div class="layout"><p onclick="steal()">Hello <strong>world</strong>'
    . '<script>alert(1)</script><img src="https://tracker.example/pixel">'
    . '<a href="jav&#x61;script:alert(1)" style="color:red">bad</a>'
    . '<a href="https://docs.example/path" title="Docs">safe</a></p>'
    . '<form action="https://evil.example"><input name="secret"></form></div>';
$safeRichHtml = Security::sanitizeRichHtml($unsafeRichHtml);
assert_true(str_contains($safeRichHtml, '<p>') && str_contains($safeRichHtml, '<strong>world</strong>'), 'G6 rich HTML: allowed formatting survives');
foreach (['<script', '<img', '<form', '<input', 'onclick', 'style=', 'javascript:'] as $blockedHtml) {
    assert_true(!str_contains(strtolower($safeRichHtml), $blockedHtml), "G6 rich HTML: {$blockedHtml} is removed");
}
assert_true(str_contains($safeRichHtml, 'href="https://docs.example/path"'), 'G6 rich HTML: safe HTTPS links survive');
assert_true(preg_match("/(?:^|; )form-action 'self'(?: (?:https:\/\/[a-z0-9.-]+|http:\/\/(?:127\.0\.0\.1|localhost))(?::[0-9]{1,5})?)*;/", Security::contentSecurityPolicy()) === 1, 'G6 CSP: form submissions are restricted to self plus declared payment gateway origins');
assert_true(str_contains($securitySrc, "frame-ancestors 'none'"), 'G6 CSP: framing is denied in CSP');

$feedEndpoint = new ReflectionMethod(FeedImport::class, 'publicFeedEndpoint');
foreach ([
    'file:///etc/passwd',
    'gopher://127.0.0.1/',
    'http://localhost/feed.xml',
    'http://127.0.0.1/feed.xml',
    'http://10.0.0.1/feed.xml',
    'http://169.254.169.254/latest/meta-data/',
    'http://[::1]/feed.xml',
    'http://[fe80::1]/feed.xml',
    'http://[64:ff9b::7f00:1]/feed.xml',
    'http://[64:ff9b::a9fe:a9fe]/latest/meta-data/',
    'http://[64:ff9b:1::7f00:1]/feed.xml',
    'http://2130706433/feed.xml',
    'http://127.0.0.1:8080/feed.xml',
    'http://user:pass@93.184.216.34/feed.xml',
] as $blockedFeedUrl) {
    assert_true($feedEndpoint->invoke(null, $blockedFeedUrl) === null, "G6 feed SSRF: blocks {$blockedFeedUrl}");
}
$publicFeed = $feedEndpoint->invoke(null, 'https://93.184.216.34/feed.xml');
assert_equals('93.184.216.34', (string)($publicFeed['ip'] ?? ''), 'G6 feed SSRF: accepts a literal public HTTPS endpoint');
$feedSourceG6 = file_get_contents(TEST_CLI_DIR . '/../src/FeedImport.php') ?: '';
assert_true(str_contains($feedSourceG6, 'CURLOPT_RESOLVE'), 'G6 feed SSRF: verified DNS result is pinned for the connection');
assert_true(str_contains($feedSourceG6, "CURLOPT_PROXY => ''") && str_contains($feedSourceG6, "CURLOPT_NOPROXY => '*'"), 'G6 feed SSRF: ambient proxies cannot bypass DNS pinning');
assert_true(str_contains($feedSourceG6, 'CURLOPT_FOLLOWLOCATION => false'), 'G6 feed SSRF: redirects cannot pivot to a private target');
assert_true(str_contains($feedSourceG6, '$maxBytes = 10 * 1024 * 1024'), 'G6 feed SSRF: response size is bounded');

$g6RefundOrderId = Orders::create([[
    'product_id' => 9001, 'variant_id' => null, 'qty' => 1, 'price' => 999,
    'sku' => 'DIGI-GUIDE', 'name' => 'Digital Guide', 'variant_name' => '',
]], [
    'name' => 'G6 Refund Buyer', 'email' => 'g6-refund@example.com', 'currency' => 'eur',
    'shipping_cost' => 0, 'tax_amount' => 0,
], 'card');
PaymentService::transitionPayment($g6RefundOrderId, 'paid', 'payment.captured', 999, 'g6_refund_paid');
$g6RefundTotal = (int)Orders::byId($g6RefundOrderId)['total'];
assert_equals($g6RefundTotal, PaymentService::refundableBalance($g6RefundOrderId), 'G6 refund: fully captured order exposes its server-side balance');
$g6OverRefundRejected = false;
try {
    PaymentService::requestRefund($g6RefundOrderId, $g6RefundTotal + 1, 'g6_refund_over_limit');
} catch (LogicException $e) {
    $g6OverRefundRejected = true;
}
assert_true($g6OverRefundRejected, 'G6 refund: amount above balance is rejected inside the transaction');
assert_equals('paid', (string)Orders::byId($g6RefundOrderId)['payment_status'], 'G6 refund: rejected amount leaves payment state unchanged');
PaymentService::requestRefund($g6RefundOrderId, 400, 'g6_refund_pending', ['actor' => 'test-admin', 'amount' => 400]);
assert_equals($g6RefundTotal, PaymentService::refundableBalance($g6RefundOrderId), 'G6 refund: pending request is not misreported as completed refund');
$g6SecondClaimRejected = false;
try {
    PaymentService::requestRefund($g6RefundOrderId, 100, 'g6_refund_second_claim');
} catch (LogicException $e) {
    $g6SecondClaimRejected = true;
}
assert_true($g6SecondClaimRejected, 'G6 refund: a second request cannot claim an already-pending payment');
assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE order_id = {$g6RefundOrderId} AND status = 'refund_pending'")->fetchColumn(), 'G6 refund: failed second claim creates no extra refund event');
PaymentService::transitionPayment($g6RefundOrderId, 'partially_refunded', 'refund.partial_succeeded', 400, 'g6_refund_partial');
assert_equals($g6RefundTotal - 400, PaymentService::refundableBalance($g6RefundOrderId), 'G6 refund: successful partial refund reduces server-side balance');
$adminSourceG6 = entrypointSource('admin.php');
assert_true(str_contains($adminSourceG6, '$amount > $refundableBalance'), 'G6 refund: handler rejects an amount above remaining balance');
assert_true(str_contains($adminSourceG6, 'PaymentService::requestRefund'), 'G6 refund: admin uses the atomic refund claim');
assert_true(str_contains($adminSourceG6, "['refund_pending', 'partially_refunded', 'refunded']"), 'G6 refund: generic payment transition blocks every refund status');
assert_true(str_contains($adminSourceG6, "\$reason === ''"), 'G6 refund: server requires a non-empty audit reason');
assert_true(str_contains($adminSourceG6, "adminCan('customers.edit')"), 'G6 RBAC: customer status control is hidden from view-only roles');
assert_true(str_contains($adminSourceG6, "adminRequire('customers.edit')"), 'G6 RBAC: customer status mutation requires edit permission server-side');
$paymentSourceG7 = file_get_contents(TEST_CLI_DIR . '/../src/PaymentService.php') ?: '';
assert_true(!str_contains($paymentSourceG7, '->quote('), 'G7 SQL: payment webhook order lookups never interpolate quoted values');
$webhookRoutingSourceG7 = file_get_contents(TEST_CLI_DIR . '/../src/PaymentWebhookRouting.php') ?: '';
assert_true(!str_contains($webhookRoutingSourceG7, '->quote(') && str_contains($webhookRoutingSourceG7, 'WHERE {$column} = ? AND store_id IN ({$marks})')
    && str_contains($webhookRoutingSourceG7, "['order_number', (string)\$refs['order_number']]") && str_contains($webhookRoutingSourceG7, "['payment_id', (string)\$refs['payment_id']]"), 'G7 SQL: Stripe and PayU fallback identifiers use bound parameters on fixed columns');

$accountSourceG6 = file_get_contents(TEST_CLI_DIR . '/../account.php') ?: '';
assert_true(str_contains($accountSourceG6, 'GDPR erase failed [{$errorId}]'), 'G6 errors: erase failure details are logged with a correlation ID');
assert_true(!str_contains($accountSourceG6, "gdpr_erase_failed') . ': ' . \$ex->getMessage()"), 'G6 errors: erase exception detail is not rendered to the customer');
$importSourceG6 = file_get_contents(TEST_CLI_DIR . '/../src/Import.php') ?: '';
$pageSourceG6 = file_get_contents(TEST_CLI_DIR . '/../page.php') ?: '';
$viewModelSourceG6 = file_get_contents(TEST_CLI_DIR . '/../src/StorefrontViewModel.php') ?: '';
$apiSourceG6 = entrypointSource('api.php');
assert_true(substr_count($adminSourceG6, 'Security::sanitizeRichHtml') >= 2, 'G6 rich HTML: CMS and admin product writes are sanitized');
assert_true(str_contains($importSourceG6, "\$data['description'] = Security::sanitizeRichHtml"), 'G6 rich HTML: product imports sanitize descriptions');
assert_true(str_contains($pageSourceG6, 'StorefrontViewModel::cmsPage') && str_contains($viewModelSourceG6, 'Security::sanitizeRichHtml'), 'G6 rich HTML: historical CMS content is sanitized at the canonical view-model boundary');
assert_true(str_contains($apiSourceG6, 'Security::sanitizeRichHtml'), 'G6 rich HTML: CMS API returns sanitized content');
$scriptBreakout = JsonLd::render(['name' => '</script><img src=x onerror=alert(1)>'], 'g6-nonce');
assert_equals(1, substr_count(strtolower($scriptBreakout), '</script>'), 'G6 JSON embed: attacker data cannot terminate the JSON-LD script');
assert_true(!str_contains(strtolower($scriptBreakout), '<img'), 'G6 JSON embed: attacker HTML stays hex-escaped inside JSON');
$productSourceG6 = file_get_contents(TEST_CLI_DIR . '/../product.php') ?: '';
$jsonLdSourceG6 = file_get_contents(TEST_CLI_DIR . '/../src/JsonLd.php') ?: '';
assert_true(substr_count($adminSourceG6, 'JSON_HEX_TAG') >= 2 && str_contains(AdminForm::render('admin-attribute', 'admin.php', ['name' => '"><script>x</script>']), 'value="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"'),
    'G6 JSON embed: admin dynamic maps hex-escape tag delimiters and server-rendered form values are HTML-escaped');
assert_true(str_contains($productSourceG6, "src/Entrypoints/Storefront.php"), 'G6 JSON embed: clean product route delegates JSON-LD rendering to the canonical storefront');
assert_true(str_contains($jsonLdSourceG6, 'JSON_HEX_TAG'), 'G6 JSON embed: shared JSON-LD renderer hex-escapes tag delimiters');

[$g6EraseRegistered, $g6EraseCustomerId] = Customers::register('g6-erase@example.com', 'StrongPass!123', 'G6 Erase');
assert_true($g6EraseRegistered, 'G6 GDPR: audit-failure fixture customer is created');
$g6EraseResult = with_expected_error_log(
    'GDPR erase audit failed',
    static fn() => GdprService::anonymizeCustomer((int)$g6EraseCustomerId, 'g6-test')
);
assert_equals('anonymized', (string)($g6EraseResult['status'] ?? ''), 'G6 GDPR: post-commit audit failure does not report erasure as failed');
assert_equals('anonymized', (string)(Customers::byId((int)$g6EraseCustomerId)['status'] ?? ''), 'G6 GDPR: customer remains anonymized after audit logging failure');
$gdprSourceG6 = file_get_contents(TEST_CLI_DIR . '/../src/GdprService.php') ?: '';
assert_true(str_contains($gdprSourceG6, 'GDPR erase audit failed [{$errorId}]'), 'G6 GDPR: post-commit audit failure is logged with a correlation ID');

// ============================================================
// TEST 61: G3 stable multi-provider registry and frozen dispatch
// ============================================================
echo "\n--- Test 61: G3 provider registry ---\n";

$providerRegistry = Adapters::registry();
assert_equals('StripePayment', (string)$providerRegistry['payment']['stripe']['class'], 'G3 registry: stripe has a stable provider code');
assert_equals('PayUPayment', (string)$providerRegistry['payment']['payu']['class'], 'G3 registry: PayU can coexist with Stripe');
assert_equals('PacketaShipping', (string)$providerRegistry['shipping']['packeta']['class'], 'G3 registry: shipping provider is registered');
assert_equals('SmtpMail', (string)$providerRegistry['mail']['smtp']['class'], 'G3 registry: mail provider is registered');
assert_equals('VatService', (string)$providerRegistry['tax']['vat']['class'], 'G3 registry: tax provider is registered');
assert_true(in_array('refund', $providerRegistry['payment']['stripe']['capabilities'], true), 'G3 registry: provider capabilities are declared');
assert_equals(StoreContext::current()->providerId(), (string)$providerRegistry['payment']['stripe']['scope'], 'G3 registry: definitions declare active provider scope');
assert_true(Adapters::provider('payment', 'stripe') instanceof StripePayment, 'G3 registry: resolves Stripe independently');
assert_true(Adapters::provider('payment', 'payu') instanceof PayUPayment, 'G3 registry: resolves PayU independently');
assert_true(Adapters::shipping('packeta') instanceof PacketaShipping, 'G3 registry: resolves shipping by stable code');
assert_true(Adapters::mail('smtp') instanceof SmtpMail, 'G3 registry: resolves mail by stable code');
assert_equals(VatService::class, Adapters::tax('vat'), 'G3 registry: resolves static tax provider contract');
assert_equals('payu', Adapters::providerCode('payment', PayUPayment::class), 'G3 registry: maps adapter class back to stable code');
$invalidProviderRejected = false;
try { Adapters::provider('payment', '../stripe'); }
catch (InvalidArgumentException $e) { $invalidProviderRejected = true; }
assert_true($invalidProviderRejected, 'G3 registry: unsafe provider code is rejected before class loading');

assert_equals('vat', (string)$financialSnapshot['providers']['tax'], 'G3 snapshot: direct financial snapshot freezes tax provider');
$checkoutProviderSnapshot = json_decode((string)$onlineCheckoutOrder['financial_snapshot'], true) ?: [];
assert_equals('stripe', (string)($checkoutProviderSnapshot['providers']['payment'] ?? ''), 'G3 snapshot: checkout freezes selected payment provider');
assert_equals('packeta', (string)($checkoutProviderSnapshot['providers']['shipping'] ?? ''), 'G3 snapshot: checkout freezes selected shipping provider');
assert_equals('log', (string)($checkoutProviderSnapshot['providers']['mail'] ?? ''), 'G3 snapshot: checkout freezes mail provider');
assert_equals('packeta', (string)($onlineCheckoutShippingSnapshot['provider'] ?? ''), 'G3 snapshot: shipping rate stores provider code');

$workerProviderSource = file_get_contents(TEST_CLI_DIR . '/worker.php') ?: '';
assert_true(!str_contains($workerProviderSource, 'Adapters::payment();'), 'G3 worker: no payment job depends on one global gateway');
assert_true(str_contains($workerProviderSource, "Adapters::payment(\$providerCode)"), 'G3 worker: refund resolves frozen payment provider');
assert_true(str_contains($workerProviderSource, "Adapters::payment(\$provider)"), 'G3 worker: expiry resolves each order provider independently');
assert_true(str_contains($workerProviderSource, "Adapters::integration(\$providerCode)"), 'G3 worker: ERP jobs resolve the frozen provider');
assert_true(str_contains($apiSourceG6, 'Adapters::payment($paymentProvider)'), 'G3 checkout: selected payment provider drives session creation');
assert_true(!str_contains($apiSourceG6, 'new StripePayment($cfg)'), 'G3 webhook: no hardcoded Stripe construction remains');

// ============================================================
// TEST 62: G4 outbox, signed webhooks and external API v1
// ============================================================
echo "\n--- Test 62: G4 outbox, webhooks and API v1 ---\n";

Bootstrap::initStoreContext('/', '', 'default', 'api');
$outsideTransactionRejected = false;
try { Outbox::record('test.invalid', 'test', 1, []); }
catch (LogicException $e) { $outsideTransactionRejected = true; }
assert_true($outsideTransactionRejected, 'G4 outbox: event writes are rejected outside a domain transaction');

$rollbackEventId = str_repeat('b', 32);
$pdo->beginTransaction();
Outbox::record('test.rolled_back', 'test', 1, ['ok' => true], 1, $rollbackEventId);
$pdo->rollBack();
$rollbackProbe = $pdo->prepare('SELECT 1 FROM event_outbox WHERE id=?');
$rollbackProbe->execute([$rollbackEventId]);
assert_true(!$rollbackProbe->fetchColumn(), 'G4 outbox: domain rollback removes the event atomically');
assert_true((int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type='order.created'")->fetchColumn() > 0, 'G4 outbox: order creation emits committed events');
assert_true((int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type LIKE 'stock.%'")->fetchColumn() > 0, 'G4 outbox: stock mutations emit committed events');
assert_true((int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type='payment.status_changed'")->fetchColumn() > 0, 'G4 outbox: payment transitions emit committed events');
assert_true((int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type LIKE 'customer.%'")->fetchColumn() > 0, 'G4 outbox: customer mutations emit committed events');

$deliveryEventId = str_repeat('a', 32);
$pdo->beginTransaction();
Outbox::record('test.delivery', 'test', 42, ['answer' => 42], 1, $deliveryEventId);
$pdo->commit();
$claimedDelivery = Outbox::claim($deliveryEventId);
assert_equals(1, (int)($claimedDelivery['attempts'] ?? 0), 'G4 outbox: conditional claim increments delivery attempt once');
$deliveryEnvelope = Outbox::envelope($claimedDelivery);
assert_equals($deliveryEventId, (string)$deliveryEnvelope['id'], 'G4 outbox: stable v1 envelope preserves event ID');
assert_equals('default', (string)$deliveryEnvelope['store_id'], 'G4 outbox: envelope carries explicit store scope');

$webhookSecret = str_repeat('s', 32);
$webhookBody = json_encode($deliveryEnvelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$webhookTimestamp = (string)time();
$webhookSignature = Webhook::signature($webhookBody, $webhookTimestamp, $deliveryEventId, $webhookSecret);
assert_true(Webhook::verify($webhookBody, $webhookTimestamp, $deliveryEventId, $webhookSignature, $webhookSecret), 'G4 webhook: valid HMAC signature and fresh timestamp pass');
assert_true(!Webhook::verify($webhookBody . 'x', $webhookTimestamp, $deliveryEventId, $webhookSignature, $webhookSecret), 'G4 webhook: tampered raw body fails signature verification');
assert_true(!Webhook::verify($webhookBody, (string)(time() - 301), $deliveryEventId, Webhook::signature($webhookBody, (string)(time() - 301), $deliveryEventId, $webhookSecret), $webhookSecret), 'G4 webhook: stale timestamp is rejected');
assert_true(!Webhook::verify($webhookBody, $webhookTimestamp, $deliveryEventId, $webhookSignature, $webhookSecret, static fn(string $id): bool => true), 'G4 webhook: consumer replay guard rejects a consumed event ID');
$mockDelivery = Webhook::deliver([
    'url' => 'https://integration.example/webhook', 'secret' => $webhookSecret,
], $deliveryEnvelope, static fn(string $url, string $body, array $headers): array => ['response_code' => 204]);
assert_true($mockDelivery['delivered'], 'G4 webhook: any 2xx transport response completes delivery');
Outbox::recordDelivery($deliveryEventId, 'contract_test', 1, true, 204, null);
Outbox::markDelivered($deliveryEventId);
assert_true(Outbox::endpointDelivered($deliveryEventId, 'contract_test'), 'G4 webhook: successful endpoint attempt is auditably deduplicated');
assert_equals('delivered', (string)$pdo->query("SELECT status FROM event_outbox WHERE id='{$deliveryEventId}'")->fetchColumn(), 'G4 outbox: delivered event reaches terminal state');
$staleEventId = str_repeat('c', 32);
$pdo->beginTransaction();
Outbox::record('test.stale_claim', 'test', 43, [], 1, $staleEventId);
$pdo->commit();
Outbox::claim($staleEventId);
$pdo->exec("UPDATE event_outbox SET updated_at=datetime('now', '-301 seconds') WHERE id='{$staleEventId}'");
$staleClaim = Outbox::claim($staleEventId);
assert_equals(2, (int)($staleClaim['attempts'] ?? 0), 'G4 outbox: worker crash claim is recoverable after the lease expires');
Outbox::markDead($staleEventId, 'contract test cleanup');

$issuedClient = ApiAuth::issueClient('G4 contract test', 'default', ['products:read', 'products:write', 'orders:read']);
$storedTokenHash = (string)$pdo->query('SELECT token_hash FROM api_clients WHERE id=' . (int)$issuedClient['id'])->fetchColumn();
assert_equals(hash('sha256', $issuedClient['token']), $storedTokenHash, 'G4 API auth: database stores only the bearer token hash');
assert_true($storedTokenHash !== $issuedClient['token'], 'G4 API auth: plaintext bearer token is never persisted');
$apiClient = ApiAuth::authenticate('Bearer ' . $issuedClient['token']);
assert_equals('default', (string)$apiClient['store_id'], 'G4 API auth: credential resolves its immutable store scope');
$wrongTokenRejected = false;
$wrongToken = substr($issuedClient['token'], 0, -1) . (str_ends_with($issuedClient['token'], '0') ? '1' : '0');
try { ApiAuth::authenticate('Bearer ' . $wrongToken); }
catch (RuntimeException $e) { $wrongTokenRejected = true; }
assert_true($wrongTokenRejected, 'G4 API auth: wrong secret fails closed');
$scopeRejected = false;
try { ApiAuth::requireScope($apiClient, 'orders:write'); }
catch (DomainException $e) { $scopeRejected = true; }
assert_true($scopeRejected, 'G4 API auth: missing mutation scope is rejected');

$productPage = ApiV1::dispatch('GET', '/api/v1/products', ['limit' => 1], [], [], $apiClient);
assert_equals(200, $productPage['status'], 'G4 API v1: scoped product list succeeds');
assert_equals(1, count($productPage['body']['data']), 'G4 API v1: cursor page obeys limit');
assert_true($productPage['body']['meta']['next_cursor'] !== null, 'G4 API v1: opaque next cursor is returned');
$secondProductPage = ApiV1::dispatch('GET', '/api/v1/products', [
    'limit' => 1, 'cursor' => $productPage['body']['meta']['next_cursor'],
], [], [], $apiClient);
assert_true((int)$secondProductPage['body']['data'][0]['id'] > (int)$productPage['body']['data'][0]['id'], 'G4 API v1: cursor advances in stable ID order');

$apiProductId = (int)$productPage['body']['data'][0]['id'];
$apiProductBefore = Products::byId($apiProductId);
$patchBody = ['stock' => (int)$apiProductBefore['stock'] + 1];
$idempotencyKey = 'g4-product-patch-0001';
$patchedProduct = ApiV1::dispatch('PATCH', "/api/v1/products/{$apiProductId}", [], $patchBody, ['Idempotency-Key' => $idempotencyKey], $apiClient);
assert_equals(200, $patchedProduct['status'], 'G4 API v1: scoped product mutation succeeds');
assert_equals((int)$apiProductBefore['stock'] + 1, (int)$patchedProduct['body']['data']['stock'], 'G4 API v1: stock remains an integer quantity');
$productOutboxCount = (int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type='product.updated' AND aggregate_id='{$apiProductId}'")->fetchColumn();
$replayedPatch = ApiV1::dispatch('PATCH', "/api/v1/products/{$apiProductId}", [], $patchBody, ['Idempotency-Key' => $idempotencyKey], $apiClient);
assert_true($replayedPatch['body']['meta']['idempotent_replay'], 'G4 API v1: identical retry returns the stored response');
assert_equals($productOutboxCount, (int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type='product.updated' AND aggregate_id='{$apiProductId}'")->fetchColumn(), 'G4 API v1: retry does not repeat mutation or outbox event');
$idempotencyConflict = false;
try {
    ApiV1::dispatch('PATCH', "/api/v1/products/{$apiProductId}", [], ['stock' => (int)$patchBody['stock'] + 1], ['Idempotency-Key' => $idempotencyKey], $apiClient);
} catch (UnexpectedValueException $e) { $idempotencyConflict = true; }
assert_true($idempotencyConflict, 'G4 API v1: same key with a different request returns conflict');
$pdo->prepare("UPDATE api_idempotency SET created_at=datetime('now', '-31 days') WHERE client_id=? AND idempotency_key=?")
    ->execute([$apiClient['id'], $idempotencyKey]);
assert_equals(1, ApiAuth::cleanupIdempotency(30), 'G4 API v1: expired replay responses are cleaned by the worker lifecycle');

$crossStoreRejected = false;
$foreignClient = $apiClient;
$foreignClient['store_id'] = 'cz';
try { ApiV1::dispatch('GET', '/api/v1/orders', [], [], [], $foreignClient); }
catch (DomainException $e) { $crossStoreRejected = true; }
assert_true($crossStoreRejected, 'G4 API v1: client cannot cross the active StoreContext');
$openApi = file_get_contents(TEST_CLI_DIR . '/../docs/openapi.yaml') ?: '';
assert_true(str_contains($openApi, 'openapi: 3.1.0'), 'G4 contract: versioned OpenAPI document exists');
assert_true(str_contains($openApi, 'Idempotency-Key') && str_contains($openApi, 'products:write'), 'G4 contract: idempotency and scopes are documented');
assert_true(str_contains($openApi, 'X-BBE-Signature') && str_contains($openApi, 'dead-letter'), 'G4 contract: HMAC, replay and retry/dead-letter semantics are documented');
$workerG4 = file_get_contents(TEST_CLI_DIR . '/worker.php') ?: '';
assert_true(str_contains($workerG4, 'processOutbox') && str_contains($workerG4, 'Outbox::markRetry'), 'G4 worker: committed events are delivered with durable retry handling');
Bootstrap::initStoreContext('/', '', 'default', 'storefront');

// ============================================================
// TEST 63: G5 setup, health, structured logs, shipping and ERP
// ============================================================
echo "\n--- Test 63: G5 merchant operations and integration contracts ---\n";

$g5Registry = Adapters::registry();
assert_equals('HttpShipping', (string)$g5Registry['shipping']['http-carrier']['class'], 'G5 registry: generic HTTP carrier is available');
assert_true(in_array('cancel', $g5Registry['shipping']['http-carrier']['capabilities'], true), 'G5 registry: carrier declares cancellation capability');
assert_equals('HttpErpAdapter', (string)$g5Registry['erp']['http']['class'], 'G5 registry: generic HTTP ERP is available');
assert_true(in_array('order_status', $g5Registry['erp']['http']['capabilities'], true), 'G5 registry: ERP declares status mutation capability');

$g5Calls = [];
JsonHttpClient::setFixtureTransport(static function (string $method, string $url, string $body, array $headers) use (&$g5Calls): array {
    $g5Calls[] = compact('method', 'url', 'body', 'headers');
    $path = (string)parse_url($url, PHP_URL_PATH);
    if ($path === '/v1/rates') return ['status' => 200, 'body' => ['rates' => [[
        'method_code' => 'fixture-pickup', 'label' => 'Fixture pickup', 'price' => 299,
        'cod_surcharge' => 0, 'delivery_days' => '1-2', 'cod' => false, 'pickup_required' => true,
    ]]]];
    if ($path === '/v1/pickup-points') return ['status' => 200, 'body' => ['data' => [[
        'id' => 'PP-1', 'name' => 'Fixture Point', 'address' => 'Test 1, Bratislava',
    ]]]];
    if ($path === '/v1/labels' && $method === 'POST') return ['status' => 201, 'body' => ['awb' => 'HTTP00000001', 'label_data' => 'fixture-label']];
    if (str_ends_with($path, '/cancel')) return ['status' => 200, 'body' => ['status' => 'cancelled']];
    if (str_starts_with($path, '/v1/shipments/')) return ['status' => 200, 'body' => ['status' => 'in_transit', 'events' => [['timestamp' => '2026-08-01T12:00:00Z', 'location' => 'Hub', 'description' => 'Accepted']]]];
    if ($path === '/v1/orders' && $method === 'POST') return ['status' => 201, 'body' => ['id' => 'ERP-1001']];
    if (str_ends_with($path, '/status')) return ['status' => 200, 'body' => ['status' => 'processing']];
    if ($path === '/v1/orders/ERP-1001' && $method === 'PATCH') return ['status' => 200, 'body' => ['status' => 'shipped']];
    if ($path === '/v1/stock') return ['status' => 200, 'body' => ['data' => [['sku' => 'TEST-001', 'qty' => 9]]]];
    if ($path === '/v1/customers') return ['status' => 201, 'body' => ['id' => 'CUS-1']];
    if ($path === '/v1/health') return ['status' => 200, 'body' => ['status' => 'ok']];
    if (str_starts_with($path, '/v1/products/')) return ['status' => 200, 'body' => ['status' => 'ok']];
    if ($path === '/v1/products') return ['status' => 200, 'body' => ['data' => [['sku' => 'TEST-001']]]];
    return ['status' => 404, 'body' => ['error' => ['code' => 'fixture_not_found']]];
});
$g5Config = [
    'integrations' => [
        'http_shipping' => ['base_url' => 'https://carrier.test', 'api_key' => str_repeat('c', 24), 'carrier_code' => 'http-carrier'],
        'http_erp' => ['base_url' => 'https://erp.test', 'api_key' => str_repeat('e', 24)],
    ],
];
$g5Shipping = new HttpShipping($g5Config);
$g5Rates = $g5Shipping->getRates(['country' => 'SK', 'weight_g' => 1000, 'subtotal' => 2000]);
assert_equals(299, (int)$g5Rates[0]['price'], 'G5 shipping: fixture rate preserves integer cents');
assert_true(!empty($g5Rates[0]['pickup_required']), 'G5 shipping: pickup requirement is explicit');
assert_equals('PP-1', (string)$g5Shipping->pickupPoints('SK', '81101')[0]['id'], 'G5 shipping: pickup points contract resolves');
$g5Label = $g5Shipping->generateLabel(['id' => 1, 'order_number' => 'G5-1', 'shipping_addr' => '{}', 'total' => 1000, 'currency' => 'eur']);
assert_equals('HTTP00000001', (string)$g5Label['awb'], 'G5 shipping: label contract returns AWB');
assert_equals('in_transit', (string)$g5Shipping->trackShipment('HTTP00000001')['status'], 'G5 shipping: tracking contract resolves');
assert_equals('cancelled', (string)$g5Shipping->cancelLabel('HTTP00000001')['status'], 'G5 shipping: label cancellation contract resolves');

ShippingService::setAdapters(['http-carrier' => $g5Shipping]);
$g5LabelCallsBefore = count(array_filter($g5Calls, static fn(array $call): bool => parse_url($call['url'], PHP_URL_PATH) === '/v1/labels' && $call['method'] === 'POST'));
$g5ServiceLabel = ShippingService::generateLabel((int)$digitalOrderId, 'http-carrier');
$g5ServiceLabelReplay = ShippingService::generateLabel((int)$digitalOrderId, 'http-carrier');
$g5LabelCallsAfter = count(array_filter($g5Calls, static fn(array $call): bool => parse_url($call['url'], PHP_URL_PATH) === '/v1/labels' && $call['method'] === 'POST'));
assert_equals((string)$g5ServiceLabel['awb'], (string)$g5ServiceLabelReplay['awb'], 'G5 shipping: label retry returns the original AWB');
assert_equals($g5LabelCallsBefore + 1, $g5LabelCallsAfter, 'G5 shipping: label retry performs one external mutation');
$g5Cancel = ShippingService::cancelLabel((int)$digitalOrderId, 'http-carrier');
$g5CancelReplay = ShippingService::cancelLabel((int)$digitalOrderId, 'http-carrier');
assert_true(empty($g5Cancel['idempotent_replay']) && !empty($g5CancelReplay['idempotent_replay']), 'G5 shipping: cancel replay is served from durable label state');
assert_equals('in_transit', (string)ShippingService::trackShipment('http-carrier', (string)$g5ServiceLabel['awb'])['status'], 'G5 shipping: service tracking uses frozen carrier');
ShippingService::setAdapters([]);

$g5Erp = new HttpErpAdapter($g5Config);
assert_equals('ERP-1001', $g5Erp->pushOrder(['id' => 1001, 'order_number' => 'G5-1001', 'items' => []]), 'G5 ERP: order export returns external ID');
$g5Erp->updateOrderStatus('ERP-1001', 'shipped', ['order_id' => 1001]);
assert_equals('processing', $g5Erp->pullOrderStatus('ERP-1001'), 'G5 ERP: status pull contract resolves');
assert_equals(9, (int)$g5Erp->pullStock()[0]['qty'], 'G5 ERP: stock contract resolves integer quantity');
assert_true($g5Erp->healthCheck(), 'G5 ERP: health contract succeeds');
assert_true(count(iterator_to_array($g5Erp->pullProducts())) === 1, 'G5 ERP: product delta contract is iterable');
$g5Erp->pushProduct(['sku' => 'TEST-001']);
assert_equals('CUS-1', $g5Erp->pushCustomer(['id' => 1, 'email' => 'fixture@example.test']), 'G5 ERP: customer export returns external ID');
assert_true(str_starts_with((string)$g5Calls[0]['headers'][3], 'Authorization: Bearer '), 'G5 HTTP: credentials use authorization header, not URL or payload');
foreach ($g5Calls as $call) {
    assert_true(!str_contains($call['url'], str_repeat('c', 24)) && !str_contains($call['body'], str_repeat('c', 24)), 'G5 HTTP: carrier secret never enters URL or JSON body');
    assert_true(!str_contains($call['url'], str_repeat('e', 24)) && !str_contains($call['body'], str_repeat('e', 24)), 'G5 HTTP: ERP secret never enters URL or JSON body');
}
JsonHttpClient::setFixtureTransport(null);

$g5Redacted = Logger::redact(['api_key' => 'secret-value', 'nested' => ['password' => 'hidden'], 'order_id' => 42]);
assert_equals('[REDACTED]', $g5Redacted['api_key'], 'G5 logging: top-level secret is redacted');
assert_equals('[REDACTED]', $g5Redacted['nested']['password'], 'G5 logging: nested secret is redacted');
assert_equals(42, $g5Redacted['order_id'], 'G5 logging: useful non-secret context remains');
Logger::setCorrelationId('g5-test-correlation');
assert_equals('g5-test-correlation', Logger::correlationId(), 'G5 logging: correlation ID is stable');
Logger::setCorrelationId(null);

$g5Schema = json_decode((string)file_get_contents(TEST_CLI_DIR . '/../config/config.schema.json'), true);
assert_true(is_array($g5Schema), 'G5 setup: machine-readable config schema is valid JSON');
assert_true(!empty($g5Schema['properties']['db_password']['x-secret']), 'G5 setup: config schema marks secrets');
$g5Doctor = Setup::doctor(require TEST_CLI_DIR . '/../config.php', dirname(TEST_CLI_DIR), $pdo);
assert_equals(1, (int)$g5Doctor['schema_version'], 'G5 setup: doctor output is explicitly versioned');
assert_true(isset($g5Doctor['counts']['pass'], $g5Doctor['checks']), 'G5 setup: doctor output is machine-readable');
$g5DoctorJson = json_encode($g5Doctor, JSON_THROW_ON_ERROR);
assert_true(!str_contains($g5DoctorJson, 'db_password'), 'G5 setup: doctor output excludes database password fields');
$g5SetupRoot = sys_get_temp_dir() . '/bbe-setup-' . bin2hex(random_bytes(4));
$g5SetupConfig = [
    'db_driver' => 'mysql',
    'private_runtime_dir' => $g5SetupRoot . '/runtime',
    'private_cache_dir' => $g5SetupRoot . '/cache',
    'jobs_dir' => $g5SetupRoot . '/jobs',
    'invoice_storage_dir' => $g5SetupRoot . '/invoices',
    'backup_storage_dir' => $g5SetupRoot . '/backups',
    'backup_log_file' => $g5SetupRoot . '/logs/backup.log',
    'structured_log_file' => $g5SetupRoot . '/logs/application.jsonl',
];
$g5ApplyFirst = Setup::apply($g5SetupConfig, dirname(TEST_CLI_DIR));
$g5SetupStateHash = hash_file('sha256', $g5ApplyFirst['state_file']);
$g5ApplySecond = Setup::apply($g5SetupConfig, dirname(TEST_CLI_DIR));
assert_equals('created', (string)$g5ApplyFirst['state_action'], 'G5 setup: first apply creates private version state');
assert_equals('unchanged', (string)$g5ApplySecond['state_action'], 'G5 setup: repeated apply is idempotent');
assert_equals($g5SetupStateHash, hash_file('sha256', $g5ApplySecond['state_file']), 'G5 setup: repeated apply does not rewrite state');
@unlink($g5ApplyFirst['state_file']);
foreach (['runtime', 'cache', 'jobs', 'invoices', 'backups', 'logs'] as $g5SetupDir) @rmdir($g5SetupRoot . '/' . $g5SetupDir);
@rmdir($g5SetupRoot);

$g5HealthConfig = (require TEST_CLI_DIR . '/../config.php');
$g5HealthConfig['jobs_dir'] = sys_get_temp_dir();
$g5HealthConfig['private_cache_dir'] = sys_get_temp_dir();
$g5Ready = Health::readiness($g5HealthConfig, dirname(TEST_CLI_DIR), $pdo);
assert_equals('ready', (string)$g5Ready['status'], 'G5 health: readiness verifies DB, stores, and writable runtime paths');
assert_equals('ok', (string)Health::liveness()['status'], 'G5 health: liveness has no dependency side effects');
assert_true(str_contains($htaccess, 'healthz|readyz'), 'G5 health: public probe routes are explicit');
assert_true(str_contains($workerProviderSource, 'updateOrderStatus'), 'G5 ERP: worker executes status mutation contract instead of logging a placeholder');
assert_true(!str_contains($workerProviderSource, 'IntegrationAdapter has no outbound status mutation contract yet'), 'G5 ERP: worker placeholder is removed');

// ============================================================
// G7: auditable inventory, backorders, bundles and split fulfillment
// ============================================================
echo "\n--- G7: Inventory ledger and partial fulfillment ---\n";
$g7CategoryId = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$pdo->exec("DELETE FROM shipment_items");
$pdo->exec("DELETE FROM shipments");
$pdo->exec("DELETE FROM inventory_receipts");
$pdo->exec("DELETE FROM purchase_order_items");
$pdo->exec("DELETE FROM purchase_orders");
$pdo->exec("DELETE FROM supplier_products");
$pdo->exec("DELETE FROM suppliers");
$pdo->exec("DELETE FROM bundle_components");
$pdo->exec("DELETE FROM warehouse_stock WHERE sku LIKE 'G7-%'");
$pdo->exec("DELETE FROM warehouses WHERE code LIKE 'g7-%'");
$pdo->exec("DELETE FROM products WHERE sku LIKE 'G7-%'");
$pdo->exec("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('g7-main','G7 Main',200,0,'default'),('g7-aux','G7 Aux',100,0,'default')");
$g7Main = (int)$pdo->query("SELECT id FROM warehouses WHERE code='g7-main'")->fetchColumn();
$g7Aux = (int)$pdo->query("SELECT id FROM warehouses WHERE code='g7-aux'")->fetchColumn();

$g7ProductInsert = $pdo->prepare("INSERT INTO products
    (store_id, category_id, sku, slug, product_type, name, price, stock, status, inventory_policy, preorder_at, personalization_schema)
    VALUES ('default', :category, :sku, :slug, :type, :name, :price, :stock, 'active', :policy, :preorder, :personalization)");
$g7Products = [
    ['G7-COMP-A','g7-comp-a','simple','G7 Component A',500,3,'backorder',null,'{}'],
    ['G7-COMP-B','g7-comp-b','simple','G7 Component B',300,10,'deny',null,'{}'],
    ['G7-BUNDLE','g7-bundle','bundle','G7 Personalized Kit',1500,10,'deny',null,'{"required":["engraving"]}'],
    ['G7-SPLIT','g7-split','simple','G7 Split Product',900,3,'deny',null,'{}'],
    ['G7-CANCEL','g7-cancel','simple','G7 Partial Cancel Product',800,3,'deny',null,'{}'],
    ['G7-PRE','g7-pre','simple','G7 Preorder Product',700,0,'preorder','2030-01-15 00:00:00','{}'],
];
foreach ($g7Products as [$sku,$slug,$type,$name,$price,$stock,$policy,$preorder,$personalization]) {
    $g7ProductInsert->execute([
        ':category' => $g7CategoryId, ':sku' => $sku, ':slug' => $slug, ':type' => $type,
        ':name' => $name, ':price' => $price, ':stock' => $stock, ':policy' => $policy,
        ':preorder' => $preorder, ':personalization' => $personalization,
    ]);
}
$g7ProductIds = [];
foreach ($pdo->query("SELECT id, sku FROM products WHERE sku LIKE 'G7-%'")->fetchAll(PDO::FETCH_ASSOC) as $row) $g7ProductIds[$row['sku']] = (int)$row['id'];
$pdo->prepare('INSERT INTO bundle_components (bundle_product_id, component_product_id, quantity, sort_order) VALUES (:bundle,:a,2,1),(:bundle,:b,1,2)')
    ->execute([':bundle' => $g7ProductIds['G7-BUNDLE'], ':a' => $g7ProductIds['G7-COMP-A'], ':b' => $g7ProductIds['G7-COMP-B']]);
Warehouse::setStock($g7Main, 'G7-COMP-A', 3, 'count', 'test', 'g7-opening-a');
Warehouse::setStock($g7Main, 'G7-COMP-B', 10, 'count', 'test', 'g7-opening-b');
Warehouse::setStock($g7Main, 'G7-SPLIT', 1, 'count', 'test', 'g7-opening-split-main');
Warehouse::setStock($g7Aux, 'G7-SPLIT', 2, 'count', 'test', 'g7-opening-split-aux');
Warehouse::setStock($g7Main, 'G7-CANCEL', 1, 'count', 'test', 'g7-opening-cancel-main');
Warehouse::setStock($g7Aux, 'G7-CANCEL', 2, 'count', 'test', 'g7-opening-cancel-aux');
Warehouse::setStock($g7Main, 'G7-REL', 4, 'count', 'test', 'g7-opening-release');
Warehouse::syncProductStock(['G7-COMP-A','G7-COMP-B','G7-SPLIT','G7-CANCEL']);
StoreContext::activate($deContext);
assert_equals(0, Warehouse::totalAvailable('G7-SPLIT'), 'G7 store isolation: another store cannot see default-store stock');
$g7CrossStoreReserveBlocked = false;
try { Warehouse::reserve('G7-SPLIT', 1); } catch (RuntimeException $e) { $g7CrossStoreReserveBlocked = true; }
assert_true($g7CrossStoreReserveBlocked, 'G7 store isolation: another store cannot reserve default-store stock');
Bootstrap::initStoreContext('/', '', 'default');

$g7MissingPersonalization = false;
try {
    Inventory::reserveLine(['product_id' => $g7ProductIds['G7-BUNDLE'], 'variant_id' => null, 'sku' => 'G7-BUNDLE', 'attrs' => []], 1);
} catch (InvalidArgumentException $e) {
    $g7MissingPersonalization = true;
}
assert_true($g7MissingPersonalization, 'G7 personalization: required fields fail closed before reservation');
assert_true(VariantAvailability::isPurchasable($g7ProductIds['G7-PRE']), 'G7 preorder: explicit preorder remains purchasable at zero stock');

$g7Customer = [
    'name' => 'G7 Customer', 'email' => 'g7@example.test', 'phone' => '', 'address' => [],
    'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur',
];
$g7BundleOrder = Orders::create([[
    'product_id' => $g7ProductIds['G7-BUNDLE'], 'variant_id' => null, 'sku' => 'G7-BUNDLE',
    'name' => 'G7 Personalized Kit', 'qty' => 2, 'price' => 1500,
    'attrs' => ['personalization' => ['engraving' => 'Audit me']],
]], $g7Customer, 'bank_transfer');
$g7BundleItem = Orders::items($g7BundleOrder)[0];
$g7BundleAttrs = json_decode((string)$g7BundleItem['attrs'], true);
assert_equals(1, (int)$g7BundleItem['backordered_qty'], 'G7 bundle: component shortage freezes one backordered kit');
assert_equals(2, count($g7BundleAttrs['_bundle_components'] ?? []), 'G7 bundle: component allocation snapshot is frozen on the order line');
$g7BackorderShipBlocked = false;
try { Fulfillment::shipOrder($g7BundleOrder); } catch (RuntimeException $e) { $g7BackorderShipBlocked = true; }
assert_true($g7BackorderShipBlocked, 'G7 backorder: final shipment fails closed until shortage is allocated');
$g7BundleShipments = Fulfillment::planOrder($g7BundleOrder);
assert_equals(1, count($g7BundleShipments), 'G7 bundle: available components are grouped into the source warehouse shipment');
assert_true(Fulfillment::ship($g7BundleShipments[0]), 'G7 fulfillment: available bundle components ship partially');
assert_true(!Fulfillment::ship($g7BundleShipments[0]), 'G7 fulfillment: repeated shipment cannot double-consume stock');
assert_equals('partially_fulfilled', (string)Orders::items($g7BundleOrder)[0]['fulfillment_status'], 'G7 fulfillment: backordered bundle remains partially fulfilled');

$g7SplitOrder = Orders::create([[
    'product_id' => $g7ProductIds['G7-SPLIT'], 'variant_id' => null, 'sku' => 'G7-SPLIT',
    'name' => 'G7 Split Product', 'qty' => 3, 'price' => 900, 'attrs' => [],
]], $g7Customer, 'bank_transfer');
$g7SplitShipments = Fulfillment::planOrder($g7SplitOrder);
assert_equals(2, count($g7SplitShipments), 'G7 fulfillment: one order line splits across two warehouses');
assert_true(Fulfillment::ship($g7SplitShipments[0]), 'G7 fulfillment: first partial warehouse shipment succeeds');
assert_equals('partially_fulfilled', (string)Orders::items($g7SplitOrder)[0]['fulfillment_status'], 'G7 fulfillment: line reports partial state after first shipment');
assert_true(Fulfillment::ship($g7SplitShipments[1]), 'G7 fulfillment: second warehouse shipment succeeds');
assert_equals('fulfilled', (string)Orders::items($g7SplitOrder)[0]['fulfillment_status'], 'G7 fulfillment: line is fulfilled only after all warehouse shipments');
assert_equals(0, Warehouse::totalAvailable('G7-SPLIT'), 'G7 fulfillment: shipment consumes exact reserved on-hand stock');

$g7CancelOrder = Orders::create([[
    'product_id' => $g7ProductIds['G7-CANCEL'], 'variant_id' => null, 'sku' => 'G7-CANCEL',
    'name' => 'G7 Partial Cancel Product', 'qty' => 3, 'price' => 800, 'attrs' => [],
]], $g7Customer, 'bank_transfer');
OrderWorkflow::transition($g7CancelOrder, 'paid');
OrderWorkflow::transition($g7CancelOrder, 'processing');
$g7CancelShipments = Fulfillment::planOrder($g7CancelOrder);
assert_true(Fulfillment::ship($g7CancelShipments[0]), 'G7 cancellation: first warehouse can ship before order cancellation');
OrderWorkflow::transition($g7CancelOrder, 'cancelled');
assert_equals(2, Warehouse::totalAvailable('G7-CANCEL'), 'G7 cancellation: only unshipped warehouse reservation is released');
assert_equals(0, (int)$pdo->query("SELECT SUM(reserved) FROM warehouse_stock WHERE sku='G7-CANCEL'")->fetchColumn(), 'G7 cancellation: no reservation leaks after partial shipment cancellation');

$g7Release = Warehouse::reserve('G7-REL', 2);
Warehouse::release('G7-REL', $g7Release, 'test', 'g7-release');
$g7DoubleReleaseBlocked = false;
try { Warehouse::release('G7-REL', $g7Release, 'test', 'g7-release-again'); } catch (RuntimeException $e) { $g7DoubleReleaseBlocked = true; }
assert_true($g7DoubleReleaseBlocked, 'G7 reservation: exact release guard rejects a double restore');
assert_equals(0, (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE warehouse_id={$g7Main} AND sku='G7-REL'")->fetchColumn(), 'G7 reservation: failed double release leaves reservation unchanged');

$pdo->exec("INSERT INTO suppliers (store_id, code, name) VALUES ('default','g7-supplier','G7 Supplier')");
$g7Supplier = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO supplier_products (supplier_id, sku, cost_price, min_order_qty, pack_size) VALUES (:supplier,'G7-COMP-A',250,4,2)")
    ->execute([':supplier' => $g7Supplier]);
$g7MinimumBlocked = false;
try { Inventory::createPurchaseOrder($g7Supplier, $g7Main, 'G7-PO-BAD', [['sku' => 'G7-COMP-A', 'quantity' => 3]]); } catch (InvalidArgumentException $e) { $g7MinimumBlocked = true; }
assert_true($g7MinimumBlocked, 'G7 procurement: supplier minimum and pack size fail closed');
$g7Po = Inventory::createPurchaseOrder($g7Supplier, $g7Main, 'G7-PO-1', [['sku' => 'G7-COMP-A', 'quantity' => 4]]);
assert_true(Inventory::receivePurchaseOrder($g7Po, 'G7-COMP-A', 2, 'g7-receipt-1'), 'G7 procurement: partial PO receipt posts stock');
assert_true(!Inventory::receivePurchaseOrder($g7Po, 'G7-COMP-A', 2, 'g7-receipt-1'), 'G7 procurement: repeated receipt idempotency key is a no-op');
assert_equals(2, (int)$pdo->query("SELECT received_qty FROM purchase_order_items WHERE purchase_order_id={$g7Po}")->fetchColumn(), 'G7 procurement: repeated receipt cannot overstate received quantity');
assert_equals(2, (int)$pdo->query("SELECT qty FROM warehouse_stock WHERE warehouse_id={$g7Main} AND sku='G7-COMP-A'")->fetchColumn(), 'G7 procurement: physical stock matches the single accepted receipt');
$g7MovementTypes = $pdo->query("SELECT DISTINCT movement_type FROM stock_movements WHERE sku LIKE 'G7-%'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['count','reserve','release','shipment','receipt','backorder'] as $g7MovementType) {
    assert_true(in_array($g7MovementType, $g7MovementTypes, true), "G7 ledger: {$g7MovementType} movement is auditable");
}
$g7LedgerRows = $pdo->query("SELECT quantity_delta, reserved_delta, on_hand_after, reserved_after FROM stock_movements WHERE sku='G7-SPLIT' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
assert_true(count($g7LedgerRows) >= 6, 'G7 ledger: split stock keeps opening, reserve and shipment history per warehouse');
assert_true(array_reduce($g7LedgerRows, static fn(bool $ok, array $row): bool => $ok && (int)$row['on_hand_after'] >= 0 && (int)$row['reserved_after'] >= 0, true), 'G7 ledger: every resulting stock snapshot preserves non-negative invariants');

// ============================================================
// G10: theme/content/marketing/i18n quality layer
// ============================================================
echo "\n--- G10: Theme, content, feeds, consent, and quality gates ---\n";
Bootstrap::initStoreContext('/', '', 'default');
I18n::setLocale('en');
$g10Manifest = Template::manifest('default');
assert_equals('BareBones Default', (string)$g10Manifest['name'], 'G10 theme: package manifest loads');
assert_true(in_array('accessibility.css', $g10Manifest['assets']['css'], true) && in_array('storefront.css', $g10Manifest['assets']['css'], true), 'G10 theme: manifest declares versioned CSS assets');
assert_true(in_array('storefront.js', $g10Manifest['assets']['js'], true), 'G10 theme: storefront behavior belongs to the theme package');
assert_true(str_contains(Template::assets()['css'][0], '?v=' . $g10Manifest['version']), 'G10 theme: asset URL carries manifest version');
$g10Document = Template::document('G10 document', '<h1>Content</h1>', [
    'body_end' => '<script nonce="test-nonce">window.BBS={};</script>',
    'marketing_hooks' => '',
]);
assert_true(str_contains($g10Document, 'href="#main-content"') && str_contains($g10Document, '<main id="main-content"'), 'G10 WCAG: shared shell has a functional skip link');
assert_true(str_contains($g10Document, '<header') && str_contains($g10Document, '<footer'), 'G10 theme: shared shell owns header and footer blocks');
assert_true(str_contains($g10Document, 'nonce="test-nonce"'), 'G11 theme: shared shell renders a CSP-safe body_end bootstrap slot');
foreach (['sk', 'cs', 'pl', 'hu', 'de', 'en', 'es'] as $renderLocale) {
    I18n::setLocale($renderLocale);
    $localizedShell = Template::document('Localized shell', '<p>Content</p>', ['marketing_hooks' => '']);
    assert_true(str_contains($localizedShell, '<html lang="' . $renderLocale . '">'), "G10 i18n render: {$renderLocale} sets the document language");
    assert_true(str_contains($localizedShell, I18n::t('search_placeholder')), "G10 i18n render: {$renderLocale} renders translated theme text");
    assert_true(!str_contains($localizedShell, '{{'), "G10 i18n render: {$renderLocale} leaves no unresolved theme placeholders");
}
I18n::setLocale('en');
$originalScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
$_SERVER['SCRIPT_NAME'] = '/test/index.php';
assert_true(str_starts_with(Template::assets()['css'][0], '/test/themes/default/assets/'), 'G10 theme: assets inherit a subdirectory installation prefix');
assert_true(str_contains(Template::document('Subdirectory', '<p>Content</p>', ['marketing_hooks' => '']), 'href="/test/shop.css"'), 'G10 theme: core assets inherit a subdirectory installation prefix');
if ($originalScriptName === null) unset($_SERVER['SCRIPT_NAME']); else $_SERVER['SCRIPT_NAME'] = $originalScriptName;
$g11CustomBody = Template::document('G11 custom body', '', [
    'body_html' => '<header id="legacy-header"></header><main id="main-content"></main>',
    'base_css' => '',
    'shop_js' => '',
    'marketing_hooks' => '',
]);
assert_true(str_contains($g11CustomBody, 'id="legacy-header"'), 'G11 theme: shared shell preserves an entrypoint-specific body without escaping it');
assert_equals(1, substr_count(strtolower($g11CustomBody), '<main'), 'G11 theme: custom body does not receive a duplicate main landmark');
foreach (['index.php', 'wishlist.php', 'account.php', 'page.php'] as $sharedShellEntrypoint) {
    $sharedShellSource = entrypointSource($sharedShellEntrypoint);
    assert_true(str_contains($sharedShellSource, 'Template::document('), 'G11 theme: ' . $sharedShellEntrypoint . ' uses the shared document shell');
    assert_true(!str_contains(strtolower($sharedShellSource), '<!doctype html>'), 'G11 theme: ' . $sharedShellEntrypoint . ' does not duplicate the document shell');
}
assert_true(str_contains(entrypointSource('product.php'), "src/Entrypoints/Storefront.php"), 'G11 theme: product.php delegates to the shared storefront document shell');

$g10MenuId = ContentService::saveMenu(['menu_code' => 'primary', 'locale' => 'en', 'label' => 'G10 Sale', 'url' => '/sale', 'active' => 1]);
$g10BannerId = ContentService::saveBanner(['slot' => 'homepage', 'locale' => 'en', 'title' => 'G10 Banner', 'body' => 'Campaign', 'link_url' => '/sale', 'active' => 1]);
$g10RedirectId = ContentService::saveRedirect(['source_path' => '/g10-old', 'target_url' => '/g10-new', 'http_status' => 308, 'active' => 1]);
$g10LandingId = ContentService::saveLanding(['slug' => 'g10-landing', 'title' => 'G10 Landing', 'body' => '<p>Safe</p><script>bad()</script>', 'seo_title' => 'G10 SEO', 'meta_description' => 'G10 description', 'status' => 'active']);
assert_equals('G10 Sale', (string)ContentService::menu('primary', 'en')[0]['label'], 'G10 content: localized store menu publishes');
assert_equals('G10 Banner', (string)ContentService::banners('homepage', 'en')[0]['title'], 'G10 content: scheduled banner publishes');
assert_equals('/g10-new', (string)ContentService::redirect('/g10-old')['target_url'], 'G10 content: exact-path redirect resolves');
$g10Landing = ContentService::page('g10-landing', 'en');
assert_equals('landing', (string)$g10Landing['page_type'], 'G10 content: landing page has explicit type');
assert_true(!str_contains((string)$g10Landing['body'], '<script'), 'G10 content: landing rich HTML is sanitized at write boundary');
assert_true((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity LIKE 'content_%'")->fetchColumn() >= 4, 'G10 content: admin content mutations are audited');
StoreContext::activate($deContext);
assert_equals([], ContentService::menu('primary', 'en'), 'G10 content: another store cannot read default-store menu');
assert_true(ContentService::redirect('/g10-old') === null, 'G10 content: another store cannot resolve default-store redirect');
Bootstrap::initStoreContext('/', '', 'default');
I18n::setLocale('en');

$g10Category = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO products (store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, summary, images, gtin, mpn, availability)
    VALUES ('default', :category, 'G10-FEED', 'g10-feed', 'simple', 'G10 Feed Product', 1234, 'eur', 5, 'active', 'Feed description', '[\"/assets/img/logo.png\"]', '1234567890123', 'G10-MPN', 'active')")
    ->execute([':category' => $g10Category]);
$g10Google = MarketingFeed::google('https://shop.example', 'en');
assert_true(str_contains($g10Google, '<g:id>G10-FEED</g:id>') && str_contains($g10Google, '<g:price>12.34 EUR</g:price>'), 'G10 feeds: Google XML uses stable IDs and integer-cent price');
assert_true(str_contains($g10Google, '<g:availability>in stock</g:availability>'), 'G10 feeds: Google availability is normalized');
$g10Meta = MarketingFeed::meta('https://shop.example', 'en');
assert_true(str_contains($g10Meta, 'G10-FEED') && str_contains($g10Meta, '12.34 EUR'), 'G10 feeds: Meta CSV exports the same product snapshot');

assert_equals(['necessary' => true, 'analytics' => false, 'marketing' => false], MarketingConsent::categories([]), 'G10 consent: optional categories default off');
assert_equals('', MarketingConsent::hooks([], 'nonce'), 'G10 consent: no analytics hook is emitted before opt-in');
$g10Hooks = MarketingConsent::hooks(['cc_v1' => json_encode(['analytics' => true, 'marketing' => false])], 'g10-nonce');
assert_true(str_contains($g10Hooks, 'barebones:consent-ready') && str_contains($g10Hooks, 'g10-nonce'), 'G10 consent: opted-in analytics emits a nonce-bound first-party hook');
$g10Seo = Seo::head('Landing', 'Description', '/page/g10-landing');
assert_true(str_contains($g10Seo, 'rel="canonical"') && str_contains($g10Seo, 'hreflang="x-default"'), 'G10 SEO: canonical and hreflang metadata share one helper');

$g10En = require TEST_CLI_DIR . '/../lang/en.php';
$g10Es = require TEST_CLI_DIR . '/../lang/es.php';
assert_equals(array_keys($g10En), array_keys($g10Es), 'G10 i18n: Spanish has exact key parity with English');
assert_equals('Añadir al carrito', $g10Es['add_to_cart'], 'G10 i18n: Spanish storefront text is translated');
$g10Ci = (string)file_get_contents(TEST_CLI_DIR . '/../.github/workflows/ci.yml');
assert_true(str_contains($g10Ci, 'php cli/quality-gates.php'), 'G10 CI: WCAG and performance quality gate is mandatory');
$g10Htaccess = (string)file_get_contents(TEST_CLI_DIR . '/../.htaccess');
assert_true(str_contains($g10Htaccess, 'feeds/google\\.xml') && str_contains($g10Htaccess, 'feeds/meta\\.csv'), 'G10 feeds: public routes are explicit');
assert_true(str_contains($g10Htaccess, 'themes/[^/]+/(?:theme\\.json|blocks'), 'G10 theme: manifests and source blocks are denied over HTTP');

ContentService::delete('menu', $g10MenuId);
ContentService::delete('banner', $g10BannerId);
ContentService::delete('redirect', $g10RedirectId);
ContentService::delete('landing', $g10LandingId);
$pdo->exec("DELETE FROM products WHERE sku='G10-FEED'");

// ============================================================
// G12: canonical extensible storefront view models
// ============================================================
echo "\n--- G12: Canonical storefront view models ---\n";
Bootstrap::initStoreContext('/', '', 'default');
I18n::setLocale('en');
$vmCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$pdo->exec("DELETE FROM product_related_products WHERE product_id BETWEEN 9701 AND 9703 OR related_product_id BETWEEN 9701 AND 9703");
$pdo->exec("DELETE FROM products WHERE id BETWEEN 9701 AND 9703");
$vmInsert = $pdo->prepare("INSERT INTO products
    (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, summary, description, images, attrs, availability, inventory_policy)
    VALUES (:id, 'default', :category, :sku, :slug, :type, :name, :price, 'eur', :stock, 'active', :summary, :description, :images, :attrs, 'active', 'deny')");
$vmInsert->execute([':id' => 9701, ':category' => $vmCategory, ':sku' => 'VM-DIGITAL', ':slug' => 'vm-digital', ':type' => 'downloadable', ':name' => 'Canonical Product', ':price' => 1299, ':stock' => 0, ':summary' => 'Canonical summary', ':description' => '<p>Editorial <strong>content</strong><script>bad()</script></p>', ':images' => '["one.jpg","two.jpg"]', ':attrs' => '{"creator":"Generic Creator","binding":"digital"}']);
$vmInsert->execute([':id' => 9702, ':category' => $vmCategory, ':sku' => 'VM-CURATED', ':slug' => 'vm-curated', ':type' => 'simple', ':name' => 'Curated Target', ':price' => 990, ':stock' => 3, ':summary' => '', ':description' => '', ':images' => '[]', ':attrs' => '{}']);
$vmInsert->execute([':id' => 9703, ':category' => $vmCategory, ':sku' => 'VM-FORMAT', ':slug' => 'vm-format', ':type' => 'simple', ':name' => 'Format Target', ':price' => 1590, ':stock' => 2, ':summary' => '', ':description' => '', ':images' => '[]', ':attrs' => '{}']);
$pdo->exec("INSERT INTO product_attrs (product_id, attr_name, attr_value, numeric_val) VALUES (9701, 'Generic field', 'Generic value', NULL)");
$pdo->exec("INSERT INTO product_specifications (product_id, lang, criteria_name, criteria_value, numeric_val, sort_order) VALUES (9701, 'en', 'Length', '42', 42, 0)");
$pdo->exec("INSERT INTO variants (product_id, sku, name, attrs, price_delta, stock, availability) VALUES (9701, 'VM-DIGITAL-A', 'Variant A', '{\"channel\":\"standard\"}', 0, 0, 'active')");
$pdo->exec("INSERT INTO variants (product_id, sku, name, attrs, price_absolute, stock, availability) VALUES (9703, 'VM-FORMAT-A', 'Format A', '{}', 1999, 4, 'active'), (9703, 'VM-FORMAT-OOS', 'Format OOS', '{}', 2499, 0, 'active')");
$pdo->exec("INSERT INTO product_related_products (product_id, related_product_id, relation_type, sort_order) VALUES (9701, 9702, 'related', 0), (9701, 9703, 'format', 0)");

$originalVmScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
$_SERVER['SCRIPT_NAME'] = '/test/index.php';
$deVmContext = StoreContext::resolve(Bootstrap::stores(), '/de/product/vm-digital');
StoreContext::activate($deVmContext);
Template::activate($deVmContext);
I18n::setLocale('de');
StorefrontViewModelRegistry::reset();
StorefrontViewModelRegistry::register('product', 'contract_test', static fn(array $context): array => ['product_id' => (int)$context['model']['product']['id']]);
$vm = StorefrontViewModel::product(Products::byId(9701));
assert_equals('product', $vm['page']['type'], 'G12 view model: product has a stable page type');
assert_equals(2, count($vm['product']['images']), 'G12 view model: all product images are exposed');
assert_true(count($vm['product']['attributes']) >= 2, 'G12 view model: generic attributes and specifications are exposed');
assert_equals(1, count($vm['product']['variants']), 'G12 view model: variants are exposed');
assert_true(count($vm['product']['categories']) >= 1 && count($vm['page']['breadcrumbs']) >= 3, 'G12 view model: categories and breadcrumb include the legacy primary category');
assert_equals('Generic Creator', $vm['product']['custom_fields']['creator'], 'G12 view model: unknown custom fields survive without core-specific names');
assert_true(str_contains($vm['product']['description_html'], '<strong>content</strong>') && !str_contains($vm['product']['description_html'], '<script'), 'G12 view model: editorial HTML is sanitized but preserved');
assert_true($vm['product']['availability']['purchasable'] && !$vm['product']['availability']['tracks_stock'] && $vm['product']['availability']['quantity'] === 0, 'G12 availability: stock-zero digital product remains purchasable without stock tracking');
assert_equals('VM-CURATED', $vm['product']['relations']['related'][0]['sku'], 'G12 relations: curated related product wins over category fallback');
assert_equals('VM-FORMAT', $vm['product']['relations']['format'][0]['sku'], 'G12 relations: arbitrary format relation uses the canonical card model');
assert_true(!isset($vm['extensions']['automotive']), 'G12 extensions: product without automotive data has no automotive payload or UI signal');
assert_equals(9701, $vm['extensions']['contract_test']['product_id'], 'G12 extensions: custom namespaced provider augments the product model');
assert_true(str_starts_with($vm['product']['url'], '/test/de/product/') && str_starts_with($vm['product']['images'][0]['url'], '/test/uploads/full/'), 'G12 URLs: locale-aware product and subdirectory-aware image URLs are canonical');
$forbiddenHtmlKeys = [];
$walkVm = static function(array $data, string $path = '') use (&$walkVm, &$forbiddenHtmlKeys): void {
    foreach ($data as $key => $value) {
        $next = $path === '' ? (string)$key : $path . '.' . $key;
        if (is_string($key) && str_ends_with($key, '_html') && !in_array($key, ['description_html', 'body_html'], true)) $forbiddenHtmlKeys[] = $next;
        if (is_array($value)) $walkVm($value, $next);
    }
};
$walkVm($vm);
assert_equals([], $forbiddenHtmlKeys, 'G12 view model: no structural HTML fragments cross the engine/theme boundary');
$productRouteSource = (string)file_get_contents(TEST_CLI_DIR . '/../product.php');
$catalogRouteSource = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Storefront/CatalogPages.php');
assert_true(str_contains($productRouteSource, "src/Entrypoints/Storefront.php") && !str_contains($productRouteSource, 'ob_start'), 'G12 routes: clean product URL delegates without a parallel renderer');
assert_equals(1, substr_count($catalogRouteSource, 'StorefrontViewModel::product('), 'G12 routes: query and clean product routes converge on one canonical builder');
assert_equals('/de/product/vm-digital', Template::seoPath($vm['page']['seo']['canonical_url']), 'G12 SEO: deployment base path is not duplicated when joined to app_url');
// Exercise the real SEO renderer with isolated config.php files, never the local installation config.
$breadcrumbFixture = test_fixture_path('product-breadcrumb');
mkdir($breadcrumbFixture . '/src', 0770, true);
copy(TEST_CLI_DIR . '/../src/StorefrontViewModel.php', $breadcrumbFixture . '/src/StorefrontViewModel.php');
copy(TEST_CLI_DIR . '/../src/Seo.php', $breadcrumbFixture . '/src/Seo.php');
file_put_contents($breadcrumbFixture . '/config.php', '<?php return ["app_url" => $GLOBALS["argv"][3]];');
file_put_contents($breadcrumbFixture . '/render.php', '<?php
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';
require __DIR__ . "/src/StorefrontViewModel.php";
require __DIR__ . "/src/Seo.php";
Db::init(new PDO("sqlite:" . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
$_SERVER["SCRIPT_NAME"] = $argv[2];
$stores = Bootstrap::stores();
$stores["stores"]["default"]["url_prefix"] = $argv[4];
$context = StoreContext::resolve($stores, "/", "", "default");
StoreContext::activate($context);
Template::activate($context);
I18n::setLocale($argv[4] === "/de" ? "de" : "en");
$category = Categories::byId((int)$argv[5]);
UrlAliases::setRules($argv[6] === "alias" ? ["/knihy" => "/category/" . $category["path"]] : []);
$product = Products::byId(9701);
$product["name"] = "Canonical Product & <Guide>";
$model = StorefrontViewModel::product($product);
echo json_encode(["head" => Template::viewModelSeoHead($model, "Product"), "breadcrumbs" => $model["page"]["breadcrumbs"], "categories" => $model["product"]["categories"]], JSON_THROW_ON_ERROR);
');
try {
    foreach ([
        ['root', '/index.php', 'https://shop.example/', '', '/'],
        ['subdirectory', '/test/index.php', 'https://shop.example/test/', '', '/test/'],
        ['localized', '/test/index.php', 'https://shop.example/test', '/de', '/test/de/'],
    ] as [$deployment, $script, $appUrl, $localePrefix, $uiPrefix]) {
        foreach (['core', 'alias'] as $urlScheme) {
            $label = "G12 breadcrumb {$deployment}/{$urlScheme}";
            $process = proc_open([PHP_BINARY, $breadcrumbFixture . '/render.php', $testDbPath, $script, $appUrl, $localePrefix, (string)$vmCategory, $urlScheme],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            assert_equals([0, ''], [proc_close($process), $stderr], "{$label}: renderer exits successfully without PHP errors");
            $rendered = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            preg_match_all('#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s', $rendered['head'], $scripts);
            $payloads = array_map(static fn(string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $scripts[1]);
            $trails = array_values(array_filter($payloads, static fn(array $payload): bool => ($payload['@type'] ?? '') === 'BreadcrumbList'));
            assert_equals(1, count($trails), "{$label}: SEO head renders one BreadcrumbList");
            $trail = $trails[0]['itemListElement'];
            $categoryPath = $urlScheme === 'alias' ? 'knihy' : 'category/' . rawurlencode((string)Categories::byId($vmCategory)['path']);
            $absolutePrefix = 'https://shop.example' . $uiPrefix;
            assert_equals([$absolutePrefix, $absolutePrefix . $categoryPath], array_column($trail, 'item'), "{$label}: home and category have exact absolute URLs without duplicated /test");
            assert_equals([1, 2, 3], array_column($trail, 'position'), "{$label}: category order and positions are preserved");
            assert_equals('Canonical Product & <Guide>', $trail[2]['name'], "{$label}: leaf retains the exact product name");
            assert_true(!array_key_exists('item', $trail[2]), "{$label}: empty leaf URL omits item");
            assert_equals([
                ['label' => (require TEST_CLI_DIR . '/../lang/' . ($localePrefix === '/de' ? 'de' : 'en') . '.php')['home'], 'url' => $uiPrefix, 'current' => false],
                ['label' => $rendered['categories'][0]['name'], 'url' => $uiPrefix . $categoryPath, 'current' => false],
                ['label' => 'Canonical Product & <Guide>', 'url' => '', 'current' => true],
            ], $rendered['breadcrumbs'], "{$label}: UI breadcrumbs remain relative and unchanged");
            assert_equals($rendered['categories'][0]['name'], $trail[1]['name'], "{$label}: category name remains unchanged");
            assert_equals($uiPrefix . $categoryPath, $rendered['categories'][0]['url'], "{$label}: category URL remains relative");
        }
    }
} finally {
    foreach (['src/StorefrontViewModel.php', 'src/Seo.php', 'config.php', 'render.php'] as $fixtureFile) unlink($breadcrumbFixture . '/' . $fixtureFile);
    rmdir($breadcrumbFixture . '/src');
    rmdir($breadcrumbFixture);
}
$renderedProduct = Template::page('product', $vm);
assert_true(str_contains($renderedProduct, 'Canonical Product') && str_contains($renderedProduct, 'name="variant-9701"'), 'G12 renderer: complete default product block renders nested variant markup');
assert_true(!str_contains($renderedProduct, '{{'), 'G12 renderer: complete product HTML contains no unresolved directives');
$renderedProductList = Template::render('storefront/section-product-list', [
    'id' => 'products', 'content' => ['heading' => 'Products', 'body_html' => ''],
    'settings' => ['variant' => 'default'],
    'data' => ['items' => [$vm['product']['relations']['related'][0]]], 't' => I18n::all(),
]);
assert_true(str_contains($renderedProductList, I18n::t('add_to_cart')) && !str_contains($renderedProductList, '{{'), 'G12 renderer: section product list resolves parent translations');
$renderedAccount = Template::page('account', AccountViewModel::build('login', null, ['de', 'en'], [], null, null));
assert_true(str_contains($renderedAccount, 'name="action" value="login"') && !str_contains($renderedAccount, '{{'), 'G12 renderer: account uses theme-owned markup without unresolved directives');
assert_true(str_contains($renderedAccount, '<label for="account-login-email">' . esc(I18n::t('account.email')) . '</label>'), 'G12 renderer: dotted translation keys produce accessible account labels');
assert_true(str_contains($renderedAccount, '<button class="btn">' . esc(I18n::t('account.login')) . '</button>'), 'G12 renderer: dotted translation keys produce accessible account controls');
$renderedWishlist = Template::page('wishlist', [
    'wishlist' => ['items' => [$vm['product']['relations']['related'][0]], 'action_url' => Template::storeUrl('/wishlist.php'), 'csrf_token' => Security::csrfToken()],
    't' => I18n::all(),
]);
assert_true(str_contains($renderedWishlist, 'name="op" value="remove"') && !str_contains($renderedWishlist, '{{'), 'G12 renderer: wishlist uses canonical product cards without unresolved directives');

$_SESSION['cart'] = [];
$plainAdd = Cart::addValidated(9702, null, 2);
assert_true($plainAdd['ok'] && (int)$plainAdd['item']['price'] === 990, 'G12 cart: product without variants uses canonical server price');
$formatVariantIds = $pdo->query("SELECT id FROM variants WHERE product_id=9703 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$digitalVariantId = (int)$pdo->query("SELECT id FROM variants WHERE product_id=9701 LIMIT 1")->fetchColumn();
assert_equals('variant_required', Cart::addValidated(9703, null, 1)['error'], 'G12 cart: variant product requires a selection');
$validVariantAdd = Cart::addValidated(9703, (int)$formatVariantIds[0], 1);
assert_true($validVariantAdd['ok'] && (int)$validVariantAdd['item']['price'] === 1999, 'G12 cart: valid variant uses server-side absolute price');
assert_equals('variant_invalid', Cart::addValidated(9703, $digitalVariantId, 1)['error'], 'G12 cart: variant belonging to another product is rejected');
assert_equals('variant_invalid', Cart::addValidated(9703, 999999, 1)['error'], 'G12 cart: unknown variant is rejected');
assert_equals('product_unavailable', Cart::addValidated(9703, (int)$formatVariantIds[1], 1)['error'], 'G12 cart: unavailable variant is rejected');
assert_true(Cart::addValidated(9701, $digitalVariantId, 1)['ok'], 'G12 cart: stockless digital variant remains purchasable');
Cart::clear();

$pdo->exec("UPDATE products SET status='archived' WHERE id=9703");
assert_true(Products::bySlug('vm-format') === null && Products::bySlugAnyStatus('vm-format')['status'] === 'archived', 'G12 routes: inactive lookup preserves archived-product 410 handling');
$productStatusRouteSource = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Storefront/ProductStatusPages.php');
assert_true(str_contains($productStatusRouteSource, 'http_response_code(404)') && str_contains($productStatusRouteSource, 'http_response_code(410)'), 'G12 routes: missing and archived products retain real HTTP status codes');
$productBlockSource = (string)file_get_contents(TEST_CLI_DIR . '/../themes/default/blocks/storefront/product-page.html');
assert_true(str_contains($productBlockSource, 'data-review-form'), 'G12 theme boundary: review submission remains owned by the product theme block');

$safeSectionsJson = ContentService::sanitizeSections([['id' => 'campaign', 'type' => 'campaign', 'block' => '../../src/evil.php', 'body_html' => '<p>Safe</p><script>bad()</script>', 'props' => ['layout' => 'wide', 'payload_html' => '<script>bad()</script>']]]);
$safeSections = json_decode($safeSectionsJson, true, 32, JSON_THROW_ON_ERROR);
assert_true(!isset($safeSections[0]['block']) && !isset($safeSections[0]['props']['payload_html']), 'G12 CMS: stored sections cannot select files or smuggle raw HTML props');
assert_true(str_contains($safeSections[0]['content']['body_html'], '<p>Safe</p>') && !str_contains($safeSections[0]['content']['body_html'], '<script'), 'G12 CMS: body_html is sanitized at write boundary');

$vmMigrationDb = new PDO('sqlite::memory:');
$vmMigrationDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$vmMigrationDb->exec('CREATE TABLE products (id INTEGER PRIMARY KEY)');
$vmMigrationDb->exec('CREATE TABLE product_related_products (product_id INTEGER NOT NULL, related_product_id INTEGER NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(product_id, related_product_id))');
$vmMigrationDb->exec("INSERT INTO product_related_products VALUES (1, 2, 7)");
$vmMigrationDb->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY)');
$vmMigration = require TEST_CLI_DIR . '/../migrations/20260804180000_storefront_view_models.php';
$vmMigration->up($vmMigrationDb, true);
$pageLayoutMigration = require TEST_CLI_DIR . '/../migrations/20260804210000_storefront_page_layouts.php';
$pageLayoutMigration->up($vmMigrationDb, true);
$pageLayoutMigration->up($vmMigrationDb, true);
assert_equals('related', (string)$vmMigrationDb->query('SELECT relation_type FROM product_related_products')->fetchColumn(), 'G12 migration: legacy relations are backfilled as related');
$pageColumns = array_column($vmMigrationDb->query('PRAGMA table_info(pages)')->fetchAll(PDO::FETCH_ASSOC), 'name');
assert_true(in_array('sections', $pageColumns, true), 'G12 migration: CMS sections column is added');
assert_true(in_array('layout', $pageColumns, true), 'G12 migration: page layout column is added idempotently');

$themeFixture = sys_get_temp_dir() . '/bbe-theme-inheritance-' . getmypid();
$themeBlocksFixture = $themeFixture . '/base-blocks';
@mkdir($themeFixture . '/default/blocks/layout', 0770, true);
@mkdir($themeFixture . '/default/blocks/demo', 0770, true);
@mkdir($themeFixture . '/default/blocks/storefront', 0770, true);
@mkdir($themeFixture . '/child/blocks/storefront', 0770, true);
@mkdir($themeBlocksFixture, 0770, true);
file_put_contents($themeFixture . '/default/theme.json', json_encode(['name' => 'Parent', 'version' => '1.0.0', 'assets' => ['css' => [], 'js' => []], 'sections' => ['product-page' => 'storefront/product-page', 'campaign' => 'demo/campaign']], JSON_THROW_ON_ERROR));
file_put_contents($themeFixture . '/child/theme.json', json_encode(['name' => 'Child', 'version' => '1.0.0', 'parent' => 'default', 'assets' => ['css' => [], 'js' => []]], JSON_THROW_ON_ERROR));
file_put_contents($themeFixture . '/default/layouts.json', json_encode(['layouts' => ['storefront' => ['slots' => ['full']]], 'pages' => ['product' => ['layout' => 'storefront', 'sections' => [['block' => 'product-page']]], 'campaign' => ['layout' => 'storefront', 'sections' => []]]], JSON_THROW_ON_ERROR));
file_put_contents($themeFixture . '/child/layouts.json', json_encode(['pages' => ['campaign' => ['sections' => [['type' => 'campaign']]]]], JSON_THROW_ON_ERROR));
file_put_contents($themeFixture . '/default/blocks/layout/storefront.html', '{{{slots.full}}}');
file_put_contents($themeFixture . '/default/blocks/storefront/product-page.html', 'parent-product');
file_put_contents($themeFixture . '/default/blocks/demo/campaign.html', 'campaign:{{heading}}');
file_put_contents($themeFixture . '/child/blocks/storefront/product-page.html', 'child-product:{{product.sku}}');
Template::setThemesDir($themeFixture);
Template::setBlocksDir($themeBlocksFixture);
Template::setStore('default');
Template::setTheme('child');
assert_equals('child-product:VM-DIGITAL', Template::page('product', $vm), 'G12 theme inheritance: child overrides one product block without copying the page');
assert_equals('campaign:Theme Campaign', Template::page('campaign', ['page' => ['sections' => [['id' => 'x', 'type' => 'campaign', 'heading' => 'Theme Campaign']]]]), 'G12 CMS: child theme adds a custom section without src changes');
assert_true(isset(Template::loadLayouts()['pages']['product']), 'G12 theme inheritance: parent page definitions remain available');
Template::setThemesDir(TEST_CLI_DIR . '/../themes');
Template::setBlocksDir(TEST_CLI_DIR . '/../blocks');
Bootstrap::initStoreContext('/', '', 'default');
I18n::setLocale('en');
if ($originalVmScriptName === null) unset($_SERVER['SCRIPT_NAME']); else $_SERVER['SCRIPT_NAME'] = $originalVmScriptName;
foreach ([$themeFixture . '/child/blocks/storefront/product-page.html', $themeFixture . '/default/blocks/demo/campaign.html', $themeFixture . '/default/blocks/storefront/product-page.html', $themeFixture . '/default/blocks/layout/storefront.html', $themeFixture . '/child/layouts.json', $themeFixture . '/default/layouts.json', $themeFixture . '/child/theme.json', $themeFixture . '/default/theme.json'] as $fixtureFile) @unlink($fixtureFile);
foreach ([$themeFixture . '/child/blocks/storefront', $themeFixture . '/child/blocks', $themeFixture . '/child', $themeFixture . '/default/blocks/demo', $themeFixture . '/default/blocks/storefront', $themeFixture . '/default/blocks/layout', $themeFixture . '/default/blocks', $themeFixture . '/default', $themeBlocksFixture, $themeFixture] as $fixtureDir) @rmdir($fixtureDir);
$pdo->exec("DELETE FROM product_related_products WHERE product_id BETWEEN 9701 AND 9703 OR related_product_id BETWEEN 9701 AND 9703");
$pdo->exec("DELETE FROM product_attrs WHERE product_id BETWEEN 9701 AND 9703");
$pdo->exec("DELETE FROM product_specifications WHERE product_id BETWEEN 9701 AND 9703");
$pdo->exec("DELETE FROM variants WHERE product_id BETWEEN 9701 AND 9703");
$pdo->exec("DELETE FROM products WHERE id BETWEEN 9701 AND 9703");
StorefrontViewModelRegistry::reset();

// ============================================================
// G15: active health/SLO metrics and alerting adapter
// ============================================================
echo "\n--- G15: Operational metrics and alerting ---\n";
$g15CheckoutBlock = (string)file_get_contents(TEST_CLI_DIR . '/../themes/default/blocks/storefront/checkout.html');
foreach (['checkout-name', 'checkout-email', 'checkout-phone', 'checkout-street', 'checkout-city', 'checkout-zip', 'checkout-country', 'checkout-notes'] as $g15CheckoutControl) {
    assert_true(str_contains($g15CheckoutBlock, 'for="' . $g15CheckoutControl . '"'), "G15 WCAG: checkout label targets {$g15CheckoutControl}");
    assert_true(str_contains($g15CheckoutBlock, 'id="' . $g15CheckoutControl . '"'), "G15 WCAG: checkout control exposes {$g15CheckoutControl}");
}
$g15Now = strtotime('2026-08-02T09:00:30+00:00');
$g15Config = require TEST_CLI_DIR . '/../config.php';
$g15Config['jobs_dir'] = sys_get_temp_dir();
$g15Config['private_cache_dir'] = sys_get_temp_dir();
$g15Config['monitoring'] = [
    'backup_rpo_seconds' => 86400,
    'queue_pending_warning' => 999999,
    'failed_jobs_warning' => 999999,
    'failed_mail_warning' => 999999,
    'payment_incidents_critical' => 999999,
    'webhook_incidents_warning' => 999999,
];
$g15Snapshot = OperationalMetrics::snapshot($g15Config, dirname(TEST_CLI_DIR), $pdo, 'default', [[
    'meta' => ['timestamp' => '2026-08-02T09:00:00+00:00'],
    'mtime' => $g15Now - 30,
]], $g15Now);
assert_equals('ok', $g15Snapshot['status'], 'G15 metrics: healthy bounded snapshot has no alert');
assert_equals(1, $g15Snapshot['metrics']['service_ready'], 'G15 metrics: readiness is exported as a numeric signal');
assert_equals(30, $g15Snapshot['metrics']['backup_age_seconds'], 'G15 metrics: newest backup age is exported');

$g15BreachedConfig = $g15Config;
$g15BreachedConfig['monitoring']['backup_rpo_seconds'] = 10;
$g15Breach = OperationalMetrics::snapshot($g15BreachedConfig, dirname(TEST_CLI_DIR), $pdo, 'default', [[
    'meta' => ['timestamp' => '2026-08-02T09:00:00+00:00'],
]], $g15Now);
assert_equals('critical', $g15Breach['status'], 'G15 metrics: RPO breach is critical');
assert_true(in_array('backup_rpo_breached', array_column($g15Breach['alerts'], 'code'), true), 'G15 metrics: RPO breach emits a stable alert code');

$g15MetricsPath = sys_get_temp_dir() . '/bbe-metrics-' . getmypid() . '.jsonl';
@unlink($g15MetricsPath);
$g15Adapter = new JsonlMetricAlert(['metrics_log_file' => $g15MetricsPath]);
$g15Adapter->publish($g15Snapshot['metrics'], $g15Breach['alerts'], ['store_id' => 'default', 'api_key' => 'must-not-leak']);
$g15Record = json_decode(trim((string)file_get_contents($g15MetricsPath)), true);
assert_equals(30, $g15Record['metrics']['backup_age_seconds'], 'G15 adapter: JSONL preserves numeric metrics');
assert_equals('[REDACTED]', $g15Record['context']['api_key'], 'G15 adapter: context secrets are redacted');
@unlink($g15MetricsPath);
$g15UnsafeMetricsPathRejected = false;
try { new JsonlMetricAlert(['metrics_log_file' => TEST_CLI_DIR . '/../metrics.jsonl']); }
catch (RuntimeException $e) { $g15UnsafeMetricsPathRejected = true; }
assert_true($g15UnsafeMetricsPathRejected, 'G15 adapter: public application-root path is rejected');
assert_true(Adapters::metrics() === null, 'G15 adapter: metrics export is optional by default');
$g15MonitorSource = (string)file_get_contents(TEST_CLI_DIR . '/monitor.php');
assert_true(str_contains($g15MonitorSource, 'OperationalMetrics::report()'), 'G15 monitoring: CLI publishes the active SLO snapshot');
$g15Runbook = (string)file_get_contents(TEST_CLI_DIR . '/../docs/OPERATIONS_RUNBOOK.md');
foreach (['99.9%', 'RPO', 'RTO', 'Incident response', 'Required live operator checks'] as $g15RunbookProof) {
    assert_true(str_contains($g15Runbook, $g15RunbookProof), "G15 runbook: documents {$g15RunbookProof}");
}

echo "\n--- Core drift guard ---\n";
$dgRoot = sys_get_temp_dir() . '/bb_drift_' . bin2hex(random_bytes(4));
$dgState = $dgRoot . '-state';
foreach (['src', 'themes/default', 'themes/shop', 'templates/legal', 'cache'] as $dgDir) mkdir($dgRoot . '/' . $dgDir, 0777, true);
file_put_contents($dgRoot . '/index.php', '<?php // core');
file_put_contents($dgRoot . '/config.php', '<?php return [];');
file_put_contents($dgRoot . '/src/Orders.php', '<?php // orders');
file_put_contents($dgRoot . '/src/Cart.php', '<?php // cart');
file_put_contents($dgRoot . '/themes/default/theme.css', 'a{}');
file_put_contents($dgRoot . '/themes/shop/theme.css', 'b{}');
file_put_contents($dgRoot . '/templates/legal/terms.html', 'terms');
file_put_contents($dgRoot . '/cache/x.json', '{}');
$dgGuard = new CoreIntegrity($dgRoot, $dgState);
assert_equals('no_manifest', $dgGuard->check()['status'], 'Drift guard: reports a missing baseline');
$dgRecorded = $dgGuard->record('test', '1.0.0');
assert_equals(4, $dgRecorded['files'], 'Drift guard: baseline covers core files only');
assert_equals('clean', $dgGuard->check()['status'], 'Drift guard: untouched core is clean');
file_put_contents($dgRoot . '/config.php', '<?php return ["changed" => true];');
file_put_contents($dgRoot . '/themes/shop/theme.css', 'b{color:red}');
file_put_contents($dgRoot . '/templates/legal/terms.html', 'new terms');
file_put_contents($dgRoot . '/cache/y.json', '{}');
assert_equals('clean', $dgGuard->check()['status'], 'Drift guard: config, shop theme, legal texts and runtime dirs are not core');
file_put_contents($dgRoot . '/src/Orders.php', '<?php // hand edit');
file_put_contents($dgRoot . '/src/Hotfix.php', '<?php // new');
unlink($dgRoot . '/src/Cart.php');
$dgReport = $dgGuard->check();
assert_equals('drift', $dgReport['status'], 'Drift guard: hand edit of core is detected');
assert_equals(['src/Orders.php'], $dgReport['modified'], 'Drift guard: lists modified core file');
assert_equals(['src/Hotfix.php'], $dgReport['added'], 'Drift guard: lists added core file');
assert_equals(['src/Cart.php'], $dgReport['missing'], 'Drift guard: lists deleted core file');
$dgGuard->record('update 1.0.1', '1.0.1');
assert_equals('clean', $dgGuard->check()['status'], 'Drift guard: re-recording after an update accepts the new core');
$dgCustom = new CoreIntegrity($dgRoot, $dgState, ['exclude' => ['src/Hotfix.php']]);
file_put_contents($dgRoot . '/src/Hotfix.php', '<?php // allowed local file');
assert_equals('drift', $dgGuard->check()['status'], 'Drift guard: default scope still sees the edit');
$dgCustom->record('custom');
file_put_contents($dgRoot . '/src/Hotfix.php', '<?php // edited again');
assert_equals('clean', $dgCustom->check()['status'], 'Drift guard: configured excludes are honoured');
$dgUpdateCli = (string)file_get_contents(TEST_CLI_DIR . '/update.php');
assert_true(substr_count($dgUpdateCli, 'updateRecordCoreBaseline($root') === 2, 'Drift guard: update apply and rollback re-record the baseline');
assert_true(is_file(TEST_CLI_DIR . '/../templates/emails/sk/core_drift_alert.html'), 'Drift guard: alert e-mail template exists');
$dgMail = MailService::render('core_drift_alert', 'sk', ['host' => 'shop.example', 'count' => 1, 'recorded_at' => 'now', 'files' => [['kind' => 'modified', 'path' => 'src/Orders.php']]]);
assert_true(str_contains($dgMail['subject'], 'shop.example') && str_contains($dgMail['html'], 'src/Orders.php'), 'Drift guard: alert names host and changed files');
$dgCleanup = static function (string $path) use (&$dgCleanup): void {
    if (is_dir($path)) { foreach (scandir($path) ?: [] as $n) if ($n !== '.' && $n !== '..') $dgCleanup($path . '/' . $n); @rmdir($path); }
    elseif (is_file($path)) @unlink($path);
};
$dgCleanup($dgRoot);
$dgCleanup($dgState);

// ============================================================
// Merchant low / critical stock alerts (warehouse availability, crossing-only e-mail)
// ============================================================
echo "\n--- Stock alerts: low / critical merchant e-mail ---\n";

require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shared.php';
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Catalog.php';

$saMail = new class implements MailAdapter {
    public array $sent = [];
    public bool $fail = false;
    public function send(array $msg): array {
        if ($this->fail) return ['status' => 'failed', 'message' => 'smtp down', 'raw' => null];
        $this->sent[] = $msg;
        return ['status' => 'sent', 'message' => '', 'raw' => null];
    }
    public function adapterId(): string { return 'stock-alert-test'; }
};
MailService::useAdapter($saMail);
StockAlerts::setConfigForTesting([
    'app_url' => 'https://shop.example',
    'merchant_notifications' => ['recipients' => ['owner@shop.example']],
    'stock_alerts' => ['low' => 5, 'critical' => 2, 'locale' => 'sk'],
]);
StockAlerts::flush();
$pdo->exec("DELETE FROM stock_alert_state");
$pdo->exec("DELETE FROM stock_alert_digests");
$saPreviousDefault = $pdo->query("SELECT id FROM warehouses WHERE is_default = 1")->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec("UPDATE warehouses SET is_default = 0");
$pdo->exec("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('sa-main', 'SA Main', 900, 1, 'default')");
$saWh = (int)$pdo->query("SELECT id FROM warehouses WHERE code = 'sa-main'")->fetchColumn();
$pdo->exec("INSERT INTO products (id, category_id, name, slug, sku, product_type, price, currency, status, stock) VALUES
    (9301, 1, 'Krvavé Slnko', 'sa-krvave-slnko', 'SA-BOOK', 'simple', 1500, 'eur', 'active', 0),
    (9302, 1, 'Druhá kniha', 'sa-druha-kniha', 'SA-BOOK2', 'simple', 1200, 'eur', 'active', 0),
    (9303, 1, 'E-kniha', 'sa-ebook', 'SA-EBOOK', 'downloadable', 500, 'eur', 'active', 0)");
Warehouse::setStock($saWh, 'SA-BOOK', 7);
Warehouse::setStock($saWh, 'SA-BOOK2', 10);
Warehouse::setStock($saWh, 'SA-EBOOK', 1);
Warehouse::syncProductStock(['SA-BOOK', 'SA-BOOK2', 'SA-EBOOK']);

assert_equals(1, StockAlerts::flush(), 'Stock alerts: stock movements queue one job per store');
$saJobs = array_values(array_filter(JobQueue::pull(1000), static fn(array $job): bool => ($job['type'] ?? '') === 'stock_alert'
    && in_array('SA-BOOK2', (array)($job['payload']['skus'] ?? []), true)));
assert_equals(1, count($saJobs), 'Stock alerts: the queued job carries every touched SKU');
assert_true(!array_diff(['SA-BOOK', 'SA-BOOK2', 'SA-EBOOK'], (array)($saJobs[0]['payload']['skus'] ?? [])), 'Stock alerts: touched SKUs are batched into that one job');

$saRun = static fn(array $skus = ['SA-BOOK', 'SA-BOOK2', 'SA-EBOOK']): array => StockAlerts::process($skus);
$saOrder = static function (string $sku, int $qty) {
    $product = Db::get()->query("SELECT id, name, price FROM products WHERE sku = " . Db::get()->quote($sku))->fetch(PDO::FETCH_ASSOC);
    return Orders::create([[
        'product_id' => (int)$product['id'], 'variant_id' => null, 'qty' => $qty, 'price' => (int)$product['price'],
        'sku' => $sku, 'name' => $product['name'], 'variant_name' => '',
    ]], ['name' => 'Stock Buyer', 'email' => 'stock-buyer@example.com', 'currency' => 'eur', 'shipping_cost' => 0, 'tax_amount' => 0], 'card');
};

assert_equals(0, $saRun()['alerts'], 'Stock alerts: products above low send nothing');
assert_equals(0, count($saMail->sent), 'Stock alerts: no e-mail while stock is healthy');

$saOrder('SA-BOOK', 2);
assert_equals(5, Warehouse::totalAvailable('SA-BOOK'), 'Stock alerts: availability is warehouse qty minus reservations');
$saRun();
assert_equals(1, count($saMail->sent), 'Stock alerts: ok → low sends one e-mail');
assert_true(str_contains($saMail->sent[0]['subject'], 'Krvavé Slnko') && str_contains($saMail->sent[0]['subject'], '(5 ks)'), 'Stock alerts: subject names the product and its available count');
assert_true(str_contains($saMail->sent[0]['html'], 'SA-BOOK') && str_contains($saMail->sent[0]['html'], 'https://shop.example/admin.php?p=product&amp;id=9301')
    && str_contains($saMail->sent[0]['html'], 'limit 5 ks'), 'Stock alerts: body lists SKU, threshold and admin product link');
assert_equals('owner@shop.example', $saMail->sent[0]['to'], 'Stock alerts: default recipients are merchant_notifications.recipients');
assert_true(str_starts_with($saMail->sent[0]['subject'], 'Nízky stav: '), 'Stock alerts: a low-only e-mail is subjected "Nízky stav"');
assert_true(str_contains($saMail->sent[0]['text'], 'https://shop.example/admin.php?p=product&id=9301') && !str_contains($saMail->sent[0]['text'], '&amp;'),
    'Stock alerts: plain-text links are not HTML-escaped');

$saOrder('SA-BOOK', 1);
$saRun();
assert_equals(1, count($saMail->sent), 'Stock alerts: another order inside the same band sends nothing');

$saOrder('SA-BOOK', 2);
$saRun();
assert_equals(2, count($saMail->sent), 'Stock alerts: low → critical sends a second e-mail');
assert_true(str_contains($saMail->sent[1]['html'], 'Kritický stav'), 'Stock alerts: critical crossing is labelled critical');
assert_true(str_starts_with($saMail->sent[1]['subject'], 'Kritický stav: Krvavé Slnko'), 'Stock alerts: subject follows the worst level (critical)');

$saCancelOrder = $saOrder('SA-BOOK', 2);
$saRun();
assert_equals(3, count($saMail->sent), 'Stock alerts: critical → 0 sends a third e-mail (ok→low→critical→0 = 3)');
assert_true(str_contains($saMail->sent[2]['html'], 'Vypredané'), 'Stock alerts: zero stock is reported as sold out');
assert_true(str_starts_with($saMail->sent[2]['subject'], 'Vypredané: Krvavé Slnko (0 ks)'), 'Stock alerts: subject follows the worst level (sold out)');

OrderWorkflow::transition($saCancelOrder, 'cancelled', 'stock alert test');
assert_equals(2, Warehouse::totalAvailable('SA-BOOK'), 'Stock alerts: cancellation returns the reservation');
$saRun();
assert_equals(3, count($saMail->sent), 'Stock alerts: cancellation (stock going up) sends nothing');
assert_equals('out', (string)$pdo->query("SELECT level FROM stock_alert_state WHERE sku = 'SA-BOOK'")->fetchColumn(), 'Stock alerts: partial recovery below low does not re-arm');

Warehouse::adjustStock($saWh, 'SA-BOOK', 20, 'receipt');
$saRun();
assert_equals('ok', (string)$pdo->query("SELECT level FROM stock_alert_state WHERE sku = 'SA-BOOK'")->fetchColumn(), 'Stock alerts: restock above low re-arms the SKU');
assert_equals(3, count($saMail->sent), 'Stock alerts: restock itself sends nothing');
assert_true(Warehouse::setAvailable('SA-BOOK', 4, 'manual_adjustment', 'test'), 'Stock alerts: manual edit is booked on the default warehouse');
assert_equals(4, (int)$pdo->query("SELECT stock FROM products WHERE sku = 'SA-BOOK'")->fetchColumn(), 'Stock alerts: manual edit keeps products.stock in sync');
$saRun();
assert_equals(4, count($saMail->sent), 'Stock alerts: a new drop after restock alerts again');

Warehouse::setAvailable('SA-BOOK', 1);
Warehouse::setAvailable('SA-BOOK2', 2);
$saRun();
assert_equals(5, count($saMail->sent), 'Stock alerts: two SKUs crossing in one run produce exactly one e-mail');
assert_true(str_contains($saMail->sent[4]['html'], 'SA-BOOK2') && str_contains($saMail->sent[4]['html'], 'Krvavé Slnko'), 'Stock alerts: the batched e-mail lists both SKUs');
assert_true(!array_filter($saMail->sent, static fn(array $m): bool => str_contains($m['html'], 'SA-EBOOK')), 'Stock alerts: e-books are never reported');
assert_true(!in_array('SA-EBOOK', array_column(StockAlerts::belowLow(), 'sku'), true), 'Stock alerts: e-books are not tracked even with a warehouse row');

// Delivery failure: state is rolled back so the retry alerts; checkout never touches the mailer.
Warehouse::adjustStock($saWh, 'SA-BOOK2', 48, 'receipt');
$saRun(['SA-BOOK2']);
$saMail->fail = true;
$saFailedCheckout = $saOrder('SA-BOOK2', 47);
assert_true($saFailedCheckout > 0, 'Stock alerts: checkout succeeds while the mail adapter is down');
assert_equals(5, count($saMail->sent), 'Stock alerts: checkout sends no stock e-mail synchronously');
$saThrown = false;
try { $saRun(['SA-BOOK2']); } catch (RuntimeException $e) { $saThrown = true; }
assert_true($saThrown, 'Stock alerts: failed delivery fails the job so the queue retries');
assert_equals('ok', (string)$pdo->query("SELECT level FROM stock_alert_state WHERE sku = 'SA-BOOK2'")->fetchColumn(), 'Stock alerts: failed delivery rolls the alert state back');
$saMail->fail = false;
$saRun(['SA-BOOK2']);
assert_equals(6, count($saMail->sent), 'Stock alerts: retry after the outage delivers the alert');

// Per-product override and shared dashboard thresholds.
$pdo->exec("UPDATE products SET stock_low_threshold = 30, stock_critical_threshold = 10 WHERE sku = 'SA-BOOK2'");
assert_equals([30, 10], StockAlerts::thresholds(['stock_low_threshold' => 30, 'stock_critical_threshold' => 10]), 'Stock alerts: product overrides replace the config default');
assert_equals([5, 2], StockAlerts::thresholds(['stock_low_threshold' => null, 'stock_critical_threshold' => null]), 'Stock alerts: empty overrides fall back to config');
assert_equals([3, 3], StockAlerts::thresholds(['stock_low_threshold' => 3, 'stock_critical_threshold' => 9]), 'Stock alerts: critical never exceeds low');
assert_equals(StockAlerts::OUT, StockAlerts::level(0, 0, 0), 'Stock alerts: zero is always critical/out');
$saSummary = StockAlerts::summary();
$saBySku = array_column($saSummary['products'], null, 'sku');
assert_equals(StockAlerts::CRITICAL, $saBySku['SA-BOOK']['level'] ?? '', 'Dashboard: uses the same thresholds as the e-mail');
assert_equals(30, (int)($saBySku['SA-BOOK2']['low'] ?? 0), 'Dashboard: honours the per-product threshold');
$_GET = ['p' => 'products', 'stock' => 'low'];
ob_start();
pageProducts();
$saProductsHtml = (string)ob_get_clean();
$_GET = [];
assert_true(str_contains($saProductsHtml, 'SA-BOOK') && !str_contains($saProductsHtml, 'SA-EBOOK') && str_contains($saProductsHtml, 'stock-critical'), 'Admin products: low-stock filter lists only alerting physical products');

// CSV import/export carry the thresholds; the import books stock in the ledger.
$saCsv = TEST_CLI_DIR . '/../db/stock-alert-import.csv';
file_put_contents($saCsv, "sku,name,category,price,stock,stock_low_threshold,stock_critical_threshold\nSA-BOOK,Krvavé Slnko,uncategorized,15,12,8,3\n");
$saImport = Import::csv($saCsv);
@unlink($saCsv);
assert_true($saImport['success'] && !$saImport['errors'], 'CSV import: threshold columns are accepted');
$saImported = $pdo->query("SELECT stock, stock_low_threshold, stock_critical_threshold FROM products WHERE sku = 'SA-BOOK'")->fetch(PDO::FETCH_ASSOC);
assert_equals([12, 8, 3], [(int)$saImported['stock'], (int)$saImported['stock_low_threshold'], (int)$saImported['stock_critical_threshold']], 'CSV import: stock and thresholds are stored');
assert_equals(12, Warehouse::totalAvailable('SA-BOOK'), 'CSV import: stock is booked in warehouse_stock');
// Admin "Export CSV" → "Import CSV" round trip: same price format, absent columns are never overwritten.
$rtRow = static fn(string $sku): array => $pdo->query("SELECT * FROM products WHERE sku = " . $pdo->quote($sku))->fetch(PDO::FETCH_ASSOC);
$rtMoves = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE sku = 'SA-BOOK'")->fetchColumn();
$pdo->prepare("UPDATE products SET price = 2398, compare_price = 2999, summary = :summary, description = :description, images = :images,
    tags = :tags, attrs = :attrs, filter_tags = 'attr_autor_x', translations = :translations, weight_g = NULL WHERE sku = 'SA-BOOK'")->execute([
    ':summary' => '-20 % "dnes", a\\b', ':description' => '<p>Popis knihy</p>', ':images' => '["at2.jpg"]', ':tags' => '["novinka","sci-fi"]',
    ':attrs' => '{"autor":"X"}', ':translations' => '{"en":{"name":"Bloody Sun"}}',
]);
$rtBefore = $rtRow('SA-BOOK');
$rtBefore2 = $rtRow('SA-BOOK2');
$rtMovesBefore = $rtMoves();
$rtFile = TEST_CLI_DIR . '/../db/stock-alert-roundtrip.csv';
$rtOut = fopen($rtFile, 'w');
exportProductsCsv($rtOut, 'SA-BOOK');
fclose($rtOut);
$rtCsv = (string)file_get_contents($rtFile);
assert_true(str_starts_with($rtCsv, "sku,name,slug,product_type,price,compare_price,stock,status,summary,weight_g,stock_low_threshold,stock_critical_threshold\n")
    && str_contains($rtCsv, ',23.98,29.99,'), 'CSV export: thresholds are exported and prices use the import format (euros)');
$rtResult = Import::csv($rtFile);
assert_true($rtResult['success'] && !$rtResult['errors'] && $rtResult['imported'] === 2, 'CSV round trip: the unchanged export imports cleanly');
assert_equals($rtBefore, $rtRow('SA-BOOK'), 'CSV round trip: unchanged export leaves the product byte-identical (price, description, images, tags, category, attrs)');
assert_equals($rtBefore2, $rtRow('SA-BOOK2'), 'CSV round trip: every exported product stays identical');
assert_equals($rtMovesBefore, $rtMoves(), 'CSV round trip: unchanged stock books nothing in the ledger');

$rtIn = fopen($rtFile, 'r');
$rtLines = [];
while (($rtLine = fgetcsv($rtIn, 0, ',', '"', '')) !== false) $rtLines[] = $rtLine;
fclose($rtIn);
foreach ($rtLines as $i => $rtLine) {
    if (($rtLine[0] ?? '') === 'SA-BOOK') { $rtLines[$i][6] = '9'; $rtLines[$i][10] = '10'; }
}
$rtOut = fopen($rtFile, 'w');
foreach ($rtLines as $rtLine) fputcsv($rtOut, $rtLine, ',', '"', '');
fclose($rtOut);
$rtResult = Import::csv($rtFile);
@unlink($rtFile);
$rtAfter = $rtRow('SA-BOOK');
$rtChanged = array_keys(array_diff_assoc(array_map('strval', $rtAfter), array_map('strval', $rtBefore)));
assert_true($rtResult['success'] && !$rtResult['errors'], 'CSV round trip: the edited export imports cleanly');
assert_true(!array_diff($rtChanged, ['stock', 'stock_low_threshold', 'updated_at']) && in_array('stock', $rtChanged, true) && in_array('stock_low_threshold', $rtChanged, true),
    'CSV round trip: editing stock and a limit changes only those columns (changed: ' . implode(', ', $rtChanged) . ')');
assert_equals([9, 10], [(int)$rtAfter['stock'], (int)$rtAfter['stock_low_threshold']], 'CSV round trip: the edited stock and limit are stored');
assert_equals(9, Warehouse::totalAvailable('SA-BOOK'), 'CSV round trip: the stock change is booked in the warehouse ledger');
assert_equals($rtMovesBefore + 1, $rtMoves(), 'CSV round trip: exactly one ledger movement for the stock change');
assert_equals($rtBefore2, $rtRow('SA-BOOK2'), 'CSV round trip: untouched rows stay identical');
assert_equals('-20 % "dnes", a\\b', Import::csvCellDecode("'-20 % \"dnes\", a\\b"), 'CSV import: the formula-guard apostrophe of the export is removed');
assert_equals("'quoted", Import::csvCellDecode("'quoted"), 'CSV import: an ordinary leading apostrophe is kept');
// Admin forms are rendered on the server from the BBF definitions, filled with the stored values.
$afHtml = AdminForm::render('admin-product', 'admin.php?p=product&action=save&id=9301', adminFormValues('admin-product', $rtRow('SA-BOOK')), '<div id="attr-section"></div>');
assert_true(str_contains($afHtml, 'action="admin.php?p=product&amp;action=save&amp;id=9301"') && str_contains($afHtml, 'enctype="multipart/form-data"')
    && str_contains($afHtml, 'id="form-admin-product"') && str_contains($afHtml, 'name="csrf_token"'), 'Admin product form: posts to the admin save handler with CSRF');
$afValue = static fn(string $html, string $name): ?string => preg_match('/<(?:input|textarea)\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*>/', $html, $m)
    ? (preg_match('/\bvalue="([^"]*)"/', $m[0], $v) ? html_entity_decode($v[1], ENT_QUOTES | ENT_HTML5) : '') : null;
assert_equals(['SA-BOOK', '2398', '9', '10', 'novinka, sci-fi'], array_map(static fn(string $n) => $afValue($afHtml, $n), ['sku', 'price', 'stock', 'stock_low_threshold', 'tags']),
    'Admin product form: opens with the stored values');
assert_true(str_contains($afHtml, '&lt;p&gt;Popis knihy&lt;/p&gt;</textarea>') && str_contains($afHtml, 'id="field-sku"') && str_contains($afHtml, 'for="field-sku"'),
    'Admin product form: textarea filled and field ids match the admin e2e selectors');
assert_true(strpos($afHtml, '<div id="attr-section"></div>') < strpos($afHtml, '<button type="submit"') && strpos($afHtml, 'name="tags"') < strpos($afHtml, '<div id="attr-section"></div>'),
    'Admin product form: the attribute section sits inside the form, after the BBF fields and before the submit button');
$afAdminSource = entrypointSource('admin.php');
assert_true(!str_contains($afAdminSource, 'bbf.js') && !str_contains($afAdminSource, 'BBF.render') && substr_count($afAdminSource, 'AdminForm::render(') === 4,
    'Admin forms: product, page, attribute and import post to admin.php instead of vendor/bbf/submit.php');
$afImport = AdminForm::render('admin-import', 'admin.php?p=import&action=upload');
assert_true(str_contains($afImport, 'action="admin.php?p=import&amp;action=upload"') && str_contains($afImport, 'enctype="multipart/form-data"')
    && (bool)preg_match('/<input type="file" name="csv_file" id="field-csv_file"[^>]*\baccept="\.csv"[^>]*\brequired\b/', $afImport), 'Admin import form: a real multipart upload to the import handler');
assert_true(str_contains((string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Operations.php'), "AdminForm::render('admin-import', 'admin.php?p=import&action=upload')"), 'Admin import page: uses the server-rendered upload form');
$afStatus = AdminForm::render('admin-product', 'admin.php', ['status' => 'retired', 'category_id' => '999999']);
assert_true(str_contains($afStatus, '<option value="retired" selected data-bbf-stored>') && str_contains($afStatus, '<option value="999999" selected data-bbf-stored>'),
    'Admin product form: a stored status or category no longer in the list stays selected, so saving does not change it');
foreach (['admin-product', 'admin-page', 'admin-attribute', 'admin-import'] as $afId) {
    assert_true(count(AdminForm::definition($afId)['fields']) > 0, "Admin forms: {$afId} definition renders");
}
assert_equals(BbfOptions::forAction('bbf_product_statuses'), BbfOptions::fromSource('/api.php?action=bbf_product_statuses'), 'Admin forms: options_from resolves to the same lists as api.php');
// Admin saves are validated by BBF (bbf_validate) against the same definitions and lists.
$afStored = adminFormValues('admin-product', $rtRow('SA-BOOK'));
$afSaved = AdminForm::validate('admin-product', $afStored, [], $afStored + ['csrf_token' => 'x', 'attr' => ['Farba' => 'modrá']], []);
$afSorted = static function (array $a): array { ksort($a); return $a; };
assert_true(is_array($afSaved) && $afSorted(array_diff_key($afSaved, ['image_upload' => 1])) === $afSorted($afStored) && $afSaved['image_upload'] === ['action' => 'keep', 'files' => []],
    'Admin product save: the untouched stored form validates to exactly the stored values, without extra posted keys');
assert_true(AdminForm::validate('admin-product', $afStored, [], ['status' => 'retired'] + $afStored, []) === null && str_contains(AdminForm::firstError(), 'Stav'),
    'Admin product save: a status outside the list is refused');
assert_true(AdminForm::validate('admin-product', ['status' => 'retired', 'category_id' => '999999'] + $afStored, [], ['status' => 'retired', 'category_id' => '999999'] + $afStored, []) !== null,
    'Admin product save: a stored status or category no longer in the list is still accepted unchanged');
assert_true(AdminForm::validate('admin-product', [], [], ['sku' => '', 'price' => 'abc', 'category_id' => '999999'] + $afStored, []) === null,
    'Admin product save: a missing SKU, a non-number price and an unknown category are refused for a new product');
$afRejected = AdminForm::render('admin-product', 'admin.php', $afStored);
$afFieldError = static fn(string $html, string $name): bool => (bool)preg_match('/id="field-' . $name . '-error" role="alert">[^<]+</', $html);
assert_true($afFieldError($afRejected, 'sku') && $afFieldError($afRejected, 'price') && $afFieldError($afRejected, 'category_id') && !$afFieldError($afRejected, 'name') && $afValue($afRejected, 'name') === $afStored['name'] && $afValue($afRejected, 'price') === $afStored['price'],
    'Admin product save: the reopened form shows the errors and keeps the valid input, invalid fields fall back to the stored value');
assert_true(!$afFieldError(AdminForm::render('admin-product', 'admin.php', $afStored), 'sku'), 'Admin product save: the errors are shown only once');
assert_true(AdminForm::validate('admin-import', [], ['allowed_extensions' => ['csv']], [], []) === null && AdminForm::firstError() !== '',
    'Admin import: a post without a CSV file is refused');
assert_true(AdminForm::validate('admin-attribute', [], [], ['name' => 'Farba', 'slug' => 'farba', 'type' => 'color', 'sort_order' => '1'], []) === null
    && AdminForm::validate('admin-attribute', [], [], ['name' => 'Farba', 'slug' => 'farba', 'type' => 'enum', 'sort_order' => '1'], []) !== null,
    'Admin attribute save: only the listed attribute types are accepted');
unset($_SESSION['admin_form_state']);
$afCatalogSource = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Catalog.php');
assert_true(str_contains($afCatalogSource, "AdminForm::validate('admin-product'") && str_contains($afCatalogSource, "AdminForm::validate('admin-page'")
    && str_contains($afCatalogSource, "AdminForm::validate('admin-attribute'")
    && str_contains((string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Operations.php'), "AdminForm::validate('admin-import'"),
    'Admin forms: product, page, attribute and import saves go through BBF validation');

// Optional daily digest: once per day after digest_hour.
StockAlerts::setConfigForTesting([
    'app_url' => 'https://shop.example',
    'merchant_notifications' => ['recipients' => ['owner@shop.example']],
    'stock_alerts' => ['low' => 5, 'critical' => 2, 'locale' => 'sk', 'digest_hour' => 7],
]);
$saSentBefore = count($saMail->sent);
assert_equals(0, StockAlerts::digest(mktime(6, 0, 0)), 'Stock digest: nothing before digest_hour');
assert_equals(1, StockAlerts::digest(mktime(8, 0, 0)), 'Stock digest: one summary after digest_hour');
assert_equals(0, StockAlerts::digest(mktime(9, 0, 0)), 'Stock digest: only once per day');
$saDigest = $saMail->sent[$saSentBefore] ?? ['subject' => '', 'html' => ''];
assert_true(str_contains($saDigest['subject'], 'Denný súhrn') && str_contains($saDigest['html'], 'SA-BOOK2'), 'Stock digest: lists everything at or below low');

// Templates, single code path, worker wiring.
foreach (['sk', 'cs', 'en', 'de', 'hu', 'pl', 'es'] as $saLocale) {
    $saRendered = MailService::render('merchant_stock_low', $saLocale, ['alert' => true, 'first_name' => 'Krvavé Slnko', 'first_available' => 2, 'more_count' => 0,
        'low_items' => [['name' => 'Krvavé Slnko', 'sku' => 'SA-BOOK', 'available' => 2, 'reserved' => 1, 'threshold' => 5, 'admin_url' => 'https://shop.example/admin.php?p=product&id=1']]]);
    assert_true(is_file(TEST_CLI_DIR . "/../templates/emails/{$saLocale}/merchant_stock_low.html") && str_contains($saRendered['subject'], 'Krvavé Slnko')
        && str_contains($saRendered['text'], 'SA-BOOK'), "Stock alerts: {$saLocale} template renders");
}
$saWorker = (string)file_get_contents(TEST_CLI_DIR . '/worker.php');
assert_true(str_contains($saWorker, 'StockAlerts::process($skus)') && str_contains($saWorker, 'StockAlerts::digest()') && !str_contains($saWorker, 'stock-alerts.log'), 'Stock alerts: worker uses the single StockAlerts path');
assert_true(str_contains((string)file_get_contents(TEST_CLI_DIR . '/../src/Inventory.php'), 'StockAlerts::touch($sku)'), 'Stock alerts: every ledger movement triggers a re-check');
assert_true(!method_exists(Products::class, 'lowStockSummary'), 'Stock alerts: legacy products.stock dashboard query is gone');

StockAlerts::flush();
StockAlerts::setConfigForTesting(null);
MailService::useAdapter(null);
$pdo->exec("DELETE FROM warehouse_stock WHERE sku IN ('SA-BOOK', 'SA-BOOK2', 'SA-EBOOK')");
$pdo->exec("DELETE FROM warehouses WHERE id = {$saWh}");
foreach ($saPreviousDefault as $saDefaultId) $pdo->exec('UPDATE warehouses SET is_default = 1 WHERE id = ' . (int)$saDefaultId);

echo "\n--- Test: URL aliases (shop-owned public URL scheme) ---\n";
UrlAliases::setRules([
    '/kniha/{slug}' => '/product/{slug}',
    '/knihy' => '/category/knihy',
    '/softver' => '/page/softver',
    '/kosik' => '/?page=cart',
    '/hladat' => '/?page=search',
    '/dakujeme' => '/?page=order-done',
]);
assert_equals('/kniha/krvave-slnko', UrlAliases::outbound('/product/krvave-slnko'), 'URL aliases: product path uses the public pattern');
assert_equals('/kniha/krvave-slnko', UrlAliases::outbound('/?page=product&slug=krvave-slnko'), 'URL aliases: ?page=product&slug is the same route');
assert_equals('/knihy?sort=price', UrlAliases::outbound('/?page=category&slug=knihy&sort=price'), 'URL aliases: category alias keeps extra query');
assert_equals('/category/e-knihy', UrlAliases::outbound('/?page=category&slug=e-knihy'), 'URL aliases: unaliased category normalizes to its path route');
assert_equals('/kosik', UrlAliases::outbound('/?page=cart'), 'URL aliases: page route alias');
assert_equals('/hladat?q=slnko', UrlAliases::outbound('/?page=search&q=slnko'), 'URL aliases: page alias keeps remaining query');
assert_equals('/dakujeme?order=ORD-1#top', UrlAliases::outbound('/?page=order-done&order=ORD-1#top'), 'URL aliases: fragment preserved');
assert_equals('/?page=checkout', UrlAliases::outbound('/?page=checkout'), 'URL aliases: unmatched route unchanged');
assert_equals('/themes/aeon/assets/a.css', UrlAliases::outbound('/themes/aeon/assets/a.css'), 'URL aliases: assets unchanged');
assert_equals(['path' => '/product/krvave-slnko', 'query' => []], UrlAliases::inbound('/kniha/krvave-slnko/'), 'URL aliases: inbound pattern resolves (trailing slash ok)');
assert_equals(['path' => '/', 'query' => ['page' => 'cart']], UrlAliases::inbound('/kosik'), 'URL aliases: inbound page alias');
assert_true(UrlAliases::inbound('/kniha/../admin') === null && UrlAliases::inbound('/kniha/a/b') === null, 'URL aliases: inbound placeholder is one safe segment');
assert_equals(['product.php', ['slug' => 'x']], UrlAliases::entry(['path' => '/product/x', 'query' => []]), 'URL aliases: product entry');
assert_equals(['index.php', ['category' => 'knihy']], UrlAliases::entry(['path' => '/category/knihy', 'query' => []]), 'URL aliases: category entry');
assert_equals(['page.php', ['slug' => 'softver']], UrlAliases::entry(['path' => '/page/softver', 'query' => []]), 'URL aliases: CMS page entry');
assert_equals(['index.php', ['page' => 'cart']], UrlAliases::entry(['path' => '/', 'query' => ['page' => 'cart']]), 'URL aliases: page entry');
assert_true(UrlAliases::entry(['path' => '/admin.php', 'query' => []]) === null, 'URL aliases: no entry for non-storefront targets');
$uaGet = $_GET;
$_GET = ['path' => '/hladat', 'q' => 'slnko'];
assert_equals('index.php', UrlAliases::dispatch('/hladat'), 'URL aliases: dispatch returns the entry script for global-scope require');
assert_equals(['page' => 'search', 'q' => 'slnko'], $_GET, 'URL aliases: dispatch merges alias query and drops the fallback path param');
assert_true(UrlAliases::dispatch('/nic-take') === null, 'URL aliases: unknown path is not dispatched');
$_GET = $uaGet;
$uaScript = $_SERVER['SCRIPT_NAME'] ?? null;
$_SERVER['SCRIPT_NAME'] = '/test/index.php';
assert_equals('/test/kniha/krvave-slnko', Template::url('/product/krvave-slnko'), 'URL aliases: Template::url applies alias under a base path');
assert_equals('/test/kosik', Template::storeUrl('/?page=cart'), 'URL aliases: Template::storeUrl applies alias');
if ($uaScript === null) unset($_SERVER['SCRIPT_NAME']); else $_SERVER['SCRIPT_NAME'] = $uaScript;
$uaThrown = false;
try { UrlAliases::setRules(['https://evil.example/x' => '/product/x']); } catch (RuntimeException $e) { $uaThrown = true; }
assert_true($uaThrown, 'URL aliases: absolute public alias is rejected');
UrlAliases::setRules([]);
assert_equals('/product/krvave-slnko', UrlAliases::outbound('/product/krvave-slnko'), 'URL aliases: no config keeps core URLs');
assert_equals('/?page=product&slug=x', UrlAliases::outbound('/?page=product&slug=x'), 'URL aliases: no config keeps query routes untouched');
UrlAliases::setRules(null);

// Real HTTP through the .htaccess fallback (redirect.php?path=…): theme forms POST cart actions to the alias.
echo "\n--- Test: URL aliases accept storefront POSTs over HTTP ---\n";
$pdo->exec("INSERT INTO products (id, category_id, name, slug, sku, product_type, price, currency, status, stock) VALUES (9401, 1, 'Alias POST', 'alias-post', 'UA-POST', 'simple', 900, 'eur', 'active', 50)");
$uaRoot = dirname(TEST_CLI_DIR);
$uaRouter = test_fixture_path('alias-router') . '.php';
$uaSessions = test_fixture_path('alias-sessions');
mkdir($uaSessions);
file_put_contents($uaRouter, '<?php
require ' . var_export($uaRoot . '/src/autoload.php', true) . ';
Db::init(new PDO(' . var_export('sqlite:' . $testDbPath, true) . ', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
UrlAliases::setRules(["/kosik" => "/?page=cart"]);
$uri = (string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($uri === "/__seed" || $uri === "/__cart") {
    session_start();
    if ($uri === "/__seed") { $_SESSION["csrf_token"] = "alias-csrf"; $_SESSION["cart"] = ["p_9401" => ["product_id" => 9401, "variant_id" => null, "qty" => 1]]; }
    echo $uri === "/__seed" ? session_id() : json_encode($_SESSION["cart"] ?? null);
    exit;
}
$_GET["path"] = $uri;
chdir(' . var_export($uaRoot, true) . ');
require ' . var_export($uaRoot . '/redirect.php', true) . ';
');
$uaProbe = stream_socket_server('tcp://127.0.0.1:0');
$uaPort = (int)substr(strrchr((string)stream_socket_get_name($uaProbe, false), ':'), 1);
fclose($uaProbe);
$uaServer = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $uaSessions, '-S', "127.0.0.1:{$uaPort}", $uaRouter],
    [0 => ['pipe', 'r'], 1 => ['file', $uaSessions . '/server.log', 'a'], 2 => ['file', $uaSessions . '/server.log', 'a']], $uaPipes, $uaRoot);
$uaHttp = static function (string $method, string $path, array $post = [], string $sid = '') use ($uaPort): array {
    $headers = "Content-Type: application/x-www-form-urlencoded\r\n" . ($sid !== '' ? "Cookie: PHPSESSID={$sid}\r\n" : '');
    $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => http_build_query($post),
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 10]]);
    $body = @file_get_contents("http://127.0.0.1:{$uaPort}{$path}", false, $context);
    $responseHeaders = $http_response_header ?? [];
    $location = '';
    foreach ($responseHeaders as $line) if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
    return ['status' => (int)(explode(' ', (string)($responseHeaders[0] ?? ''))[1] ?? 0), 'location' => $location, 'body' => (string)$body];
};
$uaSid = '';
for ($uaTry = 0; $uaTry < 50 && $uaSid === ''; $uaTry++) {
    usleep(100000);
    $uaSid = $uaHttp('GET', '/__seed')['body'];
}
assert_true($uaSid !== '', 'URL aliases HTTP: built-in server with the .htaccess fallback router is up');
$uaUpdate = $uaHttp('POST', '/kosik', ['cart_action' => 'update', 'cart_key' => 'p_9401', 'quantity' => 3, 'csrf_token' => 'alias-csrf', 'redirect' => '/kosik'], $uaSid);
assert_equals(302, $uaUpdate['status'], 'URL aliases HTTP: POST cart_action=update to the localized cart alias is served (not 405)');
assert_true(str_ends_with($uaUpdate['location'], '/kosik'), 'URL aliases HTTP: cart form POST redirects back to the cart alias');
assert_equals(3, (int)(json_decode($uaHttp('GET', '/__cart', [], $uaSid)['body'], true)['p_9401']['qty'] ?? 0), 'URL aliases HTTP: the cart quantity was changed');
assert_equals(405, $uaHttp('POST', '/nic-take', ['x' => '1'], $uaSid)['status'], 'URL aliases HTTP: POST to an unknown path is still 405');
proc_terminate($uaServer);
proc_close($uaServer);
@unlink($uaRouter);
foreach ((array)glob($uaSessions . '/*') as $uaFile) @unlink($uaFile);
@rmdir($uaSessions);
$pdo->exec("DELETE FROM products WHERE id = 9401");

// The handler redirects/exits, so exercise it in a child process with an isolated products table.
echo "\n--- Audit A01: admin bulk price changes ---\n";
$bulkFixture = test_fixture_path('bulk-prices') . '.php';
file_put_contents($bulkFixture, '<?php
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php', true) . ';
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Catalog.php', true) . ';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$driver = $argv[1];
$db = $driver === "mysql"
    ? new PDO(getenv("TEST_MYSQL_DSN"), getenv("TEST_MYSQL_USER") ?: "root", getenv("TEST_MYSQL_PASS") ?: "", [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO("sqlite::memory:");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, $driver);
$db->exec("CREATE " . ($driver === "mysql" ? "TEMPORARY " : "") . "TABLE products (id INTEGER PRIMARY KEY, store_id VARCHAR(40) NOT NULL DEFAULT \'default\', price INTEGER NOT NULL, updated_at DATETIME NULL)");
$db->exec("INSERT INTO products (id, price) VALUES (5, 1001), (7, 1010), (9, 0), (11, 99), (13, -100)");
$_SESSION = ["admin_role" => "owner"];
$_POST = ["bulk_action" => $argv[2], "product_ids" => json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR)];
register_shutdown_function(static function() use ($db): void {
    echo json_encode(["products" => $db->query("SELECT id, price, updated_at FROM products ORDER BY id")->fetchAll(), "flash" => $_SESSION["flash"] ?? null], JSON_THROW_ON_ERROR);
});
handleBulkAction();
');
try {
    $bulkDrivers = ['sqlite'];
    if (getenv('TEST_MYSQL_DSN')) $bulkDrivers[] = 'mysql';
    else echo "  [SKIP] Audit A01 live MySQL/MariaDB bulk handler (set TEST_MYSQL_DSN to enable)\n";
    foreach ($bulkDrivers as $bulkDriver) {
        foreach ([
            ['price_up_5', [5, 7, 9, 13], [1051, 1061, 0, 99, 0]],
            ['price_up_10', [5, 7, 9, 13], [1101, 1111, 0, 99, 0]],
            ['price_up_20', [5, 7, 9, 13], [1201, 1212, 0, 99, 0]],
            ['price_down_5', [5, 7, 9, 13], [951, 960, 0, 99, 0]],
            ['price_down_10', [5, 7, 9, 13], [901, 909, 0, 99, 0]],
            ['price_down_20', [5, 7, 9, 13], [801, 808, 0, 99, 0]],
            ['price_up_5', [7], [1001, 1061, 0, 99, -100]],
            ['price_up_5', [], [1001, 1010, 0, 99, -100]],
        ] as [$bulkAction, $bulkIds, $bulkExpected]) {
            $bulkLabel = "Audit A01 {$bulkDriver}/{$bulkAction}/" . implode(',', $bulkIds);
            $bulkProcess = proc_open([PHP_BINARY, $bulkFixture, $bulkDriver, $bulkAction, json_encode($bulkIds, JSON_THROW_ON_ERROR)],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $bulkPipes);
            fclose($bulkPipes[0]);
            $bulkOutput = stream_get_contents($bulkPipes[1]);
            $bulkStderr = stream_get_contents($bulkPipes[2]);
            fclose($bulkPipes[1]);
            fclose($bulkPipes[2]);
            assert_equals([0, ''], [proc_close($bulkProcess), $bulkStderr], "{$bulkLabel}: actual handler exits without PHP/SQL errors");
            $bulkResult = json_decode($bulkOutput, true, 512, JSON_THROW_ON_ERROR);
            assert_equals($bulkExpected, array_map('intval', array_column($bulkResult['products'], 'price')), "{$bulkLabel}: exact cent prices, clamp and unselected products");
            $bulkUpdated = array_map('intval', array_column(array_filter($bulkResult['products'], static fn(array $row): bool => $row['updated_at'] !== null), 'id'));
            assert_equals($bulkIds, $bulkUpdated, "{$bulkLabel}: only selected products receive updated_at");
        }
    }
} finally {
    unlink($bulkFixture);
}

require __DIR__ . '/AuditPaymentsRegression.php';
require __DIR__ . '/AuditCustomerRegression.php';
require __DIR__ . '/AuditUtcRegression.php';
require __DIR__ . '/AuditStoreAdminRegression.php';
require __DIR__ . '/AuditOrderAdminRegression.php';
require __DIR__ . '/AuditStoreConfigRegression.php';
require __DIR__ . '/AuditStorePublicCatalogRegression.php';
require __DIR__ . '/AuditStoreConfigUniqueRegression.php';
require __DIR__ . '/AuditWebhookStoreRegression.php';
require __DIR__ . '/AuditStoreAdminRemainderRegression.php';
require __DIR__ . '/AuditPaymentStateRegression.php';
require __DIR__ . '/AuditCheckoutMoneyRegression.php';
require __DIR__ . '/AuditCouponRaceRegression.php';
require __DIR__ . '/AuditOfflineInventoryRegression.php';
require __DIR__ . '/AuditOfflineExpiryNativeRegression.php';
require __DIR__ . '/AuditPromotionNoteRegression.php';
require __DIR__ . '/AuditRoleMatrixRegression.php';
require __DIR__ . '/AuditPasswordResetRegression.php';
require __DIR__ . '/AuditSessionHardeningRegression.php';
require __DIR__ . '/AuditAdminUsersRegression.php';
require __DIR__ . '/AuditEmailVerificationRegression.php';
require __DIR__ . '/AuditApiPatchRegression.php';
require __DIR__ . '/AuditQueuePoisonRegression.php';
require __DIR__ . '/AuditUpdaterToctouRegression.php';
require __DIR__ . '/AuditBackupMaintenanceRegression.php';
require __DIR__ . '/AuditSqliteMaintenanceRegression.php';
require __DIR__ . '/AuditReleasesRoutingRegression.php';
require __DIR__ . '/AuditBoundaryRedirectHostRegression.php';
require __DIR__ . '/AuditBoundaryInputRegression.php';
require __DIR__ . '/AuditBoundaryOutputRegression.php';
require __DIR__ . '/AuditInstallSmokeRegression.php';
require __DIR__ . '/AuditDocsAccuracyRegression.php';
require __DIR__ . '/AuditProductEditorRegression.php';
require __DIR__ . '/AuditTaxonomyRegression.php';
require __DIR__ . '/AuditPricingAdminRegression.php';
require __DIR__ . '/AuditB2bAdminRegression.php';
require __DIR__ . '/AuditSupplierAdminRegression.php';
require __DIR__ . '/AuditAccountOrderRegression.php';
require __DIR__ . '/AuditCheckoutCouponRegression.php';
require __DIR__ . '/AuditCheckoutAddressRegression.php';
require __DIR__ . '/AuditCheckoutPickupRegression.php';
require __DIR__ . '/AuditCheckoutCompanyRegression.php';
require __DIR__ . '/AuditCheckoutBankTransferRegression.php';
require __DIR__ . '/AuditStorefrontResponsiveRegression.php';
