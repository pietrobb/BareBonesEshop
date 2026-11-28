<?php

// Review round 27 (R62), backups (goal #660):
//  finding 9:  a restore onto a new server set the GDPR salt aside, and the first hashPii() made a fresh salt without a
//              word (the restored pseudonyms no longer matched, moving the copy in later orphaned the new ones); the doctor
//              said nothing about the copies and told the shop on the example stores to run setup.php apply;
//  finding 10: a legal text that differs from the backup was written over the live one with --force (rendered unescaped);
//  finding 11: the doctor missed a SQLite <db>.pre-restore-<hex>.rollback, a MySQL scratch database and the dumps in the
//              default recovery directory (backup_recovery_dir empty: BackupService uses <private_runtime_dir>/restore-recovery);
//  finding 12: cli/restore.php and the runbook named shipping.json, the copy is shipping-methods.json.
echo "\n--- Audit round 62: GDPR salt and stores.json after a restore onto a new server, legal texts, restore leftovers ---\n";
$r62rRoot = sys_get_temp_dir() . '/bbe-r62r-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($r62rRoot, 0700, true);
$r62rRm = static function (string $path) use (&$r62rRm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $r62rRm($path . '/' . $entry);
    }
    @rmdir($path);
};
$r62rShop = $r62rRoot . '/shop';
$r62rData = $r62rRoot . '/data';
$r62rLegal = $r62rData . '/legal/stores/default/sk/terms.html';
@mkdir($r62rShop . '/themes/default', 0700, true);
@mkdir($r62rData . '/jobs/pending', 0700, true);
@mkdir(dirname($r62rLegal), 0700, true);
file_put_contents($r62rShop . '/config.php', "<?php return ['db_name' => 'r62'];");
$r62rStores = (string)json_encode(['default_store' => 'default', 'stores' => ['default' => ['name' => 'Backup shop']]]);
$r62rSalt = str_repeat('a1', 32);
file_put_contents($r62rData . '/stores.json', $r62rStores);
file_put_contents($r62rData . '/' . DataOverrides::SHIPPING_METHODS, '{"methods":{}}');
file_put_contents($r62rData . '/gdpr_salt', $r62rSalt);
file_put_contents($r62rLegal, '<p>Terms</p><script src="/uploads/x.js"></script>');
$r62rAppCfg = ['private_runtime_dir' => $r62rData, 'jobs_dir' => $r62rData . '/jobs', 'invoice_storage_dir' => $r62rData . '/invoices',
    'uploads_dir' => $r62rShop . '/uploads', 'gdpr_salt_file' => $r62rData . '/gdpr_salt', 'digital_assets_base_dir' => $r62rData . '/downloads'];
