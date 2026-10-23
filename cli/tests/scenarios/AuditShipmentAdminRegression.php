<?php

echo "\n--- Audit A12.8: shipments and tracking in the admin order detail (partial shipments, limits, tracking, store scope, CSRF/role, audit) ---\n";

foreach (['owner' => true, 'manager' => true, 'fulfillment' => true, 'editor' => false, 'viewer' => false] as $a128Role => $a128Allowed) {
    $a128Session = $_SESSION ?? [];
    $_SESSION = ['admin_role' => $a128Role];
    assert_equals($a128Allowed, Security::adminCan('orders.ship'), "A12.8 role: {$a128Role} " . ($a128Allowed ? 'may' : 'may not') . ' create and ship shipments');
    $_SESSION = $a128Session;
}

$a128Root = test_fixture_path('admin-shipments');
mkdir($a128Root);
$a128Fixture = $a128Root . '/request.php';
$a128Source = <<<'PHP'
<?php
[, $driver, $phase, $store, $action, $root, $target] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Orders.php';
require $root . '/src/Entrypoints/Admin/Request.php';
class_exists('Security');
// The fixture has no config.php: the stock-alert shutdown flush would log "operational log unavailable" to stderr.
ini_set('error_log', dirname(__FILE__) . '/php-error.log');
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
final class A128Pdo extends PDO {
    public int $depth = 0;
    public function beginTransaction(): bool {
        if ($this->depth++ === 0) return parent::beginTransaction();
        $this->exec('SAVEPOINT a128_' . $this->depth);
        return true;
    }
    public function commit(): bool {
        if (--$this->depth === 0) return parent::commit();
        $this->exec('RELEASE SAVEPOINT a128_' . ($this->depth + 1));
        return true;
    }
    public function rollBack(): bool {
        if (--$this->depth === 0) return parent::rollBack();
        $this->exec('ROLLBACK TO SAVEPOINT a128_' . ($this->depth + 1));
        return true;
    }
    public function inTransaction(): bool { return $this->depth > 0; }
}
$db = $driver === 'mysql'
    ? new A128Pdo(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new A128Pdo('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
$idOf = static function(string $table, string $column, string $value) use ($db): int {
    $stmt = $db->prepare("SELECT id FROM {$table} WHERE {$column} = ?");
    $stmt->execute([$value]);
    return (int)$stmt->fetchColumn();
};
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate-' . $driver . '.lock'))->apply();
    foreach (['default', 'cz'] as $owner) {
        $warehouse = $db->prepare('INSERT INTO warehouses (store_id, code, name, is_default) VALUES (?, ?, ?, 0)');
        $warehouse->execute([$owner, "a128main-{$owner}", "A128MAIN-{$owner}-NAME"]);
        $warehouse->execute([$owner, "a128side-{$owner}", "A128SIDE-{$owner}-NAME"]);
        $main = $idOf('warehouses', 'code', "a128main-{$owner}");
        $side = $idOf('warehouses', 'code', "a128side-{$owner}");
        $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute([$owner, "a128cat-{$owner}", 'Cat', "a128cat-{$owner}"]);
        $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, 1000, 8, 'active')")
            ->execute([$owner, $idOf('categories', 'slug', "a128cat-{$owner}"), "A128P-{$owner}", "a128p-{$owner}", "A128P-{$owner}-NAME"]);
        $stock = $db->prepare('INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES (?, ?, ?, ?, ?)');
        $stock->execute([$owner, $main, "A128P-{$owner}", 10, 6]);
        $stock->execute([$owner, $side, "A128P-{$owner}", 4, 2]);
        $order = $db->prepare("INSERT INTO orders (store_id, order_number, customer_name, customer_email, payment_method, subtotal, total, currency, status, payment_status)
            VALUES (?, ?, ?, ?, 'bank_transfer', ?, ?, 'eur', ?, ?)");
        $item = $db->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, attrs) VALUES (?, ?, ?, ?, ?, 1000, ?, ?)');
        $product = $idOf('products', 'sku', "A128P-{$owner}");
        foreach ([["A128-{$owner}", 'paid', [[$main, 5], [$side, 2]]], ["A128-PEND-{$owner}", 'pending', [[$main, 1]]]] as [$number, $status, $allocations]) {
            $quantity = array_sum(array_column($allocations, 1));
            $order->execute([$owner, $number, "A128BUYER-{$owner}-NAME", "{$owner}@example.test", $quantity * 1000, $quantity * 1000, $status, $status === 'paid' ? 'paid' : 'pending']);
            $item->execute([$idOf('orders', 'order_number', $number), $product, "A128P-{$owner}", "A128ITEM-{$owner}-NAME", $quantity, $quantity * 1000,
                json_encode(['_inventory_allocations' => array_map(static fn(array $a): array => ['warehouse_id' => $a[0], 'qty' => $a[1], 'status' => 'reserved'], $allocations)])]);
        }
        // One planned shipment of 1 piece from the main warehouse already exists.
        $orderId = $idOf('orders', 'order_number', "A128-{$owner}");
        $db->prepare("INSERT INTO shipments (store_id, order_id, warehouse_id, status, idempotency_key) VALUES (?, ?, ?, 'planned', ?)")->execute([$owner, $orderId, $main, "setup:{$owner}"]);
        $db->prepare('INSERT INTO shipment_items (shipment_id, order_item_id, sku, quantity) VALUES (?, (SELECT id FROM order_items WHERE order_id = ?), ?, 1)')
            ->execute([$idOf('shipments', 'idempotency_key', "setup:{$owner}"), $orderId, "A128P-{$owner}"]);
    }
    exit(0);
}

