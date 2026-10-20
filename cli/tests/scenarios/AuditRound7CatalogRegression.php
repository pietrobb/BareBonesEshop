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
