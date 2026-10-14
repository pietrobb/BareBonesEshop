<?php
/**
 * BareBones Shop — PayU Payment Adapter
 * PayU REST API v2.1 (Poland/CEE region)
 * OAuth2 client_credentials flow, MD5 second-key notification verification.
 * Zero external dependencies — curl only.
 *
 * Required env vars:
 *   PAY_PAYU_CLIENT_ID       — OAuth2 client_id (merchant POS ID)
 *   PAY_PAYU_CLIENT_SECRET   — OAuth2 client_secret
 *   PAY_PAYU_SECOND_KEY      — POS second key for notification signatures
 *   PAY_PAYU_POS_ID          — POS identifier (same as client_id usually)
 *   PAY_PAYU_ENV             — 'production' | 'sandbox' (default: sandbox)
 */
class PayUPayment implements IdempotentRefundAdapter, HostedCheckoutAdapter {
    private string $clientId;
    private string $clientSecret;
    private string $secondKey;
    private string $posId;
    private string $baseUrl;
    private ?string $accessToken = null;
    private int $tokenExpiry = 0;

    public function __construct(array $cfg) {
        $this->clientId     = getenv('PAY_PAYU_CLIENT_ID')     ?: ($cfg['payu_client_id']     ?? '');
        $this->clientSecret = getenv('PAY_PAYU_CLIENT_SECRET') ?: ($cfg['payu_client_secret'] ?? '');
        $this->secondKey    = getenv('PAY_PAYU_SECOND_KEY')    ?: ($cfg['payu_second_key']    ?? '');
        $this->posId        = getenv('PAY_PAYU_POS_ID')        ?: ($cfg['payu_pos_id']        ?? '');
        $env                = getenv('PAY_PAYU_ENV')            ?: ($cfg['payu_env']            ?? 'sandbox');
        $this->baseUrl      = $env === 'production'
            ? 'https://secure.payu.com'
            : 'https://secure.snd.payu.com';
    }

    /** redirectUri is served by the API host; the sandbox pay page lives on merch-prod.snd. */
    public function redirectOrigins(): array {
        return $this->baseUrl === 'https://secure.payu.com'
            ? ['https://secure.payu.com']
            : ['https://secure.snd.payu.com', 'https://merch-prod.snd.payu.com'];
    }

    /**
     * Create PayU order, returns redirect URL.
     * Returns ['url' => redirect_url, 'session_id' => extOrderId]
     */
    public function createSession(array $order): array {
        $token = $this->getAccessToken();

        $extOrderId = $order['order_number'] ?? ('BBE-' . time());
        $notifyUrl  = $order['notify_url']   ?? '';
        $continueUrl = $order['success_url'] ?? '';

        $products = [];
        foreach ($order['items'] ?? [] as $item) {
            $products[] = [
                'name'      => $item['name'],
                'unitPrice' => (string)(int)$item['unit_price'],
                'quantity'  => (string)(int)$item['quantity'],
            ];
        }
        if (!empty($order['shipping_cost']) && $order['shipping_cost'] > 0) {
            $products[] = [
                'name'      => 'Shipping',
                'unitPrice' => (string)(int)$order['shipping_cost'],
                'quantity'  => '1',
            ];
        }

        $body = [
            'notifyUrl'       => $notifyUrl,
            'continueUrl'     => $continueUrl,
            'customerIp'      => $order['ip_address'] ?? '127.0.0.1',
            'merchantPosId'   => $this->posId,
            'description'     => 'Objednávka ' . $extOrderId,
            'currencyCode'    => strtoupper($order['currency'] ?? 'EUR'),
            'totalAmount'     => (string)(int)$order['total'],
            'extOrderId'      => $extOrderId,
            'buyer'           => [
                'email'     => $order['customer_email'] ?? '',
                'firstName' => explode(' ', $order['customer_name'] ?? '')[0] ?? '',
                'lastName'  => implode(' ', array_slice(explode(' ', $order['customer_name'] ?? ''), 1)) ?: '-',
                'language'  => 'sk',
            ],
            'products' => $products,
        ];

        $response = $this->apiCall('POST', '/api/v2_1/orders', $body, $token, allowRedirect: false);

        // PayU returns 302 redirect — location header contains the payment URL
        $redirectUrl = $response['redirectUri'] ?? ($response['orderId'] ?? '');

        return [
            'url'        => $redirectUrl,
            'session_id' => $response['orderId'] ?? $extOrderId,
        ];
    }

    /**
     * Parse PayU notification payload.
     * Returns ['event_type' => type, 'event_id' => id, 'data' => data]
     */
    public function handleWebhook(array $payload): array {
        if (isset($payload['refund']) && is_array($payload['refund'])) return $this->refundNotification($payload);
        $orderData = $payload['order'] ?? [];
        $status    = strtolower($orderData['status'] ?? '');

        // Map PayU statuses to our internal types
        $eventType = match($status) {
            'completed'  => 'payment.paid',
            'canceled'   => 'payment.failed',
            'rejected'   => 'payment.failed',
            default      => 'payment.unknown',
        };

        return [
            'event_type' => $eventType,
            'event_id'   => !empty($orderData['orderId']) && $status !== '' ? $orderData['orderId'] . ':' . $status : '',
            'data'       => array_merge($orderData, [
                'amount'   => (int)($orderData['totalAmount'] ?? 0),
                'currency' => strtolower((string)($orderData['currencyCode'] ?? '')),
            ]),
        ];
    }

