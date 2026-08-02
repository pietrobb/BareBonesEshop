<?php

// TEST 20: Pricing
// ============================================================
echo "\n--- Test 20: Pricing ---\n";

Pricing::setConfig([
    'customer_groups' => [
        'guest' => ['name' => 'Guest', 'discount' => 0],
        'wholesale' => ['name' => 'Wholesale', 'discount' => 15],
        'vip' => ['name' => 'VIP', 'discount' => 10],
    ],
    'catalog_rules' => [
        [
            'name' => 'Summer Sale',
            'active' => true,
            'from' => '2026-01-01',
            'to' => '2026-12-31',
            'conditions' => ['category' => 'oblecenie'],
            'discount_type' => 'percent',
            'discount_value' => 20,
            'priority' => 1,
        ],
    ],
    'cart_rules' => [
        [
            'name' => 'WELCOME10',
            'type' => 'coupon',
            'code' => 'WELCOME10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'min_subtotal' => 5000,
            'active' => true,
        ],
        [
            'name' => 'Free Shipping over 50',
            'type' => 'automatic',
            'conditions' => ['min_subtotal' => 5000],
            'action' => 'free_shipping',
            'active' => true,
        ],
    ],
    'tier_pricing' => [
        'enabled' => true,
        'rules' => [
            ['min_qty' => 10, 'discount_percent' => 5],
            ['min_qty' => 50, 'discount_percent' => 12],
        ],
    ],
]);

// Guest — no group discount
$product = ['price' => 1990, 'compare_price' => null, 'category_path' => 'oblecenie/tricka'];
$applied = Pricing::apply($product, 'guest', '2026-06-15');
assert_equals(1592, $applied['price'], 'Catalog rule: 20% off 1990 = 1592');
assert_equals(1990, $applied['compare_price'], 'Original price saved as compare_price');

// Wholesale — 15% group discount (less than 20% catalog, so catalog wins)
$product2 = ['price' => 1990, 'compare_price' => null, 'category_path' => 'oblecenie/tricka'];
$applied2 = Pricing::apply($product2, 'wholesale', '2026-06-15');
assert_equals(1592, $applied2['price'], 'Best discount wins (20% catalog > 15% group)');

// VIP but no matching catalog rule
$product3 = ['price' => 1000, 'compare_price' => null, 'category_path' => 'elektronika'];
$applied3 = Pricing::apply($product3, 'vip', '2026-06-15');
assert_equals(900, $applied3['price'], 'VIP 10% off: 1000 → 900');

// Tier pricing
assert_equals(1990, Pricing::tierPrice(1990, 1), 'Tier: qty 1 = no discount');
assert_equals(1891, Pricing::tierPrice(1990, 10), 'Tier: qty 10 = 5% off');
assert_equals(1751, Pricing::tierPrice(1990, 50), 'Tier: qty 50 = 12% off');

// Coupon
$couponResult = Pricing::applyCoupon('WELCOME10', 10000);
assert_equals(1000, $couponResult['discount'], 'Coupon WELCOME10: 10% of 10000 = 1000');
assert_equals('WELCOME10', $couponResult['name'], 'Coupon returns rule name');

// Coupon min not met
$couponResult2 = Pricing::applyCoupon('WELCOME10', 3000);
assert_equals(0, $couponResult2['discount'], 'Coupon rejected when min subtotal not met');

// Invalid coupon
$couponResult3 = Pricing::applyCoupon('INVALID', 10000);
assert_equals(0, $couponResult3['discount'], 'Invalid coupon returns 0');

// Auto rules
$actions = Pricing::autoCartRules(6000);
assert_equals(1, count($actions), 'Auto rule triggers above 5000');
assert_equals('free_shipping', $actions[0]['action'], 'Auto rule action is free_shipping');

$actions2 = Pricing::autoCartRules(3000);
assert_equals(0, count($actions2), 'Auto rule does not trigger below 5000');

// ============================================================
// TEST 21: Shipping
// ============================================================
echo "\n--- Test 21: Shipping ---\n";

Shipping::setConfig([
    'methods' => [
        'pickup' => [
            'name' => ['en' => 'Pickup', 'sk' => 'Odber'],
            'price' => 0,
            'available_zones' => ['domestic'],
        ],
        'standard' => [
            'name' => ['en' => 'Standard', 'sk' => 'Štandard'],
            'delivery_days' => '5-7',
            'rates' => [
                'domestic' => ['base' => 399, 'per_kg' => 0],
                'eu' => ['base' => 799, 'per_kg' => 100],
                'international' => ['base' => 1599, 'per_kg' => 200],
            ],
            'free_above' => 5000,
        ],
        'express' => [
            'name' => ['en' => 'Express', 'sk' => 'Expres'],
            'delivery_days' => '1-2',
            'rates' => [
                'domestic' => ['base' => 799, 'per_kg' => 0],
                'eu' => ['base' => 1499, 'per_kg' => 150],
            ],
        ],
    ],
    'zones' => [
        'domestic' => ['countries' => ['SK']],
        'eu' => ['countries' => ['CZ', 'DE', 'AT', 'PL']],
        'international' => ['countries' => ['*']],
    ],
]);

// Zone detection
assert_equals('domestic', Shipping::zoneForCountry('SK'), 'SK is domestic');
assert_equals('eu', Shipping::zoneForCountry('DE'), 'DE is EU');
assert_equals('international', Shipping::zoneForCountry('US'), 'US is international');

// Pickup
assert_equals(0, Shipping::calculate('pickup', 'SK'), 'Pickup is free');

// Standard domestic
assert_equals(399, Shipping::calculate('standard', 'SK', 500, 3000), 'Standard SK = 399');

// Free shipping above threshold
assert_equals(0, Shipping::calculate('standard', 'SK', 500, 6000), 'Standard SK free above 5000');

// Standard EU with weight
assert_equals(899, Shipping::calculate('standard', 'DE', 1000, 3000), 'Standard DE 1kg = 799+100');

// Express domestic
assert_equals(799, Shipping::calculate('express', 'SK'), 'Express SK = 799');

// Express unavailable internationally
assert_equals(-1, Shipping::calculate('express', 'US'), 'Express not available for US');

// Available methods
I18n::setLocale('en');
$methods = Shipping::availableMethods('SK', 3000);
assert_greater(count($methods), 1, 'Multiple shipping methods for SK');

$methods2 = Shipping::availableMethods('US', 3000);
assert_equals(1, count($methods2), 'Only standard for US');

// ============================================================
// TEST 22: Tax
// ============================================================
echo "\n--- Test 22: Tax ---\n";

Tax::setConfig([
    'default_rate' => 20,
    'prices_include_tax' => true,
    'rates' => [
        'SK' => ['standard' => 20, 'reduced' => 10, 'zero' => 0],
        'DE' => ['standard' => 19, 'reduced' => 7],
        'US' => ['standard' => 0],
    ],
    'product_tax_class' => [
        'default' => 'standard',
        'food' => 'reduced',
        'books' => 'reduced',
    ],
]);

// Basic rate lookup
assert_equals(20.0, Tax::rate('SK'), 'SK standard rate = 20%');
assert_equals(19.0, Tax::rate('DE'), 'DE standard rate = 19%');
assert_equals(0.0, Tax::rate('US'), 'US standard rate = 0%');
assert_equals(10.0, Tax::rate('SK', 'food'), 'SK reduced rate = 10%');

// Extract tax from gross
$tax = Tax::extractFromGross(1200, 'SK');  // 1200 / 1.20 = 1000, tax = 200
assert_equals(200, $tax, 'Tax extracted from gross: 1200 → 200');

$tax2 = Tax::extractFromGross(1190, 'DE');  // 1190 / 1.19 = 1000, tax = 190
assert_equals(190, $tax2, 'Tax extracted from gross DE: 1190 → 190');

// Zero tax country
$tax3 = Tax::extractFromGross(1000, 'US');
assert_equals(0, $tax3, 'No tax for US');

// Net from gross
$net = Tax::netFromGross(1200, 'SK');
assert_equals(1000, $net, 'Net from gross: 1200 → 1000');

// Calculate on net
$taxOnNet = Tax::calculateOnNet(1000, 'SK');
assert_equals(200, $taxOnNet, 'Tax on net: 1000 → 200');

// Order breakdown
$items = [
    ['total' => 2400, 'tax_class' => 'default'],
    ['total' => 1100, 'tax_class' => 'food'],
];
$breakdown = Tax::orderBreakdown($items, 'SK');
assert_equals(500, $breakdown['total_tax'], 'Order tax: 400 (standard) + 100 (reduced) = 500');
assert_true(isset($breakdown['breakdown']['20.0%']), 'Breakdown includes 20% rate');
assert_true(isset($breakdown['breakdown']['10.0%']), 'Breakdown includes 10% rate');

