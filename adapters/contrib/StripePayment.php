<?php
/**
 * BareBones Shop — Stripe Payment Adapter
 * Uses Stripe Checkout (hosted flow) via raw HTTP API.
 * Zero external dependencies — curl only.
 */
class StripePayment implements PaymentAdapter {
    private string $secretKey;
    private string $webhookSecret;
    private array $checkoutConfig;
    private string $metadataApp;

    public function __construct(array $cfg) {
        $this->secretKey = $cfg['stripe_secret_key'] ?? '';
        $this->webhookSecret = $cfg['stripe_webhook_secret'] ?? '';
        $this->checkoutConfig = is_array($cfg['stripe_checkout'] ?? null) ? $cfg['stripe_checkout'] : [];
        $this->metadataApp = trim((string)($this->checkoutConfig['metadata_app'] ?? ''));
    }

    /**
     * Create a Stripe Checkout session.
     * Returns ['url' => redirect_url, 'session_id' => id]
     */
    public function createSession(array $order): array {
        $lineItems = $this->buildLineItems($order);

        $checkoutMode = $this->managedPaymentsEligible($order, $lineItems)
            ? 'managed_payments'
            : 'standard';
        $metadata = [
            'order_id' => (string)($order['order_id'] ?? ''),
            'order_mode' => $checkoutMode,
        ];
        if ($this->metadataApp !== '') $metadata['app'] = $this->metadataApp;

        $params = [
            'mode' => 'payment',
            'success_url' => $order['success_url'] ?? '',
            'cancel_url' => $order['cancel_url'] ?? '',
            'client_reference_id' => $order['order_number'] ?? '',
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
        ];
        if ($checkoutMode === 'managed_payments') {
            $params['managed_payments'] = ['enabled' => 'true'];
            $params['billing_address_collection'] = 'required';
        }
        $email = (string)($order['customer_email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $params['customer_email'] = $email;
        $managedConfig = is_array($this->checkoutConfig['managed_payments'] ?? null)
            ? $this->checkoutConfig['managed_payments']
            : [];
        $taxBehavior = (string)($managedConfig['tax_behavior'] ?? '');
        if (!in_array($taxBehavior, ['inclusive', 'exclusive'], true)) $taxBehavior = '';

        foreach ($lineItems as $i => $line) {
            $params["line_items[{$i}][price_data][currency]"] = $line['price_data']['currency'];
            $params["line_items[{$i}][price_data][unit_amount]"] = $line['price_data']['unit_amount'];
            $params["line_items[{$i}][price_data][product_data][name]"] = $line['price_data']['product_data']['name'];
            if (!empty($line['price_data']['product_data']['tax_code'])) {
                $params["line_items[{$i}][price_data][product_data][tax_code]"] = $line['price_data']['product_data']['tax_code'];
            }
            if ($checkoutMode === 'managed_payments' && $taxBehavior !== '') {
                $params["line_items[{$i}][price_data][tax_behavior]"] = $taxBehavior;
            }
            $params["line_items[{$i}][quantity]"] = $line['quantity'];
        }

        $response = $this->apiCall('POST', '/v1/checkout/sessions', $params);

        return [
            'url' => $response['url'] ?? '',
            'session_id' => $response['id'] ?? '',
            'checkout_mode' => $checkoutMode,
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
            $productData = ['name' => (string)($item['name'] ?? 'Item')];
            $taxCode = $this->resolveTaxCode($item);
            if ($taxCode !== '') $productData['tax_code'] = $taxCode;
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $unitAmount,
                    'product_data' => $productData,
                ],
                'quantity' => $quantity,
            ];
            $lineTotal += $unitAmount * $quantity;
        }

        $shippingCost = max(0, (int)($order['shipping_cost'] ?? 0));
        if ($shippingCost > 0) {
            $shippingData = ['name' => 'Shipping'];
            $shippingTaxCode = trim((string)($this->taxCodeConfig()['shipping'] ?? ''));
            if ($shippingTaxCode !== '') $shippingData['tax_code'] = $shippingTaxCode;
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $shippingCost,
                    'product_data' => $shippingData,
                ],
                'quantity' => 1,
            ];
            $lineTotal += $shippingCost;
        }

        if ($lineTotal === $expectedTotal && $lineItems) return $lineItems;

        $productData = ['name' => 'Order ' . (string)($order['order_number'] ?? '')];
        $defaultTaxCode = trim((string)($this->taxCodeConfig()['default'] ?? ''));
        $lineTaxCodes = array_values(array_unique(array_filter(array_map(
            static fn(array $line): string => trim((string)($line['price_data']['product_data']['tax_code'] ?? '')),
            $lineItems
        ))));
        $fallbackTaxCode = $defaultTaxCode !== '' ? $defaultTaxCode : (count($lineTaxCodes) === 1 ? $lineTaxCodes[0] : '');
        if ($fallbackTaxCode !== '') $productData['tax_code'] = $fallbackTaxCode;

        return [[
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $expectedTotal,
                'product_data' => $productData,
            ],
            'quantity' => 1,
        ]];
    }

    private function managedPaymentsEligible(array $order, array $lineItems): bool {
        $config = is_array($this->checkoutConfig['managed_payments'] ?? null)
            ? $this->checkoutConfig['managed_payments']
            : [];
        if (empty($config['enabled']) || ($order['is_all_digital'] ?? null) !== true) return false;

        $country = strtoupper(trim((string)($order['billing_country'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $country)) return false;
        $excluded = array_values(array_filter(array_map(
            static fn($value): string => strtoupper(trim((string)$value)),
            is_array($config['excluded_billing_countries'] ?? null) ? $config['excluded_billing_countries'] : []
        )));
        if (in_array($country, $excluded, true)) return false;

        $eligibleTypes = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            is_array($config['eligible_product_types'] ?? null) ? $config['eligible_product_types'] : []
        )));
        foreach ($order['items'] ?? [] as $item) {
            $productType = strtolower(trim((string)($item['product_type'] ?? '')));
            if ($eligibleTypes && !in_array($productType, $eligibleTypes, true)) return false;
        }

        foreach ($lineItems as $line) {
            if (empty($line['price_data']['product_data']['tax_code'])) return false;
        }
        return !empty($lineItems);
    }

    private function resolveTaxCode(array $item): string {
        foreach (['stripe_tax_code', 'tax_code'] as $key) {
            $value = trim((string)($item[$key] ?? ''));
            if ($value !== '') return $value;
        }

        $config = $this->taxCodeConfig();
        $sku = (string)($item['sku'] ?? '');
        $bySku = is_array($config['by_sku'] ?? null) ? $config['by_sku'] : [];
        if ($sku !== '' && !empty($bySku[$sku])) return trim((string)$bySku[$sku]);

        $productType = strtolower(trim((string)($item['product_type'] ?? '')));
        $byType = is_array($config['by_product_type'] ?? null) ? $config['by_product_type'] : [];
        if ($productType !== '' && !empty($byType[$productType])) return trim((string)$byType[$productType]);

        return trim((string)($config['default'] ?? ''));
    }

    private function taxCodeConfig(): array {
        return is_array($this->checkoutConfig['tax_codes'] ?? null)
            ? $this->checkoutConfig['tax_codes']
            : [];
    }

    /**
     * Handle incoming Stripe webhook.
     * Returns ['event_type' => type, 'data' => data, 'event_id' => id]
     */
    public function handleWebhook(array $payload): array {
        $data = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
        $eventApp = trim((string)($data['metadata']['app'] ?? ''));
        $ignored = $this->metadataApp !== '' && !hash_equals($this->metadataApp, $eventApp);

        return [
            'event_type' => $payload['type'] ?? '',
            'event_id' => $payload['id'] ?? '',
            'data' => $data,
            'ignored' => $ignored,
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
    protected function apiCall(string $method, string $endpoint, array $params = []): array {
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
