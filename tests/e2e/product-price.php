<?php
// Sets one product's price on the disposable E2E shop (R49: a price that changes after the checkout page loaded).
// Usage: php tests/e2e/product-price.php <slug> <cents>
require_once __DIR__ . '/../../cli/cli-only.php';
require_once __DIR__ . '/../../src/autoload.php';

$config = require dirname(__DIR__, 2) . '/config.php';
$driver = (string)($config['db_driver'] ?? '');
$safe = $driver === 'sqlite'
    ? str_contains(strtolower(str_replace('\\', '/', (string)($config['db_sqlite_path'] ?? ''))), 'barebones-e2e')
    : preg_match('/^[A-Za-z0-9_]+_e2e_test$/', (string)($config['db_name'] ?? '')) === 1;
if (!$safe) {
    throw new RuntimeException('Refusing to change a database that is not explicitly disposable E2E storage');
}
Bootstrap::initStoreContext('/', '127.0.0.1', null);
$slug = (string)($argv[1] ?? '');
$cents = (string)($argv[2] ?? '');
if (!preg_match('/^[a-z0-9-]+$/', $slug) || !ctype_digit($cents)) throw new InvalidArgumentException('Usage: product-price.php <slug> <cents>');

$stmt = Db::get()->prepare("UPDATE products SET price = :price WHERE slug = :slug AND store_id = 'default'");
$stmt->execute([':price' => (int)$cents, ':slug' => $slug]);
if ($stmt->rowCount() !== 1) throw new RuntimeException("Product {$slug} not found");
Cache::catalogChanged();
echo "{$slug} {$cents}\n";
