<?php
/**
 * BareBones Shop — Performance Benchmark
 * Validates performance against design targets.
 * Usage: php cli/benchmark.php
 *
 * Targets (from ESHOP-DESIGN.md):
 *   Product by slug (indexed): <1ms
 *   Category listing (24 items): 2-5ms
 *   FTS5 search: 10-20ms
 *   Faceted filter: 5-15ms
 *   Price range filter: 2-5ms
 *   From flat-file cache: <1ms
 */
require_once __DIR__ . '/../src/autoload.php';

I18n::setLocale('sk');

echo "BareBones Shop — Performance Benchmark\n";
echo "========================================\n\n";

$db = Db::get();
$totalProducts = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalCats = (int)$db->query("SELECT COUNT(*) FROM categories")->fetchColumn();

echo "Dataset: {$totalProducts} products, {$totalCats} categories\n\n";

if ($totalProducts === 0) {
    echo "ERROR: No products. Run 'php cli/seed.php' first.\n";
    exit(1);
}

$results = [];

// Get a random product slug for testing
$testProduct = $db->query("SELECT slug, sku FROM products WHERE status = 'active' ORDER BY RANDOM() LIMIT 1")->fetch();
$testCat = $db->query("SELECT id, slug FROM categories WHERE product_count > 0 ORDER BY RANDOM() LIMIT 1")->fetch();

echo "Test product: {$testProduct['sku']} ({$testProduct['slug']})\n";
echo "Test category: {$testCat['slug']} (ID: {$testCat['id']})\n\n";

// ============================================================
// Benchmark functions
// ============================================================

function bench(string $name, callable $fn, int $iterations = 100): array {
    // Warmup
    for ($i = 0; $i < 3; $i++) $fn();

    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1_000_000; // ms
    }

    sort($times);
    $avg = array_sum($times) / count($times);
    $p50 = $times[(int)(count($times) * 0.5)];
    $p95 = $times[(int)(count($times) * 0.95)];
    $p99 = $times[(int)(count($times) * 0.99)];
    $min = $times[0];
    $max = end($times);

    return compact('name', 'iterations', 'avg', 'p50', 'p95', 'p99', 'min', 'max');
}

function printResult(array $r, ?float $target = null): void {
    $status = '  ';
    if ($target !== null) {
        $status = $r['p95'] <= $target ? '[OK]' : '[!!]';
    }
    printf("  %s %-40s avg: %6.2fms  p50: %6.2fms  p95: %6.2fms  p99: %6.2fms\n",
        $status, $r['name'], $r['avg'], $r['p50'], $r['p95'], $r['p99']
    );
}

// ============================================================
// Benchmarks
// ============================================================

echo "--- Database Queries ---\n";

// 1. Product by slug
$slug = $testProduct['slug'];
$r = bench('Product by slug', fn() => Products::bySlug($slug));
printResult($r, 1.0);
$results[] = $r;

// 2. Product by ID
$id = (int)$db->query("SELECT id FROM products LIMIT 1")->fetchColumn();
$r = bench('Product by ID', fn() => Products::byId($id));
printResult($r, 1.0);
$results[] = $r;

// 3. Product by SKU
$sku = $testProduct['sku'];
$r = bench('Product by SKU', fn() => Products::bySku($sku));
printResult($r, 1.0);
$results[] = $r;

// 4. Category listing (24 items, sorted)
$catId = (int)$testCat['id'];
$r = bench('Category listing (24, price_asc)', fn() => Products::byCategory($catId, 1, 24, 'price_asc'));
printResult($r, 5.0);
$results[] = $r;

// 5. Category listing sorted by name
$r = bench('Category listing (24, name_asc)', fn() => Products::byCategory($catId, 1, 24, 'name_asc'));
printResult($r, 5.0);
$results[] = $r;

// 6. FTS5 search
$r = bench('FTS5 search "tricko"', fn() => Search::query('tricko', 1, 24));
printResult($r, 20.0);
$results[] = $r;

// 7. FTS5 search broader term
$r = bench('FTS5 search "premium"', fn() => Search::query('premium', 1, 24));
printResult($r, 20.0);
$results[] = $r;

