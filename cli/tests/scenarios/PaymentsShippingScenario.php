<?php

// TEST: HTTP Smoke — hit main pages, check for PHP warnings/errors
// ============================================================
echo "\n--- Test: HTTP Smoke ---\n";

$smokeBaseUrl = getenv('SMOKE_URL') ?: 'http://localhost:8080';
$smokePages = [
    '/'                          => 'Homepage',
    '/?page=cart'                => 'Cart',
    '/?page=checkout'            => 'Checkout',
];

// Add a real category and product if we have access to the live DB
// Try to discover category/product slugs from homepage
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
$homeHtml = @file_get_contents($smokeBaseUrl . '/', false, $ctx);
$smokeAvailable = ($homeHtml !== false);

if ($smokeAvailable) {
    if (preg_match('/page=category&amp;slug=([a-z0-9-]+)/', $homeHtml, $cm)) {
        $smokePages['/?page=category&slug=' . $cm[1]] = 'Category: ' . $cm[1];
    }
    if (preg_match('/page=product&amp;slug=([a-z0-9-]+)/', $homeHtml, $pm)) {
        $smokePages['/?page=product&slug=' . $pm[1]] = 'Product: ' . $pm[1];
    }
    $smokePages['/?page=search&q=harvia'] = 'Search: harvia';

    $phpErrorPattern = '/(Warning|Fatal|Parse error|Notice|Deprecated|Undefined variable|Uncaught).*?(on line \d+|in \/|in [A-Z]:\\\\)/i';

    foreach ($smokePages as $path => $label) {
        $stream = @fopen($smokeBaseUrl . $path, 'r', false, $ctx);
        if ($stream === false) {
            assert_true(false, "Smoke {$label}: Failed to fetch");
            continue;
        }
        $html = stream_get_contents($stream);
        $metadata = stream_get_meta_data($stream);
        fclose($stream);
        if (!is_string($html)) {
            assert_true(false, "Smoke {$label}: Failed to read response");
            continue;
        }
        $status = 200;
        foreach ($metadata['wrapper_data'] ?? [] as $header) {
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $header, $hm)) $status = (int)$hm[1];
        }
        if ($status >= 400) {
            assert_true(false, "Smoke {$label}: HTTP {$status}");
        } elseif (preg_match($phpErrorPattern, $html, $errMatch)) {
            assert_true(false, "Smoke {$label}: PHP error — " . substr($errMatch[0], 0, 120));
        } else {
            assert_true(true, "Smoke {$label}: OK ({$status}, " . strlen($html) . " bytes)");
        }
    }
} else {
    echo "  [SKIP] No server running at {$smokeBaseUrl} — start with: php -S localhost:8080\n";
}

// ============================================================
// \ TEST: Product Images — verify image files exist on disk
// ============================================================
echo "\n--- Test: Product Images ---\n";

$liveDbPath = TEST_CLI_DIR . '/../db/shop.sqlite';
if (file_exists($liveDbPath)) {
    $imgDb = new PDO('sqlite:' . $liveDbPath);
    $imgDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $imgRows = $imgDb->query("SELECT id, slug, images FROM products WHERE images IS NOT NULL AND images != '[]' AND images != 'null'")->fetchAll(PDO::FETCH_ASSOC);

    $totalImages = 0;
    $missingImages = 0;
    $missingExamples = [];
    $baseDir = realpath(TEST_CLI_DIR . '/..');

    foreach ($imgRows as $row) {
        $imgs = json_decode($row['images'], true);
        if (!is_array($imgs)) continue;
        foreach ($imgs as $imgPath) {
            $totalImages++;
            $fullPath = $baseDir . '/' . ltrim(str_replace('\\', '/', $imgPath), '/');
            if (!file_exists($fullPath)) {
                $missingImages++;
                if (count($missingExamples) < 3) {
                    $missingExamples[] = $row['slug'] . ' → ' . $imgPath;
                }
            }
        }
    }

    if ($totalImages === 0) {
        echo "  [SKIP] No product image references in live database\n";
    } else {
        $missingPct = round($missingImages / $totalImages * 100, 1);
        assert_true($missingImages === 0, "Product images: {$missingImages}/{$totalImages} missing on disk ({$missingPct}%)");
    }

    if (!empty($missingExamples)) {
        foreach ($missingExamples as $ex) {
            echo "    Missing: {$ex}\n";
        }
    }

    // Also verify via HTTP if server is running
    if ($smokeAvailable && $totalImages > 0) {
        // Pick a product with images and check img tags resolve
        $sampleRow = $imgRows[0];
        $sampleImgs = json_decode($sampleRow['images'], true);
        if (!empty($sampleImgs[0])) {
            $imgUrl = $smokeBaseUrl . '/' . ltrim($sampleImgs[0], '/');
            $imgHeaders = @get_headers($imgUrl, true);
            $imgStatus = 404;
            if ($imgHeaders && preg_match('/\s(\d{3})/', $imgHeaders[0], $sm)) {
                $imgStatus = (int)$sm[1];
            }
            assert_true($imgStatus === 200, "Product images HTTP: {$sampleRow['slug']} image returns {$imgStatus}");
        }
    }

    $imgDb = null;
} else {
    echo "  [SKIP] No live database at {$liveDbPath}\n";
}

// ============================================================
// TEST: Payment Gateways
// ============================================================
echo "\n--- Test: Payment — MockGateway initiate ---\n";

// Setup MockPayment adapter
require_once TEST_CLI_DIR . '/../adapters/PaymentAdapter.php';
require_once TEST_CLI_DIR . '/../adapters/contrib/MockPayment.php';
require_once TEST_CLI_DIR . '/../src/PaymentService.php';

// Add payment_transactions table to test DB
$pdo->exec("CREATE TABLE IF NOT EXISTS payment_transactions (
    id              INTEGER PRIMARY KEY,
    order_id        INTEGER NOT NULL,
    provider        TEXT NOT NULL,
    provider_ref    TEXT NOT NULL DEFAULT '',
    status          TEXT NOT NULL,
    amount          INTEGER NOT NULL,
    currency        TEXT NOT NULL DEFAULT 'eur',
    idempotency_key TEXT NOT NULL UNIQUE,
    raw_response    TEXT NOT NULL DEFAULT '{}',
    created_at      TEXT DEFAULT (datetime('now')),
    updated_at      TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
)");
// This fixture is created after the initial migration pass; reapply G9 so its
// additive payment correlation column is tested against the same late table.
$adminQueueMigration->up($pdo, true);

// Add payment_status column to orders
try { $pdo->exec("ALTER TABLE orders ADD COLUMN payment_status TEXT NOT NULL DEFAULT 'pending'"); }
catch (\PDOException $e) { /* already exists */ }

// Payment method pricing tables
$paymentMigFile = TEST_CLI_DIR . '/../migrations/20260425071000_payment_methods_pricing_engine.php';
if (file_exists($paymentMigFile)) {
    $paymentMig = require $paymentMigFile;
    $paymentMig->up($pdo, true);
}

// Digital commerce tables and variant availability
$digitalCommerceMigFile = TEST_CLI_DIR . '/../migrations/20260713093000_digital_commerce_core.php';
if (file_exists($digitalCommerceMigFile)) {
    $digitalCommerceMig = require $digitalCommerceMigFile;
    $digitalCommerceMig->up($pdo, true);
}

