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
    SalesDaily::refreshAll(true);
    echo "Done.\n\n";
}

$start = microtime(true);

// ============================================================
// Categories
// ============================================================
echo "Creating categories...\n";

// R55 (review round 20, UX): names in the language of the store the default catalog belongs to, translated into the
// shop's other languages (was: always Slovak, with "… DE" German names). sk, cs, en and de; another language gets English.
$seedStores = StoreSettings::load();
$seedLocale = 'en';
foreach ((array)($seedStores['stores'] ?? []) as $seedStoreId => $seedStore) {
    if (is_array($seedStore) && (string)($seedStore['catalog_scope'] ?? $seedStoreId) === 'default' && (string)$seedStoreId === (string)($seedStores['default_store'] ?? $seedStoreId)) {
        $seedLocale = strtolower((string)($seedStore['default_locale'] ?? $seedStores['default_locale'] ?? 'en'));
    }
}
// Each word list in the order sk, cs, en, de.
$seedWords = static fn(string ...$words): array => array_combine(['sk', 'cs', 'en', 'de'], $words);
$seedPacks = ['sk', 'cs', 'en', 'de'];
$seedBase = in_array($seedLocale, $seedPacks, true) ? $seedLocale : 'en';
$seedOther = [];
foreach ((array)($seedStores['stores'] ?? []) as $seedStore) {
    foreach ((array)(is_array($seedStore) ? ($seedStore['languages'] ?? []) : []) as $seedLanguage) {
        $seedLanguage = strtolower((string)$seedLanguage);
        if ($seedLanguage !== $seedBase && in_array($seedLanguage, $seedPacks, true)) $seedOther[$seedLanguage] = true;
    }
}
$seedOther = array_keys($seedOther);

$categoryTree = [
    'oblecenie' => [$seedWords('Oblečenie', 'Oblečení', 'Clothing', 'Kleidung'), [
        'tricka' => $seedWords('Tričká', 'Trička', 'T-shirts', 'T-Shirts'), 'nohavice' => $seedWords('Nohavice', 'Kalhoty', 'Trousers', 'Hosen'),
        'mikiny' => $seedWords('Mikiny', 'Mikiny', 'Hoodies', 'Kapuzenpullover'), 'bundy' => $seedWords('Bundy', 'Bundy', 'Jackets', 'Jacken'),
        'saty' => $seedWords('Šaty', 'Šaty', 'Dresses', 'Kleider')]],
    'elektronika' => [$seedWords('Elektronika', 'Elektronika', 'Electronics', 'Elektronik'), [
        'telefony' => $seedWords('Telefóny', 'Telefony', 'Phones', 'Telefone'), 'tablety' => $seedWords('Tablety', 'Tablety', 'Tablets', 'Tablets'),
        'notebooky' => $seedWords('Notebooky', 'Notebooky', 'Laptops', 'Notebooks'), 'prislusenstvo' => $seedWords('Príslušenstvo', 'Příslušenství', 'Accessories', 'Zubehör')]],
    'dom-zahrada' => [$seedWords('Dom a záhrada', 'Dům a zahrada', 'Home & Garden', 'Haus & Garten'), [
        'nabytok' => $seedWords('Nábytok', 'Nábytek', 'Furniture', 'Möbel'), 'dekoracie' => $seedWords('Dekorácie', 'Dekorace', 'Decorations', 'Dekoration'),
        'naradie' => $seedWords('Náradie', 'Nářadí', 'Tools', 'Werkzeug'), 'osvetlenie' => $seedWords('Osvetlenie', 'Osvětlení', 'Lighting', 'Beleuchtung')]],
    'sport' => [$seedWords('Šport', 'Sport', 'Sports', 'Sport'), [
        'fitness' => $seedWords('Fitness', 'Fitness', 'Fitness', 'Fitness'), 'outdoor' => $seedWords('Outdoor', 'Outdoor', 'Outdoor', 'Outdoor'),
        'cyklistika' => $seedWords('Cyklistika', 'Cyklistika', 'Cycling', 'Radsport'), 'bezecke' => $seedWords('Bežecké', 'Běžecké', 'Running', 'Laufen')]],
    'knihy' => [$seedWords('Knihy', 'Knihy', 'Books', 'Bücher'), [
        'beletria' => $seedWords('Beletria', 'Beletrie', 'Fiction', 'Belletristik'), 'odborna' => $seedWords('Odborná', 'Odborná', 'Non-fiction', 'Sachbücher'),
        'detske' => $seedWords('Detské', 'Dětské', "Children's", 'Kinderbücher'), 'ucebnice' => $seedWords('Učebnice', 'Učebnice', 'Textbooks', 'Lehrbücher')]],
];

