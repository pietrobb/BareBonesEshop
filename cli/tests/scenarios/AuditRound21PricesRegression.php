<?php

// Review round 21 (2026-11-07), medium "import prices" (a v2.1.3 release condition): one separator before three digits
// was still guessed — grouped in CZK/HUF ("199.000" → 199 000 Kč next to "1299.000" → 1 299 Kč in the same file), read
// as decimals in EUR/PLN ("2.000" → 2,00 €, a German thousands separator cheapened products 1000×). A feed item whose
// price was refused (four decimals "12.3456") lost its stock update even in stock-only mode, the 100-error limit then
// stopped the sync (51 of 400 items) and the feed status still said "ok".
echo "\n--- R21: '199.000' is refused in every currency, a feed declares its decimal mark, a refused price keeps the stock ---\n";

$r21pTry = static function (callable $parse): string {
    try {
        return (string)$parse();
    } catch (InvalidArgumentException $e) {
        return str_starts_with($e->getMessage(), 'Not a price') ? 'refused' : $e->getMessage();
    }
};
$r21pAuto = [];
foreach (['199.000', '2.000', '12.900', '1.234', '12,990 Ft', '1.234 Kč', '2.000 zł', '12.3456', '0.125', '1299.000', '0.500',
    '1 299,00', '1.299,00', '1,299.00', '12,90', '1299', '1.234.567'] as $r21pRaw) {
    $r21pAuto[$r21pRaw] = $r21pTry(static fn(): int => Import::parsePriceToCents($r21pRaw));
}
assert_equals(['199.000' => 'refused', '2.000' => 'refused', '12.900' => 'refused', '1.234' => 'refused', '12,990 Ft' => 'refused',
    '1.234 Kč' => 'refused', '2.000 zł' => 'refused', '12.3456' => 'refused', '0.125' => 'refused', '1299.000' => '129900', '0.500' => '50',
    '1 299,00' => '129900', '1.299,00' => '129900', '1,299.00' => '129900', '12,90' => '1290', '1299' => '129900', '1.234.567' => '123456700'],
    $r21pAuto, 'R21 prices: one separator before exactly three digits is refused in every currency; unambiguous prices are read');
assert_equals([-1, -1, -1], [Money::parseInput('199.000', 'czk'), Money::parseInput('2.000', 'eur'), Money::parseInput('12,990', 'huf')],
    'R21 prices: the admin price field refuses the same values');
$r21pDeclared = [];
foreach ([['12.500', '.'], ['12.500', ','], ['1,299.500', '.'], ['1.299,5', ','], ['2.000', ','], ['199.000 Kč', '.'], ['12.3456', '.'],
    ['1.299,00', '.'], ['1,5', '.']] as [$r21pRaw, $r21pMark]) {
    $r21pDeclared["{$r21pRaw} {$r21pMark}"] = $r21pTry(static fn(): int => Import::parsePriceToCents($r21pRaw, $r21pMark));
}
assert_equals(['12.500 .' => '1250', '12.500 ,' => '1250000', '1,299.500 .' => '129950', '1.299,5 ,' => '129950', '2.000 ,' => '200000',
    '199.000 Kč .' => '19900', '12.3456 .' => 'refused', '1.299,00 .' => 'refused', '1,5 .' => 'refused'], $r21pDeclared,
    'R21 prices: with a declared decimal mark three decimals are read without guessing; a finer part or the wrong format is refused');

