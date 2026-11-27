<?php
// R50 money fuzz harness. Boots a migrated shop from ./tree on sqlite|mysql.
require_once __DIR__ . '/lib.php';

class R50Gateway extends MockPayment implements IdempotentRefundAdapter {
    public static array $status = [];       // payment id => provider status
    public static string $refundMode = 'succeeded';
    public static array $refundsByKey = [];
    public static array $refunded = [];     // payment id => provider refunded sum
    public static array $calls = [];
    public static array $overAsk = [];
    public static array $pending = [];   // refund id => [payment id, amount]
    public function __construct() { parent::__construct(['mock_webhook_secret' => str_repeat('k', 32)]); }
    public function redirectOrigins(): array { return []; }
    public function createSession(array $order): array {
        $id = 'cs_r50_' . $order['order_number'] . '_' . bin2hex(random_bytes(3));
        self::$status[$id] = 'open';
        return ['url' => 'https://pay.example/' . $id, 'session_id' => $id];
    }
    public static function orderOf(string $paymentId): int {
        if (preg_match('/^pi_(\d+)$/', $paymentId, $m)) return (int)$m[1];
        $st = Db::get()->prepare('SELECT order_id FROM payment_transactions WHERE provider_ref = ? UNION SELECT id FROM orders WHERE payment_id = ? LIMIT 1');
        $st->execute([$paymentId, $paymentId]);
        return (int)$st->fetchColumn();
    }
    public function getStatus(string $paymentId): string { return self::$status[$paymentId] ?? 'open'; }
    public function refund(string $paymentId, int $amount): array { return $this->refundWithIdempotency($paymentId, $amount, 'nokey_' . uniqid()); }
    public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
        if (isset(self::$refundsByKey[$idempotencyKey])) return self::$refundsByKey[$idempotencyKey];
        if (self::$refundMode === 'error') throw new RuntimeException('r50 refund network error');
        if (self::$refundMode === 'declined') throw new PaymentRefundDeclined('r50 declined');
        $oid = self::orderOf($paymentId);
        $cap = $GLOBALS['captured'][$oid] ?? null;
        $pend = array_sum(array_map(fn($p) => $p[2] === $oid ? $p[1] : 0, self::$pending));
        if ($cap !== null && (self::$refunded['o' . $oid] ?? 0) + $pend + $amount > $cap) { self::$overAsk[] = [$oid, $amount]; throw new PaymentRefundDeclined('r50: amount exceeds the charge'); }
        $r = ['refund_id' => 're_r50_' . bin2hex(random_bytes(4)), 'status' => self::$refundMode, 'amount' => $amount];
        if (self::$refundMode === 'succeeded') self::$refunded['o' . $oid] = (self::$refunded['o' . $oid] ?? 0) + $amount;
        if (self::$refundMode === 'pending') self::$pending[$r['refund_id']] = [$paymentId, $amount, $oid];
        self::$calls[] = [$paymentId, $amount, $idempotencyKey];
        return self::$refundsByKey[$idempotencyKey] = $r;
    }
}

function r50_boot(string $driver, string $target, bool $setup): PDO {
    $db = r36_boot($driver, $target, $setup);
    $jd = getenv('R50_JOBDIR') ?: R36_DIR . '/tmp/jobs-' . getmypid();
    @mkdir($jd, 0700, true);
    JobQueue::setDir($jd);
    $GLOBALS['r50_jobdir'] = $jd;
    foreach (['default', 'cz'] as $s) { r36_store($s); Adapters::set('payment.stripe', new R50Gateway()); }
    r36_store('default');
    return $db;
}

function r50_set_pricing(): void {
    Pricing::setConfig([
        'customer_groups' => ['guest' => ['name' => 'Guest', 'discount' => 0]],
        'catalog_rules' => [],
        'cart_rules' => [
            ['name' => 'ONCE', 'type' => 'coupon', 'code' => 'ONCE', 'discount_type' => 'fixed', 'discount_value' => 700, 'max_uses' => 1, 'active' => true],
            ['name' => 'PCT15', 'type' => 'coupon', 'code' => 'PCT15', 'discount_type' => 'percent', 'discount_value' => 15, 'max_uses' => 0, 'per_customer' => 0, 'active' => true],
            ['name' => 'SHIPFREE', 'type' => 'coupon', 'code' => 'SHIPFREE', 'discount_type' => 'free_shipping', 'max_uses' => 3, 'active' => true],
            ['name' => 'BIG', 'type' => 'coupon', 'code' => 'BIG', 'discount_type' => 'fixed', 'discount_value' => 99999, 'active' => true],
        ],
    ]);
}

