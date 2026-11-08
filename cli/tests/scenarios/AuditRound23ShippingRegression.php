<?php

// Review round 23 (2026-11-08, kolo 7), medium "shipping": cancelling a whole order cancelled its planned parcels in
// the database only. Their carrier labels stayed active, cash on delivery included: the carrier picked the goods up and
// collected. Now the cancellation queues shipping_labels_cancel, the worker cancels the labels at the carrier, and a
// label the carrier keeps is a critical incident and stays listed on the admin order. Low G7: a parcel cancel did not
// take the order lock, so on MySQL a label committed right after it stayed active on the cancelled parcel; a label the
// carrier returned after the whole order was cancelled was recorded as active.
echo "\n--- Audit round 23: carrier labels of a cancelled order ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r23sRoot = dirname(__DIR__, 3);
$r23sFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R23 shipping {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
foreach (['20261019150000_shipment_tracking_event_sequence.php', '20261101130000_shipping_label_shipment.php', '20261101140000_shipment_cod_amount.php'] as $r23sMigration) {
    (require $r23sRoot . '/migrations/' . $r23sMigration)->up($pdo, Db::isSqlite());
}
Db::forgetSchema();
$r23sJobsDir = test_fixture_path('r23s-jobs');
JobQueue::setDir($r23sJobsDir);
$r23sCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$r23sWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy) VALUES
    (97581, 'default', {$r23sCategory}, 'R23S-A', 'r23s-a', 'simple', 'R23S goods A', 1000, 'eur', 50, 'active', 'deny'),
    (97582, 'default', {$r23sCategory}, 'R23S-B', 'r23s-b', 'simple', 'R23S goods B', 500, 'eur', 50, 'active', 'deny')");
Warehouse::setStock($r23sWarehouse, 'R23S-A', 50, 'count', 'test', 'r23s-opening-a');
Warehouse::setStock($r23sWarehouse, 'R23S-B', 50, 'count', 'test', 'r23s-opening-b');
$r23sCustomer = ['name' => 'R23S', 'email' => 'r23s@example.test', 'phone' => '', 'address' => [],
    'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur',
    'payment_method_snapshot' => json_encode(['code' => 'cod', 'service_type' => 'cod'])];
