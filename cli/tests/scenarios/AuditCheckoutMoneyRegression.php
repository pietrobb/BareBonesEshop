<?php

// A06.2 / findings 12, 13: mixed-currency checkout and coupon limits (normalized email, atomic claim).
echo "\n--- Audit A06.2: mixed-currency checkout and atomic coupon limits ---\n";

// Finding 12 through the real checkout handler: a mixed cart is refused, never charged in the first currency.
ConsentService::setDefinitionsForTesting([]);
$pdo->exec("INSERT INTO products (id, category_id, name, slug, sku, product_type, price, currency, status, stock)
    VALUES (9621, 1, 'A062 EUR guide', 'a062-eur-guide', 'A062-EUR', 'downloadable', 50000, 'eur', 'active', 0),
           (9622, 1, 'A062 HUF guide', 'a062-huf-guide', 'A062-HUF', 'downloadable', 50000, 'huf', 'active', 0)");
$pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
    VALUES ('A062-EUR', 'pdf', 'PDF', 'guide.pdf', 'guide.pdf', 'application/pdf', 2, 3600, 1),
           ('A062-HUF', 'pdf', 'PDF', 'guide.pdf', 'guide.pdf', 'application/pdf', 2, 3600, 1)");
$a062Checkout = static function(array $productIds, string $ip) use ($pdo): array {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Cart::clear();
    foreach ($productIds as $productId) Cart::add($productId, null, 1);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_SESSION['csrf_token'] = 'a062-checkout';
    $_POST = ['csrf_token' => 'a062-checkout', 'name' => 'A062 Buyer', 'email' => 'a062@example.com', 'country' => 'SK', 'shipping_method' => 'digital', 'payment_method' => 'bank_transfer', 'consents' => ['digital_delivery' => true]];
    $before = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $result = apiCheckout();
    $_POST = [];
    return [$result, (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() - $before];
};
[$a062Mixed, $a062Created] = $a062Checkout([9621, 9622], '127.0.6.21');
assert_true(empty($a062Mixed['success']), 'A06.2 currency: checkout with EUR and HUF lines is refused (' . ($a062Mixed['error'] ?? 'accepted') . ')');
assert_equals(0, $a062Created, 'A06.2 currency: refused mixed cart creates no order');
[$a062Single, $a062Created] = $a062Checkout([9622], '127.0.6.22');
$a062Order = Orders::byId((int)($a062Single['order_id'] ?? 0)) ?: ['id' => (int)$pdo->query('SELECT MAX(id) FROM orders')->fetchColumn()];
assert_true(!empty($a062Single['success']) && $a062Created === 1, 'A06.2 currency: single-currency cart still checks out (' . ($a062Single['error'] ?? 'ok') . ')');
assert_equals(['huf', 50000], [(string)($a062Order['currency'] ?? ''), (int)($a062Order['subtotal'] ?? 0)], 'A06.2 currency: order uses the cart currency and amount');
Cart::clear();
JobQueue::pull(500);

// Finding 13a: per-customer limits compare normalized e-mail addresses.
$a062Config = (new ReflectionProperty(Pricing::class, 'config'))->getValue();
Pricing::setConfig(['cart_rules' => [
    ['type' => 'coupon', 'active' => true, 'code' => 'A062ONE', 'name' => 'A062 one per customer', 'discount_type' => 'fixed', 'discount_value' => 100, 'per_customer' => 1],
]]);
try {
    Pricing::recordCouponUse('a062one', (int)$a062Order['id'], ' Buyer.A062@Example.COM ', 100);
    assert_equals('buyer.a062@example.com', (string)$pdo->query("SELECT customer_email FROM coupon_uses WHERE coupon_code = 'A062ONE'")->fetchColumn(), 'A06.2 coupon: stored e-mail is normalized');
    assert_equals('Coupon already used', Pricing::applyCoupon('A062ONE', 1000, 'buyer.a062@example.com')['error'], 'A06.2 coupon: per-customer limit sees differently-cased e-mail');
    $a062Again = null;
    try { Pricing::recordCouponUse('A062ONE', (int)$a062Order['id'], 'BUYER.A062@example.com', 100); } catch (RuntimeException $e) { $a062Again = $e->getMessage(); }
    assert_true($a062Again !== null, 'A06.2 coupon: recording a second use with another casing is refused');
    assert_equals(null, Pricing::applyCoupon('A062ONE', 1000, 'other.a062@example.com')['error'], 'A06.2 coupon: another customer can still use the coupon');
} finally {
    Pricing::setConfig($a062Config ?? []);
}
