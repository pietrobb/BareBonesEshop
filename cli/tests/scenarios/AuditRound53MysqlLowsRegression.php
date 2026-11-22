<?php
$pdo ??= test_commerce_fixture();

// Review round 53 (MySQL lows): the credit check of a B2B order on invoice read the members' orders with a locking read
// on idx_ord_customer, which also locked the index gaps next to them: a guest order or one of the newest customer waited
// for the B2B order to commit. The check for open orders without a stored OSS net read every order on each checkout.
echo "\n--- Audit round 53: B2B credit without gap locks, OSS net lookup on its index ---\n";

$r53qRoot = test_fixture_path('r53-mysql-lows');
mkdir($r53qRoot);
file_put_contents($r53qRoot . '/mysql.php', <<<'PHP'
<?php
[, $phase, $root, $target] = $argv;
require $root . '/src/autoload.php';
final class R53qStatement extends PDOStatement {
    public static array $log = [];
    protected function __construct() {}
    public function execute(?array $params = null): bool { self::$log[] = [$this->queryString, $params ?? []]; return parent::execute($params); }
}
$connect = static function () use ($target, $phase): PDO {
    $db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($phase !== 'run' && $phase !== 'teardown') $db->exec("USE `{$target}`");
    return $db;
};
$a = $connect();
if ($phase === 'teardown') { $a->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
if ($phase === 'holder') {
    // Another member's order on invoice: holds the company, places 900 €, commits a moment later.
    [, , , , $company, $member] = $argv;
    Db::init($a, 'mysql');
    Bootstrap::initStoreContext('/', '', 'default');
    $a->beginTransaction();
    Customers::lockInvoiceCredit((int)$company);
    $a->prepare("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale)
        VALUES ('R53Q-HOLDER', 'pending', 'pending', ?, 'R53Q', 'r53q-holder@example.test', '{}', 'invoice', 90000, 90000, 'eur', 'default', 'sk')")->execute([(int)$member]);
    echo "locked\n";
    usleep(1500000);
    $a->commit();
    exit(0);
}
$a->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
$a->exec("USE `{$target}`");
Db::init($a, 'mysql');
(new Migrator($a, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
Bootstrap::initStoreContext('/', '', 'default');
$company = $a->prepare("INSERT INTO companies (store_id, code, legal_name, approval_status, credit_limit, payment_terms_days, invoice_purchase, active) VALUES ('default', ?, ?, 'approved', 100000, 14, 1, 1)");
$customer = $a->prepare("INSERT INTO customers (store_id, email, password_hash, name, company_id, status) VALUES ('default', ?, 'x', 'R53Q', ?, 'active')");
$order = $a->prepare("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale)
    VALUES (?, 'pending', 'pending', ?, 'R53Q', 'r53q@example.test', '{}', ?, 1000, 1000, 'eur', 'default', 'sk')");
for ($i = 0; $i < 5; $i++) $order->execute(["R53Q-GUEST{$i}", null, 'card']);
$members = [];
foreach (['a', 'b'] as $code) {
    $company->execute(["r53q-{$code}", "R53Q {$code}"]);
    $companyId = (int)$a->lastInsertId();
    for ($i = 0; $i < 3; $i++) {
        $customer->execute(["r53q-{$code}{$i}@example.test", $companyId]);
        $members[$code][] = [$companyId, (int)$a->lastInsertId()];
        $order->execute(["R53Q-{$code}{$i}", (int)$a->lastInsertId(), 'invoice']);
    }
}
for ($i = 0; $i < 20; $i++) $customer->execute(["r53q-retail{$i}@example.test", null]);
$newest = (int)$a->lastInsertId();
$b = $connect();
$b->exec("USE `{$target}`");
$b->exec('SET SESSION innodb_lock_wait_timeout = 1');
$out = [];
// A B2B order on invoice of company A (members with the lowest ids, next to the guests) and one of company B (members
// with the highest ids that have orders) checks its credit and has not committed; a guest and the newest customer order.
foreach ([['a', null, 'guest'], ['b', $newest, 'newest customer']] as [$code, $buyer, $label]) {
    [$companyId, $member] = $members[$code][0];
    $a->beginTransaction();
    if (method_exists(Customers::class, 'lockInvoiceCredit')) Customers::lockInvoiceCredit($companyId);
    Customers::assertInvoiceCredit($member, 500, $companyId);
    try {
        $b->prepare("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale)
            VALUES (?, 'pending', 'pending', ?, 'R53Q', 'r53q-other@example.test', '{}', 'card', 1000, 1000, 'eur', 'default', 'sk')")->execute(['R53Q-OTHER-' . $code, $buyer]);
        $out[$label] = 'placed';
    } catch (PDOException $e) {
        $out[$label] = 'waited: ' . $e->getMessage();
    }
    $a->rollBack();
}
// Freshness: another member's 900 € order holds company A and commits while this checkout waits for the company. The
// checkout (Orders::create, its credit read without a locking read) sees it and refuses 200 € more on a 1000 € limit.
$a->exec("INSERT INTO categories (name, slug, store_id) VALUES ('R53Q', 'r53q', 'default')");
$category = (int)$a->lastInsertId();
$a->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy)
    VALUES (98551, 'default', {$category}, 'R53Q-GOODS', 'r53q-goods', 'simple', 'R53Q goods', 20000, 'eur', 10, 'active', 'deny')");
$warehouse = (int)$a->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
$a->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$warehouse}, 'R53Q-GOODS', 10, 0)");
$holder = proc_open([PHP_BINARY, __FILE__, 'holder', $root, $target, (string)$members['a'][0][0], (string)$members['a'][1][1]],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fclose($pipes[0]);
$out['holder'] = trim((string)fgets($pipes[1]));
try {
    Orders::create([['product_id' => 98551, 'variant_id' => null, 'sku' => 'R53Q-GOODS', 'name' => 'R53Q goods', 'qty' => 1, 'price' => 20000, 'attrs' => []]],
        ['name' => 'R53Q', 'email' => 'r53q-a2@example.test', 'phone' => '', 'address' => [], 'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0,
         'currency' => 'eur', 'customer_id' => $members['a'][2][1], 'payment_method_snapshot' => json_encode(['code' => 'invoice', 'service_type' => 'invoice', 'payment_terms_days' => 14])], 'invoice');
    $out['checkout'] = 'placed';
} catch (CreditLimitExceededException $e) {
    $out['checkout'] = 'refused';
}
stream_get_contents($pipes[1]);
$out['holder_err'] = trim((string)stream_get_contents($pipes[2]));
fclose($pipes[1]);
fclose($pipes[2]);
$out['holder_exit'] = proc_close($holder);
// The lookup of open orders without a stored OSS net reads idx_orders_store_oss_net, not every order.
$a->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, oss_net, created_at)
    WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 900)
    SELECT CONCAT('R53Q-N', n), 'delivered', 'paid', 'R53Q', 'r53q@example.test', '{}', 'card', 1000, 1000, 'eur', 'default', 'sk', 0, UTC_TIMESTAMP() - INTERVAL n HOUR FROM seq");
$a->exec('UPDATE orders SET oss_net = 0 WHERE oss_net IS NULL');
$a->query('ANALYZE TABLE orders')->fetchAll();
$a->setAttribute(PDO::ATTR_STATEMENT_CLASS, [R53qStatement::class]);
(new ReflectionMethod(OssThreshold::class, 'storeMissingNets'))->invoke(null, $a, (int)gmdate('Y'));
$a->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
foreach (R53qStatement::$log as [$sql, $params]) {
    if (!str_contains($sql, 'oss_net IS NULL AND created_at >= ? LIMIT 1')) continue;
    $explain = $a->prepare('EXPLAIN ' . $sql);
    $explain->execute($params);
    $plan = $explain->fetch();
    $out['missing_nets'] = $plan['type'] . ':' . $plan['key'];
}
// An update from 2.1.2 right after a bulk import: order_items statistics still count the table as empty (frozen here
// by STATS_AUTO_RECALC=0). The physical OSS backfill's NOT EXISTS then scanned order_items once per invoice. Its index
// as 2.1.2 has it when that migration runs (idx_oi_order; the covering index comes with 20261121090000).
$a->exec('ALTER TABLE order_items ADD INDEX idx_oi_order (order_id), DROP INDEX idx_oi_order_cover, STATS_AUTO_RECALC = 0');
$a->exec('SET SESSION foreign_key_checks = 0');
$a->exec('TRUNCATE TABLE order_items');
$a->exec('SET SESSION foreign_key_checks = 1');
$a->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, created_at)
    WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 900)
    SELECT CONCAT('R53Q-S', n), 'delivered', 'paid', 'R53Q', 'r53q@example.test', '{}', 'card', 1000, 1000, 'eur', 'default', 'sk', UTC_TIMESTAMP() - INTERVAL n HOUR FROM seq");
$a->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) SELECT id, 98551, 'R53Q-GOODS', 'R53Q', 1, 1000, 1000 FROM orders WHERE order_number LIKE 'R53Q-S%'");
$a->exec("INSERT INTO invoices (order_id, number, type, status, issued_at, currency, total_net, total_vat, total_gross, vat_breakdown, pdf_path, download_token, store_id)
    SELECT id, CONCAT('R53Q-F', id), 'invoice', 'issued', created_at, 'eur', 813, 187, 1000, '[]', '', SHA2(CONCAT('r53q', id), 256), 'default' FROM orders WHERE order_number LIKE 'R53Q-S%'");
$analyze = $root . '/migrations/20261113175958_analyze_order_tables.php';
if (is_file($analyze)) (require $analyze)->up($a, false);
$reads = static fn(): int => (int)$a->query("SHOW SESSION STATUS LIKE 'Handler_read_rnd_next'")->fetch()['Value'];
$backfill = "SELECT COUNT(*) FROM invoices WHERE oss_net IS NULL AND status = 'issued' AND type = 'invoice' AND issued_at >= '2000-01-01'
    AND EXISTS (SELECT 1 FROM orders o WHERE o.id = invoices.order_id)
    AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = invoices.order_id AND oi.digital = 1)";
