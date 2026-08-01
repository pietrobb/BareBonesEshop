<?php
require_once __DIR__ . '/src/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Request-ID: ' . Logger::correlationId());
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    $result = ['status' => 'method_not_allowed'];
} else {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/healthz', PHP_URL_PATH) ?: '/healthz';
    $result = str_ends_with($path, '/readyz') ? Health::readiness() : Health::liveness();
    http_response_code(($result['status'] ?? '') === 'not_ready' ? 503 : 200);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
