<?php

// A13.5 / company buyer details through the real storefront handler (index.php?page=checkout): the no-JS form offers
// company name, IČO, DIČ and IČ DPH (prefilled for a B2B member from the company); the shared checkout core (HTML and
// JSON API) refuses an ID without a company name or with an invalid format (form kept), normalises the IDs and stores
// them on the order address, which the invoice snapshot and the customer's order detail read.
// SQLite always, MySQL 8 / MariaDB 11.4 via TEST_MYSQL_DSN.
echo "\n--- Audit A13.5: checkout company buyer (IČO / DIČ / IČ DPH) onto the order and invoice ---\n";

$a135Root = test_fixture_path('checkout-company');
mkdir($a135Root);
mkdir($a135Root . '/sessions');
$a135Fixture = $a135Root . '/request.php';
file_put_contents($a135Fixture, <<<'PHP'
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
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'a135cat', 'Cat', 'a135cat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'a135cat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'A135-SKU', 'a135-product', 'A135 Product', 2000, 50, 'active']);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'A135-SKU']);
    $db->prepare("INSERT INTO companies (store_id, code, legal_name, registration_number, vat_id, vat_country, approval_status) VALUES ('default', 'a135co', 'A135 Firma GmbH', 'HRB 135', 'DE811907980', 'DE', 'approved')")->execute();
    $company = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO customers (store_id, email, password_hash, name, phone, status, company_id) VALUES ('default', ?, ?, ?, ?, 'active', ?)")
        ->execute(['a135-member@example.test', 'a135-hash-member', 'A135 Member', '+421900000135', $company]);
    // A known-valid VAT ID in the VIES cache: no live VIES call from the test.
    $db->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, trader_addr, checked_at, ttl_seconds, raw_response) VALUES ('DE811907980', 'DE', 'valid', NULL, NULL, ?, 86400, NULL)")
        ->execute([gmdate('Y-m-d H:i:s')]);
    exit(0);
}

$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
(new ReflectionProperty(InvoiceService::class, 'storageDir'))->setValue(null, $dir . '/invoices');
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.13.5';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'A135-SKU'")->fetchColumn(), null, 1);
if (str_starts_with($action, 'member')) {
    $member = $db->query("SELECT id, password_hash FROM customers WHERE email = 'a135-member@example.test'")->fetch();
    $_SESSION += ['customer_id' => (int)$member['id'], 'customer_last_seen' => time(), 'customer_auth_hash' => hash('sha256', (string)$member['password_hash'])];
}
$orders = static fn(): array => $db->query("SELECT id, order_number, shipping_addr, vat_scenario FROM orders ORDER BY id")->fetchAll();
$result = static function (array $payload): never { exit("\nA135RESULT" . json_encode($payload + ['code' => 200, 'new_orders' => []], JSON_INVALID_UTF8_SUBSTITUTE)); };
if ($action === 'invoice') {
    // Invoices are issued only for paid orders (InvoiceService::canIssue).
    $db->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid' WHERE id = ?")->execute([(int)($orders()[0]['id'] ?? 0)]);
    $invoice = InvoiceService::issue((int)($orders()[0]['id'] ?? 0));
    $row = InvoiceService::byId((int)$invoice['id']);
    $result(['output' => json_encode(['customer' => json_decode((string)$row['customer_snapshot'], true), 'seller' => json_decode((string)$row['seller_snapshot'], true)])]);
}
$csrf = Security::csrfToken();
// One e-mail per action: the per-buyer checkout rate limit (3 per 30 min) must not decide these cases.
$typed = ['name' => 'A135 Buyer', 'email' => 'a135-' . str_replace('_', '-', $action) . '@example.test', 'phone' => '+421900000135', 'street' => 'A135 Strasse 5',
    'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE', 'checkout' => '1', 'csrf_token' => $csrf] + (json_decode($extra, true) ?: []);
