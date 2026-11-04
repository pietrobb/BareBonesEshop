<?php

// Review round 15 (2026-11-04). High 3: after a refund credit note (a share of every VAT rate) the item credit note of a
// returned product credited its full price at its rate, more than the invoice had left there: negative VAT at that rate.
// High 4: the issued invoice of an unpaid order closed without its money (a purchase on invoice cancelled, a refused
// cash-on-delivery parcel returned) stayed issued and due, and in the VAT and OSS reports: no credit note was issued.
echo "\n--- Audit round 15: item credit notes per VAT rate, cancellation credit notes ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r15iFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R15 invoicing {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
VatService::reset();
$r15iJobsDir = test_fixture_path('r15i-jobs');
JobQueue::setDir($r15iJobsDir);
$r15iOrders = [];
$r15iProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
// An order of an Austrian e-book (10 % AT, OSS) and a paperback (5 %), with its order lines: two VAT groups.
$r15iOrder = static function (string $tag, string $status, string $paymentStatus, string $method, string $paymentId = '') use ($pdo, $r15iProduct, &$r15iOrders): int {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'seller_oss_enabled' => true, 'digital_customer_country' => 'AT', 'tax_provider' => 'vat'];
    $line = static fn(string $sku, string $type, int $price): array => ['product_id' => 1, 'variant_id' => null, 'sku' => $sku, 'price' => $price, 'qty' => 1,
        'total' => $price, 'available' => true, 'currency_mismatch' => false, 'product_type' => $type, 'vat_class' => 'books', 'currency' => 'eur',
        'price_formatted' => '', 'total_formatted' => ''];
    $items = Cart::applyVat(['items' => [$line('R15I-EBOOK', 'digital', 1100), $line('R15I-PB', 'simple', 2100)], 'subtotal' => 3200, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES (?, 'R15I', 'r15i@example.test', '', '{\"country\":\"SK\"}', 3200, 0, 0, 0, ?, 'eur', ?, ?, ?, ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute(['R15I-' . $tag, (int)$snapshot['totals']['gross'], $status, $paymentStatus, $method, $paymentId,
            json_encode(['code' => $method, 'provider' => 'manual', 'service_type' => $method, 'label' => $method]), json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $orderId = $r15iOrders[] = (int)$pdo->lastInsertId();
    foreach ([['R15I-EBOOK', 'R15I e-book', 1100], ['R15I-PB', 'R15I paperback', 2100]] as [$sku, $name, $price]) {
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, ?, ?)')
            ->execute([$orderId, $r15iProduct, $sku, $name, $price, $price]);
    }
    return $orderId;
};
// VAT and gross of the order's invoice less all its credit notes, per breakdown key.
$r15iNet = static function (int $orderId) use ($pdo): array {
    $sum = [];
    foreach ($pdo->query("SELECT vat_breakdown FROM invoices WHERE order_id = {$orderId}")->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach (json_decode((string)$json, true) ?: [] as $key => $rate) {
            $sum[(string)$key] ??= ['net' => 0, 'vat' => 0];
            $sum[(string)$key]['net'] += (int)$rate['net'];
            $sum[(string)$key]['vat'] += (int)$rate['vat'];
        }
    }
    ksort($sum);
    return $sum;
};
$r15iJobs = static fn(int $orderId): int => count(array_filter(array_map(static fn(string $f): array => json_decode((string)file_get_contents($f), true) ?: [], glob($r15iJobsDir . '/pending/*.json') ?: []),
    static fn(array $job): bool => ($job['type'] ?? '') === 'closure_credit_note' && (int)($job['payload']['order_id'] ?? 0) === $orderId));

// High 3: a goodwill refund of 10.00 (its credit note takes a share of both rates), then the paperback comes back.
try {
    $r15iPaid = $r15iOrder('rma', 'delivered', 'paid', 'stripe');
    $r15iInvoice = InvoiceService::issue($r15iPaid);
    InvoiceService::creditRefund($r15iPaid, 1000, 'r15i-goodwill', 'R15I goodwill');
    $r15iPb = (int)Orders::items($r15iPaid)[1]['id'];
    [, $r15iRma] = Rma::create($r15iPaid, null, 'R15I paperback back', [$r15iPb => 1]);
    Rma::setStatus((int)$r15iRma, 'approved');
    $r15iRmaItem = (int)Rma::items((int)$r15iRma)[0]['id'];
    Rma::receive((int)$r15iRma, [$r15iRmaItem => ['quantity' => 1, 'disposition' => 'restock']], 'r15i-receipt');
    $r15iCredit = InvoiceService::credit((int)$r15iInvoice['id'], (int)$r15iRma, [$r15iRmaItem => 1], 'r15i-item');
    $r15iLeft = $r15iNet($r15iPaid);
    assert_true(($r15iLeft['5']['net'] ?? -1) >= 0 && ($r15iLeft['5']['vat'] ?? -1) >= 0 && ($r15iLeft['10 AT']['vat'] ?? -1) > 0,
        'R15 H3: after a refund credit note the item credit note leaves no negative VAT at the 5 % rate (was: the full price credited again) ' . json_encode($r15iLeft));
    assert_equals([0, 0], [(int)$r15iLeft['5']['net'], (int)$r15iLeft['5']['vat']], 'R15 H3: the returned paperback credits exactly what its rate still had uncredited');
    assert_true((int)$r15iCredit['total_gross'] < 0 && -(int)$r15iCredit['total_gross'] < 2100, 'R15 H3: the item credit note is the rest of the 5 % rate, less than the full price');
} catch (Throwable $e) {
    $r15iFail('H3 item credit after a refund', $e);
}

// High 4: a purchase on invoice accepted (invoice issued and due), then cancelled before anything shipped.
try {
    $r15iB2b = $r15iOrder('b2b', 'processing', 'pending', 'invoice');
    InvoiceService::issue($r15iB2b);
    OrderWorkflow::transition($r15iB2b, 'cancelled', 'R15I cancelled');
    assert_equals(1, $r15iJobs($r15iB2b), 'R15 H4: cancelling the unpaid order queues its cancellation credit note');
    $r15iStorno = InvoiceService::creditClosure($r15iB2b);
    assert_true(is_array($r15iStorno) && (string)$r15iStorno['type'] === 'credit_note', 'R15 H4: the cancelled purchase on invoice gets a credit note (was: the invoice stayed due)');
    assert_equals([], array_filter($r15iNet($r15iB2b), static fn(array $r): bool => $r['net'] !== 0 || $r['vat'] !== 0),
        'R15 H4: the invoice and its credit note cancel out at every VAT rate, so the VAT and OSS reports carry nothing');
    assert_equals((int)$r15iStorno['id'], (int)(InvoiceService::creditClosure($r15iB2b)['id'] ?? -1), 'R15 H4: the job run twice issues one credit note');
    $r15iSnap = json_decode((string)$r15iStorno['credit_snapshot'], true) ?: [];
    assert_equals('cancellation', (string)($r15iSnap['kind'] ?? ''), 'R15 H4: the credit note is a cancellation, not a refund');
} catch (Throwable $e) {
    $r15iFail('H4 cancelled purchase on invoice', $e);
}

// High 4: a cash-on-delivery parcel refused at the door and returned, its invoice issued when it shipped.
try {
    $r15iCod = $r15iOrder('cod', 'shipped', 'pending', 'cod');
    InvoiceService::issue($r15iCod);
    OrderWorkflow::transition($r15iCod, 'returned', 'R15I refused');
    assert_equals(1, $r15iJobs($r15iCod), 'R15 H4: the returned unpaid COD order queues its cancellation credit note');
    InvoiceService::creditClosure($r15iCod);
    assert_equals([], array_filter($r15iNet($r15iCod), static fn(array $r): bool => $r['net'] !== 0 || $r['vat'] !== 0),
        'R15 H4: the refused COD invoice is fully credited (was: issued and due for ever)');
    // A paid order that is returned keeps its invoice: its money goes back through the refund ledger and its credit notes.
    $r15iPaidReturn = $r15iOrder('paid-return', 'delivered', 'paid', 'stripe');
    InvoiceService::issue($r15iPaidReturn);
    assert_equals(null, InvoiceService::creditClosure($r15iPaidReturn), 'R15 H4: an order not cancelled or returned gets no cancellation credit note');
    $pdo->exec("UPDATE orders SET status = 'returned' WHERE id = {$r15iPaidReturn}");
    assert_equals(null, InvoiceService::creditClosure($r15iPaidReturn), 'R15 H4: a paid returned order gets no cancellation credit note (the refund credits it)');
} catch (Throwable $e) {
    $r15iFail('H4 refused COD', $e);
} finally {
    foreach ($r15iOrders as $r15iCleanup) {
        foreach (['credit_note_items' => 'refund_id IN (SELECT id FROM order_refunds WHERE order_id = ?)', 'order_refunds' => 'order_id = ?',
            'rma_items' => 'rma_id IN (SELECT id FROM rma_requests WHERE order_id = ?)', 'rma_requests' => 'order_id = ?',
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?',
            'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r15iTable => $r15iWhere) {
            try { $pdo->prepare("DELETE FROM {$r15iTable} WHERE {$r15iWhere}")->execute([$r15iCleanup]); } catch (Throwable $e) {}
        }
    }
}
