<?php

// Review round 23 (2026-11-08), medium "refunds" (goal #367), reproduced by the reviewer:
// - after a declined item refund the payment stayed refund_pending for good: failRefund() cancelled the credit note but
//   never moved the payment back, so the admin's next refund was refused.
// - failRefund() cancelled the already numbered credit note: a refund failing days later took it out of a VAT or OSS
//   period that may already be filed. A reversal document is issued instead.
// Low: with three or more VAT rates a refund credit note could credit a cent more at one rate than the invoice had left
// there; a refund booked without a gateway refund id (GoPay, ThePay, Comgate) waited for an unrelated item refund of
// the same amount for 30 minutes and then failed.
echo "\n--- R23: a declined item refund frees the payment and is reversed by a new document ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r23rFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R23 refunds {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
VatService::reset();
JobQueue::setDir(test_fixture_path('r23r-jobs'));
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R23rFakeStripe', false)) {
    class R23rFakeStripe extends StripePayment {
        public function __construct() { parent::__construct([]); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            return ['refund_id' => 're_r23r_' . substr(sha1($idempotencyKey), 0, 10), 'status' => 'failed'];
        }
    }
}
$r23rAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r23rSavedAdapters = $r23rAdapters->getValue();
$r23rAdapters->setValue(null, ['payment' => new R23rFakeStripe()] + $r23rSavedAdapters);
$r23rProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r23rOrders = [];
$r23rNet = static function (int $orderId) use ($pdo): array {
    $sum = [];
    foreach ($pdo->query("SELECT vat_breakdown FROM invoices WHERE order_id = {$orderId} AND status = 'issued'")->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach (json_decode((string)$json, true) ?: [] as $key => $rate) {
            $sum[(string)$key] = ($sum[(string)$key] ?? 0) + (int)$rate['net'] + (int)$rate['vat'];
        }
    }
    ksort($sum);
    return $sum;
};
try {
    // A paid card order of an e-book (10 % AT) and a paperback (5 %), invoiced; the paperback comes back.
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'seller_oss_enabled' => true, 'digital_customer_country' => 'AT', 'tax_provider' => 'vat'];
    $line = static fn(string $sku, string $type, int $price): array => ['product_id' => 1, 'variant_id' => null, 'sku' => $sku, 'price' => $price, 'qty' => 1,
        'total' => $price, 'available' => true, 'currency_mismatch' => false, 'product_type' => $type, 'vat_class' => 'books', 'currency' => 'eur',
        'price_formatted' => '', 'total_formatted' => ''];
    $items = Cart::applyVat(['items' => [$line('R23R-EBOOK', 'digital', 1100), $line('R23R-PB', 'simple', 2100)], 'subtotal' => 3200, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES ('R23R-1', 'R23R', 'r23r@example.test', '', '{\"country\":\"SK\"}', 3200, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', 'pi_r23r', ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([(int)$snapshot['totals']['gross'], json_encode(['provider' => 'stripe', 'service_type' => 'online_card']), json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $r23rOrder = $r23rOrders[] = (int)$pdo->lastInsertId();
    foreach ([['R23R-EBOOK', 'R23R e-book', 1100], ['R23R-PB', 'R23R paperback', 2100]] as [$sku, $name, $price]) {
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, ?, ?)')
            ->execute([$r23rOrder, $r23rProduct, $sku, $name, $price, $price]);
    }
    $r23rInvoice = InvoiceService::issue($r23rOrder);
    $r23rBefore = $r23rNet($r23rOrder);
    [, $r23rRma] = Rma::create($r23rOrder, null, 'R23R paperback back', [(int)Orders::items($r23rOrder)[1]['id'] => 1]);
    Rma::setStatus((int)$r23rRma, 'approved');
    $r23rRmaItem = (int)Rma::items((int)$r23rRma)[0]['id'];
    Rma::receive((int)$r23rRma, [$r23rRmaItem => ['quantity' => 1, 'disposition' => 'repair']], 'r23r-receipt');
    $r23rCredit = InvoiceService::credit((int)$r23rInvoice['id'], (int)$r23rRma, [$r23rRmaItem => 1], 'r23r-item');
    assert_equals('refund_pending', (string)Orders::byId($r23rOrder)['payment_status'], 'R23 refunds setup: the item credit note puts the payment in refund_pending');

    // The provider declines the item refund.
    WorkerJobs::handlePaymentRefund(['order_id' => $r23rOrder, 'payment_id' => 'pi_r23r', 'amount' => -(int)$r23rCredit['total_gross'],
        'refund_id' => (int)$r23rCredit['refund_id'], 'idempotency_key' => 'r23r-item'], []);
    assert_equals(['failed', 'paid'], [(string)$pdo->query('SELECT status FROM order_refunds WHERE id = ' . (int)$r23rCredit['refund_id'])->fetchColumn(),
        (string)Orders::byId($r23rOrder)['payment_status']], 'R23 refunds: after the declined item refund the payment is paid again (it stayed refund_pending)');

    $r23rReversals = $pdo->query("SELECT * FROM invoices WHERE order_id = {$r23rOrder} AND type = 'credit_note' AND credit_snapshot LIKE '%\"kind\":\"reversal\"%'")->fetchAll(PDO::FETCH_ASSOC);
    $r23rReversal = $r23rReversals[0] ?? [];
    assert_equals(['issued', 1, -(int)$r23rCredit['total_gross'], (string)$r23rCredit['number']],
        [(string)$pdo->query('SELECT status FROM invoices WHERE id = ' . (int)$r23rCredit['id'])->fetchColumn(), count($r23rReversals),
            (int)($r23rReversal['total_gross'] ?? 0), (string)((json_decode((string)($r23rReversal['credit_snapshot'] ?? ''), true) ?: [])['reverses_number'] ?? '')],
        'R23 refunds: the numbered credit note stays issued (it was cancelled out of its VAT period) and a reversal of its amount is issued');
    assert_true(($r23rReversal['number'] ?? '') !== '' && $r23rReversal['number'] !== $r23rCredit['number'] && is_file(InvoiceService::pdfAbsolutePath($r23rReversal)),
        'R23 refunds: the reversal has its own number of the credit note series and a PDF');
    assert_equals($r23rBefore, $r23rNet($r23rOrder), 'R23 refunds: credit note and reversal cancel out at every VAT rate, the invoice is uncredited again');
    assert_equals((int)$r23rReversal['id'], (int)(InvoiceService::reverseCredit((int)$r23rCredit['id'], 'again')['id'] ?? -1), 'R23 refunds: a second reversal call returns the same document');
    $r23rOss = (string)file_get_contents(TEST_CLI_DIR . '/oss-report.php');
    assert_true(str_contains($r23rOss, "=== 'reversal' ? 1 : -1") && str_contains($r23rOss, 'i.credit_snapshot'),
        'R23 refunds: the OSS report adds a reversal back instead of subtracting it as a credit note');

    // Low: a refund booked under the payment id (no gateway refund id) next to an unrelated pending item refund of the
    // same amount gets its credit note at once.
    $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, 0, 0, 300, 'eur', 'pending', 'r23r-other')")
        ->execute([$r23rOrder]);
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
        VALUES (?, 'default', 'gopay', 'pi_r23r', 'partially_refunded', 300, 'eur', 'r23r-ledger', '{}')")->execute([$r23rOrder]);
    $r23rBooked = InvoiceService::creditBookedRefund($r23rOrder, 'r23r-ledger');
    assert_equals(-300, (int)($r23rBooked['total_gross'] ?? 0),
        'R23 refunds: a refund booked without a gateway refund id is credited at once (it waited for an unrelated item refund of the same amount)');
    $pdo->prepare("DELETE FROM order_refunds WHERE idempotency_key = 'r23r-other'")->execute();

    // The admin's next refund goes through.
    assert_true(PaymentService::requestRefund($r23rOrder, 500, 'r23r-admin'), 'R23 refunds: the admin can request a refund again (was refused)');
} catch (Throwable $e) {
    $r23rFail('declined item refund', $e);
} finally {
    $r23rAdapters->setValue(null, $r23rSavedAdapters);
}

