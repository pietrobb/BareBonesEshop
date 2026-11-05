<?php

// Review round 17, low: updated_at of the API's updated_after, Logger masking, e-mail enumeration, an exact refund key,
// a partial shipment's tracking, the worker's rollback guard, the API's vat_rate, the screenshots asset, the sender
// address and four storefront/admin UX details.
echo "\n--- Audit round 17: low findings ---\n";

$r17lStore = StoreContext::current()->id();
$r17lOld = '2000-01-01 00:00:00';
$r17lRead = static function (string $sql, array $args = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $value = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $value;
};

// 1. updated_after missed three writes: the order's payment id, the customer's sign-in and the e-mail verification.
$r17lOrder = (int)$r17lRead('SELECT id FROM orders WHERE store_id = ? ORDER BY id LIMIT 1', [$r17lStore]);
assert_true($r17lOrder > 0, 'R17 low: the suite has an order to work with');
$pdo->prepare('UPDATE orders SET updated_at = ? WHERE id = ?')->execute([$r17lOld, $r17lOrder]);
Orders::setPaymentId($r17lOrder, 'r17l-payment');
assert_true((string)$r17lRead('SELECT updated_at FROM orders WHERE id = ?', [$r17lOrder]) > $r17lOld, 'R17 low: setting the payment id moves the order\'s updated_at');

$r17lEmail = 'r17-low-customer@example.com';
$r17lPassword = 'R17-low-pass-word';
[, $r17lCustomer] = Customers::register($r17lEmail, $r17lPassword, 'R17 Low');
$r17lCustomer = (int)$r17lCustomer;
$pdo->prepare('UPDATE customers SET updated_at = ? WHERE id = ?')->execute([$r17lOld, $r17lCustomer]);
[$r17lLoggedIn] = Customers::login($r17lEmail, $r17lPassword);
assert_true($r17lLoggedIn && (string)$r17lRead('SELECT updated_at FROM customers WHERE id = ?', [$r17lCustomer]) > $r17lOld,
    'R17 low: a sign-in (last_login_at) moves the customer\'s updated_at');
Customers::logout();
foreach ($GLOBALS as $r17lValue) if ($r17lValue instanceof PDOStatement) $r17lValue->closeCursor();
(require TEST_CLI_DIR . '/../migrations/20261012210000_customer_email_verification.php')->up($pdo, true);
$r17lToken = bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO customer_email_verifications (customer_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)')
    ->execute([$r17lCustomer, $r17lEmail, hash('sha256', $r17lToken), gmdate('Y-m-d H:i:s', time() + 3600)]);
$pdo->prepare('UPDATE customers SET updated_at = ? WHERE id = ?')->execute([$r17lOld, $r17lCustomer]);
assert_true(Customers::verifyEmail($r17lToken, $r17lCustomer) === $r17lCustomer
    && (string)$r17lRead('SELECT updated_at FROM customers WHERE id = ?', [$r17lCustomer]) > $r17lOld, 'R17 low: verifying the e-mail moves the customer\'s updated_at');

// 2. Logger: an XML <password>, an escaped quote inside a JSON secret, and URL-/form-encoded card numbers leaked.
$r17lMasked = Logger::redact([
    'xml' => '<auth><username>u</username><password>Hunter2 secret</password><ns:ClientSecret><![CDATA[Hunter2c]]></ns:ClientSecret></auth>',
    'json' => '{"body":"{\"password\":\"ab\\\\\"cdHunter2\"}"}',
    'url' => 'card=4242%204242%204242%204242&x=1',
    'form' => 'number=4242+4242+4242+4242&exp=12',
    'dash' => 'pan=4242%2D4242%2D4242%2D4242',
]);
foreach ($r17lMasked as $r17lKey => $r17lValue) {
    assert_true(!preg_match('/Hunter2|4242(?:%20|%2D|\+)4242/i', (string)$r17lValue), "R17 low: the log masks the {$r17lKey} secret ({$r17lValue})");
}
assert_true(str_contains((string)$r17lMasked['xml'], '<username>u</username>') && str_contains((string)$r17lMasked['url'], '&x=1'),
    'R17 low: the rest of the logged value stays readable (' . json_encode($r17lMasked) . ')');

