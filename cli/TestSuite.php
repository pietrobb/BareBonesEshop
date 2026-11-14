<?php
require_once __DIR__ . '/cli-only.php';

/** BareBones Shop — canonical scenario test runner. */
// A fresh clone has neither config.php nor the vendor/bbf submodule: say how to get them instead of aborting midway.
$suiteMissing = array_filter([is_file(dirname(__DIR__) . '/config.php') ? '' : 'config.php is missing: cp config.example.php config.php',
    is_file(dirname(__DIR__) . '/vendor/bbf/bbf_render.php') ? '' : 'vendor/bbf is empty: git submodule update --init']);
if ($suiteMissing !== []) { fwrite(STDERR, "Cannot run the tests yet:\n  - " . implode("\n  - ", $suiteMissing) . "\n"); exit(1); }
require __DIR__ . '/tests/TestHarness.php';

ob_start();
set_error_handler('fail_on_php_error');

// A scenario that throws (or a fatal error) must never end the run looking green: tests may point error_log at a
// file, so the message is written to STDERR here and the exit code is forced to 1 unless the summary was reached.
set_exception_handler(static function (Throwable $exception): void {
    while (ob_get_level() > 0) ob_end_flush();
    fwrite(STDERR, "\nTEST SUITE ABORTED by an uncaught " . get_class($exception) . ': ' . $exception->getMessage()
        . "\n  at " . $exception->getFile() . ':' . $exception->getLine() . "\n" . $exception->getTraceAsString() . "\n");
    exit(1);
});
register_shutdown_function(static function (): void {
    if (defined('TEST_SUITE_COMPLETED')) return;
    while (ob_get_level() > 0) ob_end_flush();
    $last = error_get_last();
    fwrite(STDERR, "\nTEST SUITE ABORTED before the results summary (exit or fatal error)"
        . ($last ? "; last PHP error (may be unrelated): " . $last['message'] . ' at ' . $last['file'] . ':' . $last['line'] : '') . "\n");
    exit(1);
});

echo "========================================\n";
echo "BareBones Shop — Test Suite\n";
echo "========================================\n\n";

$unexpectedWarningFailed = false;
try {
    trigger_error('unexpected harness probe', E_USER_WARNING);
} catch (ErrorException $exception) {
    $unexpectedWarningFailed = str_contains($exception->getMessage(), 'unexpected harness probe');
}
assert_true($unexpectedWarningFailed, 'Harness: unexpected warning fails immediately');
assert_true(
    expect_php_error(E_USER_DEPRECATED, 'expected harness probe', static fn() => trigger_error('expected harness probe', E_USER_DEPRECATED)),
    'Harness: explicitly expected deprecation is captured'
);

$scenarioFiles = [
    'FoundationScenario.php',
    'CommerceScenario.php',
    'PaymentsShippingScenario.php',
    'CustomersOperationsScenario.php',
    'CheckoutArchitectureScenario.php',
    'PlatformScenario.php',
];
// --only=A.php,B.php runs just these scenario files (CI runs the step lock scenario as root without /proc this way).
$suiteOnly = getopt('', ['only:'])['only'] ?? null;
if (is_string($suiteOnly)) $scenarioFiles = array_map('basename', explode(',', $suiteOnly));
// Scenarios may install their own exception handler (cli/worker.php does), so catch here as well.
try {
foreach ($scenarioFiles as $scenarioFile) {
    require __DIR__ . '/tests/scenarios/' . $scenarioFile;
    // R36 (low): --only=FoundationScenario.php,X.php runs X on every migration's schema (alone it lacked other scenarios' tables).
    if (is_string($suiteOnly) && $scenarioFile === 'FoundationScenario.php') (new Migrator(Db::get(), dirname(__DIR__) . '/migrations', test_fixture_path('only-migrate') . '.lock'))->apply();
}
if (!is_string($suiteOnly)) {
    // Every *Regression.php, historical order first, then auto-discovered (tests/RegressionSuite.php).
    require __DIR__ . '/tests/RegressionSuite.php';
    require __DIR__ . '/test-update.php';
    require __DIR__ . '/test-theme-contract.php';
    require __DIR__ . '/test-theme-upgrade-gate.php';
}
} catch (Throwable $suiteAbort) {
    while (ob_get_level() > 0) ob_end_flush();
    fwrite(STDERR, "\nTEST SUITE ABORTED by an uncaught " . get_class($suiteAbort) . ': ' . $suiteAbort->getMessage()
        . "\n  at " . $suiteAbort->getFile() . ':' . $suiteAbort->getLine() . "\n" . $suiteAbort->getTraceAsString() . "\n");
    define('TEST_SUITE_COMPLETED', true); // reported above; the shutdown check would only repeat it
    exit(1);
}

echo "\n========================================\n";
echo "Results: {$passed}/{$tests} passed";
if ($failed > 0) echo ", {$failed} FAILED";
echo "\n";

if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) echo "  - {$error}\n";
    echo "\n";
}

$pdo = null;
Db::reset();
usleep(100000);
if (isset($testDbPath)) foreach (['', '-wal', '-shm'] as $suffix) @unlink($testDbPath . $suffix);

restore_error_handler();
echo "========================================\n";
ob_end_flush();
define('TEST_SUITE_COMPLETED', true);
exit($failed > 0 ? 1 : 0);
