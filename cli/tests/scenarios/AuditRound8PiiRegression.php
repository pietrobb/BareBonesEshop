<?php

// R8 personal data. The HTTP side of the invoice link (fragment, POST, expiry, Referrer-Policy) is checked in
// AuditStorefrontCoreRegression against the real entry point.
// 1) The invoice download token is stored only as a SHA-256, expires, is minted per mail and never lands in email_log.
echo "\n--- Audit round 8: personal data (invoice token, mail log, retention, erase, queue, logs, GDPR registry) ---\n";

$r8pDir = test_fixture_path('r8-pii');
mkdir($r8pDir, 0700, true);
$r8pDb = Db::get();
$r8pDb->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale)
    VALUES ('R8P-1', 'paid', 'paid', 'R8P Buyer', 'r8p-buyer@example.test', '{}', 'card', 1000, 1000, 'eur', 'default', 'sk')");
$r8pOrder = (int)$r8pDb->lastInsertId();
$r8pDb->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, due_date, pdf_path, total_net, total_vat, total_gross, currency, seller_snapshot, locale, status, download_token, type)
    VALUES (?, 'default', 'R8P-INV-1', '2026-10-22', '2026-11-05', 'r8p.pdf', 813, 187, 1000, 'eur', '{}', 'sk', 'issued', ?, 'invoice')")
    ->execute([$r8pOrder, InvoiceService::tokenHash(bin2hex(random_bytes(32)))]);
$r8pInvoice = (int)$r8pDb->lastInsertId();
$r8pRow = static fn(): array => $r8pDb->query('SELECT download_token, download_token_expires_at FROM invoices WHERE id = ' . $r8pInvoice)->fetch(PDO::FETCH_ASSOC);
$r8pTokenOf = static fn(string $link): string => substr((string)strrchr($link, '#'), 1);

assert_true(InvoiceService::byToken((string)$r8pRow()['download_token']) === null,
    'R8 PII invoice token: the stored value is a hash, not a working token');
assert_true(InvoiceService::byToken(str_repeat('0', 64)) === null, 'R8 PII invoice token: an issued invoice has no link until a mail mints one');

$r8pLink = InvoiceService::downloadLink($r8pInvoice);
$r8pToken = $r8pTokenOf($r8pLink);
$r8pStored = $r8pRow();
assert_true(preg_match('/^[0-9a-f]{64}$/', $r8pToken) === 1 && str_contains($r8pLink, 'account.php?p=invoice#') && !str_contains($r8pLink, 'token='),
    "R8 PII invoice token: the e-mailed link carries the token in the fragment, not the query ({$r8pLink})");
assert_true($r8pStored['download_token'] === hash('sha256', $r8pToken) && $r8pStored['download_token'] !== $r8pToken,
    'R8 PII invoice token: only the SHA-256 of the mailed token is stored');
$r8pTtl = strtotime($r8pStored['download_token_expires_at'] . ' UTC') - time();
assert_true(abs($r8pTtl - InvoiceService::DOWNLOAD_LINK_TTL_DAYS * 86400) < 120, "R8 PII invoice token: the link expires after 30 days ({$r8pTtl} s)");
assert_equals($r8pInvoice, (int)(InvoiceService::byToken($r8pToken)['id'] ?? 0), 'R8 PII invoice token: the mailed token finds its invoice');
assert_equals($r8pInvoice, (int)(InvoiceService::byToken(strtoupper($r8pToken))['id'] ?? 0), 'R8 PII invoice token: the token is case-insensitive hex');

$r8pSecond = $r8pTokenOf(InvoiceService::downloadLink($r8pInvoice));
assert_true(InvoiceService::byToken($r8pToken) === null && InvoiceService::byToken($r8pSecond) !== null,
    'R8 PII invoice token: a new mail replaces the previous link');
