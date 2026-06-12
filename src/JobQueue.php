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
            self::$jobsDir = $cfg['jobs_dir'] ?? __DIR__ . '/../jobs';
        }
        return self::$jobsDir;
    }

    public static function setDir(string $dir): void { self::$jobsDir = $dir; }

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
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        $file = $dir . '/' . sprintf('%010d', $priority) . '_' . $id . '.json';
        file_put_contents($file, json_encode($job, JSON_PRETTY_PRINT), LOCK_EX);

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
        foreach (array_slice($files, 0, $limit) as $file) {
            $job = json_decode(file_get_contents($file), true);
            if ($job) {
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
        $procDir = self::dir() . '/processing';
        if (!is_dir($procDir)) mkdir($procDir, 0750, true);

        $procFile = $procDir . '/' . basename($job['_file']);
        // Atomic claim: rename() is atomic on the same filesystem. If another
        // worker already moved this file out of the queue, the source no longer
        // exists and rename() fails — return '' so the caller skips the job
        // instead of double-processing it (F7, audit v1.12).
        if (!@rename($job['_file'], $procFile)) {
            return '';
        }
        return $procFile;
    }

    /**
     * Mark job as completed
     */
    public static function complete(string $procFile): void {
        $doneDir = self::dir() . '/completed';
        if (!is_dir($doneDir)) mkdir($doneDir, 0750, true);
        rename($procFile, $doneDir . '/' . basename($procFile));
    }

    /**
     * Mark job as failed or retry (with exponential backoff)
     */
    public static function fail(string $procFile, array $job, string $error): void {
        $job['attempts'] = ($job['attempts'] ?? 0) + 1;
        $job['last_error'] = $error;

        if ($job['attempts'] >= ($job['max_attempts'] ?? 3)) {
            $failDir = self::dir() . '/failed';
            if (!is_dir($failDir)) mkdir($failDir, 0750, true);
            file_put_contents($failDir . '/' . basename($procFile), json_encode($job, JSON_PRETTY_PRINT));
            if (!@unlink($procFile)) {
                error_log("JobQueue: failed to unlink processing file: {$procFile}");
            }
        } else {
            // Retry with exponential backoff: 4s, 16s, 64s...
            $delaySec = (int)pow(4, $job['attempts']);
            $job['retry_after'] = date('c', time() + $delaySec);
            $pendingDir = self::dir() . '/pending';
            file_put_contents($pendingDir . '/' . basename($procFile), json_encode($job, JSON_PRETTY_PRINT));
            if (!@unlink($procFile)) {
                error_log("JobQueue: failed to unlink processing file: {$procFile}");
            }
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
}
