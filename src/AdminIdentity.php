<?php
/** Database-backed admin identities, MFA, store grants, devices, and revocable sessions. */
class AdminIdentity {
    private const ROLES = ['owner', 'manager', 'editor', 'fulfillment', 'viewer'];
    private static ?string $dummyPasswordHash = null;

    public static function available(): bool {
        return self::tableExists('admin_accounts');
    }

    public static function accountCount(): int {
        if (!self::available()) return 0;
        return (int)Db::get()->query('SELECT COUNT(*) FROM admin_accounts')->fetchColumn();
    }

    public static function activeCount(): int {
        if (!self::available()) return 0;
        return (int)Db::get()->query("SELECT COUNT(*) FROM admin_accounts WHERE status='active'")->fetchColumn();
    }

    public static function create(string $username, string $password, string $role = 'viewer', bool $mfaRequired = true): int {
        $username = self::username($username);
        self::assertPassword($password);
        if (!in_array($role, self::ROLES, true)) throw new InvalidArgumentException('Invalid admin role');
        $stmt = Db::get()->prepare("INSERT INTO admin_accounts
            (username, password_hash, role, status, mfa_required, created_at, updated_at)
            VALUES (:username, :hash, :role, 'active', :mfa, :created, :updated)");
        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([
            ':username' => $username,
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => $role,
            ':mfa' => $mfaRequired ? 1 : 0,
            ':created' => $now,
            ':updated' => $now,
        ]);
        $id = (int)Db::get()->lastInsertId();
        self::audit($id, null, ['username' => $username, 'role' => $role, 'status' => 'active'], 'Admin account created');
        return $id;
    }

    /** Returns the plaintext enrollment material once; only encrypted/hashed forms are persisted. */
    public static function enrollTotp(int $accountId, int $recoveryCodeCount = 10): array {
        $account = self::account($accountId);
        if (!$account) throw new RuntimeException('Admin account not found');
        $secret = Totp::generateSecret();
        $db = Db::get();
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE admin_accounts SET totp_secret_encrypted=:secret, totp_last_counter=NULL, mfa_required=1, updated_at=:updated WHERE id=:id')
                ->execute([':secret' => self::encrypt($secret), ':updated' => gmdate('Y-m-d H:i:s'), ':id' => $accountId]);
            $db->prepare('DELETE FROM admin_recovery_codes WHERE account_id=:id')->execute([':id' => $accountId]);
            $codes = [];
            $insert = $db->prepare('INSERT INTO admin_recovery_codes (account_id, code_hash, created_at) VALUES (:account, :hash, :created)');
            for ($i = 0; $i < max(4, min(20, $recoveryCodeCount)); $i++) {
                $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 5) . '-' . substr(bin2hex(random_bytes(5)), 0, 5));
                $insert->execute([':account' => $accountId, ':hash' => self::recoveryHash($code), ':created' => gmdate('Y-m-d H:i:s')]);
                $codes[] = $code;
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        self::audit($accountId, ['mfa' => false], ['mfa' => true], 'TOTP enrolled and recovery codes rotated');
        return [
            'secret' => $secret,
            'uri' => Totp::provisioningUri($secret, (string)$account['username']),
            'recovery_codes' => $codes,
        ];
    }