$r8pDb->prepare('UPDATE invoices SET download_token_expires_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $r8pInvoice]);
assert_true(InvoiceService::byToken($r8pSecond) === null, 'R8 PII invoice token: an expired link finds nothing');

// The invoice mail: the link is in the mail itself, the mail log keeps a redaction marker.
MailService::useAdapter(new LogMail(['log_file' => $r8pDir . '/mail.log']));
try {
    $r8pVars = InvoiceService::mailVars(InvoiceService::byId($r8pInvoice), Orders::byId($r8pOrder));
    $r8pSent = MailService::send('invoice_issued', 'r8p-buyer@example.test', 'sk', $r8pVars, $r8pOrder);
    $r8pMailed = $r8pTokenOf($r8pVars['invoice_download_url']);
    $r8pLog = $r8pDb->query("SELECT payload FROM email_log WHERE template = 'invoice_issued' AND order_id = {$r8pOrder} ORDER BY id DESC LIMIT 1")->fetchColumn();
    assert_true(($r8pSent['status'] ?? '') === 'sent' && str_contains((string)file_get_contents($r8pDir . '/mail.log'), $r8pMailed),
        'R8 PII invoice mail: the customer receives the working link');
    assert_true(is_string($r8pLog) && !str_contains($r8pLog, $r8pMailed) && str_contains($r8pLog, '"invoice_download_url":"[redacted]"'),
        'R8 PII invoice mail: email_log keeps no part of the link (' . substr((string)$r8pLog, 0, 200) . ')');
    assert_true(InvoiceService::byToken($r8pMailed) !== null, 'R8 PII invoice mail: sending minted a fresh, valid link');
} finally {
    MailService::useAdapter(null);
}

// Migration: plaintext tokens of existing invoices become hashes; links already mailed work for 30 more days.
$r8pOld = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r8pOld->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, download_token TEXT NOT NULL UNIQUE)');
$r8pLegacy = [];
for ($r8pI = 1; $r8pI <= 1003; $r8pI++) {
    $r8pLegacy[$r8pI] = bin2hex(random_bytes(32));
    $r8pOld->prepare('INSERT INTO invoices (id, download_token) VALUES (?, ?)')->execute([$r8pI, $r8pLegacy[$r8pI]]);
}
(require dirname(TEST_CLI_DIR) . '/migrations/20261022110000_invoice_download_token_hash.php')->up($r8pOld, true);
$r8pMigrated = $r8pOld->query('SELECT id, download_token, download_token_expires_at FROM invoices')->fetchAll(PDO::FETCH_ASSOC);
$r8pBad = array_filter($r8pMigrated, static fn(array $r): bool => $r['download_token'] !== hash('sha256', $r8pLegacy[(int)$r['id']])
    || abs(strtotime($r['download_token_expires_at'] . ' UTC') - time() - 30 * 86400) > 120);
assert_true(count($r8pMigrated) === 1003 && $r8pBad === [], 'R8 PII migration: every existing token (past one batch) is hashed and expires in 30 days (' . count($r8pBad) . ' wrong)');

