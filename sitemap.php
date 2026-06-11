<?php
/**
 * BareBones Shop — XML sitemap endpoint.
 * Reachable at /sitemap.xml via the .htaccess rewrite.
 */
require_once __DIR__ . '/src/autoload.php';

header('Content-Type: application/xml; charset=utf-8');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = $scheme . '://' . $host;

echo Sitemap::xml($base);
