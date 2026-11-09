<?php

// Review round 24 (2026-11-09, "kolo 8"), finding 4 (goal #381): cli/migrate.php and cli/update.php each checked that no
// other process held the maintenance window, then entered it. Between the two, the other could start: a migration that
// entered after an update's check overwrote its window and, ending first, lifted it while the update still ran. Update,
// rollback, migrate apply and restore now share one step lock (MaintenanceMode::claim()), taken before they look at the
// window; a step that holds it, window entered yet or not, keeps every other one out.
echo "\n--- R24: update, rollback, migration and restore exclude each other ---\n";
$r24xRepo = dirname(TEST_CLI_DIR);
$r24xBase = test_fixture_path('r24-race');
@mkdir($r24xBase, 0700, true);
$r24xPhp = static function (array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => (string)$out];
};
$r24xCopy = static function (string $from, string $to) use (&$r24xCopy): void {
    @mkdir($to, 0700, true);
    foreach (new FilesystemIterator($from) as $item) {
        $target = $to . '/' . $item->getFilename();
        if ($item->isDir()) $r24xCopy($item->getPathname(), $target);
        else copy($item->getPathname(), $target);
    }
};
// A step in another process that has started but not entered its window yet: an update between its check of the window
// and enter() (staging, the database snapshot), or a migration taking its pre-migration backup.
file_put_contents($r24xBase . '/step.php', '<?php
require ' . var_export($r24xRepo . '/src/autoload.php', true) . ';
if (method_exists("MaintenanceMode", "claim")) MaintenanceMode::claim($argv[1]);
echo "started\n";
fgets(STDIN);
exit(1);
');
$r24xStart = static function (string $root) use ($r24xBase): array {
    $process = proc_open([PHP_BINARY, $r24xBase . '/step.php', $root], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $started = trim((string)fgets($pipes[1]));
    return ['process' => $process, 'pipes' => $pipes, 'started' => $started];
};
$r24xEnd = static function (array $step): void {
    fclose($step['pipes'][0]);
    stream_get_contents($step['pipes'][1]);
    fclose($step['pipes'][1]);
    fclose($step['pipes'][2]);
    proc_close($step['process']);
};
$r24xApp = $r24xBase . '/app';
$r24xMarker = static function () use ($r24xApp): array {
    clearstatcache();
    return [is_file(MaintenanceMode::path($r24xApp)), (string)(MaintenanceMode::marker($r24xApp)['reason'] ?? '')];
};
try {
    foreach (['src', 'adapters', 'migrations', 'config', 'extensions'] as $directory) {
        if (is_dir($r24xRepo . '/' . $directory)) $r24xCopy($r24xRepo . '/' . $directory, $r24xApp . '/' . $directory);
    }
    foreach (glob($r24xRepo . '/cli/*.php') ?: [] as $script) {
        @mkdir($r24xApp . '/cli', 0700, true);
        copy($script, $r24xApp . '/cli/' . basename($script));
    }
    foreach (glob($r24xRepo . '/*.json') ?: [] as $file) copy($file, $r24xApp . '/' . basename($file));
    $r24xDb = $r24xBase . '/app-db/shop.sqlite';
    @mkdir(dirname($r24xDb), 0700, true);
    file_put_contents($r24xApp . '/config.php', '<?php return ' . var_export([
        'db_driver' => 'sqlite',
        'db_sqlite_path' => $r24xDb,
        'jobs_dir' => $r24xBase . '/app-jobs',
        'cache_dir' => $r24xBase . '/app-cache',
        'backup_storage_dir' => $r24xBase . '/app-private/backups',
        'backup_log_file' => $r24xBase . '/app-private/backup.log',
    ], true) . ";\n");
    $r24xSetup = [$r24xPhp([$r24xApp . '/cli/migrate.php', 'apply']), $r24xPhp([$r24xApp . '/cli/backup.php', '--quiet'])];
    assert_equals([0, 0, [false, '']], [$r24xSetup[0]['exit'], $r24xSetup[1]['exit'], $r24xMarker()],
        'R24 race: fixture database and backup — ' . trim(substr($r24xSetup[0]['output'] . $r24xSetup[1]['output'], -160)));
    $r24xTable = static function () use ($r24xDb): bool {
        $pdo = new PDO('sqlite:' . $r24xDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'r24_probe'")->fetchColumn() === 1;
    };
    file_put_contents($r24xApp . '/migrations/20991231000000_r24_probe.php',
        "<?php return new class { public function up(PDO \$db, bool \$isSqlite): void { \$db->exec('CREATE TABLE r24_probe (id INTEGER)'); } };\n");

    // 1. An update has started (window not entered yet): cli/migrate.php does not migrate or open a window of its own.
    $r24xStep = $r24xStart($r24xApp);
    $r24xMigrate = $r24xPhp([$r24xApp . '/cli/migrate.php', 'apply']);
    $r24xState = [$r24xStep['started'], $r24xMigrate['exit'] !== 0, str_contains($r24xMigrate['output'], 'no migration ran'), $r24xTable(), $r24xMarker()];
    // 2. The same in this process: an update entering its window while a migration has started is refused at enter().
    $r24xEnter = '';
    try {
        MaintenanceMode::enter($r24xApp, 'update 24.0.1');
    } catch (Throwable $e) {
        $r24xEnter = $e->getMessage();
    }
    $r24xEnterState = [str_contains($r24xEnter, 'Another maintenance step is running'), $r24xMarker()];
    // 3. The updater refuses a rollback before it looks at the window.
    $r24xRollback = '';
    try {
        @mkdir($r24xBase . '/update-state', 0700, true);
        (new Updater($r24xApp, $r24xBase . '/update-state'))->rollback('20990101000000-000000000000', true, true);
    } catch (Throwable $e) {
        $r24xRollback = $e->getMessage();
    }
    // 4. cli/restore.php is refused as well.
    $r24xRestore = $r24xPhp([$r24xApp . '/cli/restore.php', '--latest', '--yes']);
    $r24xRestoreState = [$r24xRestore['exit'], str_contains($r24xRestore['output'], 'another maintenance window is open'), $r24xMarker()];
    $r24xEnd($r24xStep);
    assert_equals(['started', true, true, false, [false, '']], $r24xState,
        'R24 race: while an update has started, cli/migrate.php refuses and opens no window (was: migrated and entered a window the update then shared) — '
        . trim(substr($r24xMigrate['output'], -200)));
    assert_equals([true, [false, '']], $r24xEnterState,
        'R24 race: entering a window while another step runs is refused, and the marker is not written over (was: the later step overwrote it) — ' . $r24xEnter);
    assert_true(str_contains($r24xRollback, 'Another maintenance window is open (a migration or restore is starting)'),
        'R24 race: a rollback is refused while another step runs, before it looks at the window — ' . $r24xRollback);
    assert_equals([1, true, [false, '']], $r24xRestoreState,
        'R24 race: cli/restore.php is refused while another step runs — ' . trim(substr($r24xRestore['output'], -160)));

    // 5. A failed migration window that a running update is taking over: a migration with nothing pending leaves it alone.
    $r24xMigrated = $r24xPhp([$r24xApp . '/cli/migrate.php', 'apply']);
    assert_equals([0, true, [false, '']], [$r24xMigrated['exit'], $r24xTable(), $r24xMarker()],
        'R24 race: once the other step ended, the migration runs and lifts its window — ' . trim(substr($r24xMigrated['output'], -160)));
    $r24xFailed = 'cli/migrate.php failed: r24 probe — run php cli/migrate.php apply again';
    MaintenanceMode::fail($r24xApp, $r24xFailed);
    $r24xStep = $r24xStart($r24xApp);
    $r24xNothing = $r24xPhp([$r24xApp . '/cli/migrate.php', 'apply']);
    $r24xNothingState = [$r24xNothing['exit'], $r24xMarker()];
    $r24xEnd($r24xStep);
    assert_equals([0, [true, $r24xFailed]], $r24xNothingState,
        'R24 race: a migration with nothing to apply does not lift a failed migration window while an update runs (that update takes it over) — '
        . trim(substr($r24xNothing['output'], -160)));
    $r24xLifted = $r24xPhp([$r24xApp . '/cli/migrate.php', 'apply']);
    assert_equals([0, [false, '']], [$r24xLifted['exit'], $r24xMarker()],
        'R24 race: with no other step running it lifts that window as before — ' . trim(substr($r24xLifted['output'], -160)));

    // The updater never replaces or deletes the step lock.
    assert_true(str_contains((string)file_get_contents($r24xRepo . '/src/Updater.php'), 'MaintenanceMode::FILE, MaintenanceMode::LOCK_FILE]'),
        'R24 race: the step lock is a protected file of the updater');
} catch (Throwable $e) {
    assert_true(false, 'R24 race (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    @unlink(MaintenanceMode::path($r24xApp));
    if (function_exists('updateTestRemoveTree')) updateTestRemoveTree($r24xBase);
}