// 8. Faceted filter (single attribute)
$r = bench('Filter: color=cierna', fn() => Products::byCategory($catId, 1, 24, 'price_asc', ['color' => ['cierna']]));
printResult($r, 15.0);
$results[] = $r;

// 9. Faceted filter (two attributes)
$r = bench('Filter: color+size', fn() => Products::byCategory($catId, 1, 24, 'price_asc', ['color' => ['cierna'], 'size' => ['M']]));
printResult($r, 15.0);
$results[] = $r;

// 10. Price range filter
$r = bench('Price range 1000-5000', fn() => Products::byCategory($catId, 1, 24, 'price_asc', [], 1000, 5000));
printResult($r, 5.0);
$results[] = $r;

// 11. Filter values (facets)
$r = bench('Filter values for category', fn() => Products::filterValues($catId));
printResult($r, 10.0);
$results[] = $r;

// 12. FTS suggest (autocomplete)
$r = bench('FTS suggest "pre"', fn() => Search::suggest('pre', 5));
printResult($r, 10.0);
$results[] = $r;

// 13. Category tree
$r = bench('Category tree', fn() => Categories::tree());
printResult($r, 5.0);
$results[] = $r;

// 14. Breadcrumb
$r = bench('Breadcrumb', fn() => Categories::breadcrumb($catId));
printResult($r, 2.0);
$results[] = $r;

// 15. Latest products
$r = bench('Latest 8 products', fn() => Products::latest(8));
printResult($r, 5.0);
$results[] = $r;

echo "\n--- Cache Operations ---\n";

$testCacheDir = __DIR__ . '/../cache/bench_' . getmypid();
Cache::setDir($testCacheDir);

// Write cache
$testData = ['products' => array_fill(0, 24, ['id' => 1, 'name' => 'test', 'price' => 1990])];
$r = bench('Cache write JSON', fn() => Cache::writeJson('bench/test.json', $testData));
printResult($r, 2.0);
$results[] = $r;

// Read cache
Cache::writeJson('bench/read.json', $testData);
$r = bench('Cache read JSON', fn() => Cache::readJson('bench/read.json'));
printResult($r, 1.0);
$results[] = $r;

// Key-value
Cache::set('benchkey', 'benchvalue');
$r = bench('Cache key-value get', fn() => Cache::get('benchkey'));
printResult($r, 1.0);
$results[] = $r;

// Cleanup bench cache
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testCacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iter as $f) {
    $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
}
@rmdir($testCacheDir);
Cache::setDir(__DIR__ . '/../cache');

// ============================================================
// Summary
// ============================================================
echo "\n========================================\n";
echo "SUMMARY\n";
echo "========================================\n\n";

$passed = 0;
$failed = 0;
$targets = [
    'Product by slug' => 1.0,
    'Product by ID' => 1.0,
    'Product by SKU' => 1.0,
    'Category listing (24, price_asc)' => 5.0,
    'Category listing (24, name_asc)' => 5.0,
    'FTS5 search "tricko"' => 20.0,
    'FTS5 search "premium"' => 20.0,
    'Filter: color=cierna' => 15.0,
    'Filter: color+size' => 15.0,
    'Price range 1000-5000' => 5.0,
    'Filter values for category' => 10.0,
    'FTS suggest "pre"' => 10.0,
    'Category tree' => 5.0,
    'Breadcrumb' => 2.0,
    'Latest 8 products' => 5.0,
    'Cache write JSON' => 2.0,
    'Cache read JSON' => 1.0,
    'Cache key-value get' => 1.0,
];

foreach ($results as $r) {
    $target = $targets[$r['name']] ?? null;
    if ($target !== null) {
        if ($r['p95'] <= $target) {
            $passed++;
        } else {
            $failed++;
            printf("  [MISS] %-40s p95: %.2fms (target: %.1fms)\n", $r['name'], $r['p95'], $target);
        }
    }
}

echo "\n  Performance targets: {$passed}/" . ($passed + $failed) . " met\n";
if ($failed === 0) {
    echo "  All targets met!\n";
}
echo "\n";
