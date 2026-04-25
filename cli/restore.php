<?php
/**
 * BareBones Shop — Restore SQLite DB from backup
 *
 * Usage:
 *   php cli/restore.php <remote_path>            Restore from backup at remote_path
 *   php cli/restore.php --latest                 Restore from newest backup
 *   php cli/restore.php --list                   List available backups (newest first)
 *   php cli/restore.php <path> --skip-snapshot   Don't take a pre-restore-snapshot (DANGEROUS)
 *   php cli/restore.php <path> --quiet           Minimal output
 *   php cli/restore.php <path> --yes             Skip interactive confirmation
 *
 * Safety: takes a `pre-restore-snapshot` backup of the current live DB before replacing it,
 *         unless --skip-snapshot is passed. The snapshot is retained (max 5 newest).
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
        $emit(sprintf('%s  size=%d  sha=%s  label=%s', $r['path'], $size, $sha, $label));
    }
    exit(0);
}

$remotePath = null;
if (isset($flags['latest'])) {
    $rows = BackupService::list();
    if (!$rows) { fwrite(STDERR, "No backups available.\n"); exit(2); }
    $remotePath = $rows[0]['path'];
} else {
    $remotePath = $positional[0] ?? null;
}

if (!$remotePath) {
    fwrite(STDERR, "Usage: php cli/restore.php <remote_path>|--latest|--list [--skip-snapshot] [--quiet] [--yes]\n");
    exit(1);
}

$emit("Restoring from: {$remotePath}");
$emit('Live DB: ' . (BackupService::livePath() ?: '(unknown)'));
if ($skipSnapshot) {
    $emit('WARNING: --skip-snapshot — pre-restore-snapshot will NOT be taken.');
}

if (!$yes && !$quiet) {
    echo "Type 'yes' to continue: ";
    $line = trim((string)fgets(STDIN));
    if (strtolower($line) !== 'yes') { $emit('Aborted.'); exit(1); }
}

try {
    $res = BackupService::restore($remotePath, [
        'skip_snapshot' => $skipSnapshot,
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
exit(0);