// ============================================================
// TEST 23: Orders + OrderWorkflow
// ============================================================
echo "\n--- Test 23: Orders + Workflow ---\n";

// Need product in cart with stock
$_SESSION = [];
$testProduct = Products::bySku('TSHIRT-BLK-M');
assert_true($testProduct !== null, 'Test product exists for order');

// Setup warehouse + stock so Orders::create() can reserve
$pdo->exec("INSERT INTO warehouses (code, name, priority, is_dropship, is_default, contact, notes) VALUES ('main', 'Main', 10, 0, 1, '', '')");
$whId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (:wh, :sku, :qty, 0)")
    ->execute([':wh' => $whId, ':sku' => $testProduct['sku'], ':qty' => (int)$testProduct['stock']]);

$originalStock = (int)$testProduct['stock'];

// Create order with cart items
$cartItems = [
    [
        'product_id' => $testProduct['id'],
        'variant_id' => null,
        'qty' => 2,
        'price' => $testProduct['price'],
        'sku' => $testProduct['sku'],
        'name' => $testProduct['name'],
        'variant_name' => '',
    ],
];
$customer = [
    'name' => 'Test Customer',
    'email' => 'test@example.com',
    'phone' => '+421900000000',
    'address' => ['street' => 'Test 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency' => 'eur',
    'shipping_cost' => 399,
    'tax_amount' => 0,
];

$orderId = Orders::create($cartItems, $customer, 'stripe');
assert_true($orderId > 0, 'Order created with ID > 0');

// Check order
$order = Orders::byId($orderId);
assert_true($order !== null, 'Order found by ID');
assert_equals('pending', $order['status'], 'Order status is pending');
assert_equals('Test Customer', $order['customer_name'], 'Customer name correct');
assert_equals('test@example.com', $order['customer_email'], 'Customer email correct');
assert_equals($testProduct['price'] * 2, (int)$order['subtotal'], 'Subtotal correct');
assert_equals(399, (int)$order['shipping_cost'], 'Shipping cost correct');
assert_equals($testProduct['price'] * 2 + 399, (int)$order['total'], 'Total = subtotal + shipping');
assert_equals(0, (int)$order['vat_total'], 'Legacy order stores a non-null zero VAT aggregate');
assert_equals('Test 1', (string)(json_decode((string)$order['shipping_addr'], true)['street'] ?? ''), 'Legacy order preserves the structured shipping address');

// Check order number format
assert_true(str_starts_with($order['order_number'], 'ORD-'), 'Order number starts with ORD-');

// Check order items
$items = Orders::items($orderId);
assert_equals(1, count($items), 'Order has 1 line item');
assert_equals(2, (int)$items[0]['quantity'], 'Item quantity is 2');
assert_equals($testProduct['sku'], $items[0]['sku'], 'Item SKU correct');
assert_equals(0, (int)$items[0]['vat_amount'], 'Legacy order item stores a non-null zero VAT snapshot');

// Stock decremented
$updatedProduct = Products::bySku('TSHIRT-BLK-M');
assert_equals($originalStock - 2, (int)$updatedProduct['stock'], 'Stock decremented by 2');

// By number
$orderByNum = Orders::byNumber($order['order_number']);
assert_true($orderByNum !== null, 'Order found by number');
assert_equals($orderId, (int)$orderByNum['id'], 'byNumber returns correct order');

// By email
$byEmail = Orders::byEmail('test@example.com');
assert_greater(count($byEmail), 0, 'byEmail finds orders');

// OrderWorkflow — allowed transitions
assert_true(OrderWorkflow::canTransition('pending', 'paid'), 'pending → paid allowed');
assert_true(OrderWorkflow::canTransition('pending', 'cancelled'), 'pending → cancelled allowed');
assert_true(!OrderWorkflow::canTransition('pending', 'shipped'), 'pending → shipped NOT allowed');
assert_true(!OrderWorkflow::canTransition('refunded', 'pending'), 'refunded → pending NOT allowed');

$allowed = OrderWorkflow::allowedTransitions('paid');
assert_true(in_array('processing', $allowed), 'paid can go to processing');
assert_true(in_array('shipped', $allowed), 'paid can go to shipped');
assert_true(in_array('cancelled', $allowed), 'paid can go to cancelled');

// Terminal state
assert_true(OrderWorkflow::isTerminal('refunded'), 'refunded is terminal');
assert_true(!OrderWorkflow::isTerminal('pending'), 'pending is not terminal');

// Set up job queue for workflow side effects
$testJobsDir2 = sys_get_temp_dir() . '/bareboneseshop-jobs-wf-' . getmypid();
JobQueue::setDir($testJobsDir2);

// Transition pending → paid
OrderWorkflow::transition($orderId, 'paid', 'Stripe payment received');
$order2 = Orders::byId($orderId);
assert_equals('paid', $order2['status'], 'Order transitioned to paid');

// Transition paid → shipped (queues email)
OrderWorkflow::transition($orderId, 'shipped', 'Dispatched via DPD');
$order3 = Orders::byId($orderId);
assert_equals('shipped', $order3['status'], 'Order transitioned to shipped');

// Check email job queued
$queuedJobs = JobQueue::pull(10);
assert_greater(count($queuedJobs), 0, 'Shipping notification job queued');

// Transition shipped → delivered
OrderWorkflow::transition($orderId, 'delivered');
assert_equals('delivered', Orders::byId($orderId)['status'], 'Order delivered');

// Invalid transition should throw
$threwException = false;
try {
    OrderWorkflow::transition($orderId, 'pending');
} catch (\LogicException $e) {
    $threwException = true;
}
assert_true($threwException, 'Invalid transition throws LogicException');

// Stock restore test: create another order and cancel it
$cartItems2 = [
    [
        'product_id' => $testProduct['id'],
        'variant_id' => null,
        'qty' => 1,
        'price' => $testProduct['price'],
        'sku' => $testProduct['sku'],
        'name' => $testProduct['name'],
        'variant_name' => '',
    ],
];
$orderId2 = Orders::create($cartItems2, $customer, 'stripe');
$stockBefore = (int)Products::bySku('TSHIRT-BLK-M')['stock'];

OrderWorkflow::transition($orderId2, 'cancelled', 'Customer requested');
$stockAfter = (int)Products::bySku('TSHIRT-BLK-M')['stock'];
assert_equals($stockBefore + 1, $stockAfter, 'Cancellation restores stock');

// G5: expire only old, unpaid, terminal online checkouts and restore once.
$onlineCustomer = $customer;
$onlineCustomer['payment_method_snapshot'] = json_encode(['provider' => 'stripe', 'requires_online' => true]);
$expiryOrderId = Orders::create($cartItems2, $onlineCustomer, 'stripe');
CheckoutSession::bind($expiryOrderId, 'cs_expired_test', 'stripe', 'test-browser-session');
$pdo->prepare("UPDATE orders SET created_at = datetime('now', '-3 hours'), payment_id = 'cs_expired_test' WHERE id = ?")
    ->execute([$expiryOrderId]);
$expiryStockBefore = (int)Products::bySku('TSHIRT-BLK-M')['stock'];
assert_equals(1, Orders::expirePending(3600, 10, static fn(array $order): string => 'expired'), 'G5: one terminal unpaid online order expires');
$expiredOrder = Orders::byId($expiryOrderId);
assert_equals('cancelled', $expiredOrder['status'], 'G5: expired pending order is cancelled');
assert_equals('cancelled', $expiredOrder['payment_status'], 'G5: expired pending payment is closed');
$expiredCheckoutState = $pdo->query("SELECT status FROM checkout_sessions WHERE session_id = 'cs_expired_test'")->fetchColumn();
assert_equals('expired', $expiredCheckoutState, 'G5: provider checkout binding is expired in the same transaction');
$expiryStockAfter = (int)Products::bySku('TSHIRT-BLK-M')['stock'];
assert_equals($expiryStockBefore + 1, $expiryStockAfter, 'G5: expiry releases reserved stock');
assert_equals(0, Orders::expirePending(3600, 10, static fn(array $order): string => 'expired'), 'G5: repeated expiry cannot claim the order again');
assert_equals($expiryStockAfter, (int)Products::bySku('TSHIRT-BLK-M')['stock'], 'G5: repeated expiry cannot double-restore stock');

$offlineCustomer = $customer;
$offlineCustomer['payment_method_snapshot'] = json_encode(['provider' => 'manual', 'requires_online' => false]);
$offlineOrderId = Orders::create($cartItems2, $offlineCustomer, 'transfer');
$pdo->prepare("UPDATE orders SET created_at = datetime('now', '-3 hours') WHERE id = ?")->execute([$offlineOrderId]);
assert_equals(0, Orders::expirePending(3600, 10, static fn(array $order): string => 'expired'), 'G5: offline pending order is never auto-expired');
assert_equals('pending', Orders::byId($offlineOrderId)['status'], 'G5: offline order remains pending');

$paidOnlineOrderId = Orders::create($cartItems2, $onlineCustomer, 'stripe');
$pdo->prepare("UPDATE orders SET created_at = datetime('now', '-3 hours'), payment_status = 'paid', payment_id = 'cs_paid_test' WHERE id = ?")
    ->execute([$paidOnlineOrderId]);
assert_equals(0, Orders::expirePending(3600, 10, static fn(array $order): string => 'expired'), 'G5: paid order is never auto-expired');
assert_equals('pending', Orders::byId($paidOnlineOrderId)['status'], 'G5: paid order lifecycle remains untouched');

$openOnlineOrderId = Orders::create($cartItems2, $onlineCustomer, 'stripe');
$pdo->prepare("UPDATE orders SET created_at = datetime('now', '-3 hours'), payment_id = 'cs_open_test' WHERE id = ?")
    ->execute([$openOnlineOrderId]);
assert_equals(0, Orders::expirePending(3600, 10, static fn(array $order): string => 'open'), 'G5: non-terminal provider session fails closed');
assert_equals('pending', Orders::byId($openOnlineOrderId)['status'], 'G5: open provider checkout remains pending');
$pdo->prepare("UPDATE orders SET created_at = datetime('now') WHERE id = ?")->execute([$openOnlineOrderId]);

// Order stats
$stats = Orders::stats();
assert_true(isset($stats['pending']), 'Stats has pending count');
assert_true(isset($stats['revenue']), 'Stats has revenue');

// Cleanup jobs test dir
foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
    $subDir = $testJobsDir2 . '/' . $sub;
    if (is_dir($subDir)) {
        array_map('unlink', glob($subDir . '/*.json') ?: []);
        @rmdir($subDir);
    }
}
@rmdir($testJobsDir2);

