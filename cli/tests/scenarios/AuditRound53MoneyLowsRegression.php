<?php
$pdo ??= test_commerce_fixture();

// Review round 53 (money lows): a set whose expensive part shipped refunded its cheap parts by their unit count when the
// rest was cancelled (a 29 € lamp shipped, ten 0,10 € bulbs cancelled refunded 10/11 of the set); an admin edit of an
// order on invoice counted its old total next to its new one against the credit limit; a payment refunded in full in the
// GoPay/ThePay dashboard while a smaller refund of the shop was in flight was only logged; the admin's stock edit failed
// when MySQL picked it as the victim of a deadlock.
echo "\n--- Audit round 53: set refund weights, invoice edit credit, dashboard refund in flight, stock edit deadlock ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r53mFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R53 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r53mCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$r53mWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$r53mBuyer = ['name' => 'R53M Buyer', 'email' => 'r53m@example.test', 'phone' => '', 'address' => [],
    'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'];

// --- Low: a lamp shipped, its ten bulbs cancelled, refunds the bulbs' share of the set ------------------------------
try {
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy) VALUES
        (98531, 'default', {$r53mCategory}, 'R53M-LAMP', 'r53m-lamp', 'simple', 'R53M lamp', 2900, 'eur', 5, 'active', 'deny'),
        (98532, 'default', {$r53mCategory}, 'R53M-BULB', 'r53m-bulb', 'simple', 'R53M bulb', 10, 'eur', 50, 'active', 'deny'),
        (98533, 'default', {$r53mCategory}, 'R53M-SET', 'r53m-set', 'bundle', 'R53M lamp set', 3000, 'eur', 5, 'active', 'deny')");
    $pdo->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r53m-wh-a', 'R53M A', -70, 0), ('default', 'r53m-wh-b', 'R53M B', -80, 0)");
    $r53mWhA = (int)$pdo->query("SELECT id FROM warehouses WHERE code = 'r53m-wh-a'")->fetchColumn();
    $r53mWhB = (int)$pdo->query("SELECT id FROM warehouses WHERE code = 'r53m-wh-b'")->fetchColumn();
    $pdo->exec('INSERT INTO bundle_components (bundle_product_id, component_product_id, quantity, sort_order) VALUES (98533, 98531, 1, 1), (98533, 98532, 10, 2)');
    Warehouse::setStock($r53mWhA, 'R53M-LAMP', 5, 'count', 'test', 'r53m-opening-lamp');
    Warehouse::setStock($r53mWhB, 'R53M-BULB', 50, 'count', 'test', 'r53m-opening-bulb');
    $r53mOrder = Orders::create([['product_id' => 98533, 'variant_id' => null, 'sku' => 'R53M-SET', 'name' => 'R53M lamp set', 'qty' => 1, 'price' => 3000, 'attrs' => []]],
        $r53mBuyer, 'bank_transfer');
    $r53mParcels = Fulfillment::planOrder($r53mOrder);
    $r53mLampParcel = (int)$pdo->query("SELECT shipment_id FROM shipment_items WHERE sku = 'R53M-LAMP' AND shipment_id IN (" . implode(',', array_map('intval', $r53mParcels) ?: [0]) . ')')->fetchColumn();
    Fulfillment::ship($r53mLampParcel);
    $r53mItem = Orders::items($r53mOrder)[0];
    assert_equals([[29, 30], 100], [Fulfillment::shippedShare($r53mItem), Fulfillment::unshippedGross($r53mOrder)],
        'R53 set refund: the 29 € lamp left, the ten 0,10 € bulbs are cancelled: the rest refunds 1 € of 30 € (was 10/11 of the set, 27,27 €)');
    // A set ordered before the component prices were frozen weighs each unit the same, as before.
    $r53mAttrs = json_decode((string)$r53mItem['attrs'], true) ?: [];
    foreach ($r53mAttrs['_bundle_components'] as &$r53mComponent) unset($r53mComponent['unit_price']);
    unset($r53mComponent);
    assert_equals([1, 11], Fulfillment::shippedShare(['attrs' => json_encode($r53mAttrs)] + $r53mItem), 'R53 set refund: an older set without frozen prices weighs each unit the same');
    OrderWorkflow::transition($r53mOrder, 'cancelled', 'R53 set refund fixture');
} catch (Throwable $e) {
    $r53mFail('set refund', $e);
}

