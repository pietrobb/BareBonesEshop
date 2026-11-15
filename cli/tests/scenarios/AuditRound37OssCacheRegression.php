<?php

echo "\n--- R37 OSS cache: explicit read-only audit and locked transactional repair ---\n";
$r37cDir = test_fixture_path('oss-cache-r37');
mkdir($r37cDir);
$r37cWorker = $r37cDir . '/cache.php';
file_put_contents($r37cWorker, <<<'PHP'
<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql' ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]) : new ShopPDO('sqlite:' . $dir . '/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r37c_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
try {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $order = $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, net_total, currency, status, payment_status, payment_method, store_id, created_at, oss_net)
        VALUES (?, 'Cache', 'cache@example.test', '{}', 100, 100, 100, 'eur', 'delivered', 'paid', 'bank_transfer', ?, '2026-02-01', 123)");
    $invoice = $db->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, type, oss_net)
        VALUES (?, 'seller-not-order-store', ?, ?, '', 100, 0, 100, ?, ?, ?, ?, ?)");
    foreach ([['outlet', '2026-02-02', 'EUR', 'issued', 'invoice', 1100000], ['outlet', '2026-03-02', 'eur', 'issued', 'credit_note', -200000],
        ['default', '2025-02-02', 'czk', 'issued', 'invoice', 500], ['default', '2024-02-02', 'eur', 'issued', 'credit_note', -25],
        ['default', '2026-02-02', 'eur', 'issued', 'invoice', null], ['default', '2026-02-02', 'eur', 'cancelled', 'invoice', 700],
        ['default', '2026-02-02', 'eur', 'issued', 'proforma', 800], ['default', '2026-02-02', 'eur', 'issued', 'invoice', 0]] as $n => [$store, $date, $currency, $status, $type, $net]) {
        $order->execute(['R37C-' . $n, $store]);
        $invoice->execute([(int)$db->lastInsertId(), 'R37C-' . $n, $date, $currency, $status, 'r37c-' . $n, $type, $net]);
    }
    $db->exec("INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES ('outlet', 2026, 'eur', 42), ('default', 2025, 'czk', 500), ('ghost', 2026, 'eur', 99)");
    $snapshot = static function() use ($db): array {
        $out = [];
        foreach (['orders', 'invoices', 'oss_threshold_activations', 'oss_threshold_totals'] as $table) $out[$table] = $db->query("SELECT * FROM {$table} ORDER BY 1, 2")->fetchAll();
        return $out;
    };
    $db->exec("INSERT INTO oss_threshold_activations (seller_key, year) VALUES ('default', 2026)"); $before = $snapshot();
    $config = require $root . '/config.php';
    $doctor = Setup::doctor($config, $root, $db, true);
    $cache = array_values(array_filter($doctor['checks'], static fn(array $c): bool => $c['name'] === 'database.oss_threshold_cache'))[0] ?? null;
    $checks = [['explicit doctor detects corrupt, missing and extra cached totals', ($cache['status'] ?? '') === 'fail']];
    $checks[] = ['doctor leaves financial documents, orders, activations and cache unchanged', $snapshot() === $before];
    // This assertion is the pre-fix red proof, rather than a fatal missing-helper error.
    if ($cache !== null && class_exists(OssThresholdCache::class)) {
        $audit = OssThresholdCache::check($db);
        $checks[] = ['audit counts three mismatched keys and one NULL fallback document', $audit['mismatches'] === 3 && $audit['null_nets'] === 1];
        $shallow = Setup::doctor($config, $root, $db);
        $checks[] = ['ordinary dashboard doctor does not run the deep cache scan', !in_array('database.oss_threshold_cache', array_column($shallow['checks'], 'name'), true)];
        $refused = false;
        try { OssThresholdCache::repair($db, false); } catch (RuntimeException $e) { $refused = str_contains($e->getMessage(), 'writers'); }
        $checks[] = ['repair requires explicit writers-stopped acknowledgement', $refused && $snapshot() === $before];
        $db->beginTransaction();
        $refused = false;
        try { OssThresholdCache::repair($db, true); } catch (RuntimeException $e) { $refused = true; }
        $checks[] = ['repair refuses a caller transaction without committing it', $refused && $db->inTransaction()];
        $db->rollBack();
        // Force the INSERT after DELETE to fail, proving rollback of the old corrupt cache.
        $db->exec($driver === 'mysql'
            ? "CREATE TRIGGER r37_cache_fail BEFORE INSERT ON oss_threshold_totals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'r37 injected failure'"
            : "CREATE TRIGGER r37_cache_fail BEFORE INSERT ON oss_threshold_totals BEGIN SELECT RAISE(ABORT, 'r37 injected failure'); END");
        $failed = false;
        try { OssThresholdCache::repair($db, true); } catch (PDOException $e) { $failed = str_contains($e->getMessage(), 'r37 injected failure'); }
        $checks[] = ['failed rebuild rolls DELETE back and releases its transaction', $failed && !$db->inTransaction() && $snapshot() === $before];
        $db->exec('DROP TRIGGER r37_cache_fail');
        $result = OssThresholdCache::repair($db, true);
        $after = $snapshot();
        $checks[] = ['repair restores exact signed native totals by order store/year/currency', $after['oss_threshold_totals'] == [
            ['store_id' => 'default', 'year' => 2024, 'currency' => 'eur', 'net' => -25],
            ['store_id' => 'default', 'year' => 2025, 'currency' => 'czk', 'net' => 500],
            ['store_id' => 'outlet', 'year' => 2026, 'currency' => 'eur', 'net' => 900000]]];
        unset($after['oss_threshold_totals'], $before['oss_threshold_totals']);
        $checks[] = ['repair preserves documents, NULL nets, order reservations and activation facts', $after === $before];
        $checks[] = ['repair reports a clean cache and leaves NULL fallback separate', $result['after']['mismatches'] === 0 && $result['after']['null_nets'] === 1];
        $fixed = $snapshot();
        $again = OssThresholdCache::repair($db, true);
        $checks[] = ['second repair is an unchanged no-op', !$again['changed'] && $snapshot() === $fixed];
        if ($driver === 'mysql') $checks[] = ['repair restores MySQL autocommit', (int)$db->query('SELECT @@autocommit')->fetchColumn() === 1];
    }
    echo json_encode(['checks' => $checks]);
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
foreach (['sqlite', 'mysql'] as $r37cDriver) {
    if ($r37cDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { echo "  [SKIP] R37 cache native MySQL\n"; continue; }
    $proc = proc_open([PHP_BINARY, $r37cWorker, dirname(TEST_CLI_DIR), $r37cDir, $r37cDriver], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc); $result = json_decode($raw, true);
    assert_true($exit === 0 && is_array($result), "R37 cache {$r37cDriver}: isolated fixture ({$err})");
    foreach ($result['checks'] ?? [] as [$label, $ok]) assert_true($ok, "R37 cache {$r37cDriver}: {$label}");
}
