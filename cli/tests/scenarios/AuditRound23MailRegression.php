<?php

// Review round 23 (2026-11-08), UX "e-mails" (goal #375):
// - SMTP could only come from environment variables: on shared hosting without them every e-mail was only logged. The
//   owner now enters it in Admin → E-mail delivery or in the web installer (password encrypted, environment wins);
// - the merchant's order e-mails printed the raw payment code ("paid", "— pending") instead of its label;
// - the invoice e-mail of an already paid order still asked for the "amount due" by a due date;
// - the bank transfer e-mails had no BIC; the e-mail log hid the sender name, Reply-To and the attachments.
echo "\n--- R23: SMTP from the admin and installer, translated merchant e-mails, paid invoice e-mail ---\n";
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shared.php';
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/MailDelivery.php';

$r23mDir = test_fixture_path('r23m-data');
@mkdir($r23mDir, 0750, true);
@unlink($r23mDir . '/' . MailSettings::FILE);
$r23mKey = bin2hex(random_bytes(32));
$r23mConfig = ['private_runtime_dir' => $r23mDir, 'admin_identity_key' => $r23mKey, 'provider_defaults' => ['mail' => 'log']];
$r23mEnvNames = array_merge(array_values(MailSettings::ENV), ['MAIL_ADAPTER', 'BBE_ADMIN_IDENTITY_KEY']);
$r23mEnv = [];
foreach ($r23mEnvNames as $name) { $r23mEnv[$name] = getenv($name); putenv($name); }
DataOverrides::setDirForTesting($r23mDir);
try {
    // 1) The form refuses a login over an unencrypted connection and a sender on a reserved domain; the port follows TLS.
    [$r23mBad, $r23mBadErrors] = MailSettings::validate(['mode' => 'smtp', 'host' => 'smtp.r23m.eu', 'encryption' => 'none', 'username' => 'shop', 'password' => 'x', 'from' => 'shop@example.com']);
    [$r23mOk, $r23mOkErrors] = MailSettings::validate(['mode' => 'smtp', 'host' => 'SMTP.R23M.EU', 'username' => 'shop@r23m.eu', 'password' => 'S3cret pass!', 'from' => 'Shop@R23M.eu']);
    assert_equals([['encryption' => 'plaintext_auth', 'from' => 'from'], [], 587, 'smtp.r23m.eu', 'tls'],
        [$r23mBadErrors, $r23mOkErrors, $r23mOk['port'], $r23mOk['host'], $r23mOk['encryption']],
        'R23 mail: SMTP form refuses AUTH without TLS and a reserved sender domain; defaults to STARTTLS on 587');

    // 2) Saved: the password is encrypted in a 0600 file, decrypted for SmtpMail, never stored in clear text.
    assert_equals(null, MailSettings::save($r23mOk, $r23mConfig, 'test'), 'R23 mail: the SMTP settings are saved');
    $r23mFile = (string)file_get_contents($r23mDir . '/' . MailSettings::FILE);
    $r23mSmtp = MailSettings::smtpConfig($r23mConfig);
    assert_equals([false, true, 'S3cret pass!', 'smtp.r23m.eu', 587, 'shop@r23m.eu', 'smtp'],
        [str_contains($r23mFile, 'S3cret'), str_contains($r23mFile, '"password": "v1:'), $r23mSmtp['smtp_pass'] ?? null, $r23mSmtp['smtp_host'] ?? null,
         $r23mSmtp['smtp_port'] ?? null, MailSettings::from($r23mConfig), MailSettings::providerCode($r23mConfig)],
        'R23 mail: the password is stored encrypted and opened with admin_identity_key; the saved mode selects SMTP');
    if (DIRECTORY_SEPARATOR === '/') {
        assert_equals('600', substr(sprintf('%o', fileperms($r23mDir . '/' . MailSettings::FILE)), -3), 'R23 mail: mail.json is readable by the owner only');
    }
    // An empty password field keeps the saved password; another key cannot open it (config.php regenerated).
    assert_equals(null, MailSettings::save(['password' => ''] + $r23mOk, $r23mConfig, 'test'), 'R23 mail: saving without a password works');
    $r23mOther = ['admin_identity_key' => bin2hex(random_bytes(32))] + $r23mConfig;
    assert_equals(['S3cret pass!', true, false],
        [MailSettings::smtpConfig($r23mConfig)['smtp_pass'] ?? null, MailSettings::passwordUnreadable($r23mOther), isset(MailSettings::smtpConfig($r23mOther)['smtp_pass'])],
        'R23 mail: an empty password field keeps the saved one; a different admin_identity_key reports it unreadable');

    // 3) The adapters use it: SmtpMail takes the saved server, the environment wins over it, config.php comes last.
    $r23mHost = static function (): string { $p = new ReflectionProperty(SmtpMail::class, 'host'); return (string)$p->getValue(new SmtpMail(['smtp_host' => 'config.r23m.eu'])); };
    $r23mSaved = $r23mHost();
    putenv('SMTP_HOST=env.r23m.eu');
    putenv('MAIL_ADAPTER=ses');
    $r23mEnvHost = $r23mHost();
    $r23mEnvProvider = MailSettings::providerCode($r23mConfig);
    $r23mLocked = MailSettings::environmentOverrides();
    putenv('SMTP_HOST');
    putenv('MAIL_ADAPTER');
    assert_equals(['smtp.r23m.eu', 'env.r23m.eu', 'ses', ['host' => 'SMTP_HOST'], 'smtp', 'shop@r23m.eu'],
        [$r23mSaved, $r23mEnvHost, $r23mEnvProvider, $r23mLocked, Setup::mailProvider($r23mConfig), MailService::sender($r23mConfig)],
        'R23 mail: SMTP_HOST / MAIL_ADAPTER win over the saved settings, which win over config.php; the doctor and the sender agree');

    // 4) The admin page never prints the saved password and locks a field the environment sets.
    putenv('SMTP_PASS=env-secret');
    ob_start();
    pageMailDelivery();
    $r23mPage = (string)ob_get_clean();
    putenv('SMTP_PASS');
    assert_equals([false, false, true, true, true],
        [str_contains($r23mPage, 'S3cret'), str_contains($r23mPage, 'env-secret'), str_contains($r23mPage, 'data-mail-env="SMTP_PASS"'),
         str_contains($r23mPage, 'value="smtp.r23m.eu"'), str_contains($r23mPage, 'data-mail-status="smtp"')],
        'R23 mail: Admin → E-mail delivery shows the saved server, never a password, and marks SMTP_PASS as set by the environment');

    // 5) The web installer validates the same fields (English messages) and skips them when left empty.
    $r23mInstall = static function (array $mail) use ($r23mDir): array {
        return WebInstaller::validate($mail + ['shop_name' => 'R23M', 'app_url' => 'https://r23m.eu', 'private_parent' => $r23mDir, 'email' => 'owner@r23m.eu',
            'admin_user' => 'owner', 'admin_password' => 'Long enough pass 1', 'admin_password_repeat' => 'Long enough pass 1'], TEST_CLI_DIR . '/..');
    };
    [$r23mNone] = $r23mInstall([]);
    [$r23mWith, $r23mWithErrors] = $r23mInstall(['smtp_host' => 'smtp.r23m.eu', 'smtp_user' => 'shop', 'smtp_pass' => 'pw', 'smtp_encryption' => 'none', 'mail_from' => 'shop@r23m.eu']);
    assert_equals([null, 'A login needs encryption: use tls or ssl.', 'smtp.r23m.eu'],
        [array_key_exists('mail', $r23mNone) ? $r23mNone['mail'] : 'missing', $r23mWithErrors['smtp_encryption'] ?? null, $r23mWith['mail']['host'] ?? null],
        'R23 mail: the installer offers the SMTP fields, checks them like the admin and leaves them out when empty');
} finally {
    @unlink($r23mDir . '/' . MailSettings::FILE);
    DataOverrides::setDirForTesting(null);
    foreach ($r23mEnv as $name => $value) putenv($value === false ? $name : "{$name}={$value}");
}
assert_equals(null, MailSettings::mode(), 'R23 mail: the suite is back on its own (log-only) mail settings');

