<?php

// Review round 34 (low, checkout), through the real storefront handler (index.php?page=checkout):
// 1. Without JS a buyer who picks another shipping or payment and does not press "Recalculate" was charged a total the
//    form never showed (19,90 € shown, 27,89 € ordered). The no-JS form now posts the total it showed (quoted_total,
//    inside <noscript>); a re-priced order is refused and the form shows the new total for confirmation.
// 2. A re-rendered form (recalculation, billing country of an e-book, refusal) unticked the consents, and the JS reload
//    lost the focus: ticked consents stay ticked; the JS keeps the focused field across the reload.
// 3. A free-shipping promotion: the summary said "free shipping" while the methods listed their carriage (7,99 €).
// SQLite always, MySQL 8 / MariaDB 11.4 via TEST_MYSQL_DSN.
echo "\n--- Audit R34: no-JS total confirmation, kept consents, free shipping on every method ---\n";

$r34cRoot = test_fixture_path('checkout-low');
mkdir($r34cRoot);
mkdir($r34cRoot . '/sessions');
$r34cFixture = $r34cRoot . '/request.php';
file_put_contents($r34cFixture, <<<'PHP'
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
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'r34ccat', 'Cat', 'r34ccat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'r34ccat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R34C-SKU', 'r34c-product', 'R34C Product', 1600, 50, 'active', 500]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'R34C-SKU']);
    // An e-book next to the parcel: its checkout asks for the digital-delivery consent and reprices on the billing country.
    $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, product_type, price, stock, status, inventory_policy) VALUES (?, ?, ?, ?, ?, 'downloadable', ?, 0, 'active', 'continue')")
        ->execute(['default', $cat, 'R34C-EBOOK', 'r34c-ebook', 'R34C E-book', 990]);
    $db->prepare("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
        VALUES ('R34C-EBOOK', 'pdf', 'PDF', 'r34c.pdf', 'r34c.pdf', 'application/pdf', 2, 3600, 1)")->execute();
    exit(0);
}

