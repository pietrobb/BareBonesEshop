<?php

// Review round 18 (2026-11-06), medium "acceptance": a manual order stored the product names in the admin's language
// and was saved with a shipping and payment method the admin never chose (the form preselected the first ones); the
// admin's withdrawal notice said "do not ship" for goods already delivered.
echo "\n--- R18 acceptance: manual order language and choices, withdrawal notice after shipping ---\n";

require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shipments.php';
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/ManualOrders.php';
(require TEST_CLI_DIR . '/../migrations/20261012210000_customer_email_verification.php')->up($pdo, true);
ConsentService::setDefinitionsForTesting([]);
$r18aWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$r18aCategory = (int)$pdo->query("SELECT id FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy, translations)
    VALUES (9893, 'default', ?, 'R18A Teapot', 'r18a-teapot', 'R18A-T', 'simple', 1500, 'eur', 'active', 20, 'deny', ?)")
    ->execute([$r18aCategory, json_encode(['sk' => ['name' => 'R18A Čajník'], 'de' => ['name' => 'R18A Teekanne']], JSON_UNESCAPED_UNICODE)]);
$pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'R18A-T', 20, 0)")->execute([$r18aWarehouse]);
$r18aLines = ManualOrder::parseLines('R18A-T 1')['lines'];
$r18aBuyer = static fn(array $over = []): array => $over + ['name' => 'R18 Buyer', 'email' => 'r18a-buyer@example.com', 'country' => 'SK',
    'street' => 'Hlavná 1', 'city' => 'Košice', 'zip' => '04001', 'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer', 'locale' => 'de'];
