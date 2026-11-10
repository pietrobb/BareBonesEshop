<?php

// Review round 16, medium: back-in-stock mail-bombing, log redaction, transfer reminders, the shipped e-mail without a carrier.
echo "\n--- Audit round 16: back in stock, logs, transfer reminders, shipped e-mail ---\n";

// 1. "Notify me" mailed a fresh confirmation link on every repeat of the form: one address could be flooded with
//    links. A waiting request gets a new link at most once an hour, and one address at most three links an hour.
foreach (['20261025120000_back_in_stock_requests', '20261104120000_back_in_stock_double_opt_in'] as $r16mMigration) {
    (require dirname(TEST_CLI_DIR) . "/migrations/{$r16mMigration}.php")->up($pdo, Db::isSqlite());
}
$r16mLocale = I18n::locale();
I18n::setLocale('en');
$r16mCfg = new ReflectionProperty(Security::class, 'cfg');
$r16mCfgBase = Security::config();
$r16mCfg->setValue(null, ['app_url' => 'https://r16m.example.test'] + $r16mCfgBase);
$r16mWh = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
foreach ([9791, 9792, 9793, 9794] as $r16mId) {
    $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (?, 'default', (SELECT MIN(id) FROM categories WHERE store_id = 'default'), ?, ?, ?, 'simple', 900, 'eur', 'active', 0, 'deny')")
        ->execute([$r16mId, "R16M lamp {$r16mId}", "r16m-lamp-{$r16mId}", "R16M-{$r16mId}"]);
    $pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, ?, 0, 0)")->execute([$r16mWh, "R16M-{$r16mId}"]);
}
$r16mEmail = 'r16m-target@example.test';
$r16mLinks = static fn(): int => count(array_filter(LogMail::captured(), static fn(array $m): bool => ($m['to'] ?? '') === $r16mEmail));
$r16mToken = static function (): string {
    $mail = LogMail::lastCaptured() ?? [];
    return preg_match('~page=stock-notify&(?:amp;)?confirm=([a-f0-9]{64})~', (string)($mail['html'] ?? ''), $m) ? $m[1] : '';
};
try {
    MailService::useAdapter(capture_mail()); // R26: the log provider's mail reads as not sent.
    LogMail::clearCaptured();
    $r16mFirst = BackInStock::subscribe(9791, $r16mEmail, 'en');
    $r16mLink = $r16mToken();
    $r16mRepeats = [BackInStock::subscribe(9791, $r16mEmail, 'en'), BackInStock::subscribe(9791, strtoupper($r16mEmail), 'en'), BackInStock::subscribe(9791, $r16mEmail, 'en')];
    assert_true($r16mFirst === 'confirm_sent' && $r16mRepeats === ['confirm_sent', 'confirm_sent', 'confirm_sent'] && $r16mLinks() === 1
        && $r16mLink !== '' && BackInStock::pendingForToken($r16mLink) !== null,
        'R16 back in stock: asking again within the hour sends no new link, answers the same and the first link still works (' . json_encode([$r16mFirst, $r16mRepeats, $r16mLinks()]) . ')');

    $pdo->prepare('UPDATE back_in_stock_requests SET created_at = ? WHERE email = ? AND product_id = 9791')->execute([gmdate('Y-m-d H:i:s', time() - 61 * 60), $r16mEmail]);
    $r16mLater = BackInStock::subscribe(9791, $r16mEmail, 'en');
    $r16mNewLink = $r16mToken();
    assert_true($r16mLater === 'confirm_sent' && $r16mLinks() === 2 && $r16mNewLink !== $r16mLink && BackInStock::pendingForToken($r16mNewLink) !== null
        && BackInStock::pendingForToken($r16mLink) === null,
        'R16 back in stock: after an hour a waiting request gets one fresh link and the old one is void');

    $r16mOthers = [BackInStock::subscribe(9792, $r16mEmail, 'en'), BackInStock::subscribe(9793, $r16mEmail, 'en'), BackInStock::subscribe(9794, $r16mEmail, 'en')];
    $r16mRows = (int)$pdo->query("SELECT COUNT(*) FROM back_in_stock_requests WHERE email = " . $pdo->quote($r16mEmail))->fetchColumn();
    assert_equals([['confirm_sent', 'confirm_sent', 'throttled'], 4, 3], [$r16mOthers, $r16mLinks(), $r16mRows],
        'R16 back in stock: one address gets at most three links an hour over all products; the fourth request is refused, not stored');

    $pdo->prepare('UPDATE back_in_stock_requests SET confirmed_at = ' . Db::now() . ', token_hash = NULL WHERE email = ?')->execute([$r16mEmail]);
    assert_equals(['confirm_sent', 4], [BackInStock::subscribe(9791, $r16mEmail, 'en'), $r16mLinks()], 'R16 back in stock: a confirmed request still gets nothing and the same answer');
    assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/GuestPages.php'), "'back_in_stock.' . \$result")
        && I18n::t('back_in_stock.throttled') !== 'back_in_stock.throttled', 'R16 back in stock: the storefront shows the "too many requests" text for a refused request');
} finally {
    MailService::useAdapter(null);
    $r16mCfg->setValue(null, $r16mCfgBase);
    $pdo->exec('DELETE FROM back_in_stock_requests WHERE product_id IN (9791, 9792, 9793, 9794)');
    $pdo->exec("DELETE FROM warehouse_stock WHERE sku LIKE 'R16M-%'");
    $pdo->exec('DELETE FROM products WHERE id IN (9791, 9792, 9793, 9794)');
    LogMail::clearCaptured();
}

