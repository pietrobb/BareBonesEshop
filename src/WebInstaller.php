<?php
declare(strict_types=1);

/**
 * First-visit installer for hosting without a shell (install.php): writes config.php with fresh secrets, creates the
 * private runtime directory, puts the database in place (the starter database of a demo package, else an empty
 * migrated one), sets the seller identity and the payment methods, and creates the owner account with its TOTP.
 * SQLite only. Refuses to run once config.php exists.
 */
final class WebInstaller
{
    public const STRIPE_FIELDS = ['stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret'];
    /** Proof of owning the files: install.php asks for the key in this file, readable over FTP but never over HTTP (PHP runs it). */
    public const KEY_FILE = 'install-key.php';
    /** A static file in a denied folder: the protection self-check asks the web server for it (see exposedPaths()). */
    public const PROBE_FILE = 'src/install-probe.txt';
    private const KEY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function installed(string $root): bool
    {
        return is_file($root . '/config.php');
    }

    /**
     * The installation key in <root>/install-key.php, created on the first visit (100 random bits, never part of a
     * published package, where everybody could read it). Null when PHP cannot write the shop folder.
     */
    public static function installKey(string $root): ?string
    {
        $file = $root . '/' . self::KEY_FILE;
        if (!is_file($file)) {
            $key = '';
            for ($i = 0; $i < 20; $i++) $key .= self::KEY_ALPHABET[random_int(0, 31)];
            $key = implode('-', str_split($key, 5));
            $handle = @fopen($file, 'x');
            if ($handle !== false) {
                fwrite($handle, "<?php\n// BareBones Shop installation key: install.php asks for it, so only someone with access to these\n"
                    . "// files (FTP, the hosting file manager) can install the shop. Deleted by the installation.\nreturn '{$key}';\n");
                fclose($handle);
                $uploaded = is_file($root . '/install.php') ? $root . '/install.php' : $root;
                @chmod($file, self::keyFileMode((int)@fileowner($file), (int)@fileowner($uploaded)));
            }
        }
        $key = is_file($file) ? (static fn(string $path): mixed => include $path)($file) : null;
        return is_string($key) && preg_match('/^[A-Z2-9]{5}(?:-[A-Z2-9]{5}){3}$/', $key) === 1 ? $key : null;
    }

    /**
     * Mode of install-key.php. PHP running as the account that uploaded the files (suEXEC, per-user PHP-FPM, most
     * shared hosting): only that account may read it (0600), other hosting accounts on the server may not. PHP running
     * as another user (mod_php as www-data): the FTP account is then neither the owner nor in its group, so the key
     * must be world-readable (0644) or nobody could install; over HTTP the file is executed and prints nothing.
     */
    public static function keyFileMode(int $phpUser, int $uploader): int
    {
        return $phpUser === $uploader ? 0600 : 0644;
    }

    /** Spaces, dashes and letter case of a typed key do not matter. */
    public static function keyMatches(string $root, string $typed): bool
    {
        $key = self::installKey($root);
        $normalize = static fn(string $value): string => (string)preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
        return $key !== null && hash_equals($normalize($key), $normalize($typed));
    }

    /**
     * The development server (php -S) started with this shop's router.php, which applies the .htaccess rules itself
     * and serves one request at a time, so it cannot answer its own self-check. php -S without the router serves
     * src/, config and the starter data as plain files: that is checked like any other server.
     * @param list<string> $includedFiles get_included_files() of this request (the first is the script PHP started)
     */
    public static function devRouterActive(string $root, string $sapi, array $includedFiles): bool
    {
        if ($sapi !== 'cli-server' || $includedFiles === []) return false;
        $first = realpath((string)$includedFiles[0]);
        $router = realpath($root . '/router.php');
        return $first !== false && $router !== false && $first === $router;
    }

