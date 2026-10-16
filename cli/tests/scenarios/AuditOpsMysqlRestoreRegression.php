<?php

// Ops review (MySQL backup/update): (1) the update snapshot compared a --single-transaction dump with
// row counts/CHECKSUM TABLE read from the LIVE database moments later, so any write in between
// (worker heartbeat) failed updates on live shops; (2) MySQL backups could not be restored by the
// CLI; (3) the companion .files.tar (invoices, uploads, GDPR salt) had no restore path; (4) an
// automatic update rollback discarded orders/payments written since the snapshot silently.
echo "\n--- Ops: MySQL snapshot consistency, verified MySQL/files restore, rollback data-loss report ---\n";
Bootstrap::initStoreContext('/', '', 'default');

$opsRm = static function (string $path) use (&$opsRm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $opsRm($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};
$opsRoot = test_fixture_path('ops-mysql-restore');
mkdir($opsRoot, 0700, true);
$opsError = static function (callable $callback): string {
    try {
        $callback();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
};

// ---------- (1) rows per table are counted from the dump itself ----------
$opsDump = $opsRoot . '/count.sql';
file_put_contents($opsDump, <<<'SQL'
-- MySQL dump
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (`id` int NOT NULL, `note` text) ENGINE=InnoDB;
INSERT INTO `orders` VALUES (1,'a),(b; c'),(2,'it\'s \\'),(3,'x''y'),(4,0xAB),(5,NULL);
INSERT INTO `orders` VALUES (6,'(');
INSERT INTO `odd``name` (`a`, `b`) VALUES (1,2),(3,4);
DELIMITER ;;
CREATE TRIGGER `orders_ai` AFTER INSERT ON `orders` FOR EACH ROW
INSERT INTO `orders` VALUES (99);;
DELIMITER ;
INSERT INTO `after_trigger` VALUES (1);

SQL);
assert_equals(
    ['after_trigger' => 1, 'odd`name' => 2, 'orders' => 6],
    MysqlUpdateDatabaseBackup::countDumpRows($opsDump),
    'Ops dump rows: tuples are counted through quotes, escapes, parentheses and semicolons in strings; trigger bodies are not data'
);
file_put_contents($opsDump, "INSERT INTO `orders` VALUES (1,'unterminated\n");
assert_true(str_contains($opsError(static fn() => MysqlUpdateDatabaseBackup::countDumpRows($opsDump)), 'truncated'), 'Ops dump rows: a dump that ends inside an INSERT is refused as truncated');

// Review follow-up: the scratch/probe import must stay inside the database it is imported into.
foreach ([
    'top-level USE' => "CREATE TABLE `t` (`id` int);\nUSE `shop`;\nDROP TABLE `orders`;\n",
    'CREATE DATABASE' => "-- MySQL dump\nCREATE DATABASE /*!32312 IF NOT EXISTS*/ `other` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;\n",
    'versioned DROP SCHEMA' => "/*!40000 DROP SCHEMA `shop` */;\n",
    'MariaDB versioned USE' => "/*M!100000 USE `shop` */;\n",
    'statement after a delimiter on the same line' => "SET @a = 1; use shop;\n",
    'client shell escape' => "CREATE TABLE `t` (`id` int);\n\\! touch /tmp/pwned\n",
    'client source command' => "source /etc/passwd\n",
] as $opsCase => $opsSql) {
    file_put_contents($opsDump, $opsSql);
    assert_true(str_contains($opsError(static fn() => MysqlUpdateDatabaseBackup::countDumpRows($opsDump)), 'refusing'), "Ops dump isolation: a dump with a {$opsCase} is refused");
}
file_put_contents($opsDump, "/*M!999999\\- enable the sandbox mode */ \n"
    . "-- USE `shop` in a comment\n/* CREATE DATABASE `x` in a block comment */\n"
    . "CREATE TABLE `use` (`id` int, `note` text) COMMENT='first line\nUSE `shop`;\nCREATE DATABASE x;';\n"
    . "INSERT INTO `use` VALUES (1,'USE `shop`;\nCREATE DATABASE `x`; \\\\! rm'),(2,'#not a comment');\n"
    . "DELIMITER ;;\nCREATE PROCEDURE `p`() BEGIN\n  SELECT 'use x';\nEND ;;\nDELIMITER ;\n");
assert_equals(['use' => 2], MysqlUpdateDatabaseBackup::countDumpRows($opsDump),
    'Ops dump isolation: USE/CREATE DATABASE text inside strings, comments, identifiers and routine bodies is data, and the mariadb-dump sandbox line is accepted');

// otherSessions() fails closed: an unreadable session list refuses the restore instead of counting 0.
$opsSessions = new ReflectionMethod(MysqlUpdateDatabaseBackup::class, 'otherSessions');
$opsSessionsError = $opsError(static fn() => $opsSessions->invoke(
    new MysqlUpdateDatabaseBackup('127.0.0.1', 3306, 'shop', 'shop', 'secret', 'utf8mb4', 'shop_update_probe'),
    new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), 'shop', []
));
assert_true(str_contains($opsSessionsError, 'cannot read the session list'), 'Ops MySQL restore: an unreadable session list refuses the restore (fail closed)');

// ---------- (3) files archive restore ----------
$opsPrivate = $opsRoot . '/private';
@mkdir($opsPrivate . '/invoices/2026/10', 0700, true);
@mkdir($opsPrivate . '/uploads/products', 0700, true);
@mkdir($opsPrivate . '/secrets', 0700, true);
file_put_contents($opsPrivate . '/invoices/2026/10/F1.pdf', '%PDF invoice F1');
file_put_contents($opsPrivate . '/uploads/products/shirt.jpg', 'ORIGINAL-JPEG');
file_put_contents($opsPrivate . '/secrets/gdpr_salt', str_repeat('s', 64));
$opsBackups = $opsRoot . '/backups';
$opsCfg = [
    'enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
    'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 1],
    'storage_dir' => $opsBackups,
    'files' => [
        'invoices'  => $opsPrivate . '/invoices',
        'uploads'   => $opsPrivate . '/uploads',
        'gdpr_salt' => $opsPrivate . '/secrets/gdpr_salt',
    ],
];
$opsSqlite = new PDO('sqlite:' . $opsRoot . '/live.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$opsSqlite->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
$opsTar = static function (array $entries): string {
    $tar = '';
    foreach ($entries as [$name, $type, $data]) {
        $header = str_pad($name, 100, "\0") . str_pad('0000644', 8, "\0") . str_pad('0000000', 8, "\0") . str_pad('0000000', 8, "\0")
            . str_pad(sprintf('%011o', strlen($data)), 12, "\0") . str_pad(sprintf('%011o', 0), 12, "\0") . '        ' . $type
            . str_pad($type === '2' ? '/etc/passwd' : '', 100, "\0") . "ustar\0" . '00' . str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
        $sum = 0;
        for ($i = 0; $i < 512; $i++) $sum += ord($header[$i]);
        $header = substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);
        $tar .= $header . $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
    }
    return $tar . str_repeat("\0", 1024);
};
try {
    BackupService::init($opsSqlite, $opsCfg, $opsRoot);
    $opsMeta = BackupService::create(['label' => 'manual']);
    $opsArchive = $opsBackups . '/' . $opsMeta['files_archive'];

    // Live drift after the backup: a lost invoice and salt, a changed upload, a new invoice.
    unlink($opsPrivate . '/invoices/2026/10/F1.pdf');
    unlink($opsPrivate . '/secrets/gdpr_salt');
    file_put_contents($opsPrivate . '/uploads/products/shirt.jpg', 'NEWER-JPEG');
    file_put_contents($opsPrivate . '/invoices/2026/10/F2.pdf', '%PDF invoice F2');

    $opsRefusal = $opsError(static fn() => BackupService::restoreFiles($opsMeta['remote_path']));
    assert_true(str_contains($opsRefusal, 'uploads/products/shirt.jpg') && str_contains($opsRefusal, '--force'), 'Ops files restore: a live file newer than the backup is never overwritten silently');
    assert_true(!is_file($opsPrivate . '/invoices/2026/10/F1.pdf') && !is_file($opsPrivate . '/secrets/gdpr_salt'), 'Ops files restore: a refused restore writes nothing at all');

    $opsRestored = BackupService::restoreFiles($opsMeta['remote_path'], ['force' => true]);
    assert_equals(3, $opsRestored['written'], 'Ops files restore: missing and differing files are written with --force');
    assert_equals(['uploads/products/shirt.jpg'], $opsRestored['overwritten'], 'Ops files restore: the overwritten live file is reported');
    assert_equals('%PDF invoice F1', (string)@file_get_contents($opsPrivate . '/invoices/2026/10/F1.pdf'), 'Ops files restore: a lost invoice PDF comes back');
    assert_equals('ORIGINAL-JPEG', (string)@file_get_contents($opsPrivate . '/uploads/products/shirt.jpg'), 'Ops files restore: uploads come back to the backed-up content');
    assert_equals(str_repeat('s', 64), (string)@file_get_contents($opsPrivate . '/secrets/gdpr_salt'), 'Ops files restore: the GDPR salt comes back to its configured path');
    assert_equals(0600, fileperms($opsPrivate . '/secrets/gdpr_salt') & 0777, 'Ops files restore: the restored GDPR salt is owner-only');
    assert_equals('%PDF invoice F2', (string)@file_get_contents($opsPrivate . '/invoices/2026/10/F2.pdf'), 'Ops files restore: live files absent from the backup are kept');
    $opsAgain = BackupService::restoreFiles($opsMeta['remote_path']);
    assert_equals([0, 3], [$opsAgain['written'], $opsAgain['unchanged']], 'Ops files restore: restoring again is a no-op without --force');

    // An archive whose bytes do not match its metadata is refused before extraction.
    $opsOriginalTar = (string)file_get_contents($opsArchive);
    file_put_contents($opsArchive, $opsOriginalTar . 'x');
    assert_true(str_contains($opsError(static fn() => BackupService::restoreFiles($opsMeta['remote_path'], ['force' => true])), 'mismatch'), 'Ops files restore: a tampered archive fails sha256/size verification');

    // Even an archive with matching metadata (attacker controls storage) cannot escape or plant links.
    $opsArchiveMetaPath = $opsArchive . '.meta.json';
    $opsArchiveMeta = json_decode((string)file_get_contents($opsArchiveMetaPath), true);
    $opsPlant = static function (string $tar) use ($opsArchive, $opsArchiveMetaPath, $opsArchiveMeta): void {
        file_put_contents($opsArchive, $tar);
        file_put_contents($opsArchiveMetaPath, json_encode(['sha256' => hash('sha256', $tar), 'size_bytes' => strlen($tar), 'entries' => 1] + $opsArchiveMeta));
    };
    foreach ([
        'parent traversal' => ['invoices/../../escape.txt', '0', 'escaped'],
        'absolute path' => [$opsRoot . '/absolute.txt', '0', 'escaped'],
        'symlink entry' => ['invoices/link.pdf', '2', ''],
        'hard link entry' => ['invoices/hard.pdf', '1', ''],
    ] as $opsCase => [$opsName, $opsType, $opsData]) {
        $opsPlant($opsTar([[$opsName, $opsType, $opsData]]));
        $opsMessage = $opsError(static fn() => BackupService::restoreFiles($opsMeta['remote_path'], ['force' => true]));
        assert_true($opsMessage !== '' && !str_contains($opsMessage, 'sha256'), "Ops files restore: archive with a {$opsCase} is refused by the extractor");
    }
    clearstatcache();
    assert_true(!file_exists($opsPrivate . '/escape.txt') && !file_exists($opsRoot . '/escape.txt') && !file_exists($opsRoot . '/absolute.txt')
        && !is_link($opsPrivate . '/invoices/link.pdf') && !file_exists($opsPrivate . '/invoices/hard.pdf'), 'Ops files restore: nothing was written outside the configured directories');

    $opsNoFilesCfg = $opsCfg;
    $opsNoFilesCfg['files'] = [];
    $opsNoFilesCfg['storage_dir'] = $opsRoot . '/backups-nofiles';
    BackupService::init($opsSqlite, $opsNoFilesCfg, $opsRoot);
    $opsNoFiles = BackupService::create(['label' => 'manual']);
    assert_true(str_contains($opsError(static fn() => BackupService::restoreFiles($opsNoFiles['remote_path'])), 'no files archive'), 'Ops files restore: a backup without a files archive is reported, not ignored');
} catch (Throwable $opsFailure) {
    assert_true(false, 'Ops files restore (' . $opsFailure->getMessage() . ')');
} finally {
    BackupService::reset();
}

// ---------- review follow-up: files restore safety, ownership, ordering ----------
$opsF = $opsRoot . '/follow-up';
$opsFPrivate = $opsF . '/private';
@mkdir($opsFPrivate . '/invoices/2026/10', 0750, true);
@mkdir($opsFPrivate . '/uploads/products', 0755, true);
@mkdir($opsF . '/outside', 0700, true);
chmod($opsFPrivate . '/uploads', 0755);
chmod($opsFPrivate . '/uploads/products', 0755);
file_put_contents($opsFPrivate . '/invoices/2026/10/F1.pdf', '%PDF F1');
file_put_contents($opsFPrivate . '/uploads/products/shirt.jpg', 'SHIRT');
file_put_contents($opsFPrivate . '/uploads/products/foto 10:30.jpg', 'FOTO');
@mkdir($opsFPrivate . '/uploads/gallery/2026', 0755, true);
file_put_contents($opsFPrivate . '/uploads/gallery/2026/a.jpg', 'GALLERY');
$opsFCfg = $opsCfg;
$opsFCfg['storage_dir'] = $opsF . '/backups';
$opsFCfg['files'] = ['invoices' => $opsFPrivate . '/invoices', 'uploads' => $opsFPrivate . '/uploads'];
$opsImmutable = null;
try {
    BackupService::init($opsSqlite, $opsFCfg, $opsRoot);
    $opsFMeta = BackupService::create(['label' => 'manual']);

    // ':' is legal in Linux filenames; one such upload must not abort the archive restore.
    unlink($opsFPrivate . '/uploads/products/foto 10:30.jpg');
    if (PHP_OS_FAMILY !== 'Windows') {
        $opsColon = $opsError(static fn() => BackupService::restoreFiles($opsFMeta['remote_path']));
        assert_true($opsColon === '' && (string)@file_get_contents($opsFPrivate . '/uploads/products/foto 10:30.jpg') === 'FOTO',
            'Ops files restore: an upload named with ":" is restored on Linux' . ($opsColon !== '' ? " ({$opsColon})" : ''));
    }

    // Mode (and, as root, owner/group) come from the replaced file, else from the directory.
    chmod($opsFPrivate . '/uploads/products/shirt.jpg', 0644);
    file_put_contents($opsFPrivate . '/uploads/products/shirt.jpg', 'NEWER SHIRT');
    $opsRunAsRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
    if ($opsRunAsRoot) {
        chown($opsFPrivate . '/uploads/products/shirt.jpg', 4321);
        chgrp($opsFPrivate . '/uploads/products/shirt.jpg', 4322);
        chown($opsFPrivate . '/uploads', 4401);
        chgrp($opsFPrivate . '/uploads', 4402);
    }
    unlink($opsFPrivate . '/uploads/gallery/2026/a.jpg');
    rmdir($opsFPrivate . '/uploads/gallery/2026');
    rmdir($opsFPrivate . '/uploads/gallery');
    BackupService::restoreFiles($opsFMeta['remote_path'], ['force' => true]);
    clearstatcache();
    assert_equals(0644, fileperms($opsFPrivate . '/uploads/products/shirt.jpg') & 0777, 'Ops files restore: an overwritten file keeps its mode');
    assert_equals(0644, fileperms($opsFPrivate . '/uploads/gallery/2026/a.jpg') & 0777, 'Ops files restore: a new file gets its directory mode without execute bits');
    assert_equals(0755, fileperms($opsFPrivate . '/uploads/gallery/2026') & 0777, 'Ops files restore: new directories copy the mode of the existing parent');
    if ($opsRunAsRoot) {
        assert_equals([4321, 4322], [fileowner($opsFPrivate . '/uploads/products/shirt.jpg'), filegroup($opsFPrivate . '/uploads/products/shirt.jpg')],
            'Ops files restore (root): an overwritten file keeps its owner and group');
        assert_equals([4401, 4402, 4401, 4402], [fileowner($opsFPrivate . '/uploads/gallery/2026/a.jpg'), filegroup($opsFPrivate . '/uploads/gallery/2026/a.jpg'),
            fileowner($opsFPrivate . '/uploads/gallery'), filegroup($opsFPrivate . '/uploads/gallery')],
            'Ops files restore (root): new files and directories take the owner and group of the existing parent, not root');
    } else {
        echo "  [SKIP] Ops files restore owner/group preservation (needs root)\n";
    }

    // A symlinked directory below the configured root is refused while planning: nothing is written anywhere.
    unlink($opsFPrivate . '/invoices/2026/10/F1.pdf');
    unlink($opsFPrivate . '/uploads/products/shirt.jpg');
    unlink($opsFPrivate . '/uploads/products/foto 10:30.jpg');
    rmdir($opsFPrivate . '/uploads/products');
    symlink($opsF . '/outside', $opsFPrivate . '/uploads/products');
    $opsSymlinked = $opsError(static fn() => BackupService::restoreFiles($opsFMeta['remote_path'], ['force' => true]));
    clearstatcache();
    assert_true(str_contains($opsSymlinked, 'is a symlink') && str_contains($opsSymlinked, 'nothing was written'),
        'Ops files restore: a symlinked directory below the configured root is refused');
    assert_true((scandir($opsF . '/outside') ?: []) === ['.', '..'] && !file_exists($opsFPrivate . '/invoices/2026/10/F1.pdf'),
        'Ops files restore: the refusal happens before any write (nothing outside, no other file restored)');
    unlink($opsFPrivate . '/uploads/products');

    // A write that still fails mid-way names the files already written.
    mkdir($opsFPrivate . '/uploads/products', 0755);
    if ($opsRunAsRoot) {
        // Root bypasses permissions: an immutable live file makes the final rename fail instead.
        file_put_contents($opsFPrivate . '/uploads/products/shirt.jpg', 'IMMUTABLE');
        exec('chattr +i ' . escapeshellarg($opsFPrivate . '/uploads/products/shirt.jpg') . ' 2>/dev/null', $opsOut, $opsCode);
        $opsImmutable = $opsCode === 0 ? $opsFPrivate . '/uploads/products/shirt.jpg' : null;
    } else {
        chmod($opsFPrivate . '/uploads/products', 0200); // writable but not searchable: planning passes, the write fails
        $opsImmutable = $opsFPrivate . '/uploads/products';
    }
    if ($opsImmutable !== null) {
        $opsMidway = $opsError(static fn() => BackupService::restoreFiles($opsFMeta['remote_path'], ['force' => true]));
        assert_true(str_contains($opsMidway, 'stopped at uploads/products/') && str_contains($opsMidway, 'already written: invoices/2026/10/F1.pdf'),
            'Ops files restore: a write failing mid-way reports which files were already written' . ($opsMidway === '' ? '' : " ({$opsMidway})"));
    } else {
        echo "  [SKIP] Ops files restore mid-way failure report (chattr unavailable)\n";
    }
} catch (Throwable $opsFailure) {
    assert_true(false, 'Ops files restore follow-up (' . $opsFailure->getMessage() . ')');
} finally {
    if ($opsImmutable !== null) {
        if ($opsRunAsRoot ?? false) exec('chattr -i ' . escapeshellarg($opsImmutable) . ' 2>/dev/null');
        if (is_dir($opsImmutable)) @chmod($opsImmutable, 0755);
    }
    BackupService::reset();
}

// --latest must never pick a .gz companion that shares its backup's creation stamp (storages that list it).
$opsGzStorage = new class implements BackupStorage {
    public function put(string $localPath, string $remotePath, array $meta): void {}
    public function get(string $remotePath, string $localPath): void {}
    public function delete(string $remotePath): void {}
    public function list(string $prefix = ''): array
    {
        $meta = ['created_us' => '1792143329215038', 'filename' => 'eshop-20261016-093529.sql', 'remote_path' => '2026/10/16/eshop-20261016-093529.sql'];
        return [
            ['path' => '2026/10/16/eshop-20261016-093529.sql.gz', 'mtime' => 1, 'meta' => ['content_encoding' => 'gzip'] + $meta],
            ['path' => '2026/10/16/eshop-20261016-093529.sql', 'mtime' => 1, 'meta' => $meta],
        ];
    }
};
try {
    BackupService::init($opsSqlite, $opsCfg, $opsRoot, $opsGzStorage);
    $opsRows = BackupService::list();
    assert_true($opsRows[0]['path'] === '2026/10/16/eshop-20261016-093529.sql' && BackupService::isCompressedCopy($opsRows[1]),
        'Ops restore --latest: the plain backup sorts before its .gz companion with the same created_us');
} finally {
    BackupService::reset();
}
$opsRestoreCli = (string)file_get_contents(dirname(__DIR__, 2) . '/restore.php');
assert_true(str_contains($opsRestoreCli, 'BackupService::isCompressedCopy($row)'), 'Ops restore --latest: the CLI filters gzip companions before picking the newest row');

// MySQL adapter construction is lazy: a configuration it cannot dump fails the backup, not init()
// (cli/migrate.php used to "continue without backup" when init() threw).
$opsApp = $opsRoot . '/lazy-app';
@mkdir($opsApp . '/config', 0700, true);
file_put_contents($opsApp . '/config.php', '<?php return ' . var_export([
    'db_driver' => 'mysql', 'db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => 'my-shop', 'db_user' => 'shop', 'db_password' => 'x',
    'backup_storage_dir' => $opsRoot . '/lazy-backups', 'backup_log_file' => $opsRoot . '/lazy-logs/backup.log',
    'invoice_storage_dir' => $opsRoot . '/lazy-invoices', 'uploads_dir' => $opsRoot . '/lazy-uploads', 'gdpr_salt_file' => $opsRoot . '/lazy-salt',
    'private_runtime_dir' => $opsRoot . '/lazy-private',
], true) . ";\n");
$opsLazyMysql = new class('sqlite:' . $opsRoot . '/lazy-stand-in.db') extends PDO {
    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : parent::getAttribute($attribute);
    }
};
try {
    $opsLazyInit = $opsError(static fn() => BackupService::init($opsLazyMysql, null, $opsApp));
    assert_equals('', $opsLazyInit, 'Ops MySQL backup: init() accepts a db_name the dump adapter rejects (construction is lazy)');
    assert_true(str_contains($opsError(static fn() => BackupService::create(['label' => 'pre-migration'])), 'cannot use this database configuration'),
        'Ops MySQL backup: the backup itself fails loudly for that configuration (a pre-migration backup hard-fails)');
} finally {
    BackupService::reset();
    $opsLazyMysql = null;
}
$opsMigrate = (string)file_get_contents(dirname(__DIR__, 2) . '/migrate.php');
assert_true(!str_contains($opsMigrate, 'continuing without backup') && str_contains($opsMigrate, 'Pre-migration backup unavailable'),
    'Ops migrate: a failing backup init stops the migration when pre-migration backups are enabled');

