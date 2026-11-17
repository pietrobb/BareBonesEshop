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

// R41: a refused daily feed still lets the documents waiting for the rate of their day convert (EurRates::daily()).
$run = EurRates::daily(Db::get(), in_array('--force', array_slice($argv, 1), true));
foreach ($run['out'] as $line) echo $line, "\n";
foreach ($run['err'] as $line) fwrite(STDERR, $line . "\n");
exit($run['exit']);
