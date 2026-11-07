<?php

// Review round 21 (2026-11-07), low "import and feeds": the admin saw only how many rows a CSV import or a feed sync
// refused, not which and why (a scheduled sync showed nothing); a feed rebuilt during a long sync kept the old prices
// for the 15-minute cache time once the sync committed (rows are stamped when written, the R18 marker skipped feeds);
// the ERP product sync stored any price it was sent ("abc", -5 on SQLite); the XML feed read prices by the catalog's
// currency, the CSV import by the product's.
echo "\n--- R21: refused rows are listed, a committed sync marks feeds stale, the ERP sync checks its prices ---\n";

[$r21lSavedDb, $r21lSavedDriver] = [Db::get(), Db::driver()];
$r21lStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r21lSavedStores = $r21lStoresProp->getValue();
$r21lPrevious = StoreContext::current();
$r21lLock = sys_get_temp_dir() . '/bbe-r21l-' . getmypid() . '.lock';
$r21lDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    (new Migrator($r21lDb, TEST_CLI_DIR . '/../migrations', $r21lLock))->apply(static fn($m) => null);
    Db::init($r21lDb, 'sqlite');
    $r21lSample = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    $r21lStoresProp->setValue(null, $r21lSample);
    StoreContext::activate(StoreContext::resolve($r21lSample, null, null, 'default', 'storefront'));
    $r21lPrice = static fn(string $sku): string => (string)($r21lDb->query('SELECT price FROM products WHERE sku = ' . $r21lDb->quote($sku))->fetchColumn());

    // ERP product sync: price and compare_price are integer cents; anything else is refused before it is stored.
    $r21lCategory = Import::ensureCategory('R21L');
    $r21lErp = static function ($price, $compare = null) use ($r21lCategory): string {
        try {
            Import::upsertProduct(['sku' => 'R21L-ERP', 'slug' => 'r21l-erp', 'name' => 'R21L ERP', 'category_id' => $r21lCategory,
                'price' => $price, 'compare_price' => $compare, 'stock' => 1]);
            return 'stored';
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'integer cents expected') ? 'refused' : get_class($e) . ': ' . $e->getMessage();
        }
    };
    $r21lErpResults = [$r21lErp(1500), $r21lErp('1600'), $r21lErp('abc'), $r21lErp(-5), $r21lErp('-5'), $r21lErp(12.5), $r21lErp('12.50'),
        $r21lErp(2000000001), $r21lErp(1700, 'x'), $r21lErp(1700, -1), $r21lErp(null)];
    assert_equals(['stored', 'stored', 'refused', 'refused', 'refused', 'refused', 'refused', 'refused', 'refused', 'refused', 'refused'], $r21lErpResults,
        'R21 ERP sync: a price or compare price that is not integer cents (abc, negative, a fraction, above the limit) is refused');
    assert_equals('1600', $r21lPrice('R21L-ERP'), 'R21 ERP sync: a refused price leaves the stored price as it was (was: "abc" or -5 saved on SQLite)');

    // Feed sync: the item errors of the last sync are kept for the feed page, capped; a clean sync clears them.
    $r21lWh = Warehouse::save(['code' => 'r21l-erp', 'name' => 'R21L ERP', 'is_default' => 0]);
    $r21lFeed = Warehouse::feedById(Warehouse::saveFeed(['code' => 'r21l-feed', 'name' => 'r21l', 'warehouse_id' => $r21lWh,
        'url' => 'https://example.com/r21l.xml', 'format' => 'xml', 'import_mode' => FeedImport::MODE_UPSERT,
        'field_map' => ['SKU' => 'sku', 'NAME' => 'name', 'PRICE' => ['field' => 'price', 'transform' => 'price_to_cents'], 'STOCK' => 'stock']]));
    $r21lErrors = static fn(): array => Warehouse::feedLastErrors(Warehouse::feedById((int)$r21lFeed['id']));
    FeedImport::applyItems($r21lFeed, [['SKU' => 'R21L-F1', 'NAME' => 'R21L 1', 'PRICE' => '10.00', 'STOCK' => '1'],
        ['SKU' => 'R21L-F2', 'NAME' => 'R21L 2', 'PRICE' => '199.000', 'STOCK' => '1']]);
    $r21lKept = $r21lErrors();
    assert_true(count($r21lKept) === 1 && str_starts_with($r21lKept[0], 'Item #1 (SKU R21L-F2): price refused, product not created: Not a price: 199.000'),
        'R21 feed: the feed keeps which item its last sync refused and why (' . json_encode($r21lKept, JSON_UNESCAPED_UNICODE) . ')');
    $r21lMany = [];
    for ($n = 0; $n < 120; $n++) $r21lMany[] = ['SKU' => "R21L-M{$n}", 'NAME' => "R21L M{$n}", 'PRICE' => '2.000', 'STOCK' => '1'];
    FeedImport::applyItems($r21lFeed, $r21lMany);
    $r21lCapped = $r21lErrors();
    assert_equals([Warehouse::FEED_ERRORS_KEPT + 1, '… +20'], [count($r21lCapped), end($r21lCapped)], 'R21 feed: the kept errors are capped at 100 and say how many more there were');
    FeedImport::applyItems($r21lFeed, [['SKU' => 'R21L-F1', 'NAME' => 'R21L 1', 'PRICE' => '10.00', 'STOCK' => '2']]);
    assert_equals([], $r21lErrors(), 'R21 feed: a clean sync clears the kept errors');

    // A sync or import that changed a product marks feeds and sitemaps stale when it commits; one that changed nothing does not.
    // (Backdated: a product stamped in the second the sync starts counts as changed by it — whole-second updated_at.)
    $r21lDb->exec("UPDATE products SET updated_at = '2020-01-01 00:00:00'");
    $r21lVersion = Cache::catalogVersion();
    FeedImport::applyItems($r21lFeed, [['SKU' => 'R21L-F1', 'NAME' => 'R21L 1', 'PRICE' => '10.00', 'STOCK' => '3']]);
    $r21lUnchanged = Cache::catalogVersion();
    FeedImport::applyItems($r21lFeed, [['SKU' => 'R21L-F1', 'NAME' => 'R21L 1', 'PRICE' => '11.00', 'STOCK' => '3']]);
    $r21lRepriced = Cache::catalogVersion();
    assert_equals([true, false], [$r21lVersion === $r21lUnchanged, $r21lRepriced === $r21lUnchanged],
        'R21 feed cache: a feed sync that repriced a product marks the public feeds stale at its commit (a feed rebuilt mid-sync kept the old price 15 min); a sync that changed nothing keeps them');
    $r21lCsv = tempnam(sys_get_temp_dir(), 'r21l') . '.csv';
    file_put_contents($r21lCsv, "sku,name,price\nR21L-F1,R21L 1,12.00\n");
    Import::csv($r21lCsv);
    @unlink($r21lCsv);
    assert_true(Cache::catalogVersion() !== $r21lRepriced, 'R21 feed cache: a CSV import that repriced a product marks the public feeds stale at its commit');

    // Price reading does not depend on a currency: a CZK product of the EUR catalog reads "1.299,00" alike from a feed and a CSV.
    $r21lDb->exec("UPDATE products SET currency = 'czk' WHERE sku = 'R21L-F1'");
    FeedImport::applyItems($r21lFeed, [['SKU' => 'R21L-F1', 'NAME' => 'R21L 1', 'PRICE' => '1.299,00', 'STOCK' => '3']]);
    $r21lFromFeed = $r21lPrice('R21L-F1');
    file_put_contents($r21lCsv, "sku,name,price\nR21L-F1,R21L 1,\"1.299,50\"\n");
    Import::csv($r21lCsv);
    @unlink($r21lCsv);
    assert_equals(['129900', '129950'], [$r21lFromFeed, $r21lPrice('R21L-F1')], 'R21 feed: an XML feed and a CSV read a price the same way, whatever the product\'s currency');

    // The admin messages list the refused rows (the first 20, then how many more), escaped.
    if (!function_exists('setFlash')) require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shared.php';
    $r21lSession = $_SESSION ?? null;
    $_SESSION = [];
    setFlash('err', 'Imported 1, errors 22', array_merge(['Line 2 (SKU: <b>): Not a price: 199.000'], array_fill(0, 21, 'Line x')));
    ob_start();
    flash();
    $r21lHtml = (string)ob_get_clean();
    $_SESSION = $r21lSession ?? [];
    assert_true(str_contains($r21lHtml, '<li>Line 2 (SKU: &lt;b&gt;): Not a price: 199.000</li>') && substr_count($r21lHtml, '<li>') === 21
        && str_contains($r21lHtml, strtr(I18n::t('admin.common.flash_more'), ['{count}' => '2'])),
        'R21 admin: the import and feed sync message lists the refused rows with the reason (the first 20, then how many more), escaped');
    $r21lOps = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Operations.php');
    assert_true(str_contains($r21lOps, '$encodingNote, $result[\'errors\']);') && str_contains($r21lOps, "\$msg, \$result['errors'] ?? []);")
        && str_contains($r21lOps, 'Warehouse::feedLastErrors($f)'),
        'R21 admin: the CSV import, "Sync now" and the feed page show the refused rows');
} finally {
    Db::init($r21lSavedDb, $r21lSavedDriver);
    @unlink($r21lLock);
    $r21lStoresProp->setValue(null, $r21lSavedStores);
    StoreContext::activate($r21lPrevious);
}
