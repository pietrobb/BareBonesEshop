<?php
/** Built-in domain-neutral section data providers. */
final class StorefrontCoreSectionProviders
{
    public static function register(): void
    {
        self::provider('core.banner', static function(array $parameters): array {
            $slot = (string)($parameters['slot'] ?? 'homepage');
            $item = ContentService::banners($slot, I18n::locale())[0] ?? null;
            return ['item' => $item ? self::banner($item) : null];
        });
        self::provider('core.featured-categories', static function(array $parameters): array {
            $limit = self::limit($parameters, 6);
            return ['items' => array_map([self::class, 'category'], Categories::featured($limit))];
        });
        self::provider('core.latest-products', static function(array $parameters): array {
            $limit = self::limit($parameters, 12);
            return ['items' => array_map([StorefrontViewModel::class, 'productCard'], Products::latest($limit))];
        });
        self::provider('core.curated-products', static function(array $parameters): array {
            $items = [];
            foreach (array_slice((array)($parameters['ids'] ?? []), 0, 100) as $id) {
                $row = Products::byId((int)$id);
                if ($row) $items[(int)$row['id']] = StorefrontViewModel::productCard($row);
            }
            foreach (array_slice((array)($parameters['slugs'] ?? []), 0, 100) as $slug) {
                $row = Products::bySlug(Security::slug((string)$slug));
                if ($row) $items[(int)$row['id']] = StorefrontViewModel::productCard($row);
            }
            return ['items' => array_values($items)];
        });
        self::provider('core.category-products', static function(array $parameters): array {
            $category = !empty($parameters['id'])
                ? Categories::byId((int)$parameters['id'])
                : Categories::bySlug(Security::slug((string)($parameters['slug'] ?? '')));
            if (!$category) return ['category' => null, 'items' => []];
            $result = Products::byCategory((int)$category['id'], 1, self::limit($parameters, 12), (string)($parameters['sort'] ?? 'newest'));
            return ['category' => self::category($category), 'items' => array_map([StorefrontViewModel::class, 'productCard'], $result['products'])];
        });
    }

    private static function provider(string $name, callable $provider): void
    {
        if (!StorefrontSectionRegistry::has($name)) StorefrontSectionRegistry::register($name, $provider);
    }

    private static function limit(array $parameters, int $default): int
    {
        return max(1, min(100, (int)($parameters['limit'] ?? $default)));
    }

    public static function category(array $row): array
    {
        $row = I18n::category($row);
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'slug' => (string)$row['slug'],
            'url' => Template::storeUrl('/category/' . rawurlencode((string)($row['path'] ?? $row['slug']))),
            'count' => (int)($row['product_count'] ?? 0),
        ];
    }

    private static function banner(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'body_html' => Security::sanitizeRichHtml((string)$row['body']),
            'image_url' => self::url((string)$row['image_url']),
            'link_url' => self::url((string)$row['link_url']),
            'link_label' => (string)$row['link_label'],
        ];
    }

    private static function url(string $url): string
    {
        if ($url === '' || preg_match('#^https://#i', $url)) return $url;
        return Template::storeUrl('/' . ltrim($url, '/'));
    }
}
