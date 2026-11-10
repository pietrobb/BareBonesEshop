<?php

// Review round 15, medium: content, e-mails and GDPR.
echo "\n--- Audit round 15: content, e-mails and GDPR ---\n";

// 1. The order confirmation's legal information cannot be hidden by colour: white text, the text colour equal to the
//    background or <font color> made it unreadable while the guard counted it as seen.
$r15cLocale = I18n::locale();
I18n::setLocale('en');
$r15cLegal = '{{seller_name}}, {{seller_address}} <a href="{{withdrawal_url}}">withdraw</a> <a href="{{terms_url}}">terms</a>';
$r15cRequiredPrefix = (string)strtok(I18n::t('admin.email.msg.required_vars'), '{');
$r15cRefused = static fn(string $html): bool => array_filter(EmailTemplates::validate('order_confirmation', 'Order {{order_number}}', $html, ''),
    static fn(string $e): bool => str_starts_with($e, $r15cRequiredPrefix)) !== [];
$r15cCases = [
    // refused: the text cannot be told from its background
    'color:white' => ['<p style="color:white">' . $r15cLegal . '</p>', true],
    'color:#FFF' => ['<div style="font-size:13px; COLOR: #FFF">' . $r15cLegal . '</div>', true],
    'same text and background' => ['<p style="color:#2b6cb0;background-color:#2b6cb0">' . $r15cLegal . '</p>', true],
    'font color' => ['<font color="#ffffff">' . $r15cLegal . '</font>', true],
    'font color named' => ['<font color=snow>' . $r15cLegal . '</font>', true],
    'near white' => ['<p style="color:#f4f4f4">' . $r15cLegal . '</p>', true],
    'transparent rgba' => ['<p style="color:rgba(0,0,0,.04)">' . $r15cLegal . '</p>', true],
    'hsl white' => ['<p style="color:hsl(0 0% 100%)">' . $r15cLegal . '</p>', true],
    'entity-encoded colour' => ['<p style="color:&#x23;fff">' . $r15cLegal . '</p>', true],
    'class rule' => ['<style>.fine { color: #fefefe }</style><p class="note fine">' . $r15cLegal . '</p>', true],
    'body rule' => ['<style>body { color: #fff }</style>' . $r15cLegal, true],
    'child rule' => ['<style>.box > p { color: #fff }</style><div class="box"><p>' . $r15cLegal . '</p></div>', true],
    '!important rule over inline' => ['<style>p { color: #fff !important }</style><p style="color:#000">' . $r15cLegal . '</p>', true],
    'text-fill transparent' => ['<p style="-webkit-text-fill-color:transparent">' . $r15cLegal . '</p>', true],
    'unknown colour (var)' => ['<p style="color:var(--x)">' . $r15cLegal . '</p>', true],
    'dark cell, dark text' => ['<table><tr><td bgcolor="#000000"><span style="color:#111">' . $r15cLegal . '</span></td></tr></table>', true],
    'default link blue on a dark cell' => ['<table><tr><td bgcolor="#1a202c"><font color="#fff">' . $r15cLegal . '</font></td></tr></table>', true],
    // accepted: readable
    'plain' => ['<p>' . $r15cLegal . '</p>', false],
    'grey footer' => ['<p style="color:#666;font-size:12px">' . $r15cLegal . '</p>', false],
    'white on a dark cell' => ['<table><tr><td bgcolor="#1a202c"><font color="#fff">' . str_replace('<a ', '<a style="color:#90cdf4" ', $r15cLegal) . '</font></td></tr></table>', false],
    'white on a dark rule background' => ['<style>.dark td { background: #000 url(x.png) no-repeat; color: #fff }</style><table class="dark"><tr><td>' . $r15cLegal . '</td></tr></table>', false],
    'readable child of a white parent' => ['<div style="color:#fff"><p style="color:#222">' . $r15cLegal . '</p></div>', false],
    'hover colour only' => ['<style>a:hover { color: #fff }</style><p>' . $r15cLegal . '</p>', false],
];
$r15cGot = $r15cWant = [];
foreach ($r15cCases as $r15cName => [$r15cHtml, $r15cRefuse]) {
    $r15cWant[$r15cName] = $r15cRefuse;
    $r15cGot[$r15cName] = $r15cRefused($r15cHtml);
}
assert_equals($r15cWant, $r15cGot, 'R15 e-mail guard: legal text in a colour that cannot be read against its background counts as missing');
$r15cWhiteLink = str_replace('<a href="{{withdrawal_url}}">', '<a href="{{withdrawal_url}}" style="color:#fff">', '<p>' . $r15cLegal . '</p>');
assert_true($r15cRefused($r15cWhiteLink), 'R15 e-mail guard: a withdrawal link in white text does not count as the link');
I18n::setLocale($r15cLocale);
assert_equals([21.0, 1.0, true], [round(CssColor::contrast(CssColor::BLACK, CssColor::WHITE), 2), round(CssColor::contrast(CssColor::WHITE, CssColor::WHITE), 2),
    CssColor::contrast((array)CssColor::parse('#fff'), (array)CssColor::parse('#4299e1')) >= CssColor::MIN_CONTRAST],
    'R15 e-mail guard: WCAG contrast (black/white 21, same 1; the shipped white-on-blue button stays readable)');

