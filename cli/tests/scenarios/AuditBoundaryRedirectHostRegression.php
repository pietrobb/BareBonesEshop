<?php
/**
 * Audit 2026-10-12 / A10.1 / low findings:
 *  - an admin redirect (or menu/banner URL) "/\evil.com" passed the "local path" check and redirect.php sent
 *    it as Location, which browsers resolve to https://evil.com;
 *  - sitemap.php, robots.php and the storefront JSON-LD built absolute URLs from the request Host header;
 *  - the feed SSRF filter let the CGNAT range 100.64.0.0/10 (and other non-global IPv4 ranges) through.
 */

echo "\n--- Audit A10.1: redirect backslash, Host-independent canonical URLs, feed SSRF CGNAT ---\n";

$a101Root = dirname(TEST_CLI_DIR);
$a101Throws = static function (callable $fn): bool {
    try { $fn(); } catch (InvalidArgumentException) { return true; }
    return false;
};

// --- Admin content URLs: backslash-smuggled hosts are rejected on save, plain local paths still work. ---
foreach (['/\\evil.com', '/\\/evil.com', '/foo\\..\\evil', "/\\\tevil.com"] as $a101Index => $a101Bad) {
    $a101Json = json_encode($a101Bad);
    assert_true($a101Throws(fn() => ContentService::saveRedirect(['source_path' => '/a101-bad-' . $a101Index, 'target_url' => $a101Bad, 'http_status' => 301, 'active' => 1])),
        "A10.1 redirect: target {$a101Json} is rejected on save");
    assert_true($a101Throws(fn() => ContentService::saveMenu(['label' => 'A10.1', 'url' => $a101Bad, 'active' => 1])),
        "A10.1 menu: URL {$a101Json} is rejected on save");
    assert_true($a101Throws(fn() => ContentService::saveBanner(['title' => 'A10.1', 'link_url' => $a101Bad, 'active' => 1])),
        "A10.1 banner: link {$a101Json} is rejected on save");
}
assert_true($a101Throws(fn() => ContentService::saveRedirect(['source_path' => '/\\a101-src', 'target_url' => '/ok', 'active' => 1])),
    'A10.1 redirect: a backslash source path is rejected');
$a101GoodId = ContentService::saveRedirect(['source_path' => '/a101-old', 'target_url' => '/a101-new?x=1', 'http_status' => 301, 'active' => 1]);
assert_true($a101GoodId > 0, 'A10.1 redirect: a plain local target is still accepted');
$a101BannerId = ContentService::saveBanner(['title' => 'A10.1 ok', 'link_url' => 'https://partner.example/promo', 'active' => 0]);
assert_true($a101BannerId > 0, 'A10.1 banner: an absolute HTTPS link is still accepted');
// A row stored before the fix (or written directly to the database) must not be followed.
$pdo->prepare("INSERT INTO content_redirects (store_id, source_path, target_url, http_status, active) VALUES ('default', '/a101-legacy', ?, 302, 1)")
    ->execute(['/\\evil.com']);
$pdo->prepare("INSERT INTO content_redirects (store_id, source_path, target_url, http_status, active) VALUES ('default', '/a101-legacy-abs', ?, 301, 1)")
    ->execute(['//evil.com/x']);

// --- Feed SSRF: CGNAT and other non-global IPv4 ranges are blocked, neighbouring public space is not. ---
$a101Feed = new ReflectionMethod(FeedImport::class, 'publicFeedEndpoint');
foreach (['100.64.0.1', '100.100.100.200', '100.127.255.254', '192.0.0.8', '198.18.0.1', '198.19.255.254', '[::ffff:100.64.0.1]', '[64:ff9b::6440:1]'] as $a101Ip) {
    assert_true($a101Feed->invoke(null, "http://{$a101Ip}/feed.xml") === null, "A10.1 feed SSRF: blocks non-global {$a101Ip}");
}
foreach (['100.63.255.254', '100.128.0.1', '198.20.0.1', '93.184.216.34'] as $a101Ip) {
    assert_equals($a101Ip, (string)($a101Feed->invoke(null, "https://{$a101Ip}/feed.xml")['ip'] ?? ''), "A10.1 feed SSRF: public {$a101Ip} stays allowed");
}

