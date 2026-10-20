<?php

// Round 7 review (2026-10-20), hotfix: on MySQL two partial shipments of one order both passed the
// capacity check (the order shipped twice and took another order's reservation), and two concurrent
// purchase-order receipts received more than was ordered. Real second connection, as AuditCouponRaceRegression.
echo "\n--- Audit round 7 (hotfix): concurrent shipments and purchase-order receipts ---\n";
$r7lRoot = test_fixture_path('r7-lock-race');
mkdir($r7lRoot);
file_put_contents($r7lRoot . '/race.php', <<<'PHP'
<?php
[, $driver, $phase, $root, $target] = $argv;
require $root . '/src/autoload.php';
$connect = static function() use ($driver, $target): PDO {
    $db = $driver === 'mysql'
        ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
        : new PDO('sqlite:' . $target, null, null, [PDO::ATTR_TIMEOUT => 1]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
};
$a = $connect();
if ($driver === 'mysql') {
    if ($phase === 'teardown') { $a->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $a->exec("CREATE DATABASE `{$target}`");
    $a->exec("USE `{$target}`");
}
Db::init($a, $driver);
(new Migrator($a, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
$b = $connect();
if ($driver === 'mysql') { $b->exec("USE `{$target}`"); $b->exec('SET SESSION innodb_lock_wait_timeout = 1'); }
$a->exec("INSERT INTO categories (store_id, name, slug) VALUES ('default', 'R7L', 'r7l')");
$category = (int)$a->lastInsertId();
$a->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97201, 'default', {$category}, 'R7L', 'r7l', 'R7L-SKU', 'simple', 1000, 'eur', 'active', 0, 'deny')");
$a->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r7l-wh', 'R7L', 1, 1)");
$wh = (int)$a->lastInsertId();
$a->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$wh}, 'R7L-SKU', 10, 2)");
$a->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id)
    VALUES ('R7L-1', 'paid', 'paid', 'R7L', 'r7l@example.test', 'card', 2000, 2000, 'eur', 'default')");
$order = (int)$a->lastInsertId();
$attrs = json_encode(['_inventory_allocations' => [['warehouse_id' => $wh, 'warehouse_code' => 'r7l-wh', 'qty' => 2, 'status' => 'reserved']]]);
$a->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, attrs, reserved_qty) VALUES (?, 97201, 'R7L-SKU', 'R7L', 2, 1000, 2000, ?, 2)")
    ->execute([$order, $attrs]);
$item = (int)$a->lastInsertId();
$try = static function(PDO $db, callable $fn) use ($driver): string {
    Db::init($db, $driver);
    try { return $fn() === false ? 'replayed' : 'ok'; } catch (Throwable $e) { return 'refused'; }
};
$ship = static fn(string $key): callable => static fn() => Fulfillment::createShipment($order, $wh, [['order_item_id' => $item, 'sku' => 'R7L-SKU', 'quantity' => 2]], $key);

// Two partial shipments of the whole reservation, the second while the first is uncommitted.
$a->beginTransaction();
$first = $try($a, $ship('r7l-a'));
$concurrent = $try($b, $ship('r7l-b'));
$a->commit();
$after = $try($b, $ship('r7l-c'));
$planned = (int)$a->query("SELECT COALESCE(SUM(quantity), 0) FROM shipment_items WHERE status <> 'cancelled'")->fetchColumn();

