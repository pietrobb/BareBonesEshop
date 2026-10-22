<?php

// Round 8 review (2026-10-21), test quality: removing a row lock (FOR UPDATE) from returns, withdrawals, the feed
// stock sync or the bulk product delete failed no test, because SQLite has no row locks and no older snapshot to
// read. Each case here runs on MySQL/MariaDB with a real second connection: either a writer holds the lock
// uncommitted, or the second caller read before the first one committed (its snapshot is older). Without the
// lock the second caller decides on stale data; with it, it waits and sees the committed state.
echo "\n--- Audit round 8 (test quality): row locks of returns, withdrawals, feed sync and bulk delete ---\n";
$r8kRoot = test_fixture_path('r8-lock-race');
mkdir($r8kRoot);
file_put_contents($r8kRoot . '/race.php', <<<'PHP'
<?php
[, $phase, $root, $target] = $argv;
require $root . '/src/autoload.php';
$connect = static function(int $lockWait) use ($target): PDO {
    $db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($target !== '') $db->exec("USE `{$target}`");
    $db->exec("SET SESSION innodb_lock_wait_timeout = {$lockWait}");
    return $db;
};
if ($phase === 'teardown') {
    (new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: ''))->exec("DROP DATABASE IF EXISTS `{$target}`");
    exit(0);
}
if ($phase === 'cancel') {
    // Second process of the shipment-vs-cancel cases: an admin cancels the order, waiting up to 10 s for locks.
    Db::init($connect(10), 'mysql');
    try { OrderWorkflow::transition((int)$argv[4], 'cancelled'); echo json_encode('ok'); } catch (Throwable $e) { echo json_encode('refused'); }
    exit(0);
}
if ($phase === 'bulk-delete') {
    // Second process of the bulk-delete case: waits up to 10 s for the reservation's row lock.
    Db::init($connect(10), 'mysql');
    echo json_encode(ProductBulk::apply('delete', [(int)$argv[4]])['skipped']);
    exit(0);
}
$admin = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '');
$admin->exec("CREATE DATABASE `{$target}`");
$admin = null;
$a = $connect(1);
Db::init($a, 'mysql');
(new Migrator($a, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
$b = $connect(1);
$try = static function(PDO $db, callable $fn): string {
    Db::init($db, 'mysql');
    try {
        $result = $fn();
        // Rma::create returns [success, id, error], Withdrawal::submit ['ok' => …, 'error' => …].
        return is_array($result) ? json_encode(array_is_list($result) ? [$result[0], $result[2]] : [$result['ok'], $result['error']]) : 'ok';
    } catch (Throwable $e) { return 'refused'; }
};
$a->exec("INSERT INTO categories (store_id, name, slug) VALUES ('default', 'R8K', 'r8k')");
$category = (int)$a->lastInsertId();
$a->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97301, 'default', {$category}, 'R8K', 'r8k', 'R8K-SKU', 'simple', 1000, 'eur', 'active', 10, 'deny')");
$a->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r8k-wh', 'R8K', 1, 1)");
$wh = (int)$a->lastInsertId();
$order = static function(string $number) use ($a): array {
    $a->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id)
        VALUES ('{$number}', 'delivered', 'paid', 'R8K', 'r8k@example.test', 'card', 2000, 2000, 'eur', 'default')");
    $id = (int)$a->lastInsertId();
    $a->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES ({$id}, 97301, 'R8K-SKU', 'R8K', 2, 1000, 2000)");
    return [$id, (int)$a->lastInsertId()];
};
$out = [];

// 1. Rma::create while another writer holds the order (a shipment, cancellation or return in progress): it waits.
[$o1, $i1] = $order('R8K-1');
$a->beginTransaction();
Db::init($a, 'mysql');
Orders::lockWithin($o1);
$out['rma_create'][] = $try($b, static fn() => Rma::create($o1, null, 'r8k', [$i1 => 2]));
$a->rollBack();
$out['rma_create'][] = $try($b, static fn() => Rma::create($o1, null, 'r8k', [$i1 => 2]));

// 2. Withdrawal::submit while another writer holds the order: it waits, then a repeated statement finds nothing open.
[$o2, $i2] = $order('R8K-2');
$row2 = $a->query("SELECT * FROM orders WHERE id = {$o2}")->fetch();
$a->beginTransaction();
Db::init($a, 'mysql');
Orders::lockWithin($o2);
$out['withdrawal'][] = $try($b, static fn() => Withdrawal::submit($row2, 'R8K', [$i2]));
$a->rollBack();
$out['withdrawal'][] = $try($b, static fn() => Withdrawal::submit($row2, 'R8K', [$i2]));
$out['withdrawal'][] = $try($b, static fn() => Withdrawal::submit($row2, 'R8K', [$i2]));
$out['withdrawal'][] = (int)$a->query("SELECT COUNT(*) FROM withdrawal_requests WHERE order_id = {$o2}")->fetchColumn();

