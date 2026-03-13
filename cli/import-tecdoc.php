<?php
/**
 * BareBones Shop — TecDoc Import
 * Reads demo/tecdoc_enriched.db → creates categories, products, specs, OE numbers, vehicles.
 *
 * Usage: php cli/import-tecdoc.php [--fresh] [--limit=N]
 *   --fresh  Clear all existing data first
 *   --limit  Import only N articles (for testing)
 */
require_once __DIR__ . '/../src/autoload.php';

$args = getopt('', ['fresh', 'limit:']);
$fresh = isset($args['fresh']);
$limit = isset($args['limit']) ? (int)$args['limit'] : 0;

echo "BareBones Shop — TecDoc Import\n";
echo "================================\n\n";

// Open TecDoc sidecar DB
$tecdocPath = __DIR__ . '/../demo/tecdoc_enriched.db';
if (!file_exists($tecdocPath)) {
    echo "ERROR: TecDoc database not found at {$tecdocPath}\n";
    exit(1);
}

$tdb = new PDO('sqlite:' . $tecdocPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$tdb->exec('PRAGMA cache_size = -64000');
$tdb->exec('PRAGMA mmap_size = 268435456');

$db = Db::get();
$start = microtime(true);

// Stats
$totalArticles = (int)$tdb->query("SELECT COUNT(*) FROM articles")->fetchColumn();
echo "TecDoc: {$totalArticles} articles\n";

if ($fresh) {
    echo "FRESH mode: clearing shop data...\n";
    $db->exec("DELETE FROM product_vehicles");
    $db->exec("DELETE FROM product_oe_numbers");
    $db->exec("DELETE FROM product_specifications");
    $db->exec("DELETE FROM product_attrs");
    $db->exec("DELETE FROM variants");
    $db->exec("DELETE FROM slug_registry");
    $db->exec("DELETE FROM order_items");
    $db->exec("DELETE FROM orders");
    $db->exec("DELETE FROM products");
    $db->exec("DELETE FROM categories");
    echo "Done.\n\n";
}

// ============================================================
// 1. Build Category Tree from genart_categories
// ============================================================
echo "Creating categories from genart hierarchy...\n";

$genarts = $tdb->query("
    SELECT gc.genart_no, gc.assembly, gc.description,
           COUNT(DISTINCT ag.katalog) as article_count
    FROM genart_categories gc
    LEFT JOIN article_genart ag ON ag.genart_no = gc.genart_no
    GROUP BY gc.genart_no
    ORDER BY gc.assembly, gc.description
")->fetchAll();

$catIds = [];      // slug => category_id
$genartToCat = []; // genart_no => category_id

$catStmt = $db->prepare("INSERT OR IGNORE INTO categories (slug, name, parent_id, path, depth, sort_order, translations, meta)
    VALUES (:slug, :name, :pid, :path, :depth, :sort, :trans, :meta)");

$db->beginTransaction();
$sort = 0;

// Track assembly (root) categories
$assemblyIds = []; // assembly_slug => id

foreach ($genarts as $g) {
    $assembly = trim($g['assembly']);
    if ($assembly === '') $assembly = 'Other';

    $assemblySlug = Import::slugify($assembly);
    if (!$assemblySlug) $assemblySlug = 'other';

    // Create assembly root category if not exists
    if (!isset($assemblyIds[$assemblySlug])) {
        $catStmt->execute([
            ':slug'  => $assemblySlug,
            ':name'  => $assembly,
            ':pid'   => null,
            ':path'  => $assemblySlug,
            ':depth' => 0,
            ':sort'  => $sort++,
            ':trans' => '{}',
            ':meta'  => '{}',
        ]);
        // Could be 0 if already exists (INSERT OR IGNORE)
        $id = (int)$db->lastInsertId();
        if ($id === 0) {
            $id = (int)$db->query("SELECT id FROM categories WHERE slug = " . $db->quote($assemblySlug))->fetchColumn();
        }
        $assemblyIds[$assemblySlug] = $id;
        $catIds[$assemblySlug] = $id;
    }
    $parentId = $assemblyIds[$assemblySlug];

    // Create genart child category
    $desc = trim($g['description']);
    if (!$desc) continue;

    $childSlug = Import::slugify($desc);
    if (!$childSlug) continue;

    // Ensure uniqueness (some genarts have same description)
    $uniqueSlug = $childSlug;
    if (isset($catIds[$uniqueSlug])) {
        $uniqueSlug = $childSlug . '-' . $g['genart_no'];
    }

    $childPath = $assemblySlug . '/' . $uniqueSlug;

    $catStmt->execute([
        ':slug'  => $uniqueSlug,
        ':name'  => $desc,
        ':pid'   => $parentId,
        ':path'  => $childPath,
        ':depth' => 1,
        ':sort'  => $sort++,
        ':trans' => '{}',
        ':meta'  => json_encode(['genart_no' => (int)$g['genart_no']]),
    ]);

    $id = (int)$db->lastInsertId();
    if ($id === 0) {
        $id = (int)$db->query("SELECT id FROM categories WHERE slug = " . $db->quote($uniqueSlug))->fetchColumn();
    }

    $catIds[$uniqueSlug] = $id;
    $genartToCat[(int)$g['genart_no']] = $id;
}

$db->commit();
echo "  Created " . count($catIds) . " categories (" . count($assemblyIds) . " assemblies, " . count($genartToCat) . " genarts)\n\n";

// ============================================================
// 2. Import Articles as Products
// ============================================================
echo "Importing articles as products...\n";

// Price ranges by assembly (cents) — realistic auto parts pricing
$priceRanges = [
    'body'           => [500, 25000],
    'brake-system'   => [800, 15000],
    'cooling-system' => [400, 12000],
    'lights'         => [1500, 35000],
    'engine-mounting' => [2000, 15000],
    'wheel-suspension' => [1500, 20000],
    'air-supply'     => [600, 8000],
    'mixture-formation' => [2000, 25000],
    'steering'       => [3000, 25000],
    'suspension'     => [3000, 20000],
    'clutch'         => [5000, 35000],
    'starter-system' => [5000, 30000],
    'alternator'     => [5000, 35000],
    'ignition-system' => [500, 8000],
    'default'        => [500, 15000],
];

// Supplier markup multipliers
$supplierMarkup = [
    'VALEO'  => 1.0,
    'BOSCH'  => 1.15,
    'SACHS'  => 1.1,
    'TRW'    => 1.05,
    'FEBI'   => 0.9,
    'MEYLE'  => 0.95,
    'SKF'    => 1.1,
    'HELLA'  => 1.1,
    'LuK'    => 1.05,
    'BEHR'   => 1.0,
];

// Get article→genart mapping
$articleGenarts = [];
$agRows = $tdb->query("SELECT katalog, genart_no FROM article_genart")->fetchAll();
foreach ($agRows as $ag) {
    $articleGenarts[$ag['katalog']] = (int)$ag['genart_no'];
}
echo "  Loaded " . count($articleGenarts) . " article-genart mappings\n";

// Get translations (row-by-row to conserve memory)
$i18nMap = [];
$i18nCursor = $tdb->query("SELECT katalog, lang, product_name FROM article_i18n");
while ($row = $i18nCursor->fetch()) {
    $i18nMap[$row['katalog']][$row['lang']] = $row['product_name'];
}
echo "  Loaded " . count($i18nMap) . " i18n records\n";

// Prepare statements
$prodStmt = $db->prepare("INSERT INTO products (sku, slug, product_type, name, category_id, price, compare_price, currency, stock, status, images, summary, description, attrs, tags, filter_tags, weight_g, translations)
    VALUES (:sku, :slug, 'auto_part', :name, :cat, :price, :cp, 'eur', :stock, 'active', :images, :summary, '', :attrs, :tags, :ftags, 0, :trans)");

// Default (uncategorized) category
$defaultCatId = null;

// Fetch all articles
$articleQuery = "SELECT * FROM articles ORDER BY katalog";
if ($limit > 0) $articleQuery .= " LIMIT {$limit}";
$articles = $tdb->query($articleQuery)->fetchAll();

$imported = 0;
$skipped = 0;
$batchSize = 500;
$db->beginTransaction();

$usedSlugs = [];

foreach ($articles as $art) {
    $katalog = $art['katalog'];
    $genartNo = $articleGenarts[$katalog] ?? null;

    // Determine category
    $catId = null;
    if ($genartNo && isset($genartToCat[$genartNo])) {
        $catId = $genartToCat[$genartNo];
    }
    if (!$catId) {
        if (!$defaultCatId) {
            $defaultCatId = Import::ensureCategory('uncategorized', $db);
        }
        $catId = $defaultCatId;
    }

    // Name (SK primary)
    $name = trim($art['money_nazev'] ?? '');
    if (!$name) {
        $name = $i18nMap[$katalog]['sk'] ?? ($i18nMap[$katalog]['en'] ?? "Article {$katalog}");
    }

    // SKU = supplier article number
    $sku = trim($art['article_no'] ?? $katalog);
    $supplier = trim($art['supplier_name'] ?? '');
    // Make SKU unique by prefixing supplier
    $fullSku = $supplier ? (strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $supplier), 0, 3)) . '-' . $sku) : $sku;

    // Slug
    $slug = Import::slugify($name . '-' . $fullSku);
    if (!$slug) $slug = 'article-' . $katalog;
    // Ensure unique
    if (isset($usedSlugs[$slug])) {
        $slug .= '-' . $katalog;
    }
    $usedSlugs[$slug] = true;

    // Price — deterministic based on katalog hash
    $assemblySlug = '';
    if ($genartNo && isset($genartToCat[$genartNo])) {
        // Find assembly from category path
        $catRow = $db->query("SELECT path FROM categories WHERE id = {$catId}")->fetch();
        $assemblySlug = explode('/', $catRow['path'] ?? '')[0] ?? '';
    }
    $range = $priceRanges[$assemblySlug] ?? $priceRanges['default'];
    $markup = $supplierMarkup[$supplier] ?? 1.0;

    // Deterministic "random" price from hash
    $hash = crc32($katalog);
    $priceBase = $range[0] + abs($hash) % ($range[1] - $range[0]);
    $price = (int)round($priceBase * $markup);

    // Some items on sale
    $comparePrice = null;
    if ($hash % 5 === 0) {
        $comparePrice = (int)round($price * (1 + ($hash % 30 + 10) / 100));
    }

    $stock = 5 + abs($hash) % 50;

    // Images
    $images = [];
    if (!empty($art['main_image_url'])) {
        $images[] = $art['main_image_url'];
    }

    // Attrs (for FTS filter_tags)
    $attrs = [];
    if ($supplier) $attrs['supplier'] = $supplier;
    if ($genartNo) $attrs['genart'] = (string)$genartNo;

    $filterTags = Import::buildFilterTagsFromAttrs($attrs);

    // Tags
    $tags = ['auto-parts'];
    if ($supplier) $tags[] = strtolower($supplier);

    // Summary
    $summary = $supplier ? "{$name} — {$supplier}" : $name;
    if (!empty($art['article_no'])) {
        $summary .= " (Art.Nr. {$art['article_no']})";
    }

    // Translations
    $translations = [];
    foreach (['en', 'de'] as $lang) {
        $trName = $i18nMap[$katalog][$lang] ?? null;
        if ($trName) {
            $translations[$lang] = [
                'name' => $trName,
                'slug' => Import::slugify($trName . '-' . $fullSku),
            ];
        }
    }

    try {
        $prodStmt->execute([
            ':sku'     => $fullSku,
            ':slug'    => $slug,
            ':name'    => $name,
            ':cat'     => $catId,
            ':price'   => $price,
            ':cp'      => $comparePrice,
            ':stock'   => $stock,
            ':images'  => json_encode($images),
            ':summary' => $summary,
            ':attrs'   => json_encode($attrs),
            ':tags'    => json_encode($tags),
            ':ftags'   => $filterTags,
            ':trans'   => json_encode($translations),
        ]);
        $imported++;
    } catch (PDOException $e) {
        // Duplicate slug/sku — skip
        $skipped++;
        continue;
    }

    if ($imported % $batchSize === 0) {
        $db->commit();
        $db->beginTransaction();
        $elapsed = round((microtime(true) - $start) * 1000);
        echo "  [{$imported}] {$elapsed}ms\n";
    }
}

if ($db->inTransaction()) $db->commit();
echo "  Imported: {$imported}, Skipped: {$skipped}\n\n";

// ============================================================
// 3. Import Product Attributes (for standard faceted filtering)
// ============================================================
echo "Importing product attributes...\n";

$attrStmt = $db->prepare("INSERT INTO product_attrs (product_id, attr_name, attr_value, numeric_val) VALUES (:pid, :name, :val, :num)");

$db->beginTransaction();
$attrCount = 0;

// Get product ID map: sku → id
$prodIdMap = [];
$rows = $db->query("SELECT id, sku FROM products WHERE product_type = 'auto_part'")->fetchAll();
foreach ($rows as $r) {
    $prodIdMap[$r['sku']] = (int)$r['id'];
}

// Insert supplier and genart as filterable attributes
foreach ($prodIdMap as $sku => $pid) {
    $product = $db->query("SELECT attrs FROM products WHERE id = {$pid}")->fetchColumn();
    $attrs = json_decode($product, true) ?: [];

    foreach ($attrs as $name => $value) {
        $numVal = is_numeric($value) ? (float)$value : null;
        $attrStmt->execute([':pid' => $pid, ':name' => $name, ':val' => (string)$value, ':num' => $numVal]);
        $attrCount++;
    }

    if ($attrCount % 5000 === 0) {
        $db->commit();
        $db->beginTransaction();
    }
}
if ($db->inTransaction()) $db->commit();
echo "  Inserted {$attrCount} product attributes\n\n";

// ============================================================
// 4. Import Specifications (parametric data)
// ============================================================
echo "Importing specifications...\n";

$specStmt = $db->prepare("INSERT INTO product_specifications (product_id, lang, criteria_name, criteria_value, numeric_val, sort_order) VALUES (:pid, :lang, :cname, :cval, :nval, :sort)");

// Build katalog→product_id mapping, then free large arrays
$katalogToProductId = [];
foreach ($articles as $art) {
    $katalog = $art['katalog'];
    $supplier = trim($art['supplier_name'] ?? '');
    $sku = trim($art['article_no'] ?? $katalog);
    $fullSku = $supplier ? (strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $supplier), 0, 3)) . '-' . $sku) : $sku;
    if (isset($prodIdMap[$fullSku])) {
        $katalogToProductId[$katalog] = $prodIdMap[$fullSku];
    }
}

