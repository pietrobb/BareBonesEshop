<?php
/**
 * Review round 4 (API routing after the /api/v1 unification, security): no legacy api.php/?action= routes,
 * hyphenated carrier codes, store-prefixed and subdirectory external API, review form store URL and errors,
 * mail sealing and plain-text parts, rich-text link allow-list, cache deny, coupon throttle.
 * The HTTP side (legacy URLs answer 404) and the cache deny is covered in AuditInstallSmokeRegression.
 */

echo "\n--- Round 4: API routing and security ---\n";

$r4Root = dirname(TEST_CLI_DIR);
$r4Stores = ['default' => ['url_prefix' => ''], 'cz' => ['url_prefix' => '/cs']];

// 1. Theme assets are versioned by theme.json (cached for a year): the storefront.js URL change needs a new version.
$r4Theme = json_decode((string)file_get_contents($r4Root . '/themes/default/theme.json'), true);
assert_true(version_compare((string)($r4Theme['version'] ?? ''), '2.3.0', '>='), 'Round 4 theme: version bumped with the storefront.js API URL change');
assert_true(array_filter(Template::assets()['js'], static fn(string $u): bool => str_ends_with($u, 'storefront.js?v=' . rawurlencode((string)$r4Theme['version']))) !== [],
    'Round 4 theme: storefront.js URL carries the theme version');
// 2. One API gateway only: neither the old ?action= query nor api.php (any action) is routed.
foreach ([
    ['/api/v1/storefront?action=cart', '', ['action' => '', 'get' => []]],
    ['/cs/api/v1/storefront?action=payment_methods&country=CZ', '', ['action' => '', 'get' => []]],
    ['/api/v1/storefront/cart?action=wishlist', '', ['action' => 'cart', 'get' => []]],
    ['/api.php?action=webhook_payment&provider=gopay', '', null],
    ['/api.php?action=webhook_stripe', '', null],
    ['/api.php?action=csp_report', '', null],
    ['/shop/api.php?action=webhook_payment&provider=payu', '/shop', null],
    ['/api.php?action=categories', '', null],
] as [$r4Uri, $r4Base, $r4Expected]) {
    assert_equals($r4Expected, ApiRoute::resolve($r4Uri, $r4Base, $r4Stores), "Round 4 single API: {$r4Uri} has no legacy route");
}
$r4Htaccess = (string)file_get_contents($r4Root . '/.htaccess');
assert_true(!str_contains($r4Htaccess, 'api\.php'), 'Round 4 single API: .htaccess (and router.php, which parses it) has no api.php rewrite');
assert_true(!str_contains((string)file_get_contents($r4Root . '/deploy/nginx.conf'), 'api\.php'), 'Round 4 single API: nginx.conf has no api.php rewrite');
assert_true(!is_file($r4Root . '/api.php'), 'Round 4 single API: no second entrypoint script');

// 3. Carrier codes as apiWebhookShipping accepts them (the default carrier is http-carrier).
foreach ([['http-carrier', 'http-carrier'], ['dpd_sk', 'dpd_sk'], ['-x', null], ['Bad', null], [str_repeat('a', 41), null], [str_repeat('a', 40), str_repeat('a', 40)]] as [$r4Carrier, $r4Expected]) {
    $r4Route = ApiRoute::resolve('/api/v1/webhooks/shipping/' . $r4Carrier, '', $r4Stores);
    assert_equals($r4Expected, $r4Route['get']['carrier'] ?? null, "Round 4 carrier route: {$r4Carrier}");
}

