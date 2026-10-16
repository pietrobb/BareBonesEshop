<?php
require_once __DIR__ . '/cli-only.php';

/** BareBones Shop — canonical scenario test runner. */
require __DIR__ . '/tests/TestHarness.php';

ob_start();
set_error_handler('fail_on_php_error');

// A scenario that throws (or a fatal error) must never end the run looking green: tests may point error_log at a
// file, so the message is written to STDERR here and the exit code is forced to 1 unless the summary was reached.
$suiteCompleted = false;
set_exception_handler(static function (Throwable $exception): void {
    while (ob_get_level() > 0) ob_end_flush();
    fwrite(STDERR, "\nTEST SUITE ABORTED by an uncaught " . get_class($exception) . ': ' . $exception->getMessage()
        . "\n  at " . $exception->getFile() . ':' . $exception->getLine() . "\n" . $exception->getTraceAsString() . "\n");
    exit(1);
});
register_shutdown_function(static function () use (&$suiteCompleted): void {
    if ($suiteCompleted) return;
    while (ob_get_level() > 0) ob_end_flush();
    $last = error_get_last();
    fwrite(STDERR, "\nTEST SUITE ABORTED before the results summary"
        . ($last ? ': ' . $last['message'] . ' at ' . $last['file'] . ':' . $last['line'] : '') . "\n");
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
foreach ($scenarioFiles as $scenarioFile) {
    require __DIR__ . '/tests/scenarios/' . $scenarioFile;
}
// Every *Regression.php, historical order first, then auto-discovered (tests/RegressionSuite.php).
require __DIR__ . '/tests/RegressionSuite.php';
require __DIR__ . '/test-update.php';
require __DIR__ . '/test-theme-contract.php';
require __DIR__ . '/test-theme-upgrade-gate.php';

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
@unlink($testDbPath);
@unlink($testDbPath . '-wal');
@unlink($testDbPath . '-shm');

restore_error_handler();
echo "========================================\n";
ob_end_flush();
$suiteCompleted = true;
exit($failed > 0 ? 1 : 0);