$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['default'], ['id'=>'cz', 'url_prefix'=>'/cz', 'domains'=>[], 'catalog_scope'=>'cz', 'provider_scope'=>'cz']);
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('en');
$foreign = $store === 'default' ? 'cz' : 'default';
$own = $store;
$perms = $action === 'viewer_create' ? ['orders.view', 'orders.edit'] : ['orders.view', 'orders.edit', 'orders.ship'];
$_SESSION = ['admin_role'=>'fulfillment', 'admin_user'=>'a128', 'admin_identity_id'=>1, 'admin_store_permissions'=>[$store=>$perms], 'csrf_token'=>'a128-token'];
$snapshot = static function() use ($db): array {
    return [
        'shipments' => $db->query('SELECT id, store_id, order_id, warehouse_id, status, carrier, tracking_number, tracking_url, idempotency_key FROM shipments ORDER BY id')->fetchAll(),
        'shipment_items' => $db->query('SELECT s.store_id, si.shipment_id, si.sku, si.quantity, si.status FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id ORDER BY si.id')->fetchAll(),
        'warehouse_stock' => $db->query('SELECT ws.store_id, w.code, ws.sku, ws.qty, ws.reserved FROM warehouse_stock ws JOIN warehouses w ON w.id = ws.warehouse_id ORDER BY ws.warehouse_id, ws.sku')->fetchAll(),
        'stock_movements' => $db->query("SELECT store_id, warehouse_id, sku, movement_type, quantity_delta FROM stock_movements WHERE movement_type = 'shipment' ORDER BY id")->fetchAll(),
        'orders' => $db->query('SELECT store_id, order_number, status FROM orders ORDER BY id')->fetchAll(),
        'order_items' => $db->query('SELECT o.store_id, o.order_number, oi.fulfillment_status FROM order_items oi JOIN orders o ON o.id = oi.order_id ORDER BY oi.id')->fetchAll(),
        'audit' => $db->query("SELECT entity, entity_id, old_value, new_value, store_id FROM audit_log WHERE entity = 'shipment' ORDER BY id")->fetchAll(),
    ];
};
$orderId = static fn(?string $owner = null, string $prefix = 'A128-'): int => $idOf('orders', 'order_number', $prefix . ($owner ?? $own));
$itemId = static function(?string $owner = null) use ($db, $orderId, $own): int {
    $stmt = $db->prepare('SELECT id FROM order_items WHERE order_id = ?');
    $stmt->execute([$orderId($owner ?? $own)]);
    return (int)$stmt->fetchColumn();
};
$main = (string)$idOf('warehouses', 'code', "a128main-{$own}");
$side = (string)$idOf('warehouses', 'code', "a128side-{$own}");
$setupShipment = static fn(?string $owner = null): int => $idOf('shipments', 'idempotency_key', 'setup:' . ($owner ?? $own));
$tracking = ['carrier'=>'Packeta', 'tracking_number'=>'Z 123 456', 'tracking_url'=>'https://tracking.example.test/Z123456'];
$create = static fn(array $qty, array $fields = []): array => array_replace(['csrf_token'=>'a128-token', 'shipment_key'=>str_repeat('a1', 16), 'warehouse'=>$main, 'qty'=>$qty], $fields);
$line = static fn(?string $owner = null): string => $itemId($owner) . ':A128P-' . ($owner ?? $own);
$db->beginTransaction();
$before = $snapshot();
$extra = [];
ob_start();
register_shutdown_function(static function() use ($db, $snapshot, &$before, &$extra): void {
    $output = ob_get_contents();
    if ($extra instanceof Closure) $extra = $extra();
    ob_clean();
    $result = ['output'=>$output, 'http_status'=>http_response_code(), 'before'=>$before, 'after'=>$snapshot(), 'flash'=>$_SESSION['flash'] ?? null, 'extra'=>$extra];
    $db->depth = 1;
    $db->rollBack();
    print json_encode($result, JSON_THROW_ON_ERROR);
    ob_end_flush();
});
$coreShipped = static function() use ($orderId, $itemId, $own, $main): int {
    $id = Fulfillment::createShipment($orderId(), (int)$main, [['order_item_id'=>$itemId(), 'sku'=>"A128P-{$own}", 'quantity'=>2]], 'core:a128');
    Fulfillment::ship($id, 'GLS', 'OLD-1', null);
    return $id;
};
// The consumer withdrew from the order (storefront withdrawal form) after it was paid.
$withdraw = static function() use ($db, $orderId, $itemId, $own): void {
    $db->prepare('INSERT INTO withdrawal_requests (store_id, order_id, order_number, customer_name, customer_email, items, reason, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$own, $orderId(), "A128-{$own}", "A128BUYER-{$own}-NAME", "{$own}@example.test", json_encode([$itemId() => 1]), "A128REASON-{$own}", '2026-10-23 10:00:00']);
};
$_GET = ['id' => $orderId()];
match ($action) {
    'withdrawn_page' => [$withdraw(), pageOrder()],
    'withdrawn_ship' => [$withdraw(), $_POST = ['csrf_token'=>'a128-token', 'shipment_id'=>(string)$setupShipment()] + $tracking, handlePost('order', 'shipment_ship')],
    'withdrawn_create_ship' => [$withdraw(), $_POST = $create([$line() => '1'], ['ship_now'=>'1']), handlePost('order', 'shipment_create')],
    'withdrawn_status' => [$withdraw(), $_POST = ['csrf_token'=>'a128-token', 'new_status'=>'shipped'], handlePost('order', 'status')],
    'withdrawn_bulk' => [$withdraw(), $extra = static fn(): array => OrderWorkflow::bulkTransition([$orderId()], 'shipped', null, false)],
    'withdrawn_ship_ack' => [$withdraw(), $_POST = ['csrf_token'=>'a128-token', 'shipment_id'=>(string)$setupShipment(), 'withdrawal_ack'=>'1'] + $tracking, handlePost('order', 'shipment_ship')],
    'page' => [$extra = static fn(): array => ['shippable' => Fulfillment::shippable($orderId())], pageOrder()],
    'create_ship' => [$_POST = $create([$line() => '3'], $tracking + ['ship_now'=>'1']), handlePost('order', 'shipment_create')],
    'create_planned' => [$_POST = $create([$line() => '2']), handlePost('order', 'shipment_create')],
    'create_side' => [$_POST = $create([$line() => '2'], ['warehouse'=>$side, 'ship_now'=>'1']), handlePost('order', 'shipment_create')],
    'create_replay' => [$_POST = $create([$line() => '2']), Fulfillment::createShipment($orderId(), (int)$main,
        [['order_item_id'=>$itemId(), 'sku'=>"A128P-{$own}", 'quantity'=>2]], "admin:{$orderId()}:" . str_repeat('a1', 16)), handlePost('order', 'shipment_create')],
    'ship_planned' => [$_POST = ['csrf_token'=>'a128-token', 'shipment_id'=>(string)$setupShipment()] + $tracking, handlePost('order', 'shipment_ship')],
    'cancel_planned' => [$_POST = ['csrf_token'=>'a128-token', 'shipment_id'=>(string)$setupShipment()],
        $extra = static fn(): array => ['shippable' => Fulfillment::shippable($orderId())], handlePost('order', 'shipment_cancel')],
    'tracking_update' => [$_POST = ['csrf_token'=>'a128-token', 'shipment_id'=>(string)$coreShipped()] + $tracking, handlePost('order', 'shipment_tracking')],
    'order_shipped_after_partial' => [$extra = (static function() use ($orderId, $coreShipped): array {
        $coreShipped();
        try {
            // The stock side effect of the order status "shipped" (OrderWorkflow::handleShipped).
            Fulfillment::shipOrder($orderId());
            return ['transition' => 'ok', 'api' => array_map(static fn(array $s): array => ApiV1::shipmentDto($s, []), Fulfillment::shipments($orderId()))];
        } catch (Throwable $e) {
            return ['transition' => $e->getMessage()];
        }
    })()],
    'create_over' => [$_POST = $create([$line() => '5']), handlePost('order', 'shipment_create')],
    'create_zero' => [$_POST = $create([$line() => '0']), handlePost('order', 'shipment_create')],
    'create_negative' => [$_POST = $create([$line() => '-1']), handlePost('order', 'shipment_create')],
    'create_foreign_line' => [$_POST = $create([$line($foreign) => '1']), handlePost('order', 'shipment_create')],
    'create_foreign_warehouse' => [$_POST = $create([$line() => '1'], ['warehouse'=>(string)$idOf('warehouses', 'code', "a128main-{$foreign}")]), handlePost('order', 'shipment_create')],
    'create_bad_key' => [$_POST = $create([$line() => '1'], ['shipment_key'=>'x']), handlePost('order', 'shipment_create')],
    'create_bad_url' => [$_POST = $create([$line() => '1'], ['carrier'=>'X', 'tracking_url'=>'javascript:alert(1)', 'ship_now'=>'1']), handlePost('order', 'shipment_create')],
    'create_pending_order' => [$_GET = ['id'=>$orderId(null, 'A128-PEND-')], $_POST = $create([$line() => '1']), handlePost('order', 'shipment_create')],
    'create_foreign_order' => [$_GET = ['id'=>$orderId($foreign)], $_POST = $create([$line($foreign) => '1']), handlePost('order', 'shipment_create')],
    'create_no_csrf' => [$_POST = array_diff_key($create([$line() => '1']), ['csrf_token'=>1]), handlePost('order', 'shipment_create')],
    'ship_foreign_shipment' => [$_POST = ['csrf_token'=>'a128-token', 'shipment_id'=>(string)$setupShipment($foreign)] + $tracking, handlePost('order', 'shipment_ship')],
    'cancel_shipped' => [$_POST = ['csrf_token'=>'a128-token', 'shipment_id'=>(string)$coreShipped()], $before = $snapshot(), handlePost('order', 'shipment_cancel')],
    'viewer_create' => [$_POST = $create([$line() => '1']), handlePost('order', 'shipment_create')],
};
PHP;
file_put_contents($a128Fixture, $a128Source);
$a128Run = static function(array $args) use ($a128Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a128Fixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
// Refused actions: expected flash fragment; shipments, stock, ledger, order state and audit log must stay byte-identical.
$a128Refused = [
    'create_over' => 'exceeds what is left', 'create_zero' => 'at least one item', 'create_negative' => 'Invalid shipment form',
    'create_foreign_line' => 'Invalid shipment form', 'create_foreign_warehouse' => 'Invalid shipment form', 'create_bad_key' => 'Invalid shipment form',
    'create_bad_url' => 'link must start with http', 'create_pending_order' => 'only be created and shipped for paid', 'create_foreign_order' => 'order does not exist',
    'create_no_csrf' => 'CSRF', 'ship_foreign_shipment' => 'shipment does not exist', 'cancel_shipped' => 'already been processed',
    // R9: goods the consumer withdrew from are not shipped without an explicit acknowledgement.
    'withdrawn_ship' => 'Confirm the warning', 'withdrawn_create_ship' => 'Confirm the warning', 'withdrawn_status' => 'Confirm the warning',
];
$a128Actions = array_merge(['page', 'create_ship', 'create_planned', 'create_side', 'create_replay', 'ship_planned', 'cancel_planned', 'tracking_update',
    'order_shipped_after_partial', 'viewer_create', 'withdrawn_page', 'withdrawn_bulk', 'withdrawn_ship_ack'], array_keys($a128Refused));
$a128Drivers = ['sqlite' => $a128Root . '/shipments.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a128Drivers['mysql'] = 'bbe_a128_shipments_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A12.8 native shipments (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a128Drivers as $a128Driver => $a128Target) {
        [$a128Exit, , $a128Stderr] = $a128Run([$a128Driver, 'setup', 'default', 'none', dirname(TEST_CLI_DIR), $a128Target]);
        assert_equals([0, ''], [$a128Exit, $a128Stderr], "A12.8 {$a128Driver}: migrated fixture with two warehouses, a paid split order and a planned shipment per store");
        foreach (['default', 'cz'] as $a128Store) {
            $a128Foreign = $a128Store === 'default' ? 'cz' : 'default';
            foreach ($a128Actions as $a128Action) {
                $a128Label = "A12.8 {$a128Driver}/{$a128Store}/{$a128Action}";
                [$a128Exit, $a128Stdout, $a128Stderr] = $a128Run([$a128Driver, 'run', $a128Store, $a128Action, dirname(TEST_CLI_DIR), $a128Target]);
                $a128Expected = $a128Action === 'viewer_create' ? 255 : 0;
                assert_equals([true, ''], [in_array($a128Exit, [0, $a128Expected], true), $a128Stderr], "{$a128Label}: actual handler exits without errors");
                $a128Result = json_decode((string)$a128Stdout, true) ?: ['output'=>'', 'http_status'=>0, 'before'=>[], 'after'=>[], 'flash'=>null, 'extra'=>[]];
                $a128Output = (string)$a128Result['output'];
                $a128Flash = $a128Result['flash'] ?? [];
                $a128Before = $a128Result['before'];
                $a128After = $a128Result['after'];
                $a128Own = static fn(array $rows, string $store): array => array_values(array_filter($rows, static fn(array $r): bool => $r['store_id'] === $store));
                assert_true(!preg_match('/A128[A-Z]+-' . $a128Foreign . '-NAME/', $a128Output), "{$a128Label}: no foreign store data rendered");
                foreach (['shipments', 'shipment_items', 'warehouse_stock', 'stock_movements', 'orders', 'order_items', 'audit'] as $a128Table) {
                    assert_equals($a128Own($a128Before[$a128Table] ?? [], $a128Foreign), $a128Own($a128After[$a128Table] ?? [], $a128Foreign), "{$a128Label}: foreign store {$a128Table} unchanged");
                }
                if (isset($a128Refused[$a128Action]) || $a128Action === 'viewer_create') {
                    if ($a128Action === 'viewer_create') {
                        assert_equals(403, $a128Result['http_status'], "{$a128Label}: role without orders.ship gets 403");
                    } else {
                        assert_equals(['err', true], [$a128Flash['type'] ?? null, str_contains((string)($a128Flash['msg'] ?? ''), $a128Refused[$a128Action])],
                            "{$a128Label}: refused with a specific error (" . ($a128Flash['msg'] ?? '') . ')');
                    }
                    assert_equals($a128Before, $a128After, "{$a128Label}: shipments, stock, ledger, orders and audit log unchanged");
                    continue;
                }
                $a128Shipments = $a128Own($a128After['shipments'] ?? [], $a128Store);
                $a128New = array_values(array_slice($a128Shipments, count($a128Own($a128Before['shipments'] ?? [], $a128Store))));
                $a128Items = array_column(array_map(static fn(array $r): array => ['k' => $r['shipment_id'] . ':' . $r['status'], 'q' => (int)$r['quantity']],
                    $a128Own($a128After['shipment_items'] ?? [], $a128Store)), 'q', 'k');
                $a128Stock = array_column($a128Own($a128After['warehouse_stock'] ?? [], $a128Store), null, 'code');
                $a128Moves = $a128Own($a128After['stock_movements'] ?? [], $a128Store);
                $a128Fulfil = array_column($a128Own($a128After['order_items'] ?? [], $a128Store), 'fulfillment_status', 'order_number');
                $a128Status = array_column($a128Own($a128After['orders'] ?? [], $a128Store), 'status', 'order_number');
                $a128NewAudit = array_slice($a128After['audit'] ?? [], count($a128Before['audit'] ?? []));
                $a128StockOf = static fn(string $kind): array => [(int)($a128Stock["a128{$kind}-{$a128Store}"]['qty'] ?? -1), (int)($a128Stock["a128{$kind}-{$a128Store}"]['reserved'] ?? -1)];
                if (!in_array($a128Action, ['page', 'order_shipped_after_partial', 'withdrawn_page', 'withdrawn_bulk'], true)) {
                    assert_equals('ok', $a128Flash['type'] ?? null, "{$a128Label}: action succeeds (" . ($a128Flash['msg'] ?? '') . ')');
                }
                if (!in_array($a128Action, ['page', 'create_replay', 'order_shipped_after_partial', 'withdrawn_page', 'withdrawn_bulk'], true)) {
                    assert_equals([$a128Store], array_values(array_unique(array_column($a128NewAudit, 'store_id'))), "{$a128Label}: change audited in the active store");
                }
                match ($a128Action) {
                    'page' => assert_true(str_contains($a128Output, 'id="shipments"') && substr_count($a128Output, 'data-shipment-create') === 2
                        && str_contains($a128Output, 'max="4"') && str_contains($a128Output, 'max="2"') && str_contains($a128Output, "A128MAIN-{$a128Store}-NAME")
                        && str_contains($a128Output, "A128SIDE-{$a128Store}-NAME") && str_contains($a128Output, 'action=shipment_ship') && str_contains($a128Output, 'action=shipment_cancel')
                        && preg_match('/name="shipment_key" value="[a-f0-9]{32}"/', $a128Output) === 1,
                        "{$a128Label}: shipments card lists the planned shipment and per-warehouse forms limited to the open quantity"),
                    'create_ship' => assert_equals([['shipped', 'Packeta', 'Z 123 456', 'https://tracking.example.test/Z123456'], 3, [7, 3], 1, 'partially_fulfilled', 'paid'],
                        [[$a128New[0]['status'] ?? null, $a128New[0]['carrier'] ?? null, $a128New[0]['tracking_number'] ?? null, $a128New[0]['tracking_url'] ?? null],
                            $a128Items[($a128New[0]['id'] ?? 0) . ':shipped'] ?? 0, $a128StockOf('main'), count($a128Moves), $a128Fulfil["A128-{$a128Store}"] ?? null, $a128Status["A128-{$a128Store}"] ?? null],
                        "{$a128Label}: partial shipment shipped with tracking, stock and ledger booked, order stays paid"),
                    'create_planned' => assert_equals(['planned', 2, [10, 6], 0], [$a128New[0]['status'] ?? null, $a128Items[($a128New[0]['id'] ?? 0) . ':planned'] ?? 0, $a128StockOf('main'), count($a128Moves)],
                        "{$a128Label}: planned shipment does not touch stock"),
                    'create_side' => assert_equals(['shipped', 2, [2, 0], [10, 6]], [$a128New[0]['status'] ?? null, $a128Items[($a128New[0]['id'] ?? 0) . ':shipped'] ?? 0, $a128StockOf('side'), $a128StockOf('main')],
                        "{$a128Label}: shipment from the second warehouse ships only from that warehouse"),
                    'create_replay' => assert_equals([1, true], [count($a128New), str_contains((string)($a128Flash['msg'] ?? ''), 'already been processed')],
                        "{$a128Label}: resubmitted form creates no second shipment"),
                    'ship_planned' => assert_equals([['shipped', 'Packeta', 'https://tracking.example.test/Z123456'], [9, 5]],
                        [[$a128Shipments[0]['status'] ?? null, $a128Shipments[0]['carrier'] ?? null, $a128Shipments[0]['tracking_url'] ?? null], $a128StockOf('main')],
                        "{$a128Label}: planned shipment shipped with tracking"),
                    'withdrawn_ship_ack' => assert_equals(['shipped', [9, 5]], [$a128Shipments[0]['status'] ?? null, $a128StockOf('main')],
                        "{$a128Label}: with the acknowledgement the withdrawn order ships"),
                    'withdrawn_page' => assert_true(str_contains($a128Output, 'data-withdrawals') && str_contains($a128Output, "A128REASON-{$a128Store}")
                        && str_contains($a128Output, "A128ITEM-{$a128Store}-NAME (1×)") && str_contains($a128Output, '2026-10-23 10:00:00 UTC')
                        && substr_count($a128Output, 'data-withdrawal-ack') === 4,
                        "{$a128Label}: order detail shows the withdrawal (time, lines, reason) and every ship form asks for the acknowledgement ("
                            . substr_count($a128Output, 'data-withdrawal-ack') . ')'),
                    'withdrawn_bulk' => assert_equals([[], 'withdrawn', $a128Before['orders'] ?? null],
                        [$a128Result['extra']['changed'] ?? null, array_values($a128Result['extra']['rejected'] ?? [])[0] ?? null, $a128After['orders'] ?? null],
                        "{$a128Label}: bulk status 'shipped' skips the withdrawn order"),
                    'cancel_planned' => assert_equals(['cancelled', [10, 6], 5], [$a128Shipments[0]['status'] ?? null, $a128StockOf('main'),
                        array_column(array_merge(...array_column($a128Result['extra']['shippable'] ?? [], 'lines')), 'remaining')[0] ?? null],
                        "{$a128Label}: cancelled shipment frees its quantity"),
                    'tracking_update' => assert_equals([['Packeta', 'Z 123 456', 'https://tracking.example.test/Z123456'], ['carrier' => 'GLS', 'tracking_number' => 'OLD-1', 'tracking_url' => null]],
                        [[end($a128Shipments)['carrier'] ?? null, end($a128Shipments)['tracking_number'] ?? null, end($a128Shipments)['tracking_url'] ?? null],
                            json_decode((string)(end($a128NewAudit)['old_value'] ?? ''), true)], "{$a128Label}: tracking corrected, old values kept in the audit log"),
                    // Main warehouse keeps 1 reserved piece for the pending order of the same store.
                    'order_shipped_after_partial' => assert_equals(['ok', 'paid', 'fulfilled', [5, 1], [2, 0], true],
                        [$a128Result['extra']['transition'] ?? null, $a128Status["A128-{$a128Store}"] ?? null, $a128Fulfil["A128-{$a128Store}"] ?? null, $a128StockOf('main'), $a128StockOf('side'),
                            array_key_exists('tracking_url', $a128Result['extra']['api'][0] ?? [])],
                        "{$a128Label}: order status shipped after a partial admin shipment ships exactly the remainder (" . json_encode($a128Result['extra']['transition'] ?? null) . ')'),
                };
            }
        }
    }
} finally {
    if (isset($a128Drivers['mysql'])) $a128Run(['mysql', 'teardown', 'default', 'none', dirname(TEST_CLI_DIR), $a128Drivers['mysql']]);
    $a128Files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a128Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a128Files as $a128File) $a128File->isDir() ? rmdir($a128File->getPathname()) : unlink($a128File->getPathname());
    rmdir($a128Root);
}
