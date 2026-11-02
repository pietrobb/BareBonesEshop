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

    public static function installed(string $root): bool
    {
        return is_file($root . '/config.php');
    }

    /**
     * The starter data of a demo package: demo/<name>/dist/starter.json naming a ready SQLite database, its private
     * downloads and its stores.json. demo/ is denied over HTTP. Null for a plain install.
     * @return array{name: string, database: string, downloads: string, stores: string}|null
     */
    public static function starter(string $root): ?array
    {
        foreach (glob($root . '/demo/*/dist/starter.json') ?: [] as $file) {
            $manifest = json_decode((string)file_get_contents($file), true);
            if (!is_array($manifest)) continue;
            $dir = dirname($file);
            $path = static fn(string $key, string $default): string => $dir . '/' . basename((string)($manifest[$key] ?? $default));
            $starter = ['name' => (string)($manifest['name'] ?? 'Shop'), 'database' => $path('database', 'shop.sqlite'),
                'downloads' => $path('downloads', 'downloads'), 'stores' => $path('stores', 'stores.json')];
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
        foreach (['shop_name', 'app_url', 'private_parent', 'email', 'company', 'street', 'city', 'zip', 'country', 'vat_id', 'iban', 'timezone', 'admin_user', 'stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret'] as $key) {
            $v[$key] = $text($key);
        }
        $v['country'] = strtoupper($v['country']);
        $v['iban'] = strtoupper((string)preg_replace('/\s+/', '', $v['iban']));
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
     * @return array{admin: array<string, mixed>, doctor: array<string, mixed>, starter: string|null}
     */
    public static function install(array $v, string $root): array
    {
        $stripe = array_filter(array_intersect_key($v, array_flip(self::STRIPE_FIELDS)), static fn($value): bool => $value !== '');
        $created = Setup::createConfig($root, $v['app_url'], $v['email'], ['driver' => 'sqlite'], $v['private_parent'], $stripe + ['web_worker' => true]);
        if ($created['action'] !== 'created') throw new RuntimeException('The shop is already installed (config.php exists).');
        $config = require $root . '/config.php';
        $database = (string)$config['db_sqlite_path'];
        $starter = self::starter($root);
        $seeded = false;
        try {
            if ($starter !== null) self::copyStarterStores($starter, $config);
            Setup::apply($config, $root);
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
            return ['admin' => ['username' => $v['admin_user']] + (array)$owner['enrollment'], 'doctor' => Setup::doctor($config, $root, Db::get()), 'starter' => $starter['name'] ?? null];
        } catch (Throwable $e) {
            @unlink($root . '/config.php');
            if ($seeded || (is_file($database) && $starter === null)) {
                foreach (['', '-wal', '-shm', '-journal'] as $suffix) @unlink($database . $suffix);
            }
            throw $e;
        }
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
