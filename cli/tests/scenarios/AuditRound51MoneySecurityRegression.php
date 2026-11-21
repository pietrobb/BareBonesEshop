<?php
$pdo ??= test_commerce_fixture();

// Review round 51 (money and security lows):
//  - an admin edit that raised an order on invoice did not check the company's credit limit (checkout did, R50);
//  - a payment refunded in full in the GoPay/ThePay dashboard (REFUNDED, no refund of the shop in flight) was only
//    logged: the order stayed paid and shippable;
//  - the checkout's estimate of the OSS threshold netted a book at the SK reduced 19 % (its class), not at the 5 % the
//    order is priced with; HU medicines are 5 % (were the reduced 18 %), HU e-books 27 % (were the printed books' 5 %);
//  - the limit of 3 placed orders per buyer in 30 minutes was checked before the order and recorded after it, so
//    concurrent checkouts all passed it;
//  - a download whose request was killed (no shutdown function) kept its transfer lease for good: no free resume.
echo "\n--- Audit round 51: credit limit on edit, dashboard refund, OSS estimate rates, atomic order limit, download lease ---\n";
require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';

Bootstrap::initStoreContext('/', '', 'default');
$r51sFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R51 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};

// --- Low: an admin edit of an order on invoice checks the company's credit limit -------------------------------------
try {
    ConsentService::setDefinitionsForTesting([]);
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $r51sCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r51sWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy) VALUES
        (98711, 'default', {$r51sCategory}, 'R51S-GOODS', 'r51s-goods', 'simple', 'R51S goods', 30000, 'eur', 20, 'active', 'deny')");
    Warehouse::setStock($r51sWarehouse, 'R51S-GOODS', 20, 'count', 'test', 'r51s-opening');
    $pdo->exec("INSERT INTO companies (store_id, code, legal_name, approval_status, credit_limit, payment_terms_days, invoice_purchase, active)
        VALUES ('default', 'r51s-co', 'R51S s.r.o.', 'approved', 100000, 14, 1, 1)");
    $r51sCompany = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO customers (store_id, email, password_hash, name, company_id, status) VALUES ('default', 'r51s-member@example.test', '!test!', 'R51S Member', ?, 'active')")
        ->execute([$r51sCompany]);
    $r51sMember = (int)$pdo->lastInsertId();
    $r51sInvoiceSnapshot = json_encode(['code' => 'invoice', 'service_type' => 'invoice', 'payment_terms_days' => 14]);
    $r51sBuyer = ['name' => 'R51S Member', 'email' => 'r51s-member@example.test', 'phone' => '+421900000051', 'country' => 'SK', 'street' => 'Úverová 1',
        'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer', 'locale' => 'sk'];
    $r51sManual = ManualOrder::create(ManualOrder::parseLines('R51S-GOODS 1')['lines'], $r51sBuyer, false);
    $r51sOrder = (int)($r51sManual['order_id'] ?? 0);
    assert_true(!empty($r51sManual['ok']) && $r51sOrder > 0, 'R51 credit edit: the member\'s order is entered (' . ($r51sManual['error'] ?? 'ok') . ')');
    // The order is on invoice (as one placed so would be), and the member has a second open order on invoice.
    $pdo->prepare("UPDATE orders SET payment_method = 'invoice', payment_method_snapshot = ?, customer_id = ? WHERE id = ?")->execute([$r51sInvoiceSnapshot, $r51sMember, $r51sOrder]);
    $r51sOther = Orders::create([['product_id' => 98711, 'variant_id' => null, 'sku' => 'R51S-GOODS', 'name' => 'R51S goods', 'qty' => 2, 'price' => 30000, 'attrs' => []]],
        ['name' => 'R51S Member', 'email' => 'r51s-member@example.test', 'phone' => '', 'address' => [], 'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0,
         'currency' => 'eur', 'customer_id' => $r51sMember, 'payment_method_snapshot' => $r51sInvoiceSnapshot], 'invoice');
    $r51sBefore = (int)Orders::byId($r51sOrder)['total'];
    assert_true($r51sBefore >= 30000 && Customers::companyOpenTotal($r51sCompany) === $r51sBefore + 60000,
        'R51 credit edit: the company owes the edited order and 600 € of another one on a 1000 € limit');
    // The edit's own pricing (ManualOrder::editItems() prices so), on invoice.
    $r51sPriced = new ReflectionMethod(ManualOrder::class, 'priced');
    $r51sEdit = static function (string $lines) use ($r51sPriced, $r51sBuyer, $r51sMember, $r51sOrder, $r51sInvoiceSnapshot): ?string {
        return Orders::replaceItems($r51sOrder, static function () use ($r51sPriced, $lines, $r51sBuyer, $r51sMember, $r51sInvoiceSnapshot): array {
            $priced = $r51sPriced->invoke(null, ManualOrder::parseLines($lines)['lines'], $r51sBuyer, $r51sMember, null, 'sk');
            if (!isset($priced['error'])) $priced['payment_method_snapshot'] = $r51sInvoiceSnapshot;
            return $priced;
        });
    };
    $r51sStock = static fn(): int => (int)$pdo->query("SELECT stock FROM products WHERE id = 98711")->fetchColumn();
    $r51sStockBefore = $r51sStock();
    assert_equals(['checkout.error.credit_limit', $r51sBefore, 1, $r51sStockBefore], [$r51sEdit('R51S-GOODS 2'), (int)Orders::byId($r51sOrder)['total'],
        array_sum(array_column(Orders::items($r51sOrder), 'quantity')), $r51sStock()],
        'R51 credit edit: an edit raising the order on invoice past the credit limit is refused and changes nothing (was: 1500 € open on a 1000 € limit)');
    OrderWorkflow::transition($r51sOther, 'cancelled', 'R51 credit fixture');
    assert_equals([null, 2], [$r51sEdit('R51S-GOODS 2'), array_sum(array_column(Orders::items($r51sOrder), 'quantity'))],
        'R51 credit edit: within the limit the same edit goes through');
    assert_equals(null, $r51sEdit('R51S-GOODS 1'), 'R51 credit edit: an edit lowering the order is never refused for the credit');
    OrderWorkflow::transition($r51sOrder, 'cancelled', 'R51 credit fixture');
} catch (Throwable $e) {
    $r51sFail('credit edit', $e);
}