// --- Real entrypoints over HTTP with a hostile Host header. ---
$a101Router = test_fixture_path('a101-router') . '.php';
file_put_contents($a101Router, '<?php
require ' . var_export($a101Root . '/src/autoload.php', true) . ';
Db::init(new PDO(' . var_export('sqlite:' . $testDbPath, true) . ', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
UrlAliases::setRules([]);
(new ReflectionProperty(Security::class, "cfg"))->setValue(null, ["app_url" => (string)($_SERVER["HTTP_X_TEST_APP_URL"] ?? "")] + (require ' . var_export($a101Root . '/config.php', true) . '));
$path = (string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
chdir(' . var_export($a101Root, true) . ');
if ($path === "/sitemap.xml") require "sitemap.php";
elseif ($path === "/robots.txt") require "robots.php";
elseif ($path === "/") require "index.php";
else { $_GET["path"] = $path; require "redirect.php"; }
');
$a101Probe = stream_socket_server('tcp://127.0.0.1:0');
$a101Port = (int)substr(strrchr((string)stream_socket_get_name($a101Probe, false), ':'), 1);
fclose($a101Probe);
$a101Log = test_fixture_path('a101-router') . '.log';
$a101Server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$a101Port}", $a101Router],
    [0 => ['pipe', 'r'], 1 => ['file', $a101Log, 'a'], 2 => ['file', $a101Log, 'a']], $a101Pipes, $a101Root);
$a101Get = static function (string $path, string $appUrl = '') use ($a101Port): array {
    $headers = "Host: attacker.example\r\n" . ($appUrl !== '' ? "X-Test-App-Url: {$appUrl}\r\n" : '');
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => $headers, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20]]);
    $body = @file_get_contents("http://127.0.0.1:{$a101Port}{$path}", false, $context);
    $responseHeaders = $http_response_header ?? [];
    $location = null;
    foreach ($responseHeaders as $line) {
        if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
    }
    return ['status' => (int)(explode(' ', (string)($responseHeaders[0] ?? ''))[1] ?? 0), 'location' => $location, 'body' => (string)$body];
};
try {
    for ($i = 0; $i < 50 && $a101Get('/a101-nothing')['status'] === 0; $i++) usleep(100000);

    $a101Redirect = $a101Get('/a101-old');
    assert_true($a101Redirect['status'] === 301 && $a101Redirect['location'] === '/a101-new?x=1', 'A10.1 redirect.php: a valid local redirect is still followed (' . json_encode($a101Redirect['location']) . ')');
    foreach (['/a101-legacy', '/a101-legacy-abs'] as $a101Source) {
        $a101Legacy = $a101Get($a101Source);
        assert_true($a101Legacy['status'] === 404 && $a101Legacy['location'] === null,
            "A10.1 redirect.php: stored off-site target behind {$a101Source} fails closed (got {$a101Legacy['status']} " . json_encode($a101Legacy['location']) . ')');
    }

    $a101Sitemap = $a101Get('/sitemap.xml', 'https://shop.example/base');
    assert_true($a101Sitemap['status'] === 200 && str_contains($a101Sitemap['body'], '<loc>https://shop.example/base/</loc>'), 'A10.1 sitemap.xml: locations use configured app_url');
    assert_true(!str_contains($a101Sitemap['body'], 'attacker.example'), 'A10.1 sitemap.xml: Host header is not reflected');
    $a101Robots = $a101Get('/robots.txt', 'https://shop.example/base');
    assert_true(str_contains($a101Robots['body'], 'Sitemap: https://shop.example/base/sitemap.xml'), 'A10.1 robots.txt: Sitemap directive uses configured app_url');
    assert_true(!str_contains($a101Robots['body'], 'attacker.example'), 'A10.1 robots.txt: Host header is not reflected');

    // Shipped default app_url => '': never fall back to the Host header.
    $a101SitemapNoUrl = $a101Get('/sitemap.xml');
    assert_true($a101SitemapNoUrl['status'] === 503 && !str_contains($a101SitemapNoUrl['body'], 'attacker.example'), "A10.1 sitemap.xml: without app_url answers 503 instead of trusting Host (got {$a101SitemapNoUrl['status']})");
    $a101RobotsNoUrl = $a101Get('/robots.txt');
    assert_true($a101RobotsNoUrl['status'] === 200 && str_contains($a101RobotsNoUrl['body'], 'Disallow: /admin') && !str_contains($a101RobotsNoUrl['body'], 'Sitemap:'),
        'A10.1 robots.txt: without app_url still serves rules but omits the Sitemap directive');
    assert_true(!str_contains($a101RobotsNoUrl['body'], 'attacker.example'), 'A10.1 robots.txt: without app_url Host header is not reflected');

    $a101Home = $a101Get('/', 'https://shop.example');
    assert_true($a101Home['status'] === 200 && str_contains($a101Home['body'], 'application/ld+json'), 'A10.1 storefront home renders JSON-LD');
    assert_true(str_contains($a101Home['body'], 'https:\/\/shop.example\/') || str_contains($a101Home['body'], 'https://shop.example/'), 'A10.1 storefront JSON-LD uses configured app_url');
    assert_true(!str_contains($a101Home['body'], 'attacker.example'), 'A10.1 storefront home: Host header is not reflected anywhere');
    $a101HomeNoUrl = $a101Get('/');
    assert_true($a101HomeNoUrl['status'] === 200 && !str_contains($a101HomeNoUrl['body'], 'attacker.example'), 'A10.1 storefront home without app_url: Host header is not reflected');
} finally {
    proc_terminate($a101Server);
    proc_close($a101Server);
    @unlink($a101Router);
    @unlink($a101Log);
    $pdo->exec("DELETE FROM content_redirects WHERE source_path LIKE '/a101-%'");
    $pdo->exec("DELETE FROM content_banners WHERE title LIKE 'A10.1%'");
    $pdo->exec("DELETE FROM content_menu_items WHERE label = 'A10.1'");
}
