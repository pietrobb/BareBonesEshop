<?php
/**
 * BareBones Shop — CSV import templates: the header row the import reads, per product type of catalog.json.
 * R55 (review round 20, UX): one source for cli/schema-build.php (import/template-<type>.csv) and the admin's download
 * (admin.php?p=import&action=template), which a web installation without those files has too.
 * R56 (review round 21, F1 + UX): the template names its product type in a product_type column (the import of a filled
 * "downloadable" template created physical "simple" products: shipping charged, no delivery), leaves out admin-only
 * attributes (F2), carries one sample row (status draft: imported by mistake it is not published) and is written the
 * way Excel in SK/CZ/DE opens it in columns (UTF-8 BOM, ";"; the import reads both delimiters).
 */
final class ImportTemplates
{
    public const DELIMITER = ';';

    /** @return array<string, list<string>> product type => header row */
    public static function all(): array
    {
        $stores = Bootstrap::stores();
        $templates = [];
        foreach (self::types() as $type => $definition) $templates[$type] = self::headers($definition, $stores);
        return $templates;
    }

    /** @return array<string, array> product type => its catalog.json definition ("simple" always present) */
    public static function types(): array
    {
        $catalog = json_decode((string)@file_get_contents(dirname(__DIR__) . '/catalog.json'), true);
        $types = [];
        foreach ((is_array($catalog['product_types'] ?? null) ? $catalog['product_types'] : []) + ['simple' => []] as $type => $definition) {
            $types[(string)$type] = is_array($definition) ? $definition : [];
        }
        return $types;
    }

    /** The product type's name in the locale (catalog.json "name"), else English, else the code. */
    public static function label(string $type, string $locale): string
    {
        $name = (array)((self::types()[$type] ?? [])['name'] ?? []);
        return trim((string)($name[$locale] ?? $name['en'] ?? '')) ?: ucfirst($type);
    }

    /** @return list<string> */
    public static function headers(array $typeDefinition, array $stores): array
    {
        // R54 (review round 19, UX): vat_class too (books, food, reduced, ...); an empty cell keeps the default.
        $headers = ['sku', 'name', 'slug', 'product_type', 'category', 'price', 'compare_price', 'stock', 'status', 'summary', 'description', 'image', 'weight_g', 'tags', 'vat_class'];
        foreach (array_keys((array)($typeDefinition['attributes'] ?? [])) as $attribute) {
            if (!CatalogAttributes::isAdminOnly((string)$attribute)) $headers[] = "attr:{$attribute}";
        }
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

    /**
     * The sample row under the headers: the type in product_type, a draft status, a price, stock and weight only for a
     * physical product, the first option of a select attribute. $name is the (translated) "replace or delete" text.
     * @param list<string> $headers
     * @return list<string>
     */
    public static function sample(string $type, array $typeDefinition, array $headers, string $name): array
    {
        $physical = ($typeDefinition['physical'] ?? true) !== false && !in_array($type, VariantAvailability::DIGITAL_TYPES, true);
        $attributes = (array)($typeDefinition['attributes'] ?? []);
        $row = [];
        foreach ($headers as $header) {
            $value = match ($header) {
                'sku' => 'SAMPLE-' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $type) ?? 'ITEM'),
                'name' => $name,
                'product_type' => $type,
                'price' => '9.90',
                'stock' => $physical ? '10' : '',
                'weight_g' => $physical ? '500' : '',
                'status' => 'draft',
                default => '',
            };
            if (str_starts_with($header, 'attr:')) {
                $definition = (array)($attributes[substr($header, 5)] ?? []);
                $options = (array)($definition['options'] ?? []);
                if (($definition['type'] ?? '') === 'select' && $options !== []) {
                    $value = (string)(array_is_list($options) ? $options[0] : array_key_first($options));
                }
            }
            $row[] = $value;
        }
        return $row;
    }

    /** The template as CSV text: UTF-8 BOM, the header row and the sample rows, ";"-separated. */
    public static function csv(array $headers, array ...$rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ([$headers, ...$rows] as $row) fputcsv($out, $row, self::DELIMITER, '"', '');
        rewind($out);
        $csv = (string)stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /** The complete template of a product type (headers + sample row); null for an unknown type. */
    public static function template(string $type, string $sampleName, ?array $stores = null): ?string
    {
        $types = self::types();
        if (!isset($types[$type])) return null;
        $headers = self::headers($types[$type], $stores ?? Bootstrap::stores());
        return self::csv($headers, self::sample($type, $types[$type], $headers, $sampleName));
    }
}
