<?php

// Review round 12 (R38, low): bytes that are not UTF-8 in a manual order's fields (a mistyped paste, a forged form)
// reached json_encode() and the pattern matching of the order lines: HTTP 500 and an incident. The admin handler now
// refuses with 400 naming the field, and nothing is saved; a well-formed order still goes through.
echo "\n--- R38 a manual order with bytes that are not UTF-8 is refused with 400, not a 500 ---\n";

$r38uRoot = test_fixture_path('manual-utf8-r38');
mkdir($r38uRoot);
mkdir($r38uRoot . '/sessions');
$r38uFixture = $r38uRoot . '/admin.php';
file_put_contents($r38uFixture, <<<'PHP'
<?php
[, $driver, $phase, $root, $target, $dir, $extra] = $argv;
require $root . '/src/autoload.php';
putenv('BBE_ADMIN_IDENTITY_KEY=' . base64_encode(str_repeat('U', 32)));
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
JobQueue::setDir($dir . '/jobs-' . $driver);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate-' . $driver . '.sqlite'));
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $id = AdminIdentity::create('r38u-owner', 'R38u-owner-password-2026', 'owner', false);
    AdminIdentity::grantStore($id, 'default', ['*']);
    AdminIdentity::setStatus($id, 'active');
    $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r38u', 'R38U', 'r38u', 0)");
    $db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['default', (int)$db->lastInsertId(), 'R38U-MUG', 'r38u-mug', 'R38U Mug', 1500, 100, 'active', 300]);
    $warehouse = (int)$db->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, ?, 100, 0)')->execute([$warehouse, 'R38U-MUG']);
    $db->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r38u_pay', 'manual', 'R38U payment', '', 'offline', 0, 0, 0, 0, 0, 1, 990)");
    $db->exec("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r38u_pay', 'SK')");
    $db->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('default', 'r38u-ship', 'r38u', 'R38U courier', 'courier', 0, 0, 1)");
    $db->exec("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order)
        VALUES ('default', 'r38u-ship', 'r38u', 'SK', 'domestic', 0, 999999, 300, 0, '1', 9)");
    echo AdminIdentity::authenticate('r38u-owner', 'R38u-owner-password-2026', '', '127.0.38.21', 'R38U')['token'] ?? '';
    exit(0);
}
$args = unserialize(base64_decode($extra));
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
session_save_path($dir . '/sessions');
session_id('r38u' . $driver . substr(hash('sha256', $args['token']), 0, 12));
session_start();
$_SESSION['csrf_token'] = 'r38u';
$_SESSION['admin_identity_token'] = $args['token'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.38.21';
$_SERVER['HTTP_USER_AGENT'] = 'R38U';
$_SERVER['REQUEST_URI'] = '/admin.php';
$_GET = ['p' => 'order_new', 'action' => 'create'];
$_POST = $args['post'] + ['csrf_token' => 'r38u'];
$count = static fn(): int => (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$before = $count();
ob_start();
register_shutdown_function(static function() use ($count, $before): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR38URESULT" . json_encode(['code' => http_response_code() ?: 200, 'new_orders' => $count() - $before,
        'flash' => $_SESSION['flash'] ?? null, 'out' => mb_substr($out, 0, 600)], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/src/Entrypoints/Admin.php';
PHP);
$r38uRun = static function(array $args) use ($r38uFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r38uFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($out, 'R38URESULT');
    return [$exit, $at === false ? null : json_decode(substr($out, $at + 10), true), trim(substr($at === false ? $out : substr($out, 0, $at), 0, 300) . ' ' . substr($err, 0, 300))];
};
$r38uApp = dirname(TEST_CLI_DIR);
$r38uTargets = [];
try {
    foreach (['sqlite', 'mysql'] as $r38uDriver) {
        if ($r38uDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R38 manual order UTF-8 native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
        $r38uTarget = $r38uDriver === 'mysql' ? 'bbe_r38u_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $r38uRoot . '/shop.sqlite';
        $r38uTargets[] = [$r38uDriver, $r38uTarget];
        [$exit, , $token] = $r38uRun([$r38uDriver, 'setup', $r38uApp, $r38uTarget, $r38uRoot, '']);
        assert_true($exit === 0 && preg_match('/^[A-Za-z0-9_-]{20,}$/', $token) === 1, "R38 manual order UTF-8 {$r38uDriver}: isolated fixture and an owner session ({$exit}, " . substr($token, 0, 120) . ')');
        $order = ['name' => 'R38U Zákazník', 'email' => 'r38u@example.test', 'phone' => '+421900000038', 'country' => 'SK', 'street' => 'Hlavná 1',
            'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'r38u-ship', 'payment_method' => 'r38u_pay', 'lines' => "R38U-MUG 2", 'locale' => 'sk'];
        foreach (['name' => "R38U Z\xE1kazn\xEDk", 'street' => "Hlavn\xE1 1", 'lines' => "R38U-MUG\xA0 2", 'notes' => "pozn\xE1mka"] as $field => $bytes) {
            $label = "R38 manual order UTF-8 {$r38uDriver}/{$field}";
            [$exit, $res, $noise] = $r38uRun([$r38uDriver, 'request', $r38uApp, $r38uTarget, $r38uRoot, base64_encode(serialize(['token' => $token, 'post' => [$field => $bytes] + $order]))]);
            assert_true(($res['code'] ?? null) === 400 && ($res['new_orders'] ?? null) === 0 && str_contains((string)($res['out'] ?? ''), 'role="alert"'),
                "{$label}: refused with 400 naming the field, no order (got " . json_encode($res === null ? $noise : ['code' => $res['code'], 'new_orders' => $res['new_orders'], 'out' => mb_substr((string)$res['out'], 0, 160)], JSON_UNESCAPED_UNICODE) . ')');
        }
        [$exit, $res, $noise] = $r38uRun([$r38uDriver, 'request', $r38uApp, $r38uTarget, $r38uRoot, base64_encode(serialize(['token' => $token, 'post' => $order]))]);
        assert_true(($res['new_orders'] ?? null) === 1 && ($res['code'] ?? null) === 302,
            "R38 manual order UTF-8 {$r38uDriver}: the same order in UTF-8 is entered (got " . json_encode($res ?? $noise, JSON_UNESCAPED_UNICODE) . ')');
    }
} finally {
    foreach ($r38uTargets as [$r38uDriver, $r38uTarget]) if ($r38uDriver === 'mysql') $r38uRun(['mysql', 'teardown', $r38uApp, $r38uTarget, $r38uRoot, '']);
}
