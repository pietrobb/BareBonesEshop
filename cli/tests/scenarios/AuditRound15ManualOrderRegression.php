<?php

// Review round 15, high 2: an order entered in the admin joined any active account with the buyer's e-mail, verified or
// not. Signing up with the victim's address was enough to see the address, phone, invoices and downloads of every order
// the merchant typed in for them. Only an account that proved the address gets the order; otherwise it stays a guest
// order and joins the account when its holder verifies the address.
echo "\n--- Audit round 15: manual orders and unverified accounts ---\n";

(require TEST_CLI_DIR . '/../migrations/20261012210000_customer_email_verification.php')->up($pdo, true);
ConsentService::setDefinitionsForTesting([]);
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$r15mWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$r15mCategory = (int)$pdo->query("SELECT id FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (9851, 'default', ?, 'R15M item', 'r15m-item', 'R15M-A', 'simple', 1200, 'eur', 'active', 20, 'deny')")->execute([$r15mCategory]);
$pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'R15M-A', 20, 0)")->execute([$r15mWarehouse]);
$r15mLines = ManualOrder::parseLines('R15M-A 1')['lines'];
$r15mBuyer = static fn(string $email): array => ['name' => 'R15 Victim', 'email' => $email, 'phone' => '+421900111222', 'country' => 'SK',
    'street' => 'Tajná 7', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer', 'locale' => 'sk'];
$r15mCustomerOf = static fn(int $orderId): ?int => ($id = (int)(Orders::byId($orderId)['customer_id'] ?? 0)) > 0 ? $id : null;

// The attacker signs up with the victim's address and never verifies it (they cannot read that mailbox).
[$r15mOk, $r15mAttacker] = Customers::register('r15m-victim@example.com', 'attacker-password-1', 'Attacker');
assert_true($r15mOk && $r15mAttacker > 0, 'R15 H2 fixture: an unverified account with the victim\'s e-mail exists');
$r15mOrder = (int)(ManualOrder::create($r15mLines, $r15mBuyer('R15M-Victim@Example.com'), false)['order_id'] ?? 0);
assert_true($r15mOrder > 0, 'R15 H2 fixture: the merchant enters an order for the victim');
assert_equals(null, $r15mCustomerOf($r15mOrder), 'R15 H2: the manual order does not join the unverified account with the same e-mail');
assert_equals([0, null, []], [Customers::orderCount($r15mAttacker), Customers::order($r15mAttacker, $r15mOrder), Customers::orderItems($r15mAttacker, $r15mOrder)],
    'R15 H2: the unverified account sees neither the order nor its lines (address, phone, invoice, downloads)');

// The address holder verifies it while signed in to the account: the guest order joins it then.
$r15mToken = (string)Customers::startEmailVerification($r15mAttacker);
assert_equals($r15mAttacker, Customers::verifyEmail($r15mToken, $r15mAttacker), 'R15 H2: the account holder verifies the address');
assert_equals($r15mAttacker, $r15mCustomerOf($r15mOrder), 'R15 H2: after verification the earlier manual order joins the account');

// A verified account gets the next manual order directly, as before.
$r15mNext = (int)(ManualOrder::create($r15mLines, $r15mBuyer('r15m-victim@example.com'), false)['order_id'] ?? 0);
assert_equals($r15mAttacker, $r15mCustomerOf($r15mNext), 'R15 H2: a manual order for a verified account joins it at once');

foreach ([$r15mOrder, $r15mNext] as $r15mId) OrderWorkflow::transition($r15mId, 'cancelled', 'R15 H2 cleanup');
