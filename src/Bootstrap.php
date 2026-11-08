<?php
/**
 * Bootstrap — shared per-request front-controller helpers.
 *
 * Centralises work that every storefront entry point (index.php, product.php,
 * page.php, admin.php, account.php) otherwise repeated on each request:
 * decoding stores.json and detecting/applying the active locale. Loaders cache
 * their result in a static for the lifetime of the request, so stores.json is
 * read and parsed at most once instead of 3+ times per page render.
 */
class Bootstrap {
    private static ?array $stores = null;
    private static ?array $categoryNames = null;

    /** Per-request cached stores.json decode. Always returns an array. */
    public static function stores(): array {
        if (self::$stores === null) {
            $file = DataOverrides::storesPath();
            self::$stores = is_file($file)
                ? (json_decode((string)file_get_contents($file), true) ?: [])
                : [];
        }
        return self::$stores;
    }

    /** Resolve and activate the request's explicit StoreContext. */
    public static function initStoreContext(
        ?string $uri = null,
        ?string $host = null,
        ?string $storeId = null,
        string $channel = 'storefront'
    ): StoreContext {
        $context = StoreContext::resolve(self::stores(), $uri, $host, $storeId, $channel);
        StoreContext::activate($context);
        Template::activate($context);
        StorefrontComposition::boot();
        return $context;
    }