// Free memory
unset($articles, $i18nMap, $agRows, $articleGenarts, $usedSlugs, $prodIdMap);

echo "  Mapped " . count($katalogToProductId) . " katalog→product_id\n";

$db->beginTransaction();
$specCount = 0;

// Process specs row-by-row from TecDoc (too large for fetchAll)
$specCursor = $tdb->query("SELECT katalog, lang, seq, criteria_name, criteria_value FROM article_specifications ORDER BY katalog, lang, seq");

while ($spec = $specCursor->fetch()) {
    $pid = $katalogToProductId[$spec['katalog']] ?? null;
    if (!$pid) continue;

    $critValue = trim($spec['criteria_value']);
    if ($critValue === '') continue;

    // Extract numeric value if present
    $numVal = null;
    if (preg_match('/^[\d]+([.,]\d+)?$/', $critValue)) {
        $numVal = (float)str_replace(',', '.', $critValue);
    }

    $specStmt->execute([
        ':pid'   => $pid,
        ':lang'  => $spec['lang'],
        ':cname' => trim($spec['criteria_name']),
        ':cval'  => $critValue,
        ':nval'  => $numVal,
        ':sort'  => (int)$spec['seq'],
    ]);
    $specCount++;

    if ($specCount % 10000 === 0) {
        $db->commit();
        $db->beginTransaction();
        $elapsed = round((microtime(true) - $start) * 1000);
        echo "  [{$specCount} specs] {$elapsed}ms\n";
    }
}
if ($db->inTransaction()) $db->commit();
echo "  Inserted {$specCount} specifications\n\n";

