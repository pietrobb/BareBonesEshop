<?php

// Operator decision (R37 #467, HU27): the store chooses whether a sale taxed at another member state's VAT keeps the
// catalog price's net (default "net": 123 € at SK 23 % → 127 € at HU 27 %) or its gross ("gross": 123 €, lower margin).
// With OSS off, the sale that passes the threshold at the seller's VAT activates OSS, whatever net the foreign rate leaves.
echo "\n--- R37 cross-border pricing: keep net (default) or gross; the threshold decision activates OSS ---\n";
$r37pRoot = test_fixture_path('oss-pricing-r37');
mkdir($r37pRoot);
mkdir($r37pRoot . '/sessions');
$r37pFixture = $r37pRoot . '/request.php';
file_put_contents($r37pFixture, <<<'PHP'
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
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r37p', 'R37P', 'r37p', 0)");
    $cat = (int)$db->lastInsertId();
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    foreach ([['R37P-MUG', 12300], ['R37P-PEN', 1250]] as [$sku, $price]) {
        $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['default', $cat, $sku, strtolower($sku), $sku, $price, 100, 'active', 500]);
        $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 100, 0)')->execute([$warehouse, $sku]);
    }
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r37p-pay', 'manual', 'R37P payment', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    $db->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('default', 'r37p-post', 'r37p', 'R37P post', 'parcel', 0, 0, 1)");
    $db->exec("INSERT INTO shipping_zones (store_id, code, name, active, sort_order) VALUES ('default', 'r37p-zone', 'R37P zone', 1, 1)");
    foreach (['SK', 'AT', 'HU'] as $country) {
        $db->prepare("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r37p-pay', ?)")->execute([$country]);
        $db->prepare("INSERT INTO shipping_zone_countries (store_id, zone_code, country) VALUES ('default', 'r37p-zone', ?)")->execute([$country]);
        $db->prepare("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order)
            VALUES ('default', 'r37p-post', 'r37p', ?, 'r37p-zone', 0, 999999, 0, 0, '2', 1)")->execute([$country]);
    }
    exit(0);
}
$config = require AppConfig::path($root);
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.37.2';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
$stores = Bootstrap::stores();
$stores['stores']['default']['oss_enabled'] = (bool)$args['oss'];
$stores['stores']['default']['legal']['country'] = 'SK';
if ($args['pricing'] !== null) $stores['stores']['default']['cross_border_pricing'] = $args['pricing'];
else unset($stores['stores']['default']['cross_border_pricing']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$orders = static fn(): array => $db->query('SELECT id, total, net_total, vat_scenario, oss_net FROM orders ORDER BY id')->fetchAll();
if ($phase === 'state') {
    $store = $stores['stores']['default'];
    $state = ['sum' => OssThreshold::worstYear($db, 'default', $store, $stores['stores'])['sum'], 'next_sale_abroad' => OssThreshold::exceeded()];
    $db->exec('DELETE FROM oss_threshold_activations');
    OssThreshold::reset();
    $state['after_loss'] = OssThreshold::exceeded();
    OssThreshold::rebuildActivations($db);
    $state['after_rebuild'] = OssThreshold::exceeded();
    $state['doctor'] = Setup::ossThresholdCheck($db, 'default', $store, $stores['stores'])['message'];
    echo 'R37PRESULT' . json_encode($state);
    exit(0);
}
if (isset($args['history'])) {
    $db->exec('DELETE FROM oss_threshold_totals');
    $db->prepare("INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES ('default', ?, 'eur', ?)")->execute([(int)ShopTime::date('Y'), (int)$args['history']]);
}
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = " . $db->quote($args['sku']))->fetchColumn(), null, 1);
if ($phase === 'api') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $args['post'];
    require_once $root . '/src/Http/ApiHandlers.php';
    echo 'R37PRESULT' . json_encode(($_GET['action'] ?? '') === 'shipping_rates' ? apiShippingRates() : apiPaymentMethods());
    exit(0);
}
$before = count($orders());
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['page' => 'checkout'];
// Exactly what the page/browser sent; only the token is this process's own (fresh session).
$_POST = ['csrf_token' => Security::csrfToken()] + array_diff_key($args['post'], ['csrf_token' => true]);
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR37PRESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/index.php';
PHP);
$r37pRun = static function(array $args) use ($r37pFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r37pFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R37PRESULT');
    return [$exit, $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true), substr($raw, 0, min($at === false ? 600 : $at, 600))];
};
$r37pApp = dirname(TEST_CLI_DIR);
// The no-JS buyer: submit the rendered checkout form as a browser would (values, checked radios/boxes, selected
// options, the pressed submit), typing only into required empty text fields; consents ticked.
$r37pFormPost = static function(string $html): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $form = (new DOMXPath($dom))->query('//form[contains(concat(" ", @class, " "), " checkout-form ")]')->item(0);
    if (!$form instanceof DOMElement) return [];
    $typed = ['email' => 'r37p@example.test', 'tel' => '+36300000037', 'zip' => '1011', 'city' => 'Budapest'];
    $pairs = [];
    foreach ((new DOMXPath($dom))->query('.//input|.//select|.//textarea|.//button', $form) as $el) {
        $name = $el->getAttribute('name');
        if ($name === '' || $el->hasAttribute('disabled')) continue;
        $type = strtolower($el->getAttribute('type') ?: ($el->nodeName === 'button' ? 'submit' : 'text'));
        if ($el->nodeName === 'select') {
            $options = $el->getElementsByTagName('option');
            $chosen = null;
            foreach ($options as $o) if ($o->hasAttribute('selected')) $chosen = $o;
            $chosen ??= $options->item(0);
            if ($chosen) $pairs[] = [$name, $chosen->hasAttribute('value') ? $chosen->getAttribute('value') : trim($chosen->textContent)];
        } elseif ($type === 'submit') {
            continue;
        } elseif ($type === 'radio') {
            if ($el->hasAttribute('checked')) $pairs[] = [$name, $el->getAttribute('value')];
        } elseif ($type === 'checkbox') {
            if ($el->hasAttribute('checked') || $el->hasAttribute('required') || str_starts_with($name, 'consents[')) $pairs[] = [$name, $el->getAttribute('value') ?: 'on'];
        } else {
            $value = $el->nodeName === 'textarea' ? $el->textContent : $el->getAttribute('value');
            if ($value === '' && $el->hasAttribute('required') && $type !== 'hidden') $value = $typed[$type] ?? $typed[$name] ?? 'R37P';
            $pairs[] = [$name, $value];
        }
    }
    // The place-order button is unnamed (checkout=1 is hidden), so no submitter pair; named submits are other actions.
    parse_str(implode('&', array_map(static fn(array $p): string => rawurlencode($p[0]) . '=' . rawurlencode($p[1]), $pairs)), $post);
    return $post;
};
// [label, OSS on, cross_border_pricing (null = not configured), country, SKU, threshold history, expected total, net, OSS activated by the order]
$r37pCases = [
    ['default keeps net HU', true, null, 'HU', 'R37P-MUG', null, 12700, 10000, null],
    ['net HU', true, 'net', 'HU', 'R37P-MUG', null, 12700, 10000, null],
    ['net AT', true, 'net', 'AT', 'R37P-MUG', null, 12000, 10000, null],
    ['gross HU', true, 'gross', 'HU', 'R37P-MUG', null, 12300, 9685, null],
    ['threshold gross HU27', false, 'gross', 'HU', 'R37P-PEN', 999000, 1250, 984, 999984],
    ['threshold net HU27', false, 'net', 'HU', 'R37P-PEN', 999000, 1290, 1016, 1000016],
];
$r37pTargets = [];
$r37pBrowser = [];
$r37pBrowserTarget = [];
try {
    foreach (['sqlite', 'mysql'] as $r37pDriver) {
        if ($r37pDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R37 cross-border pricing native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
        $fixtures = [];
        foreach ($r37pCases as $index => [$label, $oss, $pricing, $country, $sku, $history, $total, $net, $sum]) {
            // OSS-on sales do not change each other's price: one fixture; each threshold case gets its own.
            $group = $oss ? 'oss' : "threshold{$index}";
            if (!isset($fixtures[$group])) {
                $fixtures[$group] = $r37pDriver === 'mysql' ? 'bbe_r37p_' . getmypid() . '_' . $index . '_' . bin2hex(random_bytes(3)) . '_test' : $r37pRoot . "/shop-{$r37pDriver}-{$group}.sqlite";
                $r37pTargets[] = [$r37pDriver, $fixtures[$group]];
                [$exit, , $noise] = $r37pRun([$r37pDriver, 'setup', $r37pApp, $fixtures[$group], $r37pRoot, '{}']);
                assert_equals([0, ''], [$exit, trim($noise)], "R37 pricing {$r37pDriver}/{$group}: isolated migrated fixture");
            }
            $target = $fixtures[$group];
            $run = static function(string $phase, array $post) use ($r37pRun, $r37pDriver, $r37pApp, $target, $r37pRoot, $oss, $pricing, $sku, $history, $label): array {
                $args = ['oss' => $oss, 'pricing' => $pricing, 'sku' => $sku, 'post' => $post] + ($history === null ? [] : ['history' => $history]);
                [$exit, $result, $noise] = $r37pRun([$r37pDriver, $phase, $r37pApp, $target, $r37pRoot, json_encode($args)]);
                assert_true($exit === 0 && is_array($result), "R37 pricing {$r37pDriver}/{$label}/{$phase}: handler completes ({$noise})");
                return $result ?? [];
            };
            $page = $run('refresh', ['country' => $country, 'checkout_refresh' => '1']);
            $html = (string)($page['output'] ?? '');
            $quoted = preg_match('~name="quoted_total" value="(\d+)"~', $html, $m) ? (int)$m[1] : null;
            $shownSubtotal = preg_match('~id="checkout-subtotal" value="(\d+)"~', $html, $m) ? (int)$m[1] : null;
            $method = preg_match('~name="shipping_method" value="([^"]+)"[^>]*checked~', $html, $m) ? $m[1] : '';
            assert_equals([$total, $total, 'r37p-post'], [$quoted, $shownSubtotal, $method], "R37 pricing {$r37pDriver}/{$label}: the checkout page shows the policy's price");
            if (in_array($label, ['net AT', 'net HU'], true)) {
                $rates = $run('api', ['action' => 'shipping_rates', 'country' => $country, 'subtotal' => (string)$total]);
                assert_equals(['oss', $total], [$rates['vat_scenario'] ?? null, $rates['goods_subtotal'] ?? null], "R37 pricing {$r37pDriver}/{$label}: the shipping preview prices the goods as the page");
                $r37pBrowserTarget[$r37pDriver] = $target;
                $r37pBrowser[$r37pDriver][$country] = ['page' => $html, 'rates' => $rates,
                    'payments' => $run('api', ['country' => $country, 'currency' => 'eur', 'amount' => (string)$total, 'shipping_method' => 'r37p-post', 'shipping' => '0'])];
            }
            $formPost = $r37pFormPost($html);
            assert_equals([$country, 'r37p-post', 'r37p-pay', (string)$total, '1'], [$formPost['country'] ?? null, $formPost['shipping_method'] ?? null, $formPost['payment'] ?? null, $formPost['quoted_total'] ?? null, $formPost['checkout'] ?? null],
                "R37 pricing {$r37pDriver}/{$label}: the rendered no-JS form itself carries country, delivery, payment and the shown total");
            $ordered = $run('order', $formPost);
            $order = $ordered['new_orders'][0] ?? [];
            assert_true(count($ordered['new_orders'] ?? []) === 1 && ($order['vat_scenario'] ?? '') === 'oss' && (int)($order['total'] ?? -1) === $total && (int)($order['net_total'] ?? -1) === $net,
                "R37 pricing {$r37pDriver}/{$label}: placed at the shown total {$total}, net {$net} (" . (preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', (string)($ordered['output'] ?? ''), $m) ? html_entity_decode($m[1]) : json_encode($order)) . ')');
            if ($sum === null) continue;
            $state = $run('state', []);
            // Without the decision's activation only a tally past the threshold (the net kept) would still say so.
            assert_equals([$sum, true, $sum > OssThreshold::EUR_CENTS, true], [$state['sum'] ?? null, $state['next_sale_abroad'] ?? null, $state['after_loss'] ?? null, $state['after_rebuild'] ?? null],
                "R37 pricing {$r37pDriver}/{$label}: own tally recorded, the crossing decision keeps OSS for the next sale and survives a rebuild");
            assert_true(str_contains((string)($state['doctor'] ?? ''), 'OSS') && str_contains((string)($state['doctor'] ?? ''), number_format($sum / 100, 2, '.', ' ')),
                "R37 pricing {$r37pDriver}/{$label}: doctor reports OSS with the recorded turnover ({$state['doctor']})");
        }
    }
    // The shipped JS on the real handlers' pages: Austria (120 €) → Hungary (127 €) stays OSS but costs another gross;
    // the buyer then places the order and the browser's own POST goes through the real handler on the same fixture.
    foreach ($r37pBrowser as $r37pDriver => $countries) {
        if (!getenv('R37_NODE')) { echo "  [SKIP] R37 pricing Chromium: set R37_NODE and optionally R37_PLAYWRIGHT\n"; break; }
        $payload = json_encode(['source' => file_get_contents($r37pApp . '/themes/default/assets/storefront.js'), 'countries' => $countries, 'playwright' => getenv('R37_PLAYWRIGHT') ?: '@playwright/test']);
        $log = $r37pRoot . '/browser-' . $r37pDriver . '.log';
        $p = proc_open([getenv('R37_NODE'), '-'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
        fwrite($pipes[0], 'const fixture = ' . $payload . ";\n" . file_get_contents($r37pApp . '/tests/e2e/r37-oss-pricing.cjs')); fclose($pipes[0]);
        $exit = proc_close($p);
        $out = (string)file_get_contents($log);
        echo preg_replace('~^R37PPOST.*$~m', '', $out);
        assert_equals(0, $exit, "R37 pricing {$r37pDriver}: Chromium re-prices AT → HU under kept net and submits");
        $browserPost = [];
        if (preg_match('~^R37PPOST(.*)$~m', $out, $m)) parse_str(trim($m[1]), $browserPost);
        unset($browserPost['csrf_token']); // the replay runs in a fresh session; its own token is added
        // R49: the scripted browser posts the total it shows (quoted_total), as the no-JS form does in its <noscript>.
        assert_equals(['HU', 'r37p-post', 'r37p-pay', '12700', '1'], [$browserPost['country'] ?? null, $browserPost['shipping_method'] ?? null, $browserPost['payment'] ?? null, $browserPost['quoted_total'] ?? null, $browserPost['checkout'] ?? null],
            "R37 pricing {$r37pDriver}: the browser's own POST after AT → HU carries country, delivery and payment (keys: " . implode(',', array_keys($browserPost)) . ')');
        [$exit, $placed, $noise] = $r37pRun([$r37pDriver, 'order', $r37pApp, $r37pBrowserTarget[$r37pDriver], $r37pRoot,
            json_encode(['oss' => true, 'pricing' => 'net', 'sku' => 'R37P-MUG', 'post' => $browserPost])]);
        $order = $placed['new_orders'][0] ?? [];
        assert_true($browserPost !== [] && count($placed['new_orders'] ?? []) === 1 && ($order['vat_scenario'] ?? '') === 'oss' && (int)($order['total'] ?? -1) === 12700 && (int)($order['net_total'] ?? -1) === 10000,
            "R37 pricing {$r37pDriver}: the browser's HU submission is placed at 12700, net 10000 (" . (preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', (string)($placed['output'] ?? ''), $m) ? html_entity_decode($m[1]) : json_encode($order) . ' ' . $noise) . ')');
    }
} finally {
    foreach ($r37pTargets as [$r37pDriver, $target]) if ($r37pDriver === 'mysql') $r37pRun(['mysql', 'teardown', $r37pApp, $target, $r37pRoot, '{}']);
}

// Admin → Stores keeps the choice; a form without the field (older page) keeps it too.
$r37pSettings = file_get_contents(dirname(TEST_CLI_DIR) . '/src/StoreSettings.php') . file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/StoreSettings.php');
assert_true(str_contains($r37pSettings, "in_array(\$input['cross_border_pricing'] ?? null, ['net', 'gross'], true)") && str_contains($r37pSettings, 'name="cross_border_pricing"'),
    'R37 pricing: the store form offers and saves cross_border_pricing');
assert_true(Cart::keepsNetAbroad([]) && Cart::keepsNetAbroad(['cross_border_pricing' => 'net']) && !Cart::keepsNetAbroad(['cross_border_pricing' => 'gross']),
    'R37 pricing: an unconfigured store keeps the net abroad');
$r37pJs = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/themes/default/assets/storefront.js');
assert_true(str_contains($r37pJs, 'repriceCheckout(result.vat_scenario, result.goods_subtotal)'),
    'R37 pricing: another OSS rate (another goods subtotal) re-prices the checkout from the shipping preview');
