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

    private const LEGACY_CHECKSUMS = [
        '20260424000000_baseline' => [
            '0733ee8ec1141fe01a63fc86ff0f8a0efff517b7873f847759bcfe0e63500b29' => ['198e229704609cf3a2fd5943cb2a50a8b31872892e72e476ece36e64c1ccb832', 'a3ca08f0ad32a92cfa750126363c55ff7b6504e0c0b2819d05c5e8be227e248e'],
            'b77aaf955c42756c146d861e36c942b6f6eba00b639846571dbb69b0c860700d' => ['198e229704609cf3a2fd5943cb2a50a8b31872892e72e476ece36e64c1ccb832', 'a3ca08f0ad32a92cfa750126363c55ff7b6504e0c0b2819d05c5e8be227e248e'],
        ],
        '20260424221721_create_invoices' => [
            '5f3ae8cbe4351aa66dcf3950298614af9a931466cd5f89d75bb30abcce796e84' => ['d723987d9ae0d85b069c69b5503e212afef19d4ddffda920fe916a69b6c1c919', '77a943c7490abb854a2af0b107def2b54d1629a4dd6f2e3b9a83b4cde2613e90'],
            '72f897ada616001f367632189e50c54b85e33c8915e2bc3b673afc1ff77d35dc' => ['d723987d9ae0d85b069c69b5503e212afef19d4ddffda920fe916a69b6c1c919', '77a943c7490abb854a2af0b107def2b54d1629a4dd6f2e3b9a83b4cde2613e90'],
        ],
        '20260425013405_add_vat_columns' => [
            'a1d5a426999bca90c2ffbe4b3e7c1f8bad06cf70d415a58d77ef6d137b44dce5' => ['e81e9601d9beae3ba845ea8d94624776cf96d8d6fe9a894f6e4b90e8b47ef475', '62c13dc02a6f69606a30847578783c9cb2f45b1f14cf643b55bbd47a04bd4e2c'],
            'e1d674c6cb8160bd322648d561030e52fdd3babfc340478bb74a51be1c1bd164' => ['e81e9601d9beae3ba845ea8d94624776cf96d8d6fe9a894f6e4b90e8b47ef475', '62c13dc02a6f69606a30847578783c9cb2f45b1f14cf643b55bbd47a04bd4e2c'],
        ],
        '20260425033813_add_gdpr_tables' => [
            'da4fb344cd73b8f42184691e276d2193fb5c8d3ecfec26714fd8ffc956f3238f' => ['2b8698f5da52f19fc93347b10e53fe76231c21c5aa801c0ed61849b1c8b345e1', '29e96c2aba08e105bb4c45b13222d7af6163a91c28eda7d7296d8c9283b5c543'],
            '8e7c68e6aa60d169b983ce37203206b05864e7c198dfeba4fe66cd41b9ce7af4' => ['2b8698f5da52f19fc93347b10e53fe76231c21c5aa801c0ed61849b1c8b345e1', '29e96c2aba08e105bb4c45b13222d7af6163a91c28eda7d7296d8c9283b5c543'],
        ],
        '20260425064000_shipping_methods_pricing_engine' => [
            '0b8f42a4a9049e68b01c265abc95fd78cb5ce1c598b7bc41f68c6891190cb18d' => ['94b031011374ad9de5e6d83673fe756d30005187ee0caab6d1dbde73b5fc83e3', '4e7b78bf38e1fa90911645064a3bca1e74931bcca4e067e1a1f9718572b233a8'],
            '694ff0930cf8124307428f7105cbbfc8400675f05b6c2660beb62ef536e5baf5' => ['94b031011374ad9de5e6d83673fe756d30005187ee0caab6d1dbde73b5fc83e3', '4e7b78bf38e1fa90911645064a3bca1e74931bcca4e067e1a1f9718572b233a8'],
        ],
        '20260425071000_payment_methods_pricing_engine' => [
            '3c18e9bf7cf11472c3cf673db4727687b7c032e549143a73d5617ff72d9490f5' => ['c2ef36962e255089187c153531a9f099f5a32d0b42810b3e0d70dc1ee97c8d0e', '82f59571d4625b7a4c93306505447efeec4a2bf5f8fd2e7ae5dc02b32f0592c7'],
            '893fe8b1cbcfb6ba9776cf6532e4204d193d98c269d7d49f377b9a0a32be4354' => ['c2ef36962e255089187c153531a9f099f5a32d0b42810b3e0d70dc1ee97c8d0e', '82f59571d4625b7a4c93306505447efeec4a2bf5f8fd2e7ae5dc02b32f0592c7'],
        ],
        '20260426090000_create_product_reviews' => [
            'e0f7600c4d4f970dc8814bdb9972e779eb689862c1af0bf15f67817bb2de4524' => ['8aeb582aa036dbea9f39545dc91510bc36c8660659943ebff6cd17fccaf1a1fb', '2062d966bd199d0e13043ae9c6c4854f315ff8c95d63a5d28bc7aa7b9de568cf'],
            '05da7bb75b1cc8e355565baeb3ba3d975b058c4a7145d69bbad32c545a5dab26' => ['8aeb582aa036dbea9f39545dc91510bc36c8660659943ebff6cd17fccaf1a1fb', '2062d966bd199d0e13043ae9c6c4854f315ff8c95d63a5d28bc7aa7b9de568cf'],
        ],
        '20260426093000_create_wishlist_items' => [
            'a16b8762e4c656ac945ee4039367037eac23d08f5a76093662ad1f8549ec028f' => ['1d14ab07178305da1af1d8873decfadc46ccd53f5f05beb2b42f83d36e021a52', '0122c697ee59d7159ed1ae28f88e425dc731fa4d07fb88c2c30779585428acec'],
            '3ac343201b6b64efe8857f7333fbd3276a6fd33f3826c30948a56d29e6b3aec1' => ['1d14ab07178305da1af1d8873decfadc46ccd53f5f05beb2b42f83d36e021a52', '0122c697ee59d7159ed1ae28f88e425dc731fa4d07fb88c2c30779585428acec'],
        ],
        '20260426100000_create_rma' => [
            'ca2f151aa638e6cbdd484901d7650aff28890c62031067d1f41665aae1540d2e' => ['a1fe6b14bee90fd52e63d45eb3ad16e03f370f0e1f15b013cd22a7e8e878971b', 'e2d9ab647dd5d932b9235606c365dfef1dc1f4f59780816c8c5fd2319eb7f0de'],
            'e969d966581bb935484f1861a023d1fff5f3706c5be86df49e538c002c604017' => ['a1fe6b14bee90fd52e63d45eb3ad16e03f370f0e1f15b013cd22a7e8e878971b', 'e2d9ab647dd5d932b9235606c365dfef1dc1f4f59780816c8c5fd2319eb7f0de'],
        ],
    ];

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
     *
     * Optional `$preApplyHook` is called once with the array of pending names
     * BEFORE any migration runs (and only if there ARE pending). Throwing from
     * the hook aborts the apply() call without modifying any state. Used by
     * cli/migrate.php to take a pre-migration safety backup.
     */
    public function apply(?callable $logger = null, ?callable $preApplyHook = null): array {
        $logger = $logger ?? static fn(string $m) => null;
        $this->lock();
        try {
            $this->ensureMigrationsTable();
            $this->verifyChecksums();
            $pending = $this->pendingFiles();
            if ($pending && $preApplyHook) {
                $preApplyHook(array_keys($pending), $logger);
            }
            $applied = [];
            foreach ($pending as $name => $path) {
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
                $ok = $this->checksumMatches($name, $r['checksum'], $this->fileChecksum($files[$name]));
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
        $short   = MigrationSql::short($this->isSqlite);
        $date    = MigrationSql::datetime($this->isSqlite);
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                id          INTEGER PRIMARY KEY{$autoinc},
                name        {$short} NOT NULL UNIQUE,
                checksum    {$short} NOT NULL,
                applied_at  {$date} NOT NULL DEFAULT ({$now}),
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
            if (!$this->checksumMatches($name, $r['checksum'], $actual)) {
                throw new \RuntimeException(
                    "Migration {$name} was modified after being applied " .
                    "(expected checksum {$r['checksum']}, got {$actual}). " .
                    "Refusing to continue. Restore the original file or write a new migration."
                );
            }
        }
    }

    private function checksumMatches(string $name, string $recorded, string $actual): bool {
        if ($recorded === $actual) return true;
        return in_array($actual, self::LEGACY_CHECKSUMS[$name][$recorded] ?? [], true);
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
