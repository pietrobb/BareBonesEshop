<?php

// Round 7 review (2026-10-20), stock. Backorder and preorder lines froze their shortfall as an allocation without a
// warehouse and nothing ever reserved stock for them later, so such orders could never ship. A cancellation after a
// partial shipment refunded the whole order and the shipped units had no way back (RMA only for delivered orders,
// and its restock skipped them as "already restored"). An RMA that discarded units the order-level return had
// already restocked left them on sale.
echo "\n--- R7 stock: backorder allocation, cancel after partial shipment, RMA discard ---\n";
Bootstrap::initStoreContext('/', '', 'default');
$r7sCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$pdo->exec("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES ('r7s-main','R7S Main',300,0,'default'),('r7s-aux','R7S Aux',250,0,'default')");
$r7sMain = (int)$pdo->query("SELECT id FROM warehouses WHERE code='r7s-main'")->fetchColumn();
$r7sAux = (int)$pdo->query("SELECT id FROM warehouses WHERE code='r7s-aux'")->fetchColumn();
$r7sProducts = [];
// Fixed ids: an auto-assigned id follows the highest fixed id of earlier scenarios and collides with later ones.
$r7sInsert = $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, stock, status, inventory_policy, preorder_at)
    VALUES (:id, 'default', :category, :sku, :slug, 'simple', :name, :price, 0, 'active', :policy, :preorder)");
foreach ([['R7S-BO', 'backorder', null], ['R7S-FIFO', 'backorder', null], ['R7S-PRE', 'preorder', '2031-03-01 00:00:00'],
    ['R7S-REL', 'backorder', null], ['R7S-CXL', 'deny', null], ['R7S-RMA', 'deny', null]] as [$r7sSku, $r7sPolicy, $r7sPreorder]) {
    $r7sInsert->execute([':id' => 97601 + count($r7sProducts), ':category' => $r7sCategory, ':sku' => $r7sSku, ':slug' => strtolower($r7sSku), ':name' => $r7sSku . ' goods',
        ':price' => 800, ':policy' => $r7sPolicy, ':preorder' => $r7sPreorder]);
    $r7sProducts[$r7sSku] = (int)$pdo->lastInsertId();
}
$r7sCustomer = ['name' => 'R7S Customer', 'email' => 'r7s@example.test', 'phone' => '', 'address' => [],
    'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'];
$r7sOrder = static fn(string $sku, int $qty): int => Orders::create([['product_id' => $r7sProducts[$sku], 'variant_id' => null, 'sku' => $sku,
    'name' => $sku . ' goods', 'qty' => $qty, 'price' => 800, 'attrs' => []]], $r7sCustomer, 'bank_transfer');
$r7sLine = static fn(int $orderId): array => Orders::items($orderId)[0];
$r7sOnHand = static fn(string $sku): int => (int)$pdo->query("SELECT COALESCE(SUM(qty), 0) FROM warehouse_stock WHERE sku=" . $pdo->quote($sku))->fetchColumn();

// Backorder: 1 in stock, 3 ordered. Each receipt reserves for the waiting line until it is complete and ships.
Warehouse::setStock($r7sMain, 'R7S-BO', 1, 'count', 'test', 'r7s-opening');
$r7sBo = $r7sOrder('R7S-BO', 3);
assert_equals(2, (int)$r7sLine($r7sBo)['backordered_qty'], 'R7 stock: the shortfall is frozen as 2 backordered units');
$r7sBlocked = false;
try { Fulfillment::shipOrder($r7sBo); } catch (RuntimeException) { $r7sBlocked = true; }
assert_true($r7sBlocked, 'R7 stock: a backordered order does not ship before stock arrives');
Warehouse::adjustStock($r7sMain, 'R7S-BO', 1, 'receipt', 'test', 'r7s-receipt-1');
assert_equals(1, (int)$r7sLine($r7sBo)['backordered_qty'], 'R7 stock: a receipt of 1 reserves 1 waiting unit');
assert_equals(2, (int)$r7sLine($r7sBo)['reserved_qty'], 'R7 stock: the line now holds 2 reserved units');
assert_equals(0, Warehouse::totalAvailable('R7S-BO'), 'R7 stock: the received unit is no longer on sale');
$r7sPo = $pdo->prepare("INSERT INTO suppliers (store_id, code, name) VALUES ('default','r7s-supplier','R7S Supplier')");
$r7sPo->execute();
$r7sSupplier = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO supplier_products (supplier_id, sku, cost_price, min_order_qty, pack_size) VALUES (?, 'R7S-BO', 400, 1, 1)")->execute([$r7sSupplier]);
$r7sPoId = Inventory::createPurchaseOrder($r7sSupplier, $r7sMain, 'R7S-PO-1', [['sku' => 'R7S-BO', 'quantity' => 5]]);
assert_true(Inventory::receivePurchaseOrder($r7sPoId, 'R7S-BO', 5, 'r7s-po-receipt'), 'R7 stock: the purchase order receipt is booked');
assert_equals(0, (int)$r7sLine($r7sBo)['backordered_qty'], 'R7 stock: the PO receipt allocates the rest of the backorder');
assert_equals('unfulfilled', (string)$r7sLine($r7sBo)['fulfillment_status'], 'R7 stock: the line is no longer reported as backordered');
assert_equals(4, Warehouse::totalAvailable('R7S-BO'), 'R7 stock: only the surplus of the receipt is on sale');
assert_true(count(Fulfillment::shipOrder($r7sBo)) > 0, 'R7 stock: the allocated backorder ships');
assert_equals('fulfilled', (string)$r7sLine($r7sBo)['fulfillment_status'], 'R7 stock: the backordered line is fulfilled');
assert_equals(4, $r7sOnHand('R7S-BO'), 'R7 stock: the shipment takes the 3 units off hand');

