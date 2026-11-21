<?php

// Review round 21 (R51, MySQL lows):
//  - the credit check of a B2B order on invoice (Customers::companyOpenTotal(), a locking read in the order transaction)
//    found the company's members through an index starting with customer_group_id: MySQL locked every customer of the
//    store and the orders on invoice of other companies waited on it;
//  - a 365-day sales report took 13 s at 500 000 orders: every order and order line of the period was read and joined
//    to its refunds and credits. The reports read covering indexes and correct only the orders with a refund or credit.
echo "\n--- Audit round 51: B2B credit check locks one company, sales reports from covering indexes ---\n";

$r51pDb = Db::get();
$r51pAt = static fn(int $secondsAgo): string => gmdate('Y-m-d H:i:s', time() - $secondsAgo);
$r51pOrders = [];
try {
    $r51pCategory = (int)$r51pDb->query('SELECT MIN(id) FROM categories')->fetchColumn();
    $r51pProduct = $r51pDb->prepare("INSERT INTO products (id, category_id, name, slug, sku, price, store_id) VALUES (?, ?, ?, ?, ?, 1000, 'default')");
    foreach ([951051 => 'R51R-NEW', 951052 => 'R51R-B', 951053 => 'R51R-C'] as $r51pId => $r51pSku) $r51pProduct->execute([$r51pId, $r51pCategory, $r51pSku, strtolower($r51pSku), $r51pSku]);
    $r51pOrder = $r51pDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method,
        subtotal, total, currency, store_id, locale, is_synthetic, created_at) VALUES (?, ?, 'paid', 'R51R', 'r51r@example.test', '{}', 'card', ?, ?, 'XTS', 'default', 'sk', ?, ?)");
    $r51pLine = $r51pDb->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, ?, ?)');
    $r51pAdd = static function (string $status, int $total, int $synthetic, int $ago, array $lines) use ($r51pDb, $r51pOrder, $r51pLine, $r51pAt, &$r51pOrders): int {
        $r51pOrder->execute(['R51R-' . bin2hex(random_bytes(4)), $status, $total, $total, $synthetic, $r51pAt($ago)]);
        $id = (int)$r51pDb->lastInsertId();
        $r51pOrders[] = $id;
        foreach ($lines as [$product, $sku, $name, $amount]) $r51pLine->execute([$id, $product, $sku, $name, $amount, $amount]);
        return $id;
    };
    // Older line of the product under its old SKU and name, with half of the order refunded (500 of 1000).
    $r51pRefunded = $r51pAdd('delivered', 1000, 0, 7200, [[951051, 'R51R-OLD', 'R51R old name', 600], [951052, 'R51R-B', 'R51R B', 400]]);
    $r51pDb->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'stripe', 'partially_refunded', 500, 'XTS', ?, 'default')")
        ->execute([$r51pRefunded, 'r51r-' . bin2hex(random_bytes(4))]);
    $r51pAdd('delivered', 700, 0, 3600, [[951051, 'R51R-NEW', 'R51R new name', 700]]);
    $r51pAdd('cancelled', 5000, 0, 3000, [[951053, 'R51R-C', 'R51R C', 5000]]); // paid, then cancelled: not revenue
    $r51pAdd('delivered', 9000, 1, 3000, [[951053, 'R51R-C', 'R51R C', 9000]]); // post-deploy smoke order

    $r51pSince = SalesReport::sinceUtc(2);
    $r51pTotals = SalesReport::totals('default', $r51pSince);
    assert_equals(['orders' => 2, 'revenue' => 1200, 'aov' => 600], $r51pTotals['xts'] ?? null,
        'R51 reports: the period counts the paid orders less what was refunded of them, not a cancelled or smoke order');
    assert_equals($r51pTotals, SalesReport::totalsOf(SalesReport::trend('default', 2)), 'R51 reports: the report page\'s totals summed from its daily trend are the totals of the period');
    $r51pTop = array_map(static fn(array $r): array => [$r['product_id'], $r['sku'], $r['name'], $r['quantity'], $r['revenue']], SalesReport::topProducts('default', $r51pSince)['xts'] ?? []);
    assert_equals([[951051, 'R51R-NEW', 'R51R new name', 2, 1000], [951052, 'R51R-B', 'R51R B', 1, 200]], $r51pTop,
        'R51 reports: a best seller keeps its share of the refunded order (300 + 700) and shows its latest SKU and name (was: the alphabetically last, its old name)');
} catch (Throwable $e) {
    assert_true(false, 'R51 reports (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    if ($r51pOrders !== []) {
        $r51pIds = implode(',', $r51pOrders);
        foreach (['payment_transactions', 'order_items'] as $r51pTable) $r51pDb->exec("DELETE FROM {$r51pTable} WHERE order_id IN ({$r51pIds})");
        $r51pDb->exec("DELETE FROM orders WHERE id IN ({$r51pIds})");
    }
    $r51pDb->exec('DELETE FROM products WHERE id IN (951051, 951052, 951053)');
}

