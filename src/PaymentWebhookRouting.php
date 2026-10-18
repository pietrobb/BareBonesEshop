<?php
/**
 * Store resolution for verified payment webhooks.
 *
 * The request host/prefix only selects which provider credentials verify the signature.
 * The order itself is then located in every store sharing that provider scope (every store for
 * Stripe, verified with the single global stripe_webhook_secret), and the event is processed
 * inside the order's own StoreContext. Unresolvable events are
 * persisted for bounded provider retry and surfaced as webhook incidents, never dropped.
 */
final class PaymentWebhookRouting {
    public const MAX_ATTEMPTS = 5;

    /** @return list<string> stores whose provider credentials verified this request */
    public static function eligibleStoreIds(): array {
        $scope = StoreContext::current()->providerId();
        $ids = [];
        foreach ((array)(Bootstrap::stores()['stores'] ?? []) as $id => $config) {
            if ((string)(is_array($config) ? ($config['provider_scope'] ?? $id) : $id) === $scope) $ids[] = (string)$id;
        }
        $current = StoreContext::current()->id();
        if (!in_array($current, $ids, true)) $ids[] = $current;
        return $ids;
    }

    /**
     * Locate the order by the first trusted reference present (order_id, session_id,
     * order_number, payment_id). Returns ['id' => int, 'store_id' => string] or throws.
     */
    public static function locate(array $refs, string $provider): array {
        $db = Db::get();
        $orderId = (int)($refs['order_id'] ?? 0);
        if ($orderId <= 0 && (string)($refs['session_id'] ?? '') !== '') {
            $orderId = CheckoutSession::resolveOrderId((string)$refs['session_id'], $provider) ?? 0;
        }
        if ($orderId > 0) {
            [$column, $value] = ['id', $orderId];
        } elseif ((string)($refs['order_number'] ?? '') !== '') {
            [$column, $value] = ['order_number', (string)$refs['order_number']];
        } elseif ((string)($refs['payment_id'] ?? '') !== '') {
            [$column, $value] = ['payment_id', (string)$refs['payment_id']];
        } else {
            throw new PaymentWebhookUnresolved($refs, 'Webhook carries no order reference');
        }

        // checkout's notify_url carries the order's store prefix/domain, so other providers arrive in its scope.
        $stores = $provider === 'stripe'
            ? array_values(array_unique([...array_map('strval', array_keys((array)(Bootstrap::stores()['stores'] ?? []))), ...self::eligibleStoreIds()]))
            : self::eligibleStoreIds();
        $marks = implode(',', array_fill(0, count($stores), '?'));
        $stmt = $db->prepare("SELECT id, store_id FROM orders WHERE {$column} = ? AND store_id IN ({$marks}) LIMIT 2");
        $stmt->execute([$value, ...$stores]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new PaymentWebhookUnresolved($refs, $rows ? 'Webhook order reference is ambiguous' : 'Webhook order not found in provider scope');
        }
        return ['id' => (int)$rows[0]['id'], 'store_id' => (string)$rows[0]['store_id']];
    }

    /** Run $fn inside the given store's context and restore the request context afterwards. */
    public static function within(string $storeId, callable $fn): mixed {
        $previous = StoreContext::current();
        if ($previous->id() === $storeId) return $fn();
        $root = Bootstrap::stores();
        $channels = (array)($root['stores'][$storeId]['channels'] ?? ['storefront']);
        $channel = in_array($previous->channel(), $channels, true) ? $previous->channel() : (string)reset($channels);
        StoreContext::activate(StoreContext::resolve($root, null, null, $storeId, $channel));
        try {
            return $fn();
        } finally {
            StoreContext::activate($previous);
        }
    }

    /**
     * Persist an unresolved signed event. Returns ['status' => 'retry'|'dead', 'attempts' => int].
     * 'retry' must be answered with a non-2xx status so the provider redelivers.
     */
    public static function park(string $provider, string $eventId, string $eventType, PaymentWebhookUnresolved $e): array {
        $db = Db::get();
        $key = [':provider' => $provider, ':event' => $eventId];
        $db->prepare(Db::insertIgnore() . " INTO payment_webhook_inbox (store_id, provider, event_id, event_type, refs, status, attempts)
            VALUES (:store, :provider, :event, :type, :refs, 'retry', 0)")
            ->execute($key + [
                ':store' => StoreContext::current()->id(),
                ':type' => substr($eventType, 0, 80),
                ':refs' => json_encode($e->refs, JSON_UNESCAPED_UNICODE),
            ]);
        $db->prepare("UPDATE payment_webhook_inbox
            SET status = CASE WHEN attempts + 1 >= " . self::MAX_ATTEMPTS . " THEN 'dead' ELSE 'retry' END,
                attempts = attempts + 1,
                last_error = :error, correlation_id = :correlation, updated_at = " . Db::now() . "
            WHERE provider = :provider AND event_id = :event")
            ->execute($key + [
                ':error' => substr($e->getMessage(), 0, 500),
                ':correlation' => Logger::correlationId(),
            ]);
        $stmt = $db->prepare('SELECT status, attempts FROM payment_webhook_inbox WHERE provider = :provider AND event_id = :event');
        $stmt->execute($key);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['status' => 'retry', 'attempts' => 1];
        error_log("payment webhook [{$provider}] {$eventType} {$eventId} unresolved ({$row['status']} #{$row['attempts']}): " . $e->getMessage());
        return ['status' => (string)$row['status'], 'attempts' => (int)$row['attempts']];
    }

    /** Close a previously parked event once a redelivery was processed. */
    public static function resolved(string $provider, string $eventId, string $storeId): void {
        Db::get()->prepare("UPDATE payment_webhook_inbox SET status = 'resolved', resolved_store_id = :resolved, updated_at = " . Db::now() . "
            WHERE provider = :provider AND event_id = :event AND status <> 'resolved'")
            ->execute([':resolved' => $storeId, ':provider' => $provider, ':event' => $eventId]);
    }
}
