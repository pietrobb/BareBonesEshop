<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Feed Sync Runner
 *
 * Usage:
 *   php cli/feed-sync.php                  — sync all due feeds
 *   php cli/feed-sync.php --all            — sync all enabled feeds (ignore schedule)
 *   php cli/feed-sync.php --feed=neosolar  — sync specific feed by code
 *   php cli/feed-sync.php --feed=3         — sync specific feed by ID
 *   php cli/feed-sync.php --verbose        — show progress
 *   php cli/feed-sync.php --list           — list all feeds and status
 *
 * Cron example: 0 0,6,12,18 * * * php /path/to/cli/feed-sync.php --verbose
 */
require_once __DIR__ . '/../src/autoload.php';

$verbose = in_array('--verbose', $argv ?? []);
$all = in_array('--all', $argv ?? []);
$list = in_array('--list', $argv ?? []);

// Parse --feed=xxx
$feedArg = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--feed=')) {
        $feedArg = substr($arg, 7);
    }
}

if ($verbose) {
    echo "BareBones Shop — Feed Sync\n";
    echo date('Y-m-d H:i:s') . "\n\n";
}

if ($list) {
    $feeds = Warehouse::allFeeds();
    if (empty($feeds)) {
        echo "No feeds configured.\n";
        exit;
    }
    printf("%-4s %-20s %-15s %-8s %-20s %-6s %s\n", 'ID', 'Code', 'Warehouse', 'Format', 'Last Sync', 'Count', 'Status');
    echo str_repeat('-', 100) . "\n";
    foreach ($feeds as $f) {
        printf("%-4d %-20s %-15s %-8s %-20s %-6d %s\n",
            $f['id'], $f['code'], $f['warehouse_code'], $f['format'],
            $f['last_sync'] ?? 'never', $f['last_count'] ?? 0,
            $f['enabled'] ? ($f['last_status'] ?? '—') : 'DISABLED'
        );
    }
    exit;
}

if ($feedArg !== null) {
    // Sync specific feed
    if (is_numeric($feedArg)) {
        $feed = Warehouse::feedById((int)$feedArg);
    } else {
        $stmt = Db::get()->prepare("SELECT * FROM feeds WHERE code = :code");
        $stmt->execute([':code' => $feedArg]);
        $feed = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$feed) {
        echo "Feed not found: {$feedArg}\n";
        exit(1);
    }

    if ($verbose) echo "Syncing feed: {$feed['code']}...\n";
    $result = FeedImport::sync((int)$feed['id'], $verbose);

    if (!empty($result['error'])) {
        echo "ERROR: {$result['error']}\n";
        exit(1);
    }

    if ($verbose) {
        echo "\nDone. Imported: {$result['imported']}, Stock: {$result['stock_updated']}\n";
        if (!empty($result['errors'])) {
            echo "Errors (" . count($result['errors']) . "):\n";
            foreach (array_slice($result['errors'], 0, 10) as $e) echo "  - {$e}\n";
        }
    }
    exit;
}

// Sync all feeds
$results = FeedImport::syncAll($verbose, !$all);

if ($verbose) {
    echo "\n=== Summary ===\n";
    $totalImported = 0;
    $totalStock = 0;
    $totalErrors = 0;
    foreach ($results as $code => $r) {
        $totalImported += $r['imported'] ?? 0;
        $totalStock += $r['stock_updated'] ?? 0;
        $totalErrors += count($r['errors'] ?? []);
    }
    echo "Feeds synced:    " . count($results) . "\n";
    echo "Products:        {$totalImported}\n";
    echo "Stock updates:   {$totalStock}\n";
    echo "Errors:          {$totalErrors}\n";
}
