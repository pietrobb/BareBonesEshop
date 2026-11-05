<?php

// Review round 17, medium: the suite read the developer's config.php. An enabled extension or an outbound webhook
// broke 3–5 core tests, and the suite posted 32 signed events to the developer's real webhook. The suite now runs on
// a copy without the outbound integrations (AppConfig::suiteConfigForTesting), and so does every process it starts.
echo "\n--- Audit round 17: tests isolated from the developer's config.php ---\n";

$r17iRoot = dirname(TEST_CLI_DIR);
$r17iExample = require $r17iRoot . '/config.example.php';

// 1. The suite's config is its own file, without extensions or outbound webhooks whatever config.php enables.
$r17iPath = AppConfig::path($r17iRoot);
$r17iConfig = require $r17iPath;
assert_true($r17iPath === TEST_DATA_DIR . '/' . AppConfig::SUITE_FILE && $r17iConfig['extensions'] === [] && $r17iConfig['outbound_webhooks'] === [],
    'R17 isolation: the suite reads its own config without extensions or outbound webhooks (' . $r17iPath . ')');
assert_equals([], Security::config()['outbound_webhooks'] ?? [], 'R17 isolation: the shop code in the suite sees no outbound webhook');

// 2. What is reset and what is kept: the environment stays (database, private directories, app_url, keys), everything
// that leaves the machine goes back to config.example.php.
$r17iDeveloper = array_replace($r17iExample, [
    'db_driver' => 'mysql', 'db_host' => 'db.local', 'db_name' => 'dev_shop', 'db_password' => 'dev-pass',
    'private_runtime_dir' => '/srv/dev-private', 'jobs_dir' => '/srv/dev-private/jobs', 'app_url' => 'https://dev.example',
    'admin_identity_key' => 'dev-key', 'allow_mock_webhook' => true,
    'extensions' => ['acme-loyalty'],
    'outbound_webhooks' => [['url' => 'https://hooks.dev.example/commerce', 'secret' => 's', 'events' => ['*']]],
    'providers' => ['payment' => [], 'shipping' => ['dev-carrier' => 'DevCarrier'], 'mail' => ['smtp' => 'SmtpMail'], 'tax' => [], 'erp' => ['dev' => 'DevErp']],
    'provider_defaults' => ['payment' => 'mock', 'mail' => 'smtp', 'tax' => 'vat'],
    'adapters' => ['payment' => 'MockPayment', 'integration' => 'DevIntegration', 'metrics' => 'DevMetrics'] + $r17iExample['adapters'],
    'integrations' => ['http_erp' => ['base_url' => 'https://erp.dev.example', 'api_key' => 'k', 'timeout' => 15]] + $r17iExample['integrations'],
    'merchant_notifications' => ['recipients' => ['owner@dev.example']] + $r17iExample['merchant_notifications'],
    'stripe_secret_key' => 'sk_live_dev', 'stripe_webhook_secret' => 'whsec_dev', 'gopay_client_secret' => 'gp', 'payu_second_key' => 'pu',
    'comgate_secret' => 'cg', 'thepay_password' => 'tp', 'shipping_webhook_secrets' => ['packeta' => 'p', 'gls' => 'g'],
]);
$r17iSuite = AppConfig::suiteConfigForTesting($r17iDeveloper, $r17iExample);
assert_equals(['mysql', 'db.local', 'dev_shop', 'dev-pass', '/srv/dev-private', '/srv/dev-private/jobs', 'https://dev.example', 'dev-key', true, 'MockPayment', 'mock'],
    [$r17iSuite['db_driver'], $r17iSuite['db_host'], $r17iSuite['db_name'], $r17iSuite['db_password'], $r17iSuite['private_runtime_dir'],
        $r17iSuite['jobs_dir'], $r17iSuite['app_url'], $r17iSuite['admin_identity_key'], $r17iSuite['allow_mock_webhook'],
        $r17iSuite['adapters']['payment'], $r17iSuite['provider_defaults']['payment']],
    'R17 isolation: the suite keeps the database, private directories, app_url, keys and the mock payment of config.php');
