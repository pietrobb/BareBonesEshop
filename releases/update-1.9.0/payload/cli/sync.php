<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — ERP Sync Runner
 * Usage: php cli/sync.php [--verbose]
 * Cron: every 15 minutes — php /path/to/cli/sync.php
 */
require_once __DIR__ . '/../src/autoload.php';

$verbose = in_array('--verbose', $argv ?? []);

if ($verbose) echo "BareBones Shop — ERP Sync\n";

$result = Sync::run();

if ($verbose) {
    if (!empty($result['skipped'])) {
        echo "Skipped: {$result['reason']}\n";
    } elseif (!empty($result['error'])) {
        echo "Error: {$result['error']}\n";
    } else {
        echo "Products updated:  {$result['products_updated']}\n";
        echo "Stock updated:     {$result['stock_updated']}\n";
        echo "Orders pushed:     {$result['orders_pushed']}\n";
        echo "Orders failed:     {$result['orders_failed']}\n";
        echo "Statuses updated:  {$result['statuses_updated']}\n";
    }
}
