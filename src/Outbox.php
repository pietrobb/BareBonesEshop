<?php
/**
 * Transactional commerce event outbox.
 * record() must run inside the same database transaction as the domain mutation.
 */
class Outbox {
    public const MAX_ATTEMPTS = 8;

    public static function record(
        string $eventType,
        string $aggregateType,
        string|int $aggregateId,
        array $payload,
        int $version = 1,
        ?string $eventId = null
    ): string {
        $db = Db::get();
        if (!$db->inTransaction()) {
            throw new LogicException('Outbox event must be recorded inside a transaction');
        }
        foreach ([$eventType, $aggregateType] as $value) {
            if (!preg_match('/^[a-z][a-z0-9_.-]{1,190}$/', $value)) {
                throw new InvalidArgumentException('Invalid outbox event identity');
            }
        }
        if ($version < 1) throw new InvalidArgumentException('Invalid outbox event version');

        $eventId ??= bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/', $eventId)) {
            throw new InvalidArgumentException('Invalid outbox event ID');
        }
        $context = StoreContext::current();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO event_outbox
            (id, store_id, channel_id, event_type, aggregate_type, aggregate_id, event_version,
             payload, status, attempts, available_at, occurred_at, updated_at, correlation_id)
            VALUES (:id, :store, :channel, :event, :aggregate, :aggregate_id, :version,
                    :payload, 'pending', 0, :available, :occurred, :updated, :correlation)");
        $stmt->execute([
            ':id' => $eventId,
            ':store' => $context->id(),
            ':channel' => $context->channel(),
            ':event' => $eventType,
            ':aggregate' => $aggregateType,
            ':aggregate_id' => (string)$aggregateId,
            ':version' => $version,
            ':payload' => $payloadJson,
            ':available' => $now,
            ':occurred' => $now,
            ':updated' => $now,
            ':correlation' => Logger::correlationId(),
        ]);
        return $eventId;
    }

    /** @return array<int,array<string,mixed>> */
    public static function pending(int $limit = 25): array {
        $limit = max(1, min(100, $limit));
        $now = gmdate('Y-m-d H:i:s');
        $stale = gmdate('Y-m-d H:i:s', time() - 300);
        $stmt = Db::get()->prepare("SELECT * FROM event_outbox
            WHERE (status IN ('pending', 'retry') AND available_at <= :now)
               OR (status = 'delivering' AND updated_at <= :stale)
            ORDER BY occurred_at, id LIMIT :limit");
        $stmt->bindValue(':now', $now);
        $stmt->bindValue(':stale', $stale);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function claim(string $eventId): ?array {
        $db = Db::get();
        $now = gmdate('Y-m-d H:i:s');
        $stale = gmdate('Y-m-d H:i:s', time() - 300);
        $stmt = $db->prepare("UPDATE event_outbox
            SET status='delivering', attempts=attempts+1, updated_at=:now
            WHERE id=:id AND (
                (status IN ('pending','retry') AND available_at <= :available)
                OR (status='delivering' AND updated_at <= :stale)
            )");
        $stmt->execute([':id' => $eventId, ':now' => $now, ':available' => $now, ':stale' => $stale]);
        if ($stmt->rowCount() !== 1) return null;
        $read = $db->prepare('SELECT * FROM event_outbox WHERE id=:id');
        $read->execute([':id' => $eventId]);
        return $read->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function envelope(array $event): array {
        $payload = json_decode((string)$event['payload'], true, 512, JSON_THROW_ON_ERROR);
        return [
            'specversion' => '1.0',
            'id' => (string)$event['id'],
            'type' => (string)$event['event_type'],
            'source' => 'bareboneseshop/' . (string)$event['store_id'],
            'time' => gmdate('c', strtotime((string)$event['occurred_at'] . ' UTC')),
            'datacontenttype' => 'application/json',
            'subject' => (string)$event['aggregate_type'] . '/' . (string)$event['aggregate_id'],
            'bbeversion' => (int)$event['event_version'],
            'store_id' => (string)$event['store_id'],
            'channel_id' => (string)$event['channel_id'],
            'data' => $payload,
        ];
    }

    public static function endpointDelivered(string $eventId, string $endpointCode): bool {
        $stmt = Db::get()->prepare("SELECT 1 FROM webhook_deliveries
            WHERE event_id=:event AND endpoint_code=:endpoint AND status='delivered' LIMIT 1");
        $stmt->execute([':event' => $eventId, ':endpoint' => $endpointCode]);
        return (bool)$stmt->fetchColumn();
    }

    public static function recordDelivery(
        string $eventId,
        string $endpointCode,
        int $attempt,
        bool $delivered,
        ?int $responseCode,
        ?string $error
    ): void {
        // Configured endpoints use [a-z0-9_-]; extension listeners are "ext:<code>:<name>".
        if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $endpointCode)) {
            throw new InvalidArgumentException('Invalid webhook endpoint code');
        }
        $stmt = Db::get()->prepare("INSERT INTO webhook_deliveries
            (event_id, endpoint_code, attempt, status, response_code, error)
            VALUES (:event, :endpoint, :attempt, :status, :code, :error)");
        try {
            $stmt->execute([
                ':event' => $eventId,
                ':endpoint' => $endpointCode,
                ':attempt' => $attempt,
                ':status' => $delivered ? 'delivered' : 'failed',
                ':code' => $responseCode,
                ':error' => $error === null ? null : substr($error, 0, 2000),
            ]);
        } catch (PDOException $e) {
            if (!in_array($e->getCode(), ['23000', '23505'], true)) throw $e;
        }
    }

    /**
     * Delivery outcomes apply only to an event still being delivered. $claimedAttempts is the
     * claim's `attempts` value (claim() increments it on every claim, including a takeover after the
     * 300 s stale lease), so it identifies the claim like DatabaseQueue's claim token: a slow first
     * deliverer can no longer overwrite the state written by the worker that took the event over.
     * They return false when the claim is no longer owned.
     */
    public static function markDelivered(string $eventId, ?int $claimedAttempts = null): bool {
        return self::updateState($eventId, 'delivered', gmdate('Y-m-d H:i:s'), null, $claimedAttempts);
    }

    public static function markRetry(string $eventId, int $attempts, string $error, ?int $claimedAttempts = null): bool {
        if ($attempts >= self::MAX_ATTEMPTS) {
            return self::markDead($eventId, $error, $claimedAttempts);
        }
        $delay = min(3600, 5 * (2 ** max(0, $attempts - 1)));
        return self::updateState($eventId, 'retry', gmdate('Y-m-d H:i:s', time() + $delay), $error, $claimedAttempts);
    }

    /**
     * Give a claimed event back without consuming the attempt claim() counted: for an event none of
     * whose failing deliveries was actually tried in this sweep (each was skipped), so a skipped
     * event can never die unattempted. Guarded by the claim token like the other outcomes; the
     * event is due again immediately (the next sweep picks it up).
     */
    public static function release(string $eventId, int $claimedAttempts, string $reason): bool {
        $stmt = Db::get()->prepare("UPDATE event_outbox
            SET status='retry', attempts=attempts-1, available_at=:available, last_error=:error, updated_at=:updated
            WHERE id=:id AND status='delivering' AND attempts=:attempts AND attempts > 0");
        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([':available' => $now, ':error' => substr($reason, 0, 2000), ':updated' => $now, ':id' => $eventId, ':attempts' => $claimedAttempts]);
        return $stmt->rowCount() === 1;
    }

    public static function markDead(string $eventId, string $error, ?int $claimedAttempts = null): bool {
        return self::updateState($eventId, 'dead', gmdate('Y-m-d H:i:s'), $error, $claimedAttempts);
    }

    private static function updateState(string $eventId, string $status, string $availableAt, ?string $error, ?int $claimedAttempts): bool {
        $stmt = Db::get()->prepare("UPDATE event_outbox
            SET status=:status, available_at=:available, last_error=:error, updated_at=:updated
            WHERE id=:id AND status='delivering'" . ($claimedAttempts === null ? '' : ' AND attempts=:attempts'));
        $params = [
            ':status' => $status,
            ':available' => $availableAt,
            ':error' => $error === null ? null : substr($error, 0, 2000),
            ':updated' => gmdate('Y-m-d H:i:s'),
            ':id' => $eventId,
        ];
        if ($claimedAttempts !== null) $params[':attempts'] = $claimedAttempts;
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }
}
