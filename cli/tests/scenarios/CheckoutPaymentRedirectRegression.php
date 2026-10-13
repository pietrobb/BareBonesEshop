<?php

// P0 2026-10-13: since 1.9.0 the HTML card checkout answered POST with 302 to
// checkout.stripe.com while the page CSP said form-action 'self'; browsers apply
// form-action to that redirect and silently dropped it. A resubmit then found an
// empty cart, and the gateway cancel_url /checkout/cancel was a 404.
echo "\n--- P0 checkout: hosted payment redirect, resubmit and cancel ---\n";
require_once dirname(TEST_CLI_DIR) . '/adapters/PaymentAdapter.php';

$p0FormAction = static function (string $policy): array {
    preg_match("/(?:^|;)\s*form-action ([^;]*);/", $policy, $match);
    return preg_split('/\s+/', trim($match[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
};
$p0Origin = static function (string $url): string {
    $parts = parse_url($url) ?: [];
    return strtolower(($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));
};

// Every registered online gateway declares where it sends the browser, and the policy allows it.
$p0Sources = $p0FormAction(Security::contentSecurityPolicy());
assert_equals("'self'", $p0Sources[0] ?? '', "P0 CSP: form-action starts with 'self'");
foreach (Adapters::registry('payment') as $p0Code => $p0Definition) {
    if ($p0Code === 'mock') continue; // test double; hosted mode is covered by the browser E2E
    $p0Class = Adapters::providerClass('payment', (string)$p0Code);
    if ($p0Class === 'CashOnDelivery') continue;
    assert_true(is_subclass_of($p0Class, HostedCheckoutAdapter::class), "P0 CSP: online payment adapter {$p0Code} declares its redirect origins");
    $p0Declared = is_subclass_of($p0Class, HostedCheckoutAdapter::class) ? Adapters::provider('payment', (string)$p0Code)->redirectOrigins() : [];
    assert_true($p0Declared !== [], "P0 CSP: {$p0Code} declares at least one origin");
    foreach ($p0Declared as $p0DeclaredOrigin) {
        assert_true(in_array($p0DeclaredOrigin, $p0Sources, true), "P0 CSP: form-action allows {$p0Code} redirect origin {$p0DeclaredOrigin}");
    }
}
assert_true(in_array('https://checkout.stripe.com', $p0Sources, true), 'P0 CSP: form-action allows the Stripe Checkout redirect');
foreach (['production' => ['https://secure.payu.com'], 'sandbox' => ['https://secure.snd.payu.com', 'https://merch-prod.snd.payu.com']] as $p0Env => $p0Expected) {
    assert_equals($p0Expected, (new PayUPayment(['payu_env' => $p0Env]))->redirectOrigins(), "P0 CSP: PayU {$p0Env} declares its pay page origins");
}
$p0PrevHost = $_SERVER['HTTP_HOST'] ?? null;
$_SERVER['HTTP_HOST'] = 'shop.example';
assert_true(Security::formActionAllows('https://checkout.stripe.com/c/pay/cs_test_1'), 'P0 CSP: Stripe Checkout URL passes form-action');
assert_true(Security::formActionAllows('/?page=order-done&order=X'), 'P0 CSP: relative success URL passes form-action');
assert_true(Security::formActionAllows('http://shop.example/?page=order-done'), 'P0 CSP: same-origin URL passes form-action');
assert_true(!Security::formActionAllows('https://undeclared-gateway.example/pay'), 'P0 CSP: undeclared gateway origin is detected');
assert_true(!Security::formActionAllows('//undeclared-gateway.example/pay'), 'P0 CSP: protocol-relative foreign URL is detected');
if ($p0PrevHost === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $p0PrevHost;

// The real storefront over HTTP (index.php and the .htaccess fallback redirect.php).
$p0Root = test_fixture_path('p0-checkout');
mkdir($p0Root . '/sessions', 0700, true);
$p0DbPath = $p0Root . '/shop.sqlite';
$p0Snapshot = new PDO('sqlite:' . $testDbPath);
$p0Snapshot->exec('VACUUM INTO ' . $p0Snapshot->quote($p0DbPath));
$p0Snapshot = null;
$p0Db = new PDO('sqlite:' . $p0DbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$p0Db->exec("UPDATE payment_methods SET provider = 'stripe', active = 1 WHERE code = 'card'");
// The HTML checkout reads config.php itself and needs an absolute app_url for gateway callbacks:
// serve a copy of the application with its own config instead of touching the real one.
$p0AppRoot = $p0Root . '/app';
$p0Skip = ['.git', 'node_modules', 'releases', 'tests', 'cli', 'docs', 'uploads', 'storage', 'cache', 'logs', 'db', 'jobs', 'import', 'test-results', '.worktrees', 'config.php'];
foreach (new DirectoryIterator(dirname(TEST_CLI_DIR)) as $p0Entry) {
    if ($p0Entry->isDot() || in_array($p0Entry->getFilename(), $p0Skip, true)) continue;
    $p0Source = $p0Entry->getPathname();
    $p0Target = $p0AppRoot . '/' . $p0Entry->getFilename();
    if ($p0Entry->isFile()) { @mkdir($p0AppRoot, 0700, true); copy($p0Source, $p0Target); continue; }
    $p0Files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p0Source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    @mkdir($p0Target, 0700, true);
    foreach ($p0Files as $p0File) {
        $p0Path = $p0Target . '/' . $p0Files->getSubPathname();
        $p0File->isDir() ? @mkdir($p0Path, 0700, true) : copy($p0File->getPathname(), $p0Path);
    }
}
$p0Probe = stream_socket_server('tcp://127.0.0.1:0');
$p0Port = (int)substr(strrchr((string)stream_socket_get_name($p0Probe, false), ':'), 1);
fclose($p0Probe);
$p0Config = require dirname(TEST_CLI_DIR) . '/config.php';
$p0Config['app_url'] = "http://127.0.0.1:{$p0Port}";
// Own limiter store: earlier scenarios already checked out from 127.0.0.1.
$p0Config['private_cache_dir'] = $p0Root . '/private-cache';
file_put_contents($p0AppRoot . '/config.php', '<?php return ' . var_export($p0Config, true) . ';');
file_put_contents($p0Root . '/router.php', '<?php
require ' . var_export($p0AppRoot . '/src/autoload.php', true) . ';
require_once ' . var_export($p0AppRoot . '/adapters/PaymentAdapter.php', true) . ';
require_once ' . var_export($p0AppRoot . '/adapters/contrib/StripePayment.php', true) . ';
Db::init(new PDO("sqlite:" . __DIR__ . "/shop.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]), "sqlite");
JobQueue::setDir(__DIR__ . "/jobs");
/** Real Stripe adapter (and its declared origins) without the network call. */
final class P0Stripe extends StripePayment {
    public function createSession(array $order): array {
        $base = is_file(__DIR__ . "/gateway.txt") ? trim((string)file_get_contents(__DIR__ . "/gateway.txt")) : "https://checkout.stripe.com/c/pay/";
        file_put_contents(__DIR__ . "/cancel_url.txt", (string)$order["cancel_url"]);
        file_put_contents(__DIR__ . "/success_url.txt", (string)$order["success_url"]);
        return ["url" => $base . "cs_test_p0_" . $order["order_number"] . "#fid=p0", "session_id" => "cs_test_p0_" . $order["order_number"]];
    }
}
(new ReflectionProperty(Adapters::class, "instances"))->setValue(null, ["payment" => new P0Stripe([])]);
$uri = (string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($uri === "/__seed") {
    session_start();
    $_SESSION["csrf_token"] = "p0-csrf";
    $_SESSION["cart"] = ["p_' . (int)$payProduct['id'] . '" => ["product_id" => ' . (int)$payProduct['id'] . ', "variant_id" => null, "qty" => 1]];
    echo session_id();
    exit;
}
chdir(' . var_export($p0AppRoot, true) . ');
if ($uri === "/" || $uri === "/index.php") { require ' . var_export($p0AppRoot . '/index.php', true) . '; exit; }
$_GET["path"] = $uri;
require ' . var_export($p0AppRoot . '/redirect.php', true) . ';
');
$p0Server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $p0Root . '/sessions', '-S', "127.0.0.1:{$p0Port}", $p0Root . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $p0Root . '/server.log', 'a'], 2 => ['file', $p0Root . '/server.log', 'a']], $p0Pipes, $p0AppRoot);
$p0Http = static function (string $method, string $path, array $post, string &$sid) use ($p0Port): array {
    $headers = "Content-Type: application/x-www-form-urlencoded\r\nHost: 127.0.0.1:{$p0Port}\r\n" . ($sid !== '' ? "Cookie: PHPSESSID={$sid}\r\n" : '');
    $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => http_build_query($post),
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 15]]);
    $body = @file_get_contents("http://127.0.0.1:{$p0Port}{$path}", false, $context);
    $result = ['status' => 0, 'location' => '', 'csp' => '', 'body' => (string)$body];
    foreach ($http_response_header ?? [] as $index => $line) {
        if ($index === 0) $result['status'] = (int)(explode(' ', $line)[1] ?? 0);
        if (stripos($line, 'Location:') === 0) $result['location'] = trim(substr($line, 9));
        if (stripos($line, 'Content-Security-Policy:') === 0) $result['csp'] = trim(substr($line, 24));
        if (preg_match('/^Set-Cookie:\s*PHPSESSID=([^;]+)/i', $line, $cookie)) $sid = $cookie[1];
    }
    return $result;
};
$p0Form = static fn(string $email): array => [
    'csrf_token' => 'p0-csrf', 'checkout' => '1', 'name' => 'P0 Card Customer', 'email' => $email, 'phone' => '+421900000077',
    'street' => 'Platobna 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'shipping_method' => 'packeta', 'payment' => 'card',
];
try {
    $p0Sid = '';
    for ($p0Try = 0; $p0Try < 50 && $p0Sid === ''; $p0Try++) {
        usleep(100000);
        $p0Sid = $p0Http('GET', '/__seed', [], $p0Sid)['body'];
    }
    assert_true($p0Sid !== '', 'P0 HTTP: storefront fixture server is up');

    $p0Page = $p0Http('GET', '/?page=checkout', [], $p0Sid);
    assert_equals(200, $p0Page['status'], 'P0 HTTP: checkout page renders');
    assert_true(str_contains($p0Page['body'], 'data-checkout-form') && str_contains($p0Page['body'], 'data-redirecting-label='), 'P0 HTTP: checkout form carries the submit-lock hooks');
    assert_true(preg_match('/name="payment" value="card"[^>]*data-online/', $p0Page['body']) === 1, 'P0 HTTP: online payment option is marked for the redirect label');

    $p0Submit = $p0Http('POST', '/?page=checkout', $p0Form('p0-card@example.test'), $p0Sid);
    assert_equals(302, $p0Submit['status'], 'P0 HTTP: card checkout answers with a redirect');
    assert_true(str_starts_with($p0Submit['location'], 'https://checkout.stripe.com/c/pay/cs_test_p0_'), 'P0 HTTP: redirect goes to the Stripe Checkout session');
    // The browser checks the redirect against the CSP of the page that submitted the form.
    assert_true(in_array($p0Origin($p0Submit['location']), $p0FormAction($p0Page['csp']), true), 'P0 HTTP: checkout page CSP form-action allows the gateway redirect (the 1.9.0 regression)');
    preg_match('/cs_test_p0_([A-Za-z0-9-]+)/', $p0Submit['location'], $p0Match);
    $p0OrderNumber = (string)($p0Match[1] ?? '');
    $p0Order = $p0Db->query('SELECT id, payment_status FROM orders WHERE order_number = ' . $p0Db->quote($p0OrderNumber))->fetch();
    assert_true(is_array($p0Order) && $p0Order['payment_status'] === 'pending', 'P0 HTTP: order exists and awaits payment');

    $p0Resubmit = $p0Http('POST', '/?page=checkout', $p0Form('p0-card@example.test'), $p0Sid);
    assert_equals(302, $p0Resubmit['status'], 'P0 HTTP: resubmit with an empty cart is not "cart is empty"');
    assert_equals($p0Submit['location'], $p0Resubmit['location'], 'P0 HTTP: resubmit continues the same pending payment');
    assert_equals(1, (int)$p0Db->query("SELECT COUNT(*) FROM orders WHERE customer_email = 'p0-card@example.test'")->fetchColumn(), 'P0 HTTP: resubmit creates no second order');

    $p0Back = $p0Http('GET', '/?page=checkout', [], $p0Sid);
    $p0Link = htmlspecialchars($p0Submit['location'], ENT_QUOTES, 'UTF-8');
    assert_true(str_contains($p0Back['body'], 'data-pending-order="' . $p0OrderNumber . '"') && str_contains($p0Back['body'], 'href="' . $p0Link . '"'), 'P0 HTTP: returning to checkout offers the pending order payment link');

    $p0CancelUrl = is_file($p0Root . '/cancel_url.txt') ? (string)file_get_contents($p0Root . '/cancel_url.txt') : '';
    $p0CancelPath = (string)parse_url($p0CancelUrl, PHP_URL_PATH) . '?' . (string)parse_url($p0CancelUrl, PHP_URL_QUERY);
    assert_true(str_contains($p0CancelUrl, 'order=' . rawurlencode($p0OrderNumber)), 'P0 HTTP: cancel_url names the order');
    foreach (['gateway cancel_url' => $p0CancelPath, 'legacy /checkout/cancel' => '/checkout/cancel?order=' . rawurlencode($p0OrderNumber)] as $p0Label => $p0Path) {
        $p0Cancel = $p0Http('GET', $p0Path, [], $p0Sid);
        assert_equals(200, $p0Cancel['status'], "P0 HTTP: {$p0Label} is a real page (not 404)");
        assert_true(str_contains($p0Cancel['body'], I18n::t('checkout_payment_cancelled')) || str_contains($p0Cancel['body'], 'data-pending-order="' . $p0OrderNumber . '"'), "P0 HTTP: {$p0Label} explains the cancelled payment");
        assert_true(str_contains($p0Cancel['body'], 'href="' . $p0Link . '"'), "P0 HTTP: {$p0Label} lets the customer continue the payment");
    }
    $p0Stranger = '';
    $p0Http('GET', '/__seed', [], $p0Stranger);
    $p0Foreign = $p0Http('GET', '/checkout/cancel?order=' . rawurlencode($p0OrderNumber), [], $p0Stranger);
    assert_true($p0Foreign['status'] === 200 && !str_contains($p0Foreign['body'], 'cs_test_p0_'), 'P0 HTTP: another browser does not get the payment link by order number');

    // success_url is core-built for every gateway: a real page, and a declined payment can be retried.
    $p0SuccessUrl = str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_p0_' . $p0OrderNumber, is_file($p0Root . '/success_url.txt') ? (string)file_get_contents($p0Root . '/success_url.txt') : '');
    $p0SuccessPath = (string)parse_url($p0SuccessUrl, PHP_URL_PATH) . '?' . (string)parse_url($p0SuccessUrl, PHP_URL_QUERY);
    $p0Db->prepare("UPDATE orders SET payment_status = 'failed' WHERE id = ?")->execute([(int)($p0Order['id'] ?? 0)]);
    $p0Success = $p0Http('GET', $p0SuccessPath, [], $p0Sid);
    assert_equals(200, $p0Success['status'], 'P0 HTTP: gateway success_url is a real page (not 404)');
    assert_true(str_contains($p0Success['body'], $p0OrderNumber), 'P0 HTTP: success_url shows the order');
    assert_true(str_contains($p0Success['body'], 'href="' . $p0Link . '"'), 'P0 HTTP: success_url offers a retry link for a declined payment');
    $p0Db->prepare("UPDATE orders SET payment_status = 'pending' WHERE id = ?")->execute([(int)($p0Order['id'] ?? 0)]);
    $p0Success = $p0Http('GET', $p0SuccessPath, [], $p0Sid);
    assert_true($p0Success['status'] === 200 && !str_contains($p0Success['body'], 'data-pending-payment-link'), 'P0 HTTP: success_url awaiting the webhook does not offer a second payment');

    $p0Db->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = ?")->execute([(int)($p0Order['id'] ?? 0)]);
    $p0Paid = $p0Http('GET', '/?page=checkout', [], $p0Sid);
    assert_true(!str_contains($p0Paid['body'], 'data-pending-payment-link'), 'P0 HTTP: a paid order is no longer offered for payment');

    // A gateway URL outside form-action would be dropped by the browser: show a link instead of a 302.
    file_put_contents($p0Root . '/gateway.txt', 'https://undeclared-gateway.example/pay/');
    $p0Sid2 = '';
    $p0Sid2 = $p0Http('GET', '/__seed', [], $p0Sid2)['body'];
    $p0Guard = $p0Http('POST', '/?page=checkout', $p0Form('p0-guard@example.test'), $p0Sid2);
    assert_equals(200, $p0Guard['status'], 'P0 HTTP: undeclared gateway origin is not answered with a silently blocked redirect');
    assert_true(str_contains($p0Guard['body'], 'href="https://undeclared-gateway.example/pay/cs_test_p0_'), 'P0 HTTP: undeclared gateway gets a clickable payment link');
} finally {
    proc_terminate($p0Server);
    proc_close($p0Server);
}
