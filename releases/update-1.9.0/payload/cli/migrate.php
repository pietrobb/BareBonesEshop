<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Database Migration CLI
 *
 * Usage:
 *   php cli/migrate.php                 Apply all pending migrations
 *   php cli/migrate.php status          Show applied / pending / missing
 *   php cli/migrate.php create <name>   Generate stub migration file
 *   php cli/migrate.php baseline        Mark all current files as applied (no-run)
 *
 * Flags:
 *   --skip-backup   Disable the automatic pre-migration backup (DANGEROUS).
 *                   By default, when there are pending migrations, a backup
 *                   labelled 'pre-migration' is taken before any migration runs.
 *
 * Forward-only. No down() / rollback — write a new migration to revert.
 */
require_once __DIR__ . '/../src/autoload.php';

$argvLocal = $argv ?? [];
$skipBackup = false;
$args = [];
foreach (array_slice($argvLocal, 1) as $a) {
    if ($a === '--skip-backup') { $skipBackup = true; continue; }
    $args[] = $a;
}
$cmd = $args[0] ?? 'apply';

$migrator = new Migrator();

$logger = static function (string $msg): void {
    echo $msg . "\n";
};

// Pre-migration backup hook — only for `apply`, only when config.auto_pre_migration is on.
$preApplyHook = null;
if ($cmd === 'apply' && !$skipBackup) {
    $preApplyHook = static function (array $pendingNames, callable $log): void {
        try {
            BackupService::init(Db::get());
        } catch (\Throwable $e) {
            $log("  [backup] init failed: " . $e->getMessage() . " — continuing without backup.");
            return;
        }
        $cfg = BackupService::config();
        if (empty($cfg['enabled']) || empty($cfg['auto_pre_migration'])) {
            return; // explicitly disabled in config
        }
        $log("  [backup] taking pre-migration backup (" . count($pendingNames) . " pending)...");
        try {
            $meta = BackupService::create([
                'label'   => 'pre-migration',
                'trigger' => 'migration',
            ]);
            $log("  [backup] OK {$meta['remote_path']} sha=" . substr($meta['sha256'], 0, 12));
        } catch (\Throwable $e) {
            // Hard fail — better to abort than to migrate without safety net.
            throw new \RuntimeException(
                'Pre-migration backup failed: ' . $e->getMessage() .
                ' — re-run with --skip-backup to override (NOT RECOMMENDED).'
            );
        }
    };
}

try {
    switch ($cmd) {
        case 'apply':
            echo "Applying pending migrations...\n";
            $applied = $migrator->apply($logger, $preApplyHook);
            $n = count($applied);
            echo $n === 0 ? "Nothing to apply.\n" : "Applied {$n} migration(s).\n";
            break;

        case 'status':
            $s = $migrator->status();
            echo "Applied:\n";
            if (!$s['applied']) {
                echo "  (none)\n";
            }
            foreach ($s['applied'] as $name => $info) {
                $flag = $info['checksum_ok'] === true  ? 'ok'
                      : ($info['checksum_ok'] === null ? 'missing' : 'CHECKSUM-FAIL');
                echo "  [{$flag}] {$name}  ({$info['applied_at']})\n";
            }
            echo "\nPending:\n";
            if (!$s['pending']) {
                echo "  (none)\n";
            }
            foreach ($s['pending'] as $name) {
                echo "  - {$name}\n";
            }
            if (!empty($s['missing'])) {
                echo "\nMissing files for applied migrations:\n";
                foreach ($s['missing'] as $name) {
                    echo "  ! {$name}\n";
                }
            }
            break;

        case 'create':
            $name = $argvLocal[2] ?? '';
            if ($name === '') {
                fwrite(STDERR, "Usage: php cli/migrate.php create <name>\n");
                exit(2);
            }
            $path = $migrator->create($name);
            echo "Created: {$path}\n";
            break;

        case 'baseline':
            echo "Baselining existing migrations...\n";
            $marked = $migrator->baseline($logger);
            $n = count($marked);
            echo $n === 0 ? "Nothing to baseline.\n" : "Marked {$n} migration(s) as applied.\n";
            break;

        case '-h':
        case '--help':
        case 'help':
            echo "Usage:\n";
            echo "  php cli/migrate.php                 Apply all pending migrations\n";
            echo "  php cli/migrate.php status          Show applied / pending / missing\n";
            echo "  php cli/migrate.php create <name>   Generate stub migration file\n";
            echo "  php cli/migrate.php baseline        Mark current files as applied (no run)\n";
            echo "Flags:\n";
            echo "  --skip-backup                       Skip pre-migration backup (DANGEROUS)\n";
            break;

        default:
            fwrite(STDERR, "Unknown command: {$cmd}\n");
            fwrite(STDERR, "Try: php cli/migrate.php help\n");
            exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
