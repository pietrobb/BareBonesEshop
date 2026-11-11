<?php

// R31 (operator 2026-11-11): the buyer can be invoiced to another address than the parcel is delivered to.
// Through the real storefront handler (index.php?page=checkout): the no-JS form offers "billing address differs" with
// name, street, city, zip and a billing country (any country, not only delivery zones); a missing field or unknown
// country is refused with the typed values kept; the order stores the delivery address in shipping_addr and the
// billing address with the buyer company in billing_addr (NULL when the same, as before); the JSON core accepts a
// billing_address object; a signed-in customer's billing address is saved as a billing address and prefills the next
// form; the invoice is issued to the billing address. SQLite always, MySQL 8 / MariaDB 11.4 via TEST_MYSQL_DSN.
echo "\n--- Audit R31: billing address other than the delivery address ---\n";

$r31bRoot = test_fixture_path('checkout-billing');
mkdir($r31bRoot);
mkdir($r31bRoot . '/sessions');
$r31bFixture = $r31bRoot . '/request.php';
file_put_contents($r31bFixture, <<<'PHP'
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
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'r31bcat', 'Cat', 'r31bcat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'r31bcat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R31B-SKU', 'r31b-product', 'R31B Product', 2000, 50, 'active']);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'R31B-SKU']);
    $db->prepare("INSERT INTO customers (store_id, email, password_hash, name, phone, status) VALUES ('default', ?, ?, ?, ?, 'active')")
        ->execute(['r31b-member@example.test', 'r31b-hash-member', 'R31B Member', '+421900000031']);
    exit(0);
}

