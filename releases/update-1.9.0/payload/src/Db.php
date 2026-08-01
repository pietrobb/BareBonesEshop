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
        self::$driver = strtolower(trim((string)($cfg['db_driver'] ?? '')));
        if (!in_array(self::$driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Unsupported db_driver; expected sqlite or mysql');
        }

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
            $sqlitePath = (string)($cfg['db_sqlite_path'] ?? '');
            $absolute = str_starts_with($sqlitePath, '/')
                || str_starts_with($sqlitePath, '\\\\')
                || preg_match('/^[A-Za-z]:[\\\\\/]/', $sqlitePath) === 1;
            if (!$absolute || preg_match('/[\x00-\x1F\x7F]/', $sqlitePath) || str_contains($sqlitePath, ':memory:')) {
                throw new RuntimeException('db_sqlite_path must be a safe absolute filesystem path');
            }
            $dbDir = dirname($sqlitePath);
            if (!is_dir($dbDir) || !is_writable($dbDir)) {
                throw new RuntimeException("SQLite directory must exist and be writable: {$dbDir}");
            }
            self::$pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
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

    /**
     * INSERT prefix that silently skips duplicate-key rows.
     * SQLite: INSERT OR IGNORE / MySQL: INSERT IGNORE.
     * MySQL caveat: INSERT IGNORE downgrades some non-dup errors to warnings —
     * only use where the insert is a pure "create if missing" on a unique key.
     */
    public static function insertIgnore(): string {
        return self::isSqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    }

    /**
     * Driver-aware upsert clause appended after INSERT ... VALUES (...).
     * SQLite emits ON CONFLICT(<target>) DO UPDATE SET <updatesSqlite>
     * (use excluded.col to reference the rejected row); MySQL emits
     * ON DUPLICATE KEY UPDATE <updatesMysql> (use VALUES(col)).
     * MySQL ignores the conflict target — it matches on ANY unique key,
     * so the table must have exactly one relevant unique constraint.
     */
    public static function upsertClause(string $conflictTarget, string $updatesSqlite, ?string $updatesMysql = null): string {
        return self::isSqlite()
            ? "ON CONFLICT({$conflictTarget}) DO UPDATE SET {$updatesSqlite}"
            : 'ON DUPLICATE KEY UPDATE ' . ($updatesMysql ?? $updatesSqlite);
    }

    /**
     * Driver-aware "<field> + <seconds> seconds" datetime expression.
     * $seconds may be a column name or a numeric SQL expression.
     */
    public static function dateAddSeconds(string $field, string $seconds): string {
        return self::isSqlite()
            ? "datetime({$field}, '+' || {$seconds} || ' seconds')"
            : "DATE_ADD({$field}, INTERVAL {$seconds} SECOND)";
    }
}
