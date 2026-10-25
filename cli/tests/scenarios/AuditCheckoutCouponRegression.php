<?php

// A13.2 / checkout coupon UI through the real storefront handler (index.php?page=checkout): the HTML form applies and
// removes a coupon without JS (CSRF-protected, nothing ordered), refuses a code without a guessing oracle (only the
// minimum order is a reason of its own; attempts share the coupon API's throttle), keeps what the buyer
// typed, shows the discount in the summary and the placed order carries the same discount. SQLite always,
// MySQL 8 / MariaDB 11.4 via TEST_MYSQL_DSN.
echo "\n--- Audit A13.2: checkout coupon UI (no-JS apply/remove, refusal reasons, kept values, discount in order) ---\n";

$a132Root = test_fixture_path('checkout-coupon');
mkdir($a132Root);
mkdir($a132Root . '/sessions');
$a132Fixture = $a132Root . '/request.php';
file_put_contents($a132Fixture, <<<'PHP'
<?php
[, $driver, $phase, $action, $root, $target, $dir, $extra] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
Pricing::setConfig(['cart_rules' => [
    ['type' => 'coupon', 'active' => true, 'code' => 'A132SAVE', 'name' => 'A132 ten percent', 'discount_type' => 'percent', 'discount_value' => 10],
    ['type' => 'coupon', 'active' => true, 'code' => 'A132BIG', 'name' => 'A132 big spender', 'discount_type' => 'fixed', 'discount_value' => 500, 'min_subtotal' => 100000],
    ['type' => 'coupon', 'active' => true, 'code' => 'A132ONCE', 'name' => 'A132 single use', 'discount_type' => 'fixed', 'discount_value' => 300, 'max_uses' => 1],
    ['type' => 'coupon', 'active' => false, 'code' => 'A132OFF', 'name' => 'A132 inactive', 'discount_type' => 'fixed', 'discount_value' => 300],
    ['type' => 'coupon', 'active' => true, 'code' => 'A132RACE', 'name' => 'A132 single use, raced', 'discount_type' => 'fixed', 'discount_value' => 300, 'max_uses' => 1],
]]);
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'a132cat', 'Cat', 'a132cat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'a132cat'")->fetchColumn();
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', $cat, 'A132-SKU', 'a132-product', 'A132 Product', 2000, 50, 'active']);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 50, 0)')->execute([$warehouse, 'A132-SKU']);
    // A132ONCE is used up by an earlier order.
    $db->beginTransaction();
    $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id) VALUES (?, 'pending', 'pending', 'A132', 'earlier@example.test', 'card', 2000, 1700, 'eur', 'default')")->execute(['A132-EARLIER']);
    Pricing::recordCouponUse('A132ONCE', (int)$db->lastInsertId(), 'earlier@example.test', 300);
    $db->commit();
    exit(0);
}

