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
 *   enabled, storage, compression, retention { daily, weekly, monthly, minimum_kept, pre_migration, manual_max_age_days },
 *   auto_pre_migration, auto_gc_on_backup, wal_checkpoint, log_file, log_max_bytes, log_max_files
 */
class BackupService
{
    /** A copy of live taken by restore() — not a restore point of its own (gc rotation and --latest skip it). */
    public const SNAPSHOT_LABEL = 'pre-restore-snapshot';
    /** Labelled backups kept by their own rules, outside the daily/weekly/monthly rotation. */
    private const ROTATION_EXEMPT_LABELS = [self::SNAPSHOT_LABEL, 'pre-migration', 'manual'];

    /** @var array */
    private static array $cfg = [];
    private static ?PDO $db = null;
    private static ?BackupStorage $storage = null;
    private static ?string $rootDir = null;
    /** Path to the live SQLite file backing the configured PDO; null if unknown / not local. */
    private static ?string $livePath = null;
    /** db_sqlite_path from config.php as written; SQLite reports the link-resolved file in PRAGMA database_list. */
    private static ?string $configuredPath = null;
    /** Descriptors (fd => [dev, ino, verified]) root's read-only connections opened in this process (rootForeignDatabase()),
     *  verified or refused, while still open; kept by reset(): SQLite keeps them open behind another connection's lock.
     *  R40: by descriptor, not inode (was "dev:ino" for the whole process: a deleted file's inode number, given to another
     *  file later, counted as refused). */
    private static array $rootOpened = [];
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
        self::$configuredPath = null;
        if ($config !== null) {
            $storageRoot = (string)($config['storage_dir'] ?? self::$rootDir . '/storage/backups');
            $storageClass = (string)($config['storage_adapter'] ?? 'LocalBackupStorage');
            self::$fileSources = (array)($config['files'] ?? []);
            self::$recoveryDir = (string)($config['recovery_dir'] ?? dirname($storageRoot) . '/restore-recovery');
        } else {
            $appCfg = require AppConfig::path(self::$rootDir);
            $storageRoot = (string)($appCfg['backup_storage_dir'] ?? dirname(self::$rootDir) . '/bareboneseshop-private/backups');
            $storageClass = (string)($appCfg['backup_storage_adapter'] ?? 'LocalBackupStorage');
            self::$cfg['log_file'] = (string)($appCfg['backup_log_file'] ?? dirname(self::$rootDir) . '/bareboneseshop-private/logs/backup.log');
            self::$configuredPath = (string)($appCfg['db_sqlite_path'] ?? '') ?: null;
            if (self::$cfg['include_files'] ?? true) {
                self::$fileSources = self::defaultFileSources($appCfg, self::$rootDir);
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
        self::$configuredPath = null;
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

    /**
     * The backup path restore() takes: relative to the backup storage, as list() prints it. An absolute path to a file
     * in a local backup storage is accepted as well and made relative; any other absolute path is refused by name.
     */
    public static function storagePath(string $given): string
    {
        if (!preg_match('~^(?:/|\\\\|[A-Za-z]:)~', $given)) return $given;
        $storage = self::storage();
        $root = $storage instanceof LocalBackupStorage ? realpath($storage->root()) : false;
        $file = realpath($given);
        $prefix = $root === false ? '' : rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        if ($root === false || $file === false || !is_file($file) || !str_starts_with($file, $prefix)) {
            throw new RuntimeException("{$given} is not a backup in the backup storage" . ($root === false ? '' : " ({$root})")
                . '; give the path as `php cli/restore.php --list` prints it, relative to the storage');
        }
        return str_replace('\\', '/', substr($file, strlen($prefix)));
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
            'retention'           => ['daily' => 7, 'weekly' => 4, 'monthly' => 6, 'minimum_kept' => 3, 'pre_migration' => 5, 'manual_max_age_days' => 180],
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
     * R38 (low): root keeps root for a tree only root can change (cli_drop_root), but the SQLite file is in the private
     * directory the web user writes. A symbolic link put at db_path there made root's VACUUM INTO copy any SQLite database
     * root can read into a backup. As root, the file the connection has open must be the regular, singly linked file at
     * $path (same inode through /proc/self/fd, so a link swapped back after connecting does not pass), reached through no
     * symbolic link another account owns. Null when fine or not root.
     * R39 (low): any open descriptor of that inode passed, and root holds others: the web user moved the .backup.lock it
     * had created (root opened it) to db_path after the connection was made through a link, and the other database was
     * copied (5 of 5). Root now copies from a read-only connection of its own ($verified), opened after the checks; the
     * descriptors that connection added must be of the inode checked, which must still be at $path afterwards.
     */
    private static function rootForeignDatabase(string $path, ?PDO &$verified = null): ?string
    {
        $verified = null;
        if (!self::runsAsRoot()) return null;
        // R40 (medium 2): the file and its directories were checked at different moments: the web user swapping its db/
        // directory with a link to another one made the file lstat() see the other database while the directory check saw
        // the real directory (2 of 50, 8 of 200 backups held the other database). The path is now resolved component by
        // component ($canonical, following only root's links), and the descriptors the connection opened must be that
        // path, its journal, WAL and shared memory by the kernel's own name for them (/proc/self/fd).
        $canonical = self::rootResolvedPath($path, $error);
        if ($canonical === null) return $error;
        $checked = static function () use ($path, $canonical): array|string {
            clearstatcache();
            if (self::rootResolvedPath($path, $error) !== $canonical) return $error ?? "the database {$path} moved";
            $file = @lstat($canonical);
            if (!is_array($file)) return "the database {$path} cannot be read";
            if (((int)$file['mode'] & 0170000) !== 0100000 || (int)$file['nlink'] !== 1) return "the database {$path} is not a regular file with a single link";
            return self::foreignSidecar($canonical, $file) ?? $file;
        };
        $file = $checked();
        if (is_string($file)) return $file;
        $proc = is_dir('/proc/self/fd');
        // Capability review run 527: without /proc the file the connection opened cannot be seen, and checks before and
        // after the open are the race R40 closed. Root then backs up only a database no other account can move or swap.
        if (!$proc && ($error = self::rootOnlyPath($canonical)) !== null) return $error;
        $descriptors = static function () use ($proc): array {
            if (!$proc) return [];
            $open = [];
            foreach (scandir('/proc/self/fd') ?: [] as $fd) {
                $s = ctype_digit((string)$fd) ? @stat('/proc/self/fd/' . $fd) : false;
                if (is_array($s)) $open[(int)$fd] = [(int)$s['dev'], (int)$s['ino'], (int)$s['mode'] & 0170000, (string)@readlink('/proc/self/fd/' . $fd), (int)$s['nlink'], (int)$s['uid']];
            }
            return $open;
        };
        $before = $descriptors();
        foreach (self::$rootOpened as $fd => [$dev, $ino]) {
            if (!isset($before[$fd]) || [$before[$fd][0], $before[$fd][1]] !== [$dev, $ino]) unset(self::$rootOpened[$fd]);
        }
        // Read-only: SQLite reuses the descriptor a closed connection left behind another connection's lock instead of
        // opening the file again, but only one of a connection opened the same way — here, an earlier one of ours.
        $sidecars = [$canonical . '-journal', $canonical . '-wal', $canonical . '-shm'];
        $added = [];
        $connection = new PDO('sqlite:' . $canonical, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        try {
            $connection->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn(); // the file is open from here on
        } finally {
            // Files the connection added; each but the journal, WAL and shared memory may stay open behind another
            // connection's lock (WAL mode holds one) and be reused by a later read-only open.
            $added = array_filter(array_diff_key($descriptors(), $before), static fn(array $d): bool => $d[2] === 0100000);
            foreach ($added as $fd => [$dev, $ino, , $name]) if (!in_array($name, $sidecars, true)) self::$rootOpened[$fd] = [$dev, $ino, false];
        }
        $main = null;
        foreach ($added as $fd => $d) {
            if ($d[3] === $canonical) $main = [$d[0], $d[1], $fd];
            elseif (!in_array($d[3], $sidecars, true)) return "the database open is not the file at {$path} ({$d[3]})";
            // R41 (low 10): the journal, WAL and shared memory root opened are the database owner's own single-link files
            // (a hard link put there after the check above made root write to the linked file).
            elseif ($d[4] !== 1 || !in_array($d[5], [0, (int)$file['uid']], true)) return "{$d[3]} is not a single-link file of the database's owner";
        }
        $after = $checked();
        if (is_string($after)) return $after;
        $inode = [(int)$after['dev'], (int)$after['ino']];
        // None added: SQLite reused a descriptor an earlier read-only connection of ours left, of the inode it found at the
        // path. It is of this file when one of this inode is open and none of another file that was refused (R40: was
        // refused for the rest of the process after any refusal; a refused file is closed with its connection unless
        // another one holds a lock on it). This file's own WAL refused while its directory was moved away is not another file.
        $now = $descriptors();
        $ofThisFile = false;
        $refusedOpen = false;
        foreach (self::$rootOpened as $fd => [$dev, $ino, $wasVerified]) {
            if (!isset($now[$fd]) || [$now[$fd][0], $now[$fd][1]] !== [$dev, $ino]) continue;
            if ([$dev, $ino] === $inode) $ofThisFile = true;
            elseif (!$wasVerified && $now[$fd][3] !== $canonical && !in_array($now[$fd][3], $sidecars, true)) $refusedOpen = true;
        }
        $reused = $main === null && $ofThisFile && !$refusedOpen;
        // Without /proc (a container without it) which file the connection opened cannot be seen; rootOnlyPath() above
        // ensured no other account can change the path or the file.
        $mainOpen = !$proc || $reused || ($main !== null && [$main[0], $main[1]] === $inode);
        if (!$mainOpen || [(int)$file['dev'], (int)$file['ino']] !== $inode) return "the database open is not the file at {$path}";
        if ($main !== null) self::$rootOpened[$main[2]][2] = true;
        $verified = $connection;
        return null;
    }

    private static function runsAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * R41 (low 10): the shop's own connection (Db::get()) as root switched the database at db_sqlite_path to WAL — a write —
     * before a backup refused it as another account's (a link swapped in). Why root may not open the SQLite database at
     * $path, or null (also when not root): a link of another account on the way, not a regular single-link file, or a
     * journal, WAL or shared memory next to it that is not (rootForeignDatabase() verifies the file opened afterwards).
     */
    public static function rootOpenRefusal(string $path): ?string
    {
        if (!self::runsAsRoot()) return null;
        clearstatcache();
        if (!file_exists($path) && !is_link($path)) {
            // A database root creates (install, seed): no link of another account leads to its directory.
            return self::rootResolvedPath(dirname($path), $error) === null ? $error : null;
        }
        $canonical = self::rootResolvedPath($path, $error);
        if ($canonical === null) return $error;
        $file = @lstat($canonical);
        if (!is_array($file) || ((int)$file['mode'] & 0170000) !== 0100000 || (int)$file['nlink'] !== 1) return "the database {$path} is not a regular file with a single link";
        return self::foreignSidecar($canonical, $file);
    }

    /**
     * R41 (low 10): the -journal, -wal and -shm root opens with the database (read and written by SQLite) must be regular
     * files with a single link of the database's owner or root; a hard or symbolic link there made root write to its target.
     * @param array<int|string, mixed> $file lstat() of the database
     */
    private static function foreignSidecar(string $canonical, array $file): ?string
    {
        foreach (['-journal', '-wal', '-shm'] as $suffix) {
            $s = @lstat($canonical . $suffix);
            if (!is_array($s)) continue;
            if (((int)$s['mode'] & 0170000) !== 0100000 || (int)$s['nlink'] !== 1) return "{$canonical}{$suffix} is not a regular file with a single link";
            if (!in_array((int)$s['uid'], [0, (int)$file['uid']], true)) return "{$canonical}{$suffix} belongs to another account than the database";
        }
        return null;
    }

    /**
     * Null when only root can change the link-free path $canonical: every directory on it belongs to root and is writable
     * by no other account, or is sticky and the entry in it belongs to root. Otherwise why a root backup is refused.
     */
    private static function rootOnlyPath(string $canonical): ?string
    {
        $path = '';
        $stickyParent = false;
        foreach (array_merge([''], array_values(array_filter(explode('/', $canonical), static fn(string $p): bool => $p !== ''))) as $part) {
            $path = $part === '' ? '/' : rtrim($path, '/') . '/' . $part;
            $stat = @lstat($path);
            if (!is_array($stat)) return "the database {$canonical} cannot be read";
            $mode = (int)$stat['mode'];
            $isDir = ($mode & 0170000) === 0040000;
            if ((int)$stat['uid'] !== 0 && ($stickyParent || $isDir)) {
                return "without /proc the file opened cannot be verified and {$path} belongs to another account";
            }
            if ($isDir && ($mode & 0022) !== 0 && ($mode & 01000) === 0) {
                return "without /proc the file opened cannot be verified and {$path} is writable by another account";
            }
            $stickyParent = $isDir && ($mode & 0022) !== 0;
        }
        return null;
    }

    /**
     * R40: $path with every symbolic link resolved by hand, one component at a time (lstat, never stat): only a link root
     * owns is followed; one of another account, or a link at the database itself, is refused ($error). The physical
     * path the kernel reports for a descriptor of that file (/proc/self/fd) when it was opened through no swapped link.
     */
    private static function rootResolvedPath(string $path, ?string &$error = null): ?string
    {
        $error = null;
        $pending = explode('/', str_starts_with($path, '/') ? $path : getcwd() . '/' . $path);
        $resolved = '';
        $links = 0;
        while ($pending !== []) {
            $part = array_shift($pending);
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                $resolved = $resolved === '' ? '' : rtrim(dirname($resolved), '/');
                continue;
            }
            $next = $resolved . '/' . $part;
            $stat = @lstat($next);
            if (!is_array($stat)) {
                $error = "the database {$path} cannot be read";
                return null;
            }
            if (((int)$stat['mode'] & 0170000) !== 0120000) {
                $resolved = $next;
                continue;
            }
            if ($pending === []) $error = "the database {$path} is a symbolic link";
            elseif ((int)$stat['uid'] !== 0) $error = "{$next} is a symbolic link of another account";
            elseif (++$links > 40) $error = "the database {$path} has too many symbolic links";
            if ($error !== null) return null;
            $target = (string)readlink($next);
            if (str_starts_with($target, '/')) $resolved = '';
            array_unshift($pending, ...explode('/', $target));
        }
        return $resolved === '' ? '/' : $resolved;
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
        // R29: on a file system without file locks LOCK_EX fails every write, and the throw below, raised from the CLI's own
        // catch of the failed backup ("cannot be locked …"), ended it with an uncaught fatal error instead of that message.
        // One line appended without the lock (O_APPEND) is not torn by a concurrent writer either.
        if (@file_put_contents($abs, $line, FILE_APPEND | LOCK_EX) === false && file_put_contents($abs, $line, FILE_APPEND) === false) {
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
            // 1. WAL checkpoint (best-effort). R40 (low): not as root: it wrote, before any check, to whatever database the
            // shop's connection had open (a link swapped in). Root's VACUUM INTO of the verified file includes the WAL anyway.
            if ($driver !== 'mysql' && !empty(self::$cfg['wal_checkpoint']) && !self::runsAsRoot()) {
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
                    $foreign = self::rootForeignDatabase(self::$configuredPath ?? (string)self::$livePath, $verified);
                    if ($foreign !== null) throw new RuntimeException("refused as root: {$foreign}. Run the backup as the account that owns the database");
                    $sqlPath = str_replace("'", "''", $absPath);
                    ($verified ?? self::$db)->exec("VACUUM INTO '{$sqlPath}'");
                    $verified = null;
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

            // 8. Optional GC — never from inside a restore: the backup being restored must not be collected under it.
            if (!empty(self::$cfg['auto_gc_on_backup']) && $label !== self::SNAPSHOT_LABEL) {
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
                        // R50: the jobs not done yet; completed jobs and the worker's lock are not worth restoring.
                        if ($name === 'jobs' && !str_contains($relativeOf($file), '/') && !($file->isDir() && in_array($file->getFilename(), ['pending', 'processing', 'failed'], true))) return false;
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
        // R27 (capability review run 397): refused before the snapshot when the restored file could not keep live's owner.
        $live = is_file(self::$livePath) ? @stat(self::$livePath) : false;
        $keep = is_array($live) ? FileOwnership::cannotKeep(self::$livePath, (int)$live['uid'], (int)$live['gid']) : null;
        if ($keep !== null) {
            throw new RuntimeException("Restore refused, nothing was changed: {$keep}");
        }

        // 2. pre-restore-snapshot (BEFORE acquiring restore lock — create() owns its own lock)
        $snapshotPath = null;
        if (!$skipSnapshot) {
            try {
                $snapshotPath = self::takeRestoreSnapshot($remotePath);
            } catch (\Throwable $e) {
                self::log('Restore: pre-snapshot failed: ' . $e->getMessage());
                throw new RuntimeException('Pre-restore snapshot failed: ' . $e->getMessage(), 0, $e);
            }
        }
        // Set when live may be gone or damaged: the snapshot is then the operator's copy and must stay.
        $liveAtRisk = false;
        try {

        $livePath = self::$livePath;
        if (!is_file($livePath)) {
            throw new RuntimeException("Restore: live database not found: {$livePath}");
        }
        $restoreTemp = $livePath . '.restore-' . bin2hex(random_bytes(6)) . '.tmp';
        // The restored file replaces live: keep live's mode, owner and group, or a restore run from the CLI leaves
        // www-data with a read-only database (only root can hand the file to another owner; a user only to a group it is
        // in, FileOwnership::cannotKeep() refused the rest above). The database directory
        // belongs to the web user, so the copy is created exclusively with live's mode and owned via lchown on that
        // inode (T7): chmod/chown by path followed a symlink swapped in by the web user (R23 G5: and a hard link).
        $liveOwner = @fileowner($livePath);
        $liveGroup = @filegroup($livePath);
        $restoreInode = self::stageExclusive($absSrc, $restoreTemp, fileperms($livePath) & 0666,
            $liveOwner === false ? null : $liveOwner, $liveGroup === false ? null : $liveGroup);
        if ($restoreInode === null
            || filesize($restoreTemp) !== $expectedSize
            || !hash_equals($expectedSha, (string)hash_file('sha256', $restoreTemp))) {
            throw new RuntimeException('Restore: failed to stage a complete verified replacement database');
        }

        $lockHandle = self::acquireLock();
        try {
            $liveWarnings = [];
            $liveSeries = self::liveNumberSeries(self::$db, $liveWarnings);
            // 3. close this process's connections to the live file; the gate proves no other process holds it
            self::$db = null;
            $sharedReleased = class_exists('Db', false) && Db::releaseSqliteFile($livePath);
            gc_collect_cycles();

            try {
                $swapped = SqliteMaintenance::exclusive($livePath, 'backup restore', static function () use ($livePath, &$restoreTemp, &$liveAtRisk, $maxRetries, $retryDelayMs): array {
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
                            $liveAtRisk = true;
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
                            $liveAtRisk = true;
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
            $numberingWarnings = [...$liveWarnings, ...self::continueNumberSeries($newDb, $liveSeries)];

            self::log("RESTORE OK src={$remotePath} snapshot=" . ($snapshotPath ?: '-') .
                " integrity={$integrity} applied={$appliedCount} pending={$pendingCount}");
            foreach ($numberingWarnings as $warning) self::log('RESTORE WARNING ' . $warning);

            return [
                'restored'        => $remotePath,
                'snapshot'        => $snapshotPath,
                'integrity'       => $integrity,
                'schema_warnings' => $schemaWarnings,
                'numbering_warnings' => $numberingWarnings,
                'applied_count'   => $appliedCount,
                'pending_count'   => $pendingCount,
                'meta'            => $meta,
            ];
        } finally {
            self::releaseLock($lockHandle);
        }
        } catch (\Throwable $e) {
            // Refused or rolled back: live is the database the snapshot copied, so the snapshot is no restore
            // point and must not push real backups out of the newest slots.
            if (!$liveAtRisk) self::discardSnapshot($snapshotPath);
            throw $e;
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

    private const NUMBER_SERIES = [
        'invoice_number_counters' => ['store_id', 'type', 'year', 'prefix'],
        'invoice_counters' => ['year', 'prefix'],
    ];

    /** R50: last issued number per document series of $db, by counter table (null: the table is missing). */
    private static function numberSeries(PDO $db, ?array $tables = null): array
    {
        $series = [];
        foreach ($tables ?? self::NUMBER_SERIES as $table => $columns) {
            Db::forgetSchema($db);
            if (!Db::tableExists($table, $db)) {
                $series[$table] = null;
                continue;
            }
            $rows = $db->query('SELECT ' . implode(', ', $columns) . ", last_number FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
            $series[$table] = [];
            foreach ($rows as $row) {
                $key = implode("\x1f", array_map(static fn(string $c): string => (string)$row[$c], $columns));
                $series[$table][$key] = max((int)$row['last_number'], $series[$table][$key] ?? 0);
            }
        }
        return $series;
    }

    /**
     * R54 (review round 19, low): the live database a restore replaces is often the damaged one. A counter table it cannot
     * read no longer stops the restore (it did since R50): the operator is warned that the numbers were not carried over.
     */
    private static function liveNumberSeries(PDO $db, array &$warnings): array
    {
        // R55 (review round 20, low): table by table, so one unreadable counter table no longer drops the series the others
        // hold; a lock (SQLITE_BUSY, a MySQL lock wait) is waited out and, if it stays, stops the restore before anything is
        // replaced. Was: any error dropped every series with a warning, and invoice numbers without their PDF were reused.
        $series = [];
        foreach (self::NUMBER_SERIES as $table => $columns) {
            for ($attempt = 1; ; $attempt++) {
                try {
                    $series += self::numberSeries($db, [$table => $columns]);
                    break;
                } catch (\Throwable $e) {
                    if (preg_match('/database is (?:locked|busy)|SQLITE_BUSY|Lock wait timeout|Deadlock found/i', $e->getMessage()) === 1) {
                        if ($attempt < self::SERIES_READ_ATTEMPTS) {
                            usleep(250000 * $attempt);
                            continue;
                        }
                        throw new RuntimeException('Restore: the live database stays locked (' . $e->getMessage() . ') while its document number series '
                            . 'are read; nothing was restored. Stop the worker, cron jobs and web traffic, then run the restore again.', 0, $e);
                    }
                    $warnings[] = "The number series of {$table} in the live database could not be read (" . $e->getMessage() . '), so the restored database '
                        . 'numbers those documents from its own counters. Check the last numbers issued before issuing new ones.';
                    break;
                }
            }
        }
        return $series;
    }

    private const SERIES_READ_ATTEMPTS = 4;

    /**
     * R50: an older backup brings back lower number counters, so the next document took a number already issued (and
     * its PDF overwrote the other customer's). Every series of the replaced database continues after the higher of the
     * two last numbers. Returns the warnings for the operator: the numbers whose documents the backup does not have.
     */
    private static function continueNumberSeries(PDO $db, array $live): array
    {
        $restored = self::numberSeries($db);
        // An older schema numbers from the legacy per-(year, prefix) table only; its migration carries that over.
        if ($restored['invoice_number_counters'] === null && $restored['invoice_counters'] !== null) {
            foreach ($live['invoice_number_counters'] ?? [] as $key => $last) {
                [, , $year, $prefix] = explode("\x1f", (string)$key);
                $legacy = $year . "\x1f" . $prefix;
                $live['invoice_counters'][$legacy] = max($last, $live['invoice_counters'][$legacy] ?? 0);
            }
        }
        $warnings = [];
        foreach (self::NUMBER_SERIES as $table => $columns) {
            if ($restored[$table] === null) continue;
            $where = implode(' AND ', array_map(static fn(string $c): string => "{$c} = ?", $columns));
            foreach ($live[$table] ?? [] as $key => $last) {
                $had = $restored[$table][$key] ?? null;
                if ($had !== null && $had >= $last) continue;
                $values = explode("\x1f", (string)$key);
                if ($had === null) {
                    $db->prepare("INSERT INTO {$table} (" . implode(', ', $columns) . ', last_number) VALUES (' . str_repeat('?, ', count($columns)) . '?)')
                        ->execute([...$values, $last]);
                } else {
                    $db->prepare("UPDATE {$table} SET last_number = ? WHERE {$where}")->execute([$last, ...$values]);
                }
                // The legacy table only shadows the per-store series when both exist: one warning per series.
                if ($table === 'invoice_counters' && $restored['invoice_number_counters'] !== null) continue;
                $named = array_combine($columns, $values);
                $series = $named['prefix'] . $named['year'] . (isset($named['store_id']) ? " ({$named['store_id']} {$named['type']})" : '');
                $first = ($had ?? 0) + 1;
                $warnings[] = sprintf('Number series %s: %s issued after this backup and %s not in the restored database (the PDFs stay in '
                    . 'the invoice storage); numbering continues at %d.', $series, $first === $last ? "number {$last} was" : "numbers {$first}–{$last} were",
                    $first === $last ? 'its document is' : 'their documents are', $last + 1);
            }
        }
        return $warnings;
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
                $snapshotPath = self::takeRestoreSnapshot($remotePath);
                self::downloadVerified($snapshotPath, $rollback);
                @chmod($rollback, 0600);
            } catch (\Throwable $e) {
                self::discardSnapshot($snapshotPath);
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
                $liveWarnings = [];
                $liveSeries = self::liveNumberSeries(self::$db, $liveWarnings);
                $result = $mysql->restoreBackupDump($source, self::$mysqlScratchDatabase, $rollback, $schema, $ownSessions);
            } catch (\Throwable $e) {
                if ($e->getCode() === MysqlUpdateDatabaseBackup::RESTORE_FAILED_AFTER_DROP) {
                    // Live was dropped: whatever happened next, the pre-restore dump stays where the operator can use it.
                    $keepRollback = true;
                    $e = new RuntimeException($e->getMessage() . " (pre-restore dump kept at {$rollback}; also stored as backup {$snapshotPath})",
                        MysqlUpdateDatabaseBackup::RESTORE_FAILED_AFTER_DROP, $e);
                } else {
                    // Refused before live was dropped: live is what the snapshot copied, no restore point.
                    self::discardSnapshot($snapshotPath);
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
            $numberingWarnings = [...$liveWarnings, ...self::continueNumberSeries(self::$db, $liveSeries)];
            self::log("RESTORE OK driver=mysql src={$remotePath} snapshot=" . ($snapshotPath ?: '-')
                . " tables={$result['tables']} rows={$result['rows']} applied={$appliedCount} pending={$pendingCount}");
            foreach ($numberingWarnings as $warning) self::log('RESTORE WARNING ' . $warning);

            return [
                'restored'        => $remotePath,
                'snapshot'        => $snapshotPath,
                'integrity'       => 'ok (scratch restore matched the dump row-for-row; live matches scratch)',
                'schema_warnings' => $schemaWarnings,
                'numbering_warnings' => $numberingWarnings,
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
     * unless force => true (cli/restore.php --force). Files absent from the backup are kept. Scripts and dotfiles of
     * themes/ and uploads/ go to the private recovery directory, never into the web root.
     *
     * Returns ['archive', 'written', 'unchanged', 'overwritten', 'set_aside', 'quarantined' => entries, 'skipped_sources' => names,
     * 'aside_dir' => this restore's directory of the copies set aside or quarantined (null: none)].
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
        $asideRun = null;
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
            $setAside = [];
            $quarantined = [];
            $jobsRan = null;
            foreach ($entries as $entry) {
                [$name, $rest] = explode('/', $entry, 2) + [1 => ''];
                $root = (string)(self::$fileSources[$name] ?? '');
                if ($root === '') {
                    $skippedSources[$name] = true;
                    continue;
                }
                $kind = self::sourceKind($name, $root, (string)($kinds[$name] ?? ''));
                if ($kind === 'file' && $rest !== '' && !str_contains($rest, '/')) {
                    [$target, $base] = [$root, dirname($root)];
                } elseif ($kind === 'directory' && $rest !== '') {
                    [$target, $base] = [rtrim($root, '/\\') . '/' . $rest, $root];
                } else {
                    throw new RuntimeException("Restore: files archive entry {$entry} does not fit source {$name} ({$kind})");
                }
                $source = $stagingDir . '/' . $entry;
                $isAside = $isQuarantined = false;
                // R53: a script or a per-directory server config (.htaccess, .user.ini) never goes into a directory the web
                // server serves, not even a missing one: a forged backup's themes/default/assets/shell.php ran after --files-only.
                // R55 (review round 20, low): uploads hold images and documents; a script, page or SVG of a forged backup (a
                // script a forged legal text loads, allowed by CSP 'self') is quarantined like a server script.
                if (in_array($name, self::WEB_SOURCES, true) && (self::runnableInWebRoot($rest) || ($name === 'uploads' && self::activeInBrowser($rest)))
                    && !(is_file($target) && !is_link($target) && hash_equals((string)hash_file('sha256', $source), (string)hash_file('sha256', $target)))) {
                    $asideDir = $asideRun ??= self::createAsideRun();
                    [$target, $base] = [$asideDir . '/' . $name . '/' . $rest, $asideDir];
                    $isAside = $isQuarantined = true;
                } elseif (in_array($name, self::ASIDE_SOURCES, true) && !is_link($target)) {
                    $differs = is_file($target) && !hash_equals((string)hash_file('sha256', $source), (string)hash_file('sha256', $target));
                    if ($name === 'jobs' && !is_file($target)) $jobsRan ??= self::holdsAnyFile($root);
                    // R54 (review round 19, low): a theme file the live themes lack is set aside too: a forged backup's child
                    // theme checkout.html with its own script became the checkout page (a card skimmer).
                    if ($differs || ($name === 'jobs' && !is_file($target) && $jobsRan) || ($name === 'themes' && !is_file($target))) {
                        // R51: in the private recovery directory, never next to the live source: config.php.from-backup
                        // and themes.from-backup/ in the web root were served (database password, gateway keys).
                        $asideDir = $asideRun ??= self::createAsideRun();
                        $target = $kind === 'file' ? $asideDir . '/' . basename($root) : $asideDir . '/' . $name . '/' . $rest;
                        $base = $asideDir;
                        $isAside = true;
                    }
                } elseif ($name === 'legal' && !is_link($target) && !file_exists($target)) {
                    // R55 (review round 20, low): a legal text the live shop lacks is set aside too: it is rendered unescaped
                    // ({{{content_html}}}), and a forged backup's terms.html loaded a script. Was: written in place, unreported.
                    $asideDir = $asideRun ??= self::createAsideRun();
                    [$target, $base] = [$asideDir . '/' . $name . '/' . $rest, $asideDir];
                    $isAside = true;
                }
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
                    // A copy set aside by an earlier restore is replaced; it is not the shop's live file.
                    if (!$isAside) $conflicts[] = $entry;
                    $overwrite = true;
                }
                if (!is_writable($directory['existing'])) {
                    $refused[] = "{$entry} ({$directory['existing']} is not writable by this user)";
                    continue;
                }
                $ownership = self::restoredFileOwnership($target, $directory['existing'], match ($name) {
                    'gdpr_salt', 'stores', 'config' => 0640, 'mail', 'jobs' => 0600, default => 0666,
                });
                // R53 (review round 53, low): a copy set aside holds secrets (config.php) or quarantined scripts; it and the
                // directories created for it are the operator's only (0600/0700), not modelled on the directory above.
                if ($isAside) [$ownership['mode'], $ownership['dir_mode']] = [0600, 0700];
                // R27 (run 397): a file or directory this user cannot give the owner and group it must have is refused.
                $keep = $ownership['uid'] !== null && $ownership['gid'] !== null ? FileOwnership::cannotKeep($target, $ownership['uid'], $ownership['gid']) : null;
                if ($keep === null && $directory['create'] !== [] && $ownership['dir_uid'] !== null && $ownership['dir_gid'] !== null) {
                    $keep = FileOwnership::cannotKeep($directory['create'][0], $ownership['dir_uid'], $ownership['dir_gid']);
                }
                if ($keep !== null) {
                    $refused[] = "{$entry} ({$keep})";
                    continue;
                }
                $plan[] = [$entry, $source, $target, $base, $ownership, $overwrite];
                if ($isQuarantined) $quarantined[] = $entry;
                elseif ($isAside) $setAside[] = $entry;
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
            $writtenAside = [];
            foreach ($plan as [$entry, $source, $target, $base, $ownership, $overwrite]) {
                try {
                    if ($base === $asideRun) self::assertAsideRun($asideRun);
                    self::installRestoredFile($source, $target, $base, $ownership);
                } catch (\Throwable $e) {
                    // R55 (review round 20, low): the copies set aside are named as such. Was: listed as written in place.
                    $message = 'Restore: files restore stopped at ' . $entry . ': ' . preg_replace('/^Restore: /', '', $e->getMessage()) . '. '
                        . ($written === [] ? 'No file was written in place.' : count($written) . ' file(s) were already written in place: ' . implode(', ', $written) . '.')
                        . ($writtenAside === [] ? '' : ' ' . count($writtenAside) . ' copy(ies) were set aside in ' . $asideRun . ': ' . implode(', ', $writtenAside) . '.');
                    self::log('RESTORE FILES FAIL src=' . $archivePath . ' ' . $message);
                    throw new RuntimeException($message, 0, $e);
                }
                if ($base === $asideRun) $writtenAside[] = $entry;
                else $written[] = $entry;
                if ($overwrite) $overwritten[] = $entry;
            }
            if ($asideRun !== null) self::pruneAsideRuns($asideRun);
            self::log("RESTORE FILES OK src={$archivePath} written=" . count($plan) . " unchanged={$unchanged} overwritten=" . count($overwritten)
                . ($skippedSources !== [] ? ' skipped_sources=' . implode(',', array_keys($skippedSources)) : '')
                . ($setAside !== [] ? ' set_aside=' . count($setAside) : '')
                . ($quarantined !== [] ? ' quarantined=' . count($quarantined) : ''));
            return [
                'archive'         => $archivePath,
                'written'         => count($plan),
                'unchanged'       => $unchanged,
                'overwritten'     => $overwritten,
                'set_aside'       => $setAside,
                'quarantined'     => $quarantined,
                'skipped_sources' => array_keys($skippedSources),
                'aside_dir'       => $setAside !== [] || $quarantined !== [] ? $asideRun : null,
            ];
        } finally {
            // A refused restore leaves no empty directory of its own behind.
            if ($asideRun !== null) @rmdir($asideRun);
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
    private static function restoredFileOwnership(string $target, string $directory, int $newFileMask): array
    {
        $file = is_file($target) ? @stat($target) : false;
        $dir = @stat($directory);
        $dirMode = is_array($dir) ? $dir['mode'] & 07777 : 0750;
        // A new file of a secret ($newFileMask 0640, mail.json 0600) never gets more than that from its directory.
        $mode = is_array($file) ? $file['mode'] & 07777 : ($dirMode & 0666 & $newFileMask);
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

    /**
     * Owner and group that differ are applied with lchown/lchgrp on the inode created by this restore ($ino from lstat):
     * the directory belongs to the web user, who could swap the path for a symlink or hard link to /etc/shadow between
     * two calls. chown followed it (T7). R27 (run 397): not only as root (a step started as root no longer runs as root);
     * what a user cannot set fails instead of being skipped, FileOwnership::cannotKeep() refused it before any write.
     */
    private static function applyOwnership(string $path, ?int $uid, ?int $gid, ?int $ino = null): void
    {
        if (!function_exists('posix_geteuid')) return;
        self::assertSameInode($path, $ino);
        [$uid, $gid] = self::ownershipChange((array)@lstat($path), $uid, $gid);
        if ($uid === null && $gid === null) return;
        if ($uid !== null && !@lchown($path, $uid)) {
            throw new RuntimeException("Restore: cannot set the owner of {$path}");
        }
        if ($gid !== null && !@lchgrp($path, $gid)) {
            throw new RuntimeException("Restore: cannot set the group of {$path}");
        }
        self::assertSameInode($path, $ino);
    }

    /** $path is still the file or directory this restore created (not a link, not another inode). */
    private static function assertSameInode(string $path, ?int $ino): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $type = $stat === false ? 0 : $stat['mode'] & 0170000;
        // A directory cannot be hard-linked; a regular file with a second link is somebody else's file.
        if (!in_array($type, [0040000, 0100000], true) || ($ino !== null && (int)$stat['ino'] !== $ino) || ($type === 0100000 && (int)$stat['nlink'] !== 1)) {
            throw new RuntimeException("Restore: {$path} was replaced while it was being restored; refusing");
        }
    }

    /**
     * Files a backup archives next to the database, from config.php. T8: the merchant's stores and sellers, legal
     * texts and sold e-book files are not in the database either; without them a restored shop had no stores, the
     * template legal texts and no downloads. stores.json and the legal texts live where DataOverrides reads them
     * (the data directory first).
     * @return array<string, string>
     */
    public static function defaultFileSources(array $appCfg, string $rootDir): array
    {
        $private = dirname($rootDir) . '/bareboneseshop-private';
        $runtime = rtrim(str_replace('\\', '/', (string)($appCfg['private_runtime_dir'] ?? '')), '/');
        $legacyStores = $rootDir . '/stores.json';
        return [
            'invoices'  => (string)($appCfg['invoice_storage_dir'] ?? $private . '/invoices'),
            'uploads'   => (string)($appCfg['uploads_dir'] ?? $rootDir . '/uploads'),
            'gdpr_salt' => (string)($appCfg['gdpr_salt_file'] ?? $private . '/secrets/gdpr_salt'),
            'stores'    => $runtime !== '' && (is_file($runtime . '/stores.json') || !is_file($legacyStores)) ? $runtime . '/stores.json' : $legacyStores,
            'legal'     => $runtime !== '' ? $runtime . '/legal' : '',
            'downloads' => (string)($appCfg['digital_assets_base_dir'] ?? $private . '/downloads'),
            // R28: the e-mail delivery settings (Admin → E-mail delivery); a restored shop logged its mail instead of sending it.
            'mail'      => $runtime !== '' ? $runtime . '/' . MailSettings::FILE : '',
            // R50: config.php (database, payment gateway and API secrets), the file job queue (mails and invoices not
            // sent yet) and the themes the merchant created or edited were in no backup.
            'config'    => AppConfig::path($rootDir),
            'jobs'      => (string)($appCfg['jobs_dir'] ?? $private . '/jobs'),
            'themes'    => $rootDir . '/themes',
        ];
    }

    /**
     * R50: sources restored next to the live ones (<source>.from-backup) instead of over them: an older config.php
     * would point the shop at another database, a theme file the release changed would go back to the old version,
     * and a queue that has already run would send its mails again. Only missing files are restored in place.
     */
    private const ASIDE_SOURCES = ['config', 'themes', 'jobs'];

    /** The default sources that are one file (defaultFileSources()); the others are directories. */
    private const FILE_SOURCES = ['gdpr_salt', 'stores', 'mail', 'config'];
    private const DIRECTORY_SOURCES = ['invoices', 'uploads', 'legal', 'downloads', 'jobs', 'themes'];

    /**
     * R53 (review round 53, low): whether a restored source is one file or a directory is this shop's, not the backup
     * metadata's (which whoever can write the backup storage writes too): a default source by its name, another by its
     * configured path here; only a source that exists in neither form goes by the metadata.
     */
    private static function sourceKind(string $name, string $root, string $recorded): string
    {
        if (in_array($name, self::FILE_SOURCES, true)) return 'file';
        if (in_array($name, self::DIRECTORY_SOURCES, true)) return 'directory';
        if (is_dir($root)) return 'directory';
        if (is_file($root)) return 'file';
        return $recorded;
    }

    /** R53: sources the web server serves directly. */
    private const WEB_SOURCES = ['themes', 'uploads'];

    /**
     * Whether a file at $relative inside a web-served source could be run or could reconfigure the server: a dotfile
     * or a file in a dot-directory (.htaccess, .user.ini), or a script name, also as an inner extension (shell.php.jpg
     * runs where Apache maps handlers by any extension). Neither the shop's themes nor its uploads hold such a file.
     */
    private static function runnableInWebRoot(string $relative): bool
    {
        foreach (explode('/', $relative) as $segment) {
            if (str_starts_with($segment, '.')) return true;
        }
        return preg_match('/\.(?:php[0-9]*|phps|phtml?|phar|inc|cgi|fcgi|pl|py|rb|sh|shtml|asp|aspx|jsp)(?:\.|$)/i', basename($relative)) === 1;
    }

    /**
     * R55: a file of uploads/ a browser could run or render as active content from the shop's origin. R56 (review round
     * 21, low): an allowlist of the images, documents and media uploads hold, not a list of active types: Apache served
     * .es, .ecma, .dat or a name without an extension with a type it sniffed (no nosniff outside its FilesMatch list),
     * and CSP 'self' let a forged legal text load it as a script.
     */
    private static function activeInBrowser(string $relative): bool
    {
        return preg_match('/^[^.][^\/]*\.(?:jpe?g|png|gif|webp|avif|ico|bmp|pdf|mp4|webm|mp3|ogg)$/i', basename($relative)) !== 1
            || preg_match('/\.(?:m?js|cjs|es|ecma|wasm|x?html?|xht|svgz?|xml|xsl|css|swf)\./i', basename($relative)) === 1;
    }

    /** R51: where restored copies are set aside — <backup_recovery_dir>/from-backup, private (0700), outside the web root. */
    public static function asideDirectory(): string
    {
        $dir = self::$recoveryDir;
        if ($dir === '') {
            throw new RuntimeException('BackupService: no private recovery directory configured for the copies set aside');
        }
        self::assertPrivateAbsolutePath($dir, 'backup_recovery_dir');
        return rtrim(str_replace('\\', '/', $dir), '/') . '/from-backup';
    }

    /**
     * R54 (review round 19, medium 1): every restore sets its copies aside in a directory of its own, from-backup/<time>-<random>,
     * created exclusively (mkdir fails on an existing name or link) and 0700. Only backup_recovery_dir was checked: whoever
     * can write it (the web user through the setgid group of a split deployment) replaced from-backup with a symlink to the
     * application root, and --files-only --yes wrote config.php over the live one and shell.php and .htaccess into the web
     * root while reporting them set aside. Neither from-backup nor the new directory may be a link, and the result must
     * resolve below the recovery directory.
     */
    private static function createAsideRun(): string
    {
        $root = self::asideDirectory();
        $recovery = dirname($root);
        if (is_link($recovery) || (!is_dir($recovery) && !@mkdir($recovery, 0700, true) && !is_dir($recovery))) {
            throw new RuntimeException("Restore: cannot use the private recovery directory {$recovery}");
        }
        clearstatcache(true, $root);
        $stat = @lstat($root);
        if ($stat === false) {
            if (!self::withMode(0700, static fn(): bool => @mkdir($root, 0700))) {
                throw new RuntimeException("Restore: cannot create {$root}");
            }
        } elseif (($stat['mode'] & 0170000) !== 0040000) {
            throw new RuntimeException("Restore: {$root} is a symlink or not a directory; refusing to set copies aside through it");
        }
        // R54 (capability review): a real from-backup others can list and change still let them read the new run's name and
        // swap the run for a symlink to the application root between the check and the write. Only a private one is used.
        self::assertPrivateDirectory($root);
        $run = $root . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        if (!self::withMode(0700, static fn(): bool => @mkdir($run, 0700))) {
            throw new RuntimeException("Restore: cannot create {$run}");
        }
        self::assertAsideRun($run);
        return $run;
    }

    /** R55: copies set aside by the newest restores are kept; older runs (each with a config.php) are removed. */
    private const ASIDE_RUNS_KEPT = 5;

    /**
     * R55 (review round 20, low): every restore left another copy of config.php (database password, gateway keys) in
     * from-backup and nothing removed them. The newest ASIDE_RUNS_KEPT runs stay (always $current); older run directories
     * (by their time-stamped name; only real directories, never through a link) are removed.
     */
    private static function pruneAsideRuns(string $current): void
    {
        $root = dirname($current);
        $runs = [];
        foreach (@scandir($root) ?: [] as $entry) {
            if (!preg_match('/^\d{8}-\d{6}-[0-9a-f]{12}$/D', $entry)) continue;
            $stat = @lstat($root . '/' . $entry);
            if ($stat !== false && ($stat['mode'] & 0170000) === 0040000) $runs[] = $entry;
        }
        rsort($runs, SORT_STRING);
        foreach (array_slice(array_values(array_diff($runs, [basename($current)])), self::ASIDE_RUNS_KEPT - 1) as $old) {
            self::removeTree($root . '/' . $old);
            self::log("Restore: removed the copies set aside by an older restore, {$root}/{$old}");
        }
    }

    /** $run and from-backup are still real directories (no link swapped in since) resolving below the recovery directory. */
    private static function assertAsideRun(string $run): void
    {
        $root = dirname($run);
        foreach ([$root, $run] as $dir) {
            clearstatcache(true, $dir);
            $stat = @lstat($dir);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
                throw new RuntimeException("Restore: {$dir} is a symlink or not a directory; refusing to set copies aside through it");
            }
            self::assertPrivateDirectory($dir);
        }
        $realRecovery = realpath(dirname($root));
        $realRun = realpath($run);
        if ($realRecovery === false || $realRun === false
            || str_replace('\\', '/', $realRun) !== rtrim(str_replace('\\', '/', $realRecovery), '/') . '/' . basename($root) . '/' . basename($run)) {
            throw new RuntimeException("Restore: {$run} does not resolve inside the private recovery directory; refusing");
        }
    }

    /** $dir (lstat) belongs to this process's user and gives group and others no access: nobody else can rename its entries. */
    private static function assertPrivateDirectory(string $dir): void
    {
        if (PHP_OS_FAMILY === 'Windows') return;
        clearstatcache(true, $dir);
        $stat = @lstat($dir);
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($stat === false || ($owner !== null && (int)$stat['uid'] !== $owner) || ((int)$stat['mode'] & 0077) !== 0) {
            throw new RuntimeException("Restore: {$dir} is not private (it must belong to this user with mode 0700); "
                . 'move it away and run the restore again');
        }
    }

    /** Whether directory $dir holds any file (a job queue that has already run). */
    private static function holdsAnyFile(string $dir): bool
    {
        if (is_link($dir) || !is_dir($dir)) return false;
        $entries = @scandir($dir);
        // An unreadable queue counts as one that has run: its jobs are set aside, never sent twice.
        if ($entries === false) return true;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_file($path) || self::holdsAnyFile($path)) return true;
        }
        return false;
    }

    /**
     * Copy $source to $temporary created exclusively ('x': never through an existing name or link) with its final
     * $mode; returns the new file's inode, null on failure. Run as root, the copy gets $uid/$gid while it is still
     * open (R23 G5: FileOwnership, never by a name the web user can re-link), and is checked to be the same inode.
     */
    private static function stageExclusive(string $source, string $temporary, int $mode, ?int $uid = null, ?int $gid = null): ?int
    {
        $handedOver = false;
        $inode = self::withMode($mode, static function () use ($source, $temporary, $uid, $gid, &$handedOver): ?int {
            $in = @fopen($source, 'rb');
            $out = $in ? @fopen($temporary, 'xb') : false;
            try {
                $copied = $in && $out && stream_copy_to_stream($in, $out) !== false && fflush($out);
                $stat = $copied ? fstat($out) : false;
                // R27 (run 397): not only as root; a user gives its file a group it is in, anything else fails here (not silently).
                [$uid, $gid] = is_array($stat) && function_exists('posix_geteuid') ? self::ownershipChange($stat, $uid, $gid) : [null, null];
                if ($uid !== null || $gid !== null) {
                    FileOwnership::handOver($out, "Restore: {$temporary}", $uid, $gid);
                    $handedOver = true;
                }
                return is_array($stat) ? (int)$stat['ino'] : null;
            } catch (\Throwable $e) {
                @unlink($temporary);
                throw $e;
            } finally {
                if ($in) fclose($in);
                if ($out) fclose($out);
            }
        });
        if ($inode !== null && $handedOver) {
            self::assertSameInode($temporary, $inode);
        }
        return $inode;
    }

    /** The owner and group (null: unchanged) a new file with $stat still needs to become $uid/$gid. */
    private static function ownershipChange(array $stat, ?int $uid, ?int $gid): array
    {
        return [$uid !== null && $uid !== (int)$stat['uid'] ? $uid : null, $gid !== null && $gid !== (int)$stat['gid'] ? $gid : null];
    }

    /** Run $create with the umask that gives exactly $mode, so no chmod by path (which follows links) is needed. */
    private static function withMode(int $mode, Closure $create): mixed
    {
        $previous = umask(~$mode & 0777);
        try {
            return $create();
        } finally {
            umask($previous);
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
            if (!self::withMode($ownership['dir_mode'], static fn(): bool => @mkdir($dir, $ownership['dir_mode'])) || is_link($dir) || !is_dir($dir)) {
                throw new RuntimeException("Restore: cannot create {$dir}");
            }
            $stat = @lstat($dir);
            self::applyOwnership($dir, $ownership['dir_uid'], $ownership['dir_gid'], is_array($stat) ? (int)$stat['ino'] : null);
        }
        $parent = dirname($target);
        $realBase = realpath($base);
        $realDirectory = realpath($parent);
        if ($realBase === false || $realDirectory === false
            || ($realDirectory !== $realBase && !str_starts_with($realDirectory, rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException("Restore: {$target} resolves outside its configured directory; refusing");
        }
        $temporary = $parent . DIRECTORY_SEPARATOR . '.' . basename($target) . '.restore-' . bin2hex(random_bytes(6)) . '.tmp';
        $inode = self::stageExclusive($source, $temporary, $ownership['mode'] & 0666, $ownership['uid'], $ownership['gid']);
        if ($inode === null) {
            @unlink($temporary);
            throw new RuntimeException("Restore: cannot stage {$target}");
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

    /** Store a copy of live before a restore from $sourcePath; returns its remote path. */
    private static function takeRestoreSnapshot(string $sourcePath): string
    {
        $snap = self::create(['label' => self::SNAPSHOT_LABEL, 'trigger' => 'auto']);
        $snapshotPath = (string)($snap['remote_path'] ?? '');
        self::pruneSnapshots(5, $sourcePath);
        return $snapshotPath;
    }

    /** Remove the snapshot (and its .gz copy) of an attempt that left live as it was. */
    private static function discardSnapshot(?string $snapshotPath): void
    {
        if ($snapshotPath === null || $snapshotPath === '') return;
        foreach ([$snapshotPath, $snapshotPath . '.gz'] as $path) {
            $row = self::storedRow($path);
            if ($row === null) continue;
            try {
                self::deleteBackup($row);
            } catch (\Throwable $e) {
                self::log("Restore: cannot discard the snapshot {$path} of a refused restore: " . $e->getMessage());
            }
        }
        self::log("Restore: refused or rolled back, pre-restore snapshot {$snapshotPath} discarded");
    }

    /**
     * Keep only the newest `$keep` pre-restore snapshots; delete the rest — never $protect, the backup
     * a restore is reading right now (a snapshot can itself be restored).
     */
    private static function pruneSnapshots(int $keep, ?string $protect = null): void
    {
        $snaps = array_values(array_filter(self::list(), static fn(array $row): bool =>
            (string)($row['meta']['label'] ?? '') === self::SNAPSHOT_LABEL && !self::isCompressedCopy($row)));
        // already newest-first
        for ($i = $keep; $i < count($snaps); $i++) {
            $path = (string)$snaps[$i]['path'];
            if ($protect !== null && ($path === $protect || $path . '.gz' === $protect)) continue;
            foreach ([$snaps[$i], self::storedRow($path . '.gz')] as $row) {
                if ($row === null) continue;
                try {
                    self::deleteBackup($row);
                } catch (\Throwable $e) {
                    self::log('Restore: snapshot prune failed ' . $row['path'] . ': ' . $e->getMessage());
                }
            }
        }
    }

    /** The newest backup to restore: never a .gz companion and never a pre-restore snapshot (a copy of a past live). */
    public static function latestRestorePoint(): ?array
    {
        foreach (self::list() as $row) {
            if (!self::isCompressedCopy($row) && (string)($row['meta']['label'] ?? '') !== self::SNAPSHOT_LABEL) return $row;
        }
        return null;
    }

    // ---------- list() / gc() ----------

    public static function list(): array
    {
        // Files archives are companions of a database backup, not restore points of their own. R53 (review round 53,
        // low): their SHA-256 and size go with the backup row (files_sha256, files_size): the runbook's own SHA-256 list
        // must cover the files archive too (config.php, themes), which the restore verifies the same way.
        $archives = [];
        $rows = [];
        foreach (self::$storage->list() as $row) {
            if (str_ends_with((string)($row['path'] ?? ''), '.files.tar')) $archives[(string)$row['path']] = $row;
            else $rows[] = $row;
        }
        foreach ($rows as $i => $row) {
            $archive = $archives[(string)($row['meta']['files_archive'] ?? '')] ?? null;
            if ($archive === null) continue;
            $rows[$i]['files_sha256'] = (string)($archive['meta']['sha256'] ?? '');
            $rows[$i]['files_size'] = (int)($archive['size'] ?? 0);
        }
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

        // The rotation counts restore points only: pre-restore snapshots, pre-migration and manual backups are kept
        // by their own rules below and must not take the newest slots from scheduled backups.
        $rotation = array_values(array_filter($all, static fn(array $row): bool =>
            !in_array((string)($row['meta']['label'] ?? ''), self::ROTATION_EXEMPT_LABELS, true)));

        // Always-keep set: paths preserved no matter what.
        $keep = [];
        // Floor: newest $minKept entries always kept
        foreach (array_slice($rotation, 0, $minKept) as $row) {
            $keep[$row['path']] = true;
        }

        // Group by day (YYYY-MM-DD) — keep newest per day, last $daily days
        $byDay = [];
        foreach ($rotation as $row) {
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
        foreach ($rotation as $row) {
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
        foreach ($rotation as $row) {
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

        // Labelled backups outside the rotation: pre-restore snapshots (pruned by restore itself), the newest
        // `pre_migration` pre-migration backups and manual backups, which expire only past `manual_max_age_days`.
        $preMigrationKeep = max(0, (int)($cfg['pre_migration'] ?? 5));
        $manualMaxAge = max(0, (int)($cfg['manual_max_age_days'] ?? 180)); // 0 = keep manual backups forever
        $manualCutoff = $manualMaxAge > 0 ? new \DateTimeImmutable("-{$manualMaxAge} days") : null;
        $preMigrationSeen = 0;
        foreach ($all as $row) { // newest first
            $label = (string)($row['meta']['label'] ?? '');
            if ($label === self::SNAPSHOT_LABEL
                || ($label === 'pre-migration' && $preMigrationSeen++ < $preMigrationKeep)
                || ($label === 'manual' && ($manualCutoff === null || self::rowWhen($row) >= $manualCutoff))) {
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
            try {
                if (FileSystem::tryLock($h, $lockPath)) return $h;
            } catch (RuntimeException $e) {
                fclose($h);
                throw $e;
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