$config = is_file($root . '/config.php') ? require AppConfig::path($root) : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
$stores = Bootstrap::stores();
$stores['stores']['default']['legal']['iban'] = 'SK31 1200 0000 1987 4263 7541';
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate-' . $driver . '.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.34.3';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
foreach (['R34C-SKU', 'R34C-EBOOK'] as $sku) Cart::add((int)$db->query("SELECT id FROM products WHERE sku = '{$sku}'")->fetchColumn(), null, 1);
if (str_starts_with($action, 'free')) Pricing::setConfig(['cart_rules' => [['type' => 'automatic', 'active' => true, 'name' => 'R34C free shipping', 'action' => 'free_shipping']]]);
$orders = static fn(): array => $db->query("SELECT id, total, shipping_cost, shipping_method FROM orders ORDER BY id")->fetchAll();
// One e-mail per action: the per-buyer checkout rate limit must not decide these cases.
$typed = ['name' => 'R34C Buyer', 'email' => 'r34c-' . str_replace('_', '-', $action) . '@example.test', 'phone' => '+421900000034', 'street' => 'Hlavná 1',
    'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'csrf_token' => Security::csrfToken()] + (json_decode($extra, true) ?: []);
[$method, $post] = match ($action) {
    'get', 'free_get' => ['GET', []],
    'refresh' => ['POST', ['checkout_refresh' => '1'] + $typed],
    'stale', 'confirm', 'free_place' => ['POST', ['checkout' => '1'] + $typed],
};
$_SERVER['REQUEST_METHOD'] = $method;
$_GET = ['page' => 'checkout'];
$_POST = $post;
http_response_code(200);
$before = count($orders());
ob_start();
register_shutdown_function(static function() use ($orders, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR34CRESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$r34cRun = static function(array $args) use ($r34cFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r34cFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R34CRESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$r34cDrivers = ['sqlite' => $r34cRoot . '/checkout.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r34cDrivers['mysql'] = 'bbe_r34c_checkout_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit R34 native checkout low (set TEST_MYSQL_DSN)\n";
$r34cApp = dirname(TEST_CLI_DIR);

// The JS keeps the focused field across a server re-price; the no-JS total is posted only without JS (inside <noscript>).
$r34cJs = (string)file_get_contents($r34cApp . '/themes/default/assets/storefront.js');
$r34cTpl = (string)file_get_contents($r34cApp . '/themes/default/blocks/storefront/checkout.html');
assert_true(substr_count($r34cJs, 'submitCheckoutRefresh(') === 3 && !str_contains($r34cJs, '.requestSubmit(refresh)') && str_contains($r34cJs, 'restoreCheckoutFocus();')
    && str_contains($r34cTpl, '<noscript><input type="hidden" name="quoted_total" value="{{total_cents}}"></noscript>')
    && str_contains($r34cTpl, '{{#if required}} required{{/if}}{{#if checked}} checked{{/if}}>'),
    'R34 checkout: every JS re-price remembers the focused field and restores it; the no-JS form posts its total; consents render their ticked state');
try {
    foreach ($r34cDrivers as $r34cDriver => $r34cTarget) {
        [$r34cExit, , $r34cNoise] = $r34cRun([$r34cDriver, 'setup', 'none', $r34cApp, $r34cTarget, $r34cRoot, '{}']);
        assert_equals([0, ''], [$r34cExit, trim($r34cNoise)], "R34 checkout {$r34cDriver}: migrated fixture with a product");
        $r34cDo = static function(string $action, array $extra = []) use ($r34cRun, $r34cDriver, $r34cApp, $r34cTarget, $r34cRoot): array {
            [$exit, $result, $noise] = $r34cRun([$r34cDriver, 'run', $action, $r34cApp, $r34cTarget, $r34cRoot, json_encode($extra)]);
            $label = "R34 checkout {$r34cDriver}/{$action}";
            assert_true($exit === 0 && $result !== null && !str_contains((string)$result['output'], 'Fatal error') && !str_contains((string)$result['output'], 'Uncaught'),
                "{$label}: real handler completes (exit {$exit} " . trim($noise) . ')');
            $html = (string)($result['output'] ?? '');
            $form = preg_match('~<form method="post" class="checkout-form".*?</form>~s', $html, $m) ? $m[0] : '';
            return [$label, $result ?? ['new_orders' => [], 'output' => ''], $form];
        };
        $r34cQuoted = static fn(string $form): ?int => preg_match('~<noscript><input type="hidden" name="quoted_total" value="(\d+)"></noscript>~', $form, $m) ? (int)$m[1] : null;
        $r34cShipping = static function(string $form): array {
            preg_match_all('~name="shipping_method" value="([^"]+)"(?: checked)? data-price-cents="(\d+)"> [^<]*(?:<small>[^<]*</small>)?</span> <strong>([^<]*)</strong>~', $form, $m, PREG_SET_ORDER);
            return array_combine(array_column($m, 1), array_map(static fn(array $row): array => [(int)$row[2], html_entity_decode($row[3])], $m));
        };
        $r34cChecked = static fn(string $form, string $name): ?string => preg_match('~name="' . $name . '" value="([^"]+)" checked~', $form, $m) ? $m[1] : null;
        $r34cError = static fn(string $form): string => preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', $form, $m) ? html_entity_decode($m[1]) : '';

        [$l, $r, $form] = $r34cDo('get');
        $r34cMethods = array_filter($r34cShipping($form), static fn(string $code): bool => !str_contains($code, 'pickup'), ARRAY_FILTER_USE_KEY);
        preg_match_all('~name="(consents\[[a-z_]+\])" value="1"~', $form, $r34cConsents);
        $r34cDefault = (string)$r34cChecked($form, 'shipping_method');
        $r34cPayment = (string)$r34cChecked($form, 'payment');
        $r34cOther = (string)(array_keys(array_filter($r34cMethods, static fn(array $m, string $code): bool => $code !== $r34cDefault && $m[0] !== ($r34cMethods[$r34cDefault][0] ?? -1), ARRAY_FILTER_USE_BOTH))[0] ?? '');
        $r34cShown = $r34cQuoted($form);
        assert_true($r34cShown !== null && $r34cShown > 0 && $r34cDefault !== '' && $r34cPayment !== '' && $r34cOther !== '' && $r34cConsents[1] !== []
            && preg_match_all('~name="consents\[[a-z_]+\]" value="1"[^>]* checked>~', $form) === 0,
            "{$l}: the no-JS form carries the total it shows ({$r34cShown}); a second, differently priced method ({$r34cOther}) is offered; consents ("
            . implode(', ', $r34cConsents[1]) . ') start unticked');
        assert_true(in_array('consents[digital_delivery]', $r34cConsents[1], true) && str_contains($form, 'data-billing-reprices="1"') === false
            && str_contains((string)$r['output'], 'data-billing-reprices="1"'), "{$l}: the e-book asks for the digital-delivery consent and the JS re-prices on the billing country");
        $r34cTicks = array_fill_keys(array_map(static fn(string $n): string => substr($n, 9, -1), $r34cConsents[1]), '1');

        [$l, $r, $form] = $r34cDo('refresh', ['consents' => $r34cTicks, 'shipping_method' => $r34cOther, 'payment' => $r34cPayment]);
        $r34cNew = $r34cQuoted($form);
        assert_true($r['new_orders'] === [] && preg_match_all('~name="consents\[[a-z_]+\]" value="1"[^>]* checked>~', $form) === count($r34cTicks),
            "{$l}: \"Recalculate\" keeps every ticked consent ticked");
        assert_true($r34cNew !== null && $r34cNew !== $r34cShown && $r34cChecked($form, 'shipping_method') === $r34cOther,
            "{$l}: the recalculated form shows (and posts) the total of the other method ({$r34cShown} -> {$r34cNew})");

        // The buyer saw the first total, picked the other method and ordered without recalculating.
        [$l, $r, $form] = $r34cDo('stale', ['consents' => $r34cTicks, 'shipping_method' => $r34cOther, 'payment' => $r34cPayment, 'quoted_total' => (string)$r34cShown]);
        assert_true($r['new_orders'] === [] && str_contains($r34cError($form), 'prepočítala') && $r34cQuoted($form) === $r34cNew
            && preg_match_all('~name="consents\[[a-z_]+\]" value="1"[^>]* checked>~', $form) === count($r34cTicks),
            "{$l}: an order re-priced since the form was shown is not placed; the form shows the new total to confirm, consents kept (" . $r34cError($form) . ')');

        [$l, $r] = $r34cDo('confirm', ['consents' => $r34cTicks, 'shipping_method' => $r34cOther, 'payment' => $r34cPayment, 'quoted_total' => (string)$r34cNew]);
        assert_true(count($r['new_orders']) === 1 && (int)$r['new_orders'][0]['total'] === $r34cNew && $r['new_orders'][0]['shipping_method'] === $r34cOther,
            "{$l}: confirmed at the total shown, the order is placed at exactly that total ({$r34cNew})");

        // A free-shipping promotion waives the carriage of every method, and the methods say so like the summary.
        [$l, $r, $form] = $r34cDo('free_get');
        $r34cFree = array_filter($r34cShipping($form), static fn(string $code): bool => !str_contains($code, 'pickup'), ARRAY_FILTER_USE_KEY);
        $r34cFreeShown = $r34cQuoted($form);
        assert_true(isset($r34cFree[$r34cOther]) && array_unique(array_column($r34cFree, 0)) === [0] && array_unique(array_column($r34cFree, 1)) === ['Doprava zadarmo']
            && str_contains((string)$r['output'], '<span id="summary-shipping">Doprava zadarmo</span>'),
            "{$l}: with free shipping every delivery method is listed free, like the summary (" . json_encode($r34cFree, JSON_UNESCAPED_UNICODE) . ')');
        [$l, $r] = $r34cDo('free_place', ['consents' => $r34cTicks, 'shipping_method' => $r34cOther, 'payment' => $r34cPayment, 'quoted_total' => (string)$r34cFreeShown]);
        assert_true(count($r['new_orders']) === 1 && (int)$r['new_orders'][0]['shipping_cost'] === 0 && (int)$r['new_orders'][0]['total'] === $r34cFreeShown,
            "{$l}: the other method ordered under free shipping is charged no carriage, at the total shown ({$r34cFreeShown})");
    }
} finally {
    if (isset($r34cDrivers['mysql'])) $r34cRun(['mysql', 'teardown', 'none', $r34cApp, $r34cDrivers['mysql'], $r34cRoot, '{}']);
}
