<?php

// Review round 18 (2026-11-06), medium:
// - "partial Paid": the admin recorded 10 € as "Paid" on a 100 € order and the order went to fulfilment.
// - "feeds still rebuilt": every ERP/feed sync and every admin stock edit threw away the cached feeds and sitemaps even
//   when no product went in or out of stock (an unchanged ERP upsert bumped updated_at, every recount of a SKU to the
//   same number and every stock move marked the catalog changed, a feed with the same prices rewrote them).
echo "\n--- R18: a partial payment does not pay the order; feeds are rebuilt only for a change they show ---\n";
$pdo = Db::get();

$r18pfOrder = ['id' => 0, 'status' => 'pending', 'total' => 10000, 'currency' => 'eur'];
assert_equals([9000, 0, 0, 0, 0], [
    PaymentService::adminPaymentShortfall($r18pfOrder, 'paid', 1000),
    PaymentService::adminPaymentShortfall($r18pfOrder, 'paid', 10000),
    PaymentService::adminPaymentShortfall($r18pfOrder, 'paid', 12000),
    PaymentService::adminPaymentShortfall(['status' => 'cancelled'] + $r18pfOrder, 'paid', 1000),
    PaymentService::adminPaymentShortfall($r18pfOrder, 'failed', 1000),
], 'R18 paid: 10 € of a 100 € order is 90 € short; the full amount, more, a cancelled order (refund flag) and other states are not');
try {
    PaymentService::recordAdminPayment($r18pfOrder, 'paid', 1000, '', 'r18');
    assert_true(false, 'R18 paid: recording 10 € as paid for a 100 € order is refused');
} catch (InvalidArgumentException) {
    assert_true(true, 'R18 paid: recording 10 € as paid for a 100 € order is refused');
}
assert_true(str_contains(file_get_contents(dirname(__DIR__, 3) . '/src/Entrypoints/Admin/Orders.php'), 'PaymentService::adminPaymentShortfall('),
    'R18 paid: the order detail checks the amount and explains the shortfall before recording the payment');