// An approved return of both units of a fresh order.
$approved = static function(string $number) use ($order, $a, $try): array {
    [$o, $i] = $order($number);
    Db::init($a, 'mysql');
    [, $rma] = Rma::create($o, null, 'r8k', [$i => 2]);
    Rma::setStatus((int)$rma, 'approved');
    return [(int)$rma, (int)$a->query("SELECT id FROM rma_items WHERE rma_id = " . (int)$rma)->fetchColumn()];
};

// 3. Two receipts of both units: the second caller read the line before the first receipt committed.
[$r3, $ri3] = $approved('R8K-3');
$b->beginTransaction();
$b->query("SELECT received_quantity FROM rma_items WHERE id = {$ri3}")->fetchAll();
$out['rma_receive'][] = $try($a, static fn() => Rma::receive($r3, [$ri3 => ['quantity' => 2, 'disposition' => 'discard']], 'r8k-r1'));
$out['rma_receive'][] = $try($b, static fn() => Rma::receive($r3, [$ri3 => ['quantity' => 2, 'disposition' => 'discard']], 'r8k-r2'));
$b->inTransaction() ? $b->rollBack() : null;
$out['rma_receive'][] = (int)$a->query("SELECT received_quantity FROM rma_items WHERE id = {$ri3}")->fetchColumn();

// 4. A receipt into a return rejected meanwhile: the caller read the return while it was still approved.
[$r4, $ri4] = $approved('R8K-4');
$b->beginTransaction();
$b->query("SELECT status FROM rma_requests WHERE id = {$r4}")->fetchAll();
Db::init($a, 'mysql');
$out['rma_rejected'][] = Rma::setStatus($r4, 'rejected') ? 'ok' : 'refused';
$out['rma_rejected'][] = $try($b, static fn() => Rma::receive($r4, [$ri4 => ['quantity' => 1, 'disposition' => 'discard']], 'r8k-r4'));
$b->inTransaction() ? $b->rollBack() : null;
$out['rma_rejected'][] = (int)$a->query("SELECT received_quantity FROM rma_items WHERE id = {$ri4}")->fetchColumn();

// 5. Two exchanges of the received units at once: the second waits for the first.
[$r5, $ri5] = $approved('R8K-5');
$try($a, static fn() => Rma::receive($r5, [$ri5 => ['quantity' => 2, 'disposition' => 'discard']], 'r8k-r5'));
$a->beginTransaction();
$out['rma_exchange'][] = $try($a, static fn() => Rma::createExchange($r5, $ri5, 2, 97301, null, 'r8k-x1'));
$out['rma_exchange'][] = $try($b, static fn() => Rma::createExchange($r5, $ri5, 1, 97301, null, 'r8k-x2'));
$a->commit();
$out['rma_exchange'][] = $try($b, static fn() => Rma::createExchange($r5, $ri5, 1, 97301, null, 'r8k-x3'));
$out['rma_exchange'][] = (int)$a->query("SELECT COALESCE(SUM(quantity), 0) FROM return_exchanges WHERE rma_item_id = {$ri5}")->fetchColumn();

// 6. A feed sync setting stock below a reservation committed after the sync's transaction first read the row.
$a->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97302, 'default', {$category}, 'R8K feed', 'r8k-feed', 'R8K-FEED', 'simple', 1000, 'eur', 'active', 5, 'deny')");
$a->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$wh}, 'R8K-FEED', 5, 0)");
$b->beginTransaction();
$b->query("SELECT qty, reserved FROM warehouse_stock WHERE sku = 'R8K-FEED'")->fetchAll();
$out['feed_sync'][] = $try($a, static fn() => Warehouse::reserve('R8K-FEED', 3) ? 'reserved' : null);
$out['feed_sync'][] = $try($b, static fn() => Warehouse::bulkSetStock($wh, ['R8K-FEED' => 1]));
$b->inTransaction() ? $b->commit() : null;
$out['feed_sync'][] = array_map('intval', $a->query("SELECT qty, reserved FROM warehouse_stock WHERE sku = 'R8K-FEED'")->fetch(PDO::FETCH_NUM));

