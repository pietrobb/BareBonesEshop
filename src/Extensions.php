<?php
/**
 * Extensions: shop-specific code in extensions/<code>/ that plugs into the core registries —
 * providers (payment, shipping, mail, ...), domain event listeners, storefront view-model data,
 * storefront pages, JSON API actions and its own database migrations (extensions/<code>/migrations,
 * applied by `php cli/migrate.php`) — without editing core files. Only codes listed in config
 * 'extensions' are loaded.
 * The updater never writes to extensions/ and the drift guard does not hash it.
 *
 * extensions/<code>/extension.php returns ['code' => '<code>', 'name' => ..., 'version' => ...,
 * 'register' => static function (ExtensionApi $api): void { ... }]; classes are autoloaded from
 * extensions/<code>/src/<Class>.php. A broken extension is skipped as a whole and reported by
 * errors(), the log and `cli/setup.php doctor`; the shop and the other extensions keep running.
 */
final class Extensions
{
    public const DIRECTORY = 'extensions';

    private static bool $booted = false;
    private static array $loaded = [];
    private static array $providers = [];
    private static array $listeners = [];
    private static array $storefront = [];
    private static array $pages = [];
    private static array $api = [];
    private static array $roots = [];
    private static array $errors = [];
    private static array $autoloaders = [];

    /** Load the enabled extensions once per process. $config/$directory default to config.php and <root>/extensions. */
    public static function boot(?array $config = null, ?string $directory = null): void
    {
        if (self::$booted) return;
        self::$booted = true;
        $config ??= self::config();
        $directory ??= dirname(__DIR__) . '/' . self::DIRECTORY;
        $enabled = $config['extensions'] ?? [];
        if (!is_array($enabled) || !array_is_list($enabled)) {
            self::fail('*', 'config "extensions" must be a list of extension codes');
            return;
        }
        foreach ($enabled as $code) {
            $code = is_string($code) ? $code : '';
            try {
                self::load($code, rtrim($directory, '/\\'));
            } catch (Throwable $e) {
                self::fail($code, $e->getMessage());
            }
        }
    }

    /** @return array<string,array{code:string,name:string,version:string}> */
    public static function loaded(): array
    {
        self::boot();
        return self::$loaded;
    }

    /** @return array<string,string> extension code => readable reason it was not loaded */
    public static function errors(): array
    {
        self::boot();
        return self::$errors;
    }

    /** @return array<string,array<string,array>> provider type => code => definition */
    public static function providers(): array
    {
        self::boot();
        return self::$providers;
    }

    /** Register the extensions' storefront view-model providers (StorefrontComposition::boot). */
    public static function registerStorefront(): void
    {
        self::boot();
        foreach (self::$storefront as [$context, $namespace, $provider]) {
            if (!StorefrontViewModelRegistry::has($context, $namespace)) {
                StorefrontViewModelRegistry::register($context, $namespace, $provider);
            }
        }
    }

    /**
     * Render the extension page for a storefront ?page= value, or null when no extension owns it.
     * @return array{title:string, content:string}|null
     */
    public static function page(string $route): ?array
    {
        self::boot();
        if (!isset(self::$pages[$route])) return null;
        $result = (self::$pages[$route])($_GET);
        if (!is_array($result) || !is_string($result['title'] ?? null) || !is_string($result['content'] ?? null)) {
            throw new UnexpectedValueException("extension page {$route} must return ['title' => string, 'content' => string]");
        }
        return ['title' => $result['title'], 'content' => $result['content']];
    }

    /**
     * Run the extension JSON API action for ?action=, or null when no extension owns it. The
     * action's declared method is enforced (405) and a non-GET/HEAD request needs the session
     * CSRF token unless the action registered 'csrf' => false (403).
     */
    public static function api(string $action): ?array
    {
        self::boot();
        if (!isset(self::$api[$action])) return null;
        $definition = self::$api[$action];
        $requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $declared = $definition['method'];
        if ($declared !== null && $requestMethod !== $declared && !($declared === 'GET' && $requestMethod === 'HEAD')) {
            http_response_code(405);
            if (!headers_sent()) header('Allow: ' . ($declared === 'GET' ? 'GET, HEAD' : $declared));
            return ['error' => 'Method not allowed'];
        }
        if (!in_array($requestMethod, ['GET', 'HEAD'], true) && $definition['csrf'] && !Security::csrfValidate()) {
            http_response_code(403);
            return ['error' => 'Invalid CSRF token'];
        }
        $result = ($definition['handler'])($_GET);
        if (!is_array($result)) throw new UnexpectedValueException("extension API action {$action} must return an array");
        return $result;
    }

    /**
     * One migration runner per loaded extension that ships a migrations/ directory. Files use the
     * core format (<TIMESTAMP>_<name>.php returning an object with up(PDO, bool)); they are recorded
     * as "<code>/<file>" in extension_migrations and are checksummed like core migrations.
     * @return array<string,Migrator> extension code => runner, in config order
     */
    public static function migrators(?PDO $db = null, ?string $lockFile = null): array
    {
        self::boot();
        $migrators = [];
        foreach (self::$roots as $code => $root) {
            $directory = $root . '/migrations';
            if (!is_dir($directory) || is_link($directory)) continue;
            $migrators[$code] = new Migrator($db, $directory, $lockFile, 'extension_migrations', $code . '/');
        }
        return $migrators;
    }