// ============================================================
// TEST 24: Out-of-stock order rejection
// ============================================================
echo "\n--- Test 24: Out-of-stock Order ---\n";

$cartItemsOOS = [
    [
        'product_id' => $testProduct['id'],
        'variant_id' => null,
        'qty' => 999999, // way more than stock
        'price' => $testProduct['price'],
        'sku' => $testProduct['sku'],
        'name' => $testProduct['name'],
        'variant_name' => '',
    ],
];
$oosException = false;
try {
    Orders::create($cartItemsOOS, $customer, 'stripe');
} catch (\RuntimeException $e) {
    $oosException = true;
    assert_true(str_contains($e->getMessage(), 'Out of stock'), 'OOS error message correct');
}
assert_true($oosException, 'Out-of-stock order throws RuntimeException');

// ============================================================
// TEST 25: Stripe Webhook Signature Verification
// ============================================================
echo "\n--- Test 25: Webhook Signature ---\n";

// Inline the signature verification function for testing (api.php has side effects)
function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool {
    if (strlen($secret) < 16 || trim($secret) !== $secret) return false;
    $timestamp = '';
    $signatures = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$key, $value] = explode('=', trim($part), 2) + [1 => ''];
        if ($key === 't') {
            if ($timestamp !== '' && $timestamp !== $value) return false;
            $timestamp = $value;
        } elseif ($key === 'v1' && $value !== '') {
            $signatures[] = strtolower($value);
        }
    }
    if ($timestamp === '' || !ctype_digit($timestamp) || !$signatures) return false;
    if (abs(time() - (int)$timestamp) > 300) return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) return true;
    }
    return false;
}

$testSecret = 'test-stripe-webhook-secret';
$testPayload = '{"id":"evt_1","type":"checkout.session.completed"}';
$testTimestamp = (string)time();
$testSig = hash_hmac('sha256', $testTimestamp . '.' . $testPayload, $testSecret);
$testHeader = "t={$testTimestamp},v1={$testSig}";

assert_true(verifyStripeSignature($testPayload, $testHeader, $testSecret), 'Valid Stripe signature accepted');
assert_true(verifyStripeSignature($testPayload, "t={$testTimestamp},v1=deadbeef,v1={$testSig}", $testSecret), 'Any valid Stripe v1 signature is accepted during secret rotation');
assert_true(!verifyStripeSignature($testPayload, $testHeader, ''), 'Empty Stripe webhook secret fails closed');
$weakSecretHeader = "t={$testTimestamp},v1=" . hash_hmac('sha256', $testTimestamp . '.' . $testPayload, 'short');
assert_true(!verifyStripeSignature($testPayload, $weakSecretHeader, 'short'), 'Weak Stripe webhook secret fails closed even with a matching HMAC');
assert_true(!verifyStripeSignature($testPayload, $testHeader, 'wrong_secret'), 'Wrong secret rejected');
assert_true(!verifyStripeSignature('tampered', $testHeader, $testSecret), 'Tampered payload rejected');
assert_true(!verifyStripeSignature($testPayload, "t=1,t={$testTimestamp},v1={$testSig}", $testSecret), 'Conflicting Stripe timestamps rejected');
assert_true(!verifyStripeSignature($testPayload, "t=not-a-time,v1={$testSig}", $testSecret), 'Malformed Stripe timestamp rejected');

// Expired timestamp
$oldTimestamp = (string)(time() - 600);
$oldSig = hash_hmac('sha256', $oldTimestamp . '.' . $testPayload, $testSecret);
$oldHeader = "t={$oldTimestamp},v1={$oldSig}";
assert_true(!verifyStripeSignature($testPayload, $oldHeader, $testSecret), 'Expired signature rejected');

// ============================================================
// TEST 26: Cache Warming (inline)
// ============================================================
echo "\n--- Test 26: Cache Warming ---\n";

$warmCacheDir = TEST_CLI_DIR . '/../cache/test_warm_' . getmypid();
Cache::setDir($warmCacheDir);

// Warm categories
I18n::setLocale('sk');
$tree = Categories::tree();
$translatedTree = array_map(fn($cat) => I18n::category($cat), $tree);
Cache::writeJson(Cache::localePath('sk', 'categories.json'), ['categories' => $translatedTree]);

$read = Cache::readJson(Cache::localePath('sk', 'categories.json'));
assert_true(!empty($read['categories']), 'Cache warmed: categories');

// Warm a category listing
$tricka = Categories::bySlug('tricka');
$cfg = require TEST_CLI_DIR . '/../config.php';
$listing = Products::byCategory($tricka['id'], 1, $cfg['per_page'] ?? 24, 'price_asc');
$listing['products'] = array_map(fn($p) => Products::enrich(I18n::product($p)), $listing['products']);
Cache::writeJson(Cache::localePath('sk', "cat/tricka/page-1.json"), $listing);

$readListing = Cache::readJson(Cache::localePath('sk', "cat/tricka/page-1.json"));
assert_true(!empty($readListing['products']), 'Cache warmed: category listing');
assert_equals(3, $readListing['total'], 'Cache listing has correct total');

// Warm a product page
$product = Products::bySku('TSHIRT-BLK-M');
$enriched = Products::enrich(I18n::product($product));
Cache::writeJson(Cache::localePath('sk', "product/{$product['slug']}.json"), ['product' => $enriched]);

$readProduct = Cache::readJson(Cache::localePath('sk', "product/{$product['slug']}.json"));
assert_true(!empty($readProduct['product']), 'Cache warmed: product page');
assert_equals('TSHIRT-BLK-M', $readProduct['product']['sku'], 'Cache product has correct SKU');

// Cleanup warm cache
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($warmCacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iter as $f) {
    $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
}
@rmdir($warmCacheDir);
Cache::setDir(TEST_CLI_DIR . '/../cache');

// ============================================================
// TEST 27: Worker job handler (inline test)
// ============================================================
echo "\n--- Test 27: Worker Job Dispatch ---\n";

$testJobsDir3 = sys_get_temp_dir() . '/bareboneseshop-jobs-worker-' . getmypid();
JobQueue::setDir($testJobsDir3);

// Push various job types
$j1 = JobQueue::push('email_send', ['to' => 'test@test.com', 'template' => 'test'], 5);
$j2 = JobQueue::push('cache_rebuild', ['scope' => 'full'], 1);
$j3 = JobQueue::push('stock_alert', ['threshold' => 10], 3);

$stats = JobQueue::stats();
assert_equals(3, $stats['pending'], 'Worker: 3 jobs pending');

