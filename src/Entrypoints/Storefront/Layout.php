<?php
/** Shared storefront document renderer. Included in request scope. */
$hreflangData = Bootstrap::hreflangAlternates();
$hreflangs = $hreflangData['alternates'];
$pathNoPrefix = $hreflangData['pathNoPrefix'];
$themeAssets = Template::assets();
$store = StoreContext::current();
$shopName = (string)($store->config()['name'] ?? 'BareBones Shop');
$homeUrl = Template::url('/');
$localUrl = static function(string $url): string {
    if ($url === '' || $url[0] === '?' || $url[0] === '#') return $url;
    return $url[0] === '/' ? Template::url($url) : $url;
};

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . Template::baseUrl();
$jsonLd = '';
$nonce = Security::cspNonce();
if ($page === 'home') {
    $jsonLd  = JsonLd::render(JsonLd::organization($base, Bootstrap::stores()), $nonce);
    $jsonLd .= JsonLd::render(JsonLd::webSite($base, Bootstrap::stores()), $nonce);
} elseif ($page === 'category' && !empty($cat)) {
    $crumbItems = [['name' => $t('home'), 'url' => Template::url('/')]];
    foreach (Categories::breadcrumb((int)$cat['id']) as $crumbCat) {
        $crumbItems[] = [
            'name' => $catName($crumbCat['name']),
            'url' => Template::url('/?page=category&slug=' . rawurlencode((string)$crumbCat['slug'])),
        ];
    }
    $jsonLd = JsonLd::render(JsonLd::breadcrumbList($crumbItems, $base), $nonce);
}

ob_start();
foreach ($hreflangs as $locale => $prefix) {
    echo '<link rel="alternate" hreflang="' . $e($locale) . '" href="' . $e(Template::url($prefix . $pathNoPrefix)) . '">' . "\n";
}
echo '<link rel="alternate" hreflang="x-default" href="' . $e(Template::url($hreflangData['xDefaultPrefix'] . $pathNoPrefix)) . '">' . "\n";
if ($jsonLd !== '') echo $jsonLd . "\n";
$seoHead = ob_get_clean();

$languageLinksHtml = '';
foreach ($hreflangs as $locale => $prefix) {
    $languageLinksHtml .= '<a href="' . $e(Template::url($prefix . $pathNoPrefix)) . '" class="' . ($lang === $locale ? 'active' : '') . '">' . strtoupper($e($locale)) . '</a>';
}

$navigationHtml = '';
$managedNavigation = ContentService::menu('primary', $lang);
if ($managedNavigation) {
    foreach ($managedNavigation as $item) {
        $navigationHtml .= Template::render('storefront/navigation-link', [
            'url' => $localUrl((string)$item['url']),
            'label' => (string)$item['label'],
            'children_html' => '',
        ]);
    }
} else {
    $navCategories = Categories::roots();
    usort($navCategories, static fn(array $a, array $b): int => (int)$b['product_count'] <=> (int)$a['product_count']);
    $navCategories = array_filter($navCategories, static fn(array $category): bool => (int)$category['product_count'] > 0 && $category['slug'] !== 'uncategorized');
    foreach (array_slice($navCategories, 0, 8) as $category) {
        $children = Categories::children((int)$category['id']);
        usort($children, static fn(array $a, array $b): int => (int)$b['product_count'] <=> (int)$a['product_count']);
        $children = array_values(array_filter($children, static fn(array $child): bool => (int)$child['product_count'] > 0));
        $childrenHtml = '';
        foreach (array_slice($children, 0, 12) as $child) {
            $childrenHtml .= '<a href="' . $e(Template::url('/?page=category&slug=' . rawurlencode((string)$child['slug']))) . '">' . $e($catName($child['name'])) . '</a>';
        }
        if (count($children) > 12) {
            $childrenHtml .= '<a href="' . $e(Template::url('/?page=category&slug=' . rawurlencode((string)$category['slug']))) . '">' . $e(I18n::t('view_all')) . '</a>';
        }
        $navigationHtml .= Template::render('storefront/navigation-link', [
            'url' => Template::url('/?page=category&slug=' . rawurlencode((string)$category['slug'])),
            'label' => $catName($category['name']),
            'children_html' => $childrenHtml,
        ]);
    }
}

