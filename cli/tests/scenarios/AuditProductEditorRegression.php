<?php

echo "\n--- Audit A12.1/A12.2: admin product editor (Unicode slug, brand, GTIN/MPN, extra categories, SEO, translations, image order/alt/delete) ---\n";

// Slugs transliterate Central European letters instead of dropping them.
foreach ([
    'Košeľa Žltá' => 'kosela-zlta',
    'Łódź Wrocław Źdźbło' => 'lodz-wroclaw-zdzblo',
    'Őrség Űrlap' => 'orseg-urlap',
    'Größe & Maße' => 'grosse-masse',
    '  --plain-ascii 42-- ' => 'plain-ascii-42',
] as $a121Input => $a121Expected) {
    assert_equals($a121Expected, Security::slug($a121Input), "A12.1 slug: '{$a121Input}' transliterates");
}
assert_equals('kosela-zlta', Import::slugify('Košeľa Žltá'), 'A12.1 slug: CSV import uses the same transliteration');

$a121Root = test_fixture_path('admin-product-editor');
mkdir($a121Root);
$a121Fixture = $a121Root . '/request.php';
$a121Source = <<<'PHP'
<?php
[, $driver, $phase, $store, $action, $root, $target] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Catalog.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
final class A121Pdo extends PDO {
    public int $depth = 0;
    public function beginTransaction(): bool {
        if ($this->depth++ === 0) return parent::beginTransaction();
        $this->exec('SAVEPOINT a121_' . $this->depth);
        return true;
    }
    public function commit(): bool {
        if (--$this->depth === 0) return parent::commit();
        $this->exec('RELEASE SAVEPOINT a121_' . ($this->depth + 1));
        return true;
    }
    public function rollBack(): bool {
        if (--$this->depth === 0) return parent::rollBack();
        $this->exec('ROLLBACK TO SAVEPOINT a121_' . ($this->depth + 1));
        return true;
    }
    public function inTransaction(): bool { return $this->depth > 0; }
}
$db = $driver === 'mysql'
    ? new A121Pdo(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new A121Pdo('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
$uploads = dirname(__FILE__) . '/uploads';
Adapters::set('storage', new LocalStorage(['uploads_dir' => $uploads, 'uploads_url' => '/uploads']));
$idOf = static function(string $table, string $column, string $value) use ($db): int {
    $stmt = $db->prepare("SELECT id FROM {$table} WHERE {$column} = ?");
    $stmt->execute([$value]);
    return (int)$stmt->fetchColumn();
};
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate-' . $driver . '.lock'))->apply();
    foreach (['default', 'cz'] as $owner) {
        foreach (['1', '2'] as $n) {
            $db->prepare('INSERT INTO categories (store_id, slug, name, path, depth) VALUES (?, ?, ?, ?, 0)')
                ->execute([$owner, "a121c{$n}-{$owner}", "A121C{$n}-{$owner}-NAME", "a121c{$n}-{$owner}"]);
        }
        $db->prepare('INSERT INTO brands (store_id, code, name) VALUES (?, ?, ?)')->execute([$owner, "a121b-{$owner}", "A121B-{$owner}-NAME"]);
        $category = $idOf('categories', 'slug', "a121c1-{$owner}");
        $db->prepare("INSERT INTO products (store_id, category_id, brand_id, gtin, mpn, sku, slug, name, price, stock, status, summary, translations, images, image_alts)
            VALUES (?, ?, ?, '96385074', ?, ?, ?, ?, 1000, 5, 'active', 'Base summary', ?, ?, ?)")
            ->execute([$owner, $category, $idOf('brands', 'code', "a121b-{$owner}"), "A121M-{$owner}", "A121X-{$owner}", "a121x-{$owner}", "A121X-{$owner}-NAME",
                json_encode(['en' => ['name' => "EN-X-{$owner}", 'slug' => "taken-{$owner}", 'seo_title' => "EN-SEO-{$owner}", 'meta_description' => "EN-META-{$owner}",
                    'image_alts' => ["/uploads/products/a122-own-{$owner}.jpg" => "SEED-EN-ALT-{$owner}"]]]),
                json_encode(["/uploads/products/a122-own-{$owner}.jpg", '/uploads/products/a122-shared.jpg', 'https://cdn.example/a122.jpg']),
                json_encode(["/uploads/products/a122-own-{$owner}.jpg" => "SEED-ALT-{$owner}"])]);
        $product = $idOf('products', 'sku', "A121X-{$owner}");
        $db->prepare('INSERT INTO product_categories (product_id, category_id, is_primary, sort_order) VALUES (?, ?, 1, 0), (?, ?, 0, 1)')
            ->execute([$product, $category, $product, $idOf('categories', 'slug', "a121c2-{$owner}")]);
        $db->prepare("INSERT INTO slug_registry (slug, locale, entity, entity_id, store_id) VALUES (?, 'en', 'product', ?, ?)")
            ->execute(["taken-{$owner}", $product, $owner]);
        // A12.3 variants: V1 legacy stock, V2 ordered, V3 ledgered with a reservation, V4 unused with a price-list price.
        $db->exec($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS = 0' : 'PRAGMA foreign_keys = OFF');
        $variant = $db->prepare('INSERT INTO variants (product_id, sku, name, attrs, price_delta, stock, sort_order) VALUES (?, ?, ?, ?, 0, ?, ?)');
        foreach ([[1, 'Red', '{"color":"red"}', 3], [2, 'Ordered', '{}', 0], [3, 'Ledgered', '{}', 3], [4, 'Unused', '{}', 0]] as [$n, $name, $attrs, $stock]) {
            $variant->execute([$product, "A123V{$n}-{$owner}", $name, $attrs, $stock, $n - 1]);
        }
        $db->prepare("INSERT INTO order_items (order_id, product_id, variant_id, sku, name, quantity, unit_price, total) VALUES (990001, ?, ?, ?, 'Ordered', 1, 1000, 1000)")
            ->execute([$product, $idOf('variants', 'sku', "A123V2-{$owner}"), "A123V2-{$owner}"]);
        $db->prepare('INSERT INTO price_list_prices (price_list_id, product_id, variant_id, price) VALUES (990001, ?, ?, 900)')->execute([$product, $idOf('variants', 'sku', "A123V4-{$owner}")]);
        $db->prepare("INSERT INTO warehouses (code, name, is_default, store_id) VALUES (?, 'WH', 1, ?)")->execute(["a123wh-{$owner}", $owner]);
        $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved, store_id) VALUES (?, ?, 4, 1, ?)')
            ->execute([$idOf('warehouses', 'code', "a123wh-{$owner}"), "A123V3-{$owner}", $owner]);
        $db->exec($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS = 1' : 'PRAGMA foreign_keys = ON');
    }
    exit(0);
}

if (!is_dir($uploads . '/products')) mkdir($uploads . '/products', 0777, true);
foreach (['a122-own-default.jpg', 'a122-own-default-thumb.jpg', 'a122-own-default-thumb.webp', 'a122-own-cz.jpg', 'a122-own-cz-thumb.jpg', 'a122-own-cz-thumb.webp', 'a122-shared.jpg'] as $file) {
    file_put_contents($uploads . '/products/' . $file, 'img');
}
$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['default'], ['id'=>'cz', 'url_prefix'=>'/cz', 'domains'=>[], 'catalog_scope'=>'cz', 'provider_scope'=>'cz']);
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('sk');
$foreign = $store === 'default' ? 'cz' : 'default';
$own = $store;
$_SESSION = ['admin_role'=>'owner', 'admin_user'=>'a121'];
$snapshot = static function() use ($db, $uploads): array {
    return [
        'files' => array_map('basename', glob($uploads . '/products/*') ?: []),
        'products' => $db->query('SELECT * FROM products ORDER BY id')->fetchAll(),
        'product_categories' => $db->query('SELECT product_id, category_id, is_primary, sort_order FROM product_categories ORDER BY product_id, sort_order, category_id')->fetchAll(),
        'slug_registry' => $db->query('SELECT slug, locale, entity, entity_id, store_id FROM slug_registry ORDER BY slug, locale')->fetchAll(),
        'variants' => $db->query('SELECT product_id, sku, name, attrs, price_absolute, price_delta, stock, availability, inventory_policy, sort_order FROM variants ORDER BY sku')->fetchAll(),
        'movements' => $db->query('SELECT sku, movement_type, quantity_delta FROM stock_movements ORDER BY id')->fetchAll(),
        'warehouse_stock' => $db->query('SELECT sku, qty, reserved FROM warehouse_stock ORDER BY sku')->fetchAll(),
        'price_list_prices' => $db->query('SELECT variant_id, price FROM price_list_prices ORDER BY id')->fetchAll(),
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
$_POST = [];
$_FILES = [];
$category = static fn(string $n, string $owner): int => $idOf('categories', 'slug', "a121c{$n}-{$owner}");
$brand = static fn(string $owner): int => $idOf('brands', 'code', "a121b-{$owner}");
$existing = $idOf('products', 'sku', "A121X-{$own}");
$post = static fn(array $overrides = []): array => array_replace(['sku'=>"A121N-{$own}", 'name'=>'Košeľa Žltá', 'slug'=>'', 'category_id'=>(string)$category('1', $own),
    'product_type'=>'simple', 'price'=>'100', 'compare_price'=>'', 'stock'=>'0', 'stock_low_threshold'=>'', 'stock_critical_threshold'=>'', 'status'=>'active',
    'summary'=>'', 'description'=>'', 'images'=>'', 'weight_g'=>'0', 'tags'=>'', 'brand_id'=>'', 'gtin'=>'', 'mpn'=>'', 'seo_title'=>'', 'meta_description'=>''], $overrides);
$full = static fn(array $overrides = []): array => $post(array_replace([
    'name'=>'A121 full product', 'slug'=>"a121n-{$own}", 'brand_id'=>(string)$brand($own), 'gtin'=>' 4006381333931 ', 'mpn'=>'A121-MPN', 'seo_title'=>'SK SEO title', 'meta_description'=>'SK meta description',
    'extra_category_ids'=>[(string)$category('2', $own)],
    'tr'=>['en'=>['name'=>'Yellow Shirt', 'slug'=>'', 'summary'=>'EN summary', 'description'=>'<p>EN body</p><script>alert(1)</script>', 'seo_title'=>'EN SEO title', 'meta_description'=>'EN meta']],
], $overrides));
$edit = static fn(array $overrides = []): array => $post(array_replace(['sku'=>"A121X-{$own}", 'name'=>"A121X-{$own}-NAME", 'slug'=>"a121x-{$own}", 'stock'=>'5', 'price'=>'1000',
    'summary'=>'Base summary', 'brand_id'=>(string)$brand($own), 'gtin'=>'96385074', 'mpn'=>"A121M-{$own}", 'extra_category_ids'=>[(string)$category('2', $own)],
    'images'=>"/uploads/products/a122-own-{$own}.jpg | SEED-ALT-{$own}\n/uploads/products/a122-shared.jpg\nhttps://cdn.example/a122.jpg"], $overrides));
// The posted variant table as the editor renders it, with per-SKU field overrides and extra rows ('new' or foreign ids).
$vtable = static function(array $overrides = [], array $extraRows = []) use ($existing): array {
    $table = [];
    foreach (ProductVariants::forEditor($existing) as $v) {
        $table[(string)$v['id']] = array_replace(['sku'=>$v['sku'], 'name'=>$v['name'], 'attrs'=>$v['attrs_text'], 'price_absolute'=>$v['price_absolute'] === null ? '' : (string)$v['price_absolute'],
            'price_delta'=>(string)$v['price_delta'], 'stock'=>(string)$v['stock'], 'availability'=>$v['availability'], 'inventory_policy'=>$v['inventory_policy'], 'sort_order'=>(string)$v['sort_order']], $overrides[$v['sku']] ?? []);
    }
    return ['variants' => $table + $extraRows];
};
$newVariant = static fn(string $sku): array => ['new' => ['sku'=>$sku, 'name'=>'New', 'attrs'=>'size=XL', 'price_absolute'=>'', 'price_delta'=>'250', 'stock'=>'2', 'availability'=>'active', 'inventory_policy'=>'inherit', 'sort_order'=>'4']];
match ($action) {
    'variants_page' => [$_GET = ['id'=>$existing], pageProduct()],
    'variants_edit' => [$_GET = ['id'=>$existing], $extra = static function() use ($existing, $own): array {
        $new = (int)(array_column(Products::variants($existing), 'id', 'sku')["A123N-{$own}"] ?? 0);
        return ['purchasable' => [$new > 0 && VariantAvailability::isPurchasable($existing, $new)], 'storefront' => array_column(Products::variants($existing), 'sku')];
    }, $_POST = $vtable(["A123V1-{$own}" => ['name'=>'Red L', 'attrs'=>"color=red\nsize = L", 'price_absolute'=>'1200', 'stock'=>'7'], "A123V3-{$own}" => ['stock'=>'10']], $newVariant("A123N-{$own}")), handleProductVariantsSave()],
    'variants_delete' => [$_GET = ['id'=>$existing], $_POST = $vtable(["A123V4-{$own}" => ['delete'=>'1']]), handleProductVariantsSave()],
    'variants_delete_ordered' => [$_GET = ['id'=>$existing], $_POST = $vtable(["A123V2-{$own}" => ['delete'=>'1']]), handleProductVariantsSave()],
    'variants_rename_ordered' => [$_GET = ['id'=>$existing], $_POST = $vtable(["A123V2-{$own}" => ['sku'=>"A123V2B-{$own}"]]), handleProductVariantsSave()],
    'variants_rename_ledgered' => [$_GET = ['id'=>$existing], $_POST = $vtable(["A123V3-{$own}" => ['sku'=>"A123V3B-{$own}"]]), handleProductVariantsSave()],
    'variants_sku_product' => [$_GET = ['id'=>$existing], $_POST = $vtable([], $newVariant("A121X-{$foreign}")), handleProductVariantsSave()],
    'variants_sku_duplicate' => [$_GET = ['id'=>$existing], $_POST = $vtable([], $newVariant("A123V1-{$own}")), handleProductVariantsSave()],
    'variants_negative_stock' => [$_GET = ['id'=>$existing], $_POST = $vtable(["A123V1-{$own}" => ['stock'=>'-1']]), handleProductVariantsSave()],
    'variants_foreign_row' => [$_GET = ['id'=>$existing], $_POST = $vtable([], [(string)$idOf('variants', 'sku', "A123V1-{$foreign}") => ['sku'=>"A123V1-{$foreign}", 'name'=>'Stolen', 'stock'=>'99']]), handleProductVariantsSave()],
    'variants_foreign_product' => [$_GET = ['id'=>$idOf('products', 'sku', "A121X-{$foreign}")], $_POST = ['variants'=>['new'=>['sku'=>"A123F-{$own}", 'stock'=>'5']]], handleProductVariantsSave()],
    'create_unicode' => [$_POST = $post(), handleProductSave()],
    'create_full' => [$_POST = $full(), handleProductSave()],
    'create_foreign_brand' => [$_POST = $full(['brand_id'=>(string)$brand($foreign)]), handleProductSave()],
    'create_foreign_extra_category' => [$_POST = $full(['extra_category_ids'=>[(string)$category('2', $foreign)]]), handleProductSave()],
    'create_bad_gtin' => [$_POST = $full(['gtin'=>'4006381333932']), handleProductSave()],
    'create_taken_translation_slug' => [$_POST = $full(['tr'=>['en'=>['name'=>'Taken', 'slug'=>"taken-{$own}"]]]), handleProductSave()],
    'create_translation_slug_is_base_slug' => [$_POST = $full(['tr'=>['en'=>['name'=>'Taken', 'slug'=>"a121x-{$own}"]]]), handleProductSave()],
    'create_unknown_locale' => [$_POST = $full(['tr'=>['xx'=>['name'=>'Unknown']]]), handleProductSave()],
    'edit_page' => [$_GET = ['id'=>$existing], pageProduct()],
    'edit_clear_translation' => [$_GET = ['id'=>$existing], $_POST = $post(['sku'=>"A121X-{$own}", 'name'=>"A121X-{$own}-NAME", 'slug'=>"a121x-{$own}", 'stock'=>'5', 'price'=>'1000',
        'summary'=>'Base summary', 'brand_id'=>(string)$brand($own), 'gtin'=>'96385074', 'mpn'=>"A121M-{$own}", 'extra_category_ids'=>[], 'tr'=>['en'=>['name'=>'', 'slug'=>'', 'seo_title'=>'', 'meta_description'=>'']]]), handleProductSave()],
    // The untouched editor form as rendered for the seeded product (EN translation with its own slug).
    'edit_unchanged' => [$_GET = ['id'=>$existing], $_POST = $edit(['tr'=>['en'=>['name'=>"EN-X-{$own}", 'slug'=>"taken-{$own}", 'seo_title'=>"EN-SEO-{$own}",
        'meta_description'=>"EN-META-{$own}", 'image_alts'=>"/uploads/products/a122-own-{$own}.jpg | SEED-EN-ALT-{$own}"]]]), handleProductSave()],
    // An imported/legacy product has only category_id and no product_categories rows.
    'edit_unchanged_legacy' => [$db->prepare('DELETE FROM product_categories WHERE product_id = ?')->execute([$existing]), $_GET = ['id'=>$existing],
        $_POST = $edit(['extra_category_ids'=>[], 'tr'=>['en'=>['name'=>"EN-X-{$own}", 'slug'=>"taken-{$own}", 'seo_title'=>"EN-SEO-{$own}",
        'meta_description'=>"EN-META-{$own}", 'image_alts'=>"/uploads/products/a122-own-{$own}.jpg | SEED-EN-ALT-{$own}"]]]), handleProductSave()],
    'images_edit' => [$_GET = ['id'=>$existing], $extra = static function() use ($existing): array {
        $view = static function(string $locale) use ($existing): array {
            I18n::setLocale($locale);
            return array_map(static fn(array $i): array => [basename((string)$i['url']), $i['alt']], StorefrontViewModel::product(Products::byId($existing))['product']['images']);
        };
        return ['sk' => $view('sk'), 'en' => $view('en')];
    }, $_POST = $edit(['images'=>"https://cdn.example/a122.jpg | CDN alt\n/uploads/products/a122-own-{$own}.jpg | Own alt \n\n/uploads/products/a122-shared.jpg\n/uploads/products/a122-shared.jpg | dup",
        'tr'=>['en'=>['name'=>"EN-X-{$own}", 'slug'=>"taken-{$own}", 'seo_title'=>"EN-SEO-{$own}", 'meta_description'=>"EN-META-{$own}", 'image_alts'=>"/uploads/products/a122-own-{$own}.jpg | EN own alt"]]]), handleProductSave()],
    'images_delete' => [$_GET = ['id'=>$existing], $_POST = $edit(['images'=>'https://cdn.example/a122.jpg']), handleProductSave()],
    'images_rejected' => [$_GET = ['id'=>$existing], $_POST = $edit(['images'=>'https://cdn.example/a122.jpg', 'gtin'=>'96385075']), handleProductSave()],
    'images_bad_url' => [$_GET = ['id'=>$existing], $_POST = $edit(['images'=>"javascript:alert(1)\n/uploads/products/a122-shared.jpg"]), handleProductSave()],
    'images_alt_unknown' => [$_GET = ['id'=>$existing], $_POST = $edit(['tr'=>['en'=>['name'=>"EN-X-{$own}", 'image_alts'=>'https://other.example/x.jpg | Alt']]]), handleProductSave()],
    'duplicate_sku' => [$_POST = $full(['sku'=>"A121X-{$own}"]), handleProductSave()],
    'view_model' => (static function() use ($existing, $own, $foreign, &$extra): void {
        I18n::setLocale('en');
        $model = StorefrontViewModel::product(Products::byId($existing));
        $extra = ['seo' => $model['page']['seo'], 'name' => $model['product']['name'], 'lookups' => [
            (int)(Products::bySlug("taken-{$own}")['id'] ?? 0), (int)(Products::bySlugAnyStatus("taken-{$own}")['id'] ?? 0),
            Products::bySlug("taken-{$foreign}"), Products::relatedByType($existing, 'related'),
        ]];
    })(),
};
PHP;
file_put_contents($a121Fixture, $a121Source);
$a121Run = static function(array $args) use ($a121Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a121Fixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$a121Rejected = ['create_foreign_brand', 'create_foreign_extra_category', 'create_bad_gtin', 'create_taken_translation_slug', 'create_translation_slug_is_base_slug', 'create_unknown_locale',
    'images_rejected', 'images_bad_url', 'images_alt_unknown', 'duplicate_sku'];
$a121Actions = array_merge(['create_unicode', 'create_full', 'edit_page', 'edit_unchanged', 'edit_unchanged_legacy', 'edit_clear_translation', 'view_model', 'images_edit', 'images_delete'], $a121Rejected,
    ['variants_page', 'variants_edit', 'variants_delete', 'variants_delete_ordered', 'variants_rename_ordered', 'variants_rename_ledgered', 'variants_sku_product',
        'variants_sku_duplicate', 'variants_negative_stock', 'variants_foreign_row', 'variants_foreign_product']);
$a122Paths = [ProductEditor::uploadPath('Košeľa/../x', 'jpg'), ProductEditor::uploadPath('Košeľa/../x', 'jpg')];
assert_true($a122Paths[0] !== $a122Paths[1] && (bool)preg_match('#^products/Ko[A-Za-z0-9_-]*x-[0-9a-f]{16}\.jpg$#', $a122Paths[0]), 'A12.2 upload: two uploads of one SKU get distinct safe names');
$a121Drivers = ['sqlite' => $a121Root . '/editor.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a121Drivers['mysql'] = 'bbe_a121_editor_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A12.1 native product editor (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a121Drivers as $a121Driver => $a121Target) {
        [$a121Exit, , $a121Stderr] = $a121Run([$a121Driver, 'setup', 'default', 'none', dirname(TEST_CLI_DIR), $a121Target]);
        assert_equals([0, ''], [$a121Exit, $a121Stderr], "A12.1 {$a121Driver}: migrated fixture with brands, categories and a translated product per catalog");
        foreach (['default', 'cz'] as $a121Store) {
            $a121Foreign = $a121Store === 'default' ? 'cz' : 'default';
            foreach ($a121Actions as $a121Action) {
                $a121Label = "A12.1 {$a121Driver}/{$a121Store}/{$a121Action}";
                [$a121Exit, $a121Stdout, $a121Stderr] = $a121Run([$a121Driver, 'run', $a121Store, $a121Action, dirname(TEST_CLI_DIR), $a121Target]);
                assert_equals([0, ''], [$a121Exit, $a121Stderr], "{$a121Label}: actual handler exits without errors");
                $a121Result = json_decode((string)$a121Stdout, true) ?: ['output'=>'', 'before'=>[], 'after'=>[], 'flash'=>null, 'extra'=>[]];
                $a121Output = (string)$a121Result['output'];
                assert_true(!preg_match('/A121[A-Z]\d?-' . $a121Foreign . '\b/', $a121Output), "{$a121Label}: no foreign catalog data rendered");
                $a121Products = array_column($a121Result['after']['products'] ?? [], null, 'sku');
                $a121New = $a121Products["A121N-{$a121Store}"] ?? null;
                $a121Existing = $a121Products["A121X-{$a121Store}"] ?? [];
                $a121Links = static fn(array $result, int $productId): array => array_values(array_map(
                    static fn(array $row): array => [(int)$row['category_id'], (int)$row['is_primary']],
                    array_filter($result['after']['product_categories'] ?? [], static fn(array $row): bool => (int)$row['product_id'] === $productId)
                ));
                $a121Registry = static fn(array $result, int $productId): array => array_values(array_map(
                    static fn(array $row): array => [$row['slug'], $row['locale'], $row['store_id']],
                    array_filter($result['after']['slug_registry'] ?? [], static fn(array $row): bool => $row['entity'] === 'product' && (int)$row['entity_id'] === $productId)
                ));
                if (in_array($a121Action, $a121Rejected, true)) {
                    assert_true($a121New === null, "{$a121Label}: invalid post creates no product");
                    assert_equals('err', $a121Result['flash']['type'] ?? null, "{$a121Label}: admin sees an error");
                    assert_equals($a121Result['before']['slug_registry'] ?? [], $a121Result['after']['slug_registry'] ?? null, "{$a121Label}: slug registry unchanged");
                    assert_equals($a121Result['before']['products'] ?? [], $a121Result['after']['products'] ?? null, "{$a121Label}: no product row changed");
                    assert_equals($a121Result['before']['files'] ?? [], $a121Result['after']['files'] ?? null, "{$a121Label}: no image file stored or deleted");
                }
                $a123Variants = array_column($a121Result['after']['variants'] ?? [], null, 'sku');
                $a123Unchanged = static fn(array $result): bool => ($result['before']['variants'] ?? 0) === ($result['after']['variants'] ?? 1)
                    && ($result['before']['movements'] ?? 0) === ($result['after']['movements'] ?? 1) && ($result['before']['warehouse_stock'] ?? 0) === ($result['after']['warehouse_stock'] ?? 1)
                    && ($result['before']['price_list_prices'] ?? 0) === ($result['after']['price_list_prices'] ?? 1);
                $a123Errors = ['variants_delete_ordered' => 'je v objednávkach', 'variants_rename_ordered' => 'je v objednávkach', 'variants_rename_ledgered' => 'rezervovaný sklad',
                    'variants_sku_product' => 'už používa', 'variants_sku_duplicate' => 'dvakrát', 'variants_negative_stock' => 'celé čísla', 'variants_foreign_row' => 'nepatrí', 'variants_foreign_product' => 'Produkt neexistuje'];
                if (isset($a123Errors[$a121Action])) {
                    $a123Label = str_replace('A12.1', 'A12.3', $a121Label);
                    assert_equals(['err', true], [$a121Result['flash']['type'] ?? null, str_contains((string)($a121Result['flash']['msg'] ?? ''), $a123Errors[$a121Action])], "{$a123Label}: refused with a specific error");
                    assert_true($a123Unchanged($a121Result), "{$a123Label}: variants, ledger, movements and prices unchanged");
                }
                if ($a121Action === 'variants_page') {
                    foreach (['action=variants_save', "value=\"A123V1-{$a121Store}\"", 'color=red', 'name="variants[new][sku]"', 'name="csrf_token"', '<option value="discontinued"'] as $a123Needle) {
                        assert_true(str_contains($a121Output, $a123Needle), "A12.3 {$a121Driver}/{$a121Store}/variants_page: editor renders {$a123Needle}");
                    }
                    assert_true(!str_contains($a121Output, "A123V1-{$a121Foreign}"), "A12.3 {$a121Driver}/{$a121Store}/variants_page: no foreign variants");
                }
                if ($a121Action === 'variants_edit') {
                    $a123Label = "A12.3 {$a121Driver}/{$a121Store}/variants_edit";
                    $a123V1 = $a123Variants["A123V1-{$a121Store}"] ?? [];
                    $a123New = $a123Variants["A123N-{$a121Store}"] ?? [];
                    assert_equals('ok', $a121Result['flash']['type'] ?? null, "{$a123Label}: save succeeds");
                    assert_equals(['Red L', ['color'=>'red', 'size'=>'L'], 1200, 7], [$a123V1['name'] ?? null, json_decode((string)($a123V1['attrs'] ?? ''), true), (int)($a123V1['price_absolute'] ?? 0), (int)($a123V1['stock'] ?? 0)],
                        "{$a123Label}: row fields saved; legacy stock moved into the ledger and mirrored to variants.stock");
                    assert_equals([['size'=>'XL'], null, 250, 2, (int)($a121Existing['id'] ?? 0)], [json_decode((string)($a123New['attrs'] ?? ''), true), array_key_exists('price_absolute', $a123New) ? $a123New['price_absolute'] : 'missing', (int)($a123New['price_delta'] ?? 0), (int)($a123New['stock'] ?? 0), (int)($a123New['product_id'] ?? 0)],
                        "{$a123Label}: new variant created on the edited product");
                    assert_equals(10, (int)($a123Variants["A123V3-{$a121Store}"]['stock'] ?? 0), "{$a123Label}: ledgered variant shows available = qty - reserved");
                    $a123Ledger = [];
                    foreach ($a121Result['after']['warehouse_stock'] ?? [] as $a123Row) {
                        $a123Ledger[$a123Row['sku']] = [($a123Ledger[$a123Row['sku']][0] ?? 0) + (int)$a123Row['qty'], ($a123Ledger[$a123Row['sku']][1] ?? 0) + (int)$a123Row['reserved']];
                    }
                    assert_equals([[7, 0], [11, 1], [2, 0]], array_map(static fn(string $sku): array => $a123Ledger[$sku] ?? [-1, -1],
                        ["A123V1-{$a121Store}", "A123V3-{$a121Store}", "A123N-{$a121Store}"]), "{$a123Label}: stock booked in the default warehouse, reservation kept");
                    $a123Moves = array_slice($a121Result['after']['movements'] ?? [], count($a121Result['before']['movements'] ?? []));
                    assert_equals([["A123V1-{$a121Store}", 7], ["A123V3-{$a121Store}", 7], ["A123N-{$a121Store}", 2]], array_map(static fn(array $m): array => [$m['sku'], (int)$m['quantity_delta']], $a123Moves),
                        "{$a123Label}: one ledger movement per changed stock, none for unchanged rows");
                    assert_equals(['purchasable' => [true], 'storefront' => ["A123V1-{$a121Store}", "A123V2-{$a121Store}", "A123V3-{$a121Store}", "A123V4-{$a121Store}", "A123N-{$a121Store}"]], $a121Result['extra'] ?? null,
                        "{$a123Label}: storefront lists the new variant in order and it is purchasable");
                }
                if ($a121Action === 'variants_delete') {
                    $a123Label = "A12.3 {$a121Driver}/{$a121Store}/variants_delete";
                    assert_equals('ok', $a121Result['flash']['type'] ?? null, "{$a123Label}: save succeeds");
                    assert_true(!isset($a123Variants["A123V4-{$a121Store}"]) && count($a123Variants) === count($a121Result['before']['variants'] ?? []) - 1, "{$a123Label}: only the unused variant deleted");
                    assert_equals(count($a121Result['before']['price_list_prices'] ?? []) - 1, count($a121Result['after']['price_list_prices'] ?? []), "{$a123Label}: its price-list price removed");
                }
                $a122Own = "/uploads/products/a122-own-{$a121Store}.jpg";
                $a122ExistingTr = json_decode((string)($a121Existing['translations'] ?? '{}'), true) ?: [];
                if ($a121Action === 'images_edit') {
                    assert_equals('ok', $a121Result['flash']['type'] ?? null, "{$a121Label}: save succeeds");
                    assert_equals([['https://cdn.example/a122.jpg', $a122Own, '/uploads/products/a122-shared.jpg'], ['https://cdn.example/a122.jpg' => 'CDN alt', $a122Own => 'Own alt'], [$a122Own => 'EN own alt']],
                        [json_decode((string)($a121Existing['images'] ?? ''), true), json_decode((string)($a121Existing['image_alts'] ?? ''), true), $a122ExistingTr['en']['image_alts'] ?? null],
                        "{$a121Label}: line order, duplicates collapsed, base and EN alt texts stored");
                    assert_equals([
                        'sk' => [['a122.jpg', 'CDN alt'], ["a122-own-{$a121Store}.jpg", 'Own alt'], ['a122-shared.jpg', "A121X-{$a121Store}-NAME"]],
                        'en' => [['a122.jpg', 'CDN alt'], ["a122-own-{$a121Store}.jpg", 'EN own alt'], ['a122-shared.jpg', "EN-X-{$a121Store}"]],
                    ], $a121Result['extra'] ?? null, "{$a121Label}: storefront follows the order and per-locale alts, falling back to the product name");
                    assert_equals($a121Result['before']['files'] ?? [], $a121Result['after']['files'] ?? null, "{$a121Label}: no file deleted while still listed");
                }
                if ($a121Action === 'images_delete') {
                    assert_equals('ok', $a121Result['flash']['type'] ?? null, "{$a121Label}: save succeeds");
                    assert_equals([['https://cdn.example/a122.jpg'], null, false, "EN-X-{$a121Store}"],
                        [json_decode((string)($a121Existing['images'] ?? ''), true), $a121Existing['image_alts'] ?? null, isset($a122ExistingTr['en']['image_alts']), $a122ExistingTr['en']['name'] ?? null],
                        "{$a121Label}: removed images and their base/EN alts dropped, other translation kept");
                    $a122Gone = ["a122-own-{$a121Store}.jpg", "a122-own-{$a121Store}-thumb.jpg", "a122-own-{$a121Store}-thumb.webp"];
                    assert_equals(array_values(array_diff($a121Result['before']['files'] ?? [], $a122Gone)), $a121Result['after']['files'] ?? null,
                        "{$a121Label}: own upload and its variants deleted; file still used by the other store's product kept");
                }
                if ($a121Action === 'create_unicode') {
                    assert_equals(['kosela-zlta', 'Košeľa Žltá', $a121Store], [$a121New['slug'] ?? null, $a121New['name'] ?? null, $a121New['store_id'] ?? null], "{$a121Label}: empty slug is generated from the Unicode name");
                }
                if ($a121Action === 'create_full') {
                    $a121Tr = json_decode((string)($a121New['translations'] ?? '{}'), true) ?: [];
                    assert_equals(['4006381333931', 'A121-MPN', 'SK SEO title', 'SK meta description'],
                        [$a121New['gtin'] ?? null, $a121New['mpn'] ?? null, $a121New['seo_title'] ?? null, $a121New['meta_description'] ?? null], "{$a121Label}: GTIN, MPN and SEO fields stored");
                    assert_true((int)($a121New['brand_id'] ?? 0) > 0 && (int)$a121New['brand_id'] === (int)($a121Existing['brand_id'] ?? -1), "{$a121Label}: own catalog brand stored");
                    assert_equals(['name'=>'Yellow Shirt', 'slug'=>'yellow-shirt', 'summary'=>'EN summary', 'description'=>'<p>EN body</p>', 'seo_title'=>'EN SEO title', 'meta_description'=>'EN meta'],
                        $a121Tr['en'] ?? null, "{$a121Label}: EN translation stored, slug generated from the translated name, HTML sanitized");
                    $a121NewId = (int)($a121New['id'] ?? 0);
                    $a121Primary = (int)($a121New['category_id'] ?? 0);
                    $a121ExtraLinks = $a121Links($a121Result, $a121NewId);
                    assert_true(count($a121ExtraLinks) === 2 && $a121ExtraLinks[0] === [$a121Primary, 1] && $a121ExtraLinks[1][1] === 0 && $a121ExtraLinks[1][0] !== $a121Primary,
                        "{$a121Label}: primary + extra category written to product_categories");
                    assert_equals([['yellow-shirt', 'en', $a121Store]], $a121Registry($a121Result, $a121NewId), "{$a121Label}: translated slug registered for the active catalog");
                }
                if ($a121Action === 'edit_page') {
                    foreach (['name="gtin"', 'value="96385074"', "A121M-{$a121Store}", "A121B-{$a121Store}-NAME", 'name="extra_category_ids[]"', "A121C2-{$a121Store}-NAME",
                        'name="tr[en][name]"', "value=\"EN-X-{$a121Store}\"", "value=\"taken-{$a121Store}\"", "EN-META-{$a121Store}", 'name="seo_title"', 'name="meta_description"',
                        "/uploads/products/a122-own-{$a121Store}.jpg | SEED-ALT-{$a121Store}", 'name="tr[en][image_alts]"', "/uploads/products/a122-own-{$a121Store}.jpg | SEED-EN-ALT-{$a121Store}"] as $a121Needle) {
                        assert_true(str_contains($a121Output, $a121Needle), "{$a121Label}: editor renders {$a121Needle}");
                    }
                    assert_true((bool)preg_match('/<option value="\d+"[^>]*selected[^>]*>\s*A121B-' . $a121Store . '-NAME/', $a121Output), "{$a121Label}: stored brand preselected");
                    assert_true((bool)preg_match('/<option value="\d+" selected>A121C2-' . $a121Store . '-NAME/', $a121Output), "{$a121Label}: stored extra category preselected");
                }
                if ($a121Action === 'edit_unchanged' || $a121Action === 'edit_unchanged_legacy') {
                    assert_equals(['ok', 'Bez zmien.'], [$a121Result['flash']['type'] ?? null, $a121Result['flash']['msg'] ?? null], "{$a121Label}: untouched form reports no change");
                    assert_equals($a121Result['before']['products'] ?? [], $a121Result['after']['products'] ?? null, "{$a121Label}: product row (base slug, updated_at) not rewritten");
                    assert_equals($a121Result['before']['slug_registry'] ?? [], $a121Result['after']['slug_registry'] ?? null, "{$a121Label}: slug registry unchanged");
                    $a121ExistingId = (int)($a121Existing['id'] ?? 0);
                    $a121Expected = $a121Action === 'edit_unchanged' ? $a121Links(['after' => $a121Result['before']], $a121ExistingId) : [];
                    assert_equals($a121Expected, $a121Links($a121Result, $a121ExistingId), "{$a121Label}: category links not rewritten");
                }
                if ($a121Action === 'edit_clear_translation') {
                    $a121Tr = json_decode((string)($a121Existing['translations'] ?? '{}'), true) ?: [];
                    $a121ExistingId = (int)($a121Existing['id'] ?? 0);
                    assert_true(!isset($a121Tr['en']), "{$a121Label}: cleared EN translation removed");
                    assert_equals([], $a121Registry($a121Result, $a121ExistingId), "{$a121Label}: cleared translated slug unregistered");
                    assert_equals([[(int)($a121Existing['category_id'] ?? 0), 1]], $a121Links($a121Result, $a121ExistingId), "{$a121Label}: deselected extra category unlinked, primary kept");
                    assert_equals('ok', $a121Result['flash']['type'] ?? null, "{$a121Label}: save succeeds");
                }
                if ($a121Action === 'view_model') {
                    $a121Seo = $a121Result['extra']['seo'] ?? [];
                    $a121Ld = $a121Seo['json_ld'][0] ?? [];
                    assert_equals(["EN-SEO-{$a121Store}", "EN-META-{$a121Store}", "EN-X-{$a121Store}"], [$a121Seo['title'] ?? null, $a121Seo['description'] ?? null, $a121Result['extra']['name'] ?? null],
                        "{$a121Label}: storefront uses translated SEO title/description");
                    assert_equals([['@type'=>'Brand', 'name'=>"A121B-{$a121Store}-NAME"], '96385074', "A121M-{$a121Store}"], [$a121Ld['brand'] ?? null, $a121Ld['gtin8'] ?? null, $a121Ld['mpn'] ?? null],
                        "{$a121Label}: product JSON-LD carries brand, GTIN and MPN");
                    $a121ExistingId = (int)($a121Existing['id'] ?? 0);
                    assert_equals([$a121ExistingId, $a121ExistingId, null, []], $a121Result['extra']['lookups'] ?? null,
                        "{$a121Label}: translated slug resolves only in its own catalog; relation queries run on native prepares");
                }
                $a121ForeignBefore = array_values(array_filter($a121Result['before']['products'] ?? [], static fn(array $row): bool => $row['store_id'] === $a121Foreign));
                $a121ForeignAfter = array_values(array_filter($a121Result['after']['products'] ?? [], static fn(array $row): bool => $row['store_id'] === $a121Foreign));
                assert_equals($a121ForeignBefore, $a121ForeignAfter, "{$a121Label}: foreign catalog products unchanged");
            }
        }
    }
} finally {
    if (isset($a121Drivers['mysql'])) $a121Run(['mysql', 'teardown', 'default', 'none', dirname(TEST_CLI_DIR), $a121Drivers['mysql']]);
    $a121Files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a121Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a121Files as $a121File) $a121File->isDir() ? rmdir($a121File->getPathname()) : unlink($a121File->getPathname());
    rmdir($a121Root);
}
