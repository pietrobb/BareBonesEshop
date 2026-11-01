<?php

// A06.3 / finding 16: Cart::enriched() respects inventory_policy, so backorder/preorder lines are purchasable.
echo "\n--- Audit A06.3: backorder/preorder cart lines and offline reservation expiry ---\n";
ConsentService::setDefinitionsForTesting([]);
$a063Future = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
$a063Product = $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy, available_from, preorder_at)
    VALUES (:id, 'default', 1, :name, :slug, :sku, 'simple', :price, 'eur', 'active', :stock, :policy, :from, :preorder)");
foreach ([
    [9631, 'A063 backorder', 'A063-BACK', 1237, 0, 'backorder', null, null],
    [9632, 'A063 preorder', 'A063-PRE', 2111, 0, 'preorder', $a063Future, $a063Future],
    [9633, 'A063 deny', 'A063-DENY', 500, 1, 'deny', null, null],
    [9634, 'A063 variant parent', 'A063-VPARENT', 900, 0, 'deny', null, null],
    [9635, 'A063 transfer stock', 'A063-STOCK', 700, 5, 'deny', null, null],
] as [$a063Id, $a063Name, $a063Sku, $a063Price, $a063Stock, $a063Policy, $a063From, $a063Pre]) {
    $a063Product->execute([':id' => $a063Id, ':name' => $a063Name, ':slug' => strtolower($a063Sku), ':sku' => $a063Sku, ':price' => $a063Price,
        ':stock' => $a063Stock, ':policy' => $a063Policy, ':from' => $a063From, ':preorder' => $a063Pre]);
}
$pdo->exec("INSERT INTO variants (id, product_id, sku, name, stock, price_delta, availability, inventory_policy)
    VALUES (96341, 9634, 'A063-VBACK', 'Backorder variant', 0, 0, 'active', 'backorder')");
$a063Warehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', :wh, 'A063-STOCK', 5, 0)")
    ->execute([':wh' => $a063Warehouse]);

Cart::clear();
$a063Adds = [
    [9631, null, 2], [9632, null, 2], [9634, 96341, 2], [9633, null, 3],
];
foreach ($a063Adds as [$a063Pid, $a063Vid, $a063Qty]) {
    $a063Add = Cart::addValidated($a063Pid, $a063Vid, $a063Qty);
    assert_true(!empty($a063Add['ok']), "A06.3 cart: product {$a063Pid} can be added (" . ($a063Add['error'] ?? 'ok') . ')');
}
$a063Lines = [];
foreach (Cart::enriched()['items'] as $a063Line) $a063Lines[$a063Line['key']] = $a063Line;
$a063Shape = static fn(string $key): array => [!empty($a063Lines[$key]['available']), (int)($a063Lines[$key]['qty'] ?? -1), (int)($a063Lines[$key]['total'] ?? -1)];
assert_equals([true, 2, 2474], $a063Shape('p_9631'), 'A06.3 cart: zero-stock backorder line is available with the requested quantity and total');
assert_equals([true, 2, 4222], $a063Shape('p_9632'), 'A06.3 cart: future preorder line is available, not coming_soon');
assert_equals([true, 2, 1800], $a063Shape('v_96341'), 'A06.3 cart: backorder variant overrides the deny parent policy');
assert_equals([true, 1, 500], $a063Shape('p_9633'), 'A06.3 cart: deny line is still capped at physical stock');

// HTML transport: the theme cart renders the same backorder totals.
if (!function_exists('renderCart')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/CheckoutPages.php';
$a063Globals = ['t' => $GLOBALS['t'] ?? null, 'storefront_currency' => $GLOBALS['storefront_currency'] ?? null];
$GLOBALS['t'] = static fn(string $key): string => $key;
// Cart amounts are formatted in the cart's currency (storefrontCartPrice()).
$a063Price = static fn(int $cents): string => htmlspecialchars(I18n::price($cents, (string)Cart::enriched()['currency']));
try {
    $a063Html = renderCart();
} finally {
    foreach ($a063Globals as $a063Key => $a063Value) $GLOBALS[$a063Key] = $a063Value;
}
assert_true(str_contains($a063Html, $a063Price(2474)) && str_contains($a063Html, $a063Price(4222)), 'A06.3 HTML cart: backorder and preorder line totals are rendered');
assert_true(str_contains($a063Html, $a063Price(8996)), 'A06.3 HTML cart: subtotal includes backorder/preorder lines');

// API transport: checkout freezes the shortfall as backordered instead of refusing the order.
$a063Checkout = static function(array $lines, string $ip, string $email) use ($pdo): array {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Cart::clear();
    foreach ($lines as [$pid, $vid, $qty]) Cart::add($pid, $vid, $qty);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_SESSION['csrf_token'] = 'a063-checkout';
    $_POST = ['csrf_token' => 'a063-checkout', 'name' => 'A063 Buyer', 'email' => $email, 'country' => 'SK',
        'street' => 'Audit 3', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer'];
    $result = apiCheckout();
    $_POST = [];
    Cart::clear();
    return $result;
};
$a063Back = $a063Checkout([[9631, null, 2], [9634, 96341, 1]], '127.0.6.31', 'a063-back@example.com');
assert_true(!empty($a063Back['success']), 'A06.3 API: backorder cart checks out (' . ($a063Back['error'] ?? 'ok') . ')');
$a063BackItems = [];
foreach (Orders::items((int)($a063Back['order_id'] ?? 0)) as $a063Item) $a063BackItems[$a063Item['sku']] = [(int)$a063Item['quantity'], (int)$a063Item['backordered_qty'], (string)$a063Item['fulfillment_status']];
ksort($a063BackItems);
assert_equals(['A063-BACK' => [2, 2, 'backordered'], 'A063-VBACK' => [1, 1, 'backordered']], $a063BackItems, 'A06.3 API: order lines freeze the backordered quantity');

// Finding 14: an unpaid bank transfer releases its reservation after the configured age (default 14 days).
JobQueue::pull(500);
$a063Transfer = $a063Checkout([[9635, null, 2]], '127.0.6.32', 'a063-transfer@example.com');
$a063TransferId = (int)($a063Transfer['order_id'] ?? 0);
$a063Reserved = static fn(): int => (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE sku = 'A063-STOCK'")->fetchColumn();
assert_equals([true, 2], [!empty($a063Transfer['success']), $a063Reserved()], 'A06.3 expiry: bank transfer checkout reserves two pieces');
$a063Backdate = $pdo->prepare('UPDATE orders SET created_at = :at WHERE id = :id');
$a063Backdate->execute([':at' => gmdate('Y-m-d H:i:s', time() - 13 * 86400), ':id' => $a063TransferId]);
$a063Sweep = static fn(): int => class_exists('PendingOrderExpiry')
    ? PendingOrderExpiry::sweep(['pending_order_expiry_seconds' => 172800, 'pending_order_expiry_batch' => 25], null)
    : Orders::expirePending(172800, 25);
$a063Sweep();
assert_equals(['pending', 2], [(string)Orders::byId($a063TransferId)['status'], $a063Reserved()], 'A06.3 expiry: 13-day-old transfer is still inside the default 14-day window');
// R11: due at the end of day 14 on the shop's calendar, then 3 grace days for the money to arrive.
$a063Backdate->execute([':at' => gmdate('Y-m-d H:i:s', time() - 18 * 86400), ':id' => $a063TransferId]);
$a063Sweep();
$a063Expired = Orders::byId($a063TransferId);
assert_equals(['cancelled', 'cancelled', 0], [(string)$a063Expired['status'], (string)$a063Expired['payment_status'], $a063Reserved()], 'A06.3 expiry: an unpaid transfer past its due date and grace days is cancelled and its reservation released');
$a063Mail = array_values(array_filter(JobQueue::pull(500), static fn(array $job): bool => ($job['payload']['template'] ?? '') === 'order_cancelled'
    && (int)($job['payload']['order_id'] ?? 0) === $a063TransferId));
assert_equals(1, count($a063Mail), 'A06.3 expiry: transfer buyer receives one order_cancelled e-mail');
$a063Sweep();
assert_equals(0, $a063Reserved(), 'A06.3 expiry: repeated sweep cannot release the reservation twice');
