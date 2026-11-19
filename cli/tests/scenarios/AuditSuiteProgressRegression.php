<?php
// The runner buffers stdout until the summary, so a failure in minute 10 of a 90-minute run used to show only at the
// end. Every failure and one line per finished test file now go straight to STDERR; --stop-on-fail ends at the first one.
$spRoot = test_fixture_path('suite-progress');
$spRemove = static function (string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $spEntry) {
        $spEntry->isDir() && !$spEntry->isLink() ? rmdir($spEntry->getPathname()) : unlink($spEntry->getPathname());
    }
    rmdir($dir);
};
try {
    $spSaved = $suiteProgressStream;
    $suiteProgressStream = fopen('php://memory', 'w+');
    suite_progress('regression', 3, 9, '/elsewhere/AuditExampleRegression.php');
    rewind($suiteProgressStream);
    $spLine = (string)stream_get_contents($suiteProgressStream);
    fclose($suiteProgressStream);
    $suiteProgressStream = $spSaved;
    assert_true(preg_match('~^\[progress \d\d:\d\d:\d\d\] regression 3/9 AuditExampleRegression\.php done: ' . $tests . ' assertions, ' . $failed . ' failed, \d+\.\d min\n$~', $spLine) === 1,
        'Suite progress: a finished file reports its position, the assertions and failures so far and the elapsed time (' . trim($spLine) . ')');

    $spRunner = (string)file_get_contents(TEST_CLI_DIR . '/TestSuite.php');
    $spRegressions = (string)file_get_contents(__DIR__ . '/../RegressionSuite.php');
    assert_true(str_contains($spRunner, "suite_progress('scenario', \$scenarioIndex + 1, count(\$scenarioFiles), \$scenarioFile)")
        && str_contains($spRunner, "suite_progress('extra'")
        && str_contains($spRegressions, "suite_progress('regression', \$regressionIndex + 1, count(\$regressionFiles), \$regressionFile)"),
        'Suite progress: every scenario, regression and extra test file reports when it is done');

    // A child process with the real harness: the failure reaches STDERR while stdout is still buffered.
    mkdir($spRoot . '/tmp', 0700, true);
    file_put_contents($spRoot . '/probe.php', "<?php\nrequire " . var_export(__DIR__ . '/../TestHarness.php', true) . ";\nob_start();\n"
        . "assert_true(true, 'progress probe passes');\nassert_true(false, 'progress probe fails');\nfwrite(STDERR, \"after the failure\\n\");\n");
    $spRun = static function (array $arguments) use ($spRoot): array {
        $process = proc_open(array_merge([PHP_BINARY, '-d', 'sys_temp_dir=' . $spRoot . '/tmp', $spRoot . '/probe.php'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    };
    [$spExit, $spOut, $spErr] = $spRun([]);
    $spFailedAt = strpos($spErr, 'FAILED #1 (assertion 2): progress probe fails');
    assert_true($spExit === 0 && $spFailedAt !== false && $spFailedAt < (int)strpos($spErr, 'after the failure')
        && str_contains($spOut, '[FAIL] progress probe fails') && !str_contains($spErr, 'STOPPED'),
        "Suite progress: a failure is written to STDERR at once and the run goes on (exit {$spExit}: " . trim($spErr) . ')');
    [$spExit, $spOut, $spErr] = $spRun(['--stop-on-fail']);
    assert_true($spExit === 1 && str_contains($spErr, 'TEST SUITE STOPPED at the first failure (--stop-on-fail): progress probe fails')
        && !str_contains($spErr, 'after the failure') && !str_contains($spErr, 'ABORTED')
        && str_contains($spOut, '[PASS] progress probe passes') && str_contains($spOut, '[FAIL] progress probe fails'),
        "Suite progress: --stop-on-fail ends the run at the first failure with exit 1 and the buffered output (exit {$spExit}: " . trim($spErr) . ')');
} catch (Throwable $e) {
    assert_true(false, 'Suite progress (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $spRemove($spRoot);
}
