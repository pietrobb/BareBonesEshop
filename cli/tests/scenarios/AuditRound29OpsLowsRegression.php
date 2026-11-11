<?php

// Review round 29 (2026-11-11, main @ d9dc6722), lows of updater, mail and invoice (goal #412):
// - logs masked SMTP_PASS / smtp_pass only as part of "password"; a bare "pass" key went out in clear;
// - SMTP_HOST=::1 (an IPv6 address) connected nowhere: the socket address needs brackets;
// - the order confirmation of a shop without some IDs printed "IČO: , DIČ:";
// - the payment QR went out as an attachment the e-mail never mentioned;
// - merchant e-mails were written in the customer's language;
// - an invoice printed a payment QR for an IBAN with wrong check digits;
// - a very long word in an item name took minutes to wrap in the PDF (every prefix re-measured);
// - the backup log written with LOCK_EX failed on a file system without file locks, from the CLI's catch: a fatal error.
// (The updater's "theme would break checkout" for a probe that could not check is in test-theme-upgrade-gate.php.)
echo "\n--- R29 ops lows: smtp_pass masked, SMTP over IPv6, seller IDs, QR mentioned, merchant language, invalid IBAN, PDF wrap ---\n";

// 1. Logs mask a bare "pass" key, not words that merely contain it.
$r29oMask = new ReflectionMethod(Logger::class, 'maskSecrets');
assert_equals(['smtp_pass=[REDACTED]', '{"smtp_pass":"[REDACTED]"}', 'SMTP_PASS: [REDACTED]', 'bypass=1 passenger=Jo'],
    [$r29oMask->invoke(null, 'smtp_pass=Hunter2'), $r29oMask->invoke(null, '{"smtp_pass":"Hunter2"}'), $r29oMask->invoke(null, 'SMTP_PASS: Hunter2'),
     $r29oMask->invoke(null, 'bypass=1 passenger=Jo')],
    'R29 ops: SMTP_PASS / smtp_pass are masked in log text (was: in clear); bypass / passenger stay');
assert_equals(['smtp_pass' => '[REDACTED]', 'pass' => '[REDACTED]', 'passenger' => 'ok'],
    Logger::redact(['smtp_pass' => 'Hunter2', 'pass' => 'Hunter2', 'passenger' => 'ok']), 'R29 ops: a context key smtp_pass / pass is redacted');

// 2. An IPv6 SMTP_HOST is connected to (the server here never greets: the send times out, it does not fail to connect).
$r29oServer = @stream_socket_server('tcp://[::1]:0', $r29oErrno, $r29oErr);
if ($r29oServer === false) {
    echo "  [SKIP] R29 ops: no IPv6 loopback here ({$r29oErr})\n";
} else {
    $r29oPort = (int)substr(strrchr((string)stream_socket_get_name($r29oServer, false), ':'), 1);
    $r29oEnvNames = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_ENCRYPTION', 'SMTP_TIMEOUT', 'SMTP_USER'];
    $r29oEnv = array_map(static fn(string $name): string|false => getenv($name), array_combine($r29oEnvNames, $r29oEnvNames));
    try {
        foreach (['SMTP_HOST' => '::1', 'SMTP_PORT' => (string)$r29oPort, 'SMTP_ENCRYPTION' => 'none', 'SMTP_TIMEOUT' => '1'] as $name => $value) putenv("{$name}={$value}");
        putenv('SMTP_USER');
        $r29oResult = (new SmtpMail())->send(['to' => 'buyer@r29o.eu', 'from' => 'shop@r29o.eu', 'subject' => 'x', 'text' => 'x']);
        assert_true(!str_starts_with((string)($r29oResult['message'] ?? ''), 'connect failed'),
            'R29 ops: SMTP_HOST=::1 connects to the IPv6 address (was: "connect failed", got: ' . ($r29oResult['message'] ?? '') . ')');
    } finally {
        foreach ($r29oEnv as $name => $value) putenv($value === false ? $name : "{$name}={$value}");
        fclose($r29oServer);
    }
}