[$r21pSavedDb, $r21pSavedDriver] = [Db::get(), Db::driver()];
$r21pStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r21pSavedStores = $r21pStoresProp->getValue();
$r21pPrevious = StoreContext::current();
$r21pLock = sys_get_temp_dir() . '/bbe-r21p-' . getmypid() . '.lock';
$r21pDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    (new Migrator($r21pDb, TEST_CLI_DIR . '/../migrations', $r21pLock))->apply(static fn($m) => null);
    Db::init($r21pDb, 'sqlite');
    $r21pSample = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    $r21pSample['stores']['hu']['catalog_scope'] = 'hu';
    $r21pStoresProp->setValue(null, $r21pSample);
    $r21pPrice = static fn(string $sku): string => (string)($r21pDb->query('SELECT price FROM products WHERE sku = ' . $r21pDb->quote($sku))->fetchColumn());

    // A CSV in the HUF catalog: "199.000" is refused (it was 199 000 Ft), "1299.000" next to it is 1 299 Ft.
    StoreContext::activate(StoreContext::resolve($r21pSample, null, null, 'hu', 'storefront'));
    $r21pCsv = tempnam(sys_get_temp_dir(), 'r21p') . '.csv';
    file_put_contents($r21pCsv, "sku,name,price,category\nR21P-HUF-A,R21 A,199.000,R21P\nR21P-HUF-B,R21 B,1299.000,R21P\n");
    $r21pHuCsv = Import::csv($r21pCsv);
    // The EUR catalog: a German "2.000" is refused (it was 2,00 €).
    StoreContext::activate(StoreContext::resolve($r21pSample, null, null, 'default', 'storefront'));
    file_put_contents($r21pCsv, "sku,name,price,category\nR21P-EUR-A,R21 C,2.000,R21P\nR21P-EUR-B,R21 D,\"2.000,00\",R21P\n");
    $r21pEuCsv = Import::csv($r21pCsv);
    @unlink($r21pCsv);
    assert_equals([1, 'Line 2 (SKU: R21P-HUF-A): Not a price: 199.000', '', '129900', 1, 'Line 2 (SKU: R21P-EUR-A): Not a price: 2.000', '', '200000'],
        [$r21pHuCsv['imported'], preg_replace('/ \(ambiguous.*$/', '', $r21pHuCsv['errors'][0] ?? ''), $r21pPrice('R21P-HUF-A'), $r21pPrice('R21P-HUF-B'),
            $r21pEuCsv['imported'], preg_replace('/ \(ambiguous.*$/', '', $r21pEuCsv['errors'][0] ?? ''), $r21pPrice('R21P-EUR-A'), $r21pPrice('R21P-EUR-B')],
        'R21 import: "199.000" (HUF) and "2.000" (EUR) are a row error and store nothing; "1299.000" and "2.000,00" are read');

    // 400 products of an ERP feed, then the same feed with four-decimal prices.
    $r21pWh = Warehouse::save(['code' => 'r21p-erp', 'name' => 'R21P ERP', 'is_default' => 0]);
    $r21pFeed = static function (string $code, string $mode, $price) use ($r21pWh): array {
        return Warehouse::feedById(Warehouse::saveFeed(['code' => $code, 'name' => $code, 'warehouse_id' => $r21pWh, 'url' => 'https://example.com/r21p.xml',
            'format' => 'xml', 'import_mode' => $mode, 'field_map' => ['SKU' => 'sku', 'NAME' => 'name', 'PRICE' => $price, 'STOCK' => 'stock']]));
    };
    $r21pItems = static function (string $price, int $stock): array {
        $items = [];
        for ($n = 0; $n < 400; $n++) $items[] = ['SKU' => sprintf('R21P-F%03d', $n), 'NAME' => "R21P {$n}", 'PRICE' => $price, 'STOCK' => (string)($stock + $n % 3)];
        return $items;
    };
    $r21pUpsert = $r21pFeed('r21p-upsert', FeedImport::MODE_UPSERT, 'price');
    $r21pFirst = FeedImport::applyItems($r21pUpsert, $r21pItems('10.00', 1));
    $r21pStock = static fn(): int => (int)$r21pDb->query("SELECT SUM(qty) FROM warehouse_stock WHERE warehouse_id = {$r21pWh} AND sku LIKE 'R21P-F%'")->fetchColumn();
    $r21pStatus = static fn(array $feed): string => (string)Warehouse::feedById((int)$feed['id'])['last_status'];
    assert_equals([400, [], 'ok', 400 * 1 + 133 + 2 * 133], [$r21pFirst['imported'], $r21pFirst['errors'], $r21pStatus($r21pUpsert), $r21pStock()],
        'R21 feed: the clean first sync imports all 400 products');

    // price_stock: every price is refused, every stock is still booked, the sync is not cut short and is not "ok".
    $r21pPriceStock = $r21pFeed('r21p-price-stock', FeedImport::MODE_PRICE_STOCK, ['field' => 'price', 'transform' => 'price_to_cents']);
    $r21pFour = FeedImport::applyItems($r21pPriceStock, $r21pItems('12.3456', 5));
    assert_equals([400, 400, 400, false, 'partial: 400 errors (400 prices refused)', 400 * 5 + 133 + 2 * 133, '1000'],
        [$r21pFour['imported'], count($r21pFour['errors']), $r21pFour['prices_refused'] ?? 0, $r21pFour['incomplete'] ?? null, $r21pStatus($r21pPriceStock), $r21pStock(),
            $r21pPrice('R21P-F399')],
        'R21 feed: 400 four-decimal prices are refused, yet the stock of all 400 items is synced and the products keep their price (was 51)');
    assert_true(str_starts_with($r21pFour['errors'][0], 'Item #0 (SKU R21P-F000): price refused, price kept: Not a price: 12.3456'),
        'R21 feed: the error names the item, the SKU and the refused value (' . $r21pFour['errors'][0] . ')');

    // stock_only never reads the price: no error at all.
    $r21pStockOnly = $r21pFeed('r21p-stock-only', FeedImport::MODE_STOCK_ONLY, ['field' => 'price', 'transform' => 'price_to_cents']);
    $r21pOnly = FeedImport::applyItems($r21pStockOnly, $r21pItems('12.3456', 7));
    assert_equals([400, [], 'ok', 400 * 7 + 133 + 2 * 133], [$r21pOnly['imported'], $r21pOnly['errors'], $r21pStatus($r21pStockOnly), $r21pStock()],
        'R21 feed: a stock-only sync of four-decimal prices updates every stock without an error');

    // upsert: an existing product keeps its price and gets its stock; a new one with a refused price is not created.
    $r21pMixed = FeedImport::applyItems($r21pUpsert, [['SKU' => 'R21P-F000', 'NAME' => 'R21P 0', 'PRICE' => '199.000', 'STOCK' => '9'],
        ['SKU' => 'R21P-NEW', 'NAME' => 'R21P new', 'PRICE' => '199.000', 'STOCK' => '9']]);
    assert_equals([1, 2, '1000', '', 9], [$r21pMixed['imported'], $r21pMixed['prices_refused'] ?? 0, $r21pPrice('R21P-F000'), $r21pPrice('R21P-NEW'),
        (int)$r21pDb->query("SELECT qty FROM warehouse_stock WHERE warehouse_id = {$r21pWh} AND sku = 'R21P-F000'")->fetchColumn()],
        'R21 feed upsert: an ambiguous price keeps the existing product\'s price and books its stock; a new product is not created');
    assert_true(str_contains($r21pMixed['errors'][1] ?? '', 'price refused, product not created'), 'R21 feed upsert: the error says the new product was not created');

    // A feed that declares its decimal mark reads "12.500" as 12,50 without an error.
    $r21pDot = $r21pFeed('r21p-dot', FeedImport::MODE_PRICE_STOCK, ['field' => 'price', 'transform' => 'price_to_cents', 'decimal' => '.']);
    $r21pDotRun = FeedImport::applyItems($r21pDot, [['SKU' => 'R21P-F001', 'NAME' => 'R21P 1', 'PRICE' => '12.500', 'STOCK' => '2']]);
    assert_equals([[], '1250'], [$r21pDotRun['errors'], $r21pPrice('R21P-F001')], 'R21 feed: "decimal": "." reads an ERP "12.500" as 12,50');

    // Item errors that are not prices still stop the sync after 100, and the status says so.
    $r21pBad = [];
    for ($n = 0; $n < 102; $n++) $r21pBad[] = ['SKU' => "R21P-BAD-{$n}", 'NAME' => '', 'PRICE' => '1.00', 'STOCK' => '1'];
    $r21pCut = FeedImport::applyItems($r21pUpsert, $r21pBad);
    assert_equals([101, true, 'stopped after 101 errors'], [count($r21pCut['errors']), $r21pCut['incomplete'] ?? null, $r21pStatus($r21pUpsert)],
        'R21 feed: 101 item errors stop the sync, reported as stopped (it said "ok with 101 errors")');
} finally {
    Db::init($r21pSavedDb, $r21pSavedDriver);
    @unlink($r21pLock);
    $r21pStoresProp->setValue(null, $r21pSavedStores);
    StoreContext::activate($r21pPrevious);
}
