<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Restore the database (SQLite or MySQL/MariaDB) and stored files from a backup
 *
 * Usage:
 *   php cli/restore.php <remote_path>            Restore from backup at remote_path (relative to the backup storage, as
 *                                                --list prints it; an absolute path to a file in it is accepted too)
 *   php cli/restore.php --latest                 Restore from newest backup (never a pre-restore-snapshot)
 *   php cli/restore.php --list                   List available backups (newest first)
 *   php cli/restore.php <path> --skip-snapshot   Don't take a pre-restore-snapshot (DANGEROUS; SQLite only)
 *   php cli/restore.php <path> --quiet           Minimal output
 *   php cli/restore.php <path> --yes             Skip interactive confirmation
 *   php cli/restore.php <path> --i-stopped-writers
 *                                                Required for a MySQL/MariaDB (.sql) restore: confirms web
 *                                                traffic, workers and cron are stopped (db_name is dropped
 *                                                and re-imported; anything written meanwhile is lost)
 *   php cli/restore.php <path> --files           Also restore the backup's invoices/uploads/GDPR-salt archive
 *   php cli/restore.php <path> --files-only      Restore only that files archive, not the database
 *   php cli/restore.php <path> --files --force   Overwrite live files whose content differs from the backup
 *
 * Safety: the backup is verified (identity, size, sha256) before anything changes. SQLite is checked
 *         with integrity_check and swapped under the exclusive maintenance gate. MySQL is imported into
 *         the scratch database (backup_mysql_restore_scratch_database) and checked row-for-row first.
 *         A `pre-restore-snapshot` backup of the current database is taken unless --skip-snapshot
 *         (retained, max 5 newest). Files are extracted into a private staging directory by a strict
 *         reader (regular files only, no absolute/../link entries); differing live files are only
 *         overwritten with --force, and files missing from the backup are kept.
 */
require_once __DIR__ . '/../src/autoload.php';

$argvLocal = $argv ?? [];
array_shift($argvLocal); // drop script name

$flags = [];
$positional = [];
foreach ($argvLocal as $a) {
    if (str_starts_with($a, '--')) {
        $eq = strpos($a, '=');
        if ($eq !== false) {
            $flags[substr($a, 2, $eq - 2)] = substr($a, $eq + 1);
        } else {
            $flags[substr($a, 2)] = true;
        }
    } else {
        $positional[] = $a;
    }
}

$quiet = isset($flags['quiet']);
$skipSnapshot = isset($flags['skip-snapshot']);
$yes = isset($flags['yes']);
$filesOnly = isset($flags['files-only']);
$withFiles = $filesOnly || isset($flags['files']);
$force = isset($flags['force']);
$writersStopped = isset($flags['i-stopped-writers']);
$emit = static function (string $s) use ($quiet) { if (!$quiet) echo $s . "\n"; };

try {
    // No local reference: restore() must be able to close every connection of this process
    // before the maintenance gate proves no other process holds the SQLite file.
    BackupService::init(Db::get());
} catch (\Throwable $e) {
    fwrite(STDERR, 'Init failed: ' . $e->getMessage() . "\n");
    exit(3);
}

if (isset($flags['list'])) {
    $rows = BackupService::list();
    if (!$rows) { $emit('No backups found.'); exit(0); }
    foreach ($rows as $r) {
        $label = (string)($r['meta']['label'] ?? '?');
        $sha   = substr((string)($r['meta']['sha256'] ?? ''), 0, 12);
        $size  = (int)($r['size'] ?? 0);
        $files = !empty($r['meta']['files_archive']) ? '  files=yes' : '';
        $emit(sprintf('%s  size=%d  sha=%s  label=%s%s', $r['path'], $size, $sha, $label, $files));
    }
    exit(0);
}

$remotePath = null;
if (isset($flags['latest'])) {
    // The newest restore point: never a .gz companion and never a pre-restore snapshot (a copy of an earlier live).
    $latest = BackupService::latestRestorePoint();
    if ($latest === null) { fwrite(STDERR, "No backups available (pre-restore snapshots are restored only by explicit path).\n"); exit(2); }
    $remotePath = (string)$latest['path'];
} else {
    $remotePath = $positional[0] ?? null;
}

if (!$remotePath) {
    fwrite(STDERR, "Usage: php cli/restore.php <remote_path>|--latest|--list [--skip-snapshot] [--quiet] [--yes] [--i-stopped-writers] [--files|--files-only] [--force]\n");
    exit(1);
}
try {
    $remotePath = BackupService::storagePath($remotePath);
} catch (\Throwable $e) {
    fwrite(STDERR, 'REFUSED: ' . $e->getMessage() . "\n");
    exit(1);
}
$isMysqlDump = preg_match('/\.sql(?:\.gz)?$/', $remotePath) === 1;
if (!$filesOnly && $isMysqlDump && !$writersStopped) {
    fwrite(STDERR, "REFUSED: a MySQL/MariaDB restore drops and re-imports db_name, discarding anything written while it runs.\n"
        . "Stop web traffic (maintenance page), workers and cron, then re-run with --i-stopped-writers.\n");
    exit(1);
}
if (!$filesOnly && $isMysqlDump && $skipSnapshot) {
    fwrite(STDERR, "REFUSED: --skip-snapshot is not supported for a MySQL/MariaDB restore; the pre-restore-snapshot is the stored copy\n"
        . "of the current database if the restore and its automatic rollback both fail.\n");
    exit(1);
}

