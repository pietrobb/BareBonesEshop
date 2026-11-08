<?php

// R23 UX: the payment QR in the payer's standard. Only PAY by square (Slovak, EUR) existed: a German customer's banking
// app could not read it and a CZK order got no QR at all. Now CZK → Czech SPAYD, EUR from Slovakia → PAY by square,
// EUR from elsewhere → the SEPA EPC code (GiroCode); on the order pages, as a PNG with the e-mails, and on the invoice.
echo "\n--- R23 UX: payment QR by currency and country (SPAYD, PAY by square, SEPA EPC) ---\n";

$r23qPayment = ['iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX', 'amount' => 12345, 'currency' => 'eur',
    'variable_symbol' => '4321', 'due_date' => '20261027', 'note' => 'ORD-1', 'beneficiary' => 'Woodle s.r.o. Žilina'];
assert_equals(['format' => PaymentQr::EPC, 'payload' => "BCD\n002\n1\nSCT\nCOBADEFFXXX\nWoodle s.r.o. Zilina\nDE89370400440532013000\nEUR123.45\n\n\n4321 ORD-1"],
    PaymentQr::for($r23qPayment, 'DE'),
    'R23 QR: EUR from Germany is the EPC069-12 SEPA credit transfer code (BCD 002, UTF-8, SCT, BIC, name, IBAN, amount, remittance with the reference) — was PAY by square');
assert_equals(['format' => PaymentQr::PAY_BY_SQUARE, 'payload' => PayBySquare::encode($r23qPayment)], PaymentQr::for($r23qPayment, 'sk'),
    'R23 QR: EUR from Slovakia stays PAY by square');
assert_equals(['format' => '', 'payload' => ''], PaymentQr::for(['beneficiary' => ''] + $r23qPayment, 'AT'),
    'R23 QR: no EPC code without the beneficiary name the standard requires');
$r23qCzk = ['iban' => 'CZ65 0800 0000 1920 0014 5399', 'bic' => 'GIBACZPX', 'amount' => 150000, 'currency' => 'czk',
    'variable_symbol' => '77', 'due_date' => '20261027', 'note' => 'Objednávka *ORD-77', 'beneficiary' => 'A136 CZ s.r.o.'];
assert_equals(['format' => PaymentQr::SPAYD, 'payload' => 'SPD*1.0*ACC:CZ6508000000192000145399+GIBACZPX*AM:1500.00*CC:CZK*DT:20261027*X-VS:77*MSG:Objednavka ORD-77*RN:A136 CZ s.r.o.'],
    PaymentQr::for($r23qCzk, 'SK'),
    'R23 QR: CZK is the Czech SPAYD (ACC IBAN+BIC, AM, CC, DT, X-VS, ASCII message without the * separator, RN) whatever the country — was no QR');
assert_equals(['format' => '', 'payload' => ''], PaymentQr::for(['currency' => 'huf'] + $r23qCzk, 'HU'),
    'R23 QR: a currency without a common standard (HUF) keeps the text instructions only');

// The instructions pick the format from the order's address, else the store's country.
$r23qOrder = ['id' => 91, 'order_number' => 'ORD-R23Q', 'total' => 2590, 'currency' => 'eur', 'payment_method' => 'bank_transfer',
    'payment_method_snapshot' => '', 'payment_status' => 'pending', 'status' => 'pending', 'created_at' => '2026-10-13 10:00:00'];
$r23qLegal = ['name' => 'R23Q Shop s.r.o.', 'iban' => 'SK31 1200 0000 1987 4263 7541', 'bic' => 'SUBASKBX', 'country' => 'SK'];
$r23qDe = BankTransferInstructions::forOrder(['shipping_addr' => json_encode(['country' => 'DE'])] + $r23qOrder, $r23qLegal);
$r23qSk = BankTransferInstructions::forOrder(['shipping_addr' => json_encode(['country' => 'SK'])] + $r23qOrder, $r23qLegal);
$r23qNone = BankTransferInstructions::forOrder($r23qOrder, ['country' => 'AT'] + $r23qLegal);
assert_equals([PaymentQr::EPC, '', true, I18n::t('bank_transfer.qr_hint_epc')],
    [$r23qDe['qr_format'] ?? '', $r23qDe['pay_by_square'] ?? '-', str_contains((string)($r23qDe['qr_svg'] ?? ''), 'aria-label="' . htmlspecialchars(I18n::t('bank_transfer.qr_label_epc')) . '"'),
        $r23qDe['qr_hint'] ?? ''],
    'R23 QR: a German order of a Slovak store gets the SEPA code, labelled and explained as such (was the Slovak PAY by square)');
assert_equals([PaymentQr::PAY_BY_SQUARE, true, PaymentQr::EPC], [$r23qSk['qr_format'] ?? '', ($r23qSk['pay_by_square'] ?? '') !== '', $r23qNone['qr_format'] ?? ''],
    'R23 QR: a Slovak address keeps PAY by square; an order without an address follows the store\'s country (AT → SEPA)');
