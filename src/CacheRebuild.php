<?php
/**
 * The cache_rebuild job: a full rebuild (category trees, first listing page and filters of every category) or the
 * regeneration of one cached path. Runs in the store that queued it (_store_id): its cache namespace, languages and
 * catalog only. Sized for large catalogs: one listing query per category for all languages. (It wrote per-group price
 * maps, cache/prices/*.json, that nothing read: shop prices come from price lists and promotions.)
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
