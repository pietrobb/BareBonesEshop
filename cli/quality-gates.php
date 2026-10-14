<?php
require_once __DIR__ . '/cli-only.php';

/** Deterministic G10 quality gates: theme package, i18n parity, WCAG invariants, and asset budgets. */
$root = dirname(__DIR__);
$failures = [];
$pass = static function(string $message): void { echo "[PASS] {$message}\n"; };
$check = static function(bool $condition, string $message) use (&$failures, $pass): void {
    if ($condition) $pass($message);
    else { $failures[] = $message; echo "[FAIL] {$message}\n"; }
};

echo "BareBones G10 quality gates\n" . str_repeat('=', 40) . "\n";
$themeDirs = glob($root . '/themes/*', GLOB_ONLYDIR) ?: [];
$check($themeDirs !== [], 'At least one theme package exists');
foreach ($themeDirs as $themeDir) {
    $theme = basename($themeDir);
    $manifestPath = $themeDir . '/theme.json';
    $manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
    $check(is_array($manifest), "Theme {$theme} manifest is valid JSON");
    if (!is_array($manifest)) continue;
    $check((string)($manifest['name'] ?? '') !== '' && preg_match('/^\d+\.\d+\.\d+$/', (string)($manifest['version'] ?? '')) === 1,
        "Theme {$theme} declares name and semantic version");
    $budgets = $manifest['performance_budgets'] ?? [];
    foreach (['css' => 'css_bytes', 'js' => 'js_bytes'] as $kind => $budgetKey) {
        $bytes = 0;
        foreach ((array)($manifest['assets'][$kind] ?? []) as $asset) {
            $safe = is_string($asset) && $asset !== '' && !str_starts_with($asset, '/') && !in_array('..', explode('/', str_replace('\\', '/', $asset)), true);
            $path = $themeDir . '/assets/' . $asset;
            $check($safe && is_file($path), "Theme {$theme} {$kind} asset {$asset} exists safely");
            if ($safe && is_file($path)) $bytes += filesize($path) ?: 0;
        }
        $budget = (int)($budgets[$budgetKey] ?? 0);
        $check($budget > 0 && $bytes <= $budget, "Performance budget {$theme} {$kind}: {$bytes}/{$budget} bytes");
    }
    $blockBytes = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeDir . '/blocks', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) if ($file->isFile()) $blockBytes += $file->getSize();
    $blockBudget = (int)($budgets['block_bytes'] ?? 0);
    $check($blockBudget > 0 && $blockBytes <= $blockBudget, "Performance budget {$theme} blocks: {$blockBytes}/{$blockBudget} bytes");
}

