<?php
/**
 * Signed outgoing webhook contract and HTTP delivery.
 * Endpoint URLs and secrets are deployment config only and never persisted.
 */
class Webhook {
    public const TIMESTAMP_TOLERANCE = 300;

    public static function signature(string $rawBody, string $timestamp, string $eventId, string $secret): string {
        self::assertSecret($secret);
        if (!preg_match('/^\d{10}$/', $timestamp) || !preg_match('/^[a-f0-9]{32}$/', $eventId)) {
            throw new InvalidArgumentException('Invalid webhook signature input');
        }
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $eventId . '.' . $rawBody, $secret);
    }

    /**
     * Consumers should persist event IDs after successful processing. The optional
     * replay guard returns true when the event ID has already been consumed.
     */
    public static function verify(
        string $rawBody,
        string $timestamp,
        string $eventId,
        string $providedSignature,
        string $secret,
        ?callable $replayGuard = null,
        ?int $now = null
    ): bool {
        if (!preg_match('/^\d{10}$/', $timestamp)) return false;
        $now ??= time();
        if (abs($now - (int)$timestamp) > self::TIMESTAMP_TOLERANCE) return false;
        if (!preg_match('/^v1=[a-f0-9]{64}$/', $providedSignature)) return false;
        try {
            $expected = self::signature($rawBody, $timestamp, $eventId, $secret);
        } catch (InvalidArgumentException $e) {
            return false;
        }
        if (!hash_equals($expected, $providedSignature)) return false;
        if ($replayGuard !== null && $replayGuard($eventId) === true) return false;
        return true;
    }

    /**
     * Why a configured outbound endpoint cannot be delivered to, or null when it is usable.
     * Endpoints need an https URL; plain http only with 'allow_insecure_http' => true on that
     * endpoint (local development receivers). Codes are [a-z0-9_-] so they never collide with
     * extension listener endpoints ("ext:<code>:<name>").
     */
    public static function endpointProblem(string $code, mixed $endpoint): ?string {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $code)) return 'invalid endpoint code (a-z, 0-9, "_" and "-", up to 64 characters)';
        if (!is_array($endpoint)) return 'endpoint must be an array';
        $url = trim((string)($endpoint['url'] ?? ''));
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) return 'invalid URL';
        if ($scheme !== 'https' && ($endpoint['allow_insecure_http'] ?? false) !== true) {
            return 'URL must use https (set allow_insecure_http => true only for a local development receiver)';
        }
        $secret = (string)($endpoint['secret'] ?? '');
        if (strlen($secret) < 32 || trim($secret) !== $secret) return 'secret must contain at least 32 non-whitespace bytes';
        return null;
    }

    /** @return array<string,string> code => problem for every configured endpoint that cannot be used */
    public static function invalidEndpoints(array $config): array {
        $problems = [];
        foreach ((array)($config['outbound_webhooks'] ?? []) as $code => $endpoint) {
            if (is_array($endpoint) && array_key_exists('active', $endpoint) && !$endpoint['active']) continue;
            $problem = self::endpointProblem((string)$code, $endpoint);
            if ($problem !== null) $problems[(string)$code] = $problem;
        }
        return $problems;
    }

    /** @return array{delivered:bool,response_code:?int,error:?string,timed_out:bool} */
    public static function deliver(array $endpoint, array $envelope, ?callable $transport = null): array {
        $url = trim((string)($endpoint['url'] ?? ''));
        $secret = (string)($endpoint['secret'] ?? '');
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Invalid webhook endpoint URL');
        }
        if ($scheme !== 'https' && ($endpoint['allow_insecure_http'] ?? false) !== true) {
            throw new InvalidArgumentException('Webhook endpoint URL must use https');
        }
        self::assertSecret($secret);
        $eventId = (string)($envelope['id'] ?? '');
        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string)time();
        $headers = [
            'Content-Type: application/cloudevents+json',
            'User-Agent: BareBonesEshop-Webhook/1',
            'X-BBE-Event-ID: ' . $eventId,
            'X-BBE-Timestamp: ' . $timestamp,
            'X-BBE-Signature: ' . self::signature($body, $timestamp, $eventId, $secret),
        ];

        if ($transport !== null) {
            $result = $transport($url, $body, $headers);
            $code = isset($result['response_code']) ? (int)$result['response_code'] : null;
            return [
                'delivered' => $code !== null && $code >= 200 && $code < 300,
                'response_code' => $code,
                'error' => isset($result['error']) && $result['error'] !== '' ? (string)$result['error'] : null,
                'timed_out' => !empty($result['timed_out']),
            ];
        }
        if (!function_exists('curl_init')) {
            return ['delivered' => false, 'response_code' => null, 'error' => 'cURL extension is unavailable', 'timed_out' => false];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch);
        $error = $response === false ? curl_error($ch) : null;
        $timedOut = $response === false && curl_errno($ch) === CURLE_OPERATION_TIMEDOUT;
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [
            'delivered' => $error === null && $code >= 200 && $code < 300,
            'response_code' => $code > 0 ? $code : null,
            'error' => $error,
            'timed_out' => $timedOut,
        ];
    }

    public static function endpointAccepts(array $endpoint, string $eventType): bool {
        if (array_key_exists('active', $endpoint) && !$endpoint['active']) return false;
        $events = $endpoint['events'] ?? ['*'];
        if (!is_array($events)) return false;
        foreach ($events as $pattern) {
            $pattern = (string)$pattern;
            if ($pattern === '*' || $pattern === $eventType) return true;
            if (str_ends_with($pattern, '.*') && str_starts_with($eventType, substr($pattern, 0, -1))) return true;
        }
        return false;
    }

    private static function assertSecret(string $secret): void {
        if (strlen($secret) < 32 || trim($secret) !== $secret) {
            throw new InvalidArgumentException('Webhook secret must contain at least 32 non-whitespace bytes');
        }
    }
}
