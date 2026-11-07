<?php

// Review round 22 (2026-11-07), low "store switch and SEO":
// - the store switch dropped the shopper's language: English in the HU store led to the SK store in Slovak.
// - the sitemap listed only each store's default language, not the further store languages the page's hreflang
//   announces (cs-SK, en-HU, ...), with the same URLs.
echo "\n--- R22: the store switch keeps the language a store has, the sitemap lists every hreflang language version ---\n";

$r22sSavedLocale = I18n::locale();
$r22sSavedStore = StoreContext::current();
$r22sSavedGet = $_GET;
$r22sStoreLinks = static function (string $uri = '/?page=cart'): array {
    $links = [];
    foreach ((new ReflectionMethod(StorefrontSeo::class, 'storeLinks'))->invoke(null, Bootstrap::hreflangAlternates($uri)) as $link) {
        $links[$link['label']] = substr($link['url'], strlen(Template::baseUrl())) . ($link['active'] ? ' *' : '');
    }
    return $links;
};
$r22sRelative = static function (string $url): string {
    $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5 | ENT_XML1));
    return (string)($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
};
try {
    // English in the HU store: every store with English keeps it, the /en/ store (English is its own) needs no ?lang.
    Bootstrap::initStoreContext('/hu/', '', 'hu');
    I18n::setLocale('en');
    $_GET['lang'] = 'en';
    $r22sHuEn = $r22sStoreLinks();
    assert_equals(['SK · €' => '/?page=cart&lang=en', 'CZ · Kč' => '/cs/?page=cart&lang=en', 'HU · Ft' => '/hu/?page=cart&lang=en *', 'US · $' => '/en/?page=cart'],
        array_intersect_key($r22sHuEn, array_flip(['SK · €', 'CZ · Kč', 'HU · Ft', 'US · $'])),
        'R22 switcher: English in the HU store leads to the SK and CZ stores in English (it was Slovak and Czech) (' . json_encode($r22sHuEn, JSON_UNESCAPED_UNICODE) . ')');

    // Hungarian in the HU store: no other store has it, so each opens in its own language.
    I18n::setLocale('hu');
    unset($_GET['lang']);
    $r22sHu = $r22sStoreLinks();
    assert_equals(['SK · €' => '/?page=cart', 'PL · zł' => '/pl/?page=cart', 'HU · Ft' => '/hu/?page=cart *'],
        array_intersect_key($r22sHu, array_flip(['SK · €', 'PL · zł', 'HU · Ft'])), 'R22 switcher: a language the store does not have is not carried over');

    // Spanish in the /en/ store: the SK store has no Spanish.
    Bootstrap::initStoreContext('/en/', '', 'us');
    I18n::setLocale('es');
    $_GET['lang'] = 'es';
    $r22sEs = $r22sStoreLinks();
    assert_equals(['/?page=cart', '/en/?page=cart&lang=es *'], [$r22sEs['SK · €'] ?? null, $r22sEs['US · $'] ?? null],
        'R22 switcher: Spanish stays only where the store has it');

    // The sitemap's home page entry announces exactly the hreflang of the home page, and lists each of those URLs.
    Bootstrap::initStoreContext('/', '', 'default');
    I18n::setLocale('sk');
    unset($_GET['lang']);
    preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]*)"#', Seo::head('T', 'D', '/'), $r22sHead);
    // The page's links carry the deployment base path (here the CLI's); the sitemap is rendered for a bare host.
    $r22sBasePath = rtrim((string)(parse_url(Template::baseUrl(), PHP_URL_PATH) ?? ''), '/');
    $r22sPage = array_combine($r22sHead[1], array_map(static fn(string $href): string => $r22sBasePath !== '' && str_starts_with($r22sRelative($href), $r22sBasePath . '/')
        ? substr($r22sRelative($href), strlen($r22sBasePath)) : $r22sRelative($href), $r22sHead[2]));
    $r22sXml = Sitemap::xml('https://shop.example');
    preg_match_all('#<loc>([^<]*)</loc>#', $r22sXml, $r22sLocs);
    $r22sLocs = array_map($r22sRelative, $r22sLocs[1]);
    preg_match('#<url>\s*<loc>https://shop\.example/</loc>(.*?)</url>#s', $r22sXml, $r22sHome);
    preg_match_all('#hreflang="([^"]+)" href="([^"]*)"#', $r22sHome[1] ?? '', $r22sSitemapLinks);
    $r22sSitemap = array_combine($r22sSitemapLinks[1], array_map($r22sRelative, $r22sSitemapLinks[2]));
    ksort($r22sPage);
    ksort($r22sSitemap);
    assert_equals($r22sPage, $r22sSitemap, 'R22 sitemap: the home page entry has the same hreflang alternates as the page (cs-SK, en-HU, ... were missing)');
    assert_equals([], array_values(array_diff(array_values($r22sPage), $r22sLocs)), 'R22 sitemap: every language version the page announces is a <loc>');
    assert_true(in_array('/hu/?lang=en', $r22sLocs, true) && in_array('/?lang=cs', $r22sLocs, true) && in_array('/en/?lang=es', $r22sLocs, true)
        && count(array_unique($r22sLocs)) === count($r22sLocs),
        'R22 sitemap: English in the HU store, Czech in the SK store and Spanish in the /en/ store are listed, each once');
} finally {
    $_GET = $r22sSavedGet;
    StoreContext::activate($r22sSavedStore);
    I18n::setLocale($r22sSavedLocale);
}
