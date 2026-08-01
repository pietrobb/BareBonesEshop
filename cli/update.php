<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Safe production update CLI.
 *
 * Usage:
 *   php cli/update.php check    --package=/path/to/release [--public-key=...]
 *   php cli/update.php backup   --package=/path/to/release [--public-key=...]
 *   php cli/update.php apply    --package=/path/to/release [--public-key=...]
 *   php cli/update.php baseline --version=1.0.0
 *   php cli/update.php rollback --snapshot=YYYYmmddHHMMSS-abcdef123456 [--force]
 *   php cli/update.php list
 *
 * Unsigned packages are refused unless --allow-unsigned is explicitly supplied.
 */
require_once __DIR__ . '/../src/autoload.php';

$args = array_slice($argv ?? [], 1);
$command = $args[0] ?? 'help';
$options = [];
foreach (array_slice($args, 1) as $argument) {
    if ($argument === '--allow-unsigned' || $argument === '--force') {
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
    $config = require $root . '/config.php';
    if (!is_array($config)) {
        throw new RuntimeException('config.php must return an array');
    }
    $privateRoot = (string)($config['private_runtime_dir'] ?? dirname($root) . '/bareboneseshop-private');
    $stateDirectory = (string)($config['update_storage_dir'] ?? rtrim($privateRoot, '/\\') . '/updates');
    $publicKey = isset($options['public-key'])
        ? (string)$options['public-key']
        : (string)($config['update_public_key'] ?? getenv('BAREBONES_UPDATE_PUBLIC_KEY') ?: '');

    if ($command === 'help' || $command === '--help' || $command === '-h') {
        updateHelp();
        exit(0);
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
        $snapshotId = (string)($options['snapshot'] ?? '');
        if ($snapshotId === '') {
            throw new InvalidArgumentException('rollback requires --snapshot=ID');
        }
        $updater = new Updater($root, $stateDirectory);
        $result = $updater->rollback($snapshotId, !empty($options['force']));
        echo "Rollback complete: {$result['snapshot']}, restored {$result['restored_files']} file(s).\n";
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
    if ($package->runsMigrations()) {
        $database = Db::get();
        $migrationRunner = static function () use ($root): void {
            $migrator = new Migrator(Db::get(), $root . '/migrations');
            $applied = $migrator->apply(static function (string $message): void {
                echo "  {$message}\n";
            });
            echo '  Applied ' . count($applied) . " migration(s).\n";
        };
    }
    $updater = new Updater($root, $stateDirectory, $database, $migrationRunner);
    $database = null; // Updater owns the only non-static reference; enables SQLite rollback on Windows.

    if ($command === 'check') {
        $report = $updater->check($package);
        printUpdateReport($report);
        exit($report['can_apply'] ? 0 : 1);
    }
    if ($command === 'backup') {
        $snapshot = $updater->backup($package);
        echo "Snapshot created: {$snapshot['id']}\n";
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
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
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
    echo "  php cli/update.php check --package=DIR [--public-key=KEY]\n";
    echo "  php cli/update.php backup --package=DIR [--public-key=KEY]\n";
    echo "  php cli/update.php apply --package=DIR [--public-key=KEY]\n";
    echo "  php cli/update.php baseline --version=VERSION\n";
    echo "  php cli/update.php rollback --snapshot=ID [--force]\n";
    echo "  php cli/update.php list\n";
    echo "Options:\n";
    echo "  --allow-unsigned  Accept an unsigned package explicitly (development only)\n";
    echo "  --public-key=KEY  Ed25519 public key as base64, hex, or a file path\n";
    echo "  --force           Roll back even if an installed file changed after update\n";
}
