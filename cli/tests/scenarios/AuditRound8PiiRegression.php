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
