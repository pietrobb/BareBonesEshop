<?php

// Storefront core audit (2026-10-16): FTS query syntax 500s, 404/410 pages with a second shell, soft 404s,
// unbounded facet filters, integer overflow in paging/prices, nested category links, cookie consent methods,
// wishlist labels/headers/redirects, review limits and no-JS flow, the order-done reflection and hardcoded strings.
// The real storefront entry points run under the PHP built-in server on a migrated SQLite fixture.
echo "\n--- Audit storefront core: search, status pages, filters, overflow, links, consent, wishlist, reviews ---\n";

$sfaRoot = test_fixture_path('storefront-core');
mkdir($sfaRoot . '/sessions', 0700, true);
mkdir($sfaRoot . '/cache', 0700, true);
$sfaApp = dirname(TEST_CLI_DIR);
$sfaDbPath = $sfaRoot . '/shop.sqlite';
$sfaDb = new PDO('sqlite:' . $sfaDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new Migrator($sfaDb, $sfaApp . '/migrations', $sfaRoot . '/migrate.lock'))->apply();
$sfaCategory = static function (string $slug, string $name, ?int $parent, string $path) use ($sfaDb): int {
    $sfaDb->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['default', $slug, $name, $parent, $path, substr_count($path, '/')]);
    return (int)$sfaDb->lastInsertId();
};
$sfaKitchen = $sfaCategory('sfa-kitchen', 'SFA Kitchen', null, 'sfa-kitchen');
$sfaMugs = $sfaCategory('sfa-mugs', 'SFA Mugs', $sfaKitchen, 'sfa-kitchen/sfa-mugs');
$sfaOther = $sfaCategory('sfa-other', 'SFA Other', null, 'sfa-other');
$sfaProduct = static function (int $category, string $sku, string $slug, string $name, int $price, string $status = 'active', string $summary = '') use ($sfaDb): int {
    $sfaDb->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, summary, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, 50, ?)')
        ->execute(['default', $category, $sku, $slug, $name, $summary, $price, $status]);
    return (int)$sfaDb->lastInsertId();
};
$sfaShirt = $sfaProduct($sfaMugs, 'SFA-SHIRT', 'sfa-blue-shirt', 'SFA Blue T-Shirt', 1500, 'active', "O'Neil 3.5 mm 50% off x/y a,b");
$sfaMug = $sfaProduct($sfaMugs, 'SFA-MUG', 'sfa-mug', 'SFA Mug', 900);
$sfaGone = $sfaProduct($sfaMugs, 'SFA-GONE', 'sfa-gone', 'SFA Retired Kettle', 2500, 'archived');
$sfaDraft = $sfaProduct($sfaMugs, 'SFA-DRAFT', 'sfa-draft', 'SFA Draft Teapot', 2500, 'draft');
for ($sfaI = 1; $sfaI <= 30; $sfaI++) $sfaProduct($sfaKitchen, 'SFA-FILL-' . $sfaI, 'sfa-filler-' . $sfaI, 'SFA Filler ' . $sfaI, 100 + $sfaI);
$sfaForeign = $sfaProduct($sfaOther, 'SFA-FOREIGN', 'sfa-foreign', 'SFA Foreign Lamp', 700);
foreach ([[$sfaShirt, 'Color', 'Blue', null], [$sfaMug, 'Color', 'Red', null], [$sfaShirt, 'Volume', '300', 300.0], [$sfaMug, 'Volume', '500', 500.0]] as [$sfaPid, $sfaName, $sfaValue, $sfaNum]) {
    $sfaDb->prepare('INSERT INTO product_specifications (product_id, lang, criteria_name, criteria_value, numeric_val) VALUES (?, ?, ?, ?, ?)')
        ->execute([$sfaPid, 'sk', $sfaName, $sfaValue, $sfaNum]);
}
Import::recountCategories($sfaDb);