// 3. Registration and the guest confirmation page told anyone which addresses have an account ("already registered").
$r17lRoot = test_fixture_path('r17-low-account');
mkdir($r17lRoot . '/sessions', 0777, true);
file_put_contents($r17lRoot . '/run.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $mode, $email] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate-' . md5($email) . '.sqlite'));
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example']);
MailService::useAdapter(new LogMail(['log_file' => $dir . '/emails.log']));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$mails = static fn(): array => array_map(static fn(array $m): array => ['to' => $m['to'], 'text' => $m['text']], LogMail::captured());
if ($mode === 'guest') {
    $order = ['id' => 917017, 'customer_id' => null, 'customer_email' => $email, 'locale' => 'en'];
    echo "\nR17LRESULT" . json_encode(['shown' => GuestCheckoutAccount::shown($order), 'outcome' => GuestCheckoutAccount::sendLink($order, 'en'), 'mails' => $mails()]);
    exit;
}
$_SESSION = ['csrf_token' => 'r17l', 'form_trap_tokens_' . md5('account_register') => ['r17ltrap' => time() - 10]];
$_SERVER['REQUEST_URI'] = '/account.php';
$_SERVER['REMOTE_ADDR'] = '127.0.7.17';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['p' => 'register'];
$_POST = ['action' => 'register', 'email' => $email, 'password' => 'R17-new-pass-word', 'name' => 'R17 Register', 'register_token' => 'r17ltrap', 'csrf_token' => 'r17l'];
http_response_code(200);
ob_start();
register_shutdown_function(static function () use ($mails, $email): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR17LRESULT" . json_encode(['code' => http_response_code(), 'flash' => str_replace($email, '{email}', (string)($GLOBALS['flash'] ?? '')),
        'flash_type' => $GLOBALS['flashType'] ?? null, 'page' => $GLOBALS['p'] ?? null, 'taken_text' => str_contains($out, I18n::t('account.err_email_taken')),
        'shown_as' => str_contains($out, 'flash-error') ? 'error' : (str_contains($out, 'flash-ok') ? 'ok' : 'none'),
        'mails' => $mails()]);
});
require $root . '/account.php';
PHP);
$r17lRun = static function (string $mode, string $email) use ($r17lRoot, $testDbPath): array {
    $process = proc_open([PHP_BINARY, $r17lRoot . '/run.php', dirname(TEST_CLI_DIR), $testDbPath, $r17lRoot, $mode, $email],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($raw, 'R17LRESULT');
    $result = $at === false ? null : json_decode(substr($raw, $at + 10), true);
    return is_array($result) ? $result : ['raw' => substr($raw, 0, 800)];
};
$r17lNew = $r17lRun('register', 'r17-low-new@example.com');
$r17lTaken = $r17lRun('register', $r17lEmail);
$r17lView = static fn(array $r): array => array_intersect_key($r, ['code' => 1, 'flash' => 1, 'flash_type' => 1, 'page' => 1, 'taken_text' => 1, 'shown_as' => 1]);
// The rendered page shows the confirmation as a success box, never as an error (the bookshop acceptance checks .flash-error).
assert_true(($r17lNew['shown_as'] ?? null) === 'ok' && $r17lView($r17lNew) === $r17lView($r17lTaken),
    'R17 low: registering a taken address reads exactly like a new account (' . json_encode([$r17lNew, $r17lTaken]) . ')');
$r17lMailTo = static fn(array $r, string $needle): bool => (bool)array_filter($r['mails'] ?? [], static fn(array $m): bool => str_contains((string)$m['text'], $needle));
assert_true($r17lMailTo($r17lTaken, 'reset') && !$r17lMailTo($r17lTaken, 'verify_email'),
    'R17 low: the owner of the taken address gets a sign-in (password reset) link instead (' . json_encode($r17lTaken['mails'] ?? null) . ')');
$r17lGuest = $r17lRun('guest', $r17lEmail);
assert_true(($r17lGuest['shown'] ?? false) === true && ($r17lGuest['outcome'] ?? '') === 'sent' && $r17lMailTo($r17lGuest, 'reset'),
    'R17 low: the guest confirmation page offers an account for an address that has one too; its mailbox gets a reset link (' . json_encode($r17lGuest) . ')');

// 4. A refund key holding "%" or "_" matched other refunds' credit notes through LIKE.
$pdo->prepare("INSERT INTO invoices (order_id, store_id, number, type, status, currency, total_net, total_vat, total_gross, issued_at, pdf_path, download_token, credit_snapshot)
    VALUES (?, ?, 'R17L-CN-1', 'credit_note', 'issued', 'eur', -100, 0, -100, " . Db::now() . ", 'r17l/cn.pdf', ?, ?)")
    ->execute([$r17lOrder, $r17lStore, hash('sha256', 'r17l-cn'), json_encode(['refund_key' => 'r17l-refund-AB'])]);
$r17lCreditId = (int)$pdo->lastInsertId();
$r17lByKey = new ReflectionMethod(InvoiceService::class, 'refundCreditByKey');
$r17lByKey->setAccessible(true);
assert_equals([$r17lCreditId, null, null], [
    (int)($r17lByKey->invoke(null, $pdo, $r17lOrder, 'r17l-refund-AB')['id'] ?? 0),
    $r17lByKey->invoke(null, $pdo, $r17lOrder, 'r17l-refund-A_'),
    $r17lByKey->invoke(null, $pdo, $r17lOrder, 'r17l-%'),
], 'R17 low: a refund finds only the credit note of its own key, "_" and "%" match nothing else');
$pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$r17lCreditId]);

