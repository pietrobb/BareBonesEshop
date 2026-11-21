<?php
$pdo ??= test_commerce_fixture();

// Review round 21 (R51, money and stock): a set split between two warehouses whose first parcel left refunded nothing
// when the rest was cancelled (R50 counted the whole set as shipped) and its returned part could not be restocked; a
// line with two reservations in one warehouse kept a unit reserved for good after one unit shipped; a cancellation
// released its lines in order-line order and deadlocked against a checkout of the same items on MySQL (500).
echo "\n--- Audit round 51: split sets, shared-warehouse reservations, cancellation lock order ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r51mFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R51 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r51mCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$r51mWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy) VALUES
    (98511, 'default', {$r51mCategory}, 'R51M-PART-A', 'r51m-part-a', 'simple', 'R51M part A', 1000, 'eur', 5, 'active', 'deny'),
    (98512, 'default', {$r51mCategory}, 'R51M-PART-B', 'r51m-part-b', 'simple', 'R51M part B', 1000, 'eur', 5, 'active', 'deny'),
    (98513, 'default', {$r51mCategory}, 'R51M-SET', 'r51m-set', 'bundle', 'R51M set', 3000, 'eur', 5, 'active', 'deny'),
    (98514, 'default', {$r51mCategory}, 'R51M-TWICE', 'r51m-twice', 'simple', 'R51M twice', 1000, 'eur', 5, 'active', 'deny')");
$r51mLine = static fn(int $productId, string $sku, int $price, int $qty = 1): array => ['product_id' => $productId, 'variant_id' => null, 'sku' => $sku,
    'name' => $sku, 'qty' => $qty, 'price' => $price, 'attrs' => []];
$r51mBuyer = ['name' => 'R51M Buyer', 'email' => 'r51m@example.test', 'phone' => '', 'address' => [],
    'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'];
