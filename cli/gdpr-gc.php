<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — GDPR Retention Garbage Collector
 *
 * Reads config/data-retention.json and applies each policy via GdprService::applyRetention.
 *
 * Usage:
 *   php cli/gdpr-gc.php                  Dry-run all policies (default — safe)
 *   php cli/gdpr-gc.php --apply          Actually delete/scrub. Triggers BackupService snapshot first.
 *   php cli/gdpr-gc.php --policy=email_log   Run a single named policy
 *   php cli/gdpr-gc.php --quiet          Print one summary line (cron-friendly)
 *
 * Exit codes:
 *   0  success (some or no rows affected)
 *   1  config missing / invalid
 *   2  policy execution error
 *   3  pre-backup failed (only on --apply)
 */
require_once __DIR__ . '/../src/autoload.php';

$argvLocal = $argv ?? [];
$opts = ['apply' => false, 'policy' => null, 'quiet' => false];
foreach (array_slice($argvLocal, 1) as $a) {
    if ($a === '--apply') { $opts['apply'] = true; continue; }
    if ($a === '--quiet') { $opts['quiet'] = true; continue; }
    if (str_starts_with($a, '--policy=')) { $opts['policy'] = substr($a, 9); continue; }
    fwrite(STDERR, "Unknown arg: {$a}\n");
    exit(1);
}

$say = static function (string $msg) use ($opts): void {
    if (!$opts['quiet']) echo $msg . "\n";
};

$cfgPath = __DIR__ . '/../config/data-retention.json';
if (!file_exists($cfgPath)) {
    fwrite(STDERR, "Missing config/data-retention.json\n");
    exit(1);
}
$cfg = json_decode((string)file_get_contents($cfgPath), true);
if (!is_array($cfg) || !isset($cfg['policies']) || !is_array($cfg['policies'])) {
    fwrite(STDERR, "Invalid data-retention.json — missing 'policies'\n");
    exit(1);
}

$policies = $cfg['policies'];
if ($opts['policy'] !== null) {
    if (!isset($policies[$opts['policy']])) {
        fwrite(STDERR, "Unknown policy: {$opts['policy']}\n");
        exit(1);
    }
    $policies = [$opts['policy'] => $policies[$opts['policy']]];
}

// Pre-backup snapshot on --apply
if ($opts['apply']) {
    try {
        if (class_exists('BackupService')) {
            $svc = new BackupService();
            $say("[backup] taking pre-retention snapshot...");
            $info = $svc->create(['label' => 'pre-retention-gc']);
            $say("[backup] OK " . ($info['filename'] ?? '?') . " sha=" . substr($info['sha256'] ?? '', 0, 12));
        } else {
            $say("[backup] BackupService not available — proceeding without snapshot (NOT RECOMMENDED)");
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "Pre-backup failed: " . $e->getMessage() . "\n");
        exit(3);
    }
}

$mode = $opts['apply'] ? 'apply' : 'dry-run';
$say("--- GDPR retention GC (mode={$mode}, policies=" . count($policies) . ") ---");

$totalAffected = 0;
$failures = [];

foreach ($policies as $key => $policy) {
    try {
        $res = GdprService::applyRetention((string)$key, (array)$policy, $mode);
        $totalAffected += (int)$res['affected'];
        $say(sprintf("  [%s] %-28s affected=%d (%dms)",
            $mode === 'apply' ? 'OK' : '..',
            $key,
            $res['affected'],
            $res['duration_ms']
        ));
    } catch (\Throwable $e) {
        $failures[] = "{$key}: " . $e->getMessage();
        fwrite(STDERR, "  [FAIL] {$key}: " . $e->getMessage() . "\n");
    }
}

$summary = sprintf("[%s] %d policies, %d row(s) %s",
    $mode, count($policies), $totalAffected,
    $mode === 'apply' ? 'affected' : 'would-be affected'
);
echo $summary . "\n";

if (!empty($failures)) {
    fwrite(STDERR, "Failures: " . count($failures) . "\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(2);
}
exit(0);
