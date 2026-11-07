<?php
/**
 * Storefront document head (canonical, robots, hreflang, JSON-LD) and language switcher
 * for every page rendered through the shared storefront Layout, including the pages
 * without a canonical view model (category, search, cart, checkout, account, wishlist).
 */
final class StorefrontSeo
{
    /** Per-visitor transactional pages: never indexed. */
    public const PRIVATE_PAGES = ['cart', 'cart-unsubscribe', 'checkout', 'checkout-cancel', 'order-done', 'account', 'wishlist'];

    /**
     * @param array|null $viewModel canonical view model carrying page.seo (home, product, CMS page)
     * @param array|null $category  the resolved category row on a category page, null when not found
     * @return array{head: string, language_links: list<array{url: string, label: string, active: bool}>, store_links: list<array{url: string, label: string, title: string, active: bool}>}
     */
    public static function document(string $page, string $title, ?array $viewModel, ?array $category = null): array
    {
        $status = (int)(http_response_code() ?: 200);
        // Error pages (missing product/category/page) are not alternates of anything: no hreflang.
        $error = $status >= 400 || ($page === 'category' && $category === null);
        $alternates = Bootstrap::hreflangAlternates();
        if ($error || in_array($page, self::PRIVATE_PAGES, true)) {
            $head = self::robots('noindex');
        } elseif ($page === 'search') {
            $head = self::robots('noindex,follow');
        } elseif (is_array($viewModel) && is_array($viewModel['page']['seo'] ?? null)) {
            $head = Template::viewModelSeoHead($viewModel, $title);
        } elseif ($page === 'category' && $category !== null) {
            $head = Seo::head($title, '', self::categoryPath($category, (int)($_GET['pg'] ?? 1)), true);
        } elseif (in_array($page, ConsumerNotice::LEGAL_PAGES, true)) {
            $head = Seo::head($title, '', Template::seoPath(Template::storeUrl('/?page=' . $page)), true);
        } else {
            $head = self::hreflang($alternates);
        }
        $jsonLd = $error ? '' : self::jsonLd($page, $category);
        return [
            'head' => $head . ($jsonLd !== '' ? "\n" . $jsonLd : ''),
            'language_links' => self::languageLinks($alternates),
            'store_links' => self::storeLinks($alternates),
        ];
    }

    /**
     * Base-less canonical path of a category listing: the /category/{slug} route the sitemap
     * lists, without sort/filter parameters; pagination is kept because page 2+ list other products.
     */
    public static function categoryPath(array $category, int $page = 1): string
    {
        $path = Template::seoPath(Template::storeUrl('/category/' . rawurlencode((string)$category['slug'])));
        return $page > 1 ? $path . (str_contains($path, '?') ? '&' : '?') . 'pg=' . $page : $path;
    }

