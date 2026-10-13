<?php
/** Final front-controller fallback for admin-managed exact-path redirects. */
require_once __DIR__ . '/src/autoload.php';

Security::headers();
$path = (string)($_GET['path'] ?? parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
// Public aliases (/kosik, /pokladna) are real pages: the entry script handles its own methods,
// so cart/checkout form POSTs must reach it.
$aliasEntry = UrlAliases::dispatch($path);
// Core path route: older payment sessions carry cancel_url /checkout/cancel?order=… (now ?page=checkout-cancel).
if ($aliasEntry === null && rtrim($path, '/') === '/checkout/cancel') {
    unset($_GET['path']);
    $_GET['page'] = 'checkout-cancel';
    $aliasEntry = 'index.php';
}
if ($aliasEntry !== null) {
    require __DIR__ . '/' . $aliasEntry;
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
try {
    Bootstrap::initLocale($path);
    $redirect = ContentService::redirect($path);
} catch (InvalidArgumentException $e) {
    $redirect = null;
}
if (!$redirect) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
header('Cache-Control: no-store');
header('Location: ' . $redirect['target_url'], true, (int)$redirect['http_status']);
