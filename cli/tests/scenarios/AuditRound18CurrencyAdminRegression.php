<?php

// Review round 18 (2026-11-06), medium "HUF/CZK in the admin": saving an unchanged form rounded amounts stored finer than
// a whole unit (price list 1234567 â†’ 1234600, B2B credit limit 123456 â†’ 123500), and saving one price-list line
// rewrote every other one rounded. Medium "shared catalog": a product created in the admin of the HU store (HUF),
// which shares the default store's EUR catalog, was priced in HUF and could not be sold in the EUR store.
echo "\n--- R18: admin amounts round-trip in CZK/HUF, a new product takes the catalog's currency ---\n";
require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Catalog.php';

$r18uTrip = static fn(int $cents, string $currency): ?int => Money::adminInput(Money::adminValue($cents, $currency), $currency);
assert_equals([1234567, 123456, 1234500, 1290, -150, 0], [
    $r18uTrip(1234567, 'HUF'), $r18uTrip(123456, 'CZK'), $r18uTrip(1234500, 'HUF'), $r18uTrip(1290, 'EUR'), Money::adminInput(Money::adminValue(-150, 'CZK'), 'CZK', 0, true), $r18uTrip(0, 'HUF'),
], 'R18 currency: every stored amount is shown and saved back unchanged in CZK/HUF (1234567 stayed 1234567, was 1234600)');
assert_equals(['12345.67', '12345', '1234.56'], [Money::adminValue(1234567, 'HUF'), Money::adminValue(1234500, 'HUF'), Money::adminValue(123456, 'CZK')],
    'R18 currency: a whole-unit amount is shown in whole units, one stored with cents shows them');
assert_equals(['12345.67', '12345'], [adminFormValues('admin-product', ['price' => 1234567, 'currency' => 'huf'])['price'], adminFormValues('admin-product', ['price' => 1234500, 'currency' => 'huf'])['price']],
    'R18 currency: the product form shows a HUF price stored with cents exactly');
assert_equals([-1, null], [Money::parseInput('12.5', 'huf'), Money::adminInput('12.505', 'HUF')],
    'R18 currency: a payment amount stays in whole units (parseInput), and no admin amount is finer than a cent');

// A new product of a shared catalog is priced in the currency of the store the catalog belongs to.
$r18uStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r18uSavedStores = $r18uStoresProp->getValue();
$r18uPrevious = StoreContext::current();
try {
    $r18uSample = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    $r18uStoresProp->setValue(null, $r18uSample);
    StoreContext::activate(StoreContext::resolve($r18uSample, null, null, 'hu', 'storefront'));
    $r18uShared = [adminCatalogCurrency(), adminProductCurrency(null), adminProductCurrency(['currency' => 'huf'])];
    $r18uSample['stores']['hu']['catalog_scope'] = 'hu';
    $r18uStoresProp->setValue(null, $r18uSample);
    StoreContext::activate(StoreContext::resolve($r18uSample, null, null, 'hu', 'storefront'));
    $r18uOwn = adminProductCurrency(null);
    assert_equals([['EUR', 'EUR', 'HUF'], 'HUF'], [$r18uShared, $r18uOwn],
        'R18 shared catalog: a new product in the HU admin of the default (EUR) catalog is priced in EUR, an existing one keeps its currency, a HU-only catalog uses HUF');
    assert_true(I18n::format('admin.product.prices_in', ['currency' => 'EUR']) !== 'admin.product.prices_in'
        && str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Catalog.php'), "I18n::format('admin.product.prices_in', ['currency' => adminProductCurrency(\$product ?: null)])"),
        'R18 shared catalog: the product form says which currency its prices are in');
} finally {
    $r18uStoresProp->setValue(null, $r18uSavedStores);
    StoreContext::activate($r18uPrevious);
}
