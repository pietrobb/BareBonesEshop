<?php

// A13.1 / customer order detail + reorder through the real account.php handler: only the customer's own order in the
// current store is visible (no foreign PII, 404 otherwise); reorder puts the lines back at today's price and skips what
// can no longer be bought; CSRF and login are required. SQLite always, MySQL 8 / MariaDB 11.4 via TEST_MYSQL_DSN.
echo "\n--- Audit A13.1: customer order detail and reorder (ownership, store, current price/stock, CSRF) ---\n";

$a131Root = test_fixture_path('account-orders');
mkdir($a131Root);
mkdir($a131Root . '/sessions');
$a131Fixture = $a131Root . '/request.php';
file_put_contents($a131Fixture, <<<'PHP'
<?php
[, $driver, $phase, $store, $action, $root, $target, $dir] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
$idOf = static function(string $table, string $column, string $value) use ($db): int {
    $stmt = $db->prepare("SELECT id FROM {$table} WHERE {$column} = ?");
    $stmt->execute([$value]);
    return (int)$stmt->fetchColumn();
};
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $hash = password_hash('A131-pass-word', PASSWORD_BCRYPT, ['cost' => 4]);
    foreach (['default' => 'eur', 'cz' => 'czk'] as $owner => $currency) {
        $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute([$owner, "a131cat-{$owner}", 'Cat', "a131cat-{$owner}"]);
        $cat = $idOf('categories', 'slug', "a131cat-{$owner}");
        $product = $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $product->execute([$owner, $cat, "A131-LIVE-{$owner}", "a131-live-{$owner}", "A131 Live {$owner}", 1000, 10, 'active']);
        $product->execute([$owner, $cat, "A131-VAR-{$owner}", "a131-var-{$owner}", "A131 Variant {$owner}", 500, 0, 'active']);
        $product->execute([$owner, $cat, "A131-GONE-{$owner}", "a131-gone-{$owner}", "A131 Gone {$owner}", 300, 10, 'draft']);
        $product->execute([$owner, $cat, "A131-EMPTY-{$owner}", "a131-empty-{$owner}", "A131 Empty {$owner}", 400, 0, 'active']);
        $db->prepare("INSERT INTO variants (product_id, sku, name, stock) VALUES (?, ?, 'Red', 5)")->execute([$idOf('products', 'sku', "A131-VAR-{$owner}"), "A131-VAR-RED-{$owner}"]);
        $customer = $db->prepare("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES (?, ?, ?, ?, 'active')");
        $customer->execute([$owner, "a131-owner-{$owner}@example.test", $hash, "A131 Owner {$owner}"]);
        $customer->execute([$owner, "a131-other-{$owner}@example.test", $hash, "A131 Other {$owner}"]);
        $customerId = static fn(string $email) => (int)$db->query("SELECT id FROM customers WHERE store_id = " . $db->quote($owner) . " AND email = " . $db->quote($email))->fetchColumn();
        $order = $db->prepare("INSERT INTO orders (store_id, order_number, status, payment_status, customer_id, customer_name, customer_email, shipping_addr, payment_method,
            payment_method_snapshot, shipping_method, shipping_rate_snapshot, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, tracking_number, carrier)
            VALUES (?, ?, 'shipped', 'paid', ?, ?, ?, ?, 'bank_transfer', ?, 'courier', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $order->execute([$owner, "A131-OWN-{$owner}", $customerId("a131-owner-{$owner}@example.test"), "A131 Owner {$owner}", "a131-owner-{$owner}@example.test",
            json_encode(['street' => "A131 Street {$owner}", 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK']), json_encode(['label' => 'A131 Bank']),
            json_encode(['name' => 'A131 Courier']), 3500, 200, 390, 610, 3690, $currency, "A131TRACK{$owner}", 'A131Carrier']);
        $order->execute([$owner, "A131-OTHER-{$owner}", $customerId("a131-other-{$owner}@example.test"), "A131 FOREIGN-PII {$owner}", "a131-other-{$owner}@example.test",
            json_encode(['street' => "A131 FOREIGN-STREET {$owner}", 'city' => 'Kosice', 'zip' => '04001', 'country' => 'SK']), '{}', '{}', 1000, 0, 0, 0, 1000, $currency, null, null]);
        $item = $db->prepare('INSERT INTO order_items (order_id, product_id, variant_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $own = $idOf('orders', 'order_number', "A131-OWN-{$owner}");
        // Prices at the time of the order differ from today's (1000 / 500): reorder must not carry them over.
        $item->execute([$own, $idOf('products', 'sku', "A131-LIVE-{$owner}"), null, "A131-LIVE-{$owner}", "A131 Live {$owner}", 3, 700, 2100]);
        $item->execute([$own, $idOf('products', 'sku', "A131-VAR-{$owner}"), $idOf('variants', 'sku', "A131-VAR-RED-{$owner}"), "A131-VAR-RED-{$owner}", "A131 Variant {$owner}", 1, 450, 450]);
        $item->execute([$own, $idOf('products', 'sku', "A131-GONE-{$owner}"), null, "A131-GONE-{$owner}", "A131 Gone {$owner}", 1, 300, 300]);
        $item->execute([$own, $idOf('products', 'sku', "A131-EMPTY-{$owner}"), null, "A131-EMPTY-{$owner}", "A131 Empty {$owner}", 2, 325, 650]);
        $item->execute([$idOf('orders', 'order_number', "A131-OTHER-{$owner}"), $idOf('products', 'sku', "A131-LIVE-{$owner}"), null, "A131-LIVE-{$owner}", "A131 FOREIGN-ITEM {$owner}", 1, 1000, 1000]);
    }
    exit(0);
}

$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['default'], ['id' => 'cz', 'url_prefix' => '/cz', 'domains' => [], 'catalog_scope' => 'cz',
    'provider_scope' => 'cz', 'languages' => ['sk', 'en'], 'currency' => 'czk']);
$stores['stores']['default']['languages'] = ['sk', 'en'];
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$prefix = $store === 'cz' ? '/cz' : '';
$_SERVER['REMOTE_ADDR'] = '127.0.13.1';
$_SERVER['REQUEST_URI'] = $prefix . '/account.php';
Bootstrap::initLocale($_SERVER['REQUEST_URI']);
$foreign = $store === 'default' ? 'cz' : 'default';
$orderId = static fn(string $kind, string $owner) => $idOf('orders', 'order_number', "A131-{$kind}-{$owner}");
if ($action !== 'guest_page' && $action !== 'guest_reorder') Customers::login("a131-owner-{$store}@example.test", 'A131-pass-word');
$csrf = (string)($_SESSION['csrf_token'] ?? Security::csrfToken());
[$method, $get, $post] = match ($action) {
    'orders_page' => ['GET', ['p' => 'orders'], []],
    'own_page' => ['GET', ['p' => 'order', 'id' => (string)$orderId('OWN', $store)], []],
    'foreign_customer_page' => ['GET', ['p' => 'order', 'id' => (string)$orderId('OTHER', $store)], []],
    'foreign_store_page' => ['GET', ['p' => 'order', 'id' => (string)$orderId('OWN', $foreign)], []],
    'guest_page' => ['GET', ['p' => 'order', 'id' => (string)$orderId('OWN', $store)], []],
    'reorder_own' => ['POST', [], ['action' => 'reorder', 'order_id' => (string)$orderId('OWN', $store), 'csrf_token' => $csrf]],
    'reorder_foreign_customer' => ['POST', [], ['action' => 'reorder', 'order_id' => (string)$orderId('OTHER', $store), 'csrf_token' => $csrf]],
    'reorder_foreign_store' => ['POST', [], ['action' => 'reorder', 'order_id' => (string)$orderId('OWN', $foreign), 'csrf_token' => $csrf]],
    'reorder_no_csrf' => ['POST', [], ['action' => 'reorder', 'order_id' => (string)$orderId('OWN', $store)]],
    'guest_reorder' => ['POST', [], ['action' => 'reorder', 'order_id' => (string)$orderId('OWN', $store), 'csrf_token' => $csrf]],
};
$_SERVER['REQUEST_METHOD'] = $method;
$_GET = $get;
$_POST = $post;
$_SERVER['REQUEST_URI'] = $prefix . '/account.php' . ($get ? '?' . http_build_query($get) : '');
http_response_code(200);
$expected = [
    'price' => I18n::price(1000, $store === 'cz' ? 'czk' : 'eur'),
    'total' => I18n::price(3690, $store === 'cz' ? 'czk' : 'eur'),
    'discount' => I18n::price(-200, $store === 'cz' ? 'czk' : 'eur'),
];
ob_start();
register_shutdown_function(static function() use ($expected): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    $cart = [];
    if (!empty($_SESSION['cart'])) {
        foreach (Cart::enriched()['items'] as $line) $cart[$line['sku']] = ['qty' => (int)$line['qty'], 'price' => (int)$line['price']];
    }
    echo "\nA131RESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'flash' => $_SESSION['account_flash'] ?? null,
        'page_flash' => $GLOBALS['flash'] ?? null, 'cart' => $cart, 'expected' => $expected]);
});
require $root . '/account.php';
PHP);
$a131Run = static function(array $args) use ($a131Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a131Fixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'A131RESULT');
    $result = $at === false ? null : json_decode(substr($raw, $at + 10), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$a131Drivers = ['sqlite' => $a131Root . '/account.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a131Drivers['mysql'] = 'bbe_a131_account_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A13.1 native customer order detail/reorder (set TEST_MYSQL_DSN)\n";
$a131Root2 = dirname(TEST_CLI_DIR);
try {
    foreach ($a131Drivers as $a131Driver => $a131Target) {
        [$a131Exit, , $a131Noise] = $a131Run([$a131Driver, 'setup', 'default', 'none', $a131Root2, $a131Target, $a131Root]);
        assert_equals([0, ''], [$a131Exit, trim($a131Noise)], "A13.1 {$a131Driver}: migrated fixture with two stores, two customers and their orders");
        foreach (['default', 'cz'] as $a131Store) {
            $a131Foreign = $a131Store === 'default' ? 'cz' : 'default';
            $a131Do = static function(string $action) use ($a131Run, $a131Driver, $a131Store, $a131Root2, $a131Target, $a131Root): array {
                [$exit, $result, $noise] = $a131Run([$a131Driver, 'run', $a131Store, $action, $a131Root2, $a131Target, $a131Root]);
                $label = "A13.1 {$a131Driver}/{$a131Store}/{$action}";
                assert_true($exit === 0 && $result !== null && !str_contains((string)$result['output'], 'Fatal error') && !str_contains((string)$result['output'], 'Uncaught'),
                    "{$label}: real account.php handler completes (" . trim($noise) . ')');
                return [$label, $result ?? ['code' => null, 'output' => '', 'flash' => null, 'page_flash' => null, 'cart' => [], 'expected' => []]];
            };
            $a131NoForeign = static fn(string $html): bool => !str_contains($html, 'FOREIGN-PII') && !str_contains($html, 'FOREIGN-STREET') && !str_contains($html, 'FOREIGN-ITEM')
                && !str_contains($html, "A131-OTHER-");

            // First request after login: the fixture hash has bcrypt cost 4, so login rehashes it (cost 12) — the session must stay valid.
            [$l, $r] = $a131Do('orders_page');
            assert_equals(200, $r['code'], "{$l}: the first request after a login that upgraded the password hash keeps the customer signed in");
            assert_true(preg_match('~href="[^"]*account\.php\?p=order&(?:amp;)?id=\d+"[^>]*>A131-OWN-' . $a131Store . '<~', $r['output']) === 1
                && $a131NoForeign($r['output']) && !str_contains($r['output'], "A131-OWN-{$a131Foreign}"), "{$l}: own order links to its detail, nothing foreign listed");

            [$l, $r] = $a131Do('own_page');
            $html = (string)$r['output'];
            assert_true($r['code'] === 200 && str_contains($html, "A131-OWN-{$a131Store}") && str_contains($html, "A131 Live {$a131Store}")
                && str_contains($html, "A131 Variant {$a131Store}") && str_contains($html, "A131-VAR-RED-{$a131Store}"), "{$l}: detail lists every line with SKU");
            assert_true(str_contains($html, htmlspecialchars($r['expected']['total'], ENT_QUOTES)) && str_contains($html, htmlspecialchars($r['expected']['discount'], ENT_QUOTES)),
                "{$l}: totals and discount in the order's own currency ({$r['expected']['total']})");
            assert_true(str_contains($html, "A131 Street {$a131Store}") && str_contains($html, 'A131 Courier') && str_contains($html, 'A131 Bank')
                && str_contains($html, "A131TRACK{$a131Store}"), "{$l}: shipping address, method, payment and tracking shown");
            assert_true(preg_match('~<form method="post"[^>]*>(?:(?!</form>).)*name="csrf_token" value="[^"]+"(?:(?!</form>).)*name="action" value="reorder"~s', $html) === 1
                && $a131NoForeign($html), "{$l}: reorder is a CSRF-protected no-JS form; no other customer's data rendered");

            foreach (['foreign_customer_page' => 'another customer', 'foreign_store_page' => 'another store'] as $a131Action => $a131What) {
                [$l, $r] = $a131Do($a131Action);
                assert_true($r['code'] === 404 && $a131NoForeign((string)$r['output']) && !str_contains((string)$r['output'], "A131 Street {$a131Foreign}")
                    && !str_contains((string)$r['output'], 'name="action" value="reorder"'), "{$l}: an order of {$a131What} is a plain 404 without its data");
            }
            [$l, $r] = $a131Do('guest_page');
            assert_true(!str_contains((string)$r['output'], "A131-OWN-{$a131Store}") && !str_contains((string)$r['output'], "A131 Street {$a131Store}"), "{$l}: guests are sent to login, no order data");

            [$l, $r] = $a131Do('reorder_own');
            assert_equals(["A131-LIVE-{$a131Store}" => ['qty' => 3, 'price' => 1000], "A131-VAR-RED-{$a131Store}" => ['qty' => 1, 'price' => 500]], $r['cart'],
                "{$l}: purchasable lines are back in the cart at today's price (not 700/450 from the order)");
            assert_true(($r['flash']['type'] ?? '') === 'ok' && str_contains((string)$r['flash']['message'], "A131 Gone {$a131Store}")
                && str_contains((string)$r['flash']['message'], "A131 Empty {$a131Store}") && str_contains((string)$r['flash']['message'], '2'),
                "{$l}: inactive and out-of-stock lines are skipped and named (" . json_encode($r['flash'], JSON_UNESCAPED_UNICODE) . ')');

            foreach (['reorder_foreign_customer', 'reorder_foreign_store', 'guest_reorder'] as $a131Action) {
                [$l, $r] = $a131Do($a131Action);
                assert_true($r['cart'] === [] && $r['flash'] === null, "{$l}: nothing is added to the cart");
            }
            [$l, $r] = $a131Do('reorder_no_csrf');
            assert_true($r['code'] === 403 && $r['cart'] === [], "{$l}: missing CSRF token is refused");
        }
    }
} finally {
    if (isset($a131Drivers['mysql'])) $a131Run(['mysql', 'teardown', 'default', 'none', $a131Root2, $a131Drivers['mysql'], $a131Root]);
    $a131Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a131Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a131Tree as $a131Path) $a131Path->isDir() ? @rmdir($a131Path->getPathname()) : @unlink($a131Path->getPathname());
    @rmdir($a131Root);
}
