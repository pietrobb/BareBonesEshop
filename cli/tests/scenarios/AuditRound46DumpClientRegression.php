<?php

// Review round 46 (#36): a MySQL 8 shop with MariaDB's client tools (mariadb-dump, or Debian's mysqldump, which is
// MariaDB's) could not update or back up: mariadb-dump writes the values of generated columns (categories.parent_id_uniq)
// into its INSERTs and MySQL refuses them on restore (ERROR 3105). R17 only refused such a client. The shop's dumps now
// write DEFAULT for generated values; doctor/check warn about the client instead of refusing.

echo "\n--- Audit round 46: MySQL 8 update/backup with MariaDB's dump client ---\n";

// 1. The rewrite: only generated positions of the listed tables, only data INSERTs, strings and comments untouched.
$r46dDir = test_fixture_path('r46-dump-client');
@mkdir($r46dDir, 0700, true);
$r46dDump = $r46dDir . '/dump.sql';
$r46dIn = <<<'SQL'
/*M!999999\- enable the sandbox mode */
-- MariaDB dump 10.19  Distrib 11.8.9-MariaDB
/*!40101 SET NAMES utf8mb4 */;
CREATE TABLE `categories` (
  `id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `slug` varchar(64) DEFAULT NULL,
  `parent_id_uniq` int GENERATED ALWAYS AS (coalesce(`parent_id`,0)) STORED,
  PRIMARY KEY (`id`)
);
INSERT INTO `categories` VALUES
(1,NULL,'a, (b)',0),
(2,1,'it\'s 1,2)',1);
INSERT INTO `categories` VALUES (3,2,'c',2),(4,NULL,'d',0);
INSERT INTO `categories` (`id`, `parent_id`, `slug`, `parent_id_uniq`) VALUES (5,NULL,'e',0);
INSERT INTO `invoices` VALUES (7,'invoice',42,42),(8,'credit_note',42,NULL);
INSERT INTO `products` VALUES (1,'(0)',3);
DELIMITER ;;
CREATE TRIGGER t AFTER INSERT ON products FOR EACH ROW BEGIN INSERT INTO `categories` VALUES (9,NULL,'t',0); END ;;
DELIMITER ;
SQL;
$r46dOut = <<<'SQL'
/*M!999999\- enable the sandbox mode */
-- MariaDB dump 10.19  Distrib 11.8.9-MariaDB
/*!40101 SET NAMES utf8mb4 */;
CREATE TABLE `categories` (
  `id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `slug` varchar(64) DEFAULT NULL,
  `parent_id_uniq` int GENERATED ALWAYS AS (coalesce(`parent_id`,0)) STORED,
  PRIMARY KEY (`id`)
);
INSERT INTO `categories` VALUES
(1,NULL,'a, (b)',DEFAULT),
(2,1,'it\'s 1,2)',DEFAULT);
INSERT INTO `categories` VALUES (3,2,'c',DEFAULT),(4,NULL,'d',DEFAULT);
INSERT INTO `categories` (`id`, `parent_id`, `slug`, `parent_id_uniq`) VALUES (5,NULL,'e',DEFAULT);
INSERT INTO `invoices` VALUES (7,'invoice',42,DEFAULT),(8,'credit_note',42,DEFAULT);
INSERT INTO `products` VALUES (1,'(0)',3);
DELIMITER ;;
CREATE TRIGGER t AFTER INSERT ON products FOR EACH ROW BEGIN INSERT INTO `categories` VALUES (9,NULL,'t',0); END ;;
DELIMITER ;
SQL;
file_put_contents($r46dDump, $r46dIn . "\n");
$r46dRows = MysqlUpdateDatabaseBackup::countDumpRows($r46dDump);
$r46dReplaced = MysqlUpdateDatabaseBackup::defaultGeneratedValues($r46dDump, ['categories' => [3], 'invoices' => [3]]);
assert_equals([7, $r46dOut . "\n"], [$r46dReplaced, (string)file_get_contents($r46dDump)],
    'R46 dump client: generated values become DEFAULT in every INSERT form; strings, other tables and trigger bodies are untouched');
assert_equals($r46dRows, MysqlUpdateDatabaseBackup::countDumpRows($r46dDump), 'R46 dump client: the rewritten dump carries the same rows');
assert_equals(0, MysqlUpdateDatabaseBackup::defaultGeneratedValues($r46dDump, ['orders' => [0]]), 'R46 dump client: a dump without the tables is left alone');
assert_equals([true, false, false], [
    MysqlUpdateDatabaseBackup::clientWritesGeneratedValues('mariadb-dump from 11.8.9-MariaDB, client 10.20', '8.4.13'),
    MysqlUpdateDatabaseBackup::clientWritesGeneratedValues('mariadb-dump from 11.8.9-MariaDB, client 10.20', '11.8.3-MariaDB-ubu2404'),
    MysqlUpdateDatabaseBackup::clientWritesGeneratedValues('mysqldump  Ver 8.4.13 for Linux on x86_64 (MySQL Community Server - GPL)', '8.4.13'),
], 'R46 dump client: only a MariaDB client against a MySQL server is rewritten');
@unlink($r46dDump);

