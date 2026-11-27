<?php

// Review round 25 (R60), lows (goal #648).
echo "\n--- R60 lows ---\n";
$r60lRepo = dirname(TEST_CLI_DIR);
$r60lRoot = test_fixture_path('r60-lows');
mkdir($r60lRoot, 0700, true);
$r60lChild = static function (string $code, array $args = []) use ($r60lRoot, $r60lRepo): array {
    $file = $r60lRoot . '/child-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($file, "<?php\n[, \$root] = \$argv;\n\$args = array_slice(\$argv, 2);\nrequire \$root . '/src/autoload.php';\n" . $code);
    $out = [];
    exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, $file, $r60lRepo, ...$args])) . ' 2>&1', $out);
    @unlink($file);
    foreach ($out as $line) if (str_starts_with($line, 'r60l ')) return (array)json_decode(substr($line, 5), true);
    return ['error' => substr(implode(' | ', $out), 0, 400)];
};

try {
    // Bootstrap::stores() read stores.json whole at every request, whatever its size.
    $r60lStores = $r60lChild(<<<'PHP'
$dir = $args[0];
DataOverrides::setDirForTesting($dir);
$json = json_encode(['default_store' => 'default', 'stores' => ['default' => ['name' => 'R60L']]]);
file_put_contents($dir . '/stores.json', $json . str_repeat(' ', DataOverrides::STORES_MAX_BYTES - strlen($json)));
$small = Bootstrap::stores()['default_store'] ?? null;
$property = new ReflectionProperty(Bootstrap::class, 'stores');
$property->setValue(null, null);
file_put_contents($dir . '/stores.json', $json . str_repeat(' ', DataOverrides::STORES_MAX_BYTES + 1 - strlen($json)));
$large = Bootstrap::stores();
echo 'r60l ' . json_encode(['small' => $small, 'large' => $large]) . "\n";
PHP, [$r60lRoot]);
    assert_equals(['small' => 'default', 'large' => []], $r60lStores,
        'R60 stores.json: read up to DataOverrides::STORES_MAX_BYTES (2 MB); a larger file is refused like invalid JSON (was: read whole at every request)');
} catch (Throwable $e) {
    assert_true(false, 'R60 stores.json (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    @unlink($r60lRoot . '/stores.json');
}

// Admin → Shipping / Payments: names of invisible characters, a line separator, Private Use characters alone, and delivery
// days of any text were saved (trim() only).
file_put_contents($r60lRoot . '/admin.php', <<<'PHP'
<?php
[, $root, $db, $action] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Operations.php';
class_exists(Security::class);
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($pdo, 'sqlite');
if ($action === 'setup') {
    (new Migrator($pdo, $root . '/migrations', dirname($db) . '/migrate.lock'))->apply();
    echo "r60l {\"ok\":true}\n";
    exit(0);
}
Bootstrap::initStoreContext('/', '', 'default');
I18n::setLocale('en');
$_SESSION = ['admin_role' => 'manager', 'admin_identity_id' => 1, 'admin_store_permissions' => ['default' => ['shipping.manage', 'payments.manage']]];
$_GET = [];
$count = static fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
ob_start();
register_shutdown_function(static function () use ($count): void {
    ob_end_clean();
    echo 'r60l ' . json_encode(['flash' => $_SESSION['flash']['type'] ?? null,
        'zones' => $count("SELECT COUNT(*) FROM shipping_zones WHERE code LIKE 'r60l%'"),
        'methods' => $count("SELECT COUNT(*) FROM shipping_methods WHERE code LIKE 'r60l%'") + $count("SELECT COUNT(*) FROM payment_methods WHERE code LIKE 'r60l%'"),
        'rates' => $count("SELECT COUNT(*) FROM shipping_rates WHERE delivery_days IN ('asap!', '2-3') AND weight_from_g = 60"),
        'names' => Db::get()->query("SELECT name FROM shipping_zones WHERE code LIKE 'r60l%' UNION ALL SELECT name FROM payment_methods WHERE code LIKE 'r60l%'")->fetchAll(PDO::FETCH_COLUMN)]) . "\n";
});
$rate = ['method_code' => 'gls', 'zone_code' => 'domestic', 'country' => '', 'weight_from_g' => '60', 'weight_to_g' => '1000', 'price' => '1.00', 'cod_surcharge' => '0', 'active' => '1'];
match ($action) {
    'zone_zwsp' => [$_POST = ['code' => 'r60lz', 'name' => "\u{200B}\u{200B}", 'active' => '1'], handleShippingZoneSave()],
    'zone_separator' => [$_POST = ['code' => 'r60lz', 'name' => "Zone\u{2028}A", 'active' => '1'], handleShippingZoneSave()],
    'ship_pua' => [$_POST = ['code' => 'r60ls', 'carrier' => 'gls', 'name' => "\u{E000}\u{F8FF}", 'service_type' => 'parcel', 'active' => '1'], handleShippingMethodSave()],
    'pay_separator' => [$_POST = ['code' => 'r60lp', 'provider' => 'manual', 'name' => "Pay\u{2028}later", 'service_type' => 'offline', 'min_amount' => '0', 'active' => '1'], handlePaymentMethodSave()],
    'rate_text' => [$_POST = $rate + ['delivery_days' => 'asap!'], handleShippingRateSave()],
    'rate_range' => [$_POST = $rate + ['delivery_days' => '2-3'], handleShippingRateSave()],
};
PHP);
$r60lAdmin = static function (string $action) use ($r60lRoot): array {
    $out = [];
    exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, $r60lRoot . '/admin.php', dirname(TEST_CLI_DIR), $r60lRoot . '/admin.sqlite', $action])) . ' 2>&1', $out);
    foreach ($out as $line) if (str_starts_with($line, 'r60l ')) return (array)json_decode(substr($line, 5), true);
    return ['error' => substr(implode(' | ', $out), 0, 400)];
};
try {
    assert_equals(['ok' => true], $r60lAdmin('setup'), 'R60 admin names: a migrated shop');
    $r60lRuns = [];
    foreach (['zone_zwsp', 'ship_pua', 'rate_text'] as $r60lAction) $r60lRuns[$r60lAction] = $r60lAdmin($r60lAction);
    assert_equals(array_fill_keys(['zone_zwsp', 'ship_pua', 'rate_text'], ['flash' => 'err', 'zones' => 0, 'methods' => 0, 'rates' => 0, 'names' => []]), $r60lRuns,
        'R60 admin names: a zone or method named U+200B or Private Use characters alone, and delivery days "asap!" are refused (was: saved)');
    // R61 (review round 26, low 8): a U+2028 inside is saved as a space (was: refused, which locked records saved before R60).
    assert_equals(['flash' => 'ok', 'zones' => 1, 'methods' => 0, 'rates' => 0, 'names' => ['Zone A']], $r60lAdmin('zone_separator'),
        'R60/R61 admin names: a zone name with U+2028 inside is saved with a space instead');
    assert_equals(['flash' => 'ok', 'zones' => 1, 'methods' => 1, 'rates' => 0, 'names' => ['Zone A', 'Pay later']], $r60lAdmin('pay_separator'),
        'R60/R61 admin names: a payment method name with U+2028 inside is saved with a space instead');
    assert_equals(['flash' => 'ok', 'zones' => 1, 'methods' => 1, 'rates' => 1, 'names' => ['Zone A', 'Pay later']], $r60lAdmin('rate_range'), 'R60 admin names: delivery days "2-3" are saved');
} catch (Throwable $e) {
    assert_true(false, 'R60 admin names (' . get_class($e) . ': ' . $e->getMessage() . ')');
}

