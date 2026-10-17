<?php

// A13.3 / checkout form state through the real storefront handler (index.php?page=checkout): a signed-in customer's
// form starts from the profile and default shipping address, a saved address can be picked without JS (only the
// customer's own, CSRF-protected, nothing ordered), a refused order re-renders the form with every value kept and the
// reason, and the countries come from the store's shipping zones instead of a hardcoded list. SQLite always,
// MySQL 8 / MariaDB 11.4 via TEST_MYSQL_DSN.
echo "\n--- Audit A13.3: checkout saved-address prefill, kept values after refusal, countries from shipping zones ---\n";

$a133Root = test_fixture_path('checkout-address');
mkdir($a133Root);
mkdir($a133Root . '/sessions');
$a133Fixture = $a133Root . '/request.php';
file_put_contents($a133Fixture, <<<'PHP'
<?php
[, $driver, $phase, $action, $root, $target, $dir, $extra] = $argv;
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
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'a133cat', 'Cat', 'a133cat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'a133cat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'A133-SKU', 'a133-product', 'A133 Product', 2000, 50, 'active']);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'A133-SKU']);
    // Norway is configured only in this store's database zones; Iceland only for another store.
    $db->prepare('INSERT INTO shipping_zone_countries (store_id, zone_code, country) VALUES (?, ?, ?)')->execute(['default', 'eu', 'NO']);
    $db->prepare('INSERT INTO shipping_zone_countries (store_id, zone_code, country) VALUES (?, ?, ?)')->execute(['other', 'eu', 'IS']);
    $customer = $db->prepare("INSERT INTO customers (store_id, email, password_hash, name, phone, status) VALUES ('default', ?, ?, ?, ?, 'active')");
    $customer->execute(['a133-member@example.test', 'a133-hash-member', 'A133 Member', '+421900000133']);
    $member = (int)$db->lastInsertId();
    $customer->execute(['a133-other@example.test', 'a133-hash-other', 'A133 Other', '']);
    $other = (int)$db->lastInsertId();
    $address = $db->prepare("INSERT INTO customer_addresses (customer_id, type, is_default, name, street, city, zip, country, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $address->execute([$member, 'shipping', 1, 'A133 Default Name', 'A133 Default Street 1', 'Brno', '60200', 'CZ', '']);
    $address->execute([$member, 'shipping', 0, 'A133 Second Name', 'A133 Second Street 2', 'Wien', '1010', 'AT', '+43100000133']);
    $address->execute([$member, 'billing', 1, 'A133 Billing Name', 'A133 Billing Street 3', 'Praha', '11000', 'CZ', '']);
    $address->execute([$other, 'shipping', 1, 'A133 Foreign Name', 'A133 Foreign Street 9', 'Kosice', '04001', 'SK', '']);
    exit(0);
}

$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.13.3';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'A133-SKU'")->fetchColumn(), null, 1);
$addressId = static fn(string $street): string => (string)$db->query("SELECT id FROM customer_addresses WHERE street = " . $db->quote($street))->fetchColumn();
if (str_starts_with($action, 'member')) {
    $member = $db->query("SELECT id, password_hash FROM customers WHERE email = 'a133-member@example.test'")->fetch();
    $_SESSION += ['customer_id' => (int)$member['id'], 'customer_last_seen' => time(), 'customer_auth_hash' => hash('sha256', (string)$member['password_hash'])];
}
$csrf = Security::csrfToken();
$typed = ['name' => 'A133 Typed', 'email' => 'a133-typed@example.test', 'phone' => '+421900000999', 'street' => 'A133 Typed Street 5',
    'city' => 'Zilina', 'zip' => '01001', 'country' => 'CZ', 'notes' => 'A133 leave at door'] + (json_decode($extra, true) ?: []);
