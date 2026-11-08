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
        // R23: characters XML 1.0 does not allow (C0 controls such as \x01 or \x0B from a CSV import) made the whole
        // feed invalid and Google refused every item; invalid UTF-8 no longer empties the value.
        $e = static fn(string $value): string => htmlspecialchars(self::xmlText($value), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $store = StoreContext::current();
        fwrite($out, implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>',
            '<title>' . $e((string)($store->config()['name'] ?? $store->id())) . '</title>',
            '<link>' . $e($baseUrl . Template::seoPath(Template::storeUrl('/'))) . '</link>',
            '<description>Product catalog</description>',
        ]) . "\n");
        foreach (self::rows($locale) as $product) {
            foreach (self::items($product, $baseUrl) as $item) {
                $lines = ['<item>'];
                foreach ($item as $tag => $value) {
                    if ($value !== '') $lines[] = '<g:' . $tag . '>' . $e($value) . '</g:' . $tag . '>';
                }
                $lines[] = '</item>';
                fwrite($out, implode("\n", $lines) . "\n");
            }
        }
        fwrite($out, "</channel></rss>\n");
    }

    /** @param resource $out */
    public static function writeMeta($out, string $baseUrl, ?string $locale = null): void {
        $baseUrl = self::baseUrl($baseUrl);
        $locale = self::locale($locale);
        $headers = ['id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand', 'gtin', 'mpn', 'sale_price', 'item_group_id', 'color', 'size'];
        fputcsv($out, $headers, ',', '"', '\\');
        // Merchant-entered text must not become a spreadsheet formula when the CSV is opened.
        $cell = [Security::class, 'csvCell'];
        foreach (self::rows($locale) as $product) {
            foreach (self::items($product, $baseUrl) as $google) {
                fputcsv($out, [
                    $cell($google['id']), $cell($google['title']), $cell($google['description']), $google['availability'],
                    $google['condition'], $google['price'], $google['link'], $google['image_link'],
                    $cell($google['brand']), $cell($google['gtin']), $cell($google['mpn']),
                    $google['sale_price'], $cell($google['item_group_id']), $cell($google['color']), $cell($google['size']),
                ], ',', '"', '\\');
            }
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
        $digitalComponent = Db::tableExists('bundle_components') ? VariantAvailability::digitalComponentSql('p.id') : '0';
        $stmt = Db::get()->prepare("SELECT p.id, p.sku, p.slug, p.name, p.summary, p.description, p.category_id, p.brand_id,
                p.price, p.compare_price, p.currency, p.stock, p.images, p.translations, p.gtin, p.mpn,
                p.product_type, p.status, p.availability, p.available_from, p.inventory_policy, p.preorder_at, b.name AS brand_name,
                {$digitalComponent} AS digital_component
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
            // A product with variants (a book in eBook/paperback/hardcover) keeps its stock on the variants.
            $variantStates = VariantAvailability::variantStatesMany(array_map(static fn(array $p): int => (int)$p['id'], $chunk));
            $variants = self::variantsOf(array_keys($variantStates));
            foreach ($chunk as $product) {
                if (isset($variantStates[(int)$product['id']])) $product['_variant_state'] = $variantStates[(int)$product['id']];
                $product['_variants'] = $variants[(int)$product['id']] ?? [];
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

    /**
     * The feed items of one product: the product itself, or (when it has variants) one item per variant grouped by the
     * product's SKU (item_group_id), each with its own SKU, price and availability as the product page offers that
     * variant, and a link that opens the page on it (?variant=). A product's GTIN/MPN is not copied onto its variants.
     * @return list<array<string, string>>
     */
    private static function items(array $product, string $baseUrl): array {
        $base = self::googleFields($product, $baseUrl);
        // R23: an item the store does not sell (no price in its currency: the cart refuses it) is not offered; a HU store
        // of a shared EUR catalog listed it "in stock, 10.00 EUR".
        if (($product['_variants'] ?? []) === []) {
            return Pricing::soldInStore(trim((string)($product['currency'] ?? '')) ?: StoreContext::current()->currency()) ? [$base] : [];
        }
        $items = [];
        foreach ($product['_variants'] as $row) {
            $variant = StorefrontViewModel::variant($product, $row);
            $schema = (string)$variant['availability']['schema'];
            $availability = (int)($product['digital_component'] ?? 0) === 1 || str_ends_with($schema, '/OutOfStock') ? 'out of stock'
                : (str_ends_with($schema, '/InStock') ? 'in stock' : (str_ends_with($schema, '/PreOrder') ? 'preorder' : 'backorder'));
            $attrs = json_decode((string)($row['attrs'] ?? '{}'), true);
            $attr = static function (array $names) use ($attrs): string {
                foreach (is_array($attrs) ? $attrs : [] as $key => $value) {
                    if (in_array(mb_strtolower(trim((string)$key)), $names, true) && is_scalar($value)) return mb_substr(trim((string)$value), 0, 100);
                }
                return '';
            };
            $items[] = [
                'id' => $variant['sku'],
                'title' => (string)$product['name'] . ($variant['name'] !== '' ? ' — ' . $variant['name'] : ''),
                'link' => $base['link'] . (str_contains($base['link'], '?') ? '&' : '?') . 'variant=' . $variant['id'],
                'availability' => $availability,
                'availability_date' => $availability === 'preorder'
                    ? self::isoDate((string)(($row['available_from'] ?? '') ?: ($product['available_from'] ?? '') ?: ($product['preorder_at'] ?? ''))) : '',
                'price' => Money::decimal($variant['price_amount_minor'], $variant['currency']) . ' ' . $variant['currency'],
                'sale_price' => '', // the product page shows a variant's own price, never a reduction
                'gtin' => '', 'mpn' => '',
                'item_group_id' => $base['id'],
                'color' => $attr(['color', 'colour', 'farba', 'barva', 'farbe', 'kolor', 'szín']),
                'size' => $attr(['size', 'veľkosť', 'velkost', 'velikost', 'größe', 'rozmiar', 'méret', 'talla']),
            ] + $base;
        }
        return $items;
    }

    /**
     * Variants of these products in the product page's order.
     * @param list<int> $productIds
     * @return array<int, list<array>> product id => variant rows
     */
    private static function variantsOf(array $productIds): array {
        if ($productIds === []) return [];
        $marks = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = Db::get()->prepare("SELECT v.* FROM variants v JOIN products p ON p.id = v.product_id
            WHERE p.store_id = ? AND v.product_id IN ({$marks}) ORDER BY v.product_id, v.sort_order, v.id");
        $stmt->execute([StoreContext::current()->catalogId(), ...$productIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int)$row['product_id']][] = $row;
        return $out;
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
        $price = max(0, (int)$product['price']);
        // The struck-through price the storefront shows (capped to the 30-day low, PriceHistory::capCompare) is the
        // regular price; what the guest pays is the sale price.
        $compare = (int)($product['compare_price'] ?? 0) > $price ? (int)$product['compare_price'] : null;
        $money = static fn(int $amount): string => Money::decimal($amount, $currency) . ' ' . $currency;
        return [
            'id' => (string)$product['sku'],
            'title' => (string)$product['name'],
            'description' => mb_substr($description, 0, 5000),
            'link' => self::absoluteUrl($baseUrl, StorefrontViewModel::productUrl((string)$product['slug'])),
            'image_link' => $image,
            'availability' => $availability,
            'availability_date' => $availability === 'preorder'
                ? self::isoDate((string)(($product['available_from'] ?? '') ?: ($product['preorder_at'] ?? ''))) : '',
            'price' => $money($compare ?? $price),
            'sale_price' => $compare === null ? '' : $money($price),
            'condition' => 'new',
            'brand' => (string)($product['brand_name'] ?? ''),
            'gtin' => (string)($product['gtin'] ?? ''),
            'mpn' => (string)($product['mpn'] ?? ''),
            'item_group_id' => '',
            'color' => '',
            'size' => '',
        ];
    }

    /**
     * The storefront's rules (VariantAvailability, StorefrontViewModel::availability): a product the shop does not sell
     * (discontinued, disabled, out_of_stock, not yet released) is out of stock whatever its stock; a digital product
     * or one with stock is in stock; one sold ahead of stock follows its inventory policy (preorder or backorder).
     */
    private static function availability(array $product): string {
        // R14: a bundle with an e-book component cannot be bought (Inventory::reserveLine refuses it), whatever its stock.
        if ((int)($product['digital_component'] ?? 0) === 1) return 'out of stock';
        $variants = $product['_variant_state'] ?? null;
        if (is_array($variants)) {
            if (!$variants['purchasable']) return 'out of stock';
            if ($variants['in_stock']) return 'in stock';
            return ($product['inventory_policy'] ?? '') === Inventory::PREORDER ? 'preorder' : 'backorder';
        }
        if (!VariantAvailability::productPurchasable($product)) return 'out of stock';
        if (VariantAvailability::isDigitalType((string)($product['product_type'] ?? '')) || (int)($product['stock'] ?? 0) > 0) return 'in stock';
        return ($product['inventory_policy'] ?? '') === Inventory::PREORDER ? 'preorder' : 'backorder';
    }

    /** Text without the characters XML 1.0 forbids (C0 controls but tab and line breaks, U+FFFE/U+FFFF, broken UTF-8). */
    public static function xmlText(string $value): string {
        $valid = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+/u', '', $valid) ?? '';
    }

    private static function isoDate(string $date): string {
        // Stored timestamps are UTC (Db::now), whatever PHP's display timezone is.
        try {
            return $date === '' ? '' : (new DateTimeImmutable($date, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('c');
        } catch (Exception $e) {
            Logger::warning('marketing_feed.invalid_date', ['date' => mb_substr($date, 0, 40), 'exception' => get_debug_type($e)]);
            return '';
        }
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
