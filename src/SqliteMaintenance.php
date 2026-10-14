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
    private static ?float $defaultWait = null;

    /** Test hook: how long exclusive() waits for other connections to close (null = default). */
    public static function setDefaultWait(?float $seconds): void
    {
        self::$defaultWait = $seconds;
    }

    public static function markerPath(string $dbPath): string
    {
        return $dbPath . '.maintenance';
    }

    public static function isActive(string $dbPath): bool
    {
        return is_file(self::markerPath($dbPath));
    }

    public static function assertOpen(string $dbPath): void
    {
        if (self::isActive($dbPath)) {
            throw new DatabaseMaintenanceException('Database maintenance in progress (restore/rollback); retry later');
        }
    }

    public static function enter(string $dbPath, string $reason): void
    {
        $handle = @fopen(self::markerPath($dbPath), 'x');
        if ($handle === false) {
            throw new RuntimeException('Database maintenance is already in progress: ' . self::markerPath($dbPath));
        }
        fwrite($handle, (string)json_encode(['reason' => $reason, 'pid' => getmypid(), 'since' => gmdate('c')]));
        fclose($handle);
    }

    public static function leave(string $dbPath): void
    {
        @unlink(self::markerPath($dbPath));
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
