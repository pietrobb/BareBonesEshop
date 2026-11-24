<?php

// Review round 23 (R58), daily sales totals:
// medium S2. Every refresh read the store's logged changes again from id 0. When the read reached the deadline, one day
//    was built and only its changes among those read were deleted, so the next request read nearly the same changes and
//    built the same day again: on MariaDB 700 000 changes took 3.3-4.8 s per report request for 20-80 changes, the worker
//    one day per 20 s job, and every report summed from the orders for hours. The changes are now read oldest day first
//    through (store_id, created_at, id) and each batch's days are built and their changes deleted before the next.
// low L1. Past the deadline the delete ran a statement per 500 changes read, those of the days left for later too.
// low L2. Today's revenue (dashboard, order list) refreshed the daily totals although a period without a closed day is
//    summed from the orders anyway, and the dashboard refreshed again for its trend.
// An isolated child shop per driver; every request runs past its deadline (0 s), so the counts do not depend on speed.
echo "\n--- R58 daily sales: a backlog read past the deadline is built oldest day first, no refresh for today, once per page ---\n";
$r58sRoot = test_fixture_path('sales-daily-r58');
mkdir($r58sRoot, 0700, true);
file_put_contents($r58sRoot . '/child.php', <<<'PHP'
<?php
[, $driver, $target, $root] = $argv;
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
final class R58sStatement extends PDOStatement {
    /** @var array<string, int> */
    public static array $runs = [];
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        if (str_starts_with($this->queryString, 'DELETE FROM sales_daily WHERE store_id = ? AND day = ?')) self::$runs['builds'] = (self::$runs['builds'] ?? 0) + 1;
        if (str_contains($this->queryString, 'sales_daily_changes') && str_contains($this->queryString, 'id IN (')) self::$runs['deletes'] = (self::$runs['deletes'] ?? 0) + 1;
        return parent::execute($params);
    }
}
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new ShopPDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    $db->exec("DROP DATABASE IF EXISTS `{$target}`");
    $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    $db->exec("USE `{$target}`");
} else {
    $db->exec('PRAGMA journal_mode=WAL');
}
$out = [];
try {
    Db::init($db, $driver);
    (new Migrator($db, $root . '/migrations', __DIR__ . '/migrate-' . $driver . '.lock'))->apply();
    Bootstrap::initStoreContext('/', '', 'default');
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [R58sStatement::class]);
    $order = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, created_at)
        VALUES (?, 'delivered', 'paid', 'R58S', 'r58s@example.test', '{}', 'card', 1000, 1000, 'eur', 'default', 'sk', ?)");
    $at = static fn(int $days): string => (new DateTimeImmutable('today'))->modify("-{$days} days")->modify('+600 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    for ($days = 2; $days <= 41; $days++) $order->execute(['R58S-' . $days, $at($days)]);
    SalesDaily::refreshAll();
    // Every built day (before builtUntil(), which ends early at a day whose change was not built) as the orders sum it.
    $wrong = static function() use ($at): array {
        $wrong = [];
        $built = SalesDaily::days('default', SalesDaily::dayOf($at(41)), SalesDaily::builtUntil('default'))['eur'] ?? [];
        for ($day = SalesDaily::dayOf($at(41)); $day < SalesDaily::builtUntil('default'); $day = SalesDaily::nextDay($day)) {
            $raw = SalesReport::revenueRows('default', SalesDaily::dayStartUtc($day), SalesDaily::dayStartUtc(SalesDaily::nextDay($day)))['eur']['revenue'] ?? 0;
            if (($built[$day]['revenue'] ?? 0) !== $raw) $wrong[] = $day;
        }
        return $wrong;
    };
    $changes = static fn(): int => (int)$db->query('SELECT COUNT(*) FROM sales_daily_changes')->fetchAll(PDO::FETCH_COLUMN)[0];

    // Medium S2 and low L1: 60 000 changes of 40 days, the days taking turns in id order (a bulk status change), and
    // every order of those days changed. 40 requests past their deadline.
    $db->exec('UPDATE orders SET total = 1300');
    $db->beginTransaction();
    for ($i = 0; $i < 60000; $i += 1000) {
        $values = [];
        $params = [];
        for ($j = $i; $j < $i + 1000; $j++) { $values[] = '(?, ?)'; array_push($params, 'default', $at(2 + $j % 40)); }
        $db->prepare('INSERT INTO sales_daily_changes (store_id, created_at) VALUES ' . implode(',', $values))->execute($params);
    }
    $db->commit();
    R58sStatement::$runs = [];
    $stale = [];
    for ($request = 1; $request <= 40; $request++) {
        SalesDaily::refresh('default', 0.0);
        if ($wrong() !== []) $stale[] = $request;
    }
    $out['backlog'] = [$changes(), $stale, R58sStatement::$runs['builds'] ?? 0, (R58sStatement::$runs['deletes'] ?? 0) <= 40 * 3];
    $out['backlogRuns'] = R58sStatement::$runs;
    SalesDaily::refresh('default');
    $out['backlogDone'] = [$changes(), $wrong(), SalesDaily::builtUntil('default') === SalesDaily::openDay()];

    // A day of more changes than one batch holds, read past a deadline that has not come yet: built a part at a time,
    // every change deleted, the day right.
    $db->prepare('UPDATE orders SET total = 1700 WHERE order_number = ?')->execute(['R58S-9']);
    $db->beginTransaction();
    for ($i = 0; $i < 120000; $i += 1000) {
        $db->prepare('INSERT INTO sales_daily_changes (store_id, created_at) VALUES ' . implode(',', array_fill(0, 1000, '(?, ?)')))
            ->execute(array_merge(...array_fill(0, 1000, ['default', $at(9)])));
    }
    $db->commit();
    SalesDaily::refresh('default', 120.0);
    $out['bigDay'] = [$changes(), $wrong(), SalesDaily::builtUntil('default') === SalesDaily::openDay()];

    // Low L2: today's revenue does not refresh (an order inserted without touchOrders() stays unseen).
    $maxOrders = static fn(): string => (string)($db->query("SELECT value FROM sales_daily_state WHERE state_key = 'max_orders'")->fetchAll(PDO::FETCH_COLUMN)[0] ?? '');
    $order->execute(['R58S-OLD-1', $at(5)]);
    $before = $maxOrders();
    SalesReport::totals('default', SalesReport::sinceUtc(1));
    $out['today'] = $maxOrders() === $before;

    // Low L2: within a page's refreshOnce() its reports refresh once; after it they refresh again.
    if (method_exists(SalesReport::class, 'refreshOnce')) {
        $page = SalesReport::refreshOnce();
        SalesReport::trend('default', 14);
        $first = $maxOrders() !== $before;
        $order->execute(['R58S-OLD-2', $at(6)]);
        $mid = $maxOrders();
        SalesReport::totals('default', SalesReport::sinceUtc(30));
        SalesReport::topProducts('default', SalesReport::sinceUtc(30));
        $again = $maxOrders() !== $mid;
        unset($page);
        $totals = SalesReport::totals('default', SalesReport::sinceUtc(30))['eur']['revenue'] ?? -1;
        $raw = SalesReport::revenueRows('default', SalesReport::sinceUtc(30), '9999-01-01 00:00:00')['eur']['revenue'] ?? -2;
        $out['once'] = [$first, $again, $maxOrders() !== $mid, $totals === $raw];
    }
} finally {
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
PHP);