// 2. The logs kept card numbers and security codes, secrets in JSON nested inside a JSON string (escaped quotes),
//    and cookie headers with the session id.
$r16mLogged = Logger::redact([
    'spaced' => 'paid with 4242 4242 4242 4242 ok', 'dashed' => 'card 5555-5555-5555-4444', 'amex' => 'amex 378282246310005',
    'jsonp' => '{"pan":"4012888888881881","exp":"12/29"}', 'number' => 4111111111111111,
    'codes' => 'cvc=123 and "card_cvv2": "4567" security_code: 999',
    'nested' => '{"data":"{\"password\":\"hunter two\",\"x\":1}"}', 'nested2' => '{\"access_token\":\"r16m-tok\"}',
    'hdr' => 'Cookie: PHPSESSID=abc123; cc_v1=x', 'jc' => '{"cookie":"a=b; c=d","n":1}', 'sid' => 'resumed PHPSESSID=deadbeef;',
    'cardNumber' => 'x', 'card_cvc' => '321',
    'prose' => 'migration 20261104120000_back_in_stock, order 1234567890123 EAN 5901234123457 at 20261105101500, Cookie consent given, not Luhn 4111111111111112',
]);
assert_equals([
    'spaced' => 'paid with [REDACTED CARD] ok', 'dashed' => 'card [REDACTED CARD]', 'amex' => 'amex [REDACTED CARD]',
    'jsonp' => '{"pan":"[REDACTED CARD]","exp":"12/29"}', 'number' => '[REDACTED CARD]',
    'codes' => 'cvc=[REDACTED] and "card_cvv2": "[REDACTED]" security_code: [REDACTED]',
    'nested' => '{"data":"{\"password\":\"[REDACTED]\",\"x\":1}"}', 'nested2' => '{\"access_token\":\"[REDACTED]\"}',
    'hdr' => 'Cookie: [REDACTED]', 'jc' => '{"cookie":"[REDACTED]","n":1}', 'sid' => 'resumed PHPSESSID=[REDACTED];',
    'cardNumber' => '[REDACTED]', 'card_cvc' => '[REDACTED]',
    'prose' => 'migration 20261104120000_back_in_stock, order 1234567890123 EAN 5901234123457 at 20261105101500, Cookie consent given, not Luhn 4111111111111112',
], $r16mLogged, 'R16 logs: card numbers, security codes, secrets in escaped JSON and cookies are masked; EANs, timestamps and prose stay');
// A card number at the end of a sentence, or followed by the CVC/expiry or preceded by another number, is still masked.
assert_equals([
    'stop' => 'Charge failed for [REDACTED CARD].', 'dstop' => 'card [REDACTED CARD].', 'astop' => 'amex [REDACTED CARD].',
    'cvcnext' => 'input [REDACTED CARD] 123', 'expnext' => 'card [REDACTED CARD] 12/26', 'lead' => 'qty 5 [REDACTED CARD] ok',
    'dec' => 'total 4242424242424242.50', 'dotted' => 'host 10.4242424242424242', 'notluhn' => 'ref 4242424242424241.',
    'longid' => 'id 42424242424242424242.',
], Logger::redact([
    'stop' => 'Charge failed for 4242424242424242.', 'dstop' => 'card 4242-4242-4242-4242.', 'astop' => 'amex 378282246310005.',
    'cvcnext' => 'input 4242 4242 4242 4242 123', 'expnext' => 'card 4242 4242 4242 4242 12/26', 'lead' => 'qty 5 4242 4242 4242 4242 ok',
    'dec' => 'total 4242424242424242.50', 'dotted' => 'host 10.4242424242424242', 'notluhn' => 'ref 4242424242424241.',
    'longid' => 'id 42424242424242424242.',
]), 'R16 logs: a card number before a full stop, a CVC or an expiry date is masked; decimals and longer ids stay');
$r16mErrLog = tempnam(sys_get_temp_dir(), 'r16m');
$r16mSavedErrLog = ini_set('error_log', $r16mErrLog);
try {
    Logger::phpLog('Charge failed for 4242424242424242 cvc: 123 Cookie: PHPSESSID=r16msess');
    $r16mPhp = (string)file_get_contents($r16mErrLog);
    assert_true(!str_contains($r16mPhp, '4242424242424242') && !str_contains($r16mPhp, 'r16msess') && !str_contains($r16mPhp, ': 123') && str_contains($r16mPhp, '[REDACTED CARD]'),
        'R16 logs: the PHP error log masks the same (' . trim($r16mPhp) . ')');
    file_put_contents($r16mErrLog, '');
    Logger::phpLog('Charge failed for 4242424242424242.');
    $r16mPhp = (string)file_get_contents($r16mErrLog);
    assert_true(!str_contains($r16mPhp, '4242424242424242') && str_contains($r16mPhp, '[REDACTED CARD].'),
        'R16 logs: the PHP error log masks a card number before a full stop (' . trim($r16mPhp) . ')');
} finally {
    ini_set('error_log', (string)$r16mSavedErrLog);
    @unlink($r16mErrLog);
}

