<?php

// Admin catalog / operations review (2026-10-16): store-scoped supplier feeds and feed import modes, invoice and
// credit-note numbering per seller and document type, invoices only for paid orders, store-scoped product list,
// shared attribute definitions, audit log coverage, one SKU rule, translated slugs per catalog, per-store digital
// asset paths, escaping, price floors, array input, image cap, windowed pagination and streaming exports.
echo "\n--- Audit admin catalog: feeds, invoice numbering, catalog scope, audit log, SKUs, slugs, prices ---\n";

$aacRoot = test_fixture_path('admin-catalog');
mkdir($aacRoot);
$aacFixture = $aacRoot . '/request.php';
$aacSource = <<<'PHP'
<?php
[, $driver, $phase, $action, $root, $target] = $argv;
require $root . '/src/autoload.php';
foreach (['Shared', 'Catalog', 'Operations', 'Orders', 'Customers'] as $module) require $root . "/src/Entrypoints/Admin/{$module}.php";
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
/** Nested begin/commit become savepoints, so every action is rolled back at the end (SQLite and MySQL alike). */
final class AacPdo extends PDO {
    public int $depth = 0;
    public function beginTransaction(): bool {
        if ($this->depth++ === 0) return parent::beginTransaction();
        $this->exec('SAVEPOINT aac_' . $this->depth);
        return true;
    }
    public function commit(): bool {
        if (--$this->depth === 0) return parent::commit();
        $this->exec('RELEASE SAVEPOINT aac_' . ($this->depth + 1));
        return true;
    }
    public function rollBack(): bool {
        if (--$this->depth === 0) return parent::rollBack();
        $this->exec('ROLLBACK TO SAVEPOINT aac_' . ($this->depth + 1));
        return true;
    }
    public function inTransaction(): bool { return $this->depth > 0; }
}
$db = $driver === 'mysql'
    ? new AacPdo(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new AacPdo('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
} else {
    $db->exec('PRAGMA foreign_keys = ON');
}
Db::init($db, $driver);
$q = static function(string $sql, array $params = []) use ($db): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
};
$idOf = static fn(string $table, string $column, string $value): int => (int)($q("SELECT id FROM {$table} WHERE {$column} = ?", [$value])[0]['id'] ?? 0);
$year = (int)date('Y');

if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate-' . $driver . '.lock'))->apply();
    $category = $db->prepare('INSERT INTO categories (slug, name, path, store_id) VALUES (?, ?, ?, ?)');
    foreach (['default', 'cz'] as $store) $category->execute(["aac-cat-{$store}", "AAC {$store}", "aac-cat-{$store}", $store]);
    $product = $db->prepare('INSERT INTO products (sku, slug, name, category_id, price, status, store_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $product->execute(['AAC-DEF-1', 'aac-def-1', 'AAC default one', $idOf('categories', 'slug', 'aac-cat-default'), 350, 'active', 'default']);
    $product->execute(['AAC-DEF-2', 'aac-def-2', 'AAC default two', $idOf('categories', 'slug', 'aac-cat-default'), 1000, 'draft', 'default']);
    $product->execute(['AAC-CZ-1', 'aac-cz-1', 'AAC cz one', $idOf('categories', 'slug', 'aac-cat-cz'), 2000, 'active', 'cz']);
    $db->prepare("INSERT INTO variants (product_id, sku, name, price_delta) VALUES (?, 'AAC-DEF-1-V', 'Cheaper', -300)")->execute([$idOf('products', 'sku', 'AAC-DEF-1')]);
    $warehouse = $db->prepare('INSERT INTO warehouses (store_id, code, name, is_default) VALUES (?, ?, ?, 1)');
    $feed = $db->prepare("INSERT INTO feeds (code, name, warehouse_id, url, format, field_map, import_mode) VALUES (?, ?, ?, 'https://10.0.0.1/feed.xml', 'xml', ?, ?)");
    $map = json_encode(['SKU' => 'sku', 'NAME' => 'name', 'PRICE' => 'price', 'STOCK' => 'stock']);
    foreach (['default', 'cz'] as $store) {
        $warehouse->execute([$store, "aac-wh-{$store}", "AAC WH {$store}"]);
        $feed->execute(["aac-feed-{$store}", "AAC feed {$store}", $idOf('warehouses', 'code', "aac-wh-{$store}"), $map, 'upsert']);
    }
    $order = $db->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, locale)
        VALUES (?, ?, ?, ?, 'AAC Buyer', 'aac@example.test', 'bank_transfer', ?, ?, ?, 'sk')");
    $item = $db->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, ?, ?)');
    foreach ([['AAC-O1', 'default', 'paid', 'paid', 'eur', 'AAC-DEF-1'], ['AAC-O2', 'default', 'shipped', 'paid', 'eur', 'AAC-DEF-1'],
              ['AAC-O3', 'cz', 'paid', 'paid', 'czk', 'AAC-CZ-1'], ['AAC-O4', 'default', 'pending', 'pending', 'eur', 'AAC-DEF-1']] as [$number, $store, $status, $payment, $currency, $sku]) {
        $order->execute([$number, $store, $status, $payment, 1210, 1210, $currency]);
        $item->execute([$idOf('orders', 'order_number', $number), $idOf('products', 'sku', $sku), $sku, $sku, 1210, 1210]);
    }
    $db->exec("INSERT INTO attribute_definitions (name, slug, type) VALUES ('AAC Color', 'aac-color', 'enum'), ('AAC Size', 'aac-size', 'enum')");
    $db->prepare("INSERT INTO attribute_options (attribute_id, value, sort_order) VALUES (?, 'red', 1), (?, 'blue', 2)")->execute([$idOf('attribute_definitions', 'slug', 'aac-color'), $idOf('attribute_definitions', 'slug', 'aac-color')]);
    $link = $db->prepare('INSERT INTO category_attributes (category_id, attribute_id) VALUES (?, ?)');
    $link->execute([$idOf('categories', 'slug', 'aac-cat-default'), $idOf('attribute_definitions', 'slug', 'aac-color')]);
    $link->execute([$idOf('categories', 'slug', 'aac-cat-cz'), $idOf('attribute_definitions', 'slug', 'aac-color')]);
    $link->execute([$idOf('categories', 'slug', 'aac-cat-default'), $idOf('attribute_definitions', 'slug', 'aac-size')]);
    $db->prepare("INSERT INTO product_reviews (product_id, author_name, rating, body, status) VALUES (?, 'AAC', 5, 'ok', 'pending')")->execute([$idOf('products', 'sku', 'AAC-DEF-1')]);
    $db->exec("INSERT INTO customers (email, password_hash, name, status, store_id) VALUES ('aac-customer@example.test', '!test!', 'AAC Customer', 'active', 'default')");
    $db->exec("INSERT INTO pages (slug, title, body, status, store_id) VALUES ('aac-page', 'AAC page', 'x', 'active', 'default')");
    exit(0);
}

