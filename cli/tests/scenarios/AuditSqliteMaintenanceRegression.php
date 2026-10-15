<?php
/**
 * Audit 2026-10-12 / A09.3 / finding 24 — swapping the live SQLite file (backup restore, updater
 * rollback) while other PHP processes still hold it open. On POSIX the rename succeeds, the other
 * process keeps writing into the unlinked inode and its committed writes silently disappear.
 *
 * Restore/rollback must run under a maintenance gate: new connections are refused (Db::get) and
 * the swap only happens once no other connection holds the database; otherwise it is refused
 * without touching anything. Driven with a real second PHP process holding a WAL connection.
 */

echo "\n--- Audit A09.3: SQLite restore/rollback under maintenance, live writers ---\n";

$a093Root = test_fixture_path('sqlite-maintenance');
mkdir($a093Root, 0700, true);
file_put_contents($a093Root . '/writer.php', <<<'PHP'
<?php
// A long-running writer (worker, web request): opens the DB, idles, then commits a row.
[, $dbPath, $dir] = $argv;
$db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA busy_timeout = 5000');
$db->query('SELECT COUNT(*) FROM notes')->fetchColumn();
touch($dir . '/ready');
for ($i = 0; $i < 400 && !is_file($dir . '/go'); $i++) usleep(50000);
$db->exec("INSERT INTO notes (body) VALUES ('written by live process')");
$db = null;
echo 'committed';
PHP);

