<?php

// Round 10 review (2026-10-25): security and payments.
echo "\n--- Audit round 10 (security): admin identity, payments, throttles ---\n";
foreach ($GLOBALS as $r10sValue) if ($r10sValue instanceof PDOStatement) $r10sValue->closeCursor();

$r10sKey = base64_encode(str_repeat('R', 32));
$r10sRoot = test_fixture_path('r10-security');
mkdir($r10sRoot);
mkdir($r10sRoot . '/sessions');
// Child process: one admin request with a chosen database, config override and pre-set session.
file_put_contents($r10sRoot . '/admin.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $key, $sessionJson, $page, $action, $postJson, $cfgJson, $basicJson] = $argv;
require $root . '/src/autoload.php';
putenv('BBE_ADMIN_IDENTITY_KEY=' . $key);
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
JobQueue::setDir($dir . '/jobs');
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$cfg = json_decode($cfgJson, true);
if ($cfg) (new ReflectionProperty(Security::class, 'cfg'))->setValue(null, $cfg + (require $root . '/config.php'));
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
ini_set('error_log', $dir . '/php-error.log');
session_save_path($dir . '/sessions');
session_id('r10s' . bin2hex(random_bytes(6)));
session_start();
$_SESSION = json_decode($sessionJson, true) + ['csrf_token' => 'r10s'];
$basic = json_decode($basicJson, true);
if ($basic) [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']] = $basic;
$_SERVER['REQUEST_METHOD'] = $action === '' ? 'GET' : 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.10.1';
$_SERVER['HTTP_USER_AGENT'] = 'R10 security';
$_GET = ['p' => $page, 'action' => $action];
$_POST = json_decode($postJson, true) + ['csrf_token' => 'r10s'];
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR10SRESULT" . json_encode(['code' => http_response_code(), 'flash' => $_SESSION['flash'] ?? null, 'out' => $out,
        'authenticated' => !empty($_SESSION['admin_authenticated']), 'identity' => $_SESSION['admin_identity_id'] ?? null]);
});
require $root . '/src/Entrypoints/Admin.php';
PHP);
$r10sRun = static function(string $dbPath, array $session, string $page = 'dashboard', string $action = '', array $post = [], array $cfg = [], array $basic = []) use ($r10sRoot, $r10sKey): array {
    $process = proc_open([PHP_BINARY, $r10sRoot . '/admin.php', dirname(TEST_CLI_DIR), $dbPath, $r10sRoot, $r10sKey, json_encode((object)$session), $page, $action,
        json_encode((object)$post), json_encode((object)$cfg), json_encode($basic)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($raw, 'R10SRESULT');
    $result = $at === false ? null : json_decode(substr($raw, $at + 10), true);
    return is_array($result) ? $result + ['raw' => ''] : ['code' => null, 'flash' => null, 'out' => '', 'authenticated' => null, 'identity' => null, 'raw' => substr($raw, 0, 600)];
};
$r10sLabel = static fn(string $label, array $r): string => $label . ' (code=' . var_export($r['code'], true) . ' flash=' . json_encode($r['flash'])
    . ' auth=' . var_export($r['authenticated'], true) . ($r['raw'] !== '' ? ' raw=' . $r['raw'] : ' out=' . substr(strip_tags((string)$r['out']), 0, 160)) . ')';
$r10sLegacyHash = password_hash('R10-legacy-password', PASSWORD_BCRYPT, ['cost' => 4]);
$r10sLegacyCfg = ['admin_user' => 'r10legacy', 'admin_password' => $r10sLegacyHash];

try {
    // M1: accountCount() read any database error as "no account", so Basic auth against the old config admin_password
    // (no MFA) signed in while the database was being restored or out of connections.
    $r10sBroken = $r10sRoot . '/broken.sqlite';
    file_put_contents($r10sBroken, str_repeat('not a database ', 512));
    $r10sPrevDb = Db::get();
    $r10sThrown = false;
    try {
        Db::init(new PDO('sqlite:' . $r10sBroken, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), 'sqlite');
        AdminIdentity::accountCount();
    } catch (Throwable $e) {
        $r10sThrown = true;
    } finally {
        Db::init($r10sPrevDb, 'sqlite');
    }
    assert_true($r10sThrown, 'R10 M1: AdminIdentity::accountCount() throws on a database error instead of reporting 0 accounts');
    $r = $r10sRun($r10sBroken, [], 'dashboard', '', [], $r10sLegacyCfg, ['r10legacy', 'R10-legacy-password']);
    assert_true($r['code'] === 503 && $r['authenticated'] === false, $r10sLabel('R10 M1: with the database down the legacy config password does not sign in (fail closed 503)', $r));

    // A legacy session was never checked again: opened while no account existed, it outlived the first DB account.
    assert_greater(AdminIdentity::accountCount(), 0, 'R10 M1: the test database holds DB admin accounts');
    $r = $r10sRun($testDbPath, ['admin_authenticated' => true, 'admin_user' => 'r10legacy', 'admin_role' => 'owner', 'admin_last_seen' => time()], 'dashboard', '', [], $r10sLegacyCfg);
    assert_true($r['authenticated'] === false && in_array($r['code'], [401, 403], true), $r10sLabel('R10 M1: a legacy session ends once a DB admin account exists', $r));

    // Bootstrap still works: a legacy session on an empty account table creates the first owner, sees its TOTP once and is signed out.
    $r10sEmpty = $r10sRoot . '/empty-accounts.sqlite';
    $pdo->exec('VACUUM INTO ' . $pdo->quote($r10sEmpty));
    $r10sEmptyDb = new PDO('sqlite:' . $r10sEmpty);
    $r10sEmptyDb->exec('DELETE FROM admin_sessions; DELETE FROM admin_devices; DELETE FROM admin_recovery_codes; DELETE FROM admin_store_permissions; DELETE FROM admin_accounts');
    $r10sEmptyDb = null;
    $r10sLegacySession = ['admin_authenticated' => true, 'admin_user' => 'r10legacy', 'admin_role' => 'owner', 'admin_last_seen' => time()];
    $r = $r10sRun($r10sEmpty, $r10sLegacySession, 'admin_users', 'create', ['username' => 'r10-first-owner', 'role' => 'owner', 'password' => 'R10-first-owner-pass', 'mfa' => '1'], $r10sLegacyCfg);
    $r10sSecret = preg_match('#<code>([A-Z2-7]{32})</code>#', (string)$r['out'], $m) ? $m[1] : '';
    assert_true($r10sSecret !== '' && $r['authenticated'] === false && str_contains((string)$r['out'], 'config.php'),
        $r10sLabel('R10 M1: the legacy session that creates the first owner is shown the TOTP once and signed out', $r));
    $r = $r10sRun($r10sEmpty, $r10sLegacySession, 'dashboard', '', [], $r10sLegacyCfg);
    assert_true($r['authenticated'] === false, $r10sLabel('R10 M1: after the first owner exists the old legacy session is refused', $r));

    // Owner changed its own password and TOTP without the current password (a hijacked session took the account over).
    $r10sOwnerId = AdminIdentity::create('r10-self-owner', 'R10-self-owner-pass', 'owner', false);
    AdminIdentity::grantStore($r10sOwnerId, 'default', ['*']);
    $r10sToken = (string)(AdminIdentity::authenticate('r10-self-owner', 'R10-self-owner-pass', '', '127.0.10.2', 'R10 security')['token'] ?? '');
    $r10sSelf = ['admin_identity_token' => $r10sToken];
    $r10sHash = static fn(): string => (string)(AdminIdentity::accountByUsername('r10-self-owner')['password_hash'] ?? '');
    $r = $r10sRun($testDbPath, $r10sSelf, 'admin_users', 'password', ['account_id' => (string)$r10sOwnerId, 'password' => 'R10-hijacked-password']);
    assert_true(!password_verify('R10-hijacked-password', $r10sHash()) && ($r['flash']['type'] ?? '') === 'err', $r10sLabel('R10 self: own password change without the current password is refused', $r));
    $r = $r10sRun($testDbPath, $r10sSelf, 'admin_users', 'mfa', ['account_id' => (string)$r10sOwnerId, 'current_password' => 'wrong-password-x']);
    assert_true((string)(AdminIdentity::accountByUsername('r10-self-owner')['totp_secret_encrypted'] ?? '') === '', $r10sLabel('R10 self: own TOTP reset with a wrong current password is refused', $r));
    $r = $r10sRun($testDbPath, $r10sSelf, 'admin_users', 'password', ['account_id' => (string)$r10sOwnerId, 'password' => 'R10-rotated-password', 'current_password' => 'R10-self-owner-pass']);
    assert_true(password_verify('R10-rotated-password', $r10sHash()), $r10sLabel('R10 self: own password change with the current password works', $r));

    // M2: Comgate created test payments unless comgate_test was switched off, so live credentials with the default
    // marked orders paid without money; readiness passed test gateways (comgate_test, Stripe sk_test_) as ready.
    $r10sCalls = [];
    $r10sTransport = static function (string $method, string $url, array $headers, ?string $body) use (&$r10sCalls): array {
        $r10sCalls[] = json_decode((string)$body, true);
        return ['status' => 201, 'body' => '{"code":0,"message":"OK","transId":"R10S-0001-TEST","redirect":"https://payments.comgate.cz/client/instructions/index?id=R10S-0001-TEST"}'];
    };
    putenv('PAY_COMGATE_TEST');
    if (!class_exists('ComgatePayment')) require_once dirname(TEST_CLI_DIR) . '/adapters/contrib/ComgatePayment.php';
    (new ComgatePayment(['comgate_merchant' => '465123', 'comgate_secret' => 'r10s-secret'], $r10sTransport))->createSession(['order_number' => 'R10S-1', 'total' => 1990, 'currency' => 'czk',
        'customer_email' => 'buyer@example.com', 'success_url' => 'https://shop.example/done', 'cancel_url' => 'https://shop.example/cancel']);
    assert_true(($r10sCalls[0]['test'] ?? null) === false, 'R10 M2: Comgate without comgate_test creates production payments (' . json_encode($r10sCalls[0]['test'] ?? null) . ')');
    $r10sExample = require dirname(TEST_CLI_DIR) . '/config.example.php';
    assert_true(($r10sExample['comgate_test'] ?? null) === false, 'R10 M2: config.example.php ships comgate_test = false');
    $r10sPayDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r10sPayDb->exec('CREATE TABLE payment_methods (store_id TEXT, code TEXT, provider TEXT, active INTEGER, requires_online INTEGER)');
    $r10sPayDb->exec("INSERT INTO payment_methods VALUES ('default', 'card', 'stripe', 1, 1), ('cz', 'comgate', 'comgate', 1, 1), ('cz', 'gopay', 'gopay', 1, 1)");
    $r10sLevels = static function (array $cfg) use ($r10sPayDb): array {
        $levels = [];
        foreach (PaymentReadiness::checks($r10sPayDb, $cfg) as [$status, $name]) $levels[$name] = $status;
        return $levels;
    };
    $r10sPayCfg = ['stripe_webhook_secret' => 'whsec_' . str_repeat('b2', 10), 'comgate_merchant' => '465123', 'comgate_secret' => 'r10s-secret',
        'gopay_goid' => '8123456789', 'gopay_client_id' => '1234567', 'gopay_client_secret' => 'r10s-gopay-secret'];
    foreach (['PAY_COMGATE_TEST', 'PAY_GOPAY_ENV'] as $r10sEnv) putenv($r10sEnv);
    assert_equals(['payment.provider.comgate' => 'warn', 'payment.provider.gopay' => 'warn', 'payment.provider.stripe' => 'warn'],
        $r10sLevels(['stripe_secret_key' => 'sk_test_' . str_repeat('A1', 12), 'comgate_test' => true, 'gopay_env' => 'sandbox'] + $r10sPayCfg),
        'R10 M2: readiness warns about test gateways (Stripe sk_test_, comgate_test, GoPay sandbox): orders are paid without money');
    assert_equals(['payment.provider.comgate' => 'pass', 'payment.provider.gopay' => 'pass', 'payment.provider.stripe' => 'pass'],
        $r10sLevels(['stripe_secret_key' => 'sk_live_' . str_repeat('A1', 12), 'gopay_env' => 'production'] + $r10sPayCfg),
        'R10 M2: live Stripe key, Comgate default and GoPay production pass readiness');
    $r10sPayDb = null;

    // M3: the per-e-mail checkout limit (3 in 30 min) counted every attempt, refused ones included, from any IP and before
    // validation: one request every 10 minutes locked any buyer's e-mail out of checkout.
    require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $r10sPrevSession = $_SESSION ?? [];
    $r10sPrevAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    try {
        $_SESSION = [];
        Cart::clear();
        $r10sAppCfg = require dirname(TEST_CLI_DIR) . '/config.php';
        $r10sCheckout = static fn(string $ip): string => (string)((function () use ($ip, $r10sAppCfg) {
            $_SERVER['REMOTE_ADDR'] = $ip;
            return checkoutExecute(['name' => 'R10 Buyer', 'email' => 'r10-victim@example.com', 'country' => 'SK', 'payment_method' => 'bank_transfer'], $r10sAppCfg);
        })()['error_code'] ?? '');
        for ($i = 0; $i < 4; $i++) $r10sCheckout('203.0.113.66');
        assert_equals('cart_empty', $r10sCheckout('198.51.100.10'), 'R10 M3: refused checkouts from another IP do not lock the buyer\'s e-mail out of checkout');
        Security::rateLimitRecord('checkout_subject_email_' . hash('sha256', 'r10-victim@example.com'), true);
        Security::rateLimitRecord('checkout_subject_email_' . hash('sha256', 'r10-victim@example.com'), true);
        Security::rateLimitRecord('checkout_subject_email_' . hash('sha256', 'r10-victim@example.com'), true);
        assert_equals('checkout_too_many_attempts', $r10sCheckout('198.51.100.10'), 'R10 M3: three placed orders for one e-mail from one IP within 30 minutes still stop the fourth');
        assert_equals('cart_empty', $r10sCheckout('198.51.100.11'), 'R10 M3: the placed-order limit of a guest e-mail is counted per IP');
    } finally {
        $_SESSION = $r10sPrevSession;
        if ($r10sPrevAddr === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $r10sPrevAddr;
        http_response_code(200);
    }

    // A12: the audit log took its actor from request headers (PHP_AUTH_USER, X-Webhook-Source): anyone could forge it.
    $r10sPrevSession = $_SESSION ?? [];
    $r10sPrevServer = $_SERVER;
    try {
        $r10sActor = static fn(): string => (string)(new ReflectionMethod(AuditLog::class, 'requestActor'))->invoke(null);
        $_SESSION = [];
        $_SERVER['PHP_AUTH_USER'] = 'r10-forged-owner';
        $_SERVER['HTTP_X_WEBHOOK_SOURCE'] = 'r10-forged-webhook';
        assert_equals('system', $r10sActor(), 'R10 A12: forged PHP_AUTH_USER / X-Webhook-Source headers do not become the audit actor');
        $_SESSION = ['admin_user' => 'r10-unverified'];
        assert_equals('system', $r10sActor(), 'R10 A12: an admin_user without an authenticated admin session is not the audit actor');
        $_SESSION = ['admin_authenticated' => true, 'admin_user' => 'r10-verified'];
        assert_equals('admin:r10-verified', $r10sActor(), 'R10 A12: the verified admin session is the audit actor');
    } catch (ReflectionException $e) {
        assert_true(false, 'R10 A12: AuditLog has a request actor derived from the verified session (' . $e->getMessage() . ')');
    } finally {
        $_SESSION = $r10sPrevSession;
        $_SERVER = $r10sPrevServer;
    }

    // A15: cli/hash-password.php took the password from argv (process list, shell history).
    $r10sHashCli = static function (array $args, string $stdin): array {
        $env = getenv();
        unset($env['BBE_ADMIN_PASSWORD']);
        $process = proc_open(array_merge([PHP_BINARY, dirname(TEST_CLI_DIR) . '/cli/hash-password.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = trim((string)stream_get_contents($pipes[1]));
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    };
    $r = $r10sHashCli(['R10-argv-password'], '');
    assert_true($r['code'] !== 0 && !password_verify('R10-argv-password', $r['out']), 'R10 A15: hash-password refuses a password in the command arguments (' . trim($r['err'] . $r['out']) . ')');
    $r = $r10sHashCli([], "R10-stdin-password\n");
    assert_true($r['code'] === 0 && password_verify('R10-stdin-password', $r['out']), 'R10 A15: hash-password reads the password from stdin (' . trim($r['err'] . $r['out']) . ')');

    // A15: admin-user create inserted the account, then granted the store and enrolled TOTP as separate writes: an
    // invalid --store left an owner account without any store grant behind.
    $r10sAtomicError = '';
    try {
        AdminIdentity::createWithStore('r10-atomic-owner', 'R10-atomic-owner-pass', 'owner', true, 'Not A Store!', true);
    } catch (Throwable $e) {
        $r10sAtomicError = get_class($e) . ': ' . $e->getMessage();
    }
    assert_true(str_contains($r10sAtomicError, 'Invalid store ID') && AdminIdentity::accountByUsername('r10-atomic-owner') === null,
        "R10 A15: a failing store grant leaves no half-created admin account ({$r10sAtomicError})");
    putenv('BBE_ADMIN_IDENTITY_KEY=' . $r10sKey);
    try {
        $r10sCreated = AdminIdentity::createWithStore('r10-atomic-owner', 'R10-atomic-owner-pass', 'owner', true, 'default', true);
    } finally {
        putenv('BBE_ADMIN_IDENTITY_KEY');
    }
    $r10sAtomicRow = AdminIdentity::accountByUsername('r10-atomic-owner') ?? [];
    assert_true((int)($r10sAtomicRow['id'] ?? 0) === $r10sCreated['id'] && count($r10sCreated['enrollment']['recovery_codes'] ?? []) === 10
        && (string)($r10sAtomicRow['totp_secret_encrypted'] ?? '') !== '' && !Db::get()->inTransaction(), 'R10 A15: createWithStore commits account, store grant and TOTP enrollment together');
    assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/admin-user.php'), 'AdminIdentity::createWithStore('), 'R10 A15: cli/admin-user.php create uses the atomic createWithStore()');

    // I12: the JSON API limited each action separately (120/min), so one address could send 120 × every action.
    $r10sCfgProp = new ReflectionProperty(Security::class, 'cfg');
    $r10sPrevCfg = $r10sCfgProp->getValue();
    $r10sPrevAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    try {
        $r10sCfgProp->setValue(null, ['api_ip_limit' => 5] + (require dirname(TEST_CLI_DIR) . '/config.php'));
        Security::setRateDbForTesting(new PDO('sqlite::memory:'));
        $_SERVER['REMOTE_ADDR'] = '203.0.113.12';
        $r10sApiAllowed = static fn(string $action): bool => method_exists(Security::class, 'apiAllowed') ? Security::apiAllowed($action) : Security::rateLimit('api_' . $action, 120);
        $r10sApiResults = array_map($r10sApiAllowed, ['products', 'cart', 'search', 'filters', 'page', 'categories']);
        assert_equals([true, true, true, true, true, false], $r10sApiResults, 'R10 I12: the JSON API caps all actions of one IP together (api_ip_limit)');
        assert_true($r10sApiAllowed('webhook_payment'), 'R10 I12: signed provider webhooks are not refused by the per-IP total');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.13';
        assert_true($r10sApiAllowed('products'), 'R10 I12: another IP has its own total');
        $r10sSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Api.php');
        assert_true(str_contains($r10sSource, 'Security::apiAllowed($action)'), 'R10 I12: the API entrypoint throttles through Security::apiAllowed()');
    } finally {
        $r10sCfgProp->setValue(null, $r10sPrevCfg);
        if ($r10sPrevAddr === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $r10sPrevAddr;
        http_response_code(200);
    }
} finally {
    $r10sTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r10sRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($r10sTree as $r10sPath) $r10sPath->isDir() ? @rmdir($r10sPath->getPathname()) : @unlink($r10sPath->getPathname());
    @rmdir($r10sRoot);
}
