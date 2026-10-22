<?php

// A07.3 / low findings: one-time TOTP, no customer lockout DoS, strict sessions + CSRF rotation, no raw session IDs at rest.
echo "\n--- Audit A07.3: TOTP replay, login throttle, session hardening ---\n";
foreach ($GLOBALS as $a073Value) if ($a073Value instanceof PDOStatement) $a073Value->closeCursor();

// TOTP: a code is accepted once; replays and older steps inside the ±1 window are refused.
putenv('BBE_ADMIN_IDENTITY_KEY=' . base64_encode(str_repeat('K', 32)));
try {
    $a073Admin = AdminIdentity::create('a073-operator', 'A073-password-1234', 'manager', true);
    $a073Secret = AdminIdentity::enrollTotp($a073Admin)['secret'];
    $a073Code = Totp::code($a073Secret);
    $a073First = AdminIdentity::authenticate('a073-operator', 'A073-password-1234', $a073Code, '127.0.7.4', 'A073 Device');
    assert_true($a073First !== null, 'A07.3 TOTP: a fresh code authenticates');
    // A replay that wrongly succeeds emits no login_failed event; count it as accepted instead of aborting the suite.
    $a073Refused = static function(string $code): bool {
        try {
            return with_expected_structured_log('admin.login_failed', static fn() => AdminIdentity::authenticate('a073-operator', 'A073-password-1234', $code, '198.51.100.73', 'Replayer')) === null;
        } catch (RuntimeException $e) {
            return false;
        }
    };
    assert_true($a073Refused($a073Code), 'A07.3 TOTP: the same code cannot be replayed inside its validity window');
    assert_true($a073Refused(Totp::code($a073Secret, time() - 30)), 'A07.3 TOTP: an older time step is refused after a newer one was used');
    $a073Next = AdminIdentity::authenticate('a073-operator', 'A073-password-1234', Totp::code($a073Secret, time() + 30), '127.0.7.4', 'A073 Device');
    assert_true($a073Next !== null, 'A07.3 TOTP: the next time step still authenticates');
    foreach ([$a073First, $a073Next] as $a073Login) if ($a073Login) AdminIdentity::revokeSession($a073Login['session_id']);
} finally {
    putenv('BBE_ADMIN_IDENTITY_KEY');
}

