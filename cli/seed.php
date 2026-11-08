<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Data Seeder
 * Generates large-scale test data for benchmarking.
 * Usage: php cli/seed.php [--count=1000] [--fresh]
 */
require_once __DIR__ . '/../src/autoload.php';

$args = getopt('', ['count:', 'fresh']);
$count = (int)($args['count'] ?? 1000);
$fresh = isset($args['fresh']);

echo "BareBones Shop — Seeder\n";
echo "========================\n";
echo "Target: {$count} products\n\n";

$db = Db::get();

if ($fresh) {
    echo "FRESH mode: clearing data...\n";
    $db->exec("DELETE FROM product_attrs");
    $db->exec("DELETE FROM variants");
    $db->exec("DELETE FROM slug_registry");
    $db->exec("DELETE FROM order_items");
    $db->exec("DELETE FROM orders");
    $db->exec("DELETE FROM products");
    $db->exec("DELETE FROM warehouse_stock");
    $db->exec("DELETE FROM categories");
    echo "Done.\n\n";
}

$start = microtime(true);

// ============================================================
// Categories
// ============================================================
echo "Creating categories...\n";

$categoryTree = [
    'oblecenie' => ['Oblečenie', ['tricka' => 'Tričká', 'nohavice' => 'Nohavice', 'mikiny' => 'Mikiny', 'bundy' => 'Bundy', 'saty' => 'Šaty']],
    'elektronika' => ['Elektronika', ['telefony' => 'Telefóny', 'tablety' => 'Tablety', 'notebooky' => 'Notebooky', 'prislusenstvo' => 'Príslušenstvo']],
    'dom-zahrada' => ['Dom a záhrada', ['nabytok' => 'Nábytok', 'dekoracie' => 'Dekorácie', 'naradie' => 'Náradie', 'osvetlenie' => 'Osvetlenie']],
    'sport' => ['Šport', ['fitness' => 'Fitness', 'outdoor' => 'Outdoor', 'cyklistika' => 'Cyklistika', 'bezecke' => 'Bežecké']],
    'knihy' => ['Knihy', ['beletria' => 'Beletria', 'odborna' => 'Odborná', 'detske' => 'Detské', 'ucebnice' => 'Učebnice']],
];

$catIds = []; // slug => id
$catStmt = $db->prepare(Db::insertIgnore() . " INTO categories (slug, name, parent_id, path, depth, sort_order, translations) VALUES (:slug, :name, :pid, :path, :depth, :sort, :trans)");

$translations = [
    'oblecenie' => ['en' => ['name' => 'Clothing'], 'de' => ['name' => 'Kleidung']],
    'elektronika' => ['en' => ['name' => 'Electronics'], 'de' => ['name' => 'Elektronik']],
    'dom-zahrada' => ['en' => ['name' => 'Home & Garden'], 'de' => ['name' => 'Haus & Garten']],
    'sport' => ['en' => ['name' => 'Sports'], 'de' => ['name' => 'Sport']],
    'knihy' => ['en' => ['name' => 'Books'], 'de' => ['name' => 'Bücher']],
];

$sort = 0;
foreach ($categoryTree as $rootSlug => [$rootName, $children]) {
    $catStmt->execute([
        ':slug' => $rootSlug, ':name' => $rootName, ':pid' => null,
        ':path' => $rootSlug, ':depth' => 0, ':sort' => $sort++,
        ':trans' => json_encode($translations[$rootSlug] ?? []),
    ]);
    $rootId = (int)$db->lastInsertId();
    $catIds[$rootSlug] = $rootId;

    foreach ($children as $childSlug => $childName) {
        $catStmt->execute([
            ':slug' => $childSlug, ':name' => $childName, ':pid' => $rootId,
            ':path' => $rootSlug . '/' . $childSlug, ':depth' => 1, ':sort' => $sort++,
            ':trans' => '{}',
        ]);
        $catIds[$childSlug] = (int)$db->lastInsertId();
    }
}
echo "  Created " . count($catIds) . " categories.\n\n";

// ============================================================
// Products
// ============================================================
echo "Generating {$count} products...\n";

$productTypes = ['simple', 'clothing', 'electronics'];
$colors = ['cierna', 'biela', 'cervena', 'modra', 'zelena', 'seda', 'hneda', 'zlta', 'oranzova', 'ruzova'];
$sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
$materials = ['bavlna', 'polyester', 'vlna', 'silk', 'nylon', 'denim'];
$brands = ['Nike', 'Adidas', 'Samsung', 'Apple', 'Sony', 'LG', 'Bosch', 'Ikea', 'Generic'];
$adjectives = ['Premium', 'Classic', 'Modern', 'Vintage', 'Ultra', 'Pro', 'Eco', 'Slim', 'Max', 'Super'];
$nouns = [
    'oblecenie' => ['Tričko', 'Košeľa', 'Mikina', 'Bunda', 'Nohavice', 'Šortky', 'Sveter', 'Vesta'],
    'elektronika' => ['Telefón', 'Tablet', 'Notebook', 'Slúchadlá', 'Nabíjačka', 'Puzdro', 'Kábel', 'Adaptér'],
    'dom-zahrada' => ['Stolička', 'Stôl', 'Lampa', 'Vankúš', 'Záves', 'Váza', 'Polica', 'Koberec'],
    'sport' => ['Lopta', 'Činky', 'Podložka', 'Fľaša', 'Rukavice', 'Batoh', 'Helma', 'Topánky'],
    'knihy' => ['Román', 'Učebnica', 'Príručka', 'Zbierka', 'Atlas', 'Slovník', 'Encyklopédia', 'Komiks'],
];

