<?php

final class MysqlUpdateDatabaseBackup implements UpdateDatabaseBackup
{
    private string $host;
    private int $port;
    private string $database;
    private string $user;
    private string $password;
    private string $charset;
    private string $probeDatabase;
    private string $dumpBinary;
    private string $clientBinary;

    public function __construct(
        string $host,
        int $port,
        string $database,
        string $user,
        string $password,
        string $charset,
        string $probeDatabase,
        string $dumpBinary = 'mysqldump',
        string $clientBinary = 'mysql'
    ) {
        foreach ([$database, $probeDatabase] as $name) {
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
                throw new InvalidArgumentException('MySQL update database names may contain only letters, digits, and underscores');
            }
        }
        if ($database === $probeDatabase || !str_ends_with(strtolower($probeDatabase), '_update_probe')) {
            throw new InvalidArgumentException('MySQL update probe database must be different from the live database and end with _update_probe');
        }
        if ($host === '' || $user === '' || $port < 1 || $port > 65535 || str_contains($host, ';')
            || str_contains($password, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $host . $user . $dumpBinary . $clientBinary)) {
            throw new InvalidArgumentException('Invalid MySQL update backup connection or binary configuration');
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $charset)) {
            throw new InvalidArgumentException('Invalid MySQL character set');
        }
        if ($dumpBinary === '' || $clientBinary === '') {
            throw new InvalidArgumentException('MySQL dump and client binaries must be configured');
        }

        $this->host = $host;
        $this->port = $port;
        $this->database = $database;
        $this->user = $user;
        $this->password = $password;
        $this->charset = $charset;
        $this->probeDatabase = $probeDatabase;
        $this->dumpBinary = $dumpBinary;
        $this->clientBinary = $clientBinary;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function probe(string $workingDirectory): array
    {
        return $this->create($workingDirectory);
    }

    public function create(string $snapshotDirectory): array
    {
        if (!is_dir($snapshotDirectory) || is_link($snapshotDirectory)) {
            throw new RuntimeException('MySQL update snapshot directory is unavailable');
        }

        $server = $this->serverConnection();
        $this->assertSchemaExists($server, $this->database);
        $schema = $this->schemaSettings($server, $this->database);
        $this->assertTransactionalTables($server, $this->database);

        $dumpPath = $snapshotDirectory . DIRECTORY_SEPARATOR . 'database.sql';
        if (file_exists($dumpPath)) {
            throw new RuntimeException('MySQL update dump path already exists');
        }
        // The dump is a --single-transaction snapshot of one instant while the live data keeps
        // moving (worker heartbeats, orders): live row counts or CHECKSUM TABLE read afterwards
        // describe a different instant. The data expectation is therefore derived from the dump
        // itself (rows per table); only the schema, which writers do not change, is read from the
        // live database — before and after the dump, which proves no DDL ran in between.
        $schemaBefore = $this->schemaInventory($server, $this->database);
        $this->dump($dumpPath);
        if (!is_file($dumpPath) || filesize($dumpPath) === 0) {
            throw new RuntimeException('MySQL update dump was not created');
        }
        @chmod($dumpPath, 0600);

        $expected = $this->schemaInventory($server, $this->database);
        if (!hash_equals(hash('sha256', json_encode($schemaBefore, JSON_THROW_ON_ERROR)), hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('MySQL schema changed while the update dump was taken; retry when no migration or DDL is running');
        }
        $expected['row_counts'] = $this->expectedRowCounts($expected, self::countDumpRows($dumpPath));
        // The probe's content checksums describe the very bytes snapshotted; a rollback must reproduce them.
        $inventory = $this->verifyDisposableRestore($dumpPath, $schema, $expected);
        $hash = hash_file('sha256', $dumpPath);
        if (!is_string($hash)) {
            throw new RuntimeException('Cannot checksum MySQL update dump');
        }

        return [
            'driver' => 'mysql',
            'database' => $this->database,
            'sha256' => $hash,
            'size_bytes' => filesize($dumpPath),
            'character_set' => $schema['character_set'],
            'collation' => $schema['collation'],
            'inventory' => $inventory,
            'verified_restore' => true,
            'verified_at' => gmdate('c'),
        ];
    }

    /**
     * Consistent single-transaction dump of the live database to $dumpPath (scheduled backups).
     * Unlike create() it does not need the disposable probe schema.
     */
    public function dumpTo(string $dumpPath): array
    {
        if (file_exists($dumpPath)) {
            throw new RuntimeException('MySQL backup dump path already exists');
        }
        $server = $this->serverConnection();
        $this->assertSchemaExists($server, $this->database);
        $schema = $this->schemaSettings($server, $this->database);
        $this->assertTransactionalTables($server, $this->database);
        $this->dump($dumpPath);
        if (!is_file($dumpPath) || filesize($dumpPath) === 0) {
            throw new RuntimeException('MySQL backup dump was not created');
        }
        @chmod($dumpPath, 0600);
        return ['database' => $this->database] + $schema;
    }

    public function restore(string $snapshotDirectory, array $metadata): void
    {
        if (($metadata['driver'] ?? null) !== 'mysql' || ($metadata['database'] ?? null) !== $this->database
            || empty($metadata['verified_restore']) || !is_array($metadata['inventory'] ?? null)) {
            throw new RuntimeException('MySQL rollback metadata is invalid or belongs to another database');
        }
        $dumpPath = $snapshotDirectory . DIRECTORY_SEPARATOR . 'database.sql';
        $expectedHash = (string)($metadata['sha256'] ?? '');
        if (!is_file($dumpPath) || !preg_match('/^[0-9a-f]{64}$/', $expectedHash)
            || !hash_equals($expectedHash, (string)hash_file('sha256', $dumpPath))) {
            throw new RuntimeException('MySQL rollback dump failed integrity verification');
        }
        $schema = [
            'character_set' => (string)($metadata['character_set'] ?? ''),
            'collation' => (string)($metadata['collation'] ?? ''),
        ];
        $this->validateSchemaSettings($schema);

        // Re-prove that the stored dump can be restored before touching the live database.
        $this->verifyDisposableRestore($dumpPath, $schema, $metadata['inventory']);

        $server = $this->serverConnection();
        $this->assertSchemaExists($server, $this->database);
        $server->exec('DROP DATABASE ' . $this->quoteIdentifier($this->database));
        $this->createSchema($server, $this->database, $schema);
        $server = null;

        $this->import($dumpPath, $this->database);
        $server = $this->serverConnection();
        $actual = $this->inventory($server, $this->database);
        $this->assertMatchingInventory($metadata['inventory'], $actual, 'live rollback');
    }

    /**
     * Verified restore of a scheduled backup dump (cli/restore.php). The dump is imported into the
     * disposable $scratchDatabase first, which must hold exactly the rows the dump carries; only
     * then is the live database replaced and proven identical to the scratch copy. MySQL cannot
     * rename a database and RENAME TABLE cannot move triggers, views, routines or events across
     * schemas, so live is replaced by drop + import rather than swapped. If that import or its
     * verification fails, $rollbackDumpPath (a dump of live taken just before) is imported back.
     * Every writer must be stopped by the caller; sessions the server shows on the live database
     * are refused, except the caller's own $ignoredSessionIds (without the PROCESS privilege only
     * this user's own sessions are visible).
     */
    public function restoreBackupDump(string $dumpPath, string $scratchDatabase, string $rollbackDumpPath, ?array $schema = null, array $ignoredSessionIds = []): array
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $scratchDatabase) || $scratchDatabase === $this->database
            || $scratchDatabase === $this->probeDatabase || !str_ends_with(strtolower($scratchDatabase), '_restore_scratch')) {
            throw new InvalidArgumentException('MySQL restore scratch database must be a separate database whose name ends with _restore_scratch');
        }
        foreach ([$dumpPath, $rollbackDumpPath] as $path) {
            if (!is_file($path) || is_link($path) || filesize($path) === 0) {
                throw new RuntimeException('MySQL restore dump is missing or empty');
            }
        }
        $server = $this->serverConnection();
        $this->assertSchemaExists($server, $this->database);
        $schema ??= $this->schemaSettings($server, $this->database);
        $this->validateSchemaSettings($schema);
        $dumpRows = self::countDumpRows($dumpPath);
        if ($this->schemaExists($server, $scratchDatabase)) {
            throw new RuntimeException("Refusing to overwrite existing MySQL scratch database {$scratchDatabase}; drop it once you have checked it holds nothing you need");
        }

        $created = false;
        try {
            // 1. Scratch restore: the dump imports cleanly and every table holds exactly its dumped rows.
            $this->createSchema($server, $scratchDatabase, $schema);
            $created = true;
            $this->import($dumpPath, $scratchDatabase);
            $scratch = $this->inventory($server, $scratchDatabase);
            if ($scratch['row_counts'] !== $this->expectedRowCounts($scratch, $dumpRows)) {
                throw new RuntimeException('MySQL scratch restore row counts do not match the rows in the backup dump');
            }

            // 2. No other session may still use the live database.
            $others = $this->otherSessions($server, $this->database, $ignoredSessionIds);
            if ($others > 0) {
                throw new RuntimeException("Refusing to replace the live MySQL database: {$others} other session(s) are connected to it; stop web traffic, workers and cron first");
            }

            // 3. Replace live and prove it equals the verified scratch copy, else re-import the previous database.
            $server->exec('DROP DATABASE ' . $this->quoteIdentifier($this->database));
            try {
                $this->createSchema($server, $this->database, $schema);
                $this->import($dumpPath, $this->database);
                $live = $this->inventory($server, $this->database);
                $this->assertMatchingInventory($scratch, $live, 'live restore');
            } catch (Throwable $restoreError) {
                try {
                    $server->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($this->database));
                    $this->createSchema($server, $this->database, $schema);
                    $this->import($rollbackDumpPath, $this->database);
                } catch (Throwable $rollbackError) {
                    throw new RuntimeException('MySQL restore failed (' . $restoreError->getMessage() . ') and re-importing the previous database also failed ('
                        . $rollbackError->getMessage() . "); import {$rollbackDumpPath} manually", 0, $restoreError);
                }
                throw new RuntimeException('MySQL restore failed; the previous database was re-imported: ' . $restoreError->getMessage(), 0, $restoreError);
            }

            $migrations = null;
            if (array_key_exists('schema_migrations', $scratch['row_counts'])) {
                $migrations = array_map('strval', $server->query('SELECT name FROM ' . $this->quoteIdentifier($this->database) . '.'
                    . $this->quoteIdentifier('schema_migrations'))->fetchAll(PDO::FETCH_COLUMN));
            }
            return [
                'database' => $this->database,
                'tables' => count($scratch['row_counts']),
                'rows' => array_sum($scratch['row_counts']),
                'row_counts' => $scratch['row_counts'],
                'migrations' => $migrations,
            ];
        } finally {
            if ($created) {
                $server->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($scratchDatabase));
            }
        }
    }

    /**
     * Rows per table in a mysqldump/mariadb-dump file, counted from its INSERT statements (one value
     * tuple is one row). Strings are skipped with the dump's quoting and backslash escapes, and
     * statements inside DELIMITER blocks (routine and trigger bodies) are not data.
     */
    public static function countDumpRows(string $dumpPath): array
    {
        $handle = @fopen($dumpPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read MySQL dump');
        }
        $counts = [];
        $delimiter = ';';
        $table = null;
        $depth = 0;
        $quote = null;
        $escaped = false;
        try {
            while (($line = fgets($handle)) !== false) {
                $offset = 0;
                if ($table === null) {
                    if (preg_match('/^DELIMITER\s+(\S+)/i', $line, $match) === 1) {
                        $delimiter = $match[1];
                        continue;
                    }
                    if ($delimiter !== ';' || preg_match('/^INSERT INTO `((?:[^`]|``)+)`(?: \([^)]*\))? VALUES ?/', $line, $match) !== 1) {
                        continue;
                    }
                    $table = str_replace('``', '`', $match[1]);
                    $counts[$table] ??= 0;
                    $offset = strlen($match[0]);
                }
                $length = strlen($line);
                for ($i = $offset; $i < $length; $i++) {
                    $char = $line[$i];
                    if ($quote !== null) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($char === '\\') {
                            $escaped = true;
                        } elseif ($char === $quote) {
                            $quote = null;
                        }
                    } elseif ($char === "'" || $char === '"') {
                        $quote = $char;
                    } elseif ($char === '(') {
                        if ($depth === 0) {
                            $counts[$table]++;
                        }
                        $depth++;
                    } elseif ($char === ')') {
                        $depth--;
                    } elseif ($char === ';' && $depth === 0) {
                        $table = null;
                        break;
                    }
                }
            }
        } finally {
            fclose($handle);
        }
        if ($table !== null || $depth !== 0 || $quote !== null) {
            throw new RuntimeException('MySQL dump ends inside an INSERT statement (truncated dump)');
        }
        ksort($counts);
        return $counts;
    }

    /** Rows per base table the dump must restore: a table without INSERTs is empty; rows for an unknown table are an error. */
    private function expectedRowCounts(array $schemaInventory, array $dumpRows): array
    {
        $expected = [];
        foreach ($schemaInventory['tables'] as $table) {
            if (($table['TABLE_TYPE'] ?? null) === 'BASE TABLE') {
                $name = (string)$table['TABLE_NAME'];
                $expected[$name] = (int)($dumpRows[$name] ?? 0);
                unset($dumpRows[$name]);
            }
        }
        if ($dumpRows !== []) {
            throw new RuntimeException('MySQL dump holds rows for tables missing from the schema: ' . implode(', ', array_keys($dumpRows)));
        }
        ksort($expected);
        return $expected;
    }

    /** Sessions using $database other than this one and the caller's own ($ignoredSessionIds). */
    private function otherSessions(PDO $server, string $database, array $ignoredSessionIds): int
    {
        try {
            $statement = $server->prepare('SELECT ID FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID()');
            $statement->execute([$database]);
            $ignored = array_map('intval', $ignoredSessionIds);
            return count(array_filter(
                $statement->fetchAll(PDO::FETCH_COLUMN),
                static fn($id): bool => !in_array((int)$id, $ignored, true)
            ));
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Imports the dump into the disposable probe database and returns the probe's full inventory;
     * every part $expectedInventory names (schema, row counts, checksums when known) must match.
     */
    private function verifyDisposableRestore(string $dumpPath, array $schema, array $expectedInventory): array
    {
        $server = $this->serverConnection();
        if ($this->schemaExists($server, $this->probeDatabase)) {
            throw new RuntimeException("Refusing to overwrite existing MySQL probe database {$this->probeDatabase}");
        }

        $created = false;
        try {
            $this->createSchema($server, $this->probeDatabase, $schema);
            $created = true;
            $this->import($dumpPath, $this->probeDatabase);
            $actual = $this->inventory($server, $this->probeDatabase);
            $this->assertMatchingInventory($expectedInventory, array_intersect_key($actual, $expectedInventory), 'disposable restore');
            return $actual;
        } finally {
            if ($created) {
                try {
                    $server->exec('DROP DATABASE ' . $this->quoteIdentifier($this->probeDatabase));
                } catch (Throwable $e) {
                    throw new RuntimeException("Cannot remove MySQL update probe database {$this->probeDatabase}: " . $e->getMessage(), 0, $e);
                }
            }
        }
    }

    private function dump(string $dumpPath): void
    {
        $this->withDefaultsFile(function (string $defaultsFile) use ($dumpPath): void {
            $this->run([
                $this->dumpBinary,
                '--defaults-extra-file=' . $defaultsFile,
                '--protocol=TCP',
                '--single-transaction',
                '--quick',
                '--routines',
                '--events',
                '--triggers',
                '--hex-blob',
                '--no-tablespaces',
                '--default-character-set=' . $this->charset,
                '--result-file=' . $dumpPath,
                $this->database,
            ]);
        });
    }

    private function import(string $dumpPath, string $database): void
    {
        $this->withDefaultsFile(function (string $defaultsFile) use ($dumpPath, $database): void {
            $this->run([
                $this->clientBinary,
                '--defaults-extra-file=' . $defaultsFile,
                '--protocol=TCP',
                '--default-character-set=' . $this->charset,
                '--database=' . $database,
            ], $dumpPath);
        });
    }

    private function run(array $command, ?string $stdinPath = null): void
    {
        $descriptors = [
            0 => $stdinPath === null ? ['pipe', 'r'] : ['file', $stdinPath, 'rb'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start MySQL backup command');
        }
        if ($stdinPath === null) {
            fclose($pipes[0]);
        }
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $detail = trim((string)$stderr);
            if ($detail === '') {
                $detail = trim((string)$stdout);
            }
            throw new RuntimeException('MySQL backup command failed with exit code ' . $exitCode
                . ($detail !== '' ? ': ' . substr($detail, 0, 4000) : ''));
        }
    }

    private function withDefaultsFile(callable $callback): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bb-update-mysql-');
        if ($path === false) {
            throw new RuntimeException('Cannot create temporary MySQL client configuration');
        }
        $content = "[client]\n"
            . 'host=' . $this->optionValue($this->host) . "\n"
            . 'port=' . $this->port . "\n"
            . 'user=' . $this->optionValue($this->user) . "\n"
            . 'password=' . $this->optionValue($this->password) . "\n";
        try {
            if (file_put_contents($path, $content, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write temporary MySQL client configuration');
            }
            @chmod($path, 0600);
            $callback($path);
        } finally {
            @unlink($path);
        }
    }

    private function optionValue(string $value): string
    {
        return '"' . str_replace(
            ["\\", "\"", "\n", "\r", "\t"],
            ["\\\\", "\\\"", "\\n", "\\r", "\\t"],
            $value
        ) . '"';
    }

    private function serverConnection(): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;charset=%s', $this->host, $this->port, $this->charset),
            $this->user,
            $this->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    private function schemaSettings(PDO $server, string $database): array
    {
        $statement = $server->prepare(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS character_set, DEFAULT_COLLATION_NAME AS collation '
            . 'FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
        );
        $statement->execute([$database]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException("MySQL database does not exist: {$database}");
        }
        $schema = [
            'character_set' => (string)$row['character_set'],
            'collation' => (string)$row['collation'],
        ];
        $this->validateSchemaSettings($schema);
        return $schema;
    }

    private function validateSchemaSettings(array $schema): void
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', (string)($schema['character_set'] ?? ''))
            || !preg_match('/^[A-Za-z0-9_]{1,64}$/', (string)($schema['collation'] ?? ''))) {
            throw new RuntimeException('MySQL database character set metadata is invalid');
        }
    }

    private function createSchema(PDO $server, string $database, array $schema): void
    {
        $this->validateSchemaSettings($schema);
        $server->exec('CREATE DATABASE ' . $this->quoteIdentifier($database)
            . ' CHARACTER SET ' . $schema['character_set'] . ' COLLATE ' . $schema['collation']);
    }

    private function assertTransactionalTables(PDO $server, string $database): void
    {
        $statement = $server->prepare(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'"
        );
        $statement->execute([$database]);
        $rows = $statement->fetchAll();
        if ($rows) {
            $names = array_map(static fn(array $row): string => (string)$row['TABLE_NAME'] . ' (' . (string)$row['ENGINE'] . ')', $rows);
            throw new RuntimeException('MySQL update snapshots require InnoDB tables for transactional consistency: ' . implode(', ', $names));
        }
    }

    private function schemaInventory(PDO $server, string $database): array
    {
        $queries = [
            'tables' => 'SELECT TABLE_NAME, TABLE_TYPE, COALESCE(ENGINE, \'\') AS ENGINE, TABLE_COLLATION, CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            'columns' => 'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, COLUMN_TYPE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
            'indexes' => 'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            'constraints' => 'SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, CONSTRAINT_NAME',
            'key_columns' => 'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
            'references' => 'SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? ORDER BY TABLE_NAME, CONSTRAINT_NAME',
            'views' => 'SELECT TABLE_NAME, VIEW_DEFINITION, CHECK_OPTION, IS_UPDATABLE, SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            'triggers' => 'SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_ORDER, ACTION_CONDITION, ACTION_STATEMENT, ACTION_ORIENTATION, ACTION_TIMING, SQL_MODE, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME',
            'routines' => 'SELECT ROUTINE_NAME, ROUTINE_TYPE, DTD_IDENTIFIER, ROUTINE_DEFINITION, IS_DETERMINISTIC, SQL_DATA_ACCESS, SECURITY_TYPE, SQL_MODE, DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_NAME, ROUTINE_TYPE',
            'events' => 'SELECT EVENT_NAME, EVENT_TYPE, EVENT_DEFINITION, INTERVAL_VALUE, INTERVAL_FIELD, STATUS, ON_COMPLETION, SQL_MODE, DEFINER FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME',
        ];
        $inventory = [];
        foreach ($queries as $key => $sql) {
            $statement = $server->prepare($sql);
            $statement->execute([$database]);
            $inventory[$key] = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        return $inventory;
    }

    private function inventory(PDO $server, string $database): array
    {
        $inventory = $this->schemaInventory($server, $database);
        $inventory['row_counts'] = [];
        $inventory['table_checksums'] = [];
        foreach ($inventory['tables'] as $table) {
            if (($table['TABLE_TYPE'] ?? null) !== 'BASE TABLE') {
                continue;
            }
            $name = (string)$table['TABLE_NAME'];
            $qualified = $this->quoteIdentifier($database) . '.' . $this->quoteIdentifier($name);
            $count = $server->query('SELECT COUNT(*) FROM ' . $qualified)->fetchColumn();
            $checksumRow = $server->query('CHECKSUM TABLE ' . $qualified . ' EXTENDED')->fetch(PDO::FETCH_ASSOC);
            $checksum = is_array($checksumRow) ? ($checksumRow['Checksum'] ?? $checksumRow['CHECKSUM'] ?? null) : null;
            if ($checksum === null) {
                throw new RuntimeException("MySQL cannot calculate a content checksum for table {$name}");
            }
            $inventory['row_counts'][$name] = (int)$count;
            $inventory['table_checksums'][$name] = (string)$checksum;
        }
        ksort($inventory['row_counts']);
        ksort($inventory['table_checksums']);
        return $inventory;
    }

    private function assertMatchingInventory(array $expected, array $actual, string $context): void
    {
        $expectedJson = json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $actualJson = json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!hash_equals(hash('sha256', $expectedJson), hash('sha256', $actualJson))) {
            throw new RuntimeException("MySQL {$context} inventory does not match the live snapshot source");
        }
    }

    private function schemaExists(PDO $server, string $database): bool
    {
        $statement = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $statement->execute([$database]);
        return (int)$statement->fetchColumn() === 1;
    }

    private function assertSchemaExists(PDO $server, string $database): void
    {
        if (!$this->schemaExists($server, $database)) {
            throw new RuntimeException("MySQL database does not exist: {$database}");
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
