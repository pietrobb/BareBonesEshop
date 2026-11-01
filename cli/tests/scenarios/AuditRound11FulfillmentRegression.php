<?php

// Round 11 review (2026-11-01) "fulfillment": H8 a B2B purchase on invoice is accepted, invoiced with the company's
// payment terms and marked paid later; COD is paid once the carrier delivers; one labelled COD fee (checkout = invoice);
// a delivery note per parcel without internal notes; H9 carrier labels per parcel with the COD amount of the order and
// no invented tracking number when the carrier API is not connected.
echo "\n--- Audit round 11: fulfillment (purchase on invoice, COD, delivery notes, carrier labels) ---\n";

require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shipments.php';
$r11fSavedArgv = $argv ?? [];
$argv = ['worker.php', '--once'];
require_once TEST_CLI_DIR . '/../cli/worker.php';
$argv = $r11fSavedArgv;
Bootstrap::initStoreContext('/', '', 'default');
$r11fFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R11 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r11fDb = Db::get();
$r11fCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$r11fWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
// Fixed ids: an auto-assigned id follows the highest fixed id of earlier scenarios and collides with later ones.
$pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy) VALUES
    (98971, 'default', {$r11fCategory}, 'R11F-A', 'r11f-a', 'simple', 'R11F goods A', 1000, 'eur', 20, 'active', 'deny'),
    (98972, 'default', {$r11fCategory}, 'R11F-B', 'r11f-b', 'simple', 'R11F goods B', 500, 'eur', 20, 'active', 'deny')");
Warehouse::setStock($r11fWarehouse, 'R11F-A', 20, 'count', 'test', 'r11f-opening-a');
Warehouse::setStock($r11fWarehouse, 'R11F-B', 20, 'count', 'test', 'r11f-opening-b');
$r11fCustomer = ['name' => 'R11F Company', 'email' => 'r11f@example.test', 'phone' => '', 'address' => [],
    'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'];
$r11fOrder = static fn(string $method, array $snapshot): int => Orders::create([['product_id' => 98971, 'variant_id' => null, 'sku' => 'R11F-A',
    'name' => 'R11F goods A', 'qty' => 1, 'price' => 1000, 'attrs' => []]],
    $r11fCustomer + ['payment_method_snapshot' => json_encode($snapshot)], $method);
$r11fWithin = static function (int $orderId, string $to) use ($r11fDb): array {
    $r11fDb->beginTransaction();
    try {
        Orders::lockWithin($orderId);
        $jobs = OrderWorkflow::transitionWithin($orderId, $to, 'R11 test');
        $r11fDb->commit();
        return $jobs;
    } catch (Throwable $e) {
        if ($r11fDb->inTransaction()) $r11fDb->rollBack();
        throw $e;
    }
};

