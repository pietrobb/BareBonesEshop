<?php

// Round 4 UX/SEO audit (account, admin, storefront SEO), through the real entry points under the PHP built-in
// server on a migrated SQLite fixture: cookie-consent withdrawal (cc_v1 + consent log), validated account addresses,
// linked labels, translated statuses and local dates, the downloads page, 404 for unknown account pages, store
// languages in the profile, the neutral form-trap message, admin input kept on rejected saves, paginated customers,
// bulk-action feedback, category soft 404 / trailing slash, JSON-LD backorder, ?lang= canonical/hreflang, legal canonicals.
echo "\n--- Audit round 4: account, admin and storefront SEO ---\n";

$r4Root = test_fixture_path('round4-account-admin-seo');
mkdir($r4Root . '/sessions', 0700, true);
mkdir($r4Root . '/cache', 0700, true);
$r4App = dirname(TEST_CLI_DIR);
$r4Db = new PDO('sqlite:' . $r4Root . '/shop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new Migrator($r4Db, $r4App . '/migrations', $r4Root . '/migrate.lock'))->apply();
$r4Db->exec("INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES ('default', 'r4-cat', 'R4 Cat', NULL, 'r4-cat', 0)");
$r4Cat = (int)$r4Db->lastInsertId();
$r4Product = $r4Db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, inventory_policy, product_type) VALUES ('default', ?, ?, ?, ?, 1000, ?, 'active', ?, ?)");
$r4Product->execute([$r4Cat, 'R4-STOCK', 'r4-stock', 'R4 Stock', 5, 'deny', 'simple']);
$r4Product->execute([$r4Cat, 'R4-BACK', 'r4-back', 'R4 Back', 0, 'backorder', 'simple']);
$r4Product->execute([$r4Cat, 'R4-PRE', 'r4-pre', 'R4 Pre', 0, 'preorder', 'simple']);
$r4Product->execute([$r4Cat, 'R4-EBOOK', 'r4-ebook', 'R4 Ebook', 0, 'deny', 'digital']);
Import::recountCategories($r4Db);
$r4Hash = password_hash('R4-pass-word', PASSWORD_BCRYPT, ['cost' => 4]);
$r4Customer = $r4Db->prepare("INSERT INTO customers (store_id, email, password_hash, name, status, locale) VALUES ('default', ?, ?, ?, 'active', 'sk')");
$r4Customer->execute(['r4-owner@example.test', $r4Hash, 'R4 Owner']);
$r4Owner = (int)$r4Db->lastInsertId();
$r4Customer->execute(['r4-other@example.test', $r4Hash, 'R4 Other']);
$r4Other = (int)$r4Db->lastInsertId();
for ($r4I = 1; $r4I <= 60; $r4I++) $r4Customer->execute(["r4-filler-{$r4I}@example.test", $r4Hash, "R4 Filler {$r4I}"]);
$r4Order = $r4Db->prepare("INSERT INTO orders (store_id, order_number, status, payment_status, customer_id, customer_name, customer_email, shipping_addr, payment_method,
    shipping_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, created_at) VALUES ('default', ?, ?, 'paid', ?, 'R4', 'r4@example.test', '{}', 'card', 'digital', 1000, 0, 0, 0, 1000, 'eur', '2026-01-15 23:30:00')");
$r4Order->execute(['R4-OWN', 'shipped', $r4Owner]);
$r4OwnOrder = (int)$r4Db->lastInsertId();
$r4Order->execute(['R4-FOREIGN', 'paid', $r4Other]);
$r4ForeignOrder = (int)$r4Db->lastInsertId();
$r4Db->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name) VALUES ('R4-EBOOK', 'default', 'R4 Handbook PDF', 'r4.pdf', 'r4.pdf')");
$r4Asset = (int)$r4Db->lastInsertId();
$r4Entitlements = [];
foreach ([$r4OwnOrder, $r4ForeignOrder] as $r4OrderId) {
    $r4Db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, (SELECT id FROM products WHERE sku = 'R4-EBOOK'), 'R4-EBOOK', 'R4 Ebook', 1, 1000, 1000)")->execute([$r4OrderId]);
    $r4Db->prepare("INSERT INTO digital_entitlements (order_id, order_item_id, asset_id, token_hash, status, download_count, download_limit, expires_at) VALUES (?, ?, ?, ?, 'active', 1, 3, ?)")
        ->execute([$r4OrderId, (int)$r4Db->lastInsertId(), $r4Asset, hash('sha256', 'r4-mail-' . $r4OrderId), time() + 86400]);
    $r4Entitlements[$r4OrderId] = (int)$r4Db->lastInsertId();
}

