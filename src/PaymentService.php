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
        // Money captured after a failure, a cancellation or an expiry is a fact the ledger records;
        // settleCapture() then pays the order or refunds the cancelled one.
        'failed' => ['paid', 'cancelled'],
        'cancelled' => ['paid'],
        'refunded' => [],
    ];

    /**
     * Payment instruments a consumer may not be surcharged for (PSD2 art. 62(4), Directive 2011/83/EU art. 19):
     * consumer cards and SEPA credit transfers. Their configured fee applies to approved business accounts only.
     */
    public const NO_CONSUMER_SURCHARGE = ['online_card', 'bank_transfer'];
    /** Service types the admin form offers, with their label keys; 'invoice' is the B2B purchase on account. */
    public const SERVICE_TYPE_LABELS = [
        'online_card'   => 'admin.payments.type_online_card',
        'bank_transfer' => 'admin.payments.type_bank_transfer',
        'cod'           => 'admin.shipping.cod',
        'invoice'       => 'admin.b2b.invoice_purchase',
        'offline'       => 'admin.payments.type_offline',
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
        $ownPrices = StoreContext::current()->usesProviderPrices($currency);
        $businessBuyer = Customers::isBusinessBuyer($customerId);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            // An online method whose gateway has no credentials cannot start a payment: not offered (the doctor reports it).
            if ((int)($row['requires_online'] ?? 0) === 1 && !self::gatewayCanStart((string)($row['provider'] ?? ''))) continue;
            // A transfer to a missing or mistyped IBAN would leave the customer with an order they cannot pay.
            if (($row['service_type'] ?? '') === 'bank_transfer' && BankTransferInstructions::storeIban() === '') continue;
            if (!$businessBuyer && in_array((string)($row['service_type'] ?? ''), self::NO_CONSUMER_SURCHARGE, true)) {
                $row['fixed_fee'] = 0;
                $row['percent_fee_bps'] = 0;
            }
            // Fees and limits are amounts in the scope owner's currency: a cart in another currency
            // only gets methods that have none (a percentage fee is currency-neutral).
            if (!$ownPrices && ((int)$row['fixed_fee'] > 0 || (int)$row['min_amount'] > 0 || (int)($row['max_amount'] ?? 0) > 0 || (int)($row['free_above'] ?? 0) > 0)) continue;
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

    /** False only for a shipped gateway (not an extension's or a substituted adapter) that lacks its credentials. */
    public static function gatewayCanStart(string $provider): bool {
        if (Adapters::paymentSubstituted($provider)) return true;
        try {
            $gateway = Adapters::payment($provider);
        } catch (\Throwable $e) {
            // An unknown or disabled provider stays listed and fails at payment start, where the customer sees it.
            Logger::exception('payment.gateway_unavailable', $e, ['provider' => $provider]);
            return true;
        }
        return (Adapters::registry('payment')[$provider]['class'] ?? null) !== get_class($gateway)
            || PaymentReadiness::canTakePayments($provider, Security::config());
    }

    public static function selectMethod(string $methodCode, string $country, string $currency, int $amount, string $shippingMethod = '', ?int $customerId = null): ?array {
        foreach (self::availableMethods($country, $currency, $amount, $shippingMethod, $customerId) as $method) {
            if (($method['code'] ?? '') === $methodCode) return $method;
        }
        return null;
    }

    /**
     * Fee checkout charges for a quoted method. Under reverse charge or export ($netOfDomesticVat) the fixed part
     * loses the seller's domestic VAT like shipping does; the percentage part is already taken of the net amount.
     */
    public static function chargedFee(array $quote, bool $netOfDomesticVat, string $sellerCountry, ?string $taxProviderCode = null): int {
        $fee = max(0, (int)($quote['fee'] ?? 0));
        $fixedFee = min($fee, max(0, (int)($quote['fixed_fee'] ?? $fee)));
        if (!$netOfDomesticVat || $fixedFee === 0) return $fee;
        return $fee - $fixedFee + Money::round(Cart::netOfDomesticVat($fixedFee, $sellerCountry, 'standard', $taxProviderCode), (string)($quote['currency'] ?? 'eur'));
    }

    /** Cash on delivery is a method's service type, not its (renamable) code. */
    public static function isCashOnDelivery(string $methodCode): bool {
        if (!self::hasMethodTables()) return $methodCode === 'cod';
        $stmt = Db::get()->prepare("SELECT service_type FROM payment_methods WHERE store_id = :store AND code = :code");
        $stmt->execute([':store' => StoreContext::current()->providerId(), ':code' => $methodCode]);
        return $stmt->fetchColumn() === 'cod';
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
        // Read under the order row lock: a cancellation committing after a plain read failed the compare-and-set
        // (SQLite: "database is locked") and the confirmed payment answered 500.
        return self::withLockedOrder($orderId, static fn(array $order): bool =>
            self::applyTransition($order, $to, $eventType, $amount > 0 ? $amount : (int)($order['total'] ?? 0), $idempotencyKey, $payload));
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
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();
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
                if ($ownTransaction) {
                    $db->commit();
                    self::flushRefundMails();
                }
                return true;
            }
            if ($ownTransaction) $db->rollBack();
            return false;
        } catch (\Throwable $e) {
            self::rollBackOwn($ownTransaction);
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
        // 1. Verify signature — ALWAYS required; gateways without signed webhooks fail closed
        if (!$gateway instanceof SignedWebhookPaymentAdapter || !$gateway->verifySignature($rawPayload, $sigHeader)) {
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

    /**
     * Apply an event the worker read from the provider's authenticated API (PaymentStatusEventAdapter::statusEvent()),
     * with the same dedup, routing and state machine as a notification of it.
     */
    public static function applyProviderStatusEvent(array $event, string $providerName): array {
        return self::processEvent($event, $providerName);
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
        $claim = Idempotency::webhookAlreadyProcessed($eventId) ? 'done' : Idempotency::claim($eventKey);
        if ($claim === 'in_flight') {
            return ['handled' => false, 'event_type' => $eventType, 'order_id' => null, 'retry' => true];
        }
        if ($claim === 'done') {
            return ['handled' => false, 'event_type' => $eventType, 'order_id' => null, 'duplicate' => true];
        }

        $status = self::eventTypeToTxStatus($eventType);
        try {
            // 4. Resolve the order in every store sharing the verified provider scope.
            try {
                $target = PaymentWebhookRouting::locate(self::orderRefs($data, $providerName), $providerName);
            } catch (PaymentWebhookUnresolved $e) {
                if ($status === 'unknown') {
                    Idempotency::complete($eventKey);
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
        Idempotency::complete($eventKey);

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
        if ($idempotencyKey !== null && $idempotencyKey !== '' && $gateway instanceof IdempotentRefundAdapter) {
            // R25: from here on the provider may report this refund before its result is booked (inFlightCandidatesWithin()).
            $dispatchKey = 'refund_dispatched_' . $idempotencyKey;
            if (!self::transactionExists($dispatchKey)) {
                self::logTransaction(orderId: $orderId, provider: self::providerName($gateway), providerRef: $paymentId, status: 'refund_dispatched', amount: 0,
                    currency: strtolower($currency ?: (string)(Orders::byId($orderId)['currency'] ?? 'eur')), idempotencyKey: $dispatchKey,
                    rawResponse: ['payment_id' => $paymentId, 'amount' => $amount], failClosed: true);
            }
            $result = $gateway->refundWithIdempotency($paymentId, $amount, $idempotencyKey);
        } elseif ($idempotencyKey !== null && $idempotencyKey !== '') {
            // Without provider idempotency a retry after an unknown outcome could refund twice:
            // the key allows one provider call, any further attempt is for a human to reconcile.
            $attemptKey = 'refund_attempt_' . $idempotencyKey;
            if (self::transactionExists($attemptKey)) {
                throw new RuntimeException('Refund was already attempted at a provider without idempotent refunds; reconcile manually');
            }
            self::logTransaction(
                orderId: $orderId,
                provider: self::providerName($gateway),
                providerRef: $paymentId,
                status: 'refund_attempted',
                amount: $amount,
                currency: strtolower($currency ?: (string)(Orders::byId($orderId)['currency'] ?? 'eur')),
                idempotencyKey: $attemptKey,
                rawResponse: ['payment_id' => $paymentId],
                failClosed: true,
            );
            $result = $gateway->refund($paymentId, $amount);
        } else {
            $result = $gateway->refund($paymentId, $amount);
        }

        $status = (string)($result['status'] ?? 'failed');
        $outcome = $status;
        $refundId = (string)($result['refund_id'] ?? '');
        $db = Db::get();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();
        try {
            // The provider's refund webhook may have booked this refund (by its id, or for GoPay/ThePay, which name
            // none, by the refund request it settled) first: the ledger keeps one money row per provider refund,
            // this call's row then only confirms it (refund_confirmed moves no money).
            self::lockOrderWithin($orderId);
            if ($status === 'succeeded' && (self::refundAlreadyRecorded($orderId, $refundId)
                || ($refundId === '' && self::refundRequestSettled($orderId, (string)$idempotencyKey, $amount)))) {
                $status = 'confirmed';
            } elseif ($refundId !== '' && in_array($status, ['succeeded', 'pending'], true) && self::refundSettledByEvent($orderId, (string)$idempotencyKey)) {
                // R24: a Stripe charge.refunded without its refund list booked this very refund already (bookCumulativeRefund()).
                $status = 'confirmed';
                self::noteRefundIdWithin($orderId, (string)$idempotencyKey, $refundId);
            }
            self::logTransaction(
                orderId:        $orderId,
                provider:       self::providerName($gateway),
                providerRef:    $refundId,
                status:         'refund_' . $status,
                amount:         $amount,
                currency:       strtolower($currency ?: (string)(Orders::byId($orderId)['currency'] ?? 'eur')),
                idempotencyKey: $idempotencyKey ?: ('refund_' . $orderId . '_' . ($refundId !== '' ? $refundId : uniqid())),
                rawResponse:    $result,
            );
            // R26: a refund still pending at the provider is not completed by the money a summary event booked for it: it may
            // fail yet (providerRefundFailed() then takes that booking back); its refund.succeeded completes it.
            self::linkItemRefundWithin($orderId, (string)$idempotencyKey, $refundId, $outcome === 'pending' ? 'pending' : $status);
            // R26: the call's result is recorded, so the marker that it may be at the provider goes (it stayed for good).
            if ((string)$idempotencyKey !== '') {
                Db::get()->prepare("DELETE FROM payment_transactions WHERE order_id = :order AND store_id = :store AND idempotency_key = :key AND status = 'refund_dispatched'")
                    ->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':key' => 'refund_dispatched_' . $idempotencyKey]);
            }
            // R25: money an event booked provisionally as this refund was another refund. R26: the refund of that sum still in
            // flight, one booked on its own (taken back), or else other money with a credit note of its own.
            foreach (self::provisionalRefundsWithin($orderId) as $part) {
                if ($part['execute'] === (string)$idempotencyKey && $part['state'] === 'failed') {
                    self::failProvisionalWithin(Orders::byId($orderId) ?: ['id' => $orderId], $part, self::providerName($gateway), $part['ledger'] . '@' . $part['execute'], false);
                }
            }
            self::rebookReclaimedWithin($orderId, self::providerName($gateway), 'refund_' . $status);
            if ($ownTransaction) {
                $db->commit();
                self::flushRefundMails();
            }
        } catch (\Throwable $e) {
            self::rollBackOwn($ownTransaction);
            throw $e;
        }

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
        $stmt = Db::get()->prepare("\n            SELECT COALESCE(SUM(amount), 0)\n            FROM payment_transactions\n            WHERE order_id = :order_id AND store_id = :store\n              AND status IN ('partially_refunded', 'refunded', 'refund_succeeded', 'refund_reversed')\n        ");
        $stmt->execute([':order_id' => $orderId, ':store' => StoreContext::current()->id()]);
        return max(0, (int)$stmt->fetchColumn());
    }

    public static function refundableBalance(int $orderId): int {
        $order = Orders::byId($orderId);
        if (!$order || !in_array((string)($order['payment_status'] ?? ''), ['paid', 'refund_pending', 'partially_refunded', 'disputed'], true)) {
            return 0;
        }
        return max(0, (int)($order['total'] ?? 0) - self::refundedAmount($orderId) - self::offLedgerCredits($order));
    }

    /**
     * Credit notes settled outside the payment ledger: an order without a payment id has its item refunds
     * paid back by hand (Rma::completeRefund('manual-admin')), which books no payment transaction. That money
     * is returned all the same, so the order must not refund it again (InvoiceService::refundableForCredit()).
     */
    private static function offLedgerCredits(array $order): int {
        if (trim((string)($order['payment_id'] ?? '')) !== '') return 0;
        $stmt = Db::get()->prepare("SELECT COALESCE(SUM(amount), 0) FROM order_refunds WHERE order_id = :order AND store_id = :store AND status = 'succeeded'");
        $stmt->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id()]);
        return max(0, (int)$stmt->fetchColumn());
    }

    /**
     * Pending credit notes (InvoiceService::credit()) whose provider refund the ledger does not hold yet (under
     * their key or their provider refund id): that money is already promised back, so no new refund may claim it.
     */
    public static function unbookedCreditAmount(int $orderId): int {
        $stmt = Db::get()->prepare("SELECT COALESCE(SUM(r.amount), 0) FROM order_refunds r
            WHERE r.order_id = :order AND r.store_id = :store AND r.status = 'pending' AND NOT EXISTS (
                SELECT 1 FROM payment_transactions t
                WHERE t.order_id = r.order_id AND t.store_id = r.store_id
                  AND t.status IN ('partially_refunded', 'refunded', 'refund_succeeded')
                  AND (t.idempotency_key = r.idempotency_key OR (r.payment_reference IS NOT NULL AND r.payment_reference <> '' AND t.provider_ref = r.payment_reference)))");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        return max(0, (int)$stmt->fetchColumn());
    }

    /** What a new refund (admin request, cancellation, manual refund) may still claim: the balance less pending credit notes. */
    public static function claimableRefund(int $orderId): int {
        return max(0, self::refundableBalance($orderId) - self::unbookedCreditAmount($orderId));
    }

    /** A refund requested (requestRefund()) whose money is not booked yet: a credit note must not promise it again. */
    public static function requestedRefundInFlight(array $order): int {
        return (int)(self::latestRefundRequestRow($order, true)['amount'] ?? 0);
    }

    public static function requestRefund(int $orderId, int $amount, string $idempotencyKey, array $payload = []): bool {
        if ($amount <= 0 || $idempotencyKey === '') throw new \InvalidArgumentException('Invalid refund request');

        $db = Db::get();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();
        try {
            // Under the order lock a credit note takes too (InvoiceService::credit()): read outside it, the balance missed
            // a credit note committing meanwhile and both together returned more than was paid.
            self::lockOrderWithin($orderId);
            $order = Orders::byId($orderId);
            if (!$order) throw new \RuntimeException("Order #{$orderId} not found");
            $from = (string)($order['payment_status'] ?? 'pending');
            if (!self::canPaymentTransition($from, 'refund_pending') || $amount > self::claimableRefund($orderId)) {
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
            if ($ownTransaction) $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * A refund an admin requested: claimed on the ledger like requestRefund(), then executed by a
     * payment_refund job at the provider (its refund webhook may complete it first). Money returned
     * by hand (refundsByHand()) cannot be requested: nothing would execute it (R16: the payment stayed
     * refund_pending for ever); the admin records it with completeManualRefund().
     */
    public static function requestRefundAndQueue(int $orderId, int $amount, string $idempotencyKey, array $payload = []): void {
        if (Db::get()->inTransaction()) throw new \LogicException('The refund job is queued after commit: call outside a transaction');
        $order = Orders::byId($orderId);
        if ($order && self::refundsByHand($order)) throw new \LogicException('This payment is returned by hand: record the refund with completeManualRefund()');
        self::requestRefund($orderId, $amount, $idempotencyKey, $payload);
        $order = Orders::byId($orderId);
        if (!$order) return;
        JobQueue::push('payment_refund', [
            'order_id' => $orderId,
            'payment_id' => (string)$order['payment_id'],
            'amount' => $amount,
            'idempotency_key' => self::refundExecuteKey($idempotencyKey),
        ], 1);
    }

    /**
     * The latest refund request no payment_refund job ever executed (e.g. requested before requestRefundAndQueue()
     * queued one): the payment is refund_pending at a provider with a refund API, nothing was booked after the
     * request and its execute key never reached the ledger.
     *
     * @return array{amount:int, idempotency_key:string}|null its amount and the job's execute key
     */
    public static function unexecutedRefundRequest(array $order): ?array {
        $request = self::latestRefundRequestRow($order);
        if ($request === null) return null;
        $db = Db::get();
        $params = [':order' => (int)$order['id'], ':store' => StoreContext::current()->id()];
        $executeKey = self::refundExecuteKey((string)$request['idempotency_key']);
        $later = $db->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store
            AND ((id > :id AND status IN ('partially_refunded', 'refunded', 'refund_succeeded', 'refund_confirmed')) OR idempotency_key IN (:execute, :attempt)) LIMIT 1");
        $later->execute($params + [':id' => (int)$request['id'], ':execute' => $executeKey, ':attempt' => 'refund_attempt_' . $executeKey]);
        return $later->fetchColumn() ? null : ['amount' => (int)$request['amount'], 'idempotency_key' => $executeKey];
    }

    /**
     * Queue the payment_refund job of a refund request no job ever executed (unexecutedRefundRequest()), audited.
     * Its execute key is the one requestRefundAndQueue() would have used, so a job still queued for it cannot refund twice.
     */
    public static function retryRefundRequest(int $orderId, string $actor): bool {
        if (Db::get()->inTransaction()) throw new \LogicException('The refund job is queued after commit: call outside a transaction');
        $order = Orders::byId($orderId);
        $request = $order ? self::unexecutedRefundRequest($order) : null;
        if ($order === null || $request === null) return false;
        JobQueue::push('payment_refund', [
            'order_id' => $orderId,
            'payment_id' => (string)$order['payment_id'],
            'amount' => $request['amount'],
            'idempotency_key' => $request['idempotency_key'],
        ], 1);
        AuditLog::log('order_refund', $orderId, 'refund_pending', 'refund_pending', 'Refund of ' . AuditLogLabels::amount((int)$request['amount'], $order['currency'] ?? 'eur') . " re-queued by {$actor}");
        return true;
    }

    /**
     * The latest requestRefund() row of a refund_pending payment at a provider with a refund API.
     *
     * @return array{id:mixed, amount:mixed, idempotency_key:mixed, raw_response:mixed}|null
     */
    private static function latestRefundRequestRow(array $order, bool $byHandToo = false): ?array {
        if ((string)($order['payment_status'] ?? '') !== 'refund_pending' || (!$byHandToo && self::refundsByHand($order))) return null;
        $stmt = Db::get()->prepare("SELECT id, amount, idempotency_key, raw_response FROM payment_transactions WHERE order_id = :order AND store_id = :store AND status = 'refund_pending' ORDER BY id DESC");
        $stmt->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id()]);
        // Only requestRefund() rows are requests. A job the provider answered "pending" also logs refund_pending, under
        // its execute key or (credit notes, RMA) its own key: that row is the executed refund, and re-deriving an
        // execute key from it would refund again.
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $raw = json_decode((string)($row['raw_response'] ?? ''), true);
            if (!is_array($raw) || ($raw['event_type'] ?? null) !== 'refund.requested') continue;
            // One request at a time: a refund booked after the latest one settled it, so a later item refund is in flight.
            $later = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store
                AND id > :id AND status IN ('partially_refunded', 'refunded', 'refund_succeeded', 'refund_confirmed') LIMIT 1");
            $later->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id(), ':id' => (int)$row['id']]);
            return $later->fetchColumn() ? null : $row;
        }
        return null;
    }

    /**
     * A refund request the provider accepted without naming a refund id (GoPay, ThePay): no refund
     * notification can identify it, and a partial refund is reported without its amount, so the payment
     * would stay refund_pending forever. The admin who saw it paid out completes it (confirmProviderRefund()).
     * Item refunds (credit notes, RMA) are executed under their own key and carry their order_refunds id.
     *
     * @return array{amount:int, request_key:string, refund_id?:int}|null
     */
    public static function unconfirmedProviderRefund(array $order): ?array {
        $request = self::latestRefundRequestRow($order);
        if ($request === null) return self::unconfirmedItemRefund($order);
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store
            AND idempotency_key = :execute AND status = 'refund_pending' AND (provider_ref IS NULL OR provider_ref = '') LIMIT 1");
        $stmt->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id(),
            ':execute' => self::refundExecuteKey((string)$request['idempotency_key'])]);
        return $stmt->fetchColumn() ? ['amount' => (int)$request['amount'], 'request_key' => (string)$request['idempotency_key']] : null;
    }

    /**
     * The latest pending item refund (InvoiceService::credit()) whose payment_refund job the provider accepted
     * without a refund id: no webhook can find it (Rma::noteProviderRefund() had no id to note). Two such refunds
     * can be in flight at once: confirming the first moves the payment to partially_refunded, and the second must
     * still be offered, else its money is never booked.
     *
     * @return array{amount:int, request_key:string, refund_id:int}|null
     */
    private static function unconfirmedItemRefund(array $order): ?array {
        if (!in_array((string)($order['payment_status'] ?? ''), ['refund_pending', 'partially_refunded'], true) || self::refundsByHand($order)) return null;
        $stmt = Db::get()->prepare("SELECT r.id, r.amount, r.idempotency_key FROM order_refunds r
            WHERE r.order_id = :order AND r.store_id = :store AND r.status = 'pending' AND (r.payment_reference IS NULL OR r.payment_reference = '')
              AND EXISTS (SELECT 1 FROM payment_transactions t WHERE t.order_id = r.order_id AND t.store_id = r.store_id
                AND t.idempotency_key = r.idempotency_key AND t.status = 'refund_pending' AND (t.provider_ref IS NULL OR t.provider_ref = ''))
            ORDER BY r.id DESC LIMIT 1");
        $stmt->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id()]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? ['amount' => (int)$row['amount'], 'request_key' => (string)$row['idempotency_key'], 'refund_id' => (int)$row['id']] : null;
    }

    /** Book the provider refund in flight (unconfirmedProviderRefund()) the admin verified at the provider, audited. */
    public static function confirmProviderRefund(int $orderId, string $actor, string $note): void {
        if (trim($note) === '') throw new \InvalidArgumentException('A refund confirmation needs a note');
        self::withLockedOrder($orderId, static function (array $order) use ($orderId, $actor, $note): bool {
            $pending = self::unconfirmedProviderRefund($order);
            if ($pending === null) throw new \LogicException('No provider refund awaits confirmation on this order');
            // Never more than the payment still holds (a provider report may have booked part of it already).
            if ($pending['amount'] > self::refundableBalance($orderId)) throw new \LogicException('The provider refund exceeds the refundable balance');
            // Names the request it settles, so a late provider report of the same refund books nothing more.
            if (!self::bookRefundWithin($order, self::orderProvider($order), '', $pending['amount'], 'refund.admin_confirmed',
                'refund_admin_confirmed_' . $pending['request_key'], ['actor' => $actor, 'note' => $note, 'settles_refund_request' => $pending['request_key']],
                $pending['refund_id'] ?? 0, 'admin-confirmed:' . $actor)) {
                throw new \LogicException('The provider refund could not be booked');
            }
            $to = (string)(Orders::byId($orderId)['payment_status'] ?? '');
            AuditLog::log('order_refund', $orderId, 'refund_pending', $to, 'Provider refund of ' . AuditLogLabels::amount((int)$pending['amount'], $order['currency'] ?? 'eur') . " confirmed by {$actor}: {$note}");
            return true;
        });
        self::closeRefundedOrder($orderId, 'Provider refund confirmed');
    }

    /**
     * Payment side of an order cancellation, inside the caller's transaction. An unpaid payment
     * is closed (payment_status cancelled) and the provider session voided by a payment_cancel
     * job, so the customer can no longer pay; captured money goes on the refund ledger, at most
     * $maxRefund (the goods that never shipped, Fulfillment::unshippedGross()) when part shipped.
     *
     * @return list<array{0:string,1:array,2:int}> deferred jobs
     */
    public static function cancellationJobsWithin(int $orderId, ?int $maxRefund = null): array {
        $order = Orders::byId($orderId);
        if (!$order) return [];
        $from = (string)($order['payment_status'] ?? 'pending');
        if (in_array($from, ['pending', 'authorized', 'failed'], true)) {
            self::updatePaymentStatus($orderId, 'cancelled', 'order.cancelled', [], $from);
            AuditLog::log('payment_status', $orderId, $from, 'cancelled', 'order.cancelled');
            $paymentId = trim((string)($order['payment_id'] ?? ''));
            return $paymentId === '' ? [] : [['payment_cancel', ['order_id' => $orderId, 'payment_id' => $paymentId], 1]];
        }
        return self::orderRefundJobsWithin($orderId, 'order.cancelled', $maxRefund);
    }

    /**
     * Put the whole refundable balance of a captured order on the refund ledger (once per order)
     * and return the payment_refund job that executes it with a stable provider idempotency key.
     *
     * @return list<array{0:string,1:array,2:int}>
     */
    public static function orderRefundJobsWithin(int $orderId, string $reason, ?int $maxRefund = null): array {
        $order = Orders::byId($orderId);
        $paymentId = trim((string)($order['payment_id'] ?? ''));
        $requestKey = 'order_refund_request_' . $orderId;
        if (!$order || self::transactionExists($requestKey)) return [];
        $amount = self::claimableRefund($orderId);
        if ($maxRefund !== null) $amount = min($amount, max(0, $maxRefund));
        if ($amount <= 0) return [];
        if ($paymentId === '') {
            // Offline money (bank transfer, COD) has no provider payment to refund: the merchant returns it by hand.
            // A refund already requested returns its own amount, so only the rest is flagged.
            $manual = $amount - self::pendingRefundAmount($order);
            if ($manual > 0) self::flagManualRefundWithin($order, $manual, $reason);
            return [];
        }
        $from = (string)($order['payment_status'] ?? '');
        if (!in_array($from, ['paid', 'partially_refunded'], true)) {
            // A refund already in flight or a dispute: the merchant settles it by hand.
            IncidentMonitor::report('payment.order_refund_manual', 'payment', 'critical', ['order_id' => $orderId]);
            return [];
        }
        self::requestRefund($orderId, $amount, $requestKey, ['reason' => $reason]);
        return [['payment_refund', [
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'amount' => $amount,
            'idempotency_key' => 'order_refund_' . $orderId,
        ], 1]];
    }

    /** The refund in flight: requestRefund() allows one at a time, so it is the latest request while the payment is refund_pending. */
    private static function pendingRefundAmount(array $order): int {
        return (int)(self::pendingRefundRequest($order)['amount'] ?? 0);
    }

    /** @return array{amount:int, idempotency_key:string}|null the refund request in flight (pendingRefundAmount()) */
    private static function pendingRefundRequest(array $order): ?array {
        if ((string)($order['payment_status'] ?? '') !== 'refund_pending') return null;
        $stmt = Db::get()->prepare("SELECT amount, idempotency_key FROM payment_transactions WHERE order_id = :order AND store_id = :store AND status = 'refund_pending' ORDER BY id DESC LIMIT 1");
        $stmt->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id()]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? ['amount' => max(0, (int)$row['amount']), 'idempotency_key' => (string)$row['idempotency_key']] : null;
    }

    /** The payment_refund job's execute key of a ledger refund request key (requestRefundAndQueue(), orderRefundJobsWithin()). */
    private static function refundExecuteKey(string $requestKey): string {
        return preg_match('/^order_refund_request_(\d+)$/', $requestKey, $m) ? 'order_refund_' . $m[1] : $requestKey . '_execute';
    }

    /** The ledger request key of a payment_refund job's execute key (the inverse of refundExecuteKey()). */
    public static function refundRequestKey(string $executeKey): ?string {
        if (preg_match('/^order_refund_(\d+)$/', $executeKey, $m)) return 'order_refund_request_' . $m[1];
        return str_ends_with($executeKey, '_execute') ? substr($executeKey, 0, -strlen('_execute')) : null;
    }

    /**
     * Whether a refund without a provider refund id was already booked under the order lock: a
     * refund.reported event settled this call's refund request, or the ledger could not hold it
     * on top of what is already refunded.
     */
    private static function refundRequestSettled(int $orderId, string $executeKey, int $amount): bool {
        $order = Orders::byId($orderId);
        if (!$order) return false;
        if (self::refundedAmount($orderId) + $amount > (int)$order['total']) return true;
        return self::refundRequestKey($executeKey) !== null && self::refundSettledByEvent($orderId, $executeKey);
    }

    /**
     * R18: what an amount recorded as "paid" by hand lacks to the order total (0 when it pays the order). 10 € recorded
     * for a 100 € order used to pay it and send it to fulfilment. No amount entered means the order total. Money on a
     * cancelled order is always recorded: it is flagged for a manual refund, whatever the amount.
     */
    public static function adminPaymentShortfall(array $order, string $newStatus, int $amount): int {
        if ($newStatus !== 'paid' || $amount <= 0 || (string)($order['status'] ?? '') === 'cancelled') return 0;
        return max(0, (int)$order['total'] - $amount);
    }

    /** R19: what an amount recorded as "paid" by hand exceeds the order total by (it passed without a word). */
    public static function adminPaymentExcess(array $order, string $newStatus, int $amount): int {
        if ($newStatus !== 'paid' || (string)($order['status'] ?? '') === 'cancelled') return 0;
        return max(0, $amount - (int)$order['total']);
    }

    /** R19: "0" typed as the amount is no payment (it paid the whole order); only an empty field means the order total. */
    public static function adminPaymentZero(string $newStatus, string $input, int $amount): bool {
        return $newStatus === 'paid' && $amount === 0 && trim($input) !== '';
    }

    /**
     * A payment status set by hand on the order detail. Money recorded as paid pays a pending order like a gateway
     * capture (R17: after a failed card the order stayed Pending until a second status change). Returns true when the
     * order was cancelled: the money is then flagged for a manual refund instead.
     */
    public static function recordAdminPayment(array $order, string $newStatus, int $amount, string $note, string $actor): bool {
        if (self::adminPaymentShortfall($order, $newStatus, $amount) > 0) {
            throw new \InvalidArgumentException('A payment below the order total does not pay the order');
        }
        $orderId = (int)$order['id'];
        $key = 'admin_payment_' . $orderId . '_' . $newStatus . '_' . time();
        self::transitionPayment($orderId, $newStatus, 'admin.' . $newStatus, $amount, $key, [
            'actor' => $actor,
            'note' => $note,
        ]);
        if ($newStatus !== 'paid') return false;
        if ((string)$order['status'] === 'cancelled') {
            // No gateway session captured it, so the recorded amount (the order total when none was entered) is
            // flagged for a manual refund with an incident.
            self::settleAdminPaymentOnCancelled($orderId, $amount > 0 ? $amount : (int)$order['total'], 'Admin payment: ' . $note);
            return true;
        }
        self::settleCapture($orderId, 'Admin payment' . ($note !== '' ? ': ' . $note : ''));
        return false;
    }

    /**
     * Money an admin recorded by hand on a cancelled order. No provider session captured it, so no
     * gateway refund is attempted: the recorded amount is flagged for a manual refund and a critical
     * incident raised. Captures reported by a gateway go through settleCapture().
     */
    public static function settleAdminPaymentOnCancelled(int $orderId, int $amount, string $note): void {
        $order = Orders::byId($orderId);
        if (!$order || (string)$order['status'] !== 'cancelled' || $amount <= 0) return;
        $db = Db::get();
        $db->beginTransaction();
        try {
            self::flagManualRefundWithin($order, $amount, 'payment.captured_after_cancel: ' . $note);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        IncidentMonitor::report('payment.captured_after_cancel', 'payment', 'critical', ['order_id' => $orderId]);
    }

    /**
     * Once per order: a ledger row (status refund_manual_required) and a critical incident record
     * captured money no provider refund can return, so it is never kept silently.
     */
    private static function flagManualRefundWithin(array $order, int $amount, string $reason): void {
        $orderId = (int)$order['id'];
        $key = 'order_refund_manual_' . $orderId;
        if (self::transactionExists($key)) return;
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? '{}'), true) ?: [];
        self::logTransaction(
            orderId: $orderId,
            provider: (string)($snapshot['provider'] ?? $order['payment_method'] ?? ''),
            providerRef: '',
            status: 'refund_manual_required',
            amount: $amount,
            currency: strtolower((string)($order['currency'] ?? 'eur')),
            idempotencyKey: $key,
            rawResponse: ['event_type' => 'refund.manual_required', 'reason' => $reason],
            failClosed: true,
        );
        AuditLog::log('order_refund', $orderId, (string)($order['payment_status'] ?? ''), 'refund_manual_required', $reason);
        IncidentMonitor::report('payment.order_refund_manual', 'payment', 'critical', ['order_id' => $orderId]);
    }

    /**
     * A capture reported for an order whose payment is already settled. A redelivery of the
     * recorded payment is harmless; one under a provider reference the order never had is money
     * taken twice, which raises a critical incident. Returns true for such a foreign capture.
     */
    public static function reportForeignCapture(array $order, array $refs): bool {
        $refs = array_values(array_filter(array_map('strval', $refs), static fn(string $ref): bool => $ref !== ''));
        if ($refs === []) return false;
        // Only captures count as known. A session merely opened for the order (one per "pay again") is no proof that its
        // money was booked: paid as well, it is the second capture. orders.payment_id names the newest session, not the
        // captured one, so it only counts for a capture booked without its references (by hand, older versions).
        $known = [];
        $bareCapture = false;
        $stmt = Db::get()->prepare("SELECT raw_response FROM payment_transactions WHERE order_id = :order AND store_id = :store");
        $stmt->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id()]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $tx) {
            $raw = json_decode((string)($tx['raw_response'] ?? ''), true);
            if (!is_array($raw) || ($raw['event_type'] ?? '') !== 'payment.paid') continue;
            $captured = self::captureRefs($raw);
            if ($captured === []) $bareCapture = true;
            foreach ($captured as $ref) $known[] = $ref;
        }
        if ($known === [] || $bareCapture) $known[] = (string)($order['payment_id'] ?? '');
        if (array_intersect($refs, $known) !== []) return false;
        IncidentMonitor::report('payment.duplicate_capture', 'payment', 'critical', ['order_id' => (int)$order['id']]);
        AuditLog::log('payment_duplicate_capture', (int)$order['id'], (string)($order['payment_status'] ?? ''), (string)($order['payment_status'] ?? ''), 'Capture under another provider reference: ' . implode(', ', $refs));
        return true;
    }

    /** @return list<string> provider references a payment event or ledger payload names */
    private static function captureRefs(array $data): array {
        $refs = [];
        foreach (['payment_id', 'provider_ref', 'orderId', 'payment_intent', 'session_id', 'id'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string)$data[$key] !== '') $refs[] = (string)$data[$key];
        }
        return $refs;
    }

    /** The provider confirmed the refund executed under this idempotency key (booked here or first by its webhook). */
    public static function refundSucceeded(string $idempotencyKey): bool {
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE idempotency_key = :key AND status IN ('refund_succeeded', 'refund_confirmed') LIMIT 1");
        $stmt->execute([':key' => $idempotencyKey]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * The payment status after a refund job's provider refund succeeded, under the order lock and through the state
     * machine: written blindly, a late job moved a refunded payment back to partially_refunded, or a disputed one on.
     * A whole-order refund moves only the refund_pending payment it claimed; an item refund (credit note) any refundable
     * one. Returns the payment status the order has afterwards.
     */
    public static function settleRefundJob(int $orderId, int $amount, bool $itemRefund, string $refundId, int $itemRefundId = 0): string {
        $status = '';
        self::withLockedOrder($orderId, static function (array $order) use ($orderId, $amount, $itemRefund, $refundId, $itemRefundId, &$status): bool {
            $from = (string)($order['payment_status'] ?? 'pending');
            $status = $from;
            $to = self::refundedAmount($orderId) >= (int)$order['total'] ? 'refunded' : 'partially_refunded';
            if ($from === $to || (!$itemRefund && $from !== 'refund_pending') || !self::canPaymentTransition($from, $to)) return false;
            self::updatePaymentStatus($orderId, $to, 'refund.succeeded', [
                'refund_id' => $itemRefundId > 0 ? $itemRefundId : null,
                'amount' => $amount,
                'provider_ref' => $refundId,
            ], $from);
            $status = $to;
            return true;
        });
        return $status;
    }

    /** @return array{amount:int, idempotency_key:string}|null the whole-order refund claimed by orderRefundJobsWithin() */
    public static function orderRefundRequest(int $orderId): ?array {
        $stmt = Db::get()->prepare("SELECT amount FROM payment_transactions WHERE idempotency_key = :key AND order_id = :order AND store_id = :store");
        $stmt->execute([':key' => 'order_refund_request_' . $orderId, ':order' => $orderId, ':store' => StoreContext::current()->id()]);
        $amount = $stmt->fetchColumn();
        return $amount === false ? null : ['amount' => (int)$amount, 'idempotency_key' => 'order_refund_' . $orderId];
    }

    /**
     * After the provider captured money: a payable order moves to paid. An order cancelled or
     * expired meanwhile stays cancelled, its payment is refunded and a critical incident is
     * raised, so money is never kept silently.
     */
    public static function settleCapture(int $orderId, string $note): void {
        $manualKey = 'order_refund_manual_' . $orderId;
        $jobs = [];
        $captured = false;
        $db = Db::get();
        $db->beginTransaction();
        try {
            // Decided under the order row lock: a cancellation committing after a plain read of the order made the
            // transition to paid fail and the webhook answer 500, instead of refunding on the cancelled order.
            Orders::lockWithin($orderId);
            $order = Orders::byId($orderId);
            if ($order && OrderWorkflow::canTransition((string)$order['status'], 'paid')) {
                $jobs = OrderWorkflow::transitionWithin($orderId, 'paid', $note);
            } elseif ($order && (string)$order['status'] === 'cancelled') {
                $manualBefore = self::transactionExists($manualKey);
                $jobs = self::orderRefundJobsWithin($orderId, 'payment.captured_after_cancel');
                if ($jobs !== []) AuditLog::log('order_refund', $orderId, 'cancelled', 'refund_pending', 'Payment captured on a cancelled order: ' . $note);
                // Offline money gets no refund job, only the manual-refund ledger row: still a capture to report.
                $captured = $jobs !== [] || (!$manualBefore && self::transactionExists($manualKey));
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        if ($captured) IncidentMonitor::report('payment.captured_after_cancel', 'payment', 'critical', ['order_id' => $orderId]);
        OrderWorkflow::pushDeferredJobs($jobs);
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

    /** Ledger statuses of money returned to the customer (refundedAmount()). */
    private const REFUNDED_STATUSES = ['partially_refunded', 'refunded', 'refund_succeeded'];

    /** @var list<array{0:int,1:int,2:string}> refund mails of ledger rows written in the open transaction */
    private static array $refundMails = [];

    /**
     * Tell the customer about money returned (any refund: whole order, partial, RMA credit note, by hand). Inside a
     * transaction the mail waits for its commit (flushRefundMails()), so a rolled-back booking sends nothing.
     */
    private static function queueRefundMail(int $orderId, int $amount, string $ledgerKey): void {
        self::$refundMails[] = [$orderId, $amount, $ledgerKey];
        if (!Db::get()->inTransaction()) self::flushRefundMails();
    }

    /** Queue the refund mails of the transaction that just committed ($committed false: it rolled back, none). */
    private static function flushRefundMails(bool $committed = true): void {
        $mails = self::$refundMails;
        self::$refundMails = [];
        if (!$committed) return;
        foreach ($mails as [$orderId, $amount, $ledgerKey]) {
            $order = Orders::byId($orderId);
            if (!$order) continue;
            // Every refund corrects the invoice (InvoiceService::creditBookedRefund(); an item refund has its credit note already).
            JobQueue::push('refund_credit_note', ['order_id' => $orderId, 'refund_key' => $ledgerKey], 5);
            if (trim((string)($order['customer_email'] ?? '')) === '') continue;
            JobQueue::push('email_send', [
                'template' => 'refund_issued',
                'to' => (string)$order['customer_email'],
                'locale' => (string)($order['locale'] ?? ''),
                'order_id' => $orderId,
                'refund_amount' => $amount,
                'refund_key' => $ledgerKey,
            ], 5);
        }
    }

    /** Whether the ledger holds the refund row a refund mail announces (it was committed). */
    public static function refundBooked(int $orderId, string $ledgerKey): bool {
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store AND idempotency_key = :key
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded') AND amount > 0");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':key' => $ledgerKey]);
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
            // Money returned to the customer: the customer is told, once per ledger row.
            if ($stmt->rowCount() === 1 && $amount > 0 && in_array($status, self::REFUNDED_STATUSES, true)) {
                self::queueRefundMail($orderId, $amount, $idempotencyKey);
            }
        } catch (\Throwable $e) {
            if ($failClosed) throw $e;
            // Never let logging failure break merchant-initiated payment operations.
            Logger::phpLog("PaymentService: failed to log transaction for order #{$orderId}: " . $e->getMessage());
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
                'order_number' => PayUPayment::orderNumber((string)($data['extOrderId'] ?? '')),
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
     * currency (and, for captures, the exact total); refunds are booked once per provider refund
     * (applyRefundEvent()). Events that do not apply (late failure after capture, repeated capture)
     * change nothing and stay in the payment log as 'ignored'; a repeated capture under another
     * provider reference also raises an incident.
     */
    private static function applyWebhookEvent(int $orderId, string $provider, string $eventType, string $eventKey, array $data): void {
        $order = Orders::byId($orderId);
        if (!$order) throw new RuntimeException("Order #{$orderId} vanished during payment webhook");
        $total = (int)$order['total'];
        $orderCurrency = strtolower((string)($order['currency'] ?? 'eur'));
        $amount = (int)($data['amount'] ?? 0);
        $currency = strtolower((string)($data['currency'] ?? ''));
        if (in_array($eventType, ['payment.paid', 'refund.succeeded'], true)
            && ($currency !== $orderCurrency || $amount <= 0 || ($eventType === 'payment.paid' && $amount !== $total))) {
            // Money taken for another amount (e.g. a session opened before an edit of the total) is never booked: the
            // merchant must refund or settle it by hand.
            if ($eventType === 'payment.paid' && !IncidentMonitor::reportedForOrder('payment.amount_mismatch', $orderId)) {
                IncidentMonitor::report('payment.amount_mismatch', 'payment', 'critical', ['order_id' => $orderId]);
            }
            throw new RuntimeException("Payment webhook {$eventType} amount/currency does not match order #{$orderId}");
        }
        if ($eventType === 'refund.succeeded' || $eventType === 'refund.reported') {
            self::applyRefundEvent($orderId, $provider, $eventType, $eventKey, $data);
            return;
        }

        // Under the order row lock, taken before the ledger insert: two events of one order at once (succeeded and
        // charge.succeeded) otherwise each held the shared lock the payment_transactions foreign key takes on the
        // order and then both waited to update it — a MySQL deadlock, and the loser was never applied.
        $settle = self::withLockedOrder($orderId, static function (array $order) use ($orderId, $provider, $eventType, $eventKey, $data, $amount, $currency, $orderCurrency): bool {
            $from = (string)($order['payment_status'] ?? 'pending');
            $to = match ($eventType) {
                'payment.paid' => 'paid',
                'payment.failed' => in_array($from, ['pending', 'authorized'], true) ? 'failed' : null,
                'payment.disputed' => 'disputed',
                default => null,
            };
            $payload = array_merge(['provider' => $provider], $data);
            $providerRef = (string)($data['refund_id'] ?? $data['provider_ref'] ?? $data['orderId'] ?? $data['id'] ?? '');
            // A capture while a refund is in flight is a second capture, not the payment: moving refund_pending to paid
            // dropped the refund in flight. refund_pending -> paid is for a failed refund (providerRefundFailed()) only.
            $capturable = !($to === 'paid' && $from === 'refund_pending');
            if ($to !== null && $to !== $from && $capturable && self::canPaymentTransition($from, $to)) {
                self::applyTransition($order, $to, $eventType, $amount, $eventKey, $payload);
                if ($to === 'paid') self::recordPaymentReference($order, $provider, $data);
                return $to === 'paid';
            }
            // Checked before this event's own row joins the ledger, whose references it would match.
            if ($to === 'paid') self::reportForeignCapture($order, self::captureRefs($data));
            self::recordEvent($orderId, $eventType, 'ignored', $amount, $currency ?: $orderCurrency, $eventKey, $payload + ['payment_status' => $from], $provider, $providerRef);
            if ($to !== null) AuditLog::log('payment_webhook_ignored', $orderId, $from, $from, "{$provider} {$eventType}");
            // A redelivery after a failed settlement finishes it (settleCapture is idempotent).
            return $to === 'paid' && $from === 'paid';
        });

        if ($settle) self::settleCapture($orderId, 'Payment confirmed via webhook');
    }

    /**
     * The provider's payment id of the capture that paid the order: without it refundsByHand() sent the refund to the
     * merchant instead of the provider's refund API. R14 #3: a retry pointed payment_id at its new session, so a
     * customer who paid the older attempt left the order paid with the reference of an unpaid one, and refunds went
     * to it and failed. A reference the order holds is replaced only by one of the order's own recorded attempts
     * (payment_transactions), or when it is only the checkout session id of one (Stripe cs_, R15 any provider); any
     * other reference a notification carries leaves it as it is (R8).
     */
    private static function recordPaymentReference(array $order, string $provider, array $data): void {
        $ref = trim((string)match ($provider) {
            'stripe' => $data['payment_intent'] ?? $data['id'] ?? '',
            'payu' => $data['orderId'] ?? '',
            default => $data['payment_id'] ?? '',
        });
        $current = trim((string)($order['payment_id'] ?? ''));
        if ($ref === '' || $ref === $current) return;
        if ($current !== '' && $provider === 'stripe' && str_starts_with($ref, 'cs_')) return;
        $stripeSession = $provider === 'stripe' && str_starts_with($current, 'cs_');
        // R15: at a provider whose checkout session id is not its payment id (extension adapters), the order held the
        // session id of its own attempt and refunds went to it. The capture of that attempt names the payment: it replaces it.
        if ($current !== '' && !$stripeSession && !self::isOrderAttempt((int)$order['id'], $provider, $ref)
            && !self::isOrderAttempt((int)$order['id'], $provider, $current, true)) return;
        Orders::setPaymentId((int)$order['id'], $ref);
        if ($current !== '' && !$stripeSession) AuditLog::log('payment_reference', (int)$order['id'], $current, $ref, "{$provider} capture of an earlier payment attempt");
    }

    /** Whether $ref is a payment attempt opened for this order (its payment_transactions row; $opened: its session row). */
    private static function isOrderAttempt(int $orderId, string $provider, string $ref, bool $opened = false): bool {
        if (!Db::tableExists('payment_transactions')) return false;
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = ? AND provider = ? AND provider_ref = ?"
            . ($opened ? " AND status = 'initiated'" : '') . ' LIMIT 1');
        $stmt->execute([$orderId, $provider, $ref]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * A provider refund event, booked under the order row lock. refund.succeeded carries the provider
     * refund id and amount (bookRefundWithin()). refund.reported (GoPay, ThePay: the payment shows as
     * refunded, with neither refund id nor refunded amount) completes the shop's own refund in flight
     * only when the payment is fully refunded and that refund is exactly the remaining balance;
     * anything else stays a log entry.
     */
    private static function applyRefundEvent(int $orderId, string $provider, string $eventType, string $eventKey, array $data): void {
        self::withLockedOrder($orderId, static function (array $order) use ($orderId, $provider, $eventType, $eventKey, $data): bool {
            $from = (string)($order['payment_status'] ?? 'pending');
            $orderCurrency = strtolower((string)($order['currency'] ?? 'eur'));
            $currency = strtolower((string)($data['currency'] ?? ''));
            $payload = array_merge(['provider' => $provider], $data);
            if ($eventType === 'refund.succeeded') {
                return self::bookRefundWithin($order, $provider, (string)($data['refund_id'] ?? ''), (int)$data['amount'], $eventType, $eventKey, $payload);
            }
            $request = self::pendingRefundRequest($order);
            $inFlight = (int)($request['amount'] ?? 0);
            if (strtolower((string)($data['state'] ?? '')) === 'refunded' && $from === 'refund_pending' && $currency === $orderCurrency
                && $inFlight > 0 && $inFlight === self::refundableBalance($orderId)) {
                // Names the request it settles, so the refund worker's own result only confirms it (refundRequestSettled()).
                return self::bookRefundWithin($order, $provider, '', $inFlight, $eventType, $eventKey,
                    $payload + ['settles_refund_request' => (string)($request['idempotency_key'] ?? '')]);
            }
            self::recordEvent($orderId, $eventType, 'ignored', (int)($data['amount'] ?? 0), $currency ?: $orderCurrency, $eventKey,
                $payload + ['payment_status' => $from], $provider, (string)($data['provider_ref'] ?? ''));
            return false;
        });
        self::closeRefundedOrder($orderId, 'Refund confirmed via webhook');
    }

    /**
     * Book a refund the provider executed (e.g. listed by a Stripe charge.refunded event), once per
     * provider refund id: whichever of the refund worker and the webhook comes first records the money.
     */
    public static function bookProviderRefund(int $orderId, string $provider, string $refundId, int $amount, string $eventType, string $eventKey, array $payload = []): bool {
        if ($refundId === '') throw new \InvalidArgumentException('A provider refund needs its refund id');
        return self::withLockedOrder($orderId, static fn(array $order): bool => self::bookProviderRefundWithin($order, $provider, $refundId, $amount, $eventType, $eventKey, $payload));
    }

    /**
     * A provider refund named by its id. R25: one that is the shop's own refund in flight ($payload['refund_key'], the
     * refund key Stripe carries in the refund's metadata) is booked as that refund (its credit note, its request). One whose
     * money an event without refund ids booked already is only identified — a refund_confirmed row under its id, no money:
     * the shop's own refund that bookCumulativeRefund() settled, or a refund of no shop key that fits the money such an
     * event booked as a refund of its own and no refund id claimed yet (unidentifiedRefunded()). Was: a refund.updated or a
     * later charge.refunded between that event and the refund job's result booked it again (42.00 on the ledger for 21.00
     * returned) and issued a second credit note.
     */
    private static function bookProviderRefundWithin(array $order, string $provider, string $refundId, int $amount, string $eventType, string $eventKey, array $payload): bool {
        $orderId = (int)$order['id'];
        $key = (string)($payload['refund_key'] ?? '');
        $fresh = $amount > 0 && !self::transactionExists($eventKey) && !self::refundAlreadyRecorded($orderId, $refundId);
        // R25 (review run 379): a refund of no shop key larger than the unidentified money may be money an event without
        // refund ids booked provisionally as a shop refund the provider has not named yet: that booking is reclaimed (its
        // refund books its own money once its result names it), oldest first. Was: a 25.00 dashboard refund matched
        // with a 21.00 item refund still at Stripe left 4.00 unidentified, and its own event booked 25.00 again.
        // R25 (review run 380): also one its own refund id confirmed already (the job's result or an event with its key came
        // first): that refund happened, so its money is booked once under its own id now. Was: only an 'open' one, and the
        // dashboard refund's event after the item's result booked 25.00 again.
        // R26: only what it needs — of a 21.00 booking a 5.00 dashboard refund reclaims 5.00 (was: all 21.00, and the item's
        // own id then booked 21.00 again: 42.00 on the ledger for 26.00 refunded, credit notes -21.00 twice).
        $reclaims = [];
        if ($fresh && $key === '' && ($unidentified = self::unidentifiedRefunded($orderId)) < $amount) {
            $need = $amount - $unidentified;
            foreach (self::provisionalRefundsWithin($orderId) as $part) {
                if (!in_array($part['state'], ['open', 'confirmed'], true)) continue;
                $take = min($need, $part['amount'] - $part['reclaimed']);
                if ($take <= 0) continue;
                $reclaims[$part['ledger']] = $take;
                $need -= $take;
                if ($need <= 0) break;
            }
            if ($need > 0) $reclaims = [];
        }
        if ($fresh && ($key !== '' ? self::refundSettledByEvent($orderId, $key) : ($reclaims !== [] || $amount <= self::unidentifiedRefunded($orderId)))) {
            self::logTransaction(
                orderId: $orderId,
                provider: $provider,
                providerRef: $refundId,
                status: 'refund_confirmed',
                amount: $amount,
                currency: strtolower((string)($order['currency'] ?? 'eur')),
                idempotencyKey: $eventKey,
                rawResponse: ['event_type' => $eventType, 'provider' => $provider, 'refund_id' => $refundId,
                    ($key !== '' ? 'identifies_booked_refund' : 'identifies_unidentified_refund') => true]
                    + ($reclaims !== [] ? ['reclaims' => $reclaims] : []) + $payload,
                failClosed: true,
            );
            if ($key !== '') {
                self::noteRefundIdWithin($orderId, $key, $refundId);
                // A provisionally booked item refund is completed once its own refund id confirms it.
                self::linkItemRefundWithin($orderId, $key, $refundId, 'confirmed');
            }
            // The reclaimed money was this refund: it corrects the invoice by a credit note of its own, of what it reclaimed.
            if ($reclaims !== []) JobQueue::push('refund_credit_note', ['order_id' => $orderId, 'refund_key' => $eventKey], 5);
            // R25 (review run 380): money reclaimed from a refund its own id confirmed (now or before) is booked as that refund.
            return self::rebookReclaimedWithin($orderId, $provider, $eventType);
        }
        if ($key === '') return self::bookRefundWithin($order, $provider, $refundId, $amount, $eventType, $eventKey, $payload);
        // The shop's own refund, before its job's result: booked as the refund it executes, so the item refund's credit
        // note is the only one and an admin refund gets one (creditBookedRefund()).
        $item = Db::get()->prepare("SELECT id FROM order_refunds WHERE order_id = :order AND store_id = :store AND idempotency_key = :key AND status = 'pending' LIMIT 1");
        $item->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':key' => $key]);
        $itemRefundId = (int)($item->fetchColumn() ?: 0);
        return self::bookRefundWithin($order, $provider, $refundId, $amount, $eventType, $eventKey,
            $payload + ['settles_refund_request' => self::refundRequestKey($key) ?? $key], $itemRefundId, $refundId);
    }

    /**
     * R25: the provider refund id of a refund in flight that an event without refund ids settled (bookCumulativeRefund()):
     * the item refund (credit note) noted the event's ledger key as its payment reference, and reconciliation lost the
     * provider's refund id. Noted once the refund job's result or an event names it.
     */
    private static function noteRefundIdWithin(int $orderId, string $executeKey, string $refundId): void {
        if ($refundId === '') return;
        $keys = array_values(array_filter([$executeKey, self::refundRequestKey($executeKey)]));
        $ledgerKeys = [];
        $stmt = Db::get()->prepare("SELECT idempotency_key, raw_response FROM payment_transactions WHERE order_id = :order AND store_id = :store
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded')");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $settled = json_decode((string)$row['raw_response'], true);
            if (is_array($settled) && in_array($settled['settles_refund_request'] ?? null, $keys, true)) $ledgerKeys[] = (string)$row['idempotency_key'];
        }
        foreach ($ledgerKeys as $ledgerKey) {
            Db::get()->prepare("UPDATE order_refunds SET payment_reference = :ref WHERE order_id = :order AND store_id = :store
                AND idempotency_key IN (:key, :request) AND payment_reference = :ledger")
                ->execute([':ref' => $refundId, ':order' => $orderId, ':store' => StoreContext::current()->id(),
                    ':key' => $keys[0], ':request' => $keys[1] ?? $keys[0], ':ledger' => $ledgerKey]);
        }
    }

    /**
     * R25: money an event without refund ids booked as a refund of its own (bookCumulativeRefund(): what no refund of the
     * shop in flight explains, e.g. a refund made in the Stripe dashboard) that no event naming a refund id has claimed yet.
     */
    private static function unidentifiedRefunded(int $orderId): int {
        $stmt = Db::get()->prepare("SELECT status, amount, raw_response FROM payment_transactions WHERE order_id = :order AND store_id = :store
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded', 'refund_confirmed')");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        $pool = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $raw = json_decode((string)$row['raw_response'], true);
            if (!is_array($raw)) continue;
            if ($row['status'] !== 'refund_confirmed' && !empty($raw['unidentified_refund'])) $pool += (int)$row['amount'];
            if ($row['status'] === 'refund_confirmed' && !empty($raw['identifies_unidentified_refund'])) $pool -= (int)$row['amount'];
        }
        // R25: a provisional booking that turned out to be other money is such money too. R26: of one reclaimed in part only
        // what was reclaimed (the refund that claimed it subtracts its amount above), of a failed one the rest too.
        foreach (self::provisionalRefundsWithin($orderId) as $part) {
            $pool += $part['reclaimed'] + ($part['state'] === 'failed' ? $part['amount'] - $part['reclaimed'] : 0);
        }
        return max(0, $pool);
    }

    /**
     * Book the part of a provider's cumulative refunded amount the ledger does not hold yet, for
     * events that name no refund ids. Read and written under the order row lock, so two concurrent
     * events cannot both book the same difference.
     */
    public static function bookCumulativeRefund(int $orderId, string $provider, int $cumulativeAmount, string $eventType, string $eventKey, array $payload = []): bool {
        return self::withLockedOrder($orderId, static function (array $order) use ($orderId, $provider, $cumulativeAmount, $eventType, $eventKey, $payload): bool {
            // R25: never more than the order still holds (the per-rate credit cap stopped the credit note, its job failed).
            $delta = min($cumulativeAmount - self::refundedAmount($orderId), (int)$order['total'] - self::refundedAmount($orderId));
            if ($delta <= 0) return false;
            // R24: the shop's own refunds in flight (admin request, cancellation, item refunds) the reported amount holds are
            // booked as those refunds, so an item refund's credit note is the only one (creditBookedRefund()) and the refund
            // job's result only confirms it (refundSettledByEvent()). R25: matched as the set of them whose sum fits the
            // amount, not one of exactly the amount: two item refunds of 21.00 reported as 42.00 were booked as a refund of
            // their own and then each again by its job (84.00 on a 42.00 order); 21.00 of an item refund and 5.00 from the
            // dashboard in one event left the payment refunded. What no refund in flight explains is a refund of its own.
            $booked = false;
            $part = 0;
            $candidates = self::inFlightCandidatesWithin($order);
            $chosen = self::pickInFlight($candidates, $delta);
            // R26: the refunds in flight it was not matched to, which it may have been instead (failProvisionalWithin()).
            $alternatives = array_values(array_map(static fn(array $c): array => ['execute' => $c['execute'], 'settles' => $c['key'], 'amount' => $c['amount']],
                array_filter($candidates, static fn(array $c): bool => !in_array($c, $chosen, true))));
            foreach ($chosen as $settles) {
                $ledgerKey = $part++ === 0 ? $eventKey : $eventKey . '#' . $part;
                // R25 (review run 379): a refund the provider has not named yet may not have happened (its call still in
                // flight, or failing): the money may be a dashboard refund. Booked provisionally — its item refund stays
                // pending until its own refund id confirms it (refund(), bookProviderRefundWithin()); an event naming
                // another refund id may reclaim it (provisionalRefundsWithin()). Was: completed here, and a dashboard
                // refund larger than it was booked a second time.
                $provisional = $settles['refund_id'] === '';
                $booked = self::bookRefundWithin(Orders::byId($orderId) ?: $order, $provider, $settles['refund_id'], $settles['amount'], $eventType,
                    $ledgerKey, $payload + ['settles_refund_request' => $settles['key']] + ($provisional ? ['provisional_refund' => $settles['execute'], 'alternatives' => $alternatives] : []),
                    $provisional ? 0 : $settles['item_refund_id'], $settles['refund_id']) || $booked;
                $delta -= $settles['amount'];
            }
            if ($delta > 0) {
                $booked = self::bookRefundWithin(Orders::byId($orderId) ?: $order, $provider, '', $delta, $eventType,
                    $part === 0 ? $eventKey : $eventKey . '#rest', $payload + ['unidentified_refund' => true]) || $booked;
            }
            return $booked;
        });
    }

    /**
     * R25: the shop's refunds in flight a provider-reported refund may hold: the refund request of a refund_pending payment
     * and the item refunds (credit notes) whose money is not booked, each with its provider refund id when the refund job
     * noted one already. R26: pickInFlight() then picks the set it settles.
     * @return list<array{key:string, execute:string, refund_id:string, item_refund_id:int, amount:int}>
     */
    private static function inFlightCandidatesWithin(array $order): array {
        $db = Db::get();
        $candidates = [];
        $request = self::latestRefundRequestRow($order);
        if ($request !== null && (int)$request['amount'] > 0) {
            $key = (string)$request['idempotency_key'];
            $executed = $db->prepare("SELECT provider_ref FROM payment_transactions WHERE order_id = :order AND store_id = :store
                AND idempotency_key = :execute AND status = 'refund_pending' AND provider_ref IS NOT NULL AND provider_ref <> '' LIMIT 1");
            $executed->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id(), ':execute' => self::refundExecuteKey($key)]);
            $candidates[] = ['key' => $key, 'refund_id' => (string)($executed->fetchColumn() ?: ''), 'item_refund_id' => 0, 'amount' => (int)$request['amount']];
        }
        $items = $db->prepare("SELECT r.id, r.idempotency_key, r.payment_reference, r.amount FROM order_refunds r
            WHERE r.order_id = :order AND r.store_id = :store AND r.status = 'pending' AND r.amount > 0 AND NOT EXISTS (
                SELECT 1 FROM payment_transactions t WHERE t.order_id = r.order_id AND t.store_id = r.store_id
                  AND t.status IN ('partially_refunded', 'refunded', 'refund_succeeded')
                  AND (t.idempotency_key = r.idempotency_key OR (r.payment_reference IS NOT NULL AND r.payment_reference <> '' AND t.provider_ref = r.payment_reference)))
            ORDER BY r.id");
        $items->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id()]);
        foreach ($items->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $candidates[] = ['key' => (string)$row['idempotency_key'], 'refund_id' => (string)($row['payment_reference'] ?? ''),
                'item_refund_id' => (int)$row['id'], 'amount' => (int)$row['amount']];
        }
        // R25: only a refund whose job has called the provider can be in what the provider reports (refund()). Was: a
        // dashboard refund of the same sum completed an item refund still queued, whose job then refunded the money again
        // and only confirmed it.
        // A refund an earlier such event booked provisionally (bookCumulativeRefund()) is not in flight a second time.
        $orderId = (int)$order['id'];
        return array_values(array_filter(array_map(static fn(array $c): array =>
            $c + ['execute' => $c['item_refund_id'] > 0 ? $c['key'] : self::refundExecuteKey($c['key'])], $candidates),
            static function (array $c) use ($orderId): bool {
                if (self::refundSettledByEvent($orderId, $c['execute'])) return false;
                if ($c['refund_id'] !== '') return true;
                return self::transactionExists('refund_dispatched_' . $c['execute']) || self::transactionExists('refund_attempt_' . $c['execute']);
            }));
    }

    /**
     * @param list<array{key:string, execute:string, refund_id:string, item_refund_id:int, amount:int}> $candidates
     * @return list<array{key:string, execute:string, refund_id:string, item_refund_id:int, amount:int}>
     */
    private static function pickInFlight(array $candidates, int $amount): array {
        // A handful at most in practice; beyond 12 the oldest first that fit.
        $count = count($candidates);
        if ($count > 12) {
            $chosen = [];
            foreach ($candidates as $candidate) {
                if ($candidate['amount'] <= $amount) {
                    $chosen[] = $candidate;
                    $amount -= $candidate['amount'];
                }
            }
            return $chosen;
        }
        $best = 0;
        $bestSum = 0;
        for ($mask = 1; $mask < (1 << $count); $mask++) {
            $sum = 0;
            for ($i = 0; $i < $count; $i++) {
                if ($mask & (1 << $i)) $sum += $candidates[$i]['amount'];
            }
            // Masks run oldest-first in the low bits: the first of equal sum and size is the one with the oldest refunds.
            if ($sum <= $amount && ($sum > $bestSum || ($sum === $bestSum && $best !== 0 && substr_count(decbin($mask), '1') < substr_count(decbin($best), '1')))) {
                [$best, $bestSum] = [$mask, $sum];
            }
        }
        $chosen = [];
        for ($i = 0; $i < $count; $i++) {
            if ($best & (1 << $i)) $chosen[] = $candidates[$i];
        }
        return $chosen;
    }

    /**
     * Whether a provider-reported refund without its refund id (bookCumulativeRefund(), refund.reported) was booked as the
     * refund this payment_refund job executes ($executeKey: the item refund's key, or the request's execute key).
     */
    private static function refundSettledByEvent(int $orderId, string $executeKey): bool {
        if ($executeKey === '') return false;
        $keys = array_filter([$executeKey, self::refundRequestKey($executeKey)]);
        $stmt = Db::get()->prepare("SELECT idempotency_key, raw_response FROM payment_transactions WHERE order_id = :order AND store_id = :store
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded')");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $settled = json_decode((string)$row['raw_response'], true);
            if (is_array($settled) && empty($settled['provisional_refund']) && in_array($settled['settles_refund_request'] ?? null, $keys, true)) return true;
        }
        // R25: a provisional booking another refund id reclaimed, or whose refund failed, settled nothing. R26: matched on what
        // it stands for now (moved to the refund it really was: failProvisionalWithin()); one reclaimed in part still settles.
        foreach (self::provisionalRefundsWithin($orderId) as $part) {
            if (in_array($part['state'], ['open', 'confirmed'], true) && ($part['execute'] === $executeKey || in_array($part['settles'], $keys, true))) return true;
        }
        return false;
    }

    /**
     * R25 (review run 379): the refunds an event without refund ids booked before the provider named them
     * (bookCumulativeRefund()), oldest first. Each is 'open' until its own refund id confirms it ('confirmed': the refund
     * job's result, or an event carrying its refund key), 'failed' when its refund call failed (the money was another
     * refund), 'reclaimed' when events naming other refund ids claimed all of its money (bookProviderRefundWithin()). A
     * confirmed one carries the refund id that confirmed it.
     * R26: an event naming another refund id claims only what it needs ('reclaimed': that much of 'amount'; was: all of
     * it, and the refund's own id booked it again); a failed one may be moved to the refund of the same sum it really was
     * ('refund_reassigned', failProvisionalWithin()), or taken back ('reversed') when it duplicated a refund booked on its own
     * or Stripe failed it later. 'alternatives' are the other refunds in flight it could have been; 'rebooked' what was
     * booked under its own refund for the money reclaimed after it was confirmed (rebookReclaimedWithin()).
     *
     * @return list<array{ledger:string, execute:string, settles:string, amount:int, reclaimed:int, legacy_reclaimed:int, rebooked:int,
     *     confirmed:bool, state:string, refund_id:string, alternatives:list<array{execute:string, settles:string, amount:int}>}>
     */
    private static function provisionalRefundsWithin(int $orderId): array {
        $stmt = Db::get()->prepare("SELECT idempotency_key, status, amount, provider_ref, raw_response FROM payment_transactions WHERE order_id = :order AND store_id = :store
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded', 'refund_confirmed', 'refund_failed', 'refund_canceled', 'refund_cancelled',
                'refund_reassigned', 'refund_reversed') ORDER BY id");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        $parts = [];
        $confirmed = [];
        $failed = [];
        $reclaimed = [];
        $legacy = [];
        $reassigned = [];
        $reversed = [];
        $rebooked = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $raw = json_decode((string)$row['raw_response'], true);
            $raw = is_array($raw) ? $raw : [];
            $key = (string)$row['idempotency_key'];
            if ($row['status'] === 'refund_confirmed') {
                $confirmedBy = (string)($row['provider_ref'] ?? '');
                $confirmed[$key] = $confirmedBy;
                if (!empty($raw['identifies_booked_refund']) && (string)($raw['refund_key'] ?? '') !== '') $confirmed[(string)$raw['refund_key']] = $confirmedBy;
                $claims = (array)($raw['reclaims'] ?? []);
                if (array_is_list($claims)) {
                    foreach ($claims as $ledger) $legacy[(string)$ledger] = true; // R25 claimed a booking whole
                } else {
                    foreach ($claims as $ledger => $amount) $reclaimed[(string)$ledger] = ($reclaimed[(string)$ledger] ?? 0) + (int)$amount;
                }
            } elseif (in_array($row['status'], ['refund_failed', 'refund_canceled', 'refund_cancelled'], true)) {
                $failed[$key] = true;
            } elseif ($row['status'] === 'refund_reassigned') {
                $reassigned[(string)($raw['reassigns'] ?? '')] = ['execute' => (string)($raw['provisional_refund'] ?? ''), 'settles' => (string)($raw['settles_refund_request'] ?? '')];
            } elseif ($row['status'] === 'refund_reversed') {
                $reversed[(string)($raw['reverses_provisional_refund'] ?? '')] = true;
            } elseif ((string)($raw['provisional_refund'] ?? '') !== '') {
                $parts[] = ['ledger' => $key, 'execute' => (string)$raw['provisional_refund'], 'settles' => (string)($raw['settles_refund_request'] ?? ''),
                    'amount' => (int)$row['amount'], 'alternatives' => array_values(array_filter((array)($raw['alternatives'] ?? []), 'is_array'))];
            } elseif ((string)($raw['rebooks_reclaimed_refund'] ?? '') !== '') {
                $ledger = (string)$raw['rebooks_reclaimed_refund'];
                $rebooked[$ledger] = ($rebooked[$ledger] ?? 0) + (int)$row['amount'];
            }
        }
        $list = [];
        foreach ($parts as $part) {
            $ledger = $part['ledger'];
            if (isset($reassigned[$ledger])) {
                $part['execute'] = $reassigned[$ledger]['execute'];
                $part['settles'] = $reassigned[$ledger]['settles'];
            }
            $legacyAmount = isset($legacy[$ledger]) ? $part['amount'] : 0;
            $part['legacy_reclaimed'] = $legacyAmount;
            $part['reclaimed'] = min($part['amount'], $legacyAmount + ($reclaimed[$ledger] ?? 0));
            $part['rebooked'] = $rebooked[$ledger] ?? 0;
            $part['confirmed'] = isset($confirmed[$part['execute']]);
            $part['refund_id'] = $confirmed[$part['execute']] ?? '';
            $part['state'] = isset($reversed[$ledger]) ? 'reversed' : ($part['reclaimed'] >= $part['amount'] ? 'reclaimed'
                : ($part['confirmed'] ? 'confirmed' : (isset($failed[$part['execute']]) ? 'failed' : 'open')));
            $list[] = $part;
        }
        return $list;
    }

    /**
     * R26: what the credit note of the provisional booking under $ledgerKey corrects (InvoiceService::creditBookedRefund()),
     * or null when that is no provisional booking: the refund it now stands for ('settles', '' once it is other money) and
     * the money left to it ('amount'): less what other refunds reclaimed (they credit it under their own key), nothing once
     * it was taken back; a failed one's rest is other money of its own.
     * @return array{settles:string, amount:int}|null
     */
    public static function provisionalRefundCredit(int $orderId, string $ledgerKey): ?array {
        foreach (self::provisionalRefundsWithin($orderId) as $part) {
            if ($part['ledger'] !== $ledgerKey) continue;
            $rest = $part['amount'] - $part['reclaimed'];
            return match ($part['state']) {
                'open', 'confirmed' => ['settles' => $part['settles'], 'amount' => $rest],
                'failed' => ['settles' => '', 'amount' => $part['legacy_reclaimed'] + $rest],
                default => ['settles' => '', 'amount' => $part['legacy_reclaimed']],
            };
        }
        return null;
    }

    /**
     * R26: the money of reclaimed parts of provisional bookings whose own refund happened after all (confirmed): booked once
     * as that refund (no refund id on the row: its id is recorded as confirmed), settling its request, so its item refund's
     * credit note stays the only one. Was (R25): only a part reclaimed whole after its confirmation.
     */
    private static function rebookReclaimedWithin(int $orderId, string $provider, string $eventType): bool {
        $booked = false;
        foreach (self::provisionalRefundsWithin($orderId) as $part) {
            $owed = $part['reclaimed'] - $part['rebooked'];
            if (!$part['confirmed'] || $part['state'] === 'reversed' || $owed <= 0) continue;
            $booked = self::bookRefundWithin(Orders::byId($orderId) ?: ['id' => $orderId], $provider, '', $owed, $eventType,
                $part['ledger'] . '#own' . ($part['rebooked'] > 0 ? ':' . $part['rebooked'] : ''),
                ['refund_id' => $part['refund_id'], 'settles_refund_request' => $part['settles'] !== '' ? $part['settles'] : $part['execute'],
                    'rebooks_reclaimed_refund' => $part['ledger']]) || $booked;
        }
        return $booked;
    }

    /**
     * R26: a refund booked provisionally ($part) did not happen: its call failed ($async false) or Stripe failed it later
     * ($async true). Its money is
     * - the other refund in flight of exactly that sum it may have been (the summary matched the older of two): moved to it,
     *   whose result then only confirms it (was: that result booked it again, 42.00 for 21.00 refunded);
     * - taken back when such a refund was booked on its own meanwhile (the summary's money is in the ledger twice), or when
     *   Stripe failed this refund after it was reported (its amount was counted while pending);
     * - else money of no refund of the shop (a dashboard refund, R25), corrected by a credit note of its own.
     */
    private static function failProvisionalWithin(array $order, array $part, string $provider, string $eventKey, bool $async): void {
        $orderId = (int)$order['id'];
        $rest = $part['amount'] - $part['reclaimed'];
        if ($rest <= 0 || $part['state'] === 'reversed') return;
        $currency = strtolower((string)($order['currency'] ?? 'eur'));
        foreach ($part['alternatives'] as $other) {
            $execute = (string)($other['execute'] ?? '');
            if ((int)($other['amount'] ?? 0) !== $rest || $execute === '' || $execute === $part['execute'] || self::refundSettledByEvent($orderId, $execute)) continue;
            if (self::refundBookedOnItsOwn($orderId, $execute)) {
                self::reverseProvisionalWithin($order, $part, $rest, $eventKey, 'duplicates refund ' . $execute);
                return;
            }
            if (self::transactionExists('refund_dispatched_' . $execute) || self::refundPendingAtProvider($orderId, $execute)) {
                self::logTransaction(orderId: $orderId, provider: $provider, providerRef: (string)($order['payment_id'] ?? ''), status: 'refund_reassigned', amount: 0,
                    currency: $currency, idempotencyKey: $eventKey . '#reassign', failClosed: true,
                    rawResponse: ['reassigns' => $part['ledger'], 'provisional_refund' => $execute, 'settles_refund_request' => (string)($other['settles'] ?? $execute),
                        'from' => $part['execute']]);
                return;
            }
        }
        if ($async) {
            self::reverseProvisionalWithin($order, $part, $rest, $eventKey, 'failed at the provider');
            return;
        }
        JobQueue::push('refund_credit_note', ['order_id' => $orderId, 'refund_key' => $part['ledger']], 5);
    }

    /** R26: take a provisional booking's money ($amount) back off the ledger: the payment returns to what the rest holds. */
    private static function reverseProvisionalWithin(array $order, array $part, int $amount, string $eventKey, string $why): void {
        $orderId = (int)$order['id'];
        $from = (string)(Orders::byId($orderId)['payment_status'] ?? $order['payment_status'] ?? '');
        self::logTransaction(orderId: $orderId, provider: self::orderProvider($order), providerRef: (string)($order['payment_id'] ?? ''), status: 'refund_reversed',
            amount: -$amount, currency: strtolower((string)($order['currency'] ?? 'eur')), idempotencyKey: $eventKey . '#reverse', failClosed: true,
            rawResponse: ['reverses_provisional_refund' => $part['ledger'], 'refund' => $part['execute'], 'reason' => $why]);
        // The booking never happened: the payment goes back past the state machine's one-way refund steps, audited.
        $to = self::refundedAmount($orderId) > 0 ? 'partially_refunded' : 'paid';
        if (in_array($from, ['refunded', 'partially_refunded', 'refund_pending'], true) && $from !== $to) {
            self::updatePaymentStatus($orderId, $to, 'refund.reversed', ['amount' => $amount, 'ledger' => $part['ledger']], $from);
            AuditLog::log('payment_status', $orderId, $from, $to, 'refund.reversed: provisional booking ' . $part['ledger'] . ' ' . $why);
        }
    }

    /** R26: whether the refund $executeKey executes was booked by its own result (not as a provisional booking). */
    private static function refundBookedOnItsOwn(int $orderId, string $executeKey): bool {
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store AND idempotency_key = :key
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded') AND amount > 0 LIMIT 1");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':key' => $executeKey]);
        return (bool)$stmt->fetchColumn();
    }

    /** R26: whether the provider answered the refund $executeKey executes as pending (it may still succeed). */
    private static function refundPendingAtProvider(int $orderId, string $executeKey): bool {
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store AND idempotency_key = :key
            AND status = 'refund_pending' LIMIT 1");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':key' => $executeKey]);
        return (bool)$stmt->fetchColumn();
    }

    /** Whether an order's payment is returned by hand: offline money, or a provider without a refund API. */
    public static function refundsByHand(array $order): bool {
        return trim((string)($order['payment_id'] ?? '')) === '' || in_array(self::orderProvider($order), ['manual', 'cod', 'none'], true);
    }

    /**
     * Record a refund the merchant paid out by hand (bank transfer back, cash) for a payment no
     * provider refund API returns (refundsByHand()). Moves the payment to partially_refunded or
     * refunded, audited with the acting admin; a fully refunded returned or cancelled order closes.
     */
    public static function completeManualRefund(int $orderId, int $amount, string $actor, string $note): void {
        if ($amount <= 0 || trim($note) === '') throw new \InvalidArgumentException('Invalid manual refund');
        $key = 'manual_refund_' . $orderId . '_' . bin2hex(random_bytes(12));
        self::withLockedOrder($orderId, static function (array $order) use ($orderId, $amount, $actor, $note, $key): bool {
            $from = (string)($order['payment_status'] ?? 'pending');
            if (!self::refundsByHand($order) || $amount > self::claimableRefund($orderId)) {
                throw new \LogicException('Manual refund is not available for this order or amount');
            }
            $to = self::refundedAmount($orderId) + self::offLedgerCredits($order) + $amount >= (int)$order['total'] ? 'refunded' : 'partially_refunded';
            $payload = ['actor' => $actor, 'note' => $note];
            if ($from === $to) {
                self::recordEvent($orderId, 'refund.manual_completed', $to, $amount, (string)($order['currency'] ?? 'eur'), $key, $payload, self::orderProvider($order));
            } elseif (self::canPaymentTransition($from, $to)) {
                self::applyTransition($order, $to, 'refund.manual_completed', $amount, $key, $payload);
            } else {
                throw new \LogicException("Manual refund cannot move payment from '{$from}' to '{$to}'");
            }
            AuditLog::log('order_refund', $orderId, $from, $to, 'Manual refund of ' . AuditLogLabels::amount($amount, $order['currency'] ?? 'eur') . " completed by {$actor}: {$note}");
            return true;
        });
        self::closeRefundedOrder($orderId, 'Manual refund completed');
        // The invoice is corrected by a credit note for the money returned (an RMA credit note was issued with its return).
        // The refund is booked either way: a credit note that cannot be issued is raised for the merchant to issue by hand.
        try {
            InvoiceService::creditRefund($orderId, $amount, $key, $note);
        } catch (\Throwable $e) {
            Logger::error('manual refund credit note failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            IncidentMonitor::report('invoice.refund_credit_failed', 'invoice', 'warning', ['order_id' => $orderId, 'amount' => $amount]);
        }
    }

    /**
     * One money row per provider refund (by refund id when the provider names one), inside the
     * caller's transaction under the order row lock. Moves the payment to partially_refunded or
     * refunded and completes the item refund (credit note) it settles. Returns false when the
     * refund was already booked or does not apply to the payment (logged as 'ignored'). A caller that knows
     * the item refund it settles names it ($itemRefundId), instead of matching a pending one by amount.
     */
    private static function bookRefundWithin(array $order, string $provider, string $refundId, int $amount, string $eventType, string $eventKey, array $payload,
        int $itemRefundId = 0, string $itemReference = ''): bool {
        $orderId = (int)$order['id'];
        $from = (string)($order['payment_status'] ?? 'pending');
        $currency = strtolower((string)($order['currency'] ?? 'eur'));
        if ($amount <= 0 || self::transactionExists($eventKey)) return false;
        $payload = array_merge(['provider' => $provider], $payload, $refundId !== '' ? ['refund_id' => $refundId] : []);
        if (self::refundAlreadyRecorded($orderId, $refundId)) {
            // The refund worker (or another event) booked this refund: logged, no money moves.
            self::recordEvent($orderId, $eventType, 'ignored', 0, $currency, $eventKey, $payload + ['payment_status' => $from], $provider, $refundId);
            // R26: one the provider answered pending, whose money a summary event booked, is completed once it succeeds.
            if ($itemRefundId > 0) Rma::completeRefund($itemRefundId, $itemReference !== '' ? $itemReference : $refundId);
            else self::completeItemRefundWithin($orderId, $refundId);
            return false;
        }
        $to = self::refundedAmount($orderId) + $amount >= (int)$order['total'] ? 'refunded' : 'partially_refunded';
        $ref = $refundId !== '' ? $refundId : (string)($order['payment_id'] ?? '');
        if ($to !== $from && self::canPaymentTransition($from, $to)) {
            self::applyTransition($order, $to, $eventType, $amount, $eventKey, $payload, $ref);
        } elseif ($to === 'partially_refunded' && $from === $to) {
            self::recordEvent($orderId, $eventType, $to, $amount, $currency, $eventKey, $payload, $provider, $ref);
        } else {
            self::recordEvent($orderId, $eventType, 'ignored', $amount, $currency, $eventKey, $payload + ['payment_status' => $from], $provider, $ref);
            AuditLog::log('payment_webhook_ignored', $orderId, $from, $from, "{$provider} {$eventType}");
            return false;
        }
        if ($itemRefundId > 0) Rma::completeRefund($itemRefundId, $itemReference);
        else self::completeItemRefundWithin($orderId, $refundId);
        return true;
    }

    /**
     * A provider refund that failed (or was canceled) after the provider accepted it as pending. The money was
     * never booked (only succeeded refunds are), so a refund request still in flight is released: the payment
     * returns to paid / partially_refunded and the admin can request it again. A refund already booked by its
     * id cannot be undone automatically. Either way a critical incident tells the merchant the customer is unpaid.
     */
    public static function providerRefundFailed(int $orderId, string $provider, string $refundId, int $amount, string $eventKey, array $payload = []): bool {
        $released = self::withLockedOrder($orderId, static function (array $order) use ($orderId, $provider, $refundId, $amount, $eventKey, $payload): bool {
            if (self::transactionExists($eventKey)) return false;
            $from = (string)($order['payment_status'] ?? 'pending');
            $currency = strtolower((string)($order['currency'] ?? 'eur'));
            $payload = array_merge(['provider' => $provider, 'refund_id' => $refundId], $payload);
            // R26: a refund Stripe answered pending whose money a summary event booked provisionally (its id confirmed that
            // booking) failed: the booking is taken back or moved to the refund it was (failProvisionalWithin()) and its item
            // refund released with its credit note. Was: booked for good, only the incident below.
            foreach (self::provisionalRefundsWithin($orderId) as $part) {
                if ($refundId === '' || $part['refund_id'] !== $refundId || $part['state'] !== 'confirmed') continue;
                self::failProvisionalWithin($order, $part, $provider, $eventKey, true);
                $itemRefund = self::pendingItemRefundByReference($orderId, $refundId);
                if ($itemRefund !== null) Rma::failRefund($itemRefund, "{$provider} refund {$refundId} of {$amount} failed");
                self::recordEvent($orderId, 'refund.failed', 'refund_failed', 0, $currency, $eventKey, $payload + ['amount' => $amount, 'provisional_refund' => $part['ledger']], $provider, $refundId);
                return true;
            }
            $booked = self::refundAlreadyRecorded($orderId, $refundId);
            $to = self::refundedAmount($orderId) > 0 ? 'partially_refunded' : 'paid';
            if (!$booked && $from === 'refund_pending' && self::isRequestedRefundExecution($orderId, $refundId) && self::canPaymentTransition($from, $to)) {
                // No money moves: amount 0, so the status row is never read as a capture or a refund.
                self::applyTransition($order, $to, 'refund.failed', 0, $eventKey, $payload + ['amount' => $amount], $refundId);
                AuditLog::log('order_refund', $orderId, $from, $to, "{$provider} refund {$refundId} of " . AuditLogLabels::amount($amount, $currency) . ' failed');
                return true;
            }
            // R14 T18: the pending refund of a credit note (item refund) under this provider id is released as well.
            if (!$booked && ($itemRefund = self::pendingItemRefundByReference($orderId, $refundId)) !== null
                && Rma::failRefund($itemRefund, "{$provider} refund {$refundId} of {$amount} failed")) {
                self::recordEvent($orderId, 'refund.failed', 'refund_failed', 0, $currency, $eventKey, $payload + ['amount' => $amount, 'item_refund_id' => $itemRefund], $provider, $refundId);
                return true;
            }
            self::recordEvent($orderId, 'refund.failed', 'ignored', $amount, $currency, $eventKey, $payload + ['payment_status' => $from, 'booked' => $booked], $provider, $refundId);
            return false;
        });
        IncidentMonitor::report('payment.refund_failed', 'payment', 'critical', ['order_id' => $orderId, 'refund_id' => $refundId, 'released' => $released]);
        return $released;
    }

    /**
     * R23: a declined item refund (Rma::failRefund()) released its credit note, but the payment stayed refund_pending, and
     * the admin's next refund was refused. With no other refund in flight it returns to paid / partially_refunded.
     */
    public static function releaseFailedItemRefund(int $orderId, int $itemRefundId): bool {
        return self::withLockedOrder($orderId, static function (array $order) use ($orderId, $itemRefundId): bool {
            if ((string)($order['payment_status'] ?? '') !== 'refund_pending' || self::requestedRefundInFlight($order) > 0) return false;
            $pending = Db::get()->prepare("SELECT 1 FROM order_refunds WHERE order_id = :order AND store_id = :store AND status = 'pending' LIMIT 1");
            $pending->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
            if ($pending->fetchColumn()) return false;
            $to = self::refundedAmount($orderId) > 0 ? 'partially_refunded' : 'paid';
            if (!self::canPaymentTransition('refund_pending', $to)) return false;
            // No money moves: amount 0, so the status row is never read as a capture or a refund.
            return self::applyTransition($order, $to, 'refund.failed', 0, 'item-refund-failed:' . $itemRefundId, ['item_refund_id' => $itemRefundId]);
        });
    }

    /** Whether the provider answered this order's refund request (not a credit note's item refund) as pending under $refundId. */
    private static function isRequestedRefundExecution(int $orderId, string $refundId): bool {
        if ($refundId === '') return false;
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions t WHERE t.order_id = :order AND t.store_id = :store
            AND t.status = 'refund_pending' AND t.provider_ref = :ref
            AND NOT EXISTS (SELECT 1 FROM order_refunds r WHERE r.order_id = t.order_id AND r.store_id = t.store_id
                AND (r.payment_reference = :ref2 OR r.idempotency_key = t.idempotency_key)) LIMIT 1");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':ref' => $refundId, ':ref2' => $refundId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * The pending item refund (credit note) a booked provider refund settles, by its provider refund id only. Matched by
     * amount, a whole-order refund or another credit note of the same sum completed the wrong one. A provider refund
     * booked before its id was noted on the credit note is linked when the refund job's result arrives (linkItemRefundWithin()).
     */
    private static function completeItemRefundWithin(int $orderId, string $refundId): void {
        $itemRefund = self::pendingItemRefundByReference($orderId, $refundId);
        if ($itemRefund !== null) Rma::completeRefund($itemRefund, $refundId);
    }

    /** The pending item refund (credit note) the provider refund $refundId was noted on. */
    private static function pendingItemRefundByReference(int $orderId, string $refundId): ?int {
        if ($refundId === '') return null;
        $stmt = Db::get()->prepare("SELECT id FROM order_refunds WHERE order_id = :order AND store_id = :store AND status = 'pending' AND payment_reference = :ref ORDER BY id LIMIT 1");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':ref' => $refundId]);
        $itemRefund = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $itemRefund === false ? null : (int)$itemRefund;
    }

    /**
     * The credit note a refund job executed ($idempotencyKey is its order_refunds key): its provider refund id is noted,
     * and once the money is booked (by this call or by the provider's webhook first) it is completed.
     */
    private static function linkItemRefundWithin(int $orderId, string $idempotencyKey, string $refundId, string $status): void {
        if ($idempotencyKey === '') return;
        $stmt = Db::get()->prepare("SELECT id FROM order_refunds WHERE order_id = :order AND store_id = :store AND idempotency_key = :key AND status = 'pending' LIMIT 1");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':key' => $idempotencyKey]);
        $itemRefund = $stmt->fetchColumn();
        if ($itemRefund === false) return;
        // R26: a refund the provider answered pending stays pending when only a summary event booked its money (its
        // refund_confirmed row): it may fail yet. Its own refund webhook booking the money under its id completes it.
        if (in_array($status, ['succeeded', 'confirmed'], true)
            || ($refundId !== '' && ($status === 'pending' ? self::refundMoneyBookedById($orderId, $refundId) : self::refundAlreadyRecorded($orderId, $refundId)))) {
            Rma::completeRefund((int)$itemRefund, $refundId !== '' ? $refundId : 'provider-refund');
        } elseif ($status === 'pending' && $refundId !== '') {
            Rma::noteProviderRefund((int)$itemRefund, $refundId);
        } elseif (in_array($status, ['failed', 'canceled', 'cancelled'], true)) {
            Rma::failRefund((int)$itemRefund, 'The payment provider declined the item refund');
        }
    }

    /** A fully refunded payment closes its returned or cancelled order. */
    private static function closeRefundedOrder(int $orderId, string $note): void {
        $order = Orders::byId($orderId);
        if ($order && (string)($order['payment_status'] ?? '') === 'refunded' && in_array((string)$order['status'], ['returned', 'cancelled'], true)
            && OrderWorkflow::canTransition((string)$order['status'], 'refunded')) {
            OrderWorkflow::transition($orderId, 'refunded', $note);
        }
    }

    /**
     * Run $work with the freshly read order inside a transaction that holds the order row lock.
     *
     * @param callable(array): bool $work
     */
    private static function withLockedOrder(int $orderId, callable $work): bool {
        $db = Db::get();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();
        try {
            self::lockOrderWithin($orderId);
            $order = Orders::byId($orderId);
            if (!$order) throw new RuntimeException("Order #{$orderId} not found");
            $result = $work($order);
            if ($ownTransaction) {
                $db->commit();
                self::flushRefundMails();
            }
            return $result;
        } catch (\Throwable $e) {
            self::rollBackOwn($ownTransaction);
            throw $e;
        }
    }

    /**
     * Serialise refund bookkeeping per order inside the open transaction: MySQL/MariaDB lock the
     * order row; SQLite takes its single writer lock now, before the ledger is read.
     */
    private static function lockOrderWithin(int $orderId): void {
        Orders::lockWithin($orderId);
    }

    /** Roll back a transaction this call opened, if it is still open. */
    private static function rollBackOwn(bool $ownTransaction): void {
        $db = Db::get();
        if ($ownTransaction && $db->inTransaction()) $db->rollBack();
        if ($ownTransaction) self::flushRefundMails(false);
    }

    /**
     * Payment method as the customer sees it: the label of the order's payment snapshot (written at checkout in the
     * order's language), never the internal code in orders.payment_method ('stripe', 'manual', 'free'). An order
     * without a snapshot label gets the translated generic name of its method type ('' when it has none).
     */
    public static function orderLabel(array $order, ?string $locale = null): string {
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $label = trim((string)(($snapshot['label'] ?? '') ?: ($snapshot['name'] ?? '')));
        $locale ??= (string)($order['locale'] ?? I18n::locale());
        // A seeded name frozen at checkout ('Invoice purchase') reads in the document's language; a merchant's name stays.
        if ($label !== '') return MethodLabels::name('payment', (string)($snapshot['code'] ?? ''), $label, $locale);
        $type = (string)(($snapshot['service_type'] ?? '') ?: ($order['payment_method'] ?? ''));
        if ($type === '') return '';
        $key = match ($type) {
            'free' => 'free',
            'cod', 'bank_transfer', 'invoice' => 'methods.payment.' . $type,
            default => 'methods.payment.card',
        };
        return I18n::tIn($key, $locale);
    }

    /**
     * Payment method of an order as the admin reads it (lists, order detail, operations): the method's name from the
     * checkout snapshot (a seeded name in the admin's language), else the generic name of its type; the internal code
     * (bank_transfer, cod, stripe) only when there is nothing else.
     */
    public static function adminLabel(array $order): string {
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $label = trim((string)(($snapshot['label'] ?? '') ?: ($snapshot['name'] ?? '')));
        if ($label !== '') return MethodLabels::name('payment', (string)($snapshot['code'] ?? ''), $label);
        $generic = self::orderLabel($order, I18n::locale());
        return $generic !== '' ? $generic : (string)($order['payment_method'] ?? '');
    }

    private static function orderProvider(array $order): string {
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? '{}'), true) ?: [];
        return (string)($snapshot['provider'] ?? $order['payment_method'] ?? '');
    }

    /** R26: whether the ledger holds money booked under the provider refund id $refundId itself (its own webhook or result). */
    private static function refundMoneyBookedById(int $orderId, string $refundId): bool {
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store AND provider_ref = :ref
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded') AND amount > 0 LIMIT 1");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':ref' => $refundId]);
        return (bool)$stmt->fetchColumn();
    }

    private static function refundAlreadyRecorded(int $orderId, string $refundId): bool {
        if ($refundId === '') return false;
        // R24: refund_confirmed too: a job result that confirmed money a refund event booked without this id (refundSettledByEvent()).
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store AND provider_ref = :ref
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded', 'refund_confirmed') LIMIT 1");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':ref' => $refundId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Provider codes of this store's active online payment methods: the gateways checkout can send the buyer to
     * (Security::paymentRedirectOrigins). Without the method tables checkout offers the Stripe fallback method.
     * @return list<string>
     */
    public static function onlineProviderCodes(): array {
        try {
            $stmt = Db::get()->prepare("SELECT DISTINCT provider FROM payment_methods WHERE store_id = :store AND active = 1 AND requires_online = 1 ORDER BY provider");
            $stmt->execute([':store' => StoreContext::current()->providerId()]);
            return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
        } catch (\PDOException $e) {
            return ['stripe'];
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
        $fixedFee = (int)($row['fixed_fee'] ?? 0);
        $fee = $fixedFee;
        $bps = (int)($row['percent_fee_bps'] ?? 0);
        if ($bps > 0) $fee += (int)ceil($amount * $bps / 10000);
        // Charged in units the currency shows (whole Kč/Ft).
        [$fee, $fixedFee] = [Money::round($fee, $currency), Money::round($fixedFee, $currency)];
        if (!empty($row['free_above']) && $amount >= (int)$row['free_above']) $fee = $fixedFee = 0;

        return [
            'code' => (string)$row['code'],
            'provider' => (string)($row['provider'] ?? ''),
            'label' => MethodLabels::name('payment', (string)$row['code'], (string)($row['name'] ?? $row['code'])),
            'description' => MethodLabels::description('payment', (string)$row['code'], (string)($row['description'] ?? '')),
            'service_type' => (string)($row['service_type'] ?? 'offline'),
            'fee' => $fee,
            'fixed_fee' => $fixedFee,
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