$sfaProbe = stream_socket_server('tcp://127.0.0.1:0');
$sfaPort = (int)substr(strrchr((string)stream_socket_get_name($sfaProbe, false), ':'), 1);
fclose($sfaProbe);
file_put_contents($sfaRoot . '/router.php', '<?php
$sfaApp = ' . var_export($sfaApp, true) . ';
$sfaDir = ' . var_export($sfaRoot, true) . ';
require $sfaApp . "/src/autoload.php";
$db = new PDO("sqlite:" . $sfaDir . "/shop.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($db, "sqlite");
Cache::setDir($sfaDir . "/cache");
JobQueue::setDir($sfaDir . "/jobs");
$config = require $sfaApp . "/config.php";
(new ReflectionProperty(Security::class, "cfg"))->setValue(null, ["app_url" => "http://127.0.0.1:' . $sfaPort . '"] + $config);
Security::setRateDbForTesting(new PDO("sqlite:" . $sfaDir . "/rate.sqlite"));
(new ReflectionProperty(InvoiceService::class, "storageDir"))->setValue(null, $sfaDir . "/invoices");
$uri = (string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($uri === "/__seed") {
    session_start();
    $_SESSION["csrf_token"] = "sfa-csrf";
    echo session_id();
    exit;
}
if ($uri === "/__probe") {
    header("Content-Type: application/json");
    StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), "/", "", "default"));
    $out = [];
    $spec = ["Color" => ["Red", ["nested"], "Blue"], "Bogus" => ["x"]];
    for ($i = 0; $i < 30; $i++) $spec["Color" . $i] = array_map("strval", range(1, 50));
    $many = [];
    for ($i = 0; $i < 40; $i++) $many[] = "v" . $i;
    $out["bounded"] = Products::boundSpecFilters([' . $sfaMugs . '], "sk", $spec + ["Volume" => $many], ["Volume" => ["min" => "100", "max" => "NaN"], "Bogus" => ["min" => 1]]);
    // A full-text failure (here: the FTS table is gone inside a rolled-back transaction) degrades to LIKE.
    $db->beginTransaction();
    $db->exec("DROP TABLE products_fts");
    $fallback = with_error_log_silenced(static fn() => Search::query("blue t-shirt", 1, 24));
    $db->rollBack();
    $out["fallback"] = array_column($fallback["products"], "slug");
    $out["filters_from_query"] = Search::filtersFromQuery(["pmin" => "9223372036854775807", "pmax" => "-5"]);
    echo json_encode($out);
    exit;
}
function with_error_log_silenced(callable $callback): mixed {
    $previous = ini_set("error_log", ' . var_export($sfaRoot . '/fallback.log', true) . ');
    try { return $callback(); } finally { ini_set("error_log", (string)$previous); }
}
chdir($sfaApp);
$entry = match ($uri) {
    "/", "/index.php" => "index.php",
    "/product.php", "/wishlist.php", "/page.php", "/account.php" => ltrim($uri, "/"),
    default => str_starts_with($uri, "/api/v1/") ? "api/v1/index.php" : null,
};
// Like router.php: before PHP 8.4 the built-in server sets SCRIPT_NAME to a dotted request path.
if ($entry !== null) { $_SERVER["SCRIPT_NAME"] = $_SERVER["PHP_SELF"] = "/" . $entry; require $sfaApp . "/" . $entry; exit; }
// The .htaccess clean URLs (/product/x, /category/x, /page/x).
$route = UrlAliases::entry(["path" => $uri, "query" => []]);
if ($route !== null) { $_GET = $route[1] + $_GET; require $sfaApp . "/" . $route[0]; exit; }
$_GET["path"] = $uri;
require $sfaApp . "/redirect.php";
');
$sfaServer = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sfaRoot . '/sessions', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $sfaRoot . '/php-error.log',
    '-S', "127.0.0.1:{$sfaPort}", $sfaRoot . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $sfaRoot . '/server.log', 'a'], 2 => ['file', $sfaRoot . '/server.log', 'a']], $sfaPipes, $sfaApp);