// 2. Native: a MySQL 8 shop with categories rows, dumped and restored with MariaDB's tools.
$r46dDumpBin = (string)getenv('TEST_MARIADB_DUMP_BINARY');
$r46dClientBin = (string)getenv('TEST_MARIADB_CLIENT_BINARY');
if (!getenv('TEST_MYSQL_DSN') || $r46dDumpBin === '' || $r46dClientBin === '') {
    print "  [SKIP] R46 dump client native MySQL (set TEST_MYSQL_DSN, TEST_MARIADB_DUMP_BINARY and TEST_MARIADB_CLIENT_BINARY)\n";
} else {
    preg_match('/host=([^;]+)/', (string)getenv('TEST_MYSQL_DSN'), $r46dHost);
    preg_match('/port=(\d+)/', (string)getenv('TEST_MYSQL_DSN'), $r46dPort);
    [$r46dUser, $r46dPass] = [getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: ''];
    $r46dServer = new PDO('mysql:host=' . ($r46dHost[1] ?? '127.0.0.1') . ';port=' . ($r46dPort[1] ?? 3306), $r46dUser, $r46dPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $r46dDb = 'bbe_r46d_' . getmypid() . '_' . bin2hex(random_bytes(3));
    $r46dCleanup = [$r46dDb, $r46dDb . '_update_probe', $r46dDb . '_restore_scratch'];
    $r46dSlugs = static fn(): array => $r46dServer->query("SELECT CONCAT(slug, ':', parent_id_uniq) FROM `{$r46dDb}`.categories ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    try {
        $r46dServer->exec("CREATE DATABASE `{$r46dDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $r46dServer->exec("CREATE TABLE `{$r46dDb}`.categories (id INT PRIMARY KEY, parent_id INT NULL, slug VARCHAR(191) NOT NULL,
            parent_id_uniq INT GENERATED ALWAYS AS (COALESCE(parent_id, 0)) STORED, UNIQUE KEY idx_cat_slug_parent (slug, parent_id_uniq)) ENGINE=InnoDB");
        $r46dServer->exec("CREATE TABLE `{$r46dDb}`.invoices (id INT PRIMARY KEY, type VARCHAR(16) NOT NULL, order_id INT NOT NULL,
            invoice_order_unique INT GENERATED ALWAYS AS (CASE WHEN type = 'invoice' THEN order_id ELSE NULL END) STORED, UNIQUE KEY (invoice_order_unique)) ENGINE=InnoDB");
        $r46dServer->exec("INSERT INTO `{$r46dDb}`.categories (id, parent_id, slug) VALUES (1, NULL, 'books'), (2, 1, 'novels'), (3, 1, 'it''s, (poetry)')");
        $r46dServer->exec("INSERT INTO `{$r46dDb}`.invoices (id, type, order_id) VALUES (1, 'invoice', 10), (2, 'credit_note', 10)");
        $r46dBackup = new MysqlUpdateDatabaseBackup($r46dHost[1] ?? '127.0.0.1', (int)($r46dPort[1] ?? 3306), $r46dDb, $r46dUser, $r46dPass,
            'utf8mb4', $r46dDb . '_update_probe', $r46dDumpBin, $r46dClientBin);
        $r46dWarning = (string)$r46dBackup->dumpClientWarning();
        assert_true(str_contains($r46dWarning, 'categories.parent_id_uniq') && str_contains($r46dWarning, 'DEFAULT'),
            'R46 dump client: doctor/check warn about the MariaDB client against MySQL (' . $r46dWarning . ')');
        assert_equals(null, $r46dBackup->dumpClientProblem(), 'R46 dump client: the MariaDB client no longer blocks an update');

        // Update snapshot (dump + verified disposable restore), then a rollback after a write.
        $r46dSnap = $r46dDir . '/snapshot';
        mkdir($r46dSnap, 0700, true);
        $r46dMeta = $r46dBackup->create($r46dSnap);
        assert_true(!empty($r46dMeta['verified_restore']) && str_contains((string)file_get_contents($r46dSnap . '/database.sql'), 'DEFAULT)'),
            'R46 dump client: the update snapshot of a MySQL 8 shop with categories is taken and its disposable restore verified');
        $r46dServer->exec("INSERT INTO `{$r46dDb}`.categories (id, parent_id, slug) VALUES (4, 2, 'after')");
        $r46dBackup->restore($r46dSnap, $r46dMeta);
        assert_equals(['books:0', 'novels:1', "it's, (poetry):1"], $r46dSlugs(), 'R46 dump client: update rollback restores the categories and recomputes parent_id_uniq');

        // cli/backup.php: a scheduled backup dump and its verified restore.
        $r46dServer->exec("INSERT INTO `{$r46dDb}`.categories (id, parent_id, slug) VALUES (5, NULL, 'kept')");
        $r46dBackup->dumpTo($r46dDir . '/backup.sql');
        $r46dServer->exec("DELETE FROM `{$r46dDb}`.categories WHERE id = 1");
        $r46dBackup->dumpTo($r46dDir . '/before-restore.sql');
        $r46dBackup->restoreBackupDump($r46dDir . '/backup.sql', $r46dDb . '_restore_scratch', $r46dDir . '/before-restore.sql');
        assert_equals(['books:0', 'novels:1', "it's, (poetry):1", 'kept:0'], $r46dSlugs(), 'R46 dump client: a backup restore brings the categories back');
        assert_equals(['10', null], array_map(static fn($v): ?string => $v === null ? null : (string)$v,
            $r46dServer->query("SELECT invoice_order_unique FROM `{$r46dDb}`.invoices ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)),
            'R46 dump client: the invoice uniqueness column is recomputed after the restore');
    } catch (Throwable $e) {
        assert_true(false, 'R46 dump client native (' . get_class($e) . ': ' . substr($e->getMessage(), 0, 600) . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    } finally {
        foreach ($r46dCleanup as $r46dName) $r46dServer->exec("DROP DATABASE IF EXISTS `{$r46dName}`");
        foreach (glob($r46dDir . '/{,snapshot/}*', GLOB_BRACE) ?: [] as $r46dFile) if (is_file($r46dFile)) @unlink($r46dFile);
        @rmdir($r46dDir . '/snapshot');
    }
}
@rmdir($r46dDir);
