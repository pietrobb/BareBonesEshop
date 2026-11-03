<?php

// Review round 13 (N4, N5, T5): the withdrawal and return period of an order delivered in several parcels, and refunds.
echo "\n--- Audit round 13: withdrawal period from the last parcel, refunds ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r13rFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R13 refunds {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};

// N4: an order of two books in two parcels. The period ran from the first delivered parcel (MAX over the delivered
// ones), so it could close before the second book arrived; art. 9(2)(b)(i) starts it with the last one.
$r13rDay = 86400;
$r13rAgo = static fn(int $days): string => gmdate('Y-m-d H:i:s', time() - $days * $r13rDay);
$r13rCfg = new ReflectionProperty('Security', 'cfg');
$r13rCfgPrev = $r13rCfg->getValue();
$r13rCfg->setValue(null, ['rma_return_window_days' => 14] + (array)$r13rCfgPrev);
$r13rOrderId = 0;
try {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency,
            status, payment_status, payment_method, store_id, created_at) VALUES ('R13R-N4', 'R13R', 'r13r@example.test', '', '{}', 2000, 0, 0, 0, 2000, 'eur', 'shipped', 'paid', 'bank', 'default', ?)")
        ->execute([$r13rAgo(40)]);
    $r13rOrderId = (int)$pdo->lastInsertId();
    $r13rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
    $r13rItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, digital) VALUES (?, ?, ?, ?, 1, 1000, 1000, 0)");
    $r13rItem->execute([$r13rOrderId, $r13rProduct, 'R13R-BOOK-A', 'Book A']);
    $r13rItemA = (int)$pdo->lastInsertId();
    $r13rItem->execute([$r13rOrderId, $r13rProduct, 'R13R-BOOK-B', 'Book B']);
    $r13rItemB = (int)$pdo->lastInsertId();
    $r13rParcel = static function (string $key, int $itemId, string $sku, ?string $deliveredAt) use ($pdo, $r13rOrderId, $r13rAgo): int {
        $pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, tracking_number, idempotency_key, shipped_at, delivered_at, tracking_status)
            VALUES ('default', ?, 'shipped', 'r13r', ?, ?, ?, ?, ?)")->execute([$r13rOrderId, $key, $key, $r13rAgo(30), $deliveredAt, $deliveredAt === null ? 'in_transit' : 'delivered']);
        $shipmentId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO shipment_items (shipment_id, order_item_id, sku, quantity) VALUES (?, ?, ?, 1)')->execute([$shipmentId, $itemId, $sku]);
        return $shipmentId;
    };
    $r13rOrder = static fn(): array => Orders::byId($r13rOrderId) ?: [];

    $r13rParcel('r13r-n4-a', $r13rItemA, 'R13R-BOOK-A', $r13rAgo(20));
    assert_true(Withdrawal::receivedAt($r13rOrder()) === false,
        'R13 N4: with book B in no parcel yet, the goods have not all arrived (was: the period ran from book A, 20 days ago)');
    $r13rB = $r13rParcel('r13r-n4-b', $r13rItemB, 'R13R-BOOK-B', null);
    assert_true(Withdrawal::receivedAt($r13rOrder()) === false && Withdrawal::withinPeriod($r13rOrder()) && Rma::withinReturnWindow($r13rOrder())
        && Withdrawal::refusal($r13rOrder()) === null,
        'R13 N4: while book B is on the way the consumer may still withdraw and return (was: period over after 14 days from book A)');
    $pdo->prepare("UPDATE shipments SET delivered_at = ?, tracking_status = 'delivered' WHERE id = ?")->execute([$r13rAgo(3), $r13rB]);
    assert_equals(strtotime($r13rAgo(3) . ' UTC'), Withdrawal::receivedAt($r13rOrder()), 'R13 N4: the period starts with the last parcel delivered');
    assert_true(Withdrawal::withinPeriod($r13rOrder()) && Rma::withinReturnWindow($r13rOrder()), 'R13 N4: 3 days after the last parcel the order is withdrawable and returnable');
    $pdo->prepare("UPDATE shipments SET delivered_at = ? WHERE id = ?")->execute([$r13rAgo(16), $r13rB]);
    assert_true(!Withdrawal::withinPeriod($r13rOrder()) && !Rma::withinReturnWindow($r13rOrder()), 'R13 N4: 16 days after the last parcel the period is over');
    // A cancelled parcel does not hold the period open.
    $pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, idempotency_key) VALUES ('default', ?, 'cancelled', 'r13r', 'r13r-n4-c')")->execute([$r13rOrderId]);
    assert_equals(strtotime($r13rAgo(16) . ' UTC'), Withdrawal::receivedAt($r13rOrder()), 'R13 N4: a cancelled parcel is not waited for');
} catch (Throwable $e) { $r13rFail('N4 withdrawal period', $e); }
finally {
    $r13rCfg->setValue(null, $r13rCfgPrev);
    if ($r13rOrderId > 0) {
        $pdo->prepare('DELETE FROM shipment_items WHERE shipment_id IN (SELECT id FROM shipments WHERE order_id = ?)')->execute([$r13rOrderId]);
        $pdo->prepare('DELETE FROM shipments WHERE order_id = ?')->execute([$r13rOrderId]);
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$r13rOrderId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r13rOrderId]);
    }
}

