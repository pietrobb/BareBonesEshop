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
$lowStockSummary = Products::lowStockSummary(10, 1000);
$lowStockSkus = array_column($lowStockSummary['products'], 'sku');
assert_true(in_array('PHYS-LOW', $lowStockSkus, true), 'Dashboard low stock: physical products remain included');
assert_true(!in_array('DIGI-GUIDE', $lowStockSkus, true), 'Dashboard low stock: downloadable products are excluded');
$pdo->exec("UPDATE products SET product_type = 'digital' WHERE id = 9001");
$lowStockSkus = array_column(Products::lowStockSummary(10, 1000)['products'], 'sku');
assert_true(!in_array('DIGI-GUIDE', $lowStockSkus, true), 'Dashboard low stock: digital alias products are excluded');
$pdo->exec("UPDATE products SET product_type = 'downloadable' WHERE id = 9001");
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
$pdo->exec("UPDATE products SET price = 999 WHERE id = 9001");
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
assert_true(str_contains($securitySrc, "form-action 'self'"), 'G6 CSP: form submissions are restricted to self');
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
assert_true(substr_count($paymentSourceG7, 'payment_id = :payment_id') >= 2 && str_contains($paymentSourceG7, 'order_number = :order_number'), 'G7 SQL: Stripe and PayU fallback identifiers use bound parameters');

$accountSourceG6 = file_get_contents(TEST_CLI_DIR . '/../account.php') ?: '';
assert_true(str_contains($accountSourceG6, 'GDPR erase failed [{$errorId}]'), 'G6 errors: erase failure details are logged with a correlation ID');
assert_true(!str_contains($accountSourceG6, "gdpr_erase_failed') . ': ' . \$ex->getMessage()"), 'G6 errors: erase exception detail is not rendered to the customer');
$importSourceG6 = file_get_contents(TEST_CLI_DIR . '/../src/Import.php') ?: '';
$pageSourceG6 = file_get_contents(TEST_CLI_DIR . '/../page.php') ?: '';
$apiSourceG6 = entrypointSource('api.php');
assert_true(substr_count($adminSourceG6, 'Security::sanitizeRichHtml') >= 2, 'G6 rich HTML: CMS and admin product writes are sanitized');
assert_true(str_contains($importSourceG6, "\$data['description'] = Security::sanitizeRichHtml"), 'G6 rich HTML: product imports sanitize descriptions');
assert_true(str_contains($pageSourceG6, 'Security::sanitizeRichHtml'), 'G6 rich HTML: historical CMS content is sanitized on render');
assert_true(str_contains($apiSourceG6, 'Security::sanitizeRichHtml'), 'G6 rich HTML: CMS API returns sanitized content');
$scriptBreakout = JsonLd::render(['name' => '</script><img src=x onerror=alert(1)>'], 'g6-nonce');
assert_equals(1, substr_count(strtolower($scriptBreakout), '</script>'), 'G6 JSON embed: attacker data cannot terminate the JSON-LD script');
assert_true(!str_contains(strtolower($scriptBreakout), '<img'), 'G6 JSON embed: attacker HTML stays hex-escaped inside JSON');
$productSourceG6 = file_get_contents(TEST_CLI_DIR . '/../product.php') ?: '';
$jsonLdSourceG6 = file_get_contents(TEST_CLI_DIR . '/../src/JsonLd.php') ?: '';
assert_true(substr_count($adminSourceG6, 'JSON_HEX_TAG') >= 4, 'G6 JSON embed: admin prefill and dynamic maps hex-escape tag delimiters');
assert_true(str_contains($productSourceG6, 'JSON_HEX_TAG'), 'G6 JSON embed: product JSON-LD hex-escapes tag delimiters');
assert_true(str_contains($jsonLdSourceG6, 'JSON_HEX_TAG'), 'G6 JSON embed: shared JSON-LD renderer hex-escapes tag delimiters');

[$g6EraseRegistered, $g6EraseCustomerId] = Customers::register('g6-erase@example.com', 'StrongPass!123', 'G6 Erase');
assert_true($g6EraseRegistered, 'G6 GDPR: audit-failure fixture customer is created');
$g6EraseResult = GdprService::anonymizeCustomer((int)$g6EraseCustomerId, 'g6-test');
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
assert_true(in_array('accessibility.css', $g10Manifest['assets']['css'], true), 'G10 theme: manifest declares versioned CSS assets');
assert_true(str_contains(Template::assets()['css'][0], '?v=2.0.0'), 'G10 theme: asset URL carries manifest version');
$g10Document = Template::document('G10 document', '<h1>Content</h1>', [
    'body_end' => '<script nonce="test-nonce">window.BBS={};</script>',
    'marketing_hooks' => '',
]);
assert_true(str_contains($g10Document, 'href="#main-content"') && str_contains($g10Document, '<main id="main-content"'), 'G10 WCAG: shared shell has a functional skip link');
assert_true(str_contains($g10Document, '<header') && str_contains($g10Document, '<footer'), 'G10 theme: shared shell owns header and footer blocks');
assert_true(str_contains($g10Document, 'nonce="test-nonce"'), 'G11 theme: shared shell renders a CSP-safe body_end bootstrap slot');
$g11CustomBody = Template::document('G11 custom body', '', [
    'body_html' => '<header id="legacy-header"></header><main id="main-content"></main>',
    'base_css' => '',
    'shop_js' => '',
    'marketing_hooks' => '',
]);
assert_true(str_contains($g11CustomBody, 'id="legacy-header"'), 'G11 theme: shared shell preserves an entrypoint-specific body without escaping it');
assert_equals(1, substr_count(strtolower($g11CustomBody), '<main'), 'G11 theme: custom body does not receive a duplicate main landmark');
foreach (['index.php', 'product.php', 'wishlist.php', 'account.php', 'page.php'] as $sharedShellEntrypoint) {
    $sharedShellSource = entrypointSource($sharedShellEntrypoint);
    assert_true(str_contains($sharedShellSource, 'Template::document('), 'G11 theme: ' . $sharedShellEntrypoint . ' uses the shared document shell');
    assert_true(!str_contains(strtolower($sharedShellSource), '<!doctype html>'), 'G11 theme: ' . $sharedShellEntrypoint . ' does not duplicate the document shell');
}

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
// G15: active health/SLO metrics and alerting adapter
// ============================================================
echo "\n--- G15: Operational metrics and alerting ---\n";
$g15StorefrontSource = entrypointSource('index.php');
foreach (['checkout-name', 'checkout-email', 'checkout-phone', 'checkout-street', 'checkout-city', 'checkout-zip', 'checkout-country', 'checkout-notes'] as $g15CheckoutControl) {
    assert_true(str_contains($g15StorefrontSource, 'for="' . $g15CheckoutControl . '"'), "G15 WCAG: checkout label targets {$g15CheckoutControl}");
    assert_true(str_contains($g15StorefrontSource, 'id="' . $g15CheckoutControl . '"'), "G15 WCAG: checkout control exposes {$g15CheckoutControl}");
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
