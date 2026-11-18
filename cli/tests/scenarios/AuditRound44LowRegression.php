<?php

// Review round 16 (R44, low 7):
// - trusted_proxies took public ranges up to IPv4 /8 and IPv6 /7 (R42's MIN_PROXY_PREFIX): a whole /8 of other networks
//   could name its own client IP and IP country. A public range is at most IPv4 /16, IPv6 /32 now (a CDN's wider network is
//   listed as several); private networks (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10, 127.0.0.0/8, fc00::/7)
//   may still be listed whole.
// - npm audit ran at the end of the browser job, after an hour of browser installs and e2e; it is its own first CI job.
echo "\n--- R44 low: trusted proxy ranges at most /16 (/32), npm audit first in CI ---\n";

$r44lCfg = new ReflectionProperty(Security::class, 'cfg');
$r44lCfgBefore = $r44lCfg->getValue();
try {
    assert_equals(['104.16.0.0/13', '2a06:98c0::/29', '1.0.0.0/8', '10.0.0.0/7', '172.0.0.0/8', '::ffff:104.0.0.0/104'],
        Security::tooWideTrustedProxies(['trusted_proxies' => ['104.16.0.0/13', '2a06:98c0::/29', '1.0.0.0/8', '104.16.0.0/16', '2606:4700::/32',
            '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '127.0.0.0/8', 'fc00::/7', '10.0.0.0/7', '172.0.0.0/8',
            '::ffff:104.0.0.0/104', '173.245.48.0/20', '203.0.113.7']]),
        'R44 trusted proxies: public ranges wider than IPv4 /16 or IPv6 /32 are too wide (was: up to /8 and /7); private networks whole are not, a range reaching past one is');

    $r44lCfg->setValue(null, ['trusted_proxies' => ['104.16.0.0/13', '104.24.0.0/16', '10.0.0.0/8', 'fc00::/7']] + (array)(require AppConfig::path(dirname(TEST_CLI_DIR))));
    assert_equals([false, true, true, true, false],
        array_map(static fn(string $ip): bool => Security::isTrustedProxy($ip), ['104.17.1.1', '104.24.3.3', '10.9.9.9', 'fd00::5', '11.0.0.1']),
        'R44 trusted proxies: a /13 public range is ignored (was: trusted), a /16 one and whole private networks are trusted');
    $r44lSetup = Setup::ipCountryHeaderCheck(['trusted_proxies' => ['104.16.0.0/13']], 'r44', ['vat_ip_country_header' => 'CF-IPCountry']);
    assert_equals('fail', $r44lSetup['status'] ?? null, 'R44 trusted proxies: the doctor fails a store whose IP country header only a /13 range would trust');

    $r44lCi = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/.github/workflows/ci.yml');
    preg_match_all('/^  ([a-z][a-z0-9-]*):\n(.*?)(?=^  [a-z][a-z0-9-]*:\n|\z)/ms', substr($r44lCi, (int)strpos($r44lCi, "\njobs:\n") + 7), $r44lJobs, PREG_SET_ORDER);
    $r44lAudit = array_values(array_filter($r44lJobs, static fn(array $job): bool => str_contains($job[2], 'npm audit --audit-level=moderate')));
    assert_true(count($r44lAudit) === 1 && $r44lAudit[0][1] === ($r44lJobs[0][1] ?? null) && !str_contains($r44lAudit[0][2], 'needs:')
        && !str_contains($r44lAudit[0][2], 'playwright') && !str_contains($r44lAudit[0][2], 'npm ci') && preg_match('/timeout-minutes: [1-5]\n/', $r44lAudit[0][2]) === 1,
        'R44 CI: npm audit is the first job, on its own (no install, no browsers, no needs, at most 5 minutes) (got ' . json_encode(array_column($r44lAudit, 1)) . ')');
    $r44lBrowser = array_values(array_filter($r44lJobs, static fn(array $job): bool => $job[1] === 'browser-qualification'));
    assert_true(count($r44lBrowser) === 1 && !str_contains($r44lBrowser[0][2], 'npm audit'), 'R44 CI: the browser job no longer runs npm audit at its end');
} catch (Throwable $r44lE) {
    assert_true(false, 'R44 low (' . get_class($r44lE) . ': ' . $r44lE->getMessage() . ' @ ' . basename($r44lE->getFile()) . ':' . $r44lE->getLine() . ')');
} finally {
    $r44lCfg->setValue(null, $r44lCfgBefore);
}
