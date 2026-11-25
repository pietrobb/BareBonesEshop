<?php

// Review round 24 (2026-11-25, main @ 2913aec7), shipping form lows (goal #637): the Standard/Express form saved zones it
// does not show (rates[local] = 0 € priced Standard in the pickup zone, which comes before "domestic", so domestic
// delivery cost nothing); "free above 0" was saved and did nothing; malformed delivery days said "amount format"; an
// unreadable settings file switched the methods the merchant had turned off back on; a zone whose prices were all
// removed kept its countries in the checkout list. Finding 7 (the CLI install) is in AuditInstallSmokeRegression.
echo "\n--- R59: shipping form zones, free above 0, delivery days, unreadable settings, unpriced zone ---\n";
if (!function_exists('pageShipping')) {
    if (!function_exists('adminFieldErrors')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php';
}

$r59hRoot = dirname(TEST_CLI_DIR);
$r59hLocale = I18n::locale();
$r59hFile = json_decode((string)file_get_contents($r59hRoot . '/shipping.json'), true);
$r59hDir = test_fixture_path('r59-shipping');
@mkdir($r59hDir, 0700, true);
$r59hStores = ['default_store' => 'r59h', 'stores' => ['r59h' => ['name' => 'R59 shipping', 'default_locale' => 'en', 'currency' => 'eur', 'url_prefix' => '',
    'domains' => [], 'permissions' => ['*'], 'legal' => ['country' => 'DE']]]];
$r59hFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R59 shipping {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r59hFresh = static function () use ($r59hDir, $r59hFile, $r59hStores): void {
    DataOverrides::setDirForTesting($r59hDir);
    Shipping::setConfig((array)$r59hFile);
    StoreContext::activate(StoreContext::resolve($r59hStores, '/', '', 'r59h'));
};

try {
    $r59hFresh();
    I18n::setLocale('en');
    // 1. A zone the form does not show is not saved, and a stored one is not applied.
    $r59hLocal = shippingFileMethodSettings('standard', ['method' => 'standard', 'active' => '1', 'free_above' => '50.00', 'delivery_days' => '5-7',
        'rates' => ['local' => ['base' => '0', 'per_kg' => '0']]], []);
    assert_equals([null, []], [$r59hLocal['error'], $r59hLocal['settings']], 'R59 low: rates[local] (a zone the form does not show) is not saved (was: Standard 0 € in the pickup zone)');
    file_put_contents($r59hDir . '/' . DataOverrides::SHIPPING_METHODS, json_encode(['methods' => ['standard' => ['rates' => ['local' => ['base' => 0, 'per_kg' => 0]]]]]));
    Shipping::forget();
    assert_equals(399, Shipping::calculate('standard', 'DE', 1000, 1000), 'R59 low: a stored rates[local] is ignored, domestic delivery keeps its 3.99 € (was: 0 €)');

    // 2. Free above 0 and malformed delivery days are refused with their own message.
    $r59hZero = shippingFileMethodSettings('standard', ['method' => 'standard', 'active' => '1', 'free_above' => '0', 'delivery_days' => '5-7'], []);
    $r59hDays = shippingFileMethodSettings('standard', ['method' => 'standard', 'active' => '1', 'free_above' => '50.00', 'delivery_days' => 'soon'], []);
    assert_equals([I18n::t('admin.shipping.free_above_zero'), I18n::t('admin.shipping.days_format')], [$r59hZero['error'], $r59hDays['error']],
        'R59 low: free above 0 (did nothing) and delivery days that are no number are refused, each with its own message (was: saved; "amount format")');
    assert_true(!str_starts_with(I18n::t('admin.shipping.free_above_zero'), 'admin.') && !str_starts_with(I18n::t('admin.shipping.days_format'), 'admin.'),
        'R59 low: both messages are translated');

    // 3. An unreadable settings file keeps Standard and Express off; the doctor names it.
    file_put_contents($r59hDir . '/' . DataOverrides::SHIPPING_METHODS, '{"methods": {"standard": {"active": false}');
    Shipping::forget();
    assert_equals([true, -1, -1], [DataOverrides::shippingMethods()['invalid'] ?? false, Shipping::calculate('standard', 'DE', 1000, 1000), Shipping::calculate('express', 'DE', 1000, 1000)],
        'R59 low: an unreadable settings file offers neither Standard nor Express (was: both on at shipping.json prices)');
    $r59hChecks = [];
    $r59hCfg = array_replace(require AppConfig::path($r59hRoot), ['private_runtime_dir' => $r59hDir]);
    foreach (Setup::doctor($r59hCfg, $r59hRoot, Db::get())['checks'] as $r59hCheck) $r59hChecks[$r59hCheck['name']] = $r59hCheck['status'];
    assert_equals('warn', $r59hChecks['shipping.methods_file'] ?? null, 'R59 low: the doctor warns about the unreadable settings file');

    // 4. A zone whose every price was removed leaves the checkout country list.
    file_put_contents($r59hDir . '/' . DataOverrides::SHIPPING_METHODS, json_encode(['methods' => ['standard' => ['rates' => ['eu' => null]], 'express' => ['rates' => ['eu' => null]]]]));
    Shipping::forget();
    assert_equals(['DE'], Shipping::zoneCountries(), 'R59 low: the EU zone without any price adds no destination countries (was: listed, and nothing delivered there)');
} catch (Throwable $e) { $r59hFail('settings', $e); } finally {
    I18n::setLocale($r59hLocale);
    DataOverrides::setDirForTesting(null);
    Shipping::setConfig(is_array($r59hFile) ? $r59hFile : []);
    Bootstrap::initStoreContext('/', '', 'default');
    @unlink($r59hDir . '/' . DataOverrides::SHIPPING_METHODS);
    @rmdir($r59hDir);
}