// 6) Merchant e-mails print the payment state in the e-mail's language; a paid order's invoice e-mail asks for nothing.
$r23mDb = Db::get();
$r23mNumber = 'R23M-' . bin2hex(random_bytes(3));
$r23mDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id, locale)
    VALUES (?, 'processing', 'paid', 'R23M Buyer', 'r23m@example.test', 'card', 'pi_r23m', 5000, 5000, 'eur', 'default', 'sk')")->execute([$r23mNumber]);
$r23mOrderId = (int)$r23mDb->lastInsertId();
$r23mSuiteConfig = TEST_DATA_DIR . '/' . AppConfig::SUITE_FILE;
$r23mSavedConfig = (string)file_get_contents($r23mSuiteConfig);
$r23mMails = [];
$r23mMailer = new class($r23mMails) implements MailAdapter {
    public function __construct(private array &$mails) {}
    public function send(array $msg): array { $this->mails[] = $msg; return ['status' => 'sent', 'message' => '', 'raw' => null]; }
    public function adapterId(): string { return 'r23-mail'; }
};
try {
    $r23mCfg = require $r23mSuiteConfig;
    $r23mCfg['merchant_notifications'] = ['enabled' => true, 'recipients' => ['r23m-merchant@example.test']];
    file_put_contents($r23mSuiteConfig, '<?php return ' . var_export($r23mCfg, true) . ";\n");
    MailService::useAdapter($r23mMailer);
    MerchantNotifier::send($r23mOrderId, 'paid');
    $r23mText = (string)($r23mMails[0]['text'] ?? '');
    assert_true(str_contains($r23mText, I18n::tIn('payment_status.paid', 'sk')) && !preg_match('/\bpaid\b/', $r23mText) && str_contains($r23mText, '(pi_r23m)'),
        'R23 mail: the merchant e-mail of a paid order says "Zaplatené" in Slovak, not the code "paid" (got: ' . substr($r23mText, 0, 300) . ')');
} finally {
    MailService::useAdapter(null);
    file_put_contents($r23mSuiteConfig, $r23mSavedConfig);
}
$r23mInvoice = static fn(bool $paid): string => MailService::render('invoice_issued', 'en', ['invoice_paid' => $paid, 'invoice_due' => !$paid] + EmailTemplates::SAMPLE)['html'];
assert_equals([false, false, true, true, true],
    [str_contains($r23mInvoice(true), 'Amount due'), str_contains($r23mInvoice(true), 'Due date'), str_contains($r23mInvoice(true), 'Paid – nothing to pay'),
     str_contains($r23mInvoice(false), 'Amount due'), str_contains(MailService::render('bank_transfer_reminder', 'en', EmailTemplates::SAMPLE)['html'], 'TATRSKBX')],
    'R23 mail: the invoice e-mail of a paid order has no amount due or due date; bank transfer e-mails show the BIC');

