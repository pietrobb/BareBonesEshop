<?php

// Review round 36 (2026-11-13), low "refunds" (goal #460): the reviewer's refund fuzzer once ended with 2.80 more on the
// payment ledger than on the credit notes, the refund_credit_note job having failed with "The refund exceeds what the
// invoice still has uncredited". This fuzzer drives the real refund paths against a Stripe fake that keeps its own
// books (idempotency keys, the charge limit, refunds listed by key, answers lost in an outage, refunds created late):
// returns credited, refund jobs run, webhooks delivered late and out of order, stalled item refunds released, the return
// refunded again, admin refunds of part of the order, refunds made in the Stripe dashboard. Then everything settles and
// per order Stripe, the ledger and the credit notes must hold the same money, with no failed credit-note job.
// R36_REFUND_FUZZ_RUNS sets the number of seeds (default 24); SQLite always, MySQL 8 / MariaDB 11.4 via TEST_MYSQL_DSN.
echo "\n--- R36: refund fuzzer (Stripe, ledger and credit notes agree) ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r36zJobsDir = test_fixture_path('r36z-jobs');
JobQueue::setDir($r36zJobsDir);
$r36zRuns = max(1, (int)(getenv('R36_REFUND_FUZZ_RUNS') ?: 24));
$r36zFirstSeed = (int)(getenv('R36_REFUND_FUZZ_SEED') ?: 3600);

if (!class_exists('R36zFakeStripe', false)) {
    /** Stripe as far as refunds go: one book per PaymentIntent, refunds never above the charge. */
    class R36zFakeStripe extends StripePayment {
        /** @var array<string, int> paid per PaymentIntent */
        public static array $paid = [];
        /** @var array<string, array{pi: string, key: string, amount: int, status: string, delivered: string}> */
        public static array $refunds = [];
        /** @var list<array{pi: string, key: string, amount: int}> requests Stripe received but executes only later */
        public static array $late = [];
        public static string $mode = 'ok';
        private static int $sequence = 0;
        public function __construct() { parent::__construct([]); }
        public static function reset(): void { self::$paid = self::$refunds = self::$late = []; self::$mode = 'ok'; }
        public static function committed(string $pi): int {
            $sum = 0;
            foreach (self::$refunds as $refund) if ($refund['pi'] === $pi && $refund['status'] !== 'failed') $sum += $refund['amount'];
            return $sum;
        }
        public static function succeeded(string $pi): int {
            $sum = 0;
            foreach (self::$refunds as $refund) if ($refund['pi'] === $pi && $refund['status'] === 'succeeded') $sum += $refund['amount'];
            return $sum;
        }
        /** Creates the refund at Stripe; null when the charge has no room for it (Stripe refuses). */
        public static function create(string $pi, string $key, int $amount, string $status): ?string {
            if ($amount <= 0 || self::committed($pi) + $amount > (self::$paid[$pi] ?? 0)) return null;
            $id = 're_r36z_' . (++self::$sequence);
            self::$refunds[$id] = ['pi' => $pi, 'key' => $key, 'amount' => $amount, 'status' => $status, 'delivered' => ''];
            return $id;
        }
        private static function byKey(string $pi, string $key): ?string {
            foreach (self::$refunds as $id => $refund) if ($key !== '' && $refund['pi'] === $pi && $refund['key'] === $key) return $id;
            return null;
        }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            $mode = self::$mode;
            if ($mode === 'error') throw new RuntimeException('R36Z network error');
            if ($mode === 'decline') throw new PaymentRefundDeclined('R36Z declined', 400);
            $id = self::byKey($paymentId, $idempotencyKey);
            if ($id === null) {
                foreach (self::$late as $late) if ($late['pi'] === $paymentId && $late['key'] === $idempotencyKey) throw new RuntimeException('R36Z still processing the first request');
                if ($mode === 'lost_late') {
                    self::$late[] = ['pi' => $paymentId, 'key' => $idempotencyKey, 'amount' => $amount];
                    throw new RuntimeException('R36Z timeout (executed later)');
                }
                $id = self::create($paymentId, $idempotencyKey, $amount, $mode === 'pending' ? 'pending' : 'succeeded');
                if ($id === null) throw new PaymentRefundDeclined('R36Z amount exceeds the charge', 400);
            }
            if ($mode === 'lost') throw new RuntimeException('R36Z timeout (executed)');
            return ['refund_id' => $id, 'status' => self::$refunds[$id]['status']];
        }
        public function refundByKey(string $paymentId, string $idempotencyKey): ?array {
            $id = self::byKey($paymentId, $idempotencyKey);
            return $id === null ? ['status' => 'none'] : ['refund_id' => $id, 'status' => self::$refunds[$id]['status'], 'amount' => self::$refunds[$id]['amount']];
        }
    }
}

