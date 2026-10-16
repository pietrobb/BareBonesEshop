<?php
/**
 * BareBones Shop — XML sitemap endpoint.
 * Reachable at /sitemap.xml via the .htaccess rewrite. Absolute URLs come from the
 * configured app_url only; the request Host header is attacker-controlled.
 */
require_once __DIR__ . '/src/autoload.php';

$base = Security::configuredAppUrl();
if ($base === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 3600');
    echo "Sitemap unavailable: configure app_url.\n";
    exit;
}

// /sitemap.xml is part 0 (the urlset, or a sitemap index past 50,000 URLs); /sitemap-N.xml is part N.
$part = preg_match('/^[1-9][0-9]{0,5}$/', (string)($_GET['part'] ?? '')) ? (int)$_GET['part'] : 0;
if (isset($_GET['part']) && $part === 0) {
    http_response_code(404);
    exit;
}
try {
    // Rendered once per TTL / catalog change into the store's cache namespace, then streamed from disk.
    $writer = static function($out) use ($base, $part): void { Sitemap::write($out, $base, $part); };
    header('Content-Type: application/xml; charset=utf-8');
    PublicFeedCache::send('sitemap-' . $part . '.xml', $base, $writer);
} catch (OutOfRangeException) {
    header_remove('Content-Type');
    http_response_code(404);
}
