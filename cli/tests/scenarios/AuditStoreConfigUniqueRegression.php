<?php

echo "\n--- Audit A05.5: store-scoped uniqueness of CMS slugs and shipping/payment codes ---\n";
$a055Root = test_fixture_path('config-unique-store');
mkdir($a055Root);
$a055Fixture = $a055Root . '/request.php';
$a055Source = <<<'PHP'
<?php
[, $driver, $phase, $store, $action, $root, $target] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Catalog.php';
require $root . '/src/Entrypoints/Admin/Operations.php';
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
$tables = [
    'pages' => 'id, store_id, slug, title, status',
    'shipping_methods' => 'id, store_id, code, carrier, name',
    'shipping_zones' => 'id, store_id, code, name',
    'shipping_zone_countries' => 'id, store_id, zone_code, country, postal_min, postal_max',
    'payment_methods' => 'id, store_id, code, provider, name',
    'payment_method_countries' => 'id, store_id, method_code, country',
    'payment_method_shipping' => 'id, store_id, method_code, shipping_method_code',
];
$snapshot = static function() use ($db, $tables): array {
    $rows = [];
    foreach ($tables as $table => $columns) $rows[$table] = $db->query("SELECT {$columns} FROM {$table} ORDER BY id")->fetchAll();
    return $rows;
};
$indexes = static function() use ($db, $driver, $tables): array {
    $result = [];
    foreach (array_keys($tables) as $table) {
        $unique = [];
        $names = [];
        if ($driver === 'sqlite') {
            foreach ($db->query("PRAGMA index_list({$table})")->fetchAll() as $index) {
                $names[] = str_starts_with($index['name'], 'sqlite_autoindex_') ? 'autoindex' : $index['name'];
                if ((int)$index['unique'] === 1 && $index['origin'] !== 'pk') {
                    $unique[] = implode(',', array_column($db->query("PRAGMA index_info(\"{$index['name']}\")")->fetchAll(), 'name'));
                }
            }
        } else {
            $stmt = $db->prepare("SELECT INDEX_NAME, MIN(NON_UNIQUE) AS non_unique, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
                FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME <> 'PRIMARY' GROUP BY INDEX_NAME");
            $stmt->execute([$table]);
            foreach ($stmt->fetchAll() as $index) {
                $names[] = (int)$index['non_unique'] === 0 ? 'autoindex' : $index['INDEX_NAME'];
                if ((int)$index['non_unique'] === 0) $unique[] = $index['cols'];
            }
        }
        sort($unique);
        $names = array_values(array_filter($names, static fn(string $name): bool => $name !== 'autoindex' && !str_starts_with($name, 'uq_')));
        sort($names);
        $result[$table] = ['unique' => $unique, 'indexes' => $names];
    }
    return $result;
};
if ($phase === 'setup') {
    // Upgrade path: schema as released before this fix, seeded, then the new migration applied.
    $previous = dirname(__FILE__) . '/migrations-previous';
    mkdir($previous);
    foreach (glob($root . '/migrations/*.php') as $file) {
        if (basename($file) < '20261012160000') copy($file, $previous . '/' . basename($file));
    }
    (new Migrator($db, $previous, dirname(__FILE__) . '/migrate-' . $driver . '.lock'))->apply();
    $db->exec("INSERT INTO pages (store_id, slug, title, body, status) VALUES ('default', 'shared', 'DEFAULT-PAGE', '<p>x</p>', 'active')");
    $db->exec("INSERT INTO shipping_methods (store_id, code, carrier, name) VALUES ('default', 'shared', 'dcarrier', 'DEFAULT-SHIP')");
    $db->exec("INSERT INTO shipping_zones (store_id, code, name) VALUES ('default', 'shared', 'DEFAULT-ZONE')");
    $db->exec("INSERT INTO shipping_zone_countries (store_id, zone_code, country, postal_min, postal_max) VALUES ('default', 'shared', 'SK', '', '')");
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name) VALUES ('default', 'shared', 'dprov', 'DEFAULT-PAY')");
    $db->exec("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'shared', 'SK')");
    $db->exec("INSERT INTO payment_method_shipping (store_id, method_code, shipping_method_code) VALUES ('default', 'shared', 'shared')");
    $before = $snapshot();
    $indexesBefore = $indexes();
    (new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate-' . $driver . '.lock'))->apply();
    foreach (glob($previous . '/*') as $file) unlink($file);
    rmdir($previous);
    $duplicates = [];
    foreach (["INSERT INTO pages (store_id, slug, title, body, status) VALUES ('default', 'shared', 'DUP', '', 'active')",
              "INSERT INTO shipping_methods (store_id, code, carrier, name) VALUES ('default', 'shared', 'x', 'DUP')",
              "INSERT INTO shipping_zone_countries (store_id, zone_code, country, postal_min, postal_max) VALUES ('default', 'shared', 'SK', '', '')",
              "INSERT INTO payment_method_shipping (store_id, method_code, shipping_method_code) VALUES ('default', 'shared', 'shared')"] as $sql) {
        try { $db->exec($sql); $duplicates[] = 'accepted'; } catch (PDOException) { $duplicates[] = 'rejected'; }
    }
    print json_encode(['before' => $before, 'after' => $snapshot(), 'indexes_before' => $indexesBefore, 'indexes_after' => $indexes(), 'duplicates' => $duplicates], JSON_THROW_ON_ERROR);
    exit(0);
}

$stores = Bootstrap::stores();
$base = $stores['stores']['default'];
// A store with an empty provider scope (the sample cz/hu/pl scopes are seeded by 20261022230000_currency_price_lists).
$stores['stores']['a055'] = array_replace($base, ['id'=>'a055', 'url_prefix'=>'/a055', 'domains'=>[], 'catalog_scope'=>'default', 'provider_scope'=>'a055']);
$stores['stores']['sk2'] = array_replace($base, ['id'=>'sk2', 'url_prefix'=>'/sk2', 'domains'=>[], 'catalog_scope'=>'default', 'provider_scope'=>'default']);
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('en');
$_SESSION = ['admin_role'=>'manager', 'admin_identity_id'=>1, 'admin_store_permissions'=>[$store=>['products.view','products.edit','shipping.manage','payments.manage']]];
ob_start();
register_shutdown_function(static function() use ($snapshot): void {
    $output = ob_get_contents();
    ob_clean();
    print json_encode(['output'=>$output, 'flash'=>$_SESSION['flash'] ?? null, 'rows'=>$snapshot()], JSON_THROW_ON_ERROR);
    ob_end_flush();
});
$_GET = [];
$method = ['carrier'=>'czcarrier', 'name'=>'CZ-SHIP', 'service_type'=>'parcel', 'active'=>'1', 'sort_order'=>'5', 'code'=>'shared'];
$payment = ['provider'=>'czprov', 'name'=>'CZ-PAY', 'service_type'=>'offline', 'active'=>'1', 'min_amount'=>'0', 'code'=>'shared'];
match ($action) {
    'page_create', 'page_dup' => [$_POST = ['slug'=>'shared', 'title'=>'CZ-PAGE', 'status'=>'active'], handleCmsPageSave()],
    'ship_method_create', 'ship_method_dup' => [$_POST = $method, handleShippingMethodSave()],
    'ship_zone_create', 'ship_zone_dup' => [$_POST = ['code'=>'shared', 'name'=>'CZ-ZONE', 'active'=>'1'], handleShippingZoneSave()],
    'ship_country_create', 'ship_country_dup' => [$_POST = ['zone_code'=>'shared', 'country'=>'SK'], handleShippingCountrySave()],
    'pay_method_create', 'pay_method_dup' => [$_POST = $payment, handlePaymentMethodSave()],
    'pay_country_create', 'pay_country_dup' => [$_POST = ['method_code'=>'shared', 'country'=>'SK'], handlePaymentCountrySave()],
    'pay_link_create', 'pay_link_dup' => [$_POST = ['method_code'=>'shared', 'shipping_method_code'=>'shared'], handlePaymentShippingSave()],
    'shared_scope_country' => [$_POST = ['method_code'=>'shared', 'country'=>'HU'], handlePaymentCountrySave()],
    'shared_scope_list' => pageShipping(),
};
PHP;
file_put_contents($a055Fixture, $a055Source);
$a055Run = static function(array $args) use ($a055Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a055Fixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$a055Unique = [
    'pages' => ['store_id,slug'],
    'shipping_methods' => ['store_id,code'],
    'shipping_zones' => ['store_id,code'],
    'shipping_zone_countries' => ['store_id,zone_code,country,postal_min,postal_max'],
    'payment_methods' => ['store_id,code'],
    'payment_method_countries' => ['store_id,method_code,country'],
    'payment_method_shipping' => ['store_id,method_code,shipping_method_code'],
];
$a055Creates = ['page_create', 'ship_method_create', 'ship_zone_create', 'ship_country_create', 'pay_method_create', 'pay_country_create', 'pay_link_create'];
$a055Drivers = ['sqlite' => $a055Root . '/unique.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a055Drivers['mysql'] = 'bbe_a055_unique_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A05.5 native store-scoped uniqueness (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a055Drivers as $a055Driver => $a055Target) {
        [$a055Exit, $a055Out, $a055Stderr] = $a055Run([$a055Driver, 'setup', 'default', 'none', dirname(TEST_CLI_DIR), $a055Target]);
        assert_equals([0, ''], [$a055Exit, $a055Stderr], "A05.5 {$a055Driver}: previous schema seeded and upgraded");
        $a055Setup = json_decode($a055Out, true, 512, JSON_THROW_ON_ERROR);
        $a055Default = static fn(array $rows): array => array_map(static fn(array $table): array => array_values(array_filter($table, static fn(array $row): bool => $row['store_id'] === 'default')), $rows);
        assert_equals($a055Setup['before'], $a055Default($a055Setup['after']), "A05.5 {$a055Driver}: upgrade preserves every existing configuration row and id");
        $a055Seeded = array_values(array_unique(array_merge(...array_map(static fn(array $table): array => array_column(array_filter($table, static fn(array $row): bool => $row['store_id'] !== 'default'), 'store_id'), array_values($a055Setup['after'])))));
        sort($a055Seeded);
        assert_equals(['cz', 'hu', 'pl'], $a055Seeded, "A05.5 {$a055Driver}: the upgrade adds rows only to the seeded sample price-list scopes");
        assert_equals($a055Unique, array_map(static fn(array $table): array => $table['unique'], $a055Setup['indexes_after']), "A05.5 {$a055Driver}: unique keys are scoped by store_id only");
        assert_equals(array_map(static fn(array $table): array => $table['indexes'], $a055Setup['indexes_before']), array_map(static fn(array $table): array => $table['indexes'], $a055Setup['indexes_after']), "A05.5 {$a055Driver}: secondary indexes survive the rebuild");
        assert_equals(['rejected', 'rejected', 'rejected', 'rejected'], $a055Setup['duplicates'], "A05.5 {$a055Driver}: duplicates inside one store are still rejected");
        foreach ($a055Creates as $a055Action) {
            foreach ([$a055Action, str_replace('_create', '_dup', $a055Action)] as $a055Step) {
                [$a055Exit, $a055Out, $a055Stderr] = $a055Run([$a055Driver, 'run', 'a055', $a055Step, dirname(TEST_CLI_DIR), $a055Target]);
                assert_equals([0, ''], [$a055Exit, $a055Stderr], "A05.5 {$a055Driver}/a055/{$a055Step}: actual handler exits without errors");
            }
        }
        $a055Result = json_decode($a055Out, true, 512, JSON_THROW_ON_ERROR);
        $a055Own = array_map(static fn(array $table): int => count(array_filter($table, static fn(array $row): bool => $row['store_id'] === 'a055')), $a055Result['rows']);
        assert_equals(array_fill_keys(array_keys($a055Unique), 1), $a055Own, "A05.5 {$a055Driver}: a second store reuses every code/slug of default exactly once");
        assert_equals($a055Default($a055Setup['after']), $a055Default($a055Result['rows']), "A05.5 {$a055Driver}: default store rows unchanged by the second store");
        [$a055Exit, $a055Out, $a055Stderr] = $a055Run([$a055Driver, 'run', 'sk2', 'shared_scope_country', dirname(TEST_CLI_DIR), $a055Target]);
        assert_equals([0, ''], [$a055Exit, $a055Stderr], "A05.5 {$a055Driver}/sk2: shared provider_scope handler exits without errors");
        $a055Rows = json_decode($a055Out, true, 512, JSON_THROW_ON_ERROR)['rows']['payment_method_countries'];
        assert_equals([['default', 'HU']], array_values(array_map(static fn(array $row): array => [$row['store_id'], $row['country']], array_filter($a055Rows, static fn(array $row): bool => $row['country'] === 'HU' && $row['method_code'] === 'shared'))), "A05.5 {$a055Driver}/sk2: config is written to the provider_scope checkout reads");
        [$a055Exit, $a055Out, $a055Stderr] = $a055Run([$a055Driver, 'run', 'sk2', 'shared_scope_list', dirname(TEST_CLI_DIR), $a055Target]);
        $a055Html = json_decode($a055Out, true, 512, JSON_THROW_ON_ERROR)['output'];
        assert_true($a055Exit === 0 && str_contains($a055Html, 'DEFAULT-SHIP') && !str_contains($a055Html, 'CZ-SHIP'), "A05.5 {$a055Driver}/sk2: admin lists the shared provider_scope configuration");
    }
} finally {
    if (isset($a055Drivers['mysql'])) $a055Run(['mysql', 'teardown', 'default', 'none', dirname(TEST_CLI_DIR), $a055Drivers['mysql']]);
    foreach (glob($a055Root . '/migrations-previous/*') ?: [] as $a055File) unlink($a055File);
    if (is_dir($a055Root . '/migrations-previous')) rmdir($a055Root . '/migrations-previous');
    foreach (glob($a055Root . '/*') ?: [] as $a055File) unlink($a055File);
    rmdir($a055Root);
}
