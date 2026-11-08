<?php

// Review round 23 (2026-11-08, "kolo 7"), medium 1 + 6 and the updater lows (goal #365):
// - an updater killed half-way (SSH dropped, OOM killer, a migration timeout) runs no finally: its plain marker expired
//   after STALE_AFTER and the shop and worker ran a mix of old and new files over a half-migrated database. The same for
//   a rollback, cli/migrate.php and now cli/restore.php. Each marks its window failed (with what to run) before the
//   first change;
// - the worker checked maintenance only when it started and pulled jobs for up to 55 s into an update, holding the
//   SQLite connection that made the automatic rollback refuse. It checks before every pull and every job;
// - cli/migrate.php migrated under (and lifted) the window of an update that was running; the updater took over the
//   window of a running migration the same way. Both refuse while another process holds the window;
// - cli/restore.php replaced the database without any maintenance window;
// - MaintenanceMode::fail() let go of the lock before the failed marker was written.
echo "\n--- R23: a killed update, migration or restore keeps the shop closed; the worker stops for maintenance ---\n";
$r23mRepo = dirname(TEST_CLI_DIR);
$r23mBase = test_fixture_path('r23-maint');
@mkdir($r23mBase, 0700, true);
$r23mError = static function (callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};
$r23mReason = static fn(string $root): string => (string)(MaintenanceMode::marker($root)['reason'] ?? '');
$r23mMarked = static function (string $root): bool {
    clearstatcache(true, MaintenanceMode::path($root));
    return is_file(MaintenanceMode::path($root));
};
$r23mAge = static function (string $root, int $seconds): void {
    touch(MaintenanceMode::path($root), time() - $seconds);
    clearstatcache(true, MaintenanceMode::path($root));
};
$r23mPhp = static function (array $args, ?string $cwd = null): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => (string)$out];
};
// Another process (an update or a migration in another shell or container) holding a window open until released.
file_put_contents($r23mBase . '/hold.php', '<?php
require ' . var_export($r23mRepo . '/src/autoload.php', true) . ';
MaintenanceMode::enter($argv[1], $argv[2]);
// The armed phase: the step is changing files or the database, so its window says failed while it still holds it.
if (($argv[3] ?? "") === "arm") MaintenanceMode::arm($argv[1], $argv[2]);
echo "entered\n";
fgets(STDIN);
exit(1);
');
$r23mHold = static function (string $root, string $reason, string $phase = 'enter') use ($r23mBase): array {
    $process = proc_open([PHP_BINARY, $r23mBase . '/hold.php', $root, $reason, $phase], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $entered = trim((string)fgets($pipes[1]));
    return ['process' => $process, 'pipes' => $pipes, 'entered' => $entered];
};
$r23mRelease = static function (array $held, string $root): void {
    fclose($held['pipes'][0]);
    stream_get_contents($held['pipes'][1]);
    fclose($held['pipes'][1]);
    fclose($held['pipes'][2]);
    proc_close($held['process']);
    @unlink(MaintenanceMode::path($root));
};

// 1. Updater: apply killed during its migration (exit() runs no finally, as a kill).
$r23mUpdater = null;
try {
    $r23mRoot = $r23mBase . '/updater';
    foreach (['base' => 1, 'installed' => 1, 'v2' => 2] as $tree => $n) {
        @mkdir("{$r23mRoot}/{$tree}/src", 0700, true);
        file_put_contents("{$r23mRoot}/{$tree}/src/A.php", "a{$n}\n");
    }
    @mkdir("{$r23mRoot}/v2/migrations", 0700, true);
    file_put_contents("{$r23mRoot}/v2/migrations/20991231000000_r23.php", "<?php return new class { public function up(PDO \$db, bool \$isSqlite): void {} };\n");
    UpdatePackageBuilder::build($r23mRoot . '/base', $r23mRoot . '/v2', $r23mRoot . '/package-2', '23.0.1', '23.0.0');
    $r23mShop = $r23mRoot . '/installed';
    $r23mDb = $r23mRoot . '/live.sqlite';
    (new PDO('sqlite:' . $r23mDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    $r23mUpdater = new Updater($r23mShop, $r23mRoot . '/private',
        new PDO('sqlite:' . $r23mDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function (): void {});
    $r23mUpdater->baseline('23.0.0');
    $r23mRead = static fn(): string => trim((string)@file_get_contents($r23mShop . '/src/A.php'));

    file_put_contents($r23mRoot . '/killed.php', '<?php
require ' . var_export($r23mRepo . '/src/autoload.php', true) . ';
$root = $argv[1];
$updater = new Updater($root . "/installed", $root . "/private", new PDO("sqlite:" . $root . "/live.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
    static function (): void { echo "migrating\n"; exit(9); });
$updater->apply(new UpdatePackage($root . "/package-2", null, true));
echo "returned\n";
');
    $r23mKilled = $r23mPhp([$r23mRoot . '/killed.php', $r23mRoot]);
    $r23mFound = preg_match('/--snapshot=(\S+)/', $r23mReason($r23mShop), $r23mNamed) === 1 ? $r23mNamed[1] : '';
    $r23mAge($r23mShop, MaintenanceMode::STALE_AFTER + 600);
    $r23mHour = MaintenanceMode::active($r23mShop);
    $r23mAge($r23mShop, MaintenanceMode::MAX_AGE + 600);
    $r23mDay = MaintenanceMode::active($r23mShop);
    assert_equals([9, true, 'a2', true, true, true, true], [$r23mKilled['exit'], str_contains($r23mKilled['output'], 'migrating'), $r23mRead(),
        MaintenanceMode::failed($r23mShop, 'update 23.0.1 was interrupted'), $r23mFound !== '', $r23mHour, $r23mDay],
        'R23 updater: killed during its migration, the update leaves the shop closed past the hour (and the day) with the rollback to run (was: open after STALE_AFTER on half-updated files) — ' . trim(substr($r23mKilled['output'], -200)));
    $r23mUpdater->rollback($r23mFound, true, true);
    assert_equals(['a1', false], [$r23mRead(), $r23mMarked($r23mShop)],
        'R23 updater: the rollback the window names restores the files and reopens the shop');

    // 2. A window another process holds (cli/migrate.php running) is not taken over by an update.
    $r23mUpdater = new Updater($r23mShop, $r23mRoot . '/private',
        new PDO('sqlite:' . $r23mDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function (): void {});
    $r23mHeld = $r23mHold($r23mShop, 'cli/migrate.php: 1 pending migration(s)');
    $r23mRefused = $r23mError(static fn() => $r23mUpdater->apply(new UpdatePackage($r23mRoot . '/package-2', null, true)));
    assert_equals(['entered', true, 'a1', 'cli/migrate.php: 1 pending migration(s)'], [$r23mHeld['entered'],
        str_contains($r23mRefused, 'Another maintenance window is open'), $r23mRead(), $r23mReason($r23mShop)],
        'R23 updater: while a migration holds the window, an update is refused and changes nothing (was: it took the window over and lifted it at the end)');
    $r23mRelease($r23mHeld, $r23mShop);
    // The same while that migration is in its armed phase (its window says failed while it runs): still refused.
    $r23mArmedReason = 'cli/migrate.php was interrupted during a migration — the shop stays in maintenance; fix the cause and run php cli/migrate.php apply again';
    $r23mHeld = $r23mHold($r23mShop, $r23mArmedReason, 'arm');
    $r23mRefused = $r23mError(static fn() => $r23mUpdater->apply(new UpdatePackage($r23mRoot . '/package-2', null, true)));
    $r23mArmedState = [$r23mHeld['entered'], str_contains($r23mRefused, 'Another maintenance window is open'), $r23mRead(), $r23mReason($r23mShop)];
    $r23mRelease($r23mHeld, $r23mShop);
    assert_equals(['entered', true, 'a1', $r23mArmedReason], $r23mArmedState,
        'R23 updater: while a migration holds its armed (failed-marked) window, an update is refused and changes nothing (was: taken for a dead run\'s window) — ' . $r23mRefused);
    $r23mUpdater->apply(new UpdatePackage($r23mRoot . '/package-2', null, true));
    assert_equals(['a2', false], [$r23mRead(), $r23mMarked($r23mShop)], 'R23 updater: once that step ended the update runs');

    // 3. Whose marker it is (cli/migrate.php lifts only its own).
    MaintenanceMode::enter($r23mShop, 'cli/migrate.php: 1 pending migration(s)');
    $r23mMine = MaintenanceMode::owned($r23mShop);
    file_put_contents(MaintenanceMode::path($r23mShop), json_encode(['since' => gmdate('c'), 'reason' => 'update 23.0.9', 'pid' => 1, 'host' => 'r23-other.invalid']));
    assert_equals([true, false], [$r23mMine, MaintenanceMode::owned($r23mShop)], 'R23 maintenance: owned() tells this process\'s marker from one another step wrote over it');
    MaintenanceMode::leave($r23mShop);
    assert_true(preg_match('/public function rollback\(.*MaintenanceMode::arm\(\$this->root, "rollback \{\$snapshotId\} was interrupted.*\$this->restoreSnapshot\(/s',
        (string)file_get_contents($r23mRepo . '/src/Updater.php')) === 1,
        'R23 updater: a rollback marks its window failed before it restores anything (killed half-way, the shop stays closed)');
} catch (Throwable $e) {
    assert_true(false, 'R23 updater maintenance (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r23mUpdater = null;
    gc_collect_cycles();
}

// 4-6. cli/migrate.php, cli/worker.php and cli/restore.php in a disposable copy of the application (own config.php,
// own SQLite database), never on the suite's database.
$r23mApp = $r23mBase . '/app';
$r23mCopy = static function (string $from, string $to) use (&$r23mCopy): void {
    @mkdir($to, 0700, true);
    foreach (new FilesystemIterator($from) as $item) {
        $target = $to . '/' . $item->getFilename();
        if ($item->isDir()) $r23mCopy($item->getPathname(), $target);
        else copy($item->getPathname(), $target);
    }
};
try {
    foreach (['src', 'adapters', 'migrations', 'config', 'extensions'] as $directory) {
        if (is_dir($r23mRepo . '/' . $directory)) $r23mCopy($r23mRepo . '/' . $directory, $r23mApp . '/' . $directory);
    }
    foreach (glob($r23mRepo . '/cli/*.php') ?: [] as $script) {
        @mkdir($r23mApp . '/cli', 0700, true);
        copy($script, $r23mApp . '/cli/' . basename($script));
    }
    foreach (glob($r23mRepo . '/*.json') ?: [] as $file) copy($file, $r23mApp . '/' . basename($file));
    $r23mAppDb = $r23mBase . '/app-db/shop.sqlite';
    @mkdir(dirname($r23mAppDb), 0700, true);
    file_put_contents($r23mApp . '/config.php', '<?php return ' . var_export([
        'db_driver' => 'sqlite',
        'db_sqlite_path' => $r23mAppDb,
        'jobs_dir' => $r23mBase . '/app-jobs',
        'cache_dir' => $r23mBase . '/app-cache',
        'backup_storage_dir' => $r23mBase . '/app-private/backups',
        'backup_log_file' => $r23mBase . '/app-private/backup.log',
    ], true) . ";\n");
    $r23mFirst = $r23mPhp([$r23mApp . '/cli/migrate.php', 'apply']);
    assert_equals([0, false], [$r23mFirst['exit'], $r23mMarked($r23mApp)],
        'R23 migrate: a successful run lifts its window — ' . trim(substr($r23mFirst['output'], -160)));
    $r23mTable = static function () use ($r23mAppDb): bool {
        $pdo = new PDO('sqlite:' . $r23mAppDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'r23_probe'")->fetchColumn() === 1;
    };

    // 4a. Another process holds the window (an update is running): no migration runs under it, the window stays.
    $r23mProbe = $r23mApp . '/migrations/20991231000000_r23_probe.php';
    file_put_contents($r23mProbe, "<?php return new class { public function up(PDO \$db, bool \$isSqlite): void { \$db->exec('CREATE TABLE r23_probe (id INTEGER)'); } };\n");
    $r23mHeld = $r23mHold($r23mApp, 'update 23.0.1');
    $r23mUnder = $r23mPhp([$r23mApp . '/cli/migrate.php', 'apply']);
    $r23mUnderState = [$r23mHeld['entered'], $r23mUnder['exit'] !== 0, str_contains($r23mUnder['output'], 'Another maintenance window is open'),
        $r23mTable(), $r23mReason($r23mApp)];
    $r23mRelease($r23mHeld, $r23mApp);
    assert_equals(['entered', true, true, false, 'update 23.0.1'], $r23mUnderState,
        'R23 migrate: while an update holds the window, cli/migrate.php refuses and leaves that window alone (was: migrated under it and lifted it) — ' . trim(substr($r23mUnder['output'], -160)));
    $r23mUpdateArmed = 'update 23.0.1 was interrupted before it finished; run php cli/update.php rollback --snapshot=r23x --force --accept-data-loss';
    $r23mHeld = $r23mHold($r23mApp, $r23mUpdateArmed, 'arm');
    $r23mUnder = $r23mPhp([$r23mApp . '/cli/migrate.php', 'apply']);
    $r23mUnderState = [$r23mHeld['entered'], $r23mUnder['exit'] !== 0, str_contains($r23mUnder['output'], 'Another maintenance window is open'),
        $r23mTable(), $r23mReason($r23mApp)];
    $r23mRelease($r23mHeld, $r23mApp);
    assert_equals(['entered', true, true, false, $r23mUpdateArmed], $r23mUnderState,
        'R23 migrate: while an update is in its armed phase (window marked failed, still held), cli/migrate.php refuses too (was: migrated under it) — ' . trim(substr($r23mUnder['output'], -160)));

    // 4b. Killed during a migration: the shop stays closed past the hour with what to run.
    file_put_contents($r23mProbe, "<?php return new class { public function up(PDO \$db, bool \$isSqlite): void { echo \"r23 migrating\\n\"; exit(7); } };\n");
    $r23mKilledRun = $r23mPhp([$r23mApp . '/cli/migrate.php', 'apply']);
    $r23mAge($r23mApp, MaintenanceMode::STALE_AFTER + 600);
    assert_equals([7, true, true], [$r23mKilledRun['exit'], MaintenanceMode::active($r23mApp), MaintenanceMode::failed($r23mApp, 'cli/migrate.php was interrupted')],
        'R23 migrate: killed during a migration, cli/migrate.php leaves the shop closed past the hour (was: the marker expired) — ' . trim(substr($r23mKilledRun['output'], -160)));
    file_put_contents($r23mProbe, "<?php return new class { public function up(PDO \$db, bool \$isSqlite): void { \$db->exec('CREATE TABLE r23_probe (id INTEGER)'); } };\n");
    $r23mFixed = $r23mPhp([$r23mApp . '/cli/migrate.php', 'apply']);
    assert_equals([0, true, false], [$r23mFixed['exit'], $r23mTable(), $r23mMarked($r23mApp)],
        'R23 migrate: the next successful run completes the schema and lifts that window — ' . trim(substr($r23mFixed['output'], -160)));

    // 5. The worker stops when maintenance starts while it is in its loop (was: up to 55 s more of jobs).
    $r23mWorker = proc_open([PHP_BINARY, $r23mApp . '/cli/worker.php', '--verbose'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r23mWorkerPipes);
    fclose($r23mWorkerPipes[0]);
    $r23mWorkerOut = '';
    while (!str_contains($r23mWorkerOut, 'Worker started') && ($r23mLine = fgets($r23mWorkerPipes[1])) !== false) $r23mWorkerOut .= $r23mLine;
    sleep(3); // in the loop by now (no jobs: it polls every 2 s)
    MaintenanceMode::enter($r23mApp, 'update 23.0.2');
    $r23mEntered = microtime(true);
    stream_set_blocking($r23mWorkerPipes[1], false);
    do {
        $r23mWorkerOut .= (string)stream_get_contents($r23mWorkerPipes[1]);
        $r23mStatus = proc_get_status($r23mWorker);
        if ($r23mStatus['running']) usleep(200000);
    } while ($r23mStatus['running'] && microtime(true) - $r23mEntered < 30);
    $r23mStopped = microtime(true) - $r23mEntered;
    if ($r23mStatus['running']) proc_terminate($r23mWorker);
    $r23mWorkerOut .= (string)stream_get_contents($r23mWorkerPipes[1]) . (string)stream_get_contents($r23mWorkerPipes[2]);
    fclose($r23mWorkerPipes[1]);
    fclose($r23mWorkerPipes[2]);
    proc_close($r23mWorker);
    MaintenanceMode::leave($r23mApp);
    assert_true($r23mStopped < 15 && str_contains($r23mWorkerOut, 'Maintenance started; worker stops.'),
        sprintf('R23 worker: maintenance that starts during the run stops the worker within seconds (%.1f s; was: it kept pulling jobs until 55 s) — %s',
            $r23mStopped, trim(substr($r23mWorkerOut, -200))));

    // 6. cli/restore.php keeps its own window, never takes over one that is held, and keeps a failed one.
    $r23mBackup = $r23mPhp([$r23mApp . '/cli/backup.php', '--quiet']);
    assert_equals(0, $r23mBackup['exit'], 'R23 restore: fixture backup — ' . trim(substr($r23mBackup['output'], -160)));
    $r23mHeld = $r23mHold($r23mApp, 'update 23.0.3');
    $r23mBlocked = $r23mPhp([$r23mApp . '/cli/restore.php', '--latest', '--yes']);
    $r23mBlockedState = [$r23mBlocked['exit'], str_contains($r23mBlocked['output'], 'another maintenance window is open'), $r23mReason($r23mApp)];
    $r23mRelease($r23mHeld, $r23mApp);
    assert_equals([1, true, 'update 23.0.3'], $r23mBlockedState,
        'R23 restore: while an update holds the window, cli/restore.php is refused and leaves it alone (was: replaced the database under it) — ' . trim(substr($r23mBlocked['output'], -160)));
    $r23mUpdateArmed = 'update 23.0.3 was interrupted before it finished; run php cli/update.php rollback --snapshot=r23x --force --accept-data-loss';
    $r23mHeld = $r23mHold($r23mApp, $r23mUpdateArmed, 'arm');
    $r23mBlocked = $r23mPhp([$r23mApp . '/cli/restore.php', '--latest', '--yes']);
    $r23mBlockedState = [$r23mHeld['entered'], $r23mBlocked['exit'], str_contains($r23mBlocked['output'], 'another maintenance window is open'), $r23mReason($r23mApp)];
    $r23mRelease($r23mHeld, $r23mApp);
    assert_equals(['entered', 1, true, $r23mUpdateArmed], $r23mBlockedState,
        'R23 restore: while an update is in its armed phase (window marked failed, still held), cli/restore.php is refused too (was: taken for a dead run\'s window, restored under the update and took its window over) — ' . trim(substr($r23mBlocked['output'], -160)));
    $r23mRestored = $r23mPhp([$r23mApp . '/cli/restore.php', '--latest', '--yes']);
    assert_equals([0, false], [$r23mRestored['exit'], $r23mMarked($r23mApp)],
        'R23 restore: a completed restore lifts its own window — ' . trim(substr($r23mRestored['output'], -160)));
    MaintenanceMode::fail($r23mApp, 'cli/migrate.php failed: r23 probe — run php cli/migrate.php apply again');
    $r23mUnderFailed = $r23mPhp([$r23mApp . '/cli/restore.php', '--latest', '--yes']);
    assert_equals([0, true, 'cli/migrate.php failed: r23 probe — run php cli/migrate.php apply again'],
        [$r23mUnderFailed['exit'], MaintenanceMode::failed($r23mApp), $r23mReason($r23mApp)],
        'R23 restore: a failed window found before stays after the restore (the restore does not fix what it names) — ' . trim(substr($r23mUnderFailed['output'], -160)));
    MaintenanceMode::leave($r23mApp);
    $r23mRestoreCli = (string)file_get_contents($r23mRepo . '/cli/restore.php');
    assert_true(preg_match('/MaintenanceMode::enter\(\$root.*MaintenanceMode::arm\(\$root.*BackupService::restore\(/s', $r23mRestoreCli) === 1,
        'R23 restore: cli/restore.php marks its window failed before it replaces anything (a killed restore keeps the shop closed)');
} catch (Throwable $e) {
    assert_true(false, 'R23 migrate/worker/restore (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    if (function_exists('updateTestRemoveTree')) updateTestRemoveTree($r23mBase);
}
