<?php

// Review round 21 (2026-11-07), low "store switch and SEO": after R20 the SK and CZ stores no longer linked each other
// ("CS" in the SK store was Czech in euros), "HU" in a store switched store and repriced the cart unannounced,
// /product/x?lang=cs had the Slovak page as canonical, and English in the HU store had no hreflang.
echo "\n--- R21: a store switch apart from languages, self-canonical store languages, hreflang per store language ---\n";

$r21sSavedLocale = I18n::locale();
$r21sSavedStore = StoreContext::current();
$r21sSavedGet = $_GET;
$r21sSavedSession = $_SESSION ?? null;
$r21sLinks = static function (string $method, string $uri = '/?page=cart'): array {
    $links = [];
    foreach ((new ReflectionMethod(StorefrontSeo::class, $method))->invoke(null, Bootstrap::hreflangAlternates($uri)) as $link) {
        $links[$link['label']] = substr($link['url'], strlen(Template::baseUrl())) . ($link['active'] ? ' *' : '');
    }
    return $links;
};
$r21sCanonical = static fn(string $head): string => (string)(preg_match('#<link rel="canonical" href="([^"]*)"#', $head, $m) ? $m[1] : '');
$r21sHreflang = static function (string $head): array {
    preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]*)"#', $head, $m);
    return array_combine($m[1], array_map(static fn(string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5), $m[2]));
};
try {
    // The SK store: its languages stay in it; every store is reachable from the store switch by country and currency.
    Bootstrap::initStoreContext('/', '', 'default');
    I18n::setLocale('sk');
    unset($_GET['lang']);
    assert_equals(['SK' => '/?page=cart *', 'CS' => '/?page=cart&lang=cs', 'EN' => '/?page=cart&lang=en'], $r21sLinks('languageLinks'),
        'R21 switcher: the SK store\'s language links are its own languages (CS is Czech in this store)');
    $r21sSk = $r21sLinks('storeLinks');
    // R22: the CZ store has Slovak too, so the shopper stays in Slovak there.
    assert_equals(['SK · €' => '/?page=cart *', 'CZ · Kč' => '/cs/?page=cart&lang=sk', 'HU · Ft' => '/hu/?page=cart'],
        array_intersect_key($r21sSk, array_flip(['SK · €', 'CZ · Kč', 'HU · Ft'])),
        'R21 switcher: the SK store links the CZ and HU stores by country and currency (the CZ store was unreachable) (' . json_encode($r21sSk, JSON_UNESCAPED_UNICODE) . ')');
    Bootstrap::initStoreContext('/cs/', '', 'cz');
    I18n::setLocale('cs');
    $r21sCz = $r21sLinks('storeLinks');
    assert_equals(['/?page=cart&lang=cs', '/cs/?page=cart *'], [$r21sCz['SK · €'] ?? null, $r21sCz['CZ · Kč'] ?? null], 'R21 switcher: the CZ store links back to the SK store');
    assert_true(!isset($r21sLinks('languageLinks')['HU']), 'R21 switcher: HU is a store, not a language link of the CZ store');
    $r21sTitles = array_column((new ReflectionMethod(StorefrontSeo::class, 'storeLinks'))->invoke(null, Bootstrap::hreflangAlternates('/')), 'title');
    assert_true(in_array('Magyar üzlet', $r21sTitles, true), 'R21 switcher: a store link is titled with the store name');

    // Czech chosen in the SK store is its own page: canonical and hreflang cs-SK name it; hreflang cs is the CZ store.
    Bootstrap::initStoreContext('/', '', 'default');
    I18n::setLocale('cs');
    $_GET['lang'] = 'cs';
    $r21sHead = Seo::head('T', 'D', Template::seoPath(Template::storeUrl('/product/r21s')));
    $r21sAlt = $r21sHreflang($r21sHead);
    assert_true(str_ends_with($r21sCanonical($r21sHead), '/product/r21s?lang=cs'),
        'R21 SEO: /product/x?lang=cs in the SK store is canonical to itself (it was the Slovak page) (' . $r21sCanonical($r21sHead) . ')');
    assert_true(str_ends_with($r21sAlt['cs-SK'] ?? '', '/product/r21s?lang=cs') && str_ends_with($r21sAlt['cs'] ?? '', '/cs/product/r21s')
        && str_ends_with($r21sAlt['sk'] ?? '', '/product/r21s') && !str_contains($r21sAlt['sk'] ?? '', 'lang='),
        'R21 SEO: hreflang cs-SK is Czech in the SK store, cs the CZ store, sk the SK store (' . json_encode($r21sAlt) . ')');

    // English in the HU store: self-canonical and announced as en-HU (it had no hreflang).
    Bootstrap::initStoreContext('/hu/', '', 'hu');
    I18n::setLocale('en');
    $_GET['lang'] = 'en';
    $r21sHuHead = Seo::head('T', 'D', Template::seoPath(Template::storeUrl('/cart')));
    $r21sHuAlt = $r21sHreflang($r21sHuHead);
    assert_true(str_ends_with($r21sCanonical($r21sHuHead), '/hu/cart?lang=en') && str_ends_with($r21sHuAlt['en-HU'] ?? '', '/hu/cart?lang=en')
        && str_ends_with($r21sHuAlt['en'] ?? '', '/en/cart'),
        'R21 SEO: English in the HU store is canonical to itself and announced as en-HU; en stays the /en/ store (' . json_encode($r21sHuAlt) . ')');
    assert_equals(['cs-SK', 'en-SK', 'sk-CZ', 'en-CZ', 'en-PL', 'en-HU', 'en-DE'], array_keys(Bootstrap::hreflangAlternates('/')['regional']),
        'R21 SEO: every further store language is announced once, qualified by the store\'s country (Spanish stays es of the /en/ store)');
    $r21sCodes = array_keys($r21sHuAlt);
    assert_equals(count($r21sCodes), count(array_unique($r21sCodes)), 'R21 SEO: no hreflang code names two URLs');
    $r21sStatus = http_response_code();
    http_response_code(200);
    $r21sStoreHead = (string)StorefrontSeo::document('home', 'T', null)['head'];
    if (is_int($r21sStatus)) http_response_code($r21sStatus);
    assert_true(str_contains($r21sStoreHead, 'hreflang="en-HU"'), 'R21 SEO: pages without a view model announce the store languages too');

    // The cart is priced by the store showing it: arriving with a cart in a store of another currency says so once.
    $_SESSION = ['cart' => []];
    Bootstrap::initStoreContext('/', '', 'default');
    I18n::setLocale('sk');
    StorefrontCartAction::noteStoreCurrency(1);
    $r21sFirst = StorefrontFlash::pull();
    Bootstrap::initStoreContext('/hu/', '', 'hu');
    I18n::setLocale('hu');
    StorefrontCartAction::noteStoreCurrency(1);
    $r21sSwitched = StorefrontFlash::pull();
    StorefrontCartAction::noteStoreCurrency(1);
    $r21sAgain = StorefrontFlash::pull();
    Bootstrap::initStoreContext('/cs/', '', 'cz');
    StorefrontCartAction::noteStoreCurrency(0);
    $r21sEmpty = StorefrontFlash::pull();
    assert_true($r21sFirst === '' && str_contains($r21sSwitched, 'HUF') && str_contains($r21sSwitched, 'Magyar üzlet') && $r21sAgain === '' && $r21sEmpty === '',
        'R21 cart: a cart arriving in a store of another currency is told once (' . $r21sSwitched . '); an empty cart or the same store says nothing');

    foreach (['default', 'bookshop'] as $r21sTheme) {
        $r21sHeader = (string)file_get_contents(TEST_CLI_DIR . "/../themes/{$r21sTheme}/blocks/storefront/header.html");
        assert_true(str_contains($r21sHeader, '<nav class="store-switch" aria-label="{{t.store_switch}}">{{#each store_links}}'),
            "R21 switcher: the {$r21sTheme} theme header shows the store switch apart from the languages");
    }
    $r21sLayout = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Storefront/Layout.php');
    $r21sEntry = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Storefront.php');
    assert_true(str_contains($r21sLayout, "'store_links' => \$seo['store_links']") && str_contains($r21sEntry, 'StorefrontCartAction::noteStoreCurrency($cartCount);'),
        'R21 switcher: the storefront passes the store links and checks the cart currency on every page');
} finally {
    $_GET = $r21sSavedGet;
    $_SESSION = $r21sSavedSession ?? [];
    StoreContext::activate($r21sSavedStore);
    I18n::setLocale($r21sSavedLocale);
}
