<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — queue dead outgoing webhook events again
 *
 * An event whose receiver failed for MAX_ATTEMPTS deliveries (about three days) is "dead". Once the
 * receiver works again, queue it again; the worker then delivers it with a fresh set of attempts.
 * Endpoints that already acknowledged the event are not called again.
 *
 * Usage:
 *   php cli/outbox-requeue.php                 List dead events (newest first, at most 100)
 *   php cli/outbox-requeue.php <event-id> ...  Queue the given dead events again
 *   php cli/outbox-requeue.php --all           Queue every dead event again
 *
 * Exit codes: 0 success, 1 invalid arguments or an ID that is not a dead event.
 */
require_once __DIR__ . '/../src/autoload.php';

$args = array_slice($argv ?? [], 1);
$db = Db::get();

if ($args === []) {
    $rows = $db->query("SELECT id, store_id, event_type, aggregate_type, aggregate_id, updated_at, last_error
        FROM event_outbox WHERE status = 'dead' ORDER BY updated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) { echo "No dead events.\n"; exit(0); }
    foreach ($rows as $row) {
        echo "{$row['id']}  {$row['store_id']}  {$row['event_type']}  {$row['aggregate_type']}/{$row['aggregate_id']}  {$row['updated_at']}  "
            . substr((string)$row['last_error'], 0, 120) . "\n";
    }
    exit(0);
}

$ids = $args === ['--all']
    ? $db->query("SELECT id FROM event_outbox WHERE status = 'dead' ORDER BY occurred_at, seq, id")->fetchAll(PDO::FETCH_COLUMN)
    : $args;
$failed = 0;
foreach ($ids as $id) {
    $id = (string)$id;
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        fwrite(STDERR, "Not an event ID: {$id}\n");
        $failed++;
        continue;
    }
    if (Outbox::requeue($id)) {
        echo "Queued again: {$id}\n";
    } else {
        fwrite(STDERR, "Not a dead event: {$id}\n");
        $failed++;
    }
}
exit($failed > 0 ? 1 : 0);
