<?php
/**
 * BareBones Shop — Database abstraction
 * Unified PDO wrapper for SQLite and MySQL/MariaDB.
 */
class Db {
    private static ?PDO $pdo = null;
    private static string $driver = '';
    private static ?string $configPath = null;
    /** @var WeakMap<PDO, array<string, list<string>>>|null Column lists of existing tables, per connection. */
    private static ?WeakMap $schemaCache = null;

    public static function get(): PDO {
        if (self::$pdo) return self::$pdo;

        $cfg = require self::$configPath ?? __DIR__ . '/../config.php';
        self::$driver = strtolower(trim((string)($cfg['db_driver'] ?? '')));
        if (!in_array(self::$driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Unsupported db_driver; expected sqlite or mysql');
        }

        $pdoClass = !empty($cfg['benchmark_query_count']) ? QueryCountingPDO::class : ShopPDO::class;
        if (self::$driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['db_host'], $cfg['db_port'], $cfg['db_name'], $cfg['db_charset']);
            self::$pdo = new $pdoClass($dsn, $cfg['db_user'], $cfg['db_password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE'");
            self::$pdo->exec("SET SESSION time_zone='+00:00'");
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
            SqliteMaintenance::assertOpen($sqlitePath);
            // Only the CLI (install, seed, migrate) may create the database; a web request would silently serve a new empty one.
            if (PHP_SAPI !== 'cli' && !is_file($sqlitePath)) {
                throw new RuntimeException("SQLite database file does not exist: {$sqlitePath}");
            }
            self::$pdo = new $pdoClass('sqlite:' . $sqlitePath, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $journalMode = strtolower((string)self::$pdo->query('PRAGMA journal_mode')->fetchColumn());
            if ($journalMode !== 'wal') self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
            self::$pdo->exec('PRAGMA cache_size = -64000');
            self::$pdo->exec('PRAGMA temp_store = MEMORY');
            self::$pdo->exec('PRAGMA mmap_size = 268435456');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        }

        return self::$pdo;
    }

    /**
     * Connect with another installation's config.php (a trusted release's cli/update.php run with --installed):
     * the next get() opens that shop's database.
     */
    public static function useConfig(string $configPath): void {
        self::$configPath = $configPath;
        self::$pdo = null;
    }

    /** Initialize with an existing PDO (for testing) */
    public static function init(PDO $pdo, string $driver = 'sqlite'): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $pdo->exec("SET SESSION time_zone='+00:00'");
        self::$pdo = $pdo;
        self::$driver = $driver;
        self::forgetSchema($pdo);
    }

    /** Reset connection (for testing) */
    public static function reset(): void {
        self::$pdo = null;
        self::$driver = '';
        self::$schemaCache = null;
    }

    /** Drops the shared connection if it holds the SQLite file $sqlitePath (restore/rollback replaces that file). */
    public static function releaseSqliteFile(string $sqlitePath): bool {
        if (!self::$pdo || !SqliteMaintenance::sameFile(self::$pdo, $sqlitePath)) return false;
        self::reset();
        return true;
    }

    public static function resetQueryCount(): void {
        if (self::$pdo instanceof QueryCountingPDO) self::$pdo->resetQueryCount();
    }

    public static function queryCount(): ?int {
        return self::$pdo instanceof QueryCountingPDO ? self::$pdo->queryCount() : null;
    }

    public static function driver(): string {
        if (!self::$driver && !self::$pdo) self::get();
        return self::$driver;
    }

    public static function isSqlite(): bool { return self::driver() === 'sqlite'; }
    public static function isMysql(): bool  { return self::driver() === 'mysql'; }

    /**
     * Prepare and execute a statement whose rows stream from the server, so a large export never holds the whole
     * result in PHP memory (SQLite always steps row by row). MySQL native prepares ignore the buffered-query option
     * on prepare() and read the connection's setting at execute(): it is switched off for this execute only and
     * restored at once, so every other query stays buffered. Read the statement to the end (or closeCursor())
     * before running another query on the connection.
     */
    public static function executeUnbuffered(string $sql, array $params = []): PDOStatement {
        $pdo = self::get();
        if (!self::isMysql()) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }
        $attribute = defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
        $buffered = $pdo->getAttribute($attribute);
        $pdo->setAttribute($attribute, false);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } finally {
            $pdo->setAttribute($attribute, $buffered);
        }
    }

    /**
     * Runs "$statement WHERE <key> IN (next batch of keys) AND (<where>)" until no row matches <where>: a retention
     * purge of millions of rows becomes many short statements (each its own commit outside a transaction) instead
     * of one that holds the SQLite write lock for half a minute while checkouts give up after busy_timeout.
     * $statement is "DELETE FROM t" or "UPDATE t SET ..." (its own named parameters in $statementParams); every
     * placeholder appears once per statement (MySQL native prepares). Returns the rows written.
     */
    public static function writeInBatches(string $table, string $statement, string $where, array $params = [],
        array $statementParams = [], string $key = 'id', int $batch = 1000, ?PDO $db = null): int {
        $db ??= self::get();
        $batch = max(1, $batch);
        $total = 0;
        do {
            $select = $db->prepare("SELECT {$key} FROM {$table} WHERE {$where} ORDER BY {$key} LIMIT {$batch}");
            $select->execute($params);
            $keys = $select->fetchAll(PDO::FETCH_COLUMN);
            $select->closeCursor(); // an open read keeps a stale WAL snapshot: the write would fail once another request committed
            if (!$keys) break;
            $keyParams = [];
            foreach (array_values($keys) as $i => $value) $keyParams[":batch_key{$i}"] = $value;
            $write = $db->prepare("{$statement} WHERE {$key} IN (" . implode(', ', array_keys($keyParams)) . ") AND ({$where})");
            $write->execute($statementParams + $params + $keyParams);
            $written = $write->rowCount();
            $total += $written;
        } while (count($keys) === $batch && $written > 0);
        return $total;
    }

