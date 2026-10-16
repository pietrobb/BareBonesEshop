<?php
/** Canonical, theme-facing storefront data contract. */
final class StorefrontViewModel
{
    public static function product(array $row): array
    {
        $source = self::enrichedProduct($row);
        $productId = (int)$source['id'];
        $categories = self::categories($productId);
        $attributes = self::attributes($productId);
        $variants = array_map(
            static fn(array $variant): array => self::variant($source, $variant),
            Products::variants($productId)
        );
        $availability = self::availability($source);
        foreach ($variants as $index => $variant) {
            $variants[$index]['selected'] = false;
            if (!$availability['purchasable'] && $variant['availability']['purchasable']) $availability = $variant['availability'];
        }
        foreach ($variants as $index => $variant) {
            if ($variant['availability']['purchasable']) {
                $variants[$index]['selected'] = true;
                $availability = $variant['availability'];
                break;
            }
        }
        $images = self::images($source);
        $url = self::productUrl((string)$source['slug']);
        $currency = strtoupper((string)($source['currency'] ?? StoreContext::current()->currency()));
        $relations = [];
        foreach (Products::typedRelations($productId, 100) as $relation) {
            $type = (string)$relation['relation_type'];
            unset($relation['relation_type'], $relation['relation_sort_order']);
            $relations[$type][] = self::productCard($relation);
        }
        if (empty($relations['related'])) {
            $relations['related'] = array_map(
                static fn(array $related): array => self::productCard($related),
                Products::related($productId, 4)
            );
        }

        $breadcrumbs = [[
            'label' => I18n::t('home'),
            'url' => Template::storeUrl('/'),
            'current' => false,
        ]];
        foreach ($categories as $category) {
            $breadcrumbs[] = [
                'label' => $category['name'],
                'url' => $category['url'],
                'current' => false,
            ];
        }
        $breadcrumbs[] = ['label' => (string)$source['name'], 'url' => '', 'current' => true];

        $product = [
            'id' => $productId,
            'slug' => (string)$source['slug'],
            'sku' => (string)$source['sku'],
            'type' => (string)($source['product_type'] ?? 'simple'),
            'name' => (string)$source['name'],
            'summary' => (string)($source['summary'] ?? ''),
            'description_html' => Security::sanitizeRichHtml((string)($source['description'] ?? '')),
            'url' => $url,
            'currency' => $currency,
            'price' => self::price($source),
            'availability' => $availability,
            'images' => $images,
            'categories' => $categories,
            'attributes' => $attributes,
            'custom_fields' => self::customFields($source['attrs'] ?? []),
            'variants' => $variants,
            'requires_variant' => $variants !== [],
            'relations' => $relations,
        ];

        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['name'],
            'description' => $product['summary'],
            'sku' => $product['sku'],
            'url' => $url,
            'offers' => [
                '@type' => 'Offer',
                'price' => number_format($product['price']['amount_minor'] / 100, 2, '.', ''),
                'priceCurrency' => $currency,
                'availability' => $availability['purchasable']
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'url' => $url,
            ],
        ];
        if ($images !== []) {
            $jsonLd['image'] = array_column($images, 'url');
        }
        $brand = self::brandName((int)($source['brand_id'] ?? 0));
        if ($brand !== '') $jsonLd['brand'] = ['@type' => 'Brand', 'name' => $brand];
        $gtin = (string)($source['gtin'] ?? '');
        if (preg_match('/^(\d{8}|\d{12,14})$/', $gtin)) $jsonLd['gtin' . strlen($gtin)] = $gtin;
        if (trim((string)($source['mpn'] ?? '')) !== '') $jsonLd['mpn'] = trim((string)$source['mpn']);

