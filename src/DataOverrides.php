<?php
declare(strict_types=1);

/**
 * Merchant data the admin saves outside the application code: stores.json and the legal texts. A Docker install
 * mounts the code read-only, so the admin writes them into the private runtime directory (config
 * private_runtime_dir), and a copy there takes precedence over the shipped file.
 */
final class DataOverrides
{
    public const STORES_EXAMPLE = 'stores.example.json';
    /** Largest settings file (stores.json, mail settings) readRegularFile() reads: far above any real shop's. */
    public const MAX_SETTINGS_BYTES = 8 * 1048576;
    /** R58: the merchant's settings of the shipping.json delivery methods (shippingMethods()). */
    public const SHIPPING_METHODS = 'shipping-methods.json';

    private static ?string $testDir = null;
    private static ?string $suiteDir = null;

    /** Test hook: another data directory (null = back to the suite's directory, else config private_runtime_dir). */
    public static function setDirForTesting(?string $dir): void
    {
        self::$testDir = $dir;
    }

    /**
     * Test suite: its own data directory for the whole run, so the suite never reads (or writes) the stores.json and
     * legal texts of a shop installed in this checkout (`setup.php install` puts them in private_runtime_dir).
     * The environment carries it to the PHP processes the suite starts (php -S servers, fixtures).
     */
    public static function isolateSuiteForTesting(string $dir): void
    {
        self::$suiteDir = $dir;
        putenv('BBE_SUITE_DATA_DIR=' . $dir);
        putenv('BBE_SUITE_APP_ROOT=' . (string)realpath(dirname(__DIR__)));
    }

    /** The suite's data directory in this process, or inherited from the suite for this same application root. */
    private static function suiteDir(): ?string
    {
        return self::$suiteDir ?? AppConfig::suiteDataDir(dirname(__DIR__));
    }

    /** The writable data directory, or null when none is configured. */
    public static function dir(?array $config = null): ?string
    {
        // The suite's directory stands in for config.php's private_runtime_dir, not for a config a caller passes.
        $test = self::$testDir ?? ($config === null ? self::suiteDir() : null);
        if ($test !== null) return rtrim(str_replace('\\', '/', $test), '/');
        // Before install (no config.php yet) there is no data directory, only the shipped files.
        if ($config === null && !is_file(dirname(__DIR__) . '/config.php')) return null;
        $config ??= Security::config();
        $dir = rtrim(str_replace('\\', '/', (string)($config['private_runtime_dir'] ?? '')), '/');
        return $dir !== '' ? $dir : null;
    }

    /**
     * The stores.json in use: the copy in the data directory, else a stores.json in $appRoot (an install from
     * before 2.1, ignored by git), else the shipped stores.example.json. Git tracks only the example, so neither
     * an admin save nor a hand edit ever conflicts with `git pull`.
     */
    public static function storesPath(?string $appRoot = null, ?array $config = null): string
    {
        $dir = self::dir($config);
        if ($dir !== null && is_file($dir . '/stores.json')) return $dir . '/stores.json';
        $root = $appRoot ?? dirname(__DIR__);
        return is_file($root . '/stores.json') ? $root . '/stores.json' : $root . '/' . self::STORES_EXAMPLE;
    }

    /** Where the admin saves stores.json: the data directory, else stores.json in the application root; never the example. */
    public static function storesWritePath(): ?string
    {
        $dir = self::dir();
        if ($dir !== null && self::ensureDir($dir)) return $dir . '/stores.json';
        $root = dirname(__DIR__) . '/stores.json';
        return (is_file($root) ? is_writable($root) : is_writable(dirname($root))) ? $root : null;
    }

