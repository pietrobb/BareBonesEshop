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
     * sitemap for one store (default: the active store). Always includes the
     * homepage; categories/products come from the store's catalog, CMS pages
     * from the store itself. Missing tables are skipped gracefully.
     */
    public static function paths(?StoreContext $store = null): array {
        $store ??= StoreContext::current();
        $paths = ['/'];

        try {
            $db = Db::get();
            $queries = [
                '/category/' => ["SELECT slug FROM categories WHERE store_id = :store AND slug IS NOT NULL AND slug != '' ORDER BY slug", $store->catalogId()],
                '/product/' => ["SELECT slug FROM products WHERE store_id = :store AND status = 'active' AND slug IS NOT NULL AND slug != '' ORDER BY slug", $store->catalogId()],
                '/page/' => ["SELECT slug FROM pages WHERE store_id = :store AND status = 'active' AND slug IS NOT NULL AND slug != '' ORDER BY slug", $store->id()],
            ];
            foreach ($queries as $route => [$sql, $owner]) {
                $stmt = $db->prepare($sql);
                $stmt->execute([':store' => $owner]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $slug) $paths[] = $route . $slug;
            }
        } catch (\Throwable $ignore) {
            /* fresh install — tables may not exist yet; homepage alone is valid */
        }

        return array_values(array_unique(array_map([UrlAliases::class, 'outbound'], $paths)));
    }

    /**
     * Storefront stores keyed by store id => [locale, url_prefix, path set].
     * hreflang alternates are emitted only between stores that serve the path.
     */
    private static function storePathSets(): array {
        $config = Bootstrap::stores();
        $sets = [];
        foreach (array_keys($config['stores'] ?? []) as $storeId) {
            try {
                $store = StoreContext::resolve($config, '/', '', (string)$storeId);
            } catch (InvalidArgumentException) {
                continue; // store without a storefront channel has no public URLs
            }
            $sets[(string)$storeId] = [
                'locale' => (string)($store->config()['default_locale'] ?? 'en'),
                'prefix' => (string)($store->config()['url_prefix'] ?? ''),
                'paths' => array_flip(self::paths($store)),
            ];
        }
        return $sets;
    }

    /**
     * Render the full sitemap XML for the given absolute base (scheme + host,
     * no trailing slash), e.g. "https://shop.example".
     */
    public static function xml(string $base): string {
        $base = rtrim($base, '/');
        $sets = self::storePathSets();
        $defaultStore = (string)(Bootstrap::stores()['default_store'] ?? '');
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_XML1, 'UTF-8');

        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
              . 'xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        $allPaths = [];
        foreach ($sets as $set) $allPaths += $set['paths'];
        foreach (array_keys($allPaths) as $path) {
            $path = (string)$path;
            $rootPath = ($path === '/') ? '/' : $path;
            $serving = array_filter($sets, static fn(array $set): bool => isset($set['paths'][$path]));
            foreach ($serving as $set) {
                $out .= "  <url>\n";
                $out .= '    <loc>' . $e($base . $set['prefix'] . $rootPath) . "</loc>\n";
                $alternates = [];
                foreach ($serving as $alt) $alternates[$alt['locale']] ??= $alt['prefix'];
                foreach ($alternates as $altLocale => $altPrefix) {
                    $out .= '    <xhtml:link rel="alternate" hreflang="' . $e((string)$altLocale)
                          . '" href="' . $e($base . $altPrefix . $rootPath) . "\"/>\n";
                }
                if (isset($serving[$defaultStore])) {
                    $xHref = $base . $serving[$defaultStore]['prefix'] . $rootPath;
                    $out .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . $e($xHref) . "\"/>\n";
                }
                $out .= "  </url>\n";
            }
        }

        $out .= "</urlset>\n";
        return $out;
    }
}
