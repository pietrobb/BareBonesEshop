<?php
declare(strict_types=1);

/**
 * Maintenance window of the updater (review round 14, T20): while cli/update.php replaces files and migrates the
 * database, web requests get 503 instead of running a mix of old and new code against a half-migrated schema, and
 * the job worker waits. The marker is <app root>/.maintenance; a marker older than STALE_AFTER (an updater killed
 * mid-way) no longer blocks the shop.
 */
final class MaintenanceMode
{
    public const FILE = '.maintenance';
    public const STALE_AFTER = 3600;

    public static function path(string $root): string
    {
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . self::FILE;
    }

    public static function active(string $root): bool
    {
        clearstatcache(true, self::path($root));
        $since = @filemtime(self::path($root));
        return $since !== false && $since > time() - self::STALE_AFTER;
    }

    public static function enter(string $root, string $reason): void
    {
        $json = json_encode(['since' => gmdate('c'), 'reason' => $reason], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
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
