<?php
/** Public store-scoped catalog feeds for Google Merchant and Meta Commerce. */
require_once __DIR__ . '/src/autoload.php';

Security::headers();
$locale = Bootstrap::initLocale();
$baseUrl = Security::configuredAppUrl();
$channel = strtolower((string)($_GET['channel'] ?? ''));

if ($baseUrl === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Marketing feed is not configured.';
    exit;
}
$feeds = [
    'google' => ['google-%s.xml', 'application/xml; charset=utf-8', [MarketingFeed::class, 'writeGoogle']],
    'meta' => ['meta-%s.csv', 'text/csv; charset=utf-8', [MarketingFeed::class, 'writeMeta']],
];
if (!isset($feeds[$channel])) {
    http_response_code(404);
    echo 'Feed not found';
    exit;
}
[$name, $contentType, $write] = $feeds[$channel];
header('Content-Type: ' . $contentType);
if ($channel === 'meta') header('Content-Disposition: inline; filename="meta-catalog.csv"');
header('Cache-Control: public, max-age=' . PublicFeedCache::TTL);
// Rendered once per TTL / catalog change into the store's cache namespace, then streamed from disk.
PublicFeedCache::send(sprintf($name, $locale), $baseUrl, static function($out) use ($write, $baseUrl, $locale): void {
    $write($out, $baseUrl, $locale);
});
