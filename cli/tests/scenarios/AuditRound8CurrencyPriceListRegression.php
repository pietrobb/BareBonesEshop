<?php

// Round 8 review (2026-10-21), unfixed from round 7: the sample CZ, HU and PL stores shared the default store's
// price lists (provider_scope "default"), written in EUR. Since round 7 a store in another currency is never quoted
// those EUR amounts, so the three stores offered only personal pickup and fee-less payments: no carrier, no cash on
// delivery. Each now has its own provider scope with a price list in its own currency (migration
// 20261022230000_currency_price_lists), and the default store's EUR price list is unchanged.

echo "\n--- Audit round 8 (rest): price lists in the store currency (CZ, HU, PL) ---\n";

$r8cRoot = dirname(__DIR__, 3);
$r8cSample = json_decode((string)file_get_contents($r8cRoot . '/stores.example.json'), true);
$r8cStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r8cSavedStores = $r8cStoresProp->getValue();
$r8cPrevious = StoreContext::current();
// The suite database applies only selected migrations by hand; the seeded price lists need a fully migrated one.
[$r8cSavedDb, $r8cSavedDriver] = [Db::get(), Db::driver()];
$r8cDbPath = test_fixture_path('r8-price-lists');
$r8cDb = new PDO('sqlite:' . $r8cDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$r8cCases = [
    // store => [currency, destination country, goods subtotal in minor units]
    'cz' => ['czk', 'CZ', 80000],
    'hu' => ['huf', 'HU', 1200000],
    'pl' => ['pln', 'PL', 15000],
];
try {
    (new Migrator($r8cDb, $r8cRoot . '/migrations', $r8cDbPath . '.lock'))->apply(static fn($m) => null);
    Db::init($r8cDb, 'sqlite');
    $r8cStoresProp->setValue(null, $r8cSample);
    foreach ($r8cCases as $r8cStore => [$r8cCurrency, $r8cCountry, $r8cSubtotal]) {
        $r8cConfig = $r8cSample['stores'][$r8cStore] ?? [];
        assert_equals([$r8cCurrency, $r8cStore], [$r8cConfig['currency'] ?? null, $r8cConfig['provider_scope'] ?? null], "R8 price lists {$r8cStore}: the sample store has its own provider scope for its {$r8cCurrency} price list");

        StoreContext::activate(StoreContext::resolve($r8cSample, null, null, $r8cStore, 'storefront'));
        assert_true(StoreContext::current()->usesProviderPrices(), "R8 price lists {$r8cStore}: the store charges the amounts of its own price list");

        $r8cRates = [];
        foreach (ShippingService::calculateRates($r8cCountry, 1000, $r8cSubtotal, true) as $r8cRate) {
            $r8cRates[(string)$r8cRate['method_code']] = [(int)$r8cRate['price'], (int)($r8cRate['cod_surcharge'] ?? 0)];
        }
        foreach (['packeta', 'gls'] as $r8cCarrier) {
            assert_true(isset($r8cRates[$r8cCarrier]) && $r8cRates[$r8cCarrier][0] > 0, "R8 price lists {$r8cStore}: {$r8cCarrier} delivers to {$r8cCountry} at a price (" . json_encode($r8cRates) . ')');
        }
        if ($r8cCurrency !== 'pln') {
            assert_equals([], array_keys(array_filter($r8cRates, static fn(array $r): bool => $r[0] % 100 !== 0 || $r[1] % 100 !== 0)),
                "R8 price lists {$r8cStore}: shipping prices and COD surcharges are whole {$r8cCurrency} units, not converted cents (" . json_encode($r8cRates) . ')');
        }
        assert_true(($r8cRates['packeta'][1] ?? 0) > 0, "R8 price lists {$r8cStore}: a cash-on-delivery parcel carries its surcharge");

        $r8cMethods = [];
        foreach (PaymentService::availableMethods($r8cCountry, $r8cCurrency, $r8cSubtotal, 'packeta') as $r8cMethod) {
            $r8cMethods[(string)$r8cMethod['code']] = (int)$r8cMethod['fee'];
        }
        assert_true(isset($r8cMethods['cod'], $r8cMethods['card'], $r8cMethods['bank_transfer']), "R8 price lists {$r8cStore}: cash on delivery, card and bank transfer are offered (" . json_encode($r8cMethods) . ')');
        assert_true(($r8cMethods['cod'] ?? 0) > 0 && $r8cMethods['cod'] % 100 === 0, "R8 price lists {$r8cStore}: cash on delivery has its fee in whole {$r8cCurrency} units (" . ($r8cMethods['cod'] ?? 0) . ')');

        // A cart is charged in its products' currency: EUR goods of the shared catalog in this store must not be
        // quoted the store's price list (104 Kč Packeta would be charged as 104 €); the carriers' EUR tiers apply.
        $r8cEurRates = [];
        foreach (ShippingService::calculateRates($r8cCountry, 1000, $r8cSubtotal, true, 0, 0, 'eur') as $r8cRate) {
            $r8cEurRates[(string)$r8cRate['method_code']] = [(int)$r8cRate['price'], (int)($r8cRate['cod_surcharge'] ?? 0)];
        }
        foreach (ShippingService::cartQuote([['currency' => 'eur', 'weight_g' => 1000, 'qty' => 1]], $r8cSubtotal, $r8cCountry)['rates'] ?? [] as $r8cRate) {
            $r8cEurRates['quote ' . $r8cRate['method_code']] = [(int)$r8cRate['price'], null];
        }
        $r8cCharged = [];
        foreach (['packeta', 'gls'] as $r8cCarrier) {
            if (($r8cEurRates[$r8cCarrier] ?? null) === $r8cRates[$r8cCarrier]) $r8cCharged[] = $r8cCarrier;
            if (isset($r8cEurRates['quote ' . $r8cCarrier]) && $r8cEurRates['quote ' . $r8cCarrier][0] === $r8cRates[$r8cCarrier][0]) $r8cCharged[] = 'quote ' . $r8cCarrier;
        }
        assert_equals([], $r8cCharged, "R8 price lists {$r8cStore}: an EUR cart is not quoted the {$r8cCurrency} carrier rates (calculateRates and the checkout cartQuote: " . json_encode($r8cEurRates) . ')');
        $r8cEurPayments = array_column(PaymentService::availableMethods($r8cCountry, 'eur', 8000, 'packeta'), 'code');
        assert_true(!in_array('cod', $r8cEurPayments, true), "R8 price lists {$r8cStore}: an EUR cart is not charged the {$r8cCurrency} cash-on-delivery fee (" . json_encode($r8cEurPayments) . ')');
    }

    StoreContext::activate(StoreContext::resolve($r8cSample, null, null, 'default', 'storefront'));
    $r8cDefault = [];
    foreach (ShippingService::calculateRates('SK', 1000, 2000, false) as $r8cRate) $r8cDefault[(string)$r8cRate['method_code']] = (int)$r8cRate['price'];
    assert_equals(299, $r8cDefault['packeta'] ?? null, 'R8 price lists default: the EUR price list is unchanged (Packeta SK 2,99 €)');
} finally {
    $r8cStoresProp->setValue(null, $r8cSavedStores);
    StoreContext::activate($r8cPrevious);
    Db::init($r8cSavedDb, $r8cSavedDriver);
    $r8cDb = null;
    foreach (['', '-wal', '-shm', '.lock'] as $r8cSuffix) @unlink($r8cDbPath . $r8cSuffix);
}

// An existing installation keeps its stores.json: the upgrade notes say how to switch a store to the seeded scope.
$r8cDocs = (string)file_get_contents($r8cRoot . '/CHANGELOG.md');
assert_true(preg_match('/20261022230000_currency_price_lists[^\n]*\n(?:[^\n]*\n){0,6}[^\n]*`provider_scope`/', $r8cDocs) === 1, 'R8 price lists docs: the CHANGELOG upgrade notes explain the seeded CZ/HU/PL scopes and the provider_scope switch');