$config = is_file($root . '/config.php') ? require AppConfig::path($root) : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
(new ReflectionProperty(InvoiceService::class, 'storageDir'))->setValue(null, $dir . '/invoices');
$stores = Bootstrap::stores();
$stores['stores']['default']['legal']['iban'] = 'SK31 1200 0000 1987 4263 7541';
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.31.2';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R31B-SKU'")->fetchColumn(), null, 1);
$member = $db->query("SELECT id, password_hash FROM customers WHERE email = 'r31b-member@example.test'")->fetch();
if (str_starts_with($action, 'member')) {
    $_SESSION += ['customer_id' => (int)$member['id'], 'customer_last_seen' => time(), 'customer_auth_hash' => hash('sha256', (string)$member['password_hash'])];
}
$orders = static fn(): array => $db->query("SELECT id, order_number, customer_name, shipping_addr, billing_addr FROM orders ORDER BY id")->fetchAll();
$result = static function (array $payload): never { exit("\nR31BRESULT" . json_encode($payload + ['code' => 200, 'new_orders' => []], JSON_INVALID_UTF8_SUBSTITUTE)); };
if ($action === 'invoice') {
    $all = $orders();
    $byName = static fn(string $name): int => (int)(array_values(array_filter($all, static fn(array $o): bool => $o['customer_name'] === $name))[0]['id'] ?? 0);
    $out = [];
    foreach (['billed' => 'R31B Billed', 'same' => 'R31B Same'] as $key => $name) {
        // Invoices are issued only for paid orders (InvoiceService::canIssue).
        $db->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid' WHERE id = ?")->execute([$byName($name)]);
        $invoice = InvoiceService::issue($byName($name));
        $out[$key] = json_decode((string)InvoiceService::byId((int)$invoice['id'])['customer_snapshot'], true);
    }
    // A credit note corrects the invoice: same buyer, billed to the same address.
    $credit = InvoiceService::creditRefund($byName('R31B Billed'), 500, 'r31b-refund-1', 'R31B partial refund');
    $out['credit'] = $credit === null ? null : json_decode((string)InvoiceService::byId((int)$credit['id'])['customer_snapshot'], true);
    $result(['output' => json_encode($out)]);
}
if ($action === 'addresses') {
    $result(['output' => json_encode($db->query("SELECT type, name, company, vat_id, street, city, zip, country FROM customer_addresses ORDER BY id")->fetchAll())]);
}
$csrf = Security::csrfToken();
// One e-mail per action: the per-buyer checkout rate limit (3 per 30 min) must not decide these cases.
$typed = ['name' => 'R31B Buyer', 'email' => 'r31b-' . str_replace('_', '-', $action) . '@example.test', 'phone' => '+421900000031', 'street' => 'Doručovacia 1',
    'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'checkout' => '1', 'csrf_token' => $csrf] + (json_decode($extra, true) ?: []);
$billing = ['billing_different' => '1', 'billing_name' => 'R31B Firma s.r.o. účtáreň', 'billing_street' => 'Fakturačná 9', 'billing_city' => 'Praha',
    'billing_zip' => '11000', 'billing_country' => 'CZ'];
$company = ['company' => 'R31B Firma s.r.o.', 'ico' => '27082440', 'dic' => 'CZ27082440'];
if ($action === 'core_json') {
    $run = CheckoutService::execute(['name' => 'R31B Json', 'billing_address' => ['name' => 'R31B Json Billing', 'street' => 'Main St 1', 'city' => 'Austin', 'zip' => '73301', 'country' => 'US']]
        + array_diff_key($typed, ['checkout' => 1, 'csrf_token' => 1]) + ['payment_method' => $typed['payment'] ?? ''], $config);
    $result(['output' => json_encode($run), 'new_orders' => array_values(array_filter($orders(), static fn(array $o): bool => $o['customer_name'] === 'R31B Json'))]);
}
[$method, $post] = match ($action) {
    'guest_get', 'member_get' => ['GET', []],
    'guest_refresh' => ['POST', ['checkout_refresh' => '1'] + $typed],
    'guest_refresh_billing' => ['POST', ['checkout_refresh' => '1'] + $billing + $typed],
    'guest_billing_missing' => ['POST', ['billing_street' => ''] + $billing + $typed],
    'guest_billing_country' => ['POST', ['billing_country' => 'XX'] + $billing + $typed],
    'guest_unticked' => ['POST', ['billing_different' => '', 'billing_street' => ''] + $billing + $typed],
    'guest_billed' => ['POST', ['name' => 'R31B Billed'] + $company + $billing + $typed],
    'guest_same' => ['POST', ['name' => 'R31B Same'] + $company + $typed],
    'member_place' => ['POST', ['name' => 'R31B Member', 'save_address' => '1'] + $company + $billing + $typed],
};
$_SERVER['REQUEST_METHOD'] = $method;
$_GET = ['page' => 'checkout'];
$_POST = $post;
http_response_code(200);
$before = count($orders());
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR31BRESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$r31bRun = static function(array $args) use ($r31bFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r31bFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R31BRESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$r31bDrivers = ['sqlite' => $r31bRoot . '/checkout.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r31bDrivers['mysql'] = 'bbe_r31b_billing_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit R31 native billing address (set TEST_MYSQL_DSN)\n";
$r31bApp = dirname(TEST_CLI_DIR);
try {
    foreach ($r31bDrivers as $r31bDriver => $r31bTarget) {
        [$r31bExit, , $r31bNoise] = $r31bRun([$r31bDriver, 'setup', 'none', $r31bApp, $r31bTarget, $r31bRoot, '{}']);
        assert_equals([0, ''], [$r31bExit, trim($r31bNoise)], "R31 billing {$r31bDriver}: migrated fixture with a product and a customer");
        $r31bExtra = '{}';
        $r31bDo = static function(string $action) use (&$r31bExtra, $r31bRun, $r31bDriver, $r31bApp, $r31bTarget, $r31bRoot): array {
            [$exit, $result, $noise] = $r31bRun([$r31bDriver, 'run', $action, $r31bApp, $r31bTarget, $r31bRoot, $r31bExtra]);
            $label = "R31 billing {$r31bDriver}/{$action}";
            assert_true($exit === 0 && $result !== null && !str_contains((string)$result['output'], 'Fatal error') && !str_contains((string)$result['output'], 'Uncaught'),
                "{$label}: real handler completes (exit {$exit} " . trim($noise) . ')');
            $html = (string)($result['output'] ?? '');
            $form = preg_match('~<form method="post" class="checkout-form".*?</form>~s', $html, $m) ? $m[0] : '';
            return [$label, $result ?? ['new_orders' => []], $html, $form];
        };
        $r31bValue = static fn(string $form, string $name): ?string => preg_match('~<input id="[^"]+" name="' . $name . '"[^>]* value="([^"]*)"~', $form, $m) ? html_entity_decode($m[1]) : null;
        $r31bSelected = static fn(string $form, string $name): ?string => preg_match('~<select name="' . $name . '".*?</select>~s', $form, $s) && preg_match('~<option value="([A-Z]{2})" selected~', $s[0], $m) ? $m[1] : null;
        $r31bOptions = static fn(string $form, string $name): array => preg_match('~<select name="' . $name . '".*?</select>~s', $form, $s) && preg_match_all('~<option value="([A-Z]{2})"~', $s[0], $m) ? $m[1] : [];
        $r31bTicked = static fn(string $form): bool => (bool)preg_match('~name="billing_different" value="1" checked~', $form);
        $r31bError = static fn(string $form): string => preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', $form, $m) ? html_entity_decode($m[1]) : '';

        [$l, , , $form] = $r31bDo('guest_get');
        assert_true(str_contains($form, '<h3 style="margin-top:20px">Doručovacia adresa</h3>') && str_contains($form, 'Fakturačná adresa je iná ako doručovacia')
            && !$r31bTicked($form) && $r31bValue($form, 'billing_street') === '' && str_contains($form, 'autocomplete="billing street-address"'),
            "{$l}: the no-JS form names the delivery address and offers an unticked 'billing address differs' with its own fields");
        $r31bBillingCountries = $r31bOptions($form, 'billing_country');
        assert_true(in_array('US', $r31bBillingCountries, true) && !in_array('US', $r31bOptions($form, 'country'), true) && $r31bSelected($form, 'billing_country') === 'SK',
            "{$l}: the billing country list is not limited to the delivery countries and starts at the delivery country (" . implode(',', $r31bBillingCountries) . ')');

        [$l, $r, , $form] = $r31bDo('guest_refresh');
        preg_match('~name="shipping_method" value="([^"]+)" checked~', $form, $r31bShip);
        preg_match('~name="payment" value="([^"]+)"(?: checked)?(?: data-online)?>~', $form, $r31bPay);
        assert_true($r['new_orders'] === [] && !empty($r31bShip[1]) && !empty($r31bPay[1]), "{$l}: shipping and payment are offered to SK for the fixture cart");
        $r31bExtra = json_encode(['shipping_method' => $r31bShip[1] ?? '', 'payment' => $r31bPay[1] ?? '']);

        [$l, $r, , $form] = $r31bDo('guest_refresh_billing');
        assert_true($r['new_orders'] === [] && $r31bTicked($form) && $r31bValue($form, 'billing_city') === 'Praha' && $r31bSelected($form, 'billing_country') === 'CZ'
            && $r31bSelected($form, 'country') === 'SK', "{$l}: a refresh keeps the ticked billing address and its country apart from the delivery country");

        [$l, $r, , $form] = $r31bDo('guest_billing_missing');
        assert_true($r['new_orders'] === [] && $r31bError($form) === 'Vyplňte fakturačnú adresu: meno, ulicu, mesto a PSČ.' && $r31bTicked($form)
            && $r31bValue($form, 'billing_city') === 'Praha', "{$l}: a billing address without a street is refused (" . $r31bError($form) . '); the typed values are kept');

        [$l, $r, , $form] = $r31bDo('guest_billing_country');
        assert_true($r['new_orders'] === [] && $r31bError($form) === 'Krajina fakturačnej adresy nie je platná.', "{$l}: an unknown billing country is refused (" . $r31bError($form) . ')');

        [$l, $r] = $r31bDo('guest_unticked');
        assert_true(count($r['new_orders']) === 1 && $r['new_orders'][0]['billing_addr'] === null,
            "{$l}: billing fields left behind with the box unticked are ignored; the order is billed to the delivery address (billing_addr NULL)");

        [$l, $r] = $r31bDo('guest_billed');
        $r31bShipping = json_decode((string)($r['new_orders'][0]['shipping_addr'] ?? ''), true) ?: [];
        $r31bBilling = json_decode((string)($r['new_orders'][0]['billing_addr'] ?? ''), true) ?: [];
        assert_equals(['street' => 'Doručovacia 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'], $r31bShipping,
            "{$l}: shipping_addr holds the delivery address only");
        assert_equals(['name' => 'R31B Firma s.r.o. účtáreň', 'street' => 'Fakturačná 9', 'city' => 'Praha', 'zip' => '11000', 'country' => 'CZ',
            'company' => 'R31B Firma s.r.o.', 'ico' => '27082440', 'dic' => 'CZ27082440'], $r31bBilling,
            "{$l}: billing_addr holds the billing address with the buyer company and its IDs");

        [$l, $r] = $r31bDo('guest_same');
        $r31bShipping = json_decode((string)($r['new_orders'][0]['shipping_addr'] ?? ''), true) ?: [];
        assert_true(count($r['new_orders']) === 1 && $r['new_orders'][0]['billing_addr'] === null && ($r31bShipping['company'] ?? '') === 'R31B Firma s.r.o.',
            "{$l}: without a billing address the order is stored as before (company on the delivery address, billing_addr NULL)");

        [$l, $r] = $r31bDo('core_json');
        $r31bCore = json_decode((string)$r['output'], true) ?: [];
        $r31bBilling = json_decode((string)($r['new_orders'][0]['billing_addr'] ?? ''), true) ?: [];
        assert_true(!empty($r31bCore['success']) && ($r31bBilling['country'] ?? '') === 'US' && ($r31bBilling['name'] ?? '') === 'R31B Json Billing',
            "{$l}: the JSON checkout core accepts a billing_address object (" . json_encode($r31bCore['error'] ?? null) . ')');

        [$l, $r] = $r31bDo('member_place');
        assert_true(count($r['new_orders']) === 1, "{$l}: a signed-in customer places an order billed elsewhere");
        [$l, $r] = $r31bDo('addresses');
        $r31bBook = json_decode((string)$r['output'], true) ?: [];
        $r31bTypes = array_column($r31bBook, null, 'type');
        assert_true(count($r31bBook) === 2 && ($r31bTypes['shipping']['city'] ?? '') === 'Bratislava' && ($r31bTypes['shipping']['company'] ?? '') === ''
            && ($r31bTypes['billing']['city'] ?? '') === 'Praha' && strtoupper((string)($r31bTypes['billing']['country'] ?? '')) === 'CZ'
            && ($r31bTypes['billing']['company'] ?? '') === 'R31B Firma s.r.o.', "{$l}: 'save address' keeps the delivery and the billing address apart (" . json_encode($r31bBook) . ')');

        [$l, , , $form] = $r31bDo('member_get');
        assert_true($r31bTicked($form) && $r31bValue($form, 'billing_street') === 'Fakturačná 9' && $r31bSelected($form, 'billing_country') === 'CZ'
            && $r31bValue($form, 'street') === 'Doručovacia 1' && $r31bValue($form, 'company') === 'R31B Firma s.r.o.' && str_contains($form, 'name="saved_billing_address"'),
            "{$l}: the next form starts with the saved delivery and billing addresses");

        [$l, $r] = $r31bDo('invoice');
        $r31bInvoice = json_decode((string)$r['output'], true) ?: [];
        $r31bBilled = $r31bInvoice['billed'] ?? [];
        assert_true(($r31bBilled['name'] ?? '') === 'R31B Firma s.r.o. účtáreň' && ($r31bBilled['street'] ?? '') === 'Fakturačná 9' && ($r31bBilled['country'] ?? '') === 'CZ'
            && ($r31bBilled['company'] ?? '') === 'R31B Firma s.r.o.' && ($r31bBilled['ico'] ?? '') === '27082440' && ($r31bBilled['email'] ?? '') === 'r31b-guest-billed@example.test',
            "{$l}: the invoice is issued to the billing address and company (" . json_encode($r31bBilled) . ')');
        assert_equals(['name' => 'R31B Billed', 'street' => 'Doručovacia 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'], $r31bBilled['delivery'] ?? null,
            "{$l}: the invoice also names the delivery address the goods went to");
        assert_true(is_array($r31bInvoice['credit'] ?? null) && $r31bInvoice['credit'] === $r31bBilled,
            "{$l}: a credit note of the invoice is issued to the same billing address (" . json_encode($r31bInvoice['credit'] ?? null) . ')');
        $r31bSame = $r31bInvoice['same'] ?? [];
        assert_true(($r31bSame['name'] ?? '') === 'R31B Same' && ($r31bSame['street'] ?? '') === 'Doručovacia 1' && ($r31bSame['company'] ?? '') === 'R31B Firma s.r.o.'
            && !array_key_exists('delivery', $r31bSame),
            "{$l}: an order without a billing address is invoiced to the delivery address as before (" . json_encode($r31bSame) . ')');
    }
} finally {
    if (isset($r31bDrivers['mysql'])) $r31bRun(['mysql', 'teardown', 'none', $r31bApp, $r31bDrivers['mysql'], $r31bRoot, '{}']);
    $r31bTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r31bRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($r31bTree as $r31bPath) $r31bPath->isDir() ? @rmdir($r31bPath->getPathname()) : @unlink($r31bPath->getPathname());
    @rmdir($r31bRoot);
}
