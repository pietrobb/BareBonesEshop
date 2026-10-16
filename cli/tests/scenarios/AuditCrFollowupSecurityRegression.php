<?php

// CR follow-up (security/auth): admin login timing, delegated admins.manage escalation,
// distributed customer password guessing, mail capture location and forged CSP alarms.
echo "\n--- CR follow-up: security and auth findings ---\n";
foreach ($GLOBALS as $crsValue) if ($crsValue instanceof PDOStatement) $crsValue->closeCursor();

// 1. Unknown admin usernames ran password_hash() per request (the static cache dies with each
// PHP-FPM request): two bcrypt rounds instead of one revealed whether an account exists.
$crsDummy = new ReflectionMethod(AdminIdentity::class, 'dummyPasswordHash');
$crsDummyCache = new ReflectionProperty(AdminIdentity::class, 'dummyPasswordHash');
$crsDummyCache->setValue(null, null);
$crsFirst = (string)$crsDummy->invoke(null);
$crsDummyCache->setValue(null, null);
$crsSecond = (string)$crsDummy->invoke(null);
assert_true($crsFirst === $crsSecond && in_array($crsFirst, (new ReflectionClassConstant(AdminIdentity::class, 'DUMMY_PASSWORD_HASHES'))->getValue(), true),
    'CR follow-up admin login: the unknown-user hash is a precomputed constant (no password_hash() per request)');
assert_true(!password_needs_rehash($crsFirst, PASSWORD_DEFAULT) && !password_verify('', $crsFirst),
    'CR follow-up admin login: the precomputed hash matches PASSWORD_DEFAULT cost and accepts no password');
$crsCustomerDummy = (string)(new ReflectionClassConstant(Customers::class, 'DUMMY_PASSWORD_HASH'))->getValue();
assert_true(!password_needs_rehash($crsCustomerDummy, PASSWORD_BCRYPT, ['cost' => (int)(new ReflectionClassConstant(Customers::class, 'BCRYPT_COST'))->getValue()])
    && !(new ReflectionClass(Customers::class))->hasProperty('dummyPasswordHash'),
    'CR follow-up customer login: unknown e-mails verify against a precomputed hash at the customer bcrypt cost (no password_hash() per request)');

$crsRoot = test_fixture_path('cr-followup-security');
mkdir($crsRoot, 0700, true);
mkdir($crsRoot . '/sessions');
$crsRun = static function (array $args, string $marker): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($raw, $marker);
    $result = $at === false ? null : json_decode(substr($raw, $at + strlen($marker)), true);
    return is_array($result) ? $result + ['raw' => ''] : ['code' => null, 'flash' => null, 'out' => '', 'customer_id' => 0, 'raw' => substr($raw, 0, 400)];
};
$crsRead = static function (string $sql, array $args = []) use ($testDbPath) {
    $read = new PDO('sqlite:' . $testDbPath);
    $stmt = $read->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};

