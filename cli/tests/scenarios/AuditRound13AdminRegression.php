<?php
/**
 * Round 13 (review 2026-11-03), PR "admin": T10 + UX8 a store under a path the shop routes itself (/api, /product, …)
 * took those URLs over — refused in the admin, never routed, reported by doctor; T17 the legal text editor's &lang=
 * also switched the admin language, re-authentication (current password) had no attempt limit, a store with
 * customers or used as another store's catalogue/providers could be removed and left its admin grants behind, an
 * admin created in the web admin could skip TOTP, and the fulfillment role held orders.edit (items, prices, manual
 * orders, cancelling).
 */

echo "\n--- Audit R13: admin (reserved store prefixes, legal editor language, re-auth limit, store removal, TOTP, fulfillment) ---\n";

// --- T10 / UX8: reserved URL prefixes -------------------------------------------------------------------------------
$r13aReserved = static fn(string $prefix): ?bool => method_exists(StoreContext::class, 'isReservedPrefix') ? StoreContext::isReservedPrefix($prefix) : null;
foreach (['/api', '/API', '/product', '/category/x', '/page', '/feeds', '/assets', '/uploads', '/healthz', '/config', '/admin.php'] as $r13aPrefix) {
    assert_true($r13aReserved($r13aPrefix) === true, "R13 T10: {$r13aPrefix} is reserved for the shop's own routes");
}
foreach (['/cs', '/b2b', '/sk2', '/cs/b2b', ''] as $r13aPrefix) {
    assert_true($r13aReserved($r13aPrefix) === false, "R13 T10: {$r13aPrefix} stays usable as a store prefix");
}
$r13aRoot = ['default_store' => 'default', 'stores' => [
    'default' => ['currency' => 'eur', 'url_prefix' => ''],
    'evil' => ['currency' => 'eur', 'url_prefix' => '/api'],
    'shadow' => ['currency' => 'eur', 'url_prefix' => '/product/'],
    'cz' => ['currency' => 'czk', 'url_prefix' => '/cs'],
]];
assert_equals(['default', 'default', 'default', 'cz'], [
    StoreContext::resolve($r13aRoot, '/api/v1/storefront/cart', '')->id(),
    StoreContext::resolve($r13aRoot, '/api', '')->id(),
    StoreContext::resolve($r13aRoot, '/product/book-1', '')->id(),
    StoreContext::resolve($r13aRoot, '/cs/product/book-1', '')->id(),
], 'R13 T10: a store under /api or /product does not take over the API or product pages; a normal prefix still resolves');
assert_equals([['path' => '/product/api/v1/x', 'store' => null], ['path' => '/api/v1/x', 'store' => 'cz']],
    [ApiRoute::normalize('/product/api/v1/x', '', $r13aRoot['stores']), ApiRoute::normalize('/cs/api/v1/x', '', $r13aRoot['stores'])],
    'R13 T10: a reserved prefix is not stripped from API paths either; a store prefix still is');

