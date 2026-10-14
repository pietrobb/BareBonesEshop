<?php

echo "\n--- Audit A05.2: admin order lists, sales metrics and invoice PDF ---\n";
$a05OrderRoot = test_fixture_path('admin-order-store');
mkdir($a05OrderRoot);
$a05OrderFixture = $a05OrderRoot . '/request.php';
$a05OrderSource = <<<'PHP'
<?php
require $argv[5] . '/src/autoload.php';
require $argv[5] . '/src/Entrypoints/Admin/Shared.php';
require $argv[5] . '/src/Entrypoints/Admin/Orders.php';
require $argv[5] . '/src/Entrypoints/Admin/Reports.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$driver = $argv[1];
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, $driver);
$database = null;
if ($driver === 'mysql') {
    $database = 'bbe_a05_orders_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
    $db->exec("CREATE DATABASE `{$database}`");
    $db->exec("USE `{$database}`");
}
$db->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY, store_id VARCHAR(40) NOT NULL,
 order_number VARCHAR(100) NOT NULL, customer_name VARCHAR(100) NOT NULL, customer_email VARCHAR(100) NOT NULL,
 total INTEGER NOT NULL, currency VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL,
 payment_status VARCHAR(20) NOT NULL, payment_method VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL, is_synthetic INTEGER NOT NULL DEFAULT 0)");
$stmt = $db->prepare('INSERT INTO orders (id, store_id, order_number, customer_name, customer_email, total, currency, status, payment_status, payment_method, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([[5, 'default', 1234], [6, 'default', 2000], [7, 'cz', 10000], [8, 'cz', 25000]] as [$id, $store, $total]) {
    $stmt->execute([$id, $store, 'A05-' . $store . '-' . $id, 'Private ' . $store . ' ' . $id,
        $store . $id . '@example.test', $total, 'eur', 'pending', 'pending', 'bank', date('Y-m-d H:i:s')]);
}
$db->exec("CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL)");
$db->exec('INSERT INTO order_items VALUES (1,5), (2,7)');
$db->exec("CREATE TABLE digital_entitlements (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, download_count INTEGER NOT NULL)");
$db->exec('INSERT INTO digital_entitlements VALUES (1,5,2), (2,6,3), (3,7,11), (4,8,13)');
$db->exec("CREATE TABLE email_log (id INTEGER PRIMARY KEY, order_id INTEGER NULL, status VARCHAR(20) NOT NULL)");
$db->exec("INSERT INTO email_log VALUES (1,5,'failed'), (2,7,'failed'), (3,8,'failed'), (4,NULL,'failed')");
$db->exec("CREATE TABLE merchant_notifications (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, status VARCHAR(20) NOT NULL)");
$db->exec("INSERT INTO merchant_notifications VALUES (1,5,'failed'), (2,7,'failed'), (3,8,'failed')");
$db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, store_id VARCHAR(40) NOT NULL, order_id INTEGER NOT NULL,
 number VARCHAR(100) NOT NULL, pdf_path VARCHAR(100) NOT NULL)");