$r18aAdminLocale = I18n::locale();
I18n::setLocale('sk');
$r18aOrders = [];
try {
    // The admin works in Slovak, the customer reads German: the order lines carry the German name.
    $r18aCreated = ManualOrder::create($r18aLines, $r18aBuyer(), false);
    $r18aOrders[] = $r18aId = (int)($r18aCreated['order_id'] ?? 0);
    $r18aItems = $r18aId > 0 ? Orders::items($r18aId) : [];
    assert_equals(['R18A Teekanne', 'de', 'sk'], [(string)($r18aItems[0]['name'] ?? ''), (string)(Orders::byId($r18aId)['locale'] ?? ''), I18n::locale()],
        'R18 manual order: the lines carry the product name in the customer\'s language, the admin keeps theirs');
    // Changing the lines of that order keeps the customer's language too. (An earlier scenario's coupon use may
    // name a recycled order id; this order has no coupon.)
    $pdo->prepare("DELETE FROM coupon_uses WHERE order_id = ?")->execute([$r18aId]);
    $r18aEdit = ManualOrder::editItems($r18aId, ManualOrder::parseLines('R18A-T 2')['lines']);
    $r18aItems = Orders::items($r18aId);
    assert_true($r18aEdit['ok'] && (string)($r18aItems[0]['name'] ?? '') === 'R18A Teekanne' && (int)($r18aItems[0]['quantity'] ?? 0) === 2,
        'R18 manual order: an edit of the lines keeps the customer\'s language (' . json_encode([$r18aEdit, $r18aItems[0]['name'] ?? null]) . ')');

    // A shipping or payment method the admin left unchosen is refused, never filled in with a default.
    $r18aNoShip = ManualOrder::create($r18aLines, $r18aBuyer(['shipping_method' => '']), false);
    $r18aNoPay = ManualOrder::create($r18aLines, $r18aBuyer(['payment_method' => '']), false);
    assert_equals([false, 'admin.order.manual.choose_methods', false, 'admin.order.manual.choose_methods'],
        [$r18aNoShip['ok'], $r18aNoShip['error'], $r18aNoPay['ok'], $r18aNoPay['error']],
        'R18 manual order: an empty shipping or payment choice is refused with its own message');
    ob_start();
    pageOrderNew();
    $r18aForm = (string)ob_get_clean();
    assert_true(preg_match('/<select id="mo-shipping"[^>]*><option value="">[^<]+<\/option>/', $r18aForm) === 1
        && preg_match('/<select id="mo-payment"[^>]*required[^>]*><option value="">[^<]+<\/option>/', $r18aForm) === 1
        && preg_match('/<select id="mo-(shipping|payment)".*?<option value="[^"]+" selected/s', $r18aForm) === 0,
        'R18 manual order form: shipping and payment start on an empty "choose" option, nothing preselected');

    // Withdrawal notice: "do not ship" before shipping, "wait for the return" once the goods are on their way.
    $r18aWithdrawal = [['received_at' => '2026-11-01 10:00:00', 'items' => '{}', 'reason' => '', 'confirmation_status' => '']];
    $r18aNotice = static function (array $order) use ($r18aWithdrawal): string {
        ob_start();
        renderOrderWithdrawals($order + ['id' => 0], $r18aWithdrawal);
        return (string)ob_get_clean();
    };
    $r18aHasNotice = static fn(string $html, string $key): bool => str_contains($html, esc(I18n::t($key)));
    $r18aPaid = $r18aNotice(['status' => 'paid', 'delivered_at' => null]);
    $r18aDelivered = $r18aNotice(['status' => 'delivered', 'delivered_at' => '2026-10-30 12:00:00']);
    $r18aShipped = $r18aNotice(['status' => 'shipped', 'delivered_at' => null]);
    assert_equals([true, false, true, true, false], [
        $r18aHasNotice($r18aPaid, 'admin.withdrawal.notice'), $r18aHasNotice($r18aPaid, 'admin.withdrawal.notice_sent'),
        $r18aHasNotice($r18aDelivered, 'admin.withdrawal.notice_sent'), $r18aHasNotice($r18aShipped, 'admin.withdrawal.notice_sent'),
        $r18aHasNotice($r18aDelivered, 'admin.withdrawal.notice'),
    ], 'R18 withdrawal: "do not ship" only for goods not sent yet; a shipped or delivered order waits for the return');

    // shipping.json "standard" next to the store's own shipping rates: quoted, and a normal checkout orders it.
    require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
    $r18aStores = Bootstrap::stores();
    $r18aWithIban = $r18aStores;
    $r18aWithIban['stores']['default']['legal'] = ['iban' => 'SK31 1200 0000 1987 4263 7541'] + (array)($r18aStores['stores']['default']['legal'] ?? []);
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $r18aWithIban);
    try {
        $r18aDbRates = (int)$pdo->query("SELECT COUNT(*) FROM shipping_rates WHERE store_id = 'default' AND COALESCE(active, 1) = 1")->fetchColumn();
        $r18aCodes = array_column(ShippingService::calculateRates('SK', 500, 1500, false, 0, 1, 'eur'), 'method_code');
        assert_true($r18aDbRates > 0 && in_array('standard', $r18aCodes, true) && in_array('express', $r18aCodes, true) && in_array('packeta', $r18aCodes, true),
            'R18 shipping: the shipping.json methods are quoted next to the store\'s own rates (' . json_encode([$r18aDbRates, $r18aCodes]) . ')');
        Security::setRateDbForTesting(new PDO('sqlite::memory:'));
        Cart::clear();
        Cart::add(9893, null, 1);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.88';
        $_SESSION['csrf_token'] = 'r18a-standard';
        $_POST = ['csrf_token' => 'r18a-standard', 'name' => 'R18 Standard', 'email' => 'r18a-standard@example.com', 'country' => 'SK',
            'street' => 'Hlavná 1', 'city' => 'Košice', 'zip' => '04001', 'shipping_method' => 'standard', 'payment_method' => 'bank_transfer'];
        // A store-wide free-shipping promotion left by an earlier scenario waives it like any other method.
        $r18aCartNow = Cart::enriched();
        $r18aStdPrice = Pricing::freeShippingEarned((int)$r18aCartNow['subtotal'], $r18aCartNow['items'], null, 'eur') ? 0 : 399;
        try {
            $r18aStd = apiCheckout();
        } finally {
            $_POST = [];
            Cart::clear();
        }
        $r18aOrders[] = $r18aStdId = (int)($r18aStd['order_id'] ?? 0);
        $r18aStdOrder = $r18aStdId > 0 ? Orders::byId($r18aStdId) : [];
        assert_equals([true, 'standard', $r18aStdPrice], [(bool)($r18aStd['success'] ?? false), (string)($r18aStdOrder['shipping_method'] ?? ''), (int)($r18aStdOrder['shipping_cost'] ?? -1)],
            'R18 shipping: a checkout with shipping.json "standard" is accepted at its shipping.json price (' . json_encode([$r18aStd['success'] ?? null, $r18aStd['error_code'] ?? null]) . ')');
    } finally {
        (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $r18aStores);
    }
} finally {
    I18n::setLocale($r18aAdminLocale);
    foreach (array_filter($r18aOrders) as $r18aOrder) OrderWorkflow::transition($r18aOrder, 'cancelled', 'R18 acceptance cleanup');
}
