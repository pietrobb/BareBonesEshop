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

header('Content-Type: application/xml; charset=utf-8');
echo Sitemap::xml($base);