    /**
     * A product/category reached by an old (SlugRepair) or another language's slug: 301 to its canonical URL. A product
     * ($productId) is redirected only to a slug that shows it: one taken by another product must not redirect (or loop).
     */
    public static function redirectToCanonical(string $requested, string $canonical, string $route, int $productId = 0): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) || $canonical === $requested) return;
        if ($productId > 0 && (int)(Products::bySlug($canonical)['id'] ?? 0) !== $productId) return;
        $url = $route === 'product' ? StorefrontViewModel::productUrl($canonical) : StorefrontViewModel::categoryUrl($canonical);
        $target = SlugRepair::canonicalRedirect($requested, $canonical, $url, $_GET);
        if ($target === null) return;
        header('Location: ' . $target, true, 301);
        exit;
    }

    private static function robots(string $directives): string
    {
        return '<meta name="robots" content="' . $directives . '">';
    }

    private static function hreflang(array $alternates): string
    {
        $lines = [];
        foreach ($alternates['alternates'] as $locale => $prefix) {
            $lines[] = '<link rel="alternate" hreflang="' . esc((string)$locale) . '" href="'
                . esc(Template::absoluteUrl(Template::url($prefix . $alternates['pathNoPrefix']))) . '">';
        }
        foreach ($alternates['secondary'] as $locale => $prefix) {
            $lines[] = '<link rel="alternate" hreflang="' . esc((string)$locale) . '" href="'
                . esc(Template::absoluteUrl(Bootstrap::withLang(Template::url($prefix . $alternates['pathNoPrefix']), (string)$locale))) . '">';
        }
        foreach ($alternates['regional'] as $code => $target) {
            $lines[] = '<link rel="alternate" hreflang="' . esc((string)$code) . '" href="'
                . esc(Template::absoluteUrl(Bootstrap::withLang(Template::url($target['prefix'] . $alternates['pathNoPrefix']), $target['locale']))) . '">';
        }
        $lines[] = '<link rel="alternate" hreflang="x-default" href="'
            . esc(Template::absoluteUrl(Template::url($alternates['xDefaultPrefix'] . $alternates['pathNoPrefix']))) . '">';
        return implode("\n", $lines);
    }

    /**
     * The languages of the current store, all on its own URL: the default without ?lang=, a further one with it (R20:
     * English in the HU store stays in the HU store and its currency). R21: languages of other stores are no longer
     * language links: Czech of the SK store and the CZ store were both "CS" (one in euros, the SK and CZ stores no
     * longer linked each other), and "HU" moved the cart to the HU store and forints unannounced. See storeLinks().
     * @return list<array{url: string, label: string, active: bool}>
     */
    private static function languageLinks(array $alternates): array
    {
        $current = StoreContext::current();
        $default = strtolower($current->defaultLocale());
        $here = Template::url((string)($current->config()['url_prefix'] ?? '') . $alternates['pathNoPrefix']);
        $further = array_map(static fn($locale): string => strtolower((string)$locale), (array)($current->config()['languages'] ?? []));
        return array_map(static fn(string $locale): array => [
            'url' => $locale === $default ? $here : Bootstrap::withLang($here, $locale),
            'label' => strtoupper($locale),
            'active' => I18n::locale() === $locale,
        ], array_values(array_unique(array_merge([$default], $further))));
    }

    /**
     * R21: every public store by country and currency ("CZ · Kč", the store name as title), on the same page in that
     * store's own language. Changing the store changes the currency the cart is priced in, so it is a separate choice
     * (StorefrontCartAction::noteStoreCurrency() tells a shopper with a cart). Empty with a single public store.
     * R22: a store that has the shopper's language keeps it (English in the HU store → the SK store in English, not
     * in Slovak); otherwise the store's own language.
     * @return list<array{url: string, label: string, title: string, active: bool}>
     */
    private static function storeLinks(array $alternates): array
    {
        $config = Bootstrap::stores();
        $currentId = StoreContext::current()->id();
        $locale = strtolower(I18n::locale());
        $links = [];
        foreach (is_array($config['stores'] ?? null) ? $config['stores'] : [] as $id => $store) {
            if (!is_array($store) || !StoreContext::isPublicConfig($store)) continue;
            $country = strtoupper((string)($store['legal']['country'] ?? ''));
            $name = (string)($store['name'] ?? $id);
            $url = Template::url((string)($store['url_prefix'] ?? '') . $alternates['pathNoPrefix']);
            $further = array_map(static fn($language): string => strtolower((string)$language), (array)($store['languages'] ?? []));
            $keepsLocale = $locale !== strtolower((string)($store['default_locale'] ?? 'en')) && in_array($locale, $further, true);
            $links[] = [
                'url' => $keepsLocale ? Bootstrap::withLang($url, $locale) : $url,
                'label' => preg_match('/^[A-Z]{2}$/', $country) ? $country . ' · ' . I18n::priceFormat(strtolower((string)($store['currency'] ?? '')))['symbol'] : $name,
                'title' => $name,
                'active' => (string)($store['id'] ?? $id) === $currentId,
            ];
        }
        return count($links) > 1 ? $links : [];
    }

    /** Organization/WebSite of the current store on its home page; the category trail on a category page. */
    private static function jsonLd(string $page, ?array $category): string
    {
        // Absolute structured-data URLs come from app_url only (never the Host header); unset → base-relative URLs.
        $base = Security::configuredAppUrl() ?? Template::baseUrl();
        $nonce = Security::cspNonce();
        if ($page === 'home') {
            $storeId = StoreContext::current()->id();
            return JsonLd::render(JsonLd::organization($base, Bootstrap::stores(), $storeId), $nonce)
                . JsonLd::render(JsonLd::webSite($base, Bootstrap::stores(), $storeId), $nonce);
        }
        if ($page !== 'category' || $category === null) return '';
        // $base already contains the deployment base path, so join it with base-less paths.
        $items = [['name' => I18n::t('home'), 'url' => Template::seoPath(Template::storeUrl('/'))]];
        foreach (Categories::breadcrumb((int)$category['id']) as $crumb) {
            $items[] = [
                'name' => Bootstrap::categoryName((string)$crumb['name'], I18n::locale()),
                'url' => self::categoryPath($crumb),
            ];
        }
        return JsonLd::render(JsonLd::breadcrumbList($items, $base), $nonce);
    }
}
