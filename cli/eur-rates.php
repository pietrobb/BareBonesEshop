<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Store the ECB reference rates the 10 000 EUR OSS threshold converts sales in other currencies with (EurRates).
 * Usage: php cli/eur-rates.php — the ECB publishes them on working days around 16:00 CET, e.g.:
 *   35 17 * * 1-5 php /path/to/shop/cli/eur-rates.php
 * Without it the fixed eur_exchange_rates of config/vat-rates.json are used (the setup doctor warns when they age).
 * A feed that lacks a stored currency or moves a rate by more than 50 % is refused (the stored rates stay); after
 * checking it with the ECB, store it with: php cli/eur-rates.php --force (never a rate 10 times away from the old one)
 * Then documents of this and last year in another currency still without their EUR at issue are converted at the ECB rate
 * of their day (fetched from the ECB history when not stored yet; OssThreshold::convertPending()).
 */
require_once __DIR__ . '/../src/autoload.php';

try {
    $db = Db::get();
    $result = EurRates::refresh($db, in_array('--force', array_slice($argv, 1), true));
    echo "ECB rates of {$result['as_of']}: {$result['count']} currencies stored\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ECB rates not updated: ' . $e->getMessage() . "\n");
    exit(1);
}
// R41 (medium 3): documents in another currency issued without the ECB rate of their day (before this cron line, or
// before R41) are converted at that rate now, from the ECB's history.
try {
    $converted = OssThreshold::convertPending($db);
    if ($converted['pending'] > 0) {
        $history = EurRates::fetchHistory($db, (string)$converted['oldest'], $converted['currencies']);
        if ($history['refused'] !== []) fwrite(STDERR, 'ECB history rates refused (more than 50 % from today\'s): ' . implode(', ', array_slice($history['refused'], 0, 10)) . "\n");
        $converted = ['converted' => $converted['converted'] + OssThreshold::convertPending($db)['converted']] + OssThreshold::pending($db);
    }
    echo "OSS documents converted to EUR at the rate of their day: {$converted['converted']}; still counted at today's rate: {$converted['pending']}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'OSS documents not converted: ' . $e->getMessage() . "\n");
    exit(1);
}
