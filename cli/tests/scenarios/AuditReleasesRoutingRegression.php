<?php
/**
 * Audit 2026-10-12 / A09.4 / low findings: `.htaccess` exposed `releases/` (release PHP, manifest.json,
 * stores.json copies), its Level-0 cache rewrites could never match (the PHP cache lives under a store
 * namespace and is skipped for logged-in customers), and store URL prefixes were hardcoded for /de and
 * /en only although the sitemap and hreflang advertise every configured prefix (/cs, /pl, /hu ...).
 */

echo "\n--- Audit A09.4: releases HTTP deny, cache rewrites, store URL prefixes ---\n";

$a094Root = dirname(TEST_CLI_DIR);
$a094Htaccess = str_replace("\r\n", "\n", (string)file_get_contents($a094Root . '/.htaccess'));

// Every unconditional deny rule ("RewriteRule <pattern> - [F...]" without RewriteCond) evaluated like
// Apache does for a per-directory request path (no leading slash; NC = case-insensitive).
$a094Rules = [];
$a094Conditional = false;
foreach (explode("\n", $a094Htaccess) as $a094Line) {
    $a094Line = trim($a094Line);
    if (str_starts_with($a094Line, 'RewriteCond ')) {
        $a094Conditional = true;
    } elseif (preg_match('/^RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]*)\]$/', $a094Line, $a094M)) {
        if (!$a094Conditional && $a094M[2] === '-' && preg_match('/\bF\b/', $a094M[3])) {
            $a094Rules[] = [$a094M[1], stripos($a094M[3], 'NC') !== false ? 'i' : ''];
        }
        $a094Conditional = false;
    }
}
$a094Denied = static function (string $path) use ($a094Rules): bool {
    foreach ($a094Rules as [$pattern, $modifiers]) {
        if (preg_match('#' . str_replace('#', '\#', $pattern) . '#' . $modifiers, $path) === 1) return true;
    }
    return false;
};
foreach ([
    'releases/update-1.9.0/manifest.json',
    'releases/update-1.9.0/payload/src/Db.php',
    'releases/update-1.9.0/payload/cli/test.php',
    'releases/update-1.9.0/payload/stores.json',
    'releases/update-1.9.0/payload/admin.php',
    'releases/',
    'RELEASES/update-1.9.0/manifest.json',
    'tests/parity/selftest.mjs',
    'tests/e2e/package.json',
    // CR-A: local tooling output in a developer/CI checkout (dependencies, Playwright traces with cookies).
    'node_modules/playwright/package.json',
    'test-results/checkout-chromium/trace.zip',
    'playwright-report/index.html',
    'parity-report/index.html',
    // CR-A: PHPStan configuration and baseline (the baseline maps known findings to source lines).
    'phpstan.neon.dist',
    'phpstan-baseline.neon',
] as $a094Path) {
    assert_true($a094Denied($a094Path), "A09.4 .htaccess: /{$a094Path} is denied over HTTP");
}
$a094Nginx = (string)file_get_contents($a094Root . '/deploy/nginx.conf');
foreach (['node_modules', 'test-results', 'playwright-report', 'parity-report'] as $a094Tool) {
    assert_true(preg_match('#location ~\* \^/\(\?:[^)]*\b' . preg_quote($a094Tool, '#') . '\b[^)]*\)\(\?:/\|\$\) \{ return 403; \}#', $a094Nginx) === 1, "CR-A nginx.conf: /{$a094Tool}/ is denied");
}
foreach (['index.php', 'product.php', 'api/v1/index.php', 'assets/logo.png', 'themes/default/assets/storefront.css', 'vendor/bbf/bbf.js', 'releasesnotes.html'] as $a094Path) {
    assert_true(!$a094Denied($a094Path), "A09.4 .htaccess: /{$a094Path} stays public");
}

