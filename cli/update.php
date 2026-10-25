<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Safe production update CLI.
 *
 * Usage:
 *   php cli/update.php doctor   [--package=/path/to/release] [--json]
 *   php cli/update.php check    --package=/path/to/release [--public-key=...]
 *   php cli/update.php backup   --package=/path/to/release [--public-key=...]
 *   php cli/update.php apply    --package=/path/to/release [--public-key=...]
 *   php cli/update.php baseline --version=1.0.0
 *   php cli/update.php rollback --snapshot=YYYYmmddHHMMSS-abcdef123456 [--force] [--accept-data-loss]
 *   php cli/update.php list
 *
 * Unsigned packages are refused unless --allow-unsigned is explicitly supplied.
 */
require_once __DIR__ . '/../src/autoload.php';

$args = array_slice($argv ?? [], 1);
$command = $args[0] ?? 'help';
$options = [];
foreach (array_slice($args, 1) as $argument) {
    if (in_array($argument, ['--allow-unsigned', '--force', '--json', '--skip-backup-probe', '--accept-data-loss'], true)) {
        $options[substr($argument, 2)] = true;
        continue;
    }
    if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $options[$name] = $value;
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(2);
}

$root = dirname(__DIR__);
try {
    // --installed=DIR: this (trusted, newer) release's updater operates on the shop in DIR, with DIR's config.php
    // and database — how a shop that predates the updater (v1.8.0) is adopted without copying files into it.
    if (isset($options['installed'])) {
        $installed = realpath((string)$options['installed']);
        if ($installed === false || !is_file($installed . '/config.php') || !is_dir($installed . '/src')) {
            throw new InvalidArgumentException('--installed must be the root of an installed shop (with config.php and src/)');
        }
        $root = $installed;
        Db::useConfig($root . '/config.php');
    }
    $config = require $root . '/config.php';
    if (!is_array($config)) {
        throw new RuntimeException('config.php must return an array');
    }
    $privateRoot = (string)($config['private_runtime_dir'] ?? dirname($root) . '/bareboneseshop-private');
    $stateDirectory = (string)($config['update_storage_dir'] ?? rtrim($privateRoot, '/\\') . '/updates');
    $configuredPublicKey = trim((string)($config['update_public_key'] ?? ''));
    $environmentPublicKey = trim((string)(getenv('BAREBONES_UPDATE_PUBLIC_KEY') ?: ''));
    $publicKey = isset($options['public-key'])
        ? (string)$options['public-key']
        : ($configuredPublicKey !== '' ? $configuredPublicKey : $environmentPublicKey);

    if ($command === 'help' || $command === '--help' || $command === '-h') {
        updateHelp();
        exit(0);
    }

    if ($command === 'doctor') {
        $databaseBackup = createUpdateDatabaseBackup($config);
        $package = null;
        $packageDirectory = (string)($options['package'] ?? '');
        if ($packageDirectory !== '') {
            $package = new UpdatePackage(
                $packageDirectory,
                $publicKey !== '' ? $publicKey : null,
                !empty($options['allow-unsigned'])
            );
        }
        // The same migration runner as apply, so a package with migrations gets the preflight apply will see (never run here).
        $updater = new Updater($root, $stateDirectory, Db::get(), updateMigrationRunner($root), $databaseBackup, new ThemeUpgradeGate($stateDirectory));
        $report = $updater->doctor(
            $publicKey !== '' ? $publicKey : null,
            $package,
            empty($options['skip-backup-probe']),
            $config
        );
        printUpdateDoctorReport($report, !empty($options['json']));
        exit($report['status'] === 'READY' ? 0 : 1);
    }

    if ($command === 'baseline') {
        $version = (string)($options['version'] ?? '');
        if ($version === '') {
            throw new InvalidArgumentException('baseline requires --version=VERSION');
        }
        $updater = new Updater($root, $stateDirectory);
        $state = $updater->baseline($version);
        echo "Installed release baselined as {$state['version']}.\n";
        exit(0);
    }

    if ($command === 'list') {
        $updater = new Updater($root, $stateDirectory);
        $snapshots = $updater->listSnapshots();
        if (!$snapshots) {
            echo "No update snapshots.\n";
            exit(0);
        }
        foreach ($snapshots as $snapshot) {
            echo ($snapshot['id'] ?? '?') . '  '
                . ($snapshot['status'] ?? '?') . '  version='
                . ($snapshot['package_version'] ?? '?') . '  '
                . ($snapshot['created_at'] ?? '?') . "\n";
        }
        exit(0);
    }

    if ($command === 'rollback') {
        $databaseBackup = createUpdateDatabaseBackup($config);
        $snapshotId = (string)($options['snapshot'] ?? '');
        if ($snapshotId === '') {
            throw new InvalidArgumentException('rollback requires --snapshot=ID');
        }
        // With a connection the rollback counts the rows it discards (the restore releases it before replacing the
        // database); a code-only rollback still works when the database is unreachable.
        try {
            $database = Db::get();
        } catch (Throwable $e) {
            $database = null;
            fwrite(STDERR, 'No database connection; rows discarded by a database rollback cannot be counted: ' . $e->getMessage() . "\n");
        }
        $updater = new Updater($root, $stateDirectory, $database, null, $databaseBackup);
        $database = null;
        $result = $updater->rollback($snapshotId, !empty($options['force']), !empty($options['accept-data-loss']));
        echo "Rollback complete: {$result['snapshot']}, restored {$result['restored_files']} file(s)"
            . ($result['database_restored_to'] !== null ? ", database restored to {$result['database_restored_to']}" : '') . ".\n";
        foreach ((array)($result['discarded_writes'] ?? []) as $table => $count) {
            echo "  discarded since the snapshot: {$table}=" . ($count === null ? 'unknown' : $count) . "\n";
        }
        updateRecordCoreBaseline($root, $stateDirectory, $config, 'rollback ' . $snapshotId, null);
        exit(0);
    }

    if (!in_array($command, ['check', 'backup', 'apply'], true)) {
        throw new InvalidArgumentException("Unknown command: {$command}");
    }
    $packageDirectory = (string)($options['package'] ?? '');
    if ($packageDirectory === '') {
        throw new InvalidArgumentException("{$command} requires --package=/path/to/release");
    }
    $package = new UpdatePackage(
        $packageDirectory,
        $publicKey !== '' ? $publicKey : null,
        !empty($options['allow-unsigned'])
    );

    $database = null;
    $migrationRunner = null;
    $databaseBackup = null;
    if ($package->runsMigrations()) {
        $database = Db::get();
        $databaseBackup = createUpdateDatabaseBackup($config);
        $migrationRunner = updateMigrationRunner($root);
    }
    $updater = new Updater($root, $stateDirectory, $database, $migrationRunner, $databaseBackup, new ThemeUpgradeGate($stateDirectory));
    $database = null; // Updater owns the only non-static reference; enables SQLite rollback on Windows.

    if ($command === 'check') {
        $report = $updater->check($package);
        printUpdateReport($report);
        exit($report['can_apply'] ? 0 : 1);
    }
    if ($command === 'backup') {
        $snapshot = $updater->backup($package);
        echo "Snapshot created: {$snapshot['id']}\n";
        try {
            $pruned = $updater->pruneSnapshots((int)($config['update_snapshots_keep'] ?? 3));
            if ($pruned) echo 'Deleted ' . count($pruned) . " old update snapshot(s) (update_snapshots_keep).\n";
        } catch (Throwable $e) {
            fwrite(STDERR, 'WARNING: old update snapshots not pruned: ' . $e->getMessage() . "\n");
        }
        exit(0);
    }

    $report = $updater->check($package);
    printUpdateReport($report);
    if (!$report['can_apply']) {
        exit(1);
    }
    $result = $updater->apply($package);
    echo "Updated to {$result['version']}: {$result['changed']} changed, "
        . "{$result['already_current']} already current. Rollback snapshot: {$result['snapshot']}\n";
    foreach ($result['retired'] as $retiredPath) {
        echo "Moved obsolete generated file {$retiredPath} into the snapshot (retired/)\n";
    }
    try {
        $pruned = $updater->pruneSnapshots((int)($config['update_snapshots_keep'] ?? 3));
        if ($pruned) echo 'Deleted ' . count($pruned) . " old update snapshot(s) (update_snapshots_keep).\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'WARNING: old update snapshots not pruned: ' . $e->getMessage() . "\n");
    }
    updateRecordCoreBaseline($root, $stateDirectory, $config, 'update ' . $result['version'], (string)$result['version']);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

/** The drift guard baseline follows every successful update or rollback; failure here never undoes the update. */
function updateRecordCoreBaseline(string $root, string $stateDirectory, array $config, string $reason, ?string $version): void
{
    try {
        $result = (new CoreIntegrity($root, $stateDirectory, (array)($config['drift_guard'] ?? [])))->record($reason, $version);
        echo "Core drift baseline recorded: {$result['files']} file(s).\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'WARNING: core drift baseline not recorded: ' . $e->getMessage() . "\n");
    }
}

function updateMigrationRunner(string $root): Closure
{
    return static function () use ($root): void {
        $migrator = new Migrator(Db::get(), $root . '/migrations');
        $applied = $migrator->apply(static function (string $message): void {
            echo "  {$message}\n";
        });
        echo '  Applied ' . count($applied) . " migration(s).\n";
    };
}

function createUpdateDatabaseBackup(array $config): ?UpdateDatabaseBackup
{
    if (strtolower(trim((string)($config['db_driver'] ?? ''))) !== 'mysql') {
        return null;
    }
    $probeDatabase = trim((string)($config['update_mysql_probe_database'] ?? ''));
    if ($probeDatabase === '') {
        return null;
    }
    return new MysqlUpdateDatabaseBackup(
        (string)($config['db_host'] ?? ''),
        (int)($config['db_port'] ?? 3306),
        (string)($config['db_name'] ?? ''),
        (string)($config['db_user'] ?? ''),
        (string)($config['db_password'] ?? ''),
        (string)($config['db_charset'] ?? 'utf8mb4'),
        $probeDatabase,
        BackupService::mysqlBinary((string)($config['update_mysql_dump_binary'] ?? 'mysqldump'), 'mysqldump', 'mariadb-dump'),
        BackupService::mysqlBinary((string)($config['update_mysql_client_binary'] ?? 'mysql'), 'mysql', 'mariadb')
    );
}

function printUpdateDoctorReport(array $report, bool $json): void
{
    if ($json) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        return;
    }
    echo "BareBones Shop — Update Readiness\n";
    echo "=================================\n";
    foreach ($report['checks'] as $check) {
        echo '[' . strtoupper($check['level']) . '] ' . $check['name'] . ': ' . $check['message'] . "\n";
    }
    $preflight = $report['package_preflight'] ?? null;
    if (is_array($preflight) && !$preflight['can_apply']) {
        foreach ($preflight['blockers'] as $blocker) {
            echo "  [BLOCK] {$blocker}\n";
        }
        foreach ($preflight['conflicts'] as $path => $reason) {
            echo "  [CONFLICT] {$path}: {$reason}\n";
        }
    }
    echo "Result: {$report['status']} ({$report['counts']['ok']} ok, {$report['counts']['warn']} warn, {$report['counts']['fail']} fail)\n";
}

