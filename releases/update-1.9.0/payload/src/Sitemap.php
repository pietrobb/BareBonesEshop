<?php
/**
 * Sitemap — dynamic multi-locale XML sitemap generator.
 *
 * Emits one <url> entry per (canonical path × store locale), each carrying the
 * full set of <xhtml:link rel="alternate" hreflang="…"> alternates plus an
 * x-default, so search engines see every localized variant of every page.
 *
 * Canonical (locale-neutral, prefix-less) paths covered:
 *   /                       homepage
 *   /category/{slug}        every category
 *   /product/{slug}         every active product
 *   /page/{slug}            every active CMS page
 *
 * The generation is pure (string in, string out) so it can be unit-tested
 * without an HTTP round-trip.
 */
class Sitemap {
    /**
     * Store locale => url_prefix map, e.g. ['sk' => '', 'cs' => '/cs', 'pl' => '/pl'].
     * Reuses the same source of truth as the storefront hreflang tags.
     */
    public static function storePrefixes(): array {
        return Bootstrap::hreflangAlternates('/')['alternates'];
    }

    /** url_prefix of the default store (for hreflang=x-default). */
    public static function xDefaultPrefix(): string {
        return Bootstrap::hreflangAlternates('/')['xDefaultPrefix'];
    }

    /**
     * Collect every canonical (prefix-less) path that should appear in the
     * sitemap. Always includes the homepage; category/product/page rows are
     * pulled from the DB and skipped gracefully if a table is missing.
     */
    public static function paths(): array {
        $paths = ['/'];

        try {
            $db = Db::get();
            foreach ($db->query("SELECT slug FROM categories WHERE slug IS NOT NULL AND slug != '' ORDER BY slug")->fetchAll(PDO::FETCH_COLUMN) as $slug) {
                $paths[] = '/category/' . $slug;
            }
            foreach ($db->query("SELECT slug FROM products WHERE status = 'active' AND slug IS NOT NULL AND slug != '' ORDER BY slug")->fetchAll(PDO::FETCH_COLUMN) as $slug) {
                $paths[] = '/product/' . $slug;
            }
            foreach ($db->query("SELECT slug FROM pages WHERE status = 'active' AND slug IS NOT NULL AND slug != '' ORDER BY slug")->fetchAll(PDO::FETCH_COLUMN) as $slug) {
                $paths[] = '/page/' . $slug;
            }
        } catch (\Throwable $ignore) {
            /* fresh install — tables may not exist yet; homepage alone is valid */
        }

        return $paths;
    }

    /**
     * Render the full sitemap XML for the given absolute base (scheme + host,
     * no trailing slash), e.g. "https://shop.example".
     */
    public static function xml(string $base): string {
        $base = rtrim($base, '/');
        $prefixes = self::storePrefixes();
        $xDefault = self::xDefaultPrefix();
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_XML1, 'UTF-8');

        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
              . 'xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        foreach (self::paths() as $path) {
            $rootPath = ($path === '/') ? '' : $path;
            foreach ($prefixes as $prefix) {
                $loc = $base . $prefix . ($rootPath === '' ? '/' : $rootPath);
                $out .= "  <url>\n";
                $out .= '    <loc>' . $e($loc) . "</loc>\n";
                foreach ($prefixes as $altLocale => $altPrefix) {
                    $href = $base . $altPrefix . ($rootPath === '' ? '/' : $rootPath);
                    $out .= '    <xhtml:link rel="alternate" hreflang="' . $e($altLocale)
                          . '" href="' . $e($href) . "\"/>\n";
                }
                $xHref = $base . $xDefault . ($rootPath === '' ? '/' : $rootPath);
                $out .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . $e($xHref) . "\"/>\n";
                $out .= "  </url>\n";
            }
        }

        $out .= "</urlset>\n";
        return $out;
    }
}
