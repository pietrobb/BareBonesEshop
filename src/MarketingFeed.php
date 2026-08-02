<?php
/** Deterministic, store-scoped Google Merchant XML and Meta catalog CSV exports. */
class MarketingFeed {
    public static function google(string $baseUrl, ?string $locale = null): string {
        $baseUrl = self::baseUrl($baseUrl);
        $locale = self::locale($locale);
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $store = StoreContext::current();
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>',
            '<title>' . $e((string)($store->config()['name'] ?? $store->id())) . '</title>',
            '<link>' . $e($baseUrl) . '</link>',
            '<description>Product catalog</description>',
        ];
        foreach (self::products($locale) as $product) {
            $lines[] = '<item>';
            foreach (self::googleFields($product, $baseUrl) as $tag => $value) {
                if ($value !== '') $lines[] = '<g:' . $tag . '>' . $e($value) . '</g:' . $tag . '>';
            }
            $lines[] = '</item>';
        }
        $lines[] = '</channel></rss>';
        return implode("\n", $lines) . "\n";
    }

    public static function meta(string $baseUrl, ?string $locale = null): string {
        $baseUrl = self::baseUrl($baseUrl);
        $locale = self::locale($locale);
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new RuntimeException('Unable to create feed stream');
        $headers = ['id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand', 'gtin', 'mpn'];
        fputcsv($stream, $headers, ',', '"', '\\');
        foreach (self::products($locale) as $product) {
            $google = self::googleFields($product, $baseUrl);
            fputcsv($stream, [
                $google['id'], $google['title'], $google['description'], $google['availability'],
                $google['condition'], $google['price'], $google['link'], $google['image_link'],
                $google['brand'], $google['gtin'], $google['mpn'],
            ], ',', '"', '\\');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return is_string($csv) ? $csv : '';
    }

    public static function products(?string $locale = null): array {
        $locale = self::locale($locale);
        $stmt = Db::get()->prepare("SELECT p.id, p.sku, p.slug, p.name, p.summary, p.description,
                p.price, p.currency, p.stock, p.images, p.translations, p.gtin, p.mpn,
                p.availability, p.available_from, b.name AS brand_name
            FROM products p LEFT JOIN brands b ON b.id=p.brand_id AND b.store_id=p.store_id
            WHERE p.store_id=:store AND p.status='active' ORDER BY p.id");
        $stmt->execute([':store' => StoreContext::current()->catalogId()]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $translations = json_decode((string)($product['translations'] ?? '{}'), true);
            $translated = is_array($translations) ? ($translations[$locale] ?? []) : [];
            foreach (['name', 'summary', 'description', 'slug'] as $field) {
                if (is_array($translated) && isset($translated[$field]) && $translated[$field] !== '') {
                    $product[$field] = (string)$translated[$field];
                }
            }
            $result[] = $product;
        }
        return $result;
    }

    private static function googleFields(array $product, string $baseUrl): array {
        $images = json_decode((string)($product['images'] ?? '[]'), true);
        $image = is_array($images) && isset($images[0]) ? self::absoluteUrl($baseUrl, (string)$images[0]) : '';
        $description = trim(strip_tags((string)($product['summary'] ?: $product['description'])));
        $availability = self::availability($product);
        return [
            'id' => (string)$product['sku'],
            'title' => (string)$product['name'],
            'description' => mb_substr($description, 0, 5000),
            'link' => $baseUrl . '/product/' . rawurlencode((string)$product['slug']),
            'image_link' => $image,
            'availability' => $availability,
            'availability_date' => $availability === 'preorder' ? self::isoDate((string)($product['available_from'] ?? '')) : '',
            'price' => number_format(((int)$product['price']) / 100, 2, '.', '') . ' ' . strtoupper((string)$product['currency']),
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

    private static function absoluteUrl(string $baseUrl, string $url): string {
        if ($url === '') return '';
        if (filter_var($url, FILTER_VALIDATE_URL)) return $url;
        return $baseUrl . '/' . ltrim($url, '/');
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
