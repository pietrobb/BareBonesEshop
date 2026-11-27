<?php

// Review round 61 (kolo 26), money:
//  low 2: a credit note capped below a rate's uncredited rest (money refunded outside the return) rounded its net down and
//         credited 1 cent more VAT than the invoice had left at that rate (2381 against 2380 at 23 %): capToUncredited()
//         bounded the net, never the VAT;
//  low 3: an admin edit of an order's lines was the deadlock victim against stock writes (13 % of edits with
//         Warehouse::setAvailable running) and, unlike checkout, was not run again.
echo "\n--- Audit round 61: credit VAT capped at the rate's rest; an order edit picked as a deadlock victim runs again ---\n";

$r61mCap = new ReflectionMethod(InvoiceService::class, 'capToUncredited');
$r61mKey = VatService::breakdownKey(2300, 'SK', 'goods');
$r61mSource = ['vat_breakdown' => json_encode([$r61mKey => ['net' => 20000, 'vat' => 4600]])];
$r61mLine = static fn(int $gross, int $net): array => ['rate_basis_points' => 2300, 'country' => 'SK', 'supply' => 'goods', 'gross' => $gross, 'net' => $net, 'vat' => $gross - $net];
// Left at 23 %: 12 300 gross of which 10 001 net, i.e. 2 299 VAT. A credit of 12 299 whose net rounded to 9 999 would take 2 300.
[$r61mLines] = $r61mCap->invoke(null, [$r61mLine(12299, 9999)], [$r61mKey => ['gross' => 12300, 'net' => 10001]], $r61mSource);
assert_equals([12299, 10000, 2299], [(int)$r61mLines[0]['gross'], (int)$r61mLines[0]['net'], (int)$r61mLines[0]['vat']],
    'R61 credit VAT: a credit below the rate\'s rest credits no more VAT than the rate has left (its net takes the cent; was 2300 of 2299)');
// Two lines over the cap: scaled to the rest, the VAT stays within it too and the totals stay consistent.
[$r61mLines, $r61mTotals] = $r61mCap->invoke(null, [$r61mLine(8000, 6504), $r61mLine(8000, 6504)], [$r61mKey => ['gross' => 12299, 'net' => 10000]], $r61mSource);
$r61mVat = array_sum(array_map(static fn(array $l): int => (int)$l['vat'], $r61mLines));
assert_true(array_sum(array_column($r61mLines, 'gross')) === 12299 && $r61mVat <= 2299 && (int)$r61mTotals['vat'] === $r61mVat,
    "R61 credit VAT: capped lines credit at most the rate's VAT rest ({$r61mVat} of 2299)");
// A credit that leaves room keeps its own split.
[$r61mLines] = $r61mCap->invoke(null, [$r61mLine(1230, 1000)], [$r61mKey => ['gross' => 12300, 'net' => 10001]], $r61mSource);
assert_equals([1000, 230], [(int)$r61mLines[0]['net'], (int)$r61mLines[0]['vat']], 'R61 credit VAT: a credit within the rate\'s rest keeps its net and VAT');

$r61mOrders = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Orders.php');
assert_true(str_contains($r61mOrders, 'return self::replaceItemsWithin($db, $orderId, $price, $newItems);'),
    'R61 edit deadlock: replaceItems() runs its transaction in the deadlock retry loop');

