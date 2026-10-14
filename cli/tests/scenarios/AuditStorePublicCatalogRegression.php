<?php

echo "\n--- Audit A05.4: public specification lookups and sitemap per store/catalog ---\n";
$a054Root = test_fixture_path('public-catalog-store');
mkdir($a054Root);
$a054Fixture = $a054Root . '/request.php';
$a054Source = <<<'PHP'
<?php
[, $driver, $phase, $store, $action, $root, $target] = $argv;
require $root . '/src/autoload.php';
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
    foreach (['default' => 1, 'cz' => 2] as $owner => $n) {
        $db->prepare("INSERT INTO categories (store_id, slug, name, path) VALUES (?, ?, ?, ?)")
            ->execute([$owner, "a05tc-{$owner}", "A05TC-{$owner}-NAME", "a05tc-{$owner}"]);
        $category = (int)$db->lastInsertId();
        foreach (['', 'b'] as $suffix) {
            $db->prepare("INSERT INTO products (store_id, sku, slug, name, category_id, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, 5, 'active')")
                ->execute([$owner, "A05TS{$suffix}-{$owner}", "a05tp{$suffix}-{$owner}", "A05TP{$suffix}-{$owner}-NAME", $category, 1000 * $n]);
            $product = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO product_specifications (product_id, lang, criteria_name, criteria_value, numeric_val) VALUES (?, 'en', 'A05CRIT', ?, NULL)")
                ->execute([$product, "SPEC{$suffix}-{$owner}"]);
        }
        $db->prepare('INSERT INTO pages (store_id, slug, title, body, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$owner, "a05tg-{$owner}", "A05TG-{$owner}-TITLE", '<p>x</p>', 'active']);
    }
    exit(0);
}