// 2) The order's IP address, phone and note are no accounting data: erased with the customer, scrubbed after 180 days.
$r8pCustomer = static function (string $email) use ($r8pDb): int {
    $r8pDb->prepare("INSERT INTO customers (email, password_hash, name, phone) VALUES (?, 'x', 'R8P Person', '+421900000001')")->execute([$email]);
    return (int)$r8pDb->lastInsertId();
};
$r8pContactOrder = static function (?int $customer, string $email, int $ageDays) use ($r8pDb): int {
    $r8pDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, customer_phone, shipping_addr, payment_method,
            subtotal, total, currency, store_id, locale, ip_address, notes, created_at)
        VALUES (?, 'delivered', 'paid', ?, 'R8P Person', ?, '+421900000001', '{\"street\":\"R8P street 1\"}', 'card', 1000, 1000, 'eur', 'default', 'sk', '203.0.113.8', 'Ring twice, R8P', ?)")
        ->execute(['R8P-C-' . bin2hex(random_bytes(3)), $customer, $email, gmdate('Y-m-d H:i:s', time() - $ageDays * 86400)]);
    return (int)$r8pDb->lastInsertId();
};
$r8pContact = static fn(int $id): array => $r8pDb->query("SELECT customer_name, customer_email, shipping_addr, ip_address, customer_phone, notes FROM orders WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
$r8pErased = $r8pCustomer('r8p-erase@example.test');
$r8pErasedOrder = $r8pContactOrder($r8pErased, 'r8p-erase@example.test', 3);
$r8pOther = $r8pCustomer('r8p-keep@example.test');
$r8pOtherOrder = $r8pContactOrder($r8pOther, 'r8p-keep@example.test', 3);
GdprService::anonymizeCustomer($r8pErased, 'admin');
$r8pAfter = $r8pContact($r8pErasedOrder);
assert_equals(['', '', ''], [$r8pAfter['ip_address'], $r8pAfter['customer_phone'], $r8pAfter['notes']],
    'R8 PII erase: the IP address, phone and note of the customer\'s orders are erased');
assert_true($r8pAfter['customer_name'] === 'R8P Person' && str_contains((string)$r8pAfter['shipping_addr'], 'R8P street 1'),
    'R8 PII erase: name and address stay for the accounting retention');
assert_equals('203.0.113.8', $r8pContact($r8pOtherOrder)['ip_address'], 'R8 PII erase: another customer\'s order keeps its data');

$r8pPolicies = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/config/data-retention.json'), true)['policies'];
$r8pOld = $r8pContactOrder(null, 'r8p-guest@example.test', 200);
$r8pRecent = $r8pContactOrder(null, 'r8p-guest@example.test', 30);
$r8pRun = GdprService::applyRetention('orders_contact', (array)($r8pPolicies['orders_contact'] ?? []), 'apply');
$r8pOldRow = $r8pContact($r8pOld);
assert_true(($r8pPolicies['orders_contact']['ttl_days'] ?? null) === 180 && (int)$r8pRun['affected'] >= 1
    && !str_contains($r8pOldRow['ip_address'] . $r8pOldRow['customer_phone'] . $r8pOldRow['notes'], 'R8P') && $r8pOldRow['ip_address'] !== '203.0.113.8'
    && !str_contains($r8pOldRow['customer_phone'], '+421'),
    'R8 PII retention: after 180 days the IP address, phone and note of an order are scrubbed');
assert_true($r8pOldRow['customer_email'] === 'r8p-guest@example.test' && $r8pContact($r8pRecent)['ip_address'] === '203.0.113.8',
    'R8 PII retention: the e-mail stays (10-year policy) and a recent order keeps its contact data');

// 4) A queued cart reminder carries the cart id, not the recovery token: the worker builds the links when it sends.
$r8pToken4 = str_repeat('d', 32);
$r8pDb->prepare("INSERT INTO abandoned_carts (session_id, customer_email, cart_data, store_id, recovery_token, cart_value, item_count, reminder_consent_version, reminder_consent_at, created_at, updated_at)
    VALUES ('r8p-cart', 'r8p-cart@example.test', '[]', 'default', ?, 100, 1, 'v1', datetime('now'), datetime('now', '-30 hours'), datetime('now', '-30 hours'))")->execute([$r8pToken4]);
JobQueue::setDir($r8pDir . '/jobs');
// Loading the worker runs one pass over the (still empty) queue.
if (!function_exists('handleEmailSend')) {
    $argv = ['worker.php', '--once'];
    ob_start();
    require_once TEST_CLI_DIR . '/../cli/worker.php';
    ob_end_clean();
}
AbandonedCarts::sweep(['base_url' => 'https://shop.example']);
$r8pReminder = null;
foreach (JobQueue::pull(500) as $r8pJob) if (($r8pJob['payload']['to'] ?? '') === 'r8p-cart@example.test') $r8pReminder = $r8pJob;
$r8pPayload = json_encode($r8pReminder['payload'] ?? null);
assert_true($r8pReminder !== null && !str_contains((string)$r8pPayload, $r8pToken4),
    "R8 PII queue: the cart reminder job holds no recovery token ({$r8pPayload})");
MailService::useAdapter(new LogMail(['log_file' => $r8pDir . '/mail4.log']));
try {
    LogMail::clearCaptured();
    if ($r8pReminder !== null) handleEmailSend($r8pReminder['payload'], $r8pReminder);
    $r8pText = (string)(LogMail::lastCaptured()['text'] ?? '');
} finally {
    MailService::useAdapter(null);
}
assert_true(str_contains($r8pText, 'https://shop.example/?cart_recover=' . $r8pToken4) && str_contains($r8pText, 'cart_unsubscribe=' . $r8pToken4),
    'R8 PII queue: the worker still sends the working recovery and opt-out links');

// 5) Exception messages carry customer input (an e-mail in a failed INSERT, a provider error): the PHP log gets them masked.
$r8pPhpLog = $r8pDir . '/php-error.log';
$r8pPrevLog = ini_set('error_log', $r8pPhpLog);
try {
    Logger::phpLog("Order status change error [7]: SQLSTATE[23000]: UNIQUE failed (values 'Jan.Novak+eshop@example.co.uk', token=abc123secret)");
} finally {
    ini_set('error_log', (string)$r8pPrevLog);
}
$r8pLogged = (string)@file_get_contents($r8pPhpLog);
assert_true(str_contains($r8pLogged, 'Order status change error [7]') && str_contains($r8pLogged, '[email]')
    && !str_contains($r8pLogged, 'example.co.uk') && !str_contains($r8pLogged, 'abc123secret'),
    "R8 PII logs: the PHP log line keeps the event but masks the e-mail and the token ({$r8pLogged})");
$r8pRaw = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(TEST_CLI_DIR) . '/src', FilesystemIterator::SKIP_DOTS)) as $r8pFile) {
    if ($r8pFile->getExtension() !== 'php' || $r8pFile->getFilename() === 'Logger.php') continue;
    if (preg_match('/(?<![\w>:])error_log\(/', (string)file_get_contents($r8pFile->getPathname()))) $r8pRaw[] = $r8pFile->getFilename();
}
assert_equals([], $r8pRaw, 'R8 PII logs: application code writes the PHP log only through Logger::phpLog');

