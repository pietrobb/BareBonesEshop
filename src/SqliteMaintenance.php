<?php
/**
 * Maintenance gate for operations that replace the live SQLite file (backup restore, updater rollback).
 *
 * Swapping the file under an open connection silently loses that process's later commits on POSIX
 * (it keeps writing into the unlinked inode). exclusive() therefore:
 *   1. raises a marker file next to the database — Db::get() refuses new connections while it exists;
 *   2. proves no other connection holds the database: switching a WAL database to DELETE journal
 *      mode needs exclusive access and fails while any other process has it open (the app always
 *      opens SQLite in WAL mode);
 *   3. runs the swap, and always lowers the gate again. If the database stays busy past the wait,
 *      nothing is touched and the operation is refused.
 */
final class SqliteMaintenance
{
    private const WAIT_SECONDS = 15.0;
    /** Covers the instant between creating the marker and locking it in enter(). */
    private const STALE_GRACE_SECONDS = 5;
    private static ?float $defaultWait = null;
    /** @var array<string, resource> markers this process owns, held open and locked */
    private static array $held = [];

    /** Test hook: how long exclusive() waits for other connections to close (null = default). */
    public static function setDefaultWait(?float $seconds): void
    {
        self::$defaultWait = $seconds;
    }

    public static function markerPath(string $dbPath): string
    {
        return $dbPath . '.maintenance';
    }

    /**
     * The owner holds an OS lock on the marker for the whole operation; the OS drops it when the
     * process dies (fatal error, OOM, kill), where `finally` never runs. An unlocked marker past the
     * grace period is therefore stale and cleared — but only while the live database file exists:
     * a crash between the two renames of a swap leaves no live file, and reopening would create an
     * empty database, so the shop stays closed until the operator restores the .rollback copy.
     */
    public static function isActive(string $dbPath): bool
    {
        $marker = self::markerPath($dbPath);
        if (!is_file($marker)) return false;
        if (isset(self::$held[$marker])) return true;
        $mtime = @filemtime($marker);
        if ($mtime === false || $mtime > time() - self::STALE_GRACE_SECONDS || !is_file($dbPath)) return true;
        $handle = @fopen($marker, 'r');
        if ($handle === false) return is_file($marker);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return true; // the owning process is still running
        }
        // The lock was taken on the inode opened above. Another process may have cleared that stale
        // marker and a new owner created a fresh one at the same path meanwhile: unlinking by path
        // would then delete the live marker. Only clear the path while it is still the locked file.
        $opened = fstat($handle);
        clearstatcache(true, $marker);
        $current = @stat($marker);
        if ($opened === false || $current === false
            || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return $current !== false; // replaced: a new owner's marker (within its grace period)
        }
        @unlink($marker); // POSIX: unlink while holding the lock, so no new marker can be hit
        flock($handle, LOCK_UN);
        fclose($handle);
        // Windows refuses to delete an open file. On POSIX a second unlink by path after releasing
        // the lock could delete a marker a new owner has created meanwhile.
        if (PHP_OS_FAMILY === 'Windows' && is_file($marker)) @unlink($marker);
        if (is_file($marker)) return true;
        if (class_exists('Logger')) Logger::warning('sqlite.maintenance_stale_cleared', ['marker' => basename($marker)]);
        return false;
    }

    public static function assertOpen(string $dbPath): void
    {
        if (self::isActive($dbPath)) {
            throw new DatabaseMaintenanceException('Database maintenance in progress (restore/rollback); retry later');
        }
    }

    public static function enter(string $dbPath, string $reason): void
    {
        $marker = self::markerPath($dbPath);
        $handle = self::isActive($dbPath) ? false : @fopen($marker, 'x');
        if ($handle === false) {
            throw new RuntimeException('Database maintenance is already in progress: ' . $marker);
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            @unlink($marker);
            throw new RuntimeException('Database maintenance marker cannot be locked: ' . $marker);
        }
        fwrite($handle, (string)json_encode(['reason' => $reason, 'pid' => getmypid(), 'since' => gmdate('c')]));
        fflush($handle);
        self::$held[$marker] = $handle; // kept open (and locked) until leave() or process death
    }

    public static function leave(string $dbPath): void
    {
        $marker = self::markerPath($dbPath);
        if (isset(self::$held[$marker])) {
            flock(self::$held[$marker], LOCK_UN);
            fclose(self::$held[$marker]);
            unset(self::$held[$marker]);
        }
        @unlink($marker);
    }

    /** Runs $swap behind the gate once no other connection holds $dbPath; refuses without side effects otherwise. */
    public static function exclusive(string $dbPath, string $reason, callable $swap, ?float $waitSeconds = null): mixed
    {
        self::enter($dbPath, $reason);
        try {
            self::drain($dbPath, $waitSeconds ?? self::$defaultWait ?? self::WAIT_SECONDS);
            return $swap();
        } finally {
            self::leave($dbPath);
        }
    }

    /** True when $pdo is a SQLite connection whose main database is the file $dbPath. */
    public static function sameFile(PDO $pdo, string $dbPath): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return false;
        }
        $file = '';
        foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? '') === 'main') {
                $file = (string)($row['file'] ?? '');
            }
        }
        $a = $file !== '' ? realpath($file) : false;
        $b = realpath($dbPath);
        $normalize = static fn(string $path): string => PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $path)) : $path;
        return $a !== false && $b !== false && $normalize($a) === $normalize($b);
    }

    private static function drain(string $dbPath, float $waitSeconds): void
    {
        if (!is_file($dbPath)) {
            return;
        }
        $deadline = microtime(true) + max(0.0, $waitSeconds);
        do {
            $mode = '';
            try {
                $probe = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $probe->exec('PRAGMA busy_timeout = 0');
                $probe->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
                $mode = strtolower((string)$probe->query('PRAGMA journal_mode = DELETE')->fetchColumn());
            } catch (PDOException) {
                $mode = '';
            } finally {
                $probe = null;
            }
            if ($mode === 'delete') {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Database is still open in another process; stop workers and web traffic, then retry');
    }
}