    /**
     * Literal text for a `LIKE ... ESCAPE '!'` pattern: user-typed % and _ stay characters on
     * SQLite and MySQL alike ("!" avoids MySQL's backslash string-literal rules).
     */
    public static function likeLiteral(string $value): string {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /** Persisted machine timestamps are UTC; PHP's display timezone is unchanged. */
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

    /**
     * Column names of $table (empty when the table does not exist), always read from the schema.
     * Errors other than "missing table" (connection loss, privileges) propagate.
     *
     * @return list<string>
     */
    public static function tableColumns(string $table, ?PDO $db = null): array {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $table)) throw new InvalidArgumentException('Invalid table name');
        $db ??= self::get();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = array_column($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
        } else {
            $stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
            $stmt->execute([$table]);
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        $columns = array_values(array_map('strval', $columns));
        if ($columns !== []) {
            self::$schemaCache ??= new WeakMap();
            $tables = self::$schemaCache[$db] ?? [];
            $tables[$table] = $columns;
            self::$schemaCache[$db] = $tables;
        }
        return $columns;
    }

    /**
     * Probes for optional code paths, so a schema whose migrations are not applied yet degrades
     * instead of failing. A hit is cached per connection; a miss is probed again next time (a
     * migration may have added it meanwhile). Migrator::apply() and forgetSchema() drop the cache.
     */
    public static function tableExists(string $table, ?PDO $db = null): bool {
        $db ??= self::get();
        if (isset(self::$schemaCache[$db][$table])) return true;
        return self::tableColumns($table, $db) !== [];
    }

    public static function hasColumn(string $table, string $column, ?PDO $db = null): bool {
        $db ??= self::get();
        if (in_array($column, self::$schemaCache[$db][$table] ?? [], true)) return true;
        return in_array($column, self::tableColumns($table, $db), true);
    }

    /**
     * The connection can write: a no-op UPDATE still fails on a read-only SQLite database, a read_only
     * MySQL server and a user without write grants (SELECT 1 succeeds on all of them).
     * SQLite: a database another connection is writing (backup, import, GDPR gc) is busy, not read-only —
     * the probe does not wait for that writer (SQLITE_READONLY is reported before any lock is taken).
     */
    public static function writable(?PDO $db = null): bool {
        $db ??= self::get();
        $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $timeout = $sqlite ? (int)$db->query('PRAGMA busy_timeout')->fetchColumn() : 0;
        if ($sqlite) $db->exec('PRAGMA busy_timeout = 0');
        try {
            return $db->exec('UPDATE orders SET id = id WHERE 1 = 0') !== false;
        } catch (Throwable $e) {
            // SQLITE_BUSY (5) / SQLITE_LOCKED (6): another connection holds the write lock.
            return $sqlite && $e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [5, 6], true);
        } finally {
            if ($sqlite) $db->exec('PRAGMA busy_timeout = ' . $timeout);
        }
    }

    /**
     * Whether a transaction is open on $db, also one opened with exec('BEGIN'): PDO does not track that
     * on SQLite before PHP 8.4 (inTransaction() is false). pdo_mysql's inTransaction() reads the server's
     * in-transaction status flag, so it already sees a raw BEGIN on MySQL and MariaDB (@@in_transaction is
     * MariaDB-only). SQLite refuses BEGIN only while a transaction is open.
     */
    public static function transactionOpen(?PDO $db = null): bool {
        $db ??= self::get();
        if ($db->inTransaction()) return true;
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || PHP_VERSION_ID >= 80400) return false;
        $mode = $db->getAttribute(PDO::ATTR_ERRMODE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        try {
            $began = $db->exec('BEGIN') !== false;
        } finally {
            $db->setAttribute(PDO::ATTR_ERRMODE, $mode);
        }
        if ($began) $db->exec('ROLLBACK');
        return !$began;
    }

    /** Roll back the open transaction, also one PDO does not track (see transactionOpen()). */
    public static function rollBackOpen(?PDO $db = null): bool {
        $db ??= self::get();
        return $db->inTransaction() ? $db->rollBack() : $db->exec('ROLLBACK') !== false;
    }

    /** Forget cached table probes (after DDL), for one connection or all of them. */
    public static function forgetSchema(?PDO $db = null): void {
        if ($db === null) {
            self::$schemaCache = null;
        } elseif (self::$schemaCache !== null) {
            unset(self::$schemaCache[$db]);
        }
    }

    /**
     * Refresh SQLite planner statistics after schema changes or bulk writes (cheap: only re-analyzes
     * what changed). No-op on MySQL/MariaDB; never fails the caller.
     */
    public static function optimize(?PDO $db = null): void {
        $db ??= self::get();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') return;
        try {
            $db->exec('PRAGMA optimize');
        } catch (\Throwable $e) {
            Logger::phpLog('[Db] PRAGMA optimize failed: ' . $e->getMessage());
        }
    }
}