$r13aTmp = test_fixture_path('r13-admin');
mkdir($r13aTmp);
$r13aStoresFile = $r13aTmp . '/stores.json';
copy(dirname(TEST_CLI_DIR) . '/stores.example.json', $r13aStoresFile);
$r13aStores = static fn(): array => json_decode((string)file_get_contents($r13aStoresFile), true);
$r13aStaffId = 0;
try {
    StoreSettings::setPathForTesting($r13aStoresFile);
    foreach (['/api', '/product', '/feeds', '/assets', '/uploads'] as $r13aPrefix) {
        $r13aBefore = (string)file_get_contents($r13aStoresFile);
        $r13aResult = StoreSettings::addStore(['id' => 'r13a' . trim($r13aPrefix, '/'), 'name' => 'R13A', 'url_prefix' => $r13aPrefix, 'default_locale' => 'en', 'currency' => 'eur']);
        assert_true(!$r13aResult['ok'] && $r13aBefore === (string)file_get_contents($r13aStoresFile), "R13 UX8: the admin refuses a new store under {$r13aPrefix}");
    }
    $r13aDoctor = static fn(array $stores): array => array_values(array_filter(Setup::doctor(['db_driver' => 'sqlite', 'private_runtime_dir' => $r13aTmp], dirname(TEST_CLI_DIR))['checks'] ?? [],
        static fn(array $c): bool => str_starts_with((string)$c['name'], 'stores.url_prefix.')));
    $r13aFile = $r13aStores();
    $r13aFile['stores']['evil'] = array_replace($r13aFile['stores']['cz'], ['id' => 'evil', 'url_prefix' => '/api']);
    file_put_contents($r13aTmp . '/stores.json', json_encode($r13aFile));
    $r13aChecks = $r13aDoctor($r13aFile);
    assert_true(count($r13aChecks) === 1 && $r13aChecks[0]['name'] === 'stores.url_prefix.evil' && $r13aChecks[0]['status'] === 'fail',
        'R13 T10: doctor fails a hand-edited store under a reserved prefix (' . json_encode($r13aChecks) . ')');
    copy(dirname(TEST_CLI_DIR) . '/stores.example.json', $r13aStoresFile);

    // --- T17: store removal ------------------------------------------------------------------------------------------
    assert_true(StoreSettings::addStore(['id' => 'r13a', 'name' => 'R13A shop', 'url_prefix' => '/r13a', 'default_locale' => 'en', 'currency' => 'eur'])['ok'], 'R13 T17: a store is added');
    $pdo->prepare("INSERT INTO customers (email, password_hash, store_id) VALUES ('r13a-customer@example.test', 'x', 'r13a')")->execute();
    assert_true(!StoreSettings::removeStore('r13a', $pdo)['ok'] && isset($r13aStores()['stores']['r13a']), 'R13 T17: a store with customer accounts cannot be removed');
    $pdo->exec("DELETE FROM customers WHERE email = 'r13a-customer@example.test'");
    $r13aFile = $r13aStores();
    $r13aFile['stores']['r13b'] = array_replace($r13aFile['stores']['r13a'] ?? $r13aFile['stores']['cz'], ['id' => 'r13b', 'url_prefix' => '/r13b', 'catalog_scope' => 'r13a']);
    file_put_contents($r13aStoresFile, json_encode($r13aFile));
    assert_true(!StoreSettings::removeStore('r13a', $pdo)['ok'] && isset($r13aStores()['stores']['r13a']), 'R13 T17: a store whose catalogue another store sells cannot be removed');
    assert_true(StoreSettings::removeStore('r13b', $pdo)['ok'], 'R13 T17: the store using it is removed first');
    $r13aStaffId = AdminIdentity::create('r13a-store-staff', 'R13a-password-staff', 'viewer', false);
    AdminIdentity::grantStore($r13aStaffId, 'r13a', ['*']);
    assert_true(StoreSettings::removeStore('r13a', $pdo)['ok'] && !isset($r13aStores()['stores']['r13a']), 'R13 T17: an unused store is removed');
    $r13aGrants = $pdo->prepare("SELECT COUNT(*) FROM admin_store_permissions WHERE store_id = 'r13a'");
    $r13aGrants->execute();
    assert_equals(0, (int)$r13aGrants->fetchColumn(), 'R13 T17: the removed store\'s admin grants go with it (a later store with the same id grants nobody)');
} finally {
    StoreSettings::setPathForTesting(null);
    $pdo->exec("DELETE FROM customers WHERE email = 'r13a-customer@example.test'");
    $pdo->exec("DELETE FROM admin_store_permissions WHERE store_id IN ('r13a', 'r13b')");
    if ($r13aStaffId > 0) $pdo->prepare('DELETE FROM admin_accounts WHERE id = ?')->execute([$r13aStaffId]);
}

