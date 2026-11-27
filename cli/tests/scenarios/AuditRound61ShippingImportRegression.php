<?php

// Review round 26 (R61), lows 6–9 (goal #654): shipping-import and the names and delivery days of older records.
//  6: shipping-import reported "zone eu: its countries differ" for every real 2.1.x file (its eu zone has no SK), and
//     sent the merchant to Admin → zones, which do not set these countries. The R60 test built its "2.1.2 file" from the
//     new one, so it never saw that.
//  7: shipping-import took free-text delivery days ("2-3 working days") that Admin → Shipping then refused on every save.
//  8: a name saved with a tab or U+2028 before R60, or text delivery days, locked the record ("code and name are required").
//  9: the doctor check shipping.file_edited had no title and no fix page; the admin text said "Standard and Express" in
//     English in every language.
echo "\n--- Audit round 61: shipping-import of a real 2.1.2 file, delivery days, older names, shipping doctor texts ---\n";
if (!function_exists('pageShipping')) {
    if (!function_exists('adminFieldErrors')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php';
}
$r61sRepo = dirname(TEST_CLI_DIR);
$r61sLocale = I18n::locale();
$r61sFile = (array)json_decode((string)file_get_contents($r61sRepo . '/shipping.json'), true);
$r61sOld = (array)json_decode((string)file_get_contents(TEST_CLI_DIR . '/tests/fixtures/shipping-2.1.2.json'), true);
$r61sDir = test_fixture_path('r61-shipping');
@mkdir($r61sDir, 0700, true);
$r61sFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R61 shipping {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};

try {
    assert_equals('d15f263e1c322e7b0fd1b4d839d89d973fcc4134df4c13803c814f7a9bbe2384', hash_file('sha256', TEST_CLI_DIR . '/tests/fixtures/shipping-2.1.2.json'),
        'R61 import: the fixture is shipping.json exactly as 2.1.2 shipped it');
    // Low 6: the 2.1.2 file with one price changed by hand.
    $r61sEdited = $r61sOld;
    $r61sEdited['methods']['standard']['rates']['domestic']['base'] = 299;
    $r61sImport = Shipping::importSettings($r61sEdited, $r61sFile);
    assert_equals([[], 299], [$r61sImport['warnings'], $r61sImport['methods']['standard']['rates']['domestic']['base'] ?? null],
        'R61 import: a real 2.1.2 file (eu zone without SK) imports its price without a zone warning (was: "zone eu: its countries differ")');
    $r61sEdited['zones']['eu']['countries'] = ['CZ', 'DE'];
    $r61sZone = Shipping::importSettings($r61sEdited, $r61sFile)['warnings'];
    assert_true(count($r61sZone) === 1 && str_contains($r61sZone[0], 'zone eu') && !str_contains($r61sZone[0], 'Admin → Shipping → zones'),
        'R61 import: countries really changed are still reported, without sending the merchant to the zones page (' . implode('; ', $r61sZone) . ')');

    // Low 7: delivery days of free text.
    $r61sEdited = $r61sOld;
    $r61sEdited['methods']['standard']['delivery_days'] = '2-3 working days';
    $r61sEdited['methods']['express']['delivery_days'] = 'next day';
    $r61sDays = Shipping::importSettings($r61sEdited, $r61sFile);
    assert_equals(['2-3', '1-2'], [$r61sDays['methods']['standard']['delivery_days'], $r61sDays['methods']['express']['delivery_days']],
        'R61 import: "2-3 working days" is imported as 2-3; a text without a number keeps the shipped days (was: the text, refused by every save)');
    assert_true(count($r61sDays['warnings']) === 2 && str_contains(implode(' ', $r61sDays['warnings']), 'delivery days'),
        'R61 import: both changed delivery days are reported');
    foreach ($r61sDays['methods'] as $r61sMethod) {
        assert_true(Shipping::deliveryDaysAccepted($r61sMethod['delivery_days']), 'R61 import: imported delivery days are ones Admin → Shipping takes');
    }

    // Low 8: delivery days saved as text before R60 stay editable; a changed text is still refused.
    DataOverrides::setDirForTesting($r61sDir);
    Shipping::setConfig($r61sFile);
    $r61sLegacy = Shipping::methodSettings($r61sFile['methods']['standard'], Shipping::editableZones($r61sFile));
    $r61sLegacy['delivery_days'] = '2-3 working days';
    DataOverrides::saveShippingMethods(['standard' => $r61sLegacy]);
    Shipping::forget();
    $r61sPost = ['method' => 'standard', 'free_above' => '50.00', 'delivery_days' => '2-3 working days',
        'rates' => ['domestic' => ['base' => '3.99', 'per_kg' => '0'], 'eu' => ['base' => '7.99', 'per_kg' => '1.00'], 'international' => ['base' => '15.99', 'per_kg' => '2.00']]];
    $r61sOff = shippingFileMethodSettings('standard', $r61sPost, ['standard' => $r61sLegacy]);
    assert_equals([null, false], [$r61sOff['error'], $r61sOff['settings']['standard']['active'] ?? null],
        'R61 admin: Standard with text delivery days saved before R60 can be turned off (was: "Days: a number or a range")');
    $r61sChanged = shippingFileMethodSettings('standard', ['delivery_days' => '3 business days'] + $r61sPost, ['standard' => $r61sLegacy]);
    assert_true($r61sChanged['error'] !== null, 'R61 admin: a new text in delivery days is still refused');
    assert_true(Shipping::deliveryDaysAccepted('2-3 working days', '2-3 working days') && !Shipping::deliveryDaysAccepted('3 business days', '2-3 working days'),
        'R61 admin: a rate\'s delivery days saved as text stay as they are; another text is refused');

    // Low 8: names with a tab or line separator inside are saved with a space; a name that shows nothing says so.
    I18n::setLocale('en');
    $_POST = ['name' => "Packeta\tPoint\u{2028}Plus"];
    assert_equals('Packeta Point Plus', postedMethodName(), 'R61 admin: a tab or U+2028 inside a name becomes a space (was: refused, record locked)');
    $_POST = ['name' => "\u{200B}\u{E000}"];
    assert_equals(['', I18n::t('admin.common.name_invisible')], [postedMethodName(), methodSaveError('admin.common.code_name_required')],
        'R61 admin: a name that shows nothing is refused with its own reason (was: "Code and name are required")');
    $_POST = ['name' => ''];
    assert_equals(I18n::t('admin.common.code_name_required'), methodSaveError('admin.common.code_name_required'), 'R61 admin: an empty name is still "required"');
} catch (Throwable $e) {
    $r61sFail('import and admin', $e);
} finally {
    $_POST = [];
    @unlink($r61sDir . '/' . DataOverrides::SHIPPING_METHODS);
    @rmdir($r61sDir);
    DataOverrides::setDirForTesting(null);
    Shipping::setConfig($r61sFile);
    I18n::setLocale($r61sLocale);
}

// Low 9: the doctor check has a title and a fix page in every language; the admin text names the methods in the language.
try {
    assert_equals(['shipping', 'shipping'], [SetupChecklist::fixPage('shipping.file_edited'), SetupChecklist::fixPage('shipping.methods_file')],
        'R61 doctor: shipping.file_edited and shipping.methods_file link to Admin → Shipping (was: no "Fix")');
    foreach (glob($r61sRepo . '/lang/*.php') ?: [] as $r61sLang) {
        $r61sCode = basename($r61sLang, '.php');
        $r61sTexts = require $r61sLang;
        $r61sFileEdited = (string)($r61sTexts['admin.shipping.file_edited'] ?? '');
        assert_true(($r61sTexts['admin.doctor.shipping_file_edited'] ?? '') !== '' && ($r61sTexts['admin.doctor.shipping_methods_file'] ?? '') !== '',
            "R61 doctor {$r61sCode}: the shipping checks have a title");
        assert_true(str_contains($r61sFileEdited, '{methods}') && !str_contains($r61sFileEdited, 'Standard'),
            "R61 admin {$r61sCode}: the hand-edit warning names the methods in the language (was: \"Standard and Express\")");
    }
    I18n::setLocale('sk');
    assert_true(str_contains(I18n::format('admin.shipping.file_edited', ['methods' => 'Štandardné doručenie']), 'Štandardné doručenie')
        && SetupChecklist::title('shipping.file_edited') === I18n::t('admin.doctor.shipping_file_edited'),
        'R61 doctor sk: the dashboard shows the Slovak title');
} catch (Throwable $e) {
    $r61sFail('doctor texts', $e);
} finally {
    I18n::setLocale($r61sLocale);
}