// 2. A legal text the merchant edits announces the day of the edit: the editor starts from the shipped text and its
//    <!-- legal-updated --> date, so an edited page kept the shipped date. Changing the seller details the pages print
//    dates them too, and a store-wide legal.updated_at no longer hides a newer text.
$r15cTmp = test_fixture_path('r15-content');
mkdir($r15cTmp . '/data', 0777, true);
try {
    copy(dirname(TEST_CLI_DIR) . '/stores.example.json', $r15cTmp . '/stores.json');
    StoreSettings::setPathForTesting($r15cTmp . '/stores.json');
    DataOverrides::setDirForTesting($r15cTmp . '/data');
    $r15cToday = date('Y-m-d');
    $r15cShippedHtml = (string)file_get_contents(ConsumerNotice::legalTemplatePath('terms', 'en', 'default'));
    preg_match('/<!--\s*legal-updated:\s*(\d{4}-\d{2}-\d{2})\s*-->/', $r15cShippedHtml, $r15cShipped);
    $r15cDateShown = static fn(string $date, array $legal = []): bool => str_contains(ConsumerNotice::renderLegal('terms', 'en', ['name' => 'R15C s.r.o.'] + $legal, 'default'), I18n::date($date, 'en'));
    assert_true(isset($r15cShipped[1]) && $r15cShipped[1] < $r15cToday && $r15cDateShown($r15cShipped[1]), 'R15 legal date fixture: the shipped terms show their own older date');

    $r15cEdited = str_replace('</h1>', "</h1>\n<p>R15C extra clause.</p>", $r15cShippedHtml);
    assert_true(DataOverrides::saveLegal('terms', 'en', ConsumerNotice::stampLegalText('terms', 'en', $r15cEdited, 'default'), 'default')
        && $r15cDateShown($r15cToday) && !$r15cDateShown($r15cShipped[1]), 'R15 legal date: the edited terms show the day of the edit, not the shipped date');

    $r15cOld = "<!-- legal-updated: 2026-01-15 -->\n<h1>R15C terms of {{name}}</h1>\n<p>{{updated_at}}</p>\n";
    DataOverrides::saveLegal('terms', 'en', $r15cOld, 'default');
    assert_equals([$r15cOld, $r15cOld], [ConsumerNotice::stampLegalText('terms', 'en', $r15cOld, 'default'),
        ConsumerNotice::stampLegalText('terms', 'en', "<h1>R15C terms of {{name}}</h1>\r\n<p>{{updated_at}}</p>\r\n", 'default')],
        'R15 legal date: saving the text unchanged keeps its date (also without the marker or with CRLF)');
    assert_true(str_starts_with(ConsumerNotice::stampLegalText('terms', 'en', "<!-- legal-updated: 2020-01-01 -->\n<h1>R15C changed {{name}}</h1>", 'default'), "<!-- legal-updated: {$r15cToday} -->\n<h1>R15C changed"),
        'R15 legal date: a typed-in old marker does not back-date a changed text');
    assert_equals(['', '  '], [ConsumerNotice::stampLegalText('terms', 'en', '', 'default'), ConsumerNotice::stampLegalText('terms', 'en', '  ', 'default')],
        'R15 legal date: an empty text stays empty (reset to the shipped text)');
    assert_equals('x', ConsumerNotice::stampLegalText('../../config', 'en', 'x', 'default'), 'R15 legal date: an unknown page is not read or stamped');
    assert_equals([true, true], [$r15cDateShown('2026-01-15', ['updated_at' => '2020-01-01']), $r15cDateShown('2026-02-01', ['updated_at' => '2026-02-01'])],
        'R15 legal date: the page shows the later of its text date and the store\'s legal.updated_at');

    $r15cLegal = StoreSettings::load()['stores']['default']['legal'];
    $r15cInput = ['store_name' => 'R15C Shop'] + array_map('strval', array_filter($r15cLegal, 'is_scalar')) + ['payment_terms_days' => '14', 'vat_registered' => '1'];
    $r15cInput['iban'] = 'SK31 1200 0000 1987 4263 7541';
    $r15cSaved = StoreSettings::save('default', ['street' => 'Nová 15'] + $r15cInput);
    assert_true($r15cSaved['ok'] && (StoreSettings::load()['stores']['default']['legal']['updated_at'] ?? null) === $r15cToday,
        'R15 legal date: changing the seller address dates the legal pages (' . implode(' ', $r15cSaved['errors']) . ')');
    $r15cData = json_decode((string)file_get_contents($r15cTmp . '/stores.json'), true);
    $r15cData['stores']['default']['legal']['updated_at'] = '2020-01-01';
    file_put_contents($r15cTmp . '/stores.json', json_encode($r15cData));
    StoreSettings::save('default', ['street' => 'Nová 15', 'bic' => 'TATRSKBX'] + $r15cInput);
    assert_equals('2020-01-01', StoreSettings::load()['stores']['default']['legal']['updated_at'] ?? null, 'R15 legal date: a bank detail the pages do not print does not re-date them');
} finally {
    StoreSettings::setPathForTesting(null);
    DataOverrides::setDirForTesting(null);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r15cTmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $r15cItem) {
        $r15cItem->isDir() ? @rmdir($r15cItem->getPathname()) : @unlink($r15cItem->getPathname());
    }
    @rmdir($r15cTmp);
}