// 4. External API below a store prefix or an install subdirectory reaches ApiV1; a prefix must match the key's store.
assert_equals(['path' => '/api/v1/products', 'store' => 'cz'], ApiRoute::normalize('/shop/cs/api/v1/products?limit=1', '/shop', $r4Stores), 'Round 4 external API: subdirectory and store prefix are stripped');
assert_equals(['path' => '/api/v1/products', 'store' => null], ApiRoute::normalize('/api/v1/products', '', $r4Stores), 'Round 4 external API: no prefix, no store');
$r4ServerBefore = $_SERVER;
$r4StoreBefore = StoreContext::current();
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$r4Default = ApiAuth::issueClient('Round 4 default', 'default', ['products:read']);
$r4Cz = ApiAuth::issueClient('Round 4 cz', 'cz', ['products:read']);
$r4External = static function (string $uri, string $token, string $script = '/api/v1/index.php') use ($r4Root): array {
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['SCRIPT_NAME'] = $script;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    $_GET = [];
    ob_start();
    require $r4Root . '/src/Entrypoints/ApiExternal.php';
    return (array)json_decode((string)ob_get_clean(), true);
};
try {
    assert_true(isset($r4External('/api/v1/products', $r4Default['token'])['data']), 'Round 4 external API: /api/v1/products still answers');
    assert_true(isset($r4External('/cs/api/v1/products', $r4Cz['token'])['data']), 'Round 4 external API: /cs/api/v1/products answers for a cz key (was 404)');
    assert_true(isset($r4External('/shop/api/v1/products', $r4Default['token'], '/shop/api/v1/index.php')['data']), 'Round 4 external API: subdirectory install answers (was 404)');
    assert_equals('forbidden', $r4External('/cs/api/v1/products', $r4Default['token'])['error']['code'] ?? null, 'Round 4 external API: a default-store key under the /cs prefix is refused');
} finally {
    $_SERVER = $r4ServerBefore;
    $_GET = [];
    $pdo->exec("DELETE FROM api_clients WHERE name LIKE 'Round 4 %'");
    Bootstrap::initStoreContext('/', '', $r4StoreBefore->id(), $r4StoreBefore->channel());
}

// 5. Review form posts into its own store and shows the server's refusal reason.
Bootstrap::initStoreContext('/cs/', '', 'cz');
try {
    StorefrontReviewExtension::register();
    $r4Review = StorefrontViewModelRegistry::extensions('product', ['model' => ['product' => ['id' => 1, 'url' => '/cs/product/x']]])['reviews']['product'] ?? [];
    assert_equals(Template::baseUrl() . '/cs/api/v1/storefront/review_submit', $r4Review['submit_url'] ?? null, 'Round 4 reviews: a prefixed store posts the review form into its own store');
    assert_true(($r4Review['duplicate_label'] ?? '') === I18n::t('reviews.duplicate') && ($r4Review['rate_limited_label'] ?? '') === I18n::t('account.rate_limited'),
        'Round 4 reviews: duplicate and rate-limit texts are exposed to the form');
} finally {
    Bootstrap::initStoreContext('/', '', $r4StoreBefore->id(), $r4StoreBefore->channel());
}
$r4Block = (string)file_get_contents($r4Root . '/themes/default/blocks/storefront/product-page.html');
$r4Js = (string)file_get_contents($r4Root . '/themes/default/assets/storefront.js');
assert_true(str_contains($r4Block, 'data-duplicate-label=') && str_contains($r4Block, 'data-rate-limited-label=')
    && str_contains($r4Js, 'duplicate_review: form.dataset.duplicateLabel') && str_contains($r4Js, 'rate_limited: form.dataset.rateLimitedLabel'),
    'Round 4 reviews: storefront.js shows the duplicate / rate-limited texts for result.error');

// 6. A top-level value cannot forge a sealed {{#each}} row value; checkout strips control characters at input.
$r4Forged = "Eva\x00" . base64_encode('<img src=x onerror=alert(1)>') . "\x01";
$r4Html = MailService::renderString('<p>{{customer_name}}</p>{{#each items}}<b>{{name}}</b>{{/each}}', ['customer_name' => $r4Forged, 'items' => [['name' => 'A & B']]]);
assert_true(!str_contains($r4Html, '<img') && str_contains($r4Html, '<b>A &amp; B</b>'), 'Round 4 mail: a forged seal in a value stays text, row values still render escaped');

// 7. Plain-text part keeps table cells, rows, paragraphs and link targets apart.
$r4Text = MailService::textFromHtml("<html><head><style>p{color:red}</style></head><body><h2>Hi</h2><p>Line one<br>Line two</p>\n"
    . '<table><tr><th>Produkt</th><th>Ks</th><th>Cena</th></tr><tr><td>Mug</td><td>2</td><td>10 &euro;</td></tr></table>'
    . '<p><a href="https://shop.example/r?a=1&amp;b=2">Reset password</a> or <a href="https://shop.example/x">https://shop.example/x</a></p><ul><li>One</li><li>Two</li></ul></body></html>');
