<?php
/**
 * BareBones Shop — Idempotency
 * Prevent duplicate webhook/import processing.
 */
class Idempotency {
    /** A claim older than this belongs to a process that died mid-event; a redelivery may take it over. */
    public const LEASE_SECONDS = 300;

    /**
     * Claim an event for processing: 'claimed' (process it, then complete()), 'done' (already processed, skip) or
     * 'in_flight' (another request is processing it right now; ask the sender to retry later).
     */
    public static function claim(string $eventId, int $leaseSeconds = self::LEASE_SECONDS): string {
        $db = Db::get();
        $now = time();
        try {
            $db->prepare("INSERT INTO processed_events (event_id, state, claimed_at) VALUES (:id, 'processing', :now)")
               ->execute([':id' => $eventId, ':now' => $now]);
            return 'claimed';
        } catch (\PDOException $e) {
            // Only unique constraint violations mean "seen before"; real DB errors (connection, disk) propagate.
            if (!in_array($e->getCode(), ['23000', '23505'], true)) throw $e;
        }
        $takeover = $db->prepare("UPDATE processed_events SET claimed_at = :now
            WHERE event_id = :id AND state = 'processing' AND (claimed_at IS NULL OR claimed_at < :cutoff)");
        $takeover->execute([':now' => $now, ':id' => $eventId, ':cutoff' => $now - $leaseSeconds]);
        if ($takeover->rowCount() === 1) return 'claimed';
        $state = $db->prepare('SELECT state FROM processed_events WHERE event_id = :id');
        $state->execute([':id' => $eventId]);
        return $state->fetchColumn() === 'processing' ? 'in_flight' : 'done';
    }

    /** The claimed event was fully processed: later deliveries are duplicates. */
    public static function complete(string $eventId): void {
        Db::get()->prepare("UPDATE processed_events SET state = 'done' WHERE event_id = :id")->execute([':id' => $eventId]);
    }

    /**
     * Claim an event; true means skip it (already processed or in flight). Callers must complete() after processing.
     */
    public static function webhookProcessed(string $eventId): bool {
        return self::claim($eventId) !== 'claimed';
    }

    public static function webhookAlreadyProcessed(string $eventId): bool {
        $stmt = Db::get()->prepare("SELECT 1 FROM processed_events WHERE event_id = :id LIMIT 1");
        $stmt->execute([':id' => $eventId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Remove an event record so it can be retried (call on business logic failure)
     */
    public static function unmark(string $eventId): void {
        $db = Db::get();
        $stmt = $db->prepare("DELETE FROM processed_events WHERE event_id = :id");
        $stmt->execute([':id' => $eventId]);
    }

    /**
     * Cleanup old events (call periodically)
     */
    public static function cleanup(int $olderThanDays = 7): int {
        $db = Db::get();
        if (Db::isSqlite()) {
            $stmt = $db->prepare("DELETE FROM processed_events WHERE created_at < datetime('now', :days)");
            $stmt->execute([':days' => "-{$olderThanDays} days"]);
        } else {
            $days = (int)$olderThanDays;
            $stmt = $db->query("DELETE FROM processed_events WHERE created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
        }
        return (int)$stmt->rowCount();
    }
}
