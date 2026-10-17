<?php
/**
 * Robots — robots.txt body generator.
 *
 * Disallows transactional / private / internal paths that have no SEO value
 * and must never be indexed (cart, checkout, account, admin, api, raw config),
 * allows the public catalog, and advertises the absolute sitemap URL.
 *
 * Pure (base string in, body string out) so it is unit-testable without HTTP.
 */
class Robots {
    /**
     * Paths crawlers must not fetch, matching the URLs the storefront really links: transactional
     * pages are query routes (/?page=cart, /cs/?page=checkout), account/wishlist/API are scripts that
     * may sit under a store url_prefix (/cs/account.php). Category pagination (?pg=) stays crawlable:
     * listing links only carry sort= for a non-default order.
     */
    private const DISALLOW = [
        '/admin',
        '/admin.php',
        '/api/',
        '/*/api/',
        '/account',
        '/*/account.php',
        '/wishlist',
        '/*/wishlist',
        '/*?*page=cart',
        '/*?*page=checkout',
        '/*?*page=order-done',
        '/*?*cart_recover=',
        '/*?*cart_unsubscribe=',
        '/checkout/',
        '/cache/',
        '/*?*add=',     // cart mutation query params
        '/*?*sort=',    // non-default sort orders duplicate the canonical listing
    ];

    /**
     * Render the robots.txt body for the given absolute base
     * (scheme + host, no trailing slash), e.g. "https://shop.example".
     * Without a base the Sitemap directive is omitted (it must be absolute).
     */
    public static function txt(?string $base): string {
        $lines = ['User-agent: *'];
        foreach (self::DISALLOW as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $lines[] = 'Allow: /';
        if ($base !== null) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . rtrim($base, '/') . '/sitemap.xml';
        }

        return implode("\n", $lines) . "\n";
    }
}