// N5 + T5: refunds. A Stripe fake answers one refund id per idempotency key; a job directory of its own shows the queued jobs.
require_once TEST_CLI_DIR . '/../cli/worker.php';
require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R13rFakeStripe', false)) {
    class R13rFakeStripe extends StripePayment {
        public string $status = 'succeeded';
        public function __construct() { parent::__construct([]); }
        public static function refundIdFor(string $key): string { return 're_r13r_' . substr(sha1($key), 0, 12); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            return ['refund_id' => self::refundIdFor($idempotencyKey), 'status' => $this->status];
        }
    }
}
$r13rJobsDir = test_fixture_path('r13r-jobs');
JobQueue::setDir($r13rJobsDir);
$r13rAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r13rSavedAdapters = $r13rAdapters->getValue();
$r13rStripe = new R13rFakeStripe();
$r13rAdapters->setValue(null, ['payment' => $r13rStripe] + $r13rSavedAdapters);
$r13rRateDb = new ReflectionProperty(Security::class, 'rateDb');
$r13rSavedRateDb = $r13rRateDb->getValue();
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$r13rOrders = [];
$r13rStripeOrder = static function (string $tag, string $paymentStatus, int $total = 1000) use ($pdo, &$r13rOrders): int {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency,
            status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'R13R', 'r13r@example.test', '', '{}', ?, 0, 0, 0, ?, 'eur', 'delivered', ?, 'stripe', ?, ?, 'default', " . Db::now() . ")")
        ->execute(['R13R-' . $tag . '-' . bin2hex(random_bytes(3)), $total, $total, $paymentStatus, 'pi_r13r_' . $tag, json_encode(['provider' => 'stripe', 'service_type' => 'online_card'])]);
    return $r13rOrders[] = (int)$pdo->lastInsertId();
};
$r13rItemRefund = static function (int $orderId, int $amount, string $key) use ($pdo): int {
    $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, 0, 0, ?, 'eur', 'pending', ?)")
        ->execute([$orderId, $amount, $key]);
    return (int)$pdo->lastInsertId();
};
$r13rItemRefundRow = static fn(int $id): array => array_map('strval', array_values($pdo->query('SELECT status, payment_reference FROM order_refunds WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC)));
$r13rPending = static function (string $type, int $orderId) use ($r13rJobsDir): array {
    $jobs = [];
    foreach (glob($r13rJobsDir . '/pending/*.json') ?: [] as $file) {
        $job = json_decode((string)file_get_contents($file), true);
        if (($job['type'] ?? '') === $type && (int)($job['payload']['order_id'] ?? 0) === $orderId) $jobs[] = $job;
    }
    return $jobs;
};
$r13rCheckout = static function (string $tag) use (&$r13rOrders): int {
    Cart::clear();
    Cart::add(97491, null, 2);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SESSION['csrf_token'] = 'r13r-' . $tag;
    $_POST = ['csrf_token' => 'r13r-' . $tag, 'name' => 'R13R Buyer', 'email' => 'r13r-' . $tag . '@example.com', 'country' => 'SK',
        'street' => 'Refund 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer'];
    $result = apiCheckout();
    $_POST = [];
    Cart::clear();
    return $r13rOrders[] = (int)($result['order_id'] ?? 0);
};
try {
    foreach ($GLOBALS as $r13rValue) if ($r13rValue instanceof PDOStatement) $r13rValue->closeCursor();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (97491, 'default', 1, 'R13R refund book', 'r13r-refund-book', 'R13R-REFUND', 'simple', 1500, 'eur', 'active', 0, 'backorder')");

    // N5 (1): a credit note checked the refundable balance outside the order lock. A refund request committed meanwhile
    // (here held open by a second process) and the credit note together returned more than was paid.
    if (Db::isSqlite()) {
        $r13rCreditOrder = $r13rCheckout('credit-race');
        $pdo->exec("UPDATE orders SET status='paid', payment_status='paid', payment_id='' WHERE id={$r13rCreditOrder}");
        $r13rInvoice = InvoiceService::issue($r13rCreditOrder);
        $pdo->exec("UPDATE orders SET status='delivered' WHERE id={$r13rCreditOrder}");
        $r13rTotal = (int)Orders::byId($r13rCreditOrder)['total'];
        [, $r13rRma] = Rma::create($r13rCreditOrder, null, 'R13R credit race', [(int)Orders::items($r13rCreditOrder)[0]['id'] => 2]);
        Rma::setStatus((int)$r13rRma, 'approved');
        $r13rRmaItem = (int)Rma::items((int)$r13rRma)[0]['id'];
        Rma::receive((int)$r13rRma, [$r13rRmaItem => ['quantity' => 2, 'disposition' => 'repair']], 'r13r-credit-receipt');
        $r13rHolder = test_fixture_path('r13r-refund-holder') . '.php';
        file_put_contents($r13rHolder, <<<'PHP'
<?php
// Requests a refund of all but 10.00 of the order in a transaction held open for 2 s, on the parent's database file and store.
[, $root, $file, $store, $order, $amount] = $argv;
require $root . '/src/autoload.php';
StoreContext::activate(unserialize((string)file_get_contents($store), ['allowed_classes' => [StoreContext::class]]));
$db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($db, 'sqlite');
$db->beginTransaction();
try {
    PaymentService::requestRefund((int)$order, (int)$amount, 'r13r-credit-race-request');
} catch (Throwable $e) {
    echo 'child: ', $e->getMessage(), "\n";
    exit(1);
}
echo "held\n";
usleep(2000000);
$db->commit();
echo "committed\n";
PHP);
        $r13rStore = $r13rHolder . '.store';
        file_put_contents($r13rStore, serialize(StoreContext::current()));
        $r13rFile = test_fixture_path('r13r-credit-db') . '.sqlite';
        $r13rSuiteDb = Db::get();
        $r13rSuiteDb->exec('VACUUM INTO ' . $r13rSuiteDb->quote($r13rFile));
        $r13rDb = new PDO('sqlite:' . $r13rFile, null, null, [PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $r13rDb->exec('PRAGMA journal_mode = WAL');
        Db::init($r13rDb, 'sqlite');
        try {
            $r13rProc = proc_open([PHP_BINARY, $r13rHolder, dirname(TEST_CLI_DIR), $r13rFile, $r13rStore, (string)$r13rCreditOrder, (string)($r13rTotal - 1000)],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r13rPipes);
            fclose($r13rPipes[0]);
            $r13rHeld = trim((string)fgets($r13rPipes[1]));
            try {
                InvoiceService::credit((int)$r13rInvoice['id'], (int)$r13rRma, [$r13rRmaItem => 2], 'r13r-credit-race-c');
                $r13rCredit = 'credited';
            } catch (Throwable $e) {
                $r13rCredit = $e->getMessage();
            }
            $r13rHolderOut = trim((string)stream_get_contents($r13rPipes[1]) . stream_get_contents($r13rPipes[2]));
            fclose($r13rPipes[1]);
            fclose($r13rPipes[2]);
            proc_close($r13rProc);
            $r13rPromised = (int)$r13rDb->query("SELECT COALESCE(SUM(amount), 0) FROM order_refunds WHERE order_id = {$r13rCreditOrder}")->fetchColumn()
                + (int)$r13rDb->query("SELECT COALESCE(SUM(amount), 0) FROM payment_transactions WHERE order_id = {$r13rCreditOrder} AND status = 'refund_pending'")->fetchColumn();
        } finally {
            Db::init($r13rSuiteDb, 'sqlite');
            $r13rDb = null;
            foreach (['', '-wal', '-shm'] as $r13rSuffix) @unlink($r13rFile . $r13rSuffix);
            @unlink($r13rHolder);
            @unlink($r13rStore);
        }
        assert_equals(['held', 'Credit exceeds the amount that can still be refunded', 'committed', $r13rTotal - 1000], [$r13rHeld, $r13rCredit, $r13rHolderOut, $r13rPromised],
            'R13 N5: a credit note started while a refund request is uncommitted waits for the order and is refused (was: both, more than the order total)');
    }

    // N5 (2): a second capture while a refund is in flight moved refund_pending to paid as if it were the payment.
    $r13rCapture = $r13rStripeOrder('capture', 'paid');
    PaymentService::requestRefund($r13rCapture, 400, 'r13r-capture-request');
    PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => 'evt_r13r_second_capture',
        'data' => ['order_id' => $r13rCapture, 'amount' => 1000, 'currency' => 'eur', 'payment_intent' => 'pi_r13r_capture_2']], 'stripe');
    $r13rCaptureOrder = Orders::byId($r13rCapture);
    assert_equals(['refund_pending', 400, 1], [(string)$r13rCaptureOrder['payment_status'], PaymentService::requestedRefundInFlight($r13rCaptureOrder),
        (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE order_id = {$r13rCapture} AND status = 'ignored'")->fetchColumn()],
        'R13 N5: a capture during refund_pending is logged as ignored and the refund stays in flight (was: payment paid, the request orphaned)');

    // N5 (3): a provider refund booked by its webhook completed the first pending item refund of the same amount.
    $r13rMatch = $r13rStripeOrder('match', 'refund_pending');
    $r13rA = $r13rItemRefund($r13rMatch, 300, 'r13r-match-a');
    $r13rB = $r13rItemRefund($r13rMatch, 300, 'r13r-match-b');
    $r13rRefundB = R13rFakeStripe::refundIdFor('r13r-match-b');
    PaymentService::bookProviderRefund($r13rMatch, 'stripe', $r13rRefundB, 300, 'refund.succeeded', 'stripe_refund_' . $r13rRefundB);
    assert_equals([['pending', ''], ['pending', '']], [$r13rItemRefundRow($r13rA), $r13rItemRefundRow($r13rB)],
        'R13 N5: a provider refund the shop cannot name yet completes no item refund (was: item refund A, matched by its amount)');
    PaymentService::refund($r13rMatch, 'pi_r13r_match', 300, $r13rStripe, 'r13r-match-b', 'eur');
    assert_equals([['pending', ''], ['succeeded', $r13rRefundB], 300], [$r13rItemRefundRow($r13rA), $r13rItemRefundRow($r13rB), PaymentService::refundedAmount($r13rMatch)],
        "R13 N5: the refund job's result links the booked refund to its own item refund (B), money booked once");

    // N5 (4): the refund worker wrote the payment status blindly: a late item-refund job moved a disputed payment on.
    $r13rDisputed = $r13rStripeOrder('dispute', 'disputed');
    $r13rItem = $r13rItemRefund($r13rDisputed, 300, 'r13r-dispute-item');
    handlePaymentRefund(['order_id' => $r13rDisputed, 'payment_id' => 'pi_r13r_dispute', 'amount' => 300, 'refund_id' => $r13rItem, 'idempotency_key' => 'r13r-dispute-item'], []);
    assert_equals(['disputed', 300, 'succeeded'], [(string)Orders::byId($r13rDisputed)['payment_status'], PaymentService::refundedAmount($r13rDisputed), $r13rItemRefundRow($r13rItem)[0]],
        'R13 N5: the refund job books the money but leaves a disputed payment to the state machine (was: partially_refunded)');

    // T5: a credit note was issued only for money returned by hand. An admin refund through the provider corrects the invoice too.
    $r13rInvoiced = $r13rCheckout('t5');
    $pdo->exec("UPDATE orders SET status='paid', payment_status='paid', payment_id='pi_r13r_t5', payment_method='stripe',
        payment_method_snapshot='" . json_encode(['provider' => 'stripe', 'service_type' => 'online_card']) . "' WHERE id={$r13rInvoiced}");
    $r13rT5Invoice = InvoiceService::issue($r13rInvoiced);
    PaymentService::requestRefundAndQueue($r13rInvoiced, 400, 'r13r-t5-request', ['reason' => 'R13R']);
    $r13rT5Job = $r13rPending('payment_refund', $r13rInvoiced)[0] ?? null;
    assert_true($r13rT5Job !== null, 'R13 T5: the admin refund is queued');
    handlePaymentRefund($r13rT5Job['payload'], $r13rT5Job);
    $r13rCreditJobs = $r13rPending('refund_credit_note', $r13rInvoiced);
    assert_equals(1, count($r13rCreditJobs), 'R13 T5: the booked refund queues its credit note (was: none, the invoice kept the refunded sum)');
    $r13rWorkerSource = (string)file_get_contents(TEST_CLI_DIR . '/../cli/worker.php') . @file_get_contents(TEST_CLI_DIR . '/../src/WorkerJobs.php');
    assert_true(str_contains($r13rWorkerSource, "'refund_credit_note'") && str_contains($r13rWorkerSource, 'InvoiceService::creditBookedRefund('),
        'R13 T5: the worker handles refund_credit_note jobs');
    foreach ($r13rCreditJobs as $r13rJob) {
        InvoiceService::creditBookedRefund((int)$r13rJob['payload']['order_id'], (string)$r13rJob['payload']['refund_key']);
        InvoiceService::creditBookedRefund((int)$r13rJob['payload']['order_id'], (string)$r13rJob['payload']['refund_key']);
    }
    $r13rNotes = $pdo->query("SELECT total_gross, refers_to_invoice_id FROM invoices WHERE order_id = {$r13rInvoiced} AND type = 'credit_note'")->fetchAll(PDO::FETCH_NUM);
    assert_equals([[-400, (int)$r13rT5Invoice['id']]], array_map(static fn(array $row): array => array_map('intval', $row), $r13rNotes),
        'R13 T5: one credit note of -4.00 refers to the invoice, a redelivered job issues no second one');
    // An item refund's money (its RMA credit note exists) gets no second credit note.
    $r13rT5Item = $r13rItemRefund($r13rInvoiced, 200, 'r13r-t5-item');
    PaymentService::refund($r13rInvoiced, 'pi_r13r_t5', 200, $r13rStripe, 'r13r-t5-item', 'eur');
    foreach ($r13rPending('refund_credit_note', $r13rInvoiced) as $r13rJob) InvoiceService::creditBookedRefund((int)$r13rJob['payload']['order_id'], (string)$r13rJob['payload']['refund_key']);
    assert_equals([1, 'succeeded'], [(int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id = {$r13rInvoiced} AND type = 'credit_note'")->fetchColumn(), $r13rItemRefundRow($r13rT5Item)[0]],
        'R13 T5: the refund of an item refund issues no refund credit note of its own');
} catch (Throwable $e) { $r13rFail('N5/T5', $e); }
finally {
    $r13rAdapters->setValue(null, $r13rSavedAdapters);
    $r13rRateDb->setValue(null, $r13rSavedRateDb);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    foreach ($r13rOrders as $r13rId) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'invoices' => 'order_id = ?',
            'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r13rTable => $r13rWhere) {
            try { $pdo->prepare("DELETE FROM {$r13rTable} WHERE {$r13rWhere}")->execute([$r13rId]); } catch (Throwable $e) {}
        }
    }
    $pdo->exec('DELETE FROM products WHERE id = 97491');
}
