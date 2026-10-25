<?php
declare(strict_types=1);

/**
 * Merchant data the admin saves outside the application code: stores.json and the legal texts. A Docker install
 * mounts the code read-only, so the admin writes them into the private runtime directory (config
 * private_runtime_dir), and a copy there takes precedence over the shipped file.
 */
final class DataOverrides
{
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

    /** The stores.json in use: the saved copy in the data directory, else the one shipped in $appRoot. */
    public static function storesPath(?string $appRoot = null, ?array $config = null): string
    {
        $dir = self::dir($config);
        if ($dir !== null && is_file($dir . '/stores.json')) return $dir . '/stores.json';
        return ($appRoot ?? dirname(__DIR__)) . '/stores.json';
    }

    /** Where the admin saves stores.json: the data directory when the shipped file cannot be written. */
    public static function storesWritePath(): ?string
    {
        $current = self::storesPath();
        if (is_file($current) && is_writable($current) && is_writable(dirname($current))) return $current;
        $dir = self::dir();
        return $dir !== null && self::ensureDir($dir) ? $dir . '/stores.json' : null;
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