$r23qMissing = [];
foreach (['en', 'sk', 'cs', 'de', 'es', 'hu', 'pl'] as $r23qLang) {
    $r23qTexts = require dirname(TEST_CLI_DIR) . "/lang/{$r23qLang}.php";
    foreach (['qr_hint_epc', 'qr_label_epc', 'qr_hint_spayd', 'qr_label_spayd'] as $r23qKey) {
        if (trim((string)($r23qTexts['bank_transfer.' . $r23qKey] ?? '')) === '') $r23qMissing[] = "{$r23qLang}:{$r23qKey}";
    }
}
assert_equals([], $r23qMissing, 'R23 QR: the SEPA and SPAYD labels and hints are translated in every admin/storefront language');

// The e-mails carry the QR as a PNG attachment (no inline SVG in mail clients).
$r23qPng = BankTransferInstructions::qrAttachment(['shipping_addr' => json_encode(['country' => 'DE'])] + $r23qOrder, $r23qLegal);
$r23qBytes = (string)($r23qPng['data'] ?? '');
$r23qHeader = strlen($r23qBytes) > 33 ? unpack('Nwidth/Nheight/Cdepth/Ccolour', substr($r23qBytes, 16, 10)) : [];
$r23qMatrix = QrCode::matrix((string)$r23qDe['qr_payload']);
$r23qDim = (count($r23qMatrix) + 8) * 6;
$r23qIdat = strpos($r23qBytes, 'IDAT');
$r23qRaw = $r23qIdat === false ? '' : (string)@gzuncompress(substr($r23qBytes, $r23qIdat + 4, unpack('N', substr($r23qBytes, $r23qIdat - 4, 4))[1]));
// Pixel (x, y) of the image: scanline y, after its filter byte. The finder pattern's corner module (0, 0) is dark.
$r23qPixel = static fn(int $x, int $y): int => ord($r23qRaw[$y * ($r23qDim + 1) + 1 + $x] ?? "\x80");
assert_equals(['image/png', 'payment-qr-ORD-R23Q.png', "\x89PNG", $r23qDim, $r23qDim, 8, 0, $r23qDim * ($r23qDim + 1), 255, 0],
    [$r23qPng['content_type'] ?? '', $r23qPng['filename'] ?? '', substr($r23qBytes, 0, 4), $r23qHeader['width'] ?? 0, $r23qHeader['height'] ?? 0,
        $r23qHeader['depth'] ?? 0, $r23qHeader['colour'] ?? -1, strlen($r23qRaw), $r23qPixel(0, 0), $r23qPixel(24, 24)],
    'R23 QR: the e-mail attachment is a greyscale PNG of the same symbol with a 4-module white quiet zone');
assert_true(BankTransferInstructions::qrAttachment(['currency' => 'huf'] + $r23qOrder, $r23qLegal) === null,
    'R23 QR: no attachment when the order has no QR');
$r23qJobs = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/WorkerJobs.php');
assert_true(preg_match("/in_array\\(\\\$template, \\['order_confirmation', 'bank_transfer_reminder'\\], true\\) && \\\$order \\? BankTransferInstructions::qrAttachment\\(\\\$order\\)/", $r23qJobs) === 1,
    'R23 QR: the order confirmation and the transfer reminder attach it');

// The invoice still to pay by transfer carries it too, at the right of the payment lines.
$r23qDraw = new ReflectionMethod(InvoiceService::class, 'drawPaymentQr');
$r23qInvoice = ['due_date' => '2026-10-27', 'total_gross' => 12345, 'currency' => 'eur', 'number' => 'FA-R23Q-1', 'order_id' => 91];
$r23qPdf = new MinimalPdf();
$r23qBottom = $r23qDraw->invoke(null, $r23qPdf, $r23qInvoice, ['iban' => 'SK3112000000198742637541', 'name' => 'R23Q Shop s.r.o.'], ['country' => 'DE'], 200.0);
$r23qRects = substr_count($r23qPdf->render(), " re f\n");
$r23qHufPdf = new MinimalPdf();
$r23qHuf = $r23qDraw->invoke(null, $r23qHufPdf, ['currency' => 'huf'] + $r23qInvoice, ['iban' => 'SK3112000000198742637541', 'name' => 'R23Q Shop s.r.o.'], ['country' => 'HU'], 200.0);
assert_equals([234.0, true, null, 0], [$r23qBottom, $r23qRects > 50, $r23qHuf, substr_count($r23qHufPdf->render(), " re f\n")],
    "R23 QR: the invoice draws a 30 mm QR ({$r23qRects} filled runs) and continues below it; none for a currency without a standard");
$r23qService = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/InvoiceService.php');
assert_true(preg_match('/orderPaysOnDelivery\(.*?\$L\[\'iban\'\].*?self::drawPaymentQr\(/s', $r23qService) === 1
    && preg_match('/if \(\$settled\) \{[^}]*drawPaymentQr/s', $r23qService) === 0,
    'R23 QR: only an invoice that shows the IBAN to pay to (not paid, not a credit note, not cash on delivery) gets the QR');
