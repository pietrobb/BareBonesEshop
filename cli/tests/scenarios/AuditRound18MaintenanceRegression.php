<?php

// Review round 18 (2026-11-06), high "a failed or refused update.php rollback reopens the shop": rollback() and apply()
// lifted the maintenance window in a finally, so a rollback refused without --accept-data-loss or for an unknown
// snapshot removed a failed window, and a rollback that stopped half-way served a mix of old and new files.
// Low: a foreign flock on the marker kept the shop closed for ever.
echo "\n--- R18: only a successful update or rollback lifts the maintenance window ---\n";
$r18mRoot = test_fixture_path('r18-maintenance');
$r18mError = static function (callable $run): string {
    try {
        $run();
        return '';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};
$r18mUpdater = null;
try {
    foreach (['base/src', 'target/src', 'target/migrations', 'installed/src'] as $sub) mkdir($r18mRoot . '/' . $sub, 0700, true);
    file_put_contents($r18mRoot . '/base/src/Core.php', "core v1\n");
    file_put_contents($r18mRoot . '/installed/src/Core.php', "core v1\n");
    file_put_contents($r18mRoot . '/target/src/Core.php', "core v2\n");
    file_put_contents($r18mRoot . '/target/migrations/20991231000003_r18.php', "<?php return new class {};\n");
    UpdatePackageBuilder::build($r18mRoot . '/base', $r18mRoot . '/target', $r18mRoot . '/package', '18.0.1', '18.0.0');
    $r18mShop = $r18mRoot . '/installed';
    $r18mDbPath = $r18mRoot . '/live.sqlite';
    (new PDO('sqlite:' . $r18mDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    $r18mOpen = static fn(): Updater => new Updater($r18mShop, $r18mRoot . '/private',
        new PDO('sqlite:' . $r18mDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function (): void {});
    $r18mUpdater = $r18mOpen();
    $r18mUpdater->baseline('18.0.0');
    $r18mSnapshot = (string)$r18mUpdater->apply(new UpdatePackage($r18mRoot . '/package', null, true))['snapshot'];
    $r18mUpdater = null;
    gc_collect_cycles();
    $r18mHalf = 'update 18.0.2 failed and its automatic rollback failed; run php cli/update.php rollback --snapshot=x --force --accept-data-loss';
    $r18mState = static fn(): array => [MaintenanceMode::active($r18mShop), MaintenanceMode::failed($r18mShop),
        (string)(MaintenanceMode::marker($r18mShop)['reason'] ?? '')];

    // 1. Refused rollbacks leave a failed window as it was.
    MaintenanceMode::fail($r18mShop, $r18mHalf);
    $r18mUpdater = $r18mOpen();
    $r18mNoConsent = $r18mError(static fn() => $r18mUpdater->rollback($r18mSnapshot, false, false));
    $r18mUnknown = $r18mError(static fn() => $r18mUpdater->rollback('20990101000000-000000000000', true, true));
    $r18mInvalid = $r18mError(static fn() => $r18mUpdater->rollback('nonsense', true, true));
    assert_true(str_contains($r18mNoConsent, '--accept-data-loss') && str_contains($r18mUnknown, 'not found') && $r18mInvalid !== '',
        'R18 rollback: refused without --accept-data-loss and for an unknown or invalid snapshot (' . $r18mNoConsent . ' | ' . $r18mUnknown . ')');
    assert_equals([true, true, $r18mHalf], $r18mState(),
        'R18 rollback: a refused rollback keeps the failed maintenance window (the shop stays closed, same instructions)');

    // 2. An update refused before it enters maintenance does not lift it either (the same package is already installed).
    $r18mAgain = $r18mError(static fn() => $r18mUpdater->apply(new UpdatePackage($r18mRoot . '/package', null, true)));
    assert_true($r18mAgain !== '', 'R18 apply: the installed package is refused a second time');
    assert_equals([true, true, $r18mHalf], $r18mState(), 'R18 apply: a refused update keeps the failed maintenance window');

    // 3. A refused rollback with no window open opens none.
    MaintenanceMode::leave($r18mShop);
    $r18mError(static fn() => $r18mUpdater->rollback($r18mSnapshot, false, false));
    assert_true(!is_file(MaintenanceMode::path($r18mShop)), 'R18 rollback: a refused rollback leaves an open shop open');

    // 3b. Capability review: the window of an updater killed mid-apply (fresh marker, not failed, files half-written)
    //     survives a rollback refused after it entered maintenance (the changed file, no --force) unchanged.
    $r18mKilled = ['since' => gmdate('c', time() - 600), 'reason' => 'update 18.0.1', 'pid' => 999999, 'host' => (string)gethostname()];
    file_put_contents(MaintenanceMode::path($r18mShop), json_encode($r18mKilled));
    touch(MaintenanceMode::path($r18mShop), time() - 600);
    clearstatcache();
    $r18mKilledAt = filemtime(MaintenanceMode::path($r18mShop));
    file_put_contents($r18mShop . '/src/Core.php', "half\n");
    $r18mChanged = $r18mError(static fn() => $r18mUpdater->rollback($r18mSnapshot, false, true));
    clearstatcache();
    assert_equals([true, true, $r18mKilled, $r18mKilledAt], [str_contains($r18mChanged, 'src/Core.php'), MaintenanceMode::active($r18mShop),
        MaintenanceMode::marker($r18mShop), @filemtime(MaintenanceMode::path($r18mShop))],
        'R18 rollback: a rollback refused for a changed file keeps the window of a killed updater as it was (' . $r18mChanged . ')');
    file_put_contents($r18mShop . '/src/Core.php', "core v2\n");
    MaintenanceMode::leave($r18mShop);

    // 4. R19: a snapshot missing its only file is refused before anything changes, so the shop is not closed by it
    //    (R18 marked this "half-way"; a rollback that really restored a file and then failed: AuditRound19UpdaterMaintenance).
    $r18mCopy = $r18mRoot . '/private/snapshots/' . $r18mSnapshot . '/files/src/Core.php';
    rename($r18mCopy, $r18mCopy . '.away');
    $r18mHalfway = $r18mError(static fn() => $r18mUpdater->rollback($r18mSnapshot, false, true));
    assert_equals([true, false, "core v2\n"], [str_contains($r18mHalfway, 'Snapshot file missing'), is_file(MaintenanceMode::path($r18mShop)),
        (string)file_get_contents($r18mShop . '/src/Core.php')],
        'R18 rollback: a rollback refused for a missing snapshot file changed nothing and leaves the shop open (' . $r18mHalfway . ')');

    // 5. Once the cause is fixed, the rollback it names completes and lifts the window.
    rename($r18mCopy . '.away', $r18mCopy);
    $r18mUpdater = null;
    gc_collect_cycles();
    $r18mUpdater = $r18mOpen();
    $r18mUpdater->rollback($r18mSnapshot, true, true);
    assert_equals([false, false, "core v1\n"], [MaintenanceMode::active($r18mShop), is_file(MaintenanceMode::path($r18mShop)),
        (string)file_get_contents($r18mShop . '/src/Core.php')],
        'R18 rollback: the completed rollback restores the files and lifts the window');
} catch (Throwable $e) {
    assert_true(false, 'R18 maintenance (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    $r18mUpdater = null;
    gc_collect_cycles();
}

// 6. A foreign lock on the marker keeps the shop closed for MAX_AGE at most (R17: an update's own lock still counts
//    while the marker is younger than that, see AuditRound17MaintenanceRegression).
$r18mLockRoot = $r18mRoot . '/lock';
mkdir($r18mLockRoot, 0700, true);
$r18mMarker = MaintenanceMode::path($r18mLockRoot);
file_put_contents($r18mMarker, json_encode(['since' => gmdate('c'), 'reason' => 'update 9.9.9', 'pid' => 1, 'host' => 'r18-gone.invalid']));
$r18mForeign = fopen($r18mMarker, 'r');
$r18mLocked = flock($r18mForeign, LOCK_EX | LOCK_NB);
touch($r18mMarker, time() - MaintenanceMode::STALE_AFTER - 600);
clearstatcache(true, $r18mMarker);
$r18mHour = MaintenanceMode::active($r18mLockRoot);
touch($r18mMarker, time() - MaintenanceMode::MAX_AGE - 600);
clearstatcache(true, $r18mMarker);
$r18mDay = MaintenanceMode::active($r18mLockRoot);
flock($r18mForeign, LOCK_UN);
fclose($r18mForeign);
@unlink($r18mMarker);
assert_equals([true, true, false], [$r18mLocked, $r18mHour, $r18mDay],
    'R18 maintenance: a lock on the marker keeps the shop closed past the hour, but not past MAX_AGE');

// 7. cli/migrate.php: a run that stopped before its first migration (the pre-migration backup failed) changed nothing,
//    so it restores the window it found instead of a permanent 503 (end-to-end run in the PR proof).
$r18mScript = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/migrate.php');
assert_true(preg_match('/if \(\$ownMaintenance && !\$schemaTouched\) \{\s*if \(\$priorFailure !== null\) \{\s*MaintenanceMode::fail\(\$root, \$priorFailure\);.*?\} else \{\s*MaintenanceMode::leave\(\$root\);/s', $r18mScript) === 1
    && preg_match('/\$preApplyHook\(\$names, \$log\);\s*\$backupTaken = true;\s*\}\s*(?:\/\/[^\n]*\n\s*)?if \(\$ownMaintenance\) \{\s*MaintenanceMode::arm\(\$root, [^\n]*\);\s*\}\s*\$schemaTouched = true;/', $r18mScript) === 1,
    'R18 migrate.php: a failed pre-migration backup reopens the unchanged shop (or keeps an earlier failed window)');
