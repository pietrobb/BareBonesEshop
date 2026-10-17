<?php

// A16.6: ThePay gateway per the official ThePay API (https://gate.thepay.cz/openapi.yaml) — Signature/
// SignatureDate headers with merchant_id, hosted pay_url redirect, unsigned GET notifications
// (?project_id&payment_uid&type) always confirmed by the payment detail call, refunds. No live
// payments: every ThePay call goes through an injected transport.
echo "\n--- Audit A16.6: ThePay payment gateway ---\n";
require_once dirname(TEST_CLI_DIR) . '/adapters/PaymentAdapter.php';
require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';
$a166Ready = is_file(dirname(TEST_CLI_DIR) . '/adapters/contrib/ThePayPayment.php');
assert_true($a166Ready, 'A16.6 ThePay: adapter exists');
assert_equals('ThePayPayment', Adapters::registry('payment')['thepay']['class'] ?? null, 'A16.6 ThePay: built-in provider code thepay is registered');

$a166Cfg = ['thepay_merchant_id' => '6cdf1b24-0479-4a64-b076-b0ece482453f', 'thepay_project_id' => '1212', 'thepay_password' => 'a166-password'];
$a166Detail = static fn(string $uid, string $state, int $amount = 1990, string $currency = 'EUR', int $project = 1212, ?int $paid = null): array => ['status' => 200, 'body' => json_encode([
    'uid' => $uid, 'project_id' => $project, 'order_id' => 'A166-' . $uid, 'state' => $state, 'currency' => $currency, 'amount' => $amount,
    'paid_amount' => $paid ?? (in_array($state, ['paid', 'refunded', 'partially_refunded'], true) ? $amount : 0)])];
$a166Calls = [];
$a166Answers = [];
$a166Transport = static function (string $method, string $url, array $headers, ?string $body) use (&$a166Calls, &$a166Answers): array {
    $a166Calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    $path = (string)parse_url($url, PHP_URL_PATH);
    if ($method === 'POST' && preg_match('#^/v1/projects/[0-9]+/payments$#', $path)) return $a166Answers['create'] ?? ['status' => 201, 'body' => '{"pay_url":"https://demo.gate.thepay.cz/a166/pay/","detail_url":"https://demo.gate.thepay.cz/a166/state/"}'];
    if ($method === 'POST' && str_ends_with($path, '/refund')) return $a166Answers['refund'] ?? ['status' => 201, 'body' => ''];
    if ($method === 'GET' && preg_match('#^/v1/projects/[0-9]+/payments/([^/]+)$#', $path, $m)) return $a166Answers[$m[1]] ?? ['status' => 404, 'body' => '{"message":"Payment not found"}'];
    return ['status' => 404, 'body' => ''];
};
$a166Throws = static function (callable $fn, string $class = 'Throwable'): bool {
    try { $fn(); } catch (Throwable $e) { return $e instanceof $class; }
    return false;
};
$a166Header = static function (array $call, string $name): string {
    foreach ($call['headers'] ?? [] as $header) {
        [$key, $value] = array_pad(explode(':', (string)$header, 2), 2, '');
        if (strcasecmp(trim($key), $name) === 0) return trim($value);
    }
    return '';
};
$a166Signed = static function (array $call) use ($a166Header): bool {
    $date = $a166Header($call, 'SignatureDate');
    $time = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $date, new DateTimeZone('UTC'));
    return $time !== false && abs($time->getTimestamp() - time()) <= 20
        && hash_equals(hash('sha256', '6cdf1b24-0479-4a64-b076-b0ece482453f' . 'a166-password' . $date), $a166Header($call, 'Signature'));
};
$a166Query = static fn(array $call): array => (static function (string $url): array { parse_str((string)parse_url($url, PHP_URL_QUERY), $q); return $q; })($call['url'] ?? '');
$a166Notification = static fn(string $uid, array $override = []): array => array_replace(['project_id' => '1212', 'payment_uid' => $uid, 'type' => 'state_changed'], $override);

