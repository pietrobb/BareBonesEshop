<?php
/**
 * BareBones Shop — Payment Service
 * Orchestrates payment flow: initiate, webhook processing, refunds.
 * Uses PaymentAdapter implementations (Stripe, PayU, Mock, COD).
 * Writes to payment_transactions for idempotent event log.
 */
class PaymentService {
    private const PAYMENT_TRANSITIONS = [
        'pending' => ['authorized', 'paid', 'failed', 'cancelled'],
        'authorized' => ['paid', 'failed', 'cancelled'],
        'paid' => ['refund_pending', 'partially_refunded', 'refunded', 'disputed'],
        'refund_pending' => ['paid', 'partially_refunded', 'refunded', 'failed'],
        'partially_refunded' => ['refund_pending', 'refunded', 'disputed'],
        'disputed' => ['paid', 'refund_pending', 'refunded'],
        'failed' => [],
        'cancelled' => [],
        'refunded' => [],
    ];

    /**
     * Return available checkout payment methods for country/cart/shipping context.
     */
    public static function availableMethods(string $country, string $currency, int $amount, string $shippingMethod = ''): array {
        $country = strtoupper(trim($country ?: 'SK'));
        $currency = strtolower(trim($currency ?: 'eur'));
        $amount = max(0, $amount);
        $shippingMethod = trim($shippingMethod);

        if (!self::hasMethodTables()) {
            return self::fallbackMethods($amount, $shippingMethod);
        }

        $db = Db::get();
        $params = [':country' => $country, ':amount' => $amount];
        $shippingFilter = '';
        if ($shippingMethod !== '') {
            $shippingFilter = "
                AND (
                    NOT EXISTS (SELECT 1 FROM payment_method_shipping ps_any WHERE ps_any.method_code = m.code)
                    OR EXISTS (SELECT 1 FROM payment_method_shipping ps WHERE ps.method_code = m.code AND ps.shipping_method_code = :shipping_method)
                )";
            $params[':shipping_method'] = $shippingMethod;
        }

        $stmt = $db->prepare("
            SELECT m.*
            FROM payment_methods m
            WHERE m.active = 1
              AND EXISTS (SELECT 1 FROM payment_method_countries pc WHERE pc.method_code = m.code AND pc.country = :country)
              AND m.min_amount <= :amount
              AND (m.max_amount IS NULL OR m.max_amount = 0 OR m.max_amount >= :amount)
              {$shippingFilter}
            ORDER BY m.sort_order, m.name
        ");
        $stmt->execute($params);

        $methods = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $methods[] = self::methodRowToQuote($row, $amount, $currency);
        }
        return $methods;
    }

    public static function selectMethod(string $methodCode, string $country, string $currency, int $amount, string $shippingMethod = ''): ?array {
        foreach (self::availableMethods($country, $currency, $amount, $shippingMethod) as $method) {
            if (($method['code'] ?? '') === $methodCode) return $method;
        }
        return null;
    }

    public static function canPaymentTransition(string $from, string $to): bool {
        return in_array($to, self::PAYMENT_TRANSITIONS[$from] ?? [], true);
    }

    public static function allowedPaymentTransitions(string $from): array {
        return self::PAYMENT_TRANSITIONS[$from] ?? [];
    }

    public static function paymentStatuses(): array {
        return array_keys(self::PAYMENT_TRANSITIONS);
    }

    public static function recordEvent(int $orderId, string $eventType, string $status, int $amount, string $currency, string $idempotencyKey, array $payload = [], string $provider = '', string $providerRef = ''): bool {
        if ($idempotencyKey !== '' && self::transactionExists($idempotencyKey)) return false;

        self::logTransaction(
            orderId: $orderId,
            provider: $provider,
            providerRef: $providerRef,
            status: $status,
            amount: $amount,
            currency: strtolower($currency ?: 'eur'),
            idempotencyKey: $idempotencyKey ?: ('payment_event_' . $orderId . '_' . uniqid()),
            rawResponse: array_merge(['event_type' => $eventType], $payload),
        );
        return true;
    }

