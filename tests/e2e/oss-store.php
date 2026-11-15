<?php
// Turns OSS on (legal country SK, cross_border_pricing left at its default "net") or off on the default E2E store,
// for oss-pricing.spec.js only: the other specs switch countries under the store's non-OSS prices.
// Usage: php tests/e2e/oss-store.php on|off
require_once __DIR__ . '/../../cli/cli-only.php';
require_once __DIR__ . '/../../src/autoload.php';

$config = require dirname(__DIR__, 2) . '/config.php';
$driver = (string)($config['db_driver'] ?? '');
$safe = $driver === 'sqlite'
    ? str_contains(strtolower(str_replace('\\', '/', (string)($config['db_sqlite_path'] ?? ''))), 'barebones-e2e')
    : preg_match('/^[A-Za-z0-9_]+_e2e_test$/', (string)($config['db_name'] ?? '')) === 1;
if (!$safe) {
    throw new RuntimeException('Refusing to change a store that is not explicitly disposable E2E storage');
}
$mode = (string)($argv[1] ?? '');
if (!in_array($mode, ['on', 'off'], true)) throw new InvalidArgumentException('Usage: oss-store.php on|off');

$stores = StoreSettings::load();
$stores['stores']['default']['oss_enabled'] = $mode === 'on';
$stores['stores']['default']['legal']['country'] = 'SK';
unset($stores['stores']['default']['cross_border_pricing']);
$path = DataOverrides::storesWritePath() ?? throw new RuntimeException('No writable stores.json location for the E2E shop');
file_put_contents($path, json_encode($stores, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
echo "OSS {$mode}\n";
