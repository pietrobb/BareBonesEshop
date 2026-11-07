<?php

// Review round 20 (2026-11-07), medium "language switcher": in the HU and CZ stores the EN link of the switcher led to
// another store (/?lang=en, or that store's /en/), so the cart was repriced in its currency (5 490 Ft became 31,67 €).
// A language the current store offers now stays in the current store; and English in the HU store keeps ?lang=en on
// its links and API calls (it was dropped, being another store's own language, so the next page fell back to HU).
echo "\n--- R20: the language switcher stays in the store and its currency ---\n";

$r20lSavedLocale = I18n::locale();
$r20lSavedStore = StoreContext::current();
$r20lSavedGet = $_GET;
$r20lLinks = static function (): array {
    $method = new ReflectionMethod(StorefrontSeo::class, 'languageLinks');
    $links = [];
    foreach ($method->invoke(null, Bootstrap::hreflangAlternates('/?page=cart')) as $link) {
        $links[$link['label']] = substr($link['url'], strlen(Template::baseUrl())) . ($link['active'] ? ' *' : '');
    }
    return $links;
};
try {
    Bootstrap::initStoreContext('/hu/', '', 'hu');
    I18n::setLocale('hu');
    $r20lHu = $r20lLinks();
    assert_equals(['/hu/?page=cart *', '/hu/?page=cart&lang=en'], [$r20lHu['HU'] ?? null, $r20lHu['EN'] ?? null],
        'R20 switcher: in the HU store HU and EN stay in the HU store (EN led to another store and currency) (' . json_encode($r20lHu) . ')');
    assert_equals(['/?page=cart', '/cs/?page=cart', '/de/?page=cart'], [$r20lHu['SK'] ?? null, $r20lHu['CS'] ?? null, $r20lHu['DE'] ?? null],
        'R20 switcher: a language the HU store does not offer still leads to the store that has it');

    // English chosen in the HU store: links, forms and the API keep it; the canonical stays the HU store's URL.
    I18n::setLocale('en');
    $_GET['lang'] = 'en';
    assert_equals(['en', null], [Bootstrap::secondaryLocale(), Bootstrap::canonicalSecondaryLocale()],
        'R20 switcher: English in the HU store is carried by its links, not by its canonical (the /en/ store owns English)');
    assert_equals(Template::baseUrl() . '/hu/cart?lang=en', Template::storeUrl('/cart'), 'R20 switcher: the next page of the HU store stays English');
    $r20lHuEn = $r20lLinks();
    assert_equals(['/hu/?page=cart', '/hu/?page=cart&lang=en *'], [$r20lHuEn['HU'] ?? null, $r20lHuEn['EN'] ?? null],
        'R20 switcher: from English back to Hungarian drops ?lang= and stays in the HU store');
    preg_match('#<link rel="canonical" href="([^"]*)"#', Seo::head('T', 'D', Template::storeUrl('/cart')), $r20lCanonical);
    assert_true(str_ends_with((string)($r20lCanonical[1] ?? ''), '/hu/cart'), 'R20 switcher: the canonical of English in the HU store is its HU URL (' . ($r20lCanonical[1] ?? '') . ')');

    unset($_GET['lang']);
    I18n::setLocale('en');
    assert_equals(null, Bootstrap::secondaryLocale(), 'R20 switcher: without ?lang=en a stray English locale adds nothing to the HU store\'s links');

    // The CZ store: EN stays in /cs/ (CZK), SK too (a further language of the CZ store).
    Bootstrap::initStoreContext('/cs/', '', 'cz');
    I18n::setLocale('cs');
    $r20lCz = $r20lLinks();
    assert_equals(['/cs/?page=cart *', '/cs/?page=cart&lang=en', '/cs/?page=cart&lang=sk', '/hu/?page=cart'],
        [$r20lCz['CS'] ?? null, $r20lCz['EN'] ?? null, $r20lCz['SK'] ?? null, $r20lCz['HU'] ?? null],
        'R20 switcher: in the CZ store CS, EN and SK stay in /cs/ (CZK); HU leads to the HU store (' . json_encode($r20lCz) . ')');

    // A further language no store defaults to is unchanged: Spanish of the /en/ store.
    Bootstrap::initStoreContext('/en/', '', 'us');
    I18n::setLocale('en');
    $r20lUs = $r20lLinks();
    assert_equals(['/en/?page=cart *', '/en/?page=cart&lang=es'], [$r20lUs['EN'] ?? null, $r20lUs['ES'] ?? null],
        'R20 switcher: the /en/ store keeps Spanish as ?lang=es on its own URL');
} finally {
    $_GET = $r20lSavedGet;
    StoreContext::activate($r20lSavedStore);
    I18n::setLocale($r20lSavedLocale);
}
