<?php

// CR-M13 (code review 2026-10-15): the worker had a `cleanup` handler, but nothing ever queued a
// cleanup job, and the handler only covered processed_events/API replies and file jobs. Delivered
// outbox events, their delivery attempts and completed database queue jobs grew without bound.
echo "\n--- CR-M13: retention cleanup is scheduled and covers outbox/queue tables ---\n";
$rcDb = Db::get();
$rcRoot = test_fixture_path('retention-cleanup');
@mkdir($rcRoot . '/jobs/completed', 0700, true);
$rcDirProp = new ReflectionProperty(JobQueue::class, 'jobsDir');
$rcOldDir = $rcDirProp->getValue();
$rcForceProp = new ReflectionProperty(JobQueue::class, 'forceFile');
$rcOldForce = $rcForceProp->getValue();
$rcHasClass = class_exists('RetentionCleanup');
assert_true($rcHasClass, 'CR-M13: a retention cleanup service exists');
assert_true(str_contains((string)file_get_contents(dirname(__DIR__, 2) . '/worker.php'), 'RetentionCleanup::enqueueIfDue('), 'CR-M13: every worker run checks whether the daily cleanup job is due');

try {
    // --- scheduling: once per day, also with concurrent workers ---
    JobQueue::useBackend(new DatabaseQueue($rcDb, 30));
    $rcCleanupJobs = static fn(): int => (int)$rcDb->query("SELECT COUNT(*) FROM queue_jobs WHERE type = 'cleanup' AND state = 'pending'")->fetchColumn();
    $rcDb->exec("DELETE FROM queue_jobs WHERE type = 'cleanup'");
    $rcMarker = $rcRoot . '/jobs/retention-cleanup.last';
    $rcNow = time();
    if ($rcHasClass) {
        assert_true(RetentionCleanup::enqueueIfDue($rcMarker, $rcNow), 'CR-M13: the first worker run of the day queues the cleanup job');
        assert_true(!RetentionCleanup::enqueueIfDue($rcMarker, $rcNow + 60), 'CR-M13: a later run the same day does not queue it again');
    }
    assert_equals(1, $rcCleanupJobs(), 'CR-M13: exactly one cleanup job is queued per day');
    if ($rcHasClass) {
        assert_true(RetentionCleanup::enqueueIfDue($rcMarker, $rcNow + 86400 + 1), 'CR-M13: the cleanup job is queued again a day later');
    }
    assert_equals(2, $rcCleanupJobs(), 'CR-M13: the next day adds the next cleanup job');
    $rcDb->exec("DELETE FROM queue_jobs WHERE type = 'cleanup'");

    // --- what the job removes, and what it keeps ---
    $rcOld = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
    $rcNew = gmdate('Y-m-d H:i:s');
    $rcEvent = $rcDb->prepare("INSERT INTO event_outbox (id, store_id, channel_id, event_type, aggregate_type, aggregate_id,
        event_version, payload, status, attempts, available_at, occurred_at, updated_at, correlation_id)
        VALUES (:id, 'default', 'storefront', 'order.created', 'order', '1', 1, '{}', :status, 1, :at, :at, :at, 'cr-m13')");
    $rcIds = [];
    foreach (['delivered_old' => ['delivered', $rcOld], 'delivered_new' => ['delivered', $rcNew], 'dead_old' => ['dead', $rcOld], 'retry_old' => ['retry', $rcOld]] as $rcKey => [$rcStatus, $rcAt]) {
        $rcIds[$rcKey] = bin2hex(random_bytes(16));
        $rcEvent->execute([':id' => $rcIds[$rcKey], ':status' => $rcStatus, ':at' => $rcAt]);
    }
    $rcDb->prepare("INSERT INTO webhook_deliveries (event_id, endpoint_code, attempt, status) VALUES (:id, 'erp', 1, 'delivered')")
        ->execute([':id' => $rcIds['delivered_old']]);
    $rcJob = $rcDb->prepare("INSERT INTO queue_jobs (id, type, payload, state, available_at, correlation_id, created_at, updated_at, completed_at)
        VALUES (:id, 'cr_m13', '{}', :state, :at, 'cr-m13', :at, :at, :done)");
    $rcJobs = [];
    foreach (['completed_old' => ['completed', $rcOld, $rcOld], 'completed_new' => ['completed', $rcNew, $rcNew], 'failed_old' => ['failed', $rcOld, null]] as $rcKey => [$rcState, $rcAt, $rcDone]) {
        $rcJobs[$rcKey] = bin2hex(random_bytes(16));
        $rcJob->execute([':id' => $rcJobs[$rcKey], ':state' => $rcState, ':at' => $rcAt, ':done' => $rcDone]);
    }
    $rcDb->prepare('INSERT INTO processed_events (event_id, created_at) VALUES (:id, :at)')->execute([':id' => 'cr-m13-old', ':at' => $rcOld]);
    file_put_contents($rcRoot . '/jobs/completed/old.json', '{}');
    touch($rcRoot . '/jobs/completed/old.json', time() - 10 * 86400);

    if ($rcHasClass) {
        RetentionCleanup::run(['jobs_dir' => $rcRoot . '/jobs']);
    } else {
        // Old behaviour: the only cleanup code there was.
        Idempotency::cleanup(7);
    }
    $rcExists = static function (string $table, string $column, string $id) use ($rcDb): bool {
        $stmt = $rcDb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :id");
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn() > 0;
    };
    assert_true(!$rcExists('event_outbox', 'id', $rcIds['delivered_old']), 'CR-M13: delivered outbox events past retention are removed');
    assert_true(!$rcExists('webhook_deliveries', 'event_id', $rcIds['delivered_old']), 'CR-M13: their delivery attempts are removed with them');
    assert_true($rcExists('event_outbox', 'id', $rcIds['delivered_new']), 'CR-M13: recently delivered events are kept');
    assert_true($rcExists('event_outbox', 'id', $rcIds['dead_old']) && $rcExists('event_outbox', 'id', $rcIds['retry_old']), 'CR-M13: dead and still-retrying events are never removed');
    assert_true(!$rcExists('queue_jobs', 'id', $rcJobs['completed_old']), 'CR-M13: completed database jobs past retention are removed');
    assert_true($rcExists('queue_jobs', 'id', $rcJobs['completed_new']) && $rcExists('queue_jobs', 'id', $rcJobs['failed_old']), 'CR-M13: recent completed and failed jobs are kept');
    assert_true(!$rcExists('processed_events', 'event_id', 'cr-m13-old'), 'CR-M13: old processed webhook events are removed');
    assert_true(!is_file($rcRoot . '/jobs/completed/old.json'), 'CR-M13: old completed job files are removed');
} finally {
    JobQueue::resetBackend();
    $rcDirProp->setValue(null, $rcOldDir);
    $rcForceProp->setValue(null, $rcOldForce);
    foreach ($rcIds ?? [] as $rcId) {
        $rcDb->prepare('DELETE FROM webhook_deliveries WHERE event_id = :id')->execute([':id' => $rcId]);
        $rcDb->prepare('DELETE FROM event_outbox WHERE id = :id')->execute([':id' => $rcId]);
    }
    $rcDb->exec("DELETE FROM queue_jobs WHERE type IN ('cr_m13', 'cleanup')");
}
