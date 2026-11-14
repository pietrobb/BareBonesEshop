<?php

// Review round 36 (2026-11-13), low (goal #459): a profile edit with a name or phone over 191 characters or not UTF-8,
// and a new address with text that is not UTF-8, failed on MySQL with a 500 ("Data too long" / "Incorrect string
// value"); long values without spaces pushed the account page sideways at 390 px; saved addresses did not show IČO/DIČ.
echo "\n--- R36: account profile and address checks, long values, company IDs on saved addresses ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r36aBad = "R36A \xC3\x28 bad";
$r36aLong = str_repeat('ž', 192);
try {
    // 1. Profile: refused per field, nothing saved.
    assert_equals(['name' => 'account.err_address_too_long', 'phone' => 'account.err_address_too_long'],
        Customers::profileErrors(['name' => $r36aLong, 'phone' => $r36aLong]), 'R36 account: a 192-character profile name and phone are refused per field (was a MySQL 500)');
    assert_equals(['name' => 'account.err_invalid_text', 'phone' => 'account.err_invalid_text'],
        Customers::profileErrors(['name' => $r36aBad, 'phone' => "\xFF"]), 'R36 account: a profile name and phone that are not UTF-8 are refused per field (was a MySQL 500)');
    assert_equals([], Customers::profileErrors(['name' => str_repeat('ž', 191), 'phone' => '+421 900 123 456']), 'R36 account: 191 characters (multibyte) and a phone still fit');

    // 2. Address: text that is not UTF-8 is a field error in every text field.
    $base = ['type' => 'billing', 'name' => 'R36A Buyer', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'];
    assert_equals(['name' => 'account.err_invalid_text', 'company' => 'account.err_invalid_text', 'ico' => 'account.err_invalid_text', 'street' => 'account.err_invalid_text'],
        Customers::addressErrors(['name' => $r36aBad, 'company' => $r36aBad, 'street' => $r36aBad, 'ico' => "\xC3"] + $base),
        'R36 account: an address with text that is not UTF-8 is refused per field (was a MySQL 500)');
    assert_equals([], Customers::addressErrors($base), 'R36 account: a valid address still passes');

    // 3. The refused profile shows what was typed with the error at its field.
    $model = AccountViewModel::build('profile', ['id' => 1, 'email' => 'r36a@example.test', 'name' => 'Old', 'phone' => '', 'locale' => 'en'], ['en'],
        ['name' => $r36aLong, 'phone' => ''], 'x', 'error', null, Customers::profileErrors(['name' => $r36aLong, 'phone' => '']))['account'];
    assert_true(($model['profile']['name'] ?? null) === $r36aLong && ($model['profile_errors']['name'] ?? '') === I18n::t('account.err_address_too_long') && !isset($model['profile_errors']['phone']),
        'R36 account: the profile page shows the typed name and its error');
    $template = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/themes/default/blocks/storefront/account-page.html');
    assert_true(str_contains($template, 'aria-describedby="account-profile-name-error"') && str_contains($template, 'aria-describedby="account-profile-phone-error"'),
        'R36 account: the profile fields carry their error (aria-invalid, aria-describedby)');
    $entry = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/Account.php');
    assert_true(preg_match('/if \(\$fieldErrors = Customers::profileErrors\(\$profile\)\).*?\} else \{\s*Customers::update\(\(int\)\$_SESSION\[\'customer_id\'\], \$profile\);/s', $entry) === 1,
        'R36 account: the profile is saved only when it has no field errors');

    // 4. Saved addresses show IČO, DIČ and VAT ID; long values without spaces wrap inside the card.
    assert_true(str_contains($template, '{{#if ico}}') && str_contains($template, '{{#if dic}}') && str_contains($template, '{{#if vat_id}}'),
        'R36 account: a saved address shows its IČO, DIČ and VAT ID');
    $css = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/themes/default/assets/storefront.css');
    assert_true(str_contains($css, '.addr-card{border:1px solid #e2e8f0;border-radius:4px;padding:12px;min-width:0;overflow-wrap:anywhere}') && str_contains($css, '.account-page .card{overflow-wrap:anywhere}'),
        'R36 account: long values without spaces wrap instead of pushing the account page sideways at 390 px');

    foreach (['account.err_invalid_text', 'account.err_profile_invalid'] as $key) {
        foreach (glob(dirname(TEST_CLI_DIR) . '/lang/*.php') ?: [] as $langFile) {
            $strings = require $langFile;
            assert_true(trim((string)($strings[$key] ?? '')) !== '', "R36 account: {$key} is translated in " . basename($langFile, '.php'));
        }
    }
} catch (Throwable $e) {
    assert_true(false, 'R36 account (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
