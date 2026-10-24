<?php

echo "\n--- Audit A05: store-scoped admin catalog handlers ---\n";
$a05Fixture = test_fixture_path('admin-store') . '.php';
file_put_contents($a05Fixture, '<?php
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php', true) . ';
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Catalog.php', true) . ';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$driver = $argv[1];
$db = $driver === "mysql"
    ? new PDO(getenv("TEST_MYSQL_DSN"), getenv("TEST_MYSQL_USER") ?: "root", getenv("TEST_MYSQL_PASS") ?: "", [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO("sqlite::memory:");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, $driver);
$db->exec("CREATE " . ($driver === "mysql" ? "TEMPORARY " : "") . "TABLE products (
 id INTEGER PRIMARY KEY, store_id VARCHAR(40) NOT NULL, category_id INTEGER NULL, sku VARCHAR(40) NOT NULL, name VARCHAR(100) NOT NULL,
 slug VARCHAR(100) NOT NULL, product_type VARCHAR(40) NOT NULL, price INTEGER NOT NULL, compare_price INTEGER NULL,
 stock INTEGER NOT NULL, status VARCHAR(20) NOT NULL, summary VARCHAR(100) NOT NULL, weight_g INTEGER NULL,
 stock_low_threshold INTEGER NULL, stock_critical_threshold INTEGER NULL, updated_at DATETIME NULL)");
$db->exec("INSERT INTO products (id,store_id,sku,name,slug,product_type,price,stock,status,summary)
 VALUES (5,\'default\',\'A05-DEFAULT\',\'Default private product\',\'a05-default\',\'physical\',1000,3,\'draft\',\'private default\'),
 (7,\'cz\',\'A05-CZ\',\'CZ private product\',\'a05-cz\',\'physical\',2000,4,\'draft\',\'private cz\')");
// Bulk actions read order references and the variant price floor, and write the audit log.
$tmp = $driver === "mysql" ? "TEMPORARY " : "";
$db->exec("CREATE {$tmp}TABLE variants (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, sku VARCHAR(40) NULL, price_absolute INTEGER NULL, price_delta INTEGER NOT NULL DEFAULT 0)");
// A delete books out and drops the warehouse stock of the product (none here).
$db->exec("CREATE {$tmp}TABLE warehouse_stock (id INTEGER PRIMARY KEY, store_id VARCHAR(40), warehouse_id INTEGER NOT NULL, sku VARCHAR(40) NOT NULL, qty INTEGER NOT NULL DEFAULT 0, reserved INTEGER NOT NULL DEFAULT 0)");
$db->exec("CREATE {$tmp}TABLE order_items (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL)");
// A status change or delete recounts the categories of the products (none here: no category).
$db->exec("CREATE {$tmp}TABLE product_categories (product_id INTEGER NOT NULL, category_id INTEGER NOT NULL)");
// A delete frees the product\'s registered slugs.
$db->exec("CREATE {$tmp}TABLE slug_registry (slug VARCHAR(100) NOT NULL, locale VARCHAR(10) NOT NULL, entity VARCHAR(40) NOT NULL, entity_id INTEGER NOT NULL, store_id VARCHAR(40) NULL)");
$db->exec("CREATE {$tmp}TABLE audit_log (id INTEGER PRIMARY KEY " . ($driver === "mysql" ? "AUTO_INCREMENT" : "") . ", entity VARCHAR(40), entity_id INTEGER, action VARCHAR(40), old_value TEXT, new_value TEXT, note TEXT, actor VARCHAR(80), ip_address VARCHAR(80), store_id VARCHAR(40), correlation_id VARCHAR(80), created_at DATETIME NULL)");
// A status or price change records product.updated (outbox) and a price change the price history (Omnibus).
$db->exec("CREATE {$tmp}TABLE event_sequence (id INTEGER PRIMARY KEY " . ($driver === "mysql" ? "AUTO_INCREMENT" : "AUTOINCREMENT") . ")");
$db->exec("CREATE {$tmp}TABLE event_outbox (id VARCHAR(32) PRIMARY KEY, store_id VARCHAR(40), channel_id VARCHAR(40), event_type VARCHAR(190), aggregate_type VARCHAR(190), aggregate_id VARCHAR(190), event_version INTEGER, payload TEXT, status VARCHAR(20), attempts INTEGER, available_at DATETIME, occurred_at DATETIME, updated_at DATETIME, correlation_id VARCHAR(80), seq INTEGER)");
// Not TEMPORARY: the 30-day lookup reads the history table twice in one query, which MySQL refuses for a temporary table.
$db->exec("DROP TABLE IF EXISTS product_price_history");
$db->exec("CREATE TABLE product_price_history (id INTEGER PRIMARY KEY " . ($driver === "mysql" ? "AUTO_INCREMENT" : "AUTOINCREMENT") . ", product_id INTEGER NOT NULL, price INTEGER NOT NULL, lowest_prior INTEGER NULL, changed_at DATETIME NOT NULL)");
$store = $argv[2];
$stores = Bootstrap::stores();
$stores["stores"]["cz"] = array_replace($stores["stores"]["default"], ["id"=>"cz", "url_prefix"=>"/cz", "domains"=>[], "catalog_scope"=>"cz", "provider_scope"=>"cz"]);
StoreContext::activate(StoreContext::resolve($stores, "/", "", $store));
$mode = $argv[4] ?? "mixed";
$grantStore = $mode === "denied" ? ($store === "default" ? "cz" : "default") : $store;
$_SESSION = ["admin_role"=>"editor", "admin_identity_id"=>1, "admin_store_permissions"=>[$grantStore=>["products.edit", "products.view"]]];
$_POST = ["bulk_action"=>$argv[3], "product_ids"=>match ($mode) {"foreign"=>[$store === "default" ? 7 : 5], "empty"=>[], default=>[5,7,999]}];
$canEdit = Security::adminCan("products.edit");
ob_start();
register_shutdown_function(static function() use ($db, $canEdit): void {
    $output = ob_get_contents();
    ob_clean();
    print json_encode(["products"=>$db->query("SELECT id,store_id,price,status,updated_at FROM products ORDER BY id")->fetchAll(),"flash"=>$_SESSION["flash"]??null,"output"=>$output,"can_edit"=>$canEdit,"http_status"=>http_response_code()], JSON_THROW_ON_ERROR);
    ob_end_flush();
});
if ($argv[3] === "export") handleExport();
handleBulkAction();
');
try {
    $a05Drivers = ['sqlite'];
    if (getenv('TEST_MYSQL_DSN')) $a05Drivers[] = 'mysql';
    else print "  [SKIP] Audit A05 native admin store boundary (set TEST_MYSQL_DSN)\n";
    foreach ($a05Drivers as $a05Driver) {
        foreach (['default', 'cz'] as $a05Store) {
            foreach (['activate', 'archive', 'delete', 'price_up_5', 'price_up_10', 'price_up_20', 'price_down_5', 'price_down_10', 'price_down_20', 'export'] as $a05Action) {
                foreach (['mixed', 'foreign', 'empty', 'denied'] as $a05Mode) {
                $a05Label = "A05 {$a05Driver}/{$a05Store}/{$a05Action}/{$a05Mode}";
                $a05Process = proc_open([PHP_BINARY, $a05Fixture, $a05Driver, $a05Store, $a05Action, $a05Mode], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $a05Pipes);
                fclose($a05Pipes[0]);
                $a05Output = stream_get_contents($a05Pipes[1]);
                $a05Stderr = stream_get_contents($a05Pipes[2]);
                fclose($a05Pipes[1]);
                fclose($a05Pipes[2]);
                assert_equals([0, ''], [proc_close($a05Process), $a05Stderr], "{$a05Label}: actual handler exits cleanly");
                $a05Result = json_decode($a05Output, true, 512, JSON_THROW_ON_ERROR);
                assert_equals($a05Mode !== 'denied', $a05Result['can_edit'], "{$a05Label}: permission follows active-store grants");
                $a05OwnId = $a05Store === 'default' ? 5 : 7;
                $a05ForeignId = $a05Store === 'default' ? 7 : 5;
                $a05Rows = array_column($a05Result['products'], null, 'id');
                $a05ForeignPrice = $a05Store === 'default' ? 2000 : 1000;
                assert_equals(['id'=>$a05ForeignId,'store_id'=>$a05Store === 'default' ? 'cz' : 'default','price'=>$a05ForeignPrice,'status'=>'draft','updated_at'=>null], isset($a05Rows[$a05ForeignId]) ? array_replace($a05Rows[$a05ForeignId], ['id'=>(int)$a05Rows[$a05ForeignId]['id'],'price'=>(int)$a05Rows[$a05ForeignId]['price']]) : null, "{$a05Label}: foreign product remains exactly unchanged");
                if ($a05Mode === 'denied') {
                    assert_equals(403, $a05Result['http_status'], "{$a05Label}: handler rejects other-store grants");
                    assert_true(!str_contains($a05Result['output'], 'A05-'), "{$a05Label}: denied handler discloses no product");
                    assert_equals(['id'=>$a05OwnId, 'store_id'=>$a05Store, 'price'=>$a05Store === 'default' ? 1000 : 2000, 'status'=>'draft', 'updated_at'=>null], array_replace($a05Rows[$a05OwnId], ['id'=>(int)$a05Rows[$a05OwnId]['id'], 'price'=>(int)$a05Rows[$a05OwnId]['price']]), "{$a05Label}: denied handler leaves own product unchanged");
                } elseif ($a05Action === 'export') {
                    assert_true(str_contains($a05Result['output'], $a05Store === 'default' ? 'A05-DEFAULT' : 'A05-CZ'), "{$a05Label}: CSV contains own product");
                    assert_true(!str_contains($a05Result['output'], $a05Store === 'default' ? 'A05-CZ' : 'A05-DEFAULT'), "{$a05Label}: CSV excludes foreign product");
                } elseif ($a05Mode !== 'mixed') {
                    assert_equals(['id'=>$a05OwnId, 'store_id'=>$a05Store, 'price'=>$a05Store === 'default' ? 1000 : 2000, 'status'=>'draft', 'updated_at'=>null], array_replace($a05Rows[$a05OwnId], ['id'=>(int)$a05Rows[$a05OwnId]['id'], 'price'=>(int)$a05Rows[$a05OwnId]['price']]), "{$a05Label}: unselected own product remains unchanged");
                } elseif ($a05Action === 'delete') {
                    assert_true(!isset($a05Rows[$a05OwnId]), "{$a05Label}: own product deleted");
                } elseif (str_starts_with($a05Action, 'price_')) {
                    $a05OwnPrice = $a05Store === 'default' ? 1000 : 2000;
                    $a05Percent = (int)filter_var($a05Action, FILTER_SANITIZE_NUMBER_INT);
                    $a05Multiplier = 1 + (str_contains($a05Action, '_up_') ? 1 : -1) * $a05Percent / 100;
                    assert_equals((int)round($a05OwnPrice * $a05Multiplier), (int)$a05Rows[$a05OwnId]['price'], "{$a05Label}: own product price changed");
                } else {
                    assert_equals($a05Action === 'activate' ? 'active' : 'archived', $a05Rows[$a05OwnId]['status'], "{$a05Label}: own product status changed");
                }
                }
            }
        }
    }
} finally {
    unlink($a05Fixture);
}
