<?php

// Review round 25 (2026-11-09, "kolo 9"), finding 1, finding 4 and the open part of "arm() overwrites an earlier failure"
// (goal #384):
// - a restore that failed without replacing anything (a corrupt backup, a refused files archive) lifted the failed window
//   an interrupted restore of the same scope had left: the shop reopened on a half-restored database or files;
// - the command the window names dropped --force and --i-stopped-writers: run again, it was refused (or, by the above,
//   reopened the shop);
// - a restore that left pending migrations reopened the shop on a schema older than the code;
// - a restore's reason quotes earlier failures ("; earlier: … --snapshot=X"): a rollback of code only of X lifted it;
// - an update or rollback armed over a failed migration dropped that failure: the rollback its marker names reopened the
//   shop over the half-migrated schema.
echo "\n--- R25: a failed restore keeps the window it found; flags, pending migrations, carried failures ---\n";
$r25rRepo = dirname(TEST_CLI_DIR);
$r25rBase = test_fixture_path('r25-restore');
@mkdir($r25rBase, 0700, true);
$r25rPhp = static function (array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => (string)$out];
};
$r25rCopy = static function (string $from, string $to) use (&$r25rCopy): void {
    @mkdir($to, 0700, true);
    foreach (new FilesystemIterator($from) as $item) {
        $target = $to . '/' . $item->getFilename();
        if ($item->isDir()) $r25rCopy($item->getPathname(), $target);
        else copy($item->getPathname(), $target);
    }
};
// A restore killed after arm(): its process ends without lifting or replacing the window (no finally runs).
file_put_contents($r25rBase . '/killed.php', '<?php
require ' . var_export($r25rRepo . '/src/autoload.php', true) . ';
MaintenanceMode::enter($argv[1], "cli/restore.php " . $argv[2]);
MaintenanceMode::arm($argv[1], $argv[3], ["restore_scope" => $argv[4]]);
exit(9);
');
$r25rApp = $r25rBase . '/app';
$r25rUpdater = null;
try {
    foreach (['src', 'adapters', 'migrations', 'config', 'extensions'] as $directory) {
        if (is_dir($r25rRepo . '/' . $directory)) $r25rCopy($r25rRepo . '/' . $directory, $r25rApp . '/' . $directory);
    }
    foreach (glob($r25rRepo . '/cli/*.php') ?: [] as $script) {
        @mkdir($r25rApp . '/cli', 0700, true);
        copy($script, $r25rApp . '/cli/' . basename($script));
    }
    foreach (glob($r25rRepo . '/*.json') ?: [] as $file) copy($file, $r25rApp . '/' . basename($file));
    @mkdir($r25rBase . '/app-db', 0700, true);
    file_put_contents($r25rApp . '/config.php', '<?php return ' . var_export([
        'db_driver' => 'sqlite',
        'db_sqlite_path' => $r25rBase . '/app-db/shop.sqlite',
        'jobs_dir' => $r25rBase . '/app-jobs',
        'cache_dir' => $r25rBase . '/app-cache',
        'backup_storage_dir' => $r25rBase . '/app-private/backups',
        'backup_log_file' => $r25rBase . '/app-private/backup.log',
    ], true) . ";\n");
    $r25rSetup = [$r25rPhp([$r25rApp . '/cli/migrate.php', 'apply']), $r25rPhp([$r25rApp . '/cli/backup.php', '--quiet'])];
    sleep(1); // the second backup gets its own path
    $r25rSetup[] = $r25rPhp([$r25rApp . '/cli/backup.php', '--quiet']);
    $r25rPaths = [];
    foreach (explode("\n", trim($r25rPhp([$r25rApp . '/cli/restore.php', '--list'])['output'])) as $r25rLine) {
        $r25rP = (string)strtok($r25rLine, ' ');
        if (str_ends_with($r25rP, '.db')) $r25rPaths[] = $r25rP;
    }
    assert_equals([0, 0, 0, 2], [$r25rSetup[0]['exit'], $r25rSetup[1]['exit'], $r25rSetup[2]['exit'], count($r25rPaths)],
        'R25 restore: fixture database and two backups — ' . implode(' ', $r25rPaths));
    [$r25rGood, $r25rBad] = $r25rPaths; // newest first
    // The second backup is corrupt: the database and its files archive no longer match their recorded sha256.
    file_put_contents($r25rBase . '/app-private/backups/' . $r25rBad, 'r25 corrupt', FILE_APPEND);
    file_put_contents($r25rBase . '/app-private/backups/' . $r25rBad . '.files.tar', 'r25 corrupt', FILE_APPEND);

    $r25rReason = static fn(string $path, string $flags): string => "cli/restore.php {$path} was interrupted before it finished; check the database and files, then run php cli/restore.php "
        . escapeshellarg($path) . $flags . ' again';
    $r25rKill = static function (string $path, string $reason, string $scope) use ($r25rPhp, $r25rBase, $r25rApp): array {
        $r25rPhp([$r25rBase . '/killed.php', $r25rApp, $path, $reason, $scope]);
        clearstatcache();
        return MaintenanceMode::marker($r25rApp);
    };
    $r25rState = static function () use ($r25rApp): array {
        clearstatcache();
        return [is_file(MaintenanceMode::path($r25rApp)), (string)(MaintenanceMode::marker($r25rApp)['reason'] ?? '')];
    };

    // 1. Killed while restoring the database; then a restore of the same scope fails before it replaces anything (corrupt
    //    backup, sha256 mismatch). The database is still half-restored: the window it found stays as it was.
    $r25rKilled = $r25rKill($r25rGood, $r25rReason($r25rGood, ''), 'db');
    $r25rRun = $r25rPhp([$r25rApp . '/cli/restore.php', $r25rBad, '--yes']);
    clearstatcache();
    assert_equals([4, true, $r25rKilled], [$r25rRun['exit'], MaintenanceMode::failed($r25rApp), MaintenanceMode::marker($r25rApp)],
        'R25 restore: a failed restore that replaced nothing puts back the window of the interrupted one (was: lifted, shop reopened) — '
        . trim(substr($r25rRun['output'], -200)));
    // ... and the restore the window names still completes it.
    $r25rRun = $r25rPhp([$r25rApp . '/cli/restore.php', $r25rGood, '--yes']);
    assert_equals([0, [false, '']], [$r25rRun['exit'], $r25rState()], 'R25 restore: the named restore then lifts it — ' . trim(substr($r25rRun['output'], -160)));

    // 2. The same for files: a killed files restore, then a files restore of a corrupt archive.
    $r25rKilled = $r25rKill($r25rGood, $r25rReason($r25rGood, ' --files-only --force'), 'files');
    $r25rRun = $r25rPhp([$r25rApp . '/cli/restore.php', $r25rBad, '--files-only', '--yes']);
    clearstatcache();
    assert_equals([5, $r25rKilled], [$r25rRun['exit'], MaintenanceMode::marker($r25rApp)],
        'R25 restore: a refused files restore keeps the window of an interrupted files restore — ' . trim(substr($r25rRun['output'], -200)));
    MaintenanceMode::leave($r25rApp);

    // 3. A database + files restore whose files part fails without writing: the database part completes an interrupted
    //    database restore, an interrupted files restore stays.
    $r25rFiles = $r25rReason($r25rGood, ' --files-only');
    $r25rKill($r25rGood, $r25rFiles, 'files');
    copy($r25rBase . '/app-private/backups/' . $r25rBad . '.files.tar', $r25rBase . '/bad.files.tar');
    copy($r25rBase . '/app-private/backups/' . $r25rGood . '.files.tar', $r25rBase . '/good.files.tar');
    copy($r25rBase . '/bad.files.tar', $r25rBase . '/app-private/backups/' . $r25rGood . '.files.tar');
    $r25rRun = $r25rPhp([$r25rApp . '/cli/restore.php', $r25rGood, '--files', '--yes']);
    copy($r25rBase . '/good.files.tar', $r25rBase . '/app-private/backups/' . $r25rGood . '.files.tar');
    assert_equals([5, [true, $r25rFiles]], [$r25rRun['exit'], $r25rState()],
        'R25 restore: the files part of a database + files restore failing keeps the interrupted files restore — ' . trim(substr($r25rRun['output'], -200)));
    MaintenanceMode::leave($r25rApp);

    // 4. The command the window names keeps --force and --i-stopped-writers. The real cli/restore.php is killed after arm().
    $r25rCli = (string)file_get_contents($r25rApp . '/cli/restore.php');
    $r25rArmAt = strpos($r25rCli, 'MaintenanceMode::arm($root,');
    $r25rArmEnd = $r25rArmAt === false ? false : strpos($r25rCli, ");\n", $r25rArmAt);
    assert_true($r25rArmEnd !== false, 'R25 restore: the arm() call of cli/restore.php is found for the kill probe');
    file_put_contents($r25rApp . '/cli/restore-killed.php', substr($r25rCli, 0, (int)$r25rArmEnd + 3) . "exit(9);\n" . substr($r25rCli, (int)$r25rArmEnd + 3));
    $r25rRun = $r25rPhp([$r25rApp . '/cli/restore-killed.php', $r25rGood, '--files', '--force', '--i-stopped-writers', '--yes']);
    assert_equals([9, [true, $r25rReason($r25rGood, ' --files --force --i-stopped-writers')]], [$r25rRun['exit'], $r25rState()],
        'R25 restore: the command an interrupted restore names is the one that ran, with --force and --i-stopped-writers — ' . trim(substr($r25rRun['output'], -160)));
    MaintenanceMode::leave($r25rApp);

    // 5. A restore that leaves pending migrations keeps the shop closed until cli/migrate.php apply has run.
    file_put_contents($r25rApp . '/migrations/20991231000025_r25_probe.php',
        "<?php return new class { public function up(PDO \$db, bool \$isSqlite): void { \$db->exec('CREATE TABLE r25_probe (id INTEGER PRIMARY KEY)'); } };\n");
    $r25rRun = $r25rPhp([$r25rApp . '/cli/restore.php', $r25rGood, '--yes']);
    $r25rPending = $r25rState();
    $r25rMigrate = $r25rPhp([$r25rApp . '/cli/migrate.php', 'apply']);
    assert_equals([0, true, true, 0, [false, '']],
        [$r25rRun['exit'], $r25rPending[0], str_starts_with($r25rPending[1], 'cli/migrate.php: the database restored from'), $r25rMigrate['exit'], $r25rState()],
        'R25 restore: pending migrations after a restore keep the shop closed; migrate.php apply then opens it (was: opened on the old schema) — '
        . $r25rPending[1] . ' ' . trim(substr($r25rRun['output'] . $r25rMigrate['output'], -200)));
    @unlink($r25rApp . '/migrations/20991231000025_r25_probe.php');

    // 6. Fields, not reason text, tell what lifts a failure; R24's single carried reason still counts.
    assert_equals([
        ['reason' => 'cli/restore.php a.db was interrupted; earlier: update 9 failed; run php cli/update.php rollback --snapshot=S1 --force', 'restore_scope' => 'db'],
        ['reason' => 'update 9 failed; run php cli/update.php rollback --snapshot=S1 --force', 'rollback_snapshot' => 'S1'],
        ['reason' => 'cli/migrate.php failed: x'],
    ], MaintenanceMode::failures(['failed' => true, 'restore_scope' => 'db',
        'reason' => 'cli/restore.php a.db was interrupted; earlier: update 9 failed; run php cli/update.php rollback --snapshot=S1 --force',
        'pending_other' => [['reason' => 'update 9 failed; run php cli/update.php rollback --snapshot=S1 --force', 'rollback_snapshot' => 'S1'], 'cli/migrate.php failed: x']]),
        'R25 maintenance: failures() lists the window\'s own failure and those it carries, classified by their fields');
} catch (Throwable $e) {
    assert_true(false, 'R25 restore (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    @unlink(MaintenanceMode::path($r25rApp));
}

// 7. Updater: an update armed over a failed migration carries it on; a code-only rollback of that update keeps the shop
//    closed for it; and a restore window that quotes "--snapshot=X" is not lifted by the rollback of X.
$r25uRoot = $r25rBase . '/updater';
try {
    foreach (['base/src', 'code/src', 'schema/src', 'schema/migrations', 'installed/src'] as $sub) mkdir($r25uRoot . '/' . $sub, 0700, true);
    foreach (['base' => 'a1', 'installed' => 'a1', 'code' => 'a2', 'schema' => 'a3'] as $tree => $content) {
        file_put_contents("{$r25uRoot}/{$tree}/src/A.php", $content . "\n");
    }
    file_put_contents($r25uRoot . '/schema/migrations/20991231000026_r25.php', "<?php return new class {};\n");
    UpdatePackageBuilder::build($r25uRoot . '/base', $r25uRoot . '/code', $r25uRoot . '/package-code', '25.0.1', '25.0.0');
    UpdatePackageBuilder::build($r25uRoot . '/code', $r25uRoot . '/schema', $r25uRoot . '/package-schema', '25.0.2', '25.0.1');
    $r25uShop = $r25uRoot . '/installed';
    $r25uDb = $r25uRoot . '/live.sqlite';
    (new PDO('sqlite:' . $r25uDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    $r25uArmed = [];
    // A rollback that restored the database lets go of its connection: the next step uses a new updater (as cli/update.php).
    $r25uNew = static function () use (&$r25uArmed, $r25uShop, $r25uRoot, $r25uDb): Updater {
        return new Updater($r25uShop, $r25uRoot . '/private', new PDO('sqlite:' . $r25uDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
            static function () use (&$r25uArmed, $r25uShop): void { $r25uArmed = MaintenanceMode::marker($r25uShop); });
    };
    $r25rUpdater = $r25uNew();
    $r25rUpdater->baseline('25.0.0');
    $r25uState = static fn(): array => [MaintenanceMode::failed($r25uShop), (string)(MaintenanceMode::marker($r25uShop)['reason'] ?? '')];
    $r25uMigrate = 'cli/migrate.php failed: r25 boom — run php cli/migrate.php apply again';

    // 7a. The marker a killed update leaves (seen by its migration runner, right after arm()) carries the migration failure.
    $r25uCode = $r25rUpdater->apply(new UpdatePackage($r25uRoot . '/package-code', null, true));
    MaintenanceMode::fail($r25uShop, $r25uMigrate);
    $r25uSchema = $r25rUpdater->apply(new UpdatePackage($r25uRoot . '/package-schema', null, true));
    assert_equals([(string)$r25uSchema['snapshot'], [['reason' => $r25uMigrate, 'before_snapshots' => [(string)$r25uSchema['snapshot']]]], [false, '']],
        [$r25uArmed['rollback_snapshot'] ?? null, $r25uArmed['pending_other'] ?? null, $r25uState()],
        'R25 updater: an update armed over a failed migration carries it (rollback_snapshot + pending_other); completed with its migrations it lifts both');
    $r25rUpdater->rollback((string)$r25uSchema['snapshot'], true, true);

    // 7b. Killed during an update of code only on top of that failure: the code-only rollback it names keeps the shop closed.
    MaintenanceMode::fail($r25uShop, "update 25.0.1 was interrupted before it finished; run php cli/update.php rollback --snapshot={$r25uCode['snapshot']} --force --accept-data-loss",
        ['rollback_snapshot' => (string)$r25uCode['snapshot'], 'pending_other' => [['reason' => $r25uMigrate]]]);
    $r25uBack = $r25rUpdater->rollback((string)$r25uCode['snapshot'], true, true);
    assert_equals([[true, $r25uMigrate], $r25uMigrate], [$r25uState(), $r25uBack['still_closed'] ?? null],
        'R25 updater: the rollback a killed update names keeps the migration failure it carried (was: shop reopened on a half-migrated schema)');
    MaintenanceMode::leave($r25uShop);

    // 7c. A half-done restore whose reason quotes the rollback of a snapshot is not lifted by that rollback.
    $r25uAgain = $r25rUpdater->apply(new UpdatePackage($r25uRoot . '/package-code', null, true));
    $r25uRestore = "cli/restore.php x.db was interrupted before it finished; check the database and files, then run php cli/restore.php 'x.db' again; earlier: update 25.0.1 failed; run php cli/update.php rollback --snapshot={$r25uAgain['snapshot']} --force";
    MaintenanceMode::fail($r25uShop, $r25uRestore, ['restore_scope' => 'db']);
    $r25rUpdater->rollback((string)$r25uAgain['snapshot'], true, true);
    assert_equals([true, $r25uRestore], $r25uState(),
        'R25 updater: a code-only rollback does not lift a restore window whose reason quotes --snapshot= (was: matched by a regular expression)');
    MaintenanceMode::leave($r25uShop);

    // 7d. Killed during an update WITH migrations armed over a failed migration: its database snapshot was taken after that
    //     failure (half-migrated schema), so the database rollback the marker names keeps the shop closed for it.
    $r25rUpdater = $r25uNew();
    $r25rUpdater->apply(new UpdatePackage($r25uRoot . '/package-code', null, true));
    MaintenanceMode::fail($r25uShop, $r25uMigrate);
    $r25uKilled = $r25rUpdater->apply(new UpdatePackage($r25uRoot . '/package-schema', null, true));
    MaintenanceMode::fail($r25uShop, (string)$r25uArmed['reason'], array_intersect_key($r25uArmed, array_flip(['rollback_snapshot', 'pending_other'])));
    $r25uBack = $r25rUpdater->rollback((string)$r25uKilled['snapshot'], true, true);
    assert_equals([[true, $r25uMigrate], $r25uMigrate], [$r25uState(), $r25uBack['still_closed'] ?? null],
        'R25 updater: the database rollback a killed migration update names keeps a migration failure older than its snapshot (was: shop reopened on the half-migrated schema the snapshot holds)');
    $r25rUpdater = $r25uNew();
    $r25rUpdater->apply(new UpdatePackage($r25uRoot . '/package-schema', null, true));
    assert_equals([false, ''], $r25uState(), 'R25 updater: an update that runs the migrations then lifts it');
    MaintenanceMode::leave($r25uShop);
} catch (Throwable $e) {
    assert_true(false, 'R25 updater (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r25rUpdater = null;
    gc_collect_cycles();
    if (function_exists('updateTestRemoveTree')) updateTestRemoveTree($r25rBase);
}
