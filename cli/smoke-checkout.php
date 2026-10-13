<?php
require_once __DIR__ . '/cli-only.php';
/**
 * Post-deploy checkout smoke test (P0 2026-10-13 layer 4).
 *
 * Walks the real HTML storefront like a customer, without a browser:
 *   1. add a product to the cart, 2. open the checkout page and read its CSP,
 *   3. submit the checkout form with an online payment method,
 *   4. require a redirect to a foreign gateway origin that the checkout page's
 *      CSP form-action allows (the 1.9.0 regression a browser drops silently),
 *   5. require the gateway return pages to exist (not 404), 6. optionally open the gateway URL.
 * The request carries X-BareBones-Synthetic: <monitoring.synthetic_token>, so the shop flags the
 * order synthetic, sends no mail and closes it right after the redirect (stock released).
 *
 * Usage:
 *   php cli/smoke-checkout.php --base-url=https://shop.example --product=123
 *       [--token=...|env BAREBONES_SYNTHETIC_TOKEN] [--checkout-path=/?page=checkout]
 *       [--payment=card] [--shipping=packeta] [--country=SK] [--variant=ID]
 *       [--expect-gateway=https://checkout.stripe.com] [--no-fetch-gateway] [--timeout=20]
 * Exit: 0 all checks passed, 1 a check failed, 2 usage/configuration error.
 */
require_once __DIR__ . '/../src/SyntheticCheckout.php';

$options = getopt('', ['base-url:', 'product:', 'variant:', 'token:', 'checkout-path:', 'payment:', 'shipping:',
    'country:', 'expect-gateway:', 'no-fetch-gateway', 'timeout:', 'email:']);
$localConfig = is_file(__DIR__ . '/../config.php') ? (array)(require __DIR__ . '/../config.php') : [];
$baseUrl = rtrim((string)($options['base-url'] ?? ($localConfig['app_url'] ?? '')), '/');
$productId = (int)($options['product'] ?? 0);
$token = (string)($options['token'] ?? (getenv('BAREBONES_SYNTHETIC_TOKEN') ?: ($localConfig['monitoring']['synthetic_token'] ?? '')));
$timeout = max(1, (int)($options['timeout'] ?? 20));
if (!preg_match('#^https?://[^/?\#]+(/[^?\#]*)?$#i', $baseUrl) || $productId <= 0) {
    fwrite(STDERR, "Usage: php cli/smoke-checkout.php --base-url=https://shop.example --product=ID [--token=...]\n");
    exit(2);
}
if (strlen($token) < 32) {
    // Without the token the shop would create a real order and mail the merchant.
    fwrite(STDERR, "A synthetic token of at least 32 characters is required (--token, BAREBONES_SYNTHETIC_TOKEN or monitoring.synthetic_token).\n");
    exit(2);
}

