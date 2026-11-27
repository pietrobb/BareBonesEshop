<?php

// Review round 20 (R55), restore lows:
//  - a legal text the live shop lacked was written in place (rendered unescaped), and an uploads/ script it could load
//    (CSP 'self') as well: a forged backup's terms.html ran its script;
//  - any error reading the live number series dropped all of them with a warning, also a passing lock (SQLITE_BUSY):
//    invoice numbers whose PDFs exist were issued again;
//  - every restore left another copy of config.php in from-backup and nothing removed them.
echo "\n--- Audit round 55: legal/uploads set aside, live number series per table and under a lock, aside runs pruned ---\n";
$r55rRoot = sys_get_temp_dir() . '/bbe-r55r-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($r55rRoot, 0700, true);
$r55rRm = static function (string $path) use (&$r55rRm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $r55rRm($path . '/' . $entry);
    }
    @rmdir($path);
};
$r55rCfg = static fn(string $dir, array $files = []): array => ['enabled' => true, 'storage' => 'local', 'compression' => false,
    'auto_gc_on_backup' => false, 'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 5],
    'storage_dir' => $dir, 'files' => $files];

// 1. Legal texts the live shop lacks and uploads/ scripts are set aside; images and documents are restored in place.
$r55rShop = $r55rRoot . '/shop';
$r55rData = $r55rRoot . '/data';
foreach (['/uploads/full', '/themes/default'] as $r55rSub) @mkdir($r55rShop . $r55rSub, 0700, true);
@mkdir($r55rData . '/legal/sk', 0700, true);
file_put_contents($r55rShop . '/config.php', '<?php return [];');
$r55rAsideFiles = [
    'legal/sk/terms.html' => '<p>Terms</p><script src="/uploads/full/x.js"></script>',
    'uploads/full/x.js' => 'fetch("/admin")',
    'uploads/full/page.html' => '<script>1</script>',
    'uploads/full/logo.svg' => '<svg onload="alert(1)"/>',
];
$r55rInPlace = ['uploads/full/photo.jpg' => 'jpeg', 'uploads/full/manual.pdf' => '%PDF'];
$r55rWhere = static fn(string $rel): string => str_starts_with($rel, 'legal/') ? $r55rData . '/' . $rel : $r55rShop . '/' . $rel;
foreach ($r55rAsideFiles + $r55rInPlace as $r55rRel => $r55rBody) file_put_contents($r55rWhere($r55rRel), $r55rBody);
$r55rSources = BackupService::defaultFileSources(['private_runtime_dir' => $r55rData, 'jobs_dir' => $r55rData . '/jobs',
    'invoice_storage_dir' => $r55rData . '/invoices', 'uploads_dir' => $r55rShop . '/uploads', 'gdpr_salt_file' => $r55rData . '/gdpr_salt',
    'digital_assets_base_dir' => $r55rData . '/downloads'], $r55rShop);
