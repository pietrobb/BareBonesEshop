<?php

// Review round 20 (2026-11-07), medium "three decimals on import": the R19 rule reading "1.234" as 1 234 applied in every
// currency, so an ERP CSV or XML feed exporting three decimals stored "12.500" as 12 500,00 € (instead of 12,50 €) and
// "99.990" as 99 990 €, silently. Digit grouping before three digits is now read only in a currency shown in whole
// units (CZK, HUF); in a currency with cents it is the decimal mark.
echo "\n--- R20: '12.500' is 12,50 in EUR, '1.234' is 1 234 only in CZK/HUF ---\n";

assert_equals([1250, 9999, 123, 1250, 12346, 13], [
    Import::parsePriceToCents('12.500', 'eur'), Import::parsePriceToCents('99.990', 'eur'), Import::parsePriceToCents('1.234', 'eur'),
    Import::parsePriceToCents('12,500', 'eur'), Import::parsePriceToCents('123.456 EUR', 'eur'), Import::parsePriceToCents('0.125', 'eur'),
], 'R20 import: in EUR a single separator before three digits is the decimal mark ("12.500" is 12,50, was 12 500,00)');
assert_equals([123400, 1250000, 1299000, 129900], [
    Import::parsePriceToCents('1.234', 'czk'), Import::parsePriceToCents('12.500', 'huf'), Import::parsePriceToCents('12,990 Ft', 'huf'),
    Import::parsePriceToCents('1.299,00', 'eur'),
], 'R20 import: in CZK/HUF it still groups digits ("1.234 Kč" is 1 234); both separators keep meaning the same in EUR');

[$r20dSavedDb, $r20dSavedDriver] = [Db::get(), Db::driver()];
$r20dStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r20dSavedStores = $r20dStoresProp->getValue();
$r20dPrevious = StoreContext::current();
$r20dLock = sys_get_temp_dir() . '/bbe-r20d-' . getmypid() . '.lock';
$r20dDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    (new Migrator($r20dDb, TEST_CLI_DIR . '/../migrations', $r20dLock))->apply(static fn($m) => null);
    Db::init($r20dDb, 'sqlite');
    $r20dSample = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    $r20dSample['stores']['hu']['catalog_scope'] = 'hu';
    $r20dStoresProp->setValue(null, $r20dSample);

    // The default store's EUR catalog: a CSV row and a Heureka-style XML feed from an ERP with three decimals.
    StoreContext::activate(StoreContext::resolve($r20dSample, null, null, 'default', 'storefront'));
    Import::importProduct(['sku' => 'R20D-EUR-CSV', 'name' => 'R20 EUR CSV', 'price' => '12.500', 'compare_price' => '99.990', 'stock' => '2', 'category' => 'R20D EUR']);
    $r20dWh = Warehouse::save(['code' => 'r20d-erp', 'name' => 'R20D ERP', 'is_default' => 0]);
    $r20dFeeds = [];
    foreach (['transform' => ['field' => 'price', 'transform' => 'price_to_cents'], 'plain' => 'price'] as $r20dKind => $r20dPrice) {
        $r20dFeeds[$r20dKind] = Warehouse::feedById(Warehouse::saveFeed(['code' => "r20d-{$r20dKind}", 'name' => "R20D {$r20dKind}", 'warehouse_id' => $r20dWh,
            'url' => 'https://example.com/feed.xml', 'format' => 'xml', 'import_mode' => FeedImport::MODE_UPSERT,
            'field_map' => ['ITEM_ID' => 'sku', 'PRODUCTNAME' => 'name', 'PRICE_VAT' => $r20dPrice, 'CATEGORYTEXT' => 'category', 'STOCK' => 'stock']]));
    }
    $r20dXml = static fn(string $sku): string => '<?xml version="1.0" encoding="utf-8"?><SHOP><SHOPITEM><ITEM_ID>' . $sku . '</ITEM_ID>'
        . '<PRODUCTNAME>R20 feed</PRODUCTNAME><PRICE_VAT>99.990</PRICE_VAT><CATEGORYTEXT>R20D EUR</CATEGORYTEXT><STOCK>4</STOCK></SHOPITEM></SHOP>';
    $r20dErrors = array_merge(FeedImport::importDocument($r20dFeeds['transform'], $r20dXml('R20D-EUR-FEED'))['errors'],
        FeedImport::importDocument($r20dFeeds['plain'], $r20dXml('R20D-EUR-FEED2'))['errors']);
    // An existing EUR product updated by the CSV import: the new price is read in the product's currency.
    Import::importProduct(['sku' => 'R20D-EUR-CSV', 'name' => 'R20 EUR CSV', 'price' => '24.900']);

    // The HU store with a catalog of its own (HUF): "12.500" there is 12 500 Ft.
    StoreContext::activate(StoreContext::resolve($r20dSample, null, null, 'hu', 'storefront'));
    Import::importProduct(['sku' => 'R20D-HUF-CSV', 'name' => 'R20 HUF CSV', 'price' => '12.500', 'stock' => '1', 'category' => 'R20D HUF']);
    // The same XML feed into the HUF catalog: "99.990" there is 99 990 Ft.
    $r20dHuFeed = Warehouse::feedById(Warehouse::saveFeed(['code' => 'r20d-hu', 'name' => 'R20D HU', 'warehouse_id' => Warehouse::save(['code' => 'r20d-erp-hu', 'name' => 'R20D ERP HU', 'is_default' => 0]),
        'url' => 'https://example.com/feed-hu.xml', 'format' => 'xml', 'import_mode' => FeedImport::MODE_UPSERT,
        'field_map' => ['ITEM_ID' => 'sku', 'PRODUCTNAME' => 'name', 'PRICE_VAT' => ['field' => 'price', 'transform' => 'price_to_cents'], 'CATEGORYTEXT' => 'category', 'STOCK' => 'stock']]));
    $r20dErrors = array_merge($r20dErrors, FeedImport::importDocument($r20dHuFeed, $r20dXml('R20D-HUF-FEED'))['errors']);

    $r20dRows = array_map(static fn(array $r): string => $r['sku'] . ' ' . $r['currency'] . ' ' . $r['price'] . ' ' . ($r['compare_price'] ?? '-'),
        $r20dDb->query("SELECT sku, currency, price, compare_price FROM products WHERE sku LIKE 'R20D-%' ORDER BY sku")->fetchAll());
    assert_equals(['R20D-EUR-CSV eur 2490 9999', 'R20D-EUR-FEED eur 9999 -', 'R20D-EUR-FEED2 eur 9999 -', 'R20D-HUF-CSV huf 1250000 -', 'R20D-HUF-FEED huf 9999000 -'], $r20dRows,
        'R20 import: an ERP CSV/XML feed "12.500"/"99.990" is 12,50/99,99 € (was 12 500/99 990 €), "12.500" in a HUF catalog is 12 500 Ft ('
        . json_encode($r20dErrors) . ')');
} finally {
    Db::init($r20dSavedDb, $r20dSavedDriver);
    @unlink($r20dLock);
    $r20dStoresProp->setValue(null, $r20dSavedStores);
    StoreContext::activate($r20dPrevious);
}