// H8: purchase on invoice — accepted from 'pending', invoiced on acceptance with the company's terms, paid later.
try {
    $r11fInvoiceOrder = $r11fOrder('invoice', ['code' => 'invoice', 'service_type' => 'invoice', 'label' => 'Nákup na faktúru', 'payment_terms_days' => 30]);
    $r11fRow = Orders::byId($r11fInvoiceOrder);
    assert_true(in_array('processing', OrderWorkflow::allowedFor($r11fRow), true) && OrderWorkflow::adminRejection($r11fRow, 'processing', false) === null,
        'R11 H8: the admin may accept an unpaid purchase on invoice (pending -> processing) without marking it paid');
    assert_true(Fulfillment::orderShippable($r11fRow), 'R11 H8: an accepted-to-be purchase on invoice can be fulfilled before its payment');
    $r11fJobs = $r11fWithin($r11fInvoiceOrder, 'processing');
    assert_true(in_array('invoice_issue', array_column($r11fJobs, 0), true), 'R11 H8: accepting the purchase on invoice issues its invoice');
    $r11fRow = Orders::byId($r11fInvoiceOrder);
    assert_equals(['processing', 'pending'], [$r11fRow['status'], $r11fRow['payment_status']], 'R11 H8: the accepted order waits for payment');
    $r11fLabel = method_exists('OrderStatusLabels', 'paymentOf') ? OrderStatusLabels::paymentOf($r11fRow, 'sk') : OrderStatusLabels::payment('pending', 'sk');
    assert_true($r11fLabel !== OrderStatusLabels::payment('pending', 'sk') && str_contains(mb_strtolower($r11fLabel), 'faktúr'),
        'R11 H8: the admin shows the order as "on invoice, awaiting payment", not as a failed or missing payment');
    handleInvoiceIssue(['order_id' => $r11fInvoiceOrder], []);
    $r11fInvoice = InvoiceService::byOrderId($r11fInvoiceOrder);
    assert_true($r11fInvoice !== null, 'R11 H8: the invoice exists after acceptance');
    assert_equals(ShopTime::addDays((string)($r11fInvoice['issued_at'] ?? ''), 30), (string)($r11fInvoice['due_date'] ?? ''),
        'R11 H8: the invoice is due after the payment terms frozen on the order (30 days), not the seller default');
    $r11fWithin($r11fInvoiceOrder, 'shipped');
    $r11fAwaiting = array_map('intval', array_column(OfflinePaymentQueue::awaiting(), 'id'));
    assert_true(in_array($r11fInvoiceOrder, $r11fAwaiting, true), 'R11 H8: the shipped, unpaid purchase on invoice is in the awaiting-payment queue');
    assert_true(OfflinePaymentQueue::confirm($r11fInvoiceOrder, 'r11-admin', 'R11 invoice paid'), 'R11 H8: the payment of the invoice is recorded');
    $r11fRow = Orders::byId($r11fInvoiceOrder);
    assert_equals(['shipped', 'paid'], [$r11fRow['status'], $r11fRow['payment_status']], 'R11 H8: marking it paid keeps the order shipped and counts the money');
} catch (Throwable $e) {
    $r11fFail('H8', $e);
}

// COD: the carrier collected the money on delivery, so the payment is paid (revenue), exactly once.
try {
    $r11fCod = $r11fOrder('cod', ['code' => 'cod', 'service_type' => 'cod']);
    OrderWorkflow::transition($r11fCod, 'shipped', 'R11 COD handover');
    OrderWorkflow::transition($r11fCod, 'delivered', 'R11 COD delivered');
    $r11fRow = Orders::byId($r11fCod);
    $r11fCollected = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM payment_transactions WHERE order_id = ? AND status = 'paid'");
    $r11fCollected->execute([$r11fCod]);
    assert_equals(['delivered', 'paid', [1, (int)$r11fRow['total']]], [$r11fRow['status'], $r11fRow['payment_status'], array_map('intval', $r11fCollected->fetch(PDO::FETCH_NUM))],
        'R11 COD: delivery records the collected cash on delivery as paid, with the order total');
} catch (Throwable $e) {
    $r11fFail('COD collected', $e);
}

