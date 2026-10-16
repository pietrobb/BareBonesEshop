<?php
/**
 * Browser-test server router: `php -S 127.0.0.1:8080 tests/e2e/router.php` from the repository root.
 * Everything goes through the shop's router.php (the .htaccess deny/rewrite rules, as production sees
 * them) except the one HTTP fixture csp-guard.spec.js loads from the shop origin; the rest of tests/
 * stays denied.
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
if (rawurldecode(explode('?', (string)($_SERVER['REQUEST_URI'] ?? '/'), 2)[0]) === '/tests/e2e/csp-guard-fixture.php') {
    require __DIR__ . '/csp-guard-fixture.php';
    return true;
}
return require dirname(__DIR__, 2) . '/router.php';