/** Product with ledgered stock in the given warehouses: [warehouseId => qty]. */
function r50_product(PDO $db, string $store, string $sku, int $price, string $cur, array $stock, string $type = 'simple', string $policy = 'deny', string $vat = 'standard'): int {
    $cat = $db->prepare('SELECT id FROM categories WHERE store_id = ? LIMIT 1'); $cat->execute([$store]); $catId = $cat->fetchColumn();
    if (!$catId) { $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute([$store, 'c-' . $store, 'Cat', 'c-' . $store]); $catId = $db->lastInsertId(); }
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, currency, stock, status, weight_g, product_type, vat_class, inventory_policy) VALUES (?,?,?,?,?,?,?,0,?,?,?,?,?)')
        ->execute([$store, $catId, $sku, strtolower($sku), 'P ' . $sku, $price, $cur, 'active', $type === 'digital' ? 0 : 400, $type, $vat, $policy]);
    $id = (int)$db->lastInsertId();
    foreach ($stock as $wh => $q) Warehouse::setStock((int)$wh, $sku, $q, 'count', 'r50', 'open-' . $sku . '-' . $wh);
    Warehouse::syncProductStock([$sku]);
    if ($type === 'digital') $db->prepare("INSERT INTO digital_assets (sku, storage_path, download_name) VALUES (?, ?, ?)")->execute([$sku, 'r50/' . $sku . '.pdf', $sku . '.pdf']);
    return $id;
}

function r50_drain(array &$log, int $max = 200, bool $force = false): int {
    $n = 0;
    if ($force) foreach (glob($GLOBALS['r50_jobdir'] . '/pending/*.json') ?: [] as $f) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j) && isset($j['retry_after'])) { unset($j['retry_after']); file_put_contents($f, json_encode($j)); } }
    $handlers = [
        'payment_refund' => [WorkerJobs::class, 'handlePaymentRefund'],
        'payment_cancel' => [WorkerJobs::class, 'handlePaymentCancel'],
        'refund_credit_note' => static fn(array $p) => InvoiceService::creditBookedRefund((int)($p['order_id'] ?? 0), (string)($p['refund_key'] ?? ''), (int)($p['deferrals'] ?? 0), (int)($p['waits'] ?? 0)),
        'closure_credit_note' => static fn(array $p) => InvoiceService::creditClosure((int)($p['order_id'] ?? 0)),
        'invoice_issue' => [WorkerJobs::class, 'handleInvoiceIssue'],
        'digital_fulfill' => [WorkerJobs::class, 'handleDigitalFulfill'],
        'digital_revoke' => [WorkerJobs::class, 'handleDigitalRevoke'],
    ];
    $store = StoreContext::current()->id();
    for ($round = 0; $round < 20 && $n < $max; $round++) {
        $jobs = JobQueue::pull(50);
        $did = false;
        foreach ($jobs as $job) {
            if (!JobQueue::isReady($job)) continue;
            $proc = JobQueue::markProcessing($job);
            if ($proc === '') continue;
            $did = true; $n++;
            JobQueue::beginWork($proc);
            $type = $job['type'] ?? '';
            try {
                $payload = $job['payload'] ?? [];
                Bootstrap::initStoreContext('/', '', (string)($payload['_store_id'] ?? 'default'));
                VatService::reset();
                if (isset($handlers[$type])) $handlers[$type]($payload, $job);
                if (Db::get()->inTransaction()) throw new RuntimeException("job left tx open: $type");
                JobQueue::complete($proc);
            } catch (Throwable $e) {
                if (Db::get()->inTransaction()) Db::get()->rollBack();
                $log[] = "JOBFAIL $type " . json_encode($job['payload'] ?? []) . ': ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200)
                    . ($e instanceof PDOException ? ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ' < ' . implode(' < ', array_map(fn($f) => basename($f['file'] ?? '?') . ':' . ($f['line'] ?? '?'), array_slice($e->getTrace(), 0, 6))) : '');
                try { JobQueue::fail($proc, $job, 'r50'); } catch (Throwable $e2) {}
            } finally { JobQueue::endWork(); }
        }
        if (!$did) break;
    }
    Bootstrap::initStoreContext('/', '', $store); VatService::reset();
    return $n;
}
