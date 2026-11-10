<?php

// Review round 27 (2026-11-10, "kolo 11"), goal #398 (low): R26 recorded with an update's snapshot only a migration stopped
// half-way. A snapshot taken over a restore or a rollback left half-done holds that state too: once a step completed it
// and lifted the window, the rollback of that snapshot put the half-done state back and reopened the shop over it. A
// manual backup taken during a failed window recorded nothing at all.
echo "\n--- R27: a rollback puts back the half-done restore or rollback its snapshot was taken over ---\n";
$r27sRoot = test_fixture_path('r27-snapshots');
@mkdir($r27sRoot, 0700, true);
$r27sUpdater = null;
try {
    foreach (['base/src', 'code/src', 'schema/src', 'schema/migrations', 'installed/src'] as $sub) mkdir($r27sRoot . '/' . $sub, 0700, true);
    foreach (['base' => 'b1', 'installed' => 'b1', 'code' => 'b2', 'schema' => 'b3'] as $tree => $content) {
        file_put_contents("{$r27sRoot}/{$tree}/src/B.php", $content . "\n");
    }
    file_put_contents($r27sRoot . '/schema/migrations/20991231000028_r27.php', "<?php return new class {};\n");
    UpdatePackageBuilder::build($r27sRoot . '/base', $r27sRoot . '/code', $r27sRoot . '/package-code', '27.0.1', '27.0.0');
    UpdatePackageBuilder::build($r27sRoot . '/code', $r27sRoot . '/schema', $r27sRoot . '/package-schema', '27.0.2', '27.0.1');
    $r27sShop = $r27sRoot . '/installed';
    $r27sDb = $r27sRoot . '/live.sqlite';
    (new PDO('sqlite:' . $r27sDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    $r27sNew = static fn(): Updater => new Updater($r27sShop, $r27sRoot . '/private',
        new PDO('sqlite:' . $r27sDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function (): void {});
    $r27sState = static fn(): array => [MaintenanceMode::failed($r27sShop), (string)(MaintenanceMode::marker($r27sShop)['reason'] ?? '')];
    $r27sScopes = static fn(): array => array_map(static fn(array $f): array => array_diff_key($f, ['reason' => 1]),
        MaintenanceMode::failures(MaintenanceMode::marker($r27sShop)));
    $r27sSchema = new UpdatePackage($r27sRoot . '/package-schema', null, true);
    $r27sUpdater = $r27sNew();
    $r27sUpdater->baseline('27.0.0');
    $r27sUpdater->apply(new UpdatePackage($r27sRoot . '/package-code', null, true));

    // 1. A restore of the database left half-done: the update keeps the shop closed for it, its snapshot holds that state.
    $r27sRestore = 'cli/restore.php failed half-way (r27 boom); run php cli/restore.php again';
    MaintenanceMode::fail($r27sShop, $r27sRestore, ['restore_scope' => 'db']);
    $r27sOverRestore = $r27sUpdater->apply($r27sSchema);
    $r27sKept = $r27sState();
    MaintenanceMode::leave($r27sShop); // the restore run again completed it
    $r27sUpdater = $r27sNew();
    $r27sBack = $r27sUpdater->rollback((string)$r27sOverRestore['snapshot'], true, true);
    assert_equals([[true, $r27sRestore], [true, $r27sRestore], $r27sRestore, [['restore_scope' => 'db']]],
        [$r27sKept, $r27sState(), $r27sBack['still_closed'] ?? null, $r27sScopes()],
        'R27 updater: the rollback of a database snapshot taken over a restore left half-done keeps the shop closed for that restore (was: reopened over the half-restored database)');

    // 2. A rollback left half-done: that rollback is still the one that completes it.
    MaintenanceMode::leave($r27sShop);
    $r27sOther = '20990101000000-abcdefabcdef';
    $r27sRollback = "rollback {$r27sOther} failed half-way (r27 boom); fix the cause and run php cli/update.php rollback --snapshot={$r27sOther} --force";
    MaintenanceMode::fail($r27sShop, $r27sRollback, ['rollback_snapshot' => $r27sOther]);
    $r27sUpdater = $r27sNew();
    $r27sOverRollback = $r27sUpdater->apply($r27sSchema);
    MaintenanceMode::leave($r27sShop); // the rollback of $r27sOther run again completed it
    $r27sUpdater = $r27sNew();
    $r27sBack2 = $r27sUpdater->rollback((string)$r27sOverRollback['snapshot'], true, true);
    assert_equals([[true, $r27sRollback], $r27sRollback, [['rollback_snapshot' => $r27sOther]]],
        [$r27sState(), $r27sBack2['still_closed'] ?? null, $r27sScopes()],
        'R27 updater: the rollback of a snapshot taken over a rollback left half-done keeps the shop closed until that rollback runs again (was: reopened)');

    // 3. A restore of uploads only: the snapshot holds no uploads, its rollback does not put that one back.
    MaintenanceMode::leave($r27sShop);
    MaintenanceMode::fail($r27sShop, $r27sRestore, ['restore_scope' => 'files']);
    $r27sUpdater = $r27sNew();
    $r27sOverFiles = $r27sUpdater->apply($r27sSchema);
    MaintenanceMode::leave($r27sShop);
    $r27sUpdater = $r27sNew();
    $r27sBack3 = $r27sUpdater->rollback((string)$r27sOverFiles['snapshot'], true, true);
    assert_equals([[false, ''], null], [$r27sState(), $r27sBack3['still_closed'] ?? null],
        'R27 updater: the rollback of a snapshot taken over a restore of uploads only opens the shop (it holds no uploads)');

    // 4. A manual backup taken during a failed window records the failure like an update's snapshot.
    $r27sMigrate = 'cli/migrate.php failed: r27 boom — run php cli/migrate.php apply again';
    MaintenanceMode::fail($r27sShop, $r27sMigrate);
    $r27sUpdater = $r27sNew();
    $r27sBackup = $r27sUpdater->backup($r27sSchema);
    MaintenanceMode::leave($r27sShop); // a migration run again completed it
    $r27sUpdater = $r27sNew();
    $r27sBack4 = $r27sUpdater->rollback((string)$r27sBackup['id'], true, true);
    assert_equals([[true, $r27sMigrate], $r27sMigrate], [$r27sState(), $r27sBack4['still_closed'] ?? null],
        'R27 updater: the rollback of a manual backup taken during a failed migration keeps the shop closed for it (was: reopened over the half-migrated database)');
} catch (Throwable $e) {
    assert_true(false, 'R27 snapshots (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r27sUpdater = null;
    gc_collect_cycles();
    if (isset($r27sShop)) @unlink(MaintenanceMode::path($r27sShop));
}