// Low: three VAT rates of one cent each, a refund of two cents.
try {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, store_id, created_at)
        VALUES ('R23R-3', 'R23R', 'r23r@example.test', '', '{\"country\":\"SK\"}', 3, 0, 0, 0, 3, 'eur', 'delivered', 'paid', 'bank_transfer', 'default', " . Db::now() . ")")->execute();
    $r23rThree = $r23rOrders[] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, type, status, currency, total_net, total_vat, total_gross, vat_breakdown, issued_at, pdf_path, download_token,
            customer_snapshot, seller_snapshot, locale)
        VALUES (?, 'default', 'R23R-3', 'invoice', 'issued', 'eur', 3, 0, 3, ?, " . Db::now() . ", 'r23r/3.pdf', ?, '{}', '{}', 'sk')")
        ->execute([$r23rThree, json_encode(['5' => ['net' => 1, 'vat' => 0], '10' => ['net' => 1, 'vat' => 0], '23' => ['net' => 1, 'vat' => 0]]), hash('sha256', 'r23r-3')]);
    $r23rSplit = json_decode((string)(InvoiceService::creditRefund($r23rThree, 2, 'r23r-three')['vat_breakdown'] ?? '{}'), true) ?: [];
    assert_true($r23rSplit !== [] && min(array_map(static fn(array $r): int => (int)$r['net'] + (int)$r['vat'], $r23rSplit)) >= -1
        && array_sum(array_map(static fn(array $r): int => (int)$r['net'] + (int)$r['vat'], $r23rSplit)) === -2,
        'R23 refunds: with three rates no rate is credited more than it has (was -2 cents at a 1-cent rate) (' . json_encode($r23rSplit) . ')');
} catch (Throwable $e) {
    $r23rFail('three VAT rates', $e);
} finally {
    foreach ($r23rOrders as $r23rCleanup) {
        foreach (['credit_note_items' => 'credit_note_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r23rTable => $r23rWhere) {
            try { $pdo->prepare("DELETE FROM {$r23rTable} WHERE {$r23rWhere}")->execute([$r23rCleanup]); } catch (Throwable $e) {}
        }
    }
}