$r51mStock = static fn(int $warehouseId, string $sku): array => array_map('intval', $pdo->query("SELECT qty, reserved FROM warehouse_stock
    WHERE warehouse_id = {$warehouseId} AND sku = " . $pdo->quote($sku))->fetch(PDO::FETCH_NUM) ?: [-1, -1]);

// --- Medium: a set split between two warehouses, its first parcel shipped, the rest cancelled ----------------------
try {
    $pdo->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r51m-wh-a', 'R51M A', -70, 0), ('default', 'r51m-wh-b', 'R51M B', -80, 0)");
    $r51mWhA = (int)$pdo->query("SELECT id FROM warehouses WHERE code = 'r51m-wh-a'")->fetchColumn();
    $r51mWhB = (int)$pdo->query("SELECT id FROM warehouses WHERE code = 'r51m-wh-b'")->fetchColumn();
    $pdo->exec('INSERT INTO bundle_components (bundle_product_id, component_product_id, quantity, sort_order) VALUES (98513, 98511, 1, 1), (98513, 98512, 1, 2)');
    Warehouse::setStock($r51mWhA, 'R51M-PART-A', 5, 'count', 'test', 'r51m-opening-a');
    Warehouse::setStock($r51mWhB, 'R51M-PART-B', 5, 'count', 'test', 'r51m-opening-b');
    $r51mOrder = Orders::create([$r51mLine(98513, 'R51M-SET', 3000)], $r51mBuyer + ['shipping_cost' => 500], 'bank_transfer');
    $r51mParcels = Fulfillment::planOrder($r51mOrder);
    $r51mParcelA = (int)$pdo->query("SELECT shipment_id FROM shipment_items WHERE sku = 'R51M-PART-A' AND shipment_id IN (" . implode(',', array_map('intval', $r51mParcels) ?: [0]) . ')')->fetchColumn();
    Fulfillment::ship($r51mParcelA);
    $r51mItem = Orders::items($r51mOrder)[0];
    assert_equals([[1, 2], 1500], [Fulfillment::shippedShare($r51mItem), Fulfillment::unshippedGross($r51mOrder)],
        'R51 split set: half of the set left; cancelling the rest refunds that half, 15 € of 30 € (was: nothing, R50 counted the set as shipped)');
    OrderWorkflow::transition($r51mOrder, 'cancelled', 'R51 split set: the rest is cancelled');
    assert_equals([[4, 0], [5, 0]], [$r51mStock($r51mWhA, 'R51M-PART-A'), $r51mStock($r51mWhB, 'R51M-PART-B')],
        'R51 split set: the cancellation releases the part that never left; the shipped part stays out');
    Inventory::returnOrderItemQuantity(Orders::items($r51mOrder)[0], 1, 'r51m-rma');
    assert_equals([[5, 0], [5, 0]], [$r51mStock($r51mWhA, 'R51M-PART-A'), $r51mStock($r51mWhB, 'R51M-PART-B')],
        'R51 split set: the returned set restocks the part that came back, and only it (was: "exceeds shipped allocation", nothing restocked)');
} catch (Throwable $e) {
    $r51mFail('split set', $e);
}

// --- Medium (fuzzer): two reservations of one line in one warehouse, one unit shipped, the rest cancelled ----------
try {
    Warehouse::setStock($r51mWarehouse, 'R51M-TWICE', 5, 'count', 'test', 'r51m-opening-twice');
    $r51mTwice = Orders::create([$r51mLine(98514, 'R51M-TWICE', 1000, 2)], $r51mBuyer, 'bank_transfer');
    $r51mTwiceItem = Orders::items($r51mTwice)[0];
    // As a backorder allocated after a restock leaves it: the line's two units reserved by two allocations.
    $r51mAttrs = json_decode((string)$r51mTwiceItem['attrs'], true) ?: [];
    $r51mAllocation = ($r51mAttrs['_inventory_allocations'] ?? $r51mAttrs['_warehouse_allocations'] ?? [])[0] ?? null;
    assert_true(is_array($r51mAllocation) && (int)$r51mAllocation['qty'] === 2, 'R51 shared warehouse: the line holds one allocation of two units');
    $r51mKey = isset($r51mAttrs['_inventory_allocations']) ? '_inventory_allocations' : '_warehouse_allocations';
    $r51mAttrs[$r51mKey] = [['qty' => 1] + $r51mAllocation, ['qty' => 1] + $r51mAllocation];
    $pdo->prepare('UPDATE order_items SET attrs = ? WHERE id = ?')->execute([json_encode($r51mAttrs), (int)$r51mTwiceItem['id']]);
    $r51mParcel = (int)(Fulfillment::planOrder($r51mTwice)[0] ?? 0);
    $pdo->exec("UPDATE shipment_items SET quantity = 1 WHERE shipment_id = {$r51mParcel}");
    Fulfillment::ship($r51mParcel);
    assert_equals([4, 1], $r51mStock($r51mWarehouse, 'R51M-TWICE'), 'R51 shared warehouse: one unit left, one is still reserved');
    Inventory::releaseOrderItem(Orders::items($r51mTwice)[0], $r51mTwice);
    assert_equals([4, 0], $r51mStock($r51mWarehouse, 'R51M-TWICE'),
        'R51 shared warehouse: cancelling the rest releases the unit that never left (was: each allocation subtracted the shipped unit, one unit reserved for good)');
} catch (Throwable $e) {
    $r51mFail('shared warehouse', $e);
}

// --- Medium: cancellation locks its stock in SKU order; a deadlock victim checkout runs again ----------------------
$r51mOrdersSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Orders.php');
assert_true(str_contains($r51mOrdersSource, "Inventory::lockOrderSkus(\$items);\n            \$skus = [];"),
    'R51 deadlock: a cancellation or return locks the stock of all its SKUs in SKU order before it releases line by line');
assert_true(str_contains($r51mOrdersSource, 'if ($attempt >= 3 || !Db::deadlocked($e)) throw $e;')
    && str_contains($r51mOrdersSource, 'return self::createWithin($db, $storeContext, $cartItems, $customer, $paymentMethod, $coupon);'),
    'R51 deadlock: a checkout MySQL picked as a deadlock victim runs its order transaction again (was: 500)');
assert_true(!Db::deadlocked(new PDOException('SQLSTATE[23000]: duplicate')) && !Db::deadlocked(new RuntimeException('x')), 'R51 deadlock: any other error is not retried');

$r51mRoot = test_fixture_path('r51-money-race');
mkdir($r51mRoot);
file_put_contents($r51mRoot . '/race.php', <<<'PHP'
<?php
[, $phase, $root, $target] = $argv;
require $root . '/src/autoload.php';
$connect = static function(): PDO {
    $db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
};
$a = $connect();
if ($phase === 'teardown') { $a->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
$a->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
$a->exec("USE `{$target}`");
Db::init($a, 'mysql');
(new Migrator($a, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
Bootstrap::initStoreContext('/', '', 'default');
$b = $connect();
$b->exec("USE `{$target}`");
$b->exec('SET SESSION innodb_lock_wait_timeout = 1');
$warehouse = (int)$a->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
$a->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$warehouse}, 'R51R-X', 10, 2), ('default', {$warehouse}, 'R51R-Y', 10, 2)");
$line = static fn(string $sku): array => ['id' => 0, 'product_id' => 0, 'variant_id' => null, 'sku' => $sku, 'quantity' => 1, 'digital' => 0,
    'attrs' => json_encode(['_inventory_allocations' => [['warehouse_id' => $warehouse, 'qty' => 1, 'status' => 'reserved']]])];
$out = [];
$a->beginTransaction();
// Its lines in the order [Y, X]: the cancellation holds X (first in SKU order) before it releases Y.
Inventory::lockOrderSkus([$line('R51R-Y'), $line('R51R-X')]);
try { $b->query("SELECT id FROM warehouse_stock WHERE sku = 'R51R-X' FOR UPDATE")->fetchAll(); $out[] = 'taken'; } catch (PDOException $e) { $out[] = 'blocked'; }
$a->rollBack();
// A deadlock victim (MySQL error 1213) is recognised and retried; a lock wait timeout (1205) is not.
$victim = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
$victim->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
$timeout = new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded');
$timeout->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];
$out[] = [Db::deadlocked($victim), Db::deadlocked($timeout)];
echo json_encode($out);
PHP);
$r51mPhp = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
if (getenv('TEST_MYSQL_DSN')) {
    $r51mTarget = 'bbe_r51m_race_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
    try {
        [$r51mCode, $r51mOut] = $r51mPhp([$r51mRoot . '/race.php', 'run', dirname(TEST_CLI_DIR), $r51mTarget]);
        assert_equals([0, ['blocked', [true, false]]], [$r51mCode, json_decode($r51mOut, true) ?? $r51mOut],
            'R51 mysql: a cancellation of lines [Y, X] holds the stock of X before it touches Y, as a checkout does (no lock cycle); a deadlock victim is retried, a lock timeout is not');
    } finally {
        $r51mPhp([$r51mRoot . '/race.php', 'teardown', dirname(TEST_CLI_DIR), $r51mTarget]);
    }
} else {
    print "  [SKIP] R51 mysql cancellation lock order (set TEST_MYSQL_DSN)\n";
}
foreach (glob($r51mRoot . '/*') ?: [] as $r51mFile) unlink($r51mFile);
rmdir($r51mRoot);
