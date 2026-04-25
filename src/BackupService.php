<?php
/**
 * BackupService — vytvára/obnovuje SQLite zálohy cez VACUUM INTO.
 *
 * Public API:
 *   BackupService::init(PDO $db, ?array $config = null, ?string $rootDir = null);
 *   BackupService::create(array $opts = []): array  // returns meta of created backup
 *   BackupService::restore(string $remotePath, array $opts = []): array
 *   BackupService::gc(): array                       // returns ['kept' => N, 'deleted' => [...]]
 *   BackupService::list(): array                     // pass-through to storage
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

    public static function init(PDO $db, ?array $config = null, ?string $rootDir = null): void
    {
        self::$db = $db;
        self::$rootDir = $rootDir ?: dirname(__DIR__);
        self::$cfg = $config ?: self::loadConfig(self::$rootDir . '/config/backup.json');
        $storageRoot = self::$rootDir . '/storage/backups';
        self::$storage = new LocalBackupStorage($storageRoot);
        self::$livePath = self::detectLivePath($db);
    }

    public static function reset(): void
    {
        self::$cfg = [];
        self::$db = null;
        self::$storage = null;
        self::$rootDir = null;
        self::$livePath = null;
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
        $rel = (string)($cfg['log_file'] ?? 'storage/backup.log');
        $abs = self::$rootDir . '/' . $rel;
        $dir = dirname($abs);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        self::rotateLog($abs, (int)($cfg['log_max_bytes'] ?? 10485760), (int)($cfg['log_max_files'] ?? 5));
        $line = '[' . date('c') . '] ' . $message . "\n";
        @file_put_contents($abs, $line, FILE_APPEND | LOCK_EX);
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

    public static function buildRemotePath(\DateTimeInterface $when, int $suffix = 0): string
    {
        $datePart = $when->format('Y/m/d');
        $stamp = $when->format('Ymd-His');
        $name = 'eshop-' . $stamp . ($suffix > 0 ? '-' . $suffix : '') . '.db';
        return $datePart . '/' . $name;
    }

    /** Reserve a unique remote path for `now` — appends -1, -2, ... if the .db already exists. */
    public static function reserveUniquePath(\DateTimeInterface $when): string
    {
        $rp = self::buildRemotePath($when, 0);
        $i = 0;
        while (is_file(self::$storage->localPath($rp))) {
            $i++;
            $rp = self::buildRemotePath($when, $i);
            if ($i > 999) {
                throw new RuntimeException('BackupService: cannot reserve unique backup path');
            }
        }
        return $rp;
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
        if (self::$livePath === null) {
            throw new RuntimeException('BackupService::create — live SQLite path unknown (in-memory or unsupported driver)');
        }

        $label    = (string)($opts['label']   ?? 'manual');
        $trigger  = (string)($opts['trigger'] ?? $label);
        $compress = (bool)($opts['compress']  ?? (self::$cfg['compression'] ?? false));

        $lockHandle = self::acquireLock();
        try {
            // 1. WAL checkpoint (best-effort)
            if (!empty(self::$cfg['wal_checkpoint'])) {
                try { self::$db->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (\Throwable $e) { /* non-fatal */ }
            }

            // 2. Reserve a unique remote path
            $when = new DateTimeImmutable('now');
            $remotePath = self::reserveUniquePath($when);
            $absPath = self::$storage->localPath($remotePath);
            $absDir  = dirname($absPath);
            if (!is_dir($absDir)) mkdir($absDir, 0775, true);

            // 3. VACUUM INTO — atomic, consistent snapshot
            $sqlPath = str_replace("'", "''", $absPath);
            try {
                self::$db->exec("VACUUM INTO '{$sqlPath}'");
            } catch (\Throwable $e) {
                @unlink($absPath);
                self::log("CREATE FAIL label={$label} err=" . $e->getMessage());
                throw new RuntimeException('VACUUM INTO failed: ' . $e->getMessage(), 0, $e);
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
                $gzPath = $absPath . '.gz';
                $in  = @fopen($absPath, 'rb');
                $out = @gzopen($gzPath, 'wb6');
                if ($in === false || $out === false) {
                    if ($in)  fclose($in);
                    if ($out) gzclose($out);
                    @unlink($gzPath);
                } else {
                    while (!feof($in)) {
                        $buf = fread($in, 65536);
                        if ($buf === false) break;
                        gzwrite($out, $buf);
                    }
                    fclose($in);
                    gzclose($out);
                    $compressed = is_file($gzPath);
                }
            }

            // 6. Schema version
            $schemaVersion = self::currentSchemaVersion(self::$db);

            // 7. Meta JSON written by storage adapter
            $meta = [
                'filename'       => basename($remotePath),
                'remote_path'    => $remotePath,
                'timestamp'      => $when->format('c'),
                'size_bytes'     => $size,
                'sha256'         => $sha,
                'schema_version' => $schemaVersion,
                'label'          => $label,
                'trigger'        => $trigger,
                'compressed'     => $compressed,
                'created_by'     => 'BackupService',
            ];
            $metaJson = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($metaJson === false) {
                throw new RuntimeException('Meta encode failed');
            }
            if (file_put_contents($absPath . '.meta.json', $metaJson) === false) {
                throw new RuntimeException("Meta write failed: {$absPath}.meta.json");
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
            self::releaseLock($lockHandle);
        }
    }

    // ---------- gc() (placeholder — implemented in next commit) ----------

    public static function gc(): array
    {
        // To be implemented in the next commit (retention).
        return ['kept' => 0, 'deleted' => []];
    }

    // ---------- locking ----------

    private static function acquireLock()
    {
        $lockPath = self::$storage instanceof LocalBackupStorage
            ? self::$storage->root() . DIRECTORY_SEPARATOR . '.backup.lock'
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . '.bbe-backup.lock';

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
