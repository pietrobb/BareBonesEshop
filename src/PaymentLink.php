<?php
/**
 * R10 UX3 "pay again": an unpaid online order gets a new payment from its order page, the customer account or the
 * e-mail the admin sends ("send payment link"). The e-mailed token is stored only as a hash (checkout_sessions,
 * provider 'pay_link') and lapses after LINK_TTL. Opening the link starts nothing (mail scanners follow links);
 * the gateway session is created on the shopper's confirming POST.
 */
final class PaymentLink {
    public const LINK_TTL = 7 * 86400;
    /** A hosted session younger than this may still be paid (Stripe: 24 h), so it is reused rather than a second one opened. */
    private const LIVE_SESSION = 23 * 3600;
    private const PROVIDER = 'pay_link';

    /** An order that is still pending, unpaid (or its payment failed) and paid through a gateway that can start a payment. */
    public static function payable(array $order): bool {
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true);
        $provider = is_array($snapshot) && !empty($snapshot['requires_online']) ? (string)($snapshot['provider'] ?? '') : '';
        return $provider !== '' && (string)($order['status'] ?? '') === 'pending'
            && in_array((string)($order['payment_status'] ?? 'pending'), ['pending', 'failed'], true)
            && PaymentService::gatewayCanStart($provider);
    }

    /** A new link for the e-mail; the raw token exists only in the returned URL. */
    public static function issue(array $order): string {
        $token = bin2hex(random_bytes(24));
        CheckoutSession::bind((int)$order['id'], hash('sha256', $token), self::PROVIDER);
        return self::baseUrl() . '/?page=pay&token=' . $token;
    }

    /** The active store's order the link was issued for, while the link is fresh. */
    public static function orderForToken(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $stmt = Db::get()->prepare("SELECT order_id FROM checkout_sessions
            WHERE provider = :provider AND session_id = :hash AND created_at >= :since LIMIT 1");
        $stmt->execute([':provider' => self::PROVIDER, ':hash' => hash('sha256', $token), ':since' => gmdate('Y-m-d H:i:s', time() - self::LINK_TTL)]);
        $orderId = $stmt->fetchColumn();
        return $orderId === false ? null : Orders::byId((int)$orderId);
    }

    /**
     * The gateway URL to pay $order at: a still-live session of a pending payment, otherwise a new session.
     * Null when the order cannot be paid, another request is starting its payment right now, or the gateway failed.
     */
    public static function start(array $order): ?string {
        if (!self::payable($order)) return null;
        $orderId = (int)$order['id'];
        $db = Db::get();
        $stmt = $db->prepare("SELECT raw_response, created_at FROM payment_transactions
            WHERE order_id = :order AND store_id = :store AND status = 'initiated' ORDER BY id DESC");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $latest = $sessions[0] ?? null;
        if ($latest !== null && (string)$order['payment_status'] === 'pending'
            && (string)$latest['created_at'] >= gmdate('Y-m-d H:i:s', time() - self::LIVE_SESSION)) {
            // Paying a second session while the first is still open would take the money twice.
            $url = (string)((json_decode((string)$latest['raw_response'], true) ?: [])['url'] ?? '');
            if ($url === '') return null;
            CheckoutSession::rememberPendingPayment($orderId, (string)$order['order_number'], $url, '');
            return $url;
        }
        // One new session per attempt: a concurrent request (double click, two tabs) finds the attempt claimed.
        $claim = $db->prepare(Db::insertIgnore() . " INTO checkout_sessions (order_id, provider, session_id, status) VALUES (:order, 'pay_restart', :attempt, 'completed')");
        $claim->execute([':order' => $orderId, ':attempt' => $orderId . ':' . count($sessions)]);
        if ($claim->rowCount() !== 1) return null;

        $snapshot = json_decode((string)$order['payment_method_snapshot'], true);
        $provider = (string)$snapshot['provider'];
        $financial = json_decode((string)($order['financial_snapshot'] ?? ''), true);
        $baseUrl = self::baseUrl();
        $confirmation = CheckoutSession::createConfirmation($orderId, CheckoutSession::browserSessionId());
        try {
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
            IncidentMonitor::report('payment.session_failed', 'payment', 'critical', ['order_id' => $orderId, 'exception' => $e]);
            return null;
        }
        $sessionId = (string)($session['session_id'] ?? '');
        if ($sessionId !== '') {
            CheckoutSession::bind($orderId, $sessionId, $provider, CheckoutSession::browserSessionId());
            $db->prepare("UPDATE orders SET payment_id = :pid WHERE id = :id AND store_id = :store")
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
            $payUrl = self::issue($order);
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

    private static function baseUrl(): string {
        return AbandonedCarts::storeBaseUrl(StoreContext::current()->id(), Security::appUrl(Security::config()));
    }
}
