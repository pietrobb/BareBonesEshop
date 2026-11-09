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
 * - A failed step that left the schema or the files half-done marks the window failed (fail()): it never goes stale.
 *   A failed migration is lifted by a successful `php cli/migrate.php apply`, an update that runs the migrations or a
 *   rollback that restores the database; a half-done update or rollback only by the rollback its reason names
 *   (Updater::windowKept()). Any other step puts the failed window back.
 * - R23: an update, rollback, migration or restore marks its window failed (arm()) right before its first change, so
 *   one killed half-way leaves the shop closed with what to run instead of an expiring marker.
 * - R24: those steps exclude each other through one step lock (claim(), <app root>/.maintenance.lock), taken before a
 *   step looks at the window and held until it has left or failed it. Checking the marker's lock and then entering
 *   left a gap: a migration and an update could both enter, and the migration ending first lifted the window of the
 *   update still running.
 */
final class MaintenanceMode
{
    public const FILE = '.maintenance';
    public const LOCK_FILE = '.maintenance.lock';
    public const STALE_AFTER = 3600;
    public const MAX_AGE = 86400;

    /** @var array<string,resource> marker path => the open handle this process holds the lock on */
    private static array $held = [];
    /** @var array<string,resource> step lock path => the open handle of the step this process runs (claim()) */
    private static array $claimed = [];

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

    /**
     * R25: every failed step a failed marker stands for, the one it shows first: its own and those it carries on under it
     * (pending_other; R24 wrote a single reason there). Each is ['reason' => …] plus what lifts it: restore_scope (a
     * restore left half-done: completed by a restore of that much), rollback_snapshot (an update or rollback: completed by
     * the rollback of that snapshot), neither (a migration: completed by a step that migrates or puts the database back,
     * but not by the rollback of a snapshot listed in before_snapshots: taken after it failed, that one holds its schema).
     * Steps match on these fields, never on the reason text: a restore's reason quotes earlier failures ("; earlier: …").
     * Empty when the marker is not failed.
     * @return list<array{reason: string, restore_scope?: string, rollback_snapshot?: string, before_snapshots?: list<string>}>
     */
    public static function failures(array $marker): array
    {
        if (($marker['failed'] ?? false) !== true) return [];
        $more = $marker['pending_other'] ?? [];
        $list = [];
        foreach (array_merge([$marker], is_array($more) ? array_values($more) : [$more]) as $failure) {
            if (is_string($failure)) $failure = ['reason' => $failure];
            if (!is_array($failure) || !is_string($failure['reason'] ?? null) || $failure['reason'] === '') continue;
            $entry = ['reason' => $failure['reason']];
            $scope = $failure['restore_scope'] ?? null;
            $snapshot = $failure['rollback_snapshot'] ?? null;
            if (in_array($scope, ['db', 'files', 'both'], true)) {
                $entry['restore_scope'] = $scope;
            } elseif (str_starts_with($entry['reason'], 'cli/restore.php ')) {
                $entry['restore_scope'] = 'both'; // R24 kept no scope for a carried restore failure: only a full restore covers it
            } elseif (is_string($snapshot) && $snapshot !== '') {
                $entry['rollback_snapshot'] = $snapshot;
            } elseif (preg_match('/^(?:update|rollback) .*?--snapshot=(\S+)/', $entry['reason'], $named) === 1) {
                $entry['rollback_snapshot'] = $named[1]; // written before R25: the updater's own reason names its rollback
            }
            $before = $failure['before_snapshots'] ?? null;
            if (!isset($entry['restore_scope']) && !isset($entry['rollback_snapshot']) && is_array($before)) {
                $before = array_values(array_filter($before, static fn($id): bool => is_string($id) && $id !== ''));
                if ($before !== []) $entry['before_snapshots'] = $before;
            }
            if (!in_array($entry, $list, true)) $list[] = $entry;
        }
        return $list;
    }

    /**
     * R25: end a step's window with the failures still open: none lifts it, otherwise the first is shown and the rest
     * carried on under it (pending_other), so the step that completes the first does not reopen the shop over the others.
     * @param list<array{reason: string}> $failures
     */
    public static function settle(string $root, array $failures): void
    {
        if ($failures === []) {
            self::leave($root);
            return;
        }
        $first = array_shift($failures);
        self::fail($root, $first['reason'], self::about($first, $failures));
    }

    /**
     * R25: the marker fields of a failed step $failure (restore_scope or rollback_snapshot) carrying $pending under it.
     * @param list<array{reason: string}> $pending
     */
    public static function about(array $failure, array $pending): array
    {
        unset($failure['reason']);
        return $failure + ($pending !== [] ? ['pending_other' => $pending] : []);
    }

