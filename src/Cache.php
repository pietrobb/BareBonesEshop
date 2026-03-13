<?php
/**
 * BareBones Shop — Flat-file Cache Manager
 * Atomic writes, stale-while-revalidate, per-locale paths.
 */
class Cache {
    private static ?string $cacheDir = null;
    private static ?array $cfg = null;

    private static function cfg(): array {
        if (!self::$cfg) {
            self::$cfg = require __DIR__ . '/../config.php';
        }
        return self::$cfg;
    }

    private static function dir(): string {
        if (!self::$cacheDir) {
            $cfg = self::cfg();
            self::$cacheDir = $cfg['cache_dir'] ?? __DIR__ . '/../cache';
        }
        return self::$cacheDir;
    }

    public static function setDir(string $dir): void {
        self::$cacheDir = $dir;
    }

    /**
     * Read cached data (returns null if not found or expired)
     */
    public static function read(string $path, ?int $ttl = null): ?string {
        $file = self::path($path);
        if (!file_exists($file)) return null;

        // Check flush marker
        $flushMarker = self::path('.flush_marker');
        if (file_exists($flushMarker)) {
            $flushTime = (int)file_get_contents($flushMarker);
            if (filemtime($file) < $flushTime) return null;
        }

        // Check TTL (use config default when ttl=0 or not specified)
        if ($ttl !== null) {
            if ($ttl <= 0) {
                $cfg = self::cfg();
                $ttl = $cfg['cache_ttl'] ?? 3600;
            }
            if ((time() - filemtime($file)) > $ttl) return null;
        }

        return file_get_contents($file);
    }

    /**
     * Read and decode JSON cache
     */
    public static function readJson(string $path, ?int $ttl = null): ?array {
        $data = self::read($path, $ttl);
        if ($data === null) return null;
        return json_decode($data, true);
    }

    /**
     * Atomic write — write to .tmp then rename
     */
    public static function write(string $path, string $data): void {
        $file = self::path($path);
        $tmp = $file . '.tmp.' . getmypid();
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        file_put_contents($tmp, $data, LOCK_EX);
        rename($tmp, $file);
    }

    /**
     * Write JSON to cache
     */
    public static function writeJson(string $path, array $data): void {
        self::write($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Check if cache file exists and is fresh
     */
    public static function fresh(string $path, ?int $ttl = null): bool {
        return self::read($path, $ttl) !== null;
    }

    /**
     * Invalidate — mark as stale, existing content serves until rebuilt
     */
    public static function invalidate(string $path): void {
        $file = self::path($path);
        if (file_exists($file)) {
            // Set mtime far in the past so TTL checks treat it as expired,
            // but file still exists for stale-while-revalidate
            touch($file, 1);
        }
        // Queue rebuild if JobQueue is available
        if (class_exists('JobQueue')) {
            JobQueue::push('cache_rebuild', ['path' => $path], 1);
        }
    }

    /**
     * Invalidate all files with a prefix (mark stale, don't delete)
     */
    public static function invalidatePrefix(string $prefix): void {
        $dir = self::path($prefix);
        if (is_dir($dir)) {
            self::touchDirStale($dir);
        }
        if (class_exists('JobQueue')) {
            JobQueue::push('cache_rebuild', ['prefix' => $prefix], 2);
        }
    }

    /**
     * Flush all — mark everything as stale
     */
    public static function flush(): void {
        file_put_contents(self::path('.flush_marker'), (string)time());
    }

    /**
     * Get/set simple key-value
     */
    public static function get(string $key): ?string {
        return self::read('kv/' . md5($key));
    }

    public static function set(string $key, string $value): void {
        self::write('kv/' . md5($key), $value);
    }

    /**
     * Build locale-prefixed path
     */
    public static function localePath(string $locale, string $path): string {
        return $locale . '/' . $path;
    }

    /**
     * Full filesystem path for a cache key
     */
    public static function path(string $key): string {
        if (str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new \InvalidArgumentException('Invalid cache key');
        }
        return self::dir() . '/' . $key;
    }

    /** Mark all files in directory as stale (set mtime to past) */
    private static function touchDirStale(string $dir): void {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                self::touchDirStale($path);
            } else {
                touch($path, 1);
            }
        }
    }

    /** Delete directory recursively (used only by flush for cleanup) */
    private static function deleteDir(string $dir): void {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                self::deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
