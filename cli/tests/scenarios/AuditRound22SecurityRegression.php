<?php

// Review round 22 (2026-11-07), low "security" (goal #360):
// - on /cs//evil.example/ the 404 page's store switch linked "//evil.example/" (another site, protocol-relative): the
//   store prefix was cut from a path with a doubled slash. Older, R21 extended it to every prefixed store;
// - error messages had no length limit: a feed with a 1 MB price made a 9 MB admin page.
echo "\n--- R22: no off-site link from the store switch, error messages of bounded length ---\n";

$r22sSavedLocale = I18n::locale();
$r22sSavedStore = StoreContext::current();
$r22sSavedUri = $_SERVER['REQUEST_URI'] ?? null;
$r22sOffSite = static fn(string $url): bool => (bool)preg_match('#^[/\\\\]{2}|^[a-z][a-z0-9+.-]*:#i', substr($url, strlen(Template::baseUrl())));
try {
    assert_equals('/cs/evil.example/', Bootstrap::canonicalRequestPath('/cs//evil.example/'), 'R22 links: the request path collapses a doubled slash');
    foreach ([['/cs/', 'cz', 'cs', '/cs//evil.example/'], ['/hu/', 'hu', 'hu', '/hu//evil.example/x'], ['/', 'default', 'sk', '//evil.example/'],
              ['/cs/', 'cz', 'cs', '/cs/\\evil.example/']] as [$r22sBase, $r22sStore, $r22sLocale, $r22sUri]) {
        Bootstrap::initStoreContext($r22sBase, '', $r22sStore);
        I18n::setLocale($r22sLocale);
        foreach ([Bootstrap::canonicalRequestPath($r22sUri), $r22sUri] as $r22sPath) {
            $r22sAlt = Bootstrap::hreflangAlternates($r22sPath);
            $r22sUrls = [];
            foreach (['storeLinks', 'languageLinks'] as $r22sMethod) {
                foreach ((new ReflectionMethod(StorefrontSeo::class, $r22sMethod))->invoke(null, $r22sAlt) as $r22sLink) $r22sUrls[] = $r22sLink['url'];
            }
            $r22sBad = array_values(array_filter($r22sUrls, $r22sOffSite));
            assert_true($r22sUrls !== [] && $r22sBad === [] && str_starts_with($r22sAlt['pathNoPrefix'], '/') && !str_starts_with($r22sAlt['pathNoPrefix'], '//'),
                "R22 links: on {$r22sUri} ({$r22sStore}) no store or language link leaves the site (" . json_encode($r22sBad) . ')');
        }
    }
    // The page as rendered: a 404 under the CZ store's prefix.
    Bootstrap::initStoreContext('/cs/', '', 'cz');
    I18n::setLocale('cs');
    $_SERVER['REQUEST_URI'] = '/cs//evil.example/';
    $r22sDoc = StorefrontSeo::document('404', 'Not found', null);
    $r22sSk = array_values(array_filter($r22sDoc['store_links'], static fn(array $l): bool => str_starts_with($l['label'], 'SK')));
    assert_equals(['/evil.example/'], array_map(static fn(array $l): string => substr($l['url'], strlen(Template::baseUrl())), $r22sSk),
        'R22 links: the CZ 404 page links the SK store on its own site, not "//evil.example/"');
    assert_true(!str_contains((string)$r22sDoc['head'], '//evil.example'), 'R22 links: canonical and hreflang carry no doubled slash');
} catch (Throwable $e) {
    assert_true(false, 'R22 links (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    if ($r22sSavedUri === null) unset($_SERVER['REQUEST_URI']); else $_SERVER['REQUEST_URI'] = $r22sSavedUri;
    StoreContext::activate($r22sSavedStore);
    I18n::setLocale($r22sSavedLocale);
}

// Error messages: a huge value is quoted clipped, wherever the message is made or shown.
$r22sHuge = '1' . str_repeat('9', 1000000) . 'x';
$r22sMessage = static function (callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};
$r22sPriceError = $r22sMessage(static fn() => Import::parsePriceToCents($r22sHuge));
assert_true(str_starts_with($r22sPriceError, 'Not a price: 1999') && mb_strlen($r22sPriceError) < 200 && str_contains($r22sPriceError, '(1000002 characters)'),
    'R22 errors: "Not a price" quotes a 1 MB value clipped, with its length (' . mb_strlen($r22sPriceError) . ' characters)');
assert_equals('abc', Str::clip('abc'), 'R22 errors: a short message stays as it is');
assert_equals('žľť… (5 characters)', Str::clip('žľťčš', 3), 'R22 errors: clipping counts characters, not bytes');

[$r22sSavedDb, $r22sSavedDriver] = [Db::get(), Db::driver()];
$r22sStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r22sSavedStores = $r22sStoresProp->getValue();
$r22sPrevious = StoreContext::current();
$r22sLock = sys_get_temp_dir() . '/bbe-r22s-' . getmypid() . '.lock';
$r22sDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    (new Migrator($r22sDb, TEST_CLI_DIR . '/../migrations', $r22sLock))->apply(static fn($m) => null);
    Db::init($r22sDb, 'sqlite');
    $r22sSample = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    $r22sStoresProp->setValue(null, $r22sSample);
    StoreContext::activate(StoreContext::resolve($r22sSample, null, null, 'default', 'storefront'));

    // A feed item with a 1 MB price and a 100 KB SKU, and a CSV row with a 500 KB price.
    $r22sWh = Warehouse::save(['code' => 'r22s', 'name' => 'R22S', 'is_default' => 0]);
    $r22sFeed = Warehouse::feedById(Warehouse::saveFeed(['code' => 'r22s-feed', 'name' => 'r22s', 'warehouse_id' => $r22sWh,
        'url' => 'https://example.com/r22s.xml', 'format' => 'xml', 'import_mode' => FeedImport::MODE_UPSERT,
        'field_map' => ['SKU' => 'sku', 'NAME' => 'name', 'PRICE' => ['field' => 'price', 'transform' => 'price_to_cents'], 'STOCK' => 'stock']]));
    $r22sResult = FeedImport::applyItems($r22sFeed, [['SKU' => 'R22S-' . str_repeat('S', 100000), 'NAME' => 'R22S', 'PRICE' => $r22sHuge, 'STOCK' => '1'],
        ['SKU' => 'R22S-2', 'NAME' => 'R22S 2', 'PRICE' => $r22sHuge, 'STOCK' => '1']]);
    $r22sFeedErrors = array_map('strval', (array)($r22sResult['errors'] ?? []));
    $r22sLongest = max(array_map('mb_strlen', $r22sFeedErrors ?: ['']));
    assert_true(count($r22sFeedErrors) === 2 && $r22sLongest <= 340, "R22 errors: a feed item's error is at most about 300 characters (longest {$r22sLongest})");
    $r22sCsv = tempnam(sys_get_temp_dir(), 'r22s') . '.csv';
    file_put_contents($r22sCsv, "sku,name,price\nR22S-C,R22S C," . substr($r22sHuge, 0, 500000) . "x\n");
    $r22sCsvErrors = array_map('strval', (array)(Import::csv($r22sCsv)['errors'] ?? []));
    @unlink($r22sCsv);
    assert_true(count($r22sCsvErrors) === 1 && mb_strlen($r22sCsvErrors[0]) <= 340 && str_contains($r22sCsvErrors[0], 'Not a price: 1999'),
        'R22 errors: a CSV row\'s error quotes its price clipped (' . mb_substr($r22sCsvErrors[0] ?? '', 0, 400) . ')');

    // The admin message clips each listed line whatever made it.
    if (!function_exists('setFlash')) require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shared.php';
    $r22sSession = $_SESSION ?? null;
    $_SESSION = [];
    setFlash('err', 'x', [str_repeat('<b>', 400000)]);
    ob_start();
    flash();
    $r22sHtml = (string)ob_get_clean();
    $_SESSION = $r22sSession ?? [];
    assert_true(strlen($r22sHtml) < 3000, 'R22 errors: the admin message with a 1.2 MB line is ' . strlen($r22sHtml) . ' bytes (was over 4 MB)');
} catch (Throwable $e) {
    assert_true(false, 'R22 errors (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    Db::init($r22sSavedDb, $r22sSavedDriver);
    @unlink($r22sLock);
    $r22sStoresProp->setValue(null, $r22sSavedStores);
    StoreContext::activate($r22sPrevious);
}
