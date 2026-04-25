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
        if (self::$livePath === null) {
            throw new RuntimeException('BackupService::restore — live SQLite path unknown');
        }

        $skipSnapshot = (bool)($opts['skip_snapshot'] ?? false);
        $maxRetries   = (int)($opts['max_retries']   ?? 3);
        $retryDelayMs = (int)($opts['retry_delay']   ?? 100);

        $absSrc = self::$storage->localPath($remotePath);
        if (!is_file($absSrc)) {
            // Maybe the user passed a .gz path — try decompress to a temp and use that
            $gz = $absSrc . '.gz';
            if (is_file($gz)) {
                $tmp = $absSrc . '.tmp.decompressed';
                $in  = @gzopen($gz, 'rb');
                $out = @fopen($tmp, 'wb');
                if ($in === false || $out === false) {
                    if ($in)  gzclose($in);
                    if ($out) fclose($out);
                    throw new RuntimeException("Restore: cannot decompress {$gz}");
                }
                while (!gzeof($in)) {
                    $buf = gzread($in, 65536);
                    if ($buf === false) break;
                    fwrite($out, $buf);
                }
                gzclose($in);
                fclose($out);
                $absSrc = $tmp;
            } else {
                throw new RuntimeException("Restore: backup not found: {$remotePath}");
            }
        }

        $metaPath = self::$storage->localPath($remotePath) . '.meta.json';
        $meta = is_file($metaPath) ? (json_decode((string)file_get_contents($metaPath), true) ?: []) : [];

        // 1a. sha256 verify
        if (!empty($meta['sha256'])) {
            $actual = hash_file('sha256', $absSrc);
            if ($actual !== $meta['sha256']) {
                throw new RuntimeException("Restore: sha256 mismatch (expected {$meta['sha256']}, got {$actual})");
            }
        }

        // 1b. integrity_check on the BACKUP file before we touch the live DB
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

        $lockHandle = self::acquireLock();
        try {

            // 3. close live PDO
            $livePath = self::$livePath;
            self::$db = null;
            // also clear shared singleton if it matches
            if (class_exists('Db', false)) {
                try {
                    if (Db::isSqlite()) Db::reset();
                } catch (\Throwable $e) { /* ignore */ }
            }
            // best-effort GC
            gc_collect_cycles();

            // 4. atomic replace with retry (Windows file-lock tolerance)
            $err = null;
            for ($i = 0; $i < $maxRetries; $i++) {
                if (@copy($absSrc, $livePath)) {
                    $err = null;
                    break;
                }
                $err = error_get_last()['message'] ?? 'unknown';
                usleep($retryDelayMs * 1000);
            }
            // also remove WAL/SHM siblings (they belong to the OLD db, would corrupt the restored copy)
            @unlink($livePath . '-wal');
            @unlink($livePath . '-shm');
            if ($err !== null) {
                self::log("RESTORE FAIL src={$remotePath} err={$err}");
                throw new RuntimeException("Restore: copy failed after {$maxRetries} retries: {$err}. " .
                    "Hint: close all open connections and stop the worker before restoring.");
            }

            // 5. open fresh PDO + integrity
            $newDb = new PDO('sqlite:' . $livePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $newDb->exec('PRAGMA journal_mode = WAL');
            $newDb->exec('PRAGMA foreign_keys = ON');
            $integrity = (string)$newDb->query('PRAGMA integrity_check')->fetchColumn();

            // re-init the global Db singleton with the fresh PDO so the rest of the process keeps working
            if (class_exists('Db', false)) {
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

            // cleanup decompressed temp if any
            if (str_ends_with($absSrc, '.tmp.decompressed')) {
                @unlink($absSrc);
            }

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
    }

    /** Keep only the newest `$keep` backups labelled 'pre-restore-snapshot'; delete the rest. */
    private static function pruneSnapshots(int $keep): void
    {
        $all = self::$storage->list();
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
                self::$storage->delete($snaps[$i]['path']);
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    // ---------- list() / gc() ----------

    public static function list(): array
    {
        return self::$storage->list();
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

        $all = self::$storage->list(); // newest first
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
                self::$storage->delete($row['path']);
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
