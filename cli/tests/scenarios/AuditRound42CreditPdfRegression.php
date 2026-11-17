<?php

// Review round 16 (R42, low 3): InvoiceService::issue() committed the invoice, then failed writing its PDF and never
// queued the credit notes of the refunds booked before it (R41 low 4); a retry found the invoice and returned before
// that step, so the refund stayed without a credit note. They are queued right after the commit now.
echo "\n--- R42 credit note: queued after the invoice commit even when its PDF cannot be written ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r42cJobsDir = test_fixture_path('r42c-jobs');
JobQueue::setDir($r42cJobsDir);
$r42cCart = $_SESSION['cart'] ?? null;
$r42cStorage = new ReflectionProperty(InvoiceService::class, 'storageDir');
$r42cStorage->setAccessible(true);
$r42cSavedStorage = $r42cStorage->getValue();
$r42cBlocker = test_fixture_path('r42c-storage-is-a-file');
try {
    $pdo->exec("DELETE FROM products WHERE sku = 'R42C-EBOOK'");
    $pdo->exec("DELETE FROM digital_assets WHERE sku = 'R42C-EBOOK'");
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99421, 'default', 1, 'R42C e-book', 'r42c-ebook', 'R42C-EBOOK', 'downloadable', 3000, 'eur', 'active', 0, 'continue')");
    $pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
        VALUES ('R42C-EBOOK', 'pdf', 'PDF', 'r42c.pdf', 'r42c.pdf', 'application/pdf', 2, 3600, 1)");
    $_SESSION['cart'] = [];
    $r42cPlaced = ManualOrder::create(ManualOrder::parseLines('R42C-EBOOK 1')['lines'], ['name' => 'R42C Kupec', 'email' => 'r42c@example.com',
        'country' => 'SK', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'digital', 'payment_method' => 'bank_transfer', 'locale' => 'sk'], false);
    $r42cOrder = (int)($r42cPlaced['order_id'] ?? 0);
    assert_true($r42cOrder > 0, 'R42 credit note: a pending order is placed (' . json_encode($r42cPlaced) . ')');
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
        VALUES (?, 'default', 'stripe', 're_r42c_part', 'partially_refunded', 500, 'eur', 'stripe_refund_re_r42c_part', '{}')")->execute([$r42cOrder]);
    $pdo->prepare("UPDATE orders SET payment_status = 'partially_refunded' WHERE id = ?")->execute([$r42cOrder]);
    PaymentService::settleLateCapture($r42cOrder, 'R42C capture');
    assert_equals('paid', (string)Orders::byId($r42cOrder)['status'], 'R42 credit note: captured after a partial refund, the order is paid (control)');

    $r42cCreditJobs = static function () use ($r42cJobsDir, $r42cOrder): array {
        $jobs = [];
        foreach (glob($r42cJobsDir . '/pending/*.json') ?: [] as $file) {
            $job = json_decode((string)file_get_contents($file), true) ?: [];
            if (($job['type'] ?? '') === 'refund_credit_note' && (int)($job['payload']['order_id'] ?? 0) === $r42cOrder) $jobs[] = (string)($job['payload']['refund_key'] ?? '');
        }
        return $jobs;
    };
    // invoice_storage_dir is a regular file: the PDF of the committed invoice cannot be written.
    file_put_contents($r42cBlocker, 'not a directory');
    $r42cStorage->setValue(null, $r42cBlocker);
    $r42cFailed = null;
    try {
        InvoiceService::issue($r42cOrder);
    } catch (Throwable $r42cErr) {
        $r42cFailed = $r42cErr->getMessage();
    }
    assert_true($r42cFailed !== null, 'R42 credit note: the PDF write fails (storage is a file) and issue() reports it');
    $r42cInvoice = InvoiceService::byOrderId($r42cOrder);
    assert_true(is_array($r42cInvoice) && (string)$r42cInvoice['type'] === 'invoice', 'R42 credit note: the invoice itself was committed before the PDF failed');
    assert_equals(['stripe_refund_re_r42c_part'], $r42cCreditJobs(), 'R42 credit note: the credit note job of the refund booked before the invoice is queued although the PDF failed (was: never queued)');

    $r42cStorage->setValue(null, $r42cSavedStorage);
    InvoiceService::issue($r42cOrder);
    assert_equals(1, count($r42cCreditJobs()), 'R42 credit note: issuing again returns the existing invoice and queues nothing more');
    $r42cCredit = InvoiceService::creditBookedRefund($r42cOrder, 'stripe_refund_re_r42c_part');
    assert_true(is_array($r42cCredit) && (int)$r42cCredit['total_gross'] === -500 && (string)$r42cCredit['type'] === 'credit_note',
        'R42 credit note: the queued job issues the credit note of the 5.00 refunded');
} catch (Throwable $r42cE) {
    assert_true(false, 'R42 credit note (' . get_class($r42cE) . ': ' . $r42cE->getMessage() . ' @ ' . basename($r42cE->getFile()) . ':' . $r42cE->getLine() . ')');
} finally {
    $r42cStorage->setValue(null, $r42cSavedStorage);
    if (is_file($r42cBlocker)) @unlink($r42cBlocker);
    $_SESSION['cart'] = $r42cCart;
}
