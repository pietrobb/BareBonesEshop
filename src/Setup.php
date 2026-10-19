<?php
/** Idempotent merchant onboarding checks and safe runtime-directory application. */
class Setup
{
    public const SCHEMA_VERSION = 1;
    public const SUBMODULE_ACTION = 'Run git submodule update --init (or clone with --recurse-submodules).';
    // Hosting document roots (cPanel, Plesk, XAMPP, IIS, Apache, Wedos, Websupport). /var/www itself is not served.
    private const WEB_ROOT_NAMES = ['public_html', 'httpdocs', 'htdocs', 'wwwroot', 'html', 'www', 'web'];

    /**
     * The served directory above the application (installed into a subdirectory such as public_html/shop),
     * or null when the application root is the document root. operations.document_root overrides the guess.
     */
    public static function enclosingWebRoot(string $root, array $config = []): ?string
    {
        $normalize = static fn(string $path): string => rtrim(str_replace('\\', '/', $path), '/');
        $app = $normalize($root);
        $declared = trim((string)($config['operations']['document_root'] ?? ''));
        if ($declared !== '') {
            $declared = $normalize($declared);
            return strtolower($declared) !== strtolower($app) && str_starts_with(strtolower($app), strtolower($declared) . '/') ? $declared : null;
        }
        for ($dir = $normalize(dirname($app)); $dir !== $normalize(dirname($dir)) && $dir !== ''; $dir = $normalize(dirname($dir))) {
            if (in_array(strtolower(basename($dir)), self::WEB_ROOT_NAMES, true) && strtolower($dir) !== '/var/www') return $dir;
        }
        return null;
    }

    public static function bundledFormsPresent(string $root): bool
    {
        return is_file($root . '/vendor/bbf/bbf_render.php');
    }

    /**
     * Creates config.php from config.example.php with a fresh admin_identity_key.
     * An existing config.php is never touched.
     */
    public static function createConfig(?string $root = null, string $appUrl = ''): array
    {
        $root ??= dirname(__DIR__);
        $target = $root . '/config.php';
        if (is_file($target)) return ['path' => 'config.php', 'action' => 'unchanged'];
        if ($appUrl !== '' && (!filter_var($appUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new InvalidArgumentException('--app-url must be an absolute http(s) URL');
        }
        $template = @file_get_contents($root . '/config.example.php');
        if (!is_string($template)) throw new RuntimeException('config.example.php is missing');
        $config = $template;
        // In a subdirectory of a web root the template's ../bareboneseshop-private would be served: keep it above the web root.
        $webRoot = self::enclosingWebRoot($root);
        $privateBase = $webRoot === null ? null : dirname($webRoot) . '/bareboneseshop-data/' . basename(str_replace('\\', '/', $root));
        if ($privateBase !== null) $config = str_replace('dirname(__DIR__)', var_export($privateBase, true), $config);
        foreach (['admin_identity_key' => base64_encode(random_bytes(32)), 'app_url' => rtrim($appUrl, '/')] as $key => $value) {
            if ($value === '') continue;
            $count = 0;
            $config = preg_replace("/('" . $key . "'\s*=>\s*)''/", '${1}' . var_export($value, true), (string)$config, 1, $count);
            if (!is_string($config) || $count !== 1) throw new RuntimeException("config.example.php has no {$key} entry");
        }
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $config, LOCK_EX) === false) throw new RuntimeException('Cannot write config.php');
        @chmod($tmp, 0640);
        if (!is_array(require $tmp)) {
            @unlink($tmp);
            throw new RuntimeException('Generated config.php does not return an array');
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot write config.php');
        }
        return ['path' => 'config.php', 'action' => 'created', 'generated' => ['admin_identity_key'], 'app_url' => rtrim($appUrl, '/')]
            + ($privateBase !== null ? ['private_base' => $privateBase, 'web_root' => $webRoot] : []);
    }

