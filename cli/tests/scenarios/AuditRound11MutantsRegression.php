<?php

// Round 11 review (2026-11-01) "tests": mutants that survived the suite — stock reservation (warehouse priority, the exact
// last piece, rollback of a refused reservation, backorder remainder), adjustStock (down to exactly the reservations,
// up to exactly the limit, no-op change, stock.adjusted before/after), refund idempotency (same event, same refund id
// under another event), the currency of a refund.reported notification and the snapshot fingerprint (key order is not
// part of it, every value is).
echo "\n--- Audit round 11: surviving mutants ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r11mFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R11 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r11mDb = Db::get();
$r11mTag = bin2hex(random_bytes(3));
$r11mThrows = static function (callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};

// Stock reservation and adjustStock.
try {
    $r11mDb->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r11m-high-{$r11mTag}', 'R11M high', 90, 0)");
    $r11mHigh = (int)$r11mDb->lastInsertId();
    $r11mDb->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r11m-low-{$r11mTag}', 'R11M low', -5, 0)");
    $r11mLow = (int)$r11mDb->lastInsertId();
    $r11mDb->exec("INSERT INTO categories (store_id, name, slug) VALUES ('default', 'R11M', 'r11m-cat-{$r11mTag}')");
    $r11mCategory = (int)$r11mDb->lastInsertId();
    $r11mProduct = $r11mDb->prepare("INSERT INTO products (store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES ('default', {$r11mCategory}, ?, ?, ?, 'simple', 1000, 'eur', 'active', 0, 'deny')");
    $r11mStock = $r11mDb->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, ?, ?, ?)");
    $r11mRow = static function (int $warehouseId, string $sku) use ($r11mDb): array {
        $stmt = $r11mDb->prepare('SELECT qty, reserved FROM warehouse_stock WHERE warehouse_id = ? AND sku = ?');
        $stmt->execute([$warehouseId, $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['qty' => -1, 'reserved' => -1];
        return [(int)$row['qty'], (int)$row['reserved']];
    };
    $r11mSku = static function (string $name, array $stock) use ($r11mProduct, $r11mStock, $r11mTag): string {
        $sku = 'R11M-' . $name . '-' . $r11mTag;
        $r11mProduct->execute(['R11M ' . $name, strtolower($sku), $sku]);
        foreach ($stock as [$warehouseId, $qty, $reserved]) $r11mStock->execute([$warehouseId, $sku, $qty, $reserved]);
        Warehouse::syncProductStock([$sku]);
        return $sku;
    };
    $r11mAlloc = static fn(array $allocations): array => array_map(static fn(array $a): string => ($a['warehouse_id'] ?? '-') . ':' . $a['qty'] . ':' . $a['status'], $allocations);

    // The highest-priority warehouse is reserved first.
    $sku = $r11mSku('prio', [[$r11mLow, 5, 0], [$r11mHigh, 2, 0]]);
    assert_equals(["{$r11mHigh}:2:reserved", "{$r11mLow}:1:reserved"], $r11mAlloc(Warehouse::reserve($sku, 3)),
        'R11 reserve: the highest-priority warehouse gives its stock first, the rest comes from the next one');
    // The exact last free pieces can be reserved.
    $sku = $r11mSku('last', [[$r11mHigh, 3, 1]]);
    assert_equals([["{$r11mHigh}:2:reserved"], [3, 3]], [$r11mAlloc(Warehouse::reserve($sku, 2)), $r11mRow($r11mHigh, $sku)],
        'R11 reserve: the last free pieces are reserved (free stock equal to the request)');
    // A refused reservation leaves nothing reserved.
    $sku = $r11mSku('deny', [[$r11mHigh, 2, 0], [$r11mLow, 1, 0]]);
    $error = $r11mThrows(static fn() => Warehouse::reserve($sku, 5));
    assert_equals([true, [2, 0], [1, 0]], [str_contains($error, 'chýba 2'), $r11mRow($r11mHigh, $sku), $r11mRow($r11mLow, $sku)],
        'R11 reserve: a reservation that cannot be met is refused and its partial allocations are released');
    // Backorder: the free stock is reserved, the remainder is a backorder line.
    $sku = $r11mSku('back', [[$r11mHigh, 2, 0]]);
    assert_equals([["{$r11mHigh}:2:reserved", '-:3:backorder'], [2, 2]], [$r11mAlloc(Warehouse::reserve($sku, 5, Inventory::BACKORDER)), $r11mRow($r11mHigh, $sku)],
        'R11 reserve: with backorders the free stock is reserved and only the remainder is backordered');

    // adjustStock down to exactly the reservations, not below.
    $sku = $r11mSku('adj', [[$r11mHigh, 5, 2]]);
    $events = static fn() => $r11mDb->query("SELECT payload FROM event_outbox WHERE event_type = 'stock.adjusted' AND payload LIKE " . $r11mDb->quote('%' . $sku . '%') . " ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    Warehouse::adjustStock($r11mHigh, $sku, -3, 'adjustment');
    $adjusted = json_decode((string)($events()[0] ?? '{}'), true);
    assert_equals([[2, 2], 3, 0, 'adjustment'], [$r11mRow($r11mHigh, $sku), $adjusted['before'] ?? null, $adjusted['after'] ?? null, $adjusted['reason'] ?? null],
        'R11 adjustStock: stock can be lowered to exactly the reserved count; stock.adjusted reports available 3 -> 0');
    assert_equals([true, [2, 2]], [str_contains($r11mThrows(static fn() => Warehouse::adjustStock($r11mHigh, $sku, -1)), 'violate reservations'), $r11mRow($r11mHigh, $sku)],
        'R11 adjustStock: lowering below the reservations is refused and changes nothing');
    $movements = static fn() => (int)$r11mDb->query("SELECT COUNT(*) FROM stock_movements WHERE sku = " . $r11mDb->quote($sku))->fetchColumn();
    $count = $movements();
    $eventCount = count($events());
    Warehouse::adjustStock($r11mHigh, $sku, 0);
    assert_equals([$count, $eventCount, [2, 2]], [$movements(), count($events()), $r11mRow($r11mHigh, $sku)],
        'R11 adjustStock: a zero change books no movement and no event');
    // Up to exactly the stock limit, not above.
    $sku = $r11mSku('max', [[$r11mHigh, Warehouse::MAX_QTY - 1, 0]]);
    Warehouse::adjustStock($r11mHigh, $sku, 1);
    assert_equals([Warehouse::MAX_QTY, 0], $r11mRow($r11mHigh, $sku), 'R11 adjustStock: stock can be raised to exactly the limit');
    assert_equals([true, [Warehouse::MAX_QTY, 0]], [str_contains($r11mThrows(static fn() => Warehouse::adjustStock($r11mHigh, $sku, 1)), 'stock limit'), $r11mRow($r11mHigh, $sku)],
        'R11 adjustStock: raising above the limit is refused and changes nothing');
} catch (Throwable $e) { $r11mFail('stock', $e); }

// Refund idempotency and the currency of refund.reported.
try {
    $r11mOrder = static function (string $paymentStatus, string $provider, string $paymentId, string $currency = 'eur') use ($r11mDb): int {
        $snapshot = ['provider' => $provider, 'service_type' => 'online_card'];
        $r11mDb->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
            VALUES (?, 'R11M', 'r11m@example.test', '', '{}', 1000, 0, 0, 0, 1000, ?, 'delivered', ?, ?, ?, ?, 'default', " . Db::now() . ")")
            ->execute(['R11M-' . bin2hex(random_bytes(5)), $currency, $paymentStatus, $provider, $paymentId, json_encode($snapshot)]);
        return (int)$r11mDb->lastInsertId();
    };
    $r11mState = static fn(int $orderId): array => [(string)Orders::byId($orderId)['payment_status'], PaymentService::refundedAmount($orderId)];

    $r11mApply = new ReflectionMethod(PaymentService::class, 'applyWebhookEvent');
    $o = $r11mOrder('paid', 'stripe', 'pi_r11m_' . $r11mTag);
    assert_true(PaymentService::bookProviderRefund($o, 'stripe', 're_r11m_a', 400, 'refund.succeeded', 'payment_stripe_r11m_a_' . $o),
        'R11 refund: a provider refund is booked');
    assert_equals([false, ['partially_refunded', 400]],
        [PaymentService::bookProviderRefund($o, 'stripe', 're_r11m_a', 400, 'refund.succeeded', 'payment_stripe_r11m_a_' . $o), $r11mState($o)],
        'R11 refund: the same event processed again books nothing');
    assert_equals([false, ['partially_refunded', 400]],
        [PaymentService::bookProviderRefund($o, 'stripe', 're_r11m_a', 400, 'charge.refunded', 'payment_stripe_r11m_b_' . $o), $r11mState($o)],
        'R11 refund: the same refund id under another event books nothing');
    assert_equals([true, ['refunded', 1000]],
        [PaymentService::bookProviderRefund($o, 'stripe', 're_r11m_c', 600, 'refund.succeeded', 'payment_stripe_r11m_c_' . $o), $r11mState($o)],
        'R11 refund: a second refund id is booked and completes the refund');

    // A provider refund without a refund id (only the event names it) redelivered under the same event: booked once.
    $o = $r11mOrder('paid', 'gopay', '30' . random_int(10000000, 99999999));
    $r11mSucceeded = static fn() => $r11mApply->invoke(null, $o, 'gopay', 'refund.succeeded', 'payment_gopay_r11m_noid_' . $o, ['amount' => 300, 'currency' => 'EUR']);
    $r11mItemRefund = $r11mDb->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, 0, 0, 300, 'eur', 'pending', ?)");
    $r11mItemRefund->execute([$o, 'r11m_item_a_' . $o]);
    $r11mItemRefund->execute([$o, 'r11m_item_b_' . $o]);
    $r11mSucceeded();
    $r11mSucceeded();
    $r11mItems = $r11mDb->prepare('SELECT status FROM order_refunds WHERE order_id = ? ORDER BY id');
    $r11mItems->execute([$o]);
    assert_equals([['partially_refunded', 300], ['succeeded', 'pending']], [$r11mState($o), $r11mItems->fetchAll(PDO::FETCH_COLUMN)],
        'R11 refund: a refund without a refund id redelivered under the same event is booked once and settles one credit note, not a second one');
    $r11mReported = static fn(int $orderId, string $currency, string $key) => $r11mApply->invoke(null, $orderId, 'gopay', 'refund.reported', $key,
        ['state' => 'REFUNDED', 'currency' => $currency, 'amount' => 1000, 'provider_ref' => (string)Orders::byId($orderId)['payment_id']]);
    $o = $r11mOrder('paid', 'gopay', '31' . random_int(10000000, 99999999));
    PaymentService::requestRefund($o, 1000, 'r11m_request_' . $o);
    $r11mReported($o, 'CZK', 'payment_gopay_r11m_czk_' . $o);
    assert_equals(['refund_pending', 0], $r11mState($o), 'R11 refund.reported: a notification in another currency than the order settles nothing');
    $r11mReported($o, 'EUR', 'payment_gopay_r11m_eur_' . $o);
    assert_equals(['refunded', 1000], $r11mState($o), 'R11 refund.reported: the notification in the order currency settles the refund in flight');
    $o = $r11mOrder('paid', 'gopay', '32' . random_int(10000000, 99999999));
    PaymentService::requestRefund($o, 600, 'r11m_partial_' . $o);
    $r11mReported($o, 'EUR', 'payment_gopay_r11m_part_' . $o);
    assert_equals(['refund_pending', 0], $r11mState($o), 'R11 refund.reported: "refunded" does not settle a request smaller than the remaining balance');
} catch (Throwable $e) { $r11mFail('refunds', $e); }

// Snapshot fingerprint: canonical (key order is irrelevant at every level), covers every value.
try {
    $snapshot = VatService::buildSnapshot([['qty' => 2, 'price' => 1230, 'sku' => 'r11m']], ['seller_country' => 'SK', 'buyer_country' => 'SK'],
        ['currency' => 'eur', 'effective_date' => '2026-06-01', 'shipping' => 500]);
    $reverse = static function (array $value) use (&$reverse): array {
        if (!array_is_list($value)) $value = array_reverse($value, true);
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $reverse($item);
        return $value;
    };
    $reordered = $reverse($snapshot);
    $topOnly = array_reverse($snapshot, true);
    $nestedOnly = $snapshot;
    $nestedOnly['totals'] = array_reverse($snapshot['totals'], true);
    $nestedOnly['components'][0] = array_reverse($snapshot['components'][0], true);
    assert_equals(['', '', ''], [$r11mThrows(static fn() => VatService::validateSnapshot($reordered)), $r11mThrows(static fn() => VatService::validateSnapshot($topOnly)),
        $r11mThrows(static fn() => VatService::validateSnapshot($nestedOnly))],
        'R11 fingerprint: a snapshot stored with another key order (top level or nested) still verifies');
    $again = VatService::buildSnapshot([['qty' => 2, 'price' => 1230, 'sku' => 'r11m']], ['seller_country' => 'SK', 'buyer_country' => 'SK'],
        ['currency' => 'eur', 'effective_date' => '2026-06-01', 'shipping' => 500]);
    $otherDay = VatService::buildSnapshot([['qty' => 2, 'price' => 1230, 'sku' => 'r11m']], ['seller_country' => 'SK', 'buyer_country' => 'SK'],
        ['currency' => 'eur', 'effective_date' => '2026-06-02', 'shipping' => 500]);
    assert_true(preg_match('/^[0-9a-f]{64}$/', (string)$snapshot['fingerprint']) === 1 && $again['fingerprint'] === $snapshot['fingerprint'] && $otherDay['fingerprint'] !== $snapshot['fingerprint'],
        'R11 fingerprint: deterministic SHA-256, a different date gives a different fingerprint');
    $tampered = $snapshot;
    $tampered['effective_date'] = '2026-06-02';
    $tamperedNested = $snapshot;
    $tamperedNested['providers']['tax'] = 'vat_other';
    assert_equals([true, true], [str_contains($r11mThrows(static fn() => VatService::validateSnapshot($tampered)), 'fingerprint mismatch'),
        str_contains($r11mThrows(static fn() => VatService::validateSnapshot($tamperedNested)), 'fingerprint mismatch')],
        'R11 fingerprint: a changed top-level or nested value is detected');
} catch (Throwable $e) { $r11mFail('fingerprint', $e); }
