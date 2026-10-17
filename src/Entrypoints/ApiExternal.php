<?php
/** BareBonesEshop external machine API v1 (API key). No browser session or CSRF is used. Loaded by Api.php. */

Security::headers();
header('Content-Type: application/json; charset=utf-8');

$status = 500;
$body = ['error' => ['code' => 'internal_error', 'message' => 'Internal error']];
try {
    if (!Security::rateLimit('external_api_v1', 300)) {
        $status = 429;
        $body = ['error' => ['code' => 'rate_limited', 'message' => 'Too many requests']];
    } else {
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $client = ApiAuth::authenticate($authorization);
        Bootstrap::initStoreContext('/', '', (string)$client['store_id'], 'api');
        if (!StoreContext::current()->allows('api')) {
            throw new DomainException('External API is disabled for this store');
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $rawBody = file_get_contents('php://input');
        $requestBody = [];
        if ($rawBody !== false && $rawBody !== '') {
            $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
            if ($contentType !== 'application/json') throw new InvalidArgumentException('Content-Type must be application/json');
            $requestBody = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($requestBody)) throw new InvalidArgumentException('JSON body must be an object');
        }
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $headers['Idempotency-Key'] ??= (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        $result = ApiV1::dispatch(
            $method,
            (string)($_SERVER['REQUEST_URI'] ?? '/api/v1'),
            $_GET,
            $requestBody,
            $headers,
            $client
        );
        $status = $result['status'];
        $body = $result['body'];
    }
} catch (Throwable $e) {
    ['status' => $status, 'body' => $body] = ApiV1::errorResponse($e);
}

http_response_code($status);
echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
