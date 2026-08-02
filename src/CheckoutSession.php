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