$r55rPdo = new PDO('sqlite:' . $r55rRoot . '/files.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r55rPdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
try {
    BackupService::init($r55rPdo, $r55rCfg($r55rRoot . '/backups', $r55rSources), $r55rShop);
    $r55rMeta = BackupService::create(['label' => 'manual']);
    foreach (array_keys($r55rAsideFiles + $r55rInPlace) as $r55rRel) unlink($r55rWhere($r55rRel));
    $r55rFiles = BackupService::restoreFiles((string)$r55rMeta['remote_path']);
    $r55rAside = (string)($r55rFiles['aside_dir'] ?? '');
    clearstatcache();
    foreach ($r55rAsideFiles as $r55rRel => $r55rBody) {
        assert_true(!file_exists($r55rWhere($r55rRel)), "R55 restore: {$r55rRel} the live shop lacked is not written in place");
        assert_equals($r55rBody, @file_get_contents($r55rAside . '/' . $r55rRel), "R55 restore: {$r55rRel} is kept in the private recovery directory");
    }
    foreach ($r55rInPlace as $r55rRel => $r55rBody) {
        assert_equals($r55rBody, @file_get_contents($r55rWhere($r55rRel)), "R55 restore: {$r55rRel} is restored in place");
    }
    $r55rReported = array_merge((array)($r55rFiles['set_aside'] ?? []), (array)($r55rFiles['quarantined'] ?? []));
    sort($r55rReported);
    $r55rExpected = array_keys($r55rAsideFiles);
    sort($r55rExpected);
    assert_equals($r55rExpected, $r55rReported, 'R55 restore: the files set aside are reported (was: written in place, unreported)');

    // 3. Each restore that sets copies aside adds a run; only the newest five are kept.
    // The live shop still lacks the legal text, so each restore sets it aside again.
    for ($r55rRun = 0; $r55rRun < 6; $r55rRun++) {
        $r55rLast = (string)(BackupService::restoreFiles((string)$r55rMeta['remote_path'])['aside_dir'] ?? '');
    }
    $r55rRuns = array_values(array_filter(scandir(dirname($r55rLast)) ?: [], static fn(string $e): bool => $e !== '.' && $e !== '..'));
    // R61: runs younger than 30 days all stay (AuditRound61RestoreRegression: an older one beyond the newest five is removed).
    $r55rFrom = dirname($r55rLast);
    foreach (array_slice($r55rRuns, 0, 2) as $r55rI => $r55rOld) {
        rename($r55rFrom . '/' . $r55rOld, $r55rFrom . '/' . gmdate('Ymd-His', time() - 40 * 86400 - $r55rI) . '-' . substr($r55rOld, 16));
    }
    $r55rLast = (string)(BackupService::restoreFiles((string)$r55rMeta['remote_path'])['aside_dir'] ?? '');
    $r55rKept = array_values(array_filter(scandir($r55rFrom) ?: [], static fn(string $e): bool => $e !== '.' && $e !== '..'));
    assert_true(count($r55rRuns) === 7 && count($r55rKept) === 6 && in_array(basename($r55rLast), $r55rKept, true),
        'R55 restore: copies set aside are removed once older than the newest five and 30 days (was: one more config.php copy each time): ' . count($r55rRuns) . '/' . count($r55rKept));
} catch (Throwable $e) {
    assert_true(false, 'R55 restore files (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    BackupService::reset();
    $r55rPdo = null;
    gc_collect_cycles();
}

// 2. The live number series are read table by table; a lock that does not pass stops the restore before anything is replaced.
$r55rLiveDb = static function (string $path): PDO {
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0]);
    $db->exec('CREATE TABLE schema_migrations (name TEXT PRIMARY KEY)');
    $db->exec("CREATE TABLE invoice_number_counters (store_id TEXT NOT NULL, type TEXT NOT NULL DEFAULT 'invoice', year INTEGER NOT NULL,
        prefix TEXT NOT NULL DEFAULT '', last_number INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (store_id, type, year, prefix))");
    $db->exec("CREATE TABLE invoice_counters (year INTEGER NOT NULL, prefix TEXT NOT NULL DEFAULT '', last_number INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (year, prefix))");
    $db->exec("INSERT INTO invoice_number_counters VALUES ('default', 'invoice', 2026, 'FA', 3)");
    $db->exec("INSERT INTO invoice_counters VALUES (2026, 'FA', 3)");
    return $db;
};
$r55rLivePath = $r55rRoot . '/live.db';
$r55rLive = $r55rLiveDb($r55rLivePath);
try {
    BackupService::init($r55rLive, $r55rCfg($r55rRoot . '/backups-series'), $r55rRoot);
    $r55rOld = (string)BackupService::create(['label' => 'scheduled'])['remote_path'];
    // After the backup FA 4 and 5 were issued; the legacy counter table of the live database is unreadable (damaged).
    $r55rLive->exec("UPDATE invoice_number_counters SET last_number = 5");
    $r55rLive->exec('DROP TABLE invoice_counters');
    $r55rLive->exec('CREATE TABLE invoice_counters (year INTEGER NOT NULL, prefix TEXT NOT NULL)');
    $r55rLive = null;
    BackupService::reset();
    $r55rLive = new PDO('sqlite:' . $r55rLivePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0]);
    BackupService::init($r55rLive, $r55rCfg($r55rRoot . '/backups-series'), $r55rRoot);
    $r55rLive = null;
    $r55rRestored = BackupService::restore($r55rOld, ['skip_snapshot' => true]);
    $r55rAfter = new PDO('sqlite:' . $r55rLivePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assert_equals(5, (int)$r55rAfter->query("SELECT last_number FROM invoice_number_counters")->fetchColumn(),
        'R55 restore: the readable series continues after FA 5 when another counter table is unreadable (was: every series dropped, FA 4 issued again)');
    $r55rWarnings = implode(' | ', (array)($r55rRestored['numbering_warnings'] ?? []));
    assert_true(str_contains($r55rWarnings, 'invoice_counters') && str_contains($r55rWarnings, 'could not be read'),
        "R55 restore: the warning names the unreadable table ({$r55rWarnings})");
    $r55rAfter = null;
} catch (Throwable $e) {
    assert_true(false, 'R55 restore series (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    BackupService::reset();
    $r55rLive = null;
    gc_collect_cycles();
}

$r55rBusyPath = $r55rRoot . '/busy.db';
$r55rBusy = $r55rLiveDb($r55rBusyPath);
$r55rHolder = null;
try {
    BackupService::init($r55rBusy, $r55rCfg($r55rRoot . '/backups-busy'), $r55rRoot);
    $r55rBusyOld = (string)BackupService::create(['label' => 'scheduled'])['remote_path'];
    $r55rBusy->exec("UPDATE invoice_number_counters SET last_number = 5");
    // Another process writes: it holds the database lock for longer than the restore waits.
    $r55rHolder = new PDO('sqlite:' . $r55rBusyPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r55rHolder->exec('BEGIN EXCLUSIVE');
    $r55rStarted = microtime(true);
    try { $r55rBusyResult = BackupService::restore($r55rBusyOld, ['skip_snapshot' => true]); } catch (Throwable $e) { $r55rBusyResult = $e->getMessage(); }
    assert_true(is_string($r55rBusyResult) && str_contains($r55rBusyResult, 'stays locked'),
        'R55 restore: a lock on the live database that does not pass stops the restore while the number series are read (was: the series dropped with a warning): '
        . (is_string($r55rBusyResult) ? $r55rBusyResult : json_encode($r55rBusyResult['numbering_warnings'] ?? null)));
    assert_true(microtime(true) - $r55rStarted < 10, 'R55 restore: it waits for the lock a few times, not for ever');
    $r55rHolder->exec('ROLLBACK');
    $r55rHolder = null;
    $r55rBusy = null;
    BackupService::reset();
    $r55rCheck = new PDO('sqlite:' . $r55rBusyPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assert_equals(5, (int)$r55rCheck->query("SELECT last_number FROM invoice_number_counters")->fetchColumn(), 'R55 restore: the live database is left as it was');
    $r55rCheck = null;
} catch (Throwable $e) {
    assert_true(false, 'R55 restore busy (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    if ($r55rHolder !== null) { try { $r55rHolder->exec('ROLLBACK'); } catch (Throwable) {} }
    $r55rHolder = null;
    BackupService::reset();
    $r55rBusy = null;
    gc_collect_cycles();
    $r55rRm($r55rRoot);
}
