<?php

// Review round 34 (medium 3): goods collected in the shop are supplied in the seller's country, but the VAT scenario
// looked for the pickup method in a quote at weight 0. For an address abroad that quote came from the zone's own
// rates (no pickup there; the pickup adapter answers only above their weight bands), so a heavy order collected in
// Bratislava by a buyer with an Austrian address paid 20 % Austrian VAT through OSS.
echo "\n--- Audit round 34: in-store pickup is supplied at the shop ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r34pStores = Bootstrap::stores();
$r34pOss = $r34pStores;
$r34pOss['stores']['default']['oss_enabled'] = true;
$r34pOss['stores']['default']['legal']['country'] = 'SK';
$r34pStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r34pStoresProp->setValue(null, $r34pOss);
Bootstrap::initStoreContext('/', '', 'default');
$r34pCart = $_SESSION['cart'] ?? null;
try {
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99361, 'default', 1, 'R34P table', 'r34p-table', 'R34P-TABLE', 'simple', 12300, 'eur', 'active', 10, 'continue')");
    $_SESSION['cart'] = ['p_99361' => ['product_id' => 99361, 'variant_id' => null, 'qty' => 1]];
    $r34pScenario = static fn(string $country, string $method): array => array_values(array_intersect_key(
        CheckoutService::vatContext($country, '', $method, false)['scenario'], ['scenario' => 1, 'rate_country' => 1]));

    assert_true(ShippingService::isPickupMethod('pickup') && !ShippingService::isPickupMethod('gls') && !ShippingService::isPickupMethod(''),
        'R34 pickup: the pickup method is recognised by its definition, a courier is not');
    assert_equals([VatService::SCENARIO_DOMESTIC, 'SK'], $r34pScenario('AT', 'pickup'),
        'R34 pickup: goods collected in the shop by a buyer with an Austrian address pay Slovak VAT (was OSS AT)');
    assert_equals([VatService::SCENARIO_OSS, 'AT'], $r34pScenario('AT', 'gls'),
        'R34 pickup: the same goods sent to Austria are still an Austrian OSS supply');
    assert_equals([VatService::SCENARIO_DOMESTIC, 'SK'], $r34pScenario('SK', 'pickup'), 'R34 pickup: a Slovak buyer collecting pays Slovak VAT as before');

    // The cart and the financial snapshot price the collected goods at the Slovak rate; the OSS report has nothing.
    $r34pVat = CheckoutService::vatContext('AT', '', 'pickup', false);
    $r34pItems = array_values(array_filter(Cart::enriched($r34pVat['context'])['items'], static fn(array $i): bool => !empty($i['available'])));
    $r34pSnapshot = VatService::buildSnapshot($r34pItems, $r34pVat['context'], ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    assert_equals([VatService::SCENARIO_DOMESTIC, 'SK', (int)round(VatService::rate('SK', 'standard') * 100)],
        [$r34pSnapshot['scenario'], $r34pSnapshot['rate_country'], (int)$r34pSnapshot['components'][0]['rate_basis_points']],
        'R34 pickup: the order snapshot of collected goods is domestic at the Slovak rate');
} catch (Throwable $e) {
    assert_true(false, 'R34 pickup (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
$r34pStoresProp->setValue(null, $r34pStores);
Bootstrap::initStoreContext('/', '', 'default');
if ($r34pCart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r34pCart;
