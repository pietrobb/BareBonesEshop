<?php
/**
 * BareBones Shop — Mock Payment Adapter
 * For unit tests and local dev — no network required.
 * Behaviour controlled by constructor $cfg['mock_result']:
 *   'success' (default) — createSession returns success URL, webhooks simulate paid
 *   'fail'              — createSession succeeds but webhook simulates failed payment
 *   'error'             — createSession throws RuntimeException
 */
class MockPayment implements PaymentAdapter {
    private string $result;
    private string $secret;
    /** @var array<string, string> session_id → order_number */
    private static array $sessions = [];

    public function __construct(array $cfg) {
        $this->result = $cfg['mock_result'] ?? 'success';
        $this->secret = (string)($cfg['mock_webhook_secret'] ?? 'mock_sig');
    }

    public function createSession(array $order): array {
        if ($this->result === 'error') {
            throw new \RuntimeException('MockPayment: simulated gateway error');
        }

        $sessionId = 'mock_' . ($order['order_number'] ?? uniqid());
        self::$sessions[$sessionId] = $order['order_number'] ?? '';

        return [
            'url'        => ($order['success_url'] ?? '/') . '?mock_session=' . $sessionId,
            'session_id' => $sessionId,
        ];
    }

    public function handleWebhook(array $payload): array {
        $sessionId = $payload['session_id'] ?? '';
        $eventType = $this->result === 'fail' ? 'payment.failed' : 'payment.paid';

        return [
            'event_type' => $eventType,
            'event_id'   => $payload['event_id'] ?? ('mock_evt_' . uniqid()),
            'data'       => [
                'session_id'  => $sessionId,
                'order_id'    => $payload['order_id'] ?? null,
                'amount'      => $payload['amount'] ?? 0,
                'currency'    => $payload['currency'] ?? 'eur',
                'provider_ref' => $sessionId,
            ],
        ];
    }

    public function refund(string $paymentId, int $amount): array {
        return [
            'refund_id' => 'mock_ref_' . uniqid(),
            'status'    => 'succeeded',
        ];
    }

    public function getStatus(string $paymentId): string {
        return $this->result === 'fail' ? 'failed' : 'succeeded';
    }

    public function verifySignature(string $payload, string $sigHeader): bool {
        // Constant-time compare against configured secret ($cfg['mock_webhook_secret'],
        // default 'mock_sig' for unit tests). Never accepts an empty header.
        return $sigHeader !== '' && hash_equals($this->secret, $sigHeader);
    }

    /** Simulate a paid webhook payload for a given order */
    public static function paidPayload(int $orderId, int $amount, string $sessionId): array {
        return [
            'event_id'   => 'mock_evt_paid_' . $orderId,
            'session_id' => $sessionId,
            'order_id'   => $orderId,
            'amount'     => $amount,
            'currency'   => 'eur',
            'provider_ref' => $sessionId,
        ];
    }

    /** Simulate a refund webhook payload */
    public static function refundPayload(int $orderId, string $sessionId): array {
        return [
            'event_id'   => 'mock_evt_refund_' . $orderId,
            'session_id' => $sessionId,
            'order_id'   => $orderId,
            'provider_ref' => $sessionId,
        ];
    }
}