$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate-' . $driver . '.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.13.2';
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['REQUEST_URI'] = '/index.php?page=checkout';
Bootstrap::initLocale('/index.php');
Cart::add((int)$db->query("SELECT id FROM products WHERE sku = 'A132-SKU'")->fetchColumn(), null, 2);
$csrf = Security::csrfToken();
$buyer = ['name' => 'A132 Buyer', 'email' => 'a132-buyer@example.test', 'phone' => '+421900000132', 'street' => 'A132 Street 7',
    'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'notes' => 'A132 ring twice'] + (json_decode($extra, true) ?: []);
[$method, $post] = match ($action) {
    'get' => ['GET', []],
    'apply' => ['POST', $buyer + ['checkout' => '1', 'csrf_token' => $csrf, 'coupon_code' => ' a132save ', 'coupon_action' => 'apply']],
    'apply_unknown' => ['POST', $buyer + ['checkout' => '1', 'csrf_token' => $csrf, 'coupon_code' => 'A132NOPE', 'coupon_action' => 'apply']],
    'apply_inactive' => ['POST', $buyer + ['checkout' => '1', 'csrf_token' => $csrf, 'coupon_code' => 'A132OFF', 'coupon_action' => 'apply']],
    'apply_min' => ['POST', $buyer + ['checkout' => '1', 'csrf_token' => $csrf, 'coupon_code' => 'A132BIG', 'coupon_action' => 'apply']],
    'apply_used_up' => ['POST', $buyer + ['checkout' => '1', 'csrf_token' => $csrf, 'coupon_code' => 'A132ONCE', 'coupon_action' => 'apply']],
    'remove' => ['POST', $buyer + ['checkout' => '1', 'csrf_token' => $csrf, 'coupon_code' => 'A132SAVE', 'coupon_action' => 'remove']],
    'apply_no_csrf' => ['POST', $buyer + ['checkout' => '1', 'coupon_code' => 'A132SAVE', 'coupon_action' => 'apply']],
    'place' => ['POST', $buyer + ['checkout' => '1', 'csrf_token' => $csrf, 'coupon_code' => 'A132SAVE']],
    'place_raced' => ['POST', $buyer + ['checkout' => '1', 'csrf_token' => $csrf, 'coupon_code' => 'A132RACE']],
};
// R8: a concurrent checkout takes the last use of A132RACE after this one priced it (the preview accepted the
// code) and before this order claims it — the trigger is that winner, inside this order's transaction. A shared
// pricing.json coupon counts its uses across stores (counter store_id '*').
if ($action === 'place_raced') {
    $db->exec($driver === 'mysql'
        ? "CREATE TRIGGER a132_race AFTER INSERT ON orders FOR EACH ROW INSERT INTO coupon_usage_counters (store_id, coupon_code, subject, uses) VALUES ('*', 'A132RACE', '', 1) ON DUPLICATE KEY UPDATE uses = 1"
        : "CREATE TRIGGER a132_race AFTER INSERT ON orders BEGIN INSERT OR REPLACE INTO coupon_usage_counters (store_id, coupon_code, subject, uses) VALUES ('*', 'A132RACE', '', 1); END");
    register_shutdown_function(static fn() => $db->exec('DROP TRIGGER a132_race'));
}
$_SERVER['REQUEST_METHOD'] = $method;
$_GET = ['page' => 'checkout'];
$_POST = $post;
http_response_code(200);
$orders = static fn(): array => $db->query("SELECT order_number, subtotal, discount_amount, total FROM orders WHERE customer_email = 'a132-buyer@example.test'")->fetchAll();
$before = count($orders());
$expected = ['discount' => I18n::price(-400, 'eur'), 'subtotal' => I18n::price(4000, 'eur'),
    'race_refusal' => htmlspecialchars(sprintf(I18n::t('coupon_invalid'), 'A132RACE'), ENT_QUOTES), 'failed' => htmlspecialchars(I18n::t('checkout.failed'), ENT_QUOTES)];
ob_start();
register_shutdown_function(static function() use ($orders, $before, $expected): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    $now = $orders();
    echo "\nA132RESULT" . json_encode(['code' => http_response_code(), 'output' => $out, 'new_orders' => array_slice($now, $before), 'expected' => $expected], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$a132Run = static function(array $args) use ($a132Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a132Fixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'A132RESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 10), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$a132Drivers = ['sqlite' => $a132Root . '/checkout.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a132Drivers['mysql'] = 'bbe_a132_coupon_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A13.2 native checkout coupon UI (set TEST_MYSQL_DSN)\n";
$a132App = dirname(TEST_CLI_DIR);
try {
    foreach ($a132Drivers as $a132Driver => $a132Target) {
        [$a132Exit, , $a132Noise] = $a132Run([$a132Driver, 'setup', 'none', $a132App, $a132Target, $a132Root, '{}']);
        assert_equals([0, ''], [$a132Exit, trim($a132Noise)], "A13.2 {$a132Driver}: migrated fixture with a product and four coupons");
        $a132Extra = '{}';
        $a132Do = static function(string $action) use (&$a132Extra, $a132Run, $a132Driver, $a132App, $a132Target, $a132Root): array {
            [$exit, $result, $noise] = $a132Run([$a132Driver, 'run', $action, $a132App, $a132Target, $a132Root, $a132Extra]);
            $label = "A13.2 {$a132Driver}/{$action}";
            assert_true($exit === 0 && $result !== null && !str_contains((string)$result['output'], 'Fatal error') && !str_contains((string)$result['output'], 'Uncaught'),
                "{$label}: real storefront checkout handler completes (exit {$exit} " . trim($noise) . ')');
            return [$label, $result ?? ['code' => null, 'output' => '', 'new_orders' => [], 'expected' => ['discount' => '-', 'subtotal' => '-']]];
        };
        $a132Form = static function(string $html): string {
            return preg_match('~<form method="post" class="checkout-form".*?</form>~s', $html, $m) ? $m[0] : '';
        };
        $a132Kept = static fn(string $form): bool => str_contains($form, 'value="A132 Buyer"') && str_contains($form, 'value="a132-buyer@example.test"')
            && str_contains($form, 'value="A132 Street 7"') && str_contains($form, '>A132 ring twice</textarea>') && str_contains($form, 'value="81101"');

        [$l, $r] = $a132Do('get');
        $form = $a132Form((string)$r['output']);
        assert_true(preg_match('~name="coupon_code"~', $form) === 1 && preg_match('~<button type="submit" name="coupon_action" value="apply" formnovalidate>~', $form) === 1
            && strpos($form, 'name="coupon_action"') < strpos($form, 'class="btn-checkout"') && !str_contains((string)$r['output'], 'id="summary-discount"'),
            "{$l}: the checkout form has a coupon field with a no-JS apply button and no discount yet");
        $a132GetTotal = preg_match('~id="summary-total">([^<]*)<~', (string)$r['output'], $a132Total) ? $a132Total[1] : '';
        // Keep the shipping and payment the server offers so the final order uses real, available methods.
        preg_match('~name="shipping_method" value="([^"]+)" checked~', $form, $a132Ship);
        preg_match('~name="payment" value="([^"]+)"(?: checked)?>~', $form, $a132Pay); // an offline method: no gateway call
        assert_true(!empty($a132Ship[1]) && !empty($a132Pay[1]), "{$l}: shipping and payment are offered for the fixture cart");
        $a132Extra = json_encode(['shipping_method' => $a132Ship[1] ?? '', 'payment' => $a132Pay[1] ?? '']);

        [$l, $r] = $a132Do('apply');
        $html = (string)$r['output'];
        $form = $a132Form($html);
        assert_true($r['new_orders'] === [] && str_contains($form, 'name="coupon_code" autocomplete="off" maxlength="64" value="a132save"')
            && str_contains($html, 'name="coupon_action" value="remove"') && str_contains($html, 'a132save'),
            "{$l}: applying a coupon re-prices the form, shows it as applied with a remove button and places no order");
        preg_match('~id="summary-total">([^<]*)<~', $html, $a132Total);
        assert_true(str_contains($html, 'id="summary-discount">' . htmlspecialchars($r['expected']['discount'], ENT_QUOTES) . '<')
            && preg_match('~<input type="hidden" id="checkout-discount" value="400"[ >]~', $html) === 1 && ($a132Total[1] ?? '') !== '' && ($a132Total[1] ?? '') !== $a132GetTotal,
            "{$l}: the summary shows the 10 % discount ({$r['expected']['discount']}) and a reduced total (" . ($a132Total[1] ?? '?') . " vs {$a132GetTotal})");
        assert_true($a132Kept($form) && str_contains($form, 'value="' . ($a132Ship[1] ?? '') . '" checked') && str_contains($form, 'value="' . ($a132Pay[1] ?? '') . '" checked'),
            "{$l}: everything the buyer entered (contact, address, note, shipping, payment) is kept");

        foreach (['apply_unknown' => 'A132NOPE', 'apply_inactive' => 'A132OFF', 'apply_min' => 'A132BIG', 'apply_used_up' => 'A132ONCE'] as $a132Action => $a132Code) {
            [$l, $r] = $a132Do($a132Action);
            $html = (string)$r['output'];
            $form = $a132Form($html);
            assert_true($r['new_orders'] === [] && preg_match('~<p id="checkout-coupon-error" role="alert"[^>]*>[^<]*' . $a132Code . '[^<]*</p>~', $html) === 1
                && str_contains($form, 'name="coupon_code" autocomplete="off" maxlength="64" value=""') && !str_contains($html, 'id="summary-discount"') && $a132Kept($form),
                "{$l}: a refused coupon is named in an alert, not applied, and the typed values stay");
        }
        $a132Reasons = [];
        foreach (['apply_unknown', 'apply_min', 'apply_used_up'] as $a132Action) {
            [, $r] = $a132Do($a132Action);
            preg_match('~<p id="checkout-coupon-error"[^>]*>([^<]*)</p>~', (string)$r['output'], $a132M);
            $a132Reasons[] = $a132M[1] ?? '';
        }
        assert_true($a132Reasons[0] !== $a132Reasons[1] && str_replace('A132ONCE', 'A132NOPE', $a132Reasons[2]) === $a132Reasons[0],
            'A13.2 ' . $a132Driver . ': a used-up coupon reads like an unknown one, only the minimum amount has its own reason ('
            . json_encode($a132Reasons, JSON_UNESCAPED_UNICODE) . ')');

        [$l, $r] = $a132Do('remove');
        $form = $a132Form((string)$r['output']);
        assert_true($r['new_orders'] === [] && str_contains($form, 'name="coupon_code" autocomplete="off" maxlength="64" value=""')
            && !str_contains((string)$r['output'], 'id="summary-discount"') && $a132Kept($form), "{$l}: removing the coupon drops the discount and keeps the form");

        [$l, $r] = $a132Do('apply_no_csrf');
        assert_true($r['new_orders'] === [] && array_filter(array_map(static fn(string $f): string => htmlspecialchars((string)((require $f)['checkout_csrf_error'] ?? ''), ENT_QUOTES, 'UTF-8'), glob(dirname(TEST_CLI_DIR) . '/lang/*.php')), static fn(string $m): bool => str_contains((string)$r['output'], $m)) !== [] && !str_contains((string)$r['output'], 'name="coupon_action" value="remove"'),
            "{$l}: a coupon request without the CSRF token is refused");

        [$l, $r] = $a132Do('place');
        $a132Order = $r['new_orders'][0] ?? [];
        assert_true(count($r['new_orders']) === 1 && (int)($a132Order['subtotal'] ?? 0) === 4000 && (int)($a132Order['discount_amount'] ?? 0) === 400,
            "{$l}: the order placed with the shown coupon carries the same 400 discount (" . json_encode($r['new_orders'])
            . ($r['new_orders'] ? '' : ' ' . substr(trim(strip_tags((string)preg_replace('~^.*<main[^>]*>|</main>.*$~s', '', (string)$r['output']))), 0, 300)) . ')');

        [$l, $r] = $a132Do('place_raced');
        assert_true($r['code'] === 200 && $r['new_orders'] === [] && str_contains((string)$r['output'], $r['expected']['race_refusal'] ?? '-')
            && !str_contains((string)$r['output'], $r['expected']['failed'] ?? '-'),
            "{$l}: losing the last coupon use to a concurrent order is the coupon refusal, not a 503 checkout failure (HTTP {$r['code']}, " . json_encode($r['new_orders']) . ')');

        // The form's coupon attempts count against the coupon API's budget (10 per 10 minutes per client IP).
        $a132Throttled = array_filter(array_map(static fn(string $f): string => htmlspecialchars((string)((require $f)['coupon_too_many_attempts'] ?? ''), ENT_QUOTES, 'UTF-8'), glob(dirname(TEST_CLI_DIR) . '/lang/*.php')));
        $a132Tries = 0;
        do {
            [$l, $r] = $a132Do('apply_unknown');
            $a132Hit = array_filter($a132Throttled, static fn(string $m): bool => str_contains((string)$r['output'], $m)) !== [];
        } while (!$a132Hit && ++$a132Tries < 12);
        assert_true($a132Hit && $a132Tries <= 3, "{$l}: after 10 coupon attempts from the checkout form the next one is throttled ({$a132Tries} extra tries)");
    }
} finally {
    if (isset($a132Drivers['mysql'])) $a132Run(['mysql', 'teardown', 'none', $a132App, $a132Drivers['mysql'], $a132Root, '{}']);
    $a132Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a132Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a132Tree as $a132Path) $a132Path->isDir() ? @rmdir($a132Path->getPathname()) : @unlink($a132Path->getPathname());
    @rmdir($a132Root);
}