// 3. The seller line prints only the IDs the shop has.
$r29oSeller = static function (string $lang, string $ico, string $dic, string $icDph): string {
    $vars = ['seller_name' => 'R29O', 'seller_address' => 'Hlavna 1', 'seller_ico' => $ico, 'seller_dic' => $dic, 'seller_ic_dph' => $icDph,
        'seller_ids' => $ico . $dic . $icDph !== '' ? '1' : '', 'seller_dic_sep' => $ico !== '' && $dic !== '' ? ', ' : '',
        'seller_ic_dph_sep' => $icDph !== '' && $ico . $dic !== '' ? ', ' : '', 'seller_email' => 'shop@r29o.eu'];
    $html = MailService::renderString((string)file_get_contents(TEST_CLI_DIR . "/../templates/emails/{$lang}/order_confirmation.html"), $vars);
    preg_match('/R29O, Hlavna 1(.*?)shop@r29o\.eu/s', $html, $m);
    return $m[1] ?? '';
};
assert_equals(['<br>IČ DPH: SK2020123456<br>', '<br>IČO: 12345678, DIČ: 2020123456<br>', '<br>', '<br>Company ID: 12345678, VAT ID: SK2020123456<br>'],
    [$r29oSeller('sk', '', '', 'SK2020123456'), $r29oSeller('sk', '12345678', '2020123456', ''), $r29oSeller('cs', '', '', ''),
     $r29oSeller('en', '12345678', '', 'SK2020123456')],
    'R29 ops: the confirmation prints only the seller IDs the shop has (was: "IČO: , DIČ:")');
$r29oVars = ConsumerNotice::mailVars(['id' => 0, 'order_number' => 'R29O', 'store_id' => 'default', 'locale' => 'sk']);
assert_equals([$r29oVars['seller_ico'] . $r29oVars['seller_dic'] . $r29oVars['seller_ic_dph'] !== '' ? '1' : '',
        $r29oVars['seller_ico'] !== '' && $r29oVars['seller_dic'] !== '' ? ', ' : ''],
    [$r29oVars['seller_ids'] ?? null, $r29oVars['seller_dic_sep'] ?? null], 'R29 ops: the order confirmation gets the separators for the IDs it has');

// 4. The bank transfer e-mails say the QR is attached; 6. the invoice of an IBAN with wrong check digits has no QR.
$pdo->exec('DELETE FROM invoices WHERE order_id = 97991');
$pdo->exec('DELETE FROM order_items WHERE order_id = 97991');
$pdo->exec('DELETE FROM orders WHERE id = 97991');
$r29oStorage = new ReflectionProperty(InvoiceService::class, 'storageDir');
$r29oPrevStorage = $r29oStorage->getValue();
$r29oDir = test_fixture_path('r29o-invoices');
@mkdir($r29oDir);
try {
    $pdo->prepare("INSERT INTO orders (id, store_id, order_number, status, payment_status, customer_name, customer_email, customer_phone, shipping_addr, payment_method,
        payment_method_snapshot, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at)
        VALUES (97991, 'default', 'R29O-QR', 'processing', 'pending', 'R29O', 'r29o@example.com', '', ?, 'bank_transfer', ?, 1000, 0, 0, 0, 1000, 'eur', 'sk', " . Db::now() . ")")
        ->execute([json_encode(['street' => 'Bankova 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK']),
            json_encode(['code' => 'bank_transfer', 'provider' => 'manual', 'service_type' => 'bank_transfer', 'label' => 'Bankový prevod'])]);
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (97991, ?, 'R29O-QR', 'R29O QR', 1, 1000, 1000)")
        ->execute([(int)$pdo->query('SELECT MIN(id) FROM products')->fetchColumn()]);
    $r29oOrder = Orders::byId(97991);
    $r29oBank = BankTransferInstructions::mailVars($r29oOrder);
    $r29oNote = (string)($r29oBank['bank_qr_note'] ?? '');
    assert_true(BankTransferInstructions::qrAttachment($r29oOrder) !== null && str_contains($r29oNote, I18n::tIn('bank_transfer.qr_attached', 'sk')),
        'R29 ops: an order with an attached QR gets the note in its language (got: ' . $r29oNote . ')');
    foreach (['order_confirmation', 'bank_transfer_reminder'] as $r29oTpl) {
        $r29oHtml = MailService::render($r29oTpl, 'sk', ['bank_transfer' => true, 'bank_qr_note' => 'R29O-NOTE'] + EmailTemplates::SAMPLE)['html'];
        assert_true(str_contains($r29oHtml, 'R29O-NOTE'), "R29 ops: {$r29oTpl} says the QR is attached (was: never mentioned)");
    }

    $r29oStorage->setValue(null, realpath($r29oDir));
    $r29oInvoice = InvoiceService::issue(97991);
    $r29oDraw = new ReflectionMethod(InvoiceService::class, 'drawPaymentQr');
    $r29oQr = static fn(string $iban): ?float => $r29oDraw->invoke(null, new MinimalPdf(), $r29oInvoice + ['due_date' => '2026-12-01'],
        ['iban' => $iban, 'bic' => 'TATRSKBX', 'name' => 'R29O', 'country' => 'SK'], ['country' => 'SK'], 100.0);
    assert_equals([true, null, null], [$r29oQr('SK3112000000198742637541') !== null, $r29oQr('SK3112000000198742637542'), $r29oQr('')],
        'R29 ops: the invoice prints a payment QR for a valid IBAN only (was: also for wrong check digits)');
} catch (Throwable $e) {
    assert_true(false, 'R29 ops: QR note / invoice QR (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r29oStorage->setValue(null, $r29oPrevStorage);
    $pdo->exec('DELETE FROM invoices WHERE order_id = 97991');
    $pdo->exec('DELETE FROM order_items WHERE order_id = 97991');
    $pdo->exec('DELETE FROM orders WHERE id = 97991');
}

// 5. Merchant e-mails are written in the merchant's language (merchant_notifications.locale, else the store's).
$r29oNumber = 'R29O-' . bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id, locale)
    VALUES (?, 'processing', 'paid', 'R29O Kunde', 'r29o@example.test', 'card', 'pi_r29o', 5000, 5000, 'eur', 'default', 'de')")->execute([$r29oNumber]);