    public static function grantStore(int $accountId, string $storeId, array $permissions): void {
        if (!self::account($accountId)) throw new RuntimeException('Admin account not found');
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $storeId)) throw new InvalidArgumentException('Invalid store ID');
        $permissions = array_values(array_unique(array_map('strval', $permissions)));
        foreach ($permissions as $permission) {
            if ($permission !== '*' && !preg_match('/^[a-z][a-z0-9_.-]*(?:\.\*)?$/', $permission)) {
                throw new InvalidArgumentException('Invalid store permission');
            }
        }
        $json = json_encode($permissions, JSON_THROW_ON_ERROR);
        $now = gmdate('Y-m-d H:i:s');
        $update = Db::get()->prepare('UPDATE admin_store_permissions SET permissions=:permissions, updated_at=:updated WHERE account_id=:account AND store_id=:store');
        $update->execute([':permissions' => $json, ':updated' => $now, ':account' => $accountId, ':store' => $storeId]);
        if ($update->rowCount() === 0) {
            try {
                Db::get()->prepare('INSERT INTO admin_store_permissions (account_id, store_id, permissions, created_at, updated_at) VALUES (:account, :store, :permissions, :created, :updated)')
                    ->execute([':account' => $accountId, ':store' => $storeId, ':permissions' => $json, ':created' => $now, ':updated' => $now]);
            } catch (PDOException $e) {
                if (!in_array($e->getCode(), ['23000', '23505'], true)) throw $e;
                $update->execute([':permissions' => $json, ':updated' => $now, ':account' => $accountId, ':store' => $storeId]);
            }
        }
        self::audit($accountId, null, ['store_id' => $storeId, 'permissions' => $permissions], 'Store permissions changed');
    }

    public static function setStatus(int $accountId, string $status): void {
        if (!in_array($status, ['active', 'disabled'], true)) throw new InvalidArgumentException('Invalid admin status');
        $old = self::account($accountId);
        if (!$old) throw new RuntimeException('Admin account not found');
        Db::get()->prepare('UPDATE admin_accounts SET status=:status, updated_at=:updated WHERE id=:id')
            ->execute([':status' => $status, ':updated' => gmdate('Y-m-d H:i:s'), ':id' => $accountId]);
        if ($status !== 'active') self::revokeSessions($accountId);
        self::audit($accountId, ['status' => $old['status']], ['status' => $status], 'Admin lifecycle changed');
    }

    public static function setPassword(int $accountId, string $password): void {
        if (!self::account($accountId)) throw new RuntimeException('Admin account not found');
        self::assertPassword($password);
        Db::get()->prepare('UPDATE admin_accounts SET password_hash=:hash, failed_attempts=0, locked_until=NULL, updated_at=:updated WHERE id=:id')
            ->execute([
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ':updated' => gmdate('Y-m-d H:i:s'),
                ':id' => $accountId,
            ]);
        self::revokeSessions($accountId);
        self::audit($accountId, null, ['password_rotated' => true], 'Admin password rotated and sessions revoked');
    }

    public static function authenticate(string $username, string $password, string $mfaCode, string $ip, string $userAgent): ?array {
        if (!self::available()) return null;
        $username = strtolower(trim($username));
        $stmt = Db::get()->prepare('SELECT * FROM admin_accounts WHERE username=:username LIMIT 1');
        $stmt->execute([':username' => $username]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $validPassword = password_verify($password, (string)($account['password_hash'] ?? self::dummyPasswordHash()));
        $locked = $account && $account['locked_until'] !== null && strtotime((string)$account['locked_until'] . ' UTC') > time();
        if (!$account || $account['status'] !== 'active' || $locked || !$validPassword) {
            // Wrong passwords must not lock the account: anyone could lock the
            // owner out. Password guessing is throttled per IP by AdminLoginThrottle;
            // the account lock below only follows MFA failures with a valid password.
            if ($account && !$locked) Logger::warning('admin.login_failed', ['account_id' => (int)$account['id'], 'locked_until' => null]);
            return null;
        }

        if ((int)$account['mfa_required'] === 1) {
            $encrypted = (string)($account['totp_secret_encrypted'] ?? '');
            if ($encrypted === '') return null;
            $counter = Totp::matchedCounter(self::decrypt($encrypted), $mfaCode);
            $validMfa = $counter !== null
                ? self::claimTotpCounter((int)$account['id'], $counter)
                : self::consumeRecoveryCode((int)$account['id'], $mfaCode);
            if (!$validMfa) {
                self::recordFailure((int)$account['id']);
                return null;
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        Db::get()->prepare('UPDATE admin_accounts SET failed_attempts=0, locked_until=NULL, last_login_at=:login, updated_at=:updated WHERE id=:id')
            ->execute([':login' => $now, ':updated' => $now, ':id' => $account['id']]);
        $session = self::createSession((int)$account['id'], $ip, $userAgent);
        self::audit((int)$account['id'], null, ['device_id' => $session['device_id']], 'Admin login succeeded');
        return $session + [
            'account_id' => (int)$account['id'],
            'username' => (string)$account['username'],
            'role' => (string)$account['role'],
            'store_permissions' => self::storePermissions((int)$account['id']),
        ];
    }

    public static function session(string $token): ?array {
        if ($token === '' || !self::available()) return null;
        $stmt = Db::get()->prepare("SELECT s.*, a.username, a.role, a.status AS account_status, d.revoked_at AS device_revoked
            FROM admin_sessions s JOIN admin_accounts a ON a.id=s.account_id
            JOIN admin_devices d ON d.id=s.device_id
            WHERE s.token_hash=:hash AND s.revoked_at IS NULL LIMIT 1");
        $stmt->execute([':hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $idleSeconds = self::configInt('admin_session_idle_seconds', 1800, 300, 86400);
        if (!$row || $row['account_status'] !== 'active' || $row['device_revoked'] !== null
            || strtotime((string)$row['expires_at'] . ' UTC') <= time()
            || strtotime((string)$row['last_seen_at'] . ' UTC') < time() - $idleSeconds) {
            if ($row) self::revokeSession((string)$row['id']);
            return null;
        }
        $now = gmdate('Y-m-d H:i:s');
        Db::get()->prepare('UPDATE admin_sessions SET last_seen_at=:seen WHERE id=:id')->execute([':seen' => $now, ':id' => $row['id']]);
        Db::get()->prepare('UPDATE admin_devices SET last_seen_at=:seen WHERE id=:id')->execute([':seen' => $now, ':id' => $row['device_id']]);
        return [
            'session_id' => (string)$row['id'],
            'account_id' => (int)$row['account_id'],
            'device_id' => (int)$row['device_id'],
            'username' => (string)$row['username'],
            'role' => (string)$row['role'],
            'store_permissions' => self::storePermissions((int)$row['account_id']),
        ];
    }

    public static function storeAllows(array $grants, string $storeId, string $permission): bool {
        $permissions = $grants[$storeId] ?? null;
        if (!is_array($permissions)) return false;
        foreach ($permissions as $granted) {
            if ($granted === '*' || $granted === $permission) return true;
            if (str_ends_with($granted, '.*')) {
                $prefix = substr($granted, 0, -2);
                if ($permission === $prefix || str_starts_with($permission, $prefix . '.')) return true;
            }
        }
        return false;
    }

    public static function revokeSession(string $sessionId): void {
        if (!self::available() || $sessionId === '') return;
        Db::get()->prepare('UPDATE admin_sessions SET revoked_at=:revoked WHERE id=:id AND revoked_at IS NULL')
            ->execute([':revoked' => gmdate('Y-m-d H:i:s'), ':id' => $sessionId]);
    }

    public static function revokeSessions(int $accountId): void {
        if (!self::available()) return;
        Db::get()->prepare('UPDATE admin_sessions SET revoked_at=:revoked WHERE account_id=:account AND revoked_at IS NULL')
            ->execute([':revoked' => gmdate('Y-m-d H:i:s'), ':account' => $accountId]);
    }

    public static function revokeDevice(int $accountId, int $deviceId): void {
        $now = gmdate('Y-m-d H:i:s');
        Db::get()->prepare('UPDATE admin_devices SET revoked_at=:revoked WHERE id=:id AND account_id=:account')
            ->execute([':revoked' => $now, ':id' => $deviceId, ':account' => $accountId]);
        Db::get()->prepare('UPDATE admin_sessions SET revoked_at=:revoked WHERE device_id=:device AND account_id=:account AND revoked_at IS NULL')
            ->execute([':revoked' => $now, ':device' => $deviceId, ':account' => $accountId]);
        self::audit($accountId, null, ['device_id' => $deviceId], 'Admin device revoked');
    }

    public static function accounts(): array {
        if (!self::available()) return [];
        return Db::get()->query("SELECT id, username, role, status, mfa_required, last_login_at, created_at FROM admin_accounts ORDER BY username")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function roles(): array {
        return self::ROLES;
    }

    /** MFA enrollment needs the deployment key; check before creating state that would depend on it. */
    public static function mfaKeyConfigured(): bool {
        try {
            self::secretKey();
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    public static function accountById(int $id): ?array {
        return self::available() ? self::account($id) : null;
    }

    public static function accountByUsername(string $username): ?array {
        if (!self::available()) return null;
        $stmt = Db::get()->prepare('SELECT * FROM admin_accounts WHERE username=:username LIMIT 1');
        $stmt->execute([':username' => strtolower(trim($username))]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function devices(int $accountId): array {
        $stmt = Db::get()->prepare('SELECT id, label, user_agent, last_ip, last_seen_at, revoked_at, created_at FROM admin_devices WHERE account_id=:account ORDER BY last_seen_at DESC');
        $stmt->execute([':account' => $accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function createSession(int $accountId, string $ip, string $userAgent): array {
        $userAgent = substr($userAgent, 0, 1000);
        $ip = substr($ip, 0, 191);
        $fingerprint = hash('sha256', $userAgent);
        $now = gmdate('Y-m-d H:i:s');
        $deviceId = self::upsertDevice($accountId, $fingerprint, $ip, $userAgent, $now);
        $token = bin2hex(random_bytes(32));
        $sessionId = bin2hex(random_bytes(16));
        $lifetime = self::configInt('admin_session_lifetime_seconds', 28800, 1800, 604800);
        Db::get()->prepare('INSERT INTO admin_sessions
            (id, account_id, device_id, token_hash, ip_address, user_agent, last_seen_at, expires_at, created_at)
            VALUES (:id, :account, :device, :hash, :ip, :agent, :seen, :expires, :created)')
            ->execute([
                ':id' => $sessionId, ':account' => $accountId, ':device' => $deviceId,
                ':hash' => hash('sha256', $token), ':ip' => $ip, ':agent' => $userAgent,
                ':seen' => $now, ':expires' => gmdate('Y-m-d H:i:s', time() + $lifetime), ':created' => $now,
            ]);
        return ['token' => $token, 'session_id' => $sessionId, 'device_id' => $deviceId];
    }

    private static function upsertDevice(int $accountId, string $fingerprint, string $ip, string $userAgent, string $now): int {
        $read = Db::get()->prepare('SELECT id FROM admin_devices WHERE account_id=:account AND fingerprint=:fingerprint');
        $read->execute([':account' => $accountId, ':fingerprint' => $fingerprint]);
        $id = (int)$read->fetchColumn();
        if ($id > 0) {
            Db::get()->prepare('UPDATE admin_devices SET user_agent=:agent, last_ip=:ip, last_seen_at=:seen, revoked_at=NULL WHERE id=:id')
                ->execute([':agent' => $userAgent, ':ip' => $ip, ':seen' => $now, ':id' => $id]);
            return $id;
        }
        Db::get()->prepare('INSERT INTO admin_devices (account_id, fingerprint, label, user_agent, last_ip, last_seen_at, created_at) VALUES (:account, :fingerprint, :label, :agent, :ip, :seen, :created)')
            ->execute([':account' => $accountId, ':fingerprint' => $fingerprint, ':label' => substr($userAgent, 0, 80), ':agent' => $userAgent, ':ip' => $ip, ':seen' => $now, ':created' => $now]);
        return (int)Db::get()->lastInsertId();
    }

    /** A TOTP code is single-use: only a time step newer than the last accepted one wins (atomic). */
    private static function claimTotpCounter(int $accountId, int $counter): bool {
        $stmt = Db::get()->prepare('UPDATE admin_accounts SET totp_last_counter=:counter
            WHERE id=:id AND (totp_last_counter IS NULL OR totp_last_counter < :counter_floor)');
        $stmt->execute([':counter' => $counter, ':id' => $accountId, ':counter_floor' => $counter]);
        return $stmt->rowCount() === 1;
    }

    private static function consumeRecoveryCode(int $accountId, string $code): bool {
        $hash = self::recoveryHash($code);
        $stmt = Db::get()->prepare('UPDATE admin_recovery_codes SET used_at=:used WHERE account_id=:account AND code_hash=:hash AND used_at IS NULL');
        $stmt->execute([':used' => gmdate('Y-m-d H:i:s'), ':account' => $accountId, ':hash' => $hash]);
        return $stmt->rowCount() === 1;
    }

    // Unknown usernames are verified against a hash made exactly like real ones (PASSWORD_DEFAULT,
    // cost 12 on PHP 8.4+), so the response time does not reveal whether an account exists.
    private static function dummyPasswordHash(): string {
        return self::$dummyPasswordHash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    private static function recordFailure(int $accountId): void {
        $account = self::account($accountId);
        if (!$account) return;
        $attempts = (int)$account['failed_attempts'] + 1;
        $lockedUntil = $attempts >= 5 ? gmdate('Y-m-d H:i:s', time() + min(3600, 60 * (2 ** min(5, $attempts - 5)))) : null;
        Db::get()->prepare('UPDATE admin_accounts SET failed_attempts=:attempts, locked_until=:locked, updated_at=:updated WHERE id=:id')
            ->execute([':attempts' => $attempts, ':locked' => $lockedUntil, ':updated' => gmdate('Y-m-d H:i:s'), ':id' => $accountId]);
        Logger::warning('admin.login_failed', ['account_id' => $accountId, 'locked_until' => $lockedUntil]);
    }

    private static function storePermissions(int $accountId): array {
        $stmt = Db::get()->prepare('SELECT store_id, permissions FROM admin_store_permissions WHERE account_id=:account');
        $stmt->execute([':account' => $accountId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string)$row['permissions'], true);
            $result[(string)$row['store_id']] = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }
        return $result;
    }

    private static function account(int $id): ?array {
        $stmt = Db::get()->prepare('SELECT * FROM admin_accounts WHERE id=:id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function username(string $username): string {
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,63}$/', $username)) throw new InvalidArgumentException('Invalid admin username');
        return $username;
    }

    private static function assertPassword(string $password): void {
        if (strlen($password) < 12 || strlen($password) > 1024) throw new InvalidArgumentException('Admin password must contain 12 to 1024 bytes');
    }

    private static function recoveryHash(string $code): string {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
        return hash_hmac('sha256', $normalized, self::secretKey());
    }

    private static function encrypt(string $plaintext): string {
        if (!function_exists('sodium_crypto_secretbox')) throw new RuntimeException('Sodium is required for admin MFA');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::secretKey()));
    }

    private static function decrypt(string $encoded): string {
        if (!str_starts_with($encoded, 'v1:')) throw new RuntimeException('Unsupported MFA secret format');
        $raw = base64_decode(substr($encoded, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Invalid MFA secret');
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::secretKey());
        if ($plaintext === false) throw new RuntimeException('Cannot decrypt MFA secret');
        return $plaintext;
    }

    private static function secretKey(): string {
        $cfg = require __DIR__ . '/../config.php';
        $value = trim((string)(getenv('BBE_ADMIN_IDENTITY_KEY') ?: ($cfg['admin_identity_key'] ?? '')));
        $key = preg_match('/^[a-f0-9]{64}$/i', $value) ? hex2bin($value) : base64_decode($value, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('admin_identity_key must be a 32-byte base64 or hex deployment secret');
        }
        return $key;
    }

    private static function configInt(string $key, int $default, int $min, int $max): int {
        $cfg = require __DIR__ . '/../config.php';
        return max($min, min($max, (int)($cfg[$key] ?? $default)));
    }

    private static function audit(int $id, $old, $new, string $note): void {
        if (self::tableExists('audit_log')) AuditLog::log('admin_account', $id, $old, $new, $note);
    }

    private static function tableExists(string $table): bool {
        try {
            $db = Db::get();
            if (Db::isSqlite()) {
                $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name");
            } else {
                $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:name');
            }
            $stmt->execute([':name' => $table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
