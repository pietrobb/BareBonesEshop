<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Cache Warmer
 * Runs the full cache rebuild (CacheRebuild: category trees, first listing page and filters of every category,
 * price maps) for every store, in its own namespace, catalog and languages. Product detail pages are cached on
 * first view; --products also pre-renders them (one JSON per product and language: at 200k products that is
 * hundreds of thousands of files that every catalog change makes stale again).
 * Usage: php cli/cache-warm.php [--products] [--store=<id>] [--verbose]
 */
require_once __DIR__ . '/../src/autoload.php';

$verbose = in_array('--verbose', $argv ?? [], true);
$withProducts = in_array('--products', $argv ?? [], true);
$onlyStore = '';
foreach ($argv ?? [] as $arg) if (str_starts_with($arg, '--store=')) $onlyStore = substr($arg, 8);
$cfg = require __DIR__ . '/../config.php';

echo "BareBones Shop — Cache Warmer\n";
echo "==============================\n\n";
$start = microtime(true);

$storeIds = array_map('strval', array_keys(Bootstrap::stores()['stores'] ?? [])) ?: ['default'];
if ($onlyStore !== '') {
    if (!in_array($onlyStore, $storeIds, true)) {
        fwrite(STDERR, "Unknown store: {$onlyStore}\n");
        exit(1);
    }
    $storeIds = [$onlyStore];
}

$products = 0;
foreach ($storeIds as $storeId) {
    $store = Bootstrap::initStoreContext('/', '', $storeId);
    $t = microtime(true);
    CacheRebuild::run(['scope' => 'full'], $cfg);
    echo "Store {$storeId}: categories, listings, filters and prices in " . round(microtime(true) - $t, 1) . " s\n";
    if (!$withProducts) continue;

    $locales = array_values(array_unique(array_map('strval', (array)($store->config()['languages'] ?? [])))) ?: [$store->defaultLocale()];
    $stmt = Db::get()->prepare("SELECT * FROM products WHERE store_id = :catalog AND status = 'active' AND id > :after ORDER BY id LIMIT 500");
    $after = 0;
    do {
        $stmt->execute([':catalog' => $store->catalogId(), ':after' => $after]);
        $chunk = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($chunk as $row) {
            $after = (int)$row['id'];
            foreach ($locales as $locale) {
                I18n::setLocale($locale);
                $product = Products::enrich(I18n::product($row));
                $product['variants'] = Products::variants((int)$row['id']);
                $product['attributes'] = Products::attrs((int)$row['id']);
                Cache::writeJson(Cache::localePath($locale, "product/{$row['slug']}.json"), ['product' => $product]);
                $products++;
            }
        }
        if ($verbose) echo "  products up to id {$after}\n";
    } while (count($chunk) === 500);
}

echo "\nDone in " . round(microtime(true) - $start, 1) . " s" . ($withProducts ? ", {$products} product pages" : '') . ".\n";
