<?php
/**
 * BareBones Shop — Centralized security
 * CSRF, rate limiting, input sanitization, HTTP headers, admin auth.
 */
class Security {
    // ============================================================
    // Config cache — avoid re-reading config.php on every call
    // ============================================================
    private static ?array $cfg = null;

    private static function cfg(): array {
        if (!self::$cfg) {
            self::$cfg = require __DIR__ . '/../config.php';
        }
        return self::$cfg;
    }

    // ============================================================
    // Session — secure cookie params + start
    // ============================================================
    public static function sessionStart(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'secure'   => !empty($_SERVER['HTTPS']),
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    // ============================================================
    // HTTP headers — call once at the beginning of every request
    // ============================================================
    public static function headers(): void {
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
        if (!empty($_SERVER['HTTPS'])) {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        }
        header("Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' https://js.stripe.com; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: https:; "
            . "connect-src 'self' https://api.stripe.com; "
            . "frame-src https://js.stripe.com; "
            . "object-src 'none'; "
            . "base-uri 'self';"
        );
    }

    // ============================================================
    // CSRF — token generation and validation
    // ============================================================
    public static function csrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfValidate(): bool {
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if ($sessionToken === '') return false; // no token ever generated = reject
        $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return hash_equals($sessionToken, $submitted);
    }

    // ============================================================
    // Rate limiting — dedicated SQLite DB
    // ============================================================
    private static ?PDO $rateDb = null;
    private static int $rateDbLastCleanup = 0;

    private static function rateDb(): PDO {
        if (!self::$rateDb) {
            $cfg = self::cfg();
            $cacheDir = $cfg['cache_dir'] ?? __DIR__ . '/../cache';
            if (!is_dir($cacheDir)) mkdir($cacheDir, 0750, true);

            self::$rateDb = new PDO('sqlite:' . $cacheDir . '/rate_limit.sqlite');
            self::$rateDb->exec('PRAGMA synchronous = OFF');
            self::$rateDb->exec('PRAGMA journal_mode = MEMORY');
            self::$rateDb->exec('CREATE TABLE IF NOT EXISTS hits (
                key TEXT NOT NULL, ts INTEGER NOT NULL
            )');
            self::$rateDb->exec('CREATE INDEX IF NOT EXISTS idx_hits ON hits(key, ts)');
            self::$rateDbLastCleanup = time();
            self::$rateDb->exec("DELETE FROM hits WHERE ts < " . (time() - 300));
        } elseif (time() - self::$rateDbLastCleanup > 60) {
            self::$rateDbLastCleanup = time();
            self::$rateDb->exec("DELETE FROM hits WHERE ts < " . (time() - 300));
        }
        return self::$rateDb;
    }

    public static function rateLimit(string $action, int $maxPerMinute = 60): bool {
        $ip = self::clientIp();
        $key = md5($action . '|' . $ip);
        $now = time();
        $window = $now - 60;
        $db = self::rateDb();

        $db->prepare("INSERT INTO hits (key, ts) VALUES (:key, :ts)")
           ->execute([':key' => $key, ':ts' => $now]);

        $stmt = $db->prepare("SELECT COUNT(*) FROM hits WHERE key = :key AND ts > :window");
        $stmt->execute([':key' => $key, ':window' => $window]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $maxPerMinute) {
            http_response_code(429);
            header('Retry-After: 60');
            return false;
        }
        return true;
    }

    // ============================================================
    // Input sanitization
    // ============================================================
    public static function clean(string $input): string {
        return trim(strip_tags($input));
    }

    public static function slug(string $input): string {
        $s = strtolower($input);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }

    public static function int($input, int $default = 0): int {
        return filter_var($input, FILTER_VALIDATE_INT) !== false
            ? (int)$input : $default;
    }

    public static function email(string $input): ?string {
        $email = filter_var(trim($input), FILTER_VALIDATE_EMAIL);
        return $email ?: null;
    }

    // ============================================================
    // IP address — behind proxy/load balancer
    // ============================================================
    public static function clientIp(): string {
        $cfg = self::cfg();
        $trusted = $cfg['trusted_proxies'] ?? [];

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if (!empty($trusted) && in_array($remoteAddr, $trusted)) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            $ips = array_map('trim', explode(',', $forwarded));
            return $ips[0] ?: $remoteAddr;
        }
        return $remoteAddr;
    }

    // ============================================================
    // Admin authentication — with brute-force protection + roles
    // ============================================================

