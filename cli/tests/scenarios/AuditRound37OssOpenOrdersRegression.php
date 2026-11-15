<?php

echo "\n--- R37 OSS open orders: invoiced orders leave the threshold's candidate index ---\n";
$r37oRoot = test_fixture_path('oss-open-r37');
mkdir($r37oRoot);
$r37oFixture = $r37oRoot . '/check.php';
file_put_contents($r37oFixture, <<<'PHP'
<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $dir . '/open.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r37o_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
try {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $stores = Bootstrap::stores();
    $stores['stores']['default']['oss_enabled'] = false;
    $stores['stores']['default']['legal']['country'] = 'SK';
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
    Bootstrap::initStoreContext('/', '', 'default');
    $year = (int)ShopTime::date('Y');
    $orderStmt = $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
        total, net_total, currency, status, payment_status, payment_method, shipping_method, store_id, vat_scenario, created_at)
        VALUES (?, 'R37O', 'r37o@example.test', '', '{\"country\":\"AT\"}', 120, 0, 20, 0, 120, 100, 'eur', 'pending', 'paid', 'bank_transfer', 'gls', 'default', 'oss', ?)");
    $invoiceStmt = $db->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type)
        VALUES (?, 'default', ?, ?, '', 100, 20, 120, 'eur', 'issued', ?, 'oss', 'invoice')");
    $net = static fn(int $id): int => (int)$db->query("SELECT oss_net FROM orders WHERE id = {$id}")->fetchColumn();
    $sum = static fn(): int => OssThreshold::worstYear($db, 'default', $stores['stores']['default'], $stores['stores'])['sum'];
    $checks = [];
    $check = static function(bool $ok, string $label) use (&$checks): void { $checks[] = [$label, $ok]; };
    $orderStmt->execute(['R37O-1', $year . '-01-02 12:00:00']);
    $order = (int)$db->lastInsertId();
    OssThreshold::recordOrder($db, $order);
    $check($net($order) === 100 && $sum() === 100, 'an uninvoiced order counts');
    $db->beginTransaction();
    $invoiceStmt->execute([$order, 'R37O-1', $year . '-01-03', 'r37o-1']);
    $invoice = (int)$db->lastInsertId();
    OssThreshold::record($db, $invoice);
    $db->commit();
    $check($net($order) === 0 && $sum() === 100, 'issuing clears the open-order index and counts once');
    OssThreshold::recordOrder($db, $order);
    $check($net($order) === 0 && $sum() === 100, 'refreshing an invoiced order does not reintroduce it');
    InvoiceService::cancel($invoice, 'R37O cancel');
    $check($net($order) === 100 && $sum() === 100, 'cancelling an invoice restores a live order contribution');
    InvoiceService::cancel($invoice, 'R37O repeated cancel');
    $check($net($order) === 100 && $sum() === 100, 'repeated cancellation is idempotent');
    $db->prepare("UPDATE invoices SET type = 'replaced' WHERE id = ?")->execute([$invoice]);
    $invoiceStmt->execute([$order, 'R37O-REISSUE', $year . '-01-03', 'r37o-reissue']);
    $invoice = (int)$db->lastInsertId();
    OssThreshold::record($db, $invoice);
    $check($net($order) === 0 && $sum() === 100, 'a replacement invoice removes the order contribution again');
    $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$order]);
    InvoiceService::cancel($invoice, 'R37O closed order');
    $check($net($order) === 0 && $sum() === 0, 'cancelling the invoice of a cancelled order does not restore it');

    $count = max(1, min(200000, (int)(getenv('R37_OSS_BENCH_ORDERS') ?: 100)));
    $db->beginTransaction();
    for ($i = 0; $i < $count; $i++) {
        $orderStmt->execute(['R37O-B' . $i, $year . '-01-02 12:00:00']);
        $id = (int)$db->lastInsertId();
        // Emulate the old release: every already-invoiced order still has a positive oss_net.
        $db->prepare('UPDATE orders SET oss_net = 100 WHERE id = ?')->execute([$id]);
        $invoiceStmt->execute([$id, 'R37O-B' . $i, $year . '-01-03', 'r37o-b' . $i]);
        $doc = (int)$db->lastInsertId();
        $db->prepare('UPDATE invoices SET oss_net = 100 WHERE id = ?')->execute([$doc]);
    }
    $db->prepare("INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES ('default', ?, 'eur', 0)")
        ->execute([$year - 1]);
    $db->prepare("UPDATE oss_threshold_totals SET net = ? WHERE store_id = 'default' AND year = ? AND currency = 'eur'")->execute([$count * 100, $year]);
    $db->commit();
    $start = microtime(true); $before = $sum(); $beforeMs = (microtime(true) - $start) * 1000;
    $start = microtime(true);
    (require $root . '/migrations/20261114200000_oss_open_orders.php')->up($db, $driver === 'sqlite');
    $migrationMs = (microtime(true) - $start) * 1000;
    $start = microtime(true); $after = $sum(); $afterMs = (microtime(true) - $start) * 1000;
    $candidates = (int)$db->query('SELECT COUNT(*) FROM orders WHERE oss_net > 0')->fetchColumn();
    $check($before === $count * 100 && $after === $before && $candidates === 0, 'migration removes old invoiced candidates without changing turnover');
    (require $root . '/migrations/20261114200000_oss_open_orders.php')->up($db, $driver === 'sqlite');
    $check($sum() === $after, 'migration is idempotent');
    // A VAT decision already at destination cannot be invalidated by another order crossing the threshold.
    $snapshot = ['scenario' => 'oss', 'currency' => 'eur', 'totals' => ['net' => 100], 'components' => [['type' => 'product', 'supply' => 'oss', 'net' => 100]]];
    $lock = OssThreshold::guardOrder($db, $snapshot, ['address' => ['country' => 'AT']], [['product_type' => 'simple']]);
    OssThreshold::releaseOrderLock($db, $lock);
    $check($lock === null, 'already-OSS orders do not serialize on the crossing-decision lock');
    $plans = []; if ($driver === 'mysql') foreach (["SELECT i.* FROM invoices i JOIN orders o ON o.id = i.order_id WHERE o.store_id = 'default' AND i.status = 'issued' AND i.type IN ('invoice', 'credit_note') AND i.issued_at >= '{$year}-01-01' AND i.issued_at < '" . ($year + 1) . "-01-01' AND i.oss_net IS NULL ORDER BY i.id", "SELECT LOWER(o.currency), SUM(o.oss_net) FROM orders o WHERE o.store_id = 'default' AND o.oss_net > 0 AND o.created_at >= '{$year}-01-01' AND o.status NOT IN ('cancelled','refunded','failed') AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.order_id = o.id AND i.type = 'invoice' AND i.status = 'issued') GROUP BY LOWER(o.currency)"] as $sql) $plans[] = $db->query('EXPLAIN ' . $sql)->fetchAll(); echo 'R37ORESULT' . json_encode(['checks' => $checks, 'bench' => compact('count', 'beforeMs', 'afterMs', 'migrationMs', 'candidates', 'plans')]);
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
foreach (['sqlite', 'mysql'] as $r37oDriver) {
    if ($r37oDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R37 OSS open orders native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
    $process = proc_open([PHP_BINARY, $r37oFixture, dirname(TEST_CLI_DIR), $r37oRoot, $r37oDriver], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R37ORESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    assert_true($exit === 0 && is_array($result), "R37 OSS open orders {$r37oDriver}: isolated lifecycle completes (" . ($at === false ? substr($raw, 0, 1200) : '') . ')');
    foreach ($result['checks'] ?? [] as [$label, $ok]) assert_true($ok, "R37 OSS open orders {$r37oDriver}: {$label}");
    print 'R37 OSS benchmark ' . $r37oDriver . ': ' . json_encode($result['bench'] ?? []) . "\n";
}
