<?php
// R49 returns-vs-outside-refunds harness. Usage: require, then rlib_boot($root, $dir, $driver, $dbname).
class PStripe extends StripePayment { public static array $calls = []; public static int $n = 0; public function __construct() { parent::__construct([]); }
  public function refundWithIdempotency(string $paymentId, int $amount, string $key): array { self::$calls[] = [$amount, $key]; return ['status' => 'succeeded', 'id' => 're_p_' . (++self::$n), 'amount' => $amount]; }
  public function refundByKey(string $paymentId, string $idempotencyKey): ?array { return ['status' => 'none']; } }
function rlib_boot(string $root, string $dir, string $driver, string $dbname): PDO {
    if ($driver === 'mysql') {
        $db = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'bbe_ci', 'bbe-ci-pass', [PDO::ATTR_EMULATE_PREPARES => false]);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("DROP DATABASE IF EXISTS `{$dbname}`"); $db->exec("CREATE DATABASE `{$dbname}` CHARACTER SET utf8mb4"); $db->exec("USE `{$dbname}`");
    } else {
        $f = $dir . '/' . $dbname . '.sqlite'; foreach ([$f, "$f-wal", "$f-shm"] as $x) if (is_file($x)) unlink($x);
        $db = new ShopPDO('sqlite:' . $f); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    Db::init($db, $driver);
    (new Migrator($db, $root . '/migrations', $dir . '/m-' . getmypid() . '.lock'))->apply();
    Db::forgetSchema($db);
    Bootstrap::initStoreContext('/', '', 'default');
    (new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new PStripe()]);
    JobQueue::setDir($dir . '/jobs-' . $dbname);
    foreach (glob($dir . '/jobs-' . $dbname . '/*/*.json') ?: [] as $f) unlink($f);
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'p', 'P', 'p', 0)");
    return $db;
}
/** lines: [[price, qty, vat_class]]; returns [orderId, invoiceId, [itemIds]] */
function rlib_order(PDO $db, array $lines, string $currency = 'eur', string $pay = 'stripe'): array {
    static $n = 0; $n++;
    $cat = (int)$db->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
    $items = [];
    foreach ($lines as $i => [$price, $qty, $class]) {
        $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, vat_class) VALUES ('default', ?, ?, ?, ?, ?, 50, 'active', ?)")->execute([$cat, "P{$n}-{$i}", "p{$n}-{$i}", "P{$n}-{$i}", $price, $class]);
        $items[] = ['product_id' => (int)$db->lastInsertId(), 'sku' => "P{$n}-{$i}", 'qty' => $qty, 'price' => $price, 'total' => $price * $qty, 'product_type' => 'simple', 'vat_class' => $class, 'currency' => $currency, 'available' => true];
    }
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK'];
    $items = Cart::applyVat(['items' => $items, 'subtotal' => array_sum(array_column($items, 'total')), 'currency' => $currency], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => $currency, 'effective_date' => ShopTime::date('Y-m-d')]);
    $gross = (int)$snapshot['totals']['gross'];
    $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'P', 'p@example.test', '{\"country\":\"SK\"}', ?, ?, ?, 'delivered', 'paid', ?, ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute(["R49-{$n}", $gross, $gross, $currency, $pay, $pay === 'stripe' ? "pi_r49_{$n}" : '', json_encode($pay === 'stripe' ? ['provider' => 'stripe', 'service_type' => 'online_card'] : ['provider' => 'manual', 'service_type' => 'bank_transfer']), json_encode($snapshot)]);
    $order = (int)$db->lastInsertId();
    $ids = [];
    foreach ($items as $it) { $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$order, $it['product_id'], $it['sku'], $it['sku'], $it['qty'], $it['price'], $it['total']]); $ids[] = (int)$db->lastInsertId(); }
    $inv = InvoiceService::issue($order);
    return [$order, (int)$inv['id'], $ids, $gross];
}
function rlib_jobs(string $dir, string $dbname): array {
    $log = [];
    for ($round = 0; $round < 6; $round++) {
        $files = glob($dir . '/jobs-' . $dbname . '/pending/*.json') ?: [];
        if (!$files) break;
        foreach ($files as $file) {
            $job = json_decode((string)file_get_contents($file), true); @unlink($file);
            $p = (array)($job['payload'] ?? []);
            try {
                if (($job['type'] ?? '') === 'payment_refund') { WorkerJobs::handlePaymentRefund($p, ['attempts' => 1, 'max_attempts' => 3] + $job); $log[] = 'refund-job ' . ($p['amount'] ?? $p['refund_id'] ?? ''); }
                elseif (($job['type'] ?? '') === 'refund_credit_note') { $c = InvoiceService::creditBookedRefund((int)$p['order_id'], (string)$p['refund_key'], (int)($p['deferrals'] ?? 0), (int)($p['waits'] ?? 0)); $log[] = 'cn-job ' . ($c ? $c['total_gross'] : 'none'); }
            } catch (Throwable $e) { $log[] = 'JOB FAIL ' . ($job['type'] ?? '') . ': ' . $e->getMessage(); }
        }
    }
    return $log;
}
/** Return units [itemId => qty] and receive them. */
function rlib_return(int $order, array $units): int {
    [, $rma] = Rma::create($order, null, 'return', $units); Rma::setStatus((int)$rma, 'approved');
    $dec = []; foreach (Rma::items((int)$rma) as $ri) $dec[(int)$ri['id']] = ['quantity' => (int)$ri['quantity'], 'disposition' => 'restock'];
    Rma::receive((int)$rma, $dec, 'rcpt-' . $rma);
    return (int)$rma;
}
/** The admin's "Refunded" exactly as handleRmaStatus() (with the R49 deduct confirmation). */
function rlib_admin_refunded(int $id): string {
    try {
        if (Rma::settleAlreadyRefunded($id)) return 'settled';
        $rma = Rma::byId($id);
        if (Rma::refundInFlight((int)$rma['order_id'])) return 'REFUSED: refund in flight';
        $outside = Rma::refundedOutsideReturns((int)$rma['order_id']);
        $source = InvoiceService::byOrderId((int)$rma['order_id']);
        $q = InvoiceService::creditableQuantities($id);
        if (!$q) return 'nothing to credit';
        [$o2, $due] = Rma::owedAfterOutside($id, (int)$rma['order_id']);
        if ($outside > 0 && InvoiceService::rmaCreditOwed((int)$source['id'], $id, $q, $outside)[1] === 0) return Rma::closeWithoutPayout($id) ? "closed w/o payout (outside {$outside}, hint due {$due})" : 'close refused';
        $credit = InvoiceService::credit((int)$source['id'], $id, $q, 'admin-credit-' . $id . '-' . InvoiceService::creditAttempts($id), $outside > 0);
        if (trim((string)(Orders::byId((int)$rma['order_id'])['payment_id'] ?? '')) === '') Rma::completeRefund((int)$credit['refund_id'], 'manual-admin');
        return "credit " . (-(int)$credit['total_gross']) . " (outside {$outside}, hint due {$due})";
    } catch (Throwable $e) { return 'REFUSED: ' . $e->getMessage(); }
}
function rlib_dashboard(int $order, int $amount): void {
    static $k = 0; $k++;
    PaymentService::bookProviderRefund($order, 'stripe', 're_dash' . $k . '_' . $order, $amount, 'charge.refunded', 'stripe_refund_re_dash' . $k . '_' . $order, []);
}
function rlib_state(PDO $db, int $order): array {
    $cn = (int)$db->query("SELECT COALESCE(SUM(-total_gross),0) FROM invoices WHERE order_id={$order} AND type='credit_note' AND status<>'cancelled'")->fetchColumn();
    return ['refunded' => PaymentService::refundedAmount($order), 'credit_notes' => $cn, 'stripe_calls' => array_sum(array_column(PStripe::$calls, 0))];
}
