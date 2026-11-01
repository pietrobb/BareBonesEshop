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

    /** Test hook: another data directory (null = config private_runtime_dir). */
    public static function setDirForTesting(?string $dir): void
    {
        self::$testDir = $dir;
    }

    /** The writable data directory, or null when none is configured. */
    public static function dir(?array $config = null): ?string
    {
        if (self::$testDir !== null) return rtrim(str_replace('\\', '/', self::$testDir), '/');
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
        if ($content === false || !is_array(json_decode($content, true))) throw new RuntimeException("Cannot read {$source}");
        // The example's sample stores (pl, hu, de, us with made-up sellers) come in hidden: no storefront until the
        // merchant enters the seller and publishes the store in Admin → Stores and seller.
        if ($source !== $legacy) $content = (string)preg_replace('/"sample"\s*:\s*true\b/', '"public": false', $content);
        if (!self::ensureDir($dir) || !self::atomicWrite($target, $content)) throw new RuntimeException("Cannot write {$target}");
        @chmod($target, 0640);
        if ($source !== $legacy) return ['path' => $target, 'action' => 'created from ' . self::STORES_EXAMPLE];
        // A read-only code directory (Docker) keeps the old file; the data directory copy takes precedence anyway.
        return ['path' => $target, 'action' => @unlink($legacy) ? 'moved from the application root' : 'copied from the application root'];
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
