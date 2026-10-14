<?php

// A13.6 / bank transfer instructions with a PAY by square QR code. An order paid by bank transfer must tell the
// customer where and how to pay: the store's IBAN/BIC, the exact amount, the variable symbol the merchant matches
// the transfer by (order ID) and a PAY by square QR code — on the order-done page (real index.php handler), in the
// customer's order detail, in the JSON checkout response and in the order confirmation e-mail. Paid, COD or
// cancelled orders get no instructions. SQLite always, MySQL 8 / MariaDB 11.4 via TEST_MYSQL_DSN.
echo "\n--- Audit A13.6: bank transfer instructions and PAY by square QR ---\n";

// Golden vector: decoded by liblzma (Python lzma, FORMAT_ALONE with the rebuilt 13-byte header, CRC32 matched) into
// the tab-separated payload below, and its QR matrix read back by zxing-cpp (C:/Users/Public/a136_*_verify.py).
$a136Vector = PayBySquare::encode(['iban' => 'SK96 1100 0000 0029 1859 9669', 'bic' => 'TATRSKBX', 'amount' => 12345, 'currency' => 'eur',
    'variable_symbol' => '4321', 'due_date' => '20261027', 'note' => 'Objednávka ORD-20261013-ABC123', 'beneficiary' => 'Woodle s.r.o. Žilina']);
assert_equals('04084000DED4UGR09C500SN5AVVQ3LS4CUPS0VOIUMJM841AG04DM98HQMC6GAR2UJIN99CJ0NBRNV4LLARA498K1N0MRV6LOGCBL9E090FB95B3D8JE0SVR6Q53E74NRGJ9OO4A4HTEM8U9I9NEMRA3A0UTBNTUIT5HLR9D1BU91VMQVHL77TFV40508P6D3HM5PS20LARG',
    $a136Vector, 'A13.6 PAY by square: header 1.1.0, CRC32 + payload "\t1\t1\t123.45\tEUR\t20261027\t4321\t\t\t\tObjednavka ORD-20261013-ABC123\t1\tSK9611000000002918599669\tTATRSKBX\t0\t0\tWoodle s.r.o. Zilina\t\t", LZMA1 lc3/lp0/pb2, base32hex');
$a136Matrix = QrCode::matrix($a136Vector);
assert_equals([49, '224ebd717abe2001c7e2d99bab45471caf1cac63'], [count($a136Matrix), sha1(json_encode($a136Matrix))],
    'A13.6 QR: the PAY by square text encodes as the version 8-M symbol that zxing-cpp reads back');
$a136Unit = ['id' => 77, 'order_number' => 'ORD-A136-CZK', 'total' => 150000, 'currency' => 'czk', 'payment_method' => 'bank_transfer',
    'payment_method_snapshot' => '', 'payment_status' => 'pending', 'status' => 'pending', 'created_at' => '2026-10-13 10:00:00'];
$a136Czk = BankTransferInstructions::forOrder($a136Unit, ['name' => 'A136 CZ s.r.o.', 'iban' => 'CZ65 0800 0000 1920 0014 5399', 'bic' => 'GIBACZPX']);
assert_true(($a136Czk['currency'] ?? '') === 'CZK' && ($a136Czk['iban'] ?? '') === 'CZ6508000000192000145399' && ($a136Czk['variable_symbol'] ?? '') === '77'
    && ($a136Czk['pay_by_square'] ?? '-') === '' && ($a136Czk['qr_svg'] ?? '-') === '', 'A13.6: a CZK order gets text instructions but no PAY by square QR (EUR-only standard)');
assert_true(BankTransferInstructions::forOrder($a136Unit, ['iban' => 'CZ66 0800 0000 1920 0014 5399']) === null
    && BankTransferInstructions::forOrder(['payment_method' => 'cod'] + $a136Unit, ['iban' => 'CZ65 0800 0000 1920 0014 5399']) === null,
    'A13.6: no instructions for an IBAN with a wrong checksum or a non-transfer payment');

