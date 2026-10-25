<?php

// A16.5: Comgate gateway per the official REST API v2.0 (apidoc.comgate.cz) — Basic merchant:secret,
// hosted redirect, push notifications pre-checked by merchant+secret and always confirmed by the
// /status call (the doc's own requirement), refunds. No live payments: every Comgate call goes
// through an injected transport.
echo "\n--- Audit A16.5: Comgate payment gateway ---\n";
require_once dirname(TEST_CLI_DIR) . '/adapters/PaymentAdapter.php';
require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';
$a165Ready = is_file(dirname(TEST_CLI_DIR) . '/adapters/contrib/ComgatePayment.php');
assert_true($a165Ready, 'A16.5 Comgate: adapter exists');
assert_equals('ComgatePayment', Adapters::registry('payment')['comgate']['class'] ?? null, 'A16.5 Comgate: built-in provider code comgate is registered');

$a165Cfg = ['comgate_merchant' => '465123', 'comgate_secret' => 'a165-secret-Xy9', 'comgate_test' => true]; // staging fixture: test payments
$a165Status = static fn(string $transId, string $status, string $price = '1990', string $curr = 'EUR', string $test = 'true', ?string $refId = null): array => ['status' => 200, 'body' => json_encode([
    'code' => 0, 'message' => 'OK', 'test' => $test, 'price' => $price, 'curr' => $curr, 'label' => 'A165', 'refId' => $refId ?? 'A165-' . $transId,
    'method' => 'CARD_CZ_CSOB_2', 'email' => 'buyer@example.com', 'transId' => $transId, 'status' => $status])];
$a165Calls = [];
$a165Answers = [];
$a165Transport = static function (string $method, string $url, array $headers, ?string $body) use (&$a165Calls, &$a165Answers): array {
    $a165Calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    $path = (string)parse_url($url, PHP_URL_PATH);
    if ($method === 'POST' && $path === '/v2.0/payment.json') return $a165Answers['create'] ?? ['status' => 201, 'body' => '{"code":0,"message":"OK","transId":"AB12-CD34-EF56","redirect":"https://payments.comgate.cz/client/instructions/index?id=AB12-CD34-EF56"}'];
    if ($method === 'POST' && $path === '/v2.0/refund.json') return $a165Answers['refund'] ?? ['status' => 200, 'body' => '{"code":0,"message":"OK"}'];
    if ($method === 'GET' && preg_match('#^/v2\.0/payment/transId/([^/]+)\.json$#', $path, $m)) return $a165Answers[$m[1]] ?? ['status' => 200, 'body' => '{"code":1400,"message":"Bad request"}'];
    return ['status' => 404, 'body' => ''];
};
$a165Throws = static function (callable $fn, string $class = 'Throwable'): bool {
    try { $fn(); } catch (Throwable $e) { return $e instanceof $class; }
    return false;
};
$a165Header = static function (array $call, string $name): string {
    foreach ($call['headers'] ?? [] as $header) {
        [$key, $value] = array_pad(explode(':', (string)$header, 2), 2, '');
        if (strcasecmp(trim($key), $name) === 0) return trim($value);
    }
    return '';
};
$a165Push = static fn(string $transId, string $status, array $override = []): array => array_replace([
    'merchant' => '465123', 'test' => 'true', 'price' => '1990', 'curr' => 'EUR', 'label' => 'A165', 'refId' => 'A165-' . $transId,
    'transId' => $transId, 'secret' => 'a165-secret-Xy9', 'status' => $status, 'email' => 'buyer@example.com', 'full_name' => 'A165 Buyer'], $override);

