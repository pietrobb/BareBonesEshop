<?php

// Round 7 review (2026-10-20), catalog and import. A feed in upsert mode booked its stock twice (the product import
// adjusted the default warehouse, then the feed warehouse was set as well), and every later sync failed the stock
// row, so availability stayed doubled and never went down (overselling). A product that disappears from the feed kept
// its last count for ever. Feed and CSV prices with thousands separators or currency signs were read as 1.00 / 0.
echo "\n--- Audit round 7 (catalog): feed stock, missing products, price parsing ---\n";
[$r7cSavedDb, $r7cSavedDriver] = [Db::get(), Db::driver()];
$r7cLock = sys_get_temp_dir() . '/bbe-r7c-' . getmypid() . '.lock';
$r7cDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    (new Migrator($r7cDb, TEST_CLI_DIR . '/../migrations', $r7cLock))->apply(static fn($m) => null);
    Db::init($r7cDb, 'sqlite');
    $r7cMain = Warehouse::save(['code' => 'r7c-main', 'name' => 'R7C main', 'is_default' => 1]);
    $r7cFeedWh = Warehouse::save(['code' => 'r7c-supplier', 'name' => 'R7C supplier', 'is_default' => 0]);
    $r7cFeedId = Warehouse::saveFeed(['code' => 'r7c-feed', 'name' => 'R7C feed', 'warehouse_id' => $r7cFeedWh, 'url' => 'https://example.com/feed.xml',
        'format' => 'xml', 'import_mode' => FeedImport::MODE_UPSERT,
        'field_map' => ['SKU' => 'sku', 'NAME' => 'name', 'PRICE' => 'price', 'STOCK' => 'stock']]);
    $r7cFeed = Warehouse::feedById($r7cFeedId);
    $r7cQty = static fn(int $wh, string $sku): ?int => ($v = $r7cDb->query("SELECT qty FROM warehouse_stock WHERE warehouse_id = {$wh} AND sku = " . $r7cDb->quote($sku))->fetchColumn()) === false ? null : (int)$v;
    $r7cStock = static fn(string $sku): int => (int)$r7cDb->query('SELECT stock FROM products WHERE sku = ' . $r7cDb->quote($sku))->fetchColumn();

    $r7cFirst = FeedImport::applyItems($r7cFeed, [
        ['SKU' => 'R7C-A', 'NAME' => 'R7C A', 'PRICE' => '10.00', 'STOCK' => '10'],
        ['SKU' => 'R7C-B', 'NAME' => 'R7C B', 'PRICE' => '5.00', 'STOCK' => '4'],
    ]);
    assert_equals(10, Warehouse::totalAvailable('R7C-A'), 'R7 feed upsert: a new product is stocked once, with the feed count (' . json_encode($r7cFirst['errors']) . ')');
    assert_true($r7cQty($r7cMain, 'R7C-A') === null || $r7cQty($r7cMain, 'R7C-A') === 0, 'R7 feed upsert: the feed stock is not booked into the default warehouse too');
    assert_equals(10, $r7cStock('R7C-A'), 'R7 feed upsert: products.stock shows the feed count');

    $r7cSecond = FeedImport::applyItems($r7cFeed, [
        ['SKU' => 'R7C-A', 'NAME' => 'R7C A', 'PRICE' => '10.00', 'STOCK' => '3'],
        ['SKU' => 'R7C-B', 'NAME' => 'R7C B', 'PRICE' => '5.00', 'STOCK' => '4'],
    ]);
    assert_true($r7cSecond['errors'] === [] && Warehouse::totalAvailable('R7C-A') === 3 && $r7cStock('R7C-A') === 3,
        'R7 feed upsert: a lower count on the next sync lowers availability (' . Warehouse::totalAvailable('R7C-A') . ', ' . json_encode($r7cSecond['errors']) . ')');

    // A product the supplier no longer lists has nothing in the feed warehouse; reservations stay covered.
    Warehouse::reserve('R7C-B', 1);
    $r7cThird = FeedImport::applyItems($r7cFeed, [['SKU' => 'R7C-A', 'NAME' => 'R7C A', 'PRICE' => '10.00', 'STOCK' => '3']]);
    assert_equals(1, $r7cQty($r7cFeedWh, 'R7C-B'), 'R7 feed: a product missing from the feed drops to its reserved units in the feed warehouse');
    assert_equals(0, Warehouse::totalAvailable('R7C-B'), 'R7 feed: a product missing from the feed is no longer sellable');
    assert_equals(1, (int)($r7cThird['stock_zeroed'] ?? 0), 'R7 feed: the sync result counts the products it zeroed');
    // An empty or broken download zeroes nothing.
    FeedImport::applyItems($r7cFeed, []);
    assert_equals(3, Warehouse::totalAvailable('R7C-A'), 'R7 feed: an empty feed leaves the stock alone');
    // A feed that does not map stock never zeroes anything.
    $r7cPriceFeed = $r7cFeed;
    $r7cPriceFeed['field_map'] = json_encode(['SKU' => 'sku', 'NAME' => 'name', 'PRICE' => 'price']);
    FeedImport::applyItems($r7cPriceFeed, [['SKU' => 'R7C-B', 'NAME' => 'R7C B', 'PRICE' => '6.00']]);
    assert_equals(3, Warehouse::totalAvailable('R7C-A'), 'R7 feed: a feed without a stock column does not zero the products it omits');
    // A sync that stops at the error limit never saw the rest of the feed: those products are still listed.
    $r7cBadRows = [];
    for ($n = 0; $n < 102; $n++) $r7cBadRows[] = ['SKU' => "R7C-BAD-{$n}", 'NAME' => 'R7C bad', 'PRICE' => 'na dotaz', 'STOCK' => '1'];
    $r7cCut = FeedImport::applyItems($r7cFeed, [...$r7cBadRows, ['SKU' => 'R7C-A', 'NAME' => 'R7C A', 'PRICE' => '10.00', 'STOCK' => '3']]);
    assert_true(count($r7cCut['errors']) === 101 && Warehouse::totalAvailable('R7C-A') === 3 && (int)($r7cCut['stock_zeroed'] ?? 0) === 0,
        'R7 feed: a sync cut short by the error limit zeroes nothing (errors ' . count($r7cCut['errors']) . ', R7C-A ' . Warehouse::totalAvailable('R7C-A') . ', zeroed ' . (int)($r7cCut['stock_zeroed'] ?? 0) . ')');

    // Prices: thousands separators, currency signs and codes.
    $r7cPrices = [];
    foreach (['1 299,00', '1.299,00', '1,299.00', '€24.90', '24,90 €', 'EUR 24.90', '24.90 EUR', '1299', "1\u{00A0}299,50", '0,99', '19.9', '1 299 Kč'] as $raw) {
        $r7cPrices[$raw] = Import::parsePriceToCents($raw);
    }
    assert_equals(['1 299,00' => 129900, '1.299,00' => 129900, '1,299.00' => 129900, '€24.90' => 2490, '24,90 €' => 2490, 'EUR 24.90' => 2490,
        '24.90 EUR' => 2490, '1299' => 129900, "1\u{00A0}299,50" => 129950, '0,99' => 99, '19.9' => 1990, '1 299 Kč' => 129900], $r7cPrices,
        'R7 prices: thousands separators, currency signs and codes are parsed to the right cents');
    $r7cBad = [];
    foreach (['abc', '12abc', '1.2.3,4,5'] as $raw) {
        try { Import::parsePriceToCents($raw); $r7cBad[] = $raw; } catch (InvalidArgumentException) {}
    }
    assert_equals([], $r7cBad, 'R7 prices: a value that is not a price is refused instead of read as 0');
    FeedImport::applyItems($r7cFeed, [['SKU' => 'R7C-A', 'NAME' => 'R7C A', 'PRICE' => '1 299,00', 'STOCK' => '3']]);
    assert_equals(129900, (int)$r7cDb->query("SELECT price FROM products WHERE sku = 'R7C-A'")->fetchColumn(), 'R7 prices: a feed price "1 299,00" is stored as 1299.00');
    $r7cTransformFeed = $r7cFeed;
    $r7cTransformFeed['field_map'] = json_encode(['SKU' => 'sku', 'NAME' => 'name', 'PRICE' => ['field' => 'price', 'transform' => 'price_to_cents'], 'STOCK' => 'stock']);
    FeedImport::applyItems($r7cTransformFeed, [['SKU' => 'R7C-A', 'NAME' => 'R7C A', 'PRICE' => '€1.299,90', 'STOCK' => '3']]);
    assert_equals(129990, (int)$r7cDb->query("SELECT price FROM products WHERE sku = 'R7C-A'")->fetchColumn(), 'R7 prices: the price_to_cents feed transform reads "€1.299,90" as 1299.90');
} finally {
    Db::init($r7cSavedDb, $r7cSavedDriver);
    @unlink($r7cLock);
}