// Oldest order first: one unit arrives for two waiting orders.
$r7sFirst = $r7sOrder('R7S-FIFO', 1);
$r7sSecond = $r7sOrder('R7S-FIFO', 1);
$pdo->exec("UPDATE orders SET created_at = '2026-01-01 00:00:00' WHERE id = {$r7sFirst}");
Warehouse::adjustStock($r7sMain, 'R7S-FIFO', 1, 'receipt', 'test', 'r7s-fifo');
assert_equals(0, (int)$r7sLine($r7sFirst)['backordered_qty'], 'R7 stock: the older backorder gets the unit');
assert_equals(1, (int)$r7sLine($r7sSecond)['backordered_qty'], 'R7 stock: the newer backorder keeps waiting');

// Preorder: a feed sync with stock allocates it too and keeps the release date.
$r7sPre = $r7sOrder('R7S-PRE', 2);
assert_equals(2, (int)$r7sLine($r7sPre)['backordered_qty'], 'R7 stock: a preorder at zero stock waits for 2 units');
Warehouse::bulkSetStock($r7sMain, ['R7S-PRE' => 2]);
assert_equals(0, (int)$r7sLine($r7sPre)['backordered_qty'], 'R7 stock: the feed stock allocates the preorder');
assert_true((string)$r7sLine($r7sPre)['preorder_at'] !== '', 'R7 stock: the preorder keeps its release date');
assert_equals(0, Warehouse::totalAvailable('R7S-PRE'), 'R7 stock: the fed units are reserved for the preorder');
$r7sPreAttrs = json_decode((string)$r7sLine($r7sPre)['attrs'], true);
assert_equals([['warehouse_id' => $r7sMain, 'qty' => 2, 'status' => 'reserved']], array_map(static fn(array $a): array => ['warehouse_id' => (int)$a['warehouse_id'], 'qty' => (int)$a['qty'], 'status' => $a['status']], $r7sPreAttrs['_inventory_allocations']), 'R7 stock: the allocation snapshot names the warehouse');

// A cancelled order's released reservation goes to the waiting order.
Warehouse::setStock($r7sMain, 'R7S-REL', 2, 'count', 'test', 'r7s-rel');
$r7sHolder = $r7sOrder('R7S-REL', 2);
$r7sWaiter = $r7sOrder('R7S-REL', 2);
assert_equals(2, (int)$r7sLine($r7sWaiter)['backordered_qty'], 'R7 stock: the second order waits for 2 units');
OrderWorkflow::transition($r7sHolder, 'cancelled', 'R7 stock release');
assert_equals(0, (int)$r7sLine($r7sWaiter)['backordered_qty'], 'R7 stock: the released units are allocated to the waiting order');
assert_equals(0, Warehouse::totalAvailable('R7S-REL'), 'R7 stock: the released units do not go on sale over the waiting order');