if ($a166Ready) {
    $a166Class = Adapters::providerClass('payment', 'thepay');
    assert_true(is_subclass_of($a166Class, HostedCheckoutAdapter::class) && is_subclass_of($a166Class, PullVerifiedNotificationAdapter::class), 'A16.6 ThePay: hosted redirect + detail-verified notification adapter');
    assert_true(!is_subclass_of($a166Class, IdempotentRefundAdapter::class), 'A16.6 ThePay: the refund request has no idempotency key, so idempotent refunds are not claimed');
    assert_equals(['https://demo.gate.thepay.cz'], (new ThePayPayment([]))->redirectOrigins(), 'A16.6 ThePay: demo gate origin by default');
    assert_equals(['https://gate.thepay.cz'], (new ThePayPayment(['thepay_env' => 'production']))->redirectOrigins(), 'A16.6 ThePay: production gate origin');

    // Payment creation per POST /v1/projects/{project_id}/payments.
    $a166Order = ['order_number' => 'A166-1', 'total' => 1990, 'currency' => 'eur', 'customer_email' => 'buyer@example.com', 'customer_name' => 'Jana Mária Nováková', 'locale' => 'sk',
        'success_url' => 'https://shop.example/?page=order-done&order=A166-1&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => 'https://shop.example/?page=checkout-cancel&order=A166-1',
        'notify_url' => 'https://shop.example/api/v1/webhooks/payment/thepay'];
    $a166Session = (new ThePayPayment($a166Cfg, $a166Transport))->createSession($a166Order);
    $a166Create = $a166Calls[0] ?? ['method' => '', 'url' => '', 'headers' => [], 'body' => ''];
    $a166Body = json_decode((string)$a166Create['body'], true) ?: [];
    assert_equals('https://demo.gate.thepay.cz/a166/pay/', $a166Session['url'] ?? null, 'A16.6 ThePay create: customer goes to pay_url');
    assert_true(preg_match('/^A166-1-[0-9a-f]{8}$/D', (string)($a166Session['session_id'] ?? '')) === 1 && ($a166Body['uid'] ?? null) === ($a166Session['session_id'] ?? ''), 'A16.6 ThePay create: payment uid = order number + random suffix, kept as session');
    assert_equals(['POST', 'https://demo.api.thepay.cz/v1/projects/1212/payments', ['merchant_id' => '6cdf1b24-0479-4a64-b076-b0ece482453f'], 'application/json'],
        [$a166Create['method'], strtok($a166Create['url'], '?'), $a166Query($a166Create), $a166Header($a166Create, 'Content-Type')], 'A16.6 ThePay create: POST /v1/projects/{project_id}/payments?merchant_id on the demo API as JSON');
    assert_true($a166Signed($a166Create), 'A16.6 ThePay auth: Signature = sha256(merchant_id . password . SignatureDate), SignatureDate RFC 7231 GMT now');
    assert_equals([1990, 'EUR', 'A166-1', 'sk', ['name' => 'Jana Mária', 'surname' => 'Nováková', 'email' => 'buyer@example.com']],
        [$a166Body['amount'] ?? null, $a166Body['currency_code'] ?? null, $a166Body['order_id'] ?? null, $a166Body['language_code'] ?? null, $a166Body['customer'] ?? null],
        'A16.6 ThePay create: amount in minor units, ISO currency, order id, language, customer name/surname/e-mail');
    assert_equals(['https://shop.example/?page=order-done&order=A166-1&session_id=', 'https://shop.example/api/v1/webhooks/payment/thepay'],
        [$a166Body['return_url'] ?? null, $a166Body['notif_url'] ?? null], 'A16.6 ThePay create: return and notification URLs');
    assert_true(!str_contains((string)$a166Create['body'], 'a166-password') && !str_contains((string)$a166Create['url'], 'a166-password'), 'A16.6 ThePay create: the API password never leaves the shop');
    $a166Calls = [];
    (new ThePayPayment($a166Cfg, $a166Transport))->createSession(['customer_name' => 'Cher', 'locale' => 'xx'] + $a166Order);
    $a166Body = json_decode((string)($a166Calls[0]['body'] ?? ''), true) ?: [];
    assert_equals([['name' => 'Cher', 'surname' => 'Cher', 'email' => 'buyer@example.com'], 'en'], [$a166Body['customer'] ?? null, $a166Body['language_code'] ?? null], 'A16.6 ThePay create: one-word name fills the required surname, unsupported language falls back to en');
    $a166Calls = [];
    $a166Answers['A166-1-00000000'] = $a166Detail('A166-1-00000000', 'waiting_for_payment');
    (new ThePayPayment($a166Cfg + ['thepay_env' => 'production'], $a166Transport))->getStatus('A166-1-00000000');
    assert_equals('https://api.thepay.cz/v1/projects/1212/payments/A166-1-00000000', strtok($a166Calls[0]['url'] ?? '', '?'), 'A16.6 ThePay: production API base api.thepay.cz');

    // Fail closed before any HTTP call / on refused or foreign answers.
    foreach (['thepay_merchant_id', 'thepay_project_id', 'thepay_password'] as $a166Missing) {
        $a166Calls = [];
        $a166Cfg2 = $a166Cfg;
        unset($a166Cfg2[$a166Missing]);
        assert_true($a166Throws(static fn() => (new ThePayPayment($a166Cfg2, $a166Transport))->createSession($a166Order)) && $a166Calls === [], "A16.6 ThePay: missing {$a166Missing} refuses to create a payment without calling ThePay");
    }
    $a166Calls = [];
    assert_true($a166Throws(static fn() => (new ThePayPayment($a166Cfg, $a166Transport))->createSession(['notify_url' => ''] + $a166Order)) && $a166Calls === [], 'A16.6 ThePay: payment without a notification URL is refused');
    $a166Answers['create'] = ['status' => 201, 'body' => '{"pay_url":"https://pay.attacker.example/pay/","detail_url":"https://pay.attacker.example/state/"}'];
    assert_true($a166Throws(static fn() => (new ThePayPayment($a166Cfg, $a166Transport))->createSession($a166Order)), 'A16.6 ThePay: pay_url outside the declared gate origin is refused');
    $a166Answers['create'] = ['status' => 409, 'body' => '{"message":"Payment with this UID was already created with different request body data."}'];
    assert_true($a166Throws(static fn() => (new ThePayPayment($a166Cfg, $a166Transport))->createSession($a166Order)), 'A16.6 ThePay: refused creation is an error');
    unset($a166Answers['create']);

    // Notifications: project_id + payment_uid are references only; the event comes from the detail call.
    $a166Answers['A166-1-0000abcd'] = $a166Detail('A166-1-0000abcd', 'paid');
    $a166Calls = [];
    $a166Event = (new ThePayPayment($a166Cfg, $a166Transport))->verifiedEvent($a166Notification('A166-1-0000abcd', ['state' => 'paid', 'amount' => '1']));
    assert_equals(['payment.paid', 'A166-1-0000abcd:paid', 'A166-A166-1-0000abcd', 'A166-1-0000abcd', 1990, 'eur'],
        [$a166Event['event_type'] ?? '', $a166Event['event_id'] ?? '', $a166Event['data']['order_number'] ?? '', $a166Event['data']['payment_id'] ?? '', $a166Event['data']['amount'] ?? 0, $a166Event['data']['currency'] ?? ''],
        'A16.6 ThePay notify: paid detail becomes payment.paid with the detail amount/currency and a per-state event id');
    $a166DetailCall = $a166Calls[0] ?? ['method' => '', 'url' => '', 'headers' => []];
    assert_true($a166DetailCall['method'] === 'GET' && strtok($a166DetailCall['url'], '?') === 'https://demo.api.thepay.cz/v1/projects/1212/payments/A166-1-0000abcd' && ($a166Query($a166DetailCall)['merchant_id'] ?? '') === '6cdf1b24-0479-4a64-b076-b0ece482453f' && $a166Signed($a166DetailCall),
        'A16.6 ThePay notify: verified by a signed GET /v1/projects/{project_id}/payments/{payment_uid}');
    foreach (['expired' => 'payment.failed', 'preauth_cancelled' => 'payment.failed', 'preauth_expired' => 'payment.failed', 'error' => 'payment.failed', 'refunded' => 'refund.reported', 'partially_refunded' => 'refund.reported', 'waiting_for_payment' => 'payment.waiting_for_payment', 'preauthorized' => 'payment.preauthorized'] as $a166State => $a166Type) {
        $a166Answers['A166-1-0000abcd'] = $a166Detail('A166-1-0000abcd', $a166State);
        assert_equals($a166Type, (new ThePayPayment($a166Cfg, $a166Transport))->verifiedEvent($a166Notification('A166-1-0000abcd'))['event_type'] ?? '', "A16.6 ThePay notify: state {$a166State} maps to {$a166Type}");
    }
    $a166Answers['A166-1-0000abcd'] = $a166Detail('A166-1-0000abcd', 'paid', 1990, 'EUR', 1212, 1000);
    assert_equals(1000, (new ThePayPayment($a166Cfg, $a166Transport))->verifiedEvent($a166Notification('A166-1-0000abcd'))['data']['amount'] ?? 0, 'A16.6 ThePay notify: a paid event carries the paid amount, so an underpayment cannot pay the order');
    foreach (['other project' => ['project_id' => '1213'], 'no project' => ['project_id' => null], 'array uid' => ['payment_uid' => ['A166-1-0000abcd']], 'malformed uid' => ['payment_uid' => '../refund'], 'empty uid' => ['payment_uid' => ''], 'long uid' => ['payment_uid' => str_repeat('a', 101)]] as $a166Case => $a166Override) {
        $a166Calls = [];
        $a166N = array_filter($a166Notification('A166-1-0000abcd', $a166Override), static fn($v) => $v !== null);
        assert_true($a166Throws(static fn() => (new ThePayPayment($a166Cfg, $a166Transport))->verifiedEvent($a166N), PaymentNotificationUnverified::class) && $a166Calls === [], "A16.6 ThePay notify: {$a166Case} refused without a detail call");
    }
    assert_true($a166Throws(static fn() => (new ThePayPayment($a166Cfg, $a166Transport))->verifiedEvent($a166Notification('A166-9-unknown')), PaymentNotificationUnverified::class), 'A16.6 ThePay notify: uid ThePay does not know (404) is unverified');
    $a166Answers['A166-1-0000abcd'] = $a166Detail('A166-1-0000abce', 'paid');
    assert_true($a166Throws(static fn() => (new ThePayPayment($a166Cfg, $a166Transport))->verifiedEvent($a166Notification('A166-1-0000abcd')), PaymentNotificationUnverified::class), 'A16.6 ThePay notify: detail of a different payment is unverified');
    $a166Answers['A166-1-0000abcd'] = $a166Detail('A166-1-0000abcd', 'paid', 1990, 'EUR', 1300);
    assert_true($a166Throws(static fn() => (new ThePayPayment($a166Cfg, $a166Transport))->verifiedEvent($a166Notification('A166-1-0000abcd')), PaymentNotificationUnverified::class), 'A16.6 ThePay notify: payment of another project is unverified');
    $a166Answers['A166-1-0000abcd'] = $a166Detail('A166-1-0000abcd', 'stolen');
    assert_true($a166Throws(static fn() => (new ThePayPayment($a166Cfg, $a166Transport))->verifiedEvent($a166Notification('A166-1-0000abcd')), PaymentNotificationUnverified::class), 'A16.6 ThePay notify: unknown state is unverified');
    foreach (['HTTP 503' => ['status' => 503, 'body' => '{"message":"Service Unavailable"}'], 'HTTP 429' => ['status' => 429, 'body' => '{"message":"Too Many Requests"}'], 'HTTP 401 (shop credentials)' => ['status' => 401, 'body' => '{"message":"Unauthorized"}'], 'HTTP 403 (IP whitelist)' => ['status' => 403, 'body' => '{"message":"Forbidden"}']] as $a166Case => $a166Answer) {
        $a166Answers['A166-1-0000abcd'] = $a166Answer;
        assert_true($a166Throws(static fn() => (new ThePayPayment($a166Cfg, $a166Transport))->verifiedEvent($a166Notification('A166-1-0000abcd')))
            && !$a166Throws(static fn() => (new ThePayPayment($a166Cfg, $a166Transport))->verifiedEvent($a166Notification('A166-1-0000abcd')), PaymentNotificationUnverified::class), "A16.6 ThePay notify: {$a166Case} stays retryable (not unverified)");
    }
    $a166Answers['A166-1-0000abcd'] = $a166Detail('A166-1-0000abcd', 'paid');
    assert_equals('paid', (new ThePayPayment($a166Cfg, $a166Transport))->getStatus('A166-1-0000abcd'), 'A16.6 ThePay status: state of the payment');

    // Refunds: POST …/payments/{uid}/refund {amount, reason}; 201 = refund request accepted.
    $a166Calls = [];
    $a166Refund = (new ThePayPayment($a166Cfg, $a166Transport))->refund('A166-1-0000abcd', 500);
    $a166RefundCall = end($a166Calls) ?: ['method' => '', 'url' => '', 'headers' => [], 'body' => ''];
    $a166RefundBody = json_decode((string)$a166RefundCall['body'], true) ?: [];
    assert_true($a166RefundCall['method'] === 'POST' && strtok($a166RefundCall['url'], '?') === 'https://demo.api.thepay.cz/v1/projects/1212/payments/A166-1-0000abcd/refund' && $a166Signed($a166RefundCall)
        && ($a166RefundBody['amount'] ?? null) === 500 && is_string($a166RefundBody['reason'] ?? null) && $a166RefundBody['reason'] !== '', 'A16.6 ThePay refund: signed POST JSON amount + reason to …/refund');
    assert_equals('succeeded', $a166Refund['status'] ?? '', 'A16.6 ThePay refund: 201 is an accepted refund');
    $a166Answers['refund'] = ['status' => 403, 'body' => '{"message":"Refund not allowed"}'];
    assert_true($a166Throws(static fn() => (new ThePayPayment($a166Cfg, $a166Transport))->refund('A166-1-0000abcd', 500)), 'A16.6 ThePay refund: refused refund is not reported as succeeded');
    unset($a166Answers['refund']);
    $a166Calls = [];
    assert_true($a166Throws(static fn() => (new ThePayPayment($a166Cfg, $a166Transport))->refund('A166-1-0000abcd', 0)) && $a166Calls === [], 'A16.6 ThePay refund: non-positive amount refused');
}

