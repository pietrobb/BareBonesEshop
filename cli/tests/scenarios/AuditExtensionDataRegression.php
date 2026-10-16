<?php

// The core is generic: auto-parts (TecDoc vehicles, OE numbers) left it. Existing databases lose the empty auto-parts
// tables through a migration that never deletes data, and anything a single kind of shop needs is an extension: its own
// tables/columns (extensions/<code>/migrations, recorded apart from core migrations), storefront pages and JSON API
// actions — served through the real entrypoints, escaped, and never able to replace a core route.
echo "\n--- Generic core: auto-parts removed; extension migrations, pages and API actions ---\n";

$edRoot = test_fixture_path('extension-data');
$edWrite = static function(string $base, array $files): void {
    foreach ($files as $path => $content) {
        $absolute = $base . '/' . $path;
        if (!is_dir(dirname($absolute))) mkdir(dirname($absolute), 0750, true);
        file_put_contents($absolute, $content);
    }
};
$edManifest = static fn(string $code, string $register): string => "<?php\nreturn ['code' => '{$code}', 'name' => '{$code}', 'version' => '1.0.0',\n"
    . "    'register' => static function (ExtensionApi \$api): void {\n{$register}\n    },\n];\n";
$edExt = $edRoot . '/extensions';
$edWrite($edExt, [
    'acme-shop/extension.php' => $edManifest('acme-shop', <<<'PHP'
        $api->page('hello', static fn(array $query): array => [
            'title' => 'Acme hello page',
            'content' => Template::process('<p class="acme-hello">{{name}}</p>', ['name' => Security::clean($query['name'] ?? '') ?: (string)($query['raw'] ?? '')]),
        ]);
        $api->api('notes', static fn(array $query): array => [
            'notes' => (int)Db::get()->query('SELECT COUNT(*) FROM ext_acme_shop_notes')->fetchColumn(),
            'badge_column' => Db::get()->query('SELECT ext_acme_shop_badge FROM products LIMIT 1')->fetchAll() !== null,
        ]);
PHP),
    'acme-shop/migrations/20261014130000_notes.php' => <<<'PHP'
<?php
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $id = $isSqlite ? 'INTEGER PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $db->exec("CREATE TABLE IF NOT EXISTS ext_acme_shop_notes (id {$id}, product_id INTEGER NOT NULL, note TEXT NOT NULL)");
    }
};
PHP,
    'acme-shop/migrations/20261014131000_product_badge.php' => <<<'PHP'
<?php
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $db->exec('ALTER TABLE products ADD COLUMN ext_acme_shop_badge VARCHAR(40) DEFAULT NULL');
    }
};
PHP,
    'acme-two/extension.php' => $edManifest('acme-two', ''),
    'acme-two/migrations/20261014130000_notes.php' => <<<'PHP'
<?php
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $db->exec('CREATE TABLE ext_acme_two_flags (product_id INTEGER NOT NULL)');
    }
};
PHP,
    'bad-route/extension.php' => $edManifest('bad-route', "        \$api->api('ok', static fn(array \$q): array => ['leaked' => true]);\n        \$api->page('Cart Page', static fn(array \$q): array => ['title' => 'x', 'content' => 'x']);"),
]);
$edConfig = ['extensions' => ['acme-shop', 'acme-two', 'bad-route']];
file_put_contents($edRoot . '/config.json', json_encode($edConfig));