// 5. A partial shipment's e-mail showed the tracking of the order's latest parcel, not its own.
$pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, tracking_number, idempotency_key, shipped_at) VALUES (?, ?, 'shipped', 'packeta', 'R17L-TRACK-1', 'r17l-ship-1', ?)")
    ->execute([$r17lStore, $r17lOrder, gmdate('Y-m-d H:i:s', time() - 60)]);
$r17lFirst = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, tracking_number, idempotency_key, shipped_at) VALUES (?, ?, 'shipped', NULL, NULL, 'r17l-ship-2', ?)")
    ->execute([$r17lStore, $r17lOrder, gmdate('Y-m-d H:i:s')]);
$r17lSecond = (int)$pdo->lastInsertId();
assert_equals(['R17L-TRACK-1', null, 'R17L-TRACK-1'], [
    Fulfillment::latestTracking($r17lOrder)['tracking_number'] ?? null,
    Fulfillment::latestTracking($r17lOrder, $r17lSecond)['tracking_number'] ?? null,
    Fulfillment::latestTracking($r17lOrder, $r17lFirst)['tracking_number'] ?? null,
], 'R17 low: the tracking of a named parcel is that parcel\'s, never an earlier one\'s');
$r17lJobs = Fulfillment::shippedJobsWithin($r17lOrder, $r17lSecond);
assert_true($r17lJobs === [] || (($r17lJobs[0][1]['shipment_id'] ?? null) === $r17lSecond), 'R17 low: the shipped e-mail job names its parcel (' . json_encode($r17lJobs) . ')');
$pdo->prepare("DELETE FROM shipments WHERE idempotency_key IN ('r17l-ship-1', 'r17l-ship-2')")->execute();
$pdo->prepare('UPDATE orders SET payment_id = NULL WHERE id = ? AND payment_id = ?')->execute([$r17lOrder, 'r17l-payment']);

// 6. The CLI worker recorded a failed job inside the job's still open transaction (and every later job with it).
$r17lWorker = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/worker.php');
assert_true(substr_count($r17lWorker, '$rollBackOpenJob();') >= 2 && str_contains($r17lWorker, 'Job left a database transaction open'),
    'R17 low: the worker rolls a failed job\'s transaction back and refuses a job that leaves one open');

