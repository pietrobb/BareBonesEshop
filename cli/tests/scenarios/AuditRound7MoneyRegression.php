<?php

// Round 7 "money and checkout": stores whose currency has no minor units (CZK, HUF in stores.json) show whole
// koruny/forint, so they must also charge whole ones. A 10 % coupon or a 2.5 % payment fee left fractions in the
// order (129,90 Kč shown as "130 Kč"), and Stripe refuses an HUF amount that is not a multiple of 100.
echo "\n--- R7 money: whole units in currencies without minor units ---\n";
ConsentService::setDefinitionsForTesting([]);
I18n::setLocale('sk');
$r7mPricing = (new ReflectionProperty(Pricing::class, 'config'))->getValue();
$r7mActivePromotions = $pdo->query("SELECT id FROM promotions WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec("UPDATE promotions SET active = 0");
Pricing::setConfig(['cart_rules' => [
    ['type' => 'coupon', 'active' => true, 'code' => 'R7M10', 'name' => 'R7M ten percent', 'discount_type' => 'percent', 'discount_value' => 10, 'stacking' => 'stack'],
]]);
$r7mStores = Bootstrap::stores();
$r7mPrevious = StoreContext::current();
$r7mStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r7mTestStores = $r7mStores;
$r7mCases = ['czk' => ['store' => 'r7mczk', 'product' => 97301, 'price' => 129900, 'shipping' => 7900],
    'huf' => ['store' => 'r7mhuf', 'product' => 97302, 'price' => 1299900, 'shipping' => 129000]];
foreach ($r7mCases as $r7mCurrency => $r7mCase) {
    $r7mStore = $r7mCase['store'];
    $r7mTestStores['stores'][$r7mStore] = array_replace($r7mStores['stores']['default'], ['id' => $r7mStore, 'url_prefix' => '/' . $r7mStore, 'domains' => [],
        'catalog_scope' => $r7mStore, 'provider_scope' => $r7mStore, 'currency' => $r7mCurrency, 'channels' => ['storefront']]);
    // 1 299 Kč / 12 999 Ft goods, 79 Kč / 1 290 Ft shipping, a 10 % coupon and a 2.5 % payment fee.
    $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (?, ?, 1, 'R7M goods', ?, ?, 'simple', ?, ?, 'active', 0, 'backorder')")
        ->execute([$r7mCase['product'], $r7mStore, 'r7m-goods-' . $r7mCurrency, 'R7M-' . strtoupper($r7mCurrency), $r7mCase['price'], $r7mCurrency]);
    $pdo->prepare("INSERT INTO shipping_zones (store_id, code, name) VALUES (?, ?, 'R7M zone')")->execute([$r7mStore, 'r7mzone' . $r7mCurrency]);
    $pdo->prepare("INSERT INTO shipping_zone_countries (store_id, zone_code, country) VALUES (?, ?, 'SK')")->execute([$r7mStore, 'r7mzone' . $r7mCurrency]);
    $pdo->prepare("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES (?, ?, 'gls', 'R7M parcel', 'parcel', 0, 0, 1)")
        ->execute([$r7mStore, 'r7mship' . $r7mCurrency]);
    $pdo->prepare("INSERT INTO shipping_rates (store_id, carrier, country, zone_code, method_code, weight_from_g, weight_to_g, price, cod_surcharge, sort_order)
        VALUES (?, 'gls', '', ?, ?, 0, 999999, ?, 0, 1)")->execute([$r7mStore, 'r7mzone' . $r7mCurrency, 'r7mship' . $r7mCurrency, $r7mCase['shipping']]);
    $pdo->prepare("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
        VALUES (?, ?, 'manual', 'R7M percent', '', 'offline', 0, 0, 0, 250, NULL, 0, 1, 1)")->execute([$r7mStore, 'r7mpct' . $r7mCurrency]);
    $pdo->prepare("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES (?, ?, 'SK')")->execute([$r7mStore, 'r7mpct' . $r7mCurrency]);
}
$r7mCheckout = static function (string $currency, array $case): array {
    StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), null, null, $case['store'], 'storefront'));
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Cart::clear();
    Cart::add($case['product'], null, 1);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.9.73';
    $_SESSION['csrf_token'] = 'r7m-checkout';
    $_POST = ['csrf_token' => 'r7m-checkout', 'name' => 'R7M Buyer', 'email' => 'r7m-buyer@example.com', 'phone' => '', 'country' => 'SK',
        'street' => 'Audit 7', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'r7mship' . $currency, 'payment_method' => 'r7mpct' . $currency, 'coupon_code' => 'R7M10'];
    $result = apiCheckout();
    $_POST = [];
    Cart::clear();
    $order = Orders::byId((int)($result['order_id'] ?? 0)) ?: [];
    $money = [];
    foreach ($order ? ['subtotal', 'discount_amount', 'shipping_cost', 'payment_fee', 'total'] : [] as $column) $money[$column] = (int)$order[$column];
    if ($order) $order['items'] = Orders::items((int)$order['id']);
    return [$result, $order, $money];
};
$r7mStripe = static fn(array $order): array => (new ReflectionMethod(StripePayment::class, 'buildLineItems'))->invoke(new StripePayment(['stripe_secret_key' => 'sk_test_r7m']), $order);
try {
    $r7mStoresProp->setValue(null, $r7mTestStores);
    foreach ($r7mCases as $r7mCurrency => $r7mCase) {
        [$r7mResult, $r7mOrder, $r7mMoney] = $r7mCheckout($r7mCurrency, $r7mCase);
        assert_true(!empty($r7mResult['success']) && $r7mMoney !== [], "R7 money {$r7mCurrency}: the checkout places the order (" . ($r7mResult['error'] ?? 'ok') . ')');
        if ($r7mMoney === []) continue;
        assert_equals([], array_keys(array_filter($r7mMoney, static fn(int $amount): bool => $amount % 100 !== 0)),
            "R7 money {$r7mCurrency}: discount, shipping, payment fee and total are whole units of a currency without minor units (" . json_encode($r7mMoney) . ')');
        assert_equals($r7mMoney['subtotal'] - $r7mMoney['discount_amount'] + $r7mMoney['shipping_cost'] + $r7mMoney['payment_fee'], $r7mMoney['total'],
            "R7 money {$r7mCurrency}: the rounded parts still add up to the total");
        $r7mLines = array_map(static fn(array $line): int => (int)$line['price_data']['unit_amount'] * (int)$line['quantity'], $r7mStripe($r7mOrder));
        assert_equals([], array_values(array_filter($r7mLines, static fn(int $amount): bool => $amount % 100 !== 0)),
            "R7 money {$r7mCurrency}: every Stripe line amount is a multiple of 100 (Stripe refuses other HUF amounts)");
    }
    // An order that still carries fractions (made before this fix) is refused before Stripe sees it, with a clear reason.
    $r7mThrown = '';
    try {
        $r7mStripe(['currency' => 'huf', 'total' => 1299950, 'order_number' => 'R7M-OLD', 'items' => []]);
    } catch (RuntimeException $e) {
        $r7mThrown = $e->getMessage();
    }
    assert_true(str_contains($r7mThrown, 'multiple of 100'), 'R7 money: Stripe refuses an HUF total that is not whole forint before calling the API (' . $r7mThrown . ')');
    assert_equals([129900, 130000, 1299, 0], [Money::round(129949, 'czk'), Money::round(129950, 'CZK'), Money::round(1299, 'eur'), Money::round(49, 'huf')],
        'R7 money: Money::round rounds half up to the smallest unit the currency shows; EUR stays in cents');
} finally {
    $r7mStoresProp->setValue(null, $r7mStores);
    StoreContext::activate($r7mPrevious);
    Pricing::setConfig($r7mPricing);
    if ($r7mActivePromotions) $pdo->exec('UPDATE promotions SET active = 1 WHERE id IN (' . implode(',', array_map('intval', $r7mActivePromotions)) . ')');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}
