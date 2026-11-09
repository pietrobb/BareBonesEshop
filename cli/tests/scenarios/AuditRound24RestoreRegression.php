<?php

// Review round 24 (2026-11-09, "kolo 8"), finding 1 (goal #378): an interrupted cli/restore.php left a failed window that
// names the restore to run again. That run completed, then put the earlier failure back (cli/restore.php:141,153): the
// shop stayed in maintenance for good, whatever was run next. A restore of any backup (capability review run 373: also
// another one or the pre-restore-snapshot) that replaces at least what the failed one did (restore_scope: database, files
// or both) now lifts it; less, or a failed window of another step, stays — also under a restore killed on top of it
// (capability review run 375).
echo "\n--- R24: re-running an interrupted restore reopens the shop ---\n";
$r24rRepo = dirname(TEST_CLI_DIR);
$r24rBase = test_fixture_path('r24-restore');
@mkdir($r24rBase, 0700, true);
$r24rPhp = static function (array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => (string)$out];
};
$r24rCopy = static function (string $from, string $to) use (&$r24rCopy): void {
    @mkdir($to, 0700, true);
    foreach (new FilesystemIterator($from) as $item) {
        $target = $to . '/' . $item->getFilename();
        if ($item->isDir()) $r24rCopy($item->getPathname(), $target);
        else copy($item->getPathname(), $target);
    }
};
// A restore killed after arm(): its process ends without lifting or replacing the window (no finally runs).
file_put_contents($r24rBase . '/killed.php', '<?php
require ' . var_export($r24rRepo . '/src/autoload.php', true) . ';
MaintenanceMode::enter($argv[1], "cli/restore.php " . $argv[2]);
MaintenanceMode::arm($argv[1], $argv[3], ["restore_scope" => $argv[4]]);
exit(9);
');
$r24rApp = $r24rBase . '/app';
try {
    foreach (['src', 'adapters', 'migrations', 'config', 'extensions'] as $directory) {
        if (is_dir($r24rRepo . '/' . $directory)) $r24rCopy($r24rRepo . '/' . $directory, $r24rApp . '/' . $directory);
    }
    foreach (glob($r24rRepo . '/cli/*.php') ?: [] as $script) {
        @mkdir($r24rApp . '/cli', 0700, true);
        copy($script, $r24rApp . '/cli/' . basename($script));
    }
    foreach (glob($r24rRepo . '/*.json') ?: [] as $file) copy($file, $r24rApp . '/' . basename($file));
    @mkdir($r24rBase . '/app-db', 0700, true);
    file_put_contents($r24rApp . '/config.php', '<?php return ' . var_export([
        'db_driver' => 'sqlite',
        'db_sqlite_path' => $r24rBase . '/app-db/shop.sqlite',
        'jobs_dir' => $r24rBase . '/app-jobs',
        'cache_dir' => $r24rBase . '/app-cache',
        'backup_storage_dir' => $r24rBase . '/app-private/backups',
        'backup_log_file' => $r24rBase . '/app-private/backup.log',
    ], true) . ";\n");
    $r24rSetup = [$r24rPhp([$r24rApp . '/cli/migrate.php', 'apply']), $r24rPhp([$r24rApp . '/cli/backup.php', '--quiet'])];
    $r24rList = $r24rPhp([$r24rApp . '/cli/restore.php', '--list']);
    $r24rPath = (string)strtok(trim($r24rList['output']), " \n");
    assert_equals([0, 0, true], [$r24rSetup[0]['exit'], $r24rSetup[1]['exit'], str_contains($r24rPath, '.db')],
        'R24 restore: fixture database and backup — ' . trim(substr($r24rSetup[1]['output'] . $r24rList['output'], -160)));

    $r24rReason = static fn(string $path, string $scope): string => "cli/restore.php {$path} was interrupted before it finished; check the database and files, then run php cli/restore.php "
        . escapeshellarg($path) . $scope . ' again';
    $r24rKill = static function (string $path, string $reason, string $scope = 'db') use ($r24rPhp, $r24rBase, $r24rApp): array {
        $killed = $r24rPhp([$r24rBase . '/killed.php', $r24rApp, $path, $reason, $scope]);
        clearstatcache();
        return [$killed['exit'], MaintenanceMode::active($r24rApp), MaintenanceMode::failed($r24rApp, 'cli/restore.php ')];
    };
    $r24rState = static function () use ($r24rApp): array {
        clearstatcache();
        return [is_file(MaintenanceMode::path($r24rApp)), (string)(MaintenanceMode::marker($r24rApp)['reason'] ?? '')];
    };

    // 1. Killed while restoring the database; the command its marker names completes it and reopens the shop.
    assert_equals([9, true, true], $r24rKill($r24rPath, $r24rReason($r24rPath, '')),
        'R24 restore: an interrupted restore leaves a failed window that names the restore to run again');
    $r24rAgain = $r24rPhp([$r24rApp . '/cli/restore.php', $r24rPath, '--yes']);
    assert_equals([0, [false, '']], [$r24rAgain['exit'], $r24rState()],
        'R24 restore: running the restore the failed window names again lifts it and opens the shop (was: the earlier failure was put back, maintenance for good) — '
        . trim(substr($r24rAgain['output'], -200)));

    // 2. A restore of less than the interrupted one (database only after files) leaves the files half-restored: it stays.
    $r24rFiles = $r24rReason($r24rPath, ' --files-only');
    $r24rKill($r24rPath, $r24rFiles, 'files');
    $r24rLess = $r24rPhp([$r24rApp . '/cli/restore.php', $r24rPath, '--yes']);
    assert_equals([0, [true, $r24rFiles], true], [$r24rLess['exit'], $r24rState(), str_contains($r24rLess['output'], 'stays in maintenance')],
        'R24 restore: a database-only restore keeps the window of an interrupted files restore, and says so — ' . trim(substr($r24rLess['output'], -200)));
    MaintenanceMode::leave($r24rApp);

    // 3. Capability review run 373: the database restored from ANOTHER backup completes an interrupted database restore
    //    (was: the failure named backup A, so restoring B put it back and the shop stayed closed for good).
    $r24rKill('2000/01/01/other.db', $r24rReason('2000/01/01/other.db', ''));
    $r24rRun = $r24rPhp([$r24rApp . '/cli/restore.php', $r24rPath, '--yes']);
    assert_equals([0, [false, '']], [$r24rRun['exit'], $r24rState()],
        'R24 restore: restoring another backup lifts the failed window of an interrupted database restore — ' . trim(substr($r24rRun['output'], -200)));

    // 4. ... also the pre-restore-snapshot the interrupted restore took (the documented way back).
    $r24rSnapshot = '';
    foreach (explode("\n", $r24rPhp([$r24rApp . '/cli/restore.php', '--list'])['output']) as $r24rLine) {
        if ($r24rSnapshot === '' && str_contains($r24rLine, 'label=pre-restore-snapshot')) $r24rSnapshot = (string)strtok($r24rLine, ' ');
    }
    $r24rKill($r24rPath, $r24rReason($r24rPath, ''));
    $r24rRun = $r24rPhp([$r24rApp . '/cli/restore.php', $r24rSnapshot, '--yes']);
    assert_equals([true, 0, [false, '']], [$r24rSnapshot !== '', $r24rRun['exit'], $r24rState()],
        'R24 restore: restoring the pre-restore-snapshot lifts the failed window of the interrupted restore — ' . $r24rSnapshot . ' ' . trim(substr($r24rRun['output'], -200)));

    // 5. An interrupted database + files restore, then the database only: the files may be half-replaced, it stays.
    $r24rBoth = $r24rReason('2000/01/01/other.db', ' --files');
    $r24rKill('2000/01/01/other.db', $r24rBoth, 'both');
    $r24rRun = $r24rPhp([$r24rApp . '/cli/restore.php', $r24rPath, '--yes']);
    assert_equals([0, [true, $r24rBoth], 'both'], [$r24rRun['exit'], $r24rState(), MaintenanceMode::marker($r24rApp)['restore_scope'] ?? null],
        'R24 restore: a database-only restore keeps the window of an interrupted database + files restore — ' . trim(substr($r24rRun['output'], -160)));
    MaintenanceMode::leave($r24rApp);

    // 6. Another step's failure (a migration) is not what a restore completes.
    $r24rMigrate = 'cli/migrate.php failed: r24 probe — run php cli/migrate.php apply again';
    MaintenanceMode::fail($r24rApp, $r24rMigrate);
    $r24rRun = $r24rPhp([$r24rApp . '/cli/restore.php', $r24rPath, '--yes']);
    assert_equals([0, [true, $r24rMigrate]], [$r24rRun['exit'], $r24rState()],
        'R24 restore: a failed migration window stays after a restore — ' . trim(substr($r24rRun['output'], -160)));
    MaintenanceMode::leave($r24rApp);

    // 7. Capability review run 375: a restore killed ON TOP of a failed update or migration. Its own window (restore_scope)
    //    replaced the marker and dropped the earlier failure, so the next restore that covered it reopened the shop on
    //    half-replaced code or a half-migrated schema. The real cli/restore.php is killed right after its arm().
    $r24rCliCopy = (string)file_get_contents($r24rApp . '/cli/restore.php');
    $r24rArmAt = strpos($r24rCliCopy, 'MaintenanceMode::arm($root,');
    $r24rArmEnd = $r24rArmAt === false ? false : strpos($r24rCliCopy, ");\n", $r24rArmAt);
    assert_true($r24rArmEnd !== false, 'R24 restore: the arm() call of cli/restore.php is found for the kill probe');
    file_put_contents($r24rApp . '/cli/restore-killed.php', substr($r24rCliCopy, 0, (int)$r24rArmEnd + 3) . "exit(9);\n" . substr($r24rCliCopy, (int)$r24rArmEnd + 3));
    $r24rUpdate = 'update 24.0.1 failed half-way (r24 probe); run php cli/update.php rollback --snapshot=r24-probe --force';
    foreach ([[$r24rUpdate, '', 'an update'], [$r24rMigrate, '', 'a migration'], [$r24rUpdate, '--files', 'an update (database + files restore)']] as [$r24rOther, $r24rFlag, $r24rWhat]) {
        MaintenanceMode::fail($r24rApp, $r24rOther);
        $r24rArgs = array_values(array_filter([$r24rPath, $r24rFlag, '--yes']));
        $r24rKilled = $r24rPhp(array_merge([$r24rApp . '/cli/restore-killed.php'], $r24rArgs));
        clearstatcache();
        $r24rKilledMarker = MaintenanceMode::marker($r24rApp);
        assert_equals([9, true, true], [$r24rKilled['exit'], MaintenanceMode::failed($r24rApp), str_contains((string)($r24rKilledMarker['reason'] ?? ''), $r24rOther)],
            "R24 restore: a restore killed on top of {$r24rWhat} failure still names that failure — " . trim(substr($r24rKilled['output'], -160)));
        // Killed twice: the second restore's window carries the earlier failure on.
        $r24rPhp(array_merge([$r24rApp . '/cli/restore-killed.php'], $r24rArgs));
        $r24rRun = $r24rPhp(array_merge([$r24rApp . '/cli/restore.php'], $r24rArgs));
        assert_equals([0, [true, $r24rOther], true], [$r24rRun['exit'], $r24rState(), str_contains($r24rRun['output'], 'stays in maintenance')],
            "R24 restore: a restore that completes interrupted ones keeps {$r24rWhat} failure under them (was: reopened the shop) — " . trim(substr($r24rRun['output'], -200)));
        MaintenanceMode::leave($r24rApp);
    }

    // The reason the test kills with is the one cli/restore.php arms with.
    $r24rCli = (string)file_get_contents($r24rRepo . '/cli/restore.php');
    assert_true(str_contains($r24rCli, 'MaintenanceMode::arm($root, "cli/restore.php {$remotePath} was interrupted before it finished; check the database and files, then run {$restoreCommand} again"')
        && str_contains($r24rCli, "\$failAbout = ['restore_scope' => \$failScope]"),
        'R24 restore: cli/restore.php arms its window with the reason and restore_scope this test simulates');
} catch (Throwable $e) {
    assert_true(false, 'R24 restore (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    @unlink(MaintenanceMode::path($r24rApp));
    if (function_exists('updateTestRemoveTree')) updateTestRemoveTree($r24rBase);
}
