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
            // R15: with pending migrations the shop is in maintenance (web 503, the worker waits) as during an update:
            // a long migration otherwise ran against live checkout writes (deadlocks, half-migrated reads). The
            // updater's own window (marker already there) is left to the updater.
            // R17: a run that fails leaves the shop closed (a migration may have stopped half-way, MySQL DDL does not
            // roll back); the next successful run, which completes the schema, takes that window over and lifts it.
            $root = dirname(__DIR__);
            $ownMaintenance = false;
            $backupTaken = false;
            $beforeFirst = static function (array $names, callable $log) use (&$backupTaken, &$ownMaintenance, $preApplyHook, $root): void {
                if (!$ownMaintenance && (!MaintenanceMode::active($root) || MaintenanceMode::failed($root, 'cli/migrate.php'))) {
                    MaintenanceMode::enter($root, 'cli/migrate.php: ' . count($names) . ' pending migration(s)');
                    $ownMaintenance = true;
                    $log('  [maintenance] the shop answers 503 until the migrations finish');
                }
                if ($backupTaken || $preApplyHook === null) return;
                $preApplyHook($names, $log);
                $backupTaken = true;
            };
            try {
                $applied = $migrator->apply($logger, $beforeFirst);
                foreach (Extensions::migrators() as $code => $extensionMigrator) {
                    $applied = array_merge($applied, $extensionMigrator->apply($logger, $beforeFirst));
                }
            } catch (Throwable $migrationError) {
                if ($ownMaintenance) {
                    MaintenanceMode::fail($root, 'cli/migrate.php failed: ' . $migrationError->getMessage()
                        . ' — the shop stays in maintenance; fix the cause and run php cli/migrate.php apply again');
                    fwrite(STDERR, "The shop stays in maintenance (503) until php cli/migrate.php apply succeeds.\n");
                }
                throw $migrationError;
            }
            // Every migration is applied: a window this run opened, or one a failed run left, ends here.
            if ($ownMaintenance || MaintenanceMode::failed($root, 'cli/migrate.php')) MaintenanceMode::leave($root);
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
