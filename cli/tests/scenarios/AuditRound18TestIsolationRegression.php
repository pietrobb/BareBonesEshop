<?php

// Review round 18 (2026-11-06), medium "test isolation": the suite still wrote into the developer's private directories
// (~330 jobs in jobs/pending that their worker would run with the real config, an overwritten invoice, log lines),
// and AppConfig followed the suite's environment variables in any process, a production web request included.
echo "\n--- R18: the suite keeps to its own private directories, the suite config only for suite processes ---\n";

$r18iRoot = dirname(TEST_CLI_DIR);
$r18iPrivate = str_replace('\\', '/', TEST_DATA_DIR) . '/private';
$r18iConfig = Security::config();
$r18iKeys = ['private_runtime_dir', 'jobs_dir', 'invoice_storage_dir', 'backup_storage_dir', 'backup_log_file', 'backup_recovery_dir',
    'structured_log_file', 'metrics_log_file', 'update_storage_dir', 'private_cache_dir', 'digital_assets_base_dir', 'gdpr_salt_file'];
$r18iOutside = array_filter($r18iKeys, static fn(string $key): bool => !str_starts_with(str_replace('\\', '/', (string)($r18iConfig[$key] ?? '')), $r18iPrivate));
assert_equals([], array_values($r18iOutside), 'R18 isolation: every private directory and log file of the suite config lies in the suite\'s own directory');

// The file queue's jobs directory (where a test's queued jobs go unless it sets its own) is the suite's.
$r18iJobs = new ReflectionProperty(JobQueue::class, 'jobsDir');
$r18iPrevious = $r18iJobs->getValue();
try {
    $r18iJobs->setValue(null, null);
    $r18iDir = str_replace('\\', '/', (string)(new ReflectionMethod(JobQueue::class, 'dir'))->invoke(null));
    assert_equals($r18iPrivate . '/jobs', $r18iDir, 'R18 isolation: the file queue of the suite writes into its own jobs directory');
} finally {
    $r18iJobs->setValue(null, $r18iPrevious);
}

// Without the private directory argument (a caller of its own), the config keeps its directories (R17 behaviour).
$r18iKept = AppConfig::suiteConfigForTesting(['jobs_dir' => '/srv/dev/jobs', 'private_runtime_dir' => '/srv/dev'], []);
$r18iMoved = AppConfig::suiteConfigForTesting(['jobs_dir' => '/srv/dev/jobs', 'private_runtime_dir' => '/srv/dev'], [], '/tmp/suite/private/');
assert_equals(['/srv/dev/jobs', '/tmp/suite/private/jobs', '/tmp/suite/private', '/tmp/suite/private/logs/application.jsonl'],
    [$r18iKept['jobs_dir'], $r18iMoved['jobs_dir'], $r18iMoved['private_runtime_dir'], $r18iMoved['structured_log_file']],
    'R18 isolation: the suite copy moves the private paths under the given directory');

// The environment counts only for a suite directory in the temp directory, on the command line.
$r18iFake = test_fixture_path('r18-not-a-suite-dir');
mkdir($r18iFake, 0700, true);
file_put_contents($r18iFake . '/' . AppConfig::SUITE_FILE, "<?php return ['fake' => true];\n");
$r18iChild = static function (string $dataDir) use ($r18iRoot): string {
    $env = array_merge(getenv(), ['BBE_SUITE_DATA_DIR' => $dataDir, 'BBE_SUITE_APP_ROOT' => (string)realpath($r18iRoot)]);
    $process = proc_open([PHP_BINARY, '-r', 'require ' . var_export($r18iRoot . '/src/autoload.php', true) . '; echo AppConfig::path(' . var_export($r18iRoot, true) . ');'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return trim((string)$out);
};
$r18iSuiteSeen = $r18iChild(TEST_DATA_DIR);
$r18iFakeSeen = $r18iChild($r18iFake);
assert_true($r18iSuiteSeen === TEST_DATA_DIR . '/' . AppConfig::SUITE_FILE && dirname($r18iFakeSeen) === $r18iRoot && basename($r18iFakeSeen) === 'config.php',
    'R18 isolation: a child reads the suite config from the suite directory, never from any other directory the environment names ('
    . $r18iSuiteSeen . ' | ' . $r18iFakeSeen . ')');
@unlink($r18iFake . '/' . AppConfig::SUITE_FILE);
@rmdir($r18iFake);
$r18iSource = (string)file_get_contents($r18iRoot . '/src/AppConfig.php') . file_get_contents($r18iRoot . '/src/DataOverrides.php');
assert_true(str_contains($r18iSource, "if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) return null;")
    && substr_count($r18iSource, "getenv('BBE_SUITE_DATA_DIR')") === 1,
    'R18 isolation: a web request (any SAPI but the CLI and php -S) ignores the suite variables; DataOverrides uses the same check');
