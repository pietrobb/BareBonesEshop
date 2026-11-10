<?php

// Review round 28, finding 3 and lows:
// - only the updater's own files (UPDATER_FILES) waited for the end of a rollback, not what they load: a 2.1.3 rollback
//   removed AppConfig.php and FileOwnership.php (new in 2.1.3) first, and stopped half-way, the command .maintenance
//   names failed with 'Class "AppConfig" not found' (so did every update.php); the shop stayed on 503;
// - the version state was written before the updater's files: killed in between, the state said the old version over
//   the new updater, and the next apply of the same package mixed the releases;
// - without file locks in the private directory every step failed as if another one ran;
// - a probe database left by a killed MySQL update or rollback was refused without saying to drop it.
echo "\n--- R28: an interrupted rollback can be finished; no file locks and leftover probe said plainly ---\n";
$r28rRoot = test_fixture_path('r28-rollback');
$r28rUpdater = null;
$r28rError = static function (callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};
try {
    // lang/a.json is restored before themes/t.html: the rollback has changed a file when it stops (window failed).
    $r28rBase = ['cli/update.php' => 'u1', 'src/Updater.php' => 'p1', 'src/A.php' => 'a1', 'lang/a.json' => 'l1', 'themes/t.html' => 't1'];
    $r28rNew = ['cli/update.php' => 'u2', 'src/Updater.php' => 'p2', 'src/A.php' => 'a2', 'lang/a.json' => 'l2', 'themes/t.html' => 't2',
        'src/Dep.php' => 'd2', 'themes/new.html' => 'n2'];
    foreach (['base' => $r28rBase, 'installed' => $r28rBase, 'v2' => $r28rNew] as $tree => $files) {
        foreach ($files as $path => $body) {
            @mkdir(dirname("{$r28rRoot}/{$tree}/{$path}"), 0700, true);
            file_put_contents("{$r28rRoot}/{$tree}/{$path}", "{$body}\n");
        }
    }
    UpdatePackageBuilder::build($r28rRoot . '/base', $r28rRoot . '/v2', $r28rRoot . '/package', '28.0.1', '28.0.0');
    $r28rShop = $r28rRoot . '/installed';
    $r28rDb = $r28rRoot . '/live.sqlite';
    (new PDO('sqlite:' . $r28rDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    $r28rUpdater = new Updater($r28rShop, $r28rRoot . '/private',
        new PDO('sqlite:' . $r28rDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function (): void {});
    $r28rRead = static fn(string $path): ?string => is_file("{$r28rShop}/{$path}") ? trim((string)file_get_contents("{$r28rShop}/{$path}")) : null;
    $r28rVersion = static fn(): string => (string)(json_decode((string)@file_get_contents($r28rRoot . '/private/installed.json'), true)['version'] ?? '');
    $r28rUpdater->baseline('28.0.0');

    // 1. Stopped at a file of the shop (themes/t.html cannot be written): nothing the updater loads is gone yet.
    $r28rSnap = (string)$r28rUpdater->apply(new UpdatePackage($r28rRoot . '/package', null, true))['snapshot'];
    unlink($r28rShop . '/themes/t.html');
    mkdir($r28rShop . '/themes/t.html/x', 0700, true);
    $r28rHalf = $r28rError(static fn() => $r28rUpdater->rollback($r28rSnap, true, true));
    assert_equals([true, 'd2', 'a2', 'u2', 'p2', true, '28.0.1'],
        [$r28rHalf !== '', $r28rRead('src/Dep.php'), $r28rRead('src/A.php'), $r28rRead('cli/update.php'), $r28rRead('src/Updater.php'),
            MaintenanceMode::failed($r28rShop), $r28rVersion()],
        'R28 rollback: stopped half-way, src/ (a class only the new release has included) is still there for the updater that'
        . ' finishes it (was: removed first, "Class not found" for every update.php)');
    rmdir($r28rShop . '/themes/t.html/x');
    rmdir($r28rShop . '/themes/t.html');
    $r28rUpdater->rollback($r28rSnap, true, true);
    assert_equals([null, null, 'a1', 'u1', 'p1', 't1', false, '28.0.0'],
        [$r28rRead('src/Dep.php'), $r28rRead('themes/new.html'), $r28rRead('src/A.php'), $r28rRead('cli/update.php'),
            $r28rRead('src/Updater.php'), $r28rRead('themes/t.html'), is_file(MaintenanceMode::path($r28rShop)), $r28rVersion()],
        'R28 rollback: the command it named finishes it; files of the new release are removed, the shop reopens');

    // 2. Stopped at the version state, written last: the files are back but the state still names the new release.
    $r28rSnap = (string)$r28rUpdater->apply(new UpdatePackage($r28rRoot . '/package', null, true))['snapshot'];
    $r28rState = $r28rRoot . '/private/installed.json';
    $r28rStateJson = (string)file_get_contents($r28rState);
    unlink($r28rState);
    mkdir($r28rState . '/x', 0700, true);
    $r28rAtState = $r28rError(static fn() => $r28rUpdater->rollback($r28rSnap, true, true));
    rmdir($r28rState . '/x');
    rmdir($r28rState);
    file_put_contents($r28rState, $r28rStateJson);
    assert_equals([true, 'u1', null, '28.0.1', true],
        [$r28rAtState !== '', $r28rRead('cli/update.php'), $r28rRead('src/Dep.php'), $r28rVersion(), MaintenanceMode::failed($r28rShop)],
        'R28 rollback: the version state is written after every file (was: before the updater, a kill in between said 28.0.0 over 28.0.1)');
    $r28rUpdater->rollback($r28rSnap, true, true);
    assert_equals(['28.0.0', false], [$r28rVersion(), is_file(MaintenanceMode::path($r28rShop))], 'R28 rollback: re-run, the state follows');
} catch (Throwable $e) {
    assert_true(false, 'R28 rollback (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r28rUpdater = null;
    gc_collect_cycles();
}

// 3. No file locks: a step says so instead of "another one runs".
$r28rLocks = method_exists(FileSystem::class, 'lockRefused');
assert_true($r28rLocks && FileSystem::lockRefused('/x/.update.lock', 1) === false, 'R28 locks: a lock another process holds is reported as busy');
if (PHP_OS_FAMILY !== 'Windows') {
    $r28rNoLock = $r28rLocks ? $r28rError(static fn() => FileSystem::lockRefused('/x/.update.lock', 0)) : '';
    assert_true(str_contains($r28rNoLock, '/x/.update.lock') && str_contains($r28rNoLock, 'no file locks'),
        'R28 locks: flock() failing without another holder names the missing file locks (was: "Another update process is already running")');
}
$r28rSrc = static fn(string $file): string => (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/' . $file);
assert_true(str_contains($r28rSrc('Updater.php'), 'FileSystem::tryLock($this->lockHandle')
    && str_contains($r28rSrc('BackupService.php'), 'FileSystem::tryLock($h, $lockPath)')
    && str_contains($r28rSrc('Migrator.php'), 'FileSystem::noFileLocks($this->lockFile)')
    && substr_count($r28rSrc('SqliteMaintenance.php'), 'self::noFileLocks($marker)') === 2,
    'R28 locks: the update, backup, migration and SQLite maintenance locks tell missing file locks from a holder');

// 4. A probe database left by a killed MySQL update: the refusal says to drop it.
assert_true(str_contains($r28rSrc('MysqlUpdateDatabaseBackup.php'), 'drop it (DROP DATABASE `{$this->probeDatabase}`) and run the command again'),
    'R28 MySQL: a leftover probe database is refused with the DROP DATABASE to run');
