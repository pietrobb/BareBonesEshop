<?php
declare(strict_types=1);

/**
 * Maintenance window of the updater (review round 14, T20): while cli/update.php replaces files and migrates the
 * database, web requests get 503 instead of running a mix of old and new code against a half-migrated schema, and
 * the job worker waits. The marker is <app root>/.maintenance; a marker older than STALE_AFTER (an updater killed
 * mid-way) no longer blocks the shop. R16: a longer update (a big migration) keeps its window past STALE_AFTER while
 * the process that wrote the marker still runs on this host, up to MAX_AGE (the PID may have been reused since).
 */
final class MaintenanceMode
{
    public const FILE = '.maintenance';
    public const STALE_AFTER = 3600;
    public const MAX_AGE = 86400;

    public static function path(string $root): string
    {
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . self::FILE;
    }

    public static function active(string $root): bool
    {
        $path = self::path($root);
        clearstatcache(true, $path);
        $since = @filemtime($path);
        if ($since === false) return false;
        if ($since > time() - self::STALE_AFTER) return true;
        if ($since <= time() - self::MAX_AGE) return false;
        $marker = json_decode((string)@file_get_contents($path), true);
        return is_array($marker) && ($marker['host'] ?? null) === (string)gethostname() && self::running((int)($marker['pid'] ?? 0));
    }

    /** Whether this process ID still runs (unknown on a host without /proc and the posix extension: no). */
    private static function running(int $pid): bool
    {
        if ($pid <= 0) return false;
        if (is_dir('/proc/self')) return is_dir('/proc/' . $pid);
        if (!function_exists('posix_kill')) return false;
        // EPERM: the process exists but belongs to another user (the web server checking the CLI updater).
        return posix_kill($pid, 0) || posix_get_last_error() === 1;
    }

    public static function enter(string $root, string $reason): void
    {
        $json = json_encode(['since' => gmdate('c'), 'reason' => $reason, 'pid' => getmypid(), 'host' => (string)gethostname()],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (!DataOverrides::atomicWrite(self::path($root), $json)) {
            throw new RuntimeException('Cannot write the maintenance marker ' . self::path($root) . '; the shop would keep serving during the update');
        }
    }

    public static function leave(string $root): void
    {
        @unlink(self::path($root));
    }

    /** The answer to a web request during maintenance. */
    public static function respond(): never
    {
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
        echo "The shop is being updated. Please try again in a minute.\n";
        exit;
    }
}
