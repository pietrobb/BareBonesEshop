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
            return true;  // duplicate, skip
        }
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
            $stmt = $db->prepare("DELETE FROM processed_events WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)");
            $stmt->execute([':days' => $olderThanDays]);
        }
        return $stmt->rowCount();
    }
}
