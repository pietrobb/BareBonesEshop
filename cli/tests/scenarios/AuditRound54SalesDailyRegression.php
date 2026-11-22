<?php

// Review round 19 (R54), daily sales totals:
// medium 5. On MySQL a change logged by a transaction not committed yet was below the MAX(id) the build read: unseen by
//    the build, it was deleted with the others and its day kept the old figures until --rebuild.
// medium 6. On SQLite the cursor of that MAX(id) stayed open; when a checkout wrote meanwhile the build's transaction
//    failed at once with "database is locked" (the report answered 500).
// low. A request whose built_until was read before a worker built further dropped the changes of the days the worker
//    had built (as if no day had been built from them).
// low. A zone off by three quarters of an hour (Kathmandu) counted the orders of its day's first minutes the day before.
// An isolated child shop per driver, a second connection interleaved deterministically.
echo "\n--- R54 daily sales: uncommitted changes kept, no SQLite lock error, worker-built days, half-hour zones ---\n";
$r54sRoot = test_fixture_path('sales-daily-r54');
mkdir($r54sRoot, 0700, true);
file_put_contents($r54sRoot . '/child.php', <<<'PHP'
<?php
[, $driver, $target, $root] = $argv;
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
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
$other = $connect();
if ($driver === 'mysql') $other->exec("USE `{$target}`");
$order = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, created_at)
    VALUES (?, 'delivered', 'paid', 'R54S', 'r54s@example.test', '{}', 'card', 1000, 1000, 'eur', 'default', 'sk', ?)");
