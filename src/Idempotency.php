<?php
/**
 * BareBones Shop — Idempotency
 * Prevent duplicate webhook/import processing.
 */
class Idempotency {
    /** A claim older than this belongs to a process that died mid-event; a redelivery may take it over. */
    public const LEASE_SECONDS = 300;
    /** @var array<string,int> claimed_at this process wrote for each event it claimed: its claim token */
    private static array $claims = [];
    /** Connection whose processed_events still lacks the state/claimed_at columns (migration pending). */
    private static ?PDO $legacyDb = null;

    /**
     * Claim an event for processing: 'claimed' (process it, then complete()), 'done' (already processed, skip) or
     * 'in_flight' (another request is processing it right now; ask the sender to retry later).
     */
    public static function claim(string $eventId, int $leaseSeconds = self::LEASE_SECONDS): string {
        $db = Db::get();
        $now = time();
        if (self::$legacyDb === $db) return self::legacyClaim($db, $eventId);
        try {
            $db->prepare("INSERT INTO processed_events (event_id, state, claimed_at) VALUES (:id, 'processing', :now)")
               ->execute([':id' => $eventId, ':now' => $now]);
            self::$claims[$eventId] = $now;
            return 'claimed';
        } catch (\PDOException $e) {
            // Only unique constraint violations mean "seen before"; real DB errors (connection, disk) propagate.
            if (!in_array($e->getCode(), ['23000', '23505'], true)) {
                if (self::hasClaimColumns($db)) throw $e;
                // Code deployed before cli/migrate.php ran: keep accepting webhooks with the legacy
                // consume-on-claim semantics instead of failing every delivery until the migration.
                self::$legacyDb = $db;
                if (class_exists('Logger')) Logger::warning('idempotency.claim_state_migration_pending');
                return self::legacyClaim($db, $eventId);
            }
        }
        $takeover = $db->prepare("UPDATE processed_events SET claimed_at = :now
            WHERE event_id = :id AND state = 'processing' AND (claimed_at IS NULL OR claimed_at < :cutoff)");
        $takeover->execute([':now' => $now, ':id' => $eventId, ':cutoff' => $now - $leaseSeconds]);
        if ($takeover->rowCount() === 1) {
            self::$claims[$eventId] = $now;
            return 'claimed';
        }
        $state = $db->prepare('SELECT state FROM processed_events WHERE event_id = :id');
        $state->execute([':id' => $eventId]);
        return $state->fetchColumn() === 'processing' ? 'in_flight' : 'done';
    }

    /** The claimed event was fully processed: later deliveries are duplicates. */
    public static function complete(string $eventId): void {
        $db = Db::get();
        unset(self::$claims[$eventId]);
        if (self::$legacyDb === $db) return; // the legacy row is consumed on insert
        $db->prepare("UPDATE processed_events SET state = 'done' WHERE event_id = :id")->execute([':id' => $eventId]);
    }

    private static function legacyClaim(PDO $db, string $eventId): string {
        try {
            $db->prepare('INSERT INTO processed_events (event_id) VALUES (:id)')->execute([':id' => $eventId]);
            return 'claimed';
        } catch (\PDOException $e) {
            if (!in_array($e->getCode(), ['23000', '23505'], true)) throw $e;
            return 'done';
        }
    }

    private static function hasClaimColumns(PDO $db): bool {
        // An unreadable or missing table is a real error, not a pending migration. Hits are cached
        // per connection, so a migrated schema costs no probe query per webhook.
        return (Db::hasColumn('processed_events', 'state', $db) && Db::hasColumn('processed_events', 'claimed_at', $db))
            || !Db::tableExists('processed_events', $db);
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
     * Remove an event record so it can be retried (call on business logic failure).
     * A claim taken by this process is released only while it still owns it: a handler that ran past
     * the lease must not delete the claim a redelivery has taken over meanwhile.
     */
    public static function unmark(string $eventId): void {
        $db = Db::get();
        $claimedAt = self::$claims[$eventId] ?? null;
        unset(self::$claims[$eventId]);
        if ($claimedAt === null || self::$legacyDb === $db) {
            $db->prepare("DELETE FROM processed_events WHERE event_id = :id")->execute([':id' => $eventId]);
            return;
        }
        $db->prepare("DELETE FROM processed_events WHERE event_id = :id AND state = 'processing' AND claimed_at = :claimed")
           ->execute([':id' => $eventId, ':claimed' => $claimedAt]);
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
