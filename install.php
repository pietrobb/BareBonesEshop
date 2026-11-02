<?php
/**
 * BareBones Shop — thin first-visit web installer entrypoint (404 once config.php exists).
 * The implementation lives under the web-denied src tree.
 */
require_once __DIR__ . '/src/autoload.php';
require __DIR__ . '/src/Entrypoints/Install.php';