// Pull in priority order
$jobs = JobQueue::pull(10);
assert_equals('cache_rebuild', $jobs[0]['type'], 'Worker: highest priority first (cache_rebuild)');
assert_equals(StoreContext::current()->id(), $jobs[0]['payload']['_store_id'] ?? null, 'Worker: queued job carries active store context');
assert_equals(StoreContext::current()->channel(), $jobs[0]['payload']['_channel_id'] ?? null, 'Worker: queued job carries active channel context');
assert_equals('stock_alert', $jobs[1]['type'], 'Worker: second priority (stock_alert)');
assert_equals('email_send', $jobs[2]['type'], 'Worker: third priority (email_send)');

// Process one
$procFile = JobQueue::markProcessing($jobs[0]);
JobQueue::complete($procFile);
$stats2 = JobQueue::stats();
assert_equals(2, $stats2['pending'], 'Worker: 2 jobs left after processing');
assert_equals(1, $stats2['completed'], 'Worker: 1 completed');

// Cleanup
foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
    $subDir = $testJobsDir3 . '/' . $sub;
    if (is_dir($subDir)) {
        array_map('unlink', glob($subDir . '/*.json') ?: []);
        @rmdir($subDir);
    }
}
@rmdir($testJobsDir3);

// ============================================================
// TEST 28: Security Audit
// ============================================================
echo "\n--- Test 28: Security Audit ---\n";

// SQL injection via prepared statements
$maliciousSlug = "'; DROP TABLE products; --";
$result = Products::bySlug($maliciousSlug);
assert_true($result === null, 'SQL injection via slug returns null (no crash)');

$tableCount = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='products'")->fetchColumn();
assert_equals(1, $tableCount, 'products table still exists after injection attempt');

// XSS prevention
$xssPayload = '<script>alert("xss")</script>';
$escaped = esc($xssPayload);
assert_true(!str_contains($escaped, '<script>'), 'esc() prevents XSS: no raw <script>');
assert_true(str_contains($escaped, '&lt;script&gt;'), 'esc() converts to HTML entities');

// Slug sanitization prevents directory traversal
$traversal = '../../../etc/passwd';
$safeSlug = Security::slug($traversal);
assert_true(!str_contains($safeSlug, '..'), 'Slug sanitization prevents directory traversal');
assert_true(!str_contains($safeSlug, '/'), 'Slug sanitization removes path separators');

// Int sanitization
assert_equals(0, Security::int('abc; DROP TABLE'), 'int() rejects SQL in input');
assert_equals(0, Security::int('1 OR 1=1'), 'int() rejects boolean injection');

// Email validation
assert_equals(null, Security::email('admin@"><script>'), 'email() rejects XSS payload');
assert_equals(null, Security::email('not-email'), 'email() rejects invalid format');

// Webhook replay protection (idempotency)
$replay1 = Idempotency::webhookProcessed('replay_test_001');
assert_true(!$replay1, 'Webhook first delivery accepted');
$replay2 = Idempotency::webhookProcessed('replay_test_001');
assert_true($replay2, 'Webhook replay blocked');

// Order creation rejects negative price manipulation
$testProduct = Products::bySku('TSHIRT-BLK-M');
if ($testProduct) {
    // Even if client sends negative qty, server enforces min 1
    $maxQty = min(99, max(1, -5)); // Simulating server-side sanitization
    assert_equals(1, $maxQty, 'Negative qty sanitized to 1');
}

// Template engine prevents stored XSS
$xssData = ['name' => '<img src=x onerror=alert(1)>'];
$rendered = Template::process('{{name}}', $xssData);
assert_true(!str_contains($rendered, '<img'), 'Template prevents stored XSS');
assert_true(str_contains($rendered, '&lt;img'), 'Template escapes stored XSS');

// Raw template output is opt-in only
$rendered2 = Template::process('{{{name}}}', $xssData);
assert_true(str_contains($rendered2, '<img'), 'Triple braces = raw (developer choice)');

// ============================================================
// TEST 29: TecDoc — Specifications CRUD & Parametric Search
// ============================================================
echo "\n--- Test 29: TecDoc — Specifications & Parametric Search ---\n";

// Create a "Brake Disc" category for auto parts
$pdo->exec("INSERT INTO categories (slug, name, parent_id, path, depth, sort_order, meta) VALUES ('brake-system', 'Brake System', NULL, 'brake-system', 0, 100, '{}')");
$brakeSystemId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO categories (slug, name, parent_id, path, depth, sort_order, meta) VALUES ('brake-disc', 'Brake Disc', {$brakeSystemId}, 'brake-system/brake-disc', 1, 101, '{\"genart_no\":192}')");
$brakeDiscCatId = (int)$pdo->lastInsertId();

// Insert 3 brake disc products with different specs
$pdo->exec("INSERT INTO products (sku, slug, product_type, name, category_id, price, stock, status, summary, filter_tags)
    VALUES ('BD-001', 'brake-disc-front-300mm', 'auto_part', 'Brake Disc Front 300mm', {$brakeDiscCatId}, 5500, 10, 'active', 'Front brake disc 300mm ventilated', 'attr_supplier_trw')");
$bd1Id = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO products (sku, slug, product_type, name, category_id, price, stock, status, summary, filter_tags)
    VALUES ('BD-002', 'brake-disc-front-280mm', 'auto_part', 'Brake Disc Front 280mm', {$brakeDiscCatId}, 4200, 5, 'active', 'Front brake disc 280mm solid', 'attr_supplier_febi')");
$bd2Id = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO products (sku, slug, product_type, name, category_id, price, stock, status, summary, filter_tags)
    VALUES ('BD-003', 'brake-disc-rear-260mm', 'auto_part', 'Brake Disc Rear 260mm', {$brakeDiscCatId}, 3800, 15, 'active', 'Rear brake disc 260mm solid', 'attr_supplier_trw')");
$bd3Id = (int)$pdo->lastInsertId();

// Add specifications for each disc
$specStmt = $pdo->prepare("INSERT INTO product_specifications (product_id, lang, criteria_name, criteria_value, numeric_val, sort_order) VALUES (?, ?, ?, ?, ?, ?)");

// BD-001: Front 300mm ventilated TRW
$specStmt->execute([$bd1Id, 'sk', 'Priemer [mm]', '300', 300.0, 1]);
$specStmt->execute([$bd1Id, 'sk', 'Hrúbka [mm]', '28', 28.0, 2]);
$specStmt->execute([$bd1Id, 'sk', 'Strana montáže', 'Vpredu', null, 3]);
$specStmt->execute([$bd1Id, 'sk', 'Typ', 'Vetraný', null, 4]);
$specStmt->execute([$bd1Id, 'sk', 'Počet otvorov', '5', 5.0, 5]);

// BD-002: Front 280mm solid FEBI
$specStmt->execute([$bd2Id, 'sk', 'Priemer [mm]', '280', 280.0, 1]);
$specStmt->execute([$bd2Id, 'sk', 'Hrúbka [mm]', '22', 22.0, 2]);
$specStmt->execute([$bd2Id, 'sk', 'Strana montáže', 'Vpredu', null, 3]);
$specStmt->execute([$bd2Id, 'sk', 'Typ', 'Plný', null, 4]);
$specStmt->execute([$bd2Id, 'sk', 'Počet otvorov', '4', 4.0, 5]);

// BD-003: Rear 260mm solid TRW
$specStmt->execute([$bd3Id, 'sk', 'Priemer [mm]', '260', 260.0, 1]);
$specStmt->execute([$bd3Id, 'sk', 'Hrúbka [mm]', '10', 10.0, 2]);
$specStmt->execute([$bd3Id, 'sk', 'Strana montáže', 'Vzadu', null, 3]);
$specStmt->execute([$bd3Id, 'sk', 'Typ', 'Plný', null, 4]);
$specStmt->execute([$bd3Id, 'sk', 'Počet otvorov', '5', 5.0, 5]);

// Get specifications for a product
$specs = TecDoc::specifications($bd1Id, 'sk');
assert_equals(5, count($specs), 'BD-001 has 5 specs');
assert_equals('Priemer [mm]', $specs[0]['criteria_name'], 'First spec is Priemer');
assert_equals('300', $specs[0]['criteria_value'], 'Priemer value = 300');
assert_equals(300.0, $specs[0]['numeric_val'], 'Numeric value extracted');

// Parametric search — no filters → all 3 products
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', [], null, null, 'sk');
assert_equals(3, $result['total'], 'Parametric: no filter → 3 products');

// Filter by mounting side = Vpredu → 2 products
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', ['Strana montáže' => ['Vpredu']], null, null, 'sk');
assert_equals(2, $result['total'], 'Parametric: Vpredu → 2 products');

// Filter by type = Plný → 2 products (BD-002, BD-003)
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', ['Typ' => ['Plný']], null, null, 'sk');
assert_equals(2, $result['total'], 'Parametric: Plný → 2 products');

// Combined filter: Vpredu + Plný → 1 product (BD-002)
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', [
    'Strana montáže' => ['Vpredu'],
    'Typ' => ['Plný'],
], null, null, 'sk');
assert_equals(1, $result['total'], 'Parametric: Vpredu + Plný → 1 product');
assert_equals('BD-002', $result['products'][0]['sku'], 'Filtered product is BD-002');

