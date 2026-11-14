<?php

// Review round 36 (medium 3 + low, goal #456): after a change of country the checkout form still named in-store pickup
// (the default), which is not offered abroad. The VAT preview counted the goods as collected in the shop, so a business
// with a valid Austrian VAT ID saw Slovak VAT (26,40 €) while the order went by courier under reverse charge (22,68 €);
// without JS the buyer got a "total changed" he did not cause. The VAT scenario now follows the pickup only where it is
// offered. Low: cash on delivery refused for in-store pickup (no JS) now names the new total, as "total changed" does.
echo "\n--- Audit R36: the checkout VAT preview follows the delivery actually offered ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r36kStores = Bootstrap::stores();
$r36kOss = $r36kStores;
$r36kOss['stores']['default']['oss_enabled'] = true;
$r36kOss['stores']['default']['legal']['country'] = 'SK';
$r36kStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r36kStoresProp->setValue(null, $r36kOss);
Bootstrap::initStoreContext('/', '', 'default');
$r36kCart = $_SESSION['cart'] ?? null;
try {
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99362, 'default', 1, 'R36K lamp', 'r36k-lamp', 'R36K-LAMP', 'simple', 12300, 'eur', 'active', 10, 'continue')");
    $pdo->prepare("DELETE FROM vat_id_cache WHERE vat_id = 'ATU36363636'")->execute();
    $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES ('ATU36363636', 'AT', 'valid', 'R36K GmbH', :at, 86400)")
        ->execute([':at' => gmdate('Y-m-d H:i:s')]);
    $_SESSION['cart'] = ['p_99362' => ['product_id' => 99362, 'variant_id' => null, 'qty' => 1]];
    $r36kScenario = static fn(string $country, string $vatId, string $method): array => array_values(array_intersect_key(
        CheckoutService::vatContext($country, $vatId, $method, false, $vatId !== '' ? 'valid' : null)['scenario'], ['scenario' => 1, 'rate_country' => 1]));
    $r36kOffered = static fn(string $country): array => array_map(static fn(array $r): string => (string)($r['method_code'] ?? ''),
        ShippingService::calculateRates($country, 0, 12300, false, 0, 1, 'eur'));

    assert_true(!in_array('pickup', $r36kOffered('AT'), true) && in_array('pickup', $r36kOffered('SK'), true),
        'R36 pickup: in-store pickup is offered in Slovakia, not to an Austrian address (got AT: ' . implode(',', $r36kOffered('AT')) . ')');
    assert_equals(VatService::SCENARIO_REVERSE_CHARGE, $r36kScenario('AT', 'ATU36363636', 'pickup')[0],
        'R36 pickup: a business with a valid ATU still naming pickup after switching to Austria is priced under reverse charge, as the courier order (was domestic SK)');
    assert_equals([VatService::SCENARIO_OSS, 'AT'], $r36kScenario('AT', '', 'pickup'),
        'R36 pickup: an Austrian consumer still naming pickup is priced as the parcel to Austria (OSS AT)');
    assert_equals([VatService::SCENARIO_DOMESTIC, 'SK'], $r36kScenario('SK', '', 'pickup'), 'R36 pickup: pickup in Slovakia stays domestic');

    // Where the merchant offers pickup to an Austrian address, the goods collected in the shop keep Slovak VAT (R34).
    $pdo->exec("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order)
        VALUES ('default', 'pickup', 'pickup', 'AT', 'near_eu', 0, 999999, 0, 0, '0', 9)");
    assert_true(in_array('pickup', $r36kOffered('AT'), true), 'R36 pickup: a pickup rate for Austria offers pickup there');
    assert_equals([VatService::SCENARIO_DOMESTIC, 'SK'], $r36kScenario('AT', '', 'pickup'),
        'R36 pickup: pickup offered to an Austrian address is still supplied in the shop (Slovak VAT)');
} catch (Throwable $e) {
    assert_true(false, 'R36 pickup (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $pdo->exec("DELETE FROM shipping_rates WHERE store_id = 'default' AND method_code = 'pickup' AND country = 'AT'");
    $pdo->exec('DELETE FROM products WHERE id = 99362');
    $pdo->prepare("DELETE FROM vat_id_cache WHERE vat_id = 'ATU36363636'")->execute();
    $r36kStoresProp->setValue(null, $r36kStores);
    Bootstrap::initStoreContext('/', '', 'default');
    VatService::reset();
    if ($r36kCart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r36kCart;
}

// Through the real storefront handler (no JS): the buyer switches the SK form to Austria and recalculates, then orders.
$r36kRoot = test_fixture_path('checkout-pickup-r36');
mkdir($r36kRoot);
mkdir($r36kRoot . '/sessions');
$r36kFixture = $r36kRoot . '/request.php';
file_put_contents($r36kFixture, <<<'PHP'
<?php
[, $driver, $phase, $action, $root, $target, $dir, $extra] = $argv;
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
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'r36kcat', 'Cat', 'r36kcat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'r36kcat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R36K-SKU', 'r36k-product', 'R36K Product', 1500, 50, 'active', 500]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'R36K-SKU']);
    $db->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES ('ATU36363636', 'AT', 'valid', 'R36K GmbH', ?, 86400)")
        ->execute([gmdate('Y-m-d H:i:s')]);
    // A fresh install has no payment for Austria (no IBAN for a transfer, COD in Slovakia only): an offline one without a fee.
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r36k_pay', 'manual', 'R36K Platba', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    $db->exec("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r36k_pay', 'AT')");
    exit(0);
}

$config = is_file($root . '/config.php') ? require AppConfig::path($root) : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate-' . $driver . '.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.36.4';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R36K-SKU'")->fetchColumn(), null, 1);
$orders = static fn(): array => $db->query("SELECT id, total, vat_scenario, shipping_method FROM orders ORDER BY id")->fetchAll();
$typed = (json_decode($extra, true) ?: []) + ['name' => 'R36K Buyer', 'email' => 'r36k-' . $action . '@example.test', 'phone' => '+421900000036', 'street' => 'Hlavná 1',
    'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'csrf_token' => Security::csrfToken()];
[$method, $post] = $action === 'get' ? ['GET', []] : ['POST', ['checkout' => '1'] + $typed];
$_SERVER['REQUEST_METHOD'] = $method;
$_GET = ['page' => 'checkout'];
$_POST = $post;
http_response_code(200);
$before = count($orders());
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR36KRESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$r36kRun = static function(array $args) use ($r36kFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r36kFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R36KRESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$r36kDrivers = ['sqlite' => $r36kRoot . '/checkout.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r36kDrivers['mysql'] = 'bbe_r36k_checkout_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit R36 native checkout pickup (set TEST_MYSQL_DSN)\n";
$r36kApp = dirname(TEST_CLI_DIR);
try {
    foreach ($r36kDrivers as $r36kDriver => $r36kTarget) {
        [$r36kExit, , $r36kNoise] = $r36kRun([$r36kDriver, 'setup', 'none', $r36kApp, $r36kTarget, $r36kRoot, '{}']);
        assert_equals([0, ''], [$r36kExit, trim($r36kNoise)], "R36 checkout {$r36kDriver}: migrated fixture with a product and a valid ATU");
        $r36kDo = static function(string $action, array $extra = []) use ($r36kRun, $r36kDriver, $r36kApp, $r36kTarget, $r36kRoot): array {
            [$exit, $result, $noise] = $r36kRun([$r36kDriver, 'run', $action, $r36kApp, $r36kTarget, $r36kRoot, json_encode($extra)]);
            $label = "R36 checkout {$r36kDriver}/{$action}";
            assert_true($exit === 0 && $result !== null && !str_contains((string)$result['output'], 'Fatal error') && !str_contains((string)$result['output'], 'Uncaught'),
                "{$label}: real handler completes (exit {$exit} " . trim($noise) . ')');
            $html = (string)($result['output'] ?? '');
            $form = preg_match('~<form method="post" class="checkout-form".*?</form>~s', $html, $m) ? $m[0] : '';
            return [$label, $result ?? ['new_orders' => [], 'output' => ''], $form, $html];
        };
        $r36kQuoted = static fn(string $form): ?int => preg_match('~<noscript><input type="hidden" name="quoted_total" value="(\d+)"></noscript>~', $form, $m) ? (int)$m[1] : null;
        $r36kError = static fn(string $form): string => preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', $form, $m) ? html_entity_decode($m[1]) : '';
        $r36kMethods = static fn(string $form): array => preg_match_all('~name="shipping_method" value="([^"]+)"~', $form, $m) ? $m[1] : [];
        $r36kChecked = static fn(string $form): string => preg_match('~name="shipping_method" value="([^"]+)"[^>]*checked~', $form, $m) ? $m[1] : '';
        $r36kTotal = static fn(string $html): string => preg_match('~<span id="summary-total">([^<]+)</span>~', $html, $m) ? html_entity_decode($m[1]) : '';

        [$l, $r, $form] = $r36kDo('get');
        $r36kPickup = (string)(array_values(array_filter($r36kMethods($form), static fn(string $c): bool => str_contains($c, 'pickup')))[0] ?? '');
        $r36kConsents = preg_match_all('~name="consents\[([a-z_]+)\]" value="1"~', $form, $r36kC) ? array_fill_keys($r36kC[1], '1') : [];
        $r36kSkShown = $r36kQuoted($form);
        assert_true($r36kPickup !== '' && $r36kSkShown !== null, "{$l}: the SK form offers in-store pickup ({$r36kPickup})");

        // Switched to Austria with the ATU, still naming pickup: "Recalculate" shows the courier and its reverse-charge total.
        $r36kB2b = ['country' => 'AT', 'city' => 'Wien', 'zip' => '1010', 'company' => 'R36K GmbH', 'vat_id' => 'ATU36363636', 'payment' => 'r36k_pay', 'consents' => $r36kConsents];
        [$l, $r, $form, $html] = $r36kDo('refresh', $r36kB2b + ['shipping_method' => $r36kPickup, 'checkout_refresh' => '1']);
        $r36kCourier = $r36kChecked($form);
        $r36kShown = $r36kQuoted($form);
        assert_true($r['new_orders'] === [] && !in_array($r36kPickup, $r36kMethods($form), true) && $r36kCourier !== '' && $r36kShown !== null,
            "{$l}: recalculated for Austria, the form offers no pickup and selects a courier ({$r36kCourier}, " . $r36kTotal($html) . ')');

        // Ordered as shown: no "total changed", the order charges exactly that total under reverse charge.
        $r36kPayments = preg_match_all('~name="payment" value="([^"]+)"~', $form, $m) ? $m[1] : [];
        $r36kPayment = preg_match('~name="payment" value="([^"]+)"[^>]*checked~', $form, $m) ? $m[1]
            : (string)(array_values(array_diff($r36kPayments, ['cod']))[0] ?? '');
        assert_true($r36kPayment !== '', "{$l}: the Austrian form offers a payment (" . implode(',', $r36kPayments) . ')');
        [$l, $r, $form] = $r36kDo('order', ['payment' => $r36kPayment] + $r36kB2b + ['shipping_method' => $r36kCourier, 'quoted_total' => (string)$r36kShown]);
        $r36kOrder = $r['new_orders'][0] ?? [];
        assert_true(count($r['new_orders']) === 1 && (int)($r36kOrder['total'] ?? -1) === $r36kShown && ($r36kOrder['vat_scenario'] ?? '') === VatService::SCENARIO_REVERSE_CHARGE,
            "{$l}: the business orders at the total shown ({$r36kShown}) under reverse charge, without \"total changed\" (got " . json_encode($r36kOrder) . ' ' . $r36kError($form) . ')');

        // Low: cash on delivery refused for in-store pickup names the total the form now shows.
        [$l, $r, $form, $html] = $r36kDo('codpickup', ['shipping_method' => $r36kPickup, 'payment' => 'cod', 'consents' => $r36kConsents, 'quoted_total' => (string)$r36kSkShown]);
        $r36kNow = $r36kTotal($html);
        assert_true($r['new_orders'] === [] && $r36kNow !== '' && str_contains($r36kError($form), 'Dobierka') && str_contains($r36kError($form), $r36kNow),
            "{$l}: cash on delivery refused with in-store pickup names the new total ({$r36kNow}: " . $r36kError($form) . ')');
    }
} finally {
    if (isset($r36kDrivers['mysql'])) $r36kRun(['mysql', 'teardown', 'none', $r36kApp, $r36kDrivers['mysql'], $r36kRoot, '{}']);
}
