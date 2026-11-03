<?php
/**
 * Round 14 (review "kolo 6", 2026-11-03), PR "usability": a store's further language (?lang=xx) was lost after the
 * first click — storefront links carried no ?lang=, so the product link of the English page opened in the store
 * language and the English slug answered with a 301 to the store language's slug (UX2).
 */

echo "\n--- Audit R14: usability (language kept across links) ---\n";

$r14uFail = static fn(string $what, Throwable $e) => assert_true(false, "R14 UX {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');

// --- UX2: the active further language stays on every storefront link ----------------------------------------------
$r14uSavedLocale = I18n::locale();
$r14uSavedStore = StoreContext::current();
try {
    Bootstrap::initStoreContext('/en/', '', 'us');
    I18n::setLocale('es');
    assert_equals('es', Bootstrap::secondaryLocale(), 'R14 UX2 fixture: Spanish is a further language of the /en/ store');
    $r14uProduct = StorefrontViewModel::productUrl('r14u-producto');
    $r14uCategory = StorefrontViewModel::categoryUrl('r14u-categoria');
    assert_true(str_ends_with($r14uProduct, '/en/product/r14u-producto?lang=es') && str_contains($r14uCategory, 'lang=es'),
        "R14 UX2: product and category links of a Spanish page keep ?lang=es ({$r14uProduct}, {$r14uCategory})");
    assert_equals(Template::baseUrl() . '/en/cart?x=1&lang=es#top', Template::storeUrl('/cart?x=1#top'),
        'R14 UX2: the language goes into the query, before a #fragment');
    assert_equals('https://example.test/a', Template::storeUrl('https://example.test/a'), 'R14 UX2: an absolute URL is left alone');

    $r14uRedirect = SlugRepair::canonicalRedirect('r14u-product', 'r14u-producto', $r14uProduct, ['lang' => 'es', 'sort' => 'name_asc']);
    assert_true(is_string($r14uRedirect) && substr_count($r14uRedirect, 'lang=') === 1 && str_contains($r14uRedirect, 'sort=name_asc'),
        'R14 UX2: the redirect to another language\'s slug carries ?lang= once (' . var_export($r14uRedirect, true) . ')');

    $r14uHead = Seo::head('T', 'D', $r14uProduct);
    preg_match('#<link rel="canonical" href="([^"]*)"#', $r14uHead, $r14uCanonical);
    preg_match('#hreflang="en" href="([^"]*)"#', $r14uHead, $r14uEnglish);
    assert_true(str_ends_with((string)($r14uCanonical[1] ?? ''), '/en/product/r14u-producto?lang=es') && substr_count((string)$r14uCanonical[1], 'lang=') === 1
        && str_ends_with((string)($r14uEnglish[1] ?? ''), '/en/product/r14u-producto'),
        'R14 UX2: the canonical keeps one ?lang=es and the store language\'s alternate has none (' . ($r14uCanonical[1] ?? '') . ' / ' . ($r14uEnglish[1] ?? '') . ')');

    assert_equals(Template::baseUrl() . '/en/order-done', Template::withOrderLang(Template::storeUrl('/order-done'), ['locale' => 'en']),
        'R14 UX2: a page about an order in the store language drops the browsing language');
    assert_equals('/a?x=1&lang=es', Bootstrap::withLang('/a?lang=de&x=1', 'es'), 'R14 UX2: withLang replaces a language already set');
    assert_equals('/a?x=1#f', Bootstrap::withoutLang('/a?x=1&lang=es#f'), 'R14 UX2: withoutLang keeps the rest of the URL');

    I18n::setLocale('en');
    assert_true(!str_contains(StorefrontViewModel::productUrl('r14u-product'), 'lang='), 'R14 UX2: the store language needs no ?lang=');
} catch (Throwable $e) { $r14uFail('UX2', $e); } finally {
    StoreContext::activate($r14uSavedStore);
    I18n::setLocale($r14uSavedLocale);
}
