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
file_put_contents($dir . '/stores.json', $json . str_repeat(' ', Bootstrap::STORES_MAX_BYTES - strlen($json)));
$small = Bootstrap::stores()['default_store'] ?? null;
$property = new ReflectionProperty(Bootstrap::class, 'stores');
$property->setValue(null, null);
file_put_contents($dir . '/stores.json', $json . str_repeat(' ', Bootstrap::STORES_MAX_BYTES + 1 - strlen($json)));
$large = Bootstrap::stores();
echo 'r60l ' . json_encode(['small' => $small, 'large' => $large]) . "\n";
PHP, [$r60lRoot]);
    assert_equals(['small' => 'default', 'large' => []], $r60lStores,
        'R60 stores.json: read up to Bootstrap::STORES_MAX_BYTES (2 MB); a larger file is refused like invalid JSON (was: read whole at every request)');
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
        'rates' => $count("SELECT COUNT(*) FROM shipping_rates WHERE delivery_days IN ('asap!', '2-3') AND weight_from_g = 60")]) . "\n";
});
$rate = ['method_code' => 'gls', 'zone_code' => 'domestic', 'country' => '', 'weight_from_g' => '60', 'weight_to_g' => '1000', 'price' => '1.00', 'cod_surcharge' => '0', 'active' => '1'];
match ($action) {
    'zone_zwsp' => [$_POST = ['code' => 'r60lz', 'name' => "\u{200B}\u{200B}", 'active' => '1'], handleShippingZoneSave()],
    'zone_separator' => [$_POST = ['code' => 'r60lz', 'name' => "Zone\u{2028}A", 'active' => '1'], handleShippingZoneSave()],
    'zone_ok' => [$_POST = ['code' => 'r60lz', 'name' => 'Zone A', 'active' => '1'], handleShippingZoneSave()],
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
    foreach (['zone_zwsp', 'zone_separator', 'ship_pua', 'pay_separator', 'rate_text'] as $r60lAction) $r60lRuns[$r60lAction] = $r60lAdmin($r60lAction);
    assert_equals(array_fill_keys(['zone_zwsp', 'zone_separator', 'ship_pua', 'pay_separator', 'rate_text'], ['flash' => 'err', 'zones' => 0, 'methods' => 0, 'rates' => 0]), $r60lRuns,
        'R60 admin names: a zone or method named U+200B, Private Use characters alone or with U+2028, and delivery days "asap!" are refused (was: saved)');
    assert_equals(['flash' => 'ok', 'zones' => 1, 'methods' => 0, 'rates' => 0], $r60lAdmin('zone_ok'), 'R60 admin names: a visible zone name is saved');
    assert_equals(['flash' => 'ok', 'zones' => 1, 'methods' => 0, 'rates' => 1], $r60lAdmin('rate_range'), 'R60 admin names: delivery days "2-3" are saved');
} catch (Throwable $e) {
    assert_true(false, 'R60 admin names (' . get_class($e) . ': ' . $e->getMessage() . ')');
}

foreach (glob($r60lRoot . '/*') ?: [] as $r60lFile) @unlink($r60lFile);
@rmdir($r60lRoot);
