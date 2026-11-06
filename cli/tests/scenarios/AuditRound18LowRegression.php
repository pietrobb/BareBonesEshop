<?php

// Review round 18 (2026-11-06), low findings and UX nits (goal #331).

echo "\n--- R18 low: dev configs over HTTP ---\n";

// The JS tool configs at the web root (eslint, Playwright, Lighthouse CI) were served as static files:
// they list the test routes, fixture credentials and the internal URLs of the CI setup.
$r18lRoot = dirname(TEST_CLI_DIR);
$r18lProbe = stream_socket_server('tcp://127.0.0.1:0');
$r18lPort = (int)substr(strrchr((string)stream_socket_get_name($r18lProbe, false), ':'), 1);
fclose($r18lProbe);
$r18lLog = test_fixture_path('r18-low-router') . '.log';
$r18lServer = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$r18lPort}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $r18lLog, 'a'], 2 => ['file', $r18lLog, 'a']], $r18lPipes, $r18lRoot);
$r18lStatus = static function (string $path) use ($r18lPort): int {
    $context = stream_context_create(['http' => ['method' => 'GET', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 15]]);
    @file_get_contents("http://127.0.0.1:{$r18lPort}{$path}", false, $context);
    return (int)(explode(' ', (string)(HttpResponseHeaders::last($http_response_header ?? null)[0] ?? ''))[1] ?? 0);
};
try {
    for ($i = 0; $i < 50 && $r18lStatus('/robots.txt') === 0; $i++) usleep(100000);
    foreach (['/eslint.config.js', '/playwright.config.js', '/lighthouserc.js', '/Playwright.Config.JS', '/eslint.config.mjs'] as $r18lPath) {
        assert_equals(403, $r18lStatus($r18lPath), "R18 low: {$r18lPath} is not served over HTTP");
    }
    $r18lAsset = glob($r18lRoot . '/themes/*/assets/*.js')[0] ?? null;
    assert_true($r18lAsset !== null, 'R18 low: a theme script exists to check');
    assert_equals(200, $r18lStatus('/' . substr((string)$r18lAsset, strlen($r18lRoot) + 1)), 'R18 low: theme scripts stay public');
} finally {
    proc_terminate($r18lServer);
    proc_close($r18lServer);
    @unlink($r18lLog);
}
$r18lNginx = (string)file_get_contents($r18lRoot . '/deploy/nginx.conf');
assert_true(str_contains($r18lNginx, '|[^/]+\.config\.[cm]?js|lighthouserc\.[cm]?js)$ { return 403; }'), 'R18 low: nginx.conf denies the same root configs');

echo "\n--- R18 UX: invoice e-mail link in the customer's language ---\n";