    /**
     * `setup apply`: give the install its own stores.json in the data directory — the stores.json an older install
     * kept in the application root (moved, so only one copy is ever edited), else a copy of the example.
     * @return array{path: string, action: string}|null  null without a data directory
     */
    public static function seedStores(string $appRoot, array $config): ?array
    {
        $dir = self::dir($config);
        if ($dir === null) return null;
        $target = $dir . '/stores.json';
        if (is_file($target)) return ['path' => $target, 'action' => 'unchanged'];
        $legacy = $appRoot . '/stores.json';
        $source = is_file($legacy) ? $legacy : $appRoot . '/' . self::STORES_EXAMPLE;
        if (!is_file($source)) return null; // not an application tree; doctor reports the missing stores
        $content = @file_get_contents($source);
        $data = $content === false ? null : json_decode($content, true);
        if (!is_array($data)) throw new RuntimeException("Cannot read {$source}");
        // A v1.x stores.json is upgraded into the copy; the old file stays, so a rollback to v1.x still reads its own.
        $upgraded = $source === $legacy ? self::upgradeLegacyStores($data) : null;
        if ($upgraded !== null) {
            $json = json_encode($upgraded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            if (!self::ensureDir($dir) || !self::atomicWrite($target, $json, 0640)) throw new RuntimeException("Cannot write {$target}");
            return ['path' => $target, 'action' => 'upgraded from the v1.x format (main store "' . self::legacyMainStore($data)
                . '" is now "default"); the application root file is kept unchanged for a rollback'];
        }
        // The example's sample stores (cz, pl, hu, de, us with made-up sellers) come in hidden: no storefront until the
        // merchant enters the seller and publishes the store in Admin → Stores and seller.
        if ($source !== $legacy) $content = (string)preg_replace('/"sample"\s*:\s*true\b/', '"public": false', $content);
        // R54 (review round 19, UX): the system language (the admin's) is the main store's, as the web installer sets it
        // (was: the example's "en" over a Slovak shop after `setup.php install`).
        $mainLocale = (string)($data['stores'][(string)($data['default_store'] ?? 'default')]['default_locale'] ?? '');
        if ($source !== $legacy && preg_match('/^[a-z]{2}$/', $mainLocale) === 1 && ($data['default_locale'] ?? null) !== $mainLocale) {
            $relocalized = (string)preg_replace('/"default_locale"\s*:\s*"[^"]*"/', '"default_locale": "' . $mainLocale . '"', $content, 1);
            $check = json_decode($relocalized, true);
            if (is_array($check) && ($check['default_locale'] ?? null) === $mainLocale) $content = $relocalized;
        }
        if (!self::ensureDir($dir) || !self::atomicWrite($target, $content, 0640)) throw new RuntimeException("Cannot write {$target}");
        if ($source !== $legacy) return ['path' => $target, 'action' => 'created from ' . self::STORES_EXAMPLE];
        // A read-only code directory (Docker) keeps the old file; the data directory copy takes precedence anyway.
        return ['path' => $target, 'action' => @unlink($legacy) ? 'moved from the application root' : 'copied from the application root'];
    }

    /**
     * R15 high 7: a v1.x stores.json has no default_store, no store ids and no channels, and keys the main store (the
     * one without url_prefix) by its country ("sk"). Every row the migrations scoped belongs to the store 'default',
     * and the workers, cron and admin open that store: the shop answered 503. Returns the current format (the main
     * store renamed to 'default', the others keep their keys; one shared catalog; the API channel), or null when $data
     * is not a v1.x file. Additive otherwise: every existing field is kept.
     */
    public static function upgradeLegacyStores(array $data): ?array
    {
        $stores = $data['stores'] ?? null;
        if (isset($data['default_store']) || !is_array($stores) || $stores === []) return null;
        $main = self::legacyMainStore($data);
        $mainCurrency = strtolower((string)($stores[$main]['currency'] ?? ''));
        $upgraded = [];
        foreach ($stores as $key => $store) {
            if (!is_array($store)) continue;
            $id = (string)$key === $main ? 'default' : (string)$key;
            $sameProvider = $id === 'default' || strtolower((string)($store['currency'] ?? '')) === $mainCurrency;
            $upgraded[$id] = ['id' => $id] + $store + ['domains' => [], 'catalog_scope' => 'default',
                'provider_scope' => $sameProvider ? 'default' : $id, 'theme' => 'default', 'channels' => ['storefront', 'api']];
        }
        $data['default_store'] = 'default';
        $data['stores'] = $upgraded;
        return $data;
    }

    /** The main store of a v1.x stores.json: 'default' when present, else the one without url_prefix, else the first. */
    private static function legacyMainStore(array $data): string
    {
        $stores = (array)($data['stores'] ?? []);
        if (isset($stores['default'])) return 'default';
        foreach ($stores as $key => $store) {
            if (is_array($store) && rtrim((string)($store['url_prefix'] ?? ''), '/') === '') return (string)$key;
        }
        return (string)array_key_first($stores);
    }

    /** True when the stores.json in use is still in the v1.x format (see upgradeLegacyStores). */
    public static function storesNeedUpgrade(string $appRoot, array $config): bool
    {
        $data = json_decode((string)self::readRegularFile(self::storesPath($appRoot, $config)), true);
        return is_array($data) && self::upgradeLegacyStores($data) !== null;
    }

    /**
     * R47 (review round 17, low): the contents of a regular file, else null. Opened non-blocking ('n' = O_NONBLOCK), so a
     * FIFO swapped in after storesPath()'s is_file() cannot stall the caller (root's update doctor/check); fstat() of
     * the open handle then refuses anything but a regular file. R54 (review round 19, low): nor larger than $maxBytes —
     * a 3 GB stores.json held root's update check for a minute.
     */
    public static function readRegularFile(string $path, int $maxBytes = self::MAX_SETTINGS_BYTES): ?string
    {
        $handle = @fopen($path, 'rn');
        if ($handle === false) return null;
        try {
            $stat = fstat($handle);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || (int)$stat['size'] > $maxBytes) return null;
            $contents = stream_get_contents($handle, $maxBytes + 1);
            return $contents === false || strlen($contents) > $maxBytes ? null : $contents;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The merchant's legal text of $page in $locale for one store (null = the current store), or null to use the
     * shipped template. Texts are per store: the US store's English terms are not the terms of an EU store that also
     * speaks English. A text saved before 2.1 for a language (legal/<locale>/) still applies to every store of that
     * language until the texts are split per store (setup apply, or the first save in the admin).
     */
    public static function legalPath(string $page, string $locale, ?string $storeId = null): ?string
    {
        $storeId ??= self::currentStoreId();
        if (!self::validLegal($page, $locale, $storeId) || ($dir = self::dir()) === null) return null;
        foreach (["{$dir}/legal/stores/{$storeId}/{$locale}/{$page}.html", "{$dir}/legal/{$locale}/{$page}.html"] as $file) {
            if (is_file($file)) return $file;
        }
        return null;
    }

    public static function validLegal(string $page, string $locale, string $storeId = 'default'): bool
    {
        return in_array($page, ConsumerNotice::LEGAL_PAGES, true) && preg_match('/^[a-z]{2}$/', $locale) === 1
            && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $storeId) === 1;
    }

    /** Save (or with '' remove) one store's own legal text; false when the data directory is not writable. */
    public static function saveLegal(string $page, string $locale, string $html, ?string $storeId = null): bool
    {
        $storeId ??= self::currentStoreId();
        if (!self::validLegal($page, $locale, $storeId) || ($dir = self::dir()) === null) return false;
        if (self::splitLegacyLegal() === null) return false;
        $file = "{$dir}/legal/stores/{$storeId}/{$locale}/{$page}.html";
        if (trim($html) === '') return !is_file($file) || @unlink($file);
        return self::ensureDir(dirname($file)) && self::atomicWrite($file, $html);
    }

    /** Delete a removed store's own legal texts (legal/stores/<id>/<locale>/<page>.html). */
    public static function removeStoreLegal(string $storeId): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $storeId) !== 1 || ($dir = self::dir()) === null) return;
        $root = "{$dir}/legal/stores/{$storeId}";
        foreach (glob("{$root}/[a-z][a-z]/*.html") ?: [] as $file) @unlink($file);
        foreach (glob("{$root}/[a-z][a-z]", GLOB_ONLYDIR) ?: [] as $locale) @rmdir($locale);
        @rmdir($root);
    }

    /**
     * Give every store its own copy of the legal texts saved before 2.1 per language (legal/<locale>/<page>.html):
     * each store speaking that language keeps the text it showed, then the shared file is removed, so a later
     * change for one store no longer changes the others. Idempotent; a store with its own text keeps it.
     * @return list<string>|null  the store files written; null when a copy failed (the shared file is kept)
     */
    public static function splitLegacyLegal(?array $stores = null, ?array $config = null): ?array
    {
        if (($dir = self::dir($config)) === null) return [];
        $legacy = glob("{$dir}/legal/[a-z][a-z]/*.html") ?: [];
        if ($legacy === []) return [];
        $stores ??= (array)(StoreSettings::load()['stores'] ?? []);
        $written = [];
        foreach ($legacy as $file) {
            $locale = basename(dirname($file));
            $page = basename($file, '.html');
            $html = @file_get_contents($file);
            if ($html === false || !self::validLegal($page, $locale)) continue;
            $copied = true;
            $matched = false;
            foreach ($stores as $storeId => $store) {
                if (!is_array($store) || !self::validLegal($page, $locale, (string)$storeId)) continue;
                $languages = array_map('strval', (array)($store['languages'] ?? []));
                if (!in_array($locale, $languages, true) && (string)($store['default_locale'] ?? '') !== $locale) continue;
                $matched = true;
                $target = "{$dir}/legal/stores/{$storeId}/{$locale}/{$page}.html";
                if (is_file($target)) continue;
                if (self::ensureDir(dirname($target)) && self::atomicWrite($target, $html)) $written[] = $target;
                else $copied = false;
            }
            if (!$copied) return null;
            if (!$matched) continue; // no store speaks that language: kept for a store added with it later
            @unlink($file);
            @rmdir(dirname($file));
        }
        return $written;
    }

    /**
     * R58 (review round 23, medium S4): the merchant's settings of the shipping.json delivery methods (Standard, Express)
     * from Admin → Shipping — on or off, prices per zone, free shipping and delivery days — kept in the data directory
     * so shipping.json stays as shipped (a hand edit stopped every update at "installed file was locally modified").
     * Shipping::config() lays them over shipping.json. [] when nothing was saved (or no data directory).
     * @return array{methods?: array<string, array<string, mixed>>, invalid?: true}
     */
    public static function shippingMethods(?array $config = null): array
    {
        $dir = self::dir($config);
        if ($dir === null || !file_exists($dir . '/' . self::SHIPPING_METHODS)) return [];
        $path = $dir . '/' . self::SHIPPING_METHODS;
        $data = json_decode((string)PrivateOwner::run(static fn(): ?string => self::readRegularFile($path)), true);
        if (is_array($data) && is_array($data['methods'] ?? null)) return ['methods' => $data['methods']];
        // R59 (review round 24, low): a file that cannot be read keeps Standard and Express off (was: [], so methods the
        // merchant had turned off were offered again at shipping.json's prices). The doctor names the file.
        return ['methods' => array_fill_keys(Shipping::CHECKOUT_METHODS, ['active' => false]), 'invalid' => true];
    }

    /** Save the shipping.json method settings (shippingMethods()); false without a writable data directory. */
    public static function saveShippingMethods(array $methods, ?array $config = null): bool
    {
        $dir = self::dir($config);
        if ($dir === null || !self::ensureDir($dir)) return false;
        $json = json_encode(['methods' => $methods === [] ? new stdClass() : $methods], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) && self::atomicWrite($dir . '/' . self::SHIPPING_METHODS, $json . "\n", 0640);
    }

    private static function currentStoreId(): string
    {
        return StoreContext::hasCurrent() ? StoreContext::current()->id() : 'default';
    }

    /**
     * Readers see the old or the new file, never a half-written one. The new file gets $mode, else the mode of the regular
     * file it replaces (read without following a link), else the umask's.
     * R27 (finding 1): the temporary file is created exclusively with that mode (umask), never chmodded by its name. The
     * directory may be the web user's: it renamed a symbolic link to /etc/shadow over the temporary file between its write
     * and the chmod, and a step run as root made /etc/shadow 0666.
     */
    public static function atomicWrite(string $file, string $content, ?int $mode = null): bool
    {
        clearstatcache(true, $file);
        $old = @lstat($file);
        $mode ??= is_array($old) && ($old['mode'] & 0170000) === 0100000 ? $old['mode'] & 0666 : null;
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $umask = $mode !== null ? umask(~$mode & 0777) : null;
        try {
            $handle = @fopen($tmp, 'x');
        } finally {
            if ($umask !== null) umask($umask);
        }
        if (!is_resource($handle)) return false;
        $written = @fwrite($handle, $content) === strlen($content) && @fflush($handle);
        fclose($handle);
        if (!$written || !@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
        return true;
    }

    private static function ensureDir(string $dir): bool
    {
        return (is_dir($dir) || @mkdir($dir, 0750, true)) && is_writable($dir);
    }
}