$r62rDbPath = $r62rRoot . '/files.db';
$r62rPdo = new PDO('sqlite:' . $r62rDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r62rPdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
$r62rRecovery = $r62rRoot . '/recovery';
$r62rSaltOf = new ReflectionMethod(GdprService::class, 'saltOf');
$r62rSaltOf->setAccessible(true);
$r62rDoctor = static function (array $config): array {
    $checks = [];
    foreach ((array)(Setup::doctor($config + ['db_driver' => 'sqlite'], dirname(TEST_CLI_DIR))['checks'] ?? []) as $check) $checks[(string)$check['name']] = $check;
    return $checks;
};
try {
    BackupService::init($r62rPdo, ['enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
        'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 5], 'storage_dir' => $r62rRoot . '/backups',
        'recovery_dir' => $r62rRecovery, 'files' => BackupService::defaultFileSources($r62rAppCfg, $r62rShop)], $r62rShop);
    $r62rPath = (string)BackupService::create(['label' => 'manual'])['remote_path'];

    // Finding 10: a differing legal text stays, also with --force; the backup's goes aside.
    file_put_contents($r62rLegal, '<p>Live terms</p>');
    $r62rForced = BackupService::restoreFiles($r62rPath, ['force' => true]);
    clearstatcache();
    $r62rLegalCopy = (string)($r62rForced['aside_dir'] ?? '') . '/legal/stores/default/sk/terms.html';
    assert_true((string)file_get_contents($r62rLegal) === '<p>Live terms</p>' && str_contains((string)@file_get_contents($r62rLegalCopy), '<script')
        && !in_array('legal/stores/default/sk/terms.html', (array)($r62rForced['overwritten'] ?? []), true)
        && in_array('legal/stores/default/sk/terms.html', (array)($r62rForced['set_aside'] ?? []), true),
        'R62 restore: a legal text that differs from the backup is set aside even with --force, never written over the live one (was: overwritten)');

    // Finding 9: a new server — no salt, stores.json or shipping settings.
    foreach (['stores.json', DataOverrides::SHIPPING_METHODS, 'gdpr_salt'] as $r62rFile) unlink($r62rData . '/' . $r62rFile);
    $r62rFiles = BackupService::restoreFiles($r62rPath);
    $r62rAside = (string)($r62rFiles['aside_dir'] ?? '');
    clearstatcache();
    $r62rCfg = $r62rAppCfg + ['backup_recovery_dir' => $r62rRecovery];
    $r62rRefused = '';
    try {
        $r62rSaltOf->invoke(null, $r62rCfg);
    } catch (RuntimeException $e) {
        $r62rRefused = $e->getMessage();
    }
    clearstatcache();
    assert_true(str_contains($r62rRefused, $r62rAside . '/gdpr_salt') && str_contains($r62rRefused, 'mv ') && !file_exists($r62rData . '/gdpr_salt'),
        'R62 GDPR: with the backup\'s salt set aside and none in place, no new salt is made; the error names the copy and the move (was: a fresh salt, silently)');
    $r62rMissing = RestoreRecovery::missingLive($r62rRecovery, BackupService::defaultFileSources($r62rCfg, dirname(TEST_CLI_DIR)));
    assert_equals(['gdpr_salt', 'stores', 'shipping'], array_keys($r62rMissing), 'R62 restore: the salt, stores.json and shipping settings the shop lacks are found set aside');
    assert_equals(['copy' => $r62rAside . '/stores.json', 'live' => $r62rData . '/stores.json'], $r62rMissing['stores'] ?? null,
        'R62 restore: each with the copy and the path the shop reads it from');

    $r62rChecks = $r62rDoctor($r62rCfg);
    assert_true(($r62rChecks['backup.set_aside']['status'] ?? '') === 'warn'
        && str_contains((string)$r62rChecks['backup.set_aside']['message'], $r62rAside . '/gdpr_salt -> ' . $r62rData . '/gdpr_salt'),
        'R62 doctor: backup.set_aside warns with each copy and where it belongs (was: nothing)');
    $r62rStoresAction = (string)($r62rChecks['stores.file']['action'] ?? '');
    assert_true(str_contains($r62rStoresAction, 'mv ' . $r62rAside . '/stores.json ' . $r62rData . '/stores.json') && !str_starts_with($r62rStoresAction, 'Run php cli/setup.php apply'),
        'R62 doctor: on the example stores with the backup\'s stores.json set aside, it advises moving the copy in, not setup.php apply');

    rename($r62rAside . '/gdpr_salt', $r62rData . '/gdpr_salt');
    clearstatcache();
    assert_equals($r62rSalt, $r62rSaltOf->invoke(null, $r62rCfg), 'R62 GDPR: once moved in, the backup\'s salt is used');
    assert_true(!isset($r62rDoctor($r62rCfg)['backup.set_aside']) || !str_contains((string)$r62rDoctor($r62rCfg)['backup.set_aside']['message'], 'gdpr_salt'),
        'R62 doctor: a copy moved into place is no longer reported');

    // Finding 11: what a killed restore left, also in the default recovery directory.
    $r62rRollback = $r62rDbPath . '.pre-restore-' . str_repeat('e', 12) . '.rollback';
    file_put_contents($r62rRollback, 'live');
    file_put_contents($r62rRecovery . '/scratch-shop_restore_scratch.marker', "shop_restore_scratch\n");
    $r62rLeft = BackupService::restoreLeftovers($r62rRecovery, $r62rDbPath);
    assert_true(in_array($r62rRollback, $r62rLeft, true) && in_array('MySQL database shop_restore_scratch (marker ' . $r62rRecovery . '/scratch-shop_restore_scratch.marker)', $r62rLeft, true),
        'R62 doctor: the SQLite live database a killed restore moved aside and a MySQL scratch database are listed (was: not)');
    $r62rDefault = $r62rData . '/restore-recovery';
    @mkdir($r62rDefault, 0700, true);
    $r62rDump = $r62rDefault . '/pre-restore-shop-20261128-120000-abcd1234.sql';
    file_put_contents($r62rDump, '-- dump');
    assert_equals($r62rDefault, RestoreRecovery::directory($r62rAppCfg, $r62rShop), 'R62: the default recovery directory is <private_runtime_dir>/restore-recovery');
    $r62rDefaultChecks = $r62rDoctor($r62rAppCfg + ['db_sqlite_path' => $r62rDbPath]);
    assert_true(str_contains((string)($r62rDefaultChecks['backup.restore_leftovers']['message'] ?? ''), $r62rDump)
        && str_contains((string)($r62rDefaultChecks['backup.restore_leftovers']['message'] ?? ''), $r62rRollback),
        'R62 doctor: with backup_recovery_dir empty, the dumps in the default recovery directory are reported (was: none looked there)');
} catch (Throwable $e) {
    assert_true(false, 'R62 restore (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    BackupService::reset();
    $r62rPdo = null;
    gc_collect_cycles();
}
$r62rRm($r62rRoot);

foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $r62rLang) {
    $r62rStrings = require dirname(TEST_CLI_DIR) . '/lang/' . $r62rLang . '.php';
    assert_true(trim((string)($r62rStrings['admin.doctor.backup_set_aside'] ?? '')) !== '', "R62 i18n {$r62rLang}: the dashboard names the set-aside check");
}
$r62rCli = (string)file_get_contents(TEST_CLI_DIR . '/restore.php');
$r62rRunbook = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/docs/OPERATIONS_RUNBOOK.md');
assert_true(str_contains($r62rCli, DataOverrides::SHIPPING_METHODS) && !str_contains($r62rCli, 'shipping.json')
    && str_contains($r62rRunbook, '`shipping-methods.json`') && !str_contains($r62rRunbook, '`shipping.json`, the GDPR salt'),
    'R62 restore: cli/restore.php and the runbook name the copy shipping-methods.json (was: shipping.json)');
