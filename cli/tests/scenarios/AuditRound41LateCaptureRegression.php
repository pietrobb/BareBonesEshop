<?php

// Review round 15 (R41, low 4 and 5), the capture of a payment after its refund (PaymentService::settleLateCapture(), R40):
// 4. Refunded in part while the order was still pending (no invoice), then captured: the order was paid and invoiced in
//    full, but the refund's credit note job had ended on "no invoice to correct": 30.00 invoiced, 5.00 refunded, no credit
//    note. Issuing the invoice now queues the credit notes of the refunds booked before it.
// 5. An order fulfilled already whose older data has no 'paid' ledger row was cancelled (its stock put back) by such a
//    capture; only an order still waiting for its payment is settled now, any other is only logged.
echo "\n--- R41 late capture: credit note of a refund booked before the invoice, fulfilled orders left alone ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r41lJobsDir = test_fixture_path('r41l-jobs');
JobQueue::setDir($r41lJobsDir);
$r41lOrder = 0;
$r41lOrders = [];
$r41lCart = $_SESSION['cart'] ?? null;
try {
    // 4. Refunded in part before the invoice existed.
    $pdo->exec("DELETE FROM products WHERE sku = 'R41L-EBOOK'");
    $pdo->exec("DELETE FROM digital_assets WHERE sku = 'R41L-EBOOK'");
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99411, 'default', 1, 'R41L e-book', 'r41l-ebook', 'R41L-EBOOK', 'downloadable', 3000, 'eur', 'active', 0, 'continue')");
    $pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
        VALUES ('R41L-EBOOK', 'pdf', 'PDF', 'r41l.pdf', 'r41l.pdf', 'application/pdf', 2, 3600, 1)");
    $_SESSION['cart'] = [];
    $r41lPlaced = ManualOrder::create(ManualOrder::parseLines('R41L-EBOOK 1')['lines'], ['name' => 'R41L Kupec', 'email' => 'r41l@example.com',
        'country' => 'SK', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'digital', 'payment_method' => 'bank_transfer', 'locale' => 'sk'], false);
    $r41lOrders[] = $r41lOrder = (int)($r41lPlaced['order_id'] ?? 0);
    assert_true($r41lOrder > 0 && (string)(Orders::byId($r41lOrder)['status'] ?? '') === 'pending', 'R41 late capture: a pending order is placed (' . json_encode($r41lPlaced) . ')');
    $r41lTotal = (int)Orders::byId($r41lOrder)['total'];
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
        VALUES (?, 'default', 'stripe', 're_r41l_part', 'partially_refunded', 500, 'eur', 'stripe_refund_re_r41l_part', '{}')")->execute([$r41lOrder]);
    $pdo->prepare("UPDATE orders SET payment_status = 'partially_refunded' WHERE id = ?")->execute([$r41lOrder]);
    assert_equals(null, InvoiceService::creditBookedRefund($r41lOrder, 'stripe_refund_re_r41l_part'), 'R41 late capture: the refund\'s credit note job finds no invoice yet and ends');

    PaymentService::settleLateCapture($r41lOrder, 'R41L capture');
    assert_equals('paid', (string)Orders::byId($r41lOrder)['status'], 'R41 late capture: captured after a partial refund, the pending order is paid (R40, control)');
    // Issued the way the admin's "issue invoice" button and cli/invoices-backfill.php do (InvoiceService::issue() alone,
    // no invoice_issue job): capability review 533, the credit note job was queued by the worker job only.
    $r41lCreditJobs = static function () use ($r41lJobsDir, &$r41lOrder): array {
        $jobs = [];
        foreach (glob($r41lJobsDir . '/pending/*.json') ?: [] as $file) {
            $job = json_decode((string)file_get_contents($file), true) ?: [];
            if (($job['type'] ?? '') === 'refund_credit_note' && (int)($job['payload']['order_id'] ?? 0) === $r41lOrder) $jobs[] = (string)($job['payload']['refund_key'] ?? '');
        }
        return $jobs;
    };
    $r41lInvoice = InvoiceService::issue($r41lOrder);
    assert_true(is_array($r41lInvoice) && (int)$r41lInvoice['total_gross'] === $r41lTotal, 'R41 late capture: the invoice is for the whole order');
    assert_equals(['stripe_refund_re_r41l_part'], $r41lCreditJobs(), 'R41 late capture: issuing the invoice (admin button, backfill, worker alike) queues the credit note job of the refund booked before it');
    InvoiceService::issue($r41lOrder);
    assert_equals(1, count($r41lCreditJobs()), 'R41 late capture: issuing again returns the existing invoice and queues nothing more');
    foreach ($r41lCreditJobs() as $r41lKey) $r41lCredit = InvoiceService::creditBookedRefund($r41lOrder, $r41lKey);
    assert_true(is_array($r41lCredit ?? null) && (int)$r41lCredit['total_gross'] === -500 && (string)$r41lCredit['type'] === 'credit_note',
        'R41 late capture: that job issues the credit note of the 5.00 refunded (was: 30.00 invoiced, no credit note) (got ' . json_encode(is_array($r41lCredit ?? null) ? [$r41lCredit['type'], $r41lCredit['total_gross']] : null) . ')');
    assert_equals(0, InvoiceService::queueCreditsOfBookedRefunds($r41lOrder), 'R41 late capture: a refund credited already is not queued again');

    // 5. Fulfilled orders without a 'paid' ledger row (older data) are left as they are.
    foreach (['processing', 'shipped', 'paid'] as $r41lStatus) {
        $pdo->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id)
            VALUES (?, ?, 'refunded', 'R41L', 'r41l@example.test', 'card', ?, 1000, 1000, 'eur', 'default')")->execute(['R41L-' . strtoupper($r41lStatus), $r41lStatus, 'pi_r41l_' . $r41lStatus]);
        $r41lOrders[] = $r41lLegacy = (int)$pdo->lastInsertId();
        PaymentService::settleLateCapture($r41lLegacy, 'R41L capture');
        $r41lAfter = Orders::byId($r41lLegacy);
        assert_equals([$r41lStatus, 'refunded'], [(string)$r41lAfter['status'], (string)$r41lAfter['payment_status']],
            "R41 late capture: an order {$r41lStatus} already (no 'paid' ledger row, refunded) is not cancelled by a late capture, only logged");
    }
    $pdo->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id)
        VALUES ('R41L-PENDING', 'pending', 'refunded', 'R41L', 'r41l@example.test', 'card', 'pi_r41l_pending', 1000, 1000, 'eur', 'default')")->execute();
    $r41lOrders[] = $r41lPending = (int)$pdo->lastInsertId();
    PaymentService::settleLateCapture($r41lPending, 'R41L capture');
    assert_equals('cancelled', (string)Orders::byId($r41lPending)['status'], 'R41 late capture: a pending order refunded in full is still cancelled (R40, control)');
} catch (Throwable $r41lE) {
    assert_true(false, 'R41 late capture (' . get_class($r41lE) . ': ' . $r41lE->getMessage() . ' @ ' . basename($r41lE->getFile()) . ':' . $r41lE->getLine() . ')');
} finally {
    $_SESSION['cart'] = $r41lCart;
}
