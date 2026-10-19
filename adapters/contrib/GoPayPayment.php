<?php
/**
 * BareBones Shop — GoPay Payment Adapter (GoPay REST API, doc.gopay.com)
 * OAuth2 client_credentials, hosted gateway redirect (gw_url), payment inquiry, refunds.
 * Zero external dependencies — curl only.
 *
 * GoPay notifications are an unsigned HTTP GET carrying only ?id=<payment id>. They are never
 * trusted: every notification is verified by an authenticated payment inquiry
 * (PullVerifiedNotificationAdapter) and the event uses the state, amount and currency GoPay
 * returns for a payment of this merchant's GoID.
 *
 * Refunds started from the shop go through the refund worker. GoPay's refund call has no
 * idempotency key, so this adapter does not offer IdempotentRefundAdapter (credit-note refunds
 * that require one fail closed). The refund history carries no refund id to
 * deduplicate against, so a REFUNDED notification only completes the shop's own refund in
 * flight when that refund is the whole remaining balance; PARTIALLY_REFUNDED (no refunded
 * amount) and any other refund report are logged and move no money in the shop.
 *
 * Config (env var wins over config.php):
 *   PAY_GOPAY_GOID           / gopay_goid           — merchant GoID (payment target)
 *   PAY_GOPAY_CLIENT_ID      / gopay_client_id      — OAuth2 ClientID
 *   PAY_GOPAY_CLIENT_SECRET  / gopay_client_secret  — OAuth2 ClientSecret
 *   PAY_GOPAY_ENV            / gopay_env            — 'production' | 'sandbox' (default sandbox)
 */
class GoPayPayment implements HostedCheckoutAdapter, PullVerifiedNotificationAdapter, PaymentStatusEventAdapter, SignedWebhookPaymentAdapter {
    private const STATES = ['CREATED', 'PAYMENT_METHOD_CHOSEN', 'TIMEOUTED', 'PAID', 'CANCELED', 'AUTHORIZED', 'PARTIALLY_REFUNDED', 'REFUNDED'];

    private string $goid;
    private string $clientId;
    private string $clientSecret;
    private bool $production;
    /** @var callable(string,string,list<string>,?string):array{status:int,body:string} */
    private $transport;
    private ?string $accessToken = null;
    private int $tokenExpiry = 0;

    public function __construct(array $cfg, ?callable $transport = null) {
        $this->goid = trim((string)(getenv('PAY_GOPAY_GOID') ?: ($cfg['gopay_goid'] ?? '')));
        $this->clientId = (string)(getenv('PAY_GOPAY_CLIENT_ID') ?: ($cfg['gopay_client_id'] ?? ''));
        $this->clientSecret = (string)(getenv('PAY_GOPAY_CLIENT_SECRET') ?: ($cfg['gopay_client_secret'] ?? ''));
        $this->production = (getenv('PAY_GOPAY_ENV') ?: ($cfg['gopay_env'] ?? 'sandbox')) === 'production';
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    public function redirectOrigins(): array {
        return [$this->production ? 'https://gate.gopay.cz' : 'https://gw.sandbox.gopay.com'];
    }

    private function apiBase(): string {
        return ($this->production ? 'https://gate.gopay.cz' : 'https://gw.sandbox.gopay.com') . '/api';
    }

    /** Returns ['url' => gw_url, 'session_id' => GoPay payment id]. */
    public function createSession(array $order): array {
        $notifyUrl = (string)($order['notify_url'] ?? '');
        $returnUrl = str_replace('{CHECKOUT_SESSION_ID}', '', (string)($order['success_url'] ?? ''));
        if (!preg_match('#^https?://#i', $notifyUrl) || !preg_match('#^https?://#i', $returnUrl)) {
            throw new \RuntimeException('GoPay needs absolute return and notification URLs');
        }
        // Items are optional in GoPay; they are not sent because discounts and fees would make their
        // sum differ from the charged total. The order number identifies the order.
        $body = [
            'amount' => (int)($order['total'] ?? 0),
            'currency' => strtoupper((string)($order['currency'] ?? 'EUR')),
            'order_number' => (string)($order['order_number'] ?? ''),
            'payer' => ['contact' => ['email' => (string)($order['customer_email'] ?? '')]],
            'target' => ['type' => 'ACCOUNT', 'goid' => $this->goidValue()],
            'callback' => ['return_url' => $returnUrl, 'notification_url' => $notifyUrl],
        ];
        if ($body['amount'] <= 0 || $body['order_number'] === '') throw new \RuntimeException('GoPay payment needs an amount and an order number');

        $payment = $this->call('POST', '/payments/payment', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'application/json');
        $gwUrl = (string)($payment['gw_url'] ?? '');
        $origin = self::origin($gwUrl);
        if (empty($payment['id']) || !in_array($origin, $this->redirectOrigins(), true)) {
            throw new \RuntimeException('GoPay returned no payment id or a gateway URL outside the declared origin');
        }
        return ['url' => $gwUrl, 'session_id' => (string)$payment['id']];
    }

    /** Signed-webhook entry point is not used: GoPay notifications are pull-verified. */
    public function handleWebhook(array $payload): array {
        return $this->verifiedEvent($payload);
    }

    public function verifySignature(string $payload, string $sigHeader): bool {
        return false;
    }

    public function verifiedEvent(array $notification): array {
        $id = (string)($notification['id'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
            throw new PaymentNotificationUnverified('GoPay notification carries no valid payment id');
        }
        try {
            $payment = $this->call('GET', '/payments/payment/' . $id);
        } catch (\RuntimeException $e) {
            // GoPay refused the inquiry (unknown id, another merchant): unverifiable, not retryable.
            if ($e->getCode() >= 400 && $e->getCode() < 500) throw new PaymentNotificationUnverified($e->getMessage(), 0, $e);
            throw $e;
        }
        $state = (string)($payment['state'] ?? '');
        if ((string)($payment['id'] ?? '') !== $id || !in_array($state, self::STATES, true)) {
            throw new PaymentNotificationUnverified('GoPay inquiry does not describe the notified payment');
        }
        $target = $payment['target'] ?? [];
        if ((string)($target['goid'] ?? '') !== $this->goid || ($target['type'] ?? 'ACCOUNT') !== 'ACCOUNT') {
            throw new PaymentNotificationUnverified('GoPay payment belongs to another merchant account');
        }
        $eventType = match ($state) {
            'PAID' => 'payment.paid',
            'CANCELED', 'TIMEOUTED' => 'payment.failed',
            'REFUNDED', 'PARTIALLY_REFUNDED' => 'refund.reported',
            default => 'payment.' . strtolower($state),
        };
        return [
            'event_type' => $eventType,
            'event_id' => $id . ':' . $state,
            'data' => [
                'order_number' => (string)($payment['order_number'] ?? ''),
                'payment_id' => $id,
                'provider_ref' => $id,
                'state' => $state,
                'amount' => (int)($payment['amount'] ?? 0),
                'currency' => strtolower((string)($payment['currency'] ?? '')),
            ],
        ];
    }

    /** Returns ['refund_id' => '', 'status' => succeeded|pending|failed]. */
    public function refund(string $paymentId, int $amount): array {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $paymentId) || $amount <= 0) {
            throw new \InvalidArgumentException('GoPay refund needs a payment id and a positive amount');
        }
        $response = $this->call('POST', '/payments/payment/' . $paymentId . '/refund', http_build_query(['amount' => $amount]), 'application/x-www-form-urlencoded');
        return [
            'refund_id' => '',
            'status' => match ((string)($response['result'] ?? '')) {
                'FINISHED' => 'succeeded',
                'ACCEPTED' => 'pending',
                default => 'failed',
            },
        ];
    }

