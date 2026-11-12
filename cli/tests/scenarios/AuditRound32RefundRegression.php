<?php

// Review round 32 (2026-11-11, main @ f21a3cff), lows on refunds:
// - the refund.updated of a refund 2.0.x booked while it was still pending waited for a charge.refunded that never came:
//   Stripe got 503 for three days and a warning was raised;
// - "retry" was offered while the request's job still waited in the queue, and the second job, run after a later request,
//   moved that request's payment out of refund_pending;
// - "paid" set by hand did not release the request (it counted as in flight again after a credit note);
// - a refund job died for good after three network errors.
// SQLite always, MySQL 8 / MariaDB 11.4 via TEST_MYSQL_DSN.
echo "\n--- R32: refunds — a 2.0.x pending booking, retry while queued, a duplicate job, manual paid, network errors ---\n";

if (!function_exists('stripeRefundKey')) require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r32rJobsDir = test_fixture_path('r32r-jobs');
JobQueue::setDir($r32rJobsDir);
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R32rFakeStripe', false)) {
    class R32rFakeStripe extends StripePayment {
        /** null: the refund succeeds; 0: the network fails; another int: Stripe answers with that HTTP code. */
        public static ?int $answer = null;
        public static int $calls = 0;
        /** @var list<array<string,mixed>>|null what GET /v1/refunds?charge= answers; null: Stripe cannot be reached */
        public static ?array $list = null;
        public function __construct() { parent::__construct([]); }
        protected function apiCall(string $method, string $endpoint, array $params = [], array $extraHeaders = []): array {
            self::$calls++;
            if (self::$answer !== null) throw new \RuntimeException(self::$answer === 0 ? 'Connection timed out' : 'Stripe API: declined', self::$answer);
            return ['id' => 're_r32r_' . substr(sha1((string)($params['metadata'][self::REFUND_KEY_METADATA] ?? uniqid())), 0, 10), 'status' => 'succeeded'];
        }
        public function chargeRefunds(string $chargeId): ?array {
            if (self::$list === null) throw new RuntimeException('Stripe API unreachable');
            return self::$list;
        }
    }
}
// The payment_refund payloads of $orderId waiting or running in the file queue.
$r32rQueued = static function (int $orderId) use ($r32rJobsDir): array {
    $payloads = [];
    foreach (['pending', 'processing'] as $sub) foreach (glob($r32rJobsDir . '/' . $sub . '/*.json') ?: [] as $file) {
        $job = json_decode((string)file_get_contents($file), true);
        if (($job['type'] ?? null) === 'payment_refund' && (int)($job['payload']['order_id'] ?? 0) === $orderId) $payloads[] = $job['payload'];
    }
    return $payloads;
};
$r32rDropQueue = static function () use ($r32rJobsDir): void {
    foreach (['pending', 'processing'] as $sub) foreach (glob($r32rJobsDir . '/' . $sub . '/*.json') ?: [] as $file) @unlink($file);
};

