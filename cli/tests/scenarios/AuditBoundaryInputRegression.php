<?php
/**
 * Audit 2026-10-12 / A10.2 / low findings:
 *  - array instead of string (csrf_token[]=x, email[]=x, q[]=x ...) reached string-typed functions
 *    (hash_equals, trim, Security::clean ...) and ended as TypeError → HTTP 500;
 *  - LIKE searches had no ESCAPE clause: "50%" / "a_b" were wildcards, and the admin catalog's
 *    backslash escaping matched nothing on SQLite;
 *  - the Meta catalog CSV had no spreadsheet formula-injection guard.
 * Every case runs through the real HTTP entrypoints (PHP built-in server) or the real admin/list code.
 */

echo "\n--- Audit A10.2: array inputs, LIKE ESCAPE, Meta CSV formula injection ---\n";

$a102Root = dirname(TEST_CLI_DIR);
$a102Router = test_fixture_path('a102-router') . '.php';
file_put_contents($a102Router, '<?php
require ' . var_export($a102Root . '/src/autoload.php', true) . ';
Db::init(new PDO(' . var_export('sqlite:' . $testDbPath, true) . ', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
Security::setRateDbForTesting(new PDO("sqlite::memory:"));
UrlAliases::setRules([]);
(new ReflectionProperty(Security::class, "cfg"))->setValue(null, ["app_url" => "https://shop.example"] + (require ' . var_export($a102Root . '/config.php', true) . '));
$path = (string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
chdir(' . var_export($a102Root, true) . ');
$script = ltrim($path, "/");
if ($script === "" ) $script = "index.php";
if (!preg_match("/^[a-z]+\\.php$/", $script) || !is_file($script)) { http_response_code(404); exit; }
require $script;
');
$a102Probe = stream_socket_server('tcp://127.0.0.1:0');
$a102Port = (int)substr(strrchr((string)stream_socket_get_name($a102Probe, false), ':'), 1);
fclose($a102Probe);
$a102Log = test_fixture_path('a102-router') . '.log';
$a102Server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$a102Port}", $a102Router],
    [0 => ['pipe', 'r'], 1 => ['file', $a102Log, 'a'], 2 => ['file', $a102Log, 'a']], $a102Pipes, $a102Root);
$a102Request = static function (string $method, string $target, array $options = []) use ($a102Port): array {
    $headers = 'Host: shop.example' . "\r\n";
    if (!empty($options['cookie'])) $headers .= 'Cookie: ' . $options['cookie'] . "\r\n";
    $content = null;
    if (array_key_exists('json', $options)) {
        $content = json_encode($options['json']);
        $headers .= "Content-Type: application/json\r\n";
    } elseif (array_key_exists('form', $options)) {
        $content = http_build_query($options['form']);
        $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    if (!empty($options['csrf_header'])) $headers .= 'X-CSRF-Token: ' . $options['csrf_header'] . "\r\n";
    $http = ['method' => $method, 'header' => $headers, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30];
    if ($content !== null) $http['content'] = $content;
    $body = @file_get_contents("http://127.0.0.1:{$a102Port}{$target}", false, stream_context_create(['http' => $http]));
    $responseHeaders = $http_response_header ?? [];
    $cookies = [];
    foreach ($responseHeaders as $line) {
        if (stripos($line, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $line, $m)) $cookies[$m[1]] = $m[2];
    }
    return ['status' => (int)(explode(' ', (string)($responseHeaders[0] ?? ''))[1] ?? 0), 'body' => (string)$body, 'cookies' => $cookies];
};
$a102Session = static function () use ($a102Request): array {
    $page = $a102Request('GET', '/account.php?p=login');
    $cookie = implode('; ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($page['cookies']), $page['cookies']));
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page['body'], $m);
    return ['cookie' => $cookie, 'csrf' => $m[1] ?? ''];
};
$a102Fatal = static fn(array $response): bool => $response['status'] === 500 || $response['status'] === 0
    || preg_match('/TypeError|Fatal error|Uncaught|Internal error/', $response['body']) === 1;

$a102GetNames = ['action', 'amount', 'carrier', 'cart_recover', 'cart_unsubscribe', 'cat', 'category', 'category_id', 'cod', 'confirmation_token',
    'country', 'currency', 'email', 'filter', 'filters', 'channel', 'id', 'ktype', 'lang', 'limit', 'manufacturer', 'model', 'msg', 'next', 'ok',
    'order', 'p', 'page', 'path', 'payment_status', 'period', 'pg', 'pmax', 'pmin', 'price_max', 'price_min', 'provider', 'q', 'relation_type',
    'shipping_method', 'slug', 'sort', 'spec', 'status', 'stock', 'subcat', 'subtotal', 'token', 'volume_cm3', 'weight_g'];
$a102PostNames = ['account_id', 'action', 'amount', 'analytics', 'carrier', 'cart_action', 'city', 'code', 'company', 'company_website', 'confirm',
    'contact', 'country', 'current', 'customer_id', 'email', 'items', 'locale', 'marketing', 'method', 'name', 'new', 'next', 'note', 'notes', 'op',
    'options', 'order_id', 'password', 'phone', 'price', 'product_id', 'q', 'qty', 'quantity', 'reason', 'redirect', 'register_token', 'return_to',
    'review_id', 'rma_id', 'shipping_method', 'shipping_method_code', 'payment', 'payment_method', 'sku', 'street', 'token', 'type', 'url', 'username',
    'vat_id', 'variant_id', 'zip', 'consents', 'rating', 'title', 'body', 'coupon', 'coupon_code', 'pickup_point', 'billing', 'shipping',
    'admin_username', 'admin_password', 'admin_mfa', 'admin_login', 'mfa'];
$a102Arrays = static function (array $names, array $fixed = []): array {
    $values = [];
    foreach ($names as $name) $values[$name] = ['x' => ['1']];
    return $fixed + $values;
};
$a102ApiActions = ['categories', 'products', 'product', 'search', 'filters', 'cart', 'cart_add', 'cart_update', 'cart_restore', 'cart_enriched',
    'checkout', 'consent_definitions', 'shipping_methods', 'shipping_rates', 'payment_methods', 'search_suggest', 'validate_coupon',
    'review_submit', 'wishlist', 'wishlist_toggle', 'cookie_consent', 'vehicle_manufacturers', 'vehicle_models', 'vehicle_types',
    'vehicle_parts', 'oe_search', 'parametric_search', 'product_specs', 'product_oe', 'product_vehicles', 'page', 'bbf_categories'];

$a102Cases = [];
foreach (['home', 'category', 'product', 'vehicle', 'search', 'oe', 'privacy', 'cart', 'cart-unsubscribe', 'checkout', 'order-done'] as $a102Page) {
    $a102Cases[] = ["GET /?page={$a102Page} with array query values", 'GET', '/?' . http_build_query($a102Arrays($a102GetNames, ['page' => $a102Page])), []];
}
$a102Cases[] = ['GET / with page[]', 'GET', '/?page[]=cart', []];
foreach (['login', 'register', 'forgot', 'reset', 'orders', 'privacy', 'verify'] as $a102P) {
    $a102Cases[] = ["GET account.php?p={$a102P} with array query values", 'GET', '/account.php?' . http_build_query($a102Arrays($a102GetNames, ['p' => $a102P])), []];
}
foreach ($a102ApiActions as $a102Action) {
    $a102Cases[] = ["GET api.php?action={$a102Action} with array query values", 'GET', '/api.php?' . http_build_query($a102Arrays($a102GetNames, ['action' => $a102Action])), []];
}
foreach (['feed.php', 'redirect.php', 'wishlist.php', 'download.php', 'page.php', 'product.php', 'health.php', 'admin.php', 'sitemap.php', 'robots.php'] as $a102Script) {
    $a102Cases[] = ["GET {$a102Script} with array query values", 'GET', '/' . $a102Script . '?' . http_build_query($a102Arrays($a102GetNames)), []];
}

try {
    for ($i = 0; $i < 50 && $a102Request('GET', '/health.php')['status'] === 0; $i++) usleep(100000);
    $a102S = $a102Session();
    assert_true($a102S['cookie'] !== '' && $a102S['csrf'] !== '', 'A10.2 fixture: a storefront session with a CSRF token was obtained');

    // POST forms: a csrf_token[] array, and a valid token with every other field as an array.
    $a102Posts = [
        ['index.php cart', '/', ['cart_action' => 'add']],
        ['index.php cart update', '/', ['cart_action' => 'update']],
        ['index.php cart remove', '/', ['cart_action' => 'remove']],
        ['checkout', '/?page=checkout', ['checkout' => '1']],
        ['wishlist toggle', '/wishlist.php', ['op' => 'toggle']],
        ['wishlist remove', '/wishlist.php', ['op' => 'remove']],
        ['admin login', '/admin.php', ['admin_login' => '1']],
    ];
    foreach (['register', 'login', 'logout', 'forgot', 'reset', 'cookie_consent', 'resend_verification'] as $a102Action) {
        $a102Posts[] = ["account {$a102Action}", '/account.php', ['action' => $a102Action]];
    }
    foreach ($a102Posts as [$a102Label, $a102Target, $a102Fixed]) {
        $a102Cases[] = ["POST {$a102Label} with csrf_token[]", 'POST', $a102Target, ['cookie' => $a102S['cookie'], 'form' => $a102Fixed + ['csrf_token' => ['x']]]];
        $a102Cases[] = ["POST {$a102Label} with array fields", 'POST', $a102Target, ['cookie' => $a102S['cookie'], 'form' => $a102Arrays($a102PostNames, $a102Fixed + ['csrf_token' => $a102S['csrf']])]];
    }
    foreach ($a102ApiActions as $a102Action) {
        $a102Cases[] = ["POST api.php?action={$a102Action} with csrf_token[] form", 'POST', '/api.php?action=' . $a102Action, ['cookie' => $a102S['cookie'], 'form' => ['csrf_token' => ['x']]]];
        $a102Cases[] = ["POST api.php?action={$a102Action} with array JSON fields", 'POST', '/api.php?action=' . $a102Action,
            ['cookie' => $a102S['cookie'], 'csrf_header' => $a102S['csrf'], 'json' => $a102Arrays($a102PostNames)]];
        $a102Cases[] = ["POST api.php?action={$a102Action} with JSON array body", 'POST', '/api.php?action=' . $a102Action,
            ['cookie' => $a102S['cookie'], 'csrf_header' => $a102S['csrf'], 'json' => ['a', 'b']]];
    }

    // A logged-in customer reaches the account actions behind Customers::isLoggedIn().
    $a102Email = 'a102-array-' . bin2hex(random_bytes(3)) . '@example.com';
    Customers::register($a102Email, 'A102-strong-pass!', 'Array Tester');
    $a102Customer = $a102Session();
    $a102Login = $a102Request('POST', '/account.php', ['cookie' => $a102Customer['cookie'],
        'form' => ['csrf_token' => $a102Customer['csrf'], 'action' => 'login', 'email' => $a102Email, 'password' => 'A102-strong-pass!']]);
    $a102Cookies = $a102Login['cookies'] + array_column(array_map(fn($p) => explode('=', $p, 2), explode('; ', $a102Customer['cookie'])), 1, 0);
    $a102CustomerCookie = implode('; ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($a102Cookies), $a102Cookies));
    $a102Account = $a102Request('GET', '/account.php', ['cookie' => $a102CustomerCookie]);
    assert_true(str_contains($a102Account['body'], $a102Email), 'A10.2 fixture: customer is logged in for account actions');
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $a102Account['body'], $a102M);
    $a102CustomerCsrf = $a102M[1] ?? '';
    foreach (['change_password', 'update_profile', 'address_add', 'address_update', 'address_delete', 'rma_request', 'resend_verification', 'data_export'] as $a102Action) {
        $a102Cases[] = ["POST logged-in account {$a102Action} with array fields", 'POST', '/account.php',
            ['cookie' => $a102CustomerCookie, 'form' => $a102Arrays($a102PostNames, ['action' => $a102Action, 'csrf_token' => $a102CustomerCsrf])]];
    }
    $a102Cases[] = ['POST logged-in account erase_account with csrf_token[]', 'POST', '/account.php',
        ['cookie' => $a102CustomerCookie, 'form' => ['action' => 'erase_account', 'csrf_token' => ['x']]]];
    foreach (['orders', 'order', 'addresses', 'returns', 'privacy', 'profile'] as $a102P) {
        $a102Cases[] = ["GET logged-in account.php?p={$a102P} with array query values", 'GET', '/account.php?' . http_build_query($a102Arrays($a102GetNames, ['p' => $a102P])), ['cookie' => $a102CustomerCookie]];
    }

    foreach ($a102Cases as [$a102Label, $a102Method, $a102Target, $a102Options]) {
        $a102Response = $a102Request($a102Method, $a102Target, $a102Options);
        assert_true(!$a102Fatal($a102Response), "A10.2 array input: {$a102Label} is handled without 500 (got {$a102Response['status']})");
    }
    $a102ServerLog = (string)@file_get_contents($a102Log);
    preg_match_all('/(?:PHP Fatal error|Uncaught [A-Za-z]+Error|API error \[[^\]]*\])[^\n]{0,260}/', $a102ServerLog, $a102Errors);
    assert_equals([], array_values(array_unique($a102Errors[0])), 'A10.2 array input: no fatal/TypeError/API error in the server log');

    // The valid token still works after the hardening (no false negatives); fresh session because
    // the fuzzed logout above legitimately ended the first one.
    $a102Fresh = $a102Session();
    $a102Valid = $a102Request('POST', '/account.php', ['cookie' => $a102Fresh['cookie'], 'form' => ['csrf_token' => $a102Fresh['csrf'], 'action' => 'cookie_consent', 'analytics' => '1']]);
    assert_true(in_array($a102Valid['status'], [200, 302, 303], true), "A10.2 CSRF: a valid scalar token is still accepted (got {$a102Valid['status']})");
} finally {
    proc_terminate($a102Server);
    proc_close($a102Server);
    @unlink($a102Router);
    @unlink($a102Log);
}

// --- CSRF validation itself: arrays and other non-strings are rejected, never compared. ---
$a102OldSession = $_SESSION ?? [];
$a102OldPost = $_POST;
$_SESSION['csrf_token'] = str_repeat('a', 64);
foreach ([['x'], ['a' => str_repeat('a', 64)], 123, null] as $a102Bad) {
    $_POST = ['csrf_token' => $a102Bad];
    $a102Result = null;
    try { $a102Result = Security::csrfValidate(); } catch (Throwable $e) { $a102Result = get_class($e); }
    assert_true($a102Result === false, 'A10.2 CSRF: csrfValidate rejects ' . get_debug_type($a102Bad) . ' (got ' . json_encode($a102Result) . ')');
}
$_POST = ['csrf_token' => str_repeat('a', 64)];
assert_true(Security::csrfValidate(), 'A10.2 CSRF: matching scalar token validates');
$_POST = $a102OldPost;
$_SESSION = $a102OldSession;

// --- LIKE: "%" and "_" are literal characters in admin and catalog searches on every driver. ---
$a102Catalog = StoreContext::current()->catalogId();
$a102Store = StoreContext::current()->id();
$pdo->exec("DELETE FROM products WHERE id BETWEEN 9421 AND 9424");
$a102Insert = $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock) VALUES (?, ?, 1, ?, ?, ?, 'simple', 100, 'eur', 'active', 1)");
$a102Insert->execute([9421, $a102Catalog, 'Zľava 50% Lampa', 'a102-lampa-50', 'A102_X1']);
$a102Insert->execute([9422, $a102Catalog, 'Lampa 500 W', 'a102-lampa-500', 'A102AX1']);
$a102Insert->execute([9423, $a102Catalog, 'Lampa back\\slash', 'a102-lampa-bs', 'A102-BS']);
$a102AdminProducts = static function (string $search): array {
    $stream = fopen('php://memory', 'w+');
    exportProductsCsv($stream, $search);
    rewind($stream);
    $csv = (string)stream_get_contents($stream);
    fclose($stream);
    preg_match_all('/a102-lampa-[a-z0-9]+/', $csv, $m);
    sort($m[0]);
    return array_values(array_unique($m[0]));
};
require_once $a102Root . '/src/Entrypoints/Admin/Catalog.php';
assert_equals(['a102-lampa-50'], $a102AdminProducts('50%'), 'A10.2 LIKE: admin product search "50%" finds only the literal 50%');
assert_equals(['a102-lampa-50'], $a102AdminProducts('A102_X'), 'A10.2 LIKE: admin product search "_" is literal');
assert_equals(['a102-lampa-bs'], $a102AdminProducts('back\\slash'), 'A10.2 LIKE: admin product search keeps a literal backslash');
assert_equals(['a102-lampa-50', 'a102-lampa-500', 'a102-lampa-bs'], $a102AdminProducts('Lampa'), 'A10.2 LIKE: plain admin product search still matches substrings');
$pdo->exec("DELETE FROM customers WHERE email LIKE 'a102-like-%'");
foreach (['a102-like-50%off@example.com', 'a102-like-500@example.com', 'a102-like-a_b@example.com', 'a102-like-axb@example.com'] as $a102Mail) {
    $pdo->prepare("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES (?, ?, 'x', 'Like', 'active')")->execute([$a102Store, $a102Mail]);
}
$a102CustomerEmails = static fn(string $q): array => array_values(array_filter(array_map(fn($r) => $r['email'], Customers::adminList($q)), fn($e) => str_starts_with($e, 'a102-like-')));
assert_equals(['a102-like-50%off@example.com'], $a102CustomerEmails('50%'), 'A10.2 LIKE: admin customer search "50%" is literal');
assert_equals(['a102-like-a_b@example.com'], $a102CustomerEmails('a_b'), 'A10.2 LIKE: admin customer search "_" is literal');
assert_equals(4, count($a102CustomerEmails('a102-like')), 'A10.2 LIKE: plain admin customer search still matches substrings');
$pdo->exec("DELETE FROM products WHERE id BETWEEN 9421 AND 9424");
$pdo->exec("DELETE FROM customers WHERE email LIKE 'a102-like-%'");

// Same customer search and catalog-style LIKE on native MySQL/MariaDB (ESCAPE '!' and literal backslash).
if (getenv('TEST_MYSQL_DSN')) {
    $a102My = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $a102My->exec("CREATE TEMPORARY TABLE customers (id INT AUTO_INCREMENT PRIMARY KEY, store_id VARCHAR(40) NOT NULL, email VARCHAR(191) NOT NULL,
        name VARCHAR(191) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'active', created_at DATETIME NULL, last_login_at DATETIME NULL)");
    $a102My->exec("CREATE TEMPORARY TABLE a102_products (name VARCHAR(191) NOT NULL, sku VARCHAR(64) NOT NULL)");
    foreach (['a102-like-50%off@example.com', 'a102-like-500@example.com', 'a102-like-a_b@example.com', 'a102-like-axb@example.com', 'a102-like-back\\slash@example.com'] as $a102Mail) {
        $a102My->prepare("INSERT INTO customers (store_id, email, name) VALUES (?, ?, 'Like')")->execute([$a102Store, $a102Mail]);
    }
    foreach ([['Zľava 50% Lampa', 'A102_X1'], ['Lampa 500 W', 'A102AX1'], ['Lampa back\\slash', 'A102-BS']] as [$a102Name, $a102Sku]) {
        $a102My->prepare("INSERT INTO a102_products (name, sku) VALUES (?, ?)")->execute([$a102Name, $a102Sku]);
    }
    Db::init($a102My, 'mysql');
    try {
        assert_equals(['a102-like-50%off@example.com'], $a102CustomerEmails('50%'), 'A10.2 LIKE native MySQL: customer search "50%" is literal');
        assert_equals(['a102-like-a_b@example.com'], $a102CustomerEmails('a_b'), 'A10.2 LIKE native MySQL: customer search "_" is literal');
        assert_equals(['a102-like-back\\slash@example.com'], $a102CustomerEmails('back\\slash'), 'A10.2 LIKE native MySQL: customer search keeps a literal backslash');
        assert_equals(5, count($a102CustomerEmails('a102-like')), 'A10.2 LIKE native MySQL: plain customer search matches substrings');
        $a102MyNames = static function (string $search) use ($a102My): array {
            $stmt = $a102My->prepare("SELECT name FROM a102_products WHERE (name LIKE :q ESCAPE '!' OR sku LIKE :q2 ESCAPE '!') ORDER BY name");
            $stmt->execute([':q' => '%' . Db::likeLiteral($search) . '%', ':q2' => '%' . Db::likeLiteral($search) . '%']);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        };
        assert_equals(['Zľava 50% Lampa'], $a102MyNames('50%'), 'A10.2 LIKE native MySQL: catalog pattern "50%" is literal');
        assert_equals(['Zľava 50% Lampa'], $a102MyNames('A102_X'), 'A10.2 LIKE native MySQL: catalog pattern "_" is literal');
        assert_equals(['Lampa back\\slash'], $a102MyNames('back\\slash'), 'A10.2 LIKE native MySQL: catalog pattern keeps a literal backslash');
    } finally {
        Db::init($pdo);
        $a102My->exec("DROP TEMPORARY TABLE IF EXISTS customers");
        $a102My->exec("DROP TEMPORARY TABLE IF EXISTS a102_products");
    }
} else {
    print "  [SKIP] Audit A10.2 native LIKE ESCAPE (set TEST_MYSQL_DSN)\n";
}

// --- Meta catalog CSV: spreadsheet formulas in merchant-controlled text are neutralised. ---
$pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, summary, gtin, mpn) VALUES (9424, ?, 1, ?, 'a102-formula', ?, 'simple', 990, 'eur', 'active', 3, ?, ?, ?)")
    ->execute([$a102Catalog, '=HYPERLINK("https://evil.example","Klik")', '+A102-SKU', '@SUM(1+1)', '-1+1', "\t=cmd"]);
$a102Meta = MarketingFeed::meta('https://shop.example', 'en');
$a102Row = null;
foreach (array_map(fn($line) => str_getcsv($line, ',', '"', '\\'), preg_split('/\r?\n/', trim($a102Meta))) as $a102Cells) {
    if (in_array("'+A102-SKU", $a102Cells, true) || in_array('+A102-SKU', $a102Cells, true)) $a102Row = $a102Cells;
}
assert_true(is_array($a102Row), 'A10.2 Meta CSV: formula product is exported');
foreach ((array)$a102Row as $a102Cell) {
    $a102First = $a102Cell === '' ? '' : $a102Cell[0];
    assert_true(!in_array($a102First, ['=', '+', '@', "\t", "\r"], true) && !($a102First === '-' && !is_numeric($a102Cell)),
        'A10.2 Meta CSV: cell ' . json_encode($a102Cell) . ' cannot start a spreadsheet formula');
}
assert_true(in_array("'=HYPERLINK(\"https://evil.example\",\"Klik\")", (array)$a102Row, true), 'A10.2 Meta CSV: the title text is preserved behind the guard');
assert_true(in_array('9.90 EUR', (array)$a102Row, true) && in_array('in stock', (array)$a102Row, true), 'A10.2 Meta CSV: system fields stay unchanged');
$pdo->exec("DELETE FROM products WHERE id = 9424");
