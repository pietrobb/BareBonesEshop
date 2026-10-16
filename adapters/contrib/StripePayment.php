<?php
/**
 * BareBones Shop — Stripe Payment Adapter
 * Uses Stripe Checkout (hosted flow) via raw HTTP API.
 * Zero external dependencies — curl only.
 */
class StripePayment implements IdempotentRefundAdapter, HostedCheckoutAdapter, CancellablePaymentAdapter {
    private string $secretKey;
    private string $webhookSecret;

    public function __construct(array $cfg) {
        $this->secretKey = $cfg['stripe_secret_key'] ?? '';
        $this->webhookSecret = $cfg['stripe_webhook_secret'] ?? '';
    }

    public function redirectOrigins(): array {
        return ['https://checkout.stripe.com'];
    }

    /**
     * Create a Stripe Checkout session.
     * Returns ['url' => redirect_url, 'session_id' => id]
     */
    public function createSession(array $order): array {
        $lineItems = $this->buildLineItems($order);

        $params = [
            'mode' => 'payment',
            'success_url' => $order['success_url'] ?? '',
            'cancel_url' => $order['cancel_url'] ?? '',
            'client_reference_id' => $order['order_number'] ?? '',
            'metadata' => [
                'order_id' => (string)($order['order_id'] ?? ''),
                'financial_snapshot_id' => (string)($order['financial_snapshot_id'] ?? ''),
            ],
        ];
        $email = (string)($order['customer_email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $params['customer_email'] = $email;

        foreach ($lineItems as $i => $line) {
            $params["line_items[{$i}][price_data][currency]"] = $line['price_data']['currency'];
            $params["line_items[{$i}][price_data][unit_amount]"] = $line['price_data']['unit_amount'];
            $params["line_items[{$i}][price_data][product_data][name]"] = $line['price_data']['product_data']['name'];
            $params["line_items[{$i}][quantity]"] = $line['quantity'];
        }

        $response = $this->apiCall('POST', '/v1/checkout/sessions', $params);

        return [
            'url' => $response['url'] ?? '',
            'session_id' => $response['id'] ?? '',
        ];
    }

    private function buildLineItems(array $order): array {
        $currency = strtolower((string)($order['currency'] ?? 'eur'));
        $expectedTotal = (int)($order['total'] ?? 0);
        if ($expectedTotal <= 0) {
            throw new RuntimeException('Stripe Checkout requires a positive order total');
        }

        $lineItems = [];
        $lineTotal = 0;
        foreach ($order['items'] ?? [] as $item) {
            $quantity = max(1, (int)($item['quantity'] ?? 1));
            $unitAmount = (int)($item['unit_price'] ?? 0);
            if ($unitAmount < 0) throw new RuntimeException('Stripe line item amount cannot be negative');
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $unitAmount,
                    'product_data' => ['name' => (string)($item['name'] ?? 'Item')],
                ],
                'quantity' => $quantity,
            ];
            $lineTotal += $unitAmount * $quantity;
        }

        $shippingCost = max(0, (int)($order['shipping_cost'] ?? 0));
        if ($shippingCost > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $shippingCost,
                    'product_data' => ['name' => 'Shipping'],
                ],
                'quantity' => 1,
            ];
            $lineTotal += $shippingCost;
        }

        if ($lineTotal === $expectedTotal && $lineItems) return $lineItems;

        return [[
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $expectedTotal,
                'product_data' => ['name' => 'Order ' . (string)($order['order_number'] ?? '')],
            ],
            'quantity' => 1,
        ]];
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
        return $this->performRefund($paymentId, $amount, null);
    }

    public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
        return $this->performRefund($paymentId, $amount, $idempotencyKey);
    }

    private function performRefund(string $paymentId, int $amount, ?string $idempotencyKey): array {
        $params = ['payment_intent' => $paymentId];
        if ($amount > 0) $params['amount'] = $amount;
        $headers = $idempotencyKey === null ? [] : ['Idempotency-Key: ' . hash('sha256', $idempotencyKey)];
        $response = $this->apiCall('POST', '/v1/refunds', $params, $headers);
        return [
            'refund_id' => $response['id'] ?? '',
            'status' => $response['status'] ?? 'failed',
        ];
    }

    /** Expire an open Checkout Session or cancel an uncaptured PaymentIntent. */
    public function cancelPayment(string $paymentId): void {
        if (str_starts_with($paymentId, 'cs_')) {
            $path = '/v1/checkout/sessions/' . rawurlencode($paymentId);
            if (($this->apiCall('GET', $path)['status'] ?? '') === 'open') $this->apiCall('POST', $path . '/expire');
            return;
        }
        $path = '/v1/payment_intents/' . rawurlencode($paymentId);
        $cancellable = ['requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture'];
        if (in_array($this->apiCall('GET', $path)['status'] ?? '', $cancellable, true)) $this->apiCall('POST', $path . '/cancel');
    }

    /**
     * Get payment status for a payment intent.
     */
    public function getStatus(string $paymentId): string {
        $endpoint = str_starts_with($paymentId, 'cs_')
            ? '/v1/checkout/sessions/' . rawurlencode($paymentId)
            : '/v1/payment_intents/' . rawurlencode($paymentId);
        $response = $this->apiCall('GET', $endpoint);
        if (($response['payment_status'] ?? '') === 'paid') return 'paid';
        return $response['status'] ?? 'unknown';
    }

    /**
     * Verify Stripe webhook signature.
     */
    public function verifySignature(string $payload, string $sigHeader): bool {
        if (strlen($this->webhookSecret) < 16 || trim($this->webhookSecret) !== $this->webhookSecret) return false;

        $timestamp = '';
        $signatures = [];
        foreach (explode(',', $sigHeader) as $pair) {
            [$key, $value] = explode('=', trim($pair), 2) + [1 => ''];
            if ($key === 't') {
                if ($timestamp !== '' && $timestamp !== $value) return false;
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }
        if ($timestamp === '' || !ctype_digit($timestamp) || !$signatures) return false;
        if (abs(time() - (int)$timestamp) > 300) return false;

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) return true;
        }
        return false;
    }

    /**
     * Raw Stripe API call using curl.
     */
    private function apiCall(string $method, string $endpoint, array $params = [], array $extraHeaders = []): array {
        if (empty($this->secretKey)) {
            throw new \RuntimeException('Stripe secret key not configured');
        }

        $url = 'https://api.stripe.com' . $endpoint;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
            'Authorization: Bearer ' . $this->secretKey,
        ], $extraHeaders));
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
