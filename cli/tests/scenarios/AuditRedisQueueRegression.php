<?php

// RedisQueue (src/Queue.php) is the multi-node queue backend. Its claim, completion, lease renewal and
// expired-lease recovery are Lua scripts, so they only prove themselves against a real Redis: the test
// runs when ext-redis is loaded and TEST_REDIS_HOST names a disposable server (CI's Redis service), and
// BAREBONES_REQUIRE_REDIS_TEST=1 turns a missing server into a failure instead of a skip.
echo "\n--- RedisQueue: claim, complete, extendLease, expired-lease release ---\n";
$rqHost = (string)(getenv('TEST_REDIS_HOST') ?: '');
$rqRequired = getenv('BAREBONES_REQUIRE_REDIS_TEST') === '1';
if (!class_exists('Redis') || $rqHost === '') {
    if ($rqRequired) assert_true(false, 'RedisQueue: BAREBONES_REQUIRE_REDIS_TEST=1 needs ext-redis and TEST_REDIS_HOST');
    else print "  [SKIP] RedisQueue against a live Redis (load ext-redis and set TEST_REDIS_HOST)\n";
} else {
    $rqPrefix = 'bbe:test:' . getmypid() . ':' . bin2hex(random_bytes(4)) . ':';
    $rqConfig = ['host' => $rqHost, 'port' => (int)(getenv('TEST_REDIS_PORT') ?: 6379), 'prefix' => $rqPrefix, 'lease_seconds' => 30];
    $rqRedis = new Redis();
    $rqRedis->connect($rqConfig['host'], $rqConfig['port'], 2.0);
    $rqClaimOf = static fn(string $claim): string => explode(':', $claim)[1];
    try {
        $rqQueue = new RedisQueue($rqConfig);
        assert_equals('redis', $rqQueue->name(), 'RedisQueue: backend name');

        // Claim: a pending job is delivered once, and a second claim of the same job is refused.
        $rqId = $rqQueue->push('redis_probe', ['n' => 1], 3);
        $rqPulled = array_values(array_filter($rqQueue->pull(10), static fn(array $job): bool => $job['id'] === $rqId));
        assert_equals(1, count($rqPulled), 'RedisQueue: pull returns the pending job');
        assert_equals(['n' => 1], array_diff_key($rqPulled[0]['payload'] ?? [], ['_store_id' => 1, '_channel_id' => 1]), 'RedisQueue: payload round-trips');
        $rqClaim = $rqQueue->markProcessing($rqPulled[0]);
        assert_true((bool)preg_match('/^redis:[a-f0-9]{32}:[a-f0-9]{32}$/', $rqClaim), 'RedisQueue: markProcessing returns a claim token');
        assert_equals('', $rqQueue->markProcessing($rqPulled[0]), 'RedisQueue: a job already claimed cannot be claimed twice');
        assert_true(!in_array($rqId, array_column($rqQueue->pull(10), 'id'), true), 'RedisQueue: a claimed job is no longer pulled');
        assert_equals(['pending' => 0, 'processing' => 1, 'completed' => 0, 'failed' => 0], $rqQueue->stats(), 'RedisQueue: stats count the claimed job as processing');

        // extendLease: the owner renews; the lease end moves forward.
        $rqRedis->zAdd($rqPrefix . 'processing', time() + 5, $rqId);
        assert_true($rqQueue->extendLease($rqClaim), 'RedisQueue: the owner renews its lease');
        assert_true((int)$rqRedis->zScore($rqPrefix . 'processing', $rqId) >= time() + 25, 'RedisQueue: extendLease moves the lease end forward');
        $rqForged = 'redis:' . $rqId . ':' . str_repeat('0', 32);
        assert_true(!$rqQueue->extendLease($rqForged), 'RedisQueue: a foreign token cannot renew the lease');

        // complete: the owner completes once; a stale or repeated completion is refused.
        $rqQueue->complete($rqClaim);
        assert_equals('completed', $rqRedis->hGet($rqPrefix . 'job:' . $rqId, 'state'), 'RedisQueue: complete marks the job completed');
        assert_equals(['pending' => 0, 'processing' => 0, 'completed' => 1, 'failed' => 0], $rqQueue->stats(), 'RedisQueue: stats count the completed job');
        $rqRefused = false;
        try { $rqQueue->complete($rqClaim); } catch (RuntimeException $e) { $rqRefused = true; }
        assert_true($rqRefused, 'RedisQueue: completing a claim twice is refused');
        assert_true(!$rqQueue->extendLease($rqClaim), 'RedisQueue: a completed job has no lease to renew');

        // Expired lease: the worker died mid-job. The next pull releases the job back to pending as an
        // attempt, the dead worker's claim is void, and another worker can claim it.
        $rqId = $rqQueue->push('redis_probe', ['n' => 2]);
        $rqJob = array_values(array_filter($rqQueue->pull(10), static fn(array $job): bool => $job['id'] === $rqId))[0] ?? [];
        $rqClaimA = $rqQueue->markProcessing($rqJob);
        $rqRedis->zAdd($rqPrefix . 'processing', time() - 1, $rqId);
        $rqRetry = array_values(array_filter($rqQueue->pull(10), static fn(array $job): bool => $job['id'] === $rqId))[0] ?? [];
        assert_equals(1, (int)($rqRetry['attempts'] ?? 0), 'RedisQueue: an expired lease is released to pending and counted as an attempt');
        assert_equals('retry', $rqRedis->hGet($rqPrefix . 'job:' . $rqId, 'state'), 'RedisQueue: the released job is marked for retry');
        assert_true(!$rqQueue->extendLease($rqClaimA), 'RedisQueue: the dead worker cannot renew a released lease');
        $rqClaimB = $rqQueue->markProcessing($rqRetry);
        assert_true($rqClaimB !== '' && $rqClaimB !== $rqClaimA && $rqClaimOf($rqClaimB) === $rqId, 'RedisQueue: another worker re-claims the released job');
        $rqRefused = false;
        try { $rqQueue->complete($rqClaimA); } catch (RuntimeException $e) { $rqRefused = true; }
        assert_true($rqRefused, 'RedisQueue: the dead worker cannot complete the re-claimed job');
        $rqQueue->complete($rqClaimB);
        assert_equals('completed', $rqRedis->hGet($rqPrefix . 'job:' . $rqId, 'state'), 'RedisQueue: the new owner completes the job');

        // An expired lease at max_attempts dead-letters the job instead of re-delivering it.
        $rqId = $rqQueue->push('redis_probe', ['n' => 3]);
        $rqRedis->hSet($rqPrefix . 'job:' . $rqId, 'attempts', '2');
        $rqJob = array_values(array_filter($rqQueue->pull(10), static fn(array $job): bool => $job['id'] === $rqId))[0] ?? [];
        $rqQueue->markProcessing($rqJob);
        $rqRedis->zAdd($rqPrefix . 'processing', time() - 1, $rqId);
        assert_true(!in_array($rqId, array_column($rqQueue->pull(10), 'id'), true), 'RedisQueue: an expired lease at max_attempts is not re-delivered');
        $rqFailures = array_values(array_filter($rqQueue->failures(10), static fn(array $row): bool => $row['id'] === $rqId));
        assert_true(str_contains((string)($rqFailures[0]['last_error'] ?? ''), 'lease expired'), 'RedisQueue: the dead-lettered job records why it failed');

        // Retention: completed job hashes expire (completed_ttl_seconds) and the daily cleanup trims the
        // completed index by age; before, both grew forever and RetentionCleanup never looked at Redis.
        $rqTtlQueue = new RedisQueue($rqConfig + ['completed_ttl_seconds' => 120]);
        $rqId = $rqTtlQueue->push('redis_probe', ['n' => 4]);
        $rqJob = array_values(array_filter($rqTtlQueue->pull(10), static fn(array $job): bool => $job['id'] === $rqId))[0] ?? [];
        $rqTtlQueue->complete($rqTtlQueue->markProcessing($rqJob));
        $rqTtl = (int)$rqRedis->ttl($rqPrefix . 'job:' . $rqId);
        assert_true($rqTtl > 0 && $rqTtl <= 120, 'RedisQueue: a completed job hash expires after completed_ttl_seconds');
        $rqDefaultTtl = (int)$rqRedis->ttl($rqPrefix . 'job:' . $rqClaimOf($rqClaimB));
        assert_true($rqDefaultTtl > 120 && $rqDefaultTtl <= RedisQueue::COMPLETED_TTL_SECONDS, 'RedisQueue: the default expiry is 7 days');
        $rqRedis->zAdd($rqPrefix . 'completed', time() - 8 * 86400, $rqId);
        JobQueue::useBackend($rqTtlQueue);
        try {
            $rqDeleted = RetentionCleanup::run(['completed_jobs_days' => 7]);
        } finally {
            JobQueue::useBackend(null); // back to the file queue the suite uses
        }
        assert_true(($rqDeleted['redis_jobs'] ?? 0) === 1 && $rqRedis->zScore($rqPrefix . 'completed', $rqId) === false && !$rqRedis->exists($rqPrefix . 'job:' . $rqId),
            'RedisQueue: retention cleanup trims completed jobs older than completed_jobs_days from the index and deletes their hash');
        assert_true($rqRedis->zScore($rqPrefix . 'completed', $rqClaimOf($rqClaimB)) !== false, 'RedisQueue: younger completed jobs stay');
        // R10: failed jobs were never trimmed; past failed_jobs_days (90) they go too.
        $rqDeadId = (string)($rqFailures[0]['id'] ?? '');
        $rqRedis->zAdd($rqPrefix . 'failed', time() - 91 * 86400, $rqDeadId);
        JobQueue::useBackend($rqTtlQueue);
        try {
            $rqDeleted = RetentionCleanup::run([]);
        } finally {
            JobQueue::useBackend(null);
        }
        assert_true($rqDeadId !== '' && $rqRedis->zScore($rqPrefix . 'failed', $rqDeadId) === false && !$rqRedis->exists($rqPrefix . 'job:' . $rqDeadId),
            'R10 RedisQueue: retention cleanup removes failed jobs older than failed_jobs_days (they were kept forever)');

        // Delayed push (rate-limited mail retries): not pulled before its time.
        $rqId = $rqTtlQueue->pushAt('redis_probe', ['n' => 5], time() + 600);
        assert_true(!in_array($rqId, array_column($rqTtlQueue->pull(100), 'id'), true), 'RedisQueue: a delayed job is not pulled before its time');

        // R10: priority. A low-priority job that became available a second earlier was pulled before
        // every higher-priority job (score available_at * 100 + priority); DatabaseQueue orders by priority first.
        $rqPrio = new RedisQueue(['prefix' => $rqPrefix . 'prio:'] + $rqConfig);
        $rqLow = $rqPrio->pushAt('redis_probe', ['n' => 'low'], time() + 1, 9);
        sleep(2);
        $rqHigh = $rqPrio->push('redis_probe', ['n' => 'high'], 1);
        assert_equals([$rqHigh, $rqLow], array_column($rqPrio->pull(10), 'id'), 'R10 RedisQueue: a higher-priority job is pulled before an older low-priority one');
        // A failed attempt waits out its backoff outside the ready set, then rejoins at its priority.
        $rqClaimHigh = $rqPrio->markProcessing(['id' => $rqHigh]);
        $rqPrio->fail($rqClaimHigh, [], 'r10 transient');
        assert_true($rqRedis->zScore($rqPrefix . 'prio:delayed', $rqHigh) !== false && !in_array($rqHigh, array_column($rqPrio->pull(10), 'id'), true),
            'R10 RedisQueue: a retried job is not pulled before its backoff');
        assert_equals(['pending' => 2, 'processing' => 0, 'completed' => 0, 'failed' => 0], $rqPrio->stats(), 'R10 RedisQueue: stats count delayed jobs as pending');
    } finally {
        $rqKeys = $rqRedis->keys($rqPrefix . '*');
        if ($rqKeys) $rqRedis->del($rqKeys);
        $rqRedis->close();
    }
}