    public function statusEvent(string $paymentId): array {
        return $this->verifiedEvent(['id' => $paymentId]);
    }

    public function getStatus(string $paymentId): string {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $paymentId)) return 'unknown';
        return strtolower((string)($this->call('GET', '/payments/payment/' . $paymentId)['state'] ?? 'unknown'));
    }

    private function goidValue(): int|string {
        return ctype_digit($this->goid) && strlen($this->goid) < 19 ? (int)$this->goid : $this->goid;
    }

    private function accessToken(): string {
        if ($this->accessToken !== null && time() < $this->tokenExpiry - 30) return $this->accessToken;
        try {
            $response = $this->send('POST', $this->apiBase() . '/oauth2/token', [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ], http_build_query(['scope' => 'payment-all', 'grant_type' => 'client_credentials']));
        } catch (\RuntimeException $e) {
            // Rejected credentials are the shop's configuration problem, never a forged notification.
            throw new \RuntimeException('GoPay OAuth failed: ' . $e->getMessage(), 0, $e);
        }
        if (empty($response['access_token']) || !is_string($response['access_token'])) {
            throw new \RuntimeException('GoPay OAuth: no access_token in response');
        }
        $this->accessToken = $response['access_token'];
        $this->tokenExpiry = time() + max(60, (int)($response['expires_in'] ?? 1800));
        return $this->accessToken;
    }

    private function call(string $method, string $path, ?string $body = null, ?string $contentType = null): array {
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->accessToken()];
        if ($contentType !== null) $headers[] = 'Content-Type: ' . $contentType;
        return $this->send($method, $this->apiBase() . $path, $headers, $body);
    }

    private function send(string $method, string $url, array $headers, ?string $body): array {
        if ($this->goid === '' || $this->clientId === '' || $this->clientSecret === '') {
            throw new \RuntimeException('GoPay credentials not configured');
        }
        $response = ($this->transport)($method, $url, $headers, $body);
        $status = (int)($response['status'] ?? 0);
        $data = json_decode((string)($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            // The HTTP status is the exception code: 4xx = GoPay refused the request, others = retryable.
            throw new \RuntimeException("GoPay API error: {$method} " . parse_url($url, PHP_URL_PATH) . " HTTP {$status}", $status);
        }
        return $data;
    }

    private static function origin(string $url): string {
        $parts = parse_url($url) ?: [];
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) return '';
        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** @return array{status:int, body:string} */
    private static function curlTransport(string $method, string $url, array $headers, ?string $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $responseBody = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($responseBody === false || $error !== '') throw new \RuntimeException('GoPay transport error: ' . $error);
        return ['status' => $status, 'body' => (string)$responseBody];
    }
}
