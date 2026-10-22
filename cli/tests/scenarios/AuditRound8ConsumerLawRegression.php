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
              ['R8L-OLD', 'delivered', gmdate('Y-m-d H:i:s', time() - 20 * 86400)], ['R8L-CXL', 'cancelled', null]] as [$number, $status, $deliveredAt]) {
        $order->execute([$number, $status, $deliveredAt]);
        $orderId = (int)$db->lastInsertId();
        $item->execute([$orderId, $product, 'R8L-SKU', 'R8L Chair', 1, 2000, 2000]);
        $item->execute([$orderId, $product, 'R8L-SKU', 'R8L Table', 1, 1000, 1000]);
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
$page = $action === 'checkout' ? 'checkout' : ($action === 'home' ? 'home' : 'withdrawal');
$_SERVER['REQUEST_URI'] = '/index.php?page=' . $page;
$_SERVER['REQUEST_METHOD'] = in_array($action, ['review', 'confirm'], true) ? 'POST' : 'GET';
$_GET = ['page' => $page];
$_POST = $_SERVER['REQUEST_METHOD'] === 'POST' ? $input + ['withdrawal_step' => $action, 'csrf_token' => Security::csrfToken()] : [];
if ($action === 'checkout') Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'R8L-SKU'")->fetchColumn(), null, 1);
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
    return $result ?? ['code' => null, 'output' => '', 'withdrawals' => [], 'mails' => [], 'items' => [], 'jobs' => []];
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
