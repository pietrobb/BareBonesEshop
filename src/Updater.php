<?php

final class Updater
{
    private const STATE_FILE = 'installed.json';
    private const PROTECTED_FILES = ['.env', 'config.php', '.barebones-version.json'];
    private const PROTECTED_PREFIXES = [
        '.git/', '.worktrees/', '.claude/', '.playwright-mcp/',
        'cache/', 'db/', 'extensions/', 'jobs/', 'logs/', 'storage/', 'uploads/',
    ];

    /** Tables whose MAX(id) at snapshot time shows what a database rollback throws away. */
    private const HIGH_WATER_TABLES = ['orders', 'payment_transactions'];

    private string $root;
    private string $stateDirectory;
    private ?PDO $database;
    private $migrationRunner;
    private ?UpdateDatabaseBackup $databaseBackup;
    private ?ThemeUpgradeGate $themeGate;
    private $lockHandle = null;

    public function __construct(
        string $root,
        string $stateDirectory,
        ?PDO $database = null,
        ?callable $migrationRunner = null,
        ?UpdateDatabaseBackup $databaseBackup = null,
        ?ThemeUpgradeGate $themeGate = null
    ) {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot) || is_link($realRoot)) {
            throw new InvalidArgumentException("Application root not found: {$root}");
        }
        $this->root = rtrim($realRoot, DIRECTORY_SEPARATOR);
        $this->stateDirectory = $this->absolutePath($stateDirectory);
        $this->ensureDirectory($this->stateDirectory, 0750);
        $realStateDirectory = realpath($this->stateDirectory);
        if ($realStateDirectory === false || is_link($this->stateDirectory)) {
            throw new RuntimeException('Update state directory cannot be resolved safely');
        }
        $this->stateDirectory = rtrim($realStateDirectory, DIRECTORY_SEPARATOR);
        if ($this->isInside($this->stateDirectory, $this->root)) {
            throw new RuntimeException('Update state directory must be outside the public application root');
        }
        $this->database = $database;
        $this->migrationRunner = $migrationRunner;
        $this->databaseBackup = $databaseBackup;
        $this->themeGate = $themeGate;
    }

    public static function isProtectedPath(string $path): bool
    {
        $comparable = strtolower(str_replace('\\', '/', $path));
        if (in_array($comparable, self::PROTECTED_FILES, true)) {
            return true;
        }
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($comparable, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function baseline(string $version): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]{0,63}$/', $version)) {
            throw new InvalidArgumentException('Invalid baseline version');
        }
        $this->lock();
        try {
            if (is_file($this->statePath())) {
                throw new RuntimeException('Installed version state already exists; baseline cannot overwrite it');
            }
            $state = [
                'version' => $version,
                'baselined_at' => gmdate('c'),
            ];
            $this->writeJsonAtomic($this->statePath(), $state);
            return $state;
        } finally {
            $this->unlock();
        }
    }

    public function check(UpdatePackage $package): array
    {
        $ready = [];
        $current = [];
        $conflicts = [];
        $blockers = [];
        $warnings = [];

        $state = $this->readState();
        $installedVersion = is_string($state['version'] ?? null) ? $state['version'] : null;
        $baseVersion = $package->baseVersion();
        if ($installedVersion !== null && $baseVersion !== null && $installedVersion !== $baseVersion) {
            $blockers[] = "Installed version {$installedVersion} does not match package base version {$baseVersion}";
        } elseif ($installedVersion === null && $baseVersion !== null) {
            $blockers[] = "Installed version is unknown; run baseline --version={$baseVersion} only after identifying the deployed release";
        } elseif ($installedVersion === null) {
            $warnings[] = 'Package and installation are unversioned; file hashes are the only compatibility check';
        }

        if ($package->runsMigrations()) {
            if ($this->database === null) {
                $blockers[] = 'Package contains migrations but no database connection is available';
            } else {
                $driver = (string)$this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'mysql' && $this->databaseBackup === null) {
                    $blockers[] = 'MySQL migration packages require a configured dump/restore adapter and disposable probe database';
                } elseif (!in_array($driver, ['sqlite', 'mysql'], true)) {
                    $blockers[] = "Migration packages do not support database driver {$driver}";
                }
                if ($this->migrationRunner === null) {
                    $blockers[] = 'Package contains migrations but no migration runner is configured';
                }
            }
        }

        // New core + installed themes/stores.json/store overrides, rendered before anything changes.
        $themeContract = null;
        if ($this->themeGate === null) {
            $warnings[] = 'Theme checkout contract was not verified: this updater instance has no theme gate';
        } else {
            $themeContract = $this->themeGate->evaluate($this->root, $package);
            if ($themeContract['status'] !== 'GO') {
                foreach ($themeContract['failures'] as $failure) {
                    $blockers[] = 'Theme checkout contract: ' . $failure;
                }
                $blockers[] = 'Theme checkout contract NO-GO: the installed theme/store blocks named above would break checkout with '
                    . $package->version() . '. Update them in the installation (or ship the fix in the package), then rerun check';
            }
        }

        foreach ($package->entries() as $entry) {
            $path = $entry['path'];
            if (self::isProtectedPath($path)) {
                $conflicts[$path] = 'protected deployment path';
                continue;
            }
            try {
                $target = $this->targetPath($path, true);
            } catch (Throwable $e) {
                $conflicts[$path] = $e->getMessage();
                continue;
            }
            $exists = is_file($target) && !is_link($target);
            $actual = $exists ? hash_file('sha256', $target) : null;
            if ($actual === false) {
                $conflicts[$path] = 'cannot hash installed file';
                continue;
            }
            if (file_exists($target) && !$exists) {
                $conflicts[$path] = 'installed path is not a regular file';
                continue;
            }

            $previous = $entry['previous_sha256'] ?? null;
            if ($entry['action'] === 'write') {
                if ($actual !== null && hash_equals($entry['sha256'], $actual)) {
                    $current[] = $path;
                } elseif (($previous === null && $actual === null)
                    || ($previous !== null && $actual !== null && hash_equals($previous, $actual))) {
                    $ready[] = $path;
                } else {
                    $conflicts[$path] = $actual === null
                        ? 'installed file is missing but package expects the base file'
                        : 'installed file was locally modified';
                }
            } else {
                if ($actual === null) {
                    $current[] = $path;
                } elseif ($previous !== null && hash_equals($previous, $actual)) {
                    $ready[] = $path;
                } else {
                    $conflicts[$path] = 'installed file was locally modified';
                }
            }
        }

        return [
            'version' => $package->version(),
            'base_version' => $baseVersion,
            'installed_version' => $installedVersion,
            'ready' => $ready,
            'current' => $current,
            'conflicts' => $conflicts,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'theme_contract' => $themeContract,
            'can_apply' => !$conflicts && !$blockers,
        ];
    }

    public function backup(UpdatePackage $package): array
    {
        $this->lock();
        try {
            $report = $this->check($package);
            $this->assertApplicable($report);
            return $this->createSnapshot($package, $report, 'manual-backup');
        } finally {
            $this->unlock();
        }
    }

    public function apply(UpdatePackage $package): array
    {
        $this->lock();
        $snapshot = null;
        $staged = [];
        $mutated = false;
        try {
            $report = $this->check($package);
            $this->assertApplicable($report);
            $snapshot = $this->createSnapshot($package, $report, 'pre-update');
            $report = $this->check($package);
            $this->assertApplicable($report);

            // The package was verified when it was opened, possibly minutes ago (the MySQL dump
            // runs in between). Stage every write and re-hash the staged copy against the signed
            // manifest before touching a single installed file.
            $ready = array_values(array_filter(
                $package->entries(),
                static fn(array $entry): bool => in_array($entry['path'], $report['ready'], true)
            ));
            foreach ($ready as $entry) {
                $this->assertEntryStillApplicable($entry);
                if ($entry['action'] === 'write') {
                    $staged[$entry['path']] = $this->stageVerifiedFile(
                        $package->payloadPath($entry['path']),
                        $this->targetPath($entry['path'], false),
                        $entry['sha256'],
                        $entry['path']
                    );
                }
            }

            $mutated = true;
            foreach ($ready as $entry) {
                if ($entry['action'] === 'write') {
                    $this->commitStagedFile($staged[$entry['path']], $this->targetPath($entry['path'], false));
                    unset($staged[$entry['path']]);
                } else {
                    $this->deleteFile($entry['path']);
                }
            }

            if ($package->runsMigrations()) {
                ($this->migrationRunner)();
            }

            $state = [
                'version' => $package->version(),
                'base_version' => $package->baseVersion(),
                'installed_at' => gmdate('c'),
                'snapshot' => $snapshot['id'],
            ];
            $this->writeJsonAtomic($this->statePath(), $state);
            $this->setSnapshotStatus($snapshot['id'], 'applied');

            return [
                'version' => $package->version(),
                'snapshot' => $snapshot['id'],
                'changed' => count($report['ready']),
                'already_current' => count($report['current']),
                'retired' => $this->retireObsoleteFiles($snapshot['id']),
            ];
        } catch (Throwable $e) {
            foreach ($staged as $temporary) {
                @unlink($temporary);
            }
            if (is_array($snapshot) && isset($snapshot['id']) && !$mutated) {
                // Nothing installed and no migration ran: restoring files or a database dump
                // over an untouched installation would only add risk.
                $this->setSnapshotStatus($snapshot['id'], 'aborted', $e->getMessage());
            } elseif (is_array($snapshot) && isset($snapshot['id'])) {
                // Counted before the restore wipes them: writes since the snapshot that the rollback discards.
                $discarded = $this->discardedWrites($snapshot);
                try {
                    $this->restoreSnapshot($snapshot['id'], true);
                    $this->setSnapshotStatus($snapshot['id'], 'auto-rolled-back', $e->getMessage(), $discarded);
                } catch (Throwable $rollbackError) {
                    throw new RuntimeException(
                        'Update failed: ' . $e->getMessage() . '; automatic rollback also failed: ' . $rollbackError->getMessage(),
                        0,
                        $e
                    );
                }
                $notice = $this->reportDiscardedWrites($discarded, 'automatic rollback');
                if ($notice !== null) {
                    throw new RuntimeException($e->getMessage() . '; ' . $notice, 0, $e);
                }
            }
            throw $e;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Delete all but the newest $keep (at least 1) 'pre-update' snapshots: each holds a full database copy
     * or dump, so they would otherwise pile up with every update (and keep erased personal data forever).
     * The snapshot of the installed release and 'manual-backup' snapshots are never deleted.
     * @return list<string> ids of the deleted snapshots
     */
    public function pruneSnapshots(int $keep): array
    {
        $this->lock();
        try {
            $installed = (string)($this->readJson($this->statePath())['snapshot'] ?? '');
            $deleted = [];
            $kept = 0;
            foreach ($this->listSnapshots() as $snapshot) { // newest first
                $id = (string)($snapshot['id'] ?? '');
                if (($snapshot['reason'] ?? '') !== 'pre-update' || !preg_match('/^\d{14}-[0-9a-f]{12}$/', $id)) continue;
                if (++$kept <= max(1, $keep) || $id === $installed) continue;
                $this->removeTree($this->snapshotDirectory($id));
                $deleted[] = $id;
            }
            return $deleted;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Restores code and, for a migration update, the database snapshot. That discards every database write since
     * the snapshot (orders, payments, customers, stock), so it is refused unless $acceptDataLoss confirms it.
     */
    public function rollback(string $snapshotId, bool $force = false, bool $acceptDataLoss = false): array
    {
        $this->lock();
        try {
            $metadata = $this->snapshotMetadata($snapshotId);
            $databaseAt = is_array($metadata['database'] ?? null) ? (string)$metadata['created_at'] : null;
            if ($databaseAt !== null && !$acceptDataLoss) {
                throw new RuntimeException(self::dataLossWarning($databaseAt)
                    . ' Stop all writers, export or reconcile those records, then re-run with --accept-data-loss.');
            }
            $discarded = $databaseAt !== null ? $this->discardedWrites($metadata) : null;
            $result = $this->restoreSnapshot($snapshotId, $force);
            $this->setSnapshotStatus($snapshotId, 'rolled-back', null, $discarded);
            $this->reportDiscardedWrites($discarded, 'manual rollback');
            return $result + ['database_restored_to' => $databaseAt, 'discarded_writes' => $discarded];
        } finally {
            $this->unlock();
        }
    }

    public function listSnapshots(): array
    {
        $directory = $this->stateDirectory . DIRECTORY_SEPARATOR . 'snapshots';
        if (!is_dir($directory)) {
            return [];
        }
        $rows = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $path) {
            $meta = $this->readJson($path . DIRECTORY_SEPARATOR . 'snapshot.json');
            if ($meta) {
                $rows[] = $meta;
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        return $rows;
    }

    public function doctor(?string $publicKey = null, ?UpdatePackage $package = null, bool $probeDatabaseBackup = true): array
    {
        $checks = [];
        $add = static function (string $level, string $name, string $message) use (&$checks): void {
            $checks[] = compact('level', 'name', 'message');
        };

        $add(PHP_VERSION_ID >= 80100 ? 'ok' : 'fail', 'php', 'PHP ' . PHP_VERSION . ' (8.1+ required)');
        $add(PHP_SAPI === 'cli' ? 'ok' : 'fail', 'sapi', 'Update command is running under ' . PHP_SAPI);

        $hasSodium = function_exists('sodium_crypto_sign_verify_detached');
        $add($hasSodium ? 'ok' : 'fail', 'sodium', $hasSodium
            ? 'Ed25519 signature verification is available'
            : 'PHP sodium extension is missing; signed production updates cannot be verified');
        if ($publicKey === null || trim($publicKey) === '') {
            $add('fail', 'public_key', 'No production update public key is configured');
        } elseif ($hasSodium) {
            try {
                UpdatePackage::decodeKey(trim($publicKey), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'update public key');
                $add('ok', 'public_key', 'Configured Ed25519 public key is valid');
            } catch (Throwable $e) {
                $add('fail', 'public_key', $e->getMessage());
            }
        } else {
            $add('fail', 'public_key', 'Public key cannot be validated without sodium');
        }

        $add(is_writable($this->stateDirectory) ? 'ok' : 'fail', 'state_directory',
            is_writable($this->stateDirectory)
                ? 'Private update state directory is writable'
                : 'Private update state directory is not writable');
        try {
            $this->lock();
            $add('ok', 'update_lock', 'Exclusive update lock can be acquired');
        } catch (Throwable $e) {
            $add('fail', 'update_lock', $e->getMessage());
        } finally {
            $this->unlock();
        }

        if ($this->database === null) {
            $add('fail', 'database', 'No database connection is available for readiness verification');
        } else {
            try {
                $this->database->query('SELECT 1')->fetchColumn();
                $driver = (string)$this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
                $add(in_array($driver, ['sqlite', 'mysql'], true) ? 'ok' : 'fail', 'database',
                    "Database connection is healthy ({$driver})");

                if ($probeDatabaseBackup && in_array($driver, ['sqlite', 'mysql'], true)) {
                    $probeDirectory = $this->stateDirectory . DIRECTORY_SEPARATOR . '.doctor-' . bin2hex(random_bytes(6));
                    try {
                        $this->ensureDirectory($probeDirectory, 0750);
                        if ($driver === 'sqlite') {
                            $metadata = $this->snapshotSqliteDatabase($probeDirectory);
                            $backupPath = $probeDirectory . DIRECTORY_SEPARATOR . 'database.sqlite';
                            $probe = new PDO('sqlite:' . $backupPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                            $integrity = (string)$probe->query('PRAGMA integrity_check')->fetchColumn();
                            $probe = null;
                            if ($integrity !== 'ok' || !hash_equals((string)$metadata['sha256'], (string)hash_file('sha256', $backupPath))) {
                                throw new RuntimeException("SQLite backup probe failed integrity verification: {$integrity}");
                            }
                            $add('ok', 'database_backup', 'SQLite snapshot creation, checksum, and integrity probe passed');
                        } elseif ($this->databaseBackup === null) {
                            $level = $package !== null && $package->runsMigrations() ? 'fail' : 'warn';
                            $add($level, 'database_backup', 'MySQL dump/restore adapter is not configured; it is required only for migration packages');
                        } else {
                            $this->databaseBackup->probe($probeDirectory);
                            $add('ok', 'database_backup', 'MySQL dump, disposable restore, schema, row-count, and content-checksum comparison passed');
                        }
                    } catch (Throwable $e) {
                        $add('fail', 'database_backup', $e->getMessage());
                    } finally {
                        $this->removeTree($probeDirectory);
                    }
                } elseif ($driver === 'sqlite') {
                    $add('fail', 'database_backup', 'SQLite backup capability was not verified because the probe was skipped');
                } elseif ($driver === 'mysql') {
                    $level = $package !== null && $package->runsMigrations() ? 'fail' : 'warn';
                    $add($level, 'database_backup', 'MySQL backup capability was not verified because the probe was skipped');
                }
            } catch (Throwable $e) {
                $add('fail', 'database', 'Database readiness check failed: ' . $e->getMessage());
            }
        }

        $preflight = null;
        if ($package === null) {
            $add('warn', 'package', 'No package supplied; package signature and installed-file compatibility were not checked');
        } else {
            $preflight = $this->check($package);
            $add($preflight['can_apply'] ? 'ok' : 'fail', 'package', $preflight['can_apply']
                ? 'Package signature, payload, version, and installed-file preflight passed'
                : 'Package preflight failed with ' . count($preflight['blockers']) . ' blocker(s) and '
                    . count($preflight['conflicts']) . ' conflict(s)');
        }

        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($checks as $check) {
            $counts[$check['level']]++;
        }
        return [
            'status' => $counts['fail'] === 0 ? 'READY' : 'NOT_READY',
            'counts' => $counts,
            'checks' => $checks,
            'package_preflight' => $preflight,
        ];
    }

    private function createSnapshot(UpdatePackage $package, array $report, string $reason): array
    {
        $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
        $snapshotDirectory = $this->snapshotDirectory($id);
        $filesDirectory = $snapshotDirectory . DIRECTORY_SEPARATOR . 'files';
        $this->ensureDirectory($filesDirectory, 0750);

        $metadata = [
            'id' => $id,
            'created_at' => gmdate('c'),
            'reason' => $reason,
            'status' => 'prepared',
            'package_version' => $package->version(),
            'files' => [],
            'database' => null,
            'state_existed' => is_file($this->statePath()),
        ];

        try {
            foreach ($package->entries() as $entry) {
                $target = $this->targetPath($entry['path'], true);
                $exists = is_file($target) && !is_link($target);
                $row = [
                    'path' => $entry['path'],
                    'existed' => $exists,
                    'before_sha256' => $exists ? hash_file('sha256', $target) : null,
                    'after_sha256' => $entry['action'] === 'write' ? $entry['sha256'] : null,
                ];
                if ($exists) {
                    $backupPath = $filesDirectory . DIRECTORY_SEPARATOR
                        . str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
                    $this->ensureDirectory(dirname($backupPath), 0750);
                    if (!copy($target, $backupPath)) {
                        throw new RuntimeException("Cannot snapshot file: {$entry['path']}");
                    }
                    @chmod($backupPath, fileperms($target) & 0777);
                }
                $metadata['files'][] = $row;
            }

            $statePath = $this->statePath();
            if ($metadata['state_existed'] && !copy($statePath, $snapshotDirectory . DIRECTORY_SEPARATOR . 'version-state.json')) {
                throw new RuntimeException('Cannot snapshot installed version state');
            }

            if ($package->runsMigrations()) {
                $driver = (string)$this->database?->getAttribute(PDO::ATTR_DRIVER_NAME);
                // Read before the dump, so a row written while it runs counts as "since the snapshot" (over-, never under-reported).
                $highWater = $this->highWaterMarks();
                $metadata['database'] = $driver === 'mysql'
                    ? $this->databaseBackup?->create($snapshotDirectory)
                    : $this->snapshotSqliteDatabase($snapshotDirectory);
                if (!is_array($metadata['database'])) {
                    throw new RuntimeException('Database migration snapshot was not created');
                }
                $metadata['database']['high_water'] = $highWater;
            }
            $this->writeJsonAtomic($snapshotDirectory . DIRECTORY_SEPARATOR . 'snapshot.json', $metadata);
            return $metadata;
        } catch (Throwable $e) {
            $this->removeTree($snapshotDirectory);
            throw $e;
        }
    }

    private function snapshotSqliteDatabase(string $snapshotDirectory): array
    {
        if ($this->database === null || $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('A SQLite database connection is required for migration snapshot');
        }
        $row = $this->database->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC);
        $livePath = is_array($row) ? (string)($row['file'] ?? '') : '';
        if ($livePath === '' || $livePath === ':memory:' || !is_file($livePath)) {
            throw new RuntimeException('Cannot resolve the live SQLite database path');
        }
        $backupPath = $snapshotDirectory . DIRECTORY_SEPARATOR . 'database.sqlite';
        $quoted = str_replace("'", "''", $backupPath);
        $this->database->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $this->database->exec("VACUUM INTO '{$quoted}'");
        if (!is_file($backupPath)) {
            throw new RuntimeException('SQLite update snapshot was not created');
        }
        @chmod($backupPath, 0600);
        return [
            'driver' => 'sqlite',
            'live_path' => $livePath,
            'sha256' => hash_file('sha256', $backupPath),
            'size_bytes' => filesize($backupPath),
        ];
    }

    public static function dataLossWarning(string $snapshotCreatedAt): string
    {
        $age = max(0, time() - (int)strtotime($snapshotCreatedAt));
        return sprintf('WARNING: rollback replaces the live database with the snapshot taken at %s (%dh %02dm ago).'
            . ' Every order, payment, customer, stock movement and other change written since then is permanently lost.',
            $snapshotCreatedAt, intdiv($age, 3600), intdiv($age % 3600, 60));
    }

    private function snapshotMetadata(string $snapshotId): array
    {
        if (!preg_match('/^\d{14}-[0-9a-f]{12}$/', $snapshotId)) {
            throw new InvalidArgumentException('Invalid update snapshot ID');
        }
        $metadata = $this->readJson($this->snapshotDirectory($snapshotId) . DIRECTORY_SEPARATOR . 'snapshot.json');
        if (!$metadata || ($metadata['id'] ?? null) !== $snapshotId || !is_array($metadata['files'] ?? null)) {
            throw new RuntimeException("Update snapshot not found or invalid: {$snapshotId}");
        }
        return $metadata;
    }

    private function restoreSnapshot(string $snapshotId, bool $force): array
    {
        $metadata = $this->snapshotMetadata($snapshotId);
        $directory = $this->snapshotDirectory($snapshotId);

        if (!$force) {
            foreach ($metadata['files'] as $row) {
                $target = $this->targetPath((string)$row['path'], true);
                $actual = is_file($target) && !is_link($target) ? hash_file('sha256', $target) : null;
                $expected = $row['after_sha256'] ?? null;
                if (($expected === null && $actual !== null)
                    || ($expected !== null && ($actual === null || !hash_equals((string)$expected, (string)$actual)))) {
                    throw new RuntimeException("Rollback refused because {$row['path']} changed after the update; use --force only after review");
                }
            }
        }

        $database = is_array($metadata['database'] ?? null) ? $metadata['database'] : null;
        $isMysql = $database !== null && ($database['driver'] ?? null) === 'mysql';
        if ($isMysql && $this->databaseBackup === null) {
            throw new RuntimeException('MySQL rollback requires the configured dump/restore adapter');
        }
        $sqliteSource = $database !== null && !$isMysql ? $this->verifiedSqliteSnapshot($directory, $database) : null;

        $restore = function () use ($metadata, $directory, $database, $isMysql, $sqliteSource): void {
            foreach ($metadata['files'] as $row) {
                $path = (string)$row['path'];
                if (!empty($row['existed'])) {
                    $source = $directory . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR
                        . str_replace('/', DIRECTORY_SEPARATOR, $path);
                    if (!is_file($source) || is_link($source)) {
                        throw new RuntimeException("Snapshot file missing: {$path}");
                    }
                    $this->installFile($source, $path);
                } else {
                    $this->deleteFile($path);
                }
            }

            $statePath = $this->statePath();
            if (!empty($metadata['state_existed'])) {
                $source = $directory . DIRECTORY_SEPARATOR . 'version-state.json';
                if (!is_file($source)) {
                    throw new RuntimeException('Snapshot version state is missing');
                }
                $this->installAbsoluteFile($source, $statePath);
            } elseif (is_file($statePath) && !unlink($statePath)) {
                throw new RuntimeException('Cannot remove installed version state during rollback');
            }

            if ($isMysql) {
                $this->database = null;
                if (class_exists('Db', false)) {
                    Db::reset();
                }
                $this->databaseBackup->restore($directory, $database);
            } elseif ($sqliteSource !== null) {
                $livePath = (string)$database['live_path'];
                @unlink($livePath . '-wal');
                @unlink($livePath . '-shm');
                $this->installAbsoluteFile($sqliteSource, $livePath);
                @unlink($livePath . '-wal');
                @unlink($livePath . '-shm');
            }
        };

        if ($sqliteSource === null) {
            $restore();
        } else {
            // Code and database roll back together, only once no other process holds the live file.
            $livePath = (string)$database['live_path'];
            $this->database = null;
            if (class_exists('Db', false)) {
                Db::releaseSqliteFile($livePath);
            }
            gc_collect_cycles();
            SqliteMaintenance::exclusive($livePath, 'update rollback', $restore);
        }
        return ['snapshot' => $snapshotId, 'restored_files' => count($metadata['files'])];
    }

    private function verifiedSqliteSnapshot(string $snapshotDirectory, array $database): string
    {
        if (($database['driver'] ?? null) !== 'sqlite') {
            throw new RuntimeException('Unsupported database snapshot driver');
        }
        $source = $snapshotDirectory . DIRECTORY_SEPARATOR . 'database.sqlite';
        $expected = (string)($database['sha256'] ?? '');
        if ((string)($database['live_path'] ?? '') === '' || !is_file($source) || !preg_match('/^[0-9a-f]{64}$/', $expected)
            || !hash_equals($expected, (string)hash_file('sha256', $source))) {
            throw new RuntimeException('SQLite rollback snapshot failed integrity verification');
        }
        return $source;
    }

    private function assertEntryStillApplicable(array $entry): void
    {
        $target = $this->targetPath((string)$entry['path'], true);
        $actual = is_file($target) && !is_link($target) ? hash_file('sha256', $target) : null;
        $previous = $entry['previous_sha256'] ?? null;
        $matches = $previous === null
            ? $actual === null
            : is_string($actual) && hash_equals((string)$previous, $actual);
        if (!$matches) {
            throw new RuntimeException("Installed file changed during update: {$entry['path']}");
        }
    }

    private function installFile(string $source, string $relativePath): void
    {
        $target = $this->targetPath($relativePath, false);
        $this->installAbsoluteFile($source, $target);
    }

    private function installAbsoluteFile(string $source, string $target): void
    {
        $this->commitStagedFile($this->stageFile($source, $target), $target);
    }

    private function stageFile(string $source, string $target): string
    {
        if (!is_file($source) || is_link($source)) {
            throw new RuntimeException("Update source is not a regular file: {$source}");
        }
        $this->ensureDirectory(dirname($target), 0755);
        $temporary = dirname($target) . DIRECTORY_SEPARATOR . '.' . basename($target)
            . '.update-' . bin2hex(random_bytes(6)) . '.tmp';
        if (!copy($source, $temporary)) {
            @unlink($temporary);
            throw new RuntimeException("Cannot stage update file: {$target}");
        }
        @chmod($temporary, fileperms($source) & 0777);
        return $temporary;
    }

    /** Stages a payload file and proves the staged bytes — the ones that will be installed — match the signed hash. */
    private function stageVerifiedFile(string $source, string $target, string $expectedSha256, string $relativePath): string
    {
        $temporary = $this->stageFile($source, $target);
        $actual = hash_file('sha256', $temporary);
        if (!is_string($actual) || !hash_equals($expectedSha256, $actual)) {
            @unlink($temporary);
            throw new RuntimeException("Payload checksum mismatch at install time: {$relativePath}");
        }
        return $temporary;
    }

    private function commitStagedFile(string $temporary, string $target): void
    {
        try {
            if (file_exists($target) && (!is_file($target) || is_link($target))) {
                throw new RuntimeException("Update target is not a regular file: {$target}");
            }
            // One rename replaces the target in place (rename(2) on POSIX, MoveFileEx with
            // REPLACE_EXISTING on Windows). Moving the old file aside first left a window in
            // which live requests found no file at all; on failure the old file stays intact.
            // Windows refuses the replace while a request briefly holds the target open: retry.
            for ($attempt = 1; !@rename($temporary, $target); $attempt++) {
                if (DIRECTORY_SEPARATOR !== '\\' || $attempt >= 40) {
                    throw new RuntimeException("Cannot atomically install update file: {$target}");
                }
                usleep(25000);
            }
        } finally {
            @unlink($temporary);
        }
    }

    private function deleteFile(string $relativePath): void
    {
        $target = $this->targetPath($relativePath, true);
        if (!file_exists($target)) {
            return;
        }
        if (!is_file($target) || is_link($target) || !unlink($target)) {
            throw new RuntimeException("Cannot delete update target: {$relativePath}");
        }
    }

    /**
     * Moves generated files that no release tracks (so no package can delete them by hash) into
     * the snapshot's "retired" directory. Best effort: a file that cannot be moved stays in place
     * and does not fail an update that has already been applied.
     */
    private function retireObsoleteFiles(string $snapshotId): array
    {
        $retired = [];
        foreach (ObsoleteAdminForms::find($this->root) as $relativePath) {
            try {
                $target = $this->targetPath($relativePath, true);
                $destination = $this->snapshotDirectory($snapshotId) . DIRECTORY_SEPARATOR . 'retired'
                    . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                $this->ensureDirectory(dirname($destination), 0750);
                if (copy($target, $destination) && unlink($target)) {
                    $retired[] = $relativePath;
                }
            } catch (Throwable) {
                continue;
            }
        }
        return $retired;
    }

    private function targetPath(string $relativePath, bool $allowMissingParent): string
    {
        $relativePath = UpdatePackage::normalizePath($relativePath);
        $current = $this->root;
        $parts = explode('/', $relativePath);
        foreach ($parts as $index => $part) {
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new RuntimeException("Symlink is not allowed in installed path: {$relativePath}");
            }
            if ($index < count($parts) - 1 && file_exists($current) && !is_dir($current)) {
                throw new RuntimeException("Installed parent is not a directory: {$relativePath}");
            }
            if (!$allowMissingParent && $index < count($parts) - 1 && !file_exists($current)) {
                $this->ensureDirectory($current, 0755);
            }
        }
        return $current;
    }

    private function statePath(): string
    {
        return $this->stateDirectory . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    private function readState(): array
    {
        return $this->readJson($this->statePath()) ?? [];
    }

    private function readJson(string $path): ?array
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read JSON file: {$path}");
        }
        try {
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON file: {$path}", 0, $e);
        }
        if (!is_array($value)) {
            throw new RuntimeException("JSON file must contain an object: {$path}");
        }
        return $value;
    }

    private function writeJsonAtomic(string $path, array $value): void
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path)
            . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Cannot atomically write JSON file: {$path}");
        }
        @chmod($path, 0640);
    }

    private function setSnapshotStatus(string $id, string $status, ?string $error = null, ?array $discardedWrites = null): void
    {
        $path = $this->snapshotDirectory($id) . DIRECTORY_SEPARATOR . 'snapshot.json';
        $metadata = $this->readJson($path);
        if (!$metadata) {
            throw new RuntimeException("Snapshot metadata missing: {$id}");
        }
        $metadata['status'] = $status;
        $metadata['updated_at'] = gmdate('c');
        if ($error !== null) {
            $metadata['error'] = $error;
        }
        if ($discardedWrites !== null) {
            $metadata['discarded_writes'] = $discardedWrites;
        }
        $this->writeJsonAtomic($path, $metadata);
    }

    /** Highest id per tracked table (0 when empty); a table that cannot be read is left out. */
    private function highWaterMarks(): array
    {
        $marks = [];
        if ($this->database === null) {
            return $marks;
        }
        foreach (self::HIGH_WATER_TABLES as $table) {
            try {
                $marks[$table] = (int)$this->database->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
            } catch (Throwable $e) {
                // Table not created yet on this installation.
            }
        }
        return $marks;
    }

    /**
     * Rows each tracked table gained since the snapshot's high-water mark: what restoring the
     * snapshot throws away. A count that cannot be read is null (unknown, never assumed zero).
     * Returns null for a snapshot without a database, or when this instance has no connection
     * (cli/update.php rollback, where --accept-data-loss already acknowledged the loss).
     */
    private function discardedWrites(array $snapshot): ?array
    {
        if (!is_array($snapshot['database'] ?? null) || $this->database === null) {
            return null;
        }
        $marks = $snapshot['database']['high_water'] ?? null;
        $counts = [];
        foreach (self::HIGH_WATER_TABLES as $table) {
            if (is_array($marks) && !array_key_exists($table, $marks)) {
                continue; // the table did not exist when the snapshot was taken
            }
            $mark = is_array($marks) ? $marks[$table] : null;
            if (!is_int($mark)) {
                $counts[$table] = null;
                continue;
            }
            try {
                $statement = $this->database->prepare("SELECT COUNT(*) FROM {$table} WHERE id > ?");
                $statement->execute([$mark]);
                $counts[$table] = (int)$statement->fetchColumn();
            } catch (Throwable $e) {
                $counts[$table] = null;
            }
        }
        return $counts;
    }

    /**
     * Makes a rollback that discards data loud: logs and raises a critical incident when rows were
     * (or may have been) written since the snapshot. Returns the operator notice, or null when the
     * rollback provably discarded no tracked rows.
     */
    private function reportDiscardedWrites(?array $discarded, string $context): ?string
    {
        if ($discarded === null) {
            return null;
        }
        $known = array_filter($discarded, static fn($count): bool => is_int($count));
        $total = array_sum($known);
        $unknown = array_keys(array_diff_key($discarded, $known));
        if ($total === 0 && $unknown === []) {
            return null;
        }
        $parts = [];
        foreach ($known as $table => $count) {
            if ($count > 0) $parts[] = "{$count} {$table} row(s)";
        }
        if ($unknown !== []) $parts[] = 'an unknown number of ' . implode('/', $unknown) . ' rows';
        $notice = "{$context} discarded " . implode(' and ', $parts) . ' written after the update snapshot;'
            . ' recover them from the pre-update data (orders, payments, payment provider records) before reopening the shop';
        if (class_exists('IncidentMonitor')) {
            IncidentMonitor::report('update.rollback_discarded_writes', 'update', 'critical', ['count' => $total]);
        }
        return $notice;
    }

    private function assertApplicable(array $report): void
    {
        if (!empty($report['blockers'])) {
            throw new RuntimeException('Update blocked: ' . implode('; ', $report['blockers']));
        }
        if (!empty($report['conflicts'])) {
            $details = [];
            foreach ($report['conflicts'] as $path => $reason) {
                $details[] = "{$path}: {$reason}";
            }
            throw new RuntimeException('Update conflicts: ' . implode('; ', $details));
        }
    }

    private function snapshotDirectory(string $id): string
    {
        return $this->stateDirectory . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $id;
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (is_link($path)) {
            throw new RuntimeException("Directory cannot be a symlink: {$path}");
        }
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
            throw new RuntimeException("Cannot create directory: {$path}");
        }
    }

    private function lock(): void
    {
        $path = $this->stateDirectory . DIRECTORY_SEPARATOR . '.update.lock';
        $this->lockHandle = fopen($path, 'c');
        if (!is_resource($this->lockHandle) || !flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($this->lockHandle)) {
                fclose($this->lockHandle);
            }
            $this->lockHandle = null;
            throw new RuntimeException('Another update process is already running');
        }
    }

    private function unlock(): void
    {
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
        }
        $this->lockHandle = null;
    }

    private function absolutePath(string $path): string
    {
        if ($path === '' || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new InvalidArgumentException('Update state directory is invalid');
        }
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path);
        if (!$absolute) {
            $path = $this->root . DIRECTORY_SEPARATOR . $path;
        }
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private function isInside(string $candidate, string $parent): bool
    {
        $candidate = strtolower(rtrim(str_replace('\\', '/', $candidate), '/'));
        $parent = strtolower(rtrim(str_replace('\\', '/', $parent), '/'));
        return $candidate === $parent || str_starts_with($candidate . '/', $parent . '/');
    }

    private function removeTree(string $path): void
    {
        FileSystem::removeTree($path);
    }
}