// All five findings against one database ($tag 'SQLite' or 'MySQL'); every section is checked on its own.
$r32rChecks = static function (PDO $pdo, string $tag) use ($r32rQueued, $r32rDropQueue): void {
    $r32rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
    $r32rOrders = [];
    $r32rOrder = static function (string $number, string $paymentStatus = 'paid') use ($pdo, $r32rProduct, &$r32rOrders): int {
        $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
        $line = ['product_id' => 1, 'variant_id' => null, 'sku' => "{$number}-PB", 'price' => 4200, 'qty' => 1, 'total' => 4200, 'available' => true,
            'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'books', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
        $items = Cart::applyVat(['items' => [$line], 'subtotal' => 4200, 'currency' => 'eur'], $ctx)['items'];
        $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
                total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
            VALUES (?, 'R32R', 'r32r@example.test', '', '{\"country\":\"SK\"}', 4200, 0, 0, 0, ?, 'eur', 'delivered', ?, 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
            ->execute([$number, (int)$snapshot['totals']['gross'], $paymentStatus, 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card']),
                json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $id = $r32rOrders[] = (int)$pdo->lastInsertId();
        // SQLite reuses the id of an order an earlier scenario deleted; rows it left under that id are not this order's.
        foreach (['payment_transactions', 'order_refunds'] as $r32rStale) $pdo->prepare("DELETE FROM {$r32rStale} WHERE order_id = ?")->execute([$id]);
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 4200, 4200)')
            ->execute([$id, $r32rProduct, "{$number}-PB", "R32R {$number}"]);
        InvoiceService::issue($id);
        return $id;
    };
    $r32rState = static fn(int $orderId): array => [PaymentService::refundedAmount($orderId), (string)Orders::byId($orderId)['payment_status'],
        PaymentService::requestedRefundInFlight(Orders::byId($orderId))];
    $r32rJob = static fn(int $orderId, int $amount, string $key, int $itemRefund = 0, array $job = [], array $extra = []) => WorkerJobs::handlePaymentRefund(['order_id' => $orderId,
        'payment_id' => (string)Orders::byId($orderId)['payment_id'], 'amount' => $amount, 'idempotency_key' => $key] + ($itemRefund > 0 ? ['refund_id' => $itemRefund] : []) + $extra, $job);
    $r32rCredit = static function (int $orderId, int $amount, string $key) use ($pdo): int {
        $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, 0, 0, ?, 'eur', 'pending', ?)")
            ->execute([$orderId, $amount, $key]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE orders SET payment_status = 'refund_pending' WHERE id = ? AND payment_status IN ('paid', 'partially_refunded', 'disputed')")->execute([$orderId]);
        return $id;
    };
    $r32rFail = static fn(string $section, Throwable $e) => assert_true(false,
        "R32 refunds {$tag}, {$section} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');

    try {
        // 1. "Retry" while the request's job still waits in the queue.
        $r32rDropQueue();
        $o1 = $r32rOrder('R32R-1');
        PaymentService::requestRefundAndQueue($o1, 1000, 'r32r-1', ['reason' => 'R32R']);
        $queuedRetry = [PaymentService::unexecutedRefundRequest(Orders::byId($o1)), PaymentService::retryRefundRequest($o1, 'r32r-admin'), count($r32rQueued($o1))];
        $r32rDropQueue(); // the job is lost (a queue directory wiped): now nothing will send it
        assert_equals([null, false, 1, 1000], [...$queuedRetry, PaymentService::unexecutedRefundRequest(Orders::byId($o1))['amount'] ?? null],
            "R32 refunds {$tag}: no \"retry\" while the request's job waits in the queue; once no job is left it is offered (was: offered at once, a second job queued)");
    } catch (Throwable $e) { $r32rFail('retry while queued', $e); }
    try {
        // 2. A duplicate job of a settled request after a later request: it neither calls Stripe nor closes the later request.
        $o2 = $r32rOrder('R32R-2');
        PaymentService::requestRefundAndQueue($o2, 1000, 'r32r-2', ['reason' => 'R32R']);
        $r32rJob($o2, 1000, 'r32r-2_execute');
        PaymentService::requestRefundAndQueue($o2, 500, 'r32r-2b', ['reason' => 'R32R']);
        R32rFakeStripe::$calls = 0;
        $r32rJob($o2, 1000, 'r32r-2_execute');
        assert_equals([[1000, 'refund_pending', 500], 0], [$r32rState($o2), R32rFakeStripe::$calls],
            "R32 refunds {$tag}: the duplicate job of the first request leaves the later 5.00 in flight and calls Stripe no more (was: partially_refunded, the 5.00 as paid out)");
        $r32rJob($o2, 500, 'r32r-2b_execute');
        assert_equals([1500, 'partially_refunded', 0], $r32rState($o2), "R32 refunds {$tag}: the later request's own job then settles it");
    } catch (Throwable $e) { $r32rFail('duplicate job', $e); }
    try {
        // 3. "Paid" set by hand on the refund_pending payment releases the request, also after a later credit note.
        $r32rDropQueue();
        $o3 = $r32rOrder('R32R-3');
        PaymentService::requestRefundAndQueue($o3, 1000, 'r32r-3', ['reason' => 'R32R']);
        PaymentService::recordAdminPayment(Orders::byId($o3), 'paid', 0, 'R32R the refund was not sent', 'r32r-admin');
        $item3 = $r32rCredit($o3, 300, 'r32r-3-item');
        $released = [$r32rState($o3), PaymentService::unexecutedRefundRequest(Orders::byId($o3))];
        R32rFakeStripe::$calls = 0;
        $r32rJob($o3, 1000, 'r32r-3_execute'); // the request's job, still queued when "paid" was set
        $r32rJob($o3, 300, 'r32r-3-item', $item3);
        assert_equals([[[0, 'refund_pending', 0], null], 1, [300, 'partially_refunded', 0]], [$released, R32rFakeStripe::$calls, $r32rState($o3)],
            "R32 refunds {$tag}: after \"paid\" by hand the request is not in flight, no \"retry\", its job refunds nothing, the credit note settles (was: 10.00 in flight, refund_pending for good)");
    } catch (Throwable $e) { $r32rFail('manual paid', $e); }
    try {
        // 4. Network errors: the last attempt of an item refund job is queued again with a delay, up to a limit.
        $r32rDropQueue();
        $o4 = $r32rOrder('R32R-4');
        $item4 = $r32rCredit($o4, 400, 'r32r-4-item');
        R32rFakeStripe::$answer = 0;
        $early = 'no error';
        try { $r32rJob($o4, 400, 'r32r-4-item', $item4, ['attempts' => 0, 'max_attempts' => 3]); } catch (RuntimeException $e) { $early = 'failed'; }
        $last = 'requeued';
        try { $r32rJob($o4, 400, 'r32r-4-item', $item4, ['attempts' => 2, 'max_attempts' => 3]); } catch (RuntimeException $e) { $last = 'failed: ' . $e->getMessage(); }
        $requeued = $r32rQueued($o4);
        $limit = 'requeued';
        try { $r32rJob($o4, 400, 'r32r-4-item', $item4, ['attempts' => 2, 'max_attempts' => 3], ['error_requeues' => 12]); } catch (RuntimeException $e) { $limit = 'failed'; }
        R32rFakeStripe::$answer = null;
        assert_equals(['failed', 'requeued', 1, 1, 'failed'], [$early, $last, count($requeued), (int)($requeued[0]['error_requeues'] ?? 0), $limit],
            "R32 refunds {$tag}: an item refund's last failed attempt is queued again (the queue retries first; after 12 re-queues it fails) (was: the job died, the credit note stayed pending)");
        $r32rDropQueue();
        $r32rJob($o4, 400, 'r32r-4-item', $item4, [], ['error_requeues' => 1]);
        assert_equals([400, 'succeeded'], [PaymentService::refundedAmount($o4), (string)$pdo->query("SELECT status FROM order_refunds WHERE id = {$item4}")->fetchColumn()],
            "R32 refunds {$tag}: the re-queued job refunds the credit note once Stripe answers");
    } catch (Throwable $e) { R32rFakeStripe::$answer = null; $r32rFail('network errors', $e); }
    try {
        // 5. A refund 2.0.x booked from a charge.refunded while it was pending; its refund.updated (succeeded) comes now.
        $o5 = $r32rOrder('R32R-5', 'partially_refunded');
        $booked = time() - 60;
        $stamp = gmdate('Y-m-d H:i:s', $booked);
        $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response, created_at, updated_at)
            VALUES (?, 'default', 'stripe', ?, 'partially_refunded', 1000, 'eur', ?, ?, ?, ?)")
            ->execute([$o5, 'pi_r32r-5', "stripe_refund_ch_r32r_{$o5}_1000", json_encode(['cumulative_amount' => 1000]), $stamp, $stamp]);
        $old = ['id' => 're_r32r_5_old', 'amount' => 1000, 'status' => 'succeeded', 'created' => $booked - 5, 'charge' => 'ch_r32r_5', 'metadata' => [], 'payment_intent' => 'pi_r32r-5'];
        $updated = static function (string $event) use ($o5, $old): string {
            try {
                applyStripeRefundUpdated($old, $event, $o5);
                return 'processed';
            } catch (PaymentWebhookDeferred) {
                return 'deferred';
            }
        };
        R32rFakeStripe::$list = null;
        $unlisted = $updated('evt_r32r_5a');
        R32rFakeStripe::$list = [$old];
        $listed = $updated('evt_r32r_5b');
        assert_equals(['deferred', 'processed', 1000, 'partially_refunded'], [$unlisted, $listed, PaymentService::refundedAmount($o5), (string)Orders::byId($o5)['payment_status']],
            "R32 legacy refund {$tag}: the refund.updated of a refund 2.0.x booked while pending lists the charge's refunds and is identified, not booked again (was: 503 for three days); without the list it waits");
    } catch (Throwable $e) {
        $r32rFail('2.0.x pending booking', $e);
    } finally {
        R32rFakeStripe::$answer = null;
        R32rFakeStripe::$list = null;
        $r32rDropQueue();
        foreach ($r32rOrders as $r32rCleanup) {
            foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
                'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r32rTable => $r32rWhere) {
                try { $pdo->prepare("DELETE FROM {$r32rTable} WHERE {$r32rWhere}")->execute([$r32rCleanup]); } catch (Throwable $e) {}
            }
        }
    }
};

$r32rAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r32rSavedAdapters = $r32rAdapters->getValue();
$r32rAdapters->setValue(null, ['payment' => new R32rFakeStripe()] + $r32rSavedAdapters);
$r32rSqlite = Db::get();
$r32rSqliteDriver = Db::driver();
try {
    $r32rChecks($r32rSqlite, 'SQLite');
    if (getenv('TEST_MYSQL_DSN')) {
        $r32rNative = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $r32rNativeDatabase = 'bbe_r32r_' . bin2hex(random_bytes(6)) . '_test';
        $r32rNativeLock = test_fixture_path('r32r-native') . '.lock';
        $r32rNative->exec("CREATE DATABASE {$r32rNativeDatabase} CHARACTER SET utf8mb4");
        try {
            $r32rNative->exec("USE {$r32rNativeDatabase}");
            Db::init($r32rNative, 'mysql');
            (new Migrator($r32rNative, dirname(TEST_CLI_DIR) . '/migrations', $r32rNativeLock))->apply();
            $r32rNative->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r32r', 'R32R', 'r32r', 0)");
            $r32rNative->exec("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', " . (int)$r32rNative->lastInsertId() . ", 'R32R-P', 'r32r-p', 'R32R Product', 4200, 5, 'active')");
            $r32rChecks($r32rNative, 'MySQL');
        } catch (Throwable $e) {
            assert_true(false, 'R32 refunds MySQL: setup (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        } finally {
            Db::init($r32rSqlite, $r32rSqliteDriver);
            $r32rNative->exec("DROP DATABASE IF EXISTS {$r32rNativeDatabase}");
            $r32rNative = null;
            @unlink($r32rNativeLock);
        }
    } else {
        echo "  [SKIP] R32 refunds native MySQL/MariaDB (set TEST_MYSQL_DSN)\n";
    }
} finally {
    $r32rAdapters->setValue(null, $r32rSavedAdapters);
}
