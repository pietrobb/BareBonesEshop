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
        // R38 (low): no 'failed': the money was captured (only a refund of it is in flight).
        'refund_pending' => ['paid', 'partially_refunded', 'refunded'],
        'partially_refunded' => ['refund_pending', 'refunded', 'disputed'],
        'disputed' => ['paid', 'refund_pending', 'refunded'],
        // Money captured after a failure, a cancellation or an expiry is a fact the ledger records;
        // settleCapture() then pays the order or refunds the cancelled one.
        'failed' => ['paid', 'cancelled'],
        'cancelled' => ['paid'],
        'refunded' => [],
    ];
    /**
     * R38 (medium 7): a refund the provider executed is a fact the ledger records, as a capture after a failure is. During
     * a dispute or after a failed payment (whose capture the shop missed) it was logged 'ignored' and the ledger held 0.
     * Only provider refunds take these steps (bookRefundWithin()); the admin's manual status change does not offer them.
     */
    private const PROVIDER_REFUND_TRANSITIONS = [
        'disputed' => ['partially_refunded'],
        'failed' => ['partially_refunded', 'refunded'],
        // R39 (low): so is one of a payment the shop still holds as pending, authorized or cancelled (its capture missed).
        'pending' => ['partially_refunded', 'refunded'],
        'authorized' => ['partially_refunded', 'refunded'],
        'cancelled' => ['partially_refunded', 'refunded'],
    ];
    /** Payment states whose provider refund is the first sign of a capture the shop missed (bookRefundWithin()). */
    private const MISSED_CAPTURE_STATES = ['failed', 'pending', 'authorized', 'cancelled'];

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
        $shippingRefusesCod = $shippingMethod !== '' && self::shippingMethodRefusesCod($shippingMethod);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            // R36 (low): a delivery method set to "no cash on delivery" is not offered with it; the order refused it after.
            if ($shippingRefusesCod && ($row['service_type'] ?? '') === 'cod') continue;
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

    /** Whether the store's shipping method $code is set up without cash on delivery (supports_cod = 0). */
    private static function shippingMethodRefusesCod(string $code): bool {
        $db = Db::get();
        if (!Db::tableExists('shipping_methods', $db) || !Db::hasColumn('shipping_methods', 'store_id')) return false;
        $stmt = $db->prepare('SELECT supports_cod FROM shipping_methods WHERE store_id = :store AND code = :code LIMIT 1');
        $stmt->execute([':store' => StoreContext::current()->providerId(), ':code' => $code]);
        $supports = $stmt->fetchColumn();
        return $supports !== false && (int)$supports === 0;
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

    private static function canProviderRefundTransition(string $from, string $to): bool {
        return in_array($to, self::PROVIDER_REFUND_TRANSITIONS[$from] ?? [], true);
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
    private static function applyTransition(array $order, string $to, string $eventType, int $amount, string $idempotencyKey, array $payload, string $providerRef = '', bool $providerRefund = false): bool {
        $orderId = (int)$order['id'];
        $from = (string)($order['payment_status'] ?? 'pending');
        if ($from === $to) return false;
        if (!self::canPaymentTransition($from, $to) && !($providerRefund && self::canProviderRefundTransition($from, $to))) {
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
        // Claim item dispatch under the same order lock as late-refund cancellation. No DB lock spans provider I/O.
        if ($idempotencyKey !== null && $idempotencyKey !== '' && !self::withLockedOrder($orderId, static function (array $order) use ($orderId, $idempotencyKey, $gateway): bool {
            $item = Db::get()->prepare('SELECT status FROM order_refunds WHERE order_id = ? AND store_id = ? AND idempotency_key = ?');
            $item->execute([$orderId, StoreContext::current()->id(), $idempotencyKey]);
            $status = $item->fetchColumn(); $item->closeCursor();
            if ($status !== false && $status !== 'pending') return false;
            // R42 (low 8): the job of the order's open refund request marks its dispatch too (requestedRefundAtProvider());
            // a direct refund that executes no request leaves the ledger as it was.
            if ($status === false) {
                $request = self::latestRefundRequestRow($order, true);
                if ($request === null || self::refundRequestKey($idempotencyKey) !== (string)$request['idempotency_key']) return true;
            }
            self::logTransaction($orderId, self::providerName($gateway), '', 'refund_attempted', 0, (string)$order['currency'], 'refund_dispatch_' . $idempotencyKey,
                [$status === false ? 'dispatch_started' : 'item_dispatch_started' => true], true);
            return true;
        })) return ['status' => 'skipped'];
        if ($idempotencyKey !== null && $idempotencyKey !== '' && $gateway instanceof IdempotentRefundAdapter) {
            $result = $gateway->refundWithIdempotency($paymentId, $amount, $idempotencyKey);
        } elseif ($idempotencyKey !== null && $idempotencyKey !== '') {
            // Without provider idempotency a retry after an unknown outcome could refund twice:
            // the key allows one provider call, any further attempt is for a human to reconcile.
            $attemptKey = 'refund_attempt_' . $idempotencyKey;
            if (self::transactionExists($attemptKey) && self::transactionExists($idempotencyKey)) {
                // The provider answered the attempt (failed): asking it again could refund twice.
                throw new RuntimeException('Refund was already attempted at a provider without idempotent refunds; reconcile manually');
            } elseif (self::transactionExists($attemptKey) && self::refundRequestSettled($orderId, $idempotencyKey, $amount)) {
                // R39 (low): a provider notification (refunded in full) or the admin settled it meanwhile: nothing is
                // unknown any more (was: a critical "refund outcome unknown" incident for a booked refund).
                $result = ['status' => 'succeeded', 'refund_id' => ''];
            } elseif (self::transactionExists($attemptKey)) {
                // R38 (medium 8): the attempt's answer was lost (a timeout after GoPay/ThePay executed it). The provider's
                // payment state settles it when it can (refunded in full); else the admin confirms or releases it on the order.
                $result = self::recoverUnknownRefund($orderId, $paymentId, $amount, $gateway)
                    ?? throw new PaymentRefundOutcomeUnknown('Refund was already attempted at a provider without idempotent refunds; confirm or release it on the order');
            } else {
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
            }
        } else {
            $result = $gateway->refund($paymentId, $amount);
        }

        $status = (string)($result['status'] ?? 'failed');
        $outcome = $status;
        $refundId = (string)($result['refund_id'] ?? '');
        $db = Db::get();
        // R55 (low): GoPay/ThePay refuse the refund of a payment refunded in their dashboard meanwhile; the customer has the
        // money. The payment's REFUNDED status settles the refunds in flight first (applyRefundEvent()), the credit note
        // with its money. Was: the credit note released with a critical "refund failed", its money then booked outside it.
        // R57 (review round 22, medium S3): the payment's state read through statusEvent(), which every such adapter builds
        // from its own parameters. Was: a GoPay notification (['id' => …]) for all, which ThePay always refused as
        // unverified: its refused refund released the credit note and the money was booked as a refund outside it. Only a
        // refund report is applied: a PAID state (Comgate reports no refunds) says nothing about this refund.
        if ($status === 'failed' && $gateway instanceof PaymentStatusEventAdapter && !$db->inTransaction()) {
            try {
                $event = $gateway->statusEvent($paymentId);
                if ($event['event_type'] === 'refund.reported') self::applyProviderStatusEvent($event, self::providerName($gateway));
            } catch (\Throwable $e) {
                Logger::warning('refund.status_check_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            }
        }
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
                // R24: a refund event without refund ids booked this very refund already (applyRefundEvent(); a Stripe
                // charge.refunded without its refund list did up to 2.1.2).
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
            // R26: a refund still pending at the provider is not completed by money booked for it without its id: it may
            // fail yet; its refund.succeeded completes it.
            self::linkItemRefundWithin($orderId, (string)$idempotencyKey, $refundId, $outcome === 'pending' ? 'pending' : $status);
            // R27: the provider failed this refund before its pending result was recorded (its refund.failed found nothing to
            // release then): released now, as the event would have. Was: the refund request or item refund stayed pending.
            if ($outcome === 'pending' && ($failedBy = self::refundFailureRecorded($orderId, $refundId)) !== null) {
                self::providerRefundFailed($orderId, self::providerName($gateway), $refundId, $amount, $failedBy . '#result', ['after_result' => true]);
            }
            if ($ownTransaction) {
                $db->commit();
                self::flushRefundMails();
            }
        } catch (\Throwable $e) {
            self::rollBackOwn($ownTransaction);
            throw $e;
        }
        if (isset($failedBy)) self::closeReleasedOrder($orderId, 'Refund failed, the money was refunded already');

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
        return max(0, (int)($order['total'] ?? 0) - self::refundedAmount($orderId) - self::offLedgerCredits($order));
    }

    /**
     * The status a refund_pending payment returns to when the refund in flight is released (failed, declined): no money
     * moved, so what was refunded before decides. R30 (low): a fully refunded order went to partially_refunded.
     */
    private static function statusAfterRelease(array $order): string {
        $refunded = self::refundedAmount((int)$order['id']) + self::offLedgerCredits($order);
        return $refunded <= 0 ? 'paid' : ($refunded >= (int)$order['total'] ? 'refunded' : 'partially_refunded');
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
        $stmt = Db::get()->prepare("SELECT COALESCE(SUM(r.amount), 0) FROM order_refunds r WHERE " . self::UNBOOKED_CREDIT);
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        return max(0, (int)$stmt->fetchColumn());
    }

    private const UNBOOKED_CREDIT = "r.order_id = :order AND r.store_id = :store AND r.status = 'pending' AND NOT EXISTS (
        SELECT 1 FROM payment_transactions t
        WHERE t.order_id = r.order_id AND t.store_id = r.store_id
          AND t.status IN ('partially_refunded', 'refunded', 'refund_succeeded')
          AND (t.idempotency_key = r.idempotency_key OR (r.payment_reference IS NOT NULL AND r.payment_reference <> '' AND t.provider_ref = r.payment_reference)))";

    /**
     * The pending credit notes of unbookedCreditAmount(), oldest first.
     *
     * @return list<array{id:mixed, amount:mixed, idempotency_key:mixed, payment_reference:mixed}>
     */
    private static function pendingItemRefunds(int $orderId): array {
        $stmt = Db::get()->prepare("SELECT r.id, r.amount, r.idempotency_key, r.payment_reference FROM order_refunds r WHERE " . self::UNBOOKED_CREDIT . ' ORDER BY r.id');
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        return array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** What a new refund (admin request, cancellation, manual refund) may still claim: the balance less pending credit notes. */
    public static function claimableRefund(int $orderId): int {
        return max(0, self::refundableBalance($orderId) - self::unbookedCreditAmount($orderId));
    }

    /** A refund requested (requestRefund()) whose money is not booked yet: a credit note must not promise it again. */
    public static function requestedRefundInFlight(array $order): int {
        return (int)(self::latestRefundRequestRow($order, true)['amount'] ?? 0);
    }

    /**
     * R42 (low 8): requestedRefundInFlight() the provider may have executed already: its payment_refund job started
     * (dispatch marker, attempt or answer on the ledger), or the merchant refunds by hand at the provider. A request whose
     * job still waits in the queue explained a provider refund event it cannot be (a dashboard refund of the same money).
     */
    public static function requestedRefundAtProvider(array $order): int {
        $request = self::latestRefundRequestRow($order, true);
        if ($request === null) return 0;
        if (self::refundsByHand($order)) return (int)$request['amount'];
        $executeKey = self::refundExecuteKey((string)$request['idempotency_key']);
        $stmt = Db::get()->prepare('SELECT 1 FROM payment_transactions WHERE order_id = ? AND store_id = ? AND idempotency_key IN (?, ?, ?) LIMIT 1');
        $stmt->execute([(int)$order['id'], StoreContext::current()->id(), $executeKey, 'refund_attempt_' . $executeKey, 'refund_dispatch_' . $executeKey]);
        return $stmt->fetchColumn() ? (int)$request['amount'] : 0;
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
            SalesDaily::touchOrders([$orderId], $db);

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
     * R38 (medium 8): the outcome of a refund attempt at a provider without idempotent refunds was lost (a timeout after the
     * provider executed it). Its payment state answers it when the attempt was the whole remaining balance and the provider
     * reports the payment refunded in full (GoPay REFUNDED, ThePay refunded): that refund was executed. A partial refund
     * (reported without its amount) or a provider without refund states (Comgate) stays for the admin (unconfirmedProviderRefund()).
     *
     * @return array{refund_id:string, status:string, recovered_state:string}|null
     */
    private static function recoverUnknownRefund(int $orderId, string $paymentId, int $amount, PaymentAdapter $gateway): ?array {
        if (!$gateway instanceof PaymentStatusEventAdapter || $paymentId === '' || $amount !== self::refundableBalance($orderId)) return null;
        $state = (string)($gateway->statusEvent($paymentId)['data']['state'] ?? '');
        if (strtoupper($state) !== 'REFUNDED') return null;
        AuditLog::log('order_refund', $orderId, 'refund_pending', 'refund_pending', self::providerName($gateway) . " reports payment {$paymentId} refunded: the attempt whose answer was lost was executed");
        return ['refund_id' => '', 'status' => 'succeeded', 'recovered_state' => $state];
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
        // R29: a refund that settles the request already made latestRefundRequestRow() return null. Any other refund booked
        // meanwhile (one from the provider's dashboard) hid the retry of a request no job executed: it stayed refund_pending.
        $later = $db->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store
            AND idempotency_key IN (:execute, :attempt) LIMIT 1");
        $later->execute($params + [':execute' => $executeKey, ':attempt' => 'refund_attempt_' . $executeKey]);
        if ($later->fetchColumn()) return null;
        // R32 (low): "retry" was offered while the request's job still waited in the queue (the worker had not run yet), and
        // the second job it queued ran after the first. The file, database and Redis queues list their jobs; a backend that
        // cannot list them offers no retry (a job may still be waiting).
        $open = JobQueue::openJobs('payment_refund');
        if ($open === null) return null;
        foreach ($open as $job) {
            if ((int)($job['order_id'] ?? 0) === (int)$order['id'] && (string)($job['idempotency_key'] ?? '') === $executeKey) return null;
        }
        return ['amount' => (int)$request['amount'], 'idempotency_key' => $executeKey];
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
            // One request at a time: a refund booked after the latest one that settles it means a later item refund is in
            // flight. R28: only one that settles it; any refund booked meanwhile (a refund event during an admin refund)
            // released the request's money for a second refund or credit note. R29 (capability review 408): an item refund's own
            // money (under its order_refunds key or provider refund id) never settles it; matched by amount, an item refund of
            // the requested amount released the request.
            $later = Db::get()->prepare("SELECT t.idempotency_key, t.provider_ref, t.amount, t.status, t.raw_response FROM payment_transactions t WHERE t.order_id = :order
                AND t.store_id = :store AND t.id > :id AND t.status IN ('paid', 'partially_refunded', 'refunded', 'refund_succeeded', 'refund_confirmed')
                AND NOT EXISTS (SELECT 1 FROM order_refunds r WHERE r.order_id = t.order_id AND r.store_id = t.store_id
                    AND (r.idempotency_key = t.idempotency_key OR (r.payment_reference IS NOT NULL AND r.payment_reference <> '' AND t.provider_ref = r.payment_reference)))");
            $later->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id(), ':id' => (int)$row['id']]);
            $executeKey = self::refundExecuteKey((string)$row['idempotency_key']);
            foreach ($later->fetchAll(\PDO::FETCH_ASSOC) as $booked) {
                $bookedRaw = json_decode((string)($booked['raw_response'] ?? ''), true);
                // R30 (finding 2): a request the provider declined or failed was released (the payment left refund_pending).
                // Still read as in flight once a later credit note made the payment refund_pending again, it kept it there
                // for good, hid the refund form and offered to retry the declined refund.
                if (is_array($bookedRaw) && ($bookedRaw['event_type'] ?? null) === 'refund.failed' && (int)$booked['amount'] === 0) return null;
                if (self::releasesRefundRequest((string)$booked['status'], is_array($bookedRaw) ? $bookedRaw : [])) return null;
                if ((string)$booked['status'] === 'paid') {
                    // R32 (low): "paid" set by hand on the refund_pending payment released the request as a declined refund
                    // does; it counted as in flight again once a later credit note made the payment refund_pending.
                    if (self::releasesRefundRequest('paid', is_array($bookedRaw) ? $bookedRaw : [])) return null;
                    continue; // otherwise only a release returns a refund_pending payment to paid
                }
                if ((string)$booked['idempotency_key'] === $executeKey
                    || self::settlesRefundRequest($order, $row, is_array($bookedRaw) ? $bookedRaw : [], (string)($booked['provider_ref'] ?? ''), (int)$booked['amount'])) {
                    return null;
                }
            }
            return $row;
        }
        return null;
    }

    /**
     * R32: a ledger row (status, payload) that released the refund request before it: "paid" set by hand on the order.
     * R38: with refunds booked before, that release returns the payment to partially_refunded / refunded.
     */
    private static function releasesRefundRequest(string $status, array $payload): bool {
        return in_array($status, ['paid', 'partially_refunded', 'refunded'], true) && ($payload['event_type'] ?? null) === 'admin.paid';
    }

    /**
     * R32 (low): whether the request a whole-order payment_refund job executes ($executeKey) is over: a later request was
     * made (possible only once this one settled or was released) or "paid" was set by hand after it. Such a job, queued
     * twice by "retry" while the first was still waiting, moved the payment of the later request out of refund_pending
     * (as if that one had been paid out), and a day later, when Stripe no longer knew the key, could refund again.
     */
    public static function refundJobSuperseded(int $orderId, string $executeKey): bool {
        $requestKey = self::refundRequestKey($executeKey);
        if ($requestKey === null) return false;
        // R57 (review round 22, S3): released as declined or not executed (declinedRefundRequest(), releaseUnknownRefund()):
        // the job run again pays nothing out. Was: it asked the provider again, or failed "reconcile manually".
        if (self::transactionExists('refund-declined:' . $executeKey) || self::transactionExists('refund-not-executed:' . $executeKey)) return true;
        $params = [':order' => $orderId, ':store' => StoreContext::current()->id()];
        $own = Db::get()->prepare("SELECT id FROM payment_transactions WHERE order_id = :order AND store_id = :store AND idempotency_key = :key AND status = 'refund_pending'");
        $own->execute($params + [':key' => $requestKey]);
        $ownId = $own->fetchColumn();
        if ($ownId === false) return false;
        $later = Db::get()->prepare("SELECT status, raw_response FROM payment_transactions WHERE order_id = :order AND store_id = :store AND id > :id
            AND status IN ('refund_pending', 'paid', 'partially_refunded', 'refunded')");
        $later->execute($params + [':id' => (int)$ownId]);
        foreach ($later->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $raw = json_decode((string)($row['raw_response'] ?? ''), true);
            $raw = is_array($raw) ? $raw : [];
            if (((string)$row['status'] === 'refund_pending' && ($raw['event_type'] ?? null) === 'refund.requested')
                || self::releasesRefundRequest((string)$row['status'], $raw)) {
                return true;
            }
        }
        return false;
    }

    /**
     * R28: whether a booked refund (its ledger payload, provider refund id and amount) executes this refund request. Named by
     * the request (settles_refund_request) or by the refund id its job recorded under the execute key. A Stripe refund of
     * the shop carries its refund key, so one without is another refund (the dashboard's). Unnamed elsewhere, one of the
     * requested amount: a provider executes the amount asked for. Money returned by hand names no request: any refund.
     */
    private static function settlesRefundRequest(array $order, array $request, array $payload, string $refundId, int $amount): bool {
        $requestKey = (string)$request['idempotency_key'];
        $executeKey = self::refundExecuteKey($requestKey);
        $named = (string)($payload['settles_refund_request'] ?? '');
        if ($named !== '') return in_array($named, [$requestKey, $executeKey], true);
        if (self::refundsByHand($order)) return true;
        if ($refundId !== '') {
            $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store
                AND idempotency_key = :execute AND provider_ref = :ref LIMIT 1");
            $stmt->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id(), ':execute' => $executeKey, ':ref' => $refundId]);
            if ($stmt->fetchColumn()) return true;
            if ((string)($payload['provider'] ?? '') === 'stripe') return false;
        }
        return $amount === (int)$request['amount'];
    }

    /**
     * A refund request the provider accepted without naming a refund id (GoPay, ThePay): no refund
     * notification can identify it, and a partial refund is reported without its amount, so the payment
     * would stay refund_pending forever. The admin who saw it paid out completes it (confirmProviderRefund()).
     * Item refunds (credit notes, RMA) are executed under their own key and carry their order_refunds id.
     *
     * @return array{amount:int, request_key:string, refund_id?:int, unknown?:true}|null
     */
    public static function unconfirmedProviderRefund(array $order): ?array {
        $request = self::latestRefundRequestRow($order);
        if ($request === null) return self::unconfirmedItemRefund($order);
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store
            AND idempotency_key = :execute AND status = 'refund_pending' AND (provider_ref IS NULL OR provider_ref = '') LIMIT 1");
        $executeKey = self::refundExecuteKey((string)$request['idempotency_key']);
        $stmt->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id(), ':execute' => $executeKey]);
        if ($stmt->fetchColumn()) return ['amount' => (int)$request['amount'], 'request_key' => (string)$request['idempotency_key']];
        return self::unknownRefundOutcome($order, $executeKey)
            ? ['amount' => (int)$request['amount'], 'request_key' => (string)$request['idempotency_key'], 'unknown' => true] : null;
    }

    /** @param array{amount:int, request_key:string} $pending the refund offered now (unconfirmedProviderRefund()) */
    private static function isOfferedRefund(array $pending, string $requestKey, int $amount): bool {
        return $requestKey !== '' && hash_equals($pending['request_key'], $requestKey) && $pending['amount'] === $amount;
    }

    /**
     * R38 (medium 8): a provider without idempotent refunds (GoPay, Comgate, ThePay) was asked for the request's refund and
     * its answer never came (the attempt row, no result under the execute key), and no job of it waits: only the admin, who
     * checks the provider, knows whether it was executed. Was: the payment stayed refund_pending, and "paid" set by hand
     * dropped the refunded money from the ledger.
     */
    private static function unknownRefundOutcome(array $order, string $executeKey): bool {
        $stmt = Db::get()->prepare("SELECT idempotency_key FROM payment_transactions WHERE order_id = :order AND store_id = :store AND idempotency_key IN (:execute, :attempt)");
        $stmt->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id(), ':execute' => $executeKey, ':attempt' => 'refund_attempt_' . $executeKey]);
        $keys = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if ($keys !== ['refund_attempt_' . $executeKey]) return false;
        $open = JobQueue::openJobs('payment_refund');
        if ($open === null) return false;
        foreach ($open as $job) {
            if ((int)($job['order_id'] ?? 0) === (int)$order['id'] && (string)($job['idempotency_key'] ?? '') === $executeKey) return false;
        }
        return true;
    }

    /**
     * R38 (medium 8): the admin checked the provider and the refund whose answer was lost was not executed: the request is
     * released as a declined one (declinedRefundRequest()), the payment returns to paid / partially_refunded, audited.
     */
    public static function releaseUnknownRefund(int $orderId, string $actor, string $note, string $requestKey, int $amount): bool {
        if (trim($note) === '') throw new \InvalidArgumentException('A refund release needs a note');
        $released = self::withLockedOrder($orderId, static function (array $order) use ($orderId, $actor, $note, $requestKey, $amount): bool {
            $pending = self::unconfirmedProviderRefund($order);
            // R40 (medium 1): only the refund the admin saw (confirmProviderRefund()).
            if ($pending === null || empty($pending['unknown']) || !self::isOfferedRefund($pending, $requestKey, $amount)) return false;
            // R39 (medium 3): an item refund (credit note) not paid out is released as a declined one: its credit note reversed.
            if (isset($pending['refund_id'])) return Rma::failRefund((int)$pending['refund_id'], "Item refund not executed at the provider, released by {$actor}: {$note}");
            $to = self::statusAfterRelease($order);
            if (!self::canPaymentTransition('refund_pending', $to)) return false;
            $executeKey = self::refundExecuteKey($pending['request_key']);
            // No money moves: amount 0, read as the request's release (latestRefundRequestRow()).
            if (!self::applyTransition($order, $to, 'refund.failed', 0, 'refund-not-executed:' . $executeKey, ['amount' => $pending['amount'], 'actor' => $actor, 'note' => $note])) return false;
            AuditLog::log('order_refund', $orderId, 'refund_pending', $to, 'Refund of ' . AuditLogLabels::amount((int)$pending['amount'], (string)($order['currency'] ?? 'eur'))
                . " not executed at the provider, released by {$actor}: {$note}");
            return true;
        });
        if ($released) self::closeReleasedOrder($orderId, 'Refund not executed, the money was refunded already');
        return $released;
    }

    /**
     * The latest pending item refund (InvoiceService::credit()) whose payment_refund job the provider accepted
     * without a refund id: no webhook can find it (Rma::noteProviderRefund() had no id to note). Two such refunds
     * can be in flight at once: confirming the first moves the payment to partially_refunded, and the second must
     * still be offered, else its money is never booked.
     *
     * R39 (medium 2): whatever the payment's status: "not executed" on a whole-order refund in flight next to it returned
     * the payment to paid, and an item refund GoPay had accepted was offered no action (paid, GoPay 10.00 out, ledger 0).
     * R39 (medium 3): also one whose attempt's answer was lost (a timeout at a provider without idempotent refunds, no
     * job of it waiting): only "release" was offered, which reversed the credit note GoPay had paid out. Marked unknown:
     * the admin confirms it executed or not executed (releaseUnknownRefund()).
     *
     * @return array{amount:int, request_key:string, refund_id:int, unknown?:true}|null
     */
    private static function unconfirmedItemRefund(array $order): ?array {
        if (!in_array((string)($order['payment_status'] ?? ''), ['refund_pending', 'partially_refunded', 'paid', 'disputed'], true) || self::refundsByHand($order)) return null;
        $db = Db::get();
        $params = [':order' => (int)$order['id'], ':store' => StoreContext::current()->id()];
        $stmt = $db->prepare("SELECT r.id, r.amount, r.idempotency_key FROM order_refunds r
            WHERE r.order_id = :order AND r.store_id = :store AND r.status = 'pending' AND (r.payment_reference IS NULL OR r.payment_reference = '')
            ORDER BY r.id DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) return null;
        $ledger = $db->prepare("SELECT idempotency_key, status, provider_ref FROM payment_transactions WHERE order_id = :order AND store_id = :store AND idempotency_key IN (:key, :attempt)");
        $open = null;
        foreach ($rows as $row) {
            $key = (string)$row['idempotency_key'];
            $ledger->execute($params + [':key' => $key, ':attempt' => 'refund_attempt_' . $key]);
            $byKey = [];
            foreach ($ledger->fetchAll(\PDO::FETCH_ASSOC) as $tx) $byKey[(string)$tx['idempotency_key']] = $tx;
            $found = ['amount' => (int)$row['amount'], 'request_key' => $key, 'refund_id' => (int)$row['id']];
            $accepted = $byKey[$key] ?? null;
            if ($accepted !== null && $accepted['status'] === 'refund_pending' && (string)($accepted['provider_ref'] ?? '') === '') return $found;
            if ($accepted !== null || !isset($byKey['refund_attempt_' . $key])) continue;
            // The attempt without its answer: unknown once no job of it waits (a queue that cannot list its jobs: never).
            $open ??= JobQueue::openJobs('payment_refund') ?? false;
            if ($open === false) continue;
            foreach ($open as $job) {
                if ((int)($job['order_id'] ?? 0) === (int)$order['id'] && (int)($job['refund_id'] ?? 0) === (int)$row['id']) continue 2;
            }
            return $found + ['unknown' => true];
        }
        return null;
    }

    /**
     * R33 (low): an item refund (credit note) whose payment_refund job died — its re-queues after network errors ran out
     * (about 9 hours, WorkerJobs::REFUND_ERROR_REQUEUES) — stayed pending for good, and nothing let the admin release it.
     * Offered when the provider never answered for it (no refund id, no ledger row under its key; an attempt a provider
     * without idempotent refunds refused to repeat is for the admin to reconcile too) and no job of it is
     * waiting or running; a queue backend that cannot list its jobs offers nothing (a job may still be waiting).
     *
     * @return array{amount:int, refund_id:int}|null
     */
    public static function stalledItemRefund(array $order): ?array {
        if (self::refundsByHand($order)) return null;
        $stmt = Db::get()->prepare("SELECT r.id, r.amount, r.idempotency_key FROM order_refunds r
            WHERE r.order_id = :order AND r.store_id = :store AND r.status = 'pending' AND (r.payment_reference IS NULL OR r.payment_reference = '')
              AND NOT EXISTS (SELECT 1 FROM payment_transactions t WHERE t.order_id = r.order_id AND t.store_id = r.store_id
                AND t.idempotency_key = r.idempotency_key)
            ORDER BY r.id");
        $stmt->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id()]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) return null;
        $open = JobQueue::openJobs('payment_refund');
        if ($open === null) return null;
        $queued = [];
        foreach ($open as $job) {
            if ((int)($job['order_id'] ?? 0) === (int)$order['id']) $queued[(int)($job['refund_id'] ?? 0)] = true;
        }
        foreach ($rows as $row) {
            // R39 (medium 3): one whose attempt reached the provider (answer lost) is confirmed executed or not executed
            // (unconfirmedItemRefund()); "release" alone reversed the credit note GoPay had paid out.
            if (self::transactionExists('refund_attempt_' . (string)$row['idempotency_key'])) continue;
            if (!isset($queued[(int)$row['id']])) return ['amount' => (int)$row['amount'], 'refund_id' => (int)$row['id']];
        }
        return null;
    }

    /**
     * Release the stalled item refund (stalledItemRefund()) the admin checked at the provider: it fails like a declined
     * one (Rma::failRefund(): the credit note is reversed, the payment leaves refund_pending), audited with the admin and
     * the note. R34: a provider that can tell (RefundLookupAdapter) is asked first. A refund it executed after all (its
     * answer was lost in the outage) is booked as the credit note's refund instead ('refunded'), one it still processes is
     * noted for its webhook ('pending'); was: released, the late charge.refunded then booked the money without a credit
     * note. A provider that cannot be reached refuses the release (throws). Returns 'released', or '' when it is no longer
     * stalled (its job came back, or it settled meanwhile).
     */
    public static function releaseStalledItemRefund(int $orderId, int $itemRefundId, string $actor, string $note, bool $withoutProviderCheck = false): string {
        if (Db::get()->inTransaction()) throw new \LogicException('Release a stalled item refund outside a transaction');
        $order = Orders::byId($orderId);
        $stalled = $order ? self::stalledItemRefund($order) : null;
        $refund = Rma::refundById($itemRefundId) ?? [];
        // R34 (low): a second submit (double click) of a release that went through says so instead of failing. R35 (low):
        // also when the first one found the refund executed ('refunded') or still processed at the provider ('pending'), only
        // as that release's own outcome: a refund the provider accepted without a release is still not released ('').
        if ($order !== null && $stalled === null && (int)($refund['order_id'] ?? 0) === $orderId) {
            $found = self::releaseFoundProviderRefund($orderId, (string)($refund['payment_reference'] ?? ''));
            $settled = match (true) {
                ($refund['status'] ?? '') === 'failed' => 'already_released',
                $found && ($refund['status'] ?? '') === 'succeeded' => 'refunded',
                $found && ($refund['status'] ?? '') === 'pending' => 'pending',
                default => '',
            };
            if ($settled !== '') return $settled;
        }
        if ($order === null || $stalled === null || $stalled['refund_id'] !== $itemRefundId) return '';
        $key = (string)($refund['idempotency_key'] ?? '');
        $gateway = WorkerJobs::orderPaymentGateway([], $order);
        $provider = self::providerName($gateway);
        try {
            $found = $gateway instanceof RefundLookupAdapter ? $gateway->refundByKey((string)($order['payment_id'] ?? ''), $key) : null;
        } catch (\Throwable $e) {
            // R38 (low): a provider whose refund lookup keeps failing (an outage, a key that may not read refunds) left the
            // item refund stuck for good. The admin who checked the provider's dashboard may release it without the check;
            // a refund that was executed after all then gets its own credit note when its webhook books it.
            if (!$withoutProviderCheck) throw $e;
            $found = null;
            $note .= " (not checked with {$provider}: " . mb_substr($e->getMessage(), 0, 200) . ')';
        }
        $providerRefundId = (string)($found['refund_id'] ?? '');
        if (($found['status'] ?? '') === 'succeeded' && $providerRefundId !== '') {
            self::bookProviderRefund($orderId, $provider, $providerRefundId, (int)$found['amount'], 'refund.verified', $provider . '_refund_' . $providerRefundId, ['refund_key' => $key]);
            AuditLog::log('order_refund', $orderId, 'pending', 'succeeded', "Item refund release by {$actor}: {$provider} refund {$providerRefundId} was executed, booked instead");
            return 'refunded';
        }
        if (($found['status'] ?? '') === 'pending' && $providerRefundId !== '') {
            Rma::noteProviderRefund($itemRefundId, $providerRefundId);
            AuditLog::log('order_refund', $orderId, 'pending', 'pending', "Item refund release by {$actor}: {$provider} refund {$providerRefundId} is still processed");
            return 'pending';
        }
        return self::withLockedOrder($orderId, static function (array $current) use ($itemRefundId, $actor, $note): bool {
            $stalled = self::stalledItemRefund($current);
            if ($stalled === null || $stalled['refund_id'] !== $itemRefundId) return false;
            return Rma::failRefund($itemRefundId, "Item refund released by {$actor}: {$note}");
        }) ? 'released' : '';
    }

    /** R35: an earlier release on the order found provider refund $reference executed or still processed (its audit entry). */
    private static function releaseFoundProviderRefund(int $orderId, string $reference): bool {
        if ($reference === '') return false;
        foreach (AuditLog::forEntity('order_refund', $orderId, 200) as $row) {
            $note = (string)($row['note'] ?? '');
            if (str_starts_with($note, 'Item refund release by ') && str_contains($note, " refund {$reference} ")) return true;
        }
        return false;
    }

    /**
     * R39 (medium 4): whether a refund of this refund_pending payment reached the provider: the request's job called it
     * (its result or its attempt is on the ledger), or an item refund in flight did (accepted without a refund id, or its
     * answer lost). Such money is confirmed or marked not executed, never released by a manual status change.
     */
    public static function refundAtProvider(array $order): bool {
        if ((string)($order['payment_status'] ?? '') !== 'refund_pending' || self::refundsByHand($order)) return false;
        $request = self::latestRefundRequestRow($order);
        if ($request !== null) {
            $executeKey = self::refundExecuteKey((string)$request['idempotency_key']);
            $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store AND idempotency_key IN (:execute, :attempt) LIMIT 1");
            $stmt->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id(), ':execute' => $executeKey, ':attempt' => 'refund_attempt_' . $executeKey]);
            if ($stmt->fetchColumn()) return true;
        }
        return self::unconfirmedItemRefund($order) !== null;
    }

    /**
     * Book the provider refund in flight (unconfirmedProviderRefund()) the admin verified at the provider, audited.
     * R40 (medium 1): only the refund the admin saw ($requestKey, $amount from the page): was re-found by the handler, so a
     * double click or a stale page confirmed the next one offered (an older refund lost before the provider: ledger 2000,
     * the provider paid out 1000).
     */
    public static function confirmProviderRefund(int $orderId, string $actor, string $note, string $requestKey, int $amount): void {
        if (trim($note) === '') throw new \InvalidArgumentException('A refund confirmation needs a note');
        self::withLockedOrder($orderId, static function (array $order) use ($orderId, $actor, $note, $requestKey, $amount): bool {
            $pending = self::unconfirmedProviderRefund($order);
            if ($pending === null) throw new \LogicException('No provider refund awaits confirmation on this order');
            if (!self::isOfferedRefund($pending, $requestKey, $amount)) throw new \LogicException('The refund awaiting confirmation is not the one the admin saw');
            // Never more than the payment still holds (a provider report may have booked part of it already).
            if ($pending['amount'] > self::refundableBalance($orderId)) throw new \LogicException('The provider refund exceeds the refundable balance');
            // Names the request it settles, so a late provider report of the same refund books nothing more.
            if (!self::bookRefundWithin($order, self::orderProvider($order), '', $pending['amount'], 'refund.admin_confirmed',
                'refund_admin_confirmed_' . $pending['request_key'], ['actor' => $actor, 'note' => $note, 'settles_refund_request' => $pending['request_key']],
                $pending['refund_id'] ?? 0, 'admin-confirmed:' . $actor)) {
                throw new \LogicException('The provider refund could not be booked');
            }
            $to = (string)(Orders::byId($orderId)['payment_status'] ?? '');
            AuditLog::log('order_refund', $orderId, (string)($order['payment_status'] ?? ''), $to, 'Provider refund of ' . AuditLogLabels::amount((int)$pending['amount'], $order['currency'] ?? 'eur') . " confirmed by {$actor}: {$note}");
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
        $from = (string)($order['payment_status'] ?? '');
        // R39 (medium 4): a refund the provider accepted (or whose answer was lost) is money out, not a request to drop:
        // "paid" released it, the ledger held 0 and its confirmation was gone. It is confirmed or marked not executed.
        if ($newStatus === 'paid' && $from === 'refund_pending' && self::refundAtProvider($order)) {
            throw new \LogicException('A refund sent to the provider is confirmed or marked not executed, not dropped by "paid"');
        }
        // R38 (low): "paid" on a refund_pending payment releases the refund in flight (R32). Refunds booked before it stay
        // refunded: was "paid", although part of the money was returned. No money moves (amount 0). R39 (low): so on a
        // disputed payment (the dispute won): "paid" dropped the refunds booked during the dispute from its status.
        if ($newStatus === 'paid' && in_array($from, ['refund_pending', 'disputed'], true) && self::statusAfterRelease($order) !== 'paid') {
            $released = self::withLockedOrder($orderId, static fn(array $current): bool => (string)($current['payment_status'] ?? '') === $from
                && self::applyTransition($current, self::statusAfterRelease($current), 'admin.paid', 0, $key, ['actor' => $actor, 'note' => $note], '', $from === 'disputed'));
            if ($released) self::closeReleasedOrder($orderId, 'Admin payment: the money was refunded already');
            return false;
        }
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
            // R29 (review round 29, finding 4): an item refund job that ended while an admin or cancellation refund is in
            // flight moved the payment out of refund_pending, so the request's money counted as free again and the admin
            // could request it a second time (the customer got 22.00 instead of 40.99, no warning). Its money is booked;
            // the payment stays refund_pending until the request settles, as for a refund event (bookRefundWithin()).
            if ($itemRefund && $from === 'refund_pending' && ($request = self::latestRefundRequestRow($order, true)) !== null) {
                $currency = (string)($order['currency'] ?? 'eur');
                AuditLog::log('order_refund', $orderId, $from, $from, 'Item refund ' . ($refundId !== '' ? $refundId . ' ' : '') . 'of '
                    . AuditLogLabels::amount($amount, $currency) . ' booked while the refund of ' . AuditLogLabels::amount((int)$request['amount'], $currency) . ' is in flight');
                if (self::refundedAmount($orderId) + (int)$request['amount'] > (int)$order['total']) {
                    IncidentMonitor::report('payment.refund_overcommitted', 'payment', 'critical', ['order_id' => $orderId]);
                }
                return false;
            }
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
            SalesDaily::touchOrders([$orderId], $db);
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

    /**
     * R34 (low): tell the customer about an item refund paid back by hand (an order without a payment id, completed as
     * 'manual-admin'): it books no ledger row, so queueRefundMail() never ran for it. Its credit note exists already.
     */
    public static function mailManualItemRefund(int $orderId, int $amount): void {
        $order = Orders::byId($orderId);
        if (!$order || $amount <= 0 || trim((string)($order['customer_email'] ?? '')) === '') return;
        JobQueue::push('email_send', [
            'template' => 'refund_issued',
            'to' => (string)$order['customer_email'],
            'locale' => (string)($order['locale'] ?? ''),
            'order_id' => $orderId,
            'refund_amount' => $amount,
        ], 5);
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
            // R52: a ledger row changes the order's day in the sales totals (a refund booked with the payment status
            // unchanged writes nothing else). Logged here, in the row's transaction: SalesDaily's id watcher can pass an
            // id whose transaction commits after a higher one (MySQL).
            if ($stmt->rowCount() === 1) SalesDaily::touchOrders([$orderId], $db);
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
        $late = false;
        $settle = self::withLockedOrder($orderId, static function (array $order) use ($orderId, $provider, $eventType, $eventKey, $data, $amount, $currency, $orderCurrency, &$late): bool {
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
            $late = $to === 'paid' && in_array($from, ['partially_refunded', 'refunded'], true);
            return $to === 'paid' && $from === 'paid';
        });

        if ($settle) self::settleCapture($orderId, 'Payment confirmed via webhook');
        if ($late) self::settleLateCapture($orderId, 'Payment confirmed via webhook');
    }

    /**
     * R40 (low): a capture that arrives after the provider's refund of it was booked (bookRefundWithin(): the refund of a
     * payment the shop still held pending, authorized or cancelled is the first sign of its capture). The capture was
     * only logged (the payment does not leave refunded): the order stayed pending for ever with its stock reserved. The
     * payment stays as its refunds left it; the order is settled: refunded in full it is cancelled (its stock released),
     * in part it is paid (settleCapture()). A capture the ledger already holds (a redelivery) changes nothing.
     */
    public static function settleLateCapture(int $orderId, string $note): void {
        $order = Orders::byId($orderId);
        if (!$order) return;
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store AND status = 'paid' LIMIT 1");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        if ($stmt->fetchColumn() !== false) return;
        $payment = (string)($order['payment_status'] ?? '');
        // R41 (low 5): only an order still waiting for its payment (it can become paid) is settled. One fulfilled already,
        // whose older data has no 'paid' ledger row, was cancelled and its stock put back; it is only logged, as before R40.
        if (!OrderWorkflow::canTransition((string)$order['status'], 'paid')) {
            AuditLog::log('payment_webhook_ignored', $orderId, $payment, $payment, $note . ': captured after a refund, order ' . (string)$order['status'] . ' left as it is');
            return;
        }
        if ($payment === 'refunded' && OrderWorkflow::canTransition((string)$order['status'], 'cancelled')) {
            OrderWorkflow::transition($orderId, 'cancelled', $note . ': captured after it was refunded in full');
        } elseif ($payment === 'partially_refunded') {
            self::settleCapture($orderId, $note . ': captured after a part of it was refunded');
        }
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
            // R39 (low): the request itself (latestRefundRequestRow()), not the latest refund_pending row: that was the
            // item refund GoPay accepted, booked as a request, and the item refund (credit note) stayed pending for good.
            $request = self::latestRefundRequestRow($order, true);
            $inFlight = (int)($request['amount'] ?? 0);
            if (strtolower((string)($data['state'] ?? '')) === 'refunded' && $from === 'refund_pending' && $currency === $orderCurrency
                && $inFlight > 0 && $inFlight === self::refundableBalance($orderId)) {
                // Names the request it settles, so the refund worker's own result only confirms it (refundRequestSettled()).
                return self::bookRefundWithin($order, $provider, '', $inFlight, $eventType, $eventKey,
                    $payload + ['settles_refund_request' => (string)($request['idempotency_key'] ?? '')]);
            }
            // R53 (low): the whole payment refunded in the dashboard while a smaller refund of the shop is in flight. Was:
            // only logged, the order stayed refund_pending with the rest counted as paid. The rest is booked as a refund
            // outside the shop. The request is settled first (named, so an equal amount is not mistaken for it), then the rest
            // is booked against the payment that request left partially refunded, which refunds it.
            // R55 (review round 20, medium 2): credit notes pending beside that request are refunds in flight too: each is
            // booked with its credit note (as below), only the rest outside the shop. Was: their money was booked outside
            // the shop and their item refunds stayed pending for good, with nothing for the admin to confirm or release.
            $balance = self::refundableBalance($orderId);
            $pendingBeside = $inFlight > 0 ? self::pendingItemRefunds($orderId) : [];
            $besideItems = array_sum(array_map(static fn(array $row): int => (int)$row['amount'], $pendingBeside));
            if (strtolower((string)($data['state'] ?? '')) === 'refunded' && $from === 'refund_pending' && $currency === $orderCurrency
                && $inFlight > 0 && $inFlight < $balance && $inFlight + $besideItems <= $balance && ($pendingBeside === [] || !self::refundsByHand($order))) {
                $settled = self::bookRefundWithin($order, $provider, '', $inFlight, $eventType, $eventKey,
                    $payload + ['settles_refund_request' => (string)($request['idempotency_key'] ?? '')]);
                $current = $settled ? Orders::byId($orderId) : null;
                if ($current === null) return false;
                foreach ($pendingBeside as $row) {
                    $reference = (string)($row['payment_reference'] ?? '');
                    $refundId = $reference === (string)($order['payment_id'] ?? '') ? '' : $reference;
                    $key = $eventKey . ':credit:' . (int)$row['id'];
                    self::bookRefundWithin($current, $provider, $refundId, (int)$row['amount'], $eventType, $key,
                        $payload + ['settles_refund_request' => (string)$row['idempotency_key']], (int)$row['id'], $reference !== '' ? $reference : $key);
                    $current = Orders::byId((int)$current['id']) ?? $current; // re-read: the booking changed its payment state
                }
                $rest = $balance - $inFlight - $besideItems;
                if ($rest > 0) self::bookRefundWithin($current, $provider, '', $rest, $eventType, $eventKey . ':outside', $payload);
                return true;
            }
            // R39 (low): item refunds (credit notes) still pending when the payment is reported refunded are settled by it.
            // R51 (low): the whole payment refunded in the GoPay/ThePay dashboard, no refund of the shop in flight: the
            // remaining balance is booked as a refund outside the shop (like a Stripe dashboard refund), so the order closes.
            // R54 (review round 19, medium 3): every pending credit note is a refund in flight, whatever its job's state
            // (queued, being sent, accepted with or without a refund id). Was: one not yet accepted was ignored, the whole
            // balance booked outside the shop and its credit note then declined (money booked with no credit note), and an
            // accepted one smaller than the balance ignored the event for good (the payment stayed paid). Each is booked
            // with its credit note; only the rest is a refund outside the shop. A partial refund there names no amount and
            // stays a log entry.
            // R56 (review round 21, medium 2): also a refund_pending payment with nothing in flight: the shop's refund job
            // booked its money (refund_succeeded) and has not moved the payment on yet (settleRefundJob()). Was: ignored,
            // the event's id used up, so GoPay's later notifications and polls were dropped as duplicates (3000 refunded
            // there, 500 in the ledger, the order still to be shipped).
            $balance = self::refundableBalance($orderId);
            $pending = $request === null ? self::pendingItemRefunds($orderId) : [];
            $inFlightItems = array_sum(array_map(static fn(array $row): int => (int)$row['amount'], $pending));
            if ($request === null && strtolower((string)($data['state'] ?? '')) === 'refunded' && $currency === $orderCurrency && $balance > 0
                && ($pending !== [] ? $inFlightItems <= $balance && !self::refundsByHand($order) : in_array($from, ['paid', 'partially_refunded', 'disputed', 'refund_pending'], true))) {
                $rest = $balance - $inFlightItems;
                $booked = false;
                $current = $order;
                foreach ($pending as $i => $row) {
                    $reference = (string)($row['payment_reference'] ?? '');
                    // A reference that is the payment's own id (GoPay names no refund ids) identifies no refund.
                    $refundId = $reference === (string)($order['payment_id'] ?? '') ? '' : $reference;
                    // The last one settles under the event's key when nothing else is booked (R39), so a redelivery finds it.
                    $key = $rest === 0 && $i === array_key_last($pending) ? $eventKey : $eventKey . ':credit:' . (int)$row['id'];
                    $booked = self::bookRefundWithin($current, $provider, $refundId, (int)$row['amount'], $eventType, $key,
                        $payload + ['settles_refund_request' => (string)$row['idempotency_key']], (int)$row['id'], $reference !== '' ? $reference : $key) || $booked;
                    $current = Orders::byId($orderId) ?? $current;
                }
                if ($rest > 0) $booked = self::bookRefundWithin($current, $provider, '', $rest, $eventType, $eventKey, $payload) || $booked;
                return $booked;
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
        $booked = self::withLockedOrder($orderId, static fn(array $order): bool => self::bookProviderRefundWithin($order, $provider, $refundId, $amount, $eventType, $eventKey, $payload));
        if ($booked && !Db::get()->inTransaction()) self::closeRefundedOrder($orderId, 'Provider refund booked');
        return $booked;
    }

    /**
     * A provider refund named by its id. R25: one that is the shop's own refund in flight ($payload['refund_key'], the
     * refund key Stripe carries in the refund's metadata) is booked as that refund (its credit note, its request). One whose
     * money a refund event without refund ids booked already as that refund (a Stripe charge.refunded without its refund
     * list up to 2.1.2) is only identified: a refund_confirmed row under its id, no money.
     * R27: nothing else is booked without its refund id any more (a charge.refunded whose refunds cannot be listed waits
     * for Stripe to redeliver it), so a refund of no shop key is booked as it is.
     */
    private static function bookProviderRefundWithin(array $order, string $provider, string $refundId, int $amount, string $eventType, string $eventKey, array $payload): bool {
        $orderId = (int)$order['id'];
        $key = (string)($payload['refund_key'] ?? '');
        if ($key !== '' && $amount > 0 && !self::transactionExists($eventKey) && !self::refundAlreadyRecorded($orderId, $refundId)
            && self::refundSettledByEvent($orderId, $key)) {
            self::logTransaction(
                orderId: $orderId,
                provider: $provider,
                providerRef: $refundId,
                status: 'refund_confirmed',
                amount: $amount,
                currency: strtolower((string)($order['currency'] ?? 'eur')),
                idempotencyKey: $eventKey,
                rawResponse: ['event_type' => $eventType, 'provider' => $provider, 'refund_id' => $refundId, 'identifies_booked_refund' => true] + $payload,
                failClosed: true,
            );
            self::noteRefundIdWithin($orderId, $key, $refundId);
            self::linkItemRefundWithin($orderId, $key, $refundId, 'confirmed');
            return false;
        }
        if ($key === '') {
            if (self::identifiesLegacyRefundWithin($order, $provider, $refundId, $amount, $eventType, $eventKey, $payload)) return false;
            return self::bookRefundWithin($order, $provider, $refundId, $amount, $eventType, $eventKey, $payload);
        }
        // The shop's own refund, before its job's result: booked as the refund it executes, so the item refund's credit
        // note is the only one and an admin refund gets one (creditBookedRefund()).
        $item = Db::get()->prepare("SELECT id, status FROM order_refunds WHERE order_id = :order AND store_id = :store AND idempotency_key = :key LIMIT 1");
        $item->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':key' => $key]);
        $itemRow = $item->fetch(\PDO::FETCH_ASSOC) ?: [];
        $itemRefundId = ($itemRow['status'] ?? '') === 'pending' ? (int)$itemRow['id'] : 0;
        // R34: the credit note of this refund was released (or declined) and reversed, yet the provider paid it out: the
        // money is booked, and a credit note for it follows (InvoiceService::creditBookedRefund()); the merchant is told.
        $lateRelease = ($itemRow['status'] ?? '') === 'failed' && !self::transactionExists($eventKey) && !self::refundAlreadyRecorded($orderId, $refundId);
        if ($lateRelease) {
            // R36 (low): no status pair: "failed → failed" read "Payment failed → Payment failed"; the booking logs the payment's change.
            AuditLog::log('order_refund', $orderId, null, null, "{$provider} refund {$refundId} of a released item refund was executed");
            IncidentMonitor::report('payment.released_refund_executed', 'payment', 'critical', ['order_id' => $orderId, 'item_refund_id' => (int)$itemRow['id'], 'refund_id' => $refundId]);
        }
        $booked = self::bookRefundWithin($order, $provider, $refundId, $amount, $eventType, $eventKey,
            $payload + ['settles_refund_request' => self::refundRequestKey($key) ?? $key], $itemRefundId, $refundId);
        // R35 (medium 1): that money refunded the returned units; "Refunded" on the return paid them out again.
        if ($lateRelease && $booked && Rma::coverReleasedRefund((int)$itemRow['id'], $refundId)) {
            // R36 (medium 4): the admin may have refunded those items again meanwhile; that second refund is stopped.
            $duplicates = Rma::stopDuplicateRefunds($orderId, (int)$itemRow['id'], "Item refund {refund} stopped: {$provider} refund {$refundId} of the released item refund {$itemRow['id']} paid it");
            foreach ($duplicates['stopped'] as $stopped) {
                IncidentMonitor::report('payment.duplicate_refund_stopped', 'payment', 'critical', ['order_id' => $orderId, 'item_refund_id' => $stopped]);
            }
            foreach ($duplicates['sent'] as $sent) {
                AuditLog::log('order_refund', $orderId, 'pending', 'pending', "Item refund {$sent} was already sent to {$provider}: the released item refund {$itemRow['id']} paid the same items ({$provider} refund {$refundId})");
                IncidentMonitor::report('payment.duplicate_refund_sent', 'payment', 'critical', ['order_id' => $orderId, 'item_refund_id' => $sent]);
            }
        }
        return $booked;
    }

    /**
     * R29: a refund booked before refunds were booked by their id. Up to 2.1.2 a Stripe charge.refunded booked the change
     * of the charge's refunded total under the payment id, without refund ids. A later event that names that refund (each
     * charge.refunded lists all of the charge's refunds, so the next dashboard refund does) booked its money again. A refund
     * without a shop key that Stripe created before the last such booking is identified against that unattributed money,
     * oldest first (applyStripeChargeRefunded() sorts them): a refund_confirmed row under its id, no money.
     */
    private static function identifiesLegacyRefundWithin(array $order, string $provider, string $refundId, int $amount, string $eventType, string $eventKey, array $payload): bool {
        $orderId = (int)$order['id'];
        $paymentId = trim((string)($order['payment_id'] ?? ''));
        $created = (int)($payload['refund_created'] ?? 0);
        if ($paymentId === '' || $amount <= 0 || $created <= 0 || self::transactionExists($eventKey) || self::refundAlreadyRecorded($orderId, $refundId)) return false;
        $stmt = Db::get()->prepare("SELECT status, amount, raw_response, created_at FROM payment_transactions WHERE order_id = :order AND store_id = :store
            AND (provider_ref = :ref OR status = 'refund_confirmed') AND amount > 0
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded', 'refund_confirmed')");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':ref' => $paymentId]);
        $unattributed = 0;
        $latest = null;
        $covered = null; // the charge's highest refunded total (amount_refunded) such a booking saw
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $raw = json_decode((string)$row['raw_response'], true);
            $raw = is_array($raw) ? $raw : [];
            if ($row['status'] === 'refund_confirmed') {
                if (!empty($raw['identifies_legacy_refund'])) $unattributed -= (int)$row['amount'];
                continue;
            }
            // The shop's own refunds and the ones booked by id name what they settled.
            if (isset($raw['refund_id']) || isset($raw['settles_refund_request'])) continue;
            $unattributed += (int)$row['amount'];
            $at = strtotime((string)$row['created_at'] . ' UTC');
            if ($at !== false) $latest = max($latest ?? $at, $at);
            if (isset($raw['cumulative_amount'])) $covered = max($covered ?? 0, (int)$raw['cumulative_amount']);
        }
        if ($latest === null || $amount > $unattributed) return false;
        // R30 (low): five minutes either side of the last such booking (a shop clock behind Stripe's) a new dashboard refund
        // was taken for one the old code booked, and the old one was booked again when it came by id. A charge.refunded lists
        // the charge's refunds: one whose running total (oldest first) the old booking's refunded total covers is one it
        // booked, whatever the clocks say. A refund alone (refund.updated) near that time waits for that list.
        $position = isset($payload['refund_position']) ? (int)$payload['refund_position'] : null;
        if ($position !== null && $covered !== null) {
            if ($position > $covered) return false;
        } elseif ($created > $latest + 300) {
            return false;
        } elseif ($position === null && $created > $latest - 300) {
            throw new \PaymentWebhookDeferred("Stripe refund {$refundId} was created when a refund was booked without its id; it waits for the charge.refunded that lists the charge's refunds");
        }
        $currency = strtolower((string)($order['currency'] ?? 'eur'));
        self::logTransaction(
            orderId: $orderId,
            provider: $provider,
            providerRef: $refundId,
            status: 'refund_confirmed',
            amount: $amount,
            currency: $currency,
            idempotencyKey: $eventKey,
            rawResponse: ['event_type' => $eventType, 'provider' => $provider, 'refund_id' => $refundId, 'identifies_legacy_refund' => true] + $payload,
            failClosed: true,
        );
        $status = (string)($order['payment_status'] ?? '');
        AuditLog::log('order_refund', $orderId, $status, $status, "{$provider} refund {$refundId} of " . AuditLogLabels::amount($amount, $currency)
            . ' was booked before refunds were booked by their id; not booked again');
        return true;
    }

    /**
     * R25: the provider refund id of a refund in flight that an event without refund ids settled (refundSettledByEvent()):
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
     * Whether a provider-reported refund without its refund id (refund.reported; a Stripe charge.refunded up to 2.1.2) was booked as the
     * refund this payment_refund job executes ($executeKey: the item refund's key, or the request's execute key).
     */
    public static function refundSettledByEvent(int $orderId, string $executeKey): bool {
        if ($executeKey === '') return false;
        $keys = array_filter([$executeKey, self::refundRequestKey($executeKey)]);
        $stmt = Db::get()->prepare("SELECT idempotency_key, raw_response FROM payment_transactions WHERE order_id = :order AND store_id = :store
            AND status IN ('partially_refunded', 'refunded', 'refund_succeeded')");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $settled = json_decode((string)$row['raw_response'], true);
            if (is_array($settled) && in_array($settled['settles_refund_request'] ?? null, $keys, true)) return true;
        }
        return false;
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
        // R28: a refund event while the shop's own refund is in flight (admin refund, cancellation) that is not that refund
        // moved the payment out of refund_pending: the request's money counted as free again (a second refund or credit note
        // could promise it) and its job's result no longer moved the payment (settleRefundJob()). Its money is booked; the
        // payment stays refund_pending until the request settles.
        $request = $from === 'refund_pending' ? self::latestRefundRequestRow($order, true) : null;
        if ($request !== null && !self::settlesRefundRequest($order, $request, $payload, $refundId, $amount)) {
            self::recordEvent($orderId, $eventType, $to, $amount, $currency, $eventKey, $payload, $provider, $ref);
            AuditLog::log('order_refund', $orderId, $from, $from, "{$provider} refund {$ref} of " . AuditLogLabels::amount($amount, $currency)
                . ' booked while the refund of ' . AuditLogLabels::amount((int)$request['amount'], $currency) . ' is in flight');
            if (self::refundedAmount($orderId) + (int)$request['amount'] > (int)$order['total']) {
                // Together with the refund in flight more than was paid: the provider refuses or pays the customer twice.
                IncidentMonitor::report('payment.refund_overcommitted', 'payment', 'critical', ['order_id' => $orderId]);
            }
            if ($itemRefundId > 0) Rma::completeRefund($itemRefundId, $itemReference);
            else self::completeItemRefundWithin($orderId, $refundId);
            return true;
        }
        if ($to !== $from && (self::canPaymentTransition($from, $to) || self::canProviderRefundTransition($from, $to))) {
            self::applyTransition($order, $to, $eventType, $amount, $eventKey, $payload, $ref, true);
            if (in_array($from, self::MISSED_CAPTURE_STATES, true)) {
                // The shop recorded the payment as failed (or never captured), yet the provider captured it: its refund is the first sign.
                AuditLog::log('order_refund', $orderId, $from, $to, "{$provider} refund {$ref} of a payment recorded as {$from}");
                IncidentMonitor::report('payment.refund_after_failed', 'payment', 'critical', ['order_id' => $orderId, 'refund_id' => $ref, 'amount' => $amount]);
            }
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
            $booked = self::refundAlreadyRecorded($orderId, $refundId);
            $to = self::statusAfterRelease($order);
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
        IncidentMonitor::report('payment.refund_failed', 'payment', self::releasedSeverity($orderId, $released), ['order_id' => $orderId, 'refund_id' => $refundId, 'released' => $released]);
        if ($released) self::closeReleasedOrder($orderId, 'Refund failed, the money was refunded already');
        return $released;
    }

    /**
     * R23: a declined item refund (Rma::failRefund()) released its credit note, but the payment stayed refund_pending, and
     * the admin's next refund was refused. With no other refund in flight it returns to paid / partially_refunded.
     */
    public static function releaseFailedItemRefund(int $orderId, int $itemRefundId): bool {
        $released = self::withLockedOrder($orderId, static function (array $order) use ($orderId, $itemRefundId): bool {
            if ((string)($order['payment_status'] ?? '') !== 'refund_pending' || self::requestedRefundInFlight($order) > 0) return false;
            $pending = Db::get()->prepare("SELECT 1 FROM order_refunds WHERE order_id = :order AND store_id = :store AND status = 'pending' LIMIT 1");
            $pending->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
            if ($pending->fetchColumn()) return false;
            $to = self::statusAfterRelease($order);
            if (!self::canPaymentTransition('refund_pending', $to)) return false;
            // No money moves: amount 0, so the status row is never read as a capture or a refund.
            return self::applyTransition($order, $to, 'refund.failed', 0, 'item-refund-failed:' . $itemRefundId, ['item_refund_id' => $itemRefundId]);
        });
        if ($released) self::closeReleasedOrder($orderId, 'Item refund failed, the money was refunded already');
        return $released;
    }

    /**
     * R29 (finding 5): the provider declined the refund of this order's refund request for good (PaymentRefundDeclined).
     * Was: the job retried the same declined request until it died, the payment stayed refund_pending, and once a refund
     * from the provider's dashboard was booked the admin could neither retry it nor request a new one. No money moved:
     * the request is released, the payment returns to paid / partially_refunded and the merchant is told.
     */
    public static function declinedRefundRequest(int $orderId, string $executeKey, string $reason): bool {
        $released = self::withLockedOrder($orderId, static function (array $order) use ($orderId, $executeKey, $reason): bool {
            $request = self::latestRefundRequestRow($order, true);
            if ($request === null || self::refundExecuteKey((string)$request['idempotency_key']) !== $executeKey) return false;
            $to = self::statusAfterRelease($order);
            if (!self::canPaymentTransition('refund_pending', $to)) return false;
            $amount = (int)$request['amount'];
            // No money moves: amount 0, so the status row is never read as a capture or a refund.
            if (!self::applyTransition($order, $to, 'refund.failed', 0, 'refund-declined:' . $executeKey, ['amount' => $amount, 'reason' => $reason])) return false;
            AuditLog::log('order_refund', $orderId, 'refund_pending', $to, 'Refund of '
                . AuditLogLabels::amount($amount, (string)($order['currency'] ?? 'eur')) . ' declined by the provider: ' . $reason);
            return true;
        });
        IncidentMonitor::report('payment.refund_failed', 'payment', self::releasedSeverity($orderId, $released), ['order_id' => $orderId, 'declined' => true, 'released' => $released]);
        if ($released) self::closeReleasedOrder($orderId, 'Refund declined, the money was refunded already');
        return $released;
    }

    /**
     * R56 (review round 21, low): a released refund is critical (the customer may be unpaid) unless the payment is refunded
     * in full after it (the money went back another way, e.g. in the GoPay/ThePay dashboard): then a warning. Was: a
     * critical "refund failed" for a customer who had all the money.
     */
    private static function releasedSeverity(int $orderId, bool $released): string {
        return $released && (string)(Orders::byId($orderId)['payment_status'] ?? '') === 'refunded' ? 'warning' : 'critical';
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

    /**
     * R55 (review round 20, medium 4): a refund released (declined, failed, not executed, dropped by "paid") after the
     * rest of the money went back another way leaves the payment refunded; the order is closed as by any full refund.
     * Was: it stayed processing and could still be shipped. Inside a caller's transaction the caller closes it after
     * its commit (OrderWorkflow::transition() opens its own).
     */
    private static function closeReleasedOrder(int $orderId, string $note): void {
        if (!Db::get()->inTransaction()) self::closeRefundedOrder($orderId, $note);
    }

    /**
     * A fully refunded payment closes its returned or cancelled order. R50: an order not shipped yet (paid, accepted)
     * whose money all went back (a full manual refund, a refund in the PayU/GoPay dashboard) is cancelled first; it
     * stayed paid and could still be shipped.
     */
    public static function closeRefundedOrder(int $orderId, string $note): void {
        $order = Orders::byId($orderId);
        if ($order && (string)($order['payment_status'] ?? '') === 'refunded' && in_array((string)$order['status'], ['paid', 'processing'], true)) {
            OrderWorkflow::transition($orderId, 'cancelled', $note);
            $order = Orders::byId($orderId);
        }
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
     * Payment method of an order as the admin reads it (lists, order detail, operations): the method's current name in
     * the admin's language (merchantLabel), else the generic name of its type; the internal code (bank_transfer, cod,
     * stripe) only when there is nothing else.
     */
    public static function adminLabel(array $order): string {
        // R54 (review round 19, UX): the method's current name in the admin's language, as merchantLabel() gives it
        // (was: the snapshot label, written in the customer's language — "Kreditkarte" in a Slovak admin).
        $label = self::merchantLabel($order, I18n::locale());
        return $label !== '' ? $label : (string)($order['payment_method'] ?? '');
    }

    /**
     * R51 (low): the payment method in a merchant e-mail, in $locale. The snapshot label was written in the customer's
     * language ("Kreditkarte" of a German order told a Slovak shop in German): the method's current name in $locale
     * (the merchant's translation, the translated seeded name, else the name as entered) when the method still exists.
     */
    public static function merchantLabel(array $order, string $locale): string {
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true);
        $code = is_array($snapshot) ? trim((string)($snapshot['code'] ?? '')) : '';
        if ($code !== '' && StoreContext::hasCurrent() && self::hasMethodTables()) {
            $stmt = Db::get()->prepare('SELECT name FROM payment_methods WHERE store_id = ? AND code = ?');
            $stmt->execute([StoreContext::current()->providerId(), $code]);
            $name = trim((string)$stmt->fetchColumn());
            if ($name !== '') return MethodLabels::name('payment', $code, $name, $locale);
        }
        return self::orderLabel($order, $locale);
    }

    private static function orderProvider(array $order): string {
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? '{}'), true) ?: [];
        return (string)($snapshot['provider'] ?? $order['payment_method'] ?? '');
    }

    /** R27: the ledger key of a refund.failed of the provider refund $refundId that released nothing (it came before the refund's result). */
    private static function refundFailureRecorded(int $orderId, string $refundId): ?string {
        if ($refundId === '') return null;
        $stmt = Db::get()->prepare("SELECT idempotency_key, raw_response FROM payment_transactions WHERE order_id = :order AND store_id = :store
            AND provider_ref = :ref AND status = 'ignored' ORDER BY id");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':ref' => $refundId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $raw = json_decode((string)$row['raw_response'], true);
            if (is_array($raw) && ($raw['event_type'] ?? '') === 'refund.failed') return (string)$row['idempotency_key'];
        }
        return null;
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
