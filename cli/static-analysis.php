<?php
require_once __DIR__ . '/cli-only.php';

/** Dev-only dependency-free static and architecture checks. */
$root = dirname(__DIR__);
$errors = [];
$phpFiles = [];

foreach (glob($root . '/*.php') ?: [] as $file) $phpFiles[] = $file;
foreach (['src', 'adapters', 'cli', 'migrations', 'tests'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }
}
$phpFiles = array_values(array_unique($phpFiles));
sort($phpFiles);

/** @return array{functions: int, max_function_lines: int, coupling: int} */
function architectureMetrics(string $source): array {
    $tokens = token_get_all($source);
    $classes = [];
    $functionLines = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $class = ltrim($token[1], '\\');
            $next = $i + 1;
            while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $next++;
            if ($class !== '' && ctype_upper($class[0]) && $next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_DOUBLE_COLON) {
                $classes[$class] = true;
            }
        }
        if (!is_array($token) || $token[0] !== T_FUNCTION) continue;

        $name = null;
        $body = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j] === '(') break;
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) $name = $tokens[$j][1];
        }
        if ($name === null) continue;
        for (; $j < $count; $j++) {
            if ($tokens[$j] === '{') { $body = $j; break; }
        }
        if ($body === null) continue;

        $line = $token[2];
        $depth = 0;
        for ($j = $i; $j < $count; $j++) {
            $part = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            $line += substr_count($part, "\n");
            if ($j < $body) continue;
            if ($tokens[$j] === '{') $depth++;
            if ($tokens[$j] === '}' && --$depth === 0) {
                $functionLines[] = $line - $token[2] + 1;
                break;
            }
        }
    }

    return [
        'functions' => count($functionLines),
        'max_function_lines' => $functionLines === [] ? 0 : max($functionLines),
        'coupling' => count($classes),
    ];
}

