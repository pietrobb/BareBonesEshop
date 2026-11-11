<?php

// Review round 29, finding 2: a 2.1.3 rollback restored src/ alphabetically before src/Updater.php. Killed in that
// stretch (64 of 445 operations) the command .maintenance named ran the 2.1.3 updater on the 2.1.2 FileSystem and died
// on FileSystem::tryLock() every time (503 for good); killed a little later, the 2.1.2 updater ran it and reopened the
// shop with 11 files still of 2.1.3. Now .maintenance names a private copy of the updater that started the step, which
// finishes it whatever the shop's src/ holds.
echo "\n--- R29: an interrupted rollback is finished by the updater that started it, in every window ---\n";
$r29rRoot = test_fixture_path('r29-rollback');
$r29rUpdater = null;
$r29rError = static function (callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};
$r29rRun = static function (string $command): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out . $err];
};
try {
    // The shop's src/ holds an updater and the FileSystem it needs; src/Z.php is restored after src/FileSystem.php and
    // before src/Updater.php — the stretch where the 2.1.3 updater stood on the 2.1.2 FileSystem.
    $r29rBase = ['cli/update.php' => 'u1', 'src/FileSystem.php' => 'f1', 'src/Updater.php' => 'p1', 'src/Z.php' => 'z1', 'themes/t.html' => 't1'];
    $r29rNew = ['cli/update.php' => 'u2', 'src/FileSystem.php' => 'f2', 'src/Updater.php' => 'p2', 'src/Z.php' => 'z2', 'themes/t.html' => 't2',
        'src/New.php' => 'n2'];
    foreach (['base' => $r29rBase, 'installed' => $r29rBase, 'v2' => $r29rNew] as $tree => $files) {
        foreach ($files as $path => $body) {
            @mkdir(dirname("{$r29rRoot}/{$tree}/{$path}"), 0700, true);
            file_put_contents("{$r29rRoot}/{$tree}/{$path}", "{$body}\n");
        }
    }
    UpdatePackageBuilder::build($r29rRoot . '/base', $r29rRoot . '/v2', $r29rRoot . '/package', '29.0.1', '29.0.0');
    $r29rShop = $r29rRoot . '/installed';
    $r29rPrivate = $r29rRoot . '/private';
    $r29rDb = $r29rRoot . '/live.sqlite';
    (new PDO('sqlite:' . $r29rDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    file_put_contents($r29rShop . '/config.php', '<?php return ' . var_export([
        'db_driver' => 'sqlite', 'db_sqlite_path' => $r29rDb, 'private_runtime_dir' => $r29rRoot . '/runtime',
        'update_storage_dir' => $r29rPrivate, 'drift_guard' => ['enabled' => false],
    ], true) . ";\n");
    $r29rUpdater = new Updater($r29rShop, $r29rPrivate,
        new PDO('sqlite:' . $r29rDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function (): void {});
    $r29rRead = static fn(string $path): ?string => is_file("{$r29rShop}/{$path}") ? trim((string)file_get_contents("{$r29rShop}/{$path}")) : null;
    $r29rVersion = static fn(): string => (string)(json_decode((string)@file_get_contents($r29rPrivate . '/installed.json'), true)['version'] ?? '');
    $r29rFinishers = static fn(): array => glob($r29rPrivate . '/finishers/*') ?: [];
    $r29rUpdater->baseline('29.0.0');

    // A completed update keeps no copy of the updater.
    $r29rSnap = (string)$r29rUpdater->apply(new UpdatePackage($r29rRoot . '/package', null, true))['snapshot'];
    assert_equals([[], '29.0.1'], [$r29rFinishers(), $r29rVersion()], 'R29 rollback: a completed update leaves no copy of the updater behind');

    // Stopped in src/ between FileSystem.php and Updater.php: the shop's FileSystem is the old one, its updater the new.
    unlink($r29rShop . '/src/Z.php');
    mkdir($r29rShop . '/src/Z.php/x', 0700, true);
    $r29rHalf = $r29rError(static fn() => $r29rUpdater->rollback($r29rSnap, true, true));
    rmdir($r29rShop . '/src/Z.php/x');
    rmdir($r29rShop . '/src/Z.php');
    $r29rMarker = (string)@file_get_contents(MaintenanceMode::path($r29rShop));
    assert_equals([true, 'f1', 'p2', true], [$r29rHalf !== '', $r29rRead('src/FileSystem.php'), $r29rRead('src/Updater.php'), MaintenanceMode::failed($r29rShop)],
        'R29 rollback: stopped between src/FileSystem.php and src/Updater.php (the window that failed for good on FileSystem::tryLock())');
    $r29rCommand = preg_match('/run (php \S+ rollback --installed=\S+ --snapshot=' . preg_quote($r29rSnap, '/') . ' --force(?: --accept-data-loss)?)/', $r29rMarker, $m) ? $m[1] : '';
    assert_true($r29rCommand !== '' && str_contains($r29rCommand, $r29rPrivate . '/finishers/') && count($r29rFinishers()) === 1,
        'R29 rollback: .maintenance names the private copy of the updater that started the rollback, for the shop (' . $r29rMarker . ')');
    // The shop's own updater files are nothing the command needs: it runs whatever src/ holds now.
    file_put_contents($r29rShop . '/src/Updater.php', "<?php throw new Error('shop updater ran');\n");
    if (PHP_OS_FAMILY === 'Windows') $r29rCommand = str_replace("'", '"', $r29rCommand);
    [$r29rExit, $r29rOut] = $r29rRun(escapeshellarg(PHP_BINARY) . substr($r29rCommand, 3));
    $r29rUpdater = null;
    gc_collect_cycles();
    assert_true($r29rExit === 0 && str_contains($r29rOut, 'Rollback complete'), 'R29 rollback: the named command finishes it (' . trim($r29rOut) . ')');
    assert_equals([null, 'f1', 'p1', 'z1', 'u1', 't1', false, '29.0.0', []],
        [$r29rRead('src/New.php'), $r29rRead('src/FileSystem.php'), $r29rRead('src/Updater.php'), $r29rRead('src/Z.php'), $r29rRead('cli/update.php'),
            $r29rRead('themes/t.html'), is_file(MaintenanceMode::path($r29rShop)), $r29rVersion(), $r29rFinishers()],
        'R29 rollback: every file is back, the shop reopens only then, and the copy of the updater is gone');
} catch (Throwable $e) {
    assert_true(false, 'R29 rollback (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r29rUpdater = null;
    gc_collect_cycles();
}
