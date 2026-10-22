<?php

// Round 8 review (2026-10-21), unfixed from round 7: the checkout previews showed other numbers than the order charges.
// The HTML checkout of a digital cart a coupon makes free offered paid methods with their fee, while checkout places it
// with the fee-less 'free' method; the shipping-rates API under a free-shipping promotion showed cash on delivery at 0,
// while checkout keeps charging the carrier's COD surcharge (ShippingService::freeShippingPrice()).
echo "\n--- Audit round 8 (rest): checkout previews of free orders and free shipping with COD ---\n";
require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
$r8vPricing = (new ReflectionProperty(Pricing::class, 'config'))->getValue() ?? [];
$r8vServer = array_intersect_key($_SERVER, array_flip(['REQUEST_METHOD']));
try {
    // 1. A digital cart whose goods a coupon covers: the payment preview is the free method checkout uses, no fee.
    $r8vFree = CheckoutService::paymentPreview([['product_id' => 1, 'product_type' => 'digital', 'qty' => 1, 'price' => 900, 'total' => 900]],
        0, 'SK', 'eur', 'digital', 0, false, '', null);
    assert_equals([['free'], 'free', 0, 0], [array_column($r8vFree['methods'], 'code'), $r8vFree['selected']['code'] ?? null, (int)($r8vFree['selected']['fee'] ?? -1), $r8vFree['shipping']],
        'R8 free order preview: a digital cart made free by a coupon previews the fee-less free payment checkout places it with');
    $r8vPaid = CheckoutService::paymentPreview([['product_id' => 1, 'product_type' => 'digital', 'qty' => 1, 'price' => 900, 'total' => 900]],
        900, 'SK', 'eur', 'digital', 0, false, '', null);
    assert_true(!in_array('free', array_column($r8vPaid['methods'], 'code'), true) && $r8vPaid['methods'] !== [],
        'R8 free order preview: a digital cart with something to pay still previews the paid methods');

    // 2. The shipping-rates API with cash on delivery under an automatic free-shipping promotion: the carriage is
    //    waived, the carrier's COD surcharge stays, as checkout charges it.
    $pdo->exec("INSERT INTO categories (store_id, name, slug) VALUES ('default', 'R8V', 'r8v-cat')");
    $r8vCategory = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (9881, 'default', {$r8vCategory}, 'R8V parcel', 'r8v-parcel', 'R8V-PARCEL', 'simple', 3000, 'eur', 'active', 0, 'backorder')");
    $pdo->exec("INSERT INTO shipping_methods (code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('r8vship', 'gls', 'R8V parcel', 'parcel', 1, 0, 1)");
    $pdo->exec("INSERT INTO shipping_rates (carrier, country, zone_code, method_code, weight_from_g, weight_to_g, price, cod_surcharge, sort_order)
        VALUES ('gls', '', 'domestic', 'r8vship', 0, 999999, 400, 150, 1)");
    Cart::clear();
    Cart::add(9881, null, 1);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['country' => 'SK', 'cod' => '1'];
    $r8vRates = array_column(apiShippingRates()['methods'] ?? [], 'price', 'method_code');
    assert_equals(550, $r8vRates['r8vship'] ?? null, 'R8 COD preview setup: cash on delivery adds the 1,50 € surcharge to the 4 € rate');
    Pricing::setConfig(['cart_rules' => [['type' => 'automatic', 'active' => true, 'name' => 'R8V free shipping', 'action' => 'free_shipping']]]);
    $r8vRates = array_column(apiShippingRates()['methods'] ?? [], 'price', 'method_code');
    assert_equals(150, $r8vRates['r8vship'] ?? null, 'R8 COD preview: under free shipping the API shows the COD surcharge checkout still charges (was 0)');
    $_GET = ['country' => 'SK'];
    $r8vRates = array_column(apiShippingRates()['methods'] ?? [], 'price', 'method_code');
    assert_equals(0, $r8vRates['r8vship'] ?? null, 'R8 COD preview: without cash on delivery free shipping is 0');
} finally {
    Pricing::setConfig($r8vPricing);
    $_GET = [];
    $_SERVER = array_replace($_SERVER, $r8vServer);
    Cart::clear();
    $pdo->exec("DELETE FROM shipping_rates WHERE method_code = 'r8vship'");
    $pdo->exec("DELETE FROM shipping_methods WHERE code = 'r8vship'");
    $pdo->exec('DELETE FROM products WHERE id = 9881');
    $pdo->exec("DELETE FROM categories WHERE slug = 'r8v-cat'");
}
