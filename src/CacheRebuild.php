<?php
/**
 * The cache_rebuild job: a full rebuild (category trees, first listing page and filters of every category, price
 * maps per customer group) or the regeneration of one cached path. Runs in the store that queued it (_store_id):
 * its cache namespace, languages and catalog only. Sized for large catalogs: one listing query per category for
 * all languages, price maps streamed to disk.
 */
final class CacheRebuild
{
    public static function run(array $payload, array $cfg): void
    {
        $store = StoreContext::current();
        $locales = array_values(array_unique(array_map('strval', (array)($store->config()['languages'] ?? [])))) ?: [$store->defaultLocale()];
        $perPage = (int)($cfg['per_page'] ?? 24);
        if (($payload['scope'] ?? 'partial') === 'full' || !empty($payload['prefix'])) {
            self::full($locales, $perPage);
        } elseif (!empty($payload['path'])) {
            self::path((string)$payload['path'], $perPage, (string)($cfg['cache_dir'] ?? ''));
        }
    }

    /** @param list<string> $locales */
    private static function full(array $locales, int $perPage): void
    {
        foreach ($locales as $locale) {
            I18n::setLocale($locale);
            Cache::writeJson(Cache::localePath($locale, 'categories.json'), ['categories' => self::translatedTree()]);
        }

        // One catalog query per category, translated into every language: the same queries used to run again for
        // each language (with a listing query that scanned the whole catalog, ~20 h at 5000 categories).
        foreach (CatalogScope::rows('SELECT * FROM categories WHERE store_id = :catalog') as $cat) {
            JobQueue::heartbeat();
            $listing = Products::byCategory((int)$cat['id'], 1, $perPage, 'price_asc');
            $raw = Products::filterValues((int)$cat['id']);
            foreach ($locales as $locale) {
                I18n::setLocale($locale);
                $data = $listing;
                $data['products'] = Products::enrichMany(array_map([I18n::class, 'product'], $listing['products']));
                $data['category'] = Categories::enrich(I18n::category($cat)); // as apiCategory() caches it
                Cache::writeJson(Cache::localePath($locale, "cat/{$cat['slug']}/page-1.json"), $data);
                Cache::writeJson(Cache::localePath($locale, "cat/{$cat['slug']}/filters.json"), ['filters' => self::filters($raw)]);
            }
        }

        $groups = array_keys(Pricing::config()['customer_groups'] ?? ['guest' => []]);
        if (!in_array('guest', $groups, true)) array_unshift($groups, 'guest');
        foreach ($groups as $group) {
            JobQueue::heartbeat();
            Cache::writeStream("prices/{$group}.json", static fn($out) => self::writePrices($out, (string)$group));
        }
    }

    /**
     * The {sku: price} map of one customer group, streamed in id-keyset chunks: the whole active catalog held in
     * PHP did not fit in a 128 MB worker. Buffered chunks, because Pricing::apply may query and an open unbuffered
     * MySQL result forbids that.
     *
     * @param resource $out
     */
    private static function writePrices($out, string $group): void
    {
        $stmt = Db::get()->prepare("SELECT id, sku, price, category_id FROM products
            WHERE store_id = :catalog AND status = 'active' AND id > :after ORDER BY id LIMIT 2000");
        $first = true;
        $after = 0;
        do {
            $stmt->execute([':catalog' => StoreContext::current()->catalogId(), ':after' => $after]);
            $chunk = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($chunk as $p) {
                fwrite($out, ($first ? '{' : ',') . json_encode((string)$p['sku'], JSON_UNESCAPED_UNICODE) . ':' . json_encode(Pricing::apply($p, $group)['price']));
                $first = false;
                $after = (int)$p['id'];
            }
        } while (count($chunk) === 2000);
        fwrite($out, $first ? '[]' : '}');
    }

    private static function path(string $path, int $perPage, string $cacheDir): void
    {
        if (preg_match('#^([a-z]{2})/cat/([a-z0-9-]+)/page-(\d+)\.json$#', $path, $m)) {
            I18n::setLocale($m[1]);
            $cat = Categories::bySlug($m[2]);
            if ($cat) {
                $data = Products::byCategory((int)$cat['id'], (int)$m[3], $perPage, 'price_asc');
                $data['products'] = Products::enrichMany(array_map([I18n::class, 'product'], $data['products']));
                $data['category'] = Categories::enrich(I18n::category($cat));
                Cache::writeJson(Cache::localePath($m[1], "cat/{$m[2]}/page-{$m[3]}.json"), $data);
            }
        } elseif (preg_match('#^([a-z]{2})/product/([a-z0-9-]+)\.json$#', $path, $m)) {
            I18n::setLocale($m[1]);
            $product = Products::bySlug($m[2]);
            if ($product) {
                $product = Products::enrich(I18n::product($product));
                $product['variants'] = Products::variants($product['id']);
                $product['attributes'] = Products::attrs($product['id']);
                Cache::writeJson(Cache::localePath($m[1], "product/{$m[2]}.json"), ['product' => $product]);
            }
        } elseif (preg_match('#^([a-z]{2})/categories\.json$#', $path, $m)) {
            I18n::setLocale($m[1]);
            Cache::writeJson(Cache::localePath($m[1], 'categories.json'), ['categories' => self::translatedTree()]);
        } elseif ($cacheDir !== '' && file_exists($cacheDir . '/' . $path)) {
            // Unknown pattern — mark stale for on-demand regeneration
            touch($cacheDir . '/' . $path, 1);
        }
    }

    /** Category tree of the current catalog in the current language (two levels enriched). */
    private static function translatedTree(): array
    {
        return array_map(static function (array $cat): array {
            $cat = Categories::enrich(I18n::category($cat));
            if (!empty($cat['children'])) {
                $cat['children'] = array_map(static fn(array $c): array => Categories::enrich(I18n::category($c)), $cat['children']);
            }
            return $cat;
        }, Categories::tree());
    }

    /** @param array<string, list<array{value: string, count: int}>> $raw */
    private static function filters(array $raw): array
    {
        $filters = [];
        foreach ($raw as $attrName => $options) {
            $filters[] = [
                'attr_name' => $attrName,
                'label'     => I18n::attrLabel($attrName),
                'options'   => array_map(static fn(array $opt): array => [
                    'value' => $opt['value'],
                    'label' => I18n::attr($attrName, $opt['value']),
                    'count' => $opt['count'],
                ], $options),
            ];
        }
        return $filters;
    }
}
