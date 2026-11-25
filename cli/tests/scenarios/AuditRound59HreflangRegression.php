<?php
$pdo ??= test_commerce_fixture();

// Review round 24 (R59), finding 6: two stores of one language (a German shop and its /at store, both "de") were one
// hreflang "de": the later store's URL replaced the earlier one's on every page and in the sitemap, so the German
// home page was announced as the /at page and Google saw two pages claiming one code. The /at store also took the
// seller's country (DE), so its English version collided with the German store's and was not announced at all.
// Now each store sharing a language is announced by its country (de-DE, de-AT), which Admin → Stores sets.
echo "\n--- R59 hreflang: stores sharing a language, the store's country ---\n";

$r59hRoot = dirname(TEST_CLI_DIR);
$r59hDir = test_fixture_path('r59-hreflang');
@mkdir($r59hDir, 0700, true);
$r59hStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r59hStoresBefore = $r59hStoresProp->getValue();
$r59hSession = $_SESSION ?? [];
$r59hLocale = I18n::locale();
$r59hFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R59 hreflang {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
// With APP_URL configured (CI) hrefs are APP_URL + shop-relative path; without it they carry the base path (/cli).
$r59hHref = static function (string $href): string {
    $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
    $appUrl = Security::configuredAppUrl();
    if ($appUrl !== null && str_starts_with($href, $appUrl)) return substr($href, strlen($appUrl));
    return substr($href, strlen(Template::baseUrl()));
};
$r59hLinks = static function (string $head) use ($r59hHref): array {
    preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]*)"#', $head, $m);
    return array_combine($m[1], array_map($r59hHref, $m[2]));
};
try {
    // A German web install (its store "de" on the empty prefix) adds the Austrian store /at in German, both with English.
    $r59hExample = json_decode((string)file_get_contents($r59hRoot . '/stores.example.json'), true);
    $r59hInstalled = WebInstaller::withIdentity((array)$r59hExample, ['timezone' => 'Europe/Berlin', 'shop_locale' => 'de', 'currency' => 'eur', 'company' => 'R59 GmbH',
        'city' => 'Berlin', 'country' => 'DE', 'vat_id' => 'DE123456789', 'iban' => 'DE89 3704 0044 0532 0130 00', 'shop_name' => 'R59 DE', 'street' => 'Hauptstr. 1',
        'zip' => '10115', 'ico' => '', 'dic' => '', 'bic' => '', 'email' => 'info@r59-de.test']);
    $r59hPath = "{$r59hDir}/stores.json";
    file_put_contents($r59hPath, json_encode($r59hInstalled, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    StoreSettings::setPathForTesting($r59hPath);
    $r59hDefault = (string)StoreSettings::load()['default_store'];
    $r59hAdded = StoreSettings::addStore(['id' => 'at', 'url_prefix' => '/at', 'default_locale' => 'de', 'currency' => 'eur', 'name' => 'R59 AT']);
    $r59hBase = ['oss_enabled' => '', 'public' => '1', 'timezone' => 'Europe/Vienna'];
    $r59hEn = StoreSettings::save($r59hDefault, $r59hBase + ['store_name' => 'R59 DE', 'languages' => ['de', 'en']] + array_map(static fn($v): string => is_scalar($v) ? (string)$v : '', (array)(StoreSettings::load()['stores'][$r59hDefault]['legal'] ?? [])));
    $r59hBadCountry = StoreSettings::save('at', $r59hBase + ['store_name' => 'R59 AT', 'languages' => ['de', 'en'], 'market_country' => 'AUT']);
    $r59hCountryBefore = StoreSettings::load()['stores']['at']['country'] ?? null;
    $r59hSaved = StoreSettings::save('at', $r59hBase + ['store_name' => 'R59 AT', 'languages' => ['de', 'en'], 'market_country' => 'at']);
    $r59hKept = StoreSettings::save('at', $r59hBase + ['store_name' => 'R59 AT']);
    $r59hRootCfg = StoreSettings::load();
    assert_equals([true, true, false, null, true, true, 'AT', ['de', 'en']],
        [$r59hAdded['ok'], $r59hEn['ok'], $r59hBadCountry['ok'], $r59hCountryBefore, $r59hSaved['ok'], $r59hKept['ok'], $r59hRootCfg['stores']['at']['country'] ?? null, $r59hRootCfg['stores']['at']['languages'] ?? null],
        'R59 finding 6: Admin → Stores saves the store\'s country (at → AT), refuses "AUT", a form without the field keeps it (was: stores.json only): '
        . implode(' ', array_merge($r59hAdded['errors'], $r59hEn['errors'], $r59hSaved['errors'], $r59hKept['errors'])));
    $r59hStoresProp->setValue(null, $r59hRootCfg);
    StoreContext::activate(StoreContext::resolve($r59hRootCfg, '/at/', '', 'at'));

    $r59hAlt = Bootstrap::hreflangAlternates('/at/');
    assert_equals([['de-DE' => '', 'de-AT' => '/at'], ['de-DE' => 'de', 'de-AT' => 'de'], ['en' => $r59hDefault, 'en-AT' => 'at'], '/'],
        [$r59hAlt['alternates'], $r59hAlt['locales'] ?? null, array_intersect_key($r59hAlt['stores'], $r59hAlt['secondary'] + $r59hAlt['regional']), $r59hAlt['pathNoPrefix']],
        'R59 finding 6: the German store and its /at store are de-DE and de-AT, the English pages en (the first store\'s) and en-AT (was: one "de", the /at store\'s, and no English /at page)');

    $r59hPage = $r59hLinks((new ReflectionMethod(StorefrontSeo::class, 'hreflang'))->invoke(null, Bootstrap::hreflangAlternates('/at/?page=contact')));
    assert_equals(['de-DE' => '/?page=contact', 'de-AT' => '/at/?page=contact', 'en' => '/?page=contact&lang=en', 'en-AT' => '/at/?page=contact&lang=en', 'x-default' => '/?page=contact'],
        $r59hPage, 'R59 finding 6: the /at page announces both German pages and both English ones, each once');

    $r59hHead = Seo::head('T', 'D', '/at/');
    preg_match('#<link rel="canonical" href="([^"]*)"#', $r59hHead, $r59hCanonical);
    $r59hHeadLinks = $r59hLinks($r59hHead);
    assert_equals(['/at/', '/at/', '/'], [$r59hHref($r59hCanonical[1] ?? ''), $r59hHeadLinks['de-AT'] ?? null, $r59hHeadLinks['de-DE'] ?? null],
        'R59 finding 6: the /at page\'s canonical URL is its own de-AT alternate, the German store\'s is de-DE (was: "de" pointed at one of them only)');

    $r59hXml = Sitemap::xml('https://shop.r59.example');
    preg_match('#<url>\s*<loc>https://shop\.r59\.example/at/</loc>(.*?)</url>#s', $r59hXml, $r59hHome);
    preg_match_all('#hreflang="([^"]+)" href="([^"]*)"#', $r59hHome[1] ?? '', $r59hM);
    assert_equals(['de-DE' => 'https://shop.r59.example/', 'de-AT' => 'https://shop.r59.example/at/', 'en' => 'https://shop.r59.example/?lang=en',
        'en-AT' => 'https://shop.r59.example/at/?lang=en', 'x-default' => 'https://shop.r59.example/'], array_combine($r59hM[1], $r59hM[2]),
        'R59 finding 6: the sitemap announces the same alternates (was: "de" the German store only, the /at store not at all)');

    // The admin form: the field with the country the store sells to now as its placeholder.
    if (!function_exists('pageStoreSettings')) {
        if (!function_exists('adminFieldErrors')) require_once $r59hRoot . '/src/Entrypoints/Admin/Shared.php';
        require_once $r59hRoot . '/src/Entrypoints/Admin/StoreManagement.php';
        require_once $r59hRoot . '/src/Entrypoints/Admin/StoreSettings.php';
    }
    $_SESSION = ['admin_role' => 'owner', 'admin_user' => 'r59', 'csrf_token' => 'r59-token'];
    I18n::setLocale('en');
    ob_start();
    try { pageStoreSettings(); } finally { $r59hForm = (string)ob_get_clean(); }
    $r59hAtForm = (string)(preg_match('#data-store-settings="at".*?</form>#s', $r59hForm, $r59hFm) ? $r59hFm[0] : '');
    assert_true(str_contains($r59hAtForm, 'name="market_country" value="AT"') && str_contains($r59hAtForm, 'placeholder="DE"')
        && str_contains($r59hAtForm, I18n::t('admin.stores.field.market_country')),
        'R59 finding 6: the store form has the store\'s country (AT), the seller\'s DE shown when it is empty (was: no field)');

    // Cleared: the store takes the seller's country again; two stores of one language and country keep one code each.
    $r59hCleared = StoreSettings::save('at', $r59hBase + ['store_name' => 'R59 AT', 'market_country' => '']);
    $r59hStoresProp->setValue(null, StoreSettings::load());
    $r59hSame = Bootstrap::hreflangAlternates('/');
    assert_equals([true, false, ['de-DE' => '']], [$r59hCleared['ok'], array_key_exists('country', StoreSettings::load()['stores']['at']), $r59hSame['alternates']],
        'R59 finding 6: an empty country is removed; two German stores of one country keep one de-DE (the first), never one URL per code twice');
} catch (Throwable $e) { $r59hFail('stores sharing a language', $e); }

$_SESSION = $r59hSession;
I18n::setLocale($r59hLocale);
StoreSettings::setPathForTesting(null);
$r59hStoresProp->setValue(null, $r59hStoresBefore);
Bootstrap::initStoreContext('/', '', 'default');
foreach (glob($r59hDir . '/*') ?: [] as $r59hFile) @unlink($r59hFile);
@rmdir($r59hDir);
