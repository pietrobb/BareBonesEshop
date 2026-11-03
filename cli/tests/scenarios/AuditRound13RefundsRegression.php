<?php

// Review round 13 (N4, N5, T5): the withdrawal and return period of an order delivered in several parcels, and refunds.
echo "\n--- Audit round 13: withdrawal period from the last parcel, refunds ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r13rFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R13 refunds {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};

// N4: an order of two books in two parcels. The period ran from the first delivered parcel (MAX over the delivered
// ones), so it could close before the second book arrived; art. 9(2)(b)(i) starts it with the last one.
$r13rDay = 86400;
$r13rAgo = static fn(int $days): string => gmdate('Y-m-d H:i:s', time() - $days * $r13rDay);
$r13rCfg = new ReflectionProperty('Security', 'cfg');
$r13rCfgPrev = $r13rCfg->getValue();
$r13rCfg->setValue(null, ['rma_return_window_days' => 14] + (array)$r13rCfgPrev);
$r13rOrderId = 0;
try {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency,
            status, payment_status, payment_method, store_id, created_at) VALUES ('R13R-N4', 'R13R', 'r13r@example.test', '', '{}', 2000, 0, 0, 0, 2000, 'eur', 'shipped', 'paid', 'bank', 'default', ?)")
        ->execute([$r13rAgo(40)]);
    $r13rOrderId = (int)$pdo->lastInsertId();
    $r13rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
    $r13rItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, digital) VALUES (?, ?, ?, ?, 1, 1000, 1000, 0)");
    $r13rItem->execute([$r13rOrderId, $r13rProduct, 'R13R-BOOK-A', 'Book A']);
    $r13rItemA = (int)$pdo->lastInsertId();
    $r13rItem->execute([$r13rOrderId, $r13rProduct, 'R13R-BOOK-B', 'Book B']);
    $r13rItemB = (int)$pdo->lastInsertId();
    $r13rParcel = static function (string $key, int $itemId, string $sku, ?string $deliveredAt) use ($pdo, $r13rOrderId, $r13rAgo): int {
        $pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, tracking_number, idempotency_key, shipped_at, delivered_at, tracking_status)
            VALUES ('default', ?, 'shipped', 'r13r', ?, ?, ?, ?, ?)")->execute([$r13rOrderId, $key, $key, $r13rAgo(30), $deliveredAt, $deliveredAt === null ? 'in_transit' : 'delivered']);
        $shipmentId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO shipment_items (shipment_id, order_item_id, sku, quantity) VALUES (?, ?, ?, 1)')->execute([$shipmentId, $itemId, $sku]);
        return $shipmentId;
    };
    $r13rOrder = static fn(): array => Orders::byId($r13rOrderId) ?: [];

    $r13rParcel('r13r-n4-a', $r13rItemA, 'R13R-BOOK-A', $r13rAgo(20));
    assert_true(Withdrawal::receivedAt($r13rOrder()) === false,
        'R13 N4: with book B in no parcel yet, the goods have not all arrived (was: the period ran from book A, 20 days ago)');
    $r13rB = $r13rParcel('r13r-n4-b', $r13rItemB, 'R13R-BOOK-B', null);
    assert_true(Withdrawal::receivedAt($r13rOrder()) === false && Withdrawal::withinPeriod($r13rOrder()) && Rma::withinReturnWindow($r13rOrder())
        && Withdrawal::refusal($r13rOrder()) === null,
        'R13 N4: while book B is on the way the consumer may still withdraw and return (was: period over after 14 days from book A)');
    $pdo->prepare("UPDATE shipments SET delivered_at = ?, tracking_status = 'delivered' WHERE id = ?")->execute([$r13rAgo(3), $r13rB]);
    assert_equals(strtotime($r13rAgo(3) . ' UTC'), Withdrawal::receivedAt($r13rOrder()), 'R13 N4: the period starts with the last parcel delivered');
    assert_true(Withdrawal::withinPeriod($r13rOrder()) && Rma::withinReturnWindow($r13rOrder()), 'R13 N4: 3 days after the last parcel the order is withdrawable and returnable');
    $pdo->prepare("UPDATE shipments SET delivered_at = ? WHERE id = ?")->execute([$r13rAgo(16), $r13rB]);
    assert_true(!Withdrawal::withinPeriod($r13rOrder()) && !Rma::withinReturnWindow($r13rOrder()), 'R13 N4: 16 days after the last parcel the period is over');
    // A cancelled parcel does not hold the period open.
    $pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, idempotency_key) VALUES ('default', ?, 'cancelled', 'r13r', 'r13r-n4-c')")->execute([$r13rOrderId]);
    assert_equals(strtotime($r13rAgo(16) . ' UTC'), Withdrawal::receivedAt($r13rOrder()), 'R13 N4: a cancelled parcel is not waited for');
} catch (Throwable $e) { $r13rFail('N4 withdrawal period', $e); }
finally {
    $r13rCfg->setValue(null, $r13rCfgPrev);
    if ($r13rOrderId > 0) {
        $pdo->prepare('DELETE FROM shipment_items WHERE shipment_id IN (SELECT id FROM shipments WHERE order_id = ?)')->execute([$r13rOrderId]);
        $pdo->prepare('DELETE FROM shipments WHERE order_id = ?')->execute([$r13rOrderId]);
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$r13rOrderId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r13rOrderId]);
    }
}
