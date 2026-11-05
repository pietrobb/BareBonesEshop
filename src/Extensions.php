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
 * errors(), the log and `cli/setup.php doctor`; the shop and the other extensions keep running. A parse, compile or
 * class-linking error in an extension's file quarantines that extension (quarantineOnFatal, a file in
 * private_runtime_dir) until its PHP files change; limits and exceptions a request can provoke never do.
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
    /** @var array<string,list<string>> enabled code => its root as given and resolved (fatal-error guard) */
    private static array $guarded = [];
    private static string $quarantineFile = '';
    private static bool $guardRegistered = false;

    /** Load the enabled extensions once per process. $config/$directory default to config.php and <root>/extensions. */
    public static function boot(?array $config = null, ?string $directory = null): void
    {
        if (self::$booted) return;
        self::$booted = true;
        $config ??= self::config();
        $directory = rtrim($directory ?? dirname(__DIR__) . '/' . self::DIRECTORY, '/\\');
        $enabled = $config['extensions'] ?? [];
        if (!is_array($enabled) || !array_is_list($enabled)) {
            self::fail('*', 'config "extensions" must be a list of extension codes');
            return;
        }
        if ($enabled === []) return;
        // R15: an error PHP cannot catch (a provider class missing its interface's methods, a class declared twice)
        // ended every request of the shop. The request that hits one still fails, but the extension whose file raised
        // it is quarantined: later requests skip it like any broken extension until its files change.
        self::$quarantineFile = self::quarantinePath($config);
        if (!self::$guardRegistered) {
            register_shutdown_function([self::class, 'quarantineOnFatal']);
            self::$guardRegistered = true;
        }
        $quarantine = self::readQuarantine();
        foreach ($enabled as $code) {
            $code = is_string($code) ? $code : '';
            $root = $directory . '/' . $code;
            try {
                if (isset($quarantine[$code]) && preg_match('/^[a-z][a-z0-9-]{1,30}$/', $code)) {
                    if (($quarantine[$code]['fingerprint'] ?? '') === self::fingerprint($root)) {
                        throw new RuntimeException('quarantined after a fatal error at ' . date('Y-m-d H:i:s', (int)($quarantine[$code]['at'] ?? 0))
                            . ': ' . (string)($quarantine[$code]['error'] ?? '') . ' (changing one of the extension\'s PHP files retries it)');
                    }
                }
                if (preg_match('/^[a-z][a-z0-9-]{1,30}$/', $code)) self::$guarded[$code] = array_unique([self::normalize($root), self::normalize((string)(realpath($root) ?: $root))]);
                self::load($code, $directory);
                if (isset($quarantine[$code])) self::writeQuarantine($code, null);
            } catch (Throwable $e) {
                self::fail($code, $e->getMessage());
            }
        }
    }

    /**
     * Shutdown guard: a fatal error raised in an enabled extension's file quarantines that extension. Memory and
     * time limits are not the extension's fault alone and do not.
     * @internal registered by boot()
     */
    public static function quarantineOnFatal(): void
    {
        $error = error_get_last();
        if ($error === null || !self::brokenCode((int)$error['type'], (string)$error['message'])) return;
        $file = self::normalize((string)$error['file']);
        foreach (self::$guarded as $code => $roots) {
            foreach ($roots as $root) {
                if (!str_starts_with($file, $root . '/')) continue;
                $message = $error['message'] . ' in ' . substr($file, strlen($root) + 1) . ':' . $error['line'];
                self::writeQuarantine($code, ['error' => $message, 'at' => time(), 'fingerprint' => self::fingerprint($root)]);
                Logger::error('extension.quarantined', ['extension' => $code, 'error' => $message]);
                return;
            }
        }
    }

    /**
     * Only an error in the extension's code itself quarantines it: a parse or compile error, or a class that cannot be
     * linked (a provider missing its interface's methods, an incompatible signature). Everything else a request can
     * provoke with its input: an uncaught exception (R16), a memory, time or stack limit, "Out of memory" (R17).
     */
    private static function brokenCode(int $type, string $message): bool
    {
        if (in_array($type, [E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return true;
        return $type === E_ERROR && preg_match('/abstract methods? and must therefore|must be compatible with|^Cannot (re)?declare |'
            . 'cannot implement |cannot extend |^Access level to /i', $message) === 1;
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
     * Render the extension page for a storefront ?page= value, or null when no extension owns it. A handler that
     * throws (bad input, a failing service) answers 500 with a neutral page and is logged; it never ends the request
     * as a fatal error (R16).
     * @return array{title:string, content:string}|null
     */
    public static function page(string $route): ?array
    {
        self::boot();
        if (!isset(self::$pages[$route])) return null;
        try {
            $result = (self::$pages[$route])($_GET);
            if (!is_array($result) || !is_string($result['title'] ?? null) || !is_string($result['content'] ?? null)) {
                throw new UnexpectedValueException("extension page {$route} must return ['title' => string, 'content' => string]");
            }
        } catch (Throwable $e) {
            Logger::exception('extension.page_failed', $e, ['page' => $route]);
            http_response_code(500);
            $message = I18n::t('account.page_unavailable');
            return ['title' => $message, 'content' => Template::render('storefront/status-message', ['message' => $message])];
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
            $db = Db::get();
            $wasInTransaction = Db::transactionOpen($db);
            try {
                ($listener['listener'])($envelope);
            } catch (Throwable $e) {
                $error = get_class($e) . ': ' . $e->getMessage();
                Logger::exception('extension.listener_failed', $e, ['endpoint' => $endpoint, 'event_id' => (string)$event['id']]);
            }
            // A listener that leaves its own transaction open would swallow every later write of
            // this worker run (refund records included): roll it back and count it as a failure.
            // Also one opened with exec('BEGIN'), which inTransaction() misses before PHP 8.4.
            if (!$wasInTransaction && Db::transactionOpen($db)) {
                Db::rollBackOpen($db);
                $error ??= 'RuntimeException: listener left a database transaction open';
                Logger::warning('extension.listener_open_transaction', ['endpoint' => $endpoint, 'event_id' => (string)$event['id']]);
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
        self::$loaded = self::$providers = self::$listeners = self::$storefront = self::$pages = self::$api = self::$roots = self::$errors = self::$autoloaders = self::$guarded = [];
        self::$quarantineFile = '';
    }

    /**
     * Quarantine file in private_runtime_dir. Without one there is no quarantine (R17): the shared temp directory had a
     * predictable file name, so another account on the host could plant a quarantine and switch extensions off.
     */
    private static function quarantinePath(array $config): string
    {
        $runtimeDir = rtrim((string)($config['private_runtime_dir'] ?? ''), '/\\');
        return $runtimeDir !== '' && is_dir($runtimeDir) && is_writable($runtimeDir) ? $runtimeDir . '/extension-quarantine.json' : '';
    }

    /** @return array<string,array{error?:string,at?:int,fingerprint?:string}> */
    private static function readQuarantine(): array
    {
        if (self::$quarantineFile === '' || !is_file(self::$quarantineFile)) return [];
        $data = json_decode((string)file_get_contents(self::$quarantineFile), true);
        return is_array($data) ? $data : [];
    }

    /** Set (or with null clear) one extension's quarantine entry. */
    private static function writeQuarantine(string $code, ?array $entry): void
    {
        if (self::$quarantineFile === '') return;
        $data = self::readQuarantine();
        if ($entry === null) unset($data[$code]); else $data[$code] = $entry;
        $temporary = self::$quarantineFile . '.' . getmypid() . '.tmp';
        if (file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false || !rename($temporary, self::$quarantineFile)) {
            @unlink($temporary);
            Logger::error('extension.quarantine_not_written', ['extension' => $code, 'file' => self::$quarantineFile]);
        }
    }

    /** Paths, sizes and modification times of the extension's PHP files: any edit, added or removed file changes it. */
    private static function fingerprint(string $root): string
    {
        if (!is_dir($root)) return '';
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') $files[] = substr(self::normalize($file->getPathname()), strlen(self::normalize($root))) . '|' . $file->getSize() . '|' . $file->getMTime();
        }
        sort($files);
        return hash('sha256', implode("\n", $files));
    }

    private static function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
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
        $file = AppConfig::path(dirname(__DIR__));
        $config = is_file($file) ? require $file : [];
        return is_array($config) ? $config : [];
    }
}