// --- Low: a full refund in the GoPay/ThePay dashboard closes the order ----------------------------------------------
try {
    $r51sApply = new ReflectionMethod(PaymentService::class, 'applyWebhookEvent');
    $r51sGopay = static function (string $status, string $paymentStatus) use ($pdo): int {
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
            VALUES (?, 'R51S', 'r51s-gopay@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', ?, ?, 'gopay', ?, ?, 'default', " . Db::now() . ")")
            ->execute(['R51S-' . bin2hex(random_bytes(5)), $status, $paymentStatus, '3100000' . random_int(100, 999), json_encode(['provider' => 'gopay', 'service_type' => 'online_card'])]);
        return (int)$pdo->lastInsertId();
    };
    $r51sReport = static fn(int $orderId, string $state, string $key) => $r51sApply->invoke(null, $orderId, 'gopay', 'refund.reported', $key,
        ['state' => $state, 'currency' => 'EUR', 'amount' => 1000, 'provider_ref' => (string)Orders::byId($orderId)['payment_id']]);
    $r51sState = static function (int $orderId): array {
        $order = Orders::byId($orderId);
        return [(string)$order['status'], (string)$order['payment_status'], PaymentService::refundedAmount($orderId)];
    };
    $r51sPaid = $r51sGopay('paid', 'paid');
    $r51sReport($r51sPaid, 'PARTIALLY_REFUNDED', 'r51s_gopay_part_' . $r51sPaid);
    assert_equals(['paid', 'paid', 0], $r51sState($r51sPaid), 'R51 dashboard refund: a partial refund in the GoPay dashboard (no amount) is only logged');
    $r51sReport($r51sPaid, 'REFUNDED', 'r51s_gopay_full_' . $r51sPaid);
    $r51sClosed = $r51sState($r51sPaid);
    assert_true($r51sClosed[0] !== 'paid' && array_slice($r51sClosed, 1) === ['refunded', 1000],
        'R51 dashboard refund: a payment refunded in full in the GoPay dashboard books the refund and closes the order (was: logged, the order stayed paid; ' . json_encode($r51sClosed) . ')');
    $r51sReport($r51sPaid, 'REFUNDED', 'r51s_gopay_again_' . $r51sPaid);
    assert_equals(1000, PaymentService::refundedAmount($r51sPaid), 'R51 dashboard refund: the notification again books nothing');
    // Part refunded through the shop before, the rest in the dashboard: the rest is booked.
    $r51sPart = $r51sGopay('delivered', 'partially_refunded');
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
        VALUES (?, 'default', 'gopay', 'r51s', 'partially_refunded', 400, 'eur', ?, '{}')")->execute([$r51sPart, 'r51s_part_' . $r51sPart]);
    $r51sReport($r51sPart, 'REFUNDED', 'r51s_gopay_rest_' . $r51sPart);
    assert_equals(['refunded', 1000], array_slice($r51sState($r51sPart), 1), 'R51 dashboard refund: after a partial refund of the shop the dashboard refund of the rest books the remaining 6 €');
} catch (Throwable $e) {
    $r51sFail('dashboard refund', $e);
}

