<?php

// Review round 30 (2026-11-11, main @ e41e8e0b), lows of invoices, texts and logs (goal #418): a malformed BIC in the
// invoice QR, an empty seller line in the CS/HU/PL e-mail, English audit notes, "tú" in Spanish e-mails, two German
// words for the payment reference, "1 active product(s)", English import row errors, `'pass' => 'x'` in the log.
echo "\n--- R30: invoice QR BIC, seller VAT id line, audit and import texts, ES usted, DE reference, plural, log masking ---\n";

$r30tLocale = I18n::locale();
$r30tRoot = dirname(TEST_CLI_DIR);
try {
    // 1. A malformed BIC is left out of the invoice QR as the e-mail's QR leaves it out; a valid one stays.
    $r30tDraw = new ReflectionMethod(InvoiceService::class, 'drawPaymentQr');
    $r30tInvoice = ['due_date' => '2026-11-20', 'total_gross' => 4590, 'currency' => 'eur', 'number' => 'FA-R30T-1', 'order_id' => 93];
    $r30tPdf = static function (string $bic) use ($r30tDraw, $r30tInvoice): string {
        $pdf = new MinimalPdf();
        $r30tDraw->invoke(null, $pdf, $r30tInvoice, ['iban' => 'SK3112000000198742637541', 'bic' => $bic, 'name' => 'R30T Shop s.r.o.'], ['country' => 'DE'], 200.0);
        return $pdf->render();
    };
    $r30tNone = $r30tPdf('');
    assert_equals([true, false], [$r30tPdf('NOT A BIC!') === $r30tNone, $r30tPdf('subaskbx') === $r30tNone],
        'R30 invoice: a malformed BIC is not put into the SEPA QR (it was, while the e-mail\'s QR left it out); a valid one is');

    // 2. The seller line of the order e-mail shows the VAT id in every language (CS/HU/PL had the line break only).
    $r30tNoVat = [];
    foreach (['en', 'sk', 'cs', 'de', 'es', 'hu', 'pl'] as $r30tLang) {
        $r30tMail = (string)file_get_contents("{$r30tRoot}/templates/emails/{$r30tLang}/order_confirmation.html");
        if (str_contains($r30tMail, '{{#if seller_ids}}') && !str_contains($r30tMail, '{{seller_ic_dph}}')) $r30tNoVat[] = $r30tLang;
    }
    assert_equals([], $r30tNoVat, 'R30 mail: a seller with only a VAT id gets it on the seller line, not an empty line');

    // 3. Audit notes of the provider's refund during the admin's one, two-factor setup and store permissions.
    I18n::setLocale('sk');
    $r30tNotes = [
        AuditLogLabels::note('order_refund', 1, 'stripe refund re_r30 of ' . AuditLogLabels::amount(500, 'eur') . ' booked while the refund of '
            . AuditLogLabels::amount(1290, 'eur') . ' is in flight'),
        AuditLogLabels::note('admin_user', 1, 'TOTP enrolled and recovery codes rotated'),
        AuditLogLabels::note('admin_user', 1, 'Store permissions changed'),
    ];
    assert_equals([], array_values(array_filter($r30tNotes, static fn(string $n): bool => preg_match('/\b(booked|in flight|TOTP|permissions)\b/', $n) === 1)),
        'R30 audit log: the refund booked during an in-flight one, TOTP setup and store permissions are in the admin\'s language (' . implode(' | ', $r30tNotes) . ')');
    assert_true(str_contains($r30tNotes[0], 're_r30') && str_contains($r30tNotes[0], '5,00') && str_contains($r30tNotes[0], '12,90'),
        'R30 audit log: the translated refund note keeps the refund id and both amounts');

    // 4. Import errors in the admin's language, with line, SKU and value; an unknown error stays as written.
    assert_equals([
        'Riadok 7: nesprávny počet stĺpcov',
        'Riadok 9 (SKU: R30-A): Neplatná cena: 12,345 (nejednoznačná: napíšte napr. 199.00 alebo 199000).',
        'Riadok 10 (SKU: R30-B): SKU R30-B už používa variant.',
        'CSV je prázdne alebo v neplatnom formáte.',
        'Riadok 11 (SKU: R30-C): SQLSTATE[HY000]: database is locked',
    ], array_map([ImportErrorLabels::class, 'text'], [
        'Line 7: column count mismatch',
        'Line 9 (SKU: R30-A): Not a price: 12,345 (ambiguous: write e.g. 199.00 or 199000)',
        "Line 10 (SKU: R30-B): SKU 'R30-B' is already used by a variant",
        'Empty CSV or invalid format',
        'Line 11 (SKU: R30-C): SQLSTATE[HY000]: database is locked',
    ]), 'R30 import: row and file errors are shown in the admin\'s language (were English); an unknown error keeps its text');

    // 5. The unpriced-products warning takes the plural form of its count.
    assert_equals([true, true], [
        str_contains(strtr(I18n::plural('admin.stores.unpriced', 1), ['{currency}' => 'PLN', '{skus}' => 'X']), '1 aktívny'),
        str_contains(strtr(I18n::plural('admin.stores.unpriced', 5), ['{currency}' => 'PLN', '{skus}' => 'X']), '5 aktívnych'),
    ], 'R30 SK admin: "1 aktívny produkt…" / "5 aktívnych produktov…" (was "1 aktívnych produktov")');

    // 6. Every new text is in every language.
    $r30tMissing = [];
    $r30tKeys = ['admin.audit.note.refund_in_flight', 'admin.audit.note.mfa_enrolled', 'admin.audit.note.permissions',
        'admin.import.err.row', 'admin.import.err.columns', 'admin.import.err.status', 'admin.stores.unpriced_one', 'admin.stores.unpriced_many'];
    foreach (['en', 'sk', 'cs', 'de', 'es', 'hu', 'pl'] as $r30tLang) {
        $r30tTexts = require "{$r30tRoot}/lang/{$r30tLang}.php";
        foreach ($r30tKeys as $r30tKey) if (trim((string)($r30tTexts[$r30tKey] ?? '')) === '') $r30tMissing[] = "{$r30tLang}:{$r30tKey}";
    }
    assert_equals([], $r30tMissing, 'R30 i18n: the new audit, import and unpriced texts exist in every language');

    // 7. Spanish e-mails address the customer with "usted"; German calls the payment reference one name.
    $r30tTu = [];
    foreach (glob("{$r30tRoot}/templates/emails/es/*.html") ?: [] as $r30tFile) {
        if (preg_match('/\b(copia este|Utiliza|tu pedido|tu cuenta|tu contraseña|Haz clic|puedes)\b/iu', (string)file_get_contents($r30tFile)) === 1) $r30tTu[] = basename($r30tFile);
    }
    assert_equals([], $r30tTu, 'R30 ES mail: no "tú" form in the Spanish e-mails');
    $r30tDe = (string)file_get_contents("{$r30tRoot}/src/InvoiceService.php");
    assert_true(str_contains($r30tDe, "'variable_symbol' => 'Zahlungsreferenz'") && !str_contains($r30tDe, "'variable_symbol' => 'Verwendungszweck'"),
        'R30 DE: the invoice and the order e-mail name the payment reference the same way');

    // 8. The log masks a secret written as a PHP array (var_export / print_r of a config).
    $r30tMask = new ReflectionMethod(Logger::class, 'maskSecrets');
    assert_equals([
        "array ( 'smtp_pass' => '[REDACTED]', 'api_key' => '[REDACTED]', 'user' => 'bob', )",
        '[pass] => [REDACTED]',
        "'passive' => 'keep'",
    ], [
        $r30tMask->invoke(null, "array ( 'smtp_pass' => 'hunter2 x', 'api_key' => 'k123', 'user' => 'bob', )"),
        $r30tMask->invoke(null, '[pass] => s3cret'),
        $r30tMask->invoke(null, "'passive' => 'keep'"),
    ], "R30 log: 'pass' => 'x' and print_r's [pass] => x are masked (were written as is); other keys untouched");
} catch (Throwable $e) {
    assert_true(false, 'R30 texts (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    I18n::setLocale($r30tLocale);
}
