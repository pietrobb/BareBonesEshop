<?php
/**
 * BareBones Shop — TecDoc Performance Benchmark
 * Tests parametric search, OE lookup, vehicle cascade on real data.
 */
require_once __DIR__ . '/../src/autoload.php';

I18n::setLocale('sk');

echo "BareBones Shop — TecDoc Benchmark\n";
echo "===================================\n\n";

$db = Db::get();
$totalProducts = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalSpecs = (int)$db->query("SELECT COUNT(*) FROM product_specifications")->fetchColumn();
$totalOE = (int)$db->query("SELECT COUNT(*) FROM product_oe_numbers")->fetchColumn();
$totalVeh = (int)$db->query("SELECT COUNT(*) FROM product_vehicles")->fetchColumn();

echo "Dataset: {$totalProducts} products, {$totalSpecs} specs, {$totalOE} OE numbers, {$totalVeh} vehicles\n\n";

if ($totalProducts === 0) {
    echo "ERROR: No products. Run 'php cli/import-tecdoc.php' first.\n";
    exit(1);
}

function bench(string $name, callable $fn, int $iterations = 50): array {
    for ($i = 0; $i < 3; $i++) $fn();
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1_000_000;
    }
    sort($times);
    return [
        'name' => $name,
        'avg'  => array_sum($times) / count($times),
        'p50'  => $times[(int)(count($times) * 0.5)],
        'p95'  => $times[(int)(count($times) * 0.95)],
        'p99'  => $times[(int)(count($times) * 0.99)],
    ];
}

function printResult(array $r, ?float $target = null): void {
    $status = $target !== null ? ($r['p95'] <= $target ? '[OK]' : '[!!]') : '  ';
    printf("  %s %-45s avg: %6.2fms  p50: %6.2fms  p95: %6.2fms\n",
        $status, $r['name'], $r['avg'], $r['p50'], $r['p95']);
}