$r29oOrderId = (int)$pdo->lastInsertId();
$r29oSuiteConfig = TEST_DATA_DIR . '/' . AppConfig::SUITE_FILE;
$r29oSavedConfig = (string)file_get_contents($r29oSuiteConfig);
$r29oMails = [];
$r29oMailer = new class($r29oMails) implements MailAdapter {
    public function __construct(private array &$mails) {}
    public function send(array $msg): array { $this->mails[] = $msg; return ['status' => 'sent', 'message' => '', 'raw' => null]; }
    public function adapterId(): string { return 'r29-mail'; }
};
try {
    $r29oCfg = require $r29oSuiteConfig;
    $r29oCfg['merchant_notifications'] = ['enabled' => true, 'recipients' => ['r29o-merchant@example.test'], 'locale' => 'sk'];
    file_put_contents($r29oSuiteConfig, '<?php return ' . var_export($r29oCfg, true) . ";\n");
    MailService::useAdapter($r29oMailer);
    MerchantNotifier::send($r29oOrderId, 'paid');
    $r29oText = (string)($r29oMails[0]['text'] ?? '');
    assert_true(str_contains($r29oText, I18n::tIn('payment_status.paid', 'sk')) && !str_contains($r29oText, I18n::tIn('payment_status.paid', 'de')),
        'R29 ops: the merchant e-mail of a German order is in the merchant\'s Slovak (was: German; got: ' . substr($r29oText, 0, 200) . ')');
} catch (Throwable $e) {
    assert_true(false, 'R29 ops: merchant language (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    MailService::useAdapter(null);
    file_put_contents($r29oSuiteConfig, $r29oSavedConfig);
    $pdo->prepare('DELETE FROM merchant_notifications WHERE order_id = ?')->execute([$r29oOrderId]);
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r29oOrderId]);
}

// 7. A 50 000-character word wraps in well under a second, every piece within the column.
$r29oPdf = new MinimalPdf();
$r29oStart = microtime(true);
$r29oLines = $r29oPdf->wrap('Stôl ' . str_repeat('ABCDÉ', 10000) . ' koniec', 60, 8);
$r29oTook = microtime(true) - $r29oStart;
$r29oWidth = new ReflectionMethod(MinimalPdf::class, 'approxTextWidth');
assert_true($r29oTook < 1.0 && count($r29oLines) > 100 && max(array_map(static fn(string $l): float => $r29oWidth->invoke($r29oPdf, $l, 8.0, false), $r29oLines)) <= 60
    && str_replace(' ', '', implode('', $r29oLines)) === 'Stôl' . str_repeat('ABCDÉ', 10000) . 'koniec',
    'R29 ops: a 50 000-character word wraps in ' . round($r29oTook, 2) . ' s within the column, nothing lost (was: minutes)');

// 8. The backup log falls back to a plain append, and the CLI's failure path cannot end in an uncaught log error.
assert_true(str_contains((string)file_get_contents(TEST_CLI_DIR . '/../src/BackupService.php'), "file_put_contents(\$abs, \$line, FILE_APPEND) === false")
    && substr_count((string)file_get_contents(TEST_CLI_DIR . '/backup.php'), "catch (\\Throwable \$logError) { Logger::exception('backup.log_failed', \$logError); }") === 2,
    'R29 ops: a backup on a file system without file locks reports its failure instead of a fatal error (guard)');
