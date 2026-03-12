<?php
/**
 * BareBones Shop — Database abstraction
 * Unified PDO wrapper for SQLite and MySQL/MariaDB.
 */
class Db {
    private static ?PDO $pdo = null;
    private static string $driver = '';

    public static function get(): PDO {
        if (self::$pdo) return self::$pdo;

        $cfg = require __DIR__ . '/../config.php';
        self::$driver = $cfg['db_driver'];

        if (self::$driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['db_host'], $cfg['db_port'], $cfg['db_name'], $cfg['db_charset']);
            self::$pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE'");
        } else {
            self::$pdo = new PDO('sqlite:' . $cfg['db_sqlite_path'], null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
            self::$pdo->exec('PRAGMA cache_size = -64000');
            self::$pdo->exec('PRAGMA temp_store = MEMORY');
            self::$pdo->exec('PRAGMA mmap_size = 268435456');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        }

        return self::$pdo;
    }

    /** Initialize with an existing PDO (for testing) */
    public static function init(PDO $pdo, string $driver = 'sqlite'): void {
        self::$pdo = $pdo;
        self::$driver = $driver;
    }

    /** Reset connection (for testing) */
    public static function reset(): void {
        self::$pdo = null;
        self::$driver = '';
    }

    public static function driver(): string {
        if (!self::$driver && !self::$pdo) self::get();
        return self::$driver;
    }

    public static function isSqlite(): bool { return self::driver() === 'sqlite'; }
    public static function isMysql(): bool  { return self::driver() === 'mysql'; }

    /** Current timestamp expression for the active driver */
    public static function now(): string {
        return self::isSqlite() ? "datetime('now')" : 'NOW()';
    }
}
