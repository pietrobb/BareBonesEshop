<?php
/** Bounded, store-scoped operational failure overview for administrators. */
class OperationalDashboard {
    public static function summary(?string $storeId = null, int $limit = 20): array {
        $storeId ??= StoreContext::current()->id();
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $storeId)) throw new InvalidArgumentException('Invalid store ID');
        $limit = max(1, min(50, $limit));
        $db = Db::get();

        $mail = self::tableExists($db, 'email_log')
            ? self::rows($db, "SELECT id, recipient, template, error, correlation_id, created_at
                FROM email_log WHERE store_id=:store AND status='failed'
                ORDER BY created_at DESC LIMIT :limit", $storeId, $limit)
            : [];
        $paymentCorrelation = self::tableExists($db, 'payment_transactions')
            ? "(SELECT pt.correlation_id FROM payment_transactions pt
                WHERE pt.order_id=orders.id AND pt.store_id=orders.store_id
                ORDER BY pt.created_at DESC, pt.id DESC LIMIT 1)"
            : "''";
        $payments = self::tableExists($db, 'orders')
            ? self::rows($db, "SELECT id, order_number, payment_status, payment_method, payment_id,
                    {$paymentCorrelation} AS correlation_id, created_at
                FROM orders WHERE store_id=:store AND (
                    payment_status IN ('failed','refund_failed')
                    OR (payment_status IN ('pending','authorized') AND created_at <= :stale)
                ) ORDER BY created_at DESC LIMIT :limit", $storeId, $limit, gmdate('Y-m-d H:i:s', time() - 3600))
            : [];
        $webhooks = self::tableExists($db, 'event_outbox')
            ? self::rows($db, "SELECT id, event_type, status, attempts, last_error, correlation_id, updated_at
                FROM event_outbox WHERE store_id=:store AND status IN ('retry','dead')
                ORDER BY updated_at DESC LIMIT :limit", $storeId, $limit)
            : [];
        if (self::tableExists($db, 'payment_webhook_inbox')) {
            $parked = self::rows($db, "SELECT id, provider, event_type, status, attempts, last_error, correlation_id, updated_at
                FROM payment_webhook_inbox WHERE store_id=:store AND status IN ('retry','dead')
                ORDER BY updated_at DESC LIMIT :limit", $storeId, $limit);
            foreach ($parked as $row) {
                $row['event_type'] = $row['provider'] . ':' . $row['event_type'];
                $row['status'] = 'unresolved_' . $row['status'];
                unset($row['provider']);
                $webhooks[] = $row;
            }
            $webhooks = array_slice($webhooks, 0, $limit);
        }

        $jobs = array_values(array_filter(
            JobQueue::failures($limit * 2),
            static fn(array $job): bool => (string)($job['store_id'] ?? 'default') === $storeId
        ));
        $jobs = array_slice($jobs, 0, $limit);

        return [
            'store_id' => $storeId,
            'correlation_id' => Logger::correlationId(),
            'queue_backend' => JobQueue::backendName(),
            'queue' => JobQueue::stats(),
            'counts' => [
                'failed_jobs' => count($jobs),
                'failed_mail' => count($mail),
                'payment_incidents' => count($payments),
                'webhook_incidents' => count($webhooks),
            ],
            'failed_jobs' => $jobs,
            'failed_mail' => $mail,
            'payment_incidents' => $payments,
            'webhook_incidents' => $webhooks,
        ];
    }

    private static function tableExists(PDO $db, string $table): bool {
        if (Db::isSqlite()) {
            $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:table");
        } else {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
        }
        $stmt->execute([':table' => $table]);
        return (bool)$stmt->fetchColumn();
    }

    private static function rows(PDO $db, string $sql, string $storeId, int $limit, ?string $stale = null): array {
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':store', $storeId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        if ($stale !== null) $stmt->bindValue(':stale', $stale);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
