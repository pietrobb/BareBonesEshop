<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Database Migration CLI
 *
 * Usage:
 *   php cli/migrate.php                 Apply all pending migrations (core, then enabled extensions)
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
        $db = Db::get();
        $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $applicationTables = $driver === 'sqlite'
            ? (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations'")->fetchColumn()
            : (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME <> 'schema_migrations'")->fetchColumn();
        if ($applicationTables === 0) {
            $log("  [backup] fresh schema has no application tables; no backup required.");
            return;
        }
        try {
            BackupService::init($db);
        } catch (\Throwable $e) {
            // Fail closed: an unusable backup configuration must not turn into an unbacked migration.
            $backupCfg = BackupService::loadConfig(dirname(__DIR__) . '/config/backup.json');
            if (empty($backupCfg['enabled']) || empty($backupCfg['auto_pre_migration'])) {
                return; // explicitly disabled in config
            }
            throw new \RuntimeException(
                'Pre-migration backup unavailable (backup init failed: ' . $e->getMessage() . ')' .
                ' — fix the backup configuration, or re-run with --skip-backup to override (NOT RECOMMENDED).'
            );
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
            // Core first, then each enabled extension's migrations; at most one pre-migration backup.
            $backupTaken = false;
            $backupOnce = $preApplyHook === null ? null : static function (array $names, callable $log) use (&$backupTaken, $preApplyHook): void {
                if ($backupTaken) return;
                $preApplyHook($names, $log);
                $backupTaken = true;
            };
            $applied = $migrator->apply($logger, $backupOnce);
            foreach (Extensions::migrators() as $code => $extensionMigrator) {
                $applied = array_merge($applied, $extensionMigrator->apply($logger, $backupOnce));
            }
            $n = count($applied);
            echo $n === 0 ? "Nothing to apply.\n" : "Applied {$n} migration(s).\n";
            break;

        case 'status':
            $s = $migrator->status();
            foreach (Extensions::migrators() as $extensionMigrator) {
                $extensionStatus = $extensionMigrator->status();
                $s['applied'] += $extensionStatus['applied'];
                $s['pending'] = array_merge($s['pending'], $extensionStatus['pending']);
                $s['missing'] = array_merge($s['missing'], $extensionStatus['missing']);
            }
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
