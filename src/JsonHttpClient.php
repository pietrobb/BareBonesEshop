<?php
/**
 * Small JSON-over-HTTP transport for deployment-configured integrations.
 * Test suites inject a fixture transport; production uses cURL without redirects or ambient proxies.
 */
class JsonHttpClient
{
    /** @var null|callable */
    private static $fixtureTransport = null;

    public static function setFixtureTransport(?callable $transport): void
    {
        self::$fixtureTransport = $transport;
    }

    public static function request(
        string $baseUrl,
        string $path,
        string $method = 'GET',
        ?array $payload = null,
        array $headers = [],
        int $timeout = 15
    ): array {
        $baseUrl = rtrim(trim($baseUrl), '/');
        self::assertBaseUrl($baseUrl);
        if (!str_starts_with($path, '/') || str_contains($path, '..') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new InvalidArgumentException('Integration path must be an absolute safe path');
        }
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException('Unsupported integration HTTP method');
        }
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $requestHeaders = array_merge([
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: BareBonesEshop-Integration/1',
        ], $headers);
        $url = $baseUrl . $path;

        if (self::$fixtureTransport !== null) {
            $result = (self::$fixtureTransport)($method, $url, $body, $requestHeaders);
            return self::decodeResult($result);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension is required for HTTP integrations');
        }

        $responseBody = '';
        $tooLarge = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body === '' ? null : $body,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => max(1, min(60, $timeout)),
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$tooLarge): int {
                if (strlen($responseBody) + strlen($chunk) > 1048576) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $error = $ok === false ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($tooLarge) throw new RuntimeException('Integration response exceeds 1 MiB');
        if ($ok === false) throw new RuntimeException('Integration request failed: ' . ($error !== '' ? $error : 'transport error'));
        return self::decodeResult(['status' => $status, 'body' => $responseBody]);
    }

    private static function assertBaseUrl(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new InvalidArgumentException('Integration base_url must be an absolute HTTPS URL');
        }
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new InvalidArgumentException('Integration base_url cannot contain credentials, query, or fragment');
        }
    }

    private static function decodeResult(array $result): array
    {
        $status = (int)($result['status'] ?? $result['response_code'] ?? 0);
        $raw = $result['body'] ?? '';
        $decoded = is_array($raw) ? $raw : ($raw === '' ? [] : json_decode((string)$raw, true));
        if (!is_array($decoded)) throw new RuntimeException('Integration returned invalid JSON');
        if ($status < 200 || $status >= 300) {
            $code = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string)($decoded['error']['code'] ?? $decoded['code'] ?? 'remote_error'));
            throw new RuntimeException("Integration returned HTTP {$status} ({$code})");
        }
        return $decoded;
    }
}
