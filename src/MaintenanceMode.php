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
 * - R26: the step lock is opened without following a link and no step changes a file it did not create (openLock()).
 */
final class MaintenanceMode
{
    public const FILE = '.maintenance';
    public const LOCK_FILE = '.maintenance.lock';
    /** R26: the claim file that stands for the step lock where the file system has no file locks (claimWithoutFlock()). */
    public const HELD_SUFFIX = '.held';
    public const STALE_AFTER = 3600;
    public const MAX_AGE = 86400;

    /** @var array<string,resource> marker path => the open handle this process holds the lock on */
    private static array $held = [];
    /** @var array<string,resource|string> step lock path => the open handle of the step this process runs (claim()); a string: the claim file where there are no file locks */
    private static array $claimed = [];
    /** @var array<string,true> step lock path => this process wrote its holder record into it (emptied again by unclaim()) */
    private static array $recorded = [];

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
     * R26: the lock is opened without following a symbolic link or a hard link (openLock()), and this process changes the
     * owner or mode of no file it did not create: a lock it cannot share with the app root's owner is replaced (refit()).
     */
    public static function claim(string $root): bool
    {
        $path = self::lockPath($root);
        if (isset(self::$claimed[$path])) return true;
        for ($attempt = 0; ; $attempt++) {
            [$handle, $fits] = self::openLock($root, $path);
            $wouldBlock = 0;
            if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                fclose($handle);
                // R25: a file system without file locks (NFS without lockd, CIFS with nobrl) refuses every lock, not one held.
                // R26: steps there exclude each other by an exclusively created claim file instead (R25 let them all run).
                if ($wouldBlock !== 1 && PHP_OS_FAMILY !== 'Windows') return self::claimWithoutFlock($path);
                return false;
            }
            // R26: a step that replaced a lock it could not share (refit()) renamed a new one over the name meanwhile: a lock
            // on the old file excludes nobody, so take the one the name holds now.
            if (self::sameFile($path, $handle)) break;
            flock($handle, LOCK_UN);
            fclose($handle);
            if ($attempt >= 2) return false;
        }
        if (!$fits) {
            $refitted = self::refit($root, $path, $handle);
            $fits = $refitted !== $handle;
            $handle = $refitted;
        }
        // R25: who holds it, for the step refused meanwhile (a refused step said "another window is open ()"). R26: only into
        // a lock that fits (a kept foreign one is not written to).
        if ($fits && self::writable($handle)) {
            ftruncate($handle, 0);
            fwrite($handle, self::holderRecord());
            fflush($handle);
            self::$recorded[$path] = true;
        }
        self::$claimed[$path] = $handle;
        return true;
    }

    /** R25: what keeps a step from starting, for its message: the open window's reason, else who holds the step lock. */
    public static function busy(string $root): string
    {
        $reason = (string)(self::marker($root)['reason'] ?? '');
        return $reason !== '' ? $reason : 'step lock held by ' . self::holder($root);
    }

    /**
     * R25: who holds the step lock now (the step that claimed it last), for a refused step's message. R26: any account of
     * the app's group can write the record, so it is shown as printable text only.
     */
    public static function holder(string $root): string
    {
        $path = self::lockPath($root);
        $claim = json_decode((string)@file_get_contents($path), true);
        if (!is_array($claim) || !isset($claim['pid'])) $claim = json_decode((string)@file_get_contents($path . self::HELD_SUFFIX), true);
        if (!is_array($claim) || !isset($claim['pid'])) return 'another process holds ' . $path;
        $text = static fn(mixed $value): string => mb_substr((string)preg_replace('/\p{C}+/u', '?', is_scalar($value) ? (string)$value : '?'), 0, 200);
        return 'pid ' . (int)$claim['pid'] . ' on ' . $text($claim['host'] ?? '?') . ' since ' . $text($claim['since'] ?? '?')
            . (($claim['command'] ?? '') !== '' ? ': ' . $text($claim['command']) : '');
    }

    /** R24: let go of the step lock (the step ended without leaving or failing its window: another step's, or none). */
    public static function unclaim(string $root): void
    {
        $path = self::lockPath($root);
        if (!isset(self::$claimed[$path])) return;
        $claimed = self::$claimed[$path];
        unset(self::$claimed[$path]);
        if (is_string($claimed)) {
            // R26: the claim file of a file system without locks: removed only while it is still this process's own.
            $held = json_decode((string)@file_get_contents($claimed), true);
            if (is_array($held) && ($held['pid'] ?? null) === getmypid() && ($held['host'] ?? null) === (string)gethostname()) @unlink($claimed);
            return;
        }
        if (isset(self::$recorded[$path])) ftruncate($claimed, 0);
        unset(self::$recorded[$path]);
        flock($claimed, LOCK_UN);
        fclose($claimed);
    }

    private static function lockPath(string $root): string
    {
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . self::LOCK_FILE;
    }

    private static function holderRecord(): string
    {
        return json_encode(['pid' => getmypid(), 'host' => (string)gethostname(), 'since' => gmdate('c'), 'start' => self::startTime(getmypid()),
            'command' => implode(' ', array_slice($_SERVER['argv'] ?? [], 0, 3))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /**
     * R26: open the step lock without following a link to another file. Run as root, R25 opened it with fopen('c'), which
     * follows a symbolic link, then handed the file to the app root's owner (0660) and emptied it: the web user, who can
     * write the app root, linked .maintenance.lock to /etc/passwd or /etc/shadow and the next restore, update or migration
     * gave it that file. Now
     * - a new lock is created exclusively (O_EXCL: never through a link), by root as the app root's owner and group
     *   (seteuid, so no chown and no /proc is needed: R25 died on every root step without /proc and protected hard links);
     * - an existing one is used only when the name is a regular file with one link (not a symbolic or hard link to another
     *   file) and the file opened is that same file (dev and inode), else the step is refused;
     * - its owner and mode are never changed: one the app root's owner or group cannot use (0644 from before R25, a
     *   deploy account's 0660) is replaced by a new one once its lock is held (refit()).
     * @return array{0: resource, 1: bool} the open lock and whether it fits (the app root's owner or group can use it, no
     *                                     other account can)
     */
    private static function openLock(string $root, string $path): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $handle = @fopen($path, 'c');
            if (!is_resource($handle)) throw new RuntimeException('Cannot open the maintenance step lock ' . $path);
            return [$handle, true];
        }
        $app = @stat($root);
        $app = is_array($app) ? $app : null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $handle = self::create($path, $app);
            if (is_resource($handle)) return [$handle, true];
            $link = @lstat($path);
            if (!is_array($link)) {
                if ($attempt < 2) continue; // removed meanwhile: create it
                throw new RuntimeException('Cannot create the maintenance step lock ' . $path . ': the app root must be writable by the owner of the app root'
                    . ' and the user that runs the step');
            }
            self::refuseLink($path, $link);
            $handle = @fopen($path, 'r+');
            if (!is_resource($handle)) $handle = @fopen($path, 'r'); // a lock that cannot be written can still be locked, then replaced
            if (!is_resource($handle)) {
                throw new RuntimeException(sprintf('Cannot open the maintenance step lock %s (owner %d, group %d, mode %04o): it must be usable by'
                    . ' the user that runs the shop; run the step as the owner of the app root (or root), or remove the file when no step runs',
                    $path, $link['uid'], $link['gid'], $link['mode'] & 07777));
            }
            $open = @fstat($handle);
            if (is_array($open) && $open['dev'] === $link['dev'] && $open['ino'] === $link['ino']) return [$handle, self::fits($open, $app)];
            fclose($handle); // the name was swapped between the check and the open: look again
        }
        throw new RuntimeException('Cannot open the maintenance step lock ' . $path . ': it keeps changing while it is opened');
    }

    /**
     * R26: create a lock file exclusively (null when the name exists), usable by the app root's owner and group only. Root
     * creates it as that owner; another account gives it the app root's group where it can, else it is not created: a lock
     * a deploy account made 0660 for itself shut the web user out of every step.
     * @return resource|null
     */
    private static function create(string $path, ?array $app)
    {
        $old = umask(0007);
        try {
            $handle = self::asAppOwner($app, static fn() => @fopen($path, 'x+'));
        } finally {
            umask($old);
        }
        if (!is_resource($handle)) return null;
        $open = @fstat($handle);
        if (is_array($open) && !self::fits($open, $app) && ($fd = self::descriptor($handle)) !== null) {
            @chgrp($fd, $app['gid']); // our own new file, through its descriptor; allowed when this account is in that group
            clearstatcache();
            $open = @fstat($handle);
        }
        if (is_array($open) && !self::fits($open, $app)) {
            fclose($handle);
            @unlink($path);
            throw new RuntimeException(sprintf('Cannot create the maintenance step lock %s so that the owner (%d) or group (%d) of the app root'
                . ' can use it: run update, rollback, migrate and restore as the owner of the app root, a member of its group, or root',
                $path, $app['uid'] ?? -1, $app['gid'] ?? -1));
        }
        return $handle;
    }

    /**
     * R26: run $open as the owner and group of the app root when this process is root (effective IDs only, put back
     * right after), so the file it creates belongs to them without a chown.
     */
    private static function asAppOwner(?array $app, callable $open): mixed
    {
        if ($app === null || !function_exists('posix_geteuid') || posix_geteuid() !== 0 || ($app['uid'] === 0 && $app['gid'] === 0)) return $open();
        $gid = posix_getegid();
        if (!@posix_setegid($app['gid'])) throw new RuntimeException('Cannot switch to the group of the app root (' . $app['gid'] . ') to create the maintenance step lock');
        if (!@posix_seteuid($app['uid'])) {
            posix_setegid($gid);
            throw new RuntimeException('Cannot switch to the owner of the app root (' . $app['uid'] . ') to create the maintenance step lock');
        }
        try {
            return $open();
        } finally {
            posix_seteuid(0);
            posix_setegid($gid);
        }
    }

    /** R26: refuse a lock name that is a symbolic link, not a regular file, or a hard link to another file. */
    private static function refuseLink(string $path, array $link): void
    {
        $type = $link['mode'] & 0170000;
        $what = $type === 0120000 ? 'a symbolic link' : ($type !== 0100000 ? 'not a regular file' : ($link['nlink'] !== 1 ? 'a hard link (' . $link['nlink'] . ' names)' : null));
        if ($what === null) return;
        throw new RuntimeException("REFUSED: the maintenance step lock {$path} is {$what}; it could stand for any file, so no step opens it."
            . " Check who made it, then remove it (rm {$path}) and run the step again");
    }

    /** R26: whether the app root's owner or group can use a lock with this stat and no other account can (unknown root: yes). */
    private static function fits(array $stat, ?array $app): bool
    {
        if ($app === null) return true;
        $mode = $stat['mode'] & 0777;
        if (($mode & 0007) !== 0) return false;
        return ($stat['uid'] === $app['uid'] && ($mode & 0600) === 0600) || ($stat['gid'] === $app['gid'] && ($mode & 0060) === 0060);
    }

    /**
     * R26: put a new, fitting lock in place of one that is held now but does not fit (0644 from before R25: any account
     * could lock it; a deploy account's: the web user could not open it). Its lock is taken before the rename, and a step
     * that locked the old file finds the name moved on (sameFile()). Where it cannot be replaced the old one is kept.
     * @param resource $handle the old lock, held
     * @return resource the lock now held
     */
    private static function refit(string $root, string $path, $handle)
    {
        $app = @stat($root);
        $temporary = $path . '.' . bin2hex(random_bytes(6));
        try {
            $new = self::create($temporary, is_array($app) ? $app : null);
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'WARNING: ' . $e->getMessage() . "; {$path} is kept as it is\n");
            return $handle;
        }
        if (!is_resource($new) || !flock($new, LOCK_EX | LOCK_NB) || !@rename($temporary, $path)) {
            if (is_resource($new)) fclose($new);
            @unlink($temporary);
            return $handle;
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        return $new;
    }

    /** @param resource $handle */
    private static function sameFile(string $path, $handle): bool
    {
        if (PHP_OS_FAMILY === 'Windows') return true;
        clearstatcache(true, $path);
        $link = @lstat($path);
        $open = @fstat($handle);
        return is_array($link) && is_array($open) && ($link['mode'] & 0170000) === 0100000 && $link['dev'] === $open['dev'] && $link['ino'] === $open['ino'];
    }

    /** @param resource $handle */
    private static function writable($handle): bool
    {
        $mode = stream_get_meta_data($handle)['mode'];
        return str_contains($mode, '+') || str_contains($mode, 'w') || str_contains($mode, 'a') || str_contains($mode, 'x') || str_contains($mode, 'c');
    }

    /** @param resource $handle /proc/self/fd/N of an open file, or null without /proc. */
    private static function descriptor($handle): ?string
    {
        $open = @fstat($handle);
        $descriptors = is_array($open) ? @scandir('/proc/self/fd') : false;
        if (!is_array($descriptors)) return null;
        foreach ($descriptors as $fd) {
            if (!ctype_digit($fd)) continue;
            $stat = @stat('/proc/self/fd/' . $fd);
            if (is_array($stat) && $stat['ino'] === $open['ino'] && $stat['dev'] === $open['dev']) return '/proc/self/fd/' . $fd;
        }
        return null;
    }

    /**
     * R26: the step lock of a file system without file locks: a claim file created exclusively (O_EXCL, which NFS and CIFS
     * honour) next to the lock and removed by unclaim(). R25 let every step run there, though its comment said they still
     * excluded each other. One left by a step that died is taken over when it named a process of this host that no longer
     * runs, or is older than MAX_AGE.
     */
    private static function claimWithoutFlock(string $path): bool
    {
        $claim = $path . self::HELD_SUFFIX;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $old = umask(0007);
            try {
                $handle = @fopen($claim, 'x');
            } finally {
                umask($old);
            }
            if (is_resource($handle)) {
                fwrite($handle, self::holderRecord());
                fclose($handle);
                fwrite(STDERR, "WARNING: {$path} cannot be locked on this file system (flock unsupported); this step holds {$claim} instead"
                    . " — if a step is killed on another host, remove that file once no step runs\n");
                self::$claimed[$path] = $claim;
                return true;
            }
            $held = json_decode((string)@file_get_contents($claim), true);
            $since = @filemtime($claim);
            $abandoned = is_int($since) && $since <= time() - self::MAX_AGE;
            if (!$abandoned && is_array($held) && ($held['host'] ?? null) === (string)gethostname()) {
                $pid = (int)($held['pid'] ?? 0);
                $start = $held['start'] ?? null;
                $abandoned = $pid > 0 && (is_string($start) && $start !== '' && self::startTime($pid) !== null
                    ? self::startTime($pid) !== $start
                    : function_exists('posix_kill') && !@posix_kill($pid, 0) && posix_get_last_error() === 3); // ESRCH
            }
            if (!$abandoned) return false;
            @unlink($claim);
        }
        return false;
    }

    public static function enter(string $root, string $reason): void
    {
        if (!self::claim($root)) {
            throw new RuntimeException('Another maintenance step is running (' . self::busy($root) . '); wait until that step ends, then retry');
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
