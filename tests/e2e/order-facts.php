<?php
// Read-only order facts for browser tests (no state change, unlike assert-checkout.php).
// Usage: php tests/e2e/order-facts.php ORD-…   |   php tests/e2e/order-facts.php --email=x@example.test
require_once __DIR__ . '/../../cli/cli-only.php';
require_once __DIR__ . '/../../src/autoload.php';

$config = require dirname(__DIR__, 2) . '/config.php';
$driver = (string)($config['db_driver'] ?? '');
$safe = $driver === 'sqlite'
    ? str_contains(strtolower(str_replace('\\', '/', (string)($config['db_sqlite_path'] ?? ''))), 'barebones-e2e')
    : preg_match('/^[A-Za-z0-9_]+_e2e_test$/', (string)($config['db_name'] ?? '')) === 1;
if (!$safe) {
    throw new RuntimeException('Refusing to inspect a database that is not explicitly disposable E2E storage');
}

Bootstrap::initStoreContext('/', '127.0.0.1');
$arg = trim((string)($argv[1] ?? ''));
if (str_starts_with($arg, '--email=')) {
    $stmt = Db::get()->prepare('SELECT COUNT(*) FROM orders WHERE customer_email = :email');
    $stmt->execute([':email' => substr($arg, 8)]);
    echo json_encode(['count' => (int)$stmt->fetchColumn()], JSON_THROW_ON_ERROR);
    exit;
}
if (!preg_match('/^ORD-[A-Z0-9-]+$/', $arg)) {
    throw new InvalidArgumentException('A valid order number or --email= is required');
}
$order = Orders::byNumber($arg);
if (!$order) throw new RuntimeException('Order not found');
echo json_encode([
    'order_id' => (int)$order['id'],
    'total' => (int)$order['total'],
    'status' => (string)$order['status'],
    'payment_status' => (string)($order['payment_status'] ?? ''),
], JSON_THROW_ON_ERROR);
