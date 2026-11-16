<?php

// Review round 12 (R38, medium 3): an e-book next to goods was taxed in the billing country the shopper only typed
// (CheckoutService::vatContext()): a Slovak buyer with goods delivered in Slovakia and a Luxembourg billing address paid
// 17 % on the e-book instead of 23 %, and the shop declared it under OSS in Luxembourg. Next to goods the delivery
// country, proven by the dispatch, decides; a digital-only order still goes by the billing country.
echo "\n--- R38 e-book VAT country in a mixed cart follows the delivery, not a typed billing country ---\n";
$r38eRoot = test_fixture_path('ebook-country-r38');
mkdir($r38eRoot);
mkdir($r38eRoot . '/sessions');
$r38eFixture = $r38eRoot . '/request.php';
file_put_contents($r38eFixture, <<<'PHP'
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
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r38e', 'R38E', 'r38e', 0)");
    $cat = (int)$db->lastInsertId();
    $insert = $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, product_type, price, stock, status, weight_g, inventory_policy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute(['default', $cat, 'R38E-MUG', 'r38e-mug', 'R38E Mug', 'simple', 12300, 100, 'active', 500, 'deny']);
    $insert->execute(['default', $cat, 'R38E-BOOK', 'r38e-book', 'R38E E-book', 'downloadable', 1230, 0, 'active', 0, 'continue']);
    $db->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
        VALUES ('R38E-BOOK', 'pdf', 'PDF', 'r38e.pdf', 'r38e.pdf', 'application/pdf', 2, 3600, 1)");
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 100, 0)')->execute([$warehouse, 'R38E-MUG']);
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r38e_pay', 'manual', 'R38E payment', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    foreach (['SK', 'LU'] as $country) $db->prepare("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r38e_pay', ?)")->execute([$country]);
    $db->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('default', 'r38e-post', 'r38e', 'R38E post', 'parcel', 0, 0, 1)");
    $db->exec("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order)
        VALUES ('default', 'r38e-post', 'r38e', 'SK', 'domestic', 0, 999999, 0, 0, '2', 1)");
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
$stores = Bootstrap::stores();
$stores['stores']['default']['oss_enabled'] = !empty($args['oss']);
$stores['stores']['default']['legal']['country'] = 'SK';
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Bootstrap::initStoreContext('/', '', 'default');
unset($args['oss']);
VatService::reset();
$product = static fn(string $sku): int => (int)$db->query("SELECT id FROM products WHERE sku = '{$sku}'")->fetchColumn();
foreach ((array)($args['skus'] ?? ['R38E-MUG', 'R38E-BOOK']) as $sku) Cart::add($product($sku), null, 1);
unset($args['skus']);
$typed = $args + ['name' => 'R38E Kupujúci', 'email' => 'r38e@example.test', 'phone' => '+421900000038', 'street' => 'Hlavná 1',
    'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'payment' => 'r38e_pay', 'payment_method' => 'r38e_pay',
    'billing_different' => '1', 'billing_name' => 'R38E Kupujúci', 'billing_country' => 'LU', 'billing_street' => 'Rue 1',
    'billing_city' => 'Luxembourg', 'billing_zip' => '1111', 'csrf_token' => Security::csrfToken()];
$orders = static fn(): array => $db->query('SELECT id, total, vat_scenario, oss_net, financial_snapshot FROM orders ORDER BY id')->fetchAll();
$before = count($orders());
// The e-book's line of each new order: [rate in basis points, rate country, supply].
$lines = static function(array $orders): array {
    $out = [];
    foreach ($orders as $order) {
        $snapshot = json_decode((string)$order['financial_snapshot'], true) ?: [];
        $book = null;
        foreach ((array)($snapshot['components'] ?? []) as $c) if (($c['type'] ?? '') === 'product' && ($c['sku'] ?? '') === 'R38E-BOOK') $book = $c;
        if ($book === null) foreach ((array)($snapshot['components'] ?? []) as $c) if (($c['type'] ?? '') === 'product' && (int)($c['net'] ?? 0) < 5000) $book = $c;
        $out[] = ['id' => (int)$order['id'], 'total' => (int)$order['total'], 'scenario' => $order['vat_scenario'], 'oss_net' => $order['oss_net'] === null ? null : (int)$order['oss_net'],
            'ebook' => $book === null ? null : [(int)($book['rate_basis_points'] ?? -1), $book['rate_country'] ?? null, $book['supply'] ?? null]];
    }
    return $out;
};
if ($phase === 'manual') {
    $result = ManualOrder::create(ManualOrder::parseLines("R38E-MUG 1\nR38E-BOOK 1")['lines'], $typed + ['shipping_method' => 'r38e-post'], false);
    echo 'R38ERESULT' . json_encode(['result' => $result, 'new_orders' => $lines(array_slice($orders(), $before))]);
    exit(0);
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['page' => 'checkout'];
$_POST = $typed;
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($orders, $before, $lines): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR38ERESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => $lines(array_slice($orders(), $before))], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/index.php';
PHP);
$r38eRun = static function(array $args) use ($r38eFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r38eFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R38ERESULT');
    return [$exit, $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true), substr($raw, 0, min($at === false ? 600 : $at, 600))];
};
$r38eApp = dirname(TEST_CLI_DIR);
$r38eTargets = [];
$r38eSk = (int)round(VatService::rate('SK', 'standard') * 100);
$r38eLu = (int)round(VatService::rate('LU', 'standard') * 100);
try {
    foreach (['sqlite', 'mysql'] as $r38eDriver) {
        if ($r38eDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R38 e-book country native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
        $r38eTarget = $r38eDriver === 'mysql' ? 'bbe_r38e_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $r38eRoot . '/shop.sqlite';
        $r38eTargets[] = [$r38eDriver, $r38eTarget];
        [$exit, , $noise] = $r38eRun([$r38eDriver, 'setup', $r38eApp, $r38eTarget, $r38eRoot, '{}']);
        assert_equals([0, ''], [$exit, trim($noise)], "R38 e-book country {$r38eDriver}: isolated migrated fixture");
        $do = static function(string $phase, array $input) use ($r38eRun, $r38eDriver, $r38eApp, $r38eTarget, $r38eRoot): array {
            [$exit, $result, $noise] = $r38eRun([$r38eDriver, $phase, $r38eApp, $r38eTarget, $r38eRoot, json_encode($input)]);
            assert_true($exit === 0 && is_array($result), "R38 e-book country {$r38eDriver}/{$phase}: handler completes ({$noise})");
            return $result ?? [];
        };
        foreach ([true, false] as $oss) {
            $label = "R38 e-book country {$r38eDriver}/OSS " . ($oss ? 'on' : 'off');
            $page = $do('refresh', ['oss' => $oss, 'shipping_method' => 'r38e-post', 'checkout_refresh' => '1']);
            $html = (string)($page['output'] ?? '');
            $quoted = preg_match('~name="quoted_total" value="(\d+)"~', $html, $m) ? (int)$m[1] : null;
            $consents = preg_match_all('~name="consents\[([a-z_]+)\]" value="1"~', $html, $m) ? array_fill_keys($m[1], '1') : [];
            assert_equals(12300 + 1230, $quoted, "{$label}: SK delivery billed to LU shows the Slovak gross of both lines");
            $ordered = $do('order', ['oss' => $oss, 'shipping_method' => 'r38e-post', 'quoted_total' => (string)$quoted, 'consents' => $consents, 'checkout' => '1']);
            $order = $ordered['new_orders'][0] ?? [];
            $error = preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', (string)($ordered['output'] ?? ''), $m) ? html_entity_decode($m[1]) : '';
            assert_true(count($ordered['new_orders'] ?? []) === 1 && ($order['scenario'] ?? '') === 'domestic' && ($order['total'] ?? -1) === $quoted,
                "{$label}: storefront order placed domestic at the shown total (" . json_encode($order) . " {$error})");
            assert_true(($order['ebook'][0] ?? -1) === $r38eSk && !in_array($order['ebook'][2] ?? null, ['oss'], true) && ($order['oss_net'] ?? -1) === 0,
                "{$label}: the e-book pays {$r38eSk} bp Slovak VAT, not {$r38eLu} bp Luxembourg OSS, and nothing counts abroad (" . json_encode($order['ebook'] ?? null) . ')');
            $manual = $do('manual', ['oss' => $oss]);
            $order = $manual['new_orders'][0] ?? [];
            assert_true(($manual['result']['ok'] ?? false) && ($order['ebook'][0] ?? -1) === $r38eSk && ($order['oss_net'] ?? -1) === 0,
                "{$label}: a manual order billed to LU taxes the e-book at home too (" . json_encode([$manual['result']['error'] ?? 'ok', $order]) . ')');
        }
        // Control: a digital-only order still goes by the billing country (no dispatch contradicts it).
        $page = $do('refresh', ['oss' => true, 'skus' => ['R38E-BOOK'], 'checkout_refresh' => '1']);
        $html = (string)($page['output'] ?? '');
        $quoted = preg_match('~name="quoted_total" value="(\d+)"~', $html, $m) ? (int)$m[1] : null;
        $consents = preg_match_all('~name="consents\[([a-z_]+)\]" value="1"~', $html, $m) ? array_fill_keys($m[1], '1') : [];
        $ordered = $do('order', ['oss' => true, 'skus' => ['R38E-BOOK'], 'quoted_total' => (string)$quoted, 'consents' => $consents, 'checkout' => '1']);
        $order = $ordered['new_orders'][0] ?? [];
        assert_true(($order['scenario'] ?? '') === 'oss' && ($order['ebook'][0] ?? -1) === $r38eLu,
            "R38 e-book country {$r38eDriver}: a digital-only order billed to LU is still a Luxembourg OSS supply (" . json_encode($order) . ')');
    }
} finally {
    foreach ($r38eTargets as [$r38eDriver, $r38eTarget]) if ($r38eDriver === 'mysql') $r38eRun(['mysql', 'teardown', $r38eApp, $r38eTarget, $r38eRoot, '{}']);
}
