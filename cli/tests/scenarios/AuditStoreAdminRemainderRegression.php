<?php

echo "\n--- Audit A05.7: remaining admin store boundaries (resend, audit, GDPR, dashboard, customers, catalog editor) ---\n";
$a057Root = test_fixture_path('admin-remainder-store');
mkdir($a057Root);
$a057Fixture = $a057Root . '/request.php';
$a057Source = <<<'PHP'
<?php
[, $driver, $phase, $store, $action, $root, $target] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Catalog.php';
require $root . '/src/Entrypoints/Admin/Customers.php';
require $root . '/src/Entrypoints/Admin/Operations.php';
require $root . '/src/Entrypoints/Admin/Orders.php';
class_exists(Security::class);
// Resend handlers are rate limited per log/invoice id; each request gets a private limiter so reruns stay deterministic.
$a057Rate = new PDO('sqlite::memory:');
$a057Rate->exec('CREATE TABLE hits (key TEXT NOT NULL, ts INTEGER NOT NULL)');
(new ReflectionProperty(Security::class, 'rateDb'))->setValue(null, $a057Rate);
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
// Handlers open their own transactions; savepoints let the whole request roll back afterwards.
final class A057Pdo extends PDO {
    public int $depth = 0;
    public function beginTransaction(): bool {
        if ($this->depth++ === 0) return parent::beginTransaction();
        $this->exec('SAVEPOINT a057_' . $this->depth);
        return true;
    }
    public function commit(): bool {
        if (--$this->depth === 0) return parent::commit();
        $this->exec('RELEASE SAVEPOINT a057_' . ($this->depth + 1));
        return true;
    }
    public function rollBack(): bool {
        if (--$this->depth === 0) return parent::rollBack();
        $this->exec('ROLLBACK TO SAVEPOINT a057_' . ($this->depth + 1));
        return true;
    }
    public function inTransaction(): bool { return $this->depth > 0; }
}
$db = $driver === 'mysql'
    ? new A057Pdo(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new A057Pdo('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}`");
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
    $db->exec("INSERT INTO attribute_definitions (name, slug, type, sort_order) VALUES ('A057 shared', 'a057-shared', 'enum', 1)");
    $shared = $idOf('attribute_definitions', 'slug', 'a057-shared');
    foreach (['default', 'cz'] as $owner) {
        $db->prepare('INSERT INTO categories (store_id, slug, name, path, depth) VALUES (?, ?, ?, ?, 0)')
            ->execute([$owner, "a057c-{$owner}", "A057C-{$owner}-NAME", "a057c-{$owner}"]);
        $category = $idOf('categories', 'slug', "a057c-{$owner}");
        $db->prepare('INSERT INTO category_attributes (category_id, attribute_id) VALUES (?, ?)')->execute([$category, $shared]);
        foreach (['P', 'Q'] as $kind) {
            $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, 1000, 5, 'active')")
                ->execute([$owner, $category, "A057{$kind}-{$owner}", strtolower("a057{$kind}-{$owner}"), "A057{$kind}-{$owner}-NAME"]);
        }
        $product = $idOf('products', 'sku', "A057P-{$owner}");
        $db->prepare("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name) VALUES (?, 'default', ?, ?, 'file.pdf')")
            ->execute(["A057P-{$owner}", "A057D-{$owner}-LABEL", "a057/{$owner}.pdf"]);
        $db->prepare("INSERT INTO product_reviews (product_id, author_name, rating, title, body, status) VALUES (?, ?, 5, 'A057', 'A057', 'pending')")
            ->execute([$product, "A057R-{$owner}-AUTHOR"]);
        $db->prepare("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES (?, ?, '!test!', ?, 'active')")
            ->execute([$owner, "a057-{$owner}@example.test", "A057U-{$owner}-NAME"]);
        $customer = $idOf('customers', 'email', "a057-{$owner}@example.test");
        $db->prepare("INSERT INTO orders (store_id, order_number, customer_id, customer_name, customer_email, payment_method, subtotal, total, currency, status)
            VALUES (?, ?, ?, ?, ?, 'cod', 1000, 1000, 'eur', 'pending')")
            ->execute([$owner, "A057O-{$owner}", $customer, "A057O-{$owner}-CUST", "a057-{$owner}@example.test"]);
        $order = $idOf('orders', 'order_number', "A057O-{$owner}");
        $db->prepare("INSERT INTO email_log (store_id, order_id, recipient, template, locale, subject, adapter, status, payload)
            VALUES (?, ?, ?, 'order_confirmation', 'en', ?, 'log', 'sent', '{}')")
            ->execute([$owner, $order, "a057-{$owner}@example.test", "A057E-{$owner}-SUBJECT"]);
        $db->prepare("INSERT INTO invoices (store_id, order_id, number, issued_at, pdf_path, download_token) VALUES (?, ?, ?, '2026-10-12', ?, ?)")
            ->execute([$owner, $order, "A057I-{$owner}", "a057/{$owner}-invoice.pdf", hash('sha256', "a057-{$owner}")]);
        $db->prepare("INSERT INTO audit_log (store_id, entity, entity_id, action, old_value, new_value, note, actor) VALUES (?, 'order_status', ?, 'update', '', '', ?, 'admin:test')")
            ->execute([$owner, $order, "A057A-{$owner}-NOTE"]);
        $db->prepare("INSERT INTO gdpr_events (store_id, action, customer_id, actor, note) VALUES (?, 'export', ?, 'admin:test', ?)")
            ->execute([$owner, $customer, "A057G-{$owner}-NOTE"]);
    }
    // A foreign-store order that references the default customer id must not inflate its admin order count.
    $db->prepare("INSERT INTO orders (store_id, order_number, customer_id, customer_name, customer_email, payment_method, subtotal, total, currency, status)
        VALUES ('cz', 'A057X-cz', ?, 'A057X-cz-CUST', 'x@example.test', 'cod', 1, 1, 'eur', 'pending')")
        ->execute([$idOf('customers', 'email', 'a057-default@example.test')]);
    $db->prepare("INSERT INTO orders (store_id, order_number, customer_id, customer_name, customer_email, payment_method, subtotal, total, currency, status)
        VALUES ('default', 'A057X-default', ?, 'A057X-default-CUST', 'x@example.test', 'cod', 1, 1, 'eur', 'pending')")
        ->execute([$idOf('customers', 'email', 'a057-cz@example.test')]);
    exit(0);
}

$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['default'], ['id'=>'cz', 'url_prefix'=>'/cz', 'domains'=>[], 'catalog_scope'=>'cz', 'provider_scope'=>'cz']);
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('en');
$foreign = $store === 'default' ? 'cz' : 'default';
$own = $store;
$_SESSION = ['admin_role'=>'owner', 'admin_user'=>'a057'];
$tables = [
    'products' => 'SELECT id, store_id, sku, name, category_id, status FROM products ORDER BY id',
    'categories' => 'SELECT id, store_id, slug, name FROM categories ORDER BY id',
    'category_attributes' => 'SELECT c.store_id, ca.category_id, ca.attribute_id FROM category_attributes ca JOIN categories c ON c.id = ca.category_id ORDER BY ca.category_id, ca.attribute_id',
    'digital_assets' => "SELECT da.id, COALESCE(p.store_id, 'none') AS store_id, da.sku, da.asset_key, da.label, da.active FROM digital_assets da LEFT JOIN products p ON p.sku = da.sku ORDER BY da.id",
    'product_reviews' => 'SELECT r.id, p.store_id, r.status FROM product_reviews r JOIN products p ON p.id = r.product_id ORDER BY r.id',
    'product_related_products' => 'SELECT p.store_id, rp.product_id, rp.related_product_id FROM product_related_products rp JOIN products p ON p.id = rp.product_id ORDER BY rp.product_id, rp.related_product_id',
    'customers' => 'SELECT id, store_id, email, name, status FROM customers ORDER BY id',
    'email_log' => 'SELECT id, store_id, order_id, recipient, template FROM email_log ORDER BY id',
    'gdpr_events' => 'SELECT id, store_id, action, customer_id FROM gdpr_events ORDER BY id',
];
$snapshot = static function() use ($db, $tables): array {
    $rows = [];
    foreach ($tables as $table => $sql) $rows[$table] = $db->query($sql)->fetchAll();
    return $rows;
};
$db->beginTransaction();
$before = $snapshot();
ob_start();
register_shutdown_function(static function() use ($db, $snapshot, $before): void {
    $output = ob_get_contents();
    ob_clean();
    $result = ['output'=>$output, 'http_status'=>http_response_code(), 'before'=>$before, 'after'=>$snapshot(), 'flash'=>$_SESSION['flash'] ?? null];
    $db->depth = 1;
    $db->rollBack();
    print json_encode($result, JSON_THROW_ON_ERROR);
    ob_end_flush();
});
$_GET = [];
$_POST = [];
$_FILES = [];
$product = static fn(string $kind, string $owner): int => $idOf('products', 'sku', "A057{$kind}-{$owner}");
$category = static fn(string $owner): int => $idOf('categories', 'slug', "a057c-{$owner}");
$order = static fn(string $owner): int => $idOf('orders', 'order_number', "A057O-{$owner}");
$newProduct = static fn(int $categoryId): array => ['sku'=>"A057N-{$own}", 'name'=>"A057N-{$own}-NAME", 'slug'=>"a057n-{$own}", 'category_id'=>(string)$categoryId,
    'product_type'=>'simple', 'price'=>'100', 'compare_price'=>'', 'stock'=>'0', 'stock_low_threshold'=>'', 'stock_critical_threshold'=>'', 'status'=>'active',
    'summary'=>'', 'description'=>'', 'images'=>'', 'weight_g'=>'0', 'tags'=>''];
$asset = ['asset_key'=>'default', 'label'=>'HACKED-LABEL', 'storage_path'=>'a057/hacked.pdf', 'download_name'=>'hacked.pdf', 'content_type'=>'application/pdf', 'download_limit'=>'5', 'expiry_days'=>'7', 'active'=>'1'];
$shared = $idOf('attribute_definitions', 'slug', 'a057-shared');
match ($action) {
    'dashboard' => pageDashboard(),
    'audit' => pageAudit(),
    'gdpr' => [$_GET = ['email'=>'a057'], pageGdpr()],
    'customers' => pageCustomers(),
    'product_own' => [$_GET = ['id'=>$product('P', $own)], pageProduct()],
    'product_foreign' => [$_GET = ['id'=>$product('P', $foreign)], pageProduct()],
    'product_create' => [$_POST = $newProduct($category($own)), handleProductSave()],
    'product_create_foreign_category' => [$_POST = $newProduct($category($foreign)), handleProductSave()],
    'related_save_foreign' => [$_GET = ['id'=>$product('P', $own)], $_POST = ['relation_type'=>'related', 'related_product_ids'=>[$product('Q', $foreign)]], handleProductRelatedSave()],
    'related_save_foreign_product' => [$_GET = ['id'=>$product('P', $foreign)], $_POST = ['relation_type'=>'related', 'related_product_ids'=>[$product('Q', $foreign)]], handleProductRelatedSave()],
    'digital' => pageDigitalAssets(),
    'digital_save_foreign' => [$_POST = $asset + ['sku'=>"A057P-{$foreign}"], handleDigitalAssetSave()],
    'digital_save_own' => [$_POST = ['asset_key'=>'extra'] + $asset + ['sku'=>"A057Q-{$own}"], handleDigitalAssetSave()],
    'digital_toggle_foreign' => [$_POST = ['asset_id'=>$idOf('digital_assets', 'sku', "A057P-{$foreign}"), 'active'=>'0'], handleDigitalAssetToggle()],
    'reviews' => pageReviews(),
    'review_foreign' => [$_POST = ['review_id'=>$idOf('product_reviews', 'author_name', "A057R-{$foreign}-AUTHOR")], handleReviewModerate('approved')],
    'attributes' => pageAttributes(),
    'attribute_edit' => [$_GET = ['id'=>$shared], pageAttribute()],
    'attribute_save' => [$_GET = ['id'=>$shared], $_POST = ['name'=>'A057 shared', 'slug'=>'a057-shared', 'type'=>'enum', 'unit'=>'', 'sort_order'=>'1', 'category_ids'=>[(string)$category($foreign)]], handleAttributeSave()],
    'attribute_delete' => [$_POST = ['attr_id'=>$shared], handleAttributeDelete()],
    'email_resend_foreign' => [$_GET = ['id'=>$order($foreign)], $_POST = ['log_id'=>$idOf('email_log', 'subject', "A057E-{$foreign}-SUBJECT")], handleOrderEmailResend()],
    'email_resend_own' => [$_GET = ['id'=>$order($own)], $_POST = ['log_id'=>$idOf('email_log', 'subject', "A057E-{$own}-SUBJECT")], handleOrderEmailResend()],
    'invoice_resend_foreign' => [$_GET = ['id'=>$order($foreign)], $_POST = ['invoice_id'=>$idOf('invoices', 'number', "A057I-{$foreign}")], handleOrderInvoiceResend()],
    'gdpr_anonymize_foreign' => [$_POST = ['customer_id'=>$idOf('customers', 'email', "a057-{$foreign}@example.test")], handleGdprAnonymize()],
    'gdpr_anonymize_own' => [$_POST = ['customer_id'=>$idOf('customers', 'email', "a057-{$own}@example.test")], handleGdprAnonymize()],
};
PHP;
file_put_contents($a057Fixture, $a057Source);
$a057Run = static function(array $args) use ($a057Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a057Fixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
// Own-store marker each read page must still show.
$a057Shows = [
    'dashboard' => 'A057O-%s', 'audit' => 'A057A-%s-NOTE', 'gdpr' => 'A057G-%s-NOTE', 'customers' => 'A057U-%s-NAME',
    'product_own' => 'A057Q-%s-NAME', 'digital' => 'A057P-%s</td>', 'reviews' => 'A057R-%s-AUTHOR',
    'attributes' => 'A057C-%s-NAME', 'attribute_edit' => 'a057c-%s',
];
// Expected new own-store rows: [table, column, value pattern per new row...].
$a057Creates = [
    'product_create' => ['products', 'sku', 'A057N-%s'],
    'digital_save_own' => ['digital_assets', 'asset_key', 'extra'],
    'email_resend_own' => ['email_log', 'template', 'order_confirmation'],
    'gdpr_anonymize_own' => ['gdpr_events', 'action', 'erase', 'admin_anonymize'],
];
$a057Actions = array_merge(array_keys($a057Shows), array_keys($a057Creates), [
    'product_foreign', 'product_create_foreign_category', 'related_save_foreign', 'related_save_foreign_product',
    'digital_save_foreign', 'digital_toggle_foreign', 'review_foreign', 'attribute_save', 'attribute_delete',
    'email_resend_foreign', 'invoice_resend_foreign', 'gdpr_anonymize_foreign',
]);
$a057Drivers = ['sqlite' => $a057Root . '/remainder.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a057Drivers['mysql'] = 'bbe_a057_admin_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A05.7 native remaining admin store boundaries (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a057Drivers as $a057Driver => $a057Target) {
        [$a057Exit, , $a057Stderr] = $a057Run([$a057Driver, 'setup', 'default', 'none', dirname(TEST_CLI_DIR), $a057Target]);
        assert_equals([0, ''], [$a057Exit, $a057Stderr], "A05.7 {$a057Driver}: migrated fixture with default/cz stores and separate catalogs");
        foreach (['default', 'cz'] as $a057Store) {
            $a057Foreign = $a057Store === 'default' ? 'cz' : 'default';
            $a057ForeignMarker = '/(A057[A-Z]-|a057[a-z]?-)' . $a057Foreign . '\b/i';
            foreach ($a057Actions as $a057Action) {
                $a057Label = "A05.7 {$a057Driver}/{$a057Store}/{$a057Action}";
                [$a057Exit, $a057Stdout, $a057Stderr] = $a057Run([$a057Driver, 'run', $a057Store, $a057Action, dirname(TEST_CLI_DIR), $a057Target]);
                assert_equals([0, ''], [$a057Exit, $a057Stderr], "{$a057Label}: actual handler exits without errors");
                $a057Result = json_decode($a057Stdout, true, 512, JSON_THROW_ON_ERROR);
                assert_true(!preg_match($a057ForeignMarker, $a057Result['output']), "{$a057Label}: no foreign store data disclosed");
                $a057Owned = static fn(array $rows, string $owner): array => array_values(array_filter($rows, static fn(array $row): bool => $row['store_id'] === $owner));
                $a057ForeignChanged = [];
                $a057Grown = [];
                foreach ($a057Result['before'] as $a057Table => $a057Rows) {
                    if ($a057Owned($a057Rows, $a057Foreign) != $a057Owned($a057Result['after'][$a057Table], $a057Foreign)) $a057ForeignChanged[] = $a057Table;
                    if (count($a057Rows) < count($a057Result['after'][$a057Table])) $a057Grown[] = $a057Table;
                }
                assert_equals([], $a057ForeignChanged, "{$a057Label}: foreign store rows unchanged");
                if (isset($a057Shows[$a057Action])) {
                    assert_true(str_contains($a057Result['output'], sprintf($a057Shows[$a057Action], $a057Store)), "{$a057Label}: own store data still listed");
                }
                if (isset($a057Creates[$a057Action])) {
                    [$a057Table, $a057Column] = $a057Creates[$a057Action];
                    $a057New = array_values(array_udiff($a057Result['after'][$a057Table], $a057Result['before'][$a057Table], static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']));
                    $a057Expected = array_map(static fn(string $value): array => [$a057Store, sprintf($value, $a057Store)], array_slice($a057Creates[$a057Action], 2));
                    assert_equals($a057Expected, array_map(static fn(array $row): array => [$row['store_id'], $row[$a057Column]], $a057New), "{$a057Label}: new row belongs to the active store");
                } elseif (!in_array($a057Action, ['attribute_save'], true)) {
                    assert_equals([], $a057Grown, "{$a057Label}: no rows created");
                }
                if ($a057Action === 'customers') {
                    preg_match('/A057U-' . $a057Store . '-NAME<\/td>\s*<td>.*?<\/td>\s*<td>(\d+)<\/td>/s', $a057Result['output'], $a057Count);
                    assert_equals('1', $a057Count[1] ?? null, "{$a057Label}: customer order count only counts active store orders");
                }
                if ($a057Action === 'product_foreign') {
                    assert_true(!str_contains($a057Result['output'], 'name="sku"'), "{$a057Label}: foreign product id renders no editor");
                }
                if ($a057Action === 'attribute_save') {
                    $a057Links = static fn(array $rows, string $owner): int => count($a057Owned($rows, $owner));
                    assert_equals([1, 0], [$a057Links($a057Result['before']['category_attributes'], $a057Store), $a057Links($a057Result['after']['category_attributes'], $a057Store)],
                        "{$a057Label}: own catalog link replaced by submitted (foreign-only) selection");
                }
            }
        }
    }
} finally {
    if (isset($a057Drivers['mysql'])) $a057Run(['mysql', 'teardown', 'default', 'none', dirname(TEST_CLI_DIR), $a057Drivers['mysql']]);
    foreach (glob($a057Root . '/*') ?: [] as $a057File) unlink($a057File);
    rmdir($a057Root);
}