// --- Rendered admin (own SQLite database and stores.json copy) -----------------------------------------------------
foreach ($GLOBALS as $r13aValue) if ($r13aValue instanceof PDOStatement) $r13aValue->closeCursor();
$r13aAppRoot = dirname(TEST_CLI_DIR);
mkdir($r13aTmp . '/sessions');
$r13aDbPath = $r13aTmp . '/admin.sqlite';
$r13aSuiteDb = Db::get();
$r13aSuiteDriver = Db::driver();
$r13aPdo = new PDO('sqlite:' . $r13aDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$r13aKey = base64_encode(str_repeat('A', 32));
$r13aPreviousKey = getenv('BBE_ADMIN_IDENTITY_KEY');
putenv('BBE_ADMIN_IDENTITY_KEY=' . $r13aKey);
$r13aTokens = [];
$r13aIds = [];
$r13aLogin = static function (string $who) use (&$r13aTokens): void {
    $r13aTokens[$who] = (string)(AdminIdentity::authenticate("r13a-{$who}", 'R13a-password-' . $who, '', '127.0.13.80', 'R13A')['token'] ?? '');
};
try {
    Db::init($r13aPdo, 'sqlite');
    (new Migrator($r13aPdo, $r13aAppRoot . '/migrations', $r13aTmp . '/migrate.lock'))->apply();
    foreach (['owner', 'fulfillment'] as $r13aWho) {
        $r13aIds[$r13aWho] = AdminIdentity::create("r13a-{$r13aWho}", 'R13a-password-' . $r13aWho, $r13aWho, false);
        AdminIdentity::grantStore($r13aIds[$r13aWho], 'default', ['*']);
        $r13aLogin($r13aWho);
    }
} finally {
    Db::init($r13aSuiteDb, $r13aSuiteDriver);
}
file_put_contents($r13aTmp . '/admin.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $key, $token, $getJson, $postJson] = $argv;
require $root . '/src/autoload.php';
putenv('BBE_ADMIN_IDENTITY_KEY=' . $key);
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
JobQueue::setDir($dir . '/jobs');
StoreSettings::setPathForTesting($dir . '/stores.json');
// A file, not :memory:: the re-authentication limit has to outlive one request.
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
$post = json_decode($postJson, true);
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
session_save_path($dir . '/sessions');
session_id('r13a' . substr(hash('sha256', $token), 0, 16));
session_start();
$_SESSION['csrf_token'] = 'r13a';
$_SESSION['admin_identity_token'] = $token;
$_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.13.81';
$_SERVER['HTTP_USER_AGENT'] = 'R13A';
$_SERVER['REQUEST_URI'] = '/admin.php';
$_GET = json_decode($getJson, true);
$_POST = $post === [] ? [] : $post + ['csrf_token' => 'r13a'];
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR13ARESULT" . json_encode(['code' => http_response_code() ?: 200, 'flash' => $_SESSION['flash'] ?? null, 'out' => $out], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/src/Entrypoints/Admin.php';
PHP);
$r13aRun = static function (string $who, array $get, array $post = []) use ($r13aTmp, $r13aAppRoot, $r13aDbPath, $r13aKey, &$r13aTokens): array {
    $process = proc_open([PHP_BINARY, $r13aTmp . '/admin.php', $r13aAppRoot, $r13aDbPath, $r13aTmp, $r13aKey, $r13aTokens[$who], json_encode($get), json_encode($post)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($stdout, 'R13ARESULT');
    $result = $at === false ? null : json_decode(substr($stdout, $at + 10), true);
    return is_array($result) ? $result : ['code' => null, 'flash' => null, 'out' => substr($stderr . ' | ' . substr($stdout, -300), 0, 900)];
};
$r13aRead = static function (string $sql, array $args = []) use ($r13aDbPath) {
    $read = new PDO('sqlite:' . $r13aDbPath);
    $stmt = $read->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};
$r13aLabel = static fn(string $label, array $r): string => $label . ' (code=' . var_export($r['code'], true) . ' flash=' . json_encode($r['flash'] ?? null) . ')';
$r13aDenied = static fn(array $r): bool => $r['code'] === 403 && str_contains($r['out'], 'insufficient permissions');
try {
    // --- T17: &lang= of the legal text editor --------------------------------------------------------------------
    $r = $r13aRun('owner', ['p' => 'stores', 'lang' => 'en']);
    assert_true(str_contains($r['out'], '&amp;legal=terms&amp;text_lang=sk#store-legal') && !str_contains($r['out'], '&amp;legal=terms&amp;lang='),
        $r13aLabel('R13 T17 legal editor: the text links choose the text language with text_lang, not the admin language', $r));
    $r = $r13aRun('owner', ['p' => 'stores', 'store' => 'default', 'legal' => 'terms', 'text_lang' => 'sk']);
    assert_true(str_contains($r['out'], 'action=legal_save&amp;store=default&amp;legal=terms&amp;text_lang=sk"') && str_contains($r['out'], '<html lang="en">'),
        $r13aLabel('R13 T17 legal editor: the Slovak terms open for editing while the admin stays in English', $r));
    $r = $r13aRun('owner', ['p' => 'stores', 'store' => 'default', 'legal' => 'terms', 'text_lang' => 'sk', 'lang' => 'de']);
    assert_true(str_contains($r['out'], 'action=legal_save&amp;store=default&amp;legal=terms&amp;text_lang=sk"') && str_contains($r['out'], '<html lang="de">'),
        $r13aLabel('R13 T17 legal editor: switching the admin language keeps the text being edited', $r));
    $r13aRun('owner', ['p' => 'stores', 'lang' => 'en']);

    // --- T17: an admin created on the web always gets TOTP -------------------------------------------------------
    $r = $r13aRun('owner', ['p' => 'admin_users', 'action' => 'create'], ['username' => 'r13a-staff', 'role' => 'viewer', 'password' => 'R13a-password-staff']);
    $r13aStaff = $r13aRead('SELECT mfa_required, totp_secret_encrypted FROM admin_accounts WHERE username = ?', ['r13a-staff']);
    assert_true($r13aStaff !== null && (int)$r13aStaff['mfa_required'] === 1 && (string)$r13aStaff['totp_secret_encrypted'] !== '',
        $r13aLabel('R13 T17 TOTP: an account created in the web admin without the MFA box still requires and enrolls TOTP', $r));
    $r = $r13aRun('owner', ['p' => 'admin_users']);
    assert_true(preg_match('#<h2>r13a-staff — TOTP</h2>.*?<code>[A-Z2-7]{32}</code>#s', $r['out']) === 1 && !str_contains($r['out'], 'name="mfa"'),
        $r13aLabel('R13 T17 TOTP: the enrollment secret is shown once and the form has no MFA opt-out', $r));

    // --- T17: fulfillment ships orders, it does not edit or cancel them -----------------------------------------
    $r = $r13aRun('fulfillment', ['p' => 'order', 'id' => '999999', 'action' => 'status'], ['new_status' => 'cancelled']);
    assert_true($r13aDenied($r), $r13aLabel('R13 T17 fulfillment: cannot cancel an order', $r));
    $r = $r13aRun('fulfillment', ['p' => 'order', 'id' => '999999', 'action' => 'items_edit'], ['qty' => ['1' => '5']]);
    assert_true($r13aDenied($r), $r13aLabel('R13 T17 fulfillment: cannot edit order lines', $r));
    $r = $r13aRun('fulfillment', ['p' => 'order_new']);
    assert_true($r13aDenied($r), $r13aLabel('R13 T17 fulfillment: cannot open the manual order form', $r));
    $r = $r13aRun('fulfillment', ['p' => 'order', 'id' => '999999', 'action' => 'status'], ['new_status' => 'processing']);
    assert_true(!$r13aDenied($r) && is_array($r['flash']), $r13aLabel('R13 T17 fulfillment: still moves an order along its workflow', $r));
    $r = $r13aRun('fulfillment', ['p' => 'orders', 'action' => 'bulk_status'], ['order_ids' => ['999999'], 'new_status' => 'cancelled']);
    assert_true($r13aDenied($r), $r13aLabel('R13 T17 fulfillment: cannot cancel orders in bulk', $r));

    // --- T17: re-authentication has an attempt limit --------------------------------------------------------------
    $r13aOwnerHash = static fn() => (string)($r13aRead('SELECT password_hash FROM admin_accounts WHERE id = ?', [$r13aIds['owner']])['password_hash'] ?? '');
    $r13aChange = static fn(string $current): array => $r13aRun('owner', ['p' => 'admin_users', 'action' => 'password'],
        ['account_id' => (string)$r13aIds['owner'], 'password' => 'R13a-taken-over-password', 'current_password' => $current]);
    for ($r13aTry = 1; $r13aTry <= 4; $r13aTry++) $r13aChange('wrong-guess-' . $r13aTry);
    $r13aSessionBefore = $r13aRead('SELECT revoked_at FROM admin_sessions WHERE token_hash = ?', [hash('sha256', $r13aTokens['owner'])]);
    $r = $r13aChange('wrong-guess-5');
    $r13aSessionAfter = $r13aRead('SELECT revoked_at FROM admin_sessions WHERE token_hash = ?', [hash('sha256', $r13aTokens['owner'])]);
    assert_true($r13aSessionBefore !== null && $r13aSessionBefore['revoked_at'] === null && ($r13aSessionAfter['revoked_at'] ?? null) !== null,
        $r13aLabel('R13 T17 re-auth: the fifth wrong current password signs the guessing session out', $r));
    Db::init($r13aPdo, 'sqlite');
    try { $r13aLogin('owner'); } finally { Db::init($r13aSuiteDb, $r13aSuiteDriver); }
    $r = $r13aChange('R13a-password-owner');
    assert_true(!password_verify('R13a-taken-over-password', $r13aOwnerHash()) && str_contains((string)($r['flash']['msg'] ?? json_encode($r['flash'])), '15'),
        $r13aLabel('R13 T17 re-auth: within the window even the right current password is refused', $r));
} finally {
    $r13aPdo = null;
    if ($r13aPreviousKey === false) putenv('BBE_ADMIN_IDENTITY_KEY');
    else putenv('BBE_ADMIN_IDENTITY_KEY=' . $r13aPreviousKey);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r13aTmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $r13aItem) {
        $r13aItem->isDir() ? @rmdir($r13aItem->getPathname()) : @unlink($r13aItem->getPathname());
    }
    @rmdir($r13aTmp);
}