// 7. Bulk delete of a product while a checkout reserves its stock: the delete (another process) waits for the
// reservation's commit and then keeps the product, instead of deleting it from under the reservation.
$a->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97303, 'default', {$category}, 'R8K del', 'r8k-del', 'R8K-DEL', 'simple', 1000, 'eur', 'active', 5, 'deny')");
$a->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$wh}, 'R8K-DEL', 5, 0)");
// A runs $first in an open transaction, a second process runs $phase meanwhile; A commits once that process is
// blocked (3 s), and the process's output is returned.
$whileOpen = static function(callable $first, string $phase, int $id) use ($a, $root, $target): mixed {
    $a->beginTransaction();
    Db::init($a, 'mysql');
    $first();
    $process = proc_open([PHP_BINARY, __FILE__, $phase, $root, $target, (string)$id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    usleep(3000000);
    $a->commit();
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return json_decode($output, true) ?? $output;
};
$deleteOut = $whileOpen(static fn() => Warehouse::reserve('R8K-DEL', 1), 'bulk-delete', 97303);
$out['bulk_delete'] = [$deleteOut, (int)$a->query('SELECT COUNT(*) FROM products WHERE id = 97303')->fetchColumn(),
    array_map('intval', $a->query("SELECT qty, reserved FROM warehouse_stock WHERE sku = 'R8K-DEL'")->fetch(PDO::FETCH_NUM) ?: [])];

// 8/9. An admin cancels an order while a shipment of it is being planned (8) or shipped (9). The cancellation waits
// for the order row, then sees the committed shipment: a planned one is cancelled with the order, a shipped unit is
// not released twice.
$paidOrder = static function(string $number, string $sku, int $productId) use ($a, $category, $wh): array {
    $a->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES ({$productId}, 'default', {$category}, '{$sku}', '" . strtolower($sku) . "', '{$sku}', 'simple', 1000, 'eur', 'active', 8, 'deny')");
    $a->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$wh}, '{$sku}', 10, 2)");
    $a->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id)
        VALUES ('{$number}', 'paid', 'paid', 'R8K', 'r8k@example.test', 'card', 2000, 2000, 'eur', 'default')");
    $id = (int)$a->lastInsertId();
    $attrs = json_encode(['_inventory_allocations' => [['warehouse_id' => $wh, 'warehouse_code' => 'r8k-wh', 'qty' => 2, 'status' => 'reserved']]]);
    $a->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, attrs, reserved_qty) VALUES (?, ?, ?, ?, 2, 1000, 2000, ?, 2)")
        ->execute([$id, $productId, $sku, $sku, $attrs]);
    return [$id, (int)$a->lastInsertId()];
};
$state = static function(int $orderId, string $sku) use ($a): array {
    return [
        (string)$a->query("SELECT status FROM orders WHERE id = {$orderId}")->fetchColumn(),
        $a->query("SELECT status FROM shipments WHERE order_id = {$orderId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
        array_map('intval', $a->query("SELECT qty, reserved FROM warehouse_stock WHERE sku = '{$sku}'")->fetch(PDO::FETCH_NUM)),
    ];
};
[$o8, $i8] = $paidOrder('R8K-8', 'R8K-S8', 97308);
$out['cancel_planning'] = [$whileOpen(static fn() => Fulfillment::createShipment($o8, $wh, [['order_item_id' => $i8, 'sku' => 'R8K-S8', 'quantity' => 2]], 'r8k-s8'), 'cancel', $o8),
    ...$state($o8, 'R8K-S8')];
[$o9, $i9] = $paidOrder('R8K-9', 'R8K-S9', 97309);
Db::init($a, 'mysql');
$s9 = Fulfillment::createShipment($o9, $wh, [['order_item_id' => $i9, 'sku' => 'R8K-S9', 'quantity' => 1]], 'r8k-s9');
$out['cancel_shipping'] = [$whileOpen(static fn() => Fulfillment::ship($s9), 'cancel', $o9), ...$state($o9, 'R8K-S9')];

// 10. Stock arrives for a backordered line while a caller's transaction holds an older snapshot (a cancellation frees
// stock and allocates inside its own transaction): the allocation re-reads the line under lock and does not reserve
// the units the line already got a second time.
$a->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97310, 'default', {$category}, 'R8K bo', 'r8k-bo', 'R8K-BO', 'simple', 1000, 'eur', 'active', 0, 'backorder')");
$a->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$wh}, 'R8K-BO', 0, 0)");
$a->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id)
    VALUES ('R8K-10', 'paid', 'paid', 'R8K', 'r8k@example.test', 'card', 2000, 2000, 'eur', 'default')");