    /** Role definitions: permissions for each role */
    private static array $rolePermissions = [
        'owner'       => ['*'],
        'manager'     => ['products.*', 'orders.*', 'customers.view', 'reports.view', 'import', 'cache'],
        'editor'      => ['products.edit', 'products.view', 'categories.edit', 'media.upload'],
        'fulfillment' => ['orders.view', 'orders.ship', 'orders.print'],
        'viewer'      => ['products.view', 'orders.view', 'reports.view'],
    ];

    public static function adminAuth(): bool {
        $cfg = self::cfg();

        // Session fast-path: already authenticated this session
        self::sessionStart();
        if (!empty($_SESSION['admin_authenticated'])) {
            return true;
        }

        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';

        $ip = self::clientIp();
        $cacheDir = $cfg['cache_dir'] ?? __DIR__ . '/../cache';
        $rateDir = $cacheDir . '/rate';
        if (!is_dir($rateDir)) mkdir($rateDir, 0750, true);

        $lockFile = $rateDir . '/admin_' . md5($ip);
        $lockData = file_exists($lockFile) ? json_decode(file_get_contents($lockFile), true) : null;

        if ($lockData && $lockData['failures'] >= 3) {
            $lockoutSec = min(300, (int)pow(2, $lockData['failures'] - 1));
            if (time() - $lockData['last_attempt'] < $lockoutSec) {
                http_response_code(429);
                header('Retry-After: ' . $lockoutSec);
                exit('Too many login attempts. Try again later.');
            }
        }

        $authenticated = false;
        $role = 'owner';

        // Try multi-user auth from admin-roles.json first
        $rolesFile = __DIR__ . '/../admin-roles.json';
        if (file_exists($rolesFile)) {
            $roles = json_decode(file_get_contents($rolesFile), true);
            $users = $roles['users'] ?? [];
            if (isset($users[$user]) && !empty($users[$user]['password'])
                && password_verify($pass, $users[$user]['password'])) {
                $authenticated = true;
                $role = $users[$user]['role'] ?? 'viewer';
            }
        }

        // Fallback: single-user from config.php
        if (!$authenticated && !empty($cfg['admin_password'])
            && $user === ($cfg['admin_user'] ?? 'admin')
            && password_verify($pass, $cfg['admin_password'])) {
            $authenticated = true;
            $role = 'owner';
        }

        if ($authenticated) {
            if (file_exists($lockFile)) unlink($lockFile);
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_user'] = $user;
            $_SESSION['admin_role'] = $role;
            return true;
        }

        if (empty($cfg['admin_password'])) {
            // No password configured at all — check if roles file has users
            $rolesFile = __DIR__ . '/../admin-roles.json';
            if (!file_exists($rolesFile) || empty(json_decode(file_get_contents($rolesFile), true)['users'] ?? [])) {
                http_response_code(503);
                exit('Service unavailable.');
            }
        }

        $lockData = $lockData ?? ['failures' => 0];
        $lockData['failures']++;
        $lockData['last_attempt'] = time();
        file_put_contents($lockFile, json_encode($lockData), LOCK_EX);

        header('WWW-Authenticate: Basic realm="Shop Admin"');
        http_response_code(401);
        exit('Unauthorized');
    }

    /** Get the current admin user's role */
    public static function adminRole(): string {
        return $_SESSION['admin_role'] ?? 'owner';
    }

    /** Get the current admin username */
    public static function adminUser(): string {
        return $_SESSION['admin_user'] ?? 'admin';
    }

    /** Check if current admin has a permission */
    public static function adminCan(string $permission): bool {
        $role = self::adminRole();
        $perms = self::$rolePermissions[$role] ?? [];

        // Load custom roles from admin-roles.json if present
        $rolesFile = __DIR__ . '/../admin-roles.json';
        if (file_exists($rolesFile)) {
            $rolesData = json_decode(file_get_contents($rolesFile), true);
            if (isset($rolesData['roles'][$role]['permissions'])) {
                $perms = $rolesData['roles'][$role]['permissions'];
            }
        }

        foreach ($perms as $perm) {
            if ($perm === '*') return true;
            if ($perm === $permission) return true;
            // Wildcard: products.* matches products.edit, products.view, etc.
            if (str_ends_with($perm, '.*')) {
                $prefix = substr($perm, 0, -2);
                if (str_starts_with($permission, $prefix . '.') || $permission === $prefix) return true;
            }
        }
        return false;
    }

    /** Require permission — return 403 if denied */
    public static function adminRequire(string $permission): void {
        if (!self::adminCan($permission)) {
            http_response_code(403);
            exit('Forbidden: insufficient permissions');
        }
    }
}

// ============================================================
// Global helper — used everywhere for XSS prevention
// ============================================================
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
