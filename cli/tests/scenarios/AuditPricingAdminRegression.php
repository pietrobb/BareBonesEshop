<?php

echo "\n--- Audit A12.5: admin promotions, coupon codes and price lists (store scope, validation, storefront effect, CSRF/role, audit) ---\n";

foreach (['owner' => true, 'manager' => true, 'editor' => false, 'fulfillment' => false, 'viewer' => false] as $a125Role => $a125Allowed) {
    $a125Session = $_SESSION ?? [];
    $_SESSION = ['admin_role' => $a125Role];
    assert_equals($a125Allowed, Security::adminCan('pricing.manage'), "A12.5 role: {$a125Role} " . ($a125Allowed ? 'may' : 'may not') . ' manage promotions and price lists');
    $_SESSION = $a125Session;
}
$a125Permissions = require dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Permissions.php';
assert_equals(['pricing.manage', 'pricing.manage'], [$a125Permissions['promotions'] ?? null, $a125Permissions['price_lists'] ?? null],
    'A12.5 role: promotion and price list pages require pricing.manage');

$a125Root = test_fixture_path('admin-pricing');
mkdir($a125Root);
$a125Fixture = $a125Root . '/request.php';
$a125Source = <<<'PHP'
<?php
[, $driver, $phase, $store, $action, $root, $target] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Catalog.php';
require $root . '/src/Entrypoints/Admin/Request.php';
require $root . '/src/Http/ApiHandlers.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
final class A125Pdo extends PDO {
    public int $depth = 0;
    public function beginTransaction(): bool {
        if ($this->depth++ === 0) return parent::beginTransaction();
        $this->exec('SAVEPOINT a125_' . $this->depth);
        return true;
    }
    public function commit(): bool {
        if (--$this->depth === 0) return parent::commit();
        $this->exec('RELEASE SAVEPOINT a125_' . ($this->depth + 1));
        return true;
    }
    public function rollBack(): bool {
        if (--$this->depth === 0) return parent::rollBack();
        $this->exec('ROLLBACK TO SAVEPOINT a125_' . ($this->depth + 1));
        return true;
    }
    public function inTransaction(): bool { return $this->depth > 0; }
}
$db = $driver === 'mysql'
    ? new A125Pdo(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new A125Pdo('sqlite:' . $target);
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
    foreach (['default', 'cz'] as $owner) {
        $up = strtoupper($owner);
        $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute([$owner, "a125cat-{$owner}", "A125CAT-{$owner}-NAME", "a125cat-{$owner}"]);
        $cat = $idOf('categories', 'slug', "a125cat-{$owner}");
        $db->prepare('INSERT INTO brands (store_id, code, name, active) VALUES (?, ?, ?, 1)')->execute([$owner, "a125br-{$owner}", "A125BR-{$owner}-NAME"]);
        $db->prepare("INSERT INTO products (store_id, category_id, brand_id, sku, slug, name, price, currency, stock, status) VALUES (?, ?, ?, ?, ?, ?, 1000, 'eur', 10, 'active')")
            ->execute([$owner, $cat, $idOf('brands', 'code', "a125br-{$owner}"), "A125P-{$owner}", "a125p-{$owner}", "A125P-{$owner}-NAME"]);
        $product = $idOf('products', 'sku', "A125P-{$owner}");
        $db->prepare('INSERT INTO product_categories (product_id, category_id, is_primary) VALUES (?, ?, 1)')->execute([$product, $cat]);
        $db->prepare('INSERT INTO variants (product_id, sku, name, stock) VALUES (?, ?, ?, 5)')->execute([$product, "A125V-{$owner}", 'Variant']);
        $db->prepare('INSERT INTO customer_groups (store_id, code, name) VALUES (?, ?, ?)')->execute([$owner, "a125grp-{$owner}", "A125GRP-{$owner}-NAME"]);
        $promo = $db->prepare('INSERT INTO promotions (store_id, code, name, scope, coupon_code, priority, stacking, conditions, actions, active) VALUES (?, ?, ?, ?, ?, 100, ?, ?, ?, ?)');
        $promo->execute([$owner, "a125promo-{$owner}", "A125PROMO-{$owner}-NAME", 'catalog', null, 'exclusive', '{}', '{"type":"discount_percent","bps":5000}', 0]);
        $promo->execute([$owner, "a125live-{$owner}", "A125LIVE-{$owner}-NAME", 'cart', "A125LIVE-{$up}", 'exclusive', '{}', '{"type":"discount_fixed","amount":300}', 1]);
        $promo->execute([$owner, "a125used-{$owner}", "A125USED-{$owner}-NAME", 'cart', "A125USED-{$up}", 'exclusive', '{}', '{"type":"discount_fixed","amount":100}', 1]);
        $db->prepare("INSERT INTO orders (store_id, order_number, customer_name, customer_email, payment_method, subtotal, total) VALUES (?, ?, 'Seed', 'seed@example.test', 'card', 1000, 900)")
            ->execute([$owner, "A125-{$owner}"]);
        $db->prepare('INSERT INTO coupon_uses (coupon_code, order_id, customer_email, discount, store_id) VALUES (?, ?, ?, 100, ?)')
            ->execute(["A125USED-{$up}", $idOf('orders', 'order_number', "A125-{$owner}"), 'seed@example.test', $owner]);
        $db->prepare("INSERT INTO price_lists (store_id, code, name, currency, customer_group_id, priority, active) VALUES (?, ?, ?, 'EUR', NULL, 0, 1)")
            ->execute([$owner, "a125pl-{$owner}", "A125PL-{$owner}-NAME"]);
        $db->prepare('INSERT INTO price_list_prices (price_list_id, product_id, variant_id, min_qty, price) VALUES (?, ?, NULL, 1, 900)')
            ->execute([$idOf('price_lists', 'code', "a125pl-{$owner}"), $product]);
    }
    exit(0);
}