$sfaHttp = static function (string $method, string $path, array $post = [], ?string &$sid = null, array $extraHeaders = []) use ($sfaPort): array {
    $headers = "Content-Type: application/x-www-form-urlencoded\r\nHost: 127.0.0.1:{$sfaPort}\r\n" . ($sid ? "Cookie: PHPSESSID={$sid}\r\n" : '') . implode('', array_map(static fn(string $h): string => $h . "\r\n", $extraHeaders));
    $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => http_build_query($post),
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20]]);
    $body = @file_get_contents("http://127.0.0.1:{$sfaPort}{$path}", false, $context);
    $result = ['status' => 0, 'location' => '', 'csp' => '', 'body' => (string)$body, 'headers' => []];
    foreach ($http_response_header ?? [] as $index => $line) {
        if ($index === 0) $result['status'] = (int)(explode(' ', $line)[1] ?? 0);
        if ($index > 0 && str_contains($line, ':')) $result['headers'][strtolower(trim(strstr($line, ':', true)))] = trim(substr(strstr($line, ':'), 1));
        if (stripos($line, 'Location:') === 0) $result['location'] = trim(substr($line, 9));
        if (stripos($line, 'Content-Security-Policy:') === 0) $result['csp'] = trim(substr($line, 24));
        if (preg_match('/^Set-Cookie:\s*PHPSESSID=([^;]+)/i', $line, $cookie)) $sid = $cookie[1];
    }
    return $result;
};
$sfaCount = static fn(string $needle, string $html): int => substr_count($html, $needle);
// The page body without the shell: the language switcher echoes the (URL-encoded) query string by design.
$sfaMain = static fn(string $html): string => preg_match('~<main\b.*</main>~s', $html, $m) ? $m[0] : '';
$sfaNoFatal = static fn(array $r): bool => !str_contains($r['body'], 'Fatal error') && !str_contains($r['body'], 'Uncaught') && !str_contains($r['body'], 'Internal error');
try {
    $sfaSid = '';
    for ($sfaTry = 0; $sfaTry < 50 && $sfaSid === ''; $sfaTry++) {
        usleep(100000);
        $sfaSid = $sfaHttp('GET', '/__seed', [], $sfaSid)['body'];
    }
    assert_true($sfaSid !== '', 'SFA: storefront fixture server is up');
    $sfaHome = $sfaHttp('GET', '/', [], $sfaSid);
    preg_match('~<html lang="([a-z]{2})~', $sfaHome['body'], $sfaLangMatch);
    $sfaLang = $sfaLangMatch[1] ?? 'sk';
    $sfaT = require $sfaApp . '/lang/' . $sfaLang . '.php';
    $sfaE = static fn(string $key): string => htmlspecialchars((string)$sfaT[$key], ENT_QUOTES, 'UTF-8');

    // Footer About/Contact link only to CMS pages that exist and are active (no 404 link on every page).
    assert_true(!str_contains($sfaHome['body'], '/page/about') && !str_contains($sfaHome['body'], '/page/contact'),
        'SFA footer: no About/Contact links while those CMS pages do not exist');
    $sfaDb->exec("INSERT INTO pages (slug, title, body, status, store_id) VALUES ('about', 'About', '<p>About</p>', 'active', 'default'), ('contact', 'Contact', '<p>Contact</p>', 'draft', 'default')");
    $sfaFooter = $sfaHttp('GET', '/', [], $sfaSid)['body'];
    assert_true(str_contains($sfaFooter, '/page/about') && !str_contains($sfaFooter, '/page/contact'),
        'SFA footer: an active About page is linked, a draft Contact page is not');
    $sfaDb->exec("DELETE FROM pages WHERE slug IN ('about', 'contact') AND store_id = 'default'");

    // R7: without JS the cookie banner is a real form, so it can be closed (it covered the order button).
    $sfaBannerPage = $sfaHttp('GET', '/?page=search&q=mug', [], $sfaSid)['body'];
    preg_match('~<form id="cc-banner"[^>]*>(.*?)</form>~s', $sfaBannerPage, $sfaBanner);
    preg_match('~<form id="cc-banner"[^>]*\smethod="post" action="([^"]+)"~', $sfaBannerPage, $sfaBannerAction);
    preg_match('~name="csrf_token" value="([^"]+)"~', $sfaBanner[1] ?? '', $sfaBannerCsrf);
    preg_match('~name="return_to" value="([^"]+)"~', $sfaBanner[1] ?? '', $sfaBannerReturn);
    assert_true(str_ends_with($sfaBannerAction[1] ?? '', '/account.php') && ($sfaBannerCsrf[1] ?? '') !== ''
        && preg_match('~<button type="submit"[^>]*name="method" value="banner_reject_all"~', $sfaBanner[1] ?? '') === 1
        && preg_match('~<button type="submit"[^>]*name="method" value="banner_accept_all"~', $sfaBanner[1] ?? '') === 1,
        'R7 cookie banner: a POST form to account.php with CSRF and real accept/reject submit buttons (were JS-only type=button)');
    $sfaBannerReturnTo = html_entity_decode($sfaBannerReturn[1] ?? '', ENT_QUOTES);
    assert_equals('/?page=search&q=mug', $sfaBannerReturnTo, 'R7 cookie banner: the form returns to the page it covered');
    $sfaConsents = static fn(): array => $sfaDb->query("SELECT method, categories_json FROM cookie_consent ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_NUM) ?: [];
    foreach (['banner_reject_all' => false, 'banner_accept_all' => true] as $sfaBannerMethod => $sfaBannerAll) {
        $sfaBannerPost = $sfaHttp('POST', '/account.php', ['csrf_token' => $sfaBannerCsrf[1] ?? '', 'action' => 'cookie_consent', 'source' => 'banner',
            'return_to' => $sfaBannerReturnTo, 'method' => $sfaBannerMethod], $sfaSid);
        assert_equals([302, '/?page=search&q=mug', $sfaBannerMethod, ['necessary' => true, 'analytics' => $sfaBannerAll, 'marketing' => $sfaBannerAll]],
            [$sfaBannerPost['status'], $sfaBannerPost['location'], $sfaConsents()[0] ?? null, json_decode((string)($sfaConsents()[1] ?? ''), true)],
            "R7 cookie banner: no-JS {$sfaBannerMethod} records the choice and goes back to the page");
    }

    // 1. Every search token is a quoted FTS5 string; only the last one is a prefix.
    foreach (['t-shirt blue', '3.5 mm', "o'neil shirt", 'a,b c', '50% off', 'x/y z', '+x y', 'NEAR(a b)', '"', '* -', 'col:val x'] as $sfaQuery) {
        $sfaSearch = $sfaHttp('GET', '/?page=search&q=' . rawurlencode($sfaQuery), [], $sfaSid);
        assert_true($sfaSearch['status'] === 200 && $sfaNoFatal($sfaSearch), "SFA search: '{$sfaQuery}' renders (HTTP {$sfaSearch['status']}, not a 500)");
        $sfaApiSearch = $sfaHttp('GET', '/api/v1/storefront/search?q=' . rawurlencode($sfaQuery), [], $sfaSid);
        assert_true($sfaApiSearch['status'] === 200 && is_array(json_decode($sfaApiSearch['body'], true)) && !isset(json_decode($sfaApiSearch['body'], true)['error']),
            "SFA api search: '{$sfaQuery}' answers JSON results");
    }
    foreach (['t-shirt blue', '3.5 mm', "o'neil shirt", '50% off', 'x/y'] as $sfaQuery) {
        assert_true(str_contains($sfaHttp('GET', '/?page=search&q=' . rawurlencode($sfaQuery), [], $sfaSid)['body'], 'SFA Blue T-Shirt'), "SFA search: '{$sfaQuery}' finds the product");
    }
    $sfaSearchSource = (string)file_get_contents($sfaApp . '/src/Search.php');
    assert_true(str_contains($sfaSearchSource, "str_replace('\"', '\"\"', \$w)"), 'SFA search: tokens are quoted with doubled inner quotes');
    $sfaProbeResult = json_decode($sfaHttp('GET', '/__probe', [], $sfaSid)['body'], true);
    assert_equals(['sfa-blue-shirt'], $sfaProbeResult['fallback'] ?? null, 'SFA search: an FTS error falls back to a LIKE search instead of a 500');

    // 2. 404 / 410 render once inside the shell (one header/main/footer), with an <h1> and the right status.
    $sfaMissing = $sfaHttp('GET', '/?page=product&slug=sfa-nope', [], $sfaSid);
    assert_equals(404, $sfaMissing['status'], 'SFA 404: unknown product answers 404');
    assert_true($sfaCount('<main', $sfaMissing['body']) === 1 && $sfaCount('<footer', $sfaMissing['body']) === 1 && $sfaCount('<h1', $sfaMissing['body']) === 1,
        'SFA 404: one main/footer and one h1 (no second layout inside the shell)');
    assert_true(str_contains($sfaMissing['body'], '<h1>' . $sfaE('product_not_found') . '</h1>'), 'SFA 404: the status message is the page h1');
    $sfaGonePage = $sfaHttp('GET', '/product/sfa-gone', [], $sfaSid);
    assert_equals(410, $sfaGonePage['status'], 'SFA 410: archived product answers 410');
    assert_true($sfaCount('<main', $sfaGonePage['body']) === 1 && $sfaCount('<footer', $sfaGonePage['body']) === 1 && $sfaCount('<h1', $sfaGonePage['body']) === 1,
        'SFA 410: one main/footer and one h1');
    assert_true(str_contains($sfaGonePage['body'], '<h1>SFA Retired Kettle</h1>') && str_contains($sfaGonePage['body'], $sfaE('product_discontinued'))
        && str_contains($sfaGonePage['body'], '/product/sfa-mug"'), 'SFA 410: h1, product name and related product cards');
    assert_true(!is_file($sfaApp . '/blocks/error/404.html') && !is_file($sfaApp . '/blocks/error/410.html')
        && !str_contains((string)file_get_contents($sfaApp . '/layouts.json'), 'error/'), 'SFA 404: the untranslated error layouts are gone');

    // 3. Soft 404s.
    $sfaNoCategory = $sfaHttp('GET', '/?page=category&slug=sfa-nope', [], $sfaSid);
    assert_true($sfaNoCategory['status'] === 404 && str_contains($sfaNoCategory['body'], '<h1>' . $sfaE('cat_not_found') . '</h1>'), 'SFA soft 404: unknown category answers 404');
    $sfaNoPage = $sfaHttp('GET', '/?page=sfa-nonsense', [], $sfaSid);
    assert_true($sfaNoPage['status'] === 404 && str_contains($sfaNoPage['body'], $sfaE('account.page_not_found')) && !str_contains($sfaNoPage['body'], 'SFA Filler'),
        'SFA soft 404: unknown ?page= answers 404, not the homepage with 200');
    assert_equals(200, $sfaHome['status'], 'SFA soft 404: the homepage itself still answers 200');
    // R7 low: any other unknown address fell through to redirect.php, which answered a bare text/plain "Not found".
    $sfaNoUrl = $sfaHttp('GET', '/sfa-no-such-page', [], $sfaSid);
    assert_true($sfaNoUrl['status'] === 404 && str_starts_with($sfaNoUrl['headers']['content-type'] ?? '', 'text/html')
        && $sfaCount('<main', $sfaNoUrl['body']) === 1 && str_contains($sfaNoUrl['body'], $sfaE('account.page_not_found')),
        'R7 404: an unknown address gets the storefront 404 page in the shell (was text/plain "Not found")');
    $sfaNoAsset = $sfaHttp('GET', '/images/sfa-missing.png', [], $sfaSid);
    assert_true($sfaNoAsset['status'] === 404 && $sfaNoAsset['body'] === 'Not found', 'R7 404: a missing image keeps the bare 404');

    // R7 low: the invoice opened by its token link (no login) was cacheable for 5 minutes (private, max-age=300).
    mkdir($sfaRoot . '/invoices', 0700, true);
    file_put_contents($sfaRoot . '/invoices/sfa-inv.pdf', "%PDF-1.4\n%sfa\n");
    $sfaToken = str_repeat('ab', 32);
    $sfaDb->prepare("INSERT INTO invoices (order_id, number, issued_at, pdf_path, download_token, download_token_expires_at) VALUES (999001, 'SFA-INV-1', '2026-10-20', 'sfa-inv.pdf', ?, ?)")
        ->execute([hash('sha256', $sfaToken), gmdate('Y-m-d H:i:s', time() + 3600)]);
    $sfaInvoice = $sfaHttp('GET', '/account.php?p=invoice&token=' . $sfaToken, [], $sfaSid);
    assert_true($sfaInvoice['status'] === 200 && ($sfaInvoice['headers']['content-type'] ?? '') === 'application/pdf' && str_starts_with($sfaInvoice['body'], '%PDF'),
        'R7 invoice link: the token opens the PDF');
    assert_equals(['no-store', 'noindex, nofollow'], [$sfaInvoice['headers']['cache-control'] ?? '', $sfaInvoice['headers']['x-robots-tag'] ?? ''],
        'R7 invoice link: the PDF is not cached and not indexed (was Cache-Control: private, max-age=300)');
    // R8 PII: the e-mailed link keeps the token in the fragment; the page posts it (no CSRF session needed), sends no Referer.
    $sfaLanding = $sfaHttp('GET', '/account.php?p=invoice');
    assert_true($sfaLanding['status'] === 200 && str_contains($sfaLanding['body'], 'id="invoice-link"') && str_contains($sfaLanding['body'], 'location.hash')
        && ($sfaLanding['headers']['referrer-policy'] ?? '') === 'no-referrer' && preg_match('~<script nonce="[^"]+">~', $sfaLanding['body']) === 1,
        'R8 PII invoice link: the landing page reads the token from the fragment and posts it, with no Referer (' . $sfaLanding['status'] . ')');
    $sfaPosted = $sfaHttp('POST', '/account.php?p=invoice', ['token' => $sfaToken]);
    assert_true($sfaPosted['status'] === 200 && str_starts_with($sfaPosted['body'], '%PDF') && ($sfaPosted['headers']['referrer-policy'] ?? '') === 'no-referrer',
        'R8 PII invoice link: the posted token opens the PDF (' . $sfaPosted['status'] . ')');
    assert_equals('no-referrer', $sfaInvoice['headers']['referrer-policy'] ?? '', 'R8 PII: a token in the query (old links) is answered without a Referer policy leak');
    $sfaDb->prepare("UPDATE invoices SET download_token_expires_at = ? WHERE number = 'SFA-INV-1'")->execute([gmdate('Y-m-d H:i:s', time() - 1)]);
    $sfaExpired = $sfaHttp('POST', '/account.php?p=invoice', ['token' => $sfaToken]);
    assert_true($sfaExpired['status'] === 404 && !str_starts_with($sfaExpired['body'], '%PDF') && str_contains($sfaExpired['body'], 'role="alert"'),
        'R8 PII invoice link: an expired link no longer opens the PDF (' . $sfaExpired['status'] . ')');
    $sfaRawStored = $sfaHttp('POST', '/account.php?p=invoice', ['token' => hash('sha256', $sfaToken)]);
    assert_true($sfaRawStored['status'] === 404, 'R8 PII invoice link: the stored hash itself is not a working token');
    foreach (['/account.php?p=reset&token=' . str_repeat('c', 64), '/account.php?p=verify_email&token=' . str_repeat('c', 64), '/?cart_recover=' . str_repeat('c', 64)] as $sfaBearer) {
        assert_equals('no-referrer', $sfaHttp('GET', $sfaBearer)['headers']['referrer-policy'] ?? '', "R8 PII: a page opened by a bearer link sends no Referer ({$sfaBearer})");
    }
    assert_equals('strict-origin-when-cross-origin', $sfaHome['headers']['referrer-policy'] ?? '', 'R8 PII: other pages keep strict-origin-when-cross-origin');

    // 4. Facet filters are bounded and limited to the category's own criteria and descendants.
    $sfaBounded = $sfaProbeResult['bounded'] ?? [[], []];
    assert_equals(['Color', 'Volume'], array_keys($sfaBounded[0]), 'SFA filters: spec names outside the category facets are dropped');
    assert_equals(['Red', 'Blue'], $sfaBounded[0]['Color'] ?? null, 'SFA filters: non-scalar values are dropped');
    assert_equals(Products::MAX_FILTER_VALUES, count($sfaBounded[0]['Volume'] ?? []), 'SFA filters: values per criterion are capped');
    assert_equals(['Volume' => ['min' => 100]], $sfaBounded[1], 'SFA filters: range edges must be finite numbers on known criteria');
    $sfaFlood = '/?page=category&slug=sfa-kitchen';
    for ($sfaI = 0; $sfaI < 25; $sfaI++) for ($sfaJ = 0; $sfaJ < 30; $sfaJ++) $sfaFlood .= '&spec[Bogus' . $sfaI . '][]=v' . $sfaJ;
    $sfaFlood .= '&spec[Color][]=Red&spec[Color][x][]=y&rmin[Volume][a]=1&rmax[Volume]=600&subcat[]=' . $sfaOther . '&subcat[][]=1&pmin[]=1';
    $sfaStarted = microtime(true);
    $sfaFlooded = $sfaHttp('GET', $sfaFlood, [], $sfaSid);
    assert_true($sfaFlooded['status'] === 200 && $sfaNoFatal($sfaFlooded) && microtime(true) - $sfaStarted < 10, 'SFA filters: a flood of criteria/values/arrays renders quickly');
    assert_true(str_contains($sfaFlooded['body'], 'SFA Mug') && !str_contains($sfaFlooded['body'], 'SFA Blue T-Shirt') && !str_contains($sfaFlooded['body'], 'SFA Foreign Lamp'),
        'SFA filters: the real Color filter applies; a foreign subcat[] neither widens nor empties the category');
    assert_true(!str_contains($sfaMain($sfaFlooded['body']), 'Bogus'), 'SFA filters: unknown criteria are not echoed into pagination/filter links');
    $sfaSub = $sfaHttp('GET', '/?page=category&slug=sfa-kitchen&subcat[]=' . $sfaMugs, [], $sfaSid);
    assert_true(str_contains($sfaSub['body'], 'SFA Mug') && !str_contains($sfaSub['body'], 'SFA Filler 1<'), 'SFA filters: a real descendant subcat[] still narrows');

    // 5. Integer overflow and nested JSON filters.
    $sfaHuge = '9223372036854775807';
    foreach (["/?page=search&q=sfa&pg={$sfaHuge}", "/?page=search&q=sfa&pmin={$sfaHuge}&pmax={$sfaHuge}", "/?page=category&slug=sfa-kitchen&pg={$sfaHuge}",
        "/?page=category&slug=sfa-kitchen&pmin={$sfaHuge}&pmax={$sfaHuge}", "/?page=category&slug=sfa-kitchen&pmin=99999999999999999999"] as $sfaPath) {
        $sfaResult = $sfaHttp('GET', $sfaPath, [], $sfaSid);
        // A category page past the last one is a real 404 (round 4 soft-404 fix); it still renders without a fatal.
        $sfaExpected = str_contains($sfaPath, 'page=category') && str_contains($sfaPath, '&pg=') ? 404 : 200;
        assert_true($sfaResult['status'] === $sfaExpected && $sfaNoFatal($sfaResult), "SFA overflow: {$sfaPath} renders (HTTP {$sfaResult['status']})");
    }
    foreach (["/api/v1/storefront/products?cat=sfa-kitchen&page={$sfaHuge}", "/api/v1/storefront/search?q=sfa&page={$sfaHuge}",
        "/api/v1/storefront/parametric_search?category_id={$sfaKitchen}&page={$sfaHuge}", "/api/v1/storefront/products?cat=sfa-kitchen&price_min={$sfaHuge}"] as $sfaPath) {
        $sfaResult = $sfaHttp('GET', $sfaPath, [], $sfaSid);
        assert_true($sfaResult['status'] === 200 && !isset(json_decode($sfaResult['body'], true)['error']), "SFA overflow: {$sfaPath} answers JSON (HTTP {$sfaResult['status']})");
    }
    assert_equals(Search::MAX_PRICE_FILTER * 100, $sfaProbeResult['filters_from_query']['opts']['price_min'] ?? null, 'SFA overflow: search pmin is clamped before the cents conversion');
    assert_equals(0, $sfaProbeResult['filters_from_query']['opts']['price_max'] ?? null, 'SFA overflow: a negative pmax is clamped to 0');
    $sfaNested = $sfaHttp('GET', '/api/v1/storefront/products?cat=sfa-kitchen&filters=' . rawurlencode('{"color":[["red"]]}'), [], $sfaSid);
    assert_true($sfaNested['status'] === 400 && (json_decode($sfaNested['body'], true)['error'] ?? '') === 'Invalid filters', 'SFA api products: nested filter arrays are a 400, not a TypeError 500');
    $sfaFlat = $sfaHttp('GET', '/api/v1/storefront/products?cat=sfa-kitchen&filters=' . rawurlencode('{"color":["red"],"size":"m"}'), [], $sfaSid);
    assert_equals(200, $sfaFlat['status'], 'SFA api products: flat filters still work');

    // 6. Category links use the slug the router resolves (not the urlencoded nested path).
    $sfaShirtPage = $sfaHttp('GET', '/product/sfa-blue-shirt', [], $sfaSid);
    assert_true(str_contains($sfaShirtPage['body'], '/category/sfa-mugs"') && !str_contains($sfaShirtPage['body'], 'sfa-kitchen%2Fsfa-mugs'),
        'SFA links: product breadcrumb links the nested category by slug');
    assert_true(preg_match('~"item":"[^"]*\\\\?/category\\\\?/sfa-mugs"~', $sfaShirtPage['body']) === 1, 'SFA links: breadcrumb JSON-LD names the slug URL');
    $sfaCategoryBySlug = $sfaHttp('GET', '/category/sfa-mugs', [], $sfaSid);
    assert_true($sfaCategoryBySlug['status'] === 200 && str_contains($sfaCategoryBySlug['body'], 'SFA Mug'), 'SFA links: the linked category URL resolves');
    $sfaApiCategory = json_decode($sfaHttp('GET', '/api/v1/storefront/products?cat=sfa-mugs', [], $sfaSid)['body'], true);
    assert_true(str_ends_with((string)($sfaApiCategory['category']['url'] ?? ''), '/category/sfa-mugs'), 'SFA links: the API category URL uses the slug too');

    // 7. Cookie consent methods reach the consent log under GdprService's names.
    foreach (['banner_accept_all' => 'banner_accept_all', 'banner_reject_all' => 'banner_reject_all', 'accept_all' => 'banner_accept_all', 'bogus' => 'preferences'] as $sfaSent => $sfaStored) {
        $sfaConsent = $sfaHttp('POST', '/api/v1/storefront/cookie_consent', ['csrf_token' => 'sfa-csrf', 'method' => $sfaSent, 'analytics' => '1'], $sfaSid);
        $sfaRow = $sfaDb->query('SELECT method FROM cookie_consent ORDER BY id DESC LIMIT 1')->fetchColumn();
        assert_true($sfaConsent['status'] === 200 && $sfaRow === $sfaStored, "SFA consent: method {$sfaSent} is stored as {$sfaStored} (got " . var_export($sfaRow, true) . ')');
    }
    $sfaJs = (string)file_get_contents($sfaApp . '/themes/default/assets/storefront.js');
    $sfaBannerHtml = (string)file_get_contents($sfaApp . '/themes/default/blocks/storefront/cookie-banner.html');
    assert_true(str_contains($sfaBannerHtml, 'value="banner_accept_all"') && str_contains($sfaBannerHtml, 'value="banner_reject_all"')
        && str_contains($sfaJs, 'event.submitter.value') && str_contains($sfaJs, "'banner_accept_all'"), 'SFA consent: the banner sends the logged method names');

    // 8. Wishlist: translated labels, security headers, safe return target, active products only, aria-pressed.
    $sfaWishlist = $sfaHttp('GET', '/wishlist.php', [], $sfaSid);
    assert_true($sfaWishlist['status'] === 200 && str_contains($sfaWishlist['body'], '<h1>' . $sfaE('wishlist.title') . '</h1>')
        && str_contains($sfaWishlist['body'], $sfaE('wishlist.empty')), 'SFA wishlist: the page has a translated h1 and empty state');
    assert_true($sfaWishlist['csp'] !== '', 'SFA wishlist: Security::headers() sends the CSP');
    foreach (['wishlist.title', 'wishlist.remove', 'wishlist.empty', 'wishlist.in', 'wishlist.add'] as $sfaKey) {
        foreach (glob($sfaApp . '/lang/*.php') as $sfaLangFile) assert_true(trim((string)((require $sfaLangFile)[$sfaKey] ?? '')) !== '', "SFA wishlist: {$sfaKey} in " . basename($sfaLangFile));
    }
    assert_true(str_contains($sfaShirtPage['body'], 'aria-pressed="false"') && str_contains($sfaShirtPage['body'], $sfaE('wishlist.add')), 'SFA wishlist: aria-pressed="false" and a named button');
    preg_match('~name="return_to" value="([^"]+)"~', $sfaShirtPage['body'], $sfaReturn);
    $sfaReturnUrl = html_entity_decode($sfaReturn[1] ?? '');
    $sfaToggle = $sfaHttp('POST', '/wishlist.php', ['csrf_token' => 'sfa-csrf', 'op' => 'toggle', 'product_id' => $sfaShirt, 'return_to' => $sfaReturnUrl], $sfaSid);
    assert_true($sfaToggle['status'] === 302 && $sfaToggle['location'] === $sfaReturnUrl && $sfaReturnUrl !== '', 'SFA wishlist: the rendered product URL is accepted as return target (' . $sfaToggle['location'] . ')');
    assert_true(str_contains($sfaHttp('GET', '/product/sfa-blue-shirt', [], $sfaSid)['body'], 'aria-pressed="true"'), 'SFA wishlist: aria-pressed="true" once saved');
    foreach (['https://evil.example/x', '//evil.example/x', '/\\evil.example', "/product/x\r\nSet-Cookie: a=b"] as $sfaEvil) {
        $sfaToggle = $sfaHttp('POST', '/wishlist.php', ['csrf_token' => 'sfa-csrf', 'op' => 'toggle', 'product_id' => $sfaMug, 'return_to' => $sfaEvil], $sfaSid);
        assert_true(str_ends_with($sfaToggle['location'], '/product/sfa-mug'), 'SFA wishlist: unsafe return_to ' . json_encode($sfaEvil) . ' falls back to the product URL');
    }
    $sfaWishCount = static fn(int $pid): int => (int)$sfaDb->query('SELECT COUNT(*) FROM wishlist_items WHERE product_id = ' . $pid)->fetchColumn();
    foreach ([$sfaGone, $sfaDraft, 999999] as $sfaPid) {
        $sfaHttp('POST', '/wishlist.php', ['csrf_token' => 'sfa-csrf', 'op' => 'toggle', 'product_id' => $sfaPid], $sfaSid);
        assert_equals(0, $sfaWishCount($sfaPid), "SFA wishlist: no-JS toggle does not save inactive/missing product {$sfaPid}");
        $sfaApiToggle = json_decode($sfaHttp('POST', '/api/v1/storefront/wishlist_toggle', ['csrf_token' => 'sfa-csrf', 'product_id' => $sfaPid], $sfaSid)['body'], true);
        assert_true(($sfaApiToggle['error'] ?? '') === 'invalid_product' && $sfaWishCount($sfaPid) === 0, "SFA wishlist: API toggle refuses inactive/missing product {$sfaPid}");
    }

    // 9. Reviews: length limits, active products only, duplicate guard, no-JS redirect with a flash.
    $sfaReview = static fn(array $fields) => $sfaHttp('POST', '/api/v1/storefront/review_submit', $fields + ['csrf_token' => 'sfa-csrf', 'rating' => '5', 'body' => 'Great mug indeed'], $sfaSid);
    assert_equals('author_too_long', json_decode($sfaReview(['product_id' => $sfaMug, 'author_name' => str_repeat('a', Reviews::MAX_AUTHOR + 1)])['body'], true)['error'] ?? null, 'SFA reviews: author_name is length-limited server-side');
    assert_equals('body_too_long', json_decode($sfaReview(['product_id' => $sfaMug, 'author_name' => 'Ann', 'body' => str_repeat('b', 2001)])['body'], true)['error'] ?? null, 'SFA reviews: body is length-limited server-side');
    foreach ([$sfaGone, $sfaDraft] as $sfaPid) {
        assert_equals('invalid_product', json_decode($sfaReview(['product_id' => $sfaPid, 'author_name' => 'Ann'])['body'], true)['error'] ?? null, "SFA reviews: product {$sfaPid} that is not active cannot be reviewed");
    }
    $sfaFirst = $sfaReview(['product_id' => $sfaMug, 'author_name' => 'Ann', 'return_to' => '/product/sfa-mug']);
    assert_true($sfaFirst['status'] === 303 && $sfaFirst['location'] === '/product/sfa-mug#reviews', 'SFA reviews: a no-JS form post is redirected back to the product (' . $sfaFirst['status'] . ' ' . $sfaFirst['location'] . ')');
    $sfaFlashPage = $sfaHttp('GET', '/product/sfa-mug', [], $sfaSid)['body'];
    assert_true(str_contains($sfaFlashPage, 'role="status" >' . $sfaE('reviews.success') . '</p>'), 'SFA reviews: the product page shows the success flash once');
    assert_true(!str_contains($sfaHttp('GET', '/product/sfa-mug', [], $sfaSid)['body'], $sfaE('reviews.success') . '</p>'), 'SFA reviews: the flash is shown only once');
    $sfaDuplicate = $sfaReview(['product_id' => $sfaMug, 'author_name' => 'Ann', 'return_to' => 'https://evil.example/']);
    assert_true($sfaDuplicate['status'] === 429 && (json_decode($sfaDuplicate['body'], true)['error'] ?? '') === 'duplicate_review' && $sfaDuplicate['location'] === '',
        'SFA reviews: a second review of the same product within the window is refused; a foreign return_to is not followed');
    assert_equals(1, (int)$sfaDb->query('SELECT COUNT(*) FROM product_reviews WHERE product_id = ' . $sfaMug)->fetchColumn(), 'SFA reviews: one stored review');
    $sfaJson = $sfaHttp('POST', '/api/v1/storefront/review_submit', [], $sfaSid, ['X-CSRF-Token: sfa-csrf']);
    assert_equals('', $sfaJson['location'], 'SFA reviews: requests without return_to keep the JSON answer');

    // 10. Order-done only shows a verified order number.
    $sfaDone = $sfaHttp('GET', '/?page=order-done&order=' . rawurlencode('SFA-FAKE-<b>12345</b>'), [], $sfaSid);
    assert_true($sfaDone['status'] === 200 && !str_contains($sfaMain($sfaDone['body']), 'SFA-FAKE') && str_contains($sfaDone['body'], $sfaE('order_done_unverified')),
        'SFA order-done: an unverified ?order= is not reflected; a neutral message is shown');

    // 11. Hardcoded strings are catalog keys.
    $sfaCheckoutSource = (string)file_get_contents($sfaApp . '/src/Entrypoints/Storefront/CheckoutPages.php');
    foreach (["'Domov'", "'Chyba'", 'Príliš veľa', 'Chyba pri spracovaní', "'CSRF token mismatch.'"] as $sfaLiteral) {
        assert_true(!str_contains($sfaCheckoutSource, $sfaLiteral), "SFA i18n: CheckoutPages has no hardcoded {$sfaLiteral}");
    }
    $sfaPrivacy = $sfaHttp('GET', '/?page=privacy', [], $sfaSid);
    assert_true(preg_match('~<title>' . preg_quote($sfaE('privacy_policy'), '~') . '~', $sfaPrivacy['body']) === 1, 'SFA i18n: the legal page title is translated');
    $sfaPaged = $sfaHttp('GET', '/?page=search&q=sfa', [], $sfaSid)['body'];
    assert_true(str_contains($sfaPaged, 'aria-label="' . $sfaE('breadcrumb_nav') . '"') && str_contains($sfaPaged, 'aria-label="' . $sfaE('pagination_nav') . '"'),
        'SFA i18n: breadcrumb and pagination landmarks carry translated names');
    $sfaCart = $sfaHttp('GET', '/?page=cart', [], $sfaSid)['body'];
    assert_true(str_contains($sfaCart, 'aria-label="' . $sfaE('breadcrumb_nav') . '"'), 'SFA i18n: cart breadcrumb landmark is named');
    assert_true(str_contains($sfaShirtPage['body'], $sfaE('sku_label') . ': SFA-SHIRT') && !str_contains($sfaShirtPage['body'], 'aria-label="Breadcrumb"'), 'SFA i18n: SKU label and product breadcrumb are translated');
    $sfaErrors = is_file($sfaRoot . '/php-error.log') ? (string)file_get_contents($sfaRoot . '/php-error.log') : '';
    assert_true(!str_contains($sfaErrors, 'PHP Fatal') && !str_contains($sfaErrors, 'PHP Warning') && !str_contains($sfaErrors, 'TypeError'), 'SFA: no PHP fatal/warning/TypeError was logged (' . substr($sfaErrors, 0, 400) . ')');
} finally {
    proc_terminate($sfaServer);
    proc_close($sfaServer);
    $sfaDb = null;
    $sfaTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sfaRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($sfaTree as $sfaPath) $sfaPath->isDir() ? @rmdir($sfaPath->getPathname()) : @unlink($sfaPath->getPathname());
    @rmdir($sfaRoot);
}
