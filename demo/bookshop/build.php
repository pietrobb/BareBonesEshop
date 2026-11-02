<?php
declare(strict_types=1);

/**
 * Bookshop demo catalog generator (#237): public-domain books as an eBook download and a paperback, most also as a
 * hardcover; genre categories, filterable specifications (author, century, language, format), an original SVG cover
 * per title and a small EPUB demo edition behind every eBook variant.
 *
 * Usage: php demo/bookshop/build.php [--store=default] [--shop-name="Bookshop"]
 *
 * Runs against the database in config.php and only on an empty catalog. Deterministic: the same data files give the
 * same catalog, prices, stock, covers and EPUB bytes.
 */
require_once __DIR__ . '/../../cli/cli-only.php';
require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/lib/BookCover.php';
require_once __DIR__ . '/lib/EpubWriter.php';

$options = getopt('', ['store:', 'shop-name:']);
$storeId = (string)($options['store'] ?? 'default');
$shopName = (string)($options['shop-name'] ?? 'Bookshop');
$root = dirname(__DIR__, 2);
$config = require $root . '/config.php';

Bootstrap::initStoreContext('/', '', $storeId);
$catalog = StoreContext::current()->catalogId();
$db = Db::get();

$taken = $db->prepare('SELECT COUNT(*) FROM products WHERE store_id = :catalog');
$taken->execute([':catalog' => $catalog]);
if ((int)$taken->fetchColumn() > 0) {
    fwrite(STDERR, "The catalog '{$catalog}' already has products. The bookshop demo is built into an empty catalog.\n");
    exit(1);
}
$warehouse = Warehouse::defaultWarehouse();
if (!$warehouse) {
    fwrite(STDERR, "No default warehouse - run php cli/migrate.php first.\n");
    exit(1);
}
$downloads = rtrim((string)($config['digital_assets_base_dir'] ?? ''), '/\\');
if ($downloads === '') {
    fwrite(STDERR, "digital_assets_base_dir is not configured: the eBooks need a private download directory.\n");
    exit(1);
}
$coverDir = rtrim((string)($config['uploads_dir'] ?? $root . '/uploads'), '/\\') . '/bookshop/covers';
$epubDir = $downloads . '/' . $catalog . '/bookshop';
foreach ([$coverDir, $epubDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot create {$dir}\n");
        exit(1);
    }
}

/** Display names and order of the genre categories; each has a data file data/<genre>.php. */
$genres = [
    'classics' => 'Classics', 'adventure' => 'Adventure', 'science-fiction' => 'Science Fiction', 'mystery' => 'Mystery & Crime',
    'horror' => 'Gothic & Horror', 'romance' => 'Romance', 'historical' => 'Historical Fiction', 'children' => 'Young Readers',
    'humour' => 'Humour & Satire', 'poetry-drama' => 'Poetry & Drama', 'ideas' => 'Philosophy & Ideas', 'travel' => 'Travel & Nature',
];
$descriptions = [
    'classics' => 'The great novels of the 19th century and earlier: the books every other book talks about.',
    'adventure' => 'Desert islands, pirate gold, the Klondike and the open sea.',
    'science-fiction' => 'Where the genre began: time machines, Martians, submarines and the Moon.',
    'mystery' => 'Detectives, spies and impossible crimes, from Baker Street to the Thames.',
    'horror' => 'Vampires, ghosts, doubles and haunted houses: the Gothic tradition.',
    'romance' => 'Courtship, pride, scandal and the long road to a happy ending.',
    'historical' => 'Musketeers, Crusaders, revolutions and the frontier.',
    'children' => 'Rabbit holes, jungles, secret gardens and Neverland, for readers of every age.',
    'humour' => 'Satire, nonsense and comedy that still lands a century later.',
    'poetry-drama' => 'Plays and poems from Shakespeare to Wilde.',
    'ideas' => 'Philosophy, politics, science and the essays that changed minds.',
    'travel' => 'Journeys, expeditions and the natural world described by those who saw it first.',
];

$h = static fn(string $s): int => (int)hexdec(substr(hash('sha256', $s), 0, 8));
$money = static fn(int $cents): string => number_format($cents / 100, 2, '.', '');
$century = static function (int $year): string {
    $c = intdiv($year - 1, 100) + 1;
    $suffix = in_array($c % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$c % 10] ?? 'th');
    return $year < 0 ? 'Antiquity' : $c . $suffix . ' century';
};

