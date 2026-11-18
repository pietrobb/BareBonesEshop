<?php
$pdo ??= test_commerce_fixture();

// Review round 17 (R47, low 5): R44 made every issue() of an invoiced order queue the credit notes of its booked
// refunds still missing. It listed the whole open refund_credit_note queue first (a file scan or a query over every
// job) and only then looked for refunds, so every invoice e-mail, download or worker retry paid for it although almost
// no order has a refund. The refunds are looked up first; the queue is read only when one lacks its credit note.
echo "\n--- R47 credit notes: issue() reads the job queue only when a refund lacks its credit note ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r47qCart = $_SESSION['cart'] ?? null;
$r47qQueue = new class($pdo) extends DatabaseQueue {
    public int $scans = 0;
    public function openPayloads(string $type, string $store): array {
        $this->scans++;
        return parent::openPayloads($type, $store);
    }
};
JobQueue::useBackend($r47qQueue);
try {
    $pdo->exec("DELETE FROM products WHERE sku = 'R47Q-EBOOK'");
    $pdo->exec("DELETE FROM digital_assets WHERE sku = 'R47Q-EBOOK'");
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99471, 'default', 1, 'R47Q e-book', 'r47q-ebook', 'R47Q-EBOOK', 'downloadable', 3000, 'eur', 'active', 0, 'continue')");
    $pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
        VALUES ('R47Q-EBOOK', 'pdf', 'PDF', 'r47q.pdf', 'r47q.pdf', 'application/pdf', 2, 3600, 1)");
    $r47qPlace = static function (string $email): int {
        $_SESSION['cart'] = [];
        $placed = ManualOrder::create(ManualOrder::parseLines('R47Q-EBOOK 1')['lines'], ['name' => 'R47Q Kupec', 'email' => $email,
            'country' => 'SK', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'digital', 'payment_method' => 'bank_transfer', 'locale' => 'sk'], false);
        return (int)($placed['order_id'] ?? 0);
    };

    // An order without refunds: issuing and re-issuing its invoice never lists the queue.
    $r47qPlain = $r47qPlace('r47q-plain@example.com');
    PaymentService::settleCapture($r47qPlain, 'R47Q capture');
    InvoiceService::issue($r47qPlain);
    $r47qQueue->scans = 0;
    for ($i = 0; $i < 3; $i++) InvoiceService::issue($r47qPlain);
    assert_equals(0, $r47qQueue->scans, 'R47 credit queue: re-issuing the invoice of an order without refunds reads no job queue (was: one full scan per call)');

    // An order with a refund booked before its invoice still gets exactly one credit note job.
    $r47qRefunded = $r47qPlace('r47q-refund@example.com');
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response, created_at)
        VALUES (?, 'default', 'stripe', 're_r47q_part', 'partially_refunded', 500, 'eur', 'stripe_refund_re_r47q_part', '{}', '2026-01-02 03:04:05')")->execute([$r47qRefunded]);
    $pdo->prepare("UPDATE orders SET payment_status = 'partially_refunded' WHERE id = ?")->execute([$r47qRefunded]);
    PaymentService::settleLateCapture($r47qRefunded, 'R47Q capture');
    $r47qQueue->scans = 0;
    InvoiceService::issue($r47qRefunded);
    InvoiceService::issue($r47qRefunded);
    $r47qJobs = array_values(array_filter($r47qQueue->openPayloads('refund_credit_note', 'default'),
        static fn(array $p): bool => (int)($p['order_id'] ?? 0) === $r47qRefunded));
    assert_true($r47qQueue->scans >= 2 && count($r47qJobs) === 1 && ($r47qJobs[0]['refund_key'] ?? '') === 'stripe_refund_re_r47q_part',
        'R47 credit queue: a refund without its credit note is still queued once (got ' . json_encode([$r47qQueue->scans, $r47qJobs]) . ')');
} catch (Throwable $r47qE) {
    assert_true(false, 'R47 credit queue (' . get_class($r47qE) . ': ' . $r47qE->getMessage() . ' @ ' . basename($r47qE->getFile()) . ':' . $r47qE->getLine() . ')');
} finally {
    JobQueue::resetBackend();
    $_SESSION['cart'] = $r47qCart;
}
