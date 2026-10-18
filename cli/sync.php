<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — ERP Sync Runner (every store)
 * Usage: php cli/sync.php [--verbose]
 * Cron: every 15 minutes — php /path/to/cli/sync.php
 * A run still in progress makes the next one exit without syncing (the same orders are never pushed twice).
 */
require_once __DIR__ . '/../src/autoload.php';

$verbose = in_array('--verbose', $argv ?? []);

if ($verbose) echo "BareBones Shop — ERP Sync\n";

$cfg = require __DIR__ . '/../config.php';
$jobsDir = $cfg['jobs_dir'] ?? dirname(__DIR__, 2) . '/bareboneseshop-private/jobs';
if (!is_dir($jobsDir)) @mkdir($jobsDir, 0750, true);
$lockFile = $jobsDir . '/sync.lock';
$lock = JobQueue::acquireWorkerLock($lockFile);
if ($lock === null) {
    // A lock that cannot be opened (jobs_dir missing or not writable) is an error cron must see, not a run in progress.
    $probe = @fopen($lockFile, 'c+');
    if (!is_resource($probe)) {
        fwrite(STDERR, "ERP sync: cannot open the lock file {$lockFile} (check jobs_dir permissions).\n");
        exit(1);
    }
    fclose($probe);
    if ($verbose) echo "ERP sync is already running.\n";
    exit(0);
}

$result = Sync::runAllStores();
flock($lock, LOCK_UN);
fclose($lock);

if ($verbose) {
    if (!empty($result['skipped'])) {
        echo "Skipped: {$result['reason']}\n";
    } elseif (!empty($result['error'])) {
        echo "Error: {$result['error']}\n";
    } else {
        echo "Products updated:  {$result['products_updated']}\n";
        echo "Products skipped:  {$result['products_skipped']}\n";
        echo "Stock updated:     {$result['stock_updated']}\n";
        echo "Orders pushed:     {$result['orders_pushed']}\n";
        echo "Orders failed:     {$result['orders_failed']}\n";
        echo "Statuses updated:  {$result['statuses_updated']}\n";
    }
}
