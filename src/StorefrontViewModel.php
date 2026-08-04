<?php
/** Canonical, theme-facing storefront data contract. */
final class StorefrontViewModel
{
    public static function product(array $row): array
    {
        self::registerDefaultExtensions();
        $source = self::enrichedProduct($row);
        $productId = (int)$source['id'];
        $categories = self::categories($productId);
        $attributes = self::attributes($productId);
        $variants = array_map(
            static fn(array $variant): array => self::variant($source, $variant),
            Products::variants($productId)
        );
        $availability = self::availability($source);
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

        $model = [
            'page' => [
                'type' => 'product',
                'template' => 'product',
                'title' => $product['name'],
                'seo' => [
                    'title' => $product['name'],
                    'description' => $product['summary'],
                    'canonical_url' => $url,
                    'image_url' => $images[0]['url'] ?? '',
                    'json_ld' => [$jsonLd, JsonLd::breadcrumbList(array_map(
                        static fn(array $item): array => ['name' => $item['label'], 'url' => $item['url']],
                        $breadcrumbs
                    ))],
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
        $reviews = $model['extensions']['reviews'] ?? [];
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
        $locale = I18n::locale();
        $banner = ContentService::banners('homepage', $locale)[0] ?? [];
        $categories = array_map(static function(array $row): array {
            $category = I18n::category($row);
            return [
                'id' => (int)$category['id'],
                'name' => (string)$category['name'],
                'slug' => (string)$category['slug'],
                'url' => Template::storeUrl('/category/' . rawurlencode((string)$category['path'])),
                'count' => (int)($category['product_count'] ?? 0),
            ];
        }, Categories::featured(6));
        $latest = array_map([self::class, 'productCard'], Products::latest(12));
        $sections = [
            [
                'id' => 'home-hero', 'type' => 'hero', 'variant' => 'default',
                'heading' => (string)($banner['title'] ?? '') ?: I18n::t('hero_title'),
                'body_html' => Security::sanitizeRichHtml((string)($banner['body'] ?? '')),
                'media' => [
                    'image_url' => self::contentUrl((string)($banner['image_url'] ?? '') ?: '/assets/img/slider1.jpg'),
                    'alt' => (string)($banner['title'] ?? '') ?: I18n::t('hero_title'),
                ],
                'cta' => [
                    'label' => (string)($banner['link_label'] ?? '') ?: I18n::t('shop_now'),
                    'url' => self::contentUrl((string)($banner['link_url'] ?? '') ?: '/'),
                ],
                'items' => [], 'props' => [],
            ],
            [
                'id' => 'home-categories', 'type' => 'category-list', 'variant' => 'default',
                'heading' => I18n::t('categories'), 'body_html' => '', 'media' => [], 'cta' => [],
                'items' => $categories, 'props' => [],
            ],
            [
                'id' => 'home-latest', 'type' => 'product-list', 'variant' => 'default',
                'heading' => I18n::t('latest'), 'body_html' => '', 'media' => [], 'cta' => [],
                'items' => $latest, 'props' => [],
            ],
        ];
        return [
            'page' => [
                'type' => 'home', 'template' => 'homepage', 'title' => I18n::t('home'),
                'seo' => ['title' => I18n::t('home'), 'description' => '', 'canonical_url' => Template::storeUrl('/'), 'image_url' => '', 'json_ld' => []],
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
        $sections = self::cmsSections($row['sections'] ?? '[]');
        if ($sections === []) {
            $sections[] = [
                'id' => 'content', 'type' => 'rich-text', 'variant' => 'default',
                'heading' => (string)$row['title'], 'body_html' => $body,
                'media' => [], 'cta' => [], 'items' => [], 'props' => [],
            ];
        }
        $url = Template::storeUrl('/page/' . rawurlencode((string)$row['slug']));
        $description = trim((string)($row['meta_description'] ?? '')) ?: mb_substr(trim(strip_tags($body)), 0, 320);
        $model = [
            'page' => [
                'type' => (string)($row['page_type'] ?? 'content'),
                'template' => self::slug((string)($row['template'] ?? 'cms'), 'cms'),
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
        return [
            'id' => (int)$variant['id'],
            'sku' => (string)$variant['sku'],
            'name' => (string)$variant['name'],
            'attributes' => self::customFields(json_decode((string)($variant['attrs'] ?? '{}'), true) ?: []),
            'price_delta_minor' => (int)($variant['price_delta'] ?? 0),
            'price_amount_minor' => isset($variant['price_absolute']) ? (int)$variant['price_absolute'] : null,
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
                'url' => Template::storeUrl('/category/' . rawurlencode((string)$row['path'])),
            ];
        }
        return $result;
    }

    private static function attributes(int $productId): array
    {
        $rows = Products::attrs($productId);
        $specifications = [];
        foreach (array_unique([I18n::locale(), StoreContext::current()->defaultLocale(), 'en', 'sk']) as $locale) {
            $specifications = TecDoc::specifications($productId, $locale);
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

    private static function images(array $product): array
    {
        $rows = is_array($product['images'] ?? null)
            ? $product['images']
            : (json_decode((string)($product['images'] ?? '[]'), true) ?: []);
        $result = [];
        foreach (array_values($rows) as $index => $image) {
            $data = is_array($image) ? $image : ['url' => (string)$image];
            $raw = trim((string)($data['url'] ?? ''));
            if ($raw === '' || $raw === 'placeholder.jpg') continue;
            $result[] = [
                'url' => self::imageUrl($raw, 'full'),
                'thumbnail_url' => self::imageUrl((string)($data['thumbnail_url'] ?? $raw), 'thumb'),
                'alt' => (string)($data['alt'] ?? $product['name'] ?? ''),
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

    private static function cmsSections(mixed $encoded): array
    {
        $sections = is_array($encoded) ? $encoded : (json_decode((string)$encoded, true) ?: []);
        if (!is_array($sections)) return [];
        $result = [];
        foreach (array_values($sections) as $index => $section) {
            if (!is_array($section)) continue;
            $type = self::slug((string)($section['type'] ?? ''), '');
            if ($type === '') continue;
            $result[] = [
                'id' => self::slug((string)($section['id'] ?? 'section-' . $index), 'section-' . $index),
                'type' => $type,
                'variant' => self::slug((string)($section['variant'] ?? 'default'), 'default'),
                'heading' => trim((string)($section['heading'] ?? '')),
                'body_html' => Security::sanitizeRichHtml((string)($section['body_html'] ?? '')),
                'media' => self::sectionData((array)($section['media'] ?? [])),
                'cta' => self::sectionData((array)($section['cta'] ?? [])),
                'items' => self::safeData((array)($section['items'] ?? [])),
                'props' => self::safeData((array)($section['props'] ?? [])),
            ];
        }
        return $result;
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

    private static function productUrl(string $slug): string
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

    private static function registerDefaultExtensions(): void
    {
        if (class_exists(Reviews::class) && !StorefrontViewModelRegistry::has('product', 'reviews')) {
            StorefrontViewModelRegistry::register('product', 'reviews', static function(array $context): array {
                $productId = (int)$context['model']['product']['id'];
                $aggregate = Reviews::aggregate($productId);
                $translations = I18n::all();
                return [
                    'title' => $translations['reviews'],
                    'empty_label' => $translations['reviews.none'],
                    'items' => array_map(static fn(array $review): array => [
                        'id' => (int)$review['id'],
                        'author_name' => (string)$review['author_name'],
                        'rating' => (int)$review['rating'],
                        'title' => (string)$review['title'],
                        'body' => (string)$review['body'],
                        'created_at' => (string)$review['created_at'],
                    ], Reviews::approved($productId)),
                    'count' => (int)$aggregate['count'],
                    'average' => (int)$aggregate['count'] > 0 ? (float)$aggregate['avg'] : null,
                    'submit_url' => Template::url('/api.php?action=review_submit'),
                    'csrf_token' => Security::csrfToken(),
                    'requires_author' => empty($_SESSION['customer_id']),
                    'form_title' => $translations['reviews.write'],
                    'rating_label' => $translations['reviews.rating'],
                    'author_label' => $translations['reviews.author'],
                    'review_title_label' => $translations['reviews.review_title'],
                    'body_label' => $translations['reviews.body'],
                    'submit_label' => $translations['reviews.submit'],
                    'success_label' => $translations['reviews.success'],
                    'error_label' => $translations['reviews.error'],
                ];
            });
        }
        if (class_exists(Wishlist::class) && !StorefrontViewModelRegistry::has('product', 'wishlist')) {
            StorefrontViewModelRegistry::register('product', 'wishlist', static function(array $context): array {
                $product = $context['model']['product'];
                $active = Wishlist::has((int)$product['id']);
                $translations = I18n::all();
                return [
                    'active' => $active,
                    'label' => $active
                        ? ($translations['wishlist.in'] ?? 'In wishlist')
                        : ($translations['wishlist.add'] ?? 'Add to wishlist'),
                    'toggle_url' => Template::url('/wishlist.php?store=' . rawurlencode(StoreContext::current()->id())),
                    'return_url' => (string)$product['url'],
                    'csrf_token' => Security::csrfToken(),
                ];
            });
        }
        if (class_exists(TecDoc::class) && !StorefrontViewModelRegistry::has('product', 'automotive')) {
            StorefrontViewModelRegistry::register('product', 'automotive', static function(array $context): array {
                $source = $context['source'];
                $productId = (int)$source['id'];
                try {
                    $oeNumbers = TecDoc::oeNumbers($productId);
                    $vehicles = TecDoc::vehiclesForProduct($productId, 100);
                } catch (PDOException $e) {
                    return [];
                }
                if ($oeNumbers === [] && $vehicles === [] && (string)($source['product_type'] ?? '') !== 'auto_part') return [];
                return [
                    'enabled' => true,
                    'oe_numbers' => array_map(static fn(array $oe): array => [
                        'brand' => (string)$oe['oem_brand'],
                        'number' => (string)$oe['oem_number'],
                        'url' => Template::storeUrl('/?page=oe&q=' . rawurlencode((string)$oe['oem_number'])),
                    ], $oeNumbers),
                    'vehicles' => array_map(static fn(array $vehicle): array => [
                        'manufacturer' => (string)$vehicle['manufacturer'],
                        'model' => (string)$vehicle['model'],
                        'type' => (string)$vehicle['type_name'],
                        'ktype' => (int)$vehicle['ktype'],
                    ], $vehicles),
                ];
            });
        }
    }
}
