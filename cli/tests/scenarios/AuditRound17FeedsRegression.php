<?php

// Review round 17 (2026-11-05), medium "feeds and sitemaps rebuilt after every sale": since v2.1.2 a stock change moved
// products.updated_at (for API v1 updated_after), and the public feed/sitemap cache fingerprint is MAX(updated_at), so
// every order threw away every cached feed and sitemap. A sale now moves products.stock_updated_at; the feed stays
// current where it shows stock (a product going out of stock rebuilds it), and the API still returns the change.
echo "\n--- R17: feeds and sitemaps are not rebuilt after every sale ---\n";
$pdo = Db::get();
$r17fCacheDir = new ReflectionProperty(Cache::class, 'cacheDir');
if (PHP_VERSION_ID < 80100) $r17fCacheDir->setAccessible(true);
$r17fPreviousCacheDir = $r17fCacheDir->getValue();
$r17fRoot = test_fixture_path('r17-feeds');
mkdir($r17fRoot . '/cache', 0700, true);
try {
    $r17fWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r17fCategory = (int)$pdo->query("SELECT id FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy, updated_at)
        VALUES (9971, 'default', ?, 'R17F stock', 'r17f-stock', 'R17F-STOCK', 'simple', 1500, 'eur', 'active', 5, 'deny', '2020-01-01 00:00:00')")->execute([$r17fCategory]);
    $pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'R17F-STOCK', 5, 0)")->execute([$r17fWarehouse]);
    Cache::setDir($r17fRoot . '/cache');
    sleep(1); // the cache compares whole seconds: build the entry after any earlier catalog change
    $r17fCalls = 0;
    $r17fWriter = static function ($out) use (&$r17fCalls): void { $r17fCalls++; fwrite($out, "<feed>{$r17fCalls}</feed>"); };
    $r17fSend = static function () use ($r17fWriter): string {
        ob_start();
        PublicFeedCache::send('r17f-feed.xml', 'https://shop.example', $r17fWriter);
        return (string)ob_get_clean();
    };
    $r17fFirst = $r17fSend();

    // A sale that leaves the product in stock: the cached feed is served, the API still sees the stock change.
    $pdo->beginTransaction();
    Inventory::reserveLine(['product_id' => 9971, 'variant_id' => null, 'sku' => 'R17F-STOCK', 'product_type' => 'simple'], 2);
    $pdo->commit();
    $r17fRow = $pdo->query('SELECT stock, updated_at, stock_updated_at FROM products WHERE id = 9971')->fetch(PDO::FETCH_ASSOC);
    $r17fAfterSale = $r17fSend();
    assert_equals(['<feed>1</feed>', '<feed>1</feed>', 3, '2020-01-01 00:00:00', true],
        [$r17fFirst, $r17fAfterSale, (int)$r17fRow['stock'], (string)$r17fRow['updated_at'], (string)$r17fRow['stock_updated_at'] > '2021-01-01'],
        'R17 feeds: a sale moves stock_updated_at, not updated_at, and the cached feed is served (was: rebuilt after every sale)');
    Bootstrap::initStoreContext('/', '', 'default');
    $r17fClient = ApiAuth::authenticate('Bearer ' . ApiAuth::issueClient('R17 feeds ERP', 'default', ['products:read'])['token']);
    $r17fApiRow = [];
    $r17fCursor = '';
    do {
        $r17fPage = ApiV1::dispatch('GET', '/api/v1/products', ['updated_after' => '2021-01-01T00:00:00Z', 'limit' => 100]
            + ($r17fCursor === '' ? [] : ['cursor' => $r17fCursor]), [], [], $r17fClient)['body'] ?? [];
        foreach ($r17fPage['data'] ?? [] as $r17fProduct) if ((int)$r17fProduct['id'] === 9971) $r17fApiRow = $r17fProduct;
        $r17fCursor = (string)($r17fPage['meta']['next_cursor'] ?? '');
    } while ($r17fCursor !== '');
    assert_true(($r17fApiRow['stock'] ?? null) === 3 && ($r17fApiRow['updated_at'] ?? '') === (string)$r17fRow['stock_updated_at'],
        'R17 feeds: API v1 updated_after still returns the sold product, with the stock change time as updated_at (' . json_encode($r17fApiRow) . ')');

    // The last pieces sold: the product goes out of stock, and the feed (availability) is rebuilt.
    $pdo->beginTransaction();
    Inventory::reserveLine(['product_id' => 9971, 'variant_id' => null, 'sku' => 'R17F-STOCK', 'product_type' => 'simple'], 3);
    $pdo->commit();
    assert_equals('<feed>2</feed>', $r17fSend(), 'R17 feeds: a product going out of stock rebuilds the feed');

    // A content change (a product save) still rebuilds it.
    sleep(1);
    $r17fSend();
    $r17fBeforeEdit = $r17fCalls;
    $pdo->exec("UPDATE products SET name = 'R17F renamed', updated_at = '2099-01-01 00:00:00' WHERE id = 9971");
    $r17fSend();
    assert_equals($r17fBeforeEdit + 1, $r17fCalls, 'R17 feeds: a product save still rebuilds the feed');
} catch (Throwable $e) {
    assert_true(false, 'R17 feeds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec("DELETE FROM warehouse_stock WHERE sku = 'R17F-STOCK'");
    $pdo->exec('DELETE FROM products WHERE id = 9971');
    $r17fCacheDir->setValue(null, $r17fPreviousCacheDir);
    $r17fRemove = static function (string $dir) use (&$r17fRemove): void {
        foreach (glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $entry) is_dir($entry) ? $r17fRemove($entry) : @unlink($entry);
        @rmdir($dir);
    };
    $r17fRemove($r17fRoot);
}
