<?php
require_once __DIR__ . '/cli-only.php';

$standaloneUpdateTest = !function_exists('assert_true');
if ($standaloneUpdateTest) {
    require_once __DIR__ . '/../src/autoload.php';
    $tests = 0;
    $passed = 0;
    $failed = 0;
    $errors = [];
    function assert_true(bool $condition, string $message): void {
        global $tests, $passed, $failed, $errors;
        $tests++;
        if ($condition) { $passed++; echo "  [PASS] {$message}\n"; return; }
        $failed++;
        $errors[] = $message;
        echo "  [FAIL] {$message}\n";
    }
    function assert_equals($expected, $actual, string $message): void {
        global $tests, $passed, $failed, $errors;
        $tests++;
        if ($expected === $actual) { $passed++; echo "  [PASS] {$message}\n"; return; }
        $failed++;
        $detail = $message . ' (expected: ' . var_export($expected, true) . ', got: ' . var_export($actual, true) . ')';
        $errors[] = $detail;
        echo "  [FAIL] {$detail}\n";
    }
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    Db::init($pdo, 'sqlite');
}

echo "\n--- Test 61: Safe versioned updater ---\n";

$updateTestRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'barebones-update-test-' . bin2hex(random_bytes(6));
$updateBase = $updateTestRoot . DIRECTORY_SEPARATOR . 'base';
$updateTarget = $updateTestRoot . DIRECTORY_SEPARATOR . 'target';
$updateInstalled = $updateTestRoot . DIRECTORY_SEPARATOR . 'installed';
$updateState = $updateTestRoot . DIRECTORY_SEPARATOR . 'private-updates';
$updatePackageDir = $updateTestRoot . DIRECTORY_SEPARATOR . 'package';
foreach ([$updateBase, $updateTarget, $updateInstalled] as $directory) {
    mkdir($directory . DIRECTORY_SEPARATOR . 'src', 0750, true);
}

file_put_contents($updateBase . '/src/Core.php', "old core\n");
file_put_contents($updateBase . '/unchanged.txt', "same\n");
file_put_contents($updateBase . '/config.php', "customer base config\n");
file_put_contents($updateTarget . '/src/Core.php', "new core\n");
file_put_contents($updateTarget . '/src/NewFeature.php', "new feature\n");
file_put_contents($updateTarget . '/unchanged.txt', "same\n");
file_put_contents($updateTarget . '/config.php', "release config must not ship\n");
mkdir($updateTarget . '/DB', 0750, true);
file_put_contents($updateTarget . '/DB/runtime.sqlite', "runtime data must not ship\n");
mkdir($updateTarget . '/releases', 0750, true);
file_put_contents($updateTarget . '/releases/update-1.1.0.zip', "published package must not ship inside future deltas\n");
file_put_contents($updateInstalled . '/src/Core.php', "old core\n");
file_put_contents($updateInstalled . '/unchanged.txt', "same\n");
file_put_contents($updateInstalled . '/config.php', "customer production config\n");

