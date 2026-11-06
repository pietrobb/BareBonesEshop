<?php

// Review round 19 (2026-11-06), low "updater":
// - a successful update of code only lifted the failed window of a migration stopped half-way: the shop reopened on an
//   unfinished schema; only an update that runs the migrations (or migrate.php apply) may lift it;
// - a rollback that changed nothing (its snapshot misses a file) marked the window failed and kept the shop closed:
//   the sources are checked before the first change, and "half-way" means a file was really restored.
echo "\n--- R19: a code-only update keeps a failed window, a rollback that changed nothing does not close the shop ---\n";
$r19uRoot = test_fixture_path('r19-updater-maintenance');
$r19uError = static function (callable $run): string {
    try {
        $run();
        return '';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};
$r19uUpdater = null;
try {
    foreach (['base/src', 'code/src', 'schema/src', 'schema/migrations', 'installed/src'] as $sub) mkdir($r19uRoot . '/' . $sub, 0700, true);
    foreach (['base', 'installed'] as $tree) {
        file_put_contents("{$r19uRoot}/{$tree}/src/A.php", "a1\n");
        file_put_contents("{$r19uRoot}/{$tree}/src/B.php", "b1\n");
    }
    foreach (['code', 'schema'] as $tree) {
        file_put_contents("{$r19uRoot}/{$tree}/src/A.php", "a2\n");
        file_put_contents("{$r19uRoot}/{$tree}/src/B.php", "b2\n");
    }
    file_put_contents($r19uRoot . '/schema/migrations/20991231000004_r19.php', "<?php return new class {};\n");
    UpdatePackageBuilder::build($r19uRoot . '/base', $r19uRoot . '/code', $r19uRoot . '/package-code', '19.0.1', '19.0.0');
    UpdatePackageBuilder::build($r19uRoot . '/code', $r19uRoot . '/schema', $r19uRoot . '/package-schema', '19.0.2', '19.0.1');
    $r19uShop = $r19uRoot . '/installed';
    $r19uDbPath = $r19uRoot . '/live.sqlite';
    (new PDO('sqlite:' . $r19uDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    $r19uOpen = static fn(): Updater => new Updater($r19uShop, $r19uRoot . '/private',
        new PDO('sqlite:' . $r19uDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function (): void {});
    $r19uState = static fn(): array => [MaintenanceMode::active($r19uShop), MaintenanceMode::failed($r19uShop),
        (string)(MaintenanceMode::marker($r19uShop)['reason'] ?? '')];
    $r19uRead = static fn(string $file): string => (string)@file_get_contents($r19uShop . '/src/' . $file);
    $r19uUpdater = $r19uOpen();
    $r19uUpdater->baseline('19.0.0');

    // 1. A migration stopped half-way; an update of code only succeeds, but the schema is still unfinished.
    $r19uMigrate = 'cli/migrate.php failed: r19 boom — run php cli/migrate.php apply again';
    MaintenanceMode::fail($r19uShop, $r19uMigrate);
    $r19uCode = $r19uUpdater->apply(new UpdatePackage($r19uRoot . '/package-code', null, true));
    assert_equals([[true, true, $r19uMigrate], $r19uMigrate, "a2\n"], [$r19uState(), $r19uCode['still_closed'] ?? null, $r19uRead('A.php')],
        'R19 apply: an update of code only keeps the failed window of a half-done migration and says so (was: shop reopened)');

    // 2. A rollback whose snapshot misses a file is refused before it changes anything: the window it found stays.
    $r19uSnapshot = (string)$r19uCode['snapshot'];
    $r19uCopy = $r19uRoot . '/private/snapshots/' . $r19uSnapshot . '/files/src/B.php';
    rename($r19uCopy, $r19uCopy . '.away');
    MaintenanceMode::leave($r19uShop);
    $r19uMissing = $r19uError(static fn() => $r19uUpdater->rollback($r19uSnapshot, false, true));
    assert_equals([true, false, "a2\n", "b2\n"], [str_contains($r19uMissing, 'Snapshot file missing'), is_file(MaintenanceMode::path($r19uShop)),
        $r19uRead('A.php'), $r19uRead('B.php')],
        'R19 rollback: one refused for a missing snapshot file changed nothing and leaves the open shop open (was: failed window, closed)');
    rename($r19uCopy . '.away', $r19uCopy);

    // 3. A rollback that restored a file and then stopped keeps the shop closed and says what to run.
    unlink($r19uShop . '/src/B.php');
    mkdir($r19uShop . '/src/B.php/x', 0700, true);
    $r19uHalf = $r19uError(static fn() => $r19uUpdater->rollback($r19uSnapshot, true, true));
    [$r19uActive, $r19uFailed, $r19uReason] = $r19uState();
    assert_true($r19uHalf !== '' && $r19uRead('A.php') === "a1\n" && $r19uActive && $r19uFailed
        && str_contains($r19uReason, "rollback {$r19uSnapshot} failed half-way") && str_contains($r19uReason, "--snapshot={$r19uSnapshot} --force"),
        'R19 rollback: one that restored a file and then failed keeps the shop closed and names the command (' . $r19uReason . ')');
    rmdir($r19uShop . '/src/B.php/x');
    rmdir($r19uShop . '/src/B.php');
    $r19uUpdater->rollback($r19uSnapshot, true, true);
    assert_equals([false, "a1\n", "b1\n"], [is_file(MaintenanceMode::path($r19uShop)), $r19uRead('A.php'), $r19uRead('B.php')],
        'R19 rollback: the rollback it names completes and lifts the window');

    // 4. An update that runs the migrations completes the schema and lifts a failed window.
    $r19uUpdater->apply(new UpdatePackage($r19uRoot . '/package-code', null, true));
    MaintenanceMode::fail($r19uShop, $r19uMigrate);
    $r19uSchema = $r19uUpdater->apply(new UpdatePackage($r19uRoot . '/package-schema', null, true));
    assert_equals([false, null], [is_file(MaintenanceMode::path($r19uShop)), $r19uSchema['still_closed'] ?? null],
        'R19 apply: an update that ran the migrations lifts the failed window');
    assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/update.php'), "\$result['still_closed']"),
        'R19 apply: cli/update.php warns that the shop stays closed');
} catch (Throwable $e) {
    assert_true(false, 'R19 updater maintenance (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r19uUpdater = null;
    gc_collect_cycles();
}

$r19uRunbook = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/docs/AI_UPDATE_RUNBOOK.md');
assert_true(!str_contains($r19uRunbook, 'the application has no built-in maintenance mode') && !str_contains($r19uRunbook, 'The application has no built-in maintenance mode')
    && str_contains($r19uRunbook, '.maintenance') && str_contains($r19uRunbook, '2.1.2'),
    'R19 runbook: it describes the maintenance window and the 2.1.2 transition (it said there was no maintenance mode)');