// 3. The transfer reminder sweep read the 500 oldest pending orders without a reminder. Cash-on-delivery orders and
//    transfers past their due date never get one and stayed in that window for ever: once there were 500 of them,
//    no transfer was reminded again. Only orders inside the payment term are read now, page by page.
$r16mStore = StoreContext::current()->id();
$r16mTerm = PendingOrderExpiry::DEFAULT_OFFLINE_SECONDS['bank_transfer'];
$r16mInsert = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
    VALUES (?, 'R16 Remind', ?, '', '{}', 2000, 0, 0, 0, 2000, 'eur', 'pending', 'pending', ?, '', ?, ?, ?)");
$r16mOrder = static function (string $method, int $daysAgo, string $email) use ($r16mInsert, $r16mStore): int {
    $snapshot = ['provider' => $method, 'service_type' => $method, 'requires_online' => false, 'label' => $method];
    $r16mInsert->execute(['R16M-' . bin2hex(random_bytes(5)), $email, $method, json_encode($snapshot), $r16mStore, gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400)]);
    return (int)Db::get()->lastInsertId();
};
$r16mReminder = require TEST_CLI_DIR . '/../migrations/20261101120000_order_payment_reminder.php';
$r16mReminder->up($pdo, Db::isSqlite());
Db::forgetSchema();
try {
    $pdo->beginTransaction();
    for ($i = 0; $i < 520; $i++) $r16mOrder('cod', 40, 'r16m-cod@example.test');       // old cash on delivery, never reminded
    for ($i = 0; $i < 210; $i++) $r16mOrder('cod', 3, 'r16m-cod@example.test');        // recent ones: more than a page
    $r16mPast = $r16mOrder('bank_transfer', 25, 'r16m-past@example.test');              // past due and grace: never reminded
    $pdo->commit();
    $r16mDue = $r16mOrder('bank_transfer', 13, 'r16m-due@example.test');                // due tomorrow: reminder day
    $r16mMarked = static fn(int $id): bool => (string)(Orders::byId($id)['payment_reminder_sent_at'] ?? '') !== '';
    BankTransferInstructions::remindDue($r16mTerm);
    assert_true($r16mMarked($r16mDue) && !$r16mMarked($r16mPast),
        'R16 transfer reminders: 730 pending cash-on-delivery orders do not crowd out a transfer that is due tomorrow');
    $r16mCodMarked = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE customer_email = 'r16m-cod@example.test' AND payment_reminder_sent_at IS NOT NULL")->fetchColumn();
    assert_equals(0, $r16mCodMarked, 'R16 transfer reminders: cash-on-delivery orders get no transfer reminder');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // The queued reminder finds the order cancelled and sends nothing.
    if (isset($r16mDue) && (Orders::byId($r16mDue)['status'] ?? '') === 'pending') OrderWorkflow::transition($r16mDue, 'cancelled', 'R16 test cleanup');
    $pdo->exec("DELETE FROM orders WHERE order_number LIKE 'R16M-%' AND customer_email <> 'r16m-due@example.test'");
}

