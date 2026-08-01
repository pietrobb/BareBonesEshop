<?php
/** Canonical, hreflang, Open Graph, and indexability metadata for SSR pages. */
class Seo {
    public static function head(string $title, string $description, string $path, bool $index = true): string {
        $path = self::localPath($path);
        $cfg = require __DIR__ . '/../config.php';
        $base = rtrim((string)($cfg['app_url'] ?? ''), '/');
        if ($base !== '' && (!filter_var($base, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($base, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new RuntimeException('app_url must be an absolute HTTP(S) URL');
        }
        $canonical = $base . $path;
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
        foreach ($alternates['alternates'] as $locale => $prefix) {
            $lines[] = '<link rel="alternate" hreflang="' . $e((string)$locale) . '" href="'
                . $e($base . (string)$prefix . $alternates['pathNoPrefix']) . '">';
        }
        $lines[] = '<link rel="alternate" hreflang="x-default" href="'
            . $e($base . $alternates['xDefaultPrefix'] . $alternates['pathNoPrefix']) . '">';
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