// One process per step: a migrated database on SQLite and, when configured, on MySQL/MariaDB.
$edFixture = $edRoot . '/step.php';
file_put_contents($edFixture, <<<'PHP'
<?php
[, $driver, $step, $root, $dir, $target] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($step === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    if (in_array($step, ['legacy', 'fresh'], true)) $db->exec("CREATE DATABASE `{$target}`");
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
$tables = static function () use ($db, $driver): array {
    $names = $driver === 'sqlite'
        ? $db->query("SELECT name FROM sqlite_master WHERE type IN ('table', 'trigger')")->fetchAll(PDO::FETCH_COLUMN)
        : $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_intersect(['product_oe_numbers', 'product_vehicles', 'oe_fts', 'oe_ai', 'oe_ad', 'product_specifications', 'ext_acme_shop_notes', 'ext_acme_two_flags'], $names));
};
$core = static fn(string $migrations): Migrator => new Migrator($db, $migrations, $dir . '/' . $driver . '.lock');
$state = [];
if ($step === 'legacy') {
    // A shop on the previous release: every core migration except the removal one, with a vehicle row kept by the shop.
    $previous = $dir . '/previous-migrations-' . $driver;
    mkdir($previous);
    foreach (glob($root . '/migrations/*.php') as $file) {
        if (basename($file) !== '20261014120000_remove_auto_parts_tables.php') copy($file, $previous . '/' . basename($file));
    }
    $core($previous)->apply();
    $state['before'] = $tables();
    $db->exec("INSERT INTO categories (store_id, slug, name, path) VALUES ('default', 'ed-cat', 'ED', 'ed-cat')");
    $category = (int)$db->lastInsertId();
    $db->exec("INSERT INTO products (store_id, sku, slug, name, category_id, price, stock, status) VALUES ('default', 'ED-1', 'ed-1', 'ED 1', {$category}, 100, 1, 'active')");
    $product = (int)$db->lastInsertId();
    $db->exec("INSERT INTO product_vehicles (product_id, ktype, manufacturer, model, type_name) VALUES ({$product}, 1, 'KEEP', 'ME', '')");
    $state['applied'] = $core($root . '/migrations')->apply();
    $state['after'] = $tables();
    $state['kept_rows'] = $db->query('SELECT manufacturer, model FROM product_vehicles')->fetchAll();
} elseif ($step === 'fresh') {
    $core($root . '/migrations')->apply();
    $state['after'] = $tables();
} elseif ($step === 'extensions') {
    Extensions::boot(json_decode((string)file_get_contents($dir . '/config.json'), true), $dir . '/extensions');
    $state['errors'] = Extensions::errors();
    $state['runners'] = array_keys(Extensions::migrators($db, $dir . '/' . $driver . '.lock'));
    $state['applied'] = [];
    foreach (Extensions::migrators($db, $dir . '/' . $driver . '.lock') as $migrator) $state['applied'] = array_merge($state['applied'], $migrator->apply());
    $state['again'] = [];
    foreach (Extensions::migrators($db, $dir . '/' . $driver . '.lock') as $migrator) $state['again'] = array_merge($state['again'], $migrator->apply());
    $state['ext_status'] = array_map(static fn(Migrator $m): array => $m->status(), Extensions::migrators($db, $dir . '/' . $driver . '.lock'));
    $state['core_status'] = $core($root . '/migrations')->status();
    $state['core_rows'] = (int)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE name LIKE '%acme%'")->fetchColumn();
    $state['ext_rows'] = $db->query('SELECT name FROM extension_migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    $state['after'] = $tables();
    // Core inserts that do not know the extension column keep working.
    $db->exec("INSERT INTO categories (store_id, slug, name, path) VALUES ('default', 'ed-cat-2', 'ED', 'ed-cat-2')");
    $category = (int)$db->lastInsertId();
    $db->exec("INSERT INTO products (store_id, sku, slug, name, category_id, price, stock, status) VALUES ('default', 'ED-2', 'ed-2', 'ED 2', {$category}, 100, 1, 'active')");
    $state['badge'] = $db->query("SELECT ext_acme_shop_badge FROM products WHERE sku = 'ED-2'")->fetchColumn();
} elseif ($step === 'tampered') {
    Extensions::boot(json_decode((string)file_get_contents($dir . '/config.json'), true), $dir . '/extensions');
    try {
        Extensions::migrators($db, $dir . '/' . $driver . '.lock')['acme-shop']->apply();
        $state['error'] = null;
    } catch (Throwable $e) {
        $state['error'] = $e->getMessage();
    }
    $state['core'] = $core($root . '/migrations')->apply();
}
echo "\nEDSTATE" . json_encode($state);
PHP);
$edRun = static function(string $driver, string $step, string $target) use ($edFixture, $edRoot): array {
    $process = proc_open([PHP_BINARY, $edFixture, $driver, $step, dirname(TEST_CLI_DIR), $edRoot, $target], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $state = str_contains($raw, "\nEDSTATE") ? json_decode(substr($raw, strrpos($raw, "\nEDSTATE") + 8), true) : null;
    return [$exit, $state, trim(substr($raw, 0, 400))];
};

$edDrivers = ['sqlite' => [$edRoot . '/legacy.sqlite', $edRoot . '/fresh.sqlite']];
if (getenv('TEST_MYSQL_DSN')) {
    $edSuffix = getmypid() . '_' . bin2hex(random_bytes(4));
    $edDrivers['mysql'] = ["bbe_ed_legacy_{$edSuffix}_test", "bbe_ed_fresh_{$edSuffix}_test"];
} else {
    print "  [SKIP] Generic core native migrations (set TEST_MYSQL_DSN)\n";
}
$edServer = null;
try {
    foreach ($edDrivers as $edDriver => [$edLegacy, $edFresh]) {
        [$edExit, $edState, $edRaw] = $edRun($edDriver, 'legacy', $edLegacy);
        assert_true($edExit === 0 && in_array('product_oe_numbers', $edState['before'] ?? [], true) && in_array('product_vehicles', $edState['before'] ?? [], true),
            "Generic core {$edDriver}: a database of the previous release has the auto-parts tables ({$edRaw})");
        assert_equals(['20261014120000_remove_auto_parts_tables'], $edState['applied'] ?? null, "Generic core {$edDriver}: the update runs only the removal migration");
        assert_true(!array_intersect(['product_oe_numbers', 'oe_fts', 'oe_ai', 'oe_ad'], $edState['after'] ?? ['?'])
            && in_array('product_specifications', $edState['after'] ?? [], true),
            "Generic core {$edDriver}: empty auto-parts tables (and SQLite OE search index/triggers) are dropped; generic product_specifications stays (" . json_encode($edState['after'] ?? null) . ')');
        assert_true(in_array('product_vehicles', $edState['after'] ?? [], true) && ($edState['kept_rows'] ?? null) === [['manufacturer' => 'KEEP', 'model' => 'ME']],
            "Generic core {$edDriver}: a table that still holds a shop's data is left untouched (no silent data loss)");

        [$edExit, $edState, $edRaw] = $edRun($edDriver, 'fresh', $edFresh);
        assert_true($edExit === 0 && ($edState['after'] ?? null) === ['product_specifications'],
            "Generic core {$edDriver}: a fresh install ends without any auto-parts table (" . json_encode($edState['after'] ?? $edRaw) . ')');

        [$edExit, $edState, $edRaw] = $edRun($edDriver, 'extensions', $edFresh);
        assert_true($edExit === 0 && ($edState['runners'] ?? null) === ['acme-shop', 'acme-two'] && str_contains((string)($edState['errors']['bad-route'] ?? ''), 'invalid page name "Cart Page"'),
            "Generic core {$edDriver}: enabled extensions with migrations/ get a runner; a bad route skips its extension ({$edRaw})");
        assert_equals(['acme-shop/20261014130000_notes', 'acme-shop/20261014131000_product_badge', 'acme-two/20261014130000_notes'], $edState['applied'] ?? null,
            "Generic core {$edDriver}: extension migrations run in config order, named <code>/<file>; equal file names in two extensions do not collide");
        assert_equals([], $edState['again'] ?? null, "Generic core {$edDriver}: extension migrations run once");
        assert_true(($edState['ext_rows'] ?? null) === $edState['applied'] && ($edState['core_rows'] ?? null) === 0
            && ($edState['core_status']['pending'] ?? null) === [] && ($edState['core_status']['missing'] ?? null) === []
            && ($edState['ext_status']['acme-shop']['missing'] ?? null) === [] && ($edState['ext_status']['acme-two']['missing'] ?? null) === [],
            "Generic core {$edDriver}: extension migrations are recorded in extension_migrations only; core and per-extension status stay clean (" . json_encode([$edState['core_status']['missing'] ?? null, $edState['ext_status'] ?? null]) . ')');
        assert_true(array_intersect(['ext_acme_shop_notes', 'ext_acme_two_flags'], $edState['after'] ?? []) === ['ext_acme_shop_notes', 'ext_acme_two_flags'] && array_key_exists('badge', $edState) && $edState['badge'] === null,
            "Generic core {$edDriver}: an extension adds its own tables and a nullable column to a core table; core inserts keep working");

        $edMigration = $edExt . '/acme-shop/migrations/20261014130000_notes.php';
        $edOriginal = (string)file_get_contents($edMigration);
        file_put_contents($edMigration, $edOriginal . "// edited after it ran\n");
        [, $edState, $edRaw] = $edRun($edDriver, 'tampered', $edFresh);
        file_put_contents($edMigration, $edOriginal);
        assert_true(str_contains((string)($edState['error'] ?? ''), 'acme-shop/20261014130000_notes was modified after being applied') && ($edState['core'] ?? null) === [],
            "Generic core {$edDriver}: an applied extension migration that changed stops its run like a core one; core migrations are unaffected ({$edRaw})");
    }

    // Real HTTP entrypoints (PHP built-in server) on the migrated SQLite shop with the extensions enabled.
    $edRouter = $edRoot . '/router.php';
    $edAppRoot = dirname(TEST_CLI_DIR);
    file_put_contents($edRouter, '<?php
require ' . var_export($edAppRoot . '/src/autoload.php', true) . ';
Db::init(new PDO(' . var_export('sqlite:' . $edRoot . '/fresh.sqlite', true) . ', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
Security::setRateDbForTesting(new PDO("sqlite::memory:"));
UrlAliases::setRules([]);
(new ReflectionProperty(Security::class, "cfg"))->setValue(null, ["app_url" => "https://shop.example"] + (require ' . var_export($edAppRoot . '/config.php', true) . '));
Extensions::boot(' . var_export($edConfig, true) . ', ' . var_export($edExt, true) . ');
chdir(' . var_export($edAppRoot, true) . ');
$script = ltrim((string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/") ?: "index.php";
if (!preg_match("/^[a-z]+\\.php$/", $script) || !is_file($script)) { http_response_code(404); exit; }
require $script;
');
    $edProbe = stream_socket_server('tcp://127.0.0.1:0');
    $edPort = (int)substr(strrchr((string)stream_socket_get_name($edProbe, false), ':'), 1);
    fclose($edProbe);
    $edServer = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$edPort}", $edRouter], [0 => ['pipe', 'r'], 1 => ['file', $edRoot . '/server.log', 'a'], 2 => ['file', $edRoot . '/server.log', 'a']], $edPipes, $edAppRoot);
    $edGet = static function(string $target) use ($edPort): array {
        for ($i = 0; $i < 50; $i++) {
            $body = @file_get_contents("http://127.0.0.1:{$edPort}{$target}", false, stream_context_create(['http' => ['ignore_errors' => true, 'header' => "Host: shop.example\r\n", 'timeout' => 20]]));
            if ($body !== false) return [(int)(explode(' ', (string)($http_response_header[0] ?? ''))[1] ?? 0), (string)$body];
            usleep(100000);
        }
        return [0, ''];
    };
    [$edStatus, $edBody] = $edGet('/?page=acme-shop.hello&raw=' . rawurlencode('<script>alert(1)</script>'));
    assert_true($edStatus === 200 && str_contains($edBody, '<p class="acme-hello">&lt;script&gt;alert(1)&lt;/script&gt;</p>') && str_contains($edBody, 'Acme hello page')
        && !str_contains($edBody, '<script>alert(1)'),
        'Generic core HTTP: /?page=<code>.<name> renders the extension page inside the theme layout, escaped (' . $edStatus . ' ' . substr(strip_tags($edBody), 0, 160) . ')');
    [$edStatus, $edBody] = $edGet('/api.php?action=acme-shop.notes');
    assert_equals(['notes' => 0, 'badge_column' => true], json_decode($edBody, true), 'Generic core HTTP: /api.php?action=<code>.<name> returns the extension action as JSON (' . $edStatus . ')');
    foreach (['vehicle_manufacturers', 'oe_search', 'product_vehicles', 'bad-route.ok'] as $edAction) {
        [$edStatus, $edBody] = $edGet('/api.php?action=' . $edAction);
        assert_equals(['error' => 'Unknown action'], json_decode($edBody, true), "Generic core HTTP: API action {$edAction} does not exist ({$edStatus})");
    }
    foreach (['vehicle&ktype=1', 'oe&q=1K0', 'acme-shop.unknown', 'bad-route.ok'] as $edPage) {
        [$edStatus, $edBody] = $edGet('/?page=' . $edPage);
        // Storefront core audit: an unknown page is a real 404 page, not the homepage answered with 200.
        assert_true($edStatus === 404 && !preg_match('/Fatal error|Uncaught|TypeError/', $edBody) && !str_contains($edBody, 'leaked'),
            "Generic core HTTP: /?page={$edPage} is not a route any more and answers a 404 page ({$edStatus})");
    }
} finally {
    if (is_resource($edServer)) {
        proc_terminate($edServer);
        proc_close($edServer);
    }
    if (isset($edDrivers['mysql'])) foreach ($edDrivers['mysql'] as $edDatabase) $edRun('mysql', 'teardown', $edDatabase);
    $edTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($edRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($edTree as $edPath) $edPath->isDir() ? @rmdir($edPath->getPathname()) : @unlink($edPath->getPathname());
    @rmdir($edRoot);
}
