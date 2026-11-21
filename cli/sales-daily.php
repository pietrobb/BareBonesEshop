<?php
require_once __DIR__ . '/cli-only.php';

/**
 * cli/sales-daily.php — bring the daily sales totals of the reports up to date (SalesDaily).
 *
 * Usage:
 *   php cli/sales-daily.php            build the days closed or changed since the last run
 *   php cli/sales-daily.php --rebuild  build every day again from the orders (after orders were deleted by hand)
 */
require_once __DIR__ . '/../src/autoload.php';

$args = getopt('', ['rebuild']);
$start = microtime(true);
if (!SalesDaily::refreshAll(isset($args['rebuild']))) {
    fwrite(STDERR, "The daily sales tables do not exist: run php cli/migrate.php first.\n");
    exit(1);
}
printf("Daily sales totals up to date (%d ms).\n", (int)round((microtime(true) - $start) * 1000));
