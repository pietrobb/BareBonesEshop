<?php

// Review round 30, a low: a step killed after making its private copy of the updater (finishers/) but before
// .maintenance named it, or after it completed but before it dropped the copy, left the copy for good: pruning removed
// only copies of deleted snapshots and day-old half-made ones. Now pruning keeps only the copies .maintenance names.
echo "\n--- R30: copies of the updater a killed step left behind are pruned ---\n";
$r30fRoot = test_fixture_path('r30-finishers');
$r30fUpdater = null;
try {
    $r30fBase = ['cli/update.php' => 'u1', 'src/FileSystem.php' => 'f1', 'src/Updater.php' => 'p1', 'src/Z.php' => 'z1'];
    $r30fNew = ['cli/update.php' => 'u2', 'src/FileSystem.php' => 'f2', 'src/Updater.php' => 'p2', 'src/Z.php' => 'z2'];
    foreach (['base' => $r30fBase, 'installed' => $r30fBase, 'v2' => $r30fNew] as $tree => $files) {
        foreach ($files as $path => $body) {
            @mkdir(dirname("{$r30fRoot}/{$tree}/{$path}"), 0700, true);
            file_put_contents("{$r30fRoot}/{$tree}/{$path}", "{$body}\n");
        }
    }
    UpdatePackageBuilder::build($r30fRoot . '/base', $r30fRoot . '/v2', $r30fRoot . '/package', '30.0.1', '30.0.0');
    $r30fShop = $r30fRoot . '/installed';
    $r30fPrivate = $r30fRoot . '/private';
    $r30fDb = $r30fRoot . '/live.sqlite';
    (new PDO('sqlite:' . $r30fDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    file_put_contents($r30fShop . '/config.php', '<?php return ' . var_export([
        'db_driver' => 'sqlite', 'db_sqlite_path' => $r30fDb, 'private_runtime_dir' => $r30fRoot . '/runtime',
        'update_storage_dir' => $r30fPrivate, 'drift_guard' => ['enabled' => false],
    ], true) . ";\n");
    $r30fUpdater = new Updater($r30fShop, $r30fPrivate,
        new PDO('sqlite:' . $r30fDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function (): void {});
    $r30fCopies = static fn(): array => array_map('basename', glob($r30fPrivate . '/finishers/*') ?: []);
    $r30fUpdater->baseline('30.0.0');
    $r30fSnap = (string)$r30fUpdater->apply(new UpdatePackage($r30fRoot . '/package', null, true))['snapshot'];

    // What killed steps leave: a finished copy .maintenance never named (killed before arm, or after the step completed)
    // and a half-made one of a snapshot that still exists.
    foreach (["{$r30fSnap}-0badc0de", "{$r30fSnap}-0badf00d.partial"] as $r30fLeft) {
        mkdir("{$r30fPrivate}/finishers/{$r30fLeft}/src", 0700, true);
        file_put_contents("{$r30fPrivate}/finishers/{$r30fLeft}/src/Updater.php", "<?php\n");
    }
    // A half-done rollback: .maintenance names its own copy, which must stay.
    unlink($r30fShop . '/src/Z.php');
    mkdir($r30fShop . '/src/Z.php/x', 0700, true);
    try { $r30fUpdater->rollback($r30fSnap, true, true); } catch (Throwable) {}
    rmdir($r30fShop . '/src/Z.php/x');
    rmdir($r30fShop . '/src/Z.php');
    file_put_contents($r30fShop . '/src/Z.php', "z2\n");
    $r30fMarker = (string)@file_get_contents(MaintenanceMode::path($r30fShop));
    $r30fNamed = array_values(array_filter($r30fCopies(), static fn(string $c): bool => !str_contains($c, '0bad') && str_contains($r30fMarker, $c)));
    assert_true(count($r30fNamed) === 1, 'R30 finishers: the half-done rollback\'s copy is named in .maintenance (' . json_encode($r30fCopies()) . ')');

    $r30fUpdater->pruneSnapshots(3);
    assert_equals($r30fNamed, $r30fCopies(),
        'R30 finishers: pruning removes the copies killed steps left (never named, half-made) and keeps the one .maintenance names');
} catch (Throwable $e) {
    assert_true(false, 'R30 finishers (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r30fUpdater = null;
    gc_collect_cycles();
}
