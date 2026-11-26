<?php

// Review round 25 (R60), medium 3: moving Standard/Express from a hand-edited shipping.json to Admin → Shipping. The edit
// stops the update (2.1.2 has no Admin → Shipping for them, so the CHANGELOG's "enter them there first" was impossible,
// and the 2.1.3 notes even said to edit the file); restoring the shipped file brought back 3.99 € / 7.99 € and lost the
// prices, as did any later release, because the admin kept only what differed from the file. Neither the doctor nor
// the admin said anything.
echo "\n--- R60: Standard/Express settings saved whole, shipping-import of an edited shipping.json, doctor and admin warn ---\n";
if (!function_exists('pageShipping')) {
    if (!function_exists('adminFieldErrors')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php';
}
$r60hRoot = dirname(TEST_CLI_DIR);
$r60hLocale = I18n::locale();
$r60hFilePath = $r60hRoot . '/shipping.json';
$r60hFile = json_decode((string)file_get_contents($r60hFilePath), true);
$r60hDir = test_fixture_path('r60-shipping');
@mkdir($r60hDir, 0700, true);
$r60hStores = ['default_store' => 'r60h', 'stores' => ['r60h' => ['name' => 'R60 shipping', 'default_locale' => 'en', 'currency' => 'eur', 'url_prefix' => '',
    'domains' => [], 'permissions' => ['*'], 'legal' => ['country' => 'DE']]]];
$r60hFresh = static function (array $file) use ($r60hDir, $r60hStores): void {
    DataOverrides::setDirForTesting($r60hDir);
    Shipping::setConfig($file);
    StoreContext::activate(StoreContext::resolve($r60hStores, '/', '', 'r60h'));
};
$r60hFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R60 shipping {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};

assert_true(in_array(hash_file('sha256', $r60hFilePath), Shipping::SHIPPED_SHA256, true) && !Shipping::fileEdited(),
    'R60: this release\'s shipping.json is a known shipped version (a release changing it adds its hash to Shipping::SHIPPED_SHA256)');

// 1. A saved method keeps its prices when a later release changes shipping.json.
try {
    $r60hFresh((array)$r60hFile);
    $r60hSaved = shippingFileMethodSettings('standard', ['method' => 'standard', 'active' => '1', 'free_above' => '50.00', 'delivery_days' => '5-7',
        'rates' => ['domestic' => ['base' => '3.99', 'per_kg' => '0'], 'eu' => ['base' => '7.99', 'per_kg' => '1.00'], 'international' => ['base' => '15.99', 'per_kg' => '2.00']]], []);
    assert_equals(null, $r60hSaved['error'], 'R60: the unchanged Standard form is accepted');
    file_put_contents($r60hDir . '/' . DataOverrides::SHIPPING_METHODS, json_encode(['methods' => $r60hSaved['settings']]));
    $r60hNext = $r60hFile;
    $r60hNext['methods']['standard']['rates']['domestic']['base'] = 499;
    $r60hNext['methods']['standard']['free_above'] = 9000;
    $r60hFresh($r60hNext);
    assert_equals([399, 0], [Shipping::calculate('standard', 'DE', 1000, 1000), Shipping::calculate('standard', 'DE', 1000, 6000)],
        'R60: a price saved in Admin → Shipping stays when a later shipping.json changes it (was: 4.99, free only from 90)');
} catch (Throwable $e) { $r60hFail('saved whole', $e); }
@unlink($r60hDir . '/' . DataOverrides::SHIPPING_METHODS);

// 2. shipping-import: the settings of a 2.1.2 shipping.json edited by hand (Standard 2.99 at home, no Express).
try {
    $r60hFresh((array)$r60hFile);
    $r60hEdited = $r60hFile;
    unset($r60hEdited['methods']['express']);
    $r60hEdited['methods']['standard']['rates']['domestic'] = ['base' => 299, 'per_kg' => 0];
    unset($r60hEdited['methods']['standard']['rates']['international'], $r60hEdited['methods']['standard']['free_above']);
    $r60hEdited['zones']['local'] = $r60hEdited['zones']['domestic'] = ['countries' => ['SK']];
    $r60hImport = Shipping::importSettings($r60hEdited, (array)$r60hFile);
    assert_equals([], $r60hImport['warnings'], 'R60 import: a 2.1.2 file (home zones named SK) raises no zone warning');
    DataOverrides::saveShippingMethods($r60hImport['methods']);
    Shipping::forget();
    assert_equals([299, 299, -1, -1], [Shipping::calculate('standard', 'DE', 1000, 1000), Shipping::calculate('standard', 'DE', 1000, 9000),
        Shipping::calculate('standard', 'US', 1000, 1000), Shipping::calculate('express', 'DE', 1000, 1000)],
        'R60 import: Standard costs what the edit charged (2.99, no free shipping, no international), Express the edit removed is off');
    $r60hEdited['methods']['standard']['rates']['cz'] = ['base' => 499, 'per_kg' => 0];
    $r60hEdited['zones']['eu'] = ['countries' => ['SK', 'CZ']];
    $r60hWarnings = Shipping::importSettings($r60hEdited, (array)$r60hFile)['warnings'];
    assert_true(count($r60hWarnings) === 2 && str_contains(implode(' ', $r60hWarnings), 'cz') && str_contains(implode(' ', $r60hWarnings), 'zone eu'),
        'R60 import: a zone of its own and changed countries, which Admin → Shipping cannot set, are reported (' . implode('; ', $r60hWarnings) . ')');
} catch (Throwable $e) { $r60hFail('import', $e); }
@unlink($r60hDir . '/' . DataOverrides::SHIPPING_METHODS);
Shipping::setConfig((array)$r60hFile);

// 3. The command refuses without a copy of the edit (the settings must lie over the shipped file).
$r60hCmd = proc_open([PHP_BINARY, TEST_CLI_DIR . '/setup.php', 'shipping-import'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r60hPipes, $r60hRoot);
$r60hOut = stream_get_contents($r60hPipes[1]) . stream_get_contents($r60hPipes[2]);
fclose($r60hPipes[1]); fclose($r60hPipes[2]);
assert_true(proc_close($r60hCmd) === 1 && str_contains($r60hOut, '--from=') && str_contains($r60hOut, 'git checkout shipping.json'),
    'R60 import: shipping-import without --from says how to copy the edit aside and restore the shipped file');

// 4. An edited shipping.json: the doctor and Admin → Shipping say so and how to move on.
$r60hOriginal = (string)file_get_contents($r60hFilePath);
try {
    file_put_contents($r60hFilePath, str_replace('"base": 399', '"base": 299', $r60hOriginal));
    clearstatcache();
    $r60hChecks = [];
    $r60hCfg = array_replace(require AppConfig::path($r60hRoot), ['private_runtime_dir' => $r60hDir]);
    foreach (Setup::doctor($r60hCfg, $r60hRoot, Db::get())['checks'] as $r60hCheck) $r60hChecks[$r60hCheck['name']] = $r60hCheck;
    assert_true(($r60hChecks['shipping.file_edited']['status'] ?? null) === 'warn' && str_contains((string)($r60hChecks['shipping.file_edited']['action'] ?? ''), 'shipping-import --from='),
        'R60 doctor: a hand-edited shipping.json is a warning naming shipping-import (was: silent)');
    $r60hFresh((array)json_decode((string)file_get_contents($r60hFilePath), true));
    I18n::setLocale('en');
    ob_start();
    try { pageShipping(); } finally { $r60hPage = (string)ob_get_clean(); }
    assert_true(str_contains($r60hPage, 'data-shipping-file-edited') && str_contains($r60hPage, 'shipping-import'),
        'R60 admin: Admin → Shipping warns about the hand-edited shipping.json');
} catch (Throwable $e) {
    $r60hFail('warnings', $e);
} finally {
    file_put_contents($r60hFilePath, $r60hOriginal);
    clearstatcache();
    I18n::setLocale($r60hLocale);
    Shipping::setConfig((array)$r60hFile);
    DataOverrides::setDirForTesting(null);
}
assert_true(!Shipping::fileEdited(), 'R60: shipping.json is back as shipped');
