<?php
/**
 * BareBones Shop — catalog.json attributes marked "admin_only" (e.g. downloadable.download_url).
 * R56 (review round 21, F2): the flag was declared but nothing checked it, so a paid file's URL entered as an attribute
 * was shown in the product page's parameters, /api/v1/storefront/product, the category filters and the filter tags.
 * Such an attribute stays in the product (admin editor, CSV export) and is left out of everything public.
 */
final class CatalogAttributes
{
    /** @var array<string, true>|null lower-cased attribute name => true */
    private static ?array $adminOnly = null;

    /** @return array<string, true> */
    private static function adminOnly(): array
    {
        if (self::$adminOnly === null) {
            $catalog = json_decode((string)@file_get_contents(dirname(__DIR__) . '/catalog.json'), true);
            $catalog = is_array($catalog) ? $catalog : [];
            $groups = [(array)($catalog['global_attributes'] ?? [])];
            foreach ((array)($catalog['product_types'] ?? []) as $type) $groups[] = is_array($type) ? (array)($type['attributes'] ?? []) : [];
            $names = [];
            foreach ($groups as $attributes) {
                foreach ($attributes as $name => $definition) {
                    if (is_array($definition) && !empty($definition['admin_only'])) $names[self::key((string)$name)] = true;
                }
            }
            self::$adminOnly = $names;
        }
        return self::$adminOnly;
    }

    /**
     * R57 (review round 22, N3): the filter token's normalisation (Str::normalizeToken()), so every spelling the filters and
     * filter tags read as this attribute is admin-only too. Was: lower-cased only, "download-url" stayed public.
     */
    private static function key(string $name): string
    {
        return Str::normalizeToken($name);
    }

    public static function isAdminOnly(string $name): bool
    {
        return isset(self::adminOnly()[self::key($name)]);
    }

    /** An attribute name => value map without the admin-only attributes. */
    public static function publicMap(array $attributes): array
    {
        foreach (array_keys($attributes) as $name) {
            if (self::isAdminOnly((string)$name)) unset($attributes[$name]);
        }
        return $attributes;
    }

    /** product_attrs rows (attr_name, attr_value, ...) without the admin-only attributes. */
    public static function publicRows(array $rows): array
    {
        return array_values(array_filter($rows, static fn($row): bool => !self::isAdminOnly((string)($row['attr_name'] ?? ''))));
    }
}
