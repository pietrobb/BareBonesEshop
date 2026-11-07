<?php

// Review round 22 (2026-11-07), low "updater" (goal #359), all reproduced by the reviewer:
// - a rollback stopped half-way had already put back the old release's updater files; the next rollback ran that
//   updater, which opened the half-restored shop. The updater's own files are restored last now;
// - an update during a failed update's window could prune the snapshot .maintenance names: the shop stayed closed;
// - a rollback of an older snapshot while a later update was still installed (or half-applied) left that update's
//   other files in place, and the only command the window allowed then mixed releases again. It is refused.
echo "\n--- R22: updater files restored last, the snapshot .maintenance names is kept, rollbacks newest first ---\n";
$r22uRoot = test_fixture_path('r22-updater');
$r22uUpdater = null;
$r22uError = static function (callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};
try {
    $r22uFiles = ['cli/update.php' => 'u', 'src/Updater.php' => 'p', 'src/A.php' => 'a', 'src/B.php' => 'b'];
    foreach (['base' => 1, 'installed' => 1, 'v2' => 2, 'v3' => 2] as $tree => $n) {
        foreach ($r22uFiles as $path => $letter) {
            @mkdir(dirname("{$r22uRoot}/{$tree}/{$path}"), 0700, true);
            file_put_contents("{$r22uRoot}/{$tree}/{$path}", "{$letter}{$n}\n");
        }
    }
    file_put_contents("{$r22uRoot}/v3/src/A.php", "a3\n");
    UpdatePackageBuilder::build($r22uRoot . '/base', $r22uRoot . '/v2', $r22uRoot . '/package-2', '22.0.1', '22.0.0');
    UpdatePackageBuilder::build($r22uRoot . '/v2', $r22uRoot . '/v3', $r22uRoot . '/package-3', '22.0.2', '22.0.1');
    $r22uShop = $r22uRoot . '/installed';
    $r22uDbPath = $r22uRoot . '/live.sqlite';
    (new PDO('sqlite:' . $r22uDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    $r22uUpdater = new Updater($r22uShop, $r22uRoot . '/private',
        new PDO('sqlite:' . $r22uDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function (): void {});
    $r22uRead = static fn(string $path): string => trim((string)@file_get_contents($r22uShop . '/' . $path));
    $r22uTree = static fn(): array => array_map($r22uRead, array_combine(array_keys($r22uFiles), array_keys($r22uFiles)));
    $r22uUpdater->baseline('22.0.0');

    // 1. A rollback that stops half-way (src/B.php cannot be written) has restored the other files, not the updater.
    $r22uFirst = (string)$r22uUpdater->apply(new UpdatePackage($r22uRoot . '/package-2', null, true))['snapshot'];
    unlink($r22uShop . '/src/B.php');
    mkdir($r22uShop . '/src/B.php/x', 0700, true);
    $r22uHalf = $r22uError(static fn() => $r22uUpdater->rollback($r22uFirst, true, true));
    assert_equals([true, 'a1', 'u2', 'p2', true], [$r22uHalf !== '', $r22uRead('src/A.php'), $r22uRead('cli/update.php'),
        $r22uRead('src/Updater.php'), MaintenanceMode::failed($r22uShop)],
        'R22 rollback: stopped half-way, it has restored the shop\'s files but not yet the updater (was: cli/update.php and Updater.php first)');
    rmdir($r22uShop . '/src/B.php/x');
    rmdir($r22uShop . '/src/B.php');
    $r22uUpdater->rollback($r22uFirst, true, true);
    assert_equals([['cli/update.php' => 'u1', 'src/Updater.php' => 'p1', 'src/A.php' => 'a1', 'src/B.php' => 'b1'], false],
        [$r22uTree(), is_file(MaintenanceMode::path($r22uShop))], 'R22 rollback: completed, every file is back and the shop reopens');

    // 2. Two updates in a row (the same second): the older one is not rolled back while the later one is installed.
    $r22uS2 = (string)$r22uUpdater->apply(new UpdatePackage($r22uRoot . '/package-2', null, true))['snapshot'];
    $r22uS3 = (string)$r22uUpdater->apply(new UpdatePackage($r22uRoot . '/package-3', null, true))['snapshot'];
    $r22uBefore = $r22uTree();
    $r22uOlder = $r22uError(static fn() => $r22uUpdater->rollback($r22uS2, true, true));
    assert_equals([true, true, $r22uBefore, false], [str_contains($r22uOlder, 'Rollback refused'), str_contains($r22uOlder, "--snapshot={$r22uS3}"),
        $r22uTree(), is_file(MaintenanceMode::path($r22uShop))],
        'R22 rollback: an older snapshot under a later installed update is refused, names the rollback to run first and changes nothing (was: a mix of releases)');
    // A failed later update (its automatic rollback failed too, so its snapshot is still "prepared") blocks it as well.
    $r22uMeta = $r22uRoot . '/private/snapshots/' . $r22uS3 . '/snapshot.json';
    $r22uJson = json_decode((string)file_get_contents($r22uMeta), true);
    file_put_contents($r22uMeta, json_encode(['status' => 'prepared'] + $r22uJson));
    $r22uHalfUpdate = "update 22.0.2 failed and its automatic rollback failed; run php cli/update.php rollback --snapshot={$r22uS3} --force --accept-data-loss";
    MaintenanceMode::fail($r22uShop, $r22uHalfUpdate);
    $r22uOlder = $r22uError(static fn() => $r22uUpdater->rollback($r22uS2, true, true));
    assert_equals([true, true, $r22uHalfUpdate], [str_contains($r22uOlder, 'Rollback refused'), MaintenanceMode::failed($r22uShop),
        (string)(MaintenanceMode::marker($r22uShop)['reason'] ?? '')],
        'R22 rollback: further back than a half-applied update is refused and the window it names stays');
    file_put_contents($r22uMeta, json_encode($r22uJson));
    $r22uUpdater->rollback($r22uS3, true, true);
    assert_equals([['cli/update.php' => 'u2', 'src/Updater.php' => 'p2', 'src/A.php' => 'a2', 'src/B.php' => 'b2'], false],
        [$r22uTree(), is_file(MaintenanceMode::path($r22uShop))], 'R22 rollback: the named rollback of the later update completes and lifts the window');
    $r22uUpdater->rollback($r22uS2, true, true);
    assert_equals(['cli/update.php' => 'u1', 'src/Updater.php' => 'p1', 'src/A.php' => 'a1', 'src/B.php' => 'b1'], $r22uTree(),
        'R22 rollback: then the older one goes back cleanly');
} catch (Throwable $e) {
    assert_true(false, 'R22 updater (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r22uUpdater = null;
    gc_collect_cycles();
}

// 3. Retention: update_snapshots_keep does not delete the snapshot whose rollback a failed window names.
try {
    $r22uState = test_fixture_path('r22-updater-keep') . '/updates';
    $r22uApp = dirname($r22uState) . '/app';
    mkdir($r22uApp, 0700, true);
    $r22uIds = [];
    foreach ([1, 2, 3] as $i) {
        $id = sprintf('2026110700000%d-%012x', $i, 0x220 + $i);
        mkdir($r22uState . '/snapshots/' . $id, 0700, true);
        file_put_contents($r22uState . '/snapshots/' . $id . '/snapshot.json', json_encode(['id' => $id, 'reason' => 'pre-update',
            'status' => $i === 1 ? 'prepared' : 'applied', 'created_at' => "2026-11-07T00:00:0{$i}+00:00", 'database' => ['driver' => 'sqlite']]));
        $r22uIds[$i] = $id;
    }
    MaintenanceMode::fail($r22uApp, "update 22.0.1 failed and its automatic rollback failed; run php cli/update.php rollback --snapshot={$r22uIds[1]} --force --accept-data-loss");
    $r22uPruned = (new Updater($r22uApp, $r22uState))->pruneSnapshots(1);
    assert_equals([[$r22uIds[2]], true], [$r22uPruned, is_dir($r22uState . '/snapshots/' . $r22uIds[1])],
        'R22 snapshots: update_snapshots_keep keeps the snapshot .maintenance names for its rollback (was: deleted, shop closed for good)');
} catch (Throwable $e) {
    assert_true(false, 'R22 updater retention (' . get_class($e) . ': ' . $e->getMessage() . ')');
}
