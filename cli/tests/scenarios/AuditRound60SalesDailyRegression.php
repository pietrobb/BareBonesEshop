<?php

// Review round 25 (R60), medium 6: the first build of the daily sales after the update read the hours of every order not
// built yet at each call (SalesDaily::buildRange()), then built until its deadline: on MySQL (150 000 orders) 2-3 s per
// dashboard and 7-9 s per report request, about 100 of them without a worker. A call now reads one window of 31 days
// from the next order on at a time. And the migration, run by the older release's classes of a signed update, neither
// built the days nor queued the worker job; it queues the job now.
// An isolated child shop per driver; every request runs past its deadline (0 s), so the counts do not depend on speed.
echo "\n--- R60 daily sales: a range build reads a window of days at a time, the update's migration queues the build ---\n";
$r60dRoot = test_fixture_path('sales-daily-r60');
mkdir($r60dRoot, 0700, true);
file_put_contents($r60dRoot . '/child.php', <<<'PHP'
<?php
[, $driver, $target, $root] = $argv;
require $root . '/src/autoload.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
final class R60dStatement extends PDOStatement {
    /** @var list<int> days spanned by each read of the order hours */
    public static array $spans = [];
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        if (str_starts_with($this->queryString, 'SELECT DISTINCT SUBSTR(created_at, 1, 13) FROM orders')) {
            self::$spans[] = (int)round((strtotime((string)$params[2]) - max(0, (int)strtotime((string)$params[1]))) / 86400);
        }
        return parent::execute($params);
    }
}
$connect = static function () use ($driver, $target): PDO {
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
$out = [];
try {
    Db::init($db, $driver);
    JobQueue::setDir(__DIR__ . '/jobs-' . $driver);
    (new Migrator($db, $root . '/migrations', __DIR__ . '/migrate-' . $driver . '.lock'))->apply();
    Bootstrap::initStoreContext('/', '', 'default');
    // A year and a month of orders, one a day, none built yet (as after the update).
    $order = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, created_at)
        VALUES (?, 'delivered', 'paid', 'R60D', 'r60d@example.test', '{}', 'card', 1000, ?, 'eur', 'default', 'sk', ?)");
    $at = static fn(int $days): string => (new DateTimeImmutable('today'))->modify("-{$days} days")->modify('+600 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $db->beginTransaction();
    for ($days = 2; $days <= 400; $days++) $order->execute(['R60D-' . $days, 1000 + $days, $at($days)]);
    $db->commit();
    $db->exec("DELETE FROM sales_daily_state WHERE state_key LIKE 'built_until:%' OR state_key LIKE 'timezone:%'");
    $db->exec('DELETE FROM sales_daily');
    $db->exec('DELETE FROM sales_daily_changes');
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [R60dStatement::class]);
    $until = [];
    for ($i = 0; $i < 3; $i++) {
        SalesDaily::refresh('default', 0.0);
        $until[] = SalesDaily::builtUntil('default');
    }
    $out['request_spans'] = R60dStatement::$spans;
    $out['advances'] = $until[0] > SalesDaily::dayOf($at(400)) && $until[1] > $until[0] && $until[2] > $until[1];
    R60dStatement::$spans = [];
    SalesDaily::refresh('default');
    $out['full_spans_max'] = max(R60dStatement::$spans ?: [0]);
    $out['built_until_open'] = SalesDaily::builtUntil('default') === SalesDaily::openDay();
    $wrong = 0;
    $built = SalesDaily::days('default', SalesDaily::dayOf($at(400)), SalesDaily::openDay())['eur'] ?? [];
    for ($days = 2; $days <= 400; $days++) if (($built[SalesDaily::dayOf($at($days))]['revenue'] ?? null) != SalesReport::revenueRows('default', SalesDaily::dayStartUtc(SalesDaily::dayOf($at($days))), SalesDaily::dayStartUtc(SalesDaily::nextDay(SalesDaily::dayOf($at($days)))))['eur']['revenue']) $wrong++;
    $out['days_built'] = count($built);
    $out['wrong'] = $wrong;
    // The migration run by another release's classes (here: Db::get() is not the connection it migrates) queues the job.
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    $other = $connect();
    if ($driver === 'mysql') $other->exec("USE `{$target}`");
    $before = count(JobQueue::openJobs(SalesDaily::JOB) ?? []);
    (require $root . '/migrations/20261121120000_sales_daily.php')->up($other, $driver === 'sqlite');
    $out['queued'] = count(JobQueue::openJobs(SalesDaily::JOB) ?? []) - $before;
} catch (Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
} finally {
    if ($driver === 'mysql') { try { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); } catch (Throwable) {} }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
PHP);
$r60dDrivers = ['sqlite' => $r60dRoot . '/shop.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r60dDrivers['mysql'] = 'bbe_r60d_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R60 daily sales native MySQL (set TEST_MYSQL_DSN)\n";
try {
    foreach ($r60dDrivers as $r60dDriver => $r60dTarget) {
        $r60dProcess = proc_open([PHP_BINARY, $r60dRoot . '/child.php', $r60dDriver, $r60dTarget, dirname(TEST_CLI_DIR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r60dPipes);
        fclose($r60dPipes[0]);
        $r60dStdout = (string)stream_get_contents($r60dPipes[1]);
        $r60dStderr = (string)stream_get_contents($r60dPipes[2]);
        fclose($r60dPipes[1]);
        fclose($r60dPipes[2]);
        proc_close($r60dProcess);
        $r60d = json_decode($r60dStdout, true);
        if (!is_array($r60d) || isset($r60d['error'])) {
            assert_true(false, "R60 daily sales ({$r60dDriver}): " . (is_array($r60d) ? $r60d['error'] : substr($r60dStdout . $r60dStderr, 0, 400)));
            continue;
        }
        assert_true($r60d['request_spans'] !== [] && max($r60d['request_spans']) <= 31,
            "R60 daily sales ({$r60dDriver}): a request past its deadline reads the order hours of at most 31 days (was: all 399 days at every request; " . json_encode($r60d['request_spans']) . ')');
        assert_true($r60d['advances'], "R60 daily sales ({$r60dDriver}): each request builds further from where the last stopped");
        assert_true($r60d['full_spans_max'] <= 31 && $r60d['built_until_open'], "R60 daily sales ({$r60dDriver}): a build without deadline goes window by window up to the open day");
        assert_equals([399, 0], [$r60d['days_built'], $r60d['wrong']], "R60 daily sales ({$r60dDriver}): every day is built and sums as its orders do");
        assert_equals(1, $r60d['queued'], "R60 daily sales ({$r60dDriver}): the migration run by another release's classes queues the build job (was: nothing)");
    }
} finally {
    foreach (['sqlite', 'mysql'] as $r60dDriver) {
        foreach (glob($r60dRoot . '/jobs-' . $r60dDriver . '/*/*') ?: [] as $r60dFile) @unlink($r60dFile);
        foreach (glob($r60dRoot . '/jobs-' . $r60dDriver . '/*', GLOB_ONLYDIR) ?: [] as $r60dDir) @rmdir($r60dDir);
        @rmdir($r60dRoot . '/jobs-' . $r60dDriver);
    }
    foreach (glob($r60dRoot . '/*') ?: [] as $r60dFile) @unlink($r60dFile);
    @rmdir($r60dRoot);
}
