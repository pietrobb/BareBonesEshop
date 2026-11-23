<?php

// Review round 21 (R56), daily sales totals:
// medium 1. buildChanged() read every logged change of a store at once, keeping their id runs as PHP arrays: 530 000
//    changes whose ids are not consecutive (a multi-store shop whose reports nobody opens, Galera's id steps) exceeded
//    the 128 MB memory limit and every report request answered 500 without getting further.
// low. A report's refresh waited for the writers' lock for innodb_lock_wait_timeout (50 s on MariaDB) while
//    `cli/sales-daily.php --rebuild` held it, instead of its 2 s.
// low. logInserted() over ids far apart (each more than 5000 above the previous one) logged one row per transaction.
// An isolated child shop per driver (memory_limit 48M), a second connection holding the lock on MySQL.
echo "\n--- R56 daily sales: change backlog in batches within memory, lock wait within the request, sparse inserted ids ---\n";
$r56sRoot = test_fixture_path('sales-daily-r56');
mkdir($r56sRoot, 0700, true);
file_put_contents($r56sRoot . '/child.php', <<<'PHP'
<?php
[, $driver, $target, $root] = $argv;
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
final class R56sStatement extends PDOStatement {
    /** @var array<string, int> */
    public static array $runs = [];
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        if (str_contains($this->queryString, 'FROM orders o WHERE o.id > :from')) self::$runs['window'] = (self::$runs['window'] ?? 0) + 1;
        return parent::execute($params);
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
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [R56sStatement::class]);
$insert = static function(string $number, string $createdAt, string $store = 'default', int $total = 1000, ?int $id = null) use ($db): int {
    $db->prepare("INSERT INTO orders (id, order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, created_at)
        VALUES (?, ?, 'delivered', 'paid', 'R56S', 'r56s@example.test', '{}', 'card', ?, ?, 'eur', ?, 'sk', ?)")->execute([$id, $number, $total, $total, $store, $createdAt]);
    return (int)$db->lastInsertId();
};
$at = static fn(int $days): string => (new DateTimeImmutable('today'))->modify("-{$days} days")->modify('+600 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$byDay = [];
foreach ([3, 8, 20] as $days) $byDay[$days] = $insert('R56S-' . $days, $at($days));
$insert('R56S-SECOND', $at(6), 'second');
SalesDaily::refreshAll();
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
// $count changes of $store on $createdAt, ids $step apart from $firstId (explicit ids: a backlog that is not consecutive).
$logChanges = static function(int $firstId, int $count, int $step, string $store, string $createdAt) use ($db): void {
    $db->beginTransaction();
    for ($i = 0; $i < $count; $i += 1000) {
        $values = [];
        $params = [];
        for ($j = $i; $j < min($count, $i + 1000); $j++) { $values[] = '(?, ?, ?)'; array_push($params, $firstId + $j * $step, $store, $createdAt); }
        $db->prepare('INSERT INTO sales_daily_changes (id, store_id, created_at) VALUES ' . implode(',', $values))->execute($params);
    }
    $db->commit();
};
$out = [];
try {
    // Medium 1: 250 000 changes of this store, every other id (another store's in between), under a 48 MB limit.
    $db->prepare('UPDATE orders SET total = 1700 WHERE id = ?')->execute([$byDay[8]]);
    $logChanges(1000001, 250000, 2, 'default', $at(8));
    $logChanges(1000002, 2000, 2, 'second', $at(6));
    $started = microtime(true);
    SalesDaily::refresh('default');
    $out['backlog'] = [$changes(), $changes('second'), $wrong(), SalesDaily::builtUntil('default') === SalesDaily::openDay()];

    // Medium 1, within the report's seconds: a batch is built, the changes not read yet (an older day) are summed
    // from the orders, not read from the stale built day.
    $db->prepare('UPDATE orders SET total = 1900 WHERE id = ?')->execute([$byDay[20]]);
    $logChanges(3000001, 60000, 1, 'default', $at(3));
    $logChanges(3100001, 1, 1, 'default', $at(20));
    SalesDaily::refresh('default', 0.0);
    $stale = SalesDaily::builtUntil('default');
    $out['partial'] = [$stale <= SalesDaily::dayOf($at(20)), $changes() > 0 && $changes() < 60001];
    SalesDaily::refresh('default');
    $out['partialDone'] = [$changes(), $wrong()];

    // Low: 30 orders inserted without touchOrders() 10 000 ids apart.
    $max = (int)$db->query('SELECT MAX(id) FROM orders')->fetchAll(PDO::FETCH_COLUMN)[0];
    for ($i = 1; $i <= 30; $i++) $insert('R56S-SPARSE-' . $i, $at(8), 'default', 100, $max + $i * 10000);
    R56sStatement::$runs = [];
    SalesDaily::refresh('default', 2.0);
    $out['sparse'] = [R56sStatement::$runs['window'] ?? 0, $wrong(), $changes()];

    // Low (MySQL): `--rebuild` holds the writers' lock; a report waits its own seconds, not 50.
    if ($driver === 'mysql') {
        $db->exec('SET SESSION innodb_lock_wait_timeout = 50');
        $other = $connect();
        $other->exec("USE `{$target}`");
        $other->beginTransaction();
        $other->query("SELECT value FROM sales_daily_state WHERE state_key = 'lock' FOR UPDATE")->fetchAll();
        $db->prepare('UPDATE orders SET total = 2100 WHERE id = ?')->execute([$byDay[3]]);
        SalesDaily::touchOrders([$byDay[3]]);
        SalesReport::$refreshFailed = [];
        $started = microtime(true);
        $totals = SalesReport::totals('default', SalesReport::sinceUtc(30));
        $elapsed = microtime(true) - $started;
        $other->rollBack();
        $raw = SalesReport::revenueRows('default', SalesReport::sinceUtc(30), '9999-01-01 00:00:00')['eur']['revenue'] ?? -1;
        $out['lockWait'] = [$elapsed < 5.0, ($totals['eur']['revenue'] ?? 0) === $raw, (int)$db->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchAll(PDO::FETCH_COLUMN)[0]];
    }
} finally {
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r56sDrivers = ['sqlite' => $r56sRoot . '/shop.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r56sDrivers['mysql'] = 'bbe_r56s_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R56 daily sales native MySQL (set TEST_MYSQL_DSN)\n";
foreach ($r56sDrivers as $r56sDriver => $r56sTarget) {
    $r56sProcess = proc_open([PHP_BINARY, '-d', 'memory_limit=48M', $r56sRoot . '/child.php', $r56sDriver, $r56sTarget, dirname(TEST_CLI_DIR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r56sPipes);
    fclose($r56sPipes[0]);
    $r56sStdout = (string)stream_get_contents($r56sPipes[1]);
    $r56sStderr = (string)stream_get_contents($r56sPipes[2]);
    fclose($r56sPipes[1]);
    fclose($r56sPipes[2]);
    $r56sExit = proc_close($r56sProcess);
    $r56sOut = json_decode($r56sStdout, true);
    if ($r56sExit !== 0 || !is_array($r56sOut)) {
        assert_true(false, "R56 daily sales {$r56sDriver}: child shop ran within 48 MB (exit {$r56sExit}: " . substr($r56sStdout . $r56sStderr, -1500) . ')');
        continue;
    }
    $tag = "R56 daily sales {$r56sDriver}";
    assert_equals([0, 2000, [], true], $r56sOut['backlog'] ?? null,
        "{$tag}: 250 000 changes whose ids are not consecutive are built and deleted in batches within 48 MB (was: out of memory, 500 on every report)");
    assert_equals([true, true], $r56sOut['partial'] ?? null,
        "{$tag}: a report out of seconds after a batch sums the days of the changes it did not read from the orders");
    assert_equals([0, []], $r56sOut['partialDone'] ?? null, "{$tag}: the next refresh builds the rest");
    assert_true(($r56sOut['sparse'][0] ?? 99) <= 2 && ($r56sOut['sparse'][1] ?? null) === [] && ($r56sOut['sparse'][2] ?? null) === 0,
        "{$tag}: 30 inserted orders 10 000 ids apart are logged in one batch, not one transaction each: " . json_encode($r56sOut['sparse'] ?? null));
    if ($r56sDriver === 'mysql') {
        assert_equals([true, true, 50], $r56sOut['lockWait'] ?? null,
            "{$tag}: a report waits for the lock --rebuild holds no longer than its own seconds, answers from the orders and restores the session's lock wait (was: 50 s)");
    }
}
foreach (glob($r56sRoot . '/*') ?: [] as $r56sFile) @unlink($r56sFile);
@rmdir($r56sRoot);