$o10 = (int)$a->lastInsertId();
$a->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, attrs, reserved_qty, backordered_qty)
    VALUES (?, 97310, 'R8K-BO', 'R8K bo', 2, 1000, 2000, ?, 0, 2)")->execute([$o10, json_encode(['_inventory_allocations' => [['warehouse_id' => null, 'qty' => 2, 'status' => 'backorder']]])]);
$b->beginTransaction();
$b->query("SELECT backordered_qty FROM order_items WHERE order_id = {$o10}")->fetchAll();
$out['backorder'][] = $try($a, static fn() => Warehouse::adjustStock($wh, 'R8K-BO', 4, 'adjustment', 'test', 'r8k-bo'));
Db::init($b, 'mysql');
$out['backorder'][] = BackorderAllocation::allocate(['R8K-BO']);
$b->commit();
$out['backorder'][] = array_map('intval', $a->query("SELECT qty, reserved FROM warehouse_stock WHERE sku = 'R8K-BO'")->fetch(PDO::FETCH_NUM));
// 10b. The same, but the older snapshot already sees the stock (it arrived without an allocation, e.g. a feed) and
// another allocation reserves it for the line meanwhile: only the locked re-read of the line stops a second reservation.
$a->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97312, 'default', {$category}, 'R8K bo2', 'r8k-bo2', 'R8K-BO2', 'simple', 1000, 'eur', 'active', 4, 'backorder')");
$a->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$wh}, 'R8K-BO2', 4, 0)");
$a->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id)
    VALUES ('R8K-10B', 'paid', 'paid', 'R8K', 'r8k@example.test', 'card', 2000, 2000, 'eur', 'default')");
$o10b = (int)$a->lastInsertId();
$a->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, attrs, reserved_qty, backordered_qty)
    VALUES (?, 97312, 'R8K-BO2', 'R8K bo2', 2, 1000, 2000, ?, 0, 2)")->execute([$o10b, json_encode(['_inventory_allocations' => [['warehouse_id' => null, 'qty' => 2, 'status' => 'backorder']]])]);
$b->beginTransaction();
$b->query("SELECT backordered_qty FROM order_items WHERE order_id = {$o10b}")->fetchAll();
Db::init($a, 'mysql');
$out['backorder_seen'][] = BackorderAllocation::allocate(['R8K-BO2']);
Db::init($b, 'mysql');
$out['backorder_seen'][] = BackorderAllocation::allocate(['R8K-BO2']);
$b->commit();
$out['backorder_seen'][] = array_map('intval', $a->query("SELECT qty, reserved FROM warehouse_stock WHERE sku = 'R8K-BO2'")->fetch(PDO::FETCH_NUM));

// 11. A shipment planned in a transaction that read before stock arrived for its backordered line: the order lines
// are re-read under lock, so the units allocated meanwhile can be shipped (a stale read saw no allocation).
$a->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97311, 'default', {$category}, 'R8K b11', 'r8k-b11', 'R8K-B11', 'simple', 1000, 'eur', 'active', 0, 'backorder')");
$a->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$wh}, 'R8K-B11', 0, 0)");
$a->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id)
    VALUES ('R8K-11', 'paid', 'paid', 'R8K', 'r8k@example.test', 'card', 2000, 2000, 'eur', 'default')");
$o11 = (int)$a->lastInsertId();
$a->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, attrs, reserved_qty, backordered_qty)
    VALUES (?, 97311, 'R8K-B11', 'R8K b11', 2, 1000, 2000, ?, 0, 2)")->execute([$o11, json_encode(['_inventory_allocations' => [['warehouse_id' => null, 'qty' => 2, 'status' => 'backorder']]])]);
