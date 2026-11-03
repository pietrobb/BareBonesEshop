<?php

// Review round 14 (kolo 6), shipping and withdrawal: T4 cancelling a parcel left its label active at the carrier (it
// was still picked up and collected); two parcels of one order labelled at once sent the carrier one idempotency key
// (one AWB for both); a label the carrier created while its parcel was being cancelled was recorded on the cancelled
// parcel. F3: a bundle ships as its components, but the withdrawal period counted the component units against the
// bundle quantity (half of the parts in parcels counted as all of them). The feed offered a bundle with an e-book
// component as in stock although the shop refuses to sell it.
echo "\n--- Audit round 14: parcel labels at the carrier, bundle withdrawal period, feed ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r14sFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R14 ship {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r14sRoot = dirname(__DIR__, 3);
foreach (['20261019150000_shipment_tracking_event_sequence.php', '20261101130000_shipping_label_shipment.php', '20261101140000_shipment_cod_amount.php'] as $r14sMigration) {
    (require $r14sRoot . '/migrations/' . $r14sMigration)->up($pdo, Db::isSqlite());
}
Db::forgetSchema();
$r14sCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$r14sWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
// Fixed ids: an auto-assigned id follows the highest fixed id of earlier scenarios and collides with later ones.
$pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy) VALUES
    (97461, 'default', {$r14sCategory}, 'R14S-A', 'r14s-a', 'simple', 'R14S goods A', 1000, 'eur', 50, 'active', 'deny'),
    (97462, 'default', {$r14sCategory}, 'R14S-B', 'r14s-b', 'simple', 'R14S goods B', 500, 'eur', 50, 'active', 'deny'),
    (97463, 'default', {$r14sCategory}, 'R14S-SET', 'r14s-set', 'bundle', 'R14S set', 2000, 'eur', 10, 'active', 'deny'),
    (97464, 'default', {$r14sCategory}, 'R14S-EB', 'r14s-eb', 'digital', 'R14S e-book', 700, 'eur', 0, 'active', 'deny'),
    (97465, 'default', {$r14sCategory}, 'R14S-EBSET', 'r14s-ebset', 'bundle', 'R14S set with e-book', 2500, 'eur', 10, 'active', 'deny')");
