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
     * Storefront stores in config order: store id => locale, url_prefix, catalog and page owner.
     * hreflang alternates are emitted only between stores that serve the path.
     * @return array<string, array{locale: string, prefix: string, catalog: string, id: string}>
     */
    private static function stores(): array {
        $config = Bootstrap::stores();
        $stores = [];
        foreach (array_keys($config['stores'] ?? []) as $storeId) {
            try {
                $store = StoreContext::resolve($config, '/', '', (string)$storeId);
            } catch (InvalidArgumentException) {
                continue; // store without a storefront channel has no public URLs
            }
            $stores[(string)$storeId] = [
                'locale' => (string)($store->config()['default_locale'] ?? 'en'),
                'prefix' => (string)($store->config()['url_prefix'] ?? ''),
                'catalog' => $store->catalogId(),
                'id' => $store->id(),
            ];
        }
        return $stores;
    }

    /** Protocol limits per sitemap file (sitemaps.org): 50,000 <url> entries and 50 MB uncompressed. */
    public const MAX_URLS = 50000;
    public const MAX_BYTES = 50000000;
    private static int $maxUrls = self::MAX_URLS;
    private static int $maxBytes = self::MAX_BYTES;

    /** Test hook: a lower per-file limit exercises the sitemap index without 50k rows. */
    public static function setMaxUrlsForTesting(?int $max): void {
        self::$maxUrls = $max === null ? self::MAX_URLS : max(1, $max);
    }

    /** Test hook: a lower per-file byte limit exercises size-based splitting without 50 MB of XML. */
    public static function setMaxBytesForTesting(?int $max): void {
        self::$maxBytes = $max === null ? self::MAX_BYTES : max(1, $max);
    }

    private const URLSET_OPEN = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    private const URLSET_CLOSE = "</urlset>\n";

    /**
     * Render the sitemap XML for the given absolute base (scheme + host + install path, no
     * trailing slash), e.g. "https://shop.example". Part 0 is /sitemap.xml: the full urlset while
     * it fits MAX_URLS and MAX_BYTES, otherwise a <sitemapindex> of /sitemap-1.xml … /sitemap-N.xml (parts 1..N).
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
        $stores = self::stores();
        // Every hreflang alternate repeats in each store's <url>, so with several stores 50,000 URLs reached 45 MB:
        // parts are cut by both limits, which needs one pass over the entries to number them.
        $parts = 0;
        foreach (self::partEntries($stores, $base) as [$number]) $parts = $number;
        $parts = max(1, $parts);
        if ($part < 0 || $part > $parts || ($part > 0 && $parts === 1)) throw new OutOfRangeException('Unknown sitemap part');
        if ($part === 0 && $parts > 1) {
            $e = static fn(string $s): string => htmlspecialchars($s, ENT_XML1, 'UTF-8');
            fwrite($out, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
            fwrite($out, '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");
            for ($number = 1; $number <= $parts; $number++) {
                fwrite($out, '  <sitemap><loc>' . $e($base . '/sitemap-' . $number . '.xml') . "</loc></sitemap>\n");
            }
            fwrite($out, "</sitemapindex>\n");
            return;
        }

        fwrite($out, self::URLSET_OPEN);
        $wanted = max(1, $part);
        foreach (self::partEntries($stores, $base) as [$number, $entry]) {
            if ($number > $wanted) break;
            if ($number === $wanted) fwrite($out, $entry);
        }
        fwrite($out, self::URLSET_CLOSE);
    }

    /**
     * Every <url> entry with the number of the part it belongs to: a new part starts when the current one has
     * MAX_URLS entries or the next entry would take the file (with its urlset wrapper) past MAX_BYTES.
     * @return Generator<int, array{0: int, 1: string}>
     */
    private static function partEntries(array $stores, string $base): Generator {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_XML1, 'UTF-8');
        $defaultStore = (string)(Bootstrap::stores()['default_store'] ?? '');
        $wrapper = strlen(self::URLSET_OPEN) + strlen(self::URLSET_CLOSE);
        [$number, $count, $bytes] = [1, 0, $wrapper];
        foreach (self::entries($stores) as $urls) {
            $links = '';
            $alternates = [];
            foreach ($urls as $storeId => $altUrl) $alternates[$stores[$storeId]['locale']] ??= $altUrl;
            foreach ($alternates as $altLocale => $altUrl) {
                $links .= '    <xhtml:link rel="alternate" hreflang="' . $e((string)$altLocale)
                    . '" href="' . $e($base . $altUrl) . "\"/>\n";
            }
            if (isset($urls[$defaultStore])) {
                $links .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . $e($base . $urls[$defaultStore]) . "\"/>\n";
            }
            foreach ($urls as $url) {
                $entry = "  <url>\n" . '    <loc>' . $e($base . $url) . "</loc>\n" . $links . "  </url>\n";
                if ($count > 0 && ($count >= self::$maxUrls || $bytes + strlen($entry) > self::$maxBytes)) {
                    [$number, $count, $bytes] = [$number + 1, 0, $wrapper];
                }
                $count++;
                $bytes += strlen($entry);
                yield [$number, $entry];
            }
        }
    }

    /** Rows of one product keyset chunk (products.slug is globally unique, so it is the cursor). */
    private const PRODUCT_CHUNK = 1000;

    /**
     * Every sitemap path, streamed: store id => URL path (store prefix + localized path) of each store serving it.
     * Homepage, then categories, products and CMS pages by slug. Products are read in keyset chunks, never all
     * at once: 200k products × several stores used to exhaust the memory limit.
     * @return Generator<int, array<string, string>>
     */
    private static function entries(array $stores): Generator {
        $owners = static function (string $kind) use ($stores): array {
            $byOwner = [];
            foreach ($stores as $storeId => $store) $byOwner[$store[$kind]][] = $storeId;
            return $byOwner;
        };
        $urls = static function (string $path, array $storeIds, ?array $localized = null) use ($stores): array {
            $urls = [];
            foreach ($storeIds as $storeId) $urls[$storeId] = $stores[$storeId]['prefix'] . ($localized[$stores[$storeId]['locale']] ?? $path);
            return $urls;
        };
        yield $urls('/', array_keys($stores));

        $catalogs = $owners('catalog');
        foreach (self::slugOwners("SELECT DISTINCT slug, store_id FROM categories WHERE slug IS NOT NULL AND slug != ''", array_keys($catalogs)) as $slug => $catalogIds) {
            $serving = array_merge(...array_map(static fn(string $catalog): array => $catalogs[$catalog], $catalogIds));
            yield $urls(UrlAliases::outbound('/category/' . $slug), self::inStoreOrder($serving, $stores));
        }
        foreach (self::products(array_keys($catalogs)) as $row) {
            $translations = str_contains((string)$row['translations'], '"slug"') ? json_decode((string)$row['translations'], true) : null;
            $localized = [];
            foreach (is_array($translations) ? $translations : [] as $locale => $fields) {
                $slug = is_array($fields) ? (string)($fields['slug'] ?? '') : '';
                if ($slug !== '' && $slug !== $row['slug']) $localized[(string)$locale] = UrlAliases::outbound('/product/' . rawurlencode($slug));
            }
            yield $urls(UrlAliases::outbound('/product/' . $row['slug']), $catalogs[(string)$row['store_id']], $localized);
        }
        $pageOwners = $owners('id');
        foreach (self::slugOwners("SELECT DISTINCT slug, store_id FROM pages WHERE status = 'active' AND slug IS NOT NULL AND slug != ''", array_keys($pageOwners)) as $slug => $ownerIds) {
            $serving = array_merge(...array_map(static fn(string $owner): array => $pageOwners[$owner], $ownerIds));
            yield $urls(UrlAliases::outbound('/page/' . $slug), self::inStoreOrder($serving, $stores));
        }
    }

    /**
     * Slug => owner ids (catalogs or stores) for the small per-owner tables (categories, CMS pages), in slug order.
     * @return array<string, list<string>>
     */
    private static function slugOwners(string $sql, array $owners): array {
        if (!$owners) return [];
        $params = [];
        foreach (array_values($owners) as $i => $owner) $params[":o{$i}"] = (string)$owner;
        $grouped = [];
        try {
            $stmt = Db::get()->prepare($sql . ' AND store_id IN (' . implode(', ', array_keys($params)) . ')');
            $stmt->execute($params);
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) $grouped[(string)$row['slug']][] = (string)$row['store_id'];
        } catch (\Throwable) {
            return []; // fresh install — the table may not exist yet
        }
        ksort($grouped, SORT_STRING);
        return $grouped;
    }

    /**
     * Active products of the given catalogs in slug order, PRODUCT_CHUNK rows per query.
     * @return Generator<int, array{slug: string, store_id: string, translations: ?string}>
     */
    private static function products(array $catalogs): Generator {
        if (!$catalogs) return;
        $params = [];
        foreach (array_values($catalogs) as $i => $catalog) $params[":c{$i}"] = (string)$catalog;
        $sql = "SELECT slug, store_id, translations FROM products WHERE status = 'active' AND slug IS NOT NULL AND slug != ''
            AND store_id IN (" . implode(', ', array_keys($params)) . ')';
        $after = null;
        do {
            try {
                $stmt = Db::get()->prepare($sql . ($after === null ? '' : ' AND slug > :after') . ' ORDER BY slug LIMIT ' . self::PRODUCT_CHUNK);
                $stmt->execute($after === null ? $params : $params + [':after' => $after]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable) {
                return; // fresh install — no products table yet
            }
            foreach ($rows as $row) yield $row;
            $after = $rows ? (string)$rows[count($rows) - 1]['slug'] : null;
        } while (count($rows) === self::PRODUCT_CHUNK);
    }

    /** @return list<string> the given store ids in store config order */
    private static function inStoreOrder(array $storeIds, array $stores): array {
        return array_values(array_intersect(array_keys($stores), $storeIds));
    }
}
