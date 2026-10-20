<?php
/**
 * Deterministic, store-scoped Google Merchant XML and Meta catalog CSV exports.
 *
 * Rows are read in id-ordered chunks and streamed, so a large catalog is never held in memory.
 * Each item mirrors what the storefront shows a guest in the active store: the store-prefixed
 * product URL with the locale's slug, the storefront image URL, and the price after the guest's
 * price lists and automatic catalog promotions (integer cents; currency falls back to the store's).
 */
class MarketingFeed {
    private const CHUNK = 500;

    public static function google(string $baseUrl, ?string $locale = null): string {
        return self::capture(static fn($out) => self::writeGoogle($out, $baseUrl, $locale));
    }

    public static function meta(string $baseUrl, ?string $locale = null): string {
        return self::capture(static fn($out) => self::writeMeta($out, $baseUrl, $locale));
    }

    /** @param resource $out */
    public static function writeGoogle($out, string $baseUrl, ?string $locale = null): void {
        $baseUrl = self::baseUrl($baseUrl);
        $locale = self::locale($locale);
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $store = StoreContext::current();
        fwrite($out, implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>',
            '<title>' . $e((string)($store->config()['name'] ?? $store->id())) . '</title>',
            '<link>' . $e($baseUrl . Template::seoPath(Template::storeUrl('/'))) . '</link>',
            '<description>Product catalog</description>',
        ]) . "\n");
        foreach (self::rows($locale) as $product) {
            $lines = ['<item>'];
            foreach (self::googleFields($product, $baseUrl) as $tag => $value) {
                if ($value !== '') $lines[] = '<g:' . $tag . '>' . $e($value) . '</g:' . $tag . '>';
            }
            $lines[] = '</item>';
            fwrite($out, implode("\n", $lines) . "\n");
        }
        fwrite($out, "</channel></rss>\n");
    }

    /** @param resource $out */
    public static function writeMeta($out, string $baseUrl, ?string $locale = null): void {
        $baseUrl = self::baseUrl($baseUrl);
        $locale = self::locale($locale);
        $headers = ['id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand', 'gtin', 'mpn'];
        fputcsv($out, $headers, ',', '"', '\\');
        // Merchant-entered text must not become a spreadsheet formula when the CSV is opened.
        $cell = [Security::class, 'csvCell'];
        foreach (self::rows($locale) as $product) {
            $google = self::googleFields($product, $baseUrl);
            fputcsv($out, [
                $cell($google['id']), $cell($google['title']), $cell($google['description']), $google['availability'],
                $google['condition'], $google['price'], $google['link'], $google['image_link'],
                $cell($google['brand']), $cell($google['gtin']), $cell($google['mpn']),
            ], ',', '"', '\\');
        }
    }

    /** All exported products of the active store, translated and priced (materialised; prefer rows()). */
    public static function products(?string $locale = null): array {
        return iterator_to_array(self::rows($locale), false);
    }

    /**
     * Active products of the store's catalog in id order, CHUNK rows per query, each translated to
     * $locale and priced like the storefront prices them for a guest.
     * @return Generator<int, array>
     */
    public static function rows(?string $locale = null): Generator {
        $locale = self::locale($locale);
        $catalog = StoreContext::current()->catalogId();
        $stmt = Db::get()->prepare("SELECT p.id, p.sku, p.slug, p.name, p.summary, p.description, p.category_id, p.brand_id,
                p.price, p.compare_price, p.currency, p.stock, p.images, p.translations, p.gtin, p.mpn,
                p.availability, p.available_from, b.name AS brand_name
            FROM products p LEFT JOIN brands b ON b.id=p.brand_id AND b.store_id=p.store_id
            WHERE p.store_id=:store AND p.status='active' AND p.id > :after ORDER BY p.id LIMIT " . self::CHUNK);
        $after = 0;
        do {
            $stmt->execute([':store' => $catalog, ':after' => $after]);
            $chunk = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if ($chunk === []) break;
            $after = (int)end($chunk)['id'];
            // One pricing context per chunk: guest price lists and catalog promotions, as on the storefront.
            $context = ProductEnrichmentContext::forProducts($chunk, [], null);
            foreach ($chunk as $product) {
                $translations = json_decode((string)($product['translations'] ?? '{}'), true);
                $translated = is_array($translations) ? ($translations[$locale] ?? []) : [];
                foreach (['name', 'summary', 'description', 'slug'] as $field) {
                    if (is_array($translated) && isset($translated[$field]) && $translated[$field] !== '') {
                        $product[$field] = (string)$translated[$field];
                    }
                }
                unset($product['translations']);
                $product['category_ids'] = $context->categoryIds((int)$product['id']);
                yield $context->applyCommerce($product);
            }
        } while (count($chunk) === self::CHUNK);
    }

    private static function googleFields(array $product, string $baseUrl): array {
        $images = json_decode((string)($product['images'] ?? '[]'), true);
        $first = is_array($images) ? ($images[0] ?? '') : '';
        $first = trim(is_array($first) ? (string)($first['url'] ?? '') : (string)$first);
        if ($first === 'placeholder.jpg') $first = ''; // the storefront shows no image for it either
        $image = $first === '' ? '' : (preg_match('#^https?://#i', $first) ? StorefrontViewModel::imageUrl($first) : self::absoluteUrl($baseUrl, StorefrontViewModel::imageUrl($first)));
        $description = trim(strip_tags((string)($product['summary'] ?: $product['description'])));
        $availability = self::availability($product);
        $currency = strtoupper(trim((string)($product['currency'] ?? ''))) ?: strtoupper(StoreContext::current()->currency());
        return [
            'id' => (string)$product['sku'],
            'title' => (string)$product['name'],
            'description' => mb_substr($description, 0, 5000),
            'link' => self::absoluteUrl($baseUrl, StorefrontViewModel::productUrl((string)$product['slug'])),
            'image_link' => $image,
            'availability' => $availability,
            'availability_date' => $availability === 'preorder' ? self::isoDate((string)($product['available_from'] ?? '')) : '',
            'price' => number_format(max(0, (int)$product['price']) / 100, 2, '.', '') . ' ' . $currency,
            'condition' => 'new',
            'brand' => (string)($product['brand_name'] ?? ''),
            'gtin' => (string)($product['gtin'] ?? ''),
            'mpn' => (string)($product['mpn'] ?? ''),
        ];
    }

    private static function availability(array $product): string {
        $state = strtolower((string)($product['availability'] ?? 'active'));
        if (in_array($state, ['preorder', 'pre-order'], true)) return 'preorder';
        if (in_array($state, ['backorder', 'back-order'], true)) return 'backorder';
        return (int)($product['stock'] ?? 0) > 0 ? 'in stock' : 'out of stock';
    }

    private static function isoDate(string $date): string {
        $timestamp = strtotime($date);
        return $timestamp === false ? '' : gmdate('c', $timestamp);
    }

    /** Join a storefront URL (base-path relative, e.g. /shop/cs/product/x) to the configured base. */
    private static function absoluteUrl(string $baseUrl, string $url): string {
        if ($url === '') return '';
        if (preg_match('#^https?://#i', $url)) return $url;
        return $baseUrl . Template::seoPath($url);
    }

    /** @param callable(resource): void $writer */
    private static function capture(callable $writer): string {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new RuntimeException('Unable to create feed stream');
        $writer($stream);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        return is_string($content) ? $content : '';
    }

    private static function baseUrl(string $baseUrl): string {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('A valid app_url is required for marketing feeds');
        }
        return $baseUrl;
    }

    private static function locale(?string $locale): string {
        $locale = strtolower(trim((string)($locale ?? I18n::locale())));
        if (!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale)) throw new InvalidArgumentException('Invalid feed locale');
        return $locale;
    }
}