// 7) The e-mail log shows the sender name, Reply-To and attachments, and keeps an .eml copy of the whole message.
$r23mLogDir = test_fixture_path('r23m-log');
@mkdir($r23mLogDir, 0750, true);
$r23mLog = new LogMail(['log_file' => $r23mLogDir . '/emails.log']);
$r23mLog->send(['to' => 'buyer@r23m.eu', 'from' => 'shop@r23m.eu', 'from_name' => 'R23M Shop', 'reply_to' => 'help@r23m.eu', 'subject' => "Invoice\r\nBcc: x@evil.test",
    'text' => 'Hello', 'html' => '<p>Hello</p>', 'attachments' => [['filename' => 'invoice.pdf', 'content_type' => 'application/pdf', 'data' => '%PDF-1.4 r23m']]]);
$r23mLogged = (string)@file_get_contents($r23mLogDir . '/emails.log');
$r23mEml = glob($r23mLogDir . '/emails/*.eml') ?: [];
assert_equals([true, true, true, false, 1],
    [str_contains($r23mLogged, 'FROM=R23M Shop <shop@r23m.eu>'), str_contains($r23mLogged, 'Reply-To: help@r23m.eu'),
     str_contains($r23mLogged, 'Attachment: invoice.pdf (application/pdf, 13 bytes)'), (bool)preg_match('/^Bcc:/m', $r23mLogged), count($r23mEml)],
    'R23 mail: the log names sender, Reply-To and attachments (no header injection) and keeps an .eml of the message (log: ' . substr($r23mLogged, 0, 300) . ')');
