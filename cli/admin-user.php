<?php
require_once __DIR__ . '/cli-only.php';
require_once __DIR__ . '/../src/autoload.php';

$command = strtolower((string)($argv[1] ?? ''));
$options = getopt('', ['username:', 'role:', 'store:', 'permissions:', 'status:', 'device:', 'no-mfa']);

try {
    if ($command === 'keygen') {
        fwrite(STDOUT, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) . PHP_EOL);
        fwrite(STDERR, "Store this value as admin_identity_key or BBE_ADMIN_IDENTITY_KEY; it cannot be recovered from the database.\n");
        exit(0);
    }

    if (!AdminIdentity::available()) throw new RuntimeException('Run php cli/migrate.php first');

    if ($command === 'create') {
        $username = (string)($options['username'] ?? '');
        $role = strtolower((string)($options['role'] ?? 'viewer'));
        $store = strtolower((string)($options['store'] ?? 'default'));
        $password = (string)(getenv('BBE_ADMIN_PASSWORD') ?: '');
        if ($password === '') throw new RuntimeException('Set BBE_ADMIN_PASSWORD in the process environment; passwords are not accepted in command arguments');
        $mfa = !array_key_exists('no-mfa', $options);
        $id = AdminIdentity::create($username, $password, $role, $mfa);
        AdminIdentity::grantStore($id, $store, ['*']);
        $result = ['id' => $id, 'username' => strtolower($username), 'role' => $role, 'store_id' => $store, 'mfa_required' => $mfa];
        if ($mfa) {
            $enrollment = AdminIdentity::enrollTotp($id);
            $result += $enrollment;
            $result['warning'] = 'Store the recovery codes now; only hashes are retained.';
        }
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    if ($command === 'list') {
        fwrite(STDOUT, json_encode(AdminIdentity::accounts(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    $username = (string)($options['username'] ?? '');
    $account = AdminIdentity::accountByUsername($username);
    if (!$account) throw new RuntimeException('Admin account not found');
    $accountId = (int)$account['id'];

    if ($command === 'mfa-enroll') {
        $result = AdminIdentity::enrollTotp($accountId);
        $result['warning'] = 'Store the recovery codes now; only hashes are retained.';
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    if ($command === 'status') {
        $status = strtolower((string)($options['status'] ?? ''));
        AdminIdentity::setStatus($accountId, $status);
        fwrite(STDOUT, json_encode(['username' => $account['username'], 'status' => $status], JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    if ($command === 'password') {
        $password = (string)(getenv('BBE_ADMIN_PASSWORD') ?: '');
        if ($password === '') throw new RuntimeException('Set BBE_ADMIN_PASSWORD in the process environment; passwords are not accepted in command arguments');
        AdminIdentity::setPassword($accountId, $password);
        fwrite(STDOUT, json_encode(['username' => $account['username'], 'password_rotated' => true, 'sessions_revoked' => true], JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    if ($command === 'devices') {
        fwrite(STDOUT, json_encode(AdminIdentity::devices($accountId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    if ($command === 'grant') {
        $store = strtolower((string)($options['store'] ?? 'default'));
        $permissions = array_values(array_filter(array_map('trim', explode(',', (string)($options['permissions'] ?? '*')))));
        AdminIdentity::grantStore($accountId, $store, $permissions);
        fwrite(STDOUT, json_encode(['username' => $account['username'], 'store_id' => $store, 'permissions' => $permissions], JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    if ($command === 'revoke-sessions') {
        AdminIdentity::revokeSessions($accountId);
        fwrite(STDOUT, json_encode(['username' => $account['username'], 'sessions_revoked' => true], JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    if ($command === 'revoke-device') {
        $deviceId = (int)($options['device'] ?? 0);
        if ($deviceId <= 0) throw new InvalidArgumentException('Invalid --device');
        AdminIdentity::revokeDevice($accountId, $deviceId);
        fwrite(STDOUT, json_encode(['username' => $account['username'], 'device_id' => $deviceId, 'revoked' => true], JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php cli/admin-user.php keygen\n");
    fwrite(STDERR, "  BBE_ADMIN_PASSWORD=... php cli/admin-user.php create --username=ops --role=owner --store=default\n");
    fwrite(STDERR, "  php cli/admin-user.php list\n");
    fwrite(STDERR, "  php cli/admin-user.php mfa-enroll --username=ops\n");
    fwrite(STDERR, "  php cli/admin-user.php status --username=ops --status=disabled|active\n");
    fwrite(STDERR, "  BBE_ADMIN_PASSWORD=... php cli/admin-user.php password --username=ops\n");
    fwrite(STDERR, "  php cli/admin-user.php devices --username=ops\n");
    fwrite(STDERR, "  php cli/admin-user.php grant --username=ops --store=default --permissions=orders.*,reports.view\n");
    fwrite(STDERR, "  php cli/admin-user.php revoke-sessions --username=ops\n");
    fwrite(STDERR, "  php cli/admin-user.php revoke-device --username=ops --device=123\n");
    exit(64);
} catch (Throwable $e) {
    fwrite(STDERR, 'Admin identity command failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
