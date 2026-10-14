<?php
require_once __DIR__ . '/Queue.php';

/**
 * BareBones Shop — Configurable Job Queue
 * JSON files in jobs/ directory. Zero external dependencies.
 */
class JobQueue {
    /** Same default as the durable backends' lease_seconds; worker runs are capped at ~55 s. */
    private const FILE_LEASE_SECONDS = 300;
    private static ?string $jobsDir = null;
    private static ?QueueBackend $backendOverride = null;
    private static ?QueueBackend $configuredBackend = null;
    private static bool $forceFile = false;

    private static function backend(): ?QueueBackend {
        if (self::$forceFile) return null;
        if (self::$backendOverride !== null) return self::$backendOverride;
        if (self::$configuredBackend !== null) return self::$configuredBackend;
        $cfg = require __DIR__ . '/../config.php';
        $queue = is_array($cfg['queue'] ?? null) ? $cfg['queue'] : [];
        $name = strtolower((string)($queue['backend'] ?? 'file'));
        self::$configuredBackend = match ($name) {
            'database', 'db' => new DatabaseQueue(Db::get(), (int)($queue['lease_seconds'] ?? 300)),
            'redis' => new RedisQueue(is_array($queue['redis'] ?? null) ? $queue['redis'] + ['lease_seconds' => $queue['lease_seconds'] ?? 300] : []),
            'file' => null,
            default => throw new RuntimeException('Unknown queue backend: ' . $name),
        };
        return self::$configuredBackend;
    }

    private static function dir(): string {
        if (!self::$jobsDir) {
            $cfg = require __DIR__ . '/../config.php';
            self::$jobsDir = self::validatePrivateDir((string)($cfg['jobs_dir'] ?? dirname(__DIR__, 2) . '/bareboneseshop-private/jobs'));
        }
        return self::$jobsDir;
    }

    public static function setDir(string $dir): void {
        self::$jobsDir = self::validatePrivateDir($dir);
        self::$forceFile = true;
    }

    public static function useBackend(?QueueBackend $backend): void {
        self::$backendOverride = $backend;
        self::$forceFile = $backend === null;
    }

    public static function resetBackend(): void {
        self::$backendOverride = null;
        self::$configuredBackend = null;
        self::$forceFile = false;
    }

    public static function backendName(): string { return self::backend()?->name() ?? 'file'; }
    public static function supportsConcurrentWorkers(): bool { return self::backend() !== null; }

    /**
     * Push a new job to the queue
     */
    public static function push(string $type, array $payload, int $priority = 5): string {
        if ($backend = self::backend()) return $backend->push($type, $payload, $priority);
        if (class_exists('StoreContext')) {
            $payload['_store_id'] ??= StoreContext::current()->id();
            $payload['_channel_id'] ??= StoreContext::current()->channel();
        }
        $id = time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $job = [
            'id'           => $id,
            'type'         => $type,
            'priority'     => $priority,
            'payload'      => $payload,
            'created_at'   => date('c'),
            'attempts'     => 0,
            'max_attempts' => 3,
            'correlation_id' => Logger::correlationId(),
        ];

        $dir = self::dir() . '/pending';
        self::ensureDirectory($dir);

        $file = $dir . '/' . sprintf('%010d', $priority) . '_' . $id . '.json';
        self::writeJson($file, $job, true);

        return $id;
    }