if ($a165Ready) {
    $a165Class = Adapters::providerClass('payment', 'comgate');
    assert_true(is_subclass_of($a165Class, HostedCheckoutAdapter::class) && is_subclass_of($a165Class, PullVerifiedNotificationAdapter::class), 'A16.5 Comgate: hosted redirect + status-verified notification adapter');
    assert_true(!is_subclass_of($a165Class, IdempotentRefundAdapter::class), 'A16.5 Comgate: refund refId is not unique per Comgate, so idempotent refunds are not claimed');
    assert_equals(['https://payments.comgate.cz'], (new ComgatePayment([]))->redirectOrigins(), 'A16.5 Comgate: payment gateway origin');

    // Payment creation per POST /v2.0/payment.json.
    $a165Gateway = new ComgatePayment($a165Cfg, $a165Transport);
    $a165Order = ['order_number' => 'A165-1', 'total' => 1990, 'currency' => 'eur', 'customer_email' => 'buyer@example.com', 'customer_name' => 'Jana Nováková', 'locale' => 'sk',
        'success_url' => 'https://shop.example/?page=order-done&order=A165-1&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => 'https://shop.example/?page=checkout-cancel&order=A165-1',
        'notify_url' => 'https://shop.example/api/v1/webhooks/payment/comgate'];
    $a165Session = $a165Gateway->createSession($a165Order);
    assert_equals(['url' => 'https://payments.comgate.cz/client/instructions/index?id=AB12-CD34-EF56', 'session_id' => 'AB12-CD34-EF56'], $a165Session, 'A16.5 Comgate create: redirect URL, transId kept as session');
    $a165Create = $a165Calls[0] ?? ['method' => '', 'url' => '', 'headers' => [], 'body' => ''];
    assert_equals(['POST', 'https://payments.comgate.cz/v2.0/payment.json', 'Basic ' . base64_encode('465123:a165-secret-Xy9'), 'application/json'],
        [$a165Create['method'], $a165Create['url'], $a165Header($a165Create, 'Authorization'), $a165Header($a165Create, 'Content-Type')],
        'A16.5 Comgate create: POST /v2.0/payment.json, Basic merchant:secret, JSON');
    $a165Body = json_decode((string)$a165Create['body'], true) ?: [];
    assert_equals([true, 1990, 'EUR', 'A165-1', 'A165-1', 'ALL', 'buyer@example.com', 'Jana Nováková', 'sk'],
        [$a165Body['test'] ?? null, $a165Body['price'] ?? null, $a165Body['curr'] ?? null, $a165Body['label'] ?? null, $a165Body['refId'] ?? null, $a165Body['method'] ?? null, $a165Body['email'] ?? null, $a165Body['fullName'] ?? null, $a165Body['lang'] ?? null],
        'A16.5 Comgate create: test flag, price in minor units, ISO currency, label, refId = order number, method ALL, payer e-mail/name, language');
    assert_equals(['https://shop.example/?page=order-done&order=A165-1&session_id=', 'https://shop.example/?page=checkout-cancel&order=A165-1', 'https://shop.example/?page=order-done&order=A165-1&session_id='],
        [$a165Body['url_paid'] ?? null, $a165Body['url_cancelled'] ?? null, $a165Body['url_pending'] ?? null], 'A16.5 Comgate create: paid/cancelled/pending return URLs');
    assert_true(!array_key_exists('secret', $a165Body) && !array_key_exists('merchant', $a165Body), 'A16.5 Comgate create: credentials only in the Authorization header');
    $a165Calls = [];
    (new ComgatePayment($a165Cfg, $a165Transport))->createSession(['order_number' => 'A165-LONG-ORDER-NUMBER-1', 'locale' => 'xx'] + $a165Order);
    $a165Body = json_decode((string)($a165Calls[0]['body'] ?? ''), true) ?: [];
    assert_true(mb_strlen((string)($a165Body['label'] ?? '')) === 16 && ($a165Body['refId'] ?? '') === 'A165-LONG-ORDER-NUMBER-1' && !isset($a165Body['lang']), 'A16.5 Comgate create: label cut to 16 characters, full order number in refId, unsupported language left to Comgate');
    $a165Calls = [];
    (new ComgatePayment(['comgate_test' => false] + $a165Cfg, $a165Transport))->createSession($a165Order);
    assert_equals(false, (json_decode((string)($a165Calls[0]['body'] ?? ''), true) ?: [])['test'] ?? null, 'A16.5 Comgate create: comgate_test=false creates production payments');

    // Fail closed before any HTTP call / on refused or foreign answers.
    foreach (['comgate_merchant', 'comgate_secret'] as $a165Missing) {
        $a165Calls = [];
        $a165Cfg2 = $a165Cfg;
        unset($a165Cfg2[$a165Missing]);
        assert_true($a165Throws(static fn() => (new ComgatePayment($a165Cfg2, $a165Transport))->createSession($a165Order)) && $a165Calls === [], "A16.5 Comgate: missing {$a165Missing} refuses to create a payment without calling Comgate");
    }
    $a165Calls = [];
    assert_true($a165Throws(static fn() => (new ComgatePayment($a165Cfg, $a165Transport))->createSession(['success_url' => ''] + $a165Order)) && $a165Calls === [], 'A16.5 Comgate: payment without a return URL is refused');
    $a165Answers['create'] = ['status' => 200, 'body' => '{"code":1309,"message":"incorrect amount"}'];
    assert_true($a165Throws(static fn() => (new ComgatePayment($a165Cfg, $a165Transport))->createSession($a165Order)), 'A16.5 Comgate: create with code != 0 is an error');
    $a165Answers['create'] = ['status' => 201, 'body' => '{"code":0,"message":"OK","transId":"AB12-CD34-EF57","redirect":"https://pay.attacker.example/x"}'];
    assert_true($a165Throws(static fn() => (new ComgatePayment($a165Cfg, $a165Transport))->createSession($a165Order)), 'A16.5 Comgate: redirect outside the declared gateway origin is refused');
    unset($a165Answers['create']);

    // Push notifications: merchant+secret pre-check, then the event comes from the /status call.
    $a165Answers['AB12-CD34-EF56'] = $a165Status('AB12-CD34-EF56', 'PAID');
    $a165Calls = [];
    $a165Event = (new ComgatePayment($a165Cfg, $a165Transport))->verifiedEvent($a165Push('AB12-CD34-EF56', 'PAID', ['price' => '1', 'curr' => 'CZK']));
    assert_equals(['payment.paid', 'AB12-CD34-EF56:PAID', 'A165-AB12-CD34-EF56', 'AB12-CD34-EF56', 1990, 'eur'],
        [$a165Event['event_type'] ?? '', $a165Event['event_id'] ?? '', $a165Event['data']['order_number'] ?? '', $a165Event['data']['payment_id'] ?? '', $a165Event['data']['amount'] ?? 0, $a165Event['data']['currency'] ?? ''],
        'A16.5 Comgate push: PAID status call becomes payment.paid with the status amount/currency (push values ignored)');
    $a165StatusCall = $a165Calls[0] ?? ['method' => '', 'url' => '', 'headers' => []];
    assert_equals(['GET', 'https://payments.comgate.cz/v2.0/payment/transId/AB12-CD34-EF56.json', 'Basic ' . base64_encode('465123:a165-secret-Xy9')],
        [$a165StatusCall['method'], $a165StatusCall['url'], $a165Header($a165StatusCall, 'Authorization')], 'A16.5 Comgate push: verified by GET /v2.0/payment/transId/{transId}.json');
    foreach (['CANCELLED' => 'payment.failed', 'AUTHORIZED' => 'payment.authorized', 'PENDING' => 'payment.pending'] as $a165State => $a165Type) {
        $a165Answers['AB12-CD34-EF56'] = $a165Status('AB12-CD34-EF56', $a165State);
        assert_equals($a165Type, (new ComgatePayment($a165Cfg, $a165Transport))->verifiedEvent($a165Push('AB12-CD34-EF56', 'PAID'))['event_type'] ?? '', "A16.5 Comgate push: status {$a165State} maps to {$a165Type} whatever the push claims");
    }
    $a165Answers['AB12-CD34-EF56'] = $a165Status('AB12-CD34-EF56', 'PAID');
    foreach (['wrong secret' => ['secret' => 'a165-secret-Xy8'], 'no secret' => ['secret' => null], 'other merchant' => ['merchant' => '465124'], 'array secret' => ['secret' => ['a165-secret-Xy9']]] as $a165Case => $a165Override) {
        $a165Calls = [];
        $a165Notification = array_filter($a165Push('AB12-CD34-EF56', 'PAID', $a165Override), static fn($v) => $v !== null);
        assert_true($a165Throws(static fn() => (new ComgatePayment($a165Cfg, $a165Transport))->verifiedEvent($a165Notification), PaymentNotificationUnverified::class) && $a165Calls === [], "A16.5 Comgate push: {$a165Case} refused without a status call");
    }
    foreach (['', '../refund', 'AB12 CD34', str_repeat('A', 65)] as $a165BadId) {
        $a165Calls = [];
        assert_true($a165Throws(static fn() => (new ComgatePayment($a165Cfg, $a165Transport))->verifiedEvent($a165Push($a165BadId, 'PAID')), PaymentNotificationUnverified::class) && $a165Calls === [], 'A16.5 Comgate push: malformed transId ' . var_export(substr($a165BadId, 0, 12), true) . ' refused without a status call');
    }
    assert_true($a165Throws(static fn() => (new ComgatePayment($a165Cfg, $a165Transport))->verifiedEvent($a165Push('ZZ99-ZZ99-ZZ99', 'PAID')), PaymentNotificationUnverified::class), 'A16.5 Comgate push: transId Comgate does not know (code 1400) is unverified');
    $a165Answers['AB12-CD34-EF56'] = ['status' => 404, 'body' => '{"code":1400,"message":"Bad request"}'];
    assert_true($a165Throws(static fn() => (new ComgatePayment($a165Cfg, $a165Transport))->verifiedEvent($a165Push('AB12-CD34-EF56', 'PAID')), PaymentNotificationUnverified::class), 'A16.5 Comgate push: HTTP 404 status answer is unverified');
    $a165Answers['AB12-CD34-EF56'] = ['status' => 200, 'body' => json_encode(['code' => 0, 'status' => 'PAID', 'price' => '1990', 'curr' => 'EUR', 'test' => 'true', 'refId' => 'A165-1', 'transId' => 'AB12-CD34-EF99'])];
    assert_true($a165Throws(static fn() => (new ComgatePayment($a165Cfg, $a165Transport))->verifiedEvent($a165Push('AB12-CD34-EF56', 'PAID')), PaymentNotificationUnverified::class), 'A16.5 Comgate push: status about a different transaction is unverified');
    $a165Answers['AB12-CD34-EF56'] = $a165Status('AB12-CD34-EF56', 'PAID', '1990', 'EUR', 'true');
    assert_true($a165Throws(static fn() => (new ComgatePayment(['comgate_test' => false] + $a165Cfg, $a165Transport))->verifiedEvent($a165Push('AB12-CD34-EF56', 'PAID', ['test' => 'false'])), PaymentNotificationUnverified::class), 'A16.5 Comgate push: a test payment never pays an order of a production shop');
    $a165Answers['AB12-CD34-EF56'] = $a165Status('AB12-CD34-EF56', 'PAID', '19.90');
    assert_true($a165Throws(static fn() => (new ComgatePayment($a165Cfg, $a165Transport))->verifiedEvent($a165Push('AB12-CD34-EF56', 'PAID')), PaymentNotificationUnverified::class), 'A16.5 Comgate push: non-integer status price is unverified');
    foreach (['HTTP 503' => ['status' => 503, 'body' => ''], 'code 1500' => ['status' => 200, 'body' => '{"code":1500,"message":"unexpected error"}'], 'HTTP 401 (shop credentials)' => ['status' => 401, 'body' => '{"code":1400}']] as $a165Case => $a165Answer) {
        $a165Answers['AB12-CD34-EF56'] = $a165Answer;
        assert_true($a165Throws(static fn() => (new ComgatePayment($a165Cfg, $a165Transport))->verifiedEvent($a165Push('AB12-CD34-EF56', 'PAID')))
            && !$a165Throws(static fn() => (new ComgatePayment($a165Cfg, $a165Transport))->verifiedEvent($a165Push('AB12-CD34-EF56', 'PAID')), PaymentNotificationUnverified::class), "A16.5 Comgate push: {$a165Case} stays retryable (not unverified)");
    }
    $a165Answers['AB12-CD34-EF56'] = $a165Status('AB12-CD34-EF56', 'PAID');
    assert_equals('paid', (new ComgatePayment($a165Cfg, $a165Transport))->getStatus('AB12-CD34-EF56'), 'A16.5 Comgate status: status of the transaction');

    // Refunds: POST /v2.0/refund.json, code 0 = succeeded.
    $a165Calls = [];
    $a165Refund = (new ComgatePayment($a165Cfg, $a165Transport))->refund('AB12-CD34-EF56', 500);
    $a165RefundCall = end($a165Calls) ?: ['method' => '', 'url' => '', 'headers' => [], 'body' => ''];
    assert_equals(['POST', 'https://payments.comgate.cz/v2.0/refund.json', 'application/json', ['transId' => 'AB12-CD34-EF56', 'amount' => 500, 'test' => true]],
        [$a165RefundCall['method'], $a165RefundCall['url'], $a165Header($a165RefundCall, 'Content-Type'), json_decode((string)$a165RefundCall['body'], true)], 'A16.5 Comgate refund: POST JSON transId/amount/test to /v2.0/refund.json');
    assert_equals('succeeded', $a165Refund['status'] ?? '', 'A16.5 Comgate refund: code 0 is a succeeded refund');
    $a165Answers['refund'] = ['status' => 200, 'body' => '{"code":1402,"message":"refund amount higher than allowed"}'];
    assert_equals('failed', (new ComgatePayment($a165Cfg, $a165Transport))->refund('AB12-CD34-EF56', 500)['status'] ?? '', 'A16.5 Comgate refund: code 1402 is not reported as succeeded');
    unset($a165Answers['refund']);
    $a165Calls = [];
    assert_true($a165Throws(static fn() => (new ComgatePayment($a165Cfg, $a165Transport))->refund('AB12-CD34-EF56', 0)) && $a165Calls === [], 'A16.5 Comgate refund: non-positive amount refused');
}