assert_equals("Hi\n\nLine one\nLine two\n\nProdukt\tKs\tCena\nMug\t2\t10 €\n\nReset password (https://shop.example/r?a=1&b=2) or https://shop.example/x\n\nOne\nTwo", $r4Text,
    'Round 4 mail: plain text separates cells/rows/paragraphs and keeps link URLs once');

// 8. Rich-text links: prefix allow-list (parse_url sees no scheme in "javascript:80/...").
foreach ([
    'javascript:80/alert(1)' => null, 'JaVaScRiPt:alert(1)' => null, "java\tscript:alert(1)" => null, ' javascript:alert(1)' => null,
    'data:text/html,x' => null, '//evil.example/x' => null, '/\\evil.example' => null, 'vbscript:x' => null, 'https:evil' => null,
    'https://shop.example/a' => 'https://shop.example/a', 'mailto:info@example.sk' => 'mailto:info@example.sk', 'tel:+421' => 'tel:+421',
    '/page/about' => '/page/about', '#top' => '#top', '?page=cart' => '?page=cart', 'page/about?x=a:b' => 'page/about?x=a:b',
] as $r4Href => $r4Expected) {
    $r4Doc = new DOMDocument();
    @$r4Doc->loadHTML('<?xml encoding="UTF-8">' . Security::sanitizeRichHtml('<a href="' . htmlspecialchars($r4Href, ENT_QUOTES) . '">x</a>'));
    $r4A = $r4Doc->getElementsByTagName('a')->item(0);
    assert_equals($r4Expected, $r4A instanceof DOMElement && $r4A->hasAttribute('href') ? $r4A->getAttribute('href') : null, 'Round 4 rich text href: ' . addcslashes($r4Href, "\t"));
}

// 9. cache/ is denied on the decoded path (a THE_REQUEST condition was bypassed by /c%61che/).
assert_true(!str_contains($r4Htaccess, 'THE_REQUEST') && preg_match('/^RewriteRule \^\(\?:[^\r\n]*\|cache\|[^\r\n]*- \[F,L,NC\]$/m', $r4Htaccess) === 1,
    'Round 4 cache: .htaccess denies cache/ unconditionally');

// 10. Coupon validation: dedicated throttle and one answer for unknown / used-up codes; minimum order only for a usable code.
$r4Pricing = (new ReflectionProperty(Pricing::class, 'config'))->getValue();
Pricing::setConfig(['cart_rules' => [
    ['type' => 'coupon', 'active' => true, 'code' => 'R4USED', 'name' => 'R4 used', 'discount_type' => 'fixed', 'discount_value' => 100, 'max_uses' => 1, 'min_subtotal' => 999999],
    ['type' => 'coupon', 'active' => true, 'code' => 'R4MIN', 'name' => 'R4 min', 'discount_type' => 'fixed', 'discount_value' => 100, 'min_subtotal' => 999999],
]]);
$pdo->exec("INSERT INTO coupon_usage_counters (store_id, coupon_code, subject, uses) VALUES ('*', 'R4USED', '', 1)"); // shared pricing.json coupon: counted across stores
$r4Validate = static function (string $code, string $ip = '127.0.4.1'): array {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_SESSION['csrf_token'] = 'r4-coupon';
    $_POST = ['csrf_token' => 'r4-coupon', 'code' => $code, 'subtotal' => '1000'];
    $result = apiValidateCoupon();
    $_POST = [];
    return $result;
};
try {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    assert_equals('Coupon expired', Pricing::applyCoupon('R4USED', 1000)['error'], 'Round 4 coupon: usage limit is checked before the minimum order');
    assert_equals([false, 'Invalid coupon'], [($r4V = $r4Validate('R4NOPE'))['valid'], $r4V['error']], 'Round 4 coupon: unknown code');
    assert_equals([false, 'Invalid coupon'], [($r4V = $r4Validate('R4USED'))['valid'], $r4V['error']], 'Round 4 coupon: a used-up code looks like an unknown one');
    assert_equals([false, 'Minimum order not met'], [($r4V = $r4Validate('R4MIN'))['valid'], $r4V['error']], 'Round 4 coupon: the minimum order is reported for a usable code');
    for ($r4I = 0; $r4I < 7; $r4I++) $r4Validate('R4NOPE' . $r4I);
    assert_equals([false, 'Too many attempts'], [($r4V = $r4Validate('R4MIN'))['valid'], $r4V['error'] ?? null], 'Round 4 coupon: 10 attempts per 10 minutes per IP');
    // Another IP gets its own budget, but the same session (when one is active, as in the suite) does not.
    $r4Session = Security::sessionKey() !== '';
    assert_equals([false, $r4Session ? 'Too many attempts' : 'Minimum order not met'], [($r4V = $r4Validate('R4MIN', '127.0.4.2'))['valid'], $r4V['error'] ?? null],
        'Round 4 coupon: ' . ($r4Session ? 'the same session from another IP is throttled too' : 'another IP has its own budget'));
} finally {
    http_response_code(200);
    $pdo->exec("DELETE FROM coupon_usage_counters WHERE coupon_code = 'R4USED'");
    Pricing::setConfig($r4Pricing);
    $_SERVER = $r4ServerBefore;
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
}

