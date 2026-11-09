<?php

// Review round 25 (2026-11-09, "kolo 9"), finding 6 and the low "logged" items (goal #387): an order frozen on a real mail
// provider kept it — SMTP mailed real customers from a staging copy switched to 'log', a frozen SES failed every attempt
// after the shop moved to SMTP; an invalid MAIL_ADAPTER now broke the orders frozen on 'log' too; and mail the log
// provider only wrote down still read as sent on the storefront, in the admin, in merchant notifications and the GDPR export.
echo "\n--- R25: order mail goes through the provider configured now; logged mail never reads as sent ---\n";
$r25mDb = Db::get();
$r25mEnvNames = array_merge(array_values(MailSettings::ENV), ['MAIL_ADAPTER']);
$r25mEnv = [];
foreach ($r25mEnvNames as $name) { $r25mEnv[$name] = getenv($name); putenv($name); }
$r25mOrder = static function (string $frozen) use ($r25mDb): array {
    $number = 'R25M-' . bin2hex(random_bytes(3));
    $snapshot = json_encode(['providers' => ['tax' => 'vat', 'payment' => 'stripe', 'shipping' => 'none', 'mail' => $frozen]]);
    $r25mDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id, locale, financial_snapshot)
        VALUES (?, 'processing', 'paid', 'R25M Buyer', ?, 'card', 'pi_r25m', 5000, 5000, 'eur', 'default', 'en', ?)")
        ->execute([$number, strtolower($number) . '@r25m.example.test', $snapshot]);
    return Orders::byId((int)$r25mDb->lastInsertId());
};
$r25mLastLog = static function (int $orderId) use ($r25mDb): array {
    $stmt = $r25mDb->prepare('SELECT adapter, status FROM email_log WHERE order_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
try {
    // 1. A staging copy switched to 'log': an order frozen on SMTP is only logged, never sent to the real customer.
    putenv('MAIL_ADAPTER=log');
    $r25mSmtp = $r25mOrder('smtp');
    WorkerJobs::handleEmailSend(['template' => 'order_paid', 'to' => $r25mSmtp['customer_email'], 'order_id' => (int)$r25mSmtp['id'], '_provider_code' => 'smtp'], []);
    assert_equals(['adapter' => 'log', 'status' => 'sent'], $r25mLastLog((int)$r25mSmtp['id']),
        'R25 mail: an order frozen on SMTP mails through the log once the shop is switched to it (was: SMTP to the real customer from staging)');

    // 2. Moved from SES to SMTP: the order frozen on SES goes through SMTP.
    putenv('MAIL_ADAPTER=smtp');
    assert_equals('smtp', WorkerJobs::orderMailProvider($r25mOrder('ses')), 'R25 mail: an order frozen on SES mails through the SMTP configured now (was: failed every attempt)');

    // 3. A MAIL_ADAPTER naming no installed provider: the mail is only logged (an incident tells the owner), not failed.
    putenv('MAIL_ADAPTER=r25-nope');
    $r25mLog = $r25mOrder('log');
    $r25mError = '';
    try {
        WorkerJobs::handleEmailSend(['template' => 'order_paid', 'to' => $r25mLog['customer_email'], 'order_id' => (int)$r25mLog['id']], []);
    } catch (Throwable $e) {
        $r25mError = get_class($e) . ': ' . $e->getMessage();
    }
    assert_equals(['', ['adapter' => 'log', 'status' => 'sent']], [$r25mError, $r25mLastLog((int)$r25mLog['id'])],
        'R25 mail: an invalid MAIL_ADAPTER writes order mail to the log instead of failing it (R24 broke the orders frozen on \'log\')');
    putenv('MAIL_ADAPTER');

    // 4. "Logged" is no "sent" for people.
    assert_equals([true, 'logged', false, 'sent', 'failed'], [
        MailService::logged(['status' => 'sent', 'adapter' => 'log']), MailService::shownStatus(['status' => 'sent', 'adapter' => 'log']),
        MailService::logged(['status' => 'sent', 'adapter' => 'smtp']), MailService::shownStatus(['status' => 'sent', 'adapter' => 'smtp']),
        MailService::shownStatus(['status' => 'failed', 'adapter' => 'log'])],
        'R25 mail: a result the log provider only wrote down reads "logged"; a delivered one "sent"');
    $r25mRoot = dirname(TEST_CLI_DIR);
    $r25mSource = static fn(string $file): string => (string)file_get_contents($r25mRoot . '/' . $file);
    assert_true(str_contains($r25mSource('src/Withdrawal.php'), '$status = MailService::shownStatus($result);')
        && str_contains($r25mSource('src/Complaints.php'), 'return MailService::shownStatus($result);')
        && str_contains($r25mSource('src/MerchantNotifier.php'), 'self::finish($orderId, $event, $recipient, MailService::shownStatus($result), \'\');')
        && preg_match_all("~if \(MailService::logged\(\\\$res\)\) \{\s+setFlash\('err', I18n::format\('admin\.order\.msg\.email_logged'~", $r25mSource('src/Entrypoints/Admin/Orders.php')) === 2
        && str_contains($r25mSource('src/GdprService.php'), "CASE WHEN status = 'sent' AND adapter = 'log' THEN 'logged' ELSE status END AS status, sent_at"),
        'R25 mail: the withdrawal and complaint receipts (page and admin), merchant notifications, the admin resend messages and the GDPR export say "logged", not "sent"');
    foreach (glob($r25mRoot . '/lang/*.php') ?: [] as $r25mLang) {
        assert_true(trim((string)((require $r25mLang)['admin.order.msg.email_logged'] ?? '')) !== '', 'R25 mail: admin.order.msg.email_logged in ' . basename($r25mLang));
    }

    // 5. The suite keeps its captured mail out of the repository (was: db/emails/ and db/emails.test.log in the checkout).
    $r25mOffenders = [];
    foreach (glob($r25mRoot . '/cli/tests/{,*/}*.php', GLOB_BRACE) ?: [] as $r25mTest) {
        if (basename($r25mTest) !== basename(__FILE__) && str_contains((string)file_get_contents($r25mTest), "/../db/emails")) $r25mOffenders[] = basename($r25mTest);
    }
    assert_equals([], $r25mOffenders, 'R25 mail: no test writes captured e-mail into db/ of the repository');
} finally {
    foreach ($r25mEnv as $name => $value) putenv($value === false ? $name : "{$name}={$value}");
}
