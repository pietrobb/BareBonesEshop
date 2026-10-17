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
$etag = '"' . hash('sha256', (int)$entitlement['id'] . ':' . $size . ':' . (int)@filemtime($path)) . '"';

// Single byte range (RFC 9110 14): resumable downloads. If-Range with another validator, several
// ranges or an unsupported syntax get the whole file; an unsatisfiable range gets 416.
$range = DigitalDelivery::parseRange((string)($_SERVER['HTTP_RANGE'] ?? ''), (int)$size);
$ifRange = trim((string)($_SERVER['HTTP_IF_RANGE'] ?? ''));
if ($range !== null && $ifRange !== '' && $ifRange !== $etag) $range = null;
if ($range === false) {
    http_response_code(416);
    header('Content-Range: bytes */' . $size);
    header('Accept-Ranges: bytes');
    exit;
}
[$start, $end] = $range ?? [0, max(0, $size - 1)];
$length = $size > 0 ? $end - $start + 1 : 0;

// A download counts once: from byte 0; a resume is free only inside the window and up to the bytes the counted
// download already served (DigitalDelivery::beginTransfer()). Anything else is a new download: refused once spent.
$countedAt = null;
if ($method !== 'HEAD') {
    $countedAt = DigitalDelivery::beginTransfer($entitlement, $start);
    if ($countedAt === null) {
        http_response_code(403);
        exit('Forbidden');
    }
}

if ($range !== null) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Type: ' . $contentType);
header('Content-Length: ' . $length);
header('Accept-Ranges: bytes');
header('ETag: ' . $etag);
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Accel-Buffering: no');

if ($method === 'HEAD') exit;

while (ob_get_level() > 0) ob_end_clean();
// Keep running after a client abort, so the bytes served are recorded (they bound later free resumes).
ignore_user_abort(true);
$handle = fopen($path, 'rb');
if ($handle === false) {
    http_response_code(503);
    exit('Temporarily Unavailable');
}
$served = 0;
$recorded = 0;
try {
    if ($start > 0) fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
        $chunk = fread($handle, min(524288, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        flush();
        // Counted as served even when the client dropped mid-chunk: it may resume from any byte it received.
        $remaining -= strlen($chunk);
        $served += strlen($chunk);
        // A running offset (every 8 MiB) survives a killed request; the final one is written below.
        if ($served - $recorded >= 8388608) {
            DigitalDelivery::recordProgress((int)$entitlement['id'], (int)$countedAt, $start + $served);
            $recorded = $served;
        }
    }
} finally {
    fclose($handle);
    if ($served > $recorded) DigitalDelivery::recordProgress((int)$entitlement['id'], (int)$countedAt, $start + $served);
}
