<?php
/** Idempotent merchant onboarding checks and safe runtime-directory application. */
class Setup
{
    public const SCHEMA_VERSION = 1;
    public const SUBMODULE_ACTION = 'Run git submodule update --init (or clone with --recurse-submodules).';

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
        return ['path' => 'config.php', 'action' => 'created', 'generated' => ['admin_identity_key'], 'app_url' => rtrim($appUrl, '/')];
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

        foreach (self::runtimePaths($config) as $name => $path) {
            $target = str_ends_with($name, '_file') ? dirname($path) : $path;
            $safe = self::isPrivateAbsolutePath($target, $root);
            $ready = $safe && is_dir($target) && is_writable($target);
            $add($ready ? 'pass' : 'fail', 'runtime.' . $name, $ready ? "Writable private path configured: {$name}" : "Private path missing, unsafe, or not writable: {$name}", 'Run php cli/setup.php apply, then set ownership for the PHP user.');
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
        $mail = (string)($config['provider_defaults']['mail'] ?? (getenv('MAIL_ADAPTER') ?: 'log'));
        $add($payment !== '' ? 'pass' : 'fail', 'provider.payment', $payment !== '' ? "Payment provider selected: {$payment}" : 'Payment provider missing.', 'Select provider_defaults.payment.');
        $add($mail === 'log' ? 'warn' : 'pass', 'provider.mail', "Mail provider selected: {$mail}", $mail === 'log' ? 'Configure smtp or ses and send a staging message before launch.' : '');
        $webhooks = array_filter((array)($config['outbound_webhooks'] ?? []), static fn($endpoint): bool => is_array($endpoint) && ($endpoint['active'] ?? true));
        $add($webhooks === [] ? 'warn' : 'pass', 'provider.webhook', $webhooks === [] ? 'No active outbound webhook configured.' : count($webhooks) . ' active outbound webhook endpoint(s).', 'Configure one signed staging endpoint and verify a delivery audit row.');
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
