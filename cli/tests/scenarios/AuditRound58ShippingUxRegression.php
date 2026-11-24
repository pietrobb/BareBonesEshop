<?php

// Review round 23 (2026-11-24, main @ bd743edb), medium S4 + shipping lows: Admin → Shipping told the merchant to edit
// shipping.json, a core file, to change Standard/Express — and the updater then stopped at "installed file was locally
// modified". Every web install offered Standard 3.99 € / Express 7.99 € next to its own GLS, Packeta and pickup, prices
// the merchant never set. The shipping.json table showed EUR prices in a CZK store that never offers them, listed
// "In-store pickup" (never offered from shipping.json) and raw zone keys (domestic, eu); the order list and detail named a
// shipping.json method in the customer's language.
echo "\n--- R58: Standard/Express set in Admin → Shipping (not in shipping.json), off on a new web install, admin page and labels ---\n";
if (!function_exists('pageShipping')) {
    if (!function_exists('adminFieldErrors')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php';
}

$r58sRoot = dirname(TEST_CLI_DIR);
$r58sLocale = I18n::locale();
$r58sFile = json_decode((string)file_get_contents($r58sRoot . '/shipping.json'), true);
$r58sFileHash = hash_file('sha256', $r58sRoot . '/shipping.json');
$r58sDir = test_fixture_path('r58-shipping');
@mkdir($r58sDir, 0700, true);
$r58sStores = static fn(string $country, string $currency): array => ['default_store' => 'r58s', 'stores' => ['r58s' => ['name' => 'R58 shipping', 'default_locale' => 'de',
    'languages' => ['de'], 'currency' => $currency, 'url_prefix' => '', 'domains' => [], 'permissions' => ['*'], 'legal' => ['country' => $country]]]];
$r58sLegacy = static function (string $country, int $subtotal = 1000): array {
    $rates = [];
    foreach (ShippingService::calculateRates($country, 1000, $subtotal, false, 0, 1, 'EUR') as $rate) {
        if (($rate['provider'] ?? '') === 'legacy') $rates[(string)$rate['method_code']] = (int)$rate['price'];
    }
    return $rates;
};
$r58sFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R58 shipping UX {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
// The settings as Admin → Shipping saves them (section 1 checks the form gives exactly these).
$r58sSettings = ['standard' => ['active' => false], 'express' => ['free_above' => 10000,
    'rates' => ['domestic' => ['base' => 650, 'per_kg' => 0], 'eu' => null, 'international' => ['base' => 2500, 'per_kg' => 0]]]];
try {
    DataOverrides::setDirForTesting($r58sDir);
    Shipping::setConfig((array)$r58sFile);
    StoreContext::activate(StoreContext::resolve($r58sStores('DE', 'eur'), '/', '', 'r58s'));
    assert_equals(['standard' => 399, 'express' => 799], $r58sLegacy('DE'), 'R58 S4: without settings a shop keeps Standard and Express as shipping.json prices them (existing installs unchanged)');

    // 1. S4: the merchant turns Standard off and prices Express in Admin → Shipping; shipping.json is not touched.
    $r58sAll = [];
    foreach ([
        'standard' => ['method' => 'standard', 'rates' => ['domestic' => ['base' => '3.99', 'per_kg' => '0.00'], 'eu' => ['base' => '7.99', 'per_kg' => '1.00'],
            'international' => ['base' => '15.99', 'per_kg' => '2.00']], 'free_above' => '50.00', 'delivery_days' => '5-7'],
        'express' => ['method' => 'express', 'active' => '1', 'rates' => ['domestic' => ['base' => '6.50', 'per_kg' => ''], 'eu' => ['base' => '', 'per_kg' => '1.50'],
            'international' => ['base' => '25.00', 'per_kg' => '0']], 'free_above' => '100.00', 'delivery_days' => '1-2'],
    ] as $r58sKey => $r58sPost) {
        $r58sResult = shippingFileMethodSettings($r58sKey, $r58sPost, $r58sAll);
        assert_equals(null, $r58sResult['error'], "R58 S4: the {$r58sKey} form is accepted");
        $r58sAll = $r58sResult['settings'];
    }
    assert_equals($r58sSettings, $r58sAll, 'R58 S4: only what differs from shipping.json is kept (an unchanged price follows a later shipping.json)');
} catch (Throwable $e) { $r58sFail('form', $e); }
try {
    DataOverrides::setDirForTesting($r58sDir);
    file_put_contents($r58sDir . '/shipping-methods.json', json_encode(['methods' => $r58sSettings]));
    Shipping::setConfig((array)$r58sFile);
    StoreContext::activate(StoreContext::resolve($r58sStores('DE', 'eur'), '/', '', 'r58s'));
    assert_equals([['express' => 650], ['express' => 0], ['express' => 2500], -1],
        [$r58sLegacy('DE'), $r58sLegacy('DE', 10000), $r58sLegacy('US'), Shipping::calculate('standard', 'DE', 1000, 1000)],
        'R58 S4: checkout offers Express at the merchant\'s prices (free from 100) and no longer Standard (was: shipping.json only, edited by hand)');
    assert_equals(['DE'], Shipping::zoneCountries(), 'R58 S4: the EU zone neither method serves now adds no destination countries');
    assert_equals($r58sFileHash, hash_file('sha256', $r58sRoot . '/shipping.json'), 'R58 S4: shipping.json stays as shipped, so the updater finds it unmodified');
    assert_true(str_contains((string)json_encode(BackupService::defaultFileSources(['private_runtime_dir' => $r58sDir], $r58sRoot)), DataOverrides::SHIPPING_METHODS),
        'R58 S4: a backup keeps the settings');
    @unlink($r58sDir . '/shipping-methods.json');
    assert_true(DataOverrides::saveShippingMethods($r58sSettings) && DataOverrides::shippingMethods()['methods'] === $r58sSettings
        && (fileperms($r58sDir . '/' . DataOverrides::SHIPPING_METHODS) & 0777) === 0640, 'R58 S4: the settings are saved to and read from the data directory (0640)');
} catch (Throwable $e) { $r58sFail('checkout', $e); }
try {
    $r58sReset = shippingFileMethodSettings('standard', ['method' => 'standard', 'reset' => '1'], $r58sSettings);
    assert_equals([null, ['express']], [$r58sReset['error'], array_keys($r58sReset['settings'])], 'R58 S4: "restore" drops a method\'s settings');
    assert_true(shippingFileMethodSettings('pickup', ['method' => 'pickup'], [])['error'] !== null
        && shippingFileMethodSettings('express', ['method' => 'express', 'rates' => ['domestic' => ['base' => 'abc']]], [])['error'] !== null,
        'R58 S4: pickup (never offered from shipping.json) and an amount that is not one are refused');
} catch (Throwable $e) { $r58sFail('reset', $e); }
try {
    // 2. The admin page: switch and prices in an EUR store, zone names, no pickup; a note instead of EUR prices in a CZK store.
    DataOverrides::setDirForTesting($r58sDir);
    Shipping::setConfig((array)$r58sFile);
    StoreContext::activate(StoreContext::resolve($r58sStores('DE', 'eur'), '/', '', 'r58s'));
    I18n::setLocale('en');
    ob_start();
    try { pageShipping(); } finally { $r58sPage = (string)ob_get_clean(); }
    assert_true(str_contains($r58sPage, 'action=file_method_save') && preg_match('~<form [^>]*data-shipping-file-method="standard">.*?<input type="checkbox" name="active" value="1"  data-shipping-file-active>~s', $r58sPage) === 1
        && preg_match('~data-shipping-file-method="express">.*?name="rates\[domestic\]\[base\]" value="6.50"~s', $r58sPage) === 1
        && str_contains($r58sPage, '<td title="eu">European Union</td>') && !str_contains($r58sPage, 'title="pickup"') && !str_contains($r58sPage, '<td>eu</td>'),
        'R58 S4 admin: Standard and Express are switched and priced on the page, zones by name, no pickup row (was: "edit shipping.json", raw zone keys, pickup)');
    StoreContext::activate(StoreContext::resolve($r58sStores('CZ', 'czk'), '/', '', 'r58s'));
    ob_start();
    try { pageShipping(); } finally { $r58sCzk = (string)ob_get_clean(); }
    assert_true(str_contains($r58sCzk, 'data-shipping-file-other-currency') && !str_contains($r58sCzk, 'name="rates[') && !str_contains($r58sCzk, '6.50 EUR'),
        'R58 low: a CZK store says Standard and Express are not offered there instead of listing EUR prices');

} catch (Throwable $e) { $r58sFail('admin page', $e); }
try {
    // 3. The order list and detail name a shipping.json method in the admin's language.
    $r58sOrder = ['shipping_method' => 'standard', 'locale' => 'de', 'shipping_rate_snapshot' => json_encode(['provider' => 'legacy', 'method_code' => 'standard', 'label' => 'Standardversand'])];
    I18n::setLocale('sk');
    assert_equals(['Štandardné doručenie', 'Standardversand'], [ConsumerNotice::shippingMethod(['locale' => ''] + $r58sOrder), ConsumerNotice::shippingMethod($r58sOrder)],
        'R58 low: the Slovak admin reads "Štandardné doručenie", the German customer\'s e-mail "Standardversand" (was: the customer\'s language in the admin)');
    I18n::setLocale('en');

} catch (Throwable $e) { $r58sFail('order label', $e); } finally { I18n::setLocale('en'); }
try {
    // 4. S4: a new web install whose default store has shipping methods of its own starts with Standard and Express off.
    $r58sInstallDir = $r58sDir . '/install';
    @mkdir($r58sInstallDir, 0700, true);
    $r58sScope = (string)$pdo->query('SELECT store_id FROM shipping_methods WHERE active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $r58sInstallStores = ['default_store' => 'default', 'stores' => ['default' => ['provider_scope' => $r58sScope]]];
    DataOverrides::setDirForTesting(null);
    $r58sWritten = WebInstaller::fileShippingMethodsOff($pdo, $r58sInstallStores, ['private_runtime_dir' => $r58sInstallDir]);
    $r58sNone = WebInstaller::fileShippingMethodsOff($pdo, ['default_store' => 'r58-none', 'stores' => ['r58-none' => []]], ['private_runtime_dir' => $r58sInstallDir . '/none']);
    assert_equals([$r58sInstallDir . '/' . DataOverrides::SHIPPING_METHODS, ['standard' => ['active' => false], 'express' => ['active' => false]], null],
        [$r58sWritten, DataOverrides::shippingMethods(['private_runtime_dir' => $r58sInstallDir])['methods'] ?? null, $r58sNone],
        'R58 S4 installer: Standard and Express start switched off next to the store\'s own methods (was: 3.99/7.99 € the merchant never set); none without own methods');
} catch (Throwable $e) { $r58sFail('installer', $e); } finally {
    I18n::setLocale($r58sLocale);
    DataOverrides::setDirForTesting(null);
    Shipping::setConfig(is_array($r58sFile) ? $r58sFile : []);
    Bootstrap::initStoreContext('/', '', 'default');
    foreach ([$r58sDir . '/install/shipping-methods.json', $r58sDir . '/shipping-methods.json'] as $r58sLeft) @unlink($r58sLeft);
    @rmdir($r58sDir . '/install');
    @rmdir($r58sDir);
}
