<?php
/** Secure token-gated digital asset endpoint. */
require_once __DIR__ . '/src/autoload.php';

Security::headers();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit('Method Not Allowed');
}
if (!Security::rateLimit('digital_download', 30)) {
    http_response_code(429);
    exit('Too Many Requests');
}

$token = strtolower(trim((string)($_GET['token'] ?? '')));
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    http_response_code(404);
    exit('Not Found');
}

$entitlement = DigitalDelivery::resolve($token);
$path = $entitlement ? DigitalDelivery::resolvedPath($entitlement) : null;
if (!$entitlement || !$path) {
    http_response_code(404);
    exit('Not Found');
}

$size = filesize($path);
if ($size === false) {
    http_response_code(503);
    exit('Temporarily Unavailable');
}
$contentType = (string)($entitlement['content_type'] ?: 'application/octet-stream');
if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $contentType)) $contentType = 'application/octet-stream';
$name = DigitalDelivery::safeDownloadName($entitlement);

if ($method !== 'HEAD' && !DigitalDelivery::consume((int)$entitlement['id'])) {
    http_response_code(404);
    exit('Not Found');
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . $size);
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Accel-Buffering: no');

if ($method === 'HEAD') exit;

while (ob_get_level() > 0) ob_end_clean();
$handle = fopen($path, 'rb');
if ($handle === false) {
    http_response_code(503);
    exit('Temporarily Unavailable');
}
try {
    while (!feof($handle) && !connection_aborted()) {
        $chunk = fread($handle, 524288);
        if ($chunk === false) break;
        echo $chunk;
        flush();
    }
} finally {
    fclose($handle);
}
