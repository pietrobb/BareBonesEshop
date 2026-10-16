<?php

// Code review follow-up (infrastructure): import heartbeat outside the batch transaction, outbox and
// webhook-claim ownership guards, buffered backup archives that skip unreadable files, the
// maintenance-marker inode check, adapter contracts and the read-only Docker migration lock.
echo "\n--- CR follow-up: infrastructure (leases, ownership guards, backups, adapters) ---\n";
Bootstrap::initStoreContext('/', '', 'default');
$crfRoot = test_fixture_path('cr-followup-infra');
mkdir($crfRoot, 0777, true);
chmod($crfRoot, 0777);
$crfRm = static function (string $path) use (&$crfRm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    @chmod($path, 0700);
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $crfRm($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};
$crfDriver = Db::driver();

// 1. Import::csv() renews the worker lease only between batches. On the import's own connection a
//    renewal inside the open batch transaction holds the queue row lock until the commit and is
//    rolled back with the batch.
if (!class_exists('CrfImportPdo', false)) {
    final class CrfImportPdo extends PDO {
        public ?Closure $afterCommit = null;
        public function commit(): bool {
            $committed = parent::commit();
            if ($this->afterCommit) ($this->afterCommit)();
            return $committed;
        }
    }
}
$crfLeaseRenewedAt = new ReflectionProperty(JobQueue::class, 'leaseRenewedAt');
// The import runs on a copy of the suite database, so its connection never holds a lock on the suite's.
$crfImportDb = $crfRoot . '/import.sqlite';
$crfSuiteFile = '';
foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $crfRow) {
    if (($crfRow['name'] ?? '') === 'main') $crfSuiteFile = (string)$crfRow['file'];
}
$crfSuiteCopied = $crfDriver === 'sqlite' && $crfSuiteFile !== '' && copy($crfSuiteFile, $crfImportDb)
    && (!is_file($crfSuiteFile . '-wal') || copy($crfSuiteFile . '-wal', $crfImportDb . '-wal'));
