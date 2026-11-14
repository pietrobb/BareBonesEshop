<?php

// Review round 36, capability review of goal #454 (run 460): an order decides the sale that passes the OSS threshold on
// what its invoice records (CheckoutService::thresholdVat()), but the checkout page and the preview APIs decided on the
// cart lines alone. Through the real storefront handler (no JS), 40 cents short of the threshold once a 9,00 EUR mug is
// added: the page showed Slovak VAT for the mug and its paid parcel to Austria, the order charged Austrian VAT; two mugs
// and a coupon the other way round. The page now shows the scenario the order charges, at the total it charges.
echo "\n--- R36 OSS preview: the checkout page shows the VAT scenario the order passing the threshold is charged ---\n";

$r36pRoot = test_fixture_path('oss-preview-r36');
mkdir($r36pRoot);
mkdir($r36pRoot . '/sessions');
$r36pFixture = $r36pRoot . '/request.php';
file_put_contents($r36pFixture, <<<'PHP'
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
$args = json_decode($extra, true) ?: [];
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'r36pcat', 'Cat', 'r36pcat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'r36pcat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R36P-MUG', 'r36p-mug', 'R36P Mug', 900, 50, 'active', 500]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'R36P-MUG']);
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r36p_pay', 'manual', 'R36P Platba', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    $db->exec("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r36p_pay', 'AT')");
    $db->exec("INSERT INTO promotions (store_id, code, name, scope, coupon_code, priority, stacking, conditions, actions, active)
        VALUES ('default', 'R36PCOUPON', 'R36P coupon', 'cart', 'R36P17', 20, 'stack', '{}', '{\"type\":\"discount_fixed\",\"amount\":1750}', 1)");
    // This year's home-VAT sales to Austria: 40 cents short of the threshold once one mug's net is added.
    $seed = OssThreshold::EUR_CENTS - (int)round(900 / 1.23) - 40;
    $year = gmdate('Y');
    $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, net_total, currency, status, payment_status, payment_method, shipping_method, store_id, vat_scenario, created_at)
        VALUES ('R36P-SEED', 'R36P', 'r36p@example.test', '', ?, ?, 0, 0, 0, ?, ?, 'eur', 'delivered', 'paid', 'bank_transfer', 'gls', 'default', 'domestic_subthreshold', ?)")
        ->execute([json_encode(['country' => 'AT']), $seed, $seed, $seed, $year . '-01-10 10:00:00']);
    $db->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type)
        VALUES (?, 'default', 'R36P-SEED', ?, '', ?, 0, ?, 'eur', 'issued', 'r36p-seed', 'domestic_subthreshold', 'invoice')")
        ->execute([(int)$db->lastInsertId(), $year . '-01-15 10:00:00', $seed, $seed]);
    OssThreshold::record($db, (int)$db->lastInsertId());
    exit(0);
}

$config = is_file($root . '/config.php') ? require AppConfig::path($root) : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate-' . $driver . '.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.36.5';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R36P-MUG'")->fetchColumn(), null, (int)($args['qty'] ?? 1));
unset($args['qty']);
$orders = static fn(): array => $db->query("SELECT id, total, vat_scenario FROM orders WHERE order_number <> 'R36P-SEED' ORDER BY id")->fetchAll();
$typed = $args + ['name' => 'R36P Kundin', 'email' => 'r36p-' . $action . '@example.test', 'phone' => '+43660000036', 'street' => 'Ring 1',
    'city' => 'Wien', 'zip' => '1010', 'country' => 'AT', 'csrf_token' => Security::csrfToken()];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['page' => 'checkout'];
