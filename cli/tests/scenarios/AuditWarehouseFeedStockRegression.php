<?php

// Code review M6: a stock feed / ERP sync never sets on-hand below the units reserved for open orders (that would
// show negative availability and break later shipments), and a failing bulk sync rolls back everything it wrote.
echo "\n--- Code review M6: feed stock vs reservations, bulk sync rollback ---\n";
[$m6SavedDb, $m6SavedDriver] = [Db::get(), Db::driver()];
$m6Lock = sys_get_temp_dir() . '/bbe-m6-' . getmypid() . '.lock';
$m6Db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    (new Migrator($m6Db, TEST_CLI_DIR . '/../migrations', $m6Lock))->apply(static fn($m) => null);
    Db::init($m6Db, 'sqlite');
    $m6Wh = Warehouse::save(['code' => 'm6-main', 'name' => 'M6 main', 'is_default' => 1]);
    $m6Row = static fn(string $sku): array => $m6Db->query("SELECT qty, reserved FROM warehouse_stock WHERE warehouse_id = {$m6Wh} AND sku = " . $m6Db->quote($sku))->fetch() ?: ['qty' => null, 'reserved' => null];

    Warehouse::setStock($m6Wh, 'M6-RES', 5);
    Warehouse::reserve('M6-RES', 3);
    $m6Error = null;
    try { Warehouse::bulkSetStock($m6Wh, ['M6-RES' => 1]); } catch (Throwable $e) { $m6Error = $e->getMessage(); }
    if ($m6Db->inTransaction()) $m6Db->rollBack();
    $m6After = $m6Row('M6-RES');
    assert_true($m6Error === null && (int)$m6After['qty'] >= (int)$m6After['reserved'],
        'CR-M6: a feed count below the reservations never leaves on-hand under reserved (qty ' . $m6After['qty'] . ', reserved ' . $m6After['reserved'] . ', ' . ($m6Error ?? 'no error') . ')');
    assert_equals(['qty' => 3, 'reserved' => 3], ['qty' => (int)$m6After['qty'], 'reserved' => (int)$m6After['reserved']],
        'CR-M6: the oversold SKU keeps exactly its reservations, nothing left to sell');
    assert_equals(0, Warehouse::totalAvailable('M6-RES'), 'CR-M6: availability of the oversold SKU is zero, not negative');
    assert_equals(1, (int)$m6Db->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'inventory.feed_below_reserved'")->fetchColumn(),
        'CR-M6: the oversell is reported to operations');

    $m6Direct = false;
    try { Warehouse::setStock($m6Wh, 'M6-RES', 2); } catch (RuntimeException $e) { $m6Direct = true; }
    if ($m6Db->inTransaction()) $m6Db->rollBack();
    assert_true($m6Direct && (int)$m6Row('M6-RES')['qty'] === 3, 'CR-M6: a manual count below the reservations is refused');

    Warehouse::setStock($m6Wh, 'M6-OK', 4);
    // CR follow-up: one invalid feed row is skipped and reported; it no longer throws away the rest of the feed.
    $m6Threw = false;
    $m6Skipped = null;
    $m6Count = 0;
    try { $m6Count = Warehouse::bulkSetStock($m6Wh, ['M6-OK' => 9, 'M6-BAD' => -1], $m6Skipped); } catch (Throwable $e) { $m6Threw = true; }
    $m6OpenTx = $m6Db->inTransaction();
    if ($m6OpenTx) $m6Db->rollBack();
    assert_true(!$m6Threw && !$m6OpenTx && $m6Count === 1, 'CR-M6: a feed with an invalid row neither throws nor leaves its transaction open');
    assert_equals(9, (int)$m6Row('M6-OK')['qty'], 'CR-M6: the valid SKUs of a feed with an invalid row are applied');
    assert_true($m6Row('M6-BAD')['qty'] === null && $m6Skipped === ['M6-BAD'], 'CR-M6: the invalid row is skipped, written nowhere and reported');

    $m6Erp = null;
    try { Products::bulkUpdateStock([['sku' => 'M6-RES', 'qty' => 0]]); } catch (Throwable $e) { $m6Erp = $e->getMessage(); }
    if ($m6Db->inTransaction()) $m6Db->rollBack();
    assert_true($m6Erp === null && (int)$m6Row('M6-RES')['qty'] === 3, 'CR-M6: an ERP stock sync below the reservations is clamped the same way (' . ($m6Erp ?? 'ok') . ')');
} finally {
    Db::init($m6SavedDb, $m6SavedDriver);
    @unlink($m6Lock);
}
