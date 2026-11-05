<?php

// Review round 17 (2026-11-05), medium "maintenance mode and PID":
// 1. a reused PID kept the shop on 503 for up to 24 h (the marker only named a PID that "still ran");
// 2. an update started from another container (docker compose, shared volume) lost its window after 1 h, because
//    its PID meant nothing on this host;
// 3. a failed migration in cli/migrate.php reopened the shop on a half-migrated schema.
echo "\n--- R17: maintenance window held by a lock, kept after a failed migration ---\n";
$r17mRoot = test_fixture_path('r17-maintenance');
mkdir($r17mRoot);
$r17mMarker = MaintenanceMode::path($r17mRoot);
$r17mAge = static function (int $seconds) use ($r17mMarker): void {
    touch($r17mMarker, time() - $seconds);
    clearstatcache(true, $r17mMarker);
};
$r17mStat = static function (): ?string {
    $stat = @file_get_contents('/proc/self/stat');
    if (!is_string($stat) || ($close = strrpos($stat, ')')) === false) return null;
    return (preg_split('/\s+/', trim(substr($stat, $close + 1))) ?: [])[19] ?? null;
};
try {
    // 1. A marker whose PID now belongs to another process (same number, different start time) does not block.
    file_put_contents($r17mMarker, json_encode(['since' => gmdate('c'), 'reason' => 'update 9.9.9', 'pid' => getmypid(),
        'host' => (string)gethostname(), 'start' => '1']));
    $r17mAge(MaintenanceMode::STALE_AFTER + 600);
    $r17mReused = MaintenanceMode::active($r17mRoot);
    $r17mOwnStart = $r17mStat();
    if ($r17mOwnStart !== null) {
        file_put_contents($r17mMarker, json_encode(['since' => gmdate('c'), 'reason' => 'update 9.9.9', 'pid' => getmypid(),
            'host' => (string)gethostname(), 'start' => $r17mOwnStart]));
        $r17mAge(MaintenanceMode::STALE_AFTER + 600);
        $r17mSame = MaintenanceMode::active($r17mRoot);
    } else {
        $r17mSame = true; // no /proc: the lock (below) is what keeps the window
    }
    @unlink($r17mMarker);
    assert_equals([false, true], [$r17mReused, $r17mSame],
        'R17 maintenance: a reused PID (other start time) does not keep the shop closed; the same process still does');

    // 2. The updater runs in another process (another container on the same volume): its lock keeps the window,
    //    past the hour and past the day, whatever host and PID the marker names; it ends when that process ends.
    file_put_contents($r17mRoot . '/child.php', '<?php
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';
MaintenanceMode::enter($argv[1], "update 9.9.9");
echo "entered\n";
fgets(STDIN); // hold the window until the parent closes stdin
exit(1); // killed mid-update: leave() never runs
');
    $r17mChild = proc_open([PHP_BINARY, $r17mRoot . '/child.php', $r17mRoot], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r17mPipes);
    $r17mEntered = trim((string)fgets($r17mPipes[1]));
    if (PHP_OS_FAMILY !== 'Windows') {
        // Advisory lock: the content can be rewritten while the lock stays — the marker of another container.
        file_put_contents($r17mMarker, json_encode(['since' => gmdate('c'), 'reason' => 'update 9.9.9', 'pid' => 1,
            'host' => 'r17-other-container.invalid', 'start' => '1']));
    }
    $r17mAge(MaintenanceMode::STALE_AFTER + 600);
    $r17mHour = MaintenanceMode::active($r17mRoot);
    $r17mAge(MaintenanceMode::MAX_AGE + 600);
    $r17mDay = MaintenanceMode::active($r17mRoot);
    fclose($r17mPipes[0]);
    stream_get_contents($r17mPipes[1]);
    fclose($r17mPipes[1]);
    fclose($r17mPipes[2]);
    proc_close($r17mChild);
    $r17mAge(MaintenanceMode::STALE_AFTER + 600);
    $r17mGone = MaintenanceMode::active($r17mRoot);
    assert_equals(['entered', true, true, false], [$r17mEntered, $r17mHour, $r17mDay, $r17mGone],
        'R17 maintenance: an update in another process keeps its window while it runs (after 1 h and 24 h); once it is gone, an hour-old marker no longer blocks');
    @unlink($r17mMarker);

    // 3. A step that failed half-way keeps the shop closed: the marker never goes stale and names what to run.
    MaintenanceMode::enter($r17mRoot, 'cli/migrate.php: 2 pending migration(s)');
    MaintenanceMode::fail($r17mRoot, 'cli/migrate.php failed: r17 boom — run php cli/migrate.php apply again');
    $r17mAge(MaintenanceMode::MAX_AGE + 600);
    assert_true(MaintenanceMode::active($r17mRoot) && MaintenanceMode::failed($r17mRoot, 'cli/migrate.php')
        && !MaintenanceMode::failed($r17mRoot, 'update ') && str_contains((string)(MaintenanceMode::marker($r17mRoot)['reason'] ?? ''), 'migrate.php apply'),
        'R17 maintenance: a failed migration keeps the shop in maintenance, even after a day, and says what to run');
    MaintenanceMode::enter($r17mRoot, 'cli/migrate.php: 2 pending migration(s)');
    MaintenanceMode::leave($r17mRoot);
    assert_true(!MaintenanceMode::active($r17mRoot) && !is_file($r17mMarker), 'R17 maintenance: the next successful run lifts it');
} catch (Throwable $e) {
    assert_true(false, 'R17 maintenance (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    MaintenanceMode::leave($r17mRoot);
    foreach (glob($r17mRoot . '/*') ?: [] as $r17mFile) @unlink($r17mFile);
    @rmdir($r17mRoot);
}

// 3b. cli/migrate.php: on a failure the window is marked failed instead of lifted in a finally (the end-to-end run
//     with a throwing migration is in the PR proof; the suite cannot run a second copy of the CLI on its own database).
$r17mScript = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/migrate.php');
assert_true(str_contains($r17mScript, 'MaintenanceMode::fail($root') && !preg_match('/finally \{\s*if \(\$ownMaintenance\) MaintenanceMode::leave/', $r17mScript),
    'R17 maintenance: cli/migrate.php keeps the window after a failed migration instead of lifting it in a finally');