$r58sDrivers = ['sqlite' => $r58sRoot . '/shop.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r58sDrivers['mysql'] = 'bbe_r58s_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R58 daily sales native MySQL (set TEST_MYSQL_DSN)\n";
foreach ($r58sDrivers as $r58sDriver => $r58sTarget) {
    $r58sProcess = proc_open([PHP_BINARY, '-d', 'memory_limit=64M', $r58sRoot . '/child.php', $r58sDriver, $r58sTarget, dirname(TEST_CLI_DIR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r58sPipes);
    fclose($r58sPipes[0]);
    $r58sStdout = (string)stream_get_contents($r58sPipes[1]);
    $r58sStderr = (string)stream_get_contents($r58sPipes[2]);
    fclose($r58sPipes[1]);
    fclose($r58sPipes[2]);
    $r58sExit = proc_close($r58sProcess);
    $r58sOut = json_decode($r58sStdout, true);
    if ($r58sExit !== 0 || !is_array($r58sOut)) {
        assert_true(false, "R58 daily sales {$r58sDriver}: child shop ran (exit {$r58sExit}: " . substr($r58sStdout . $r58sStderr, -1500) . ')');
        continue;
    }
    $tag = "R58 daily sales {$r58sDriver}";
    assert_equals([0, [], 40, true], $r58sOut['backlog'] ?? null,
        "{$tag} S2: 60 000 changes over 40 days are gone after 40 requests past their deadline, a day each, every built day right on the way, and no more than its own changes deleted per request (was: about 125 changes per request, from id 0 again each time; "
        . json_encode($r58sOut['backlogRuns'] ?? null) . ')');
    assert_equals([0, [], true], $r58sOut['backlogDone'] ?? null, "{$tag}: the days are built and the reports read them");
    assert_equals([0, [], true], $r58sOut['bigDay'] ?? null, "{$tag}: a day of 120 000 changes (more than a batch holds) is built a batch at a time and all of them deleted");
    assert_true(($r58sOut['today'] ?? false) === true, "{$tag} L2: today's revenue is summed from the orders without refreshing the daily totals (was: a refresh, 4.95 s for the order list under a backlog)");
    assert_equals([true, false, true, true], $r58sOut['once'] ?? null,
        "{$tag} L2: within a page's SalesReport::refreshOnce() the reports refresh once, after it again, and read the orders right");
}
foreach (glob($r58sRoot . '/*') ?: [] as $r58sFile) @unlink($r58sFile);
@rmdir($r58sRoot);

// Low L2: the dashboard and the reports hold refreshOnce() while they render (the order list reads today's revenue only).
foreach (['Dashboard', 'Reports'] as $r58sPage) {
    assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/' . $r58sPage . '.php'), '$salesOnce = SalesReport::refreshOnce();'),
        "R58 daily sales: the {$r58sPage} page refreshes the daily totals at most once (was: the dashboard twice)");
}