// 3. Anonymizing a guest in the admin changed nothing the merchant could see: the GDPR page showed the same row, order
//    count and button, and the flash was a bare "anonymized". The orders stay (accounting retention), so the page now
//    says the address was erased and what was kept, the flash says what went, and the button returns only for new data.
//    Each request is its own PHP process (the handler ends with exit) on one SQLite file.
$r15gRoot = test_fixture_path('r15-gdpr-guest');
mkdir($r15gRoot);
$r15gFixture = $r15gRoot . '/request.php';
file_put_contents($r15gFixture, <<<'PHP'
<?php
[, $root, $target, $phase] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Customers.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$db = new PDO('sqlite:' . $target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($db, 'sqlite');
$guestOrder = static function(string $number, string $createdAt) use ($db): void {
    $db->prepare("INSERT INTO orders (order_number, store_id, customer_name, customer_email, customer_phone, shipping_addr, payment_method, subtotal, total, notes, ip_address, created_at)
        VALUES (?, 'default', 'R15G Guest', 'R15G-Guest@Example.test', '+421900111222', '{\"street\":\"Hlavná 1\"}', 'cod', 1000, 1000, 'call me', '198.51.100.7', ?)")->execute([$number, $createdAt]);
};
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate.lock'))->apply();
    $guestOrder('R15G-1', '2026-01-02 03:04:05');
    $guestOrder('R15G-2', '2026-01-03 03:04:05');
    $db->exec("INSERT INTO orders (order_number, store_id, customer_name, customer_email, payment_method, subtotal, total) VALUES ('R15G-OTHER', 'default', 'Other', 'r15g-other@example.test', 'cod', 1, 1)");
    exit(0);
}
if ($phase === 'new_order') { $guestOrder('R15G-3', '2999-01-01 00:00:00'); exit(0); }
StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), '/', '', 'default'));
I18n::setLocale('en');
$_SESSION = ['admin_role' => 'owner', 'admin_user' => 'r15g', 'csrf_token' => 'r15g-token'];
$_GET = ['p' => 'gdpr', 'email' => 'r15g-guest@example.test'];
ob_start();
register_shutdown_function(static function() use ($db): void {
    $output = (string)ob_get_clean();
    $orders = $db->query("SELECT order_number, customer_phone, notes, ip_address FROM orders WHERE order_number LIKE 'R15G-%' ORDER BY order_number")->fetchAll();
    print json_encode(['output' => $output, 'flash' => $_SESSION['flash'] ?? null, 'orders' => $orders], JSON_THROW_ON_ERROR);
});
if ($phase === 'anonymize') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf_token' => 'r15g-token', 'guest_email' => 'r15g-guest@example.test'];
    handleGdprAnonymize();
}
pageGdpr();
PHP);
$r15gRun = static function(string $phase) use ($r15gFixture, $r15gRoot): array {
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($r15gFixture) . ' ' . escapeshellarg(dirname(TEST_CLI_DIR)) . ' '
        . escapeshellarg($r15gRoot . '/shop.sqlite') . ' ' . escapeshellarg($phase) . ' 2>&1');
    return json_decode($out, true) ?? ['output' => $out, 'flash' => null, 'orders' => []];
};
$r15gButton = static fn(array $r): bool => str_contains((string)$r['output'], 'name="guest_email"') && substr_count((string)$r['output'], 'action=anonymize') === 1;
$r15gText = static fn(array $r): string => html_entity_decode(strip_tags((string)$r['output']), ENT_QUOTES);
try {
    $r15gRun('setup');
    $r15gBefore = $r15gRun('page');
    assert_true($r15gButton($r15gBefore) && !str_contains($r15gText($r15gBefore), 'Kept for accounting'),
        'R15 GDPR guest fixture: before the erasure the guest row offers "anonymize" (' . substr($r15gText($r15gBefore), 0, 300) . ')');
    $r15gErased = $r15gRun('anonymize');
    $r15gFlash = (string)($r15gErased['flash']['msg'] ?? $r15gErased['flash'][1] ?? json_encode($r15gErased['flash']));
    assert_true(str_contains($r15gFlash, '2 orders keep the name, address and e-mail') && preg_match('/Anonymized: [1-9]\d* records deleted or scrubbed/', $r15gFlash) === 1,
        'R15 GDPR guest: the flash says what was erased and which orders keep data for accounting (' . $r15gFlash . ')');
    $r15gAfter = $r15gRun('page');
    $r15gAfterText = $r15gText($r15gAfter);
    assert_true(!$r15gButton($r15gAfter) && str_contains($r15gAfterText, 'Anonymized on ') && str_contains($r15gAfterText, 'Kept for accounting: name, address and e-mail on 2 orders'),
        'R15 GDPR guest: after the erasure the row shows the erased state and what was kept, without offering the same erasure (' . substr($r15gAfterText, 0, 600) . ')');
    assert_equals([['order_number' => 'R15G-1', 'customer_phone' => '', 'notes' => '', 'ip_address' => ''], ['order_number' => 'R15G-2', 'customer_phone' => '', 'notes' => '', 'ip_address' => '']],
        array_values(array_filter($r15gAfter['orders'], static fn(array $o): bool => $o['order_number'] !== 'R15G-OTHER')),
        'R15 GDPR guest: the guest orders lose phone, note and IP address');
    $r15gRun('new_order');
    $r15gNew = $r15gRun('page');
    assert_true($r15gButton($r15gNew) && str_contains($r15gText($r15gNew), 'New data since then: 1.'),
        'R15 GDPR guest: an order placed after the erasure brings the button back and is counted as new data');
} finally {
    foreach (glob($r15gRoot . '/*') ?: [] as $r15gFile) @unlink($r15gFile);
    @rmdir($r15gRoot);
}

