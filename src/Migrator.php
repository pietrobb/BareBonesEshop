<?php
/**
 * BareBones Shop — Database Migrations
 *
 * Forward-only schema migration runner.
 *
 * Files: migrations/<TIMESTAMP>_<snake_name>.php — each returns an anonymous
 * class with: public function up(PDO $db, bool $isSqlite): void.
 *
 * State: schema_migrations(name UNIQUE, checksum, applied_at, duration_ms).
 *
 * Concurrency: file lock on db/.migrations.lock (flock, 10s timeout).
 *
 * Failure: per-migration BEGIN/ROLLBACK around up(); marker row is only
 * inserted on success — partial DDL won't poison the history.
 *
 * Checksum mismatch (file changed after being applied) is a hard error.
 */
class Migrator {

    public const LOCK_TIMEOUT_SECONDS = 10;
    public const LOCK_FILENAME        = '.migrations.lock';

    private PDO    $db;
    private bool   $isSqlite;
    private string $migrationsDir;
    private string $lockFile;
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(?PDO $db = null, ?string $migrationsDir = null, ?string $lockFile = null) {
        $this->db            = $db ?? Db::get();
        $this->isSqlite      = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $this->migrationsDir = $migrationsDir ?? (dirname(__DIR__) . '/migrations');
        $dbDir               = dirname(__DIR__) . '/db';
        if (!is_dir($dbDir)) @mkdir($dbDir, 0750, true);
        $this->lockFile = $lockFile ?? ($dbDir . '/' . self::LOCK_FILENAME);
    }

    // ------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------

