<?php

// CR-M9: a job that runs longer than its lease must renew it, or a second worker re-runs it.
// CR-M10: file-queue jobs in backoff must not be returned by pull(), or the worker spins at
// 100% CPU and ready jobs queued behind them starve.
echo "\n--- CR-M9/M10: job lease renewal and file-queue backoff ---\n";
$jlRoot = test_fixture_path('job-lease');
mkdir($jlRoot);
$jlDirProp = new ReflectionProperty(JobQueue::class, 'jobsDir');
$jlOldDir = $jlDirProp->getValue();
$jlForceProp = new ReflectionProperty(JobQueue::class, 'forceFile');
$jlOldForce = $jlForceProp->getValue();
$jlDb = Db::get();
$jlClaimedAt = static function (string $claim) use ($jlDb): int {
    $stmt = $jlDb->prepare('SELECT claimed_at FROM queue_jobs WHERE id = :id');
    $stmt->execute([':id' => explode(':', $claim)[1]]);
    return (int)strtotime($stmt->fetchColumn() . ' UTC');
};
$jlBackdate = static function (string $claim, int $seconds) use ($jlDb, $jlClaimedAt): void {
    $jlDb->prepare('UPDATE queue_jobs SET claimed_at = :at WHERE id = :id')
        ->execute([':at' => gmdate('Y-m-d H:i:s', $jlClaimedAt($claim) - $seconds), ':id' => explode(':', $claim)[1]]);
};
try {
    // --- M9, database backend (lease 30 s) ---
    JobQueue::useBackend(new DatabaseQueue($jlDb, 30));
    $jlId = JobQueue::push('m9_long_job', ['n' => 1]);
    $jlJob = array_values(array_filter(JobQueue::pull(100), static fn(array $j): bool => $j['id'] === $jlId))[0] ?? [];
    $jlClaim = JobQueue::markProcessing($jlJob);
    assert_true($jlClaim !== '', 'CR-M9 database: worker A claims the long job');
    assert_true(JobQueue::extendLease($jlClaim), 'CR-M9 database: renewing in the same second as the claim still reports ownership');

    // Worker A runs for 60 s (twice the lease), heartbeating between units of work.
    JobQueue::beginWork($jlClaim);
    for ($jlStep = 0; $jlStep < 4; $jlStep++) {
        $jlBackdate($jlClaim, 15);
        JobQueue::heartbeat(true);
        JobQueue::pull(100); // worker B's pass recovers expired leases
    }
    assert_true($jlClaimedAt($jlClaim) >= time() - 2, 'CR-M9 database: heartbeat moves the lease forward');
    $jlState = $jlDb->prepare('SELECT state, attempts FROM queue_jobs WHERE id = :id');
    $jlState->execute([':id' => $jlId]);
    assert_equals(['state' => 'processing', 'attempts' => 0], array_map(static fn($v) => is_numeric($v) ? (int)$v : $v, $jlState->fetch(PDO::FETCH_ASSOC)), 'CR-M9 database: a heartbeating job running past its lease is not re-delivered');
    assert_true(!in_array($jlId, array_column(JobQueue::pull(100), 'id'), true), 'CR-M9 database: worker B cannot pull the job worker A is still running');
    JobQueue::complete($jlClaim);
    JobQueue::endWork();

    // Worker A stalls without heartbeat; B takes the job over; A's next heartbeat must abort it.
    $jlId = JobQueue::push('m9_long_job', ['n' => 2]);
    $jlJob = array_values(array_filter(JobQueue::pull(100), static fn(array $j): bool => $j['id'] === $jlId))[0] ?? [];
    $jlClaimA = JobQueue::markProcessing($jlJob);
    JobQueue::beginWork($jlClaimA);
    $jlBackdate($jlClaimA, 31);
    JobQueue::pull(100);
    $jlDb->prepare("UPDATE queue_jobs SET available_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => $jlId]);
    $jlJob = array_values(array_filter(JobQueue::pull(100), static fn(array $j): bool => $j['id'] === $jlId))[0] ?? [];
    $jlClaimB = JobQueue::markProcessing($jlJob);
    assert_true($jlClaimB !== '' && $jlClaimB !== $jlClaimA, 'CR-M9 database: worker B re-claims the stalled job');
    $jlLost = false;
    try { JobQueue::heartbeat(true); } catch (JobLeaseLost $e) { $jlLost = true; }
    assert_true($jlLost, 'CR-M9 database: the stale worker\'s heartbeat aborts its run (JobLeaseLost)');
    assert_true(!JobQueue::extendLease($jlClaimA), 'CR-M9 database: a lost claim cannot be renewed');
    JobQueue::heartbeat(true); // the lost claim was dropped: no further throw
    JobQueue::complete($jlClaimB);
    JobQueue::endWork();

    // --- M9, file backend ---
    JobQueue::setDir($jlRoot . '/jobs-lease');
    JobQueue::push('m9_long_job', ['n' => 3]);
    $jlProc = JobQueue::markProcessing(JobQueue::pull(1)[0]);
    JobQueue::beginWork($jlProc);
    touch($jlProc, time() - 290);
    JobQueue::heartbeat(true);
    clearstatcache();
    assert_true(filemtime($jlProc) >= time() - 2, 'CR-M9 file: heartbeat refreshes the processing file lease');
    touch($jlProc, time() - 200);
    JobQueue::heartbeat(true);
    JobQueue::pull(0); // recoverStale()
    assert_equals(['pending' => 0, 'processing' => 1], array_intersect_key(JobQueue::stats(), ['pending' => 0, 'processing' => 0]), 'CR-M9 file: a heartbeating job is not recovered as stale');
    touch($jlProc, time() - 3600);
    JobQueue::pull(0);
    $jlLost = false;
    try { JobQueue::heartbeat(true); } catch (JobLeaseLost $e) { $jlLost = true; }
    assert_true($jlLost, 'CR-M9 file: heartbeat after the job was recovered aborts the stale run');
    JobQueue::endWork();

    // --- M10, file backend: six jobs in backoff ahead of one ready job ---
    JobQueue::setDir($jlRoot . '/jobs-backoff');
    for ($jlI = 0; $jlI < 6; $jlI++) JobQueue::push('m10_backoff', ['n' => $jlI], 1);
    foreach (glob($jlRoot . '/jobs-backoff/pending/*.json') as $jlFile) {
        $jlBacked = json_decode(file_get_contents($jlFile), true);
        $jlBacked['retry_after'] = date('c', time() + 600);
        file_put_contents($jlFile, json_encode($jlBacked));
    }
    $jlReadyId = JobQueue::push('m10_ready', [], 9);
    $jlPulled = JobQueue::pull(5);
    assert_equals([$jlReadyId], array_column($jlPulled, 'id'), 'CR-M10 file: pull() skips jobs in backoff and returns the ready job behind them');
    assert_equals([], array_values(array_filter($jlPulled, static fn(array $j): bool => !JobQueue::isReady($j))), 'CR-M10 file: pull() never hands the worker a job it would only skip (no busy loop)');
} finally {
    JobQueue::endWork();
    JobQueue::resetBackend();
    $jlDirProp->setValue(null, $jlOldDir);
    $jlForceProp->setValue(null, $jlOldForce);
    $jlDb->exec("DELETE FROM queue_jobs WHERE type IN ('m9_long_job')");
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($jlRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $jlEntry) {
        $jlEntry->isDir() ? rmdir($jlEntry->getPathname()) : unlink($jlEntry->getPathname());
    }
    rmdir($jlRoot);
}