// 4. "Notify me when back in stock" had no double opt-in: anyone could sign a stranger's address up and the shop mailed
//    it. The request now waits until the address opens the e-mailed link and presses the button (opening alone does
//    nothing: mail scanners follow links); only confirmed requests are mailed, unconfirmed ones lapse after 48 hours.
if (!function_exists('renderStockNotify')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/GuestPages.php';
(require dirname(TEST_CLI_DIR) . '/migrations/20261104120000_back_in_stock_double_opt_in.php')->up($pdo, Db::isSqlite());
$r15bLocale = I18n::locale();
I18n::setLocale('en');
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$r15bServer = $_SERVER;
$r15bCfg = new ReflectionProperty(Security::class, 'cfg');
$r15bCfgBase = Security::config();
$r15bCfg->setValue(null, ['app_url' => 'https://r15b.example.test'] + $r15bCfgBase);
$r15bWh = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (9781, 'default', (SELECT MIN(id) FROM categories WHERE store_id = 'default'), 'R15B lamp', 'r15b-lamp', 'R15B-S', 'simple', 900, 'eur', 'active', 0, 'deny')")->execute();
$pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'R15B-S', 0, 0)")->execute([$r15bWh]);
$r15bRows = static fn(): array => $pdo->query("SELECT email, confirmed_at, token_hash FROM back_in_stock_requests WHERE product_id = 9781 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$r15bAlerts = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM email_log WHERE template = 'back_in_stock' AND recipient = 'r15b-shop@example.test'")->fetchColumn();
$r15bRestock = static function (int $qty) use ($r15bWh): void {
    Warehouse::setStock($r15bWh, 'R15B-S', $qty, 'count');
    Warehouse::syncProductStock(['R15B-S']);
};
$r15bToken = static function (): string {
    $mail = LogMail::lastCaptured() ?? [];
    return preg_match('~page=stock-notify&(?:amp;)?confirm=([a-f0-9]{64})~', (string)($mail['html'] ?? ''), $m) ? $m[1] : '';
};
try {
    MailService::useAdapter(capture_mail()); // R26: the log provider's mail reads as not sent.
    LogMail::clearCaptured();
    $r15bAsked = BackInStock::subscribe(9781, 'R15B-Shop@Example.test', 'en');
    $r15bMail = LogMail::lastCaptured() ?? [];
    $r15bLink = $r15bToken();
    assert_true($r15bAsked === 'confirm_sent' && count($r15bRows()) === 1 && $r15bRows()[0]['confirmed_at'] === null
        && ($r15bMail['to'] ?? '') === 'r15b-shop@example.test' && str_contains((string)($r15bMail['subject'] ?? ''), 'R15B lamp') && $r15bLink !== ''
        && $r15bRows()[0]['token_hash'] === hash('sha256', $r15bLink),
        'R15 back in stock: the form stores an unconfirmed request and e-mails the address a confirmation link (' . json_encode([$r15bAsked, $r15bRows(), $r15bMail['subject'] ?? null]) . ')');

    $r15bAlertsBefore = $r15bAlerts();
    $r15bRestock(3);
    assert_equals([0, 0, 1], [BackInStock::process(['R15B-S']), $r15bAlerts() - $r15bAlertsBefore, count($r15bRows())],
        'R15 back in stock: an unconfirmed request is not mailed when the product returns');
    $r15bRestock(0);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['page' => 'stock-notify', 'confirm' => $r15bLink];
    $_POST = [];
    $r15bOpened = renderStockNotify();
    assert_true(str_contains($r15bOpened, 'data-stock-notify-confirm') && str_contains($r15bOpened, 'R15B lamp') && $r15bRows()[0]['confirmed_at'] === null,
        'R15 back in stock: opening the link shows a confirm button and confirms nothing');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['page' => 'stock-notify'];
    $_POST = ['confirm' => $r15bLink, 'csrf_token' => 'forged'];
    $r15bForged = renderStockNotify();
    $_POST['csrf_token'] = Security::csrfToken();
    $r15bConfirmed = renderStockNotify();
    assert_true($r15bRows()[0]['confirmed_at'] !== null && $r15bRows()[0]['token_hash'] === null && str_contains($r15bConfirmed, esc(I18n::t('back_in_stock.subscribed')))
        && str_contains($r15bForged, esc(I18n::t('back_in_stock.expired'))),
        'R15 back in stock: the posted button (with the form token) confirms the request and spends the link');
    assert_true(str_contains(renderStockNotify(), esc(I18n::t('back_in_stock.confirm_invalid'))), 'R15 back in stock: a spent link does not confirm again');

    LogMail::clearCaptured();
    assert_true(BackInStock::subscribe(9781, 'r15b-shop@example.test', 'en') === 'confirm_sent' && LogMail::captured() === [] && $r15bRows()[0]['confirmed_at'] !== null,
        'R15 back in stock: asking again after confirming sends nothing and answers the same (the form does not reveal who waits)');

    $r15bRestock(3);
    assert_equals([1, 1, 0], [BackInStock::process(['R15B-S']), $r15bAlerts() - $r15bAlertsBefore, count($r15bRows())],
        'R15 back in stock: the confirmed request is mailed once and deleted');
    $r15bRestock(0);

    BackInStock::subscribe(9781, 'r15b-late@example.test', 'en');
    $r15bLate = $r15bToken();
    $pdo->prepare("UPDATE back_in_stock_requests SET created_at = ? WHERE email = 'r15b-late@example.test'")->execute([gmdate('Y-m-d H:i:s', time() - (BackInStock::CONFIRM_HOURS + 1) * 3600)]);
    BackInStock::process(['R15B-S']);
    assert_true($r15bLate !== '' && BackInStock::pendingForToken($r15bLate) === null && !BackInStock::confirm($r15bLate) && $r15bRows() === [],
        'R15 back in stock: a link not confirmed within 48 hours is void and the request is deleted');
    assert_true(is_file(dirname(TEST_CLI_DIR) . '/templates/emails/sk/back_in_stock_confirm.html') && is_file(dirname(TEST_CLI_DIR) . '/templates/emails/de/back_in_stock_confirm.subject.txt'),
        'R15 back in stock: the confirmation e-mail exists in every language');
} finally {
    MailService::useAdapter(null);
    $_SERVER = $r15bServer;
    $r15bCfg->setValue(null, $r15bCfgBase);
    $_GET = $_POST = [];
    I18n::setLocale($r15bLocale);
    $pdo->exec("DELETE FROM back_in_stock_requests WHERE product_id = 9781");
    $pdo->exec("DELETE FROM warehouse_stock WHERE sku = 'R15B-S'");
    $pdo->exec("DELETE FROM products WHERE id = 9781");
}
