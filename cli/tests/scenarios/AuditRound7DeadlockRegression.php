<?php

// Round 7 review (2026-10-20), medium: on MySQL, payment webhooks of one order arriving together deadlocked (each
// ledger insert held the shared lock its foreign key takes on the order, then both waited to update the order) or
// lost the compare-and-set, and concurrent invoice issues deadlocked on the number counter (INSERT IGNORE's shared
// lock on the existing row): orders were left without an invoice. Two real worker processes, each pair of calls
// started together; MySQL only (SQLite has a single writer).
echo "\n--- Audit round 7: MySQL deadlocks of concurrent payment webhooks and invoice numbering ---\n";
if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] Audit R7 MySQL webhook/invoice deadlocks (set TEST_MYSQL_DSN)\n";
    return;
}
$r7dRoot = test_fixture_path('r7-deadlock');
mkdir($r7dRoot . '/jobs', 0777, true);
mkdir($r7dRoot . '/invoices', 0777, true);
file_put_contents($r7dRoot . '/race.php', <<<'PHP'
<?php
// php race.php <phase> <root> <database> <dir> [<process 1|2>]
[, $phase, $root, $database, $dir] = $argv;
$process = (int)($argv[5] ?? 0);
require $root . '/src/autoload.php';
$db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$database}`"); exit(0); }
if ($phase === 'setup') $db->exec("CREATE DATABASE `{$database}`");
$db->exec("USE `{$database}`");
Db::init($db, 'mysql');
Bootstrap::initStoreContext('/', '', 'default');
JobQueue::setDir($dir . '/jobs');
(new ReflectionProperty(InvoiceService::class, 'storageDir'))->setValue(null, $dir . '/invoices');
InvoiceService::setStoresConfigForTesting(['default_store' => 'default', 'stores' => ['default' => ['currency' => 'eur',
    'legal' => ['name' => 'R7 Seller', 'country' => 'SK', 'invoice_prefix' => 'R7D']]]]);
$pairs = 40;
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate.lock'))->apply();
    $order = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id, shipping_addr)
        VALUES (?, ?, ?, 'R7D', 'r7d@example.test', 'card', 1000, 1000, 'eur', 'default', '{\"country\":\"SK\"}')");
    $db->exec("INSERT INTO categories (store_id, name, slug) VALUES ('default', 'R7D', 'r7d')");
    $db->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock)
        VALUES (97901, 'default', " . (int)$db->lastInsertId() . ", 'R7D', 'r7d', 'R7D-SKU', 'simple', 1000, 'eur', 'active', 0)");
    $item = $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, vat_rate) VALUES (?, 97901, 'R7D-SKU', 'R7D', 1, 1000, 1000, 20)");
    for ($i = 0; $i < $pairs; $i++) {
        $order->execute(["R7D-W{$i}", 'pending', 'pending']);
        $item->execute([(int)$db->lastInsertId()]);
        foreach ([1, 2] as $p) {
            $order->execute(["R7D-I{$i}-{$p}", 'paid', 'paid']);
            $item->execute([(int)$db->lastInsertId()]);
        }
    }
    exit(0);
}
$id = static fn(string $number): int => (int)$db->query("SELECT id FROM orders WHERE order_number = " . $db->quote($number))->fetchColumn();
if ($phase === 'check') {
    echo json_encode([
        'unpaid_webhook_orders' => (int)$db->query("SELECT COUNT(*) FROM orders WHERE order_number LIKE 'R7D-W%' AND payment_status = 'pending'")->fetchColumn(),
        'webhook_events' => (int)$db->query("SELECT COUNT(*) FROM payment_transactions")->fetchColumn(),
        'invoices' => (int)$db->query("SELECT COUNT(*) FROM invoices")->fetchColumn(),
        'distinct_numbers' => (int)$db->query("SELECT COUNT(DISTINCT number) FROM invoices")->fetchColumn(),
        'last_number' => (int)$db->query("SELECT MAX(last_number) FROM invoice_number_counters")->fetchColumn(),
    ]);
    exit(0);
}
// Both processes meet before each step, so each pair of calls starts together.
$barrier = static function (string $step) use ($dir, $process): void {
    touch("{$dir}/{$step}-{$process}");
    $other = "{$dir}/{$step}-" . (3 - $process);
    $deadline = microtime(true) + 10;
    while (!is_file($other) && microtime(true) < $deadline) usleep(100);
};
$errors = [];
for ($i = 0; $i < $pairs; $i++) {
    $barrier("w{$i}");
    try {
        // A capture and a failure of one pending payment, delivered at the same moment.
        PaymentService::applyProviderStatusEvent(['event_type' => $process === 1 ? 'payment.paid' : 'payment.failed', 'event_id' => "r7d-{$i}-{$process}",
            'data' => ['order_id' => $id("R7D-W{$i}"), 'amount' => 1000, 'currency' => 'eur', 'id' => "pi_r7d_{$i}"]], 'stripe');
    } catch (Throwable $e) {
        $errors[] = 'webhook ' . $i . ': ' . $e->getMessage();
    }
}
for ($i = 0; $i < $pairs; $i++) {
    $barrier("i{$i}");
    try {
        InvoiceService::issue($id("R7D-I{$i}-{$process}"));
    } catch (Throwable $e) {
        $errors[] = 'invoice ' . $i . ': ' . $e->getMessage();
    }
}
echo json_encode($errors);
PHP);
$r7dRun = static function (string ...$args) use ($r7dRoot): array {
    $process = proc_open(array_merge([PHP_BINARY, $r7dRoot . '/race.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    return [$process, $pipes];
};
$r7dFinish = static function (array $handle): array {
    [$process, $pipes] = $handle;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
$r7dDatabase = 'bbe_r7d_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
$r7dArgs = [dirname(TEST_CLI_DIR), $r7dDatabase, $r7dRoot];
try {
    [$r7dCode, , $r7dErr] = $r7dFinish($r7dRun('setup', ...$r7dArgs));
    assert_equals(0, $r7dCode, 'R7 deadlock fixture: database and orders are set up ' . $r7dErr);
    // Both workers start before either is read, so they run side by side.
    $r7dWorkers = [$r7dRun('race', ...[...$r7dArgs, '1']), $r7dRun('race', ...[...$r7dArgs, '2'])];
    $r7dErrors = [];
    foreach (array_map($r7dFinish, $r7dWorkers) as [$r7dCode, $r7dOut, $r7dErr]) {
        $r7dErrors = array_merge($r7dErrors, $r7dCode === 0 ? (json_decode($r7dOut, true) ?? [$r7dOut]) : ["exit {$r7dCode}: {$r7dOut}{$r7dErr}"]);
    }
    $r7dInvoiceErrors = array_values(array_filter($r7dErrors, static fn(string $e): bool => str_starts_with($e, 'invoice ')));
    $r7dWebhookErrors = array_values(array_diff($r7dErrors, $r7dInvoiceErrors));
    assert_equals([], array_slice($r7dWebhookErrors, 0, 3), 'R7 MySQL webhooks: a capture and a failure of one payment at once are both applied (was a deadlock or "changed concurrently")');
    assert_equals([], array_slice($r7dInvoiceErrors, 0, 3), 'R7 MySQL invoices: concurrent issues all get their number (was a deadlock on the counter row)');
    [, $r7dCheck] = $r7dFinish($r7dRun('check', ...$r7dArgs));
    assert_equals(['unpaid_webhook_orders' => 0, 'webhook_events' => 80, 'invoices' => 80, 'distinct_numbers' => 80, 'last_number' => 80], json_decode($r7dCheck, true) ?? $r7dCheck,
        'R7 MySQL: both events of every order are booked, every order has an invoice, the numbers are unique and gapless');
} finally {
    $r7dFinish($r7dRun('teardown', ...$r7dArgs));
    $r7dTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r7dRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($r7dTree as $r7dItem) $r7dItem->isDir() ? @rmdir($r7dItem->getPathname()) : @unlink($r7dItem->getPathname());
    @rmdir($r7dRoot);
}
