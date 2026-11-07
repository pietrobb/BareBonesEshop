<?php

// Review round 20 (2026-11-07), medium "three decimals on import": the R19 rule reading "1.234" as 1 234 applied in every
// currency, so an ERP CSV or XML feed exporting three decimals stored "12.500" as 12 500,00 € (instead of 12,50 €) and
// "99.990" as 99 990 €, silently. R21 (AuditRound21Prices): such a value is refused in every currency instead of being
// guessed; a feed that declares its decimal mark reads it, in an EUR as in a HUF catalog.
echo "\n--- R20: an ERP's '12.500' is never stored as 12 500 €: refused, or 12,50 with a declared decimal mark ---\n";

$r20dRefused = static function (string $price, ?string $mark = null): string {
    try {
        return 'accepted ' . Import::parsePriceToCents($price, $mark);
    } catch (InvalidArgumentException $e) {
        return str_starts_with($e->getMessage(), 'Not a price') ? 'refused' : $e->getMessage();
    }
};
assert_equals(['refused', 'refused', 'refused', 'refused', 'accepted 1990', 'accepted 1250', 'accepted 9999', -1, -1],
    [$r20dRefused('12.500'), $r20dRefused('99.990'), $r20dRefused('0.125'), $r20dRefused('1.299,125'), $r20dRefused('19.900000'),
        $r20dRefused('12.500', '.'), $r20dRefused('99.990', '.'), Money::parseInput('1.234', 'eur'), Money::parseInput('0.125', 'czk')],
    'R20 import: three decimals are refused unless the decimal mark is declared, a part finer than a cent always, like the admin price field');

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
    $r20dXml = static fn(string $sku, string $price = '99.990'): string => '<?xml version="1.0" encoding="utf-8"?><SHOP><SHOPITEM><ITEM_ID>' . $sku . '</ITEM_ID>'
        . '<PRODUCTNAME>R20 feed</PRODUCTNAME><PRICE_VAT>' . $price . '</PRICE_VAT><CATEGORYTEXT>R20D</CATEGORYTEXT><STOCK>4</STOCK></SHOPITEM></SHOP>';
    $r20dErrors = [];
    foreach (['default' => 'eur', 'hu' => 'huf'] as $r20dStore => $r20dCurrency) {
        StoreContext::activate(StoreContext::resolve($r20dSample, null, null, $r20dStore, 'storefront'));
        $r20dWh = Warehouse::save(['code' => "r20d-erp-{$r20dStore}", 'name' => "R20D ERP {$r20dStore}", 'is_default' => 0]);
        foreach (['plain' => 'price', 'transform' => ['field' => 'price', 'transform' => 'price_to_cents'],
            'dot' => ['field' => 'price', 'transform' => 'price_to_cents', 'decimal' => '.']] as $r20dKind => $r20dPrice) {
            $r20dFeed = Warehouse::feedById(Warehouse::saveFeed(['code' => "r20d-{$r20dStore}-{$r20dKind}", 'name' => "R20D {$r20dKind}", 'warehouse_id' => $r20dWh,
                'url' => 'https://example.com/feed.xml', 'format' => 'xml', 'import_mode' => FeedImport::MODE_UPSERT,
                'field_map' => ['ITEM_ID' => 'sku', 'PRODUCTNAME' => 'name', 'PRICE_VAT' => $r20dPrice, 'CATEGORYTEXT' => 'category', 'STOCK' => 'stock']]));
            $r20dErrors["{$r20dCurrency} {$r20dKind}"] = count(FeedImport::importDocument($r20dFeed, $r20dXml(strtoupper("R20D-{$r20dCurrency}-{$r20dKind}")))['errors']);
        }
    }
    $r20dRows = array_map(static fn(array $r): string => $r['sku'] . ' ' . $r['currency'] . ' ' . $r['price'],
        $r20dDb->query("SELECT sku, currency, price FROM products WHERE sku LIKE 'R20D-%' ORDER BY sku")->fetchAll());
    assert_equals([['eur plain' => 1, 'eur transform' => 1, 'eur dot' => 0, 'huf plain' => 1, 'huf transform' => 1, 'huf dot' => 0],
        ['R20D-EUR-DOT eur 9999', 'R20D-HUF-DOT huf 9999']], [$r20dErrors, $r20dRows],
        'R20 import: an ERP feed "99.990" is an item error (no 99 990 €, no product) unless the feed declares "decimal": ".", then 99,99 in either catalog');
} finally {
    Db::init($r20dSavedDb, $r20dSavedDriver);
    @unlink($r20dLock);
    $r20dStoresProp->setValue(null, $r20dSavedStores);
    StoreContext::activate($r20dPrevious);
}
