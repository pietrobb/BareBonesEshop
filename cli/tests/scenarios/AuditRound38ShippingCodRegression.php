<?php
$pdo ??= test_commerce_fixture();

// Review round 12 (R38, low): a carrier adapter returns its cash-on-delivery surcharge apart from the price
// (ShippingAdapter::getRates(): price + cod_surcharge), while checkout bills only a surcharge carried inside the price
// (a database rate's base_price + cod_surcharge). In a currency other than the store's price lists the built-in
// carriers' tiers apply (R8): Packeta's 1 EUR and GLS's 1.50 EUR COD fee were never charged.
echo "\n--- R38 the built-in carriers' COD surcharge is charged in another currency ---\n";

$r38cRoot = test_fixture_path('shipping-cod-r38');
mkdir($r38cRoot);
mkdir($r38cRoot . '/sessions');
$r38cFixture = $r38cRoot . '/request.php';
file_put_contents($r38cFixture, <<<'PHP'
<?php
[, $driver, $phase, $root, $target, $dir, $extra] = $argv;
require $root . '/src/autoload.php';
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
$args = json_decode($extra, true) ?: [];
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r38c', 'R38C', 'r38c', 0)");
    $cat = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R38C-MUG', 'r38c-mug', 'R38C Mug', 1000, 100, 'active', 500]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 100, 0)')->execute([$warehouse, 'R38C-MUG']);
    // Cash on delivery of the CZK price lists' scope with no amount of its own (fees and limits are CZK there), so it
    // is offered to a EUR cart too.
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('cz', 'r38c_cod', 'manual', 'R38C COD', '', 'cod', 0, 0, 0, 0, 0, 1, 990)");
    $db->exec("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('cz', 'r38c_cod', 'SK')");
    exit(0);
}
$config = require AppConfig::path($root);
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.38.3';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
// A EUR storefront on the CZK price lists (stores.json: provider_scope of a CZK store): the carriers' built-in tiers.
$stores = Bootstrap::stores();
$stores['default_store'] = 'default';
$stores['stores']['default']['currency'] = 'eur';
$stores['stores']['default']['provider_scope'] = 'cz';
$stores['stores']['default']['legal']['country'] = 'SK';
$stores['stores']['cz'] = ($stores['stores']['cz'] ?? []) + ['name' => 'CZ', 'currency' => 'czk', 'url_prefix' => '/cz', 'languages' => ['cs'], 'default_locale' => 'cs', 'channels' => ['storefront']];
$stores['stores']['cz']['currency'] = 'czk';
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
if ($phase === 'context') {
    echo "\nR38CRESULT" . json_encode(['own_prices' => StoreContext::current()->usesProviderPrices('eur'), 'provider' => StoreContext::current()->providerId()]);
    exit(0);
}
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R38C-MUG'")->fetchColumn(), null, 1);
$typed = $args + ['name' => 'R38C Zákazník', 'email' => 'r38c@example.test', 'phone' => '+421900000038', 'street' => 'Hlavná 1',
    'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'payment' => 'r38c_cod', 'csrf_token' => Security::csrfToken()];
$orders = static fn(): array => $db->query('SELECT id, total, shipping_cost, payment_fee, shipping_method, payment_method, currency FROM orders ORDER BY id')->fetchAll();
$before = count($orders());
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['page' => 'checkout'];
$_POST = $typed;
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR38CRESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/index.php';
PHP);
$r38cRun = static function(array $args) use ($r38cFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r38cFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R38CRESULT');
    return [$exit, $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true), substr($raw, 0, min($at === false ? 600 : $at, 600))];
};
$r38cApp = dirname(TEST_CLI_DIR);
$r38cTargets = [];
try {
    foreach (['sqlite', 'mysql'] as $r38cDriver) {
        if ($r38cDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R38 shipping COD native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
        $r38cTarget = $r38cDriver === 'mysql' ? 'bbe_r38c_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $r38cRoot . '/shop.sqlite';
        $r38cTargets[] = [$r38cDriver, $r38cTarget];
        [$exit, , $noise] = $r38cRun([$r38cDriver, 'setup', $r38cApp, $r38cTarget, $r38cRoot, '{}']);
        assert_equals([0, ''], [$exit, trim($noise)], "R38 shipping COD {$r38cDriver}: isolated migrated fixture");
        [, $context] = $r38cRun([$r38cDriver, 'context', $r38cApp, $r38cTarget, $r38cRoot, '{}']);
        assert_equals(['own_prices' => false, 'provider' => 'cz'], $context, "R38 shipping COD {$r38cDriver}: the EUR cart is charged off the CZK price lists (built-in tiers)");
        // Packeta's EUR tier for 0.5 kg to Slovakia is 2.99 EUR plus 1 EUR COD; GLS's built-in COD fee is 1.50 EUR.
        foreach (['packeta' => 100, 'gls' => 150] as $method => $surcharge) {
            $label = "R38 shipping COD {$r38cDriver}/{$method}";
            $input = ['shipping_method' => $method];
            [$exit, $page, $noise] = $r38cRun([$r38cDriver, 'refresh', $r38cApp, $r38cTarget, $r38cRoot, json_encode($input + ['checkout_refresh' => '1'])]);
            $html = (string)($page['output'] ?? '');
            $quoted = preg_match('~name="quoted_total" value="(\d+)"~', $html, $m) ? (int)$m[1] : null;
            $checked = preg_match('~name="shipping_method" value="([^"]+)"[^>]*checked~', $html, $m) ? $m[1] : '';
            $consents = preg_match_all('~name="consents\[([a-z_]+)\]" value="1"~', $html, $m) ? array_fill_keys($m[1], '1') : [];
            assert_true($exit === 0 && $quoted !== null && $checked === $method, "{$label}: checkout offers the carrier with COD and a total ({$checked}, {$noise})");
            [$exit, $ordered, $noise] = $r38cRun([$r38cDriver, 'order', $r38cApp, $r38cTarget, $r38cRoot, json_encode(['quoted_total' => (string)$quoted, 'consents' => $consents, 'checkout' => '1'] + $input)]);
            $order = $ordered['new_orders'][0] ?? [];
            $error = preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', (string)($ordered['output'] ?? ''), $m) ? html_entity_decode($m[1]) : '';
            assert_true(count($ordered['new_orders'] ?? []) === 1 && (int)($order['total'] ?? -1) === $quoted,
                "{$label}: ordered at the total shown (got " . json_encode($order) . " {$error})");
            assert_true((int)($order['payment_fee'] ?? -1) === $surcharge
                && (int)($order['total'] ?? 0) === 1000 + (int)($order['shipping_cost'] ?? 0) + $surcharge,
                "{$label}: the carrier's COD surcharge of {$surcharge} is billed as the COD fee (was never charged; " . json_encode($order) . ')');
        }
    }
} finally {
    foreach ($r38cTargets as [$r38cDriver, $r38cTarget]) if ($r38cDriver === 'mysql') $r38cRun(['mysql', 'teardown', $r38cApp, $r38cTarget, $r38cRoot, '{}']);
}
