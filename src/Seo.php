<?php
/** Canonical, hreflang, Open Graph, and indexability metadata for SSR pages. */
class Seo {
    /** @param array<string, string> $localePaths prefix-less path per hreflang locale where it differs (translated slugs) */
    public static function head(string $title, string $description, string $path, bool $index = true, array $localePaths = []): string {
        $path = self::localPath($path);
        $cfg = require __DIR__ . '/../config.php';
        $base = rtrim((string)($cfg['app_url'] ?? ''), '/');
        if ($base !== '' && (!filter_var($base, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($base, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new RuntimeException('app_url must be an absolute HTTP(S) URL');
        }
        // Without app_url the links stay relative, but keep the deployment base path (subdirectory installs).
        if ($base === '') $base = Template::baseUrl();
        // A store's further language (?lang=xx, Bootstrap::initLocale) is its own indexable URL.
        $secondary = Bootstrap::secondaryLocale();
        $canonical = $base . ($secondary !== null ? Bootstrap::withLang($path, $secondary) : $path);
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = [
            '<meta name="description" content="' . $e(mb_substr(trim($description), 0, 320)) . '">',
            '<meta name="robots" content="' . ($index ? 'index,follow' : 'noindex,follow') . '">',
            '<link rel="canonical" href="' . $e($canonical) . '">',
            '<meta property="og:type" content="website">',
            '<meta property="og:title" content="' . $e($title) . '">',
            '<meta property="og:description" content="' . $e(mb_substr(trim($description), 0, 320)) . '">',
            '<meta property="og:url" content="' . $e($canonical) . '">',
        ];
        $alternates = Bootstrap::hreflangAlternates($path);
        $pathOf = static fn(string|int $locale): string => (string)($localePaths[(string)$locale] ?? $alternates['pathNoPrefix']);
        foreach ($alternates['alternates'] as $locale => $prefix) {
            $lines[] = '<link rel="alternate" hreflang="' . $e((string)$locale) . '" href="'
                . $e($base . (string)$prefix . $pathOf($locale)) . '">';
        }
        foreach ($alternates['secondary'] as $locale => $prefix) {
            $lines[] = '<link rel="alternate" hreflang="' . $e((string)$locale) . '" href="'
                . $e($base . Bootstrap::withLang((string)$prefix . $pathOf($locale), (string)$locale)) . '">';
        }
        $xDefaultLocale = array_search($alternates['xDefaultPrefix'], $alternates['alternates'], true);
        $lines[] = '<link rel="alternate" hreflang="x-default" href="'
            . $e($base . $alternates['xDefaultPrefix'] . ($xDefaultLocale === false ? $alternates['pathNoPrefix'] : $pathOf($xDefaultLocale))) . '">';
        return implode("\n", $lines);
    }

    private static function localPath(string $path): string {
        $path = trim($path);
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new InvalidArgumentException('SEO path must be local');
        }
        return $path;
    }
}
