<?php
/** Shared storefront document renderer. Included in request scope. */
$hreflangData = Bootstrap::hreflangAlternates();
$hreflangs = $hreflangData['alternates'];
$pathNoPrefix = $hreflangData['pathNoPrefix'];
$themeAssets = Template::assets();
$store = StoreContext::current();
$brand = Template::branding();
$shopName = (string)$brand['name'];
$homeUrl = Template::storeUrl('/');
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
$viewModel = $GLOBALS['storefront_view_model'] ?? null;
if (is_array($viewModel) && is_array($viewModel['page']['seo'] ?? null)) {
    $seoHead = Template::viewModelSeoHead($viewModel, $title);
    if ($jsonLd !== '') $seoHead .= "\n" . $jsonLd;
}

$languageLinks = [];
foreach ($hreflangs as $locale => $prefix) {
    $languageLinks[] = [
        'url' => Template::url($prefix . $pathNoPrefix),
        'label' => strtoupper($locale),
        'active' => $lang === $locale,
    ];
}

$navigation = [];
$managedNavigation = ContentService::menu('primary', $lang);
if ($managedNavigation) {
    foreach ($managedNavigation as $item) {
        $navigation[] = ['url' => $localUrl((string)$item['url']), 'label' => (string)$item['label'], 'children' => []];
    }
} else {
    $navCategories = Categories::roots();
    usort($navCategories, static fn(array $a, array $b): int => (int)$b['product_count'] <=> (int)$a['product_count']);
    $navCategories = array_filter($navCategories, static fn(array $category): bool => (int)$category['product_count'] > 0 && $category['slug'] !== 'uncategorized');
    foreach (array_slice($navCategories, 0, 8) as $category) {
        $children = Categories::children((int)$category['id']);
        usort($children, static fn(array $a, array $b): int => (int)$b['product_count'] <=> (int)$a['product_count']);
        $children = array_values(array_filter($children, static fn(array $child): bool => (int)$child['product_count'] > 0));
        $childLinks = array_map(static fn(array $child): array => [
            'url' => Template::storeUrl('/?page=category&slug=' . rawurlencode((string)$child['slug'])),
            'label' => $catName((string)$child['name']),
        ], array_slice($children, 0, 12));
        if (count($children) > 12) {
            $childLinks[] = [
                'url' => Template::storeUrl('/?page=category&slug=' . rawurlencode((string)$category['slug'])),
                'label' => I18n::t('view_all'),
            ];
        }
        $navigation[] = [
            'url' => Template::storeUrl('/?page=category&slug=' . rawurlencode((string)$category['slug'])),
            'label' => $catName((string)$category['name']),
            'children' => $childLinks,
        ];
    }
}

$loggedIn = !empty($_SESSION['customer_id']);
$footerCategories = Categories::roots();
usort($footerCategories, static fn(array $a, array $b): int => (int)$b['product_count'] <=> (int)$a['product_count']);
$footerCategories = array_filter($footerCategories, static fn(array $category): bool => (int)$category['product_count'] > 0 && $category['slug'] !== 'uncategorized');
$shell = [
    't' => I18n::all(),
    'brand' => $brand,
    'shop_name' => $shopName,
    'home_url' => $homeUrl,
    'account_url' => Template::storeUrl('/account.php' . ($loggedIn ? '' : '?p=login')),
    'account_label' => $t($loggedIn ? 'account.title' : 'account.login'),
    'cart_url' => Template::storeUrl('/?page=cart'),
    'cart_count' => $cartCount,
    'language_links' => $languageLinks,
    'navigation' => $navigation,
    'search_query' => (string)($_GET['q'] ?? ''),
    'footer_categories' => array_map(static fn(array $category): array => [
        'url' => Template::storeUrl('/?page=category&slug=' . rawurlencode((string)$category['slug'])),
        'label' => $catName((string)$category['name']),
    ], array_slice($footerCategories, 0, 6)),
    'year' => date('Y'),
    'about_url' => Template::storeUrl('/?page=about'),
    'contact_url' => Template::storeUrl('/?page=contact'),
    'privacy_url' => Template::storeUrl('/?page=privacy'),
    'terms_url' => Template::storeUrl('/?page=terms'),
    'cookies_url' => Template::storeUrl('/?page=cookies'),
    'show_cookie_banner' => (string)($_COOKIE['cc_v1'] ?? '') === '',
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
];

$runtimeConfig = [
    'apiUrl' => Template::url('/api.php'),
    'currentUrl' => Template::url('/'),
    'baseUrl' => Template::baseUrl(),
    'csrfToken' => Security::csrfToken(),
    'locale' => I18n::locale(),
    'currency' => strtoupper($store->currency()),
    'text' => [
        'added' => I18n::t('added_to_cart'),
        'selectVariant' => I18n::t('select_variant'),
        'cartError' => I18n::t('cart_error'),
        'variant_required' => I18n::t('variant_required'),
        'variant_invalid' => I18n::t('variant_invalid'),
        'product_invalid' => I18n::t('product_invalid'),
        'product_unavailable' => I18n::t('product_unavailable'),
        'free' => I18n::t('free_shipping'),
        'shippingLoading' => I18n::t('shipping_loading'),
        'shippingUnavailable' => I18n::t('shipping_unavailable'),
        'selectShipping' => I18n::t('select_shipping_first'),
        'paymentLoading' => I18n::t('payment_loading'),
        'paymentUnavailable' => I18n::t('payment_unavailable'),
    ],
];
$shell['content_html'] = $content;
$shell['runtime_config_json'] = json_encode($runtimeConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$shell['nonce'] = $nonce;
$bodyHtml = Template::render('storefront/shell', $shell);

echo Template::document($title, '', [
    'seo_head' => $seoHead,
    'body_html' => $bodyHtml,
    'body_end' => '',
    'assets' => $themeAssets,
    'base_css' => '',
    'shop_js' => '',
]);