$i11 = (int)$a->lastInsertId();
$b->beginTransaction();
$b->query("SELECT * FROM order_items WHERE order_id = {$o11}")->fetchAll();
$out['ship_allocated'][] = $try($a, static fn() => Warehouse::adjustStock($wh, 'R8K-B11', 2, 'adjustment', 'test', 'r8k-b11'));
$out['ship_allocated'][] = $try($b, static fn() => Fulfillment::createShipment($o11, $wh, [['order_item_id' => $i11, 'sku' => 'R8K-B11', 'quantity' => 2]], 'r8k-s11'));
$b->inTransaction() ? $b->commit() : null;
$out['ship_allocated'][] = (int)$a->query("SELECT COALESCE(SUM(si.quantity), 0) FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id WHERE s.order_id = {$o11}")->fetchColumn();
echo json_encode($out);
PHP);
// SQLite has one writer. Orders::lockWithin() takes the write lock with a no-op UPDATE before the order is read, so a
// second writer waits (busy timeout) and then works on the committed state. A plain SELECT there would let it read
// first; its later write then fails ("database is locked", the read snapshot is stale) once the first one commits.
file_put_contents($r8kRoot . '/sqlite.php', <<<'PHP'
<?php
[, $phase, $root, $file] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('PRAGMA journal_mode = WAL');
Db::init($db, 'sqlite');
if ($phase === 'ship') {
    try { Fulfillment::createShipment((int)$argv[4], (int)$argv[6], [['order_item_id' => (int)$argv[5], 'sku' => 'R8Q-SKU', 'quantity' => 1]], 'r8q-b'); echo json_encode('ok'); }
    catch (Throwable $e) { echo json_encode('refused: ' . $e->getMessage()); }
    exit(0);
}
if ($phase === 'refund') {
    try { echo json_encode(PaymentService::bookProviderRefund((int)$argv[4], 'card', 're_r8q', 2000, 'refund.succeeded', 'evt-r8q') ? 'ok' : 'ignored'); }
    catch (Throwable $e) { echo json_encode('refused: ' . $e->getMessage()); }
    exit(0);
}
if ($phase === 'sweep') {
    try { echo json_encode(BackorderAllocation::sweep()); } catch (Throwable $e) { echo json_encode('refused: ' . $e->getMessage()); }
    exit(0);
}
(new Migrator($db, $root . '/migrations', dirname($file) . '/sqlite-migrate.lock'))->apply();
$db->exec("INSERT INTO categories (store_id, name, slug) VALUES ('default', 'R8Q', 'r8q')");
$category = (int)$db->lastInsertId();
$db->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r8q-wh', 'R8Q', 1, 1)");
$wh = (int)$db->lastInsertId();
$db->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97401, 'default', {$category}, 'R8Q', 'r8q', 'R8Q-SKU', 'simple', 1000, 'eur', 'active', 8, 'deny'),
           (97402, 'default', {$category}, 'R8Q bo', 'r8q-bo', 'R8Q-BO', 'simple', 1000, 'eur', 'active', 2, 'backorder')");