// --- Low: the OSS threshold estimate nets each line at its category's rate -------------------------------------------
try {
    assert_equals([5.0, 27.0, 5.0, 20.0, 10.0, 5.0], [VatService::rate('HU', 'medicines', '2026-11-21'), VatService::rate('HU', 'ebooks', '2026-11-21'),
        VatService::rate('HU', 'books', '2026-11-21'), VatService::rate('SK', 'ebooks', '2024-06-01'), VatService::rate('SK', 'books', '2024-06-01'), VatService::rate('SK', 'ebooks', '2026-11-21')],
        'R51 VAT rates: HU medicines 5 % (were 18 %), HU e-books 27 % (were 5 %), SK e-books 20 % before 2025 (were 10 %), SK books and e-books 5 % now');
    assert_equals([10000, 10000, 10000], [
        OssThreshold::saleNet([['gross' => 10500, 'vat_class' => 'books', 'digital' => false]], true, false, 'SK'),
        OssThreshold::saleNet([['gross' => 12700, 'vat_class' => 'books', 'digital' => true]], false, true, 'HU'),
        OssThreshold::saleNet([['gross' => 12300, 'vat_class' => 'standard', 'digital' => false]], true, false, 'SK'),
    ], 'R51 OSS estimate: a book in SK nets at 5 % (was the reduced 19 %: 88,24 €), an e-book in HU at 27 %, standard goods unchanged');
    $r51sBook = VatService::buildSnapshot([['product_id' => 1, 'sku' => 'R51S-BOOK', 'qty' => 1, 'price' => 10500, 'vat_class' => 'books']],
        ['seller_country' => 'SK', 'customer_country' => 'SK']);
    assert_equals((int)$r51sBook['totals']['net'], OssThreshold::saleNet([['gross' => 10500, 'vat_class' => 'books', 'digital' => false]], true, false, 'SK'),
        'R51 OSS estimate: the estimate of a book line is the net its order snapshot has');
} catch (Throwable $e) {
    $r51sFail('OSS estimate', $e);
}

