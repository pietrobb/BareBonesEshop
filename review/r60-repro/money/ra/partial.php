<?php
// Partial coverage: 1 of 2 units returned and already refunded (Stripe dashboard 10 EUR, credited by amount). Admin marks the return refunded.
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql' ? new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'bbe_ci', 'bbe-ci-pass', [PDO::ATTR_EMULATE_PREPARES => false]) : new ShopPDO('sqlite:' . $dir . '/p.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') { $db->exec("DROP DATABASE IF EXISTS bbe_r52_money_ra_partial"); $db->exec("CREATE DATABASE bbe_r51_money_ra_partial CHARACTER SET utf8mb4"); $db->exec("USE bbe_r51_money_ra_partial"); } elseif (is_file($dir . '/p.sqlite')) { unlink($dir . '/p.sqlite'); $db = new ShopPDO('sqlite:' . $dir . '/p.sqlite'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); }
Db::init($db, $driver);
(new Migrator($db, $root . '/migrations', $dir . '/m.lock'))->apply();
Bootstrap::initStoreContext('/', '', 'default');
class PStripe extends StripePayment { public static array $calls = []; public function __construct() { parent::__construct([]); }
  public function refundWithIdempotency(string $paymentId, int $amount, string $key): array { self::$calls[] = [$amount, $key]; return ['status' => 'succeeded', 'id' => 're_p_' . count(self::$calls), 'amount' => $amount]; }
  public function refundByKey(string $paymentId, string $idempotencyKey): ?array { return ['status' => 'none']; } }
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new PStripe()]);
JobQueue::setDir($dir . '/jobs-' . $driver);
foreach (glob($dir . '/jobs-' . $driver . '/*/*.json') ?: [] as $f) unlink($f);
$db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'p', 'P', 'p', 0)");
$cat = (int)$db->lastInsertId();
$db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'P1', 'p1', 'P1', 1000, 5, 'active')")->execute([$cat]);
$product = (int)$db->lastInsertId();
$snapshot = VatService::buildSnapshot([['product_id' => $product, 'sku' => 'P1', 'qty' => 2, 'price' => 1000, 'product_type' => 'simple']], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
$db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
    VALUES ('P-1', 'P', 'p@example.test', '{\"country\":\"SK\"}', 2000, 2000, 'eur', 'delivered', 'paid', 'stripe', 'pi_p', ?, 'pickup', 'domestic', ?, 'default')")->execute([json_encode(['provider' => 'stripe', 'service_type' => 'online_card']), json_encode($snapshot)]);
$order = (int)$db->lastInsertId();
$db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'P1', 'P1', 2, 1000, 2000)")->execute([$order, $product]);
$item = (int)$db->lastInsertId(); $invoice = InvoiceService::issue($order);
[, $rma] = Rma::create($order, null, 'return', [$item => 1]); Rma::setStatus((int)$rma, 'approved');
$returned = (int)Rma::items((int)$rma)[0]['id'];
Rma::receive((int)$rma, [$returned => ['quantity' => 1, 'disposition' => 'restock']], 'p-receipt');
// money went out another way: a 10 EUR refund in the Stripe dashboard, booked by its webhook, credited by amount
PaymentService::bookProviderRefund($order, 'stripe', 're_dash', 1000, 'charge.refunded', 'stripe_refund_re_dash', []);
foreach ($db->query("SELECT idempotency_key FROM payment_transactions WHERE order_id = {$order} AND status IN ('partially_refunded','refunded','refund_succeeded') AND amount > 0")->fetchAll(PDO::FETCH_COLUMN) as $key) InvoiceService::creditBookedRefund($order, (string)$key);
echo "after dashboard refund: refunded ", PaymentService::refundedAmount($order), ", credit notes ", $db->query("SELECT COALESCE(SUM(-total_gross),0) FROM invoices WHERE order_id={$order} AND type='credit_note' AND status<>'cancelled'")->fetchColumn(), "\n";
// Admin: mark the return refunded (handleRmaStatus)
$settled = Rma::settleAlreadyRefunded((int)$rma);
echo "settleAlreadyRefunded: ", var_export($settled, true), "; creditable: ", json_encode(InvoiceService::creditableQuantities((int)$rma)), "\n";
try {
    $credit = InvoiceService::credit((int)$invoice['id'], (int)$rma, InvoiceService::creditableQuantities((int)$rma), 'admin-credit-' . $rma . '-' . InvoiceService::creditAttempts((int)$rma));
    echo "credit(): ", json_encode(array_intersect_key($credit, array_flip(['id', 'number', 'total_gross', 'refund_id']))), "\n";
    // run queued jobs
    foreach (glob($dir . '/jobs-' . $driver . '/pending/*.json') ?: [] as $f) { $job = json_decode(file_get_contents($f), true); echo "queued job: ", $job['type'], " ", json_encode($job['payload'] ?? $job['data'] ?? null), "\n"; }
    if (class_exists('WorkerJobs')) { $n = 0; while ($n++ < 10 && JobQueue::runOnce ?? false) {} }
} catch (Throwable $e) { echo "credit() refused: ", get_class($e), ": ", $e->getMessage(), "\n"; }
echo "rma status ", Rma::byId((int)$rma)['status'], "\n";
if ($driver === 'mysql') $db->exec("DROP DATABASE bbe_r52_money_ra_partial");