// MySQL full-text search: two-letter words ("TV") and SKUs with a hyphen ("AB-12") are below the index's token size,
// so they found nothing, and several words were OR-ed (natural language mode) where SQLite requires every word.
echo "\n--- Audit round 7 (catalog): full-text search parity ---\n";
$r7cSearchDbs = ['sqlite' => null];
if (getenv('TEST_MYSQL_DSN')) $r7cSearchDbs['mysql'] = 'bbe_r7c_search_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit R7 MySQL full-text search (set TEST_MYSQL_DSN)\n";
foreach ($r7cSearchDbs as $r7cDriver => $r7cName) {
    $r7cSearchDb = $r7cDriver === 'mysql'
        ? new PDO((string)getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
        : new PDO('sqlite::memory:');
    $r7cSearchDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r7cSearchDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    try {
        if ($r7cName !== null) { $r7cSearchDb->exec("CREATE DATABASE `{$r7cName}`"); $r7cSearchDb->exec("USE `{$r7cName}`"); }
        (new Migrator($r7cSearchDb, TEST_CLI_DIR . '/../migrations', $r7cLock))->apply(static fn($m) => null);
        Db::init($r7cSearchDb, $r7cDriver);
        $r7cSearchDb->exec("INSERT INTO categories (store_id, name, slug) VALUES ('default', 'R7C', 'r7c')");
        $r7cCat = (int)$r7cSearchDb->lastInsertId();
        $r7cInsert = $r7cSearchDb->prepare("INSERT INTO products (store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, summary, tags)
            VALUES ('default', ?, ?, ?, ?, 'simple', 1000, 'eur', 'active', 5, '', '[]')");
        foreach ([['Samsung TV 55', 'SAM-TV55'], ['Wall bracket', 'AB-12'], ['Red cotton shirt', 'SH-RED'], ['Red wine glass', 'GL-RED'], ['Blue cotton shirt', 'SH-BLUE']] as [$r7cName2, $r7cSku]) {
            $r7cInsert->execute([$r7cCat, $r7cName2, strtolower($r7cSku), $r7cSku]);
        }
        $r7cFound = static function (string $q): array {
            $skus = array_map(static fn(array $p): string => (string)$p['sku'], Search::query($q)['products']);
            sort($skus);
            return $skus;
        };
        assert_equals(['SAM-TV55'], $r7cFound('TV'), "R7 search {$r7cDriver}: a two-letter word finds the product");
        assert_equals(['AB-12'], $r7cFound('AB-12'), "R7 search {$r7cDriver}: a SKU with a hyphen finds the product");
        assert_equals(['SH-RED'], $r7cFound('red shirt'), "R7 search {$r7cDriver}: every word of a multi-word query must match");
        assert_equals(['SH-BLUE', 'SH-RED'], $r7cFound('cotton shi'), "R7 search {$r7cDriver}: the last word matches as a prefix");
        assert_equals(['SAM-TV55'], $r7cFound('samsung tv'), "R7 search {$r7cDriver}: an indexed word and a short word combine");
        assert_equals(['Wall bracket'], array_map(static fn(array $r): string => (string)$r['name'], Search::suggest('AB-12')), "R7 search {$r7cDriver}: suggestions find a SKU with a hyphen");
    } finally {
        Db::init($r7cSavedDb, $r7cSavedDriver);
        if ($r7cName !== null) $r7cSearchDb->exec("DROP DATABASE IF EXISTS `{$r7cName}`");
        @unlink($r7cLock);
    }
}

// Magento migration: the table prefix was a hard-coded default of one shop's dump, so any other dump imported nothing
// and reported success; migrated products had products.stock but no warehouse stock (shown in stock, not buyable);
// image paths were stored as "uploads/magento/..." which the storefront resolved to a missing file.
echo "\n--- Audit round 7 (catalog): Magento migration ---\n";
$r7cMage = test_fixture_path('r7c-magento');
mkdir($r7cMage);
try {
    file_put_contents($r7cMage . '/dump.sql', implode("\n", [
        '-- Magento 1.9 dump with table prefix mg_',
        "INSERT INTO `mg_catalog_product_entity` VALUES (1,4,4,'simple','MG-CHAIR',0,0,'2020-01-01 00:00:00','2020-01-01 00:00:00');",
        "INSERT INTO `mg_catalog_product_entity_varchar` VALUES (1,4,71,0,1,'Magento chair'),(2,4,97,0,1,'magento-chair'),(3,4,85,0,1,'/m/g/chair.jpg');",
        "INSERT INTO `mg_catalog_product_entity_decimal` VALUES (1,4,75,0,1,'49.9000');",
        "INSERT INTO `mg_catalog_product_entity_int` VALUES (1,4,96,0,1,1);",
        "INSERT INTO `mg_cataloginventory_stock_item` VALUES (1,1,1,'7.0000',0,1,0,0,1,1,1,0,1,1,NULL,NULL,1,0,1,0,1,0,1,0,0);",
    ]) . "\n");
    file_put_contents($r7cMage . '/run.php', '<?php
const R7C_ROOT = ' . var_export(dirname(TEST_CLI_DIR), true) . ';
const R7C_DB = ' . var_export($r7cMage . '/shop.sqlite', true) . ';
require R7C_ROOT . "/src/autoload.php";
@unlink(R7C_DB);
$db = new PDO("sqlite:" . R7C_DB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new Migrator($db, R7C_ROOT . "/migrations", R7C_DB . ".lock"))->apply(static fn($m) => null);
Db::init($db, "sqlite");
register_shutdown_function(static function () use ($db): void {
    $product = $db->query("SELECT stock, images FROM products WHERE sku = \'MG-CHAIR\'")->fetch() ?: null;
    echo "\nRESULT=" . json_encode([$product === null ? null : (int)$product["stock"], Warehouse::totalAvailable("MG-CHAIR"), $product === null ? null : json_decode($product["images"], true)]) . "\n";
});
require R7C_ROOT . "/cli/migrate-magento.php";
');
    $r7cRun = static function (array $args) use ($r7cMage): array {
        $process = proc_open(array_merge([PHP_BINARY, $r7cMage . '/run.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        return [$code, preg_match('/RESULT=(.*)$/m', $out, $m) ? json_decode($m[1], true) : null, $out];
    };
    [$r7cCode, $r7cResult, $r7cOut] = $r7cRun(['--dump=' . $r7cMage . '/dump.sql']);
    assert_true($r7cCode === 0 && str_contains($r7cOut, "Detected table prefix: 'mg_'") && ($r7cResult[0] ?? null) === 7,
        'R7 Magento: without --prefix the dump\'s table prefix is detected and its products imported (' . $r7cCode . ', ' . json_encode($r7cResult) . ')');
    assert_equals(7, $r7cResult[1] ?? null, 'R7 Magento: the migrated stock is in the warehouse ledger, so the product can be bought');
    assert_equals(['/uploads/magento/m/g/chair.jpg'], $r7cResult[2] ?? null, 'R7 Magento: an image path is the storage URL the storefront serves');
    [$r7cCode, $r7cResult, $r7cOut] = $r7cRun(['--dump=' . $r7cMage . '/dump.sql', '--prefix=other_']);
    assert_true($r7cCode === 1 && str_contains($r7cOut, 'no Magento products found') && ($r7cResult[0] ?? null) === null,
        'R7 Magento: a wrong prefix fails loudly instead of importing nothing and reporting success (' . $r7cCode . ')');
} finally {
    foreach (glob($r7cMage . '/*') ?: [] as $r7cFile) @unlink($r7cFile);
    @rmdir($r7cMage);
}

// Images: http:// pictures are mixed content on the HTTPS storefront; cards loaded the full original although the
// resize job writes smaller copies; a public original kept the photo's EXIF, GPS position included.
echo "\n--- Audit round 7 (catalog): product images ---\n";
assert_equals('https://cdn.example.com/a.jpg', StorefrontViewModel::imageUrl('http://cdn.example.com/a.jpg'), 'R7 images: an http:// image is served over https');
assert_equals([['https://cdn.example.com/a.jpg'], []], ProductEditor::parseImages('http://cdn.example.com/a.jpg'), 'R7 images: the editor stores an http:// image as https');
$r7cUploads = dirname(TEST_CLI_DIR) . '/uploads/r7c-' . getmypid();
@mkdir($r7cUploads, 0755, true);
try {
    $r7cBase = '/uploads/' . basename($r7cUploads) . '/photo';
    file_put_contents($r7cUploads . '/photo.jpg', 'x');
    assert_equals(Template::url($r7cBase . '.jpg'), StorefrontViewModel::imageUrl($r7cBase . '.jpg', 'thumb'), 'R7 images: before the resize job ran, the thumbnail is the original');
    file_put_contents($r7cUploads . '/photo-medium.jpg', 'x');
    assert_equals(Template::url($r7cBase . '-medium.jpg'), StorefrontViewModel::imageUrl($r7cBase . '.jpg', 'thumb'), 'R7 images: a card thumbnail uses the resized copy instead of the full original');
    assert_equals(Template::url($r7cBase . '.jpg'), StorefrontViewModel::imageUrl($r7cBase . '.jpg', 'full'), 'R7 images: the full size stays the original');
} finally {
    foreach (glob($r7cUploads . '/*') ?: [] as $r7cFile) @unlink($r7cFile);
    @rmdir($r7cUploads);
}

// A JPEG with EXIF (GPS + orientation 6), XMP and a comment; a PNG with text and eXIf chunks; a WebP with EXIF.
$r7cTiff = 'MM' . "\0\x2A\0\0\0\x08" . "\0\x02" . "\x01\x12\0\x03\0\0\0\x01\0\x06\0\0" . "\x88\x25\0\x04\0\0\0\x01\0\0\0\x26" . "\0\0\0\0" . 'GPS 48.1486N 17.1077E';
$r7cSegment = static fn(int $marker, string $payload): string => "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
$r7cImage = imagecreatetruecolor(4, 4);
ob_start(); imagejpeg($r7cImage); $r7cPlainJpeg = (string)ob_get_clean();
$r7cJpeg = "\xFF\xD8" . $r7cSegment(0xE0, "JFIF\0\x01\x01\0\0\x01\0\x01\0\0") . $r7cSegment(0xE1, "Exif\0\0" . $r7cTiff)
    . $r7cSegment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>GPSLatitude</x:xmpmeta>") . $r7cSegment(0xFE, 'shot at home GPS')
    . substr($r7cPlainJpeg, 2);
$r7cStripped = ImageMetadata::strip($r7cJpeg);
assert_true(!str_contains($r7cStripped, 'GPS') && !str_contains($r7cStripped, 'xmpmeta'), 'R7 images: EXIF/GPS, XMP and comments are removed from an uploaded JPEG');
assert_true(str_contains($r7cStripped, "Exif\0\0MM") && str_contains($r7cStripped, "\x01\x12\0\x03\0\0\0\x01\0\x06"), 'R7 images: the JPEG keeps its orientation so it still displays upright');
assert_true(@imagecreatefromstring($r7cStripped) !== false && str_ends_with($r7cStripped, substr($r7cPlainJpeg, -64)), 'R7 images: the stripped JPEG still decodes and its image data is unchanged');
ob_start(); imagepng($r7cImage); $r7cPlainPng = (string)ob_get_clean();
$r7cChunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
$r7cPng = substr($r7cPlainPng, 0, 33) . $r7cChunk('tEXt', "Comment\0GPS 48.1N") . $r7cChunk('eXIf', $r7cTiff) . substr($r7cPlainPng, 33);
$r7cPngStripped = ImageMetadata::strip($r7cPng);
assert_true(!str_contains($r7cPngStripped, 'GPS') && @imagecreatefromstring($r7cPngStripped) !== false, 'R7 images: text and EXIF chunks are removed from a PNG, which still decodes');
if (function_exists('imagewebp')) {
    ob_start(); imagewebp($r7cImage); $r7cPlainWebp = (string)ob_get_clean();
    $r7cExif = 'EXIF' . pack('V', strlen($r7cTiff)) . $r7cTiff . (strlen($r7cTiff) % 2 ? "\0" : '');
    $r7cWebpBody = substr($r7cPlainWebp, 12) . $r7cExif;
    $r7cWebpStripped = ImageMetadata::strip('RIFF' . pack('V', strlen($r7cWebpBody) + 4) . 'WEBP' . $r7cWebpBody);
    assert_true(!str_contains($r7cWebpStripped, 'GPS') && unpack('V', substr($r7cWebpStripped, 4, 4))[1] === strlen($r7cWebpStripped) - 8,
        'R7 images: the EXIF chunk is removed from a WebP and the RIFF size stays consistent');
}
$r7cEditor = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/ProductEditor.php');
assert_true(str_contains($r7cEditor, 'ImageMetadata::strip($bytes)'), 'R7 images: an admin upload is stored without its metadata');
