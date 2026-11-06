<?php
declare(strict_types=1);

/**
 * Maintenance window of the updater (review round 14, T20): while cli/update.php replaces files and migrates the
 * database, web requests get 503 instead of running a mix of old and new code against a half-migrated schema, and
 * the job worker waits. The marker is <app root>/.maintenance.
 *
 * - R17: the process that entered maintenance holds an exclusive lock on the marker until it leaves. While anyone
 *   holds it the shop stays closed, also when the check runs in another container on the same volume (docker
 *   compose). The kernel drops the lock when that process ends, so a reused PID cannot keep the shop closed.
 *   R18: any process that can open the marker could hold such a lock, so it counts for MAX_AGE at most.
 * - Without a lock holder, a fresh marker (younger than STALE_AFTER) still blocks: an updater killed a moment ago
 *   may have left half-written files. An older one blocks only while the PID it names still runs on this host as the
 *   same process (same start time), at most MAX_AGE — for a file system without locks.
 * - A failed step that left the schema or the files half-done marks the window failed (fail()): it never goes stale,
 *   and the next successful `php cli/migrate.php apply`, update or rollback lifts it.
 */
final class MaintenanceMode
{
    public const FILE = '.maintenance';
    public const STALE_AFTER = 3600;
    public const MAX_AGE = 86400;

    /** @var array<string,resource> marker path => the open handle this process holds the lock on */
    private static array $held = [];

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
        $marker = self::marker($root);
        if (($marker['failed'] ?? false) === true) return true;
        if ($since > time() - self::STALE_AFTER) return true;
        if ($since <= time() - self::MAX_AGE) return false;
        // R18: anyone who can open the marker can lock it, so a lock alone keeps the shop closed for MAX_AGE at most.
        if (self::lockHeld($path)) return true;
        return ($marker['host'] ?? null) === (string)gethostname() && self::running((int)($marker['pid'] ?? 0), $marker['start'] ?? null);
    }

    /** The marker's content ([] when there is none or it cannot be read). */
    public static function marker(string $root): array
    {
        $marker = json_decode((string)@file_get_contents(self::path($root)), true);
        return is_array($marker) ? $marker : [];
    }

    /** Whether a failed step keeps the shop closed (only one whose reason starts with $by, when given). */
    public static function failed(string $root, string $by = ''): bool
    {
        $marker = self::marker($root);
        return ($marker['failed'] ?? false) === true && str_starts_with((string)($marker['reason'] ?? ''), $by);
    }

    public static function enter(string $root, string $reason): void
    {
        $path = self::path($root);
        self::release($path);
        self::write($path, ['since' => gmdate('c'), 'reason' => $reason, 'pid' => getmypid(), 'host' => (string)gethostname(),
            'start' => self::startTime(getmypid())]);
        $handle = @fopen($path, 'r');
        if (is_resource($handle) && flock($handle, LOCK_EX | LOCK_NB)) {
            self::$held[$path] = $handle;
        } elseif (is_resource($handle)) {
            fclose($handle);
        }
    }

    /**
     * Keep the shop closed after a step that failed half-way (a migration that stopped part-way, a rollback that
     * failed): the marker no longer expires, and the reason says what to run.
     */
    public static function fail(string $root, string $reason): void
    {
        $path = self::path($root);
        self::release($path);
        self::write($path, ['since' => gmdate('c'), 'reason' => $reason, 'failed' => true, 'host' => (string)gethostname()]);
    }

    /**
     * Put back a window found before a step that then changed nothing (content and age): a failed one stays failed,
     * the fresh marker of an updater killed half-way keeps blocking exactly as long as it did.
     */
    public static function restore(string $root, array $marker, int $modifiedAt): void
    {
        $path = self::path($root);
        self::release($path);
        self::write($path, $marker);
        @touch($path, $modifiedAt);
        clearstatcache(true, $path);
    }

    public static function leave(string $root): void
    {
        $path = self::path($root);
        self::release($path);
        @unlink($path);
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

    private static function write(string $path, array $marker): void
    {
        $json = json_encode($marker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (!DataOverrides::atomicWrite($path, $json)) {
            throw new RuntimeException('Cannot write the maintenance marker ' . $path . '; the shop would keep serving during the update');
        }
    }

    private static function release(string $path): void
    {
        if (!isset(self::$held[$path])) return;
        flock(self::$held[$path], LOCK_UN);
        fclose(self::$held[$path]);
        unset(self::$held[$path]);
    }

    /** Whether a process holds the marker's lock (an update running here, on this host or in another container). */
    private static function lockHeld(string $path): bool
    {
        if (isset(self::$held[$path])) return true;
        $handle = @fopen($path, 'r');
        if (!is_resource($handle)) return false;
        $wouldBlock = 0;
        $free = flock($handle, LOCK_SH | LOCK_NB, $wouldBlock);
        if ($free) flock($handle, LOCK_UN);
        fclose($handle);
        // Windows does not report "would block"; a refused shared lock there means the same.
        return !$free && ($wouldBlock === 1 || PHP_OS_FAMILY === 'Windows');
    }

    /**
     * Whether this process still runs as the one that wrote the marker. With /proc the start time must match, so a
     * reused PID does not count; without a start time to compare it is unknown: no.
     */
    private static function running(int $pid, mixed $start): bool
    {
        if ($pid <= 0 || !is_string($start) || $start === '') return false;
        return self::startTime($pid) === $start;
    }

    /** The process's start time from /proc/<pid>/stat (field 22, clock ticks since boot), or null without /proc. */
    private static function startTime(int $pid): ?string
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($stat) || ($close = strrpos($stat, ')')) === false) return null;
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1))) ?: [];
        return isset($fields[19]) && ctype_digit($fields[19]) ? $fields[19] : null;
    }
}
