<?php

// Review round 34 (medium 2): the checkout previews check a VAT ID in VIES within a per-client budget (20 per
// 10 minutes), but running out of it went through Security::rateLimit, which answers the whole request with HTTP 429.
// About ten changes of a business's details failed the shipping and payment previews, the JS checkout could not be
// finished, and every buyer behind the same office IP shared the block. Cached answers counted against it too.
echo "\n--- Audit round 34: the VAT ID preview budget never fails the preview ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
if (!function_exists('apiShippingRates')) require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';
$r34lCart = $_SESSION['cart'] ?? null;
$r34lGet = $_GET;
try {
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99351, 'default', 1, 'R34L book', 'r34l-book', 'R34L-BOOK', 'simple', 1230, 'eur', 'active', 10, 'continue')");
    $pdo->prepare("DELETE FROM vat_id_cache WHERE vat_id IN ('DE811907980', 'DE934343434')")->execute();
    $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES ('DE811907980', 'DE', 'valid', 'R34L GmbH', :at, 86400)")
        ->execute([':at' => gmdate('Y-m-d H:i:s')]);
    $_SESSION['cart'] = ['p_99351' => ['product_id' => 99351, 'variant_id' => null, 'qty' => 1]];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $r34lPreview = static function (string $vatId): array {
        http_response_code(200);
        $_GET = ['country' => 'DE', 'subtotal' => '1230', 'vat_id' => $vatId];
        $rates = apiShippingRates();
        $code = http_response_code();
        $_GET = ['country' => 'DE', 'currency' => 'eur', 'amount' => '1230', 'shipping_method' => 'dpd', 'vat_id' => $vatId];
        $payments = apiPaymentMethods();
        return ['code' => [$code, http_response_code()], 'scenario' => (string)($rates['vat_scenario'] ?? ''), 'error' => isset($rates['error']) || isset($payments['error'])];
    };

    // A cached answer costs nothing: 30 previews of the same valid VAT ID stay reverse charge and leave the budget whole.
    for ($r34lI = 0; $r34lI < 30; $r34lI++) $r34lCached = $r34lPreview('DE811907980');
    assert_equals([[200, 200], VatService::SCENARIO_REVERSE_CHARGE, false, true],
        [$r34lCached['code'], $r34lCached['scenario'], $r34lCached['error'], Security::rateLimitAllows('checkout_vat_preview', 1, 600)],
        'R34 VIES budget: a cached VAT ID answer is not counted against the preview budget');

    // The budget used up: an unknown VAT ID counts as unverified, but the previews answer normally (was HTTP 429).
    for ($r34lI = 0; $r34lI < 20; $r34lI++) Security::rateLimitRecord('checkout_vat_preview');
    $r34lSpent = $r34lPreview('DE934343434');
    assert_equals([[200, 200], false, false], [$r34lSpent['code'], $r34lSpent['error'], VatService::cachedVatIdStatus('DE934343434') !== null],
        'R34 VIES budget: over the budget the shipping and payment previews still answer, without asking VIES (was 429)');
    assert_true($r34lSpent['scenario'] !== '' && $r34lSpent['scenario'] !== VatService::SCENARIO_REVERSE_CHARGE,
        'R34 VIES budget: an unchecked VAT ID is priced as unverified (' . $r34lSpent['scenario'] . ')');
    // The known VAT ID keeps working while the budget is spent.
    assert_equals([[200, 200], VatService::SCENARIO_REVERSE_CHARGE], [$r34lPreview('DE811907980')['code'], $r34lPreview('DE811907980')['scenario']],
        'R34 VIES budget: a cached valid VAT ID stays reverse charge while the budget is spent');
} catch (Throwable $e) {
    assert_true(false, 'R34 VIES budget (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
http_response_code(200);
$_GET = $r34lGet;
if ($r34lCart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r34lCart;
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
