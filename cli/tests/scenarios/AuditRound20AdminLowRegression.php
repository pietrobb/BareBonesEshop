<?php

// Review round 20 (2026-11-07), low "admin, documents, languages":
// - a withdrawn line shipped in part (1 of 3) read "Už odoslané" (already sent) in the admin;
// - the invoice's VAT scenario ("non_vat_payer") and audit-log values ("shipped", {"status":"draft"}) were raw codes;
// - the PDF credit note had two "Dátum vystavenia" (its own date and the corrected invoice's);
// - in the English PDF invoice "Unit price excl. VAT" ran into "Qty";
// - an English page of the HU/CZ store named the seeded shipping and payment methods in Hungarian/Czech ("GLS futár");
// - the checkout summary written by storefront.js used the browser's format ("HUF 7,980") next to "5 490 Ft".
echo "\n--- R20: partly shipped withdrawal line, codes in words, credit note dates, EN invoice header, method names, price format ---\n";
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shared.php';
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shipments.php';
(require TEST_CLI_DIR . '/../migrations/20261022090000_withdrawal_requests.php')->up($pdo, Db::isSqlite());
Db::forgetSchema();

$r20aRoot = dirname(TEST_CLI_DIR);
$r20aLocale = I18n::locale();
$r20aDb = Db::get();
try {
    // 1. A withdrawn line of which 1 of 3 left: neither "already sent" nor "not sent yet"; both notices apply.
    I18n::setLocale('sk');
    $r20aProduct = (int)$r20aDb->query("SELECT id FROM products WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r20aNumber = 'R20A-' . bin2hex(random_bytes(3));
    $r20aDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id, locale)
        VALUES (?, 'processing', 'paid', 'R20A Buyer', 'r20a@example.test', 'card', '', 9000, 9000, 'eur', 'default', 'sk')")->execute([$r20aNumber]);
    $r20aOrderId = (int)$r20aDb->lastInsertId();
    $r20aDb->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, attrs) VALUES (?, ?, 'R20A-CUP', 'R20A Cup', 3, 3000, 9000, '{}')")
        ->execute([$r20aOrderId, $r20aProduct]);
    $r20aItem = (int)$r20aDb->lastInsertId();
    $r20aDb->prepare("INSERT INTO shipments (store_id, order_id, status, idempotency_key) VALUES ('default', ?, 'shipped', ?)")->execute([$r20aOrderId, 'r20a-' . $r20aOrderId]);
    $r20aDb->prepare("INSERT INTO shipment_items (shipment_id, order_item_id, sku, quantity, status) VALUES (?, ?, 'R20A-CUP', 1, 'shipped')")
        ->execute([(int)$r20aDb->lastInsertId(), $r20aItem]);
    $r20aDb->prepare("INSERT INTO withdrawal_requests (store_id, order_id, order_number, customer_name, customer_email, items, reason, received_at, confirmation_status)
        VALUES ('default', ?, ?, 'R20A Buyer', 'r20a@example.test', ?, '', ?, 'sent')")
        ->execute([$r20aOrderId, $r20aNumber, json_encode([$r20aItem => 3]), gmdate('Y-m-d H:i:s')]);
    ob_start();
    renderOrderWithdrawals((array)Orders::byId($r20aOrderId), Withdrawal::forOrder($r20aOrderId));
    $r20aAdmin = (string)ob_get_clean();
    $r20aPartly = strtr(I18n::t('admin.withdrawal.line_partly_sent'), ['{sent}' => '1', '{qty}' => '3']);
    assert_equals([true, false, false, true, true], [
        str_contains($r20aAdmin, esc($r20aPartly)), str_contains($r20aAdmin, esc(I18n::t('admin.withdrawal.line_sent'))),
        str_contains($r20aAdmin, esc(I18n::t('admin.withdrawal.line_not_sent'))),
        str_contains($r20aAdmin, esc(I18n::t('admin.withdrawal.notice'))), str_contains($r20aAdmin, esc(I18n::t('admin.withdrawal.notice_sent'))),
    ], 'R20 withdrawal: a line shipped 1 of 3 says so (was "Už odoslané"), with both the return and the do-not-ship notice');
    $r20aDb->prepare('DELETE FROM withdrawal_requests WHERE order_id = ?')->execute([$r20aOrderId]);
    $r20aDb->prepare('DELETE FROM shipment_items WHERE order_item_id = ?')->execute([$r20aItem]);
    $r20aDb->prepare('DELETE FROM shipments WHERE order_id = ?')->execute([$r20aOrderId]);
    $r20aDb->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$r20aOrderId]);
    $r20aDb->prepare('DELETE FROM orders WHERE id = ?')->execute([$r20aOrderId]);

    // 2. VAT scenario and audit values in words, in every admin language.
    $r20aMissing = [];
    foreach (['cs', 'de', 'en', 'es', 'hu', 'pl', 'sk'] as $r20aLang) {
        I18n::setLocale($r20aLang);
        foreach (['domestic', 'domestic_subthreshold', 'oss', 'reverse_charge', 'export', 'non_vat_payer'] as $r20aScenario) {
            $r20aKey = 'admin.vat_scenario.' . $r20aScenario;
            if (I18n::t($r20aKey) === $r20aKey || trim(I18n::t($r20aKey)) === '') $r20aMissing[] = "{$r20aLang}:{$r20aKey}";
        }
    }
    assert_equals([], $r20aMissing, 'R20 labels: every VAT scenario has a name in every admin language');
    $r20aOrders = (string)file_get_contents($r20aRoot . '/src/Entrypoints/Admin/Orders.php');
    assert_true(!str_contains($r20aOrders, "esc(\$inv['vat_scenario'] ?? '—')") && str_contains($r20aOrders, "adminCodeLabel('admin.vat_scenario.'"),
        'R20 labels: the order detail names the invoice VAT scenario, not its code');
    I18n::setLocale('en');
    $r20aProductValue = AuditLogLabels::value('product', '{"status":"draft","price":1290}');
    assert_equals([
        OrderStatusLabels::order('shipped'), OrderStatusLabels::payment('refunded'), I18n::t('admin.invoice.status.cancelled'), true, true, 'free text', '{"x":1}',
    ], [
        AuditLogLabels::value('order_status', 'shipped'), AuditLogLabels::value('payment_status', 'refunded'), AuditLogLabels::value('invoice_cancelled', 'cancelled'),
        str_contains($r20aProductValue, I18n::t('admin.th.status') . ': ' . I18n::t('admin.product.status.draft')) && !str_contains($r20aProductValue, '"status"'),
        str_contains($r20aProductValue, 'price: 1290'),
        AuditLogLabels::value('order_status', 'free text'), AuditLogLabels::value('product', '{"x":1}'),
    ], 'R20 audit log: status codes read as their labels; other values stay as stored');
    $r20aOps = (string)file_get_contents($r20aRoot . '/src/Entrypoints/Admin/Operations.php');
    assert_true(substr_count($r20aOps, 'AuditLogLabels::value((string)$log[\'entity\']') === 2, 'R20 audit log: the old and new value columns use the labels');

    // PDF text items: [font, size, x, y, text] → the texts with their extent in pt.
    $r20aMmToPt = 72 / 25.4;
    $r20aWidth = new ReflectionMethod(MinimalPdf::class, 'approxTextWidth');
    $r20aMeasure = new MinimalPdf();
    $r20aTexts = static function (string $pdf) use ($r20aWidth, $r20aMeasure, $r20aMmToPt): array {
        preg_match_all('~BT /(F[12]) ([\d.]+) Tf ([\d.]+) ([\d.]+) Td \(((?:\\\\.|[^\\\\)])*)\) Tj ET~', $pdf, $items, PREG_SET_ORDER);
        $out = [];
        foreach ($items as [, $font, $size, $x, $y, $text]) {
            $plain = mb_convert_encoding(stripcslashes($text), 'UTF-8', 'Windows-1252');
            $w = (float)$r20aWidth->invoke($r20aMeasure, $plain, (float)$size, $font === 'F2') * $r20aMmToPt;
            $out[] = ['bold' => $font === 'F2', 'size' => (float)$size, 'x' => (float)$x, 'end' => (float)$x + $w, 'y' => (float)$y, 'text' => $plain];
        }
        return $out;
    };
    $r20aOverlaps = static function (array $texts): array {
        $out = [];
        foreach ($texts as $i => $a) {
            foreach (array_slice($texts, $i + 1) as $b) {
                $sameLine = abs($a['y'] - $b['y']) < max($a['size'], $b['size']) * 0.8;
                if ($sameLine && $a['x'] < $b['end'] && $b['x'] < $a['end']) $out[] = $a['text'] . ' × ' . $b['text'];
            }
        }
        return $out;
    };
    $r20aRender = new ReflectionMethod(InvoiceService::class, 'renderMinimalPdf');
    $r20aPdf = static fn(array $invoice): string => (string)$r20aRender->invoke(null,
        $invoice + ['currency' => 'eur', 'issued_at' => '2032-04-12', 'delivery_date' => '2032-04-12', 'due_date' => '2032-04-26',
            'total_net' => 1626, 'total_vat' => 374, 'total_gross' => 2000, 'reverse_charge_note_key' => ''],
        [['name' => 'Book', 'qty' => 1, 'rate' => 23, 'net' => 1626, 'gross' => 2000]],
        ['name' => 'R20 Seller', 'country' => 'SK'], ['name' => 'R20 Buyer', 'country' => 'SK'], []);

    // 3. The credit note's own date and the corrected invoice's date have different labels.
    $r20aSourceNumber = 'R20A-2032-' . bin2hex(random_bytes(3));
    $r20aDb->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount,
        discount_amount, total, currency, status, payment_status, payment_method, store_id, created_at)
        VALUES (?, 'R20 Buyer', 'r20a@example.test', '', '{}', 2000, 0, 0, 0, 2000, 'eur', 'paid', 'paid', 'bank', 'default', ?)")
        ->execute(['R20A-' . bin2hex(random_bytes(5)), gmdate('Y-m-d H:i:s')]);
    $r20aCreditOrder = (int)$r20aDb->lastInsertId();
    $r20aDb->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, delivery_date, due_date, pdf_path, total_net, total_vat, total_gross, currency,
            vat_breakdown, customer_snapshot, seller_snapshot, payment_method, locale, status, download_token, vat_scenario, type, refers_to_invoice_id)
        VALUES (?, 'default', ?, '2032-04-01', '2032-04-01', '2032-04-15', 'x.pdf', 1626, 374, 2000, 'eur', '{}', '{}', '{}', 'bank', 'sk', 'issued', ?, 'domestic', 'invoice', NULL)")
        ->execute([$r20aCreditOrder, $r20aSourceNumber, bin2hex(random_bytes(8))]);
    $r20aSourceId = (int)$r20aDb->lastInsertId();
    $r20aCredit = array_column($r20aTexts($r20aPdf(['type' => 'credit_note', 'number' => 'R20A-DOB-1', 'locale' => 'sk', 'order_id' => $r20aCreditOrder,
        'refers_to_invoice_id' => $r20aSourceId])), 'text');
    $r20aDated = array_values(array_filter($r20aCredit, static fn(string $t): bool => str_starts_with($t, 'Dátum vystavenia')));
    assert_equals(['Dátum vystavenia: ' . I18n::date('2032-04-12', 'sk'), 'Dátum vystavenia faktúry: ' . I18n::date('2032-04-01', 'sk')], $r20aDated,
        'R20 credit note PDF: its own issue date and the corrected invoice\'s date are labelled apart (was "Dátum vystavenia" twice)');
    $r20aDb->prepare('DELETE FROM invoices WHERE id = ?')->execute([$r20aSourceId]);
    $r20aDb->prepare('DELETE FROM orders WHERE id = ?')->execute([$r20aCreditOrder]);

    // 4. The items header of every invoice language: no column label runs into the next one.
    $r20aHeaderOverlaps = [];
    foreach (['en', 'es', 'de', 'sk', 'cs', 'hu', 'pl'] as $r20aLang) {
        $r20aHeader = array_values(array_filter($r20aTexts($r20aPdf(['type' => 'invoice', 'number' => 'R20A-INV-' . $r20aLang, 'locale' => $r20aLang, 'order_id' => 0])),
            static fn(array $t): bool => $t['bold'] && $t['size'] === 8.0));
        if (count($r20aHeader) < 6) $r20aHeaderOverlaps[] = "{$r20aLang}: header not found";
        foreach ($r20aOverlaps($r20aHeader) as $r20aClash) $r20aHeaderOverlaps[] = "{$r20aLang}: {$r20aClash}";
    }
    assert_equals([], $r20aHeaderOverlaps, 'R20 invoice PDF: the items header labels fit their columns ("Unit price excl. VAT" ran into "Qty")');

    // 5. Method names seeded into the CZ/HU/PL stores read in the page language; in the seeded language and when the
    //    merchant renamed the method they stay as stored.
    assert_equals(
        [I18n::tIn('methods.shipping.gls', 'en'), 'GLS futár', I18n::tIn('methods.payment.cod', 'en'), 'Dobírka', I18n::tIn('methods.shipping.pickup', 'en'),
            'Bankkártyás fizetés', I18n::tIn('methods.payment.card', 'en'), 'Packeta', 'GLS R20 Express'],
        [MethodLabels::name('shipping', 'gls', 'GLS futár', 'en'), MethodLabels::name('shipping', 'gls', 'GLS futár', 'hu'),
            MethodLabels::name('payment', 'cod', 'Dobírka', 'en'), MethodLabels::name('payment', 'cod', 'Dobírka', 'cs'),
            MethodLabels::name('shipping', 'pickup', 'Odbiór osobisty', 'en'),
            MethodLabels::name('payment', 'card', 'Bankkártyás fizetés', 'hu'), MethodLabels::name('payment', 'card', 'Bankkártyás fizetés', 'en'),
            MethodLabels::name('shipping', 'packeta', 'Packeta', 'en'), MethodLabels::name('shipping', 'gls', 'GLS R20 Express', 'en')],
        'R20 methods: an English page of the HU/CZ/PL store names the seeded methods in English ("GLS futár" stayed Hungarian)');
    I18n::setLocale('en');
    assert_equals(['GLS courier', 'GLS futár'], [MethodLabels::name('shipping', 'gls', 'GLS futár'), (static function (): string {
        I18n::setLocale('hu');
        return MethodLabels::name('shipping', 'gls', 'GLS futár');
    })()], 'R20 methods: without a locale argument the current page language decides');

    // 6. storefront.js writes amounts with the server's format (symbol, separators, decimals), not the browser's.
    $r20aJs = (string)file_get_contents($r20aRoot . '/themes/default/assets/storefront.js');
    $r20aLayout = (string)file_get_contents($r20aRoot . '/src/Entrypoints/Storefront/Layout.php');
    assert_true(!str_contains($r20aJs, 'toLocaleString') && str_contains($r20aJs, 'config.priceFormat')
        && str_contains($r20aLayout, "'priceFormat' => I18n::priceFormat("),
        'R20 checkout summary: storefront.js formats with the server\'s priceFormat, not Intl');
    $r20aHuf = I18n::priceFormat('huf');
    $r20aEur = I18n::priceFormat('eur');
    assert_equals(['5 490 Ft', '1 234,50 €', 0, 2],
        [number_format(5490, $r20aHuf['decimals'], $r20aHuf['decimal'], $r20aHuf['thousands']) . ' ' . $r20aHuf['symbol'],
            number_format(1234.5, $r20aEur['decimals'], $r20aEur['decimal'], $r20aEur['thousands']) . ' ' . $r20aEur['symbol'],
            $r20aHuf['decimals'], $r20aEur['decimals']],
        'R20 checkout summary: priceFormat describes what I18n::price writes');
    assert_equals([I18n::price(549000, 'huf'), I18n::price(123450, 'eur')], ['5 490 Ft', '1 234,50 €'],
        'R20 checkout summary: I18n::price still writes "5 490 Ft" and "1 234,50 €"');
} finally {
    I18n::setLocale($r20aLocale);
}
