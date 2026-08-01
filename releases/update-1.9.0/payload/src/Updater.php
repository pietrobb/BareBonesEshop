<?php

final class Updater
{
    private const STATE_FILE = 'installed.json';
    private const PROTECTED_FILES = ['.env', 'config.php', '.barebones-version.json'];
    private const PROTECTED_PREFIXES = [
        '.git/', '.worktrees/', '.claude/', '.playwright-mcp/',
        'cache/', 'db/', 'jobs/', 'logs/', 'storage/', 'uploads/',
    ];

    private string $root;
    private string $stateDirectory;
    private ?PDO $database;
    private $migrationRunner;
    private $lockHandle = null;

    public function __construct(
        string $root,
        string $stateDirectory,
        ?PDO $database = null,
        ?callable $migrationRunner = null
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
                if ($driver !== 'sqlite') {
                    $blockers[] = 'Automatic rollback for migration packages currently requires SQLite; MySQL code-only packages remain supported';
                }
                if ($this->migrationRunner === null) {
                    $blockers[] = 'Package contains migrations but no migration runner is configured';
                }
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
        try {
            $report = $this->check($package);
            $this->assertApplicable($report);
            $snapshot = $this->createSnapshot($package, $report, 'pre-update');
            $report = $this->check($package);
            $this->assertApplicable($report);

            foreach ($package->entries() as $entry) {
                if (!in_array($entry['path'], $report['ready'], true)) {
                    continue;
                }
                $this->assertEntryStillApplicable($entry);
                if ($entry['action'] === 'write') {
                    $this->installFile($package->payloadPath($entry['path']), $entry['path']);
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
            ];
        } catch (Throwable $e) {
            if (is_array($snapshot) && isset($snapshot['id'])) {
                try {
                    $this->restoreSnapshot($snapshot['id'], true);
                    $this->setSnapshotStatus($snapshot['id'], 'auto-rolled-back', $e->getMessage());
                } catch (Throwable $rollbackError) {
                    throw new RuntimeException(
                        'Update failed: ' . $e->getMessage() . '; automatic rollback also failed: ' . $rollbackError->getMessage(),
                        0,
                        $e
                    );
                }
            }
            throw $e;
        } finally {
            $this->unlock();
        }
    }

    public function rollback(string $snapshotId, bool $force = false): array
    {
        $this->lock();
        try {
            $result = $this->restoreSnapshot($snapshotId, $force);
            $this->setSnapshotStatus($snapshotId, 'rolled-back');
            return $result;
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

                if ($driver === 'sqlite' && $probeDatabaseBackup) {
                    $probeDirectory = $this->stateDirectory . DIRECTORY_SEPARATOR . '.doctor-' . bin2hex(random_bytes(6));
                    try {
                        $this->ensureDirectory($probeDirectory, 0750);
                        $metadata = $this->snapshotSqliteDatabase($probeDirectory);
                        $backupPath = $probeDirectory . DIRECTORY_SEPARATOR . 'database.sqlite';
                        $probe = new PDO('sqlite:' . $backupPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                        $integrity = (string)$probe->query('PRAGMA integrity_check')->fetchColumn();
                        $probe = null;
                        if ($integrity !== 'ok' || !hash_equals((string)$metadata['sha256'], (string)hash_file('sha256', $backupPath))) {
                            throw new RuntimeException("SQLite backup probe failed integrity verification: {$integrity}");
                        }
                        $add('ok', 'database_backup', 'SQLite snapshot creation, checksum, and integrity probe passed');
                    } catch (Throwable $e) {
                        $add('fail', 'database_backup', $e->getMessage());
                    } finally {
                        $this->removeTree($probeDirectory);
                    }
                } elseif ($driver === 'sqlite') {
                    $add('fail', 'database_backup', 'SQLite backup capability was not verified because the probe was skipped');
                } elseif ($driver === 'mysql') {
                    $add('warn', 'database_backup', 'MySQL supports code-only updates; migration updates remain blocked without verified dump/restore');
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
                $metadata['database'] = $this->snapshotSqliteDatabase($snapshotDirectory);
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
        return [
            'driver' => 'sqlite',
            'live_path' => $livePath,
            'sha256' => hash_file('sha256', $backupPath),
            'size_bytes' => filesize($backupPath),
        ];
    }

    private function restoreSnapshot(string $snapshotId, bool $force): array
    {
        if (!preg_match('/^\d{14}-[0-9a-f]{12}$/', $snapshotId)) {
            throw new InvalidArgumentException('Invalid update snapshot ID');
        }
        $directory = $this->snapshotDirectory($snapshotId);
        $metadata = $this->readJson($directory . DIRECTORY_SEPARATOR . 'snapshot.json');
        if (!$metadata || ($metadata['id'] ?? null) !== $snapshotId || !is_array($metadata['files'] ?? null)) {
            throw new RuntimeException("Update snapshot not found or invalid: {$snapshotId}");
        }

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

        if (is_array($metadata['database'] ?? null)) {
            $this->restoreSqliteDatabase($directory, $metadata['database']);
        }
        return ['snapshot' => $snapshotId, 'restored_files' => count($metadata['files'])];
    }

    private function restoreSqliteDatabase(string $snapshotDirectory, array $database): void
    {
        if (($database['driver'] ?? null) !== 'sqlite') {
            throw new RuntimeException('Unsupported database snapshot driver');
        }
        $source = $snapshotDirectory . DIRECTORY_SEPARATOR . 'database.sqlite';
        $livePath = (string)($database['live_path'] ?? '');
        $expected = (string)($database['sha256'] ?? '');
        if (!is_file($source) || !preg_match('/^[0-9a-f]{64}$/', $expected)
            || !hash_equals($expected, (string)hash_file('sha256', $source))) {
            throw new RuntimeException('SQLite rollback snapshot failed integrity verification');
        }
        $this->database = null;
        if (class_exists('Db', false)) {
            Db::reset();
        }
        @unlink($livePath . '-wal');
        @unlink($livePath . '-shm');
        $this->installAbsoluteFile($source, $livePath);
        @unlink($livePath . '-wal');
        @unlink($livePath . '-shm');
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
        if (!is_file($source) || is_link($source)) {
            throw new RuntimeException("Update source is not a regular file: {$source}");
        }
        $this->ensureDirectory(dirname($target), 0755);
        $temporary = dirname($target) . DIRECTORY_SEPARATOR . '.' . basename($target)
            . '.update-' . bin2hex(random_bytes(6)) . '.tmp';
        if (!copy($source, $temporary)) {
            throw new RuntimeException("Cannot stage update file: {$target}");
        }
        @chmod($temporary, fileperms($source) & 0777);
        $old = null;
        try {
            if (file_exists($target)) {
                if (!is_file($target) || is_link($target)) {
                    throw new RuntimeException("Update target is not a regular file: {$target}");
                }
                $old = dirname($target) . DIRECTORY_SEPARATOR . '.' . basename($target)
                    . '.old-' . bin2hex(random_bytes(6));
                if (!rename($target, $old)) {
                    throw new RuntimeException("Cannot move current update target: {$target}");
                }
            }
            if (!rename($temporary, $target)) {
                if ($old !== null) {
                    @rename($old, $target);
                }
                throw new RuntimeException("Cannot atomically install update file: {$target}");
            }
            if ($old !== null) {
                @unlink($old);
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

    private function setSnapshotStatus(string $id, string $status, ?string $error = null): void
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
        $this->writeJsonAtomic($path, $metadata);
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
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
