<?php

// R7 (low): the README promises free-shipping cart promotions, but the admin refused the action for automatic cart
// promotions and coupons, and a coupon that discounts no goods was rejected as invalid everywhere. A free-shipping
// coupon (admin promotion or pricing.json) now waives the carriage in checkout, its preview and the coupon API,
// and still counts against its usage limits.
echo "\n--- R7 free shipping: admin action, coupon waives carriage, automatic rule, usage limit ---\n";
ConsentService::setDefinitionsForTesting([]);
I18n::setLocale('en');
$r7fsPricing = (new ReflectionProperty(Pricing::class, 'config'))->getValue();
$r7fsActive = $pdo->query("SELECT id FROM promotions WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec("UPDATE promotions SET active = 0");
Pricing::setConfig(['cart_rules' => [
    ['type' => 'coupon', 'active' => true, 'code' => 'R7FSJSON', 'name' => 'R7 JSON free shipping', 'discount_type' => 'free_shipping'],
]]);
$pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (9791, 'default', 1, 'R7FS parcel', 'r7fs-parcel', 'R7FS-PARCEL', 'simple', 3000, 'eur', 'active', 0, 'backorder')");
$pdo->exec("INSERT INTO shipping_methods (code, carrier, name, service_type, supports_cod, supports_pickup, free_above, sort_order) VALUES ('r7fship', 'gls', 'R7FS parcel', 'parcel', 0, 0, NULL, 1)");
$pdo->exec("INSERT INTO shipping_rates (carrier, country, zone_code, method_code, weight_from_g, weight_to_g, price, cod_surcharge, sort_order)
    VALUES ('gls', '', 'domestic', 'r7fship', 0, 999999, 400, 0, 1), ('gls', '', 'near_eu', 'r7fship', 0, 999999, 400, 0, 1)");
$pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
    VALUES ('r7fpay', 'manual', 'R7FS pay', '', 'offline', 0, 0, 0, 0, NULL, 0, 1, 1)");
$pdo->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('r7fpay', 'SK')");
$r7fsCheckout = static function(int $qty, string $coupon = '', string $email = 'r7fs-buyer@example.com'): array {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Cart::clear();
    Cart::add(9791, null, $qty);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.9.79';
    $_SESSION['csrf_token'] = 'r7fs-checkout';
    $_POST = ['csrf_token' => 'r7fs-checkout', 'name' => 'R7FS Buyer', 'email' => $email, 'phone' => '', 'country' => 'SK',
        'street' => 'Audit 7', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'r7fship', 'payment_method' => 'r7fpay', 'coupon_code' => $coupon];
    $result = apiCheckout();
    $_POST = [];
    Cart::clear();
    $order = Orders::byId((int)($result['order_id'] ?? 0)) ?: [];
    $money = [];
    foreach ($order ? ['subtotal', 'discount_amount', 'shipping_cost', 'total'] : [] as $column) $money[$column] = (int)$order[$column];
    return [$result, $order, $money];
};
$r7fsValidate = static function(string $code): array {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Cart::clear();
    Cart::add(9791, null, 2);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.9.79';
    $_SESSION['csrf_token'] = 'r7fs-checkout';
    $_POST = ['csrf_token' => 'r7fs-checkout', 'code' => $code, 'subtotal' => '6000', 'email' => 'r7fs-other@example.com'];
    $result = apiValidateCoupon();
    $_POST = [];
    Cart::clear();
    return $result;
};
$r7fsItems = [['product_id' => 9791, 'qty' => 2, 'price' => 3000, 'total' => 6000, 'currency' => 'eur']];
$r7fsOrders = [];
try {
    // 1. The admin accepts free shipping for a coupon and for an automatic cart promotion (both were refused).
    $r7fsCoupon = PricingAdmin::savePromotion(0, ['name' => 'R7 free shipping coupon', 'code' => 'R7FSCOUPON', 'scope' => 'cart', 'coupon_code' => 'r7freeship',
        'stacking' => 'exclusive', 'active' => '1', 'action_type' => 'free_shipping', 'cond_max_uses' => '1']);
    assert_true($r7fsCoupon['ok'], 'R7 free shipping admin: a coupon promotion can give free shipping (' . $r7fsCoupon['message'] . ')');
    $r7fsAuto = PricingAdmin::savePromotion(0, ['name' => 'R7 free shipping from 100', 'code' => 'R7FSAUTO', 'scope' => 'cart', 'stacking' => 'stack',
        'active' => '1', 'action_type' => 'free_shipping', 'cond_min_subtotal' => '10000']);
    assert_true($r7fsAuto['ok'], 'R7 free shipping admin: an automatic cart promotion can give free shipping (' . $r7fsAuto['message'] . ')');
    assert_equals('{"type":"free_shipping"}', (string)$pdo->query("SELECT actions FROM promotions WHERE id = " . (int)$r7fsCoupon['id'])->fetchColumn(),
        'R7 free shipping admin: the action is stored without an amount');
    $r7fsRefused = PricingAdmin::savePromotion(0, ['name' => 'R7 catalog free shipping', 'code' => 'R7FSCAT', 'scope' => 'catalog', 'active' => '1', 'action_type' => 'free_shipping']);
    assert_true(!$r7fsRefused['ok'], 'R7 free shipping admin: a catalog promotion still cannot give free shipping');

    // 2. Without a coupon, 60 € of goods pay the 4 € carriage (the automatic rule needs 100 €).
    [$r7fsResult, $r7fsOrder, $r7fsMoney] = $r7fsCheckout(2);
    $r7fsOrders[] = (int)($r7fsOrder['id'] ?? 0);
    assert_equals(['subtotal' => 6000, 'discount_amount' => 0, 'shipping_cost' => 400, 'total' => 6400], $r7fsMoney,
        'R7 free shipping: without a coupon the carriage is charged (' . ($r7fsResult['error'] ?? 'ok') . ')');

    // 3. The coupon API and the checkout preview accept the coupon (it was "Invalid coupon": it discounts no goods).
    $r7fsV = $r7fsValidate('R7FREESHIP');
    assert_equals([true, 0, true], [$r7fsV['valid'] ?? null, $r7fsV['discount'] ?? null, $r7fsV['free_shipping'] ?? null],
        'R7 free shipping coupon API: the coupon is valid and reports free shipping (' . ($r7fsV['error'] ?? 'ok') . ')');
    $r7fsPreview = CheckoutService::discountPreview('R7FREESHIP', 6000, 'r7fs-buyer@example.com', $r7fsItems, null);
    assert_equals([null, true, 0, true], [$r7fsPreview['coupon_error'], $r7fsPreview['coupon_applied'], $r7fsPreview['coupon_discount'], $r7fsPreview['free_shipping']],
        'R7 free shipping coupon preview: the checkout form shows the coupon as applied with free shipping');

    // 4. Checkout with the coupon waives the carriage and records a use of the coupon.
    [$r7fsResult, $r7fsOrder, $r7fsMoney] = $r7fsCheckout(2, 'r7freeship');
    $r7fsOrders[] = (int)($r7fsOrder['id'] ?? 0);
    assert_equals(['subtotal' => 6000, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => 6000], $r7fsMoney,
        'R7 free shipping coupon: checkout charges no carriage (' . ($r7fsResult['error'] ?? 'ok') . ')');
    assert_equals(['R7FREESHIP'], $pdo->query('SELECT coupon_code FROM coupon_uses WHERE order_id = ' . (int)($r7fsOrder['id'] ?? 0))->fetchAll(PDO::FETCH_COLUMN),
        'R7 free shipping coupon: the use is recorded against the order');

    // 5. Its max_uses 1 is spent: a second buyer is refused like any used-up code.
    [$r7fsResult, $r7fsOrder] = $r7fsCheckout(2, 'R7FREESHIP', 'r7fs-second@example.com');
    if ($r7fsOrder) $r7fsOrders[] = (int)$r7fsOrder['id'];
    assert_equals('coupon_invalid', $r7fsResult['error_code'] ?? 'placed', 'R7 free shipping coupon: the usage limit applies to a free-shipping coupon');

    // 6. A pricing.json coupon with discount_type free_shipping works the same way.
    [$r7fsResult, $r7fsOrder, $r7fsMoney] = $r7fsCheckout(2, 'R7FSJSON');
    $r7fsOrders[] = (int)($r7fsOrder['id'] ?? 0);
    assert_equals(['subtotal' => 6000, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => 6000], $r7fsMoney,
        'R7 free shipping: a pricing.json free-shipping coupon waives the carriage (' . ($r7fsResult['error'] ?? 'ok') . ')');

    // 7. The automatic admin promotion gives free shipping once its minimum order is reached.
    [$r7fsResult, $r7fsOrder, $r7fsMoney] = $r7fsCheckout(4);
    $r7fsOrders[] = (int)($r7fsOrder['id'] ?? 0);
    assert_equals(['subtotal' => 12000, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => 12000], $r7fsMoney,
        'R7 free shipping: an automatic cart promotion waives the carriage from its minimum order (' . ($r7fsResult['error'] ?? 'ok') . ')');
} finally {
    $_POST = [];
    Cart::clear();
    $r7fsIds = implode(',', array_map('intval', array_filter($r7fsOrders)));
    if ($r7fsIds !== '') {
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name != 'orders'")->fetchAll(PDO::FETCH_COLUMN) as $r7fsTable) {
            $r7fsColumns = array_column($pdo->query('PRAGMA table_info("' . $r7fsTable . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (in_array('order_id', $r7fsColumns, true)) $pdo->exec("DELETE FROM \"{$r7fsTable}\" WHERE order_id IN ({$r7fsIds})");
        }
        $pdo->exec("DELETE FROM orders WHERE id IN ({$r7fsIds})");
    }
    $pdo->exec("DELETE FROM coupon_usage_counters WHERE coupon_code IN ('R7FREESHIP', 'R7FSJSON')");
    $pdo->exec("DELETE FROM promotions WHERE UPPER(code) IN ('R7FSCOUPON', 'R7FSAUTO', 'R7FSCAT')");
    if ($r7fsActive) $pdo->exec("UPDATE promotions SET active = 1 WHERE id IN (" . implode(',', array_map('intval', $r7fsActive)) . ")");
    $pdo->exec("DELETE FROM payment_method_countries WHERE method_code = 'r7fpay'");
    $pdo->exec("DELETE FROM payment_methods WHERE code = 'r7fpay'");
    $pdo->exec("DELETE FROM shipping_rates WHERE method_code = 'r7fship'");
    $pdo->exec("DELETE FROM shipping_methods WHERE code = 'r7fship'");
    $pdo->exec("DELETE FROM products WHERE id = 9791");
    Pricing::setConfig($r7fsPricing);
    I18n::setLocale('sk');
}
