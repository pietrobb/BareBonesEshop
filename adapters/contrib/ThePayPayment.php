<?php
/**
 * BareBones Shop — ThePay Payment Adapter (ThePay API, https://gate.thepay.cz/openapi.yaml)
 * Signature/SignatureDate authentication, hosted pay_url redirect, payment detail, refunds.
 * Zero external dependencies — curl only.
 *
 * ThePay notifications are an unsigned HTTP GET carrying only ?project_id=…&payment_uid=…&type=….
 * They are never trusted: the project must be this shop's project and every notification is
 * confirmed by the signed payment detail call (PullVerifiedNotificationAdapter), whose state,
 * amount and currency drive the payment state machine.
 *
 * ThePay's refund request has no idempotency key, so this adapter does not offer
 * IdempotentRefundAdapter (credit-note refunds that require one fail closed). A
 * refunded notification completes the shop's own refund in flight when that refund is the whole
 * remaining balance; partially_refunded and other refund reports are logged and move no money.
 *
 * Config (env var wins over config.php):
 *   PAY_THEPAY_MERCHANT_ID / thepay_merchant_id — merchant ID (administration: Implementation)
 *   PAY_THEPAY_PROJECT_ID  / thepay_project_id  — project ID of this e-shop
 *   PAY_THEPAY_PASSWORD    / thepay_password    — API password
 *   PAY_THEPAY_ENV         / thepay_env         — 'production' | 'demo' (default demo)
 */
class ThePayPayment implements HostedCheckoutAdapter, PullVerifiedNotificationAdapter, PaymentStatusEventAdapter, SignedWebhookPaymentAdapter {
    private const STATES = ['waiting_for_payment', 'waiting_for_confirmation', 'paid', 'partially_refunded', 'refunded', 'expired', 'preauthorized', 'preauth_cancelled', 'preauth_expired', 'error'];
    private const LANGS = ['cs', 'sk', 'de', 'en'];
    private const UID = '/^[A-Za-z0-9._-]{1,100}$/D';

    private string $merchantId;
    private string $projectId;
    private string $password;
    private bool $production;
    /** @var callable(string,string,list<string>,?string):array{status:int,body:string} */
    private $transport;

