<?php

echo "\n--- Audit A12.4: admin categories and brands (catalog scope, move/cycle, sibling slug, translations, delete guards, CSRF/role, audit) ---\n";

foreach (['owner' => true, 'manager' => true, 'editor' => true, 'fulfillment' => false, 'viewer' => false] as $a124Role => $a124Allowed) {
    $a124Session = $_SESSION ?? [];
    $_SESSION = ['admin_role' => $a124Role];
    assert_equals($a124Allowed, Security::adminCan('categories.edit'), "A12.4 role: {$a124Role} " . ($a124Allowed ? 'may' : 'may not') . ' edit categories and brands');
    $_SESSION = $a124Session;
}
$a124Permissions = require dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Permissions.php';
assert_equals(['categories.edit', 'categories.edit'], [$a124Permissions['categories'] ?? null, $a124Permissions['brands'] ?? null],
    'A12.4 role: category and brand pages require categories.edit');

$a124Root = test_fixture_path('admin-taxonomy');
mkdir($a124Root);
$a124Fixture = $a124Root . '/request.php';
$a124Source = <<<'PHP'
<?php
[, $driver, $phase, $store, $action, $root, $target] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Catalog.php';
require $root . '/src/Entrypoints/Admin/Request.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
final class A124Pdo extends PDO {
    public int $depth = 0;
    public function beginTransaction(): bool {
        if ($this->depth++ === 0) return parent::beginTransaction();
        $this->exec('SAVEPOINT a124_' . $this->depth);
        return true;
    }
    public function commit(): bool {
        if (--$this->depth === 0) return parent::commit();
        $this->exec('RELEASE SAVEPOINT a124_' . ($this->depth + 1));
        return true;
    }
    public function rollBack(): bool {
        if (--$this->depth === 0) return parent::rollBack();
        $this->exec('ROLLBACK TO SAVEPOINT a124_' . ($this->depth + 1));
        return true;
    }
    public function inTransaction(): bool { return $this->depth > 0; }
}
$db = $driver === 'mysql'
    ? new A124Pdo(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new A124Pdo('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
$idOf = static function(string $table, string $column, string $value) use ($db): int {
    $stmt = $db->prepare("SELECT id FROM {$table} WHERE {$column} = ?");
    $stmt->execute([$value]);
    return (int)$stmt->fetchColumn();
};
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate-' . $driver . '.lock'))->apply();
    $cat = $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, ?, ?, ?)');
    // The default catalog owns a root "shoes"; the cz catalog may create its own.
    $cat->execute(['default', 'shoes', 'Shoes', null, 'shoes', 0]);
    foreach (['default', 'cz'] as $owner) {
        // A > B > C (C holds the product), D empty root, E root used by a promotion.
        $cat->execute([$owner, "a124a-{$owner}", "A124A-{$owner}-NAME", null, "a124a-{$owner}", 0]);
        $a = $idOf('categories', 'slug', "a124a-{$owner}");
        $cat->execute([$owner, "a124b-{$owner}", "A124B-{$owner}-NAME", $a, "a124a-{$owner}/a124b-{$owner}", 1]);
        $b = $idOf('categories', 'slug', "a124b-{$owner}");
        $cat->execute([$owner, "a124c-{$owner}", "A124C-{$owner}-NAME", $b, "a124a-{$owner}/a124b-{$owner}/a124c-{$owner}", 2]);
        foreach (['d', 'e'] as $n) $cat->execute([$owner, "a124{$n}-{$owner}", 'A124' . strtoupper($n) . "-{$owner}-NAME", null, "a124{$n}-{$owner}", 0]);
        foreach (['used', 'unused', 'promo'] as $kind) {
            $db->prepare('INSERT INTO brands (store_id, code, name, active) VALUES (?, ?, ?, 1)')->execute([$owner, "a124br-{$kind}-{$owner}", "A124BR-{$kind}-{$owner}-NAME"]);
        }
        $db->prepare("INSERT INTO products (store_id, category_id, brand_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, 1000, 1, 'active')")
            ->execute([$owner, $idOf('categories', 'slug', "a124c-{$owner}"), $idOf('brands', 'code', "a124br-used-{$owner}"), "A124P-{$owner}", "a124p-{$owner}", "A124P-{$owner}-NAME"]);
        $db->prepare("INSERT INTO promotions (store_id, code, name, conditions, actions) VALUES (?, ?, 'Promo', ?, '{}')")
            ->execute([$owner, "a124promo-{$owner}", json_encode(['category_ids' => [$idOf('categories', 'slug', "a124e-{$owner}")], 'brand_ids' => [$idOf('brands', 'code', "a124br-promo-{$owner}")]])]);
    }
    Import::recountCategories($db);
    exit(0);
}