$specStmt = $db->prepare('INSERT INTO product_specifications (product_id, lang, criteria_name, criteria_value, numeric_val, sort_order)
    VALUES (:pid, :lang, :name, :value, :num, :sort)');
$variantStmt = $db->prepare('INSERT INTO variants (product_id, sku, name, attrs, price_absolute, stock, sort_order, digital)
    VALUES (:pid, :sku, :name, :attrs, :price, 0, :sort, :digital)');
$assetStmt = $db->prepare('INSERT INTO digital_assets (sku, label, storage_path, download_name, content_type, download_limit, expiry_seconds)
    VALUES (:sku, :label, :path, :name, :type, 5, 2592000)');
// Categories have no description column: the theme reads the genre blurb from meta.description.
$categoryStmt = $db->prepare('UPDATE categories SET sort_order = :sort, meta = :meta WHERE id = :id');

$createdStmt = $db->prepare('UPDATE products SET created_at = :at WHERE id = :id');
$arrivals = gmmktime(12, 0, 0, 10, 1, 2026);

$start = microtime(true);
$count = 0;
$variants = 0;
$slugs = [];
$db->beginTransaction();
try {
    $sort = 0;
    foreach ($genres as $genre => $genreName) {
        $books = require __DIR__ . "/data/{$genre}.php";
        $categoryId = Import::ensureCategory($genreName, $db);
        $categoryStmt->execute([':sort' => $sort++, ':meta' => json_encode(['description' => $descriptions[$genre]]), ':id' => $categoryId]);

        foreach ($books as [$title, $author, $year, $pages, $language, $text]) {
            $count++;
            $sku = sprintf('BK-%04d', $count);
            $slug = Import::slugify($title . ' ' . $author);
            if (isset($slugs[$slug])) throw new RuntimeException("Duplicate book: {$title} by {$author}");
            $slugs[$slug] = true;
            $hash = $h($slug);

            // Prices in cents, all ending in 99; print prices follow the page count.
            $ebook = 299 + ($hash % 4) * 100;
            $paperback = 799 + (int)(min(900, (int)round($pages * 1.6)) / 100) * 100 + 100 * ($hash % 2);
            $hardcover = $paperback + 1000 + 100 * (($hash >> 3) % 3);
            $paperStock = $hash % 11 === 0 ? 0 : 3 + ($hash >> 4) % 38;
            // Not every title has a hardcover edition (about a third has none); some of those that do are sold out.
            $hasHardcover = ($hash >> 9) % 3 !== 0;
            $hardStock = $hash % 5 === 0 ? 0 : 1 + ($hash >> 6) % 12;
            $paperWeight = 40 + (int)round($pages * 1.1);

            $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $text) ?: [])));
            $summary = preg_match('/^(.+?[.!?])(\s|$)/u', $paragraphs[0], $m) ? $m[1] : $paragraphs[0];
            $description = implode('', array_map(static fn(string $p): string => '<p>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</p>', $paragraphs))
                . '<p><em>First published ' . ($year < 0 ? 'c. ' . abs($year) . ' BC' : $year) . '. '
                . ($language === 'English' ? '' : 'Translated from the ' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') . '. ')
                . 'Print edition about ' . $pages . ' pages. The eBook is an EPUB download available right after payment.</em></p>';

            file_put_contents("{$coverDir}/{$slug}.svg", BookshopCover::svg($slug, $title, $author, $genre, $genreName));
            $gutenberg = 'https://www.gutenberg.org/ebooks/search/?query=' . rawurlencode($title . ' ' . $author);
            file_put_contents("{$epubDir}/{$slug}.epub", BookshopEpubWriter::build(['title' => $title, 'author' => $author, 'year' => $year,
                'description' => $text, 'slug' => $slug, 'gutenberg_url' => $gutenberg], $shopName));

            $tags = [$genre, Import::slugify($author)];
            if ($hash % 7 === 0) $tags[] = 'bestseller';
            Import::importProduct([
                'sku' => $sku, 'name' => $title, 'slug' => $slug, 'slug_en' => $slug, 'name_en' => $title,
                'product_type' => 'simple', 'category' => $genreName, 'price' => $money($ebook), 'stock' => '',
                'summary' => $summary, 'description' => $description, 'image' => "/uploads/bookshop/covers/{$slug}.svg",
                'tags' => implode('|', $tags), 'weight_g' => (string)$paperWeight, 'attr:Author' => $author,
            ], $db);
            $productId = (int)$db->query('SELECT id FROM products WHERE sku = ' . $db->quote($sku))->fetchColumn();
            // Spread the arrival dates over four months so "New arrivals" mixes the genres.
            $createdStmt->execute([':at' => gmdate('Y-m-d H:i:s', $arrivals - ($hash % 120) * 86400 - $count * 60), ':id' => $productId]);

            $formats = [['EB', 'eBook', $ebook, 1, 0], ['PB', 'Paperback', $paperback, 0, $paperStock]];
            if ($hasHardcover) $formats[] = ['HB', 'Hardcover', $hardcover, 0, $hardStock];
            $specs = [['Author', $author, null], ['Century', $century($year), (float)$year], ['Original language', $language, null]];
            foreach ($formats as [, $format]) $specs[] = ['Format', $format, null];
            foreach ($specs as $i => [$name, $value, $numeric]) {
                $specStmt->execute([':pid' => $productId, ':lang' => 'en', ':name' => $name, ':value' => $value, ':num' => $numeric, ':sort' => $i]);
            }

            // Shipping weighs a print copy by the product's weight (the paperback's); the eBook weighs nothing.
            foreach ($formats as $i => [$code, $format, $price, $digital, $stock]) {
                $variants++;
                $variantSku = "{$sku}-{$code}";
                $variantStmt->execute([':pid' => $productId, ':sku' => $variantSku, ':name' => $format,
                    ':attrs' => json_encode(['format' => $format]), ':price' => $price, ':sort' => $i, ':digital' => $digital]);
                if ($digital) {
                    $assetStmt->execute([':sku' => $variantSku, ':label' => 'EPUB', ':path' => "{$catalog}/bookshop/{$slug}.epub",
                        ':name' => "{$slug}.epub", ':type' => 'application/epub+zip']);
                } else {
                    Warehouse::setStock((int)$warehouse['id'], $variantSku, $stock, 'count', 'bookshop-demo', "bookshop-opening-{$variantSku}");
                }
            }
        }
        echo sprintf("  %-20s %3d titles\n", $genreName, count($books));
    }
    // Warehouse::setStock books the ledger only; the variant rows mirror it for the cart and the product page.
    Warehouse::syncProductStock();
    Import::recountCategories($db);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

echo sprintf("Done: %d books, %d variants in %d ms.\n", $count, $variants, (int)round((microtime(true) - $start) * 1000));