$leafCats = []; // category slugs that are children (leaves)
foreach ($categoryTree as $rootSlug => [$_, $children]) {
    foreach ($children as $childSlug => $__) {
        $leafCats[] = ['slug' => $childSlug, 'root' => $rootSlug, 'id' => $catIds[$childSlug]];
    }
}

$prodStmt = $db->prepare("INSERT INTO products (sku, slug, product_type, name, category_id, price, compare_price, currency, stock, status, images, summary, attrs, tags, filter_tags, weight_g, translations) VALUES (:sku, :slug, :type, :name, :cat, :price, :cp, 'eur', :stock, 'active', :images, :summary, :attrs, :tags, :ftags, :weight, :trans)");
$attrStmt = $db->prepare("INSERT INTO product_attrs (product_id, attr_name, attr_value, numeric_val) VALUES (:pid, :name, :val, :num)");
// Checkout reserves from the warehouse ledger, not products.stock: book every product's stock on the default warehouse.
$warehouse = Warehouse::defaultWarehouse();
if (!$warehouse) {
    echo "No default warehouse — run php cli/migrate.php first.\n";
    exit(1);
}
$stockStmt = $db->prepare(Db::insertIgnore() . " INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES (:store, :wh, :sku, :qty, 0)");

$batchSize = 500;
$db->beginTransaction();

for ($i = 1; $i <= $count; $i++) {
    $cat = $leafCats[array_rand($leafCats)];
    $rootSlug = $cat['root'];
    $catId = $cat['id'];

    $adj = $adjectives[array_rand($adjectives)];
    $nounList = $nouns[$rootSlug] ?? $nouns['dom-zahrada'];
    $noun = $nounList[array_rand($nounList)];
    $brand = $brands[array_rand($brands)];
    $color = $colors[array_rand($colors)];

    $name = "{$adj} {$noun} {$brand} #{$i}";
    $sku = strtoupper(substr($rootSlug, 0, 3)) . '-' . str_pad($i, 6, '0', STR_PAD_LEFT);
    $slug = Import::slugify($name); // lower-cases before stripping, so "Premium" stays "premium"

    $price = rand(299, 99900);
    $comparePrice = (rand(0, 4) === 0) ? (int)($price * rand(110, 150) / 100) : null;
    $stock = rand(0, 500);
    $weight = rand(50, 15000);

    $type = match($rootSlug) {
        'oblecenie' => 'clothing',
        'elektronika' => 'electronics',
        default => 'simple',
    };

    $size = $sizes[array_rand($sizes)];
    $material = $materials[array_rand($materials)];

    $attrsJson = json_encode(['color' => $color, 'size' => $size, 'material' => $material, 'brand' => $brand]);
    $tags = json_encode(['bestseller', $rootSlug, $cat['slug']]);

    $filterTags = "attr_color_{$color} attr_size_" . strtolower($size) . " attr_material_{$material} attr_brand_" . strtolower($brand);

    $translated = [
        'en' => ['name' => str_replace(['Tričko','Košeľa','Mikina','Bunda','Nohavice'], ['T-shirt','Shirt','Hoodie','Jacket','Pants'], $name), 'slug' => $slug . '-en'],
        'de' => ['name' => $name . ' DE', 'slug' => $slug . '-de'],
    ];
    $trans = json_encode($translated);

    $summary = "{$adj} {$noun} od {$brand}. Farba: {$color}, Veľkosť: {$size}.";

    $prodStmt->execute([
        ':sku'     => $sku,
        ':slug'    => $slug,
        ':type'    => $type,
        ':name'    => $name,
        ':cat'     => $catId,
        ':price'   => $price,
        ':cp'      => $comparePrice,
        ':stock'   => $stock,
        ':images'  => '["placeholder.jpg"]',
        ':summary' => $summary,
        ':attrs'   => $attrsJson,
        ':tags'    => $tags,
        ':ftags'   => $filterTags,
        ':weight'  => $weight,
        ':trans'   => $trans,
    ]);
    $productId = (int)$db->lastInsertId();
    // Storefront /en/ and /de/ product URLs resolve through slug_registry, as for imported products.
    Import::registerSlug($slug, 'sk', 'product', $productId, $db);
    foreach ($translated as $locale => $tr) Import::registerSlug($tr['slug'], $locale, 'product', $productId, $db);
    $stockStmt->execute([':store' => $warehouse['store_id'], ':wh' => (int)$warehouse['id'], ':sku' => $sku, ':qty' => $stock]);

    // Attributes
    $attrStmt->execute([':pid' => $productId, ':name' => 'color', ':val' => $color, ':num' => null]);
    $attrStmt->execute([':pid' => $productId, ':name' => 'size', ':val' => $size, ':num' => null]);
    $attrStmt->execute([':pid' => $productId, ':name' => 'material', ':val' => $material, ':num' => null]);
    $attrStmt->execute([':pid' => $productId, ':name' => 'brand', ':val' => $brand, ':num' => null]);

    if ($i % $batchSize === 0) {
        $db->commit();
        $db->beginTransaction();
        $elapsed = round((microtime(true) - $start) * 1000);
        echo "  [{$i}/{$count}] {$elapsed}ms\n";
    }
}

