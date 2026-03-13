<?php
/**
 * BareBones Shop — Internationalization
 * Locale management, product/attribute translation, UI strings.
 */
class I18n {
    private static string $locale = 'sk';
    private static ?array $lang = null;
    private static ?array $catalog = null;
    private static ?array $stores = null;
    private static ?array $cfg = null;

    private static function cfg(): array {
        if (!self::$cfg) {
            self::$cfg = require __DIR__ . '/../config.php';
        }
        return self::$cfg;
    }

    public static function setLocale(string $locale): void {
        self::$locale = $locale;
        self::$lang = null; // reset cached translations
    }

    public static function locale(): string {
        return self::$locale;
    }

    /** Detect locale from URL prefix or Accept-Language */
    public static function detectLocale(string $uri = ''): string {
        $stores = self::stores();
        // Check URL prefix: /en/..., /de/...
        foreach ($stores['stores'] as $code => $store) {
            $prefix = $store['url_prefix'] ?? '';
            if ($prefix && str_starts_with($uri, $prefix . '/')) {
                return $store['locale'];
            }
            if ($prefix && $uri === $prefix) {
                return $store['locale'];
            }
        }
        return $stores['default_locale'] ?? 'sk';
    }

    /** Translate product row — fallback to default locale */
    public static function product(array $row): array {
        $cfg = self::cfg();
        if (self::$locale === ($cfg['default_locale'] ?? 'sk')) {
            return $row; // base language, no translation needed
        }

        $tr = json_decode($row['translations'] ?? '{}', true);
        $localeData = $tr[self::$locale] ?? [];

        foreach (['name', 'summary', 'description'] as $field) {
            if (!empty($localeData[$field])) {
                $row[$field] = $localeData[$field];
            }
        }

        if (!empty($localeData['slug'])) {
            $row['slug'] = $localeData['slug'];
        }

        return $row;
    }

    /** Translate category row */
    public static function category(array $row): array {
        $cfg = self::cfg();
        if (self::$locale === ($cfg['default_locale'] ?? 'sk')) {
            return $row;
        }

        $tr = json_decode($row['translations'] ?? '{}', true);
        $localeData = $tr[self::$locale] ?? [];

        if (!empty($localeData['name'])) {
            $row['name'] = $localeData['name'];
        }
        if (!empty($localeData['slug'])) {
            $row['slug'] = $localeData['slug'];
        }

        return $row;
    }

    /** Translate attribute value from catalog.json options */
    public static function attr(string $attrName, string $value): string {
        $catalog = self::catalog();

        foreach ($catalog['product_types'] ?? [] as $type) {
            $attrs = $type['attributes'] ?? [];
            if (isset($attrs[$attrName])) {
                $attrDef = $attrs[$attrName];
                if (!empty($attrDef['options_translatable']) && is_array($attrDef['options'] ?? null)) {
                    $optionTr = $attrDef['options'][$value] ?? null;
                    if (is_array($optionTr)) {
                        return $optionTr[self::$locale] ?? $optionTr['en'] ?? $value;
                    }
                }
            }
        }

        return $value;
    }

    /** Get attribute label from catalog.json */
    public static function attrLabel(string $attrName): string {
        $catalog = self::catalog();

        foreach ($catalog['product_types'] ?? [] as $type) {
            $attrs = $type['attributes'] ?? [];
            if (isset($attrs[$attrName]['label'])) {
                $label = $attrs[$attrName]['label'];
                return $label[self::$locale] ?? $label['en'] ?? $attrName;
            }
        }

        // Check global attributes
        $globals = $catalog['global_attributes'] ?? [];
        if (isset($globals[$attrName]['label'])) {
            $label = $globals[$attrName]['label'];
            return $label[self::$locale] ?? $label['en'] ?? $attrName;
        }

        return $attrName;
    }

    /** UI translation — BBF lang system */
    public static function t(string $key): string {
        if (!self::$lang) {
            $file = __DIR__ . '/../lang/' . self::$locale . '.php';
            self::$lang = file_exists($file) ? require $file : require __DIR__ . '/../lang/en.php';
        }
        return self::$lang[$key] ?? $key;
    }

    /** All UI translations (for template rendering) */
    public static function all(): array {
        if (!self::$lang) {
            self::t('_init'); // trigger load
        }
        return self::$lang;
    }

    /** Format price according to currency config */
    public static function price(int $cents, ?string $currency = null): string {
        $stores = self::stores();
        $currency = $currency ?? ($stores['default_currency'] ?? 'eur');
        $cur = $stores['currencies'][$currency] ?? ['symbol' => '€', 'decimals' => 2, 'position' => 'after'];

        $decSep = ($currency === 'usd') ? '.' : ',';
        $thousSep = ($currency === 'usd') ? ',' : ' ';
        $formatted = number_format($cents / 100, $cur['decimals'], $decSep, $thousSep);

        if ($cur['position'] === 'before') {
            return $cur['symbol'] . $formatted;
        }
        return $formatted . ' ' . $cur['symbol'];
    }

    // ============================================================
    // Internal helpers
    // ============================================================
    private static function catalog(): array {
        if (!self::$catalog) {
            $file = __DIR__ . '/../catalog.json';
            self::$catalog = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        }
        return self::$catalog;
    }

    private static function stores(): array {
        if (!self::$stores) {
            $file = __DIR__ . '/../stores.json';
            self::$stores = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        }
        return self::$stores;
    }
}
