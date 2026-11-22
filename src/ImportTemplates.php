<?php
/**
 * BareBones Shop — CSV import templates: the header row the import reads, per product type of catalog.json.
 * R55 (review round 20, UX): one source for cli/schema-build.php (import/template-<type>.csv) and the admin's download
 * (admin.php?p=import&action=template), which a web installation without those files has too.
 */
final class ImportTemplates
{
    /** @return array<string, list<string>> product type => header row */
    public static function all(): array
    {
        $catalog = json_decode((string)@file_get_contents(dirname(__DIR__) . '/catalog.json'), true);
        $types = is_array($catalog['product_types'] ?? null) ? $catalog['product_types'] : [];
        $stores = Bootstrap::stores();
        $templates = [];
        foreach ($types + ['simple' => []] as $type => $definition) {
            $templates[(string)$type] = self::headers(is_array($definition) ? $definition : [], $stores);
        }
        return $templates;
    }

    /** @return list<string> */
    public static function headers(array $typeDefinition, array $stores): array
    {
        // R54 (review round 19, UX): vat_class too (books, food, reduced, ...); an empty cell keeps the default.
        $headers = ['sku', 'name', 'slug', 'category', 'price', 'compare_price', 'stock', 'status', 'summary', 'description', 'image', 'weight_g', 'tags', 'vat_class'];
        foreach (array_keys((array)($typeDefinition['attributes'] ?? [])) as $attribute) $headers[] = "attr:{$attribute}";
        $defaultLocale = (string)($stores['default_locale'] ?? 'en');
        $locales = [];
        foreach ((array)($stores['stores'] ?? []) as $store) {
            foreach ((array)($store['languages'] ?? []) as $locale) $locales[(string)$locale] = true;
        }
        foreach (array_keys($locales) as $locale) {
            if ($locale === $defaultLocale) continue;
            array_push($headers, "name_{$locale}", "summary_{$locale}", "slug_{$locale}");
        }
        return $headers;
    }

    /** The template as CSV text (UTF-8, one header row). */
    public static function csv(array $headers): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers, ',', '"', '');
        rewind($out);
        $csv = (string)stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