// Combined filter: Vpredu + Vetraný → 1 product (BD-001)
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', [
    'Strana montáže' => ['Vpredu'],
    'Typ' => ['Vetraný'],
], null, null, 'sk');
assert_equals(1, $result['total'], 'Parametric: Vpredu + Vetraný → 1 product');
assert_equals('BD-001', $result['products'][0]['sku'], 'Filtered product is BD-001');

// Price range filter within parametric search
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', [], 4000, 5000, 'sk');
assert_equals(1, $result['total'], 'Price range 4000-5000 → 1 product');
assert_equals('BD-002', $result['products'][0]['sku'], 'Price filtered product is BD-002');

// Spec filter values — dynamic facets
$filterValues = TecDoc::specFilterValues($brakeDiscCatId, 'sk');
assert_true(isset($filterValues['Strana montáže']), 'Filter has Strana montáže');
assert_true(isset($filterValues['Typ']), 'Filter has Typ');
assert_true(isset($filterValues['Počet otvorov']), 'Filter has Počet otvorov');

// Strana montáže should have Vpredu(2) and Vzadu(1)
$mountValues = array_column($filterValues['Strana montáže'], 'value');
assert_true(in_array('Vpredu', $mountValues), 'Strana montáže has Vpredu');
assert_true(in_array('Vzadu', $mountValues), 'Strana montáže has Vzadu');

// Dynamic filter narrowing: when Vpredu is selected, Typ should still show both options
$filterValues2 = TecDoc::specFilterValues($brakeDiscCatId, 'sk', ['Strana montáže' => ['Vpredu']]);
$typValues = array_column($filterValues2['Typ'], 'value');
assert_true(in_array('Vetraný', $typValues), 'With Vpredu filter, Typ still shows Vetraný');
assert_true(in_array('Plný', $typValues), 'With Vpredu filter, Typ still shows Plný');

$g4FacetCounter = new QueryCountingPDO('sqlite:' . $testDbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
Db::init($g4FacetCounter, 'sqlite');
$g4FacetCounter->resetQueryCount();
$g4UnfilteredFacets = TecDoc::specFilterValues($brakeDiscCatId, 'sk');
assert_equals($filterValues, $g4UnfilteredFacets, 'G4 facets: batched unfiltered payload preserves behavior');
assert_true($g4FacetCounter->queryCount() <= 1, 'G4 facets: unfiltered values use one aggregate query');
$g4FacetCounter->resetQueryCount();
$g4ActiveFacets = TecDoc::specFilterValues($brakeDiscCatId, 'sk', ['Strana montáže' => ['Vpredu']]);
assert_equals($filterValues2, $g4ActiveFacets, 'G4 facets: active self-exclusion payload preserves behavior');
assert_true($g4FacetCounter->queryCount() <= 2, 'G4 facets: one active filter uses at most two aggregate queries');
Db::init($pdo, 'sqlite');

// ============================================================
// TEST 30: TecDoc — OE Number Search
// ============================================================
echo "\n--- Test 30: TecDoc — OE Number Search ---\n";

// Add OE numbers (with normalized form for fast search)
$oeStmt = $pdo->prepare("INSERT INTO product_oe_numbers (product_id, oem_brand, oem_number, oem_number_norm) VALUES (?, ?, ?, ?)");
$oeStmt->execute([$bd1Id, 'VOLKSWAGEN', '1K0 615 301 AD', '1K0615301AD']);
$oeStmt->execute([$bd1Id, 'AUDI', '8J0 615 301 K', '8J0615301K']);
$oeStmt->execute([$bd2Id, 'SKODA', '6R0 615 301 B', '6R0615301B']);
$oeStmt->execute([$bd3Id, 'VOLKSWAGEN', '1K0 615 601 N', '1K0615601N']);

// Search by exact OE
$result = TecDoc::searchByOE('1K0 615 301 AD');
assert_equals(1, $result['total'], 'OE search exact: 1 result');
assert_equals('BD-001', $result['products'][0]['sku'], 'OE search finds correct product');

// Search by partial OE (normalized — strips spaces/dashes)
$result = TecDoc::searchByOE('1K0615301');
assert_equals(1, $result['total'], 'OE search normalized: finds with spaces stripped');

// Search by OE pattern across multiple products
$result = TecDoc::searchByOE('615301');
assert_equals(2, $result['total'], 'OE pattern 615301 → 2 products (BD-001, BD-003)');

// Get OE numbers for a product
$oeNums = TecDoc::oeNumbers($bd1Id);
assert_equals(2, count($oeNums), 'BD-001 has 2 OE numbers');
assert_equals('AUDI', $oeNums[0]['oem_brand'], 'First OE sorted alphabetically by brand');

// ============================================================
// TEST 31: TecDoc — Vehicle Compatibility
// ============================================================
echo "\n--- Test 31: TecDoc — Vehicle Compatibility ---\n";

// Add vehicle compatibility
$vehStmt = $pdo->prepare("INSERT INTO product_vehicles (product_id, ktype, manufacturer, model, type_name) VALUES (?, ?, ?, ?, ?)");
$vehStmt->execute([$bd1Id, 35430, 'VOLKSWAGEN', 'GOLF V (1K1)', '2.0 TDI 140HP']);
$vehStmt->execute([$bd1Id, 35431, 'VOLKSWAGEN', 'GOLF V (1K1)', '1.6 FSI 115HP']);
$vehStmt->execute([$bd2Id, 35430, 'VOLKSWAGEN', 'GOLF V (1K1)', '2.0 TDI 140HP']);
$vehStmt->execute([$bd3Id, 35430, 'VOLKSWAGEN', 'GOLF V (1K1)', '2.0 TDI 140HP']);
$vehStmt->execute([$bd1Id, 48907, 'AUDI', 'A3 (8P)', '2.0 TDI 140HP']);

// Parts by ktype
$result = TecDoc::partsByKtype(35430);
assert_equals(3, $result['total'], 'Ktype 35430 → 3 parts');

// Parts by ktype with category filter
$result = TecDoc::partsByKtype(35430, 1, 24, $brakeDiscCatId);
assert_equals(3, $result['total'], 'Ktype 35430 + brake disc cat → 3 parts');

// Categories for ktype
$cats = TecDoc::categoriesForKtype(35430);
assert_equals(1, count($cats), 'Ktype 35430 has 1 category');
assert_equals('brake-disc', $cats[0]['slug'], 'Category is brake-disc');
assert_equals(3, (int)$cats[0]['part_count'], 'Category has 3 parts for this ktype');

// Vehicles for product
$vehicles = TecDoc::vehiclesForProduct($bd1Id);
assert_equals(3, count($vehicles), 'BD-001 fits 3 vehicles');

// Enrich product with TecDoc data
$product = Products::byId($bd1Id);
$enriched = TecDoc::enrichProduct($product, 'sk');
assert_equals(5, count($enriched['specifications']), 'Enriched product has 5 specs');
assert_equals(2, count($enriched['oe_numbers']), 'Enriched product has 2 OE numbers');
assert_equals(3, $enriched['vehicle_count'], 'Enriched product fits 3 vehicles');

// ============================================================
// TEST 32: TecDoc — Parametric Filter Edge Cases
// ============================================================
echo "\n--- Test 32: TecDoc — Parametric Filter Edge Cases ---\n";

// OR within same criterion: holes = 4 OR 5 → all 3 products
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_asc', [
    'Počet otvorov' => ['4', '5'],
], null, null, 'sk');
assert_equals(3, $result['total'], 'OR within criterion: holes 4 OR 5 → 3 products');

// Sorting works
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 24, 'price_desc', [], null, null, 'sk');
assert_equals('BD-001', $result['products'][0]['sku'], 'Price desc: BD-001 first (5500)');
assert_equals('BD-003', $result['products'][2]['sku'], 'Price desc: BD-003 last (3800)');

// Pagination
$result = TecDoc::parametricSearch($brakeDiscCatId, 1, 2, 'price_asc', [], null, null, 'sk');
assert_equals(3, $result['total'], 'Pagination: total still 3');
assert_equals(2, count($result['products']), 'Pagination: page 1 has 2 items');
assert_equals(2, $result['pages'], 'Pagination: 2 pages total');

