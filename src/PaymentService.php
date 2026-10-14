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
    public static function availableMethods(string $country, string $currency, int $amount, string $shippingMethod = '', ?int $customerId = null): array {
        $country = strtoupper(trim($country ?: 'SK'));
        $currency = strtolower(trim($currency ?: 'eur'));
        $amount = max(0, $amount);
        $shippingMethod = trim($shippingMethod);

        if (!self::hasMethodTables()) {
            return self::fallbackMethods($amount, $shippingMethod);
        }

        $db = Db::get();
        $storeId = StoreContext::current()->providerId();
        StoreContext::current()->assertPermission('payment');
        $params = [':store_method' => $storeId, ':store_country' => $storeId, ':country' => $country, ':min_amount' => $amount, ':max_amount' => $amount];
        $shippingFilter = '';
        if ($shippingMethod !== '') {
            $shippingFilter = "
                AND (
                    NOT EXISTS (SELECT 1 FROM payment_method_shipping ps_any WHERE ps_any.store_id = :store_shipping_any AND ps_any.method_code = m.code)
                    OR EXISTS (SELECT 1 FROM payment_method_shipping ps WHERE ps.store_id = :store_shipping_match AND ps.method_code = m.code AND ps.shipping_method_code = :shipping_method)
                )";
            $params[':store_shipping_any'] = $storeId;
            $params[':store_shipping_match'] = $storeId;
            $params[':shipping_method'] = $shippingMethod;
        }

        $stmt = $db->prepare("
            SELECT m.*
            FROM payment_methods m
            WHERE m.store_id = :store_method AND m.active = 1
              AND EXISTS (SELECT 1 FROM payment_method_countries pc WHERE pc.store_id = :store_country AND pc.method_code = m.code AND pc.country = :country)
              AND m.min_amount <= :min_amount
              AND (m.max_amount IS NULL OR m.max_amount = 0 OR m.max_amount >= :max_amount)
              {$shippingFilter}
            ORDER BY m.sort_order, m.name
        ");
        $stmt->execute($params);

        $methods = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (($row['service_type'] ?? '') === 'invoice') {
                if ($customerId === null || !Customers::canInvoicePurchase($customerId, $amount)) continue;
                $profile = Customers::commerceProfile($customerId);
                $row['payment_terms_days'] = (int)($profile['payment_terms_days'] ?? 0);
                $row['company_id'] = (int)($profile['company_id'] ?? 0);
            }
            $methods[] = self::methodRowToQuote($row, $amount, $currency);
        }
        return $methods;
    }

    public static function selectMethod(string $methodCode, string $country, string $currency, int $amount, string $shippingMethod = '', ?int $customerId = null): ?array {
        foreach (self::availableMethods($country, $currency, $amount, $shippingMethod, $customerId) as $method) {
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
        return self::applyTransition($order, $to, $eventType, $amount > 0 ? $amount : (int)($order['total'] ?? 0), $idempotencyKey, $payload);
    }

    /** Guarded state-machine transition; $amount is recorded exactly (0 = no money moved). */
    private static function applyTransition(array $order, string $to, string $eventType, int $amount, string $idempotencyKey, array $payload, string $providerRef = ''): bool {
        $orderId = (int)$order['id'];
        $from = (string)($order['payment_status'] ?? 'pending');
        if ($from === $to) return false;
        if (!self::canPaymentTransition($from, $to)) {
            throw new \LogicException("Cannot transition payment for order #{$orderId} from '{$from}' to '{$to}'");
        }

        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? '{}'), true) ?: [];
        $provider = (string)($snapshot['provider'] ?? $order['payment_method'] ?? '');
        if ($providerRef === '') $providerRef = (string)($order['payment_id'] ?? '');
        $currency = (string)($order['currency'] ?? 'eur');

        $db = Db::get();
        $db->beginTransaction();
        try {
            if ($idempotencyKey === '' || !self::transactionExists($idempotencyKey)) {
                self::recordEvent($orderId, $eventType, $to, $amount, $currency, $idempotencyKey, $payload, $provider, $providerRef);
                self::updatePaymentStatus($orderId, $to, $eventType, [
                    'amount' => $amount,
                    'currency' => strtolower($currency),
                    'provider' => $provider,
                    'provider_ref' => $providerRef,
                ], $from);
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
    public static function initiate(array $order, PaymentAdapter $gateway, ?string $providerCode = null): array {
        StoreContext::current()->assertPermission('payment');
        if (empty($order['order_id']) || !Orders::byId((int)$order['order_id'])) {
            throw new \RuntimeException('Payment order does not belong to the active store');
        }
        $session = $gateway->createSession($order);

        // Log initiated transaction
        self::logTransaction(
            orderId:        (int)$order['order_id'],
            provider:       $providerCode ?? self::providerName($gateway),
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
        return self::processEvent($gateway->handleWebhook($parsedPayload), $providerName);
    }

    /**
     * Process an unsigned provider notification that only references a payment. The event is
     * built from the provider's authenticated API (never from the request), then follows the
     * same dedup, routing and state machine as signed webhooks.
     */
    public static function handlePulledNotification(array $notification, PullVerifiedNotificationAdapter $gateway, string $providerName): array {
        return self::processEvent($gateway->verifiedEvent($notification), $providerName);
    }

    private static function processEvent(array $event, string $providerName): array {
        $eventType = $event['event_type'] ?? '';
        $eventId   = (string)($event['event_id'] ?? '');
        $data      = $event['data'] ?? [];

        // 3. Idempotency — every accepted event needs a stable provider ID.
        if ($eventId === '' || $eventType === '') {
            throw new \RuntimeException('Webhook event identity is missing');
        }
        $eventKey = 'payment_' . $providerName . '_' . $eventId;
        if (Idempotency::webhookAlreadyProcessed($eventId) || Idempotency::webhookProcessed($eventKey)) {
            return ['handled' => false, 'event_type' => $eventType, 'order_id' => null, 'duplicate' => true];
        }

        $status = self::eventTypeToTxStatus($eventType);
        try {
            // 4. Resolve the order in every store sharing the verified provider scope.
            try {
                $target = PaymentWebhookRouting::locate(self::orderRefs($data, $providerName), $providerName);
            } catch (PaymentWebhookUnresolved $e) {
                if ($status === 'unknown') {
                    return ['handled' => true, 'event_type' => $eventType, 'order_id' => null];
                }
                Idempotency::unmark($eventKey);
                $parked = PaymentWebhookRouting::park($providerName, $eventId, $eventType, $e);
                return ['handled' => false, 'event_type' => $eventType, 'order_id' => null, 'parked' => $parked];
            }
            $orderId = $target['id'];

            // 5. Apply through the payment state machine inside the order's own store.
            PaymentWebhookRouting::within($target['store_id'], static fn() => self::applyWebhookEvent($orderId, $providerName, $eventType, $eventKey, $data));
            PaymentWebhookRouting::resolved($providerName, $eventId, $target['store_id']);
        } catch (\Throwable $e) {
            Idempotency::unmark($eventKey);
            throw $e;
        }

        return ['handled' => true, 'event_type' => $eventType, 'order_id' => $orderId];
    }

    /**
     * Refund a transaction (full or partial).
     * Logs a 'refund_initiated' transaction, queues refund job.
     */
    public static function refund(
        int $orderId,
        string $paymentId,
        int $amount,
        PaymentAdapter $gateway,
        ?string $idempotencyKey = null,
        ?string $currency = null
    ): array {
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            if (!$gateway instanceof IdempotentRefundAdapter) {
                throw new RuntimeException('Payment provider does not support idempotent refunds');
            }
            $result = $gateway->refundWithIdempotency($paymentId, $amount, $idempotencyKey);
        } else {
            $result = $gateway->refund($paymentId, $amount);
        }

        $status = $result['status'] ?? 'failed';
        self::logTransaction(
            orderId:        $orderId,
            provider:       self::providerName($gateway),
            providerRef:    $result['refund_id'] ?? '',
            status:         'refund_' . $status,
            amount:         $amount,
            currency:       strtolower($currency ?: (string)(Orders::byId($orderId)['currency'] ?? 'eur')),
            idempotencyKey: $idempotencyKey ?: ('refund_' . $orderId . '_' . ($result['refund_id'] ?? uniqid())),
            rawResponse:    $result,
        );

        return $result;
    }

    /**
     * Get all transactions for an order.
     */
    public static function transactionsForOrder(int $orderId): array {
        $stmt = Db::get()->prepare(
            "SELECT * FROM payment_transactions WHERE order_id = :oid AND store_id = :store ORDER BY created_at"
        );
        $stmt->execute([':oid' => $orderId, ':store' => StoreContext::current()->id()]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function refundedAmount(int $orderId): int {
        $stmt = Db::get()->prepare("\n            SELECT COALESCE(SUM(amount), 0)\n            FROM payment_transactions\n            WHERE order_id = :order_id AND store_id = :store\n              AND status IN ('partially_refunded', 'refunded', 'refund_succeeded')\n        ");
        $stmt->execute([':order_id' => $orderId, ':store' => StoreContext::current()->id()]);
        return max(0, (int)$stmt->fetchColumn());
    }

    public static function refundableBalance(int $orderId): int {
        $order = Orders::byId($orderId);
        if (!$order || !in_array((string)($order['payment_status'] ?? ''), ['paid', 'refund_pending', 'partially_refunded', 'disputed'], true)) {
            return 0;
        }
        return max(0, (int)($order['total'] ?? 0) - self::refundedAmount($orderId));
    }

    public static function requestRefund(int $orderId, int $amount, string $idempotencyKey, array $payload = []): bool {
        if ($amount <= 0 || $idempotencyKey === '') throw new \InvalidArgumentException('Invalid refund request');

        $db = Db::get();
        $db->beginTransaction();
        try {
            $order = Orders::byId($orderId);
            if (!$order) throw new \RuntimeException("Order #{$orderId} not found");
            $from = (string)($order['payment_status'] ?? 'pending');
            if (!self::canPaymentTransition($from, 'refund_pending') || $amount > self::refundableBalance($orderId)) {
                throw new \LogicException('Refund is not available for this amount');
            }

            $claim = $db->prepare("\n                UPDATE orders\n                SET payment_status = 'refund_pending', updated_at = " . Db::now() . "\n                WHERE id = :id AND store_id = :store AND payment_status = :from\n            ");
            $claim->execute([':id' => $orderId, ':store' => StoreContext::current()->id(), ':from' => $from]);
            if ($claim->rowCount() !== 1 || self::transactionExists($idempotencyKey)) {
                throw new \RuntimeException('Refund request state changed');
            }

            $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? '{}'), true) ?: [];
            self::logTransaction(
                orderId: $orderId,
                provider: (string)($snapshot['provider'] ?? $order['payment_method'] ?? ''),
                providerRef: (string)($order['payment_id'] ?? ''),
                status: 'refund_pending',
                amount: $amount,
                currency: (string)($order['currency'] ?? 'eur'),
                idempotencyKey: $idempotencyKey,
                rawResponse: array_merge(['event_type' => 'refund.requested'], $payload),
                failClosed: true,
            );
            AuditLog::log('payment_status', $orderId, $from, 'refund_pending', 'refund.requested');
            Outbox::record(DomainEvents::PAYMENT_STATUS_CHANGED, 'order', $orderId, [
                'order_id' => $orderId,
                'from' => $from,
                'to' => 'refund_pending',
                'event_type' => 'refund.requested',
                'amount' => $amount,
                'currency' => strtolower((string)($order['currency'] ?? 'eur')),
                'provider' => (string)($snapshot['provider'] ?? $order['payment_method'] ?? ''),
                'provider_ref' => (string)($order['payment_id'] ?? ''),
            ]);
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Update orders.payment_status.
     */
    public static function updatePaymentStatus(int $orderId, string $status, string $eventType = 'payment.status_changed', array $details = [], ?string $expectedFrom = null): void {
        $order = Orders::byId($orderId);
        if (!$order) throw new RuntimeException("Order #{$orderId} not found");
        $from = $expectedFrom ?? (string)($order['payment_status'] ?? 'pending');
        $db = Db::get();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();
        try {
            $params = [':s' => $status, ':id' => $orderId, ':store' => StoreContext::current()->id()];
            if ($expectedFrom !== null) $params[':from'] = $expectedFrom;
            $update = $db->prepare(
                "UPDATE orders SET payment_status = :s, updated_at = " . Db::now() . " WHERE id = :id AND store_id = :store"
                . ($expectedFrom !== null ? ' AND payment_status = :from' : '')
            );
            $update->execute($params);
            // Compare-and-set: a concurrent provider event must not be overwritten blindly.
            if ($expectedFrom !== null && $update->rowCount() !== 1) {
                throw new RuntimeException("Payment status of order #{$orderId} changed concurrently");
            }
            if ($from !== $status) {
                Outbox::record(DomainEvents::PAYMENT_STATUS_CHANGED, 'order', $orderId, array_merge([
                    'order_id' => $orderId,
                    'from' => $from,
                    'to' => $status,
                    'event_type' => $eventType,
                ], $details));
            }
            if ($ownTransaction) $db->commit();
        } catch (Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function renameMethodCodeLinks(string $oldCode, string $newCode): void {
        if ($oldCode === '' || $oldCode === $newCode) return;
        $db = Db::get();
        $storeId = StoreContext::current()->providerId();
        $db->prepare("UPDATE payment_method_countries SET method_code = :new WHERE method_code = :old AND store_id = :store")->execute([':new' => $newCode, ':old' => $oldCode, ':store' => $storeId]);
        $db->prepare("UPDATE payment_method_shipping SET method_code = :new WHERE method_code = :old AND store_id = :store")->execute([':new' => $newCode, ':old' => $oldCode, ':store' => $storeId]);
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
        bool   $failClosed = false,
    ): void {
        try {
            $db   = Db::get();
            $now  = Db::now();
            $stmt = $db->prepare(Db::insertIgnore() . "
                INTO payment_transactions
                    (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response, correlation_id, created_at, updated_at)
                VALUES
                    (:oid, :store, :prov, :ref, :status, :amt, :cur, :ikey, :raw, :correlation, {$now}, {$now})
            ");
            $stmt->execute([
                ':oid'    => $orderId,
                ':store'  => StoreContext::current()->id(),
                ':prov'   => $provider,
                ':ref'    => $providerRef,
                ':status' => $status,
                ':amt'    => $amount,
                ':cur'    => $currency,
                ':ikey'   => $idempotencyKey,
                ':raw'    => json_encode($rawResponse, JSON_UNESCAPED_UNICODE),
                ':correlation' => Logger::correlationId(),
            ]);
        } catch (\Throwable $e) {
            if ($failClosed) throw $e;
            // Never let logging failure break merchant-initiated payment operations.
            error_log("PaymentService: failed to log transaction for order #{$orderId}: " . $e->getMessage());
        }
    }

    /** Trusted (signed) order references, in resolution priority order. */
    private static function orderRefs(array $data, string $provider): array {
        if (!empty($data['order_id'])) return ['order_id' => (int)$data['order_id']];
        return match ($provider) {
            'stripe' => array_filter([
                'order_id' => (int)($data['metadata']['order_id'] ?? 0),
                'session_id' => (string)($data['id'] ?? ''),
                'order_number' => (string)($data['client_reference_id'] ?? ''),
                'payment_id' => (string)($data['payment_intent'] ?? $data['id'] ?? ''),
            ]),
            'payu' => array_filter([
                'order_number' => (string)($data['extOrderId'] ?? ''),
                'payment_id' => (string)($data['orderId'] ?? ''),
            ]),
            // Other adapters report the shop's order number and their payment id under these keys.
            default => array_filter([
                'order_number' => (string)($data['order_number'] ?? ''),
                'payment_id' => (string)($data['payment_id'] ?? ''),
            ]),
        };
    }

    /**
     * Provider events never bypass the payment state machine. Money events must match the order
     * currency (and, for captures, the exact total); refunds accumulate per refund. Events that do
     * not apply (late failure after capture, refund of an unpaid order, repeated capture) change
     * nothing and stay in the payment log as 'ignored'.
     */
    private static function applyWebhookEvent(int $orderId, string $provider, string $eventType, string $eventKey, array $data): void {
        $order = Orders::byId($orderId);
        if (!$order) throw new RuntimeException("Order #{$orderId} vanished during payment webhook");
        $from = (string)($order['payment_status'] ?? 'pending');
        $total = (int)$order['total'];
        $orderCurrency = strtolower((string)($order['currency'] ?? 'eur'));
        $amount = (int)($data['amount'] ?? 0);
        $currency = strtolower((string)($data['currency'] ?? ''));
        if (in_array($eventType, ['payment.paid', 'refund.succeeded'], true)
            && ($currency !== $orderCurrency || $amount <= 0 || ($eventType === 'payment.paid' && $amount !== $total))) {
            throw new RuntimeException("Payment webhook {$eventType} amount/currency does not match order #{$orderId}");
        }
        // A refund the worker already executed and logged under the provider refund id moves no new money.
        if ($eventType === 'refund.succeeded' && self::refundAlreadyRecorded($orderId, (string)($data['refund_id'] ?? ''))) $amount = 0;

        $to = match ($eventType) {
            'payment.paid' => 'paid',
            'payment.failed' => in_array($from, ['pending', 'authorized'], true) ? 'failed' : null,
            'payment.disputed' => 'disputed',
            'refund.succeeded' => self::refundedAmount($orderId) + $amount >= $total ? 'refunded' : 'partially_refunded',
            default => null,
        };
        $payload = array_merge(['provider' => $provider], $data);
        $providerRef = (string)($data['refund_id'] ?? $data['provider_ref'] ?? $data['orderId'] ?? $data['id'] ?? '');
        if ($to !== null && $to !== $from && self::canPaymentTransition($from, $to)) {
            self::applyTransition($order, $to, $eventType, $amount, $eventKey, $payload, (string)($data['refund_id'] ?? ''));
        } elseif ($to === 'partially_refunded' && $from === $to && $amount > 0) {
            self::recordEvent($orderId, $eventType, $to, $amount, $orderCurrency, $eventKey, $payload, $provider, $providerRef);
        } else {
            self::recordEvent($orderId, $eventType, 'ignored', $amount, $currency ?: $orderCurrency, $eventKey, $payload + ['payment_status' => $from], $provider, $providerRef);
            if ($to !== null) AuditLog::log('payment_webhook_ignored', $orderId, $from, $from, "{$provider} {$eventType}");
            return;
        }

        $order = Orders::byId($orderId) ?: $order;
        if ($to === 'paid' && OrderWorkflow::canTransition((string)$order['status'], 'paid')) {
            OrderWorkflow::transition($orderId, 'paid', 'Payment confirmed via webhook');
        } elseif ($to === 'refunded' && in_array($order['status'], ['returned', 'cancelled'], true)
            && OrderWorkflow::canTransition((string)$order['status'], 'refunded')) {
            OrderWorkflow::transition($orderId, 'refunded', 'Refund confirmed via webhook');
        }
    }

    private static function refundAlreadyRecorded(int $orderId, string $refundId): bool {
        if ($refundId === '') return false;
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store AND provider_ref = :ref
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded') LIMIT 1");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':ref' => $refundId]);
        return (bool)$stmt->fetchColumn();
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
            'payment_terms_days' => isset($row['payment_terms_days']) ? (int)$row['payment_terms_days'] : null,
            'company_id' => isset($row['company_id']) ? (int)$row['company_id'] : null,
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
        try {
            return Adapters::providerCode('payment', $gateway);
        } catch (\RuntimeException $e) {
            $class = get_class($gateway);
            return match(true) {
                str_contains($class, 'Stripe') => 'stripe',
                str_contains($class, 'PayU') => 'payu',
                str_contains($class, 'Mock') => 'mock',
                str_contains($class, 'Cash') => 'cod',
                default => strtolower($class),
            };
        }
    }
}
