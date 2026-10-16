<?php

// CR follow-up (checkout money): free shipping thresholds and the coupon see the goods after discounts, payment fees
// lose domestic VAT under reverse charge like shipping does, the checkout summary shows the numbers the order charges,
// every cart path caps a line at Cart::MAX_QTY, and RMA claims are checked inside their write transaction.
echo "\n--- CR follow-up: checkout money (discounted thresholds, coupon base, fee VAT, preview parity, cart cap, RMA) ---\n";
ConsentService::setDefinitionsForTesting([]);
I18n::setLocale('sk');
$crfPricing = (new ReflectionProperty(Pricing::class, 'config'))->getValue();
$crfActivePromotions = $pdo->query("SELECT id FROM promotions WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec("UPDATE promotions SET active = 0");
$crfRules = ['cart_rules' => [
    ['type' => 'coupon', 'active' => true, 'code' => 'CRF10', 'name' => 'CRF ten percent', 'discount_type' => 'percent', 'discount_value' => 10, 'stacking' => 'stack'],
    ['type' => 'coupon', 'active' => true, 'code' => 'CRFMIN', 'name' => 'CRF minimum 50', 'discount_type' => 'fixed', 'discount_value' => 200, 'min_subtotal' => 5000, 'stacking' => 'stack'],
    ['type' => 'coupon', 'active' => true, 'code' => 'CRFEXCL', 'name' => 'CRF exclusive half', 'discount_type' => 'percent', 'discount_value' => 50, 'stacking' => 'exclusive'],
]];
Pricing::setConfig($crfRules);
$pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (9781, 'default', 1, 'CRF parcel', 'crf-parcel', 'CRF-PARCEL', 'simple', 3000, 'eur', 'active', 0, 'backorder')");
// A parcel method free from 50 €, 4 € below that, and two payment methods: 10 % of the amount, and a fixed 12,30 € fee.
$pdo->exec("INSERT INTO shipping_methods (code, carrier, name, service_type, supports_cod, supports_pickup, free_above, sort_order) VALUES ('crfship', 'gls', 'CRF parcel', 'parcel', 0, 0, 5000, 1)");
$pdo->exec("INSERT INTO shipping_rates (carrier, country, zone_code, method_code, weight_from_g, weight_to_g, price, cod_surcharge, sort_order)
    VALUES ('gls', '', 'domestic', 'crfship', 0, 999999, 400, 0, 1), ('gls', '', 'near_eu', 'crfship', 0, 999999, 400, 0, 1)");
$pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
    VALUES ('crfpct', 'manual', 'CRF percent', '', 'offline', 0, 0, 0, 1000, NULL, 0, 1, 1), ('crffix', 'manual', 'CRF fixed', '', 'offline', 0, 0, 1230, 0, NULL, 0, 1, 2)");
$pdo->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('crfpct', 'SK'), ('crffix', 'SK'), ('crffix', 'DE')");
$pdo->exec("INSERT INTO promotions (store_id, code, name, scope, coupon_code, priority, stacking, conditions, actions, active)
    VALUES ('default', 'CRFAUTO', 'CRF automatic 15', 'cart', NULL, 10, 'stack', '{}', '{\"type\":\"discount_fixed\",\"amount\":1500}', 1)");
$crfCheckout = static function(int $qty, array $extra = []) use ($pdo): array {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Cart::clear();
    Cart::add(9781, null, $qty);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.9.78';
    $_SESSION['csrf_token'] = 'crf-checkout';
    $_POST = array_replace(['csrf_token' => 'crf-checkout', 'name' => 'CRF Buyer', 'email' => 'crf-buyer@example.com', 'phone' => '', 'country' => 'SK',
        'street' => 'Audit 9', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'crfship', 'payment_method' => 'crfpct'], $extra);
    $result = apiCheckout();
    $_POST = [];
    Cart::clear();
    $order = Orders::byId((int)($result['order_id'] ?? 0)) ?: [];
    $money = [];
    foreach ($order ? ['subtotal', 'discount_amount', 'shipping_cost', 'payment_fee', 'total'] : [] as $column) $money[$column] = (int)$order[$column];
    return [$result, $order, $money];
};
try {
    $crfRates = array_column(ShippingService::calculateRates('SK', 0, 4500, false, 0, 2), 'price', 'method_code');
    assert_equals(400, $crfRates['crfship'] ?? null, 'CR follow-up setup: the CRF parcel method costs 4 € below its 50 € threshold');

    // 1. 2 × 30 € with an automatic 15 € discount: 45 € of goods is below the 50 € threshold, so shipping is charged,
    //    and the 10 % payment fee is priced on goods after the discount plus shipping (45 + 4 → 4,90 €).
    [$crfResult, , $crfMoney] = $crfCheckout(2);
    assert_equals(['subtotal' => 6000, 'discount_amount' => 1500, 'shipping_cost' => 400, 'payment_fee' => 490, 'total' => 5390], $crfMoney,
        'CR follow-up threshold: free shipping from 50 € is judged on the goods after the automatic discount (' . ($crfResult['error'] ?? 'ok') . ')');

    // 2. A stacking 10 % coupon is priced on what the automatic promotion leaves: 10 % of 45 €, not of 60 €.
    [$crfResult, $crfOrder, $crfMoney] = $crfCheckout(2, ['coupon_code' => 'CRF10']);
    assert_equals(['subtotal' => 6000, 'discount_amount' => 1950, 'shipping_cost' => 400, 'payment_fee' => 445, 'total' => 4895], $crfMoney,
        'CR follow-up coupon: a stacking coupon applies to the subtotal after automatic promotions (' . ($crfResult['error'] ?? 'ok') . ')');
    $crfPreview = CheckoutService::discountPreview('CRF10', 6000, 'crf-buyer@example.com', [['product_id' => 9781, 'qty' => 2, 'price' => 3000, 'total' => 6000]], null);
    assert_equals([450, 1500, null], [$crfPreview['coupon_discount'], $crfPreview['promotion_discount'], $crfPreview['coupon_error']],
        'CR follow-up coupon: the checkout discount preview prices the coupon the same way');

    // 3. Its minimum order is checked on the same base: 45 € after the promotion does not reach 50 €.
    [$crfResult, , $crfMoney] = $crfCheckout(2, ['coupon_code' => 'CRFMIN']);
    assert_equals(['Minimum order not met', []], [$crfResult['error'] ?? 'placed', $crfMoney], 'CR follow-up coupon: the coupon minimum order sees the subtotal after promotions');
    assert_equals('coupon_min_subtotal', CheckoutService::discountPreview('CRFMIN', 6000, null, [['product_id' => 9781, 'qty' => 2, 'price' => 3000, 'total' => 6000]], null)['coupon_error'],
        'CR follow-up coupon: the preview refuses the same coupon for the same reason');

    // 4. A non-stacking coupon still competes with the promotions on the full subtotal (Pricing::combineDiscounts).
    [$crfResult, , $crfMoney] = $crfCheckout(2, ['coupon_code' => 'CRFEXCL']);
    assert_equals(['subtotal' => 6000, 'discount_amount' => 3000, 'shipping_cost' => 400, 'payment_fee' => 340, 'total' => 3740], $crfMoney,
        'CR follow-up coupon: an exclusive coupon replaces the promotion and is priced on the full subtotal (' . ($crfResult['error'] ?? 'ok') . ')');

    // 5. The HTML checkout summary (real renderCheckout, child process on this database) shows what order 2 charged.
    $crfRoot = test_fixture_path('cr-followup-checkout');
    mkdir($crfRoot);
    file_put_contents($crfRoot . '/preview.php', <<<'PHP'
<?php
[, $root, $dbPath, $rules] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
Pricing::setConfig(json_decode($rules, true));
ConsentService::setDefinitionsForTesting([]);
I18n::setLocale('sk');
require $root . '/src/Entrypoints/Storefront/CheckoutPages.php';
$t = static fn(string $key): string => $key;
$price = static fn(int $cents): string => (string)$cents;
$storeCurrency = 'eur';
$_SESSION = ['cart' => ['p_9781' => ['product_id' => 9781, 'variant_id' => null, 'qty' => 2]], 'csrf_token' => 'crf-preview'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.9.79';
$_SERVER['HTTP_HOST'] = 'localhost';
$_POST = ['checkout_refresh' => '1', 'csrf_token' => 'crf-preview', 'name' => 'CRF Preview', 'email' => 'crf-preview@example.com',
    'street' => 'Form 9', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'shipping_method' => 'crfship', 'payment' => 'crfpct', 'coupon_code' => 'CRF10'];
$html = renderCheckout();
$summary = [];
foreach (['discount', 'shipping', 'payment', 'total'] as $id) {
    $summary[$id] = preg_match('~id="summary-' . $id . '">([^<]*)<~', $html, $m) ? $m[1] : null;
}
echo 'CRFRESULT' . json_encode($summary);
PHP);
    $crfProcess = proc_open([PHP_BINARY, $crfRoot . '/preview.php', dirname(TEST_CLI_DIR), $testDbPath, json_encode($crfRules)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $crfPipes);
    fclose($crfPipes[0]);
    $crfOut = stream_get_contents($crfPipes[1]) . stream_get_contents($crfPipes[2]);
    fclose($crfPipes[1]);
    fclose($crfPipes[2]);
    proc_close($crfProcess);
    @unlink($crfRoot . '/preview.php');
    @rmdir($crfRoot);
    $crfAt = strrpos($crfOut, 'CRFRESULT');
    $crfSummary = $crfAt === false ? null : json_decode(substr($crfOut, $crfAt + 9), true);
    assert_equals(['discount' => '-1950', 'shipping' => '400', 'payment' => '445', 'total' => (string)(int)($crfOrder['total'] ?? 0)], $crfSummary,
        'CR follow-up preview: the checkout summary shows the discount, shipping, payment fee and total the order charges (' . substr(trim(strip_tags($crfAt === false ? $crfOut : '')), 0, 300) . ')');

    // 6. Reverse charge: the payment fee loses the seller's domestic VAT exactly like the shipping rate.
    $pdo->exec("UPDATE promotions SET active = 0 WHERE code = 'CRFAUTO'");
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id = 'DE811907980'");
    $pdo->exec("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, trader_addr, checked_at, ttl_seconds, raw_response) VALUES ('DE811907980','DE','valid',NULL,NULL,datetime('now'),86400,NULL)");
    [$crfResult, $crfRcOrder, $crfMoney] = $crfCheckout(1, ['country' => 'DE', 'company' => 'CRF Kunde GmbH', 'vat_id' => 'DE811907980', 'payment_method' => 'crffix', 'zip' => '10115', 'city' => 'Berlin']);
    $crfNetFee = Cart::netOfDomesticVat(1230, 'SK');
    assert_true(!empty($crfResult['success']) && ($crfRcOrder['vat_scenario'] ?? '') === 'reverse_charge' && $crfNetFee < 1230,
        'CR follow-up fee VAT: reverse charge order with a fixed payment fee (' . ($crfResult['error'] ?? ($crfRcOrder['vat_scenario'] ?? '')) . ')');
    assert_equals([$crfNetFee, Cart::netOfDomesticVat(400, 'SK')], [$crfMoney['payment_fee'] ?? null, $crfMoney['shipping_cost'] ?? null],
        'CR follow-up fee VAT: reverse charge payment fee is charged without Slovak VAT, like shipping');
    assert_equals(($crfMoney['subtotal'] ?? 0) + ($crfMoney['shipping_cost'] ?? 0) + $crfNetFee, $crfMoney['total'] ?? null,
        'CR follow-up fee VAT: reverse charge total = net goods + net shipping + net payment fee');

    // 7. Every cart path caps a line at Cart::MAX_QTY (only the JSON API capped before).
    Cart::clear();
    Cart::add(9781, null, 60);
    Cart::add(9781, null, 60);
    assert_equals(Cart::MAX_QTY, (int)(Cart::get()['p_9781']['qty'] ?? 0), 'CR follow-up cart cap: repeated adds stop at Cart::MAX_QTY');
    Cart::update('p_9781', 5000);
    assert_equals(Cart::MAX_QTY, (int)(Cart::get()['p_9781']['qty'] ?? 0), 'CR follow-up cart cap: an update is capped at Cart::MAX_QTY');
    StorefrontCartAction::execute(['cart_action' => 'update', 'product_id' => '9781', 'quantity' => '100000']);
    assert_equals(Cart::MAX_QTY, (int)(Cart::get()['p_9781']['qty'] ?? 0), 'CR follow-up cart cap: the storefront form update is capped');
    StorefrontCartAction::execute(['cart_action' => 'add', 'product_id' => '9781', 'quantity' => '99']);
    assert_equals(Cart::MAX_QTY, (int)(Cart::get()['p_9781']['qty'] ?? 0), 'CR follow-up cart cap: a storefront add on a full line stays capped');
    Cart::clear();

    // 8. RMA: claims, receipts and exchanges are validated inside the transaction that writes them.
    $crfOrderId = (int)($crfOrder['id'] ?? 0);
    $pdo->exec("UPDATE orders SET status = 'delivered' WHERE id = {$crfOrderId}");
    $crfItem = Orders::items($crfOrderId)[0] ?? ['id' => 0];
    [$crfRmaOk, $crfRmaId] = Rma::create($crfOrderId, null, 'CRF return', [(int)$crfItem['id'] => 2]);
    [$crfAgainOk, , $crfAgainError] = Rma::create($crfOrderId, null, 'CRF over-claim', [(int)$crfItem['id'] => 1]);
    assert_true($crfRmaOk && !$crfAgainOk && $crfAgainError === 'no_items' && !$pdo->inTransaction(),
        'CR follow-up RMA: a claim beyond the ordered quantity is refused and its transaction closed (' . ($crfAgainError ?? 'accepted') . ')');
    Rma::setStatus((int)$crfRmaId, 'approved');
    $crfRmaItem = Rma::items((int)$crfRmaId)[0] ?? ['id' => 0];
    assert_true(Rma::receive((int)$crfRmaId, [(int)$crfRmaItem['id'] => ['quantity' => 1, 'disposition' => 'repair']], 'crf-receipt-1'), 'CR follow-up RMA: first partial receipt recorded');
    $crfOver = null;
    try { Rma::receive((int)$crfRmaId, [(int)$crfRmaItem['id'] => ['quantity' => 2, 'disposition' => 'repair']], 'crf-receipt-2'); } catch (LogicException $e) { $crfOver = $e->getMessage(); }
    assert_true($crfOver === 'Receipt exceeds requested quantity' && !$pdo->inTransaction(), 'CR follow-up RMA: a receipt is checked against the quantity already received in its transaction');
    assert_true(Rma::receive((int)$crfRmaId, [(int)$crfRmaItem['id'] => ['quantity' => 1, 'disposition' => 'repair']], 'crf-receipt-3'), 'CR follow-up RMA: the remaining unit can still be received');
    $crfExchange = Rma::createExchange((int)$crfRmaId, (int)$crfRmaItem['id'], 2, 9781, null, 'crf-exchange-1');
    $crfExchangeOver = null;
    try { Rma::createExchange((int)$crfRmaId, (int)$crfRmaItem['id'], 1, 9781, null, 'crf-exchange-2'); } catch (LogicException $e) { $crfExchangeOver = $e->getMessage(); }
    assert_true($crfExchange > 0 && $crfExchangeOver === 'Exchange exceeds available received quantity' && !$pdo->inTransaction(),
        'CR follow-up RMA: an exchange beyond the received quantity is refused inside its transaction (' . ($crfExchangeOver ?? 'accepted') . ')');
} finally {
    $_POST = [];
    Cart::clear();
    Pricing::setConfig($crfPricing ?? []);
    $pdo->exec("DELETE FROM promotions WHERE code = 'CRFAUTO'");
    if ($crfActivePromotions) $pdo->exec("UPDATE promotions SET active = 1 WHERE id IN (" . implode(',', array_map('intval', $crfActivePromotions)) . ")");
    $pdo->exec("UPDATE payment_methods SET active = 0 WHERE code IN ('crfpct', 'crffix')");
    $pdo->exec("UPDATE shipping_methods SET active = 0 WHERE code = 'crfship'");
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
}
