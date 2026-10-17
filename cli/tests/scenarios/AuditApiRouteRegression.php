<?php
/**
 * PR14 follow-up: the browser api.php is merged into the single /api/v1 entrypoint. Route groups
 * (storefront, options, webhooks, csp-report) map onto the unchanged handlers; every other /api/v1
 * path stays the key-authenticated external API. HTTP behaviour is covered in AuditInstallSmokeRegression.
 */

echo "\n--- PR14 follow-up: one JSON API under /api/v1 ---\n";

$arRoot = dirname(TEST_CLI_DIR);
foreach ([
    ['/api/v1/storefront/cart_add', '', ['action' => 'cart_add', 'get' => []]],
    ['/api/v1/storefront/search_suggest?q=lamp', '', ['action' => 'search_suggest', 'get' => []]],
    ['/api/v1/storefront/acme-shop.notes', '', ['action' => 'acme-shop.notes', 'get' => []]],
    ['/api/v1/storefront/webhook_payment', '', ['action' => '', 'get' => []]],
    ['/api/v1/storefront/webhook_shipping', '', ['action' => '', 'get' => []]],
    ['/api/v1/storefront/csp_report', '', ['action' => '', 'get' => []]],
    ['/api/v1/storefront/bbf_categories', '', ['action' => '', 'get' => []]],
    ['/api/v1/storefront/', '', ['action' => '', 'get' => []]],
    ['/api/v1/storefront/Cart', '', ['action' => '', 'get' => []]],
    ['/api/v1/options/categories', '', ['action' => 'bbf_categories', 'get' => []]],
    ['/api/v1/webhooks/payment/comgate?id=1', '', ['action' => 'webhook_payment', 'get' => ['provider' => 'comgate']]],
    ['/api/v1/webhooks/payment/../x', '', ['action' => '', 'get' => []]],
    ['/api/v1/webhooks/shipping/packeta', '', ['action' => 'webhook_shipping', 'get' => ['carrier' => 'packeta']]],
    ['/api/v1/csp-report', '', ['action' => 'csp_report', 'get' => []]],
    ['/shop/api/v1/storefront/cart', '/shop', ['action' => 'cart', 'get' => []]],
    ['/api/v1/products', '', null],
    ['/api/v1/orders/5/status', '', null],
    ['/api/v1', '', null],
    ['/api/v1/index.php', '', null],
    ['/api.php', '', null],
    ['/storefront/cart', '', null],
] as [$arUri, $arBase, $arExpected]) {
    assert_equals($arExpected, ApiRoute::resolve($arUri, $arBase), "API route: {$arUri}" . ($arBase !== '' ? " (base {$arBase})" : ''));
}
assert_equals('https://shop.example/api/v1/webhooks/payment/comgate', ApiRoute::paymentWebhook('https://shop.example', 'comgate'), 'API route: payment notification URL sent to gateways');

// No second entrypoint or rewrite for the old browser API.
assert_true(!is_file($arRoot . '/api.php'), 'API: api.php is removed');
$arHtaccess = (string)file_get_contents($arRoot . '/.htaccess');
$arNginx = (string)file_get_contents($arRoot . '/deploy/nginx.conf');
assert_true(str_contains($arHtaccess, 'RewriteRule ^api/v1(?:/.*)?$ api/v1/index.php [QSA,L]') && !str_contains($arHtaccess, 'api.php'), 'API: .htaccess routes only /api/v1');
assert_true(str_contains($arNginx, 'rewrite ^/api/v1(?:/.*)?$ /api/v1/index.php last;') && !str_contains($arNginx, 'api.php'), 'API: nginx.conf routes only /api/v1');

// Every caller uses the new routes.
$arJs = (string)file_get_contents($arRoot . '/themes/default/assets/storefront.js');
assert_true(str_contains($arJs, "config.apiUrl || '/api/v1/storefront'") && !str_contains($arJs, 'api.php') && !preg_match('/[?&]action=/', $arJs), 'API: storefront JS calls /api/v1/storefront/<action>');
assert_true(str_contains((string)file_get_contents($arRoot . '/src/Entrypoints/Storefront/Layout.php'), "Template::url('/api/v1/storefront')"), 'API: storefront config points the JS at /api/v1/storefront');
foreach (glob($arRoot . '/forms/admin-*.json') ?: [] as $arForm) {
    foreach ((array)(json_decode((string)file_get_contents($arForm), true)['fields'] ?? []) as $arField) {
        if (!isset($arField['options_from'])) continue;
        assert_true(BbfOptions::fromSource((string)$arField['options_from']) !== null, 'API: ' . basename($arForm) . ' options_from ' . $arField['options_from'] . ' resolves');
    }
}
$arUnresolved = [];
foreach (['src', 'adapters', 'themes', 'blocks', 'forms', 'cli', 'tests/e2e'] as $arDir) {
    $arIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($arRoot . '/' . $arDir, FilesystemIterator::SKIP_DOTS));
    foreach ($arIterator as $arFile) {
        if (!preg_match('/\.(?:php|js|html|json)$/', $arFile->getFilename()) || str_contains($arFile->getPathname(), 'node_modules')
            || realpath($arFile->getPathname()) === __FILE__
            || $arFile->getFilename() === 'AuditInstallSmokeRegression.php') continue; // asserts the legacy URLs answer 404
        $arText = (string)file_get_contents($arFile->getPathname());
        // Test stubs call the handlers through their own routers with ?action=; real URLs must not use api.php.
        if (preg_match('#/api\.php\?|["\'`]api\.php["\'`?]#', $arText)) $arUnresolved[] = substr($arFile->getPathname(), strlen($arRoot) + 1);
    }
}
assert_equals([], $arUnresolved, 'API: no code builds an api.php URL any more');
