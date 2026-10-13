<?php
/**
 * BareBones Shop — robots.txt endpoint.
 * Reachable at /robots.txt via the .htaccess rewrite. Dynamic so the Sitemap
 * directive carries the absolute configured app_url (never the request Host).
 */
require_once __DIR__ . '/src/autoload.php';

header('Content-Type: text/plain; charset=utf-8');

echo Robots::txt(Security::configuredAppUrl());
