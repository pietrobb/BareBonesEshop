<?php

// Review round 24 (2026-11-09, "kolo 8"), finding 2 (goal #379): checkout freezes the mail provider with the order
// (ApiHandlers.php:794). Before the owner set up SMTP that was 'log', the default that only writes the mail to the log,
// and every later e-mail of that order (paid, shipped, invoice, refund) went through it for good (WorkerJobs.php:104),
// while the admin showed it as "Sent". Now 'log' gives way to the provider configured when the mail goes out, and a
// mail that was only logged reads "Logged (not sent)".
echo "\n--- R24: e-mails of orders placed before SMTP was set up are delivered ---\n";
if (!function_exists('pageOrder')) {
    if (!function_exists('adminFieldErrors')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php';
}
$r24mDb = Db::get();
$r24mEnvNames = array_merge(array_values(MailSettings::ENV), ['MAIL_ADAPTER']);
$r24mEnv = [];
foreach ($r24mEnvNames as $name) { $r24mEnv[$name] = getenv($name); putenv($name); }
$r24mOrder = static function (string $frozen) use ($r24mDb): array {
    $number = 'R24M-' . bin2hex(random_bytes(3));
    $r24mSnapshot = json_encode(['providers' => ['tax' => 'vat', 'payment' => 'stripe', 'shipping' => 'none', 'mail' => $frozen]]);
    $r24mDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id, locale, financial_snapshot)
        VALUES (?, 'processing', 'paid', 'R24M Buyer', ?, 'card', 'pi_r24m', 5000, 5000, 'eur', 'default', 'en', ?)")
        ->execute([$number, strtolower($number) . '@r24m.example.test', $r24mSnapshot]);
    return Orders::byId((int)$r24mDb->lastInsertId());
};
$r24mLastLog = static function (int $orderId) use ($r24mDb): array {
    $stmt = $r24mDb->prepare('SELECT adapter, status FROM email_log WHERE order_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
try {
    // 1. Placed while the shop only logged mail: the order froze 'log'.
    $r24mOld = $r24mOrder('log');
    WorkerJobs::handleEmailSend(['template' => 'order_paid', 'to' => $r24mOld['customer_email'], 'order_id' => (int)$r24mOld['id']], []);
    assert_equals(['adapter' => 'log', 'status' => 'sent'], $r24mLastLog((int)$r24mOld['id']), 'R24 mail: without a delivering provider the mail is still only logged');
    $_GET = ['id' => (string)$r24mOld['id']];
    ob_start();
    try { pageOrder(); } finally { $r24mPage = (string)ob_get_clean(); $_GET = []; }
    assert_true(str_contains($r24mPage, '<span class="badge badge-logged">' . esc(I18n::t('admin.email.status.logged')) . '</span>') && !str_contains($r24mPage, 'badge-sent'),
        'R24 mail: the order detail shows a logged e-mail as "' . I18n::t('admin.email.status.logged') . '", not "' . I18n::t('admin.email.status.sent') . '"');

    // 2. SMTP is set up now (an unreachable server, so the attempt is visible as a failed SMTP delivery).
    putenv('MAIL_ADAPTER=smtp');
    putenv('SMTP_HOST=127.0.0.1');
    putenv('SMTP_PORT=9');
    putenv('SMTP_ENCRYPTION=none');
    putenv('MAIL_FROM=shop@r24m.example.eu');
    // R25: a frozen real provider (SES) gives way too (AuditRound25MailRegression).
    assert_equals(['smtp', 'smtp', 'smtp', 'smtp'],
        [WorkerJobs::orderMailProvider($r24mOld), WorkerJobs::orderMailProvider($r24mOrder('ses')),
         WorkerJobs::orderMailProvider($r24mOrder('r24-uninstalled')), WorkerJobs::orderMailProvider(null)],
        'R24 mail: a frozen \'log\' or a provider no longer installed gives way to the configured one');
    $r24mError = '';
    try {
        WorkerJobs::handleEmailSend(['template' => 'order_shipped', 'to' => $r24mOld['customer_email'], 'order_id' => (int)$r24mOld['id'], '_provider_code' => 'log'], []);
    } catch (RuntimeException $e) {
        $r24mError = $e->getMessage();
    }
    assert_equals(['mail.delivery_failed', ['adapter' => 'smtp', 'status' => 'failed']], [$r24mError, $r24mLastLog((int)$r24mOld['id'])],
        'R24 mail: the next e-mail of the old order goes to SMTP (here unreachable: failed, retried by the queue), not to the log as "sent"');
} finally {
    foreach ($r24mEnv as $name => $value) putenv($value === false ? $name : "{$name}={$value}");
}
