<?php

// A07.4 / suggestion: admin users, passwords, 2FA and store grants are managed in the web admin (no CLI), with permission and audit.
echo "\n--- Audit A07.4: web admin user management ---\n";
foreach ($GLOBALS as $a074Value) if ($a074Value instanceof PDOStatement) $a074Value->closeCursor();
$a074Key = base64_encode(str_repeat('K', 32));
putenv('BBE_ADMIN_IDENTITY_KEY=' . $a074Key);
$a074Accounts = [];
foreach ([['a074-owner', 'owner'], ['a074-owner2', 'owner'], ['a074-delegate', 'manager'], ['a074-fulfil', 'fulfillment']] as [$a074Name, $a074Role]) {
    $a074Accounts[$a074Name] = AdminIdentity::create($a074Name, 'A074-password-' . $a074Role, $a074Role, false);
    AdminIdentity::grantStore($a074Accounts[$a074Name], 'default', ['*']);
}
$a074Token = static fn(string $name, string $password): string => (string)(AdminIdentity::authenticate($name, $password, '', '127.0.7.40', 'A074 ' . $name)['token'] ?? '');
$a074Tokens = [
    'owner' => $a074Token('a074-owner', 'A074-password-owner'),
    'delegate' => $a074Token('a074-delegate', 'A074-password-manager'),
    'fulfil' => $a074Token('a074-fulfil', 'A074-password-fulfillment'),
];

