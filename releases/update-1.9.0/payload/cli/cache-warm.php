<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Cache Warmer
 * Pre-generates flat-file cache for all locales.
 * Usage: php cli/cache-warm.php [--verbose]
 */
require_once __DIR__ . '/../src/autoload.php';

$verbose = in_array('--verbose', $argv ?? []);
$cfg = require __DIR__ . '/../config.php';
$perPage = $cfg['per_page'] ?? 24;

echo "BareBones Shop — Cache Warmer\n";
echo "==============================\n\n";

$start = microtime(true);

// Determine all locales from stores.json
$stores = json_decode(file_get_contents(__DIR__ . '/../stores.json'), true);
$locales = [];
foreach ($stores['stores'] ?? [] as $store) {
    foreach ($store['languages'] ?? [] as $lang) {
        $locales[$lang] = true;
    }
}
$locales = array_keys($locales) ?: [$stores['default_locale'] ?? 'en'];
echo "Locales: " . implode(', ', $locales) . "\n";

// Get all data
$allCats = Db::get()->query("SELECT * FROM categories ORDER BY path")->fetchAll();
$totalProducts = (int)Db::get()->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();

echo "Categories: " . count($allCats) . "\n";
echo "Products: {$totalProducts}\n\n";

$cached = 0;

foreach ($locales as $locale) {
    I18n::setLocale($locale);
    echo "--- Locale: {$locale} ---\n";

    // 1. Category tree
    $tree = Categories::tree();
    $translatedTree = array_map(function($cat) {
        $cat = I18n::category($cat);
        $cat = Categories::enrich($cat);
        if (!empty($cat['children'])) {
            $cat['children'] = array_map(function($c) {
                return Categories::enrich(I18n::category($c));
            }, $cat['children']);
        }
        return $cat;
    }, $tree);
    Cache::writeJson(Cache::localePath($locale, 'categories.json'), ['categories' => $translatedTree]);
    $cached++;
    if ($verbose) echo "  [OK] categories.json\n";

    // 2. Category listings + filters
    foreach ($allCats as $cat) {
        // First page
        $data = Products::byCategory($cat['id'], 1, $perPage, 'price_asc');
        $data['products'] = array_map(function($p) {
            return Products::enrich(I18n::product($p));
        }, $data['products']);
        $data['category'] = I18n::category($cat);
        Cache::writeJson(Cache::localePath($locale, "cat/{$cat['slug']}/page-1.json"), $data);
        $cached++;

        // Additional pages
        $totalPages = $data['pages'] ?? 1;
        for ($pg = 2; $pg <= min($totalPages, 10); $pg++) {
            $pageData = Products::byCategory($cat['id'], $pg, $perPage, 'price_asc');
            $pageData['products'] = array_map(function($p) {
                return Products::enrich(I18n::product($p));
            }, $pageData['products']);
            $pageData['category'] = I18n::category($cat);
            Cache::writeJson(Cache::localePath($locale, "cat/{$cat['slug']}/page-{$pg}.json"), $pageData);
            $cached++;
        }

        // Filters
        $raw = Products::filterValues($cat['id']);
        $filters = [];
        foreach ($raw as $attrName => $options) {
            $filters[] = [
                'attr_name' => $attrName,
                'label'     => I18n::attrLabel($attrName),
                'options'   => array_map(fn($opt) => [
                    'value' => $opt['value'],
                    'label' => I18n::attr($attrName, $opt['value']),
                    'count' => $opt['count'],
                ], $options),
            ];
        }
        Cache::writeJson(Cache::localePath($locale, "cat/{$cat['slug']}/filters.json"), ['filters' => $filters]);
        $cached++;

        if ($verbose) echo "  [OK] cat/{$cat['slug']} ({$totalPages} pages)\n";
    }

    // 3. Product detail pages
    $stmt = Db::get()->query("SELECT * FROM products WHERE status = 'active'");
    while ($product = $stmt->fetch()) {
        $product = Products::enrich(I18n::product($product));
        $product['variants'] = Products::variants($product['id']);
        $product['attributes'] = Products::attrs($product['id']);
        Cache::writeJson(Cache::localePath($locale, "product/{$product['slug']}.json"), ['product' => $product]);
        $cached++;
    }
    if ($verbose) echo "  [OK] {$totalProducts} product pages\n";
}

$elapsed = microtime(true) - $start;
echo "\nDone! Cached {$cached} files in " . round($elapsed * 1000) . "ms.\n";