// Shipping tables
$shippingMigFile = TEST_CLI_DIR . '/../migrations/20260425064000_shipping_methods_pricing_engine.php';
if (file_exists($shippingMigFile)) {
    $shippingMig = require $shippingMigFile;
    $shippingMig->up($pdo, true);
}
$pdo->exec("CREATE TABLE IF NOT EXISTS shipping_rates (
    id            INTEGER PRIMARY KEY,
    carrier       TEXT NOT NULL,
    country       TEXT NOT NULL,
    weight_from_g INTEGER NOT NULL DEFAULT 0,
    weight_to_g   INTEGER NOT NULL DEFAULT 999999,
    price         INTEGER NOT NULL,
    cod_surcharge INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT DEFAULT (datetime('now'))
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS shipping_labels (
    id         INTEGER PRIMARY KEY,
    order_id   INTEGER NOT NULL,
    carrier    TEXT NOT NULL,
    awb        TEXT NOT NULL UNIQUE,
    status     TEXT NOT NULL DEFAULT 'created',
    label_data TEXT NOT NULL DEFAULT '',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
)");
try { $pdo->exec("ALTER TABLE orders ADD COLUMN tracking_number TEXT DEFAULT NULL"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE orders ADD COLUMN carrier TEXT DEFAULT NULL"); } catch (\PDOException $e) {}

// Later feature tables must receive the same idempotent store backfill.
$storeScopeMigration->up($pdo, true);
$domainStoreConfig = Bootstrap::stores();
$domainStoreConfig['stores']['default']['domains'] = ['shop.test'];
assert_equals('default', StoreContext::resolve($domainStoreConfig, '/de/products', 'shop.test')->id(), 'Store resolution: matched domain takes precedence over another store URL prefix');
$deMarketContext = StoreContext::resolve(Bootstrap::stores(), '/de/products', '', 'de');
StoreContext::activate($deMarketContext);
assert_true(Products::bySku('TSHIRT-BLK-M') !== null, 'Store compatibility: existing market route shares the migrated default catalog');
$isolatedConfig = Bootstrap::stores();
$isolatedConfig['stores']['isolated'] = ['id' => 'isolated', 'name' => 'Isolated', 'default_locale' => 'en', 'languages' => ['en'], 'currency' => 'eur', 'url_prefix' => '/isolated', 'domains' => [], 'channels' => ['storefront'], 'permissions' => ['*'], 'legal' => []];
$deContext = StoreContext::resolve($isolatedConfig, '/isolated/products', '', 'isolated');
StoreContext::activate($deContext);
$pdo->exec("INSERT INTO categories (slug, name, path, depth, store_id) VALUES ('de-isolation', 'DE Isolation', 'de-isolation', 0, 'isolated')");
$deCategoryId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (sku, slug, name, category_id, price, currency, stock, store_id) VALUES ('DE-ONLY', 'de-only', 'DE only', ?, 1299, 'eur', 5, 'isolated')")->execute([$deCategoryId]);
assert_equals('DE-ONLY', Products::bySku('DE-ONLY')['sku'] ?? null, 'Store isolation: second store sees its product');
assert_true(Products::bySku('TSHIRT-BLK-M') === null, 'Store isolation: second store cannot read default catalog');
assert_true(Orders::byId($orderId) === null, 'Store isolation: second store cannot read default order');
Cache::write('isolation/key.txt', 'de');
$deCachePath = Cache::path('isolation/key.txt');
Bootstrap::initStoreContext('/', '', 'default');
Cache::write('isolation/key.txt', 'default');
assert_true($deCachePath !== Cache::path('isolation/key.txt'), 'Store isolation: identical cache keys have distinct paths');
assert_equals('default', Cache::read('isolation/key.txt'), 'Store isolation: default cache value is not contaminated');
assert_true(Products::bySku('DE-ONLY') === null, 'Store isolation: default store cannot read second-store catalog');
assert_true(Orders::byId($orderId) !== null, 'Store isolation: default order remains accessible in its store');

// Insert shipping_rates test fixtures
$pdo->exec("INSERT INTO shipping_rates (carrier, country, weight_from_g, weight_to_g, price, cod_surcharge) VALUES
    ('packeta_test', 'SK', 0,    2000,  299, 100),
    ('packeta_test', 'SK', 2001, 5000,  349, 100),
    ('packeta_test', 'SK', 5001, 10000, 449, 100),
    ('packeta_test', 'CZ', 0,    2000,  399, 100),
    ('packeta_test', 'CZ', 2001, 5000,  449, 100),
    ('gls_test',     'SK', 0,    2000,  399, 150),
    ('gls_test',     'SK', 2001, 5000,  499, 150),
    ('gls_test',     'DE', 0,    5000,  899, 150)
");

$mockGateway = new MockPayment(['mock_result' => 'success', 'mock_webhook_secret' => 'test-mock-webhook-secret']);

// Create a fresh pending order for payment tests
$payProduct = Products::bySku('TSHIRT-BLK-M');
$payOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Pay Customer',
    'email'         => 'pay@example.com',
    'phone'         => '+421900000001',
    'address'       => ['street' => 'Pay 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 0,
    'tax_amount'    => 0,
], 'card');
$payOrder = Orders::byId($payOrderId);

// Test A: initiate payment session
$session = PaymentService::initiate([
    'order_id'       => $payOrder['id'],
    'order_number'   => $payOrder['order_number'],
    'total'          => (int)$payOrder['total'],
    'currency'       => $payOrder['currency'],
    'items'          => Orders::items($payOrder['id']),
    'customer_email' => $payOrder['customer_email'],
    'success_url'    => '/thank-you',
    'cancel_url'     => '/cart',
], $mockGateway);

assert_true(!empty($session['url']), 'Payment: createSession returns URL');
assert_true(!empty($session['session_id']), 'Payment: createSession returns session_id');
assert_true(str_starts_with($session['session_id'], 'mock_'), 'Payment: Mock session_id starts with mock_');

// Verify initiated transaction logged
$txs = PaymentService::transactionsForOrder((int)$payOrder['id']);
assert_true(count($txs) >= 1, 'Payment: initiate logs transaction');
assert_equals('initiated', $txs[0]['status'], 'Payment: first transaction status is initiated');
assert_equals('mock', $txs[0]['provider'], 'Payment: provider is mock');
assert_equals('default', $txs[0]['store_id'], 'Payment: transaction stores active store ownership');
assert_true(!empty($txs[0]['correlation_id']), 'G9 payment: transaction stores the operational correlation ID');
StoreContext::activate($deContext);
assert_equals(0, count(PaymentService::transactionsForOrder((int)$payOrder['id'])), 'Store isolation: second store cannot read default payment transactions');
$crossStorePaymentRejected = false;
try { PaymentService::initiate(['order_id' => $payOrder['id']], $mockGateway); } catch (\RuntimeException $e) { $crossStorePaymentRejected = true; }
assert_true($crossStorePaymentRejected, 'Store isolation: second store cannot initiate payment for default order');
Bootstrap::initStoreContext('/', '', 'default');

echo "\n--- Test: Payment — webhook paid flow ---\n";

// Test B: simulate paid webhook
$paidPayload = MockPayment::paidPayload((int)$payOrder['id'], (int)$payOrder['total'], $session['session_id']);
$paidRawPayload = json_encode($paidPayload);
$paidSignature = hash_hmac('sha256', $paidRawPayload, 'test-mock-webhook-secret');
$webhookResult = PaymentService::handleWebhook(
    rawPayload:    $paidRawPayload,
    sigHeader:     $paidSignature,
    parsedPayload: $paidPayload,
    gateway:       $mockGateway,
    providerName:  'mock',
);

assert_true($webhookResult['handled'], 'Payment: webhook handled=true');
assert_equals('payment.paid', $webhookResult['event_type'], 'Payment: webhook event_type is payment.paid');

// Order should now be 'paid'
$paidOrder = Orders::byId((int)$payOrder['id']);
assert_equals('paid', $paidOrder['status'], 'Payment: order status is paid after webhook');
assert_equals('paid', $paidOrder['payment_status'], 'Payment: order payment_status is paid');

// Transaction log should have paid entry
$txs2 = PaymentService::transactionsForOrder((int)$payOrder['id']);
$statuses = array_column($txs2, 'status');
assert_true(in_array('paid', $statuses), 'Payment: paid transaction logged');

echo "\n--- Test: Payment — webhook idempotency ---\n";

// Test C: replay same webhook — must be deduplicated
$dupResult = PaymentService::handleWebhook(
    rawPayload:    $paidRawPayload,
    sigHeader:     $paidSignature,
    parsedPayload: $paidPayload,
    gateway:       $mockGateway,
    providerName:  'mock',
);
assert_true($dupResult['duplicate'] ?? false, 'Payment: duplicate webhook is idempotent');
assert_true(!$dupResult['handled'], 'Payment: duplicate webhook handled=false');

echo "\n--- Test: Payment — bad signature rejection ---\n";

// Test D: bad signature → exception
$badSigThrown = false;
try {
    $failGateway = new MockPayment(['mock_result' => 'success']);
    // Override verifySignature via anonymous class trick — use empty sig header
    PaymentService::handleWebhook(
        rawPayload:    json_encode($paidPayload),
        sigHeader:     '',   // empty sig → MockPayment::verifySignature returns false
        parsedPayload: $paidPayload,
        gateway:       $failGateway,
        providerName:  'mock',
    );
} catch (\RuntimeException $e) {
    $badSigThrown = str_contains($e->getMessage(), 'signature');
}
assert_true($badSigThrown, 'Payment: empty signature throws RuntimeException');

echo "\n--- Test: Payment — refund flow ---\n";

// Test E: refund
$refundResult = PaymentService::refund(
    orderId:    (int)$payOrder['id'],
    paymentId:  $session['session_id'],
    amount:     (int)$payOrder['total'],
    gateway:    $mockGateway,
);
assert_true(!empty($refundResult['refund_id']), 'Payment: refund returns refund_id');
assert_equals('succeeded', $refundResult['status'], 'Payment: mock refund status is succeeded');

$txs3 = PaymentService::transactionsForOrder((int)$payOrder['id']);
$refundTx = array_filter($txs3, fn($t) => str_starts_with($t['status'], 'refund_'));
assert_true(count($refundTx) >= 1, 'Payment: refund transaction logged');

echo "\n--- Test: Payment — fail gateway ---\n";

// Test F: fail gateway
$failMock = new MockPayment(['mock_result' => 'fail']);
$failSession = $failMock->createSession([
    'order_number' => 'TEST-FAIL',
    'total'        => 1000,
    'currency'     => 'eur',
    'items'        => [],
    'success_url'  => '/',
]);
assert_true(!empty($failSession['session_id']), 'Payment: fail gateway still returns session_id');

$failStatus = $failMock->getStatus($failSession['session_id']);
assert_equals('failed', $failStatus, 'Payment: fail gateway getStatus returns failed');

// Payment methods pricing engine
assert_true(isset($paymentMig), 'Payment methods: migration loaded');
$payMethodCount = (int)$pdo->query("SELECT COUNT(*) FROM payment_methods WHERE code IN ('card', 'bank_transfer', 'cod')")->fetchColumn();
assert_equals(3, $payMethodCount, 'Payment methods: default seed creates card, transfer and COD');
$paymentMig->up($pdo, true);
$payMethodCount2 = (int)$pdo->query("SELECT COUNT(*) FROM payment_methods WHERE code IN ('card', 'bank_transfer', 'cod')")->fetchColumn();
assert_equals(3, $payMethodCount2, 'Payment methods: seed is idempotent');

$pdo->exec("UPDATE payment_methods SET name = 'Admin custom card' WHERE code = 'card'");
$paymentMig->up($pdo, true);
$customCardName = (string)$pdo->query("SELECT name FROM payment_methods WHERE code = 'card'")->fetchColumn();
assert_equals('Admin custom card', $customCardName, 'Payment methods: seed does not overwrite admin-edited method');

$paymentMethodsSkPacketa = PaymentService::availableMethods('SK', 'eur', 2500, 'packeta');
$paymentCodesSkPacketa = array_column($paymentMethodsSkPacketa, 'code');
assert_true(in_array('card', $paymentCodesSkPacketa, true), 'Payment methods: card available for SK Packeta');
assert_true(in_array('bank_transfer', $paymentCodesSkPacketa, true), 'Payment methods: bank transfer available for SK Packeta');
assert_true(in_array('cod', $paymentCodesSkPacketa, true), 'Payment methods: COD available for SK Packeta');
$codQuote = PaymentService::selectMethod('cod', 'SK', 'eur', 2500, 'packeta');
assert_true($codQuote !== null, 'Payment methods: COD selectable for supported shipping method');
assert_equals(120, (int)$codQuote['fee'], 'Payment methods: COD fixed fee applied');

$codPickup = PaymentService::selectMethod('cod', 'SK', 'eur', 2500, 'pickup');
assert_true($codPickup === null, 'Payment methods: COD blocked for pickup shipping method');
$codGermany = PaymentService::selectMethod('cod', 'DE', 'eur', 2500, 'gls');
assert_true($codGermany === null, 'Payment methods: COD blocked outside seeded SK/CZ countries');

$pdo->exec("UPDATE payment_methods SET active = 0 WHERE code = 'bank_transfer'");
$disabledTransfer = PaymentService::selectMethod('bank_transfer', 'SK', 'eur', 2500, 'packeta');
assert_true($disabledTransfer === null, 'Payment methods: disabled method is not selectable');
$pdo->exec("UPDATE payment_methods SET active = 1 WHERE code = 'bank_transfer'");

$pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order) VALUES ('admin_test', 'manual', 'Admin Test', '', 'offline', 500, 5000, 25, 100, 3000, 0, 1, 99)");
$pdo->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('admin_test', 'SK')");
$adminTestQuote = PaymentService::selectMethod('admin_test', 'SK', 'eur', 1000, 'pickup');
assert_true($adminTestQuote !== null, 'Payment methods: admin-created method is selectable');
assert_equals(35, (int)$adminTestQuote['fee'], 'Payment methods: fixed + percent fee applied');
$adminTestFree = PaymentService::selectMethod('admin_test', 'SK', 'eur', 3000, 'pickup');
assert_equals(0, (int)$adminTestFree['fee'], 'Payment methods: free_above removes fee');
$adminTestTooHigh = PaymentService::selectMethod('admin_test', 'SK', 'eur', 6000, 'pickup');
assert_true($adminTestTooHigh === null, 'Payment methods: admin max amount limit enforced');
$pdo->exec("INSERT INTO payment_method_shipping (method_code, shipping_method_code) VALUES ('admin_test', 'gls')");
$adminTestWrongShipping = PaymentService::selectMethod('admin_test', 'SK', 'eur', 1000, 'pickup');
assert_true($adminTestWrongShipping === null, 'Payment methods: admin shipping constraint enforced');
$adminTestGls = PaymentService::selectMethod('admin_test', 'SK', 'eur', 1000, 'gls');
assert_true($adminTestGls !== null, 'Payment methods: admin shipping constraint allows configured method');
$pdo->exec("UPDATE payment_methods SET code = 'admin_test_renamed' WHERE code = 'admin_test'");
PaymentService::renameMethodCodeLinks('admin_test', 'admin_test_renamed');
$renamedCountryLinks = (int)$pdo->query("SELECT COUNT(*) FROM payment_method_countries WHERE method_code = 'admin_test_renamed' AND country = 'SK'")->fetchColumn();
$renamedShippingLinks = (int)$pdo->query("SELECT COUNT(*) FROM payment_method_shipping WHERE method_code = 'admin_test_renamed' AND shipping_method_code = 'gls'")->fetchColumn();
$renamedOldCountryLinks = (int)$pdo->query("SELECT COUNT(*) FROM payment_method_countries WHERE method_code = 'admin_test'")->fetchColumn();
$renamedOldShippingLinks = (int)$pdo->query("SELECT COUNT(*) FROM payment_method_shipping WHERE method_code = 'admin_test'")->fetchColumn();
assert_equals(1, $renamedCountryLinks, 'Payment methods: admin code rename preserves country links');
assert_equals(1, $renamedShippingLinks, 'Payment methods: admin code rename preserves shipping links');
assert_equals(0, $renamedOldCountryLinks, 'Payment methods: admin code rename removes stale old country links');
assert_equals(0, $renamedOldShippingLinks, 'Payment methods: admin code rename removes stale old shipping links');
$adminTestRenamedGls = PaymentService::selectMethod('admin_test_renamed', 'SK', 'eur', 1000, 'gls');
assert_true($adminTestRenamedGls !== null, 'Payment methods: renamed admin method remains selectable');
$pdo->exec("DELETE FROM payment_method_shipping WHERE method_code = 'admin_test_renamed'");
$pdo->exec("DELETE FROM payment_method_countries WHERE method_code = 'admin_test_renamed'");
$pdo->exec("DELETE FROM payment_methods WHERE code = 'admin_test_renamed'");

$paymentOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Payment Method Customer',
    'email'         => 'payment-method@example.com',
    'phone'         => '+421900000003',
    'address'       => ['street' => 'Payment 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 299,
    'payment_fee'   => (int)$codQuote['fee'],
    'payment_method_snapshot' => json_encode($codQuote, JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'cod');
$paymentOrder = Orders::byId($paymentOrderId);
assert_equals(120, (int)$paymentOrder['payment_fee'], 'Payment methods: order stores payment fee');
$paymentSnapshot = json_decode((string)$paymentOrder['payment_method_snapshot'], true) ?: [];
assert_equals('cod', (string)$paymentSnapshot['code'], 'Payment methods: order stores payment snapshot JSON');
assert_equals('manual', (string)$paymentSnapshot['provider'], 'Payment methods: order snapshot stores provider');
assert_equals('Dobierka', (string)$paymentSnapshot['label'], 'Payment methods: order snapshot stores label');

$checkoutPaymentMethods = PaymentService::availableMethods('SK', 'eur', 2799, 'packeta');
$checkoutPaymentCodes = array_column($checkoutPaymentMethods, 'code');
assert_true(in_array('cod', $checkoutPaymentCodes, true), 'Payment UX: checkout exposes COD for Packeta');
$checkoutPaymentCod = PaymentService::selectMethod('cod', 'SK', 'eur', 2799, 'packeta');
assert_true($checkoutPaymentCod !== null, 'Payment UX: checkout selected COD resolves against shipping method');
assert_equals(120, (int)$checkoutPaymentCod['fee'], 'Payment UX: checkout selected COD includes fee');
$checkoutPaymentPickupCod = PaymentService::selectMethod('cod', 'SK', 'eur', 2500, 'pickup');
assert_true($checkoutPaymentPickupCod === null, 'Payment UX: checkout blocks COD for pickup constraint');

if (!function_exists('apiCheckout')) {
    define('BB_TEST_LOAD_API_ONLY', true);
    require_once TEST_CLI_DIR . '/../api.php';
}
Adapters::set('payment', $mockGateway);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION = ['cart' => [], 'csrf_token' => 'checkout-initiate-test'];
Cart::add((int)$payProduct['id'], null, 1);
$_POST = [
    'csrf_token' => 'checkout-initiate-test',
    'name' => 'Online Checkout Customer',
    'email' => 'checkout-initiate@example.com',
    'phone' => '+421900000099',
    'street' => 'Testovacia 1',
    'city' => 'Bratislava',
    'zip' => '811 01',
    'country' => 'SK',
    'shipping_method' => 'packeta',
    'payment_method' => 'card',
];
$_SERVER['HTTP_HOST'] = 'attacker.example';
$onlineCheckoutCfg = require TEST_CLI_DIR . '/../config.php';
$onlineCheckoutCfg['app_url'] = 'https://shop.example';
$onlineCheckout = apiCheckout($onlineCheckoutCfg);
assert_true(!empty($onlineCheckout['success']), 'Payment UX: online checkout succeeds');
assert_true(!empty($onlineCheckout['payment_url']), 'Payment UX: online checkout returns payment_url');
assert_true(str_starts_with((string)$onlineCheckout['payment_url'], 'https://shop.example/'), 'G3: checkout callback uses configured app_url');
assert_true(!str_contains((string)$onlineCheckout['payment_url'], 'attacker.example'), 'G3: checkout callback ignores poisoned Host header');
assert_true(!empty($onlineCheckout['session_id']), 'Payment UX: online checkout keeps session_id contract');
assert_true(!empty($onlineCheckout['confirmation_token']), 'Payment UX: checkout returns opaque browser confirmation token');
assert_true(str_contains((string)$onlineCheckout['payment_url'], 'confirmation_token='), 'Payment UX: online success URL carries browser confirmation token');
$onlineCheckoutTx = PaymentService::transactionsForOrder((int)$onlineCheckout['order_id']);
assert_equals('initiated', (string)$onlineCheckoutTx[0]['status'], 'Payment UX: online checkout logs initiated transaction');
assert_equals((string)$onlineCheckout['session_id'], (string)$onlineCheckoutTx[0]['provider_ref'], 'Payment UX: initiated transaction stores session_id');
$onlineCheckoutOrder = Orders::byId((int)$onlineCheckout['order_id']);
assert_equals('packeta', (string)$onlineCheckoutOrder['shipping_method'], 'Shipping UX: api checkout stores selected ShippingService method');
assert_equals('domestic', (string)$onlineCheckoutOrder['shipping_zone'], 'Shipping UX: api checkout stores selected ShippingService zone');
$onlineCheckoutShippingSnapshot = json_decode((string)$onlineCheckoutOrder['shipping_rate_snapshot'], true) ?: [];
assert_equals('packeta', (string)$onlineCheckoutShippingSnapshot['method_code'], 'Shipping UX: api checkout stores selected ShippingService rate snapshot');
assert_equals((int)$onlineCheckoutShippingSnapshot['price'], (int)$onlineCheckoutOrder['shipping_cost'], 'Shipping UX: api checkout shipping cost matches selected rate snapshot');
$_POST = [];

$pdo->exec("UPDATE payment_methods SET active = 0 WHERE code = 'cod'");
$checkoutDisabledCod = PaymentService::selectMethod('cod', 'SK', 'eur', 2799, 'packeta');
assert_true($checkoutDisabledCod === null, 'Payment UX: checkout rejects disabled selected payment');
$pdo->exec("UPDATE payment_methods SET active = 1 WHERE code = 'cod'");

$paymentUxOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Payment UX Customer',
    'email'         => 'payment-ux@example.com',
    'phone'         => '+421900000004',
    'address'       => ['street' => 'Payment UX 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 299,
    'payment_fee'   => (int)$checkoutPaymentCod['fee'],
    'payment_method_snapshot' => json_encode($checkoutPaymentCod, JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'cod');
$paymentUxOrder = Orders::byId($paymentUxOrderId);
$paymentUxSnapshot = json_decode((string)$paymentUxOrder['payment_method_snapshot'], true) ?: [];
assert_equals(120, (int)$paymentUxOrder['payment_fee'], 'Payment UX: order stores selected payment fee');
assert_equals('cod', (string)$paymentUxSnapshot['code'], 'Payment UX: order snapshot stores selected code');
assert_equals('manual', (string)$paymentUxSnapshot['provider'], 'Payment UX: order snapshot stores selected provider');

if (!function_exists('renderOrderDone')) {
    $_SERVER['REQUEST_URI'] = '/?page=order-done&order=' . urlencode((string)$paymentUxOrder['order_number']);
    $_GET = ['page' => 'order-done', 'order' => (string)$paymentUxOrder['order_number']];
    ob_start();
    require_once TEST_CLI_DIR . '/../index.php';
    ob_end_clean();
}
$confirmationOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Payment Confirmation Customer',
    'email'         => 'payment-confirmation@example.com',
    'phone'         => '+421900000104',
    'address'       => ['street' => 'Payment Confirmation 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 299,
    'payment_fee'   => 0,
    'payment_method_snapshot' => json_encode(['code' => 'card', 'provider' => 'mock', 'label' => 'Card'], JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'card');
$confirmationOrder = Orders::byId($confirmationOrderId);
PaymentService::initiate([
    'order_id'       => $confirmationOrder['id'],
    'order_number'   => $confirmationOrder['order_number'],
    'total'          => (int)$confirmationOrder['total'],
    'currency'       => $confirmationOrder['currency'],
    'items'          => Orders::items($confirmationOrder['id']),
], $mockGateway);
$_GET = ['page' => 'order-done', 'order' => (string)$confirmationOrder['order_number']];
$unownedConfirmationHtml = renderOrderDone((string)$confirmationOrder['order_number']);
assert_true(!str_contains($unownedConfirmationHtml, 'payment-summary'), 'Payment UX: unowned confirmation hides payment details');
$confirmationToken = CheckoutSession::createConfirmation($confirmationOrderId, CheckoutSession::browserSessionId());
$_GET['confirmation_token'] = $confirmationToken;
$confirmationHtml = renderOrderDone((string)$confirmationOrder['order_number']);
assert_true(str_contains($confirmationHtml, 'Payment'), 'Payment UX: confirmation renders payment summary');
assert_true(str_contains($confirmationHtml, 'pending'), 'Payment UX: confirmation renders payment status');
assert_true(str_contains($confirmationHtml, 'Card'), 'Payment UX: confirmation renders payment method label');
assert_true(str_contains($confirmationHtml, 'mock_' . $confirmationOrder['order_number']), 'Payment UX: confirmation renders provider reference');

$legacyStripeOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Legacy Stripe Customer',
    'email'         => 'legacy-stripe@example.com',
    'phone'         => '+421900000105',
    'address'       => ['street' => 'Legacy Stripe 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 299,
    'payment_fee'   => 0,
    'payment_method_snapshot' => json_encode(['code' => 'card', 'provider' => 'stripe', 'label' => 'Card'], JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'card');
onPaymentCompleted([
    'id' => 'cs_legacy_paid_1',
    'payment_intent' => 'pi_legacy_paid_1',
    'amount_total' => (int)Orders::byId($legacyStripeOrderId)['total'],
    'metadata' => ['order_id' => $legacyStripeOrderId],
]);
$legacyStripeOrder = Orders::byId($legacyStripeOrderId);
$legacyStripeTxs = PaymentService::transactionsForOrder($legacyStripeOrderId);
$legacyStripeRaw = json_decode((string)$legacyStripeTxs[0]['raw_response'], true) ?: [];
assert_equals('paid', (string)$legacyStripeOrder['payment_status'], 'Payment webhook: legacy Stripe marks payment_status paid');
assert_equals('paid', (string)$legacyStripeTxs[0]['status'], 'Payment webhook: legacy Stripe logs paid transaction');
assert_equals('pi_legacy_paid_1', (string)$legacyStripeTxs[0]['provider_ref'], 'Payment webhook: legacy Stripe logs provider reference');
assert_equals('checkout.session.completed', (string)$legacyStripeRaw['event_type'], 'Payment webhook: legacy Stripe logs event type');
assert_equals('cs_legacy_paid_1', (string)$legacyStripeRaw['session_id'], 'Payment webhook: legacy Stripe logs session reference');

$paymentLifecycleOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Payment Lifecycle Customer',
    'email'         => 'payment-lifecycle@example.com',
    'phone'         => '+421900000005',
    'address'       => ['street' => 'Payment Lifecycle 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 299,
    'payment_fee'   => 0,
    'payment_method_snapshot' => json_encode(['code' => 'card', 'provider' => 'stripe', 'label' => 'Card', 'fee' => 0], JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'card');
$paymentLifecycleBefore = Orders::byId($paymentLifecycleOrderId);
$paymentLifecycleSnapshotBefore = (string)$paymentLifecycleBefore['payment_method_snapshot'];

assert_true(PaymentService::canPaymentTransition('pending', 'authorized'), 'Payment lifecycle: pending can authorize');
assert_true(!PaymentService::canPaymentTransition('pending', 'refunded'), 'Payment lifecycle: pending cannot refund');
$authorized = PaymentService::transitionPayment($paymentLifecycleOrderId, 'authorized', 'payment.authorized', 0, 'pay_lifecycle_auth', ['provider_ref' => 'pi_lifecycle_1']);
assert_true($authorized, 'Payment lifecycle: authorize transition applied');
assert_equals('authorized', Orders::byId($paymentLifecycleOrderId)['payment_status'], 'Payment lifecycle: order payment_status is authorized');
$duplicateAuthorize = PaymentService::transitionPayment($paymentLifecycleOrderId, 'authorized', 'payment.authorized', 0, 'pay_lifecycle_auth', ['provider_ref' => 'pi_lifecycle_1']);
assert_true(!$duplicateAuthorize, 'Payment lifecycle: duplicate status transition is idempotent');
$authEventCount = (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE idempotency_key = 'pay_lifecycle_auth'")->fetchColumn();
assert_equals(1, $authEventCount, 'Payment lifecycle: duplicate idempotency key logs once');

PaymentService::transitionPayment($paymentLifecycleOrderId, 'paid', 'payment.captured', 0, 'pay_lifecycle_capture');
assert_equals('paid', Orders::byId($paymentLifecycleOrderId)['payment_status'], 'Payment lifecycle: paid transition applied');
PaymentService::transitionPayment($paymentLifecycleOrderId, 'refund_pending', 'refund.requested', 500, 'pay_lifecycle_refund_requested');
assert_equals('refund_pending', Orders::byId($paymentLifecycleOrderId)['payment_status'], 'Payment lifecycle: refund_pending transition applied');
PaymentService::transitionPayment($paymentLifecycleOrderId, 'partially_refunded', 'refund.partial_succeeded', 500, 'pay_lifecycle_refund_partial');
assert_equals('partially_refunded', Orders::byId($paymentLifecycleOrderId)['payment_status'], 'Payment lifecycle: partial refund transition applied');
$invalidPaymentTransitionThrown = false;
try {
    PaymentService::transitionPayment($paymentLifecycleOrderId, 'authorized', 'payment.reauthorized', 0, 'pay_lifecycle_invalid');
} catch (\LogicException $e) {
    $invalidPaymentTransitionThrown = true;
}
assert_true($invalidPaymentTransitionThrown, 'Payment lifecycle: invalid transition throws');
assert_equals($paymentLifecycleSnapshotBefore, (string)Orders::byId($paymentLifecycleOrderId)['payment_method_snapshot'], 'Payment lifecycle: order payment snapshot remains unchanged');
$lifecycleEvents = PaymentService::transactionsForOrder($paymentLifecycleOrderId);
$lifecycleStatuses = array_column($lifecycleEvents, 'status');
assert_true(in_array('authorized', $lifecycleStatuses, true), 'Payment lifecycle: authorized event logged');
assert_true(in_array('refund_pending', $lifecycleStatuses, true), 'Payment lifecycle: refund request event logged');
assert_true(in_array('partially_refunded', $lifecycleStatuses, true), 'Payment lifecycle: partial refund event logged');
assert_equals(['refund_pending', 'refunded', 'disputed'], PaymentService::allowedPaymentTransitions('partially_refunded'), 'Payment admin: exposes allowed transitions for current payment status');
$adminTransitionOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Payment Admin Customer',
    'email'         => 'payment-admin@example.com',
    'phone'         => '+421900000006',
    'address'       => ['street' => 'Payment Admin 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 299,
    'payment_fee'   => 0,
    'payment_method_snapshot' => json_encode(['code' => 'bank_transfer', 'provider' => 'manual', 'label' => 'Bank transfer', 'fee' => 0], JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'bank_transfer');
PaymentService::transitionPayment($adminTransitionOrderId, 'paid', 'admin.paid', 0, 'pay_admin_mark_paid', ['actor' => 'test-admin', 'note' => 'Manual paid']);
$adminTxs = PaymentService::transactionsForOrder($adminTransitionOrderId);
$adminTxRaw = json_decode((string)end($adminTxs)['raw_response'], true) ?: [];
assert_equals('paid', Orders::byId($adminTransitionOrderId)['payment_status'], 'Payment admin: manual transition updates payment status');
assert_equals('admin.paid', (string)$adminTxRaw['event_type'], 'Payment admin: manual transition logs event type');
assert_equals('Manual paid', (string)$adminTxRaw['note'], 'Payment admin: manual transition logs note');
PaymentService::transitionPayment($adminTransitionOrderId, 'refund_pending', 'refund.requested', 750, 'pay_admin_refund_request', [
    'actor' => 'test-admin',
    'amount' => 750,
    'reason' => 'Customer return',
]);
$adminRefundTxs = PaymentService::transactionsForOrder($adminTransitionOrderId);
$adminRefundRaw = json_decode((string)end($adminRefundTxs)['raw_response'], true) ?: [];
assert_equals('refund_pending', Orders::byId($adminTransitionOrderId)['payment_status'], 'Payment admin: refund request updates payment status');
assert_equals('refund.requested', (string)$adminRefundRaw['event_type'], 'Payment admin: refund request logs event type');
assert_equals(750, (int)$adminRefundRaw['amount'], 'Payment admin: refund request logs amount');
assert_equals('Customer return', (string)$adminRefundRaw['reason'], 'Payment admin: refund request logs reason');
assert_equals('test-admin', (string)$adminRefundRaw['actor'], 'Payment admin: refund request logs admin actor');

$adminOrdersListSnippet = entrypointSource('admin.php');
assert_true(str_contains($adminOrdersListSnippet, '<th>Payment</th>'), 'Payment admin: orders list renders Payment column');
assert_true(str_contains($adminOrdersListSnippet, 'badge-<?= esc($o[\'payment_status\'] ?? \'pending\') ?>'), 'Payment admin: orders list renders payment status badge');
assert_true(str_contains($adminOrdersListSnippet, 'esc($o[\'payment_method\'] ?? \'\')'), 'Payment admin: orders list renders payment method');
assert_true(str_contains($adminOrdersListSnippet, 'name="payment_status"'), 'Payment admin: orders list renders payment status filter');
assert_true(str_contains($adminOrdersListSnippet, 'AND payment_status = :payment_status'), 'Payment admin: orders list filters by payment status');
assert_true(str_contains($adminOrdersListSnippet, 'Payment method:'), 'Payment admin: order detail renders payment method snapshot');
assert_true(str_contains($adminOrdersListSnippet, 'Payment reference:'), 'Payment admin: order detail renders payment reference');
assert_true(str_contains($adminOrdersListSnippet, 'Last event:'), 'Payment admin: order detail renders latest payment event');
assert_true(str_contains($adminOrdersListSnippet, '<th>Details</th>'), 'Payment admin: timeline renders transaction details column');
assert_true(str_contains($adminOrdersListSnippet, "foreach (['reason', 'actor', 'refund_id', 'session_id', 'payment_id']"), 'Payment admin: timeline renders refund/session payload details');
assert_true(str_contains($adminOrdersListSnippet, 'Refund unavailable for current payment status.'), 'Payment admin: order detail explains refund ineligible state');
assert_true(in_array('refund_pending', PaymentService::paymentStatuses(), true), 'Payment admin: payment status filter includes refund_pending');

$accountOrdersSnippet = file_get_contents(TEST_CLI_DIR . '/../account.php') ?: '';
assert_true(str_contains($accountOrdersSnippet, '$paymentRef = $tx[\'provider_ref\']'), 'Payment account: order payment history includes provider reference');

// ============================================================
// SHIPPING TESTS
// ============================================================

echo "\n--- Test: Shipping — PersonalPickup always free ---\n";
$pickup = new PersonalPickup();
assert_equals('pickup', $pickup->carrierId(), 'Shipping: PersonalPickup carrierId');
assert_true(!$pickup->supportsCOD(), 'Shipping: PersonalPickup does not support COD');
$pickupRates = $pickup->getRates(['country' => 'SK', 'weight_g' => 5000, 'subtotal' => 100]);
assert_true(!empty($pickupRates), 'Shipping: PersonalPickup returns rate');
assert_equals(0, $pickupRates[0]['price'], 'Shipping: PersonalPickup price is 0');

echo "\n--- Test: Shipping — Packeta tiered pricing (DB rates) ---\n";
$packeta = new PacketaShipping();
assert_equals('packeta', $packeta->carrierId(), 'Shipping: PacketaShipping carrierId');
assert_true($packeta->supportsCOD(), 'Shipping: Packeta supports COD');

// Weight tier 0-2000g: 299 cents
$r1 = $packeta->getRates(['country' => 'SK', 'weight_g' => 1500, 'subtotal' => 2000, 'cod' => false]);
assert_true(!empty($r1), 'Shipping: Packeta SK 1500g returns rate');
assert_equals(299, $r1[0]['price'], 'Shipping: Packeta SK 1500g = 299 cents');

// Weight tier 2001-5000g: 349 cents
$r2 = $packeta->getRates(['country' => 'SK', 'weight_g' => 3000, 'subtotal' => 2000, 'cod' => false]);
assert_equals(349, $r2[0]['price'], 'Shipping: Packeta SK 3000g = 349 cents');

// COD surcharge
$r3 = $packeta->getRates(['country' => 'SK', 'weight_g' => 1000, 'subtotal' => 2000, 'cod' => true]);
assert_equals(100, $r3[0]['cod_surcharge'], 'Shipping: Packeta SK COD surcharge = 100 cents');

echo "\n--- Test: Shipping — free_above threshold ---\n";
// Packeta free threshold default = 5000 cents (50 EUR)
$rFree = $packeta->getRates(['country' => 'SK', 'weight_g' => 2000, 'subtotal' => 5000, 'cod' => false]);
assert_equals(0, $rFree[0]['price'], 'Shipping: Packeta free when subtotal >= 5000');
assert_equals(0, $rFree[0]['cod_surcharge'], 'Shipping: Packeta free COD surcharge = 0 when free');

echo "\n--- Test: Shipping — GLS EU pricing ---\n";
$gls = new GlsShipping();
assert_equals('gls', $gls->carrierId(), 'Shipping: GlsShipping carrierId');
assert_true($gls->supportsCOD(), 'Shipping: GLS supports COD');

$rGls = $gls->getRates(['country' => 'SK', 'weight_g' => 1000, 'subtotal' => 1000, 'cod' => false]);
assert_true(!empty($rGls), 'Shipping: GLS SK returns rate');
assert_equals(399, $rGls[0]['price'], 'Shipping: GLS SK 1000g = 399 cents');

$rGlsDe = $gls->getRates(['country' => 'DE', 'weight_g' => 3000, 'subtotal' => 1000, 'cod' => false]);
assert_true(!empty($rGlsDe), 'Shipping: GLS DE returns rate');
assert_equals(899, $rGlsDe[0]['price'], 'Shipping: GLS DE 3000g = 899 cents');

// GLS should not service unknown countries
$rGlsUnknown = $gls->getRates(['country' => 'US', 'weight_g' => 1000, 'subtotal' => 1000]);
assert_true(empty($rGlsUnknown), 'Shipping: GLS does not service US');

echo "\n--- Test: Shipping — default seed pricing engine ---\n";
$methodCount = (int)$pdo->query("SELECT COUNT(*) FROM shipping_methods WHERE code IN ('pickup', 'packeta', 'gls')")->fetchColumn();
assert_equals(3, $methodCount, 'Shipping: default seed creates pickup, packeta and gls methods');
$zoneCount = (int)$pdo->query("SELECT COUNT(*) FROM shipping_zones WHERE code IN ('domestic', 'cz', 'near_eu', 'eu')")->fetchColumn();
assert_equals(4, $zoneCount, 'Shipping: default seed creates usable zones');

$shippingMig->up($pdo, true);
$methodCount2 = (int)$pdo->query("SELECT COUNT(*) FROM shipping_methods WHERE code IN ('pickup', 'packeta', 'gls')")->fetchColumn();
assert_equals(3, $methodCount2, 'Shipping: seed is idempotent for methods');

$pdo->exec("UPDATE shipping_methods SET name = 'Admin custom pickup' WHERE code = 'pickup'");
$shippingMig->up($pdo, true);
$customPickup = (string)$pdo->query("SELECT name FROM shipping_methods WHERE code = 'pickup'")->fetchColumn();
assert_equals('Admin custom pickup', $customPickup, 'Shipping: seed does not overwrite admin-edited method');

$pdo->exec("UPDATE shipping_methods SET active = 0 WHERE code = 'packeta'");
$disabledPacketa = ShippingService::selectRate('packeta', 'SK', 2000, 2000, false, 0);
assert_true($disabledPacketa === null, 'Shipping: disabled admin method is not selectable');
$pdo->exec("UPDATE shipping_methods SET active = 1 WHERE code = 'packeta'");

$dbRatesSk = ShippingService::calculateRates('SK', 2000, 2000);
$dbCarriersSk = array_column($dbRatesSk, 'carrier');
assert_true(count($dbRatesSk) >= 3, 'Shipping: seeded pricing returns rates for SK checkout');
assert_true(in_array('pickup', $dbCarriersSk), 'Shipping: seeded pricing includes pickup');
assert_true(in_array('packeta', $dbCarriersSk), 'Shipping: seeded pricing includes packeta');
assert_true(in_array('gls', $dbCarriersSk), 'Shipping: seeded pricing includes gls');

$glsEu = ShippingService::selectRate('gls', 'FR', 6000, 2000, false, 0);
assert_true($glsEu !== null, 'Shipping: seeded EU fallback rate returns GLS for FR');
assert_equals(1599, (int)$glsEu['price'], 'Shipping: seeded EU fallback 5-20kg price = 1599 cents');
$glsEuMissingSeedCountry = ShippingService::selectRate('gls', 'BG', 6000, 2000, false, 0);
assert_true($glsEuMissingSeedCountry !== null, 'Shipping: seeded EU fallback covers all EU countries');
assert_equals(1599, (int)$glsEuMissingSeedCountry['price'], 'Shipping: seeded EU fallback applies to BG 5-20kg');

$invalidCountryRate = ShippingService::selectRate('packeta', 'US', 2000, 2000, false, 0);
assert_true($invalidCountryRate === null, 'Shipping: invalid country/method combination returns no rate');
$legacyInternational = ShippingService::selectRate('standard', 'US', 2000, 2000, false, 0);
assert_true($legacyInternational !== null, 'Shipping: country without DB zone falls back to legacy international rate');
assert_equals(1999, (int)$legacyInternational['price'], 'Shipping: legacy international fallback computes standard price');

$freePacketa = ShippingService::selectRate('packeta', 'SK', 2000, 5000, false, 0);
assert_true($freePacketa !== null, 'Shipping: Packeta resolves at free shipping threshold');
assert_equals(0, (int)$freePacketa['price'], 'Shipping: method free_above threshold zeroes price');

$selectedSeedRate = ShippingService::selectRate('packeta', 'SK', 2000, 2000, false, 0);
assert_true($selectedSeedRate !== null, 'Shipping: seeded Packeta rate selectable');

$_SESSION = ['cart' => []];
Cart::add((int)$payProduct['id'], null, 1);
$shippingCart = Cart::enriched();
$shippingMetrics = ['weight_g' => 2000, 'volume_cm3' => 0];
$checkoutRates = ShippingService::calculateRates('SK', $shippingMetrics['weight_g'], (int)$shippingCart['subtotal'], false, $shippingMetrics['volume_cm3']);
assert_true(count($checkoutRates) >= 3, 'Shipping UX: checkout SK quote returns available methods from cart metrics');
$checkoutSelection = ShippingService::selectRate('packeta', 'SK', $shippingMetrics['weight_g'], (int)$shippingCart['subtotal'], false, $shippingMetrics['volume_cm3']);
assert_true($checkoutSelection !== null, 'Shipping UX: checkout selected Packeta method resolves against current cart');

$seedOrderId = Orders::create([
    [
        'product_id'   => $payProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$payProduct['price'],
        'sku'          => $payProduct['sku'],
        'name'         => $payProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Ship Seed Customer',
    'email'         => 'ship-seed@example.com',
    'phone'         => '+421900000002',
    'address'       => ['street' => 'Seed 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => (int)$selectedSeedRate['price'],
    'shipping_method' => $selectedSeedRate['method_code'],
    'shipping_zone' => $selectedSeedRate['zone_code'],
    'shipping_weight_g' => $shippingMetrics['weight_g'],
    'shipping_rate_snapshot' => json_encode($selectedSeedRate, JSON_UNESCAPED_UNICODE),
    'tax_amount'    => 0,
], 'card');
$seedOrder = Orders::byId($seedOrderId);
assert_equals('packeta', (string)$seedOrder['shipping_method'], 'Shipping: order snapshot stores method');
assert_equals('domestic', (string)$seedOrder['shipping_zone'], 'Shipping: order snapshot stores zone');
assert_equals((int)$shippingMetrics['weight_g'], (int)$seedOrder['shipping_weight_g'], 'Shipping: order snapshot stores weight');
$seedSnapshot = json_decode((string)$seedOrder['shipping_rate_snapshot'], true) ?: [];
assert_equals('packeta', (string)$seedSnapshot['method_code'], 'Shipping: order snapshot stores selected rate JSON');
assert_equals((int)$checkoutSelection['price'], (int)$seedOrder['shipping_cost'], 'Shipping UX: order stores selected checkout shipping price');
assert_equals((int)$shippingMetrics['weight_g'], (int)$seedOrder['shipping_weight_g'], 'Shipping UX: order snapshot weight matches checkout cart metrics');

// Re-seed again after snapshot checks so later adapter DB lookups still use default carrier rows.
$shippingMig->up($pdo, true);

echo "\n--- Test: Shipping — calculateRates aggregation ---\n";
ShippingService::setAdapters([new PersonalPickup(), new PacketaShipping(), new GlsShipping()]);
$allRates = ShippingService::calculateRates('SK', 2000, 2000);
assert_true(count($allRates) >= 3, 'Shipping: calculateRates returns rates from all 3 carriers for SK');

$carriers = array_column($allRates, 'carrier');
assert_true(in_array('pickup', $carriers), 'Shipping: calculateRates includes pickup');
assert_true(in_array('packeta', $carriers), 'Shipping: calculateRates includes packeta');
assert_true(in_array('gls', $carriers), 'Shipping: calculateRates includes gls');

echo "\n--- Test: Shipping — generateLabel + tracking ---\n";
// Use existing order from earlier tests
$shippingOrderId = $payOrderId; // reuse payment test order
$labelResult = ShippingService::generateLabel($shippingOrderId, 'packeta');
assert_true(!empty($labelResult['awb']), 'Shipping: generateLabel returns AWB');
assert_true(str_starts_with($labelResult['awb'], 'PKT'), 'Shipping: Packeta AWB starts with PKT');
assert_equals('packeta', $labelResult['carrier'], 'Shipping: label carrier is packeta');

// Verify label persisted in DB
$labelRow = $pdo->query("SELECT * FROM shipping_labels WHERE awb = '{$labelResult['awb']}'")->fetch();
assert_true($labelRow !== false, 'Shipping: label saved in shipping_labels table');
assert_equals('created', $labelRow['status'], 'Shipping: label status = created');
assert_equals('default', $labelRow['store_id'], 'Shipping: label stores active store ownership');
StoreContext::activate($deContext);
$crossStoreLabelRejected = false;
try { ShippingService::generateLabel($shippingOrderId, 'packeta'); } catch (\RuntimeException $e) { $crossStoreLabelRejected = true; }
assert_true($crossStoreLabelRejected, 'Store isolation: second store cannot generate a label for default order');
Bootstrap::initStoreContext('/', '', 'default');

// Verify order updated with tracking_number + carrier
$updatedOrder = $pdo->query("SELECT tracking_number, carrier FROM orders WHERE id = {$shippingOrderId}")->fetch();
assert_equals($labelResult['awb'], $updatedOrder['tracking_number'], 'Shipping: order tracking_number updated');
assert_equals('packeta', $updatedOrder['carrier'], 'Shipping: order carrier updated');

echo "\n--- Test: Shipping — tracking webhook → delivered ---\n";
// Ship the order first (workflow: paid→shipped→delivered)
OrderWorkflow::transition($shippingOrderId, 'shipped');

$webhookPayload = [
    'awb'    => $labelResult['awb'],
    'status' => 'delivered',
];
$shippingWebhookSecret = 'test-shipping-webhook-secret';
putenv('SHIP_PACKETA_WEBHOOK_SECRET=' . $shippingWebhookSecret);
$webhookRaw = json_encode($webhookPayload, JSON_UNESCAPED_SLASHES);
$webhookSignature = hash_hmac('sha256', $webhookRaw, $shippingWebhookSecret);
StoreContext::activate($deContext);
$crossStoreWebhook = ShippingService::handleTrackingWebhook('packeta', $webhookPayload, $webhookSignature, $webhookRaw);
assert_equals('unknown_awb', $crossStoreWebhook['reason'] ?? null, 'Store isolation: second store webhook cannot resolve default shipping label');
Bootstrap::initStoreContext('/', '', 'default');
$webhookResult = ShippingService::handleTrackingWebhook('packeta', $webhookPayload, $webhookSignature, $webhookRaw);
assert_true($webhookResult['handled'], 'Shipping: tracking webhook handled=true');
assert_equals('delivered', $webhookResult['status'], 'Shipping: webhook status=delivered');
assert_equals($shippingOrderId, $webhookResult['order_id'], 'Shipping: webhook order_id matches');
$shippingReplay = ShippingService::handleTrackingWebhook('packeta', $webhookPayload, $webhookSignature, $webhookRaw);
assert_true($shippingReplay['duplicate'] ?? false, 'G3: shipping webhook replay is idempotent');

// Verify label status updated
$deliveredLabel = $pdo->query("SELECT status FROM shipping_labels WHERE awb = '{$labelResult['awb']}'")->fetch();
assert_equals('delivered', $deliveredLabel['status'], 'Shipping: label status updated to delivered after webhook');

// Verify order transitioned to delivered
$deliveredOrder = $pdo->query("SELECT status FROM orders WHERE id = {$shippingOrderId}")->fetch();
assert_equals('delivered', $deliveredOrder['status'], 'Shipping: order status = delivered after webhook');

echo "\n--- Test: Shipping — bad webhook signature rejected ---\n";
$badPayload = ['awb' => 'PKT-BAD', 'status' => 'in_transit'];
$badRaw = json_encode($badPayload, JSON_UNESCAPED_SLASHES);
$exceptionThrown = false;
try {
    ShippingService::handleTrackingWebhook('packeta', $badPayload, 'bad-sig', $badRaw);
} catch (\RuntimeException $e) {
    $exceptionThrown = str_contains($e->getMessage(), 'signature');
}
assert_true($exceptionThrown, 'Shipping: bad signature throws RuntimeException');
putenv('SHIP_PACKETA_WEBHOOK_SECRET');
$exceptionThrown = false;
try {
    ShippingService::handleTrackingWebhook('packeta', $badPayload, hash_hmac('sha256', $badRaw, $shippingWebhookSecret), $badRaw);
} catch (\RuntimeException $e) {
    $exceptionThrown = str_contains($e->getMessage(), 'not configured');
}
assert_true($exceptionThrown, 'G3: shipping webhook without configured secret fails closed');

// ============================================================
// TESTS: Email Notifications (MailService + adapters + templates)
// ============================================================
echo "\n--- Test: Email — email_log table setup ---\n";
$pdo->exec("CREATE TABLE IF NOT EXISTS email_log (
    id         INTEGER PRIMARY KEY,
    order_id   INTEGER DEFAULT NULL,
    recipient  TEXT NOT NULL,
    template   TEXT NOT NULL,
    locale     TEXT NOT NULL DEFAULT 'sk',
    subject    TEXT NOT NULL DEFAULT '',
    adapter    TEXT NOT NULL DEFAULT '',
    status     TEXT NOT NULL DEFAULT 'pending',
    error      TEXT DEFAULT '',
    payload    TEXT DEFAULT '{}',
    store_id   TEXT NOT NULL DEFAULT 'default',
    correlation_id TEXT DEFAULT NULL,
    sent_at    TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now'))
)");
// Add locale to orders if missing (test DB was set up without it)
try { $pdo->exec("ALTER TABLE orders ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'"); }
catch (\PDOException $e) { /* already exists */ }

$emailLogCount = (int)$pdo->query("SELECT COUNT(*) FROM email_log")->fetchColumn();
assert_equals(0, $emailLogCount, 'Email: email_log table empty before tests');

echo "\n--- Test: Email — render template with locale ---\n";
$renderedSk = MailService::render('order_confirmation', 'sk', [
    'order_number' => 'TST-001',
    'order_status' => 'paid',
    'order_total'  => '10,00 €',
    'customer_name'=> 'Jožko',
    'items'        => [['name' => 'Tričko', 'sku' => 'SH-1', 'quantity' => 2, 'total' => '10,00 €']],
]);
assert_true(str_contains($renderedSk['subject'], 'TST-001'), 'Email: SK subject contains order number');
assert_true(str_contains($renderedSk['html'], 'Ďakujeme'), 'Email: SK HTML contains Ďakujeme');
assert_true(str_contains($renderedSk['html'], 'Tričko'), 'Email: SK HTML expands {{#each items}}');
assert_true(!str_contains($renderedSk['html'], '{{'), 'Email: SK HTML has no unresolved placeholders');

$renderedEn = MailService::render('order_confirmation', 'en', [
    'order_number' => 'TST-002',
    'order_status' => 'paid',
    'customer_name'=> 'John',
    'items'        => [],
]);
assert_true(str_contains($renderedEn['html'], 'Thanks for your order'), 'Email: EN template used when locale=en');

echo "\n--- Test: Email — locale fallback to en then sk ---\n";
$renderedXx = MailService::render('order_confirmation', 'fr', [
    'order_number' => 'TST-003',
    'customer_name'=> 'Pierre',
    'items'        => [],
]);
// fr has no templates → must fall back to en (first in fallback chain)
assert_true(str_contains($renderedXx['html'], 'Thanks for your order'), 'Email: fr locale falls back to en');
assert_true(str_contains($renderedXx['subject'], 'TST-003'), 'Email: fallback still renders variables');

$renderedNone = MailService::render('__nope__', 'sk', ['x' => 'y']);
assert_true(str_contains($renderedNone['html'], '__nope__'), 'Email: unknown template returns placeholder HTML');

echo "\n--- Test: Email — renderString edge cases ---\n";
$esc = MailService::renderString('{{a}}', ['a' => '<b>hi</b>']);
assert_equals('&lt;b&gt;hi&lt;/b&gt;', $esc, 'Email: renderString HTML-escapes scalar values');
$ifTpl = MailService::renderString('{{#if x}}YES{{/if}}{{#if y}}NO{{/if}}', ['x' => 1, 'y' => 0]);
assert_equals('YES', $ifTpl, 'Email: {{#if}} includes truthy, excludes falsy');

echo "\n--- Test: Email — LogMail adapter captures message ---\n";
LogMail::clearCaptured();
MailService::useAdapter(new LogMail(['log_file' => TEST_CLI_DIR . '/../db/emails.test.log']));
$originalMailFrom = getenv('MAIL_FROM');
$originalServerName = $_SERVER['SERVER_NAME'] ?? null;
putenv('MAIL_FROM=');
$_SERVER['SERVER_NAME'] = "attacker.example\r\nBcc: victim@example.com";
$res = MailService::send('order_paid', 'jozko@example.com', 'sk', [
    'order_number' => 'LOG-1',
    'customer_name'=> 'Jožko',
    'order_total'  => '99,00 €',
]);
assert_equals('sent', $res['status'], 'Email: LogMail send returns status=sent');
assert_equals('log', $res['adapter'], 'Email: LogMail reports adapter=log');
$last = LogMail::lastCaptured();
assert_true($last !== null, 'Email: LogMail captured message');
assert_equals('jozko@example.com', $last['to'] ?? '', 'Email: LogMail captured recipient');
assert_equals('noreply@localhost.invalid', $last['from'] ?? '', 'G4: mail sender ignores poisoned SERVER_NAME');
assert_true(str_contains($last['html'] ?? '', 'LOG-1'), 'Email: LogMail rendered order_number into HTML');
$originalMailFrom === false ? putenv('MAIL_FROM') : putenv('MAIL_FROM=' . $originalMailFrom);
if ($originalServerName === null) {
    unset($_SERVER['SERVER_NAME']);
} else {
    $_SERVER['SERVER_NAME'] = $originalServerName;
}

echo "\n--- Test: Email — email_log row persisted with status=sent ---\n";
$logId = (int)$res['log_id'];
assert_greater($logId, 0, 'Email: log_id returned');
$row = $pdo->query("SELECT * FROM email_log WHERE id = {$logId}")->fetch();
assert_equals('sent',         $row['status'],   'Email: email_log.status = sent');
assert_equals('order_paid',   $row['template'], 'Email: email_log.template = order_paid');
assert_equals('sk',           $row['locale'],   'Email: email_log.locale = sk');
assert_equals('log',          $row['adapter'],  'Email: email_log.adapter = log');
assert_true(!empty($row['sent_at']),            'Email: email_log.sent_at populated');

echo "\n--- Test: Email — invalid recipient is skipped ---\n";
$skip = MailService::send('order_paid', 'not-an-email', 'sk', ['order_number' => 'X']);
assert_equals('skipped', $skip['status'], 'Email: invalid address returns status=skipped');
assert_equals(0, $skip['log_id'], 'Email: invalid address does not create log row');
$badTemplate = MailService::send('../order_paid', 'safe@example.com', 'en');
assert_equals('skipped', $badTemplate['status'], 'G4: unsafe mail template identifier is rejected');
for ($i = 0; $i < 3; $i++) {
    $resetMail = MailService::send('account_password_reset', 'flood-target@example.com', 'en', ['reset_url' => 'https://shop.example/reset']);
    assert_equals('sent', $resetMail['status'], 'G4: reset mail within recipient budget is sent');
}
$blockedResetMail = MailService::send('account_password_reset', 'flood-target@example.com', 'en', ['reset_url' => 'https://shop.example/reset']);
assert_equals('skipped', $blockedResetMail['status'], 'G4: fourth reset mail to one recipient is throttled');
assert_equals('recipient rate limit', $blockedResetMail['error'], 'G4: recipient throttle returns a stable internal reason');

echo "\n--- Test: Email — SmtpMail rejects bad addresses without connecting ---\n";
$smtp = new SmtpMail(['smtp_host' => '127.0.0.1', 'smtp_port' => 1]);
$smtpBad = $smtp->send(['to' => 'bad', 'from' => 'also-bad', 'subject' => 't', 'html' => 'h', 'text' => 'h']);
assert_equals('failed',            $smtpBad['status'],  'Email: SmtpMail rejects invalid addresses');
assert_equals('invalid recipient', $smtpBad['message'], 'Email: SmtpMail error message identifies recipient');

echo "\n--- Test: Security hardening — SMTP CRLF strip + XFF rightmost-trusted (P4) ---\n";
// #14 SMTP header injection: CR/LF stripped from header values
$crlfM = new ReflectionMethod('SmtpMail', 'stripCrlf');
assert_equals('evilHeader', $crlfM->invoke(null, "evil\r\nHeader"), 'Security: stripCrlf removes CRLF from header value');
assert_equals('abc',        $crlfM->invoke(null, "a\rb\nc"),        'Security: stripCrlf removes bare CR and LF');

// #13 clientIp XFF anti-spoof: rightmost non-trusted address wins
$cfgProp = new ReflectionProperty('Security', 'cfg');
$origCfg = $cfgProp->getValue();
$origServer = $_SERVER;
$cfgProp->setValue(null, ['trusted_proxies' => ['10.0.0.1']]);
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 203.0.113.9, 10.0.0.1';
assert_equals('203.0.113.9', Security::clientIp(), 'Security: clientIp picks rightmost non-trusted XFF address');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
assert_equals('203.0.113.9', Security::clientIp(), 'Security: clientIp returns sole forwarded address');
$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1';
assert_equals('8.8.8.8', Security::clientIp(), 'Security: clientIp ignores XFF when remote is not a trusted proxy');
$_SERVER = $origServer;
$cfgProp->setValue(null, $origCfg);

echo "\n--- Test: Email — SesMail without credentials fails cleanly ---\n";
putenv('MAIL_SES_KEY=');
putenv('MAIL_SES_SECRET=');
$ses = new SesMail();
$sesRes = $ses->send(['to' => 'x@example.com', 'from' => 'y@example.com', 'subject' => 's', 'text' => 't']);
assert_equals('failed', $sesRes['status'], 'Email: SesMail without credentials fails');
assert_true(str_contains($sesRes['message'], 'credentials'), 'Email: SesMail error explains missing credentials');

echo "\n--- Test: Email — MailService::resend creates new log row ---\n";
$resend = MailService::resend($logId);
assert_equals('sent', $resend['status'], 'Email: resend returns status=sent');
assert_greater($resend['log_id'], $logId, 'Email: resend creates NEW log row (not amends original)');
$orig = $pdo->query("SELECT status FROM email_log WHERE id = {$logId}")->fetch();
assert_equals('sent', $orig['status'], 'Email: resend leaves original log row intact');

$resendBad = MailService::resend(999999);
assert_equals('skipped', $resendBad['status'], 'Email: resend on missing id returns skipped');
$resendWrongOrder = MailService::resend($logId, 999999);
assert_equals('skipped', $resendWrongOrder['status'], 'G4: resend cannot use an email log from another order');

echo "\n--- Test: Email — worker handleEmailSend end-to-end via OrderWorkflow ---\n";
LogMail::clearCaptured();
MailService::useAdapter(new LogMail(['log_file' => TEST_CLI_DIR . '/../db/emails.test.log']));

// Create an order ready to be paid → triggers order_paid email
$emProduct = Products::bySku('TSHIRT-BLK-M');
$emOrderId = Orders::create([
    [
        'product_id'   => $emProduct['id'],
        'variant_id'   => null,
        'qty'          => 1,
        'price'        => (int)$emProduct['price'],
        'sku'          => $emProduct['sku'],
        'name'         => $emProduct['name'],
        'variant_name' => '',
    ],
], [
    'name'          => 'Email Customer',
    'email'         => 'customer@example.com',
    'phone'         => '+421900000099',
    'address'       => ['street' => 'Email 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
    'currency'      => 'eur',
    'shipping_cost' => 0,
    'payment_method'=> 'transfer',
]);
// mark locale = en on this order
$pdo->prepare("UPDATE orders SET locale = 'en' WHERE id = :id")->execute([':id' => $emOrderId]);

OrderWorkflow::transition($emOrderId, 'paid', 'test email trigger');
// The transition pushes a job; find the one matching our order
$pending = JobQueue::pull(200);
$emailJob = null;
foreach ($pending as $j) {
    if (($j['type'] ?? '') === 'email_send' && ($j['payload']['order_id'] ?? null) == $emOrderId) {
        $emailJob = $j;
        break;
    }
}
assert_true($emailJob !== null, 'Email: OrderWorkflow enqueued email_send job on paid transition');

// Inline-execute (worker handler code path) with a forged payload recipient.
require_once TEST_CLI_DIR . '/../cli/worker.php';
LogMail::clearCaptured();
handleEmailSend(['template' => 'order_paid', 'to' => 'attacker@example.com'], []);
assert_true(LogMail::lastCaptured() === null, 'G4: worker rejects non-cart mail jobs without an order');
$emailJob['payload']['to'] = 'attacker@example.com';
handleEmailSend($emailJob['payload'], $emailJob);

$lastMail = LogMail::lastCaptured();
assert_true($lastMail !== null, 'Email: worker dispatch captured by LogMail');
assert_equals('customer@example.com', $lastMail['to'] ?? '', 'G4: worker ignores forged recipient and uses order customer_email');
assert_true(str_contains($lastMail['html'] ?? '', 'Payment received'), 'Email: worker rendered EN order_paid (locale from order row)');

$orderEmailRow = $pdo->prepare("SELECT * FROM email_log WHERE order_id = :oid AND template = 'order_paid' ORDER BY id DESC LIMIT 1");
$orderEmailRow->execute([':oid' => $emOrderId]);
$orderEmail = $orderEmailRow->fetch();
assert_true($orderEmail !== false, 'Email: email_log row linked to order_id');
assert_equals('en', $orderEmail['locale'], 'Email: worker propagated order.locale to email_log');

// Reset adapter override for downstream safety
MailService::useAdapter(null);
