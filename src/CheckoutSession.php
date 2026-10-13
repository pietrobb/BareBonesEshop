<?php
/** Durable provider checkout-session to order binding. */
class CheckoutSession {
    public static function bind(int $orderId, string $sessionId, string $provider, string $browserSessionId = ''): void {
        $sessionId = trim($sessionId);
        $provider = strtolower(trim($provider));
        if ($orderId <= 0 || $sessionId === '' || !preg_match('/^[a-z0-9_-]{2,40}$/', $provider)) {
            throw new InvalidArgumentException('Invalid checkout session binding');
        }
        $browserHash = $browserSessionId !== '' ? hash('sha256', $browserSessionId) : null;
        $stmt = Db::get()->prepare(Db::insertIgnore() . " INTO checkout_sessions
            (order_id, provider, session_id, browser_session_hash, status)
            VALUES (:order_id, :provider, :session_id, :browser_hash, 'pending')");
        $stmt->execute([
            ':order_id' => $orderId,
            ':provider' => $provider,
            ':session_id' => $sessionId,
            ':browser_hash' => $browserHash,
        ]);
    }

    public static function browserSessionId(): string {
        $id = session_id();
        if ($id !== '') return $id;
        if (empty($_SESSION['_checkout_browser_id'])) {
            $_SESSION['_checkout_browser_id'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }
        return (string)$_SESSION['_checkout_browser_id'];
    }

    public static function createConfirmation(int $orderId, string $browserSessionId): string {
        if ($browserSessionId === '') {
            throw new InvalidArgumentException('Browser session is required for checkout confirmation');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        self::bind($orderId, $token, 'confirmation', $browserSessionId);
        return $token;
    }

    public static function verifyConfirmation(int $orderId, string $token, string $browserSessionId): bool {
        return self::verifyOwnership($orderId, $token, $browserSessionId, 'confirmation');
    }

    public static function resolveOrderId(string $sessionId, string $provider = 'stripe'): ?int {
        $stmt = Db::get()->prepare("SELECT order_id FROM checkout_sessions
            WHERE provider = :provider AND session_id = :session_id LIMIT 1");
        $stmt->execute([':provider' => strtolower($provider), ':session_id' => $sessionId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public static function markCompleted(string $sessionId, string $provider = 'stripe'): void {
        Db::get()->prepare("UPDATE checkout_sessions SET status = 'completed', updated_at = " . Db::now() . "
            WHERE provider = :provider AND session_id = :session_id")
            ->execute([':provider' => strtolower($provider), ':session_id' => $sessionId]);
    }

    /** Hosted sessions (Stripe: 24 h) outlive this window, so the remembered URL is still payable. */
    private const PENDING_PAYMENT_TTL = 23 * 3600;

    /**
     * Remember the order this browser still has to pay. The cart is already empty, so a
     * resubmit, the gateway's back button or a blocked redirect must lead here instead of
     * to "your cart is empty".
     */
    public static function rememberPendingPayment(int $orderId, string $orderNumber, string $url, string $confirmationToken): void {
        if ($orderId <= 0 || $url === '') return;
        $_SESSION['_checkout_pending_payment'] = [
            'order_id' => $orderId, 'order_number' => $orderNumber, 'url' => $url,
            'confirmation_token' => $confirmationToken, 'at' => time(),
        ];
    }

    /** The remembered order while it is still unpaid and payable, otherwise null (and forgotten). */
    public static function pendingPayment(): ?array {
        $pending = $_SESSION['_checkout_pending_payment'] ?? null;
        if (!is_array($pending)) return null;
        $orderId = (int)($pending['order_id'] ?? 0);
        $fresh = time() - (int)($pending['at'] ?? 0) < self::PENDING_PAYMENT_TTL;
        $order = null;
        if ($fresh && $orderId > 0) {
            $stmt = Db::get()->prepare("SELECT status, payment_status FROM orders WHERE id = :id AND store_id = :store");
            $stmt->execute([':id' => $orderId, ':store' => StoreContext::current()->id()]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$order || !in_array((string)$order['payment_status'], ['pending', 'unpaid', 'failed', ''], true)
            || in_array((string)$order['status'], ['cancelled', 'refunded'], true)) {
            self::forgetPendingPayment();
            return null;
        }
        return $pending;
    }

    public static function forgetPendingPayment(?int $orderId = null): void {
        if ($orderId === null || (int)($_SESSION['_checkout_pending_payment']['order_id'] ?? 0) === $orderId) {
            unset($_SESSION['_checkout_pending_payment']);
        }
    }

    public static function verifyOwnership(int $orderId, string $sessionId, string $browserSessionId, string $provider = 'stripe'): bool {
        if ($orderId <= 0 || $sessionId === '' || $browserSessionId === '') return false;
        $stmt = Db::get()->prepare("SELECT browser_session_hash FROM checkout_sessions
            WHERE order_id = :order_id AND provider = :provider AND session_id = :session_id LIMIT 1");
        $stmt->execute([
            ':order_id' => $orderId,
            ':provider' => strtolower($provider),
            ':session_id' => $sessionId,
        ]);
        $hash = $stmt->fetchColumn();
        return is_string($hash) && $hash !== '' && hash_equals($hash, hash('sha256', $browserSessionId));
    }
}
