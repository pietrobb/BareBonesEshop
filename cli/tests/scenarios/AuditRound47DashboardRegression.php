<?php

echo "\n--- Audit R47: the dashboard shows orders, stock and their links only to admins allowed to open them ---\n";

$r47dRoot = test_fixture_path('r47-dashboard');
mkdir($r47dRoot);
$r47dFixture = $r47dRoot . '/request.php';
file_put_contents($r47dFixture, <<<'PHP'
<?php
[, $phase, $root, $target, $permissions] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Orders.php';
require $root . '/src/Entrypoints/Admin/Operations.php';
require $root . '/src/Entrypoints/Admin/Reports.php';
class_exists('Security');
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$db = new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate.lock'))->apply();
    $db->exec("INSERT INTO categories (store_id, name, slug, path) VALUES ('default', 'R47', 'r47', 'r47')");
    $category = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO products (store_id, category_id, sku, name, slug, price, stock, status) VALUES ('default', ?, 'R47-LOW-SKU', 'R47 Scarce Lamp', 'r47-lamp', 100, 1, 'active')")->execute([$category]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, 'R47-LOW-SKU', 1, 0)")->execute([$warehouse]);
    $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id, created_at, is_synthetic)
        VALUES ('R47-ORDER-0001', 'pending', 'pending', 'R47 Secret Buyer', 'r47@example.test', 'bank', 4321, 4321, 'eur', 'default', ?, 0)")->execute([gmdate('Y-m-d H:i:s', time() - 7200)]);
    // Capability review #567: the order is a stale-pending payment incident and its mail failed, so the incident tables render too.
    $order = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO email_log (store_id, order_id, recipient, template, subject, payload, status, error) VALUES ('default', ?, 'r47@example.test', 'order_created', 'R47', '{}', 'failed', 'smtp down')")->execute([$order]);
    $db->prepare("INSERT INTO operational_incidents (id, store_id, code, phase, severity, correlation_id, occurred_at, context_json) VALUES ('r47incident', 'default', 'payment_stuck', 'payment', 'error', 'r47-corr', ?, ?)")->execute([time(), json_encode(['order_id' => 4747])]);
    $db->prepare('UPDATE orders SET id = 4747 WHERE id = ?')->execute([$order]);
    $db->exec('UPDATE email_log SET order_id = 4747');
    exit(0);
}
StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), '/', '', 'default'));
I18n::setLocale('en');
$_SESSION = ['admin_role'=>'manager', 'admin_identity_id'=>1, 'admin_store_permissions'=>['default'=>explode(',', $permissions)]];
ob_start();
pageDashboard();
echo ob_get_clean();
PHP);
$r47dRun = static function(array $args) use ($r47dFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r47dFixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string)$stdout, (string)$stderr];
};
$r47dApp = dirname(TEST_CLI_DIR);
$r47dTarget = $r47dRoot . '/shop.sqlite';
[$r47dExit, , $r47dStderr] = $r47dRun(['setup', $r47dApp, $r47dTarget, '']);
assert_equals([0, ''], [$r47dExit, $r47dStderr], 'R47 dashboard: fixture with a recent order and a low-stock product');
$r47dPage = static function(string $permissions) use ($r47dRun, $r47dApp, $r47dTarget): string {
    [$exit, $stdout, $stderr] = $r47dRun(['run', $r47dApp, $r47dTarget, $permissions]);
    assert_equals([0, ''], [$exit, $stderr], "R47 dashboard ({$permissions}): renders without errors");
    return $stdout;
};
/** @return string[] the admin pages the dashboard links to (p=…) */
$r47dLinks = static function(string $html): array {
    preg_match_all('~href="admin\.php\?p=([a-z_]+)~', $html, $m);
    return array_values(array_unique($m[1]));
};

$r47dHtml = $r47dPage('reports.view');
foreach (['R47 Secret Buyer' => 'customer name', 'R47-ORDER-0001' => 'order number', '43,21' => 'order total', 'R47-LOW-SKU' => 'low-stock SKU', 'R47 Scarce Lamp' => 'low-stock product name',
    'r47@example.test' => 'failed-mail recipient', '#4747' => 'monitored incident order id'] as $r47dSecret => $r47dWhat) {
    assert_true(!str_contains($r47dHtml, $r47dSecret), "R47 dashboard (reports.view only): no {$r47dWhat} ('{$r47dSecret}')");
}
foreach (['order', 'orders', 'product', 'products'] as $r47dPageName) {
    assert_true(!in_array($r47dPageName, $r47dLinks($r47dHtml), true), "R47 dashboard (reports.view only): no link to p={$r47dPageName}, which answers 403");
}
assert_true(str_contains($r47dHtml, 'class="kpi kpi-') && substr_count($r47dHtml, 'class="kpi kpi-') === 4, 'R47 dashboard (reports.view only): the four figure tiles stay, as plain figures');
assert_true(in_array('reports', $r47dLinks($r47dHtml), true), 'R47 dashboard (reports.view only): the reports link stays');
assert_true(preg_match('~admin\.ops\.payment_incidents|Payment incidents~i', $r47dHtml) === 1, 'R47 dashboard (reports.view only): the payment-incident count is still reported');

$r47dHtml = $r47dPage('reports.view,orders.view');
assert_true(str_contains($r47dHtml, 'r47@example.test') && str_contains($r47dHtml, 'admin.php?p=order&id=4747">#4747'), 'R47 dashboard (orders.view): incident tables show the failed-mail recipient and the incident order');
assert_true(substr_count($r47dHtml, 'R47-ORDER-0001') >= 2, 'R47 dashboard (orders.view): the stale order is listed as a payment incident as well as a recent order');

$r47dHtml = $r47dPage('reports.view,orders.view');
foreach (['R47 Secret Buyer', 'R47-ORDER-0001'] as $r47dShown) assert_true(str_contains($r47dHtml, $r47dShown), "R47 dashboard (orders.view): recent orders show '{$r47dShown}'");
assert_true(!str_contains($r47dHtml, 'R47-LOW-SKU'), 'R47 dashboard (orders.view without products.view): no low-stock list');
$r47dSeen = $r47dLinks($r47dHtml);
assert_true(in_array('order', $r47dSeen, true) && in_array('orders', $r47dSeen, true) && !in_array('products', $r47dSeen, true), 'R47 dashboard (orders.view): order links, no product list link');

$r47dHtml = $r47dPage('reports.view,products.view');
assert_true(str_contains($r47dHtml, 'R47-LOW-SKU') && str_contains($r47dHtml, 'R47 Scarce Lamp'), 'R47 dashboard (products.view): low-stock list shown');
$r47dSeen = $r47dLinks($r47dHtml);
assert_true(in_array('products', $r47dSeen, true) && !in_array('product', $r47dSeen, true), 'R47 dashboard (products.view without products.edit): product list linked, product editor not');
assert_true(!str_contains($r47dHtml, 'R47 Secret Buyer'), 'R47 dashboard (products.view): still no recent orders');

$r47dSeen = $r47dLinks($r47dPage('reports.view,products.view,products.edit'));
assert_true(in_array('product', $r47dSeen, true), 'R47 dashboard (products.edit): low-stock product links to the editor');