    /**
     * Resolve the store and detect/apply the locale from the request URI.
     * Returns the resolved locale code. Pass an explicit $uri to override
     * $_SERVER['REQUEST_URI'] (useful in tests). $serveHidden: the request is a provider callback, which a
     * hidden store answers too (see ApiRoute::servesHiddenStore).
     */
    public static function initLocale(?string $uri = null, bool $serveHidden = false): string {
        $uri = self::storeRouteUri($uri ?? ($_SERVER['REQUEST_URI'] ?? ''));
        $context = self::initStoreContext($uri);
        if (!$context->isPublic()) {
            if (!$serveHidden) self::hiddenStoreNotFound();
            if (!headers_sent()) header('X-Robots-Tag: noindex');
        }
        $lang = I18n::detectLocale($uri);
        $requested = strtolower(Security::str($_GET['lang'] ?? ''));
        $languages = array_map('strtolower', (array)($context->config()['languages'] ?? []));
        if ($requested !== '' && preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $requested) && in_array($requested, $languages, true)) {
            $lang = $requested;
        }
        I18n::setLocale($lang);
        return $lang;
    }

    /**
     * R14 T2: the pages of one order that a gateway returns to and the payment link opens. Each carries its own order
     * token, so a hidden store's (test) order is paid and confirmed there, as its webhooks are. Never a cart action.
     */
    public static function servesHiddenOrderPage(array $get, array $post): bool {
        return in_array(Security::clean($get['page'] ?? ''), ['pay', 'order-done', 'checkout-cancel'], true) && empty($post['cart_action']);
    }

    /** A store that is not public answers its domain and URL prefix with 404, before any page or cart work. */
    private static function hiddenStoreNotFound(): never {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex');
        }
        echo "Not Found\n";
        exit;
    }

    private static function storeRouteUri(string $uri): string {
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
        $base = Template::baseUrl();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        return $path;
    }

    /** Validate any persisted customer session before storefront rendering. */
    public static function validateCustomerSession(): void {
        Customers::isLoggedIn();
    }

    /** Localized category name from categories.translations; falls back to the base name. */
    public static function categoryName(string $name, string $locale): string {
        if (self::$categoryNames === null) {
            self::$categoryNames = [];
            try {
                $rows = Db::get()->query("SELECT name, translations FROM categories WHERE translations IS NOT NULL AND translations != '' AND translations != '{}'")->fetchAll();
                foreach ($rows as $row) {
                    foreach ((json_decode((string)($row['translations'] ?? '{}'), true) ?: []) as $code => $translation) {
                        if (!empty($translation['name'])) self::$categoryNames[$code][$row['name']] = $translation['name'];
                    }
                }
            } catch (Throwable) {
                // Installation may not have the categories table yet.
            }
        }
        return self::$categoryNames[$locale][$name] ?? $name;
    }

    /** Query parameters that identify a storefront page; sort, filters and tracking never do. */
    public const CANONICAL_QUERY_PARAMS = ['page', 'slug', 'category', 'pg', 'q', 'p', 'order', 'id'];

    /**
     * Current request as a base-less local path (/cs/?page=category&slug=x&pg=2) for alternates
     * and the language switcher: the deployment base path is removed (Template::url adds it back)
     * and only the CANONICAL_QUERY_PARAMS survive.
     */
    public static function canonicalRequestPath(?string $uri = null): string {
        $uri = $uri ?? (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = Template::seoPath((string)(parse_url($uri, PHP_URL_PATH) ?: '/'));
        if (str_ends_with($path, '/index.php')) $path = substr($path, 0, -9);
        // R22: /cs//evil.example/ (a 404) left "//evil.example/" once the store prefix was cut: a protocol-relative link
        // to another site in the store switch. Repeated slashes are collapsed.
        $path = (string)preg_replace('#/{2,}#', '/', $path);
        parse_str((string)(parse_url($uri, PHP_URL_QUERY) ?? ''), $query);
        $kept = [];
        foreach (self::CANONICAL_QUERY_PARAMS as $key) {
            if (is_scalar($query[$key] ?? null) && (string)$query[$key] !== '') $kept[$key] = (string)$query[$key];
        }
        return ($path === '' ? '/' : $path) . ($kept === [] ? '' : '?' . http_build_query($kept));
    }

    /**
     * Build hreflang alternates for the current request from stores.json.
     * Returns:
     *   'alternates'     => [locale => url_prefix, ...] (one per store)
     *   'pathNoPrefix'   => request path with the matched store prefix stripped
     *   'xDefaultPrefix' => url_prefix of the default_store (for hreflang=x-default)
     *   'secondary'      => [locale => url_prefix, ...] the further `languages` of a store, served as
     *                       <prefix><path>?lang=<locale> (initLocale); a locale that is some store's
     *                       default_locale is left to that store, so every hreflang code has one URL
     *   'regional'       => [locale-COUNTRY => ['prefix' => url_prefix, 'locale' => locale], ...] the remaining store
     *                       languages (<prefix><path>?lang=<locale>), qualified by the store's legal.country
     *   'stores'         => [hreflang code => store id] of every alternate above, and 'xDefaultStore' (R23: a store
     *                       on its own domain is linked there, see storeBase())
     * Pass an explicit $uri to override $_SERVER['REQUEST_URI'] (tests).
     */
    public static function hreflangAlternates(?string $uri = null): array {
        $storesCfg = self::stores();
        $public = array_filter(is_array($storesCfg['stores'] ?? null) ? $storesCfg['stores'] : [], static fn($store): bool => is_array($store) && StoreContext::isPublicConfig($store));
        $alternates = [];
        $storeOf = [];
        foreach ($public as $id => $store) {
            $locale = $store['default_locale'] ?? 'en';
            $alternates[$locale] = $store['url_prefix'] ?? '';
            $storeOf[$locale] = (string)$id;
        }
        $secondary = [];
        foreach ($public as $id => $store) {
            foreach ((array)($store['languages'] ?? []) as $locale) {
                $locale = strtolower((string)$locale);
                if (!isset($alternates[$locale]) && !isset($secondary[$locale])) {
                    $secondary[$locale] = $store['url_prefix'] ?? '';
                    $storeOf[$locale] = (string)$id;
                }
            }
        }
        // R21: every other store language is its own page too (English in the HU store, Czech in the SK store); it is
        // announced language-region qualified by the store's seller country (en-HU), so each code still has one URL.
        $regional = [];
        foreach ($public as $id => $store) {
            $country = strtoupper((string)($store['legal']['country'] ?? ''));
            if (!preg_match('/^[A-Z]{2}$/', $country)) continue;
            $prefix = $store['url_prefix'] ?? '';
            foreach ((array)($store['languages'] ?? []) as $locale) {
                $locale = strtolower((string)$locale);
                // The language is already announced on this store's own URL (R23: by store, two stores on their own
                // domains may share the empty prefix).
                if (($storeOf[$locale] ?? null) === (string)$id) continue;
                if (!isset($regional[$locale . '-' . $country])) {
                    $regional[$locale . '-' . $country] = ['prefix' => $prefix, 'locale' => $locale];
                    $storeOf[$locale . '-' . $country] = (string)$id;
                }
            }
        }

        $pathNoPrefix = $uri ?? self::canonicalRequestPath();
        foreach ($alternates as $p) {
            if ($p !== '' && str_starts_with($pathNoPrefix, $p . '/')) {
                $pathNoPrefix = substr($pathNoPrefix, strlen($p));
                break;
            }
            if ($p !== '' && $pathNoPrefix === $p) { $pathNoPrefix = '/'; break; }
        }
        if ($pathNoPrefix === '') $pathNoPrefix = '/';
        // R22: never a protocol-relative "//host" (or "/\host") that the links built from it would send off-site.
        $pathNoPrefix = (string)preg_replace('#^[/\\\\]+#', '/', $pathNoPrefix);

        $defaultStore = $storesCfg['default_store'] ?? '';
        $xDefaultPrefix = ($storesCfg['stores'][$defaultStore] ?? ['url_prefix' => ''])['url_prefix'] ?? '';

        return [
            'alternates'     => $alternates,
            'pathNoPrefix'   => $pathNoPrefix,
            'xDefaultPrefix' => $xDefaultPrefix,
            'secondary'      => $secondary,
            'regional'       => $regional,
            'stores'         => $storeOf,
            'xDefaultStore'  => (string)$defaultStore,
        ];
    }

    /**
     * R23: absolute base URL of a store's pages: app_url moved to the store's own domain (Security::storeAppUrl()), the
     * app_url itself for a store without one (or unknown). Null without app_url: the links stay relative.
     */
    public static function storeBase(string $storeId): ?string {
        $store = self::stores()['stores'][$storeId] ?? null;
        $appUrl = Security::configuredAppUrl();
        if ($appUrl === null) return null;
        return is_array($store) ? Security::storeAppUrl($appUrl, $store) : $appUrl;
    }

    /**
     * R23: a local URL (Template::url output) of $storeId's pages as a link from the current page: relative while that
     * store lives on the current store's base URL, absolute on its own domain otherwise (the CZ store on shop.cz linked
     * from shop.sk opened the SK store under the CZ prefix, in euros). $absolute: always absolute (hreflang, canonical).
     */
    public static function storeHref(string $storeId, string $url, bool $absolute = false): string {
        $base = self::storeBase($storeId);
        if ($base === null) return $url;
        if (!$absolute && $base === Security::currentStoreAppUrl()) return $url;
        return $base . Template::seoPath($url);
    }

    /** Set ?lang=<locale> (or &lang=) on a local URL, replacing a lang it already has; a #fragment stays last. */
    public static function withLang(string $url, string $locale): string {
        [$url, $fragment] = array_pad(explode('#', self::withoutLang($url), 2), 2, null);
        return $url . (str_contains($url, '?') ? '&' : '?') . 'lang=' . rawurlencode($locale) . ($fragment === null ? '' : '#' . $fragment);
    }

    /** A local URL without its lang query parameter. */
    public static function withoutLang(string $url): string {
        $clean = (string)preg_replace('/([?&])lang=[^&#]*(&|(?=#)|$)/', '$1', $url);
        return (string)preg_replace('/[?&](?=#|$)/', '', $clean);
    }

    /**
     * The active locale when it is a non-primary language of the current store (selected by ?lang=), else null. Store
     * links, forms and the storefront API carry it. R20: also a language that is another store's own (English in the
     * HU store): the visitor stays in this store and its currency instead of falling back to its language on the next page.
     */
    public static function secondaryLocale(): ?string {
        if (!StoreContext::hasCurrent()) return null;
        $context = StoreContext::current();
        $locale = I18n::locale();
        $languages = array_map('strtolower', (array)($context->config()['languages'] ?? []));
        if ($locale === $context->defaultLocale() || !in_array($locale, $languages, true)) return null;
        // Another store's own language counts only as this request chose it (initLocale): ?lang=en in the HU store.
        $storeDefaults = array_map('strtolower', array_map('strval', array_keys(self::hreflangAlternates('/')['alternates'])));
        return !in_array($locale, $storeDefaults, true) || strtolower(Security::str($_GET['lang'] ?? '')) === $locale ? $locale : null;
    }
}