$db->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$wh}, 'R8Q-SKU', 10, 2), ('default', {$wh}, 'R8Q-BO', 2, 0)");
$order = static function(string $number, string $sku, int $product, array $allocation, int $reserved) use ($db): array {
    $db->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id)
        VALUES ('{$number}', 'paid', 'paid', 'R8Q', 'r8q@example.test', 'card', 2000, 2000, 'eur', 'default')");
    $id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, attrs, reserved_qty, backordered_qty) VALUES (?, ?, ?, ?, 2, 1000, 2000, ?, ?, ?)")
        ->execute([$id, $product, $sku, $sku, json_encode(['_inventory_allocations' => [$allocation]]), $reserved, 2 - $reserved]);
    return [$id, (int)$db->lastInsertId()];
};
[$shipOrder, $shipItem] = $order('R8Q-1', 'R8Q-SKU', 97401, ['warehouse_id' => $wh, 'warehouse_code' => 'r8q-wh', 'qty' => 2, 'status' => 'reserved'], 2);
[$boOrder] = $order('R8Q-2', 'R8Q-BO', 97402, ['warehouse_id' => null, 'qty' => 2, 'status' => 'backorder'], 0);
[$refundOrder] = $order('R8Q-3', 'R8Q-SKU', 97401, ['warehouse_id' => $wh, 'warehouse_code' => 'r8q-wh', 'qty' => 2, 'status' => 'reserved'], 2);
// The first writer holds the order while the second process starts its work; it commits after 2 s.
$whileHeld = static function(int $held, array $args) use ($db, $root, $file): mixed {
    $db->beginTransaction();
    $db->exec("UPDATE orders SET updated_at = updated_at WHERE id = {$held}");
    $process = proc_open([PHP_BINARY, __FILE__, ...[$args[0], $root, $file], ...array_slice($args, 1)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    usleep(2000000);
    $db->commit();
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return json_decode($output, true) ?? $output;
};
$shipped = $whileHeld($shipOrder, ['ship', (string)$shipOrder, (string)$shipItem, (string)$wh]);
$swept = $whileHeld($shipOrder, ['sweep']);
$refunded = $whileHeld($refundOrder, ['refund', (string)$refundOrder]);
echo json_encode([$shipped, $swept, (int)$db->query("SELECT backordered_qty FROM order_items WHERE order_id = {$boOrder}")->fetchColumn(),
    $refunded, (string)$db->query("SELECT payment_status FROM orders WHERE id = {$refundOrder}")->fetchColumn()]);
PHP);
$r8kPhp = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
[$r8kCode, $r8kOut] = $r8kPhp([$r8kRoot . '/sqlite.php', 'run', dirname(TEST_CLI_DIR), $r8kRoot . '/writers.sqlite']);
assert_equals([0, ['ok', 2, 0, 'ok', 'refunded']], [$r8kCode, json_decode($r8kOut, true) ?? $r8kOut],
    'R8 locks sqlite: a shipment, a backorder sweep and a provider refund started while another writer holds the order wait for it and then succeed');
if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R8 native row locks of returns, withdrawals, feed sync and bulk delete (set TEST_MYSQL_DSN)\n";
} else {
    $r8kDb = 'bbe_r8k_lock_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
    try {
        [$r8kCode, $r8kOut] = $r8kPhp([$r8kRoot . '/race.php', 'run', dirname(TEST_CLI_DIR), $r8kDb]);
        $r8k = json_decode($r8kOut, true);
        assert_equals(0, $r8kCode, 'R8 locks: the race script ran (' . substr($r8kOut, 0, 400) . ')');
        assert_equals(['refused', '[true,null]'], $r8k['rma_create'] ?? $r8kOut,
            'R8 locks: Rma::create waits for the order row held by another writer, and succeeds once it is released');
        assert_equals(['refused', '[true,null]', '[false,"already"]', 1], $r8k['withdrawal'] ?? $r8kOut,
            'R8 locks: Withdrawal::submit waits for the order row; afterwards one statement is recorded and a repeat finds nothing open');
        assert_equals(['ok', 'refused', 2], $r8k['rma_receive'] ?? $r8kOut,
            'R8 locks: a second receipt that read the return line before the first committed is refused (no 4 of 2 received)');
        assert_equals(['ok', 'refused', 0], $r8k['rma_rejected'] ?? $r8kOut,
            'R8 locks: a receipt into a return rejected after the caller read it is refused (the return row is re-read under lock)');
        assert_equals(['ok', 'refused', 'refused', 2], $r8k['rma_exchange'] ?? $r8kOut,
            'R8 locks: a concurrent exchange waits for the first; only the 2 received units are exchanged');
        assert_equals(['ok', 'ok', [3, 3]], $r8k['feed_sync'] ?? $r8kOut,
            'R8 locks: a feed sync below a reservation committed after the sync first read the row is clamped to the reservation');
        assert_equals([['R8K-DEL'], 1, [5, 1]], $r8k['bulk_delete'] ?? $r8kOut,
            'R8 locks: a bulk delete waiting for a reservation keeps the reserved product and its stock');
        assert_equals(['ok', 'cancelled', ['cancelled'], [10, 0]], $r8k['cancel_planning'] ?? $r8kOut,
            'R8 locks: a cancellation waiting for a shipment being planned cancels that shipment too and releases both units');
        assert_equals(['ok', 'cancelled', ['shipped'], [9, 0]], $r8k['cancel_shipping'] ?? $r8kOut,
            'R8 locks: a cancellation waiting for a shipment being shipped releases only the unit that did not ship');
        assert_equals(['ok', 0, [4, 2]], $r8k['backorder'] ?? $r8kOut,
            'R8 locks: an allocation inside a transaction with an older snapshot does not reserve an already allocated backorder line again');
        assert_equals([2, 0, [4, 2]], $r8k['backorder_seen'] ?? $r8kOut,
            'R8 locks: an older snapshot that already saw the stock does not reserve a backorder line allocated meanwhile a second time');
        assert_equals(['ok', 'ok', 2], $r8k['ship_allocated'] ?? $r8kOut,
            'R8 locks: a shipment in a transaction that read before stock was allocated to its backordered line ships the allocated units');
    } finally {
        $r8kPhp([$r8kRoot . '/race.php', 'teardown', dirname(TEST_CLI_DIR), $r8kDb]);
    }
}
foreach (glob($r8kRoot . '/*') ?: [] as $r8kFile) unlink($r8kFile);
rmdir($r8kRoot);
