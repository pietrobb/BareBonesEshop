<?php

echo "\n--- R47: admin pages keep the viewport width (badges, tables) at 390, 1024 and 1280 px ---\n";
// The CSS contract the browser check below proves visually: a badge in running text may wrap, one in a table cell does not.
$r47lCss = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin.php');
assert_true(preg_match('~(?:^|\})\.badge\{[^}]*white-space:nowrap~m', $r47lCss) !== 1, 'R47 layout: a badge outside tables may wrap (no global white-space:nowrap on .badge)');
assert_true(str_contains($r47lCss, 'td>.badge{white-space:nowrap}'), 'R47 layout: a status badge in a table cell stays on one line');
// R47 a11y (review kolo 17, low 3): the same contract without a browser; the browser script checks the rendered result.
assert_true(!preg_match('~outline:\s*(?:none|0)~', str_replace('#main:focus{outline:0}', '', $r47lCss)), 'R47 a11y: no control loses its focus outline (only the skip target <main> has none)');
assert_true(str_contains($r47lCss, ':focus-visible{outline:2px solid'), 'R47 a11y: keyboard focus draws a 2px outline');
assert_true(!str_contains($r47lCss, 'content:"ADMIN"') && str_contains($r47lCss, "I18n::t('admin.nav.badge')"), 'R47 a11y: the nav badge is translated text, not CSS content');
assert_true(str_contains($r47lCss, '<a class="skip-link" href="#main">') && str_contains($r47lCss, '<main id="main" tabindex="-1">'), 'R47 a11y: a skip link leads to the content');
assert_true(preg_match('~td a:not\(\.btn\)\{[^}]*text-decoration:underline~', $r47lCss) === 1, 'R47 a11y: links in tables are underlined, not told apart by colour alone');
foreach (['en', 'sk', 'cs', 'de', 'pl', 'es', 'hu'] as $r47lLocale) {
    assert_true(I18n::tIn('admin.nav.badge', $r47lLocale) !== 'admin.nav.badge' && I18n::tIn('skip_to_content', $r47lLocale) !== 'skip_to_content', "R47 a11y ({$r47lLocale}): badge and skip link are translated");
}
if (!getenv('R37_NODE')) { echo "  [SKIP] R47 admin layout browser: set R37_NODE and R37_PLAYWRIGHT\n"; return; }
$r47lRoot = dirname(TEST_CLI_DIR);
$r47lDir = test_fixture_path('r47-admin-layout'); mkdir($r47lDir); mkdir($r47lDir . '/sessions');
file_put_contents($r47lDir . '/root.txt', $r47lRoot);
file_put_contents($r47lDir . '/router.php', <<<'PHP'
<?php
$root = trim(file_get_contents(__DIR__ . '/root.txt'));
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
require $root . '/src/autoload.php';
$db = new ShopPDO('sqlite:' . __DIR__ . '/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
Security::setRateDbForTesting(new PDO('sqlite:' . __DIR__ . '/rate.sqlite'));
session_save_path(__DIR__ . '/sessions');
Bootstrap::initLocale($_SERVER['REQUEST_URI'] ?? '/?lang=en');
if (PHP_SAPI === 'cli') {
    (new Migrator($db, $root . '/migrations', __DIR__ . '/migration.lock'))->apply();
    $id = AdminIdentity::create('r47-ui', 'R47-ui-admin-test-2026', 'owner', false);
    AdminIdentity::grantStore($id, 'default', ['*']); AdminIdentity::setStatus($id, 'active');
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r47ui', 'UI', 'r47ui', 0)");
    $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R47-UI', 'r47-ui', 'R47 UI product', 12300, 10, 'active')")->execute([(int)$db->lastInsertId()]);
    $db->exec("INSERT INTO orders (store_id, order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method) VALUES ('default', 'R47-UI-1', 'R47 Customer', 'ui@example.test', '{}', 12300, 12300, 'eur', 'refund_pending', 'refund_pending', 'bank_transfer')");
    exit;
}
if ($path === '/admin.php') require $root . '/admin.php';
else http_response_code(404);
PHP);
$r47lSetup = proc_open([PHP_BINARY, $r47lDir . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $r47lDir . '/setup.log', 'w'], 2 => ['file', $r47lDir . '/setup.log', 'a']], $r47lPipes);
$r47lSetupExit = proc_close($r47lSetup);
assert_equals(0, $r47lSetupExit, 'R47 layout: isolated seeded database (' . file_get_contents($r47lDir . '/setup.log') . ')');
if ($r47lSetupExit !== 0) return;
$r47lSocket = stream_socket_server('tcp://127.0.0.1:0'); $r47lAddress = stream_socket_get_name($r47lSocket, false); fclose($r47lSocket);
$r47lServer = test_spawn_server([PHP_BINARY, '-S', $r47lAddress, '-t', $r47lRoot, $r47lDir . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $r47lDir . '/http.log', 'w'], 2 => ['file', $r47lDir . '/http.log', 'a']], $r47lPipes);
try {
    for ($r47lTry = 0; $r47lTry < 100; $r47lTry++) {
        $r47lProbe = @stream_socket_client('tcp://' . $r47lAddress, $r47lErrno, $r47lErrstr, .1);
        if ($r47lProbe) { fclose($r47lProbe); break; } usleep(50000);
    }
    $r47lScript = 'const fixture = ' . json_encode(['url' => 'http://' . $r47lAddress, 'playwright' => getenv('R37_PLAYWRIGHT') ?: '@playwright/test']) . ";\n" . file_get_contents($r47lRoot . '/tests/e2e/r47-admin-layout.cjs');
    $r47lBrowser = proc_open([getenv('R37_NODE'), '-'], [0 => ['pipe', 'r'], 1 => ['file', $r47lDir . '/browser.log', 'w'], 2 => ['file', $r47lDir . '/browser.log', 'a']], $r47lPipes);
    fwrite($r47lPipes[0], $r47lScript); fclose($r47lPipes[0]); $r47lExit = proc_close($r47lBrowser);
    echo file_get_contents($r47lDir . '/browser.log');
    assert_equals(0, $r47lExit, 'R47 layout: Chromium — every admin page fits 390, 1024 and 1280 px; skip link, focus ring, table links and nav badge pass');
} finally { proc_terminate($r47lServer); proc_close($r47lServer); }
