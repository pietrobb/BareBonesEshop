<?php
/**
 * BareBones Shop — Payment Service
 * Orchestrates payment flow: initiate, webhook processing, refunds.
 * Uses PaymentAdapter implementations (Stripe, PayU, Mock, COD).
 * Writes to payment_transactions for idempotent event log.
 */
class PaymentService {
    /**
     * Initiate a payment session for a newly created order.
     * Returns ['url' => redirect_url, 'session_id' => provider_session_id]
     * Logs an 'initiated' transaction record.
     */
    public static function initiate(array $order, PaymentAdapter $gateway): array {
        $session = $gateway->createSession($order);

        // Log initiated transaction
        self::logTransaction(
            orderId:        (int)$order['order_id'],
            provider:       self::providerName($gateway),
            providerRef:    $session['session_id'] ?? '',
            status:         'initiated',
            amount:         (int)$order['total'],
            currency:       $order['currency'] ?? 'eur',
            idempotencyKey: 'init_' . ($order['order_number'] ?? '') . '_' . ($session['session_id'] ?? uniqid()),
            rawResponse:    $session,
        );

        return $session;
    }

    /**
     * Process an incoming webhook event.
     * Verifies signature, deduplicates via processed_events, transitions order status.
     * Returns ['handled' => bool, 'event_type' => string, 'order_id' => int|null]
     */
    public static function handleWebhook(
        string         $rawPayload,
        string         $sigHeader,
        array          $parsedPayload,
        PaymentAdapter $gateway,
        string         $providerName
    ): array {
        // 1. Verify signature — ALWAYS required
        if (!$gateway->verifySignature($rawPayload, $sigHeader)) {
            throw new \RuntimeException('Webhook signature verification failed');
        }

        // 2. Parse provider-specific event
        $event     = $gateway->handleWebhook($parsedPayload);
        $eventType = $event['event_type'] ?? '';
        $eventId   = (string)($event['event_id'] ?? '');
        $data      = $event['data'] ?? [];

        // 3. Idempotency — skip already-processed events
        // webhookProcessed() atomically INSERTs the event_id; returns true if duplicate
        if ($eventId && Idempotency::webhookProcessed($eventId)) {
            return ['handled' => false, 'event_type' => $eventType, 'order_id' => null, 'duplicate' => true];
        }

        // 4. Resolve order
        $orderId = self::resolveOrderId($data, $providerName);

        // 5. Log transaction
        $status = self::eventTypeToTxStatus($eventType);
        if ($orderId) {
            self::logTransaction(
                orderId:        $orderId,
                provider:       $providerName,
                providerRef:    (string)($data['provider_ref'] ?? $data['orderId'] ?? $data['id'] ?? ''),
                status:         $status,
                amount:         (int)($data['amount'] ?? 0),
                currency:       strtolower($data['currency'] ?? 'eur'),
                idempotencyKey: $eventId ?: ('evt_' . $providerName . '_' . uniqid()),
                rawResponse:    $data,
            );
        }

        // 6. Event already marked processed by webhookProcessed() above (atomic INSERT).
        //    On business logic failure, caller can call Idempotency::unmark($eventId) to allow retry.

        // 7. Trigger order state transitions
        if ($orderId) {
            self::applyOrderTransition($orderId, $eventType, $status);
        }

        return ['handled' => true, 'event_type' => $eventType, 'order_id' => $orderId];
    }

    /**
     * Refund a transaction (full or partial).
     * Logs a 'refund_initiated' transaction, queues refund job.
     */
    public static function refund(int $orderId, string $paymentId, int $amount, PaymentAdapter $gateway): array {
        $result = $gateway->refund($paymentId, $amount);

        $status = $result['status'] ?? 'failed';
        self::logTransaction(
            orderId:        $orderId,
            provider:       self::providerName($gateway),
            providerRef:    $result['refund_id'] ?? '',
            status:         'refund_' . $status,
            amount:         $amount,
            currency:       'eur',
            idempotencyKey: 'refund_' . $orderId . '_' . ($result['refund_id'] ?? uniqid()),
            rawResponse:    $result,
        );

        return $result;
    }