// ---------- (2) MySQL restore refuses without stopped writers ----------
$opsFakeMysql = new class('sqlite:' . $opsRoot . '/mysql-stand-in.db') extends PDO {
    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : parent::getAttribute($attribute);
    }
};
try {
    $opsMysqlCfg = $opsCfg;
    $opsMysqlCfg['files'] = [];
    $opsMysqlCfg['storage_dir'] = $opsRoot . '/backups-fake-mysql';
    BackupService::init($opsFakeMysql, $opsMysqlCfg, $opsRoot);
    BackupService::useMysqlDumper(static function (string $path): array {
        file_put_contents($path, "INSERT INTO `orders` VALUES (1);\n");
        return ['database' => 'shop', 'character_set' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];
    });
    $opsFakeMeta = BackupService::create(['label' => 'manual']);
    assert_equals(['utf8mb4', 'utf8mb4_unicode_ci'], [$opsFakeMeta['character_set'] ?? null, $opsFakeMeta['collation'] ?? null], 'Ops MySQL restore: backup metadata records the database character set and collation');
    assert_true(str_contains($opsError(static fn() => BackupService::restore($opsFakeMeta['remote_path'], ['skip_snapshot' => true])), '--i-stopped-writers'), 'Ops MySQL restore: refused unless the operator confirms writers are stopped');
    assert_true(str_contains($opsError(static fn() => BackupService::restore($opsFakeMeta['remote_path'], ['writers_stopped' => true])), 'MySQL/MariaDB installation'), 'Ops MySQL restore: refused without a configured MySQL restore adapter');
    assert_true(str_contains($opsError(static fn() => BackupService::restore($opsFakeMeta['remote_path'] . '.gz', ['writers_stopped' => true])), 'uncompressed'), 'Ops MySQL restore: a .sql.gz copy points to the verified .sql backup');
} catch (Throwable $opsFailure) {
    assert_true(false, 'Ops MySQL restore refusals (' . $opsFailure->getMessage() . ')');
} finally {
    BackupService::reset();
    $opsFakeMysql = null;
}