try {
    $out['backfill_plan'] = (string)$a->query('EXPLAIN FORMAT=TREE ' . $backfill)->fetchColumn();
} catch (PDOException) {
    $out['backfill_plan'] = json_encode($a->query('EXPLAIN ' . $backfill)->fetchAll()); // MariaDB
}
$start = $reads();
$a->query($backfill)->fetchAll();
$out['backfill_scanned_rows'] = $reads() - $start;
// The persistent statistics: what the optimizer reloads when the table is reopened (as an ALTER TABLE products of the
// same update does), the row count it plans the backfill with.
// Readable by an account with access to the mysql schema only (CI's test account has none: not asserted there).
try {
    $persisted = $a->prepare('SELECT n_rows FROM mysql.innodb_table_stats WHERE database_name = DATABASE() AND table_name = ?');
    $persisted->execute(['order_items']);
    $out['order_items_estimate'] = (int)$persisted->fetchColumn();
} catch (PDOException) {
    $out['order_items_estimate'] = null;
}
$a->exec('ALTER TABLE order_items STATS_AUTO_RECALC = DEFAULT');
echo json_encode($out);
PHP);
$r53qPhp = static function (array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
if (getenv('TEST_MYSQL_DSN')) {
    $r53qTarget = 'bbe_r53q_lows_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
    try {
        [$r53qCode, $r53qOut] = $r53qPhp([$r53qRoot . '/mysql.php', 'run', dirname(TEST_CLI_DIR), $r53qTarget]);
        $r53qResult = json_decode($r53qOut, true) ?? $r53qOut;
        assert_equals([0, 'placed', 'placed'], [$r53qCode, $r53qResult['guest'] ?? $r53qOut, $r53qResult['newest customer'] ?? null],
            'R53 mysql: while a B2B order on invoice checks its credit, a guest order and an order of the newest customer are placed at once (was: they waited on gap locks of idx_ord_customer; '
            . substr(json_encode([$r53qCode, $r53qResult['guest'] ?? $r53qOut, $r53qResult['newest customer'] ?? null]), 0, 600) . ')');
        assert_equals(['locked', 'refused', 0], [$r53qResult['holder'] ?? null, $r53qResult['checkout'] ?? null, $r53qResult['holder_exit'] ?? null],
            'R53 mysql: a member\'s 900 € order committed while another member\'s checkout waited for the company is counted: 200 € more on a 1000 € limit is refused (' . ($r53qResult['holder_err'] ?? '') . ')');
        assert_true(in_array($r53qResult['missing_nets'] ?? null, ['range:idx_orders_store_oss_net', 'ref:idx_orders_store_oss_net'], true),
            'R53 mysql: the checkout\'s lookup of open orders without a stored OSS net reads idx_orders_store_oss_net (was: every order, 0,1-0,8 s at 500 000; ' . json_encode($r53qResult['missing_nets'] ?? null) . ')');
        if (is_array($r53qResult) && array_key_exists('order_items_estimate', $r53qResult) && $r53qResult['order_items_estimate'] === null) {
            print "  [SKIP] R53 mysql statistics refresh (the test account cannot read mysql.innodb_table_stats)\n";
        } else assert_true(($r53qResult['order_items_estimate'] ?? 0) >= 450,
            'R53 mysql: with order_items statistics left behind by a bulk import, the update from 2.1.2 refreshes them before the OSS backfills, which then read order_items by its index, not once per invoice and open order (was: 2 min at 20 000 orders, 6-16 min at 100 000; '
            . json_encode($r53qResult['backfill_scanned_rows'] ?? null) . ' rows scanned, statistics count ' . json_encode($r53qResult['order_items_estimate'] ?? null) . ' of 900 lines: ' . json_encode($r53qResult['backfill_plan'] ?? null) . ')');
    } finally {
        $r53qPhp([$r53qRoot . '/mysql.php', 'teardown', dirname(TEST_CLI_DIR), $r53qTarget]);
    }
} else {
    print "  [SKIP] R53 mysql B2B credit gap locks and OSS net lookup (set TEST_MYSQL_DSN)\n";
}
foreach (glob($r53qRoot . '/*') ?: [] as $r53qFile) unlink($r53qFile);
rmdir($r53qRoot);