    /**
     * Pull pending jobs (sorted by priority + timestamp)
     */
    public static function pull(int $limit = 10): array {
        if ($backend = self::backend()) return $backend->pull($limit);
        self::recoverStale();
        $dir = self::dir() . '/pending';
        if (!is_dir($dir)) return [];

        $files = glob($dir . '/*.json');
        sort($files); // sorts by filename = priority + timestamp

        $jobs = [];
        foreach (array_slice($files, 0, max(0, $limit)) as $file) {
            if (is_link($file) || !is_file($file)) continue;
            $raw = file_get_contents($file);
            $job = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($job)) {
                $job['_file'] = $file;
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * Check if job is ready to process (respects exponential backoff)
     */
    public static function isReady(array $job): bool {
        if (empty($job['retry_after'])) return true;
        return strtotime($job['retry_after'] . ' UTC') <= time();
    }

    /**
     * Move job to processing
     */
    public static function markProcessing(array $job): string {
        if ($backend = self::backend()) return $backend->markProcessing($job);
        $source = self::assertQueueFile((string)($job['_file'] ?? ''), 'pending', true);
        $procDir = self::dir() . '/processing';
        self::ensureDirectory($procDir);

        $procFile = $procDir . '/' . basename($source);
        // Atomic claim: rename() is atomic on the same filesystem. If another
        // worker already moved this file out of the queue, the source no longer
        // exists and rename() fails — return '' so the caller skips the job
        // instead of double-processing it (F7, audit v1.12).
        if (!@rename($source, $procFile)) {
            return '';
        }
        touch($procFile); // mtime = claim time; recoverStale() measures the lease from it
        return $procFile;
    }

    /**
     * A worker that dies mid-job (fatal error, OOM, kill) leaves the file in processing/.
     * After the lease it counts as a failed attempt: retried with backoff, or moved to
     * failed/ (dead letter) once max_attempts is reached — never retried forever.
     */
    private static function recoverStale(): void {
        $dir = self::dir() . '/processing';
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime > time() - self::FILE_LEASE_SECONDS) continue;
            $raw = @file_get_contents($file);
            $job = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($job)) continue;
            self::fail($file, $job, 'Worker stopped while processing the job (lease expired)');
        }
    }

    /**
     * Mark job as completed
     */
    public static function complete(string $procFile): void {
        if ($backend = self::backend()) { $backend->complete($procFile); return; }
        $procFile = self::assertQueueFile($procFile, 'processing');
        $doneDir = self::dir() . '/completed';
        self::ensureDirectory($doneDir);
        if (!rename($procFile, $doneDir . '/' . basename($procFile))) {
            throw new RuntimeException('JobQueue: cannot complete job');
        }
    }

    /**
     * Mark job as failed or retry (with exponential backoff)
     */
    public static function fail(string $procFile, array $job, string $error): void {
        if ($backend = self::backend()) { $backend->fail($procFile, $job, $error); return; }
        $procFile = self::assertQueueFile($procFile, 'processing');
        $job['attempts'] = ($job['attempts'] ?? 0) + 1;
        $job['last_error'] = $error;

        if ($job['attempts'] >= ($job['max_attempts'] ?? 3)) {
            $destinationDir = self::dir() . '/failed';
        } else {
            // Retry with exponential backoff: 4s, 16s, 64s...
            $delaySec = (int)pow(4, $job['attempts']);
            $job['retry_after'] = date('c', time() + $delaySec);
            $destinationDir = self::dir() . '/pending';
        }
        self::ensureDirectory($destinationDir);
        self::writeJson($procFile, $job);
        if (!rename($procFile, $destinationDir . '/' . basename($procFile))) {
            throw new RuntimeException('JobQueue: failed to move updated processing file');
        }
    }

    /**
     * Queue statistics
     */
    public static function stats(): array {
        if ($backend = self::backend()) return $backend->stats();
        $dir = self::dir();
        return [
            'pending'    => count(glob($dir . '/pending/*.json') ?: []),
            'processing' => count(glob($dir . '/processing/*.json') ?: []),
            'completed'  => count(glob($dir . '/completed/*.json') ?: []),
            'failed'     => count(glob($dir . '/failed/*.json') ?: []),
        ];
    }

    public static function failures(int $limit = 20): array {
        if ($backend = self::backend()) return $backend->failures($limit);
        $files = glob(self::dir() . '/failed/*.json') ?: [];
        rsort($files);
        $result = [];
        foreach (array_slice($files, 0, max(1, min(100, $limit))) as $file) {
            if (is_link($file) || !is_file($file)) continue;
            $job = json_decode((string)file_get_contents($file), true);
            if (!is_array($job)) continue;
            $result[] = [
                'id' => (string)($job['id'] ?? ''),
                'store_id' => (string)($job['payload']['_store_id'] ?? 'default'),
                'type' => (string)($job['type'] ?? 'unknown'),
                'attempts' => (int)($job['attempts'] ?? 0),
                'last_error' => substr((string)($job['last_error'] ?? ''), 0, 2000),
                'correlation_id' => (string)($job['correlation_id'] ?? ''),
                'updated_at' => (string)($job['retry_after'] ?? $job['created_at'] ?? ''),
            ];
        }
        return $result;
    }

    private static function validatePrivateDir(string $dir): string {
        if ($dir === '' || preg_match('/[\x00-\x1F\x7F]/', $dir)) {
            throw new RuntimeException('jobs_dir is invalid');
        }
        $absolute = str_starts_with($dir, '/') || str_starts_with($dir, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $dir) === 1;
        if (!$absolute) {
            throw new RuntimeException('jobs_dir must be an absolute path outside the application root');
        }

        $appRoot = rtrim(str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__)), '/');
        $probe = $dir;
        $suffix = [];
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) break;
            array_unshift($suffix, basename($probe));
            $probe = $parent;
        }
        $canonicalCandidate = realpath($probe);
        if ($canonicalCandidate === false) {
            throw new RuntimeException('jobs_dir parent cannot be resolved');
        }
        foreach ($suffix as $segment) {
            if ($segment === '.' || $segment === '') continue;
            $canonicalCandidate = $segment === '..'
                ? dirname($canonicalCandidate)
                : $canonicalCandidate . DIRECTORY_SEPARATOR . $segment;
        }
        $comparisonCandidate = rtrim(str_replace('\\', '/', $canonicalCandidate), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $appRoot = strtolower($appRoot);
            $comparisonCandidate = strtolower($comparisonCandidate);
        }
        if ($comparisonCandidate === $appRoot || str_starts_with($comparisonCandidate, $appRoot . '/')) {
            throw new RuntimeException('jobs_dir must be outside the application root');
        }
        return rtrim($canonicalCandidate, "/\\");
    }

    private static function ensureDirectory(string $dir): void {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("JobQueue: cannot create directory {$dir}");
        }
        $canonical = realpath($dir);
        $root = realpath(self::dir());
        if ($canonical === false || $root === false) {
            throw new RuntimeException('JobQueue: cannot resolve queue directory');
        }
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $candidate = rtrim(str_replace('\\', '/', $canonical), '/') . '/';
        if (DIRECTORY_SEPARATOR === '\\') {
            $prefix = strtolower($prefix);
            $candidate = strtolower($candidate);
        }
        if (!str_starts_with($candidate, $prefix)) {
            throw new RuntimeException('JobQueue: directory escapes jobs_dir');
        }
    }

    private static function writeJson(string $file, array $job, bool $exclusive = false): void {
        $json = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $target = $exclusive ? $file : $file . '.tmp-' . bin2hex(random_bytes(6));
        $handle = @fopen($target, 'x');
        if ($handle === false) {
            throw new RuntimeException("JobQueue: cannot open {$target}");
        }
        try {
            try {
                if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                    throw new RuntimeException("JobQueue: incomplete write to {$target}");
                }
            } finally {
                fclose($handle);
            }
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }
        @chmod($target, 0640);
        if (!$exclusive && !rename($target, $file)) {
            @unlink($target);
            throw new RuntimeException("JobQueue: cannot atomically replace {$file}");
        }
    }

    private static function assertQueueFile(string $file, string $state, bool $allowMissing = false): string {
        $stateDir = realpath(self::dir() . '/' . $state);
        $parentDir = $file !== '' ? realpath(dirname($file)) : false;
        $sameParent = $stateDir !== false && $parentDir !== false
            && (DIRECTORY_SEPARATOR === '\\' ? strcasecmp($parentDir, $stateDir) === 0 : $parentDir === $stateDir);
        if (!$sameParent || !preg_match('/^[0-9]{10}_[0-9]+_[0-9a-f]{8}\.json$/', basename($file))) {
            throw new RuntimeException('JobQueue: queue file escapes expected state directory');
        }
        if (!file_exists($file)) {
            if ($allowMissing) return $stateDir . DIRECTORY_SEPARATOR . basename($file);
            throw new RuntimeException('JobQueue: invalid queue file');
        }
        if (is_link($file) || !is_file($file)) {
            throw new RuntimeException('JobQueue: invalid queue file');
        }
        $canonical = realpath($file);
        if ($canonical === false) {
            throw new RuntimeException('JobQueue: queue file cannot be resolved');
        }
        return $canonical;
    }
}