// 6b. Checkout name and address: control characters (e.g. a forged mail seal) are removed at input.
ConsentService::setDefinitionsForTesting([]);
$pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (9841, 'default', 1, 'R4 parcel', 'r4-parcel', 'R4-PARCEL', 'simple', 1000, 'eur', 'active', 0, 'backorder')");
$pdo->exec("INSERT INTO shipping_methods (code, carrier, name, service_type, supports_cod, supports_pickup, free_above, sort_order) VALUES ('r4ship', 'gls', 'R4 parcel', 'parcel', 0, 0, NULL, 1)");
$pdo->exec("INSERT INTO shipping_rates (carrier, country, zone_code, method_code, weight_from_g, weight_to_g, price, cod_surcharge, sort_order)
    VALUES ('gls', '', 'domestic', 'r4ship', 0, 999999, 400, 0, 1)");
$pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
    VALUES ('r4pay', 'manual', 'R4 pay', '', 'offline', 0, 0, 0, 0, NULL, 0, 1, 1)");
$pdo->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('r4pay', 'SK')");
try {
    Cart::clear();
    Cart::add(9841, null, 1);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.4.3';
    $_SESSION['csrf_token'] = 'r4-checkout';
    $_POST = ['csrf_token' => 'r4-checkout', 'name' => $r4Forged, 'email' => 'r4-buyer@example.com', 'phone' => "+421\r\n900", 'country' => 'SK',
        'street' => "Audit\x001", 'city' => "Brati\x7Fslava", 'zip' => '81101', 'shipping_method' => 'r4ship', 'payment_method' => 'r4pay'];
    $r4Checkout = apiCheckout();
    $r4Order = Orders::byId((int)($r4Checkout['order_id'] ?? 0)) ?: [];
    $r4Stored = (string)($r4Order['customer_name'] ?? '') . (string)($r4Order['customer_phone'] ?? '') . (string)($r4Order['shipping_addr'] ?? '');
    assert_true($r4Order !== [] && !preg_match('/[\x00-\x1F\x7F]/', $r4Stored) && str_starts_with((string)$r4Order['customer_name'], 'Eva '),
        'Round 4 checkout: control characters are removed from name, phone and address (' . ($r4Checkout['error'] ?? 'ok') . ')');
} finally {
    $_POST = [];
    Cart::clear();
    $r4OrderIds = implode(',', array_map('intval', $pdo->query("SELECT id FROM orders WHERE customer_email = 'r4-buyer@example.com'")->fetchAll(PDO::FETCH_COLUMN)));
    if ($r4OrderIds !== '') {
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name <> 'orders'")->fetchAll(PDO::FETCH_COLUMN) as $r4Table) {
            if (in_array('order_id', array_column($pdo->query('PRAGMA table_info("' . $r4Table . '")')->fetchAll(PDO::FETCH_ASSOC), 'name'), true)) {
                $pdo->exec("DELETE FROM \"{$r4Table}\" WHERE order_id IN ({$r4OrderIds})");
            }
        }
        $pdo->exec("DELETE FROM orders WHERE id IN ({$r4OrderIds})");
    }
    $pdo->exec("DELETE FROM products WHERE id = 9841");
    $pdo->exec("DELETE FROM shipping_rates WHERE method_code = 'r4ship'");
    $pdo->exec("DELETE FROM shipping_methods WHERE code = 'r4ship'");
    $pdo->exec("DELETE FROM payment_method_countries WHERE method_code = 'r4pay'");
    $pdo->exec("DELETE FROM payment_methods WHERE code = 'r4pay'");
    ConsentService::setDefinitionsForTesting(null);
    $_SERVER = $r4ServerBefore;
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
}
