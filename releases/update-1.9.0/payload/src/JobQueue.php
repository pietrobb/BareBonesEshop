<?php
/**
 * BareBones Shop — File-based Job Queue
 * JSON files in jobs/ directory. Zero external dependencies.
 */
class JobQueue {
    private static ?string $jobsDir = null;

    private static function dir(): string {
        if (!self::$jobsDir) {
            $cfg = require __DIR__ . '/../config.php';
            self::$jobsDir = self::validatePrivateDir((string)($cfg['jobs_dir'] ?? dirname(__DIR__, 2) . '/bareboneseshop-private/jobs'));
        }
        return self::$jobsDir;
    }

    public static function setDir(string $dir): void { self::$jobsDir = self::validatePrivateDir($dir); }

    /**
     * Push a new job to the queue
     */
    public static function push(string $type, array $payload, int $priority = 5): string {
        $id = time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $job = [
            'id'           => $id,
            'type'         => $type,
            'priority'     => $priority,
            'payload'      => $payload,
            'created_at'   => date('c'),
            'attempts'     => 0,
            'max_attempts' => 3,
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
        return strtotime($job['retry_after']) <= time();
    }

    /**
     * Move job to processing
     */
    public static function markProcessing(array $job): string {
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
        return $procFile;
    }

    /**
     * Mark job as completed
     */
    public static function complete(string $procFile): void {
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
        $dir = self::dir();
        return [
            'pending'    => count(glob($dir . '/pending/*.json') ?: []),
            'processing' => count(glob($dir . '/processing/*.json') ?: []),
            'completed'  => count(glob($dir . '/completed/*.json') ?: []),
            'failed'     => count(glob($dir . '/failed/*.json') ?: []),
        ];
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
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
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
        $handle = fopen($target, 'x');
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
