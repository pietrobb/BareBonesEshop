<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Cache Rebuild (alias for cache-warm.php)
 * Spec-named entry point: php cli/cache-rebuild.php [--verbose]
 */
require __DIR__ . '/cache-warm.php';
