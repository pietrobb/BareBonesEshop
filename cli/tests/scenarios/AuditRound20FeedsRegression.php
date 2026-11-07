<?php

// Review round 20 (2026-11-07), low "feeds and sitemaps": the catalog fingerprint read every product row (COUNT(*) and
// the R19 price sums) on every feed and sitemap request, also when the entry came from the cache (200 000 products:
// 0.1 s on SQLite, 0.7 s on MySQL). A product renamed or taken off sale in the same second as another change (updated_at
// has whole seconds) reached the feed only when the 15-minute TTL ran out.
echo "\n--- R20: the feed fingerprint reads indexes only; a same-second rename reaches the feed ---\n";
$pdo = Db::get();

// 1. The fingerprint query: no product row is read (SQLite plan: index searches only).
$r20fSql = method_exists(PublicFeedCache::class, 'stateSql') ? (new ReflectionMethod(PublicFeedCache::class, 'stateSql'))->invoke(null) : 'SELECT SUM(price) FROM products';
assert_true(!preg_match('/COUNT\(\*\)\s+FROM\s+products|SUM\(/i', $r20fSql), 'R20 feeds: the fingerprint neither counts nor sums the products');
if (Db::isSqlite()) {
    $r20fPlan = implode("\n", array_map(static fn(array $r): string => (string)end($r), $pdo->query('EXPLAIN QUERY PLAN ' . $r20fSql)->fetchAll(PDO::FETCH_NUM)));
    assert_true(!preg_match('/SCAN (TABLE )?products(?! USING (COVERING )?INDEX)/', $r20fPlan) && !preg_match('/SCAN (TABLE )?products USING COVERING INDEX/', $r20fPlan),
        "R20 feeds: SQLite answers the fingerprint from indexes, not by scanning products ({$r20fPlan})");
}

$r20fCacheDir = new ReflectionProperty(Cache::class, 'cacheDir');
if (PHP_VERSION_ID < 80100) $r20fCacheDir->setAccessible(true);
$r20fPreviousCacheDir = $r20fCacheDir->getValue();
$r20fRoot = test_fixture_path('r20-feeds');
mkdir($r20fRoot . '/cache', 0700, true);
try {
    $r20fCategory = (int)$pdo->query("SELECT id FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy, updated_at, stock_updated_at)
        VALUES (9984, 'default', ?, 'R20F before', 'r20f-name', 'R20F-NAME', 'simple', 1500, 'eur', 'active', 5, 'deny', '2020-01-01 00:00:00', '2020-01-01 00:00:00')")->execute([$r20fCategory]);
    Cache::setDir($r20fRoot . '/cache');
    $r20fWriter = static function ($out) use ($pdo): void {
        $row = $pdo->query('SELECT name, status FROM products WHERE id = 9984')->fetch(PDO::FETCH_NUM);
        fwrite($out, is_array($row) ? $row[0] . ':' . $row[1] : '');
    };
    $r20fSend = static function () use ($r20fWriter): string {
        ob_start();
        PublicFeedCache::send('r20f-feed.xml', 'https://shop.example', $r20fWriter);
        return (string)ob_get_clean();
    };
    $r20fNow = static fn(): string => (string)$pdo->query('SELECT ' . Db::now())->fetchColumn();
    $r20fAfter = static function (string $second) use ($r20fNow): void {
        for ($i = 0; $i < 40 && $r20fNow() <= $second; $i++) usleep(100000);
    };
    // Every earlier change of the suite is in a past second: the first entry is not marked.
    for ($i = 0; $i < 40 && method_exists(PublicFeedCache::class, 'catalogState') && PublicFeedCache::catalogState()['racy']; $i++) usleep(100000);

    // 2. An entry built in the second of the latest change: a rename later in that second (same updated_at) reaches the
    //    feed once the second is over. The change is stamped a second ahead so the entry is built within its second.
    assert_equals('R20F before:active', $r20fSend(), 'R20 feeds: the feed is built');
    $r20fSecond = (string)$pdo->query('SELECT ' . (Db::isSqlite() ? "datetime('now', '+1 second')" : 'NOW() + INTERVAL 1 SECOND'))->fetchColumn();
    $pdo->prepare("UPDATE products SET price = 1400, updated_at = ? WHERE id = 9984")->execute([$r20fSecond]);
    $r20fPrice = $r20fSend();
    $pdo->prepare("UPDATE products SET name = 'R20F renamed', updated_at = ? WHERE id = 9984")->execute([$r20fSecond]);
    $r20fAfter($r20fSecond);
    assert_equals(['R20F before:active', 'R20F renamed:active'], [$r20fPrice, $r20fSend()],
        'R20 feeds: a rename in the second of the change the feed was built after is in the feed once that second is over (was: after the TTL)');

    // 3. The same for taking the product off sale; and a settled entry is served from the cache again.
    $r20fSecond = (string)$pdo->query('SELECT ' . (Db::isSqlite() ? "datetime('now', '+1 second')" : 'NOW() + INTERVAL 1 SECOND'))->fetchColumn();
    $pdo->prepare("UPDATE products SET price = 1300, updated_at = ? WHERE id = 9984")->execute([$r20fSecond]);
    $r20fSend();
    $pdo->prepare("UPDATE products SET status = 'archived', updated_at = ? WHERE id = 9984")->execute([$r20fSecond]);
    $r20fAfter($r20fSecond);
    $r20fArchived = $r20fSend();
    $pdo->prepare("UPDATE products SET name = 'R20F unseen' WHERE id = 9984")->execute(); // no updated_at: not a catalog change
    assert_equals(['R20F renamed:archived', 'R20F renamed:archived'], [$r20fArchived, $r20fSend()],
        'R20 feeds: a product taken off sale in that second is in the feed after it; the settled entry is then served from the cache');

    // 4. A product deleted in the admin rebuilds the feed (it no longer counts the products).
    $r20fGone = ProductBulk::apply('delete', [9984]);
    assert_equals([1, ''], [$r20fGone['count'], $r20fSend()], 'R20 feeds: a product deleted in the admin leaves the feed');
} catch (Throwable $e) {
    assert_true(false, 'R20 feeds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    foreach (["DELETE FROM warehouse_stock WHERE sku = 'R20F-NAME'", "DELETE FROM stock_movements WHERE sku = 'R20F-NAME'",
        'DELETE FROM product_price_history WHERE product_id = 9984', "DELETE FROM slug_registry WHERE entity = 'product' AND entity_id = 9984",
        "DELETE FROM audit_log WHERE entity = 'product' AND entity_id = 9984", 'DELETE FROM products WHERE id = 9984'] as $r20fCleanup) {
        try { $pdo->exec($r20fCleanup); } catch (PDOException) {}
    }
    $r20fCacheDir->setValue(null, $r20fPreviousCacheDir);
    $r20fRemove = static function (string $dir) use (&$r20fRemove): void {
        foreach (glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $entry) is_dir($entry) ? $r20fRemove($entry) : @unlink($entry);
        @rmdir($dir);
    };
    $r20fRemove($r20fRoot);
}