// Level-0 cache rewrites pointed at cache/<locale>/..., but Cache::path() writes under
// cache/stores/<store>/<channel>/<locale>/... and skips logged-in customers, so they could only ever
// serve nothing — or, if "fixed" naively, guest/other-store prices to B2B customers.
assert_true(str_contains(Cache::path(Cache::localePath('sk', 'categories.json')), '/' . StoreContext::current()->cacheNamespace() . '/sk/'), 'A09.4 cache: PHP cache entries live under the store namespace');
assert_equals(0, preg_match_all('/^RewriteRule\s+\S+\s+cache\//m', $a094Htaccess), 'A09.4 .htaccess: no dead rewrite serves JSON from cache/ bypassing PHP');
assert_true(preg_match('/^RewriteRule \^\(\?:[^\n]*\|cache\|[^\n]*\s-\s+\[F,L,NC\]$/m', $a094Htaccess) === 1, 'A09.4 .htaccess: direct cache requests stay denied');
assert_equals(0, preg_match('/^RewriteRule \^(?:de|en|cs|pl|hu)\//m', $a094Htaccess), 'A09.4 .htaccess: no hardcoded per-store prefix rules (the fallback serves every configured prefix)');

// Every configured store prefix is served through the real .htaccess fallback (redirect.php?path=/...).
$a094Stores = json_decode((string)file_get_contents($a094Root . '/stores.json'), true)['stores'] ?? [];
$pdo->exec("INSERT INTO products (id, category_id, name, slug, sku, product_type, price, currency, status, stock) VALUES (9402, 1, 'Prefix Route', 'prefix-route', 'A094-PR', 'simple', 1200, 'eur', 'active', 5)");
$a094Router = test_fixture_path('prefix-router') . '.php';
file_put_contents($a094Router, '<?php
require ' . var_export($a094Root . '/src/autoload.php', true) . ';
Db::init(new PDO(' . var_export('sqlite:' . $testDbPath, true) . ', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
UrlAliases::setRules([]);
$_GET["path"] = (string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
chdir(' . var_export($a094Root, true) . ');
require ' . var_export($a094Root . '/redirect.php', true) . ';
');
$a094Probe = stream_socket_server('tcp://127.0.0.1:0');
$a094Port = (int)substr(strrchr((string)stream_socket_get_name($a094Probe, false), ':'), 1);
fclose($a094Probe);
$a094Log = test_fixture_path('prefix-router') . '.log';
$a094Server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$a094Port}", $a094Router],
    [0 => ['pipe', 'r'], 1 => ['file', $a094Log, 'a'], 2 => ['file', $a094Log, 'a']], $a094Pipes, $a094Root);
$a094Get = static function (string $path) use ($a094Port): array {
    $context = stream_context_create(['http' => ['method' => 'GET', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 15]]);
    $body = @file_get_contents("http://127.0.0.1:{$a094Port}{$path}", false, $context);
    return ['status' => (int)(explode(' ', (string)(HttpResponseHeaders::last($http_response_header ?? null)[0] ?? ''))[1] ?? 0), 'body' => (string)$body];
};
try {
    for ($i = 0; $i < 50 && $a094Get('/nic-take')['status'] === 0; $i++) usleep(100000);
    $a094Prefixed = 0;
    foreach ($a094Stores as $a094StoreId => $a094Store) {
        $a094Prefix = rtrim((string)($a094Store['url_prefix'] ?? ''), '/');
        if ($a094Prefix === '' || !in_array('storefront', (array)($a094Store['channels'] ?? ['storefront']), true)) continue;
        $a094Prefixed++;
        $a094Lang = '<html lang="' . $a094Store['default_locale'] . '"';
        $product = $a094Get($a094Prefix . '/product/prefix-route');
        assert_equals(200, $product['status'], "A09.4 prefix {$a094Prefix}: product page is served");
        assert_true(str_contains($product['body'], $a094Lang) && str_contains($product['body'], 'Prefix Route'), "A09.4 prefix {$a094Prefix}: product page renders in store {$a094StoreId}");
        $home = $a094Get($a094Prefix . '/');
        assert_true($home['status'] === 200 && str_contains($home['body'], $a094Lang), "A09.4 prefix {$a094Prefix}/: storefront home of store {$a094StoreId}");
        assert_equals(200, $a094Get($a094Prefix)['status'], "A09.4 prefix {$a094Prefix} without trailing slash is served");
    }
    assert_true($a094Prefixed >= 5, 'A09.4: every configured store prefix was exercised');
    assert_equals(404, $a094Get('/xx/product/prefix-route')['status'], 'A09.4: an unconfigured prefix is still a 404');
    assert_equals(404, $a094Get('/cs/nic-take')['status'], 'A09.4: unknown path under a store prefix is still a 404');
} finally {
    proc_terminate($a094Server);
    proc_close($a094Server);
    @unlink($a094Router);
    @unlink($a094Log);
    $pdo->exec("DELETE FROM products WHERE id = 9402");
}
