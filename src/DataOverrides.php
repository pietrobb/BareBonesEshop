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
        if (self::$suiteDir !== null) return self::$suiteDir;
        $dir = getenv('BBE_SUITE_DATA_DIR');
        $root = getenv('BBE_SUITE_APP_ROOT');
        if (!is_string($dir) || $dir === '' || !is_string($root) || $root === '') return null;
        return realpath(dirname(__DIR__)) === $root ? $dir : null;
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
            if (!self::ensureDir($dir) || !self::atomicWrite($target, $json)) throw new RuntimeException("Cannot write {$target}");
            @chmod($target, 0640);
            return ['path' => $target, 'action' => 'upgraded from the v1.x format (main store "' . self::legacyMainStore($data)
                . '" is now "default"); the application root file is kept unchanged for a rollback'];
        }
        // The example's sample stores (cz, pl, hu, de, us with made-up sellers) come in hidden: no storefront until the
        // merchant enters the seller and publishes the store in Admin → Stores and seller.
        if ($source !== $legacy) $content = (string)preg_replace('/"sample"\s*:\s*true\b/', '"public": false', $content);
        if (!self::ensureDir($dir) || !self::atomicWrite($target, $content)) throw new RuntimeException("Cannot write {$target}");
        @chmod($target, 0640);
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
        $data = json_decode((string)@file_get_contents(self::storesPath($appRoot, $config)), true);
        return is_array($data) && self::upgradeLegacyStores($data) !== null;
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

    private static function currentStoreId(): string
    {
        return StoreContext::hasCurrent() ? StoreContext::current()->id() : 'default';
    }

    /** Readers see the old or the new file, never a half-written one. */
    public static function atomicWrite(string $file, string $content): bool
    {
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        $mode = @fileperms($file);
        if ($mode !== false) @chmod($tmp, $mode & 0777);
        if (!@rename($tmp, $file)) {
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
