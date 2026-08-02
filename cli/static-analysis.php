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

foreach ([
    'src/Entrypoints/Admin.php',
    'src/Entrypoints/Api.php',
    'src/Entrypoints/Storefront.php',
    'src/Http/ApiHandlers.php',
    'cli/TestSuite.php',
] as $relative) {
    if (!is_file($root . '/' . $relative)) $errors[] = "Missing architecture module: {$relative}";
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

printf("Static analysis passed: %d PHP files, %d stable domain events, %d thin entrypoints.\n", count($phpFiles), count($events), count($entrypointBudgets));
