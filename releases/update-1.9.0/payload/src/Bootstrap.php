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
    private static ?array $catTranslations = null;

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

    /**
     * Detect the locale from the request URI and apply it once.
     * Returns the resolved locale code. Pass an explicit $uri to override
     * $_SERVER['REQUEST_URI'] (useful in tests).
     */
    public static function initLocale(?string $uri = null): string {
        $uri = $uri ?? ($_SERVER['REQUEST_URI'] ?? '');
        $lang = I18n::detectLocale($uri);
        I18n::setLocale($lang);
        return $lang;
    }

    /**
     * Legacy category-name fallback (locale => [English name => translated]).
     * Cached per request. Used for category names not yet migrated to the
     * categories.translations JSON column.
     */
    public static function catTranslations(): array {
        if (self::$catTranslations === null) {
            $file = __DIR__ . '/../data/category-translations.php';
            self::$catTranslations = is_file($file) ? (require $file) : [];
        }
        return self::$catTranslations;
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