    /**
     * Get all transactions for an order.
     */
    public static function transactionsForOrder(int $orderId): array {
        $stmt = Db::get()->prepare(
            "SELECT * FROM payment_transactions WHERE order_id = :oid ORDER BY created_at"
        );
        $stmt->execute([':oid' => $orderId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Update orders.payment_status.
     */
    public static function updatePaymentStatus(int $orderId, string $status): void {
        Db::get()->prepare(
            "UPDATE orders SET payment_status = :s, updated_at = " . Db::now() . " WHERE id = :id"
        )->execute([':s' => $status, ':id' => $orderId]);
    }

    // =========================================================
    // Private helpers
    // =========================================================

    private static function logTransaction(
        int    $orderId,
        string $provider,
        string $providerRef,
        string $status,
        int    $amount,
        string $currency,
        string $idempotencyKey,
        array  $rawResponse,
    ): void {
        try {
            $db   = Db::get();
            $now  = Db::now();
            $stmt = $db->prepare("
                INSERT OR IGNORE INTO payment_transactions
                    (order_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response, created_at, updated_at)
                VALUES
                    (:oid, :prov, :ref, :status, :amt, :cur, :ikey, :raw, {$now}, {$now})
            ");
            $stmt->execute([
                ':oid'    => $orderId,
                ':prov'   => $provider,
                ':ref'    => $providerRef,
                ':status' => $status,
                ':amt'    => $amount,
                ':cur'    => $currency,
                ':ikey'   => $idempotencyKey,
                ':raw'    => json_encode($rawResponse, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // Never let logging failure break the payment flow
            error_log("PaymentService: failed to log transaction for order #{$orderId}: " . $e->getMessage());
        }
    }

    private static function resolveOrderId(array $data, string $provider): ?int {
        // Try explicit order_id in data first
        if (!empty($data['order_id'])) return (int)$data['order_id'];

        $db = Db::get();

        // Stripe: session object has metadata.order_id or client_reference_id
        if ($provider === 'stripe') {
            $orderId = $data['metadata']['order_id'] ?? null;
            if ($orderId) return (int)$orderId;

            $orderNumber = $data['client_reference_id'] ?? null;
            if ($orderNumber) {
                $row = $db->prepare("SELECT id FROM orders WHERE order_number = :n")->execute([':n' => $orderNumber]);
                $r   = $db->query("SELECT id FROM orders WHERE order_number = " . $db->quote($orderNumber))->fetch();
                return $r ? (int)$r['id'] : null;
            }

            // Fall back to payment_id match
            $paymentId = $data['payment_intent'] ?? $data['id'] ?? null;
            if ($paymentId) {
                $r = $db->query("SELECT id FROM orders WHERE payment_id = " . $db->quote($paymentId) . " LIMIT 1")->fetch();
                return $r ? (int)$r['id'] : null;
            }
        }

        // PayU: extOrderId matches order_number
        if ($provider === 'payu') {
            $extOrderId = $data['extOrderId'] ?? null;
            if ($extOrderId) {
                $r = $db->query("SELECT id FROM orders WHERE order_number = " . $db->quote($extOrderId) . " LIMIT 1")->fetch();
                return $r ? (int)$r['id'] : null;
            }
            // Fall back to payment_id
            $payuOrderId = $data['orderId'] ?? null;
            if ($payuOrderId) {
                $r = $db->query("SELECT id FROM orders WHERE payment_id = " . $db->quote($payuOrderId) . " LIMIT 1")->fetch();
                return $r ? (int)$r['id'] : null;
            }
        }

        // Mock: explicit order_id in payload
        return null;
    }

    private static function applyOrderTransition(int $orderId, string $eventType, string $txStatus): void {
        $order = Orders::byId($orderId);
        if (!$order) return;

        try {
            match($eventType) {
                'payment.paid' => self::onPaymentPaid($order),
                'payment.failed' => self::onPaymentFailed($order),
                'refund.succeeded' => self::onRefundSucceeded($order),
                default => null,
            };
        } catch (\Throwable $e) {
            error_log("PaymentService: transition failed for order #{$orderId} event {$eventType}: " . $e->getMessage());
        }
    }

    private static function onPaymentPaid(array $order): void {
        self::updatePaymentStatus((int)$order['id'], 'paid');
        if (OrderWorkflow::canTransition($order['status'], 'paid')) {
            OrderWorkflow::transition((int)$order['id'], 'paid', 'Payment confirmed via webhook');
        }
    }

    private static function onPaymentFailed(array $order): void {
        self::updatePaymentStatus((int)$order['id'], 'failed');
        AuditLog::log('payment_failed', (int)$order['id'], $order['status'], $order['status'], 'Payment failed');
    }

    private static function onRefundSucceeded(array $order): void {
        self::updatePaymentStatus((int)$order['id'], 'refunded');
        // Only transition to refunded if order is in a terminal-but-not-refunded state
        if (in_array($order['status'], ['returned', 'cancelled'], true)
            && OrderWorkflow::canTransition($order['status'], 'refunded')) {
            OrderWorkflow::transition((int)$order['id'], 'refunded', 'Refund confirmed via webhook');
        }
    }

    private static function eventTypeToTxStatus(string $eventType): string {
        return match($eventType) {
            'payment.paid'      => 'paid',
            'payment.failed'    => 'failed',
            'refund.succeeded'  => 'refunded',
            'payment.disputed'  => 'disputed',
            default             => 'unknown',
        };
    }

    private static function providerName(PaymentAdapter $gateway): string {
        $class = get_class($gateway);
        return match(true) {
            str_contains($class, 'Stripe')   => 'stripe',
            str_contains($class, 'PayU')     => 'payu',
            str_contains($class, 'Mock')     => 'mock',
            str_contains($class, 'Cash')     => 'cod',
            default                          => strtolower($class),
        };
    }
}