$a136Root = test_fixture_path('checkout-bank-transfer');
mkdir($a136Root);
mkdir($a136Root . '/sessions');
$a136Fixture = $a136Root . '/request.php';
file_put_contents($a136Fixture, <<<'PHP'
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
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'a136cat', 'Cat', 'a136cat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'a136cat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'A136-SKU', 'a136-product', 'A136 Product', 2000, 50, 'active']);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'A136-SKU']);
    $db->prepare("INSERT INTO customers (store_id, email, password_hash, name, phone, status) VALUES ('default', ?, ?, ?, ?, 'active')")
        ->execute(['a136-member@example.test', 'a136-hash-member', 'A136 Member', '+421900000136']);
    exit(0);
}
JobQueue::useBackend(new DatabaseQueue($db, 30));

$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
// The shipped stores.json carries placeholder (checksum-invalid) IBANs; this store gets a real-format account.
$stores = Bootstrap::stores();
$stores['stores']['default']['legal'] = ['name' => 'A136 Shop s.r.o.', 'iban' => 'SK31 1200 0000 1987 4263 7541', 'bic' => 'SUBASKBX', 'bank' => 'A136 Banka']
    + (array)($stores['stores']['default']['legal'] ?? []);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.13.6';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
$input = json_decode($extra, true) ?: [];
$orders = static fn(): array => $db->query("SELECT id, order_number, total, currency, payment_method, payment_status, status FROM orders ORDER BY id")->fetchAll();
$result = static function (array $payload): never { exit("\nA136RESULT" . json_encode($payload + ['code' => 200, 'new_orders' => []], JSON_INVALID_UTF8_SUBSTITUTE)); };
if (str_starts_with($action, 'member')) {
    $member = $db->query("SELECT id, password_hash FROM customers WHERE email = 'a136-member@example.test'")->fetch();
    $_SESSION += ['customer_id' => (int)$member['id'], 'customer_last_seen' => time(), 'customer_auth_hash' => hash('sha256', (string)$member['password_hash'])];
}
if ($action === 'mail') {
    // The confirmation job the real checkout queued, delivered by the real worker (one --once pass, then its handler).
    $a136Job = null;
    foreach (JobQueue::pull(100) as $a136Queued) {
        if (($a136Queued['type'] ?? '') === 'email_send' && ($a136Queued['payload']['template'] ?? '') === 'order_confirmation'
            && (int)($a136Queued['payload']['order_id'] ?? 0) === (int)$input['order_id']) $a136Job = $a136Queued;
    }
    MailService::useAdapter(new LogMail(['log_file' => $dir . '/mail.log']));
    $argv = ['worker.php', '--once'];
    require_once $root . '/cli/worker.php';
    LogMail::clearCaptured();
    if ($a136Job) handleEmailSend($a136Job['payload'], $a136Job);
    $result(['output' => $a136Job ? (string)(LogMail::lastCaptured()['html'] ?? '') : 'no queued order_confirmation job']);
}
if (!in_array($action, ['done', 'done_paid', 'member_account'], true)) {
    Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'A136-SKU'")->fetchColumn(), null, 1);
}
$csrf = Security::csrfToken();
// One e-mail per action: the per-buyer checkout rate limit (3 per 30 min) must not decide these cases.
$typed = ['name' => 'A136 Buyer', 'email' => 'a136-' . str_replace('_', '-', $action) . '@example.test', 'phone' => '+421900000136', 'street' => 'A136 Ulica 6',
    'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'checkout' => '1', 'csrf_token' => $csrf];
if ($action === 'api') {
    $run = CheckoutService::execute($typed + ['shipping_method' => $input['shipping_method'], 'payment_method' => 'bank_transfer'], $config);
    $result(['output' => json_encode($run), 'new_orders' => $orders()]);
}
if (in_array($action, ['done', 'done_paid'], true)) {
    $order = Orders::byId((int)$input['order_id']);
    if ($action === 'done_paid') OfflinePaymentQueue::confirm((int)$order['id'], 'a136-test', 'statement 1');
    $_GET = ['page' => 'order-done', 'order' => (string)$order['order_number'],
        'confirmation_token' => CheckoutSession::createConfirmation((int)$order['id'], CheckoutSession::browserSessionId())];
    $_SERVER['REQUEST_URI'] = '/index.php?page=order-done';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
} elseif ($action === 'member_account') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/account.php?p=order&id=' . (int)$input['order_id'];
    $_GET = ['p' => 'order', 'id' => (string)(int)$input['order_id']];
    $_POST = [];
} else {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['page' => 'checkout'];
    $_POST = $action === 'refresh' ? ['address_action' => 'refresh'] + $typed : $typed + $input;
}
http_response_code(200);
$before = count($orders());
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nA136RESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . ($action === 'member_account' ? '/account.php' : '/index.php');
PHP);
$a136Run = static function(array $args) use ($a136Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a136Fixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'A136RESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$a136Drivers = ['sqlite' => $a136Root . '/checkout.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a136Drivers['mysql'] = 'bbe_a136_transfer_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A13.6 native bank transfer instructions (set TEST_MYSQL_DSN)\n";
$a136App = dirname(TEST_CLI_DIR);
try {
    foreach ($a136Drivers as $a136Driver => $a136Target) {
        [$a136Exit, , $a136Noise] = $a136Run([$a136Driver, 'setup', 'none', $a136App, $a136Target, $a136Root, '{}']);
        assert_equals([0, ''], [$a136Exit, trim($a136Noise)], "A13.6 {$a136Driver}: migrated fixture with a product and a customer");
        $a136Do = static function(string $action, array $input = []) use ($a136Run, $a136Driver, $a136App, $a136Target, $a136Root): array {
            [$exit, $result, $noise] = $a136Run([$a136Driver, 'run', $action, $a136App, $a136Target, $a136Root, json_encode($input)]);
            $label = "A13.6 {$a136Driver}/{$action}";
            assert_true($exit === 0 && $result !== null && !str_contains((string)$result['output'], 'Fatal error') && !str_contains((string)$result['output'], 'Uncaught'),
                "{$label}: real handler completes (exit {$exit} " . trim($noise) . ')');
            return [$label, $result ?? ['new_orders' => [], 'output' => ''], (string)($result['output'] ?? '')];
        };
        $a136Block = static fn(string $html): string => preg_match('~<section class="bank-transfer".*?</section>~s', $html, $m) ? $m[0] : '';

        [$l, , $html] = $a136Do('refresh');
        preg_match('~name="shipping_method" value="([^"]+)" checked~', $html, $a136Ship);
        preg_match_all('~name="(shipping_method|payment)" value="([^"]+)"~', $html, $a136Options);
        assert_true(!empty($a136Ship[1]) && str_contains($html, 'name="payment" value="bank_transfer"') && str_contains($html, 'name="shipping_method" value="gls"'),
            "{$l}: shipping (incl. GLS, which allows cash on delivery) and bank transfer are offered to SK for the fixture cart (" . implode(',', $a136Options[2]) . ')');
        $a136Shipping = ['shipping_method' => $a136Ship[1] ?? ''];

        [$l, $r] = $a136Do('place_transfer', $a136Shipping + ['payment' => 'bank_transfer']);
        $a136Order = $r['new_orders'][0] ?? [];
        assert_true(count($r['new_orders']) === 1 && ($a136Order['payment_status'] ?? '') === 'pending', "{$l}: the no-JS checkout creates one unpaid bank-transfer order");
        $a136Id = (int)($a136Order['id'] ?? 0);
        $a136Amount = I18n::price((int)($a136Order['total'] ?? 0), (string)($a136Order['currency'] ?? 'eur'));
        $a136Due = gmdate('Y-m-d', time() + PendingOrderExpiry::DEFAULT_OFFLINE_SECONDS['bank_transfer']);

        [$l, , $html] = $a136Do('done', ['order_id' => $a136Id]);
        $a136Html = $a136Block($html);
        assert_true(str_contains($a136Html, 'Platba bankovým prevodom') && str_contains($a136Html, '<strong data-bank-iban>SK31 1200 0000 1987 4263 7541</strong> (A136 Banka)')
            && str_contains($a136Html, 'SUBASKBX') && str_contains($a136Html, 'A136 Shop s.r.o.')
            && str_contains($a136Html, '<strong data-bank-amount>' . htmlspecialchars($a136Amount) . '</strong>')
            && str_contains($a136Html, '<strong data-bank-vs>' . $a136Id . '</strong>') && str_contains($a136Html, (string)$a136Order['order_number'])
            && str_contains($a136Html, $a136Due), "{$l}: order-done shows beneficiary, IBAN, BIC, exact amount, variable symbol = order ID, message and due date");
        assert_true(preg_match('~<svg [^>]*role="img" aria-label="QR kód PAY by square pre platbu objednávky"><rect [^>]*/><path d="(M\d+,\d+h1v1h-1z)+" fill="#000"/></svg>~', $a136Html) === 1,
            "{$l}: order-done embeds the PAY by square QR as an inline, labelled SVG (no external image, CSP-safe)");

        [$l, $r] = $a136Do('api', $a136Shipping);
        $a136Api = json_decode((string)$r['output'], true) ?: [];
        $a136Bank = $a136Api['bank_transfer'] ?? [];
        $a136ApiOrder = end($r['new_orders']) ?: [];
        assert_true(!empty($a136Api['success']) && ($a136Bank['iban'] ?? '') === 'SK3112000000198742637541' && ($a136Bank['bic'] ?? '') === 'SUBASKBX'
            && ($a136Bank['amount'] ?? null) === (int)($a136ApiOrder['total'] ?? -1) && ($a136Bank['currency'] ?? '') === 'EUR'
            && ($a136Bank['variable_symbol'] ?? '') === (string)($a136Api['order_id'] ?? '') && ($a136Bank['message'] ?? '') === ($a136Api['order_number'] ?? '-')
            && !isset($a136Bank['qr_svg']), "{$l}: the JSON checkout response carries the same instructions (" . json_encode($a136Bank) . ')');
        $a136ApiPbs = PayBySquare::encode(['iban' => 'SK3112000000198742637541', 'bic' => 'SUBASKBX', 'amount' => (int)($a136ApiOrder['total'] ?? 0),
            'currency' => 'eur', 'variable_symbol' => (string)($a136Api['order_id'] ?? ''), 'due_date' => str_replace('-', '', (string)($a136Bank['due_date'] ?? '')),
            'note' => (string)($a136Api['order_number'] ?? ''), 'beneficiary' => 'A136 Shop s.r.o.']);
        assert_equals($a136ApiPbs, (string)($a136Bank['pay_by_square'] ?? ''), "{$l}: the API's PAY by square text encodes exactly the shown payment");

        [$l, , $html] = $a136Do('mail', ['order_id' => $a136Id]);
        assert_true(str_contains($html, '<h3>Platba bankovým prevodom</h3>') && str_contains($html, '<strong>SK31 1200 0000 1987 4263 7541</strong>')
            && str_contains($html, '<strong>' . $a136Id . '</strong>') && str_contains($html, '<strong>' . htmlspecialchars($a136Amount) . '</strong>'),
            "{$l}: the order confirmation e-mail lists IBAN, amount and variable symbol");

        [$l, $r] = $a136Do('place_cod', ['shipping_method' => 'gls', 'payment' => 'cod']);
        $a136Cod = (int)($r['new_orders'][0]['id'] ?? 0);
        [$l, , $html] = $a136Do('done', ['order_id' => $a136Cod]);
        assert_true($a136Cod > 0 && str_contains($html, (string)($r['new_orders'][0]['order_number'] ?? '-')) && $a136Block($html) === '',
            "{$l}: a cash-on-delivery order gets no bank transfer instructions");
        [$l, , $html] = $a136Do('mail', ['order_id' => $a136Cod]);
        assert_true(!str_contains($html, 'bankovým prevodom') && !str_contains($html, 'SK31'), "{$l}: nor does its confirmation e-mail");

        [$l, $r] = $a136Do('member_place', $a136Shipping + ['payment' => 'bank_transfer']);
        $a136Member = (int)($r['new_orders'][0]['id'] ?? 0);
        [$l, , $html] = $a136Do('member_account', ['order_id' => $a136Member]);
        assert_true($a136Member > 0 && str_contains($a136Block($html), '<strong data-bank-vs>' . $a136Member . '</strong>') && str_contains($a136Block($html), '<svg '),
            "{$l}: the customer's order detail repeats the instructions while the transfer is unpaid");

        [$l, , $html] = $a136Do('done_paid', ['order_id' => $a136Id]);
        assert_true(str_contains($html, (string)$a136Order['order_number']) && $a136Block($html) === '', "{$l}: once the transfer is confirmed the instructions disappear");
    }
} finally {
    if (isset($a136Drivers['mysql'])) $a136Run(['mysql', 'teardown', 'none', $a136App, $a136Drivers['mysql'], $a136Root, '{}']);
    $a136Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a136Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a136Tree as $a136Path) $a136Path->isDir() ? @rmdir($a136Path->getPathname()) : @unlink($a136Path->getPathname());
    @rmdir($a136Root);
}
