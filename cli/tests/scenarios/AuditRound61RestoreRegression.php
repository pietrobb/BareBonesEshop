<?php

// Review round 61 (kolo 26), backups:
//  medium 1: stores.json was not set aside: a forged files backup restored onto a new server (no stores.json) or over a
//            differing one with --force put its legal.iban into every bank-transfer instruction; shipping.json (prices)
//            and the GDPR salt were restored in place the same way;
//  low 11:   a killed SQLite restore left shop.sqlite.restore-<hex>.tmp (a full copy) next to live, and a MySQL rollback
//            dump pre-restore-*.sql (personal data) stayed in backup_recovery_dir; nothing removed or reported them;
//  low 12:   the restore printed a count, not the files and where they belong; "written" counted the copies set aside;
//            pruneAsideRuns kept 5 runs, so the jobs a disaster recovery set aside vanished after five test restores.
echo "\n--- Audit round 61: stores.json, shipping.json and the GDPR salt set aside on restore; restore leftovers ---\n";
$r61rRoot = sys_get_temp_dir() . '/bbe-r61r-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($r61rRoot, 0700, true);
$r61rRm = static function (string $path) use (&$r61rRm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $r61rRm($path . '/' . $entry);
    }
    @rmdir($path);
};
$r61rShop = $r61rRoot . '/shop';
$r61rData = $r61rRoot . '/data';
@mkdir($r61rShop . '/themes/default', 0700, true);
@mkdir($r61rData . '/jobs/pending', 0700, true);
file_put_contents($r61rShop . '/config.php', "<?php return ['db_name' => 'r61'];");
$r61rStores = (string)json_encode(['stores' => ['default' => ['name' => 'Shop', 'legal' => ['iban' => 'DE89370400440532013000', 'bank' => 'Evil Bank']]]]);
$r61rShipping = '{"methods":{"standard":{"price":1}}}';
$r61rSalt = str_repeat('s', 64);
file_put_contents($r61rData . '/stores.json', $r61rStores);
file_put_contents($r61rData . '/' . DataOverrides::SHIPPING_METHODS, $r61rShipping);
file_put_contents($r61rData . '/gdpr_salt', $r61rSalt);
file_put_contents($r61rData . '/legal-keep.txt', 'x');
$r61rSources = BackupService::defaultFileSources(['private_runtime_dir' => $r61rData, 'jobs_dir' => $r61rData . '/jobs',
    'invoice_storage_dir' => $r61rData . '/invoices', 'uploads_dir' => $r61rShop . '/uploads', 'gdpr_salt_file' => $r61rData . '/gdpr_salt',
    'digital_assets_base_dir' => $r61rData . '/downloads'], $r61rShop);
