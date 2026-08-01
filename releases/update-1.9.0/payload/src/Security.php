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
            $configuredHttps = strtolower((string)parse_url((string)(self::cfg()['app_url'] ?? ''), PHP_URL_SCHEME)) === 'https';
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => true,
                'secure'   => !empty($_SERVER['HTTPS']) || $configuredHttps,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    // ============================================================
    // CSP nonce — one random nonce per request, shared by headers()
    // and every inline <script nonce="..."> in the templates.
    // ============================================================
    private static ?string $cspNonce = null;

    public static function cspNonce(): string {
        if (self::$cspNonce === null) {
            self::$cspNonce = base64_encode(random_bytes(16));
        }
        return self::$cspNonce;
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
        $nonce = self::cspNonce();
        header("Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' 'nonce-$nonce' https://js.stripe.com; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "img-src 'self' data: https:; "
            . "connect-src 'self' https://api.stripe.com; "
            . "frame-src https://js.stripe.com; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none';"
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

    public static function rotateCsrfToken(): string {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    public static function formTrapToken(string $bucket, int $ttl = 3600): string {
        $key = 'form_trap_tokens_' . md5($bucket);
        $now = time();
        $_SESSION[$key] = array_filter(
            $_SESSION[$key] ?? [],
            static fn($ts) => is_int($ts) && $ts >= $now - $ttl
        );
        $token = bin2hex(random_bytes(16));
        $_SESSION[$key][$token] = $now;
        return $token;
    }

    public static function formTrapValidate(string $bucket, string $token, string $honeypot = '', int $minAge = 4, int $ttl = 3600): bool {
        $key = 'form_trap_tokens_' . md5($bucket);
        $issuedAt = (int)($_SESSION[$key][$token] ?? 0);
        unset($_SESSION[$key][$token]);
        if (trim($honeypot) !== '' || $issuedAt <= 0) return false;
        $age = time() - $issuedAt;
        return $age >= $minAge && $age <= $ttl;
    }

    /**
     * Sanitize a user-supplied redirect target to a safe same-site path.
     * Blocks open-redirect vectors: absolute URLs (scheme://), protocol-relative
     * (//host, /\host), backslash tricks, and header-injection chars. Returns the
     * original value only if it is a plain local path, otherwise $fallback.
     */
    public static function safeLocalPath(?string $path, string $fallback = '/'): string {
        $path = trim((string)$path);
        if ($path === '') return $fallback;
        // Any scheme prefix (http:, https:, javascript:, data:, …) → reject.
        if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $path)) return $fallback;
        // Protocol-relative or backslash-smuggled host → reject.
        if ($path[0] === '\\') return $fallback;
        if ($path[0] === '/' && isset($path[1]) && ($path[1] === '/' || $path[1] === '\\')) return $fallback;
        // Whitelist of safe path chars (also blocks CR/LF header injection).
        if (!preg_match('~^/?[A-Za-z0-9_\-./?=&%#]+$~', $path)) return $fallback;
        return $path;
    }

    public static function appUrl(array $cfg): string {
        $url = rtrim(trim((string)($cfg['app_url'] ?? '')), '/');
        if ($url === '' || str_contains($url, "\\") || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new RuntimeException('app_url must be a valid absolute HTTP(S) URL');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new RuntimeException('app_url must be a valid absolute HTTP(S) URL');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || !$validHost
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535))) {
            throw new RuntimeException('app_url must be a valid absolute HTTP(S) URL');
        }

        return $url;
    }

    /**
     * Neutralize CSV/spreadsheet formula injection. Prefixes a single quote to
     * any cell that a spreadsheet would interpret as a formula (=, +, -, @, or a
     * leading tab/CR), while leaving plain numbers (e.g. "-500") untouched.
     */
    public static function csvCell($value): string {
        $s = (string)$value;
        if ($s === '') return $s;
        $first = $s[0];
        $danger = ($first === '=' || $first === '@' || $first === "\t" || $first === "\r")
               || (($first === '+' || $first === '-') && !is_numeric($s));
        return $danger ? "'" . $s : $s;
    }

    // ============================================================
    // Rate limiting — dedicated SQLite DB
    // ============================================================
    private static ?PDO $rateDb = null;
    private static int $rateDbLastCleanup = 0;

    private static function privateCacheDir(array $cfg): string {
        $dir = (string)($cfg['private_cache_dir'] ?? dirname(__DIR__, 2) . '/bareboneseshop-private/cache');
        $absolute = str_starts_with($dir, '/') || str_starts_with($dir, '\\\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $dir) === 1;
        if (!$absolute || (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir))) {
            throw new RuntimeException('private_cache_dir must be an absolute writable directory outside the application root');
        }
        $realDir = realpath($dir);
        $appRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
        if ($realDir === false) throw new RuntimeException("Cannot resolve private_cache_dir: {$dir}");
        $normalizedDir = rtrim(str_replace('\\', '/', $realDir), '/');
        $normalizedRoot = rtrim(str_replace('\\', '/', $appRoot), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $normalizedDir = strtolower($normalizedDir);
            $normalizedRoot = strtolower($normalizedRoot);
        }
        if ($normalizedDir === $normalizedRoot || str_starts_with($normalizedDir, $normalizedRoot . '/')) {
            throw new RuntimeException('private_cache_dir must be outside the application root');
        }
        return $realDir;
    }

    private static function rateDb(): PDO {
        if (!self::$rateDb) {
            $cfg = self::cfg();
            $cacheDir = self::privateCacheDir($cfg);

            self::$rateDb = new PDO('sqlite:' . $cacheDir . '/rate_limit.sqlite');
            self::$rateDb->exec('PRAGMA synchronous = OFF');
            self::$rateDb->exec('PRAGMA journal_mode = MEMORY');
            self::$rateDb->exec('CREATE TABLE IF NOT EXISTS hits (
                key TEXT NOT NULL, ts INTEGER NOT NULL
            )');
            self::$rateDb->exec('CREATE INDEX IF NOT EXISTS idx_hits ON hits(key, ts)');
            self::$rateDbLastCleanup = time();
            self::$rateDb->exec("DELETE FROM hits WHERE ts < " . (time() - 86400));
        } elseif (time() - self::$rateDbLastCleanup > 60) {
            self::$rateDbLastCleanup = time();
            self::$rateDb->exec("DELETE FROM hits WHERE ts < " . (time() - 86400));
        }
        return self::$rateDb;
    }

    public static function setRateDbForTesting(PDO $db): void {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE IF NOT EXISTS hits (key TEXT NOT NULL, ts INTEGER NOT NULL)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_hits ON hits(key, ts)');
        self::$rateDb = $db;
        self::$rateDbLastCleanup = time();
    }

    public static function rateLimit(string $action, int $maxHits = 60, int $windowSeconds = 60, bool $bindIp = true): bool {
        $windowSeconds = max(1, min(86400, $windowSeconds));
        $identity = $bindIp ? self::clientIp() : 'global';
        $key = hash('sha256', $action . '|' . $identity);
        $now = time();
        $window = $now - $windowSeconds;
        $db = self::rateDb();

        $db->prepare("INSERT INTO hits (key, ts) VALUES (:key, :ts)")
           ->execute([':key' => $key, ':ts' => $now]);

        $stmt = $db->prepare("SELECT COUNT(*) FROM hits WHERE key = :key AND ts > :window");
        $stmt->execute([':key' => $key, ':window' => $window]);
        $count = (int) $stmt->fetchColumn();

        if ($count > max(0, $maxHits)) {
            http_response_code(429);
            header('Retry-After: ' . $windowSeconds);
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

    public static function sanitizeRichHtml(string $html): string {
        $html = trim($html);
        if ($html === '') return '';
        if (!class_exists('DOMDocument')) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="bb-rich-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $root = $doc->getElementById('bb-rich-root');
        if (!$root) return '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            self::sanitizeRichNode($child);
        }

        $safe = '';
        foreach ($root->childNodes as $child) {
            $safe .= $doc->saveHTML($child);
        }
        return trim($safe);
    }

    private static function sanitizeRichNode(DOMNode $node): void {
        if ($node->nodeType === XML_COMMENT_NODE) {
            $node->parentNode?->removeChild($node);
            return;
        }
        if (!$node instanceof DOMElement) return;

        foreach (iterator_to_array($node->childNodes) as $child) {
            self::sanitizeRichNode($child);
        }

        $tag = strtolower($node->tagName);
        $allowed = ['p', 'br', 'strong', 'em', 'b', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'code', 'pre', 'a'];
        if (!in_array($tag, $allowed, true)) {
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math'], true)) {
                $node->parentNode?->removeChild($node);
                return;
            }
            $parent = $node->parentNode;
            if ($parent) {
                while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
                $parent->removeChild($node);
            }
            return;
        }

        foreach (iterator_to_array($node->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if ($tag !== 'a' || !in_array($name, ['href', 'title'], true)) {
                $node->removeAttribute($attribute->name);
            }
        }
        if ($tag === 'a' && $node->hasAttribute('href')) {
            $href = html_entity_decode(trim($node->getAttribute('href')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $scheme = parse_url($href, PHP_URL_SCHEME);
            if ($href === '' || str_starts_with($href, '//') || preg_match('/[\x00-\x1F\x7F]/', $href)
                || ($scheme !== null && !in_array(strtolower($scheme), ['http', 'https'], true))) {
                $node->removeAttribute('href');
            } else {
                $node->setAttribute('href', $href);
            }
        }
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
            if ($forwarded === '') return $remoteAddr;
            $ips = array_map('trim', explode(',', $forwarded));
            // Walk right-to-left, skipping our own trusted proxies. The first
            // address that is NOT a trusted proxy is the real client. Anything
            // further left is appended by the client and therefore spoofable —
            // taking the leftmost value let attackers forge their rate-limit IP.
            for ($i = count($ips) - 1; $i >= 0; $i--) {
                $candidate = $ips[$i];
                if ($candidate === '' || in_array($candidate, $trusted)) continue;
                return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : $remoteAddr;
            }
            return $remoteAddr;
        }
        return $remoteAddr;
    }

    // ============================================================
    // Admin authentication — with brute-force protection + roles
    // ============================================================

    /** Role definitions: permissions for each role */
    private static array $rolePermissions = [
        'owner'       => ['*'],
        'manager'     => ['products.*', 'orders.*', 'customers.view', 'customers.edit', 'reports.view', 'gdpr.view', 'import', 'cache'],
        'editor'      => ['products.edit', 'products.view', 'categories.edit', 'media.upload'],
        'fulfillment' => ['orders.view', 'orders.edit', 'orders.ship', 'orders.print'],
        'viewer'      => ['products.view', 'orders.view', 'reports.view'],
    ];

    public static function adminAuth(): bool {
        $cfg = self::cfg();

        // Session fast-path: expire idle admin sessions instead of trusting them indefinitely.
        self::sessionStart();
        $lastSeen = (int)($_SESSION['admin_last_seen'] ?? 0);
        if (!empty($_SESSION['admin_authenticated']) && $lastSeen >= time() - 1800) {
            $_SESSION['admin_last_seen'] = time();
            return true;
        }
        unset($_SESSION['admin_authenticated'], $_SESSION['admin_user'], $_SESSION['admin_role'], $_SESSION['admin_last_seen']);

        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
        if ($user !== '' && !self::rateLimit('admin_login_account_' . hash('sha256', strtolower($user)), 10, 900, false)) {
            exit('Too many login attempts. Try again later.');
        }

        $ip = self::clientIp();
        $cacheDir = self::privateCacheDir($cfg);
        $rateDir = $cacheDir . '/rate';
        if (!is_dir($rateDir) && !mkdir($rateDir, 0750, true) && !is_dir($rateDir)) {
            throw new RuntimeException("Cannot create private rate-limit directory: {$rateDir}");
        }

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
            $_SESSION['admin_last_seen'] = time();
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

    private static ?array $cachedRolesData = null;

    /** Check if current admin has a permission */
    public static function adminCan(string $permission): bool {
        $role = self::adminRole();
        $perms = self::$rolePermissions[$role] ?? [];

        // Load custom roles from admin-roles.json (cached per request)
        if (self::$cachedRolesData === null) {
            $rolesFile = __DIR__ . '/../admin-roles.json';
            self::$cachedRolesData = file_exists($rolesFile)
                ? (json_decode(file_get_contents($rolesFile), true) ?: [])
                : [];
        }
        if (isset(self::$cachedRolesData['roles'][$role]['permissions'])) {
            $perms = self::$cachedRolesData['roles'][$role]['permissions'];
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
