<?php

// Review round 19 (2026-11-06), low "admin UX and documents":
// - the admin showed raw codes: audit-log entities ("payment_link_sent"), e-mail templates ("order_payment_link") and the
//   invoice type ("credit_note");
// - "Create and send" / "Ship" saved a parcel without a carrier (covered in AuditRound11FulfillmentRegression);
// - at 390 px the account's orders scrolled the whole page sideways and the admin's store switcher shrank to nothing;
// - the PDF credit note's title ran into the dates ("Factura rectificativa n.º …" is ~130 mm at 18 pt);
// - "Some field is too long" did not say which field;
// - an English delivery note named the country "Slovensko".

$r19aRoot = dirname(TEST_CLI_DIR);
$r19aLocale = I18n::locale();
$r19aSession = $_SESSION ?? [];
try {
    // 1. Codes in words, in every admin language.
    $r19aEntities = [];
    foreach (glob($r19aRoot . '/src/{,*/,*/*/}*.php', GLOB_BRACE) ?: [] as $r19aFile) {
        preg_match_all("~AuditLog::log\\('([a-z_]+)'~", (string)file_get_contents($r19aFile), $r19aM);
        foreach ($r19aM[1] as $r19aEntity) $r19aEntities[$r19aEntity] = true;
    }
    // Entities passed as variables (content, B2B, store configuration tables).
    foreach (['content_menu', 'content_banner', 'content_redirect', 'content_landing_page', 'customer_group', 'company',
        'shipping_methods', 'shipping_rates', 'shipping_zones', 'shipping_zone_countries', 'payment_methods',
        'payment_method_countries', 'payment_method_shipping'] as $r19aEntity) $r19aEntities[$r19aEntity] = true;
    $r19aTemplates = array_map(static fn(string $p): string => basename($p, '.html'), glob($r19aRoot . '/templates/emails/en/*.html') ?: []);
    assert_true(count($r19aEntities) >= 40 && count($r19aTemplates) >= 20, 'R19 labels setup: audit entities and e-mail templates found');
    $r19aMissing = [];
    foreach (['cs', 'de', 'en', 'es', 'hu', 'pl', 'sk'] as $r19aLang) {
        I18n::setLocale($r19aLang);
        $r19aKeys = array_merge(
            array_map(static fn(string $e): string => 'admin.audit.entity.' . $e, array_keys($r19aEntities)),
            array_map(static fn(string $t): string => 'admin.email.tpl.' . $t, $r19aTemplates),
            ['admin.invoice.type.invoice', 'admin.invoice.type.credit_note']);
        foreach ($r19aKeys as $r19aKey) {
            if (I18n::t($r19aKey) === $r19aKey || trim(I18n::t($r19aKey)) === '') $r19aMissing[] = "{$r19aLang}:{$r19aKey}";
        }
    }
    assert_equals([], $r19aMissing, 'R19 labels: every audit entity, e-mail template and invoice type has a name in every admin language');
    $r19aSources = implode("\n", array_map(static fn(string $f): string => (string)file_get_contents($r19aRoot . '/src/Entrypoints/Admin/' . $f),
        ['Operations.php', 'Orders.php', 'EmailTemplates.php']));
    assert_true(!str_contains($r19aSources, "esc(\$log['entity'])") && !str_contains($r19aSources, "esc(\$inv['type']")
        && !str_contains($r19aSources, "esc(\$em['template'])") && !str_contains($r19aSources, "['template' => \$template,"),
        'R19 labels: the audit log, the order detail and the template editor show the names, not the codes');
    I18n::setLocale('sk');
    assert_equals(['potvrdenie objednávky', 'order_unknown_r19'], [EmailTemplates::label('order_confirmation'), EmailTemplates::label('order_unknown_r19')],
        'R19 labels: the template saved/reset message names the template in words (an unknown code stays the code)');

    // 2. 390 px: the account page's grid items may shrink (the table scrolls in its box); the store switcher keeps a width.
    $r19aCss = (string)file_get_contents($r19aRoot . '/themes/default/assets/storefront.css');
    assert_true(str_contains($r19aCss, '.account-page>*{min-width:0}') && str_contains($r19aCss, '.account-table-scroll{overflow-x:auto}'),
        'R19 390 px: the account orders table scrolls inside its card, not the whole page');
    $r19aAdmin = (string)file_get_contents($r19aRoot . '/src/Entrypoints/Admin.php');
    assert_true((bool)preg_match('~@media\(max-width:760px\)\{[^\n]*nav \.store-switcher\{order:-1;flex:0 0 auto[^\n]*nav \.store-switcher select\{flex:0 0 auto;min-width:9rem\}~', $r19aAdmin),
        'R19 390 px: the admin store switcher comes first in the mobile navigation and keeps its width');

    // 3. The field that is too long is named.
    $_SESSION = ['cart' => []];
    I18n::setLocale('sk');
    $r19aLong = CheckoutService::execute(['name' => 'R19', 'email' => 'r19a@example.test', 'country' => 'SK', 'street' => str_repeat('x', 201)]);
    $r19aMessage = (string)($r19aLong['error'] ?? '');
    assert_true(($r19aLong['error_code'] ?? '') === 'checkout.error.field_too_long' && str_contains($r19aMessage, I18n::t('street'))
        && str_contains($r19aMessage, '200') && $r19aMessage !== I18n::t('checkout.error.field_too_long'),
        'R19 checkout: "field too long" says which field and its maximum (' . $r19aMessage . ')');

    // 4. The delivery note names the country in the note's language when printed in English.
    $r19aNote = ['address' => ['country' => 'sk'], 'order' => ['customer_name' => 'Jana R19']];
    I18n::setLocale('en');
    $r19aEn = DeliveryNote::recipientLines($r19aNote);
    I18n::setLocale('sk');
    $r19aSk = DeliveryNote::recipientLines($r19aNote);
    assert_equals([true, false, true], [in_array('Slovakia', $r19aEn, true), in_array('Slovensko', $r19aEn, true), in_array('Slovensko', $r19aSk, true)],
        'R19 delivery note: an English note says Slovakia, a Slovak one Slovensko');

    // 5. The credit note's header: no two texts on one baseline overlap.
    $r19aOrder = (int)(function () use ($pdo): int {
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount,
            discount_amount, total, currency, status, payment_status, payment_method, store_id, created_at)
            VALUES (?, 'R19 Buyer', 'r19a@example.test', '', '{}', 6000, 0, 0, 0, 6000, 'eur', 'paid', 'paid', 'bank', 'default', ?)")
            ->execute(['R19A-' . bin2hex(random_bytes(5)), gmdate('Y-m-d H:i:s')]);
        return (int)$pdo->lastInsertId();
    })();
    $r19aSourceNumber = 'R19A-2032-' . bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, delivery_date, due_date, pdf_path, total_net, total_vat, total_gross, currency,
            vat_breakdown, customer_snapshot, seller_snapshot, payment_method, locale, status, download_token, vat_scenario, type, refers_to_invoice_id)
        VALUES (?, 'default', ?, '2032-03-10', '2032-03-10', '2032-03-24', 'x.pdf', 4878, 1122, 6000, 'eur', '{}', '{}', '{}', 'bank', 'es', 'issued', ?, 'domestic', 'invoice', NULL)")
        ->execute([$r19aOrder, $r19aSourceNumber, bin2hex(random_bytes(8))]);
    $r19aSourceId = (int)$pdo->lastInsertId();
    $r19aRender = new ReflectionMethod(InvoiceService::class, 'renderMinimalPdf');
    $r19aPdf = (string)$r19aRender->invoke(null,
        ['type' => 'credit_note', 'number' => 'R19A-DOB-2032-000123', 'locale' => 'es', 'currency' => 'eur', 'order_id' => $r19aOrder,
            'issued_at' => '2032-03-12', 'delivery_date' => '2032-03-12', 'due_date' => '2032-03-26', 'refers_to_invoice_id' => $r19aSourceId,
            'total_net' => -1626, 'total_vat' => -374, 'total_gross' => -2000, 'reverse_charge_note_key' => ''],
        [['name' => 'Libro', 'qty' => -1, 'rate' => 23, 'net' => -1626, 'gross' => -2000]],
        ['name' => 'R19 Seller', 'country' => 'SK'], ['name' => 'R19 Buyer', 'country' => 'SK'], []);
    $r19aWidth = new ReflectionMethod(MinimalPdf::class, 'approxTextWidth');
    $r19aMeasure = new MinimalPdf();
    $r19aMmToPt = 72 / 25.4;
    $r19aTop = (297 - 43) * $r19aMmToPt; // everything above the line under the header (y ≤ 43 mm)
    preg_match_all('~BT /(F[12]) ([\d.]+) Tf ([\d.]+) ([\d.]+) Td \(((?:\\\\.|[^\\\\)])*)\) Tj ET~', $r19aPdf, $r19aItems, PREG_SET_ORDER);
    $r19aHeader = [];
    foreach ($r19aItems as [, $r19aFont, $r19aSize, $r19aX, $r19aY, $r19aText]) {
        if ((float)$r19aY < $r19aTop) continue;
        $r19aPlain = mb_convert_encoding(stripcslashes($r19aText), 'UTF-8', 'Windows-1252');
        $r19aW = (float)$r19aWidth->invoke($r19aMeasure, $r19aPlain, (float)$r19aSize, $r19aFont === 'F2') * $r19aMmToPt;
        $r19aHeader[] = ['x' => (float)$r19aX, 'end' => (float)$r19aX + $r19aW, 'y' => (float)$r19aY, 'size' => (float)$r19aSize, 'text' => $r19aPlain];
    }
    $r19aOverlaps = [];
    foreach ($r19aHeader as $r19aI => $r19aA) {
        foreach (array_slice($r19aHeader, $r19aI + 1) as $r19aB) {
            $r19aSameLine = abs($r19aA['y'] - $r19aB['y']) < max($r19aA['size'], $r19aB['size']) * 0.8;
            if ($r19aSameLine && $r19aA['x'] < $r19aB['end'] && $r19aB['x'] < $r19aA['end']) $r19aOverlaps[] = $r19aA['text'] . ' × ' . $r19aB['text'];
        }
    }
    $r19aTexts = array_column($r19aHeader, 'text');
    assert_true(count($r19aHeader) >= 5 && (bool)array_filter($r19aTexts, static fn(string $t): bool => str_contains($t, $r19aSourceNumber)),
        'R19 credit note PDF setup: the header holds the title, the dates and the corrected invoice (' . implode(' | ', $r19aTexts) . ')');
    assert_equals([], $r19aOverlaps, 'R19 credit note PDF: no text in the header runs into another');
} finally {
    I18n::setLocale($r19aLocale);
    $_SESSION = $r19aSession;
}
