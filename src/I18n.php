<?php
/**
 * BareBones Shop — Internationalization
 * Locale management, product/attribute translation, UI strings.
 */
class I18n {
    private static ?string $locale = null;
    private static ?array $lang = null;
    private static ?array $fallbackLang = null;
    private static ?array $catalog = null;
    private static ?array $stores = null;

    /** System default locale — from stores.json.default_locale, fallback 'en'. */
    public static function sysDefault(): string {
        $stores = self::stores();
        return (string)($stores['default_locale'] ?? 'en');
    }

    /** Per-store default locale — supports new 'default_locale' or legacy 'locale' key. */
    private static function storeDefault(array $store): string {
        return (string)($store['default_locale'] ?? $store['locale'] ?? self::sysDefault());
    }

    public static function setLocale(string $locale): void {
        self::$locale = $locale;
        self::$lang = null; // reset cached translations
    }

    public static function locale(): string {
        if (self::$locale === null) {
            self::$locale = self::sysDefault();
        }
        return self::$locale;
    }

    /** Detect locale from URL prefix; falls back to the active store, then system default. */
    public static function detectLocale(string $uri = ''): string {
        $stores = self::stores();
        // Check URL prefix: /en/..., /de/...
        foreach ($stores['stores'] ?? [] as $code => $store) {
            $prefix = StoreContext::routablePrefix($store);
            if ($prefix !== '' && str_starts_with($uri, $prefix . '/')) {
                return self::storeDefault($store);
            }
            if ($prefix !== '' && $uri === $prefix) {
                return self::storeDefault($store);
            }
        }
        return StoreContext::hasCurrent() ? StoreContext::current()->defaultLocale() : self::sysDefault();
    }

    /** Translate product row — prefer translations[locale] if present, else keep base row. */
    public static function product(array $row): array {
        $tr = json_decode($row['translations'] ?? '{}', true) ?: [];
        $localeData = $tr[self::locale()] ?? [];
        if (empty($localeData)) {
            return $row; // no translation — return base row as-is
        }
        foreach (['name', 'summary', 'description', 'slug', 'seo_title', 'meta_description'] as $field) {
            if (!empty($localeData[$field])) {
                $row[$field] = $localeData[$field];
            }
        }
        if (!empty($localeData['image_alts']) && is_array($localeData['image_alts'])) {
            $base = json_decode((string)($row['image_alts'] ?? ''), true);
            $row['image_alts'] = json_encode(array_merge(is_array($base) ? $base : [], $localeData['image_alts']), JSON_UNESCAPED_UNICODE);
        }
        return $row;
    }