// One COD fee: the carrier's COD surcharge is part of the labelled COD fee; the shipping line is the carriage only.
$r11fCodOrderId = 0;
$r11fActivePromotions = $pdo->query('SELECT id FROM promotions WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec('UPDATE promotions SET active = 0'); // a free-shipping promotion of an earlier scenario would waive the carriage
try {
    ConsentService::setDefinitionsForTesting([]);
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $r11fBuyer = ['name' => 'R11F COD Buyer', 'email' => 'r11f-cod@example.com', 'phone' => '+421900000012', 'country' => 'SK',
        'street' => 'Dobierková 2', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta', 'payment_method' => 'cod', 'locale' => 'sk'];
    $r11fCreated = ManualOrder::create(ManualOrder::parseLines("R11F-A 1\nR11F-B 1")['lines'], $r11fBuyer, false);
    $r11fCodOrderId = (int)($r11fCreated['order_id'] ?? 0);
    assert_true($r11fCreated['ok'] && $r11fCodOrderId > 0, 'R11 COD fee: a COD order is entered (' . ($r11fCreated['error'] ?? 'ok') . ')');
    $r11fRow = Orders::byId($r11fCodOrderId);
    $r11fRate = json_decode((string)$r11fRow['shipping_rate_snapshot'], true) ?: [];
    $r11fPayment = json_decode((string)$r11fRow['payment_method_snapshot'], true) ?: [];
    $r11fSurcharge = (int)($r11fRate['cod_surcharge'] ?? 0);
    assert_true($r11fSurcharge > 0, 'R11 COD fee setup: the Packeta rate has a COD surcharge');
    assert_equals([(int)($r11fRate['price'] ?? 0) - $r11fSurcharge, $r11fSurcharge, (int)($r11fPayment['fee'] ?? -1)],
        [(int)$r11fRow['shipping_cost'], (int)($r11fPayment['cod_surcharge'] ?? 0), (int)$r11fRow['payment_fee']],
        'R11 COD fee: shipping is the carriage, the COD surcharge is inside the one COD fee frozen on the order (checkout = invoice)');
} catch (Throwable $e) {
    $r11fFail('COD fee', $e);
} finally {
    foreach ($r11fActivePromotions as $r11fPromotion) $pdo->prepare('UPDATE promotions SET active = 1 WHERE id = ?')->execute([(int)$r11fPromotion]);
}

// Delivery notes per parcel, without internal notes; carrier labels per parcel with the order's COD amount.
$r11fEnvMock = getenv('SHIP_MOCK_LABELS');
$r11fEnvKey = $_ENV['SHIP_PACKETA_API_KEY'] ?? null;
try {
    if ($r11fCodOrderId <= 0) throw new RuntimeException('no COD order');
    $r11fItems = array_column(Orders::items($r11fCodOrderId), null, 'sku');
    $r11fWh = Fulfillment::shippable($r11fCodOrderId)[0]['warehouse_id'] ?? null;
    $r11fShipA = Fulfillment::createShipment($r11fCodOrderId, $r11fWh, [['order_item_id' => (int)$r11fItems['R11F-A']['id'], 'sku' => 'R11F-A', 'quantity' => 1]], 'r11f-a-' . $r11fCodOrderId);
    $r11fShipB = Fulfillment::createShipment($r11fCodOrderId, $r11fWh, [['order_item_id' => (int)$r11fItems['R11F-B']['id'], 'sku' => 'R11F-B', 'quantity' => 1]], 'r11f-b-' . $r11fCodOrderId);
    $pdo->prepare('UPDATE orders SET notes = ? WHERE id = ?')->execute(["Zvoňte dvakrát\nRefund: re_r11f_legacy", $r11fCodOrderId]);
    Orders::setRefundId($r11fCodOrderId, 're_r11f_new');
    assert_equals("Zvoňte dvakrát\nRefund: re_r11f_legacy", (string)Orders::byId($r11fCodOrderId)['notes'], 'R11 delivery note: a refund id is no longer appended to the customer note');
    $r11fNotes = DeliveryNote::forOrders([$r11fCodOrderId]);
    assert_equals([['R11F-A'], ['R11F-B']], array_map(static fn(array $n): array => array_column($n['items'], 'sku'), $r11fNotes),
        'R11 delivery note: one note per parcel, each with the goods of that parcel');
    $r11fHtml = DeliveryNote::html($r11fNotes);
    assert_true(str_contains($r11fHtml, 'Zvoňte dvakrát') && !str_contains($r11fHtml, 're_r11f') && !str_contains($r11fHtml, 'Refund:'),
        'R11 delivery note: the customer note is printed, internal refund references are not');
    assert_equals(1, count(DeliveryNote::forOrders([$r11fCodOrderId], $r11fShipB)), 'R11 delivery note: the note of one parcel can be printed alone');

    // Before its migration has run a label per parcel is refused with what to do (an order-level label still works).
    $r11fMigration = require TEST_CLI_DIR . '/../migrations/20261101130000_shipping_label_shipment.php';
    if (!Db::hasColumn('shipping_labels', 'shipment_id')) {
        $r11fUnmigrated = '';
        try {
            ShippingService::generateLabel($r11fCodOrderId, 'packeta', $r11fShipA);
        } catch (RuntimeException $e) {
            $r11fUnmigrated = $e->getMessage();
        }
        assert_true(str_contains($r11fUnmigrated, 'cli/migrate.php'), 'R11 H9: before the migration a label per parcel asks to run the migration');
    }
    $r11fMigration->up($pdo, Db::isSqlite());
    $r11fMigration->up($pdo, Db::isSqlite());
    Db::forgetSchema();
    assert_true(Db::hasColumn('shipping_labels', 'shipment_id') && Db::hasColumn('shipping_labels', 'cod_amount'), 'R11 H9: the label migration is applied and idempotent');

    ShippingService::setAdapters(['packeta' => new PacketaShipping(), 'gls' => new GlsShipping()]);
    putenv('SHIP_MOCK_LABELS');
    unset($_ENV['SHIP_MOCK_LABELS'], $_ENV['SHIP_PACKETA_API_KEY']);
    $r11fCarriers = ShippingService::labelCarriers();
    assert_true(isset($r11fCarriers['packeta']) && str_contains((string)$r11fCarriers['packeta'], 'not connected'),
        'R11 H9: without a connected API the admin is told Packeta labels are not available');
    $r11fRefused = null;
    try {
        ShippingService::generateLabel($r11fCodOrderId, 'packeta', $r11fShipA);
    } catch (RuntimeException $e) {
        $r11fRefused = $e->getMessage();
    }
    $r11fLabelCount = static function () use ($pdo, $r11fCodOrderId): int {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM shipping_labels WHERE order_id = ?');
        $stmt->execute([$r11fCodOrderId]);
        return (int)$stmt->fetchColumn();
    };
    assert_true($r11fRefused !== null && $r11fLabelCount() === 0, 'R11 H9: no label and no invented tracking number without the carrier API');
    $_ENV['SHIP_PACKETA_API_KEY'] = 'r11f-key';
    assert_true(str_contains((string)(ShippingService::labelCarriers()['packeta'] ?? ''), 'production API is not connected'),
        'R11 H9: with a production key the admin is told the production Packeta API is not wired (no fake AWB)');
    unset($_ENV['SHIP_PACKETA_API_KEY']);
    $r11fDoctor = array_values(array_filter(Setup::doctor()['checks'] ?? [], static fn(array $c): bool => ($c['name'] ?? '') === 'provider.shipping_labels'));
    assert_true(($r11fDoctor[0]['status'] ?? '') === 'warn' && str_contains((string)($r11fDoctor[0]['message'] ?? ''), 'packeta'),
        'R11 H9: the doctor warns that Packeta labels are not available');

    putenv('SHIP_MOCK_LABELS=1');
    $r11fTotal = (int)Orders::byId($r11fCodOrderId)['total'];
    $r11fLabelA = ShippingService::generateLabel($r11fCodOrderId, 'packeta', $r11fShipA);
    $r11fLabelB = ShippingService::generateLabel($r11fCodOrderId, 'packeta', $r11fShipB);
    $r11fAgain = ShippingService::generateLabel($r11fCodOrderId, 'packeta', $r11fShipA);
    assert_equals([$r11fTotal, 0], [(int)$r11fLabelA['cod_amount'], (int)$r11fLabelB['cod_amount']],
        'R11 H9: the first parcel collects the COD total of the order, the second goes prepaid (the amount is never collected twice)');
    assert_true($r11fLabelA['awb'] !== $r11fLabelB['awb'] && $r11fAgain['awb'] === $r11fLabelA['awb'] && $r11fLabelCount() === 2,
        'R11 H9: one label per parcel, a repeated request returns the existing label');
    $r11fShipRows = array_column(Fulfillment::shipments($r11fCodOrderId), 'tracking_number', 'id');
    assert_equals([$r11fLabelA['awb'], $r11fLabelB['awb']], [$r11fShipRows[$r11fShipA] ?? null, $r11fShipRows[$r11fShipB] ?? null],
        'R11 H9: each parcel carries the tracking number of its label');
    $r11fRequest = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Request.php');
    assert_true(function_exists('handleOrderShipmentLabel') && str_contains($r11fRequest, "'order:shipment_label' => handleOrderShipmentLabel()"),
        'R11 H9: the order detail has an admin action creating the label of a parcel');
} catch (Throwable $e) {
    $r11fFail('labels / delivery notes', $e);
} finally {
    ShippingService::setAdapters([]);
    $r11fEnvMock === false ? putenv('SHIP_MOCK_LABELS') : putenv('SHIP_MOCK_LABELS=' . $r11fEnvMock);
    if ($r11fEnvKey !== null) $_ENV['SHIP_PACKETA_API_KEY'] = $r11fEnvKey;
    ConsentService::setDefinitionsForTesting(null);
}
