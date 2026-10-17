<?php
/**
 * BareBones Shop — Comgate Payment Adapter (Comgate REST API v2.0, apidoc.comgate.cz)
 * Basic merchant:secret, hosted payment redirect, payment status, refunds. Zero external
 * dependencies — curl only.
 *
 * Push notifications are sent to the URL set in the Comgate client portal
 * (…/api/v1/webhooks/payment/comgate), as JSON (REST v2.0) or as a form (HTTP POST
 * v1.0). They carry the merchant id and secret, which are checked first; the payment state, amount
 * and currency are then always taken from the authenticated /status call, as Comgate requires
 * (PullVerifiedNotificationAdapter). A test payment never pays an order of a production shop.
 *
 * Comgate's refund refId need not be unique, so the refund call is not idempotent and this adapter
 * does not offer IdempotentRefundAdapter (credit-note refunds that require one fail closed).
 *
 * Config (env var wins over config.php):
 *   PAY_COMGATE_MERCHANT / comgate_merchant — e-shop identifier (client portal: e-shop connection)
 *   PAY_COMGATE_SECRET   / comgate_secret   — connection password
 *   PAY_COMGATE_TEST     / comgate_test     — true (default) creates test payments; false = production
 */
class ComgatePayment implements HostedCheckoutAdapter, PullVerifiedNotificationAdapter, SignedWebhookPaymentAdapter {
    private const ORIGIN = 'https://payments.comgate.cz';
    private const STATES = ['PENDING', 'AUTHORIZED', 'PAID', 'CANCELLED'];
    private const LANGS = ['bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'hr', 'hu', 'it', 'lt', 'lv', 'nl', 'no', 'pl', 'pt', 'ro', 'ru', 'sl', 'sk', 'sv', 'uk', 'vi'];
    private const TRANS_ID = '/^[A-Za-z0-9-]{1,64}$/D';

    private string $merchant;
    private string $secret;
    private bool $test;
    /** @var callable(string,string,list<string>,?string):array{status:int,body:string} */
    private $transport;

    public function __construct(array $cfg, ?callable $transport = null) {
        $this->merchant = trim((string)(getenv('PAY_COMGATE_MERCHANT') ?: ($cfg['comgate_merchant'] ?? '')));
        $this->secret = (string)(getenv('PAY_COMGATE_SECRET') ?: ($cfg['comgate_secret'] ?? ''));
        $test = getenv('PAY_COMGATE_TEST');
        $test = $test !== false && $test !== '' ? $test : ($cfg['comgate_test'] ?? true);
        $this->test = filter_var($test, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    public function redirectOrigins(): array {
        return [self::ORIGIN];
    }

    /** Returns ['url' => Comgate redirect, 'session_id' => transId]. */
    public function createSession(array $order): array {
        $returnUrl = str_replace('{CHECKOUT_SESSION_ID}', '', (string)($order['success_url'] ?? ''));
        $cancelUrl = (string)($order['cancel_url'] ?? '') ?: $returnUrl;
        if (!preg_match('#^https?://#i', $returnUrl) || !preg_match('#^https?://#i', $cancelUrl)) {
            throw new \RuntimeException('Comgate needs absolute return URLs');
        }
        $orderNumber = (string)($order['order_number'] ?? '');
        $email = (string)($order['customer_email'] ?? '');
        $body = [
            'test' => $this->test,
            'price' => (int)($order['total'] ?? 0),
            'curr' => strtoupper((string)($order['currency'] ?? 'EUR')),
            'label' => mb_substr($orderNumber, 0, 16),
            'refId' => $orderNumber,
            'method' => 'ALL',
            'email' => $email,
            'fullName' => trim((string)($order['customer_name'] ?? '')) ?: $email,
            'url_paid' => $returnUrl,
            'url_cancelled' => $cancelUrl,
            'url_pending' => $returnUrl,
        ];
        $lang = strtolower(substr((string)($order['locale'] ?? ''), 0, 2));
        if (in_array($lang, self::LANGS, true)) $body['lang'] = $lang;
        if ($body['price'] <= 0 || $orderNumber === '' || $email === '') throw new \RuntimeException('Comgate payment needs an amount, an order number and a payer e-mail');

        $payment = $this->request('POST', '/v2.0/payment.json', $body);
        $transId = (string)($payment['transId'] ?? '');
        $redirect = (string)($payment['redirect'] ?? '');
        if (($payment['code'] ?? null) !== 0) {
            throw new \RuntimeException('Comgate refused the payment: code ' . (int)($payment['code'] ?? -1));
        }
        if (!preg_match(self::TRANS_ID, $transId) || self::origin($redirect) !== self::ORIGIN) {
            throw new \RuntimeException('Comgate returned no transId or a redirect outside the declared origin');
        }
        return ['url' => $redirect, 'session_id' => $transId];
    }

    /** Signed-webhook entry point is not used: Comgate pushes are confirmed by the status call. */
    public function handleWebhook(array $payload): array {
        return $this->verifiedEvent($payload);
    }

    public function verifySignature(string $payload, string $sigHeader): bool {
        return false;
    }

    public function verifiedEvent(array $notification): array {
        $this->assertConfigured();
        $merchant = $notification['merchant'] ?? null;
        $secret = $notification['secret'] ?? null;
        if (!is_string($merchant) || !is_string($secret) || !hash_equals($this->merchant, $merchant) || !hash_equals($this->secret, $secret)) {
            throw new PaymentNotificationUnverified('Comgate push has a wrong merchant or secret');
        }
        $transId = $notification['transId'] ?? null;
        if (!is_string($transId) || !preg_match(self::TRANS_ID, $transId)) {
            throw new PaymentNotificationUnverified('Comgate push carries no valid transId');
        }
        $payment = $this->status($transId);
        $state = (string)($payment['status'] ?? '');
        $price = (string)($payment['price'] ?? '');
        if ((string)($payment['transId'] ?? '') !== $transId || !in_array($state, self::STATES, true) || !preg_match('/^[0-9]{1,15}$/D', $price)) {
            throw new PaymentNotificationUnverified('Comgate status does not describe the notified payment');
        }
        if (!$this->test && filter_var($payment['test'] ?? 'true', FILTER_VALIDATE_BOOLEAN)) {
            throw new PaymentNotificationUnverified('Comgate test payment notified to a production shop');
        }
        $eventType = match ($state) {
            'PAID' => 'payment.paid',
            'CANCELLED' => 'payment.failed',
            default => 'payment.' . strtolower($state),
        };
        return [
            'event_type' => $eventType,
            'event_id' => $transId . ':' . $state,
            'data' => [
                'order_number' => (string)($payment['refId'] ?? ''),
                'payment_id' => $transId,
                'provider_ref' => $transId,
                'state' => $state,
                'amount' => (int)$price,
                'currency' => strtolower((string)($payment['curr'] ?? '')),
            ],
        ];
    }

    /** Returns ['refund_id' => '', 'status' => succeeded|failed]. */
    public function refund(string $paymentId, int $amount): array {
        if (!preg_match(self::TRANS_ID, $paymentId) || $amount <= 0) {
            throw new \InvalidArgumentException('Comgate refund needs a transId and a positive amount');
        }
        $response = $this->request('POST', '/v2.0/refund.json', ['transId' => $paymentId, 'amount' => $amount, 'test' => $this->test]);
        return ['refund_id' => '', 'status' => ($response['code'] ?? null) === 0 ? 'succeeded' : 'failed'];
    }

    public function getStatus(string $paymentId): string {
        if (!preg_match(self::TRANS_ID, $paymentId)) return 'unknown';
        return strtolower((string)($this->status($paymentId)['status'] ?? 'unknown'));
    }

    /** Authenticated GET /v2.0/payment/transId/{transId}.json; unknown payments are unverified, outages retryable. */
    private function status(string $transId): array {
        try {
            $payment = $this->request('GET', '/v2.0/payment/transId/' . $transId . '.json');
        } catch (\RuntimeException $e) {
            // 401/403: the shop's own credentials are rejected — a configuration error, retry later.
            $code = $e->getCode();
            if ($code >= 400 && $code < 500 && $code !== 401 && $code !== 403) throw new PaymentNotificationUnverified($e->getMessage(), 0, $e);
            throw $e;
        }
        $code = $payment['code'] ?? null;
        if ($code === 1400) throw new PaymentNotificationUnverified('Comgate does not know the notified payment');
        if ($code !== 0) throw new \RuntimeException('Comgate status error: code ' . (is_int($code) ? $code : -1));
        return $payment;
    }

    private function assertConfigured(): void {
        if ($this->merchant === '' || $this->secret === '') throw new \RuntimeException('Comgate credentials not configured');
    }

    private function request(string $method, string $path, ?array $body = null): array {
        $this->assertConfigured();
        $headers = ['Accept: application/json', 'Authorization: Basic ' . base64_encode($this->merchant . ':' . $this->secret)];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        $response = ($this->transport)($method, self::ORIGIN . $path, $headers, $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $status = (int)($response['status'] ?? 0);
        $data = json_decode((string)($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            // The HTTP status is the exception code: 4xx = Comgate refused the request, others = retryable.
            throw new \RuntimeException("Comgate API error: {$method} " . preg_replace('#/transId/[^/]+#', '/transId/…', $path) . " HTTP {$status}", $status);
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
        if ($responseBody === false || $error !== '') throw new \RuntimeException('Comgate transport error: ' . $error);
        return ['status' => $status, 'body' => (string)$responseBody];
    }
}
