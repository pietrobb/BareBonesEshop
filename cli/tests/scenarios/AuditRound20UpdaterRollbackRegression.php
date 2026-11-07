<?php

// Review round 20 (2026-11-07), low "updater and docs":
// - a rollback of an update of code only reopened the shop after a failed `migrate.php apply`, although the update
//   itself had kept it closed: the schema is as half-migrated after that rollback as before it;
// - README.md said any successful update or rollback lifts a failed window;
// - the upgrade note said the 2.1.2 updater refuses a MariaDB dump client, but it does so only when the dump holds a
//   row of a table with a generated column (categories, invoices): an empty shop passes.
echo "\n--- R20: a code-only rollback keeps a failed migration's window; README and upgrade note ---\n";
$r20uRoot = test_fixture_path('r20-updater-rollback');
$r20uUpdater = null;
try {
    foreach (['base/src', 'code/src', 'schema/src', 'schema/migrations', 'installed/src'] as $sub) mkdir($r20uRoot . '/' . $sub, 0700, true);
    foreach (['base' => 'a1', 'installed' => 'a1', 'code' => 'a2', 'schema' => 'a2'] as $tree => $content) {
        file_put_contents("{$r20uRoot}/{$tree}/src/A.php", $content . "\n");
    }
    file_put_contents($r20uRoot . '/schema/migrations/20991231000005_r20.php', "<?php return new class {};\n");
    UpdatePackageBuilder::build($r20uRoot . '/base', $r20uRoot . '/code', $r20uRoot . '/package-code', '20.0.1', '20.0.0');
    UpdatePackageBuilder::build($r20uRoot . '/code', $r20uRoot . '/schema', $r20uRoot . '/package-schema', '20.0.2', '20.0.1');
    $r20uShop = $r20uRoot . '/installed';
    $r20uDbPath = $r20uRoot . '/live.sqlite';
    (new PDO('sqlite:' . $r20uDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    $r20uUpdater = new Updater($r20uShop, $r20uRoot . '/private',
        new PDO('sqlite:' . $r20uDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function (): void {});
    $r20uState = static fn(): array => [MaintenanceMode::active($r20uShop), MaintenanceMode::failed($r20uShop),
        (string)(MaintenanceMode::marker($r20uShop)['reason'] ?? '')];
    $r20uRead = static fn(): string => (string)@file_get_contents($r20uShop . '/src/A.php');
    $r20uUpdater->baseline('20.0.0');
    $r20uMigrate = 'cli/migrate.php failed: r20 boom — run php cli/migrate.php apply again';

    // 1. A migration stopped half-way; an update of code only keeps the shop closed, and so does its rollback.
    MaintenanceMode::fail($r20uShop, $r20uMigrate);
    $r20uCode = $r20uUpdater->apply(new UpdatePackage($r20uRoot . '/package-code', null, true));
    $r20uBack = $r20uUpdater->rollback((string)$r20uCode['snapshot'], false, true);
    assert_equals([[true, true, $r20uMigrate], $r20uMigrate, null, "a1\n"],
        [$r20uState(), $r20uBack['still_closed'] ?? null, $r20uBack['database_restored_to'], $r20uRead()],
        'R20 rollback: one of code only keeps the failed window of a half-done migration and says so (was: shop reopened)');

    // 2. The window of a half-done rollback is lifted by the completed rollback it names (R21: only by that one).
    MaintenanceMode::leave($r20uShop);
    $r20uAgain = $r20uUpdater->apply(new UpdatePackage($r20uRoot . '/package-code', null, true));
    MaintenanceMode::fail($r20uShop, "rollback {$r20uAgain['snapshot']} failed half-way (boom); fix the cause and run php cli/update.php rollback --snapshot={$r20uAgain['snapshot']} --force");
    $r20uOther = $r20uUpdater->rollback((string)$r20uAgain['snapshot'], true, true);
    assert_equals([false, null], [is_file(MaintenanceMode::path($r20uShop)), $r20uOther['still_closed'] ?? null],
        'R20 rollback: the completed rollback a half-done rollback\'s window names lifts it');

    // 3. A rollback that restores the database does lift a failed migration's window.
    $r20uUpdater->apply(new UpdatePackage($r20uRoot . '/package-code', null, true));
    $r20uSchema = $r20uUpdater->apply(new UpdatePackage($r20uRoot . '/package-schema', null, true));
    MaintenanceMode::fail($r20uShop, $r20uMigrate);
    $r20uDbBack = $r20uUpdater->rollback((string)$r20uSchema['snapshot'], false, true);
    assert_equals([false, null, true], [is_file(MaintenanceMode::path($r20uShop)), $r20uDbBack['still_closed'] ?? null,
        $r20uDbBack['database_restored_to'] !== null],
        'R20 rollback: one that restored the database lifts the failed window of a migration');
} catch (Throwable $e) {
    assert_true(false, 'R20 updater rollback (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r20uUpdater = null;
    gc_collect_cycles();
}

$r20uCli = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/update.php');
assert_true(preg_match("/\\\$command === 'rollback'.*still_closed.*exit\\(0\\);/s", substr($r20uCli, 0, (int)strpos($r20uCli, "'check', 'backup', 'apply'"))) === 1,
    'R20 rollback: cli/update.php rollback warns that the shop stays closed');
$r20uReadme = preg_replace('/\s+/', ' ', (string)file_get_contents(dirname(TEST_CLI_DIR) . '/README.md'));
assert_true(!str_contains($r20uReadme, 'until the next successful `migrate.php apply`, update or rollback')
    && str_contains($r20uReadme, 'rollback of code only leaves that schema as it was'),
    'R20 README: it says which step lifts a failed migration window (any update or rollback did)');
$r20uNotes = preg_replace('/\s+/', ' ', (string)file_get_contents(dirname(TEST_CLI_DIR) . '/CHANGELOG.md'));
assert_true(str_contains($r20uNotes, 'only when the dump holds a row of `categories` or `invoices`')
    && str_contains($r20uNotes, 'a shop with no category and no invoice yet passes the probe'),
    'R20 upgrade notes: the 2.1.2 refusal of a MariaDB dump client depends on rows in categories/invoices');
