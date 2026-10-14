<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Manual / Cron Backup CLI
 *
 * Usage:
 *   php cli/backup.php                       Default (cron) backup, label=scheduled — subject to retention GC
 *   php cli/backup.php --label=manual        Keep forever: manual/pre-migration/pre-restore-snapshot are never GC'd
 *   php cli/backup.php --label=before-import Custom label (subject to retention GC)
 *   php cli/backup.php --gz                  Also produce gzipped copy
 *   php cli/backup.php --gc                  Also run retention GC
 *   php cli/backup.php --gc-only             Only run GC, skip backup
 *   php cli/backup.php --quiet               Print only essential lines (cron-friendly)
 *
 * Exit codes:
 *   0  success
 *   1  backup failed
 *   2  GC failed
 *   3  config invalid / disabled
 */
require_once __DIR__ . '/../src/autoload.php';

$argvLocal = $argv ?? [];
$opts = [
    'label'    => 'scheduled',
    'gz'       => false,
    'gc'       => false,
    'gc_only'  => false,
    'quiet'    => false,
];
foreach (array_slice($argvLocal, 1) as $a) {
    if ($a === '--gz') { $opts['gz'] = true; continue; }
    if ($a === '--gc') { $opts['gc'] = true; continue; }
    if ($a === '--gc-only') { $opts['gc_only'] = true; continue; }
    if ($a === '--quiet') { $opts['quiet'] = true; continue; }
    if (str_starts_with($a, '--label=')) { $opts['label'] = substr($a, 8); continue; }
    fwrite(STDERR, "Unknown arg: {$a}\n");
    exit(3);
}

$say = static function (string $msg) use ($opts): void {
    if (!$opts['quiet']) echo $msg . "\n";
};

try {
    BackupService::init(Db::get(), null, dirname(__DIR__));
} catch (\Throwable $e) {
    fwrite(STDERR, 'Init failed: ' . $e->getMessage() . "\n");
    exit(3);
}

if (!BackupService::isEnabled()) {
    fwrite(STDERR, "Backups disabled in config/backup.json\n");
    exit(3);
}

if (!$opts['gc_only']) {
    try {
        $say('Creating backup (label=' . $opts['label'] . ')...');
        $meta = BackupService::create([
            'label'    => $opts['label'],
            'trigger'  => $opts['label'] === 'manual' ? 'manual' : 'auto',
            'compress' => $opts['gz'],
        ]);
        $say('OK ' . $meta['remote_path'] . ' size=' . $meta['size_bytes'] . ' sha256=' . $meta['sha256']);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Backup FAILED: ' . $e->getMessage() . "\n");
        BackupService::log('CLI backup FAIL: ' . $e->getMessage());
        exit(1);
    }
}

if ($opts['gc'] || $opts['gc_only']) {
    try {
        $gc = BackupService::gc();
        $say('GC: kept=' . $gc['kept'] . ', deleted=' . count($gc['deleted']));
        foreach ($gc['deleted'] as $p) $say('  -' . $p);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'GC FAILED: ' . $e->getMessage() . "\n");
        BackupService::log('CLI GC FAIL: ' . $e->getMessage());
        exit(2);
    }
}

exit(0);