// --- Low: the limit of 3 placed orders per buyer holds for concurrent checkouts -------------------------------------
$r51sPrevAddr = $_SERVER['REMOTE_ADDR'] ?? null;
try {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $_SERVER['REMOTE_ADDR'] = '203.0.113.151';
    $r51sSubject = 'checkout_subject_email_' . hash('sha256', 'r51s-buyer@example.test');
    Security::rateLimitRecord($r51sSubject, true);
    Security::rateLimitRecord($r51sSubject, true);
    // Two checkouts of the buyer's third order arrive together: both pass the check before the order is created.
    $r51sChecks = [Security::rateLimitAllows($r51sSubject, 3, 1800, true), Security::rateLimitAllows($r51sSubject, 3, 1800, true)];
    if (method_exists(Security::class, 'rateLimitReserve')) {
        $r51sFirst = Security::rateLimitReserve($r51sSubject, 3, 1800, true);
        $r51sSecond = Security::rateLimitReserve($r51sSubject, 3, 1800, true);
        assert_equals([[true, true], true, null], [$r51sChecks, is_int($r51sFirst) && $r51sFirst > 0, $r51sSecond],
            'R51 order limit: of two concurrent third orders one takes the last slot, the other is refused (was: both placed, 4 orders in 30 minutes)');
        Security::rateLimitRelease((int)$r51sFirst);
        $r51sAgain = Security::rateLimitReserve($r51sSubject, 3, 1800, true);
        assert_true(is_int($r51sAgain) && $r51sAgain > 0 && Security::rateLimitReserve($r51sSubject, 3, 1800, true) === null,
            'R51 order limit: an order that failed gives its slot back; the next one takes it, and no fourth');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.152';
        assert_true(is_int(Security::rateLimitReserve($r51sSubject, 3, 1800, true)), 'R51 order limit: a guest e-mail is still counted per IP');
    } else {
        assert_true(false, 'R51 order limit: concurrent checkouts can take the last slot atomically (Security::rateLimitReserve() missing)');
    }
    $r51sHandlers = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php');
    $r51sReserve = strpos($r51sHandlers, 'Security::rateLimitReserve($checkoutSubject, 3, 1800, $checkoutSubjectIp)');
    assert_true($r51sReserve !== false && $r51sReserve < (int)strpos($r51sHandlers, '$orderId = Orders::create($availableItems')
        && str_contains($r51sHandlers, 'Security::rateLimitRelease($checkoutSlot)') && !str_contains($r51sHandlers, 'rateLimitRecord($checkoutSubject'),
        'R51 order limit: the checkout takes the order\'s slot before it creates the order and gives it back when that fails');
} catch (Throwable $e) {
    $r51sFail('order limit', $e);
} finally {
    if ($r51sPrevAddr === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $r51sPrevAddr;
}

// --- Low: the transfer lease of a killed download lapses ------------------------------------------------------------
try {
    $pdo->exec("INSERT INTO orders (order_number, customer_name, customer_email, payment_method, subtotal, total, status, payment_status, store_id)
        VALUES ('R51S-DL', 'R51S', 'r51s-dl@example.test', 'card', 1000, 1000, 'completed', 'paid', 'default')");
    $r51sDlOrder = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES ({$r51sDlOrder}, 98711, 'R51S-EB', 'R51S e-book', 1, 1000, 1000)");
    $r51sDlItem = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO digital_assets (sku, storage_path) VALUES ('R51S-EB', 'default/r51s.epub')");
    $r51sAsset = (int)$pdo->lastInsertId();
    $r51sEnt = static function () use ($pdo, $r51sDlOrder, $r51sDlItem, $r51sAsset): int {
        $pdo->prepare("INSERT INTO digital_entitlements (order_id, order_item_id, asset_id, token_hash, status, download_limit) VALUES (?, ?, ?, ?, 'active', 1)")
            ->execute([$r51sDlOrder, $r51sDlItem, $r51sAsset, bin2hex(random_bytes(32))]);
        return (int)$pdo->lastInsertId();
    };
    $r51sRow = static fn(int $id): array => $pdo->query("SELECT * FROM digital_entitlements WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $r51sSize = 128 * 1024 * 1024;
    $r51sServed = 8 * 1024 * 1024;
    $r51sNow = time();
    $r51sLease = defined('DigitalDelivery::LEASE_SECONDS') ? DigitalDelivery::LEASE_SECONDS : 300;
    // The counted download streamed 8 MiB, then its request was killed: no endTransfer(), the lease stays.
    $r51sKilled = $r51sEnt();
    $r51sTransfer = DigitalDelivery::openTransfer($r51sRow($r51sKilled), 0, $r51sSize, $r51sNow);
    DigitalDelivery::recordProgress($r51sKilled, (int)$r51sTransfer['counted_at'], $r51sServed);
    $r51sSoon = DigitalDelivery::authorizeTransfer($r51sRow($r51sKilled), $r51sServed, $r51sSize, $r51sNow + 120);
    $r51sLater = DigitalDelivery::authorizeTransfer($r51sRow($r51sKilled), $r51sServed, $r51sSize, $r51sNow + $r51sLease + 5);
    assert_equals([false, true, 1], [$r51sSoon, $r51sLater, (int)$r51sRow($r51sKilled)['download_count']],
        'R51 download lease: a killed download\'s lease holds a while, then lapses and the client resumes for free (was: never, every resume counted as a new download)');
    // A transfer that still streams renews its lease: no free resume beside it after LEASE_SECONDS.
    $r51sAlive = $r51sEnt();
    $r51sTransfer = DigitalDelivery::openTransfer($r51sRow($r51sAlive), 0, $r51sSize, $r51sNow);
    DigitalDelivery::recordProgress($r51sAlive, (int)$r51sTransfer['counted_at'], $r51sServed);
    if (method_exists(DigitalDelivery::class, 'holdTransfer')) {
        DigitalDelivery::holdTransfer($r51sAlive, $r51sTransfer, $r51sNow + $r51sLease - 50);
        $r51sBeside = DigitalDelivery::authorizeTransfer($r51sRow($r51sAlive), $r51sServed, $r51sSize, $r51sNow + $r51sLease + 5);
        DigitalDelivery::endTransfer($r51sAlive, $r51sTransfer, $r51sServed);
        $r51sResume = DigitalDelivery::authorizeTransfer($r51sRow($r51sAlive), $r51sServed, $r51sSize, $r51sNow + $r51sLease + 10);
        assert_equals([false, true, 1], [$r51sBeside, $r51sResume, (int)$r51sRow($r51sAlive)['download_count']],
            'R51 download lease: a renewed lease still blocks a free resume beside the running transfer; its end releases it');
    } else {
        assert_true(false, 'R51 download lease: a streaming transfer renews its lease (DigitalDelivery::holdTransfer() missing)');
    }
    assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/download.php'), 'DigitalDelivery::holdTransfer((int)$entitlement[\'id\'], $transfer);'),
        'R51 download lease: download.php renews the lease while it streams');
    assert_equals(32, strlen((string)$r51sRow($r51sKilled)['transfer_lease']), 'R51 download lease: the lease fits the column (VARCHAR(32) on MySQL)');
} catch (Throwable $e) {
    $r51sFail('download lease', $e);
}
