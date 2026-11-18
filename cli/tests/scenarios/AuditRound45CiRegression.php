<?php

// Round 45 (ideas #24/#39/#45): merges waited on CI infrastructure, not on code. Playwright's system packages came from
// a runner apt mirror that stalled at ~30 KB/s until the job timed out, and the PHPStan phar download failed the job on
// a single 504 from GitHub's release server.
echo "\n--- Audit round 45: CI installs browsers and PHPStan without depending on one slow download ---\n";

$r45ciRoot = dirname(TEST_CLI_DIR);
$r45ciJobs = static function (string $workflow): array {
    preg_match_all('/^  ([a-z][a-z0-9-]*):\n(.*?)(?=^  [a-z][a-z0-9-]*:\n|\z)/ms', substr($workflow, (int)strpos($workflow, "\njobs:\n") + 7), $jobs, PREG_SET_ORDER);
    return array_column($jobs, 2, 1);
};
$r45ciSteps = static fn(string $job): array => preg_split('/^      - /m', $job) ?: [];
$r45ciCacheStep = '/uses: actions\/cache@[0-9a-f]{40} #/';
$r45ciScript = (string)@file_get_contents($r45ciRoot . '/.github/scripts/playwright-install.sh');

$r45ciBrowserJobs = [];
foreach (['ci.yml', 'payment-gateway.yml'] as $r45ciFile) {
    $r45ciWorkflow = (string)file_get_contents($r45ciRoot . '/.github/workflows/' . $r45ciFile);
    assert_true(!str_contains($r45ciWorkflow, 'npx playwright install'),
        "R45 CI: {$r45ciFile} installs browsers only through .github/scripts/playwright-install.sh");
    foreach ($r45ciJobs($r45ciWorkflow) as $r45ciName => $r45ciJob) {
        if (!str_contains($r45ciJob, 'playwright-install.sh')) continue;
        $r45ciBrowserJobs[] = "{$r45ciFile}:{$r45ciName}";
        $r45ciCached = $r45ciInstall = null;
        foreach ($r45ciSteps($r45ciJob) as $r45ciIndex => $r45ciStep) {
            if (preg_match($r45ciCacheStep, $r45ciStep) && str_contains($r45ciStep, '~/.cache/ms-playwright')
                && str_contains($r45ciStep, '~/.cache/playwright-apt') && str_contains($r45ciStep, "hashFiles('package-lock.json')")) $r45ciCached ??= $r45ciIndex;
            if (str_contains($r45ciStep, 'bash .github/scripts/playwright-install.sh ')) $r45ciInstall = [$r45ciIndex, $r45ciStep];
        }
        assert_true($r45ciCached !== null && $r45ciInstall !== null && $r45ciCached < $r45ciInstall[0],
            "R45 CI: {$r45ciFile} job {$r45ciName} restores the browsers and their .deb packages (keyed by package-lock.json) before installing");
        assert_true($r45ciInstall !== null && preg_match('/timeout-minutes: \d+\n/', $r45ciInstall[1]) === 1,
            "R45 CI: {$r45ciFile} job {$r45ciName} limits the browser install step in time");
    }
}
sort($r45ciBrowserJobs);
assert_equals(['ci.yml:bookshop-acceptance', 'ci.yml:browser-qualification', 'payment-gateway.yml:stripe-test-checkout'], $r45ciBrowserJobs,
    'R45 CI: every job that runs browsers installs them through the cached script');
assert_true(str_contains((string)file_get_contents($r45ciRoot . '/.github/workflows/payment-gateway.yml'), "      - '.github/scripts/playwright-install.sh'\n  pull_request:")
    && substr_count((string)file_get_contents($r45ciRoot . '/.github/workflows/payment-gateway.yml'), "- '.github/scripts/playwright-install.sh'") === 2,
    'R45 CI: a change of the install script runs the Stripe browser job (push and pull request)');

assert_true(preg_match('/Dir::Cache::Archives "%s"/', $r45ciScript) === 1 && str_contains($r45ciScript, 'APT::Keep-Downloaded-Packages "true"')
    && str_contains($r45ciScript, 'rm -f /etc/apt/apt.conf.d/docker-clean'),
    'R45 CI: apt keeps the downloaded packages in the cached directory');
assert_true(str_contains($r45ciScript, 'for attempt in 1 2 3') && str_contains($r45ciScript, 'timeout 480 npx playwright install --with-deps "$@"')
    && str_contains($r45ciScript, 'Acquire::Retries "5"') && str_contains($r45ciScript, 'chmod -R a+rX "$debs"'),
    'R45 CI: each install attempt is time-limited and retried, and the cache stays readable for actions/cache');

$r45ciPhpstan = $r45ciJobs((string)file_get_contents($r45ciRoot . '/.github/workflows/ci.yml'))['phpstan'] ?? '';
$r45ciPhpstanCache = $r45ciPhpstanDownload = null;
foreach ($r45ciSteps($r45ciPhpstan) as $r45ciIndex => $r45ciStep) {
    if (preg_match($r45ciCacheStep, $r45ciStep) && str_contains($r45ciStep, 'key: phpstan-phar-${{ env.PHPSTAN_VERSION }}-${{ env.PHPSTAN_SHA256 }}')) $r45ciPhpstanCache = $r45ciIndex;
    if (str_contains($r45ciStep, 'releases/download/${PHPSTAN_VERSION}/phpstan.phar')) $r45ciPhpstanDownload = [$r45ciIndex, $r45ciStep];
}
assert_true($r45ciPhpstanCache !== null && $r45ciPhpstanDownload !== null && $r45ciPhpstanCache < $r45ciPhpstanDownload[0]
    && str_contains($r45ciPhpstanDownload[1], 'if [ ! -f "$phar" ]'),
    'R45 CI: the PHPStan phar is cached by version and checksum and downloaded only on a cache miss');
assert_true($r45ciPhpstanDownload !== null && str_contains($r45ciPhpstanDownload[1], 'curl -fsSL --retry 5 --retry-all-errors --retry-delay 10')
    && str_contains($r45ciPhpstanDownload[1], 'sha256sum -c -'),
    'R45 CI: the PHPStan download retries a 5xx answer and the phar is verified against its checksum (also from the cache)');
assert_true(str_contains((string)file_get_contents($r45ciRoot . '/cli/phpstan.sh'), 'curl -fsSL --retry 5 --retry-all-errors --retry-delay 10'),
    'R45 CI: cli/phpstan.sh downloads the phar with the same retries');