    /**
     * Apply all pending migrations in timestamp order.
     * Returns array of applied names.
     */
    public function apply(?callable $logger = null): array {
        $logger = $logger ?? static fn(string $m) => null;
        $this->lock();
        try {
            $this->ensureMigrationsTable();
            $this->verifyChecksums();
            $applied = [];
            foreach ($this->pendingFiles() as $name => $path) {
                $start    = microtime(true);
                $checksum = $this->fileChecksum($path);
                $logger("  [..] {$name}");
                $migration = $this->loadMigration($path);
                $this->db->beginTransaction();
                try {
                    $migration->up($this->db, $this->isSqlite);
                    $duration = (int)round((microtime(true) - $start) * 1000);
                    $stmt = $this->db->prepare(
                        "INSERT INTO schema_migrations (name, checksum, duration_ms) VALUES (?, ?, ?)"
                    );
                    $stmt->execute([$name, $checksum, $duration]);
                    $this->db->commit();
                    $applied[] = $name;
                    $logger("  [OK] {$name} ({$duration} ms)");
                } catch (\Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $logger("  [FAIL] {$name}: " . $e->getMessage());
                    throw $e;
                }
            }
            return $applied;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Mark every existing migration file as applied — without running them.
     * Used once on existing production DB to "seed" schema_migrations so
     * subsequent runs only see new files as pending.
     *
     * Idempotent: existing rows are not duplicated.
     */
    public function baseline(?callable $logger = null): array {
        $logger = $logger ?? static fn(string $m) => null;
        $this->lock();
        try {
            $this->ensureMigrationsTable();
            $marked = [];
            foreach ($this->allFiles() as $name => $path) {
                $exists = $this->db->prepare("SELECT 1 FROM schema_migrations WHERE name = ?");
                $exists->execute([$name]);
                if ($exists->fetchColumn()) {
                    $logger("  [skip] {$name} (already recorded)");
                    continue;
                }
                $checksum = $this->fileChecksum($path);
                $stmt = $this->db->prepare(
                    "INSERT INTO schema_migrations (name, checksum, duration_ms) VALUES (?, ?, 0)"
                );
                $stmt->execute([$name, $checksum]);
                $marked[] = $name;
                $logger("  [seed] {$name}");
            }
            return $marked;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Return status array with: applied (with checksum_ok flag), pending, missing.
     *  - applied: name → ['applied_at', 'checksum_ok' bool|null]
     *  - pending: list of file names not in DB
     *  - missing: rows in DB whose file is gone
     */
    public function status(): array {
        $this->ensureMigrationsTable();
        $rows = $this->db->query(
            "SELECT name, checksum, applied_at FROM schema_migrations ORDER BY name"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $files = $this->allFiles();

        $applied = [];
        foreach ($rows as $r) {
            $name = $r['name'];
            $ok   = null;
            if (isset($files[$name])) {
                $ok = ($this->fileChecksum($files[$name]) === $r['checksum']);
            }
            $applied[$name] = [
                'applied_at'  => $r['applied_at'],
                'checksum_ok' => $ok,
            ];
        }

        $pending = [];
        foreach ($files as $name => $_) {
            if (!isset($applied[$name])) $pending[] = $name;
        }

        $missing = [];
        foreach ($applied as $name => $_) {
            if (!isset($files[$name])) $missing[] = $name;
        }

        return ['applied' => $applied, 'pending' => $pending, 'missing' => $missing];
    }

    /**
     * Scaffold a new migration file. Returns absolute path of the created file.
     */
    public function create(string $name): string {
        $slug = preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim($name)));
        $slug = trim($slug, '_');
        if ($slug === '') {
            throw new \InvalidArgumentException('Migration name cannot be empty after slugifying');
        }
        $ts   = gmdate('YmdHis');
        $file = "{$ts}_{$slug}.php";
        if (!is_dir($this->migrationsDir)) {
            @mkdir($this->migrationsDir, 0750, true);
        }
        $path = $this->migrationsDir . '/' . $file;

        $stub = <<<'PHP'
<?php
/**
 * Migration: %NAME%
 * Generated: %TS%
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        // $now    = $isSqlite ? "datetime('now')" : "NOW()";
        // $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        // $db->exec("CREATE TABLE example (id INTEGER PRIMARY KEY{$autoinc})");
    }
};

PHP;
        $stub = str_replace(['%NAME%', '%TS%'], [$slug, gmdate('c')], $stub);
        file_put_contents($path, $stub);
        return $path;
    }

    // ------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------

    public function ensureMigrationsTable(): void {
        $autoinc = $this->isSqlite ? '' : ' AUTO_INCREMENT';
        $now     = $this->isSqlite ? "datetime('now')" : 'NOW()';
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                id          INTEGER PRIMARY KEY{$autoinc},
                name        TEXT NOT NULL UNIQUE,
                checksum    TEXT NOT NULL,
                applied_at  TEXT NOT NULL DEFAULT ({$now}),
                duration_ms INTEGER NOT NULL DEFAULT 0
            )"
        );
    }

    /**
     * @return array<string,string> name => path, sorted by name (timestamp prefix)
     */
    public function allFiles(): array {
        if (!is_dir($this->migrationsDir)) return [];
        $files = glob($this->migrationsDir . '/*.php') ?: [];
        $out = [];
        foreach ($files as $f) {
            $name = basename($f, '.php');
            $out[$name] = $f;
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,string> name => path of files not yet applied */
    public function pendingFiles(): array {
        $rows = $this->db->query("SELECT name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $applied = array_flip($rows);
        $out = [];
        foreach ($this->allFiles() as $name => $path) {
            if (!isset($applied[$name])) $out[$name] = $path;
        }
        return $out;
    }

    private function loadMigration(string $path): object {
        $obj = require $path;
        if (!is_object($obj) || !method_exists($obj, 'up')) {
            throw new \RuntimeException("Migration {$path} must return an object with up() method");
        }
        return $obj;
    }

    public function fileChecksum(string $path): string {
        return hash_file('sha256', $path);
    }

    /**
     * Hard-fail if any applied migration's file checksum no longer matches
     * what was recorded — indicates the migration was edited after being run,
     * which would silently desync the DB.
     */
    private function verifyChecksums(): void {
        $rows = $this->db->query(
            "SELECT name, checksum FROM schema_migrations"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $files = $this->allFiles();
        foreach ($rows as $r) {
            $name = $r['name'];
            if (!isset($files[$name])) continue; // 'missing' handled separately
            $actual = $this->fileChecksum($files[$name]);
            if ($actual !== $r['checksum']) {
                throw new \RuntimeException(
                    "Migration {$name} was modified after being applied " .
                    "(expected checksum {$r['checksum']}, got {$actual}). " .
                    "Refusing to continue. Restore the original file or write a new migration."
                );
            }
        }
    }

    private function lock(): void {
        $this->lockHandle = fopen($this->lockFile, 'c');
        if (!$this->lockHandle) {
            throw new \RuntimeException("Cannot open lock file: {$this->lockFile}");
        }
        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
        while (true) {
            if (flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
                return;
            }
            if (microtime(true) >= $deadline) {
                fclose($this->lockHandle);
                $this->lockHandle = null;
                throw new \RuntimeException(
                    "Could not acquire migrations lock within " . self::LOCK_TIMEOUT_SECONDS .
                    "s — another migrate process running?"
                );
            }
            usleep(100_000);
        }
    }

    private function unlock(): void {
        if ($this->lockHandle) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}
