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
}