function printUpdateReport(array $report): void
{
    echo 'Package: ' . $report['version'] . ' (base ' . ($report['base_version'] ?? 'unversioned') . ")\n";
    echo 'Installed: ' . ($report['installed_version'] ?? 'unversioned') . "\n";
    echo 'Ready: ' . count($report['ready']) . ', already current: ' . count($report['current'])
        . ', conflicts: ' . count($report['conflicts']) . "\n";
    foreach ($report['warnings'] as $warning) {
        echo "  [WARN] {$warning}\n";
    }
    foreach ($report['blockers'] as $blocker) {
        echo "  [BLOCK] {$blocker}\n";
    }
    foreach ($report['conflicts'] as $path => $reason) {
        echo "  [CONFLICT] {$path}: {$reason}\n";
    }
    echo $report['can_apply'] ? "Preflight passed.\n" : "Preflight failed; no files were changed.\n";
}

function updateHelp(): void
{
    echo "Usage:\n";
    echo "  php cli/update.php doctor [--package=DIR] [--json] [--skip-backup-probe]\n";
    echo "  php cli/update.php check --package=DIR [--public-key=KEY]\n";
    echo "  php cli/update.php backup --package=DIR [--public-key=KEY]\n";
    echo "  php cli/update.php apply --package=DIR [--public-key=KEY]\n";
    echo "  php cli/update.php baseline --version=VERSION\n";
    echo "  php cli/update.php rollback --snapshot=ID [--force] [--accept-data-loss]\n";
    echo "    A migration update rollback restores the pre-update database: all orders and other writes since the\n";
    echo "    snapshot are lost. It is refused with a warning unless --accept-data-loss is given.\n";
    echo "  php cli/update.php list\n";
    echo "Options:\n";
    echo "  --allow-unsigned  Accept an unsigned package explicitly (development only)\n";
    echo "  --public-key=KEY  Ed25519 public key as base64, hex, or a file path\n";
    echo "  --force           Roll back even if an installed file changed after update\n";
    echo "  --json            Emit machine-readable doctor output\n";
    echo "  --skip-backup-probe  Skip the doctor's real database backup/restore probe\n";
}
