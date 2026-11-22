<?php

// Review round 20 (R55), usability:
//  - the CSV import templates were only files on the server (none after a web install): the admin downloads them.
//  - a store's url_prefix was set in stores.json only; a store on its own domain could not drop it.
//  - "the e-mail was only written to the log" was shown as an error.
//  - cli/seed.php named the catalog in Slovak whatever the shop's language (covered by AuditInstallSmokeRegression).

$r55uRoot = dirname(TEST_CLI_DIR);
$r55uFail = static fn(string $what, Throwable $e) => assert_true(false, "R55 UX {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');

// --- CSV import templates ---------------------------------------------------------------------------------------------------
try {
    $r55uTemplates = ImportTemplates::all();
    $r55uCatalog = array_keys((array)(json_decode((string)file_get_contents($r55uRoot . '/catalog.json'), true)['product_types'] ?? []));
    assert_true(isset($r55uTemplates['simple']) && array_diff($r55uCatalog, array_keys($r55uTemplates)) === []
        && str_starts_with(ImportTemplates::csv($r55uTemplates['simple']), "sku,name,slug,category,price,") && in_array('vat_class', $r55uTemplates['simple'], true),
        'R55 import: a template per product type, simple included, generated without files on the server: ' . json_encode(array_keys($r55uTemplates)));
    $r55uStores = ['default_locale' => 'sk', 'stores' => ['a' => ['languages' => ['sk', 'en']], 'b' => ['languages' => ['de']]]];
    assert_equals(['name_en', 'summary_en', 'slug_en', 'name_de', 'summary_de', 'slug_de'],
        array_slice(ImportTemplates::headers(['attributes' => ['color' => []]], $r55uStores), 15),
        'R55 import: attributes, then the name/summary/slug of every language but the default one');
    $r55uAdmin = (string)file_get_contents($r55uRoot . '/src/Entrypoints/Admin.php');
    $r55uOps = (string)file_get_contents($r55uRoot . '/src/Entrypoints/Admin/Operations.php');
    assert_true(str_contains($r55uAdmin, "(\$page === 'import' && \$action === 'template')") && str_contains($r55uOps, 'p=import&amp;action=template&amp;type=')
        && str_contains($r55uOps, "header('Content-Type: text/csv; charset=UTF-8');") && str_contains($r55uOps, "http_response_code(404);"),
        'R55 import: the import page links each template, served as CSV behind the import permission; an unknown type is 404');
    foreach (['en', 'sk', 'cs', 'de', 'pl', 'hu', 'es'] as $r55uLang) {
        assert_true(trim((string)((require $r55uRoot . "/lang/{$r55uLang}.php")['admin.import.templates_download'] ?? '')) !== '', "R55 import: templates_download in {$r55uLang}");
    }
} catch (Throwable $e) { $r55uFail('import templates', $e); }

// --- url_prefix in the domains form ---------------------------------------------------------------------------------------
$r55uDir = test_fixture_path('r55-ux-stores');
@mkdir($r55uDir, 0700, true);
try {
    $r55uExample = json_decode((string)file_get_contents($r55uRoot . '/stores.example.json'), true);
    file_put_contents($r55uDir . '/stores.json', json_encode($r55uExample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    StoreSettings::setPathForTesting($r55uDir . '/stores.json');
    $r55uRead = static fn(string $store): array => array_intersect_key((array)(json_decode((string)file_get_contents($r55uDir . '/stores.json'), true)['stores'][$store] ?? []), ['domains' => 1, 'url_prefix' => 1]);
    // No /healthz probe for these names: unreachableDomains() asks only under an HTTPS app_url.
    $r55uCfg = new ReflectionProperty(Security::class, 'cfg');
    $r55uCfgBefore = $r55uCfg->getValue();
    $r55uCfg->setValue(null, ['app_url' => 'http://shop.example'] + (array)$r55uCfgBefore);
    $r55uOwn = StoreSettings::saveDomains('us', 'us-store.example.com', '');
    assert_true($r55uOwn['ok'] && $r55uRead('us') === ['domains' => ['us-store.example.com'], 'url_prefix' => ''],
        'R55 stores: a store on its own domain can drop its URL prefix (was: only in stores.json): ' . json_encode([$r55uOwn['errors'], $r55uRead('us')]));
    $r55uNoDomain = StoreSettings::saveDomains('us', '');
    assert_true(!$r55uNoDomain['ok'] && $r55uRead('us')['domains'] === ['us-store.example.com'],
        'R55 stores: without a prefix the store keeps a domain (removing the last one is refused): ' . json_encode($r55uNoDomain['errors']));
    $r55uSet = StoreSettings::saveDomains('us', '', 'US-Shop/');
    $r55uTaken = StoreSettings::saveDomains('us', '', (string)($r55uExample['stores']['cz']['url_prefix'] ?? '/cz'));
    $r55uReserved = StoreSettings::saveDomains('us', '', '/api');
    assert_true($r55uSet['ok'] && $r55uRead('us') === ['domains' => [], 'url_prefix' => '/us-shop'] && !$r55uTaken['ok'] && !$r55uReserved['ok']
        && $r55uRead('us')['url_prefix'] === '/us-shop',
        'R55 stores: a new prefix is saved normalised; another store\'s prefix and a reserved path are refused: ' . json_encode([$r55uSet['errors'], $r55uRead('us')]));
    assert_true(StoreSettings::saveDomains('us', '')['ok'] && $r55uRead('us')['url_prefix'] === '/us-shop', 'R55 stores: a form without the field keeps the prefix');
    $r55uPage = (string)file_get_contents($r55uRoot . '/src/Entrypoints/Admin/StoreSettings.php');
    assert_true(str_contains($r55uPage, 'name="url_prefix"') && str_contains($r55uPage, "StoreSettings::saveDomains(\$storeId, \$_POST['domains'] ?? '', \$_POST['url_prefix'] ?? null)"),
        'R55 stores: the domains form has the URL prefix field');
} catch (Throwable $e) {
    $r55uFail('url prefix', $e);
} finally {
    StoreSettings::setPathForTesting(null);
    if (isset($r55uCfg)) $r55uCfg->setValue(null, $r55uCfgBefore);
}

// --- Mail only logged: an information, not an error -----------------------------------------------------------------------
try {
    $r55uOrders = (string)file_get_contents($r55uRoot . '/src/Entrypoints/Admin/Orders.php');
    $r55uOrderOps = (string)file_get_contents($r55uRoot . '/src/Entrypoints/Admin/OrderOperations.php');
    assert_true(substr_count($r55uOrders, "setFlash('info', I18n::format('admin.order.msg.email_logged'") === 2 && !str_contains($r55uOrders, "setFlash('err', I18n::format('admin.order.msg.email_logged'")
        && str_contains($r55uOrderOps, "=== 'logged' ? 'info' : 'err'") && str_contains($r55uAdmin ?? '', '.flash-info{'),
        'R55 admin: "only written to the log" is an information (blue), not an error');
} catch (Throwable $e) { $r55uFail('logged mail', $e); }