// ============================================================
// 5. Import OE Numbers
// ============================================================
echo "Importing OE numbers...\n";

$oeStmt = $db->prepare("INSERT INTO product_oe_numbers (product_id, oem_brand, oem_number, oem_number_norm) VALUES (:pid, :brand, :num, :norm)");

$db->beginTransaction();
$oeCount = 0;

$oeCursor = $tdb->query("SELECT katalog, oem_brand, oem_number FROM article_oe_numbers ORDER BY katalog");

while ($oe = $oeCursor->fetch()) {
    $pid = $katalogToProductId[$oe['katalog']] ?? null;
    if (!$pid) continue;

    $oeNum = trim($oe['oem_number']);
    $oeNorm = preg_replace('/[\s\-\.\/\']/', '', $oeNum);
    $oeStmt->execute([
        ':pid'   => $pid,
        ':brand' => trim($oe['oem_brand']),
        ':num'   => $oeNum,
        ':norm'  => strtoupper($oeNorm),
    ]);
    $oeCount++;

    if ($oeCount % 10000 === 0) {
        $db->commit();
        $db->beginTransaction();
        $elapsed = round((microtime(true) - $start) * 1000);
        echo "  [{$oeCount} OE] {$elapsed}ms\n";
    }
}
if ($db->inTransaction()) $db->commit();
echo "  Inserted {$oeCount} OE numbers\n\n";

