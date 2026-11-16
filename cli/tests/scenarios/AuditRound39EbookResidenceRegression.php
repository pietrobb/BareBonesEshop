<?php

// Review round 13 (R39, low): R38 taxed an e-book next to goods in the delivery country — a rule, not evidence of where
// the buyer lives (art. 24b, 24f Reg. 282/2011). A gift billed in Luxembourg and sent to Slovakia paid Slovak VAT; an
// Austrian collecting the goods in the store paid Slovak VAT too, and it was not counted toward the OSS threshold; a
// digital-only order went by the billing country alone; a store's own "pick-up in store 2" method of another carrier
// was taxed as a parcel; the buyer was not told why VAT was charged. Now the evidence decides (billing address, goods
// sent to an address, the IP country when the store's proxy gives it) and a contradiction is settled by the buyer's
// declaration, which checkout and the manual order ask for (CheckoutService::digitalResidence()).
echo "\n--- R39 e-book country by evidence: gift, store pickup abroad, IP country, own store-pickup method, VAT reason ---\n";
$r39eRoot = test_fixture_path('ebook-residence-r39');
mkdir($r39eRoot);
mkdir($r39eRoot . '/sessions');
$r39eFixture = $r39eRoot . '/request.php';
file_put_contents($r39eFixture, <<<'PHP'
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
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r39e', 'R39E', 'r39e', 0)");
    $cat = (int)$db->lastInsertId();
    $insert = $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, product_type, price, stock, status, weight_g, inventory_policy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute(['default', $cat, 'R39E-MUG', 'r39e-mug', 'R39E Mug', 'simple', 12300, 100, 'active', 500, 'deny']);
    $insert->execute(['default', $cat, 'R39E-BOOK', 'r39e-book', 'R39E E-book', 'downloadable', 1230, 0, 'active', 0, 'continue']);
    $db->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
        VALUES ('R39E-BOOK', 'pdf', 'PDF', 'r39e.pdf', 'r39e.pdf', 'application/pdf', 2, 3600, 1)");
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 100, 0)')->execute([$warehouse, 'R39E-MUG']);
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r39e_pay', 'manual', 'R39E payment', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    foreach (['SK', 'LU', 'AT', 'DE'] as $country) $db->prepare("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r39e_pay', ?)")->execute([$country]);
    $db->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('default', 'r39e-post', 'r39e', 'R39E post', 'parcel', 0, 0, 1)");
    // The store's own second pick-up desk, booked under a courier's carrier code.
    $db->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('default', 'r39e-store2', 'r39e', 'R39E pick-up in store 2', 'store_pickup', 0, 0, 2)");
    $rate = $db->prepare("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order) VALUES ('default', ?, 'r39e', ?, ?, 0, 999999, 0, 0, '2', 1)");
    $rate->execute(['r39e-post', 'SK', 'domestic']);
    $rate->execute(['r39e-store2', 'SK', 'domestic']);
    $rate->execute(['r39e-store2', 'AT', 'near_eu']);
    exit(0);
}
$config = require AppConfig::path($root);
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.39.5';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
$stores = Bootstrap::stores();
$stores['stores']['default']['oss_enabled'] = !empty($args['oss']);
$stores['stores']['default']['legal']['country'] = 'SK';
if (($args['ip'] ?? '') !== '') {
    $stores['stores']['default']['vat_ip_country_header'] = 'CF-IPCountry';
    $_SERVER['HTTP_CF_IPCOUNTRY'] = $args['ip'];
}
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$product = static fn(string $sku): int => (int)$db->query("SELECT id FROM products WHERE sku = '{$sku}'")->fetchColumn();
foreach ((array)($args['skus'] ?? ['R39E-MUG', 'R39E-BOOK']) as $sku) Cart::add($product($sku), null, 1);
unset($args['oss'], $args['ip'], $args['skus']);
$typed = $args + ['name' => 'R39E Kupujúci', 'email' => 'r39e@example.test', 'phone' => '+421900000039', 'street' => 'Hlavná 1',
    'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'payment' => 'r39e_pay', 'payment_method' => 'r39e_pay', 'csrf_token' => Security::csrfToken()];
$orders = static fn(): array => $db->query('SELECT id, total, vat_scenario, oss_net, financial_snapshot FROM orders ORDER BY id')->fetchAll();
$before = count($orders());
// Each new order's lines by SKU: [rate in basis points, rate country, supply]; and the residence evidence recorded.
$lines = static function(array $orders): array {
    $out = [];
    foreach ($orders as $order) {
        $snapshot = json_decode((string)$order['financial_snapshot'], true) ?: [];
        $items = [];
        foreach ((array)($snapshot['components'] ?? []) as $c) {
            if (($c['type'] ?? '') !== 'product') continue;
            $sku = (string)($c['sku'] ?? ((int)($c['net'] ?? 0) < 5000 ? 'R39E-BOOK' : 'R39E-MUG'));
            $items[$sku] = [(int)($c['rate_basis_points'] ?? -1), $c['rate_country'] ?? null, $c['supply'] ?? null];
        }
        $out[] = ['id' => (int)$order['id'], 'total' => (int)$order['total'], 'scenario' => $order['vat_scenario'], 'oss_net' => $order['oss_net'] === null ? null : (int)$order['oss_net'],
            'items' => $items, 'residence' => $snapshot['digital_residence'] ?? null];
    }
    return $out;
};
if ($phase === 'manual') {
    $result = ManualOrder::create(ManualOrder::parseLines("R39E-MUG 1\nR39E-BOOK 1")['lines'], $typed, false);
    echo 'R39ERESULT' . json_encode(['result' => $result, 'new_orders' => $lines(array_slice($orders(), $before))]);
    exit(0);
}
if ($phase === 'edit') {
    $result = ManualOrder::editItems((int)$args['order_id'], ManualOrder::parseLines("R39E-MUG 2\nR39E-BOOK 1")['lines']);
    $edited = array_values(array_filter($orders(), static fn(array $o): bool => (int)$o['id'] === (int)$args['order_id']));
    echo 'R39ERESULT' . json_encode(['result' => $result, 'order' => $lines($edited)[0] ?? null]);
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
    echo "\nR39ERESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => $lines(array_slice($orders(), $before))], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/index.php';
PHP);
$r39eRun = static function(array $args) use ($r39eFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r39eFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R39ERESULT');
    return [$exit, $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true), substr($raw, 0, min($at === false ? 600 : $at, 600))];
};
$r39eApp = dirname(TEST_CLI_DIR);
$r39eTargets = [];
$r39eRate = static fn(string $country): int => (int)round(VatService::rate($country, 'standard') * 100);
$r39eGift = ['shipping_method' => 'r39e-post', 'billing_different' => '1', 'billing_name' => 'R39E Kupujúci', 'billing_country' => 'LU',
    'billing_street' => 'Rue 1', 'billing_city' => 'Luxembourg', 'billing_zip' => '1111'];
