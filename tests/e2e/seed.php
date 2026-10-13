<?php
require_once __DIR__ . '/../../cli/cli-only.php';
require_once __DIR__ . '/../../src/autoload.php';

$config = require dirname(__DIR__, 2) . '/config.php';
$driver = (string)($config['db_driver'] ?? '');
$safe = $driver === 'sqlite'
    ? str_contains(strtolower(str_replace('\\', '/', (string)($config['db_sqlite_path'] ?? ''))), 'barebones-e2e')
    : preg_match('/^[A-Za-z0-9_]+_e2e_test$/', (string)($config['db_name'] ?? '')) === 1;
if (!$safe) {
    throw new RuntimeException('Refusing to seed a database that is not explicitly disposable E2E storage');
}

Bootstrap::initStoreContext('/', '127.0.0.1');
$db = Db::get();
if ((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0) {
    throw new RuntimeException('Run php cli/seed.php --count=30 before the E2E fixture seed');
}
$warehouseId = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
if ($warehouseId <= 0) throw new RuntimeException('Disposable E2E database has no default warehouse');
$stockInsert = $db->prepare(Db::insertIgnore() . ' INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, ?, 0)');
foreach ($db->query("SELECT sku, stock FROM products WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC) as $product) {
    $stockInsert->execute([$warehouseId, (string)$product['sku'], max(0, (int)$product['stock'])]);
}
foreach (['orders', 'admin_accounts'] as $table) {
    if ((int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() !== 0) {
        throw new RuntimeException("Disposable E2E database is not fresh: {$table} already contains rows");
    }
}

$customerEmail = 'account-g12@example.test';
$customerPassword = 'G12-customer-password';
[$customerCreated, $customerId, $customerError] = Customers::register(
    $customerEmail,
    $customerPassword,
    'G12 Account Customer',
    ['locale' => 'en', 'phone' => '+421900000013']
);
if (!$customerCreated) throw new RuntimeException('Cannot create E2E customer: ' . $customerError);
Customers::addAddress((int)$customerId, [
    'type' => 'shipping', 'is_default' => 1, 'name' => 'G12 Account Customer',
    'street' => 'Qualification 12', 'city' => 'Bratislava', 'zip' => '81101',
    'country' => 'SK', 'phone' => '+421900000013',
]);

$adminUsername = 'g12-operator';
$adminPassword = 'G12-admin-password-2026';
$adminId = AdminIdentity::create($adminUsername, $adminPassword, 'owner', true);
AdminIdentity::grantStore($adminId, 'default', ['*']);
$enrollment = AdminIdentity::enrollTotp($adminId);
AdminIdentity::setStatus($adminId, 'active');

$db->exec("UPDATE payment_methods SET provider='mock' WHERE code='card'");
// With a Stripe test key, a second card method creates real Stripe test-mode Checkout sessions.
$stripeTest = str_starts_with((string)($config['stripe_secret_key'] ?? ''), 'sk_test_');
if ($stripeTest) {
    $db->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
        SELECT 'card_stripe_test', 'stripe', 'Card (Stripe test mode)', description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, 1, 1, sort_order + 1
        FROM payment_methods WHERE code = 'card'");
    $db->exec("INSERT INTO payment_method_countries (method_code, country) SELECT 'card_stripe_test', country FROM payment_method_countries WHERE method_code = 'card'");
    $db->exec("INSERT INTO payment_method_shipping (method_code, shipping_method_code) SELECT 'card_stripe_test', shipping_method_code FROM payment_method_shipping WHERE method_code = 'card'");
}

if (!defined('BB_TEST_LOAD_API_ONLY')) define('BB_TEST_LOAD_API_ONLY', true);
require_once dirname(__DIR__, 2) . '/api.php';
$product = $db->query("SELECT id, sku FROM products WHERE status='active' AND stock >= 20 ORDER BY stock DESC, id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$product) throw new RuntimeException('Disposable E2E catalog needs one product with at least 20 units');
$createCheckoutOrder = static function (string $email, int $quantity) use ($config, $product): int {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'BareBones G12 fixture';
    $_SESSION = [
        'csrf_token' => 'g12-fixture-' . hash('sha256', $email),
        'cart' => [
            'p_' . (int)$product['id'] => [
                'product_id' => (int)$product['id'], 'variant_id' => null, 'qty' => $quantity,
            ],
        ],
    ];
    $rates = ShippingService::calculateRates('SK', 0, 10000, false, 0);
    $shippingMethod = (string)($rates[0]['method_code'] ?? '');
    $_POST = [
        'csrf_token' => $_SESSION['csrf_token'], 'name' => 'G12 Operations Customer',
        'email' => $email, 'phone' => '+421900000015', 'street' => 'Operations 12',
        'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK',
        'shipping_method' => $shippingMethod, 'payment_method' => 'bank_transfer',
    ];
    $result = apiCheckout($config);
    $_POST = [];
    if (empty($result['success']) || empty($result['order_id'])) {
        throw new RuntimeException('Cannot create E2E checkout order: ' . (string)($result['error'] ?? 'unknown error'));
    }
    return (int)$result['order_id'];
};

$fulfillmentOrderId = $createCheckoutOrder('fulfillment-g12@example.test', 2);
PaymentService::transitionPayment($fulfillmentOrderId, 'paid', 'payment.captured', 0, 'g12-fulfillment-paid');
OrderWorkflow::transition($fulfillmentOrderId, 'paid', 'G12 fixture paid');

$rmaOrderId = $createCheckoutOrder('rma-g12@example.test', 2);
PaymentService::transitionPayment($rmaOrderId, 'paid', 'payment.captured', 0, 'g12-rma-paid');
OrderWorkflow::transition($rmaOrderId, 'paid', 'G12 fixture paid');
$sourceInvoice = InvoiceService::issue($rmaOrderId);
OrderWorkflow::transition($rmaOrderId, 'shipped', 'G12 fixture shipped');
OrderWorkflow::transition($rmaOrderId, 'delivered', 'G12 fixture delivered');
$rmaOrderItem = Orders::items($rmaOrderId)[0] ?? null;
if (!$rmaOrderItem) throw new RuntimeException('RMA fixture order has no item');
[$rmaCreated, $rmaId, $rmaError] = Rma::create($rmaOrderId, null, 'G12 partial browser return', [(int)$rmaOrderItem['id'] => 1]);
if (!$rmaCreated || !$rmaId) throw new RuntimeException('Cannot create E2E RMA: ' . (string)$rmaError);

$state = [
    'customer' => ['id' => (int)$customerId, 'email' => $customerEmail, 'password' => $customerPassword],
    'admin' => ['id' => $adminId, 'username' => $adminUsername, 'password' => $adminPassword, 'secret' => $enrollment['secret']],
    'fulfillment' => ['order_id' => $fulfillmentOrderId],
    'rma' => ['order_id' => $rmaOrderId, 'rma_id' => (int)$rmaId, 'source_invoice_id' => (int)$sourceInvoice['id']],
    'mock_webhook_secret' => (string)$config['mock_webhook_secret'],
    'stripe_test' => $stripeTest,
];
$statePath = (string)(getenv('E2E_STATE_PATH') ?: (__DIR__ . '/.state.json'));
if (file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
    throw new RuntimeException('Cannot write E2E state');
}
@chmod($statePath, 0600);
echo "Seeded disposable E2E customer, MFA admin, and mock payment fixture\n";
