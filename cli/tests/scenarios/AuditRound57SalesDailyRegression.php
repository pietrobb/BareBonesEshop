<?php

// Review round 22 (R57), daily sales totals, medium S4: buildChanged() built and deleted the change backlog in batches of
// 50 000. A backlog spread over many days (a bulk status change, a refund run) touched nearly every one of them in each
// batch, so each day was built again per batch: 200 000 changes took 5 334 day builds instead of 381 and 60 report
// requests left 165 000 of them. The changes are read first, each of their days built once.
// An isolated child shop (memory_limit 48M) counts the day builds.
echo "\n--- R57 daily sales: a change backlog over many days builds each day once ---\n";
$r57sRoot = test_fixture_path('sales-daily-r57');
mkdir($r57sRoot, 0700, true);
file_put_contents($r57sRoot . '/child.php', <<<'PHP'
<?php
[, $target, $root] = $argv;
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
final class R57sStatement extends PDOStatement {
    public static int $builds = 0;
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        if (str_starts_with($this->queryString, 'DELETE FROM sales_daily WHERE store_id = ? AND day = ?')) self::$builds++;
        return parent::execute($params);
    }
}
$db = new ShopPDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA journal_mode=WAL');
Db::init($db, 'sqlite');
(new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
Bootstrap::initStoreContext('/', '', 'default');
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [R57sStatement::class]);
$at = static fn(int $days): string => (new DateTimeImmutable('today'))->modify("-{$days} days")->modify('+600 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$orders = [];
for ($days = 2; $days <= 41; $days++) {
    $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, created_at)
        VALUES (?, 'delivered', 'paid', 'R57S', 'r57s@example.test', '{}', 'card', 1000, 1000, 'eur', 'default', 'sk', ?)")->execute(['R57S-' . $days, $at($days)]);
    $orders[$days] = (int)$db->lastInsertId();
}
SalesDaily::refreshAll();
$out = [];
// 120 000 changes of the 40 days, the days taking turns (a bulk change in id order touches them all).
$db->exec('UPDATE orders SET total = 1300');
$db->beginTransaction();
for ($i = 0; $i < 120000; $i += 1000) {
    $values = [];
    $params = [];
    for ($j = $i; $j < $i + 1000; $j++) { $values[] = '(?, ?)'; array_push($params, 'default', $at(2 + $j % 40)); }
    $db->prepare('INSERT INTO sales_daily_changes (store_id, created_at) VALUES ' . implode(',', $values))->execute($params);
}
$db->commit();
R57sStatement::$builds = 0;
SalesDaily::refresh('default');
$wrong = [];
$built = SalesDaily::days('default', SalesDaily::dayOf($at(41)), SalesDaily::builtUntil('default'))['eur'] ?? [];
foreach ($orders as $days => $id) {
    $day = SalesDaily::dayOf($at($days));
    $raw = SalesReport::revenueRows('default', SalesDaily::dayStartUtc($day), SalesDaily::dayStartUtc(SalesDaily::nextDay($day)))['eur']['revenue'] ?? -1;
    if (($built[$day]['revenue'] ?? 0) !== $raw || $raw <= 0) $wrong[] = $day . ': ' . json_encode([$raw, $built[$day] ?? null]);
}
$out['backlog'] = [R57sStatement::$builds, $wrong, (int)$db->query('SELECT COUNT(*) FROM sales_daily_changes')->fetchAll(PDO::FETCH_COLUMN)[0]];
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r57sProcess = proc_open([PHP_BINARY, '-d', 'memory_limit=48M', $r57sRoot . '/child.php', $r57sRoot . '/shop.sqlite', dirname(TEST_CLI_DIR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r57sPipes);
fclose($r57sPipes[0]);
$r57sStdout = (string)stream_get_contents($r57sPipes[1]);
$r57sStderr = (string)stream_get_contents($r57sPipes[2]);
fclose($r57sPipes[1]);
fclose($r57sPipes[2]);
$r57sExit = proc_close($r57sProcess);
$r57sOut = json_decode($r57sStdout, true);
if ($r57sExit !== 0 || !is_array($r57sOut)) {
    assert_true(false, "R57 daily sales: child shop ran within 48 MB (exit {$r57sExit}: " . substr($r57sStdout . $r57sStderr, -1500) . ')');
} else {
    assert_equals([40, [], 0], $r57sOut['backlog'] ?? null,
        'R57 S4 daily sales: 120 000 changes over 40 days build each day once, all of them right, the backlog gone (was: each day again per 50 000 changes)');
}

// Low: the Reports page refreshed for the trend and again for the best sellers (twice the request's seconds).
try {
    $r57sStore = StoreContext::current()->id();
    $r57sSince = SalesReport::sinceUtc(30);
    SalesReport::trend($r57sStore, 30);
    assert_equals(SalesReport::topProducts($r57sStore, $r57sSince), SalesReport::topProducts($r57sStore, $r57sSince, 10, true),
        'R57 daily sales: the best sellers read after the trend\'s refresh are those of a refresh of their own');
    assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Reports.php'), 'SalesReport::topProducts($storeId, $since, 10, true)'),
        'R57 daily sales: the Reports page refreshes once, for the trend (was: again for the best sellers)');
} catch (Throwable $e) {
    assert_true(false, 'R57 daily sales reports: ' . get_class($e) . ': ' . $e->getMessage());
}
foreach (glob($r57sRoot . '/*') ?: [] as $r57sFile) @unlink($r57sFile);
@rmdir($r57sRoot);
