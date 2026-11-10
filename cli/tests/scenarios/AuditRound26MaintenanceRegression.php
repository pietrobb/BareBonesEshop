<?php

// Review round 26 (2026-11-09, "kolo 10"), goal #391:
// - finding 5: an update that completed a migration stopped half-way lifted the failed window, but its database snapshot
//   was taken over that half-migrated schema; a later `rollback --snapshot` of it restored that database and reopened the
//   shop, the failure no longer in the window;
// - a restore killed between moving the live SQLite file aside and moving the staged copy in left no live file: the gate
//   (SqliteMaintenance::isActive()) stayed active for good, every restore failed, only a manual mv helped.
echo "\n--- R26: a database rollback puts back the migration failure its snapshot holds; a killed SQLite swap recovers ---\n";
$r26mBase = test_fixture_path('r26-maintenance');
@mkdir($r26mBase, 0700, true);
$r26mUpdater = null;

// 1. Finding 5.
$r26mRoot = $r26mBase . '/updater';
try {
    foreach (['base/src', 'code/src', 'schema/src', 'schema/migrations', 'installed/src'] as $sub) mkdir($r26mRoot . '/' . $sub, 0700, true);
    foreach (['base' => 'b1', 'installed' => 'b1', 'code' => 'b2', 'schema' => 'b3'] as $tree => $content) {
        file_put_contents("{$r26mRoot}/{$tree}/src/B.php", $content . "\n");
    }
    file_put_contents($r26mRoot . '/schema/migrations/20991231000027_r26.php', "<?php return new class {};\n");
    UpdatePackageBuilder::build($r26mRoot . '/base', $r26mRoot . '/code', $r26mRoot . '/package-code', '26.0.1', '26.0.0');
    UpdatePackageBuilder::build($r26mRoot . '/code', $r26mRoot . '/schema', $r26mRoot . '/package-schema', '26.0.2', '26.0.1');
    $r26mShop = $r26mRoot . '/installed';
    $r26mDb = $r26mRoot . '/live.sqlite';
    (new PDO('sqlite:' . $r26mDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    $r26mNew = static fn(): Updater => new Updater($r26mShop, $r26mRoot . '/private',
        new PDO('sqlite:' . $r26mDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function (): void {});
    $r26mState = static fn(): array => [MaintenanceMode::failed($r26mShop), (string)(MaintenanceMode::marker($r26mShop)['reason'] ?? '')];
    $r26mMigrate = 'cli/migrate.php failed: r26 boom — run php cli/migrate.php apply again';
    $r26mUpdater = $r26mNew();
    $r26mUpdater->baseline('26.0.0');
    $r26mUpdater->apply(new UpdatePackage($r26mRoot . '/package-code', null, true));
    MaintenanceMode::fail($r26mShop, $r26mMigrate);
    // The update with migrations completes the failed one and opens the shop; its snapshot holds the half-migrated database.
    $r26mSchema = $r26mUpdater->apply(new UpdatePackage($r26mRoot . '/package-schema', null, true));
    $r26mOpened = $r26mState();
    $r26mUpdater = $r26mNew();
    $r26mBack = $r26mUpdater->rollback((string)$r26mSchema['snapshot'], true, true);
    assert_equals([[false, ''], [true, $r26mMigrate], $r26mMigrate],
        [$r26mOpened, $r26mState(), $r26mBack['still_closed'] ?? null],
        'R26 updater: the rollback of a database snapshot taken over a failed migration keeps the shop closed for it (was: reopened on the half-migrated database it restored)');
    // ... and a step that migrates again lifts it.
    $r26mUpdater = $r26mNew();
    $r26mAgain = $r26mUpdater->apply(new UpdatePackage($r26mRoot . '/package-schema', null, true));
    assert_equals([false, ''], $r26mState(), 'R26 updater: an update that runs the migrations then lifts it');
    // That update's snapshot was taken over the failure the rollback put back: its rollback keeps it too.
    $r26mUpdater = $r26mNew();
    $r26mAgainBack = $r26mUpdater->rollback((string)$r26mAgain['snapshot'], true, true);
    assert_equals([[true, $r26mMigrate], $r26mMigrate], [$r26mState(), $r26mAgainBack['still_closed'] ?? null],
        'R26 updater: a snapshot taken over a failure a rollback put back carries it on as well');
    // A snapshot taken with the shop open puts none back.
    MaintenanceMode::leave($r26mShop);
    $r26mUpdater = $r26mNew();
    $r26mOpen = $r26mUpdater->apply(new UpdatePackage($r26mRoot . '/package-schema', null, true));
    $r26mUpdater = $r26mNew();
    $r26mClean = $r26mUpdater->rollback((string)$r26mOpen['snapshot'], true, true);
    assert_equals([[false, ''], null], [$r26mState(), $r26mClean['still_closed'] ?? null],
        'R26 updater: the rollback of a database snapshot taken with the shop open opens it');
} catch (Throwable $e) {
    assert_true(false, 'R26 updater (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r26mUpdater = null;
    gc_collect_cycles();
    if (isset($r26mShop)) @unlink(MaintenanceMode::path($r26mShop));
}

// 2. A SQLite swap killed between its two renames: the marker's owner is dead (unlocked, past its grace period), no live
//    file, the live database moved aside as <db>.pre-restore-*.rollback.
try {
    $r26mLive = $r26mBase . '/swap/shop.sqlite';
    @mkdir(dirname($r26mLive), 0700, true);
    $r26mKilledSwap = static function (bool $withCopy) use ($r26mLive): string {
        @unlink($r26mLive);
        (new PDO('sqlite:' . $r26mLive, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec("CREATE TABLE r26 (v TEXT); INSERT INTO r26 VALUES ('live before the swap')");
        file_put_contents(SqliteMaintenance::markerPath($r26mLive), '{"reason":"backup restore"}');
        touch(SqliteMaintenance::markerPath($r26mLive), time() - 60);
        $copy = $r26mLive . '.pre-restore-' . bin2hex(random_bytes(6)) . '.rollback';
        rename($r26mLive, $copy);
        if (!$withCopy) unlink($copy);
        clearstatcache();
        return $copy;
    };
    $r26mCopy = $r26mKilledSwap(true);
    $r26mActive = SqliteMaintenance::isActive($r26mLive);
    clearstatcache();
    $r26mValue = is_file($r26mLive) ? (string)(new PDO('sqlite:' . $r26mLive))->query('SELECT v FROM r26')->fetchColumn() : '';
    assert_equals([false, 'live before the swap', false, false], [$r26mActive, $r26mValue, is_file($r26mCopy), is_file(SqliteMaintenance::markerPath($r26mLive))],
        'R26 sqlite: a swap killed between its renames is recovered — the database moved aside goes back, the gate opens (was: active for good, every restore refused)');
    // The next restore can enter the gate.
    $r26mEntered = SqliteMaintenance::exclusive($r26mLive, 'r26 restore', static fn(): bool => true, 0.5);
    assert_true($r26mEntered === true, 'R26 sqlite: the next restore enters the gate');
    // Without a copy to put back the gate stays active (reopening would create an empty database).
    $r26mKilledSwap(false);
    assert_equals([true, false], [SqliteMaintenance::isActive($r26mLive), is_file($r26mLive)],
        'R26 sqlite: with no live file and no copy moved aside the gate stays active, no empty database is created');
    // A live owner (marker locked) is never taken over.
    $r26mCopy = $r26mKilledSwap(true);
    $r26mHeld = fopen(SqliteMaintenance::markerPath($r26mLive), 'r');
    flock($r26mHeld, LOCK_EX);
    $r26mProbe = proc_open([PHP_BINARY, '-r', 'require ' . var_export(dirname(TEST_CLI_DIR) . '/src/SqliteMaintenance.php', true) . '; echo SqliteMaintenance::isActive($argv[1]) ? "active" : "open";', $r26mLive],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r26mPipes);
    $r26mOut = '';
    if (is_resource($r26mProbe)) {
        $r26mOut = stream_get_contents($r26mPipes[1]) . stream_get_contents($r26mPipes[2]);
        proc_close($r26mProbe);
    }
    flock($r26mHeld, LOCK_UN);
    fclose($r26mHeld);
    clearstatcache();
    assert_true(is_file($r26mCopy) && !is_file($r26mLive),
        'R26 sqlite: a swap whose owner still holds the gate is left alone — ' . trim($r26mOut));
} catch (Throwable $e) {
    assert_true(false, 'R26 sqlite (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    if (function_exists('updateTestRemoveTree')) updateTestRemoveTree($r26mBase);
}
