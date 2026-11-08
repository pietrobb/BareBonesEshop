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
            return $checkedByHand ? null : 'The installer could not check whether the web server keeps the application folders private. Open '
                . self::PROBE_FILE . ' of this shop in the browser: it must answer "Forbidden" or "Not Found". Then tick the box below and send the form again.';
        }
        if ($exposed === []) return null;
        return 'The web server serves files that must stay private (' . implode(', ', $exposed) . '), so the .htaccess rules are not active. '
            . 'Turn on mod_rewrite and AllowOverride All for this folder (ask your hosting), or use deploy/nginx.conf on nginx, then send the form again.';
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

    /** Form defaults: the address the installer is opened at, the private directory install would pick, the starter's seller. */
    public static function defaults(string $root, array $server): array
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
        return [
            'shop_name' => $starter['name'] ?? (string)($store['name'] ?? ''), 'app_url' => $appUrl, 'private_parent' => $private,
            'country' => (string)($store['legal']['country'] ?? ''), 'timezone' => (string)($store['timezone'] ?? 'Europe/Bratislava'),
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
            'ico', 'dic', 'bic'] as $key) {
            $v[$key] = $text($key);
        }
        $v['country'] = strtoupper($v['country']);
        $v['iban'] = strtoupper((string)preg_replace('/\s+/', '', $v['iban']));
        $v['bic'] = strtoupper((string)preg_replace('/\s+/', '', $v['bic']));
        $v['admin_user'] = strtolower($v['admin_user']);
        $v['admin_password'] = (string)($input['admin_password'] ?? '');
        $v['pay_cod'] = !empty($input['pay_cod']);
        $v['pay_bank'] = !empty($input['pay_bank']);
        $errors = [];
        foreach (['shop_name' => 'shop name', 'company' => 'seller name', 'street' => 'street', 'city' => 'city', 'zip' => 'postcode'] as $key => $label) {
            if ($v[$key] === '' || mb_strlen($v[$key]) > 120) $errors[$key] = "Enter the {$label} (at most 120 characters).";
        }
        if (!filter_var($v['app_url'], FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($v['app_url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $errors['app_url'] = 'Enter the address of the shop, e.g. https://books.example.com';
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid e-mail address.';
        if (preg_match('/^[A-Z]{2}$/', $v['country']) !== 1) $errors['country'] = 'Enter a two-letter country code, e.g. SK or GB.';
        if (!in_array($v['timezone'], DateTimeZone::listIdentifiers(), true)) $errors['timezone'] = 'Enter a time zone such as Europe/Bratislava.';
        if (!$v['pay_cod'] && !$v['pay_bank'] && $v['stripe_secret_key'] === '') $errors['payments'] = 'Choose at least one payment method.';
        if (($v['pay_bank'] || $v['iban'] !== '') && !BankTransferInstructions::validIban($v['iban'])) $errors['iban'] = 'Bank transfer needs a valid IBAN.';
        // R23 UX: the company and tax numbers and the BIC the invoices and the bank transfer instructions print.
        if ($v['bic'] !== '' && !BankTransferInstructions::validBic($v['bic'])) $errors['bic'] = 'Enter a BIC of 8 or 11 characters, e.g. TATRSKBX.';
        foreach (['ico' => 'company number', 'dic' => 'tax number'] as $key => $label) {
            if (mb_strlen($v[$key]) > 32 || preg_match('/[\x00-\x1F\x7F]/', $v[$key])) $errors[$key] = "Enter the {$label} (at most 32 characters).";
        }
        $errors += self::stripeErrors($v);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $v['admin_user']) !== 1) $errors['admin_user'] = 'Use 3-64 letters, digits, dots, dashes or underscores.';
        if (strlen($v['admin_password']) < 12 || strlen($v['admin_password']) > 1024) $errors['admin_password'] = 'The password needs at least 12 characters.';
        elseif (!hash_equals($v['admin_password'], (string)($input['admin_password_repeat'] ?? ''))) $errors['admin_password'] = 'The two passwords differ.';
        $privateError = self::privateParentError($v['private_parent'], $root);
        if ($privateError !== null) $errors['private_parent'] = $privateError;
        return [$v, $errors];
    }

    /** @return array<string, string> */
    private static function stripeErrors(array $v): array
    {
        $given = array_filter(array_map(static fn(string $key): string => $v[$key], self::STRIPE_FIELDS), static fn(string $value): bool => $value !== '');
        if ($given === []) return [];
        if (count($given) < 3) return ['stripe' => 'Card payments need all three Stripe values (secret key, publishable key, webhook signing secret), or none.'];
        if (preg_match('/^(sk|rk)_test_[A-Za-z0-9]{16,}$/', $v['stripe_secret_key']) !== 1 || preg_match('/^pk_test_[A-Za-z0-9]{16,}$/', $v['stripe_publishable_key']) !== 1
            || preg_match('/^whsec_[A-Za-z0-9]{16,}$/', $v['stripe_webhook_secret']) !== 1) {
            return ['stripe' => 'Use Stripe test-mode keys (sk_test_ or rk_test_, pk_test_) and a whsec_ webhook secret: this installer sets up a demo, live keys belong in config.php.'];
        }
        return [];
    }

    /** Null when bareboneseshop-private/ can be created in (or already exists writable in) $parent, outside what the web server serves. */
    public static function privateParentError(string $parent, string $root): ?string
    {
        $normalize = static fn(string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
        $absolute = str_starts_with($parent, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $parent) === 1;
        if (!$absolute || preg_match('/[\x00-\x1F\x7F]|(?:^|[\\\\\/])\.\.(?:[\\\\\/]|$)/', $parent)) return 'Enter an absolute path without "..", e.g. /home/you/shop-data';
        $served = Setup::enclosingWebRoot($root) ?? $root;
        foreach ([$root, $served] as $public) {
            if ($normalize($parent) === $normalize($public) || str_starts_with($normalize($parent) . '/', $normalize($public) . '/')) {
                return 'This folder is served by the web server: choose one outside ' . str_replace('\\', '/', $public) . ' (usually the folder above it).';
            }
        }
        $private = rtrim($parent, '/\\') . '/bareboneseshop-private';
        if (is_file($private . '/db/shop.sqlite') && (int)filesize($private . '/db/shop.sqlite') > 0) {
            return "{$private} already holds the database of another shop: choose another folder or move it away.";
        }
        // Missing folders are created (e.g. ../bareboneseshop-data/shop): the nearest existing one must be writable.
        for ($existing = $private; !file_exists($existing) && dirname($existing) !== $existing; $existing = dirname($existing));
        return is_dir($existing) && is_writable($existing) ? null
            : "PHP cannot write to {$existing}: create {$parent} and give the web server user write access, or choose another folder.";
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
            $stores = self::configureStore($root, $config, $v);
            self::configurePayments(Db::get(), $stores, $v);
            $default = (string)($stores['default_store'] ?? 'default');
            $owner = AdminIdentity::createWithStore($v['admin_user'], $v['admin_password'], 'owner', true, $default, true);
            if ($starter !== null) self::baselineRelease($starter, $config, $root);
            $leftover = $seeded ? self::removeStarter($starter, $root) : null;
            return ['admin' => ['username' => $v['admin_user']] + (array)$owner['enrollment'], 'doctor' => Setup::doctor($config, $root, Db::get()),
                'starter' => $starter['name'] ?? null, 'leftover' => $leftover];
        } catch (Throwable $e) {
            @unlink($root . '/config.php');
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
            return [null, "Installation failed (reference {$reference}). Nothing was installed; the details are in the PHP error log of your hosting."];
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

    /** The installer's HTML page (inline styles only: the strict CSP of install.php allows nothing else). */
    public static function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
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
            if ($check['status'] === 'pass') continue;
            $rows .= '<tr><td>' . self::h(strtoupper($check['status'])) . '</td><td>' . self::h($check['message']) . ($check['action'] !== '' ? '<p class="hint">' . self::h($check['action']) . '</p>' : '') . '</td></tr>';
        }
        return '<p>' . ($result['starter'] !== null ? self::h($result['starter']) . ' is installed with its demo catalog.' : 'The shop is installed.') . '</p>'
            . '<h2>Sign-in for the administration</h2><p>User name: <strong>' . self::h($admin['username']) . '</strong>. Signing in needs your password and a code from an authenticator app (Google Authenticator, Aegis, 1Password …).</p>'
            . '<p>Scan this code with the app now. It is shown only once.</p>' . QrCode::svg((string)($admin['uri'] ?? ''), 'TOTP for ' . (string)$admin['username'])
            . '<p class="hint">Or enter the key by hand: <code>' . self::h($admin['secret'] ?? '') . '</code></p>'
            . '<p><strong>Recovery codes</strong> (each works once if you lose the phone; store them safely, they are not shown again):</p><p><code>' . self::h(implode('  ', (array)($admin['recovery_codes'] ?? []))) . '</code></p>'
            . '<h2>Health check</h2><p>' . self::h($doctor['counts']['pass']) . ' checks passed, ' . self::h($doctor['counts']['warn']) . ' warnings, ' . self::h($doctor['counts']['fail']) . ' failures.</p>'
            . ($rows !== '' ? '<table>' . $rows . '</table>' : '')
            . ($result['leftover'] !== null ? '<p class="error">Delete the folder ' . self::h(substr(str_replace('\\', '/', $result['leftover']), strlen(str_replace('\\', '/', $root)) + 1))
                . ' with your FTP client: it still holds the starter database and the eBook files, which now live in the private data folder.</p>' : '')
            . '<p><a href="./">Open the shop</a> · <a href="admin.php">Sign in to the administration</a></p>';
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
        $store['legal'] = [
            'name' => $v['company'], 'street' => $v['street'], 'city' => $v['city'], 'zip' => $v['zip'], 'country' => $v['country'],
            'vat_registered' => $v['vat_id'] !== '', 'ic_dph' => $v['vat_id'], 'iban' => $v['iban'], 'email' => $v['email'],
            'invoice_prefix' => (string)($legal['invoice_prefix'] ?? ''), 'payment_terms_days' => (int)($legal['payment_terms_days'] ?? 14),
        ];
        foreach (['ico', 'dic', 'bic'] as $key) {
            if ((string)($v[$key] ?? '') !== '') $store['legal'][$key] = (string)$v[$key];
        }
        $stores['stores'][$id] = $store;
        // The installer sets up one seller. Other stores still naming the example seller go offline until the merchant
        // enters theirs in Admin → Stores and seller (as setup apply does with the hidden sample stores).
        foreach ((array)$stores['stores'] as $otherId => $other) {
            if ((string)$otherId === $id || !is_array($other) || !is_array($other['legal'] ?? null) || isset($other['legal']['seller'])) continue;
            if (Setup::legalIdentityProblems($other['legal']) !== []) $stores['stores'][$otherId]['public'] = false;
        }
        return $stores;
    }

    /**
     * Cash on delivery and bank transfer as chosen for the default store; card (Stripe) only with Stripe keys, in every
     * store (without keys checkout could not start the payment); invoice stays off.
     */
    public static function configurePayments(PDO $db, array $stores, array $v): void
    {
        $default = (string)($stores['default_store'] ?? 'default');
        $set = $db->prepare('UPDATE payment_methods SET active = :active WHERE store_id = :scope AND code = :code');
        foreach ((array)($stores['stores'] ?? []) as $id => $store) {
            $scope = (string)(is_array($store) ? ($store['provider_scope'] ?? $id) : $id);
            $wanted = ['card' => ($v['stripe_secret_key'] ?? '') !== ''];
            if ((string)$id === $default) $wanted += ['cod' => (bool)$v['pay_cod'], 'bank_transfer' => (bool)$v['pay_bank'], 'invoice' => false];
            foreach ($wanted as $code => $active) $set->execute([':active' => $active ? 1 : 0, ':scope' => $scope, ':code' => $code]);
        }
    }
}