if ($action === 'core_bad_dic') {
    $result(['output' => json_encode(CheckoutService::execute($typed + ['company' => 'A135 Core s.r.o.', 'dic' => '2020#123'], $config))]);
}
if ($action === 'member_account') {
    $id = (int)($orders()[0]['id'] ?? 0);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/account.php?p=order&id=' . $id;
    $_GET = ['p' => 'order', 'id' => (string)$id];
    $_POST = [];
}
$company = ['company' => 'A135 Firma GmbH', 'ico' => ' hrb 135 ', 'dic' => '30/123/45678', 'vat_id' => 'de 811.907-980'];
[$method, $post] = match ($action) {
    'guest_get', 'member_get', 'member_account' => ['GET', []],
    'guest_refresh' => ['POST', ['address_action' => 'refresh'] + $typed],
    'guest_no_name' => ['POST', ['ico' => '36123456'] + $typed],
    'guest_bad_vat' => ['POST', ['company' => 'A135 Bad s.r.o.', 'vat_id' => 'DE-12'] + $typed],
    'member_place' => ['POST', $company + $typed],
};
$_SERVER['REQUEST_METHOD'] = $method;
if ($action !== 'member_account') {
    $_GET = ['page' => 'checkout'];
    $_POST = $post;
}
http_response_code(200);
$before = count($orders());
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nA135RESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . ($action === 'member_account' ? '/account.php' : '/index.php');
PHP);
$a135Run = static function(array $args) use ($a135Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a135Fixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'A135RESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$a135Drivers = ['sqlite' => $a135Root . '/checkout.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a135Drivers['mysql'] = 'bbe_a135_company_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A13.5 native checkout company buyer (set TEST_MYSQL_DSN)\n";
$a135App = dirname(TEST_CLI_DIR);
try {
    foreach ($a135Drivers as $a135Driver => $a135Target) {
        [$a135Exit, , $a135Noise] = $a135Run([$a135Driver, 'setup', 'none', $a135App, $a135Target, $a135Root, '{}']);
        assert_equals([0, ''], [$a135Exit, trim($a135Noise)], "A13.5 {$a135Driver}: migrated fixture with a product, a B2B company and its member");
        $a135Extra = '{}';
        $a135Do = static function(string $action) use (&$a135Extra, $a135Run, $a135Driver, $a135App, $a135Target, $a135Root): array {
            [$exit, $result, $noise] = $a135Run([$a135Driver, 'run', $action, $a135App, $a135Target, $a135Root, $a135Extra]);
            $label = "A13.5 {$a135Driver}/{$action}";
            assert_true($exit === 0 && $result !== null && !str_contains((string)$result['output'], 'Fatal error') && !str_contains((string)$result['output'], 'Uncaught'),
                "{$label}: real handler completes (exit {$exit} " . trim($noise) . ')');
            $html = (string)($result['output'] ?? '');
            $form = preg_match('~<form method="post" class="checkout-form".*?</form>~s', $html, $m) ? $m[0] : '';
            return [$label, $result ?? ['new_orders' => []], $html, $form];
        };
        $a135Value = static fn(string $form, string $name): ?string => preg_match('~<input id="[^"]+" name="' . $name . '"[^>]* value="([^"]*)"~', $form, $m) ? html_entity_decode($m[1]) : null;
        $a135Error = static fn(string $form): string => preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', $form, $m) ? html_entity_decode($m[1]) : '';

        [$l, , , $form] = $a135Do('guest_get');
        assert_true(array_map(static fn(string $n): ?string => $a135Value($form, $n), ['company', 'ico', 'dic', 'vat_id']) === ['', '', '', '']
            && str_contains($form, '<legend><h3 style="margin:0">Nákup na firmu (nepovinné)</h3></legend>') && str_contains($form, '<label for="checkout-ico">IČO</label>')
            && str_contains($form, '<label for="checkout-vat-id">IČ DPH</label>'), "{$l}: the no-JS form offers optional company name, IČO, DIČ and IČ DPH");

        [$l, , , $form] = $a135Do('member_get');
        assert_true($a135Value($form, 'company') === 'A135 Firma GmbH' && $a135Value($form, 'ico') === 'HRB 135' && $a135Value($form, 'vat_id') === 'DE811907980',
            "{$l}: a B2B member's form starts with the company's legal name, registration number and VAT ID");

        [$l, $r, , $form] = $a135Do('guest_refresh');
        preg_match('~name="shipping_method" value="([^"]+)" checked~', $form, $a135Ship);
        preg_match('~name="payment" value="([^"]+)"(?: checked)?(?: data-online)?>~', $form, $a135Pay);
        assert_true($r['new_orders'] === [] && !empty($a135Ship[1]) && !empty($a135Pay[1]), "{$l}: shipping and payment are offered to DE for the fixture cart");
        $a135Extra = json_encode(['shipping_method' => $a135Ship[1] ?? '', 'payment' => $a135Pay[1] ?? '']);

        [$l, $r, , $form] = $a135Do('guest_no_name');
        assert_true($r['new_orders'] === [] && $a135Error($form) === 'Pri nákupe na firmu vyplňte názov firmy.' && $a135Value($form, 'ico') === '36123456'
            && $a135Value($form, 'street') === 'A135 Strasse 5', "{$l}: an IČO without a company name is refused (" . $a135Error($form) . '); the typed values are kept');

        [$l, $r, , $form] = $a135Do('guest_bad_vat');
        assert_true($r['new_orders'] === [] && $a135Error($form) === 'IČ DPH má neplatný tvar.' && $a135Value($form, 'company') === 'A135 Bad s.r.o.',
            "{$l}: an IČ DPH of invalid format is refused (" . $a135Error($form) . ')');

        [$l, $r] = $a135Do('core_bad_dic');
        $a135Core = json_decode((string)$r['output'], true) ?: [];
        assert_true(empty($a135Core['success']) && ($a135Core['error'] ?? '') === 'DIČ má neplatný tvar.',
            "{$l}: the shared checkout core (HTML + JSON API) refuses a DIČ with an invalid character (" . ($a135Core['error'] ?? '') . ')');

        [$l, $r] = $a135Do('member_place');
        $a135Address = json_decode((string)($r['new_orders'][0]['shipping_addr'] ?? ''), true) ?: [];
        assert_true(count($r['new_orders']) === 1 && array_intersect_key($a135Address, array_flip(['company', 'ico', 'dic', 'ic_dph']))
            === ['company' => 'A135 Firma GmbH', 'ico' => 'HRB135', 'dic' => '30/123/45678', 'ic_dph' => 'DE811907980'],
            "{$l}: the order address keeps the company and the normalised IČO / DIČ / IČ DPH (" . json_encode($a135Address) . ')');
        assert_equals('reverse_charge', (string)($r['new_orders'][0]['vat_scenario'] ?? ''), "{$l}: a valid EU VAT ID of another member state makes the order reverse charge");

        [$l, $r] = $a135Do('invoice');
        $a135Invoice = json_decode((string)$r['output'], true) ?: [];
        $a135Customer = $a135Invoice['customer'] ?? [];
        assert_true(($a135Customer['company'] ?? '') === 'A135 Firma GmbH' && ($a135Customer['ico'] ?? '') === 'HRB135' && ($a135Customer['dic'] ?? '') === '30/123/45678'
            && ($a135Customer['ic_dph'] ?? '') === 'DE811907980' && InvoiceService::isReverseCharge($a135Invoice['seller'] ?? [], $a135Customer),
            "{$l}: the invoice is issued to the company with its IDs and qualifies as reverse charge (" . json_encode($a135Customer) . ')');

        [$l, , $html] = $a135Do('member_account');
        assert_true(str_contains($html, 'A135 Firma GmbH<br>') && str_contains($html, 'IČO: HRB135<br>') && str_contains($html, 'DIČ: 30/123/45678<br>')
            && str_contains($html, 'IČ DPH: DE811907980<br>'), "{$l}: the customer's order detail shows the company and its IDs");
    }
} finally {
    if (isset($a135Drivers['mysql'])) $a135Run(['mysql', 'teardown', 'none', $a135App, $a135Drivers['mysql'], $a135Root, '{}']);
    $a135Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a135Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a135Tree as $a135Path) $a135Path->isDir() ? @rmdir($a135Path->getPathname()) : @unlink($a135Path->getPathname());
    @rmdir($a135Root);
}
