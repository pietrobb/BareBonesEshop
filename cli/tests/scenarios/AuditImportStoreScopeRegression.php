<?php

// Code review 2026-10-15 H6: CSV/feed import is confined to the current store's catalog,
// so an admin of store B can neither overwrite nor price store A's products by SKU.
echo "\n--- Code review H6: import store scope ---\n";
(require TEST_CLI_DIR . '/../migrations/20261013120000_category_slug_per_catalog.php')->up($pdo, true);
$h6SavedStore = StoreContext::current();
$h6Stores = Bootstrap::stores();
$h6Stores['stores']['h6b'] = array_replace($h6Stores['stores']['default'], ['id' => 'h6b', 'url_prefix' => '/h6b', 'domains' => [], 'catalog_scope' => 'h6b', 'provider_scope' => 'h6b']);
$h6Product = static function (string $sku) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT p.store_id, p.name, p.price, p.compare_price, p.description, c.store_id AS category_store FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.sku = ?');
    $stmt->execute([$sku]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$pdo->beginTransaction();
try {
    StoreContext::activate(StoreContext::resolve($h6Stores, '/', '', 'default'));
    Import::importProduct(['sku' => 'H6-A', 'name' => 'Store A product', 'price' => '10.00', 'stock' => '', 'description' => 'A only', 'category' => 'h6-cat'], $pdo);
    assert_equals(['default', 'default'], [$h6Product('H6-A')['store_id'] ?? '', $h6Product('H6-A')['category_store'] ?? ''], 'H6: store A import creates its product and category in catalog A');

    StoreContext::activate(StoreContext::resolve($h6Stores, '/h6b', '', 'h6b'));
    $h6Error = '';
    try {
        Import::importProduct(['sku' => 'H6-A', 'name' => 'Hijacked', 'price' => '0.01', 'description' => 'B wrote this'], $pdo);
    } catch (RuntimeException $e) {
        $h6Error = $e->getMessage();
    }
    assert_true(str_contains($h6Error, 'another catalog'), 'H6: store B import of a store A SKU is refused');
    $h6A = $h6Product('H6-A');
    assert_equals(['default', 'Store A product', 1000, 'A only'], [$h6A['store_id'], $h6A['name'], (int)$h6A['price'], $h6A['description']], 'H6: store A product is untouched by store B import');

    $h6Upsert = '';
    try {
        Import::upsertProduct(['sku' => 'H6-A', 'slug' => 'h6-a-hijack', 'name' => 'Hijacked', 'category_id' => null, 'price' => 1, 'compare_price' => null, 'stock' => 0], $pdo);
    } catch (RuntimeException $e) {
        $h6Upsert = $e->getMessage();
    }
    assert_true(str_contains($h6Upsert, 'another catalog'), 'H6: upsert (ERP sync) of a store A SKU from store B is refused');
    assert_equals('Store A product', $h6Product('H6-A')['name'], 'H6: refused upsert leaves store A product untouched');

    $h6Feed = new ReflectionMethod(FeedImport::class, 'updatePriceOnly');
    assert_true(!$h6Feed->invoke(null, ['sku' => 'H6-A', 'price' => '0.01'], $pdo), 'H6: store B price/stock feed does not see a store A SKU');
    assert_equals([1000, null], [(int)$h6Product('H6-A')['price'], $h6Product('H6-A')['compare_price']], 'H6: store B price feed leaves store A price untouched');

    Import::importProduct(['sku' => 'H6-B', 'name' => 'Store B product', 'price' => '5.00', 'category' => 'h6-cat'], $pdo);
    $h6B = $h6Product('H6-B');
    assert_equals(['h6b', 'h6b'], [$h6B['store_id'] ?? '', $h6B['category_store'] ?? ''], 'H6: store B import creates its product and category in catalog B');
    assert_true($h6Feed->invoke(null, ['sku' => 'H6-B', 'price' => '4.00'], $pdo), 'H6: store B price feed updates its own SKU');
    assert_equals([400, 500], [(int)$h6Product('H6-B')['price'], (int)$h6Product('H6-B')['compare_price']], 'H6: own-catalog price drop keeps the old price as compare price');
} catch (Throwable $e) {
    assert_true(false, 'Import store scope regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    StoreContext::activate($h6SavedStore);
}