$crsKey = base64_encode(str_repeat('S', 32));
putenv('BBE_ADMIN_IDENTITY_KEY=' . $crsKey);
try {
    // 2. A delegated admins.manage (custom role) could create any non-owner role and take over
    // peers or stronger staff (password, MFA with the new secret, sessions), and widen its own grants.
    $crsIds = [];
    foreach ([['crs-owner', 'owner'], ['crs-mgr', 'manager'], ['crs-fulfil', 'fulfillment'], ['crs-viewer', 'viewer'], ['crs-peer', 'manager']] as [$crsName, $crsRole]) {
        $crsIds[$crsName] = AdminIdentity::create($crsName, 'Crs-password-' . $crsName, $crsRole, false);
        AdminIdentity::grantStore($crsIds[$crsName], 'default', ['*']);
    }
    // The peer also works in a store the delegate has no access to.
    AdminIdentity::grantStore($crsIds['crs-peer'], 'crs-b2', ['*']);
    $crsLogin = static fn(string $name): string => (string)(AdminIdentity::authenticate($name, 'Crs-password-' . $name, '', '127.0.9.40', 'CRS ' . $name)['token'] ?? '');
    $crsTokens = ['owner' => $crsLogin('crs-owner'), 'mgr' => $crsLogin('crs-mgr')];
    $crsFulfilSession = $crsLogin('crs-fulfil');

    file_put_contents($crsRoot . '/admin.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $key, $who, $token, $page, $action, $postJson] = $argv;
require $root . '/src/autoload.php';
putenv('BBE_ADMIN_IDENTITY_KEY=' . $key);
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
JobQueue::setDir($dir . '/jobs');
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
Security::setRolesForTesting(['roles' => ['manager' => ['permissions' => ['admins.manage', 'products.view', 'orders.view', 'reports.view']]]]);
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
session_save_path($dir . '/sessions');
session_id('crssess' . $who);
session_start();
$_SESSION['csrf_token'] = 'crs';
$_SESSION['admin_identity_token'] = $token;
$_SERVER['REQUEST_METHOD'] = $action === '' ? 'GET' : 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.9.41';
$_SERVER['HTTP_USER_AGENT'] = 'CRS ' . $who;
$_GET = ['p' => $page, 'action' => $action];
$_POST = json_decode($postJson, true) + ['csrf_token' => 'crs'];
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nCRSRESULT" . json_encode(['code' => http_response_code(), 'flash' => $_SESSION['flash'] ?? null, 'out' => $out]);
});
require $root . '/src/Entrypoints/Admin.php';
PHP);
    $crsAdmin = static fn(string $who, string $action = '', array $post = []): array => $crsRun(
        [$crsRoot . '/admin.php', dirname(TEST_CLI_DIR), $testDbPath, $crsRoot, $crsKey, $who, $crsTokens[$who], 'admin_users', $action, json_encode($post)], 'CRSRESULT');
    $crsAccount = static fn(string $name): ?array => $crsRead('SELECT * FROM admin_accounts WHERE username = ?', [$name]);
    $crsLabel = static fn(string $label, array $r): string => $label . ' (code=' . var_export($r['code'], true) . ' flash=' . json_encode($r['flash']) . ($r['raw'] !== '' ? ' raw=' . $r['raw'] : '') . ')';
    $crsSecret = static fn(array $r): string => preg_match('#<code>([A-Z2-7]{32})</code>#', $r['out'], $m) ? $m[1] : '';
    $crsHash = static fn(string $name): string => (string)($crsAccount($name)['password_hash'] ?? '');

    $r = $crsAdmin('mgr');
    assert_true(str_contains($r['out'], '<option value="viewer"') && !str_contains($r['out'], '<option value="editor"') && !str_contains($r['out'], '<option value="fulfillment"'),
        $crsLabel('CR follow-up delegate: the create form offers only roles within the delegate\'s permissions', $r));
    foreach (['editor', 'fulfillment', 'manager'] as $crsRole) {
        $crsWanted = $crsRole === 'manager' ? 'crs-new-manager' : 'crs-new-' . $crsRole;
        $r = $crsAdmin('mgr', 'create', ['username' => $crsWanted, 'role' => $crsRole, 'password' => 'Crs-new-password']);
        if ($crsRole === 'manager') {
            assert_true(($crsAccount($crsWanted)['role'] ?? '') === 'manager', $crsLabel('CR follow-up delegate: can create an admin with exactly its own role', $r));
        } else {
            assert_true($crsAccount($crsWanted) === null && ($r['flash']['type'] ?? '') === 'err', $crsLabel("CR follow-up delegate: cannot create a stronger {$crsRole} admin", $r));
        }
    }
    $r = $crsAdmin('mgr', 'create', ['username' => 'crs-new-viewer', 'role' => 'viewer', 'password' => 'Crs-new-password']);
    assert_true(($crsAccount('crs-new-viewer')['role'] ?? '') === 'viewer', $crsLabel('CR follow-up delegate: can still create a weaker viewer admin', $r));

    $crsBefore = $crsHash('crs-fulfil');
    $r = $crsAdmin('mgr', 'password', ['account_id' => (string)$crsIds['crs-fulfil'], 'password' => 'Crs-takeover-password']);
    assert_true($crsHash('crs-fulfil') === $crsBefore && ($r['flash']['type'] ?? '') === 'err', $crsLabel('CR follow-up delegate: cannot reset the password of a stronger admin', $r));
    $r = $crsAdmin('mgr', 'mfa', ['account_id' => (string)$crsIds['crs-fulfil']]);
    $crsAfter = $crsAdmin('mgr');
    assert_true((string)($crsAccount('crs-fulfil')['totp_secret_encrypted'] ?? '') === '' && $crsSecret($crsAfter) === '',
        $crsLabel('CR follow-up delegate: cannot reset (and receive) the TOTP secret of a stronger admin', $r));
    $r = $crsAdmin('mgr', 'revoke_sessions', ['account_id' => (string)$crsIds['crs-fulfil']]);
    assert_true(AdminIdentity::session($crsFulfilSession) !== null, $crsLabel('CR follow-up delegate: cannot revoke the sessions of a stronger admin', $r));
    $r = $crsAdmin('mgr', 'status', ['account_id' => (string)$crsIds['crs-fulfil'], 'status' => 'disabled']);
    assert_true(($crsAccount('crs-fulfil')['status'] ?? '') === 'active', $crsLabel('CR follow-up delegate: cannot disable a stronger admin', $r));
    $r = $crsAdmin('mgr', 'grant', ['account_id' => (string)$crsIds['crs-fulfil'], 'permissions' => 'orders.view']);
    assert_true(json_decode((string)($crsRead("SELECT permissions FROM admin_store_permissions WHERE account_id = ? AND store_id = 'default'", [$crsIds['crs-fulfil']])['permissions'] ?? ''), true) === ['*'],
        $crsLabel('CR follow-up delegate: cannot edit the grants of a stronger admin', $r));

    $crsBefore = $crsHash('crs-peer');
    $r = $crsAdmin('mgr', 'password', ['account_id' => (string)$crsIds['crs-peer'], 'password' => 'Crs-takeover-password']);
    assert_true($crsHash('crs-peer') === $crsBefore, $crsLabel('CR follow-up delegate: cannot take over a same-role peer granted a store the delegate cannot access', $r));

    $r = $crsAdmin('mgr', 'password', ['account_id' => (string)$crsIds['crs-viewer'], 'password' => 'Crs-rotated-viewer']);
    assert_true(password_verify('Crs-rotated-viewer', $crsHash('crs-viewer')), $crsLabel('CR follow-up delegate: still resets the password of a weaker admin', $r));
    $r = $crsAdmin('mgr', 'mfa', ['account_id' => (string)$crsIds['crs-viewer']]);
    assert_true($crsSecret($crsAdmin('mgr')) !== '', $crsLabel('CR follow-up delegate: still resets the TOTP of a weaker admin', $r));

    $r = $crsAdmin('owner', 'password', ['account_id' => (string)$crsIds['crs-fulfil'], 'password' => 'Crs-owner-rotated']);
    assert_true(password_verify('Crs-owner-rotated', $crsHash('crs-fulfil')), $crsLabel('CR follow-up owner: owners still manage every non-owner admin', $r));
    $r = $crsAdmin('owner', 'password', ['account_id' => (string)$crsIds['crs-peer'], 'password' => 'Crs-owner-rotated']);
    assert_true(password_verify('Crs-owner-rotated', $crsHash('crs-peer')), $crsLabel('CR follow-up owner: owners manage admins in every store', $r));

    // Store grants narrow the role; a delegate must not lift the owner's restriction on itself.
    AdminIdentity::grantStore($crsIds['crs-mgr'], 'default', ['admins.manage', 'reports.view']);
    $r = $crsAdmin('mgr', 'grant', ['account_id' => (string)$crsIds['crs-mgr'], 'permissions' => '*']);
    assert_true(json_decode((string)($crsRead("SELECT permissions FROM admin_store_permissions WHERE account_id = ? AND store_id = 'default'", [$crsIds['crs-mgr']])['permissions'] ?? ''), true) === ['admins.manage', 'reports.view'],
        $crsLabel('CR follow-up delegate: cannot widen its own store grants', $r));
    $r = $crsAdmin('mgr', 'create', ['username' => 'crs-restricted-viewer', 'role' => 'viewer', 'password' => 'Crs-new-password']);
    assert_true($crsAccount('crs-restricted-viewer') === null, $crsLabel('CR follow-up delegate: store grants limit which roles it may create', $r));
    $r = $crsAdmin('owner', 'grant', ['account_id' => (string)$crsIds['crs-owner'], 'permissions' => '*']);
    assert_true(($r['flash']['type'] ?? '') === 'ok', $crsLabel('CR follow-up owner: owners keep editing their own grants', $r));

    // 3. The customer login limit was per account AND client IP only: rotating addresses allowed
    // unlimited guesses against one account. A high cross-IP ceiling per account now applies too.
    $crsEmail = 'crs-login@example.com';
    $pdo->prepare("INSERT INTO customers (email, password_hash, name, locale, status, store_id) VALUES (?, ?, 'CRS', 'en', 'active', 'default')")
        ->execute([$crsEmail, password_hash('crs-customer-pass', PASSWORD_BCRYPT, ['cost' => 4])]);
    file_put_contents($crsRoot . '/account.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $email, $password, $ip] = $argv;
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
session_start();
$_SESSION = ['csrf_token' => 'crs'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['p' => 'login'];
$_POST = ['action' => 'login', 'email' => $email, 'password' => $password, 'csrf_token' => 'crs'];
ob_start();
register_shutdown_function(static function(): void {
    while (ob_get_level() > 0) ob_end_clean();
    echo "\nCRSLOGIN" . json_encode(['customer_id' => (int)($_SESSION['customer_id'] ?? 0)]);
});
require $root . '/account.php';
PHP);
    $crsCustomerLogin = static fn(string $ip): array => $crsRun([$crsRoot . '/account.php', dirname(TEST_CLI_DIR), $testDbPath, $crsRoot, $crsEmail, 'crs-customer-pass', $ip], 'CRSLOGIN');
    $r = $crsCustomerLogin('198.51.100.10');
    assert_true(($r['customer_id'] ?? 0) > 0, 'CR follow-up customer login: the right password signs in under the global ceiling (' . json_encode($r) . ')');
    $crsRate = new PDO('sqlite:' . $crsRoot . '/rate.sqlite');
    $crsGlobalKey = hash('sha256', 'account_login_account_global_' . hash('sha256', $crsEmail) . '|global');
    $crsSeed = $crsRate->prepare('INSERT INTO hits (key, ts) VALUES (?, ?)');
    for ($crsI = 0; $crsI < 100; $crsI++) $crsSeed->execute([$crsGlobalKey, time()]);
    $r = $crsCustomerLogin('198.51.100.11');
    assert_equals(0, (int)($r['customer_id'] ?? -1), 'CR follow-up customer login: after 100 attempts from many IPs within an hour, even a fresh IP is throttled');
    $crsRate = null;

    // 4. LogMail wrote whole emails (reset/verification/download tokens) to logs/ inside the app root.
    $crsLogFile = new ReflectionProperty(LogMail::class, 'logFile');
    $crsAppRoot = rtrim(str_replace('\\', '/', dirname(TEST_CLI_DIR)), '/');
    $crsMailPath = (string)$crsLogFile->getValue(new LogMail(['private_runtime_dir' => $crsRoot . '/private']));
    assert_equals($crsRoot . '/private/logs/emails.log', $crsMailPath, 'CR follow-up LogMail: captures go to the private runtime directory');
    assert_equals($crsRoot . '/explicit/mail.log', (string)$crsLogFile->getValue(new LogMail(['private_runtime_dir' => $crsRoot . '/private', 'log_file' => $crsRoot . '/explicit/mail.log'])),
        'CR follow-up LogMail: an explicit log_file still overrides the location');
    $crsConfigured = (string)$crsLogFile->getValue(new LogMail(require TEST_CLI_DIR . '/../config.php'));
    assert_true(!str_starts_with(str_replace('\\', '/', $crsConfigured), $crsAppRoot . '/'), 'CR follow-up LogMail: the configured default is outside the application root (' . $crsConfigured . ')');

    // 5. Anyone can POST a CSP report: one forged form-action used to raise the critical alarm.
    $pdo->exec('DELETE FROM csp_violations');
    $crsNow = time();
    $crsForged = (string)json_encode(['csp-report' => ['document-uri' => 'https://shop.example/pokladna', 'effective-directive' => 'form-action', 'blocked-uri' => 'https://evil.example/x']]);
    for ($crsI = 0; $crsI < 5; $crsI++) CspReports::ingest($crsForged, $pdo, $crsNow - $crsI, '203.0.113.7');
    $crsMetricsConfig = require TEST_CLI_DIR . '/../config.php';
    $crsMetricsConfig['jobs_dir'] = sys_get_temp_dir();
    $crsMetricsConfig['private_cache_dir'] = sys_get_temp_dir();
    $crsMetricsConfig['monitoring'] = [
        'backup_rpo_seconds' => 86400, 'queue_pending_warning' => 999999, 'failed_jobs_warning' => 999999,
        'failed_mail_warning' => 999999, 'payment_incidents_critical' => 999999, 'webhook_incidents_warning' => 999999, 'csp_violations_warning' => 999999,
    ];
    $crsSnapshot = static fn(): array => OperationalMetrics::snapshot($crsMetricsConfig, dirname(TEST_CLI_DIR), $pdo, 'default', [['meta' => ['timestamp' => gmdate('c', $crsNow - 60)]]], $crsNow);
    $crsAlarm = static fn(array $s): bool => in_array('csp_form_action_violation', array_column($s['alerts'], 'code'), true);
    $crsOne = $crsSnapshot();
    assert_true($crsOne['metrics']['csp_form_action_violations'] === 1 && !$crsAlarm($crsOne), 'CR follow-up CSP: repeated form-action reports from one source count once and do not alarm');
    $crsPrevAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
    CspReports::ingest($crsForged, $pdo, $crsNow);
    if ($crsPrevAddr === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $crsPrevAddr;
    $crsTwo = $crsSnapshot();
    assert_true($crsTwo['metrics']['csp_form_action_violations'] === 2 && $crsAlarm($crsTwo), 'CR follow-up CSP: form-action reports from two clients (request IP by default) raise the critical alarm');
    assert_true(!str_contains((string)json_encode($pdo->query('SELECT reporter_hash FROM csp_violations')->fetchAll(PDO::FETCH_COLUMN)), '203.0.113'), 'CR follow-up CSP: reporter IPs are stored only hashed');
    $pdo->exec('DELETE FROM csp_violations');
} finally {
    putenv('BBE_ADMIN_IDENTITY_KEY');
    $crsTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($crsRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($crsTree as $crsPath) $crsPath->isDir() ? rmdir($crsPath->getPathname()) : unlink($crsPath->getPathname());
    rmdir($crsRoot);
}
