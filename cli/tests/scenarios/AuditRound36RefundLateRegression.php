<?php

// Review round 36 (2026-11-13), medium 4 (goal #457): the admin released a stalled item refund (credit note reversed) and
// refunded the return again; then Stripe's late charge.refunded paid out the released one. R35 covered its units, but the
// admin's second refund stayed queued and the worker sent it to Stripe: a multi-item return refunded twice, and the only
// signal was a critical incident without the order. R36: that second refund is stopped (failed, credit note reversed, its
// job pays nothing); one Stripe already holds cannot be stopped and is named on the order. Incidents link their order.
echo "\n--- R36: a released refund paid out late stops the admin's second refund of the same items ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r36rJobsDir = test_fixture_path('r36r-jobs');
JobQueue::setDir($r36rJobsDir);
$r36rDropQueue = static function () use ($r36rJobsDir): void {
    foreach (['pending', 'processing'] as $sub) foreach (glob($r36rJobsDir . '/' . $sub . '/*.json') ?: [] as $file) @unlink($file);
};
$r36rRunQueue = static function () use ($r36rJobsDir, $r36rDropQueue): int {
    $ran = 0;
    foreach (glob($r36rJobsDir . '/pending/*.json') ?: [] as $file) {
        $job = json_decode((string)file_get_contents($file), true);
        if (is_array($job) && ($job['type'] ?? '') === 'payment_refund') { WorkerJobs::handlePaymentRefund((array)($job['payload'] ?? []), []); $ran++; }
    }
    $r36rDropQueue();
    return $ran;
};
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R36rFakeStripe', false)) {
    class R36rFakeStripe extends StripePayment {
        public static int $refundCalls = 0;
        public function __construct() { parent::__construct([]); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            self::$refundCalls++;
            return ['refund_id' => 're_r36r_' . substr(sha1($idempotencyKey), 0, 10), 'status' => 'succeeded'];
        }
        public function refundByKey(string $paymentId, string $idempotencyKey): ?array { return ['status' => 'none']; }
    }
}
$r36rAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r36rSavedAdapters = $r36rAdapters->getValue();
$r36rAdapters->setValue(null, ['payment' => new R36rFakeStripe()] + $r36rSavedAdapters);
$r36rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r36rOrders = [];
$r36rOrder = static function (string $number) use ($pdo, $r36rProduct, &$r36rOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['product_id' => 1, 'variant_id' => null, 'price' => 1990, 'qty' => 2, 'total' => 3980, 'available' => true, 'currency_mismatch' => false,
        'product_type' => 'simple', 'vat_class' => 'standard', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $skus = ["{$number}-A", "{$number}-B"];
    $items = Cart::applyVat(['items' => [['sku' => $skus[0]] + $line, ['sku' => $skus[1], 'vat_class' => 'books'] + $line], 'subtotal' => 7960, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R36R', 'r36r@example.test', '', '{\"country\":\"SK\"}', 7960, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$number, (int)$snapshot['totals']['gross'], 'pi_' . strtolower($number),
            json_encode(['provider' => 'stripe', 'service_type' => 'online_card']), json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $id = $r36rOrders[] = (int)$pdo->lastInsertId();
    foreach (['payment_transactions', 'order_refunds'] as $stale) $pdo->prepare("DELETE FROM {$stale} WHERE order_id = ?")->execute([$id]);
    foreach ($skus as $sku) {
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 2, 1990, 3980)')
            ->execute([$id, $r36rProduct, $sku, "R36R {$sku}"]);
    }
    InvoiceService::issue($id);
    return $id;
};
// A return of one unit of both order lines whose item refund job died: pending, offered for release.
$r36rItemRefund = static function (int $orderId, string $key) use ($pdo, $r36rDropQueue): array {
    $lines = [];
    foreach (Orders::items($orderId) as $item) $lines[(int)$item['id']] = 1;
    [, $rma] = Rma::create($orderId, null, "R36R return {$key}", $lines);
    Rma::setStatus((int)$rma, 'approved');
    $decisions = [];
    foreach (Rma::items((int)$rma) as $rmaItem) $decisions[(int)$rmaItem['id']] = ['quantity' => 1, 'disposition' => 'repair'];
    Rma::receive((int)$rma, $decisions, "r36r-receipt-{$key}");
    $invoiceId = (int)$pdo->query("SELECT id FROM invoices WHERE order_id = {$orderId} AND type = 'invoice'")->fetchColumn();
    $credit = InvoiceService::credit($invoiceId, (int)$rma, array_map(static fn(): int => 1, $decisions), $key);
    $r36rDropQueue();
    return ['id' => (int)$credit['refund_id'], 'amount' => -(int)$credit['total_gross'], 'key' => $key, 'rma' => (int)$rma, 'invoice' => $invoiceId];
};
// Released by the admin, then "Refunded" on the return again: a second credit note whose refund job waits in the queue.
$r36rReleaseAndRefundAgain = static function (int $orderId, array $refund) use ($pdo): int {
    PaymentService::releaseStalledItemRefund($orderId, $refund['id'], 'r36r-admin', 'R36R nothing at Stripe');
    $quantities = InvoiceService::creditableQuantities($refund['rma']);
    $credit = InvoiceService::credit($refund['invoice'], $refund['rma'], $quantities, 'admin-credit-' . $refund['rma'] . '-' . InvoiceService::creditAttempts($refund['rma']));
    return (int)$credit['refund_id'];
};
$r36rLate = static function (int $orderId, array $refund, string $refundId): void {
    PaymentService::bookProviderRefund($orderId, 'stripe', $refundId, $refund['amount'], 'charge.refunded', 'stripe_refund_' . $refundId, ['refund_key' => $refund['key']]);
    foreach (Db::get()->query("SELECT idempotency_key FROM payment_transactions WHERE order_id = {$orderId}
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded') AND amount > 0")->fetchAll(PDO::FETCH_COLUMN) as $key) {
        InvoiceService::creditBookedRefund($orderId, (string)$key);
    }
};
$r36rIncident = static fn(string $code, int $orderId): bool => IncidentMonitor::reportedForOrder($code, $orderId);
$r36rNotes = static fn(int $orderId): array => array_map(static fn($n): string => AuditLogLabels::note('order_refund', $orderId, (string)$n),
    $pdo->query("SELECT note FROM audit_log WHERE entity = 'order_refund' AND entity_id = {$orderId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

try {
    $r36rDropQueue();
    // 1. The second refund still waits in the queue: stopped; its job pays nothing; the order is refunded once.
    $o1 = $r36rOrder('R36R-1');
    $a1 = $r36rItemRefund($o1, 'r36r-1-item');
    $b1 = $r36rReleaseAndRefundAgain($o1, $a1);
    assert_equals('pending', (string)(Rma::refundById($b1)['status'] ?? ''), 'R36 refunds: the admin refunded the released return again (second item refund pending)');
    $r36rLate($o1, $a1, 're_r36r_late_1');
    R36rFakeStripe::$refundCalls = 0;
    $ran1 = $r36rRunQueue();
    assert_equals(['failed', 0, $a1['amount'], 'refunded'],
        [(string)(Rma::refundById($b1)['status'] ?? ''), R36rFakeStripe::$refundCalls, PaymentService::refundedAmount($o1), (string)Rma::byId($a1['rma'])['status']],
        "R36 refunds: Stripe paying out the released refund stops the second one; its queued job ({$ran1}) sends nothing (was: both items refunded twice)");
    assert_true($r36rIncident('payment.duplicate_refund_stopped', $o1), 'R36 refunds: the merchant is told, the incident names the order');
    $notes1 = implode(' | ', $r36rNotes($o1));
    assert_true(preg_match('/\b' . $b1 . '\b/', $notes1) === 1 && str_contains($notes1, 're_r36r_late_1') && !str_contains($notes1, 'Item refund '),
        "R36 refunds: the order history says which refund was stopped and why, translated ({$notes1})");
    $creditable1 = InvoiceService::creditableQuantities($a1['rma']);
    assert_equals([], $creditable1, 'R36 refunds: the return offers nothing more to credit');

    // 2. The second refund was already sent to Stripe (async, pending): it cannot be stopped; the order says so.
    $o2 = $r36rOrder('R36R-2');
    $a2 = $r36rItemRefund($o2, 'r36r-2-item');
    $b2 = $r36rReleaseAndRefundAgain($o2, $a2);
    Rma::noteProviderRefund($b2, 're_r36r_sent_2');
    $r36rDropQueue();
    $r36rLate($o2, $a2, 're_r36r_late_2');
    $notes2 = implode(' | ', $r36rNotes($o2));
    assert_true((string)(Rma::refundById($b2)['status'] ?? '') === 'pending' && $r36rIncident('payment.duplicate_refund_sent', $o2)
        && preg_match('/\b' . $b2 . '\b/', $notes2) === 1 && str_contains($notes2, 're_r36r_late_2') && !str_contains($notes2, 'Item refund '),
        "R36 refunds: a second refund Stripe already holds is named on the order and reported with it ({$notes2})");

    // 3. A pending refund of other units of the order is left alone.
    $o3 = $r36rOrder('R36R-3');
    $a3 = $r36rItemRefund($o3, 'r36r-3-item');
    PaymentService::releaseStalledItemRefund($o3, $a3['id'], 'r36r-admin', 'R36R nothing at Stripe');
    $lines3 = [];
    foreach (Orders::items($o3) as $item) $lines3[(int)$item['id']] = 1;
    [, $rma3] = Rma::create($o3, null, 'R36R other units', $lines3);
    Rma::setStatus((int)$rma3, 'approved');
    $decisions3 = [];
    foreach (Rma::items((int)$rma3) as $rmaItem) $decisions3[(int)$rmaItem['id']] = ['quantity' => 1, 'disposition' => 'repair'];
    Rma::receive((int)$rma3, $decisions3, 'r36r-receipt-3b');
    $other3 = (int)InvoiceService::credit($a3['invoice'], (int)$rma3, array_map(static fn(): int => 1, $decisions3), 'r36r-3-other')['refund_id'];
    $r36rDropQueue();
    $r36rLate($o3, $a3, 're_r36r_late_3');
    assert_equals('pending', (string)(Rma::refundById($other3)['status'] ?? ''), 'R36 refunds: a refund of the other returned units is not stopped');

    // Operations lists an incident with a link to its order.
    $r36rOps = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php');
    assert_true(str_contains($r36rOps, "json_decode((string)(\$incident['context_json'] ?? ''), true)['order_id']") && str_contains($r36rOps, 'admin.php?p=order&id=<?= $incidentOrder ?>'),
        'R36 refunds: the incident list links an incident to its order');
} catch (Throwable $e) {
    assert_true(false, 'R36 refund late (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r36rAdapters->setValue(null, $r36rSavedAdapters);
    $r36rDropQueue();
    foreach ($r36rOrders as $r36rCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'return_receipts' => 'rma_item_id IN (SELECT id FROM rma_items WHERE rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?))',
            'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r36rTable => $r36rWhere) {
            try { $pdo->prepare("DELETE FROM {$r36rTable} WHERE {$r36rWhere}")->execute([$r36rCleanup]); } catch (Throwable $e) {}
        }
    }
}
