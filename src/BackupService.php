<?php
/**
 * BackupService — vytvára/obnovuje SQLite zálohy cez VACUUM INTO.
 *
 * Public API:
 *   BackupService::init(PDO $db, ?array $config = null, ?string $rootDir = null, ?BackupStorage $storage = null);
 *   BackupService::create(array $opts = []): array  // returns meta of created backup
 *   BackupService::restore(string $remotePath, array $opts = []): array
 *   BackupService::gc(): array                       // returns ['kept' => N, 'deleted' => [...]]
 *   BackupService::list(): array                     // storage rows, deterministic newest first
 *   BackupService::storage(): BackupStorage
 *
 * Config (loaded from config/backup.json):
 *   enabled, storage, compression, retention { daily, weekly, monthly, minimum_kept },
 *   auto_pre_migration, auto_gc_on_backup, wal_checkpoint, log_file, log_max_bytes, log_max_files
 */
class BackupService
{
    /** @var array */
    private static array $cfg = [];
    private static ?PDO $db = null;
    private static ?BackupStorage $storage = null;
    private static ?string $rootDir = null;
    /** Path to the live SQLite file backing the configured PDO; null if unknown / not local. */
    private static ?string $livePath = null;
    /** MySQL installs: callable(string $dumpPath): array that writes a consistent dump. */
    private static ?Closure $mysqlDumper = null;
    /** Non-database state archived next to every backup: name => file or directory. */
    private static array $fileSources = [];

    public static function init(PDO $db, ?array $config = null, ?string $rootDir = null, ?BackupStorage $storage = null): void
    {
        self::$db = $db;
        self::$rootDir = $rootDir ?: dirname(__DIR__);
        self::$cfg = $config ?: self::loadConfig(self::$rootDir . '/config/backup.json');

        self::$mysqlDumper = null;
        self::$fileSources = [];
        if ($config !== null) {
            $storageRoot = (string)($config['storage_dir'] ?? self::$rootDir . '/storage/backups');
            $storageClass = (string)($config['storage_adapter'] ?? 'LocalBackupStorage');
            self::$fileSources = (array)($config['files'] ?? []);
        } else {
            $appCfg = require self::$rootDir . '/config.php';
            $storageRoot = (string)($appCfg['backup_storage_dir'] ?? dirname(self::$rootDir) . '/bareboneseshop-private/backups');
            $storageClass = (string)($appCfg['backup_storage_adapter'] ?? 'LocalBackupStorage');
            self::$cfg['log_file'] = (string)($appCfg['backup_log_file'] ?? dirname(self::$rootDir) . '/bareboneseshop-private/logs/backup.log');
            if (self::$cfg['include_files'] ?? true) {
                $private = dirname(self::$rootDir) . '/bareboneseshop-private';
                self::$fileSources = [
                    'invoices'  => (string)($appCfg['invoice_storage_dir'] ?? $private . '/invoices'),
                    'uploads'   => (string)($appCfg['uploads_dir'] ?? self::$rootDir . '/uploads'),
                    'gdpr_salt' => (string)($appCfg['gdpr_salt_file'] ?? $private . '/secrets/gdpr_salt'),
                ];
            }
            if (self::driverName($db) === 'mysql') {
                self::$mysqlDumper = static fn(string $dumpPath): array => (new MysqlUpdateDatabaseBackup(
                    (string)($appCfg['db_host'] ?? ''),
                    (int)($appCfg['db_port'] ?? 3306),
                    (string)($appCfg['db_name'] ?? ''),
                    (string)($appCfg['db_user'] ?? ''),
                    (string)($appCfg['db_password'] ?? ''),
                    (string)($appCfg['db_charset'] ?? 'utf8mb4'),
                    // dumpTo() never touches the probe schema; the constructor only validates its name.
                    trim((string)($appCfg['update_mysql_probe_database'] ?? '')) ?: substr((string)($appCfg['db_name'] ?? ''), 0, 51) . '_update_probe',
                    self::mysqlBinary((string)($appCfg['update_mysql_dump_binary'] ?? 'mysqldump'), 'mysqldump', 'mariadb-dump'),
                    self::mysqlBinary((string)($appCfg['update_mysql_client_binary'] ?? 'mysql'), 'mysql', 'mariadb')
                ))->dumpTo($dumpPath);
            }
        }
        $logPath = (string)(self::$cfg['log_file'] ?? 'storage/backup.log');
        if (!self::isAbsolutePath($logPath)) $logPath = self::$rootDir . '/' . $logPath;
        self::assertPrivateAbsolutePath($logPath, 'backup_log_file');
        if ($storage !== null) {
            self::$storage = $storage;
        } else {
            self::assertPrivateAbsolutePath($storageRoot, 'backup_storage_dir');
            if (!class_exists($storageClass)) {
                throw new RuntimeException("Backup storage adapter not found: {$storageClass}");
            }
            $candidate = new $storageClass($storageRoot);
            if (!$candidate instanceof BackupStorage) {
                throw new RuntimeException("Backup storage adapter must implement BackupStorage: {$storageClass}");
            }
            self::$storage = $candidate;
        }
        self::$livePath = self::detectLivePath($db);
    }