$r61rDbPath = $r61rRoot . '/files.db';
$r61rPdo = new PDO('sqlite:' . $r61rDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r61rPdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
$r61rRecovery = $r61rRoot . '/recovery';
$r61rCfg = ['enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
    'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 5], 'storage_dir' => $r61rRoot . '/backups',
    'recovery_dir' => $r61rRecovery, 'files' => $r61rSources];
try {
    BackupService::init($r61rPdo, $r61rCfg, $r61rShop);
    $r61rPath = (string)BackupService::create(['label' => 'manual'])['remote_path'];

    // A new server: no stores.json, shipping.json or GDPR salt yet.
    foreach (['stores.json', DataOverrides::SHIPPING_METHODS, 'gdpr_salt'] as $r61rFile) unlink($r61rData . '/' . $r61rFile);
    $r61rFiles = BackupService::restoreFiles($r61rPath);
    $r61rAside = (string)($r61rFiles['aside_dir'] ?? '');
    clearstatcache();
    assert_true(!file_exists($r61rData . '/stores.json') && @file_get_contents($r61rAside . '/stores.json') === $r61rStores,
        'R61 restore: a stores.json the live shop lacks is set aside, not restored (its IBAN went into every bank-transfer instruction)');
    assert_true(!file_exists($r61rData . '/' . DataOverrides::SHIPPING_METHODS) && !file_exists($r61rData . '/gdpr_salt')
        && @file_get_contents($r61rAside . '/' . DataOverrides::SHIPPING_METHODS) === $r61rShipping && @file_get_contents($r61rAside . '/gdpr_salt') === $r61rSalt,
        'R61 restore: shipping.json and the GDPR salt the live shop lacks are set aside too');
    $r61rReported = (array)($r61rFiles['set_aside'] ?? []);
    sort($r61rReported);
    assert_equals(['gdpr_salt/gdpr_salt', 'shipping/' . DataOverrides::SHIPPING_METHODS, 'stores/stores.json'], $r61rReported,
        'R61 restore: the three are reported as set aside');
    assert_equals(['aside' => $r61rAside . '/stores.json', 'live' => $r61rData . '/stores.json'], $r61rFiles['destinations']['stores/stores.json'] ?? null,
        'R61 restore: each copy set aside comes with the path the shop reads it from');
    assert_equals(0, $r61rFiles['written'] ?? null, 'R61 restore: "written" counts only files written into the shop (was: also the copies set aside)');

    // A differing live stores.json is no conflict --force overwrites: it stays, the backup's goes aside.
    file_put_contents($r61rData . '/stores.json', '{"stores":{}}');
    $r61rForced = BackupService::restoreFiles($r61rPath, ['force' => true]);
    clearstatcache();
    assert_true((string)file_get_contents($r61rData . '/stores.json') === '{"stores":{}}'
        && @file_get_contents((string)($r61rForced['aside_dir'] ?? '') . '/stores.json') === $r61rStores
        && !in_array('stores/stores.json', (array)($r61rForced['overwritten'] ?? []), true),
        'R61 restore: a differing stores.json is set aside even with --force, never written over the live one');

    // Low 12: runs younger than 30 days stay, however many restores followed; older ones beyond the newest five go.
    $r61rFrom = BackupService::asideDirectory();
    $r61rOld = $r61rFrom . '/' . gmdate('Ymd-His', time() - 40 * 86400) . '-' . str_repeat('a', 12);
    $r61rRecent = $r61rFrom . '/' . gmdate('Ymd-His', time() - 86400) . '-' . str_repeat('b', 12);
    foreach ([$r61rOld, $r61rRecent] as $r61rRun) { mkdir($r61rRun . '/jobs/pending', 0700, true); file_put_contents($r61rRun . '/jobs/pending/j.json', '{}'); }
    for ($r61rI = 0; $r61rI < 6; $r61rI++) BackupService::restoreFiles($r61rPath, ['force' => true]);
    clearstatcache();
    assert_equals([false, true], [is_dir($r61rOld), is_file($r61rRecent . '/jobs/pending/j.json')],
        'R61 restore: copies set aside a day ago survive six more restores; a 40-day-old run beyond the newest five is removed');

    // Low 11: a killed SQLite restore's replacement copy is removed by the next restore; one just staged stays.
    $r61rStale = $r61rDbPath . '.restore-' . str_repeat('c', 12) . '.tmp';
    $r61rFresh = $r61rDbPath . '.restore-' . str_repeat('d', 12) . '.tmp';
    file_put_contents($r61rStale, 'copy');
    file_put_contents($r61rFresh, 'copy');
    touch($r61rStale, time() - 3600);
    @mkdir($r61rRecovery, 0700, true);
    $r61rDump = $r61rRecovery . '/pre-restore-shop-20261126-120000-abcd1234.sql';
    file_put_contents($r61rDump, '-- dump');
    assert_equals([$r61rDump, $r61rStale], BackupService::restoreLeftovers($r61rRecovery, $r61rDbPath),
        'R61 doctor: the leftover MySQL rollback dump and the stale SQLite replacement copy are listed (not the one of a running restore)');
    $r61rPdo = null;
    gc_collect_cycles();
    BackupService::restore($r61rPath, ['skip_snapshot' => true]);
    clearstatcache();
    assert_equals([false, true], [file_exists($r61rStale), file_exists($r61rFresh)],
        'R61 restore: a SQLite restore removes the replacement copy a killed restore left next to the database');
    $r61rChecks = Setup::doctor(['db_driver' => 'sqlite', 'db_sqlite_path' => $r61rDbPath, 'backup_recovery_dir' => $r61rRecovery], dirname(TEST_CLI_DIR))['checks'] ?? [];
    $r61rCheck = array_values(array_filter((array)$r61rChecks, static fn(array $c): bool => ($c['name'] ?? '') === 'backup.restore_leftovers'));
    assert_true(($r61rCheck[0]['status'] ?? '') === 'warn' && str_contains((string)($r61rCheck[0]['message'] ?? ''), $r61rDump),
        'R61 doctor: backup.restore_leftovers warns with the path of the rollback dump');
    @unlink($r61rFresh);
} catch (Throwable $e) {
    assert_true(false, 'R61 restore (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    BackupService::reset();
    $r61rPdo = null;
    gc_collect_cycles();
}
$r61rRm($r61rRoot);

foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $r61rLang) {
    $r61rStrings = require dirname(TEST_CLI_DIR) . '/lang/' . $r61rLang . '.php';
    assert_true(trim((string)($r61rStrings['admin.doctor.backup_restore_leftovers'] ?? '')) !== '', "R61 i18n {$r61rLang}: the dashboard names the restore-leftovers check");
}
$r61rCli = (string)file_get_contents(TEST_CLI_DIR . '/restore.php');
assert_true(str_contains($r61rCli, "\$files['destinations']") && str_contains($r61rCli, 'stores.json') && str_contains($r61rCli, 'set aside:'),
    'R61 restore: cli/restore.php lists each copy with its destination and counts the copies set aside');
