<?php

// Review round 19 (2026-11-06), medium "currency on import": a product created by a CSV import, the API or a feed in a
// HUF/CZK catalog was stored as EUR (Import::upsertProduct did not write the currency column): "12 990 Ft" became
// 12 990,00 € and could not be sold in the HUF store. Low: "1.234" was read as 1,23 ("1.234 Kč" stored as 1,23 Kč); the
// shipping and payment amounts in the admin were checked in the administered store's currency, not in that of the store
// the providers belong to.
echo "\n--- R19: imported products take the catalog's currency, '1.234' is 1 234, provider amounts in the providers' currency ---\n";

assert_equals([123400, 123400, 1299000, 1299000, 1990, 123450, 13, 12345600],
    [Import::parsePriceToCents('1.234', 'czk'), Import::parsePriceToCents('1.234 Kč', 'czk'), Import::parsePriceToCents('12,990 Ft', 'huf'),
        Import::parsePriceToCents('12 990 Ft', 'huf'), Import::parsePriceToCents('19.90', 'huf'), Import::parsePriceToCents('1.234,50', 'czk'),
        Import::parsePriceToCents('0.125', 'czk'), Import::parsePriceToCents('123.456', 'huf')],
    'R19 import: in CZK/HUF a single separator before three digits groups them ("1.234 Kč" is 1 234, was 1,23); "0.125" keeps its decimal mark (R20: only there)');

$r19cStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r19cSavedStores = $r19cStoresProp->getValue();
$r19cPrevious = StoreContext::current();
$r19cDb = Db::get();
$r19cSkus = ['R19C-HUF-CSV', 'R19C-HUF-SYNC', 'R19C-EUR-CSV'];
try {
    $r19cSample = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    $r19cSample['stores']['hu']['catalog_scope'] = 'hu';
    $r19cStoresProp->setValue(null, $r19cSample);

    // The HU store with a catalog of its own (HUF): a CSV/API/feed row and an ERP sync product.
    StoreContext::activate(StoreContext::resolve($r19cSample, null, null, 'hu', 'storefront'));
    Import::importProduct(['sku' => 'R19C-HUF-CSV', 'name' => 'R19 HUF product', 'price' => '12 990 Ft', 'stock' => '3', 'category' => 'R19C HUF']);
    $r19cCategory = (int)$r19cDb->query("SELECT category_id FROM products WHERE sku = 'R19C-HUF-CSV'")->fetchColumn();
    Import::upsertProduct(['sku' => 'R19C-HUF-SYNC', 'slug' => 'r19c-huf-sync', 'name' => 'R19 HUF sync', 'category_id' => $r19cCategory,
        'price' => 599000, 'compare_price' => null, 'stock' => 1]);
    // The default store's EUR catalog, from the HU store sharing it: EUR, as in the admin (R18).
    StoreContext::activate(StoreContext::resolve($r19cSample, null, null, 'default', 'storefront'));
    Import::importProduct(['sku' => 'R19C-EUR-CSV', 'name' => 'R19 EUR product', 'price' => '24.90', 'category' => 'R19C EUR']);
    $r19cRead = $r19cDb->prepare('SELECT sku, currency, price FROM products WHERE sku IN (?, ?, ?) ORDER BY sku');
    $r19cRead->execute($r19cSkus);
    $r19cRows = array_map(static fn(array $r): string => $r['sku'] . ' ' . $r['currency'] . ' ' . $r['price'], $r19cRead->fetchAll(PDO::FETCH_ASSOC));
    assert_equals(['R19C-EUR-CSV eur 2490', 'R19C-HUF-CSV huf 1299000', 'R19C-HUF-SYNC huf 599000'], $r19cRows,
        'R19 import: a new product of a HUF catalog is priced in HUF (CSV/API/feed and ERP sync), of an EUR catalog in EUR');

    // An existing product keeps its currency when it is imported again.
    $r19cDb->prepare("UPDATE products SET currency = 'czk' WHERE sku = 'R19C-EUR-CSV'")->execute();
    $r19cCategory = (int)$r19cDb->query("SELECT category_id FROM products WHERE sku = 'R19C-EUR-CSV'")->fetchColumn();
    Import::upsertProduct(['sku' => 'R19C-EUR-CSV', 'slug' => 'r19c-eur-csv', 'name' => 'R19 EUR product', 'category_id' => $r19cCategory,
        'price' => 2490, 'compare_price' => null, 'stock' => 0]);
    $r19cKept = $r19cDb->query("SELECT currency FROM products WHERE sku = 'R19C-EUR-CSV'")->fetchColumn();
    assert_equals('czk', $r19cKept, 'R19 import: re-importing an existing product does not change its currency');

    // The US store (USD) uses the default store's providers (EUR): shipping and payment amounts are in EUR.
    StoreContext::activate(StoreContext::resolve($r19cSample, null, null, 'us', 'storefront'));
    $r19cUs = [StoreContext::current()->currency(), StoreContext::current()->providerCurrency()];
    StoreContext::activate(StoreContext::resolve($r19cSample, null, null, 'hu', 'storefront'));
    $r19cHu = StoreContext::current()->providerCurrency();
    assert_equals([['usd', 'eur'], 'huf'], [$r19cUs, $r19cHu], 'R19 settings: the provider amounts are in the currency of the store owning the providers');
    $r19cOps = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php');
    assert_true(substr_count($r19cOps, 'StoreContext::current()->providerCurrency()') >= 8 && !str_contains($r19cOps, 'StoreContext::current()->currency()'),
        'R19 settings: the shipping and payment pages show and check their amounts in the providers\' currency');
} finally {
    $r19cDelete = $r19cDb->prepare('DELETE FROM products WHERE sku = ?');
    foreach ($r19cSkus as $r19cSku) {
        $r19cId = $r19cDb->prepare('SELECT id FROM products WHERE sku = ?');
        $r19cId->execute([$r19cSku]);
        $r19cProductId = $r19cId->fetchColumn();
        if ($r19cProductId !== false) {
            foreach (['price_history', 'stock_movements', 'product_slugs'] as $r19cTable) {
                try { $r19cDb->prepare("DELETE FROM {$r19cTable} WHERE product_id = ?")->execute([(int)$r19cProductId]); } catch (PDOException) {}
            }
        }
        try { $r19cDelete->execute([$r19cSku]); } catch (PDOException) {}
    }
    // The categories the import created.
    foreach ($r19cDb->query("SELECT id FROM categories WHERE slug LIKE 'r19c-%'")->fetchAll(PDO::FETCH_COLUMN) as $r19cCategoryId) {
        $r19cDb->prepare("DELETE FROM slug_registry WHERE entity = 'category' AND entity_id = ?")->execute([(int)$r19cCategoryId]);
        $r19cDb->prepare('DELETE FROM categories WHERE id = ?')->execute([(int)$r19cCategoryId]);
    }
    $r19cStoresProp->setValue(null, $r19cSavedStores);
    StoreContext::activate($r19cPrevious);
}
