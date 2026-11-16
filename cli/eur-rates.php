<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Store the ECB reference rates the 10 000 EUR OSS threshold converts sales in other currencies with (EurRates).
 * Usage: php cli/eur-rates.php — the ECB publishes them on working days around 16:00 CET, e.g.:
 *   35 17 * * 1-5 php /path/to/shop/cli/eur-rates.php
 * Without it the fixed eur_exchange_rates of config/vat-rates.json are used (the setup doctor warns when they age).
 * A feed that lacks a stored currency or moves a rate by more than 50 % is refused (the stored rates stay); after
 * checking it with the ECB, store it with: php cli/eur-rates.php --force
 */
require_once __DIR__ . '/../src/autoload.php';

try {
    $result = EurRates::refresh(Db::get(), in_array('--force', array_slice($argv, 1), true));
    echo "ECB rates of {$result['as_of']}: {$result['count']} currencies stored\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ECB rates not updated: ' . $e->getMessage() . "\n");
    exit(1);
}