    /**
     * R24: take the step lock for this process (kept until leave(), fail(), restore() or unclaim(); the kernel drops it
     * when the process ends). False while another process runs a step: an update, rollback, migration or restore.
     */
    public static function claim(string $root): bool
    {
        $path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        if (isset(self::$claimed[$path])) return true;
        $handle = @fopen($path, 'c');
        if (!is_resource($handle)) throw new RuntimeException('Cannot open the maintenance step lock ' . $path);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        self::$claimed[$path] = $handle;
        return true;
    }

    /** R24: let go of the step lock (the step ended without leaving or failing its window: another step's, or none). */
    public static function unclaim(string $root): void
    {
        $path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        if (!isset(self::$claimed[$path])) return;
        flock(self::$claimed[$path], LOCK_UN);
        fclose(self::$claimed[$path]);
        unset(self::$claimed[$path]);
    }

    public static function enter(string $root, string $reason): void
    {
        if (!self::claim($root)) {
            throw new RuntimeException('Another maintenance step is running (' . (string)(self::marker($root)['reason'] ?? 'it has not opened its window yet')
                . '); wait until that step ends, then retry');
        }
        $path = self::path($root);
        self::replace($path, self::own(['since' => gmdate('c'), 'reason' => $reason]));
        self::hold($path);
    }

    /**
     * R23: call right before the first change of files or schema. From then on the window must not expire: a process
     * killed half-way (SSH dropped, OOM killer, a timeout) runs no finally, and its plain marker opened the shop after
     * STALE_AFTER on a mix of old and new files over a half-migrated database. The marker now says failed with what to
     * run ($reason) while this process still holds its lock; on its way out the step lifts or replaces it as before.
     */
    public static function arm(string $root, string $reason, array $about = []): void
    {
        $path = self::path($root);
        self::replace($path, self::own(['since' => gmdate('c'), 'reason' => $reason, 'failed' => true] + $about));
        self::hold($path);
    }

    /**
     * Keep the shop closed after a step that failed half-way (a migration that stopped part-way, a rollback that
     * failed): the marker no longer expires, and the reason says what to run. R23: written before the lock is let go,
     * so no reader sees an unlocked marker that is older than STALE_AFTER in between.
     */
    public static function fail(string $root, string $reason, array $about = []): void
    {
        self::replace(self::path($root), ['since' => gmdate('c'), 'reason' => $reason, 'failed' => true, 'host' => (string)gethostname()] + $about);
        self::unclaim($root);
    }

    /**
     * Put back a window found before a step that then changed nothing (content and age): a failed one stays failed,
     * the fresh marker of an updater killed half-way keeps blocking exactly as long as it did.
     */
    public static function restore(string $root, array $marker, int $modifiedAt): void
    {
        $path = self::path($root);
        self::replace($path, $marker);
        @touch($path, $modifiedAt);
        clearstatcache(true, $path);
        self::unclaim($root);
    }

    /** R23: whether the marker is the one this process wrote (another may have taken the window over since). */
    public static function owned(string $root): bool
    {
        $marker = self::marker($root);
        return ($marker['pid'] ?? null) === getmypid() && ($marker['host'] ?? null) === (string)gethostname();
    }

    /** R23: whether another process holds the window open right now (an update or migration that is running). */
    public static function heldElsewhere(string $root): bool
    {
        $path = self::path($root);
        return !isset(self::$held[$path]) && is_file($path) && self::lockHeld($path);
    }

    public static function leave(string $root): void
    {
        $path = self::path($root);
        self::release($path);
        @unlink($path);
        self::unclaim($root);
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

    /** @param array<string,mixed> $marker */
    private static function own(array $marker): array
    {
        return $marker + ['pid' => getmypid(), 'host' => (string)gethostname(), 'start' => self::startTime(getmypid())];
    }

    /**
     * Write the new marker, then let go of the lock on the old one: readers see one of the two, always with a lock
     * holder or a failed flag where there was one. Windows cannot rename over a file that is open, so there the lock
     * goes first.
     */
    private static function replace(string $path, array $marker): void
    {
        if (PHP_OS_FAMILY === 'Windows') self::release($path);
        $old = self::$held[$path] ?? null;
        unset(self::$held[$path]);
        try {
            self::write($path, $marker);
        } finally {
            if (is_resource($old)) {
                flock($old, LOCK_UN);
                fclose($old);
            }
        }
    }

    /** Lock the marker that is now on disk for as long as this process runs the step. */
    private static function hold(string $path): void
    {
        $handle = @fopen($path, 'r');
        if (is_resource($handle) && flock($handle, LOCK_EX | LOCK_NB)) {
            self::$held[$path] = $handle;
        } elseif (is_resource($handle)) {
            fclose($handle);
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