$catIds = []; // key => id
$catStmt = $db->prepare(Db::insertIgnore() . " INTO categories (slug, name, parent_id, path, depth, sort_order, translations) VALUES (:slug, :name, :pid, :path, :depth, :sort, :trans)");
$catTranslations = static function (array $names) use ($seedOther): string {
    $translations = [];
    foreach ($seedOther as $locale) $translations[$locale] = ['name' => $names[$locale]];
    return $translations === [] ? '{}' : json_encode($translations, JSON_UNESCAPED_UNICODE);
};

$sort = 0;
foreach ($categoryTree as $rootKey => [$rootNames, $children]) {
    $rootSlug = Import::slugify($rootNames[$seedBase]);
    $catStmt->execute([
        ':slug' => $rootSlug, ':name' => $rootNames[$seedBase], ':pid' => null,
        ':path' => $rootSlug, ':depth' => 0, ':sort' => $sort++,
        ':trans' => $catTranslations($rootNames),
    ]);
    $rootId = (int)$db->lastInsertId();
    $catIds[$rootKey] = $rootId;

    foreach ($children as $childKey => $childNames) {
        $childSlug = Import::slugify($childNames[$seedBase]);
        $catStmt->execute([
            ':slug' => $childSlug, ':name' => $childNames[$seedBase], ':pid' => $rootId,
            ':path' => $rootSlug . '/' . $childSlug, ':depth' => 1, ':sort' => $sort++,
            ':trans' => $catTranslations($childNames),
        ]);
        $catIds[$childKey] = (int)$db->lastInsertId();
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
// Per category: [sk, cs, en, de] words of one product kind.
$nouns = [
    'oblecenie' => [['Tričko', 'Tričko', 'T-shirt', 'T-Shirt'], ['Košeľa', 'Košile', 'Shirt', 'Hemd'], ['Mikina', 'Mikina', 'Hoodie', 'Kapuzenpullover'],
        ['Bunda', 'Bunda', 'Jacket', 'Jacke'], ['Nohavice', 'Kalhoty', 'Trousers', 'Hose'], ['Šortky', 'Kraťasy', 'Shorts', 'Shorts'],
        ['Sveter', 'Svetr', 'Sweater', 'Pullover'], ['Vesta', 'Vesta', 'Vest', 'Weste']],
    'elektronika' => [['Telefón', 'Telefon', 'Phone', 'Telefon'], ['Tablet', 'Tablet', 'Tablet', 'Tablet'], ['Notebook', 'Notebook', 'Laptop', 'Notebook'],
        ['Slúchadlá', 'Sluchátka', 'Headphones', 'Kopfhörer'], ['Nabíjačka', 'Nabíječka', 'Charger', 'Ladegerät'], ['Puzdro', 'Pouzdro', 'Case', 'Hülle'],
        ['Kábel', 'Kabel', 'Cable', 'Kabel'], ['Adaptér', 'Adaptér', 'Adapter', 'Adapter']],
    'dom-zahrada' => [['Stolička', 'Židle', 'Chair', 'Stuhl'], ['Stôl', 'Stůl', 'Table', 'Tisch'], ['Lampa', 'Lampa', 'Lamp', 'Lampe'],
        ['Vankúš', 'Polštář', 'Pillow', 'Kissen'], ['Záves', 'Závěs', 'Curtain', 'Vorhang'], ['Váza', 'Váza', 'Vase', 'Vase'],
        ['Polica', 'Police', 'Shelf', 'Regal'], ['Koberec', 'Koberec', 'Rug', 'Teppich']],
    'sport' => [['Lopta', 'Míč', 'Ball', 'Ball'], ['Činky', 'Činky', 'Dumbbells', 'Hanteln'], ['Podložka', 'Podložka', 'Mat', 'Matte'],
        ['Fľaša', 'Láhev', 'Bottle', 'Flasche'], ['Rukavice', 'Rukavice', 'Gloves', 'Handschuhe'], ['Batoh', 'Batoh', 'Backpack', 'Rucksack'],
        ['Helma', 'Helma', 'Helmet', 'Helm'], ['Topánky', 'Boty', 'Shoes', 'Schuhe']],
    'knihy' => [['Román', 'Román', 'Novel', 'Roman'], ['Učebnica', 'Učebnice', 'Textbook', 'Lehrbuch'], ['Príručka', 'Příručka', 'Handbook', 'Handbuch'],
        ['Zbierka', 'Sbírka', 'Collection', 'Sammlung'], ['Atlas', 'Atlas', 'Atlas', 'Atlas'], ['Slovník', 'Slovník', 'Dictionary', 'Wörterbuch'],
        ['Encyklopédia', 'Encyklopedie', 'Encyclopedia', 'Enzyklopädie'], ['Komiks', 'Komiks', 'Comic', 'Comic']],
];
$colorNames = [
    'cierna' => $seedWords('čierna', 'černá', 'black', 'schwarz'), 'biela' => $seedWords('biela', 'bílá', 'white', 'weiß'),
    'cervena' => $seedWords('červená', 'červená', 'red', 'rot'), 'modra' => $seedWords('modrá', 'modrá', 'blue', 'blau'),
    'zelena' => $seedWords('zelená', 'zelená', 'green', 'grün'), 'seda' => $seedWords('sivá', 'šedá', 'grey', 'grau'),
    'hneda' => $seedWords('hnedá', 'hnědá', 'brown', 'braun'), 'zlta' => $seedWords('žltá', 'žlutá', 'yellow', 'gelb'),
    'oranzova' => $seedWords('oranžová', 'oranžová', 'orange', 'orange'), 'ruzova' => $seedWords('ružová', 'růžová', 'pink', 'rosa'),
];
$summaryText = $seedWords('{adj} {noun} od {brand}. Farba: {color}, veľkosť: {size}.', '{adj} {noun} od {brand}. Barva: {color}, velikost: {size}.',
    '{adj} {noun} by {brand}. Colour: {color}, size: {size}.', '{adj} {noun} von {brand}. Farbe: {color}, Größe: {size}.');

$leafCats = []; // category slugs that are children (leaves)
foreach ($categoryTree as $rootSlug => [$_, $children]) {
    foreach ($children as $childSlug => $__) {
        $leafCats[] = ['slug' => $childSlug, 'root' => $rootSlug, 'id' => $catIds[$childSlug]];
    }
}

// R54 (review round 19, UX): in the currency of the store the default catalog belongs to (was: always 'eur', so a CZK
// shop listed every seeded product as unavailable).
$seedCurrency = Warehouse::catalogCurrency('default');
$sampleRates = ['eur' => 1.0, 'czk' => 25.0, 'pln' => 4.3, 'huf' => 395.0, 'usd' => 1.08];
// Prices are drawn in euro cents and shown in the catalog's currency at the sample rate (whole units where it has none).
$seedRate = $sampleRates[$seedCurrency] ?? 1.0;
$seedWhole = (int)((StoreSettings::load()['currencies'][$seedCurrency]['decimals'] ?? 2)) === 0;
$seedPrice = static fn(int $eurCents): int => $seedWhole ? (int)round($eurCents * $seedRate / 100) * 100 : (int)round($eurCents * $seedRate);
$prodStmt = $db->prepare("INSERT INTO products (sku, slug, product_type, name, category_id, price, compare_price, currency, stock, status, images, summary, attrs, tags, filter_tags, weight_g, translations) VALUES (:sku, :slug, :type, :name, :cat, :price, :cp, " . $db->quote($seedCurrency) . ", :stock, 'active', :images, :summary, :attrs, :tags, :ftags, :weight, :trans)");
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
    $nounList = $nouns[$rootSlug];
    $nounWords = $seedWords(...$nounList[array_rand($nounList)]);
    $brand = $brands[array_rand($brands)];
    $color = $colors[array_rand($colors)];
    $size = $sizes[array_rand($sizes)];
    $localized = static fn(string $locale): array => [
        'name' => "{$adj} {$nounWords[$locale]} {$brand} #{$i}",
        'summary' => strtr($summaryText[$locale], ['{adj}' => $adj, '{noun}' => $nounWords[$locale], '{brand}' => $brand, '{color}' => $colorNames[$color][$locale], '{size}' => $size]),
    ];

    $name = $localized($seedBase)['name'];
    $sku = strtoupper(substr($rootSlug, 0, 3)) . '-' . str_pad($i, 6, '0', STR_PAD_LEFT);
    $slug = Import::slugify($name); // lower-cases before stripping, so "Premium" stays "premium"

    $price = $seedPrice(rand(299, 99900));
    $comparePrice = (rand(0, 4) === 0) ? (int)($price * rand(110, 150) / 100) : null;
    $stock = rand(0, 500);
    $weight = rand(50, 15000);

    $type = match($rootSlug) {
        'oblecenie' => 'clothing',
        'elektronika' => 'electronics',
        default => 'simple',
    };

    $material = $materials[array_rand($materials)];

    $attrsJson = json_encode(['color' => $color, 'size' => $size, 'material' => $material, 'brand' => $brand]);
    $tags = json_encode(['bestseller', $rootSlug, $cat['slug']]);

    $filterTags = "attr_color_{$color} attr_size_" . strtolower($size) . " attr_material_{$material} attr_brand_" . strtolower($brand);

    $translated = [];
    foreach ($seedOther as $locale) {
        $text = $localized($locale);
        $translated[$locale] = ['name' => $text['name'], 'summary' => $text['summary'], 'slug' => Import::slugify($text['name']) . '-' . $locale];
    }
    $trans = $translated === [] ? '{}' : json_encode($translated, JSON_UNESCAPED_UNICODE);

    $summary = $localized($seedBase)['summary'];

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
    // Storefront product URLs of every language resolve through slug_registry, as for imported products.
    Import::registerSlug($slug, $seedBase, 'product', $productId, $db);
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
