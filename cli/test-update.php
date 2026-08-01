<?php
require_once __DIR__ . '/cli-only.php';

if (!function_exists('assert_true')) {
    fwrite(STDERR, "Run updater tests through: php cli/test.php\n");
    exit(2);
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

    file_put_contents($updatePackageDir . '/payload/src/Core.php', "tampered payload\n");
    $tamperRefused = false;
    try {
        new UpdatePackage($updatePackageDir, null, true);
    } catch (RuntimeException $e) {
        $tamperRefused = str_contains($e->getMessage(), 'checksum mismatch');
    }
    assert_true($tamperRefused, 'Updater package: payload tampering is rejected by SHA-256 verification');

    if (function_exists('sodium_crypto_sign_keypair')) {
        $signedPackageDir = $updateTestRoot . DIRECTORY_SEPARATOR . 'signed-package';
        $keypair = sodium_crypto_sign_keypair();
        $secret = 'base64:' . base64_encode(sodium_crypto_sign_secretkey($keypair));
        $public = 'base64:' . base64_encode(sodium_crypto_sign_publickey($keypair));
        UpdatePackageBuilder::build($updateBase, $updateTarget, $signedPackageDir, '1.1.0', '1.0.0', $secret);
        $signedPackage = new UpdatePackage($signedPackageDir, $public);
        assert_equals('1.1.0', $signedPackage->version(), 'Updater signature: valid Ed25519 package is accepted');

        $wrongPair = sodium_crypto_sign_keypair();
        $wrongPublic = 'base64:' . base64_encode(sodium_crypto_sign_publickey($wrongPair));
        $wrongKeyRefused = false;
        try {
            new UpdatePackage($signedPackageDir, $wrongPublic);
        } catch (RuntimeException $e) {
            $wrongKeyRefused = str_contains($e->getMessage(), 'signature verification failed');
        }
        assert_true($wrongKeyRefused, 'Updater signature: package signed by another key is rejected');
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