$r36zChecks = static function (PDO $pdo, string $tag) use ($r36zJobsDir, $r36zRuns, $r36zFirstSeed): void {
    $dropQueue = static function () use ($r36zJobsDir): void {
        foreach (['pending', 'processing'] as $sub) foreach (glob($r36zJobsDir . '/' . $sub . '/*.json') ?: [] as $file) @unlink($file);
    };
    $product = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
    $orders = [];
    $violations = [];
    $refusals = 0;
    $paths = [];
    try {
        for ($run = 0; $run < $r36zRuns; $run++) {
            $seed = $r36zFirstSeed + $run;
            mt_srand($seed);
            $dropQueue();
            R36zFakeStripe::reset();
            $trace = [];
            $creditErrors = [];
            // An order of 1–3 lines (standard or books VAT), paid with Stripe and invoiced.
            $number = 'R36Z-' . $tag[0] . '-' . $seed;
            $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
            $lines = [];
            for ($l = 0, $count = mt_rand(1, 3); $l < $count; $l++) {
                $price = mt_rand(150, 4990);
                $qty = mt_rand(1, 3);
                $lines[] = ['sku' => "{$number}-{$l}", 'product_id' => 1, 'variant_id' => null, 'price' => $price, 'qty' => $qty, 'total' => $price * $qty,
                    'available' => true, 'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => mt_rand(0, 1) ? 'standard' : 'books',
                    'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
            }
            $subtotal = array_sum(array_column($lines, 'total'));
            $items = Cart::applyVat(['items' => $lines, 'subtotal' => $subtotal, 'currency' => 'eur'], $ctx)['items'];
            $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
            $pi = 'pi_' . strtolower(str_replace('-', '_', $number));
            $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
                    total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
                VALUES (?, 'R36Z', '', '', '{\"country\":\"SK\"}', ?, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
                ->execute([$number, $subtotal, (int)$snapshot['totals']['gross'], $pi,
                    json_encode(['provider' => 'stripe', 'service_type' => 'online_card']), json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $orderId = $orders[] = (int)$pdo->lastInsertId();
            foreach ($lines as $line) {
                $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$orderId, $product, $line['sku'], 'R36Z ' . $line['sku'], $line['qty'], $line['price'], $line['total']]);
            }
            InvoiceService::issue($orderId);
            $invoiceId = (int)$pdo->query("SELECT id FROM invoices WHERE order_id = {$orderId} AND type = 'invoice'")->fetchColumn();
            $paid = (int)$snapshot['totals']['gross'];
            R36zFakeStripe::$paid[$pi] = $paid;
            $rmas = [];

            // The worker: refund jobs at the provider (each attempt answered per R36zFakeStripe::$mode), credit-note jobs.
            $runQueue = static function (string $forceMode = '') use ($r36zJobsDir, $orderId, &$trace, &$creditErrors): void {
                foreach (glob($r36zJobsDir . '/pending/*.json') ?: [] as $file) {
                    $job = json_decode((string)file_get_contents($file), true);
                    if (!is_array($job)) { @unlink($file); continue; }
                    $payload = (array)($job['payload'] ?? []);
                    if (($job['type'] ?? '') === 'payment_refund') {
                        R36zFakeStripe::$mode = $forceMode !== '' ? $forceMode : ['ok', 'ok', 'ok', 'pending', 'lost', 'lost_late', 'error', 'decline'][mt_rand(0, 7)];
                        $trace[] = 'job refund ' . ($payload['refund_id'] ?? 'request') . ' ' . ($payload['amount'] ?? '') . ' ' . R36zFakeStripe::$mode;
                        @unlink($file);
                        try {
                            // The last attempt with its re-queues spent: an error kills the job (the refund stalls).
                            WorkerJobs::handlePaymentRefund($payload + ['error_requeues' => 12], ['attempts' => 2, 'max_attempts' => 3] + $job);
                        } catch (Throwable $e) {
                            $trace[] = '  job died: ' . $e->getMessage();
                            // Half the time the queue still has a retry left.
                            if ($forceMode === '' && mt_rand(0, 1) === 1) JobQueue::push('payment_refund', $payload, 1);
                        }
                        R36zFakeStripe::$mode = 'ok';
                    } elseif (($job['type'] ?? '') === 'refund_credit_note') {
                        @unlink($file);
                        try {
                            $credit = InvoiceService::creditBookedRefund((int)$payload['order_id'], (string)$payload['refund_key'], (int)($payload['deferrals'] ?? 0), (int)($payload['waits'] ?? 0));
                            $trace[] = 'job credit ' . $payload['refund_key'] . ' -> ' . ($credit === null ? 'none' : (string)$credit['total_gross']);
                        } catch (Throwable $e) {
                            $creditErrors[] = $payload['refund_key'] . ': ' . $e->getMessage();
                            $trace[] = 'job credit ' . $payload['refund_key'] . ' FAILED ' . $e->getMessage();
                        }
                    } else {
                        @unlink($file);
                    }
                }
            };
            // Stripe's webhooks: a refund's charge.refunded once it succeeded, its refund.failed once it failed.
            $deliver = static function (bool $all) use ($orderId, &$trace): void {
                foreach (R36zFakeStripe::$refunds as $id => $refund) {
                    if ($refund['delivered'] === $refund['status'] || $refund['status'] === 'pending' || (!$all && mt_rand(0, 2) === 0)) continue;
                    R36zFakeStripe::$refunds[$id]['delivered'] = $refund['status'];
                    $trace[] = "webhook {$id} {$refund['status']} {$refund['amount']} key={$refund['key']}";
                    if ($refund['status'] === 'succeeded') {
                        PaymentService::bookProviderRefund($orderId, 'stripe', $id, $refund['amount'], 'charge.refunded', 'stripe_refund_' . $id,
                            $refund['key'] !== '' ? ['refund_key' => $refund['key']] : []);
                    } else {
                        PaymentService::providerRefundFailed($orderId, 'stripe', $id, $refund['amount'], 'stripe_refund_failed_' . $id);
                    }
                }
            };
            $settlePending = static function (bool $all) use (&$trace): void {
                foreach (R36zFakeStripe::$refunds as $id => $refund) {
                    if ($refund['status'] !== 'pending' || (!$all && mt_rand(0, 1) === 0)) continue;
                    R36zFakeStripe::$refunds[$id]['status'] = $all || mt_rand(0, 3) > 0 ? 'succeeded' : 'failed';
                    $trace[] = "stripe {$id} -> " . R36zFakeStripe::$refunds[$id]['status'];
                }
            };
            $executeLate = static function (bool $all) use (&$trace): void {
                foreach (R36zFakeStripe::$late as $index => $late) {
                    if (!$all && mt_rand(0, 1) === 0) continue;
                    unset(R36zFakeStripe::$late[$index]);
                    $id = R36zFakeStripe::create($late['pi'], $late['key'], $late['amount'], 'succeeded');
                    $trace[] = "stripe executes late {$late['key']} {$late['amount']} -> " . ($id ?? 'refused (no room)');
                }
                R36zFakeStripe::$late = array_values(R36zFakeStripe::$late);
            };
            $release = static function () use ($orderId, &$trace): void {
                $stalled = PaymentService::stalledItemRefund(Orders::byId($orderId));
                if ($stalled === null) return;
                $trace[] = "release {$stalled['refund_id']} -> " . PaymentService::releaseStalledItemRefund($orderId, $stalled['refund_id'], 'r36z-admin', 'R36Z nothing at Stripe');
            };

            for ($step = 0, $steps = mt_rand(6, 14); $step < $steps; $step++) {
                $op = mt_rand(0, 9);
                $paths[$op] = ($paths[$op] ?? 0) + 1;
                try {
                    switch ($op) {
                        case 0: case 1: // the customer returns units; the admin credits them (item refund, job queued)
                            $choice = [];
                            foreach (Orders::items($orderId) as $item) if (mt_rand(0, 1) === 1) $choice[(int)$item['id']] = mt_rand(1, (int)$item['quantity']);
                            if ($choice === []) break;
                            [, $rma] = Rma::create($orderId, null, "R36Z return {$step}", $choice);
                            if (!$rma) break; // every unit is claimed already
                            Rma::setStatus((int)$rma, 'approved');
                            $decisions = [];
                            foreach (Rma::items((int)$rma) as $rmaItem) $decisions[(int)$rmaItem['id']] = ['quantity' => (int)$rmaItem['quantity'], 'disposition' => 'repair'];
                            if ($decisions === []) break;
                            Rma::receive((int)$rma, $decisions, "r36z-receipt-{$seed}-{$step}");
                            $rmas[] = (int)$rma;
                            $credit = InvoiceService::credit($invoiceId, (int)$rma, array_map(static fn(array $d): int => $d['quantity'], $decisions), "r36z-{$seed}-{$step}");
                            $trace[] = "credit rma {$rma} refund {$credit['refund_id']} " . -(int)$credit['total_gross'];
                            break;
                        case 2: case 3:
                            $runQueue();
                            break;
                        case 4:
                            $settlePending(false);
                            $executeLate(false);
                            $deliver(false);
                            break;
                        case 5:
                            $release();
                            break;
                        case 6: // "Refunded" on a return that has units to credit again (after a release)
                            foreach ($rmas as $rma) {
                                $quantities = InvoiceService::creditableQuantities($rma);
                                if ($quantities === []) continue;
                                $credit = InvoiceService::credit($invoiceId, $rma, $quantities, 'admin-credit-' . $rma . '-' . InvoiceService::creditAttempts($rma));
                                $trace[] = "credit again rma {$rma} refund {$credit['refund_id']} " . -(int)$credit['total_gross'];
                                break;
                            }
                            break;
                        case 7: // the admin refunds part of the order (no return)
                            $claimable = PaymentService::claimableRefund($orderId);
                            if ($claimable <= 0) break;
                            $amount = mt_rand(1, min($claimable, 1500));
                            PaymentService::requestRefundAndQueue($orderId, $amount, "r36z-request-{$seed}-{$step}");
                            $trace[] = "admin refund {$amount}";
                            break;
                        case 8: // a refund made in the Stripe dashboard
                            $room = $paid - R36zFakeStripe::committed($pi);
                            if ($room <= 0) break;
                            $amount = mt_rand(1, min($room, 900));
                            $trace[] = "dashboard refund {$amount} -> " . (R36zFakeStripe::create($pi, '', $amount, 'succeeded') ?? 'refused');
                            break;
                        case 9:
                            $executeLate(false);
                            $deliver(false);
                            break;
                    }
                } catch (LogicException | RuntimeException | InvalidArgumentException $e) {
                    $refusals++; // the shop refused the admin's action: allowed, nothing may have moved
                    $trace[] = "op {$op} refused: " . $e->getMessage();
                }
            }
            // Everything settles: Stripe executes and reports all, the worker runs, the admin releases what stalled and retries
            // what the provider never got.
            for ($round = 0; $round < 8; $round++) {
                $executeLate(true);
                $settlePending(true);
                $deliver(true);
                $runQueue('ok');
                $release();
                $order = Orders::byId($orderId);
                if (PaymentService::unexecutedRefundRequest($order) !== null) {
                    try { PaymentService::retryRefundRequest($orderId, 'r36z-admin'); } catch (Throwable $e) { $trace[] = 'retry refused: ' . $e->getMessage(); }
                }
                $runQueue('ok');
            }
            $stripe = R36zFakeStripe::succeeded($pi);
            $ledger = PaymentService::refundedAmount($orderId);
            $credits = (int)$pdo->query("SELECT COALESCE(SUM(-total_gross), 0) FROM invoices WHERE order_id = {$orderId} AND type = 'credit_note' AND status <> 'cancelled'")->fetchColumn();
            $openItem = (int)$pdo->query("SELECT COUNT(*) FROM order_refunds WHERE order_id = {$orderId} AND status = 'pending'")->fetchColumn();
            if ($stripe !== $ledger || $ledger !== $credits || $creditErrors !== [] || $stripe > $paid || $openItem > 0) {
                $violations[] = "seed {$seed}: paid {$paid}, Stripe {$stripe}, ledger {$ledger}, credit notes {$credits}, pending item refunds {$openItem}"
                    . ($creditErrors ? '; credit job failed: ' . implode(' / ', $creditErrors) : '') . "\n      " . implode("\n      ", $trace);
            }
        }
    } finally {
        $dropQueue();
        foreach ($orders as $cleanup) {
            foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
                'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
                'return_receipts' => 'rma_item_id IN (SELECT id FROM rma_items WHERE rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?))',
                'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $table => $where) {
                try { $pdo->prepare("DELETE FROM {$table} WHERE {$where}")->execute([$cleanup]); } catch (Throwable $e) {}
            }
        }
    }
    ksort($paths);
    echo "  R36 refund fuzzer {$tag}: {$r36zRuns} runs, operations " . json_encode($paths) . ", {$refusals} refused admin actions\n";
    assert_equals([], array_slice($violations, 0, 3),
        "R36 refund fuzzer {$tag}: after {$r36zRuns} random runs Stripe, the ledger and the credit notes hold the same money, no credit-note job fails ("
        . count($violations) . ' violations)');
};

(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
$r36zAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r36zSavedAdapters = $r36zAdapters->getValue();
$r36zAdapters->setValue(null, ['payment' => new R36zFakeStripe()] + $r36zSavedAdapters);
$r36zSqlite = Db::get();
$r36zSqliteDriver = Db::driver();
try {
    $r36zChecks($r36zSqlite, 'SQLite');
    if (getenv('TEST_MYSQL_DSN')) {
        $r36zNative = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $r36zNativeDatabase = 'bbe_r36z_' . bin2hex(random_bytes(6)) . '_test';
        $r36zNativeLock = test_fixture_path('r36z-native') . '.lock';
        $r36zNative->exec("CREATE DATABASE {$r36zNativeDatabase} CHARACTER SET utf8mb4");
        try {
            $r36zNative->exec("USE {$r36zNativeDatabase}");
            Db::init($r36zNative, 'mysql');
            (new Migrator($r36zNative, dirname(TEST_CLI_DIR) . '/migrations', $r36zNativeLock))->apply();
            $r36zNative->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r36z', 'R36Z', 'r36z', 0)");
            $r36zNative->exec("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', " . (int)$r36zNative->lastInsertId() . ", 'R36Z-P', 'r36z-p', 'R36Z Product', 1000, 5, 'active')");
            $r36zChecks($r36zNative, 'MySQL');
        } catch (Throwable $e) {
            assert_true(false, 'R36 refund fuzzer MySQL: setup (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        } finally {
            Db::init($r36zSqlite, $r36zSqliteDriver);
            $r36zNative->exec("DROP DATABASE IF EXISTS {$r36zNativeDatabase}");
            $r36zNative = null;
            @unlink($r36zNativeLock);
        }
    } else {
        echo "  [SKIP] R36 refund fuzzer native MySQL/MariaDB (set TEST_MYSQL_DSN)\n";
    }
} catch (Throwable $e) {
    assert_true(false, 'R36 refund fuzzer (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r36zAdapters->setValue(null, $r36zSavedAdapters);
}
