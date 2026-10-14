<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Refresh the Packeta pick-up points offered in checkout from the official v5 branch feed.
 *
 * Usage: php cli/packeta-pickup-points.php SK CZ
 * Needs SHIP_PACKETA_API_KEY. Packeta asks for 1–4 refreshes a day, off the full hour, e.g.:
 *   23 7,11,15,19 * * * php /path/to/cli/packeta-pickup-points.php SK CZ
 */
require_once __DIR__ . '/../src/autoload.php';

$countries = array_slice($argv ?? [], 1);
if ($countries === []) {
    fwrite(STDERR, "Usage: php cli/packeta-pickup-points.php SK [CZ ...]\n");
    exit(2);
}
$adapter = Adapters::provider('shipping', 'packeta');
$failed = false;
foreach ($countries as $country) {
    try {
        echo strtoupper($country) . ': ' . $adapter->syncPickupPoints($country) . " pick-up points\n";
    } catch (Throwable $e) {
        fwrite(STDERR, strtoupper($country) . ': ' . $e->getMessage() . "\n");
        $failed = true;
    }
}
exit($failed ? 1 : 0);