$r39eAustrian = ['shipping_method' => 'r39e-store2', 'name' => 'R39E Kunde', 'street' => 'Ring 1', 'city' => 'Wien', 'zip' => '1010', 'country' => 'AT'];
try {
    foreach (['sqlite', 'mysql'] as $r39eDriver) {
        if ($r39eDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R39 e-book residence native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
        $r39eTarget = $r39eDriver === 'mysql' ? 'bbe_r39e_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $r39eRoot . '/shop.sqlite';
        $r39eTargets[] = [$r39eDriver, $r39eTarget];
        [$exit, , $noise] = $r39eRun([$r39eDriver, 'setup', $r39eApp, $r39eTarget, $r39eRoot, '{}']);
        assert_equals([0, ''], [$exit, trim($noise)], "R39 e-book residence {$r39eDriver}: isolated migrated fixture");
        $do = static function(string $phase, array $input) use ($r39eRun, $r39eDriver, $r39eApp, $r39eTarget, $r39eRoot): array {
            [$exit, $result, $noise] = $r39eRun([$r39eDriver, $phase, $r39eApp, $r39eTarget, $r39eRoot, json_encode($input)]);
            assert_true($exit === 0 && is_array($result), "R39 e-book residence {$r39eDriver}/{$phase}: handler completes ({$noise})");
            return $result ?? [];
        };
        // Shows the page, then orders at the total it quotes. Returns [page html, order result].
        $checkout = static function(array $input) use ($do): array {
            $html = (string)($do('refresh', $input + ['checkout_refresh' => '1'])['output'] ?? '');
            $quoted = preg_match('~name="quoted_total" value="(\d+)"~', $html, $m) ? (int)$m[1] : null;
            $consents = preg_match_all('~name="consents\[([a-z_]+)\]" value="1"~', $html, $m) ? array_fill_keys($m[1], '1') : [];
            return [$html, $do('order', $input + ['quoted_total' => (string)$quoted, 'consents' => $consents, 'checkout' => '1'])];
        };
        $options = static fn(string $html): array => preg_match('~<select name="residence_country"[^>]*>(.*?)</select>~s', $html, $m) && preg_match_all('~<option value="([A-Z]{2})"~', $m[1], $o) ? $o[1] : [];

        // 1. The gift: billed in Luxembourg, sent to Slovakia.
        $label = "R39 e-book residence {$r39eDriver}/gift billed LU, sent SK";
        [$html, $ordered] = $checkout($r39eGift + ['oss' => true, 'residence_country' => '']);
        assert_true($options($html) === ['LU', 'SK'], "{$label}: checkout asks where the buyer lives, LU or SK (got " . json_encode($options($html)) . ')');
        assert_true(($ordered['new_orders'] ?? null) === [] && str_contains((string)($ordered['output'] ?? ''), 'id="checkout-residence"'),
            "{$label}: not declared — no order, the form asks again (got " . json_encode($ordered['new_orders'] ?? null) . ')');
        [$html, $ordered] = $checkout($r39eGift + ['oss' => true, 'residence_country' => 'LU']);
        $order = $ordered['new_orders'][0] ?? [];
        assert_true(($order['items']['R39E-BOOK'][0] ?? -1) === $r39eRate('LU') && ($order['items']['R39E-BOOK'][2] ?? '') === 'oss'
            && ($order['items']['R39E-MUG'][0] ?? -1) === $r39eRate('SK') && ($order['residence']['declared'] ?? '') === 'LU',
            "{$label}: a Luxembourg resident pays LU VAT on the e-book (OSS), SK on the mug, evidence recorded (got " . json_encode($order) . ')');
        assert_true(preg_match('~<p class="vat-reason"[^>]*>([^<]+)</p>~', $html, $m) === 1 && trim($m[1]) !== '',
            "{$label}: the summary says why VAT is charged as it is");
        $manual = $do('manual', $r39eGift + ['oss' => true]);
        assert_true(($manual['result']['error'] ?? '') === 'checkout.error.residence_required' && ($manual['new_orders'] ?? null) === [],
            "{$label}: a manual order asks the admin too (got " . json_encode($manual['result'] ?? null) . ')');
        $manual = $do('manual', $r39eGift + ['oss' => true, 'residence_country' => 'LU']);
        assert_true(($manual['result']['ok'] ?? false) && (($manual['new_orders'][0]['items']['R39E-BOOK'][0] ?? -1) === $r39eRate('LU')),
            "{$label}: a manual order declared LU taxes the e-book in LU (got " . json_encode($manual) . ')');
        $edit = $do('edit', ['oss' => true, 'order_id' => (int)($manual['new_orders'][0]['id'] ?? 0)]);
        assert_true(($edit['result']['ok'] ?? false) && ($edit['order']['items']['R39E-BOOK'][0] ?? -1) === $r39eRate('LU')
            && ($edit['order']['items']['R39E-MUG'][0] ?? -1) === $r39eRate('SK'),
            "{$label}: editing its lines keeps the declared residence, the e-book stays in LU (was: residence asked again; got " . json_encode($edit) . ')');

        // 2. An Austrian collecting in the store's own second pick-up desk (a method of another carrier code).
        foreach ([true, false] as $oss) {
            $label = "R39 e-book residence {$r39eDriver}/Austrian at store pick-up 2, OSS " . ($oss ? 'on' : 'off');
            [$html, $ordered] = $checkout($r39eAustrian + ['oss' => $oss, 'residence_country' => '']);
            $order = $ordered['new_orders'][0] ?? [];
            $error = preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', (string)($ordered['output'] ?? ''), $m) ? html_entity_decode($m[1]) : '';
            assert_true(($order['items']['R39E-MUG'][0] ?? -1) === $r39eRate('SK'), "{$label}: the mug collected in Bratislava pays SK VAT (got " . json_encode($order) . " {$error})");
            if ($oss) {
                assert_true(($order['items']['R39E-BOOK'][0] ?? -1) === $r39eRate('AT') && ($order['items']['R39E-BOOK'][2] ?? '') === 'oss',
                    "{$label}: the e-book is supplied where the Austrian lives (AT OSS; got " . json_encode($order['items'] ?? null) . ')');
            } else {
                assert_true(($order['items']['R39E-BOOK'][0] ?? -1) === $r39eRate('SK') && ($order['oss_net'] ?? -1) === 1000,
                    "{$label}: under the threshold the e-book pays SK VAT and its 10.00 net counts toward it, the collected mug does not (got " . json_encode($order) . ')');
            }
        }

        // 3. Digital-only: the IP country is a second item of evidence when the store's proxy gives it.
        $label = "R39 e-book residence {$r39eDriver}/digital-only billed LU from a German IP";
        $book = ['oss' => true, 'skus' => ['R39E-BOOK'], 'country' => 'LU', 'street' => 'Rue 1', 'city' => 'Luxembourg', 'zip' => '1111', 'ip' => 'DE'];
        [$html, $ordered] = $checkout($book + ['residence_country' => '']);
        assert_true($options($html) === ['LU', 'DE'] && ($ordered['new_orders'] ?? null) === [],
            "{$label}: the contradiction is asked about, no order without an answer (got " . json_encode([$options($html), $ordered['new_orders'] ?? null]) . ')');
        [, $ordered] = $checkout($book + ['residence_country' => 'DE']);
        $order = $ordered['new_orders'][0] ?? [];
        assert_true(($order['items']['R39E-BOOK'][0] ?? -1) === $r39eRate('DE') && ($order['residence'] ?? null) === ['billing' => 'LU', 'ip' => 'DE', 'declared' => 'DE'],
            "{$label}: declared DE, taxed in DE with the evidence recorded (got " . json_encode($order) . ')');
        [$html, $ordered] = $checkout(['ip' => 'LU'] + $book);
        assert_true($options($html) === [] && (($ordered['new_orders'][0]['items']['R39E-BOOK'][0] ?? -1) === $r39eRate('LU')),
            "{$label}: control — a Luxembourg IP agrees, nothing is asked");
    }
} finally {
    foreach ($r39eTargets as [$r39eDriver, $r39eTarget]) if ($r39eDriver === 'mysql') $r39eRun(['mysql', 'teardown', $r39eApp, $r39eTarget, $r39eRoot, '{}']);
}