// Find a category with products and specs
$testCat = $db->query("
    SELECT c.id, c.slug, c.product_count FROM categories c
    WHERE c.product_count > 50 AND c.depth = 1
    ORDER BY c.product_count DESC LIMIT 1
")->fetch();

$testProduct = $db->query("
    SELECT p.id, p.slug, p.sku FROM products p
    WHERE p.category_id = {$testCat['id']} AND p.status = 'active' LIMIT 1
")->fetch();

// Get a spec criterion for this category
$testSpec = $db->query("
    SELECT ps.criteria_name, ps.criteria_value
    FROM product_specifications ps
    JOIN products p ON p.id = ps.product_id
    WHERE p.category_id = {$testCat['id']} AND ps.lang = 'sk'
    GROUP BY ps.criteria_name
    ORDER BY COUNT(DISTINCT ps.criteria_value) DESC
    LIMIT 1
")->fetch();

$testOE = $db->query("SELECT oem_number FROM product_oe_numbers LIMIT 1")->fetchColumn();
$testKtype = $db->query("SELECT ktype FROM product_vehicles GROUP BY ktype ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();

echo "Test category: {$testCat['slug']} (ID: {$testCat['id']}, {$testCat['product_count']} products)\n";
echo "Test product: {$testProduct['sku']} (ID: {$testProduct['id']})\n";
echo "Test spec filter: {$testSpec['criteria_name']} = {$testSpec['criteria_value']}\n";
echo "Test OE: {$testOE}\n";
echo "Test ktype: {$testKtype}\n\n";

$results = [];

echo "--- Parametric Search ---\n";

$catId = (int)$testCat['id'];
$r = bench('Parametric: no filter', fn() => TecDoc::parametricSearch($catId, 1, 24, 'price_asc', [], null, null, 'sk'));
printResult($r, 20.0);
$results[] = $r;

$critName = $testSpec['criteria_name'];
$critVal = $testSpec['criteria_value'];
$r = bench("Parametric: 1 spec filter", fn() => TecDoc::parametricSearch($catId, 1, 24, 'price_asc', [$critName => [$critVal]], null, null, 'sk'));
printResult($r, 30.0);
$results[] = $r;

$r = bench("Parametric: price range", fn() => TecDoc::parametricSearch($catId, 1, 24, 'price_asc', [], 1000, 10000, 'sk'));
printResult($r, 20.0);
$results[] = $r;

$r = bench("Spec filter values (facets)", fn() => TecDoc::specFilterValues($catId, 'sk'));
printResult($r, 30.0);
$results[] = $r;

$r = bench("Spec filter values + active filter", fn() => TecDoc::specFilterValues($catId, 'sk', [$critName => [$critVal]]));
printResult($r, 50.0);
$results[] = $r;

echo "\n--- OE Search ---\n";

$r = bench("OE search exact", fn() => TecDoc::searchByOE($testOE));
printResult($r, 10.0);
$results[] = $r;

$r = bench("OE search partial (6 chars)", fn() => TecDoc::searchByOE(substr($testOE, 0, 6)));
printResult($r, 20.0);
$results[] = $r;

echo "\n--- Vehicle Lookup ---\n";

$ktype = (int)$testKtype;
$r = bench("Parts by ktype", fn() => TecDoc::partsByKtype($ktype));
printResult($r, 15.0);
$results[] = $r;

$r = bench("Categories for ktype", fn() => TecDoc::categoriesForKtype($ktype));
printResult($r, 15.0);
$results[] = $r;

echo "\n--- Product Detail ---\n";

$pid = (int)$testProduct['id'];
$r = bench("Product specifications", fn() => TecDoc::specifications($pid, 'sk'));
printResult($r, 2.0);
$results[] = $r;

$r = bench("Product OE numbers", fn() => TecDoc::oeNumbers($pid));
printResult($r, 2.0);
$results[] = $r;

$r = bench("Product vehicles (50)", fn() => TecDoc::vehiclesForProduct($pid, 50));
printResult($r, 5.0);
$results[] = $r;

$r = bench("Enrich product (full)", fn() => TecDoc::enrichProduct(Products::byId($pid), 'sk'));
printResult($r, 5.0);
$results[] = $r;

echo "\n--- FTS Search (28K products) ---\n";

$r = bench("FTS search 'ventilation'", fn() => Search::query('ventilation', 1, 24));
printResult($r, 20.0);
$results[] = $r;

$r = bench("FTS search 'VALEO'", fn() => Search::query('VALEO', 1, 24));
printResult($r, 20.0);
$results[] = $r;

// Vehicle cascade (from sidecar DB)
$sdb = TecDoc::sidecarDb();
if ($sdb) {
    echo "\n--- Vehicle Cascade (Sidecar DB) ---\n";

    $r = bench("Manufacturers list", fn() => TecDoc::manufacturers());
    printResult($r, 5.0);
    $results[] = $r;

    $mfr = TecDoc::manufacturers()[0] ?? 'VOLKSWAGEN';
    $r = bench("Models for manufacturer", fn() => TecDoc::models($mfr));
    printResult($r, 5.0);
    $results[] = $r;

    $mdl = TecDoc::models($mfr)[0] ?? 'GOLF';
    $r = bench("Types for model", fn() => TecDoc::types($mfr, $mdl));
    printResult($r, 5.0);
    $results[] = $r;
}

// Summary
echo "\n===================================\n";
echo "SUMMARY\n";
echo "===================================\n\n";

$passed = 0;
$failed = 0;
$targets = [
    'Parametric: no filter' => 20.0,
    'Parametric: 1 spec filter' => 30.0,
    'Parametric: price range' => 20.0,
    'Spec filter values (facets)' => 30.0,
    'Spec filter values + active filter' => 50.0,
    'OE search exact' => 150.0,    // 390K cross-refs, LIKE with contains
    'OE search partial (6 chars)' => 150.0,
    'Parts by ktype' => 15.0,
    'Categories for ktype' => 15.0,
    'Product specifications' => 2.0,
    'Product OE numbers' => 2.0,
    'Product vehicles (50)' => 5.0,
    'Enrich product (full)' => 5.0,
    "FTS search 'ventilation'" => 20.0,
    "FTS search 'VALEO'" => 20.0,
    'Manufacturers list' => 5.0,
    'Models for manufacturer' => 5.0,
    'Types for model' => 5.0,
];

foreach ($results as $r) {
    $target = $targets[$r['name']] ?? null;
    if ($target !== null) {
        if ($r['p95'] <= $target) {
            $passed++;
        } else {
            $failed++;
            printf("  [MISS] %-45s p95: %.2fms (target: %.1fms)\n", $r['name'], $r['p95'], $target);
        }
    }
}

echo "\n  Performance targets: {$passed}/" . ($passed + $failed) . " met\n";
if ($failed === 0) echo "  All targets met!\n";
echo "\n";
