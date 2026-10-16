<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Restore the database (SQLite or MySQL/MariaDB) and stored files from a backup
 *
 * Usage:
 *   php cli/restore.php <remote_path>            Restore from backup at remote_path
 *   php cli/restore.php --latest                 Restore from newest backup
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
    $db = Db::get();
    BackupService::init($db);
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
    // The newest restore point, never a .gz companion that shares its creation stamp.
    $rows = array_values(array_filter(BackupService::list(), static fn(array $row): bool => !BackupService::isCompressedCopy($row)));
    if (!$rows) { fwrite(STDERR, "No backups available.\n"); exit(2); }
    $remotePath = (string)$rows[0]['path'];
} else {
    $remotePath = $positional[0] ?? null;
}

if (!$remotePath) {
    fwrite(STDERR, "Usage: php cli/restore.php <remote_path>|--latest|--list [--skip-snapshot] [--quiet] [--yes] [--i-stopped-writers] [--files|--files-only] [--force]\n");
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

if (!$filesOnly) {
    try {
        $res = BackupService::restore($remotePath, [
            'skip_snapshot'   => $skipSnapshot,
            'writers_stopped' => $writersStopped,
        ]);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'RESTORE FAILED: ' . $e->getMessage() . "\n");
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
exit(0);