    /**
     * Official PayU refund notification: {orderId, extOrderId, refund: {refundId, amount, currencyCode, status}}.
     * FINALIZED moves money; CANCELED does not.
     */
    private function refundNotification(array $payload): array {
        $refund = $payload['refund'];
        $status = strtolower((string)($refund['status'] ?? ''));
        $refundId = (string)($refund['refundId'] ?? '');
        return [
            'event_type' => $status === 'finalized' ? 'refund.succeeded' : 'refund.' . ($status ?: 'unknown'),
            'event_id'   => !empty($payload['orderId']) && $refundId !== '' && $status !== '' ? $payload['orderId'] . ':refund:' . $refundId . ':' . $status : '',
            'data'       => [
                'orderId'    => (string)($payload['orderId'] ?? ''),
                'extOrderId' => (string)($payload['extOrderId'] ?? ''),
                'refund_id'  => $refundId,
                'amount'     => (int)($refund['amount'] ?? 0),
                'currency'   => strtolower((string)($refund['currencyCode'] ?? '')),
            ],
        ];
    }

    /**
     * Refund via PayU refund API.
     * Returns ['refund_id' => id, 'status' => status]
     */
    public function refund(string $paymentId, int $amount): array {
        return $this->performRefund($paymentId, $amount, null);
    }

    public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array {
        return $this->performRefund($paymentId, $amount, $idempotencyKey);
    }

    private function performRefund(string $paymentId, int $amount, ?string $idempotencyKey): array {
        $token = $this->getAccessToken();
        $body = ['refund' => ['description' => 'Refund']];
        if ($amount > 0) $body['refund']['amount'] = (string)$amount;
        if ($idempotencyKey !== null) $body['refund']['extRefundId'] = substr(hash('sha256', $idempotencyKey), 0, 64);
        $response = $this->apiCall('POST', "/api/v2_1/orders/{$paymentId}/refunds", $body, $token);
        return [
            'refund_id' => $response['refund']['refundId'] ?? '',
            'status'    => strtolower($response['refund']['status'] ?? 'failed'),
        ];
    }

    /**
     * Get current PayU order status.
     */
    public function getStatus(string $paymentId): string {
        $token    = $this->getAccessToken();
        $response = $this->apiCall('GET', "/api/v2_1/orders/{$paymentId}", [], $token);
        $orders   = $response['orders'] ?? [];
        return strtolower($orders[0]['status'] ?? 'unknown');
    }

    /**
     * Verify PayU notification signature.
     * PayU sends OpenPayU-Signature header:
     *   sender=checkout;signature=<MD5>;algorithm=MD5;content=DOCUMENT
     */
    public function verifySignature(string $payload, string $sigHeader): bool {
        if ($this->secondKey === '' || trim($this->secondKey) !== $this->secondKey) return false;

        // Parse signature header
        $parts = [];
        foreach (explode(';', $sigHeader) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $k = trim($k);
                if (array_key_exists($k, $parts)) return false;
                $parts[$k] = trim($v);
            }
        }

        $receivedSig = $parts['signature'] ?? '';
        if (($parts['algorithm'] ?? '') !== 'MD5' || !preg_match('/^[a-fA-F0-9]{32}$/D', $receivedSig)) return false;
        if (isset($parts['content']) && $parts['content'] !== 'DOCUMENT') return false;

        // PayU Europe signs the exact raw notification body concatenated with the POS second key.
        $expected = md5($payload . $this->secondKey);

        return hash_equals($expected, strtolower($receivedSig));
    }

    /**
     * Obtain OAuth2 access token (cached until expiry).
     */
    private function getAccessToken(): string {
        if ($this->accessToken && time() < $this->tokenExpiry - 30) {
            return $this->accessToken;
        }

        $url  = $this->baseUrl . '/pl/standard/user/oauth/authorize';
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err || $httpCode >= 400) {
            throw new \RuntimeException("PayU OAuth error: " . ($err ?: "HTTP {$httpCode}"));
        }

        $data = json_decode($resp, true) ?? [];
        if (empty($data['access_token'])) {
            throw new \RuntimeException("PayU OAuth: no access_token in response");
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = time() + (int)($data['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    /**
     * Raw PayU API call using curl.
     */
    private function apiCall(string $method, string $endpoint, array $body, string $token, bool $allowRedirect = true): array {
        if (empty($this->clientId)) {
            throw new \RuntimeException('PayU credentials not configured');
        }

        $url = $this->baseUrl . $endpoint;
        $ch  = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            // PayU redirects on order create — follow to get final response
            CURLOPT_FOLLOWLOCATION => $allowRedirect,
            CURLOPT_MAXREDIRS      => 2,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("PayU API error: {$err}");
        }

        $data = json_decode($resp, true) ?? [];

        // 2xx or 302 (redirect captured) are OK
        if ($httpCode >= 400) {
            $msg = $data['status']['statusDesc'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("PayU API: {$msg}");
        }

        return $data;
    }
}