$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['cz'], ['catalog_scope' => 'cz', 'provider_scope' => 'cz', 'channels' => ['storefront', 'api'], 'languages' => ['cs', 'en']]);
$store = str_starts_with($action, 'cz_') ? 'cz' : 'default';
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('en');
$_SESSION = ['admin_role' => 'owner', 'admin_user' => 'aac', 'csrf_token' => 'aac-token'];
$_GET = [];
$_POST = [];
$_FILES = [];
$auditBefore = (int)$q('SELECT COUNT(*) AS n FROM audit_log')[0]['n'];
$db->beginTransaction();
$extra = static fn(): array => [];
ob_start();
register_shutdown_function(static function() use ($db, $q, $auditBefore, &$extra): void {
    $output = ob_get_contents();
    ob_clean();
    $audit = array_slice($q('SELECT entity, entity_id, note, store_id, old_value, new_value FROM audit_log ORDER BY id'), $auditBefore);
    $result = ['output' => $output, 'flash' => $_SESSION['flash'] ?? null, 'extra' => $extra(), 'audit' => $audit, 'context' => StoreContext::current()->id()];
    $db->depth = 1;
    $db->rollBack();
    print json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    ob_end_flush();
});
$feedId = static fn(string $store): int => $idOf('feeds', 'code', "aac-feed-{$store}");
$whId = static fn(string $store): int => $idOf('warehouses', 'code', "aac-wh-{$store}");
$productId = static fn(string $sku): int => $idOf('products', 'sku', $sku);
$orderId = static fn(string $number): int => $idOf('orders', 'order_number', $number);
$feeds = static fn(): array => $q('SELECT code, name, warehouse_id, import_mode FROM feeds ORDER BY code');
$feedPost = static fn(array $fields): array => array_replace(['csrf_token' => 'aac-token', 'code' => 'aac-new-feed', 'name' => 'AAC new', 'warehouse_id' => (string)$whId('default'),
    'url' => 'https://10.0.0.1/new.xml', 'format' => 'xml', 'field_map' => '{"SKU":"sku"}', 'import_mode' => 'stock_only', 'schedule' => '0 */6 * * *', 'enabled' => '1'], $fields);
$items = [['SKU' => 'AAC-DEF-1', 'NAME' => 'Overwritten', 'PRICE' => '12.00', 'STOCK' => '7'], ['SKU' => 'AAC-NEW-1', 'NAME' => 'Created', 'PRICE' => '5.00', 'STOCK' => '3'],
    ['SKU' => 'AAC-CZ-1', 'NAME' => 'Foreign', 'PRICE' => '1.00', 'STOCK' => '9'], ['SKU' => 'AAC-DEF-1-V', 'STOCK' => '4']];
$stock = static fn(): array => $q('SELECT w.code, ws.sku, ws.qty FROM warehouse_stock ws JOIN warehouses w ON w.id = ws.warehouse_id ORDER BY w.code, ws.sku');
$productRow = static fn(string $sku): ?array => $q('SELECT sku, name, price, status, store_id FROM products WHERE sku = ?', [$sku])[0] ?? null;
$productPost = static fn(string $sku, array $fields): array => array_replace(['sku' => $sku, 'name' => "{$sku} name", 'slug' => strtolower($sku), 'category_id' => (string)$idOf('categories', 'slug', 'aac-cat-default'),
    'product_type' => 'simple', 'price' => '1000', 'compare_price' => '', 'stock' => '0', 'stock_low_threshold' => '', 'stock_critical_threshold' => '', 'status' => 'active',
    'summary' => '', 'description' => '', 'images' => '', 'weight_g' => '0', 'tags' => '', 'brand_id' => '', 'gtin' => '', 'mpn' => '', 'seo_title' => '', 'meta_description' => ''], $fields);
$attrPost = static fn(array $fields): array => array_replace(['csrf_token' => 'aac-token', 'name' => 'AAC Color', 'slug' => 'aac-color', 'type' => 'enum', 'unit' => '', 'sort_order' => '0',
    'options' => ['red', 'green'], 'option_order' => ['1', '3'], 'category_ids' => [(string)$idOf('categories', 'slug', 'aac-cat-default')]], $fields);
$options = static fn(string $slug): array => array_column($q('SELECT o.value FROM attribute_options o JOIN attribute_definitions d ON d.id = o.attribute_id WHERE d.slug = ? ORDER BY o.value', [$slug]), 'value');