$r18pfCacheDir = new ReflectionProperty(Cache::class, 'cacheDir');
if (PHP_VERSION_ID < 80100) $r18pfCacheDir->setAccessible(true);
$r18pfPreviousCacheDir = $r18pfCacheDir->getValue();
$r18pfRoot = test_fixture_path('r18-paid-feeds');
mkdir($r18pfRoot . '/cache', 0700, true);
try {
    $r18pfWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r18pfCategory = (int)$pdo->query("SELECT id FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy, updated_at)
        VALUES (9981, 'default', ?, 'R18PF stock', 'r18pf-stock', 'R18PF-STOCK', 'simple', 1500, 'eur', 'active', 5, 'deny', '2020-01-01 00:00:00')")->execute([$r18pfCategory]);
    $pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'R18PF-STOCK', 5, 0)")->execute([$r18pfWarehouse]);
    Cache::setDir($r18pfRoot . '/cache');
    // R20: an entry built in the second of the suite's latest catalog change is rebuilt after it; build after it.
    for ($r18pfWait = 0; $r18pfWait < 40 && PublicFeedCache::catalogState()['racy']; $r18pfWait++) usleep(100000);
    $r18pfCalls = 0;
    $r18pfWriter = static function ($out) use (&$r18pfCalls): void { $r18pfCalls++; fwrite($out, "<feed>{$r18pfCalls}</feed>"); };
    $r18pfSend = static function () use ($r18pfWriter): string {
        ob_start();
        PublicFeedCache::send('r18pf-feed.xml', 'https://shop.example', $r18pfWriter);
        return (string)ob_get_clean();
    };
    $r18pfUpdated = static fn(): array => $pdo->query('SELECT updated_at, stock_updated_at FROM products WHERE id = 9981')->fetch(PDO::FETCH_NUM);

    // A feed/ERP stock sync with the same count, then with a count that keeps the product in stock.
    assert_equals('<feed>1</feed>', $r18pfSend(), 'R18 feeds: the feed is built');
    Warehouse::bulkSetStock($r18pfWarehouse, ['R18PF-STOCK' => 5]);
    Warehouse::bulkSetStock($r18pfWarehouse, ['R18PF-STOCK' => 8]);
    assert_equals(['<feed>1</feed>', '2020-01-01 00:00:00', 8],
        [$r18pfSend(), $r18pfUpdated()[0], (int)$pdo->query('SELECT stock FROM products WHERE id = 9981')->fetchColumn()],
        'R18 feeds: a stock sync that keeps the product in stock serves the cached feed (was: rebuilt on every sync)');
    Warehouse::setStock($r18pfWarehouse, 'R18PF-STOCK', 6, 'manual_adjustment', 'admin_stock');
    assert_equals('<feed>1</feed>', $r18pfSend(), 'R18 feeds: an admin stock edit that keeps the product in stock serves the cached feed');

    // An unchanged ERP product upsert keeps updated_at; a stock-only change moves stock_updated_at; a rename moves updated_at.
    $r18pfData = ['sku' => 'R18PF-STOCK', 'slug' => 'r18pf-stock', 'name' => 'R18PF stock', 'category_id' => $r18pfCategory,
        'price' => 1500, 'compare_price' => null, 'stock' => 6, 'status' => 'active', 'images' => [], 'attrs' => [], 'tags' => []];
    Import::upsertProduct($r18pfData);
    $pdo->exec("UPDATE products SET updated_at = '2020-01-01 00:00:00', stock_updated_at = '2020-01-01 00:00:00' WHERE id = 9981");
    Import::upsertProduct($r18pfData);
    $r18pfSame = $r18pfUpdated();
    Import::upsertProduct(['stock' => 4] + $r18pfData);
    $r18pfStock = $r18pfUpdated();
    Import::upsertProduct(['name' => 'R18PF renamed'] + $r18pfData);
    $r18pfRenamed = $r18pfUpdated();
    assert_equals([['2020-01-01 00:00:00', '2020-01-01 00:00:00'], '2020-01-01 00:00:00', true, true],
        [$r18pfSame, $r18pfStock[0], $r18pfStock[1] > '2021-01-01', $r18pfRenamed[0] > '2021-01-01'],
        'R18 feeds: an ERP upsert of the same product keeps updated_at, a stock change moves stock_updated_at only, a rename updated_at');

    // A price/stock feed with the same price does not rewrite the product; another price does.
    $pdo->exec("UPDATE products SET updated_at = '2020-01-01 00:00:00' WHERE id = 9981");
    $r18pfPriceOnly = new ReflectionMethod(FeedImport::class, 'updatePriceOnly');
    if (PHP_VERSION_ID < 80100) $r18pfPriceOnly->setAccessible(true);
    $pdo->beginTransaction(); // as in a feed run (the product.updated event is recorded in it)
    $r18pfPriceOnly->invoke(null, ['sku' => 'R18PF-STOCK', 'price' => '15.00'], $pdo);
    $r18pfSamePrice = $r18pfUpdated()[0];
    $r18pfPriceOnly->invoke(null, ['sku' => 'R18PF-STOCK', 'price' => '14.00'], $pdo);
    $pdo->commit();
    assert_equals(['2020-01-01 00:00:00', true, 1400], [$r18pfSamePrice, $r18pfUpdated()[0] > '2021-01-01',
        (int)$pdo->query('SELECT price FROM products WHERE id = 9981')->fetchColumn()],
        'R18 feeds: a price feed with the same price keeps updated_at (was: bumped on every sync), a new price is saved');

    // Going out of stock still rebuilds the feed (availability), and so does a plain catalogChanged().
    for ($r18pfWait = 0; $r18pfWait < 40 && PublicFeedCache::catalogState()['racy']; $r18pfWait++) usleep(100000);
    $r18pfSend();
    $r18pfBefore = $r18pfCalls;
    Warehouse::bulkSetStock($r18pfWarehouse, ['R18PF-STOCK' => 0]);
    $r18pfSend();
    $r18pfAfterOut = $r18pfCalls;
    Cache::catalogChanged(null, false);
    $r18pfSend();
    $r18pfAfterPagesOnly = $r18pfCalls;
    Cache::catalogChanged();
    $r18pfSend();
    assert_equals([$r18pfBefore + 1, $r18pfBefore + 1, $r18pfBefore + 2], [$r18pfAfterOut, $r18pfAfterPagesOnly, $r18pfCalls],
        'R18 feeds: out of stock rebuilds the feed, a pages-only catalog change does not, a full one does');
} catch (Throwable $e) {
    assert_true(false, 'R18 feeds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec("DELETE FROM warehouse_stock WHERE sku = 'R18PF-STOCK'");
    $pdo->exec("DELETE FROM product_price_history WHERE product_id = 9981");
    $pdo->exec('DELETE FROM products WHERE id = 9981');
    $r18pfCacheDir->setValue(null, $r18pfPreviousCacheDir);
    $r18pfRemove = static function (string $dir) use (&$r18pfRemove): void {
        foreach (glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $entry) is_dir($entry) ? $r18pfRemove($entry) : @unlink($entry);
        @rmdir($dir);
    };
    $r18pfRemove($r18pfRoot);
}
