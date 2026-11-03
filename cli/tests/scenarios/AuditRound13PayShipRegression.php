<?php

// Round 13 review (2026-11-03), open from round 3: T2 a hidden store answered its payment/carrier webhooks with 404;
// T3 a second PayU attempt reused the extOrderId PayU already had (ORDER_NOT_UNIQUE); T4 the label of a cancelled
// parcel kept the cash on delivery, so every other parcel went prepaid; T9 the carrier API was called inside the
// order transaction (lock held for the carrier's timeout, label lost on rollback).
echo "\n--- Audit round 13: payments and shipping (hidden-store webhooks, PayU retry, COD after a cancelled parcel, carrier call outside the transaction) ---\n";

require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shipments.php';
Bootstrap::initStoreContext('/', '', 'default');
$r13pFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R13 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r13pDb = Db::get();

// T2: the hidden store's own webhook URLs reach the handlers; its storefront and browser API stay 404.
try {
    $r13pRoot = dirname(TEST_CLI_DIR);
    $r13pDir = test_fixture_path('r13-hidden-store');
    if (!is_dir($r13pDir)) mkdir($r13pDir, 0700, true);
    $r13pScript = $r13pDir . '/probe.php';
    file_put_contents($r13pScript, '<?php
require ' . var_export($r13pRoot . '/src/autoload.php', true) . ';
$uri = $argv[1];
$stores = ["default_store" => "default", "stores" => [
    "default" => ["name" => "default", "default_locale" => "en", "languages" => ["en"], "currency" => "eur", "url_prefix" => "", "domains" => [], "permissions" => ["*"], "channels" => ["storefront", "api"]],
    "hx" => ["name" => "hx", "default_locale" => "en", "languages" => ["en"], "currency" => "eur", "url_prefix" => "/hx", "domains" => [], "permissions" => ["*"], "channels" => ["storefront", "api"], "public" => false],
]];
(new ReflectionProperty(Bootstrap::class, "stores"))->setValue(null, $stores);
$route = ApiRoute::resolve($uri, "", $stores["stores"]);
$action = (string)($route["action"] ?? "");
Bootstrap::initLocale($uri, ApiRoute::servesHiddenStore($action));
echo "reached " . StoreContext::current()->id() . " " . $action;
');
    $r13pProbe = static function (string $uri) use ($r13pScript): string {
        $process = proc_open([PHP_BINARY, $r13pScript, $uri], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return trim($out);
    };
    assert_equals(['reached hx webhook_payment', 'reached hx webhook_shipping'],
        [$r13pProbe('/hx/api/v1/webhooks/payment/payu'), $r13pProbe('/hx/api/v1/webhooks/shipping/packeta')],
        'R13 T2: a hidden store answers its payment and carrier webhooks (was 404, the provider retried until it gave up)');
    assert_equals(['Not Found', 'Not Found'], [$r13pProbe('/hx/api/v1/storefront/cart'), $r13pProbe('/hx/')],
        'R13 T2: the hidden store still hides its storefront and browser API');
} catch (Throwable $e) {
    $r13pFail('T2', $e);
}

// T3: every PayU attempt has its own extOrderId; a notification of any attempt still finds the order.
try {
    assert_true(method_exists(PayUPayment::class, 'attemptId') && method_exists(PayUPayment::class, 'orderNumber'),
        'R13 T3: the PayU adapter numbers payment attempts');
    if (method_exists(PayUPayment::class, 'attemptId')) {
        $r13pFirst = PayUPayment::attemptId('ORD-20261103-R13P01');
        $r13pSecond = PayUPayment::attemptId('ORD-20261103-R13P01');
        assert_true($r13pFirst !== $r13pSecond && str_starts_with($r13pFirst, 'ORD-20261103-R13P01-P'),
            'R13 T3: a retried payment sends PayU a new extOrderId (was the order number again: ORDER_NOT_UNIQUE)');
        assert_equals(['ORD-20261103-R13P01', 'ORD-20261103-R13P01', 'ORD-20261103-R13P01'],
            [PayUPayment::orderNumber($r13pFirst), PayUPayment::orderNumber($r13pSecond), PayUPayment::orderNumber('ORD-20261103-R13P01')],
            'R13 T3: the order number is read back from an attempt id and from an id sent before attempt suffixes');
        $r13pSource = (string)file_get_contents($r13pRoot . '/adapters/contrib/PayUPayment.php');
        assert_true(str_contains($r13pSource, "'extOrderId'      => \$extOrderId") && str_contains($r13pSource, '$extOrderId = self::attemptId('),
            'R13 T3: createSession sends the attempt id');
    }

    $r13pKey = 'r13p-payu-second-key-0123456789';
    $r13pGateway = new PayUPayment(['payu_second_key' => $r13pKey]);
    $r13pDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id)
        VALUES ('ORD-20261103-R13P02', 'pending', 'pending', 'R13P', 'r13p@example.test', 'card', '', 1000, 1000, 'eur', 'default')")->execute();
    $r13pOrderId = (int)$r13pDb->lastInsertId();
    $r13pExt = method_exists(PayUPayment::class, 'attemptId') ? PayUPayment::attemptId('ORD-20261103-R13P02') : 'ORD-20261103-R13P02-P0a1b2c3d';
    $r13pRaw = json_encode(['order' => ['orderId' => 'payu-r13p-2', 'extOrderId' => $r13pExt, 'status' => 'COMPLETED', 'totalAmount' => '1000', 'currencyCode' => 'EUR']], JSON_THROW_ON_ERROR);
    $r13pResult = null;
    try {
        $r13pResult = PaymentService::handleWebhook($r13pRaw, 'sender=checkout;signature=' . md5($r13pRaw . $r13pKey) . ';algorithm=MD5;content=DOCUMENT', json_decode($r13pRaw, true), $r13pGateway, 'payu');
    } catch (Throwable $e) {
        $r13pResult = $e->getMessage();
    }
    assert_equals('paid', (string)Orders::byId($r13pOrderId)['payment_status'],
        'R13 T3: the notification of the second attempt pays the order (' . json_encode($r13pResult) . ')');
} catch (Throwable $e) {
    $r13pFail('T3', $e);
}

// T4 + T9: a COD order with two parcels and a carrier that records whether a transaction was open when it was called.
$r13pEnvMock = getenv('SHIP_MOCK_LABELS');
try {
    foreach (['20261101130000_shipping_label_shipment.php', '20261101140000_shipment_cod_amount.php'] as $r13pMigration) {
        (require TEST_CLI_DIR . '/../migrations/' . $r13pMigration)->up($pdo, Db::isSqlite());
    }
    Db::forgetSchema();
    $r13pCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r13pWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    // Fixed ids: an auto-assigned id follows the highest fixed id of earlier scenarios and collides with later ones.
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy) VALUES
        (98991, 'default', {$r13pCategory}, 'R13P-A', 'r13p-a', 'simple', 'R13P goods A', 1000, 'eur', 20, 'active', 'deny'),
        (98992, 'default', {$r13pCategory}, 'R13P-B', 'r13p-b', 'simple', 'R13P goods B', 500, 'eur', 20, 'active', 'deny')");
    Warehouse::setStock($r13pWarehouse, 'R13P-A', 20, 'count', 'test', 'r13p-opening-a');
    Warehouse::setStock($r13pWarehouse, 'R13P-B', 20, 'count', 'test', 'r13p-opening-b');
    $r13pLine = static fn(string $sku, int $price): array => ['product_id' => $sku === 'R13P-A' ? 98991 : 98992, 'variant_id' => null, 'sku' => $sku, 'name' => $sku, 'qty' => 1, 'price' => $price, 'attrs' => []];
    $r13pCodOrder = static fn(): int => Orders::create([$r13pLine('R13P-A', 1000), $r13pLine('R13P-B', 500)],
        ['name' => 'R13P COD', 'email' => 'r13p-cod@example.test', 'phone' => '', 'address' => [], 'shipping_cost' => 0, 'tax_amount' => 0,
            'coupon_discount' => 0, 'currency' => 'eur', 'payment_method_snapshot' => json_encode(['code' => 'cod', 'service_type' => 'cod'])], 'cod');
    $r13pParcels = static function (int $orderId): array {
        $items = array_column(Orders::items($orderId), null, 'sku');
        $wh = Fulfillment::shippable($orderId)[0]['warehouse_id'] ?? null;
        return [
            Fulfillment::createShipment($orderId, $wh, [['order_item_id' => (int)$items['R13P-A']['id'], 'sku' => 'R13P-A', 'quantity' => 1]], 'r13p-a-' . $orderId),
            Fulfillment::createShipment($orderId, $wh, [['order_item_id' => (int)$items['R13P-B']['id'], 'sku' => 'R13P-B', 'quantity' => 1]], 'r13p-b-' . $orderId),
        ];
    };
    $r13pCarrier = new class implements ShippingAdapter {
        /** @var list<bool> */
        public array $calledInTransaction = [];
        public function getRates(array $params): array { return []; }
        public function generateLabel(array $order): array {
            $this->calledInTransaction[] = Db::get()->inTransaction();
            return ['awb' => 'R13P-' . $order['id'] . '-' . ($order['shipment_id'] ?? 0) . '-' . $order['label_sequence'], 'label_data' => ''];
        }
        public function trackShipment(string $awb): array { return []; }
        public function supportsCOD(): bool { return true; }
        public function carrierId(): string { return 'r13p-carrier'; }
    };
    ShippingService::setAdapters(['r13p-carrier' => $r13pCarrier]);

    // T4: parcel A is labelled with the COD and then cancelled; parcel B now collects it.
    $r13pOrder = $r13pCodOrder();
    $r13pTotal = (int)Orders::byId($r13pOrder)['total'];
    [$r13pShipA, $r13pShipB] = $r13pParcels($r13pOrder);
    $r13pLabelA = ShippingService::generateLabel($r13pOrder, 'r13p-carrier', $r13pShipA);
    assert_equals($r13pTotal, (int)$r13pLabelA['cod_amount'], 'R13 T4 setup: the first parcel label collects the order total');
    assert_true(Fulfillment::cancel($r13pShipA), 'R13 T4 setup: the labelled parcel is cancelled');
    $r13pRow = Orders::byId($r13pOrder);
    assert_equals([$r13pTotal, $r13pTotal], [ShippingService::parcelCodAmount($r13pRow, $r13pShipB), ShippingService::labelCodAmount($r13pRow)],
        'R13 T4: after the collecting parcel is cancelled, the remaining parcel collects the COD (was 0: the cancelled parcel\'s label kept it)');
    $r13pLabelB = ShippingService::generateLabel($r13pOrder, 'r13p-carrier', $r13pShipB);
    assert_equals($r13pTotal, (int)$r13pLabelB['cod_amount'], 'R13 T4: the label of the remaining parcel collects the order total');

    // T9: the carrier is called with no transaction open; inside one generateLabel refuses before calling it.
    assert_equals([false, false], $r13pCarrier->calledInTransaction, 'R13 T9: the carrier API was called with no database transaction open');
    $r13pOrder2 = $r13pCodOrder();
    [$r13pShip2A] = $r13pParcels($r13pOrder2);
    $r13pRefused = null;
    $r13pDb->beginTransaction();
    try {
        ShippingService::generateLabel($r13pOrder2, 'r13p-carrier', $r13pShip2A);
    } catch (LogicException $e) {
        $r13pRefused = $e->getMessage();
    } finally {
        if ($r13pDb->inTransaction()) $r13pDb->rollBack();
    }
    assert_true($r13pRefused !== null && count($r13pCarrier->calledInTransaction) === 2,
        'R13 T9: inside an open transaction the label is refused before the carrier is called');
    $r13pAdmin = (string)file_get_contents($r13pRoot . '/src/Entrypoints/Admin/ShipmentLabels.php');
    assert_true((bool)preg_match("/return 'admin\\.shipment\\.msg\\.label_created';\\R\\s*\\}, true, false\\);/", $r13pAdmin),
        'R13 T9: the admin label action runs without the order transaction around the carrier call');
    $r13pLabel2 = ShippingService::generateLabel($r13pOrder2, 'r13p-carrier', $r13pShip2A);
    $r13pShipRows = array_column(Fulfillment::shipments($r13pOrder2), null, 'id');
    assert_equals([$r13pLabel2['awb'], (int)Orders::byId($r13pOrder2)['total']],
        [$r13pShipRows[$r13pShip2A]['tracking_number'] ?? null, (int)($r13pShipRows[$r13pShip2A]['cod_amount'] ?? 0)],
        'R13 T9: the label is recorded on the parcel with its COD after the carrier answered');
    assert_equals($r13pLabel2['awb'], ShippingService::generateLabel($r13pOrder2, 'r13p-carrier', $r13pShip2A)['awb'],
        'R13 T9: a repeated request returns the recorded label without a new carrier call');
} catch (Throwable $e) {
    $r13pFail('T4/T9', $e);
} finally {
    ShippingService::setAdapters([]);
    $r13pEnvMock === false ? putenv('SHIP_MOCK_LABELS') : putenv('SHIP_MOCK_LABELS=' . $r13pEnvMock);
}