// 4. "Create and ship" without a carrier (the merchant's own delivery) mailed "Order X was handed to ." The shipped
//    e-mail names the carrier only when there is one; otherwise it says the order has been shipped.
$pdo->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, locale)
    VALUES (?, ?, 'shipped', 'paid', 'R16 Buyer', 'r16m-buyer@example.test', 'cod', 1000, 1000, 'eur', ?)")->execute(['R16M-SHIP-SK', $r16mStore, 'sk']);
$r16mShipSk = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, locale)
    VALUES (?, ?, 'shipped', 'paid', 'R16 Buyer', 'r16m-buyer@example.test', 'cod', 1000, 1000, 'eur', ?)")->execute(['R16M-SHIP-EN', $r16mStore, 'en']);
$r16mShipEn = (int)$pdo->lastInsertId();
$r16mShipment = $pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, tracking_number, tracking_url, idempotency_key, shipped_at) VALUES (?, ?, 'shipped', ?, NULL, NULL, ?, ?)");
$r16mShipped = static function (int $orderId): string {
    WorkerJobs::handleEmailSend(['template' => 'order_shipped', 'to' => 'r16m-buyer@example.test', 'order_id' => $orderId], []);
    return (string)(LogMail::lastCaptured()['html'] ?? '');
};
try {
    LogMail::clearCaptured();
    $r16mShipment->execute([$r16mStore, $r16mShipSk, null, 'r16m-ship-sk', '2026-11-05 10:00:00']);
    $r16mShipment->execute([$r16mStore, $r16mShipEn, '', 'r16m-ship-en', '2026-11-05 10:00:00']);
    $r16mSk = $r16mShipped($r16mShipSk);
    $r16mEn = $r16mShipped($r16mShipEn);
    assert_true(str_contains($r16mSk, 'R16M-SHIP-SK</strong> bola odoslaná.') && !str_contains($r16mSk, 'prepravcovi')
        && str_contains($r16mEn, 'R16M-SHIP-EN</strong> has been shipped.') && !str_contains($r16mEn, 'handed to'),
        'R16 shipped e-mail: without a carrier it says the order has been shipped, not "handed to ." (' . substr(strip_tags($r16mSk . ' | ' . $r16mEn), 0, 300) . ')');
    $r16mShipment->execute([$r16mStore, $r16mShipEn, 'DPD', 'r16m-ship-en-2', '2026-11-05 11:00:00']);
    $r16mEn = $r16mShipped($r16mShipEn);
    assert_true(str_contains($r16mEn, 'was handed to DPD.') && !str_contains($r16mEn, 'has been shipped'), 'R16 shipped e-mail: with a carrier it still names the carrier, once');
    foreach (['sk', 'cs', 'pl', 'hu', 'de', 'es', 'en'] as $r16mLang) {
        $r16mTpl = (string)file_get_contents(dirname(TEST_CLI_DIR) . "/templates/emails/{$r16mLang}/order_shipped.html");
        assert_true(str_contains($r16mTpl, '{{#if carrier}}') && str_contains($r16mTpl, '{{#if without_carrier}}'), "R16 shipped e-mail ({$r16mLang}): both sentences exist");
    }
} finally {
    $pdo->exec("DELETE FROM shipments WHERE idempotency_key LIKE 'r16m-ship-%'");
    $pdo->exec("DELETE FROM orders WHERE order_number LIKE 'R16M-SHIP-%'");
    LogMail::clearCaptured();
    I18n::setLocale($r16mLocale);
}