// --- Low: an admin edit raising an order on invoice counts its new total once against the credit limit ---------------
try {
    ConsentService::setDefinitionsForTesting([]);
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy) VALUES
        (98534, 'default', {$r53mCategory}, 'R53M-GOODS', 'r53m-goods', 'simple', 'R53M goods', 30000, 'eur', 20, 'active', 'deny')");
    Warehouse::setStock($r53mWarehouse, 'R53M-GOODS', 20, 'count', 'test', 'r53m-opening-goods');
    $pdo->exec("INSERT INTO companies (store_id, code, legal_name, approval_status, credit_limit, payment_terms_days, invoice_purchase, active)
        VALUES ('default', 'r53m-co', 'R53M s.r.o.', 'approved', 100000, 14, 1, 1)");
    $r53mCompany = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO customers (store_id, email, password_hash, name, company_id, status) VALUES ('default', 'r53m-member@example.test', '!test!', 'R53M Member', ?, 'active')")
        ->execute([$r53mCompany]);
    $r53mMember = (int)$pdo->lastInsertId();
    $r53mManual = ManualOrder::create(ManualOrder::parseLines('R53M-GOODS 2')['lines'], ['name' => 'R53M Member', 'email' => 'r53m-member@example.test',
        'phone' => '+421900000053', 'country' => 'SK', 'street' => 'Úverová 3', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta',
        'payment_method' => 'bank_transfer', 'locale' => 'sk'], false);
    $r53mInvoiced = (int)($r53mManual['order_id'] ?? 0);
    assert_true($r53mInvoiced > 0, 'R53 invoice edit: the member\'s order is entered (' . ($r53mManual['error'] ?? 'ok') . ')');
    $pdo->prepare("UPDATE orders SET payment_method = 'invoice', payment_method_snapshot = ?, customer_id = ? WHERE id = ?")
        ->execute([json_encode(['code' => 'invoice', 'service_type' => 'invoice', 'payment_terms_days' => 14]), $r53mMember, $r53mInvoiced]);
    $r53mOpen = Customers::companyOpenTotal($r53mCompany);
    $r53mRaised = ManualOrder::editItems($r53mInvoiced, ManualOrder::parseLines('R53M-GOODS 3')['lines']);
    $r53mAfter = Orders::byId($r53mInvoiced);
    assert_equals([true, 3, 'invoice'], [!empty($r53mRaised['ok']), array_sum(array_column(Orders::items($r53mInvoiced), 'quantity')), (string)$r53mAfter['payment_method']],
        'R53 invoice edit: 600 € open on a 1000 € limit, raised to 900 €: the edit goes through on invoice (was: refused, the old 600 € counted next to the new 900 €; '
        . json_encode(['open' => $r53mOpen, 'result' => $r53mRaised]) . ')');
    $r53mPast = ManualOrder::editItems($r53mInvoiced, ManualOrder::parseLines('R53M-GOODS 4')['lines']);
    assert_true(empty($r53mPast['ok']) && array_sum(array_column(Orders::items($r53mInvoiced), 'quantity')) === 3,
        'R53 invoice edit: raised past the limit (1200 €) it is still refused and changes nothing');
    assert_equals((int)$r53mAfter['total'], Customers::companyOpenTotal($r53mCompany), 'R53 invoice edit: the open total outside an edit still counts the order');
    OrderWorkflow::transition($r53mInvoiced, 'cancelled', 'R53 invoice edit fixture');
} catch (Throwable $e) {
    $r53mFail('invoice edit', $e);
}