    /**
     * Whether the web server really hides the application: requests static files it must refuse from itself — the
     * src/ probe file and the starter manifests (demo/<name>/dist/*.json, next to the paid eBooks and the starter
     * database). Static files need no free PHP worker (with one FPM child the installer itself holds it). Without
     * mod_rewrite or AllowOverride (or on nginx without the deploy config) .htaccess does nothing. A path counts as
     * served only when the answer carries the file itself (a catch-all front controller answering 200 is not
     * exposure). Follows one redirect to the same host (http -> https). Returns the served paths, or null when the
     * check cannot tell (no connection to itself, another redirect, a timeout): the caller must not take null as safe.
     * @return list<string>|null
     */
    public static function exposedPaths(string $root, array $server, float $timeout = 3.0): ?array
    {
        $https = strtolower((string)($server['HTTPS'] ?? '')) === 'on';
        $host = (string)($server['HTTP_HOST'] ?? '');
        if (preg_match('/^[A-Za-z0-9.\-]+(?::\d{1,5})?$|^\[[0-9a-fA-F:]+\](?::\d{1,5})?$/', $host) !== 1) return null;
        $hostName = strtolower((string)preg_replace('/:\d+$/', '', $host));
        $base = rtrim(str_replace('\\', '/', dirname((string)($server['SCRIPT_NAME'] ?? '/install.php'))), '/');
        $paths = [self::PROBE_FILE];
        foreach (glob($root . '/demo/*/dist/*.json') ?: [] as $manifest) $paths[] = substr(str_replace('\\', '/', $manifest), strlen(str_replace('\\', '/', $root)) + 1);
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'peer_name' => $hostName]]);
        // SERVER_PORT is the port the client used: behind a port mapping or proxy the server itself listens on another one.
        $endpoint = self::ownEndpoint($server, $https, $timeout, $context);
        if ($endpoint === null) return null;
        $exposed = [];
        $redirected = false;
        foreach ($paths as $path) {
            $url = $base . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
            [$status, $location, $body] = self::fetch($endpoint, $host, $url, $timeout, $context);
            if ($status >= 300 && $status < 400 && !$redirected) {
                $redirected = true;
                $target = parse_url($location);
                $scheme = strtolower((string)($target['scheme'] ?? ''));
                if (!in_array($scheme, ['http', 'https'], true) || strtolower((string)($target['host'] ?? '')) !== $hostName) return null;
                $address = (string)preg_replace('#^\w+://|:\d+$#', '', $endpoint);
                $endpoint = ($scheme === 'https' ? 'ssl://' : 'tcp://') . $address . ':' . (int)($target['port'] ?? ($scheme === 'https' ? 443 : 80));
                $host = $hostName . (isset($target['port']) ? ':' . (int)$target['port'] : '');
                [$status, $location, $body] = self::fetch($endpoint, $host, $url, $timeout, $context);
            }
            if ($status === 0 || ($status >= 300 && $status < 400)) return null;
            if ($status < 300 && $body !== '' && str_starts_with((string)@file_get_contents($root . '/' . $path), $body)) $exposed[] = $path;
        }
        return $exposed;
    }

    /**
     * The installer's verdict on exposedPaths(): served paths refuse the install; "cannot tell" (null) is not
     * "protected" either and refuses it until the key holder confirms having checked the probe file by hand.
     * @param list<string>|null $exposed
     */
    public static function protectionError(?array $exposed, bool $checkedByHand): ?string
    {
        if ($exposed === null) {
            return $checkedByHand ? null : I18n::format('install.err.protection_unknown', ['file' => self::PROBE_FILE]);
        }
        if ($exposed === []) return null;
        return I18n::format('install.err.protection_exposed', ['paths' => implode(', ', $exposed)]);
    }

    /** The address and port this server answers on (SERVER_ADDR or loopback; the client's port, then the scheme's default). */
    private static function ownEndpoint(array $server, bool $https, float $timeout, mixed $context): ?string
    {
        foreach (array_unique([(string)($server['SERVER_ADDR'] ?? ''), '127.0.0.1']) as $address) {
            if ($address === '') continue;
            foreach (array_unique([(int)($server['SERVER_PORT'] ?? 0), $https ? 443 : 80]) as $port) {
                if ($port <= 0 || $port > 65535) continue;
                $candidate = ($https ? 'ssl://' : 'tcp://') . (str_contains($address, ':') ? '[' . $address . ']' : $address) . ':' . $port;
                $socket = @stream_socket_client($candidate, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
                if ($socket === false) continue;
                fclose($socket);
                return $candidate;
            }
        }
        return null;
    }

    /**
     * One GET: [status (0 = no answer), Location header, the first bytes of the body].
     * @return array{0: int, 1: string, 2: string}
     */
    private static function fetch(string $endpoint, string $host, string $url, float $timeout, mixed $context): array
    {
        $socket = @stream_socket_client($endpoint, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) return [0, '', ''];
        stream_set_timeout($socket, (int)ceil($timeout));
        fwrite($socket, "GET {$url} HTTP/1.0\r\nHost: {$host}\r\nUser-Agent: BareBonesEshop-installer\r\nAccept-Encoding: identity\r\nConnection: close\r\n\r\n");
        $response = '';
        while (!feof($socket) && strlen($response) < 16384) {
            $chunk = fread($socket, 4096);
            if ($chunk === false || ($chunk === '' && stream_get_meta_data($socket)['timed_out'])) break;
            $response .= $chunk;
        }
        fclose($socket);
        [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        $status = preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $head, $m) === 1 ? (int)$m[1] : 0;
        $location = preg_match('/^Location:\s*(\S+)/mi', $head, $l) === 1 ? $l[1] : '';
        return [$status, $location, substr($body, 0, 64)];
    }

    /**
     * After the starter is copied into the private folder its source in the web space (demo/<name>/dist: the database
     * and the paid eBooks) is deleted. Returns the folder when it could not be removed.
     */
    public static function removeStarter(array $starter, string $root): ?string
    {
        $dist = dirname((string)$starter['database']);
        $real = realpath($dist);
        $demo = realpath($root . '/demo');
        if ($real === false) return null;
        if ($demo === false || !str_starts_with($real, $demo . DIRECTORY_SEPARATOR) || basename($real) !== 'dist') return $dist;
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) continue;
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($real);
        return is_dir($real) ? $dist : null;
    }

    /**
     * The starter data of a demo package: demo/<name>/dist/starter.json naming a ready SQLite database, its private
     * downloads, its stores.json and the release it was built from ('' when none). demo/ is denied over HTTP. Null for
     * a plain install.
     * @return array{name: string, database: string, downloads: string, stores: string, version: string}|null
     */
    public static function starter(string $root): ?array
    {
        foreach (glob($root . '/demo/*/dist/starter.json') ?: [] as $file) {
            $manifest = json_decode((string)file_get_contents($file), true);
            if (!is_array($manifest)) continue;
            $dir = dirname($file);
            $path = static fn(string $key, string $default): string => $dir . '/' . basename((string)($manifest[$key] ?? $default));
            $starter = ['name' => (string)($manifest['name'] ?? 'Shop'), 'database' => $path('database', 'shop.sqlite'),
                'downloads' => $path('downloads', 'downloads'), 'stores' => $path('stores', 'stores.json'),
                'version' => preg_match('/^[0-9][A-Za-z0-9._+-]{0,63}$/', (string)($manifest['version'] ?? '')) === 1 ? (string)$manifest['version'] : ''];
            if (is_file($starter['database']) && is_file($starter['stores'])) return $starter;
        }
        return null;
    }

    /** R53 (low): seller country, time zone and currency a plain install in the installer's language starts from. */
    public const LOCALE_DEFAULTS = [
        'sk' => ['SK', 'Europe/Bratislava', 'eur'], 'cs' => ['CZ', 'Europe/Prague', 'czk'], 'pl' => ['PL', 'Europe/Warsaw', 'pln'],
        'hu' => ['HU', 'Europe/Budapest', 'huf'], 'de' => ['DE', 'Europe/Berlin', 'eur'], 'es' => ['ES', 'Europe/Madrid', 'eur'],
        // R54 (review round 19, UX): English names no country, so the merchant picks it (was: the Slovak example store).
        'en' => ['', 'UTC', 'eur'],
    ];

    /**
     * Form defaults: the address the installer is opened at, the private directory install would pick, the starter's seller.
     * R53 (low): a plain install (no starter) in Czech prefilled the example's Slovak store (SK, Europe/Bratislava,
     * Slovak, EUR); the installer's language ($locale) now picks the shop language, country, time zone and currency.
     */
    public static function defaults(string $root, array $server, ?string $locale = null): array
    {
        $https = strtolower((string)($server['HTTPS'] ?? '')) === 'on' || (string)($server['SERVER_PORT'] ?? '') === '443';
        $host = (string)($server['HTTP_HOST'] ?? '');
        $path = rtrim(str_replace('\\', '/', dirname((string)($server['SCRIPT_NAME'] ?? '/install.php'))), '/');
        $appUrl = preg_match('/^[A-Za-z0-9.\-]+(?::\d{1,5})?$|^\[[0-9a-fA-F:]+\](?::\d{1,5})?$/', $host) === 1 ? ($https ? 'https' : 'http') . '://' . $host . $path : '';
        $webRoot = Setup::enclosingWebRoot($root);
        $private = $webRoot === null ? dirname(str_replace('\\', '/', $root)) : dirname($webRoot) . '/bareboneseshop-data/' . basename(str_replace('\\', '/', $root));
        $starter = self::starter($root);
        $stores = json_decode((string)@file_get_contents($starter['stores'] ?? $root . '/' . DataOverrides::STORES_EXAMPLE), true);
        $store = is_array($stores) ? (array)($stores['stores'][(string)($stores['default_store'] ?? 'default')] ?? []) : [];
        if ($starter === null && isset(self::LOCALE_DEFAULTS[(string)$locale])) {
            [$country, $timezone, $currency] = self::LOCALE_DEFAULTS[(string)$locale];
            // R54: the example store's name is Slovak ("Slovenský obchod"): another language starts without a name.
            if ((string)($store['default_locale'] ?? '') !== $locale) $store['name'] = '';
            $store['legal']['country'] = $country;
            $store['timezone'] = $timezone;
            $store['default_locale'] = $locale;
            $store['currency'] = $currency;
        }
        return [
            'shop_name' => $starter['name'] ?? (string)($store['name'] ?? ''), 'app_url' => $appUrl, 'private_parent' => $private,
            'country' => (string)($store['legal']['country'] ?? ''), 'timezone' => (string)($store['timezone'] ?? 'Europe/Bratislava'),
            'shop_locale' => (string)($store['default_locale'] ?? 'sk'), 'currency' => (string)($store['currency'] ?? 'eur'),
            'admin_user' => 'owner', 'pay_cod' => '1', 'pay_bank' => '1',
        ];
    }

    /**
     * Checks the form. Stripe is optional and test-only here (a demo must never take real money).
     * @return array{0: array<string, mixed>, 1: array<string, string>} [values, field => error]
     */
    public static function validate(array $input, string $root): array
    {
        $text = static fn(string $key): string => trim((string)($input[$key] ?? ''));
        $v = [];
        foreach (['shop_name', 'app_url', 'private_parent', 'email', 'company', 'street', 'city', 'zip', 'country', 'vat_id', 'iban', 'timezone', 'admin_user', 'stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret',
            'ico', 'dic', 'bic', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from', 'shop_locale', 'currency'] as $key) {
            $v[$key] = $text($key);
        }
        $v['currency'] = strtolower($v['currency']);
        $v['country'] = strtoupper($v['country']);
        $v['iban'] = strtoupper((string)preg_replace('/\s+/', '', $v['iban']));
        $v['bic'] = strtoupper((string)preg_replace('/\s+/', '', $v['bic']));
        $v['admin_user'] = strtolower($v['admin_user']);
        $v['admin_password'] = (string)($input['admin_password'] ?? '');
        $v['pay_cod'] = !empty($input['pay_cod']);
        $v['pay_bank'] = !empty($input['pay_bank']);
        $errors = [];
        foreach (['shop_name', 'company', 'street', 'city', 'zip'] as $key) {
            if ($v[$key] === '' || mb_strlen($v[$key]) > 120) $errors[$key] = I18n::t('install.err.' . $key);
        }
        if (!filter_var($v['app_url'], FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($v['app_url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $errors['app_url'] = I18n::t('install.err.app_url');
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = I18n::t('install.err.email');
        if (preg_match('/^[A-Z]{2}$/', $v['country']) !== 1) $errors['country'] = I18n::t('install.err.country');
        if (!in_array($v['timezone'], DateTimeZone::listIdentifiers(), true)) $errors['timezone'] = I18n::t('install.err.timezone');
        // R51 (low): the shop's language and currency ('' = keep the starter's / example's, an older form without them).
        if ($v['shop_locale'] !== '' && !isset(self::LOCALES[$v['shop_locale']])) $errors['shop_locale'] = I18n::t('install.err.shop_locale');
        if ($v['currency'] !== '' && !isset(self::CURRENCIES[$v['currency']])) $errors['currency'] = I18n::t('install.err.currency');
        if (!$v['pay_cod'] && !$v['pay_bank'] && $v['stripe_secret_key'] === '') $errors['payments'] = I18n::t('install.err.payments');
        if (($v['pay_bank'] || $v['iban'] !== '') && !BankTransferInstructions::validIban($v['iban'])) $errors['iban'] = I18n::t('install.err.iban');
        // R23 UX: the company and tax numbers and the BIC the invoices and the bank transfer instructions print.
        if ($v['bic'] !== '' && !BankTransferInstructions::validBic($v['bic'])) $errors['bic'] = I18n::t('install.err.bic');
        foreach (['ico', 'dic'] as $key) {
            if (mb_strlen($v[$key]) > 32 || preg_match('/[\x00-\x1F\x7F]/', $v[$key])) $errors[$key] = I18n::t('install.err.' . $key);
        }
        $errors += self::stripeErrors($v);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $v['admin_user']) !== 1) $errors['admin_user'] = I18n::t('install.err.admin_user');
        if (strlen($v['admin_password']) < 12 || strlen($v['admin_password']) > 1024) $errors['admin_password'] = I18n::t('install.err.admin_password');
        elseif (!hash_equals($v['admin_password'], (string)($input['admin_password_repeat'] ?? ''))) $errors['admin_password'] = I18n::t('install.err.password_repeat');
        $privateError = self::privateParentError($v['private_parent'], $root);
        if ($privateError !== null) $errors['private_parent'] = $privateError;
        [$v['mail'], $mailErrors] = self::mailSettings($input);
        $errors += $mailErrors;
        return [$v, $errors];
    }

    /** MailSettings::validate() codes with an installer message (install.err.mail.<code>). */
    private const MAIL_ERRORS = ['host', 'port', 'encryption', 'plaintext_auth', 'username', 'password', 'from'];

    /**
     * R23 UX: the optional "Sending e-mail" fieldset — saved like Admin → E-mail delivery once config.php exists.
     * Null when the SMTP server and the sender are both left empty (e-mails are then only logged).
     * @return array{0: array<string, mixed>|null, 1: array<string, string>}
     */
    private static function mailSettings(array $input): array
    {
        $text = static fn(string $key): string => trim((string)(is_scalar($input[$key] ?? null) ? $input[$key] : ''));
        if ($text('smtp_host') === '' && $text('mail_from') === '') return [null, []];
        [$mail, $errors] = MailSettings::validate([
            'mode' => $text('smtp_host') === '' ? 'log' : 'smtp', 'host' => $text('smtp_host'), 'port' => $text('smtp_port'),
            'encryption' => $text('smtp_encryption'), 'username' => $text('smtp_user'),
            'password' => (string)(is_scalar($input['smtp_pass'] ?? null) ? $input['smtp_pass'] : ''), 'from' => $text('mail_from'),
        ]);
        $fields = ['host' => 'smtp_host', 'port' => 'smtp_port', 'encryption' => 'smtp_encryption', 'plaintext_auth' => 'smtp_encryption',
            'username' => 'smtp_user', 'password' => 'smtp_pass', 'from' => 'mail_from'];
        $out = [];
        foreach ($errors as $field => $code) $out[$fields[$code] ?? $fields[$field] ?? 'smtp_host'] = I18n::t('install.err.mail.' . (in_array($code, self::MAIL_ERRORS, true) ? $code : 'other'));
        return [$mail, $out];
    }

    /** @return array<string, string> */
    private static function stripeErrors(array $v): array
    {
        $given = array_filter(array_map(static fn(string $key): string => $v[$key], self::STRIPE_FIELDS), static fn(string $value): bool => $value !== '');
        if ($given === []) return [];
        if (count($given) < 3) return ['stripe' => I18n::t('install.err.stripe_incomplete')];
        if (preg_match('/^(sk|rk)_test_[A-Za-z0-9]{16,}$/', $v['stripe_secret_key']) !== 1 || preg_match('/^pk_test_[A-Za-z0-9]{16,}$/', $v['stripe_publishable_key']) !== 1
            || preg_match('/^whsec_[A-Za-z0-9]{16,}$/', $v['stripe_webhook_secret']) !== 1) {
            return ['stripe' => I18n::t('install.err.stripe_test')];
        }
        return [];
    }

    /** Null when bareboneseshop-private/ can be created in (or already exists writable in) $parent, outside what the web server serves. */
    public static function privateParentError(string $parent, string $root): ?string
    {
        $normalize = static fn(string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
        $absolute = str_starts_with($parent, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $parent) === 1;
        if (!$absolute || preg_match('/[\x00-\x1F\x7F]|(?:^|[\\\\\/])\.\.(?:[\\\\\/]|$)/', $parent)) return I18n::t('install.err.private_relative');
        $served = Setup::enclosingWebRoot($root) ?? $root;
        foreach ([$root, $served] as $public) {
            if ($normalize($parent) === $normalize($public) || str_starts_with($normalize($parent) . '/', $normalize($public) . '/')) {
                return I18n::format('install.err.private_served', ['path' => str_replace('\\', '/', $public)]);
            }
        }
        $private = rtrim($parent, '/\\') . '/bareboneseshop-private';
        if (is_file($private . '/db/shop.sqlite') && (int)filesize($private . '/db/shop.sqlite') > 0) {
            return I18n::format('install.err.private_taken', ['path' => $private]);
        }
        // Missing folders are created (e.g. ../bareboneseshop-data/shop): the nearest existing one must be writable.
        for ($existing = $private; !file_exists($existing) && dirname($existing) !== $existing; $existing = dirname($existing));
        return is_dir($existing) && is_writable($existing) ? null
            : I18n::format('install.err.private_unwritable', ['path' => $existing, 'parent' => $parent]);
    }

    /**
     * The whole installation. config.php is written first and removed again when a later step fails, together with
     * the database this run created, so the installer can simply be run again.
     * @return array{admin: array<string, mixed>, doctor: array<string, mixed>, starter: string|null, leftover: string|null}
     */
    public static function install(array $v, string $root): array
    {
        $stripe = array_filter(array_intersect_key($v, array_flip(self::STRIPE_FIELDS)), static fn($value): bool => $value !== '');
        $updateKey = self::updateKeyTarget($root, $v['private_parent']);
        $created = Setup::createConfig($root, $v['app_url'], $v['email'], ['driver' => 'sqlite'], $v['private_parent'],
            $stripe + ['web_worker' => true] + ($updateKey !== null ? ['update_public_key' => $updateKey] : []));
        if ($created['action'] !== 'created') throw new RuntimeException('The shop is already installed (config.php exists).');
        $config = require $root . '/config.php';
        $database = (string)$config['db_sqlite_path'];
        $starter = self::starter($root);
        $seeded = false;
        try {
            if ($starter !== null) self::copyStarterStores($starter, $config);
            Setup::apply($config, $root);
            if ($updateKey !== null) self::installUpdateKey($root, $updateKey);
            if ($starter !== null) $seeded = self::seed($starter, $config);
            // A web request never creates a database by itself (Db refuses a missing file): the installer creates it on purpose.
            if (!$seeded && !is_file($database)) {
                if (!touch($database)) throw new RuntimeException('Cannot create the database file');
                @chmod($database, 0640);
            }
            (new Migrator())->apply();
            foreach (Extensions::migrators() as $migrator) $migrator->apply();
            if (!empty($v['mail'])) {
                $mailPath = MailSettings::path($config);
                $mailError = MailSettings::save($v['mail'], $config, 'install.php');
                if ($mailError !== null) throw new RuntimeException("Cannot save the e-mail settings ({$mailError})");
            }
            $stores = self::configureStore($root, $config, $v);
            self::priceInStoreCurrency(Db::get(), $stores);
            if ($starter === null) self::localizeSampleMethods(Db::get(), $stores);
            self::configurePayments(Db::get(), $stores, $v);
            $default = (string)($stores['default_store'] ?? 'default');
            $owner = AdminIdentity::createWithStore($v['admin_user'], $v['admin_password'], 'owner', true, $default, true);
            if ($starter !== null) self::baselineRelease($starter, $config, $root);
            $leftover = $seeded ? self::removeStarter($starter, $root) : null;
            return ['admin' => ['username' => $v['admin_user']] + (array)$owner['enrollment'], 'doctor' => Setup::doctor($config, $root, Db::get()),
                'starter' => $starter['name'] ?? null, 'leftover' => $leftover];
        } catch (Throwable $e) {
            @unlink($root . '/config.php');
            // The SMTP password in it is encrypted with the key of the config.php just removed: nobody could open it.
            if (isset($mailPath)) @unlink($mailPath);
            if ($seeded || (is_file($database) && $starter === null)) {
                foreach (['', '-wal', '-shm', '-journal'] as $suffix) @unlink($database . $suffix);
            }
            throw $e;
        }
    }

    /**
     * install() under the caller's lock (two submits racing for the same fresh upload): [result, null] or [null, the
     * page's error]. The details (paths, driver messages) go to the PHP error log under a reference, never to the page.
     * @param resource|false $lock
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    public static function installLocked(array $v, string $root, $lock): array
    {
        if (is_resource($lock)) flock($lock, LOCK_EX);
        try {
            if (is_file($root . '/config.php')) throw new RuntimeException('The shop has just been installed by another request.');
            $result = self::install($v, $root);
            @unlink($root . '/' . self::KEY_FILE);
            return [$result, null];
        } catch (Throwable $e) {
            $reference = bin2hex(random_bytes(4));
            Logger::phpLog("BareBonesEshop install.php failure {$reference}: " . self::describe($e));
            return [null, I18n::format('install.err.failed', ['reference' => $reference])];
        }
    }

    /**
     * The failure for the PHP error log without call arguments: a trace with arguments would carry the owner's
     * password (AdminIdentity::createWithStore('owner', '<password>', …)) into error_log, which hosting panels keep
     * next to the scripts.
     */
    public static function describe(Throwable $e): string
    {
        $lines = [];
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $lines[] = get_class($current) . ': ' . $current->getMessage() . ' @ ' . $current->getFile() . ':' . $current->getLine();
            foreach ($current->getTrace() as $i => $frame) {
                $lines[] = "  #{$i} " . (isset($frame['file']) ? $frame['file'] . ':' . (int)($frame['line'] ?? 0) . ' ' : '')
                    . ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()';
            }
        }
        return implode("\n", $lines);
    }

    /** R38 UX: the installer's languages (lang/<code>.php), each named in itself for the language links. */
    /** Currencies the installer offers for the shop (those of the sample stores; price formats and EUR rates exist). */
    public const CURRENCIES = ['eur' => 'EUR (€)', 'czk' => 'CZK (Kč)', 'pln' => 'PLN (zł)', 'huf' => 'HUF (Ft)', 'usd' => 'USD ($)'];

    public const LOCALES = ['en' => 'English', 'sk' => 'Slovenčina', 'cs' => 'Čeština', 'de' => 'Deutsch', 'pl' => 'Polski', 'hu' => 'Magyar', 'es' => 'Español'];

    /**
     * R38 UX: the installer was English only. Its language is the one picked from the language links (?lang=, kept in
     * the form's action), else the browser's first supported Accept-Language, else English.
     */
    public static function locale(?string $requested, string $acceptLanguage): string
    {
        if ($requested !== null && isset(self::LOCALES[$requested])) return $requested;
        $ranked = [];
        foreach (explode(',', $acceptLanguage) as $i => $part) {
            if (preg_match('/^\s*([A-Za-z]{2})(?:-[A-Za-z0-9]{1,8})*\s*(?:;\s*q\s*=\s*([01](?:\.\d{0,3})?))?\s*$/', $part, $m) !== 1) continue;
            $code = strtolower($m[1]);
            $q = isset($m[2]) ? (float)$m[2] : 1.0;
            if ($q > 0 && isset(self::LOCALES[$code]) && !isset($ranked[$code])) $ranked[$code] = [$q, -$i];
        }
        arsort($ranked);
        return (string)(array_key_first($ranked) ?? 'en');
    }

    /** The language links above the form; the current language is not a link. */
    /** A labelled <select> of the install form (with its hint and error, as the text fields). */
    public static function selectField(string $name, array $options, string $selected, ?string $error): string
    {
        $id = 'f-' . $name;
        $errorHtml = $error !== null ? '<p class="error" id="' . $id . '-error">' . self::h($error) . '</p>' : '';
        $html = '<label for="' . $id . '">' . self::h(I18n::t('install.label.' . $name)) . '</label><p class="hint" id="' . $id . '-hint">' . self::h(I18n::t('install.hint.' . $name)) . '</p>'
            . '<select id="' . $id . '" name="' . $name . '" aria-describedby="' . $id . '-hint' . ($error !== null ? ' ' . $id . '-error" aria-invalid="true' : '') . '">';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . self::h($value) . '"' . ($selected === (string)$value ? ' selected' : '') . '>' . self::h($label) . '</option>';
        }
        return $html . '</select>' . $errorHtml;
    }

    public static function languageLinks(): string
    {
        $links = [];
        foreach (self::LOCALES as $code => $name) {
            $links[] = $code === I18n::locale() ? '<strong>' . self::h($name) . '</strong>'
                : '<a href="install.php?lang=' . $code . '" lang="' . $code . '" hreflang="' . $code . '">' . self::h($name) . '</a>';
        }
        return '<nav aria-label="' . self::h(I18n::t('install.language')) . '"><p class="hint">' . implode(' · ', $links) . '</p></nav>';
    }

    /** The installer's HTML page (inline styles only: the strict CSP of install.php allows nothing else). */
    public static function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="' . self::h(I18n::locale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::h($title) . '</title><style>'
            . 'body{font:16px/1.5 system-ui,sans-serif;max-width:46rem;margin:2rem auto;padding:0 1rem;color:#1f1d1a;background:#faf7f2}'
            . 'h1{font-family:Georgia,serif}fieldset{border:1px solid #cfc6b8;border-radius:6px;margin:0 0 1.2rem;padding:.8rem 1rem}'
            . 'label{display:block;margin:.5rem 0 .1rem;font-weight:600}input[type=text],input[type=url],input[type=email],input[type=password]{width:100%;box-sizing:border-box;padding:.45rem;border:1px solid #8a8074;border-radius:4px;font:inherit}'
            . '.check{font-weight:400}.hint{color:#5c554c;font-size:.9rem;margin:.1rem 0}.error{color:#a3150b;font-weight:600}'
            . 'button{background:#7a2e1f;color:#fff;border:0;border-radius:4px;padding:.7rem 1.4rem;font:inherit;cursor:pointer}'
            . 'table{border-collapse:collapse}td{padding:.2rem .6rem;border-bottom:1px solid #e3dccf;vertical-align:top}code{word-break:break-all}'
            . '</style></head><body><main><h1>' . self::h($title) . '</h1>' . $body . '</main></body></html>';
    }

    /** Body of the "Your shop is ready" page: the owner's TOTP QR code and recovery codes (shown only this once) and the health check. */
    public static function readyHtml(array $result, string $root): string
    {
        $admin = $result['admin'];
        $doctor = $result['doctor'];
        $rows = '';
        foreach ($doctor['checks'] as $check) {
            if ($check['status'] === 'pass' || $check['status'] === 'info') continue; // R50: optional items wait for the admin checklist
            // The check in the installer's language where the admin has a text for it; the doctor's own (English)
            // finding and remedy stay below it, as the technical detail to search for or to hand to the hosting.
            try {
                $title = SetupChecklist::title((string)$check['name']);
            } catch (Throwable $e) {
                Logger::phpLog('BareBonesEshop install.php check title ' . (string)$check['name'] . ': ' . self::describe($e));
                $title = null;
            }
            $detail = trim($check['message'] . ($check['action'] !== '' ? ' ' . $check['action'] : ''));
            $rows .= '<tr><td>' . self::h(I18n::t($check['status'] === 'fail' ? 'install.ready.status_fail' : 'install.ready.status_warn')) . '</td><td>'
                . self::h($title ?? $check['message']) . '<p class="hint">' . self::h($title !== null ? $detail : $check['action']) . '</p></td></tr>';
        }
        $leftover = $result['leftover'] !== null ? substr(str_replace('\\', '/', $result['leftover']), strlen(str_replace('\\', '/', $root)) + 1) : null;
        return '<p>' . self::h($result['starter'] !== null ? I18n::format('install.ready.starter', ['name' => $result['starter']]) : I18n::t('install.ready.plain')) . '</p>'
            . '<h2>' . self::h(I18n::t('install.ready.signin_heading')) . '</h2><p>'
            . strtr(self::h(I18n::t('install.ready.signin')), ['{user}' => '<strong>' . self::h($admin['username']) . '</strong>']) . '</p>'
            . '<p>' . self::h(I18n::t('install.ready.scan')) . '</p>' . QrCode::svg((string)($admin['uri'] ?? ''), 'TOTP ' . (string)$admin['username'])
            . '<p class="hint">' . strtr(self::h(I18n::t('install.ready.manual_key')), ['{key}' => '<code>' . self::h($admin['secret'] ?? '') . '</code>']) . '</p>'
            . '<p><strong>' . self::h(I18n::t('install.ready.recovery_title')) . '</strong> ' . self::h(I18n::t('install.ready.recovery_hint')) . '</p><p><code>'
            . self::h(implode('  ', (array)($admin['recovery_codes'] ?? []))) . '</code></p>'
            . '<h2>' . self::h(I18n::t('install.ready.health')) . '</h2><p>' . self::h(I18n::format('install.ready.counts',
                ['pass' => $doctor['counts']['pass'], 'warn' => $doctor['counts']['warn'], 'fail' => $doctor['counts']['fail']])) . '</p>'
            . ($rows !== '' ? '<table>' . $rows . '</table>' : '')
            . ($leftover !== null ? '<p class="error">' . self::h(I18n::format('install.ready.leftover', ['folder' => $leftover])) . '</p>' : '')
            . self::homeLinks();
    }

    /** "Open the shop · Sign in to the administration". */
    public static function homeLinks(): string
    {
        return '<p><a href="./">' . self::h(I18n::t('install.open_shop')) . '</a> · <a href="admin.php">' . self::h(I18n::t('install.sign_in_admin')) . '</a></p>';
    }

    private static function h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * R15: a package built from a release records it in the update state, in the folder cli/update.php uses, like
     * `php cli/update.php baseline` — or the signed updater refuses the shop as of unknown version. An existing state
     * (a reused private folder) is left as it is.
     */
    public static function baselineRelease(array $starter, array $config, string $root): void
    {
        if (($starter['version'] ?? '') === '') return;
        $private = (string)($config['private_runtime_dir'] ?? dirname($root) . '/bareboneseshop-private');
        $updater = new Updater($root, (string)($config['update_storage_dir'] ?? rtrim($private, '/\\') . '/updates'));
        if (!$updater->hasInstalledState()) $updater->baseline($starter['version']);
    }

    /**
     * R16: where the shop keeps the update key it trusts — a copy of the shipped releases/update-public.key in the
     * private data folder; null when the upload has no key. Without update_public_key the signed updater refuses every
     * package, so a shop installed over the web could not be updated. A file rather than an inline value, so
     * `php cli/update.php rotate-key` can replace it; outside the web space, so no update can swap it.
     */
    public static function updateKeyTarget(string $root, string $privateParent): ?string
    {
        if (!is_file($root . '/releases/update-public.key')) return null;
        return rtrim(str_replace('\\', '/', $privateParent), '/') . '/bareboneseshop-private/update-public.key';
    }

    /** Copies the shipped update key (checked to be an Ed25519 public key) to the path config.php names. */
    public static function installUpdateKey(string $root, string $target): void
    {
        $key = UpdateKeyRotation::encoded(trim((string)file_get_contents($root . '/releases/update-public.key')));
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Cannot create the private runtime directory');
        }
        if (!DataOverrides::atomicWrite($target, $key . "\n")) throw new RuntimeException('Cannot write the update key');
        @chmod($target, 0640);
    }

    /** The starter's stores.json becomes the install's own copy (apply then keeps it instead of the example). */
    private static function copyStarterStores(array $starter, array $config): void
    {
        $dir = rtrim((string)($config['private_runtime_dir'] ?? ''), '/\\');
        if ($dir === '' || (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir))) throw new RuntimeException('Cannot create the private runtime directory');
        if (!is_file($dir . '/stores.json') && !copy($starter['stores'], $dir . '/stores.json')) throw new RuntimeException('Cannot write stores.json');
        @chmod($dir . '/stores.json', 0640);
    }

    /** Copies the starter database and its eBook files; refuses to replace an existing database. */
    public static function seed(array $starter, array $config): bool
    {
        if (($config['db_driver'] ?? 'sqlite') !== 'sqlite') throw new RuntimeException('The web installer sets up SQLite only.');
        $target = (string)$config['db_sqlite_path'];
        if (is_file($target) && (int)filesize($target) > 0) throw new RuntimeException("A database already exists at {$target}; the starter data is only copied into a new shop.");
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) throw new RuntimeException('Cannot create the database directory');
        if (!copy($starter['database'], $target)) throw new RuntimeException('Cannot copy the starter database');
        @chmod($target, 0640);
        $downloads = rtrim((string)($config['digital_assets_base_dir'] ?? ''), '/\\');
        if (is_dir($starter['downloads']) && $downloads !== '') self::copyTree($starter['downloads'], $downloads);
        return true;
    }

    private static function copyTree(string $from, string $to): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        if (!is_dir($to) && !mkdir($to, 0750, true) && !is_dir($to)) throw new RuntimeException("Cannot create {$to}");
        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo || $item->isLink()) continue;
            $target = $to . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($from) + 1));
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) throw new RuntimeException("Cannot create {$target}");
            } elseif (!copy($item->getPathname(), $target)) {
                throw new RuntimeException("Cannot copy {$target}");
            }
        }
    }

    /** Writes shop name, time zone and seller identity into the default store of the install's stores.json. */
    private static function configureStore(string $root, array $config, array $v): array
    {
        $path = DataOverrides::storesPath($root, $config);
        $stores = json_decode((string)file_get_contents($path), true);
        if (!is_array($stores)) throw new RuntimeException("Cannot read {$path}");
        $stores = self::withIdentity($stores, $v);
        $json = json_encode($stores, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (!DataOverrides::atomicWrite($path, $json)) throw new RuntimeException("Cannot write {$path}");
        return $stores;
    }

    /** The stores data with the form's shop name, time zone and seller in the default store (the example seller is replaced, not merged). */
    public static function withIdentity(array $stores, array $v): array
    {
        $id = (string)($stores['default_store'] ?? 'default');
        $store = (array)($stores['stores'][$id] ?? []);
        $legal = (array)($store['legal'] ?? []);
        $store['name'] = $v['shop_name'];
        $store['timezone'] = $v['timezone'];
        // R51 (low): every install was a Slovak shop in EUR; the form now picks the default language and the currency.
        if (($v['shop_locale'] ?? '') !== '') {
            $store['default_locale'] = $v['shop_locale'];
            // R57 (review round 22, installer): only the chosen language. Was: the example's ["sk", "cs", "en"] stayed behind
            // it, so a German shop offered Slovak and Czech pages (and hreflang links to them) it had no texts for.
            // Admin → Stores adds languages.
            $store['languages'] = [$v['shop_locale']];
            // R53 (low): the admin (and system e-mails) fall back to the top-level locale, "en" in the example.
            $stores['default_locale'] = $v['shop_locale'];
        }
        if (($v['currency'] ?? '') !== '') {
            $store['currency'] = $v['currency'];
            $stores['default_currency'] = $v['currency'];
        }
        $store['legal'] = [
            'name' => $v['company'], 'street' => $v['street'], 'city' => $v['city'], 'zip' => $v['zip'], 'country' => $v['country'],
            'vat_registered' => $v['vat_id'] !== '', 'ic_dph' => $v['vat_id'], 'iban' => $v['iban'], 'email' => $v['email'],
            'invoice_prefix' => (string)($legal['invoice_prefix'] ?? ''), 'payment_terms_days' => (int)($legal['payment_terms_days'] ?? 14),
        ];
        foreach (['ico', 'dic', 'bic'] as $key) {
            if ((string)($v[$key] ?? '') !== '') $store['legal'][$key] = (string)$v[$key];
        }
        $stores['stores'][$id] = $store;
        // The installer sets up one seller and one store. R50 (low): the example's sample stores (cz, pl, hu, de, us with
        // made-up sellers) are left out instead of coming along hidden: the admin listed five stores the merchant never
        // created. A store with its own real seller stays (and goes offline only while its seller is incomplete); a store
        // sharing the seller, catalog or provider settings of a store left out goes with it. Admin → Stores adds more.
        $dropped = [];
        do {
            $before = count($dropped);
            foreach ((array)$stores['stores'] as $otherId => $other) {
                $otherId = (string)$otherId;
                if ($otherId === $id || isset($dropped[$otherId])) continue;
                $legal = is_array($other) && is_array($other['legal'] ?? null) ? $other['legal'] : [];
                $linked = array_filter([$legal['seller'] ?? null, $other['catalog_scope'] ?? null, $other['provider_scope'] ?? null],
                    static fn(mixed $ref): bool => is_string($ref) && isset($dropped[$ref]));
                $sample = !isset($legal['seller']) && (($other['sample'] ?? false) === true || !StoreContext::isPublicConfig((array)$other)) && Setup::legalIdentityProblems($legal) !== [];
                if ($linked !== [] || $sample) $dropped[$otherId] = true;
            }
        } while (count($dropped) > $before);
        foreach (array_keys($dropped) as $otherId) unset($stores['stores'][$otherId]);
        foreach ((array)$stores['stores'] as $otherId => $other) {
            if ((string)$otherId === $id || !is_array($other) || !is_array($other['legal'] ?? null) || isset($other['legal']['seller'])) continue;
            if (Setup::legalIdentityProblems($other['legal']) !== []) $stores['stores'][$otherId]['public'] = false;
        }
        return $stores;
    }

    /** R53: provider scopes the migrations price in another currency (20261022230000_currency_price_lists). */
    public const PRICE_LISTS = ['czk' => 'cz', 'huf' => 'hu', 'pln' => 'pl'];
    /** The sample shipping zones as migration 20260425064000 seeds them: code => [name, description]. */
    private const SEEDED_ZONES = [
        'domestic' => ['Domestic SK', 'Slovakia domestic shipping'],
        'cz' => ['Czech Republic', 'CZ cross-border shipping'],
        'near_eu' => ['Nearby EU', 'AT/HU/PL/DE regional EU shipping'],
        'eu' => ['European Union', 'Fallback EU shipping zone'],
    ];
    /** The provider tables of a scope, parents first. */
    private const PROVIDER_TABLES = ['shipping_zones', 'shipping_zone_countries', 'shipping_methods', 'shipping_rates',
        'payment_methods', 'payment_method_countries', 'payment_method_shipping'];

    /**
     * R53 (review round 53, medium 3): a shop installed in CZK kept the default store's shipping and payment prices in
     * euro cents (GLS 2.99 Kč, free shipping from 50 Kč, cash on delivery 3 Kč). The default store's provider scope now
     * takes the methods, rates, zones and fees the migrations priced in its currency (the sample CZ, HU and PL stores'
     * lists) in place of its EUR ones. A currency without such a list (USD) keeps the EUR amounts; the setup doctor warns
     * when a store's shipping prices do not fit its currency.
     */
    public static function priceInStoreCurrency(PDO $db, array $stores): void
    {
        $id = (string)($stores['default_store'] ?? 'default');
        $store = is_array($stores['stores'][$id] ?? null) ? $stores['stores'][$id] : [];
        $scope = (string)($store['provider_scope'] ?? $id);
        $source = self::PRICE_LISTS[strtolower((string)($store['currency'] ?? 'eur'))] ?? null;
        if ($source === null || $source === $scope) return;
        $has = $db->prepare('SELECT COUNT(*) FROM shipping_methods WHERE store_id = ?');
        $has->execute([$source]);
        if ((int)$has->fetchColumn() === 0) return;
        $db->beginTransaction();
        try {
            foreach (array_reverse(self::PROVIDER_TABLES) as $table) $db->prepare("DELETE FROM {$table} WHERE store_id = ?")->execute([$scope]);
            foreach (self::PROVIDER_TABLES as $table) {
                $rows = $db->prepare("SELECT * FROM {$table} WHERE store_id = ?");
                $rows->execute([$source]);
                foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    unset($row['id']);
                    $row['store_id'] = $scope;
                    $columns = array_keys($row);
                    $db->prepare("INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
                        ->execute(array_values($row));
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * R56 (review round 21, UX): a plain install outside Slovakia (de/EUR, en/USD) kept the default store's sample shipping
     * and payment methods as seeded for a Slovak shop: Slovak names ("Osobný odber", "Dobierka"), a "Domestic SK" zone of
     * Slovakia alone, the seller's own country priced in "Nearby EU" only up to 5 kg (a German order above 5 kg found no
     * carrier) and cash on delivery for SK and CZ only. Methods still named as seeded now take the shop language's names
     * (the other languages as their translations); a seller outside Slovakia gets the domestic zone, its rates and the
     * payment countries for its own country, and Slovakia moves to the EU zone. A scope priced from another currency's
     * list (priceInStoreCurrency()) or changed by hand is left alone.
     */
    public static function localizeSampleMethods(PDO $db, array $stores): void
    {
        $id = (string)($stores['default_store'] ?? 'default');
        $store = is_array($stores['stores'][$id] ?? null) ? $stores['stores'][$id] : [];
        $scope = (string)($store['provider_scope'] ?? $id);
        $locale = strtolower((string)($store['default_locale'] ?? 'sk'));
        $country = strtoupper((string)($store['legal']['country'] ?? ''));
        $db->beginTransaction();
        try {
            if ($locale !== 'sk') {
                foreach (MethodLabels::SEEDED as $kind => $methods) {
                    $table = $kind === 'payment' ? 'payment_methods' : 'shipping_methods';
                    $translated = Db::hasColumn($table, 'translations', $db);
                    $find = $db->prepare('SELECT * FROM ' . $table . ' WHERE store_id = ? AND code = ?');
                    foreach ($methods as $code => [$seededName, $seededDescription]) {
                        $find->execute([$scope, $code]);
                        $row = $find->fetch(PDO::FETCH_ASSOC);
                        if (!is_array($row) || (string)$row['name'] !== $seededName) continue;
                        $text = static function (string $suffix, string $in) use ($kind, $code): ?string {
                            $key = "methods.{$kind}.{$code}{$suffix}";
                            $value = I18n::tIn($key, $in);
                            return $value === $key || $value === '' ? null : $value;
                        };
                        $set = ['name' => $text('', $locale) ?? $seededName];
                        $describe = $seededDescription !== null && array_key_exists('description', $row) && (string)$row['description'] === $seededDescription;
                        if ($describe) $set['description'] = $text('_desc', $locale) ?? $seededDescription;
                        if ($translated) {
                            $translations = json_decode((string)($row['translations'] ?? ''), true);
                            $translations = is_array($translations) ? $translations : [];
                            foreach (array_keys(self::LOCALES) as $other) {
                                if ($other === $locale || isset($translations[$other])) continue;
                                $texts = array_filter(['name' => $text('', $other), 'description' => $describe ? $text('_desc', $other) : null], 'is_string');
                                if ($texts !== []) $translations[$other] = $texts;
                            }
                            ksort($translations);
                            $set['translations'] = $translations === [] ? null : json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        }
                        $db->prepare('UPDATE ' . $table . ' SET ' . implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($set))) . ' WHERE store_id = ? AND code = ?')
                            ->execute([...array_values($set), $scope, $code]);
                    }
                }
            }
            $domestic = $db->prepare("SELECT country FROM shipping_zone_countries WHERE store_id = ? AND zone_code = 'domestic'");
            $domestic->execute([$scope]);
            if ($country !== '' && $country !== 'SK' && preg_match('/^[A-Z]{2}$/D', $country) === 1 && $domestic->fetchAll(PDO::FETCH_COLUMN) === ['SK']) {
                $run = static fn(string $sql, array $params) => $db->prepare($sql)->execute($params);
                // The seller's country leaves the zone it sat in (DE in "Nearby EU", CZ in "Czech Republic"), with its rates there.
                $run("DELETE FROM shipping_rates WHERE store_id = ? AND country = ? AND zone_code <> 'domestic'", [$scope, $country]);
                $run("DELETE FROM shipping_zone_countries WHERE store_id = ? AND country = ?", [$scope, $country]);
                $run("UPDATE shipping_zone_countries SET country = ? WHERE store_id = ? AND zone_code = 'domestic' AND country = 'SK'", [$country, $scope]);
                $run("UPDATE shipping_rates SET country = ? WHERE store_id = ? AND zone_code = 'domestic' AND country = 'SK'", [$country, $scope]);
                $eu = $db->prepare("SELECT COUNT(*) FROM shipping_zones WHERE store_id = ? AND code = 'eu'");
                $eu->execute([$scope]);
                if ((int)$eu->fetchColumn() > 0) $run(Db::insertIgnore() . " INTO shipping_zone_countries (store_id, zone_code, country) VALUES (?, 'eu', 'SK')", [$scope]);
                // A payment method offered in Slovakia (cash on delivery: SK and CZ) is offered in the seller's country too.
                $methods = $db->prepare("SELECT method_code FROM payment_method_countries WHERE store_id = ? AND country = 'SK'");
                $methods->execute([$scope]);
                foreach ($methods->fetchAll(PDO::FETCH_COLUMN) as $method) {
                    $run(Db::insertIgnore() . ' INTO payment_method_countries (store_id, method_code, country) VALUES (?, ?, ?)', [$scope, (string)$method, $country]);
                }
            }
            // R57 (review round 22, installer): the sample zones still named as seeded take the shop language's names
            // ("Inland DE", "Europäische Union"). Was: English ("Domestic DE", "Nearby EU") in every shop.
            $domestic->execute([$scope]);
            $home = $domestic->fetchAll(PDO::FETCH_COLUMN);
            $home = count($home) === 1 ? (string)$home[0] : 'SK';
            $rename = $db->prepare('UPDATE shipping_zones SET name = ? WHERE store_id = ? AND code = ? AND name = ?');
            $describe = $db->prepare('UPDATE shipping_zones SET description = ? WHERE store_id = ? AND code = ? AND description = ?');
            foreach (self::SEEDED_ZONES as $code => [$seededName, $seededDescription]) {
                $name = strtr(I18n::tIn("install.zone.{$code}", $locale), ['{country}' => $home]);
                $description = strtr(I18n::tIn("install.zone.{$code}_desc", $locale), ['{country}' => $home]);
                if ($name !== "install.zone.{$code}") $rename->execute([$name, $scope, $code, $seededName]);
                if ($description !== "install.zone.{$code}_desc") $describe->execute([$description, $scope, $code, $seededDescription]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Cash on delivery and bank transfer as chosen for the default store; card (Stripe) only with Stripe keys, in every
     * store (without keys checkout could not start the payment); invoice stays off.
     */
    public static function configurePayments(PDO $db, array $stores, array $v): void
    {
        $default = (string)($stores['default_store'] ?? 'default');
        $set = $db->prepare('UPDATE payment_methods SET active = :active WHERE store_id = :scope AND code = :code');
        $scopes = [];
        foreach ((array)($stores['stores'] ?? []) as $id => $store) {
            $scope = (string)(is_array($store) ? ($store['provider_scope'] ?? $id) : $id);
            $scopes[] = $scope;
            $wanted = ['card' => ($v['stripe_secret_key'] ?? '') !== ''];
            if ((string)$id === $default) $wanted += ['cod' => (bool)$v['pay_cod'], 'bank_transfer' => (bool)$v['pay_bank'], 'invoice' => false];
            foreach ($wanted as $code => $active) $set->execute([':active' => $active ? 1 : 0, ':scope' => $scope, ':code' => $code]);
        }
        // The methods the migrations seeded for the sample stores the installer left out stay switched off.
        $scopes = array_values(array_unique($scopes));
        if ($scopes !== []) {
            $db->prepare('UPDATE payment_methods SET active = 0 WHERE store_id NOT IN (' . implode(', ', array_fill(0, count($scopes), '?')) . ')')->execute($scopes);
        }
    }
}
