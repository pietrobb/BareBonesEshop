<?php

// Review round 36, capability review of goal #454 (run 456): the sale that passes the 10 000 EUR OSS threshold is the
// first one charged the consumer's VAT (art. 59c), but checkout and the order lock decided on the cart's goods alone,
// before discounts and without shipping or the payment fee, while its invoice records all of them. 9 992,28 EUR sold,
// a 9,00 EUR mug and a paid parcel to Austria: Slovak VAT, invoiced past 10 000 EUR. A discount erred the other way.
echo "\n--- R36 OSS basis: the sale that passes the threshold is decided on what it is invoiced at (shipping, fee, discounts) ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r36bStores = Bootstrap::stores();
$r36bStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r36bOff = $r36bStores;
$r36bOff['stores']['default']['oss_enabled'] = false;
$r36bOff['stores']['default']['legal']['country'] = 'SK';
$r36bStoresProp->setValue(null, $r36bOff);
Bootstrap::initStoreContext('/', '', 'default');
$r36bCart = $_SESSION['cart'] ?? null;
$r36bOrders = [];
try {
    $year = (int)ShopTime::date('Y');
    $since = sprintf('%04d-01-01', $year - 1);
    // Nothing of other tests counts this year or the last.
    $r36bStashedInvoices = $pdo->query("SELECT id, issued_at FROM invoices WHERE issued_at >= '{$since}'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pdo->exec("UPDATE invoices SET issued_at = '2001-01-01 00:00:00' WHERE issued_at >= '{$since}'");
    $r36bStashedOrders = $pdo->query("SELECT id, created_at FROM orders WHERE created_at >= '{$since}'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pdo->exec("UPDATE orders SET created_at = '2001-01-01 00:00:00' WHERE created_at >= '{$since}'");
    (require TEST_CLI_DIR . '/../migrations/20261113180000_invoice_oss_net.php')->up($pdo, Db::isSqlite());
    (require TEST_CLI_DIR . '/../migrations/20261113190000_oss_threshold_totals.php')->up($pdo, Db::isSqlite());
    Db::forgetSchema($pdo);
    $r36bStashedTotals = $pdo->query('SELECT store_id, year, currency, net FROM oss_threshold_totals')->fetchAll(PDO::FETCH_NUM);
    $pdo->exec('DELETE FROM oss_threshold_totals');
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99392, 'default', 1, 'R36B mug', 'r36b-mug', 'R36B-MUG', 'simple', 900, 'eur', 'active', 10, 'deny')");
    $pdo->exec("DELETE FROM warehouse_stock WHERE sku = 'R36B-MUG'");
    $pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'R36B-MUG', 10, 0)")
        ->execute([(int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn()]);
    $r36bStore = $r36bOff['stores']['default'];
    $r36bSum = static fn(): int => OssThreshold::worstYear($pdo, 'default', $r36bStore, ['default' => $r36bStore])['sum'];

    // This year's home-VAT sales to Austria: 40 cents short of the threshold once the mug's 7,32 EUR net is added.
    $r36bGoodsNet = (int)round(900 / 1.23);
    $r36bSeed = OssThreshold::EUR_CENTS - $r36bGoodsNet - 40;
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, net_total, currency, status, payment_status, payment_method, shipping_method, store_id, vat_scenario, created_at)
        VALUES ('R36B-SEED', 'R36B', 'r36b@example.test', '', ?, ?, 0, 0, 0, ?, ?, 'eur', 'delivered', 'paid', 'bank_transfer', 'gls', 'default', 'domestic_subthreshold', ?)")
        ->execute([json_encode(['country' => 'AT']), $r36bSeed, $r36bSeed, $r36bSeed, sprintf('%04d-01-10 10:00:00', $year)]);
    $r36bOrders[] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type)
        VALUES (?, 'default', 'R36B-SEED', ?, '', ?, 0, ?, 'eur', 'issued', 'r36b-seed', 'domestic_subthreshold', 'invoice')")
        ->execute([$r36bOrders[0], sprintf('%04d-01-15 10:00:00', $year), $r36bSeed, $r36bSeed]);
    OssThreshold::record($pdo, (int)$pdo->lastInsertId());
    assert_equals($r36bSeed, $r36bSum(), 'R36 OSS basis: the seeded home-VAT sales count toward the threshold');

    // A paid parcel to Austria (the store's rates) and a payment the order can take.
    $r36bRate = null;
    foreach (ShippingService::calculateRates('AT', 500, 900, false, 0, 1, 'eur') as $rate) {
        if ((int)$rate['price'] >= 100 && ($rate['carrier'] ?? '') !== 'pickup') { $r36bRate = $rate; break; }
    }
    assert_true($r36bRate !== null, 'R36 OSS basis: a paid parcel to Austria is offered');
    $r36bMethod = (string)($r36bRate['method_code'] ?? '');
    $r36bPayment = '';
    foreach (PaymentService::availableMethods('AT', 'eur', 900 + (int)($r36bRate['price'] ?? 0), $r36bMethod) as $payment) {
        if (($payment['service_type'] ?? '') !== 'cod') { $r36bPayment = (string)$payment['code']; break; }
    }
    $r36bAustria = static function (int $qty) use ($r36bMethod): array {
        $_SESSION['cart'] = ['p_99392' => ['product_id' => 99392, 'variant_id' => null, 'qty' => $qty]];
        VatService::reset();
        $vat = CheckoutService::vatContext('AT', '', $r36bMethod, false);
        return [$vat, CheckoutService::availableItems(Cart::enriched($vat['context'])['items'] ?? [])];
    };

    // 1. Shipping counts: the goods alone stay under (the estimate), the goods and the parcel pass it.
    [$r36bVat, $r36bItems] = $r36bAustria(1);
    assert_equals(VatService::SCENARIO_DOMESTIC_SUBTHRESH, $r36bVat['scenario']['scenario'], 'R36 OSS basis: the mug alone keeps the year under the threshold (the cart-line estimate)');
    $r36bAmounts = ['shipping' => 500, 'currency' => 'eur'];
    [$r36bDecided, $r36bDecidedItems] = CheckoutService::thresholdVat($r36bVat, $r36bItems, $r36bAmounts);
    assert_equals([VatService::SCENARIO_OSS, 'AT', 20.0], [$r36bDecided['scenario']['scenario'], $r36bDecided['scenario']['rate_country'], (float)($r36bDecidedItems[0]['vat_rate'] ?? 0)],
        'R36 OSS basis: with 5,00 EUR shipping the sale passes the threshold and the order and its lines are priced at Austrian VAT (was Slovak VAT)');
    $r36bHome = VatService::buildSnapshot($r36bItems, $r36bVat['context'], $r36bAmounts);
    assert_equals($r36bGoodsNet + (int)round(500 / 1.23), OssThreshold::snapshotNet($r36bHome, $r36bItems, true, false),
        'R36 OSS basis: the sale counts its goods and its shipping, net of home VAT, as its invoice does');
    $r36bRefused = false;
    try {
        OssThreshold::releaseOrderLock($pdo, OssThreshold::guardOrder($pdo, $r36bHome, ['name' => 'R36B', 'address' => ['country' => 'AT']], $r36bItems));
    } catch (VatChangedException $e) {
        $r36bRefused = true;
    }
    assert_true($r36bRefused, 'R36 OSS basis: the order lock refuses that sale priced at home VAT (it counted the goods only)');

    // 2. A discount counts too: two mugs would pass it, after a 12,00 EUR discount they do not.
    [$r36bVat2, $r36bItems2] = $r36bAustria(2);
    assert_equals(VatService::SCENARIO_OSS, $r36bVat2['scenario']['scenario'], 'R36 OSS basis: two mugs before the discount pass the threshold (the cart-line estimate)');
    $r36bDiscount = ['discount' => 1200, 'currency' => 'eur'];
    [$r36bDecided2, $r36bDecidedItems2] = CheckoutService::thresholdVat($r36bVat2, $r36bItems2, $r36bDiscount);
    assert_equals([VatService::SCENARIO_DOMESTIC_SUBTHRESH, 23.0], [$r36bDecided2['scenario']['scenario'], (float)($r36bDecidedItems2[0]['vat_rate'] ?? 0)],
        'R36 OSS basis: after the discount the sale stays under the threshold and keeps Slovak VAT (was Austrian VAT)');
    $r36bHome2 = VatService::buildSnapshot($r36bDecidedItems2, $r36bDecided2['context'], $r36bDiscount);
    OssThreshold::releaseOrderLock($pdo, OssThreshold::guardOrder($pdo, $r36bHome2, ['name' => 'R36B', 'address' => ['country' => 'AT']], $r36bDecidedItems2));
    assert_true(true, 'R36 OSS basis: the order lock places the discounted sale at home VAT');

    // 3. End to end: a manual order of the mug with the parcel is charged Austrian VAT, and its invoice passes 10 000 EUR.
    $_SESSION['cart'] = [];
    VatService::reset();
    $r36bManual = ManualOrder::create(ManualOrder::parseLines('R36B-MUG 1')['lines'], ['name' => 'R36B Kundin', 'email' => 'r36b-manual@example.com',
        'country' => 'AT', 'street' => 'Ring 1', 'city' => 'Wien', 'zip' => '1010', 'shipping_method' => $r36bMethod, 'payment_method' => $r36bPayment, 'locale' => 'de'], false);
    $r36bOrderId = (int)($r36bManual['order_id'] ?? 0);
    if ($r36bOrderId > 0) $r36bOrders[] = $r36bOrderId;
    $r36bOrder = Orders::byId($r36bOrderId) ?? [];
    assert_equals([true, VatService::SCENARIO_OSS, true], [$r36bManual['ok'] ?? false, $r36bOrder['vat_scenario'] ?? null, (int)($r36bOrder['shipping_cost'] ?? 0) > 0],
        'R36 OSS basis: the order whose goods and shipping pass the threshold is charged Austrian VAT (' . ($r36bManual['error'] ?? 'ok') . ')');
    if ($r36bOrderId > 0) {
        $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid' WHERE id = ?")->execute([$r36bOrderId]);
        InvoiceService::issue($r36bOrderId);
    }
    $r36bInvoice = $pdo->query("SELECT vat_scenario FROM invoices WHERE order_id = {$r36bOrderId} AND type = 'invoice'")->fetchColumn();
    assert_true($r36bInvoice === VatService::SCENARIO_OSS && $r36bSum() > OssThreshold::EUR_CENTS,
        'R36 OSS basis: the invoice that takes the year past 10 000 EUR is an OSS invoice (' . var_export($r36bInvoice, true) . ', ' . $r36bSum() . ')');
} catch (Throwable $e) {
    assert_true(false, 'R36 OSS basis (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
foreach ($r36bOrders as $r36bId) {
    $pdo->prepare('DELETE FROM invoices WHERE order_id = ?')->execute([$r36bId]);
    $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$r36bId]);
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r36bId]);
}
$pdo->exec('DELETE FROM products WHERE id = 99392');
$pdo->exec("DELETE FROM warehouse_stock WHERE sku = 'R36B-MUG'");
if (Db::tableExists('oss_threshold_totals', $pdo)) {
    $pdo->exec('DELETE FROM oss_threshold_totals');
    $restore = $pdo->prepare('INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES (?, ?, ?, ?)');
    foreach ($r36bStashedTotals ?? [] as $row) $restore->execute($row);
}
$restore = $pdo->prepare('UPDATE invoices SET issued_at = ? WHERE id = ?');
foreach ($r36bStashedInvoices ?? [] as $id => $issuedAt) $restore->execute([$issuedAt, $id]);
$restore = $pdo->prepare('UPDATE orders SET created_at = ? WHERE id = ?');
foreach ($r36bStashedOrders ?? [] as $id => $createdAt) $restore->execute([$createdAt, $id]);
$r36bStoresProp->setValue(null, $r36bStores);
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
if ($r36bCart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r36bCart;