// The real checkout: Comgate needs the payer's full name, which checkout now passes to every provider.
ConsentService::setDefinitionsForTesting([]);
$pdo->exec("INSERT INTO categories (store_id, name, slug, path) VALUES ('default', 'A165', 'a165-category', 'a165-category')");
$a165Category = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO products (category_id, name, slug, sku, product_type, price, currency, status, stock)
    VALUES ({$a165Category}, 'A165 guide', 'a165-guide', 'A165-GUIDE', 'downloadable', 1990, 'eur', 'active', 0)");
$a165Product = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
    VALUES ('A165-GUIDE', 'pdf', 'PDF', 'guide.pdf', 'guide.pdf', 'application/pdf', 2, 3600, 1)");
$pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
    VALUES ('a165_comgate', 'comgate', 'Comgate', '', 'online_card', 0, NULL, 0, 0, NULL, 1, 1, 99)");
$pdo->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('a165_comgate', 'SK')");
$a165Instances = new ReflectionProperty(Adapters::class, 'instances');
$a165PrevInstances = $a165Instances->getValue();
$a165Calls = [];
$a165Answers = [];
if ($a165Ready) $a165Instances->setValue(null, ['payment' => new ComgatePayment($a165Cfg, $a165Transport)] + $a165PrevInstances);
try {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Cart::clear();
    Cart::add($a165Product, null, 1);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.16.5';
    $_SESSION['csrf_token'] = 'a165-checkout';
    $_POST = ['csrf_token' => 'a165-checkout', 'name' => 'A165 Buyer', 'email' => 'a165@example.com', 'country' => 'SK', 'shipping_method' => 'digital', 'payment_method' => 'a165_comgate', 'consents' => ['digital_delivery' => true]];
    $a165Result = apiCheckout(['app_url' => 'https://shop.example'] + (require dirname(TEST_CLI_DIR) . '/config.php'));
    $_POST = [];
    $a165Create = array_values(array_filter($a165Calls, static fn($c) => $c['method'] === 'POST' && str_ends_with($c['url'], '/v2.0/payment.json')))[0] ?? null;
    $a165Body = json_decode((string)($a165Create['body'] ?? ''), true) ?: [];
    $a165OrderRow = $pdo->query("SELECT order_number, payment_id FROM orders WHERE id = " . (int)($a165Result['order_id'] ?? 0))->fetch() ?: ['order_number' => '', 'payment_id' => ''];
    assert_true(!empty($a165Result['success']) && empty($a165Result['payment_error']), 'A16.5 checkout: Comgate order is created with a payment session (' . ($a165Result['payment_error'] ?? $a165Result['error'] ?? 'ok') . ')');
    assert_equals(['A165 Buyer', 'a165@example.com', $a165OrderRow['order_number']], [$a165Body['fullName'] ?? null, $a165Body['email'] ?? null, $a165Body['refId'] ?? null], 'A16.5 checkout: payer name, e-mail and order number are sent to Comgate');
    assert_equals('https://payments.comgate.cz/client/instructions/index?id=AB12-CD34-EF56', $a165Result['payment_url'] ?? null, 'A16.5 checkout: customer is sent to the Comgate gateway');
    assert_equals('AB12-CD34-EF56', (string)$a165OrderRow['payment_id'], 'A16.5 checkout: Comgate transId stored on the order');
} finally {
    $_POST = [];
    $a165Instances->setValue(null, $a165PrevInstances);
    Cart::clear();
    JobQueue::pull(500);
    $pdo->exec("DELETE FROM payment_method_countries WHERE method_code = 'a165_comgate'");
    $pdo->exec("DELETE FROM payment_methods WHERE code = 'a165_comgate'");
}

