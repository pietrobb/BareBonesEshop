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
            $file = __DIR__ . '/../stores.json';
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
     * $_SERVER['REQUEST_URI'] (useful in tests).
     */
    public static function initLocale(?string $uri = null): string {
        $uri = self::storeRouteUri($uri ?? ($_SERVER['REQUEST_URI'] ?? ''));
        $context = self::initStoreContext($uri);
        $lang = I18n::detectLocale($uri);
        $requested = strtolower((string)($_GET['lang'] ?? ''));
        $languages = array_map('strtolower', (array)($context->config()['languages'] ?? []));
        if ($requested !== '' && preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $requested) && in_array($requested, $languages, true)) {
            $lang = $requested;
        }
        I18n::setLocale($lang);
        return $lang;
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

    /**
     * Build hreflang alternates for the current request from stores.json.
     * Returns:
     *   'alternates'     => [locale => url_prefix, ...] (one per store)
     *   'pathNoPrefix'   => request path with the matched store prefix stripped
     *   'xDefaultPrefix' => url_prefix of the default_store (for hreflang=x-default)
     * Pass an explicit $uri to override $_SERVER['REQUEST_URI'] (tests).
     */
    public static function hreflangAlternates(?string $uri = null): array {
        $storesCfg = self::stores();
        $alternates = [];
        foreach ($storesCfg['stores'] ?? [] as $store) {
            $locale = $store['default_locale'] ?? 'en';
            $alternates[$locale] = $store['url_prefix'] ?? '';
        }

        $pathNoPrefix = $uri ?? ($_SERVER['REQUEST_URI'] ?? '/');
        foreach ($alternates as $p) {
            if ($p !== '' && str_starts_with($pathNoPrefix, $p . '/')) {
                $pathNoPrefix = substr($pathNoPrefix, strlen($p));
                break;
            }
            if ($p !== '' && $pathNoPrefix === $p) { $pathNoPrefix = '/'; break; }
        }
        if ($pathNoPrefix === '') $pathNoPrefix = '/';

        $defaultStore = $storesCfg['default_store'] ?? '';
        $xDefaultPrefix = ($storesCfg['stores'][$defaultStore] ?? ['url_prefix' => ''])['url_prefix'] ?? '';

        return [
            'alternates'     => $alternates,
            'pathNoPrefix'   => $pathNoPrefix,
            'xDefaultPrefix' => $xDefaultPrefix,
        ];
    }
}