// 7. The API's vat_rate was the int 20 for a line with a VAT component and the float 20.0 without one.
assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/ApiV1.php'), "(int)\$component['rate_basis_points'] / 100.0"),
    'R17 low: the API\'s line vat_rate is always a float');

// 8. The screenshots release asset unpacked as 0777: run.sh packs it with 0644 entries.
assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/tests/bookshop/run.sh'), '(0100644 << 16)'),
    'R17 low: the bookshop run packs bookshop-screenshots.zip with 0644 entries');

// 9. Without MAIL_FROM every e-mail left from noreply@localhost.invalid even with a real app_url.
$r17lCfg = new ReflectionProperty(Security::class, 'cfg');
$r17lCfg->setAccessible(true);
$r17lCfgBefore = $r17lCfg->getValue();
$r17lFromBefore = getenv('MAIL_FROM');
try {
    putenv('MAIL_FROM');
    $r17lSenders = [];
    foreach (['https://www.mojobchod.sk/shop', 'http://localhost:8000', 'http://127.0.0.1', 'https://shop.example.com', 'http://eshop:8080', ''] as $r17lUrl) {
        $r17lCfg->setValue(null, ['app_url' => $r17lUrl] + (array)$r17lCfgBefore);
        $r17lSenders[] = MailService::sender();
    }
    putenv('MAIL_FROM=Obchod@Mojobchod.sk');
    $r17lSenders[] = MailService::sender();
    assert_equals(['noreply@mojobchod.sk', MailService::DEFAULT_FROM, MailService::DEFAULT_FROM, MailService::DEFAULT_FROM, MailService::DEFAULT_FROM, MailService::DEFAULT_FROM, 'obchod@mojobchod.sk'], $r17lSenders,
        'R17 low: without MAIL_FROM mail leaves from noreply@ the shop\'s public domain, never a reserved or local one');
} finally {
    $r17lCfg->setValue(null, $r17lCfgBefore);
    putenv($r17lFromBefore === false ? 'MAIL_FROM' : 'MAIL_FROM=' . $r17lFromBefore);
}

// 10. UX: one skip link per storefront page, "Platba kartou Zdarma" with a space, the breadcrumb without numbers, and
//     the admin order history in words instead of codes.
$r17lTheme = dirname(TEST_CLI_DIR) . '/themes/default';
$r17lShell = Template::render('storefront/shell', ['t' => I18n::all(), 'content_html' => '<p>r17</p>', 'runtime_config_json' => '{}', 'nonce' => 'r17']);
assert_equals(1, substr_count($r17lShell, 'class="skip-link"'), 'R17 low: a storefront page has one skip link');
$r17lCheckout = (string)file_get_contents($r17lTheme . '/blocks/storefront/checkout.html') . (string)file_get_contents($r17lTheme . '/assets/storefront.js');
assert_true(!str_contains($r17lCheckout, '</span><strong>') && substr_count($r17lCheckout, '</span> <strong>') >= 4,
    'R17 low: the method label and its price are separated ("Platba kartou Zdarma", not "Platba kartouZdarma")');
assert_true((bool)preg_match('/\.breadcrumb ol\{[^}]*list-style:none/', (string)file_get_contents($r17lTheme . '/assets/storefront.css')),
    'R17 low: the breadcrumb is a row of links, not a numbered list');
require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
$r17lLocale = I18n::locale();
try {
    I18n::setLocale('sk');
    assert_true(adminCodeLabel('admin.order.history.action.', 'update') !== 'update' && OrderStatusLabels::order('pending') !== 'pending'
        && adminCodeLabel('admin.order.history.action.', 'r17-unknown') === 'r17-unknown',
        'R17 low: the admin order history shows the action and the statuses in words, an unknown code as it is');
} finally {
    I18n::setLocale($r17lLocale);
}
$r17lOrders = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php');
assert_true(str_contains($r17lOrders, "adminCodeLabel('admin.order.history.action.', (string)\$log['action'])") && !str_contains($r17lOrders, "esc(\$log['action'])"),
    'R17 low: the order detail renders its history through those labels');