    /**
     * The default MySQL tool names fall back to their MariaDB names when only those are on PATH
     * (MariaDB 11 client packages ship mariadb-dump/mariadb without the mysql* compat names).
     * An explicitly configured other binary or path is used as is.
     */
    public static function mysqlBinary(string $configured, string $default, string $mariadbName, ?string $path = null): string
    {
        if ($configured !== $default) return $configured;
        $onPath = static function (string $binary) use ($path): bool {
            foreach (explode(PATH_SEPARATOR, $path ?? (string)getenv('PATH')) as $dir) {
                if ($dir !== '' && is_file($dir . '/' . $binary) && is_executable($dir . '/' . $binary)) return true;
            }
            return false;
        };
        return !$onPath($default) && $onPath($mariadbName) ? $mariadbName : $default;
    }

    public static function reset(): void
    {
        self::$cfg = [];
        self::$db = null;
        self::$storage = null;
        self::$rootDir = null;
        self::$livePath = null;
        self::$mysqlDumper = null;
        self::$fileSources = [];
    }

    /** Override the MySQL dump step (tests, custom dump tooling). */
    public static function useMysqlDumper(?Closure $dumper): void
    {
        self::$mysqlDumper = $dumper;
    }

    private static function driverName(PDO $db): string
    {
        try {
            return strtolower((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function isEnabled(): bool
    {
        return (bool)(self::$cfg['enabled'] ?? false);
    }

    public static function config(): array
    {
        return self::$cfg;
    }

    public static function storage(): BackupStorage
    {
        if (self::$storage === null) {
            throw new RuntimeException('BackupService not initialized');
        }
        return self::$storage;
    }

    public static function livePath(): ?string
    {
        return self::$livePath;
    }

    public static function loadConfig(string $path): array
    {
        $defaults = [
            'enabled'             => true,
            'storage'             => 'local',
            'compression'         => false,
            'retention'           => ['daily' => 7, 'weekly' => 4, 'monthly' => 6, 'minimum_kept' => 3],
            'auto_pre_migration'  => true,
            'auto_gc_on_backup'   => true,
            'wal_checkpoint'      => true,
            'log_file'            => 'storage/backup.log',
            'log_max_bytes'       => 10485760,
            'log_max_files'       => 5,
        ];
        if (!is_file($path)) return $defaults;
        $raw = json_decode((string)file_get_contents($path), true);
        if (!is_array($raw)) return $defaults;
        $merged = array_replace($defaults, $raw);
        if (isset($raw['retention']) && is_array($raw['retention'])) {
            $merged['retention'] = array_replace($defaults['retention'], $raw['retention']);
        }
        return $merged;
    }

    /**
     * Detect the local SQLite filename behind a PDO connection.
     * Returns null for :memory: or non-sqlite drivers.
     */
    public static function detectLivePath(PDO $db): ?string
    {
        try {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver !== 'sqlite') return null;
            $row = $db->query("PRAGMA database_list")->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $file = (string)($row['file'] ?? '');
            if ($file === '' || $file === ':memory:') return null;
            return $file;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ---------- Logging ----------

    public static function log(string $message): void
    {
        $cfg = self::$cfg;
        $configured = (string)($cfg['log_file'] ?? 'storage/backup.log');
        $abs = self::isAbsolutePath($configured) ? $configured : self::$rootDir . '/' . $configured;
        $dir = dirname($abs);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("BackupService: cannot create log directory {$dir}");
        }
        self::rotateLog($abs, (int)($cfg['log_max_bytes'] ?? 10485760), (int)($cfg['log_max_files'] ?? 5));
        $line = '[' . date('c') . '] ' . $message . "\n";
        if (file_put_contents($abs, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("BackupService: cannot write log {$abs}");
        }
    }

    private static function rotateLog(string $abs, int $maxBytes, int $maxFiles): void
    {
        if (!is_file($abs) || $maxBytes <= 0) return;
        if (filesize($abs) < $maxBytes) return;
        for ($i = $maxFiles - 1; $i >= 1; $i--) {
            $src = $abs . '.' . $i;
            $dst = $abs . '.' . ($i + 1);
            if (is_file($src)) @rename($src, $dst);
        }
        @rename($abs, $abs . '.1');
    }

    // ---------- Naming / paths ----------

    public static function buildRemotePath(\DateTimeInterface $when, int $suffix = 0, string $extension = 'db'): string
    {
        $datePart = $when->format('Y/m/d');
        $stamp = $when->format('Ymd-His');
        $name = 'eshop-' . $stamp . ($suffix > 0 ? '-' . $suffix : '') . '.' . $extension;
        return $datePart . '/' . $name;
    }

    /** Reserve a unique remote path for `now` — appends -1, -2, ... if the file already exists. */
    public static function reserveUniquePath(\DateTimeInterface $when, string $extension = 'db'): string
    {
        $rp = self::buildRemotePath($when, 0, $extension);
        $i = 0;
        while (self::storedRow($rp) !== null) {
            $i++;
            $rp = self::buildRemotePath($when, $i, $extension);
            if ($i > 999) {
                throw new RuntimeException('BackupService: cannot reserve unique backup path');
            }
        }
        return $rp;
    }

    private static function storedRow(string $remotePath): ?array
    {
        $prefix = dirname($remotePath);
        foreach (self::$storage->list($prefix === '.' ? '' : $prefix) as $row) {
            if ((string)($row['path'] ?? '') === $remotePath) return $row;
        }
        return null;
    }

    private static function stagingPath(string $suffix): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbe-backup-' . bin2hex(random_bytes(16));
            if (@mkdir($dir, 0700)) return $dir . DIRECTORY_SEPARATOR . 'snapshot' . $suffix;
        }
        throw new RuntimeException('BackupService: cannot create a private staging directory');
    }

    private static function removeStagedFile(?string $path): void
    {
        if ($path === null) return;
        if (is_file($path)) @unlink($path);
        @rmdir(dirname($path));
    }

    // ---------- Schema info ----------

    public static function currentSchemaVersion(PDO $db): ?string
    {
        try {
            $stmt = $db->query("SELECT name FROM schema_migrations ORDER BY name DESC LIMIT 1");
            if ($stmt === false) return null;
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (string)$row['name'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ---------- create() ----------

    /**
     * Create a backup of the live SQLite DB.
     *
     * Options:
     *   label    => string  (e.g. 'manual', 'cron', 'pre-migration')  — default 'manual'
     *   trigger  => string  ('manual'|'auto'|'migration')             — default = label
     *   compress => bool    — default = config.compression
     *
     * Returns meta array on success.
     */
    public static function create(array $opts = []): array
    {
        if (self::$db === null) {
            throw new RuntimeException('BackupService::create — not initialized');
        }
        $driver = self::driverName(self::$db);
        if ($driver === 'mysql') {
            if (self::$mysqlDumper === null) {
                throw new RuntimeException('BackupService::create — MySQL dump is not configured');
            }
        } elseif (self::$livePath === null) {
            throw new RuntimeException('BackupService::create — live SQLite path unknown (in-memory or unsupported driver)');
        }

        $label    = (string)($opts['label']   ?? 'manual');
        $trigger  = (string)($opts['trigger'] ?? $label);
        $compress = (bool)($opts['compress']  ?? (self::$cfg['compression'] ?? false));
        $extension = $driver === 'mysql' ? 'sql' : 'db';

        $stagedDb = null;
        $stagedGz = null;
        $stagedFiles = null;
        $lockHandle = self::acquireLock();
        try {
            // 1. WAL checkpoint (best-effort)
            if ($driver !== 'mysql' && !empty(self::$cfg['wal_checkpoint'])) {
                try { self::$db->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (\Throwable $e) { /* non-fatal */ }
            }

            // 2. Reserve a unique remote path
            $when = new DateTimeImmutable('now');
            $remotePath = self::reserveUniquePath($when, $extension);
            $absPath = $stagedDb = self::stagingPath('.' . $extension);

            // 3. Consistent snapshot: VACUUM INTO (SQLite) or single-transaction dump (MySQL)
            try {
                if ($driver === 'mysql') {
                    (self::$mysqlDumper)($absPath);
                } else {
                    $sqlPath = str_replace("'", "''", $absPath);
                    self::$db->exec("VACUUM INTO '{$sqlPath}'");
                }
            } catch (\Throwable $e) {
                @unlink($absPath);
                self::log("CREATE FAIL label={$label} err=" . $e->getMessage());
                throw new RuntimeException(($driver === 'mysql' ? 'MySQL dump' : 'VACUUM INTO') . ' failed: ' . $e->getMessage(), 0, $e);
            }
            if (!is_file($absPath)) {
                throw new RuntimeException("Backup file not created: {$absPath}");
            }

            // 4. sha256 + size
            $size = filesize($absPath);
            $sha  = hash_file('sha256', $absPath);

            // 5. Optional gzip
            $compressed = false;
            if ($compress) {
                $gzPath = $stagedGz = $absPath . '.gz';
                $in  = @fopen($absPath, 'rb');
                $out = @gzopen($gzPath, 'wb6');
                $compressionOk = $in !== false && $out !== false;
                if ($compressionOk) {
                    while (!feof($in)) {
                        $buf = fread($in, 65536);
                        if ($buf === false || ($buf !== '' && gzwrite($out, $buf) !== strlen($buf))) {
                            $compressionOk = false;
                            break;
                        }
                    }
                }
                if (is_resource($in)) fclose($in);
                if (is_resource($out)) gzclose($out);
                if (!$compressionOk || !is_file($gzPath) || filesize($gzPath) === 0) {
                    @unlink($gzPath);
                } else {
                    $compressed = true;
                }
            }

            // 6. Schema version + invoices/uploads/GDPR salt, which a database restore alone cannot bring back
            $schemaVersion = self::currentSchemaVersion(self::$db);
            $files = null;
            if (self::$fileSources !== []) {
                $stagedFiles = self::stagingPath('.files.tar');
                $files = self::archiveFiles($stagedFiles);
                $files['remote_path'] = $remotePath . '.files.tar';
            }

            // 7. Meta JSON written by storage adapter
            $meta = [
                'filename'       => basename($remotePath),
                'remote_path'    => $remotePath,
                'timestamp'      => $when->format('c'),
                'created_us'     => $when->format('Uu'),
                'size_bytes'     => $size,
                'sha256'         => $sha,
                'schema_version' => $schemaVersion,
                'label'          => $label,
                'trigger'        => $trigger,
                'compressed'     => $compressed,
                'created_by'     => 'BackupService',
                'driver'         => $driver === 'mysql' ? 'mysql' : 'sqlite',
                'files_archive'  => $files['remote_path'] ?? null,
                'files'          => $files['sources'] ?? null,
                'files_skipped'  => $files['skipped'] ?? null,
            ];
            try {
                if ($files !== null) {
                    self::$storage->put($stagedFiles, $files['remote_path'], [
                        'filename'    => basename($files['remote_path']),
                        'remote_path' => $files['remote_path'],
                        'kind'        => 'files',
                        'backup'      => $remotePath,
                        'timestamp'   => $meta['timestamp'],
                        'size_bytes'  => $files['size_bytes'],
                        'sha256'      => $files['sha256'],
                        'entries'     => $files['entries'],
                        'sources'     => $files['sources'],
                        'skipped'     => $files['skipped'],
                    ]);
                }
                self::$storage->put($absPath, $remotePath, $meta);
                if ($compressed && $stagedGz !== null) {
                    $compressedMeta = $meta;
                    $compressedMeta['filename'] = basename($remotePath . '.gz');
                    $compressedMeta['remote_path'] = $remotePath . '.gz';
                    $compressedMeta['size_bytes'] = filesize($stagedGz);
                    $compressedMeta['sha256'] = hash_file('sha256', $stagedGz);
                    $compressedMeta['content_encoding'] = 'gzip';
                    $compressedMeta['source_sha256'] = $sha;
                    self::$storage->put($stagedGz, $remotePath . '.gz', $compressedMeta);
                }
            } catch (\Throwable $e) {
                try { self::$storage->delete($remotePath); } catch (\Throwable $ignored) { }
                if ($files !== null) {
                    try { self::$storage->delete($files['remote_path']); } catch (\Throwable $ignored) { }
                }
                if ($compressed) {
                    try { self::$storage->delete($remotePath . '.gz'); } catch (\Throwable $ignored) { }
                }
                self::log("CREATE FAIL label={$label} err=" . $e->getMessage());
                throw new RuntimeException('Backup upload failed: ' . $e->getMessage(), 0, $e);
            }

            self::log("CREATE OK path={$remotePath} size={$size} sha256={$sha} label={$label}");

            // 8. Optional GC
            if (!empty(self::$cfg['auto_gc_on_backup'])) {
                try {
                    $gc = self::gc();
                    if (!empty($gc['deleted'])) {
                        self::log('GC kept=' . $gc['kept'] . ' deleted=' . count($gc['deleted']));
                    }
                } catch (\Throwable $e) {
                    self::log('GC FAIL ' . $e->getMessage());
                }
            }

            return $meta;
        } finally {
            self::removeStagedFile($stagedFiles);
            self::removeStagedFile($stagedGz);
            self::removeStagedFile($stagedDb);
            self::releaseLock($lockHandle);
        }
    }

    /**
     * Tar every configured file source. Entries are buffered and the archive is written once
     * (per-file addFile() rewrites the whole tar, quadratic in the file count). An unreadable file
     * or directory does not fail the whole backup: it is skipped with a logged warning and listed
     * under 'skipped', which the backup manifest records.
     */
    private static function archiveFiles(string $tarPath): array
    {
        $tar = new PharData($tarPath);
        $tar->startBuffering();
        $entries = 0;
        $sources = [];
        $skipped = [];
        $addFile = static function (string $file, string $entry) use ($tar, &$entries, &$skipped): void {
            try {
                if (!is_readable($file)) throw new RuntimeException('not readable');
                $tar->addFile($file, $entry);
                $entries++;
            } catch (\Throwable $e) {
                $skipped[] = $entry;
                self::log("CREATE WARN skipped unreadable file {$entry}: " . $e->getMessage());
            }
        };
        foreach (self::$fileSources as $name => $path) {
            $name = (string)$name;
            $path = (string)$path;
            if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name)) {
                throw new RuntimeException("BackupService: invalid file source name: {$name}");
            }
            if ($path === '' || is_link($path)) {
                $sources[$name] = $path === '' ? 'not_configured' : 'symlink_skipped';
            } elseif (is_file($path)) {
                $addFile($path, $name . '/' . basename($path));
                $sources[$name] = 'file';
            } elseif (is_dir($path)) {
                $base = rtrim(str_replace('\\', '/', $path), '/') . '/';
                $relativeOf = static fn(\SplFileInfo $file): string => substr(str_replace('\\', '/', $file->getPathname()), strlen($base));
                if (!is_readable($path) || !is_executable($path)) {
                    $skipped[] = $name . '/';
                    self::log("CREATE WARN skipped unreadable directory {$name}/");
                    $sources[$name] = 'unreadable';
                    continue;
                }
                // Unreadable subdirectories are not descended into (RecursiveDirectoryIterator would throw).
                $filter = new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $file) use ($name, $relativeOf, &$skipped): bool {
                        if ($file->isLink() || !$file->isDir() || ($file->isReadable() && $file->isExecutable())) return true;
                        $skipped[] = $name . '/' . $relativeOf($file) . '/';
                        self::log("CREATE WARN skipped unreadable directory {$name}/" . $relativeOf($file) . '/');
                        return false;
                    }
                );
                foreach (new RecursiveIteratorIterator($filter) as $file) {
                    if ($file->isLink() || !$file->isFile()) continue;
                    $addFile($file->getPathname(), $name . '/' . $relativeOf($file));
                }
                $sources[$name] = 'directory';
            } else {
                $sources[$name] = 'missing';
            }
        }
        if ($entries === 0) {
            // PharData only writes the archive once it has an entry; keep the companion uniform.
            $tar->addFromString('.backup-files', "no file sources present\n");
        }
        $tar->stopBuffering();
        unset($tar);
        if (!is_file($tarPath)) {
            throw new RuntimeException('BackupService: files archive was not created');
        }
        return [
            'size_bytes' => filesize($tarPath),
            'sha256'     => hash_file('sha256', $tarPath),
            'entries'    => $entries,
            'sources'    => $sources,
            'skipped'    => $skipped,
        ];
    }

    private static function deleteBackup(array $row): void
    {
        self::$storage->delete($row['path']);
        $files = (string)($row['meta']['files_archive'] ?? '');
        if ($files !== '') self::$storage->delete($files);
    }

    // ---------- restore() ----------

    /**
     * Restore the live SQLite DB from a stored backup.
     *
     * Steps:
     *  1. Resolve & validate the source backup file (existence, sha256 match meta, integrity_check ok).
     *  2. Snapshot the current live DB into a 'pre-restore-snapshot' backup (unless skip_snapshot=true).
     *  3. Close the live PDO connection (Db::reset()) to release the file handle.
     *  4. Atomically replace the live .db with the backup (with retry for Windows file locks).
     *  5. Open a fresh PDO, integrity_check the live DB.
     *  6. Compare schema_migrations vs migrations/ files — return mismatch flags.
     *
     * Options:
     *   skip_snapshot => bool   default false
     *   max_retries   => int    default 3
     *   retry_delay   => int ms default 100
     *
     * Returns array: ['restored' => path, 'snapshot' => path|null, 'integrity' => 'ok'|err,
     *                 'schema_warnings' => [...], 'applied_count' => int, 'pending_count' => int]
     */
    public static function restore(string $remotePath, array $opts = []): array
    {
        if (self::$db === null) {
            throw new RuntimeException('BackupService::restore — not initialized');
        }
        if (preg_match('/\.sql(?:\.gz)?$/', $remotePath) === 1) {
            throw new RuntimeException('Restore: MySQL dumps are restored manually — stop web traffic and workers, then import the .sql file '
                . 'with the mysql client into db_name; automatic restore supports SQLite backups only');
        }
        if (self::$livePath === null) {
            throw new RuntimeException('BackupService::restore — live SQLite path unknown');
        }

        $skipSnapshot = (bool)($opts['skip_snapshot'] ?? false);
        $maxRetries   = max(1, (int)($opts['max_retries'] ?? 3));
        $retryDelayMs = max(0, (int)($opts['retry_delay'] ?? 100));
        $temporarySource = null;
        $restoreTemp = null;

        try {
        $stored = self::storedRow($remotePath);
        if ($stored === null) {
            throw new RuntimeException("Restore: backup not found: {$remotePath}");
        }
        $meta = $stored['meta'] ?? null;
        if (!is_array($meta) || $meta === []) {
            throw new RuntimeException('Restore: valid backup metadata is required');
        }
        $absSrc = $temporarySource = self::stagingPath('.restore.db');
        try {
            self::$storage->get($remotePath, $absSrc);
        } catch (\Throwable $e) {
            throw new RuntimeException("Restore: cannot download backup: {$remotePath}", 0, $e);
        }
        if (!is_file($absSrc)) {
            throw new RuntimeException("Restore: adapter did not create staged backup: {$remotePath}");
        }
        $expectedSha = strtolower((string)($meta['sha256'] ?? ''));
        $expectedSize = filter_var($meta['size_bytes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!preg_match('/^[0-9a-f]{64}$/', $expectedSha) || $expectedSize === false) {
            throw new RuntimeException('Restore: metadata must contain a valid sha256 and positive size_bytes');
        }
        if ((string)($meta['remote_path'] ?? '') !== $remotePath || (string)($meta['filename'] ?? '') !== basename($remotePath)) {
            throw new RuntimeException('Restore: metadata identity does not match the requested backup');
        }

        $actualSize = filesize($absSrc);
        $actual = hash_file('sha256', $absSrc);
        if ($actualSize !== $expectedSize) {
            throw new RuntimeException("Restore: size mismatch (expected {$expectedSize}, got {$actualSize})");
        }
        if (!hash_equals($expectedSha, (string)$actual)) {
            throw new RuntimeException("Restore: sha256 mismatch (expected {$expectedSha}, got {$actual})");
        }

        // integrity_check on the BACKUP file before we touch the live DB
        $verify = new PDO('sqlite:' . $absSrc, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $verifyRes = $verify->query('PRAGMA integrity_check')->fetchColumn();
        $verify = null;
        if ($verifyRes !== 'ok') {
            throw new RuntimeException("Restore: backup integrity_check failed: {$verifyRes}");
        }

        // 2. pre-restore-snapshot (BEFORE acquiring restore lock — create() owns its own lock)
        $snapshotPath = null;
        if (!$skipSnapshot) {
            try {
                $snap = self::create(['label' => 'pre-restore-snapshot', 'trigger' => 'auto']);
                $snapshotPath = $snap['remote_path'] ?? null;
                self::pruneSnapshots(5); // keep at most 5 pre-restore-snapshot backups
            } catch (\Throwable $e) {
                self::log('Restore: pre-snapshot failed: ' . $e->getMessage());
                throw new RuntimeException('Pre-restore snapshot failed: ' . $e->getMessage(), 0, $e);
            }
        }

        $livePath = self::$livePath;
        if (!is_file($livePath)) {
            throw new RuntimeException("Restore: live database not found: {$livePath}");
        }
        $restoreTemp = $livePath . '.restore-' . bin2hex(random_bytes(6)) . '.tmp';
        if (!copy($absSrc, $restoreTemp)
            || filesize($restoreTemp) !== $expectedSize
            || !hash_equals($expectedSha, (string)hash_file('sha256', $restoreTemp))) {
            throw new RuntimeException('Restore: failed to stage a complete verified replacement database');
        }
        @chmod($restoreTemp, 0640);

        $lockHandle = self::acquireLock();
        try {
            // 3. close this process's connections to the live file; the gate proves no other process holds it
            self::$db = null;
            $sharedReleased = class_exists('Db', false) && Db::releaseSqliteFile($livePath);
            gc_collect_cycles();

            try {
                $swapped = SqliteMaintenance::exclusive($livePath, 'backup restore', static function () use ($livePath, &$restoreTemp, $maxRetries, $retryDelayMs): array {
                    // 4. rollback-safe same-filesystem replacement
                    @unlink($livePath . '-wal');
                    @unlink($livePath . '-shm');
                    $rollbackPath = $livePath . '.pre-restore-' . bin2hex(random_bytes(6)) . '.rollback';
                    $movedLive = false;
                    for ($i = 0; $i < $maxRetries; $i++) {
                        if (@rename($livePath, $rollbackPath)) {
                            $movedLive = true;
                            break;
                        }
                        usleep($retryDelayMs * 1000);
                    }
                    if (!$movedLive) {
                        throw new RuntimeException('Restore: cannot move the live database aside; stop all workers and retry');
                    }
                    if (!@rename($restoreTemp, $livePath)) {
                        $restoreError = error_get_last()['message'] ?? 'unknown';
                        if (!@rename($rollbackPath, $livePath)) {
                            throw new RuntimeException("Restore: replacement failed and automatic rollback failed; recovery copy: {$rollbackPath}");
                        }
                        throw new RuntimeException("Restore: atomic replacement failed: {$restoreError}");
                    }
                    $restoreTemp = null;

                    // 5. open fresh PDO + integrity; restore the old DB if post-swap verification fails
                    try {
                        $newDb = new PDO('sqlite:' . $livePath, null, null, [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        ]);
                        $newDb->exec('PRAGMA journal_mode = WAL');
                        $newDb->exec('PRAGMA foreign_keys = ON');
                        $integrity = (string)$newDb->query('PRAGMA integrity_check')->fetchColumn();
                        if ($integrity !== 'ok') {
                            throw new RuntimeException("restored database integrity_check failed: {$integrity}");
                        }
                    } catch (\Throwable $verificationError) {
                        $newDb = null;
                        @unlink($livePath . '-wal');
                        @unlink($livePath . '-shm');
                        @unlink($livePath);
                        if (!@rename($rollbackPath, $livePath)) {
                            throw new RuntimeException(
                                "Restore verification failed and automatic rollback failed; recovery copy: {$rollbackPath}",
                                0,
                                $verificationError
                            );
                        }
                        throw new RuntimeException('Restore verification failed; previous live database was restored', 0, $verificationError);
                    }
                    @unlink($rollbackPath);
                    return [$newDb, $integrity];
                });
            } catch (\Throwable $restoreError) {
                // Refused (database busy) or rolled back: reconnect to whatever is live now.
                if (is_file($livePath)) {
                    self::$db = new PDO('sqlite:' . $livePath, null, null, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                    if ($sharedReleased) Db::init(self::$db, 'sqlite');
                }
                throw $restoreError;
            }
            [$newDb, $integrity] = $swapped;

            if ($sharedReleased) {
                Db::init($newDb, 'sqlite');
            }
            self::$db = $newDb;

            // 6. schema mismatch detection
            $schemaWarnings = [];
            $appliedCount = 0;
            $pendingCount = 0;
            try {
                $row = $newDb->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
                $appliedCount = (int)$row;
            } catch (\Throwable $e) {
                $schemaWarnings[] = 'schema_migrations table missing in restored DB';
            }

            $migDir = self::$rootDir . '/migrations';
            $allMigs = [];
            if (is_dir($migDir)) {
                foreach (glob($migDir . '/*.php') as $f) {
                    $allMigs[] = basename($f, '.php');
                }
            }
            sort($allMigs);

            try {
                $rows = $newDb->query("SELECT name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
                $appliedSet = array_flip($rows);
                foreach ($allMigs as $m) {
                    if (!isset($appliedSet[$m])) $pendingCount++;
                }
                if ($pendingCount > 0) {
                    $schemaWarnings[] = "Restored DB has older schema. Run: php cli/migrate.php ({$pendingCount} pending)";
                }
                $migSet = array_flip($allMigs);
                $extra = 0;
                foreach ($appliedSet as $name => $_) {
                    if (!isset($migSet[$name])) $extra++;
                }
                if ($extra > 0) {
                    $schemaWarnings[] = "Restored DB has {$extra} migrations not in current code. Check git history.";
                }
            } catch (\Throwable $e) { /* already warned */ }

            self::log("RESTORE OK src={$remotePath} snapshot=" . ($snapshotPath ?: '-') .
                " integrity={$integrity} applied={$appliedCount} pending={$pendingCount}");

            return [
                'restored'        => $remotePath,
                'snapshot'        => $snapshotPath,
                'integrity'       => $integrity,
                'schema_warnings' => $schemaWarnings,
                'applied_count'   => $appliedCount,
                'pending_count'   => $pendingCount,
                'meta'            => $meta,
            ];
        } finally {
            self::releaseLock($lockHandle);
        }
        } finally {
            self::removeStagedFile($temporarySource);
            if ($restoreTemp !== null && is_file($restoreTemp)) @unlink($restoreTemp);
        }
    }

    /** Keep only the newest `$keep` backups labelled 'pre-restore-snapshot'; delete the rest. */
    private static function pruneSnapshots(int $keep): void
    {
        $all = self::list();
        $snaps = [];
        foreach ($all as $row) {
            if ((string)($row['meta']['label'] ?? '') === 'pre-restore-snapshot') {
                $snaps[] = $row;
            }
        }
        if (count($snaps) <= $keep) return;
        // already newest-first
        for ($i = $keep; $i < count($snaps); $i++) {
            try {
                self::deleteBackup($snaps[$i]);
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    // ---------- list() / gc() ----------

    public static function list(): array
    {
        // Files archives are companions of a database backup, not restore points of their own.
        $rows = array_values(array_filter(
            self::$storage->list(),
            static fn(array $row): bool => !str_ends_with((string)($row['path'] ?? ''), '.files.tar')
        ));
        // Newest first without relying on 1 s file mtimes or directory order: microsecond creation
        // stamp, else the metadata/mtime second, then the -N suffix reserveUniquePath() appends.
        usort($rows, static fn(array $a, array $b): int => self::orderKey($b) <=> self::orderKey($a));
        return $rows;
    }

    private static function orderKey(array $row): array
    {
        $createdUs = (string)($row['meta']['created_us'] ?? '');
        $micro = ctype_digit($createdUs) ? (int)$createdUs : self::rowWhen($row)->getTimestamp() * 1000000;
        $suffix = preg_match('/-\d{8}-\d{6}-(\d+)\.(?:db|sql)(?:\.gz)?$/', (string)($row['path'] ?? ''), $m) === 1 ? (int)$m[1] : 0;
        return [$micro, $suffix];
    }

    /**
     * Garbage-collect old backups using grandfather-father-son retention.
     *
     * Rules:
     *  - Keep last `daily` backups from the last `daily` distinct days.
     *  - Keep last `weekly` Sunday backups (one per ISO week).
     *  - Keep last `monthly` 1st-of-month backups (one per month).
     *  - Always keep at least `minimum_kept` backups (newest first).
     *  - Manual / pre-restore-snapshot / pre-migration backups are preserved unconditionally.
     *
     * Returns ['kept' => int, 'deleted' => string[] (remote paths)].
     */
    public static function gc(): array
    {
        if (self::$storage === null) {
            throw new RuntimeException('BackupService not initialized');
        }
        $cfg = self::$cfg['retention'] ?? [];
        $daily   = (int)($cfg['daily']         ?? 7);
        $weekly  = (int)($cfg['weekly']        ?? 4);
        $monthly = (int)($cfg['monthly']       ?? 6);
        $minKept = (int)($cfg['minimum_kept']  ?? 3);

        $all = self::list(); // newest first
        if (count($all) <= $minKept) {
            return ['kept' => count($all), 'deleted' => []];
        }

        // Always-keep set: paths preserved no matter what.
        $keep = [];
        // Floor: newest $minKept entries always kept
        foreach (array_slice($all, 0, $minKept) as $row) {
            $keep[$row['path']] = true;
        }

        // Group by day (YYYY-MM-DD) — keep newest per day, last $daily days
        $byDay = [];
        foreach ($all as $row) {
            $when = self::rowWhen($row);
            $dKey = $when->format('Y-m-d');
            if (!isset($byDay[$dKey])) $byDay[$dKey] = $row['path']; // newest first → first wins
        }
        $dailyKept = 0;
        foreach ($byDay as $path) {
            if ($dailyKept >= $daily) break;
            $keep[$path] = true;
            $dailyKept++;
        }

        // Group by ISO week (YYYY-Www) — keep newest per week, last $weekly weeks
        $byWeek = [];
        foreach ($all as $row) {
            $when = self::rowWhen($row);
            $wKey = $when->format('o-W');
            if (!isset($byWeek[$wKey])) $byWeek[$wKey] = $row['path'];
        }
        $weeklyKept = 0;
        foreach ($byWeek as $path) {
            if ($weeklyKept >= $weekly) break;
            $keep[$path] = true;
            $weeklyKept++;
        }

        // Group by month (YYYY-MM) — keep newest per month, last $monthly months
        $byMonth = [];
        foreach ($all as $row) {
            $when = self::rowWhen($row);
            $mKey = $when->format('Y-m');
            if (!isset($byMonth[$mKey])) $byMonth[$mKey] = $row['path'];
        }
        $monthlyKept = 0;
        foreach ($byMonth as $path) {
            if ($monthlyKept >= $monthly) break;
            $keep[$path] = true;
            $monthlyKept++;
        }

        // Preserve manual / pre-migration / pre-restore-snapshot labels
        foreach ($all as $row) {
            $label = (string)($row['meta']['label'] ?? '');
            if (in_array($label, ['pre-restore-snapshot', 'pre-migration', 'manual'], true)) {
                $keep[$row['path']] = true;
            }
        }

        // Delete the rest
        $deleted = [];
        foreach ($all as $row) {
            if (isset($keep[$row['path']])) continue;
            try {
                self::deleteBackup($row);
                $deleted[] = $row['path'];
            } catch (\Throwable $e) {
                self::log('GC delete fail ' . $row['path'] . ' err=' . $e->getMessage());
            }
        }

        return ['kept' => count($keep), 'deleted' => $deleted];
    }

    private static function rowWhen(array $row): \DateTimeImmutable
    {
        $ts = (string)($row['meta']['timestamp'] ?? '');
        if ($ts !== '') {
            try { return new \DateTimeImmutable($ts); } catch (\Throwable $e) { /* fallthrough */ }
        }
        return (new \DateTimeImmutable('@' . (int)($row['mtime'] ?? time())))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private static function assertPrivateAbsolutePath(string $path, string $name): void
    {
        if (!self::isAbsolutePath($path)) {
            throw new RuntimeException("{$name} must be an absolute path outside the application root");
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new RuntimeException("{$name} contains control characters");
        }
        $probe = $path;
        $suffix = [];
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) break;
            array_unshift($suffix, basename($probe));
            $probe = $parent;
        }
        $canonical = realpath($probe);
        if ($canonical === false) {
            throw new RuntimeException("{$name} parent cannot be resolved");
        }
        foreach ($suffix as $segment) {
            if ($segment === '.' || $segment === '') continue;
            $canonical = $segment === '..' ? dirname($canonical) : $canonical . DIRECTORY_SEPARATOR . $segment;
        }
        $normalized = rtrim(str_replace('\\', '/', $canonical), '/');
        $appDir = dirname(__DIR__);
        $root = rtrim(str_replace('\\', '/', realpath($appDir) ?: $appDir), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $normalized = strtolower($normalized);
            $root = strtolower($root);
        }
        if ($normalized === $root || str_starts_with($normalized, $root . '/')) {
            throw new RuntimeException("{$name} must be outside the application root");
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    // ---------- locking ----------

    private static function acquireLock()
    {
        $lockPath = self::$storage instanceof LocalBackupStorage
            ? self::$storage->root() . DIRECTORY_SEPARATOR . '.backup.lock'
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . '.bbe-backup-' . hash('sha256', (string)self::$rootDir) . '.lock';

        $dir = dirname($lockPath);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $h = fopen($lockPath, 'c');
        if ($h === false) {
            throw new RuntimeException("BackupService: cannot open lock file {$lockPath}");
        }
        $deadline = microtime(true) + 10.0;
        while (true) {
            if (flock($h, LOCK_EX | LOCK_NB)) {
                return $h;
            }
            if (microtime(true) > $deadline) {
                fclose($h);
                throw new RuntimeException('BackupService: lock acquisition timed out');
            }
            usleep(100000); // 100ms
        }
    }

    private static function releaseLock($h): void
    {
        if (is_resource($h)) {
            @flock($h, LOCK_UN);
            @fclose($h);
        }
    }
}
