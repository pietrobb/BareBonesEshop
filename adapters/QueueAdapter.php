<?php
/**
 * BareBones Shop — Queue Adapter Interface
 * Default: FileQueue (jobs/ directory — flat JSON files)
 * Optional: DbQueue, RedisQueue
 */
interface QueueAdapter {
    /** Push a job to the queue, returns job ID */
    public function push(string $type, array $payload, int $priority = 5): string;

    /** Pull pending jobs for processing */
    public function pull(int $limit = 10): array;

    /** Mark job as completed */
    public function complete(string $jobId): void;

    /** Mark job as failed */
    public function fail(string $jobId, string $error): void;

    /** Retry a failed job */
    public function retry(string $jobId): void;

    /** Queue statistics: pending/processing/completed/failed counts */
    public function stats(): array;
}