// The real notification endpoint over HTTP: JSON push (REST v2.0) and form push (HTTP POST v1.0),
// each confirmed against a fake Comgate /status API.
$a165Root = test_fixture_path('comgate-notify');
mkdir($a165Root . '/src/Http', 0700, true);
file_put_contents($a165Root . '/config.php', '<?php return ' . var_export($a165Cfg, true) . ';');
copy(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php', $a165Root . '/src/Http/ApiHandlers.php');
file_put_contents($a165Root . '/statuses.json', '{}');
$a165Snapshot = new PDO('sqlite:' . $testDbPath);
$a165Snapshot->exec('VACUUM INTO ' . $a165Snapshot->quote($a165Root . '/shop.sqlite'));
$a165Snapshot = null;
$a165Db = new PDO('sqlite:' . $a165Root . '/shop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$a165Template = $a165Db->query('SELECT * FROM orders LIMIT 1')->fetch();
unset($a165Template['id']);
$a165Ids = [];
$a165Cases = ['paid' => 'C165-0001-PAID', 'forged' => 'C165-0002-PEND', 'secret' => 'C165-0003-SECR', 'amount' => 'C165-0004-AMNT', 'currency' => 'C165-0005-CURR', 'failed' => 'C165-0006-CANC', 'outage' => 'C165-0007-DOWN', 'form' => 'C165-0008-FORM'];
foreach ($a165Cases as $a165Case => $a165Tid) {
    $a165Row = array_replace($a165Template, ['order_number' => 'A165-' . $a165Tid, 'status' => 'pending', 'payment_status' => 'pending', 'payment_id' => $a165Tid, 'total' => 1990, 'currency' => 'eur', 'store_id' => 'default', 'payment_method_snapshot' => json_encode(['provider' => 'comgate'])]);
    $a165Db->prepare('INSERT INTO orders (' . implode(',', array_keys($a165Row)) . ') VALUES (' . implode(',', array_fill(0, count($a165Row), '?')) . ')')->execute(array_values($a165Row));
    $a165Ids[$a165Case] = (int)$a165Db->lastInsertId();
}
file_put_contents($a165Root . '/router.php', '<?php $a165App = ' . var_export(dirname(TEST_CLI_DIR), true) . ";\n" . <<<'PHP'
require $a165App . '/src/autoload.php';
require $a165App . '/adapters/PaymentAdapter.php';
require __DIR__ . '/src/Http/ApiHandlers.php';
$cfg = require __DIR__ . '/config.php';
Db::init(new PDO('sqlite:' . __DIR__ . '/shop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]), 'sqlite');
JobQueue::setDir(__DIR__ . '/jobs');
Bootstrap::initStoreContext('/', '', 'default');
$transport = static function (string $method, string $url, array $headers, ?string $body): array {
    file_put_contents(__DIR__ . '/calls.log', $method . ' ' . $url . "\n", FILE_APPEND);
    $statuses = json_decode((string)file_get_contents(__DIR__ . '/statuses.json'), true);
    preg_match('#/v2\.0/payment/transId/([^/]+)\.json$#', (string)parse_url($url, PHP_URL_PATH), $m);
    return $statuses[$m[1] ?? ''] ?? ['status' => 200, 'body' => '{"code":1400,"message":"Bad request"}'];
};
if (class_exists('ComgatePayment')) (new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new ComgatePayment($cfg, $transport)]);
header('Content-Type: application/json');
echo json_encode(apiWebhookPayment());
PHP);
$a165Socket = stream_socket_server('tcp://127.0.0.1:0');
$a165Address = stream_socket_get_name($a165Socket, false);
fclose($a165Socket);
$a165Server = proc_open([PHP_BINARY, '-S', $a165Address, $a165Root . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $a165Root . '/server.log', 'a'], 2 => ['file', $a165Root . '/server.log', 'a']], $a165Pipes);
fclose($a165Pipes[0]);
try {
    $a165Up = false;
    for ($a165Try = 0; $a165Try < 50 && !$a165Up; $a165Try++) {
        $a165Probe = @stream_socket_client('tcp://' . $a165Address, $a165Errno, $a165Error, 0.1);
        if ($a165Probe) { fclose($a165Probe); $a165Up = true; } else usleep(100000);
    }
    assert_true($a165Up, 'A16.5 HTTP: isolated notification fixture starts');
    $a165Comgate = static function (array $statuses) use ($a165Root, $a165Status): void {
        $answers = [];
        foreach ($statuses as $tid => $answer) $answers[$tid] = is_array($answer) ? $answer : $a165Status($tid, $answer, '1990', 'EUR', 'true', 'A165-' . $tid);
        file_put_contents($a165Root . '/statuses.json', json_encode($answers));
    };
    $a165Notify = static function (array $push, bool $form = false) use ($a165Address): array {
        $body = $form ? http_build_query($push) : json_encode($push);
        $type = $form ? 'application/x-www-form-urlencoded' : 'application/json';
        $stream = @fopen('http://' . $a165Address . '/?action=webhook_payment&provider=comgate', 'r', false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: {$type}\r\n", 'content' => $body, 'ignore_errors' => true, 'timeout' => 10]]));
        if (!$stream) return [0, null];
        $response = stream_get_contents($stream);
        preg_match('/\s(\d{3})\s/', stream_get_meta_data($stream)['wrapper_data'][0] ?? '', $status);
        fclose($stream);
        return [(int)($status[1] ?? 0), json_decode((string)$response, true)];
    };
    $a165State = static fn(string $case): string => (string)$a165Db->query('SELECT payment_status FROM orders WHERE id = ' . $a165Ids[$case])->fetchColumn();
    $a165Tx = static fn(string $case): int => (int)$a165Db->query('SELECT COUNT(*) FROM payment_transactions WHERE order_id = ' . $a165Ids[$case])->fetchColumn();
    $a165P = static fn(string $case, string $status, array $override = []): array => $a165Push($a165Cases[$case], $status, $override);

    $a165Comgate([$a165Cases['paid'] => 'PAID', $a165Cases['forged'] => 'PENDING', $a165Cases['secret'] => 'PAID',
        $a165Cases['amount'] => $a165Status($a165Cases['amount'], 'PAID', '1000', 'EUR', 'true', 'A165-' . $a165Cases['amount']),
        $a165Cases['currency'] => $a165Status($a165Cases['currency'], 'PAID', '1990', 'CZK', 'true', 'A165-' . $a165Cases['currency']),
        $a165Cases['failed'] => 'CANCELLED', $a165Cases['outage'] => ['status' => 503, 'body' => ''], $a165Cases['form'] => 'PAID']);
    assert_equals([200, ['status' => 'ok']], $a165Notify($a165P('paid', 'PAID')), 'A16.5 HTTP: JSON push for a PAID payment is accepted');
    assert_equals('paid', $a165State('paid'), 'A16.5 HTTP: confirmed PAID marks the order paid');
    $a165PaidTx = $a165Tx('paid');
    assert_equals([200, ['status' => 'already_processed']], $a165Notify($a165P('paid', 'PAID')), 'A16.5 HTTP: repeated push deduplicates');
    assert_equals($a165PaidTx, $a165Tx('paid'), 'A16.5 HTTP: replay writes no payment transaction');
    assert_equals(200, $a165Notify($a165P('forged', 'PAID'))[0], 'A16.5 HTTP: push claiming PAID for a pending payment is acknowledged');
    assert_equals('pending', $a165State('forged'), 'A16.5 HTTP: push body never pays an order Comgate reports as PENDING');
    assert_equals([400, ['error' => 'Invalid notification']], $a165Notify($a165P('secret', 'PAID', ['secret' => 'guessed'])), 'A16.5 HTTP: push with a wrong secret is rejected');
    assert_equals('pending', $a165State('secret'), 'A16.5 HTTP: wrong secret cannot pay');
    assert_equals(500, $a165Notify($a165P('amount', 'PAID'))[0], 'A16.5 HTTP: amount mismatch fails for retry');
    assert_equals('pending', $a165State('amount'), 'A16.5 HTTP: amount mismatch cannot pay');
    assert_equals(0, (int)$a165Db->query("SELECT COUNT(*) FROM processed_events WHERE event_id = 'payment_comgate_" . $a165Cases['amount'] . ":PAID'")->fetchColumn(), 'A16.5 HTTP: failed claim is released');
    assert_equals(500, $a165Notify($a165P('currency', 'PAID'))[0], 'A16.5 HTTP: currency mismatch fails');
    assert_equals('pending', $a165State('currency'), 'A16.5 HTTP: currency mismatch cannot pay');
    assert_equals([400, ['error' => 'Invalid notification']], $a165Notify($a165Push('ZZ99-ZZ99-ZZ99', 'PAID')), 'A16.5 HTTP: transId unknown to Comgate is rejected');
    assert_equals([400, ['error' => 'Invalid notification']], $a165Notify([]), 'A16.5 HTTP: empty push is rejected');
    assert_equals([200, ['status' => 'ok']], $a165Notify($a165P('failed', 'CANCELLED')), 'A16.5 HTTP: CANCELLED push accepted');
    assert_equals('failed', $a165State('failed'), 'A16.5 HTTP: CANCELLED payment fails the pending order');
    assert_equals(500, $a165Notify($a165P('outage', 'PAID'))[0], 'A16.5 HTTP: Comgate outage answers 500 so Comgate retries');
    assert_equals('pending', $a165State('outage'), 'A16.5 HTTP: outage changes nothing');
    assert_equals([200, ['status' => 'ok']], $a165Notify($a165P('form', 'PAID'), true), 'A16.5 HTTP: form-encoded push (HTTP POST protocol) is accepted');
    assert_equals('paid', $a165State('form'), 'A16.5 HTTP: confirmed form push pays the order');
    $a165Comgate([$a165Cases['amount'] => 'PAID']);
    assert_equals([200, ['status' => 'ok']], $a165Notify($a165P('amount', 'PAID')), 'A16.5 HTTP: corrected retry is accepted');
    assert_equals('paid', $a165State('amount'), 'A16.5 HTTP: corrected retry pays the order');
} finally {
    proc_terminate($a165Server);
    proc_close($a165Server);
    unset($a165State, $a165Tx);
    $a165Db = null;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a165Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $a165File) {
        $a165File->isDir() ? rmdir($a165File->getPathname()) : unlink($a165File->getPathname());
    }
    rmdir($a165Root);
}