// The real checkout hands ThePay its notification URL and the payer's name.
ConsentService::setDefinitionsForTesting([]);
$pdo->exec("INSERT INTO categories (store_id, name, slug, path) VALUES ('default', 'A166', 'a166-category', 'a166-category')");
$a166Category = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO products (category_id, name, slug, sku, product_type, price, currency, status, stock)
    VALUES ({$a166Category}, 'A166 guide', 'a166-guide', 'A166-GUIDE', 'downloadable', 1990, 'eur', 'active', 0)");
$a166Product = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
    VALUES ('A166-GUIDE', 'pdf', 'PDF', 'guide.pdf', 'guide.pdf', 'application/pdf', 2, 3600, 1)");
$pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
    VALUES ('a166_thepay', 'thepay', 'ThePay', '', 'online_card', 0, NULL, 0, 0, NULL, 1, 1, 99)");
$pdo->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('a166_thepay', 'SK')");
$a166Instances = new ReflectionProperty(Adapters::class, 'instances');
$a166PrevInstances = $a166Instances->getValue();
$a166Calls = [];
$a166Answers = [];
if ($a166Ready) $a166Instances->setValue(null, ['payment' => new ThePayPayment($a166Cfg, $a166Transport)] + $a166PrevInstances);
try {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Cart::clear();
    Cart::add($a166Product, null, 1);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.16.6';
    $_SESSION['csrf_token'] = 'a166-checkout';
    $_POST = ['csrf_token' => 'a166-checkout', 'name' => 'A166 Buyer', 'email' => 'a166@example.com', 'country' => 'SK', 'shipping_method' => 'digital', 'payment_method' => 'a166_thepay'];
    $a166Result = apiCheckout(['app_url' => 'https://shop.example'] + (require dirname(TEST_CLI_DIR) . '/config.php'));
    $_POST = [];
    $a166Create = array_values(array_filter($a166Calls, static fn($c) => $c['method'] === 'POST' && str_ends_with((string)strtok($c['url'], '?'), '/payments')))[0] ?? null;
    $a166Body = json_decode((string)($a166Create['body'] ?? ''), true) ?: [];
    $a166OrderRow = $pdo->query("SELECT order_number, payment_id FROM orders WHERE id = " . (int)($a166Result['order_id'] ?? 0))->fetch() ?: ['order_number' => '', 'payment_id' => ''];
    assert_true(!empty($a166Result['success']) && empty($a166Result['payment_error']), 'A16.6 checkout: ThePay order is created with a payment session (' . ($a166Result['payment_error'] ?? $a166Result['error'] ?? 'ok') . ')');
    assert_equals(['https://shop.example/api/v1/webhooks/payment/thepay', ['name' => 'A166', 'surname' => 'Buyer', 'email' => 'a166@example.com'], $a166OrderRow['order_number']],
        [$a166Body['notif_url'] ?? null, $a166Body['customer'] ?? null, $a166Body['order_id'] ?? null], 'A16.6 checkout: notification URL, payer and order number are sent to ThePay');
    assert_equals('https://demo.gate.thepay.cz/a166/pay/', $a166Result['payment_url'] ?? null, 'A16.6 checkout: customer is sent to the ThePay gate');
    assert_equals($a166Body['uid'] ?? 'missing', (string)$a166OrderRow['payment_id'], 'A16.6 checkout: ThePay payment uid stored on the order');
} finally {
    $_POST = [];
    $a166Instances->setValue(null, $a166PrevInstances);
    Cart::clear();
    JobQueue::pull(500);
    $pdo->exec("DELETE FROM payment_method_countries WHERE method_code = 'a166_thepay'");
    $pdo->exec("DELETE FROM payment_methods WHERE code = 'a166_thepay'");
}

