<?php

// Review round 35 (low, checkout):
// 1. Over the VAT ID preview budget the previews priced an unchecked VAT ID with VAT (27,89 € shown), but the order
//    checked it in VIES and charged reverse charge (22,68 €); without JS the buyer got a "total changed" he did not
//    cause. The session remembers the unchecked VAT ID and the order prices it as the previews did (unverified).
// 2. Without JS, cash on delivery with in-store pickup said delivery to the country is not available: it now says cash
//    on delivery is not available with that delivery method.
// 3. On a phone the new total was far below the "total changed" message: the message names it.
// 4. Every arrow key in the billing country list of an e-book reloaded the page: the JS re-prices once the buyer stops.
echo "\n--- Audit R35: checkout prices an unchecked VAT ID as shown, COD/pickup message, total in the message ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
if (!function_exists('apiShippingRates')) require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';
$r35cCart = $_SESSION['cart'] ?? null;
$r35cGet = $_GET;
try {
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99371, 'default', 1, 'R35C book', 'r35c-book', 'R35C-BOOK', 'simple', 1230, 'eur', 'active', 10, 'continue')");
    $pdo->prepare("DELETE FROM vat_id_cache WHERE vat_id IN ('DE935353535', 'DE936363636')")->execute();
    unset($_SESSION['checkout_vat_unchecked']);
    $_SESSION['cart'] = ['p_99371' => ['product_id' => 99371, 'variant_id' => null, 'qty' => 1]];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $r35cPreview = static function (string $vatId): string {
        $_GET = ['country' => 'DE', 'subtotal' => '1230', 'vat_id' => $vatId];
        return (string)(apiShippingRates()['vat_scenario'] ?? '');
    };

    // The budget used up: the previews price the unknown VAT ID with VAT, and the order prices it the same way.
    for ($r35cI = 0; $r35cI < 20; $r35cI++) Security::rateLimitRecord('checkout_vat_preview');
    $r35cShown = $r35cPreview('DE935353535');
    $r35cStatus = CheckoutService::orderVatIdStatus('DE935353535');
    $r35cOrder = CheckoutService::vatContext('DE', 'DE935353535', 'dpd', true, $r35cStatus);
    assert_true($r35cShown !== '' && $r35cShown !== VatService::SCENARIO_REVERSE_CHARGE && $r35cStatus === 'unverified'
        && $r35cOrder['scenario']['scenario'] === $r35cShown && $r35cOrder['vat_id_status'] === 'unverified' && VatService::cachedVatIdStatus('DE935353535') === null,
        "R35 checkout: a VAT ID the previews could not check is ordered as they priced it ({$r35cShown}), unverified, without asking VIES");
    // Another VAT ID nobody previewed is still checked by the order; a cached answer always wins.
    assert_equals(null, CheckoutService::orderVatIdStatus('DE936363636'), 'R35 checkout: a VAT ID the previews did not price is checked by the order');
    $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES ('DE935353535', 'DE', 'valid', 'R35C GmbH', :at, 86400)")
        ->execute([':at' => gmdate('Y-m-d H:i:s')]);
    assert_equals([null, VatService::SCENARIO_REVERSE_CHARGE, null], [CheckoutService::orderVatIdStatus('DE935353535'), $r35cPreview('DE935353535'),
        $_SESSION['checkout_vat_unchecked']['DE935353535'] ?? null], 'R35 checkout: once VIES answered, preview and order both use the answer (reverse charge)');
    $r35cHandler = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php');
    assert_true(str_contains($r35cHandler, 'CheckoutService::vatContext($country, $vatId, $shippingMethod, true, CheckoutService::orderVatIdStatus($vatId),'),
        'R35 checkout: the order handler prices the VAT ID with the status the previews used');
} catch (Throwable $e) {
    assert_true(false, 'R35 checkout VAT (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $pdo->exec("DELETE FROM products WHERE id = 99371");
    $pdo->prepare("DELETE FROM vat_id_cache WHERE vat_id IN ('DE935353535', 'DE936363636')")->execute();
    unset($_SESSION['checkout_vat_unchecked']);
    $_GET = $r35cGet;
    if ($r35cCart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r35cCart;
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
}

// The billing country list re-prices once the buyer stops moving through it (or leaves it), the toggle at once.
$r35cJs = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/themes/default/assets/storefront.js');
assert_true(str_contains($r35cJs, "billingTimer = setTimeout(reprice, 1000);") && str_contains($r35cJs, "billingCountry.addEventListener('blur', () => { if (billingTimer) reprice(); });")
    && str_contains($r35cJs, "toggle.addEventListener('change', reprice);"), 'R35 checkout: arrow keys in the billing country list do not reload the page on every step');

// Through the real storefront handler (no JS): COD with in-store pickup, and the new total inside the message.
$r35cRoot = test_fixture_path('checkout-low-r35');
mkdir($r35cRoot);
mkdir($r35cRoot . '/sessions');
$r35cFixture = $r35cRoot . '/request.php';
file_put_contents($r35cFixture, <<<'PHP'
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
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'r35ccat', 'Cat', 'r35ccat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'r35ccat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R35C-SKU', 'r35c-product', 'R35C Product', 1600, 50, 'active', 500]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'R35C-SKU']);
    exit(0);
}

$config = is_file($root . '/config.php') ? require AppConfig::path($root) : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate-' . $driver . '.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.35.3';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R35C-SKU'")->fetchColumn(), null, 1);
$orders = static fn(): array => $db->query("SELECT id, total FROM orders ORDER BY id")->fetchAll();
$typed = (json_decode($extra, true) ?: []) + ['name' => 'R35C Buyer', 'email' => 'r35c-' . $action . '@example.test', 'phone' => '+421900000035', 'street' => 'Hlavná 1',
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
    echo "\nR35CRESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($orders(), $before)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$r35cRun = static function(array $args) use ($r35cFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r35cFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R35CRESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$r35cDrivers = ['sqlite' => $r35cRoot . '/checkout.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r35cDrivers['mysql'] = 'bbe_r35c_checkout_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit R35 native checkout low (set TEST_MYSQL_DSN)\n";
$r35cApp = dirname(TEST_CLI_DIR);
try {
    foreach ($r35cDrivers as $r35cDriver => $r35cTarget) {
        [$r35cExit, , $r35cNoise] = $r35cRun([$r35cDriver, 'setup', 'none', $r35cApp, $r35cTarget, $r35cRoot, '{}']);
        assert_equals([0, ''], [$r35cExit, trim($r35cNoise)], "R35 checkout {$r35cDriver}: migrated fixture with a product");
        $r35cDo = static function(string $action, array $extra = []) use ($r35cRun, $r35cDriver, $r35cApp, $r35cTarget, $r35cRoot): array {
            [$exit, $result, $noise] = $r35cRun([$r35cDriver, 'run', $action, $r35cApp, $r35cTarget, $r35cRoot, json_encode($extra)]);
            $label = "R35 checkout {$r35cDriver}/{$action}";
            assert_true($exit === 0 && $result !== null && !str_contains((string)$result['output'], 'Fatal error') && !str_contains((string)$result['output'], 'Uncaught'),
                "{$label}: real handler completes (exit {$exit} " . trim($noise) . ')');
            $html = (string)($result['output'] ?? '');
            $form = preg_match('~<form method="post" class="checkout-form".*?</form>~s', $html, $m) ? $m[0] : '';
            return [$label, $result ?? ['new_orders' => [], 'output' => ''], $form, $html];
        };
        $r35cQuoted = static fn(string $form): ?int => preg_match('~<noscript><input type="hidden" name="quoted_total" value="(\d+)"></noscript>~', $form, $m) ? (int)$m[1] : null;
        $r35cError = static fn(string $form): string => preg_match('~<p id="checkout-error"[^>]*>([^<]+)</p>~', $form, $m) ? html_entity_decode($m[1]) : '';

        [$l, $r, $form] = $r35cDo('get');
        preg_match_all('~name="shipping_method" value="([^"]+)"~', $form, $r35cMethods);
        $r35cPickup = (string)(array_values(array_filter($r35cMethods[1], static fn(string $c): bool => str_contains($c, 'pickup')))[0] ?? '');
        $r35cParcel = (string)(array_values(array_filter($r35cMethods[1], static fn(string $c): bool => !str_contains($c, 'pickup')))[0] ?? '');
        $r35cShown = $r35cQuoted($form);
        assert_true($r35cPickup !== '' && $r35cParcel !== '' && $r35cShown !== null,
            "{$l}: the SK form offers in-store pickup ({$r35cPickup}) and a parcel ({$r35cParcel})");
        $r35cConsents = preg_match_all('~name="consents\[([a-z_]+)\]" value="1"~', $form, $r35cC) ? array_fill_keys($r35cC[1], '1') : [];

        // In-store pickup paid cash on delivery, ordered without recalculating.
        [$l, $r, $form] = $r35cDo('codpickup', ['shipping_method' => $r35cPickup, 'payment' => 'cod', 'consents' => $r35cConsents, 'quoted_total' => (string)$r35cShown]);
        assert_true($r['new_orders'] === [] && str_contains($r35cError($form), 'Dobierka nie je pri zvolenom spôsobe doručenia dostupná') && !str_contains($r35cError($form), 'krajiny'),
            "{$l}: cash on delivery with in-store pickup is refused as a payment problem, not \"no delivery to the country\" (" . $r35cError($form) . ')');

        // Another method picked without "Recalculate": the message itself names the new total.
        [$l, $r, $form, $html] = $r35cDo('stale', ['shipping_method' => $r35cParcel, 'payment' => 'cod', 'consents' => $r35cConsents, 'quoted_total' => (string)($r35cShown + 1)]);
        $r35cTotal = preg_match('~<span id="summary-total">([^<]+)</span>~', $html, $m) ? html_entity_decode($m[1]) : '';
        assert_true($r['new_orders'] === [] && $r35cTotal !== '' && str_contains($r35cError($form), 'prepočítala') && str_contains($r35cError($form), $r35cTotal),
            "{$l}: the \"total changed\" message names the new total ({$r35cTotal}: " . $r35cError($form) . ')');
    }
} finally {
    if (isset($r35cDrivers['mysql'])) $r35cRun(['mysql', 'teardown', 'none', $r35cApp, $r35cDrivers['mysql'], $r35cRoot, '{}']);
}
