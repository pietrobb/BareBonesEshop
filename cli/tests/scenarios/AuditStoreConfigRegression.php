<?php

echo "\n--- Audit A05.3: admin CMS, shipping and payment configuration per store ---\n";
$a05ConfigRoot = test_fixture_path('admin-config-store');
mkdir($a05ConfigRoot);
$a05ConfigFixture = $a05ConfigRoot . '/request.php';
$a05ConfigSource = <<<'PHP'
<?php
[, $driver, $phase, $store, $action, $root, $target] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Catalog.php';
require $root . '/src/Entrypoints/Admin/Operations.php';
require $root . '/src/Http/ApiHandlers.php';
class_exists(Security::class);
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}`");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate-' . $driver . '.lock'))->apply();
    foreach (['default' => 1111, 'cz' => 2222] as $owner => $price) {
        $db->prepare('INSERT INTO pages (store_id, slug, title, body, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$owner, "a05p-{$owner}", "A05P-{$owner}-TITLE", "<p>A05P-{$owner}-BODY</p>", 'active']);
        $db->prepare('INSERT INTO shipping_methods (store_id, code, carrier, name) VALUES (?, ?, ?, ?)')
            ->execute([$owner, "a05s-{$owner}", "a05c-{$owner}", "A05S-{$owner}-NAME"]);
        $db->prepare('INSERT INTO shipping_methods (store_id, code, carrier, name) VALUES (?, ?, ?, ?)')
            ->execute([$owner, "a05t-{$owner}", "a05c-{$owner}", "A05T-{$owner}-NAME"]);
        $db->prepare('INSERT INTO shipping_zones (store_id, code, name) VALUES (?, ?, ?)')
            ->execute([$owner, "a05z-{$owner}", "A05Z-{$owner}-NAME"]);
        $db->prepare("INSERT INTO shipping_zone_countries (store_id, zone_code, country, postal_min, postal_max) VALUES (?, ?, 'SK', ?, '')")
            ->execute([$owner, "a05z-{$owner}", "ZC-{$owner}"]);
        $db->prepare("INSERT INTO shipping_rates (store_id, method_code, zone_code, carrier, country, price, delivery_days) VALUES (?, ?, ?, ?, '', ?, ?)")
            ->execute([$owner, "a05s-{$owner}", "a05z-{$owner}", "a05c-{$owner}", $price, "RD-{$owner}"]);
        $db->prepare('INSERT INTO payment_methods (store_id, code, provider, name) VALUES (?, ?, ?, ?)')
            ->execute([$owner, "a05m-{$owner}", 'a05prov', "A05M-{$owner}-NAME"]);
        $db->prepare("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES (?, ?, 'SK')")
            ->execute([$owner, "a05m-{$owner}"]);
        $db->prepare('INSERT INTO payment_method_shipping (store_id, method_code, shipping_method_code) VALUES (?, ?, ?)')
            ->execute([$owner, "a05m-{$owner}", "a05s-{$owner}"]);
    }
    exit(0);
}

$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['default'], ['id'=>'cz', 'url_prefix'=>'/cz', 'domains'=>[], 'catalog_scope'=>'default', 'provider_scope'=>'cz']);
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('en');
$foreign = $store === 'default' ? 'cz' : 'default';
$_SESSION = ['admin_role'=>'manager', 'admin_identity_id'=>1, 'admin_store_permissions'=>[$store=>['products.view','products.edit','shipping.manage','payments.manage']]];
$tables = [
    'pages' => 'id, store_id, slug, title, status',
    'shipping_methods' => 'id, store_id, code, carrier, name, active',
    'shipping_zones' => 'id, store_id, code, name',
    'shipping_zone_countries' => 'id, store_id, zone_code, country, postal_min',
    'shipping_rates' => 'id, store_id, method_code, zone_code, carrier, price',
    'payment_methods' => 'id, store_id, code, provider, name',
    'payment_method_countries' => 'id, store_id, method_code, country',
    'payment_method_shipping' => 'id, store_id, method_code, shipping_method_code',
];
$snapshot = static function() use ($db, $tables): array {
    $rows = [];
    foreach ($tables as $table => $columns) $rows[$table] = $db->query("SELECT {$columns} FROM {$table} ORDER BY id")->fetchAll();
    return $rows;
};
$idOf = static function(string $table, string $column, string $value) use ($db): int {
    $stmt = $db->prepare("SELECT id FROM {$table} WHERE {$column} = ?");
    $stmt->execute([$value]);
    return (int)$stmt->fetchColumn();
};
$db->beginTransaction();
$before = $snapshot();
ob_start();
register_shutdown_function(static function() use ($db, $snapshot, $before): void {
    $output = ob_get_contents();
    ob_clean();
    $result = ['output'=>$output, 'http_status'=>http_response_code(), 'before'=>$before, 'after'=>$snapshot()];
    $db->rollBack();
    print json_encode($result, JSON_THROW_ON_ERROR);
    ob_end_flush();
});
$_GET = [];
$_POST = [];
$own = $store;
$method = ['carrier'=>'a05x', 'name'=>'HACKED-NAME', 'service_type'=>'parcel', 'active'=>'1', 'sort_order'=>'5'];
$payment = ['provider'=>'a05x', 'name'=>'HACKED-NAME', 'service_type'=>'offline', 'active'=>'1', 'min_amount'=>'0'];
$rate = ['country'=>'', 'weight_from_g'=>'0', 'weight_to_g'=>'5000', 'price'=>'9', 'active'=>'1'];
match ($action) {
    'pages' => pageCmsPages(),
    'page_edit_foreign' => ($_GET = ['id'=>$idOf('pages', 'slug', "a05p-{$foreign}")]) && pageCmsEdit(),
    'shipping' => pageShipping(),
    'payments' => pagePayments(),
    'api_page_own', 'api_page_foreign' => print json_encode(($_GET = ['slug'=>'a05p-' . ($action === 'api_page_own' ? $own : $foreign)]) ? apiPage() : [], JSON_THROW_ON_ERROR),
    'page_save_foreign' => [$_GET = ['id'=>$idOf('pages', 'slug', "a05p-{$foreign}")], $_POST = ['slug'=>"a05p-{$foreign}", 'title'=>'HACKED-TITLE', 'status'=>'active'], handleCmsPageSave()],
    'page_create' => [$_POST = ['slug'=>"a05new-{$own}", 'title'=>"NEW-{$own}", 'status'=>'active'], handleCmsPageSave()],
    'page_delete_foreign' => [$_POST = ['page_id'=>$idOf('pages', 'slug', "a05p-{$foreign}")], handleCmsPageDelete()],
    'ship_method_save_foreign' => [$_POST = $method + ['id'=>$idOf('shipping_methods', 'code', "a05s-{$foreign}"), 'code'=>"a05s-{$foreign}"], handleShippingMethodSave()],
    'ship_method_create' => [$_POST = $method + ['code'=>"a05snew-{$own}"], handleShippingMethodSave()],
    'ship_method_delete_foreign' => [$_POST = ['id'=>$idOf('shipping_methods', 'code', "a05s-{$foreign}")], handleShippingMethodDelete()],
    'ship_zone_save_foreign' => [$_POST = ['id'=>$idOf('shipping_zones', 'code', "a05z-{$foreign}"), 'code'=>"a05z-{$foreign}", 'name'=>'HACKED-NAME', 'active'=>'1'], handleShippingZoneSave()],
    'ship_zone_create' => [$_POST = ['code'=>"a05znew-{$own}", 'name'=>"NEW-{$own}", 'active'=>'1'], handleShippingZoneSave()],
    'ship_zone_delete_foreign' => [$_POST = ['id'=>$idOf('shipping_zones', 'code', "a05z-{$foreign}")], handleShippingZoneDelete()],
    'ship_country_create_foreign' => [$_POST = ['zone_code'=>"a05z-{$foreign}", 'country'=>'HU'], handleShippingCountrySave()],
    'ship_country_create' => [$_POST = ['zone_code'=>"a05z-{$own}", 'country'=>'HU'], handleShippingCountrySave()],
    'ship_country_delete_foreign' => [$_POST = ['id'=>$idOf('shipping_zone_countries', 'zone_code', "a05z-{$foreign}")], handleShippingCountryDelete()],
    'ship_rate_save_foreign' => [$_POST = $rate + ['id'=>$idOf('shipping_rates', 'method_code', "a05s-{$foreign}"), 'method_code'=>"a05s-{$own}", 'zone_code'=>"a05z-{$own}"], handleShippingRateSave()],
    'ship_rate_create_foreign' => [$_POST = $rate + ['method_code'=>"a05s-{$foreign}", 'zone_code'=>"a05z-{$foreign}"], handleShippingRateSave()],
    'ship_rate_create' => [$_POST = $rate + ['method_code'=>"a05s-{$own}", 'zone_code'=>"a05z-{$own}"], handleShippingRateSave()],
    'ship_rate_delete_foreign' => [$_POST = ['id'=>$idOf('shipping_rates', 'method_code', "a05s-{$foreign}")], handleShippingRateDelete()],
    'pay_method_save_foreign' => [$_POST = $payment + ['id'=>$idOf('payment_methods', 'code', "a05m-{$foreign}"), 'code'=>"a05mx-{$foreign}"], handlePaymentMethodSave()],
    'pay_method_create' => [$_POST = $payment + ['code'=>"a05mnew-{$own}"], handlePaymentMethodSave()],
    'pay_method_delete_foreign' => [$_POST = ['id'=>$idOf('payment_methods', 'code', "a05m-{$foreign}")], handlePaymentMethodDelete()],
    'pay_country_create_foreign' => [$_POST = ['method_code'=>"a05m-{$foreign}", 'country'=>'HU'], handlePaymentCountrySave()],
    'pay_country_create' => [$_POST = ['method_code'=>"a05m-{$own}", 'country'=>'HU'], handlePaymentCountrySave()],
    'pay_country_delete_foreign' => [$_POST = ['id'=>$idOf('payment_method_countries', 'method_code', "a05m-{$foreign}")], handlePaymentCountryDelete()],
    'pay_link_create_foreign' => [$_POST = ['method_code'=>"a05m-{$foreign}", 'shipping_method_code'=>"a05s-{$foreign}"], handlePaymentShippingSave()],
    'pay_link_create' => [$_POST = ['method_code'=>"a05m-{$own}", 'shipping_method_code'=>"a05t-{$own}"], handlePaymentShippingSave()],
    'pay_link_delete_foreign' => [$_POST = ['id'=>$idOf('payment_method_shipping', 'method_code', "a05m-{$foreign}")], handlePaymentShippingDelete()],
};
PHP;
file_put_contents($a05ConfigFixture, $a05ConfigSource);
$a05ConfigRun = static function(array $args) use ($a05ConfigFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a05ConfigFixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
// Expected new row per creating action: [table, marker column, marker value prefix].
$a05ConfigCreates = [
    'page_create' => ['pages', 'slug', 'a05new-'],
    'ship_method_create' => ['shipping_methods', 'code', 'a05snew-'],
    'ship_zone_create' => ['shipping_zones', 'code', 'a05znew-'],
    'ship_country_create' => ['shipping_zone_countries', 'country', 'HU'],
    'ship_rate_create' => ['shipping_rates', 'price', '9'],
    'pay_method_create' => ['payment_methods', 'code', 'a05mnew-'],
    'pay_country_create' => ['payment_method_countries', 'country', 'HU'],
    'pay_link_create' => ['payment_method_shipping', 'method_code', 'a05m-'],
];
$a05ConfigActions = array_merge(
    ['pages', 'page_edit_foreign', 'shipping', 'payments', 'api_page_own', 'api_page_foreign',
     'page_save_foreign', 'page_delete_foreign', 'ship_method_save_foreign', 'ship_method_delete_foreign',
     'ship_zone_save_foreign', 'ship_zone_delete_foreign', 'ship_country_create_foreign', 'ship_country_delete_foreign',
     'ship_rate_save_foreign', 'ship_rate_create_foreign', 'ship_rate_delete_foreign', 'pay_method_save_foreign',
     'pay_method_delete_foreign', 'pay_country_create_foreign', 'pay_country_delete_foreign',
     'pay_link_create_foreign', 'pay_link_delete_foreign'],
    array_keys($a05ConfigCreates)
);
$a05ConfigDrivers = ['sqlite' => $a05ConfigRoot . '/config.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a05ConfigDrivers['mysql'] = 'bbe_a05_config_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A05.3 native store configuration boundary (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a05ConfigDrivers as $a05ConfigDriver => $a05ConfigTarget) {
        [$a05ConfigExit, , $a05ConfigStderr] = $a05ConfigRun([$a05ConfigDriver, 'setup', 'default', 'none', dirname(TEST_CLI_DIR), $a05ConfigTarget]);
        assert_equals([0, ''], [$a05ConfigExit, $a05ConfigStderr], "A05.3 {$a05ConfigDriver}: migrated fixture schema with default/cz configuration");
        foreach (['default', 'cz'] as $a05ConfigStore) {
            $a05ConfigForeign = $a05ConfigStore === 'default' ? 'cz' : 'default';
            $a05ConfigForeignMarker = '/(a05[pstzmc]-|A05[PSTZM]-|ZC-|RD-)' . $a05ConfigForeign . '\b/';
            foreach ($a05ConfigActions as $a05ConfigAction) {
                $a05ConfigLabel = "A05.3 {$a05ConfigDriver}/{$a05ConfigStore}/{$a05ConfigAction}";
                [$a05ConfigExit, $a05ConfigStdout, $a05ConfigStderr] = $a05ConfigRun([$a05ConfigDriver, 'run', $a05ConfigStore, $a05ConfigAction, dirname(TEST_CLI_DIR), $a05ConfigTarget]);
                assert_equals([0, ''], [$a05ConfigExit, $a05ConfigStderr], "{$a05ConfigLabel}: actual handler exits without errors");
                $a05ConfigResult = json_decode($a05ConfigStdout, true, 512, JSON_THROW_ON_ERROR);
                $a05ConfigHtml = $a05ConfigResult['output'];
                assert_true(!preg_match($a05ConfigForeignMarker, $a05ConfigHtml), "{$a05ConfigLabel}: no foreign store configuration disclosed");
                $a05ConfigOwned = static fn(array $rows, string $owner): array => array_values(array_filter($rows, static fn(array $row): bool => $row['store_id'] === $owner));
                $a05ConfigForeignChanged = [];
                $a05ConfigCountChanged = [];
                foreach ($a05ConfigResult['before'] as $a05ConfigTable => $a05ConfigRows) {
                    if ($a05ConfigOwned($a05ConfigRows, $a05ConfigForeign) != $a05ConfigOwned($a05ConfigResult['after'][$a05ConfigTable], $a05ConfigForeign)) $a05ConfigForeignChanged[] = $a05ConfigTable;
                    if (count($a05ConfigRows) !== count($a05ConfigResult['after'][$a05ConfigTable])) $a05ConfigCountChanged[] = $a05ConfigTable;
                }
                assert_equals([], $a05ConfigForeignChanged, "{$a05ConfigLabel}: foreign store rows unchanged");
                if (isset($a05ConfigCreates[$a05ConfigAction])) {
                    [$a05ConfigTable, $a05ConfigColumn, $a05ConfigPrefix] = $a05ConfigCreates[$a05ConfigAction];
                    $a05ConfigNew = array_values(array_udiff($a05ConfigResult['after'][$a05ConfigTable], $a05ConfigResult['before'][$a05ConfigTable], static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']));
                    assert_equals([[$a05ConfigStore, true]], array_map(static fn(array $row): array => [$row['store_id'], str_starts_with((string)$row[$a05ConfigColumn], $a05ConfigPrefix)], $a05ConfigNew), "{$a05ConfigLabel}: new row belongs to the active store");
                    if ($a05ConfigAction === 'ship_rate_create') assert_equals("a05c-{$a05ConfigStore}", $a05ConfigNew[0]['carrier'] ?? null, "{$a05ConfigLabel}: rate carrier comes from the active store method");
                } elseif (str_ends_with($a05ConfigAction, '_create_foreign')) {
                    assert_equals([], $a05ConfigCountChanged, "{$a05ConfigLabel}: foreign zone/method codes rejected without new rows");
                }
                if (in_array($a05ConfigAction, ['pages', 'shipping', 'payments'], true)) {
                    $a05ConfigOwnMarker = ['pages' => "A05P-{$a05ConfigStore}-TITLE", 'shipping' => "RD-{$a05ConfigStore}", 'payments' => "A05M-{$a05ConfigStore}-NAME"][$a05ConfigAction];
                    assert_true(str_contains($a05ConfigHtml, $a05ConfigOwnMarker), "{$a05ConfigLabel}: own store configuration listed");
                }
                if ($a05ConfigAction === 'api_page_own') {
                    assert_equals("A05P-{$a05ConfigStore}-TITLE", json_decode($a05ConfigHtml, true)['page']['title'] ?? null, "{$a05ConfigLabel}: public page served for own store");
                }
                if ($a05ConfigAction === 'api_page_foreign') {
                    assert_equals(404, $a05ConfigResult['http_status'], "{$a05ConfigLabel}: foreign public page returns 404");
                }
            }
        }
    }
} finally {
    if (isset($a05ConfigDrivers['mysql'])) $a05ConfigRun(['mysql', 'teardown', 'default', 'none', dirname(TEST_CLI_DIR), $a05ConfigDrivers['mysql']]);
    foreach (glob($a05ConfigRoot . '/*') ?: [] as $a05ConfigFile) unlink($a05ConfigFile);
    rmdir($a05ConfigRoot);
}