$r23sLine = static fn(int $id, string $sku): array => ['product_id' => $id, 'variant_id' => null, 'sku' => $sku, 'name' => $sku, 'qty' => 1, 'price' => $id === 97581 ? 1000 : 500, 'attrs' => []];
$r23sOrder = static fn(): int => Orders::create([$r23sLine(97581, 'R23S-A'), $r23sLine(97582, 'R23S-B')], $r23sCustomer, 'cod');
$r23sParcel = static function (int $orderId, int $productId, string $sku): int {
    $items = array_column(Orders::items($orderId), null, 'product_id');
    $wh = Fulfillment::shippable($orderId)[0]['warehouse_id'] ?? null;
    return Fulfillment::createShipment($orderId, $wh, [['order_item_id' => (int)$items[$productId]['id'], 'sku' => $sku, 'quantity' => 1]], 'r23s-' . $sku . '-' . $orderId);
};
$r23sLabels = static fn(int $orderId): array => array_map(static fn(array $r): string => $r['awb'] . ':' . $r['status'],
    $pdo->query('SELECT awb, status FROM shipping_labels WHERE order_id = ' . $orderId . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
$r23sJobs = static fn(int $orderId): array => array_values(array_filter(
    array_map(static fn(string $f): array => json_decode((string)file_get_contents($f), true) ?: [], glob($r23sJobsDir . '/pending/*.json') ?: []),
    static fn(array $job): bool => ($job['type'] ?? '') === 'shipping_labels_cancel' && (int)($job['payload']['order_id'] ?? 0) === $orderId));
$r23sCritical = static fn(int $orderId): bool => (bool)$pdo->query("SELECT 1 FROM operational_incidents WHERE code = 'shipping.label_still_active' AND severity = 'critical'
    AND (context_json = '{\"order_id\":{$orderId}}' OR context_json LIKE '{\"order_id\":{$orderId},%')")->fetchColumn();

$r23sCarrier = new class implements ShippingLifecycleAdapter {
    /** @var list<string> */
    public array $cancelled = [];
    public ?Closure $whileAnswering = null;
    public function getRates(array $params): array { return []; }
    public function pickupPoints(string $country, string $postalCode = ''): array { return []; }
    public function generateLabel(array $order): array {
        if ($this->whileAnswering) ($this->whileAnswering)($order);
        return ['awb' => 'R23S-' . $order['id'] . '-' . ($order['shipment_id'] ?? 0) . '-' . $order['label_sequence'], 'label_data' => ''];
    }
    public function cancelLabel(string $awb): array { $this->cancelled[] = $awb; return ['awb' => $awb, 'status' => 'cancelled']; }
    public function trackShipment(string $awb): array { return []; }
    public function supportsCOD(): bool { return true; }
    public function carrierId(): string { return 'r23s-carrier'; }
};
$r23sPlain = new class implements ShippingAdapter {
    public function getRates(array $params): array { return []; }
    public function generateLabel(array $order): array { return ['awb' => 'R23P-' . $order['id'] . '-' . ($order['shipment_id'] ?? 0), 'label_data' => '']; }
    public function trackShipment(string $awb): array { return []; }
    public function supportsCOD(): bool { return true; }
    public function carrierId(): string { return 'r23s-plain'; }
};
ShippingService::setAdapters(['r23s-carrier' => $r23sCarrier, 'r23s-plain' => $r23sPlain]);

try {
    // Two labelled parcels of a cash-on-delivery order; the whole order is cancelled.
    $r23s1 = $r23sOrder();
    $r23sA = $r23sParcel($r23s1, 97581, 'R23S-A');
    $r23sB = $r23sParcel($r23s1, 97582, 'R23S-B');
    $r23sLabelA = ShippingService::generateLabel($r23s1, 'r23s-carrier', $r23sA);
    $r23sLabelB = ShippingService::generateLabel($r23s1, 'r23s-carrier', $r23sB);
    assert_true((int)$r23sLabelA['cod_amount'] > 0, 'R23 shipping setup: the first parcel label collects the cash on delivery');
    OrderWorkflow::transition($r23s1, 'cancelled', 'R23S cancel');
    assert_equals([1, [$r23sLabelA['awb'], $r23sLabelB['awb']]], [count($r23sJobs($r23s1)), array_column(ShippingService::strandedLabels($r23s1), 'awb')],
        'R23 shipping: cancelling the order queues the cancellation of its labels; until it runs they are listed as still active');
    $r23sLeft = ShippingService::cancelOrderLabels($r23s1);
    assert_equals([[], [$r23sLabelA['awb'], $r23sLabelB['awb']], [$r23sLabelA['awb'] . ':cancelled', $r23sLabelB['awb'] . ':cancelled'], []],
        [$r23sLeft, $r23sCarrier->cancelled, $r23sLabels($r23s1), ShippingService::strandedLabels($r23s1)],
        'R23 shipping: the cancelled order\'s labels (COD included) are cancelled at the carrier (was: active, picked up and collected)');
    assert_equals([[], 2], [ShippingService::cancelOrderLabels($r23s1), count($r23sCarrier->cancelled)], 'R23 shipping: a repeated job calls the carrier for nothing');
    $r23sWorker = (string)file_get_contents($r23sRoot . '/cli/worker.php');
    assert_true(str_contains($r23sWorker, "'shipping_labels_cancel'") && str_contains($r23sWorker, 'ShippingService::cancelOrderLabels('),
        'R23 shipping: the worker runs the label cancellation job');

    // An order without labels queues nothing.
    $r23s2 = $r23sOrder();
    OrderWorkflow::transition($r23s2, 'cancelled', 'R23S cancel without labels');
    assert_equals([], $r23sJobs($r23s2), 'R23 shipping: an order without labels queues no label job');
} catch (Throwable $e) {
    $r23sFail('order cancel', $e);
}

try {
    // An order-level label at a carrier that cannot cancel: reported as critical (e-mailed) and shown on the order.
    $r23s3 = $r23sOrder();
    $r23sPlainLabel = ShippingService::generateLabel($r23s3, 'r23s-plain');
    OrderWorkflow::transition($r23s3, 'cancelled', 'R23S cancel plain');
    assert_equals([[$r23sPlainLabel['awb']], [$r23sPlainLabel['awb'] . ':created'], true, [$r23sPlainLabel['awb']]],
        [ShippingService::cancelOrderLabels($r23s3), $r23sLabels($r23s3), $r23sCritical($r23s3), array_column(ShippingService::strandedLabels($r23s3), 'awb')],
        'R23 shipping: a label the carrier keeps is a critical incident and stays listed for the merchant');
    $r23sAdmin = (string)file_get_contents($r23sRoot . '/src/Entrypoints/Admin/Shipments.php') . file_get_contents($r23sRoot . '/src/Entrypoints/Admin/ShipmentLabels.php');
    assert_true(str_contains($r23sAdmin, 'renderStrandedLabels($orderId); ?>') && str_contains($r23sAdmin, 'ShippingService::strandedLabels($orderId)') && str_contains($r23sAdmin, "'admin.shipment.label_still_active'"),
        'R23 shipping: the admin order shows the labels the carrier still holds');
    foreach (glob($r23sRoot . '/lang/*.php') as $r23sLang) {
        assert_true(str_contains((string)((require $r23sLang)['admin.shipment.label_still_active'] ?? ''), '{label}'), 'R23 shipping: the warning exists in ' . basename($r23sLang));
    }

    // A label of a shipped parcel is not stranded: those goods travel.
    $r23s4 = $r23sOrder();
    $r23sShipped = $r23sParcel($r23s4, 97581, 'R23S-A');
    $r23sShippedLabel = ShippingService::generateLabel($r23s4, 'r23s-carrier', $r23sShipped);
    $r23sOrderLabel = ShippingService::generateLabel($r23s4, 'r23s-carrier');
    $pdo->prepare("UPDATE shipments SET status = 'shipped' WHERE id = ?")->execute([$r23sShipped]);
    $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$r23s4]);
    assert_equals([], ShippingService::strandedLabels($r23s4),
        'R23 shipping: neither the shipped parcel\'s label nor an order label of an order that shipped goods is cancelled (' . $r23sShippedLabel['awb'] . ', ' . $r23sOrderLabel['awb'] . ')');
} catch (Throwable $e) {
    $r23sFail('stranded labels', $e);
}

try {
    // G7: the order is cancelled while the carrier answers: the new label is voided, not recorded as active.
    $r23s5 = $r23sOrder();
    $r23sCarrier->whileAnswering = static function (array $order) use ($r23s5): void {
        if ((int)$order['id'] === $r23s5) OrderWorkflow::transition($r23s5, 'cancelled', 'R23S cancel while labelling');
    };
    $r23sRaced = '';
    try { ShippingService::generateLabel($r23s5, 'r23s-carrier'); } catch (RuntimeException $e) { $r23sRaced = $e->getMessage(); }
    $r23sCarrier->whileAnswering = null;
    $r23sRacedLabels = $r23sLabels($r23s5);
    assert_true(str_contains($r23sRaced, 'cancelled') && count($r23sRacedLabels) === 1 && str_ends_with($r23sRacedLabels[0], ':cancelled')
        && in_array(explode(':', $r23sRacedLabels[0])[0], $r23sCarrier->cancelled, true) && (Orders::byId($r23s5)['tracking_number'] ?? null) === null,
        'R23 G7: a label returned after its order was cancelled is cancelled at the carrier (was: recorded as active) (' . $r23sRaced . ')');

    // G7 on MySQL: a parcel cancel takes the order lock before it changes the parcel, as generateLabel records a label.
    $r23sFulfillment = (string)file_get_contents($r23sRoot . '/src/Fulfillment.php');
    assert_true((bool)preg_match("/function cancel\\(int \\\$shipmentId\\)(?:(?!function ).)*Orders::lockWithin\\((?:(?!function ).)*UPDATE shipments SET status = 'cancelled'/s", $r23sFulfillment),
        'R23 G7: Fulfillment::cancel locks the order before cancelling the parcel (was: no lock, a label committed after it stayed active)');
} catch (Throwable $e) {
    $r23sFail('G7', $e);
} finally {
    ShippingService::setAdapters([]);
}

foreach ([['shipping_labels', 'order_id IN (SELECT id FROM orders WHERE customer_email = ?)'],
    ['shipment_items', 'shipment_id IN (SELECT s.id FROM shipments s JOIN orders o ON o.id = s.order_id WHERE o.customer_email = ?)'],
    ['shipments', 'order_id IN (SELECT id FROM orders WHERE customer_email = ?)'],
    ['order_items', 'order_id IN (SELECT id FROM orders WHERE customer_email = ?)'],
    ['orders', 'customer_email = ?']] as [$r23sTable, $r23sWhere]) {
    try { $pdo->prepare("DELETE FROM {$r23sTable} WHERE {$r23sWhere}")->execute(['r23s@example.test']); } catch (Throwable $e) {}
}