$a074Root = test_fixture_path('admin-users');
mkdir($a074Root);
mkdir($a074Root . '/sessions');
file_put_contents($a074Root . '/admin.php', <<<'PHP'
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
$post = json_decode($postJson, true);
if (isset($post['__roles'])) Security::setRolesForTesting($post['__roles']);
unset($post['__roles']);
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
session_save_path($dir . '/sessions');
session_id('a074sess' . $who);
session_start();
$_SESSION['csrf_token'] = 'a074';
$_SESSION['admin_identity_token'] = $token;
$_SERVER['REQUEST_METHOD'] = $action === '' ? 'GET' : 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.7.41';
$_SERVER['HTTP_USER_AGENT'] = 'A074 ' . $who;
$_GET = ['p' => $page, 'action' => $action];
$_POST = $post + ['csrf_token' => 'a074'];
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nA074RESULT" . json_encode(['code' => http_response_code(), 'flash' => $_SESSION['flash'] ?? null, 'out' => $out]);
});
require $root . '/src/Entrypoints/Admin.php';
PHP);
$a074Run = static function(string $who, string $page, string $action = '', array $post = []) use ($a074Root, $testDbPath, $a074Key, &$a074Tokens): array {
    $process = proc_open([PHP_BINARY, $a074Root . '/admin.php', dirname(TEST_CLI_DIR), $testDbPath, $a074Root, $a074Key, $who, $a074Tokens[$who], $page, $action, json_encode($post)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($raw, 'A074RESULT');
    $result = $at === false ? null : json_decode(substr($raw, $at + 10), true);
    return is_array($result) ? $result + ['raw' => ''] : ['code' => null, 'flash' => null, 'out' => '', 'raw' => substr($raw, 0, 400)];
};
$a074Read = static function(string $sql, array $args = []) use ($testDbPath) {
    $read = new PDO('sqlite:' . $testDbPath);
    $stmt = $read->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};
$a074Account = static fn(string $name): ?array => $a074Read('SELECT * FROM admin_accounts WHERE username = ?', [$name]);
$a074Label = static fn(string $label, array $r): string => $label . ' (code=' . var_export($r['code'], true) . ' flash=' . json_encode($r['flash']) . ($r['raw'] !== '' ? ' raw=' . $r['raw'] : '') . ')';
$a074Denied = static fn(array $r): bool => $r['code'] === 403 && str_contains($r['out'], 'insufficient permissions');
$a074Secret = static fn(array $r): string => preg_match('#<code>([A-Z2-7]{32})</code>#', $r['out'], $m) ? $m[1] : '';
$a074DelegateRoles = ['__roles' => ['roles' => ['manager' => ['permissions' => ['admins.manage', 'products.view', 'orders.view', 'reports.view']]]]];

try {
    $r = $a074Run('owner', 'admin_users');
    assert_true($r['code'] !== 403 && str_contains($r['out'], 'a074-fulfil') && str_contains($r['out'], 'action=create'), $a074Label('A07.4 owner: the web admin lists admin accounts with a create form', $r));

    $r = $a074Run('owner', 'admin_users', 'create', ['username' => 'a074-staff', 'role' => 'fulfillment', 'password' => 'A074-staff-password', 'mfa' => '1']);
    $a074Staff = $a074Account('a074-staff');
    assert_true($a074Staff !== null && $a074Staff['role'] === 'fulfillment' && (int)$a074Staff['mfa_required'] === 1 && (string)$a074Staff['totp_secret_encrypted'] !== '',
        $a074Label('A07.4 create: owner creates a fulfillment admin with enrolled TOTP', $r));
    $once = $a074Run('owner', 'admin_users');
    $again = $a074Run('owner', 'admin_users');
    $a074StaffSecret = $a074Secret($once);
    $a074StaffLogin = $a074StaffSecret !== '' ? AdminIdentity::authenticate('a074-staff', 'A074-staff-password', Totp::code($a074StaffSecret), '127.0.7.42', 'A074 staff') : null;
    assert_true($a074StaffLogin !== null, $a074Label('A07.4 create: the TOTP secret shown in the web admin signs the new admin in', $once));
    assert_true($a074Secret($again) === '', 'A07.4 create: the enrollment secret is shown only once');
    assert_true($a074Read("SELECT permissions FROM admin_store_permissions WHERE account_id = ? AND store_id = 'default'", [(int)($a074Staff['id'] ?? 0)]) !== null,
        'A07.4 create: the new admin is granted the active store');

    $r = $a074Run('owner', 'admin_users', 'mfa', ['account_id' => (string)($a074Staff['id'] ?? 0)]);
    $reset = $a074Run('owner', 'admin_users');
    $a074NewSecret = $a074Secret($reset);
    assert_true($a074NewSecret !== '' && $a074NewSecret !== $a074StaffSecret && AdminIdentity::session((string)($a074StaffLogin['token'] ?? '')) === null,
        $a074Label('A07.4 2FA reset: a new seed is issued and the admin\'s existing sessions are revoked', $r));

    $r = $a074Run('owner', 'admin_users', 'password', ['account_id' => (string)($a074Staff['id'] ?? 0), 'password' => 'A074-rotated-password']);
    assert_true(password_verify('A074-rotated-password', (string)($a074Account('a074-staff')['password_hash'] ?? '')), $a074Label('A07.4 password: owner sets a new password', $r));
    $r = $a074Run('owner', 'admin_users', 'password', ['account_id' => (string)($a074Staff['id'] ?? 0), 'password' => 'short']);
    assert_true(password_verify('A074-rotated-password', (string)($a074Account('a074-staff')['password_hash'] ?? '')) && ($r['flash']['type'] ?? '') === 'err',
        $a074Label('A07.4 password: too short passwords are refused', $r));

    $r = $a074Run('owner', 'admin_users', 'grant', ['account_id' => (string)($a074Staff['id'] ?? 0), 'permissions' => 'orders.view, orders.ship']);
    $grant = $a074Read("SELECT permissions FROM admin_store_permissions WHERE account_id = ? AND store_id = 'default'", [(int)($a074Staff['id'] ?? 0)]);
    assert_true(json_decode((string)($grant['permissions'] ?? ''), true) === ['orders.view', 'orders.ship'], $a074Label('A07.4 grants: owner edits store permissions for the active store', $r));

    $r = $a074Run('owner', 'admin_users', 'status', ['account_id' => (string)$a074Accounts['a074-owner'], 'status' => 'disabled']);
    assert_true(($a074Account('a074-owner')['status'] ?? '') === 'active', $a074Label('A07.4 status: an admin cannot disable its own account', $r));
    $r = $a074Run('owner', 'admin_users', 'status', ['account_id' => (string)($a074Staff['id'] ?? 0), 'status' => 'disabled']);
    assert_true(($a074Account('a074-staff')['status'] ?? '') === 'disabled', $a074Label('A07.4 status: owner disables another admin', $r));

    $r = $a074Run('owner', 'admin_users', 'create', ['username' => 'a074-csrf', 'role' => 'viewer', 'password' => 'A074-csrf-password', 'csrf_token' => 'forged']);
    assert_true($a074Account('a074-csrf') === null, $a074Label('A07.4 CSRF: a forged create request is refused', $r));

    // Without admins.manage nothing here is reachable.
    $r = $a074Run('fulfil', 'admin_users');
    assert_true($a074Denied($r) && !str_contains($r['out'], 'a074-owner'), $a074Label('A07.4 permission: fulfillment cannot open admin users', $r));
    $r = $a074Run('fulfil', 'admin_users', 'create', ['username' => 'a074-sneaky', 'role' => 'owner', 'password' => 'A074-sneaky-password']);
    assert_true($a074Denied($r) && $a074Account('a074-sneaky') === null, $a074Label('A07.4 permission: fulfillment cannot create admins', $r));

    // A delegated admins.manage (custom role) may manage staff, never owners.
    $r = $a074Run('delegate', 'admin_users', 'create', ['username' => 'a074-escalate', 'role' => 'owner', 'password' => 'A074-escalate-pass'] + $a074DelegateRoles);
    assert_true($a074Account('a074-escalate') === null && ($r['flash']['type'] ?? '') === 'err', $a074Label('A07.4 delegate: cannot create an owner', $r));
    $r = $a074Run('delegate', 'admin_users', 'password', ['account_id' => (string)$a074Accounts['a074-owner2'], 'password' => 'A074-takeover-password'] + $a074DelegateRoles);
    assert_true(!password_verify('A074-takeover-password', (string)($a074Account('a074-owner2')['password_hash'] ?? '')), $a074Label('A07.4 delegate: cannot reset an owner password', $r));
    $r = $a074Run('delegate', 'admin_users', 'mfa', ['account_id' => (string)$a074Accounts['a074-owner2']] + $a074DelegateRoles);
    assert_true((string)($a074Account('a074-owner2')['totp_secret_encrypted'] ?? '') === '', $a074Label('A07.4 delegate: cannot reset an owner 2FA', $r));
    $r = $a074Run('delegate', 'admin_users', 'create', ['username' => 'a074-viewer', 'role' => 'viewer', 'password' => 'A074-viewer-password'] + $a074DelegateRoles);
    assert_true(($a074Account('a074-viewer')['role'] ?? '') === 'viewer', $a074Label('A07.4 delegate: can create a staff admin', $r));

    $r = $a074Run('owner', 'admin_users', 'status', ['account_id' => (string)$a074Accounts['a074-owner2'], 'status' => 'disabled']);
    assert_true(($a074Account('a074-owner2')['status'] ?? '') === 'disabled', $a074Label('A07.4 status: an owner may disable another owner while one stays active', $r));

    // Child processes run under the CLI SAPI, so the actor column reads cli:*; the web SAPI records admin:<username>.
    $audit = $a074Read("SELECT COUNT(*) AS n FROM audit_log WHERE entity = 'admin_account' AND entity_id = ?
        AND note IN ('Admin account created', 'TOTP enrolled and recovery codes rotated', 'Admin password rotated and sessions revoked', 'Store permissions changed', 'Admin lifecycle changed')", [(int)($a074Staff['id'] ?? 0)]);
    assert_true((int)($audit['n'] ?? 0) >= 6, 'A07.4 audit: every web change to an admin account is audited (' . json_encode($audit) . ')');
} finally {
    putenv('BBE_ADMIN_IDENTITY_KEY');
    $a074Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a074Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a074Tree as $a074Path) $a074Path->isDir() ? rmdir($a074Path->getPathname()) : unlink($a074Path->getPathname());
    rmdir($a074Root);
}
