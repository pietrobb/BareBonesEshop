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

    /** Protocol limit of <url> entries per sitemap file (sitemaps.org). */
    public const MAX_URLS = 50000;
    private static int $maxUrls = self::MAX_URLS;

    /** Test hook: a lower per-file limit exercises the sitemap index without 50k rows. */
    public static function setMaxUrlsForTesting(?int $max): void {
        self::$maxUrls = $max === null ? self::MAX_URLS : max(1, $max);
    }

    /**
     * Render the sitemap XML for the given absolute base (scheme + host + install path, no
     * trailing slash), e.g. "https://shop.example". Part 0 is /sitemap.xml: the full urlset while
     * it fits MAX_URLS, otherwise a <sitemapindex> of /sitemap-1.xml … /sitemap-N.xml (parts 1..N).
     * @throws OutOfRangeException for a part that does not exist
     */
    public static function xml(string $base, int $part = 0): string {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new RuntimeException('Unable to create sitemap stream');
        self::write($stream, $base, $part);
        rewind($stream);
        $xml = (string)stream_get_contents($stream);
        fclose($stream);
        return $xml;
    }

    /**
     * Stream one sitemap document (see xml()) into $out.
     * @param resource $out
     * @throws OutOfRangeException for a part that does not exist
     */
    public static function write($out, string $base, int $part = 0): void {
        $base = rtrim($base, '/');
        $sets = self::storePathSets();
        $total = 0;
        foreach (self::allPaths($sets) as $path) {
            foreach ($sets as $set) if (isset($set['paths'][$path])) $total++;
        }
        $parts = max(1, (int)ceil($total / self::$maxUrls));
        if ($part < 0 || $part > $parts || ($part > 0 && $parts === 1)) throw new OutOfRangeException('Unknown sitemap part');
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_XML1, 'UTF-8');
        fwrite($out, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        if ($part === 0 && $parts > 1) {
            fwrite($out, '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");
            for ($number = 1; $number <= $parts; $number++) {
                fwrite($out, '  <sitemap><loc>' . $e($base . '/sitemap-' . $number . '.xml') . "</loc></sitemap>\n");
            }
            fwrite($out, "</sitemapindex>\n");
            return;
        }

        fwrite($out, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
              . 'xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n");
        $first = $part === 0 ? 0 : ($part - 1) * self::$maxUrls;
        $last = $part === 0 ? PHP_INT_MAX : $first + self::$maxUrls;
        $defaultStore = (string)(Bootstrap::stores()['default_store'] ?? '');
        $index = 0;
        foreach (self::allPaths($sets) as $path) {
            $serving = array_filter($sets, static fn(array $set): bool => isset($set['paths'][$path]));
            foreach ($serving as $set) {
                if ($index++ < $first) continue;
                if ($index > $last) break 2;
                $entry = "  <url>\n" . '    <loc>' . $e($base . $set['prefix'] . $path) . "</loc>\n";
                $alternates = [];
                foreach ($serving as $alt) $alternates[$alt['locale']] ??= $alt['prefix'];
                foreach ($alternates as $altLocale => $altPrefix) {
                    $entry .= '    <xhtml:link rel="alternate" hreflang="' . $e((string)$altLocale)
                          . '" href="' . $e($base . $altPrefix . $path) . "\"/>\n";
                }
                if (isset($serving[$defaultStore])) {
                    $entry .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'
                          . $e($base . $serving[$defaultStore]['prefix'] . $path) . "\"/>\n";
                }
                fwrite($out, $entry . "  </url>\n");
            }
        }
        fwrite($out, "</urlset>\n");
    }

    /** @return list<string> every path served by at least one store, in a stable order */
    private static function allPaths(array $sets): array {
        $allPaths = [];
        foreach ($sets as $set) $allPaths += $set['paths'];
        return array_map('strval', array_keys($allPaths));
    }
}
