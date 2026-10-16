<?php
/**
 * Daily housekeeping of bookkeeping tables that otherwise grow without bound:
 * processed webhook events, replayable API responses, delivered outbox events (with their
 * delivery attempts) and completed queue jobs (database rows, Redis index entries, job files).
 * Dead/failed rows stay for the operator.
 */
final class RetentionCleanup
{
    public const INTERVAL_SECONDS = 86400;

    /**
     * Queue one `cleanup` job when the last one was queued more than a day ago. The marker is
     * claimed under an exclusive lock, so concurrent workers queue it once.
     */
    public static function enqueueIfDue(string $markerFile, ?int $now = null): bool
    {
        $now ??= time();
        $dir = dirname($markerFile);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('RetentionCleanup: cannot create marker directory');
        }
        $handle = fopen($markerFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException('RetentionCleanup: cannot open marker file');
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) return false;
            $last = (int)trim((string)stream_get_contents($handle));
            if ($last > 0 && $now - $last < self::INTERVAL_SECONDS) return false;
            JobQueue::push('cleanup', [], 9);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)$now);
            fflush($handle);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,int> deleted rows/files per kind */
    public static function run(array $cfg = [], ?int $now = null): array
    {
        $now ??= time();
        $db = Db::get();
        $cutoff = static fn(int $days): string => gmdate('Y-m-d H:i:s', $now - $days * 86400);
        $deleted = [
            'processed_events' => Idempotency::cleanup((int)($cfg['processed_events_days'] ?? 7)),
            'api_idempotency'  => ApiAuth::cleanupIdempotency((int)($cfg['api_idempotency_days'] ?? 30)),
        ];

        $outboxCutoff = $cutoff((int)($cfg['outbox_delivered_days'] ?? 30));
        $db->prepare("DELETE FROM webhook_deliveries WHERE event_id IN
            (SELECT id FROM event_outbox WHERE status = 'delivered' AND updated_at < :cutoff)")
            ->execute([':cutoff' => $outboxCutoff]);
        $outbox = $db->prepare("DELETE FROM event_outbox WHERE status = 'delivered' AND updated_at < :cutoff");
        $outbox->execute([':cutoff' => $outboxCutoff]);
        $deleted['event_outbox'] = $outbox->rowCount();

        $jobs = $db->prepare("DELETE FROM queue_jobs WHERE state = 'completed' AND completed_at < :cutoff");
        $jobs->execute([':cutoff' => $cutoff((int)($cfg['completed_jobs_days'] ?? 7))]);
        $deleted['queue_jobs'] = $jobs->rowCount();

        // Redis keeps completed jobs outside the database: hashes expire on completion, the index is trimmed here.
        $backend = JobQueue::activeBackend();
        $deleted['redis_jobs'] = $backend instanceof RedisQueue
            ? $backend->trimCompleted((int)($cfg['completed_jobs_days'] ?? 7) * 86400, $now)
            : 0;

        $deleted['job_files'] = 0;
        $completedDir = (string)($cfg['jobs_dir'] ?? '') . '/completed';
        if (($cfg['jobs_dir'] ?? '') !== '' && is_dir($completedDir)) {
            $fileCutoff = $now - (int)($cfg['completed_jobs_days'] ?? 7) * 86400;
            foreach (glob($completedDir . '/*.json') ?: [] as $file) {
                if (filemtime($file) < $fileCutoff && @unlink($file)) $deleted['job_files']++;
            }
        }
        return $deleted;
    }
}