$r51pRoot = test_fixture_path('r51-mysql-perf');
mkdir($r51pRoot);
file_put_contents($r51pRoot . '/child.php', <<<'PHP'
<?php
[, $root, $target] = $argv;
require $root . '/src/autoload.php';
final class R51pStatement extends PDOStatement {
    public static array $log = [];
    protected function __construct() {}
    public function execute(?array $params = null): bool { self::$log[] = [$this->queryString, $params ?? []]; return parent::execute($params); }
}
$connect = static function (): PDO {
    $db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
};
$a = $connect();
$out = [];
try {
    $a->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    $a->exec("USE `{$target}`");
    Db::init($a, 'mysql');
    (new Migrator($a, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
    Bootstrap::initStoreContext('/', '', 'default');
    $company = $a->prepare("INSERT INTO companies (store_id, code, legal_name, approval_status, credit_limit, payment_terms_days, invoice_purchase, active) VALUES ('default', ?, ?, 'approved', 100000, 14, 1, 1)");
    $customer = $a->prepare("INSERT INTO customers (store_id, email, password_hash, name, company_id) VALUES ('default', ?, 'x', 'R51P', ?)");
    $order = $a->prepare("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale)
        VALUES (?, 'pending', 'pending', ?, 'R51P', ?, '{}', 'invoice', 1000, 1000, 'eur', 'default', 'sk')");
    $members = [];
    foreach (['a', 'b'] as $code) {
        $company->execute(["r51p-{$code}", "R51P {$code}"]);
        $companyId = (int)$a->lastInsertId();
        for ($i = 0; $i < 3; $i++) {
            $customer->execute(["r51p-{$code}{$i}@example.test", $companyId]);
            $members[$code][] = [$companyId, (int)$a->lastInsertId()];
            $order->execute(["R51P-{$code}{$i}", (int)$a->lastInsertId(), "r51p-{$code}{$i}@example.test"]);
        }
    }
    for ($i = 0; $i < 20; $i++) $customer->execute(["r51p-retail{$i}@example.test", null]);

    // Company A's order on invoice checks its credit (locking read) and has not committed yet.
    $a->beginTransaction();
    Customers::assertInvoiceCredit($members['a'][0][1], 500);
    // Meanwhile company B's order on invoice checks its own credit.
    $b = $connect();
    $b->exec("USE `{$target}`");
    $b->exec('SET SESSION innodb_lock_wait_timeout = 1');
    Db::init($b, 'mysql');
    $b->beginTransaction();
    try { $out['other_company'] = 'open ' . Customers::companyOpenTotal($members['b'][0][0], true); } catch (Throwable $e) { $out['other_company'] = 'blocked: ' . $e->getMessage(); }
    $b->rollBack();
    Db::init($a, 'mysql');
    $a->rollBack();

    // The reports read the store's paid orders and their lines from covering indexes.
    $a->setAttribute(PDO::ATTR_STATEMENT_CLASS, [R51pStatement::class]);
    $since = SalesReport::sinceUtc(365);
    SalesReport::totals('default', $since);
    SalesReport::topProducts('default', $since);
    $a->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    $plans = [];
    foreach (R51pStatement::$log as [$sql, $params]) {
        if (!str_contains($sql, 'GROUP BY LOWER(o.currency)') || str_contains($sql, ' UNION ') || str_contains($sql, 'payment_transactions pt')) continue;
        $explain = $a->prepare('EXPLAIN ' . $sql);
        $explain->execute($params);
        $plans[str_contains($sql, 'order_items oi') ? 'top' : 'totals'] = array_map(static fn(array $r): string => $r['table'] . ':' . $r['key'] . (str_contains((string)$r['Extra'], 'Using index') && !str_contains((string)$r['Extra'], 'Using index condition') ? ':covering' : ''),
            array_values(array_filter($explain->fetchAll(), static fn(array $r): bool => in_array($r['table'], ['o', 'oi'], true) || str_starts_with((string)$r['table'], '<derived'))));
    }
    $out['plans'] = $plans;
    // Filling search_names for 1200 products commits per batch, not per product.
    $a->exec("INSERT INTO categories (name, slug, store_id) VALUES ('R51P', 'r51p', 'default')");
    $category = (int)$a->lastInsertId();
    $product = $a->prepare("INSERT INTO products (category_id, name, slug, sku, price, translations, store_id) VALUES ({$category}, ?, ?, ?, 1000, ?, 'default')");
    $a->beginTransaction();
    for ($i = 0; $i < 1200; $i++) $product->execute(["R51P {$i}", "r51p-{$i}", "R51P-{$i}", json_encode(['cs' => ['name' => "R51P CZ {$i}"]])]);
    $a->commit();
    $a->exec('UPDATE products SET search_names = NULL');
    // Com_commit counts the explicit COMMITs (an autocommitted update adds none).
    $commits = static fn(): int => (int)$a->query("SHOW SESSION STATUS LIKE 'Com_commit'")->fetch()['Value'];
    $before = $commits();
    $out['names'] = [Search::refreshNames([], $a), $commits() - $before];
    $out['fulltext'] = $a->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_TYPE = 'FULLTEXT' ORDER BY INDEX_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $out['indexes'] = $a->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
        AND INDEX_NAME IN ('idx_orders_store_payment_created', 'idx_oi_order', 'idx_customers_store_company', 'idx_orders_store_payment_cover', 'idx_oi_order_cover') ORDER BY INDEX_NAME")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
} finally {
    $connect()->exec("DROP DATABASE IF EXISTS `{$target}`");
}
echo 'R51PRESULT' . json_encode($out);
PHP);
if (getenv('TEST_MYSQL_DSN')) {
    $r51pProcess = proc_open([PHP_BINARY, $r51pRoot . '/child.php', dirname(TEST_CLI_DIR), 'bbe_r51p_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r51pPipes);
    fclose($r51pPipes[0]);
    $r51pRaw = stream_get_contents($r51pPipes[1]) . stream_get_contents($r51pPipes[2]);
    fclose($r51pPipes[1]); fclose($r51pPipes[2]);
    proc_close($r51pProcess);
    $r51pAt = strrpos($r51pRaw, 'R51PRESULT');
    $r51pResult = $r51pAt === false ? ['error' => substr($r51pRaw, 0, 1500)] : (json_decode(substr($r51pRaw, $r51pAt + 10), true) ?? ['error' => $r51pRaw]);
    assert_true(!isset($r51pResult['error']), 'R51 mysql perf: the isolated shop completes (' . ($r51pResult['error'] ?? '') . ')');
    assert_equals('open 3000', $r51pResult['other_company'] ?? null,
        'R51 mysql B2B: another company\'s credit check does not wait on a company\'s open order on invoice (was: lock wait timeout, every customer of the store locked)');
    assert_equals(['idx_customers_store_company', 'idx_oi_order_cover', 'idx_orders_store_payment_cover'], $r51pResult['indexes'] ?? null,
        'R51 mysql: the credit-check and covering report indexes exist and replace the narrower ones they start with');
    // The refunds are summed from the refunds (a separate statement), not joined as a derived table to each order.
    // MariaDB reads the orders from the index alone (":covering"), MySQL 8.4 also looks at the row: both use the index.
    $r51pOrders = static fn(?array $plan): ?array => $plan === null ? null
        : array_map(static fn(string $row): string => $row === 'o:idx_orders_store_payment_cover:covering' ? 'o:idx_orders_store_payment_cover' : $row, $plan);
    assert_equals(['o:idx_orders_store_payment_cover'], $r51pOrders($r51pResult['plans']['totals'] ?? null),
        'R51 mysql reports: the totals read the paid orders of the period through the covering index and join no refund table to every order');
    assert_equals(['o:idx_orders_store_payment_cover', 'oi:idx_oi_order_cover:covering'], $r51pOrders($r51pResult['plans']['top'] ?? null),
        'R51 mysql reports: the best sellers read the order lines from the index alone and join no refund or credit table to every line');
    assert_equals([1200, 3], $r51pResult['names'] ?? null,
        'R51 mysql search names: filling products.search_names for 1200 products takes 3 transactions of 500 (was: an autocommit per product, minutes at 100 000)');
    assert_equals(['idx_ft_filters', 'idx_ft_products_names'], $r51pResult['fulltext'] ?? null,
        'R51 mysql search names: the full-text index the search no longer reads is dropped (was: idx_ft_products kept beside idx_ft_products_names)');
} else {
    print "  [SKIP] R51 mysql B2B credit lock and covering report indexes (set TEST_MYSQL_DSN)\n";
}
foreach (glob($r51pRoot . '/*') ?: [] as $r51pFile) unlink($r51pFile);
rmdir($r51pRoot);