    /** Translate category row — prefer translations[locale] if present, else keep base row. */
    public static function category(array $row): array {
        $tr = json_decode($row['translations'] ?? '{}', true) ?: [];
        $localeData = $tr[self::locale()] ?? [];
        if (empty($localeData)) {
            return $row;
        }
        if (!empty($localeData['name'])) $row['name'] = $localeData['name'];
        if (!empty($localeData['slug'])) $row['slug'] = $localeData['slug'];
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
                        return $optionTr[self::locale()] ?? $optionTr['en'] ?? $value;
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
                return $label[self::locale()] ?? $label['en'] ?? $attrName;
            }
        }

        // Check global attributes
        $globals = $catalog['global_attributes'] ?? [];
        if (isset($globals[$attrName]['label'])) {
            $label = $globals[$attrName]['label'];
            return $label[self::locale()] ?? $label['en'] ?? $attrName;
        }

        return $attrName;
    }

    /** UI translation — BBF lang system */
    public static function t(string $key): string {
        if (!self::$lang) {
            $file = __DIR__ . '/../lang/' . self::locale() . '.php';
            self::$lang = file_exists($file) ? require $file : require __DIR__ . '/../lang/en.php';
        }
        if (isset(self::$lang[$key])) return self::$lang[$key];
        $themeText = Template::themeTexts(self::locale())[$key] ?? null;
        if ($themeText !== null) return $themeText;
        if (self::$fallbackLang === null) self::$fallbackLang = require __DIR__ . '/../lang/en.php';
        return self::$fallbackLang[$key] ?? $key;
    }

    /** UI translation with {name} placeholders replaced by $vars['name']. */
    public static function format(string $key, array $vars = []): string {
        $pairs = [];
        foreach ($vars as $name => $value) $pairs['{' . $name . '}'] = (string)$value;
        return strtr(self::t($key), $pairs);
    }

    /**
     * Count-aware translation: picks "{$key}_one", "{$key}_few" (Slavic 2–4)
     * or "{$key}_many" and substitutes {count}.
     */
    /** $display replaces {count} instead of the number itself, e.g. a range "1–2" chosen by its upper bound. */
    public static function plural(string $key, int $count, ?string $display = null): string {
        $form = 'many';
        if ($count === 1) {
            $form = 'one';
        } elseif (in_array(self::locale(), ['sk', 'cs'], true) && $count >= 2 && $count <= 4) {
            $form = 'few';
        } elseif (self::locale() === 'pl' && in_array($count % 10, [2, 3, 4], true) && !in_array($count % 100, [12, 13, 14], true)) {
            $form = 'few';
        }
        return str_replace('{count}', $display ?? (string)$count, self::t($key . '_' . $form));
    }

    /** UI translation in another locale (e-mails of an order placed in that locale); unknown locales use the current one. */
    public static function tIn(string $key, string $locale): string {
        $current = self::locale();
        if ($locale === $current || !preg_match('/^[a-z]{2}$/', $locale) || !is_file(__DIR__ . '/../lang/' . $locale . '.php')) return self::t($key);
        self::setLocale($locale);
        try {
            return self::t($key);
        } finally {
            self::setLocale($current);
        }
    }

    /** All UI translations (for template rendering), plus the active theme's own texts (Template::themeTexts). */
    public static function all(): array {
        if (!self::$lang) {
            self::t('_init'); // trigger load
        }
        return self::$lang + Template::themeTexts(self::locale());
    }

    /**
     * A stored UTC timestamp ("Y-m-d H:i:s") in the shop's display timezone (PHP's default, as in SalesReport)
     * and the locale's format.date / format.datetime pattern; unparsable input comes back unchanged.
     */
    public static function dateTime(string $utc, bool $withTime = true): string {
        if (trim($utc) === '') return '';
        try {
            $moment = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        } catch (Exception) {
            return $utc;
        }
        return $moment->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format(self::t($withTime ? 'format.datetime' : 'format.date'));
    }

    /**
     * A calendar date already on the shop's calendar ("Y-m-d": invoice issue and due dates, the bank-transfer due day,
     * legal texts) in the format.date pattern of $locale (default: the current one), with no time zone shift.
     */
    public static function date(string $ymd, ?string $locale = null): string {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($ymd)) ? DateTimeImmutable::createFromFormat('!Y-m-d', trim($ymd), new DateTimeZone('UTC')) : false;
        if ($day === false) return $ymd;
        return $day->format($locale === null ? self::t('format.date') : self::tIn('format.date', $locale));
    }

    /** Format price according to currency config */
    public static function price(int $cents, ?string $currency = null): string {
        $format = self::priceFormat($currency);
        $formatted = number_format($cents / 100, $format['decimals'], $format['decimal'], $format['thousands']);

        if ($format['position'] === 'before') {
            return $format['symbol'] . $formatted;
        }
        return $formatted . ' ' . $format['symbol'];
    }

    /** R20: how price() writes an amount of $currency — storefront.js formats the checkout totals the same way. */
    public static function priceFormat(?string $currency = null): array {
        $stores = self::stores();
        $currency = $currency ?? ($stores['default_currency'] ?? 'eur');
        $cur = $stores['currencies'][$currency] ?? ['symbol' => '€', 'decimals' => 2, 'position' => 'after'];
        return ['symbol' => (string)$cur['symbol'], 'decimals' => (int)$cur['decimals'], 'position' => (string)$cur['position'],
            'decimal' => $currency === 'usd' ? '.' : ',', 'thousands' => $currency === 'usd' ? ',' : ' '];
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
            $file = DataOverrides::storesPath();
            self::$stores = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        }
        return self::$stores;
    }
}