$crfBackend = new class implements QueueBackend {
    /** @var list<bool> inTransaction() of the import connection at each renewal */
    public array $renewals = [];
    public int $loseAt = 0;
    public function name(): string { return 'crf-probe'; }
    public function push(string $type, array $payload, int $priority = 5): string { return ''; }
    public function pull(int $limit = 10): array { return []; }
    public function markProcessing(array $job): string { return ''; }
    public function complete(string $claim): void {}
    public function fail(string $claim, array $job, string $error): void {}
    public function extendLease(string $claim): bool {
        $this->renewals[] = Db::get()->inTransaction();
        return $this->loseAt === 0 || count($this->renewals) < $this->loseAt;
    }
    public function stats(): array { return []; }
    public function failures(int $limit = 20): array { return []; }
};
$crfCsv = static function (string $prefix, int $rows) use ($crfRoot): string {
    $lines = ["sku,name,price"];
    for ($i = 1; $i <= $rows; $i++) $lines[] = sprintf('%s-%04d,CR follow-up %s %d,1.00', $prefix, $i, $prefix, $i);
    file_put_contents($path = $crfRoot . '/' . $prefix . '.csv', implode("\n", $lines) . "\n");
    return $path;
};
if ($crfSuiteCopied) {
    $crfImportPdo = new CrfImportPdo('sqlite:' . $crfImportDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $crfImportPdo->exec('PRAGMA busy_timeout = 5000');
    $crfImportPdo->exec('PRAGMA foreign_keys = ON');
    // Make every heartbeat after a commit due, so the test does not wait out HEARTBEAT_SECONDS.
    $crfImportPdo->afterCommit = static fn() => $crfLeaseRenewedAt->setValue(null, 0);
    try {
        Db::init($crfImportPdo, 'sqlite');
        JobQueue::useBackend($crfBackend);
        JobQueue::beginWork('crf-import-claim');
        $crfLeaseRenewedAt->setValue(null, 0);
        $crfResult = Import::csv($crfCsv('CRF-IMP', 1001));
        assert_equals(1001, (int)($crfResult['imported'] ?? 0), 'CR follow-up import: a 1001-row CSV imports in three batches');
        assert_equals([false, false, false], $crfBackend->renewals, 'CR follow-up import: the lease is renewed before the first batch and after each batch commit, never inside the batch transaction');

        $crfBackend->renewals = [];
        $crfBackend->loseAt = 2;
        JobQueue::beginWork('crf-import-claim');
        $crfLeaseRenewedAt->setValue(null, 0);
        $crfLost = false;
        try { Import::csv($crfCsv('CRF-LOST', 700)); } catch (JobLeaseLost $e) { $crfLost = true; }
        assert_true($crfLost, 'CR follow-up import: a lease lost at a batch boundary aborts the import');
        assert_true(!$crfImportPdo->inTransaction(), 'CR follow-up import: the aborted import leaves no transaction open');
        assert_equals(500, (int)$crfImportPdo->query("SELECT COUNT(*) FROM products WHERE sku LIKE 'CRF-LOST-%'")->fetchColumn(), 'CR follow-up import: batches committed before the lease was lost stay imported');
    } finally {
        JobQueue::endWork();
        JobQueue::resetBackend();
        Db::init($pdo, $crfDriver);
        unset($crfImportPdo);
    }
}

// 2. Long worker jobs renew their lease around the slow external step (outside DB transactions).
$crfWorker = (string)file_get_contents(TEST_CLI_DIR . '/worker.php');
foreach (['handleSearchReindex', 'handlePaymentRefund', 'handleErpOrderPush', 'handleErpOrderUpdate', 'handleCleanup', 'handleImageResize', 'handleInvoiceIssue'] as $crfHandler) {
    preg_match('/function ' . $crfHandler . '\(.*?\n}\n/s', $crfWorker, $crfBody);
    assert_true(str_contains($crfBody[0] ?? '', 'JobQueue::heartbeat('), "CR follow-up worker: {$crfHandler} renews its job lease");
}

// 3. Backups: the files archive is written once (buffered) and an unreadable file or directory is
//    skipped and recorded instead of failing the whole backup. Root reads everything, so the
//    backup runs in a child process that drops to "nobody" when started as root.
$crfBk = $crfRoot . '/backup';
foreach (['/src/uploads/many', '/src/uploads/locked-dir', '/work'] as $crfDir) mkdir($crfBk . $crfDir, 0777, true);
for ($crfI = 0; $crfI < 300; $crfI++) file_put_contents($crfBk . "/src/uploads/many/f{$crfI}.txt", "file {$crfI}\n");
file_put_contents($crfBk . '/src/uploads/secret.jpg', 'unreadable');
file_put_contents($crfBk . '/src/uploads/locked-dir/inner.jpg', 'inside an unreadable directory');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($crfBk, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $crfEntry) {
    chmod($crfEntry->getPathname(), $crfEntry->isDir() ? 0755 : 0644);
}
chmod($crfBk, 0777);
chmod($crfBk . '/work', 0777);
chmod($crfBk . '/src/uploads/secret.jpg', 0000);
chmod($crfBk . '/src/uploads/locked-dir', 0000);
file_put_contents($crfBk . '/child.php', '<?php
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';
if (function_exists("posix_geteuid") && posix_geteuid() === 0) { posix_setgid(65534); posix_setuid(65534); }
$root = $argv[1];
$simulated = !is_readable($root . "/src/uploads/secret.jpg");
$pdo = new PDO("sqlite:" . $root . "/work/live.db", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE IF NOT EXISTS x (id INTEGER PRIMARY KEY)");
BackupService::init($pdo, [
    "enabled" => true, "compression" => false, "auto_gc_on_backup" => false,
    "retention" => ["daily" => 0, "weekly" => 0, "monthly" => 0, "minimum_kept" => 1],
    "storage_dir" => $root . "/work/backups", "log_file" => $root . "/work/backup.log",
    "files" => ["uploads" => $root . "/src/uploads"],
], $root . "/work");
echo json_encode(["simulated" => $simulated, "meta" => BackupService::create(["label" => "crf"])]);
');
$crfProc = proc_open([PHP_BINARY, $crfBk . '/child.php', $crfBk], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $crfPipes);
fclose($crfPipes[0]);
$crfOut = (string)stream_get_contents($crfPipes[1]);
$crfErr = (string)stream_get_contents($crfPipes[2]);
fclose($crfPipes[1]);
fclose($crfPipes[2]);
$crfExit = proc_close($crfProc);
$crfChild = json_decode($crfOut, true);
assert_true($crfExit === 0 && is_array($crfChild), 'CR follow-up backup: a backup with unreadable files completes (' . trim($crfErr . ' ' . substr($crfOut, 0, 300)) . ')');
if (is_array($crfChild) && ($crfChild['simulated'] ?? false)) {
    $crfMeta = (array)$crfChild['meta'];
    $crfSkipped = (array)($crfMeta['files_skipped'] ?? []);
    sort($crfSkipped);
    assert_equals(['uploads/locked-dir/', 'uploads/secret.jpg'], $crfSkipped, 'CR follow-up backup: unreadable files and directories are recorded in the backup manifest');
    $crfTarPath = $crfBk . '/work/backups/' . $crfMeta['files_archive'];
    $crfNames = [];
    foreach (new RecursiveIteratorIterator(new PharData($crfTarPath)) as $crfTarEntry) {
        $crfNames[] = substr($crfTarEntry->getPathname(), strlen('phar://' . $crfTarPath . '/'));
    }
    assert_equals(300, count(array_filter($crfNames, static fn(string $n): bool => str_starts_with($n, 'uploads/many/'))), 'CR follow-up backup: every readable file is archived');
    assert_true(!in_array('uploads/secret.jpg', $crfNames, true), 'CR follow-up backup: the unreadable file is not in the archive');
    assert_true(str_contains((string)@file_get_contents($crfBk . '/work/backup.log'), 'skipped unreadable file uploads/secret.jpg'), 'CR follow-up backup: a skipped file is logged as a warning');
}
assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/BackupService.php'), '$tar->startBuffering()'), 'CR follow-up backup: the files archive is buffered and written once');

// MySQL dump binaries fall back to the MariaDB names when only those are installed.
$crfBin = $crfRoot . '/bin';
mkdir($crfBin . '/mariadb-only', 0700, true);
mkdir($crfBin . '/both', 0700, true);
foreach (['mariadb-only/mariadb-dump', 'mariadb-only/mariadb', 'both/mysqldump', 'both/mariadb-dump'] as $crfTool) {
    file_put_contents($crfBin . '/' . $crfTool, "#!/bin/sh\n");
    chmod($crfBin . '/' . $crfTool, 0755);
}
assert_equals('mariadb-dump', BackupService::mysqlBinary('mysqldump', 'mysqldump', 'mariadb-dump', $crfBin . '/mariadb-only'), 'CR follow-up backup: mysqldump falls back to mariadb-dump when only that is on PATH');
assert_equals('mariadb', BackupService::mysqlBinary('mysql', 'mysql', 'mariadb', $crfBin . '/mariadb-only'), 'CR follow-up backup: mysql client falls back to mariadb');
assert_equals('mysqldump', BackupService::mysqlBinary('mysqldump', 'mysqldump', 'mariadb-dump', $crfBin . '/both'), 'CR follow-up backup: mysqldump is kept when it is installed');
assert_equals('/opt/custom/dump', BackupService::mysqlBinary('/opt/custom/dump', 'mysqldump', 'mariadb-dump', $crfBin . '/mariadb-only'), 'CR follow-up backup: an explicitly configured binary is used as is');

// 4. A stale maintenance marker is cleared only while the path is still the locked inode.
$crfSm = $crfRoot . '/maintenance.sqlite';
(new PDO('sqlite:' . $crfSm))->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
$crfMarker = SqliteMaintenance::markerPath($crfSm);
file_put_contents($crfMarker, '{}');
touch($crfMarker, time() - 60);
clearstatcache();
assert_true(!SqliteMaintenance::isActive($crfSm) && !is_file($crfMarker), 'CR follow-up maintenance: an unlocked stale marker is still cleared');
assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/SqliteMaintenance.php'), "\$opened['ino'] !== \$current['ino']"), 'CR follow-up maintenance: the marker is unlinked only when it is still the inode that was locked');

// 5. Outbox delivery outcomes are guarded by the claim: after a stale-lease takeover the slow first
//    deliverer cannot overwrite the state written by the worker that took the event over.
$crfEventId = str_repeat('f', 32);
$pdo->exec("DELETE FROM event_outbox WHERE id = '{$crfEventId}'");
$pdo->beginTransaction();
Outbox::record('test.crf_takeover', 'test', 1, [], 1, $crfEventId);
$pdo->commit();
$crfFirst = Outbox::claim($crfEventId);
$pdo->exec("UPDATE event_outbox SET updated_at = '" . gmdate('Y-m-d H:i:s', time() - 301) . "' WHERE id = '{$crfEventId}'");
$crfSecond = Outbox::claim($crfEventId);
assert_equals([1, 2], [(int)($crfFirst['attempts'] ?? 0), (int)($crfSecond['attempts'] ?? 0)], 'CR follow-up outbox: a stale delivery is taken over by a second claim');
assert_true(Outbox::markRetry($crfEventId, 2, 'second worker failed', 2), 'CR follow-up outbox: the owning claim records its outcome');
assert_true(!Outbox::markDelivered($crfEventId, 1), 'CR follow-up outbox: the slow first deliverer no longer owns the event');
assert_equals('retry', (string)$pdo->query("SELECT status FROM event_outbox WHERE id = '{$crfEventId}'")->fetchColumn(), 'CR follow-up outbox: the takeover\'s state is not overwritten');
$pdo->exec("UPDATE event_outbox SET available_at = '" . gmdate('Y-m-d H:i:s', time() - 1) . "' WHERE id = '{$crfEventId}'");
$crfThird = Outbox::claim($crfEventId);
assert_true(!Outbox::markRetry($crfEventId, 2, 'stale', 2), 'CR follow-up outbox: an outcome of an older claim is refused');
assert_true(Outbox::markDelivered($crfEventId, (int)($crfThird['attempts'] ?? 0)), 'CR follow-up outbox: the current claim completes delivery');
assert_true(!Outbox::markRetry($crfEventId, 3, 'late', 3), 'CR follow-up outbox: a delivered event cannot be reopened');
assert_equals('delivered', (string)$pdo->query("SELECT status FROM event_outbox WHERE id = '{$crfEventId}'")->fetchColumn(), 'CR follow-up outbox: the delivered state stands');
$pdo->exec("DELETE FROM event_outbox WHERE id = '{$crfEventId}'");

// 6. Idempotency::unmark() releases only the claim this process still owns, and claim() keeps
//    working (legacy semantics) before the claim-state migration has run.
$crfKey = 'crf_event_' . bin2hex(random_bytes(4));
assert_equals('claimed', Idempotency::claim($crfKey), 'CR follow-up idempotency: the handler claims the event');
// The handler ran past the lease and a redelivery (another process) took the claim over.
$pdo->prepare('UPDATE processed_events SET claimed_at = claimed_at + 400 WHERE event_id = ?')->execute([$crfKey]);
Idempotency::unmark($crfKey);
assert_equals('processing', (string)$pdo->query("SELECT state FROM processed_events WHERE event_id = " . $pdo->quote($crfKey))->fetchColumn(), 'CR follow-up idempotency: a timed-out handler cannot delete the takeover claim');
$pdo->prepare('DELETE FROM processed_events WHERE event_id = ?')->execute([$crfKey]);
assert_equals('claimed', Idempotency::claim($crfKey), 'CR follow-up idempotency: a fresh claim');
Idempotency::unmark($crfKey);
assert_equals(0, (int)$pdo->query("SELECT COUNT(*) FROM processed_events WHERE event_id = " . $pdo->quote($crfKey))->fetchColumn(), 'CR follow-up idempotency: the owner releases its own claim for a retry');

$crfLegacy = new PDO('sqlite:' . $crfRoot . '/legacy.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$crfLegacy->exec("CREATE TABLE processed_events (event_id TEXT PRIMARY KEY, created_at TEXT DEFAULT (datetime('now')))");
try {
    Db::init($crfLegacy, 'sqlite');
    $crfLegacyFirst = Idempotency::claim('crf_legacy');
    Idempotency::complete('crf_legacy');
    assert_equals(['claimed', 'done'], [$crfLegacyFirst, Idempotency::claim('crf_legacy')], 'CR follow-up idempotency: before the migration claims fall back to the legacy insert instead of failing every webhook');
    Idempotency::unmark('crf_legacy');
    assert_equals('claimed', Idempotency::claim('crf_legacy'), 'CR follow-up idempotency: legacy unmark releases the event for a retry');
} catch (Throwable $e) {
    assert_true(false, 'CR follow-up idempotency: legacy schema fallback ran without error (' . $e->getMessage() . ')');
} finally {
    Db::init($pdo, $crfDriver);
}

// 7. Adapter contracts: verifySignature() is part of PaymentAdapter, image variants are a capability.
assert_true((new ReflectionClass('PaymentAdapter'))->hasMethod('verifySignature'), 'CR follow-up adapters: PaymentAdapter declares verifySignature()');
assert_true(!(new CashOnDelivery([]))->verifySignature('{}', 'any'), 'CR follow-up adapters: cash on delivery rejects every webhook signature');
assert_true(is_subclass_of('LocalStorage', 'ImageVariantStorageAdapter'), 'CR follow-up adapters: LocalStorage advertises image variant generation');
$crfBaseline = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/phpstan-baseline.neon');
assert_true(!str_contains($crfBaseline, 'verifySignature') && !str_contains($crfBaseline, 'generateVariants'), 'CR follow-up adapters: the contract violations are no longer baselined');

// 8. Migrations lock: a read-only db/ (Docker code mount) falls back to the private runtime dir.
$crfApp = $crfRoot . '/app';
mkdir($crfApp . '/private', 0700, true);
file_put_contents($crfApp . '/db', 'not a directory: stands in for a read-only mount');
assert_equals($crfApp . '/private/' . Migrator::LOCK_FILENAME, Migrator::defaultLockFile($crfApp, ['private_runtime_dir' => $crfApp . '/private']), 'CR follow-up migrator: the lock moves to the private runtime dir when db/ is not writable');
assert_true(str_starts_with(Migrator::defaultLockFile($crfApp, []), rtrim(sys_get_temp_dir(), '/')), 'CR follow-up migrator: without a runtime dir the lock falls back to the temp dir');
unlink($crfApp . '/db');
mkdir($crfApp . '/db', 0700);
assert_equals($crfApp . '/db/' . Migrator::LOCK_FILENAME, Migrator::defaultLockFile($crfApp, ['private_runtime_dir' => $crfApp . '/private']), 'CR follow-up migrator: a writable db/ keeps the lock where it was');

$crfRm($crfRoot);
