<?php

echo "\n--- Audit A12.7: admin suppliers and purchase orders (store scope, offers, pack/minimum, idempotent ledger receipts, CSRF/role, audit) ---\n";

foreach (['owner' => true, 'manager' => true, 'editor' => false, 'fulfillment' => false, 'viewer' => false] as $a127Role => $a127Allowed) {
    $a127Session = $_SESSION ?? [];
    $_SESSION = ['admin_role' => $a127Role];
    assert_equals($a127Allowed, Security::adminCan('import'), "A12.7 role: {$a127Role} " . ($a127Allowed ? 'may' : 'may not') . ' manage suppliers and purchase orders');
    $_SESSION = $a127Session;
}
$a127Permissions = require dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Permissions.php';
assert_equals(['import', 'import', 'import'], [$a127Permissions['suppliers'] ?? null, $a127Permissions['purchase_orders'] ?? null, $a127Permissions['warehouses'] ?? null],
    'A12.7 role: supplier and purchase order pages require the same permission as warehouses');

$a127Root = test_fixture_path('admin-suppliers');
mkdir($a127Root);
$a127Fixture = $a127Root . '/request.php';
$a127Source = <<<'PHP'
<?php
[, $driver, $phase, $store, $action, $root, $target] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Catalog.php';
require $root . '/src/Entrypoints/Admin/Suppliers.php';
require $root . '/src/Entrypoints/Admin/Request.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
final class A127Pdo extends PDO {
    public int $depth = 0;
    public function beginTransaction(): bool {
        if ($this->depth++ === 0) return parent::beginTransaction();
        $this->exec('SAVEPOINT a127_' . $this->depth);
        return true;
    }
    public function commit(): bool {
        if (--$this->depth === 0) return parent::commit();
        $this->exec('RELEASE SAVEPOINT a127_' . ($this->depth + 1));
        return true;
    }
    public function rollBack(): bool {
        if (--$this->depth === 0) return parent::rollBack();
        $this->exec('ROLLBACK TO SAVEPOINT a127_' . ($this->depth + 1));
        return true;
    }
    public function inTransaction(): bool { return $this->depth > 0; }
}
$db = $driver === 'mysql'
    ? new A127Pdo(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new A127Pdo('sqlite:' . $target);
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
        $other = $owner === 'default' ? 'cz' : 'default';
        $db->prepare("INSERT INTO warehouses (store_id, code, name, is_default) VALUES (?, ?, ?, 1)")->execute([$owner, "a127wh-{$owner}", "A127WH-{$owner}-NAME"]);
        $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute([$owner, "a127cat-{$owner}", 'Cat', "a127cat-{$owner}"]);
        $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, 1000, 0, 'active')")
            ->execute([$owner, $idOf('categories', 'slug', "a127cat-{$owner}"), "A127P-{$owner}", "a127p-{$owner}", "A127P-{$owner}-NAME"]);
        $db->prepare("INSERT INTO variants (product_id, sku, name, stock) VALUES (?, ?, 'V', 0)")->execute([$idOf('products', 'sku', "A127P-{$owner}"), "A127V-{$owner}"]);
        $supplier = $db->prepare('INSERT INTO suppliers (store_id, code, name, contact, active) VALUES (?, ?, ?, ?, ?)');
        $supplier->execute([$owner, "a127sup-{$owner}", "A127SUP-{$owner}-NAME", '{"email":"sup@example.test"}', 1]);
        $supplier->execute([$owner, "a127idle-{$owner}", "A127IDLE-{$owner}-NAME", '{}', 0]);
        $supplier->execute([$owner, "a127used-{$owner}", "A127USED-{$owner}-NAME", '{}', 1]);
        $offer = $db->prepare('INSERT INTO supplier_products (supplier_id, sku, cost_price, min_order_qty, pack_size) VALUES (?, ?, ?, ?, ?)');
        $sup = $idOf('suppliers', 'code', "a127sup-{$owner}");
        $offer->execute([$sup, "A127P-{$owner}", 250, 4, 2]);
        $offer->execute([$sup, "A127V-{$owner}", 120, 1, 1]);
        // Legacy row written before offers were validated: the supplier "offers" the other store's SKU.
        $offer->execute([$sup, "A127P-{$other}", 1, 1, 1]);
        $offer->execute([$idOf('suppliers', 'code', "a127used-{$owner}"), "A127P-{$owner}", 200, 1, 1]);
        $db->prepare("INSERT INTO purchase_orders (store_id, supplier_id, warehouse_id, number, status, currency) VALUES (?, ?, ?, ?, 'ordered', 'eur')")
            ->execute([$owner, $idOf('suppliers', 'code', "a127used-{$owner}"), $idOf('warehouses', 'code', "a127wh-{$owner}"), "A127-PO-{$owner}"]);
        $db->prepare('INSERT INTO purchase_order_items (purchase_order_id, sku, quantity, unit_cost) VALUES (?, ?, 4, 200)')->execute([$idOf('purchase_orders', 'number', "A127-PO-{$owner}"), "A127P-{$owner}"]);
    }
    exit(0);
}

