<?php

// CR-M12 (code review 2026-10-15): backups covered only a local SQLite file. A MySQL shop got no
// backup at all ("live SQLite path unknown"), and invoices, uploads and the GDPR salt were never
// archived, so a restore could not bring back issued invoice PDFs, product images or the salt
// that pseudonymised GDPR records depend on.
echo "\n--- CR-M12: backups cover MySQL and invoices/uploads/GDPR salt ---\n";

$m12Rm = static function (string $path) use (&$m12Rm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $m12Rm($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};
$m12Root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbe-cr-m12-' . bin2hex(random_bytes(4));
$m12Private = $m12Root . '/private';
@mkdir($m12Private . '/invoices/2026/10', 0700, true);
@mkdir($m12Private . '/uploads/products', 0700, true);
@mkdir($m12Private . '/secrets', 0700, true);
file_put_contents($m12Private . '/invoices/2026/10/F2026001.pdf', '%PDF-1.4 invoice F2026001');
file_put_contents($m12Private . '/uploads/products/shirt.jpg', 'JPEGDATA');
file_put_contents($m12Private . '/secrets/gdpr_salt', str_repeat('s', 64));
$m12Backups = $m12Root . '/backups';
$m12Cfg = [
    'enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
    'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 1],
    'storage_dir' => $m12Backups,
    'files' => [
        'invoices'  => $m12Private . '/invoices',
        'uploads'   => $m12Private . '/uploads',
        'gdpr_salt' => $m12Private . '/secrets/gdpr_salt',
        'media'     => $m12Private . '/does-not-exist',
    ],
];

$m12Pdo = new PDO('sqlite:' . $m12Root . '/live.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$m12Pdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
try {
    BackupService::init($m12Pdo, $m12Cfg, $m12Root);
    $m12Older = BackupService::create(['label' => 'scheduled']);
    $m12Meta = BackupService::create(['label' => 'scheduled']);
    $m12Archive = (string)($m12Meta['files_archive'] ?? '');
    assert_equals($m12Meta['remote_path'] . '.files.tar', $m12Archive, 'CR-M12: a SQLite backup records its files archive');
    $m12ArchiveAbs = $m12Backups . '/' . $m12Archive;
    assert_true($m12Archive !== '' && is_file($m12ArchiveAbs), 'CR-M12: the files archive is stored next to the database backup');
    $m12Read = static function (string $entry) use ($m12ArchiveAbs): ?string {
        if (!is_file($m12ArchiveAbs)) return null;
        $content = @file_get_contents('phar://' . str_replace('\\', '/', $m12ArchiveAbs) . '/' . $entry);
        return $content === false ? null : $content;
    };
    assert_equals('%PDF-1.4 invoice F2026001', $m12Read('invoices/2026/10/F2026001.pdf'), 'CR-M12: issued invoice PDFs are in the backup');
    assert_equals('JPEGDATA', $m12Read('uploads/products/shirt.jpg'), 'CR-M12: uploads are in the backup');
    assert_equals(str_repeat('s', 64), $m12Read('gdpr_salt/gdpr_salt'), 'CR-M12: the GDPR salt file is in the backup');
    assert_equals('missing', $m12Meta['files']['media'] ?? null, 'CR-M12: a configured but absent source is recorded, not silently dropped');
    $m12ArchiveMeta = json_decode((string)@file_get_contents($m12ArchiveAbs . '.meta.json'), true) ?: [];
    assert_true(is_file($m12ArchiveAbs) && hash_equals((string)($m12ArchiveMeta['sha256'] ?? ''), (string)hash_file('sha256', $m12ArchiveAbs)), 'CR-M12: the files archive carries a verifiable sha256');

    clearstatcache();
    $m12Paths = array_map(static fn(array $row): string => (string)$row['path'], BackupService::list());
    assert_equals([$m12Meta['remote_path'], $m12Older['remote_path']], $m12Paths, 'CR-M12: list() shows restore points only, not their files archives');
    BackupService::gc();
    clearstatcache();
    assert_true(!is_file($m12Backups . '/' . $m12Older['remote_path'] . '.files.tar'), 'CR-M12: retention GC deletes the files archive with its backup');
    assert_true(is_file($m12ArchiveAbs), 'CR-M12: retention GC keeps the files archive of a kept backup');
} catch (Throwable $m12Error) {
    assert_true(false, 'CR-M12: SQLite backup with files archive (' . $m12Error->getMessage() . ')');
} finally {
    BackupService::reset();
}

// MySQL: same create() path, the snapshot step is a single-transaction dump.
$m12Mysql = new class('sqlite:' . $m12Root . '/mysql-stand-in.db') extends PDO {
    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : parent::getAttribute($attribute);
    }
};
$m12Mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$m12Dump = "-- MySQL dump\nCREATE TABLE `orders` (`id` int);\nINSERT INTO `orders` VALUES (1);\n";
try {
    $m12MysqlCfg = $m12Cfg;
    $m12MysqlCfg['files'] = [];
    $m12MysqlCfg['storage_dir'] = $m12Root . '/backups-mysql';
    BackupService::init($m12Mysql, $m12MysqlCfg, $m12Root);
    if (method_exists('BackupService', 'useMysqlDumper')) {
        BackupService::useMysqlDumper(static function (string $path) use ($m12Dump): array {
            file_put_contents($path, $m12Dump);
            return ['database' => 'shop'];
        });
    }
    $m12MysqlMeta = BackupService::create(['label' => 'scheduled']);
    assert_true(str_ends_with((string)$m12MysqlMeta['remote_path'], '.sql'), 'CR-M12: a MySQL shop gets a .sql dump backup');
    assert_equals('mysql', $m12MysqlMeta['driver'] ?? null, 'CR-M12: MySQL backup metadata names the driver');
    $m12MysqlFile = $m12Root . '/backups-mysql/' . $m12MysqlMeta['remote_path'];
    assert_equals($m12Dump, (string)@file_get_contents($m12MysqlFile), 'CR-M12: the stored MySQL dump is the dump content');
    assert_equals(hash('sha256', $m12Dump), $m12MysqlMeta['sha256'] ?? null, 'CR-M12: the MySQL dump checksum is recorded');
    $m12RestoreError = '';
    try {
        BackupService::restore($m12MysqlMeta['remote_path'], ['skip_snapshot' => true]);
    } catch (RuntimeException $e) {
        $m12RestoreError = $e->getMessage();
    }
    assert_true(str_contains($m12RestoreError, '--i-stopped-writers'), 'CR-M12: restoring a MySQL dump is refused until writers are confirmed stopped');
} catch (Throwable $m12Error) {
    assert_true(false, 'CR-M12: MySQL backup is created (' . $m12Error->getMessage() . ')');
} finally {
    BackupService::reset();
    $m12Pdo = null;
    $m12Mysql = null;
    gc_collect_cycles();
    $m12Rm($m12Root);
}