// The real notification endpoint over HTTP: GET ?project_id&payment_uid&type, confirmed against a
// fake ThePay payment detail API.
$a166Root = test_fixture_path('thepay-notify');
mkdir($a166Root . '/src/Http', 0700, true);
file_put_contents($a166Root . '/config.php', '<?php return ' . var_export($a166Cfg, true) . ';');
copy(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php', $a166Root . '/src/Http/ApiHandlers.php');
file_put_contents($a166Root . '/payments.json', '{}');
$a166Snapshot = new PDO('sqlite:' . $testDbPath);
$a166Snapshot->exec('VACUUM INTO ' . $a166Snapshot->quote($a166Root . '/shop.sqlite'));
$a166Snapshot = null;
$a166Db = new PDO('sqlite:' . $a166Root . '/shop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$a166Template = $a166Db->query('SELECT * FROM orders LIMIT 1')->fetch();
unset($a166Template['id']);
$a166Ids = [];
$a166Cases = ['paid' => 'T166-paid-0001', 'forged' => 'T166-wait-0002', 'project' => 'T166-proj-0003', 'amount' => 'T166-amnt-0004', 'currency' => 'T166-curr-0005', 'failed' => 'T166-expd-0006', 'outage' => 'T166-down-0007'];
foreach ($a166Cases as $a166Case => $a166Uid) {
    $a166Row = array_replace($a166Template, ['order_number' => 'A166-' . $a166Uid, 'status' => 'pending', 'payment_status' => 'pending', 'payment_id' => $a166Uid, 'total' => 1990, 'currency' => 'eur', 'store_id' => 'default', 'payment_method_snapshot' => json_encode(['provider' => 'thepay'])]);
    $a166Db->prepare('INSERT INTO orders (' . implode(',', array_keys($a166Row)) . ') VALUES (' . implode(',', array_fill(0, count($a166Row), '?')) . ')')->execute(array_values($a166Row));
    $a166Ids[$a166Case] = (int)$a166Db->lastInsertId();
}
file_put_contents($a166Root . '/router.php', '<?php $a166App = ' . var_export(dirname(TEST_CLI_DIR), true) . ";\n" . <<<'PHP'
require $a166App . '/src/autoload.php';
require $a166App . '/adapters/PaymentAdapter.php';
require __DIR__ . '/src/Http/ApiHandlers.php';
$cfg = require __DIR__ . '/config.php';
Db::init(new PDO('sqlite:' . __DIR__ . '/shop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]), 'sqlite');
JobQueue::setDir(__DIR__ . '/jobs');
Bootstrap::initStoreContext('/', '', 'default');
$transport = static function (string $method, string $url, array $headers, ?string $body): array {
    file_put_contents(__DIR__ . '/calls.log', $method . ' ' . $url . "\n", FILE_APPEND);
    $payments = json_decode((string)file_get_contents(__DIR__ . '/payments.json'), true);
    return $payments[basename((string)parse_url($url, PHP_URL_PATH))] ?? ['status' => 404, 'body' => '{"message":"Payment not found"}'];
};
if (class_exists('ThePayPayment')) (new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new ThePayPayment($cfg, $transport)]);
header('Content-Type: application/json');
echo json_encode(apiWebhookPayment());
PHP);
$a166Socket = stream_socket_server('tcp://127.0.0.1:0');
$a166Address = stream_socket_get_name($a166Socket, false);
fclose($a166Socket);
$a166Server = proc_open([PHP_BINARY, '-S', $a166Address, $a166Root . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $a166Root . '/server.log', 'a'], 2 => ['file', $a166Root . '/server.log', 'a']], $a166Pipes);
fclose($a166Pipes[0]);
try {
    $a166Up = false;
    for ($a166Try = 0; $a166Try < 50 && !$a166Up; $a166Try++) {
        $a166Probe = @stream_socket_client('tcp://' . $a166Address, $a166Errno, $a166Error, 0.1);
        if ($a166Probe) { fclose($a166Probe); $a166Up = true; } else usleep(100000);
    }
    assert_true($a166Up, 'A16.6 HTTP: isolated notification fixture starts');
    $a166ThePay = static function (array $payments) use ($a166Root, $a166Detail): void {
        $answers = [];
        foreach ($payments as $uid => $answer) $answers[$uid] = is_array($answer) ? $answer : $a166Detail($uid, $answer);
        file_put_contents($a166Root . '/payments.json', json_encode($answers));
    };
    $a166Notify = static function (string $query) use ($a166Address): array {
        $stream = @fopen('http://' . $a166Address . '/?action=webhook_payment&provider=thepay' . $query, 'r', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
        if (!$stream) return [0, null];
        $response = stream_get_contents($stream);
        preg_match('/\s(\d{3})\s/', stream_get_meta_data($stream)['wrapper_data'][0] ?? '', $status);
        fclose($stream);
        return [(int)($status[1] ?? 0), json_decode((string)$response, true)];
    };
    $a166Q = static fn(string $case, string $project = '1212'): string => '&project_id=' . $project . '&payment_uid=' . rawurlencode($a166Cases[$case]) . '&type=state_changed';
    $a166State = static fn(string $case): string => (string)$a166Db->query('SELECT payment_status FROM orders WHERE id = ' . $a166Ids[$case])->fetchColumn();
    $a166Tx = static fn(string $case): int => (int)$a166Db->query('SELECT COUNT(*) FROM payment_transactions WHERE order_id = ' . $a166Ids[$case])->fetchColumn();

    $a166ThePay([$a166Cases['paid'] => 'paid', $a166Cases['forged'] => 'waiting_for_payment', $a166Cases['project'] => 'paid',
        $a166Cases['amount'] => $a166Detail($a166Cases['amount'], 'paid', 1000), $a166Cases['currency'] => $a166Detail($a166Cases['currency'], 'paid', 1990, 'CZK'),
        $a166Cases['failed'] => 'expired', $a166Cases['outage'] => ['status' => 503, 'body' => '{"message":"Service Unavailable"}']]);
    assert_equals([200, ['status' => 'ok']], $a166Notify($a166Q('paid')), 'A16.6 HTTP: GET notification for a paid payment is accepted');
    assert_equals('paid', $a166State('paid'), 'A16.6 HTTP: confirmed paid marks the order paid');
    $a166PaidTx = $a166Tx('paid');
    assert_equals([200, ['status' => 'already_processed']], $a166Notify($a166Q('paid')), 'A16.6 HTTP: repeated notification deduplicates');
    assert_equals($a166PaidTx, $a166Tx('paid'), 'A16.6 HTTP: replay writes no payment transaction');
    assert_equals(200, $a166Notify($a166Q('forged') . '&state=paid&amount=1990')[0], 'A16.6 HTTP: notification claiming paid for an unpaid payment is acknowledged');
    assert_equals('pending', $a166State('forged'), 'A16.6 HTTP: request parameters never pay an order ThePay reports as waiting');
    assert_equals([400, ['error' => 'Invalid notification']], $a166Notify($a166Q('project', '9999')), 'A16.6 HTTP: notification for another project is rejected');
    assert_equals('pending', $a166State('project'), 'A16.6 HTTP: foreign project cannot pay');
    assert_equals(500, $a166Notify($a166Q('amount'))[0], 'A16.6 HTTP: amount mismatch fails for retry');
    assert_equals('pending', $a166State('amount'), 'A16.6 HTTP: amount mismatch cannot pay');
    assert_equals(0, (int)$a166Db->query("SELECT COUNT(*) FROM processed_events WHERE event_id = 'payment_thepay_" . $a166Cases['amount'] . ":paid'")->fetchColumn(), 'A16.6 HTTP: failed claim is released');
    assert_equals(500, $a166Notify($a166Q('currency'))[0], 'A16.6 HTTP: currency mismatch fails');
    assert_equals('pending', $a166State('currency'), 'A16.6 HTTP: currency mismatch cannot pay');
    assert_equals([400, ['error' => 'Invalid notification']], $a166Notify('&project_id=1212&payment_uid=T166-none-0000&type=state_changed'), 'A16.6 HTTP: uid unknown to ThePay is rejected');
    assert_equals([400, ['error' => 'Invalid notification']], $a166Notify(''), 'A16.6 HTTP: notification without references is rejected');
    assert_equals([200, ['status' => 'ok']], $a166Notify($a166Q('failed')), 'A16.6 HTTP: expired notification accepted');
    assert_equals('failed', $a166State('failed'), 'A16.6 HTTP: expired payment fails the pending order');
    assert_equals(500, $a166Notify($a166Q('outage'))[0], 'A16.6 HTTP: ThePay outage answers 500 so ThePay retries');
    assert_equals('pending', $a166State('outage'), 'A16.6 HTTP: outage changes nothing');
    $a166ThePay([$a166Cases['amount'] => 'paid']);
    assert_equals([200, ['status' => 'ok']], $a166Notify($a166Q('amount')), 'A16.6 HTTP: corrected retry is accepted');
    assert_equals('paid', $a166State('amount'), 'A16.6 HTTP: corrected retry pays the order');
} finally {
    proc_terminate($a166Server);
    proc_close($a166Server);
    unset($a166State, $a166Tx);
    $a166Db = null;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a166Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $a166File) {
        $a166File->isDir() ? rmdir($a166File->getPathname()) : unlink($a166File->getPathname());
    }
    rmdir($a166Root);
}
