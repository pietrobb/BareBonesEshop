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
            // R18: a run that stops before its first migration (the pre-migration backup failed, e.g. MySQL with an
            // unusable mariadb-dump) changed nothing: the window goes back to what it was, open or a failed one.
            $root = dirname(__DIR__);
            $ownMaintenance = false;
            $backupTaken = false;
            $schemaTouched = false;
            $priorFailure = MaintenanceMode::failed($root, 'cli/migrate.php') ? (string)(MaintenanceMode::marker($root)['reason'] ?? '') : null;
            $beforeFirst = static function (array $names, callable $log) use (&$backupTaken, &$ownMaintenance, &$schemaTouched, $preApplyHook, $root): void {
                // R23: an update, rollback or other migration that is running holds its window; migrating under it and
                // lifting it at the end opened the shop in the middle of that step. Held is held, failed-marked or not:
                // a running step marks its window failed (arm()) before its first change.
                // R24: the step lock first (MaintenanceMode::claim()): an update entering between this check and enter() was
                // overwritten by this migration's window, which then lifted it while the update still ran.
                if (!MaintenanceMode::claim($root) || (!$ownMaintenance && MaintenanceMode::heldElsewhere($root))) {
                    throw new RuntimeException('Another maintenance window is open (' . MaintenanceMode::busy($root)
                        . '); no migration ran. Wait until that step ends, then run php cli/migrate.php apply again');
                }
                if (!$ownMaintenance && (!MaintenanceMode::active($root) || MaintenanceMode::failed($root, 'cli/migrate.php'))) {
                    MaintenanceMode::enter($root, 'cli/migrate.php: ' . count($names) . ' pending migration(s)');
                    $ownMaintenance = true;
                    $log('  [maintenance] the shop answers 503 until the migrations finish');
                }
                if (!$backupTaken && $preApplyHook !== null) {
                    $preApplyHook($names, $log);
                    $backupTaken = true;
                }
                // R23: a run killed during a migration (no catch runs) keeps the shop closed instead of an expiring marker.
                if ($ownMaintenance) {
                    MaintenanceMode::arm($root, 'cli/migrate.php was interrupted during a migration — the shop stays in maintenance; fix the cause and run php cli/migrate.php apply again');
                }
                $schemaTouched = true; // Migrator::apply() runs the pending migrations right after this hook
            };
            try {
                $applied = $migrator->apply($logger, $beforeFirst);
                foreach (Extensions::migrators() as $code => $extensionMigrator) {
                    $applied = array_merge($applied, $extensionMigrator->apply($logger, $beforeFirst));
                }
            } catch (Throwable $migrationError) {
                if ($ownMaintenance && !MaintenanceMode::owned($root)) {
                    // R23: another step took the window over meanwhile; it is that step's to lift.
                } elseif ($ownMaintenance && !$schemaTouched) {
                    if ($priorFailure !== null) {
                        MaintenanceMode::fail($root, $priorFailure);
                        fwrite(STDERR, "No migration ran; the shop stays in maintenance (503) from the earlier failed run until php cli/migrate.php apply succeeds.\n");
                    } else {
                        MaintenanceMode::leave($root);
                        fwrite(STDERR, "No migration ran; the shop is open again on the unchanged schema.\n");
                    }
                } elseif ($ownMaintenance) {
                    MaintenanceMode::fail($root, 'cli/migrate.php failed: ' . $migrationError->getMessage()
                        . ' — the shop stays in maintenance; fix the cause and run php cli/migrate.php apply again');
                    fwrite(STDERR, "The shop stays in maintenance (503) until php cli/migrate.php apply succeeds.\n");
                }
                throw $migrationError;
            }
            // Every migration is applied: a window this run opened, or one a failed run left, ends here.
            // R23: only while the marker is still this run's own — never the window of an update that took over meanwhile.
            // R24: a failed migration window is lifted only under the step lock: an update that took it over may be running.
            if ($ownMaintenance ? MaintenanceMode::owned($root) : MaintenanceMode::failed($root, 'cli/migrate.php') && MaintenanceMode::claim($root)) MaintenanceMode::leave($root);
            MaintenanceMode::unclaim($root);
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
