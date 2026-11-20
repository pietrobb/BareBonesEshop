<?php
$pdo ??= test_commerce_fixture();

// Review round 20 (R50, money and stock): the B2B credit limit was checked only when the payment was offered, so
// concurrent orders on invoice together passed it; a set split between two warehouses whose first parcel left was
// refunded in full (shipping included) when the rest was cancelled, and the shipped part could not be returned; a
// feed sync next to a goods receipt booked a wrong delta (setStock read the stock without a lock). Lows: a full manual
// or dashboard refund left the order paid and shippable; editing a pending bank-transfer order sent no new payment
// details; the SQLite credit-note job kept cursors open; two carts with the same items in reverse order deadlocked.
echo "\n--- Audit round 50: credit limit, split sets, stock ledger, refunds, transfer details ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r50mFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R50 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r50mCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$r50mWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy) VALUES
    (98501, 'default', {$r50mCategory}, 'R50M-GOODS', 'r50m-goods', 'simple', 'R50M goods', 60000, 'eur', 50, 'active', 'deny'),
    (98502, 'default', {$r50mCategory}, 'R50M-PART-A', 'r50m-part-a', 'simple', 'R50M part A', 1000, 'eur', 5, 'active', 'deny'),
    (98503, 'default', {$r50mCategory}, 'R50M-PART-B', 'r50m-part-b', 'simple', 'R50M part B', 1000, 'eur', 5, 'active', 'deny'),
    (98504, 'default', {$r50mCategory}, 'R50M-SET', 'r50m-set', 'bundle', 'R50M set', 3000, 'eur', 5, 'active', 'deny'),
    (98505, 'default', {$r50mCategory}, 'R50M-REFUND', 'r50m-refund', 'simple', 'R50M refund', 2000, 'eur', 5, 'active', 'deny')");
Warehouse::setStock($r50mWarehouse, 'R50M-GOODS', 50, 'count', 'test', 'r50m-opening-goods');
Warehouse::setStock($r50mWarehouse, 'R50M-REFUND', 5, 'count', 'test', 'r50m-opening-refund');
$r50mLine = static fn(int $productId, string $sku, int $price, int $qty = 1): array => ['product_id' => $productId, 'variant_id' => null, 'sku' => $sku,
    'name' => $sku, 'qty' => $qty, 'price' => $price, 'attrs' => []];
$r50mBuyer = ['name' => 'R50M Buyer', 'email' => 'r50m@example.test', 'phone' => '', 'address' => [],
    'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'];