// ---------- (4) automatic update rollback reports discarded orders/payments ----------
try {
    $opsBase = $opsRoot . '/upd-base';
    $opsTarget = $opsRoot . '/upd-target';
    $opsInstalled = $opsRoot . '/upd-installed';
    foreach ([$opsBase, $opsTarget, $opsInstalled] as $directory) mkdir($directory . '/src', 0750, true);
    mkdir($opsTarget . '/migrations', 0750, true);
    file_put_contents($opsBase . '/src/Core.php', "before\n");
    file_put_contents($opsTarget . '/src/Core.php', "after\n");
    file_put_contents($opsTarget . '/migrations/20990101000002_ops.php', "<?php return new class {};\n");
    file_put_contents($opsInstalled . '/src/Core.php', "before\n");
    UpdatePackageBuilder::build($opsBase, $opsTarget, $opsRoot . '/upd-package', '4.1.0', '4.0.0');
    $opsPackage = new UpdatePackage($opsRoot . '/upd-package', null, true);

    $opsDbPath = $opsRoot . '/upd.sqlite';
    $opsUpdPdo = new PDO('sqlite:' . $opsDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $opsUpdPdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, note TEXT)');
    $opsUpdPdo->exec('CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY, note TEXT)');
    $opsUpdPdo->exec("INSERT INTO orders (note) VALUES ('before update')");
    $opsUpdPdo = null;
    $opsLiveWrites = 2;
    // A rollback hands the SQLite file back, so each apply gets a fresh updater and connection.
    $opsMakeUpdater = static function () use ($opsInstalled, $opsRoot, $opsDbPath, &$opsLiveWrites): Updater {
        return new Updater($opsInstalled, $opsRoot . '/upd-private', new PDO('sqlite:' . $opsDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), static function () use ($opsDbPath, &$opsLiveWrites): void {
            // Checkout and payment webhooks kept writing while the migration ran, then it failed.
            $writer = new PDO('sqlite:' . $opsDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            for ($i = 0; $i < $opsLiveWrites; $i++) $writer->exec("INSERT INTO orders (note) VALUES ('paid during update')");
            if ($opsLiveWrites > 0) $writer->exec("INSERT INTO payment_transactions (note) VALUES ('captured')");
            $writer = null;
            throw new RuntimeException('simulated ops migration failure');
        });
    };
    $opsUpdater = $opsMakeUpdater();
    $opsUpdater->baseline('4.0.0');
    $opsIncidents = static fn(): int => (int)Db::get()->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'update.rollback_discarded_writes'")->fetchColumn();
    $opsIncidentsBefore = $opsIncidents();

    $opsApplyError = $opsError(static fn() => $opsUpdater->apply($opsPackage));
    assert_true(str_contains($opsApplyError, 'simulated ops migration failure'), 'Ops update rollback: the migration failure is still surfaced');
    assert_true(str_contains($opsApplyError, 'discarded 2 orders row(s) and 1 payment_transactions row(s)'), 'Ops update rollback: the error names the orders and payments the automatic rollback discarded');
    assert_equals($opsIncidentsBefore + 1, $opsIncidents(), 'Ops update rollback: discarded writes raise a critical incident');
    $opsSnapshots = array_values(array_filter($opsUpdater->listSnapshots(), static fn(array $s): bool => ($s['status'] ?? '') === 'auto-rolled-back'));
    assert_equals(['orders' => 2, 'payment_transactions' => 1], $opsSnapshots[0]['discarded_writes'] ?? null, 'Ops update rollback: the snapshot records the discarded rows per table');
    assert_equals(['orders' => 1, 'payment_transactions' => 0], $opsSnapshots[0]['database']['high_water'] ?? null, 'Ops update rollback: the snapshot records MAX(id) high-water marks');
    $opsCheck = new PDO('sqlite:' . $opsDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assert_equals(1, (int)$opsCheck->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'Ops update rollback: the snapshot was restored (the loss is real, hence the report)');
    $opsCheck = null;

    $opsLiveWrites = 0;
    $opsUpdater = $opsMakeUpdater();
    $opsQuietError = $opsError(static fn() => $opsUpdater->apply($opsPackage));
    assert_equals('simulated ops migration failure', $opsQuietError, 'Ops update rollback: with no writes since the snapshot the error is unchanged');
    assert_equals($opsIncidentsBefore + 1, $opsIncidents(), 'Ops update rollback: no incident when nothing was discarded');
} catch (Throwable $opsFailure) {
    assert_true(false, 'Ops update rollback report (' . $opsFailure->getMessage() . ')');
}

// ---------- (1)+(2) live MySQL/MariaDB ----------
$opsMysqlEnabled = trim((string)(getenv('TEST_MYSQL_UPDATE_DATABASE') ?: '')) !== '';
if (!$opsMysqlEnabled) {
    if (getenv('BAREBONES_REQUIRE_MYSQL_UPDATE_TEST') === '1') {
        assert_true(false, 'Ops MySQL live: CI requires TEST_MYSQL_UPDATE_DATABASE');
    } else {
        echo "  [SKIP] Ops live MySQL/MariaDB snapshot + restore (set TEST_MYSQL_UPDATE_DATABASE and TEST_MYSQL_UPDATE_*)\n";
    }
} else {
    $opsHost = (string)(getenv('TEST_MYSQL_UPDATE_HOST') ?: '127.0.0.1');
    $opsPort = (int)(getenv('TEST_MYSQL_UPDATE_PORT') ?: 3306);
    $opsUser = (string)(getenv('TEST_MYSQL_UPDATE_USER') ?: 'root');
    $opsPass = (string)(getenv('TEST_MYSQL_UPDATE_PASS') ?: '');
    $opsDumpBin = (string)(getenv('TEST_MYSQL_DUMP_BINARY') ?: 'mysqldump');
    $opsClientBin = (string)(getenv('TEST_MYSQL_CLIENT_BINARY') ?: 'mysql');
    // Scratch names derive from the configured disposable database so parallel runs on one server do not collide.
    $opsTag = substr((string)preg_replace('/[^A-Za-z0-9_]/', '', (string)getenv('TEST_MYSQL_UPDATE_DATABASE')), 0, 24) . '_opsr' . bin2hex(random_bytes(2));
    $opsLive = $opsTag . '_live';
    $opsProbe = $opsTag . '_update_probe';
    $opsScratch = $opsTag . '_restore_scratch';
    $opsQ = static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`';
    $opsServer = new PDO("mysql:host={$opsHost};port={$opsPort};charset=utf8mb4", $opsUser, $opsPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $opsConnect = static fn(): PDO => new PDO("mysql:host={$opsHost};port={$opsPort};dbname={$opsLive};charset=utf8mb4", $opsUser, $opsPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $opsSchemaExists = static function (string $name) use ($opsServer): bool {
        $statement = $opsServer->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $statement->execute([$name]);
        return (int)$statement->fetchColumn() === 1;
    };
    // Wrapper binaries: run the real tool, then act like a live writer (or fail on demand).
    $opsScript = static function (string $name, string $body) use ($opsRoot): string {
        $path = $opsRoot . '/' . $name;
        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0700);
        return $path;
    };
    $opsDumpThen = static fn(string $name, string $sql): string => $opsScript($name,
        escapeshellarg($opsDumpBin) . ' "$@" || exit $?' . "\n"
        . 'exec ' . escapeshellarg($opsClientBin) . ' "$1" --protocol=TCP ' . escapeshellarg('--database=' . $opsLive) . ' -e ' . escapeshellarg($sql) . "\n");
    $opsBackup = static fn(string $dump = '', string $client = ''): MysqlUpdateDatabaseBackup => new MysqlUpdateDatabaseBackup(
        $opsHost, $opsPort, $opsLive, $opsUser, $opsPass, 'utf8mb4', $opsProbe, $dump ?: $opsDumpBin, $client ?: $opsClientBin
    );
    $opsRecoveryDir = $opsRoot . '/restore-recovery';
    $opsKeptDump = static fn(string $message): string => preg_match('/pre-restore dump kept at (\S+\.sql)/', $message, $m) === 1 ? $m[1] : '';
    // What the runbook tells the operator to do with a kept rollback dump: recreate db_name and import it.
    $opsManualImport = static function (string $dumpPath) use ($opsServer, $opsQ, $opsLive, $opsHost, $opsPort, $opsUser, $opsPass, $opsClientBin, &$opsLivePdo): void {
        if ($dumpPath === '') return;
        $opsServer->exec('DROP DATABASE IF EXISTS ' . $opsQ($opsLive));
        $opsServer->exec('CREATE DATABASE ' . $opsQ($opsLive) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $process = proc_open([$opsClientBin, '--protocol=TCP', '--host=' . $opsHost, '--port=' . $opsPort, '--user=' . $opsUser, '--password=' . $opsPass, '--database=' . $opsLive],
            [0 => ['file', $dumpPath, 'rb'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($process)) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        $opsLivePdo->exec('USE ' . $opsQ($opsLive));
    };
    try {
        $opsServer->exec('CREATE DATABASE ' . $opsQ($opsLive) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $opsLivePdo = $opsConnect();
        $opsLivePdo->exec('CREATE TABLE heartbeat (id INT AUTO_INCREMENT PRIMARY KEY, beat DATETIME NOT NULL) ENGINE=InnoDB');
        $opsLivePdo->exec('CREATE TABLE orders (id INT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(100) NOT NULL) ENGINE=InnoDB');
        $opsLivePdo->exec('CREATE TABLE order_audit (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL) ENGINE=InnoDB');
        $opsLivePdo->exec('CREATE TABLE schema_migrations (name VARCHAR(191) PRIMARY KEY) ENGINE=InnoDB');
        $opsLivePdo->exec('CREATE TRIGGER orders_ai AFTER INSERT ON orders FOR EACH ROW INSERT INTO order_audit (order_id) VALUES (NEW.id)');
        $opsLivePdo->exec('INSERT INTO heartbeat (beat) VALUES (NOW())');
        $opsInsert = $opsLivePdo->prepare('INSERT INTO orders (note) VALUES (?)');
        foreach (["it's (a); test", "back\\slash", "line\nbreak"] as $opsNote) $opsInsert->execute([$opsNote]);
        $opsLivePdo->exec("INSERT INTO schema_migrations (name) VALUES ('20260424000000_baseline')");

        // (1) A heartbeat between the dump and the verification no longer fails the snapshot.
        mkdir($opsRoot . '/snap-write', 0700);
        $opsSnapshot = $opsBackup($opsDumpThen('dump-then-write.sh', 'INSERT INTO heartbeat (beat) VALUES (NOW())'))->create($opsRoot . '/snap-write');
        assert_true(!empty($opsSnapshot['verified_restore']), 'Ops MySQL live: update snapshot succeeds although a worker wrote right after the dump');
        assert_equals(1, $opsSnapshot['inventory']['row_counts']['heartbeat'] ?? null, 'Ops MySQL live: snapshot row counts describe the dump instant, not the live table');
        assert_equals(2, (int)$opsLivePdo->query('SELECT COUNT(*) FROM heartbeat')->fetchColumn(), 'Ops MySQL live: the concurrent write really happened');
        assert_true(!$opsSchemaExists($opsProbe), 'Ops MySQL live: the probe database is dropped after verification');
        mkdir($opsRoot . '/snap-ddl', 0700);
        assert_true(str_contains($opsError(static fn() => $opsBackup($opsDumpThen('dump-then-ddl.sh', 'ALTER TABLE heartbeat ADD COLUMN extra INT NULL'))->create($opsRoot . '/snap-ddl')), 'schema changed'),
            'Ops MySQL live: DDL during the dump is refused (schema read before and after)');
        $opsLivePdo->exec('ALTER TABLE heartbeat DROP COLUMN extra');
        $opsLivePdo->exec('DELETE FROM heartbeat WHERE id > 1');

        // (2) Backup, drift, verified restore.
        $opsMysqlCfg = $opsCfg;
        $opsMysqlCfg['files'] = [];
        $opsMysqlCfg['storage_dir'] = $opsRoot . '/backups-mysql';
        BackupService::init($opsLivePdo, $opsMysqlCfg, $opsRoot);
        BackupService::useMysqlDatabase($opsBackup(), $opsScratch);
        $opsMysqlMeta = BackupService::create(['label' => 'manual']);
        $opsLivePdo->exec("INSERT INTO orders (note) VALUES ('written after the backup')");
        $opsLivePdo->exec('CREATE TABLE junk (id INT PRIMARY KEY) ENGINE=InnoDB');
        $opsDrifted = static fn(): bool => (int)$opsLivePdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 4
            && (int)$opsLivePdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'junk'")->fetchColumn() === 1;

        assert_true(str_contains($opsError(static fn() => BackupService::restore($opsMysqlMeta['remote_path'])), '--i-stopped-writers') && $opsDrifted(),
            'Ops MySQL live: restore without --i-stopped-writers is refused and changes nothing');

        assert_true(str_contains($opsError(static fn() => BackupService::restore($opsMysqlMeta['remote_path'], ['writers_stopped' => true, 'skip_snapshot' => true])), '--skip-snapshot') && $opsDrifted(),
            'Ops MySQL live: --skip-snapshot is refused for a MySQL restore (the rollback dump must never be the only copy)');

        $opsIntruder = $opsConnect();
        $opsIntruderError = $opsError(static fn() => BackupService::restore($opsMysqlMeta['remote_path'], ['writers_stopped' => true]));
        $opsIntruder = null;
        assert_true(str_contains($opsIntruderError, 'other session') && $opsDrifted() && !$opsSchemaExists($opsScratch),
            'Ops MySQL live: a visible writer session refuses the restore before live is touched; scratch is dropped');
        assert_equals([], glob($opsRecoveryDir . '/*.sql') ?: [], 'Ops MySQL live: a restore refused before live was dropped keeps no rollback dump around');
        usleep(300000);

        $opsFailMarker = $opsRoot . '/fail-live-import';
        touch($opsFailMarker);
        $opsLossMarker = $opsRoot . '/lose-a-row';
        $opsFailingClient = $opsScript('client-fail-live.sh',
            'for a in "$@"; do if [ "$a" = ' . escapeshellarg('--database=' . $opsLive) . ' ]; then' . "\n"
            . '  if [ -f ' . escapeshellarg($opsFailMarker) . ' ]; then [ -f ' . escapeshellarg($opsFailMarker . '-sticky') . ' ] || rm -f ' . escapeshellarg($opsFailMarker)
            . '; echo "simulated live import failure" >&2; exit 1; fi' . "\n"
            . '  if [ -f ' . escapeshellarg($opsLossMarker) . ' ]; then ' . escapeshellarg($opsClientBin) . ' "$@" || exit $?; exec ' . escapeshellarg($opsClientBin)
            . ' "$1" --protocol=TCP ' . escapeshellarg('--database=' . $opsLive) . ' -e ' . escapeshellarg('DELETE FROM orders ORDER BY id DESC LIMIT 1') . '; fi' . "\n"
            . 'fi; done' . "\n"
            . 'exec ' . escapeshellarg($opsClientBin) . ' "$@"' . "\n");
        BackupService::useMysqlDatabase($opsBackup('', $opsFailingClient), $opsScratch);
        $opsFailedRestore = $opsError(static fn() => BackupService::restore($opsMysqlMeta['remote_path'], ['writers_stopped' => true]));
        assert_true(str_contains($opsFailedRestore, 'previous database was re-imported and verified') && $opsDrifted(),
            'Ops MySQL live: a failed live import re-imports the pre-restore database automatically');
        $opsKept = $opsKeptDump($opsFailedRestore);
        assert_true($opsKept !== '' && is_file($opsKept), 'Ops MySQL live: the rollback dump is kept (path in the error) once live was dropped');
        if ($opsKept !== '') @unlink($opsKept);

        // Both the live import and the automatic re-import fail: the rollback dump is the way back and must survive.
        touch($opsFailMarker);
        touch($opsFailMarker . '-sticky');
        $opsBothFail = $opsError(static fn() => BackupService::restore($opsMysqlMeta['remote_path'], ['writers_stopped' => true]));
        @unlink($opsFailMarker . '-sticky');
        @unlink($opsFailMarker);
        $opsKept = $opsKeptDump($opsBothFail);
        clearstatcache();
        assert_true(str_contains($opsBothFail, 're-importing the previous database also failed') && str_contains($opsBothFail, 'import ' . $opsKept . ' manually'),
            'Ops MySQL live: when the re-import fails too, the error names the rollback dump to import');
        assert_true($opsKept !== '' && is_file($opsKept) && str_starts_with($opsKept, $opsRecoveryDir . '/'),
            'Ops MySQL live: that rollback dump still exists, in the durable private recovery directory');
        assert_equals(0600, $opsKept !== '' ? (fileperms($opsKept) & 0777) : null, 'Ops MySQL live: the kept rollback dump is owner-only');
        assert_equals(4, $opsKept !== '' ? (MysqlUpdateDatabaseBackup::countDumpRows($opsKept)['orders'] ?? null) : null, 'Ops MySQL live: the kept dump holds the pre-restore live rows');
        $opsManualImport($opsKept);
        assert_true($opsDrifted(), 'Ops MySQL live: importing the kept rollback dump by hand brings the pre-restore database back');
        if ($opsKept !== '') @unlink($opsKept);

        // The automatic re-import is verified, not assumed: a re-import that loses a row is reported as failed.
        touch($opsFailMarker);
        touch($opsLossMarker);
        $opsLossy = $opsError(static fn() => BackupService::restore($opsMysqlMeta['remote_path'], ['writers_stopped' => true]));
        @unlink($opsLossMarker);
        assert_true(str_contains($opsLossy, 'also failed') && str_contains($opsLossy, 'rollback re-import row counts do not match'),
            'Ops MySQL live: a rollback re-import that does not reproduce the pre-restore rows is not reported as success');
        $opsKept = $opsKeptDump($opsLossy);
        $opsManualImport($opsKept);
        assert_true($opsDrifted(), 'Ops MySQL live: the kept dump of that lossy rollback still recovers every row');
        if ($opsKept !== '') @unlink($opsKept);

        // A dump that is not confined to its database is refused before anything is imported.
        $opsEvil = $opsRoot . '/evil.sql';
        file_put_contents($opsEvil, "CREATE TABLE `t` (`id` int) ENGINE=InnoDB;\nUSE " . $opsQ($opsLive) . ";\nDROP TABLE `orders`;\n");
        $opsEvilRollback = $opsRoot . '/evil-rollback.sql';
        file_put_contents($opsEvilRollback, "-- rollback\n");
        $opsEvilError = $opsError(static fn() => $opsBackup()->restoreBackupDump($opsEvil, $opsScratch, $opsEvilRollback));
        assert_true(str_contains($opsEvilError, 'switches or changes databases') && $opsDrifted() && !$opsSchemaExists($opsScratch),
            'Ops MySQL live: a dump with a top-level USE is refused before the scratch import; live is untouched');
        file_put_contents($opsEvil, "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `elsewhere`;\n");
        assert_true(str_contains($opsError(static fn() => $opsBackup()->restoreBackupDump($opsEvil, $opsScratch, $opsEvilRollback)), 'switches or changes databases') && !$opsSchemaExists($opsScratch),
            'Ops MySQL live: a dump with CREATE DATABASE is refused before the scratch import');

        BackupService::useMysqlDatabase($opsBackup(), $opsScratch);
        $opsRestore = BackupService::restore($opsMysqlMeta['remote_path'], ['writers_stopped' => true]);
        assert_true(is_string($opsRestore['snapshot']) && str_ends_with($opsRestore['snapshot'], '.sql'), 'Ops MySQL live: a pre-restore snapshot dump is stored first');
        assert_equals(3, (int)$opsLivePdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'Ops MySQL live: rows written after the backup are gone (restored to the recovery point)');
        assert_equals(0, (int)$opsLivePdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'junk'")->fetchColumn(), 'Ops MySQL live: tables created after the backup are gone');
        assert_equals(["it's (a); test", "back\\slash", "line\nbreak"], $opsLivePdo->query('SELECT note FROM orders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'Ops MySQL live: restored strings are byte-identical');
        assert_equals(1, (int)$opsLivePdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()")->fetchColumn(), 'Ops MySQL live: triggers are restored');
        assert_equals(1, $opsRestore['applied_count'], 'Ops MySQL live: restore reports the restored schema_migrations');
        assert_true(!$opsSchemaExists($opsScratch), 'Ops MySQL live: the scratch database is dropped after a successful restore');

        $opsServer->exec('CREATE DATABASE ' . $opsQ($opsScratch));
        assert_true(str_contains($opsError(static fn() => BackupService::restore($opsMysqlMeta['remote_path'], ['writers_stopped' => true])), 'existing MySQL scratch database'),
            'Ops MySQL live: an existing scratch database is never overwritten');
        $opsServer->exec('DROP DATABASE ' . $opsQ($opsScratch));

        file_put_contents($opsMysqlCfg['storage_dir'] . '/' . $opsMysqlMeta['remote_path'], "-- tampered\n", FILE_APPEND);
        assert_true(str_contains($opsError(static fn() => BackupService::restore($opsMysqlMeta['remote_path'], ['writers_stopped' => true])), 'mismatch'),
            'Ops MySQL live: a tampered dump fails size/sha256 verification');
    } catch (Throwable $opsFailure) {
        assert_true(false, 'Ops MySQL live (' . $opsFailure->getMessage() . ')');
    } finally {
        BackupService::reset();
        $opsLivePdo = null;
        foreach ([$opsLive, $opsProbe, $opsScratch] as $opsName) {
            try { $opsServer->exec('DROP DATABASE IF EXISTS ' . $opsQ($opsName)); } catch (Throwable $e) { /* best effort */ }
        }
        $opsServer = null;
    }
}

$opsSqlite = null;
gc_collect_cycles();
$opsRm($opsRoot);
