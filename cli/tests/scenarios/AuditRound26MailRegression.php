<?php

// Review round 26 (2026-11-09, "kolo 10"), the mail items (goal #392):
// - a MAIL_ADAPTER of an invalid format ("smtp.", "smtp " with a trailing space) still threw past the R25 fallback to the
//   log, and so did every mail that is not an order's (password reset, e-mail verification …);
// - an order mail written to the log for a misconfigured provider was never sent once the configuration was fixed;
// - "logged" still read as sent for the admin payment link, e-mail verification, password reset, back-in-stock, the account
//   offer after a purchase and stock alerts.
echo "\n--- R26: a misconfigured mail provider logs every mail and retries order mail; logged mail never reads as sent ---\n";
$r26mDb = Db::get();
$r26mEnvNames = array_merge(array_values(MailSettings::ENV), ['MAIL_ADAPTER']);
$r26mEnv = [];
foreach ($r26mEnvNames as $name) { $r26mEnv[$name] = getenv($name); putenv($name); }
$r26mJobs = test_fixture_path('r26m-jobs');
JobQueue::setDir($r26mJobs);
if (!class_exists('R26mCaptureMail', false)) {
    class R26mCaptureMail implements MailAdapter {
        /** @var list<array> */
        public array $sent = [];
        public function send(array $msg): array { $this->sent[] = $msg; return ['status' => 'sent']; }
        public function adapterId(): string { return 'smtp'; }
    }
}
$r26mOrder = static function () use ($r26mDb): array {
    $number = 'R26M-' . bin2hex(random_bytes(3));
    $r26mDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id, locale, financial_snapshot)
        VALUES (?, 'processing', 'paid', 'R26M Buyer', ?, 'card', 'pi_r26m', 5000, 5000, 'eur', 'default', 'en', '{}')")
        ->execute([$number, strtolower($number) . '@r26m.example.test']);
    return Orders::byId((int)$r26mDb->lastInsertId());
};
$r26mLastLog = static function (int $orderId) use ($r26mDb): array {
    $stmt = $r26mDb->prepare('SELECT adapter, status FROM email_log WHERE order_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
// The email_send jobs queued again for a mail the misconfigured provider only logged.
$r26mRetries = static function () use ($r26mJobs): array {
    $found = [];
    if (!is_dir($r26mJobs)) return [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r26mJobs, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $job = json_decode((string)file_get_contents($file->getPathname()), true);
        if (is_array($job) && ($job['type'] ?? '') === 'email_send' && isset($job['payload']['_mail_fallback_tries'])) {
            $found[] = $job['payload'];
            @unlink($file->getPathname()); // taken: each check sees only what was queued since
        }
    }
    return $found;
};
$r26mCapture = null;
try {
    // 1. An invalid format: no exception, the order mail is logged, an incident raised, and the mail queued again.
    foreach (['smtp.', 'sm tp', 'SMTP..'] as $r26mBad) {
        putenv('MAIL_ADAPTER=' . $r26mBad);
        $r26mError = '';
        try {
            $r26mCode = MailService::providerCode(null, $r26mFell);
        } catch (Throwable $e) {
            $r26mError = get_class($e) . ': ' . $e->getMessage();
            $r26mCode = '';
            $r26mFell = null;
        }
        assert_equals(['', 'log', true], [$r26mError, $r26mCode, $r26mFell], "R26 mail: MAIL_ADAPTER=\"{$r26mBad}\" falls back to the log (was: an exception)");
    }
    putenv('MAIL_ADAPTER=smtp.');
    $r26mA = $r26mOrder();
    $r26mError = '';
    try {
        WorkerJobs::handleEmailSend(['template' => 'order_paid', 'to' => $r26mA['customer_email'], 'order_id' => (int)$r26mA['id']], []);
    } catch (Throwable $e) {
        $r26mError = get_class($e) . ': ' . $e->getMessage();
    }
    $r26mQueued = $r26mRetries();
    assert_equals(['', ['adapter' => 'log', 'status' => 'sent'], [[(int)$r26mA['id'], 1]]],
        [$r26mError, $r26mLastLog((int)$r26mA['id']), array_map(static fn(array $p): array => [(int)$p['order_id'], (int)$p['_mail_fallback_tries']], $r26mQueued)],
        'R26 mail: an order mail under MAIL_ADAPTER="smtp." is logged without an exception and queued again (was: InvalidArgumentException past the fallback)');

    // 2. Not only order mail: a password reset mail under the same configuration is logged, not an exception.
    $r26mError = '';
    $r26mReset = [];
    try {
        $r26mReset = MailService::send('account_password_reset', 'r26m-reset@r26m.example.test', 'en', ['customer_name' => 'R26M', 'reset_url' => 'https://shop.example.test/r', 'expires_in' => '1']);
    } catch (Throwable $e) {
        $r26mError = get_class($e) . ': ' . $e->getMessage();
    }
    assert_equals(['', true], [$r26mError, MailService::logged($r26mReset)],
        'R26 mail: a mail that is no order\'s under an invalid MAIL_ADAPTER is logged, not an exception');

    // 3. A trailing space is the provider it names, as the admin reads it (MailSettings::providerCode()).
    putenv('MAIL_ADAPTER=smtp ');
    assert_equals(['smtp', 'smtp'], [Adapters::defaultProviderCode('mail'), MailSettings::providerCode()],
        'R26 mail: MAIL_ADAPTER="smtp " is smtp for the sender as for the admin (was: an exception)');

    // 4. The configuration fixed, the queued order mail goes out, and nothing is queued again.
    putenv('MAIL_ADAPTER=smtp');
    $r26mCapture = new R26mCaptureMail();
    MailService::useAdapter($r26mCapture);
    foreach ($r26mQueued as $r26mPayload) WorkerJobs::handleEmailSend($r26mPayload, []);
    assert_equals([[$r26mA['customer_email']], []], [array_column($r26mCapture->sent, 'to'), $r26mRetries()],
        'R26 mail: an order mail logged for a misconfigured provider is sent once the configuration is fixed (was: never)');
    MailService::useAdapter(null);
    // ... and the retries stop after their budget.
    putenv('MAIL_ADAPTER=smtp.');
    WorkerJobs::handleEmailSend(['template' => 'order_paid', 'to' => $r26mA['customer_email'], 'order_id' => (int)$r26mA['id'], '_mail_fallback_tries' => 48], []);
    assert_equals([], $r26mRetries(), 'R26 mail: an order mail is tried again at most 48 times');
    putenv('MAIL_ADAPTER');

    // 5. The admin payment link written to the log comes back 'logged' (the admin page reads only 'sent' as e-mailed and
    //    audited); through a real provider it is 'sent'.
    $r26mCfgProp = new ReflectionProperty(Security::class, 'cfg');
    $r26mCfgBefore = $r26mCfgProp->getValue();
    $r26mInstances = new ReflectionProperty(Adapters::class, 'instances');
    $r26mInstancesBefore = $r26mInstances->getValue();
    try {
        $r26mCfgProp->setValue(null, ['app_url' => 'https://r26m.example.test'] + (is_array($r26mCfgBefore) ? $r26mCfgBefore : require AppConfig::path(dirname(TEST_CLI_DIR))));
        Adapters::set('payment.r26m-gateway', new class {});
        $r26mUnpaid = static function () use ($r26mDb): array {
            $r26mDb->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
                VALUES (?, 'R26M', 'r26m-link@r26m.example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'pending', 'failed', 'r26m-gateway', '', ?, ?, " . Db::now() . ")")
                ->execute(['R26ML-' . bin2hex(random_bytes(4)), json_encode(['provider' => 'r26m-gateway', 'service_type' => 'online_card', 'requires_online' => true]), StoreContext::current()->id()]);
            return Orders::byId((int)$r26mDb->lastInsertId());
        };
        putenv('MAIL_ADAPTER=log');
        $r26mLogged = PaymentLink::send($r26mUnpaid());
        $r26mCapture = new R26mCaptureMail();
        MailService::useAdapter($r26mCapture);
        $r26mReal = PaymentLink::send($r26mUnpaid());
        assert_equals(['logged', 'sent', 1], [$r26mLogged['status'] ?? null, $r26mReal['status'] ?? null, count($r26mCapture->sent)],
            'R26 mail: an admin payment link only written to the log is "logged", not "sent"; through a real provider it is "sent" (was: "sent" for both)');
    } finally {
        MailService::useAdapter(null);
        putenv('MAIL_ADAPTER');
        $r26mInstances->setValue(null, $r26mInstancesBefore);
        $r26mCfgProp->setValue(null, $r26mCfgBefore);
    }

    // 6. "Logged" is no "sent" in the other five places the review named.
    $r26mRoot = dirname(TEST_CLI_DIR);
    $r26mSource = static fn(string $file): string => (string)file_get_contents($r26mRoot . '/' . $file);
    assert_true(substr_count($r26mSource('src/Customers.php'), "return MailService::logged(\$mail) ? 'logged' : 'sent';") === 2
        && str_contains($r26mSource('src/BackInStock.php'), "=== 'sent' && !MailService::logged(\$mail) ? 'confirm_sent' : 'failed';")
        && str_contains($r26mSource('src/BackInStock.php'), 'if (MailService::logged($result)) continue;')
        && str_contains($r26mSource('src/GuestCheckoutAccount.php'), "=== 'sent' && !MailService::logged(\$mail) ? 'sent' : 'failed';")
        && str_contains($r26mSource('src/StockAlerts.php'), 'if (MailService::logged($mail)) $logged++;'),
        'R26 mail: the admin payment link, e-mail verification, password reset, back-in-stock (confirmation and notice), the account offer and stock alerts do not count logged mail as sent');
} catch (Throwable $e) {
    assert_true(false, 'R26 mail (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    MailService::useAdapter(null);
    foreach ($r26mEnv as $name => $value) putenv($value === false ? $name : "{$name}={$value}");
}
