<?php
/**
 * Admin product editor fields beyond the base row: brand, GTIN/MPN, SEO, extra categories and per-locale
 * translations. Everything is checked against the active store's catalog before anything is written.
 */
final class ProductEditor {
    public const TRANSLATION_FIELDS = ['name' => 'Názov', 'slug' => 'URL slug', 'summary' => 'Krátky popis', 'description' => 'Popis (HTML)',
        'seo_title' => 'SEO titulok', 'meta_description' => 'Meta popis'];
    private const MAX_LENGTH = ['name' => 255, 'slug' => 255, 'summary' => 2000, 'description' => 65535, 'seo_title' => 255, 'meta_description' => 2000, 'mpn' => 70];
    private const MAX_IMAGES = 50;
    private const MAX_ALT = 255;

    /** Locales the active catalog is sold in: the store's languages plus those of every store sharing its catalog. */
    public static function locales(): array {
        $context = StoreContext::current();
        $locales = (array)($context->config()['languages'] ?? []);
        foreach ((array)(Bootstrap::stores()['stores'] ?? []) as $storeId => $store) {
            if (is_array($store) && (string)($store['catalog_scope'] ?? $storeId) === $context->catalogId()) $locales = array_merge($locales, (array)($store['languages'] ?? []));
        }
        return array_values(array_unique(array_filter(array_map('strval', $locales), static fn(string $l): bool => (bool)preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $l))));
    }

    /** Normalized GTIN-8/12/13/14 ('' when empty), or null when the length or GS1 check digit is wrong. */
    public static function gtin(string $raw): ?string {
        $gtin = (string)preg_replace('/\s+/', '', $raw);
        if ($gtin === '') return '';
        if (!preg_match('/^(\d{8}|\d{12,14})$/', $gtin)) return null;
        $sum = 0;
        foreach (str_split(strrev(substr($gtin, 0, -1))) as $i => $digit) $sum += (int)$digit * ($i % 2 === 0 ? 3 : 1);
        return (10 - $sum % 10) % 10 === (int)substr($gtin, -1) ? $gtin : null;
    }

    /** Non-primary category ids of a product inside the active catalog, in editor order. */
    public static function extraCategoryIds(int $productId): array {
        return array_map('intval', array_column(CatalogScope::rows('SELECT pc.category_id FROM product_categories pc
            JOIN categories c ON c.id = pc.category_id AND c.store_id = :catalog
            WHERE pc.product_id = :id AND pc.is_primary = 0 ORDER BY pc.sort_order, pc.category_id', [':id' => $productId]), 'category_id'));
    }

    /**
     * Validate the editor post (BBF-normalized $form plus the raw extra_category_ids[] and tr[locale][field] of $post).
     * Returns ['columns' => products columns, 'categories' => [primary, ...extra], 'slugs' => locale => slug] or an error message.
     */
    public static function validate(array $form, array $post, int $id, ?array $old): array|string {
        $slug = Security::slug($form['slug'] !== '' ? $form['slug'] : $form['name']);
        if ($slug === '') return 'Slug sa nedá vytvoriť z názvu; zadajte ho ručne.';
        $db = Db::get();
        foreach (['slug' => $slug, 'sku' => (string)$form['sku']] as $column => $value) {
            $taken = $db->prepare("SELECT 1 FROM products WHERE {$column} = :value AND id != :id");
            $taken->execute([':value' => $value, ':id' => $id ?: -1]);
            if ($taken->fetchColumn()) return strtoupper($column) . " \"{$value}\" už používa iný produkt.";
        }
        $images = self::parseImages((string)$form['images']);
        if (is_string($images)) return $images;
        [$imageUrls, $imageAlts] = $images;
        $gtin = self::gtin((string)$form['gtin']);
        if ($gtin === null) return 'GTIN/EAN musí mať 8, 12, 13 alebo 14 číslic a platnú kontrolnú číslicu.';
        foreach (['mpn' => 70, 'seo_title' => 255, 'meta_description' => 2000] as $field => $max) {
            if (mb_strlen(trim((string)$form[$field])) > $max) return "Pole {$field} môže mať najviac {$max} znakov.";
        }
        $primary = Security::int($form['category_id']);
        $owned = array_map('intval', array_column(CatalogScope::categories(), 'id'));
        $submitted = $post['extra_category_ids'] ?? [];
        if (!is_array($submitted) || array_filter($submitted, static fn($v): bool => !is_scalar($v))) return 'Neplatné ďalšie kategórie.';
        $extra = array_values(array_diff(array_unique(array_map('intval', $submitted)), [$primary]));
        if (array_diff($extra, $owned)) return 'Ďalšia kategória nepatrí do katalógu tohto obchodu.';

        $translations = self::translations($post['tr'] ?? [], json_decode((string)($old['translations'] ?? '{}'), true) ?: [], $imageUrls);
        if (is_string($translations)) return $translations;
        [$translations, $slugs] = $translations;
        $registered = $db->prepare("SELECT 1 FROM slug_registry WHERE slug = :slug AND locale = :locale AND entity = 'product' AND entity_id != :id");
        $baseSlug = $db->prepare('SELECT 1 FROM products WHERE slug = :slug AND id != :id');
        foreach (array_filter($slugs, 'strlen') as $locale => $slug) {
            $registered->execute([':slug' => $slug, ':locale' => $locale, ':id' => $id ?: -1]);
            $baseSlug->execute([':slug' => $slug, ':id' => $id ?: -1]);
            if ($registered->fetchColumn() || $baseSlug->fetchColumn()) return "Slug \"{$slug}\" ({$locale}) už používa iný produkt.";
        }
        $optional = static fn($v): ?string => trim((string)$v) === '' ? null : trim((string)$v);
        return [
            'columns' => [
                ':slug' => $slug,
                ':brand_id' => Security::int($form['brand_id']) ?: null,
                ':gtin' => $gtin === '' ? null : $gtin,
                ':mpn' => $optional($form['mpn']),
                ':seo_title' => $optional($form['seo_title']),
                ':meta_description' => $optional($form['meta_description']),
                ':translations' => $translations ? json_encode($translations, JSON_UNESCAPED_UNICODE) : '{}',
                ':image_alts' => $imageAlts ? json_encode($imageAlts, JSON_UNESCAPED_UNICODE) : null,
            ],
            'images' => $imageUrls,
            'categories' => array_merge([$primary], $extra),
            'slugs' => $slugs,
        ];
    }

    /** URL of one stored images entry (a plain URL or an {url, alt} object). */
    public static function imageUrl(mixed $image): string {
        return is_array($image) ? (string)($image['url'] ?? '') : (is_scalar($image) ? (string)$image : '');
    }

    /** Editor text of the images: one "URL | alt" line per image in display order (the first is the main image). */
    public static function imagesText(mixed $images, mixed $alts): string {
        $images = is_array($images) ? $images : (json_decode((string)$images, true) ?: []);
        $alts = is_array($alts) ? $alts : (json_decode((string)$alts, true) ?: []);
        $lines = [];
        foreach ($images as $image) {
            $url = self::imageUrl($image);
            if ($url === '') continue;
            $alt = (string)($alts[$url] ?? (is_array($image) ? ($image['alt'] ?? '') : ''));
            $lines[] = $alt === '' ? $url : "{$url} | {$alt}";
        }
        return implode("\n", $lines);
    }

    /** Parse "URL | alt" lines into [urls in order, url => alt]; duplicates collapse to the first line. */
    public static function parseImages(string $text): array|string {
        $urls = [];
        $alts = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            [$url, $alt] = [$parts[0], $parts[1] ?? ''];
            if ($url === '') continue;
            if (!self::validImageUrl($url)) return "Neplatná adresa obrázka: {$url}";
            if (mb_strlen($alt) > self::MAX_ALT) return 'Alt text obrázka môže mať najviac ' . self::MAX_ALT . ' znakov.';
            if (in_array($url, $urls, true)) continue;
            $urls[] = $url;
            if ($alt !== '') $alts[$url] = $alt;
        }
        if (count($urls) > self::MAX_IMAGES) return 'Produkt môže mať najviac ' . self::MAX_IMAGES . ' obrázkov.';
        return [$urls, $alts];
    }

    /** Store a BBF-validated image upload under a unique name; returns [url, storage path] or an error message. */
    public static function storeUpload(array $upload, string $sku): array|string {
        $dimensions = @getimagesize($upload['tmp_name']);
        $valid = is_array($dimensions) && $dimensions[0] > 0 && $dimensions[1] > 0
            && $dimensions[0] <= 12000 && $dimensions[1] <= 12000 && $dimensions[0] * $dimensions[1] <= 40000000;
        if (!$valid) return 'Obrázok je neplatný alebo má príliš veľké rozmery.';
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$upload['type']];
        $path = self::uploadPath($sku, $extension);
        $bytes = file_get_contents($upload['tmp_name']);
        if ($bytes === false || strlen($bytes) !== $upload['size']) throw new RuntimeException('Uploaded image could not be read completely');
        return [Adapters::storage()->store($path, $bytes), $path];
    }

    /** Unique storage path of a product image upload (two uploads in the same second never overwrite each other). */
    public static function uploadPath(string $sku, string $extension): string {
        $safeSku = substr((string)preg_replace('/[^a-zA-Z0-9_-]/', '_', $sku), 0, 80) ?: 'product';
        return 'products/' . $safeSku . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    /**
     * After a committed save: queue the resize of a new upload and delete editor uploads removed from the product
     * once no product of any store lists them any more. Files outside the storage's products/ uploads are never touched.
     */
    public static function afterImagesSaved(array $before, array $after, ?string $uploadPath): void {
        $storage = Adapters::storage();
        if ($uploadPath !== null) JobQueue::push('image_resize', ['path' => $uploadPath], 3);
        $prefix = substr($storage->url('products/x.jpg', 'full'), 0, -strlen('products/x.jpg'));
        $users = Db::get()->prepare("SELECT images FROM products WHERE images LIKE :needle ESCAPE '!'");
        foreach (array_diff(array_map([self::class, 'imageUrl'], $before), $after) as $url) {
            $path = str_starts_with($url, $prefix) ? substr($url, strlen($prefix)) : '';
            if (!preg_match('#^products/[A-Za-z0-9_-]+\.(jpe?g|png|gif|webp)$#', $path)) continue;
            $users->execute([':needle' => '%' . Db::likeLiteral(basename($path)) . '%']);
            foreach ($users->fetchAll(PDO::FETCH_COLUMN) as $json) {
                $listed = json_decode((string)$json, true);
                if (is_array($listed) && in_array($url, array_map([self::class, 'imageUrl'], $listed), true)) continue 2;
            }
            try {
                $storage->delete($path);
            } catch (RuntimeException $e) {
                error_log("Product image {$path} could not be deleted: " . $e->getMessage());
            }
        }
    }

    /** Remove an upload whose product save did not complete. */
    public static function discardUpload(string $path): void {
        try {
            Adapters::storage()->delete($path);
        } catch (RuntimeException $e) {
            error_log("Orphaned product upload {$path}: " . $e->getMessage());
        }
    }

    /** A relative path / bare file name or an http(s) URL; no other scheme, protocol-relative host or markup characters. */
    private static function validImageUrl(string $url): bool {
        if (strlen($url) > 2000 || preg_match('/[\x00-\x1F\x7F"<>`]/', $url)) return false;
        if (preg_match('#^https?://[^/\s]#i', $url)) return true;
        return !str_starts_with($url, '//') && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $url);
    }

    /** Write category links (primary first) and the translated slugs of the edited locales; call inside the save transaction. */
    public static function save(int $productId, array $validated): void {
        $db = Db::get();
        if (self::categoriesChanged($productId, $validated)) {
            $db->prepare('DELETE FROM product_categories WHERE product_id = :id')->execute([':id' => $productId]);
            $link = $db->prepare('INSERT INTO product_categories (product_id, category_id, is_primary, sort_order) VALUES (:id, :cat, :primary, :sort)');
            foreach ($validated['categories'] as $sort => $categoryId) {
                $link->execute([':id' => $productId, ':cat' => $categoryId, ':primary' => $sort === 0 ? 1 : 0, ':sort' => $sort]);
            }
        }
        $drop = $db->prepare("DELETE FROM slug_registry WHERE entity = 'product' AND entity_id = :id AND locale = :locale");
        $add = $db->prepare("INSERT INTO slug_registry (slug, locale, entity, entity_id, store_id) VALUES (:slug, :locale, 'product', :id, :catalog)");
        foreach ($validated['slugs'] as $locale => $slug) {
            $drop->execute([':id' => $productId, ':locale' => $locale]);
            if ($slug !== '') $add->execute([':slug' => $slug, ':locale' => $locale, ':id' => $productId, ':catalog' => StoreContext::current()->catalogId()]);
        }
    }

    /** True when saving would change the product's category links. */
    public static function categoriesChanged(int $productId, array $validated): bool {
        $linked = Db::get()->prepare('SELECT category_id FROM product_categories WHERE product_id = :id ORDER BY sort_order, category_id');
        $linked->execute([':id' => $productId]);
        return array_map('intval', $linked->fetchAll(PDO::FETCH_COLUMN)) !== $validated['categories'];
    }

    /**
     * Merge posted tr[locale][field] into the stored translations; locales outside the catalog are refused, others kept.
     * tr[locale][image_alts] holds "URL | alt" lines for images of the product; alts of removed images are dropped in every locale.
     */
    private static function translations(mixed $posted, array $translations, array $imageUrls): array|string {
        if (!is_array($posted)) return 'Neplatné preklady.';
        $locales = self::locales();
        $slugs = [];
        foreach ($posted as $locale => $fields) {
            $locale = (string)$locale;
            if (!in_array($locale, $locales, true) || !is_array($fields)) return "Preklad pre jazyk \"{$locale}\" nie je v tomto obchode povolený.";
            $entry = is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
            foreach (array_keys(self::TRANSLATION_FIELDS) as $field) {
                $value = $fields[$field] ?? '';
                if (!is_scalar($value)) return 'Neplatný preklad.';
                $value = trim((string)$value);
                if ($field === 'description' && $value !== '') $value = Security::sanitizeRichHtml($value);
                if ($field === 'slug') {
                    $name = is_scalar($fields['name'] ?? null) ? trim((string)$fields['name']) : '';
                    $value = $value !== '' || $name !== '' ? Security::slug($value !== '' ? $value : $name) : '';
                }
                if (mb_strlen($value) > self::MAX_LENGTH[$field]) return "Preklad {$locale}/{$field} je príliš dlhý.";
                if ($value === '') unset($entry[$field]); else $entry[$field] = $value;
            }
            if (!is_scalar($fields['image_alts'] ?? '')) return 'Neplatný preklad.';
            $alts = self::parseImages((string)($fields['image_alts'] ?? ''));
            if (is_string($alts)) return "Preklad {$locale}/alt texty: {$alts}";
            if (array_diff($alts[0], $imageUrls)) return "Preklad {$locale}/alt texty: obrázok nie je v zozname obrázkov produktu.";
            $entry['image_alts'] = $alts[1];
            $translations[$locale] = $entry;
            $slugs[$locale] = (string)($entry['slug'] ?? '');
        }
        foreach ($translations as $locale => $entry) {
            if (!is_array($entry)) continue;
            $alts = array_intersect_key(is_array($entry['image_alts'] ?? null) ? $entry['image_alts'] : [], array_flip($imageUrls));
            if ($alts) $entry['image_alts'] = $alts; else unset($entry['image_alts']);
            if ($entry) $translations[$locale] = $entry; else unset($translations[$locale]);
        }
        return [$translations, $slugs];
    }
}
