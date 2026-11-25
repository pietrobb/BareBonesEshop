<?php

// Review round 24 (R59), a regression of R58's deadlock fix: Warehouse::syncProductStock() writes
// products.stock_updated_at and variants.stock line by line in cart order, while Inventory::lockCartSkus() locked only
// the warehouse_stock rows (in SKU order). Two carts holding variants of the same products in reverse order
// (A-red + B-red / B-blue + A-blue) share no stock row, so both passed the up-front lock and then deadlocked on the rows
// of products A and B (1213): 5-10 of 120 concurrent checkouts answered 503. lockCartSkus() and lockOrderSkus() now lock
// the product and variant rows of their SKUs too, each table in id order.
echo "\n--- R59 checkout locks: carts with variants of the same products in reverse order do not deadlock (MySQL) ---\n";
if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R59 checkout locks native MySQL (set TEST_MYSQL_DSN)\n";
    return;
}
$r59lRoot = test_fixture_path('cart-lock-r59');
mkdir($r59lRoot, 0700, true);
file_put_contents($r59lRoot . '/child.php', <<<'PHP'
<?php
[, $mode, $target, $root] = $argv;
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$connect = static fn(): PDO => new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db = $connect();
$cart = static function (PDO $db, array $skus): array {
    $stmt = $db->prepare('SELECT v.product_id, v.id AS variant_id, v.sku FROM variants v WHERE v.sku = ?');
    $items = [];
    foreach ($skus as $sku) {
        $stmt->execute([$sku]);
        $items[] = $stmt->fetch() + ['qty' => 1, 'attrs' => []];
    }
    return $items;
};
if ($mode === 'worker') {
    // One shopper: 30 checkouts of the given cart, each reserving its lines one by one in cart order.
    $db->exec("USE `{$target}`");
    Db::init($db, 'mysql');
    Bootstrap::initStoreContext('/', '', 'default');
    $items = $cart($db, array_slice($argv, 4));
    $result = ['ok' => 0, 'deadlock' => 0, 'other' => ''];
    for ($i = 0; $i < 30; $i++) {
        $db->beginTransaction();
        try {
            Inventory::lockCartSkus($items);
            foreach ($items as $item) {
                Inventory::reserveLine($item, 1);
                usleep(4000);
            }
            $db->commit();
            $result['ok']++;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ((int)($e->errorInfo[1] ?? 0) === 1213) $result['deadlock']++;
            else $result['other'] = $e->getMessage();
        }
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit(0);
}
$db->exec("DROP DATABASE IF EXISTS `{$target}`");
$db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
$db->exec("USE `{$target}`");
$out = [];
try {
    Db::init($db, 'mysql');
    (new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
    Bootstrap::initStoreContext('/', '', 'default');
    $warehouse = (int)(Warehouse::defaultWarehouse()['id'] ?? 0);
    $db->exec("INSERT INTO categories (slug, name, store_id) VALUES ('r59l', 'R59L', 'default')");
    $category = (int)$db->lastInsertId();
    $product = $db->prepare("INSERT INTO products (sku, slug, name, category_id, price, currency, stock, status, store_id) VALUES (?, ?, ?, {$category}, 1000, 'eur', 0, 'active', 'default')");
    $variant = $db->prepare("INSERT INTO variants (product_id, sku, name, stock) VALUES (?, ?, ?, 0)");
    $stock = $db->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved, store_id) VALUES (?, ?, 1000, 0, 'default')");
    $ids = [];
    foreach (['A', 'B'] as $n) {
        $product->execute(["R59L-{$n}", "r59l-{$n}", "R59L {$n}"]);
        $ids[$n] = (int)$db->lastInsertId();
        foreach (['RED', 'BLUE'] as $colour) {
            $variant->execute([$ids[$n], "R59L-{$n}-{$colour}", $colour]);
            $stock->execute([$warehouse, "R59L-{$n}-{$colour}"]);
        }
    }
    Warehouse::syncProductStock();
    // A checkout that has taken its up-front locks holds the rows of both products and their variants: another
    // checkout's write to product B waits instead of interleaving with it.
    $db->beginTransaction();
    Inventory::lockCartSkus($cart($db, ['R59L-A-RED', 'R59L-B-RED']));
    $other = $connect();
    $other->exec("USE `{$target}`");
    $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        $other->prepare('UPDATE products SET stock_updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$ids['B']]);
        $out['product'] = 'ok';
    } catch (PDOException $e) {
        $out['product'] = (string)($e->errorInfo[1] ?? $e->getCode());
    }
    try {
        $other->prepare("UPDATE variants SET stock = stock WHERE sku = 'R59L-B-RED'")->execute();
        $out['variant'] = 'ok';
    } catch (PDOException $e) {
        $out['variant'] = (string)($e->errorInfo[1] ?? $e->getCode());
    }
    $other = null;
    $db->rollBack();
    // Two shoppers check out variants of A and B in reverse order at the same time.
    $workers = [];
    foreach ([['R59L-A-RED', 'R59L-B-RED'], ['R59L-B-BLUE', 'R59L-A-BLUE']] as $skus) {
        $process = proc_open(array_merge([PHP_BINARY, __FILE__, 'worker', $target, $root], $skus), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $out['workers'] = [];
    foreach ($workers as [$process, $pipes]) {
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $out['workers'][] = json_decode($stdout, true) ?? ['error' => substr($stdout . $stderr, -800)];
    }
    $out['reserved'] = (int)$db->query("SELECT SUM(reserved) FROM warehouse_stock WHERE sku LIKE 'R59L-%'")->fetchColumn();
} finally {
    $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
echo json_encode($out, JSON_THROW_ON_ERROR);
PHP);
$r59lTarget = 'bbe_r59l_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
$r59lProcess = proc_open([PHP_BINARY, $r59lRoot . '/child.php', 'main', $r59lTarget, dirname(TEST_CLI_DIR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r59lPipes);
fclose($r59lPipes[0]);
$r59lStdout = (string)stream_get_contents($r59lPipes[1]);
$r59lStderr = (string)stream_get_contents($r59lPipes[2]);
fclose($r59lPipes[1]);
fclose($r59lPipes[2]);
$r59lExit = proc_close($r59lProcess);
$r59lOut = json_decode($r59lStdout, true);
if ($r59lExit !== 0 || !is_array($r59lOut)) {
    assert_true(false, "R59 checkout locks: child shop ran (exit {$r59lExit}: " . substr($r59lStdout . $r59lStderr, -1500) . ')');
} else {
    assert_equals('1205', $r59lOut['product'] ?? null,
        'R59 checkout locks: a checkout\'s up-front lock holds the product rows of its lines (was: ok, only warehouse_stock was locked)');
    assert_equals('1205', $r59lOut['variant'] ?? null, 'R59 checkout locks: and the variant rows of its lines');
    $r59lDeadlocks = 0;
    $r59lOk = 0;
    foreach ($r59lOut['workers'] ?? [] as $r59lWorker) {
        assert_equals('', $r59lWorker['other'] ?? null, 'R59 checkout locks: a shopper\'s checkouts fail with no other error (' . json_encode($r59lWorker) . ')');
        $r59lDeadlocks += (int)($r59lWorker['deadlock'] ?? 0);
        $r59lOk += (int)($r59lWorker['ok'] ?? 0);
    }
    assert_equals(0, $r59lDeadlocks, 'R59 checkout locks: two shoppers buying variants of A and B in reverse order never deadlock (was: 1213 on product rows)');
    assert_equals(60, $r59lOk, 'R59 checkout locks: all 60 checkouts go through');
    assert_equals(120, $r59lOut['reserved'] ?? null, 'R59 checkout locks: every committed line reserved its unit');
}
foreach (glob($r59lRoot . '/*') ?: [] as $r59lFile) @unlink($r59lFile);
@rmdir($r59lRoot);