    public function __construct(array $cfg, ?callable $transport = null) {
        $this->merchantId = trim((string)(getenv('PAY_THEPAY_MERCHANT_ID') ?: ($cfg['thepay_merchant_id'] ?? '')));
        $this->projectId = trim((string)(getenv('PAY_THEPAY_PROJECT_ID') ?: ($cfg['thepay_project_id'] ?? '')));
        $this->password = (string)(getenv('PAY_THEPAY_PASSWORD') ?: ($cfg['thepay_password'] ?? ''));
        $this->production = (getenv('PAY_THEPAY_ENV') ?: ($cfg['thepay_env'] ?? 'demo')) === 'production';
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    public function redirectOrigins(): array {
        return [$this->production ? 'https://gate.thepay.cz' : 'https://demo.gate.thepay.cz'];
    }

    private function apiBase(): string {
        return $this->production ? 'https://api.thepay.cz' : 'https://demo.api.thepay.cz';
    }

    /** Returns ['url' => pay_url, 'session_id' => payment uid]. */
    public function createSession(array $order): array {
        $notifyUrl = (string)($order['notify_url'] ?? '');
        $returnUrl = str_replace('{CHECKOUT_SESSION_ID}', '', (string)($order['success_url'] ?? ''));
        if (!preg_match('#^https?://#i', $notifyUrl) || !preg_match('#^https?://#i', $returnUrl)) {
            throw new \RuntimeException('ThePay needs absolute return and notification URLs');
        }
        $orderNumber = (string)($order['order_number'] ?? '');
        $email = (string)($order['customer_email'] ?? '');
        $amount = (int)($order['total'] ?? 0);
        if ($amount <= 0 || $orderNumber === '' || $email === '') throw new \RuntimeException('ThePay payment needs an amount, an order number and a payer e-mail');
        // The uid is ours and unique per project; a fresh suffix lets a new attempt follow an expired one.
        $uid = substr((string)preg_replace('/[^A-Za-z0-9._-]/', '-', $orderNumber), 0, 80) . '-' . bin2hex(random_bytes(4));
        $fullName = trim((string)preg_replace('/\s+/u', ' ', (string)($order['customer_name'] ?? ''))) ?: $email;
        $split = strrpos($fullName, ' ');
        $lang = strtolower(substr((string)($order['locale'] ?? ''), 0, 2));
        $body = [
            'amount' => $amount,
            'currency_code' => strtoupper((string)($order['currency'] ?? 'EUR')),
            'uid' => $uid,
            'order_id' => mb_substr($orderNumber, 0, 100),
            'language_code' => in_array($lang, self::LANGS, true) ? $lang : 'en',
            'customer' => [
                'name' => mb_substr($split === false ? $fullName : substr($fullName, 0, $split), 0, 255),
                'surname' => mb_substr($split === false ? $fullName : substr($fullName, $split + 1), 0, 100),
                'email' => $email,
            ],
            'return_url' => $returnUrl,
            'notif_url' => $notifyUrl,
        ];

        $payment = $this->request('POST', '/payments', $body);
        $payUrl = (string)($payment['pay_url'] ?? '');
        if (!in_array(self::origin($payUrl), $this->redirectOrigins(), true)) {
            throw new \RuntimeException('ThePay returned no pay_url inside the declared gate origin');
        }
        return ['url' => $payUrl, 'session_id' => $uid];
    }

    /** Signed-webhook entry point is not used: ThePay notifications are confirmed by the detail call. */
    public function handleWebhook(array $payload): array {
        return $this->verifiedEvent($payload);
    }

    public function verifySignature(string $payload, string $sigHeader): bool {
        return false;
    }

    public function verifiedEvent(array $notification): array {
        $this->assertConfigured();
        $project = $notification['project_id'] ?? null;
        $uid = $notification['payment_uid'] ?? null;
        if (!is_scalar($project) || (string)$project !== $this->projectId) {
            throw new PaymentNotificationUnverified('ThePay notification is not for this project');
        }
        if (!is_string($uid) || !preg_match(self::UID, $uid)) {
            throw new PaymentNotificationUnverified('ThePay notification carries no valid payment uid');
        }
        $payment = $this->detail($uid);
        $state = (string)($payment['state'] ?? '');
        if ((string)($payment['uid'] ?? '') !== $uid || !in_array($state, self::STATES, true)
            || (isset($payment['project_id']) && (string)$payment['project_id'] !== $this->projectId)) {
            throw new PaymentNotificationUnverified('ThePay detail does not describe the notified payment');
        }
        $eventType = match ($state) {
            'paid' => 'payment.paid',
            'expired', 'preauth_cancelled', 'preauth_expired', 'error' => 'payment.failed',
            'refunded', 'partially_refunded' => 'refund.reported',
            default => 'payment.' . $state,
        };
        return [
            'event_type' => $eventType,
            'event_id' => $uid . ':' . $state,
            'data' => [
                'order_number' => (string)($payment['order_id'] ?? ''),
                'payment_id' => $uid,
                'provider_ref' => $uid,
                'state' => $state,
                // A paid event carries what was actually paid, so an underpayment cannot pay the order.
                'amount' => (int)($state === 'paid' ? ($payment['paid_amount'] ?? 0) : ($payment['amount'] ?? 0)),
                'currency' => strtolower((string)($payment['currency'] ?? '')),
            ],
        ];
    }

    /** Returns ['refund_id' => '', 'status' => 'succeeded'] once ThePay accepts the refund request. */
    public function refund(string $paymentId, int $amount): array {
        if (!preg_match(self::UID, $paymentId) || $amount <= 0) {
            throw new \InvalidArgumentException('ThePay refund needs a payment uid and a positive amount');
        }
        $this->request('POST', '/payments/' . $paymentId . '/refund', ['amount' => $amount, 'reason' => 'Refund from the shop'], false);
        return ['refund_id' => '', 'status' => 'succeeded'];
    }

    public function statusEvent(string $paymentId): array {
        return $this->verifiedEvent(['project_id' => $this->projectId, 'payment_uid' => $paymentId]);
    }

    public function getStatus(string $paymentId): string {
        if (!preg_match(self::UID, $paymentId)) return 'unknown';
        return (string)($this->detail($paymentId)['state'] ?? 'unknown');
    }

    /** Signed GET of the payment detail; unknown payments are unverified, everything else retryable. */
    private function detail(string $uid): array {
        try {
            return $this->request('GET', '/payments/' . $uid);
        } catch (\RuntimeException $e) {
            // 401/403 = the shop's own credentials or IP whitelist; 429 = throttling: retry later.
            if (in_array($e->getCode(), [400, 404], true)) throw new PaymentNotificationUnverified($e->getMessage(), 0, $e);
            throw $e;
        }
    }

    private function assertConfigured(): void {
        if ($this->merchantId === '' || !ctype_digit($this->projectId) || $this->password === '') {
            throw new \RuntimeException('ThePay credentials not configured');
        }
    }

    private function request(string $method, string $path, ?array $body = null, bool $jsonResponse = true): array {
        $this->assertConfigured();
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $headers = [
            'Accept: application/json',
            'SignatureDate: ' . $date,
            'Signature: ' . hash('sha256', $this->merchantId . $this->password . $date),
        ];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        $url = $this->apiBase() . '/v1/projects/' . $this->projectId . $path . '?' . http_build_query(['merchant_id' => $this->merchantId]);
        $response = ($this->transport)($method, $url, $headers, $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $status = (int)($response['status'] ?? 0);
        $data = json_decode((string)($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || ($jsonResponse && !is_array($data))) {
            // The HTTP status is the exception code: 4xx = ThePay refused the request, others = retryable.
            throw new \RuntimeException("ThePay API error: {$method} " . ($body === null ? 'payment detail' : basename($path)) . " HTTP {$status}", $status);
        }
        return is_array($data) ? $data : [];
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
        if ($responseBody === false || $error !== '') throw new \RuntimeException('ThePay transport error: ' . $error);
        return ['status' => $status, 'body' => (string)$responseBody];
    }
}
