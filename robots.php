<?php
/**
 * BareBones Shop — robots.txt endpoint.
 * Reachable at /robots.txt via the .htaccess rewrite. Dynamic so the
 * Sitemap directive carries an absolute URL for the current host.
 */
require_once __DIR__ . '/src/autoload.php';

header('Content-Type: text/plain; charset=utf-8');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = $scheme . '://' . $host;

echo Robots::txt($base);