$a073Pdo = new PDO('sqlite:' . $testDbPath);
$a073Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$a073Pdo->exec("CREATE TABLE IF NOT EXISTS cookie_consent (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT NULL, customer_id INTEGER NULL,
    ip_hash TEXT NULL, user_agent TEXT NULL, categories_json TEXT NOT NULL, method TEXT NOT NULL, consent_version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')))");
$a073Pdo->prepare("INSERT INTO customers (email, password_hash, name, locale, status, store_id) VALUES (?, ?, 'A073', 'en', 'active', 'default')")
    ->execute(['a073-owner@example.com', password_hash('A073-owner-pass', PASSWORD_BCRYPT, ['cost' => 4])]);
$a073Pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (9731, 'default', 1, 'A073 item', 'a073-item', 'A073-ITEM', 'simple', 900, 'eur', 'active', 5, 'deny')");
$a073Root = test_fixture_path('session-hardening');
mkdir($a073Root);
mkdir($a073Root . '/sessions');
file_put_contents($a073Root . '/child.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $mode, $email, $password, $ip] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ob_start();
session_save_path($dir . '/sessions');
$_SERVER['REMOTE_ADDR'] = $ip;
$_SERVER['REQUEST_URI'] = '/account.php';
$result = [];
if ($mode === 'login') {
    session_start();
    $_SESSION = ['csrf_token' => 'a073'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['p' => 'login'];
    $_POST = ['action' => 'login', 'email' => $email, 'password' => $password, 'csrf_token' => 'a073'];
    ob_start();
    register_shutdown_function(static function(): void {
        while (ob_get_level() > 0) ob_end_clean();
        echo "\nA073RESULT" . json_encode(['customer_id' => (int)($_SESSION['customer_id'] ?? 0)]);
    });
    require $root . '/account.php';
    exit;
}
if ($mode === 'consent_form') {
    session_start();
    $_SESSION = ['csrf_token' => 'a073'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['p' => 'login'];
    $_POST = ['action' => 'cookie_consent', 'analytics' => '1', 'method' => 'preferences', 'csrf_token' => 'a073'];
    ob_start();
    $formSid = session_id();
    register_shutdown_function(static function() use ($formSid): void {
        while (ob_get_level() > 0) ob_end_clean();
        echo "\nA073RESULT" . json_encode(['sid' => $formSid]);
    });
    require $root . '/account.php';
    exit;
}
if ($mode === 'admin') {
    // Admin login (HTTP Basic against a DB identity) must replace the session ID the visitor arrived with.
    putenv('BBE_ADMIN_IDENTITY_KEY=' . base64_encode(str_repeat('K', 32)));
    Security::sessionStart();
    $before = session_id();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_AUTH_USER'] = $email;
    $_SERVER['PHP_AUTH_PW'] = $password;
    $ok = Security::adminAuth();
    echo "\nA073RESULT" . json_encode(['login' => $ok, 'sid_before' => $before, 'sid_after' => session_id()]);
    exit;
}
if ($mode === 'legacy_admin') {
    // Admin login against the config.php password (no DB accounts) must also replace the session ID.
    Db::init(new PDO('sqlite::memory:'), 'sqlite');
    (new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['admin_user' => $email,
        'admin_password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4])] + (require $root . '/config.php'));
    Security::sessionStart();
    $before = session_id();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_AUTH_USER'] = $email;
    $_SERVER['PHP_AUTH_PW'] = $password;
    $ok = Security::adminAuth();
    echo "\nA073RESULT" . json_encode(['login' => $ok, 'sid_before' => $before, 'sid_after' => session_id()]);
    exit;
}
if ($mode === 'logout') {
    // Logout must hand the next person at the computer a new session ID, not the signed-in one.
    Security::sessionStart();
    [$ok] = Customers::login($email, $password);
    $before = session_id();
    Customers::logout();
    echo "\nA073RESULT" . json_encode(['login' => $ok, 'sid_before' => $before, 'sid_after' => session_id(),
        'customer_id' => $_SESSION['customer_id'] ?? null]);
    exit;
}
// mode=session: fixation, CSRF rotation and what lands in the database.
session_id('a073fixatedsessionid0123456789ab');
Security::sessionStart();
$result['fixation_adopted'] = session_id() === 'a073fixatedsessionid0123456789ab';
$sid = session_id();
$result['sid'] = $sid;
$_SESSION['csrf_token'] = 'a073-planted';
require_once $root . '/src/Http/ApiHandlers.php';
Wishlist::add(9731);
Cart::add(9731, null, 1);
AbandonedCarts::capture('a073-cart@example.com', null, ['version' => '1', 'text' => 'Remind me']);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf_token' => 'a073-planted', 'analytics' => '1', 'method' => 'preferences'];
$result['consent'] = apiCookieConsent();
[$ok] = Customers::login($email, $password);
$result['login'] = $ok;
$result['login_sid'] = session_id();
$result['csrf_rotated'] = ($_SESSION['csrf_token'] ?? '') !== 'a073-planted' && ($_SESSION['csrf_token'] ?? '') !== '';
echo "\nA073RESULT" . json_encode($result);
PHP);
$a073Run = static function(string $mode, string $email, string $password, string $ip) use ($a073Root, $testDbPath): array {
    $process = proc_open([PHP_BINARY, $a073Root . '/child.php', dirname(TEST_CLI_DIR), $testDbPath, $a073Root, $mode, $email, $password, $ip],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($raw, 'A073RESULT');
    $result = $at === false ? null : json_decode(substr($raw, $at + 10), true);
    return is_array($result) ? $result : ['raw' => substr($raw, 0, 400)];
};

try {
    // Customer lockout: foreign failures from one IP must not lock the owner out from another IP.
    for ($i = 0; $i < 11; $i++) $a073Run('login', 'a073-owner@example.com', 'wrong-' . $i, '198.51.100.74');
    $a073Attacker = $a073Run('login', 'a073-owner@example.com', 'A073-owner-pass', '198.51.100.74');
    assert_true(($a073Attacker['customer_id'] ?? null) === 0, 'A07.3 lockout: the guessing IP stays throttled for that account (' . json_encode($a073Attacker) . ')');
    $a073Owner = $a073Run('login', 'a073-owner@example.com', 'A073-owner-pass', '203.0.113.74');
    assert_true(($a073Owner['customer_id'] ?? 0) > 0, 'A07.3 lockout: the owner still logs in from another IP (' . json_encode($a073Owner) . ')');

    $s = $a073Run('session', 'a073-owner@example.com', 'A073-owner-pass', '203.0.113.75');
    assert_true(($s['fixation_adopted'] ?? true) === false, 'A07.3 session: an uninitialized client-chosen session ID is not adopted (strict mode) (' . json_encode($s) . ')');
    assert_true(($s['login'] ?? false) === true && ($s['csrf_rotated'] ?? false) === true, 'A07.3 session: customer login rotates the CSRF token');
    assert_true(($s['sid'] ?? '') !== '' && ($s['login_sid'] ?? '') !== '' && $s['login_sid'] !== $s['sid'],
        'R8 session fixation: customer login issues a new session ID (' . json_encode([$s['sid'] ?? null, $s['login_sid'] ?? null]) . ')');
    putenv('BBE_ADMIN_IDENTITY_KEY=' . base64_encode(str_repeat('K', 32)));
    AdminIdentity::create('r8-session-admin', 'R8-session-pass-1234', 'manager', false);
    putenv('BBE_ADMIN_IDENTITY_KEY');
    $r8Admin = $a073Run('admin', 'r8-session-admin', 'R8-session-pass-1234', '203.0.113.77');
    assert_true(($r8Admin['login'] ?? false) === true && ($r8Admin['sid_before'] ?? '') !== ''
        && ($r8Admin['sid_after'] ?? '') !== '' && $r8Admin['sid_after'] !== $r8Admin['sid_before'],
        'R8 session fixation: admin login issues a new session ID (' . json_encode($r8Admin) . ')');
    $r8Legacy = $a073Run('legacy_admin', 'r8-legacy-admin', 'R8-legacy-pass-1234', '203.0.113.78');
    assert_true(($r8Legacy['login'] ?? false) === true && ($r8Legacy['sid_before'] ?? '') !== ''
        && ($r8Legacy['sid_after'] ?? '') !== '' && $r8Legacy['sid_after'] !== $r8Legacy['sid_before'],
        'R8 tests: admin login with the config.php password issues a new session ID (' . json_encode($r8Legacy) . ')');
    $r8Logout = $a073Run('logout', 'a073-owner@example.com', 'A073-owner-pass', '203.0.113.79');
    assert_true(($r8Logout['login'] ?? false) === true && ($r8Logout['sid_before'] ?? '') !== '' && !isset($r8Logout['customer_id'])
        && ($r8Logout['sid_after'] ?? '') !== '' && $r8Logout['sid_after'] !== $r8Logout['sid_before'],
        'R8 tests: customer logout issues a new session ID (' . json_encode($r8Logout) . ')');
    $a073Sid = (string)($s['sid'] ?? '');
    $a073Key = $a073Sid !== '' ? hash('sha256', $a073Sid) : '';
    $a073Stored = static fn(string $sql): array => $a073Pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $wishlistKeys = $a073Stored('SELECT owner_key FROM wishlist_items WHERE product_id = 9731');
    $cartKeys = $a073Stored("SELECT session_id FROM abandoned_carts WHERE customer_email = 'a073-cart@example.com'");
    $consentKeys = $a073Stored("SELECT session_id FROM cookie_consent WHERE session_id IS NOT NULL ORDER BY id DESC LIMIT 1");
    assert_true($a073Sid !== '' && $wishlistKeys === ['s:' . $a073Key], 'A07.3 session IDs: wishlist stores only a hash of the session ID (' . json_encode($wishlistKeys) . ')');
    assert_true($a073Sid !== '' && $cartKeys === [$a073Key], 'A07.3 session IDs: abandoned cart stores only a hash of the session ID (' . json_encode($cartKeys) . ')');
    assert_true(($s['consent']['ok'] ?? $s['consent']['success'] ?? null) !== null && $consentKeys === [$a073Key], 'A07.3 session IDs: cookie consent log stores only a hash of the session ID (' . json_encode([$s['consent'] ?? null, $consentKeys]) . ')');
    $f = $a073Run('consent_form', '', '', '203.0.113.76');
    $a073FormSid = (string)($f['sid'] ?? '');
    $formKeys = $a073Stored("SELECT session_id FROM cookie_consent WHERE session_id IS NOT NULL ORDER BY id DESC LIMIT 1");
    assert_true($a073FormSid !== '' && $formKeys === [hash('sha256', $a073FormSid)], 'A07.3 session IDs: account.php cookie consent form stores only a hash of the session ID (' . json_encode([$f, $formKeys]) . ')');
} finally {
    $a073Pdo->exec("DELETE FROM wishlist_items WHERE product_id = 9731");
    $a073Pdo->exec("DELETE FROM abandoned_carts WHERE customer_email = 'a073-cart@example.com'");
    $a073Pdo->exec("UPDATE admin_accounts SET status = 'disabled' WHERE username = 'r8-session-admin'");
    $a073Pdo = null;
    $a073Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a073Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a073Tree as $a073Path) $a073Path->isDir() ? rmdir($a073Path->getPathname()) : unlink($a073Path->getPathname());
    rmdir($a073Root);
}