// Two receipts of 3 on a purchase order of 5: the second read its row before the first committed.
$a->exec("INSERT INTO suppliers (store_id, code, name) VALUES ('default', 'r7l-sup', 'R7L')");
$supplier = (int)$a->lastInsertId();
$a->exec("INSERT INTO purchase_orders (store_id, supplier_id, warehouse_id, number, status) VALUES ('default', {$supplier}, {$wh}, 'R7L-PO', 'ordered')");
$po = (int)$a->lastInsertId();
$a->exec("INSERT INTO purchase_order_items (purchase_order_id, sku, quantity) VALUES ({$po}, 'R7L-SKU', 5)");
$receive = static fn(string $key): callable => static fn() => Inventory::receivePurchaseOrder($po, 'R7L-SKU', 3, $key);
$receipts = [];
if ($driver === 'mysql') {
    $b->beginTransaction();
    $b->query("SELECT received_qty FROM purchase_order_items WHERE purchase_order_id = {$po}")->fetchAll();
    $receipts[] = $try($a, $receive('r7l-r1'));
    $receipts[] = $try($b, $receive('r7l-r2'));
    $b->inTransaction() ? $b->commit() : null;
} else {
    $receipts[] = $try($a, $receive('r7l-r1'));
    $receipts[] = $try($b, $receive('r7l-r2'));
}
$received = (int)$a->query("SELECT received_qty FROM purchase_order_items WHERE purchase_order_id = {$po}")->fetchColumn();
// Deleting a warehouse while a checkout reserves in it: A's reservation check (its read snapshot) predates B's
// committed reservation, the case a plain SELECT check followed by an unconditional DELETE let through.
$a->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97202, 'default', {$category}, 'R7L del', 'r7l-del', 'R7L-DEL', 'simple', 1000, 'eur', 'active', 5, 'deny')");
$a->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r7l-del', 'R7L del', 2, 0)");
$delWh = (int)$a->lastInsertId();
$a->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$delWh}, 'R7L-DEL', 5, 0)");
$a->beginTransaction();
$a->query("SELECT COALESCE(SUM(reserved), 0) FROM warehouse_stock WHERE warehouse_id = {$delWh}")->fetchColumn();
$reservation = $try($b, static fn() => Warehouse::reserve('R7L-DEL', 1));
$deletion = $try($a, static fn() => Warehouse::delete($delWh));
$deletion === 'ok' ? $a->commit() : $a->rollBack();
$kept = (int)$a->query("SELECT COALESCE(SUM(reserved), 0) FROM warehouse_stock WHERE warehouse_id = {$delWh}")->fetchColumn();
echo json_encode([[$first, $concurrent, $after, $planned], [$receipts, $received], [$reservation, $deletion, $kept]]);
PHP);
$r7lPhp = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
$r7lDrivers = ['sqlite' => $r7lRoot . '/race.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r7lDrivers['mysql'] = 'bbe_r7l_race_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit R7 native shipment/receipt race (set TEST_MYSQL_DSN)\n";
try {
    foreach ($r7lDrivers as $r7lDriver => $r7lTarget) {
        [$r7lCode, $r7lOut] = $r7lPhp([$r7lRoot . '/race.php', $r7lDriver, 'run', dirname(TEST_CLI_DIR), $r7lTarget]);
        $r7lResult = json_decode($r7lOut, true);
        assert_equals([0, ['ok', 'refused', 'refused', 2]], [$r7lCode, $r7lResult[0] ?? $r7lOut], "R7 {$r7lDriver} shipments: a concurrent second shipment of the same reservation is refused, only 2 units are planned");
        assert_equals([['ok', 'refused'], 3], $r7lResult[1] ?? $r7lOut, "R7 {$r7lDriver} receipts: a concurrent receipt cannot receive more than the 5 ordered");
        [$r7lReserved, $r7lDeleted, $r7lKept] = $r7lResult[2] ?? [null, null, null];
        assert_true($r7lReserved !== null && ($r7lReserved !== 'ok' || [$r7lDeleted, $r7lKept] === ['refused', 1]),
            "R7 {$r7lDriver} warehouse delete: a reservation committed during the delete survives and the delete is refused (" . json_encode($r7lResult[2] ?? $r7lOut) . ')');
        if ($r7lDriver === 'mysql') assert_equals(['ok', 'refused', 1], $r7lResult[2] ?? $r7lOut, 'R7 mysql warehouse delete: the delete re-reads reservations under lock instead of its stale snapshot');
    }
} finally {
    if (isset($r7lDrivers['mysql'])) $r7lPhp([$r7lRoot . '/race.php', 'mysql', 'teardown', dirname(TEST_CLI_DIR), $r7lDrivers['mysql']]);
    foreach (glob($r7lRoot . '/*') as $r7lFile) unlink($r7lFile);
    rmdir($r7lRoot);
}
$r7lWorkflow = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/OrderWorkflow.php');
assert_true(preg_match('/needs an open transaction.*?Orders::lockWithin\(\$orderId\);\s*\$order = Orders::byId/s', $r7lWorkflow) === 1,
    'R7 cancel vs ship: a status change locks the order before reading it, so it waits for a concurrent shipment');
