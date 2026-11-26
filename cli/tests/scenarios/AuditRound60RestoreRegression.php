<?php

// Review round 60 (kolo 25), backups:
//  medium 1: mail.json was not set aside: a forged backup's mail.json restored onto a shop whose SMTP is in config.php sent
//            config.php's SMTP login and every password-reset link to the backup's server;
//  medium 2: the jobs of a backup went into an empty queue (a new server): a forged payment_refund refunded a delivered
//            order, email_send mailed a store-branded link anywhere and import_process (registered, never created) imported
//            any CSV.
echo "\n--- Audit round 60: mail.json and every job set aside on restore, no import_process handler ---\n";
$r60rRoot = sys_get_temp_dir() . '/bbe-r60r-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($r60rRoot, 0700, true);
$r60rRm = static function (string $path) use (&$r60rRm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $r60rRm($path . '/' . $entry);
    }
    @rmdir($path);
};
$r60rShop = $r60rRoot . '/shop';
$r60rData = $r60rRoot . '/data';
@mkdir($r60rShop . '/themes/default', 0700, true);
@mkdir($r60rData . '/jobs/pending', 0700, true);
file_put_contents($r60rShop . '/config.php', "<?php return ['db_name' => 'r60'];");
$r60rMail = (string)json_encode(['provider' => 'smtp', 'smtp' => ['host' => 'smtp.evil.example', 'port' => 587]]);
file_put_contents($r60rData . '/' . MailSettings::FILE, $r60rMail);
$r60rJobs = [
    'payment_refund-1.json' => '{"type":"payment_refund","payload":{"order_id":1}}',
    'email_send-1.json'     => '{"type":"email_send","payload":{"to":"victim@example.test","body":"https://evil.example/reset"}}',
    'import_process-1.json' => '{"type":"import_process","payload":{"file":"/tmp/forged.csv"}}',
];
foreach ($r60rJobs as $r60rName => $r60rJob) file_put_contents($r60rData . '/jobs/pending/' . $r60rName, $r60rJob);
$r60rSources = BackupService::defaultFileSources(['private_runtime_dir' => $r60rData, 'jobs_dir' => $r60rData . '/jobs',
    'invoice_storage_dir' => $r60rData . '/invoices', 'uploads_dir' => $r60rShop . '/uploads', 'gdpr_salt_file' => $r60rData . '/gdpr_salt',
    'digital_assets_base_dir' => $r60rData . '/downloads'], $r60rShop);
$r60rPdo = new PDO('sqlite:' . $r60rRoot . '/files.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r60rPdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
try {
    BackupService::init($r60rPdo, ['enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
        'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 5], 'storage_dir' => $r60rRoot . '/backups',
        'files' => $r60rSources], $r60rShop);
    $r60rPath = (string)BackupService::create(['label' => 'manual'])['remote_path'];

    // The shop the backup is restored onto: SMTP in config.php (no mail.json), a new server's empty queue.
    unlink($r60rData . '/' . MailSettings::FILE);
    $r60rRm($r60rData . '/jobs');
    $r60rFiles = BackupService::restoreFiles($r60rPath);
    $r60rAside = (string)($r60rFiles['aside_dir'] ?? '');
    clearstatcache();
    assert_true(!file_exists($r60rData . '/' . MailSettings::FILE) && @file_get_contents($r60rAside . '/' . MailSettings::FILE) === $r60rMail,
        'R60 restore: a mail.json the live shop lacks is set aside, not restored (it sent the SMTP login of config.php to its server)');
    $r60rLive = array_values(array_filter(array_keys($r60rJobs), static fn(string $n): bool => file_exists($r60rData . '/jobs/pending/' . $n)));
    $r60rSet = array_values(array_filter(array_keys($r60rJobs), static fn(string $n): bool => is_file($r60rAside . '/jobs/pending/' . $n)));
    assert_equals([[], array_keys($r60rJobs)], [$r60rLive, $r60rSet],
        'R60 restore: every job goes aside, also into an empty queue (forged payment_refund, email_send, import_process)');
    $r60rReported = (array)($r60rFiles['set_aside'] ?? []);
    sort($r60rReported);
    assert_equals(['jobs/pending/email_send-1.json', 'jobs/pending/import_process-1.json', 'jobs/pending/payment_refund-1.json', 'mail/' . MailSettings::FILE],
        $r60rReported, 'R60 restore: mail.json and the jobs are reported as set aside');

    // A differing live mail.json is no conflict that --force overwrites: it stays, the backup's goes aside.
    file_put_contents($r60rData . '/' . MailSettings::FILE, '{"provider":"log"}');
    $r60rForced = BackupService::restoreFiles($r60rPath, ['force' => true]);
    clearstatcache();
    assert_true((string)file_get_contents($r60rData . '/' . MailSettings::FILE) === '{"provider":"log"}'
        && @file_get_contents((string)($r60rForced['aside_dir'] ?? '') . '/' . MailSettings::FILE) === $r60rMail
        && !in_array('mail/' . MailSettings::FILE, (array)($r60rForced['overwritten'] ?? []), true),
        'R60 restore: a differing mail.json is set aside even with --force, never written over the live one');
} catch (Throwable $e) {
    assert_true(false, 'R60 restore (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    BackupService::reset();
    $r60rPdo = null;
    gc_collect_cycles();
}
$r60rRm($r60rRoot);

$r60rWorker = (string)file_get_contents(TEST_CLI_DIR . '/worker.php');
assert_true(!str_contains($r60rWorker, "'import_process'") && !method_exists(WorkerJobs::class, 'handleImportProcess'),
    'R60 worker: no import_process handler (nothing creates the job; a forged one imported any CSV)');
assert_true(str_contains((string)file_get_contents(TEST_CLI_DIR . '/restore.php'), 'mail.json'),
    'R60 restore: cli/restore.php names mail.json among the files set aside');