assert_equals([[], [], $r17iExample['providers'], $r17iExample['integrations'], $r17iExample['merchant_notifications'], 'log', null, null, '', '', '', '', '', '', $r17iExample['shipping_webhook_secrets']],
    [$r17iSuite['extensions'], $r17iSuite['outbound_webhooks'], $r17iSuite['providers'], $r17iSuite['integrations'], $r17iSuite['merchant_notifications'],
        $r17iSuite['provider_defaults']['mail'], $r17iSuite['adapters']['integration'], $r17iSuite['adapters']['metrics'],
        $r17iSuite['stripe_secret_key'], $r17iSuite['stripe_webhook_secret'], $r17iSuite['gopay_client_secret'], $r17iSuite['payu_second_key'],
        $r17iSuite['comgate_secret'], $r17iSuite['thepay_password'], $r17iSuite['shipping_webhook_secrets']],
    'R17 isolation: extensions, outbound webhooks, providers, integrations, alert recipients and gateway keys are reset');

// 3. A process the suite starts (php -S, worker, CLI script) reads the suite's config for this application root, and a
// fixture shop elsewhere keeps its own config.php.
$r17iFixture = test_fixture_path('r17-isolation-shop');
mkdir($r17iFixture, 0700, true);
file_put_contents($r17iFixture . '/config.php', "<?php return ['fixture' => true];\n");
$r17iChild = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($r17iRoot . '/src/autoload.php', true)
    . '; echo AppConfig::path(' . var_export($r17iRoot, true) . '), "|", AppConfig::path(' . var_export($r17iFixture, true) . ');'));
assert_equals($r17iPath . '|' . $r17iFixture . '/config.php', trim($r17iChild), 'R17 isolation: a child process uses the suite config for this shop only');
@unlink($r17iFixture . '/config.php');
@rmdir($r17iFixture);

// 4. No runtime code reads <root>/config.php around AppConfig::path() (only setup and the web installer, which write it).
$r17iDirect = [];
$r17iSources = array_merge(glob($r17iRoot . '/src/*.php') ?: [], glob($r17iRoot . '/src/*/*.php') ?: [], glob($r17iRoot . '/src/*/*/*.php') ?: [],
    glob($r17iRoot . '/adapters/*.php') ?: [], glob($r17iRoot . '/adapters/contrib/*.php') ?: [], glob($r17iRoot . '/cli/*.php') ?: []);
foreach ($r17iSources as $r17iFile) {
    $r17iRelative = substr(str_replace('\\', '/', $r17iFile), strlen(str_replace('\\', '/', $r17iRoot)) + 1);
    if (in_array($r17iRelative, ['src/WebInstaller.php', 'cli/setup.php', 'cli/smoke-checkout.php', 'cli/benchmark.php'], true)) continue;
    if (preg_match_all("~\\b(?:require|include)(?:_once)?\\b[^;]*config\\.php[\"']~", (string)file_get_contents($r17iFile), $r17iMatches) > 0) {
        $r17iDirect[] = $r17iRelative . ': ' . implode(' | ', $r17iMatches[0]);
    }
}
assert_equals([], $r17iDirect, 'R17 isolation: shop code reads config.php only through AppConfig::path()');

// 4b. Nor does a test or a process it generates (php -S routers, fixtures): reading the app root's config.php there hands
// the developer's extensions, outbound webhooks and gateway keys to the child. Only TestHarness.php reads it, to build the suite copy.
$r17iTestDirect = [];
$r17iTestFiles = array_merge(glob($r17iRoot . '/cli/tests/*.php') ?: [], glob($r17iRoot . '/cli/tests/*/*.php') ?: [], glob($r17iRoot . '/cli/tests/*/*/*.php') ?: []);
foreach ($r17iTestFiles as $r17iFile) {
    $r17iRelative = substr(str_replace('\\', '/', $r17iFile), strlen(str_replace('\\', '/', $r17iRoot)) + 1);
    if ($r17iRelative === 'cli/tests/TestHarness.php') continue;
    $r17iSource = (string)file_get_contents($r17iFile);
    preg_match_all('~(\$\w+)\s*=\s*dirname\(TEST_CLI_DIR\);~', $r17iSource, $r17iVars);
    $r17iAlternatives = array_merge(['dirname\(TEST_CLI_DIR\)'], array_map(static fn(string $v): string => preg_quote($v, '~'), array_unique($r17iVars[1])));
    if (preg_match_all("~(?:" . implode('|', $r17iAlternatives) . ")\s*\.\s*[\"']/config\.php[\"']~", $r17iSource, $r17iMatches) > 0) {
        $r17iTestDirect[] = $r17iRelative . ': ' . implode(' | ', $r17iMatches[0]);
    }
}
assert_equals([], $r17iTestDirect, 'R17 isolation: tests and the processes they start read the app config only through AppConfig::path()');