// Admin → Shipping: a zone whose Standard price was removed dropped out of the method's rates, so its countries took the
// rate of the next zone listing them (international, every country): Czechia paid 15.99 € instead of not being served.
$r60lFile = json_decode((string)file_get_contents($r60lRepo . '/shipping.json'), true);
$r60lLocale = I18n::locale();
try {
    DataOverrides::setDirForTesting($r60lRoot);
    Shipping::setConfig((array)$r60lFile);
    StoreContext::activate(StoreContext::resolve(['default_store' => 'r60l', 'stores' => ['r60l' => ['name' => 'R60 lows', 'default_locale' => 'en',
        'currency' => 'eur', 'url_prefix' => '', 'domains' => [], 'permissions' => ['*'], 'legal' => ['country' => 'DE']]]], '/', '', 'r60l'));
    I18n::setLocale('en');
    file_put_contents($r60lRoot . '/' . DataOverrides::SHIPPING_METHODS, json_encode(['methods' => ['standard' => ['rates' => ['eu' => null]]]]));
    Shipping::forget();
    $r60lKeys = static fn(string $country): array => array_column(Shipping::availableMethods($country, 1000), 'key');
    assert_equals([-1, ['express'], 1599 + 200, ['standard']], [Shipping::calculate('standard', 'CZ', 1000, 1000), $r60lKeys('CZ'),
        Shipping::calculate('standard', 'US', 1000, 1000), $r60lKeys('US')],
        'R60 shipping: Standard priced empty for the EU zone is not offered in Czechia, international still is (was: Czechia at the international 17.99 €)');
    // "Restore the shipped settings" dropped the method's settings, which switched a method turned off back on.
    if (!function_exists('shippingFileMethodSettings')) {
        if (!function_exists('adminFieldErrors')) require_once $r60lRepo . '/src/Entrypoints/Admin/Shared.php';
        require_once $r60lRepo . '/src/Entrypoints/Admin/Operations.php';
    }
    $r60lReset = shippingFileMethodSettings('express', ['method' => 'express', 'reset' => '1'],
        ['express' => ['active' => false, 'free_above' => null, 'delivery_days' => '3', 'rates' => ['domestic' => ['base' => 100, 'per_kg' => 0], 'eu' => null]]]);
    $r60lExpress = $r60lReset['settings']['express'] ?? [];
    assert_equals([null, false, '1-2', 799, 1499], [$r60lReset['error'], $r60lExpress['active'] ?? null, $r60lExpress['delivery_days'] ?? null,
        $r60lExpress['rates']['domestic']['base'] ?? null, $r60lExpress['rates']['eu']['base'] ?? null],
        'R60 shipping: restoring the shipped prices keeps Express off and restores 7.99/14.99 and 1-2 days (was: Express switched back on)');
} catch (Throwable $e) {
    assert_true(false, 'R60 shipping zone without a price (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    I18n::setLocale($r60lLocale);
    DataOverrides::setDirForTesting(null);
    Shipping::setConfig(is_array($r60lFile) ? $r60lFile : []);
    Bootstrap::initStoreContext('/', '', 'default');
}

// The PL, HU and DE privacy templates were a paragraph each (no recipients, no complaint right, no invoice section).
$r60lPrivacy = [];
foreach (['sk', 'cs', 'en', 'es', 'de', 'pl', 'hu'] as $r60lLang) {
    $r60lHtml = (string)file_get_contents($r60lRepo . "/templates/legal/{$r60lLang}/privacy.html");
    $r60lPrivacy[$r60lLang] = [substr_count($r60lHtml, '<h2>'), substr_count($r60lHtml, '<li>') >= 13, str_contains($r60lHtml, '{{updated_at}}'), str_contains($r60lHtml, "\r")];
}
assert_equals(array_fill_keys(['sk', 'cs', 'en', 'es', 'de', 'pl', 'hu'], [6, true, true, false]), $r60lPrivacy,
    'R60 legal: every shipped privacy policy has the six sections (data, bases, recipients, retention, rights, invoices) and its date (was: PL/HU/DE a paragraph)');

// Store domains: one that did not answer as this shop (DNS not set up yet) was probed once and never again; the next save
// probed only domains it added or had no time left for.
$r60lCfg = new ReflectionProperty(Security::class, 'cfg');
$r60lCfgBefore = $r60lCfg->getValue();
$r60lHost = $_SERVER['HTTP_HOST'] ?? null;
try {
    $r60lStoresFile = json_decode((string)file_get_contents($r60lRepo . '/stores.example.json'), true);
    $r60lStoresFile['stores']['us']['domains'] = ['us.shop.example'];
    file_put_contents($r60lRoot . '/stores.json', json_encode($r60lStoresFile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    StoreSettings::setPathForTesting($r60lRoot . '/stores.json');
    $r60lCfg->setValue(null, ['app_url' => 'https://shop.example'] + (array)$r60lCfgBefore);
    $_SERVER['HTTP_HOST'] = 'shop.example';
    StoreSettings::setResolverForTesting(static fn(string $host): array => ['93.184.216.34']);
    $r60lCalls = [];
    $r60lUp = false;
    JsonHttpClient::setFixtureTransport(static function (string $method, string $url) use (&$r60lCalls, &$r60lUp): array {
        $host = (string)parse_url($url, PHP_URL_HOST);
        $r60lCalls[] = $host;
        return $host === 'new.shop.example' && !$r60lUp ? ['status' => 404, 'body' => 'parked']
            : ['status' => 200, 'body' => json_encode(['status' => 'ok', 'service' => 'bareboneseshop'])];
    });
    $r60lDomains = "us.shop.example\nnew.shop.example";
    $r60lFirst = StoreSettings::saveDomains('us', $r60lDomains);
    $r60lCalls = [];
    $r60lStill = StoreSettings::saveDomains('us', $r60lDomains);
    $r60lStillCalls = $r60lCalls;
    $r60lUp = true;
    $r60lCalls = [];
    $r60lFixed = StoreSettings::saveDomains('us', $r60lDomains);
    $r60lFixedCalls = $r60lCalls;
    $r60lCalls = [];
    $r60lAgain = StoreSettings::saveDomains('us', $r60lDomains);
    assert_equals([true, true, ['new.shop.example'], true, ['new.shop.example'], '', [], ''],
        [str_contains((string)($r60lFirst['warning'] ?? ''), 'new.shop.example'), str_contains((string)($r60lStill['warning'] ?? ''), 'new.shop.example'),
            $r60lStillCalls, $r60lFixed['ok'], $r60lFixedCalls, (string)($r60lFixed['warning'] ?? ''), $r60lCalls, (string)($r60lAgain['warning'] ?? '')],
        'R60 domains: a domain not answering as this shop is probed again by every save until it does, then no more (was: never again)');
} catch (Throwable $e) {
    assert_true(false, 'R60 domains (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    JsonHttpClient::setFixtureTransport(null);
    StoreSettings::setResolverForTesting(null);
    $r60lCfg->setValue(null, $r60lCfgBefore);
    StoreSettings::setPathForTesting(null);
    if ($r60lHost === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $r60lHost;
}

foreach (glob($r60lRoot . '/*') ?: [] as $r60lFile) @unlink($r60lFile);
@rmdir($r60lRoot);
