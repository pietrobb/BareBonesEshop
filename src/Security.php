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

    public static function config(): array {
        return self::cfg();
    }

    /** Non-reversible key for the current session; raw session IDs never go into the database. */
    public static function sessionKey(): string {
        $id = session_id();
        return $id === '' ? '' : hash('sha256', $id);
    }

    // ============================================================
    // Session — secure cookie params + start
    // ============================================================
    public static function sessionStart(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $configuredHttps = strtolower((string)parse_url((string)(self::cfg()['app_url'] ?? ''), PHP_URL_SCHEME)) === 'https';
            // Never adopt a client-chosen, uninitialized session ID (session fixation).
            ini_set('session.use_strict_mode', '1');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => true,
                'secure'   => self::requestIsHttps() || $configuredHttps,
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
        // Behind a TLS-terminating proxy PHP sees plain http: trust X-Forwarded-Proto from trusted_proxies, or an https
        // app_url (browsers ignore HSTS received over http, so sending it there is harmless).
        if (self::requestIsHttps() || strtolower((string)parse_url((string)(self::cfg()['app_url'] ?? ''), PHP_URL_SCHEME)) === 'https') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        }
        $reportUrl = self::cspReportUrl();
        if ($reportUrl !== null) header('Reporting-Endpoints: csp-endpoint="' . $reportUrl . '"');
        header('Content-Security-Policy: ' . self::contentSecurityPolicy());
    }

    /** Absolute CSP report endpoint; null without a valid configured app_url. */
    public static function cspReportUrl(): ?string {
        if (!is_file(dirname(__DIR__) . '/config.php')) return null;
        try {
            return self::appUrl(self::cfg()) . ApiRoute::PREFIX . '/csp-report';
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    public static function contentSecurityPolicy(): string {
        $nonce = self::cspNonce();
        return "default-src 'self'; "
            . "script-src 'self' 'nonce-$nonce' https://js.stripe.com; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "img-src 'self' data: https:; "
            . "connect-src 'self' https://api.stripe.com; "
            . "frame-src https://js.stripe.com; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action " . implode(' ', array_merge(["'self'"], self::paymentRedirectOrigins())) . "; "
            . "frame-ancestors 'none';"
            // Violations (a blocked gateway redirect above all) reach OperationalMetrics.
            . (($reportUrl = self::cspReportUrl()) !== null ? " report-uri {$reportUrl}; report-to csp-endpoint;" : '');
    }

    /**
     * Origins the checkout form POST may redirect to, declared by every registered
     * hosted-checkout payment adapter. Browsers apply form-action to the redirect that
     * follows a form submission, so a missing origin silently blocks payment
     * (P0 2026-10-13: card checkout dead since 1.9.0). Any CSP change needs the
     * browser E2E payment-redirect test in tests/e2e/commerce.spec.js.
     * @return list<string>
     */
    public static function paymentRedirectOrigins(): array {
        if (!is_file(dirname(__DIR__) . '/config.php')) return [];
        try {
            $codes = array_keys(Adapters::registry('payment'));
        } catch (\Throwable $e) {
            error_log('CSP payment origins unavailable: ' . $e->getMessage());
            return [];
        }
        $origins = [];
        foreach ($codes as $code) {
            try {
                $adapter = Adapters::provider('payment', (string)$code);
            } catch (\RuntimeException $e) {
                continue; // disabled mock provider or a provider whose class is not installed
            } catch (\Throwable $e) {
                error_log("CSP payment origins: provider {$code} failed to load: " . $e->getMessage());
                continue;
            }
            if (!$adapter instanceof HostedCheckoutAdapter) continue;
            foreach ($adapter->redirectOrigins() as $origin) {
                // https only; plain http just for a loopback test gateway.
                if (is_string($origin) && preg_match('#^(?:https://[a-z0-9.-]+|http://(?:127\.0\.0\.1|localhost))(?::[0-9]{1,5})?$#i', $origin)) {
                    $origins[] = strtolower($origin);
                }
            }
        }
        return array_values(array_unique($origins));
    }

    /** Whether a redirect answering a same-origin form POST passes this request's form-action. */
    public static function formActionAllows(string $url): bool {
        $parts = parse_url($url);
        if (!is_array($parts)) return false;
        if (!isset($parts['host'])) return !isset($parts['scheme']) && !str_starts_with($url, '//');
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $origin = $scheme . '://' . strtolower((string)$parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $self = (self::requestIsHttps() ? 'https' : 'http') . '://' . strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        return $origin === $self || in_array($origin, self::paymentRedirectOrigins(), true);
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
        if (!is_string($sessionToken) || $sessionToken === '') return false; // no token ever generated = reject
        $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        // csrf_token[]=… arrives as an array: reject it instead of a TypeError (HTTP 500).
        return is_string($submitted) && hash_equals($sessionToken, $submitted);
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
    public static function safeLocalPath(mixed $path, string $fallback = '/'): string {
        $path = trim(self::str($path));
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
     * A validated app_url moved to the store's own domain: a store with configured domains is served
     * there (StoreContext resolves it by host), so its links take the first domain and keep the
     * app_url scheme and path. A store without a valid domain gets the app_url.
     */
    public static function storeAppUrl(string $appUrl, array $store): string {
        $appUrl = rtrim($appUrl, '/');
        $domains = $store['domains'] ?? [];
        if (is_string($domains)) $domains = [$domains];
        $domain = is_array($domains) ? strtolower(trim((string)($domains[0] ?? ''))) : '';
        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) return $appUrl;
        $parts = parse_url($appUrl);
        return (string)($parts['scheme'] ?? 'https') . '://' . $domain . rtrim((string)($parts['path'] ?? ''), '/');
    }

    /** Validated public base URL, or null when app_url is not configured. Never derived from the Host header. */
    public static function configuredAppUrl(): ?string {
        try {
            return self::appUrl(self::cfg());
        } catch (RuntimeException) {
            return null;
        }
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
    private static bool $rateDbFailureReported = false;

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
        self::$rateDbFailureReported = false;
    }

    /**
     * Check-only variant of rateLimit(): true while fewer than $maxHits hits were recorded in the
     * window. Records nothing, so a refused attempt never extends a block (pair with rateLimitRecord()
     * after the guarded action succeeded).
     */
    public static function rateLimitAllows(string $action, int $maxHits, int $windowSeconds, bool $bindIp = true): bool {
        $windowSeconds = max(1, min(86400, $windowSeconds));
        return self::withRateDb(static function (PDO $db) use ($action, $maxHits, $windowSeconds, $bindIp): bool {
            $stmt = $db->prepare("SELECT COUNT(*) FROM hits WHERE key = :key AND ts > :window");
            $stmt->execute([':key' => self::rateKey($action, $bindIp), ':window' => time() - $windowSeconds]);
            return (int)$stmt->fetchColumn() < max(0, $maxHits);
        });
    }

    /** Records one hit for $action (see rateLimitAllows()). */
    public static function rateLimitRecord(string $action, bool $bindIp = true): void {
        self::withRateDb(static function (PDO $db) use ($action, $bindIp): bool {
            $db->prepare("INSERT INTO hits (key, ts) VALUES (:key, :ts)")
                ->execute([':key' => self::rateKey($action, $bindIp), ':ts' => time()]);
            return true;
        });
    }

    /**
     * Runs a rate-limit query. When the limiter's storage fails (read-only or full disk, unwritable private cache)
     * the request is allowed rather than answered with a 500: checkout, API and payment webhooks keep working, and
     * the outage is raised once per request as a critical incident.
     * @param callable(PDO): bool $query
     */
    private static function withRateDb(callable $query): bool {
        try {
            return $query(self::rateDb());
        } catch (Throwable $e) {
            if (!self::$rateDbFailureReported) {
                self::$rateDbFailureReported = true;
                Logger::error('security.rate_limit_storage_failed', ['exception_type' => get_class($e), 'message' => $e->getMessage()]);
                IncidentMonitor::report('security.rate_limit_storage_failed', 'security', 'critical', ['exception' => $e]);
            }
            return true;
        }
    }

    private static function rateKey(string $action, bool $bindIp): string {
        return hash('sha256', $action . '|' . ($bindIp ? self::clientIp() : 'global'));
    }

    /**
     * Checkout throttling shared by the HTML and JSON transports: per IP, per session, and the optional shop-wide
     * cap checkout_global_limit (attempts per minute, 0 = off). The cap was a hard-coded 200 that every attempt
     * counted towards, refused ones included: twenty addresses held it and locked every shopper out. It now
     * counts only attempts that passed the per-IP and per-session limits, and never records a refused one.
     */
    public static function checkoutAllowed(): bool {
        if (!self::rateLimit('checkout', 10)
            || !self::rateLimit('checkout_session_' . hash('sha256', session_id()), 5, 900, false)) {
            return false;
        }
        $cap = max(0, (int)(self::cfg()['checkout_global_limit'] ?? 0));
        if ($cap === 0) return true;
        if (!self::rateLimitAllows('checkout_global', $cap, 60, false)) {
            http_response_code(429);
            header('Retry-After: 60');
            return false;
        }
        self::rateLimitRecord('checkout_global', false);
        return true;
    }

    public static function rateLimit(string $action, int $maxHits = 60, int $windowSeconds = 60, bool $bindIp = true): bool {
        $windowSeconds = max(1, min(86400, $windowSeconds));
        $key = self::rateKey($action, $bindIp);
        $allowed = self::withRateDb(static function (PDO $db) use ($key, $windowSeconds, $maxHits): bool {
            $now = time();
            $db->prepare("INSERT INTO hits (key, ts) VALUES (:key, :ts)")
               ->execute([':key' => $key, ':ts' => $now]);
            $stmt = $db->prepare("SELECT COUNT(*) FROM hits WHERE key = :key AND ts > :window");
            $stmt->execute([':key' => $key, ':window' => $now - $windowSeconds]);
            return (int)$stmt->fetchColumn() <= max(0, $maxHits);
        });

        if (!$allowed) {
            http_response_code(429);
            header('Retry-After: ' . $windowSeconds);
            return false;
        }
        return true;
    }

    // ============================================================
    // Input sanitization
    // ============================================================
    public static function clean(mixed $input): string {
        return trim(strip_tags(self::str($input)));
    }

    /**
     * Scalar request value as a string. PHP turns name[]=x into an array; string-typed code
     * must see the default instead of crashing with a TypeError (HTTP 500).
     */
    public static function str(mixed $value, string $default = ''): string {
        if (is_string($value)) return $value;
        if (is_int($value) || is_float($value)) return (string)$value;
        return $default;
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
            $href = self::richUrl($node->getAttribute('href'));
            if ($href === null) {
                $node->removeAttribute('href');
            } else {
                $node->setAttribute('href', $href);
            }
        }
    }

    /**
     * Rich-text link target by prefix allow-list (parse_url finds no scheme in "javascript:80/x"): http(s)://,
     * mailto:, tel:, /path (not //), #, ? or a relative path without ':' before its first '/', '?' or '#'.
     * Whitespace and control characters, which browsers drop inside URLs, are removed before the check.
     */
    private static function richUrl(string $url): ?string {
        $url = (string)preg_replace('/[\x00-\x20\x7F]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_contains($url, '\\')) return null; // browsers read "\" as "/" ("/\host" is protocol-relative)
        return preg_match('~^(?:https?://|mailto:|tel:|/(?!/)|[#?]|[^:/?#]+(?:[/?#]|$))~i', $url) ? $url : null;
    }

    public static function slug(mixed $input): string {
        return Str::slug(self::str($input));
    }

    public static function int($input, int $default = 0): int {
        return filter_var($input, FILTER_VALIDATE_INT) !== false
            ? (int)$input : $default;
    }

    /** Positive ids of a posted list (name[]): a non-array, nested or non-numeric entry yields nothing, never a TypeError. */
    public static function intList(mixed $input): array {
        if (!is_array($input)) return [];
        $ids = array_map(static fn($id): int => is_scalar($id) ? self::int($id) : 0, $input);
        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }

    public static function email(mixed $input): ?string {
        $email = filter_var(trim(self::str($input)), FILTER_VALIDATE_EMAIL);
        return $email ?: null;
    }

    // ============================================================
    // IP address — behind proxy/load balancer
    // ============================================================
    /** Whether the client reached us over https: directly, or via a trusted proxy that says so in X-Forwarded-Proto. */
    public static function requestIsHttps(): bool {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') return true;
        $trusted = self::cfg()['trusted_proxies'] ?? [];
        if (empty($trusted) || !in_array($_SERVER['REMOTE_ADDR'] ?? '', $trusted, true)) return false;
        $proto = explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return strtolower(trim(end($proto))) === 'https';
    }

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

    /**
     * Role definitions: permissions for each role. Money-moving actions have their own
     * permissions: orders.payments (confirm/change payment), orders.refund (refunds, RMA
     * credit notes), payments.manage / shipping.manage (methods and fees), rma.manage.
     */
    private static array $rolePermissions = [
        'owner'       => ['*'],
        'manager'     => ['products.*', 'categories.edit', 'orders.*', 'customers.view', 'customers.edit', 'reports.view', 'gdpr.view', 'import', 'cache',
                          'payments.manage', 'shipping.manage', 'rma.manage', 'pricing.manage', 'emails.manage'],
        'editor'      => ['products.edit', 'products.view', 'categories.edit', 'media.upload'],
        'fulfillment' => ['orders.view', 'orders.edit', 'orders.ship', 'orders.print'],
        'viewer'      => ['products.view', 'orders.view', 'reports.view'],
    ];

    public static function adminAuth(): bool {
        $cfg = self::cfg();

        // Session fast-path: DB identities use revocable server-side sessions;
        // legacy config/JSON identities retain the existing idle timeout.
        self::sessionStart();
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_logout']) && self::csrfValidate()) {
            if (!empty($_SESSION['admin_session_id'])) AdminIdentity::revokeSession((string)$_SESSION['admin_session_id']);
            self::clearAdminSession();
            session_regenerate_id(true);
        }
        if (!empty($_SESSION['admin_identity_token'])) {
            $identity = AdminIdentity::session((string)$_SESSION['admin_identity_token']);
            if ($identity !== null) {
                $_SESSION['admin_authenticated'] = true;
                $_SESSION['admin_identity_id'] = $identity['account_id'];
                $_SESSION['admin_session_id'] = $identity['session_id'];
                $_SESSION['admin_user'] = $identity['username'];
                $_SESSION['admin_role'] = $identity['role'];
                $_SESSION['admin_store_permissions'] = $identity['store_permissions'];
                return true;
            }
        }
        $lastSeen = (int)($_SESSION['admin_last_seen'] ?? 0);
        if (empty($_SESSION['admin_identity_id']) && !empty($_SESSION['admin_authenticated']) && $lastSeen >= time() - 1800) {
            $_SESSION['admin_last_seen'] = time();
            return true;
        }
        self::clearAdminSession();

        if (AdminIdentity::accountCount() > 0) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_logout'])) {
                self::renderAdminLogin();
            }
            $isFormLogin = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login']);
            $user = (string)($isFormLogin ? ($_POST['admin_username'] ?? '') : ($_SERVER['PHP_AUTH_USER'] ?? ''));
            $pass = (string)($isFormLogin ? ($_POST['admin_password'] ?? '') : ($_SERVER['PHP_AUTH_PW'] ?? ''));
            $mfa = (string)($isFormLogin ? ($_POST['admin_mfa'] ?? '') : ($_SERVER['HTTP_X_ADMIN_MFA'] ?? ''));
            if ($isFormLogin && !self::csrfValidate()) self::renderAdminLogin('admin.login.error_form_expired');
            if ($user !== '' && $pass !== '') {
                $ip = self::clientIp();
                $throttle = new AdminLoginThrottle(self::rateDb());
                $retryAfter = $throttle->reserve($user, $ip);
                if ($retryAfter > 0) {
                    header('Retry-After: ' . $retryAfter);
                    self::renderAdminLogin('admin.login.error_throttled', 429);
                }
                $identity = AdminIdentity::authenticate($user, $pass, $mfa, $ip, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
                if ($identity !== null) {
                    $throttle->recordSuccess($user, $ip);
                    session_regenerate_id(true);
                    $_SESSION['admin_authenticated'] = true;
                    $_SESSION['admin_identity_token'] = $identity['token'];
                    $_SESSION['admin_identity_id'] = $identity['account_id'];
                    $_SESSION['admin_session_id'] = $identity['session_id'];
                    $_SESSION['admin_user'] = $identity['username'];
                    $_SESSION['admin_role'] = $identity['role'];
                    $_SESSION['admin_store_permissions'] = $identity['store_permissions'];
                    self::rotateCsrfToken();
                    if ($isFormLogin) {
                        header('Location: ' . self::safeLocalPath((string)($_POST['return_to'] ?? '/admin.php'), '/admin.php'));
                        exit;
                    }
                    return true;
                }
            }
            self::renderAdminLogin(($isFormLogin || $user !== '') ? 'admin.login.error_invalid' : null);
        }

        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
        $ip = self::clientIp();
        $throttle = new AdminLoginThrottle(self::rateDb());
        $retryAfter = $user !== '' ? $throttle->reserve($user, $ip) : 0;
        if ($retryAfter > 0) {
            http_response_code(429);
            header('Retry-After: ' . $retryAfter);
            exit('Too many login attempts. Try again later.');
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
            $throttle->recordSuccess($user, $ip);
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

        header('WWW-Authenticate: Basic realm="Shop Admin"');
        http_response_code(401);
        exit('Unauthorized');
    }

    private static function clearAdminSession(): void {
        unset(
            $_SESSION['admin_authenticated'], $_SESSION['admin_identity_token'],
            $_SESSION['admin_identity_id'], $_SESSION['admin_session_id'],
            $_SESSION['admin_store_permissions'], $_SESSION['admin_user'],
            $_SESSION['admin_role'], $_SESSION['admin_last_seen'], $_SESSION['admin_store']
        );
    }

    /**
     * Sign-in page (templates/admin/login.php) in the admin's last chosen language, else the system default.
     * $errorKey is an admin.login.error_* i18n key; null renders the plain form.
     */
    private static function renderAdminLogin(?string $errorKey = null, int $status = 0): never {
        self::headers();
        http_response_code($status ?: ($errorKey === null ? 401 : 403));
        $sessionLocale = $_SESSION['admin_locale'] ?? null;
        $locale = is_string($sessionLocale) && preg_match('/^[a-z]{2}(?:[_-][A-Za-z]{2})?$/', $sessionLocale) ? $sessionLocale : I18n::sysDefault();
        I18n::setLocale($locale);
        $view = [
            'locale' => $locale,
            'nonce' => self::cspNonce(),
            'csrf' => self::csrfToken(),
            'return_to' => self::safeLocalPath((string)($_SERVER['REQUEST_URI'] ?? '/admin.php'), '/admin.php'),
            'error' => $errorKey === null ? null : I18n::t($errorKey),
        ];
        require __DIR__ . '/../templates/admin/login.php';
        exit;
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

    /** Test hook: stands in for admin-roles.json (custom role permissions). */
    public static function setRolesForTesting(?array $rolesData): void {
        self::$cachedRolesData = $rolesData;
    }

    /** Permissions of a role: built-in definition, overridden by admin-roles.json (cached per request). */
    public static function adminRolePermissions(string $role): array {
        $perms = self::$rolePermissions[$role] ?? [];
        if (self::$cachedRolesData === null) {
            $rolesFile = __DIR__ . '/../admin-roles.json';
            self::$cachedRolesData = file_exists($rolesFile)
                ? (json_decode(file_get_contents($rolesFile), true) ?: [])
                : [];
        }
        if (isset(self::$cachedRolesData['roles'][$role]['permissions'])) {
            $perms = self::$cachedRolesData['roles'][$role]['permissions'];
            if (!is_array($perms)) return [];
        }
        return array_values(array_filter($perms, 'is_string'));
    }

    /**
     * True when the current admin holds every permission of $role (wildcards included), so
     * delegated admins.manage cannot create or take over an account more powerful than itself.
     */
    public static function adminCanAssumeRole(string $role): bool {
        if (self::adminRole() === 'owner') return true;
        if ($role === 'owner') return false;
        foreach (self::adminRolePermissions($role) as $perm) {
            if (!self::adminCan($perm)) return false;
        }
        return true;
    }

    /** Check if current admin has a permission */
    public static function adminCan(string $permission): bool {
        if (!empty($_SESSION['admin_identity_id'])) {
            $storeId = StoreContext::current()->id();
            $grants = is_array($_SESSION['admin_store_permissions'] ?? null) ? $_SESSION['admin_store_permissions'] : [];
            if (!AdminIdentity::storeAllows($grants, $storeId, $permission)) return false;
        }
        $perms = self::adminRolePermissions(self::adminRole());

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