foreach ($phpFiles as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        $errors[] = "Unreadable PHP source: {$file}";
        continue;
    }
    try {
        $tokens = token_get_all($source, TOKEN_PARSE);
    } catch (ParseError $e) {
        $errors[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ': ' . $e->getMessage();
        continue;
    }
    foreach ($tokens as $token) {
        if (is_array($token) && $token[0] === T_EVAL) {
            $errors[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ":{$token[2]} uses eval";
        }
    }
}

$entrypointBudgets = [
    'admin.php' => 20,
    'api.php' => 20,
    'index.php' => 20,
    'cli/test.php' => 20,
];
foreach ($entrypointBudgets as $relative => $budget) {
    $lines = file($root . '/' . $relative);
    if ($lines === false || count($lines) > $budget) {
        $errors[] = "{$relative} exceeds the {$budget}-line entrypoint budget";
    }
}

$architectureBudgets = [
    'src/Entrypoints/Admin.php' => ['lines' => 300, 'functions' => 0, 'max_function_lines' => 0, 'coupling' => 5],
    'src/Entrypoints/Admin/Catalog.php' => ['lines' => 1250, 'functions' => 24, 'max_function_lines' => 240, 'coupling' => 12],
    'src/Entrypoints/Admin/Customers.php' => ['lines' => 300, 'functions' => 6, 'max_function_lines' => 140, 'coupling' => 8],
    'src/Entrypoints/Admin/Operations.php' => ['lines' => 760, 'functions' => 36, 'max_function_lines' => 180, 'coupling' => 18],
    'src/Entrypoints/Admin/Orders.php' => ['lines' => 850, 'functions' => 14, 'max_function_lines' => 300, 'coupling' => 15],
    'src/Entrypoints/Admin/Recovery.php' => ['lines' => 300, 'functions' => 6, 'max_function_lines' => 90, 'coupling' => 8],
    'src/Entrypoints/Admin/Request.php' => ['lines' => 80, 'functions' => 1, 'max_function_lines' => 70, 'coupling' => 2],
    'src/Entrypoints/Admin/Shared.php' => ['lines' => 50, 'functions' => 3, 'max_function_lines' => 25, 'coupling' => 0],
    'src/Entrypoints/Storefront.php' => ['lines' => 320, 'functions' => 0, 'max_function_lines' => 0, 'coupling' => 10],
    'src/Entrypoints/Storefront/CatalogPages.php' => ['lines' => 560, 'functions' => 12, 'max_function_lines' => 230, 'coupling' => 8],
    'src/Entrypoints/Storefront/CheckoutPages.php' => ['lines' => 240, 'functions' => 4, 'max_function_lines' => 100, 'coupling' => 8],
    'src/Entrypoints/Storefront/Layout.php' => ['lines' => 620, 'functions' => 0, 'max_function_lines' => 0, 'coupling' => 8],
];
$forbiddenDependencies = [
    'src/Entrypoints/Admin/Catalog.php' => ['OrderWorkflow::', 'CheckoutService::', 'ApiHandlers'],
    'src/Entrypoints/Admin/Customers.php' => ['Products::', 'CheckoutService::', 'ApiHandlers'],
    'src/Entrypoints/Admin/Operations.php' => ['CheckoutService::', 'Orders::create(', 'ApiHandlers'],
    'src/Entrypoints/Admin/Orders.php' => ['Products::', 'ContentService::', 'ApiHandlers'],
    'src/Entrypoints/Admin/Recovery.php' => ['Products::', 'CheckoutService::', 'ApiHandlers', 'Db::get('],
    'src/Entrypoints/Storefront/CatalogPages.php' => ['CheckoutService::', 'CheckoutSession::', 'Orders::'],
    'src/Entrypoints/Storefront/CheckoutPages.php' => ['Products::', 'Categories::', 'TecDoc::', 'ContentService::'],
    'src/Entrypoints/Storefront/Layout.php' => ['CheckoutService::', 'CheckoutSession::', 'Orders::create('],
];
foreach ($architectureBudgets as $relative => $budget) {
    $path = $root . '/' . $relative;
    $source = is_file($path) ? file_get_contents($path) : false;
    if ($source === false) {
        $errors[] = "Missing architecture module: {$relative}";
        continue;
    }
    $metrics = architectureMetrics($source);
    $metrics['lines'] = count(file($path) ?: []);
    foreach ($budget as $metric => $limit) {
        if ($metrics[$metric] > $limit) {
            $errors[] = "{$relative} {$metric} {$metrics[$metric]} exceeds budget {$limit}";
        }
    }
    foreach ($forbiddenDependencies[$relative] ?? [] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            $errors[] = "{$relative} contains forbidden dependency {$forbidden}";
        }
    }
}
foreach (['src/Entrypoints/Api.php', 'src/Http/ApiHandlers.php', 'cli/TestSuite.php', 'cli/tests/TestHarness.php'] as $relative) {
    if (!is_file($root . '/' . $relative)) $errors[] = "Missing architecture module: {$relative}";
}
$testRunnerLines = count(file($root . '/cli/TestSuite.php') ?: []);
if ($testRunnerLines > 100) $errors[] = "cli/TestSuite.php lines {$testRunnerLines} exceeds budget 100";
$scenarioFiles = glob($root . '/cli/tests/scenarios/*Scenario.php') ?: [];
if (count($scenarioFiles) !== 6) $errors[] = 'Test suite must contain exactly 6 thematic scenario modules';
foreach ($scenarioFiles as $scenarioFile) {
    $scenarioLines = count(file($scenarioFile) ?: []);
    if ($scenarioLines > 1800) {
        $errors[] = basename($scenarioFile) . " lines {$scenarioLines} exceeds budget 1800";
    }
}

require_once $root . '/src/autoload.php';
foreach ([ErpAdapter::class, PimAdapter::class, WmsAdapter::class] as $contract) {
    if (!interface_exists($contract)) $errors[] = "Missing extension contract: {$contract}";
}
if (!is_subclass_of(IntegrationAdapter::class, ErpAdapter::class, true)
    || !is_subclass_of(IntegrationAdapter::class, PimAdapter::class, true)
    || !is_subclass_of(IntegrationAdapter::class, WmsAdapter::class, true)) {
    $errors[] = 'IntegrationAdapter must compose ERP, PIM, and WMS contracts';
}
$events = DomainEvents::all();
if (count($events) !== count(array_unique($events))) $errors[] = 'Domain event names must be unique';
foreach ($events as $event) {
    if (!preg_match('/^[a-z][a-z0-9_.-]{1,190}$/', $event)) $errors[] = "Invalid domain event name: {$event}";
}

if ($errors !== []) {
    foreach ($errors as $error) fwrite(STDERR, "[FAIL] {$error}\n");
    exit(1);
}

printf("Static analysis passed: %d PHP files, %d stable domain events, %d thin entrypoints, %d bounded-context modules.\n", count($phpFiles), count($events), count($entrypointBudgets), count($architectureBudgets));