    public static function transitionPayment(int $orderId, string $to, string $eventType, int $amount = 0, string $idempotencyKey = '', array $payload = []): bool {
        $order = Orders::byId($orderId);
        if (!$order) throw new \RuntimeException("Order #{$orderId} not found");

        $from = (string)($order['payment_status'] ?? 'pending');
        if ($from === $to) return false;
        if (!self::canPaymentTransition($from, $to)) {
            throw new \LogicException("Cannot transition payment for order #{$orderId} from '{$from}' to '{$to}'");
        }

        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? '{}'), true) ?: [];
        $provider = (string)($snapshot['provider'] ?? $order['payment_method'] ?? '');
        $providerRef = (string)($order['payment_id'] ?? '');
        $currency = (string)($order['currency'] ?? 'eur');
        if ($amount <= 0) $amount = (int)($order['total'] ?? 0);

        $db = Db::get();
        $db->beginTransaction();
        try {
            if ($idempotencyKey === '' || !self::transactionExists($idempotencyKey)) {
                self::recordEvent($orderId, $eventType, $to, $amount, $currency, $idempotencyKey, $payload, $provider, $providerRef);
                self::updatePaymentStatus($orderId, $to);
                AuditLog::log('payment_status', $orderId, $from, $to, $eventType);
                $db->commit();
                return true;
            }
            $db->rollBack();
            return false;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

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

    public static function renameMethodCodeLinks(string $oldCode, string $newCode): void {
        if ($oldCode === '' || $oldCode === $newCode) return;
        $db = Db::get();
        $db->prepare("UPDATE payment_method_countries SET method_code = :new WHERE method_code = :old")->execute([':new' => $newCode, ':old' => $oldCode]);
        $db->prepare("UPDATE payment_method_shipping SET method_code = :new WHERE method_code = :old")->execute([':new' => $newCode, ':old' => $oldCode]);
    }

    // =========================================================
    // Private helpers
    // =========================================================

    private static function transactionExists(string $idempotencyKey): bool {
        if ($idempotencyKey === '') return false;
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE idempotency_key = :key LIMIT 1");
        $stmt->execute([':key' => $idempotencyKey]);
        return (bool)$stmt->fetchColumn();
    }

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
            $stmt = $db->prepare(Db::insertIgnore() . "
                INTO payment_transactions
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

            $sessionId = (string)($data['id'] ?? '');
            if ($sessionId !== '') {
                $mappedOrderId = CheckoutSession::resolveOrderId($sessionId, 'stripe');
                if ($mappedOrderId !== null) return $mappedOrderId;
            }

            $orderNumber = $data['client_reference_id'] ?? null;
            if ($orderNumber) {
                $stmt = $db->prepare("SELECT id FROM orders WHERE order_number = :n LIMIT 1");
                $stmt->execute([':n' => $orderNumber]);
                $id = $stmt->fetchColumn();
                return $id === false ? null : (int)$id;
            }

            // Fall back to payment_id match
            $paymentId = $data['payment_intent'] ?? ($sessionId !== '' ? $sessionId : null);
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

    private static function hasMethodTables(): bool {
        try {
            Db::get()->query("SELECT 1 FROM payment_methods LIMIT 1");
            Db::get()->query("SELECT 1 FROM payment_method_countries LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function fallbackMethods(int $amount, string $shippingMethod): array {
        $rows = [
            ['code' => 'card', 'provider' => 'stripe', 'name' => 'Kartou online', 'description' => '', 'service_type' => 'online_card', 'fixed_fee' => 0, 'percent_fee_bps' => 0, 'free_above' => null, 'requires_online' => 1],
            ['code' => 'bank_transfer', 'provider' => 'manual', 'name' => 'Bankový prevod', 'description' => '', 'service_type' => 'bank_transfer', 'fixed_fee' => 0, 'percent_fee_bps' => 0, 'free_above' => null, 'requires_online' => 0],
        ];
        if (in_array($shippingMethod, ['packeta', 'gls'], true)) {
            $rows[] = ['code' => 'cod', 'provider' => 'manual', 'name' => 'Dobierka', 'description' => '', 'service_type' => 'cod', 'fixed_fee' => 120, 'percent_fee_bps' => 0, 'free_above' => null, 'requires_online' => 0];
        }
        return array_map(fn($row) => self::methodRowToQuote($row, $amount, 'eur'), $rows);
    }

    private static function methodRowToQuote(array $row, int $amount, string $currency): array {
        $fee = (int)($row['fixed_fee'] ?? 0);
        $bps = (int)($row['percent_fee_bps'] ?? 0);
        if ($bps > 0) $fee += (int)ceil($amount * $bps / 10000);
        if (!empty($row['free_above']) && $amount >= (int)$row['free_above']) $fee = 0;

        return [
            'code' => (string)$row['code'],
            'provider' => (string)($row['provider'] ?? ''),
            'label' => (string)($row['name'] ?? $row['code']),
            'description' => (string)($row['description'] ?? ''),
            'service_type' => (string)($row['service_type'] ?? 'offline'),
            'fee' => $fee,
            'currency' => $currency,
            'requires_online' => !empty($row['requires_online']),
        ];
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
