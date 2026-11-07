<?php

// Review round 19 (2026-11-06), medium "feeds after an import": a stock change by a CSV import (5 → 7 pieces) still
// rebuilt every feed and sitemap (it bumped updated_at), and an ERP product sync taking a product out of stock did not
// mark them changed. Low: a price saved in the same second as the change a feed was built after (updated_at has whole
// seconds) left the old price in the feed for up to the 15-minute TTL.
echo "\n--- R19: an import's stock change rebuilds the feeds only when availability changes; a same-second price change does ---\n";
$pdo = Db::get();

$r19fCacheDir = new ReflectionProperty(Cache::class, 'cacheDir');
if (PHP_VERSION_ID < 80100) $r19fCacheDir->setAccessible(true);
$r19fPreviousCacheDir = $r19fCacheDir->getValue();
$r19fRoot = test_fixture_path('r19-import-feeds');
mkdir($r19fRoot . '/cache', 0700, true);
try {
    $r19fCategory = (int)$pdo->query("SELECT id FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy, updated_at, stock_updated_at)
        VALUES (9983, 'default', ?, 'R19F stock', 'r19f-stock', 'R19F-STOCK', 'simple', 1500, 'eur', 'active', 5, 'deny', '2020-01-01 00:00:00', '2020-01-01 00:00:00')")->execute([$r19fCategory]);
    $r19fWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'R19F-STOCK', 5, 0)")->execute([$r19fWarehouse]);
    Cache::setDir($r19fRoot . '/cache');
    // R20: an entry built in the second of the suite's latest catalog change is rebuilt after it; build after it.
    for ($r19fWait = 0; $r19fWait < 40 && PublicFeedCache::catalogState()['racy']; $r19fWait++) usleep(100000);
    $r19fCalls = 0;
    $r19fWriter = static function ($out) use (&$r19fCalls): void { $r19fCalls++; fwrite($out, "<feed>{$r19fCalls}</feed>"); };
    $r19fSend = static function () use ($r19fWriter): string {
        ob_start();
        PublicFeedCache::send('r19f-feed.xml', 'https://shop.example', $r19fWriter);
        return (string)ob_get_clean();
    };
    $r19fRow = static fn(): array => $pdo->query('SELECT updated_at, stock_updated_at, stock FROM products WHERE id = 9983')->fetch(PDO::FETCH_NUM);

    // CSV import: 5 → 7 keeps the product in stock (stock_updated_at moves, the feed is served from the cache); 7 → 0 does not.
    assert_equals('<feed>1</feed>', $r19fSend(), 'R19 feeds: the feed is built');
    Import::importProduct(['sku' => 'R19F-STOCK', 'name' => 'R19F stock', 'stock' => '7']);
    $r19fCsvRow = $r19fRow();
    assert_equals(['<feed>1</feed>', '2020-01-01 00:00:00', true, 7], [$r19fSend(), $r19fCsvRow[0], $r19fCsvRow[1] > '2021-01-01', (int)$r19fCsvRow[2]],
        'R19 feeds: a CSV import moving the stock 5 → 7 keeps updated_at and the cached feed (was: rebuilt), stock_updated_at moves');
    Import::importProduct(['sku' => 'R19F-STOCK', 'name' => 'R19F stock', 'stock' => '0']);
    assert_equals(['<feed>2</feed>', '2020-01-01 00:00:00'], [$r19fSend(), $r19fRow()[0]],
        'R19 feeds: a CSV import taking the product out of stock rebuilds the feed');

    // ERP product sync: 0 → 3 brings it back in stock (was: the feed kept "out of stock"); 3 → 9 keeps the cached feed.
    $r19fData = ['sku' => 'R19F-STOCK', 'slug' => 'r19f-stock', 'name' => 'R19F stock', 'category_id' => $r19fCategory,
        'price' => 1500, 'compare_price' => null, 'stock' => 0, 'status' => 'active', 'images' => [], 'attrs' => [], 'tags' => []];
    Import::upsertProduct($r19fData); // normalises the stored row to the sync's form
    $r19fSend();
    $r19fBefore = $r19fCalls;
    Import::upsertProduct(['stock' => 3] + $r19fData);
    $r19fSend();
    $r19fAfterIn = $r19fCalls;
    Import::upsertProduct(['stock' => 9] + $r19fData);
    $r19fSend();
    assert_equals([$r19fBefore + 1, $r19fBefore + 1], [$r19fAfterIn, $r19fCalls],
        'R19 feeds: an ERP sync bringing the product back in stock rebuilds the feed, one keeping it in stock does not');

    // A price saved in the same second as the change the feed was built after (same updated_at) rebuilds it once that
    // second is over (R20: the entry built in the second of the latest change is marked, no longer the price sums).
    $r19fSecond = (string)$pdo->query('SELECT ' . (Db::isSqlite() ? "datetime('now', '+1 second')" : 'NOW() + INTERVAL 1 SECOND'))->fetchColumn();
    $pdo->prepare('UPDATE products SET updated_at = ? WHERE id = 9983')->execute([$r19fSecond]);
    $r19fSend();
    $r19fBefore = $r19fCalls;
    $pdo->prepare('UPDATE products SET price = 1400, updated_at = ? WHERE id = 9983')->execute([$r19fSecond]);
    for ($r19fWait = 0; $r19fWait < 40 && (string)$pdo->query('SELECT ' . Db::now())->fetchColumn() <= $r19fSecond; $r19fWait++) usleep(100000);
    assert_equals('<feed>' . ($r19fBefore + 1) . '</feed>', $r19fSend(),
        'R19 feeds: a price change within the second of the previous change rebuilds the feed (was: old price for up to 15 minutes)');
} catch (Throwable $e) {
    assert_true(false, 'R19 feeds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach (["DELETE FROM warehouse_stock WHERE sku = 'R19F-STOCK'", "DELETE FROM stock_movements WHERE sku = 'R19F-STOCK'",
        'DELETE FROM product_price_history WHERE product_id = 9983', "DELETE FROM slug_registry WHERE entity = 'product' AND entity_id = 9983",
        'DELETE FROM products WHERE id = 9983'] as $r19fCleanup) {
        try { $pdo->exec($r19fCleanup); } catch (PDOException) {}
    }
    $r19fCacheDir->setValue(null, $r19fPreviousCacheDir);
    $r19fRemove = static function (string $dir) use (&$r19fRemove): void {
        foreach (glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $entry) is_dir($entry) ? $r19fRemove($entry) : @unlink($entry);
        @rmdir($dir);
    };
    $r19fRemove($r19fRoot);
}
