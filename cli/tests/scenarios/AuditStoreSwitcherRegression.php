<?php

// A15.3 / usability 8: the admin store switcher offers only granted stores, narrows lists and reports, and a foreign store in the URL/POST is refused.
echo "\n--- Audit A15.3: admin store switcher limited to granted stores ---\n";
foreach ($GLOBALS as $a153Value) if ($a153Value instanceof PDOStatement) $a153Value->closeCursor();
$a153Key = base64_encode(str_repeat('S', 32));
putenv('BBE_ADMIN_IDENTITY_KEY=' . $a153Key);
$a153Grants = ['orders.view', 'orders.edit', 'reports.view'];
$a153Multi = AdminIdentity::create('a153-multi', 'A153-password-multi', 'manager', false);
AdminIdentity::grantStore($a153Multi, 'default', $a153Grants);
AdminIdentity::grantStore($a153Multi, 'cz', $a153Grants);
$a153Cz = AdminIdentity::create('a153-cz', 'A153-password-cz', 'manager', false);
AdminIdentity::grantStore($a153Cz, 'cz', $a153Grants);
$a153Tokens = [];
foreach (['multi' => 'a153-multi', 'cz' => 'a153-cz'] as $a153Who => $a153Name) {
    $a153Tokens[$a153Who] = (string)(AdminIdentity::authenticate($a153Name, 'A153-password-' . $a153Who, '', '127.0.15.30', 'A153 ' . $a153Who)['token'] ?? '');
}
$a153Order = Db::get()->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id, created_at)
    VALUES (?, 'paid', 'paid', 'A153 Buyer', 'a153@example.test', 'bank', ?, ?, ?, ?, ?)");
foreach ([['A153-SK-1', 1234, 'eur', 'default'], ['A153-CZ-1', 43210, 'czk', 'cz'], ['A153-HU-1', 987600, 'huf', 'hu']] as [$a153Number, $a153Total, $a153Currency, $a153Store]) {
    $a153Order->execute([$a153Number, $a153Total, $a153Total, $a153Currency, $a153Store, gmdate('Y-m-d H:i:s')]);
}
$a153Order->closeCursor();

