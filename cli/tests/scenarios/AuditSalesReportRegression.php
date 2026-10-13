<?php

echo "\n--- Audit A15.1: admin dashboard, sales overview and reports never add up different currencies; days follow the shop timezone ---\n";

$a151Root = test_fixture_path('admin-sales-report');
mkdir($a151Root);
$a151Fixture = $a151Root . '/request.php';
$a151Source = <<<'PHP'
<?php
[, $driver, $phase, $store, $mode, $root, $target, $zone] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Orders.php';
require $root . '/src/Entrypoints/Admin/Operations.php';
if (is_file($root . '/src/Entrypoints/Admin/Reports.php')) require $root . '/src/Entrypoints/Admin/Reports.php';
class_exists('Security'); // esc() lives in Security.php; admin.php loads it via Security::adminAuth()
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
date_default_timezone_set($zone);
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate-' . $driver . '.lock'))->apply();
    // Local wall-clock times of the shop timezone, stored as UTC like every machine timestamp.
    $midnight = (new DateTimeImmutable('today', new DateTimeZone($zone)));
    $utc = static fn(DateTimeImmutable $t): string => $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $today = $utc($midnight->modify('+5 minutes'));
    $yesterday = $utc($midnight->modify('-30 minutes'));
    $old = $utc($midnight->modify('-40 days'));
    $order = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id, created_at, is_synthetic)
        VALUES (?, ?, 'paid', 'A151 Buyer', 'a151@example.test', 'bank', ?, ?, ?, ?, ?, ?)");
    $item = $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?, ?, ?)");
    // [number, store, status, total, currency, created, synthetic, items: [product_id, sku, qty, total]]
    foreach ([
        ['A151-EUR-TODAY', 'default', 'paid', 1000, 'eur', $today, 0, [[1, 'A151-A', 2, 1000]]],
        ['A151-CZK-TODAY', 'default', 'paid', 25000, 'czk', $today, 0, [[2, 'A151-B', 1, 25000]]],
        ['A151-HUF-TODAY', 'default', 'pending', 1000000, 'HUF', $today, 0, [[1, 'A151-A', 1, 1000000]]],
        ['A151-EUR-CANCELLED', 'default', 'cancelled', 99900, 'eur', $today, 0, [[3, 'A151-C', 9, 99900]]],
        ['A151-EUR-SMOKE', 'default', 'pending', 55500, 'eur', $today, 1, [[3, 'A151-C', 1, 55500]]],
        ['A151-EUR-YESTERDAY', 'default', 'delivered', 500, 'eur', $yesterday, 0, [[3, 'A151-C', 1, 500]]],
        ['A151-EUR-OLD', 'default', 'delivered', 2000, 'eur', $old, 0, [[1, 'A151-A', 4, 2000]]],
        ['A151-CZ-TODAY', 'cz', 'paid', 777700, 'czk', $today, 0, [[2, 'A151-B', 7, 777700]]],
    ] as [$number, $owner, $status, $total, $currency, $created, $synthetic, $items]) {
        $order->execute([$number, $status, $total, $total, $currency, $owner, $created, $synthetic]);
        $orderId = (int)$db->lastInsertId();
        foreach ($items as [$productId, $sku, $qty, $lineTotal]) $item->execute([$orderId, $productId, $sku, 'Product ' . $sku, $qty, intdiv($lineTotal, $qty), $lineTotal]);
    }
    exit(0);
}
$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['default'], ['id'=>'cz', 'url_prefix'=>'/cz', 'domains'=>[], 'catalog_scope'=>'default', 'currency'=>'czk']);
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('en');
$_SESSION = ['admin_role'=>'manager', 'admin_identity_id'=>1, 'admin_store_permissions'=>[$store=>['orders.view', 'reports.view']]];
[$page, $query] = array_pad(explode('?', $mode, 2), 2, '');
parse_str($query, $_GET);
ob_start();
match ($page) {
    'dashboard' => pageDashboard(),
    'sales' => pageSalesOverview(),
    'reports' => function_exists('pageReports') ? pageReports() : print('NO_REPORTS_PAGE'),
};
echo ob_get_clean();
PHP;
file_put_contents($a151Fixture, $a151Source);
$a151Run = static function(array $args) use ($a151Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a151Fixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
/** @return array<string, array<string, string>> "currency[/key]" => data-k cells of the report rows of one kind */
$a151Rows = static function(string $html, string $kind): array {
    preg_match_all('~<tr data-report="' . $kind . '" data-currency="([a-z]+)"(?: data-key="([^"]+)")?>(.*?)</tr>~s', $html, $rows, PREG_SET_ORDER);
    $out = [];
    foreach ($rows as $row) {
        preg_match_all('~data-k="([a-z]+)">([^<]*)<~', $row[3], $cells, PREG_SET_ORDER);
        $out[$row[1] . ($row[2] !== '' ? '/' . $row[2] : '')] = array_column(array_map(static fn(array $c): array => [$c[1], html_entity_decode($c[2])], $cells), 1, 0);
    }
    return $out;
};
$a151Drivers = ['sqlite' => null];
if (getenv('TEST_MYSQL_DSN')) $a151Drivers['mysql'] = null;
else print "  [SKIP] Audit A15.1 native sales report (set TEST_MYSQL_DSN)\n";
$a151App = dirname(TEST_CLI_DIR);
foreach ($a151Drivers as $a151Driver => $_) {
    // East and west of UTC: the shop's calendar day starts before / after the UTC one.
    foreach (['Europe/Bratislava', 'America/New_York'] as $a151Zone) {
        $a151Target = $a151Driver === 'mysql'
            ? 'bbe_a151_report_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test'
            : $a151Root . '/' . md5($a151Zone) . '.sqlite';
        $a151Label = "A15.1 {$a151Driver}/{$a151Zone}";
        try {
            [$a151Exit, , $a151Stderr] = $a151Run([$a151Driver, 'setup', 'default', '', $a151App, $a151Target, $a151Zone]);
            assert_equals([0, ''], [$a151Exit, $a151Stderr], "{$a151Label}: migrated fixture with EUR/CZK/HUF orders in two stores");
            $a151Page = static function(string $mode, string $store = 'default') use ($a151Run, $a151Driver, $a151App, $a151Target, $a151Zone, $a151Label): string {
                [$exit, $stdout, $stderr] = $a151Run([$a151Driver, 'run', $store, $mode, $a151App, $a151Target, $a151Zone]);
                assert_equals([0, ''], [$exit, $stderr], "{$a151Label}/{$mode}: page renders without errors");
                return (string)$stdout;
            };

            foreach (['dashboard', 'sales'] as $a151Screen) {
                $a151Html = $a151Page($a151Screen);
                preg_match_all('~data-revenue-today="([a-z]+)">([^<]*)<~', $a151Html, $a151Today, PREG_SET_ORDER);
                $a151TodayMap = array_column(array_map(static fn(array $m): array => [$m[1], html_entity_decode($m[2])], $a151Today), 1, 0);
                assert_equals(['czk' => '250 Kč', 'eur' => '10,00 €', 'huf' => '10 000 Ft'], $a151TodayMap,
                    "{$a151Label}/{$a151Screen}: today's revenue is shown per currency, from local midnight, without cancelled or smoke-test orders");
                foreach (['10 260,00 €' => 'all currencies summed as EUR', '565,00 €' => 'smoke-test order counted', '7 777 Kč' => 'other store leaked', '1 009,00 €' => 'cancelled order counted'] as $a151Bad => $a151Why) {
                    assert_true(!str_contains($a151Html, $a151Bad), "{$a151Label}/{$a151Screen}: no '{$a151Bad}' ({$a151Why})");
                }
            }

            $a151Html = $a151Page('reports');
            assert_true(!str_contains($a151Html, 'NO_REPORTS_PAGE'), "{$a151Label}: the admin has a sales reports page");
            assert_equals([
                'czk' => ['orders' => '1', 'revenue' => '250 Kč', 'aov' => '250 Kč'],
                'eur' => ['orders' => '2', 'revenue' => '15,00 €', 'aov' => '7,50 €'],
                'huf' => ['orders' => '1', 'revenue' => '10 000 Ft', 'aov' => '10 000 Ft'],
            ], $a151Rows($a151Html, 'totals'), "{$a151Label}: 30-day orders, revenue and AOV per currency (yesterday local included, 40-day-old excluded)");
            $a151Trend = $a151Rows($a151Html, 'trend');
            $a151Day = (new DateTimeImmutable('now', new DateTimeZone($a151Zone)))->format('Y-m-d');
            $a151Yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone($a151Zone)))->format('Y-m-d');
            assert_equals(90, count($a151Trend), "{$a151Label}: daily trend has 30 local days for each of the 3 currencies");
            assert_equals([['orders' => '1', 'revenue' => '10,00 €'], ['orders' => '1', 'revenue' => '5,00 €'], ['orders' => '1', 'revenue' => '250 Kč'], ['orders' => '0', 'revenue' => '0 Kč']],
                [$a151Trend["eur/{$a151Day}"] ?? null, $a151Trend["eur/{$a151Yesterday}"] ?? null, $a151Trend["czk/{$a151Day}"] ?? null, $a151Trend["czk/{$a151Yesterday}"] ?? null],
                "{$a151Label}: trend buckets orders into the shop's local days, per currency");
            assert_equals([
                'czk/A151-B' => ['quantity' => '1', 'revenue' => '250 Kč'],
                'eur/A151-A' => ['quantity' => '2', 'revenue' => '10,00 €'],
                'eur/A151-C' => ['quantity' => '1', 'revenue' => '5,00 €'],
                'huf/A151-A' => ['quantity' => '1', 'revenue' => '10 000 Ft'],
            ], $a151Rows($a151Html, 'top'), "{$a151Label}: top products per currency, without cancelled, smoke-test or other-store sales");

            $a151Html = $a151Page('reports?days=90');
            assert_equals(['orders' => '3', 'revenue' => '35,00 €', 'aov' => '11,67 €'], $a151Rows($a151Html, 'totals')['eur'] ?? null, "{$a151Label}: 90-day period includes the 40-day-old order");
            foreach (['reports?days=9999', 'reports?days[]=7'] as $a151Mode) {
                assert_equals('2', $a151Rows($a151Page($a151Mode), 'totals')['eur']['orders'] ?? null, "{$a151Label}/{$a151Mode}: unknown period falls back to 30 days");
            }
            $a151Html = $a151Page('reports', 'cz');
            assert_equals(['czk' => ['orders' => '1', 'revenue' => '7 777 Kč', 'aov' => '7 777 Kč']], $a151Rows($a151Html, 'totals'), "{$a151Label}: cz store sees only its own sales");
        } finally {
            if ($a151Driver === 'mysql') $a151Run([$a151Driver, 'teardown', 'default', '', $a151App, $a151Target, $a151Zone]);
        }
    }
}