$loggedIn = !empty($_SESSION['customer_id']);
$cartBadgeHtml = $cartCount > 0 ? '<span class="cart-badge">' . $cartCount . '</span>' : '';
$header = Template::render('storefront/header', [
    't' => I18n::all(),
    'shop_name' => $shopName,
    'home_url' => $homeUrl,
    'account_url' => Template::url('/account.php' . ($loggedIn ? '' : '?p=login')),
    'account_label' => $t($loggedIn ? 'account.title' : 'account.login'),
    'cart_url' => Template::url('/?page=cart'),
    'cart_badge_html' => $cartBadgeHtml,
    'language_links_html' => $languageLinksHtml,
    'navigation_html' => $navigationHtml,
    'search_query' => (string)($_GET['q'] ?? ''),
]);

$categoryLinksHtml = '';
$footerCategories = Categories::roots();
usort($footerCategories, static fn(array $a, array $b): int => (int)$b['product_count'] <=> (int)$a['product_count']);
$footerCategories = array_filter($footerCategories, static fn(array $category): bool => (int)$category['product_count'] > 0 && $category['slug'] !== 'uncategorized');
foreach (array_slice($footerCategories, 0, 6) as $category) {
    $categoryLinksHtml .= '<a href="' . $e(Template::url('/?page=category&slug=' . rawurlencode((string)$category['slug']))) . '">' . $e($catName($category['name'])) . '</a>';
}
$footer = Template::render('storefront/footer', [
    't' => I18n::all(),
    'shop_name' => $shopName,
    'year' => date('Y'),
    'category_links_html' => $categoryLinksHtml,
    'about_url' => Template::url('/?page=about'),
    'contact_url' => Template::url('/?page=contact'),
    'privacy_url' => Template::url('/?page=privacy'),
    'terms_url' => Template::url('/?page=terms'),
    'cookies_url' => Template::url('/?page=cookies'),
]);

$cookieBanner = '';
if ((string)($_COOKIE['cc_v1'] ?? '') === '') {
    $cookieBanner = Template::render('storefront/cookie-banner', [
        'privacy_url' => Template::url('/?page=privacy'),
        'cookie_title' => I18n::t('cookie_banner.title'),
        'cookie_body' => I18n::t('cookie_banner.body'),
        'cookie_policy_link' => I18n::t('cookie_banner.policy_link'),
        'cookie_necessary' => I18n::t('account.gdpr_cookies_necessary'),
        'cookie_analytics' => I18n::t('account.gdpr_cookies_analytics'),
        'cookie_marketing' => I18n::t('account.gdpr_cookies_marketing'),
        'cookie_preferences' => I18n::t('cookie_banner.preferences'),
        'cookie_reject' => I18n::t('cookie_banner.reject_all'),
        'cookie_accept' => I18n::t('cookie_banner.accept_all'),
        'cookie_save' => I18n::t('cookie_banner.save_preferences'),
    ]);
}

$runtimeConfig = [
    'apiUrl' => Template::url('/api.php'),
    'currentUrl' => Template::url('/'),
    'baseUrl' => Template::baseUrl(),
    'csrfToken' => Security::csrfToken(),
    'locale' => I18n::locale(),
    'currency' => strtoupper($store->currency()),
    'text' => [
        'added' => I18n::t('added_to_cart'),
        'free' => I18n::t('free_shipping'),
        'shippingLoading' => I18n::t('shipping_loading'),
        'shippingUnavailable' => I18n::t('shipping_unavailable'),
        'selectShipping' => I18n::t('select_shipping_first'),
        'paymentLoading' => I18n::t('payment_loading'),
        'paymentUnavailable' => I18n::t('payment_unavailable'),
    ],
];
$bodyEnd = '<script nonce="' . $e($nonce) . '">window.BareBonesStorefront=' . json_encode($runtimeConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . ';</script>';
$bodyHtml = $header . '<main id="main-content" tabindex="-1"><div class="wrap">' . $content . '</div></main>' . $footer . $cookieBanner;

echo Template::document($title, '', [
    'seo_head' => $seoHead,
    'body_html' => $bodyHtml,
    'body_end' => $bodyEnd,
    'assets' => $themeAssets,
    'base_css' => '',
    'shop_js' => '',
]);