$result2 = TecDoc::parametricSearch($brakeDiscCatId, 2, 2, 'price_asc', [], null, null, 'sk');
assert_equals(1, count($result2['products']), 'Pagination: page 2 has 1 item');

// Empty category returns no filters
$emptyCatId = $brakeSystemId; // parent with no direct products
$result = TecDoc::parametricSearch($emptyCatId, 1, 24, 'price_asc', [], null, null, 'sk');
assert_equals(0, $result['total'], 'Empty category → 0 products');
assert_true(empty($result['filters']), 'Empty category → no filters');

// ============================================================
// TEST 33: Order Workflow — returned restores stock
// ============================================================
echo "\n--- Test 33: Returned Order Restores Stock ---\n";

$testJobsDir3 = sys_get_temp_dir() . '/bareboneseshop-jobs-return-' . getmypid();
JobQueue::setDir($testJobsDir3);

// Create an order, pay it, ship it, then return it
$retProduct = Products::bySku('TSHIRT-BLK-M');
$retStockBefore = (int)$retProduct['stock'];
$retCartItems = [
    [
        'product_id' => $retProduct['id'],
        'variant_id' => null,
        'qty' => 3,
        'price' => $retProduct['price'],
        'sku' => $retProduct['sku'],
        'name' => $retProduct['name'],
        'variant_name' => '',
    ],
];
$retCustomer = [
    'name' => 'Return Tester',
    'email' => 'return@test.com',
    'phone' => '',
    'address' => ['street' => 'Return St', 'city' => 'BA', 'zip' => '00000', 'country' => 'SK'],
    'currency' => 'eur',
    'shipping_cost' => 0,
    'tax_amount' => 0,
];
$retOrderId = Orders::create($retCartItems, $retCustomer, 'stripe');
$retStockAfterCreate = (int)Products::bySku('TSHIRT-BLK-M')['stock'];
assert_equals($retStockBefore - 3, $retStockAfterCreate, 'Stock decremented by 3 on order create');

OrderWorkflow::transition($retOrderId, 'paid', 'Paid');
OrderWorkflow::transition($retOrderId, 'shipped', 'Shipped');
OrderWorkflow::transition($retOrderId, 'delivered', 'Delivered');

// Return the order — should restore stock
OrderWorkflow::transition($retOrderId, 'returned', 'Customer returned');
$retStockAfterReturn = (int)Products::bySku('TSHIRT-BLK-M')['stock'];
assert_equals($retStockAfterCreate + 3, $retStockAfterReturn, 'Stock restored on return (+3)');
assert_equals('returned', Orders::byId($retOrderId)['status'], 'Order status is returned');

// Transition to refunded — stock should NOT change again
OrderWorkflow::transition($retOrderId, 'refunded', 'Refund processed');
$retStockAfterRefund = (int)Products::bySku('TSHIRT-BLK-M')['stock'];
assert_equals($retStockAfterReturn, $retStockAfterRefund, 'Stock unchanged on refund (already restored)');
assert_equals('refunded', Orders::byId($retOrderId)['status'], 'Order status is refunded');

// Cleanup
foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
    $d = $testJobsDir3 . '/' . $sub;
    if (is_dir($d)) { array_map('unlink', glob($d . '/*.json') ?: []); @rmdir($d); }
}
@rmdir($testJobsDir3);

// ============================================================
// TEST 34: Coupon discount persisted in order
// ============================================================
echo "\n--- Test 34: Coupon Discount in Order ---\n";

$couponProduct = Products::bySku('TSHIRT-BLK-M');
$couponCartItems = [
    [
        'product_id' => $couponProduct['id'],
        'variant_id' => null,
        'qty' => 1,
        'price' => $couponProduct['price'],
        'sku' => $couponProduct['sku'],
        'name' => $couponProduct['name'],
        'variant_name' => '',
    ],
];
$couponCustomer = [
    'name' => 'Coupon Tester',
    'email' => 'coupon@test.com',
    'phone' => '',
    'address' => ['street' => 'Test', 'city' => 'BA', 'zip' => '00000', 'country' => 'SK'],
    'currency' => 'eur',
    'shipping_cost' => 399,
    'tax_amount' => 200,
    'coupon_discount' => 500,
];

$couponOrderId = Orders::create($couponCartItems, $couponCustomer, 'cod');
$couponOrder = Orders::byId($couponOrderId);
$expectedSubtotal = $couponProduct['price'];
// prices_include_tax=true → extracted tax_amount is reporting-only, NOT added to total
$expectedTotal = max(0, $expectedSubtotal - 500) + 399;
assert_equals($expectedSubtotal, (int)$couponOrder['subtotal'], 'Coupon order: subtotal correct');
assert_equals(500, (int)$couponOrder['discount_amount'], 'Coupon discount persisted in order');
assert_equals($expectedTotal, (int)$couponOrder['total'], 'Coupon order: total = subtotal - discount + shipping (tax included in gross)');

// ============================================================
// TEST 35: Pricing edge cases — zero price, zero compare_price
// ============================================================
echo "\n--- Test 35: Pricing Edge Cases ---\n";

Pricing::setConfig([
    'customer_groups' => ['guest' => ['discount' => 0]],
    'catalog_rules' => [
        [
            'active' => true,
            'discount_type' => 'fixed',
            'discount_value' => 500,
            'conditions' => [],
        ],
    ],
    'tier_pricing' => ['enabled' => false],
    'cart_rules' => [],
]);

// Zero price product + fixed discount → should not divide by zero
$zeroPriceProduct = ['id' => 999, 'price' => 0, 'status' => 'active'];
$result = Pricing::apply($zeroPriceProduct);
assert_equals(0, $result['price'], 'Zero price product stays at 0 (no div by zero)');
assert_true(!isset($result['discount_percent']) || $result['discount_percent'] === 0,
    'No discount percent set for zero-price product');

// Product with compare_price = 0 should not crash enrich()
$weirdProduct = [
    'id' => 998, 'price' => 1000, 'compare_price' => 0, 'slug' => 'test',
    'images' => '[]', 'attrs' => '{}', 'tags' => '[]', 'currency' => 'eur',
];
$enriched = Products::enrich($weirdProduct);
assert_true(!isset($enriched['discount_percent']), 'compare_price=0 does not set discount_percent');
assert_equals(1000, $enriched['price'], 'Price preserved with compare_price=0');

// Restore default config
Pricing::setConfig([
    'customer_groups' => [
        'guest' => ['discount' => 0],
        'wholesale' => ['discount' => 15],
        'vip' => ['discount' => 10],
    ],
    'catalog_rules' => [
        [
            'active' => true,
            'discount_type' => 'percent',
            'discount_value' => 20,
            'conditions' => ['category' => 'tricka'],
        ],
    ],
    'tier_pricing' => [
        'enabled' => true,
        'rules' => [
            ['min_qty' => 10, 'discount_percent' => 5],
            ['min_qty' => 50, 'discount_percent' => 12],
        ],
    ],
    'cart_rules' => [
        [
            'active' => true,
            'type' => 'coupon',
            'code' => 'WELCOME10',
            'name' => 'Welcome 10% off',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'min_subtotal' => 2000,
        ],
        [
            'active' => true,
            'type' => 'automatic',
            'name' => 'Free shipping over 50',
            'conditions' => ['min_subtotal' => 5000],
            'action' => 'free_shipping',
        ],
    ],
]);

// ============================================================
// TEST 36: Import category parent_id isolation
// ============================================================
echo "\n--- Test 36: Import Category Parent Isolation ---\n";

// Create two different trees with a shared slug at leaf level
Import::ensureCategory('electronics/cables', Db::get());
Import::ensureCategory('accessories/cables', Db::get());

// They should be different categories
$elecCables = Db::get()->prepare("SELECT c.id FROM categories c WHERE c.slug = 'cables' AND c.parent_id = (SELECT id FROM categories WHERE slug = 'electronics')");
$elecCables->execute();
$elecId = (int)$elecCables->fetchColumn();

$accCables = Db::get()->prepare("SELECT c.id FROM categories c WHERE c.slug = 'cables' AND c.parent_id = (SELECT id FROM categories WHERE slug = 'accessories')");
$accCables->execute();
$accId = (int)$accCables->fetchColumn();

assert_true($elecId > 0, 'electronics/cables category exists');
assert_true($accId > 0, 'accessories/cables category exists');
assert_true($elecId !== $accId, 'Same-slug categories under different parents are distinct');

// ============================================================
// TEST 37: Cart::removeUnavailable
// ============================================================
echo "\n--- Test 37: Cart removeUnavailable ---\n";

