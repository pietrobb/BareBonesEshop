<?php

// Review round 36 (2026-11-13), low "refunds" (goal #460): money refunded in the Stripe dashboard while an item refund of
// the order was in flight found the invoice credited already by that item refund's credit note (its money had not moved).
// Its refund_credit_note job failed for good ("The refund exceeds what the invoice still has uncredited"); the item refund
// was released later (credit note reversed), and the ledger kept the dashboard money without a credit note. R36: the job
// waits for the item refund in flight and issues the credit note once it settles. (The refund fuzzer found it: seed 3605.)
echo "\n--- R36: a refund booked while an item refund is in flight gets its credit note once that one settles ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r36lJobsDir = test_fixture_path('r36l-jobs');
JobQueue::setDir($r36lJobsDir);
$r36lJobs = static function (string $type) use ($r36lJobsDir): array {
    $jobs = [];
    foreach (glob($r36lJobsDir . '/pending/*.json') ?: [] as $file) {
        $job = json_decode((string)file_get_contents($file), true);
        if (is_array($job) && ($job['type'] ?? '') === $type) $jobs[] = (array)$job['payload'];
    }
    return $jobs;
};
$r36lDropQueue = static function () use ($r36lJobsDir): void {
    foreach (['pending', 'processing'] as $sub) foreach (glob($r36lJobsDir . '/' . $sub . '/*.json') ?: [] as $file) @unlink($file);
};
$r36lOrderId = 0;
try {
    $r36lDropQueue();
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = ['sku' => 'R36L-A', 'product_id' => 1, 'variant_id' => null, 'price' => 2000, 'qty' => 2, 'total' => 4000, 'available' => true, 'currency_mismatch' => false,
        'product_type' => 'simple', 'vat_class' => 'standard', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
    $items = Cart::applyVat(['items' => [$line], 'subtotal' => 4000, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES ('R36L-1', 'R36L', '', '', '{\"country\":\"SK\"}', 4000, 0, 0, 0, 4000, 'eur', 'delivered', 'paid', 'stripe', 'pi_r36l_1', ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([json_encode(['provider' => 'stripe', 'service_type' => 'online_card']), json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $r36lOrderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R36L-A', 'R36L A', 2, 2000, 4000)")
        ->execute([$r36lOrderId, (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn()]);
    InvoiceService::issue($r36lOrderId);
    $invoiceId = (int)$pdo->query("SELECT id FROM invoices WHERE order_id = {$r36lOrderId} AND type = 'invoice'")->fetchColumn();
    // Both units come back and are credited: the item refund of 40.00 waits for its job.
    [, $rma] = Rma::create($r36lOrderId, null, 'R36L return', [(int)Orders::items($r36lOrderId)[0]['id'] => 2]);
    Rma::setStatus((int)$rma, 'approved');
    $rmaItem = (int)Rma::items((int)$rma)[0]['id'];
    Rma::receive((int)$rma, [$rmaItem => ['quantity' => 2, 'disposition' => 'repair']], 'r36l-receipt');
    $itemRefund = (int)InvoiceService::credit($invoiceId, (int)$rma, [$rmaItem => 2], 'r36l-item')['refund_id'];
    $r36lDropQueue(); // its job died: the item refund stalls
    // 6.47 refunded in the Stripe dashboard meanwhile.
    PaymentService::bookProviderRefund($r36lOrderId, 'stripe', 're_r36l_dashboard', 647, 'charge.refunded', 'stripe_refund_re_r36l_dashboard');
    $ledgerKey = (string)$pdo->query("SELECT idempotency_key FROM payment_transactions WHERE order_id = {$r36lOrderId} AND provider_ref = 're_r36l_dashboard' AND amount > 0")->fetchColumn();
    $error = '';
    try { $first = InvoiceService::creditBookedRefund($r36lOrderId, $ledgerKey); } catch (Throwable $e) { $first = null; $error = $e->getMessage(); }
    $waiting = $r36lJobs('refund_credit_note');
    $waitingJob = array_filter($waiting, static fn(array $job): bool => (int)($job['waits'] ?? 0) === 1 && (string)($job['refund_key'] ?? '') === $ledgerKey);
    assert_true($error === '' && $first === null && count($waitingJob) === 1,
        "R36 refunds: with the item refund in flight the dashboard refund's credit note waits for it (was: \"{$error}\", the job failed for good)");
    // The admin releases the stalled item refund (its credit note is reversed); the waiting job then credits the 6.47.
    $released = Rma::failRefund($itemRefund, 'R36L released');
    $r36lDropQueue();
    $credit = InvoiceService::creditBookedRefund($r36lOrderId, $ledgerKey, 0, 1);
    $credits = (int)$pdo->query("SELECT COALESCE(SUM(-total_gross), 0) FROM invoices WHERE order_id = {$r36lOrderId} AND type = 'credit_note'")->fetchColumn();
    assert_equals([true, -647, 647, 647], [$released, (int)($credit['total_gross'] ?? 0), PaymentService::refundedAmount($r36lOrderId), $credits],
        'R36 refunds: once the item refund is released the dashboard refund gets its credit note; the ledger and the credit notes agree (was: 6.47 on the ledger without one)');
    // Without an item refund in flight a refund beyond the invoice is still refused (nothing to wait for).
    $refused = '';
    try { InvoiceService::creditRefund($r36lOrderId, 4000, 'r36l-too-much'); } catch (RefundExceedsUncreditedException $e) { $refused = $e->getMessage(); }
    assert_true($refused !== '' && $r36lJobs('refund_credit_note') === [], 'R36 refunds: a refund beyond the uncredited invoice with nothing in flight still fails (no endless wait)');
} catch (Throwable $e) {
    assert_true(false, 'R36 refunds low (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r36lDropQueue();
    if ($r36lOrderId > 0) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'return_receipts' => 'rma_item_id IN (SELECT id FROM rma_items WHERE rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?))',
            'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r36lTable => $r36lWhere) {
            try { $pdo->prepare("DELETE FROM {$r36lTable} WHERE {$r36lWhere}")->execute([$r36lOrderId]); } catch (Throwable $e) {}
        }
    }
}