if ($db->inTransaction()) $db->commit();

// Subtree counts, as the importer keeps them: products sit in leaf categories, and the storefront menu
// lists only root categories with products.
echo "\nUpdating category counts...\n";
Import::recountCategories($db);

// R23: a sample store in another currency than its catalog (cz CZK, pl PLN, hu HUF, us USD on the EUR catalog) sells
// only what one of its price lists prices in its currency (Pricing::soldInStore): without these lists they sold nothing.
echo "\nPricing the sample stores in their currencies...\n";
$sampleRates = ['eur' => 1.0, 'czk' => 25.0, 'pln' => 4.3, 'huf' => 395.0, 'usd' => 1.08];
$storesCfg = StoreSettings::load();
foreach ((array)($storesCfg['stores'] ?? []) as $storeId => $store) {
    if (!is_array($store)) continue;
    $storeId = (string)$storeId;
    $catalog = (string)($store['catalog_scope'] ?? $storeId);
    $currency = strtolower((string)($store['currency'] ?? ''));
    $catalogCurrency = strtolower(Warehouse::catalogCurrency($catalog));
    if ($currency === '' || $currency === $catalogCurrency) continue;
    if (!isset($sampleRates[$currency], $sampleRates[$catalogCurrency])) {
        echo "  {$storeId}: no sample rate for {$catalogCurrency} → {$currency}, not priced\n";
        continue;
    }
    $rate = $sampleRates[$currency] / $sampleRates[$catalogCurrency];
    $wholeUnits = (int)($storesCfg['currencies'][$currency]['decimals'] ?? 2) === 0;
    $code = 'sample-' . $currency;
    $find = $db->prepare('SELECT id FROM price_lists WHERE store_id = ? AND code = ?');
    $find->execute([$storeId, $code]);
    $listId = (int)$find->fetchColumn();
    if ($listId === 0) {
        $db->prepare('INSERT INTO price_lists (store_id, code, name, currency, customer_group_id, priority, active) VALUES (?, ?, ?, ?, NULL, 0, 1)')
            ->execute([$storeId, $code, 'Sample ' . strtoupper($currency) . ' prices', strtoupper($currency)]);
        $listId = (int)$db->lastInsertId();
    }
    $db->beginTransaction();
    $db->prepare('DELETE FROM price_list_prices WHERE price_list_id = ?')->execute([$listId]);
    $row = $db->prepare('INSERT INTO price_list_prices (price_list_id, product_id, variant_id, min_qty, price, compare_price) VALUES (?, ?, NULL, 1, ?, ?)');
    $convert = static function (?int $cents) use ($rate, $wholeUnits): ?int {
        if ($cents === null || $cents <= 0) return null;
        return $wholeUnits ? max(100, (int)round($cents * $rate / 100) * 100) : max(1, (int)round($cents * $rate));
    };
    $products = $db->prepare("SELECT id, price, compare_price FROM products WHERE store_id = ? AND status = 'active' AND LOWER(currency) = ?");
    $products->execute([$catalog, $catalogCurrency]);
    $priced = 0;
    foreach ($products->fetchAll(PDO::FETCH_ASSOC) as $product) {
        $price = $convert((int)$product['price']);
        if ($price === null) continue;
        $compare = $convert($product['compare_price'] === null ? null : (int)$product['compare_price']);
        $row->execute([$listId, (int)$product['id'], $price, $compare !== null && $compare > $price ? $compare : null]);
        $priced++;
    }
    $db->commit();
    echo "  {$storeId}: {$priced} products in " . strtoupper($currency) . " (price list {$code})\n";
}
Cache::catalogChanged();

$elapsed = round((microtime(true) - $start) * 1000);
$totalProducts = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn();
echo "\nDone! {$totalProducts} products in {$elapsed}ms.\n";
echo "Average: " . round($elapsed / max(1, $count), 2) . "ms per product.\n";