$emit("Restoring from: {$remotePath}" . ($filesOnly ? ' (files archive only)' : ($withFiles ? ' (database + files archive)' : '')));
if (!$filesOnly) {
    $emit('Live DB: ' . ($isMysqlDump ? '(MySQL/MariaDB db_name, verified in the scratch database first)' : (BackupService::livePath() ?: '(unknown)')));
}
if ($skipSnapshot && !$filesOnly) {
    $emit('WARNING: --skip-snapshot — pre-restore-snapshot will NOT be taken.');
}
if ($withFiles && $force) {
    $emit('WARNING: --force — live files that differ from the backup will be overwritten.');
}

if (!$yes && !$quiet) {
    echo "Type 'yes' to continue: ";
    $line = trim((string)fgets(STDIN));
    if (strtolower($line) !== 'yes') { $emit('Aborted.'); exit(1); }
}

// R23: the shop (503) and the worker stay closed while the database and files are replaced, as during an update. A
// window that an update or migration holds is not taken over; a failed one stays after the restore (it is not fixed).
// Whether that step still runs is told by its lock, not the failed flag: a running step marks its window failed (arm())
// before its first change, so only a failed window that no process holds was left by a dead one.
$root = dirname(__DIR__);
if (MaintenanceMode::heldElsewhere($root) || (MaintenanceMode::active($root) && !MaintenanceMode::failed($root))) {
    fwrite(STDERR, 'REFUSED: another maintenance window is open (' . (string)(MaintenanceMode::marker($root)['reason'] ?? '')
        . "); wait until that update or migration ends, then retry.\n");
    exit(1);
}
$priorFailure = MaintenanceMode::failed($root) ? (string)(MaintenanceMode::marker($root)['reason'] ?? '') : null;
$restoreCommand = 'php cli/restore.php ' . escapeshellarg($remotePath) . ($filesOnly ? ' --files-only' : ($withFiles ? ' --files' : ''));
MaintenanceMode::enter($root, 'cli/restore.php ' . $remotePath);
// Killed from here on, the shop stays closed with what to run (no finally runs).
MaintenanceMode::arm($root, "cli/restore.php {$remotePath} was interrupted before it finished; check the database and files, then run {$restoreCommand} again");
$endWindow = static function (?string $halfDone) use ($root, $priorFailure): void {
    if ($halfDone !== null) {
        MaintenanceMode::fail($root, $halfDone);
        fwrite(STDERR, "The shop stays in maintenance (503) until that is resolved.\n");
    } elseif (!MaintenanceMode::owned($root)) {
        // Another step took the window over meanwhile; it is that step's to lift.
    } elseif ($priorFailure !== null) {
        MaintenanceMode::fail($root, $priorFailure);
    } else {
        MaintenanceMode::leave($root);
    }
};
/** Whether a failed restore left something half-replaced (its own automatic rollback failed, or some files are written). */
$leftHalfDone = static fn(Throwable $e): bool => $e->getCode() === MysqlUpdateDatabaseBackup::RESTORE_FAILED_AFTER_DROP
    || str_contains($e->getMessage(), 'automatic rollback failed')
    || preg_match('/\d+ file\(s\) were already written/', $e->getMessage()) === 1;

if (!$filesOnly) {
    try {
        $res = BackupService::restore($remotePath, [
            'skip_snapshot'   => $skipSnapshot,
            'writers_stopped' => $writersStopped,
        ]);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'RESTORE FAILED: ' . $e->getMessage() . "\n");
        $endWindow($leftHalfDone($e) ? "cli/restore.php {$remotePath} failed half-way ({$e->getMessage()}); fix the cause and run {$restoreCommand} again" : null);
        exit(4);
    }

    $emit('OK');
    $emit('  integrity:    ' . $res['integrity']);
    $emit('  snapshot:     ' . ($res['snapshot'] ?? '(skipped)'));
    $emit('  applied:      ' . $res['applied_count']);
    $emit('  pending:      ' . $res['pending_count']);
    foreach ($res['schema_warnings'] as $w) {
        fwrite(STDERR, 'WARNING: ' . $w . "\n");
    }
}

if ($withFiles) {
    try {
        $files = BackupService::restoreFiles($remotePath, ['force' => $force]);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'FILES RESTORE FAILED: ' . $e->getMessage() . "\n");
        $endWindow($leftHalfDone($e) ? "cli/restore.php {$remotePath} failed half-way ({$e->getMessage()}); fix the cause and run {$restoreCommand} again" : null);
        exit(5);
    }
    $emit('FILES OK ' . $files['archive']);
    $emit('  written:      ' . $files['written']);
    $emit('  unchanged:    ' . $files['unchanged']);
    $emit('  overwritten:  ' . count($files['overwritten']));
    foreach ($files['skipped_sources'] as $name) {
        fwrite(STDERR, "WARNING: files source '{$name}' is not configured here; its archived files were not restored\n");
    }
}
$endWindow(null);
exit(0);
