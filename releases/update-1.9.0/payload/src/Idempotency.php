<?php
/**
 * BareBones Shop — Idempotency
 * Prevent duplicate webhook/import processing.
 */
class Idempotency {
    /**
     * Check if event was already processed. Returns true if DUPLICATE (skip it).
     */
    public static function webhookProcessed(string $eventId): bool {
        $db = Db::get();
        try {
            $stmt = $db->prepare("INSERT INTO processed_events (event_id) VALUES (:id)");
            $stmt->execute([':id' => $eventId]);
            return false; // new event, needs processing
        } catch (\PDOException $e) {
            // Only treat unique constraint violations as "already processed"
            if (in_array($e->getCode(), ['23000', '23505'], true)) {
                return true;  // duplicate, skip
            }
            throw $e; // Re-throw real DB errors (connection, disk, etc.)
        }
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