        $model = [
            'page' => [
                'type' => 'product',
                'template' => 'product',
                'title' => $product['name'],
                'seo' => [
                    'title' => trim((string)($source['seo_title'] ?? '')) ?: $product['name'],
                    'description' => trim((string)($source['meta_description'] ?? '')) ?: $product['summary'],
                    'canonical_url' => $url,
                    'image_url' => $images[0]['url'] ?? '',
                    'json_ld' => [$jsonLd, JsonLd::breadcrumbList(array_map(
                        static fn(array $item): array => ['name' => $item['current'] ? $product['name'] : $item['label'], 'url' => $item['url'] !== '' ? Template::seoPath($item['url']) : ''],
                        $breadcrumbs
                    ), (string)((require __DIR__ . '/../config.php')['app_url'] ?? ''))],
                ],
                'breadcrumbs' => $breadcrumbs,
            ],
            'product' => $product,
            'extensions' => [],
            't' => I18n::all(),
        ];
        $model['extensions'] = StorefrontViewModelRegistry::extensions('product', [
            'source' => $source,
            'model' => $model,
        ]);
        $reviews = $model['extensions']['reviews']['product'] ?? [];
        if (!empty($reviews['count'])) {
            $model['page']['seo']['json_ld'][0]['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (float)$reviews['average'],
                'reviewCount' => (int)$reviews['count'],
            ];
        }
        return $model;
    }

    public static function productCard(array $row): array
    {
        $product = self::enrichedProduct($row);
        $images = self::images($product);
        return [
            'id' => (int)$product['id'],
            'slug' => (string)$product['slug'],
            'sku' => (string)$product['sku'],
            'type' => (string)($product['product_type'] ?? 'simple'),
            'name' => (string)$product['name'],
            'summary' => (string)($product['summary'] ?? ''),
            'url' => self::productUrl((string)$product['slug']),
            'price' => self::price($product),
            'availability' => self::availability($product),
            'image' => $images[0] ?? null,
        ];
    }

    public static function home(): array
    {
        $storeConfig = StoreContext::current()->config();
        $themePage = (array)(Template::loadLayouts()['pages']['homepage'] ?? []);
        $pageConfig = (array)($storeConfig['storefront']['pages']['home'] ?? $themePage);
        $sections = self::pageSections((array)($pageConfig['sections'] ?? []), ['page_type' => 'home']);
        $title = trim((string)($pageConfig['title'] ?? '')) ?: I18n::t('home');
        return [
            'page' => [
                'slug' => '', 'type' => 'home', 'template' => 'homepage',
                'layout' => self::slug((string)($pageConfig['layout'] ?? 'storefront'), 'storefront'),
                'title' => $title,
                'seo' => ['title' => $title, 'description' => (string)($pageConfig['description'] ?? ''), 'canonical_url' => Template::storeUrl('/'), 'image_url' => '', 'json_ld' => []],
                'breadcrumbs' => [], 'sections' => $sections,
            ],
            'sections' => $sections,
            'extensions' => StorefrontViewModelRegistry::extensions('home', ['sections' => $sections]),
            't' => I18n::all(),
        ];
    }

    public static function cmsPage(array $row): array
    {
        $body = Security::sanitizeRichHtml((string)($row['body'] ?? ''));
        $sections = self::pageSections($row['sections'] ?? '[]', ['page_type' => 'cms', 'source' => $row]);
        if ($sections === []) {
            $sections = self::pageSections([[
                'id' => 'content', 'type' => 'rich-text',
                'content' => ['heading' => (string)$row['title'], 'body_html' => $body],
            ]], ['page_type' => 'cms', 'source' => $row]);
        }
        $url = Template::storeUrl('/page/' . rawurlencode((string)$row['slug']));
        $description = trim((string)($row['meta_description'] ?? '')) ?: mb_substr(trim(strip_tags($body)), 0, 320);
        $model = [
            'page' => [
                'slug' => (string)$row['slug'],
                'type' => (string)($row['page_type'] ?? 'content'),
                'template' => self::slug((string)($row['template'] ?? 'cms'), 'cms'),
                'layout' => self::slug((string)($row['layout'] ?? 'storefront'), 'storefront'),
                'title' => (string)$row['title'],
                'body_html' => $body,
                'seo' => [
                    'title' => trim((string)($row['seo_title'] ?? '')) ?: (string)$row['title'],
                    'description' => $description,
                    'canonical_url' => $url,
                    'image_url' => '',
                    'json_ld' => [],
                ],
                'breadcrumbs' => [
                    ['label' => I18n::t('home'), 'url' => Template::storeUrl('/'), 'current' => false],
                    ['label' => (string)$row['title'], 'url' => '', 'current' => true],
                ],
                'sections' => $sections,
            ],
            'sections' => $sections,
            'extensions' => [],
            't' => I18n::all(),
        ];
        $model['extensions'] = StorefrontViewModelRegistry::extensions('cms_page', ['source' => $row, 'model' => $model]);
        return $model;
    }

    private static function enrichedProduct(array $row): array
    {
        $row = I18n::product($row);
        if (!is_array($row['images'] ?? null) || !is_array($row['attrs'] ?? null)) {
            return Products::enrich($row);
        }
        $row['description'] = Security::sanitizeRichHtml((string)($row['description'] ?? ''));
        unset($row['cost_price']);
        return $row;
    }

    private static function price(array $product): array
    {
        $amount = max(0, (int)($product['price'] ?? 0));
        $compare = isset($product['compare_price']) && (int)$product['compare_price'] > $amount
            ? (int)$product['compare_price']
            : null;
        $currency = (string)($product['currency'] ?? StoreContext::current()->currency());
        return [
            'amount_minor' => $amount,
            'formatted' => I18n::price($amount, $currency),
            'compare_amount_minor' => $compare,
            'compare_formatted' => $compare === null ? '' : I18n::price($compare, $currency),
        ];
    }

    private static function availability(array $product, ?int $variantId = null): array
    {
        $digital = VariantAvailability::isDigitalType((string)($product['product_type'] ?? ''));
        $quantity = max(0, (int)($product['stock'] ?? 0));
        $purchasable = VariantAvailability::isPurchasable((int)$product['id'], $variantId);
        $inStock = $digital || $quantity > 0;
        return [
            'purchasable' => $purchasable,
            'tracks_stock' => !$digital,
            'in_stock' => $inStock,
            'quantity' => $quantity,
            'label' => $purchasable && $inStock ? I18n::t('in_stock') : ($purchasable ? I18n::t('available') : I18n::t('out_of_stock')),
        ];
    }

    private static function variant(array $product, array $variant): array
    {
        $variantProduct = $product;
        $variantProduct['stock'] = (int)($variant['stock'] ?? 0);
        $delta = (int)($variant['price_delta'] ?? 0);
        $amount = $variant['price_absolute'] !== null
            ? (int)$variant['price_absolute']
            : (int)$product['price'] + $delta;
        $currency = (string)($product['currency'] ?? StoreContext::current()->currency());
        return [
            'id' => (int)$variant['id'],
            'sku' => (string)$variant['sku'],
            'name' => (string)$variant['name'],
            'attributes' => self::customFields(json_decode((string)($variant['attrs'] ?? '{}'), true) ?: []),
            'price_delta_minor' => $delta,
            'price_amount_minor' => max(0, $amount),
            'price_formatted' => I18n::price(max(0, $amount), $currency),
            'availability' => self::availability($variantProduct, (int)$variant['id']),
        ];
    }

    private static function categories(int $productId): array
    {
        $result = [];
        foreach (Products::categoryIds($productId) as $categoryId) {
            $row = Categories::byId($categoryId);
            if (!$row) continue;
            $row = I18n::category($row);
            $result[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'slug' => (string)$row['slug'],
                'url' => Template::storeUrl('/category/' . rawurlencode((string)$row['slug'])), // the router resolves slugs, not nested paths
            ];
        }
        return $result;
    }

    private static function attributes(int $productId): array
    {
        $rows = Products::attrs($productId);
        $specifications = [];
        foreach (array_unique([I18n::locale(), StoreContext::current()->defaultLocale(), 'en', 'sk']) as $locale) {
            $specifications = Products::specifications($productId, $locale);
            if ($specifications !== []) break;
        }
        foreach ($specifications as $specification) {
            $rows[] = [
                'attr_name' => $specification['criteria_name'],
                'attr_value' => $specification['criteria_value'],
                'numeric_val' => $specification['numeric_val'],
            ];
        }
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $label = (string)$row['attr_name'];
            $value = (string)$row['attr_value'];
            $key = $label . "\0" . $value;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = [
                'code' => self::slug(Str::normalizeToken($label), 'attribute'),
                'label' => $label,
                'value' => I18n::attr($label, $value),
                'numeric_value' => $row['numeric_val'] === null ? null : (float)$row['numeric_val'],
                'group' => null,
            ];
        }
        return $result;
    }

    private static function brandName(int $brandId): string
    {
        if ($brandId <= 0) return '';
        $stmt = Db::get()->prepare('SELECT name FROM brands WHERE id = :id AND store_id = :catalog AND active = 1');
        $stmt->execute([':id' => $brandId, ':catalog' => StoreContext::current()->catalogId()]);
        return (string)($stmt->fetchColumn() ?: '');
    }

    private static function images(array $product): array
    {
        $rows = is_array($product['images'] ?? null)
            ? $product['images']
            : (json_decode((string)($product['images'] ?? '[]'), true) ?: []);
        $alts = is_array($product['image_alts'] ?? null) ? $product['image_alts'] : (json_decode((string)($product['image_alts'] ?? ''), true) ?: []);
        $result = [];
        foreach (array_values($rows) as $index => $image) {
            $data = is_array($image) ? $image : ['url' => (string)$image];
            $raw = trim((string)($data['url'] ?? ''));
            if ($raw === '' || $raw === 'placeholder.jpg') continue;
            $alt = trim((string)($alts[$raw] ?? ''));
            if ($alt === '') $alt = trim((string)($data['alt'] ?? ''));
            // Feeds often carry alt:"" — fall back to the product name so the image is never announced as nameless.
            if ($alt === '') $alt = trim((string)($product['name'] ?? ''));
            $result[] = [
                'url' => self::imageUrl($raw, 'full'),
                'thumbnail_url' => self::imageUrl((string)($data['thumbnail_url'] ?? $raw), 'thumb'),
                'alt' => $alt,
                'primary' => isset($data['primary']) ? (bool)$data['primary'] : $index === 0,
            ];
        }
        return $result;
    }

    private static function customFields(mixed $value): array
    {
        if (is_string($value)) $value = json_decode($value, true) ?: [];
        if (!is_array($value)) return [];
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}$/', $key)) continue;
            if (is_scalar($item) || $item === null) $result[$key] = $item;
            elseif (is_array($item)) $result[$key] = self::safeData($item);
        }
        return $result;
    }

    private static function pageSections(mixed $encoded, array $context): array
    {
        try {
            $sections = json_decode(ContentService::sanitizeSections($encoded), true, 32, JSON_THROW_ON_ERROR);
        } catch (InvalidArgumentException|JsonException $exception) {
            return [];
        }
        foreach ($sections as $index => $section) {
            $source = $section['data_source'] ?? null;
            $provided = is_array($source)
                ? StorefrontSectionRegistry::resolve((string)$source['provider'], (array)$source['parameters'], $context + ['section' => $section])
                : [];
            $sections[$index]['data'] = array_replace_recursive($provided, (array)$section['data']);
        }
        return $sections;
    }

    private static function sectionData(array $data): array
    {
        $result = self::safeData($data);
        foreach ($result as $key => $value) {
            if (is_string($value) && str_ends_with((string)$key, 'url')) $result[$key] = self::contentUrl($value);
        }
        return $result;
    }

    private static function safeData(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (!is_int($key) && (!is_string($key) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}$/', $key))) continue;
            if (is_array($value)) $result[$key] = self::safeData($value);
            elseif (is_scalar($value) || $value === null) $result[$key] = is_string($value) ? trim($value) : $value;
        }
        return $result;
    }

    public static function productUrl(string $slug): string
    {
        return Template::storeUrl('/product/' . rawurlencode($slug));
    }

    private static function imageUrl(string $value, string $size): string
    {
        $value = trim($value);
        if (preg_match('#^https://#i', $value)) return $value;
        if (str_starts_with($value, '/')) return Template::url($value);
        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $value)), static fn(string $part): bool => $part !== ''));
        if ($segments === [] || in_array('..', $segments, true)) return '';
        return Template::url('/uploads/' . $size . '/' . implode('/', array_map('rawurlencode', $segments)));
    }

    private static function contentUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (preg_match('#^https://#i', $value)) return $value;
        if ($value[0] === '#') return $value;
        if ($value[0] === '?') return Template::storeUrl('/' . $value);
        return Template::storeUrl('/' . ltrim($value, '/'));
    }

    private static function slug(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $value) ? $value : $fallback;
    }
}