$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['default'], ['id'=>'cz', 'url_prefix'=>'/cz', 'domains'=>[], 'catalog_scope'=>'cz', 'provider_scope'=>'cz', 'languages'=>['sk', 'en']]);
$stores['stores']['default']['languages'] = ['sk', 'en'];
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('sk');
Cache::setDir(dirname(__FILE__) . '/cache');
Cache::writeJson(Cache::localePath('en', 'categories.json'), ['categories' => []]);
$foreign = $store === 'default' ? 'cz' : 'default';
$own = $store;
$_SESSION = ['admin_role'=>'editor', 'admin_user'=>'a124', 'csrf_token'=>'a124-token'];
$snapshot = static function() use ($db): array {
    return [
        'categories' => $db->query('SELECT id, store_id, slug, name, parent_id, path, depth, sort_order, product_count, translations FROM categories ORDER BY id')->fetchAll(),
        'brands' => $db->query('SELECT id, store_id, code, name, manufacturer_name, active FROM brands ORDER BY id')->fetchAll(),
        'audit' => $db->query("SELECT entity, entity_id, action, store_id FROM audit_log WHERE entity IN ('category', 'brand') ORDER BY id")->fetchAll(),
    ];
};
$db->beginTransaction();
$before = $snapshot();
$extra = [];
ob_start();
register_shutdown_function(static function() use ($db, $snapshot, $before, &$extra): void {
    $output = ob_get_contents();
    if ($extra instanceof Closure) $extra = $extra();
    ob_clean();
    $result = ['output'=>$output, 'before'=>$before, 'after'=>$snapshot(), 'flash'=>$_SESSION['flash'] ?? null, 'extra'=>$extra];
    $db->depth = 1;
    $db->rollBack();
    print json_encode($result, JSON_THROW_ON_ERROR);
    ob_end_flush();
});
$_GET = [];
$c = static fn(string $n, ?string $owner = null): int => $idOf('categories', 'slug', "a124{$n}-" . ($owner ?? $own));
$b = static fn(string $kind, ?string $owner = null): int => $idOf('brands', 'code', "a124br-{$kind}-" . ($owner ?? $own));
$cat = static fn(array $fields): array => array_replace(['csrf_token'=>'a124-token', 'name'=>'', 'slug'=>'', 'parent_id'=>'', 'sort_order'=>'0'], $fields);
$edit = static function(string $n, array $fields) use ($db, $c, $cat): array {
    $stmt = $db->prepare('SELECT name, slug, parent_id, sort_order FROM categories WHERE id = ?');
    $stmt->execute([$c($n)]);
    $row = $stmt->fetch();
    return $cat(array_replace(['name'=>$row['name'], 'slug'=>$row['slug'], 'parent_id'=>(string)($row['parent_id'] ?? ''), 'sort_order'=>(string)$row['sort_order']], $fields));
};
$brand = static fn(array $fields): array => array_replace(['csrf_token'=>'a124-token', 'code'=>'', 'name'=>'', 'manufacturer_name'=>'', 'active'=>'1'], $fields);
$staleTree = static fn(): array => ['tree_cache_stale' => Cache::readJson(Cache::localePath('en', 'categories.json'), 3600) === null];
match ($action) {
    'categories_page' => [$_GET = ['id'=>$c('b')], pageCategories()],
    'brands_page' => [$_GET = ['id'=>$b('unused')], pageBrands()],
    'cat_create_unicode' => [$extra = $staleTree, $_POST = $cat(['name'=>'Pánska Obuv Žltá', 'sort_order'=>'5', 'tr'=>['en'=>['name'=>'Men Yellow Shoes']]]), handlePost('categories', 'save')],
    'cat_create_shared_slug' => [$_POST = $cat(['name'=>'Shoes']), handlePost('categories', 'save')],
    'cat_create_child' => [$_POST = $cat(['name'=>'Nový', 'parent_id'=>(string)$c('a')]), handlePost('categories', 'save')],
    'cat_move' => [$_GET = ['id'=>$c('b')], $_POST = $edit('b', ['parent_id'=>(string)$c('d')]), handlePost('categories', 'save')],
    'cat_rename_slug' => [$_GET = ['id'=>$c('b')], $_POST = $edit('b', ['slug'=>'Nové B']), handlePost('categories', 'save')],
    'cat_cycle' => [$_GET = ['id'=>$c('a')], $_POST = $edit('a', ['parent_id'=>(string)$c('c')]), handlePost('categories', 'save')],
    'cat_self_parent' => [$_GET = ['id'=>$c('a')], $_POST = $edit('a', ['parent_id'=>(string)$c('a')]), handlePost('categories', 'save')],
    'cat_foreign_parent' => [$_POST = $cat(['name'=>'X', 'parent_id'=>(string)$c('a', $foreign)]), handlePost('categories', 'save')],
    'cat_edit_foreign' => [$_GET = ['id'=>$c('b', $foreign)], $_POST = $cat(['name'=>'Stolen']), handlePost('categories', 'save')],
    'cat_sibling_dup' => [$_POST = $cat(['name'=>'X', 'slug'=>"a124b-{$own}", 'parent_id'=>(string)$c('a')]), handlePost('categories', 'save')],
    'cat_bad_locale' => [$_POST = $cat(['name'=>'X', 'tr'=>['xx'=>['name'=>'Unknown']]]), handlePost('categories', 'save')],
    'cat_array_name' => [$_POST = $cat(['name'=>['X']]), handlePost('categories', 'save')],
    'cat_no_csrf' => [$_POST = array_diff_key($cat(['name'=>'No token']), ['csrf_token'=>1]), handlePost('categories', 'save')],
    'cat_delete_empty' => [$extra = $staleTree, $_POST = ['csrf_token'=>'a124-token', 'category_id'=>(string)$c('d')], handlePost('categories', 'delete')],
    'cat_delete_children' => [$_POST = ['csrf_token'=>'a124-token', 'category_id'=>(string)$c('a')], handlePost('categories', 'delete')],
    'cat_delete_products' => [$_POST = ['csrf_token'=>'a124-token', 'category_id'=>(string)$c('c')], handlePost('categories', 'delete')],
    'cat_delete_promo' => [$_POST = ['csrf_token'=>'a124-token', 'category_id'=>(string)$c('e')], handlePost('categories', 'delete')],
    'cat_delete_foreign' => [$_POST = ['csrf_token'=>'a124-token', 'category_id'=>(string)$c('d', $foreign)], handlePost('categories', 'delete')],
    'brand_create' => [$_POST = $brand(['name'=>'Žltá Značka', 'manufacturer_name'=>'Výrobca s.r.o.']), handlePost('brands', 'save')],
    'brand_dup_code' => [$_POST = $brand(['name'=>'Dup', 'code'=>"a124br-used-{$own}"]), handlePost('brands', 'save')],
    'brand_foreign_code' => [$_POST = $brand(['name'=>'Same code', 'code'=>"a124br-used-{$foreign}"]), handlePost('brands', 'save')],
    'brand_edit' => [$_GET = ['id'=>$b('unused')], $_POST = array_diff_key($brand(['name'=>'Renamed', 'code'=>"a124br-unused-{$own}"]), ['active'=>1]), handlePost('brands', 'save')],
    'brand_edit_foreign' => [$_GET = ['id'=>$b('unused', $foreign)], $_POST = $brand(['name'=>'Stolen']), handlePost('brands', 'save')],
    'brand_delete_used' => [$_POST = ['csrf_token'=>'a124-token', 'brand_id'=>(string)$b('used')], handlePost('brands', 'delete')],
    'brand_delete_promo' => [$_POST = ['csrf_token'=>'a124-token', 'brand_id'=>(string)$b('promo')], handlePost('brands', 'delete')],
    'brand_delete_unused' => [$_POST = ['csrf_token'=>'a124-token', 'brand_id'=>(string)$b('unused')], handlePost('brands', 'delete')],
    'brand_delete_foreign' => [$_POST = ['csrf_token'=>'a124-token', 'brand_id'=>(string)$b('unused', $foreign)], handlePost('brands', 'delete')],
    'import_scope' => (static function() use (&$extra, $db): void {
        $id = Import::ensureCategory('a124a-cz/a124b-cz');
        $stmt = $db->prepare('SELECT store_id, path, depth FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        $extra = $stmt->fetch();
    })(),
};
PHP;
file_put_contents($a124Fixture, $a124Source);
$a124Run = static function(array $args) use ($a124Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a124Fixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
// Refused actions: expected flash fragment; categories and brands must stay byte-identical.
$a124Refused = [
    'cat_cycle' => 'pod seba', 'cat_self_parent' => 'pod seba', 'cat_foreign_parent' => 'nepatrí do katalógu', 'cat_edit_foreign' => 'neexistuje',
    'cat_sibling_dup' => 'na tejto úrovni', 'cat_bad_locale' => 'nie je v tomto obchode povolený', 'cat_array_name' => 'Neplatný formulár', 'cat_no_csrf' => 'CSRF',
    'cat_delete_children' => 'podkategórie', 'cat_delete_products' => 'produkty', 'cat_delete_promo' => 'a124promo-', 'cat_delete_foreign' => 'neexistuje',
    'brand_dup_code' => 'už v tomto katalógu existuje', 'brand_edit_foreign' => 'neexistuje', 'brand_delete_used' => 'produkty', 'brand_delete_promo' => 'a124promo-',
    'brand_delete_foreign' => 'neexistuje',
];
$a124Actions = array_merge(['categories_page', 'brands_page', 'cat_create_unicode', 'cat_create_shared_slug', 'cat_create_child', 'cat_move', 'cat_rename_slug', 'cat_delete_empty',
    'brand_create', 'brand_foreign_code', 'brand_edit', 'brand_delete_unused', 'import_scope'], array_keys($a124Refused));
$a124Drivers = ['sqlite' => $a124Root . '/taxonomy.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a124Drivers['mysql'] = 'bbe_a124_taxonomy_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A12.4 native categories/brands (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a124Drivers as $a124Driver => $a124Target) {
        [$a124Exit, , $a124Stderr] = $a124Run([$a124Driver, 'setup', 'default', 'none', dirname(TEST_CLI_DIR), $a124Target]);
        assert_equals([0, ''], [$a124Exit, $a124Stderr], "A12.4 {$a124Driver}: migrated fixture with a category tree, brands, a product and a promotion per catalog");
        foreach (['default', 'cz'] as $a124Store) {
            $a124Foreign = $a124Store === 'default' ? 'cz' : 'default';
            foreach ($a124Actions as $a124Action) {
                $a124Label = "A12.4 {$a124Driver}/{$a124Store}/{$a124Action}";
                [$a124Exit, $a124Stdout, $a124Stderr] = $a124Run([$a124Driver, 'run', $a124Store, $a124Action, dirname(TEST_CLI_DIR), $a124Target]);
                assert_equals([0, ''], [$a124Exit, $a124Stderr], "{$a124Label}: actual handler exits without errors");
                $a124Result = json_decode((string)$a124Stdout, true) ?: ['output'=>'', 'before'=>[], 'after'=>[], 'flash'=>null, 'extra'=>[]];
                $a124Output = (string)$a124Result['output'];
                $a124Flash = $a124Result['flash'] ?? [];
                $a124Cats = array_column($a124Result['after']['categories'] ?? [], null, 'slug');
                $a124Own = static fn(array $rows, string $store): array => array_values(array_filter($rows, static fn(array $r): bool => $r['store_id'] === $store));
                $a124ByKey = static fn(array $rows, string $store): array => array_column($a124Own($rows, $store), null, 'slug');
                $a124Mine = $a124ByKey($a124Result['after']['categories'] ?? [], $a124Store);
                $a124Brands = array_column($a124Own($a124Result['after']['brands'] ?? [], $a124Store), null, 'code');
                $a124NewAudit = array_slice($a124Result['after']['audit'] ?? [], count($a124Result['before']['audit'] ?? []));
                assert_true(!preg_match('/A124[A-Z]+(-[a-z]+)?-' . $a124Foreign . '-NAME/', $a124Output), "{$a124Label}: no foreign catalog data rendered");
                foreach (['categories', 'brands'] as $a124Table) {
                    assert_equals($a124Own($a124Result['before'][$a124Table] ?? [], $a124Foreign), $a124Own($a124Result['after'][$a124Table] ?? [], $a124Foreign), "{$a124Label}: foreign catalog {$a124Table} unchanged");
                }
                // The default catalog already owns the root "shoes"; there the same create is a sibling conflict.
                $a124RefusedHere = $a124Refused + ($a124Store === 'default' ? ['cat_create_shared_slug' => 'na tejto úrovni'] : []);
                if (isset($a124RefusedHere[$a124Action])) {
                    assert_equals(['err', true], [$a124Flash['type'] ?? null, str_contains((string)($a124Flash['msg'] ?? ''), $a124RefusedHere[$a124Action])], "{$a124Label}: refused with a specific error");
                    assert_equals($a124Result['before'], $a124Result['after'], "{$a124Label}: categories, brands and audit log unchanged");
                    continue;
                }
                if (!in_array($a124Action, ['categories_page', 'brands_page', 'import_scope'], true)) {
                    assert_equals('ok', $a124Flash['type'] ?? null, "{$a124Label}: save succeeds (" . ($a124Flash['msg'] ?? '') . ')');
                    assert_equals([$a124Store], array_values(array_unique(array_column($a124NewAudit, 'store_id'))), "{$a124Label}: change audited in the active store");
                }
                $a124Path = static fn(string $slug): array => [$a124Mine[$slug]['path'] ?? null, (int)($a124Mine[$slug]['depth'] ?? -1)];
                match ($a124Action) {
                    'categories_page' => (static function() use ($a124Output, $a124Store, $a124Label, $a124Result): void {
                        foreach (['action=save&id=', 'name="csrf_token"', "value=\"A124B-{$a124Store}-NAME\"", 'name="tr[en][name]"', "a124a-{$a124Store}/a124b-{$a124Store}", 'action=delete'] as $needle) {
                            assert_true(str_contains($a124Output, $needle), "{$a124Label}: renders {$needle}");
                        }
                        $parentOptions = preg_match('#<select id="cat-parent"[^>]*>(.*?)</select>#s', $a124Output, $m) ? $m[1] : '';
                        assert_true(str_contains($parentOptions, "A124D-{$a124Store}-NAME") && !str_contains($parentOptions, "A124B-{$a124Store}-NAME") && !str_contains($parentOptions, "A124C-{$a124Store}-NAME"),
                            "{$a124Label}: parent list offers other categories but not the edited one or its subtree");
                    })(),
                    'brands_page' => assert_true(str_contains($a124Output, "value=\"A124BR-unused-{$a124Store}-NAME\"") && str_contains($a124Output, "a124br-used-{$a124Store}") && str_contains($a124Output, 'name="csrf_token"'),
                        "{$a124Label}: brand list and edit form of the own catalog rendered"),
                    'cat_create_unicode' => assert_equals([['panska-obuv-zlta', 0, null, 5, ['en'=>['name'=>'Men Yellow Shoes']]], ['tree_cache_stale'=>true]],
                        [[$a124Mine['panska-obuv-zlta']['path'] ?? null, (int)($a124Mine['panska-obuv-zlta']['depth'] ?? -1), array_key_exists('parent_id', $a124Mine['panska-obuv-zlta'] ?? []) ? $a124Mine['panska-obuv-zlta']['parent_id'] : 'missing',
                            (int)($a124Mine['panska-obuv-zlta']['sort_order'] ?? 0), json_decode((string)($a124Mine['panska-obuv-zlta']['translations'] ?? ''), true)], $a124Result['extra']],
                        "{$a124Label}: Unicode name → ASCII slug root category with EN translation; storefront tree cache marked stale"),
                    'cat_create_shared_slug' => assert_equals(['shoes', 0, $a124Store === 'cz' ? 2 : 1], [$a124Mine['shoes']['path'] ?? null, (int)($a124Mine['shoes']['depth'] ?? -1), count(array_filter($a124Result['after']['categories'] ?? [], static fn(array $r): bool => $r['slug'] === 'shoes'))],
                        "{$a124Label}: a slug used by another catalog is free in this one"),
                    'cat_create_child' => assert_equals(["a124a-{$a124Store}/novy", 1], $a124Path('novy'), "{$a124Label}: child gets parent path and depth"),
                    'cat_move' => (static function() use ($a124Path, $a124Mine, $a124Store, $a124Label): void {
                        assert_equals([["a124d-{$a124Store}/a124b-{$a124Store}", 1], ["a124d-{$a124Store}/a124b-{$a124Store}/a124c-{$a124Store}", 2]],
                            [$a124Path("a124b-{$a124Store}"), $a124Path("a124c-{$a124Store}")], "{$a124Label}: moved category and its descendants get new path/depth");
                        assert_equals([0, 1, 1], array_map(static fn(string $s): int => (int)($a124Mine[$s]['product_count'] ?? -1), ["a124a-{$a124Store}", "a124d-{$a124Store}", "a124b-{$a124Store}"]),
                            "{$a124Label}: product counts of old and new ancestors recounted");
                    })(),
                    'cat_rename_slug' => assert_equals([["a124a-{$a124Store}/nove-b", 1], ["a124a-{$a124Store}/nove-b/a124c-{$a124Store}", 2]], [$a124Path('nove-b'), $a124Path("a124c-{$a124Store}")],
                        "{$a124Label}: slug change rewrites the descendants' paths"),
                    'cat_delete_empty' => assert_equals([false, true, ['tree_cache_stale'=>true]], [isset($a124Mine["a124d-{$a124Store}"]), isset($a124Mine["a124a-{$a124Store}"]), $a124Result['extra']],
                        "{$a124Label}: only the empty category deleted; tree cache marked stale"),
                    'brand_create' => assert_equals(['Žltá Značka', 'Výrobca s.r.o.', 1], [$a124Brands['zlta-znacka']['name'] ?? null, $a124Brands['zlta-znacka']['manufacturer_name'] ?? null, (int)($a124Brands['zlta-znacka']['active'] ?? 0)],
                        "{$a124Label}: code generated from the Unicode name"),
                    'brand_foreign_code' => assert_true(isset($a124Brands["a124br-used-{$a124Foreign}"]), "{$a124Label}: a code used by another catalog is free in this one"),
                    'brand_edit' => assert_equals(['Renamed', 0], [$a124Brands["a124br-unused-{$a124Store}"]['name'] ?? null, (int)($a124Brands["a124br-unused-{$a124Store}"]['active'] ?? 1)], "{$a124Label}: rename and deactivate"),
                    'brand_delete_unused' => assert_equals([false, true], [isset($a124Brands["a124br-unused-{$a124Store}"]), isset($a124Brands["a124br-used-{$a124Store}"])], "{$a124Label}: only the unused brand deleted"),
                    'import_scope' => assert_equals([$a124Store, 'a124a-cz/a124b-cz', 1], [$a124Result['extra']['store_id'] ?? null, $a124Result['extra']['path'] ?? null, (int)($a124Result['extra']['depth'] ?? -1)],
                        "{$a124Label}: CSV import creates or reuses its category in the active catalog, never another catalog's"),
                };
            }
        }
    }
} finally {
    if (isset($a124Drivers['mysql'])) $a124Run(['mysql', 'teardown', 'default', 'none', dirname(TEST_CLI_DIR), $a124Drivers['mysql']]);
    $a124Files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a124Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a124Files as $a124File) $a124File->isDir() ? rmdir($a124File->getPathname()) : unlink($a124File->getPathname());
    rmdir($a124Root);
}
