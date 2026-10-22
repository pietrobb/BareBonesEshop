<?php

// A16.4: GoPay gateway per doc.gopay.com — OAuth2 client_credentials, hosted gw_url redirect,
// unsigned GET notifications verified by an authenticated inquiry, refunds. No live payments:
// every GoPay call goes through an injected transport.
echo "\n--- Audit A16.4: GoPay payment gateway ---\n";
require_once dirname(TEST_CLI_DIR) . '/adapters/PaymentAdapter.php';
require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';
$a164Ready = is_file(dirname(TEST_CLI_DIR) . '/adapters/contrib/GoPayPayment.php') && interface_exists('PullVerifiedNotificationAdapter');
assert_true($a164Ready, 'A16.4 GoPay: adapter and pull-verified notification contract exist');
$a164Registry = Adapters::registry('payment');
assert_equals('GoPayPayment', $a164Registry['gopay']['class'] ?? null, 'A16.4 GoPay: built-in provider code gopay is registered');

$a164Cfg = ['gopay_goid' => '8123456789', 'gopay_client_id' => 'a164-client', 'gopay_client_secret' => 'a164-secret'];
$a164Payment = static fn(int $id, string $state, int $amount = 1990, string $currency = 'EUR', string $goid = '8123456789'): array => ['status' => 200, 'body' => json_encode(['id' => $id, 'order_number' => 'A164-' . $id, 'state' => $state, 'amount' => $amount, 'currency' => $currency, 'target' => ['type' => 'ACCOUNT', 'goid' => (int)$goid]])];
$a164Calls = [];
$a164Answers = [];
$a164Transport = static function (string $method, string $url, array $headers, ?string $body) use (&$a164Calls, &$a164Answers): array {
    $a164Calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (str_ends_with($path, '/oauth2/token')) return $a164Answers['token'] ?? ['status' => 200, 'body' => '{"token_type":"bearer","access_token":"a164-token","expires_in":1800}'];
    if ($method === 'POST' && str_ends_with($path, '/payments/payment')) return $a164Answers['create'] ?? ['status' => 200, 'body' => '{"id":3123456789,"order_number":"A164-1","state":"CREATED","gw_url":"https://gw.sandbox.gopay.com/gw/v3/a164"}'];
    if ($method === 'POST' && str_ends_with($path, '/refund')) return $a164Answers['refund'] ?? ['status' => 200, 'body' => '{"id":3123456789,"result":"FINISHED"}'];
    return $a164Answers[basename($path)] ?? ['status' => 404, 'body' => '{"errors":[{"error_code":404}]}'];
};
$a164Throws = static function (callable $fn, string $class = 'Throwable'): bool {
    try { $fn(); } catch (Throwable $e) { return $e instanceof $class; }
    return false;
};
$a164Header = static fn(array $call, string $name): string => (string)(preg_grep('/^' . preg_quote($name, '/') . ':/i', $call['headers']) ? trim(explode(':', (string)array_values(preg_grep('/^' . preg_quote($name, '/') . ':/i', $call['headers']))[0], 2)[1]) : '');

