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
    /** MySQL installs: verified scratch-first restore of a backup dump (restore()). */
    private static ?MysqlUpdateDatabaseBackup $mysqlDatabase = null;
    /**
     * Builds $mysqlDatabase on first use. Construction validates the connection configuration and
     * throws for one it cannot dump (e.g. a db_name with a hyphen); doing that lazily keeps init()
     * working (list, files restore) and makes create() — and so a pre-migration backup — fail loudly.
     */
    private static ?Closure $mysqlDatabaseFactory = null;
    private static string $mysqlScratchDatabase = '';
    /** Durable private directory that keeps a MySQL rollback dump when a restore fails after dropping live. */
    private static string $recoveryDir = '';
    /** Non-database state archived next to every backup: name => file or directory. */
    private static array $fileSources = [];

    public static function init(PDO $db, ?array $config = null, ?string $rootDir = null, ?BackupStorage $storage = null): void
    {
        self::$db = $db;
        self::$rootDir = $rootDir ?: dirname(__DIR__);
        self::$cfg = $config ?: self::loadConfig(self::$rootDir . '/config/backup.json');

        self::$mysqlDumper = null;
        self::$mysqlDatabase = null;
        self::$mysqlDatabaseFactory = null;
        self::$mysqlScratchDatabase = '';
        self::$fileSources = [];
        if ($config !== null) {
            $storageRoot = (string)($config['storage_dir'] ?? self::$rootDir . '/storage/backups');
            $storageClass = (string)($config['storage_adapter'] ?? 'LocalBackupStorage');
            self::$fileSources = (array)($config['files'] ?? []);
            self::$recoveryDir = (string)($config['recovery_dir'] ?? dirname($storageRoot) . '/restore-recovery');
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
            self::$recoveryDir = (string)($appCfg['backup_recovery_dir'] ?? '')
                ?: rtrim((string)($appCfg['private_runtime_dir'] ?? dirname(self::$rootDir) . '/bareboneseshop-private'), '/\\') . '/restore-recovery';
            if (self::driverName($db) === 'mysql') {
                self::$mysqlDatabaseFactory = static fn(): MysqlUpdateDatabaseBackup => new MysqlUpdateDatabaseBackup(
                    (string)($appCfg['db_host'] ?? ''),
                    (int)($appCfg['db_port'] ?? 3306),
                    (string)($appCfg['db_name'] ?? ''),
                    (string)($appCfg['db_user'] ?? ''),
                    (string)($appCfg['db_password'] ?? ''),
                    (string)($appCfg['db_charset'] ?? 'utf8mb4'),
                    // dumpTo()/restoreBackupDump() never touch the probe schema; the constructor only validates its name.
                    trim((string)($appCfg['update_mysql_probe_database'] ?? '')) ?: substr((string)($appCfg['db_name'] ?? ''), 0, 51) . '_update_probe',
                    self::mysqlBinary((string)($appCfg['update_mysql_dump_binary'] ?? 'mysqldump'), 'mysqldump', 'mariadb-dump'),
                    self::mysqlBinary((string)($appCfg['update_mysql_client_binary'] ?? 'mysql'), 'mysql', 'mariadb')
                );
                self::$mysqlScratchDatabase = trim((string)($appCfg['backup_mysql_restore_scratch_database'] ?? ''))
                    ?: substr((string)($appCfg['db_name'] ?? ''), 0, 48) . '_restore_scratch';
                self::$mysqlDumper = static fn(string $dumpPath): array => self::mysqlDatabase()->dumpTo($dumpPath);
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
        // Windows executables carry the .exe extension (is_executable() goes by it).
        $suffix = DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
        $onPath = static function (string $binary) use ($path, $suffix): bool {
            foreach (explode(PATH_SEPARATOR, $path ?? (string)getenv('PATH')) as $dir) {
                if ($dir !== '' && is_file($dir . '/' . $binary . $suffix) && is_executable($dir . '/' . $binary . $suffix)) return true;
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
        self::$mysqlDatabase = null;
        self::$mysqlDatabaseFactory = null;
        self::$mysqlScratchDatabase = '';
        self::$recoveryDir = '';
        self::$fileSources = [];
    }

    /** Override the MySQL dump step (tests, custom dump tooling). */
    public static function useMysqlDumper(?Closure $dumper): void
    {
        self::$mysqlDumper = $dumper;
    }

    /** MySQL dump + verified restore through $database; restores go through $scratchDatabase first. */
    public static function useMysqlDatabase(?MysqlUpdateDatabaseBackup $database, string $scratchDatabase = ''): void
    {
        self::$mysqlDatabase = $database;
        self::$mysqlDatabaseFactory = null;
        self::$mysqlScratchDatabase = $scratchDatabase;
        self::$mysqlDumper = $database === null ? null : static fn(string $dumpPath): array => $database->dumpTo($dumpPath);
    }

    /** The configured MySQL backup adapter, built on first use; null when none is configured. */
    private static function mysqlDatabase(): ?MysqlUpdateDatabaseBackup
    {
        if (self::$mysqlDatabase === null && self::$mysqlDatabaseFactory !== null) {
            try {
                self::$mysqlDatabase = (self::$mysqlDatabaseFactory)();
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException('MySQL backups cannot use this database configuration: ' . $e->getMessage(), 0, $e);
            }
        }
        return self::$mysqlDatabase;
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
            'include_files'       => true,
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
        $dumpInfo = null;
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
                    $dumpInfo = (self::$mysqlDumper)($absPath);
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
            if (is_array($dumpInfo) && isset($dumpInfo['character_set'], $dumpInfo['collation'])) {
                // A restore recreates the database with the character set and collation it was dumped from.
                $meta['character_set'] = (string)$dumpInfo['character_set'];
                $meta['collation'] = (string)$dumpInfo['collation'];
            }
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

    /** A directory can be listed and entered. Windows has no execute bit: is_executable() is false for every directory. */
    private static function dirTraversable(string $path): bool
    {
        return is_readable($path) && (DIRECTORY_SEPARATOR === '\\' || is_executable($path));
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
                // A configured source that cannot be read at all fails the backup (only files and
                // subdirectories inside a readable source are skipped).
                if (!is_readable($path)) {
                    throw new RuntimeException("BackupService: file source {$name} is not readable");
                }
                $addFile($path, $name . '/' . basename($path));
                $sources[$name] = 'file';
            } elseif (is_dir($path)) {
                $base = rtrim(str_replace('\\', '/', $path), '/') . '/';
                $relativeOf = static fn(\SplFileInfo $file): string => substr(str_replace('\\', '/', $file->getPathname()), strlen($base));
                if (!self::dirTraversable($path)) {
                    throw new RuntimeException("BackupService: file source {$name} is not readable");
                }
                // Unreadable subdirectories are not descended into (RecursiveDirectoryIterator would throw).
                $filter = new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $file) use ($name, $relativeOf, &$skipped): bool {
                        if ($file->isLink() || !$file->isDir() || self::dirTraversable($file->getPathname())) return true;
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
            return self::restoreMysql($remotePath, $opts);
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
        $absSrc = $temporarySource = self::stagingPath('.restore.db');
        $meta = self::downloadVerified($remotePath, $absSrc);
        $expectedSha = strtolower((string)$meta['sha256']);
        $expectedSize = (int)$meta['size_bytes'];

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
            try {
                $applied = $newDb->query("SELECT name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
            } catch (\Throwable $e) {
                $applied = null;
            }
            [$schemaWarnings, $appliedCount, $pendingCount] = self::migrationStatus($applied);

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

    /**
     * Download a stored backup object to $localPath and prove it is the object its metadata
     * describes (identity, size, sha256) before anything uses it. Returns that metadata.
     */
    private static function downloadVerified(string $remotePath, string $localPath): array
    {
        $stored = self::storedRow($remotePath);
        if ($stored === null) {
            throw new RuntimeException("Restore: backup not found: {$remotePath}");
        }
        $meta = $stored['meta'] ?? null;
        if (!is_array($meta) || $meta === []) {
            throw new RuntimeException('Restore: valid backup metadata is required');
        }
        try {
            self::$storage->get($remotePath, $localPath);
        } catch (\Throwable $e) {
            throw new RuntimeException("Restore: cannot download backup: {$remotePath}", 0, $e);
        }
        if (!is_file($localPath)) {
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

        $actualSize = filesize($localPath);
        $actual = hash_file('sha256', $localPath);
        if ($actualSize !== $expectedSize) {
            throw new RuntimeException("Restore: size mismatch (expected {$expectedSize}, got {$actualSize})");
        }
        if (!hash_equals($expectedSha, (string)$actual)) {
            throw new RuntimeException("Restore: sha256 mismatch (expected {$expectedSha}, got {$actual})");
        }
        return $meta;
    }

    /** [warnings, applied count, pending count] for the restored schema_migrations names (null: table missing). */
    private static function migrationStatus(?array $applied): array
    {
        if ($applied === null) {
            return [['schema_migrations table missing in restored DB'], 0, 0];
        }
        $allMigs = [];
        foreach (glob(self::$rootDir . '/migrations/*.php') ?: [] as $f) {
            $allMigs[] = basename($f, '.php');
        }
        sort($allMigs);
        $appliedSet = array_flip(array_map('strval', $applied));
        $migSet = array_flip($allMigs);
        $pendingCount = count(array_diff_key($migSet, $appliedSet));
        $extra = count(array_diff_key($appliedSet, $migSet));
        $warnings = [];
        if ($pendingCount > 0) {
            $warnings[] = "Restored DB has older schema. Run: php cli/migrate.php ({$pendingCount} pending)";
        }
        if ($extra > 0) {
            $warnings[] = "Restored DB has {$extra} migrations not in current code. Check git history.";
        }
        return [$warnings, count($applied), $pendingCount];
    }

    /**
     * MySQL/MariaDB counterpart of the SQLite restore: the dump is downloaded and verified
     * (identity, size, sha256), imported into the scratch database and checked row-for-row against
     * the dump, and only then does it replace db_name (see MysqlUpdateDatabaseBackup::restoreBackupDump).
     * A dump of the current database is taken first — always stored as a pre-restore-snapshot
     * (skip_snapshot is refused: the rollback dump must never be the only copy of live) — and is
     * re-imported automatically if replacing the live database fails. Its local copy lives in the
     * private recovery directory (0600) and is kept, with its path in the error, whenever the restore
     * fails after the live database was dropped.
     *
     * Replacing db_name discards everything written while it runs, and MySQL has no file lock that
     * proves nobody else is writing, so the caller must confirm writers_stopped => true
     * (cli/restore.php --i-stopped-writers) after stopping web traffic, workers and cron.
     */
    private static function restoreMysql(string $remotePath, array $opts): array
    {
        if (str_ends_with($remotePath, '.gz')) {
            throw new RuntimeException('Restore: restore the uncompressed .sql backup stored next to this .sql.gz copy');
        }
        if (($opts['writers_stopped'] ?? false) !== true) {
            throw new RuntimeException('Restore: a MySQL restore drops and re-imports db_name, discarding anything written while it runs. '
                . 'Stop web traffic (maintenance page), workers and cron, then re-run with --i-stopped-writers');
        }
        $mysql = self::driverName(self::$db) === 'mysql' ? self::mysqlDatabase() : null;
        if ($mysql === null) {
            throw new RuntimeException('Restore: a MySQL dump is restored only into a configured MySQL/MariaDB installation (db_driver=mysql)');
        }
        if (!empty($opts['skip_snapshot'])) {
            throw new RuntimeException('Restore: --skip-snapshot is not supported for a MySQL/MariaDB restore; the pre-restore-snapshot is the '
                . 'stored copy of the current database if the restore and its automatic rollback both fail');
        }
        $source = null;
        $rollback = null;
        $keepRollback = false;
        try {
            $source = self::stagingPath('.restore.sql');
            $meta = self::downloadVerified($remotePath, $source);
            if (($meta['driver'] ?? 'mysql') !== 'mysql') {
                throw new RuntimeException('Restore: backup metadata does not describe a MySQL dump');
            }
            $schema = null;
            if (isset($meta['character_set'], $meta['collation'])) {
                $schema = ['character_set' => (string)$meta['character_set'], 'collation' => (string)$meta['collation']];
            }

            // Pre-restore snapshot (create() owns the backup lock) doubles as the automatic rollback copy.
            $snapshotPath = null;
            try {
                $rollback = self::recoveryPath($mysql->database());
                $snap = self::create(['label' => 'pre-restore-snapshot', 'trigger' => 'auto']);
                $snapshotPath = (string)$snap['remote_path'];
                self::pruneSnapshots(5);
                self::downloadVerified($snapshotPath, $rollback);
                @chmod($rollback, 0600);
            } catch (\Throwable $e) {
                self::log('Restore: pre-snapshot failed: ' . $e->getMessage());
                throw new RuntimeException('Pre-restore snapshot failed: ' . $e->getMessage(), 0, $e);
            }

            // This process's own connections are not "other writers".
            $ownSessions = [];
            $connections = [self::$db];
            try {
                if (class_exists('Db', false) && Db::isMysql()) $connections[] = Db::get();
            } catch (\Throwable $e) { /* no shared connection */ }
            foreach ($connections as $connection) {
                try {
                    if ($connection instanceof PDO) $ownSessions[] = (int)$connection->query('SELECT CONNECTION_ID()')->fetchColumn();
                } catch (\Throwable $e) { /* not a MySQL session */ }
            }

            $lockHandle = self::acquireLock();
            try {
                $result = $mysql->restoreBackupDump($source, self::$mysqlScratchDatabase, $rollback, $schema, $ownSessions);
            } catch (\Throwable $e) {
                if ($e->getCode() === MysqlUpdateDatabaseBackup::RESTORE_FAILED_AFTER_DROP) {
                    // Live was dropped: whatever happened next, the pre-restore dump stays where the operator can use it.
                    $keepRollback = true;
                    $e = new RuntimeException($e->getMessage() . " (pre-restore dump kept at {$rollback}; also stored as backup {$snapshotPath})",
                        MysqlUpdateDatabaseBackup::RESTORE_FAILED_AFTER_DROP, $e);
                }
                self::log("RESTORE FAIL driver=mysql src={$remotePath} err=" . $e->getMessage());
                throw $e;
            } finally {
                self::releaseLock($lockHandle);
                // A dropped and recreated live database leaves this connection without a default schema.
                try {
                    self::$db->exec('USE `' . str_replace('`', '``', $mysql->database()) . '`');
                } catch (\Throwable $e) { /* the next Db::get() reconnects */ }
            }

            [$schemaWarnings, $appliedCount, $pendingCount] = self::migrationStatus($result['migrations']);
            self::log("RESTORE OK driver=mysql src={$remotePath} snapshot=" . ($snapshotPath ?: '-')
                . " tables={$result['tables']} rows={$result['rows']} applied={$appliedCount} pending={$pendingCount}");

            return [
                'restored'        => $remotePath,
                'snapshot'        => $snapshotPath,
                'integrity'       => 'ok (scratch restore matched the dump row-for-row; live matches scratch)',
                'schema_warnings' => $schemaWarnings,
                'applied_count'   => $appliedCount,
                'pending_count'   => $pendingCount,
                'tables'          => $result['tables'],
                'rows'            => $result['rows'],
                'meta'            => $meta,
            ];
        } finally {
            if (!$keepRollback && $rollback !== null && is_file($rollback)) @unlink($rollback);
            self::removeStagedFile($source);
        }
    }

    /**
     * A fresh 0600 file path in the private recovery directory (created 0700) for the MySQL rollback dump.
     * Unlike the staging directory (system temp, often cleared on reboot) it survives until an operator removes it.
     */
    private static function recoveryPath(string $database): string
    {
        $dir = self::$recoveryDir;
        if ($dir === '') {
            throw new RuntimeException('BackupService: no private recovery directory configured');
        }
        self::assertPrivateAbsolutePath($dir, 'backup_recovery_dir');
        if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))) {
            throw new RuntimeException("BackupService: cannot create the private recovery directory {$dir}");
        }
        @chmod($dir, 0700);
        $path = $dir . DIRECTORY_SEPARATOR . 'pre-restore-' . $database . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql';
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException("BackupService: cannot create the rollback dump {$path}");
        }
        fclose($handle);
        @chmod($path, 0600);
        return $path;
    }

    /**
     * Restore the invoices/uploads/GDPR-salt archive stored next to backup $remotePath into the
     * file sources configured now. The archive is verified (sha256, size, entry count) and
     * extracted into a private staging directory by a strict tar reader that accepts only regular
     * files and directories with relative paths (no absolute paths, '..', links or devices). A live
     * file whose content differs from the backup is never overwritten silently: nothing is written
     * unless force => true (cli/restore.php --force). Files absent from the backup are kept.
     *
     * Returns ['archive', 'written', 'unchanged', 'overwritten' => entries, 'skipped_sources' => names].
     */
    public static function restoreFiles(string $remotePath, array $opts = []): array
    {
        if (self::$storage === null) {
            throw new RuntimeException('BackupService::restoreFiles — not initialized');
        }
        $stored = self::storedRow($remotePath);
        $meta = is_array($stored['meta'] ?? null) ? $stored['meta'] : [];
        $archivePath = (string)($meta['files_archive'] ?? '');
        if ($archivePath === '') {
            throw new RuntimeException("Restore: backup {$remotePath} has no files archive");
        }
        if ($archivePath !== $remotePath . '.files.tar') {
            throw new RuntimeException('Restore: files archive metadata does not belong to the requested backup');
        }
        $force = (bool)($opts['force'] ?? false);
        $staged = null;
        $stagingDir = null;
        try {
            $staged = self::stagingPath('.files.tar');
            $archiveMeta = self::downloadVerified($archivePath, $staged);
            $stagingDir = dirname($staged) . DIRECTORY_SEPARATOR . 'files';
            if (!@mkdir($stagingDir, 0700)) {
                throw new RuntimeException('Restore: cannot create the files staging directory');
            }
            $entries = array_values(array_filter(self::extractTarSafely($staged, $stagingDir), static fn(string $e): bool => $e !== '.backup-files'));
            $expectedEntries = $archiveMeta['entries'] ?? null;
            if (is_int($expectedEntries) && $expectedEntries !== count($entries)) {
                throw new RuntimeException("Restore: files archive holds " . count($entries) . " files, its metadata records {$expectedEntries}");
            }
            $kinds = is_array($archiveMeta['sources'] ?? null) ? $archiveMeta['sources'] : (array)($meta['files'] ?? []);

            // Plan everything before writing anything: containment (no symlinked or non-directory path
            // component below the configured directory), target type, writability and content conflicts.
            $plan = [];
            $conflicts = [];
            $refused = [];
            $skippedSources = [];
            $targets = [];
            $unchanged = 0;
            foreach ($entries as $entry) {
                [$name, $rest] = explode('/', $entry, 2) + [1 => ''];
                $root = (string)(self::$fileSources[$name] ?? '');
                if ($root === '') {
                    $skippedSources[$name] = true;
                    continue;
                }
                $kind = (string)($kinds[$name] ?? '');
                if ($kind === 'file' && $rest !== '' && !str_contains($rest, '/')) {
                    [$target, $base] = [$root, dirname($root)];
                } elseif ($kind === 'directory' && $rest !== '') {
                    [$target, $base] = [rtrim($root, '/\\') . '/' . $rest, $root];
                } else {
                    throw new RuntimeException("Restore: files archive entry {$entry} does not fit source {$name} ({$kind})");
                }
                $source = $stagingDir . '/' . $entry;
                if (isset($targets[$target])) {
                    $refused[] = "{$entry} (same target as {$targets[$target]})";
                    continue;
                }
                $targets[$target] = $entry;
                $directory = self::inspectRestoreDirectory($base, dirname($target));
                if (is_string($directory)) {
                    $refused[] = "{$entry} ({$directory})";
                    continue;
                }
                if (is_link($target) || (file_exists($target) && !is_file($target))) {
                    $refused[] = "{$entry} (target is a link or not a regular file)";
                    continue;
                }
                $overwrite = false;
                if (is_file($target)) {
                    if (hash_equals((string)hash_file('sha256', $source), (string)hash_file('sha256', $target))) {
                        $unchanged++;
                        continue;
                    }
                    $conflicts[] = $entry;
                    $overwrite = true;
                }
                if (!is_writable($directory['existing'])) {
                    $refused[] = "{$entry} ({$directory['existing']} is not writable by this user)";
                    continue;
                }
                $plan[] = [$entry, $source, $target, $base, self::restoredFileOwnership($target, $directory['existing'], $name === 'gdpr_salt'), $overwrite];
            }
            if ($refused !== []) {
                throw new RuntimeException('Restore: ' . count($refused) . ' target(s) cannot be restored safely, nothing was written: '
                    . implode(', ', array_slice($refused, 0, 5)) . (count($refused) > 5 ? ', ...' : ''));
            }
            if ($conflicts !== [] && !$force) {
                throw new RuntimeException('Restore: ' . count($conflicts) . ' live file(s) differ from the backup (' . implode(', ', array_slice($conflicts, 0, 5))
                    . (count($conflicts) > 5 ? ', ...' : '') . '); nothing was written. Review them, then re-run with --force to overwrite');
            }

            $overwritten = [];
            $written = [];
            foreach ($plan as [$entry, $source, $target, $base, $ownership, $overwrite]) {
                try {
                    self::installRestoredFile($source, $target, $base, $ownership);
                } catch (\Throwable $e) {
                    $message = 'Restore: files restore stopped at ' . $entry . ': ' . preg_replace('/^Restore: /', '', $e->getMessage()) . '. '
                        . ($written === [] ? 'No file was written.' : count($written) . ' file(s) were already written: ' . implode(', ', $written));
                    self::log('RESTORE FILES FAIL src=' . $archivePath . ' ' . $message);
                    throw new RuntimeException($message, 0, $e);
                }
                $written[] = $entry;
                if ($overwrite) $overwritten[] = $entry;
            }
            self::log("RESTORE FILES OK src={$archivePath} written=" . count($plan) . " unchanged={$unchanged} overwritten=" . count($overwritten)
                . ($skippedSources !== [] ? ' skipped_sources=' . implode(',', array_keys($skippedSources)) : ''));
            return [
                'archive'         => $archivePath,
                'written'         => count($plan),
                'unchanged'       => $unchanged,
                'overwritten'     => $overwritten,
                'skipped_sources' => array_keys($skippedSources),
            ];
        } finally {
            if ($stagingDir !== null) self::removeTree($stagingDir);
            self::removeStagedFile($staged);
        }
    }

    /**
     * Minimal ustar reader: extracts regular files and directories into the empty $directory and
     * returns the file paths. Anything else — links, devices, PAX/GNU extension headers, absolute
     * or parent-relative paths, duplicates, a bad header checksum, a truncated archive — is refused.
     */
    private static function extractTarSafely(string $tarPath, string $directory): array
    {
        $handle = @fopen($tarPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Restore: cannot open the files archive');
        }
        $files = [];
        $seen = [];
        try {
            while (true) {
                $header = (string)fread($handle, 512);
                if (strlen($header) !== 512) {
                    throw new RuntimeException('Restore: files archive is truncated');
                }
                if ($header === str_repeat("\0", 512)) {
                    break;
                }
                $checksum = octdec(trim(substr($header, 148, 8), "\0 "));
                $sum = 0;
                for ($i = 0; $i < 512; $i++) {
                    $sum += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
                }
                if ($sum !== $checksum) {
                    throw new RuntimeException('Restore: files archive header checksum mismatch');
                }
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = substr($header, 257, 5) === 'ustar' ? rtrim(substr($header, 345, 155), "\0") : '';
                $path = $prefix !== '' ? $prefix . '/' . $name : $name;
                $type = $header[156];
                $sizeField = trim(substr($header, 124, 12), "\0 ");
                if (!preg_match('/^[0-7]{1,11}$/', $sizeField)) {
                    throw new RuntimeException('Restore: files archive entry has an invalid size');
                }
                $size = (int)octdec($sizeField);
                $isDirectory = $type === '5';
                $path = $isDirectory ? rtrim($path, '/') : $path;
                if (!self::isSafeArchivePath($path)) {
                    throw new RuntimeException('Restore: files archive entry has an unsafe path: ' . substr(preg_replace('/[\x00-\x1F\x7F]/', '?', $path) ?? '', 0, 200));
                }
                if (!$isDirectory && $type !== '0' && $type !== "\0") {
                    throw new RuntimeException("Restore: files archive entry {$path} is not a regular file or directory; refusing");
                }
                if (isset($seen[$path])) {
                    throw new RuntimeException("Restore: files archive lists {$path} twice");
                }
                $seen[$path] = true;
                $target = $directory . '/' . $path;
                if ($isDirectory) {
                    if (!is_dir($target) && !@mkdir($target, 0700, true)) {
                        throw new RuntimeException("Restore: cannot stage directory {$path}");
                    }
                    if ($size !== 0) {
                        throw new RuntimeException("Restore: files archive directory {$path} carries data");
                    }
                    continue;
                }
                if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0700, true)) {
                    throw new RuntimeException("Restore: cannot stage {$path}");
                }
                $out = @fopen($target, 'xb');
                if ($out === false) {
                    throw new RuntimeException("Restore: cannot stage {$path}");
                }
                try {
                    $remaining = $size;
                    while ($remaining > 0) {
                        $chunk = fread($handle, min(1048576, $remaining));
                        if ($chunk === false || $chunk === '') {
                            throw new RuntimeException("Restore: files archive is truncated inside {$path}");
                        }
                        if (fwrite($out, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException("Restore: cannot stage {$path}");
                        }
                        $remaining -= strlen($chunk);
                    }
                } finally {
                    fclose($out);
                }
                $padding = (512 - $size % 512) % 512;
                if ($padding > 0 && strlen((string)fread($handle, $padding)) !== $padding) {
                    throw new RuntimeException('Restore: files archive is truncated');
                }
                $files[] = $path;
            }
        } finally {
            fclose($handle);
        }
        return $files;
    }

    private static function isSafeArchivePath(string $path): bool
    {
        // ':' and '\' are ordinary filename characters on Linux ('foto 10:30.jpg'); on Windows they
        // are drive and path separators.
        $forbidden = PHP_OS_FAMILY === 'Windows' ? '/[\x00-\x1F\x7F\\\\:]/' : '/[\x00-\x1F\x7F]/';
        if ($path === '' || strlen($path) > 4096 || preg_match($forbidden, $path) === 1 || str_starts_with($path, '/')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return false;
        }
        return true;
    }

    /**
     * Checks the directory a restored file goes to without creating anything. $base (the configured
     * directory, or the parent of a configured file) and its ancestors are operator configuration and
     * may be symlinks; every component below $base is lstat()ed and must be a real directory, not a
     * symlink. Returns ['create' => directories to create in order, 'existing' => deepest existing
     * directory], or the reason the path is refused.
     */
    private static function inspectRestoreDirectory(string $base, string $directory): array|string
    {
        $base = rtrim($base, '/\\');
        if ($directory !== $base && !str_starts_with($directory, $base . '/')) {
            return 'outside its configured directory';
        }
        $create = [];
        $existing = $base;
        if (is_link($base) && !is_dir($base)) {
            return "{$base} is a dangling link";
        }
        if (!is_dir($base)) {
            // The configured directory itself (and missing configured ancestors) is created.
            for ($probe = $base; !is_dir($probe); $probe = dirname($probe)) {
                if (is_link($probe) || file_exists($probe) || dirname($probe) === $probe) return "{$probe} is not a directory";
                array_unshift($create, $probe);
                $existing = dirname($probe);
            }
        }
        $current = $base;
        foreach ($directory === $base ? [] : explode('/', substr($directory, strlen($base) + 1)) as $segment) {
            $current .= '/' . $segment;
            if ($create !== []) {
                $create[] = $current;
                continue;
            }
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if ($stat === false) {
                $create[] = $current;
                continue;
            }
            if (($stat['mode'] & 0170000) === 0120000) return "{$current} is a symlink";
            if (($stat['mode'] & 0170000) !== 0040000) return "{$current} is not a directory";
            $existing = $current;
        }
        return ['create' => $create, 'existing' => $existing];
    }

    /**
     * Mode and owner a restored file gets: those of the file it replaces, else derived from the
     * directory it lands in (no execute bits; a new GDPR salt never group-writable or world-readable),
     * so a restore run as root does not leave files the web server cannot read. New directories copy
     * the mode and owner of that directory.
     */
    private static function restoredFileOwnership(string $target, string $directory, bool $secret): array
    {
        $file = is_file($target) ? @stat($target) : false;
        $dir = @stat($directory);
        $dirMode = is_array($dir) ? $dir['mode'] & 07777 : 0750;
        $mode = is_array($file) ? $file['mode'] & 07777 : ($dirMode & 0666);
        if (!is_array($file) && $secret) $mode &= 0640;
        $template = is_array($file) ? $file : $dir;
        return [
            'mode'     => $mode,
            'dir_mode' => $dirMode,
            'uid'      => is_array($template) ? (int)$template['uid'] : null,
            'gid'      => is_array($template) ? (int)$template['gid'] : null,
            'dir_uid'  => is_array($dir) ? (int)$dir['uid'] : null,
            'dir_gid'  => is_array($dir) ? (int)$dir['gid'] : null,
        ];
    }

    /** chown/chgrp are applied only when running as root (an unprivileged user cannot give files away). */
    private static function applyOwnership(string $path, ?int $uid, ?int $gid): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) return;
        if ($uid !== null && !@chown($path, $uid)) {
            throw new RuntimeException("Restore: cannot set the owner of {$path}");
        }
        if ($gid !== null && !@chgrp($path, $gid)) {
            throw new RuntimeException("Restore: cannot set the group of {$path}");
        }
    }

    /**
     * Atomic same-directory install of a staged file. Missing directories are created one at a time
     * after re-checking every component with lstat (no symlink may have appeared since planning),
     * and the result must still resolve inside $base.
     */
    private static function installRestoredFile(string $source, string $target, string $base, array $ownership): void
    {
        $directory = self::inspectRestoreDirectory($base, dirname($target));
        if (is_string($directory)) {
            throw new RuntimeException("Restore: {$target} cannot be restored safely ({$directory})");
        }
        foreach ($directory['create'] as $dir) {
            if (!@mkdir($dir, $ownership['dir_mode']) || is_link($dir) || !is_dir($dir)) {
                throw new RuntimeException("Restore: cannot create {$dir}");
            }
            @chmod($dir, $ownership['dir_mode']);
            self::applyOwnership($dir, $ownership['dir_uid'], $ownership['dir_gid']);
        }
        $parent = dirname($target);
        $realBase = realpath($base);
        $realDirectory = realpath($parent);
        if ($realBase === false || $realDirectory === false
            || ($realDirectory !== $realBase && !str_starts_with($realDirectory, rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException("Restore: {$target} resolves outside its configured directory; refusing");
        }
        $temporary = $parent . DIRECTORY_SEPARATOR . '.' . basename($target) . '.restore-' . bin2hex(random_bytes(6)) . '.tmp';
        if (!@copy($source, $temporary)) {
            @unlink($temporary);
            throw new RuntimeException("Restore: cannot stage {$target}");
        }
        try {
            @chmod($temporary, $ownership['mode']);
            self::applyOwnership($temporary, $ownership['uid'], $ownership['gid']);
        } catch (\Throwable $e) {
            @unlink($temporary);
            throw $e;
        }
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException("Restore: cannot install {$target}");
        }
    }

    private static function removeTree(string $path): void
    {
        FileSystem::removeTree($path);
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
        $path = (string)($row['path'] ?? '');
        $suffix = preg_match('/-\d{8}-\d{6}-(\d+)\.(?:db|sql)(?:\.gz)?$/', $path, $m) === 1 ? (int)$m[1] : 0;
        // A .gz copy shares its backup's creation stamp; the plain restore point sorts first.
        $plain = self::isCompressedCopy($row) ? 0 : 1;
        return [$micro, $suffix, $plain];
    }

    /** True for the gzip companion of a backup (restore uses the uncompressed object next to it). */
    public static function isCompressedCopy(array $row): bool
    {
        return str_ends_with((string)($row['path'] ?? ''), '.gz') || (string)($row['meta']['content_encoding'] ?? '') === 'gzip';
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