$r4Probe = stream_socket_server('tcp://127.0.0.1:0');
$r4Port = (int)substr(strrchr((string)stream_socket_get_name($r4Probe, false), ':'), 1);
fclose($r4Probe);
file_put_contents($r4Root . '/router.php', '<?php
$r4App = ' . var_export($r4App, true) . ';
$r4Dir = ' . var_export($r4Root, true) . ';
require $r4App . "/src/autoload.php";
$db = new PDO("sqlite:" . $r4Dir . "/shop.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($db, "sqlite");
Cache::setDir($r4Dir . "/cache");
JobQueue::setDir($r4Dir . "/jobs");
$config = require $r4App . "/config.php";
(new ReflectionProperty(Security::class, "cfg"))->setValue(null, ["app_url" => "http://127.0.0.1:' . $r4Port . '"] + $config);
DigitalDelivery::setConfigForTesting(["app_url" => ""] + $config);
Security::setRateDbForTesting(new PDO("sqlite:" . $r4Dir . "/rate.sqlite"));
$uri = (string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($uri === "/__seed") {
    session_start();
    $_SESSION["csrf_token"] = "r4-csrf";
    echo session_id();
    exit;
}
if ($uri === "/__flash") {
    session_start();
    echo json_encode($_SESSION["flash"] ?? null);
    exit;
}
chdir($r4App);
if ($uri === "/__admin") {
    session_start();
    $_SESSION["admin_role"] = "owner";
    StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), "/", "", "default"));
    I18n::setLocale("en");
    foreach (["Shared", "Catalog", "Customers", "Orders", "OrderOperations"] as $file) require_once $r4App . "/src/Entrypoints/Admin/" . $file . ".php";
    $fn = (string)$_GET["fn"];
    $fn();
    exit;
}
$entry = match ($uri) {
    "/", "/index.php", "/account.php", "/product.php" => $uri === "/" ? "index.php" : ltrim($uri, "/"),
    default => null,
};
if ($entry !== null) { $_SERVER["SCRIPT_NAME"] = $_SERVER["PHP_SELF"] = "/" . $entry; require $r4App . "/" . $entry; exit; }
$route = UrlAliases::entry(["path" => $uri, "query" => []]);
if ($route !== null) { $_GET = $route[1] + $_GET; $_SERVER["SCRIPT_NAME"] = "/" . $route[0]; require $r4App . "/" . $route[0]; exit; }
$script = UrlAliases::dispatch($uri);
if ($script !== null) { $_SERVER["SCRIPT_NAME"] = "/" . $script; require $r4App . "/" . $script; exit; }
http_response_code(404);
');
$r4Server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $r4Root . '/sessions', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $r4Root . '/php-error.log',
    '-d', 'date.timezone=Europe/Bratislava', '-S', "127.0.0.1:{$r4Port}", $r4Root . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $r4Root . '/server.log', 'a'], 2 => ['file', $r4Root . '/server.log', 'a']], $r4Pipes, $r4App);