try {
    $builtUpdate = UpdatePackageBuilder::build(
        $updateBase,
        $updateTarget,
        $updatePackageDir,
        '1.1.0',
        '1.0.0'
    );
    assert_equals(2, $builtUpdate['files'], 'Updater builder: package contains only changed non-protected files');
    assert_true(Updater::isProtectedPath('Config.php'), 'Updater paths: protected filenames cannot bypass policy with case changes');
    assert_true(Updater::isProtectedPath('DB/runtime.sqlite'), 'Updater paths: protected directories cannot bypass policy with case changes');
    assert_true(!$builtUpdate['signed'], 'Updater builder: unsigned development package is labelled explicitly');

    $unsignedRefused = false;
    try {
        new UpdatePackage($updatePackageDir);
    } catch (RuntimeException $e) {
        $unsignedRefused = str_contains($e->getMessage(), 'Unsigned');
    }
    assert_true($unsignedRefused, 'Updater package: unsigned package is refused by default');

    $updatePackage = new UpdatePackage($updatePackageDir, null, true);
    $updater = new Updater($updateInstalled, $updateState);
    $unknownVersionCheck = $updater->check($updatePackage);
    assert_true(!$unknownVersionCheck['can_apply'], 'Updater preflight: versioned delta is blocked until a legacy install is baselined');
    $updater->baseline('1.0.0');
    $updateCheck = $updater->check($updatePackage);
    assert_true($updateCheck['can_apply'], 'Updater preflight: identified pristine base can be updated');
    assert_equals(2, count($updateCheck['ready']), 'Updater preflight: changed and new files are ready');

    file_put_contents($updateInstalled . '/src/Core.php', "customer customization\n");
    $conflictCheck = $updater->check($updatePackage);
    assert_true(!$conflictCheck['can_apply'] && isset($conflictCheck['conflicts']['src/Core.php']), 'Updater preflight: customer-modified core blocks apply');
    assert_equals("customer customization\n", file_get_contents($updateInstalled . '/src/Core.php'), 'Updater conflict: modified core remains untouched');
    file_put_contents($updateInstalled . '/src/Core.php', "old core\n");

    $appliedUpdate = $updater->apply($updatePackage);
    assert_equals('1.1.0', $appliedUpdate['version'], 'Updater apply: target version is recorded');
    assert_equals("new core\n", file_get_contents($updateInstalled . '/src/Core.php'), 'Updater apply: changed core file is installed');
    assert_equals("new feature\n", file_get_contents($updateInstalled . '/src/NewFeature.php'), 'Updater apply: new file is installed');
    assert_equals("customer production config\n", file_get_contents($updateInstalled . '/config.php'), 'Updater apply: customer config is preserved');
    assert_true(is_file($updateState . '/installed.json'), 'Updater apply: per-deployment version state is stored privately');
    assert_true(!is_file($updateInstalled . '/.barebones-version.json'), 'Updater apply: no version metadata is exposed in the webroot');

    file_put_contents($updateInstalled . '/src/NewFeature.php', "post-update customization\n");
    $safeRollbackRefused = false;
    try {
        $updater->rollback($appliedUpdate['snapshot']);
    } catch (RuntimeException $e) {
        $safeRollbackRefused = str_contains($e->getMessage(), 'changed after the update');
    }
    assert_true($safeRollbackRefused, 'Updater rollback: post-update customer changes block normal rollback');

    $rollback = $updater->rollback($appliedUpdate['snapshot'], true);
    assert_equals(2, $rollback['restored_files'], 'Updater rollback: snapshot restores every affected path');
    assert_equals("old core\n", file_get_contents($updateInstalled . '/src/Core.php'), 'Updater rollback: old core content is restored');
    assert_true(!file_exists($updateInstalled . '/src/NewFeature.php'), 'Updater rollback: newly added file is removed');
    $rolledBackState = json_decode((string)file_get_contents($updateState . '/installed.json'), true);
    assert_equals('1.0.0', $rolledBackState['version'] ?? null, 'Updater rollback: previous installed version state is restored');

    $migrationBase = $updateTestRoot . '/migration-base';
    $migrationTarget = $updateTestRoot . '/migration-target';
    $migrationInstalled = $updateTestRoot . '/migration-installed';
    foreach ([$migrationBase, $migrationTarget, $migrationInstalled] as $directory) {
        mkdir($directory . '/src', 0750, true);
    }
    mkdir($migrationTarget . '/migrations', 0750, true);
    file_put_contents($migrationBase . '/src/Core.php', "before failed migration\n");
    file_put_contents($migrationTarget . '/src/Core.php', "after failed migration\n");
    file_put_contents($migrationTarget . '/migrations/20990101000000_failure.php', "<?php return new class {};\n");
    file_put_contents($migrationInstalled . '/src/Core.php', "before failed migration\n");
    $migrationPackageDir = $updateTestRoot . '/migration-package';
    UpdatePackageBuilder::build($migrationBase, $migrationTarget, $migrationPackageDir, '2.1.0', '2.0.0');
    $migrationPackage = new UpdatePackage($migrationPackageDir, null, true);
    assert_true($migrationPackage->runsMigrations(), 'Updater migrations: package marks migration execution as required');

    $migrationDbPath = $updateTestRoot . '/migration.sqlite';
    $migrationPdo = new PDO('sqlite:' . $migrationDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $migrationPdo->exec('CREATE TABLE stable_data (id INTEGER PRIMARY KEY)');
    Db::init($migrationPdo, 'sqlite');
    $migrationUpdater = new Updater(
        $migrationInstalled,
        $updateTestRoot . '/migration-private',
        $migrationPdo,
        static function (): void {
            Db::get()->exec('CREATE TABLE partial_migration (id INTEGER PRIMARY KEY)');
            throw new RuntimeException('simulated migration failure');
        }
    );
    $migrationUpdater->baseline('2.0.0');
    $migrationPdo = null;
    $migrationFailed = false;
    try {
        $migrationUpdater->apply($migrationPackage);
    } catch (RuntimeException $e) {
        $migrationFailed = str_contains($e->getMessage(), 'simulated migration failure');
    }
    assert_true($migrationFailed, 'Updater migrations: failed migration is surfaced to the operator');
    assert_equals("before failed migration\n", file_get_contents($migrationInstalled . '/src/Core.php'), 'Updater migrations: failed apply restores previous code');
    assert_true(!file_exists($migrationInstalled . '/migrations/20990101000000_failure.php'), 'Updater migrations: failed apply removes newly installed migration file');
    $verifiedMigrationDb = new PDO('sqlite:' . $migrationDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $partialTableCount = (int)$verifiedMigrationDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='partial_migration'")->fetchColumn();
    assert_equals(0, $partialTableCount, 'Updater migrations: automatic rollback restores the pre-migration SQLite snapshot');
    $verifiedMigrationDb = null;
    Db::init($pdo, 'sqlite');

    $invalidMysqlProbeRejected = false;
    try {
        new MysqlUpdateDatabaseBackup('127.0.0.1', 3306, 'shop', 'shop', 'secret', 'utf8mb4', 'unsafe_probe');
    } catch (InvalidArgumentException $e) {
        $invalidMysqlProbeRejected = str_contains($e->getMessage(), '_update_probe');
    }
    assert_true($invalidMysqlProbeRejected, 'Updater MySQL: disposable restore database requires the guarded _update_probe suffix');
    $mysqlBackupSource = (string)file_get_contents(__DIR__ . '/../src/MysqlUpdateDatabaseBackup.php');
    assert_true(str_contains($mysqlBackupSource, '--defaults-extra-file='), 'Updater MySQL: client credentials use a temporary defaults file');
    assert_true(!str_contains($mysqlBackupSource, '--password='), 'Updater MySQL: password is never exposed in process arguments');

    file_put_contents($updatePackageDir . '/payload/src/Core.php', "tampered payload\n");
    $tamperRefused = false;
    try {
        new UpdatePackage($updatePackageDir, null, true);
    } catch (RuntimeException $e) {
        $tamperRefused = str_contains($e->getMessage(), 'checksum mismatch');
    }
    assert_true($tamperRefused, 'Updater package: payload tampering is rejected by SHA-256 verification');

    $signatureTestRequired = getenv('BAREBONES_REQUIRE_UPDATE_SIGNATURE_TEST') === '1';
    if ($signatureTestRequired) {
        assert_true(function_exists('sodium_crypto_sign_keypair'), 'Updater signature: CI requires the sodium-backed Ed25519 test path');
    }
    if (function_exists('sodium_crypto_sign_keypair')) {
        $signedPackageDir = $updateTestRoot . DIRECTORY_SEPARATOR . 'signed-package';
        $keypair = sodium_crypto_sign_keypair();
        $secret = 'base64:' . base64_encode(sodium_crypto_sign_secretkey($keypair));
        $public = 'base64:' . base64_encode(sodium_crypto_sign_publickey($keypair));
        UpdatePackageBuilder::build($updateBase, $updateTarget, $signedPackageDir, '1.1.0', '1.0.0', $secret);
        $signedPackage = new UpdatePackage($signedPackageDir, $public);
        assert_equals('1.1.0', $signedPackage->version(), 'Updater signature: valid Ed25519 package is accepted');

        $doctorDbPath = $updateTestRoot . DIRECTORY_SEPARATOR . 'doctor.sqlite';
        $doctorPdo = new PDO('sqlite:' . $doctorDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $doctorPdo->exec('CREATE TABLE readiness_probe (id INTEGER PRIMARY KEY)');
        $doctorUpdater = new Updater($updateInstalled, $updateState, $doctorPdo);
        $doctorReport = $doctorUpdater->doctor($public, $signedPackage);
        $doctorChecks = array_column($doctorReport['checks'], null, 'name');
        assert_equals('READY', $doctorReport['status'], 'Updater doctor: signed compatible package and verified SQLite snapshot capability are production-ready');
        assert_equals('ok', $doctorChecks['database_backup']['level'] ?? null, 'Updater doctor: real SQLite snapshot passes integrity verification');
        assert_equals('ok', $doctorChecks['package']['level'] ?? null, 'Updater doctor: signed package preflight is included');
        $skippedProbeReport = $doctorUpdater->doctor($public, $signedPackage, false);
        assert_equals('NOT_READY', $skippedProbeReport['status'], 'Updater doctor: skipping the database backup proof cannot report production readiness');
        $doctorPdo = null;

        $wrongPair = sodium_crypto_sign_keypair();
        $wrongPublic = 'base64:' . base64_encode(sodium_crypto_sign_publickey($wrongPair));
        $wrongKeyRefused = false;
        try {
            new UpdatePackage($signedPackageDir, $wrongPublic);
        } catch (RuntimeException $e) {
            $wrongKeyRefused = str_contains($e->getMessage(), 'signature verification failed');
        }
        assert_true($wrongKeyRefused, 'Updater signature: package signed by another key is rejected');

        file_put_contents($signedPackageDir . '/manifest.json', " \n", FILE_APPEND);
        $signedManifestTamperRefused = false;
        try {
            new UpdatePackage($signedPackageDir, $public);
        } catch (RuntimeException $e) {
            $signedManifestTamperRefused = str_contains($e->getMessage(), 'signature verification failed');
        }
        assert_true($signedManifestTamperRefused, 'Updater signature: any signed manifest mutation is rejected');

        // A key whose base64 starts with "//" makes "base64://..." — must decode, not hit a stream wrapper.
        $slashKey = "\xFF\xFF\xFF" . str_repeat("\x01", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES - 3);
        $slashEncoded = 'base64:' . base64_encode($slashKey);
        assert_true(str_starts_with($slashEncoded, 'base64://'), 'Updater keys: fixture key encodes as base64://');
        assert_equals($slashKey, UpdatePackage::decodeKey($slashEncoded, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'update public key'), 'Updater keys: base64 key starting with // decodes inline, not as a stream wrapper');
    }

    $mysqlUpdateDatabase = trim((string)(getenv('TEST_MYSQL_UPDATE_DATABASE') ?: ''));
    if ($mysqlUpdateDatabase !== '') {
        if (preg_match('/^[A-Za-z0-9_]+_update_test$/', $mysqlUpdateDatabase) !== 1) {
            throw new RuntimeException('Destructive MySQL updater integration database must end with _update_test');
        }
        if (!function_exists('sodium_crypto_sign_keypair')) {
            throw new RuntimeException('Live MySQL updater integration test requires Sodium');
        }
        assert_true(true, 'Updater MySQL live: destructive integration database has the guarded _update_test suffix');
        $mysqlHost = (string)(getenv('TEST_MYSQL_UPDATE_HOST') ?: '127.0.0.1');
        $mysqlPort = (int)(getenv('TEST_MYSQL_UPDATE_PORT') ?: 3306);
        $mysqlUser = (string)(getenv('TEST_MYSQL_UPDATE_USER') ?: 'root');
        $mysqlPassword = (string)(getenv('TEST_MYSQL_UPDATE_PASS') ?: '');
        $mysqlDumpBinary = (string)(getenv('TEST_MYSQL_DUMP_BINARY') ?: 'mysqldump');
        $mysqlClientBinary = (string)(getenv('TEST_MYSQL_CLIENT_BINARY') ?: 'mysql');
        $mysqlServerDsn = "mysql:host={$mysqlHost};port={$mysqlPort};charset=utf8mb4";
        $mysqlLiveDsn = $mysqlServerDsn . ';dbname=' . $mysqlUpdateDatabase;
        $mysqlServer = new PDO($mysqlServerDsn, $mysqlUser, $mysqlPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $quotedMysqlDatabase = '`' . str_replace('`', '``', $mysqlUpdateDatabase) . '`';
        $mysqlServer->exec("DROP DATABASE IF EXISTS {$quotedMysqlDatabase}");
        $mysqlServer->exec("CREATE DATABASE {$quotedMysqlDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $mysqlLive = new PDO($mysqlLiveDsn, $mysqlUser, $mysqlPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $mysqlLive->exec('CREATE TABLE stable_data (id INT PRIMARY KEY, value_text VARCHAR(100) NOT NULL) ENGINE=InnoDB');
        $mysqlLive->exec('CREATE TABLE stable_audit (id INT AUTO_INCREMENT PRIMARY KEY, stable_id INT NOT NULL) ENGINE=InnoDB');
        $mysqlLive->exec('CREATE TRIGGER stable_insert AFTER INSERT ON stable_data FOR EACH ROW INSERT INTO stable_audit (stable_id) VALUES (NEW.id)');
        $mysqlLive->exec("INSERT INTO stable_data (id, value_text) VALUES (1, 'before update'), (2, 'keep me')");

        $mysqlBase = $updateTestRoot . '/mysql-base';
        $mysqlTarget = $updateTestRoot . '/mysql-target';
        $mysqlInstalled = $updateTestRoot . '/mysql-installed';
        foreach ([$mysqlBase, $mysqlTarget, $mysqlInstalled] as $directory) {
            mkdir($directory . '/src', 0750, true);
        }
        mkdir($mysqlTarget . '/migrations', 0750, true);
        file_put_contents($mysqlBase . '/src/Core.php', "mysql old core\n");
        file_put_contents($mysqlTarget . '/src/Core.php', "mysql new core\n");
        file_put_contents($mysqlTarget . '/migrations/20990101000001_mysql.php', "<?php return new class {};\n");
        file_put_contents($mysqlInstalled . '/src/Core.php', "mysql old core\n");
        $mysqlPackageDirectory = $updateTestRoot . '/mysql-package';
        $mysqlKeypair = sodium_crypto_sign_keypair();
        $mysqlSecret = 'base64:' . base64_encode(sodium_crypto_sign_secretkey($mysqlKeypair));
        $mysqlPublic = 'base64:' . base64_encode(sodium_crypto_sign_publickey($mysqlKeypair));
        UpdatePackageBuilder::build($mysqlBase, $mysqlTarget, $mysqlPackageDirectory, '3.1.0', '3.0.0', $mysqlSecret);
        $mysqlPackage = new UpdatePackage($mysqlPackageDirectory, $mysqlPublic);
        $mysqlProbeDatabase = $mysqlUpdateDatabase . '_update_probe';
        $mysqlBackup = new MysqlUpdateDatabaseBackup(
            $mysqlHost,
            $mysqlPort,
            $mysqlUpdateDatabase,
            $mysqlUser,
            $mysqlPassword,
            'utf8mb4',
            $mysqlProbeDatabase,
            $mysqlDumpBinary,
            $mysqlClientBinary
        );
        $mysqlMigration = static function () use ($mysqlLiveDsn, $mysqlUser, $mysqlPassword): void {
            $connection = new PDO($mysqlLiveDsn, $mysqlUser, $mysqlPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $connection->exec('CREATE TABLE migration_added (id INT PRIMARY KEY) ENGINE=InnoDB');
            $connection->exec("INSERT INTO stable_data (id, value_text) VALUES (3, 'during migration')");
        };
        $mysqlUpdater = new Updater(
            $mysqlInstalled,
            $updateTestRoot . '/mysql-private',
            $mysqlLive,
            $mysqlMigration,
            $mysqlBackup
        );
        $mysqlUpdater->baseline('3.0.0');
        $mysqlLive = null;

        $mysqlDoctor = $mysqlUpdater->doctor($mysqlPublic, $mysqlPackage);
        $mysqlDoctorChecks = array_column($mysqlDoctor['checks'], null, 'name');
        assert_equals('READY', $mysqlDoctor['status'], 'Updater MySQL live: doctor proves signed package and real dump/restore readiness');
        assert_equals('ok', $mysqlDoctorChecks['database_backup']['level'] ?? null, 'Updater MySQL live: doctor verifies disposable restore inventory');
        $mysqlApplied = $mysqlUpdater->apply($mysqlPackage);
        assert_equals("mysql new core\n", file_get_contents($mysqlInstalled . '/src/Core.php'), 'Updater MySQL live: code and migration apply after verified dump');
        $mysqlAfterApply = new PDO($mysqlLiveDsn, $mysqlUser, $mysqlPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        assert_equals(1, (int)$mysqlAfterApply->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migration_added'")->fetchColumn(), 'Updater MySQL live: migration changed the database');
        $mysqlAfterApply = null;
        $probeCheck = $mysqlServer->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $probeCheck->execute([$mysqlProbeDatabase]);
        assert_equals(0, (int)$probeCheck->fetchColumn(), 'Updater MySQL live: disposable probe database is removed after verification');

        $mysqlUpdater->rollback($mysqlApplied['snapshot']);
        $mysqlAfterRollback = new PDO($mysqlLiveDsn, $mysqlUser, $mysqlPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        assert_equals(0, (int)$mysqlAfterRollback->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migration_added'")->fetchColumn(), 'Updater MySQL live: manual rollback removes migration schema changes');
        assert_equals(2, (int)$mysqlAfterRollback->query('SELECT COUNT(*) FROM stable_data')->fetchColumn(), 'Updater MySQL live: manual rollback restores pre-update data');
        assert_equals(2, (int)$mysqlAfterRollback->query('SELECT COUNT(*) FROM stable_audit')->fetchColumn(), 'Updater MySQL live: dump restores triggers and their pre-update data');
        assert_equals("mysql old core\n", file_get_contents($mysqlInstalled . '/src/Core.php'), 'Updater MySQL live: manual rollback restores code');
        $mysqlAfterRollback = null;

        $mysqlFailurePdo = new PDO($mysqlLiveDsn, $mysqlUser, $mysqlPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $mysqlFailingUpdater = new Updater(
            $mysqlInstalled,
            $updateTestRoot . '/mysql-private',
            $mysqlFailurePdo,
            static function () use ($mysqlLiveDsn, $mysqlUser, $mysqlPassword): void {
                $connection = new PDO($mysqlLiveDsn, $mysqlUser, $mysqlPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $connection->exec('CREATE TABLE partial_mysql_migration (id INT PRIMARY KEY) ENGINE=InnoDB');
                throw new RuntimeException('simulated MySQL migration failure');
            },
            $mysqlBackup
        );
        $mysqlFailurePdo = null;
        $mysqlFailureSurfaced = false;
        try {
            $mysqlFailingUpdater->apply($mysqlPackage);
        } catch (RuntimeException $e) {
            $mysqlFailureSurfaced = str_contains($e->getMessage(), 'simulated MySQL migration failure');
        }
        assert_true($mysqlFailureSurfaced, 'Updater MySQL live: migration failure is surfaced after automatic rollback');
        $mysqlAfterFailure = new PDO($mysqlLiveDsn, $mysqlUser, $mysqlPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        assert_equals(0, (int)$mysqlAfterFailure->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partial_mysql_migration'")->fetchColumn(), 'Updater MySQL live: automatic rollback removes partial migration schema');
        assert_equals(2, (int)$mysqlAfterFailure->query('SELECT COUNT(*) FROM stable_data')->fetchColumn(), 'Updater MySQL live: automatic rollback restores pre-migration data');
        assert_equals("mysql old core\n", file_get_contents($mysqlInstalled . '/src/Core.php'), 'Updater MySQL live: automatic rollback restores code');
        Db::init($pdo, 'sqlite');
    } elseif (getenv('BAREBONES_REQUIRE_MYSQL_UPDATE_TEST') === '1') {
        assert_true(false, 'Updater MySQL live: CI requires TEST_MYSQL_UPDATE_DATABASE');
    } else {
        echo "  [SKIP] live MySQL/MariaDB updater dump/restore test (set TEST_MYSQL_UPDATE_DATABASE to a disposable *_update_test database)\n";
    }
} finally {
    updateTestRemoveTree($updateTestRoot);
}

function updateTestRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

if ($standaloneUpdateTest) {
    echo "\nResults: {$passed}/{$tests} passed" . ($failed > 0 ? ", {$failed} FAILED" : '') . "\n";
    if ($failed > 0) {
        foreach ($errors as $error) echo "  - {$error}\n";
    }
    exit($failed > 0 ? 1 : 0);
}