// The invoice link was built with storeUrl(), which adds the ?lang= of whoever sends the mail: an admin resending
// an English customer's invoice from a Spanish admin sent the customer to the Spanish invoice page.
$r18lSavedLocale = I18n::locale();
$r18lSavedStore = StoreContext::current();
try {
    Bootstrap::initStoreContext('/en/', '', 'us');
    I18n::setLocale('es');
    $r18lInvoice = ['id' => 0, 'number' => 'R18L-1', 'total_gross' => 1000, 'currency' => 'usd', 'issued_at' => '2026-11-06', 'due_date' => '2026-11-20'];
    $r18lLink = static fn(array $order): string => (string)InvoiceService::mailVars($r18lInvoice, $order + ['order_number' => 'R18L'])['invoice_download_url'];
    $r18lEnglish = $r18lLink(['locale' => 'en']);
    assert_true(!str_contains($r18lEnglish, 'lang=') && preg_match('~/en/account\.php\?p=invoice#[0-9a-f]{64}$~', $r18lEnglish) === 1,
        "R18 UX: an English order's invoice link carries no ?lang= of the sending admin ({$r18lEnglish})");
    I18n::setLocale('en');
    $r18lSpanish = $r18lLink(['locale' => 'es']);
    assert_true(str_contains($r18lSpanish, '?p=invoice&lang=es#'), "R18 UX: a Spanish order's invoice link opens the Spanish page ({$r18lSpanish})");
    I18n::setLocale('es');
    assert_true(!str_contains($r18lLink(['locale' => '']), 'lang='), 'R18 UX: an order without a language gets the store language');
} catch (Throwable $e) {
    assert_true(false, 'R18 UX invoice link (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    StoreContext::activate($r18lSavedStore);
    I18n::setLocale($r18lSavedLocale);
}

echo "\n--- R18 UX: dates on documents ---\n";

// The invoice / credit note PDF printed its dates as 2026-10-24 whatever the document language, and the delivery
// note printed the UTC day of created_at the same way (an order placed at 00:30 in Bratislava had the day before).
$r18lSavedLocale = I18n::locale();
try {
    $r18lPdf = (string)(new ReflectionMethod(InvoiceService::class, 'renderPdf'))->invoke(null,
        ['locale' => 'sk', 'currency' => 'eur', 'type' => 'invoice', 'number' => 'R18L-2026-1', 'order_id' => 97951,
            'issued_at' => '2026-10-24', 'delivery_date' => '2026-10-23', 'due_date' => '2026-11-07', 'payment_method' => 'Prevod',
            'total_net' => 1000, 'total_vat' => 230, 'total_gross' => 1230],
        [['name' => 'R18L lampa', 'qty' => 1, 'rate' => 23.0, 'net' => 1000, 'gross' => 1230]], ['name' => 'R18L s.r.o.'], ['name' => 'Kupujúci'],
        ['rates' => ['23' => ['net' => 1000, 'vat' => 230]]]);
    preg_match_all('/\((.*?)\) Tj/', $r18lPdf, $r18lRuns);
    $r18lText = implode("\n", $r18lRuns[1]);
    assert_true(str_contains($r18lText, '24. 10. 2026') && str_contains($r18lText, '23. 10. 2026') && str_contains($r18lText, '7. 11. 2026'),
        'R18 UX: a Slovak invoice prints its dates in the Slovak format');
    assert_equals(0, preg_match('/\d{4}-\d{2}-\d{2}/', $r18lText), 'R18 UX: no ISO date is left on the invoice');

    $r18lOrder = ['id' => 97952, 'order_number' => 'R18L-DN', 'locale' => 'sk', 'customer_name' => 'R18L Zákazník', 'created_at' => '2026-11-05 23:30:00', 'store_id' => StoreContext::current()->id()];
    $r18lNote = ['order' => $r18lOrder, 'items' => [['sku' => 'R18L', 'name' => 'Lampa', 'quantity' => 1]], 'address' => ['city' => 'Bratislava'], 'pickup' => null, 'rate' => [], 'shipment' => null];
    $r18lDay = (new DateTimeImmutable('2026-11-05 23:30:00', new DateTimeZone('UTC')))->setTimezone(ShopTime::zone())->format('j. n. Y');
    $r18lHtml = DeliveryNote::html([$r18lNote]);
    assert_true(str_contains($r18lHtml, ': ' . $r18lDay . '</p>') && !str_contains($r18lHtml, '2026-11-0'),
        "R18 UX: the delivery note shows the order's local day in the note's language ({$r18lDay})");
    preg_match_all('/\((.*?)\) Tj/', DeliveryNote::pdf([$r18lNote]), $r18lNoteRuns);
    assert_true(in_array(': ' . $r18lDay, array_map(static fn(string $run): string => (string)strstr($run, ': '), $r18lNoteRuns[1]), true),
        'R18 UX: the delivery note PDF shows the same date');
} catch (Throwable $e) {
    assert_true(false, 'R18 UX document dates (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    StoreContext::activate($r18lSavedStore);
    I18n::setLocale($r18lSavedLocale);
}

echo "\n--- R18 UX: e-mail sender ---\n";

// Every e-mail came from a bare noreply@ address: no store name in the inbox, and a customer's reply went to the
// noreply mailbox unless MAIL_REPLY_TO was set.
$r18lSavedReply = getenv('MAIL_REPLY_TO');
try {
    putenv('MAIL_REPLY_TO=');
    Bootstrap::initStoreContext('/', '', 'default');
    $r18lBrand = Template::branding();
    LogMail::clearCaptured();
    MailService::useAdapter(new LogMail(['log_file' => test_fixture_path('r18-low-mail') . '.log']));
    $r18lSent = MailService::send('order_paid', 'r18l@example.com', 'sk', ['order_number' => 'R18L-M', 'customer_name' => 'R18L', 'order_total' => '1,00 €']);
    $r18lMail = LogMail::lastCaptured() ?? [];
    assert_equals('sent', $r18lSent['status'] ?? '', 'R18 UX: the mail is sent');
    assert_equals((string)$r18lBrand['name'], $r18lMail['from_name'] ?? null, 'R18 UX: the From line names the store');
    assert_equals('info@example.sk', $r18lMail['reply_to'] ?? null, "R18 UX: replies go to the store's contact address");
    putenv('MAIL_REPLY_TO=orders@example.sk');
    MailService::send('order_paid', 'r18l@example.com', 'sk', ['order_number' => 'R18L-M2', 'customer_name' => 'R18L', 'order_total' => '1,00 €']);
    assert_equals('orders@example.sk', (LogMail::lastCaptured() ?? [])['reply_to'] ?? null, 'R18 UX: MAIL_REPLY_TO still wins');

    $r18lHeader = static function (array $msg): string {
        preg_match('/^From: (.*(?:\r\n .*)*)\r\n/m', MimeMessage::build($msg + ['to' => 'a@example.com', 'subject' => 's', 'text' => 'x']), $m);
        return (string)($m[1] ?? '');
    };
    $r18lUnicode = $r18lHeader(['from' => 'noreply@example.sk', 'from_name' => 'Kníhkupectvo "U Žabky"']);
    assert_true(str_ends_with($r18lUnicode, ' <noreply@example.sk>') && iconv_mime_decode('From: ' . $r18lUnicode, 0, 'UTF-8') === 'From: Kníhkupectvo "U Žabky" <noreply@example.sk>',
        "R18 UX: a non-ASCII store name is an encoded word before the address ({$r18lUnicode})");
    assert_equals('"Woodle \"SK\"" <noreply@example.sk>', $r18lHeader(['from' => 'noreply@example.sk', 'from_name' => 'Woodle "SK"']), 'R18 UX: an ASCII store name is a quoted string');
    assert_equals('"WoodleBcc: x@example.com" <noreply@example.sk>', $r18lHeader(['from' => 'noreply@example.sk', 'from_name' => "Woodle\r\nBcc: x@example.com"]), 'R18 UX: a line break in the store name cannot add a header');
    assert_equals('noreply@example.sk', $r18lHeader(['from' => 'noreply@example.sk']), 'R18 UX: without a store name the From line is the address');
} catch (Throwable $e) {
    assert_true(false, 'R18 UX e-mail sender (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r18lSavedReply === false ? putenv('MAIL_REPLY_TO') : putenv('MAIL_REPLY_TO=' . $r18lSavedReply);
    MailService::useAdapter(null);
    @unlink(test_fixture_path('r18-low-mail') . '.log');
    StoreContext::activate($r18lSavedStore);
}

echo "\n--- R18 UX: raw codes in the admin ---\n";

// The GDPR event log, the webhook incidents and the audit log printed their internal codes (admin_anonymize,
// unresolved_dead, update), and the legal-text buttons the page ids (terms (sk)).
if (!function_exists('adminCodeLabel')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
$r18lAdminSrc = static fn(string $file): string => (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/' . $file);
$r18lSavedLocale = I18n::locale();
try {
    $r18lGdprActions = preg_match('/\$allowed = \[([^\]]+)\]/', (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/GdprService.php'), $r18lM)
        ? array_map(static fn(string $c): string => trim($c, " '"), explode(',', $r18lM[1])) : [];
    assert_true(count($r18lGdprActions) >= 6, 'R18 UX: the GDPR event actions are read from GdprService::logEvent (' . implode(',', $r18lGdprActions) . ')');
    $r18lCodes = ['admin.gdpr.action.' => $r18lGdprActions, 'admin.ops.webhook_status.' => ['retry', 'dead', 'unresolved_retry', 'unresolved_dead'],
        'admin.audit.action.' => ['create', 'update', 'delete']];
    foreach (['en', 'sk', 'de'] as $r18lLocale) {
        I18n::setLocale($r18lLocale);
        foreach ($r18lCodes as $r18lPrefix => $r18lList) {
            foreach ($r18lList as $r18lCode) {
                assert_true(adminCodeLabel($r18lPrefix, $r18lCode) !== $r18lCode, "R18 UX: {$r18lPrefix}{$r18lCode} has a {$r18lLocale} label");
            }
        }
    }
    assert_true(str_contains($r18lAdminSrc('Customers.php'), "adminCodeLabel('admin.gdpr.action.', (string)\$e['action'])") && !str_contains($r18lAdminSrc('Customers.php'), "<td><?= esc(\$e['action']) ?></td>"),
        'R18 UX: the GDPR event log shows the action label');
    $r18lOps = $r18lAdminSrc('Operations.php');
    assert_true(str_contains($r18lOps, "adminCodeLabel('admin.ops.webhook_status.', (string)\$incident['status'])") && !str_contains($r18lOps, "<td><?= esc(\$incident['status']) ?></td>"),
        'R18 UX: webhook incidents show the status label');
    assert_true(str_contains($r18lOps, "adminCodeLabel('admin.audit.action.', (string)\$log['action'])") && !str_contains($r18lOps, "<td><?= esc(\$log['action']) ?></td>"),
        'R18 UX: the audit log shows the action label');
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/StoreManagement.php';
    I18n::setLocale('sk');
    foreach (ConsumerNotice::LEGAL_PAGES as $r18lPage) {
        $r18lLabel = I18n::t(ADMIN_LEGAL_PAGE_LABELS[$r18lPage] ?? $r18lPage);
        assert_true(isset(ADMIN_LEGAL_PAGE_LABELS[$r18lPage]) && $r18lLabel !== ADMIN_LEGAL_PAGE_LABELS[$r18lPage] && $r18lLabel !== $r18lPage,
            "R18 UX: the legal text button for {$r18lPage} is named ({$r18lLabel})");
    }
    assert_true(!str_contains($r18lAdminSrc('StoreManagement.php'), "#store-legal\"><?= esc(\$legalPage . ' ('"), 'R18 UX: the legal text buttons no longer print the page id');
} catch (Throwable $e) {
    assert_true(false, 'R18 UX admin codes (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    I18n::setLocale($r18lSavedLocale);
}

echo "\n--- R18 UX: store time zone in the admin ---\n";

// The store time zone (invoice dates and numbers, deadlines, the daily report) could only be set by editing
// stores.json; doctor warned about a missing one with no way to fix it from Admin → Stores and seller.
$r18lTmp = test_fixture_path('r18-low-tz');
@mkdir($r18lTmp, 0700, true);
try {
    copy(dirname(TEST_CLI_DIR) . '/stores.example.json', $r18lTmp . '/stores.json');
    StoreSettings::setPathForTesting($r18lTmp . '/stores.json');
    $r18lStore = StoreSettings::load()['stores']['default'];
    $r18lInput = ['store_name' => (string)$r18lStore['name'], 'iban' => 'SK31 1200 0000 1987 4263 7541'] + array_map('strval', array_filter($r18lStore['legal'], 'is_scalar'))
        + ['payment_terms_days' => (string)($r18lStore['legal']['payment_terms_days'] ?? '14'), 'vat_registered' => !empty($r18lStore['legal']['vat_registered']) ? '1' : ''];
    $r18lSaved = StoreSettings::save('default', ['timezone' => 'Europe/Budapest'] + $r18lInput);
    $r18lAfter = StoreSettings::load()['stores']['default'];
    assert_true($r18lSaved['ok'] && ($r18lAfter['timezone'] ?? null) === 'Europe/Budapest' && StoreContext::timezoneFor($r18lAfter) === 'Europe/Budapest',
        'R18 UX: the admin saves the store time zone (' . implode(' ', $r18lSaved['errors']) . ')');
    foreach (['Mars/Olympus', 'europe/bratislava ', '', '../UTC'] as $r18lBad) {
        $r18lBefore = (string)file_get_contents($r18lTmp . '/stores.json');
        $r18lResult = StoreSettings::save('default', ['timezone' => $r18lBad] + $r18lInput);
        assert_true(!$r18lResult['ok'] && in_array(I18n::t('admin.stores.err.timezone'), $r18lResult['errors'], true) && $r18lBefore === (string)file_get_contents($r18lTmp . '/stores.json'),
            "R18 UX: an unknown time zone \"{$r18lBad}\" is refused and nothing is saved");
    }
    assert_true(StoreSettings::save('default', $r18lInput)['ok'] && (StoreSettings::load()['stores']['default']['timezone'] ?? null) === 'Europe/Budapest',
        'R18 UX: a form without the field keeps the saved zone');
    $r18lForm = $r18lAdminSrc('StoreSettings.php');
    assert_true(str_contains($r18lForm, 'name="timezone"') && str_contains($r18lForm, 'DateTimeZone::listIdentifiers()') && str_contains($r18lForm, 'StoreContext::timezoneFor($store)'),
        'R18 UX: the store form offers every time zone with the effective one selected');
    foreach (['en', 'sk'] as $r18lLocale) {
        I18n::setLocale($r18lLocale);
        foreach (['admin.stores.field.timezone', 'admin.stores.timezone_hint', 'admin.stores.err.timezone'] as $r18lKey) assert_true(I18n::t($r18lKey) !== $r18lKey, "R18 UX: {$r18lKey} is translated ({$r18lLocale})");
    }
} catch (Throwable $e) {
    assert_true(false, 'R18 UX store time zone (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    StoreSettings::setPathForTesting(null);
    I18n::setLocale($r18lSavedLocale);
    @unlink($r18lTmp . '/stores.json');
    @unlink($r18lTmp . '/stores.json.lock');
    @rmdir($r18lTmp);
}
