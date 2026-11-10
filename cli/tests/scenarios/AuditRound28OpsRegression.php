<?php

// Review round 28 (2026-11-10, main @ a90ea0ea), items from the earlier summary (goal #405):
// - mail.json (Admin → E-mail delivery) was in no backup: a restored shop logged its mail instead of sending it;
// - an empty SMTP_* variable (`SMTP_HOST=` in .env, a compose `${SMTP_HOST}` left unset) replaced the saved server with ''
//   (SMTP_PORT= with port 0) in SmtpMail, while the admin showed the saved server as in use;
// - the invoice never had the seller's BIC that the installer and Admin → Stores collect "for the invoices".
echo "\n--- R28 ops: mail.json backed up and restored 0600, an empty SMTP_* is unset, the invoice prints the BIC ---\n";

// 1. mail.json is a backup file source and comes back owner-only.
$r28oRoot = test_fixture_path('r28o-backup');
$r28oData = $r28oRoot . '/data';
$r28oShop = $r28oRoot . '/shop';
@mkdir($r28oData, 0770, true);
@mkdir($r28oShop, 0700, true);
@chmod($r28oData, 0770);
$r28oMail = '{"mode":"smtp","host":"smtp.r28o.eu","password":"v1:secret"}';
file_put_contents($r28oData . '/' . MailSettings::FILE, $r28oMail);
@chmod($r28oData . '/' . MailSettings::FILE, 0600);
$r28oSources = BackupService::defaultFileSources(['private_runtime_dir' => $r28oData, 'digital_assets_base_dir' => $r28oData . '/downloads',
    'invoice_storage_dir' => $r28oData . '/invoices', 'uploads_dir' => $r28oShop . '/uploads', 'gdpr_salt_file' => $r28oData . '/gdpr_salt'], $r28oShop);
