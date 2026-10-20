<?php

// Round 7 "stock": allocating arrived stock to waiting backorder lines locks their orders while the caller already holds
// warehouse stock rows (a cancellation frees stock, then allocates). A shipment locks its order first and stock second,
// so on MySQL a plain FOR UPDATE on the waiting order could deadlock or time out the stock change. A waiting order that
// another transaction holds is skipped instead; the next stock increase allocates it. Real second connection.
echo "\n--- R7 stock: backorder allocation skips orders locked by another transaction (MySQL) ---\n";
$r7slRoot = test_fixture_path('r7-stock-lock');
mkdir($r7slRoot);
file_put_contents($r7slRoot . '/lock.php', <<<'PHP'
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
$a->exec("CREATE DATABASE `{$target}`");
$a->exec("USE `{$target}`");
Db::init($a, 'mysql');
(new Migrator($a, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
$a->exec('SET SESSION innodb_lock_wait_timeout = 1');
$b = $connect();
$b->exec("USE `{$target}`");
$a->exec("INSERT INTO categories (store_id, name, slug) VALUES ('default', 'R7SL', 'r7sl')");
$category = (int)$a->lastInsertId();
$a->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97611, 'default', {$category}, 'R7SL', 'r7sl', 'R7SL-SKU', 'simple', 1000, 'eur', 'active', 0, 'backorder')");
$a->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r7sl-wh', 'R7SL', 1, 1)");
$wh = (int)$a->lastInsertId();
$a->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$wh}, 'R7SL-SKU', 0, 0)");
$orders = [];
foreach (['R7SL-1', 'R7SL-2'] as $index => $number) {
    $a->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id, created_at)
        VALUES ('{$number}', 'paid', 'paid', 'R7SL', 'r7sl@example.test', 'card', 2000, 2000, 'eur', 'default', '2026-10-20 10:0{$index}:00')");
    $orders[] = $order = (int)$a->lastInsertId();
    $attrs = json_encode(['_inventory_allocations' => [['warehouse_id' => null, 'qty' => 2, 'status' => 'backorder']]]);
    $a->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, attrs, reserved_qty, backordered_qty)
        VALUES (?, 97611, 'R7SL-SKU', 'R7SL', 2, 1000, 2000, ?, 0, 2)")->execute([$order, $attrs]);
}
$waiting = static fn(int $order): int => (int)$a->query("SELECT backordered_qty FROM order_items WHERE order_id = {$order}")->fetchColumn();
$restock = static function(string $key) use ($wh): string {
    try { Warehouse::adjustStock($wh, 'R7SL-SKU', 2, 'adjustment', 'test', $key); return 'ok'; } catch (Throwable $e) { return 'refused: ' . $e->getMessage(); }
};

// The older order is held by another transaction (e.g. its shipment is running) while 2 units arrive.
$b->beginTransaction();
$b->query("SELECT id FROM orders WHERE id = {$orders[0]} FOR UPDATE")->fetchAll();
$first = $restock('r7sl-1');
$whileLocked = [$waiting($orders[0]), $waiting($orders[1])];
$b->commit();
$second = $restock('r7sl-2');
$reserved = (int)$a->query("SELECT reserved FROM warehouse_stock WHERE warehouse_id = {$wh}")->fetchColumn();
echo json_encode([$first, $whileLocked, $second, [$waiting($orders[0]), $waiting($orders[1])], $reserved]);
PHP);
$r7slPhp = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R7 stock: native backorder allocation lock (set TEST_MYSQL_DSN)\n";
} else {
    $r7slTarget = 'bbe_r7sl_lock_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
    try {
        [$r7slCode, $r7slOut] = $r7slPhp([$r7slRoot . '/lock.php', 'run', dirname(TEST_CLI_DIR), $r7slTarget]);
        $r7slResult = json_decode($r7slOut, true);
        assert_equals([0, 'ok', [2, 0]], [$r7slCode, $r7slResult[0] ?? $r7slOut, $r7slResult[1] ?? null],
            'R7 stock mysql: a restock does not wait for an order another transaction holds; the next waiting order gets the units');
        assert_equals(['ok', [0, 0], 4], array_slice(is_array($r7slResult) ? $r7slResult : [], 2),
            'R7 stock mysql: the skipped order is allocated by the next restock');
    } finally {
        $r7slPhp([$r7slRoot . '/lock.php', 'teardown', dirname(TEST_CLI_DIR), $r7slTarget]);
    }
}
foreach (glob($r7slRoot . '/*') ?: [] as $r7slFile) unlink($r7slFile);
rmdir($r7slRoot);