// 6) payment_transactions.raw_response is in the GDPR registry: erasure removes the payer the gateway named and keeps
// what the refund and duplicate-capture checks read.
$r8pPayer = $r8pCustomer('r8p-payer@example.test');
$r8pPayerOrder = $r8pContactOrder($r8pPayer, 'r8p-payer@example.test', 3);
$r8pDb->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response, created_at, updated_at)
    VALUES (?, 'default', 'stripe', 'cs_r8p', 'paid', 1000, 'eur', 'r8p-pay', ?, datetime('now'), datetime('now'))")
    ->execute([$r8pPayerOrder, json_encode(['event_type' => 'checkout.session.completed', 'payment_id' => 'pi_r8p', 'session_id' => 'cs_r8p',
        'receipt_email' => 'r8p-payer@example.test', 'customer_details' => ['email' => 'r8p-payer@example.test', 'name' => 'R8P Payer', 'address' => ['line1' => 'Payer street 9']],
        'note' => 'R8P-PAYER@example.test'])]);
assert_equals('scrub', GdprService::PII_REGISTRY['payment_transactions']['erase'] ?? null, 'R8 PII registry: payment_transactions.raw_response is registered for erasure');
GdprService::anonymizeCustomer($r8pPayer, 'admin');
$r8pTx = (string)$r8pDb->query("SELECT raw_response FROM payment_transactions WHERE idempotency_key = 'r8p-pay'")->fetchColumn();
$r8pTxData = json_decode($r8pTx, true);
assert_true(!str_contains(strtolower($r8pTx), 'r8p-payer@') && !str_contains($r8pTx, 'R8P Payer') && !str_contains($r8pTx, 'Payer street'),
    "R8 PII erase: the gateway response loses the payer's e-mail, name and address ({$r8pTx})");
assert_true(($r8pTxData['payment_id'] ?? '') === 'pi_r8p' && ($r8pTxData['event_type'] ?? '') === 'checkout.session.completed' && ($r8pTxData['session_id'] ?? '') === 'cs_r8p',
    'R8 PII erase: payment id, session and event stay for refunds and the duplicate-capture check');
