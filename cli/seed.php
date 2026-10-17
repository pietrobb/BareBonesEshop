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
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)));
    $slug = trim($slug, '-');

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

    $trans = json_encode([
        'en' => ['name' => str_replace(['Tričko','Košeľa','Mikina','Bunda','Nohavice'], ['T-shirt','Shirt','Hoodie','Jacket','Pants'], $name), 'slug' => $slug . '-en'],
        'de' => ['name' => $name . ' DE', 'slug' => $slug . '-de'],
    ]);

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

$elapsed = round((microtime(true) - $start) * 1000);
$totalProducts = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn();
echo "\nDone! {$totalProducts} products in {$elapsed}ms.\n";
echo "Average: " . round($elapsed / max(1, $count), 2) . "ms per product.\n";