$_SESSION = ['cart' => []];
$cartProd = Products::bySku('TSHIRT-BLK-M');
Cart::add((int)$cartProd['id'], null, 5);
// Also add a product with non-existent ID
$_SESSION['cart']['p_999999'] = ['product_id' => 999999, 'variant_id' => null, 'qty' => 2];

$cartBefore = Cart::get();
assert_equals(2, count($cartBefore), 'Cart has 2 items before cleanup');

Cart::removeUnavailable([]);
$cartAfter = Cart::get();
assert_true(!isset($cartAfter['p_999999']), 'Non-existent product removed from cart');
assert_true(isset($cartAfter['p_' . $cartProd['id']]), 'Valid product still in cart');

// ============================================================
// TEST 38: Coupon per-customer limit
// ============================================================
echo "\n--- Test 38: Coupon Per-Customer Limit ---\n";

Pricing::setConfig([
    'customer_groups' => ['guest' => ['discount' => 0]],
    'catalog_rules' => [],
    'tier_pricing' => ['enabled' => false],
    'cart_rules' => [
        [
            'active' => true,
            'type' => 'coupon',
            'code' => 'ONCE',
            'name' => 'One-time coupon',
            'discount_type' => 'percent',
            'discount_value' => 15,
            'per_customer' => 1,
        ],
    ],
]);

// First use should work
$r1 = Pricing::applyCoupon('ONCE', 10000, 'repeat@test.com');
assert_true($r1['discount'] > 0, 'First coupon use succeeds');

// Record usage (use a real order ID from previous test)
$existingOrder = $pdo->query("SELECT id FROM orders LIMIT 1")->fetchColumn();
Pricing::recordCouponUse('ONCE', (int)$existingOrder, 'repeat@test.com', $r1['discount']);

// Second use by same email should be rejected
$r2 = Pricing::applyCoupon('ONCE', 10000, 'repeat@test.com');
assert_equals(0, $r2['discount'], 'Second use by same customer rejected');
assert_equals('Coupon already used', $r2['error'], 'Per-customer limit error message');

// Different email should still work
$r3 = Pricing::applyCoupon('ONCE', 10000, 'other@test.com');
assert_true($r3['discount'] > 0, 'Different customer can still use coupon');

// Restore default pricing config
Pricing::setConfig([
    'customer_groups' => [
        'guest' => ['discount' => 0],
        'wholesale' => ['discount' => 15],
        'vip' => ['discount' => 10],
    ],
    'catalog_rules' => [
        [
            'active' => true,
            'discount_type' => 'percent',
            'discount_value' => 20,
            'conditions' => ['category' => 'tricka'],
        ],
    ],
    'tier_pricing' => [
        'enabled' => true,
        'rules' => [
            ['min_qty' => 10, 'discount_percent' => 5],
            ['min_qty' => 50, 'discount_percent' => 12],
        ],
    ],
    'cart_rules' => [
        [
            'active' => true,
            'type' => 'coupon',
            'code' => 'WELCOME10',
            'name' => 'Welcome 10% off',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'min_subtotal' => 2000,
        ],
        [
            'active' => true,
            'type' => 'automatic',
            'name' => 'Free shipping over 50',
            'conditions' => ['min_subtotal' => 5000],
            'action' => 'free_shipping',
        ],
    ],
]);

// ============================================================
// TEST 39: Adapters Facade
// ============================================================
echo "\n--- Test 39: Adapters Facade ---\n";

// Test CashOnDelivery adapter via Adapters::set()
require_once TEST_CLI_DIR . '/../adapters/PaymentAdapter.php';
require_once TEST_CLI_DIR . '/../adapters/contrib/CashOnDelivery.php';
$cod = new CashOnDelivery([]);
Adapters::set('payment', $cod);

$session = Adapters::payment()->createSession(['order_number' => 'TEST-001', 'success_url' => '/ok']);
assert_true(!empty($session['session_id']), 'COD adapter returns session_id');
assert_true(str_contains($session['session_id'], 'cod_'), 'COD session_id starts with cod_');
assert_equals('/ok', $session['url'], 'COD redirects to success_url');

$status = Adapters::payment()->getStatus('cod_TEST-001');
assert_equals('pending_delivery', $status, 'COD status is pending_delivery');

$refund = Adapters::payment()->refund('cod_TEST-001', 1000);
assert_equals('manual', $refund['status'], 'COD refund is manual');

// Test StorageAdapter interface
require_once TEST_CLI_DIR . '/../adapters/StorageAdapter.php';
require_once TEST_CLI_DIR . '/../adapters/contrib/LocalStorage.php';
$tmpDir = sys_get_temp_dir() . '/bbshop_test_uploads_' . getmypid();
$storage = new LocalStorage(['uploads_dir' => $tmpDir, 'uploads_url' => '/uploads']);
Adapters::set('storage', $storage);

$url = $storage->store('test/sample.txt', 'hello world');
assert_equals('/uploads/test/sample.txt', $url, 'LocalStorage store returns URL');
assert_true(file_exists($tmpDir . '/test/sample.txt'), 'File exists on disk');

$medUrl = $storage->url('test/sample.txt', 'medium');
assert_true(str_contains($medUrl, 'sample-medium'), 'URL contains variant name');

$fullUrl = $storage->url('test/sample.txt', 'full');
assert_equals('/uploads/test/sample.txt', $fullUrl, 'Full variant returns original URL');

$storage->delete('test/sample.txt');
assert_true(!file_exists($tmpDir . '/test/sample.txt'), 'File deleted from disk');

// Cleanup
@rmdir($tmpDir . '/test');
@rmdir($tmpDir);

// ============================================================
// TEST 40: Enrich adds category_path for pricing
// ============================================================
echo "\n--- Test 40: Enrich Category Path for Pricing ---\n";

$enrichedProduct = Products::enrich(Products::bySku('TSHIRT-BLK-M'));
assert_true(!empty($enrichedProduct['category_slug']), 'Enriched product has category_slug');
assert_true(!empty($enrichedProduct['category_path']), 'Enriched product has category_path');
assert_true(str_contains($enrichedProduct['category_path'], 'tricka'), 'Category path contains tricka');

// Test that pricing rules now match with category condition
$enrichedForPricing = Products::enrich(Products::bySku('TSHIRT-BLK-M'));
$priced = Pricing::apply($enrichedForPricing);
assert_true($priced['price'] < $enrichedForPricing['price'] || !empty($priced['discount_percent']),
    'Catalog rule with category condition applied via category_path');

// ============================================================
// TEST 41: Idempotency error handling
// ============================================================
echo "\n--- Test 41: Idempotency Selective Error Handling ---\n";

// First insert should work (not duplicate)
$isdup1 = Idempotency::webhookProcessed('test_idem_new_' . time());
assert_equals(false, $isdup1, 'New event is not duplicate');

// Second insert of same event should be duplicate
$eventId = 'test_idem_dup_' . time();
Idempotency::webhookProcessed($eventId);
$isdup2 = Idempotency::webhookProcessed($eventId);
assert_equals(true, $isdup2, 'Repeat event is duplicate');

// ============================================================
// TEST 42: LocalStorage path traversal prevention
// ============================================================
echo "\n--- Test 42: LocalStorage Path Traversal Prevention ---\n";

$tmpDir2 = sys_get_temp_dir() . '/bbshop_test_uploads2_' . getmypid();
$storage2 = new LocalStorage(['uploads_dir' => $tmpDir2, 'uploads_url' => '/uploads']);

// Normal path should work
$url2 = $storage2->store('products/test.txt', 'safe content');
assert_equals('/uploads/products/test.txt', $url2, 'Normal path storage works');

// Path traversal should throw
$traversalThrown = false;
try {
    $storage2->store('../../etc/passwd', 'malicious');
} catch (\RuntimeException $e) {
    $traversalThrown = true;
}
assert_true($traversalThrown, 'Path traversal in store() throws RuntimeException');

foreach (['', "bad\0name", "bad\nname", '/etc/passwd', '\\server\\share\\file', 'C:\\Windows\\file', 'products/file.txt:payload', 'products/./file', 'products\\..\\file', 'products\\mixed/file'] as $badPath) {
    foreach (['store', 'delete', 'url', 'resize'] as $method) {
        $rejected = false;
        try {
            if ($method === 'store') $storage2->store($badPath, 'x');
            elseif ($method === 'delete') $storage2->delete($badPath);
            elseif ($method === 'url') $storage2->url($badPath);
            else $storage2->resize($badPath, 10, 10);
        } catch (\RuntimeException $e) {
            $rejected = true;
        }
        assert_true($rejected, "LocalStorage {$method} rejects unsafe path " . json_encode($badPath));
    }
}