    /**
     * Run the listeners matching one claimed outbox event. Each listener is its own delivery
     * endpoint (ext:<code>:<name>) in webhook_deliveries, so a retry skips the ones that succeeded.
     * @return array{matched:int, failures:list<string>}
     */
    public static function deliver(array $event, array $envelope): array
    {
        self::boot();
        $matched = 0;
        $failures = [];
        foreach (self::$listeners as $endpoint => $listener) {
            if (!Webhook::endpointAccepts(['events' => [$listener['events']]], (string)$event['event_type'])) continue;
            $matched++;
            if (Outbox::endpointDelivered((string)$event['id'], $endpoint)) continue;
            $error = null;
            try {
                ($listener['listener'])($envelope);
            } catch (Throwable $e) {
                $error = get_class($e) . ': ' . $e->getMessage();
                Logger::exception('extension.listener_failed', $e, ['endpoint' => $endpoint, 'event_id' => (string)$event['id']]);
            }
            Outbox::recordDelivery((string)$event['id'], $endpoint, (int)$event['attempts'], $error === null, null, $error);
            if ($error !== null) $failures[] = $endpoint . ':' . $error;
        }
        return ['matched' => $matched, 'failures' => $failures];
    }

    /** Forget everything loaded (tests). Registered autoloaders are removed as well. */
    public static function reset(): void
    {
        foreach (self::$autoloaders as $autoloader) spl_autoload_unregister($autoloader);
        self::$booted = false;
        self::$loaded = self::$providers = self::$listeners = self::$storefront = self::$pages = self::$api = self::$roots = self::$errors = self::$autoloaders = [];
    }

    private static function load(string $code, string $directory): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $code)) {
            throw new InvalidArgumentException('invalid extension code (use a-z, 0-9 and "-", 2-31 characters, starting with a letter)');
        }
        if (isset(self::$loaded[$code]) || isset(self::$errors[$code])) throw new RuntimeException('listed twice in config "extensions"');
        $root = $directory . '/' . $code;
        $file = $root . '/extension.php';
        if (!is_file($file) || is_link($file) || is_link($root)) {
            throw new RuntimeException('extension.php not found in ' . self::DIRECTORY . "/{$code}/");
        }
        $manifest = (static fn(string $path): mixed => require $path)($file);
        if (!is_array($manifest)) throw new RuntimeException('extension.php must return a manifest array');
        if (($manifest['code'] ?? null) !== $code) throw new RuntimeException("manifest code must be \"{$code}\"");
        if (!isset($manifest['register']) || !is_callable($manifest['register'])) {
            throw new RuntimeException('manifest needs a callable "register" (static function (ExtensionApi $api): void)');
        }

        $classes = $root . '/src';
        $autoloader = static function (string $class) use ($classes): void {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) return;
            $path = $classes . '/' . $class . '.php';
            if (is_file($path) && !is_link($path)) require_once $path;
        };
        spl_autoload_register($autoloader);
        try {
            $api = new ExtensionApi($code);
            ($manifest['register'])($api);
            $registrations = $api->registrations();
        } catch (Throwable $e) {
            spl_autoload_unregister($autoloader);
            throw $e;
        }

        $collisions = array_intersect_key($registrations['listeners'], self::$listeners);
        if ($collisions !== []) {
            spl_autoload_unregister($autoloader);
            throw new RuntimeException('listener endpoint already registered: ' . implode(', ', array_keys($collisions)));
        }

        // All-or-nothing: nothing above is visible to the shop until register() fully succeeded.
        self::$autoloaders[] = $autoloader;
        foreach ($registrations['providers'] as $type => $definitions) {
            foreach ($definitions as $providerCode => $definition) self::$providers[$type][$providerCode] = $definition;
        }
        self::$listeners += $registrations['listeners'];
        array_push(self::$storefront, ...$registrations['storefront']);
        self::$pages += $registrations['pages'];
        self::$api += $registrations['api'];
        self::$roots[$code] = $root;
        self::$loaded[$code] = [
            'code' => $code,
            'name' => (string)($manifest['name'] ?? $code),
            'version' => (string)($manifest['version'] ?? ''),
        ];
    }

    private static function fail(string $code, string $reason): void
    {
        $key = preg_match('/^[a-z][a-z0-9-]{1,30}$/', $code) && !isset(self::$errors[$code]) ? $code : '#' . count(self::$errors);
        self::$errors[$key] = $reason;
        Logger::error('extension.load_failed', ['extension' => $code, 'error' => $reason]);
    }

    private static function config(): array
    {
        $file = dirname(__DIR__) . '/config.php';
        $config = is_file($file) ? require $file : [];
        return is_array($config) ? $config : [];
    }
}