// ============================================================
// 6. Import Vehicle Compatibility
// ============================================================
echo "Importing vehicle compatibility...\n";

$vehStmt = $db->prepare("INSERT INTO product_vehicles (product_id, ktype, manufacturer, model, type_name) VALUES (:pid, :ktype, :mfr, :mdl, :type)");

$db->beginTransaction();
$vehCount = 0;

// Row-by-row to avoid OOM with 2.5M rows
$vehCursor = $tdb->query("SELECT DISTINCT cc.katalog, cc.vehicle_id as ktype, cc.manufacturer_name, cc.model_name, cc.engine_name
    FROM article_compatible_cars cc ORDER BY cc.katalog");

$seenPairs = []; // Avoid duplicates: pid-ktype

while ($v = $vehCursor->fetch()) {
    $pid = $katalogToProductId[$v['katalog']] ?? null;
    if (!$pid) continue;

    $pairKey = $pid . '-' . $v['ktype'];
    if (isset($seenPairs[$pairKey])) continue;
    $seenPairs[$pairKey] = true;

    $vehStmt->execute([
        ':pid'   => $pid,
        ':ktype' => (int)$v['ktype'],
        ':mfr'   => trim($v['manufacturer_name']),
        ':mdl'   => trim($v['model_name']),
        ':type'  => trim($v['engine_name'] ?? ''),
    ]);
    $vehCount++;

    if ($vehCount % 50000 === 0) {
        $db->commit();
        $db->beginTransaction();
        $elapsed = round((microtime(true) - $start) * 1000);
        echo "  [{$vehCount} vehicles] {$elapsed}ms\n";
    }
}
if ($db->inTransaction()) $db->commit();
unset($seenPairs);
echo "  Inserted {$vehCount} vehicle compatibility records\n\n";

// ============================================================
// 7. Update category product counts
// ============================================================
echo "Updating category counts...\n";
Import::recountCategories($db);

// Also update parent category counts (sum of children)
$db->exec("UPDATE categories SET product_count = (
    SELECT COALESCE(SUM(c2.product_count), 0) + categories.product_count
    FROM categories c2
    WHERE c2.parent_id = categories.id
) WHERE EXISTS (SELECT 1 FROM categories c3 WHERE c3.parent_id = categories.id)");

// ============================================================
// 8. Rebuild FTS index
// ============================================================
echo "Rebuilding FTS index...\n";
if (Db::isSqlite()) {
    // Repopulate FTS from products
    $db->exec("INSERT INTO products_fts(products_fts) VALUES('rebuild')");
}

// ============================================================
// Summary
// ============================================================
$elapsed = round((microtime(true) - $start), 2);
$totalProducts = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalCats = (int)$db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$totalSpecs = (int)$db->query("SELECT COUNT(*) FROM product_specifications")->fetchColumn();
$totalOE = (int)$db->query("SELECT COUNT(*) FROM product_oe_numbers")->fetchColumn();
$totalVeh = (int)$db->query("SELECT COUNT(*) FROM product_vehicles")->fetchColumn();

echo "\n================================\n";
echo "TecDoc Import Complete!\n";
echo "================================\n";
echo "  Products:       {$totalProducts}\n";
echo "  Categories:     {$totalCats}\n";
echo "  Specifications: {$totalSpecs}\n";
echo "  OE Numbers:     {$totalOE}\n";
echo "  Vehicles:       {$totalVeh}\n";
echo "  Time:           {$elapsed}s\n";
echo "\n";
