<?php

// Review round 21 (2026-11-07), low "updater and snapshots":
// - a rollback of code only after a *failed update or rollback* (not a migration) reopened the shop although
//   .maintenance named another snapshot whose rollback was still due (files and database half-restored);
// - update_snapshots_keep counted the snapshots of updates of code only (no database) with the others: three such
//   updates deleted the only snapshot with a database;
// - README.md and the MaintenanceMode comment said any successful update or rollback lifts a failed window.
echo "\n--- R21: a half-done rollback's window is lifted only by the rollback it names; snapshots with a database are kept ---\n";
$r21uRoot = test_fixture_path('r21-updater');
$r21uUpdater = null;
try {
    foreach (['base/src', 'code/src', 'installed/src'] as $sub) mkdir($r21uRoot . '/' . $sub, 0700, true);
    foreach (['base' => 'a1', 'installed' => 'a1', 'code' => 'a2'] as $tree => $content) file_put_contents("{$r21uRoot}/{$tree}/src/A.php", $content . "\n");
    UpdatePackageBuilder::build($r21uRoot . '/base', $r21uRoot . '/code', $r21uRoot . '/package-code', '21.0.1', '21.0.0');
    $r21uShop = $r21uRoot . '/installed';
    $r21uDbPath = $r21uRoot . '/live.sqlite';
    (new PDO('sqlite:' . $r21uDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    $r21uUpdater = new Updater($r21uShop, $r21uRoot . '/private',
        new PDO('sqlite:' . $r21uDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function (): void {});
    $r21uState = static fn(): array => [MaintenanceMode::active($r21uShop), MaintenanceMode::failed($r21uShop),
        (string)(MaintenanceMode::marker($r21uShop)['reason'] ?? '')];
    $r21uUpdater->baseline('21.0.0');
    $r21uPackage = static fn(): UpdatePackage => new UpdatePackage($r21uRoot . '/package-code', null, true);

    $r21uFirst = (string)$r21uUpdater->apply($r21uPackage())['snapshot'];
    $r21uUpdater->rollback($r21uFirst, false, true);
    $r21uSecond = (string)$r21uUpdater->apply($r21uPackage())['snapshot'];
    // The rollback of the first snapshot stopped half-way: .maintenance names it.
    $r21uHalf = "rollback {$r21uFirst} failed half-way (boom); fix the cause and run php cli/update.php rollback --snapshot={$r21uFirst} --force";
    MaintenanceMode::fail($r21uShop, $r21uHalf);
    $r21uOther = $r21uUpdater->rollback($r21uSecond, true, true);
    assert_equals([[true, true, $r21uHalf], $r21uHalf], [$r21uState(), $r21uOther['still_closed'] ?? null],
        'R21 rollback: a completed code-only rollback of another snapshot keeps the half-done rollback\'s window and says so (was: shop reopened)');
    // An update of code only does not lift it either.
    $r21uUpdate = $r21uUpdater->apply($r21uPackage());
    assert_equals([[true, true, $r21uHalf], $r21uHalf], [$r21uState(), $r21uUpdate['still_closed'] ?? null],
        'R21 update: an update keeps the window of a half-done rollback of a snapshot it does not restore');
    // R22: the named rollback waits until that later update is rolled back (newest first, no mix of releases); that
    // rollback keeps the window, and the rollback the marker names then lifts it.
    $r21uLater = $r21uUpdater->rollback((string)$r21uUpdate['snapshot'], true, true);
    assert_equals([[true, true, $r21uHalf], $r21uHalf], [$r21uState(), $r21uLater['still_closed'] ?? null],
        'R21 rollback: rolling back the later update keeps the half-done rollback\'s window');
    $r21uNamed = $r21uUpdater->rollback($r21uFirst, true, true);
    assert_equals([false, null], [is_file(MaintenanceMode::path($r21uShop)), $r21uNamed['still_closed'] ?? null],
        'R21 rollback: the rollback .maintenance names lifts its window');
} catch (Throwable $e) {
    assert_true(false, 'R21 updater window (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r21uUpdater = null;
    gc_collect_cycles();
}

// Retention: one old snapshot with a database, then three updates of code only (rolled back or not).
try {
    $r21uState2 = test_fixture_path('r21-updater-keep') . '/updates';
    mkdir(dirname($r21uState2) . '/app', 0700, true);
    $r21uSnap = static function (int $i, bool $database, string $status) use ($r21uState2): string {
        $id = sprintf('2026110700000%d-%012x', $i, 0x210 + $i);
        mkdir($r21uState2 . '/snapshots/' . $id, 0700, true);
        file_put_contents($r21uState2 . '/snapshots/' . $id . '/snapshot.json', json_encode(['id' => $id, 'reason' => 'pre-update', 'status' => $status,
            'created_at' => "2026-11-07T00:00:0{$i}+00:00", 'database' => $database ? ['driver' => 'sqlite', 'path' => 'database.sqlite'] : null]));
        return $id;
    };
    $r21uIds = [1 => $r21uSnap(1, true, 'applied'), 2 => $r21uSnap(2, false, 'rolled-back'), 3 => $r21uSnap(3, false, 'rolled-back'),
        4 => $r21uSnap(4, false, 'rolled-back'), 5 => $r21uSnap(5, false, 'applied')];
    $r21uKeep = new Updater(dirname($r21uState2) . '/app', $r21uState2);
    assert_equals([$r21uIds[2]], $r21uKeep->pruneSnapshots(3),
        'R21 snapshots: update_snapshots_keep=3 keeps the only snapshot with a database after four code-only updates (it was deleted)');
    foreach ([6, 7, 8] as $i) $r21uIds[$i] = $r21uSnap($i, true, 'applied');
    $r21uSecondPrune = $r21uKeep->pruneSnapshots(3);
    assert_equals([$r21uIds[1]], $r21uSecondPrune, 'R21 snapshots: with four database snapshots the oldest of them goes, the code-only ones count apart');
} catch (Throwable $e) {
    assert_true(false, 'R21 updater retention (' . get_class($e) . ': ' . $e->getMessage() . ')');
}

$r21uReadme = preg_replace('/\s+/', ' ', (string)file_get_contents(dirname(TEST_CLI_DIR) . '/README.md'));
$r21uMaint = preg_replace('/\s+/', ' ', (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/MaintenanceMode.php'));
assert_true(str_contains($r21uReadme, 'A failed update or rollback is lifted only by the rollback the file names')
    && str_contains($r21uReadme, 'it keeps blocking for an hour') && !str_contains($r21uMaint, 'the next successful `php cli/migrate.php apply`, update or rollback lifts it')
    && str_contains($r21uMaint, 'only by the rollback its reason names'),
    'R21 docs: README and MaintenanceMode say which step lifts which failed window and how long a killed updater\'s marker blocks');
