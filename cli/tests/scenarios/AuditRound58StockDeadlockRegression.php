<?php

// Review round 23 (R58), found while verifying the round on MariaDB: two checkouts of different products deadlocked.
// Warehouse::syncProductStock() mirrored a variant SKU's ledger stock into variants with "product_id IN (SELECT id FROM
// products WHERE store_id = …)" and touched products.stock_updated_at with "products WHERE store_id = …": on MySQL and
// MariaDB those locking reads take every product row of the store, so a checkout holding one product's row and another
// checkout holding another's waited for each other (1213). Under load the order's three attempts ran out and the buyer
// got a 503 (R37's fourteen concurrent checkouts failed one or two of them in most runs). The variants are now found by a
// plain read and updated by id: syncing one SKU locks no other product's row.
echo "\n--- R58 stock sync: a variant's stock sync locks no other product of the store (MySQL) ---\n";
if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R58 stock sync native MySQL (set TEST_MYSQL_DSN)\n";
    return;
}
$r58wRoot = test_fixture_path('stock-deadlock-r58');
mkdir($r58wRoot, 0700, true);
file_put_contents($r58wRoot . '/child.php', <<<'PHP'
<?php
[, $target, $root] = $argv;
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$connect = static fn(): PDO => new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db = $connect();
$db->exec("DROP DATABASE IF EXISTS `{$target}`");
$db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
$db->exec("USE `{$target}`");
$out = [];
try {
    Db::init($db, 'mysql');
    (new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
    Bootstrap::initStoreContext('/', '', 'default');
    $warehouse = (int)(Warehouse::defaultWarehouse()['id'] ?? 0);
    $db->exec("INSERT INTO categories (slug, name, store_id) VALUES ('r58w', 'R58W', 'default')");
    $category = (int)$db->lastInsertId();
    $product = $db->prepare("INSERT INTO products (sku, slug, name, category_id, price, currency, stock, status, store_id) VALUES (?, ?, ?, {$category}, 1000, 'eur', 0, 'active', 'default')");
    $variant = $db->prepare("INSERT INTO variants (product_id, sku, name, stock) VALUES (?, ?, 'Red', 0)");
    $stock = $db->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved, store_id) VALUES (?, ?, 5, 0, 'default')");
    $ids = [];
    foreach (['A', 'B'] as $n) {
        $product->execute(["R58W-{$n}", "r58w-{$n}", "R58W {$n}"]);
        $ids[$n] = (int)$db->lastInsertId();
        $variant->execute([$ids[$n], "R58W-{$n}-RED"]);
        $stock->execute([$warehouse, "R58W-{$n}-RED"]);
    }
    // Checkout A syncs its variant inside its transaction and holds it open; another connection touches product B.
    $db->beginTransaction();
    $db->exec("UPDATE warehouse_stock SET reserved = reserved + 1 WHERE sku = 'R58W-A-RED'");
    Warehouse::syncProductStock(['R58W-A-RED']);
    $other = $connect();
    $other->exec("USE `{$target}`");
    $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        $other->prepare('UPDATE products SET stock_updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$ids['B']]);
        $other->prepare("UPDATE variants SET stock = 3 WHERE sku = 'R58W-B-RED'")->execute();
        $out['other'] = 'ok';
    } catch (PDOException $e) {
        $out['other'] = (string)($e->errorInfo[1] ?? $e->getCode());
    }
    $db->commit();
    $out['stock'] = (int)$db->query("SELECT stock FROM variants WHERE sku = 'R58W-A-RED'")->fetchColumn();
    $out['touched'] = $db->query('SELECT stock_updated_at IS NOT NULL FROM products WHERE id = ' . $ids['A'])->fetchColumn() == 1;
} finally {
    $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
echo json_encode($out, JSON_THROW_ON_ERROR);
PHP);
$r58wTarget = 'bbe_r58w_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
$r58wProcess = proc_open([PHP_BINARY, $r58wRoot . '/child.php', $r58wTarget, dirname(TEST_CLI_DIR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r58wPipes);
fclose($r58wPipes[0]);
$r58wStdout = (string)stream_get_contents($r58wPipes[1]);
$r58wStderr = (string)stream_get_contents($r58wPipes[2]);
fclose($r58wPipes[1]);
fclose($r58wPipes[2]);
$r58wExit = proc_close($r58wProcess);
$r58wOut = json_decode($r58wStdout, true);
if ($r58wExit !== 0 || !is_array($r58wOut)) {
    assert_true(false, "R58 stock sync: child shop ran (exit {$r58wExit}: " . substr($r58wStdout . $r58wStderr, -1500) . ')');
} else {
    assert_equals('ok', $r58wOut['other'] ?? null,
        'R58 stock sync: while a checkout syncs variant A, another checkout can write product B and its variant (was: 1205, product B locked by the scan of the store\'s products)');
    assert_equals(4, $r58wOut['stock'] ?? null, 'R58 stock sync: variant A mirrors the ledger (5 on hand, 1 reserved)');
    assert_true(($r58wOut['touched'] ?? false) === true, 'R58 stock sync: product A\'s stock_updated_at moves with its variant (API v1 updated_after)');
}
foreach (glob($r58wRoot . '/*') ?: [] as $r58wFile) @unlink($r58wFile);
@rmdir($r58wRoot);