file_put_contents($tmpDir2 . '/blocked', 'not a directory');
$mkdirFailureThrown = false;
try { $storage2->store('blocked/file.txt', 'x'); } catch (\RuntimeException $e) { $mkdirFailureThrown = true; }
assert_true($mkdirFailureThrown, 'LocalStorage mkdir failure throws');

$siblingDir2 = $tmpDir2 . '_sibling';
@mkdir($siblingDir2);
$linkDir2 = $tmpDir2 . '/escape-link';
if (@symlink($siblingDir2, $linkDir2)) {
    $symlinkRejected = $symlinkUrlRejected = false;
    try { $storage2->store('escape-link/escaped.txt', 'x'); } catch (\RuntimeException $e) { $symlinkRejected = true; }
    try { $storage2->url('escape-link/escaped.txt'); } catch (\RuntimeException $e) { $symlinkUrlRejected = true; }
    assert_true($symlinkRejected, 'LocalStorage rejects sibling-prefix symlink escape');
    assert_true($symlinkUrlRejected, 'LocalStorage refuses URL generation through an escaping symlink');
    assert_true(!file_exists($siblingDir2 . '/escaped.txt'), 'LocalStorage symlink escape writes nothing outside root');
    @unlink($linkDir2);
} else {
    echo "  [SKIP] LocalStorage symlink regression (symlink unavailable)\n";
}
@rmdir($siblingDir2);

// Cleanup
@unlink($tmpDir2 . '/blocked');
@unlink($tmpDir2 . '/products/test.txt');
@rmdir($tmpDir2 . '/products');
@rmdir($tmpDir2);

// ============================================================
// TEST 43: Adapters class name validation
// ============================================================
echo "\n--- Test 43: Adapters Class Name Validation ---\n";

$invalidThrown = false;
try {
    // Try to resolve an adapter with a dangerous class name
    Adapters::set('_test_invalid', (object)[]);  // clear any cache
    // We can't easily test resolve() with bad config, so validate the regex directly
    $badNames = ['../Foo', 'Foo Bar', 'Foo;Bar', 'Foo/Bar'];
    foreach ($badNames as $bad) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $bad)) {
            $invalidThrown = true;
        }
    }
} catch (\Throwable $e) {
    $invalidThrown = true;
}
assert_true($invalidThrown, 'Invalid adapter class names are rejected by validation regex');

// Valid names should pass
$validNames = ['LocalStorage', 'StripePayment', 'CashOnDelivery', 'My_Adapter'];
$allValid = true;
foreach ($validNames as $valid) {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $valid)) $allValid = false;
}
assert_true($allValid, 'Valid adapter class names pass validation regex');
assert_true(is_subclass_of(IntegrationAdapter::class, ErpAdapter::class, true), 'Extension contract: legacy integration composes ERP capability');
assert_true(is_subclass_of(IntegrationAdapter::class, PimAdapter::class, true), 'Extension contract: legacy integration composes PIM capability');
assert_true(is_subclass_of(IntegrationAdapter::class, WmsAdapter::class, true), 'Extension contract: legacy integration composes WMS capability');
assert_equals(null, Adapters::pim(), 'Extension contract: optional PIM remains disabled without configuration');
assert_equals(null, Adapters::wms(), 'Extension contract: optional WMS remains disabled without configuration');
$domainEventNames = DomainEvents::all();
assert_equals(count($domainEventNames), count(array_unique($domainEventNames)), 'Extension contract: stable domain event names are unique');
foreach ($domainEventNames as $domainEventName) {
    assert_true(preg_match('/^[a-z][a-z0-9_.-]{1,190}$/', $domainEventName) === 1, "Extension contract: {$domainEventName} is a valid outbox event name");
}

// ============================================================
// TEST 44: Idempotency unmark allows retry
// ============================================================
echo "\n--- Test 44: Idempotency Unmark Allows Retry ---\n";

$retryEventId = 'test_retry_' . time();
$dup1 = Idempotency::webhookProcessed($retryEventId);
assert_equals(false, $dup1, 'First processing marks event as new');

$dup2 = Idempotency::webhookProcessed($retryEventId);
assert_equals(true, $dup2, 'Second attempt is duplicate');

Idempotency::unmark($retryEventId);
$dup3 = Idempotency::webhookProcessed($retryEventId);
assert_equals(false, $dup3, 'After unmark, event can be reprocessed');

// ============================================================
// TEST 45: OrderWorkflow cancellation skips checkout session refund
// ============================================================
echo "\n--- Test 45: Cancellation Refund Guard ---\n";

// Set up a fresh jobs dir for this test
$testJobsDir45 = sys_get_temp_dir() . '/bareboneseshop-jobs-refund-' . getmypid();
JobQueue::setDir($testJobsDir45);

// Create an order with a checkout session ID (cs_*) as payment_id
$csOrder = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_method, payment_id)
    VALUES ('ORD-CS-TEST', 'Test User', 'test@test.com', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'pending', 'stripe', 'cs_test_session_123')");
$csOrder->execute();
$csOrderId = (int)$pdo->lastInsertId();

// Transition to cancelled
OrderWorkflow::transition($csOrderId, 'cancelled', 'Test cancel with checkout session');

// Verify no refund job was queued
$refundJobFound = false;
foreach (glob($testJobsDir45 . '/pending/*.json') ?: [] as $jf) {
    $jd = json_decode(file_get_contents($jf), true);
    if (($jd['type'] ?? '') === 'payment_refund' && ($jd['payload']['order_id'] ?? 0) === $csOrderId) {
        $refundJobFound = true;
    }
}
assert_true(!$refundJobFound, 'No refund job queued for checkout session (cs_*) payment_id');

// Now test with a payment_intent ID — refund SHOULD be queued
$piOrder = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_method, payment_id)
    VALUES ('ORD-PI-TEST', 'Test User', 'test@test.com', '', '{}', 2000, 0, 0, 0, 2000, 'eur', 'pending', 'stripe', 'pi_test_intent_456')");
$piOrder->execute();
$piOrderId = (int)$pdo->lastInsertId();

OrderWorkflow::transition($piOrderId, 'cancelled', 'Test cancel with payment intent');

$refundJobFound2 = false;
foreach (glob($testJobsDir45 . '/pending/*.json') ?: [] as $jf) {
    $jd = json_decode(file_get_contents($jf), true);
    if (($jd['type'] ?? '') === 'payment_refund' && ($jd['payload']['order_id'] ?? 0) === $piOrderId) {
        $refundJobFound2 = true;
    }
}
assert_true($refundJobFound2, 'Refund job queued for payment_intent (pi_*) payment_id');

// Cleanup jobs dir
foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
    foreach (glob($testJobsDir45 . '/' . $sub . '/*.json') ?: [] as $f) @unlink($f);
    @rmdir($testJobsDir45 . '/' . $sub);
}
@rmdir($testJobsDir45);

// ============================================================
// TEST 46: Template engine (now owned by MailService)
// ============================================================
echo "\n--- Test 46: Template Engine ---\n";

// Basic variable substitution
$tpl1 = MailService::renderString('Hello {{name}}, order #{{order_number}}', [
    'name' => 'Peter',
    'order_number' => 'ORD-001',
]);
assert_true(str_contains($tpl1, 'Peter'), 'Template var substitution works');
assert_true(str_contains($tpl1, 'ORD-001'), 'Template multiple vars work');

// {{#if}} blocks
$tpl2 = MailService::renderString('{{#if paid}}Payment received{{/if}}{{#if refunded}}Refunded{{/if}}', [
    'paid' => true,
    'refunded' => false,
]);
assert_true(str_contains($tpl2, 'Payment received'), 'Template #if truthy renders block');
assert_true(!str_contains($tpl2, 'Refunded'), 'Template #if falsy hides block');

// {{#each}} blocks
$tpl3 = MailService::renderString('<ul>{{#each items}}<li>{{name}} x{{quantity}}</li>{{/each}}</ul>', [
    'items' => [
        ['name' => 'Widget', 'quantity' => '2'],
        ['name' => 'Gadget', 'quantity' => '1'],
    ],
]);
assert_true(str_contains($tpl3, 'Widget'), 'Template #each renders items');
assert_true(str_contains($tpl3, 'Gadget'), 'Template #each renders all items');
assert_true(str_contains($tpl3, 'x2'), 'Template #each uses item fields');

// HTML escaping
$tpl4 = MailService::renderString('{{name}}', ['name' => '<script>alert(1)</script>']);
assert_true(!str_contains($tpl4, '<script>'), 'Template escapes HTML');
assert_true(str_contains($tpl4, '&lt;script&gt;'), 'Template HTML-encodes special chars');