// Cancel after a partial shipment: 1 of 3 shipped from the main warehouse.
Warehouse::setStock($r7sMain, 'R7S-CXL', 1, 'count', 'test', 'r7s-cxl-main');
Warehouse::setStock($r7sAux, 'R7S-CXL', 2, 'count', 'test', 'r7s-cxl-aux');
$r7sCxl = $r7sOrder('R7S-CXL', 3);
$pdo->exec("UPDATE orders SET payment_status = 'paid', payment_id = 'r7s_pay_cxl' WHERE id = {$r7sCxl}");
OrderWorkflow::transition($r7sCxl, 'paid');
OrderWorkflow::transition($r7sCxl, 'processing');
$r7sShipments = Fulfillment::planOrder($r7sCxl);
assert_equals(2, count($r7sShipments), 'R7 cancel: the order is planned from two warehouses');
assert_true(Fulfillment::ship($r7sShipments[0]), 'R7 cancel: the main warehouse ships 1 unit');
$r7sTotal = (int)Orders::byId($r7sCxl)['total'];
OrderWorkflow::transition($r7sCxl, 'cancelled', 'R7 cancel after partial shipment');
$r7sRefund = (int)$pdo->query("SELECT amount FROM payment_transactions WHERE order_id = {$r7sCxl} AND idempotency_key = 'order_refund_request_{$r7sCxl}'")->fetchColumn();
assert_equals($r7sTotal - intdiv($r7sTotal, 3), $r7sRefund, 'R7 cancel: only the 2 unshipped units are refunded');
assert_equals(intdiv($r7sTotal, 3), PaymentService::refundableBalance($r7sCxl) - $r7sRefund, 'R7 cancel: the shipped unit stays paid until it comes back');
assert_equals('cancelled', (string)$pdo->query("SELECT status FROM shipments WHERE id = " . (int)$r7sShipments[1])->fetchColumn(), 'R7 cancel: the planned shipment is cancelled with the order');
$r7sLateShip = false;
try { $r7sLateShip = Fulfillment::ship((int)$r7sShipments[1]); } catch (RuntimeException) { $r7sLateShip = false; }
assert_true(!$r7sLateShip, 'R7 cancel: the cancelled shipment can no longer ship released stock');
assert_equals(2, Warehouse::totalAvailable('R7S-CXL'), 'R7 cancel: the 2 unshipped units are released');
[$r7sRmaOk, $r7sRmaId] = Rma::create($r7sCxl, null, 'shipped before the cancellation', [(int)$r7sLine($r7sCxl)['id'] => 3]);
assert_true($r7sRmaOk === true, 'R7 cancel: the shipped unit of a cancelled order can be returned through RMA');
assert_equals(1, (int)Rma::items((int)$r7sRmaId)[0]['quantity'], 'R7 cancel: the RMA is limited to the shipped unit');
Rma::setStatus((int)$r7sRmaId, 'approved');
Rma::receive((int)$r7sRmaId, [(int)Rma::items((int)$r7sRmaId)[0]['id'] => ['quantity' => 1, 'disposition' => 'restock']], 'r7s-cxl-receipt');
assert_equals(3, Warehouse::totalAvailable('R7S-CXL'), 'R7 cancel: the returned shipped unit is restocked (the cancellation did not restore it)');
$r7sDelivered = $r7sOrder('R7S-CXL', 1);
assert_equals('order_not_returnable', Rma::create($r7sDelivered, null, 'not shipped', [(int)$r7sLine($r7sDelivered)['id'] => 1])[2], 'R7 cancel: an unshipped open order still has no RMA');

// RMA discard after the order-level return restocked the units: they come off stock again.
Warehouse::setStock($r7sMain, 'R7S-RMA', 5, 'count', 'test', 'r7s-rma');
$r7sRma = $r7sOrder('R7S-RMA', 2);
Fulfillment::shipOrder($r7sRma);
Orders::updateStatus($r7sRma, 'delivered');
[, $r7sRmaReq] = Rma::create($r7sRma, null, 'broken', [(int)$r7sLine($r7sRma)['id'] => 2]);
Rma::setStatus((int)$r7sRmaReq, 'approved');
OrderWorkflow::transition($r7sRma, 'returned', 'R7 order returned before inspection');
assert_equals(5, Warehouse::totalAvailable('R7S-RMA'), 'R7 RMA: the order-level return restocks both units');
Rma::receive((int)$r7sRmaReq, [(int)Rma::items((int)$r7sRmaReq)[0]['id'] => ['quantity' => 2, 'disposition' => 'discard']], 'r7s-rma-discard');
assert_equals(3, Warehouse::totalAvailable('R7S-RMA'), 'R7 RMA: the discarded units are written off');
assert_equals(3, $r7sOnHand('R7S-RMA'), 'R7 RMA: on-hand stock no longer counts the discarded units');
assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE sku = 'R7S-RMA' AND movement_type = 'rma_write_off'")->fetchColumn(), 'R7 RMA: the write-off is in the stock ledger');
assert_true(!Rma::receive((int)$r7sRmaReq, [(int)Rma::items((int)$r7sRmaReq)[0]['id'] => ['quantity' => 2, 'disposition' => 'discard']], 'r7s-rma-discard'), 'R7 RMA: a replayed discard writes off nothing more');
assert_equals(3, Warehouse::totalAvailable('R7S-RMA'), 'R7 RMA: stock is unchanged by the replay');
