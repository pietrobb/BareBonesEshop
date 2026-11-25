<?php
$pdo ??= test_commerce_fixture();

// Review round 24 (2026-11-25, main @ 2913aec7), finding 4 and the seller lows (goal #636): a store sharing a seller
// (legal.seller) could set country, vat_registered and the VAT IDs in its own legal block, so one VAT payer charged and
// invoiced another VAT in one number series (0 % "not a VAT payer" under a Czech VAT ID); neither the doctor nor the
// admin said so. Lows: vat-backfill read OSS of the store, not its seller; a store without a legal block had the
// Bratislava clock whoever it sells as; the WebSite schema was named after the company; two stores of one country and
// currency lost the country in the store switcher; the sitemap kept the old languages for 15 minutes; a store whose
// legal.seller names no seller took orders it cannot invoice.
echo "\n--- R59: seller tax identity, seller lows ---\n";

$r59sStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r59sStoresBefore = $r59sStoresProp->getValue();
$r59sFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R59 seller {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r59sRoot = ['default_locale' => 'cs', 'default_currency' => 'czk', 'default_store' => 'cz', 'stores' => [
    'cz' => ['id' => 'cz', 'name' => 'R59 CZ', 'currency' => 'czk', 'default_locale' => 'cs', 'url_prefix' => '', 'oss_enabled' => true,
        'legal' => ['name' => 'R59 s.r.o.', 'country' => 'CZ', 'vat_registered' => true, 'ic_dph' => 'CZ12345678', 'dic' => 'CZ12345678', 'ico' => '12345678',
            'street' => 'Hlavni 1', 'city' => 'Praha', 'zip' => '11000', 'invoice_prefix' => 'R59C', 'credit_note_prefix' => 'R59CC']],
    // Shares the Czech seller and claims another identity in its own block.
    'sk' => ['id' => 'sk', 'name' => 'R59 SK', 'currency' => 'eur', 'default_locale' => 'sk', 'url_prefix' => '/sk',
        'legal' => ['seller' => 'cz', 'country' => 'SK', 'vat_registered' => false, 'name' => 'R59 Other s.r.o.', 'ic_dph' => 'CZ12345678', 'email' => 'sk@r59.test']],
    // No legal block: sells as the seller of its currency, the Czech one.
    'plain' => ['id' => 'plain', 'name' => 'R59 Plain', 'currency' => 'czk', 'default_locale' => 'cs', 'url_prefix' => '/plain'],
    'broken' => ['id' => 'broken', 'name' => 'R59 Broken', 'currency' => 'eur', 'default_locale' => 'en', 'url_prefix' => '/broken', 'legal' => ['seller' => 'gone']],
]];

try {
    $r59sStoresProp->setValue(null, $r59sRoot);
    // 1. Finding 4: the seller's tax identity wins; the store's other fields (its e-mail) still print.
    $r59sSk = SellerLegal::forStore($r59sRoot, 'sk');
    assert_equals(['CZ', true, 'R59 s.r.o.', 'CZ12345678', 'sk@r59.test'],
        [$r59sSk['country'] ?? null, $r59sSk['vat_registered'] ?? null, $r59sSk['name'] ?? null, $r59sSk['ic_dph'] ?? null, $r59sSk['email'] ?? null],
        'R59 finding 4: a store sharing a seller invoices under the seller\'s country, VAT status and name (was: its own SK, not a VAT payer)');
    assert_equals([['name', 'country', 'vat_registered'], [], [], ['name', 'country', 'vat_registered']],
        [array_keys(SellerLegal::identityOverrides($r59sRoot, 'sk')), SellerLegal::identityOverrides($r59sRoot, 'cz'), SellerLegal::identityOverrides($r59sRoot, 'plain'),
            StoreSettings::identityIgnored($r59sRoot, 'sk')],
        'R59 finding 4: the ignored identity fields are listed for the store sharing the seller (ic_dph equal to the seller\'s is not), none for a seller');

    // 2. The doctor warns about them.
    $r59sDir = test_fixture_path('r59-seller');
    @mkdir($r59sDir, 0700, true);
    file_put_contents($r59sDir . '/stores.json', json_encode($r59sRoot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $r59sChecks = [];
    $r59sCfg = array_replace(require AppConfig::path(dirname(TEST_CLI_DIR)), ['private_runtime_dir' => $r59sDir]);
    foreach (Setup::doctor($r59sCfg, dirname(TEST_CLI_DIR), Db::get())['checks'] as $r59sCheck) $r59sChecks[$r59sCheck['name']] = $r59sCheck;
    $r59sWarn = $r59sChecks['stores.legal_identity.sk'] ?? [];
    assert_true(($r59sWarn['status'] ?? '') === 'warn' && str_contains((string)($r59sWarn['message'] ?? ''), 'vat_registered=false')
        && str_contains((string)($r59sWarn['message'] ?? ''), 'country="SK"') && !isset($r59sChecks['stores.legal_identity.cz']),
        'R59 finding 4: the doctor warns that the store\'s identity fields are ignored (' . json_encode($r59sWarn, JSON_UNESCAPED_UNICODE) . ')');
    foreach (glob($r59sDir . '/*') ?: [] as $r59sFile) @unlink($r59sFile);
    @rmdir($r59sDir);
} catch (Throwable $e) { $r59sFail('identity', $e); }

// 3. Lows: the clock of a store without a legal block, OSS of vat-backfill, the WebSite schema, the store switcher.
try {
    $r59sStoresProp->setValue(null, $r59sRoot);
    assert_equals(['Europe/Prague', 'Europe/Prague'], [StoreContext::timezoneFor($r59sRoot['stores']['plain']), StoreContext::timezoneFor($r59sRoot['stores']['sk'])],
        'R59 low: a store without a legal block keeps the clock of the Czech seller it sells as (was: Europe/Bratislava)');
    if (!function_exists('vatBackfillOrder')) {
        define('BB_TEST_LOAD_VAT_BACKFILL_ONLY', true);
        require TEST_CLI_DIR . '/vat-backfill.php';
    }
    assert_equals([['country' => 'CZ', 'oss' => true], ['country' => 'CZ', 'oss' => true]], [vatBackfillSeller($r59sRoot, 'sk'), vatBackfillSeller($r59sRoot, 'plain')],
        'R59 low: vat-backfill reads OSS of the seller (the Czech store has it on), not of the storefront (was: off)');
    assert_equals(['R59 SK', 'R59 s.r.o.'], [JsonLd::webSite('https://shop.example', $r59sRoot, 'sk')['name'], JsonLd::organization('https://shop.example', $r59sRoot, 'sk')['name']],
        'R59 low: the WebSite schema is named after the storefront, the Organization after the company (was: the company for both)');

    StoreContext::activate(StoreContext::resolve($r59sRoot, '/', '', 'cz'));
    $r59sLinks = array_column((new ReflectionMethod(StorefrontSeo::class, 'storeLinks'))->invoke(null, Bootstrap::hreflangAlternates('/')), 'label', 'title');
    $r59sCzk = I18n::priceFormat('czk')['symbol'];
    assert_equals(["CZ · {$r59sCzk} · R59 CZ", "CZ · {$r59sCzk} · R59 Plain"], [$r59sLinks['R59 CZ'] ?? null, $r59sLinks['R59 Plain'] ?? null],
        'R59 low: two stores of one country and currency keep the country and currency in the switcher, their names after it (was: the names alone): ' . json_encode($r59sLinks, JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) { $r59sFail('lows', $e); }

// 4. The sitemap cache follows the store configuration (a language added in Admin → Stores).
try {
    $r59sStoresProp->setValue(null, $r59sRoot);
    $r59sBuilt = 0;
    $r59sWriter = static function ($out) use (&$r59sBuilt): void { $r59sBuilt++; fwrite($out, 'sitemap ' . json_encode(Bootstrap::stores()['stores']['cz']['languages'] ?? [])); };
    $r59sSend = static function () use ($r59sWriter): string { ob_start(); PublicFeedCache::send('r59-sitemap', 'https://shop.example', $r59sWriter); return (string)ob_get_clean(); };
    $r59sFirst = [$r59sSend(), $r59sSend()];
    $r59sLanguages = $r59sRoot;
    $r59sLanguages['stores']['cz']['languages'] = ['en'];
    $r59sStoresProp->setValue(null, $r59sLanguages);
    $r59sAfter = $r59sSend();
    assert_equals([1, 2, 'sitemap ["en"]'], [$r59sFirst[0] === $r59sFirst[1] ? 1 : 0, $r59sBuilt, $r59sAfter],
        'R59 low: the cached sitemap is served while nothing changes and rebuilt once the store languages change (was: after 15 minutes)');
} catch (Throwable $e) { $r59sFail('sitemap', $e); }

// 5. A store whose legal.seller names no seller refuses checkout before it takes the order (InvoiceService cannot invoice it).
try {
    $r59sStoresProp->setValue(null, $r59sRoot);
    StoreContext::activate(StoreContext::resolve($r59sRoot, '/broken/', '', 'broken'));
    $r59sCode = http_response_code();
    $r59sResult = CheckoutService::execute(['name' => 'R59 Buyer', 'email' => 'buyer@r59.test', 'country' => 'SK', 'street' => 'Ulica 1', 'city' => 'Bratislava', 'zip' => '81101',
        'shipping_method' => 'pickup', 'payment_method' => 'bank_transfer']);
    assert_equals([false, 'checkout.seller_missing', 503], [$r59sResult['success'] ?? null, $r59sResult['error_code'] ?? null, http_response_code()],
        'R59 low: checkout of a store whose legal.seller names no seller is refused (was: the order and its payment were taken, the invoice failed)');
    http_response_code(is_int($r59sCode) ? $r59sCode : 200);
} catch (Throwable $e) { $r59sFail('seller missing', $e); }

$r59sStoresProp->setValue(null, $r59sStoresBefore);
Bootstrap::initStoreContext('/', '', 'default');