$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['default'], ['id'=>'cz', 'url_prefix'=>'/cz', 'domains'=>[], 'catalog_scope'=>'cz', 'provider_scope'=>'cz', 'languages'=>['sk', 'en']]);
$stores['stores']['default']['languages'] = ['sk', 'en'];
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('sk');
Cache::setDir(dirname(__FILE__) . '/cache');
Pricing::setConfig(['cart_rules' => [['type'=>'coupon', 'code'=>'JSONCODE', 'name'=>'JSON coupon', 'active'=>true, 'discount_type'=>'fixed', 'discount_value'=>100]]]);
$foreign = $store === 'default' ? 'cz' : 'default';
$own = $store;
$up = strtoupper($own);
$_SESSION = ['admin_role'=>'manager', 'admin_user'=>'a125', 'csrf_token'=>'a125-token'];
$snapshot = static function() use ($db): array {
    return [
        'promotions' => $db->query('SELECT id, store_id, code, name, scope, coupon_code, priority, stacking, conditions, actions, valid_from, valid_to, active FROM promotions ORDER BY id')->fetchAll(),
        'price_lists' => $db->query('SELECT id, store_id, code, name, currency, customer_group_id, priority, valid_from, valid_to, active FROM price_lists ORDER BY id')->fetchAll(),
        'price_list_prices' => $db->query('SELECT pp.price_list_id, pl.store_id, pp.product_id, pp.variant_id, pp.min_qty, pp.price, pp.compare_price
            FROM price_list_prices pp LEFT JOIN price_lists pl ON pl.id = pp.price_list_id ORDER BY pp.price_list_id, pp.product_id, pp.min_qty')->fetchAll(),
        'audit' => $db->query("SELECT entity, entity_id, action, store_id FROM audit_log WHERE entity IN ('promotion', 'price_list') ORDER BY id")->fetchAll(),
    ];
};
// Guest product detail as the storefront serves it; a cached copy with a stale price proves the save marks it stale.
$product = $idOf('products', 'sku', "A125P-{$own}");
$storefront = static function() use ($own): array {
    $_GET = ['slug' => "a125p-{$own}"];
    $guest = $_SESSION;
    $_SESSION = [];
    $result = apiProduct();
    $_SESSION = $guest;
    return ['storefront_price' => (int)($result['product']['price'] ?? -1)];
};
$db->beginTransaction();
Cache::writeJson(Cache::localePath('sk', "product/a125p-{$own}.json"), ['product' => ['price' => 1]]);
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
$p = static fn(string $kind, ?string $owner = null): int => $idOf('promotions', 'code', "a125{$kind}-" . ($owner ?? $own));
$pl = static fn(?string $owner = null): int => $idOf('price_lists', 'code', 'a125pl-' . ($owner ?? $own));
$cat = static fn(?string $owner = null): string => (string)$idOf('categories', 'slug', 'a125cat-' . ($owner ?? $own));
$group = static fn(?string $owner = null): string => (string)$idOf('customer_groups', 'code', 'a125grp-' . ($owner ?? $own));
$promo = static fn(array $fields): array => array_replace(['csrf_token'=>'a125-token', 'name'=>'', 'code'=>'', 'scope'=>'catalog', 'coupon_code'=>'', 'priority'=>'', 'stacking'=>'exclusive',
    'valid_from'=>'', 'valid_to'=>'', 'active'=>'1', 'action_type'=>'discount_percent', 'action_value'=>'', 'action_sku'=>'', 'action_qty'=>'', 'cond_min_qty'=>'', 'cond_product_skus'=>'',
    'cond_min_subtotal'=>'', 'cond_buy_sku'=>'', 'cond_buy_qty'=>'', 'cond_max_uses'=>'', 'cond_per_customer'=>''], $fields);
$list = static fn(array $fields): array => array_replace(['csrf_token'=>'a125-token', 'name'=>'', 'code'=>'', 'currency'=>'EUR', 'customer_group_id'=>'', 'priority'=>'0', 'valid_from'=>'', 'valid_to'=>'', 'active'=>'1'], $fields);
$prices = static fn(string $lines): array => ['csrf_token'=>'a125-token', 'prices'=>$lines];
$coupons = static function() use ($db, $own, $up): array {
    $code = "JESEN-{$up}";
    $results = [Pricing::applyCoupon(strtolower($code), 2000, 'a@example.test'), Pricing::applyCoupon($code, 400, 'a@example.test')];
    $db->prepare("INSERT INTO orders (store_id, order_number, customer_name, customer_email, payment_method, subtotal, total) VALUES (?, ?, 'A', 'a@example.test', 'card', 2000, 1800)")->execute([$own, "A125-NEW-{$own}"]);
    $order = (int)$db->query("SELECT id FROM orders WHERE order_number = 'A125-NEW-{$own}'")->fetchColumn();
    Pricing::recordCouponUse($code, $order, 'a@example.test', 200);
    $results[] = Pricing::applyCoupon($code, 2000, 'b@example.test');
    try {
        Pricing::recordCouponUse($code, $order, 'b@example.test', 200);
        $results[] = 'claimed';
    } catch (RuntimeException $e) {
        $results[] = 'refused';
    }
    return ['coupons' => $results];
};
match ($action) {
    'promotions_page' => [$_GET = ['id'=>$p('promo')], pagePromotions()],
    'price_lists_page' => [$_GET = ['id'=>$pl()], pagePriceLists()],
    'promo_catalog_create' => [$extra = static fn(): array => $storefront() + ['category_id' => (int)$cat()], $_POST = $promo(['name'=>'Jesenná Zľava', 'action_value'=>'12,5', 'cond_category_ids'=>[$cat()], 'valid_from'=>'2000-01-01', 'valid_to'=>'2099-12-31']),
        handlePost('promotions', 'save')],
    'promo_coupon_create' => [$extra = $coupons, $_POST = $promo(['name'=>'Jeseň', 'scope'=>'cart', 'coupon_code'=>"jesen-{$own}", 'action_value'=>'10', 'cond_min_subtotal'=>'500', 'cond_max_uses'=>'1']),
        handlePost('promotions', 'save')],
    'promo_buy_x_get_y' => [$extra = static fn(): array => ['cart' => array_column(Promotions::cartActions([['product_id'=>$product, 'qty'=>3, 'price'=>1000]], 3000), 'discount', 'code')],
        $_POST = $promo(['name'=>'Kúp 2', 'scope'=>'cart', 'action_type'=>'buy_x_get_y', 'action_sku'=>"A125P-{$own}", 'action_qty'=>'1', 'cond_buy_sku'=>"A125P-{$own}", 'cond_buy_qty'=>'2']),
        handlePost('promotions', 'save')],
    'promo_edit' => [$_GET = ['id'=>$p('promo')], $_POST = array_diff_key($promo(['name'=>'Upravená', 'code'=>"a125promo-{$own}", 'action_value'=>'50', 'cond_brand_ids'=>[(string)$idOf('brands', 'code', "a125br-{$own}")]]), ['active'=>1]),
        handlePost('promotions', 'save')],
    'promo_delete' => [$_POST = ['csrf_token'=>'a125-token', 'promotion_id'=>(string)$p('promo')], handlePost('promotions', 'delete')],
    'coupon_store_scope' => [$extra = static fn(): array => ['own' => Pricing::applyCoupon("A125LIVE-{$up}", 1000)['discount'],
        'foreign' => Pricing::applyCoupon('A125LIVE-' . strtoupper($foreign), 1000)['error']]],
    'promo_foreign_category' => [$_POST = $promo(['name'=>'X', 'action_value'=>'10', 'cond_category_ids'=>[$cat($foreign)]]), handlePost('promotions', 'save')],
    'promo_foreign_sku' => [$_POST = $promo(['name'=>'X', 'action_value'=>'10', 'cond_product_skus'=>"A125P-{$foreign}"]), handlePost('promotions', 'save')],
    'promo_foreign_group' => [$_POST = $promo(['name'=>'X', 'action_value'=>'10', 'cond_customer_group_ids'=>[$group($foreign)]]), handlePost('promotions', 'save')],
    'promo_coupon_on_catalog' => [$_POST = $promo(['name'=>'X', 'action_value'=>'10', 'coupon_code'=>'X12']), handlePost('promotions', 'save')],
    'promo_coupon_json' => [$_POST = $promo(['name'=>'X', 'scope'=>'cart', 'action_value'=>'10', 'coupon_code'=>'jsoncode']), handlePost('promotions', 'save')],
    'promo_coupon_dup' => [$_POST = $promo(['name'=>'X', 'scope'=>'cart', 'action_value'=>'10', 'coupon_code'=>"a125live-{$own}"]), handlePost('promotions', 'save')],
    'promo_bad_percent' => [$_POST = $promo(['name'=>'X', 'action_value'=>'150']), handlePost('promotions', 'save')],
    'promo_bad_dates' => [$_POST = $promo(['name'=>'X', 'action_value'=>'10', 'valid_from'=>'2030-01-01T00:00', 'valid_to'=>'2029-01-01T00:00']), handlePost('promotions', 'save')],
    'promo_fixed_unscoped' => [$_POST = $promo(['name'=>'X', 'action_type'=>'fixed_price', 'action_value'=>'100']), handlePost('promotions', 'save')],
    'promo_cart_product_cond' => [$_POST = $promo(['name'=>'X', 'scope'=>'cart', 'action_value'=>'10', 'cond_min_qty'=>'2']), handlePost('promotions', 'save')],
    'promo_limits_no_coupon' => [$_POST = $promo(['name'=>'X', 'scope'=>'cart', 'action_value'=>'10', 'cond_max_uses'=>'5']), handlePost('promotions', 'save')],
    'promo_edit_foreign' => [$_GET = ['id'=>$p('promo', $foreign)], $_POST = $promo(['name'=>'Stolen', 'action_value'=>'10']), handlePost('promotions', 'save')],
    'promo_delete_used' => [$_POST = ['csrf_token'=>'a125-token', 'promotion_id'=>(string)$p('used')], handlePost('promotions', 'delete')],
    'promo_delete_foreign' => [$_POST = ['csrf_token'=>'a125-token', 'promotion_id'=>(string)$p('promo', $foreign)], handlePost('promotions', 'delete')],
    'promo_array' => [$_POST = $promo(['name'=>['X']]), handlePost('promotions', 'save')],
    'promo_no_csrf' => [$_POST = array_diff_key($promo(['name'=>'X', 'action_value'=>'10']), ['csrf_token'=>1]), handlePost('promotions', 'save')],
    'pricelist_create' => [$_POST = $list(['name'=>'Veľkoobchod', 'currency'=>'eur', 'customer_group_id'=>$group(), 'priority'=>'5', 'valid_to'=>'2099-12-31']), handlePost('price_lists', 'save')],
    'pricelist_edit' => [$_GET = ['id'=>$pl()], $_POST = array_diff_key($list(['name'=>'Premenovaný', 'code'=>"a125pl-{$own}"]), ['active'=>1]), handlePost('price_lists', 'save')],
    'pricelist_prices' => [$extra = $storefront, $_GET = ['id'=>$pl()], $_POST = $prices("A125P-{$own} | 1 | 800\n\nA125V-{$own} | 5 | 700 | 900"), handlePost('price_lists', 'prices')],
    'pricelist_delete' => [$_POST = ['csrf_token'=>'a125-token', 'price_list_id'=>(string)$pl()], handlePost('price_lists', 'delete')],
    'pricelist_bad_currency' => [$_POST = $list(['name'=>'X', 'currency'=>'XYZ']), handlePost('price_lists', 'save')],
    'pricelist_foreign_group' => [$_POST = $list(['name'=>'X', 'customer_group_id'=>$group($foreign)]), handlePost('price_lists', 'save')],
    'pricelist_dup_code' => [$_POST = $list(['name'=>'X', 'code'=>"a125pl-{$own}"]), handlePost('price_lists', 'save')],
    'pricelist_edit_foreign' => [$_GET = ['id'=>$pl($foreign)], $_POST = $list(['name'=>'Stolen']), handlePost('price_lists', 'save')],
    'pricelist_prices_foreign_sku' => [$_GET = ['id'=>$pl()], $_POST = $prices("A125P-{$foreign} | 1 | 800"), handlePost('price_lists', 'prices')],
    'pricelist_prices_dup' => [$_GET = ['id'=>$pl()], $_POST = $prices("A125P-{$own} | 1 | 800\nA125P-{$own} | 1 | 700"), handlePost('price_lists', 'prices')],
    'pricelist_prices_compare' => [$_GET = ['id'=>$pl()], $_POST = $prices("A125P-{$own} | 1 | 800 | 500"), handlePost('price_lists', 'prices')],
    'pricelist_prices_bad_line' => [$_GET = ['id'=>$pl()], $_POST = $prices("A125P-{$own} | 1"), handlePost('price_lists', 'prices')],
    'pricelist_prices_foreign_list' => [$_GET = ['id'=>$pl($foreign)], $_POST = $prices("A125P-{$own} | 1 | 800"), handlePost('price_lists', 'prices')],
    'pricelist_delete_foreign' => [$_POST = ['csrf_token'=>'a125-token', 'price_list_id'=>(string)$pl($foreign)], handlePost('price_lists', 'delete')],
};
PHP;
file_put_contents($a125Fixture, $a125Source);
$a125Run = static function(array $args) use ($a125Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a125Fixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
// Refused actions: expected flash fragment; promotions, price lists, their prices and the audit log must stay byte-identical.
$a125Refused = [
    'promo_foreign_category' => 'nepatrí do katalógu', 'promo_foreign_sku' => 'nie je produkt', 'promo_foreign_group' => 'nepatrí do tohto obchodu',
    'promo_coupon_on_catalog' => 'len promo akcia košíka', 'promo_coupon_json' => 'pricing.json', 'promo_coupon_dup' => 'už má iná promo akcia', 'promo_bad_percent' => 'Zľava v %',
    'promo_bad_dates' => 'neskôr ako platnosť od', 'promo_fixed_unscoped' => 'obmedzená', 'promo_cart_product_cond' => 'len promo akcia katalógu',
    'promo_limits_no_coupon' => 'kupónovým kódom', 'promo_edit_foreign' => 'neexistuje', 'promo_delete_used' => 'nemožno zmazať', 'promo_delete_foreign' => 'neexistuje',
    'promo_array' => 'Neplatný formulár', 'promo_no_csrf' => 'CSRF',
    'pricelist_bad_currency' => 'Mena musí byť', 'pricelist_foreign_group' => 'nepatrí do tohto obchodu', 'pricelist_dup_code' => 'už v tomto obchode existuje',
    'pricelist_edit_foreign' => 'neexistuje', 'pricelist_prices_foreign_sku' => 'nie je produkt ani variant', 'pricelist_prices_dup' => 'dvakrát',
    'pricelist_prices_compare' => 'pôvodná cena', 'pricelist_prices_bad_line' => 'zadajte SKU', 'pricelist_prices_foreign_list' => 'neexistuje', 'pricelist_delete_foreign' => 'neexistuje',
];
$a125Actions = array_merge(['promotions_page', 'price_lists_page', 'promo_catalog_create', 'promo_coupon_create', 'promo_buy_x_get_y', 'promo_edit', 'promo_delete', 'coupon_store_scope',
    'pricelist_create', 'pricelist_edit', 'pricelist_prices', 'pricelist_delete'], array_keys($a125Refused));
$a125Drivers = ['sqlite' => $a125Root . '/pricing.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a125Drivers['mysql'] = 'bbe_a125_pricing_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A12.5 native promotions/price lists (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a125Drivers as $a125Driver => $a125Target) {
        [$a125Exit, , $a125Stderr] = $a125Run([$a125Driver, 'setup', 'default', 'none', dirname(TEST_CLI_DIR), $a125Target]);
        assert_equals([0, ''], [$a125Exit, $a125Stderr], "A12.5 {$a125Driver}: migrated fixture with a product, variant, group, promotions, coupons and a price list per store");
        foreach (['default', 'cz'] as $a125Store) {
            $a125Foreign = $a125Store === 'default' ? 'cz' : 'default';
            $a125Up = strtoupper($a125Store);
            foreach ($a125Actions as $a125Action) {
                $a125Label = "A12.5 {$a125Driver}/{$a125Store}/{$a125Action}";
                [$a125Exit, $a125Stdout, $a125Stderr] = $a125Run([$a125Driver, 'run', $a125Store, $a125Action, dirname(TEST_CLI_DIR), $a125Target]);
                assert_equals([0, ''], [$a125Exit, $a125Stderr], "{$a125Label}: actual handler exits without errors");
                $a125Result = json_decode((string)$a125Stdout, true) ?: ['output'=>'', 'before'=>[], 'after'=>[], 'flash'=>null, 'extra'=>[]];
                $a125Output = (string)$a125Result['output'];
                $a125Flash = $a125Result['flash'] ?? [];
                $a125Own = static fn(array $rows, string $store): array => array_values(array_filter($rows, static fn(array $r): bool => $r['store_id'] === $store));
                $a125Promos = array_column($a125Own($a125Result['after']['promotions'] ?? [], $a125Store), null, 'code');
                $a125Lists = array_column($a125Own($a125Result['after']['price_lists'] ?? [], $a125Store), null, 'code');
                $a125Prices = $a125Own($a125Result['after']['price_list_prices'] ?? [], $a125Store);
                $a125NewAudit = array_slice($a125Result['after']['audit'] ?? [], count($a125Result['before']['audit'] ?? []));
                assert_true(!preg_match('/A125[A-Z]+-' . $a125Foreign . '-NAME/', $a125Output), "{$a125Label}: no foreign store data rendered");
                foreach (['promotions', 'price_lists', 'price_list_prices'] as $a125Table) {
                    assert_equals($a125Own($a125Result['before'][$a125Table] ?? [], $a125Foreign), $a125Own($a125Result['after'][$a125Table] ?? [], $a125Foreign), "{$a125Label}: foreign store {$a125Table} unchanged");
                }
                if (isset($a125Refused[$a125Action])) {
                    assert_equals(['err', true], [$a125Flash['type'] ?? null, str_contains((string)($a125Flash['msg'] ?? ''), $a125Refused[$a125Action])],
                        "{$a125Label}: refused with a specific error (" . ($a125Flash['msg'] ?? '') . ')');
                    assert_equals($a125Result['before'], $a125Result['after'], "{$a125Label}: promotions, price lists, prices and audit log unchanged");
                    continue;
                }
                if (!in_array($a125Action, ['promotions_page', 'price_lists_page', 'coupon_store_scope'], true)) {
                    assert_equals('ok', $a125Flash['type'] ?? null, "{$a125Label}: save succeeds (" . ($a125Flash['msg'] ?? '') . ')');
                    assert_equals([$a125Store], array_values(array_unique(array_column($a125NewAudit, 'store_id'))), "{$a125Label}: change audited in the active store");
                }
                $a125Json = static fn(string $code, string $column): mixed => json_decode((string)($a125Promos[$code][$column] ?? 'null'), true);
                match ($a125Action) {
                    'promotions_page' => assert_true(str_contains($a125Output, "value=\"A125PROMO-{$a125Store}-NAME\"") && str_contains($a125Output, "A125LIVE-{$a125Up}") && str_contains($a125Output, 'name="csrf_token"')
                        && str_contains($a125Output, "a125cat-{$a125Store}"), "{$a125Label}: own promotions, coupons and category options rendered"),
                    'price_lists_page' => assert_true(str_contains($a125Output, "A125P-{$a125Store} | 1 | 900") && str_contains($a125Output, "A125GRP-{$a125Store}-NAME") && str_contains($a125Output, 'action=prices&id='),
                        "{$a125Label}: own price lines and customer groups rendered"),
                    'promo_catalog_create' => assert_equals([['type'=>'discount_percent', 'bps'=>1250], ['category_ids'=>[$a125Result['extra']['category_id'] ?? -1]],
                        '2000-01-01 00:00:00', '2099-12-31 23:59:59', 'catalog', 787],
                        [$a125Json('jesenna-zlava', 'actions'), $a125Json('jesenna-zlava', 'conditions'), substr((string)($a125Promos['jesenna-zlava']['valid_from'] ?? ''), 0, 19),
                            substr((string)($a125Promos['jesenna-zlava']['valid_to'] ?? ''), 0, 19), $a125Promos['jesenna-zlava']['scope'] ?? null, $a125Result['extra']['storefront_price'] ?? null],
                        "{$a125Label}: 12,5 % stored as 1250 bps for the own category, UTC day bounds; guest product detail shows list price 900 − 12,5 % = 787 instead of the stale cache"),
                    'promo_coupon_create' => (static function() use ($a125Promos, $a125Json, $a125Result, $a125Up, $a125Label): void {
                        assert_equals(["JESEN-{$a125Up}", 'cart', ['min_subtotal'=>500, 'max_uses'=>1], ['type'=>'discount_percent', 'bps'=>1000]],
                            [$a125Promos['jesen']['coupon_code'] ?? null, $a125Promos['jesen']['scope'] ?? null, $a125Json('jesen', 'conditions'), $a125Json('jesen', 'actions')],
                            "{$a125Label}: coupon code upper-cased on a cart promotion with min subtotal and usage limit");
                        assert_equals([['discount'=>200, 'name'=>'Jeseň', 'error'=>null, 'stacking'=>'exclusive'], 'Minimum order not met', 'Coupon expired', 'refused'],
                            [$a125Result['extra']['coupons'][0] ?? null, $a125Result['extra']['coupons'][1]['error'] ?? null, $a125Result['extra']['coupons'][2]['error'] ?? null, $a125Result['extra']['coupons'][3] ?? null],
                            "{$a125Label}: checkout redeems the admin coupon (case-insensitive), enforces min subtotal and max_uses at preview and at order");
                    })(),
                    'promo_buy_x_get_y' => assert_equals([['buy_product_id'=>$a125Json('kup-2', 'actions')['product_id'] ?? -1, 'buy_qty'=>2], 1, ['kup-2'=>1000]],
                        [$a125Json('kup-2', 'conditions'), $a125Json('kup-2', 'actions')['qty'] ?? null, $a125Result['extra']['cart'] ?? null],
                        "{$a125Label}: buy 2 get 1 on the own product; 3 in the cart → one unit free"),
                    'promo_edit' => assert_equals(['Upravená', 0, ['type'=>'discount_percent', 'bps'=>5000]], [$a125Promos["a125promo-{$a125Store}"]['name'] ?? null, (int)($a125Promos["a125promo-{$a125Store}"]['active'] ?? 1), $a125Json("a125promo-{$a125Store}", 'actions')],
                        "{$a125Label}: rename, keep inactive, brand condition"),
                    'promo_delete' => assert_equals([false, true], [isset($a125Promos["a125promo-{$a125Store}"]), isset($a125Promos["a125used-{$a125Store}"])], "{$a125Label}: only the unused promotion deleted"),
                    'coupon_store_scope' => assert_equals(['own'=>300, 'foreign'=>'Invalid coupon'], $a125Result['extra'], "{$a125Label}: the store's coupon promotion redeems, another store's code does not"),
                    'pricelist_create' => assert_equals(['EUR', 5, '2099-12-31 23:59:59', 1], [$a125Lists['velkoobchod']['currency'] ?? null, (int)($a125Lists['velkoobchod']['priority'] ?? 0),
                        substr((string)($a125Lists['velkoobchod']['valid_to'] ?? ''), 0, 19), (int)($a125Lists['velkoobchod']['active'] ?? 0)], "{$a125Label}: currency normalized, priority and UTC end of day stored"),
                    'pricelist_edit' => assert_equals(['Premenovaný', 0], [$a125Lists["a125pl-{$a125Store}"]['name'] ?? null, (int)($a125Lists["a125pl-{$a125Store}"]['active'] ?? 1)], "{$a125Label}: rename and deactivate"),
                    'pricelist_prices' => assert_equals([[[null, 1, 800, null], [true, 5, 700, 900]], ['storefront_price'=>800]],
                        [array_map(static fn(array $r): array => [$r['variant_id'] === null ? null : true, (int)$r['min_qty'], (int)$r['price'], $r['compare_price'] === null ? null : (int)$r['compare_price']], $a125Prices), $a125Result['extra']],
                        "{$a125Label}: product and variant prices replaced; guest product detail shows the new list price instead of the stale cache"),
                    'pricelist_delete' => assert_equals([false, []], [isset($a125Lists["a125pl-{$a125Store}"]), $a125Prices], "{$a125Label}: list and its prices deleted"),
                };
            }
        }
    }
} finally {
    if (isset($a125Drivers['mysql'])) $a125Run(['mysql', 'teardown', 'default', 'none', dirname(TEST_CLI_DIR), $a125Drivers['mysql']]);
    $a125Files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a125Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a125Files as $a125File) $a125File->isDir() ? rmdir($a125File->getPathname()) : unlink($a125File->getPathname());
    rmdir($a125Root);
}