[$method, $post] = match ($action) {
    'guest_get', 'member_get' => ['GET', []],
    'member_use_second' => ['POST', $typed + ['checkout' => '1', 'csrf_token' => $csrf, 'address_action' => 'use', 'saved_address' => $addressId('A133 Second Street 2')]],
    'member_use_foreign' => ['POST', $typed + ['checkout' => '1', 'csrf_token' => $csrf, 'address_action' => 'use', 'saved_address' => $addressId('A133 Foreign Street 9')]],
    'member_use_no_csrf' => ['POST', $typed + ['checkout' => '1', 'address_action' => 'use', 'saved_address' => $addressId('A133 Second Street 2')]],
    'guest_refused' => ['POST', ['name' => ''] + $typed + ['checkout' => '1', 'csrf_token' => $csrf]],
    'guest_place' => ['POST', $typed + ['checkout' => '1', 'csrf_token' => $csrf]],
};
$_SERVER['REQUEST_METHOD'] = $method;
$_GET = ['page' => 'checkout'];
$_POST = $post;
http_response_code(200);
$orders = static fn(): array => $db->query("SELECT order_number, customer_name, shipping_addr FROM orders ORDER BY id")->fetchAll();
$before = count($orders());
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nA133RESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$a133Run = static function(array $args) use ($a133Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a133Fixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'A133RESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$a133Drivers = ['sqlite' => $a133Root . '/checkout.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a133Drivers['mysql'] = 'bbe_a133_address_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A13.3 native checkout address form (set TEST_MYSQL_DSN)\n";
$a133App = dirname(TEST_CLI_DIR);
try {
    foreach ($a133Drivers as $a133Driver => $a133Target) {
        [$a133Exit, , $a133Noise] = $a133Run([$a133Driver, 'setup', 'none', $a133App, $a133Target, $a133Root, '{}']);
        assert_equals([0, ''], [$a133Exit, trim($a133Noise)], "A13.3 {$a133Driver}: migrated fixture with a product, two customers and their addresses");
        $a133Extra = '{}';
        $a133Do = static function(string $action) use (&$a133Extra, $a133Run, $a133Driver, $a133App, $a133Target, $a133Root): array {
            [$exit, $result, $noise] = $a133Run([$a133Driver, 'run', $action, $a133App, $a133Target, $a133Root, $a133Extra]);
            $label = "A13.3 {$a133Driver}/{$action}";
            assert_true($exit === 0 && $result !== null && !str_contains((string)$result['output'], 'Fatal error') && !str_contains((string)$result['output'], 'Uncaught'),
                "{$label}: real storefront checkout handler completes (exit {$exit} " . trim($noise) . ')');
            $html = (string)($result['output'] ?? '');
            $form = preg_match('~<form method="post" class="checkout-form".*?</form>~s', $html, $m) ? $m[0] : '';
            return [$label, $result ?? ['new_orders' => []], $html, $form];
        };
        $a133Selected = static fn(string $form): string => preg_match('~<option value="([A-Z]{2})" selected>~', $form, $m) ? $m[1] : '';
        $a133Kept = static fn(string $form): bool => str_contains($form, 'value="a133-typed@example.test"') && str_contains($form, 'value="+421900000999"')
            && str_contains($form, 'value="A133 Typed Street 5"') && str_contains($form, 'value="Zilina"') && str_contains($form, '>A133 leave at door</textarea>');

        [$l, , $html, $form] = $a133Do('guest_get');
        preg_match_all('~<option value="([A-Z]{2})"~', (string)(preg_match('~<select name="country".*?</select>~s', $form, $s) ? $s[0] : ''), $a133Codes);
        $a133Codes = $a133Codes[1];
        assert_true(count(array_intersect(['SK', 'CZ', 'AT', 'IT', 'FR', 'SE', 'NO'], $a133Codes)) === 7 && !in_array('IS', $a133Codes, true) && count($a133Codes) > 6,
            "{$l}: countries come from the store's shipping zones (database + shipping.json, here " . count($a133Codes) . '), not the six hardcoded ones; another store\'s zone is not offered');
        assert_true(!str_contains($form, 'name="saved_address"') && str_contains($form, 'name="name" autocomplete="name" value=""') && $a133Selected($form) !== '',
            "{$l}: a guest gets an empty form, no saved addresses and a preselected shipping country (" . $a133Selected($form) . ')');

        [$l, $r, $html, $form] = $a133Do('member_get');
        // Keep the shipping and payment the server offers for CZ so the final order uses real, available methods.
        preg_match('~name="shipping_method" value="([^"]+)" checked~', $form, $a133Ship);
        preg_match('~name="payment" value="([^"]+)"(?: checked)?>~', $form, $a133Pay);
        assert_true(!empty($a133Ship[1]) && !empty($a133Pay[1]), "{$l}: shipping and payment are offered to CZ for the fixture cart");
        assert_true(str_contains($form, 'value="A133 Default Name"') && str_contains($form, 'value="a133-member@example.test"') && str_contains($form, 'value="+421900000133"')
            && str_contains($form, 'value="A133 Default Street 1"') && str_contains($form, 'value="Brno"') && str_contains($form, 'value="60200"') && $a133Selected($form) === 'CZ',
            "{$l}: a signed-in customer's form starts from the profile and the default shipping address (country " . $a133Selected($form) . ')');
        assert_true(preg_match('~<select id="checkout-saved-address" name="saved_address">.*?</select>~s', $form, $a133Select) === 1
            && substr_count($a133Select[0], '<option') === 2 && str_contains($a133Select[0], 'A133 Second Street 2')
            && !str_contains($html, 'A133 Foreign') && !str_contains($html, 'A133 Billing Street')
            && str_contains($form, '<button type="submit" name="address_action" value="use" formnovalidate>'),
            "{$l}: only the customer's own shipping addresses are offered, with a no-JS button; no other customer's address leaks");

        $a133Extra = json_encode(['shipping_method' => $a133Ship[1] ?? '', 'payment' => $a133Pay[1] ?? '']);
        [$l, $r, $html, $form] = $a133Do('member_use_second');
        assert_true($r['new_orders'] === [] && str_contains($form, 'value="A133 Second Name"') && str_contains($form, 'value="A133 Second Street 2"')
            && str_contains($form, 'value="Wien"') && str_contains($form, 'value="1010"') && str_contains($form, 'value="+43100000133"') && $a133Selected($form) === 'AT'
            && str_contains($form, 'value="a133-typed@example.test"') && str_contains($form, '>A133 leave at door</textarea>') && !str_contains($html, 'id="checkout-error"'),
            "{$l}: picking a saved address fills the address fields (country " . $a133Selected($form) . '), keeps the e-mail and note and places no order');

        [$l, $r, $html, $form] = $a133Do('member_use_foreign');
        assert_true($r['new_orders'] === [] && !str_contains($html, 'A133 Foreign') && $a133Kept($form) && $a133Selected($form) === 'CZ',
            "{$l}: another customer's address id is ignored: nothing of it is shown and the typed values stay");

        [$l, $r, $html] = $a133Do('member_use_no_csrf');
        assert_true($r['new_orders'] === [] && array_filter(array_map(static fn(string $f): string => htmlspecialchars((string)((require $f)['checkout_csrf_error'] ?? ''), ENT_QUOTES, 'UTF-8'), glob(dirname(TEST_CLI_DIR) . '/lang/*.php')), static fn(string $m): bool => str_contains($html, $m)) !== [] && !str_contains($html, 'value="A133 Second Street 2"'),
            "{$l}: picking an address without the CSRF token is refused");

        [$l, $r, $html, $form] = $a133Do('guest_refused');
        assert_true($r['new_orders'] === [] && preg_match('~<p id="checkout-error"[^>]*role="alert"[^>]*>[^<]+</p>~', $form) === 1 && $a133Kept($form)
            && $a133Selected($form) === 'CZ' && str_contains($form, 'value="' . ($a133Ship[1] ?? '') . '" checked') && str_contains($form, 'name="csrf_token"'),
            "{$l}: a refused order re-renders the checkout form with the reason and every typed value, shipping included");

        [$l, $r] = $a133Do('guest_place');
        $a133Order = $r['new_orders'][0] ?? [];
        assert_true(count($r['new_orders']) === 1 && ($a133Order['customer_name'] ?? '') === 'A133 Typed' && str_contains((string)($a133Order['shipping_addr'] ?? ''), 'A133 Typed Street 5')
            && str_contains((string)($a133Order['shipping_addr'] ?? ''), 'CZ'), "{$l}: the corrected form places the order with the kept address (" . json_encode($r['new_orders']) . ')');
    }
} finally {
    if (isset($a133Drivers['mysql'])) $a133Run(['mysql', 'teardown', 'none', $a133App, $a133Drivers['mysql'], $a133Root, '{}']);
    $a133Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a133Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a133Tree as $a133Path) $a133Path->isDir() ? @rmdir($a133Path->getPathname()) : @unlink($a133Path->getPathname());
    @rmdir($a133Root);
}