switch ($action) {
    case 'feeds_scope':
        $extra = static fn(): array => ['all' => array_column(Warehouse::allFeeds(), 'code'), 'foreign' => Warehouse::feedById($feedId('cz')),
            'own' => Warehouse::feedById($feedId('default'))['code'] ?? null, 'by_foreign_wh' => Warehouse::feedsByWarehouse($whId('cz')),
            'delete_foreign' => Warehouse::deleteFeed($feedId('cz')), 'sync_foreign' => FeedImport::sync($feedId('cz'))['error'] ?? null, 'feeds' => $feeds(),
            'save_foreign' => (static function() use ($feedId, $whId): string {
                try { Warehouse::saveFeed(['code' => 'aac-x', 'name' => 'x', 'warehouse_id' => $whId('cz'), 'url' => 'https://10.0.0.1/x', 'import_mode' => 'upsert']); return 'saved'; }
                catch (RuntimeException $e) { return $e->getMessage(); }
            })(),
            'save_bad_mode' => (static function() use ($whId): string {
                try { Warehouse::saveFeed(['code' => 'aac-x', 'name' => 'x', 'warehouse_id' => $whId('default'), 'url' => 'https://10.0.0.1/x', 'import_mode' => 'full']); return 'saved'; }
                catch (InvalidArgumentException $e) { return 'refused'; }
            })()];
        break;
    case 'feed_save_foreign_warehouse':
        $extra = static fn(): array => ['feeds' => $feeds(), 't' => I18n::t('admin.feed.warehouse_foreign')];
        $_POST = $feedPost(['warehouse_id' => (string)$whId('cz')]);
        handleFeedSave();
        break;
    case 'feed_edit_foreign':
        $extra = static fn(): array => ['feeds' => $feeds(), 't' => I18n::t('admin.common.record_missing')];
        $_GET = ['id' => (string)$feedId('cz')];
        $_POST = $feedPost(['code' => 'aac-feed-cz', 'name' => 'Stolen']);
        handleFeedSave();
        break;
    case 'feed_delete_foreign':
        $extra = static fn(): array => ['feeds' => $feeds(), 't' => I18n::t('admin.common.record_missing')];
        $_POST = ['csrf_token' => 'aac-token', 'feed_id' => (string)$feedId('cz')];
        handleFeedDelete();
        break;
    case 'feed_sync_foreign':
        $extra = static fn(): array => ['t' => I18n::t('admin.feed.invalid'), 'feeds' => $feeds(), 'status' => $q('SELECT code, last_status FROM feeds ORDER BY code')];
        $_POST = ['csrf_token' => 'aac-token', 'feed_id' => (string)$feedId('cz')];
        handleFeedSync();
        break;
    case 'feed_save_price_stock':
        $extra = static fn(): array => ['feeds' => $feeds(), 'form' => (static function(): string { ob_start(); $_GET = ['id' => '0']; pageFeed(); return (string)ob_get_clean(); })()];
        $_POST = $feedPost(['import_mode' => 'price_stock']);
        handleFeedSave();
        break;
    case 'feed_delete_own':
        $extra = static fn(): array => ['feeds' => $feeds()];
        $_POST = ['csrf_token' => 'aac-token', 'feed_id' => (string)$feedId('default')];
        handleFeedDelete();
        break;
    case 'feed_stock_only':
    case 'feed_price_stock':
    case 'feed_upsert':
        $mode = substr($action, 5);
        $feed = Warehouse::feedById($feedId('default'));
        $feed['import_mode'] = $mode;
        $result = FeedImport::applyItems($feed, $items);
        $extra = static fn(): array => ['result' => array_intersect_key($result, array_flip(['imported', 'skipped', 'stock_updated'])), 'errors' => count($result['errors']),
            'products' => array_map($productRow, ['AAC-DEF-1', 'AAC-NEW-1', 'AAC-CZ-1']), 'stock' => $stock()];
        break;
    case 'feed_price_floor':
        $feed = Warehouse::feedById($feedId('default'));
        $feed['import_mode'] = 'price_stock';
        $result = FeedImport::applyItems($feed, [['SKU' => 'AAC-DEF-1', 'PRICE' => '2.00', 'STOCK' => '1'], ['SKU' => 'AAC-DEF-2', 'PRICE' => '-3.00']]);
        $extra = static fn(): array => ['errors' => $result['errors'], 'products' => array_map($productRow, ['AAC-DEF-1', 'AAC-DEF-2'])];
        break;
    case 'feed_store_context':
        $inCz = FeedImport::syncInStore('cz', $feedId('cz'));
        $after = StoreContext::current()->id();
        $fromDefault = FeedImport::sync($feedId('cz'));
        $extra = static fn(): array => ['cz' => $inCz['error'] ?? null, 'after' => $after, 'from_default' => $fromDefault['error'] ?? null,
            'all_stores' => array_column(Warehouse::allFeedsAllStores(), 'store_id', 'code')];
        break;
    case 'invoices':
        $issued = [];
        foreach (['AAC-O1', 'AAC-O3', 'AAC-O2'] as $number) {
            $owner = (string)$q('SELECT store_id FROM orders WHERE order_number = ?', [$number])[0]['store_id'];
            $issued[$number] = PaymentWebhookRouting::within($owner, static fn(): string => (string)InvoiceService::issue($orderId($number))['number']);
        }
        $unpaid = (static function() use ($orderId): string {
            try { InvoiceService::issue($orderId('AAC-O4')); return 'issued'; } catch (RuntimeException $e) { return 'refused'; }
        })();
        $db->exec("INSERT INTO invoice_number_counters (store_id, type, year, prefix, last_number) VALUES ('cz', 'invoice', {$year}, 'AAC-', 5)");
        $allocate = new ReflectionMethod(InvoiceService::class, 'allocateNumber');
        $shared = (static function() use ($allocate, $db, $year): string {
            try { return (string)$allocate->invoke(null, $db, 'default', 'invoice', $year, 'AAC-'); } catch (RuntimeException $e) { return 'refused'; }
        })();
        $creditSeries = (string)$allocate->invoke(null, $db, 'default', 'credit_note', $year, 'CN-');
        $extra = static fn(): array => ['issued' => $issued, 'unpaid' => $unpaid, 'shared' => $shared, 'credit' => $creditSeries,
            'counters' => $q("SELECT store_id, type, prefix, last_number FROM invoice_number_counters WHERE year = ? ORDER BY store_id, type, prefix", [$year]),
            'list' => array_column(InvoiceService::adminList($year), 'number'), 'list_old' => InvoiceService::adminList(1999)];
        break;
    case 'invoice_issue_unpaid':
        $extra = static fn(): array => ['invoices' => $q('SELECT number FROM invoices'), 't' => I18n::t('admin.order.msg.invoice_unpaid'),
            'page' => (static function() use ($orderId): string { ob_start(); $_GET = ['id' => (string)$orderId('AAC-O4')]; pageOrder(); return (string)ob_get_clean(); })()];
        $_GET = ['id' => (string)$orderId('AAC-O4')];
        handleOrderInvoiceIssue();
        break;
    case 'invoice_migration':
        $db->exec('DELETE FROM invoice_number_counters');
        $db->exec('DELETE FROM invoice_counters');
        $db->exec("INSERT INTO invoice_counters (year, prefix, type, last_number) VALUES (2025, '', 'invoice', 7), (2025, 'CN-', 'invoice', 2), (2024, 'CZ-', 'invoice', 4), (2023, 'ZZ-', 'invoice', 0)");
        $legacy = $db->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, download_token, type) VALUES (?, ?, ?, '2025-01-01', 'x.pdf', ?, ?)");
        foreach ([['default', '20250007', 'invoice', 'AAC-O1'], ['de', '20250006', 'invoice', 'AAC-O2'], ['default', '20250005', 'invoice', 'AAC-O3'],
                  ['cz', 'CN-20250002', 'credit_note', 'AAC-O4'], ['pl', 'CN-20250001', 'credit_note', 'AAC-O4']] as $i => [$owner, $number, $type, $order]) {
            $legacy->execute([$orderId($order), $owner, $number, "aac-token-{$i}", $type]);
        }
        $migration = require $root . '/migrations/20261016140500_invoice_number_counters.php';
        $migration->up($db, $driver === 'sqlite');
        $first = $q('SELECT store_id, type, year, prefix, last_number FROM invoice_number_counters ORDER BY year, prefix');
        $migration->up($db, $driver === 'sqlite');
        $extra = static fn(): array => ['first' => $first, 'second' => $q('SELECT store_id, type, year, prefix, last_number FROM invoice_number_counters ORDER BY year, prefix'),
            'legacy' => (int)$q('SELECT COUNT(*) AS n FROM invoice_counters')[0]['n']];
        break;
    case 'products_page':
        $_GET = ['p' => 'products', 'status' => ['active'], 'q' => ['x']];
        $extra = static fn(): array => [];
        pageProducts();
        break;
    case 'cz_products_page':
        $extra = static fn(): array => [];
        pageProducts();
        break;
    case 'bulk_delete':
        $extra = static fn(): array => ['products' => array_map($productRow, ['AAC-DEF-1', 'AAC-DEF-2', 'AAC-CZ-1']), 't' => I18n::format('admin.products.bulk.delete_skipped', ['list' => 'AAC-DEF-1'])];
        $_POST = ['csrf_token' => 'aac-token', 'bulk_action' => 'delete', 'product_ids' => [(string)$productId('AAC-DEF-1'), (string)$productId('AAC-DEF-2'), (string)$productId('AAC-CZ-1')]];
        handleBulkAction();
        break;
    case 'bulk_price_floor':
        $extra = static fn(): array => ['products' => array_map($productRow, ['AAC-DEF-1', 'AAC-DEF-2', 'AAC-CZ-1'])];
        $_POST = ['csrf_token' => 'aac-token', 'bulk_action' => 'price_down_20', 'product_ids' => [(string)$productId('AAC-DEF-1'), (string)$productId('AAC-DEF-2'), (string)$productId('AAC-CZ-1')]];
        handleBulkAction();
        break;
    case 'bulk_array_input':
        $extra = static fn(): array => ['products' => array_map($productRow, ['AAC-DEF-1', 'AAC-DEF-2'])];
        $_POST = ['csrf_token' => 'aac-token', 'bulk_action' => ['delete'], 'product_ids' => 'all'];
        handleBulkAction();
        break;
    case 'bulk_nested_ids':
        $extra = static fn(): array => ['products' => array_map($productRow, ['AAC-DEF-1', 'AAC-DEF-2'])];
        $_POST = ['csrf_token' => 'aac-token', 'bulk_action' => 'archive', 'product_ids' => [['x'], (string)$productId('AAC-DEF-1')]];
        handleBulkAction();
        break;
    case 'attr_shared_type':
        $extra = static fn(): array => ['def' => $q("SELECT slug, type FROM attribute_definitions WHERE name = 'AAC Color'"), 'options' => $options('aac-color'), 't' => I18n::t('admin.attr.shared_locked')];
        $_GET = ['id' => (string)$idOf('attribute_definitions', 'slug', 'aac-color')];
        $_POST = $attrPost(['type' => 'number']);
        handleAttributeSave();
        break;
    case 'attr_shared_slug':
        $extra = static fn(): array => ['def' => $q("SELECT slug, type FROM attribute_definitions WHERE name = 'AAC Color'"), 'options' => $options('aac-color'), 't' => I18n::t('admin.attr.shared_locked')];
        $_GET = ['id' => (string)$idOf('attribute_definitions', 'slug', 'aac-color')];
        $_POST = $attrPost(['slug' => 'aac-colour']);
        handleAttributeSave();
        break;
    case 'attr_shared_options':
        $extra = static fn(): array => ['options' => $options('aac-color')];
        $_GET = ['id' => (string)$idOf('attribute_definitions', 'slug', 'aac-color')];
        $_POST = $attrPost([]);
        handleAttributeSave();
        break;
    case 'attr_own_options':
        $extra = static fn(): array => ['options' => $options('aac-size2'), 'def' => $q("SELECT slug, type FROM attribute_definitions WHERE name = 'AAC Size'")];
        $_GET = ['id' => (string)$idOf('attribute_definitions', 'slug', 'aac-size')];
        $_POST = $attrPost(['name' => 'AAC Size', 'slug' => 'aac-size2', 'options' => ['S', 'M'], 'option_order' => ['1', '2']]);
        handleAttributeSave();
        break;
    case 'attr_options_array':
        $extra = static fn(): array => ['options' => $options('aac-size'), 't' => I18n::t('admin.attr.options_invalid')];
        $_GET = ['id' => (string)$idOf('attribute_definitions', 'slug', 'aac-size')];
        $_POST = $attrPost(['name' => 'AAC Size', 'slug' => 'aac-size', 'options' => [['S']], 'option_order' => ['1']]);
        handleAttributeSave();
        break;
    case 'attr_delete':
        $extra = static fn(): array => ['defs' => array_column($q('SELECT slug FROM attribute_definitions ORDER BY slug'), 'slug')];
        $_POST = ['csrf_token' => 'aac-token', 'attr_id' => (string)$idOf('attribute_definitions', 'slug', 'aac-size')];
        handleAttributeDelete();
        break;
    case 'product_sku_variant':
        $extra = static fn(): array => ['created' => $productRow('AAC-DEF-1-V'), 't' => I18n::format('admin.msg.variant.sku_taken', ['sku' => 'AAC-DEF-1-V']),
            'import' => (static function() use ($db): string {
                try { Import::importProduct(['sku' => 'AAC-DEF-1-V', 'name' => 'Clash', 'price' => '1'], $db); return 'imported'; } catch (RuntimeException $e) { return 'refused'; }
            })()];
        $_POST = $productPost('AAC-DEF-1-V', []);
        handleProductSave();
        break;
    case 'product_sku_rename_ordered':
        $extra = static fn(): array => ['row' => $productRow('AAC-DEF-1'), 't' => I18n::format('admin.msg.product.sku_change_blocked', ['sku' => 'AAC-DEF-1', 'reason' => I18n::t('admin.msg.variant.in_orders')])];
        $_GET = ['id' => (string)$productId('AAC-DEF-1')];
        $_POST = $productPost('AAC-DEF-1-NEW', ['slug' => 'aac-def-1', 'price' => '350']);
        handleProductSave();
        break;
    case 'product_sku_rename_free':
        $extra = static fn(): array => ['old' => $productRow('AAC-DEF-2'), 'new' => $productRow('AAC-DEF-2-NEW')];
        $_GET = ['id' => (string)$productId('AAC-DEF-2')];
        $_POST = $productPost('AAC-DEF-2-NEW', ['slug' => 'aac-def-2', 'status' => 'draft', 'attr' => ['AAC Size' => ['nested'], 'AAC Color' => 'red']]);
        handleProductSave();
        break;
    case 'product_price_floor':
        $extra = static fn(): array => ['row' => $productRow('AAC-DEF-1'), 't' => I18n::format('admin.msg.product.price_below_variants', ['min' => 300]),
            'import' => (static function() use ($db): array {
                $out = [];
                foreach (['AAC-DEF-1' => '1.00', 'AAC-NEG-1' => '-5'] as $sku => $price) {
                    try { Import::importProduct(['sku' => $sku, 'name' => $sku, 'price' => $price], $db); $out[$sku] = 'imported'; } catch (InvalidArgumentException $e) { $out[$sku] = 'refused'; }
                }
                return $out;
            })()];
        $_GET = ['id' => (string)$productId('AAC-DEF-1')];
        $_POST = $productPost('AAC-DEF-1', ['slug' => 'aac-def-1', 'price' => '100']);
        handleProductSave();
        break;
    case 'cz_slug_import':
        Import::importProduct(['sku' => 'AAC-CZ-2', 'name' => 'Česká věc', 'slug' => 'aac-cz-2', 'price' => '3', 'name_en' => 'Czech thing', 'slug_en' => 'aac-czech-thing'], $db);
        $resolved = Products::bySlug('aac-czech-thing');
        $db->prepare("UPDATE slug_registry SET store_id = 'default' WHERE slug = 'aac-czech-thing'")->execute();
        $broken = Products::bySlug('aac-czech-thing');
        (require $root . '/migrations/20261016141000_slug_registry_product_store.php')->up($db, $driver === 'sqlite');
        $extra = static fn(): array => ['registry' => $q("SELECT slug, locale, store_id FROM slug_registry WHERE slug IN ('aac-cz-2', 'aac-czech-thing') ORDER BY slug"),
            'resolved' => $resolved['sku'] ?? null, 'broken' => $broken['sku'] ?? null, 'repaired' => Products::bySlug('aac-czech-thing')['sku'] ?? null];
        break;
    case 'digital_assets':
        $base = sys_get_temp_dir() . '/aac-digital-' . getmypid();
        @mkdir($base . '/default', 0700, true);
        file_put_contents($base . '/outside.txt', 'x');
        @symlink($base, $base . '/default/escape');
        DigitalDelivery::setConfigForTesting(['digital_assets_base_dir' => $base]);
        $paths = [];
        foreach (['ebooks/a.pdf', 'default/ebooks/a.pdf', '../cz/a.pdf', '/etc/passwd', 'a//b.pdf', 'escape/outside.txt'] as $input) $paths[$input] = DigitalDelivery::storePath($input);
        $paths['cz-catalog'] = DigitalDelivery::storePath('a.pdf', 'cz');
        $paths['bad-catalog'] = DigitalDelivery::storePath('a.pdf', '../x');
        $_POST = ['csrf_token' => 'aac-token', 'sku' => 'AAC-DEF-2', 'asset_key' => 'default', 'label' => 'Manual', 'storage_path' => 'manuals/m.pdf', 'download_name' => 'm.pdf',
            'content_type' => 'application/pdf', 'download_limit' => '3', 'expiry_days' => '7', 'active' => '1'];
        $extra = static function() use ($q, $paths, $base): array {
            @unlink($base . '/default/escape');
            @unlink($base . '/outside.txt');
            @rmdir($base . '/default');
            @rmdir($base);
            return ['paths' => $paths, 'assets' => $q('SELECT sku, storage_path FROM digital_assets')];
        };
        handleDigitalAssetSave();
        break;
    case 'digital_asset_toggle':
        $db->exec("INSERT INTO digital_assets (sku, asset_key, storage_path) VALUES ('AAC-DEF-2', 'default', 'default/x.pdf'), ('AAC-CZ-1', 'default', 'cz/y.pdf')");
        $extra = static fn(): array => ['assets' => $q('SELECT sku, active FROM digital_assets ORDER BY sku')];
        $_POST = ['csrf_token' => 'aac-token', 'asset_id' => (string)$idOf('digital_assets', 'sku', 'AAC-DEF-2'), 'active' => '0'];
        handleDigitalAssetToggle();
        break;
    case 'warehouse_save':
        $extra = static fn(): array => [];
        $_POST = ['csrf_token' => 'aac-token', 'code' => 'aac-wh-new', 'name' => 'AAC new WH', 'priority' => '1'];
        handleWarehouseSave();
        break;
    case 'warehouse_delete_foreign':
        $extra = static fn(): array => ['warehouses' => array_column($q('SELECT code FROM warehouses ORDER BY code'), 'code')];
        $_POST = ['csrf_token' => 'aac-token', 'warehouse_id' => (string)$whId('cz')];
        handleWarehouseDelete();
        break;
    case 'shipping_method':
        $_POST = ['csrf_token' => 'aac-token', 'code' => 'aac-ship', 'carrier' => 'aac', 'name' => 'AAC ship', 'service_type' => 'parcel', 'active' => '1', 'sort_order' => '1'];
        $extra = static fn(): array => [];
        handleShippingMethodSave();
        break;
    case 'shipping_method_delete':
        $db->exec("INSERT INTO shipping_methods (store_id, code, carrier, name) VALUES ('default', 'aac-del', 'aac', 'AAC del')");
        $_POST = ['csrf_token' => 'aac-token', 'id' => (string)$idOf('shipping_methods', 'code', 'aac-del')];
        $extra = static fn(): array => ['left' => $q("SELECT code FROM shipping_methods WHERE code = 'aac-del'")];
        handleShippingMethodDelete();
        break;
    case 'payment_method':
        $_POST = ['csrf_token' => 'aac-token', 'code' => 'aac-pay', 'provider' => 'manual', 'name' => 'AAC pay', 'service_type' => 'offline', 'min_amount' => '0', 'fixed_fee' => '50', 'active' => '1'];
        $extra = static fn(): array => [];
        handlePaymentMethodSave();
        break;
    case 'customer_block':
        $_POST = ['csrf_token' => 'aac-token', 'customer_id' => (string)$idOf('customers', 'email', 'aac-customer@example.test'), 'status' => 'blocked'];
        $extra = static fn(): array => [];
        handleCustomerStatus();
        break;
    case 'review_approve':
        $_POST = ['csrf_token' => 'aac-token', 'review_id' => (string)$q('SELECT id FROM product_reviews')[0]['id']];
        $extra = static fn(): array => [];
        handleReviewModerate('approved');
        break;
    case 'page_delete':
        $_POST = ['csrf_token' => 'aac-token', 'page_id' => (string)$idOf('pages', 'slug', 'aac-page')];
        $extra = static fn(): array => [];
        handleCmsPageDelete();
        break;
}
PHP;
file_put_contents($aacFixture, $aacSource);
$aacRun = static function(array $args) use ($aacFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $aacFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$aacYear = (int)date('Y');
$aacDrivers = ['sqlite' => $aacRoot . '/catalog.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $aacDrivers['mysql'] = 'bbe_aac_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit admin catalog native MySQL/MariaDB run (set TEST_MYSQL_DSN)\n";
// A failed child run returns no data: its missing keys must fail the assertions below, not abort the whole suite.
$aacPreviousHandler = set_error_handler(static function(int $severity, string $message, string $file, int $line) use (&$aacPreviousHandler): bool {
    if (str_starts_with($message, 'Undefined array key') || str_contains($message, 'on null') || str_contains($message, 'on false')) return true;
    return is_callable($aacPreviousHandler) ? (bool)$aacPreviousHandler($severity, $message, $file, $line) : false;
});
try {
    foreach ($aacDrivers as $aacDriver => $aacTarget) {
        [$aacExit, , $aacStderr] = $aacRun([$aacDriver, 'setup', 'none', dirname(TEST_CLI_DIR), $aacTarget]);
        assert_equals([0, ''], [$aacExit, $aacStderr], "AAC {$aacDriver}: migrated fixture with two stores, feeds, orders, attributes");
        $aac = static function(string $action) use ($aacRun, $aacDriver, $aacTarget): array {
            [$exit, $stdout, $stderr] = $aacRun([$aacDriver, 'run', $action, dirname(TEST_CLI_DIR), $aacTarget]);
            assert_equals([0, ''], [$exit, $stderr], "AAC {$aacDriver}/{$action}: real code runs without PHP/SQL errors");
            return json_decode((string)$stdout, true) ?: ['output' => '', 'flash' => null, 'extra' => [], 'audit' => [], 'context' => null];
        };
        $refused = static function(array $r, string $label): void {
            assert_true(($r['flash']['type'] ?? null) === 'err' && str_contains((string)($r['flash']['msg'] ?? ''), (string)($r['extra']['t'] ?? "\0")), "{$label}: refused with a specific message (" . ($r['flash']['msg'] ?? '') . ')');
            assert_equals([], $r['audit'], "{$label}: nothing audited");
        };
        $seedFeeds = [['code' => 'aac-feed-cz', 'name' => 'AAC feed cz'], ['code' => 'aac-feed-default', 'name' => 'AAC feed default']];
        $feedNames = static fn(array $feeds): array => array_map(static fn(array $f): array => ['code' => $f['code'], 'name' => $f['name']], $feeds);

        // 1. Supplier feeds belong to the store of their warehouse.
        $r = $aac('feeds_scope');
        assert_equals(['aac-feed-default'], $r['extra']['all'], "AAC {$aacDriver}: feed list shows only the active store's feeds");
        assert_equals([null, 'aac-feed-default', []], [$r['extra']['foreign'], $r['extra']['own'], $r['extra']['by_foreign_wh']], "AAC {$aacDriver}: another store's feed and warehouse feeds are not readable");
        assert_equals([false, true], [$r['extra']['delete_foreign'], str_contains((string)$r['extra']['sync_foreign'], 'not found')], "AAC {$aacDriver}: another store's feed can be neither deleted nor synced");
        assert_equals(['Warehouse not found in active store', 'refused'], [$r['extra']['save_foreign'], $r['extra']['save_bad_mode']], "AAC {$aacDriver}: saveFeed refuses another store's warehouse and an unknown import mode");
        assert_equals($seedFeeds, $feedNames($r['extra']['feeds']), "AAC {$aacDriver}: feeds unchanged");
        foreach (['feed_save_foreign_warehouse', 'feed_edit_foreign', 'feed_delete_foreign', 'feed_sync_foreign'] as $aacAction) {
            $r = $aac($aacAction);
            $refused($r, "AAC {$aacDriver}/{$aacAction}");
            assert_equals($seedFeeds, $feedNames($r['extra']['feeds']), "AAC {$aacDriver}/{$aacAction}: feeds unchanged");
        }
        $r = $aac('feed_save_price_stock');
        assert_equals(['ok', 'price_stock'], [$r['flash']['type'] ?? null, array_column($r['extra']['feeds'], 'import_mode', 'code')['aac-new-feed'] ?? null], "AAC {$aacDriver}: a price_stock feed is saved with its mode");
        assert_equals([['feed', 'Admin feed create', 'default']], array_map(static fn(array $a): array => [$a['entity'], $a['note'], $a['store_id']], $r['audit']), "AAC {$aacDriver}: feed creation audited in the active store");
        assert_true(str_contains($r['extra']['form'], 'value="price_stock"') && str_contains($r['extra']['form'], 'value="stock_only"') && str_contains($r['extra']['form'], 'value="upsert"'), "AAC {$aacDriver}: the feed form offers exactly the modes the import implements");
        $r = $aac('feed_delete_own');
        assert_equals([['aac-feed-cz'], [['feed', 'Admin feed delete']]], [array_column($r['extra']['feeds'], 'code'), array_map(static fn(array $a): array => [$a['entity'], $a['note']], $r['audit'])], "AAC {$aacDriver}: own feed deleted and audited");

        // 2. Import modes: stock_only never creates or overwrites; price_stock changes only the price; upsert creates.
        $r = $aac('feed_stock_only');
        assert_equals(['imported' => 2, 'stock_updated' => 2, 'skipped' => 2], $r['extra']['result'], "AAC {$aacDriver}: stock_only takes own product and variant SKUs, skips unknown and foreign SKUs");
        assert_equals([['sku' => 'AAC-DEF-1', 'name' => 'AAC default one', 'price' => 350, 'status' => 'active', 'store_id' => 'default'], null, ['sku' => 'AAC-CZ-1', 'name' => 'AAC cz one', 'price' => 2000, 'status' => 'active', 'store_id' => 'cz']],
            array_map(static fn($p) => $p === null ? null : array_replace($p, ['price' => (int)$p['price']]), $r['extra']['products']), "AAC {$aacDriver}: stock_only changes no product content and creates nothing");
        assert_equals([['code' => 'aac-wh-default', 'sku' => 'AAC-DEF-1', 'qty' => 7], ['code' => 'aac-wh-default', 'sku' => 'AAC-DEF-1-V', 'qty' => 4]],
            array_map(static fn(array $s): array => array_replace($s, ['qty' => (int)$s['qty']]), $r['extra']['stock']), "AAC {$aacDriver}: stock_only books stock into the feed's warehouse only");
        $r = $aac('feed_price_stock');
        assert_equals([1200, 'AAC default one', null, 2000], [(int)$r['extra']['products'][0]['price'], $r['extra']['products'][0]['name'], $r['extra']['products'][1], (int)$r['extra']['products'][2]['price']],
            "AAC {$aacDriver}: price_stock (existing rows keep working) updates the own price only, creates nothing, leaves the other catalog");
        $r = $aac('feed_upsert');
        assert_equals(['Overwritten', 'Created'], [$r['extra']['products'][0]['name'], $r['extra']['products'][1]['name'] ?? null], "AAC {$aacDriver}: upsert still creates and overwrites");
        $r = $aac('feed_price_floor');
        assert_equals([2, 350, 1000], [count($r['extra']['errors']), (int)$r['extra']['products'][0]['price'], (int)$r['extra']['products'][1]['price']], "AAC {$aacDriver}: feed prices below the variant floor or negative are refused per row");
        $r = $aac('feed_store_context');
        assert_equals(['Failed to download feed', 'default', true, ['aac-feed-cz' => 'cz', 'aac-feed-default' => 'default']],
            [$r['extra']['cz'], $r['extra']['after'], str_contains((string)$r['extra']['from_default'], 'not found'), $r['extra']['all_stores']],
            "AAC {$aacDriver}: syncAll/CLI run each feed inside its warehouse's store context and restore the previous one");

        // 3./4./13. Numbering per seller and document type; only paid orders; audited; admin list filter.
        $r = $aac('invoices');
        assert_equals(['AAC-O1' => "{$aacYear}0001", 'AAC-O3' => "CZ-{$aacYear}0001", 'AAC-O2' => "{$aacYear}0002"], $r['extra']['issued'], "AAC {$aacDriver}: each seller numbers its invoices gaplessly in its own series");
        assert_equals(['refused', 'refused', "CN-{$aacYear}0001"], [$r['extra']['unpaid'], $r['extra']['shared'], $r['extra']['credit']], "AAC {$aacDriver}: unpaid order refused; a prefix numbered by another seller refused; credit notes have their own series");
        assert_equals([['cz', 'invoice', 'AAC-', 5], ['cz', 'invoice', 'CZ-', 1], ['default', 'credit_note', 'CN-', 1], ['default', 'invoice', '', 2]],
            array_map(static fn(array $c): array => [$c['store_id'], $c['type'], $c['prefix'], (int)$c['last_number']], $r['extra']['counters']), "AAC {$aacDriver}: counters keyed by store, type, year and prefix");
        assert_equals(3, count(array_filter($r['audit'], static fn(array $a): bool => $a['entity'] === 'invoice' && $a['note'] === 'Invoice issued')), "AAC {$aacDriver}: every issued invoice is audited");
        assert_equals([["{$aacYear}0002", "{$aacYear}0001"], []], [$r['extra']['list'], $r['extra']['list_old']], "AAC {$aacDriver}: invoice admin list keeps the store filter with a year filter");
        $r = $aac('invoice_issue_unpaid');
        $refused($r, "AAC {$aacDriver}/invoice_issue_unpaid");
        assert_equals([[], false], [$r['extra']['invoices'], str_contains($r['extra']['page'], 'action=invoice_issue')], "AAC {$aacDriver}: no invoice and no issue button for an unpaid order");
        if ($aacDriver === 'sqlite') {
            $r = $aac('invoice_migration');
            $aacBackfill = [['cz', 'invoice', 2024, 'CZ-', 4], ['default', 'invoice', 2025, '', 7], ['cz', 'credit_note', 2025, 'CN-', 2]];
            assert_equals($aacBackfill, array_map(static fn(array $c): array => [$c['store_id'], $c['type'], (int)$c['year'], $c['prefix'], (int)$c['last_number']], $r['extra']['first']),
                "AAC {$aacDriver}: backfill gives each shared legacy sequence one owner (default store first, else most documents, else the configured prefix) and keeps last_number");
            assert_equals([$r['extra']['first'], 4], [$r['extra']['second'], $r['extra']['legacy']], "AAC {$aacDriver}: counter migration is idempotent and keeps the legacy table");
        }

        // 5./14. Product list of the active catalog only; array query input is no TypeError.
        $r = $aac('products_page');
        assert_true(str_contains($r['output'], 'AAC-DEF-1') && !str_contains($r['output'], 'AAC-CZ-1') && str_contains($r['output'], '<span class="muted">(2)</span>'), "AAC {$aacDriver}: product list and count show only the default catalog");
        $r = $aac('cz_products_page');
        assert_true(str_contains($r['output'], 'AAC-CZ-1') && !str_contains($r['output'], 'AAC-DEF-1') && str_contains($r['output'], '<span class="muted">(1)</span>'), "AAC {$aacDriver}: product list and count show only the cz catalog");

        // 7./12./14. Bulk actions: ordered products are skipped (no FK 500), prices held at the floor, everything audited.
        $r = $aac('bulk_delete');
        assert_equals(['AAC-DEF-1', null, 'AAC-CZ-1'], array_map(static fn($p) => $p['sku'] ?? null, $r['extra']['products']), "AAC {$aacDriver}: unordered own product deleted; ordered and foreign products kept");
        assert_true(($r['flash']['type'] ?? '') === 'err' && str_contains((string)($r['flash']['msg'] ?? ''), $r['extra']['t']), "AAC {$aacDriver}: skipped ordered product reported (" . ($r['flash']['msg'] ?? '') . ')');
        assert_equals([['product', 'Admin bulk delete']], array_map(static fn(array $a): array => [$a['entity'], $a['note']], $r['audit']), "AAC {$aacDriver}: bulk delete audited");
        $r = $aac('bulk_price_floor');
        assert_equals([300, 800, 2000], array_map(static fn($p) => (int)$p['price'], $r['extra']['products']), "AAC {$aacDriver}: -20 % held at the variant floor (300), applied to the other own product, foreign untouched");
        assert_equals(2, count(array_filter($r['audit'], static fn(array $a): bool => $a['note'] === 'Admin bulk price -20%')), "AAC {$aacDriver}: bulk price changes audited per product");
        foreach (['bulk_array_input', 'bulk_nested_ids'] as $aacAction) {
            $r = $aac($aacAction);
            $aacStatus = $aacAction === 'bulk_nested_ids' ? 'archived' : 'active';
            assert_equals([$aacStatus, 'draft'], array_column($r['extra']['products'], 'status'), "AAC {$aacDriver}/{$aacAction}: array/string input handled without a TypeError");
        }

        // 6. Shared attribute definitions.
        foreach (['attr_shared_type', 'attr_shared_slug'] as $aacAction) {
            $r = $aac($aacAction);
            $refused($r, "AAC {$aacDriver}/{$aacAction}");
            assert_equals([[['slug' => 'aac-color', 'type' => 'enum']], ['blue', 'red']], [$r['extra']['def'], $r['extra']['options']], "AAC {$aacDriver}/{$aacAction}: shared definition and its values unchanged");
        }
        $r = $aac('attr_shared_options');
        assert_equals(['blue', 'green', 'red'], $r['extra']['options'], "AAC {$aacDriver}: another catalog's values of a shared attribute are kept, new ones added");
        assert_equals([['attribute', 'Admin attribute edit']], array_map(static fn(array $a): array => [$a['entity'], $a['note']], $r['audit']), "AAC {$aacDriver}: attribute save audited");
        $r = $aac('attr_own_options');
        assert_equals([['M', 'S'], [['slug' => 'aac-size2', 'type' => 'enum']]], [$r['extra']['options'], $r['extra']['def']], "AAC {$aacDriver}: an attribute only this catalog uses may change slug and replace its values");
        $r = $aac('attr_options_array');
        $refused($r, "AAC {$aacDriver}/attr_options_array");
        $r = $aac('attr_delete');
        assert_equals([['aac-color'], [['attribute', 'Admin attribute delete']]], [$r['extra']['defs'], array_map(static fn(array $a): array => [$a['entity'], $a['note']], $r['audit'])], "AAC {$aacDriver}: attribute delete audited");

        // 8./12. One SKU rule; pinned SKUs; price floor in the editor and the import.
        $r = $aac('product_sku_variant');
        $refused($r, "AAC {$aacDriver}/product_sku_variant");
        assert_equals([null, 'refused'], [$r['extra']['created'], $r['extra']['import']], "AAC {$aacDriver}: a variant SKU can be neither a new product (editor) nor an imported product");
        $r = $aac('product_sku_rename_ordered');
        $refused($r, "AAC {$aacDriver}/product_sku_rename_ordered");
        assert_equals('AAC-DEF-1', $r['extra']['row']['sku'] ?? null, "AAC {$aacDriver}: an ordered product keeps its SKU");
        $r = $aac('product_sku_rename_free');
        assert_equals(['ok', null, 'AAC-DEF-2-NEW'], [$r['flash']['type'] ?? null, $r['extra']['old'], $r['extra']['new']['sku'] ?? null], "AAC {$aacDriver}: an unreferenced product SKU can change; nested attr input ignored");
        $r = $aac('product_price_floor');
        $refused($r, "AAC {$aacDriver}/product_price_floor");
        assert_equals([350, ['AAC-DEF-1' => 'refused', 'AAC-NEG-1' => 'refused']], [(int)$r['extra']['row']['price'], $r['extra']['import']], "AAC {$aacDriver}: editor and import refuse a price below the variant floor or below zero");

        // 9. Translated slugs of a non-default catalog are registered for that catalog (and the migration repairs old rows).
        $r = $aac('cz_slug_import');
        assert_equals([['slug' => 'aac-cz-2', 'locale' => 'sk', 'store_id' => 'cz'], ['slug' => 'aac-czech-thing', 'locale' => 'en', 'store_id' => 'cz']], $r['extra']['registry'], "AAC {$aacDriver}: import registers slugs for the cz catalog");
        assert_equals(['AAC-CZ-2', null, 'AAC-CZ-2'], [$r['extra']['resolved'], $r['extra']['broken'], $r['extra']['repaired']], "AAC {$aacDriver}: the translated slug resolves; a legacy 'default' row 404s until the migration repairs it");

        // 10. Digital asset paths live in the store's own directory.
        $r = $aac('digital_assets');
        assert_equals(['ebooks/a.pdf' => 'default/ebooks/a.pdf', 'default/ebooks/a.pdf' => 'default/ebooks/a.pdf', '../cz/a.pdf' => null, '/etc/passwd' => null, 'a//b.pdf' => null],
            array_slice($r['extra']['paths'], 0, 5, true), "AAC {$aacDriver}: asset paths are kept inside <base>/<catalog>/");
        assert_equals([null, 'cz/a.pdf', null], array_values(array_slice($r['extra']['paths'], 5, 3, true)), "AAC {$aacDriver}: symlink escape and unsafe catalog ids refused; other catalogs get their own directory");
        assert_equals([['sku' => 'AAC-DEF-2', 'storage_path' => 'default/manuals/m.pdf']], $r['extra']['assets'], "AAC {$aacDriver}: saved asset stored under the store directory");
        assert_equals([['digital_asset', 'Admin digital asset save']], array_map(static fn(array $a): array => [$a['entity'], $a['note']], $r['audit']), "AAC {$aacDriver}: digital asset save audited");
        $r = $aac('digital_asset_toggle');
        assert_equals([['sku' => 'AAC-CZ-1', 'active' => 1], ['sku' => 'AAC-DEF-2', 'active' => 0]], array_map(static fn(array $a): array => array_replace($a, ['active' => (int)$a['active']]), $r['extra']['assets']), "AAC {$aacDriver}: own asset toggled");
        assert_equals([['digital_asset', 'Admin digital asset toggle']], array_map(static fn(array $a): array => [$a['entity'], $a['note']], $r['audit']), "AAC {$aacDriver}: digital asset toggle audited");

        // 7. Audit log coverage of the remaining admin changes.
        foreach (['warehouse_save' => ['warehouse', 'Admin warehouse create'], 'shipping_method' => ['shipping_methods', 'Admin config create'], 'shipping_method_delete' => ['shipping_methods', 'Admin config delete'],
                  'payment_method' => ['payment_methods', 'Admin config create'], 'customer_block' => ['customer', 'Admin customer status'], 'review_approve' => ['review', 'Admin review moderation'],
                  'page_delete' => ['page', 'Admin page delete']] as $aacAction => $aacExpected) {
            $r = $aac($aacAction);
            assert_equals([$aacExpected + [2 => 'default']], array_map(static fn(array $a): array => [$a['entity'], $a['note'], $a['store_id']], $r['audit']), "AAC {$aacDriver}/{$aacAction}: change audited in the active store");
        }
        $r = $aac('warehouse_delete_foreign');
        assert_equals([[], true], [$r['audit'], in_array('aac-wh-cz', $r['extra']['warehouses'], true)], "AAC {$aacDriver}: another store's warehouse is not deleted");
    }
} finally {
    restore_error_handler();
    if (isset($aacDrivers['mysql'])) $aacRun(['mysql', 'teardown', 'none', dirname(TEST_CLI_DIR), $aacDrivers['mysql']]);
    $aacFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($aacRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($aacFiles as $aacFile) $aacFile->isDir() ? rmdir($aacFile->getPathname()) : unlink($aacFile->getPathname());
    rmdir($aacRoot);
}

// Pure helpers: invoice prefixes, escaping, ids, pagination, image cap, bulk price, streaming exports.
assert_equals([[], 'CN-', 'CZ-CN-', 'X-'], [InvoiceService::numberingConflicts(Bootstrap::stores()), InvoiceService::numberPrefix(['invoice_prefix' => ''], 'credit_note'),
    InvoiceService::numberPrefix(['invoice_prefix' => 'CZ-'], 'credit_note'), InvoiceService::numberPrefix(['invoice_prefix' => 'CZ-', 'credit_note_prefix' => 'X-'], 'credit_note')],
    'AAC numbering: the shipped stores.json has no shared prefix; credit notes default to "<invoice_prefix>CN-"');
$aacConflicts = InvoiceService::numberingConflicts(['stores' => ['a' => ['legal' => ['invoice_prefix' => 'S-']], 'b' => ['legal' => ['invoice_prefix' => 'S-']], 'c' => ['legal' => ['invoice_prefix' => 'C-', 'credit_note_prefix' => 'C-']]]]);
assert_equals(['a/invoice', 'b/invoice', 'a/credit_note', 'b/credit_note', 'c/invoice', 'c/credit_note'], array_keys($aacConflicts), 'AAC numbering: two sellers (and so their derived credit-note prefixes), or both document types of one seller, sharing a prefix are refused');
require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Catalog.php';
$aacEsc = (string)ADMIN_JS_ESC_HTML;
assert_true(str_contains($aacEsc, '&quot;') && str_contains($aacEsc, '&#39;') && !str_contains($aacEsc, 'textContent'), 'AAC escaping: admin escHtml escapes quotes for attribute values');
$aacNode = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($aacNode !== '') {
    $aacJs = test_fixture_path('esc') . '.js';
    file_put_contents($aacJs, $aacEsc . "\nprocess.stdout.write(escHtml(`a\"b'c<d>&e`) + '|' + escHtml(null) + '|' + escHtml(5));");
    assert_equals('a&quot;b&#39;c&lt;d&gt;&amp;e||5', (string)shell_exec(escapeshellarg($aacNode) . ' ' . escapeshellarg($aacJs)), 'AAC escaping: escHtml output is safe inside quoted attributes');
    unlink($aacJs);
} else {
    print "  [SKIP] AAC escaping: node not installed\n";
}
assert_equals([[], [3, 4], []], [Security::intList('all'), Security::intList([['x'], '3', 3, '-1', '2a', 4, null]), Security::intList(null)], 'AAC input: posted id lists never raise a TypeError');
ob_start();
pagination(250, 500, 'admin.php?p=orders');
$aacPager = (string)ob_get_clean();
preg_match_all('/page=(\d+)"/', $aacPager, $aacPages);
assert_equals(['1', '248', '249', '250', '251', '252', '500'], $aacPages[1], 'AAC pagination: first, last and a window around the current page');
ob_start();
pagination(999, 3, 'admin.php?p=orders');
preg_match_all('/page=(\d+)"/', (string)ob_get_clean(), $aacPages);
assert_equals(['1', '2', '3'], $aacPages[1], 'AAC pagination: an out-of-range page is clamped');
assert_equals([25_000_000, 25_000_000, true, 1_000_000], [ProductEditor::maxUploadPixels('-1'), ProductEditor::maxUploadPixels('2G'), ProductEditor::maxUploadPixels('128M') < 25_000_000, ProductEditor::maxUploadPixels('16M')],
    'AAC image upload: pixel cap at most 25 MP and lowered to what memory_limit can decode');
assert_equals([['price' => 300, 'clamped' => true], ['price' => 1050, 'clamped' => false], ['price' => 0, 'clamped' => true]],
    [ProductBulk::changedPrice(350, -20, 300), ProductBulk::changedPrice(1000, 5), ProductBulk::changedPrice(-100, 5)], 'AAC bulk price: never below zero or the variant floor');
assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/OrderExport.php'), 'Db::prepareUnbuffered(')
    && str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Catalog.php'), 'Db::prepareUnbuffered("SELECT sku'),
    'AAC exports: order and product CSV exports stream unbuffered on MySQL');
$aacStmt = Db::prepareUnbuffered('SELECT 1 AS one');
$aacStmt->execute();
assert_equals(['one' => 1], array_map('intval', (array)$aacStmt->fetch(PDO::FETCH_ASSOC)), 'AAC exports: the streaming statement works on SQLite');
