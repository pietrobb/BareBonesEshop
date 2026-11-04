<?php

// Review round 15, high 1: the page-request worker shares the request's database connection. A request that died inside
// a transaction left it open, so every job of the pass ran inside it, looked completed and vanished when PHP rolled the
// connection back at exit (a DB queue then ran the same jobs again; SQLite stayed locked for the whole pass). Low: a
// `worker.php --once` pass handled the first five ready jobs only.
echo "\n--- Audit round 15: web worker after a request that left a transaction open ---\n";

$r15wRoot = dirname(TEST_CLI_DIR);
$r15wBase = test_fixture_path('r15-worker');
$r15wTree = static function (string $path) use (&$r15wTree): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
};

try {
    // An isolated shop: its own worker copy, config, SQLite database and jobs dir; the DB queue backend.
    foreach (['/worker/cli', '/worker/src', '/worker/jobs'] as $dir) mkdir($r15wBase . $dir, 0777, true);
    $r15wJobs = $r15wBase . '/worker/jobs';
    $r15wDbPath = $r15wBase . '/worker/shop.sqlite';
    $r15wConfig = $r15wBase . '/worker/config.php';
    file_put_contents($r15wConfig, '<?php return ' . var_export(['jobs_dir' => $r15wJobs, 'queue' => ['backend' => 'database'],
        'db_driver' => 'sqlite', 'db_sqlite_path' => $r15wDbPath], true) . ';');
    file_put_contents($r15wBase . '/worker/src/autoload.php', '<?php require_once ' . var_export($r15wRoot . '/src/autoload.php', true) . ';');
    copy($r15wRoot . '/cli/cli-only.php', $r15wBase . '/worker/cli/cli-only.php');
    copy($r15wRoot . '/cli/worker.php', $r15wBase . '/worker/cli/worker.php');
    (new Migrator(new PDO('sqlite:' . $r15wDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), $r15wRoot . '/migrations', $r15wBase . '/migrate.lock'))->apply();

    $r15wPhp = static function (string $script) use ($r15wBase): array {
        file_put_contents($r15wBase . '/step.php', $script);
        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=1', $r15wBase . '/step.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $r15wBase);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($proc), $out];
    };
    $r15wSetup = '<?php require ' . var_export($r15wRoot . '/src/autoload.php', true) . ";\n"
        . 'Db::useConfig(' . var_export($r15wConfig, true) . '); JobQueue::useBackend(new DatabaseQueue(Db::get(), 300));' . "\n";
    $r15wPass = 'register_shutdown_function(static function (): void { echo "pass-ended\n"; });' . "\n"
        . 'WebWorker::runIfDue(["operations" => ["web_worker" => true], "jobs_dir" => ' . var_export($r15wJobs, true) . '], '
        . var_export($r15wBase . '/worker', true) . ");\n";
    $r15wStates = static function () use ($r15wDbPath): array {
        $db = new PDO('sqlite:' . $r15wDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rows = $db->query("SELECT type, state FROM queue_jobs ORDER BY created_at, id")->fetchAll(PDO::FETCH_ASSOC);
        $states = [];
        foreach ($rows as $row) $states[$row['type'] . ':' . $row['state']] = ($states[$row['type'] . ':' . $row['state']] ?? 0) + 1;
        ksort($states);
        return $states;
    };

    // Seven jobs queued (and committed) earlier; this request opens a transaction, writes, and ends without committing.
    [$r15wExit, $r15wOut] = $r15wPhp($r15wSetup . 'for ($i = 0; $i < 7; $i++) JobQueue::push("back_in_stock", ["skus" => []]);');
    assert_true($r15wExit === 0 && $r15wStates() === ['back_in_stock:pending' => 7], 'R15 H1 fixture: seven committed jobs wait in the DB queue (' . trim(substr($r15wOut, 0, 160)) . ')');
    [$r15wExit, $r15wOut] = $r15wPhp($r15wSetup
        . 'Db::get()->beginTransaction(); JobQueue::push("search_reindex", ["request" => "unfinished"]);' . "\n" . $r15wPass);
    // The daily retention cleanup the worker schedules for itself is not part of the check.
    $r15wAfter = array_diff_key($r15wStates(), ['cleanup:completed' => true]);
    assert_true($r15wExit === 0 && str_contains($r15wOut, 'pass-ended') && !preg_match('/fatal|unhandled/i', $r15wOut),
        'R15 H1: the pass after a request with an open transaction ends cleanly (exit ' . $r15wExit . ': ' . trim(substr($r15wOut, 0, 200)) . ')');
    assert_equals(['back_in_stock:completed' => 7], $r15wAfter,
        'R15 H1 + low: every job the pass ran stays completed (not rolled back with the request), all seven in one --once pass, and the request\'s own uncommitted write is not committed by the pass');

    // The slot was claimed only because the pass really ran: the next pass inside the interval does nothing.
    assert_true(is_file($r15wJobs . '/web-worker.last'), 'R15 H1: the interval slot is taken by the pass that ran');

    // SQLite: the request's write lock is gone while the pass works, so another connection can write meanwhile.
    @unlink($r15wJobs . '/web-worker.last');
    [$r15wExit, $r15wOut] = $r15wPhp($r15wSetup
        . 'Db::get()->beginTransaction(); JobQueue::push("search_reindex", ["request" => "unfinished"]);' . "\n"
        . 'register_shutdown_function(static function (): void {' . "\n"
        . '    $other = new PDO("sqlite:" . ' . var_export($r15wDbPath, true) . ', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 1]);' . "\n"
        . '    try { $other->exec("BEGIN IMMEDIATE"); $other->exec("ROLLBACK"); echo "other-writer-ok\n"; } catch (Throwable $e) { echo "other-writer-locked\n"; }' . "\n"
        . '});' . "\n" . $r15wPass);
    assert_true($r15wExit === 0 && str_contains($r15wOut, 'other-writer-ok'),
        'R15 H1: on SQLite the dead request\'s write lock is released before the pass, not held for its whole length (' . trim(substr($r15wOut, 0, 200)) . ')');
} finally {
    $r15wTree($r15wBase);
}
