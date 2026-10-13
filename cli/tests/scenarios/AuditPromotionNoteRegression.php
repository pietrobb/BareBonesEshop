<?php

// A06.4 / low findings: same-product Buy-X-get-Y over-discount, checkout note not persisted or shown in admin.
echo "\n--- Audit A06.4: same-product Buy-X-get-Y and checkout note ---\n";
ConsentService::setDefinitionsForTesting([]);
$pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (9641, 'default', 1, 'A064 pack', 'a064-pack', 'A064-PACK', 'simple', 1000, 'eur', 'active', 0, 'backorder'),
           (9642, 'default', 1, 'A064 gift', 'a064-gift', 'A064-GIFT', 'simple', 300, 'eur', 'active', 0, 'backorder')");
$pdo->prepare("INSERT INTO promotions (store_id, code, name, scope, priority, stacking, conditions, actions) VALUES ('default', 'a064-b2g1', 'A064 buy 2 get 1', 'cart', 1, 'stack', ?, ?)")
    ->execute([json_encode(['buy_product_id' => 9641, 'buy_qty' => 2]), json_encode(['type' => 'buy_x_get_y', 'product_id' => 9641, 'qty' => 1])]);

$a064Checkout = static function(array $lines, string $ip, string $email, array $extra = []) use ($pdo): array {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Cart::clear();
    foreach ($lines as [$pid, $qty]) Cart::add($pid, null, $qty);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_SESSION['csrf_token'] = 'a064-checkout';
    $_POST = array_replace(['csrf_token' => 'a064-checkout', 'name' => 'A064 Buyer', 'email' => $email, 'country' => 'SK',
        'street' => 'Audit 4', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer'], $extra);
    $result = apiCheckout();
    $_POST = [];
    Cart::clear();
    $order = Orders::byId((int)($result['order_id'] ?? 0)) ?: [];
    return [$result, $order];
};
try {
    foreach ([[2, 0], [3, 1000], [5, 1000], [6, 2000]] as $a064Index => [$a064Qty, $a064Discount]) {
        [$a064Result, $a064Order] = $a064Checkout([[9641, $a064Qty]], '127.0.6.4' . $a064Index, "a064-bxgy-{$a064Qty}@example.com");
        assert_equals([true, $a064Discount], [!empty($a064Result['success']), (int)($a064Order['discount_amount'] ?? -1)],
            "A06.4 BxGy API: buy 2 get 1 of the same product with {$a064Qty} in cart discounts {$a064Discount} (" . ($a064Result['error'] ?? 'ok') . ')');
    }
    $pdo->prepare("INSERT INTO promotions (store_id, code, name, scope, priority, stacking, conditions, actions) VALUES ('default', 'a064-other', 'A064 buy 2 get gift', 'cart', 2, 'stack', ?, ?)")
        ->execute([json_encode(['buy_product_id' => 9641, 'buy_qty' => 2]), json_encode(['type' => 'buy_x_get_y', 'product_id' => 9642, 'qty' => 1])]);
    $a064Other = array_values(array_filter(Promotions::cartActions([['product_id' => 9641, 'qty' => 2, 'price' => 1000], ['product_id' => 9642, 'qty' => 1, 'price' => 300]], 2300),
        static fn(array $action): bool => $action['code'] === 'a064-other'));
    assert_equals(300, (int)($a064Other[0]['discount'] ?? -1), 'A06.4 BxGy: a different get-product still gets its free unit');
} finally {
    $pdo->exec("UPDATE promotions SET active = 0 WHERE code IN ('a064-b2g1', 'a064-other')");
}

// API: the buyer note is stored as plain bounded text.
[$a064NoteResult, $a064NoteOrder] = $a064Checkout([[9642, 1]], '127.0.6.49', 'a064-note@example.com', ['notes' => "  Zazvoňte 2×\r\n<b>ďakujem</b>\x07  "]);
assert_equals([true, "Zazvoňte 2×\n<b>ďakujem</b>"], [!empty($a064NoteResult['success']), (string)($a064NoteOrder['notes'] ?? '')], 'A06.4 note API: checkout note is persisted without control characters');
[, $a064LongOrder] = $a064Checkout([[9642, 1]], '127.0.6.50', 'a064-long@example.com', ['notes' => str_repeat('ž', 2500)]);
assert_equals(2000, mb_strlen((string)($a064LongOrder['notes'] ?? '')), 'A06.4 note API: note is bounded to 2000 characters');
[$a064ArrayResult, $a064ArrayOrder] = $a064Checkout([[9642, 1]], '127.0.6.51', 'a064-array@example.com', ['notes' => ['x']]);
assert_equals('', (string)($a064ArrayOrder['notes'] ?? 'missing'), 'A06.4 note API: non-string note is ignored (' . ($a064ArrayResult['error'] ?? 'ok') . ')');

// HTML transport: the real form handler (redirects + exits on success) runs in a child against this test database.
$a064Root = test_fixture_path('checkout-note');
mkdir($a064Root);
file_put_contents($a064Root . '/html.php', <<<'PHP'
<?php
[, $root, $dbPath] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
JobQueue::setDir(__DIR__ . '/jobs');
ConsentService::setDefinitionsForTesting([]);
require $root . '/src/Entrypoints/Storefront/CheckoutPages.php';
$t = static fn(string $key): string => $key;
$price = static fn(int $cents): string => (string)$cents;
$_SESSION = ['cart' => ['p_9642' => ['product_id' => 9642, 'variant_id' => null, 'qty' => 1]], 'csrf_token' => 'a064-html'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.6.52';
$_POST = ['checkout' => '1', 'csrf_token' => 'a064-html', 'name' => 'A064 Html', 'email' => 'a064-html@example.com', 'phone' => '',
    'street' => 'Form 4', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'shipping_method' => 'packeta',
    'payment' => 'bank_transfer', 'notes' => "Nechajte pri dverách\r\nvďaka"];
echo processCheckout();
PHP);
$a064Process = proc_open([PHP_BINARY, $a064Root . '/html.php', dirname(TEST_CLI_DIR), $testDbPath], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $a064Pipes);
fclose($a064Pipes[0]);
$a064HtmlOut = stream_get_contents($a064Pipes[1]) . stream_get_contents($a064Pipes[2]);
fclose($a064Pipes[1]);
fclose($a064Pipes[2]);
$a064HtmlCode = proc_close($a064Process);
$a064Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a064Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($a064Tree as $a064Path) $a064Path->isDir() ? rmdir($a064Path->getPathname()) : unlink($a064Path->getPathname());
rmdir($a064Root);
// A fresh connection: the suite connection may still hold a WAL read snapshot from an unfinished cursor.
$a064HtmlNote = (new PDO('sqlite:' . $testDbPath))->query("SELECT id, notes FROM orders WHERE customer_email = 'a064-html@example.com' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
assert_equals([0, "Nechajte pri dverách\nvďaka"], [$a064HtmlCode, (string)($a064HtmlNote['notes'] ?? trim(strip_tags($a064HtmlOut)))], 'A06.4 note HTML: form checkout persists the note (' . substr(trim(strip_tags($a064HtmlOut)), 0, 300) . ')');

// Admin: the order detail shows the escaped note.
if (!function_exists('pageOrder')) {
    if (!function_exists('adminFieldErrors') && is_file(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php';
}
$_GET = ['id' => (string)(int)($a064NoteOrder['id'] ?? 0)];
ob_start();
try {
    pageOrder();
} finally {
    $a064Admin = (string)ob_get_clean();
    $_GET = [];
}
assert_true(str_contains($a064Admin, 'data-order-note') && str_contains($a064Admin, 'Zazvoňte 2×') && str_contains($a064Admin, '&lt;b&gt;ďakujem&lt;/b&gt;'),
    'A06.4 note admin: order detail shows the escaped buyer note');
