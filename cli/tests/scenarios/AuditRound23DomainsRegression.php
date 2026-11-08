<?php

// Review round 23 (2026-11-08, kolo 7), medium "stores on their own domains": the store switch and hreflang built every
// store's link from its url_prefix on the current host. With the CZ store on shop-cz.example, "CZ · Kč" on the SK site
// led to the SK store in euros, and hreflang="cs" announced a SK-host URL. The sitemap used one domain for all stores
// (T19). Now each store is linked on its own domain; a sitemap lists the URLs of its own host, alternates on theirs.
echo "\n--- R23: stores on their own domains are linked there (switch, hreflang, canonical, sitemap) ---\n";

$r23dSavedStores = Bootstrap::stores();
$r23dSavedCfg = (new ReflectionProperty(Security::class, 'cfg'))->getValue();
$r23dSavedStore = StoreContext::current();
$r23dSavedLocale = I18n::locale();
$r23dSavedGet = $_GET;
$r23dSetStores = static fn(array $stores) => (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
$r23dLinks = static function (string $uri = '/?page=cart'): array {
    $links = [];
    foreach ((new ReflectionMethod(StorefrontSeo::class, 'storeLinks'))->invoke(null, Bootstrap::hreflangAlternates($uri)) as $link) {
        $url = (string)$link['url'];
        $links[$link['label']] = (str_starts_with($url, 'https://') ? $url : 'REL ' . substr($url, strlen(Template::baseUrl()))) . ($link['active'] ? ' *' : '');
    }
    return $links;
};
$r23dHreflang = static function (string $head): array {
    preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]*)"#', $head, $m);
    return array_combine($m[1], array_map(static fn(string $h): string => html_entity_decode($h, ENT_QUOTES | ENT_HTML5), $m[2]));
};
try {
    $r23dStores = $r23dSavedStores;
    // The CZ store on its own domain, at the domain root (url_prefix '' like the SK store).
    $r23dStores['stores']['cz']['domains'] = ['shop-cz.example'];
    $r23dStores['stores']['cz']['url_prefix'] = '';
    $r23dSetStores($r23dStores);
    $r23dConfig = require AppConfig::path(dirname(__DIR__, 3));
    (new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop-sk.example'] + $r23dConfig);

    // On the SK site: the CZ store is its own domain, the other stores stay relative.
    Bootstrap::initStoreContext('/', '', 'default');
    I18n::setLocale('sk');
    unset($_GET['lang']);
    $r23dSk = $r23dLinks();
    // (Slovak is a further language of the CZ store, so the link keeps it: R22.)
    assert_equals(['SK · €' => 'REL /?page=cart *', 'CZ · Kč' => 'https://shop-cz.example/?page=cart&lang=sk', 'HU · Ft' => 'REL /hu/?page=cart'],
        array_intersect_key($r23dSk, array_flip(['SK · €', 'CZ · Kč', 'HU · Ft'])),
        'R23 switch: on the SK site "CZ · Kč" opens shop-cz.example (was: the SK store, in euros) (' . json_encode($r23dSk, JSON_UNESCAPED_UNICODE) . ')');

    // On the CZ domain: every other store is on the shared app_url, the CZ store itself is relative.
    Bootstrap::initStoreContext('/', '', 'cz');
    I18n::setLocale('cs');
    $r23dCz = $r23dLinks();
    assert_equals(['SK · €' => 'https://shop-sk.example/?page=cart&lang=cs', 'CZ · Kč' => 'REL /?page=cart *', 'HU · Ft' => 'https://shop-sk.example/hu/?page=cart'],
        array_intersect_key($r23dCz, array_flip(['SK · €', 'CZ · Kč', 'HU · Ft'])),
        'R23 switch: on shop-cz.example the SK and HU stores are linked on the shop\'s main domain (was: on the CZ host, i.e. the CZ store)');

    // Seo::head on the CZ domain: canonical and every hreflang on its store's domain.
    $r23dHead = Seo::head('T', 'D', '/');
    preg_match('#<link rel="canonical" href="([^"]*)"#', $r23dHead, $r23dCanonical);
    $r23dAlt = $r23dHreflang($r23dHead);
    assert_equals(['https://shop-cz.example/', 'https://shop-cz.example/', 'https://shop-sk.example/', 'https://shop-sk.example/?lang=cs', 'https://shop-cz.example/?lang=sk', 'https://shop-sk.example/hu/', 'https://shop-sk.example/'],
        [$r23dCanonical[1] ?? '', $r23dAlt['cs'] ?? '', $r23dAlt['sk'] ?? '', $r23dAlt['cs-SK'] ?? '', $r23dAlt['sk-CZ'] ?? '', $r23dAlt['hu'] ?? '', $r23dAlt['x-default'] ?? ''],
        'R23 hreflang: canonical and alternates on each store\'s own domain; Czech in the SK store (cs-SK) is still announced although both stores have the empty prefix (' . json_encode($r23dAlt) . ')');

    // The storefront head of a page without a view model (cart is private; the 404-free home fallback).
    $r23dPlain = $r23dHreflang((new ReflectionMethod(StorefrontSeo::class, 'hreflang'))->invoke(null, Bootstrap::hreflangAlternates('/?page=contact')));
    assert_equals(['https://shop-cz.example/?page=contact', 'https://shop-sk.example/?page=contact', 'https://shop-sk.example/?page=contact'],
        [$r23dPlain['cs'] ?? '', $r23dPlain['sk'] ?? '', $r23dPlain['x-default'] ?? ''], 'R23 hreflang: the storefront head links each store on its domain too');

    // The sitemap of each host lists its own URLs; the alternates point to each store's domain.
    $r23dLocs = static function (string $xml): array {
        preg_match_all('#<loc>([^<]*)</loc>#', $xml, $m);
        return array_map(static fn(string $l): string => html_entity_decode($l, ENT_QUOTES | ENT_XML1), $m[1]);
    };
    $r23dCzXml = Sitemap::xml('https://shop-cz.example');
    $r23dSkXml = Sitemap::xml('https://shop-sk.example');
    $r23dCzLocs = $r23dLocs($r23dCzXml);
    $r23dSkLocs = $r23dLocs($r23dSkXml);
    preg_match('#<url>\s*<loc>https://shop-cz\.example/</loc>(.*?)</url>#s', $r23dCzXml, $r23dCzHome);
    $r23dCzHomeAlt = [];
    if (preg_match_all('#hreflang="([^"]+)" href="([^"]*)"#', $r23dCzHome[1] ?? '', $r23dM)) $r23dCzHomeAlt = array_combine($r23dM[1], $r23dM[2]);
    assert_true($r23dCzLocs !== [] && array_filter($r23dCzLocs, static fn(string $l): bool => !str_starts_with($l, 'https://shop-cz.example/')) === []
        && in_array('https://shop-cz.example/?lang=sk', $r23dCzLocs, true),
        'R23 sitemap: shop-cz.example lists only its own URLs, its further languages included (was: every store on one domain)');
    assert_true(!array_filter($r23dSkLocs, static fn(string $l): bool => str_starts_with($l, 'https://shop-cz.example/'))
        && in_array('https://shop-sk.example/', $r23dSkLocs, true) && in_array('https://shop-sk.example/hu/', $r23dSkLocs, true),
        'R23 sitemap: the main domain lists its stores, not the CZ store\'s URLs');
    assert_equals(['https://shop-cz.example/', 'https://shop-sk.example/', 'https://shop-sk.example/?lang=cs'],
        [$r23dCzHomeAlt['cs'] ?? '', $r23dCzHomeAlt['sk'] ?? '', $r23dCzHomeAlt['cs-SK'] ?? ''],
        'R23 sitemap: the alternates of a URL are on their stores\' domains, as on the page');
    $r23dDoc = new DOMDocument();
    assert_true($r23dDoc->loadXML($r23dCzXml) !== false, 'R23 sitemap: still well-formed');
} catch (Throwable $e) {
    assert_true(false, 'R23 domains (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r23dSetStores($r23dSavedStores);
    (new ReflectionProperty(Security::class, 'cfg'))->setValue(null, $r23dSavedCfg);
    $_GET = $r23dSavedGet;
    StoreContext::activate($r23dSavedStore);
    I18n::setLocale($r23dSavedLocale);
}