$pdo->exec("INSERT INTO bundle_components (bundle_product_id, component_product_id, quantity, sort_order) VALUES
    (97463, 97461, 2, 0), (97463, 97462, 1, 1), (97465, 97461, 1, 0), (97465, 97464, 1, 1)");
Warehouse::setStock($r14sWarehouse, 'R14S-A', 50, 'count', 'test', 'r14s-opening-a');
Warehouse::setStock($r14sWarehouse, 'R14S-B', 50, 'count', 'test', 'r14s-opening-b');
$r14sCustomer = static fn(string $payment): array => ['name' => 'R14S', 'email' => 'r14s@example.test', 'phone' => '', 'address' => [],
    'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur',
    'payment_method_snapshot' => json_encode(['code' => $payment, 'service_type' => $payment])];
$r14sLine = static fn(int $id, string $sku, int $qty, int $price): array => ['product_id' => $id, 'variant_id' => null, 'sku' => $sku, 'name' => $sku, 'qty' => $qty, 'price' => $price, 'attrs' => []];
$r14sParcel = static function (int $orderId, array $lines, string $key): int {
    $items = array_column(Orders::items($orderId), null, 'product_id');
    $wh = Fulfillment::shippable($orderId)[0]['warehouse_id'] ?? null;
    $rows = [];
    foreach ($lines as [$productId, $sku, $qty]) $rows[] = ['order_item_id' => (int)$items[$productId]['id'], 'sku' => $sku, 'quantity' => $qty];
    return Fulfillment::createShipment($orderId, $wh, $rows, $key . '-' . $orderId);
};
$r14sLabels = static fn(int $shipmentId): array => array_map(static fn(array $r): string => $r['awb'] . ':' . $r['status'],
    $pdo->query('SELECT awb, status FROM shipping_labels WHERE shipment_id = ' . $shipmentId . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));

// A carrier that creates and cancels labels; a hook may run while it "answers" (a parcel cancelled meanwhile).
$r14sCarrier = new class implements ShippingLifecycleAdapter {
    /** @var list<string> */
    public array $created = [];
    /** @var list<string> */
    public array $cancelled = [];
    public ?Closure $whileAnswering = null;
    public function getRates(array $params): array { return []; }
    public function pickupPoints(string $country, string $postalCode = ''): array { return []; }
    public function generateLabel(array $order): array {
        if ($this->whileAnswering) ($this->whileAnswering)($order);
        $awb = 'R14S-' . $order['id'] . '-' . ($order['shipment_id'] ?? 0) . '-' . $order['label_sequence'];
        $this->created[] = $awb;
        return ['awb' => $awb, 'label_data' => ''];
    }
    public function cancelLabel(string $awb): array { $this->cancelled[] = $awb; return ['awb' => $awb, 'status' => 'cancelled']; }
    public function trackShipment(string $awb): array { return []; }
    public function supportsCOD(): bool { return true; }
    public function carrierId(): string { return 'r14s-carrier'; }
};
// A carrier without label cancellation.
$r14sPlain = new class implements ShippingAdapter {
    public function getRates(array $params): array { return []; }
    public function generateLabel(array $order): array { return ['awb' => 'R14P-' . $order['id'] . '-' . ($order['shipment_id'] ?? 0), 'label_data' => '']; }
    public function trackShipment(string $awb): array { return []; }
    public function supportsCOD(): bool { return true; }
    public function carrierId(): string { return 'r14s-plain'; }
};
ShippingService::setAdapters(['r14s-carrier' => $r14sCarrier, 'r14s-plain' => $r14sPlain]);

try {
    // T4: the labelled parcel is cancelled; its label is cancelled at the carrier too.
    $r14sOrder = Orders::create([$r14sLine(97461, 'R14S-A', 1, 1000), $r14sLine(97462, 'R14S-B', 1, 500)], $r14sCustomer('cod'), 'cod');
    $r14sA = $r14sParcel($r14sOrder, [[97461, 'R14S-A', 1]], 'r14s-a');
    $r14sB = $r14sParcel($r14sOrder, [[97462, 'R14S-B', 1]], 'r14s-b');
    $r14sLabelA = ShippingService::generateLabel($r14sOrder, 'r14s-carrier', $r14sA);
    assert_true(Fulfillment::cancel($r14sA), 'R14 T4 setup: the labelled parcel is cancelled');
    $r14sLeft = method_exists(ShippingService::class, 'cancelParcelLabels') ? ShippingService::cancelParcelLabels($r14sOrder, $r14sA) : null;
    assert_equals([[], [$r14sLabelA['awb']], [$r14sLabelA['awb'] . ':cancelled']], [$r14sLeft, $r14sCarrier->cancelled, $r14sLabels($r14sA)],
        'R14 T4: cancelling a parcel cancels its label at the carrier and records it (was: still active there, picked up and collected)');
    assert_equals([], ShippingService::cancelParcelLabels($r14sOrder, $r14sA), 'R14 T4: a second run calls the carrier for nothing');
    assert_equals(1, count($r14sCarrier->cancelled), 'R14 T4: the carrier is asked to cancel the label once');

    $r14sAdmin = (string)file_get_contents($r14sRoot . '/src/Entrypoints/Admin/Shipments.php');
    assert_true((bool)preg_match("/ShippingService::cancelParcelLabels\\(\\\$orderId, \\(int\\)\\\$shipment\\['id'\\]\\)[^;]*;\\R\\s*\\}, false, false\\);/", $r14sAdmin),
        'R14 T4: the admin parcel cancel cancels its labels at the carrier, outside the order transaction');

    // A carrier that cannot cancel: the label stays active, is reported and the admin is told.
    $r14sPlainOrder = Orders::create([$r14sLine(97461, 'R14S-A', 1, 1000)], $r14sCustomer('cod'), 'cod');
    $r14sPlainParcel = $r14sParcel($r14sPlainOrder, [[97461, 'R14S-A', 1]], 'r14s-plain');
    $r14sPlainLabel = ShippingService::generateLabel($r14sPlainOrder, 'r14s-plain', $r14sPlainParcel);
    Fulfillment::cancel($r14sPlainParcel);
    assert_equals([[$r14sPlainLabel['awb']], [$r14sPlainLabel['awb'] . ':created'], true],
        [ShippingService::cancelParcelLabels($r14sPlainOrder, $r14sPlainParcel), $r14sLabels($r14sPlainParcel), IncidentMonitor::reportedForOrder('shipping.label_still_active', $r14sPlainOrder)],
        'R14 T4: a label the carrier cannot cancel stays active, is reported and returned for the admin message');
    foreach (glob($r14sRoot . '/lang/*.php') as $r14sLang) {
        assert_true(((require $r14sLang)['admin.shipment.msg.cancelled_label_active'] ?? '') !== '', 'R14 T4: the admin message exists in ' . basename($r14sLang));
    }
} catch (Throwable $e) {
    $r14sFail('T4 cancel', $e);
}

try {
    // A cancelled parcel gets no label; a parcel cancelled while the carrier answers gets its new label voided.
    $r14sOrder2 = Orders::create([$r14sLine(97461, 'R14S-A', 1, 1000), $r14sLine(97462, 'R14S-B', 1, 500)], $r14sCustomer('cod'), 'cod');
    $r14sC = $r14sParcel($r14sOrder2, [[97461, 'R14S-A', 1]], 'r14s-c');
    $r14sD = $r14sParcel($r14sOrder2, [[97462, 'R14S-B', 1]], 'r14s-d');
    Fulfillment::cancel($r14sC);
    $r14sCreatedBefore = count($r14sCarrier->created);
    $r14sRefused = '';
    try { ShippingService::generateLabel($r14sOrder2, 'r14s-carrier', $r14sC); } catch (RuntimeException $e) { $r14sRefused = $e->getMessage(); }
    assert_true(str_contains($r14sRefused, 'cancelled') && count($r14sCarrier->created) === $r14sCreatedBefore && $r14sLabels($r14sC) === [],
        'R14: a cancelled parcel gets no label and the carrier is not called (' . $r14sRefused . ')');

    $r14sCarrier->whileAnswering = static function (array $order) use ($r14sD): void {
        if ((int)($order['shipment_id'] ?? 0) === $r14sD) Fulfillment::cancel($r14sD);
    };
    $r14sRaced = '';
    try { ShippingService::generateLabel($r14sOrder2, 'r14s-carrier', $r14sD); } catch (RuntimeException $e) { $r14sRaced = $e->getMessage(); }
    $r14sCarrier->whileAnswering = null;
    $r14sRacedAwb = (string)end($r14sCarrier->created);
    $r14sDRow = array_column(Fulfillment::shipments($r14sOrder2), null, 'id')[$r14sD] ?? [];
    assert_true($r14sRaced !== '' && in_array($r14sRacedAwb, $r14sCarrier->cancelled, true)
        && $r14sLabels($r14sD) === [$r14sRacedAwb . ':cancelled'] && ($r14sDRow['tracking_number'] ?? null) === null,
        'R14: a label created while its parcel was cancelled is cancelled at the carrier and not recorded as the parcel\'s tracking (was: active on the cancelled parcel)');
} catch (Throwable $e) {
    $r14sFail('label on cancelled parcel', $e);
}

try {
    // The carrier idempotency key names the parcel: two parcels of one order labelled at once are two labels.
    $r14sKeys = [];
    JsonHttpClient::setFixtureTransport(static function (string $method, string $url, string $body) use (&$r14sKeys): array {
        $r14sKeys[] = (string)(json_decode($body, true)['idempotency_key'] ?? '');
        return ['status' => 200, 'body' => ['awb' => 'HTTP' . count($r14sKeys), 'label_data' => '']];
    });
    $r14sHttp = new HttpShipping(['integrations' => ['http_shipping' => ['base_url' => 'https://carrier.test', 'api_key' => str_repeat('k', 24)]]]);
    foreach ([['id' => 7, 'shipment_id' => 31, 'label_sequence' => 1], ['id' => 7, 'shipment_id' => 32, 'label_sequence' => 1],
        ['id' => 7, 'shipment_id' => 32, 'label_sequence' => 2], ['id' => 7, 'label_sequence' => 1]] as $r14sOrderRow) {
        $r14sHttp->generateLabel($r14sOrderRow);
    }
    assert_equals(['order-7-parcel-31', 'order-7-parcel-32', 'order-7-parcel-32-2', 'order-7'], $r14sKeys,
        'R14: each parcel has its own carrier idempotency key (was order-7 for both: one AWB for two parcels); an order-level label keeps its key');
} catch (Throwable $e) {
    $r14sFail('idempotency key', $e);
} finally {
    JsonHttpClient::setFixtureTransport(null);
    ShippingService::setAdapters([]);
}

try {
    // F3: 2 sets of (2× A + 1× B). Only the A parts are in a delivered parcel: the goods have not all arrived.
    $r14sSetOrder = Orders::create([$r14sLine(97463, 'R14S-SET', 2, 2000)], $r14sCustomer('cod'), 'cod');
    $r14sFirst = $r14sParcel($r14sSetOrder, [[97463, 'R14S-A', 4]], 'r14s-set1');
    $pdo->prepare("UPDATE shipments SET status = 'shipped', delivered_at = '2026-11-01 10:00:00' WHERE id = ?")->execute([$r14sFirst]);
    $r14sRow = Orders::byId($r14sSetOrder);
    assert_equals(false, Withdrawal::receivedAt($r14sRow),
        'R14 F3: with only the A parts of two sets delivered the withdrawal period has not started (was: 4 component units counted as both sets)');
    $r14sSecond = $r14sParcel($r14sSetOrder, [[97463, 'R14S-B', 1]], 'r14s-set2');
    $pdo->prepare("UPDATE shipments SET status = 'shipped', delivered_at = '2026-11-02 10:00:00' WHERE id = ?")->execute([$r14sSecond]);
    assert_equals(false, Withdrawal::receivedAt(Orders::byId($r14sSetOrder)), 'R14 F3: one of the two B parts is still in no parcel');
    $r14sThird = $r14sParcel($r14sSetOrder, [[97463, 'R14S-B', 1]], 'r14s-set3');
    $pdo->prepare("UPDATE shipments SET status = 'shipped', delivered_at = '2026-11-03 10:00:00' WHERE id = ?")->execute([$r14sThird]);
    assert_equals(strtotime('2026-11-03 10:00:00 UTC'), Withdrawal::receivedAt(Orders::byId($r14sSetOrder)),
        'R14 F3: the period starts with the parcel that completed both sets');
} catch (Throwable $e) {
    $r14sFail('F3 bundle withdrawal', $e);
}

try {
    // The feed: a bundle with an e-book component is not offered as in stock (checkout refuses to sell it).
    $r14sFeed = MarketingFeed::google('https://r14s.example.test');
    $r14sAvailability = static function (string $sku) use ($r14sFeed): string {
        foreach (explode('<item>', $r14sFeed) as $item) {
            if (str_contains($item, '<g:id>' . $sku . '</g:id>') && preg_match('#<g:availability>([^<]*)</g:availability>#', $item, $m)) return $m[1];
        }
        return '';
    };
    assert_equals(['out of stock', 'in stock'], [$r14sAvailability('R14S-EBSET'), $r14sAvailability('R14S-SET')],
        'R14: the feed offers a bundle with an e-book component as out of stock (was in stock); a physical bundle stays in stock');
} catch (Throwable $e) {
    $r14sFail('feed', $e);
}

foreach ([['shipping_labels', 'order_id IN (SELECT id FROM orders WHERE customer_email = ?)'],
    ['shipment_items', 'shipment_id IN (SELECT s.id FROM shipments s JOIN orders o ON o.id = s.order_id WHERE o.customer_email = ?)'],
    ['shipments', 'order_id IN (SELECT id FROM orders WHERE customer_email = ?)'],
    ['order_items', 'order_id IN (SELECT id FROM orders WHERE customer_email = ?)'],
    ['orders', 'customer_email = ?']] as [$r14sTable, $r14sWhere]) {
    try { $pdo->prepare("DELETE FROM {$r14sTable} WHERE {$r14sWhere}")->execute(['r14s@example.test']); } catch (Throwable $e) {}
}
$pdo->exec('DELETE FROM bundle_components WHERE bundle_product_id IN (97463, 97465)');
