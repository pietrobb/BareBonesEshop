<?php
$pdo ??= test_commerce_fixture();

// Review round 16 (R44, low 5): issuing an invoice queues the credit notes of the refunds booked before it
// (queueCreditsOfBookedRefunds(), R41) after its commit. When that JobQueue::push failed, the invoice stayed committed,
// the retry of issue() found it and returned it at once, and the refund never got its credit note; nothing reported it.
// Now every issue() of an invoiced order queues the missing ones not queued already, setup doctor reports refunds
// left without one, and cli/invoices-backfill.php queues them again.
echo "\n--- R44 credit notes: a failed queue push after the invoice's commit loses no credit note ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r44cJobsDir = test_fixture_path('r44c-jobs');
$r44cBrokenDir = test_fixture_path('r44c-broken-jobs');
$r44cCart = $_SESSION['cart'] ?? null;
$r44cOrder = 0;
try {
    if (!is_dir($r44cBrokenDir)) mkdir($r44cBrokenDir, 0700, true);
    file_put_contents($r44cBrokenDir . '/pending', 'not a directory'); // every push into this queue fails
    $pdo->exec("DELETE FROM products WHERE sku = 'R44C-EBOOK'");
    $pdo->exec("DELETE FROM digital_assets WHERE sku = 'R44C-EBOOK'");
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99441, 'default', 1, 'R44C e-book', 'r44c-ebook', 'R44C-EBOOK', 'downloadable', 3000, 'eur', 'active', 0, 'continue')");
    $pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
        VALUES ('R44C-EBOOK', 'pdf', 'PDF', 'r44c.pdf', 'r44c.pdf', 'application/pdf', 2, 3600, 1)");
    $_SESSION['cart'] = [];
    $r44cPlaced = ManualOrder::create(ManualOrder::parseLines('R44C-EBOOK 1')['lines'], ['name' => 'R44C Kupec', 'email' => 'r44c@example.com',
        'country' => 'SK', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'digital', 'payment_method' => 'bank_transfer', 'locale' => 'sk'], false);
    $r44cOrder = (int)($r44cPlaced['order_id'] ?? 0);
    $r44cNumber = (string)(Orders::byId($r44cOrder)['order_number'] ?? '');
    // Refunded in part while still pending (no invoice to correct yet), then captured: paid, the invoice is due.
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response, created_at)
        VALUES (?, 'default', 'stripe', 're_r44c_part', 'partially_refunded', 500, 'eur', 'stripe_refund_re_r44c_part', '{}', '2026-01-02 03:04:05')")->execute([$r44cOrder]);
    $pdo->prepare("UPDATE orders SET payment_status = 'partially_refunded' WHERE id = ?")->execute([$r44cOrder]);
    PaymentService::settleLateCapture($r44cOrder, 'R44C capture');
    $r44cCreditJobs = static function () use ($r44cJobsDir, &$r44cOrder): array {
        $jobs = [];
        foreach (glob($r44cJobsDir . '/pending/*.json') ?: [] as $file) {
            $job = json_decode((string)file_get_contents($file), true) ?: [];
            if (($job['type'] ?? '') === 'refund_credit_note' && (int)($job['payload']['order_id'] ?? 0) === $r44cOrder) $jobs[] = (string)($job['payload']['refund_key'] ?? '');
        }
        return $jobs;
    };
    $r44cDoctor = static fn(?int $now = null): array => method_exists('Setup', 'refundCreditNotesCheck') ? Setup::refundCreditNotesCheck($pdo, $now) : ['status' => 'missing', 'message' => ''];

    JobQueue::setDir($r44cBrokenDir);
    $r44cFailure = null;
    try {
        InvoiceService::issue($r44cOrder);
    } catch (Throwable $r44cE) {
        $r44cFailure = $r44cE->getMessage();
    }
    JobQueue::setDir($r44cJobsDir);
    $r44cInvoice = InvoiceService::byOrderId($r44cOrder);
    assert_true($r44cFailure !== null && is_array($r44cInvoice) && (string)$r44cInvoice['type'] === 'invoice' && $r44cCreditJobs() === [],
        'R44 credit queue: the push of the credit note job fails after the invoice is committed (setup: ' . json_encode([$r44cFailure, $r44cInvoice['number'] ?? null]) . ')');

    $r44cReported = $r44cDoctor();
    assert_true($r44cReported['status'] === 'warn' && str_contains($r44cReported['message'], $r44cNumber),
        'R44 doctor: a refund booked on an invoiced order two days ago without its credit note is reported (was: no check) (got ' . json_encode($r44cReported) . ')');
    assert_true(!str_contains($r44cDoctor((int)strtotime('2026-01-03 00:00:00 UTC'))['message'], $r44cNumber),
        'R44 doctor: a refund booked less than two days ago (its job may still be retrying) is not reported yet');

    InvoiceService::issue($r44cOrder);
    assert_equals(['stripe_refund_re_r44c_part'], $r44cCreditJobs(),
        'R44 credit queue: the retry of issue() (worker job, admin button) queues the lost credit note job (was: returned the invoice, none queued)');
    InvoiceService::issue($r44cOrder);
    assert_equals(1, count($r44cCreditJobs()), 'R44 credit queue: issuing again while that job is open queues no second one');
    $r44cCredit = InvoiceService::creditBookedRefund($r44cOrder, 'stripe_refund_re_r44c_part');
    assert_true(is_array($r44cCredit) && (int)$r44cCredit['total_gross'] === -500 && (string)$r44cCredit['type'] === 'credit_note',
        'R44 credit queue: that job issues the credit note of the 5.00 refunded (got ' . json_encode(is_array($r44cCredit) ? [$r44cCredit['type'], $r44cCredit['total_gross']] : null) . ')');
    assert_true(!str_contains($r44cDoctor()['message'], $r44cNumber) && InvoiceService::uncreditedBookedRefunds($pdo, $r44cOrder) === [],
        'R44 doctor: once credited the refund is no longer reported');
} catch (Throwable $r44cE) {
    assert_true(false, 'R44 credit queue (' . get_class($r44cE) . ': ' . $r44cE->getMessage() . ' @ ' . basename($r44cE->getFile()) . ':' . $r44cE->getLine() . ')');
} finally {
    $_SESSION['cart'] = $r44cCart;
}
