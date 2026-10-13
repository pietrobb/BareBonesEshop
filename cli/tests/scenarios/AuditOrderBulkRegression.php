<?php

echo "\n--- Audit A15.2: admin orders CSV export is formula-safe, bulk status goes through OrderWorkflow, delivery notes carry no prices ---\n";

$a152Root = test_fixture_path('admin-order-bulk');
mkdir($a152Root);
$a152Fixture = $a152Root . '/request.php';
$a152Source = <<<'PHP'
<?php
[, $driver, $phase, $store, $role, $action, $root, $target, $request] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Orders.php';
if (is_file($root . '/src/Entrypoints/Admin/OrderOperations.php')) require $root . '/src/Entrypoints/Admin/OrderOperations.php';
class_exists('Security'); // esc() lives in Security.php; admin.php loads it via Security::adminAuth()
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
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
    $db->exec("INSERT INTO categories (store_id, name, slug, path) VALUES ('default', 'A152', 'a152', 'a152')");
    $product = $db->prepare("INSERT INTO products (store_id, category_id, sku, name, slug, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, 10, 'active')");
    $product->execute(['default', (int)$db->lastInsertId(), 'A152-SKU', 'A152 Boots', 'a152-boots', 1000]);
    $productId = (int)$db->lastInsertId();
    // Available stock is the default warehouse's on-hand quantity; products.stock mirrors it.
    $warehouse = (int)$db->query("SELECT id FROM warehouses WHERE store_id = 'default' AND is_default = 1")->fetchColumn();
    if ($warehouse > 0) $db->exec("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES ({$warehouse}, 'A152-SKU', 10, 0)");
    $order = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, customer_phone, payment_method, shipping_method,
        shipping_addr, notes, subtotal, shipping_cost, total, currency, store_id, created_at, stock_restored)
        VALUES (?, ?, ?, ?, ?, ?, 'bank_transfer', 'courier', ?, ?, ?, 490, ?, 'eur', ?, ?, 0)");
    $item = $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'A152-SKU', 'A152 Boots', ?, 1000, ?)");
    $address = json_encode(['company' => "\rCR Company", 'street' => '-2+3 Main St', 'zip' => '81101', 'city' => "\tTabCity", 'country' => 'sk'], JSON_THROW_ON_ERROR);
    foreach ([
        ['A152-PAID', 'paid', 'paid', '=HYPERLINK("http://evil.test","x")', '@SUM(1+1)@example.test', '+421 900 000 000', '=1+2 leave at door', 3, 'default'],
        ['A152-PENDING', 'pending', 'pending', 'Plain Buyer', 'plain@example.test', '', '', 2, 'default'],
        ['A152-SHIPPED', 'shipped', 'paid', 'Shipped Buyer', 'shipped@example.test', '', '', 1, 'default'],
        ['A152-CZ', 'paid', 'paid', 'Foreign Buyer', 'foreign@example.test', '', '', 4, 'cz'],
    ] as [$number, $status, $payment, $name, $email, $phone, $notes, $qty, $owner]) {
        $order->execute([$number, $status, $payment, $name, $email, $phone, $address, $notes, $qty * 1000, $qty * 1000 + 490, $owner, date('Y-m-d H:i:s')]);
        $item->execute([(int)$db->lastInsertId(), $productId, $qty, $qty * 1000]);
    }
    exit(0);
}
$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['default'], ['id'=>'cz', 'url_prefix'=>'/cz', 'domains'=>[], 'catalog_scope'=>'default', 'currency'=>'czk']);
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('en');
$perms = ['manager' => ['orders.view', 'orders.edit', 'orders.print', 'orders.refund'], 'fulfillment' => ['orders.view', 'orders.edit', 'orders.print'], 'viewer' => ['orders.view'], 'none' => []][$role];
if ($role === 'none') $role = 'manager';
$_SESSION = ['admin_role'=>$role, 'admin_identity_id'=>1, 'admin_store_permissions'=>[$store=>$perms]];
$ids = [];
foreach ($db->query('SELECT id, order_number FROM orders')->fetchAll() as $row) $ids[$row['order_number']] = (int)$row['id'];
// The request names orders by number; the fixture maps them to the ids the browser would post.
$input = json_decode($request, true, 512, JSON_THROW_ON_ERROR);
if (isset($input['order_ids']) && is_array($input['order_ids'])) $input['order_ids'] = array_map(static fn($n) => is_string($n) && isset($ids[$n]) ? $ids[$n] : $n, $input['order_ids']);
if (isset($input['id'])) $input['id'] = $ids[$input['id']] ?? $input['id'];
$_SERVER['REQUEST_METHOD'] = $action === 'note_get' ? 'GET' : 'POST';
$_GET = $action === 'note_get' ? $input : [];
$_POST = $action === 'note_get' ? [] : $input + ['csrf_token' => Security::csrfToken()];
ob_start();
register_shutdown_function(static function() use ($db): void {
    $output = (string)ob_get_contents();
    ob_end_clean();
    $orders = [];
    foreach ($db->query('SELECT order_number, status, stock_restored FROM orders ORDER BY id')->fetchAll() as $row) $orders[$row['order_number']] = $row['status'] . '/' . (int)$row['stock_restored'];
    print json_encode([
        'output' => base64_encode($output),
        'http_status' => http_response_code(),
        'flash' => $_SESSION['flash'] ?? null,
        'orders' => $orders,
        'stock' => (int)$db->query("SELECT stock FROM products WHERE sku = 'A152-SKU'")->fetchColumn(),
        'events' => $db->query("SELECT event_type, COUNT(*) AS n FROM event_outbox GROUP BY event_type ORDER BY event_type")->fetchAll(PDO::FETCH_KEY_PAIR),
        'audit' => (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'order_status'")->fetchColumn(),
    ], JSON_THROW_ON_ERROR);
});
$call = static function(string $function): void {
    if (!function_exists($function)) { print 'MISSING ' . $function; return; }
    $function();
};
match ($action) {
    'export' => $call('handleOrdersExport'),
    'bulk' => $call('handleOrdersBulkStatus'),
    'note', 'note_get' => $call('pageDeliveryNotes'),
};
PHP;
file_put_contents($a152Fixture, $a152Source);
$a152Run = static function(array $args) use ($a152Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a152Fixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$a152Drivers = ['sqlite' => null];
if (getenv('TEST_MYSQL_DSN')) $a152Drivers['mysql'] = null;
else print "  [SKIP] Audit A15.2 native order bulk operations (set TEST_MYSQL_DSN)\n";
$a152App = dirname(TEST_CLI_DIR);
$a152En = (require $a152App . '/lang/en.php') + array_fill_keys(array_map(static fn(string $r): string => 'admin.orders.reason.' . $r,
    ['not_found', 'invalid_status', 'forbidden', 'invalid_transition', 'payment_not_confirmed', 'error']), "\0missing translation");
foreach ($a152Drivers as $a152Driver => $_) {
    $a152Target = $a152Driver === 'mysql' ? 'bbe_a152_bulk_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $a152Root . '/shop.sqlite';
    $a152Label = "A15.2 {$a152Driver}";
    try {
        [$a152Exit, , $a152Stderr] = $a152Run([$a152Driver, 'setup', 'default', 'manager', '', $a152App, $a152Target, '{}']);
        assert_equals([0, ''], [$a152Exit, $a152Stderr], "{$a152Label}: migrated fixture with orders in two stores");
        $a152Request = static function(string $action, array $input, string $role = 'manager', string $store = 'default') use ($a152Run, $a152Driver, $a152App, $a152Target, $a152Label): array {
            [$exit, $stdout, $stderr] = $a152Run([$a152Driver, 'run', $store, $role, $action, $a152App, $a152Target, json_encode($input, JSON_THROW_ON_ERROR)]);
            assert_equals([0, ''], [$exit, $stderr], "{$a152Label}/{$action}/{$role}: actual handler exits without errors");
            $result = json_decode((string)$stdout, true) ?: ['output' => base64_encode((string)$stdout), 'http_status' => 0, 'flash' => null, 'orders' => [], 'stock' => 0, 'events' => [], 'audit' => 0];
            $result['output'] = base64_decode((string)$result['output']);
            return $result;
        };
        $a152Initial = ['A152-PAID' => 'paid/0', 'A152-PENDING' => 'pending/0', 'A152-SHIPPED' => 'shipped/0', 'A152-CZ' => 'paid/0'];

        // CSV export: every cell a spreadsheet would run as a formula starts with an apostrophe.
        $a152Csv = $a152Request('export', []);
        $a152Rows = array_map(static fn(string $line): array => str_getcsv($line, ',', '"', ''), preg_split('/\r?\n(?=[^\r\n])/', rtrim($a152Csv['output'], "\n")));
        assert_equals('order_number', $a152Rows[0][0] ?? null, "{$a152Label}: the admin can export orders as CSV");
        $a152ByNumber = [];
        foreach (array_slice($a152Rows, 1) as $a152Row) if (count($a152Row) === count($a152Rows[0])) $a152ByNumber[$a152Row[0]] = array_combine($a152Rows[0], $a152Row);
        assert_equals(['A152-PAID', 'A152-PENDING', 'A152-SHIPPED'], array_values(array_intersect(['A152-PAID', 'A152-PENDING', 'A152-SHIPPED', 'A152-CZ'], array_keys($a152ByNumber))), "{$a152Label}: export lists this store's orders only");
        $a152Paid = $a152ByNumber['A152-PAID'] ?? [];
        assert_equals([
            'customer_name' => "'=HYPERLINK(\"http://evil.test\",\"x\")", 'customer_email' => "'@SUM(1+1)@example.test", 'customer_phone' => "'+421 900 000 000",
            'company' => "'\rCR Company", 'street' => "'-2+3 Main St", 'city' => "'\tTabCity", 'currency' => 'EUR', 'total' => '34.90', 'items' => '3', 'notes' => "'=1+2 leave at door",
        ], array_intersect_key($a152Paid, array_flip(['customer_name', 'customer_email', 'customer_phone', 'company', 'street', 'city', 'notes', 'total', 'currency', 'items'])),
            "{$a152Label}: =, @, +, -, tab and CR cells are neutralized; amounts stay plain numbers");
        $a152Unsafe = [];
        foreach (array_slice($a152Rows, 1) as $a152Row) foreach ($a152Row as $a152Cell) {
            if ($a152Cell !== '' && in_array($a152Cell[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($a152Cell)) $a152Unsafe[] = $a152Cell;
        }
        assert_equals([], $a152Unsafe, "{$a152Label}: no exported cell starts a formula");
        $a152Filtered = $a152Request('export', ['status' => 'pending']);
        assert_true(str_contains($a152Filtered['output'], 'A152-PENDING') && !str_contains($a152Filtered['output'], 'A152-PAID'), "{$a152Label}: export follows the status filter");
        $a152NoGrant = $a152Request('export', [], 'none');
        assert_equals([403, false], [$a152NoGrant['http_status'], str_contains($a152NoGrant['output'], 'A152-')], "{$a152Label}: export without orders.view for the store is refused");

        // Bulk status: only through OrderWorkflow; refused orders are reported and left untouched.
        $a152Denied = $a152Request('bulk', ['order_ids' => ['A152-PENDING'], 'new_status' => 'cancelled'], 'viewer');
        assert_equals([403, $a152Initial], [$a152Denied['http_status'], $a152Denied['orders']], "{$a152Label}: a viewer cannot change order status in bulk");
        foreach ([['bogus', 'invalid_status'], [['cancelled'], 'invalid_status'], ['paid', 'payment_not_confirmed']] as [$a152To, $a152Reason]) {
            $a152Result = $a152Request('bulk', ['order_ids' => ['A152-PENDING'], 'new_status' => $a152To]);
            assert_equals($a152Initial, $a152Result['orders'], "{$a152Label}: bulk " . json_encode($a152To) . " changes nothing");
            assert_true(str_contains((string)($a152Result['flash']['msg'] ?? ''), $a152En['admin.orders.reason.' . $a152Reason]), "{$a152Label}: bulk " . json_encode($a152To) . " reports {$a152Reason}");
        }
        $a152Cancel = $a152Request('bulk', ['order_ids' => ['A152-PAID', 'A152-PENDING', 'A152-SHIPPED', 'A152-CZ', 999999, 'x', ['nested']], 'new_status' => 'cancelled', 'note' => 'Bulk cancel']);
        assert_equals(['A152-PAID' => 'cancelled/1', 'A152-PENDING' => 'cancelled/1', 'A152-SHIPPED' => 'shipped/0', 'A152-CZ' => 'paid/0'], $a152Cancel['orders'],
            "{$a152Label}: allowed orders are cancelled, shipped (not allowed) and the other store's order are untouched");
        assert_equals([15, 2, ['order.status_changed' => 2, 'stock.changed' => 2]], [$a152Cancel['stock'], $a152Cancel['audit'], $a152Cancel['events']],
            "{$a152Label}: stock returns once per cancelled order, with one audit entry and status + stock events each");
        assert_true(str_contains((string)($a152Cancel['flash']['msg'] ?? ''), 'A152-PAID, A152-PENDING') && str_contains((string)($a152Cancel['flash']['msg'] ?? ''), $a152En['admin.orders.reason.invalid_transition'])
            && str_contains((string)($a152Cancel['flash']['msg'] ?? ''), $a152En['admin.orders.reason.not_found']), "{$a152Label}: the result names changed orders and why the others were refused");
        $a152Again = $a152Request('bulk', ['order_ids' => ['A152-PAID', 'A152-PENDING'], 'new_status' => 'cancelled']);
        assert_equals([15, 2, $a152Cancel['orders']], [$a152Again['stock'], $a152Again['audit'], $a152Again['orders']], "{$a152Label}: repeating the bulk cancel does not return stock twice");
        $a152Refund = $a152Request('bulk', ['order_ids' => ['A152-PAID'], 'new_status' => 'refunded'], 'fulfillment');
        assert_equals($a152Cancel['orders'], $a152Refund['orders'], "{$a152Label}: refunding in bulk needs the refund permission");
        assert_true(str_contains((string)($a152Refund['flash']['msg'] ?? ''), $a152En['admin.orders.reason.forbidden']), "{$a152Label}: missing refund permission is reported");
        $a152Foreign = $a152Request('bulk', ['order_ids' => ['A152-PAID'], 'new_status' => 'refunded'], 'manager', 'cz');
        assert_equals($a152Cancel['orders'], $a152Foreign['orders'], "{$a152Label}: the cz store cannot change a default-store order");

        // Delivery notes: goods and quantities, never prices; bulk from the list, single from the detail.
        $a152Html = $a152Request('note', ['order_ids' => ['A152-PAID', 'A152-SHIPPED', 'A152-CZ'], 'format' => 'html'], 'fulfillment');
        preg_match_all('~data-delivery-note="([^"]+)"~', $a152Html['output'], $a152Notes);
        assert_equals(['A152-PAID', 'A152-SHIPPED'], $a152Notes[1], "{$a152Label}: bulk HTML delivery notes for the selected orders of this store only");
        assert_true(str_contains($a152Html['output'], 'A152-SKU') && str_contains($a152Html['output'], '81101 ' . "\t" . 'TabCity') && str_contains($a152Html['output'], 'page-break-after'), "{$a152Label}: note has items, address and one page per order");
        foreach (['34,90', '34.90', '10,00', '30,00', '4,90', '€', 'EUR'] as $a152Price) {
            assert_true(!str_contains($a152Html['output'], $a152Price), "{$a152Label}: HTML delivery note shows no price ({$a152Price})");
        }
        assert_true(!str_contains($a152Html['output'], '<script') && str_contains($a152Html['output'], '=HYPERLINK(&quot;'), "{$a152Label}: customer text is escaped in the delivery note");
        $a152Pdf = $a152Request('note_get', ['id' => 'A152-PAID', 'format' => 'pdf'], 'fulfillment');
        assert_true(str_starts_with($a152Pdf['output'], '%PDF-') && str_contains($a152Pdf['output'], 'A152-PAID') && str_contains($a152Pdf['output'], 'A152 Boots'), "{$a152Label}: single PDF delivery note from the order detail");
        foreach (['34,90', '34.90', '10,00', '30,00', '4,90'] as $a152Price) {
            assert_true(!str_contains($a152Pdf['output'], $a152Price), "{$a152Label}: PDF delivery note shows no price ({$a152Price})");
        }
        $a152BulkPdf = $a152Request('note', ['order_ids' => ['A152-PAID', 'A152-PENDING'], 'format' => 'pdf'], 'fulfillment');
        assert_equals(2, preg_match_all('~/Type /Page\b~', $a152BulkPdf['output']), "{$a152Label}: bulk PDF has one page per order");
        assert_equals(404, $a152Request('note_get', ['id' => 'A152-CZ'], 'fulfillment')['http_status'], "{$a152Label}: another store's order has no delivery note");
        assert_equals(403, $a152Request('note_get', ['id' => 'A152-PAID'], 'viewer')['http_status'], "{$a152Label}: printing delivery notes needs orders.print");
    } finally {
        if ($a152Driver === 'mysql') $a152Run([$a152Driver, 'teardown', 'default', 'manager', '', $a152App, $a152Target, '{}']);
    }
}
