<?php
/** Public store-scoped catalog feeds for Google Merchant and Meta Commerce. */
require_once __DIR__ . '/src/autoload.php';

Security::headers();
$locale = Bootstrap::initLocale();
$cfg = require __DIR__ . '/config.php';
$baseUrl = (string)($cfg['app_url'] ?? '');
$channel = strtolower((string)($_GET['channel'] ?? ''));

try {
    if ($channel === 'google') {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=900');
        echo MarketingFeed::google($baseUrl, $locale);
        exit;
    }
    if ($channel === 'meta') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: inline; filename="meta-catalog.csv"');
        header('Cache-Control: public, max-age=900');
        echo MarketingFeed::meta($baseUrl, $locale);
        exit;
    }
    http_response_code(404);
    echo 'Feed not found';
} catch (InvalidArgumentException $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Marketing feed is not configured.';
}
