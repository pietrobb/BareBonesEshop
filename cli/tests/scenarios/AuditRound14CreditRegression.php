<?php

// Review round 14 (kolo 6), finding 2: a refund credit note took its VAT split from the whole invoice and was capped
// by the whole invoice only, so after an item credit note more VAT of a rate was credited than invoiced and the credit
// notes of one invoice could exceed it. A provider refund booked by its webhook before the refund job noted its id on
// the item refund got a second credit note, and two calls for one manual refund could both issue one.
echo "\n--- Audit round 14: credit notes subtract the earlier ones, no second credit note ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r14cFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R14 credit {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r14cError = static function (callable $call): string {
    try { $call(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
};
VatService::reset();
$r14cJobsDir = test_fixture_path('r14c-jobs');
JobQueue::setDir($r14cJobsDir);
$r14cOrder = 0;
try {
    // An order of an Austrian e-book (10 % AT, OSS) and a paperback collected in the shop (5 %): two VAT groups.
    $r14cCtx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'seller_oss_enabled' => true, 'digital_customer_country' => 'AT', 'tax_provider' => 'vat'];
    $r14cLine = static fn(string $sku, string $type, int $price): array => ['product_id' => 1, 'variant_id' => null, 'sku' => $sku, 'price' => $price, 'qty' => 1,
        'total' => $price, 'available' => true, 'currency_mismatch' => false, 'product_type' => $type, 'vat_class' => 'books', 'currency' => 'eur',
        'price_formatted' => '', 'total_formatted' => ''];
    $r14cItems = Cart::applyVat(['items' => [$r14cLine('R14C-EBOOK', 'digital', 1100), $r14cLine('R14C-PB', 'simple', 2100)], 'subtotal' => 3200, 'currency' => 'eur'], $r14cCtx)['items'];
    $r14cSnapshot = VatService::buildSnapshot($r14cItems, $r14cCtx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $r14cTotal = (int)$r14cSnapshot['totals']['gross'];
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES ('R14C-1', 'R14C', 'r14c@example.test', '', '{\"country\":\"SK\"}', 3200, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'stripe', 'pi_r14c', 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([$r14cTotal, json_encode($r14cSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $r14cOrder = (int)$pdo->lastInsertId();
    $r14cInvoice = InvoiceService::issue($r14cOrder);
    $r14cRates = json_decode((string)$r14cInvoice['vat_breakdown'], true);
    assert_equals(['5', '10 AT'], array_map('strval', array_keys($r14cRates)), 'R14 #2 setup: the invoice has a 5 % and a 10 % AT group');
    $r14cNotes = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id = {$r14cOrder} AND type = 'credit_note'")->fetchColumn();

    // A provider refund booked by its webhook while the item refund of the same amount has no provider refund id yet.
    $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, 0, 0, 300, 'eur', 'pending', 'r14c-item')")
        ->execute([$r14cOrder]);
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
        VALUES (?, 'default', 'stripe', 're_r14c_webhook', 'refunded', 300, 'eur', 'r14c-ledger', '{}')")->execute([$r14cOrder]);
    assert_equals(null, InvoiceService::creditBookedRefund($r14cOrder, 'r14c-ledger'), 'R14 #2: the booked refund issues no credit note yet');
    $r14cDeferred = array_values(array_filter(array_map(static fn(string $f): array => json_decode((string)file_get_contents($f), true) ?: [], glob($r14cJobsDir . '/pending/*.json') ?: []),
        static fn(array $job): bool => ($job['type'] ?? '') === 'refund_credit_note' && (int)($job['payload']['order_id'] ?? 0) === $r14cOrder));
    assert_equals([0, 1, 1], [$r14cNotes(), count($r14cDeferred), (int)($r14cDeferred[0]['payload']['deferrals'] ?? 0)],
        'R14 #2: while an item refund of that amount waits for its provider refund id, the credit note job waits a minute (was: a second credit note at once)');
    assert_true(str_contains($r14cError(static fn() => InvoiceService::creditBookedRefund($r14cOrder, 'r14c-ledger', 30)), 'no credit note issued') && $r14cNotes() === 0,
        'R14 #2: after half an hour of waiting the job fails visibly instead of guessing');
    $pdo->prepare("UPDATE order_refunds SET payment_reference = 're_r14c_webhook' WHERE order_id = ?")->execute([$r14cOrder]);
    assert_true(InvoiceService::creditBookedRefund($r14cOrder, 'r14c-ledger', 1) === null && $r14cNotes() === 0, 'R14 #2: once the id is noted the refund is recognised as the item refund');
    $pdo->prepare('DELETE FROM order_refunds WHERE order_id = ?')->execute([$r14cOrder]);
    $pdo->prepare('DELETE FROM payment_transactions WHERE order_id = ?')->execute([$r14cOrder]);

    // An item credit note of the whole e-book (10 % AT), then a refund of the rest of the order.
    $r14cSource = $pdo->query("SELECT * FROM invoices WHERE id = " . (int)$r14cInvoice['id'])->fetch(PDO::FETCH_ASSOC);
    $r14cEbook = $r14cRates['10 AT'];
    $r14cEbookGross = (int)$r14cEbook['net'] + (int)$r14cEbook['vat'];
    $r14cItemCredit = ['number' => 'R14C-CN-ITEM', 'type' => 'credit_note', 'refers_to_invoice_id' => (int)$r14cSource['id'],
        'total_gross' => -$r14cEbookGross, 'total_net' => -(int)$r14cEbook['net'], 'total_vat' => -(int)$r14cEbook['vat'],
        'vat_breakdown' => json_encode(['10 AT' => ['net' => -(int)$r14cEbook['net'], 'vat' => -(int)$r14cEbook['vat'], 'country' => 'AT', 'supply' => 'oss']]),
        'credit_snapshot' => '{}', 'download_token' => hash('sha256', 'r14c-item-credit'), 'pdf_path' => 'r14c/item.pdf'] + $r14cSource;
    unset($r14cItemCredit['id']);
    $pdo->prepare('INSERT INTO invoices (' . implode(', ', array_keys($r14cItemCredit)) . ') VALUES (' . implode(', ', array_fill(0, count($r14cItemCredit), '?')) . ')')
        ->execute(array_values($r14cItemCredit));

    $r14cRest = $r14cTotal - $r14cEbookGross;
    $r14cRefund = InvoiceService::creditRefund($r14cOrder, $r14cRest, 'r14c-rest', 'R14C');
    $r14cRefundRates = json_decode((string)($r14cRefund['vat_breakdown'] ?? '{}'), true) ?: [];
    assert_equals(['5' => [-(int)$r14cRates['5']['net'], -(int)$r14cRates['5']['vat']]],
        array_map(static fn(array $r): array => [(int)$r['net'], (int)$r['vat']], $r14cRefundRates),
        'R14 #2: the refund of the rest credits only the 5 % group the item credit note left (was: 10 % AT again, in proportion to the whole invoice)');
    assert_equals((int)($r14cRefund['id'] ?? 0), (int)(InvoiceService::creditRefund($r14cOrder, $r14cRest, 'r14c-rest', 'R14C')['id'] ?? -1),
        'R14 #2: the same refund key returns the same credit note');
    assert_true(str_contains($r14cError(static fn() => InvoiceService::creditRefund($r14cOrder, 1, 'r14c-over', 'R14C')), 'uncredited'),
        'R14 #2: nothing is left to credit on a fully credited invoice (was: capped by the whole invoice only)');
    assert_equals(-$r14cTotal, (int)$pdo->query("SELECT SUM(total_gross) FROM invoices WHERE order_id = {$r14cOrder} AND type = 'credit_note'")->fetchColumn(),
        'R14 #2: the credit notes of the invoice add up to the invoice, not more');

    // The key is checked again under the order lock, so two calls for one manual refund issue one credit note.
    $r14cSourceCode = (string)file_get_contents(dirname(__DIR__, 3) . '/src/InvoiceService.php');
    // creditRefund() and the cancellation credit note (R15) share rateCredit().
    $r14cBody = substr($r14cSourceCode, (int)strpos($r14cSourceCode, 'private static function rateCredit('), 4000);
    $r14cLock = strpos($r14cBody, 'Orders::lockWithin(');
    assert_true($r14cLock !== false && strpos($r14cBody, 'refundCreditByKey(', $r14cLock) !== false && strpos($r14cBody, 'self::uncredited(', $r14cLock) !== false,
        'R14 #2: the refund credit note checks its key and the uncredited balance under the order lock');
} catch (Throwable $e) {
    $r14cFail('credit notes', $e);
}

// T18 (b): an item refund the provider declined stayed pending for ever: its returned units and its money stayed
// "being refunded", so the merchant could neither refund them again nor see them free. (d): the invoice of a cash on
// delivery order printed the seller's IBAN, a second request to pay what the carrier collects.
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
if (!class_exists('R14cFakeStripe', false)) {
    class R14cFakeStripe extends StripePayment {
        public string $status = 'failed';
        public function __construct() { parent::__construct([]); }
        public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
            return ['refund_id' => 're_r14c_' . substr(sha1($idempotencyKey), 0, 10), 'status' => $this->status];
        }
    }
}
$r14cAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r14cSavedAdapters = $r14cAdapters->getValue();
$r14cAdapters->setValue(null, ['payment' => new R14cFakeStripe()] + $r14cSavedAdapters);
$r14cT18Orders = [];
try {
    $r14cStripeOrder = static function (string $tag) use ($pdo, &$r14cT18Orders): int {
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency,
                status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
            VALUES (?, 'R14C', 'r14c@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'delivered', 'paid', 'stripe', ?, ?, 'default', " . Db::now() . ")")
            ->execute(['R14C-' . $tag, 'pi_r14c_' . $tag, json_encode(['provider' => 'stripe', 'service_type' => 'online_card'])]);
        return $r14cT18Orders[] = (int)$pdo->lastInsertId();
    };
    // A pending item refund with its (issued) credit note, as InvoiceService::credit() leaves it.
    $r14cPendingCredit = static function (int $orderId, string $key, ?string $reference) use ($pdo): array {
        $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, type, status, currency, total_net, total_vat, total_gross, issued_at, pdf_path, download_token)
            VALUES (?, 'default', ?, 'credit_note', 'issued', 'eur', -300, 0, -300, " . Db::now() . ", ?, ?)")
            ->execute([$orderId, 'R14C-CN-' . $key, 'r14c/' . $key . '.pdf', hash('sha256', $key)]);
        $creditId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, payment_reference, idempotency_key)
            VALUES ('default', ?, 0, ?, 300, 'eur', 'pending', ?, ?)")->execute([$orderId, $creditId, $reference, $key]);
        return [(int)$pdo->lastInsertId(), $creditId];
    };
    $r14cState = static fn(int $refundId, int $creditId): array => [
        (string)$pdo->query("SELECT status FROM order_refunds WHERE id = {$refundId}")->fetchColumn(),
        (string)$pdo->query("SELECT status FROM invoices WHERE id = {$creditId}")->fetchColumn()];

    // The refund job: the provider declines the item refund.
    $r14cDeclined = $r14cStripeOrder('declined');
    [$r14cRefundId, $r14cCreditId] = $r14cPendingCredit($r14cDeclined, 'r14c-declined', null);
    $r14cJobError = $r14cError(static fn() => WorkerJobs::handlePaymentRefund(['order_id' => $r14cDeclined, 'payment_id' => 'pi_r14c_declined', 'amount' => 300,
        'refund_id' => $r14cRefundId, 'idempotency_key' => 'r14c-declined'], []));
    assert_equals(['', 'failed', 'cancelled'], [$r14cJobError, ...$r14cState($r14cRefundId, $r14cCreditId)],
        'R14 T18: a declined item refund is released and its credit note cancelled, the job is not retried (was: pending for ever, job failed)');
    assert_true(IncidentMonitor::reportedForOrder('payment.refund_failed', $r14cDeclined), 'R14 T18: the merchant is told the item refund was declined');

    // The provider's webhook: an item refund accepted as pending fails later.
    $r14cLater = $r14cStripeOrder('later');
    [$r14cLaterRefund, $r14cLaterCredit] = $r14cPendingCredit($r14cLater, 'r14c-later', 're_r14c_later');
    assert_true(PaymentService::providerRefundFailed($r14cLater, 'stripe', 're_r14c_later', 300, 'r14c-later-failed') === true
        && $r14cState($r14cLaterRefund, $r14cLaterCredit) === ['failed', 'cancelled'],
        'R14 T18: a pending item refund that fails at the provider is released with its credit note (was: ignored, pending for ever)');
    assert_true(PaymentService::providerRefundFailed($r14cLater, 'stripe', 're_r14c_later', 300, 'r14c-later-failed-2') === false,
        'R14 T18: a second failure event releases nothing more');
} catch (Throwable $e) {
    $r14cFail('T18 failed item refund', $e);
} finally {
    $r14cAdapters->setValue(null, $r14cSavedAdapters);
}

// T18 (d): the invoice of a cash on delivery order has no IBAN to pay to; a bank transfer invoice keeps it.
$r14cPdfOrders = [];
try {
    $r14cIbanOf = static function (string $code) use ($pdo, &$r14cPdfOrders): array {
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency,
                status, payment_status, payment_method, payment_method_snapshot, locale, store_id, created_at)
            VALUES (?, 'R14C', 'r14c@example.test', '', ?, 1000, 0, 0, 0, 1000, 'eur', 'processing', 'pending', ?, ?, 'sk', 'default', " . Db::now() . ")")
            ->execute(['R14C-PDF-' . $code, json_encode(['street' => 'Dobierka 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK']), $code,
                json_encode(['code' => $code, 'provider' => 'manual', 'service_type' => $code, 'label' => $code])]);
        $orderId = $r14cPdfOrders[] = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R14C-PDF', 'R14C pdf', 1, 1000, 1000)")
            ->execute([$orderId, (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn()]);
        preg_match_all('/Td \(([^)]*)\) Tj/', (string)file_get_contents(InvoiceService::pdfAbsolutePath(InvoiceService::issue($orderId))), $texts);
        return array_values(preg_grep('/^IBAN: /', $texts[1]));
    };
    $r14cTransferIban = $r14cIbanOf('bank_transfer');
    assert_true(count($r14cTransferIban) === 1, 'R14 T18 setup: the unpaid bank transfer invoice prints the IBAN to pay to');
    assert_equals([], $r14cIbanOf('cod'), 'R14 T18: the cash on delivery invoice prints no IBAN (was: the IBAN, as if the customer should pay by transfer)');
} catch (Throwable $e) {
    $r14cFail('T18 COD invoice', $e);
} finally {
    foreach ([...$r14cT18Orders, ...$r14cPdfOrders, $r14cOrder] as $r14cCleanup) {
        if ($r14cCleanup <= 0) continue;
        foreach (['order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?', 'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)',
            'invoices' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r14cTable => $r14cWhere) {
            try { $pdo->prepare("DELETE FROM {$r14cTable} WHERE {$r14cWhere}")->execute([$r14cCleanup]); } catch (Throwable $e) {}
        }
    }
}
