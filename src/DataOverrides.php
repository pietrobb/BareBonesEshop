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
        if (!self::ensureDir($dir) || !self::atomicWrite($target, $content)) throw new RuntimeException("Cannot write {$target}");
        @chmod($target, 0640);
        if ($source !== $legacy) return ['path' => $target, 'action' => 'created from ' . self::STORES_EXAMPLE];
        // A read-only code directory (Docker) keeps the old file; the data directory copy takes precedence anyway.
        return ['path' => $target, 'action' => @unlink($legacy) ? 'moved from the application root' : 'copied from the application root'];
    }

    /** A saved legal text of $page in $locale, or null to use the shipped template. */
    public static function legalPath(string $page, string $locale): ?string
    {
        if (!self::validLegal($page, $locale) || ($dir = self::dir()) === null) return null;
        $file = "{$dir}/legal/{$locale}/{$page}.html";
        return is_file($file) ? $file : null;
    }

    public static function validLegal(string $page, string $locale): bool
    {
        return in_array($page, ConsumerNotice::LEGAL_PAGES, true) && preg_match('/^[a-z]{2}$/', $locale) === 1;
    }

    /** Save (or with '' remove) the merchant's own legal text; false when the data directory is not writable. */
    public static function saveLegal(string $page, string $locale, string $html): bool
    {
        if (!self::validLegal($page, $locale) || ($dir = self::dir()) === null) return false;
        $file = "{$dir}/legal/{$locale}/{$page}.html";
        if (trim($html) === '') return !is_file($file) || @unlink($file);
        return self::ensureDir(dirname($file)) && self::atomicWrite($file, $html);
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