$cookies = [];
$http = static function (string $method, string $url, array $form = [], array $headers = []) use (&$cookies, $timeout): array {
    $lines = $headers;
    if ($cookies) $lines[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "{$k}={$v}", array_keys($cookies), $cookies));
    if ($method === 'POST') $lines[] = 'Content-Type: application/x-www-form-urlencoded';
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $lines), 'content' => $method === 'POST' ? http_build_query($form) : '',
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => $timeout,
        'user_agent' => 'BareBones-checkout-smoke/1.0',
    ]]);
    $body = @file_get_contents($url, false, $context);
    $result = ['status' => 0, 'headers' => [], 'body' => (string)$body];
    foreach ($http_response_header ?? [] as $index => $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $status)) { $result['status'] = (int)$status[1]; $result['headers'] = []; continue; }
        if ($index === 0 || !str_contains($line, ':')) continue;
        [$name, $value] = array_map('trim', explode(':', $line, 2));
        $result['headers'][strtolower($name)][] = $value;
        if (strtolower($name) === 'set-cookie' && preg_match('/^([^=;\s]+)=([^;]*)/', $value, $cookie)) $cookies[$cookie[1]] = $cookie[2];
    }
    return $result;
};
$header = static fn(array $response, string $name): string => (string)($response['headers'][strtolower($name)][0] ?? '');
$origin = static function (string $url): string {
    $parts = parse_url($url) ?: [];
    if (!isset($parts['host'])) return '';
    return strtolower(($parts['scheme'] ?? '') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
};
$absolute = static function (string $location, string $base) use ($origin): string {
    if (preg_match('#^https?://#i', $location)) return $location;
    if (str_starts_with($location, '//')) return (string)parse_url($base, PHP_URL_SCHEME) . ':' . $location;
    return $origin($base) . (str_starts_with($location, '/') ? '' : '/') . $location;
};

$failures = 0;
$report = static function (bool $ok, string $step, string $detail) use (&$failures): bool {
    echo ($ok ? 'OK   ' : 'FAIL ') . $step . ': ' . $detail . "\n";
    if (!$ok) $failures++;
    return $ok;
};
$finish = static function () use (&$failures): never {
    echo $failures === 0 ? "Checkout smoke passed.\n" : "Checkout smoke FAILED ({$failures}).\n";
    exit($failures === 0 ? 0 : 1);
};

// 1. Cart
$home = $http('GET', $baseUrl . '/');
preg_match('/"csrfToken"\s*:\s*"([^"]+)"/', $home['body'], $csrf);
if (!$report($home['status'] === 200 && isset($csrf[1]), 'storefront', "GET / → {$home['status']}" . (isset($csrf[1]) ? '' : ', no csrfToken in storefront config'))) $finish();
$add = ['cart_action' => 'add', 'product_id' => $productId, 'quantity' => 1, 'csrf_token' => stripcslashes($csrf[1])];
if (!empty($options['variant'])) $add['variant_id'] = (int)$options['variant'];
$cart = $http('POST', $baseUrl . '/', $add, ['X-Requested-With: XMLHttpRequest']);
$cartJson = json_decode($cart['body'], true);
if (!$report($cart['status'] === 200 && ($cartJson['ok'] ?? false) === true, 'cart', "add product {$productId} → {$cart['status']} " . substr(trim($cart['body']), 0, 120))) $finish();

// 2. Checkout page and its policy
$checkoutUrl = $absolute((string)($options['checkout-path'] ?? '/?page=checkout'), $baseUrl);
$page = $http('GET', $checkoutUrl);
$csp = $header($page, 'Content-Security-Policy');
preg_match('/(?:^|;)\s*form-action\s+([^;]*)/i', $csp, $formAction);
$formSources = preg_split('/\s+/', trim($formAction[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
if (!$report($page['status'] === 200 && str_contains($page['body'], 'data-checkout-form'), 'checkout page', "GET {$checkoutUrl} → {$page['status']}")) $finish();
$report($formSources !== [], 'csp', 'form-action ' . ($formSources ? implode(' ', $formSources) : '(missing)'));

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8"?>' . $page['body']);
libxml_clear_errors();
$form = (new DOMXPath($dom))->query('//form[@data-checkout-form]')->item(0);
$fields = ['name' => 'Checkout Smoke', 'email' => (string)($options['email'] ?? ('checkout-smoke+' . time() . '@example.com')),
    'phone' => '+421900000000', 'street' => 'Smoke 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => (string)($options['country'] ?? 'SK')];
$shipping = []; $payments = [];
foreach ($form instanceof DOMElement ? $form->getElementsByTagName('input') : [] as $input) {
    $name = $input->getAttribute('name');
    $type = strtolower($input->getAttribute('type'));
    if ($type === 'hidden') $fields[$name] = $input->getAttribute('value');
    elseif ($type === 'radio' && $name === 'shipping_method') $shipping[$input->getAttribute('value')] = $input->hasAttribute('checked');
    elseif ($type === 'radio' && $name === 'payment') $payments[$input->getAttribute('value')] = $input->hasAttribute('data-online');
    elseif ($type === 'checkbox' && $input->hasAttribute('required')) $fields[$name] = $input->getAttribute('value') ?: '1';
}
$fields['shipping_method'] = (string)($options['shipping'] ?? (array_search(true, $shipping, true) ?: array_key_first($shipping) ?? ''));
$fields['payment'] = (string)($options['payment'] ?? (array_search(true, $payments, true) ?: ''));
if (!$report($fields['payment'] !== '' && ($payments[$fields['payment']] ?? false), 'payment option', $fields['payment'] !== '' ? "online method {$fields['payment']}" : 'no online payment method offered')) $finish();

// 3./4. Submit and check the redirect against the page policy
$submit = $http('POST', $checkoutUrl, $fields, [SyntheticCheckout::HEADER . ': ' . $token]);
$location = $header($submit, 'Location');
$gatewayUrl = $location !== '' ? $absolute($location, $checkoutUrl) : '';
$gatewayOrigin = $origin($gatewayUrl);
$report(in_array($submit['status'], [302, 303], true) && $gatewayUrl !== '', 'checkout submit', "POST → {$submit['status']}" . ($gatewayUrl !== '' ? " Location {$gatewayOrigin}" : (str_contains($submit['body'], 'data-pending-payment-link') ? ' payment link instead of redirect: gateway origin missing from CSP form-action' : ' without redirect')));
if ($gatewayUrl === '') $finish();
$report($gatewayOrigin !== $origin($checkoutUrl), 'gateway redirect', "goes off-site to {$gatewayOrigin}");
if (isset($options['expect-gateway'])) {
    $report($gatewayOrigin === strtolower(rtrim((string)$options['expect-gateway'], '/')), 'expected gateway', "{$gatewayOrigin} vs {$options['expect-gateway']}");
}
$allowed = false;
foreach ($formSources as $source) {
    $source = strtolower(trim($source, '"'));
    if ($source === "'self'" && $gatewayOrigin === $origin($checkoutUrl)) $allowed = true;
    elseif ($source === $gatewayOrigin || $source === parse_url($gatewayUrl, PHP_URL_SCHEME) . ':') $allowed = true;
    elseif (preg_match('#^(https?)://\*\.(.+)$#', $source, $wild) && str_starts_with($gatewayOrigin, $wild[1] . '://') && str_ends_with($gatewayOrigin, '.' . $wild[2])) $allowed = true;
}
$report($allowed, 'csp form-action', $allowed ? "allows {$gatewayOrigin}" : "BLOCKS {$gatewayOrigin}: browsers drop this redirect silently");

// 5. Gateway return pages exist
foreach (['/?page=checkout-cancel', '/checkout/cancel'] as $path) {
    $return = $http('GET', $baseUrl . $path);
    $report($return['status'] > 0 && $return['status'] !== 404 && $return['status'] < 500, 'return page', "GET {$path} → {$return['status']}");
}

// 6. Gateway answers
if (!isset($options['no-fetch-gateway'])) {
    $saved = $cookies; $cookies = [];
    $gateway = $http('GET', $gatewayUrl);
    $cookies = $saved;
    $report($gateway['status'] >= 200 && $gateway['status'] < 400, 'gateway page', "GET {$gatewayOrigin} → {$gateway['status']}");
}
$finish();