$db->exec("INSERT INTO invoices VALUES (15,'default',5,'INV-default','default.pdf'), (17,'cz',7,'INV-cz','cz.pdf')");
$store = $argv[2];
$action = $argv[3];
$mode = $argv[4];
$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['default'], ['id'=>'cz', 'url_prefix'=>'/cz', 'domains'=>[], 'catalog_scope'=>'default']);
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('en');
$grantStore = $mode === 'denied' ? ($store === 'default' ? 'cz' : 'default') : $store;
$_SESSION = ['admin_role'=>'manager', 'admin_identity_id'=>1, 'admin_store_permissions'=>[$grantStore=>['orders.view','orders.edit']]];
$_GET = ['period'=>'all'];
if ($mode === 'filter') $_GET['status'] = 'pending';
if ($mode === 'search') $_GET['q'] = $store === 'default' ? 'cz7@example.test' : 'default5@example.test';
if ($mode === 'second_page') $_GET['page'] = 2;
$ownId = $store === 'default' ? 5 : 7;
$foreignId = $store === 'default' ? 7 : 5;
$_GET['id'] = $action === 'pdf' ? ($mode === 'foreign' ? $foreignId + 10 : ($mode === 'missing' ? 999 : $ownId + 10)) : $foreignId;
$_POST = ['new_status'=>'cancelled'];
(new ReflectionProperty(InvoiceService::class, 'storageDir'))->setValue(null, dirname(__FILE__));
ob_start();
register_shutdown_function(static function() use ($db, $database): void {
    $output = ob_get_contents();
    ob_clean();
    $result = ['output'=>$output, 'http_status'=>http_response_code(), 'orders'=>$db->query('SELECT id,status FROM orders ORDER BY id')->fetchAll()];
    if ($database !== null) $db->exec("DROP DATABASE `{$database}`");
    print json_encode($result, JSON_THROW_ON_ERROR);
    ob_end_flush();
});
match ($action) {
    'orders' => pageOrders(),
    'sales' => pageSalesOverview(),
    'pdf' => pageInvoicePdf(),
    'status' => handleOrderStatus(),
};
PHP;
file_put_contents($a05OrderFixture, $a05OrderSource);
file_put_contents($a05OrderRoot . '/default.pdf', "%PDF-1.4\nPRIVATE-PDF-default\n%%EOF");
file_put_contents($a05OrderRoot . '/cz.pdf', "%PDF-1.4\nPRIVATE-PDF-cz\n%%EOF");
try {
    $a05OrderDrivers = ['sqlite'];
    if (getenv('TEST_MYSQL_DSN')) $a05OrderDrivers[] = 'mysql';
    else print "  [SKIP] Audit A05.2 native order admin boundary (set TEST_MYSQL_DSN)\n";
    foreach ($a05OrderDrivers as $a05OrderDriver) {
        foreach (['default', 'cz'] as $a05OrderStore) {
            foreach (['orders'=>['own','filter','second_page','denied'], 'sales'=>['own','filter','search','second_page','denied'], 'pdf'=>['own','foreign','missing','denied'], 'status'=>['foreign','denied']] as $a05OrderAction=>$a05OrderModes) {
                foreach ($a05OrderModes as $a05OrderMode) {
                    $a05OrderLabel = "A05.2 {$a05OrderDriver}/{$a05OrderStore}/{$a05OrderAction}/{$a05OrderMode}";
                    $a05OrderProcess = proc_open([PHP_BINARY, $a05OrderFixture, $a05OrderDriver, $a05OrderStore, $a05OrderAction, $a05OrderMode, dirname(TEST_CLI_DIR)], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $a05OrderPipes);
                    fclose($a05OrderPipes[0]);
                    $a05OrderOutput = stream_get_contents($a05OrderPipes[1]);
                    $a05OrderStderr = stream_get_contents($a05OrderPipes[2]);
                    fclose($a05OrderPipes[1]);
                    fclose($a05OrderPipes[2]);
                    assert_equals([0, ''], [proc_close($a05OrderProcess), $a05OrderStderr], "{$a05OrderLabel}: actual handler exits without errors");
                    $a05OrderResult = json_decode($a05OrderOutput, true, 512, JSON_THROW_ON_ERROR);
                    $a05OrderHtml = $a05OrderResult['output'];
                    $a05OrderForeignStore = $a05OrderStore === 'default' ? 'cz' : 'default';
                    assert_true(!str_contains($a05OrderHtml, 'A05-' . $a05OrderForeignStore . '-') && !str_contains($a05OrderHtml, 'PRIVATE-PDF-' . $a05OrderForeignStore), "{$a05OrderLabel}: no foreign order or PDF disclosure");
                    assert_equals(['pending','pending','pending','pending'], array_column($a05OrderResult['orders'], 'status'), "{$a05OrderLabel}: foreign status handler and reads leave rows unchanged");
                    if ($a05OrderMode === 'denied') {
                        assert_equals(403, $a05OrderResult['http_status'], "{$a05OrderLabel}: other-store grants rejected");
                        assert_true(!str_contains($a05OrderHtml, 'A05-') && !str_contains($a05OrderHtml, 'PRIVATE-PDF-'), "{$a05OrderLabel}: denied request discloses no orders");
                    } elseif ($a05OrderAction === 'pdf') {
                        assert_equals($a05OrderMode === 'own' ? false : 404, $a05OrderResult['http_status'], "{$a05OrderLabel}: foreign and missing invoice return 404");
                        // The refusal is the translated "invoice does not exist" message in whichever admin language the request resolved to.
                        $a05MissingInvoice = array_map(static fn(string $l): string => (require dirname(TEST_CLI_DIR) . "/lang/{$l}.php")['admin.order.msg.invoice_missing'], ['sk', 'cs', 'en', 'de', 'hu', 'pl']);
                        assert_true($a05OrderMode === 'own' ? $a05OrderHtml === "%PDF-1.4\nPRIVATE-PDF-{$a05OrderStore}\n%%EOF" : in_array($a05OrderHtml, $a05MissingInvoice, true),
                            "{$a05OrderLabel}: only owned PDF is served (got: " . substr($a05OrderHtml, 0, 60) . ')');
                    } elseif (in_array($a05OrderAction, ['orders','sales'], true)) {
                        assert_equals(!in_array($a05OrderMode, ['search','second_page'], true), str_contains($a05OrderHtml, 'A05-' . $a05OrderStore . '-'), "{$a05OrderLabel}: own list and filters work");
                        assert_true(str_contains($a05OrderHtml, '(' . ($a05OrderMode === 'search' ? '0' : '2') . ')</span>'), "{$a05OrderLabel}: pagination count excludes foreign orders");
                        if ($a05OrderAction === 'sales') {
                            preg_match_all('/class="num">(.*?)<\/div>/s', $a05OrderHtml, $a05OrderStats);
                            assert_equals(['2', I18n::price($a05OrderStore === 'default' ? 3234 : 35000), '2', $a05OrderStore === 'default' ? '5' : '24'], array_map('strip_tags', $a05OrderStats[1]), "{$a05OrderLabel}: sales counters use order store, not shared catalog");
                            preg_match('/class="flash flash-err">(.*?)<\/div>/s', $a05OrderHtml, $a05OrderAlert);
                            preg_match_all('/\d+/', strip_tags($a05OrderAlert[1] ?? ''), $a05OrderAlertCounts);
                            assert_equals($a05OrderStore === 'default' ? ['1','1'] : ['2','2'], $a05OrderAlertCounts[0], "{$a05OrderLabel}: failed email and notification counts exclude foreign and unowned rows");
                        }
                    }
                }
            }
        }
    }
} finally {
    unlink($a05OrderFixture);
    unlink($a05OrderRoot . '/default.pdf');
    unlink($a05OrderRoot . '/cz.pdf');
    rmdir($a05OrderRoot);
}