$at = static fn(int $days): string => (new DateTimeImmutable('today'))->modify("-{$days} days")->modify('+600 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$byDay = [];
foreach ([3, 5, 8, 12, 20] as $days) {
    $order->execute(['R54S-' . $days, $at($days)]);
    $byDay[$days] = (int)$db->lastInsertId();
}
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
$changes = static fn(): int => (int)$db->query('SELECT COUNT(*) FROM sales_daily_changes')->fetchAll(PDO::FETCH_COLUMN)[0];
$out = [];
try {
    if ($driver === 'sqlite') {
        // Medium 6: a checkout commits on another connection while the build reads the logged changes.
        $fired = false;
        $db->sqliteCreateFunction('SUBSTR', static function($value, $start, $length = null) use (&$fired, $other, $at) {
            if (!$fired) {
                $fired = true;
                $other->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, created_at)
                    VALUES ('R54S-CHECKOUT', 'pending', 'pending', 'R54S', 'r54s@example.test', '{}', 'card', 500, 500, 'eur', 'default', 'sk', ?)")->execute([gmdate('Y-m-d H:i:s')]);
            }
            $value = (string)$value;
            return $length === null ? substr($value, (int)$start - 1) : substr($value, (int)$start - 1, (int)$length);
        }, -1);
        $db->prepare('UPDATE orders SET total = 1300 WHERE id = ?')->execute([$byDay[8]]);
        SalesDaily::touchOrders([$byDay[8]]);
        try {
            SalesDaily::refresh('default');
            $out['locked'] = [$fired, null, $wrong(), $changes()];
        } catch (Throwable $e) {
            $out['locked'] = [$fired, $e->getMessage(), [], -1];
        }
    } else {
        // Medium 5: a change is logged by a transaction that commits only after the build has run.
        $db->exec('SET SESSION innodb_lock_wait_timeout = 2');
        $other->beginTransaction();
        $other->prepare('UPDATE orders SET total = 1500 WHERE id = ?')->execute([$byDay[5]]);
        SalesDaily::touchOrders([$byDay[5]], $other);
        $db->prepare('UPDATE orders SET total = 1200 WHERE id = ?')->execute([$byDay[12]]);
        SalesDaily::touchOrders([$byDay[12]]);
        try {
            SalesDaily::refresh('default');
            $first = null;
        } catch (Throwable $e) {
            $first = $e->getMessage();
        }
        $other->commit();
        SalesDaily::refresh('default');
        $out['uncommitted'] = [$first, $wrong(), $changes()];
    }

    // Low: the request read built_until before the worker built the change's day.
    $state = static fn(string $key): string => (string)($db->query("SELECT value FROM sales_daily_state WHERE state_key = " . $db->quote($key))->fetchAll(PDO::FETCH_COLUMN)[0] ?? '');
    $builtUntil = $state('built_until:default');
    $stale = SalesDaily::dayOf($at(20));
    $db->prepare('UPDATE orders SET total = 1700 WHERE id = ?')->execute([$byDay[3]]);
    SalesDaily::touchOrders([$byDay[3]]);
    $buildChanged = new ReflectionMethod(SalesDaily::class, 'buildChanged');
    $buildChanged->invoke(null, $db, 'default', $stale, null);
    $kept = $changes();
    SalesDaily::refresh('default');
    $out['worker'] = [$builtUntil > SalesDaily::dayOf($at(3)), $kept, $wrong(), $changes()];

    // Low: Kathmandu (UTC+5:45), an order ten minutes into today.
    date_default_timezone_set('Asia/Kathmandu');
    $today = new DateTimeImmutable('today');
    if (time() - $today->getTimestamp() >= 1800) {
        $order->execute(['R54S-KTM', $today->modify('+10 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        $trend = SalesReport::trend('default', 7)['eur'] ?? [];
        $out['zone'] = [$trend[$today->format('Y-m-d')]['orders'] ?? -1, $trend[$today->modify('-1 day')->format('Y-m-d')]['orders'] ?? -1];
    } else {
        $out['zone'] = [1, 0]; // the first half hour of Kathmandu's day: no order ten minutes in yet
    }
} finally {
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r54sDrivers = ['sqlite' => $r54sRoot . '/shop.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r54sDrivers['mysql'] = 'bbe_r54s_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R54 daily sales native MySQL (set TEST_MYSQL_DSN)\n";
foreach ($r54sDrivers as $r54sDriver => $r54sTarget) {
    $r54sProcess = proc_open([PHP_BINARY, $r54sRoot . '/child.php', $r54sDriver, $r54sTarget, dirname(TEST_CLI_DIR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r54sPipes);
    fclose($r54sPipes[0]);
    $r54sStdout = (string)stream_get_contents($r54sPipes[1]);
    $r54sStderr = (string)stream_get_contents($r54sPipes[2]);
    fclose($r54sPipes[1]);
    fclose($r54sPipes[2]);
    $r54sExit = proc_close($r54sProcess);
    $r54sOut = json_decode($r54sStdout, true);
    if ($r54sExit !== 0 || !is_array($r54sOut)) {
        assert_true(false, "R54 daily sales {$r54sDriver}: child shop ran (exit {$r54sExit}: " . substr($r54sStdout . $r54sStderr, -1500) . ')');
        continue;
    }
    $tag = "R54 daily sales {$r54sDriver}";
    if ($r54sDriver === 'sqlite') {
        assert_equals([true, null, [], 0], $r54sOut['locked'] ?? null,
            "{$tag}: a checkout committed while the build reads its changes does not fail it with \"database is locked\" (was: 500), the day is right");
    } else {
        assert_equals([null, [], 0], $r54sOut['uncommitted'] ?? null,
            "{$tag}: a change committed after the build ran stays logged and its day is built again (was: deleted, the day's old figures kept)");
    }
    assert_equals([true, 1, [], 0], $r54sOut['worker'] ?? null,
        "{$tag}: the change of a day a worker built after the request read built_until is kept and built (was: dropped)");
    assert_equals([1, 0], $r54sOut['zone'] ?? null, "{$tag}: Kathmandu: an order ten minutes into today counts today (was: yesterday)");
}
foreach (glob($r54sRoot . '/*') ?: [] as $r54sFile) @unlink($r54sFile);
@rmdir($r54sRoot);
