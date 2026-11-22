<?php

// Review round 20 (R55), daily sales totals:
// medium 3. A refresh that failed (SQLite "database is locked" behind a writer holding it past the busy timeout, a MySQL
//    lock wait timeout) answered the reports and the dashboard with a 500; on MySQL the DELETE of the built changes was
//    planned as a full scan of a small table and waited on another store's uncommitted change.
// medium 5. Change ids that are not consecutive (stores interleaved) ran one DELETE each.
// low. logInserted() read a gap of millions of ids in windows of 5000 ids.
// low. Lord Howe (+10:30 winter, +11 summer): a year from summer to summer was grouped by the hour; India and other
//    half-hour zones grouped the orders summed from the orders by the minute (out of memory at 300 000 orders).
// An isolated child shop per driver, a second connection interleaved deterministically.
echo "\n--- R55 daily sales: failed refresh answers, MySQL delete by key, batched deletes, id gaps, half-hour zones ---\n";
$r55sRoot = test_fixture_path('sales-daily-r55');
mkdir($r55sRoot, 0700, true);
file_put_contents($r55sRoot . '/child.php', <<<'PHP'
<?php
[, $driver, $target, $root] = $argv;
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
/** Counts the statements of the daily sales the scenarios look at. */
final class R55sStatement extends PDOStatement {
    /** @var array<string, int> */
    public static array $runs = [];
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        $sql = $this->queryString;
        if (preg_match('/^\s*DELETE\b.*\bsales_daily_changes\b/s', $sql)) { self::$runs['delete'] = (self::$runs['delete'] ?? 0) + 1; }
        if (str_contains($sql, 'FROM orders o WHERE o.id > :from')) self::$runs['window'] = (self::$runs['window'] ?? 0) + 1;
        return parent::execute($params);
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = parent::fetchAll($mode, ...$args);
        if (str_contains($this->queryString, 'AS hour_utc')) self::$runs['buckets'] = (self::$runs['buckets'] ?? 0) + count($rows);
        return $rows;
    }
}
$connect = static function() use ($driver, $target): PDO {
    $db = $driver === 'mysql'
        ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
        : new ShopPDO('sqlite:' . $target);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
};
$db = $connect();
if ($driver === 'mysql') {
    $db->exec("DROP DATABASE IF EXISTS `{$target}`");
    $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    $db->exec("USE `{$target}`");
} else {
    $db->exec('PRAGMA journal_mode=WAL');
}
Db::init($db, $driver);
(new Migrator($db, $root . '/migrations', __DIR__ . '/migrate-' . $driver . '.lock'))->apply();
Bootstrap::initStoreContext('/', '', 'default');
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [R55sStatement::class]);
$other = $connect();
if ($driver === 'mysql') $other->exec("USE `{$target}`");
$insert = static function(string $number, string $createdAt, string $store = 'default', int $total = 1000) use ($db): int {
    $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, created_at)
        VALUES (?, 'delivered', 'paid', 'R55S', 'r55s@example.test', '{}', 'card', ?, ?, 'eur', ?, 'sk', ?)")->execute([$number, $total, $total, $store, $createdAt]);
    return (int)$db->lastInsertId();
};
$at = static fn(int $days): string => (new DateTimeImmutable('today'))->modify("-{$days} days")->modify('+600 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$byDay = [];
foreach ([3, 5, 8, 12, 20] as $days) $byDay[$days] = $insert('R55S-' . $days, $at($days));
$secondOrder = $insert('R55S-SECOND', $at(6), 'second');
SalesDaily::refreshAll();
// Every built day of the last 30 as the orders sum it.
$wrong = static function() use ($db): array {
    $wrong = [];
    $from = SalesDaily::dayOf(SalesReport::sinceUtc(30));
    $built = SalesDaily::days('default', $from, SalesDaily::builtUntil('default'))['eur'] ?? [];
    for ($day = $from; $day < SalesDaily::builtUntil('default'); $day = SalesDaily::nextDay($day)) {
        $raw = SalesReport::revenueRows('default', SalesDaily::dayStartUtc($day), SalesDaily::dayStartUtc(SalesDaily::nextDay($day)))['eur'] ?? null;
        $have = $built[$day] ?? null;
        if (($raw['revenue'] ?? 0) !== ($have['revenue'] ?? 0)) $wrong[] = $day . ': ' . json_encode([$raw, $have]);
    }
    return $wrong;
};
$changes = static fn(string $store = 'default'): int => (int)$db->query('SELECT COUNT(*) FROM sales_daily_changes WHERE store_id = ' . $db->quote($store))->fetchAll(PDO::FETCH_COLUMN)[0];
$rawRevenue = static fn(): int => SalesReport::revenueRows('default', SalesReport::sinceUtc(30), '9999-01-01 00:00:00')['eur']['revenue'] ?? -1;
$out = [];
try {
    // Medium 3: a writer holds the built rows past the lock timeout while a report refreshes a changed day.
    if ($driver === 'sqlite') $db->exec('PRAGMA busy_timeout = 300');
    else $db->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $db->prepare('UPDATE orders SET total = 1300 WHERE id = ?')->execute([$byDay[8]]);
    SalesDaily::touchOrders([$byDay[8]]);
    if ($driver === 'sqlite') {
        $other->exec('BEGIN IMMEDIATE');
    } else {
        $other->beginTransaction();
        $other->query("SELECT * FROM sales_daily WHERE store_id = 'default' FOR UPDATE")->fetchAll();
    }
    $started = microtime(true);
    try {
        $totals = SalesReport::totals('default', SalesReport::sinceUtc(30));
        $trend = SalesReport::trend('default', 30);
        SalesReport::topProducts('default', SalesReport::sinceUtc(30));
        $failure = null;
    } catch (Throwable $e) {
        $totals = $trend = [];
        $failure = get_class($e) . ': ' . $e->getMessage();
    }
    $elapsed = microtime(true) - $started;
    if ($driver === 'sqlite') $other->exec('ROLLBACK');
    else $other->rollBack();
    $raw = $rawRevenue();
    $out['failed'] = [$failure, ($totals['eur']['revenue'] ?? 0) === $raw, array_sum(array_column($trend['eur'] ?? [], 'revenue')) === $raw,
        $elapsed < ($driver === 'sqlite' ? 2.0 : 3.5)];
    if (property_exists(SalesReport::class, 'refreshFailed')) SalesReport::$refreshFailed = [];
    SalesDaily::refresh('default');
    $out['recovered'] = [$wrong(), $changes()];

    // Medium 3 (MySQL plan): another store's change is uncommitted while a report deletes the built changes (SQLite has
    // one writer: the change is committed first there).
    if ($driver === 'mysql') $other->beginTransaction();
    SalesDaily::touchOrders([$secondOrder], $other);
    $db->prepare('UPDATE orders SET total = 1100 WHERE id = ?')->execute([$byDay[12]]);
    SalesDaily::touchOrders([$byDay[12]]);
    $started = microtime(true);
    try {
        SalesDaily::refresh('default');
        $failure = null;
    } catch (Throwable $e) {
        $failure = $e->getMessage();
    }
    $elapsed = microtime(true) - $started;
    if ($driver === 'mysql') $other->commit();
    $out['uncommitted'] = [$failure, $elapsed < 0.9, $wrong(), $changes(), $changes('second')];

    // Medium 5: 3000 changes of this store interleaved with 3000 of another one.
    $log = $db->prepare('INSERT INTO sales_daily_changes (store_id, created_at) VALUES (?, ?)');
    $db->beginTransaction();
    for ($i = 0; $i < 3000; $i++) {
        $log->execute(['default', $at(8)]);
        $log->execute(['second', $at(6)]);
    }
    $db->commit();
    R55sStatement::$runs = [];
    SalesDaily::refresh('default');
    $out['interleaved'] = [(R55sStatement::$runs['delete'] ?? 0) <= 10, $changes(), $changes('second'), $wrong()];

    // Low: an order inserted without touchOrders() 20 million ids above the last one.
    $max = (int)$db->query('SELECT MAX(id) FROM orders')->fetchAll(PDO::FETCH_COLUMN)[0];
    $db->prepare("INSERT INTO orders (id, order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, created_at)
        VALUES (?, 'R55S-GAP', 'delivered', 'paid', 'R55S', 'r55s@example.test', '{}', 'card', 700, 700, 'eur', 'default', 'sk', ?)")->execute([$max + 20000000, $at(3)]);
    R55sStatement::$runs = [];
    SalesDaily::refresh('default');
    $out['gap'] = [(R55sStatement::$runs['window'] ?? 0) <= 10, $wrong()];

    // Low: Lord Howe, a year from summer (+11) to summer, an order ten minutes into a winter (+10:30) day; and India,
    // 600 orders a minute apart, both summed from the orders (as when the refresh failed: without the daily tables).
    $now = new DateTimeImmutable('2026-12-15 12:00:00', new DateTimeZone('Australia/Lord_Howe'));
    date_default_timezone_set('Australia/Lord_Howe');
    $insert('R55S-LHI', (new DateTimeImmutable('2026-07-01 00:10:00'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    $db->exec('ALTER TABLE sales_daily_state RENAME TO r55s_state_off');
    Db::forgetSchema();
    $trend = SalesReport::trend('default', 365, $now)['eur'] ?? [];
    $out['lordHowe'] = [$trend['2026-06-30']['orders'] ?? -1, $trend['2026-07-01']['orders'] ?? -1];
    date_default_timezone_set('Asia/Kolkata');
    $db->beginTransaction();
    $first = new DateTimeImmutable('2026-06-10 13:00:00', new DateTimeZone('UTC'));
    for ($i = 0; $i < 600; $i++) $insert('R55S-IN-' . $i, $first->modify("+{$i} minutes")->format('Y-m-d H:i:s'));
    $db->commit();
    R55sStatement::$runs = [];
    $trend = SalesReport::trend('default', 365, $now)['eur'] ?? [];
    // 13:00-18:29 UTC is 18:30-23:59 on 10 June in India, 18:30-22:59 UTC the next day.
    $out['india'] = [(R55sStatement::$runs['buckets'] ?? 0) <= 80, $trend['2026-06-10']['orders'] ?? -1, $trend['2026-06-11']['orders'] ?? -1];
} finally {
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r55sDrivers = ['sqlite' => $r55sRoot . '/shop.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r55sDrivers['mysql'] = 'bbe_r55s_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R55 daily sales native MySQL (set TEST_MYSQL_DSN)\n";
foreach ($r55sDrivers as $r55sDriver => $r55sTarget) {
    $r55sProcess = proc_open([PHP_BINARY, $r55sRoot . '/child.php', $r55sDriver, $r55sTarget, dirname(TEST_CLI_DIR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r55sPipes);
    fclose($r55sPipes[0]);
    $r55sStdout = (string)stream_get_contents($r55sPipes[1]);
    $r55sStderr = (string)stream_get_contents($r55sPipes[2]);
    fclose($r55sPipes[1]);
    fclose($r55sPipes[2]);
    $r55sExit = proc_close($r55sProcess);
    $r55sOut = json_decode($r55sStdout, true);
    if ($r55sExit !== 0 || !is_array($r55sOut)) {
        assert_true(false, "R55 daily sales {$r55sDriver}: child shop ran (exit {$r55sExit}: " . substr($r55sStdout . $r55sStderr, -1500) . ')');
        continue;
    }
    $tag = "R55 daily sales {$r55sDriver}";
    assert_equals([null, true, true, true], $r55sOut['failed'] ?? null,
        "{$tag}: a refresh that fails behind a writer holding the lock answers the reports from the orders, without waiting again per report (was: 500)");
    assert_equals([[], 0], $r55sOut['recovered'] ?? null, "{$tag}: the next refresh builds the changed day");
    assert_equals([null, true, [], 0, 1], $r55sOut['uncommitted'] ?? null,
        "{$tag}: another store's uncommitted change does not hold up the delete of the built changes (was: full scan, 50 s lock wait, 1205)");
    assert_equals([true, 0, 3001, []], $r55sOut['interleaved'] ?? null,
        "{$tag}: changes interleaved with another store's are deleted in batches, not one statement per id");
    assert_equals([true, []], $r55sOut['gap'] ?? null, "{$tag}: a gap of 20 million ids is skipped, not read in 4000 windows");
    assert_equals([0, 1], $r55sOut['lordHowe'] ?? null, "{$tag}: Lord Howe: a winter order ten minutes into its day counts that day in a summer-to-summer year (was: the day before)");
    assert_equals([true, 330, 270], $r55sOut['india'] ?? null, "{$tag}: India: orders summed from the orders are grouped by the quarter hour (was: by the minute) and fall on the right day");
}
foreach (glob($r55sRoot . '/*') ?: [] as $r55sFile) @unlink($r55sFile);
@rmdir($r55sRoot);