$a093Start = static function (string $dbPath, string $dir) use ($a093Root): array {
    @unlink($dir . '/ready');
    @unlink($dir . '/go');
    $process = proc_open([PHP_BINARY, $a093Root . '/writer.php', $dbPath, $dir], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    for ($i = 0; $i < 200 && !is_file($dir . '/ready'); $i++) usleep(50000);
    return [$process, $pipes];
};
$a093Finish = static function (array $handle, string $dir): string {
    [$process, $pipes] = $handle;
    touch($dir . '/go');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return $out;
};
$a093Notes = static function (string $dbPath): array {
    $db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $rows = $db->query('SELECT body FROM notes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $db = null;
    return $rows;
};

SqliteMaintenance::setDefaultWait(1.0);
$a093Db = null;
try {
    // ---- 1. BackupService::restore with a live writer ----
    $backupDir = $a093Root . '/backup';
    $backupProc = $backupDir . '/proc';
    mkdir($backupProc, 0700, true);
    $livePath = $backupDir . '/live.db';
    $a093Db = new PDO('sqlite:' . $livePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $a093Db->exec('PRAGMA journal_mode=WAL');
    $a093Db->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
    $a093Db->exec('CREATE TABLE schema_migrations (name TEXT PRIMARY KEY)');
    $a093Db->exec("INSERT INTO notes (body) VALUES ('backup state')");
    BackupService::init($a093Db, [
        'enabled' => true, 'compression' => false, 'auto_gc_on_backup' => false, 'wal_checkpoint' => true,
        'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6, 'minimum_kept' => 3],
        'log_file' => 'backup.log', 'storage_dir' => $backupDir . '/storage',
    ], $backupDir);
    $backup = BackupService::create(['label' => 'a093']);
    $a093Db->exec("INSERT INTO notes (body) VALUES ('after backup')");
    $a093Db = null;

    $writer = $a093Start($livePath, $backupProc);
    $refusal = '';
    try {
        BackupService::restore($backup['remote_path'], ['skip_snapshot' => true]);
    } catch (Throwable $e) {
        $refusal = $e->getMessage();
    }
    assert_true($refusal !== '', 'A09.3: restore is refused while another process holds the live database');
    assert_true(!SqliteMaintenance::isActive($livePath), 'A09.3: refused restore lifts the maintenance gate again');
    $writerOut = $a093Finish($writer, $backupProc);
    assert_equals('committed', trim($writerOut), 'A09.3: the live process can still commit after the refused restore');
    assert_equals(['backup state', 'after backup', 'written by live process'], $a093Notes($livePath), 'A09.3: no committed write of the live process is lost');

    $restored = BackupService::restore($backup['remote_path'], ['skip_snapshot' => true]);
    assert_equals('ok', $restored['integrity'] ?? null, 'A09.3: restore succeeds once no other process holds the database');
    assert_equals(['backup state'], $a093Notes($livePath), 'A09.3: restored database has the backup content');
    assert_true(!SqliteMaintenance::isActive($livePath), 'A09.3: successful restore lifts the maintenance gate');
    BackupService::reset();

    // ---- 2. The maintenance gate refuses new connections through Db::get() ----
    $gateApp = $a093Root . '/gate-app';
    mkdir($gateApp, 0700, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $destination = $gateApp . '/src/' . substr($item->getPathname(), strlen(dirname(__DIR__, 3) . '/src') + 1);
        $item->isDir() ? @mkdir($destination, 0700, true) : (is_dir(dirname($destination)) || mkdir(dirname($destination), 0700, true)) && copy($item->getPathname(), $destination);
    }
    file_put_contents($gateApp . '/config.php', '<?php return ' . var_export(['db_driver' => 'sqlite', 'db_sqlite_path' => $livePath], true) . ";\n");
    $probe = 'require ' . var_export($gateApp . '/src/autoload.php', true) . '; try { Db::get()->query("SELECT COUNT(*) FROM notes")->fetchColumn(); echo "open"; } catch (DatabaseMaintenanceException $e) { echo "maintenance"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(); }';
    $runProbe = static function () use ($probe): string {
        $process = proc_open([PHP_BINARY, '-r', $probe], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return trim($out);
    };
    SqliteMaintenance::enter($livePath, 'a093 test');
    $during = $runProbe();
    SqliteMaintenance::leave($livePath);
    assert_equals('maintenance', $during, 'A09.3: Db::get() refuses new connections during maintenance');
    assert_equals('open', $runProbe(), 'A09.3: Db::get() works again after maintenance');

    // ---- 3. Updater::rollback of a SQLite migration snapshot with a live writer ----
    $updDir = $a093Root . '/updater';
    $updProc = $updDir . '/proc';
    foreach (['base/src', 'target/src', 'target/migrations', 'installed/src', 'proc'] as $sub) {
        mkdir($updDir . '/' . $sub, 0700, true);
    }
    file_put_contents($updDir . '/base/src/Core.php', "core v1\n");
    file_put_contents($updDir . '/installed/src/Core.php', "core v1\n");
    file_put_contents($updDir . '/target/src/Core.php', "core v2\n");
    file_put_contents($updDir . '/target/migrations/20991231000001_a093.php', "<?php return new class {};\n");
    UpdatePackageBuilder::build($updDir . '/base', $updDir . '/target', $updDir . '/package', '9.5.0', '9.4.0');
    $updDbPath = $updDir . '/live.sqlite';
    $updDb = new PDO('sqlite:' . $updDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $updDb->exec('PRAGMA journal_mode=WAL');
    $updDb->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
    $updDb->exec("INSERT INTO notes (body) VALUES ('pre-update')");
    $updater = new Updater($updDir . '/installed', $updDir . '/private', $updDb, static function () use ($updDbPath): void {
        $migration = new PDO('sqlite:' . $updDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migration->exec("INSERT INTO notes (body) VALUES ('migrated')");
    });
    $updater->baseline('9.4.0');
    $applied = $updater->apply(new UpdatePackage($updDir . '/package', null, true));
    $updDb = null;
    $updater = new Updater($updDir . '/installed', $updDir . '/private', null);

    $writer = $a093Start($updDbPath, $updProc);
    $rollbackRefusal = '';
    try {
        $updater->rollback($applied['snapshot'], false, true);
    } catch (Throwable $e) {
        $rollbackRefusal = $e->getMessage();
    }
    assert_true($rollbackRefusal !== '', 'A09.3 updater: rollback is refused while another process holds the live database');
    assert_equals("core v2\n", file_get_contents($updDir . '/installed/src/Core.php'), 'A09.3 updater: refused rollback leaves code untouched (no partial rollback)');
    assert_true(!SqliteMaintenance::isActive($updDbPath), 'A09.3 updater: refused rollback lifts the maintenance gate');
    assert_equals('committed', trim($a093Finish($writer, $updProc)), 'A09.3 updater: the live process commits after the refused rollback');
    assert_equals(['pre-update', 'migrated', 'written by live process'], $a093Notes($updDbPath), 'A09.3 updater: no committed write is lost');

    // Code review 2026-10-15 H8: rolling back a migration update silently discarded every write since the snapshot.
    $h8Warning = '';
    try {
        $updater->rollback($applied['snapshot']);
    } catch (RuntimeException $e) {
        $h8Warning = $e->getMessage();
    }
    assert_true(str_contains($h8Warning, 'permanently lost') && str_contains($h8Warning, '--accept-data-loss'),
        'H8: rollback of a migration update without --accept-data-loss is refused with a data-loss warning');
    assert_equals(["core v2\n", ['pre-update', 'migrated', 'written by live process']], [file_get_contents($updDir . '/installed/src/Core.php'), $a093Notes($updDbPath)],
        'H8: the refused rollback leaves code and every post-update write untouched');
    $h8Result = $updater->rollback($applied['snapshot'], false, true);
    assert_true(is_string($h8Result['database_restored_to'] ?? null), 'H8: confirmed rollback reports the point in time the database was restored to');
    assert_equals("core v1\n", file_get_contents($updDir . '/installed/src/Core.php'), 'A09.3 updater: rollback restores code once the database is free');
    assert_equals(['pre-update'], $a093Notes($updDbPath), 'A09.3 updater: rollback restores the pre-update database');
} finally {
    SqliteMaintenance::setDefaultWait(null);
    BackupService::reset();
    $a093Db = null;
    if (function_exists('updateTestRemoveTree')) {
        updateTestRemoveTree($a093Root);
    }
}