$stores = Bootstrap::stores();
$base = $stores['stores']['default'];
$stores['default_store'] = 'default';
$stores['stores'] = [
    'default' => array_replace($base, ['id'=>'default', 'url_prefix'=>'', 'default_locale'=>'sk', 'domains'=>[], 'catalog_scope'=>'default']),
    'cz' => array_replace($base, ['id'=>'cz', 'url_prefix'=>'/cz', 'default_locale'=>'cs', 'domains'=>[], 'catalog_scope'=>'cz']),
    'sk2' => array_replace($base, ['id'=>'sk2', 'url_prefix'=>'/sk2', 'default_locale'=>'en', 'domains'=>[], 'catalog_scope'=>'default']),
];
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('en');
$foreign = $store === 'default' ? 'cz' : 'default';
$idOf = static function(string $table, string $column, string $value) use ($db): int {
    $stmt = $db->prepare("SELECT id FROM {$table} WHERE {$column} = ?");
    $stmt->execute([$value]);
    return (int)$stmt->fetchColumn();
};
$owner = str_ends_with($action, '_foreign') ? $foreign : $store;
$product = $idOf('products', 'slug', "a05tp-{$owner}");
$category = $idOf('categories', 'slug', "a05tc-{$owner}");
$_GET = match ($action) {
    'param_own', 'param_foreign' => ['category_id'=>(string)$category],
    'specs_own', 'specs_foreign' => ['id'=>(string)$product],
    default => [],
};
$result = match ($action) {
    'param_own', 'param_foreign' => apiParametricSearch(),
    'specs_own', 'specs_foreign' => apiProductSpecs(),
    'sitemap' => Sitemap::xml('https://shop.test'),
};
print is_string($result) ? $result : json_encode($result, JSON_THROW_ON_ERROR);
PHP;
file_put_contents($a054Fixture, $a054Source);
$a054Run = static function(array $args) use ($a054Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a054Fixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
// Own-store markers that must appear in a positive lookup.
$a054OwnMarkers = [
    'param_own' => ['A05TP-%s-NAME', 'SPEC-%s', 'SPECb-%s'],
    'specs_own' => ['SPEC-%s'],
];
$a054Actions = ['param_own', 'param_foreign', 'specs_own', 'specs_foreign', 'sitemap'];
$a054Drivers = ['sqlite' => $a054Root . '/catalog.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a054Drivers['mysql'] = 'bbe_a054_catalog_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A05.4 native public catalog boundary (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a054Drivers as $a054Driver => $a054Target) {
        [$a054Exit, , $a054Stderr] = $a054Run([$a054Driver, 'setup', 'default', 'none', dirname(TEST_CLI_DIR), $a054Target]);
        assert_equals([0, ''], [$a054Exit, $a054Stderr], "A05.4 {$a054Driver}: migrated fixture with default/cz catalogs");
        foreach (['default', 'cz'] as $a054Store) {
            $a054Foreign = $a054Store === 'default' ? 'cz' : 'default';
            foreach ($a054Actions as $a054Action) {
                $a054Label = "A05.4 {$a054Driver}/{$a054Store}/{$a054Action}";
                [$a054Exit, $a054Out, $a054Stderr] = $a054Run([$a054Driver, 'run', $a054Store, $a054Action, dirname(TEST_CLI_DIR), $a054Target]);
                assert_equals([0, ''], [$a054Exit, $a054Stderr], "{$a054Label}: actual public lookup exits without errors");
                if ($a054Action === 'sitemap') {
                    $a054Present = [];
                    foreach ([
                        '/product/a05tp-default', '/category/a05tc-default', '/page/a05tg-default',
                        '/cz/product/a05tp-cz', '/cz/category/a05tc-cz', '/cz/page/a05tg-cz',
                        '/sk2/product/a05tp-default', '/sk2/category/a05tc-default',
                        '/product/a05tp-cz', '/category/a05tc-cz', '/page/a05tg-cz',
                        '/cz/product/a05tp-default', '/cz/category/a05tc-default', '/cz/page/a05tg-default',
                        '/sk2/product/a05tp-cz', '/sk2/page/a05tg-default',
                    ] as $a054Path) {
                        if (str_contains($a054Out, '"https://shop.test' . $a054Path . '"') || str_contains($a054Out, '>https://shop.test' . $a054Path . '<')) $a054Present[] = $a054Path;
                    }
                    assert_equals([
                        '/product/a05tp-default', '/category/a05tc-default', '/page/a05tg-default',
                        '/cz/product/a05tp-cz', '/cz/category/a05tc-cz', '/cz/page/a05tg-cz',
                        '/sk2/product/a05tp-default', '/sk2/category/a05tc-default',
                    ], $a054Present, "{$a054Label}: every store lists only its own catalog and pages");
                    $a054Doc = new DOMDocument();
                    assert_true(@$a054Doc->loadXML($a054Out) !== false, "{$a054Label}: sitemap stays well-formed");
                    continue;
                }
                assert_true(!str_contains($a054Out, "-{$a054Foreign}"), "{$a054Label}: no foreign catalog data disclosed");
                $a054Data = json_decode($a054Out, true, 512, JSON_THROW_ON_ERROR);
                foreach ($a054OwnMarkers[$a054Action] ?? [] as $a054Marker) {
                    assert_true(str_contains($a054Out, sprintf($a054Marker, $a054Store)), "{$a054Label}: own store data {$a054Marker} returned");
                }
                if ($a054Action === 'param_own') {
                    assert_equals(2, $a054Data['total'] ?? null, "{$a054Label}: total counts only own catalog products");
                }
                if (str_ends_with($a054Action, '_foreign')) {
                    $a054Payload = $a054Data['products'] ?? $a054Data['specifications'] ?? null;
                    assert_equals([], $a054Payload, "{$a054Label}: foreign product/category lookup is empty");
                }
            }
        }
    }
} finally {
    if (isset($a054Drivers['mysql'])) $a054Run(['mysql', 'teardown', 'default', 'none', dirname(TEST_CLI_DIR), $a054Drivers['mysql']]);
    foreach (glob($a054Root . '/*') ?: [] as $a054File) unlink($a054File);
    rmdir($a054Root);
}