$a153Root = test_fixture_path('admin-store-switcher');
mkdir($a153Root);
mkdir($a153Root . '/sessions');
file_put_contents($a153Root . '/admin.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $key, $who, $token, $getJson, $postJson] = $argv;
require $root . '/src/autoload.php';
putenv('BBE_ADMIN_IDENTITY_KEY=' . $key);
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
JobQueue::setDir($dir . '/jobs');
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$post = json_decode($postJson, true);
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
session_save_path($dir . '/sessions');
session_id('a153sess' . $who);
session_start();
$_SESSION['csrf_token'] = 'a153';
$_SESSION['admin_identity_token'] = $token;
$_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.15.31';
$_SERVER['HTTP_USER_AGENT'] = 'A153 ' . $who;
$_SERVER['REQUEST_URI'] = '/admin.php';
$_GET = json_decode($getJson, true);
$_POST = $post === [] ? [] : $post + ['csrf_token' => 'a153'];
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nA153RESULT" . json_encode(['code' => http_response_code() ?: 200, 'store' => $_SESSION['admin_store'] ?? null, 'out' => $out]);
});
require $root . '/src/Entrypoints/Admin.php';
PHP);
$a153Run = static function(string $who, array $get, array $post = []) use ($a153Root, $testDbPath, $a153Key, $a153Tokens): array {
    $process = proc_open([PHP_BINARY, $a153Root . '/admin.php', dirname(TEST_CLI_DIR), $testDbPath, $a153Root, $a153Key, $who, $a153Tokens[$who], json_encode($get), json_encode($post)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($raw, 'A153RESULT');
    $result = $at === false ? null : json_decode(substr($raw, $at + 10), true);
    return is_array($result) ? $result + ['raw' => ''] : ['code' => null, 'store' => null, 'out' => '', 'raw' => substr($raw, 0, 600)];
};
$a153Label = static fn(string $label, array $r): string => $label . ' (code=' . var_export($r['code'], true) . ' store=' . var_export($r['store'], true) . ($r['raw'] !== '' ? ' raw=' . $r['raw'] : '') . ')';
/** Store ids offered by the switcher, or null when the page has no switcher. */
$a153Options = static function(string $html): ?array {
    if (!preg_match('~<select[^>]*data-store-switcher[^>]*>(.*?)</select>~s', $html, $select)) return null;
    preg_match_all('~<option value="([^"]*)"~', $select[1], $options);
    return $options[1];
};
$a153Shows = static fn(array $r, string $number): bool => str_contains($r['out'], $number);
$a153Refused = static fn(array $r): bool => $r['code'] === 403 && !str_contains($r['out'], 'A153-');

try {
    $r = $a153Run('multi', ['p' => 'orders']);
    assert_true($r['code'] === 200 && $a153Shows($r, 'A153-SK-1') && !$a153Shows($r, 'A153-CZ-1') && !$a153Shows($r, 'A153-HU-1'),
        $a153Label('A15.3 multi-store admin: orders list starts in the host store only', $r));
    assert_equals(['default', 'cz'], $a153Options($r['out']), 'A15.3 switcher: offers exactly the granted stores (not hu/pl/de/us)');

    $r = $a153Run('multi', ['p' => 'orders'], ['admin_switch_store' => '1', 'store' => 'cz', 'return' => 'orders']);
    assert_true($r['code'] === 302 && $r['store'] === 'cz', $a153Label('A15.3 switch: a granted store is remembered and the admin is redirected', $r));
    $r = $a153Run('multi', ['p' => 'orders']);
    assert_true($r['code'] === 200 && $a153Shows($r, 'A153-CZ-1') && !$a153Shows($r, 'A153-SK-1') && !$a153Shows($r, 'A153-HU-1'),
        $a153Label('A15.3 switch: the orders list now shows only the cz store', $r));
    $r = $a153Run('multi', ['p' => 'reports']);
    assert_true($r['code'] === 200 && str_contains($r['out'], 'data-currency="czk"') && !str_contains($r['out'], 'data-currency="eur"'),
        $a153Label('A15.3 switch: reports follow the selected store', $r));
    $r = $a153Run('multi', ['p' => 'orders', 'store' => 'default']);
    assert_true($r['code'] === 200 && $a153Shows($r, 'A153-SK-1') && !$a153Shows($r, 'A153-CZ-1') && $r['store'] === 'cz',
        $a153Label('A15.3 URL: ?store= of a granted store scopes that request without changing the remembered store', $r));

    $r = $a153Run('multi', ['p' => 'orders'], ['admin_switch_store' => '1', 'store' => 'hu', 'return' => 'orders']);
    assert_true($a153Refused($r) && $r['store'] === 'cz', $a153Label('A15.3 switch: an ungranted store is refused and the choice is kept', $r));
    foreach (['hu' => 'ungranted store', 'zz' => 'unknown store', '' => 'empty store'] as $a153Foreign => $a153Why) {
        $r = $a153Run('multi', ['p' => 'orders', 'store' => $a153Foreign]);
        assert_true($a153Refused($r), $a153Label("A15.3 URL: ?store= of an {$a153Why} is refused", $r));
    }
    $r = $a153Run('multi', ['p' => 'orders', 'store' => ['cz']]);
    assert_true($a153Refused($r), $a153Label('A15.3 URL: an array store parameter is refused', $r));
    $r = $a153Run('multi', ['p' => 'orders', 'action' => 'bulk_status'], ['store' => 'hu', 'order_ids' => ['1'], 'status' => 'shipped']);
    assert_true($a153Refused($r), $a153Label('A15.3 POST: a write naming an ungranted store is refused', $r));
    $r = $a153Run('multi', ['p' => 'orders'], ['admin_switch_store' => '1', 'store' => 'default', 'return' => 'orders', 'csrf_token' => 'forged']);
    assert_true($r['code'] === 403 && $r['store'] === 'cz', $a153Label('A15.3 switch: a forged switch request is refused', $r));
    $r = $a153Run('multi', ['p' => 'orders'], ['admin_switch_store' => '1', 'store' => 'default', 'return' => 'https://evil.example/']);
    assert_true($r['code'] === 302 && $r['store'] === 'default', $a153Label('A15.3 switch: an external return URL does not block the switch', $r));
    // The CLI SAPI keeps no response headers, so the redirect target is checked on the function admin.php sends.
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/StoreScope.php';
    $a153Pages = require dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Permissions.php';
    assert_equals(
        ['admin.php?p=orders', 'admin.php?p=dashboard', 'admin.php?p=dashboard', 'admin.php?p=dashboard'],
        array_map(static fn($return): string => adminStoreSwitchTarget($return, $a153Pages), ['orders', 'https://evil.example/', 'orders&store=hu', ['orders']]),
        'A15.3 switch: the return target is a known admin page, never an external URL or extra query'
    );

    // An admin granted only the cz store lands there instead of being locked out of the host store.
    $r = $a153Run('cz', ['p' => 'orders']);
    assert_true($r['code'] === 200 && $a153Shows($r, 'A153-CZ-1') && !$a153Shows($r, 'A153-SK-1'), $a153Label('A15.3 single-store admin: works in its only granted store', $r));
    assert_true($a153Options($r['out']) === null, 'A15.3 single-store admin: no switcher when there is nothing to switch to');
    $r = $a153Run('cz', ['p' => 'orders', 'store' => 'default']);
    assert_true($a153Refused($r), $a153Label('A15.3 single-store admin: ?store=default is refused', $r));

    // A revoked grant takes effect on the next request even for the remembered store.
    AdminIdentity::grantStore($a153Multi, 'default', []);
    $r = $a153Run('multi', ['p' => 'orders']);
    assert_true($r['code'] === 200 && $a153Shows($r, 'A153-CZ-1') && !$a153Shows($r, 'A153-SK-1') && $a153Options($r['out']) === null,
        $a153Label('A15.3 revoke: a revoked store disappears from the switcher and the admin falls back to a granted one', $r));
} finally {
    putenv('BBE_ADMIN_IDENTITY_KEY');
    $a153Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a153Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a153Tree as $a153Path) $a153Path->isDir() ? rmdir($a153Path->getPathname()) : unlink($a153Path->getPathname());
    rmdir($a153Root);
}
