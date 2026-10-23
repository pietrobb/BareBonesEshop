<?php

// Round 9 review (2026-10-23), acceptance test "CZ store": the sample CZ store shares the EUR catalog. Its admin offers
// a CZK price list, but a price list was used only in the product's own currency, so the CZK one was ignored: the
// store showed and charged euros, and the EUR cart got the English shipping.json methods ("Standard Delivery")
// instead of the store's Czech carriers priced in CZK. A price list in the store's currency now sells the product in
// that currency (listing, product page, cart, checkout); without one the item is not for sale in that store.

echo "\n--- Audit R9: a price list in the store currency sells the shared catalog in it (CZ store in CZK) ---\n";
require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';

$r9cRoot = dirname(__DIR__, 3);
$r9cSample = json_decode((string)file_get_contents($r9cRoot . '/stores.json'), true);
$r9cStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r9cSavedStores = $r9cStoresProp->getValue();
$r9cPrevious = StoreContext::current();
$r9cLocale = I18n::locale();
$r9cSession = $_SESSION ?? [];
[$r9cSavedDb, $r9cSavedDriver] = [Db::get(), Db::driver()];
$r9cDbPath = test_fixture_path('r9-store-currency');
$r9cDb = new PDO('sqlite:' . $r9cDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$r9cStore = static function (string $store) use ($r9cSample): void {
    StoreContext::activate(StoreContext::resolve($r9cSample, null, null, $store, 'storefront'));
};
try {
    (new Migrator($r9cDb, $r9cRoot . '/migrations', $r9cDbPath . '.lock'))->apply(static fn($m) => null);
    Db::init($r9cDb, 'sqlite');
    $r9cStoresProp->setValue(null, $r9cSample);
    ConsentService::setDefinitionsForTesting([]);
    I18n::setLocale('cs');

    // 19,99 € in the shared catalog; the CZ store's CZK price list sells it for 499 Kč.
    $r9cProduct = static function (int $id, string $sku) use ($r9cDb): void {
        $r9cDb->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy, weight_g)
            VALUES (?, 'default', 1, ?, ?, ?, 'simple', 1999, 'eur', 'active', 0, 'backorder', 800)")->execute([$id, "R9C {$sku}", strtolower($sku), $sku]);
    };
    $r9cProduct(97901, 'R9C-PRICED');
    $r9cProduct(97902, 'R9C-UNPRICED');
    $r9cVariant = static function (int $productId, string $sku, int $delta) use ($r9cDb): int {
        $r9cDb->prepare("INSERT INTO variants (product_id, sku, name, stock, price_delta) VALUES (?, ?, ?, 5, ?)")->execute([$productId, $sku, $sku, $delta]);
        return (int)$r9cDb->lastInsertId();
    };
    $r9cPlain = $r9cVariant(97901, 'R9C-V-PLAIN', 0);
    $r9cSurcharged = $r9cVariant(97901, 'R9C-V-PLUS', 500);
    $r9cOwnRow = $r9cVariant(97901, 'R9C-V-OWN', 500);
    $r9cDb->exec("INSERT INTO price_lists (store_id, code, name, currency, customer_group_id, priority, active) VALUES ('cz', 'r9c-czk', 'Ceník CZK', 'CZK', NULL, 0, 1)");
    $r9cList = (int)$r9cDb->lastInsertId();
    $r9cDb->prepare('INSERT INTO price_list_prices (price_list_id, product_id, variant_id, min_qty, price) VALUES (?, 97901, NULL, 1, 49900)')->execute([$r9cList]);
    $r9cDb->prepare('INSERT INTO price_list_prices (price_list_id, product_id, variant_id, min_qty, price) VALUES (?, 97901, ?, 1, 52900)')->execute([$r9cList, $r9cOwnRow]);

    // Listing and product page (one enrichment path).
    $r9cStore('cz');
    $r9cShown = Products::enrich(Products::byId(97901));
    assert_equals(['czk', 49900, null], [$r9cShown['currency'], (int)$r9cShown['price'], $r9cShown['compare_price'] ?? null],
        'R9 CZK: the CZ store shows the product at its CZK price list price, with no struck-through euro amount');
    assert_true(str_contains((string)$r9cShown['price_formatted'], 'Kč') && !str_contains((string)$r9cShown['price_formatted'], '€'), 'R9 CZK: the price is formatted in koruny (' . $r9cShown['price_formatted'] . ')');
    // Capability review run 154: an item without a CZK price was sold in euros inside the CZ store, and the first line
    // set the cart's currency, so a euro item added first turned the koruna lines into excluded mismatches.
    $r9cOther = StorefrontViewModel::productCard(Products::byId(97902));
    assert_equals(false, $r9cOther['availability']['purchasable'], 'R9 CZK: a product without a CZK price is not for sale in the CZ store (shown unavailable, ' . $r9cOther['availability']['label'] . ')');

    $r9cVariantView = new ReflectionMethod(StorefrontViewModel::class, 'variant');
    $r9cVariantRow = static fn(int $id): array => $r9cDb->query("SELECT * FROM variants WHERE id = {$id}")->fetch();
    $r9cViews = [];
    foreach (['plain' => $r9cPlain, 'own row' => $r9cOwnRow, 'surcharge' => $r9cSurcharged] as $r9cLabel => $r9cId) {
        $r9cView = $r9cVariantView->invoke(null, $r9cShown, $r9cVariantRow($r9cId));
        $r9cViews[$r9cLabel] = [$r9cView['price_amount_minor'], str_contains($r9cView['price_formatted'], 'Kč') ? 'czk' : 'eur', $r9cView['availability']['purchasable']];
    }
    assert_equals(['plain' => [49900, 'czk', true], 'own row' => [52900, 'czk', true], 'surcharge' => [2499, 'eur', false]], $r9cViews,
        'R9 CZK: variants are sold at the list price or their own CZK row; a euro surcharge without a CZK row is not for sale in the CZ store');

    $r9cStore('default');
    $r9cHome = Products::enrich(Products::byId(97901));
    assert_equals(['eur', 1999], [$r9cHome['currency'], (int)$r9cHome['price']], 'R9 CZK: the EUR store is not touched by the CZ store\'s price list');

    // Cart and a real checkout in the CZ store: koruny all the way, with the store's own Czech carriers.
    $r9cStore('cz');
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $r9cMixed = [];
    foreach (['euro item first' => [[97902, null], [97901, null]], 'surcharged variant' => [[97901, $r9cPlain], [97901, $r9cSurcharged]]] as $r9cLabel => $r9cAdds) {
        $_SESSION = ['cart' => []];
        foreach ($r9cAdds as [$r9cPid, $r9cVid]) Cart::add($r9cPid, $r9cVid, 1);
        $r9cMixedCart = Cart::enriched();
        $r9cMixed[$r9cLabel] = [$r9cMixedCart['currency'], (int)$r9cMixedCart['subtotal'],
            array_map(static fn(array $i): array => [$i['currency'], $i['available'], $i['unavailable_reason']], $r9cMixedCart['items'])];
    }
    $r9cOff = ['eur', false, Cart::UNAVAILABLE_CURRENCY];
    assert_equals([
        'euro item first' => ['czk', 49900, [$r9cOff, ['czk', true, null]]],
        'surcharged variant' => ['czk', 49900, [['czk', true, null], $r9cOff]],
    ], $r9cMixed, 'R9 CZK: a cart in the CZ store stays in koruny and counts every koruna line; a line without a CZK price is unavailable, whatever was added first');
    $_SESSION = ['cart' => []];
    $r9cRefused = [Cart::addValidated(97902, null, 1), Cart::addValidated(97901, $r9cSurcharged, 1)];
    assert_equals([['ok' => false, 'error' => 'product_unavailable'], ['ok' => false, 'error' => 'product_unavailable'], []],
        [...$r9cRefused, Cart::get()], 'R9 CZK: adding an item without a CZK price is refused and leaves the cart empty');
    try {
        Orders::create([['product_id' => 97902, 'variant_id' => null, 'sku' => 'R9C-UNPRICED', 'name' => 'R9C', 'qty' => 1, 'price' => 1999, 'currency' => 'eur']],
            ['name' => 'R9C', 'email' => 'r9c-direct@example.com', 'currency' => 'czk'], 'bank_transfer');
        $r9cDirect = 'created';
    } catch (OrderRejectedException $e) {
        $r9cDirect = $e->getMessage();
    }
    assert_equals('Not priced in the store currency: eur', $r9cDirect, 'R9 CZK: Orders::create refuses a line priced in another currency than the store\'s');
    $r9cGap = StoreCurrencyCoverage::unpriced($r9cDb, 'cz', $r9cSample['stores']['cz']);
    assert_true(in_array('R9C-UNPRICED', $r9cGap['skus'], true) && in_array('R9C-V-PLUS', $r9cGap['skus'], true)
        && !array_intersect(['R9C-PRICED', 'R9C-V-PLAIN', 'R9C-V-OWN'], $r9cGap['skus']),
        'R9 CZK: doctor lists the CZ store\'s items without a CZK price, not the priced ones (' . json_encode($r9cGap) . ')');
    assert_equals(['count' => 0, 'skus' => []], StoreCurrencyCoverage::unpriced($r9cDb, 'default', $r9cSample['stores']['default']),
        'R9 CZK: the EUR store selling its EUR catalog has nothing unpriced');

    $_SESSION = ['cart' => []];
    Cart::add(97901, null, 1);
    $r9cCart = Cart::enriched();
    assert_equals(['czk', 49900, false], [$r9cCart['currency'], (int)$r9cCart['subtotal'], (bool)($r9cCart['items'][0]['currency_mismatch'] ?? true)],
        'R9 CZK: the cart charges the product in koruny');
    $r9cLabels = array_column(ShippingService::cartQuote($r9cCart['items'], (int)$r9cCart['subtotal'], 'CZ')['rates'] ?? [], 'label', 'method_code');
    assert_true(isset($r9cLabels['gls'], $r9cLabels['packeta']) && !isset($r9cLabels['standard']) && !isset($r9cLabels['express']),
        'R9 CZK: the koruna cart is offered the CZ store\'s carriers, not the English EUR shipping.json methods (' . json_encode($r9cLabels, JSON_UNESCAPED_UNICODE) . ')');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.9.93';
    $_SESSION['csrf_token'] = 'r9c-checkout';
    $_POST = ['csrf_token' => 'r9c-checkout', 'name' => 'R9C Kupující', 'email' => 'r9c-buyer@example.com', 'phone' => '', 'country' => 'CZ',
        'street' => 'Václavské nám. 1', 'city' => 'Praha', 'zip' => '11000', 'shipping_method' => 'gls', 'payment_method' => 'bank_transfer'];
    $r9cResult = apiCheckout();
    $_POST = [];
    $r9cOrder = Orders::byId((int)($r9cResult['order_id'] ?? 0)) ?: [];
    assert_true(!empty($r9cResult['success']) && $r9cOrder !== [], 'R9 CZK: the CZ store places the order (' . ($r9cResult['error'] ?? 'ok') . ')');
    if ($r9cOrder) {
        assert_equals(['czk', 49900], [(string)$r9cOrder['currency'], (int)$r9cOrder['subtotal']], 'R9 CZK: the order is in koruny at the price list price');
        assert_true((int)$r9cOrder['shipping_cost'] > 0 && (int)$r9cOrder['shipping_cost'] % 100 === 0 && (int)$r9cOrder['total'] % 100 === 0,
            'R9 CZK: shipping and total are whole koruny from the CZ price list (shipping ' . $r9cOrder['shipping_cost'] . ', total ' . $r9cOrder['total'] . ')');
    }

    // shipping.json's own methods are named in the shopper's language, not the hardcoded English label.
    $r9cStore('default');
    I18n::setLocale('sk');
    $r9cLegacy = array_column(array_filter(ShippingService::calculateRates('IS', 1000, 2000), static fn(array $r): bool => ($r['provider'] ?? '') === 'legacy'), 'label', 'method_code');
    assert_equals('Štandardné doručenie', $r9cLegacy['standard'] ?? null, 'R9 shipping: the shipping.json method is shown in the shopper\'s language (' . json_encode($r9cLegacy, JSON_UNESCAPED_UNICODE) . ')');
} finally {
    $_POST = [];
    $_SESSION = $r9cSession;
    I18n::setLocale($r9cLocale);
    $r9cStoresProp->setValue(null, $r9cSavedStores);
    StoreContext::activate($r9cPrevious);
    Db::init($r9cSavedDb, $r9cSavedDriver);
    $r9cDb = null;
    foreach (['', '-wal', '-shm', '.lock'] as $r9cSuffix) @unlink($r9cDbPath . $r9cSuffix);
}
