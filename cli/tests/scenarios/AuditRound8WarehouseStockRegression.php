<?php

// Round 8 review (2026-10-21), unfixed from round 7: the stock count set on the admin product form (also CSV import,
// API v1 and the variant editor, all through Warehouse::setAvailable) was booked only on the default warehouse.
// Lowering the count of a SKU whose free stock sits in another warehouse was refused, so the form could not lower it.
// Now an increase still goes to the default warehouse; a decrease takes free stock from the default warehouse first,
// then from the others by ascending priority, never below their reservations, as one stock.adjusted change.
echo "\n--- Audit round 8 (rest): admin stock edit across warehouses ---\n";
$r8wDb = Db::get();
if (!Warehouse::defaultWarehouse()) {
    $r8wDb->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r8w-main', 'R8W main', 10, 1)");
}
$r8wDefault = (int)Warehouse::defaultWarehouse()['id'];
$r8wDb->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r8w-low', 'R8W low', 1, 0)");
$r8wLow = (int)$r8wDb->lastInsertId();
$r8wDb->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r8w-high', 'R8W high', 50, 0)");
$r8wHigh = (int)$r8wDb->lastInsertId();
$r8wDb->exec("INSERT INTO categories (store_id, name, slug) VALUES ('default', 'R8W', 'r8w-cat')");
$r8wCategory = (int)$r8wDb->lastInsertId();
$r8wProduct = $r8wDb->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (?, 'default', {$r8wCategory}, ?, ?, ?, 'simple', 1000, 'eur', 'active', 0, 'deny')");
$r8wStock = $r8wDb->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, ?, ?, ?)");
$r8wQty = static function (int $warehouseId, string $sku) use ($r8wDb): array {
    $stmt = $r8wDb->prepare('SELECT qty, reserved FROM warehouse_stock WHERE warehouse_id = ? AND sku = ?');
    $stmt->execute([$warehouseId, $sku]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['qty' => null, 'reserved' => null];
    return [$row['qty'] === null ? null : (int)$row['qty'], $row['reserved'] === null ? null : (int)$row['reserved']];
};
$r8wEdit = static function (string $sku, int $available): string {
    try {
        Products::setAvailableStock($sku, 'simple', $available, 'manual_adjustment', 'admin_product');
        return 'ok';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};

// 1. All the stock is in a non-default warehouse: lowering it on the form lowers that warehouse.
$r8wProduct->execute([97801, 'R8W only other', 'r8w-only-other', 'R8W-OTHER']);
$r8wStock->execute([$r8wLow, 'R8W-OTHER', 5, 0]);
Warehouse::syncProductStock(['R8W-OTHER']);
$r8wResult = $r8wEdit('R8W-OTHER', 2);
assert_equals(['ok', 2, [2, 0]], [$r8wResult, Warehouse::totalAvailable('R8W-OTHER'), $r8wQty($r8wLow, 'R8W-OTHER')],
    'R8 warehouse stock: lowering stock held only in a non-default warehouse takes it from that warehouse (was refused)');
assert_equals(2, (int)$r8wDb->query("SELECT stock FROM products WHERE id = 97801")->fetchColumn(), 'R8 warehouse stock: products.stock shows the new count');

// 2. Mixed: default first, then the lowest-priority warehouse, reservations untouched.
$r8wProduct->execute([97802, 'R8W mixed', 'r8w-mixed', 'R8W-MIX']);
$r8wStock->execute([$r8wDefault, 'R8W-MIX', 2, 0]);
$r8wStock->execute([$r8wLow, 'R8W-MIX', 4, 1]);
$r8wStock->execute([$r8wHigh, 'R8W-MIX', 3, 0]);
Warehouse::syncProductStock(['R8W-MIX']);
$r8wEvents = (int)$r8wDb->query("SELECT COUNT(*) FROM event_outbox WHERE event_type = 'stock.adjusted'")->fetchColumn();
$r8wResult = $r8wEdit('R8W-MIX', 3); // available 2 + 3 + 3 = 8 -> 3: take 2 (default) + 3 (low, keeps its 1 reserved)
assert_equals(['ok', 3, [0, 0], [1, 1], [3, 0]],
    [$r8wResult, Warehouse::totalAvailable('R8W-MIX'), $r8wQty($r8wDefault, 'R8W-MIX'), $r8wQty($r8wLow, 'R8W-MIX'), $r8wQty($r8wHigh, 'R8W-MIX')],
    'R8 warehouse stock: a decrease takes the default warehouse first, then the lowest priority, never below reservations');
assert_equals($r8wEvents + 1, (int)$r8wDb->query("SELECT COUNT(*) FROM event_outbox WHERE event_type = 'stock.adjusted'")->fetchColumn(),
    'R8 warehouse stock: the edit is one stock.adjusted change');

// 3. An increase still goes to the default warehouse.
$r8wResult = $r8wEdit('R8W-MIX', 5);
assert_equals(['ok', 5, [2, 0], [1, 1], [3, 0]],
    [$r8wResult, Warehouse::totalAvailable('R8W-MIX'), $r8wQty($r8wDefault, 'R8W-MIX'), $r8wQty($r8wLow, 'R8W-MIX'), $r8wQty($r8wHigh, 'R8W-MIX')],
    'R8 warehouse stock: an increase is booked on the default warehouse');

// 4. Down to zero empties every warehouse's free stock and keeps the reservation.
$r8wResult = $r8wEdit('R8W-MIX', 0);
assert_equals(['ok', 0, [0, 0], [1, 1], [0, 0]],
    [$r8wResult, Warehouse::totalAvailable('R8W-MIX'), $r8wQty($r8wDefault, 'R8W-MIX'), $r8wQty($r8wLow, 'R8W-MIX'), $r8wQty($r8wHigh, 'R8W-MIX')],
    'R8 warehouse stock: zero on the form empties the free stock of every warehouse, reservations stay');

$r8wDb->exec("DELETE FROM warehouse_stock WHERE sku IN ('R8W-OTHER', 'R8W-MIX')");
$r8wDb->exec("DELETE FROM stock_movements WHERE sku IN ('R8W-OTHER', 'R8W-MIX')");
$r8wDb->exec("DELETE FROM products WHERE id IN (97801, 97802)");
$r8wDb->exec("DELETE FROM warehouses WHERE id IN ({$r8wLow}, {$r8wHigh})");
$r8wDb->exec("DELETE FROM categories WHERE id = {$r8wCategory}");
