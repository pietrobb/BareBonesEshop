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

    /** @return array{delivered:bool,response_code:?int,error:?string} */
    public static function deliver(array $endpoint, array $envelope, ?callable $transport = null): array {
        $url = trim((string)($endpoint['url'] ?? ''));
        $secret = (string)($endpoint['secret'] ?? '');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Invalid webhook endpoint URL');
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
            ];
        }
        if (!function_exists('curl_init')) {
            return ['delivered' => false, 'response_code' => null, 'error' => 'cURL extension is unavailable'];
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
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [
            'delivered' => $error === null && $code >= 200 && $code < 300,
            'response_code' => $code > 0 ? $code : null,
            'error' => $error,
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