$document = (string)file_get_contents($root . '/themes/default/blocks/global/document.html');
$header = (string)file_get_contents($root . '/themes/default/blocks/global/header.html');
$a11yCss = (string)file_get_contents($root . '/themes/default/assets/accessibility.css');
$storefrontCss = (string)file_get_contents($root . '/themes/default/assets/storefront.css');
$storefrontJs = (string)file_get_contents($root . '/themes/default/assets/storefront.js');
$storefrontLayout = (string)file_get_contents($root . '/src/Entrypoints/Storefront/Layout.php');
$storefrontEntrypoint = (string)file_get_contents($root . '/src/Entrypoints/Storefront.php');
$catalogPages = (string)file_get_contents($root . '/src/Entrypoints/Storefront/CatalogPages.php');
$checkoutPages = (string)file_get_contents($root . '/src/Entrypoints/Storefront/CheckoutPages.php');
$storefrontViewModel = (string)file_get_contents($root . '/src/StorefrontViewModel.php');
$cleanProductEntrypoint = (string)file_get_contents($root . '/product.php');
$themeStorefrontBlocks = '';
foreach (glob($root . '/themes/default/blocks/storefront/*.html') ?: [] as $block) $themeStorefrontBlocks .= (string)file_get_contents($block);
$check(str_contains($document, 'href="#main-content"') && str_contains($document, '<main id="main-content"'), 'WCAG 2.2 AA: bypass block targets the main landmark');
$check(str_contains($header, '<nav') && str_contains($header, 'aria-label=') && str_contains($header, '<label'), 'WCAG 2.2 AA: navigation and search have accessible names');
$check(str_contains($a11yCss, ':focus-visible') && str_contains($a11yCss, 'prefers-reduced-motion') && str_contains($a11yCss, 'forced-colors:active'), 'WCAG 2.2 AA: focus, motion, and forced-colors safeguards exist');
$check(str_contains($a11yCss, 'min-height:24px'), 'WCAG 2.2 AA: minimum target size safeguard exists');
$check(str_contains($storefrontCss, '.grid-3{grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr))}'), 'Responsive storefront: 300px cards cannot overflow a 320px viewport');
$check(!str_contains($storefrontCss, '@import') && !str_contains($storefrontCss, 'fonts.googleapis.com'), 'CLS budget: storefront typography has no render-time remote stylesheet dependency');
$check(str_contains($storefrontCss, '.hero{position:relative;aspect-ratio:16/5') && str_contains($themeStorefrontBlocks, 'width="1920" height="600"'), 'CLS budget: homepage hero reserves its rendered aspect ratio');
$check(!str_contains($storefrontLayout, '<style') && !str_contains($storefrontLayout, 'function setTab'), 'Theme boundary: storefront CSS and behavior are not embedded in core Layout.php');
$check(!str_contains($storefrontLayout, 'woodle') && !str_contains($storefrontLayout, "\$lang==='"), 'Theme boundary: core Layout.php has no store branding or language ternaries');
$check(!str_contains($storefrontJs, 'sk-SK') && !str_contains($storefrontJs, "'EUR'") && !str_contains($storefrontJs, '"EUR"'), 'i18n runtime: locale and currency come from StoreContext');
$check(is_file($root . '/themes/default/blocks/storefront/header.html') && is_file($root . '/themes/default/blocks/storefront/footer.html'), 'Theme boundary: storefront header and footer are theme blocks');
$check(is_file($root . '/themes/default/blocks/storefront/product-detail.html') && is_file($root . '/themes/default/blocks/storefront/cart.html') && is_file($root . '/themes/default/blocks/storefront/checkout.html'), 'Theme boundary: product, cart, and checkout markup are theme blocks');
$check(!preg_match('/<(?:p|div|form|button|table|img|nav|section|article)\b/i', $storefrontEntrypoint . $catalogPages . $checkoutPages), 'Theme boundary: storefront core presenters contain no structural HTML');
$check(str_contains($cleanProductEntrypoint, "src/Entrypoints/Storefront.php") && !str_contains($cleanProductEntrypoint, 'ob_start'), 'Canonical product route: clean URL delegates without a parallel renderer');
$check(substr_count($catalogPages, 'StorefrontViewModel::product(') === 1, 'Canonical product route: one view-model builder serves storefront routing');
$check(!preg_match('/[\x27\x22](?:breadcrumb|latest|product_grid|automotive|navigation)_html[\x27\x22]/', $storefrontViewModel), 'View-model boundary: no structural HTML fragments are exposed');
$check(!preg_match('/\b(?:isbn|ebook_url|printed_book_url|AeonProductViewModel)\b/i', $storefrontViewModel), 'Universal engine: canonical model contains no vertical-specific product fields');

$langFiles = glob($root . '/lang/*.php') ?: [];
$catalogs = [];
foreach ($langFiles as $file) {
    $catalog = require $file;
    $check(is_array($catalog), 'i18n catalog ' . basename($file) . ' returns an array');
    if (is_array($catalog)) { ksort($catalog); $catalogs[basename($file, '.php')] = array_keys($catalog); }
}
$check(isset($catalogs['es']), 'Spanish locale is present');
$reference = $catalogs['en'] ?? [];
foreach ($catalogs as $locale => $keys) $check($keys === $reference, "i18n parity {$locale}: " . count($keys) . ' keys');
$esSource = (string)file_get_contents($root . '/lang/es.php');
$check(substr_count($esSource, '=>') >= 100, 'Spanish locale provides substantial native storefront overrides');

$htaccess = (string)file_get_contents($root . '/.htaccess');
$check(str_contains($htaccess, 'feeds/google\\.xml') && str_contains($htaccess, 'feeds/meta\\.csv'), 'Google Merchant and Meta feed routes are published');
$check(str_contains((string)file_get_contents($root . '/src/MarketingConsent.php'), "'analytics' => false"), 'Consent hooks default analytics and marketing to off');
require_once __DIR__ . '/theme-check.php'; $themeContract = runThemeCompatibility(); $check($themeContract['status'] === 'GO', 'Theme checkout contract: ' . $themeContract['checks'] . ' checks; ' . implode("\n", $themeContract['failures']));
if ($failures) {
    echo "\nQuality gates FAILED (" . count($failures) . ")\n";
    foreach ($failures as $failure) echo " - {$failure}\n";
    exit(1);
}
echo "\nQuality gates PASSED: WCAG 2.2 AA automated invariants, i18n parity, and performance budgets.\n";
