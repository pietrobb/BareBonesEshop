<?php

// Review round 16 (2026-11-04) "credit notes and refunds". Medium: a refund after a cancellation credit note (the
// invoice of a cancelled purchase on invoice fully credited, then the money came in after all and went back) failed
// for ever: its credit note "exceeds what the invoice still has uncredited", so the refund_credit_note job died with a
// critical incident. Medium: "Request refund" on a bank-transfer order (money returned by hand) claimed the refund and
// queued nothing, so the payment stayed "Refund in progress" with no way out shown.
echo "\n--- Audit round 16: refunds after a cancellation credit note, refunds by hand ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r16rFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R16 refunds {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
VatService::reset();
JobQueue::setDir(test_fixture_path('r16r-jobs'));
$r16rOrders = [];
$r16rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
// An order of an Austrian e-book (10 % AT, OSS) and a paperback (5 %): two VAT groups on its invoice.
$r16rOrder = static function (string $tag, string $status, string $paymentStatus, string $method, string $paymentId, string $provider) use ($pdo, $r16rProduct, &$r16rOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'seller_oss_enabled' => true, 'digital_customer_country' => 'AT', 'tax_provider' => 'vat'];
    $line = static fn(string $sku, string $type, int $price): array => ['product_id' => 1, 'variant_id' => null, 'sku' => $sku, 'price' => $price, 'qty' => 1,
        'total' => $price, 'available' => true, 'currency_mismatch' => false, 'product_type' => $type, 'vat_class' => 'books', 'currency' => 'eur',
        'price_formatted' => '', 'total_formatted' => ''];
    $items = Cart::applyVat(['items' => [$line('R16R-EBOOK', 'digital', 1100), $line('R16R-PB', 'simple', 2100)], 'subtotal' => 3200, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R16R', 'r16r@example.test', '', '{\"country\":\"SK\"}', 3200, 0, 0, 0, ?, 'eur', ?, ?, ?, ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute(['R16R-' . $tag, (int)$snapshot['totals']['gross'], $status, $paymentStatus, $method, $paymentId,
            json_encode(['code' => $method, 'provider' => $provider, 'service_type' => $method, 'label' => $method]), json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $orderId = $r16rOrders[] = (int)$pdo->lastInsertId();
    foreach ([['R16R-EBOOK', 'R16R e-book', 1100], ['R16R-PB', 'R16R paperback', 2100]] as [$sku, $name, $price]) {
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, ?, ?)')
            ->execute([$orderId, $r16rProduct, $sku, $name, $price, $price]);
    }
    return $orderId;
};
$r16rCredited = static fn(int $orderId): int => -(int)$pdo->query("SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE order_id = {$orderId} AND type = 'credit_note'")->fetchColumn();
$r16rError = static function (callable $call): string {
    try { $call(); return ''; } catch (Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
};

// Medium 1: a purchase on invoice cancelled (cancellation credit note for the whole invoice), then paid by card all the
// same; the late capture of the cancelled order is refunded and its refund_credit_note job runs.
try {
    $r16rLate = $r16rOrder('late', 'processing', 'pending', 'invoice', '', 'manual');
    $r16rInvoice = InvoiceService::issue($r16rLate);
    OrderWorkflow::transition($r16rLate, 'cancelled', 'R16R cancelled');
    InvoiceService::creditClosure($r16rLate);
    $r16rTotal = (int)$r16rInvoice['total_gross'];
    assert_equals($r16rTotal, $r16rCredited($r16rLate), 'R16 refunds: the cancelled invoice is fully credited by its cancellation credit note');
    $pdo->exec("UPDATE orders SET payment_status = 'refunded', payment_id = 'pi_r16r_late' WHERE id = {$r16rLate}");
    PaymentService::recordEvent($r16rLate, 'refund.succeeded', 'refunded', $r16rTotal, 'eur', 'r16r-late-refund', [], 'stripe', 're_r16r_late');
    $r16rJob = $r16rError(static fn() => InvoiceService::creditBookedRefund($r16rLate, 'r16r-late-refund'));
    assert_equals('', $r16rJob, 'R16 refunds: the refund_credit_note job of a refund after a cancellation credit note succeeds (was: LogicException, a permanently failed job and a critical incident)');
    assert_equals($r16rTotal, $r16rCredited($r16rLate), 'R16 refunds: nothing is credited twice: the cancellation credit note already corrected the invoice');
    assert_equals(null, InvoiceService::creditRefund($r16rLate, 500, 'r16r-late-again'), 'R16 refunds: a further refund credit note on the cancelled invoice is nothing to issue');

    // Part of the invoice was credited by a refund before: the cancellation credited the rest, the refund still balances.
    $r16rPartial = $r16rOrder('partial', 'processing', 'pending', 'invoice', '', 'manual');
    $r16rPartialInvoice = InvoiceService::issue($r16rPartial);
    InvoiceService::creditRefund($r16rPartial, 1000, 'r16r-partial-goodwill', 'R16R goodwill');
    OrderWorkflow::transition($r16rPartial, 'cancelled', 'R16R cancelled');
    InvoiceService::creditClosure($r16rPartial);
    assert_equals('', $r16rError(static fn() => InvoiceService::creditRefund($r16rPartial, (int)$r16rPartialInvoice['total_gross'] - 1000, 'r16r-partial-late')),
        'R16 refunds: the late refund of a partly refunded, then cancelled invoice issues no failing credit note');
    assert_equals((int)$r16rPartialInvoice['total_gross'], $r16rCredited($r16rPartial), 'R16 refunds: the partly refunded, cancelled invoice is credited exactly once in all');

    // Without a cancellation credit note an over-refund is still refused: nothing may credit more than was invoiced.
    $r16rPaid = $r16rOrder('over', 'delivered', 'paid', 'card', 'pi_r16r_over', 'stripe');
    $r16rPaidInvoice = InvoiceService::issue($r16rPaid);
    assert_true(str_contains($r16rError(static fn() => InvoiceService::creditRefund($r16rPaid, (int)$r16rPaidInvoice['total_gross'] + 1, 'r16r-over')), 'exceeds'),
        'R16 refunds: a refund credit note larger than the uncredited invoice is still refused when no cancellation credited it');
} catch (Throwable $e) {
    $r16rFail('after a cancellation credit note', $e);
}

// Medium 2: a bank-transfer order (no payment id, returned by hand).
if (!function_exists('pageOrder')) {
    if (!function_exists('adminFieldErrors') && is_file(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php';
}
$r16rPage = static function (int $orderId): string {
    $_GET = ['id' => (string)$orderId];
    ob_start();
    try {
        pageOrder();
    } finally {
        $html = (string)ob_get_clean();
        $_GET = [];
    }
    return $html;
};
try {
    $r16rBank = $r16rOrder('bank', 'delivered', 'paid', 'bank_transfer', '', 'manual');
    $r16rBankTotal = (int)Orders::byId($r16rBank)['total'];
    $r16rRequest = $r16rError(static fn() => PaymentService::requestRefundAndQueue($r16rBank, 1000, 'r16r-bank-request', ['reason' => 'R16R']));
    assert_true($r16rRequest !== '' && (string)Orders::byId($r16rBank)['payment_status'] === 'paid',
        'R16 refunds: a refund request on a payment returned by hand is refused and the payment stays paid (was: refund_pending with nothing to execute it) ' . $r16rRequest);
    $r16rHtml = $r16rPage($r16rBank);
    assert_true(!str_contains($r16rHtml, 'action=refund_request') && str_contains($r16rHtml, 'data-manual-refund'),
        'R16 refunds: the bank-transfer order offers the manual refund only, not "Request refund"');

    // A request claimed before this fix: the order detail says what to do, and recording the manual refund settles it.
    $r16rStuck = $r16rOrder('stuck', 'delivered', 'paid', 'bank_transfer', '', 'manual');
    PaymentService::requestRefund($r16rStuck, 1500, 'r16r-stuck-request', ['reason' => 'R16R']);
    $r16rStuckHtml = $r16rPage($r16rStuck);
    assert_true(str_contains($r16rStuckHtml, 'data-manual-refund-pending') && str_contains($r16rStuckHtml, 'data-manual-refund'),
        'R16 refunds: a by-hand refund left "Refund in progress" shows the hint to return it by hand and record it');
    PaymentService::completeManualRefund($r16rStuck, 1500, 'r16r-admin', 'R16R paid back by transfer');
    assert_equals('partially_refunded', (string)Orders::byId($r16rStuck)['payment_status'], 'R16 refunds: recording the manual refund ends "Refund in progress"');
    assert_true(!str_contains($r16rPage($r16rStuck), 'data-manual-refund-pending'), 'R16 refunds: the hint is gone once the refund is recorded');
    assert_true($r16rBankTotal > 1500, 'R16 refunds: the fixture order is larger than its partial refunds');
} catch (Throwable $e) {
    $r16rFail('by hand', $e);
} finally {
    foreach ($r16rOrders as $r16rCleanup) {
        foreach (['credit_note_items' => 'refund_id IN (SELECT id FROM order_refunds WHERE order_id = ?)', 'order_refunds' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'payment_transactions' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r16rTable => $r16rWhere) {
            try { $pdo->prepare("DELETE FROM {$r16rTable} WHERE {$r16rWhere}")->execute([$r16rCleanup]); } catch (Throwable $e) {}
        }
    }
}
