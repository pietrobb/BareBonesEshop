<?php

echo "\n--- R37: admin desktop layout, refund labels and checkout radio controls ---\n";
$r37uiLocale = I18n::locale();
foreach (['en', 'sk'] as $r37uiLocaleTest) {
    I18n::setLocale($r37uiLocaleTest);
    assert_equals(I18n::t('payment_status.refund_pending'), AuditLogLabels::value('order_refund', 'pending'), "R37 {$r37uiLocaleTest}: item refund pending uses refund state");
    assert_equals(I18n::t('admin.order.tx_status.refund_failed'), AuditLogLabels::value('order_refund', 'failed'), "R37 {$r37uiLocaleTest}: item refund failed is not payment failure");
    assert_equals(I18n::t('admin.order.tx_status.refund_succeeded'), AuditLogLabels::value('order_refund', 'succeeded'), "R37 {$r37uiLocaleTest}: item refund succeeded is translated");
    assert_true(AuditLogLabels::note('payment_status', 1, 'checkout.session.completed') !== 'checkout.session.completed', "R37 {$r37uiLocaleTest}: checkout session event translated");
}
I18n::setLocale($r37uiLocale);
if (!getenv('R37_NODE')) { echo "  [SKIP] R37 admin browser: set R37_NODE and R37_PLAYWRIGHT\n"; return; }
$r37uiRoot = dirname(TEST_CLI_DIR);
$r37uiDir = test_fixture_path('r37-admin-ui'); mkdir($r37uiDir); mkdir($r37uiDir . '/sessions');
file_put_contents($r37uiDir . '/root.txt', $r37uiRoot);
file_put_contents($r37uiDir . '/router.php', <<<'PHP'
<?php
$root = trim(file_get_contents(__DIR__ . '/root.txt'));
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (PHP_SAPI !== 'cli' && preg_match('~^/themes/default/assets/[a-zA-Z0-9_.-]+\.(?:css|js|woff2|svg)$~D', $path)) return false;
require $root . '/src/autoload.php';
$db = new ShopPDO('sqlite:' . __DIR__ . '/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
Security::setRateDbForTesting(new PDO('sqlite:' . __DIR__ . '/rate.sqlite'));
session_save_path(__DIR__ . '/sessions');
$stores = Bootstrap::stores(); $stores['stores']['default']['name'] = 'R37 Named Shop'; $stores['stores']['default']['oss_enabled'] = false; (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); Bootstrap::initLocale($_SERVER['REQUEST_URI'] ?? '/?lang=en');
if (PHP_SAPI === 'cli') {
    (new Migrator($db, $root . '/migrations', __DIR__ . '/migration.lock'))->apply();
    $id = AdminIdentity::create('r37-ui', 'R37-ui-admin-test-2026', 'owner', false);
    AdminIdentity::grantStore($id, 'default', ['*']); AdminIdentity::setStatus($id, 'active');
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r37ui', 'UI', 'r37ui', 0)");
    $category = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES ('default', ?, 'R37-UI', 'r37-ui', ?, 12300, 10, 'active', 1000)")->execute([$category, str_repeat('Product ', 20)]);
    Customers::register('ui@example.test', 'R37-ui-customer-test', str_repeat('Customer ', 15));
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order) VALUES ('default', 'r37ui_pay', 'manual', 'R37 UI payment', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    $db->exec("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r37ui_pay', 'SK')");
    $db->prepare("INSERT INTO orders (store_id, order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method) VALUES ('default', 'R37-UI-1', ?, 'ui@example.test', '{}', 12300, 12300, 'eur', 'delivered', 'paid', 'bank_transfer')")->execute([str_repeat('Customer ', 15)]);
    $order = (int)$db->lastInsertId(); $db->prepare("UPDATE orders SET subtotal = 1100000, total = 1320000, net_total = 1100000, vat_scenario = 'oss', shipping_addr = ?, created_at = ? WHERE id = ?")->execute([json_encode(['country' => 'AT']), ShopTime::date('Y-m-d H:i:s'), $order]); OssThreshold::recordOrder($db, $order);
    AuditLog::log('order_refund', $order, 'pending', 'failed', 'R37 UI stopped refund');
    AuditLog::log('payment_status', $order, 'pending', 'paid', 'checkout.session.completed');
    exit;
}
if ($path === '/admin.php') require $root . '/admin.php';
else {
    Security::sessionStart();
    if (empty($_SESSION['r37_cart'])) { Cart::add(1, null, 1); $_SESSION['r37_cart'] = true; }
    require $root . '/index.php';
}
PHP);
$r37uiSetup = proc_open([PHP_BINARY, $r37uiDir . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $r37uiDir . '/setup.log', 'w'], 2 => ['file', $r37uiDir . '/setup.log', 'a']], $r37uiPipes);
$r37uiSetupExit = proc_close($r37uiSetup);
assert_equals(0, $r37uiSetupExit, 'R37 UI: isolated seeded database (' . file_get_contents($r37uiDir . '/setup.log') . ')');
if ($r37uiSetupExit !== 0) return;
$r37uiSocket = stream_socket_server('tcp://127.0.0.1:0'); $r37uiAddress = stream_socket_get_name($r37uiSocket, false); fclose($r37uiSocket);
$r37uiServer = proc_open([PHP_BINARY, '-S', $r37uiAddress, '-t', $r37uiRoot, $r37uiDir . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $r37uiDir . '/http.log', 'w'], 2 => ['file', $r37uiDir . '/http.log', 'a']], $r37uiPipes);
try {
    for ($r37uiTry = 0; $r37uiTry < 100; $r37uiTry++) {
        $r37uiProbe = @stream_socket_client('tcp://' . $r37uiAddress, $r37uiErrno, $r37uiErrstr, .1);
        if ($r37uiProbe) { fclose($r37uiProbe); break; } usleep(50000);
    }
    $r37uiScript = 'const fixture = ' . json_encode(['url' => 'http://' . $r37uiAddress, 'playwright' => getenv('R37_PLAYWRIGHT') ?: '@playwright/test']) . ";\n" . file_get_contents($r37uiRoot . '/tests/e2e/r37-admin-ui.cjs');
    $r37uiBrowser = proc_open([getenv('R37_NODE'), '-'], [0 => ['pipe', 'r'], 1 => ['file', $r37uiDir . '/browser.log', 'w'], 2 => ['file', $r37uiDir . '/browser.log', 'a']], $r37uiPipes);
    fwrite($r37uiPipes[0], $r37uiScript); fclose($r37uiPipes[0]); $r37uiExit = proc_close($r37uiBrowser);
    echo file_get_contents($r37uiDir . '/browser.log');
    assert_equals(0, $r37uiExit, 'R37 UI: Chromium desktop admin and checkout controls');
} finally { proc_terminate($r37uiServer); proc_close($r37uiServer); }
echo "R37 UI artifacts: {$r37uiDir}\n";