if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R61 edit deadlock retry native MySQL (set TEST_MYSQL_DSN)\n";
    return;
}
$r61mRoot = test_fixture_path('edit-deadlock-r61');
mkdir($r61mRoot, 0700, true);
file_put_contents($r61mRoot . '/child.php', <<<'PHP'
<?php
[, $target, $root] = $argv;
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("DROP DATABASE IF EXISTS `{$target}`");
$db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
$db->exec("USE `{$target}`");
$out = [];
try {
    Db::init($db, 'mysql');
    (new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
    JobQueue::setDir(__DIR__ . '/jobs');
    Bootstrap::initStoreContext('/', '', 'default');
    I18n::setLocale('en');
    ConsentService::setDefinitionsForTesting([]);
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $warehouse = (int)(Warehouse::defaultWarehouse()['id'] ?? 0);
    $db->exec("INSERT INTO categories (slug, name, store_id) VALUES ('r61m', 'R61M', 'default')");
    $category = (int)$db->lastInsertId();
    foreach (['R61M-A', 'R61M-X'] as $sku) {
        $db->prepare("INSERT INTO products (sku, slug, name, category_id, product_type, price, currency, stock, status, inventory_policy, store_id) VALUES (?, ?, ?, {$category}, 'simple', 1000, 'eur', 100, 'active', 'deny', 'default')")
            ->execute([$sku, strtolower($sku), $sku]);
        Warehouse::setStock($warehouse, $sku, 100, 'count', 'test', 'r61m-' . $sku);
    }
    $db->exec("INSERT INTO shipping_methods (code, carrier, name, service_type, supports_cod, supports_pickup, free_above, sort_order) VALUES ('r61mship', 'gls', 'R61M parcel', 'parcel', 0, 0, NULL, 1)");
    $db->exec("INSERT INTO shipping_rates (carrier, country, zone_code, method_code, weight_from_g, weight_to_g, price, cod_surcharge, sort_order) VALUES ('gls', '', 'domestic', 'r61mship', 0, 999999, 400, 0, 1)");
    $db->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
        VALUES ('r61mpay', 'manual', 'R61M transfer', '', 'offline', 0, 0, 0, 0, NULL, 0, 1, 1)");
    $db->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('r61mpay', 'SK')");
    $placed = ManualOrder::create(ManualOrder::parseLines('R61M-X 1')['lines'], ['name' => 'R61M Buyer', 'email' => 'r61m@example.test',
        'phone' => '+421900000061', 'country' => 'SK', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'r61mship',
        'payment_method' => 'r61mpay', 'locale' => 'sk'], false);
    $orderId = (int)($placed['order_id'] ?? 0);
    if ($orderId <= 0) throw new RuntimeException('order not placed: ' . json_encode($placed));
    // The edit's line insert fails as a deadlock victim (MySQL error 1213) as many times as @r61_victims says.
    $db->exec("CREATE TRIGGER r61_victim BEFORE INSERT ON order_items FOR EACH ROW BEGIN
        IF @r61_victims > 0 THEN SET @r61_victims = @r61_victims - 1; SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Deadlock found when trying to get lock'; END IF;
    END");
    foreach ([[1, 'R61M-A 1'], [2, 'R61M-X 2'], [3, 'R61M-A 3']] as [$victims, $lines]) {
        $db->exec("SET @r61_victims = {$victims}");
        try {
            $edit = ManualOrder::editItems($orderId, ManualOrder::parseLines($lines)['lines']);
            $out[] = !empty($edit['ok']) ? 'ok' : (string)($edit['error'] ?? 'refused');
        } catch (PDOException $e) {
            $out[] = Db::deadlocked($e) ? 'deadlock' : $e->getMessage();
        }
        $out[] = $db->query("SELECT sku, reserved FROM warehouse_stock WHERE sku LIKE 'R61M-%' ORDER BY sku")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
} finally {
    $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
echo json_encode($out, JSON_THROW_ON_ERROR);
PHP);
$r61mTarget = 'bbe_r61m_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
$r61mProcess = proc_open([PHP_BINARY, $r61mRoot . '/child.php', $r61mTarget, dirname(TEST_CLI_DIR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r61mPipes);
fclose($r61mPipes[0]);
$r61mStdout = (string)stream_get_contents($r61mPipes[1]);
$r61mStderr = (string)stream_get_contents($r61mPipes[2]);
fclose($r61mPipes[1]);
fclose($r61mPipes[2]);
$r61mExit = proc_close($r61mProcess);
$r61mOut = json_decode($r61mStdout, true);
if ($r61mExit !== 0 || !is_array($r61mOut)) {
    assert_true(false, "R61 edit deadlock: child shop ran (exit {$r61mExit}: " . substr($r61mStdout . $r61mStderr, -1500) . ')');
} else {
    assert_equals(['ok', ['R61M-A' => 1, 'R61M-X' => 0], 'ok', ['R61M-A' => 0, 'R61M-X' => 2], 'deadlock', ['R61M-A' => 0, 'R61M-X' => 2]], $r61mOut,
        'R61 edit deadlock: an edit picked once or twice as a deadlock victim runs again and reserves once; a third time it fails and changes nothing (was: failed the first time)');
}
foreach (glob($r61mRoot . '/jobs/*/*') ?: [] as $r61mFile) @unlink($r61mFile);
foreach (glob($r61mRoot . '/jobs/*', GLOB_ONLYDIR) ?: [] as $r61mDir) @rmdir($r61mDir);
@rmdir($r61mRoot . '/jobs');
foreach (glob($r61mRoot . '/*') ?: [] as $r61mFile) @unlink($r61mFile);
@rmdir($r61mRoot);