if ($a164Ready) {
    $a164Class = Adapters::providerClass('payment', 'gopay');
    assert_true(is_subclass_of($a164Class, HostedCheckoutAdapter::class) && is_subclass_of($a164Class, PullVerifiedNotificationAdapter::class), 'A16.4 GoPay: hosted redirect + pull-verified notification adapter');
    assert_true(!is_subclass_of($a164Class, IdempotentRefundAdapter::class), 'A16.4 GoPay: no idempotency key in the GoPay refund API, so idempotent refunds are not claimed');
    assert_equals(['https://gw.sandbox.gopay.com'], (new GoPayPayment([]))->redirectOrigins(), 'A16.4 GoPay: sandbox gateway origin by default');
    assert_equals(['https://gate.gopay.cz'], (new GoPayPayment(['gopay_env' => 'production']))->redirectOrigins(), 'A16.4 GoPay: production gateway origin');

    // Payment creation: token request, then the payment body from the official contract.
    $a164Gateway = new GoPayPayment($a164Cfg, $a164Transport);
    $a164Order = ['order_number' => 'A164-1', 'total' => 1990, 'currency' => 'eur', 'customer_email' => 'buyer@example.com',
        'success_url' => 'https://shop.example/?page=order-done&order=A164-1&session_id={CHECKOUT_SESSION_ID}',
        'notify_url' => 'https://shop.example/api/v1/webhooks/payment/gopay'];
    $a164Session = $a164Gateway->createSession($a164Order);
    assert_equals(['url' => 'https://gw.sandbox.gopay.com/gw/v3/a164', 'session_id' => '3123456789'], $a164Session, 'A16.4 GoPay create: redirect to gw_url, GoPay payment id kept as session');
    assert_equals(['POST', 'https://gw.sandbox.gopay.com/api/oauth2/token'], [$a164Calls[0]['method'] ?? '', $a164Calls[0]['url'] ?? ''], 'A16.4 GoPay token: POST /api/oauth2/token on the sandbox');
    assert_equals('Basic ' . base64_encode('a164-client:a164-secret'), $a164Header($a164Calls[0] ?? ['headers' => []], 'Authorization'), 'A16.4 GoPay token: Basic ClientID:ClientSecret');
    assert_equals('scope=payment-all&grant_type=client_credentials', $a164Calls[0]['body'] ?? '', 'A16.4 GoPay token: form scope=payment-all, grant_type=client_credentials');
    assert_equals(['POST', 'https://gw.sandbox.gopay.com/api/payments/payment', 'Bearer a164-token', 'application/json'],
        [$a164Calls[1]['method'] ?? '', $a164Calls[1]['url'] ?? '', $a164Header($a164Calls[1] ?? ['headers' => []], 'Authorization'), $a164Header($a164Calls[1] ?? ['headers' => []], 'Content-Type')],
        'A16.4 GoPay create: POST /api/payments/payment with the bearer token as JSON');
    $a164Body = json_decode((string)($a164Calls[1]['body'] ?? ''), true) ?: [];
    assert_equals([1990, 'EUR', 'A164-1', ['type' => 'ACCOUNT', 'goid' => 8123456789], 'buyer@example.com'],
        [$a164Body['amount'] ?? null, $a164Body['currency'] ?? null, $a164Body['order_number'] ?? null, $a164Body['target'] ?? null, $a164Body['payer']['contact']['email'] ?? null],
        'A16.4 GoPay create: amount in minor units, upper-case currency, order number, ACCOUNT target GoID, payer e-mail');
    assert_equals(['return_url' => 'https://shop.example/?page=order-done&order=A164-1&session_id=', 'notification_url' => 'https://shop.example/api/v1/webhooks/payment/gopay'],
        $a164Body['callback'] ?? null, 'A16.4 GoPay create: return and notification URLs');
    $a164Answers['3123456789'] = $a164Payment(3123456789, 'PAID');
    assert_equals('paid', $a164Gateway->getStatus('3123456789'), 'A16.4 GoPay status: GET /payments/payment/{id} state');
    assert_equals(1, count(array_filter($a164Calls, static fn($c) => str_ends_with($c['url'], '/oauth2/token'))), 'A16.4 GoPay token: reused until it expires');
    $a164Calls = [];
    (new GoPayPayment($a164Cfg + ['gopay_env' => 'production'], $a164Transport))->getStatus('3123456789');
    assert_equals('https://gate.gopay.cz/api/oauth2/token', $a164Calls[0]['url'] ?? '', 'A16.4 GoPay: production API base gate.gopay.cz/api');

    // Fail closed before any HTTP call.
    foreach (['gopay_goid', 'gopay_client_id', 'gopay_client_secret'] as $a164Missing) {
        $a164Calls = [];
        $a164Cfg2 = $a164Cfg;
        unset($a164Cfg2[$a164Missing]);
        assert_true($a164Throws(static fn() => (new GoPayPayment($a164Cfg2, $a164Transport))->createSession($a164Order)) && $a164Calls === [], "A16.4 GoPay: missing {$a164Missing} refuses to create a payment without calling GoPay");
    }
    $a164Calls = [];
    assert_true($a164Throws(static fn() => (new GoPayPayment($a164Cfg, $a164Transport))->createSession(['notify_url' => ''] + $a164Order)) && $a164Calls === [], 'A16.4 GoPay: payment without a notification URL is refused');
    $a164Answers['create'] = ['status' => 200, 'body' => '{"id":3123456790,"state":"CREATED","gw_url":"https://pay.attacker.example/gw"}'];
    assert_true($a164Throws(static fn() => (new GoPayPayment($a164Cfg, $a164Transport))->createSession($a164Order)), 'A16.4 GoPay: gw_url outside the declared gateway origin is refused');
    unset($a164Answers['create']);

    // Notifications: only the id is read from the request; the event comes from the inquiry.
    $a164Answers['3123456789'] = $a164Payment(3123456789, 'PAID');
    $a164Event = (new GoPayPayment($a164Cfg, $a164Transport))->verifiedEvent(['id' => '3123456789', 'state' => 'IGNORED']);
    assert_equals(['payment.paid', '3123456789:PAID', 'A164-3123456789', '3123456789', 1990, 'eur'],
        [$a164Event['event_type'] ?? '', $a164Event['event_id'] ?? '', $a164Event['data']['order_number'] ?? '', $a164Event['data']['payment_id'] ?? '', $a164Event['data']['amount'] ?? 0, $a164Event['data']['currency'] ?? ''],
        'A16.4 GoPay notify: PAID inquiry becomes payment.paid with GoPay amount/currency and a per-state event id');
    foreach (['CANCELED' => 'payment.failed', 'TIMEOUTED' => 'payment.failed', 'REFUNDED' => 'refund.reported', 'PARTIALLY_REFUNDED' => 'refund.reported', 'AUTHORIZED' => 'payment.authorized'] as $a164State => $a164Type) {
        $a164Answers['3123456789'] = $a164Payment(3123456789, $a164State);
        assert_equals($a164Type, (new GoPayPayment($a164Cfg, $a164Transport))->verifiedEvent(['id' => '3123456789'])['event_type'] ?? '', "A16.4 GoPay notify: {$a164State} maps to {$a164Type}");
    }
    foreach (['', 'abc', '0', '1 OR 1=1', '../oauth2/token'] as $a164BadId) {
        $a164Calls = [];
        assert_true($a164Throws(static fn() => (new GoPayPayment($a164Cfg, $a164Transport))->verifiedEvent(['id' => $a164BadId]), PaymentNotificationUnverified::class) && $a164Calls === [], 'A16.4 GoPay notify: malformed id ' . var_export($a164BadId, true) . ' refused without an inquiry');
    }
    assert_true($a164Throws(static fn() => (new GoPayPayment($a164Cfg, $a164Transport))->verifiedEvent(['id' => '4199999999']), PaymentNotificationUnverified::class), 'A16.4 GoPay notify: id GoPay does not know (404) is unverified');
    $a164Answers['3123456789'] = $a164Payment(3123456789, 'PAID', 1990, 'EUR', '8000000001');
    assert_true($a164Throws(static fn() => (new GoPayPayment($a164Cfg, $a164Transport))->verifiedEvent(['id' => '3123456789']), PaymentNotificationUnverified::class), 'A16.4 GoPay notify: payment of another GoID is unverified');
    $a164Answers['3123456789'] = ['status' => 200, 'body' => json_encode(['id' => 3123456780, 'state' => 'PAID', 'amount' => 1990, 'currency' => 'EUR', 'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])];
    assert_true($a164Throws(static fn() => (new GoPayPayment($a164Cfg, $a164Transport))->verifiedEvent(['id' => '3123456789']), PaymentNotificationUnverified::class), 'A16.4 GoPay notify: inquiry about a different payment is unverified');
    $a164Answers['3123456789'] = ['status' => 503, 'body' => ''];
    assert_true($a164Throws(static fn() => (new GoPayPayment($a164Cfg, $a164Transport))->verifiedEvent(['id' => '3123456789']))
        && !$a164Throws(static fn() => (new GoPayPayment($a164Cfg, $a164Transport))->verifiedEvent(['id' => '3123456789']), PaymentNotificationUnverified::class), 'A16.4 GoPay notify: GoPay outage stays retryable (not unverified)');
    $a164Answers['token'] = ['status' => 401, 'body' => '{"errors":[]}'];
    $a164Answers['3123456789'] = $a164Payment(3123456789, 'PAID');
    assert_true(!$a164Throws(static fn() => (new GoPayPayment($a164Cfg, $a164Transport))->verifiedEvent(['id' => '3123456789']), PaymentNotificationUnverified::class), 'A16.4 GoPay notify: rejected shop credentials are a retryable configuration error');
    unset($a164Answers['token']);

    // Refunds: form amount, FINISHED = succeeded for the refund worker.
    $a164Calls = [];
    $a164Refund = (new GoPayPayment($a164Cfg, $a164Transport))->refund('3123456789', 500);
    $a164RefundCall = end($a164Calls) ?: ['method' => '', 'url' => '', 'headers' => [], 'body' => ''];
    assert_equals(['POST', 'https://gw.sandbox.gopay.com/api/payments/payment/3123456789/refund', 'application/x-www-form-urlencoded', 'amount=500'],
        [$a164RefundCall['method'], $a164RefundCall['url'], $a164Header($a164RefundCall, 'Content-Type'), $a164RefundCall['body']], 'A16.4 GoPay refund: POST form amount to /refund');
    assert_equals('succeeded', $a164Refund['status'] ?? '', 'A16.4 GoPay refund: FINISHED is a succeeded refund');
    $a164Answers['refund'] = ['status' => 200, 'body' => '{"id":3123456789,"result":"FAILED"}'];
    assert_equals('failed', (new GoPayPayment($a164Cfg, $a164Transport))->refund('3123456789', 500)['status'] ?? '', 'A16.4 GoPay refund: FAILED is not reported as succeeded');
    unset($a164Answers['refund']);
    assert_true($a164Throws(static fn() => (new GoPayPayment($a164Cfg, $a164Transport))->refund('3123456789', 0)), 'A16.4 GoPay refund: non-positive amount refused');
}

// The real checkout hands every online provider its notification endpoint.
ConsentService::setDefinitionsForTesting([]);
$pdo->exec("INSERT INTO categories (store_id, name, slug, path) VALUES ('default', 'A164', 'a164-category', 'a164-category')");
$a164Category = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO products (category_id, name, slug, sku, product_type, price, currency, status, stock)
    VALUES ({$a164Category}, 'A164 guide', 'a164-guide', 'A164-GUIDE', 'downloadable', 1990, 'eur', 'active', 0)");
$a164Product = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
    VALUES ('A164-GUIDE', 'pdf', 'PDF', 'guide.pdf', 'guide.pdf', 'application/pdf', 2, 3600, 1)");
$pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
    VALUES ('a164_gopay', 'gopay', 'GoPay', '', 'online_card', 0, NULL, 0, 0, NULL, 1, 1, 99)");
$pdo->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('a164_gopay', 'SK')");
$a164Instances = new ReflectionProperty(Adapters::class, 'instances');
$a164PrevInstances = $a164Instances->getValue();
$a164Calls = [];
$a164Answers = [];
if ($a164Ready) $a164Instances->setValue(null, ['payment' => new GoPayPayment($a164Cfg, $a164Transport)] + $a164PrevInstances);
try {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Cart::clear();
    Cart::add($a164Product, null, 1);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.16.4';
    $_SESSION['csrf_token'] = 'a164-checkout';
    $_POST = ['csrf_token' => 'a164-checkout', 'name' => 'A164 Buyer', 'email' => 'a164@example.com', 'country' => 'SK', 'shipping_method' => 'digital', 'payment_method' => 'a164_gopay', 'consents' => ['digital_delivery' => true]];
    $a164Result = apiCheckout(['app_url' => 'https://shop.example'] + (require dirname(TEST_CLI_DIR) . '/config.php'));
    $_POST = [];
    $a164Create = array_values(array_filter($a164Calls, static fn($c) => $c['method'] === 'POST' && str_ends_with($c['url'], '/payments/payment')))[0] ?? null;
    $a164Callback = json_decode((string)($a164Create['body'] ?? ''), true)['callback'] ?? [];
    assert_true(!empty($a164Result['success']) && empty($a164Result['payment_error']), 'A16.4 checkout: GoPay order is created with a payment session (' . ($a164Result['payment_error'] ?? $a164Result['error'] ?? 'ok') . ')');
    assert_equals('https://shop.example/api/v1/webhooks/payment/gopay', $a164Callback['notification_url'] ?? null, 'A16.4 checkout: provider notification URL is sent to the gateway');
    assert_equals('https://gw.sandbox.gopay.com/gw/v3/a164', $a164Result['payment_url'] ?? null, 'A16.4 checkout: customer is sent to the GoPay gateway');
    assert_equals('3123456789', (string)$pdo->query("SELECT payment_id FROM orders WHERE id = " . (int)($a164Result['order_id'] ?? 0))->fetchColumn(), 'A16.4 checkout: GoPay payment id stored on the order');
} finally {
    $_POST = [];
    $a164Instances->setValue(null, $a164PrevInstances);
    Cart::clear();
    JobQueue::pull(500);
    $pdo->exec("DELETE FROM payment_method_countries WHERE method_code = 'a164_gopay'");
    $pdo->exec("DELETE FROM payment_methods WHERE code = 'a164_gopay'");
}

// The real notification endpoint over HTTP: GET ?id=…, verified against a fake GoPay inquiry API.
$a164Root = test_fixture_path('gopay-notify');
mkdir($a164Root . '/src/Http', 0700, true);
file_put_contents($a164Root . '/config.php', '<?php return ' . var_export($a164Cfg, true) . ';');
copy(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php', $a164Root . '/src/Http/ApiHandlers.php');
file_put_contents($a164Root . '/payments.json', '{}');
$a164Snapshot = new PDO('sqlite:' . $testDbPath);
$a164Snapshot->exec('VACUUM INTO ' . $a164Snapshot->quote($a164Root . '/shop.sqlite'));
$a164Snapshot = null;
$a164Db = new PDO('sqlite:' . $a164Root . '/shop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$a164Template = $a164Db->query('SELECT * FROM orders LIMIT 1')->fetch();
unset($a164Template['id']);
$a164Ids = [];
foreach (['paid' => 4100000001, 'forged' => 4100000002, 'amount' => 4100000003, 'currency' => 4100000004, 'goid' => 4100000005, 'failed' => 4100000006, 'outage' => 4100000007] as $a164Case => $a164Pid) {
    $a164Row = array_replace($a164Template, ['order_number' => 'A164-' . $a164Pid, 'status' => 'pending', 'payment_status' => 'pending', 'payment_id' => (string)$a164Pid, 'total' => 1990, 'currency' => 'eur', 'store_id' => 'default', 'payment_method_snapshot' => json_encode(['provider' => 'gopay'])]);
    $a164Db->prepare('INSERT INTO orders (' . implode(',', array_keys($a164Row)) . ') VALUES (' . implode(',', array_fill(0, count($a164Row), '?')) . ')')->execute(array_values($a164Row));
    $a164Ids[$a164Case] = (int)$a164Db->lastInsertId();
}
file_put_contents($a164Root . '/router.php', '<?php $a164App = ' . var_export(dirname(TEST_CLI_DIR), true) . ";\n" . <<<'PHP'
require $a164App . '/src/autoload.php';
require $a164App . '/adapters/PaymentAdapter.php';
require __DIR__ . '/src/Http/ApiHandlers.php';
$cfg = require __DIR__ . '/config.php';
Db::init(new PDO('sqlite:' . __DIR__ . '/shop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]), 'sqlite');
JobQueue::setDir(__DIR__ . '/jobs');
Bootstrap::initStoreContext('/', '', 'default');
$transport = static function (string $method, string $url, array $headers, ?string $body): array {
    file_put_contents(__DIR__ . '/calls.log', $method . ' ' . $url . "\n", FILE_APPEND);
    if (str_ends_with((string)parse_url($url, PHP_URL_PATH), '/oauth2/token')) return ['status' => 200, 'body' => '{"access_token":"fixture-token","expires_in":1800}'];
    $payments = json_decode((string)file_get_contents(__DIR__ . '/payments.json'), true);
    return $payments[basename((string)parse_url($url, PHP_URL_PATH))] ?? ['status' => 404, 'body' => '{"errors":[{"error_code":404}]}'];
};
if (class_exists('GoPayPayment')) (new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new GoPayPayment($cfg, $transport)]);
header('Content-Type: application/json');
echo json_encode(apiWebhookPayment());
PHP);
$a164Socket = stream_socket_server('tcp://127.0.0.1:0');
$a164Address = stream_socket_get_name($a164Socket, false);
fclose($a164Socket);
$a164Server = proc_open([PHP_BINARY, '-S', $a164Address, $a164Root . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $a164Root . '/server.log', 'a'], 2 => ['file', $a164Root . '/server.log', 'a']], $a164Pipes);
fclose($a164Pipes[0]);
try {
    $a164Up = false;
    for ($a164Try = 0; $a164Try < 50 && !$a164Up; $a164Try++) {
        $a164Probe = @stream_socket_client('tcp://' . $a164Address, $a164Errno, $a164Error, 0.1);
        if ($a164Probe) { fclose($a164Probe); $a164Up = true; } else usleep(100000);
    }
    assert_true($a164Up, 'A16.4 HTTP: isolated notification fixture starts');
    $a164Gopay = static function (array $payments) use ($a164Root, $a164Payment): void {
        $answers = [];
        foreach ($payments as $id => $answer) $answers[(string)$id] = is_array($answer) ? $answer : $a164Payment($id, $answer);
        file_put_contents($a164Root . '/payments.json', json_encode($answers));
    };
    $a164Notify = static function (string $query, string $method = 'GET', string $body = '') use ($a164Address): array {
        $stream = @fopen('http://' . $a164Address . '/?action=webhook_payment&provider=gopay' . $query, 'r', false, stream_context_create(['http' => ['method' => $method, 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'ignore_errors' => true, 'timeout' => 10]]));
        if (!$stream) return [0, null];
        $response = stream_get_contents($stream);
        preg_match('/\s(\d{3})\s/', stream_get_meta_data($stream)['wrapper_data'][0] ?? '', $status);
        fclose($stream);
        return [(int)($status[1] ?? 0), json_decode((string)$response, true)];
    };
    $a164State = static fn(string $case): string => (string)$a164Db->query('SELECT payment_status FROM orders WHERE id = ' . $a164Ids[$case])->fetchColumn();
    $a164Tx = static fn(string $case): int => (int)$a164Db->query('SELECT COUNT(*) FROM payment_transactions WHERE order_id = ' . $a164Ids[$case])->fetchColumn();

    $a164Gopay([4100000001 => 'PAID', 4100000002 => 'CREATED', 4100000003 => ['status' => 200, 'body' => json_encode(['id' => 4100000003, 'order_number' => 'A164-4100000003', 'state' => 'PAID', 'amount' => 1000, 'currency' => 'EUR', 'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])],
        4100000004 => ['status' => 200, 'body' => json_encode(['id' => 4100000004, 'order_number' => 'A164-4100000004', 'state' => 'PAID', 'amount' => 1990, 'currency' => 'CZK', 'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])],
        4100000005 => ['status' => 200, 'body' => json_encode(['id' => 4100000005, 'order_number' => 'A164-4100000005', 'state' => 'PAID', 'amount' => 1990, 'currency' => 'EUR', 'target' => ['type' => 'ACCOUNT', 'goid' => 8000000001]])],
        4100000006 => 'CANCELED', 4100000007 => ['status' => 503, 'body' => '']]);
    assert_equals([200, ['status' => 'ok']], $a164Notify('&id=4100000001'), 'A16.4 HTTP: GET notification for a PAID payment is accepted');
    assert_equals('paid', $a164State('paid'), 'A16.4 HTTP: verified PAID marks the order paid');
    $a164PaidTx = $a164Tx('paid');
    assert_equals([200, ['status' => 'already_processed']], $a164Notify('&id=4100000001'), 'A16.4 HTTP: repeated notification deduplicates');
    assert_equals($a164PaidTx, $a164Tx('paid'), 'A16.4 HTTP: replay writes no payment transaction');
    assert_equals([200, ['status' => 'ok']], $a164Notify('&id=4100000002&state=PAID&amount=1990', 'POST', '{"state":"PAID","amount":1990,"currency":"EUR"}'), 'A16.4 HTTP: notification claiming PAID for an unpaid payment is acknowledged');
    assert_equals('pending', $a164State('forged'), 'A16.4 HTTP: request parameters/body never pay an order GoPay reports as CREATED');
    assert_equals(500, $a164Notify('&id=4100000003')[0], 'A16.4 HTTP: amount mismatch fails for retry');
    assert_equals('pending', $a164State('amount'), 'A16.4 HTTP: amount mismatch cannot pay');
    assert_equals(0, (int)$a164Db->query("SELECT COUNT(*) FROM processed_events WHERE event_id = 'payment_gopay_4100000003:PAID'")->fetchColumn(), 'A16.4 HTTP: failed claim is released');
    assert_equals(500, $a164Notify('&id=4100000004')[0], 'A16.4 HTTP: currency mismatch fails');
    assert_equals('pending', $a164State('currency'), 'A16.4 HTTP: currency mismatch cannot pay');
    assert_equals([400, ['error' => 'Invalid notification']], $a164Notify('&id=4100000005'), 'A16.4 HTTP: payment of another GoID is rejected');
    assert_equals('pending', $a164State('goid'), 'A16.4 HTTP: foreign GoID cannot pay');
    assert_equals([400, ['error' => 'Invalid notification']], $a164Notify('&id=4199999999'), 'A16.4 HTTP: id unknown to GoPay is rejected');
    assert_equals([400, ['error' => 'Invalid notification']], $a164Notify(''), 'A16.4 HTTP: notification without id is rejected');
    assert_equals([200, ['status' => 'ok']], $a164Notify('&id=4100000006'), 'A16.4 HTTP: CANCELED notification accepted');
    assert_equals('failed', $a164State('failed'), 'A16.4 HTTP: CANCELED payment fails the pending order');
    assert_equals(500, $a164Notify('&id=4100000007')[0], 'A16.4 HTTP: GoPay outage answers 500 so GoPay retries');
    assert_equals('pending', $a164State('outage'), 'A16.4 HTTP: outage changes nothing');
    $a164Gopay([4100000003 => 'PAID']);
    assert_equals([200, ['status' => 'ok']], $a164Notify('&id=4100000003'), 'A16.4 HTTP: corrected retry is accepted');
    assert_equals('paid', $a164State('amount'), 'A16.4 HTTP: corrected retry pays the order');
} finally {
    proc_terminate($a164Server);
    proc_close($a164Server);
    unset($a164State, $a164Tx);
    $a164Db = null;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a164Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $a164File) {
        $a164File->isDir() ? rmdir($a164File->getPathname()) : unlink($a164File->getPathname());
    }
    rmdir($a164Root);
}