assert_equals($r28oData . '/mail.json', $r28oSources['mail'] ?? null, 'R28 ops: the e-mail delivery settings (mail.json) are a backup file source');
$r28oPdo = new PDO('sqlite:' . $r28oRoot . '/live.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r28oPdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
try {
    BackupService::init($r28oPdo, ['enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
        'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 1], 'storage_dir' => $r28oRoot . '/backups', 'files' => $r28oSources], $r28oShop);
    $r28oMeta = BackupService::create(['label' => 'manual']);
    unlink($r28oData . '/' . MailSettings::FILE);
    BackupService::restoreFiles((string)$r28oMeta['remote_path']);
    clearstatcache();
    $r28oMode = DIRECTORY_SEPARATOR === '/' ? substr(sprintf('%o', (int)@fileperms($r28oData . '/mail.json')), -3) : '600';
    assert_equals([$r28oMail, '600'], [@file_get_contents($r28oData . '/mail.json'), $r28oMode],
        'R28 ops: a files restore brings mail.json back, readable by the owner only (its directory is 0770)');
} catch (Throwable $e) {
    assert_true(false, 'R28 ops: backup and restore of mail.json (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    BackupService::reset();
    $r28oPdo = null;
    gc_collect_cycles();
}

// 2. An empty SMTP_* variable falls through to the saved settings / config.php, as Admin → E-mail delivery shows it.
$r28oSaved = MailSettings::smtpConfig();
$r28oSmtp = static function (): array {
    $mail = new SmtpMail(['smtp_host' => 'cfg.r28o.eu', 'smtp_port' => 2525, 'smtp_encryption' => 'ssl']);
    $read = static fn(string $name): mixed => (new ReflectionProperty(SmtpMail::class, $name))->getValue($mail);
    return [$read('host'), $read('port'), $read('encryption')];
};
$r28oEnv = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_ENCRYPTION'];
$r28oBefore = array_map(static fn(string $name): string|false => getenv($name), array_combine($r28oEnv, $r28oEnv));
try {
    foreach ($r28oEnv as $name) putenv($name . '=');
    assert_equals([(string)($r28oSaved['smtp_host'] ?? 'cfg.r28o.eu'), (int)($r28oSaved['smtp_port'] ?? 2525), (string)($r28oSaved['smtp_encryption'] ?? 'ssl')],
        $r28oSmtp(), 'R28 ops: SMTP_HOST=, SMTP_PORT=, SMTP_ENCRYPTION= (empty) leave the saved / configured server (was: host \'\', port 0)');
    assert_equals([], array_intersect_key(MailSettings::environmentOverrides(), array_flip(['host', 'port', 'encryption'])),
        'R28 ops: the admin does not mark them as set by the environment either');
    putenv('SMTP_HOST=env.r28o.eu');
    assert_equals('env.r28o.eu', $r28oSmtp()[0], 'R28 ops: a set SMTP_HOST still wins');
} finally {
    foreach ($r28oBefore as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
}

// 3. The invoice of an order to pay by transfer prints the seller's BIC next to the IBAN.
$r28oSeller = (new ReflectionMethod(InvoiceService::class, 'sellerSnapshot'))->invoke(null, ['name' => 'R28O', 'iban' => 'SK3112000000198742637541', 'bic' => 'TATRSKBX']);
assert_equals('TATRSKBX', $r28oSeller['bic'] ?? null, 'R28 ops: the invoice\'s seller snapshot keeps the BIC (was: left out)');
$r28oStorage = new ReflectionProperty(InvoiceService::class, 'storageDir');
$r28oPrevStorage = $r28oStorage->getValue();
$r28oDir = test_fixture_path('r28o-invoices');
@mkdir($r28oDir);
$r28oStorage->setValue(null, realpath($r28oDir));
$pdo->exec('DELETE FROM invoices WHERE order_id = 97981');
$pdo->exec('DELETE FROM order_items WHERE order_id = 97981');
$pdo->exec('DELETE FROM orders WHERE id = 97981');
try {
    $pdo->prepare("INSERT INTO orders (id, store_id, order_number, status, payment_status, customer_name, customer_email, customer_phone, shipping_addr, payment_method,
        payment_method_snapshot, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at)
        VALUES (97981, 'default', 'R28O-BIC', 'processing', 'pending', 'R28O', 'r28o@example.com', '', ?, 'bank_transfer', ?, 1000, 0, 0, 0, 1000, 'eur', 'sk', " . Db::now() . ")")
        ->execute([json_encode(['street' => 'Bankova 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK']),
            json_encode(['code' => 'bank_transfer', 'provider' => 'manual', 'service_type' => 'bank_transfer', 'label' => 'Bankový prevod'])]);
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (97981, ?, 'R28O-BIC', 'R28O BIC', 1, 1000, 1000)")
        ->execute([(int)$pdo->query('SELECT MIN(id) FROM products')->fetchColumn()]);
    $r28oInvoice = InvoiceService::issue(97981);
    $r28oSnap = json_decode((string)$pdo->query('SELECT seller_snapshot FROM invoices WHERE id = ' . (int)$r28oInvoice['id'])->fetchColumn(), true) ?: [];
    $pdo->prepare('UPDATE invoices SET seller_snapshot = ? WHERE id = ?')
        ->execute([json_encode(['iban' => 'SK3112000000198742637541', 'bic' => 'TATRSKBX'] + $r28oSnap), (int)$r28oInvoice['id']]);
    $r28oPath = InvoiceService::regeneratePdf((int)$r28oInvoice['id']);
    preg_match_all('/Td \(([^)]*)\) Tj/', (string)file_get_contents(InvoiceService::pdfAbsolutePath(InvoiceService::byId((int)$r28oInvoice['id']) ?: $r28oInvoice)), $r28oTexts);
    assert_equals(['IBAN: SK3112000000198742637541   BIC: TATRSKBX'], array_values(preg_grep('/^IBAN: /', $r28oTexts[1])),
        'R28 ops: the invoice prints the BIC next to the IBAN for a payment from abroad (was: only the IBAN)');
    assert_equals([true, []], [preg_grep('/Bankov/', $r28oTexts[1]) !== [], array_values(preg_grep('/bank_transfer/', $r28oTexts[1]))],
        'R28 ops: the invoice names the payment method by its label, never the internal code (verified on main, guard)');
} catch (Throwable $e) {
    assert_true(false, 'R28 ops: invoice BIC (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r28oStorage->setValue(null, $r28oPrevStorage);
    $pdo->exec('DELETE FROM invoices WHERE order_id = 97981');
    $pdo->exec('DELETE FROM order_items WHERE order_id = 97981');
    $pdo->exec('DELETE FROM orders WHERE id = 97981');
}
