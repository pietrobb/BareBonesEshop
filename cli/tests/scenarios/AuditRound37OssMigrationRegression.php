<?php

echo "\n--- R37 OSS migration: bulk physical invoices equal documentNet, complex documents keep their calculation ---\n";
$r37mDir = test_fixture_path('oss-migration-r37');
mkdir($r37mDir);
$r37mWorker = $r37mDir . '/migration.php';
file_put_contents($r37mWorker, <<<'PHP'
<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql' ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]) : new PDO('sqlite:' . $dir . '/migration.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r37m_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
try {
    $db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, store_id VARCHAR(64), shipping_addr TEXT, billing_addr TEXT, customer_name TEXT, currency VARCHAR(8), status VARCHAR(32), created_at VARCHAR(32), vat_scenario VARCHAR(32), net_total BIGINT, financial_snapshot TEXT)');
    $db->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER, digital INTEGER, net_amount BIGINT)');
    $db->exec('CREATE INDEX idx_order_items_order ON order_items (order_id)');
    $db->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, order_id INTEGER, store_id VARCHAR(64), status VARCHAR(32), type VARCHAR(32), issued_at VARCHAR(32), currency VARCHAR(8), vat_scenario VARCHAR(32), total_net BIGINT, financial_snapshot TEXT, credit_snapshot TEXT, refers_to_invoice_id INTEGER, oss_net BIGINT DEFAULT NULL)');
    $db->exec('CREATE INDEX idx_invoices_order ON invoices (order_id)');
    $year = (int)ShopTime::date('Y');
    $order = $db->prepare('INSERT INTO orders (id, store_id, shipping_addr, billing_addr, customer_name, currency, status, created_at, vat_scenario, net_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $doc = $db->prepare('INSERT INTO invoices (id, order_id, store_id, status, type, issued_at, currency, vat_scenario, total_net, credit_snapshot, refers_to_invoice_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $cases = [
        ['oss', 'AT', 'AT', 10000, 0, 'invoice', null, null, 'issued'],
        ['domestic_subthreshold', 'AT', 'AT', 10000, 0, 'invoice', null, null, 'issued'],
        ['domestic', 'SK', 'AT', 10000, 0, 'invoice', null, null, 'issued'],
        ['export', 'US', 'US', 10000, 0, 'invoice', null, null, 'issued'],
        ['reverse_charge', 'AT', 'AT', 10000, 0, 'invoice', null, null, 'issued'],
        ['oss', 'AT', 'SK', 10000, 3000, 'invoice', null, null, 'issued'],
        ['domestic', 'SK', 'AT', 10000, 3000, 'invoice', null, null, 'issued'],
        ['export', 'US', 'AT', 10000, 3000, 'invoice', null, null, 'issued'],
        ['oss', 'AT', 'AT', -4000, 0, 'credit_note', 1, ['kind' => 'refund', 'totals' => ['net' => 4000]], 'issued'],
        ['oss', 'AT', 'AT', 4000, 0, 'credit_note', 1, ['kind' => 'reversal', 'reverses_credit_note_id' => 9], 'issued'],
        ['oss', 'AT', 'AT', 10000, 0, 'invoice', null, null, 'cancelled'],
    ];
    foreach ($cases as $n => [$scenario, $shipping, $billing, $net, $digital, $type, $ref, $credit, $status]) {
        $id = $n + 1;
        $order->execute([$id, 'default', json_encode(['country' => $shipping]), json_encode(['country' => $billing]), 'R37M', 'eur', 'pending', $year . '-01-02', $scenario, abs($net)]);
        $doc->execute([$id, $id, 'default', $status, $type, $year . '-01-03', 'eur', $scenario, $net, $credit === null ? null : json_encode($credit), $ref]);
        $db->prepare('INSERT INTO order_items (id, order_id, digital, net_amount) VALUES (?, ?, 0, ?)')->execute([$id * 2, $id, abs($net) - $digital]);
        if ($digital > 0) $db->prepare('INSERT INTO order_items (id, order_id, digital, net_amount) VALUES (?, ?, 1, ?)')->execute([$id * 2 + 1, $id, $digital]);
    }
    $expected = [];
    foreach ($db->query("SELECT * FROM invoices WHERE status = 'issued' ORDER BY id")->fetchAll() as $row) $expected[(int)$row['id']] = OssThreshold::documentNet($db, $row);
    $count = max(1, min(200000, (int)(getenv('R37_OSS_MIGRATION_DOCS') ?: 100)));
    $db->beginTransaction();
    for ($from = 0; $from < $count; $from += 500) {
        $orders = []; $invoices = [];
        for ($i = $from; $i < min($from + 500, $count); $i++) {
            $id = 100 + $i;
            $orders[] = "({$id}, 'default', '{\"country\":\"AT\"}', 'R37M', 'eur', 'delivered', '{$year}-01-02', 'oss', 100)";
            $invoices[] = "({$id}, {$id}, 'default', 'issued', 'invoice', '{$year}-01-03', 'eur', 'oss', 100)";
        }
        $db->exec('INSERT INTO orders (id, store_id, shipping_addr, customer_name, currency, status, created_at, vat_scenario, net_total) VALUES ' . implode(',', $orders));
        $db->exec('INSERT INTO invoices (id, order_id, store_id, status, type, issued_at, currency, vat_scenario, total_net) VALUES ' . implode(',', $invoices));
    }
    $db->commit();
    $baselineMs = null;
    if ($baseline = getenv('R37_OSS_BASELINE_MIGRATION')) {
        $start = microtime(true);
        if ($driver === 'sqlite') $db->beginTransaction(); (require $baseline)->up($db, $driver === 'sqlite'); if ($driver === 'sqlite') $db->commit();
        $baselineMs = (microtime(true) - $start) * 1000;
        $db->exec('UPDATE invoices SET oss_net = NULL');
    }
    $start = microtime(true);
    if ($driver === 'sqlite') $db->beginTransaction(); (require $root . '/migrations/20261113175959_oss_physical_backfill.php')->up($db, $driver === 'sqlite'); (require $root . '/migrations/20261113180000_invoice_oss_net.php')->up($db, $driver === 'sqlite'); if ($driver === 'sqlite') $db->commit();
    $migrationMs = (microtime(true) - $start) * 1000;
    $actual = array_map('intval', $db->query("SELECT id, oss_net FROM invoices WHERE id < 100 AND status = 'issued' ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR));
    $checks = [
        ['physical, mixed digital, export, reverse charge, credit and reversal agree with documentNet', $actual === $expected],
        ['all recent issued documents are filled', (int)$db->query("SELECT COUNT(*) FROM invoices WHERE status = 'issued' AND oss_net IS NULL")->fetchColumn() === 0],
        ['cancelled document is untouched', $db->query('SELECT oss_net FROM invoices WHERE id = 11')->fetchColumn() === null],
        ['bulk history total is exact', (int)$db->query('SELECT SUM(oss_net) FROM invoices WHERE id >= 100')->fetchColumn() === $count * 100],
    ];
    (require $root . '/migrations/20261113190000_oss_threshold_totals.php')->up($db, $driver === 'sqlite');
    Db::forgetSchema($db);
    $net = (int)$db->query('SELECT SUM(net) FROM oss_threshold_totals')->fetchColumn();
    $checks[] = ['following totals migration includes the bulk-filled documents exactly once', $net === array_sum($expected) + $count * 100];
    // A rerun with the live cache must use record(), never fill columns without updating the cache.
    $db->exec('UPDATE invoices SET oss_net = NULL WHERE id = 1');
    $db->exec('UPDATE oss_threshold_totals SET net = net - 10000');
    if ($driver === 'sqlite') $db->beginTransaction(); (require $root . '/migrations/20261113175959_oss_physical_backfill.php')->up($db, $driver === 'sqlite'); (require $root . '/migrations/20261113180000_invoice_oss_net.php')->up($db, $driver === 'sqlite'); if ($driver === 'sqlite') $db->commit();
    $checks[] = ['rerun with an existing cache repairs the missing document and adds its net', (int)$db->query('SELECT SUM(net) FROM oss_threshold_totals')->fetchColumn() === $net];
    if ($driver === 'sqlite') $db->beginTransaction(); (require $root . '/migrations/20261113175959_oss_physical_backfill.php')->up($db, $driver === 'sqlite'); (require $root . '/migrations/20261113180000_invoice_oss_net.php')->up($db, $driver === 'sqlite'); if ($driver === 'sqlite') $db->commit();
    $checks[] = ['rerun is idempotent', (int)$db->query('SELECT SUM(net) FROM oss_threshold_totals')->fetchColumn() === $net];
    echo json_encode(['checks' => $checks, 'bench' => compact('count', 'baselineMs', 'migrationMs')]);
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
foreach (['sqlite', 'mysql'] as $r37mDriver) {
    if ($r37mDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { echo "  [SKIP] R37 migration native MySQL\n"; continue; }
    $proc = proc_open([PHP_BINARY, $r37mWorker, dirname(TEST_CLI_DIR), $r37mDir, $r37mDriver], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc); $result = json_decode($raw, true);
    assert_true($exit === 0 && is_array($result), "R37 migration {$r37mDriver}: isolated fixture ({$err})");
    foreach ($result['checks'] ?? [] as [$label, $ok]) assert_true($ok, "R37 migration {$r37mDriver}: {$label}");
    echo "R37 migration {$r37mDriver}: " . json_encode($result['bench'] ?? []) . "\n";
}