$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['default'], ['id'=>'cz', 'url_prefix'=>'/cz', 'domains'=>[], 'catalog_scope'=>'cz', 'provider_scope'=>'cz', 'languages'=>['sk', 'en']]);
$stores['stores']['default']['languages'] = ['sk', 'en'];
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('sk');
$foreign = $store === 'default' ? 'cz' : 'default';
$own = $store;
$_SESSION = ['admin_role'=>'manager', 'admin_user'=>'a127', 'csrf_token'=>'a127-token'];
$snapshot = static function() use ($db): array {
    return [
        'suppliers' => $db->query('SELECT id, store_id, code, name, contact, active FROM suppliers ORDER BY id')->fetchAll(),
        'supplier_products' => $db->query('SELECT s.store_id, sp.supplier_id, sp.sku, sp.supplier_sku, sp.cost_price, sp.min_order_qty, sp.pack_size, sp.lead_time_days
            FROM supplier_products sp LEFT JOIN suppliers s ON s.id = sp.supplier_id ORDER BY sp.supplier_id, sp.sku')->fetchAll(),
        'purchase_orders' => $db->query('SELECT id, store_id, supplier_id, warehouse_id, number, status, currency, expected_at FROM purchase_orders ORDER BY id')->fetchAll(),
        'purchase_order_items' => $db->query('SELECT po.store_id, i.purchase_order_id, i.sku, i.quantity, i.received_qty, i.unit_cost FROM purchase_order_items i
            LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id ORDER BY i.purchase_order_id, i.sku')->fetchAll(),
        'warehouse_stock' => $db->query('SELECT ws.store_id, w.store_id AS warehouse_store, ws.sku, ws.qty FROM warehouse_stock ws LEFT JOIN warehouses w ON w.id = ws.warehouse_id ORDER BY ws.warehouse_id, ws.sku')->fetchAll(),
        'stock_movements' => $db->query('SELECT store_id, warehouse_id, sku, movement_type, quantity_delta FROM stock_movements ORDER BY id')->fetchAll(),
        'products' => $db->query('SELECT store_id, sku, stock FROM products ORDER BY id')->fetchAll(),
        'audit' => $db->query("SELECT entity, entity_id, action, store_id FROM audit_log WHERE entity IN ('supplier', 'purchase_order') ORDER BY id")->fetchAll(),
    ];
};
$db->beginTransaction();
$before = $snapshot();
$extra = [];
ob_start();
register_shutdown_function(static function() use ($db, $snapshot, $before, &$extra): void {
    $output = ob_get_contents();
    if ($extra instanceof Closure) $extra = $extra();
    ob_clean();
    $result = ['output'=>$output, 'before'=>$before, 'after'=>$snapshot(), 'flash'=>$_SESSION['flash'] ?? null, 'extra'=>$extra];
    $db->depth = 1;
    $db->rollBack();
    print json_encode($result, JSON_THROW_ON_ERROR);
    ob_end_flush();
});
$_GET = [];
$s = static fn(string $kind, ?string $owner = null): int => $idOf('suppliers', 'code', "a127{$kind}-" . ($owner ?? $own));
$wh = static fn(?string $owner = null): string => (string)$idOf('warehouses', 'code', 'a127wh-' . ($owner ?? $own));
$po = static fn(?string $owner = null): int => $idOf('purchase_orders', 'number', 'A127-PO-' . ($owner ?? $own));
$item = static function(?string $owner = null) use ($db, $po, $own): int {
    $stmt = $db->prepare('SELECT id FROM purchase_order_items WHERE purchase_order_id = ?');
    $stmt->execute([$po($owner ?? $own)]);
    return (int)$stmt->fetchColumn();
};
$supplier = static fn(array $fields): array => array_replace(['csrf_token'=>'a127-token', 'name'=>'', 'code'=>'', 'email'=>'', 'phone'=>'', 'note'=>'', 'active'=>'1'], $fields);
$offers = static fn(string $lines): array => ['csrf_token'=>'a127-token', 'products'=>$lines];
$order = static fn(array $fields): array => array_replace(['csrf_token'=>'a127-token', 'supplier_id'=>(string)$s('sup'), 'warehouse_id'=>$wh(), 'number'=>'', 'expected_at'=>'', 'items'=>''], $fields);
$receipt = static fn(array $quantities, string $key = 'a127a127a127a127a127a127a127a127'): array => ['csrf_token'=>'a127-token', 'receipt_key'=>$key, 'receive'=>$quantities];
$core = static function(int $supplierId, string $warehouseId, string $sku): string {
    try {
        Inventory::createPurchaseOrder($supplierId, (int)$warehouseId, 'A127-CORE-' . bin2hex(random_bytes(3)), [['sku' => $sku, 'quantity' => 4]]);
        return 'created';
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
};
match ($action) {
    'suppliers_page' => [$_GET = ['id'=>$s('sup')], pageSuppliers()],
    'orders_page' => pagePurchaseOrders(),
    'order_page' => [$_GET = ['id'=>$po()], pagePurchaseOrders()],
    'supplier_create' => [$_POST = $supplier(['name'=>'Dodávateľ Žltý', 'email'=>'nakup@example.test', 'phone'=>'+421 900 000 000']), handlePost('suppliers', 'save')],
    'supplier_edit' => [$_GET = ['id'=>$s('idle')], $_POST = array_diff_key($supplier(['name'=>'Renamed', 'code'=>"a127idle-{$own}", 'note'=>'Len e-mail']), ['active'=>1]), handlePost('suppliers', 'save')],
    'supplier_offers' => [$_GET = ['id'=>$s('sup')], $_POST = $offers("A127P-{$own} | X-1 | 300 | 6 | 3 | 5\n\nA127V-{$own} |  | 120"), handlePost('suppliers', 'products')],
    'supplier_delete_idle' => [$_POST = ['csrf_token'=>'a127-token', 'supplier_id'=>(string)$s('idle')], handlePost('suppliers', 'delete')],
    'po_create' => [$_POST = $order(['number'=>"PO-NEW-{$own}", 'expected_at'=>'2026-11-01', 'items'=>"A127P-{$own} | 4\nA127V-{$own} | 2 | 99"]), handlePost('purchase_orders', 'create')],
    'po_receive_partial' => [$_GET = ['id'=>$po()], $_POST = $receipt([(string)$item() => '2']), handlePost('purchase_orders', 'receive')],
    'po_receive_twice' => [$_GET = ['id'=>$po()], $_POST = $receipt([(string)$item() => '2']), SupplierAdmin::receive($po(), $_POST), handlePost('purchase_orders', 'receive')],
    'po_receive_full' => [$_GET = ['id'=>$po()], $_POST = $receipt([(string)$item() => '4']), handlePost('purchase_orders', 'receive')],
    'core_store_scope' => [$extra = ['foreign_supplier' => $core($s('sup', $foreign), $wh(), "A127P-{$own}"), 'foreign_warehouse' => $core($s('used'), $wh($foreign), "A127P-{$own}"),
        'foreign_sku' => $core($s('sup'), $wh(), "A127P-{$foreign}")]],
    'supplier_offers_foreign_sku' => [$_GET = ['id'=>$s('sup')], $_POST = $offers("A127P-{$foreign} | | 100"), handlePost('suppliers', 'products')],
    'supplier_offers_dup' => [$_GET = ['id'=>$s('sup')], $_POST = $offers("A127P-{$own} | | 100\nA127P-{$own} | | 200"), handlePost('suppliers', 'products')],
    'supplier_offers_pack' => [$_GET = ['id'=>$s('sup')], $_POST = $offers("A127P-{$own} | | 100 | 1 | 0"), handlePost('suppliers', 'products')],
    'supplier_delete_used' => [$_POST = ['csrf_token'=>'a127-token', 'supplier_id'=>(string)$s('used')], handlePost('suppliers', 'delete')],
    'supplier_delete_foreign' => [$_POST = ['csrf_token'=>'a127-token', 'supplier_id'=>(string)$s('idle', $foreign)], handlePost('suppliers', 'delete')],
    'supplier_edit_foreign' => [$_GET = ['id'=>$s('idle', $foreign)], $_POST = $supplier(['name'=>'Stolen']), handlePost('suppliers', 'save')],
    'supplier_bad_email' => [$_POST = $supplier(['name'=>'X', 'email'=>'not-an-email']), handlePost('suppliers', 'save')],
    'supplier_dup_code' => [$_POST = $supplier(['name'=>'X', 'code'=>"a127idle-{$own}"]), handlePost('suppliers', 'save')],
    'supplier_no_csrf' => [$_POST = array_diff_key($supplier(['name'=>'X']), ['csrf_token'=>1]), handlePost('suppliers', 'save')],
    'po_create_foreign_supplier' => [$_POST = $order(['supplier_id'=>(string)$s('sup', $foreign), 'items'=>"A127P-{$own} | 4"]), handlePost('purchase_orders', 'create')],
    'po_create_foreign_warehouse' => [$_POST = $order(['warehouse_id'=>$wh($foreign), 'items'=>"A127P-{$own} | 4"]), handlePost('purchase_orders', 'create')],
    'po_create_foreign_sku' => [$_POST = $order(['items'=>"A127P-{$foreign} | 4"]), handlePost('purchase_orders', 'create')],
    'po_create_inactive' => [$_POST = $order(['supplier_id'=>(string)$s('idle'), 'items'=>"A127P-{$own} | 4"]), handlePost('purchase_orders', 'create')],
    'po_create_pack' => [$_POST = $order(['items'=>"A127P-{$own} | 5"]), handlePost('purchase_orders', 'create')],
    'po_create_not_offered' => [$_POST = $order(['supplier_id'=>(string)$s('used'), 'items'=>"A127V-{$own} | 1"]), handlePost('purchase_orders', 'create')],
    'po_create_dup_number' => [$_POST = $order(['number'=>"A127-PO-{$own}", 'items'=>"A127P-{$own} | 4"]), handlePost('purchase_orders', 'create')],
    'po_create_bad_date' => [$_POST = $order(['expected_at'=>'2026-13-01', 'items'=>"A127P-{$own} | 4"]), handlePost('purchase_orders', 'create')],
    'po_create_empty' => [$_POST = $order([]), handlePost('purchase_orders', 'create')],
    'po_receive_over' => [$_GET = ['id'=>$po()], $_POST = $receipt([(string)$item() => '5']), handlePost('purchase_orders', 'receive')],
    'po_receive_foreign' => [$_GET = ['id'=>$po($foreign)], $_POST = $receipt([(string)$item($foreign) => '1']), handlePost('purchase_orders', 'receive')],
    'po_receive_foreign_item' => [$_GET = ['id'=>$po()], $_POST = $receipt([(string)$item($foreign) => '1']), handlePost('purchase_orders', 'receive')],
    'po_receive_bad_key' => [$_GET = ['id'=>$po()], $_POST = $receipt([(string)$item() => '1'], 'x'), handlePost('purchase_orders', 'receive')],
    'po_receive_no_csrf' => [$_GET = ['id'=>$po()], $_POST = array_diff_key($receipt([(string)$item() => '1']), ['csrf_token'=>1]), handlePost('purchase_orders', 'receive')],
};
PHP;
file_put_contents($a127Fixture, $a127Source);
$a127Run = static function(array $args) use ($a127Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a127Fixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
// Refused actions: expected flash fragment; suppliers, offers, orders, stock, ledger and audit log must stay byte-identical.
$a127Refused = [
    'supplier_offers_foreign_sku' => 'nie je produkt ani variant', 'supplier_offers_dup' => 'dvakrát', 'supplier_offers_pack' => 'balenie musí byť',
    'supplier_delete_used' => 'má nákupné objednávky', 'supplier_delete_foreign' => 'neexistuje', 'supplier_edit_foreign' => 'neexistuje', 'supplier_bad_email' => 'E-mail',
    'supplier_dup_code' => 'už v tomto obchode existuje', 'supplier_no_csrf' => 'CSRF',
    'po_create_foreign_supplier' => 'Dodávateľ nepatrí', 'po_create_foreign_warehouse' => 'Sklad nepatrí', 'po_create_foreign_sku' => 'is not in the catalog',
    'po_create_inactive' => 'neaktívny', 'po_create_pack' => 'v baleniach po 2', 'po_create_not_offered' => 'nemá v ponuke', 'po_create_dup_number' => 'už existuje',
    'po_create_bad_date' => 'RRRR-MM-DD', 'po_create_empty' => 'aspoň jednu položku', 'po_receive_over' => 'prijať možno 1 až 4', 'po_receive_foreign' => 'neexistuje',
    'po_receive_foreign_item' => 'Neplatný formulár', 'po_receive_bad_key' => 'Neplatný formulár', 'po_receive_no_csrf' => 'CSRF',
];
$a127Actions = array_merge(['suppliers_page', 'orders_page', 'order_page', 'supplier_create', 'supplier_edit', 'supplier_offers', 'supplier_delete_idle', 'po_create',
    'po_receive_partial', 'po_receive_twice', 'po_receive_full', 'core_store_scope'], array_keys($a127Refused));
$a127Drivers = ['sqlite' => $a127Root . '/suppliers.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a127Drivers['mysql'] = 'bbe_a127_suppliers_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A12.7 native suppliers/purchase orders (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a127Drivers as $a127Driver => $a127Target) {
        [$a127Exit, , $a127Stderr] = $a127Run([$a127Driver, 'setup', 'default', 'none', dirname(TEST_CLI_DIR), $a127Target]);
        assert_equals([0, ''], [$a127Exit, $a127Stderr], "A12.7 {$a127Driver}: migrated fixture with warehouses, products, suppliers, offers and an open order per store");
        foreach (['default', 'cz'] as $a127Store) {
            $a127Foreign = $a127Store === 'default' ? 'cz' : 'default';
            foreach ($a127Actions as $a127Action) {
                $a127Label = "A12.7 {$a127Driver}/{$a127Store}/{$a127Action}";
                [$a127Exit, $a127Stdout, $a127Stderr] = $a127Run([$a127Driver, 'run', $a127Store, $a127Action, dirname(TEST_CLI_DIR), $a127Target]);
                assert_equals([0, ''], [$a127Exit, $a127Stderr], "{$a127Label}: actual handler exits without errors");
                $a127Result = json_decode((string)$a127Stdout, true) ?: ['output'=>'', 'before'=>[], 'after'=>[], 'flash'=>null, 'extra'=>[]];
                $a127Output = (string)$a127Result['output'];
                $a127Flash = $a127Result['flash'] ?? [];
                $a127Own = static fn(array $rows, string $store): array => array_values(array_filter($rows, static fn(array $r): bool => $r['store_id'] === $store));
                $a127After = $a127Result['after'];
                $a127Suppliers = array_column($a127Own($a127After['suppliers'] ?? [], $a127Store), null, 'code');
                $a127Orders = array_column($a127Own($a127After['purchase_orders'] ?? [], $a127Store), null, 'number');
                $a127Items = $a127Own($a127After['purchase_order_items'] ?? [], $a127Store);
                $a127NewAudit = array_slice($a127After['audit'] ?? [], count($a127Result['before']['audit'] ?? []));
                assert_true(!preg_match('/A127[A-Z]+-' . $a127Foreign . '-NAME/', $a127Output), "{$a127Label}: no foreign store data rendered");
                foreach (['suppliers', 'purchase_orders', 'purchase_order_items', 'warehouse_stock', 'stock_movements', 'products'] as $a127Table) {
                    assert_equals($a127Own($a127Result['before'][$a127Table] ?? [], $a127Foreign), $a127Own($a127After[$a127Table] ?? [], $a127Foreign), "{$a127Label}: foreign store {$a127Table} unchanged");
                }
                assert_equals([], array_values(array_filter($a127After['warehouse_stock'] ?? [], static fn(array $r): bool => $r['store_id'] !== $r['warehouse_store'])),
                    "{$a127Label}: no stock row books one store's SKU into another store's warehouse");
                if (isset($a127Refused[$a127Action]) || $a127Action === 'core_store_scope') {
                    if ($a127Action !== 'core_store_scope') {
                        assert_equals(['err', true], [$a127Flash['type'] ?? null, str_contains((string)($a127Flash['msg'] ?? ''), $a127Refused[$a127Action])],
                            "{$a127Label}: refused with a specific error (" . ($a127Flash['msg'] ?? '') . ')');
                    }
                    assert_equals($a127Result['before'], $a127After, "{$a127Label}: suppliers, offers, orders, stock, ledger and audit log unchanged");
                    if ($a127Action === 'core_store_scope') {
                        assert_equals(['foreign_supplier' => true, 'foreign_warehouse' => true, 'foreign_sku' => true],
                            array_map(static fn(string $m): bool => str_contains($m, 'does not belong to store') || str_contains($m, 'is not in the catalog of store'), $a127Result['extra']),
                            "{$a127Label}: Inventory::createPurchaseOrder refuses another store's supplier, warehouse and SKU (" . json_encode($a127Result['extra']) . ')');
                    }
                    continue;
                }
                if (!str_ends_with($a127Action, '_page')) {
                    assert_equals('ok', $a127Flash['type'] ?? null, "{$a127Label}: action succeeds (" . ($a127Flash['msg'] ?? '') . ')');
                    assert_equals([$a127Store], array_values(array_unique(array_column($a127NewAudit, 'store_id'))), "{$a127Label}: change audited in the active store");
                }
                $a127Open = array_values(array_filter($a127Items, static fn(array $r): bool => $r['sku'] === "A127P-{$a127Store}" && (int)$r['quantity'] === 4 && (int)$r['unit_cost'] === 200))[0] ?? [];
                $a127Stock = array_column($a127Own($a127After['warehouse_stock'] ?? [], $a127Store), 'qty', 'sku');
                $a127Products = array_column($a127Own($a127After['products'] ?? [], $a127Store), 'stock', 'sku');
                $a127Receipts = array_values(array_filter($a127Own($a127After['stock_movements'] ?? [], $a127Store), static fn(array $r): bool => $r['movement_type'] === 'receipt'));
                match ($a127Action) {
                    'suppliers_page' => assert_true(str_contains($a127Output, "value=\"A127SUP-{$a127Store}-NAME\"") && str_contains($a127Output, "A127P-{$a127Store} |  | 250 | 4 | 2 | 0") && str_contains($a127Output, 'action=products&id='),
                        "{$a127Label}: own suppliers and the edited supplier's offer lines rendered"),
                    'orders_page' => assert_true(str_contains($a127Output, "A127-PO-{$a127Store}") && str_contains($a127Output, "A127WH-{$a127Store}-NAME") && str_contains($a127Output, 'action=create'),
                        "{$a127Label}: own orders, warehouses and the create form rendered"),
                    'order_page' => assert_true(preg_match('/name="receipt_key" value="[a-f0-9]{32}"/', $a127Output) === 1 && str_contains($a127Output, 'name="receive[') && str_contains($a127Output, 'max="4"'),
                        "{$a127Label}: receipt form carries a one-time key and the open quantity"),
                    'supplier_create' => assert_equals(['Dodávateľ Žltý', ['email'=>'nakup@example.test', 'phone'=>'+421 900 000 000'], 1],
                        [$a127Suppliers['dodavatel-zlty']['name'] ?? null, json_decode((string)($a127Suppliers['dodavatel-zlty']['contact'] ?? ''), true), (int)($a127Suppliers['dodavatel-zlty']['active'] ?? 0)],
                        "{$a127Label}: code from the Unicode name, contact stored as JSON"),
                    'supplier_edit' => assert_equals(['Renamed', ['note'=>'Len e-mail'], 0], [$a127Suppliers["a127idle-{$a127Store}"]['name'] ?? null,
                        json_decode((string)($a127Suppliers["a127idle-{$a127Store}"]['contact'] ?? ''), true), (int)($a127Suppliers["a127idle-{$a127Store}"]['active'] ?? 1)], "{$a127Label}: rename, contact, deactivate"),
                    'supplier_offers' => assert_equals([["A127P-{$a127Store}", 'X-1', 300, 6, 3, 5], ["A127V-{$a127Store}", null, 120, 1, 1, 0]],
                        array_map(static fn(array $r): array => [$r['sku'], $r['supplier_sku'], (int)$r['cost_price'], (int)$r['min_order_qty'], (int)$r['pack_size'], (int)$r['lead_time_days']],
                            array_values(array_filter($a127After['supplier_products'], static fn(array $r): bool => $r['store_id'] === $a127Store && $r['supplier_id'] === array_column($a127After['suppliers'], 'id', 'code')["a127sup-{$a127Store}"]))),
                        "{$a127Label}: offers replaced (legacy foreign SKU dropped), defaults for minimum, pack and lead time"),
                    'supplier_delete_idle' => assert_equals([false, true], [isset($a127Suppliers["a127idle-{$a127Store}"]), isset($a127Suppliers["a127used-{$a127Store}"])], "{$a127Label}: only the supplier without orders deleted"),
                    'po_create' => assert_equals(['ordered', '2026-11-01', [["A127P-{$a127Store}", 4, 250], ["A127V-{$a127Store}", 2, 99]]],
                        [$a127Orders["PO-NEW-{$a127Store}"]['status'] ?? null, substr((string)($a127Orders["PO-NEW-{$a127Store}"]['expected_at'] ?? ''), 0, 10),
                            array_map(static fn(array $r): array => [$r['sku'], (int)$r['quantity'], (int)$r['unit_cost']], array_values(array_filter($a127Items, static fn(array $r): bool => (int)$r['purchase_order_id'] === (int)($a127Orders["PO-NEW-{$a127Store}"]['id'] ?? 0))))],
                        "{$a127Label}: order with offer cost and explicit cost lines in the own store"),
                    'po_receive_partial' => assert_equals([2, 'partially_received', 2, 2, 1], [(int)($a127Open['received_qty'] ?? 0), $a127Orders["A127-PO-{$a127Store}"]['status'] ?? null,
                        (int)($a127Stock["A127P-{$a127Store}"] ?? 0), (int)($a127Products["A127P-{$a127Store}"] ?? 0), count($a127Receipts)], "{$a127Label}: partial receipt booked in the ledger, warehouse and product stock"),
                    'po_receive_twice' => assert_equals([2, 2, 1, true], [(int)($a127Open['received_qty'] ?? 0), (int)($a127Stock["A127P-{$a127Store}"] ?? 0), count($a127Receipts), str_contains((string)($a127Flash['msg'] ?? ''), 'už bol zaúčtovaný')],
                        "{$a127Label}: resubmitted receipt form is a no-op"),
                    'po_receive_full' => assert_equals([4, 'received', 4, 4], [(int)($a127Open['received_qty'] ?? 0), $a127Orders["A127-PO-{$a127Store}"]['status'] ?? null,
                        (int)($a127Stock["A127P-{$a127Store}"] ?? 0), (int)($a127Products["A127P-{$a127Store}"] ?? 0)], "{$a127Label}: full receipt closes the order"),
                };
            }
        }
    }
} finally {
    if (isset($a127Drivers['mysql'])) $a127Run(['mysql', 'teardown', 'default', 'none', dirname(TEST_CLI_DIR), $a127Drivers['mysql']]);
    $a127Files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a127Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a127Files as $a127File) $a127File->isDir() ? rmdir($a127File->getPathname()) : unlink($a127File->getPathname());
    rmdir($a127Root);
}