// --- Medium: the credit limit inside the order transaction ---------------------------------------------------------
try {
    $pdo->exec("INSERT INTO companies (store_id, code, legal_name, approval_status, credit_limit, payment_terms_days, invoice_purchase, active)
        VALUES ('default', 'r50m-co', 'R50M s.r.o.', 'approved', 100000, 14, 1, 1)");
    $r50mCompany = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO customers (store_id, email, password_hash, name, company_id, status) VALUES ('default', 'r50m-member@example.test', '!test!', 'R50M Member', ?, 'active')")
        ->execute([$r50mCompany]);
    $r50mMember = (int)$pdo->lastInsertId();
    $r50mInvoice = static fn(): int => Orders::create([$r50mLine(98501, 'R50M-GOODS', 60000)], $r50mBuyer + ['customer_id' => $r50mMember,
        'payment_method_snapshot' => json_encode(['code' => 'invoice', 'service_type' => 'invoice', 'payment_terms_days' => 14])], 'invoice');
    $r50mFirst = $r50mInvoice();
    assert_true($r50mFirst > 0 && Customers::companyOpenTotal($r50mCompany) === 60000, 'R50 credit limit: the first order on invoice (600 of 1000 €) is placed');
    $r50mSecond = 'placed';
    try {
        $r50mInvoice();
    } catch (CreditLimitExceededException $e) {
        $r50mSecond = 'refused';
    }
    assert_equals(['refused', 60000], [$r50mSecond, Customers::companyOpenTotal($r50mCompany)],
        'R50 credit limit: a second order on invoice that the offer checked against the old total is refused inside the order transaction (was: 1200 € open on a 1000 € limit)');
    $r50mCard = Orders::create([$r50mLine(98501, 'R50M-GOODS', 60000)], $r50mBuyer + ['customer_id' => $r50mMember,
        'payment_method_snapshot' => json_encode(['code' => 'card', 'service_type' => 'online_card', 'requires_online' => true])], 'card');
    assert_true($r50mCard > 0, 'R50 credit limit: the same member still pays another order by card');
    assert_true(is_subclass_of(CreditLimitExceededException::class, OrderRejectedException::class), 'R50 credit limit: the refusal is an order refusal (no order, stock released)');
    $r50mHandlers = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php');
    assert_true(str_contains($r50mHandlers, "} catch (CreditLimitExceededException \$e) {") && str_contains($r50mHandlers, "checkoutRefusal('checkout.error.credit_limit')")
        && strpos($r50mHandlers, 'catch (CreditLimitExceededException') < strpos($r50mHandlers, 'catch (OrderRejectedException $e) {'),
        'R50 credit limit: the checkout says the credit was used up (not "stock changed")');
    foreach (glob(dirname(TEST_CLI_DIR) . '/lang/*.php') ?: [] as $r50mLang) {
        assert_true(isset((require $r50mLang)['checkout.error.credit_limit']), 'R50 credit limit: ' . basename($r50mLang) . ' explains the refusal');
    }
    foreach ([$r50mFirst, $r50mCard] as $r50mOrder) OrderWorkflow::transition($r50mOrder, 'cancelled', 'R50 credit fixture');
} catch (Throwable $e) {
    $r50mFail('credit limit', $e);
}

// --- Medium: a set split between two warehouses, its first parcel shipped -------------------------------------------
try {
    $pdo->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r50m-wh-a', 'R50M A', -50, 0), ('default', 'r50m-wh-b', 'R50M B', -60, 0)");
    $r50mWhA = (int)$pdo->query("SELECT id FROM warehouses WHERE code = 'r50m-wh-a'")->fetchColumn();
    $r50mWhB = (int)$pdo->query("SELECT id FROM warehouses WHERE code = 'r50m-wh-b'")->fetchColumn();
    $pdo->exec('INSERT INTO bundle_components (bundle_product_id, component_product_id, quantity, sort_order) VALUES (98504, 98502, 1, 1), (98504, 98503, 1, 2)');
    Warehouse::setStock($r50mWhA, 'R50M-PART-A', 5, 'count', 'test', 'r50m-opening-a');
    Warehouse::setStock($r50mWhB, 'R50M-PART-B', 5, 'count', 'test', 'r50m-opening-b');
    $r50mSetOrder = Orders::create([$r50mLine(98504, 'R50M-SET', 3000)], $r50mBuyer + ['shipping_cost' => 500], 'bank_transfer');
    $r50mParcels = Fulfillment::planOrder($r50mSetOrder);
    $r50mParcelA = (int)$pdo->query("SELECT shipment_id FROM shipment_items WHERE sku = 'R50M-PART-A' AND shipment_id IN (" . implode(',', array_map('intval', $r50mParcels) ?: [0]) . ')')->fetchColumn();
    assert_true(count($r50mParcels) === 2 && $r50mParcelA > 0, 'R50 split set: the set ships in two parcels, one per warehouse');
    Fulfillment::ship($r50mParcelA);
    $r50mSetItem = Orders::items($r50mSetOrder)[0];
    assert_equals([1, 0], [Fulfillment::shippedUnits($r50mSetItem), Fulfillment::unshippedGross($r50mSetOrder)],
        'R50 split set: with its first parcel shipped the set is with the customer; cancelling the rest refunds no goods and no shipping (was: nothing shipped, the whole 35 € refunded)');
    $r50mNormalize = new ReflectionMethod(Rma::class, 'normalizeItems');
    $r50mReturnable = $r50mNormalize->invoke(null, $r50mSetOrder, [(int)$r50mSetItem['id'] => 1], [(int)$r50mSetItem['id'] => $r50mSetItem], true);
    assert_equals(1, (int)($r50mReturnable[0]['qty'] ?? 0),
        'R50 split set: after the rest is cancelled the shipped set stays returnable (was: 0, the customer kept a part nobody could refund)');
} catch (Throwable $e) {
    $r50mFail('split set', $e);
}

// --- Medium: setStock reads the stock it overwrites with a lock (MySQL; see the race below) -------------------------
$r50mWarehouseSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Warehouse.php');
assert_true(str_contains($r50mWarehouseSource, "'SELECT qty, reserved FROM warehouse_stock WHERE warehouse_id = :wh AND sku = :sku' . (Db::isMysql() ? ' FOR UPDATE' : '')"),
    'R50 stock ledger: setStock reads the count it replaces with a locking read');

// --- Low: a full refund outside the shop closes the order ----------------------------------------------------------
try {
    $r50mPaid = Orders::create([$r50mLine(98505, 'R50M-REFUND', 2000)], $r50mBuyer + ['payment_method_snapshot' => json_encode(['code' => 'bank_transfer', 'service_type' => 'bank_transfer'])], 'bank_transfer');
    PaymentService::updatePaymentStatus($r50mPaid, 'paid');
    if ((string)Orders::byId($r50mPaid)['status'] === 'pending') OrderWorkflow::transition($r50mPaid, 'paid', 'R50 fixture');
    PaymentService::completeManualRefund($r50mPaid, 2000, 'r50-admin', 'paid back in full before shipping');
    $r50mClosed = Orders::byId($r50mPaid);
    $r50mReserved = (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE sku = 'R50M-REFUND' AND warehouse_id = {$r50mWarehouse}")->fetchColumn();
    assert_equals(['refunded', 'refunded', 0], [(string)$r50mClosed['status'], (string)$r50mClosed['payment_status'], $r50mReserved],
        'R50 refunds: an order paid back in full before shipping is closed and its stock released (was: still paid and shippable)');
    assert_true(!in_array('shipped', OrderWorkflow::allowedFor($r50mClosed), true), 'R50 refunds: the refunded order can no longer be shipped');
    $r50mPayments = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/PaymentService.php');
    assert_true(str_contains($r50mPayments, "if (\$booked && !Db::get()->inTransaction()) self::closeRefundedOrder(\$orderId, 'Provider refund booked');"),
        'R50 refunds: a provider refund booked from its webhook closes the order the same way');
} catch (Throwable $e) {
    $r50mFail('full refund', $e);
}

// --- Low: an edited bank-transfer order gets new payment details ---------------------------------------------------
try {
    ConsentService::setDefinitionsForTesting([]);
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $r50mManual = ManualOrder::create(ManualOrder::parseLines('R50M-REFUND 1')['lines'], ['name' => 'R50M Transfer', 'email' => 'r50m-transfer@example.test',
        'phone' => '+421900000050', 'country' => 'SK', 'street' => 'Prevodná 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta',
        'payment_method' => 'bank_transfer', 'locale' => 'sk'], false);
    $r50mManualId = (int)($r50mManual['order_id'] ?? 0);
    assert_true(!empty($r50mManual['ok']) && $r50mManualId > 0, 'R50 transfer: the bank-transfer order is entered (' . ($r50mManual['error'] ?? 'ok') . ')');
    // pull() only lists pending jobs (it does not consume them): count this order's confirmations before and after.
    $r50mConfirmations = static fn(): int => count(array_filter(JobQueue::pull(5000), static fn(array $job): bool => ($job['payload']['template'] ?? '') === 'order_confirmation'
        && (int)($job['payload']['order_id'] ?? 0) === $r50mManualId));
    $r50mBefore = $r50mConfirmations();
    $r50mEdit = ManualOrder::editItems($r50mManualId, ManualOrder::parseLines('R50M-REFUND 2')['lines']);
    assert_equals([true, 1], [!empty($r50mEdit['ok']), $r50mConfirmations() - $r50mBefore],
        'R50 transfer: an edit that changed the amount sends the confirmation with the new amount and payment details again (was: none, the customer paid the old amount)');
    $r50mBefore = $r50mConfirmations();
    $r50mSame = ManualOrder::editItems($r50mManualId, ManualOrder::parseLines('R50M-REFUND 2')['lines']);
    assert_equals([true, 0], [!empty($r50mSame['ok']), $r50mConfirmations() - $r50mBefore], 'R50 transfer: an edit that kept the amount sends nothing');
    OrderWorkflow::transition($r50mManualId, 'cancelled', 'R50 transfer fixture');
} catch (Throwable $e) {
    $r50mFail('transfer details', $e);
}

// --- Low: credit-note job closes its cursors; carts lock their SKUs in one order -----------------------------------
$r50mInvoiceSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/InvoiceService.php');
$r50mBookedAt = (int)strpos($r50mInvoiceSource, 'public static function creditBookedRefund(');
$r50mBooked = substr($r50mInvoiceSource, $r50mBookedAt, (int)strpos($r50mInvoiceSource, 'private static function creditBookedOrWait(', $r50mBookedAt) - $r50mBookedAt);
assert_equals(4, substr_count($r50mBooked, '->closeCursor();'), 'R50 credit notes: the refund credit-note job closes each of its four reads before it writes (SQLite "database is locked")');
$r50mOrdersSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Orders.php');
assert_true(method_exists(Inventory::class, 'lockCartSkus') && str_contains($r50mOrdersSource, "Inventory::lockCartSkus(\$cartItems);\n        foreach (\$cartItems as \$item) {"),
    'R50 deadlock: an order locks the stock of all its SKUs in SKU order before it reserves line by line');

// --- MySQL: the three races on a real second connection -------------------------------------------------------------
$r50mRoot = test_fixture_path('r50-money-race');
mkdir($r50mRoot);
file_put_contents($r50mRoot . '/race.php', <<<'PHP'
<?php
[, $phase, $root, $target] = $argv;
require $root . '/src/autoload.php';
$connect = static function(): PDO {
    $db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
};
$a = $connect();
if ($phase === 'teardown') { $a->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
$a->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
$a->exec("USE `{$target}`");
Db::init($a, 'mysql');
(new Migrator($a, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
$b = $connect();
$b->exec("USE `{$target}`");
$b->exec('SET SESSION innodb_lock_wait_timeout = 1');
$warehouse = (int)$a->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
$a->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$warehouse}, 'R50R-X', 10, 0), ('default', {$warehouse}, 'R50R-Y', 10, 0)");
$a->exec("INSERT INTO companies (store_id, code, legal_name, approval_status, credit_limit, payment_terms_days, invoice_purchase, active) VALUES ('default', 'r50r', 'R50R', 'approved', 100000, 14, 1, 1)");
$company = (int)$a->lastInsertId();
$a->prepare("INSERT INTO customers (store_id, email, password_hash, name, company_id, status) VALUES ('default', 'r50r@example.test', '!', 'R50R', ?, 'active')")->execute([$company]);
$member = (int)$a->lastInsertId();
$order = static fn(PDO $db, string $number) => $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, payment_method, subtotal, total, currency, store_id)
    VALUES (?, 'pending', 'pending', ?, 'R50R', 'r50r@example.test', 'invoice', 60000, 60000, 'eur', 'default')")->execute([$number, $member]);
$credit = static function(PDO $db, int $amount) use ($member): string {
    Db::init($db, 'mysql');
    try { Customers::assertInvoiceCredit($member, $amount); return 'ok'; }
    catch (CreditLimitExceededException $e) { return 'refused'; }
    catch (PDOException $e) { return 'blocked'; }
};
$out = [];
// 1. Credit: A holds the company while its order is open; B waits, then sees A's order.
$a->beginTransaction();
$out[] = $credit($a, 60000);
$order($a, 'R50R-1');
$b->beginTransaction();
$out[] = $credit($b, 60000);
$b->rollBack();
$a->commit();
$b->beginTransaction();
$out[] = $credit($b, 60000);
$b->rollBack();
// 2. Stock ledger: A's snapshot is older than a goods receipt B commits; setStock books the real delta.
Db::init($a, 'mysql');
$a->beginTransaction();
$a->query("SELECT qty FROM warehouse_stock WHERE sku = 'R50R-X'")->fetchAll();
$b->exec("UPDATE warehouse_stock SET qty = qty + 5 WHERE sku = 'R50R-X'");
Warehouse::setStock($warehouse, 'R50R-X', 20, 'feed_sync', 'warehouse_feed', $warehouse);
$a->commit();
$out[] = (int)$a->query("SELECT quantity_delta FROM stock_movements WHERE sku = 'R50R-X' AND movement_type = 'feed_sync' ORDER BY id DESC LIMIT 1")->fetchColumn();
// 3. Lock order: A's cart [X, Y] holds both SKUs before it reserves; B cannot take Y first (it would deadlock with A).
$a->beginTransaction();
Inventory::lockCartSkus([['product_id' => 0, 'sku' => 'R50R-X'], ['product_id' => 0, 'sku' => 'R50R-Y']]);
try { $b->query("SELECT id FROM warehouse_stock WHERE sku = 'R50R-Y' FOR UPDATE")->fetchAll(); $out[] = 'taken'; } catch (PDOException $e) { $out[] = 'blocked'; }
$a->rollBack();
echo json_encode($out);
PHP);
$r50mPhp = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
if (getenv('TEST_MYSQL_DSN')) {
    $r50mTarget = 'bbe_r50m_race_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
    try {
        [$r50mCode, $r50mOut] = $r50mPhp([$r50mRoot . '/race.php', 'run', dirname(TEST_CLI_DIR), $r50mTarget]);
        assert_equals([0, ['ok', 'blocked', 'refused', 5, 'blocked']], [$r50mCode, json_decode($r50mOut, true) ?? $r50mOut],
            'R50 mysql races: a concurrent order on invoice waits for the company and is then refused; setStock books +5 after a receipt committed meanwhile (was +10); a cart locks all its SKUs up front');
    } finally {
        $r50mPhp([$r50mRoot . '/race.php', 'teardown', dirname(TEST_CLI_DIR), $r50mTarget]);
    }
} else {
    print "  [SKIP] R50 mysql races (set TEST_MYSQL_DSN)\n";
}
foreach (glob($r50mRoot . '/*') ?: [] as $r50mFile) unlink($r50mFile);
rmdir($r50mRoot);
