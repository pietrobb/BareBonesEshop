<?php

// Review round 15 (R41, medium 2): OssThreshold::collected() looked the order's method up by the order's store_id, but a
// store's checkout offers the shipping methods of its provider_scope: the sample "de" store ships with "default"'s, so
// collection in its store was counted as a supply abroad, which the checkout had taxed at home. And a store's own
// collection method of service_type "store_pickup" (R39) was not one there. collected() now shares
// ShippingService::isStorePickupMethod() with isPickupMethod() and asks the store's provider scope.
echo "\n--- R41 OSS collected(): the store's provider scope and store_pickup methods ---\n";

$r41sStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r41sStoresBefore = $r41sStoresProp->getValue();
try {
    $r41sStores = Bootstrap::stores();
    $r41sBase = (array)($r41sStores['stores']['default'] ?? []);
    $r41sStores['stores']['r41de'] = array_replace($r41sBase, ['id' => 'r41de', 'url_prefix' => '/r41de', 'domains' => [], 'catalog_scope' => 'default', 'provider_scope' => 'default',
        'legal' => array_replace((array)($r41sBase['legal'] ?? []), ['country' => 'DE'])]);
    $r41sStores['stores']['r41own'] = array_replace($r41sBase, ['id' => 'r41own', 'url_prefix' => '/r41own', 'domains' => [], 'catalog_scope' => 'default', 'provider_scope' => 'r41own',
        'legal' => array_replace((array)($r41sBase['legal'] ?? []), ['country' => 'DE'])]);
    $r41sStoresProp->setValue(null, $r41sStores);

    $pdo->exec("DELETE FROM shipping_methods WHERE code IN ('r41s-pick', 'r41s-store')");
    $pdo->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('default', 'r41s-pick', 'pickup', 'R41S pickup', 'pickup', 0, 1, 990)");
    $pdo->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('default', 'r41s-store', 'r41-courier', 'R41S pick-up in store 2', 'store_pickup', 0, 1, 991)");
    $r41sOrder = static fn(string $store, string $method): array => ['store_id' => $store, 'shipping_method' => $method,
        'shipping_addr' => json_encode(['country' => 'AT']), 'billing_addr' => json_encode(['country' => 'AT']), 'customer_name' => 'R41S'];

    assert_equals([false, false], OssThreshold::distance($r41sOrder('r41de', 'r41s-pick'), VatService::SCENARIO_DOMESTIC, 'DE'),
        'R41 collected: an Austrian collecting e-books in the "de" store (pickup method of its provider scope "default") is counted at home, not abroad');
    assert_equals([false, false], OssThreshold::distance($r41sOrder('r41de', 'r41s-store'), VatService::SCENARIO_DOMESTIC, 'DE'),
        'R41 collected: a method of service_type store_pickup of the provider scope is collection too');
    assert_equals([false, false], OssThreshold::distance($r41sOrder('default', 'r41s-store'), VatService::SCENARIO_DOMESTIC, 'SK'),
        'R41 collected: store_pickup of the order\'s own store is collection');
    assert_equals([false, true], OssThreshold::distance($r41sOrder('r41own', 'r41s-pick'), VatService::SCENARIO_DOMESTIC, 'DE'),
        'R41 collected: a store with a provider scope of its own does not take default\'s pickup method of that code (control)');

    // The checkout's test is the same one.
    Bootstrap::initStoreContext('/', '', 'default');
    assert_true(ShippingService::isPickupMethod('r41s-store') && ShippingService::isPickupMethod('r41s-pick'),
        'R41 collected: ShippingService::isPickupMethod() agrees for the store\'s methods (control)');
} finally {
    $pdo->exec("DELETE FROM shipping_methods WHERE code IN ('r41s-pick', 'r41s-store')");
    $r41sStoresProp->setValue(null, $r41sStoresBefore);
    Bootstrap::initStoreContext('/', '', 'default');
}
