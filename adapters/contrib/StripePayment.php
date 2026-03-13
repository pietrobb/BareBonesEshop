<?php
/**
 * BareBones Shop — Stripe Payment Adapter
 * Uses Stripe Checkout (hosted flow) via raw HTTP API.
 * Zero external dependencies — curl only.
 */
class StripePayment implements PaymentAdapter {
    private string $secretKey;
    private string $webhookSecret;

    public function __construct(array $cfg) {
        $this->secretKey = $cfg['stripe_secret_key'] ?? '';
        $this->webhookSecret = $cfg['stripe_webhook_secret'] ?? '';
    }

    /**
     * Create a Stripe Checkout session.
     * Returns ['url' => redirect_url, 'session_id' => id]
     */
    public function createSession(array $order): array {
        $lineItems = [];
        foreach ($order['items'] ?? [] as $item) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => $order['currency'] ?? 'eur',
                    'unit_amount' => (int)$item['unit_price'],
                    'product_data' => [
                        'name' => $item['name'],
                    ],
                ],
                'quantity' => (int)$item['quantity'],
            ];
        }

        // Add shipping as a line item if > 0
        if (!empty($order['shipping_cost']) && $order['shipping_cost'] > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => $order['currency'] ?? 'eur',
                    'unit_amount' => (int)$order['shipping_cost'],
                    'product_data' => ['name' => 'Shipping'],
                ],
                'quantity' => 1,
            ];
        }

        $params = [
            'mode' => 'payment',
            'success_url' => $order['success_url'] ?? '',
            'cancel_url' => $order['cancel_url'] ?? '',
            'client_reference_id' => $order['order_number'] ?? '',
            'customer_email' => $order['customer_email'] ?? '',
            'metadata' => ['order_id' => (string)($order['order_id'] ?? '')],
        ];

        // Build nested line_items params for form encoding
        foreach ($lineItems as $i => $li) {
            $params["line_items[{$i}][price_data][currency]"] = $li['price_data']['currency'];
            $params["line_items[{$i}][price_data][unit_amount]"] = $li['price_data']['unit_amount'];
            $params["line_items[{$i}][price_data][product_data][name]"] = $li['price_data']['product_data']['name'];
            $params["line_items[{$i}][quantity]"] = $li['quantity'];
        }

        $response = $this->apiCall('POST', '/v1/checkout/sessions', $params);

        return [
            'url' => $response['url'] ?? '',
            'session_id' => $response['id'] ?? '',
        ];
    }

    /**
     * Handle incoming Stripe webhook.
     * Returns ['event_type' => type, 'data' => data, 'event_id' => id]
     */
    public function handleWebhook(array $payload): array {
        return [
            'event_type' => $payload['type'] ?? '',
            'event_id' => $payload['id'] ?? '',
            'data' => $payload['data']['object'] ?? [],
        ];
    }

    /**
     * Refund a payment (full or partial).
     * Returns ['refund_id' => id, 'status' => status]
     */
    public function refund(string $paymentId, int $amount): array {
        $params = ['payment_intent' => $paymentId];
        if ($amount > 0) {
            $params['amount'] = $amount;
        }

        $response = $this->apiCall('POST', '/v1/refunds', $params);

        return [
            'refund_id' => $response['id'] ?? '',
            'status' => $response['status'] ?? 'failed',
        ];
    }

    /**
     * Get payment status for a payment intent.
     */
    public function getStatus(string $paymentId): string {
        $response = $this->apiCall('GET', '/v1/payment_intents/' . $paymentId);
        return $response['status'] ?? 'unknown';
    }

    /**
     * Verify Stripe webhook signature.
     */
    public function verifySignature(string $payload, string $sigHeader): bool {
        $parts = [];
        foreach (explode(',', $sigHeader) as $pair) {
            [$k, $v] = explode('=', $pair, 2);
            $parts[$k] = $v;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';
        if (!$timestamp || !$signature) return false;

        // Reject signatures older than 5 minutes
        if (abs(time() - (int)$timestamp) > 300) return false;

        $signed = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signed, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    /**
     * Raw Stripe API call using curl.
     */
    private function apiCall(string $method, string $endpoint, array $params = []): array {
        if (empty($this->secretKey)) {
            throw new \RuntimeException('Stripe secret key not configured');
        }

        $url = 'https://api.stripe.com' . $endpoint;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->secretKey,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } else {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("Stripe API error: {$error}");
        }

        $data = json_decode($body, true) ?? [];

        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("Stripe API: {$msg}");
        }

        return $data;
    }
}
