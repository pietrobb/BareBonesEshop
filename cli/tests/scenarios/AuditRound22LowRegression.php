<?php

// Review round 22 (2026-11-07), low "invoice, admin and texts":
// - a long item name on the PDF invoice ran into the quantity ("STÔL W25 …" after R21 narrowed the item column):
//   it was cut at 32 characters and capitals are wider than the flat 0.5 em the layout assumed;
// - at 390 px the refused-rows list, the feed's import-mode select, promotions and digital assets ran off the phone
//   (covered in tests/e2e/admin-ux.spec.js; the CSS rules are checked here);
// - the audit log showed raw payment event codes ("admin.paid") and amounts in cents inside JSON values ("total": 1647000);
// - the English order e-mail still said "this amount and variable symbol".
echo "\n--- R22: wrapped invoice item names, admin fits a phone, audit log in words and money, EN payment reference ---\n";

$r22lRoot = dirname(TEST_CLI_DIR);
$r22lLocale = I18n::locale();
try {
    I18n::setLocale('en');
    // 1. Invoice PDF: every line of a long name fits the item column; nothing of the name is lost.
    $r22lName = 'OAK TABLE W25 SOLID WOOD 160X80 CM BLACK FRAME WITH TWO DRAWERS';
    $r22lRender = new ReflectionMethod(InvoiceService::class, 'renderMinimalPdf');
    $r22lPdf = (string)$r22lRender->invoke(null,
        ['type' => 'invoice', 'number' => 'R22L-INV', 'locale' => 'en', 'order_id' => 0, 'currency' => 'eur', 'issued_at' => '2032-04-12',
            'delivery_date' => '2032-04-12', 'due_date' => '2032-04-26', 'total_net' => 3252, 'total_vat' => 748, 'total_gross' => 4000, 'reverse_charge_note_key' => ''],
        [['name' => $r22lName, 'qty' => 12, 'rate' => 23, 'net' => 1626, 'gross' => 2000], ['name' => 'Book', 'qty' => 1, 'rate' => 23, 'net' => 1626, 'gross' => 2000]],
        ['name' => 'R22 Seller', 'country' => 'SK'], ['name' => 'R22 Buyer', 'country' => 'SK'], []);
    $r22lMm = 72 / 25.4;
    preg_match_all('~BT /F1 8(?:\.0+)? Tf ([\d.]+) ([\d.]+) Td \(([^)]*)\) Tj ET~', $r22lPdf, $r22lRuns, PREG_SET_ORDER);
    $r22lItem = array_values(array_filter($r22lRuns, static fn(array $m): bool => abs((float)$m[1] - 20 * $r22lMm) < 0.1));
    $r22lLines = array_values(array_filter($r22lItem, static fn(array $m): bool => $m[3] !== 'Book' && str_contains($r22lName, $m[3])));
    $r22lWidth = new ReflectionMethod(MinimalPdf::class, 'approxTextWidth');
    $r22lTooWide = array_filter($r22lLines, static fn(array $m): bool => $r22lWidth->invoke(new MinimalPdf(), $m[3], 8.0, false) > 46);
    $r22lBook = array_values(array_filter($r22lItem, static fn(array $m): bool => $m[3] === 'Book'));
    $r22lQty = array_values(array_filter($r22lRuns, static fn(array $m): bool => $m[3] === '12'));
    assert_true(count($r22lLines) >= 2 && $r22lTooWide === [] && implode(' ', array_column($r22lLines, 3)) === $r22lName
        && $r22lBook !== [] && (float)$r22lBook[0][2] <= (float)end($r22lLines)[2] - 8.0
        && $r22lQty !== [] && abs((float)$r22lQty[0][2] - (float)$r22lLines[0][2]) < 0.01,
        'R22 invoice PDF: a long item name wraps inside its column, in full, and the next item starts below it (' . count($r22lLines) . ' lines)');
    // Capitals count wider than lower case, and a word wider than the column is split rather than overflowing.
    $r22lPdfObj = new MinimalPdf();
    $r22lSplit = $r22lPdfObj->wrap('SKU-' . str_repeat('W', 40), 46, 8);
    assert_true($r22lWidth->invoke($r22lPdfObj, 'WWWW', 8.0, false) > $r22lWidth->invoke($r22lPdfObj, 'iiii', 8.0, false) * 3
        && count($r22lSplit) >= 2 && implode('', $r22lSplit) === 'SKU-' . str_repeat('W', 40)
        && array_filter($r22lSplit, static fn(string $l): bool => $r22lWidth->invoke($r22lPdfObj, $l, 8.0, false) > 46) === [],
        'R22 invoice PDF: text width follows Helvetica (W wider than i), an over-long word is split to the column width');

    // 2. Admin CSS: form controls never exceed their cell, long unbroken text wraps, the digital-assets table scrolls in its box.
    $r22lAdmin = (string)file_get_contents($r22lRoot . '/src/Entrypoints/Admin.php');
    $r22lCatalog = (string)file_get_contents($r22lRoot . '/src/Entrypoints/Admin/Catalog.php');
    $r22lE2e = (string)file_get_contents($r22lRoot . '/tests/e2e/admin-ux.spec.js');
    assert_true(str_contains($r22lAdmin, 'font-size:.9rem;max-width:100%}') && str_contains($r22lAdmin, 'margin-bottom:1rem;overflow-wrap:anywhere}')
        && str_contains($r22lAdmin, 'code{overflow-wrap:anywhere}')
        && str_contains($r22lCatalog, "<div class=\"card table-scroll\">\n        <h3><?= esc(I18n::t('admin.digital.mappings'))")
        && str_contains($r22lE2e, "'/admin.php?p=feed', '/admin.php?p=promotions', '/admin.php?p=digital_assets', '/admin.php?p=import'"),
        'R22 admin: inputs and selects fit their cell, flash details and paths wrap, the digital-assets table scrolls; the phone test covers the pages');

    // 3. Audit log: payment event codes in words, JSON amounts and older cents notes in the record's currency.
    $r22lDb = Db::get();
    $r22lDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id)
        VALUES (?, 'paid', 'paid', 'R22L Buyer', 'r22l@example.test', 'card', '', 1647000, 1647000, 'huf', 'default')")->execute(['R22L-' . bin2hex(random_bytes(4))]);
    $r22lOrder = (int)$r22lDb->lastInsertId();
    $r22lHuf = I18n::price(1647000, 'huf');
    $r22lCurrency = static fn(int $orderId): ?string => $orderId === 7 ? 'huf' : null;
    try {
        assert_equals([
            strtr(I18n::t('admin.audit.event.admin'), ['{status}' => OrderStatusLabels::payment('paid')]),
            I18n::t('admin.audit.event.refund.requested'), I18n::t('admin.audit.event.cod.collected'),
            I18n::t('admin.audit.event.admin.offline_payment_received'), 'stripe.unknown_event', 'Admin status change',
            'Refund of ' . $r22lHuf . ' re-queued by admin@example.test',
            'stripe refund re_1 of ' . $r22lHuf . ' failed',
            'total: ' . $r22lHuf . '; items: [{"sku":"A/1","price":"' . I18n::price(500000, 'huf') . '","qty":2}]',
            'label_carrier: dpd; cod_amount: ' . I18n::price(129000, 'eur') . '; currency: eur',
            '{"total":1647000}',
        ], [
            AuditLogLabels::note('payment_status', 7, 'admin.paid'), AuditLogLabels::note('payment_status', 7, 'refund.requested'),
            AuditLogLabels::note('payment_status', 7, 'cod.collected'), AuditLogLabels::note('payment_status', 7, 'admin.offline_payment_received'),
            AuditLogLabels::note('payment_status', 7, 'stripe.unknown_event'), AuditLogLabels::note('payment_status', 7, 'Admin status change'),
            AuditLogLabels::note('order_refund', 7, 'Refund of 1647000 re-queued by admin@example.test', $r22lCurrency),
            AuditLogLabels::note('order_refund', 7, 'stripe refund re_1 of 1647000 failed', $r22lCurrency),
            AuditLogLabels::value('order', '{"total":1647000,"items":[{"sku":"A/1","price":500000,"qty":2}]}', $r22lOrder),
            AuditLogLabels::value('shipment', '{"label_carrier":"dpd","cod_amount":129000,"currency":"eur"}', 0),
            AuditLogLabels::value('customer', '{"total":1647000}', $r22lOrder),
        ], 'R22 audit log: "admin.paid" reads "Set in the admin: Paid", JSON amounts and older cents notes show in the order\'s currency');
    } finally {
        $r22lDb->prepare('DELETE FROM orders WHERE id = ?')->execute([$r22lOrder]);
    }
    $r22lPayments = (string)file_get_contents($r22lRoot . '/src/PaymentService.php');
    assert_true(!str_contains($r22lPayments, '"Refund of {$request[\'amount\']}') && !str_contains($r22lPayments, "AuditLog::log('order_refund', \$orderId, \$from, \$to, \"{\$provider} refund {\$refundId} of {\$amount} failed\")"),
        'R22 audit log: re-queued and failed refund notes are written with the formatted amount');

    // 4. The English order e-mail asks for the payment reference, as its table labels it.
    $r22lMail = (string)file_get_contents($r22lRoot . '/templates/emails/en/order_confirmation.html');
    assert_true(str_contains($r22lMail, 'Use exactly this amount and payment reference.') && stripos($r22lMail, 'variable symbol') === false,
        'R22 e-mails: the English bank-transfer sentence says "payment reference", not "variable symbol"');
} finally {
    I18n::setLocale($r22lLocale);
}
