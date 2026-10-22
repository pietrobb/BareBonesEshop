<?php

// R8 EU consumer law (core). Texts here are a technical baseline and must be reviewed by a lawyer.
// 1) The order button states the payment obligation (Directive 2011/83/EU art. 8(2)) in every shipped locale
//    and on the real storefront checkout page.
echo "\n--- Audit round 8: EU consumer law (order button, withdrawal, confirmation, digital consent, complaints) ---\n";

$r8lRoot = test_fixture_path('r8-consumer-law');
mkdir($r8lRoot);
mkdir($r8lRoot . '/sessions');
$r8lFixture = $r8lRoot . '/request.php';
file_put_contents($r8lFixture, <<<'PHP'
<?php
[, $phase, $action, $root, $target, $dir, $extra] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate.lock'))->apply();
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'r8lcat', 'Cat', 'r8lcat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'r8lcat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'R8L-SKU', 'r8l-product', 'R8L Product', 2000, 50, 'active']);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'R8L-SKU']);
    // Guest orders: R8L-NEW not delivered yet (two lines), R8L-DONE delivered 3 days ago, R8L-OLD delivered 20 days ago, R8L-CXL cancelled.
    $product = (int)$db->query("SELECT id FROM products WHERE sku = 'R8L-SKU'")->fetchColumn();
    $order = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id, locale, delivered_at)
        VALUES (?, ?, 'paid', 'R8L Guest', 'R8L.Guest@Example.test', 'card', 3000, 3000, 'eur', 'default', 'sk', ?)");
    $item = $db->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ([['R8L-NEW', 'paid', null], ['R8L-DONE', 'delivered', gmdate('Y-m-d H:i:s', time() - 3 * 86400)],
              ['R8L-OLD', 'delivered', gmdate('Y-m-d H:i:s', time() - 20 * 86400)], ['R8L-CXL', 'cancelled', null],
              ['R8L-ANC', 'delivered', gmdate('Y-m-d H:i:s', strtotime('-25 months'))]] as [$number, $status, $deliveredAt]) {
        $order->execute([$number, $status, $deliveredAt]);
        $orderId = (int)$db->lastInsertId();
        $item->execute([$orderId, $product, 'R8L-SKU', 'R8L Chair', 1, 2000, 2000]);
        $item->execute([$orderId, $product, 'R8L-SKU', 'R8L Table', 1, 1000, 1000]);
    }
    // Digital content: R8L-DIG supplied with the consent to lose the withdrawal right, R8L-DIGN supplied without it.
    $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, product_type) VALUES (?, ?, ?, ?, ?, ?, 0, 'active', 'downloadable')")
        ->execute(['default', $cat, 'R8L-EBOOK', 'r8l-ebook', 'R8L E-book', 500]);
    $ebook = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO digital_assets (sku, storage_path, download_name) VALUES ('R8L-EBOOK', 'default/r8l.pdf', 'r8l.pdf')")->execute();
    $asset = (int)$db->lastInsertId();
    foreach (['R8L-DIG' => true, 'R8L-DIGN' => false] as $number => $consented) {
        $order->execute([$number, 'paid', null]);
        $orderId = (int)$db->lastInsertId();
        $item->execute([$orderId, $product, 'R8L-SKU', 'R8L Chair', 1, 2000, 2000]);
        $item->execute([$orderId, $ebook, 'R8L-EBOOK', 'R8L E-book', 1, 500, 500]);
        $db->prepare("INSERT INTO digital_entitlements (order_id, order_item_id, asset_id, token_hash, status) VALUES (?, ?, ?, ?, 'active')")
            ->execute([$orderId, (int)$db->lastInsertId(), $asset, hash('sha256', $number)]);
        if ($consented) $db->prepare("INSERT INTO order_consents (order_id, consent_key, version, consent_text, user_agent) VALUES (?, 'digital_delivery', '2026-10-22', 'r8l', '')")->execute([$orderId]);
    }
    exit(0);
}
$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.8.21';
$_SERVER['HTTP_HOST'] = 'shop.example';
JobQueue::setDir($dir . '/jobs');
Bootstrap::initLocale('/index.php');
$input = json_decode($extra, true) ?: [];
if ($action === 'confirmation') {
    // The worker's real order_confirmation job; a capturing adapter keeps the message with its attachments.
    $capture = new class implements MailAdapter {
        public array $sent = [];
        public function send(array $msg): array { $this->sent[] = $msg; return ['status' => 'sent', 'message' => '']; }
        public function adapterId(): string { return 'r8l-capture'; }
    };
    MailService::useAdapter($capture);
    $argv = ['worker.php', '--once'];
    ob_start();
    require_once $root . '/cli/worker.php';
    ob_end_clean();
    $order = $db->query("SELECT * FROM orders WHERE order_number = 'R8L-NEW'")->fetch();
    handleEmailSend(['template' => 'order_confirmation', 'to' => 'ignored@example.test', 'order_id' => (int)$order['id']], []);
    $vars = ConsumerNotice::mailVars($order) + ['order_number' => 'R8L-NEW', 'customer_name' => 'R8L Guest', 'order_status' => 'paid', 'order_total' => '30,00 €', 'items' => []];
    $locales = [];
    foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $locale) $locales[$locale] = MailService::render('order_confirmation', $locale, $vars)['html'];
    echo "\nR8LRESULT" . json_encode(['sent' => array_map(static fn(array $m): array => [
        'to' => $m['to'], 'subject' => $m['subject'], 'html' => $m['html'], 'text' => $m['text'],
        'attachments' => array_map(static fn(array $a): array => ['filename' => $a['filename'], 'content_type' => $a['content_type'], 'data' => $a['data']], $m['attachments']),
    ], $capture->sent), 'locales' => $locales], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit(0);
}
if ($action === 'consent') {
    // The consent rules of a digital and of a physical cart with the deployment's config, and with the default turned off.
    $digital = ['country' => 'SK', 'product_types' => ['downloadable']];
    $physical = ['country' => 'SK', 'product_types' => ['physical']];
    $result = ['digital' => ConsentService::validate([], $digital), 'digital_given' => ConsentService::validate([ConsentService::DIGITAL_KEY => true], $digital),
        'physical' => ConsentService::validate([], $physical), 'text' => ConsentService::definitions($digital)[ConsentService::DIGITAL_KEY]['text'] ?? ''];
    ConsentService::setDefinitionsForTesting([ConsentService::DIGITAL_KEY => ['active' => false]]);
    $result['off'] = ConsentService::validate([], $digital);
    echo "\nR8LRESULT" . json_encode($result, JSON_UNESCAPED_UNICODE);
    exit(0);
}
if ($action === 'c_decide') {
    // The merchant's steps through Complaints, then the admin list as rendered.
    $id = (int)$db->query('SELECT id FROM complaints ORDER BY id LIMIT 1')->fetchColumn();
    $errors = [];
    foreach ($input['steps'] as [$status, $note]) $errors[] = Complaints::setStatus($id, $status, $note);
    require_once $root . '/src/Entrypoints/Admin/Complaints.php';
    ob_start();
    pageComplaints();
    echo "\nR8LRESULT" . json_encode(['errors' => $errors, 'admin' => ob_get_clean(),
        'complaints' => $db->query('SELECT * FROM complaints ORDER BY id')->fetchAll(),
        'mails' => $db->query("SELECT recipient, template, subject, payload FROM email_log WHERE template LIKE '%complaint%' ORDER BY id")->fetchAll()], JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}
$page = in_array($action, ['checkout', 'checkout_digital'], true) ? 'checkout' : ($action === 'home' ? 'home' : (str_starts_with($action, 'c_') ? 'complaint' : 'withdrawal'));
$_SERVER['REQUEST_URI'] = '/index.php?page=' . $page;
$_SERVER['REQUEST_METHOD'] = in_array($action, ['review', 'confirm', 'c_identify', 'c_submit'], true) ? 'POST' : 'GET';
$_GET = ['page' => $page];
$_POST = $_SERVER['REQUEST_METHOD'] === 'POST' ? $input + [$page === 'complaint' ? 'complaint_step' : 'withdrawal_step' => substr($action, $page === 'complaint' ? 2 : 0), 'csrf_token' => Security::csrfToken()] : [];
if ($action === 'checkout') Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R8L-SKU'")->fetchColumn(), null, 1);
if ($action === 'checkout_digital') Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R8L-EBOOK'")->fetchColumn(), null, 1);
http_response_code(200);
ob_start();
register_shutdown_function(static function() use ($db, $dir): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    $jobs = [];
    foreach (glob($dir . '/jobs/pending/*.json') ?: [] as $file) $jobs[] = json_decode((string)file_get_contents($file), true);
    echo "\nR8LRESULT" . json_encode(['code' => http_response_code(), 'output' => $out,
        'withdrawals' => $db->query('SELECT order_number, customer_name, customer_email, items, reason, received_at, confirmation_status FROM withdrawal_requests ORDER BY id')->fetchAll(),
        'mails' => $db->query("SELECT recipient, template, subject, payload FROM email_log WHERE template LIKE '%withdrawal%' ORDER BY id")->fetchAll(),
        'complaints' => $db->query('SELECT * FROM complaints ORDER BY id')->fetchAll(),
        'items' => $db->query("SELECT oi.id, o.order_number, oi.name FROM order_items oi JOIN orders o ON o.id = oi.order_id ORDER BY oi.id")->fetchAll(),
        'jobs' => $jobs,
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$r8lRun = static function(array $args) use ($r8lFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r8lFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R8LRESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 9), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$r8lApp = dirname(TEST_CLI_DIR);
$r8lDb = $r8lRoot . '/shop.sqlite';

// Payment-obligation wording per locale (lawyer to confirm the exact phrase).
$r8lButtonPhrase = [
    'sk' => 'povinnosťou platby', 'cs' => 'povinností platby', 'en' => 'obligation to pay', 'de' => 'zahlungspflichtig',
    'pl' => 'obowiązkiem zapłaty', 'hu' => 'fizetési kötelezettség', 'es' => 'obligación de pago',
];
$r8lFind = static function(array $lang, string $key) use (&$r8lFind): array {
    $found = [];
    foreach ($lang as $k => $v) {
        if (is_array($v)) $found = array_merge($found, $r8lFind($v, $key));
        elseif ($k === $key) $found[] = (string)$v;
    }
    return $found;
};
foreach ($r8lButtonPhrase as $r8lLocale => $r8lPhrase) {
    $r8lLang = require $r8lApp . '/lang/' . $r8lLocale . '.php';
    $r8lLabels = array_merge($r8lFind($r8lLang, 'place_order'), $r8lFind($r8lLang, 'submit_order'));
    $r8lBad = array_values(array_filter($r8lLabels, static fn(string $l): bool => !str_contains(mb_strtolower($l), $r8lPhrase)));
    assert_true($r8lLabels !== [] && $r8lBad === [], "R8 order button ({$r8lLocale}): every order-submit label states the payment obligation (" . json_encode($r8lBad, JSON_UNESCAPED_UNICODE) . ')');
}

[$r8lExit, , $r8lNoise] = $r8lRun(['setup', 'none', $r8lApp, $r8lDb, $r8lRoot, '{}']);
assert_equals([0, ''], [$r8lExit, trim($r8lNoise)], 'R8 consumer law: migrated fixture with one product');
[$r8lExit, $r8lRes, $r8lNoise] = $r8lRun(['run', 'checkout', $r8lApp, $r8lDb, $r8lRoot, '{}']);
$r8lButton = preg_match('~<button class="btn-checkout" type="submit">([^<]*)</button>~', (string)($r8lRes['output'] ?? ''), $r8lM) ? html_entity_decode($r8lM[1]) : '';
assert_true($r8lExit === 0 && str_contains(mb_strtolower($r8lButton), $r8lButtonPhrase['sk']),
    "R8 order button: the real checkout page submit button states the payment obligation ('{$r8lButton}' " . trim($r8lNoise) . ')');

// 2) Electronic withdrawal function (art. 11a): on the storefront, no login, no mandatory reason, also before delivery,
//    a separate confirmation step, the statement recorded with its receipt time and acknowledged by e-mail.
$r8lDo = static function(string $action, array $input = []) use ($r8lRun, $r8lApp, $r8lDb, $r8lRoot): array {
    [$exit, $result, $noise] = $r8lRun(['run', $action, $r8lApp, $r8lDb, $r8lRoot, json_encode($input)]);
    $ok = $exit === 0 && $result !== null && !str_contains((string)$result['output'], 'Fatal error') && !str_contains((string)$result['output'], 'Uncaught');
    assert_true($ok, "R8 withdrawal/{$action}: the real storefront handler completes (exit {$exit} " . trim($noise) . ' ' . substr(strip_tags((string)($result['output'] ?? '')), 0, 300) . ')');
    return $result ?? ['code' => null, 'output' => '', 'withdrawals' => [], 'mails' => [], 'items' => [], 'jobs' => [], 'complaints' => []];
};
$r8lHome = $r8lDo('home');
assert_true(preg_match('~<footer.*href="[^"]*\?page=withdrawal"[^>]*>Odstúpiť od zmluvy tu</a>~s', (string)$r8lHome['output']) === 1,
    'R8 withdrawal: every storefront page footer links the withdrawal function ("Odstúpiť od zmluvy tu")');
$r8lForm = $r8lDo('get');
assert_true($r8lForm['code'] === 200 && str_contains((string)$r8lForm['output'], 'name="order_number"') && str_contains((string)$r8lForm['output'], 'name="email"')
    && !preg_match('~name="reason"[^>]*required~', (string)$r8lForm['output']) && !str_contains((string)$r8lForm['output'], 'name="password"'),
    'R8 withdrawal: a guest gets the form without logging in; order number and e-mail identify the order, the reason is optional');
$r8lItemIds = [];
foreach ($r8lForm['items'] as $r8lRow) $r8lItemIds[$r8lRow['order_number']][] = (int)$r8lRow['id'];

$r8lWrong = $r8lDo('review', ['order_number' => 'R8L-NEW', 'email' => 'someone.else@example.test']);
assert_true($r8lWrong['code'] === 422 && str_contains((string)$r8lWrong['output'], 'Objednávku s týmto číslom a e-mailom sme nenašli') && !str_contains((string)$r8lWrong['output'], 'R8L Chair'),
    'R8 withdrawal: a wrong e-mail reveals nothing about the order');
$r8lReview = $r8lDo('review', ['order_number' => ' R8L-NEW ', 'email' => 'r8l.guest@example.TEST']);
assert_true(str_contains((string)$r8lReview['output'], 'Potvrdiť odstúpenie') && str_contains((string)$r8lReview['output'], 'R8L Chair')
    && str_contains((string)$r8lReview['output'], 'R8L Table') && $r8lReview['withdrawals'] === [] && $r8lReview['mails'] === [],
    'R8 withdrawal: an order not yet delivered is withdrawable; the review step lists its lines and records nothing before "Potvrdiť odstúpenie"');
$r8lDone = $r8lDo('confirm', ['order_number' => 'R8L-NEW', 'email' => 'r8l.guest@example.test', 'name' => 'R8L Guest', 'items' => [$r8lItemIds['R8L-NEW'][0]]]);
$r8lRow = $r8lDone['withdrawals'][0] ?? [];
$r8lMail = $r8lDone['mails'][0] ?? [];
$r8lPayload = json_decode((string)($r8lMail['payload'] ?? '{}'), true) ?: [];
assert_true(count($r8lDone['withdrawals']) === 1 && ($r8lRow['order_number'] ?? '') === 'R8L-NEW' && ($r8lRow['reason'] ?? null) === ''
    && array_keys(json_decode((string)($r8lRow['items'] ?? '{}'), true) ?: []) === [$r8lItemIds['R8L-NEW'][0]] && preg_match('~^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d~', (string)($r8lRow['received_at'] ?? '')) === 1,
    'R8 withdrawal: the confirmed statement is recorded with the selected line and its receipt time, without a reason (' . json_encode($r8lRow) . ')');
assert_true(($r8lMail['template'] ?? '') === 'withdrawal_received' && ($r8lMail['recipient'] ?? '') === 'r8l.guest@example.test'
    && str_contains((string)($r8lPayload['received_at'] ?? ''), (string)($r8lRow['received_at'] ?? '-')) && ($r8lPayload['items'][0]['name'] ?? '') === 'R8L Chair'
    && ($r8lRow['confirmation_status'] ?? '') === 'sent' && str_contains((string)$r8lDone['output'], 'sme prijali'),
    'R8 withdrawal: the consumer gets an acknowledgement e-mail with the statement and its receipt time, and the page confirms it (' . json_encode($r8lMail, JSON_UNESCAPED_UNICODE) . ')');
assert_true(in_array(['merchant_notify', 'withdrawal'], array_map(static fn(array $j): array => [(string)($j['type'] ?? ''), explode(':', (string)($j['payload']['event'] ?? ''))[0]], $r8lDone['jobs']), true),
    'R8 withdrawal: the merchant is notified through the merchant_notify job');
$r8lSecond = $r8lDo('review', ['order_number' => 'R8L-NEW', 'email' => 'r8l.guest@example.test']);
assert_true(str_contains((string)$r8lSecond['output'], 'R8L Table') && preg_match_all('~name="items\[\]" value="(\d+)"~', (string)$r8lSecond['output'], $r8lOffered) === 1 && (int)$r8lOffered[1][0] === $r8lItemIds['R8L-NEW'][1],
    'R8 withdrawal: a later statement offers only the lines not yet withdrawn');
$r8lDelivered = $r8lDo('confirm', ['order_number' => 'R8L-DONE', 'email' => 'r8l.guest@example.test', 'reason' => 'Nesedí farba', 'items' => $r8lItemIds['R8L-DONE']]);
assert_true(count($r8lDelivered['withdrawals']) === 2 && ($r8lDelivered['withdrawals'][1]['reason'] ?? '') === 'Nesedí farba',
    'R8 withdrawal: an order delivered 3 days ago is withdrawable; an optional reason is kept');
$r8lOld = $r8lDo('confirm', ['order_number' => 'R8L-OLD', 'email' => 'r8l.guest@example.test', 'items' => $r8lItemIds['R8L-OLD']]);
$r8lCxl = $r8lDo('review', ['order_number' => 'R8L-CXL', 'email' => 'r8l.guest@example.test']);
assert_true(str_contains((string)$r8lOld['output'], 'Lehota na odstúpenie od tejto objednávky už uplynula') && count($r8lOld['withdrawals']) === 2
    && str_contains((string)$r8lCxl['output'], 'je zrušená alebo vrátená'),
    'R8 withdrawal: after the 14-day period or on a cancelled order the statement is refused with the reason, nothing recorded');

// 3) Order confirmation (art. 8(7)): seller identity, withdrawal period + function + model form, 24-month liability,
//    ADR body and the terms on a durable medium (attachment); full terms in every shipped locale.
[$r8lExit, $r8lConf, $r8lNoise] = $r8lRun(['run', 'confirmation', $r8lApp, $r8lDb, $r8lRoot, '{}']);
$r8lMsg = $r8lConf['sent'][0] ?? ['to' => '', 'subject' => '', 'html' => '', 'text' => '', 'attachments' => []];
$r8lHtml = html_entity_decode((string)$r8lMsg['html']);
$r8lNeed = ['Woodle s.r.o.', 'Hlavná 1', 'IČO: 12345678', 'IČ DPH: SK1020304050', 'info@example.sk', 'do 14 dní od prevzatia tovaru',
    '?page=withdrawal&order=R8L-NEW', 'Vzorový formulár na odstúpenie od zmluvy', 'Týmto oznamujem', '24 mesiacov', 'Slovenská obchodná inšpekcia', '?page=terms'];
assert_true($r8lExit === 0 && count($r8lConf['sent'] ?? []) === 1 && $r8lMsg['to'] === 'r8l.guest@example.test'
    && array_filter($r8lNeed, static fn(string $s): bool => !str_contains($r8lHtml, $s)) === [] && !str_contains($r8lHtml, '{{'),
    'R8 confirmation: the worker\'s order confirmation names the seller, the withdrawal period, function and model form, the 24-month liability, the ADR body and the terms (missing: '
    . json_encode(array_values(array_filter($r8lNeed, static fn(string $s): bool => !str_contains($r8lHtml, $s))), JSON_UNESCAPED_UNICODE) . ' ' . trim($r8lNoise) . ')');
$r8lTerms = $r8lMsg['attachments'][0] ?? ['filename' => '', 'content_type' => '', 'data' => ''];
assert_true($r8lTerms['filename'] === 'terms-R8L-NEW.html' && str_starts_with($r8lTerms['content_type'], 'text/html')
    && str_contains($r8lTerms['data'], 'Obchodné podmienky') && str_contains($r8lTerms['data'], 'Woodle s.r.o.') && str_contains($r8lTerms['data'], '5. Odstúpenie od zmluvy')
    && str_contains($r8lTerms['data'], 'Slovenská obchodná inšpekcia') && !str_contains($r8lTerms['data'], '{{'),
    'R8 confirmation: the terms valid at the order are attached with the seller\'s data and the ADR body (durable medium)');
foreach ($r8lConf['locales'] ?? [] as $r8lLocale => $r8lLocaleHtml) {
    assert_true(str_contains((string)$r8lLocaleHtml, 'page=withdrawal&amp;order=R8L-NEW') && str_contains((string)$r8lLocaleHtml, 'Woodle s.r.o.')
        && str_contains((string)$r8lLocaleHtml, 'Slovensk') && preg_match_all('~<h3>~', (string)$r8lLocaleHtml) >= 5 && !str_contains((string)$r8lLocaleHtml, '{{'),
        "R8 confirmation ({$r8lLocale}): the template carries the seller, withdrawal, model form, liability, ADR and terms sections");
}
foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $r8lLocale) {
    $r8lTc = (string)@file_get_contents($r8lApp . '/templates/legal/' . $r8lLocale . '/terms.html');
    assert_true(preg_match_all('~<h2>~', $r8lTc) >= 11 && str_contains($r8lTc, '{{adr_name}}') && str_contains($r8lTc, '{{email}}') && strlen($r8lTc) > 3500,
        "R8 terms ({$r8lLocale}): full terms with withdrawal, exceptions, liability, ADR and the model form annex, not one paragraph");
}

// 4) Digital content (art. 16(m)): supplied only with the express consent to lose the withdrawal right — required by
//    default, without any checkout_consents entry; once supplied with it, the line is no longer withdrawable.
[$r8lExit, $r8lCons, $r8lNoise] = $r8lRun(['run', 'consent', $r8lApp, $r8lDb, $r8lRoot, '{}']);
assert_true($r8lExit === 0 && isset($r8lCons['digital']['digital_delivery']) && ($r8lCons['digital_given'] ?? null) === [] && ($r8lCons['physical'] ?? null) === []
    && str_contains((string)($r8lCons['text'] ?? ''), 'strácam právo na odstúpenie'),
    'R8 digital: a digital cart requires the consent to lose the withdrawal right by default (sk text), a physical cart does not (' . json_encode($r8lCons, JSON_UNESCAPED_UNICODE) . ' ' . trim($r8lNoise) . ')');
assert_true(($r8lCons['off'] ?? null) === [], 'R8 digital: checkout_consents.digital_delivery active=false turns the default off');
foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $r8lLocale) {
    $r8lLang = require $r8lApp . '/lang/' . $r8lLocale . '.php';
    assert_true(mb_strlen((string)($r8lLang['checkout.digital_delivery_consent'] ?? '')) > 80, "R8 digital ({$r8lLocale}): the consent text is translated");
}
$r8lDigPage = $r8lDo('checkout_digital');
assert_true(preg_match('~<input[^>]*name="consents\[digital_delivery\]"[^>]*required~', (string)$r8lDigPage['output']) === 1 && str_contains((string)$r8lDigPage['output'], 'strácam právo na odstúpenie'),
    'R8 digital: the real checkout page of a digital cart shows the required consent checkbox with its text');
$r8lOffer = static function(array $res): array {
    preg_match_all('~name="items\[\]" value="(\d+)"~', (string)$res['output'], $m);
    return array_map('intval', $m[1]);
};
$r8lItemIds = [];
foreach ($r8lDigPage['items'] as $r8lRow) $r8lItemIds[$r8lRow['order_number']][] = (int)$r8lRow['id'];
assert_equals([$r8lItemIds['R8L-DIG'][0]], $r8lOffer($r8lDo('review', ['order_number' => 'R8L-DIG', 'email' => 'r8l.guest@example.test'])),
    'R8 digital: e-book supplied with the consent is not withdrawable, the chair of the same order is');
assert_equals($r8lItemIds['R8L-DIGN'], $r8lOffer($r8lDo('review', ['order_number' => 'R8L-DIGN', 'email' => 'r8l.guest@example.test'])),
    'R8 digital: e-book supplied without the consent stays withdrawable');

// 5) Complaints (Directive (EU) 2019/771): not a return — own form, 24 months from delivery, defect + remedy, receipt
//    e-mail, the merchant's written decision e-mailed to the consumer.
assert_true(preg_match('~<footer.*href="[^"]*\?page=complaint"[^>]*>Reklamácia tovaru</a>~s', (string)$r8lHome['output']) === 1,
    'R8 complaint: every storefront page footer links the complaint form');
$r8lC = $r8lDo('c_identify', ['order_number' => 'R8L-OLD', 'email' => 'r8l.guest@example.test']);
assert_true(str_contains((string)$r8lC['output'], 'name="defect"') && str_contains((string)$r8lC['output'], 'value="price_reduction"')
    && preg_match_all('~name="item" value="(\d+)"~', (string)$r8lC['output']) === 2 && $r8lC['complaints'] === [],
    'R8 complaint: an order delivered 20 days ago (past the 14-day return window) still gets the complaint form with its lines, defect and remedy');
$r8lCAnc = $r8lDo('c_identify', ['order_number' => 'R8L-ANC', 'email' => 'r8l.guest@example.test']);
$r8lCNew = $r8lDo('c_identify', ['order_number' => 'R8L-NEW', 'email' => 'r8l.guest@example.test']);
assert_true(str_contains((string)$r8lCAnc['output'], 'viac ako 24 mesiacov') && !str_contains((string)$r8lCAnc['output'], 'name="defect"')
    && str_contains((string)$r8lCNew['output'], 'ešte nebol odoslaný'),
    'R8 complaint: refused after 24 months from delivery and before the goods were shipped, with the reason');
$r8lCNoDefect = $r8lDo('c_submit', ['order_number' => 'R8L-OLD', 'email' => 'r8l.guest@example.test', 'item' => $r8lItemIds['R8L-OLD'][0], 'defect' => ' ', 'remedy' => 'repair']);
assert_true(str_contains((string)$r8lCNoDefect['output'], 'Popíšte vadu tovaru') && $r8lCNoDefect['complaints'] === [],
    'R8 complaint: the defect description is required, nothing recorded without it');
$r8lCDone = $r8lDo('c_submit', ['order_number' => 'R8L-OLD', 'email' => 'r8l.guest@example.test', 'item' => $r8lItemIds['R8L-OLD'][1], 'defect' => 'Prasknutá noha stola', 'remedy' => 'replacement']);
$r8lCRow = $r8lCDone['complaints'][0] ?? [];
assert_true(count($r8lCDone['complaints']) === 1 && ($r8lCRow['item_name'] ?? '') === 'R8L Table' && ($r8lCRow['remedy'] ?? '') === 'replacement'
    && ($r8lCRow['status'] ?? '') === 'received' && ($r8lCRow['confirmation_status'] ?? '') === 'sent' && str_contains((string)$r8lCDone['output'], (string)($r8lCRow['complaint_number'] ?? '-'))
    && in_array(['merchant_notify', 'complaint'], array_map(static fn(array $j): array => [(string)($j['type'] ?? ''), explode(':', (string)($j['payload']['event'] ?? ''))[0]], $r8lCDone['jobs']), true),
    'R8 complaint: the complaint is recorded for the chosen line with defect and remedy, confirmed on the page, receipt sent, merchant notified (' . json_encode($r8lCRow, JSON_UNESCAPED_UNICODE) . ')');
assert_equals([], array_values(array_filter(array_map(static fn(array $w): string => (string)$w['order_number'], $r8lCDone['withdrawals']), static fn(string $n): bool => $n === 'R8L-OLD')),
    'R8 complaint: a complaint is not recorded as a withdrawal');
[$r8lExit, $r8lDec, $r8lNoise] = $r8lRun(['run', 'c_decide', $r8lApp, $r8lDb, $r8lRoot, json_encode(['steps' => [['accepted', ''], ['in_review', ''], ['accepted', 'Vadu uznávame, posielame nový stôl.'], ['received', '']]])]);
$r8lDecRow = $r8lDec['complaints'][0] ?? [];
$r8lDecMail = array_values(array_filter($r8lDec['mails'] ?? [], static fn(array $m): bool => $m['template'] === 'complaint_decided'))[0] ?? [];
assert_true($r8lExit === 0 && ($r8lDec['errors'] ?? null) === ['note_required', null, null, 'transition_refused'] && ($r8lDecRow['status'] ?? '') === 'accepted'
    && ($r8lDecRow['decision_note'] ?? '') === 'Vadu uznávame, posielame nový stôl.' && (string)($r8lDecRow['decided_at'] ?? '') !== '',
    'R8 complaint: the merchant reviews and decides; a decision needs its reasons and the flow only moves forward (' . json_encode($r8lDec['errors'] ?? null) . ' ' . trim($r8lNoise) . ')');
assert_true(($r8lDecMail['recipient'] ?? '') === 'r8l.guest@example.test' && str_contains((string)($r8lDecMail['payload'] ?? ''), 'posielame nov')
    && in_array('complaint_received', array_column($r8lDec['mails'] ?? [], 'template'), true),
    'R8 complaint: the consumer got the receipt and the written decision with its reasons by e-mail');
assert_true(str_contains((string)($r8lDec['admin'] ?? ''), (string)($r8lDecRow['complaint_number'] ?? '-')) && str_contains((string)($r8lDec['admin'] ?? ''), 'Prasknutá noha stola')
    && str_contains((string)($r8lDec['admin'] ?? ''), 'value="resolved"'),
    'R8 complaint: the admin complaints list shows the complaint, its defect and the next step');
