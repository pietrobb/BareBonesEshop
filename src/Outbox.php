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
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $endpointCode)) {
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

    public static function markDelivered(string $eventId): void {
        self::updateState($eventId, 'delivered', gmdate('Y-m-d H:i:s'), null);
    }

    public static function markRetry(string $eventId, int $attempts, string $error): void {
        if ($attempts >= self::MAX_ATTEMPTS) {
            self::markDead($eventId, $error);
            return;
        }
        $delay = min(3600, 5 * (2 ** max(0, $attempts - 1)));
        self::updateState($eventId, 'retry', gmdate('Y-m-d H:i:s', time() + $delay), $error);
    }

    public static function markDead(string $eventId, string $error): void {
        self::updateState($eventId, 'dead', gmdate('Y-m-d H:i:s'), $error);
    }

    private static function updateState(string $eventId, string $status, string $availableAt, ?string $error): void {
        $stmt = Db::get()->prepare("UPDATE event_outbox
            SET status=:status, available_at=:available, last_error=:error, updated_at=:updated
            WHERE id=:id");
        $stmt->execute([
            ':status' => $status,
            ':available' => $availableAt,
            ':error' => $error === null ? null : substr($error, 0, 2000),
            ':updated' => gmdate('Y-m-d H:i:s'),
            ':id' => $eventId,
        ]);
    }
}
