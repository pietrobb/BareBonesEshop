<?php
/**
 * BareBones Shop — Cash On Delivery Adapter
 * No external payment processing needed.
 */
class CashOnDelivery implements PaymentAdapter {
    public function __construct(array $cfg) {}

    public function createSession(array $order): array {
        return [
            'url' => $order['success_url'] ?? '',
            'session_id' => 'cod_' . ($order['order_number'] ?? ''),
        ];
    }

    public function handleWebhook(array $payload): array {
        return ['event_type' => 'cod', 'event_id' => '', 'data' => []];
    }

    public function refund(string $paymentId, int $amount): array {
        return ['refund_id' => '', 'status' => 'manual'];
    }

    public function getStatus(string $paymentId): string {
        return 'pending_delivery';
    }

    /** Cash on delivery has no provider webhooks: every webhook request is rejected. */
    public function verifySignature(string $payload, string $sigHeader): bool {
        return false;
    }
}
