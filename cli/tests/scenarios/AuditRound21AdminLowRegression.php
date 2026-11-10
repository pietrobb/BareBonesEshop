<?php

// Review round 21 (2026-11-07), low "admin and documents":
// - audit-log notes carried refund amounts in cents ("Manual refund of 1647000 completed by …" for 16 470 Ft);
// - the first parcel of a partly shipped cash-on-delivery order collected the whole COD amount (covered in the R11/R13
//   fulfillment scenarios: each parcel collects its goods, the last one the rest of the total);
// - the English e-mail of the HU store said "Variable symbol" (a Slovak/Czech term; HU/PL/DE/ES were calques too);
// - the English PDF invoice wrapped "Unit price excl. VAT" onto two lines although the item column had room.
echo "\n--- R21: audit notes in money, payment reference label, one-line invoice items header ---\n";

$r21aRoot = dirname(TEST_CLI_DIR);
$r21aLocale = I18n::locale();
try {
    // 1. Audit notes: new notes are written in the order's currency, older ones in cents read the same way.
    $r21aHuf = I18n::price(1647000, 'huf');
    $r21aCurrency = static fn(int $orderId): ?string => $orderId === 7 ? 'huf' : null;
    assert_equals([
        $r21aHuf, I18n::price(1290, 'eur'),
        // R23: in the admin's language.
        I18n::format('admin.audit.note.refund_manual', ['amount' => $r21aHuf, 'detail' => 'admin@example.test: bank 12']),
        I18n::format('admin.audit.note.refund_provider', ['amount' => $r21aHuf, 'detail' => 'admin: seen in portal']),
        I18n::format('admin.audit.note.stripe_full_refund', ['amount' => $r21aHuf]),
        I18n::format('admin.audit.note.stripe_refund', ['amount' => I18n::price(500000, 'huf'), 'total' => $r21aHuf]),
        'Manual refund of 1647000 completed by x: y',
        I18n::t('admin.audit.note.status_change'),
    ], [
        AuditLogLabels::amount(1647000, 'HUF'), AuditLogLabels::amount(1290, null),
        AuditLogLabels::note('order_refund', 7, 'Manual refund of 1647000 completed by admin@example.test: bank 12', $r21aCurrency),
        AuditLogLabels::note('order_refund', 7, 'Provider refund of 1647000 confirmed by admin: seen in portal', $r21aCurrency),
        AuditLogLabels::note('order_status', 7, 'Stripe full refund: 1647000 cents', $r21aCurrency),
        AuditLogLabels::note('order_refund', 7, 'Stripe refund: 500000/1647000 cents', $r21aCurrency),
        AuditLogLabels::note('product', 7, 'Manual refund of 1647000 completed by x: y', $r21aCurrency),
        AuditLogLabels::note('order_status', 7, 'Admin status change', $r21aCurrency),
    ], 'R21 audit log: refund notes show the amount in the order\'s currency ("16 470 Ft"), not in cents; other notes stay as written');
    $r21aPayments = (string)file_get_contents($r21aRoot . '/src/PaymentService.php') . (string)file_get_contents($r21aRoot . '/src/Http/ApiHandlers.php');
    assert_true(!str_contains($r21aPayments, 'refund of {$') && !str_contains($r21aPayments, ' cents"') && !str_contains($r21aPayments, ' cents")')
        && substr_count($r21aPayments, 'AuditLogLabels::amount(') === 9, 'R21 audit log: refund notes are written with the formatted amount'); // R22: +2 (re-queued, failed); R28: +2 (booked while a refund is in flight)
    // The amount a Stripe refund webhook records as the new value reads in the order's currency too (the order row decides).
    $r21aDb = Db::get();
    $r21aDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id)
        VALUES (?, 'paid', 'refunded', 'R21A Buyer', 'r21a@example.test', 'card', '', 1647000, 1647000, 'huf', 'default')")->execute(['R21A-' . bin2hex(random_bytes(4))]);
    $r21aOrder = (int)$r21aDb->lastInsertId();
    assert_equals([$r21aHuf, OrderStatusLabels::payment('refunded'), '1647000'], [
        AuditLogLabels::value('order_refund', '1647000', $r21aOrder), AuditLogLabels::value('order_refund', 'refunded', $r21aOrder),
        AuditLogLabels::value('order_refund', '1647000'),
    ], 'R21 audit log: a refund amount recorded in cents shows in the order\'s currency');
    $r21aDb->prepare('DELETE FROM orders WHERE id = ?')->execute([$r21aOrder]);
    $r21aOps = (string)file_get_contents($r21aRoot . '/src/Entrypoints/Admin/Operations.php');
    assert_true(str_contains($r21aOps, 'AuditLogLabels::note((string)$log[\'entity\']') && !str_contains($r21aOps, "esc(\$log['note'] ?? '')"),
        'R21 audit log: the note column reads older notes in money too');

    // 2. The bank-transfer reference in the order and reminder e-mails uses the language's own term, as the storefront does.
    $r21aLabels = [];
    foreach (['en', 'de', 'es', 'hu', 'pl', 'sk', 'cs'] as $r21aLang) {
        I18n::setLocale($r21aLang);
        foreach (['order_confirmation', 'bank_transfer_reminder'] as $r21aTemplate) {
            $r21aHtml = (string)file_get_contents("{$r21aRoot}/templates/emails/{$r21aLang}/{$r21aTemplate}.html");
            preg_match('~>([^<>]*)</td><td><strong>\{\{bank_vs\}\}~u', $r21aHtml, $r21aMatch);
            if (($r21aMatch[1] ?? null) !== I18n::t('bank_transfer.variable_symbol')) $r21aLabels[] = "{$r21aLang}/{$r21aTemplate}: " . ($r21aMatch[1] ?? '(none)');
        }
    }
    assert_equals([], $r21aLabels, 'R21 e-mails: the bank-transfer reference is labelled like the storefront (EN "Payment reference", not "Variable symbol")');

    // 3. The PDF items header is one line in every invoice language, and the row amounts end where their column ends.
    $r21aRender = new ReflectionMethod(InvoiceService::class, 'renderMinimalPdf');
    $r21aPdf = static fn(string $locale): string => (string)$r21aRender->invoke(null,
        ['type' => 'invoice', 'number' => 'R21A-INV-' . $locale, 'locale' => $locale, 'order_id' => 0, 'currency' => 'eur', 'issued_at' => '2032-04-12',
            'delivery_date' => '2032-04-12', 'due_date' => '2032-04-26', 'total_net' => 1626, 'total_vat' => 374, 'total_gross' => 2000, 'reverse_charge_note_key' => ''],
        [['name' => 'Book', 'qty' => 1, 'rate' => 23, 'net' => 1626, 'gross' => 2000]],
        ['name' => 'R21 Seller', 'country' => 'SK'], ['name' => 'R21 Buyer', 'country' => 'SK'], []);
    $r21aWrapped = [];
    foreach (['en', 'es', 'de', 'sk', 'cs', 'hu', 'pl'] as $r21aLang) {
        preg_match_all('~BT /F2 8(?:\.0+)? Tf ([\d.]+) ([\d.]+) Td~', $r21aPdf($r21aLang), $r21aHeader, PREG_SET_ORDER);
        $r21aRows = array_unique(array_map(static fn(array $m): string => $m[2], $r21aHeader));
        if (count($r21aHeader) !== 6 || count($r21aRows) !== 1) $r21aWrapped[] = "{$r21aLang}: " . count($r21aHeader) . ' labels on ' . count($r21aRows) . ' lines';
    }
    assert_equals([], $r21aWrapped, 'R21 invoice PDF: the items header is six labels on one line in every language ("Unit price excl. VAT" no longer wraps)');
    $r21aSource = (string)file_get_contents($r21aRoot . '/src/InvoiceService.php');
    assert_true(str_contains($r21aSource, '$col = self::PDF_ITEM_COLUMNS;') && !str_contains($r21aSource, "'width' => 26]"),
        'R21 invoice PDF: the header and the rows share one column table');
} finally {
    I18n::setLocale($r21aLocale);
}