    public static function doctor(?array $config = null, ?string $root = null, ?PDO $db = null): array
    {
        $root ??= dirname(__DIR__);
        $config ??= require $root . '/config.php';
        $checks = [];
        $add = static function (string $status, string $name, string $message, string $action = '') use (&$checks): void {
            $checks[] = compact('status', 'name', 'message', 'action');
        };

        $add(version_compare(PHP_VERSION, '8.1.0', '>=') ? 'pass' : 'fail', 'runtime.php', 'PHP ' . PHP_VERSION, 'Install PHP 8.1 or newer.');
        $bbf = self::bundledFormsPresent($root);
        $add($bbf ? 'pass' : 'fail', 'runtime.vendor_bbf', 'BareBonesForms submodule ' . ($bbf ? 'present' : 'missing'), self::SUBMODULE_ACTION);
        foreach (['pdo', 'json'] as $extension) {
            $add(extension_loaded($extension) ? 'pass' : 'fail', 'runtime.extension.' . $extension, $extension . (extension_loaded($extension) ? ' loaded' : ' missing'), "Enable PHP extension {$extension}.");
        }

        $schemaPath = $root . '/config/config.schema.json';
        $schema = is_file($schemaPath) ? json_decode((string)file_get_contents($schemaPath), true) : null;
        $add(is_array($schema) && ($schema['properties']['db_driver']['enum'] ?? null) === ['sqlite', 'mysql'] ? 'pass' : 'fail', 'config.schema', 'Machine-readable config schema ' . (is_array($schema) ? 'loaded' : 'missing or invalid'), 'Restore config/config.schema.json from the release.');
        $driver = strtolower((string)($config['db_driver'] ?? ''));
        $add(in_array($driver, ['sqlite', 'mysql'], true) ? 'pass' : 'fail', 'config.db_driver', $driver !== '' ? "Database driver: {$driver}" : 'Database driver missing', 'Set db_driver to sqlite or mysql.');
        $appUrl = (string)($config['app_url'] ?? '');
        $add($appUrl !== '' ? 'pass' : 'warn', 'config.app_url', $appUrl !== '' ? 'Public base URL configured.' : 'app_url is empty: sitemap, password reset and e-mail links are unavailable.', $appUrl !== '' ? '' : "Set app_url in config.php (php cli/setup.php install --app-url=URL sets it on a new install).");

        $storesPath = $root . '/stores.json';
        $stores = is_file($storesPath) ? json_decode((string)file_get_contents($storesPath), true) : null;
        $defaultStore = is_array($stores) ? (string)($stores['default_store'] ?? '') : '';
        $storeOk = is_array($stores) && isset($stores['stores'][$defaultStore]);
        $add($storeOk ? 'pass' : 'fail', 'stores.default', $storeOk ? "Default store: {$defaultStore}" : 'stores.json has no valid default store', 'Define default_store and its store record.');
        // Invoice / credit-note series that would issue the same numbers are refused at issue time: report them now.
        $numbering = is_array($stores) ? InvoiceService::numberingConflicts($stores) : [];
        $add($numbering === [] ? 'pass' : 'fail', 'stores.invoice_numbering', $numbering === []
            ? 'Every seller has its own invoice and credit-note number series.'
            : 'Invoice numbering conflicts (these series are refused): ' . implode(' | ', array_map(static fn(string $series, string $message): string => "{$series}: {$message}", array_keys($numbering), $numbering)),
            'Give each store with its own legal block its own legal.invoice_prefix / legal.credit_note_prefix, or set legal.seller to the store whose series a storefront of the same company shares.');

        $webRoot = self::enclosingWebRoot($root, $config);
        foreach (self::runtimePaths($config) as $name => $path) {
            $target = str_ends_with($name, '_file') ? dirname($path) : $path;
            if ($webRoot !== null && self::isPrivateAbsolutePath($target, $root) && !self::isPrivateAbsolutePath($target, $webRoot)) {
                $add('fail', 'runtime.' . $name, "Private path inside the web root {$webRoot} (downloadable over HTTP): {$name}", "Move {$name} above {$webRoot} in config.php (or set operations.document_root if {$webRoot} is not served).");
                continue;
            }
            $ready = self::isPrivateAbsolutePath($target, $root) && is_dir($target) && is_writable($target);
            $add($ready ? 'pass' : 'fail', 'runtime.' . $name, $ready ? "Writable private path configured: {$name}" : "Private path missing, unsafe, or not writable: {$name}", 'Run php cli/setup.php apply, then set ownership for the PHP user.');
        }
        foreach (['cache_dir' => $root . '/cache', 'uploads_dir' => $root . '/uploads'] as $name => $default) {
            $dir = (string)($config[$name] ?? $default);
            $add(is_dir($dir) && is_writable($dir) ? 'pass' : 'fail', 'runtime.' . $name, (is_dir($dir) && is_writable($dir) ? 'Writable: ' : 'Missing or not writable by the PHP user: ') . $name, "Create {$dir} and make it writable by the PHP user.");
        }

        try {
            $probe = $db ?? Db::get();
            $probe->query('SELECT 1')->fetchColumn();
            $add('pass', 'database.connection', 'Database connection succeeds.');
            try {
                $probe->query('SELECT 1 FROM orders LIMIT 1');
                $add('pass', 'database.schema', 'Commerce schema is available.');
            } catch (Throwable $e) {
                $add('fail', 'database.schema', 'Commerce schema is not ready.', 'Run php cli/migrate.php.');
            }
            self::adminChecks($config, $add);
        } catch (Throwable $e) {
            $add('fail', 'database.connection', 'Database connection failed.', 'Verify database credentials/path and run migrations.');
        }

        self::providerChecks($config, $add);
        self::operationsChecks($config, $root, $add);

        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($checks as $check) $counts[$check['status']]++;
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $counts['fail'] === 0 ? 'ready' : 'needs_attention',
            'counts' => $counts,
            'checks' => $checks,
            'next_command' => $counts['fail'] === 0 ? 'php cli/setup.php doctor --json --strict' : 'php cli/setup.php apply --json',
        ];
    }

    public static function apply(?array $config = null, ?string $root = null): array
    {
        $root ??= dirname(__DIR__);
        $config ??= require $root . '/config.php';
        $changes = [];
        foreach (self::runtimePaths($config) as $name => $path) {
            $target = str_ends_with($name, '_file') ? dirname($path) : $path;
            if (!self::isPrivateAbsolutePath($target, $root)) {
                throw new RuntimeException("Refusing unsafe runtime path: {$name}");
            }
            if (!is_dir($target)) {
                if (!mkdir($target, 0750, true) && !is_dir($target)) throw new RuntimeException("Cannot create runtime path: {$name}");
                $changes[] = ['path' => $name, 'action' => 'created'];
            } else {
                $changes[] = ['path' => $name, 'action' => 'unchanged'];
            }
        }
        // uploads/ is served to visitors (0755); cache/ only to PHP.
        foreach (['cache_dir' => [$root . '/cache', 0750], 'uploads_dir' => [$root . '/uploads', 0755]] as $name => [$default, $mode]) {
            $dir = (string)($config[$name] ?? $default);
            if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) throw new RuntimeException("Cannot create runtime path: {$name}");
        }
        $stateDir = (string)($config['private_runtime_dir'] ?? '');
        $statePath = rtrim($stateDir, '/\\') . '/setup-state.json';
        $existingState = is_file($statePath) ? json_decode((string)file_get_contents($statePath), true) : null;
        $stateCurrent = is_array($existingState)
            && ($existingState['schema_version'] ?? null) === self::SCHEMA_VERSION
            && ($existingState['application'] ?? null) === 'BareBonesEshop';
        if (!$stateCurrent) {
            $state = ['schema_version' => self::SCHEMA_VERSION, 'applied_at' => gmdate('c'), 'application' => 'BareBonesEshop'];
            if (file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
                throw new RuntimeException('Cannot write private setup state');
            }
            @chmod($statePath, 0640);
        }
        return ['status' => 'applied', 'changes' => $changes, 'state_file' => $statePath, 'state_action' => $stateCurrent ? 'unchanged' : 'created'];
    }

    private static function providerChecks(array $config, callable $add): void
    {
        $payment = (string)($config['provider_defaults']['payment'] ?? 'stripe');
        $mail = self::mailProvider($config);
        $add($payment !== '' ? 'pass' : 'fail', 'provider.payment', $payment !== '' ? "Payment provider selected: {$payment}" : 'Payment provider missing.', 'Select provider_defaults.payment.');
        $add($mail === 'log' ? 'warn' : 'pass', 'provider.mail', "Mail provider selected: {$mail}", $mail === 'log' ? 'Configure smtp or ses and send a staging message before launch.' : '');
        $webhooks = array_filter((array)($config['outbound_webhooks'] ?? []), static fn($endpoint): bool => is_array($endpoint) && ($endpoint['active'] ?? true));
        $add($webhooks === [] ? 'warn' : 'pass', 'provider.webhook', $webhooks === [] ? 'No active outbound webhook configured.' : count($webhooks) . ' active outbound webhook endpoint(s).', 'Configure one signed staging endpoint and verify a delivery audit row.');
        $invalidWebhooks = Webhook::invalidEndpoints($config);
        $add($invalidWebhooks === [] ? 'pass' : 'fail', 'provider.webhook_config', $invalidWebhooks === []
            ? 'Outbound webhook endpoints are valid.'
            : 'Invalid outbound webhook endpoints: ' . implode('; ', array_map(static fn(string $code, string $problem): string => "{$code}: {$problem}", array_keys($invalidWebhooks), $invalidWebhooks)),
            'Use https URLs (allow_insecure_http only for a local receiver), secrets of 32+ bytes and codes of a-z, 0-9, "_" and "-".');
        self::upgradeChecks($config, $add);
        Extensions::boot($config);
        $extensionErrors = Extensions::errors();
        $add($extensionErrors === [] ? 'pass' : 'fail', 'extensions', $extensionErrors === []
            ? count(Extensions::loaded()) . ' extension(s) loaded.'
            : 'Extensions not loaded: ' . implode('; ', array_map(static fn($code, string $error): string => "{$code}: {$error}", array_keys($extensionErrors), $extensionErrors)),
            'Fix or disable the listed extensions (config "extensions", directory extensions/<code>/).');

        $shipping = (array)($config['integrations']['http_shipping'] ?? []);
        $shippingReady = self::integrationReady($shipping);
        $add($shippingReady ? 'pass' : 'warn', 'provider.shipping_lifecycle', $shippingReady ? 'HTTP carrier lifecycle configured.' : 'HTTP carrier lifecycle not configured.', 'Configure integrations.http_shipping and run fixture/live sandbox smoke.');
        $erp = (array)($config['integrations']['http_erp'] ?? []);
        $erpReady = self::integrationReady($erp);
        $add($erpReady ? 'pass' : 'warn', 'provider.erp', $erpReady ? 'HTTP ERP contract configured.' : 'HTTP ERP contract not configured.', 'Configure integrations.http_erp and verify create/status update in staging.');
    }

    /** Settings that silently change behaviour after the upgrade (UpgradeChecks, CHANGELOG upgrade notes). */
    private static function upgradeChecks(array $config, callable $add): void
    {
        $insecure = UpgradeChecks::insecureHttpWebhooks($config);
        $add($insecure === [] ? 'pass' : 'warn', 'upgrade.webhook_http', $insecure === []
            ? 'No outbound webhook relies on plain http.'
            : 'Outbound webhooks over plain http are no longer delivered: ' . implode(', ', array_keys($insecure)),
            'Switch the endpoint to https, or set allow_insecure_http => true only for a receiver on a trusted local network.');
        $reminderBroken = UpgradeChecks::cartReminderWithoutConsent($config);
        $add($reminderBroken ? 'warn' : 'pass', 'upgrade.cart_reminder_consent', $reminderBroken
            ? 'cart_reminder is enabled but checkout_consents has no active cart_reminder consent: no reminder is stored or sent.'
            : 'Cart reminder consent configuration is consistent.',
            "Add checkout_consents['cart_reminder'] (version, text, required => false) or disable cart_reminder.");
        $nginx = UpgradeChecks::nginxPhpFallback($config);
        if ($nginx !== null) {
            $add($nginx ? 'pass' : 'warn', 'upgrade.nginx_php_fallback', $nginx
                ? 'nginx sends missing .php scripts to @redirect (store-prefixed scripts work).'
                : 'nginx PHP location has no "try_files $uri @redirect;": store-prefixed scripts (/cs/account.php, /cs/wishlist.php) answer 404.',
                'Copy the location ~ \.php$ block from deploy/nginx.conf into ' . (string)($config['operations']['nginx_config'] ?? 'the nginx site config') . ' and reload nginx.');
        }
    }

    /** The provider MailService really uses: MAIL_ADAPTER wins over provider_defaults.mail. */
    public static function mailProvider(array $config): string
    {
        return strtolower((string)(getenv('MAIL_ADAPTER') ?: ($config['provider_defaults']['mail'] ?? '') ?: 'log'));
    }

    /** Somebody can sign in to the back-office: an active account (or the legacy hash) and an MFA key that opens its secret. */
    private static function adminChecks(array $config, callable $add): void
    {
        if (!AdminIdentity::available()) return;
        $total = AdminIdentity::accountCount();
        $active = AdminIdentity::activeCount();
        $canSignIn = $total === 0 ? (string)($config['admin_password'] ?? '') !== '' : $active > 0;
        $add($canSignIn ? 'pass' : 'fail', 'admin.accounts', $canSignIn ? "{$active} active admin account(s)." : ($total === 0 ? 'No admin account: nobody can sign in to the back-office.' : 'Every admin account is disabled.'),
            $canSignIn ? '' : 'BBE_ADMIN_PASSWORD=... php cli/admin-user.php create --username=NAME --role=owner (or status --status=active).');
        $locked = AdminIdentity::undecryptableMfaAccounts();
        $add($locked === [] ? 'pass' : 'fail', 'admin.mfa_key', $locked === []
            ? 'admin_identity_key opens the MFA secret of every active admin.'
            : 'admin_identity_key cannot decrypt the MFA secret of ' . implode(', ', $locked) . ': they cannot sign in (config.php regenerated or key changed).',
            $locked === [] ? '' : 'Restore admin_identity_key from the previous config.php (or BBE_ADMIN_IDENTITY_KEY); if it is lost, re-enrol each account: php cli/admin-user.php mfa-enroll --username=NAME.');
    }

    private static function integrationReady(array $integration): bool
    {
        $url = (string)($integration['base_url'] ?? '');
        return filter_var($url, FILTER_VALIDATE_URL)
            && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https'
            && strlen((string)($integration['api_key'] ?? '')) >= 16;
    }

    private static function operationsChecks(array $config, string $root, callable $add): void
    {
        $add(is_file($root . '/cli/worker.php') ? 'pass' : 'fail', 'operations.worker', 'Worker entry point ' . (is_file($root . '/cli/worker.php') ? 'present' : 'missing'), 'Restore cli/worker.php.');
        $cron = trim((string)($config['operations']['worker_cron'] ?? ''));
        $add(preg_match('/^(?:\S+\s+){4}\S+$/', $cron) ? 'pass' : 'warn', 'operations.cron', $cron !== '' ? 'Worker cron declaration configured.' : 'Worker cron declaration not recorded.', 'Set operations.worker_cron and install the equivalent scheduler entry.');
        $backupPath = $root . '/config/backup.json';
        $backup = is_file($backupPath) ? json_decode((string)file_get_contents($backupPath), true) : null;
        $add(is_array($backup) && !empty($backup['enabled']) ? 'pass' : 'fail', 'operations.backup', 'Backup configuration ' . (is_array($backup) && !empty($backup['enabled']) ? 'enabled' : 'missing or disabled'), 'Enable config/backup.json and run a restore probe.');
        $add(is_file($root . '/health.php') ? 'pass' : 'fail', 'operations.health', 'Health/readiness endpoint ' . (is_file($root . '/health.php') ? 'present' : 'missing'), 'Restore health.php.');
    }

    private static function runtimePaths(array $config): array
    {
        $paths = [
            'private_runtime_dir' => (string)($config['private_runtime_dir'] ?? ''),
            'private_cache_dir' => (string)($config['private_cache_dir'] ?? ''),
            'jobs_dir' => (string)($config['jobs_dir'] ?? ''),
            'invoice_storage_dir' => (string)($config['invoice_storage_dir'] ?? ''),
            'backup_storage_dir' => (string)($config['backup_storage_dir'] ?? ''),
            'backup_log_file' => (string)($config['backup_log_file'] ?? ''),
            'structured_log_file' => (string)($config['structured_log_file'] ?? rtrim((string)($config['private_runtime_dir'] ?? ''), '/\\') . '/logs/application.jsonl'),
        ];
        if (($config['db_driver'] ?? 'sqlite') === 'sqlite') {
            $paths = ['db_dir' => dirname((string)($config['db_sqlite_path'] ?? ''))] + $paths;
        }
        return $paths;
    }

    private static function isPrivateAbsolutePath(string $path, string $root): bool
    {
        if ($path === '' || preg_match('/[\x00-\x1F\x7F]/', $path)) return false;
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
        if (!$absolute) return false;
        $normalized = strtolower(rtrim(str_replace('\\', '/', $path), '/'));
        $app = strtolower(rtrim(str_replace('\\', '/', $root), '/'));
        return $normalized !== $app && !str_starts_with($normalized, $app . '/');
    }
}
