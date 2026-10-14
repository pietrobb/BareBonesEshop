<?php

// Low finding (queue): a job that kills the worker process must be retried a bounded number of
// times and then dead-lettered; the file backend must recover jobs stranded in processing/.
echo "\n--- Audit A08.2: poison jobs and crashed workers ---\n";
$a082Root = test_fixture_path('queue-poison');
mkdir($a082Root);
file_put_contents($a082Root . '/probe.php', <<<'PHP'
<?php
[, $phase, $backend, $root, $target] = $argv;
require $root . '/src/autoload.php';
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
$store = ['name' => 'default', 'default_locale' => 'en', 'languages' => ['en'], 'currency' => 'eur', 'url_prefix' => '', 'domains' => [], 'permissions' => ['*'], 'channels' => ['storefront', 'api'], 'catalog_scope' => 'default'];
$stores = ['default_store' => 'default', 'stores' => ['default' => $store]];
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
StoreContext::activate(StoreContext::resolve($stores, '/', '', null, 'storefront'));
$db = null;
if ($backend === 'file') {
    JobQueue::setDir($target);
} else {
    $db = $backend === 'mysql'
        ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
        : new PDO('sqlite:' . $target);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($backend === 'mysql') {
        if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
        if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}`");
        $db->exec("USE `{$target}`");
    }
    Db::init($db, $backend === 'mysql' ? 'mysql' : 'sqlite');
    if ($phase === 'setup') (new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
    JobQueue::useBackend(new DatabaseQueue($db, 30));
}

if ($phase === 'setup') {
    echo json_encode(['id' => JobQueue::push('a082_poison', ['n' => 1])]);
    exit(0);
}

if ($phase === 'work') {
    // One worker pass, as in cli/worker.php: claim, then run the handler. The poison handler
    // exhausts memory — a fatal error no try/catch can turn into JobQueue::fail().
    foreach (JobQueue::pull(5) as $job) {
        if (!JobQueue::isReady($job)) continue;
        if (JobQueue::markProcessing($job) === '') continue;
        file_put_contents(__DIR__ . '/runs-' . $backend, "run\n", FILE_APPEND);
        ini_set('memory_limit', '32M');
        $hog = [];
        while (true) $hog[] = str_repeat('x', 1 << 20);
    }
    echo 'idle';
    exit(0);
}

if ($phase === 'expire') {
    // The worker is gone; let its lease run out.
    if ($backend === 'file') {
        foreach (glob($target . '/processing/*.json') as $file) touch($file, time() - 3600);
    } else {
        $db->exec("UPDATE queue_jobs SET claimed_at = '2000-01-01 00:00:00' WHERE state = 'processing'");
    }
    // Recovered jobs keep their backoff; make them due for the next pass.
    if ($backend === 'file') {
        JobQueue::pull(0);
        foreach (glob($target . '/pending/*.json') as $file) {
            $job = json_decode(file_get_contents($file), true);
            unset($job['retry_after']);
            file_put_contents($file, json_encode($job));
        }
    } else {
        JobQueue::pull(0);
        $db->exec("UPDATE queue_jobs SET available_at = '2000-01-01 00:00:00' WHERE state IN ('pending', 'retry')");
    }
    exit(0);
}

if ($phase === 'state') {
    JobQueue::pull(0);
    $failures = JobQueue::failures(5);
    echo json_encode([
        'stats' => JobQueue::stats(),
        'runs' => substr_count((string)@file_get_contents(__DIR__ . '/runs-' . $backend), 'run'),
        'failure' => $failures ? [(int)$failures[0]['attempts'], str_contains((string)$failures[0]['last_error'], 'lease')] : null,
    ]);
    exit(0);
}
PHP);
$a082Php = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
$a082Backends = ['file' => $a082Root . '/jobs', 'sqlite' => $a082Root . '/queue.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a082Backends['mysql'] = 'bbe_a082_queue_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A08.2 native database queue (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a082Backends as $a082Backend => $a082Target) {
        $a082Tag = "A08.2 {$a082Backend}";
        $a082Run = static fn(string $phase): array => $a082Php([$a082Root . '/probe.php', $phase, $a082Backend, dirname(TEST_CLI_DIR), $a082Target]);
        $a082State = static function() use ($a082Run): array {
            [, $out, $err] = $a082Run('state');
            return json_decode($out, true) ?? ['error' => $out . $err];
        };
        [$a082Code, $a082Out, $a082Err] = $a082Run('setup');
        assert_true($a082Code === 0 && isset(json_decode($a082Out, true)['id']), "{$a082Tag}: poison job queued ({$a082Out}{$a082Err})");

        [$a082Code, , $a082Err] = $a082Run('work');
        assert_true($a082Code !== 0 && str_contains($a082Err, 'memory'), "{$a082Tag}: poison job kills the worker process");
        $a082Run('expire');
        $a082 = $a082State();
        assert_equals([1, 0, 0, 1], [$a082['stats']['pending'] ?? null, $a082['stats']['processing'] ?? null, $a082['stats']['failed'] ?? null, $a082['runs'] ?? null], "{$a082Tag}: job stranded by the crash is recovered for retry after its lease");

        for ($a082I = 2; $a082I <= 4; $a082I++) {
            $a082Run('work');
            $a082Run('expire');
        }
        $a082 = $a082State();
        assert_equals(3, $a082['runs'] ?? null, "{$a082Tag}: a worker-killing job runs at most max_attempts (3) times");
        assert_equals([0, 0, 1], [$a082['stats']['pending'] ?? null, $a082['stats']['processing'] ?? null, $a082['stats']['failed'] ?? null], "{$a082Tag}: exhausted poison job is dead-lettered, not retried forever");
        assert_equals([3, true], $a082['failure'] ?? null, "{$a082Tag}: dead-letter entry records the attempts and the lease-expiry cause");
        [$a082Code, $a082Out] = $a082Run('work');
        assert_equals([0, 'idle'], [$a082Code, $a082Out], "{$a082Tag}: the next worker pass is healthy again");
    }
} finally {
    if (isset($a082Backends['mysql'])) $a082Php([$a082Root . '/probe.php', 'teardown', 'mysql', dirname(TEST_CLI_DIR), $a082Backends['mysql']]);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a082Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $a082File) {
        $a082File->isDir() ? rmdir($a082File->getPathname()) : unlink($a082File->getPathname());
    }
    rmdir($a082Root);
}