$r4Http = static function (string $method, string $path, array $post = [], ?string &$sid = null, array $cookies = []) use ($r4Port): array {
    if ($sid) $cookies['PHPSESSID'] = $sid;
    $cookieHeader = $cookies ? 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => $k . '=' . rawurlencode((string)$v), array_keys($cookies), $cookies)) . "\r\n" : '';
    $context = stream_context_create(['http' => ['method' => $method, 'header' => "Content-Type: application/x-www-form-urlencoded\r\nHost: 127.0.0.1:{$r4Port}\r\n" . $cookieHeader,
        'content' => http_build_query($post), 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20]]);
    $body = @file_get_contents("http://127.0.0.1:{$r4Port}{$path}", false, $context);
    $result = ['status' => 0, 'location' => '', 'cookies' => [], 'body' => (string)$body];
    foreach (HttpResponseHeaders::last($http_response_header ?? null) as $index => $line) {
        if ($index === 0) $result['status'] = (int)(explode(' ', $line)[1] ?? 0);
        if (stripos($line, 'Location:') === 0) $result['location'] = trim(substr($line, 9));
        if (stripos($line, 'Set-Cookie:') === 0) $result['cookies'][] = trim(substr($line, 11));
        if (preg_match('/^Set-Cookie:\s*PHPSESSID=([^;]+)/i', $line, $cookie)) $sid = $cookie[1];
    }
    return $result;
};
$r4Head = static fn(string $html): string => (string)strstr($html, '</head>', true);
$r4T = require $r4App . '/lang/sk.php';
$r4E = static fn(string $key): string => htmlspecialchars((string)$r4T[$key], ENT_QUOTES, 'UTF-8');
try {
    $r4Sid = '';
    for ($r4Try = 0; $r4Try < 50 && $r4Sid === ''; $r4Try++) {
        usleep(100000);
        $r4Sid = $r4Http('GET', '/__seed', [], $r4Sid)['body'];
    }
    assert_true($r4Sid !== '', 'R4: fixture server is up');

    // --- 7: a too-fast registration (form trap) is not "too many attempts" ---
    $r4Guest = $r4Sid;
    $r4Register = $r4Http('GET', '/account.php?p=register', [], $r4Guest);
    preg_match('/name="register_token" value="([^"]+)"/', $r4Register['body'], $r4Token);
    $r4Fast = $r4Http('POST', '/account.php', ['csrf_token' => 'r4-csrf', 'action' => 'register', 'register_token' => $r4Token[1] ?? '', 'name' => 'Fast', 'email' => 'r4-fast@example.test', 'password' => 'R4-pass-word'], $r4Guest);
    assert_true(str_contains($r4Fast['body'], $r4E('account.form_retry')) && !str_contains($r4Fast['body'], $r4E('account.rate_limited')),
        'R4 register: a submit faster than the form trap allows shows a neutral "try again", not the rate-limit message');

    // --- 3: every account form label is linked to its control; autocomplete hints ---
    $r4Bare = static fn(string $html): int => preg_match_all('#<label>(?!\s*<input)#', preg_replace('#<div class="form-trap".*?</div>#s', '', $html));
    $r4Linked = static function (string $html): bool {
        preg_match_all('#<label for="([^"]+)"#', $html, $m);
        foreach ($m[1] as $id) if (!str_contains($html, 'id="' . $id . '"')) return false;
        return $m[1] !== [];
    };
    $r4Forgot = $r4Http('GET', '/account.php?p=forgot', [], $r4Guest)['body'];
    assert_true($r4Bare($r4Register['body']) === 0 && $r4Linked($r4Register['body']) && $r4Bare($r4Forgot) === 0 && $r4Linked($r4Forgot)
        && str_contains($r4Register['body'], 'autocomplete="new-password"') && str_contains($r4Register['body'], 'autocomplete="email"'),
        'R4 labels: register and forgot labels point at their inputs, with email/new-password autocomplete');

    $r4Http('GET', '/account.php?p=login', [], $r4Sid);
    $r4Login = $r4Http('POST', '/account.php', ['csrf_token' => 'r4-csrf', 'action' => 'login', 'email' => 'r4-owner@example.test', 'password' => 'R4-pass-word'], $r4Sid);
    assert_equals(302, $r4Login['status'], 'R4: the fixture customer signs in');
    $r4Csrf = 'r4-csrf';
    foreach (['profile', 'change-password', 'privacy', 'addresses'] as $r4Page) {
        $r4Html = $r4Http('GET', '/account.php?p=' . $r4Page, [], $r4Sid)['body'];
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $r4Html, $r4M)) $r4Csrf = $r4M[1];
        assert_true($r4Bare($r4Html) === 0 && $r4Linked($r4Html), "R4 labels: every label on the {$r4Page} page is linked to its control");
    }
    $r4ChangePassword = $r4Http('GET', '/account.php?p=change-password', [], $r4Sid)['body'];
    assert_true(str_contains($r4ChangePassword, 'autocomplete="current-password"') && str_contains($r4ChangePassword, 'autocomplete="new-password"'),
        'R4 labels: change password uses current-password/new-password autocomplete');

    // --- 6: the profile only offers the current store's languages ---
    $r4Profile = $r4Http('GET', '/account.php?p=profile', [], $r4Sid)['body'];
    preg_match_all('#<select id="account-profile-locale"[^>]*>(.*?)</select>#s', $r4Profile, $r4Select);
    preg_match_all('#<option value="([a-z]{2})"#', $r4Select[1][0] ?? '', $r4Locales);
    assert_equals(array_map('strval', (array)Bootstrap::stores()['stores']['default']['languages']), $r4Locales[1], 'R4 profile: the language list is the current store\'s languages only');

    // --- 5: downloads page, account-issued link, 404 for unknown pages ---
    $r4Downloads = $r4Http('GET', '/account.php?p=downloads', [], $r4Sid);
    assert_true($r4Downloads['status'] === 200 && str_contains($r4Downloads['body'], 'R4 Handbook PDF') && str_contains($r4Downloads['body'], 'R4-OWN')
        && !str_contains($r4Downloads['body'], 'R4-FOREIGN') && str_contains($r4Downloads['body'], '<td>2</td>')
        && str_contains($r4Downloads['body'], 'p=downloads') && !str_contains($r4Downloads['body'], $r4E('account.page_not_found')),
        'R4 downloads: the page lists only the customer\'s own entitlement with the downloads left, linked in the account navigation');
    $r4Issue = $r4Http('POST', '/account.php', ['csrf_token' => $r4Csrf, 'action' => 'download', 'id' => (string)$r4Entitlements[$r4OwnOrder]], $r4Sid);
    $r4NewToken = preg_match('#/download\.php\?token=([0-9a-f]{64})$#', $r4Issue['location'], $r4M) ? $r4M[1] : '';
    $r4StoredHash = (string)$r4Db->query('SELECT token_hash FROM digital_entitlements WHERE id = ' . $r4Entitlements[$r4OwnOrder])->fetchColumn();
    assert_true($r4Issue['status'] === 303 && $r4NewToken !== '' && hash_equals($r4StoredHash, hash('sha256', $r4NewToken)),
        'R4 downloads: the account issues a fresh download link for its own entitlement (303 to download.php)');
    $r4ForeignIssue = $r4Http('POST', '/account.php', ['csrf_token' => $r4Csrf, 'action' => 'download', 'id' => (string)$r4Entitlements[$r4ForeignOrder]], $r4Sid);
    assert_true($r4ForeignIssue['status'] === 200 && $r4ForeignIssue['location'] === '' && str_contains($r4ForeignIssue['body'], $r4E('account.download_unavailable'))
        && (string)$r4Db->query('SELECT token_hash FROM digital_entitlements WHERE id = ' . $r4Entitlements[$r4ForeignOrder])->fetchColumn() === hash('sha256', 'r4-mail-' . $r4ForeignOrder),
        'R4 downloads: another customer\'s entitlement is refused and left untouched');
    assert_equals(404, $r4Http('GET', '/account.php?p=bogus', [], $r4Sid)['status'], 'R4 account: an unknown ?p= is a real 404');

    // --- 4: translated statuses and dates in the shop timezone and locale format ---
    $r4Orders = $r4Http('GET', '/account.php?p=orders', [], $r4Sid)['body'];
    $r4Detail = $r4Http('GET', '/account.php?p=order&id=' . $r4OwnOrder, [], $r4Sid)['body'];
    $r4Local = (new DateTimeImmutable('2026-01-15 23:30:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Bratislava'))->format((string)$r4T['format.datetime']);
    foreach (['list' => $r4Orders, 'detail' => $r4Detail] as $r4Label => $r4Html) {
        assert_true(str_contains($r4Html, '>' . $r4E('order_status.shipped') . '</span>') && str_contains($r4Html, '>' . $r4E('payment_status.paid') . '</span>')
            && str_contains($r4Html, $r4Local) && !str_contains($r4Html, '2026-01-15 23:30'),
            "R4 orders {$r4Label}: statuses are translated and the date is local ({$r4Local})");
    }

    // --- 2: address validation, typed values kept, country select and translated types ---
    $r4Bad = $r4Http('POST', '/account.php', ['csrf_token' => $r4Csrf, 'action' => 'address_add', 'type' => 'billing', 'name' => '', 'street' => 'R4 Typed Street 1',
        'city' => 'Bratislava', 'zip' => '81101', 'country' => 'XX'], $r4Sid);
    assert_true($r4Bad['status'] === 200 && str_contains($r4Bad['body'], $r4E('account.err_address_required')) && str_contains($r4Bad['body'], $r4E('account.err_address_country'))
        && str_contains($r4Bad['body'], 'value="R4 Typed Street 1"') && (bool)preg_match('#<option value="billing" selected>#', $r4Bad['body'])
        && !str_contains($r4Bad['body'], $r4E('account.saved')) && (int)$r4Db->query('SELECT COUNT(*) FROM customer_addresses')->fetchColumn() === 0,
        'R4 addresses: a missing name and an unknown country are refused with field errors and the typed values kept');
    $r4Good = $r4Http('POST', '/account.php', ['csrf_token' => $r4Csrf, 'action' => 'address_add', 'type' => 'billing', 'name' => 'R4 Owner', 'street' => 'R4 Street 1',
        'city' => 'Bratislava', 'zip' => '81101', 'country' => 'sk'], $r4Sid);
    $r4Saved = $r4Http('GET', '/account.php?p=addresses&ok=1', [], $r4Sid)['body'];
    assert_true($r4Good['status'] === 302 && (string)$r4Db->query('SELECT country FROM customer_addresses')->fetchColumn() === 'SK'
        && str_contains($r4Saved, '<strong>' . $r4E('account.addr_type_billing') . '</strong>') && str_contains($r4Saved, '<select id="account-address-country" name="country"'),
        'R4 addresses: a valid address is saved; the list shows the translated type and the form a country select');

    // --- 1: cookie consent withdrawal writes cc_v1, clears the legacy cookie and is logged ---
    $r4Consent = $r4Http('POST', '/account.php', ['csrf_token' => $r4Csrf, 'action' => 'cookie_consent', 'method' => 'preferences', 'analytics' => '1'], $r4Sid, ['cookie_consent' => '{"analytics":true,"marketing":true}']);
    $r4Cc = array_values(array_filter($r4Consent['cookies'], static fn(string $c): bool => str_starts_with($c, 'cc_v1=')));
    $r4CcValue = $r4Cc ? json_decode(urldecode((string)strstr(substr($r4Cc[0], 6), ';', true)), true) : null;
    $r4Legacy = array_filter($r4Consent['cookies'], static fn(string $c): bool => str_starts_with($c, 'cookie_consent=') && stripos($c, 'expires=') !== false);
    $r4Log = $r4Db->query('SELECT customer_id, categories_json, method FROM cookie_consent ORDER BY id DESC LIMIT 1')->fetch();
    assert_true($r4Consent['status'] === 302 && $r4CcValue === ['necessary' => true, 'analytics' => true, 'marketing' => false] && $r4Legacy !== []
        && (int)($r4Log['customer_id'] ?? 0) === $r4Owner && (json_decode((string)($r4Log['categories_json'] ?? ''), true)['marketing'] ?? null) === false,
        'R4 consent: the account choice is stored as cc_v1 (banner format), the legacy cookie_consent is cleared and the change is logged');
    $r4Privacy = $r4Http('GET', '/account.php?p=privacy', [], $r4Sid, ['cc_v1' => '{"necessary":true,"analytics":false,"marketing":true}'])['body'];
    assert_true((bool)preg_match('#name="marketing" value="1" checked#', $r4Privacy) && !preg_match('#name="analytics" value="1" checked#', $r4Privacy),
        'R4 consent: the privacy page shows the banner\'s cc_v1 choice');

    // --- 11/12/13: category soft 404, trailing slash, JSON-LD availability ---
    $r4Past = $r4Http('GET', '/category/r4-cat?pg=999', [], $r4Sid);
    $r4First = $r4Http('GET', '/category/r4-cat', [], $r4Sid);
    assert_true($r4Past['status'] === 404 && str_contains($r4Head($r4Past['body']), 'content="noindex"') && $r4First['status'] === 200
        && str_contains($r4Head($r4First['body']), 'index,follow'),
        'R4 category: a page past the last one is a 404 with noindex; page 1 stays indexable');
    $r4Slash = $r4Http('GET', '/category/r4-cat/?sort=name_asc', [], $r4Sid);
    assert_true($r4Slash['status'] === 301 && str_ends_with($r4Slash['location'], '/category/r4-cat?sort=name_asc'),
        'R4 category: a trailing slash 301s to the canonical category URL (' . $r4Slash['location'] . ')');
    foreach (['r4-stock' => 'InStock', 'r4-back' => 'BackOrder', 'r4-pre' => 'PreOrder'] as $r4Slug => $r4Schema) {
        $r4ProductPage = $r4Http('GET', '/product.php?slug=' . $r4Slug, [], $r4Sid)['body'];
        assert_true(str_contains($r4ProductPage, 'https:\/\/schema.org\/' . $r4Schema) || str_contains($r4ProductPage, 'https://schema.org/' . $r4Schema),
            "R4 JSON-LD: {$r4Slug} offers availability {$r4Schema}");
    }

    // --- 14: secondary store language canonical/hreflang/switcher; legal pages canonical ---
    $r4Spanish = $r4Http('GET', '/en/?lang=es', [], $r4Sid)['body'];
    // Seo::head() joins app_url from config.php (absolute) or stays base-relative without it: compare paths.
    $r4AppUrl = rtrim(trim((string)((require __DIR__ . '/../../../config.php')['app_url'] ?? '')), '/');
    $r4Paths = static fn(string $html): string => str_replace(array_filter(['http://127.0.0.1:' . $r4Port, $r4AppUrl]), '', $html);
    $r4SpanishHead = $r4Paths($r4Head($r4Spanish));
    assert_true(str_contains($r4Spanish, '<html lang="es"') && str_contains($r4SpanishHead, '<link rel="canonical" href="/en/?lang=es">')
        && str_contains($r4SpanishHead, 'hreflang="es" href="/en/?lang=es"') && str_contains($r4SpanishHead, 'hreflang="en" href="/en/"')
        && (bool)preg_match('#<div class="lang-switch">.*href="/en/\?lang=es"[^>]*>ES</a>#s', $r4Spanish),
        'R4 languages: a store\'s further language has its own canonical and hreflang (?lang=es) and a switcher entry');
    $r4Legal = $r4Paths($r4Head($r4Http('GET', '/?page=privacy', [], $r4Sid)['body']));
    assert_true(str_contains($r4Legal, '<link rel="canonical" href="/?page=privacy">') && str_contains($r4Legal, 'hreflang="cs" href="/cs/?page=privacy"'),
        'R4 legal pages: the privacy page has a canonical and hreflang alternates');

    // --- 9: admin customers are paginated and the heading shows the real total ---
    $r4Admin = '';
    $r4Http('GET', '/__seed', [], $r4Admin);
    $r4Total = (int)$r4Db->query("SELECT COUNT(*) FROM customers WHERE store_id = 'default'")->fetchColumn();
    $r4List = $r4Http('GET', '/__admin?fn=pageCustomers', [], $r4Admin)['body'];
    $r4Page2 = $r4Http('GET', '/__admin?fn=pageCustomers&page=2', [], $r4Admin)['body'];
    assert_true(str_contains($r4List, '<span class="muted">(' . $r4Total . ')</span>') && substr_count($r4List, 'p=customer&amp;id=') + substr_count($r4List, 'p=customer&id=') === 50
        && str_contains($r4List, 'p=customers&amp;q=&amp;page=2') && substr_count($r4Page2, 'p=customer&id=') === $r4Total - 50,
        'R4 admin customers: 50 per page with pagination, the heading counts all ' . $r4Total);

    // --- 10: bulk actions give feedback and keep the filters; order bulk status needs an explicit choice ---
    $r4Bulk = $r4Http('POST', '/__admin?fn=handleBulkAction', ['bulk_action' => 'archive', 'q' => 'r4', 'status' => 'draft', 'stock' => 'low', 'page' => '2'], $r4Admin);
    $r4Flash = json_decode($r4Http('GET', '/__flash', [], $r4Admin)['body'], true);
    assert_true($r4Bulk['location'] === 'admin.php?p=products&q=r4&status=draft&stock=low&page=2' && ($r4Flash['type'] ?? '') === 'err'
        && ($r4Flash['msg'] ?? '') === (require $r4App . '/lang/en.php')['admin.products.bulk.none_selected'],
        'R4 admin bulk: nothing selected flashes an error and returns to the filtered list (' . $r4Bulk['location'] . ')');
    $r4OrdersPage = $r4Http('GET', '/__admin?fn=pageOrders', [], $r4Admin)['body'];
    assert_true((bool)preg_match('#<select id="bulk-status" name="new_status" required><option value="">#', $r4OrdersPage) && str_contains($r4OrdersPage, 'type="submit" data-confirm="'),
        'R4 admin orders: bulk status starts with an empty required choice and asks for confirmation');
    $r4BulkStatus = $r4Http('POST', '/__admin?fn=handleOrdersBulkStatus', ['order_ids' => [$r4OwnOrder], 'new_status' => ''], $r4Admin);
    $r4Flash = json_decode($r4Http('GET', '/__flash', [], $r4Admin)['body'], true);
    assert_true(($r4Flash['type'] ?? '') === 'err' && (string)$r4Db->query('SELECT status FROM orders WHERE id = ' . $r4OwnOrder)->fetchColumn() === 'shipped',
        'R4 admin orders: a bulk status post without a status changes nothing and says so');

    // --- 8: rejected admin saves keep the typed input (one-shot); a new product does not land on id=0 ---
    $r4Db->exec("INSERT INTO pages (store_id, slug, title, body, status) VALUES ('default', 'r4-taken', 'Taken', '', 'active')");
    $r4PageSave = $r4Http('POST', '/__admin?fn=handleCmsPageSave', ['slug' => 'r4-taken', 'title' => 'R4 Typed Title', 'body' => '<p>R4 typed body</p>', 'status' => 'active'], $r4Admin);
    $r4PageForm = $r4Http('GET', '/__admin?fn=pageCmsEdit', [], $r4Admin)['body'];
    $r4PageAgain = $r4Http('GET', '/__admin?fn=pageCmsEdit', [], $r4Admin)['body'];
    assert_true($r4PageSave['location'] === 'admin.php?p=page_edit' && str_contains($r4PageForm, 'R4 Typed Title') && str_contains($r4PageForm, 'R4 typed body')
        && !str_contains($r4PageAgain, 'R4 Typed Title'),
        'R4 admin CMS: a taken slug reopens the new-page form with the typed title and body, once');
    $r4ProductSave = $r4Http('POST', '/__admin?fn=handleProductSave', ['sku' => '', 'name' => 'R4 Typed Product', 'price' => '1234', 'category_id' => (string)$r4Cat], $r4Admin);
    $r4ProductForm = $r4Http('GET', '/__admin?fn=pageProduct', [], $r4Admin)['body'];
    assert_true($r4ProductSave['location'] === 'admin.php?p=product' && str_contains($r4ProductForm, 'R4 Typed Product'),
        'R4 admin product: a rejected new product returns to the prefilled new-product form, not id=0');
} finally {
    if (is_resource($r4Server)) { proc_terminate($r4Server); proc_close($r4Server); }
    $r4Log = is_file($r4Root . '/php-error.log') ? trim((string)file_get_contents($r4Root . '/php-error.log')) : '';
    assert_equals('', $r4Log, 'R4: the requests logged no PHP errors');
    $r4Db = null;
    $r4Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r4Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($r4Tree as $r4Path) $r4Path->isDir() ? @rmdir($r4Path->getPathname()) : @unlink($r4Path->getPathname());
    @rmdir($r4Root);
}
