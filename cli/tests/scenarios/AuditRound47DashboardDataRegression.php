<?php

echo "\n--- Audit R47: dashboard figures of a shop with a shared catalog, revenue in every currency, dates in the admin's language ---\n";

$r47gRoot = test_fixture_path('r47-dashboard-data');
mkdir($r47gRoot);
$r47gFixture = $r47gRoot . '/request.php';
file_put_contents($r47gFixture, <<<'PHP'
<?php
[, $phase, $root, $target, $locale] = $argv;
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
    // The catalog belongs to 'default'; the 'r47shared' shop sells from it and keeps its own orders.
    $db->exec("INSERT INTO categories (store_id, name, slug, path) VALUES ('default', 'R47G', 'r47g', 'r47g')");
    $category = (int)$db->lastInsertId();
    foreach ([1, 2, 3] as $i) {
        $db->prepare("INSERT INTO products (store_id, category_id, sku, name, slug, price, stock, status) VALUES ('default', ?, ?, ?, ?, 100, 50, 'active')")
            ->execute([$category, "R47G-{$i}", "R47G product {$i}", "r47g-{$i}"]);
    }
    $order = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id, created_at, is_synthetic)
        VALUES (?, 'paid', 'paid', 'R47G Buyer', 'r47g@example.test', 'card', ?, ?, ?, 'r47shared', ?, 0)");
    $order->execute(['R47G-CZK', 123400, 123400, 'czk', gmdate('Y-m-d H:i:s')]);
    $order->execute(['R47G-EUR', 5600, 5600, 'eur', gmdate('Y-m-d H:i:s')]);
    exit(0);
}
$stores = Bootstrap::stores();
$stores['stores']['r47shared'] = array_replace($stores['stores']['default'], ['id' => 'r47shared', 'name' => 'R47 shared', 'url_prefix' => '/r47shared', 'domains' => [],
    'catalog_scope' => 'default', 'provider_scope' => 'r47shared', 'currency' => 'czk']);
StoreContext::activate(StoreContext::resolve($stores, '/', '', 'r47shared'));
I18n::setLocale($locale);
$_SESSION = ['admin_role'=>'manager', 'admin_identity_id'=>1, 'admin_store_permissions'=>['r47shared'=>['reports.view']]];
ob_start();
pageDashboard();
echo ob_get_clean();
echo '<!--r47g-today:' . date('Y-m-d') . '-->';
PHP);
$r47gRun = static function(array $args) use ($r47gFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r47gFixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string)$stdout, (string)$stderr];
};
$r47gApp = dirname(TEST_CLI_DIR);
$r47gTarget = $r47gRoot . '/shop.sqlite';
[$r47gExit, , $r47gStderr] = $r47gRun(['setup', $r47gApp, $r47gTarget, 'en']);
assert_equals([0, ''], [$r47gExit, $r47gStderr], 'R47 dashboard data: fixture with a shared catalog and orders in two currencies');
$r47gPage = static function(string $locale) use ($r47gRun, $r47gApp, $r47gTarget): array {
    [$exit, $stdout, $stderr] = $r47gRun(['run', $r47gApp, $r47gTarget, $locale]);
    assert_equals([0, ''], [$exit, $stderr], "R47 dashboard data ({$locale}): renders without errors");
    preg_match('/<!--r47g-today:(\d{4}-\d{2}-\d{2})-->/', $stdout, $m);
    return [$stdout, DateTimeImmutable::createFromFormat('!Y-m-d', $m[1] ?? '1970-01-01')];
};

[$r47gHtml, $r47gToday] = $r47gPage('en');
assert_true(preg_match('~<span>' . preg_quote(I18n::tIn('admin.dashboard.total_products', 'en'), '~') . '</span><strong>3</strong>~', $r47gHtml) === 1,
    'R47 dashboard data: a shop selling from a shared catalog counts that catalog\'s products (3, was 0)');
preg_match_all('/data-report-chart="([a-z]{3})"/', $r47gHtml, $r47gCharts);
assert_equals(['czk', 'eur'], $r47gCharts[1], 'R47 dashboard data: revenue in another currency gets its own chart, the shop\'s currency first (was: CZK only)');
assert_true(str_contains($r47gHtml, '<h3>EUR</h3>') && str_contains($r47gHtml, '<h3>CZK</h3>'), 'R47 dashboard data: each chart is headed by its currency');

$r47gEnDay = $r47gToday->format('j M');
$r47gOldDay = $r47gToday->format('j.n.');
assert_true(str_contains($r47gHtml, '>' . $r47gEnDay . '</text>') && !str_contains($r47gHtml, '>' . $r47gOldDay . '</text>'),
    "R47 dashboard data (en): day labels in the English format ('{$r47gEnDay}', was '{$r47gOldDay}')");
assert_true(str_contains($r47gHtml, '<title>' . $r47gToday->format('j M Y') . ': '), 'R47 dashboard data (en): bar tooltips name the day in the English format');

[$r47gHtml] = $r47gPage('de');
$r47gDeDay = $r47gToday->format('d.m.');
assert_true(str_contains($r47gHtml, '>' . $r47gDeDay . '</text>') && str_contains($r47gHtml, '<title>' . $r47gToday->format('d.m.Y') . ': '),
    "R47 dashboard data (de): day labels and tooltips in the German format ('{$r47gDeDay}')");
[$r47gHtml] = $r47gPage('hu');
assert_true(str_contains($r47gHtml, '>' . $r47gToday->format('m. d.') . '</text>'), 'R47 dashboard data (hu): month before day');
