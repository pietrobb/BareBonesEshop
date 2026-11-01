<?php
/**
 * R10 UX3 "pay again": an unpaid online order gets a new payment from its order page, the customer account or the
 * e-mail the admin sends ("send payment link"). The e-mailed token is stored only as a hash (checkout_sessions,
 * provider 'pay_link') and lapses after LINK_TTL. Opening the link starts nothing (mail scanners follow links);
 * the gateway session is created on the shopper's confirming POST.
 */
final class PaymentLink {
    /** The e-mailed link works this long, and an online order with a live link is not expired meanwhile (Orders::expirePending). */
    public const LINK_TTL = 7 * 86400;
    /** A link that was e-mailed: it promises LINK_TTL to pay and holds the order that long. */
    private const PROVIDER = 'pay_link';
    /** A "Pay" button rendered on a page (status lookup, failed-payment confirmation): it promises nothing, holds nothing. */
    private const PAGE_PROVIDER = 'pay_link_page';
    /** Longer than any gateway call can take (PHP max_execution_time); a claim this old belongs to a request that died. */
    private const CLAIM_LEASE = 300;

    /** An order that is still pending, unpaid (or its payment failed) and paid through a gateway that can start a payment. */
    public static function payable(array $order): bool {
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true);
        $provider = is_array($snapshot) && !empty($snapshot['requires_online']) ? (string)($snapshot['provider'] ?? '') : '';
        return $provider !== '' && (string)($order['status'] ?? '') === 'pending'
            && in_array((string)($order['payment_status'] ?? 'pending'), ['pending', 'failed'], true)
            && PaymentService::gatewayCanStart($provider);
    }

    /**
     * A new link; the raw token exists only in the returned URL. Only a $mailed link defers the order's expiry: one
     * shown on a page is issued on every view, and counting it would let each lookup keep the order alive for another week.
     */
    public static function issue(array $order, bool $mailed = false): string {
        $token = bin2hex(random_bytes(24));
        CheckoutSession::bind((int)$order['id'], hash('sha256', $token), $mailed ? self::PROVIDER : self::PAGE_PROVIDER);
        return self::baseUrl() . '/?page=pay&token=' . $token;
    }

    /** The active store's order the link was issued for, while the link is fresh. */
    public static function orderForToken(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $stmt = Db::get()->prepare("SELECT order_id FROM checkout_sessions
            WHERE provider IN (:provider, :page) AND session_id = :hash AND created_at >= :since LIMIT 1");
        $stmt->execute([':provider' => self::PROVIDER, ':page' => self::PAGE_PROVIDER, ':hash' => hash('sha256', $token), ':since' => gmdate('Y-m-d H:i:s', time() - self::LINK_TTL)]);
        $orderId = $stmt->fetchColumn();
        return $orderId === false ? null : Orders::byId((int)$orderId);
    }

    /** A payment link e-mailed for the order still works: the order waits for it instead of expiring. */
    public static function hasLiveLink(int $orderId): bool {
        $stmt = Db::get()->prepare("SELECT 1 FROM checkout_sessions WHERE provider = :provider AND order_id = :order AND created_at >= :since LIMIT 1");
        $stmt->execute([':provider' => self::PROVIDER, ':order' => $orderId, ':since' => gmdate('Y-m-d H:i:s', time() - self::LINK_TTL)]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * The gateway URL to pay $order at: its previous session while the gateway still takes payment on it, otherwise a
     * new session. Null when the order cannot be paid, the previous session is paid or its state unknown, another
     * request is starting its payment right now, or the gateway failed.
     */
    public static function start(array $order): ?string {
        // Re-read: the caller's copy may predate an edit of the total.
        $order = Orders::byId((int)($order['id'] ?? 0));
        if ($order === null || !self::payable($order)) return null;
        $orderId = (int)$order['id'];
        $db = Db::get();
        $snapshot = json_decode((string)$order['payment_method_snapshot'], true);
        $provider = (string)$snapshot['provider'];
        $sessions = self::sessions($orderId);
        $latest = $sessions[0] ?? null;
        if ($latest !== null) {
            // Paying a second session while the first can still be paid takes the money twice. Whether it can is the
            // gateway's answer, not a guess from its age (Stripe sessions live 24 h, PayU/GoPay/Comgate their own time).
            $state = self::sessionState($order, $latest, $provider);
            if ($state === 'open') {
                $url = (string)((json_decode((string)$latest['raw_response'], true) ?: [])['url'] ?? '');
                if ($url === '') return null;
                CheckoutSession::rememberPendingPayment($orderId, (string)$order['order_number'], $url, '');
                return $url;
            }
            if ($state !== 'closed') return null;
        }
        // One new session per attempt: a concurrent request (double click, two tabs) finds the attempt claimed.
        // The claim is released when the attempt fails, and lapses after CLAIM_LEASE if its request died mid-way.
        $attempt = $orderId . ':' . count($sessions);
        if (!self::claim($db, $orderId, $attempt)) {
            $db->prepare("DELETE FROM checkout_sessions WHERE provider = 'pay_restart' AND session_id = :attempt AND created_at < :before")
                ->execute([':attempt' => $attempt, ':before' => gmdate('Y-m-d H:i:s', time() - self::CLAIM_LEASE)]);
            if (!self::claim($db, $orderId, $attempt)) return null;
        }

        $financial = json_decode((string)($order['financial_snapshot'] ?? ''), true);
        try {
            $baseUrl = self::baseUrl();
            $confirmation = CheckoutSession::createConfirmation($orderId, CheckoutSession::browserSessionId());
            $session = PaymentService::initiate([
                'order_id' => $orderId, 'order_number' => $order['order_number'], 'total' => (int)$order['total'],
                'customer_email' => (string)$order['customer_email'], 'customer_name' => (string)($order['customer_name'] ?? ''),
                'locale' => (string)($order['locale'] ?? ''), 'currency' => $order['currency'] ?? 'eur',
                'items' => Orders::items($orderId), 'shipping_cost' => (int)$order['shipping_cost'],
                'financial_snapshot_id' => is_array($financial) ? ($financial['fingerprint'] ?? null) : null,
                'success_url' => $baseUrl . '/?page=order-done&order=' . rawurlencode((string)$order['order_number']) . '&confirmation_token=' . rawurlencode($confirmation) . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $baseUrl . '/?page=checkout-cancel&order=' . rawurlencode((string)$order['order_number']),
                'notify_url' => ApiRoute::paymentWebhook($baseUrl, $provider),
            ], Adapters::payment($provider), $provider);
        } catch (Throwable $e) {
            // A gateway outage is the usual reason an order is unpaid: the next "Pay" must be able to try again.
            $db->prepare("DELETE FROM checkout_sessions WHERE provider = 'pay_restart' AND session_id = :attempt")->execute([':attempt' => $attempt]);
            IncidentMonitor::report('payment.session_failed', 'payment', 'critical', ['order_id' => $orderId, 'exception' => $e]);
            return null;
        }
        $sessionId = (string)($session['session_id'] ?? '');
        $now = Orders::byId($orderId);
        if ($now === null || (int)$now['total'] !== (int)$order['total'] || !self::payable($now)) {
            // Edited or paid while the gateway call ran: the session asks for an amount the order no longer has.
            if ($sessionId !== '') self::closeSession($provider, $sessionId);
            return null;
        }
        if ($sessionId !== '') {
            CheckoutSession::bind($orderId, $sessionId, $provider, CheckoutSession::browserSessionId());
            // Only an unpaid order follows its newest session; a paid one keeps the reference of the capture.
            $db->prepare("UPDATE orders SET payment_id = :pid WHERE id = :id AND store_id = :store AND status = 'pending' AND payment_status IN ('pending', 'failed')")
                ->execute([':pid' => $sessionId, ':id' => $orderId, ':store' => StoreContext::current()->id()]);
        }
        CheckoutSession::rememberPendingPayment($orderId, (string)$order['order_number'], (string)$session['url'], $confirmation);
        AuditLog::log('payment_link', $orderId, (string)$order['payment_status'], (string)$order['payment_status'], 'New payment session ' . $sessionId);
        return (string)$session['url'];
    }

    /** Where a "Pay" form sends the browser: the gateway, or the pay page when a redirect would leave the CSP form-action. */
    public static function redirectFor(array $order): ?string {
        $url = self::start($order);
        if ($url === null) return null;
        return Security::formActionAllows($url) ? $url : Template::storeUrl('/?page=pay');
    }

    /** E-mails the customer a new payment link (admin "send payment link"). */
    public static function send(array $order): array {
        if (!self::payable($order)) return ['status' => 'not_payable'];
        try {
            $payUrl = self::issue($order, true);
        } catch (RuntimeException) {
            return ['status' => 'no_app_url']; // the e-mailed link needs the shop's public address (config app_url)
        }
        $locale = (string)($order['locale'] ?? '');
        return MailService::send('order_payment_link', (string)$order['customer_email'], $locale, [
            'customer_name' => (string)($order['customer_name'] ?? ''), 'order_number' => (string)$order['order_number'],
            'order_total' => I18n::price((int)$order['total'], $order['currency'] ?? null),
            'pay_url' => $payUrl, 'valid_days' => (string)intdiv(self::LINK_TTL, 86400),
        ], (int)$order['id']);
    }

    /**
     * Back on the confirmation page after paying a session opened when an earlier payment had failed: the order is still
     * 'failed' until the gateway's notification arrives, so the gateway is asked. A payment it reports paid, or still
     * processing, is shown as pending (being confirmed), never as failed with a second "Pay".
     */
    public static function returnStatus(array $order): string {
        $status = (string)($order['payment_status'] ?? 'pending');
        if ($status !== 'failed' || !self::payable($order)) return $status;
        $latest = self::sessions((int)$order['id'])[0] ?? null;
        if ($latest === null || self::eventAfter((int)$order['id'], (int)$latest['id'])) return $status;
        $snapshot = json_decode((string)$order['payment_method_snapshot'], true);
        return self::sessionState($order, $latest, (string)$snapshot['provider']) === 'closed' ? 'failed' : 'pending';
    }

    /** @return list<array<string,mixed>> the order's gateway sessions, newest first */
    private static function sessions(int $orderId): array {
        $stmt = Db::get()->prepare("SELECT id, provider_ref, amount, currency, raw_response FROM payment_transactions
            WHERE order_id = :order AND store_id = :store AND status = 'initiated' ORDER BY id DESC");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The previous session of $order as the gateway reports it: 'open' (still payable for the order's amount: reuse it),
     * 'closed' (cannot be paid any more: a new one may open), otherwise 'paid' or 'unknown' (open nothing).
     * An open session for an amount an edit has changed is voided first, where the gateway can.
     */
    private static function sessionState(array $order, array $session, string $provider): string {
        $ref = trim((string)$session['provider_ref']);
        if ($ref === '') return 'closed';
        try {
            $status = strtolower(trim(Adapters::payment($provider)->getStatus($ref)));
        } catch (Throwable $e) {
            Logger::phpLog("Payment session state of order #{$order['id']} unknown: " . $e->getMessage());
            return 'unknown';
        }
        if (Orders::providerFinalUnpaid($status)) return 'closed';
        if (in_array($status, ['paid', 'succeeded', 'captured', 'completed'], true)) return 'paid';
        if ((int)$session['amount'] === (int)$order['total'] && strtolower((string)$session['currency']) === strtolower((string)($order['currency'] ?? 'eur'))) {
            return 'open';
        }
        return self::closeSession($provider, $ref) ? 'closed' : 'unknown';
    }

    /** Void a gateway session that must not be paid any more; false when the gateway cannot (it may then still be paid). */
    private static function closeSession(string $provider, string $sessionId): bool {
        try {
            $gateway = Adapters::payment($provider);
            if (!$gateway instanceof CancellablePaymentAdapter) return false;
            $gateway->cancelPayment($sessionId);
            return true;
        } catch (Throwable $e) {
            IncidentMonitor::report('payment.session_cancel_failed', 'payment', 'error', ['exception' => $e]);
            return false;
        }
    }

    /** A payment event (failure, cancellation, capture) logged after the session $transactionId opened. */
    private static function eventAfter(int $orderId, int $transactionId): bool {
        $stmt = Db::get()->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :order AND store_id = :store AND id > :id AND status <> 'initiated' LIMIT 1");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id(), ':id' => $transactionId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @phpstan-impure the second call after a lapsed claim was deleted can succeed */
    private static function claim(PDO $db, int $orderId, string $attempt): bool {
        $claim = $db->prepare(Db::insertIgnore() . " INTO checkout_sessions (order_id, provider, session_id, status) VALUES (:order, 'pay_restart', :attempt, 'completed')");
        $claim->execute([':order' => $orderId, ':attempt' => $attempt]);
        return $claim->rowCount() === 1;
    }

    private static function baseUrl(): string {
        return AbandonedCarts::storeBaseUrl(StoreContext::current()->id(), Security::appUrl(Security::config()));
    }
}