$_POST = $typed;
http_response_code(200);
$before = count($orders());
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR36PRESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$r36pRun = static function(array $args) use ($r36pFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r36pFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R36PRESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$r36pApp = dirname(TEST_CLI_DIR);
$r36pTargets = [];
$r36pCases = [
    // 1. One mug and the paid parcel pass the threshold (the mug alone does not): Austrian VAT.
    'parcel' => [['qty' => 1], VatService::SCENARIO_OSS],
    // 2. Two mugs pass it, after the 17,50 EUR coupon (even with the parcel) they do not: Slovak VAT.
    'coupon' => [['qty' => 2, 'coupon_code' => 'R36P17'], VatService::SCENARIO_DOMESTIC_SUBTHRESH],
];
try {
    foreach (['sqlite', 'mysql'] as $r36pDriver) {
        if ($r36pDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] Audit R36 native OSS preview (set TEST_MYSQL_DSN)\n"; continue; }
        foreach ($r36pCases as $r36pCase => [$r36pArgs, $r36pExpected]) {
            $r36pTarget = $r36pDriver === 'mysql' ? 'bbe_r36p_' . $r36pCase . '_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $r36pRoot . "/{$r36pCase}.sqlite";
            $r36pTargets[] = [$r36pDriver, $r36pTarget];
            [$r36pExit, , $r36pNoise] = $r36pRun([$r36pDriver, 'setup', 'none', $r36pApp, $r36pTarget, $r36pRoot, '{}']);
            assert_equals([0, ''], [$r36pExit, trim($r36pNoise)], "R36 OSS preview {$r36pDriver}/{$r36pCase}: migrated fixture 40 cents short of the threshold");
            $r36pDo = static function(string $action, array $post) use ($r36pRun, $r36pDriver, $r36pCase, $r36pApp, $r36pTarget, $r36pRoot): array {
                [$exit, $result, $noise] = $r36pRun([$r36pDriver, 'run', $action, $r36pApp, $r36pTarget, $r36pRoot, json_encode($post)]);
                $label = "R36 OSS preview {$r36pDriver}/{$r36pCase}/{$action}";
                assert_true($exit === 0 && $result !== null && !str_contains((string)$result['output'], 'Fatal error') && !str_contains((string)$result['output'], 'Uncaught'),
                    "{$label}: real handler completes (exit {$exit} " . trim($noise) . ')');
                $html = (string)($result['output'] ?? '');
                return [$label, $result ?? ['new_orders' => [], 'output' => ''], $html];
            };
            $r36pQuoted = static fn(string $html): ?int => preg_match('~<noscript><input type="hidden" name="quoted_total" value="(\d+)"></noscript>~', $html, $m) ? (int)$m[1] : null;
            $r36pScenario = static fn(string $html): string => preg_match('~data-vat-scenario="([a-z_]*)"~', $html, $m) ? $m[1] : '';
            $r36pChecked = static fn(string $html, string $name): string => preg_match('~name="' . $name . '" value="([^"]+)"[^>]*checked~', $html, $m) ? $m[1] : '';
            $r36pError = static fn(string $html): string => preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', $html, $m) ? html_entity_decode($m[1]) : '';

            // The no-JS buyer recalculates the Austrian form (a paid parcel is selected), then orders at the total shown.
            [$l, $r, $html] = $r36pDo('refresh', $r36pArgs + ['checkout_refresh' => '1', 'payment' => 'r36p_pay']);
            $r36pShipping = $r36pChecked($html, 'shipping_method');
            $r36pConsents = preg_match_all('~name="consents\[([a-z_]+)\]" value="1"~', $html, $m) ? array_fill_keys($m[1], '1') : [];
            [$r36pShown, $r36pShownScenario] = [$r36pQuoted($html), $r36pScenario($html)];
            assert_true($r['new_orders'] === [] && $r36pShipping !== '' && $r36pShown !== null && $r36pShownScenario === $r36pExpected,
                "{$l}: the page shows the scenario the order is charged ({$r36pExpected}; shown {$r36pShownScenario}, {$r36pShipping}, " . var_export($r36pShown, true) . ')');
            $r36pPost = $r36pArgs + ['checkout' => '1', 'shipping_method' => $r36pShipping, 'payment' => $r36pChecked($html, 'payment') ?: 'r36p_pay', 'consents' => $r36pConsents, 'quoted_total' => (string)$r36pShown];
            [$l, $r, $html] = $r36pDo('order', $r36pPost);
            $r36pOrder = $r['new_orders'][0] ?? [];
            assert_true(count($r['new_orders']) === 1 && (int)($r36pOrder['total'] ?? -1) === $r36pShown && ($r36pOrder['vat_scenario'] ?? '') === $r36pShownScenario,
                "{$l}: ordered on the first submit at the total and scenario shown ({$r36pShown}, {$r36pShownScenario}; got " . json_encode($r36pOrder) . ' ' . $r36pError($html) . ')');
        }
    }
} finally {
    foreach ($r36pTargets as [$r36pDriver, $r36pTarget]) {
        if ($r36pDriver === 'mysql') $r36pRun(['mysql', 'teardown', 'none', $r36pApp, $r36pTarget, $r36pRoot, '{}']);
    }
}
