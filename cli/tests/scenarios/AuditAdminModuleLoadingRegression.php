<?php

echo "\n--- Admin route module loading: each admin page loads only its modules, and every page renderer and POST handler resolves ---\n";

$amlRoot = dirname(TEST_CLI_DIR);
$amlEntrypoint = (string)file_get_contents($amlRoot . '/src/Entrypoints/Admin.php');
$amlRequest = (string)file_get_contents($amlRoot . '/src/Entrypoints/Admin/Request.php');
$amlModules = require $amlRoot . '/src/Entrypoints/Admin/Modules.php';
$amlPermissions = require $amlRoot . '/src/Entrypoints/Admin/Permissions.php';

assert_true(!preg_match("#require_once __DIR__ \\. '/Admin/(Catalog|Orders|Operations|Customers)\\.php'#", $amlEntrypoint),
    'Admin modules: the entrypoint does not eagerly load page modules');
assert_equals([], array_values(array_diff(array_keys($amlPermissions), array_keys($amlModules))), 'Admin modules: every permissioned page has a module list');

// Functions each page needs: the render match in Admin.php, its POST arms in Request.php and the special exits.
$amlNeeds = [];
if (preg_match('/^match\(\$page\) \{(.*?)^\};/ms', $amlEntrypoint, $amlRender)) {
    preg_match_all("/'([a-z_]+)'\\s*=>\\s*([A-Za-z]+)\\(/", $amlRender[1], $amlArms, PREG_SET_ORDER);
    foreach ($amlArms as [, $amlPage, $amlFunction]) $amlNeeds[$amlPage][] = $amlFunction;
}
preg_match_all("/'([a-z_]+):[a-z_]+'\\s*=>\\s*([A-Za-z]+)\\(/", $amlRequest, $amlPostArms, PREG_SET_ORDER);
foreach ($amlPostArms as [, $amlPage, $amlFunction]) $amlNeeds[$amlPage][] = $amlFunction;
foreach (['products' => 'handleExport', 'orders' => 'handleOrdersExport', 'invoice_pdf' => 'pageInvoicePdf', 'delivery_note' => 'pageDeliveryNotes', 'unknown-page' => 'pageDashboard'] as $amlPage => $amlFunction) {
    $amlNeeds[$amlPage][] = $amlFunction;
}
assert_true(count($amlNeeds) >= 40 && count($amlPostArms) >= 70, 'Admin modules: page and POST handler arms were parsed (' . count($amlNeeds) . ' pages, ' . count($amlPostArms) . ' POST arms)');

$amlScript = test_fixture_path('admin-module-loading.php');
file_put_contents($amlScript, <<<'PHP'
<?php
[, $root, $page, $needs] = $argv;
require $root . '/src/autoload.php';
require_once $root . '/src/Entrypoints/Admin/Shared.php';
require_once $root . '/src/Entrypoints/Admin/Request.php';
require_once $root . '/src/Entrypoints/Admin/StoreScope.php';
$modules = require $root . '/src/Entrypoints/Admin/Modules.php';
foreach ($modules[$page] ?? $modules['dashboard'] as $module) require_once $root . '/src/Entrypoints/Admin/' . $module . '.php';
echo json_encode(array_values(array_filter(explode(',', $needs), static fn(string $f): bool => !function_exists($f))));
PHP);
foreach ($amlNeeds as $amlPage => $amlFunctions) {
    $amlFunctions = array_values(array_unique($amlFunctions));
    $amlOutput = [];
    exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, $amlScript, $amlRoot, (string)$amlPage, implode(',', $amlFunctions)])) . ' 2>&1', $amlOutput, $amlExit);
    assert_equals([0, '[]'], [$amlExit, implode("\n", $amlOutput)], "Admin modules: page '{$amlPage}' resolves " . implode(', ', $amlFunctions));
}
unlink($amlScript);
