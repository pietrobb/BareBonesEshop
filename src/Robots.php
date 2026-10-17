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
    /** Paths crawlers must not index. */
    private const DISALLOW = [
        '/admin',
        '/admin.php',
        '/api/',
        '/account',
        '/account.php',
        '/cart',
        '/checkout',
        '/order-done',
        '/cache/',
        '/*?*add=',     // cart mutation query params
        '/*?*sort=',    // faceted-sort URL noise
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
