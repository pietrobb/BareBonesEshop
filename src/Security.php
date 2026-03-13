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
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return hash_equals($_SESSION['csrf_token'] ?? '', $token);
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

        if ($count > $maxPerMinute) {
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
    // Admin authentication — with brute-force protection
    // ============================================================
    public static function adminAuth(): bool {
        $cfg = self::cfg();

        if (empty($cfg['admin_password'])) {
            http_response_code(503);
            exit('Service unavailable.');
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

        if ($user === $cfg['admin_user'] && !empty($cfg['admin_password'])
            && password_verify($pass, $cfg['admin_password'])) {
            if (file_exists($lockFile)) unlink($lockFile);
            self::sessionStart();
            session_regenerate_id(true);
            return true;
        }

        $lockData = $lockData ?? ['failures' => 0];
        $lockData['failures']++;
        $lockData['last_attempt'] = time();
        file_put_contents($lockFile, json_encode($lockData), LOCK_EX);

        header('WWW-Authenticate: Basic realm="Shop Admin"');
        http_response_code(401);
        exit('Unauthorized');
    }
}

// ============================================================
// Global helper — used everywhere for XSS prevention
// ============================================================
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