// --- Low: the whole payment refunded in the GoPay dashboard while a smaller refund of the shop is in flight -----------
try {
    $r53mApply = new ReflectionMethod(PaymentService::class, 'applyWebhookEvent');
    $r53mGopay = static function () use ($pdo): int {
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
            VALUES (?, 'R53M', 'r53m-gopay@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'delivered', 'paid', 'gopay', ?, ?, 'default', " . Db::now() . ")")
            ->execute(['R53M-' . bin2hex(random_bytes(5)), '3200000' . random_int(100, 999), json_encode(['provider' => 'gopay', 'service_type' => 'online_card'])]);
        return (int)$pdo->lastInsertId();
    };
    $r53mReport = static fn(int $orderId, string $key) => $r53mApply->invoke(null, $orderId, 'gopay', 'refund.reported', $key,
        ['state' => 'REFUNDED', 'currency' => 'EUR', 'amount' => 1000, 'provider_ref' => (string)Orders::byId($orderId)['payment_id']]);
    $r53mPaid = $r53mGopay();
    PaymentService::requestRefund($r53mPaid, 400, 'r53m_shop_refund_' . $r53mPaid);
    assert_equals('refund_pending', (string)Orders::byId($r53mPaid)['payment_status'], 'R53 dashboard refund: the shop\'s 4 € refund is in flight');
    $r53mReport($r53mPaid, 'r53m_gopay_full_' . $r53mPaid);
    $r53mRefunded = [(string)Orders::byId($r53mPaid)['payment_status'], PaymentService::refundedAmount($r53mPaid), PaymentService::refundableBalance($r53mPaid)];
    assert_equals(['refunded', 1000, 0], $r53mRefunded,
        'R53 dashboard refund: the payment refunded in full in the dashboard settles the shop\'s 4 € and books the other 6 € (was: only logged, refund_pending with 6 € counted as paid)');
    $r53mReport($r53mPaid, 'r53m_gopay_again_' . $r53mPaid);
    assert_equals(1000, PaymentService::refundedAmount($r53mPaid), 'R53 dashboard refund: the notification again books nothing');
    // An equal split (5 € in flight of 10 €) is not mistaken for the request itself.
    $r53mHalf = $r53mGopay();
    PaymentService::requestRefund($r53mHalf, 500, 'r53m_shop_half_' . $r53mHalf);
    $r53mReport($r53mHalf, 'r53m_gopay_half_' . $r53mHalf);
    assert_equals(['refunded', 1000], [(string)Orders::byId($r53mHalf)['payment_status'], PaymentService::refundedAmount($r53mHalf)],
        'R53 dashboard refund: with half of the payment in flight the dashboard refund books both halves');
} catch (Throwable $e) {
    $r53mFail('dashboard refund', $e);
}

// --- Low (MySQL): a stock edit MySQL picked as a deadlock victim runs again -------------------------------------------
$r53mRoot = test_fixture_path('r53-money-deadlock');
mkdir($r53mRoot);
file_put_contents($r53mRoot . '/deadlock.php', <<<'PHP'
<?php
[, $phase, $root, $target] = $argv;
require $root . '/src/autoload.php';
$db = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
$db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
$db->exec("USE `{$target}`");
Db::init($db, 'mysql');
(new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
Bootstrap::initStoreContext('/', '', 'default');
// The ledger write fails as a deadlock victim (MySQL error 1213) as many times as @r53_victims says.
$db->exec("CREATE TRIGGER r53_victim BEFORE INSERT ON stock_movements FOR EACH ROW BEGIN
    IF @r53_victims > 0 THEN SET @r53_victims = @r53_victims - 1; SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Deadlock found when trying to get lock'; END IF;
END");
$warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
$db->exec("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', {$warehouse}, 'R53D-X', 10, 0)");
$qty = static fn(): int => (int)$db->query("SELECT qty FROM warehouse_stock WHERE sku = 'R53D-X'")->fetchColumn();
$out = [];
foreach ([[1, 7], [2, 5], [3, 2]] as [$victims, $available]) {
    $db->exec("SET @r53_victims = {$victims}");
    try { Warehouse::setAvailable('R53D-X', $available); $out[] = 'ok'; } catch (PDOException $e) { $out[] = Db::deadlocked($e) ? 'deadlock' : $e->getMessage(); }
    $out[] = $qty();
}
$db->exec('SET @r53_victims = 1');
try { Warehouse::adjustStock($warehouse, 'R53D-X', 3, 'api_adjustment'); $out[] = 'ok'; } catch (PDOException $e) { $out[] = 'deadlock'; }
$out[] = $qty();
$out[] = (int)$db->query("SELECT COUNT(*) FROM stock_movements WHERE sku = 'R53D-X'")->fetchColumn();
echo json_encode($out);
PHP);
$r53mPhp = static function (array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
if (getenv('TEST_MYSQL_DSN')) {
    $r53mTarget = 'bbe_r53m_deadlock_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
    try {
        [$r53mCode, $r53mOut] = $r53mPhp([$r53mRoot . '/deadlock.php', 'run', dirname(TEST_CLI_DIR), $r53mTarget]);
        assert_equals([0, ['ok', 7, 'ok', 5, 'deadlock', 5, 'ok', 8, 3]], [$r53mCode, json_decode($r53mOut, true) ?? $r53mOut],
            'R53 mysql: a stock edit or API adjustment picked once or twice as a deadlock victim runs again and books once; a third time it fails and changes nothing (was: failed the first time)');
    } finally {
        $r53mPhp([$r53mRoot . '/deadlock.php', 'teardown', dirname(TEST_CLI_DIR), $r53mTarget]);
    }
} else {
    print "  [SKIP] R53 mysql stock edit deadlock retry (set TEST_MYSQL_DSN)\n";
}
foreach (glob($r53mRoot . '/*') ?: [] as $r53mFile) unlink($r53mFile);
rmdir($r53mRoot);
