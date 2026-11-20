<?php

// Round 50 review (2026-11-20), backup and personal data:
//  1. restoring an older backup brought back lower invoice counters: the next invoice took a number already issued
//     and its PDF overwrote the other customer's, with no warning;
//  2. a restore onto a new (empty) MySQL/MariaDB server failed on connect (db_name did not exist);
//  3. config.php, the file job queue and the merchant's themes were in no backup;
//  4. after a GDPR erasure the phone stayed on the invoice, the /api/v1 replay cache kept the response with the
//     customer's data and the coupon counters kept an unsalted SHA-256 of the e-mail.
echo "\n--- Audit round 50: backup/restore numbering, empty MySQL restore, backup coverage, GDPR leftovers ---\n";

$r50bRoot = sys_get_temp_dir() . '/bbe-r50b-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($r50bRoot, 0700, true);
$r50bFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R50 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r50bRm = static function (string $path) use (&$r50bRm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $r50bRm($path . '/' . $entry);
    }
    @rmdir($path);
};
$r50bBackupCfg = static fn(string $dir, array $files = []): array => ['enabled' => true, 'storage' => 'local', 'compression' => false,
    'auto_gc_on_backup' => false, 'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 5],
    'storage_dir' => $dir, 'files' => $files];

// 1a. The number series of the replaced database continue after a restore of an older backup, with a warning.
$r50bLivePath = $r50bRoot . '/live.db';
$r50bLive = new PDO('sqlite:' . $r50bLivePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r50bLive->exec('CREATE TABLE schema_migrations (name TEXT PRIMARY KEY)');
$r50bLive->exec("CREATE TABLE invoice_number_counters (store_id TEXT NOT NULL, type TEXT NOT NULL DEFAULT 'invoice', year INTEGER NOT NULL,
    prefix TEXT NOT NULL DEFAULT '', last_number INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (store_id, type, year, prefix))");
$r50bLive->exec("CREATE TABLE invoice_counters (year INTEGER NOT NULL, prefix TEXT NOT NULL DEFAULT '', last_number INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (year, prefix))");
$r50bLive->exec("INSERT INTO invoice_number_counters VALUES ('default', 'invoice', 2026, 'FA', 3), ('default', 'invoice', 2025, 'FA', 40)");
$r50bLive->exec("INSERT INTO invoice_counters VALUES (2026, 'FA', 3)");
try {
    BackupService::init($r50bLive, $r50bBackupCfg($r50bRoot . '/backups'), $r50bRoot);
    $r50bOld = (string)BackupService::create(['label' => 'scheduled'])['remote_path'];
    // After the backup: invoices FA 4 and 5 and the first credit note were issued.
    $r50bLive->exec("UPDATE invoice_number_counters SET last_number = 5 WHERE year = 2026");
    $r50bLive->exec("UPDATE invoice_counters SET last_number = 5");
    $r50bLive->exec("INSERT INTO invoice_number_counters VALUES ('default', 'credit_note', 2026, 'FACN-', 1)");
    $r50bLive = null;
    $r50bRestored = BackupService::restore($r50bOld);
    $r50bAfter = new PDO('sqlite:' . $r50bLivePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r50bCounters = $r50bAfter->query("SELECT type || ':' || year || ':' || prefix AS k, last_number FROM invoice_number_counters ORDER BY k")->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_equals(['credit_note:2026:FACN-' => 1, 'invoice:2025:FA' => 40, 'invoice:2026:FA' => 5], array_map('intval', $r50bCounters),
        'R50 restore: the invoice and credit-note series continue after the last number issued before the restore');
    assert_equals(5, (int)$r50bAfter->query("SELECT last_number FROM invoice_counters WHERE year = 2026 AND prefix = 'FA'")->fetchColumn(),
        'R50 restore: the legacy per-(year, prefix) counter continues too');
    $r50bWarnings = (array)($r50bRestored['numbering_warnings'] ?? []);
    assert_true(count($r50bWarnings) === 2 && str_contains(implode("\n", $r50bWarnings), 'numbers 4–5') && str_contains(implode("\n", $r50bWarnings), 'continues at 6'),
        'R50 restore: the operator is warned which numbers were issued after the backup (' . implode(' | ', $r50bWarnings) . ')');
    $r50bAfter = null;
    $r50bCli = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/restore.php');
    assert_true(str_contains($r50bCli, "numbering_warnings"), 'R50 restore: cli/restore.php prints the numbering warnings');
} catch (Throwable $e) {
    $r50bFail('restore numbering', $e);
} finally {
    BackupService::reset();
    $r50bLive = null;
    gc_collect_cycles();
}

// 1b. Capability review: the counters are only carried over from the replaced database. A restore onto a lost, empty
//     or new database (nothing to carry over) numbered from the backup's lower counter and issued again the numbers
//     whose PDFs are in the invoice storage, in another month folder unnoticed. The storage is the second record.
$r50bNumDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r50bNumDb->exec("CREATE TABLE invoice_number_counters (store_id TEXT NOT NULL, type TEXT NOT NULL DEFAULT 'invoice', year INTEGER NOT NULL,
    prefix TEXT NOT NULL DEFAULT '', last_number INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (store_id, type, year, prefix))");
$r50bNumDb->exec("INSERT INTO invoice_number_counters VALUES ('default', 'invoice', 2091, 'FA', 3)");
$r50bNumDb->exec("CREATE TABLE invoice_counters (year INTEGER NOT NULL, prefix TEXT NOT NULL DEFAULT '', last_number INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (year, prefix))");
$r50bNumDb->exec("INSERT INTO invoice_counters VALUES (2091, 'LG', 3)");
$r50bYearDir = null;
try {
    $r50bStorageRoot = new ReflectionMethod('InvoiceService', 'storageDir');
    $r50bStorageRoot->setAccessible(true);
    $r50bYearDir = rtrim((string)$r50bStorageRoot->invoke(null), '/\\') . '/2091';
    // Issued after the backup: FA 4 in October, FA 5 in November; LG 4 (legacy series) in October.
    foreach (['10/FA20910004', '11/FA20910005', '10/LG20910004'] as $r50bIssued) {
        @mkdir(dirname($r50bYearDir . '/' . $r50bIssued), 0750, true);
        file_put_contents($r50bYearDir . '/' . $r50bIssued . '.pdf', '%PDF issued after the backup');
    }
    $r50bAlloc = new ReflectionMethod('InvoiceService', 'allocateNumber');
    $r50bAlloc->setAccessible(true);
    $r50bNext = (string)$r50bAlloc->invoke(null, $r50bNumDb, 'default', 'invoice', 2091, 'FA');
    assert_equals('FA20910006', $r50bNext,
        'R50 restore onto an empty database: the next number skips the numbers whose PDFs are in the invoice storage, in any month');
    assert_equals(6, (int)$r50bNumDb->query("SELECT last_number FROM invoice_number_counters WHERE year = 2091 AND prefix = 'FA'")->fetchColumn(),
        'R50 restore onto an empty database: the counter continues after the skipped numbers');
    $r50bLegacy = new ReflectionMethod('InvoiceService', 'allocateLegacyNumber');
    $r50bLegacy->setAccessible(true);
    assert_equals('LG20910005', (string)$r50bLegacy->invoke(null, $r50bNumDb, 2091, 'LG'),
        'R50 restore onto an empty database: the legacy per-(year, prefix) series skips an issued number too');
} catch (Throwable $e) {
    $r50bFail('numbering after a restore onto an empty database', $e);
} finally {
    if ($r50bYearDir !== null) $r50bRm($r50bYearDir);
    $r50bNumDb = null;
}

// 1c. Last line of defence: should a numbered document's PDF path still be taken, the file there is kept aside.
try {
    $r50bStorage = new ReflectionMethod('InvoiceService', 'storageDir');
    $r50bStorage->setAccessible(true);
    $r50bWrite = new ReflectionMethod('InvoiceService', 'writePdf');
    $r50bWrite->setAccessible(true);
    $r50bPdfDir = rtrim((string)$r50bStorage->invoke(null), '/\\') . '/r50b-' . bin2hex(random_bytes(3));
    $r50bPdf = $r50bPdfDir . '/FA2026005.pdf';
    $r50bWrite->invoke(null, $r50bPdf, '%PDF bob');
    $r50bWrite->invoke(null, $r50bPdf, '%PDF alice');
    $r50bAside = glob($r50bPdfDir . '/FA2026005.superseded-*.pdf') ?: [];
    assert_true((string)@file_get_contents($r50bPdf) === '%PDF alice' && count($r50bAside) === 1 && file_get_contents($r50bAside[0]) === '%PDF bob',
        'R50 restore: the PDF of the earlier document with the same number is kept as *.superseded-*.pdf');
    $r50bWrite->invoke(null, $r50bPdf, '%PDF alice again', false);
    assert_true(file_get_contents($r50bPdf) === '%PDF alice again' && count(glob($r50bPdfDir . '/*.superseded-*.pdf') ?: []) === 1,
        'R50 restore: re-rendering the PDF of an existing invoice replaces its own file');
    $r50bRm($r50bPdfDir);
} catch (Throwable $e) {
    $r50bFail('invoice PDF protection', $e);
}

// 2. Restore onto a new MySQL/MariaDB server: cli/restore.php creates the missing db_name.
assert_true(method_exists('Db', 'createMissingMysqlDatabase') && method_exists('Db', 'isUnknownDatabase'),
    'R50 empty MySQL: Db can create the missing database for a restore');
$r50bDsn = (string)(getenv('TEST_MYSQL_DSN') ?: '');
if ($r50bDsn !== '' && method_exists('Db', 'createMissingMysqlDatabase')) {
    preg_match('/host=([^;]+)/', $r50bDsn, $r50bHost);
    preg_match('/port=(\d+)/', $r50bDsn, $r50bPort);
    $r50bName = 'r50b_empty_' . bin2hex(random_bytes(4));
    $r50bConfig = $r50bRoot . '/mysql-config.php';
    file_put_contents($r50bConfig, '<?php return ' . var_export(['db_driver' => 'mysql', 'db_host' => $r50bHost[1] ?? '127.0.0.1',
        'db_port' => (int)($r50bPort[1] ?? 3306), 'db_name' => $r50bName, 'db_charset' => 'utf8mb4',
        'db_user' => (string)getenv('TEST_MYSQL_USER'), 'db_password' => (string)getenv('TEST_MYSQL_PASS')], true) . ';');
    $r50bShared = Db::get();
    $r50bServer = new PDO(preg_replace('/;?dbname=[^;]*/', '', $r50bDsn), (string)getenv('TEST_MYSQL_USER'), (string)getenv('TEST_MYSQL_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    try {
        Db::useConfig($r50bConfig);
        $r50bConnect = '';
        try { Db::get(); } catch (Throwable $e) { $r50bConnect = Db::isUnknownDatabase($e) ? 'unknown' : $e->getMessage(); }
        assert_equals('unknown', $r50bConnect, 'R50 empty MySQL: connecting to the missing db_name is recognized as "Unknown database"');
        assert_true(Db::createMissingMysqlDatabase() && Db::get()->query('SELECT DATABASE()')->fetchColumn() === $r50bName,
            'R50 empty MySQL: the empty database is created and the shop connects to it');
        assert_equals(false, Db::createMissingMysqlDatabase(), 'R50 empty MySQL: an existing database is left alone');
    } catch (Throwable $e) {
        $r50bFail('empty MySQL restore', $e);
    } finally {
        $r50bServer->exec('DROP DATABASE IF EXISTS `' . $r50bName . '`');
        Db::useConfig(AppConfig::path(dirname(TEST_CLI_DIR)));
        Db::init($r50bShared, (string)$r50bShared->getAttribute(PDO::ATTR_DRIVER_NAME));
    }
}

// 3. config.php, the jobs not done yet and the themes are backed up; a restore never writes over the live config.php,
//    a theme file the release changed or the queue of a shop that has already run (*.from-backup next to them).
$r50bShop = $r50bRoot . '/shop';
$r50bData = $r50bRoot . '/data';
foreach (['/themes/default', '/themes/mine'] as $r50bSub) @mkdir($r50bShop . $r50bSub, 0700, true);
foreach (['/jobs/pending', '/jobs/completed', '/invoices'] as $r50bSub) @mkdir($r50bData . $r50bSub, 0700, true);
file_put_contents($r50bShop . '/config.php', "<?php return ['db_name' => 'old_server'];");
file_put_contents($r50bShop . '/themes/default/style.css', 'body{color:red}');
file_put_contents($r50bShop . '/themes/mine/home.html', '<h1>Mine</h1>');
file_put_contents($r50bData . '/jobs/pending/mail-1.json', '{"type":"mail"}');
file_put_contents($r50bData . '/jobs/completed/mail-0.json', '{"type":"mail"}');
file_put_contents($r50bData . '/jobs/worker.lock', '');
$r50bSources = BackupService::defaultFileSources(['private_runtime_dir' => $r50bData, 'jobs_dir' => $r50bData . '/jobs',
    'invoice_storage_dir' => $r50bData . '/invoices', 'uploads_dir' => $r50bShop . '/uploads', 'gdpr_salt_file' => $r50bData . '/gdpr_salt',
    'digital_assets_base_dir' => $r50bData . '/downloads'], $r50bShop);
assert_equals([$r50bShop . '/config.php', $r50bData . '/jobs', $r50bShop . '/themes'],
    [$r50bSources['config'] ?? null, $r50bSources['jobs'] ?? null, $r50bSources['themes'] ?? null],
    'R50 backup: config.php, the job queue and the themes are backup sources');
$r50bPdo = new PDO('sqlite:' . $r50bRoot . '/files.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r50bPdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
try {
    BackupService::init($r50bPdo, $r50bBackupCfg($r50bRoot . '/file-backups', $r50bSources), $r50bShop);
    $r50bMeta = BackupService::create(['label' => 'manual']);
    $r50bTarPath = $r50bRoot . '/file-backups/' . $r50bMeta['remote_path'] . '.files.tar';
    $r50bTar = new PharData($r50bTarPath);
    $r50bEntries = [];
    foreach (new RecursiveIteratorIterator($r50bTar) as $r50bFile) {
        $r50bEntries[] = substr(str_replace('\\', '/', $r50bFile->getPathname()), strlen('phar://' . $r50bTarPath . '/'));
    }
    sort($r50bEntries);
    assert_true(in_array('config/config.php', $r50bEntries, true) && in_array('jobs/pending/mail-1.json', $r50bEntries, true)
        && in_array('themes/default/style.css', $r50bEntries, true) && in_array('themes/mine/home.html', $r50bEntries, true),
        'R50 backup: the archive holds config.php, the pending jobs and the themes (' . implode(', ', $r50bEntries) . ')');
    assert_true(!in_array('jobs/completed/mail-0.json', $r50bEntries, true) && !in_array('jobs/worker.lock', $r50bEntries, true),
        'R50 backup: completed jobs and the worker lock are left out');
    $r50bTar = null;

    // The same shop, later: a new server's config.php, an updated default theme, the custom theme lost, the queue drained.
    file_put_contents($r50bShop . '/config.php', "<?php return ['db_name' => 'new_server'];");
    file_put_contents($r50bShop . '/themes/default/style.css', 'body{color:blue}');
    unlink($r50bShop . '/themes/mine/home.html');
    unlink($r50bData . '/jobs/pending/mail-1.json');
    $r50bFiles = BackupService::restoreFiles((string)$r50bMeta['remote_path']);
    clearstatcache();
    assert_equals(["<?php return ['db_name' => 'new_server'];", "<?php return ['db_name' => 'old_server'];"],
        [file_get_contents($r50bShop . '/config.php'), @file_get_contents($r50bShop . '/config.php.from-backup')],
        'R50 restore: a differing config.php is written next to the live one, without --force');
    assert_equals(['body{color:blue}', 'body{color:red}', '<h1>Mine</h1>'], [file_get_contents($r50bShop . '/themes/default/style.css'),
        @file_get_contents($r50bShop . '/themes.from-backup/default/style.css'), @file_get_contents($r50bShop . '/themes/mine/home.html')],
        'R50 restore: a changed theme file is set aside, a missing one is restored in place');
    assert_true(!is_file($r50bData . '/jobs/pending/mail-1.json') && is_file($r50bData . '/jobs.from-backup/pending/mail-1.json'),
        'R50 restore: the jobs of a queue that has already run are set aside (they would be sent again)');
    assert_true(count((array)($r50bFiles['set_aside'] ?? [])) === 3, 'R50 restore: the files set aside are reported (' . json_encode($r50bFiles['set_aside'] ?? null) . ')');

    // A new server: the queue directory is empty, so the pending jobs go back in place.
    $r50bRm($r50bData . '/jobs');
    BackupService::restoreFiles((string)$r50bMeta['remote_path']);
    clearstatcache();
    assert_true(is_file($r50bData . '/jobs/pending/mail-1.json'), 'R50 restore: on a new server the pending jobs are restored into the queue');
} catch (Throwable $e) {
    $r50bFail('backup coverage', $e);
} finally {
    BackupService::reset();
    $r50bPdo = null;
    gc_collect_cycles();
}
$r50bRm($r50bRoot);

// 4. GDPR erasure leaves no phone on the invoices, no API response naming the customer and no unsalted e-mail hash.
$r50bDb = Db::get();
$r50bEmail = 'r50b-erase-' . bin2hex(random_bytes(3)) . '@example.test';
$r50bKeep = 'r50b-keep-' . bin2hex(random_bytes(3)) . '@example.test';
try {
    $r50bDb->prepare("INSERT INTO customers (email, password_hash, name, phone) VALUES (?, 'x', 'R50B Person', '+421900005050')")->execute([$r50bEmail]);
    $r50bCustomer = (int)$r50bDb->lastInsertId();
    $r50bDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, customer_phone, shipping_addr,
            payment_method, subtotal, total, currency, store_id, locale) VALUES (?, 'delivered', 'paid', ?, 'R50B Person', ?, '+421900005050', '{}', 'card', 1000, 1000, 'eur', 'default', 'sk')")
        ->execute(['R50B-' . bin2hex(random_bytes(3)), $r50bCustomer, $r50bEmail]);
    $r50bOrder = (int)$r50bDb->lastInsertId();
    $r50bPdfPath = 'r50b/R50B-INV-' . bin2hex(random_bytes(3)) . '.pdf';
    $r50bDb->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, due_date, pdf_path, total_net, total_vat, total_gross, currency, seller_snapshot,
            customer_snapshot, locale, status, download_token, type)
        VALUES (?, 'default', ?, '2026-11-20', '2026-12-04', ?, 813, 187, 1000, 'eur', '{}', ?, 'sk', 'issued', ?, 'invoice')")
        ->execute([$r50bOrder, 'R50B-INV-' . bin2hex(random_bytes(3)), $r50bPdfPath,
            json_encode(['name' => 'R50B Person', 'email' => $r50bEmail, 'phone' => '+421900005050', 'country' => 'SK']), InvoiceService::tokenHash(bin2hex(random_bytes(32)))]);
    $r50bInvoice = (int)$r50bDb->lastInsertId();
    $r50bStorageDir = new ReflectionMethod('InvoiceService', 'storageDir');
    $r50bStorageDir->setAccessible(true);
    $r50bPdfFile = rtrim((string)$r50bStorageDir->invoke(null), '/\\') . '/' . $r50bPdfPath;
    @mkdir(dirname($r50bPdfFile), 0750, true);
    file_put_contents($r50bPdfFile, '%PDF as sent');
    $r50bDb->prepare("INSERT INTO api_clients (key_id, token_hash, store_id, name, scopes) VALUES (?, ?, 'default', 'R50B ERP', '[]')")
        ->execute(['r50b-' . bin2hex(random_bytes(4)), hash('sha256', random_bytes(16))]);
    $r50bClient = (int)$r50bDb->lastInsertId();
    $r50bIdem = $r50bDb->prepare("INSERT INTO api_idempotency (store_id, client_id, idempotency_key, request_hash, response_status, response_body) VALUES ('default', ?, ?, ?, 201, ?)");
    $r50bIdem->execute([$r50bClient, 'r50b-erase', hash('sha256', 'a'), json_encode(['order' => ['customer_email' => strtoupper($r50bEmail), 'customer_phone' => '+421900005050']])]);
    $r50bIdem->execute([$r50bClient, 'r50b-keep', hash('sha256', 'b'), json_encode(['order' => ['customer_email' => $r50bKeep]])]);
    $r50bSubject = static fn(string $email): string => 'customer:' . hash('sha256', Pricing::normalizeEmail($email));
    $r50bCounter = $r50bDb->prepare("INSERT INTO coupon_usage_counters (store_id, coupon_code, subject, uses) VALUES (?, 'R50BCODE', ?, 1)");
    $r50bCounter->execute(['default', $r50bSubject($r50bEmail)]);
    $r50bCounter->execute(['*', $r50bSubject($r50bEmail)]);
    $r50bCounter->execute(['default', $r50bSubject($r50bKeep)]);
    $r50bCounter->execute(['default', '']);

    GdprService::anonymizeCustomer($r50bCustomer, 'admin');

    $r50bSnap = json_decode((string)$r50bDb->query("SELECT customer_snapshot FROM invoices WHERE id = {$r50bInvoice}")->fetchColumn(), true);
    assert_true(is_array($r50bSnap) && ($r50bSnap['phone'] ?? null) === '' && ($r50bSnap['name'] ?? '') === 'R50B Person',
        'R50 GDPR: the phone is erased from the invoice snapshot, the name stays for the accounting retention (' . json_encode($r50bSnap) . ')');
    assert_equals('%PDF as sent', @file_get_contents($r50bPdfFile), 'R50 GDPR: the issued PDF stays as it was sent (accounting record in its original form)');
    $r50bRm(dirname($r50bPdfFile));
    $r50bKeys = $r50bDb->query("SELECT idempotency_key FROM api_idempotency WHERE client_id = {$r50bClient} ORDER BY idempotency_key")->fetchAll(PDO::FETCH_COLUMN);
    assert_equals(['r50b-keep'], $r50bKeys, 'R50 GDPR: the API replay response naming the customer is deleted, another customer\'s stays');
    $r50bLeft = array_map(static fn(array $row): string => $row['store_id'] . '|' . $row['subject'],
        $r50bDb->query("SELECT store_id, subject FROM coupon_usage_counters WHERE coupon_code = 'R50BCODE'")->fetchAll(PDO::FETCH_ASSOC));
    sort($r50bLeft);
    $r50bExpected = ['default|', 'default|' . $r50bSubject($r50bKeep)];
    sort($r50bExpected);
    assert_equals($r50bExpected, $r50bLeft, 'R50 GDPR: the coupon counters keyed by the unsalted e-mail hash are deleted (store and shared), the others stay');
} catch (Throwable $e) {
    $r50bFail('GDPR erasure leftovers', $e);
} finally {
    $r50bDb->exec("DELETE FROM coupon_usage_counters WHERE coupon_code = 'R50BCODE'");
    if (isset($r50bClient)) $r50bDb->exec("DELETE FROM api_idempotency WHERE client_id = {$r50bClient}");
    if (isset($r50bClient)) $r50bDb->exec("DELETE FROM api_clients WHERE id = {$r50bClient}");
}
