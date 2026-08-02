<?php
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

$orderNumber = trim((string)($argv[1] ?? ''));
if (!preg_match('/^ORD-[A-Z0-9-]+$/', $orderNumber)) {
    throw new InvalidArgumentException('A valid order number is required');
}

Bootstrap::initStoreContext('/', '127.0.0.1');
$order = Orders::byNumber($orderNumber);
if (!$order) throw new RuntimeException('HTML checkout order was not persisted');

$snapshot = json_decode((string)($order['financial_snapshot'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
VatService::validateSnapshot($snapshot);
if ((int)$order['total'] !== (int)$snapshot['totals']['gross']
    || (int)$order['vat_total'] !== (int)$snapshot['totals']['vat']
    || (int)$order['net_total'] !== (int)$snapshot['totals']['net']) {
    throw new RuntimeException('HTML checkout order totals diverge from its financial snapshot');
}

$items = Orders::items((int)$order['id']);
if (!$items || min(array_map(static fn(array $item): int => (int)$item['vat_amount'], $items)) <= 0) {
    throw new RuntimeException('HTML checkout did not persist per-line VAT');
}

if ((string)$order['status'] === 'pending') {
    OrderWorkflow::transition((int)$order['id'], 'paid', 'Disposable browser checkout qualification');
}
$invoice = InvoiceService::issue((int)$order['id']);
$invoiceSnapshot = json_decode((string)($invoice['financial_snapshot'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
if ($invoiceSnapshot !== $snapshot
    || (int)$invoice['total_gross'] !== (int)$snapshot['totals']['gross']
    || (int)$invoice['total_vat'] !== (int)$snapshot['totals']['vat']) {
    throw new RuntimeException('HTML checkout invoice diverges from the immutable financial snapshot');
}

echo json_encode([
    'order_id' => (int)$order['id'],
    'snapshot_fingerprint' => (string)$snapshot['fingerprint'],
    'vat' => (int)$snapshot['totals']['vat'],
    'net' => (int)$snapshot['totals']['net'],
    'invoice_id' => (int)$invoice['id'],
], JSON_THROW_ON_ERROR);
